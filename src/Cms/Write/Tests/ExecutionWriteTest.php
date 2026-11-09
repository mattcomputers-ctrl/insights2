<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\Ops\Execution\ExecutionRequests as X;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingRequests;
use PII\Cms\Write\Request;

/* ── Golden: templates reproduce the logged floor requests byte for byte ── */

/**
 * The warnings the client noted on a logged request, and how long the
 * screen was open. Insights sends neither itself; the golden tests put
 * them back so the rest of the request can be compared exactly.
 *
 * @return array{events:list<array{0:array<string,string>,1:?string,2:array<string,string>}>,open:int,start:bool,end:bool}
 */
$exExtras = static function (string $xml): array {
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $root   = $doc->documentElement;
    $events = [];
    foreach ($root->childNodes as $node) {
        if (!$node instanceof DOMElement || $node->tagName !== 'event') {
            continue;
        }
        $attrs = $props = [];
        $memo  = null;
        foreach ($node->attributes as $a) {
            $attrs[$a->name] = $a->value;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'Memo') {
                $memo = $child->textContent;
            } elseif ($child instanceof DOMElement && $child->tagName === 'properties') {
                foreach ($child->attributes as $a) {
                    $props[$a->name] = $a->value;
                }
            }
        }
        $events[] = [$attrs, $memo, $props];
    }
    $open = 0;
    if ($root->hasAttribute('StartDate')) {
        $open = (new DateTimeImmutable($root->getAttribute('TransDate')))->getTimestamp()
              - (new DateTimeImmutable($root->getAttribute('StartDate')))->getTimestamp();
    }
    return ['events' => $events, 'open' => $open, 'start' => $root->hasAttribute('StartDate'), 'end' => $root->hasAttribute('EndDate')];
};

/** Compare a rebuilt request with its fixture, after adding the client's warning notes back. */
$exSame = static function (string $xml, array $f, Request $req, string $what) use ($exExtras): void {
    foreach ($exExtras($xml)['events'] as [$attrs, $memo, $props]) {
        $req->event($attrs, $memo, $props);
    }
    t_xml_same($xml, $req->toXml($f['env']), $what);
};

/** @return list<array{0:string,1:array,2:list<array>}> [xml, parsed, steps] for every fixture of an action */
$exEach = static function (string $action): array {
    $out = [];
    foreach (cms_fixture_ids($action) as $log) {
        $xml   = cms_fixture($action, $log);
        $f     = cms_fixture_parse($xml);
        $out[] = [$xml, $f, $f['steps'], (string) $log];
    }
    return $out;
};

t_section('Execution golden: ' . X::NEW_INGREDIENT);
$exCount = 0;
foreach ($exEach(X::NEW_INGREDIENT) as [$xml, $f, $s, $log]) {
    $d = $s[1]['data'];
    $exSame($xml, $f, X::newIngredient($s[0]['keys']['Ordr'], $s[0]['keys']['Version'], $d['Item'], $d['QtyReqd'], $d['QtyEntered']), "log {$log}");
    $exCount++;
}
t_check($exCount >= 2, 'both add-ingredient fixtures were exercised');

t_section('Execution golden: ' . X::MAKE);
$exFirst = $exAgain = 0;
foreach ($exEach(X::MAKE) as [$xml, $f, $s, $log]) {
    $moves = $done = [];
    $into  = null;
    $owner = '';
    $n     = count($s);
    foreach ($s as $i => $step) {
        $d = $step['data'];
        switch ($step['object']) {
            case 'CreateInventoryCost':
                $into  = ['item' => $d['Item'], 'unitCost' => $d['UnitCostMK']];
                $owner = $d['Owner'];
                break;
            case 'CreateMFReceipt':
                if (isset($d['ChangeSet'])) {
                    $into = ['changeSet' => $d['ChangeSet']];
                }
                break;
            case 'MoveInventory':
                $moves[] = ['line' => $d['SrcOrdDetail'], 'item' => $d['Item'], 'qty' => $d['Quantity']];
                $owner   = $d['Owner'];
                if (isset($d['TrgLocation'])) {
                    $into['vessel'] = $d['TrgLocation'];
                }
                break;
            case 'UpdateManufacturingOrdDetailPreweighStatus':
                $done[] = ['line' => $d['OrdDetail'], 'weighed' => true];
                break;
            case 'UpdateManufacturingOrdDetailExecStatus':
                $weighed = $s[$i - 1]['object'] === 'UpdateManufacturingOrdDetailPreweighStatus';
                if (!$weighed && $i < $n - 2) {
                    $done[] = ['line' => $d['OrdDetail'], 'weighed' => false];
                }
                break;
        }
    }
    isset($into['changeSet']) ? $exAgain++ : $exFirst++;
    $exSame($xml, $f, X::makeBatch($s[0]['keys']['Ordr'], $s[0]['keys']['Version'], $owner, $s[$n - 2]['data']['OrdDetail'], $moves, $done, $into), "log {$log}");
}
t_check($exFirst >= 2, 'first makes were exercised');
t_check($exAgain >= 1, 'a make run again after an undo was exercised');
t_check(t_throws(\InvalidArgumentException::class, static fn () => X::makeBatch(1, 1, '4', 2, [], [], ['changeSet' => 5])) !== null,
    'a make run again needs its vessel');

t_section('Execution golden: ' . X::TESTING);
$exCount = 0;
foreach ($exEach(X::TESTING) as [$xml, $f, $s, $log]) {
    $d = $s[4]['data'];
    $exSame($xml, $f, X::inProcessTesting($s[3]['data']['Ordr'], $s[0]['keys']['OrdDetail'], $s[1]['keys']['Location'],
        $s[1]['keys']['Version'], $s[2]['data']['OrdDetail'], $d['SubLot'], $d['SampledBy'], $d['SampleDate']), "log {$log}");
    $exCount++;
}
t_check($exCount >= 2, 'both send-for-testing fixtures were exercised');

t_section('Execution golden: packaging phase, packout request, production record');
$exCount = 0;
foreach ($exEach(X::PACK_PHASE) as [$xml, $f, $s, $log]) {
    $exSame($xml, $f, X::executePackagingPhase($s[0]['data']['OrdDetail']), "phase log {$log}");
    $exCount++;
}
foreach ($exEach(X::PACK_REQUESTED) as [$xml, $f, $s, $log]) {
    $exSame($xml, $f, X::packoutRequested($s[0]['data']['Ordr'], $s[0]['data']['Item']), "request log {$log}");
    $exCount++;
}
foreach ($exEach(X::CHANGE_SET) as [$xml, $f, $s, $log]) {
    $exSame($xml, $f, X::createChangeSet($s[0]['keys']['Ordr'], $s[0]['keys']['Version'], $s[1]['data']['Owner']), "record log {$log}");
    $exCount++;
}
t_check($exCount >= 6, 'all six fixtures were exercised');

t_section('Execution golden: ' . ManufacturingRequests::RELEASE . ' from the floor');
$exDone = [];
foreach ($exEach(ManufacturingRequests::RELEASE) as [$xml, $f, $s, $log]) {
    if ($f['program'] !== X::CMS) {
        continue;
    }
    $lot = null;
    if ($s[2]['object'] === 'CreateLot') {
        $d   = $s[2]['data'];
        $lot = ['item' => $d['Item'], 'productLine' => $d['OrdDetail'], 'lotPrefix' => $d['LotPrefix'],
                'user' => $d['ReleasedBy'], 'manfDate' => $d['ManfDate']];
    }
    $exSame($xml, $f, X::releasePack($s[0]['keys']['Ordr'], $s[0]['keys']['Version'], $s[0]['data']['DateReleased'], $lot), "log {$log}");
    $exDone[] = $log;
}
t_same(['865557', '866320'], $exDone, 'release with and without a new lot is reproduced');

t_section('Execution golden: ' . X::PACKAGING);
$exFirst = $exLater = 0;
foreach ($exEach(X::PACKAGING) as [$xml, $f, $s, $log]) {
    $extras = $exExtras($xml);
    $obj    = array_column($s, 'object');
    $csAt   = array_search('CreateChangeSet', $obj, true);
    $pack   = ['ordr' => $s[$csAt]['data']['Ordr']];
    $owner  = $s[$csAt]['data']['Owner'];
    $into   = null;
    $comps  = [];
    $bulk   = null;
    $packages = $made = '';
    foreach ($s as $step) {
        $d = $step['data'];
        if ($step['object'] === 'CreateInventoryCost') {
            $into = ['changeSet' => $d['ChangeSet'], 'unitCost' => $d['UnitCostMK']];
        }
        if ($step['object'] !== 'MoveInventory') {
            continue;
        }
        if (($d['MakeContext'] ?? '') === 'MK') {
            $made = $d['Quantity'];
            $pack['line'] = $d['TrgOrdDetail'];
            $pack['item'] = $d['Item'];
            if (isset($d['InventoryCost'])) {
                $into = ['container' => $d['SrcLocation'], 'inventoryCost' => $d['InventoryCost']];
            }
        } elseif (!isset($d['SrcOrdDetail'])) {
            $packages = $d['Quantity'];
        } elseif (isset($d['Sublot'])) {
            $bulk = ['line' => $d['SrcOrdDetail'], 'item' => $d['Item'], 'sublot' => $d['Sublot'], 'vessel' => $d['SrcLocation'], 'qty' => $d['Quantity']];
        } else {
            $comps[] = ['line' => $d['SrcOrdDetail'], 'item' => $d['Item'], 'qty' => $d['Quantity']];
        }
    }
    isset($into['container']) ? $exLater++ : $exFirst++;
    $exSame($xml, $f, X::packaging($s[0]['keys']['Ordr'], $s[0]['keys']['Version'], $owner, $pack, $packages, $comps, $bulk, $made, $into,
        $extras['open']), "log {$log}");
}
t_check($exFirst >= 2, 'first packouts were exercised');
t_check($exLater >= 1, 'a later packout into the existing container was exercised');
t_check(t_throws(\InvalidArgumentException::class, static fn () => X::packaging(1, 1, '4', ['ordr' => 2, 'line' => 3, 'item' => 4], '1', [],
    ['line' => 5, 'item' => 6, 'sublot' => 7, 'vessel' => 8, 'qty' => '1'], '1', ['container' => 9])) !== null,
    'a later packout needs its cost record');

t_section('Execution golden: ' . X::COMPLETE);
$exCount = 0;
foreach ($exEach(X::COMPLETE) as [$xml, $f, $s, $log]) {
    $extras = $exExtras($xml);
    t_check($extras['start'] && !$extras['end'], "log {$log} carries a start and no end");
    $exSame($xml, $f, X::markComplete($s[0]['keys']['Ordr'], $s[0]['keys']['Version']), "log {$log}");
    $exCount++;
}
t_check($exCount >= 2, 'both complete fixtures were exercised');

t_section('Execution golden: ' . X::UNPACKAGING);
$exCount = 0;
foreach ($exEach(X::UNPACKAGING) as [$xml, $f, $s, $log]) {
    $used = [];
    $line = '';
    foreach ($s as $step) {
        if ($step['object'] === 'UpdateManufacturingOrdDetailQtyUsed') {
            $used[] = $step['data']['OrdDetail'];
        } elseif ($step['object'] === 'UpdateManufacturingOrdDetailQtyMade') {
            $line = $step['data']['OrdDetail'];
        }
    }
    $exSame($xml, $f, X::unpackaging($s[0]['keys']['Ordr'], $s[0]['keys']['Version'], $s[1]['data']['ChangeSet'], $used, $line), "log {$log}");
    $exCount++;
}
t_check($exCount >= 2, 'both undo-packout fixtures were exercised');

t_section('Execution golden: ' . X::REVERSE);
$exClone = $exPlain = 0;
foreach ($exEach(X::REVERSE) as [$xml, $f, $s, $log]) {
    $lines = [];
    $clone = false;
    foreach ($s as $step) {
        if ($step['object'] === 'UpdateManufacturingOrdDetailQtyUsed') {
            $lines[] = $step['data']['OrdDetail'];
        }
        $clone = $clone || $step['object'] === 'CloneFIPT';
    }
    $clone ? $exClone++ : $exPlain++;
    $exSame($xml, $f, X::reverseReceipt($s[0]['keys']['Ordr'], $s[0]['keys']['Version'], $s[1]['data']['Receipt'], $lines, $clone), "log {$log}");
}
t_check($exClone >= 1 && $exPlain >= 1, 'undo make with and without a fresh test step is reproduced');

<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\Ops\Manufacturing\ManufacturingRequests;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

$mfPick = static fn (array $data, array $names): array => array_intersect_key($data, array_flip($names));

/** Rebuild a logged ticket save through the templates; null when the shape is one Insights leaves to the client. */
$mfRebuild = static function (array $f) use ($mfPick): ?\PII\Cms\Write\Request {
    $s = $f['steps'];
    $objects = array_map(static fn (array $x): string => $x['object'] . '.' . $x['operation'], $s);
    $headerKeys = ['Owner', 'Recipe', 'DateOrdered', 'DateRequired', 'PlanStartDate', 'PlacedBy', 'AllowOvercommit',
                   'Comment', 'PercentPrecision', 'ChangeNumber', 'BillTo'];

    if ($objects[0] === 'Ordr.Insert' && ($s[0]['data']['Context'] ?? '') === 'MFBA' && isset($s[0]['data']['Recipe'])) {
        $by = ['BA' => [], 'PK' => [], 'UB' => [], 'UI' => [], 'INSTR' => []];
        foreach ($s as $x) {
            if ($x['object'] === 'OrdDetail') {
                $by[$x['data']['Context']][] = $x['data'];
            }
        }
        if (count($by['BA']) !== 1 || count($by['PK']) !== 1 || count($by['UB']) !== 1) {
            return null;
        }
        return ManufacturingRequests::createTicket(
            $mfPick($s[0]['data'], $headerKeys),
            $mfPick($by['BA'][0], ['RecipeDetailReference', 'AltID', 'Phase', 'BatchType', 'TimeToAddUnit', 'TotalWeight', 'TotalVolume', 'ExecOrder']),
            $mfPick($by['PK'][0], ['RecipeDetailReference', 'AltID', 'Item', 'QtyReqd', 'TotalWeight', 'TotalVolume']),
            $mfPick($by['UB'][0], ['RecipeDetailReference', 'AltID', 'UseFrom']),
            array_map(static fn (array $d): array => $mfPick($d, ['RecipeDetailReference', 'AltID', 'Line', 'Item', 'QtyEntered',
                'QtyReqd', 'StdQty', 'ExecOrder', 'AdjControlValue']), $by['UI']),
            array_map(static fn (array $d): array => $mfPick($d, ['RecipeDetailReference', 'AltID', 'Description', 'ExecOrder']), $by['INSTR']),
        );
    }

    if (array_slice($objects, 0, 3) === ['Ordr.UpdateVersion', 'Ordr.Insert', 'OrdDetail.Insert']) {
        $commit = null;
        $uis    = [];
        foreach ($s as $i => $x) {
            if ($x['object'] === 'OrdDetail' && $x['data']['Context'] === 'UI') {
                $uis[$i + 1] = $mfPick($x['data'], ['RecipeDetailReference', 'AltID', 'Item', 'QtyEntered', 'QtyReqd', 'StdQty']);
            } elseif ($x['object'] === 'OrdDetailCommit') {
                $commit = $x;
            }
        }
        $uis[(int) $commit['subs'][0]['FromStep']]['bulk'] = true;
        return ManufacturingRequests::addPackTicket(
            $s[0]['keys']['Ordr'], $s[0]['keys']['Version'],
            $mfPick($s[1]['data'], $headerKeys),
            $mfPick($s[2]['data'], ['RecipeDetailReference', 'Item', 'QtyReqd']),
            array_values($uis), $commit['data']['SrcOrdDetail'],
        );
    }

    if (array_slice($objects, 0, 4) === ['Ordr.Update', 'VerifyESD.Execute', 'ModifyMFOrderProcedureDetails.Execute', 'UpdateLinkedPackagingOrders.Execute']) {
        $last = $s[count($s) - 1];
        return ManufacturingRequests::editTicket((int) $s[0]['keys']['Ordr'], (int) $s[0]['keys']['Version'], $s[0]['data'],
            $s[1]['data']['Owner'],
            $last['object'] === 'CreateLotForPackagingProductOrders'
                ? ['user' => $last['data']['User'], 'lotPrefix' => $last['data']['LotPrefix']] : null);
    }
    return null;
};

t_section('Manufacturing golden: ' . ManufacturingRequests::SAVE);
$mfSkipped = [];
$mfDone    = [];
foreach (cms_fixture_ids(ManufacturingRequests::SAVE) as $mfLog) {
    $mfXml = cms_fixture(ManufacturingRequests::SAVE, $mfLog);
    $mfF   = cms_fixture_parse($mfXml);
    $mfReq = $mfRebuild($mfF);
    if ($mfReq === null) {
        $mfSkipped[] = (string) $mfLog;
        continue;
    }
    t_xml_same($mfXml, $mfReq->toXml($mfF['env']), "log {$mfLog}");
    $mfDone[] = (string) $mfLog;
}
t_same(['839011', '850590', '862211', '865832', '866303', '866304'], $mfDone,
    'ticket create, packaging ticket and header edits are reproduced');
// Left to the client: recipe editing, tickets without a recipe, rescale, packaging quantity change and removal.
t_same(['844641', '846224', '857625', '860225', '861076', '861077', '861269', '861270', '863400', '863613', '863850', '865555', '865829'],
    $mfSkipped, 'the other logged saves are shapes Insights does not send');

t_section('Manufacturing golden: ' . ManufacturingRequests::RELEASE);
$mfDone = [];
foreach (cms_fixture_ids(ManufacturingRequests::RELEASE) as $mfLog) {
    $mfXml = cms_fixture(ManufacturingRequests::RELEASE, $mfLog);
    $mfF   = cms_fixture_parse($mfXml);
    $mfS   = $mfF['steps'];
    $mfObj = array_column($mfS, 'object');
    $mfIpt = array_keys($mfObj, 'OrdDetail');
    $mfLot = array_search('CreateLot', $mfObj, true);
    if ($mfLot === false || !in_array('CreateMFOrderFinalTests', $mfObj, true) || count($mfIpt) > 1) {
        continue;   // tickets without a recipe, repack tickets, re-release: left to the client
    }
    $mfSpec = null;
    if ($mfIpt !== []) {
        $mfD    = $mfS[$mfIpt[0]]['data'];
        $mfSpec = ['altId' => $mfD['AltID'], 'execOrder' => $mfD['ExecOrder'], 'owner' => $mfD['Owner'], 'tests' => []];
        foreach ($mfS as $mfX) {
            if ($mfX['object'] === 'OrdDetailTest') {
                $mfSpec['tests'][] = $mfX['data'];
            }
        }
    }
    $mfL   = $mfS[$mfLot]['data'];
    $mfReq = ManufacturingRequests::release($mfS[0]['keys']['Ordr'], $mfS[0]['keys']['Version'], $mfS[0]['data']['DateReleased'],
        $mfIpt !== [] ? $mfS[$mfIpt[0]]['data']['Parent'] : '0', $mfL['OrdDetail'], $mfSpec,
        ['item' => $mfL['Item'], 'lotPrefix' => $mfL['LotPrefix'], 'user' => $mfF['env']->user, 'manfDate' => $mfL['ManfDate'] ?? null]);
    t_xml_same($mfXml, $mfReq->toXml($mfF['env']), "log {$mfLog}");
    $mfDone[] = (string) $mfLog;
}
t_same(['865823', '866305'], $mfDone, 'release with and without specification tests is reproduced');

t_section('Manufacturing golden: ' . ManufacturingRequests::CLOSE);
$mfCount = 0;
foreach (cms_fixture_ids(ManufacturingRequests::CLOSE) as $mfLog) {
    $mfXml = cms_fixture(ManufacturingRequests::CLOSE, $mfLog);
    $mfF   = cms_fixture_parse($mfXml);
    $mfS   = $mfF['steps'];
    $mfLines = [];
    foreach ($mfS as $mfX) {
        if ($mfX['object'] === 'OrdDetail') {
            $mfLines[] = (int) $mfX['keys']['OrdDetail'];
        }
    }
    $mfReq = ManufacturingRequests::close((int) $mfS[0]['keys']['Ordr'], (int) $mfS[0]['keys']['Version'],
        $mfS[0]['data']['DateCompleted'] ?? null, $mfLines);
    t_xml_same($mfXml, $mfReq->toXml($mfF['env']), "log {$mfLog}");
    $mfCount++;
}
t_check($mfCount >= 2, 'both close shapes were exercised');

t_section('Manufacturing golden: ' . ManufacturingRequests::PUBLISH);
$mfDone = [];
foreach (cms_fixture_ids(ManufacturingRequests::PUBLISH) as $mfLog) {
    $mfXml = cms_fixture(ManufacturingRequests::PUBLISH, $mfLog);
    $mfF   = cms_fixture_parse($mfXml);
    $mfS   = $mfF['steps'];
    if (count($mfS) !== 4 || $mfF['program'] === '') {
        continue;   // one publish also rewrote the batch weight; one was logged without a program name
    }
    $mfD   = $mfS[2]['data'];
    $mfReq = ManufacturingRequests::publishRecipe((int) $mfS[0]['keys']['Recipe'], (int) $mfS[0]['keys']['Version'], $mfD['Owner'],
        (int) $mfD['Parent'], (int) $mfS[1]['data']['Parent'], (int) $mfD['UseFrom'], (int) $mfD['AltID']);
    t_xml_same($mfXml, $mfReq->toXml($mfF['env']), "log {$mfLog}");
    $mfDone[] = (string) $mfLog;
}
t_same(['861271', '863615'], $mfDone, 'recipe publish is reproduced');

t_section('Manufacturing golden: ' . ManufacturingRequests::ACTIVE);
$mfCount = 0;
foreach (cms_fixture_ids(ManufacturingRequests::ACTIVE) as $mfLog) {
    $mfXml = cms_fixture(ManufacturingRequests::ACTIVE, $mfLog);
    $mfF   = cms_fixture_parse($mfXml);
    if ($mfF['program'] !== ManufacturingRequests::CMS_RECIPE) {
        continue;
    }
    $mfS   = $mfF['steps'];
    $mfReq = ManufacturingRequests::setRecipeInactive((int) $mfS[0]['keys']['Recipe'], (int) $mfS[0]['keys']['Version'],
        $mfS[0]['data']['Inactive'] === 'true');
    t_xml_same($mfXml, $mfReq->toXml($mfF['env']), "log {$mfLog}");
    $mfCount++;
}
t_check($mfCount >= 1, 'recipe activate and deactivate fixtures were exercised');

t_section('Manufacturing templates refuse malformed input');
$mfHead = ['Owner' => '4', 'Recipe' => 1, 'DateOrdered' => 'd', 'DateRequired' => 'd', 'PlanStartDate' => 'd', 'PlacedBy' => 'u', 'ChangeNumber' => 5];
$mfBa   = ['RecipeDetailReference' => 1, 'AltID' => 8, 'Phase' => 'PHASE', 'BatchType' => 2, 'TimeToAddUnit' => 'hr',
           'TotalWeight' => '1', 'TotalVolume' => '1', 'ExecOrder' => 1];
$mfPk   = ['RecipeDetailReference' => 2, 'AltID' => 1, 'Item' => 9, 'QtyReqd' => '10', 'TotalWeight' => '1', 'TotalVolume' => '1'];
$mfUb   = ['RecipeDetailReference' => 3, 'AltID' => 11, 'UseFrom' => 8];
$mfUi   = ['RecipeDetailReference' => 4, 'AltID' => 2, 'Line' => 1, 'Item' => 7, 'QtyEntered' => '10 lb', 'QtyReqd' => '10', 'StdQty' => '1', 'ExecOrder' => 2];
foreach ([
    'ticket without ingredients' => static fn () => ManufacturingRequests::createTicket($mfHead, $mfBa, $mfPk, $mfUb, [], []),
    'ticket without recipe'      => static fn () => ManufacturingRequests::createTicket(['Recipe' => ''] + $mfHead, $mfBa, $mfPk, $mfUb, [$mfUi], []),
    'unknown header column'      => static fn () => ManufacturingRequests::createTicket($mfHead + ['Bogus' => 1], $mfBa, $mfPk, $mfUb, [$mfUi], []),
    'line missing a value'       => static fn () => ManufacturingRequests::createTicket($mfHead, $mfBa, ['Item' => ''] + $mfPk, $mfUb, [$mfUi], []),
    'unknown line column'        => static fn () => ManufacturingRequests::createTicket($mfHead, $mfBa, $mfPk, $mfUb, [$mfUi + ['Bogus' => 1]], []),
    'pack without bulk line'     => static fn () => ManufacturingRequests::addPackTicket(1, 1, $mfHead, $mfPk, [$mfUi], 5),
    'empty edit'                 => static fn () => ManufacturingRequests::editTicket(1, 1, [], '4'),
    'edit of another column'     => static fn () => ManufacturingRequests::editTicket(1, 1, ['Status' => 'CLS'], '4'),
    'spec line without tests'    => static fn () => ManufacturingRequests::release(1, 1, 'd', 2, 3,
        ['altId' => 1, 'execOrder' => 1, 'owner' => '4', 'tests' => []], ['item' => 1, 'lotPrefix' => 'p', 'user' => 'u', 'manfDate' => null]),
    'approved lot without date'  => static fn () => ManufacturingRequests::release(1, 1, 'd', 2, 3, null,
        ['item' => 1, 'lotPrefix' => 'p', 'user' => 'u', 'manfDate' => null]),
    'lines on a completed close' => static fn () => ManufacturingRequests::close(1, 1, null, [5]),
] as $mfWhat => $mfFn) {
    t_check(t_throws(\InvalidArgumentException::class, $mfFn) !== null, "refused: {$mfWhat}");
}

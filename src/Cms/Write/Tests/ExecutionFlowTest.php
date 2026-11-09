<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\DryRunTransport;
use PII\Cms\Write\FakeCmsReader;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Ops\Execution\ExecutionRequests as X;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingRequests;
use PII\Cms\Write\TransportResult;

/* ── Floor flows: what a user's input becomes, against a fake CMS that moves on as the server would ── */

$xfPerms  = array_keys(\PII\Cms\Write\OperationRegistry::permissionsFor('batch_tickets'));
$xfWriter = static fn (FakeCmsReader $cms, $transport, ?array $perms = null): \PII\Cms\Write\CmsWriter => new \PII\Cms\Write\CmsWriter(
    $cms, $transport, static fn (string $p) => in_array($p, $perms ?? $xfPerms, true), 'pic\\user1', 'WS1', true, '7.15.0.680',
    static function (array $row): void {});
/**
 * Level what legitimately differs from the log: the program names, the
 * clock, the cost estimate, and the warning notes the client adds.
 */
$xfNorm = static fn (string $xml): string => preg_replace(
    ['/<event\b.*?<\/event>/s', '/Application="[^"]+"/', '/ (TransDate|StartDate|EndDate)="[^"]+"/', '/UnitCostMK="[^"]+"/'],
    ['', 'Application="A"', ' $1="T"', 'UnitCostMK="C"'], $xml);
$xfRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle),
        "refused: {$label}" . ($e !== null ? ' [' . $e->getMessage() . ']' : ' (not refused)'));
};
/** Every action of a request as one comparable string, without its step number. */
$xfActs = static function (string $xml): array {
    $out = [];
    foreach (cms_fixture_parse($xml)['steps'] as $s) {
        $out[] = json_encode([$s['object'], $s['operation'], $s['keys'], $s['data'], array_map(
            static function ($sub) { unset($sub['FromStep']); return $sub; }, $s['subs'])]);
    }
    return $out;
};

/** One ticket line as both readers return it. */
$xfLine = static fn (int $id, string $ctx, array $over = []): array => $over + [
    'OrdDetail' => $id, 'Context' => $ctx, 'AltID' => null, 'Line' => null, 'Item' => null, 'Parent' => null, 'IsOpen' => 1,
    'ExecOrder' => null, 'IsRepackaging' => 0, 'QtyReqd' => null, 'QtyUsed' => null, 'Status' => 'NST', 'ExecStatus' => null,
    'ItemCode' => null, 'Description' => null, 'Unit' => null, 'UnitCost' => null,
];
$xfUi = static fn (int $id, int $line, int $item, string $qty, ?string $cost, ?int $exec = null, string $unit = 'lb', array $over = []): array
    => $xfLine($id, 'UI', $over + ['Line' => $line, 'Item' => $item, 'ItemCode' => "R{$item}", 'Description' => "RAW {$item}", 'Unit' => $unit,
        'QtyReqd' => $qty, 'UnitCost' => $cost, 'ExecOrder' => $exec]);
$xfHead = static fn (int $ordr, int $version, array $over = []): array => $over + [
    'Ordr' => $ordr, 'Version' => $version, 'Context' => 'MFBA', 'Status' => 'RLS', 'UserHold' => null, 'Owner' => '4', 'Recipe' => 20867,
    'BillTo' => null, 'AllowOvercommit' => 1, 'DateRequired' => '2026-11-09T08:04:11', 'PlanStartDate' => '2026-11-09T08:04:11',
    'DateReleased' => '2026-11-09T00:00:00', 'BillToName' => null,
];

/**
 * A fake CMS over a small world the test can change between requests.
 * world: 'heads' [ordr => row], 'lines' [ordr => rows], 'packs' [bulk ordr => rows], 'sets' [ordr => change set rows],
 * 'receipts' [change set => id], 'costs' [change set => row], 'vessels' [line => row], 'sublots' [line => id], 'items' [code => row].
 */
$xfCms = static function (\ArrayObject $w): FakeCmsReader {
    return (new FakeCmsReader())
        ->when('FROM CMS.dbo.Item i WHERE i.ItemCode = ?', static fn (array $p) => isset($w['items'][$p[0]]) ? [$w['items'][$p[0]]] : [])
        ->when('FROM CMS.dbo.Ordr o', static fn (array $p) => isset($w['heads'][(int) $p[0]]) ? [$w['heads'][(int) $p[0]]] : [])
        ->when('FROM CMS.dbo.OrdDetail od', static fn (array $p) => $w['lines'][(int) $p[0]] ?? [])
        ->when('FROM CMS.dbo.OrdDetail xl', static fn (array $p) => $w['lines'][(int) $p[0]] ?? [])
        ->when('FROM CMS.dbo.OrdDetailCommit c', static fn (array $p) => $w['packs'][(int) $p[0]] ?? [])
        ->when('FROM CMS.dbo.ChangeSet cs', static fn (array $p) => $w['sets'][(int) $p[0]] ?? [])
        ->when('FROM CMS.dbo.ChangeSetMFReceipt mr', static fn (array $p) => isset($w['receipts'][(int) $p[0]])
            ? [['Receipt' => $w['receipts'][(int) $p[0]]]] : [])
        ->when('FROM CMS.dbo.InventoryCost ic', static fn (array $p) => isset($w['costs'][(int) $p[0]]) ? [$w['costs'][(int) $p[0]]] : [])
        ->when('FROM CMS.dbo.Location v', static fn (array $p) => isset($w['vessels'][(int) $p[0]]) ? [$w['vessels'][(int) $p[0]]] : [])
        ->when('FROM CMS.dbo.Lot lt', static fn (array $p) => isset($w['sublots'][(int) $p[0]]) ? [['Sublot' => $w['sublots'][(int) $p[0]]]] : []);
};
$xfSet = static fn (int $id, string $ctx, ?int $reversedBy = null): array => ['ChangeSet' => $id, 'Context' => $ctx, 'ReverseChangeSet' => $reversedBy];
/** Change one line of the world. */
$xfTouch = static function (\ArrayObject $w, int $ordr, int $line, array $over): void {
    $lines = $w['lines'];
    foreach ($lines[$ordr] as $i => $l) {
        if ($l['OrdDetail'] === $line) {
            $lines[$ordr][$i] = $over + $l;
        }
    }
    $w['lines'] = $lines;
};
$xfBump = static function (\ArrayObject $w, int $ordr, array $over = []): void {
    $heads = $w['heads'];
    $heads[$ordr] = $over + ['Version' => $heads[$ordr]['Version'] + 1] + $heads[$ordr];
    $w['heads'] = $heads;
};

t_section('Execution registry');
t_same(11, count($xfPerms), 'eleven batch ticket write permissions, six of them for the floor');
foreach (['mfx.add_ingredient', 'mfx.make_batch', 'mfx.send_to_qc', 'mfx.packout', 'mfx.complete', 'mfx.undo_packout', 'mfx.undo_make'] as $op) {
    t_check(\PII\Cms\Write\OperationRegistry::get($op) !== null, "{$op} is registered");
}

/* Ticket 194761 (10 lb of B123117) and its packaging ticket 194762, as CMS held them before log 866313. */
$xfWorld = static function () use ($xfHead, $xfLine, $xfUi): \ArrayObject {
    return new \ArrayObject([
        'heads' => [
            194761 => $xfHead(194761, 3),
            194762 => $xfHead(194762, 1, ['Context' => 'MFPP', 'Status' => 'NST', 'UserHold' => 'HOLD', 'DateReleased' => null, 'Recipe' => 20868]),
        ],
        'lines' => [
            194761 => [
                $xfLine(566187, 'BA', ['ExecOrder' => 1]),
                $xfLine(566188, 'PK', ['Item' => 123117, 'ItemCode' => 'B123117', 'Description' => 'TEST RED', 'Unit' => 'lb', 'QtyReqd' => '10',
                                       'UnitCost' => '5.9267', 'Parent' => 566187]),
                $xfLine(566189, 'UB'),
                $xfUi(566190, 1, 101268, '5.180000001', '1.5861', 3),
                $xfUi(566191, 2, 108604, '0.4', '2.53', 4),
                $xfUi(566192, 3, 119584, '4', '11.58', 5),
                $xfUi(566193, 4, 101152, '0.219999999', null, 6),
                $xfUi(566194, 5, 101270, '0.2', '5.3866', 7),
                $xfLine(566195, 'INSTR', ['ExecOrder' => 2]),
                $xfLine(566196, 'INSTR', ['ExecOrder' => 8]),
                $xfLine(566200, 'IPT', ['ExecOrder' => 10]),
                $xfLine(566201, 'FT'),
            ],
            194762 => [
                $xfLine(566197, 'PK', ['Item' => 123118, 'ItemCode' => 'P123118', 'Description' => 'TEST RED 5LB', 'Unit' => 'ea', 'QtyReqd' => '10']),
                $xfUi(566198, 1, 111075, '2.15', '1.075', null, 'ea'),
                $xfUi(566199, 2, 123117, '10', '5.9267', null, 'lb', ['ItemCode' => 'B123117']),
            ],
        ],
        'packs'    => [194761 => [['Ordr' => 194762, 'Status' => 'NST', 'ItemCode' => 'P123118', 'Qty' => '10']]],
        'sets'     => [],
        'receipts' => [],
        'costs'    => [],
        'vessels'  => [],
        'sublots'  => [566188 => 61576, 566197 => 61577],
        'items'    => [
            'R100849' => ['Item' => 100849, 'ItemCode' => 'R100849', 'Description' => 'RAW 100849', 'Unit' => 'lb', 'Status' => null],
            'R101268' => ['Item' => 101268, 'ItemCode' => 'R101268', 'Description' => 'RAW 101268', 'Unit' => 'lb', 'Status' => null],
            'B123117' => ['Item' => 123117, 'ItemCode' => 'B123117', 'Description' => 'TEST RED', 'Unit' => 'lb', 'Status' => null],
            'OLD1'    => ['Item' => 7, 'ItemCode' => 'OLD1', 'Description' => 'OLD', 'Unit' => 'lb', 'Status' => 'OBS'],
        ],
    ]);
};

t_section('Execution flow: add an ingredient on the floor');
$w    = $xfWorld();
$cms  = $xfCms($w);
$fake = new FakeTransport();
$wr   = $xfWriter($cms, $fake);
$in   = ['ordr' => '194761', 'item' => 'R100849', 'qty' => '.22'];
$now  = new DateTimeImmutable('2026-11-09T12:23:50');
$p    = $wr->preview('mfx.add_ingredient', $in, $now);
t_same([], $p->plan->warnings, 'a new item raises no warning');
t_check(str_contains(json_encode($p->plan->summary), '0.22 lb of R100849'), 'the summary names the ingredient: ' . json_encode($p->plan->summary));
t_check($wr->execute('mfx.add_ingredient', $in, $now, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($xfNorm(cms_fixture(X::NEW_INGREDIENT, 866313)), $xfNorm($fake->sent[0] ?? ''), 'the request matches log 866313');
$p = $wr->preview('mfx.add_ingredient', ['item' => 'R101268'] + $in, $now);
t_check(count($p->plan->warnings) === 1 && str_contains($p->plan->warnings[0], 'already'), 'an item already on the ticket is pointed out');
foreach ([
    'no item'          => [['item' => ''], 'Enter the item'],
    'unknown item'     => [['item' => 'NOPE'], 'was not found'],
    'obsolete item'    => [['item' => 'OLD1'], 'obsolete'],
    'the product'      => [['item' => 'B123117'], 'what this ticket makes'],
    'zero quantity'    => [['qty' => '0'], 'more than zero'],
    'packaging ticket' => [['ordr' => '194762'], 'packaging ticket'],
    'unknown ticket'   => [['ordr' => '5'], 'was not found'],
] as $label => [$over, $needle]) {
    $xfRefused(static fn () => $wr->preview('mfx.add_ingredient', $over + $in, $now), $needle, $label);
}

t_section('Execution flow: make the batch');
$xfBump($w, 194761);
$lines = $w['lines'];
$lines[194761][] = $xfUi(566318, 6, 100849, '0.22', '12.006', 9);
$w['lines'] = $lines;
foreach ([
    'send for testing before the make' => 'mfx.send_to_qc',
    'packout before the make'          => 'mfx.packout',
    'complete before the make'         => 'mfx.complete',
    'undo before the make'             => 'mfx.undo_make',
] as $label => $op) {
    $xfRefused(static fn () => $wr->preview($op, ['ordr' => '194761', 'pack' => '194762', 'qty' => '10', 'packages' => '2'], $now), 'has not been made yet', $label);
}
$in  = ['ordr' => '194761', 'qty' => [566193 => '0']];
$now = new DateTimeImmutable('2026-11-09T12:24:17');
$p   = $wr->preview('mfx.make_batch', $in, $now);
t_check(count($p->plan->warnings) === 1 && str_contains($p->plan->warnings[0], 'Not used in this batch: R101152'),
    'an ingredient left out is pointed out: ' . json_encode($p->plan->warnings));
t_check(str_contains(json_encode($p->plan->summary), '5.926664 per lb'), 'the cost is estimated from replacement costs: ' . json_encode($p->plan->summary));
t_check($wr->execute('mfx.make_batch', $in, $now, $p->hash)->ok(), 'committed on the fake transport');
$sent = $xfActs($fake->sent[1] ?? '<request/>');
$want = $xfActs(cms_fixture(X::MAKE, 866314));
$sentCost = preg_replace('/"UnitCostMK":"[^"]+"/', '"UnitCostMK":"C"', $sent);
$wantCost = preg_replace('/"UnitCostMK":"[^"]+"/', '"UnitCostMK":"C"', $want);
t_same(array_slice($wantCost, 0, 15), array_slice($sentCost, 0, 15), 'record, vessel and ingredient moves match log 866314 in order');
t_same(array_slice($want, -2), array_slice($sent, -2), 'the batch step and the ticket status close the request as in log 866314');
sort($sentCost);
sort($wantCost);
t_same($wantCost, $sentCost, 'the same steps are marked done as in log 866314 (the client lists them in another order)');
t_check(str_contains($fake->sent[1], 'UnitCostMK="5.926664"') && !str_contains($fake->sent[1], '<event'), 'the estimate is sent; no warning notes are added');
$p = $wr->preview('mfx.make_batch', ['ordr' => '194761', 'qty' => [566190 => '5.5', 566193 => '']], $now);
t_check(count($p->plan->warnings) === 2 && str_contains($p->plan->warnings[0], 'R101268: 5.5 instead of 5.180000001'), 'a changed quantity is pointed out');
$p = $wr->preview('mfx.make_batch', ['ordr' => '194761'], $now);
t_check(str_contains($p->rendered->stages[0]['xml'], 'SrcOrdDetail="566193"') && str_contains(implode(' ', $p->plan->warnings), 'No replacement cost in CMS for R101152'),
    'with nothing typed every ingredient is used at its ticket quantity, and a missing cost is pointed out');
$xfRefused(static fn () => $wr->preview('mfx.make_batch', ['ordr' => '194761', 'qty' => [566190 => 'x']], $now), 'Quantity of R101268', 'a quantity that is not a number');
$xfRefused(static fn () => $wr->preview('mfx.make_batch', ['ordr' => '194761',
    'qty' => [566190 => '', 566191 => '', 566192 => '', 566193 => '', 566194 => '', 566318 => '']], $now), 'no ingredient', 'nothing used');
$xfRefused(static fn () => $wr->preview('mfx.make_batch', ['ordr' => '194762'], $now), 'packaging ticket', 'a packaging ticket');

/* The server's side of the make: record 139109, receipt, cost, vessel; every step but the test is done. */
$xfBump($w, 194761);
$w['sets']     = [194761 => [$xfSet(139109, 'MF')]];
$w['receipts'] = [139109 => 31400];
$w['costs']    = [139109 => ['InventoryCost' => 81590, 'UnitCostMK' => '5.97078']];
$w['vessels']  = [566187 => ['Location' => 119589, 'Version' => 1, 'LocationCode' => 'VO194761']];
foreach ([566190, 566191, 566192, 566193, 566194, 566195, 566196, 566201, 566318] as $id) {
    $xfTouch($w, 194761, $id, ['ExecStatus' => 'CMP']);
}
$xfRefused(static fn () => $wr->preview('mfx.make_batch', ['ordr' => '194761'], $now), 'made already', 'making twice');
$xfRefused(static fn () => $wr->preview('mfx.add_ingredient', ['ordr' => '194761', 'item' => 'R100849', 'qty' => '1'], $now), 'made already', 'adding after the make');

t_section('Execution flow: send for testing');
$in  = ['ordr' => '194761'];
$now = new DateTimeImmutable('2026-11-09T12:24:40');
$p   = $wr->preview('mfx.send_to_qc', $in, $now);
t_check($wr->execute('mfx.send_to_qc', $in, $now, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($xfNorm(cms_fixture(X::TESTING, 866315)), $xfNorm($fake->sent[2] ?? ''), 'the request matches log 866315, sample time included');
$p = $wr->preview('mfx.complete', $in, $now);
t_check(count($p->plan->warnings) === 2, 'completing now warns that nothing is packed and nothing was tested: ' . json_encode($p->plan->warnings));
$xfTouch($w, 194761, 566200, ['ExecStatus' => 'CMP']);
$xfBump($w, 194761);
$xfRefused(static fn () => $wr->preview('mfx.send_to_qc', $in, $now), 'sent for testing already', 'sending twice');

t_section('Execution flow: pack out through a packaging ticket');
$in  = ['ordr' => '194761', 'pack' => '194762', 'qty' => '10', 'packages' => '2'];
$now = new DateTimeImmutable('2026-11-09T12:25:53');
$p   = $wr->preview('mfx.packout', $in, $now);
t_same(['Open the packaging phase', 'Release packaging ticket 194762', 'Ask for the packout',
        'Open the production record of packaging ticket 194762', 'Pack out'],
    array_column($p->rendered->stages, 'name'), 'a first packout is five requests');
t_check(str_contains($p->rendered->stages[4]['xml'], 'ChangeSet="{stage 4: new ChangeSet}"'), 'the preview shows a placeholder for the production record');
t_check(str_contains(json_encode($p->plan->summary), '10 ea in 2 packages') && str_contains(json_encode($p->plan->summary), '2.15 ea R111075')
    && str_contains(json_encode($p->plan->summary), '6.201905 per ea'), 'the summary gives packages, components and cost: ' . json_encode($p->plan->summary));
t_same([], $p->plan->warnings, 'a packout of the planned quantity raises no warning');
/* The fake server answers each request and moves the world on, as CMS does. */
$fake->willReturn(static function (string $xml) use ($w, $xfTouch): TransportResult {
    $xfTouch($w, 194761, 566188, ['Status' => 'STD']);
    return new TransportResult(TransportResult::COMMITTED, 866319, 0, null, 'ok', []);
});
$fake->willReturn(static function (string $xml) use ($w, $xfBump): TransportResult {
    $xfBump($w, 194762, ['Status' => 'RLS', 'UserHold' => null, 'DateReleased' => '2026-11-09T00:00:00']);
    return new TransportResult(TransportResult::COMMITTED, 866320, 0, null, 'ok', []);
});
$fake->willReturn(new TransportResult(TransportResult::COMMITTED, 866321, 0, null, 'ok', []));
$fake->willReturn(static function (string $xml) use ($w, $xfBump, $xfSet): TransportResult {
    $xfBump($w, 194762);
    $w['sets'] = $w['sets'] + [194762 => [$xfSet(139110, 'MF')]];
    return new TransportResult(TransportResult::COMMITTED, 866322, 0, null, 'ok', [
        ['step' => 2, 'table' => 'ChangeSet', 'field' => 'ChangeSet', 'value' => '139110']]);
});
$res = $wr->execute('mfx.packout', $in, $now, $p->hash);
t_check($res->ok(), 'all five requests committed on the fake transport: ' . $res->describe());
t_same(8, count($fake->sent), 'five requests sent');
t_xml_same($xfNorm(cms_fixture(X::PACK_PHASE, 866319)), $xfNorm($fake->sent[3] ?? ''), 'the packaging phase matches log 866319');
t_xml_same($xfNorm(cms_fixture(ManufacturingRequests::RELEASE, 866320)), $xfNorm($fake->sent[4] ?? ''), 'the release of the packaging ticket matches log 866320');
t_xml_same($xfNorm(cms_fixture(X::PACK_REQUESTED, 866321)), $xfNorm($fake->sent[5] ?? ''), 'the packout request matches log 866321');
t_xml_same($xfNorm(cms_fixture(X::CHANGE_SET, 866322)), $xfNorm($fake->sent[6] ?? ''), 'the production record matches log 866322 (the version the release left)');
t_xml_same($xfNorm(cms_fixture(X::PACKAGING, 866323)), $xfNorm($fake->sent[7] ?? ''), 'the packout matches log 866323, with the record CMS just made');
t_check(str_contains($fake->sent[7], 'UnitCostMK="6.201905"'), 'the packout cost is the batch cost plus the components');

/* The server's side of the packout: packout 139111 on the packaging ticket, its cost and container. */
$xfBump($w, 194761);
$w['sets']    = [194762 => [$xfSet(139110, 'MF'), $xfSet(139111, 'MFP')]] + $w['sets'];
$w['costs']   = $w['costs'] + [139110 => ['InventoryCost' => 81592, 'UnitCostMK' => '6.2019']];
$w['vessels'] = $w['vessels'] + [566197 => ['Location' => 119591, 'Version' => 1, 'LocationCode' => 'V031738']];

$p = $wr->preview('mfx.packout', ['qty' => '12', 'packages' => '3'] + $in, $now);
t_same(['Ask for the packout', 'Pack out'], array_column($p->rendered->stages, 'name'), 'a second packout skips what is already done');
t_check(str_contains($p->rendered->stages[1]['xml'], 'TrgLocation="119591"') && str_contains($p->rendered->stages[1]['xml'], 'InventoryCost="81592"')
    && !str_contains($p->rendered->stages[1]['xml'], 'CreateContainer'), 'it fills the container and cost record of the first');
t_check(count($p->plan->warnings) === 2 && str_contains($p->plan->warnings[0], 'More than the packaging ticket asks for (12 against 10 ea)')
    && str_contains($p->plan->warnings[1], 'packed before'), 'over-packing and a repeat packout are pointed out: ' . json_encode($p->plan->warnings));
t_check(str_contains($p->rendered->stages[1]['xml'], 'Quantity="2.58"') && str_contains($p->rendered->stages[1]['xml'], 'Quantity="12"'),
    'components and bulk follow the packaging ticket in proportion');
foreach ([
    'not its packaging ticket' => [['pack' => '194000'], 'is not a packaging ticket of'],
    'no quantity'              => [['qty' => ''], 'Quantity made'],
    'fraction of a package'    => [['packages' => '1.5'], 'whole number'],
    'no packages'              => [['packages' => '0'], 'whole number'],
] as $label => [$over, $needle]) {
    $xfRefused(static fn () => $wr->preview('mfx.packout', $over + $in, $now), $needle, $label);
}
$xfRefused(static fn () => $wr->preview('mfx.undo_make', ['ordr' => '194761'], $now), 'Undo its packouts first', 'undoing the make of a packed batch');

t_section('Execution flow: complete the ticket');
$in  = ['ordr' => '194761'];
$now = new DateTimeImmutable('2026-11-09T12:26:21');
$p   = $wr->preview('mfx.complete', $in, $now);
t_same([], $p->plan->warnings, 'a made, tested, packed batch completes without warnings');
t_check(str_contains(json_encode($p->plan->summary), '1 packout on 194762 (P123118)'), 'the summary lists what was packed: ' . json_encode($p->plan->summary));
t_check($wr->execute('mfx.complete', $in, $now, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($xfNorm(cms_fixture(X::COMPLETE, 866324)), $xfNorm($fake->sent[8] ?? ''), 'the request matches log 866324');
$xfBump($w, 194761, ['Status' => 'CMP']);
foreach (['mfx.complete', 'mfx.packout', 'mfx.undo_packout', 'mfx.undo_make', 'mfx.send_to_qc'] as $op) {
    $xfRefused(static fn () => $wr->preview($op, ['ordr' => '194761', 'pack' => '194762', 'qty' => '1', 'packages' => '1', 'changeSet' => '139111'], $now),
        'is completed already', "{$op} on a completed ticket");
}

t_section('Execution flow: the whole run in dry-run');
$w   = $xfWorld();
$dry = $xfWriter($xfCms($w), new DryRunTransport());
$res = $dry->execute('mfx.add_ingredient', ['ordr' => '194761', 'item' => 'R100849', 'qty' => '.22'], $now);
t_check($res->dryRun() && count($res->stages) === 1, 'add an ingredient runs in dry-run');
$res = $dry->execute('mfx.make_batch', ['ordr' => '194761'], $now);
t_check($res->dryRun() && count($res->stages) === 1, 'the make runs in dry-run');
$w['sets']     = [194761 => [$xfSet(139109, 'MF')]];
$w['receipts'] = [139109 => 31400];
$w['costs']    = [139109 => ['InventoryCost' => 81590, 'UnitCostMK' => '5.97078']];
$w['vessels']  = [566187 => ['Location' => 119589, 'Version' => 1, 'LocationCode' => 'VO194761']];
$res = $dry->execute('mfx.send_to_qc', ['ordr' => '194761'], $now);
t_check($res->dryRun() && count($res->stages) === 1, 'send for testing runs in dry-run');
$res = $dry->execute('mfx.packout', ['ordr' => '194761', 'pack' => '194762', 'qty' => '10', 'packages' => '2'], $now);
t_check($res->dryRun() && count($res->stages) === 5, 'all five packout requests are prepared in dry-run: ' . $res->describe());
$res = $dry->execute('mfx.complete', ['ordr' => '194761'], $now);
t_check($res->dryRun() && count($res->stages) === 1, 'complete runs in dry-run');
$res = $dry->execute('mfx.undo_make', ['ordr' => '194761'], $now);
t_check($res->dryRun() && count($res->stages) === 1, 'undo the make runs in dry-run');

t_section('Execution flow: a later packout into the same container');
$w = new \ArrayObject([
    'heads' => [194447 => $xfHead(194447, 13), 194449 => $xfHead(194449, 4, ['Context' => 'MFPP', 'Recipe' => 20001])],
    'lines' => [
        194447 => [
            $xfLine(564044, 'BA', ['ExecOrder' => 1]),
            $xfLine(564045, 'PK', ['Item' => 112535, 'ItemCode' => 'B112535', 'Description' => 'TEST GREEN', 'Unit' => 'lb', 'QtyReqd' => '1100',
                                   'Status' => 'STD', 'Parent' => 564044]),
            $xfUi(564046, 1, 101268, '1100', '1.5861', 2, 'lb', ['ExecStatus' => 'CMP']),
        ],
        194449 => [
            $xfLine(564058, 'PK', ['Item' => 112536, 'ItemCode' => 'P112536', 'Description' => 'TEST GREEN 5LB', 'Unit' => 'ea', 'QtyReqd' => '220']),
            $xfUi(564059, 1, 111075, '47.3', '1.075', null, 'ea'),
            $xfUi(564060, 2, 112535, '220', '4.1', null, 'lb', ['ItemCode' => 'B112535']),
        ],
    ],
    'packs'    => [194447 => [['Ordr' => 194449, 'Status' => 'RLS', 'ItemCode' => 'P112536', 'Qty' => '220']]],
    'sets'     => [194447 => [$xfSet(139090, 'MF')], 194449 => [$xfSet(139098, 'MF'), $xfSet(139099, 'MFP')]],
    'receipts' => [139090 => 31390],
    'costs'    => [139090 => ['InventoryCost' => 81580, 'UnitCostMK' => '4.1'], 139098 => ['InventoryCost' => 81584, 'UnitCostMK' => '4.3']],
    'vessels'  => [564044 => ['Location' => 119579, 'Version' => 3, 'LocationCode' => 'VO194447'],
                   564058 => ['Location' => 119582, 'Version' => 2, 'LocationCode' => 'V031730']],
    'sublots'  => [564045 => 61319, 564058 => 61320],
    'items'    => [],
]);
$fake = new FakeTransport();
$wr   = $xfWriter($xfCms($w), $fake);
$in   = ['ordr' => '194447', 'pack' => '194449', 'qty' => '4', 'packages' => '1'];
$now  = new DateTimeImmutable('2026-11-09T10:27:08');
$p    = $wr->preview('mfx.packout', $in, $now);
$res  = $wr->execute('mfx.packout', $in, $now, $p->hash);
t_check($res->ok() && count($fake->sent) === 2, 'two requests committed: ' . $res->describe());
t_xml_same($xfNorm(cms_fixture(X::PACK_REQUESTED, 866270)), $xfNorm($fake->sent[0] ?? ''), 'the packout request matches log 866270');
t_xml_same($xfNorm(cms_fixture(X::PACKAGING, 866271)), $xfNorm($fake->sent[1] ?? ''), 'the later packout matches log 866271');
$w['sets'] = [194449 => [$xfSet(139098, 'MF'), $xfSet(139099, 'MFP', 139120)]] + $w['sets'];
$w['vessels'] = [564044 => $w['vessels'][564044]];
$p = $wr->preview('mfx.packout', $in, $now);
t_check(str_contains($p->rendered->stages[1]['xml'], 'CreateContainer') && str_contains($p->rendered->stages[1]['xml'], 'ChangeSet="139098"'),
    'after the only packout was undone the next one is a first packout again, on the record the ticket already has');
$w['vessels'] = [];
$xfRefused(static fn () => $wr->preview('mfx.packout', $in, $now), 'vessel or the lot', 'a batch whose vessel is not found');
$xfRefused(static fn () => $wr->preview('mfx.send_to_qc', $in, $now), 'vessel or the lot', 'testing a batch whose vessel is not found');

t_section('Execution flow: undo a packout');
$w = new \ArrayObject([
    'heads' => [194100 => $xfHead(194100, 11), 194101 => $xfHead(194101, 5, ['Context' => 'MFPP', 'Recipe' => 20002])],
    'lines' => [
        194100 => [
            $xfLine(561492, 'BA', ['ExecOrder' => 1]),
            $xfLine(561493, 'PK', ['Item' => 111310, 'ItemCode' => 'B111310', 'Description' => 'TEST BLACK', 'Unit' => 'lb', 'QtyReqd' => '50',
                                   'Status' => 'STD', 'Parent' => 561492]),
            $xfUi(561494, 1, 101268, '50', '1.5861', 2, 'lb', ['ExecStatus' => 'CMP']),
        ],
        194101 => [
            $xfLine(561507, 'PK', ['Item' => 111311, 'ItemCode' => 'P111311', 'Description' => 'TEST BLACK 5LB', 'Unit' => 'ea', 'QtyReqd' => '10']),
            $xfUi(561508, 1, 111075, '2.15', '1.075', null, 'ea'),
            $xfUi(561509, 2, 111310, '50', '3.2', null, 'lb', ['ItemCode' => 'B111310']),
        ],
    ],
    'packs'    => [194100 => [['Ordr' => 194101, 'Status' => 'RLS', 'ItemCode' => 'P111311', 'Qty' => '50']]],
    'sets'     => [194100 => [$xfSet(138900, 'MF')], 194101 => [$xfSet(138910, 'MF'), $xfSet(138911, 'MFP', 138915), $xfSet(138917, 'MFP')]],
    'receipts' => [138900 => 31380],
    'costs'    => [138900 => ['InventoryCost' => 81500, 'UnitCostMK' => '3.2'], 138910 => ['InventoryCost' => 81501, 'UnitCostMK' => '16.2']],
    'vessels'  => [561492 => ['Location' => 119400, 'Version' => 2, 'LocationCode' => 'VO194100'],
                   561507 => ['Location' => 119401, 'Version' => 2, 'LocationCode' => 'V031700']],
    'sublots'  => [561493 => 61200, 561507 => 61201],
    'items'    => [],
]);
$fake = new FakeTransport();
$wr   = $xfWriter($xfCms($w), $fake);
$in   = ['ordr' => '194100', 'pack' => '194101', 'changeSet' => '138917'];
$now  = new DateTimeImmutable('2026-11-06T08:36:02');
$p    = $wr->preview('mfx.undo_packout', $in, $now);
t_check(str_contains(json_encode($p->plan->summary), 'number 1 of 1 on packaging ticket 194101'), 'the summary names the packout: ' . json_encode($p->plan->summary));
t_check($wr->execute('mfx.undo_packout', $in, $now, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($xfNorm(cms_fixture(X::UNPACKAGING, 865478)), $xfNorm($fake->sent[0] ?? ''), 'the request matches log 865478');
$xfRefused(static fn () => $wr->preview('mfx.undo_packout', ['changeSet' => '138911'] + $in, $now), 'undone already', 'a packout already undone');
$xfRefused(static fn () => $wr->preview('mfx.undo_packout', ['changeSet' => '138910'] + $in, $now), 'was not found', 'the production record is not a packout');
$xfRefused(static fn () => $wr->preview('mfx.undo_packout', ['pack' => '194762'] + $in, $now), 'is not a packaging ticket of', 'another ticket');
$xfBump($w, 194101, ['Status' => 'CMP']);
$xfRefused(static fn () => $wr->preview('mfx.undo_packout', $in, $now), 'is completed', 'a completed packaging ticket');

t_section('Execution flow: undo the make, then make again');
$w = new \ArrayObject([
    'heads' => [194072 => $xfHead(194072, 6)],
    'lines' => [194072 => [
        $xfLine(561264, 'BA', ['ExecOrder' => 1]),
        $xfLine(561265, 'PK', ['Item' => 111400, 'ItemCode' => 'B111400', 'Description' => 'TEST WHITE', 'Unit' => 'lb', 'QtyReqd' => '500',
                               'Status' => 'STD', 'Parent' => 561264]),
        $xfLine(561266, 'UB'),
        $xfUi(561267, 1, 107853, '150', '2', 3, 'lb', ['ExecStatus' => 'CMP']),
        $xfUi(561268, 2, 100837, '35', '1', 4, 'lb', ['ExecStatus' => 'CMP']),
        $xfUi(561269, 3, 101129, '310', '1.5', 5, 'lb', ['ExecStatus' => 'CMP']),
        $xfUi(561270, 4, 100837, '5', '1', 6, 'lb', ['ExecStatus' => 'CMP']),
        $xfLine(561271, 'INSTR', ['ExecOrder' => 2, 'ExecStatus' => 'CMP']),
        $xfLine(561276, 'IPT', ['ExecOrder' => 7, 'ExecStatus' => 'CMP']),
        $xfLine(561275, 'FT', ['ExecStatus' => 'CMP']),
    ]],
    'packs'    => [],
    'sets'     => [194072 => [$xfSet(138800, 'MF'), $xfSet(138801, 'MFP', 138802)]],
    'receipts' => [138800 => 31371],
    'costs'    => [138800 => ['InventoryCost' => 81400, 'UnitCostMK' => '1.6']],
    'vessels'  => [561264 => ['Location' => 119331, 'Version' => 4, 'LocationCode' => 'VO194072']],
    'sublots'  => [561265 => 61100],
    'items'    => [],
]);
$fake = new FakeTransport();
$wr   = $xfWriter($xfCms($w), $fake);
$in   = ['ordr' => '194072'];
$now  = new DateTimeImmutable('2026-11-05T16:09:55');
$p    = $wr->preview('mfx.undo_make', $in, $now);
t_check(count($p->plan->warnings) === 1 && str_contains($p->plan->warnings[0], 'fresh test step'), 'a tested batch gets a fresh test step: ' . json_encode($p->plan->warnings));
t_check($wr->execute('mfx.undo_make', $in, $now, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($xfNorm(cms_fixture(X::REVERSE, 865041)), $xfNorm($fake->sent[0] ?? ''), 'the request matches log 865041');
$xfTouch($w, 194072, 561276, ['ExecStatus' => null]);
$p = $wr->preview('mfx.undo_make', $in, $now);
t_check($p->plan->warnings === [] && !str_contains($p->rendered->stages[0]['xml'], 'CloneFIPT'), 'an untested batch is undone without a fresh test step');
$xfTouch($w, 194072, 561276, ['ExecStatus' => 'CMP']);

/* The server's side of the undo: the receipt is reversed, the record and vessel stay, a new final test step is open. */
$xfBump($w, 194072);
$w['receipts'] = [];
$lines = $w['lines'];
$lines[194072][] = $xfLine(561277, 'FT');
$w['lines'] = $lines;
$xfRefused(static fn () => $wr->preview('mfx.undo_make', $in, $now), 'has not been made yet', 'undoing twice');
$in  = ['ordr' => '194072', 'qty' => [561267 => '', 561268 => '45.5', 561269 => '403', 561270 => '16.5']];
$now = new DateTimeImmutable('2026-11-05T16:10:08');
$p   = $wr->preview('mfx.make_batch', $in, $now);
t_check(!str_contains(json_encode($p->plan->summary), 'Estimated cost') && str_contains(json_encode($p->plan->summary), 'again'),
    'a make run again keeps its cost record: ' . json_encode($p->plan->summary));
t_check($wr->execute('mfx.make_batch', $in, $now, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($xfNorm(cms_fixture(X::MAKE, 865042)), $xfNorm($fake->sent[1] ?? ''), 'the make run again matches log 865042');
$w['vessels'] = [];
$xfRefused(static fn () => $wr->preview('mfx.make_batch', $in, $now), 'vessel was not found', 'a make run again without its vessel');

t_section('Execution flow: tickets the floor flows leave alone, and permissions');
$w = $xfWorld();
$wr = $xfWriter($xfCms($w), new FakeTransport());
$now = new DateTimeImmutable('2026-11-09T12:00:00');
$heads = $w['heads'];
$heads[194761] = ['Status' => 'NST', 'UserHold' => 'HOLD', 'DateReleased' => null] + $heads[194761];
$w['heads'] = $heads;
$xfRefused(static fn () => $wr->preview('mfx.make_batch', ['ordr' => '194761'], $now), 'not released yet', 'an unreleased ticket');
$heads[194761] = ['Status' => 'RLS', 'UserHold' => null, 'DateReleased' => '2026-11-09T00:00:00', 'Recipe' => null] + $heads[194761];
$w['heads'] = $heads;
$xfRefused(static fn () => $wr->preview('mfx.make_batch', ['ordr' => '194761'], $now), 'not cut from a single recipe', 'a hand-built ticket');
$w = $xfWorld();
$w['sets']     = [194761 => [$xfSet(139109, 'MF'), $xfSet(139200, 'MFP')]];
$w['receipts'] = [139109 => 31400];
$w['vessels']  = [566187 => ['Location' => 119589, 'Version' => 1, 'LocationCode' => 'VO194761']];
$wr = $xfWriter($xfCms($w), new FakeTransport());
$xfRefused(static fn () => $wr->preview('mfx.packout', ['ordr' => '194761', 'pack' => '194762', 'qty' => '1', 'packages' => '1'], $now),
    'packed directly', 'a batch packed without a packaging ticket');
$xfRefused(static fn () => $wr->preview('mfx.undo_make', ['ordr' => '194761'], $now), 'Undo its packouts first', 'undoing the make of a directly packed batch');

$some = $xfWriter($xfCms($xfWorld()), new FakeTransport(), ['write.mfx.make_batch']);
t_check($some->allowed('mfx.make_batch') && !$some->allowed('mfx.packout') && !$some->allowed('mfx.undo_make') && !$some->allowed('mfx.add_ingredient'),
    'each floor action has its own permission');
$undo = $xfWriter($xfCms($xfWorld()), new FakeTransport(), ['write.mfx.undo']);
t_check($undo->allowed('mfx.undo_make') && $undo->allowed('mfx.undo_packout') && !$undo->allowed('mfx.make_batch'), 'one permission covers both undos');

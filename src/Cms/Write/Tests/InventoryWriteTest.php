<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\FakeCmsReader;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Ops\Inventory\InventoryRequests as IR;
use PII\Cms\Write\TransportResult;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

t_section('Inventory golden: InventoryCountDataSet.SaveChanges');
$shapes = [];
foreach (cms_fixture_ids(IR::SAVE) as $log) {
    $xml = cms_fixture(IR::SAVE, $log);
    $f   = cms_fixture_parse($xml);
    $s   = $f['steps'];
    $first = $s[0];
    if ($first['operation'] === 'Insert') {
        $shape = 'create';
        $lines = [];
        foreach (array_slice($s, 1) as $l) {
            t_same($l['data']['QtyEntered'], $l['data']['Qty'], "log {$log}: a line's Qty is what was typed");
            $lines[] = ['item' => $l['data']['Item'], 'qty' => $l['data']['QtyEntered'], 'location' => $l['data']['Location']];
        }
        $r = IR::createCount($first['data']['Owner'], $first['data']['Description'] ?? '', $first['data']['EffectiveDate'], $lines);
    } elseif ($first['operation'] === 'Delete') {
        $shape = 'delete';
        $head  = end($s);
        $r = IR::deleteCount($head['keys']['InventoryCount'], $head['keys']['Version'],
            array_map(static fn (array $l): string => $l['keys']['InventoryCountDetail'], array_slice($s, 0, -1)));
    } else {
        $shape = $first['operation'] === 'Update' ? 'edit head' : 'edit lines';
        $r = IR::editCount($first['keys']['InventoryCount'], $first['keys']['Version'], $first['data'],
            array_map(static fn (array $l): array => ['detail' => $l['keys']['InventoryCountDetail'], 'qty' => $l['data']['QtyEntered']],
                array_slice($s, 1)));
    }
    $shapes[$shape] = true;
    t_same(IR::CMS_COUNT, $f['program'], "log {$log}: sent by the client's count screen");
    t_xml_same($xml, $r->toXml($f['env']), "log {$log} ({$shape})");
}
ksort($shapes);
t_same(['create', 'delete', 'edit head', 'edit lines'], array_keys($shapes), 'fixtures cover create, edit (head, lines) and delete');

t_section('Inventory golden: InventoryCountDataSet.Post');
$n = 0;
foreach (cms_fixture_ids(IR::POST) as $log) {
    $xml = cms_fixture(IR::POST, $log);
    $f   = cms_fixture_parse($xml);
    $s   = $f['steps'];
    $adjust = $moves = [];
    $record = null;
    foreach ($s as $step) {
        if ($step['object'] === 'InventoryCountDetail') {
            $adjust[] = [$step['keys']['InventoryCountDetail'], $step['data']['QtyAdjust']];
        } elseif ($step['object'] === 'CreateChangeSet') {
            $record = $step['data'];
        } elseif ($step['object'] === 'MoveInventory') {
            $moves[] = $step['data'];
        }
    }
    $lines = [];
    foreach ($adjust as $i => [$detail, $adj]) {
        $lines[] = ['detail' => $detail, 'item' => $moves[$i]['Item'], 'location' => $moves[$i]['SrcLocation'],
                    'qty' => $moves[$i]['QtyEntered'], 'adjust' => $adj];
        // What the client sends as the adjustment is counted minus on hand; the move is its negative.
        $onHand = (float) $moves[$i]['QtyEntered'] - (float) $adj;
        t_same($adj, IR::adjustment((float) $moves[$i]['QtyEntered'], $onHand), "log {$log}: adjustment of line {$detail} prints as the client prints it");
    }
    t_xml_same($xml, IR::postCount($s[0]['keys']['InventoryCount'], $s[0]['keys']['Version'], $record['Owner'], $record['ChangeDate'], $lines)
        ->toXml($f['env']), "log {$log}");
    $n++;
}
t_check($n >= 3, "{$n} post fixtures");

t_section('Inventory golden: label flag and planned dates');
foreach (cms_fixture_ids(IR::RESET_LABEL) as $log) {
    $xml = cms_fixture(IR::RESET_LABEL, $log);
    $f   = cms_fixture_parse($xml);
    t_same(IR::CMS_LABEL, $f['program'], "log {$log}: program");
    t_xml_same($xml, IR::resetLabel($f['steps'][0]['data']['Location'])->toXml($f['env']), "log {$log}");
}
foreach (cms_fixture_ids(IR::RECALC_PLAN) as $log) {
    $xml = cms_fixture(IR::RECALC_PLAN, $log);
    $f   = cms_fixture_parse($xml);
    t_same(IR::CMS_PLAN, $f['program'], "log {$log}: program");
    t_xml_same($xml, IR::recalculatePlan($f['steps'][0]['data']['Owner'])->toXml($f['env']), "log {$log}");
}

t_section('Inventory: doubles as the client prints them');
foreach ([
    ['304.8', 304.8], ['-299.73399996059965', 2 - 301.73399996059965], ['83.600000000000023', 83.600000000000023],
    ['0.30000000000000004', 0.1 + 0.2], ['5', 5.0], ['-5', -5.0], ['0', 0.0], ['0', -0.0], ['0.1', 0.1],
    ['1E-07', 1.0e-7], ['1.5E-05', 1.5e-5], ['1E+16', 1.0e16], ['123456789012345', 123456789012345.0],
] as [$text, $f]) {
    t_same($text, IR::dbl($f), "{$text}");
}
t_same('299.73399996059965', IR::negate('-299.73399996059965'), 'a shortage moves stock out');
t_same('-304.8', IR::negate('304.8'), 'a surplus moves stock in');
t_same('-0', IR::negate('0'), 'no difference is sent as -0, as the client does');
t_check(t_throws(\InvalidArgumentException::class, static fn () => IR::createCount('4', '', '2026-11-08T00:00:00', [])) !== null, 'a count without lines is not built');
t_check(t_throws(\InvalidArgumentException::class, static fn () => IR::editCount(1, 1, [], [])) !== null, 'an edit without changes is not built');

/* ── Flows: what a user's input becomes ── */

$ivPerms  = array_keys(\PII\Cms\Write\OperationRegistry::permissionsFor('inventory'));
$ivWriter = static fn (FakeCmsReader $cms, $transport, ?array $perms = null): \PII\Cms\Write\CmsWriter => new \PII\Cms\Write\CmsWriter(
    $cms, $transport, static fn (string $p) => in_array($p, $perms ?? $ivPerms, true), 'pic\\user1', 'WS1', true, '7.15.0.680',
    static function (array $row): void {});
$ivNorm = static fn (string $xml): string => preg_replace(['/Application="[^"]+"/', '/ TransDate="[^"]+"/'], ['Application="A"', ' TransDate="T"'], $xml);
$ivRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle),
        "refused: {$label}" . ($e !== null ? ' [' . $e->getMessage() . ']' : ' (not refused)'));
};
/**
 * A fake CMS: 'items' [code => [Item, Unit]], 'stock' [item => on-hand text],
 * 'counts' [id => head row], 'lines' [count => detail rows], 'containers' [id => row].
 */
$ivCms = static function (\ArrayObject $w): FakeCmsReader {
    return (new FakeCmsReader())
        ->when('FROM CMS.dbo.Item i WHERE i.ItemCode = ?', static fn (array $p) => isset($w['items'][$p[0]])
            ? [['Item' => $w['items'][$p[0]][0], 'ItemCode' => $p[0], 'Description' => "ITEM {$p[0]}", 'Unit' => $w['items'][$p[0]][1]]] : [])
        ->when('FROM CMS.dbo.Inventory v WHERE v.Item = ?', static fn (array $p) => [['OnHand' => $w['stock'][(int) $p[0]] ?? null]])
        ->when('FROM CMS.dbo.InventoryCount c WHERE', static fn (array $p) => isset($w['counts'][(int) $p[0]]) ? [$w['counts'][(int) $p[0]]] : [])
        ->when('FROM CMS.dbo.InventoryCountDetail d JOIN', static fn (array $p) => $w['lines'][(int) $p[0]] ?? [])
        ->when('FROM CMS.dbo.Location l WHERE', static fn (array $p) => isset($w['containers'][(int) $p[0]]) ? [$w['containers'][(int) $p[0]]] : []);
};
$ivWorld = static fn (): \ArrayObject => new \ArrayObject([
    'items'  => ['R101261' => [101261, 'lb'], 'R110871' => [110871, 'lb'], 'P123115' => [123115, 'ea']],
    'stock'  => [101261 => '3.0173399996059965e+002', 123115 => '5.0000000000000000e+000'],
    'counts' => [
        1440 => ['InventoryCount' => 1440, 'Version' => 1, 'Owner' => '4', 'Description' => 'cycle count',
                 'EffectiveDate' => '2026-01-29T00:00:00', 'Posted' => 0, 'ChangeSet' => null],
        1579 => ['InventoryCount' => 1579, 'Version' => 1, 'Owner' => '4', 'Description' => 'cycle count',
                 'EffectiveDate' => '2026-10-27T00:00:00', 'Posted' => 0, 'ChangeSet' => null],
        1586 => ['InventoryCount' => 1586, 'Version' => 2, 'Owner' => '4', 'Description' => 'cycle count',
                 'EffectiveDate' => '2026-11-08T00:00:00', 'Posted' => 1, 'ChangeSet' => 139046],
    ],
    'lines' => [
        1440 => [['InventoryCountDetail' => 38099, 'Item' => 101261, 'Location' => 10669, 'QtyEntered' => '240', 'Qty' => '2.4000000000000000e+002',
                  'QtyAdjust' => null, 'ItemCode' => 'R101261', 'Description' => 'ITEM', 'Unit' => 'lb', 'OnHand' => '3.0173399996059965e+002']],
        1579 => [
            ['InventoryCountDetail' => 38393, 'Item' => 101261, 'Location' => 10669, 'QtyEntered' => '2', 'Qty' => '2.0000000000000000e+000',
             'QtyAdjust' => null, 'ItemCode' => 'R101261', 'Description' => 'ITEM', 'Unit' => 'lb', 'OnHand' => '3.0173399996059965e+002'],
            ['InventoryCountDetail' => 38394, 'Item' => 110871, 'Location' => 10669, 'QtyEntered' => '304.8', 'Qty' => '3.0480000000000001e+002',
             'QtyAdjust' => null, 'ItemCode' => 'R110871', 'Description' => 'ITEM', 'Unit' => 'lb', 'OnHand' => null],
        ],
    ],
    'containers' => [
        119590 => ['Location' => 119590, 'LocationCode' => 'C119590', 'Context' => 'CONT', 'LabelReqd' => 1],
        119591 => ['Location' => 119591, 'LocationCode' => 'C119591', 'Context' => 'CONT', 'LabelReqd' => 0],
    ],
]);

t_section('Inventory registry');
t_same(['write.inv.count', 'write.inv.count_post', 'write.inv.reset_label', 'write.inv.recalc_plan'], $ivPerms, 'four inventory write permissions');
foreach (['inv.count_create', 'inv.count_edit', 'inv.count_delete', 'inv.count_post', 'inv.count_create_post', 'inv.reset_label', 'inv.recalc_plan'] as $op) {
    t_check(\PII\Cms\Write\OperationRegistry::get($op) !== null, "{$op} is registered");
}

t_section('Inventory flow: save a new count');
$w    = $ivWorld();
$fake = new FakeTransport();
$wr   = $ivWriter($ivCms($w), $fake);
$now  = new DateTimeImmutable('2026-10-28T15:03:23');
$in   = ['date' => '2026-10-27', 'description' => ' cycle  count ', 'lines' => [
    ['item' => 'R101261', 'qty' => '2'], ['item' => '', 'qty' => ''], ['item' => ' R110871 ', 'qty' => '304.80'],
]];
$p = $wr->preview('inv.count_create', $in, $now);
t_same(1, count($p->rendered->stages), 'saving is one request');
$sum = json_encode($p->plan->summary);
t_check(str_contains($sum, '2 lb counted, 301.734 on hand: removes 299.734 lb'), 'the review shows what posting would remove: ' . $sum);
t_check(str_contains($sum, '304.8 lb counted, 0 on hand: adds 304.8 lb'), 'and what it would add');
t_check($wr->execute('inv.count_create', $in, $now, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($ivNorm(cms_fixture(IR::SAVE, 861111)), $ivNorm($fake->sent[0] ?? ''), 'the request matches log 861111');
foreach ([
    'no lines'        => [['lines' => []], 'at least one item'],
    'unknown item'    => [['lines' => [['item' => 'NOPE', 'qty' => '1']]], 'was not found'],
    'item twice'      => [['lines' => [['item' => 'R101261', 'qty' => '1'], ['item' => 'R101261', 'qty' => '2']]], 'twice'],
    'no quantity'     => [['lines' => [['item' => 'R101261', 'qty' => '']]], 'needs a number'],
    'negative'        => [['lines' => [['item' => 'R101261', 'qty' => '-1']]], 'zero or more'],
    'quantity alone'  => [['lines' => [['item' => '', 'qty' => '4']]], 'no item'],
    'bad date'        => [['date' => '10/27/2026'], 'not a valid date'],
    'future date'     => [['date' => '2026-10-29'], 'future'],
    'long description' => [['description' => str_repeat('x', 51)], 'too long'],
] as $label => [$over, $needle]) {
    $ivRefused(static fn () => $wr->preview('inv.count_create', $over + $in, $now), $needle, $label);
}
t_check(!$ivWriter($ivCms($w), $fake, ['write.inv.count_post'])->allowed('inv.count_create'), 'saving needs its own permission');

t_section('Inventory flow: save and post in one go');
$w    = $ivWorld();
$fake = new FakeTransport();
$fake->willReturn(new TransportResult(TransportResult::COMMITTED, 861111, 0, null, 'ok', [
    ['step' => 1, 'table' => 'InventoryCount', 'field' => 'InventoryCount', 'value' => '1579'],
    ['step' => 2, 'table' => 'InventoryCountDetail', 'field' => 'InventoryCountDetail', 'value' => '38393'],
    ['step' => 3, 'table' => 'InventoryCountDetail', 'field' => 'InventoryCountDetail', 'value' => '38394'],
]));
$wr = $ivWriter($ivCms($w), $fake);
$p  = $wr->preview('inv.count_create_post', $in, $now);
t_same(2, count($p->rendered->stages), 'two requests: save, then post');
t_same(1, count($p->plan->warnings), 'the review warns that posting changes stock');
t_check($wr->execute('inv.count_create_post', $in, $now, $p->hash)->ok(), 'both committed');
t_xml_same($ivNorm(cms_fixture(IR::SAVE, 861111)), $ivNorm($fake->sent[0] ?? ''), 'the save matches log 861111');
t_xml_same($ivNorm(cms_fixture(IR::POST, 861112)), $ivNorm($fake->sent[1] ?? ''), 'the post matches log 861112, with the ids the save returned');
t_check(!$ivWriter($ivCms($w), $fake, ['write.inv.count'])->allowed('inv.count_create_post'), 'posting needs the posting permission');

t_section('Inventory flow: post a saved count');
$w    = $ivWorld();
$fake = new FakeTransport();
$wr   = $ivWriter($ivCms($w), $fake);
$p    = $wr->preview('inv.count_post', ['count' => '1579'], $now);
t_check($wr->execute('inv.count_post', ['count' => '1579'], $now, $p->hash)->ok(), 'committed');
t_xml_same($ivNorm(cms_fixture(IR::POST, 861112)), $ivNorm($fake->sent[0] ?? ''), 'the request matches log 861112');
$ivRefused(static fn () => $wr->preview('inv.count_post', ['count' => '1586'], $now), 'already posted', 'a posted count');
$ivRefused(static fn () => $wr->preview('inv.count_post', ['count' => '7'], $now), 'was not found', 'an unknown count');
$w['lines'] = [1579 => []] + $w['lines'];
$ivRefused(static fn () => $wr->preview('inv.count_post', ['count' => '1579'], $now), 'no lines', 'a count without lines');

t_section('Inventory flow: change and delete a count');
$w    = $ivWorld();
$fake = new FakeTransport();
$wr   = $ivWriter($ivCms($w), $fake);
$now  = new DateTimeImmutable('2026-01-29T09:20:07');
$in   = ['count' => 1440, 'description' => 'cycle count', 'date' => '2026-01-29', 'qty' => [38099 => '239.1']];
$p    = $wr->preview('inv.count_edit', $in, $now);
t_check($wr->execute('inv.count_edit', $in, $now, $p->hash)->ok(), 'committed');
t_xml_same($ivNorm(cms_fixture(IR::SAVE, 768178)), $ivNorm($fake->sent[0] ?? ''), 'a changed quantity matches log 768178');
$in = ['count' => 1440, 'description' => 'Adjust', 'qty' => [38099 => '240']];
$p  = $wr->preview('inv.count_edit', $in, $now);
$wr->execute('inv.count_edit', $in, $now, $p->hash);
t_check(str_contains($fake->sent[1] ?? '', '<action Object="InventoryCount" Step="1" Operation="Update"><keys InventoryCount="1440" Version="1"/><data Description="Adjust"/></action></request>'),
    'a changed description is the header update of log 735520, and an unchanged quantity is not sent');
$in = ['count' => 1440, 'date' => '2026-01-28', 'qty' => [38099 => '1']];
$p  = $wr->preview('inv.count_edit', $in, $now);
t_check(str_contains($p->rendered->stages[0]['xml'] ?? json_encode($p->rendered->stages), 'EffectiveDate=\"2026-01-28T00:00:00\"')
    || str_contains(json_encode($p->rendered->stages), '2026-01-28T00:00:00'), 'a changed date goes on the header');
foreach ([
    'nothing changed' => [['count' => 1440, 'qty' => [38099 => '240']], 'Nothing was changed'],
    'unknown line'    => [['count' => 1440, 'qty' => [5 => '1']], 'has changed in CMS'],
    'posted'          => [['count' => 1586, 'description' => 'x'], 'already posted'],
    'bad quantity'    => [['count' => 1440, 'qty' => [38099 => 'ten']], 'needs a number'],
] as $label => [$input, $needle]) {
    $ivRefused(static fn () => $wr->preview('inv.count_edit', $input, $now), $needle, $label);
}
$w['counts'] = [1498 => ['InventoryCount' => 1498, 'Version' => 1, 'Owner' => '4', 'Description' => '', 'EffectiveDate' => '2026-04-22T00:00:00',
                         'Posted' => 0, 'ChangeSet' => null]] + $w['counts'];
$w['lines']  = [1498 => [
    ['InventoryCountDetail' => 38246, 'Item' => 101261, 'Location' => 10669, 'QtyEntered' => '1', 'Qty' => '1', 'QtyAdjust' => null,
     'ItemCode' => 'R101261', 'Description' => 'ITEM', 'Unit' => 'lb', 'OnHand' => null],
    ['InventoryCountDetail' => 38247, 'Item' => 110871, 'Location' => 10669, 'QtyEntered' => '1', 'Qty' => '1', 'QtyAdjust' => null,
     'ItemCode' => 'R110871', 'Description' => 'ITEM', 'Unit' => 'lb', 'OnHand' => null],
]] + $w['lines'];
$fake = new FakeTransport();
$wr   = $ivWriter($ivCms($w), $fake);
$p    = $wr->preview('inv.count_delete', ['count' => 1498], $now);
t_check($wr->execute('inv.count_delete', ['count' => 1498], $now, $p->hash)->ok(), 'committed');
t_xml_same($ivNorm(cms_fixture(IR::SAVE, 798764)), $ivNorm($fake->sent[0] ?? ''), 'the delete matches log 798764');
$ivRefused(static fn () => $wr->preview('inv.count_delete', ['count' => 1586], $now), 'already posted', 'deleting a posted count');

t_section('Inventory flow: label flag and planned dates');
$w    = $ivWorld();
$fake = new FakeTransport();
$wr   = $ivWriter($ivCms($w), $fake);
$p    = $wr->preview('inv.reset_label', ['location' => 119590], $now);
$wr->execute('inv.reset_label', ['location' => 119590], $now, $p->hash);
t_xml_same($ivNorm(cms_fixture(IR::RESET_LABEL, 866316)), $ivNorm($fake->sent[0] ?? ''), 'the request matches log 866316');
$ivRefused(static fn () => $wr->preview('inv.reset_label', ['location' => 119591], $now), 'not waiting', 'a container that needs no label');
$ivRefused(static fn () => $wr->preview('inv.reset_label', ['location' => 1], $now), 'was not found', 'an unknown container');
$p = $wr->preview('inv.recalc_plan', [], $now);
$wr->execute('inv.recalc_plan', [], $now, $p->hash);
t_xml_same($ivNorm(cms_fixture(IR::RECALC_PLAN, 866076)), $ivNorm($fake->sent[1] ?? ''), 'the request matches log 866076');

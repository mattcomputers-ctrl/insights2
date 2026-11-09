<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\FakeCmsReader;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Ops\MasterData\ItemRequests as IR;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

t_section('Item golden: ItemUpdateDataSet.SaveChanges');
$itShapes = [];
foreach (cms_fixture_ids(IR::ITEM_SAVE) as $log) {
    $xml = cms_fixture(IR::ITEM_SAVE, $log);
    $f   = cms_fixture_parse($xml);
    $s   = $f['steps'];
    $own = $f['env']->owner ?? '4';
    $ops = implode(' ', array_map(static fn (array $x): string => $x['operation'] . ' ' . $x['object'], $s));
    t_same(IR::CMS_ITEM, $f['program'], "log {$log}: sent by the client's item screen");
    $trail = $s[max(0, count($s) - 2)]['data'];
    if ($ops === 'UpdateVersion Item Update ItemEntity Execute InheritItemsPropertiesByPackagedProducts Execute FixupItemManf') {
        $itShapes['minimum (update)'] = true;
        t_xml_same($xml, IR::minimumStockUpdate($s[0]['keys']['Item'], $s[0]['keys']['Version'], $s[1]['keys']['ItemEntity'], $s[1]['data']['MinimumStock'], $trail['Owner'])->toXml($f['env']), "log {$log} (minimum stock, update)");
    } elseif ($ops === 'UpdateVersion Item Insert ItemEntity Execute InheritItemsPropertiesByPackagedProducts Execute FixupItemManf') {
        $itShapes['minimum (insert)'] = true;
        t_xml_same($xml, IR::minimumStockInsert($s[0]['keys']['Item'], $s[0]['keys']['Version'], $s[1]['data']['MinimumStock'], $trail['Owner'])->toXml($f['env']), "log {$log} (minimum stock, insert)");
    } elseif ($ops === 'Update Item Execute InheritItemsPropertiesByPackagedProducts Execute FixupItemManf' && array_keys($s[0]['data']) === ['Supplier']) {
        $itShapes['supplier'] = true;
        t_xml_same($xml, IR::supplierUpdate($s[0]['keys']['Item'], $s[0]['keys']['Version'], $s[0]['data']['Supplier'], $trail['Owner'])->toXml($f['env']), "log {$log} (supplier)");
    } elseif ($ops === 'Insert Item Insert ItemChemical Insert ItemCustom Execute InheritItemsPropertiesByPackagedProducts Execute FixupItemManf') {
        $itShapes['create'] = true;
        $d = $s[0]['data'];
        t_xml_same($xml, IR::itemCreate($d['ItemCode'], $d['Description'], $d['Unit'], $d['GLGroup'], $s[2]['data']['Group'],
            $d['ShipInContainer'] === 'true', $d['CreateUser'], $d['CreateArea'], $s[0]['keys']['Version'])->toXml($f['env']), "log {$log} (create)");
        // Each create fixture is one of the kinds Insights offers, with that kind's settings and nothing else.
        $kindKey = $d['GLGroup'] === 'Packaging' ? 'packaging' : 'raw';
        $kind    = IR::KINDS[$kindKey];
        $itShapes["create ({$kindKey})"] = true;
        t_same([$d['Unit'], $d['GLGroup'], $s[2]['data']['Group'], $d['ShipInContainer'] === 'true'],
            [$kind['unit'], $kind['glGroup'], $kind['group'], $kind['shipInContainer']], "log {$log}: the settings of the {$kindKey} kind");
        t_same('WHS', $d['Treatment'], "log {$log}: stock of the item is tracked");
        t_check(!isset($d['Supplier']) && !isset($s[1]['data']['ConcentrationPercent']), "log {$log}: no supplier and no concentration, as Insights sends it");
    } else {
        // Products with pack sizes and tests, new pack sizes, test changes and deleting an item stay in the client.
        $itShapes['not built'] = true;
        t_check(!str_ends_with($ops, 'Execute InheritItemsPropertiesByPackagedProducts Execute FixupItemManf') || str_contains($ops, 'ItemPackagedProduct') || str_contains($ops, 'ItemTest') || str_contains($ops, 'Delete'), "log {$log}: a shape that is left to the client ({$ops})");
    }
}
ksort($itShapes);
t_same(['create', 'create (packaging)', 'create (raw)', 'minimum (insert)', 'minimum (update)', 'not built', 'supplier'], array_keys($itShapes), 'fixtures cover what is built, both kinds of new item included');
t_same('ea', IR::itemCreate('X', 'Y', 'ea', 'Packaging', 'Packaging Material', false, 'u', '4')->steps()[0]->first('data')['Unit'], 'a packaging item counts in each');

/* ── Flows ── */

$itPerms  = ['write.item.settings', 'write.item.create'];
$itWriter = static fn (FakeCmsReader $cms, $transport, ?array $perms = null): \PII\Cms\Write\CmsWriter => new \PII\Cms\Write\CmsWriter(
    $cms, $transport, static fn (string $p) => in_array($p, $perms ?? $itPerms, true), 'pic\\user1', 'WS1', true, '7.15.0.680',
    static function (array $row): void {});
$itNorm = static fn (string $xml): string => preg_replace(['/Application="[^"]+"/', '/ TransDate="[^"]+"/'], ['Application="A"', ' TransDate="T"'], $xml);
$itRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle),
        "refused: {$label}" . ($e !== null ? ' [' . $e->getMessage() . ']' : ' (not refused)'));
};
/**
 * A fake CMS. Items: [Item, ItemCode, Description, Context, Version, ReplacedBy, Supplier, Unit];
 * settings: item id => [ItemEntity, MinimumStock]; suppliers: [Entity, EntityCode, EntityName, Context, Inactive].
 */
$itCms = static function (array $rows, array $settings, array $suppliers): FakeCmsReader {
    $row = static fn (?array $r): array => $r === null ? [] : [[
        'Item' => $r[0], 'ItemCode' => $r[1], 'Description' => $r[2], 'Context' => $r[3], 'Version' => $r[4],
        'ReplacedBy' => $r[5], 'Supplier' => $r[6], 'Unit' => $r[7]]];
    $find = static function (int $col, mixed $v) use ($rows): ?array {
        foreach ($rows as $r) {
            if ((string) $r[$col] === (string) $v) {
                return $r;
            }
        }
        return null;
    };
    return (new FakeCmsReader())
        ->when('FROM CMS.dbo.Item i WHERE i.Item = ?', static fn (array $p) => $row($find(0, $p[0])))
        ->when('FROM CMS.dbo.Item i WHERE i.ItemCode = ?', static fn (array $p) => $row($find(1, $p[0])))
        ->when("FROM CMS.dbo.ItemEntity e WHERE e.Item = ? AND e.Entity = ? AND e.Context = 'ST'", static fn (array $p) =>
            $p[1] === 4 && isset($settings[$p[0]]) ? [['ItemEntity' => $settings[$p[0]][0], 'MinimumStock' => $settings[$p[0]][1]]] : [])
        ->when('FROM CMS.dbo.Entity e LEFT JOIN CMS.dbo.Entities n ON n.Entity = e.Entity WHERE e.EntityCode = ?', static fn (array $p) =>
            array_values(array_map(static fn (array $s): array => ['Entity' => $s[0], 'EntityCode' => $s[1], 'EntityName' => $s[2], 'Context' => $s[3], 'Inactive' => $s[4]],
                array_filter($suppliers, static fn (array $s): bool => strcasecmp($s[1], (string) $p[0]) === 0))));
};
$itRows = [
    [100774, 'PGR1000', 'PIGMENT RED 57:1', 'SUNDRY', 6, null, 100484, 'lb'],
    [122865, 'VAR200', 'GLOSS VARNISH', 'SUNDRY', 2, null, null, 'lb'],
    [123104, 'PGY2300', 'PIGMENT YELLOW 128', 'SUNDRY', 1, null, null, 'lb'],
    [123124, 'OR1457UV-50', 'ORANGE (5 LB)', 'PP', 3, null, null, 'lb'],
    [113832, 'SUP-RED', 'SUPPLIER RED', 'NAME', 2, 100774, null, 'lb'],
    [101338, '50', '50', 'PROTOTYPE', 1, null, null, 'ea'],
];
$itSettings  = [100774 => [102696, '1500'], 123124 => [102700, null]];
$itSuppliers = [
    [100484, 'ACME', 'Acme Pigments', 'SP', 0],
    [100485, 'OLDCO', 'Old Company', 'SP', 1],
    [100486, 'CUST1', 'A Customer', 'BT,ST', 0],
];
$itNow = new DateTimeImmutable('2026-11-09T11:00:05');

t_section('Item registry');
$itAll = \PII\Cms\Write\OperationRegistry::permissionsFor('items');
t_same('Write to CMS: set the minimum stock and the preferred supplier of items', $itAll['write.item.settings'] ?? null, 'one permission for the two settings');
t_same('Write to CMS: create raw material and packaging items', $itAll['write.item.create'] ?? null, 'creating has its own permission');

t_section('Item flow: minimum stock');
$fake = new FakeTransport();
$wr   = $itWriter($itCms($itRows, $itSettings, $itSuppliers), $fake);
$in   = ['item' => 'pgr1000', 'minimum' => '2,000'];
$p    = $wr->preview('item.min_stock', $in, $itNow);
t_check(str_contains(json_encode($p->plan->summary), '2000 lb (was 1500)'), 'the review shows the new and the old minimum');
t_same([], $p->plan->warnings, 'no warning for an ordinary minimum');
t_check($wr->execute('item.min_stock', $in, $itNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($itNorm(cms_fixture(IR::ITEM_SAVE, 865429)), $itNorm($fake->sent[0] ?? ''), 'an item with stock settings: the row is updated, as in log 865429');
$in = ['item' => 'VAR200', 'minimum' => '4800'];
$p  = $wr->preview('item.min_stock', $in, $itNow);
t_check(str_contains(json_encode($p->plan->summary), '(none before)'), 'the review says there was no minimum');
t_check($wr->execute('item.min_stock', $in, $itNow, $p->hash)->ok(), 'committed');
t_xml_same($itNorm(cms_fixture(IR::ITEM_SAVE, 858032)), $itNorm($fake->sent[1] ?? ''), 'an item without stock settings: the row is added, as in log 858032');
$p = $wr->preview('item.min_stock', ['item' => 'SUP-RED', 'minimum' => '99'], $itNow);
t_same('100774', cms_fixture_parse($p->rendered->stages[0]['xml'])['steps'][0]['keys']['Item'], 'an alias resolves to its item');
t_same(1, count($p->plan->warnings), 'a minimum of 99 warns that the item will show as discontinued');
$p = $wr->preview('item.min_stock', ['item' => 'OR1457UV-50', 'minimum' => '12.5'], $itNow);
$st = cms_fixture_parse($p->rendered->stages[0]['xml'])['steps'][1];
t_same(['Update', '102700', '12.5'], [$st['operation'], $st['keys']['ItemEntity'], $st['data']['MinimumStock']], 'a pack size with an empty settings row is updated');
foreach ([
    'no item'      => [['item' => ''], 'Choose the item'],
    'unknown item' => [['item' => 'NOPE'], 'was not found'],
    'not stocked'  => [['item' => '50'], 'not a stock item'],
    'no minimum'   => [['minimum' => ' '], 'Enter the minimum'],
    'negative'     => [['minimum' => '-5'], 'must be a number'],
    'not a number' => [['minimum' => 'lots'], 'must be a number'],
    'unchanged'    => [['minimum' => '1500.0'], 'Nothing was changed'],
] as $label => [$over, $needle]) {
    $itRefused(static fn () => $wr->preview('item.min_stock', $over + ['item' => 'PGR1000', 'minimum' => '10'], $itNow), $needle, $label);
}
t_check(!$itWriter($itCms($itRows, $itSettings, $itSuppliers), $fake, ['write.item.create'])->allowed('item.min_stock'), 'needs the settings permission');

t_section('Item flow: preferred supplier');
$fake = new FakeTransport();
$wr   = $itWriter($itCms($itRows, $itSettings, $itSuppliers), $fake);
$in   = ['item' => 'PGY2300', 'supplier' => 'acme'];
$p    = $wr->preview('item.supplier', $in, $itNow);
t_check(str_contains(json_encode($p->plan->summary), 'ACME Acme Pigments (none before)'), 'the review names the supplier');
t_check($wr->execute('item.supplier', $in, $itNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($itNorm(cms_fixture(IR::ITEM_SAVE, 864642)), $itNorm($fake->sent[0] ?? ''), 'the request matches log 864642');
foreach ([
    'no supplier'       => [['supplier' => ''], 'Choose the supplier'],
    'unknown supplier'  => [['supplier' => 'NOPE'], 'was not found'],
    'a customer'        => [['supplier' => 'CUST1'], 'was not found'],
    'inactive supplier' => [['supplier' => 'OLDCO'], 'inactive'],
    'a pack size'       => [['item' => 'OR1457UV-50'], 'has no supplier'],
    'unchanged'         => [['item' => 'PGR1000'], 'Nothing was changed'],
] as $label => [$over, $needle]) {
    $itRefused(static fn () => $wr->preview('item.supplier', $over + $in, $itNow), $needle, $label);
}

t_section('Item flow: create');
$fake = new FakeTransport();
$wr   = $itWriter($itCms($itRows, $itSettings, $itSuppliers), $fake);
$in   = ['code' => ' pgy2301 ', 'description' => ' Pigment  Yellow 129 ', 'kind' => 'raw'];
$p    = $wr->preview('item.create', $in, $itNow);
t_check($wr->execute('item.create', $in, $itNow, $p->hash)->ok(), 'committed on the fake transport');
$got  = cms_fixture_parse($fake->sent[0] ?? '');
$want = cms_fixture_parse(cms_fixture(IR::ITEM_SAVE, 864641));
t_same(['PGY2301', 'Pigment Yellow 129', '1'], [$got['steps'][0]['data']['ItemCode'], $got['steps'][0]['data']['Description'], $got['steps'][0]['keys']['Version']],
    'the code is upper-cased, the description tidied, the insert carries Version 1');
$same = static function (array $steps): array {
    unset($steps[0]['data']['ItemCode'], $steps[0]['data']['Description'], $steps[0]['keys']['Version']);
    return $steps;
};
t_same($same($want['steps']), $same($got['steps']), 'everything else is as in log 864641');
$p = $wr->preview('item.create', ['code' => '5GP', 'description' => '5 GALLON PAIL', 'kind' => 'packaging'], $itNow);
$st = cms_fixture_parse($p->rendered->stages[0]['xml'])['steps'];
t_same(['ea', 'Packaging', 'false', 'Packaging Material'], [$st[0]['data']['Unit'], $st[0]['data']['GLGroup'], $st[0]['data']['ShipInContainer'], $st[2]['data']['Group']],
    'a packaging item: each, Packaging, not shipped in its container');
t_same(2, count($p->plan->warnings), 'the review says what is still to be set and that the stock is tracked');
t_check(str_contains($p->plan->warnings[1] ?? '', 'stock tracked') && str_contains($p->plan->warnings[1] ?? '', 'in CMS'),
    'a packaging item: the review says an untracked one is created in CMS');
t_same(1, count($wr->preview('item.create', ['code' => 'RM1', 'description' => 'RAW ONE', 'kind' => 'raw'], $itNow)->plan->warnings),
    'a raw material: only the note on what is still to be set');
$fake  = new FakeTransport();
$wr    = $itWriter($itCms($itRows, $itSettings, $itSuppliers), $fake);
$in    = ['code' => '5lb pail', 'description' => '5 POUND BLACK PLASTIC PAIL WITH HANDLE', 'kind' => 'packaging'];
$itDay = new DateTimeImmutable('2023-05-10T12:56:24');
$p     = $wr->preview('item.create', $in, $itDay);
t_check($wr->execute('item.create', $in, $itDay, $p->hash)->ok(), 'a packaging item: committed on the fake transport');
// The client sent Version 8 on this insert; Insights sends 1 and the new row starts at 1 either way.
t_xml_same($itNorm(str_replace('Version="8"', 'Version="1"', cms_fixture(IR::ITEM_SAVE, 501743))), $itNorm($fake->sent[0] ?? ''),
    'a packaging item: the request is log 501743 but for the Version on the insert');
foreach ([
    'no kind'        => [['kind' => ''], 'what kind'],
    'unknown kind'   => [['kind' => 'finished'], 'what kind'],
    'no code'        => [['code' => ''], 'Enter a code'],
    'code in use'    => [['code' => 'pgr1000'], 'already used by an item'],
    'alias in use'   => [['code' => 'SUP-RED'], 'already used by another alias'],
    'no description' => [['description' => ' '], 'Enter a description'],
] as $label => [$over, $needle]) {
    $itRefused(static fn () => $wr->preview('item.create', $over + $in, $itNow), $needle, $label);
}
t_check(!$itWriter($itCms($itRows, $itSettings, $itSuppliers), $fake, ['write.item.settings'])->allowed('item.create'), 'needs the create permission');

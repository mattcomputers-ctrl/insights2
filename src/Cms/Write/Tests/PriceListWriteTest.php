<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\FakeCmsReader;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Ops\MasterData\PriceListRequests as PL;
use PII\Cms\Write\Ops\MasterData\PriceListState;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

t_section('Price list golden: PriceVersionUpdateDataSet.SaveChanges');
$plShapes = [];
foreach (cms_fixture_ids(PL::SAVE) as $log) {
    $xml = cms_fixture(PL::SAVE, $log);
    $f   = cms_fixture_parse($xml);
    $s   = $f['steps'];
    $ops = implode(' ', array_map(static fn (array $x): string => $x['operation'] . ' ' . $x['object'], $s));
    if ($ops === 'UpdateVersion PriceVersion Update PriceDetail' && array_diff(array_keys($s[1]['data']), PL::EDITABLE) === []) {
        $plShapes['line edit'] = true;
        t_xml_same($xml, PL::lineUpdate($s[0]['keys']['PriceVersion'], $s[0]['keys']['Version'], $s[1]['keys']['PriceDetail'],
            $s[1]['keys']['Version'], $s[1]['data'])->toXml($f['env']), "log {$log} (change a line)");
    } elseif ($ops === 'UpdateVersion PriceVersion Insert PriceDetail' && !isset($s[1]['data']['MinOrder2'])) {
        $plShapes['line add'] = true;
        $d = $s[1]['data'];
        t_xml_same($xml, PL::lineInsert($s[0]['keys']['PriceVersion'], $s[0]['keys']['Version'], [
            'item' => $d['Item'], 'description' => $d['Description'], 'unit' => $d['EntityUnit'], 'minOrder' => $d['MinOrder1'],
            'price' => $d['Price1'], 'currency' => $d['Currency'], 'pkgType' => $d['PkgType'] ?? null,
            'perPack' => $d['EntityQuantity'] ?? null, 'supplierCode' => $d['EntityItemCode'] ?? '',
        ])->toXml($f['env']), "log {$log} (add a line)");
    } else {
        // New lists and new versions of a list, deleted lines and comments stay in the client.
        $plShapes['not built'] = true;
        t_check(str_contains($ops, 'Insert PriceVersion') || str_contains($ops, 'Delete PriceDetail') || str_contains($ops, 'Update PriceVersion'),
            "log {$log}: a shape that is left to the client ({$ops})");
    }
}
ksort($plShapes);
t_same(['line add', 'line edit', 'not built'], array_keys($plShapes), 'fixtures cover what is built');
$plLine = ['item' => 5, 'description' => 'D', 'unit' => 'lb', 'minOrder' => '1', 'price' => '2.50', 'currency' => 'USD'];
t_same(['PriceVersion', 'Item', 'Description', 'EntityUnit', 'PriceByPackage', 'MinOrder1', 'Price1', 'Currency', 'InvItem'],
    array_keys(PL::lineInsert(9, 1, $plLine)->steps()[1]->first('data')), 'an item sold loose leaves out the package, its quantity and a blank code');
t_same(['Price1', 'EntityItemCode'], array_keys(PL::lineUpdate(9, 1, 3, 1, ['EntityItemCode' => 'X', 'Price1' => '1'])->steps()[1]->first('data')),
    'price before code when both change');
t_throws(\InvalidArgumentException::class, static fn () => PL::lineUpdate(9, 1, 3, 1, []));
t_throws(\InvalidArgumentException::class, static fn () => PL::lineUpdate(9, 1, 3, 1, ['Description' => 'X']));
t_throws(\InvalidArgumentException::class, static fn () => PL::lineInsert(9, 1, ['pkgType' => 7] + $plLine));
t_throws(\InvalidArgumentException::class, static fn () => PL::lineInsert(9, 1, ['perPack' => '44'] + $plLine));
t_throws(\InvalidArgumentException::class, static fn () => PL::lineInsert(9, 1, ['price' => ''] + $plLine));
t_same('11.46', PriceListState::money('11.4600'), 'money drops spare zeros');
t_same('9.00', PriceListState::money('9.0000'), 'money keeps two decimals');
t_same('6.2856', PriceListState::money('6.2856'), 'money keeps four decimals when they are used');

/* ── Flows ── */

$plPerms  = ['write.price.edit'];
$plWriter = static fn (FakeCmsReader $cms, $transport, ?array $perms = null): \PII\Cms\Write\CmsWriter => new \PII\Cms\Write\CmsWriter(
    $cms, $transport, static fn (string $p) => in_array($p, $perms ?? $plPerms, true), 'pic\\user1', 'WS1', true, '7.15.0.680',
    static function (array $row): void {});
$plNorm = static fn (string $xml): string => preg_replace(['/Application="[^"]+"/', '/ TransDate="[^"]+"/'], ['Application="A"', ' TransDate="T"'], $xml);
$plRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle),
        "refused: {$label}" . ($e !== null ? ' [' . $e->getMessage() . ']' : ' (not refused)'));
};
/**
 * A fake CMS.
 * Suppliers: [Entity, EntityCode, EntityName, Context, Inactive, Currency].
 * Lists:     [PriceVersion, Entity, Version, EffectiveDate].
 * Lines:     [PriceDetail, PriceVersion, Version, InvItem, ItemCode, Description, EntityItemCode, PkgType, PkgCode,
 *             EntityQuantity, Price1, MinOrder2].
 * Items:     [Item, ItemCode, Description, Context, Unit].
 */
$plCms = static function (array $suppliers, array $lists, array $lines, array $items): FakeCmsReader {
    $item = static function (int $col, mixed $v) use ($items): array {
        foreach ($items as $i) {
            if (strcasecmp((string) $i[$col], (string) $v) === 0) {
                return [['Item' => $i[0], 'ItemCode' => $i[1], 'Description' => $i[2], 'Context' => $i[3], 'Version' => 1,
                    'ReplacedBy' => null, 'Unit' => $i[4], 'Supplier' => null]];
            }
        }
        return [];
    };
    $pick = static function (array $p, bool $later) use ($lists): array {
        $found = array_values(array_filter($lists, static fn (array $l): bool => $l[1] === (int) $p[0]
            && ($later ? $l[3] . ' 00:00:00' > $p[1] : $l[3] . ' 00:00:00' <= $p[1])));
        usort($found, static fn (array $a, array $b): int => $later ? strcmp($a[3], $b[3]) : strcmp($b[3], $a[3]));
        return $found === [] ? [] : [['PriceVersion' => $found[0][0], 'Version' => $found[0][2], 'EffectiveDate' => $found[0][3]]];
    };
    return (new FakeCmsReader())
        ->when('WHERE e.EntityCode = ?', static fn (array $p) => array_values(array_map(
            static fn (array $s): array => ['Entity' => $s[0], 'EntityCode' => $s[1], 'EntityName' => $s[2], 'Context' => $s[3], 'Inactive' => $s[4], 'Currency' => $s[5]],
            array_filter($suppliers, static fn (array $s): bool => strcasecmp($s[1], (string) $p[0]) === 0))))
        ->when('FROM CMS.dbo.Item i WHERE i.ItemCode = ?', static fn (array $p) => $item(1, $p[0]))
        ->when('FROM CMS.dbo.Item i WHERE i.Item = ?', static fn (array $p) => $item(0, $p[0]))
        ->when('/* current price list */', static fn (array $p) => $pick($p, false))
        ->when('/* later price list */', static fn (array $p) => $pick($p, true))
        ->when('/* price list lines */', static fn (array $p) => array_values(array_map(
            static fn (array $l): array => ['PriceDetail' => $l[0], 'Version' => $l[2], 'InvItem' => $l[3], 'ItemCode' => $l[4], 'Description' => $l[5],
                'EntityItemCode' => $l[6], 'PkgType' => $l[7], 'PkgCode' => $l[8], 'EntityQuantity' => $l[9], 'EntityUnit' => 'lb',
                'PriceByPackage' => 0, 'Currency' => 'USD', 'MinOrder1' => '1.0000000000000000e+000', 'Price1' => $l[10],
                'MinOrder2' => $l[11], 'Price2' => $l[11] === null ? null : '1.0000'],
            array_filter($lines, static fn (array $l): bool => $l[1] === (int) $p[0]))))
        ->when('/* package types */', [
            ['Item' => 101084, 'ItemCode' => 'DRUM', 'Description' => 'Drum'],
            ['Item' => 101186, 'ItemCode' => 'BOX', 'Description' => 'Box'],
        ]);
};
$plSuppliers = [
    [100484, 'ACME', 'Acme Pigments', 'SP', 0, 'USD'],
    [100485, 'NOLIST', 'No List Co', 'SP', 0, null],
    [100486, 'MAPLE', 'Maple Resins', 'SP', 0, 'CAD'],
    [100487, 'GONE', 'Gone Co', 'SP', 1, 'USD'],
    [100893, 'COMPANY2', 'Company 2', 'BT,ST', 0, 'USD'],
];
$plLists = [
    [700, 100484, 9, '2025-01-01'],
    [733, 100484, 4, '2026-06-01'],
    [750, 100486, 1, '2026-01-01'],
];
$plLines = [
    [16793, 733, 1, 113100, 'PIG-BLUE', 'PIGMENT BLUE 15', 'OLD-CODE', 101186, 'BOX', '4.4000000000000000e+001', '6.2800', null],
    [16794, 733, 3, 113101, 'PIG-RED', 'PIGMENT RED 22', '', null, null, null, '9.0000', null],
    [16795, 733, 2, 113102, 'PIG-TIER', 'PIGMENT VIOLET', 'V-1', 101084, 'DRUM', '4.4000000000000000e+002', '5.0000', '1.0000000000000000e+002'],
    [16001, 700, 1, 113100, 'PIG-BLUE', 'PIGMENT BLUE 15', 'OLD-CODE', 101186, 'BOX', '4.4000000000000000e+001', '5.0000', null],
];
$plItems = [
    [113182, 'PIG-ORANGE', 'PIGMENT ORANGE 16', 'PP', 'lb'],
    [113100, 'PIG-BLUE', 'PIGMENT BLUE 15', 'PP', 'lb'],
    [113101, 'PIG-RED', 'PIGMENT RED 22', 'PP', 'lb'],
    [200001, 'RECIPE-1', 'A recipe', 'BA', 'lb'],
    [200002, 'NOUNIT', 'No unit', 'PP', ''],
];
$plNow = new DateTimeImmutable('2026-11-09T11:00:05');

t_section('Price list registry');
$plAll = \PII\Cms\Write\OperationRegistry::permissionsFor('purchase_orders');
t_same('Write to CMS: change prices and add items on supplier price lists', $plAll['write.price.edit'] ?? null, 'one permission for price lists');

t_section('Price list flow: change a line');
$fake = new FakeTransport();
$wr   = $plWriter($plCms($plSuppliers, [[733, 100484, 5, '2026-06-01']], $plLines, $plItems), $fake);
$plEdit = ['supplier' => 'acme', 'line' => '16793', 'price' => '6.28', 'supplier_code' => ' 5400A '];
$p = $wr->preview('price.line_edit', $plEdit, $plNow);
t_check(str_contains(json_encode($p->plan->summary), '5400A (was OLD-CODE)'), 'the review shows the old and the new code');
t_check(!str_contains(json_encode($p->plan->summary), '"Price"'), 'an unchanged price is not in the review');
t_check($wr->execute('price.line_edit', $plEdit, $plNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($plNorm(cms_fixture(PL::SAVE, 865647)), $plNorm($fake->sent[0] ?? ''), 'the request is the one in log 865647');

$wr = $plWriter($plCms($plSuppliers, $plLists, $plLines, $plItems), new FakeTransport());
$p  = $wr->preview('price.line_edit', ['price' => '$6.50'] + $plEdit, $plNow);
t_check(str_contains($p->rendered->stages[0]['xml'], '<keys PriceVersion="733" Version="4"/>'), 'the list goes with its own version');
t_check(str_contains($p->rendered->stages[0]['xml'], '<keys PriceDetail="16793" Version="1"/><data Price1="6.50" EntityItemCode="5400A"/>'), 'price and code change together');
t_check(str_contains(json_encode($p->plan->summary), '6.50 USD per lb (was 6.28)'), 'the review shows the old and the new price');
$p = $wr->preview('price.line_edit', ['supplier' => 'ACME', 'line' => '16794', 'price' => '9.25', 'supplier_code' => ''], $plNow);
t_check(str_contains($p->rendered->stages[0]['xml'], '<keys PriceDetail="16794" Version="3"/><data Price1="9.25"/>'), 'only the price is sent when the code is the same');
t_same(1, count($p->plan->warnings), 'no warning about a later list when there is none');
foreach ([
    'no supplier'        => [['supplier' => ''], 'Choose the supplier'],
    'unknown supplier'   => [['supplier' => 'NOPE'], 'was not found'],
    'a customer'         => [['supplier' => 'COMPANY2'], 'was not found'],
    'inactive supplier'  => [['supplier' => 'GONE'], 'inactive'],
    'no price list'      => [['supplier' => 'NOLIST'], 'no price list in effect'],
    'no line'            => [['line' => ''], 'not on the price list'],
    'line of old list'   => [['line' => '16001'], 'not on the price list'],
    'no price'           => [['price' => ''], 'Enter the price'],
    'zero price'         => [['price' => '0'], 'above 0'],
    'five decimals'      => [['price' => '1.23456'], 'four decimals'],
    'text price'         => [['price' => 'abc'], 'above 0'],
    'code too long'      => [['supplier_code' => str_repeat('A', 21)], 'too long'],
    'code removed'       => [['supplier_code' => ''], 'cannot be removed'],
    'nothing changed'    => [['supplier_code' => 'OLD-CODE', 'price' => '6.2800'], 'Nothing was changed'],
    'price by quantity'  => [['line' => '16795', 'price' => '4.75', 'supplier_code' => 'V-1'], 'prices by quantity'],
] as $label => [$over, $needle]) {
    $plRefused(static fn () => $wr->preview('price.line_edit', $over + $plEdit, $plNow), $needle, $label);
}
$p = $wr->preview('price.line_edit', ['supplier' => 'ACME', 'line' => '16795', 'price' => '5', 'supplier_code' => 'V-2'], $plNow);
t_check(str_contains($p->rendered->stages[0]['xml'], '<data EntityItemCode="V-2"/>'), 'the code of a line with prices by quantity can change');
$wr = $plWriter($plCms($plSuppliers, array_merge($plLists, [[760, 100484, 1, '2027-01-01']]), $plLines, $plItems), new FakeTransport());
$p  = $wr->preview('price.line_edit', $plEdit, $plNow);
t_check(str_contains(implode(' ', $p->plan->warnings), 'dated 2027-01-01 takes over later'), 'a later list is pointed out');
t_check(str_contains($p->rendered->stages[0]['xml'], 'PriceVersion="733"'), 'the list in effect is the one changed');
$plRefused(static fn () => $plWriter($plCms($plSuppliers, $plLists, $plLines, $plItems), new FakeTransport(), [])->preview('price.line_edit', $plEdit, $plNow),
    '', 'without the permission');

t_section('Price list flow: add an item');
$fake = new FakeTransport();
$wr   = $plWriter($plCms($plSuppliers, $plLists, $plLines, $plItems), $fake);
$plAdd = ['supplier' => 'ACME', 'item' => 'pig-orange', 'supplier_code' => '1316YG', 'package' => '101186', 'per_pack' => '22',
    'price' => '11.46', 'min_order' => '', 'description' => ''];
$p = $wr->preview('price.line_add', $plAdd, $plNow);
t_check(str_contains(json_encode($p->plan->summary), 'BOX of 22 lb'), 'the review names the package');
t_check(str_contains(json_encode($p->plan->summary), '11.46 USD per lb'), 'the review shows the price');
t_check($wr->execute('price.line_add', $plAdd, $plNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($plNorm(cms_fixture(PL::SAVE, 864696)), $plNorm($fake->sent[0] ?? ''), 'the request is the one in log 864696');

$p = $wr->preview('price.line_add', ['package' => '', 'per_pack' => '', 'supplier_code' => '', 'min_order' => '500', 'description' => 'Orange  16 '] + $plAdd, $plNow);
t_check(str_contains($p->rendered->stages[0]['xml'], '<data PriceVersion="733" Item="113182" Description="Orange 16" EntityUnit="lb" PriceByPackage="false" MinOrder1="500" Price1="11.46" Currency="USD" InvItem="113182"/>'),
    'an item sold loose, with its own description and a minimum order');
$p = $wr->preview('price.line_add', ['item' => 'PIG-BLUE', 'per_pack' => '55'] + $plAdd, $plNow);
t_check(str_contains($p->rendered->stages[0]['xml'], 'EntityQuantity="55"'), 'the same item in another size of package can be added');
$p = $wr->preview('price.line_add', ['item' => 'PIG-RED'] + $plAdd, $plNow);
t_check(str_contains($p->rendered->stages[0]['xml'], 'Item="113101" PkgType="101186"'), 'an item sold loose can also be added in a package');
foreach ([
    'no supplier'       => [['supplier' => ''], 'Choose the supplier'],
    'no price list'     => [['supplier' => 'NOLIST'], 'no price list in effect'],
    'other currency'    => [['supplier' => 'MAPLE'], 'is paid in CAD'],
    'no item'           => [['item' => ''], 'Choose the item'],
    'unknown item'      => [['item' => 'NOPE'], 'was not found'],
    'not a stock item'  => [['item' => 'RECIPE-1'], 'not a stock item'],
    'item without unit' => [['item' => 'NOUNIT'], 'no stock unit'],
    'no price'          => [['price' => ''], 'Enter the price'],
    'bad price'         => [['price' => '-1'], 'above 0'],
    'bad minimum'       => [['min_order' => '0'], 'above 0'],
    'unknown package'   => [['package' => '999'], 'Choose the package'],
    'package, no size'  => [['per_pack' => ''], 'Enter how much lb is in one BOX'],
    'size, no package'  => [['package' => ''], 'Choose the package the quantity is for'],
    'bad size'          => [['per_pack' => 'x'], 'above 0'],
    'code too long'     => [['supplier_code' => str_repeat('A', 21)], 'too long'],
    'already listed'    => [['item' => 'PIG-BLUE', 'per_pack' => '44.0'], 'already on this price list, BOX of 44 lb'],
    'already loose'     => [['item' => 'PIG-RED', 'package' => '', 'per_pack' => ''], 'already on this price list, by the lb'],
] as $label => [$over, $needle]) {
    $plRefused(static fn () => $wr->preview('price.line_add', $over + $plAdd, $plNow), $needle, $label);
}

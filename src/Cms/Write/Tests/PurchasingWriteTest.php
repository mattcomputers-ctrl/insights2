<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\Ops\Purchasing\PurchasingRequests;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

/** Turn a logged OrdDetail insert + its OrdDetailPricing insert back into the template's typed line. */
$poLineOf = static function (array $detail, array $pricing): array {
    $d = $detail['data'];
    return [
        'item'         => (int) $d['Item'],
        'description'  => $d['Description'] ?? null,
        'qtyReqd'      => $d['QtyReqd'],
        'supQty'       => $d['SupQty'],
        'supUnit'      => $d['SupUnit'],
        'percentUnder' => $d['PercentUnder'],
        'percentOver'  => $d['PercentOver'],
        'datePromised' => $d['DatePromised'] ?? null,
        'manualPrice'  => $d['ManualPrice'] === 'true',
        'price'        => $d['Price'],
        'pricing'      => $pricing['data'],
    ];
};

/** Rebuild a logged purchase order save through the templates. */
$poRebuild = static function (array $f, string $action, string $log) use ($poLineOf): \PII\Cms\Write\Request {
    $steps = $f['steps'];
    $head  = $steps[0];
    $n     = count($steps);
    if ($head['operation'] === 'Insert') {
        $header = $head['data'];
        unset($header['Context']);
        $lines = [];
        $addr  = [];
        $memo  = null;
        for ($i = 1; $i < $n; $i++) {
            $s = $steps[$i];
            if ($s['object'] === 'OrdDetail') {
                $lines[] = $poLineOf($s, $steps[++$i]);
            } elseif ($s['object'] === 'AddressRef') {
                $addr[$s['keys']['Reference']] = $s['data'];
            } elseif ($s['object'] === 'MemoRef') {
                $memo = $s['data']['Data'];
            }
        }
        return PurchasingRequests::createOrder($header, $lines, $addr['SupplierAddress'], $addr['ShipToAddress'], $memo);
    }
    $changes = [];
    for ($i = 1; $i < $n; $i++) {
        $s  = $steps[$i];
        $op = $s['object'] . '.' . $s['operation'];
        switch ($op) {
            case 'OrdDetail.Update':
                $changes[] = ['op' => 'updateLine', 'ordDetail' => (int) $s['keys']['OrdDetail'], 'changes' => $s['data']];
                break;
            case 'OrdDetailPricing.Delete':
                $next = $steps[++$i];
                $changes[] = ['op' => 'deleteLine', 'pricing' => (int) $s['keys']['OrdDetailPricing'],
                              'ordDetail' => (int) $next['keys']['OrdDetail']];
                break;
            case 'OrdDetail.Insert':
                $changes[] = ['op' => 'addLine', 'line' => $poLineOf($s, $steps[++$i]), 'owner' => $s['data']['Owner']];
                break;
            case 'MemoRef.Insert':
            case 'MemoRef.Update':
                $changes[] = ['op' => $s['operation'] === 'Insert' ? 'addMemo' : 'updateMemo', 'text' => $s['data']['Data']];
                break;
            default:
                t_check(false, "log {$log}: fixture step {$op} has no template");
        }
    }
    return PurchasingRequests::editOrder((int) $head['keys']['Ordr'], (int) $head['keys']['Version'],
        $head['operation'] === 'Update' ? $head['data'] : [], $changes, $action);
};

foreach ([PurchasingRequests::SAVE => 13, PurchasingRequests::MARK_COMPLETE => 2] as $action => $atLeast) {
    t_section("Purchasing golden: {$action}");
    $count = 0;
    foreach (cms_fixture_ids($action) as $log) {
        $xml = cms_fixture($action, $log);
        $f   = cms_fixture_parse($xml);
        t_xml_same($xml, $poRebuild($f, $action, $log)->toXml($f['env']), "log {$log}");
        $count++;
    }
    t_check($count >= $atLeast, "at least {$atLeast} fixtures were exercised");
}

t_section('Purchasing golden: PurchaseReceiptDataSet.SaveChanges');
$skipped = [];
$count   = 0;
foreach (cms_fixture_ids(PurchasingRequests::RECEIPT_SAVE) as $log) {
    $xml = cms_fixture(PurchasingRequests::RECEIPT_SAVE, $log);
    $f   = cms_fixture_parse($xml);
    $s   = $f['steps'];
    if (($s[1]['object'] ?? '') !== 'CreateChangeSet') {
        $skipped[] = (string) $log;   // a correction to an existing receipt: not built, stays in the client
        continue;
    }
    $costed = $s[3]['object'] === 'CreateInventoryCost';
    $req = PurchasingRequests::receive((int) $s[0]['keys']['Ordr'], (int) $s[0]['keys']['Version'], $s[1]['data']['Owner'],
        $s[1]['data']['ChangeDate'], $s[1]['data']['Shipment'], (int) $s[2]['keys']['OrdDetail'], (int) $s[2]['data']['Item'],
        $s[2]['data']['PSQty'], $s[2]['data']['PSUnit'], $costed ? $s[3]['data']['UnitCostMK'] : null);
    t_xml_same($xml, $req->toXml($f['env']), "log {$log}");
    $count++;
}
t_check($count >= 3, 'new-receipt fixtures were exercised');
t_same(['441178', '801462', '851664'], $skipped, 'only the three receipt-correction fixtures are left to the client');

t_section('Purchasing golden: PurchaseReceiptDataSet.ReverseReceipt');
$count = 0;
foreach (cms_fixture_ids(PurchasingRequests::RECEIPT_REVERSE) as $log) {
    $xml  = cms_fixture(PurchasingRequests::RECEIPT_REVERSE, $log);
    $f    = cms_fixture_parse($xml);
    $s    = $f['steps'];
    $move = $s[3]['data'];
    $req  = PurchasingRequests::reverseReceipt((int) $s[0]['keys']['Ordr'], (int) $s[0]['keys']['Version'], $move['Owner'],
        (int) $s[1]['keys']['ChangeSet'], (int) $s[1]['keys']['Version'], (int) $move['TrgOrdDetail'], (int) $move['Item'],
        ltrim($move['Quantity'], '-'), isset($move['InventoryCost']) ? (int) $move['InventoryCost'] : null, $move['UnitCost'] ?? null);
    t_xml_same($xml, $req->toXml($f['env']), "log {$log}");
    $count++;
}
t_check($count >= 3, 'reversal fixtures were exercised');

t_section('Purchasing templates refuse malformed input');
foreach ([
    'unknown header column' => static fn () => PurchasingRequests::editOrder(1, 1, ['Bogus' => 'x'], []),
    'unknown change'        => static fn () => PurchasingRequests::editOrder(1, 1, [], [['op' => 'dropTable']]),
    'empty save'            => static fn () => PurchasingRequests::editOrder(1, 1, [], []),
    'unknown action'        => static fn () => PurchasingRequests::editOrder(1, 1, ['Terms' => 'x'], [], 'Other.Action'),
    'order with no lines'   => static fn () => PurchasingRequests::createOrder(
        ['Owner' => '4', 'Entity' => 1, 'Currency' => 'USD', 'PlacedBy' => 'u', 'DateOrdered' => '2026-01-01T00:00:00'], [], ['Address' => 1], ['Address' => 4]),
    'order with no supplier' => static fn () => PurchasingRequests::createOrder(['Owner' => '4'], [], [], []),
    'half-costed reversal'  => static fn () => PurchasingRequests::reverseReceipt(1, 1, '4', 2, 1, 3, 4, '5', 6, null),
    'negative reversal'     => static fn () => PurchasingRequests::reverseReceipt(1, 1, '4', 2, 1, 3, 4, '-5'),
] as $name => $fn) {
    t_check(t_throws(InvalidArgumentException::class, $fn) !== null, "refused: {$name}");
}

/* ── Flows: what a user's input becomes, against a fake CMS ── */

$poPerms  = array_keys(\PII\Cms\Write\OperationRegistry::permissionsFor('purchase_orders'));
$poWriter = static function (\PII\Cms\Write\FakeCmsReader $cms, $transport, ?array $perms = null) use ($poPerms): \PII\Cms\Write\CmsWriter {
    $perms ??= $poPerms;
    return new \PII\Cms\Write\CmsWriter($cms, $transport, static fn (string $p) => in_array($p, $perms, true), 'pic\\user1',
        'WS1', true, '7.15.0.680', static function (array $row): void {});
};
$poNorm = static fn (string $xml): string => preg_replace(
    ['/Application="[^"]+"/', '/ TransDate="[^"]+"/'], ['Application="Chemical Management System"', ' TransDate="T"'], $xml);
$poApp = static fn (string $xml): string => preg_replace('/Application="[^"]+"/', 'Application="Chemical Management System"', $xml);
$poRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle),
        "refused: {$label}" . ($e !== null ? ' [' . $e->getMessage() . ']' : ' (not refused)'));
};
$poFake = static fn (): \PII\Cms\Write\FakeTransport => new \PII\Cms\Write\FakeTransport();

t_section('Purchasing registry and number format');
t_same(5, count($poPerms), 'five purchasing write permissions');
foreach (['165' => 165.0, '44.09' => 44.09, '440.90000000000003' => 10 * 44.09, '3.8600000000000003' => 3.86 * 135 / 135,
          '1322.76' => 3 * 440.92, '22.795' => (float) '2.2795000000000002e+001'] as $text => $f) {
    t_same((string) $text, \PII\Cms\Write\Ops\Purchasing\PurchasingState::dbl($f), "floating-point {$text} prints as the client prints it");
}

/** CMS as it stood just before log 863603: supplier 100298, one packaged item on its price list. */
$f603    = cms_fixture_parse(cms_fixture(PurchasingRequests::SAVE, 863603));
$memo603 = '';
foreach ($f603['steps'] as $s) {
    if ($s['object'] === 'MemoRef') {
        $memo603 = $s['data']['Data'];
    }
}
$poSupplier = static fn (array $over = []): array => $over + [
    'Entity' => 100298, 'EntityCode' => 'S5', 'EntityName' => 'Company 5', 'Context' => 'SP,MF,', 'Inactive' => 0,
    'Currency' => 'USD', 'Terms' => 'Net 30', 'Incoterms' => 'Dest', 'ShipVia' => null,
];
$poAddr = static fn (array $p): array => (int) $p[0] === 4
    ? [['Address' => 4, 'Name' => 'Company 6', 'AddrLine1' => '2 Example St', 'AddrLine2' => null, 'AddrLine3' => null,
        'City' => 'Anytown', 'State' => 'IL', 'ZipCode' => '00000', 'Contact' => null, 'Email' => null, 'Fax' => '555-0103',
        'Phone' => '555-0102', 'CountryName' => 'USA']]
    : [['Address' => 101558, 'Name' => 'Company 5', 'AddrLine1' => '1 Example St', 'AddrLine2' => null, 'AddrLine3' => null,
        'City' => 'Anytown', 'State' => 'MI', 'ZipCode' => '00000', 'Contact' => null, 'Email' => 'a@example.com', 'Fax' => null,
        'Phone' => '555-0100', 'CountryName' => null]];
$poPriceRow = static fn (array $over = []): array => $over + [
    'PriceDetail' => 16699, 'PriceVersion' => 726, 'EntityItemCode' => 'PI-TPO', 'Description' => 'TPO PHOTOINITIATOR', 'Comment' => null,
    'Currency' => 'USD', 'PkgType' => 102227, 'EntityQuantity' => '4.409000000000000e+001', 'EntityUnit' => 'lb', 'PriceByPackage' => 0,
    'MinOrder1' => '1.000000000000000e+000', 'Price1' => '7.8800', 'MinOrder2' => null, 'Price2' => null, 'MinOrder3' => null,
    'Price3' => null, 'MinOrder4' => null, 'Price4' => null, 'MinOrder5' => null, 'Price5' => null,
    'Item' => 101125, 'ItemCode' => 'R101125', 'ItemDescription' => 'TPO', 'Unit' => 'lb', 'Treatment' => 'WHS',
    'UnderReceive' => '1.000000000000000e+000', 'OverReceive' => '1.000000000000000e+000',
];
$poCms = static function (array $supplierOver = [], ?array $priceRows = null) use ($poSupplier, $poAddr, $poPriceRow, $memo603): \PII\Cms\Write\FakeCmsReader {
    $rows = $priceRows ?? [$poPriceRow()];
    return (new \PII\Cms\Write\FakeCmsReader())
        ->when('FROM CMS.dbo.Entity e LEFT JOIN CMS.dbo.Entities n', [$poSupplier($supplierOver)])
        ->when("FROM CMS.dbo.AddressRef a WHERE a.TableName = 'Entity'", $poAddr)
        ->when("FROM CMS.dbo.MemoRef m WHERE m.TableName = 'Entity'", [['Data' => $memo603]])
        ->when('/* carrier */', [['Context' => 'SV,']])
        ->when('FROM CMS.dbo.PriceVersion pv', static fn (array $p) => array_values(array_filter($rows,
            static fn ($r) => array_intersect([$r['ItemCode'], $r['EntityItemCode']], array_map('strval', $p)) !== [])))
        ->when('FROM CMS.dbo.Item i WHERE i.ItemCode = ?', static fn (array $p) => in_array('LOOSE', $p, true) ? [[
            'Item' => 555, 'ItemCode' => 'LOOSE', 'ItemDescription' => 'UNLISTED ITEM', 'Unit' => 'gal', 'Treatment' => 'NONE',
            'UnderReceive' => '0.000000000000000e+000', 'OverReceive' => '5.000000000000000e+000']] : []);
};

t_section('Purchasing flow: create order');
$now603 = new DateTimeImmutable('2026-11-03T17:40:20');
$in603  = ['supplier' => 100298, 'lines' => [['code' => 'PI-TPO', 'qty' => '10', 'price' => '', 'promised' => ''],
                                              ['code' => '', 'qty' => '']]];
$fake = $poFake();
$w    = $poWriter($poCms(), $fake);
$p    = $w->preview('po.create_order', $in603, $now603);
t_same(0, count($fake->sent), 'preview sends nothing');
t_same([], $p->plan->warnings, 'a price-list line raises no warning');
$res  = $w->execute('po.create_order', $in603, $now603, $p->hash);
t_check($res->ok(), 'create committed on the fake transport');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::SAVE, 863603)), $poNorm($fake->sent[0] ?? ''), 'packaged line with supplier memo matches log 863603');
$res = $poWriter($poCms(), new \PII\Cms\Write\DryRunTransport())->execute('po.create_order', $in603, $now603);
t_check($res->dryRun(), 'create runs end to end in dry-run');

/** Log 864775: an unpackaged item, a promised date, no terms, no memo, a supplier abroad. */
$cms775 = $poCms(['Entity' => 101312, 'EntityName' => 'Company 7', 'Terms' => null, 'Incoterms' => null], [$poPriceRow([
        'PriceDetail' => 13720, 'PriceVersion' => 563, 'EntityItemCode' => 'JUP-2310', 'Description' => 'SECURITY TAGGANT UV FLUORESCENT SW LW RED',
        'PkgType' => null, 'EntityQuantity' => null, 'Price1' => '404.5450', 'Item' => 112527, 'ItemCode' => 'R112527'])])
    ->when("FROM CMS.dbo.MemoRef m WHERE m.TableName = 'Entity'", [])
    ->when("FROM CMS.dbo.AddressRef a WHERE a.TableName = 'Entity'", static fn (array $p) => (int) $p[0] === 4 ? $poAddr($p) : [[
        'Address' => 101344, 'Name' => 'Company 7', 'AddrLine1' => '1 Example St', 'AddrLine2' => '', 'AddrLine3' => null, 'City' => 'Anytown',
        'State' => null, 'ZipCode' => null, 'Contact' => null, 'Email' => 'a@example.com', 'Fax' => '555-0101', 'Phone' => '555-0100',
        'CountryName' => 'China']]);
$fake = $poFake();
$res  = $poWriter($cms775, $fake)->execute('po.create_order',
    ['supplier' => 101312, 'lines' => [['code' => 'R112527', 'qty' => '17.6', 'price' => '', 'promised' => '2026-11-12']]],
    new DateTimeImmutable('2026-11-05T14:07:21'));
t_check($res->ok(), 'second create committed');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::SAVE, 864775)), $poNorm($fake->sent[0] ?? ''), 'unpackaged line found by our own item code matches log 864775');

$tierRow = $poPriceRow(['PkgType' => null, 'EntityQuantity' => null, 'MinOrder1' => '1.0e+002', 'Price1' => '9.0000',
    'MinOrder2' => '5.0e+002', 'Price2' => '8.5000', 'MinOrder3' => '2.0e+003', 'Price3' => '8.0000', 'Comment' => 'Call first']);
$fake = $poFake();
$w    = $poWriter($poCms([], [$tierRow]), $fake);
$res  = $w->execute('po.create_order', ['supplier' => 100298, 'memo' => '', 'incoterms' => 'ship point', 'ship_via' => 100789,
    'lines' => [['code' => 'PI-TPO', 'qty' => '600'], ['code' => 'LOOSE', 'qty' => '2', 'price' => '14.25']]], $now603);
$x    = $fake->sent[0] ?? '';
t_check($res->ok(), 'tiered and unlisted lines committed');
t_check(str_contains($x, 'QtyReqd="600" SupQty="600" SupUnit="lb"') && str_contains($x, 'ManualPrice="false" Price="8.5"'), 'the quantity break that applies sets the price');
t_check(str_contains($x, 'Comment="Call first" Currency="USD" EntityUnit="lb" PriceByPackage="false" MinOrder1="100" Price1="9.0000" MinOrder2="500" Price2="8.5000" MinOrder3="2000" Price3="8.0000" QtyPerEntityQty="1" EntityUnit2="lb"'), 'every quantity break is copied to the line');
t_check(str_contains($x, 'Item="555" Description="UNLISTED ITEM" QtyReqd="2" SupQty="2" SupUnit="gal" IsOpen="true" PercentUnder="0" PercentOver="5" ManualPrice="true" Price="14.25"'), 'an item off the price list is ordered at the typed price');
t_check(str_contains($x, '<data Item="555" Currency="USD" EntityUnit="gal" PriceByPackage="false" QtyPerEntityQty="1" EntityUnit2="gal"/>'), 'its pricing row carries no price-list reference');
t_check(str_contains($x, 'Incoterms="Ship Point" ShipVia="100789"') && !str_contains($x, 'MemoRef'), 'typed delivery terms and carrier are used; a cleared memo is not sent');
$p = $w->preview('po.create_order', ['supplier' => 100298, 'lines' => [['code' => 'PI-TPO', 'qty' => '50'], ['code' => 'LOOSE', 'qty' => '2', 'price' => '1']]], $now603);
t_check(count($p->plan->warnings) === 2 && str_contains($p->plan->warnings[0], 'below the supplier') && str_contains($p->plan->warnings[1], 'not on this supplier'),
    'warns below the minimum order and for an item off the price list: ' . json_encode($p->plan->warnings));
$fake = $poFake();
$poWriter($poCms(), $fake)->execute('po.create_order', ['memo' => "Ship Friday\nDock 2"] + $in603, $now603);
t_check(str_contains($fake->sent[0] ?? '', 'Dock 2') && !str_contains($fake->sent[0] ?? '', 'Ringwood'), "a typed memo replaces the supplier's standing memo");

$twoRows = [$poPriceRow(), $poPriceRow(['PriceDetail' => 16700, 'EntityItemCode' => 'PI-TPO-D', 'ItemCode' => 'R101125'])];
$addrSql = "FROM CMS.dbo.AddressRef a WHERE a.TableName = 'Entity'";
foreach ([
    'no supplier'        => [$poCms(), ['supplier' => 0] + $in603, 'Choose a supplier'],
    'unknown supplier'   => [$poCms()->when('FROM CMS.dbo.Entity e LEFT JOIN CMS.dbo.Entities n', []), $in603, 'Choose a supplier'],
    'inactive'           => [$poCms(['Inactive' => 1]), $in603, 'inactive'],
    'no currency'        => [$poCms(['Currency' => null]), $in603, 'no currency'],
    'no address'         => [$poCms()->when($addrSql, static fn (array $p) => (int) $p[0] === 4 ? $poAddr($p) : []), $in603, 'has no address'],
    'no address of ours' => [$poCms()->when($addrSql, static fn (array $p) => (int) $p[0] === 4 ? [] : $poAddr($p)), $in603, 'receiving address'],
    'no lines'           => [$poCms(), ['lines' => []] + $in603, 'at least one line'],
    'unknown item'       => [$poCms(), ['lines' => [['code' => 'NOPE', 'qty' => '1', 'price' => '1']]] + $in603, 'not found'],
    'zero quantity'      => [$poCms(), ['lines' => [['code' => 'PI-TPO', 'qty' => '0']]] + $in603, 'more than zero'],
    'negative quantity'  => [$poCms(), ['lines' => [['code' => 'PI-TPO', 'qty' => '-2']]] + $in603, 'more than zero'],
    'text quantity'      => [$poCms(), ['lines' => [['code' => 'PI-TPO', 'qty' => 'ten']]] + $in603, 'needs a number'],
    'negative price'     => [$poCms(), ['lines' => [['code' => 'PI-TPO', 'qty' => '1', 'price' => '-1']]] + $in603, 'cannot be negative'],
    'bad promised date'  => [$poCms(), ['lines' => [['code' => 'PI-TPO', 'qty' => '1', 'promised' => 'soon']]] + $in603, 'not a valid date'],
    'unlisted, no price' => [$poCms(), ['lines' => [['code' => 'LOOSE', 'qty' => '1', 'price' => '']]] + $in603, 'enter a price'],
    'price per package'  => [$poCms([], [$poPriceRow(['PriceByPackage' => 1])]), $in603, 'per package'],
    'unit mismatch'      => [$poCms([], [$poPriceRow(['EntityUnit' => 'kg'])]), $in603, 'by the'],
    'other currency'     => [$poCms([], [$poPriceRow(['Currency' => 'EUR'])]), $in603, 'is in'],
    'breaks + packages'  => [$poCms([], [$poPriceRow(['MinOrder2' => '1.0e+001', 'Price2' => '7.0000'])]), $in603, 'quantity-break prices'],
    'listed twice'       => [$poCms([], $twoRows), ['lines' => [['code' => 'R101125', 'qty' => '1']]] + $in603, 'more than once'],
    'unknown terms'      => [$poCms(), ['incoterms' => 'FOB Mars'] + $in603, 'Delivery terms'],
    'unknown carrier'    => [$poCms()->when('/* carrier */', []), ['ship_via' => 5] + $in603, 'carrier was not found'],
    'memo too long'      => [$poCms(), ['memo' => str_repeat('x', 4001)] + $in603, 'Memo'],
] as $name => [$cms, $in, $needle]) {
    $poRefused(fn () => $poWriter($cms, $poFake())->preview('po.create_order', $in, $now603), $needle, $name);
}
$fake = $poFake();
$res  = $poWriter($poCms([], $twoRows), $fake)->execute('po.create_order', ['lines' => [['code' => 'PI-TPO-D', 'qty' => '1']]] + $in603, $now603);
t_check($res->ok() && str_contains($fake->sent[0] ?? '', 'PriceDetail="16700"'), "the supplier's own code picks between two price-list rows for one item");

/** An open purchase order as PurchasingState reads it (PO 194531 just before log 863605). */
$poHead = static fn (array $over = []): array => $over + [
    'Ordr' => 194531, 'Version' => 2, 'Status' => null, 'Owner' => '4', 'Entity' => 100298, 'Currency' => 'USD', 'Terms' => 'Net 30',
    'Incoterms' => null, 'ShipVia' => null, 'DateOrdered' => '2026-11-03T17:40:20', 'ShipViaName' => null, 'SupplierName' => 'Company 5',
];
$poLine = static fn (array $over = []): array => $over + [
    'OrdDetail' => 564550, 'Item' => 101125, 'IsOpen' => 1, 'ManualPrice' => 0, 'Price' => '7.8800', 'QtyReqd' => '8.818400000000000e+002',
    'QtyUsed' => '0.000000000000000e+000', 'SupQty' => '2.000000000000000e+000', 'SupUnit' => '', 'PercentOver' => '1.000000000000000e+000',
    'DatePromised' => null, 'ItemCode' => 'R101125', 'Unit' => 'lb', 'Treatment' => 'WHS', 'Description' => 'TPO PHOTOINITIATOR',
    'OrdDetailPricing' => 54100, 'EntityItemCode' => 'PI-TPO', 'EntityUnit' => 'lb', 'PriceByPackage' => 0,
    'QtyPerEntityQty' => '4.409200000000000e+002',
];
$poReceipt = static fn (array $over = []): array => $over + [
    'ChangeSet' => 133914, 'Version' => 1, 'ChangeDate' => '2026-08-18T09:00:00', 'Shipment' => 'PS-1', 'OrdDetail' => 544479,
    'Item' => 121849, 'PSQty' => '3.960000000000000e+001', 'PSUnit' => 'lb', 'Billed' => 0, 'LineCount' => 1,
    'InventoryCost' => 78598, 'UnitCostMK' => '2.279500000000000e+001',
];
$poOrderCms = static function (array $head = [], ?array $lines = null, array $memo = [], array $receipts = []) use ($poCms, $poHead, $poLine): \PII\Cms\Write\FakeCmsReader {
    return $poCms()
        ->when('FROM CMS.dbo.Ordr o LEFT JOIN', [$poHead($head)])
        ->when('FROM CMS.dbo.OrdDetail od', $lines ?? [$poLine()])
        ->when("FROM CMS.dbo.MemoRef m WHERE m.TableName = 'Ordr'", $memo)
        ->when('FROM CMS.dbo.ChangeSet cs', $receipts);
};
$poRun = static function (\PII\Cms\Write\FakeCmsReader $cms, string $op, array $in, string $at) use ($poWriter, $poFake): array {
    $fake = $poFake();
    $res  = $poWriter($cms, $fake)->execute($op, $in, new DateTimeImmutable($at));
    return [$res, $fake->sent[0] ?? ''];
};

t_section('Purchasing flow: edit order');
$nowE = new DateTimeImmutable('2026-11-03T17:42:50');
$same = ['ordr' => 194531, 'lines' => [564550 => ['qty' => '2', 'promised' => '']], 'memo' => '', 'incoterms' => '', 'ship_via' => 0];
$poRefused(fn () => $poWriter($poOrderCms(), $poFake())->preview('po.edit_order', $same, $nowE), 'Nothing was changed', 'a save with no differences');

[$res, $x] = $poRun($poOrderCms(), 'po.edit_order', ['lines' => [564550 => ['qty' => '3', 'promised' => '']]] + $same, '2026-11-03T17:42:50');
t_check($res->ok(), 'quantity change committed');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::SAVE, 863605)), $poNorm($x), 'package quantity change matches log 863605');

[$res, $x] = $poRun($poOrderCms(['Ordr' => 193230, 'Version' => 1], [$poLine(['OrdDetail' => 555461])]), 'po.edit_order',
    ['ordr' => 193230, 'lines' => [555461 => ['promised' => '2026-10-06']]], '2026-10-01T14:10:36');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::SAVE, 852597)), $poNorm($x), 'promised date change matches log 852597');

$two = [$poLine(['OrdDetail' => 551752, 'OrdDetailPricing' => 53081]), $poLine(['OrdDetail' => 551753, 'OrdDetailPricing' => 53082, 'QtyUsed' => '4.4092e+002'])];
[$res, $x] = $poRun($poOrderCms(['Ordr' => 192671, 'Version' => 3], $two), 'po.edit_order', ['ordr' => 192671, 'remove' => ['551752']], '2026-09-18T11:57:22');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::SAVE, 848643)), $poNorm($x), 'line removal matches log 848643');

$green = $poPriceRow(['PriceDetail' => 16793, 'PriceVersion' => 733, 'EntityItemCode' => '5200D', 'Description' => 'GREEN 7 PIGMENT',
    'PkgType' => 101186, 'EntityQuantity' => '5.5e+001', 'Price1' => '5.6700', 'Item' => 112775, 'ItemCode' => 'R112775']);
$cms = $poOrderCms(['Ordr' => 194354, 'Version' => 2])->when('FROM CMS.dbo.PriceVersion pv', [$green]);
[$res, $x] = $poRun($cms, 'po.edit_order', ['ordr' => 194354, 'add' => [['code' => '5200D', 'qty' => '3', 'promised' => '2026-11-02'], ['code' => '']]], '2026-10-29T17:48:08');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::SAVE, 861711)), $poNorm($x), 'added line matches log 861711');

[$res, $x] = $poRun($poOrderCms(['Ordr' => 194352, 'Version' => 1]), 'po.edit_order',
    ['ordr' => 194352, 'memo' => 'Please advise if it is possible to ship at least one drum Friday 10/30.'], '2026-10-29T16:40:02');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::SAVE, 861699)), $poNorm($x), 'first memo matches log 861699');
[$res, $x] = $poRun($poOrderCms(['Ordr' => 189270, 'Version' => 2], null, [['Data' => 'Old note']]), 'po.edit_order', ['ordr' => 189270, 'memo' => ''], '2026-06-17T14:46:17');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::SAVE, 819079)), $poNorm($x), 'cleared memo matches log 819079');
[$res, $x] = $poRun($poOrderCms(['Ordr' => 194671, 'Version' => 1]), 'po.edit_order', ['ordr' => 194671, 'incoterms' => 'Dest', 'ship_via' => 100789], '2026-11-05T14:11:24');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::SAVE, 864786)), $poNorm($x), 'delivery terms and carrier change matches log 864786');

foreach ([
    'completed order'       => [$poOrderCms(['Status' => 'CMP']), $same, 'is completed'],
    'missing order'         => [$poOrderCms()->when('FROM CMS.dbo.Ordr o LEFT JOIN', []), $same, 'not found'],
    'vanished line'         => [$poOrderCms(), ['ordr' => 194531, 'lines' => [9 => ['qty' => '3']]], 'no longer on PO'],
    'received line qty'     => [$poOrderCms([], $two), ['ordr' => 194531, 'lines' => [551753 => ['qty' => '5']]], 'received in part'],
    'closed line qty'       => [$poOrderCms([], [$poLine(['IsOpen' => 0])]), ['ordr' => 194531, 'lines' => [564550 => ['qty' => '5']]], 'closed line'],
    'zero quantity'         => [$poOrderCms(), ['ordr' => 194531, 'lines' => [564550 => ['qty' => '0']]], 'more than zero'],
    'received line removal' => [$poOrderCms([], $two), ['ordr' => 194531, 'remove' => [551753]], 'has been received'],
    'last line removal'     => [$poOrderCms(), ['ordr' => 194531, 'remove' => [564550]], 'at least one line'],
    'bad promised date'     => [$poOrderCms(), ['ordr' => 194531, 'lines' => [564550 => ['promised' => 'soon']]], 'not a valid date'],
    'unknown carrier'       => [$poOrderCms()->when('/* carrier */', []), ['ordr' => 194531, 'ship_via' => 7], 'carrier was not found'],
] as $name => [$cms, $in, $needle]) {
    $poRefused(fn () => $poWriter($cms, $poFake())->preview('po.edit_order', $in, $nowE), $needle, $name);
}

$version = 2;
$moving  = $poOrderCms()->when('FROM CMS.dbo.Ordr o LEFT JOIN', function () use (&$version, $poHead) {
    return [$poHead(['Version' => $version])];
});
$fake = $poFake();
$w    = $poWriter($moving, $fake);
$inQ  = ['ordr' => 194531, 'lines' => [564550 => ['qty' => '3']]];
$seen = $w->preview('po.edit_order', $inQ, $nowE);
$version = 3;   // someone else saved the PO in between
t_check(t_throws(\PII\Cms\Write\StalePreview::class, fn () => $w->execute('po.edit_order', $inQ, $nowE, $seen->hash)) !== null
    && count($fake->sent) === 0, 'a PO saved by someone else in between forces a new preview');

t_section('Purchasing flow: complete order');
$lines = [$poLine(['OrdDetail' => 562272, 'QtyUsed' => '8.818400000000000e+002']), $poLine(['OrdDetail' => 562273, 'IsOpen' => 0])];
$cms   = $poOrderCms(['Ordr' => 194208, 'Version' => 2], $lines);
$fake  = $poFake();
$w     = $poWriter($cms, $fake);
$nowC  = new DateTimeImmutable('2026-11-04T16:30:28');
$p     = $w->preview('po.complete_order', ['ordr' => 194208], $nowC);
t_same([], $p->plan->warnings, 'no warning when the open lines are fully received');
$res   = $w->execute('po.complete_order', ['ordr' => 194208], $nowC, $p->hash);
t_xml_same($poApp(cms_fixture(PurchasingRequests::SAVE, 864238)), $poApp($fake->sent[0] ?? ''), 'complete matches log 864238, timestamps included');
$p = $poWriter($poOrderCms(), $poFake())->preview('po.complete_order', ['ordr' => 194531], $nowC);
t_check(count($p->plan->warnings) === 1 && str_contains($p->plan->warnings[0], 'Nothing has been received'), 'warns when nothing has been received');
$p = $poWriter($poOrderCms([], [$poLine(['QtyUsed' => '4.4092e+002'])]), $poFake())->preview('po.complete_order', ['ordr' => 194531], $nowC);
t_check(count($p->plan->warnings) === 1 && str_contains($p->plan->warnings[0], 'not fully received'), 'warns when a line is short');
$poRefused(fn () => $poWriter($poOrderCms(['Status' => 'CMP']), $poFake())->preview('po.complete_order', ['ordr' => 194531], $nowC), 'is completed', 'a completed PO cannot be completed again');

t_section('Purchasing flow: receive');
$line357 = $poLine(['OrdDetail' => 564545, 'Item' => 101724, 'Price' => '3.8600', 'QtyReqd' => '1.215e+003', 'SupQty' => '9.0e+000',
    'QtyPerEntityQty' => '1.35e+002', 'ItemCode' => 'R101724']);
$cms357  = $poOrderCms(['Ordr' => 194529, 'Version' => 2], [$line357]);
$in357   = ['ordr' => 194529, 'ord_detail' => 564545, 'qty' => '1215', 'packing_slip' => ' 123137 '];
$now357  = new DateTimeImmutable('2026-11-09T13:16:08');
$fake    = $poFake();
$w       = $poWriter($cms357, $fake);
$p       = $w->preview('po.receive', $in357, $now357);
t_same([], $p->plan->warnings, 'receiving the ordered quantity raises no warning');
$res     = $w->execute('po.receive', $in357, $now357, $p->hash);
t_check($res->ok(), 'receipt committed');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::RECEIPT_SAVE, 866357)), $poNorm($fake->sent[0] ?? ''), 'costed receipt matches log 866357, unit cost to the last digit');
t_check($poWriter($cms357, new \PII\Cms\Write\DryRunTransport())->execute('po.receive', $in357, $now357)->dryRun(), 'receive runs end to end in dry-run');

$line511 = $poLine(['OrdDetail' => 562088, 'Item' => 104925, 'Treatment' => 'NONE', 'QtyReqd' => '4.05e+002', 'SupQty' => '4.05e+002',
    'SupUnit' => 'lb', 'QtyPerEntityQty' => '1.0e+000']);
[$res, $x] = $poRun($poOrderCms(['Ordr' => 194176, 'Version' => 1], [$line511]), 'po.receive',
    ['ordr' => 194176, 'ord_detail' => 562088, 'qty' => '405', 'packing_slip' => 'SC0390153'], '2026-11-02T14:19:12');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::RECEIPT_SAVE, 862511)), $poNorm($x), 'receipt of an item that carries no cost matches log 862511');

$p = $poWriter($cms357, $poFake())->preview('po.receive', ['qty' => '1300'] + $in357, $now357);
t_check(count($p->plan->warnings) === 1 && str_contains($p->plan->warnings[0], 'over-receipt allowance'), 'warns when over the allowance');
$p = $poWriter($poOrderCms(['Ordr' => 194529], [$line357], [], [$poReceipt(['OrdDetail' => 564545, 'Shipment' => '123137'])]), $poFake())
    ->preview('po.receive', ['qty' => '10'] + $in357, $now357);
t_check(count($p->plan->warnings) === 1 && str_contains($p->plan->warnings[0], 'already been received'), 'warns when the packing slip was already received on the line');
foreach ([
    'completed PO'      => [$poOrderCms(['Status' => 'CMP'], [$line357]), $in357, 'is completed'],
    'vanished line'     => [$cms357, ['ord_detail' => 1] + $in357, 'no longer on PO'],
    'closed line'       => [$poOrderCms([], [$poLine(['OrdDetail' => 564545, 'IsOpen' => 0])]), $in357, 'closed line'],
    'foreign currency'  => [$poOrderCms(['Currency' => 'EUR'], [$line357]), $in357, 'exchange rate'],
    'price per package' => [$poOrderCms([], [$poLine(['OrdDetail' => 564545, 'PriceByPackage' => 1])]), $in357, 'Receive it in CMS'],
    'no pricing row'    => [$poOrderCms([], [$poLine(['OrdDetail' => 564545, 'OrdDetailPricing' => null, 'EntityUnit' => null])]), $in357, 'Receive it in CMS'],
    'zero quantity'     => [$cms357, ['qty' => '0'] + $in357, 'more than zero'],
    'negative quantity' => [$cms357, ['qty' => '-5'] + $in357, 'more than zero'],
    'no packing slip'   => [$cms357, ['packing_slip' => ''] + $in357, 'packing slip number'],
    'long packing slip' => [$cms357, ['packing_slip' => str_repeat('9', 21)] + $in357, 'Packing slip number'],
] as $name => [$cms, $in, $needle]) {
    $poRefused(fn () => $poWriter($cms, $poFake())->preview('po.receive', $in, $now357), $needle, $name);
}

t_section('Purchasing flow: reverse receipt');
$line836 = $poLine(['OrdDetail' => 544479, 'Item' => 121849, 'ItemCode' => 'R121849', 'QtyUsed' => '3.96e+001']);
$cms836  = $poOrderCms(['Ordr' => 191578, 'Version' => 2], [$line836], [], [$poReceipt()]);
$in836   = ['ordr' => 191578, 'change_set' => 133914];
[$res, $x] = $poRun($cms836, 'po.reverse_receipt', $in836, '2026-08-19T12:08:20');
t_check($res->ok(), 'reversal committed');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::RECEIPT_REVERSE, 838836)), $poNorm($x), 'costed reversal matches log 838836');
$r792 = $poReceipt(['ChangeSet' => 117452, 'OrdDetail' => 471885, 'Item' => 104770, 'PSQty' => '2.0e+001', 'InventoryCost' => null, 'UnitCostMK' => null]);
[$res, $x] = $poRun($poOrderCms(['Ordr' => 180866, 'Version' => 2, 'Status' => 'CMP'], [$line836], [], [$r792]), 'po.reverse_receipt',
    ['ordr' => 180866, 'change_set' => 117452], '2025-12-04T08:16:00');
t_xml_same($poNorm(cms_fixture(PurchasingRequests::RECEIPT_REVERSE, 752792)), $poNorm($x), 'reversal without cost on a completed PO matches log 752792');
$p = $poWriter($poOrderCms(['Status' => 'CMP'], [$line836], [], [$poReceipt()]), $poFake())->preview('po.reverse_receipt', ['ordr' => 194531, 'change_set' => 133914], $nowC);
t_same(2, count($p->plan->warnings), 'a reversal on a completed PO carries an extra warning');
foreach ([
    'receipt of another PO' => [$cms836, ['change_set' => 5] + $in836, 'was not found on PO'],
    'already reversed'      => [$poOrderCms([], [$line836], [], [$poReceipt(['PSQty' => '0.0e+000'])]), $in836, 'already been reversed'],
    'billed'                => [$poOrderCms([], [$line836], [], [$poReceipt(['Billed' => 1])]), $in836, 'supplier bill'],
    'several lines'         => [$poOrderCms([], [$line836], [], [$poReceipt(['LineCount' => 2])]), $in836, 'more than one line'],
    'closed PO'             => [$poOrderCms(['Status' => 'CLS'], [$line836], [], [$poReceipt()]), $in836, 'is closed'],
] as $name => [$cms, $in, $needle]) {
    $poRefused(fn () => $poWriter($cms, $poFake())->preview('po.reverse_receipt', $in, $nowC), $needle, $name);
}
$poRefused(fn () => $poWriter($cms836, $poFake(), ['write.po.receive'])->preview('po.reverse_receipt', $in836, $nowC), 'permission', 'each purchasing operation needs its own permission');

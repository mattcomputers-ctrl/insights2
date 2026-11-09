<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\Ops\Sales\SalesRequests;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

/** Turn a logged OrdDetail insert + its OrdDetailPricing insert back into the template's typed line. */
$lineOf = static function (array $detail, array $pricing): array {
    $d = $detail['data'];
    $p = $pricing['data'];
    return [
        'Owner'        => $d['Owner'],
        'isOpen'       => $d['IsOpen'] === 'true',
        'manualPrice'  => $d['ManualPrice'] === 'true',
        'price'        => $d['Price'],
        'qty'          => $d['QtyReqd'],
        'qtyCommitted' => $d['QtyCommitted'] ?? null,
        'item'         => (int) $d['Item'],
        'itemName'     => (int) $d['ItemName'],
        'description'  => $d['Description'] ?? null,
        'unit'         => $p['EntityUnit'],
        'priceList'    => isset($p['PriceDetail']) ? [
            'priceDetail' => (int) $p['PriceDetail'], 'priceVersion' => (int) $p['PriceVersion'],
            'currency' => $p['Currency'], 'minOrder1' => $p['MinOrder1'], 'price1' => $p['Price1'],
        ] : null,
    ];
};

t_section('Sales golden: ShippingOrderDataSet.SaveChanges');
$seen = ['create' => 0, 'edit' => 0];
foreach (cms_fixture_ids(SalesRequests::SAVE) as $log) {
    $xml   = cms_fixture(SalesRequests::SAVE, $log);
    $f     = cms_fixture_parse($xml);
    $steps = $f['steps'];
    $event = str_contains($xml, '<event ');
    $head  = $steps[0];
    $n     = count($steps);

    if ($head['operation'] === 'Insert') {
        $seen['create']++;
        $header = $head['data'];
        unset($header['Context']);
        $lines = [];
        $memos = ['InternalMemo' => null, 'ShippingMemo' => null];
        $address = [];
        $credit = false;
        for ($i = 1; $i < $n; $i++) {
            $s = $steps[$i];
            if ($s['object'] === 'OrdDetail') {
                $lines[] = $lineOf($s, $steps[++$i]);
            } elseif ($s['object'] === 'MemoRef') {
                $memos[$s['keys']['Reference']] = [
                    'memo' => isset($s['data']['Memo']) ? (int) $s['data']['Memo'] : null,
                    'text' => $s['data']['Data'],
                ];
            } elseif ($s['object'] === 'AddressRef') {
                $address = $s['data'];
            } elseif ($s['object'] === 'CheckOrderCredit') {
                $credit = true;
            }
        }
        $req = SalesRequests::createOrder($header, $lines, $address, $memos['InternalMemo'], $memos['ShippingMemo'],
            $credit, $event, $head['keys']['Version']);
    } else {
        $seen['edit']++;
        $changes = [];
        $credit  = ['mode' => 'none'];
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
                    $changes[] = ['op' => 'addLine', 'line' => $lineOf($s, $steps[++$i])];
                    break;
                case 'MemoRef.Insert':
                case 'MemoRef.Update':
                    $changes[] = ['op' => $s['operation'] === 'Insert' ? 'addMemo' : 'updateMemo',
                                  'reference' => $s['keys']['Reference'], 'text' => $s['data']['Data'],
                                  'memo' => isset($s['data']['Memo']) ? (int) $s['data']['Memo'] : null];
                    break;
                case 'AddressRef.Update':
                    $changes[] = ['op' => 'updateAddress', 'changes' => $s['data']];
                    break;
                case 'UpdateOrderTaxes.Execute':
                    break;
                case 'CheckOrderCredit.Execute':
                    $credit = isset($s['data']['Release'])
                        ? ['mode' => 'release']
                        : ['mode' => 'check', 'oldReserve' => $s['data']['OldReserveAmount']];
                    break;
                default:
                    t_check(false, "log {$log}: fixture step {$op} has no template");
            }
        }
        $req = SalesRequests::editOrder((int) $head['keys']['Ordr'], (int) $head['keys']['Version'],
            $head['operation'] === 'Update' ? $head['data'] : [], $changes, $credit, $event);
    }
    t_xml_same($xml, $req->toXml($f['env']), "log {$log}");
}
t_check($seen['create'] >= 7 && $seen['edit'] >= 15, 'both create and edit fixtures were exercised');

t_section('Sales golden: ShippingOrderDataSet.CompleteSale');
foreach (cms_fixture_ids('ShippingOrderDataSet.CompleteSale') as $log) {
    $xml = cms_fixture('ShippingOrderDataSet.CompleteSale', $log);
    $f   = cms_fixture_parse($xml);
    $req = SalesRequests::completeSale((int) $f['steps'][0]['keys']['Ordr'], (int) $f['steps'][0]['keys']['Version']);
    t_xml_same($xml, $req->toXml($f['env']), "log {$log}");
}

t_section('Sales templates refuse malformed input');
t_throws(InvalidArgumentException::class, static fn () => SalesRequests::editOrder(1, 1, ['Bogus' => 'x'], [], ['mode' => 'none']));
t_throws(InvalidArgumentException::class, static fn () => SalesRequests::editOrder(1, 1, [], [['op' => 'dropTable']], ['mode' => 'none']));
t_throws(InvalidArgumentException::class, static fn () => SalesRequests::editOrder(1, 1, [], [], ['mode' => 'maybe']));
t_throws(InvalidArgumentException::class, static fn () => SalesRequests::createOrder(['Owner' => '4'], [], []));

/* ── Flows through the one door (CmsWriter) against a fake CMS ── */

$salesPerms  = array_keys(\PII\Cms\Write\OperationRegistry::permissionsFor('sales_orders'));
$salesWriter = static function (\PII\Cms\Write\FakeCmsReader $cms, $transport, ?array $perms = null) use ($salesPerms): \PII\Cms\Write\CmsWriter {
    $perms ??= $salesPerms;
    return new \PII\Cms\Write\CmsWriter($cms, $transport, static fn (string $p) => in_array($p, $perms, true), 'pic\\user1',
        'WS1', true, '7.15.0.680', static function (array $row): void {});
};
$salesNorm = static fn (string $xml): string => preg_replace(
    ['/Application="[^"]+"/', '/ TransDate="[^"]+"/', '/ChangeNumber="[^"]+"/'],
    ['Application="Chemical Management System"', ' TransDate="T"', 'ChangeNumber="N"'], $xml);
$refused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle), "refused: {$label}");
};
$newFake = static fn (): \PII\Cms\Write\FakeTransport => new \PII\Cms\Write\FakeTransport();

t_section('Sales registry');
t_same(5, count(array_filter($salesPerms, static fn (string $p): bool => str_starts_with($p, 'write.sales.'))), 'five sales write permissions');

/** CMS as it stood just before log 866306: customer 101427 ordering 10 lb of an alias item at no charge. */
$f306    = cms_fixture_parse(cms_fixture('ShippingOrderDataSet.SaveChanges', 866306));
$memo306 = $f306['steps'][3]['data']['Data'];
$entity  = static fn (array $over = []): array => $over + [
    'Entity' => 101427, 'EntityCode' => 'C12', 'EntityName' => 'Company 12', 'Parent' => null, 'Context' => 'BTSH,',
    'Inactive' => 0, 'Currency' => 'USD', 'Terms' => '2% 30 Net 60', 'Salesman' => 101316, 'Tax1Group' => null,
    'Tax1Number' => '18635768', 'Tax2Number' => null, 'Incoterms' => null, 'ShipVia' => 100738,
    'FreightService' => null, 'FreightAccount' => null, 'FreightOption' => null,
];
$customerCms = static function (array $entityOver = []) use ($entity, $memo306): \PII\Cms\Write\FakeCmsReader {
    return (new \PII\Cms\Write\FakeCmsReader())
        ->when('FROM CMS.dbo.Entity e LEFT JOIN CMS.dbo.Entities n', [$entity($entityOver)])
        ->when("FROM CMS.dbo.AddressRef a WHERE a.TableName = 'Entity'", [[
            'Address' => 101536, 'Name' => 'Company 12', 'AddrLine1' => '1 Example St', 'AddrLine2' => null,
            'AddrLine3' => null, 'City' => 'Anytown', 'State' => 'IL', 'ZipCode' => '00000', 'Country' => null,
            'CountryName' => null, 'Contact' => null, 'Email' => 'a@example.com', 'Fax' => null, 'Phone' => '555-0100']])
        ->when("FROM CMS.dbo.MemoRef m WHERE m.TableName = 'Entity'", [['Memo' => 1556, 'Data' => $memo306]])
        ->when('/* carrier */', [['Context' => 'SV,']])
        ->when('WHERE i.ItemCode = ? AND i.Context IN', static fn (array $p) => $p[0] === 'OSPOW' ? [[
            'Item' => 121305, 'ItemCode' => 'OSPOW', 'Description' => 'O/S PRIMASET OPAQUE OFF WHITE', 'Unit' => null,
            'Context' => 'NAME', 'ReplacedBy' => 121304, 'RealUnit' => 'lb', 'RealCode' => 'E121304']] : [])
        ->when('FROM CMS.dbo.PriceVersion pv', [])
        ->when("WHERE o.Context = 'SH' AND o.BillTo = ?", []);
};
$dupPo = "WHERE o.Context = 'SH' AND o.BillTo = ?";

t_section('Sales flow: create order');
$now306 = new DateTimeImmutable('2026-11-09T12:14:11');
$in306  = ['ship_to' => 101427, 'po_number' => 'REPLACEMENT', 'date_required' => '2026-11-09', 'ship_via' => 0,
           'lines' => [['code' => 'OSPOW', 'qty' => '10', 'price' => '0'], ['code' => '', 'qty' => '', 'price' => '']]];
$fake = $newFake();
$w    = $salesWriter($customerCms(), $fake);
$p    = $w->preview('sales.create_order', $in306, $now306);
t_same(0, count($fake->sent), 'preview sends nothing');
t_same([], $fake->generated, 'preview takes no number from the CMS counter');
t_check(str_contains(json_encode($p->rendered), '{next ChangeNumber}'), 'preview shows the counter as a placeholder');
$res = $w->execute('sales.create_order', $in306, $now306, $p->hash);
t_check($res->ok(), 'create committed on the fake transport');
t_xml_same($salesNorm(cms_fixture('ShippingOrderDataSet.SaveChanges', 866306)), $salesNorm($fake->sent[0]), 'flow output matches log 866306');
t_same(1, count($fake->generated), 'one number taken from the CMS counter at send time');
t_check(!str_contains($fake->sent[0], '{next') && (bool) preg_match('/ChangeNumber="\d+"/', $fake->sent[0]), 'the generated ChangeNumber is sent');

$res = $salesWriter($customerCms(), new \PII\Cms\Write\DryRunTransport())->execute('sales.create_order', $in306, $now306);
t_check($res->dryRun(), 'create runs end to end in dry-run');

foreach ([
    'no customer'       => [$customerCms(), ['ship_to' => 0] + $in306, 'Choose a customer'],
    'not a ship-to'     => [$customerCms(['Context' => 'SP,']), $in306, 'Choose a customer'],
    'inactive'          => [$customerCms(['Inactive' => 1]), $in306, 'inactive'],
    'no currency'       => [$customerCms(['Currency' => null]), $in306, 'no currency'],
    'no address'        => [$customerCms()->when("FROM CMS.dbo.AddressRef a WHERE a.TableName = 'Entity'", []), $in306, 'no address'],
    'bad date'          => [$customerCms(), ['date_required' => '11/09/2026'] + $in306, 'not a valid date'],
    'past date'         => [$customerCms(), ['date_required' => '2026-11-01'] + $in306, 'in the past'],
    'no lines'          => [$customerCms(), ['lines' => []] + $in306, 'at least one line'],
    'unknown item'      => [$customerCms(), ['lines' => [['code' => 'NOPE', 'qty' => '1', 'price' => '1']]] + $in306, 'not found'],
    'zero quantity'     => [$customerCms(), ['lines' => [['code' => 'OSPOW', 'qty' => '0', 'price' => '1']]] + $in306, 'cannot be zero'],
    'text quantity'     => [$customerCms(), ['lines' => [['code' => 'OSPOW', 'qty' => 'ten', 'price' => '1']]] + $in306, 'needs a number'],
    'no price anywhere' => [$customerCms(), ['lines' => [['code' => 'OSPOW', 'qty' => '5', 'price' => '']]] + $in306, 'enter a price'],
    'negative price'    => [$customerCms(), ['lines' => [['code' => 'OSPOW', 'qty' => '5', 'price' => '-2']]] + $in306, 'cannot be negative'],
    'duplicate PO'      => [$customerCms()->when($dupPo, [['Ordr' => 190001]]), $in306, 'already on order 190001'],
    'unknown carrier'   => [$customerCms()->when('/* carrier */', []), ['ship_via' => 5] + $in306, 'carrier was not found'],
] as $name => [$cms, $in, $needle]) {
    $refused(fn () => $salesWriter($cms, $newFake())->preview('sales.create_order', $in, $now306), $needle, $name);
}

$fake = $newFake();
$res  = $salesWriter($customerCms()->when($dupPo, [['Ordr' => 190001]]), $fake)
    ->execute('sales.create_order', ['duplicate_po_ok' => '1'] + $in306, $now306);
t_check($res->ok() && str_contains($fake->sent[0], '<event '), 'an accepted duplicate PO is sent with the event the client sends');

$fake   = $newFake();
$priced = $customerCms()->when('FROM CMS.dbo.PriceVersion pv', [[
    'PriceDetail' => 9001, 'PriceVersion' => 77, 'Currency' => 'USD', 'MinOrder1' => '1.0000', 'Price1' => '12.5000', 'MinOrder2' => null]]);
$inQ = ['lines' => [['code' => 'OSPOW', 'qty' => '4', 'price' => '']], 'is_quote' => '1', 'internal_memo' => 'Call first',
        'shipping_memo' => "Dock 2\nRing bell"] + $in306;
$res = $salesWriter($priced, $fake)->execute('sales.create_order', $inQ, $now306);
$x   = $fake->sent[0] ?? '';
t_check($res->ok(), 'quote with a price-list price committed');
t_check(str_contains($x, 'Status="QUO"') && str_contains($x, 'IsQuote="true"'), 'quote header');
t_check(str_contains($x, 'ManualPrice="false" Price="12.5"') && str_contains($x, 'PriceDetail="9001"'), 'price-list price used and referenced');
t_check(!str_contains($x, 'QtyCommitted') && str_contains($x, 'IsOpen="false"'), 'quote lines are not open or committed');
t_check(!str_contains($x, 'CheckOrderCredit'), 'no credit check for a quote');
t_check(str_contains($x, 'Data="Call first"') && !str_contains($x, 'Memo="1556"'), 'a typed internal memo replaces the customer memo');
t_check(str_contains($x, 'Reference="ShippingMemo"') && str_contains($x, 'Dock 2&#x0D;&#x0A;Ring bell'), 'shipping memo sent with CRLF line ends');

$tiered = $customerCms()->when('FROM CMS.dbo.PriceVersion pv', [[
    'PriceDetail' => 9001, 'PriceVersion' => 77, 'Currency' => 'USD', 'MinOrder1' => '1.0000', 'Price1' => '12.5000', 'MinOrder2' => '100.0000']]);
$refused(fn () => $salesWriter($tiered, $newFake())->preview('sales.create_order', $inQ, $now306), 'enter a price', 'quantity-break price lists need a typed price');

/** An open order as SalesState reads it. */
$orderHead = static fn (array $over = []): array => $over + [
    'Ordr' => 194480, 'Version' => 5, 'Status' => 'RTS', 'Owner' => '4', 'ShipTo' => 101427, 'BillTo' => 101427,
    'IsQuote' => 0, 'CreditHold' => 0, 'ReserveAmount' => '3144.0000', 'PoNumber' => 'PO-1',
    'DateRequired' => '2026-11-10T09:30:00', 'DateCompleted' => null, 'ShipVia' => 100738, 'ShipViaName' => 'Our Truck',
    'ShipToName' => 'Company 12', 'BillToName' => 'Company 12',
];
$orderLine = static fn (array $over = []): array => $over + [
    'OrdDetail' => 564262, 'Item' => 121304, 'ItemName' => 121305, 'IsOpen' => 1, 'ManualPrice' => 0, 'Price' => '786.0000',
    'QtyReqd' => '5.0000', 'QtyUsed' => '0.0000', 'ItemCode' => 'E121304', 'AliasCode' => 'OSPOW',
    'Description' => 'O/S PRIMASET OPAQUE OFF WHITE', 'Unit' => 'lb', 'OrdDetailPricing' => 880001,
];
$orderCms = static function (array $head = [], ?array $lines = null, array $memos = []) use ($customerCms, $orderHead, $orderLine): \PII\Cms\Write\FakeCmsReader {
    return $customerCms()
        ->when('FROM CMS.dbo.Ordr o LEFT JOIN', [$orderHead($head)])
        ->when('FROM CMS.dbo.OrdDetail od', $lines ?? [$orderLine()])
        ->when("FROM CMS.dbo.MemoRef m WHERE m.TableName = 'Ordr'", $memos);
};

t_section('Sales flow: edit order');
$nowE = new DateTimeImmutable('2026-11-05T12:16:58');
$fake = $newFake();
$same = ['ordr' => 194480, 'po_number' => 'PO-1', 'date_required' => '2026-11-10', 'ship_via' => 100738,
         'lines' => [564262 => ['qty' => '5', 'price' => '786']]];
$w    = $salesWriter($orderCms(), $fake);
$refused(fn () => $w->preview('sales.edit_order', $same, $nowE), 'Nothing was changed', 'an unchanged form sends nothing');
$inE  = ['lines' => [564262 => ['qty' => '4', 'price' => '786.00']]] + $same;
$p    = $w->preview('sales.edit_order', $inE, $nowE);
$res  = $w->execute('sales.edit_order', $inE, $nowE, $p->hash);
t_check($res->ok(), 'quantity change committed');
t_xml_same($salesNorm(cms_fixture('ShippingOrderDataSet.SaveChanges', 864679)), $salesNorm($fake->sent[0]), 'quantity change matches log 864679');

$fake = $newFake();
$res  = $salesWriter($orderCms(['Ordr' => 194617, 'Version' => 2, 'ReserveAmount' => '703.2000']), $fake)
    ->execute('sales.edit_order', ['ordr' => 194617, 'ship_via' => 100771], $nowE);
t_xml_same($salesNorm(cms_fixture('ShippingOrderDataSet.SaveChanges', 864816)), $salesNorm($fake->sent[0]), 'carrier change matches log 864816');

$fake = $newFake();
$inM  = ['ordr' => 194480, 'po_number' => 'PO-2', 'date_required' => '2026-11-12',
         'lines' => [564262 => ['qty' => '5', 'price' => '800']], 'add' => [['code' => 'OSPOW', 'qty' => '2', 'price' => '3.5']],
         'internal_memo' => 'Hold for pickup', 'shipping_memo' => ''];
$res  = $salesWriter($orderCms([], null, [['Reference' => 'InternalMemo', 'Data' => 'Old note']]), $fake)->execute('sales.edit_order', $inM, $nowE);
$x    = $fake->sent[0] ?? '';
t_check($res->ok(), 'mixed edit committed');
t_check(str_contains($x, 'DateRequired="2026-11-12T09:30:00"') && str_contains($x, 'PoNumber="PO-2"') && !str_contains($x, 'ShipVia='), 'header carries only the changed columns and keeps the time of day');
t_check(str_contains($x, '<keys OrdDetail="564262"/><data ManualPrice="true" Price="800"/>'), 'a price change marks the line as manually priced');
t_check(str_contains($x, 'Operation="Insert"><keys OrdDetail="@@identity"/>') && str_contains($x, 'QtyReqd="2"'), 'added line inserted');
t_check(str_contains($x, 'Operation="Update"><keys TableID="194480" TableName="Ordr" Reference="InternalMemo"/><data Data="Hold for pickup"/>'), 'existing memo updated');
t_check(!str_contains($x, 'ShippingMemo'), 'an empty memo that never existed is not sent');
t_check(!str_contains($x, 'ChangeNumber'), 'no ChangeNumber on an edit');
t_check(str_contains($x, 'OldReserveAmount="3144.0000"'), 'credit re-check carries the old reserve');

$two = [
    $orderLine(['OrdDetail' => 1, 'OrdDetailPricing' => 11, 'ManualPrice' => 1, 'Price' => '2.0000', 'AliasCode' => 'A']),
    $orderLine(['OrdDetail' => 2, 'OrdDetailPricing' => 21, 'ManualPrice' => 1, 'Price' => '3.0000', 'QtyReqd' => '6.0000', 'QtyUsed' => '6.0000', 'AliasCode' => 'B']),
];
$fake = $newFake();
$res  = $salesWriter($orderCms([], $two), $fake)->execute('sales.edit_order', ['ordr' => 194480, 'remove' => ['1']], $nowE);
t_check($res->ok() && str_contains($fake->sent[0], 'Object="OrdDetailPricing" Step="2" Operation="Delete"><keys OrdDetailPricing="11"/>')
    && str_contains($fake->sent[0], 'Object="OrdDetail" Step="3" Operation="Delete"><keys OrdDetail="1"/>'), 'removing a line deletes its pricing row, then the line');
foreach ([
    'shipped line removal'  => [$orderCms([], $two), ['ordr' => 194480, 'remove' => [2]], 'has shipped'],
    'shipped line quantity' => [$orderCms([], $two), ['ordr' => 194480, 'lines' => [2 => ['qty' => '7', 'price' => '3']]], 'already shipped'],
    'vanished line'         => [$orderCms([], $two), ['ordr' => 194480, 'lines' => [3 => ['qty' => '7', 'price' => '3']]], 'no longer on order'],
    'completed order'       => [$orderCms(['Status' => 'CMP']), $inE, 'is completed'],
    'missing order'         => [$orderCms()->when('FROM CMS.dbo.Ordr o LEFT JOIN', []), $inE, 'not found'],
    'duplicate PO on edit'  => [$orderCms()->when($dupPo, [['Ordr' => 190001]]), ['ordr' => 194480, 'po_number' => 'PO-9'], 'already on order 190001'],
] as $name => [$cms, $in, $needle]) {
    $refused(fn () => $salesWriter($cms, $newFake())->preview('sales.edit_order', $in, $nowE), $needle, $name);
}

$fake = $newFake();
$res  = $salesWriter($orderCms(['Status' => 'QUO', 'IsQuote' => 1]), $fake)->execute('sales.edit_order', $inE, $nowE);
t_check($res->ok() && !str_contains($fake->sent[0], 'QtyCommitted') && !str_contains($fake->sent[0], 'CheckOrderCredit'), 'quote edits commit nothing and skip the credit check');

$version = 5;
$moving  = $orderCms()->when('FROM CMS.dbo.Ordr o LEFT JOIN', function () use (&$version, $orderHead) {
    return [$orderHead(['Version' => $version])];
});
$fake = $newFake();
$w    = $salesWriter($moving, $fake);
$seen = $w->preview('sales.edit_order', $inE, $nowE);
$version = 6;   // someone else saved the order in between
t_throws(\PII\Cms\Write\StalePreview::class, fn () => $w->execute('sales.edit_order', $inE, $nowE, $seen->hash));
t_same(0, count($fake->sent), 'an order saved by someone else in between forces a new preview');

t_section('Sales flow: release, complete, complete sale');
$fake = $newFake();
$held = $orderCms(['Ordr' => 194781, 'Version' => 1, 'Status' => 'CSH', 'CreditHold' => 1, 'ReserveAmount' => '643.2000']);
$res  = $salesWriter($held, $fake)->execute('sales.release_order', ['ordr' => 194781], new DateTimeImmutable('2026-11-09T12:53:37'));
t_check($res->ok(), 'release committed');
t_xml_same($salesNorm(cms_fixture('ShippingOrderDataSet.SaveChanges', 866348)), $salesNorm($fake->sent[0]), 'release matches log 866348');
$refused(fn () => $salesWriter($orderCms(), $newFake())->preview('sales.release_order', ['ordr' => 194480], $nowE), 'is open', 'only orders on credit hold can be released');

$fake  = $newFake();
$nowC  = new DateTimeImmutable('2026-11-09T12:38:17');
$lines = [
    $orderLine(['OrdDetail' => 564912, 'Price' => '2.5700', 'QtyReqd' => '2.0000', 'QtyUsed' => '1.0000']),
    $orderLine(['OrdDetail' => 564913, 'IsOpen' => 0, 'QtyReqd' => '6.0000', 'QtyUsed' => '6.0000']),
];
$w   = $salesWriter($orderCms(['Ordr' => 194579, 'Version' => 4, 'ReserveAmount' => '2.5700'], $lines), $fake);
$p   = $w->preview('sales.complete_order', ['ordr' => 194579], $nowC);
t_same([], $p->plan->warnings, 'no warning when something has shipped');
$res = $w->execute('sales.complete_order', ['ordr' => 194579], $nowC, $p->hash);
t_check($res->ok(), 'complete committed');
t_xml_same(cms_fixture('ShippingOrderDataSet.SaveChanges', 866346), preg_replace('/Application="[^"]+"/', 'Application="Chemical Management System"', $fake->sent[0]), 'complete matches log 866346, timestamps included');
$p = $salesWriter($orderCms(), $newFake())->preview('sales.complete_order', ['ordr' => 194480], $nowC);
t_check(count($p->plan->warnings) === 1 && str_contains($p->plan->warnings[0], 'Nothing has shipped'), 'warns when nothing has shipped');
$refused(fn () => $salesWriter($orderCms(['Status' => 'QUO', 'IsQuote' => 1]), $newFake())->preview('sales.complete_order', ['ordr' => 194480], $nowC), 'a quote', 'a quote cannot be completed');

$saleId = cms_fixture_ids('ShippingOrderDataSet.CompleteSale')[0];
$fSale  = cms_fixture_parse(cms_fixture('ShippingOrderDataSet.CompleteSale', $saleId));
$saleOrdr = (int) $fSale['steps'][0]['keys']['Ordr'];
$fake   = $newFake();
$w      = $salesWriter($orderCms(['Ordr' => $saleOrdr, 'Version' => (int) $fSale['steps'][0]['keys']['Version']]), $fake);
$p      = $w->preview('sales.complete_sale', ['ordr' => $saleOrdr], $nowC);
t_same(2, count($p->plan->warnings), 'complete sale warns that it ships and invoices at once, from stock');
$res    = $w->execute('sales.complete_sale', ['ordr' => $saleOrdr], $nowC, $p->hash);
t_check($res->ok(), 'complete sale committed');
t_xml_same($salesNorm(cms_fixture('ShippingOrderDataSet.CompleteSale', $saleId)), $salesNorm($fake->sent[0]), "complete sale matches log {$saleId}");
$refused(fn () => $salesWriter($orderCms([], [$lines[1]]), $newFake())->preview('sales.complete_sale', ['ordr' => 194480], $nowC), 'no open lines', 'complete sale needs an open line');
$refused(fn () => $salesWriter($orderCms(), $newFake(), ['write.sales.edit_order'])->preview('sales.complete_sale', ['ordr' => 194480], $nowC), 'permission', 'each sales operation needs its own permission');

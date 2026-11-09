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

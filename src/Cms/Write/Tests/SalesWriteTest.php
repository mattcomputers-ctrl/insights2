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

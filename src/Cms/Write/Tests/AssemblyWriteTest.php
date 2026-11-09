<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\Ops\Shipping\ShippingRequests;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

/**
 * A logged assembly request, taken apart: kind (new, add, remove), order,
 * container, versions and lines.
 *
 * A move with a CheckVersion before it changes a line already in the
 * container. Each line gets: shape (new, more, less, out), held (what
 * the container held before, worked back from the two logged numbers),
 * qty (what the template takes) and typed (what a person types into
 * the Insights form to get it).
 */
$asmParts = static function (array $f): array {
    $out = ['kind' => 'add', 'lines' => [], 'container' => 0, 'containerVersion' => 0, 'stockVersion' => 0];
    $checked = false;
    foreach ($f['steps'] as $step) {
        if ($step['object'] === 'Ordr') {
            $out['ordr']    = (int) $step['keys']['Ordr'];
            $out['version'] = (int) $step['keys']['Version'];
        } elseif ($step['object'] === 'CreateAssembly') {
            $out['kind'] = 'new';
        } elseif ($step['object'] === 'Location' && $step['operation'] === 'UpdateVersion') {
            $out['container']        = (int) $step['keys']['Location'];
            $out['containerVersion'] = (int) $step['keys']['Version'];
        } elseif ($step['object'] === 'Location') {
            $out['stockVersion'] = (int) $step['keys']['Version'];
            $checked = true;
        } elseif ($step['object'] === 'MoveInventory') {
            $out['owner'] = $step['data']['Owner'];
            $moved   = (float) $step['data']['Quantity'];
            $entered = $step['data']['QtyEntered'];
            $line    = ['ordDetail' => (int) $step['data']['TrgOrdDetail'], 'item' => (int) $step['data']['Item']];
            if (!$checked) {
                $line += ['shape' => 'new', 'qty' => $entered, 'typed' => $entered];
            } elseif ($moved > 0) {
                $line += ['shape' => 'more', 'held' => (float) $entered - $moved, 'qty' => $entered,
                          'typed' => \PII\Cms\Write\Fmt::num(round($moved, 6))];
            } elseif (ShippingRequests::g15(-$moved) === $entered) {
                $line += ['shape' => 'out', 'held' => -$moved, 'qty' => '0', 'typed' => $entered];
                $out['kind'] = 'remove';
            } else {
                $line += ['shape' => 'less', 'held' => (float) $entered - $moved, 'qty' => $entered,
                          'typed' => \PII\Cms\Write\Fmt::num(round(-$moved, 6))];
                $out['kind'] = 'remove';
            }
            $out['lines'][] = $line;
            $checked = false;
        }
    }
    return $out;
};
$asmRebuild = static fn (array $p): \PII\Cms\Write\Request => match ($p['kind']) {
    'new'    => ShippingRequests::assembleNew($p['ordr'], $p['version'], $p['owner'], $p['lines']),
    'add'    => ShippingRequests::assembleAdd($p['ordr'], $p['version'], $p['owner'], $p['container'], $p['containerVersion'], $p['lines'],
        $p['stockVersion'] ?: null),
    'remove' => ShippingRequests::assembleRemove($p['ordr'], $p['version'], $p['owner'], $p['container'], $p['containerVersion'], $p['stockVersion'], $p['lines']),
};
/** What the logged container held before the request, per order line. */
$asmHeld = static fn (array $p): array => array_column(array_filter($p['lines'], static fn (array $l): bool => isset($l['held'])), 'held', 'ordDetail');

t_section('Assembly golden: AssembleDataSet.SaveChanges');
$asmKinds = [];
foreach (cms_fixture_ids(ShippingRequests::ASSEMBLE) as $log) {
    $xml = cms_fixture(ShippingRequests::ASSEMBLE, $log);
    $f   = cms_fixture_parse($xml);
    $p   = $asmParts($f);
    t_xml_same($xml, $asmRebuild($p)->toXml($f['env']), "log {$log} ({$p['kind']}, " . count($p['lines']) . ' lines)');
    $asmKinds[$p['kind']] = ($asmKinds[$p['kind']] ?? 0) + 1;
    foreach ($p['lines'] as $l) {
        $asmShapes[$l['shape']] = ($asmShapes[$l['shape']] ?? 0) + 1;
    }
}
t_check(($asmKinds['new'] ?? 0) >= 3 && ($asmKinds['add'] ?? 0) >= 1 && ($asmKinds['remove'] ?? 0) >= 2, 'new, add and remove fixtures were all exercised');
t_check(($asmShapes['more'] ?? 0) >= 3 && ($asmShapes['less'] ?? 0) >= 2 && ($asmShapes['out'] ?? 0) >= 3 && ($asmShapes['new'] ?? 0) >= 4,
    'a line topped up, taken part out, taken out whole and new to the container were all exercised');

t_section('Assembly templates: a change to a line already in the container');
// The move is the new total less what is held, in floating point like the client (logs 814460, 802136, 733555).
$asmOne = static fn (\PII\Cms\Write\Request $r): string => $r->toXml(cms_fixture_parse(cms_fixture(ShippingRequests::ASSEMBLE, 814460))['env']);
$xml = $asmOne(ShippingRequests::assembleRemove(1, 1, '4', 2, 1, 1, [['ordDetail' => 5, 'item' => 6, 'held' => 272.7, 'qty' => '272']]));
t_check(str_contains($xml, 'Quantity="-0.69999999999998863" QtyEntered="272"'), 'part out: the move is what stays in less what is held');
$xml = $asmOne(ShippingRequests::assembleAdd(1, 1, '4', 2, 1, [['ordDetail' => 5, 'item' => 6, 'held' => 9.1999999999999886, 'qty' => '18']], 1));
t_check(str_contains($xml, 'Quantity="8.8000000000000114" QtyEntered="18"') && substr_count($xml, 'CheckVersion') === 1, 'topped up: a version check, the difference, and the new total as entered');
$xml = $asmOne(ShippingRequests::assembleRemove(1, 1, '4', 2, 1, 1, [['ordDetail' => 5, 'item' => 6, 'held' => 69.699999999999989]]));
t_check(str_contains($xml, 'Quantity="-69.699999999999989" QtyEntered="69.7"'), 'all out: minus what is held exactly, entered to 15 digits');
$xml = $asmOne(ShippingRequests::assembleAdd(1, 1, '4', 2, 1, [['ordDetail' => 7, 'item' => 8, 'qty' => '3'], ['ordDetail' => 5, 'item' => 6, 'held' => 1.0, 'qty' => '4']], 1));
t_check(strpos($xml, 'TrgOrdDetail="5"') < strpos($xml, 'TrgOrdDetail="7"') && substr_count($xml, 'CheckVersion') === 1
    && str_contains($xml, 'Quantity="3" QtyEntered="4"'), 'changed lines go first, new lines after them without a check');

t_section('Assembly templates refuse malformed input');
foreach ([
    'no lines'      => static fn () => ShippingRequests::assembleNew(1, 1, '4', []),
    'zero quantity' => static fn () => ShippingRequests::assembleNew(1, 1, '4', [['ordDetail' => 1, 'item' => 1, 'qty' => '0']]),
    'negative'      => static fn () => ShippingRequests::assembleAdd(1, 1, '4', 2, 1, [['ordDetail' => 1, 'item' => 1, 'qty' => '-1']]),
    'not a number'  => static fn () => ShippingRequests::assembleRemove(1, 1, '4', 2, 1, 1, [['ordDetail' => 1, 'item' => 1, 'held' => 5.0, 'qty' => 'x']]),
    'take out without what is held' => static fn () => ShippingRequests::assembleRemove(1, 1, '4', 2, 1, 1, [['ordDetail' => 1, 'item' => 1, 'qty' => '1']]),
    'more stays in than is held'    => static fn () => ShippingRequests::assembleRemove(1, 1, '4', 2, 1, 1, [['ordDetail' => 1, 'item' => 1, 'held' => 5.0, 'qty' => '5']]),
    'top up without the stock version' => static fn () => ShippingRequests::assembleAdd(1, 1, '4', 2, 1, [['ordDetail' => 1, 'item' => 1, 'held' => 5.0, 'qty' => '6']]),
    'top up to no more than is held'   => static fn () => ShippingRequests::assembleAdd(1, 1, '4', 2, 1, [['ordDetail' => 1, 'item' => 1, 'held' => 5.0, 'qty' => '5']], 1),
] as $name => $fn) {
    t_check(t_throws(InvalidArgumentException::class, $fn) !== null, "refused: {$name}");
}

/* ── Flows through the one door (CmsWriter) against a fake CMS ── */

$asmPerms  = ['write.ship.assemble'];
$asmWriter = static function (\PII\Cms\Write\FakeCmsReader $cms, $transport, ?array $perms = null) use ($asmPerms): \PII\Cms\Write\CmsWriter {
    $perms ??= $asmPerms;
    return new \PII\Cms\Write\CmsWriter($cms, $transport, static fn (string $p) => in_array($p, $perms, true), 'pic\\user1',
        'WS1', true, '7.15.0.680', static function (array $row): void {});
};
$asmNorm = static fn (string $xml): string => (string) preg_replace(['/Application="[^"]+"/', '/ TransDate="[^"]+"/'],
    ['Application="Chemical Management System"', ' TransDate="T"'], $xml);
$asmRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle), "refused: {$label}" . ($e !== null ? ': ' . $e->getMessage() : ' (nothing thrown)'));
};

/**
 * CMS as the assembly flows read it, built from a logged request: the
 * order has the logged lines (10 ordered each, none shipped), the stock
 * location holds 100 of everything, and $inside says what already sits
 * in the logged container (order line => quantity).
 */
$asmWorld = static function (array $p, array $inside = [], array $over = []): \PII\Cms\Write\FakeCmsReader {
    $w = $over + ['lots' => 1, 'insideItem' => null, 'status' => 'RTS', 'ordered' => '10.0000', 'shipped' => '0.0000', 'open' => 1, 'onHand' => '1.0000000000000000e+002',
                  'stockVersion' => $p['stockVersion'] ?: 1, 'containers' => $p['container'] ? [$p['container'] => $p['containerVersion']] : []];
    $lines = [];
    foreach ($p['lines'] as $l) {
        $lines[] = ['OrdDetail' => $l['ordDetail'], 'Item' => $l['item'], 'ItemName' => $l['item'], 'IsOpen' => $w['open'], 'ManualPrice' => 0,
                    'Price' => '1.0000', 'QtyReqd' => $w['ordered'], 'QtyUsed' => $w['shipped'], 'ItemCode' => 'PP' . $l['item'],
                    'AliasCode' => null, 'Description' => 'Ink', 'Unit' => 'lb', 'OrdDetailPricing' => null];
    }
    return (new \PII\Cms\Write\FakeCmsReader())
        ->when('FROM CMS.dbo.Ordr o LEFT JOIN', static fn (array $q) => $q[0] === $p['ordr'] ? [[
            'Ordr' => $p['ordr'], 'Version' => $p['version'], 'Status' => $w['status'], 'Owner' => '4', 'ShipTo' => 101427, 'BillTo' => 101427,
            'IsQuote' => 0, 'CreditHold' => 0, 'ReserveAmount' => '0.0000', 'PoNumber' => 'PO-1',
            'DateRequired' => '2026-11-10T09:30:00', 'DateCompleted' => null, 'ShipVia' => 100738, 'ShipViaName' => 'Our Truck',
            'ShipToName' => 'Company 12', 'BillToName' => 'Company 12']] : [])
        ->when('FROM CMS.dbo.OrdDetail od', $lines)
        ->when("FROM CMS.dbo.MemoRef m WHERE m.TableName = 'Ordr'", [])
        ->when('/* assembled */', static function () use ($w): array {
            $out = [];
            foreach ($w['containers'] as $loc => $version) {
                $out[] = ['ContainerId' => $loc, 'Container' => "ASM{$loc}", 'WeightLb' => null, 'ItemCode' => null, 'Version' => $version];
            }
            return $out;
        })
        ->when('FROM CMS.dbo.GetAssemblyContent(?)', static function (array $q) use ($p, $inside, $w): array {
            $out = [];
            foreach ($q[0] === $p['container'] ? $inside : [] as $ordDetail => $qty) {
                $item = $w['insideItem'] ?? array_column($p['lines'], 'item', 'ordDetail')[$ordDetail] ?? 999;
                // 'lots' splits each line over that many content rows.
                for ($n = 0; $n < $w['lots']; $n++) {
                    $out[] = ['OrdDetail' => $ordDetail, 'Item' => $item, 'ItemCode' => "PP{$item}", 'Unit' => 'lb', 'Qty' => sprintf('%.16e', $qty / $w['lots'])];
                }
            }
            return $out;
        })
        ->when('/* pick stock */', [['OnHand' => $w['onHand']]])
        ->when('/* location version */', static fn (array $q) => (string) $q[0] === ShippingRequests::STOCK
            ? [['Version' => $w['stockVersion']]] : []);
};
$asmInput = static function (array $p, ?array $qty = null): array {
    return ['order' => $p['ordr'], 'container' => $p['kind'] === 'new' ? 'new' : (string) $p['container'],
            'qty' => $qty ?? array_column($p['lines'], 'typed', 'ordDetail')];
};
$asmNow = new DateTimeImmutable('2026-11-09T10:00:00');

t_section('Assembly registry');
$asmDefs = \PII\Cms\Write\OperationRegistry::permissionsFor('shipping');
t_check(isset($asmDefs['write.ship.assemble']) && str_contains($asmDefs['write.ship.assemble'], 'assemble a sales order'), 'assembling and taking out share one permission');

t_section('Assembly flow: every logged request, through the flow');
foreach (cms_fixture_ids(ShippingRequests::ASSEMBLE) as $log) {
    $xml  = cms_fixture(ShippingRequests::ASSEMBLE, $log);
    $p    = $asmParts(cms_fixture_parse($xml));
    $op   = $p['kind'] === 'remove' ? 'ship.unassemble' : 'ship.assemble';
    $fake = new \PII\Cms\Write\FakeTransport();
    // The container holds what the logged one held; the form gets what a person would type.
    $w    = $asmWriter($asmWorld($p, $asmHeld($p), ['ordered' => '1000.0000', 'onHand' => '5000']), $fake);
    $pre  = $w->preview($op, $asmInput($p), $asmNow);
    $res  = $w->execute($op, $asmInput($p), $asmNow, $pre->hash);
    t_check($res->ok() && count($fake->sent) === 1, "log {$log}: one request");
    t_xml_same($asmNorm($xml), $asmNorm($fake->sent[0]), "log {$log}: the {$p['kind']} flow sends the logged request");
}

t_section('Assembly flow: previews and rules');
$asmNew = $asmParts(cms_fixture_parse(cms_fixture(ShippingRequests::ASSEMBLE, 866114)));
$asmAdd = $asmParts(cms_fixture_parse(cms_fixture(ShippingRequests::ASSEMBLE, 865759)));
$asmRem = $asmParts(cms_fixture_parse(cms_fixture(ShippingRequests::ASSEMBLE, 855689)));
[$asmL1, $asmL2] = array_column($asmNew['lines'], 'ordDetail');
$asmPre = static fn (string $op, array $p, array $input, array $inside = [], array $over = [], ?array $perms = null)
    => $asmWriter($asmWorld($p, $inside, $over), new \PII\Cms\Write\FakeTransport(), $perms)->preview($op, $input, $asmNow);

$pre = $asmPre('ship.assemble', $asmNew, $asmInput($asmNew, [$asmL1 => '4', $asmL2 => '']));
$sum = json_encode($pre->plan->summary);
t_check(str_contains($sum, 'a new container') && str_contains($sum, '4 lb from stock') && str_contains($sum, '6 lb still to assemble'), 'the preview says what is picked and what is left');
t_same(1, substr_count($pre->rendered->stages[0]->xml ?? json_encode($pre->rendered), 'MoveInventory'), 'a blank line is left out');
$pre = $asmPre('ship.assemble', $asmNew, $asmInput($asmNew, [$asmL1 => '1,000.5']), [], ['ordered' => '2000.0000', 'onHand' => '5000']);
t_check(str_contains(json_encode($pre->rendered), '1000.5'), 'thousands separators are accepted');
$pre = $asmPre('ship.assemble', $asmAdd, $asmInput($asmAdd), [$asmAdd['lines'][0]['ordDetail'] => 5.0]);
t_check(str_contains(json_encode($pre->plan->summary), "container ASM{$asmAdd['container']}"), 'adding names the container');
// 2 more of a line the container already holds 5 of: sent as a change to 7, not as a second pick.
$asmA1 = $asmAdd['lines'][0]['ordDetail'];
$pre = $asmPre('ship.assemble', $asmAdd, $asmInput($asmAdd, [$asmA1 => '2']), [$asmA1 => 5.0]);
$xml = json_encode($pre->rendered);
t_check(str_contains($xml, 'Quantity=\"2\" QtyEntered=\"7\"') && substr_count($xml, 'CheckVersion') === 1, 'more of a line already in the container raises its total');
t_check(str_contains(json_encode($pre->plan->summary), '2 lb from stock, making 7 lb in the container'), 'the preview says what the container will hold');
$pre = $asmPre('ship.assemble', $asmAdd, $asmInput($asmAdd, [$asmA1 => '0.3']), [$asmA1 => 0.1]);
t_check(str_contains(json_encode($pre->rendered), 'Quantity=\"0.30000000000000004\" QtyEntered=\"0.4\"'), 'the difference is worked out in floating point, like the client');
$asmRefused(fn () => $asmPre('ship.assemble', $asmAdd, $asmInput($asmAdd, [$asmA1 => '2']), [$asmA1 => 5.0], ['lots' => 2]), 'more than one lot', 'adding to a line held as several lots');
$asmRefused(fn () => $asmPre('ship.assemble', $asmAdd, $asmInput($asmAdd, [$asmA1 => '2']), [$asmA1 => 5.0], ['insideItem' => 999]), 'has changed in CMS', 'a line whose item is not the one in the container');

$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew), [], [], ['write.ship.create_waybill']), 'permission', 'assembling needs its own permission');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, ['order' => 5] + $asmInput($asmNew)), 'not found', 'an unknown order');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew), [], ['status' => 'CMP']), 'assembled', 'a completed order');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew, [])), 'at least one line', 'no quantities');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew, [$asmL1 => '', $asmL2 => ' '])), 'at least one line', 'only blank quantities');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew, [$asmL1 => '0'])), 'above zero', 'zero');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew, [$asmL1 => '-2'])), 'above zero', 'a negative quantity');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew, [$asmL1 => 'five'])), 'above zero', 'words');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew, [77 => '1'])), 'has changed', 'a line of another order');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew), [], ['open' => 0]), 'closed', 'a closed line');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew, [$asmL1 => '10.5'])), 'only 10 lb is left', 'more than ordered');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew, [$asmL1 => '5']), [], ['shipped' => '6.0000']), 'only 4 lb is left', 'more than is left after shipping');
$asmRefused(fn () => $asmPre('ship.assemble', $asmAdd, $asmInput($asmAdd, [$asmAdd['lines'][0]['ordDetail'] => '5']), [$asmAdd['lines'][0]['ordDetail'] => 7.0]),
    'already in containers 7', 'more than is left after what is already assembled');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew), [], ['onHand' => '3.0000000000000000e+000']), 'only 3 lb', 'more than is in stock');
$asmRefused(fn () => $asmPre('ship.assemble', $asmNew, $asmInput($asmNew), [], ['onHand' => null]), 'only 0 lb', 'nothing in stock');
$asmRefused(fn () => $asmPre('ship.assemble', $asmAdd, ['container' => '424242'] + $asmInput($asmAdd)), 'not assembled for order', "another order's container");
$asmRefused(fn () => $asmPre('ship.assemble', $asmAdd, $asmInput($asmAdd), [], ['containers' => []]), 'not assembled for order', 'a container that has shipped');

t_section('Assembly flow: taking stock out');
[$asmR1, $asmR2] = array_column($asmRem['lines'], 'ordDetail');
$asmFull = [$asmR1 => 5.0, $asmR2 => 8.0];
$pre = $asmPre('ship.unassemble', $asmRem, ['order' => $asmRem['ordr'], 'container' => (string) $asmRem['container']], $asmFull);
$xml = json_encode($pre->rendered);
t_check(substr_count($xml, 'MoveInventory') === 2 && str_contains($xml, 'Quantity=\"-5\"') && str_contains($xml, 'Quantity=\"-8\"'), 'without quantities everything comes out');
t_check(str_contains(json_encode($pre->plan->summary), 'back to stock'), 'the preview says where it goes');
$pre = $asmPre('ship.unassemble', $asmRem, $asmInput($asmRem, [$asmR1 => '2', $asmR2 => '']), $asmFull);
t_check(str_contains(json_encode($pre->plan->summary), '3 lb stays in') && substr_count(json_encode($pre->rendered), 'MoveInventory') === 1, 'part of one line can come out');
t_check(str_contains(json_encode($pre->rendered), 'Quantity=\"-2\" QtyEntered=\"3\"'), 'part out: the request carries what stays in as entered');
$pre = $asmPre('ship.unassemble', $asmRem, $asmInput($asmRem, [$asmR1 => '0.7']), [$asmR1 => 272.7]);
t_check(str_contains(json_encode($pre->rendered), 'Quantity=\"-0.69999999999998863\" QtyEntered=\"272\"'), 'part out of 272.7: the same odd digits the client sends');
$pre = $asmPre('ship.unassemble', $asmRem, $asmInput($asmRem, [$asmR1 => '5']), $asmFull);
t_check(str_contains(json_encode($pre->rendered), 'Quantity=\"-5\" QtyEntered=\"5\"') && !str_contains(json_encode($pre->plan->summary), 'lb stays in'), 'the whole quantity typed takes the line out');
$asmRefused(fn () => $asmPre('ship.unassemble', $asmRem, $asmInput($asmRem, [$asmR1 => '2']), $asmFull, ['lots' => 2]), 'more than one lot', 'taking out of a line held as several lots');
$asmRefused(fn () => $asmPre('ship.unassemble', $asmRem, ['order' => $asmRem['ordr'], 'container' => (string) $asmRem['container']], $asmFull, ['lots' => 2]), 'more than one lot', 'taking everything out when a line is held as several lots');

$asmRefused(fn () => $asmPre('ship.unassemble', $asmRem, $asmInput($asmRem), $asmFull, [], ['write.ship.reject_waybill']), 'permission', 'taking out needs the assembly permission');
$asmRefused(fn () => $asmPre('ship.unassemble', $asmRem, ['container' => ''] + $asmInput($asmRem), $asmFull), 'Choose the container', 'no container');
$asmRefused(fn () => $asmPre('ship.unassemble', $asmRem, ['container' => '424242'] + $asmInput($asmRem), $asmFull), 'not assembled for order', "another order's container");
$asmRefused(fn () => $asmPre('ship.unassemble', $asmRem, $asmInput($asmRem), []), 'is empty', 'an empty container');
$asmRefused(fn () => $asmPre('ship.unassemble', $asmRem, $asmInput($asmRem, [$asmR1 => '6']), $asmFull), 'holds 5 lb', 'more than the container holds');
$asmRefused(fn () => $asmPre('ship.unassemble', $asmRem, $asmInput($asmRem, [77 => '1']), $asmFull), 'has changed', 'a line that is not in the container');
$asmRefused(fn () => $asmPre('ship.unassemble', $asmRem, $asmInput($asmRem, [$asmR1 => '']), $asmFull), 'at least one line', 'only blank quantities');
$asmRefused(fn () => $asmPre('ship.unassemble', $asmRem, $asmInput($asmRem), $asmFull, ['status' => 'CMP']), 'changed', 'a completed order');

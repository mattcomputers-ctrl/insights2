<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\Ops\Shipping\CreateWaybill;
use PII\Cms\Write\Ops\Shipping\ShippingRequests;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

/** Rebuild a logged waybill request (either the first one or one of the later to-do requests). */
$shipRebuild = static function (array $f): \PII\Cms\Write\Request {
    $s = $f['steps'];
    if ($s[0]['object'] === 'WaybillInProcess') {
        $todo = $s[2]['object'] ?? ShippingRequests::TODO_COMPLETE;
        return ShippingRequests::todo($todo, $s[0]['keys']['WaybillInProcess'], $s[1]['keys']['Waybill'], $s[1]['keys']['Version'],
            $s[2]['data']['Ordr'] ?? '', $s[2]['data']['FreightCharge'] ?? '', $f['action'] === ShippingRequests::RESUME);
    }
    $orders = $containers = $weights = $freight = [];
    $head   = $address = null;
    foreach ($s as $step) {
        switch ($step['object']) {
            case 'Ordr':
                $orders[] = ['ordr' => $step['keys']['Ordr'], 'version' => $step['keys']['Version']];
                break;
            case 'Location':
                $containers[] = ['location' => $step['keys']['Location'], 'version' => $step['keys']['Version']];
                break;
            case 'Waybill':
                $head = $step['data'];
                break;
            case 'WaybillTrackingNumber':
                $weights[$step['data']['Location']] = (float) $step['data']['ActWeightLb'];
                break;
            case 'AddressRef':
                $address = $step['data'];
                break;
            case 'WaybillInProcess':
                if (isset($step['data']['Ordr'])) {
                    $freight[$step['data']['Ordr']] = $step['data']['FreightCharge'] ?? '';
                }
                break;
        }
    }
    foreach ($orders as &$o) {
        $o['freight'] = $freight[$o['ordr']];
    }
    foreach ($containers as &$c) {
        $c['lb'] = $weights[$c['location']];
    }
    unset($o, $c);
    $fromOrder = array_diff_key($head, array_flip(['Owner', 'PlacedBy', 'DateShipped', 'ShipAsIs', 'TrailerNumber', 'SealNumbers', 'Comment']));
    return ShippingRequests::createWaybill($head['Owner'], $head['PlacedBy'], $head['DateShipped'], $orders, $containers,
        $fromOrder, $address, $address['TableID']);
};

// The client adds kilograms up line by line; ours is pounds times the factor. Same weight, last digits may differ.
$shipKg = static fn (string $xml): string => preg_replace_callback('/ActWeightKg="([^"]+)"/',
    static fn (array $m): string => 'ActWeightKg="' . round((float) $m[1], 9) . '"', $xml);

t_section('Shipping golden: WaybillDataSet.SaveChanges');
$count = 0;
$firsts = 0;
foreach (cms_fixture_ids(ShippingRequests::SAVE) as $log) {
    $xml = cms_fixture(ShippingRequests::SAVE, $log);
    $f   = cms_fixture_parse($xml);
    t_xml_same($shipKg($xml), $shipKg($shipRebuild($f)->toXml($f['env'])), "log {$log}");
    $firsts += $f['steps'][0]['object'] === 'Ordr' ? 1 : 0;
    $count++;
}
t_check($count >= 19, 'all waybill fixtures were exercised');
t_check($firsts >= 6, 'at least six of them create a waybill');
t_check(str_contains(cms_fixture(ShippingRequests::SAVE, 863747), 'FreightOption="0" DeliveryConfirmation="1" PoNumber='),
    'log 863747 has a delivery confirmation, written before the PO number');

// Finishing a waybill left part way: the client's own requests (waybill 3863, logged in 2020 under another owner).
t_section('Shipping golden: WaybillDataSet.ProcessOutstandingRequests');
$count = 0;
foreach (cms_fixture_ids(ShippingRequests::RESUME) as $log) {
    $xml = cms_fixture(ShippingRequests::RESUME, $log);
    $f   = cms_fixture_parse($xml);
    t_same(ShippingRequests::RESUME, $f['action'], "log {$log} is a resume request");
    t_xml_same($xml, $shipRebuild($f)->toXml($f['env']), "log {$log}");
    $count++;
}
t_check($count >= 3, 'the three resume fixtures were exercised');

t_section('Shipping golden: WaybillDataSet.RejectWaybill');
$count = 0;
foreach (cms_fixture_ids(ShippingRequests::REJECT) as $log) {
    $xml = cms_fixture(ShippingRequests::REJECT, $log);
    $f   = cms_fixture_parse($xml);
    $k   = $f['steps'][0]['keys'];
    t_xml_same($xml, ShippingRequests::rejectWaybill((int) $k['Waybill'], (int) $k['Version'])->toXml($f['env']), "log {$log}");
    $count++;
}
t_check($count >= 3, 'reject fixtures were exercised');

t_section('Shipping golden: PrintShippingLabel.UpdateShippingLabelAddress');
// The label printer's own envelope names no user or workstation. Ours always does, so the actions are compared.
$shipActions = static fn (string $xml): string => substr($xml, (int) strpos($xml, '<action'));
$shipEnv     = cms_fixture_parse(cms_fixture(ShippingRequests::REJECT, 848374))['env'];
$count       = 0;
foreach (cms_fixture_ids(ShippingRequests::LABEL) as $log) {
    $xml = cms_fixture(ShippingRequests::LABEL, $log);
    preg_match('/TableID="(\d+)".*Address="(\d+)"/', $xml, $m);
    $ours = ShippingRequests::labelAddress((int) $m[1], (int) $m[2])->toXml($shipEnv);
    t_same($shipActions($xml), $shipActions($ours), "log {$log}");
    t_check(str_contains($ours, 'Program="PrintShippingLabel"') && str_contains($ours, 'User="pic\\user1"'), "log {$log}: sent as the label program, with the user named");
    $count++;
}
t_check($count >= 3, 'label fixtures were exercised');
t_check(str_contains(ShippingRequests::labelAddress(1, 2, true)->toXml($shipEnv), 'Operation="Update"'), 'a reprint to another address updates the record');

t_section('Shipping number formats');
foreach ([[24.0, '24'], [0.0, '0'], [29.6, '29.6'], [24 * ShippingRequests::KG_PER_LB, '10.886216880000001'],
          [144 * ShippingRequests::KG_PER_LB, '65.317301280000009'], [29.6 * ShippingRequests::KG_PER_LB, '13.426334152']] as [$in, $want]) {
    t_same($want, ShippingRequests::dbl($in), "double {$want}");
}
t_same('188.0000', ShippingRequests::money('188'), 'money has four decimals');

t_section('Shipping templates refuse malformed input');
$shipAddr = ['Address' => 1, 'Name' => 'Company 1'];
foreach ([
    'no orders'       => static fn () => ShippingRequests::createWaybill('4', 'u', 'd', [], [['location' => 1, 'version' => 1, 'lb' => 1.0]], [], $shipAddr),
    'no containers'   => static fn () => ShippingRequests::createWaybill('4', 'u', 'd', [['ordr' => 1, 'version' => 1]], [], [], $shipAddr),
    'no address'      => static fn () => ShippingRequests::createWaybill('4', 'u', 'd', [['ordr' => 1, 'version' => 1]], [['location' => 1, 'version' => 1, 'lb' => 1.0]], [], []),
    'unknown column'  => static fn () => ShippingRequests::createWaybill('4', 'u', 'd', [['ordr' => 1, 'version' => 1]], [['location' => 1, 'version' => 1, 'lb' => 1.0]], ['Status' => 'CMP'], $shipAddr),
    'negative weight' => static fn () => ShippingRequests::createWaybill('4', 'u', 'd', [['ordr' => 1, 'version' => 1]], [['location' => 1, 'version' => 1, 'lb' => -1.0]], [], $shipAddr),
    'unknown to-do'   => static fn () => ShippingRequests::todo('DropEverything', 1, 1, 1),
    'ship no order'   => static fn () => ShippingRequests::todo(ShippingRequests::TODO_SHIP, 1, 1, 1),
] as $name => $fn) {
    t_check(t_throws(InvalidArgumentException::class, $fn) !== null, "refused: {$name}");
}

/* ── Flows through the one door (CmsWriter) against a fake CMS ── */

$shipPerms  = array_keys(\PII\Cms\Write\OperationRegistry::permissionsFor('shipping'));
$shipWriter = static function (\PII\Cms\Write\FakeCmsReader $cms, $transport, ?array $perms = null) use ($shipPerms): \PII\Cms\Write\CmsWriter {
    $perms ??= $shipPerms;
    return new \PII\Cms\Write\CmsWriter($cms, $transport, static fn (string $p) => in_array($p, $perms, true), 'pic\\user1',
        'WS1', true, '7.15.0.680', static function (array $row): void {});
};
// The client fills the address TableID with a number of its own; CMS takes the real one from the Waybill step.
$shipNorm = static fn (string $xml): string => $shipKg((string) preg_replace(
    ['/Application="[^"]+"/', '/ TransDate="[^"]+"/', '/ TableID="\d+" TableName="Waybill"/'],
    ['Application="Chemical Management System"', ' TransDate="T"', ' TableID="N" TableName="Waybill"'], $xml));
$shipRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle), "refused: {$label}" . ($e !== null ? '' : ' (nothing thrown)'));
};
$shipFake = static fn (): \PII\Cms\Write\FakeTransport => new \PII\Cms\Write\FakeTransport();

t_section('Shipping registry');
t_same(['write.ship.assemble', 'write.ship.create_waybill', 'write.ship.reject_waybill', 'write.invoice.freight', 'write.ship.label_address'], $shipPerms,
    'five shipping write permissions (finishing a waybill goes with creating one)');
t_check(str_contains(\PII\Cms\Write\OperationRegistry::permissionsFor('shipping')['write.ship.create_waybill'], 'ship assembled'), 'the shared permission is described by the main operation');

/**
 * CMS as a create-waybill flow reads it, built from a logged first request:
 * each order has one container, in the order logged.
 */
$shipWorld = static function (int $firstLog, \PII\Cms\Write\FakeTransport $fake, int $waybill, int $firstTodo) {
    $f      = cms_fixture_parse(cms_fixture(ShippingRequests::SAVE, $firstLog));
    $orders = $locs = $lbs = $freight = [];
    $head   = $addr = [];
    foreach ($f['steps'] as $step) {
        match ($step['object']) {
            'Ordr'                  => $orders[(int) $step['keys']['Ordr']] = (int) $step['keys']['Version'],
            'Location'              => $locs[(int) $step['keys']['Location']] = (int) $step['keys']['Version'],
            'Waybill'               => $head = $step['data'],
            'WaybillTrackingNumber' => $lbs[(int) $step['data']['Location']] = $step['data']['ActWeightLb'],
            'AddressRef'            => $addr = $step['data'],
            'WaybillInProcess'      => isset($step['data']['Ordr']) ? $freight[(int) $step['data']['Ordr']] = $step['data']['FreightCharge'] ?? '' : null,
            default                 => null,
        };
    }
    $orderIds = array_keys($orders);
    $locIds   = array_keys($locs);
    $todoRows = [];
    foreach ($orderIds as $id) {
        $todoRows[] = ['Ordr' => $id, 'Action' => 'CreateShipmentsByOrder', 'FreightCharge' => $freight[$id] === '' ? null : $freight[$id] . '.0000'];
    }
    $todoRows[] = ['Ordr' => null, 'Action' => 'SaveShippedPallets', 'FreightCharge' => null];
    $todoRows[] = ['Ordr' => null, 'Action' => 'MarkCompleted', 'FreightCharge' => null];

    $cms = (new \PII\Cms\Write\FakeCmsReader())
        ->when('FROM CMS.dbo.Ordr o LEFT JOIN', static fn (array $p) => isset($orders[$p[0]]) ? [[
            'Ordr' => $p[0], 'Version' => $orders[$p[0]], 'Status' => 'RTS', 'Owner' => '4', 'ShipTo' => 101427, 'BillTo' => 101427,
            'IsQuote' => 0, 'CreditHold' => 0, 'ReserveAmount' => '0.0000', 'PoNumber' => 'PO-' . $p[0],
            'DateRequired' => '2026-11-10T09:30:00', 'DateCompleted' => null, 'ShipVia' => 100738, 'ShipViaName' => 'Our Truck',
            'ShipToName' => 'Company 12', 'BillToName' => 'Company 12']] : [])
        ->when('FROM CMS.dbo.OrdDetail od', [])
        ->when("FROM CMS.dbo.MemoRef m WHERE m.TableName = 'Ordr'", [])
        ->when('/* assembled */', static function (array $p) use ($orderIds, $locIds, $locs, $lbs): array {
            $k = array_search($p[0], $orderIds, true);
            // One container per order; the last order takes any that are left.
            $mine = $k === count($orderIds) - 1 ? array_slice($locIds, $k) : [$locIds[$k]];
            return array_map(static fn (int $loc): array => ['ContainerId' => $loc, 'Container' => "ASM{$loc}", 'WeightLb' => $lbs[$loc],
                'ItemCode' => 'E100', 'Version' => $locs[$loc]], $mine);
        })
        ->when('/* freight */', [[
            'ShipVia' => $head['ShipVia'] ?? null, 'FreightService' => $head['FreightService'] ?? null,
            'FreightOption' => $head['FreightOption'] === '0' ? null : $head['FreightOption'], 'FreightAccount' => $head['FreightAccount'] ?? null,
            'PoNumber' => $head['PoNumber'] ?? null, 'DeliveryConfirmation' => $head['DeliveryConfirmation'] ?? null]])
        ->when("FROM CMS.dbo.AddressRef a WHERE a.TableName = 'Ordr'", [[
            'Address' => $addr['Address'], 'Name' => $addr['Name'], 'Department' => null, 'AddrLine1' => $addr['AddrLine1'],
            'AddrLine2' => $addr['AddrLine2'], 'AddrLine3' => $addr['AddrLine3'], 'City' => $addr['City'], 'State' => $addr['State'],
            'ZipCode' => $addr['ZipCode'], 'Country' => null, 'Contact' => $addr['Contact'], 'Email' => $addr['Email'],
            'Fax' => $addr['Fax'], 'Phone' => $addr['Phone'], 'StateName' => null, 'CountryName' => $addr['CountryName'] ?: null, 'URL' => null,
            'Residential' => null, 'EmergencyContact' => null]])
        // Once the waybill exists: one to-do row is ticked and the version moves on with every later request.
        ->when('FROM CMS.dbo.WaybillInProcess p WHERE p.Waybill = ?', static function (array $p) use ($fake, $todoRows, $waybill, $firstTodo): array {
            t_same($waybill, $p[0], 'the to-do list is read for the new waybill');
            $out = [];
            foreach ($todoRows as $k => $row) {
                $out[] = ['WaybillInProcess' => $firstTodo + $k, 'IsDone' => $k < count($fake->sent) - 1 ? 1 : 0] + $row;
            }
            return $out;
        })
        ->when('/* waybill version */', static fn () => [['Version' => count($fake->sent)]]);
    $wbStep = count($orders) + count($locs) + 1;
    $fake->willReturn(new \PII\Cms\Write\TransportResult(\PII\Cms\Write\TransportResult::COMMITTED, 900001, 0, null, 'ok',
        [['step' => $wbStep, 'table' => 'Waybill', 'field' => 'Waybill', 'value' => (string) $waybill]]));
    return [$cms, $orderIds, $locIds, new DateTimeImmutable($head['DateShipped']), $freight];
};

t_section('Shipping flow: two orders on one waybill (logs 861915 to 861919)');
$fake = $shipFake();
[$cms, $ids, $locs, $now] = $shipWorld(861915, $fake, 18476, 55779);
$in   = ['orders' => implode(', ', $ids)];
$w    = $shipWriter($cms, $fake);
$p    = $w->preview('ship.create_waybill', $in, $now);
t_same(0, count($fake->sent), 'preview sends nothing');
t_same(5, count($p->rendered->stages), 'the preview shows all five requests');
$shown = json_encode($p->rendered);
t_check(str_contains($shown, '{stage 1: new Waybill}') && str_contains($shown, '{to-do row 4}'), 'ids CMS will generate show as placeholders');
t_check(str_contains(json_encode($p->plan->warnings), 'no weight'), 'a container with no weight is pointed out');
$res = $w->execute('ship.create_waybill', $in, $now, $p->hash);
t_check($res->ok(), 'the waybill committed on the fake transport');
t_same(5, count($fake->sent), 'five requests were sent');
foreach ([861915, 861916, 861917, 861918, 861919] as $k => $log) {
    t_xml_same($shipNorm(cms_fixture(ShippingRequests::SAVE, $log)), $shipNorm($fake->sent[$k] ?? ''), "request " . ($k + 1) . " matches log {$log}");
}

$fake = $shipFake();
[$cms] = $shipWorld(861915, $fake, 18476, 55779);
$res   = $shipWriter($cms, new \PII\Cms\Write\DryRunTransport())->execute('ship.create_waybill', $in, $now);
t_check($res->dryRun(), 'shipping runs end to end in dry-run');
t_same(5, count($res->stages), 'dry-run checks all five requests');

t_section('Shipping flow: one order with a freight charge (logs 855512 to 855515)');
$fake = $shipFake();
[$cms, $ids, $locs, $now, $freight] = $shipWorld(855512, $fake, 18330, 55332);
$in   = ['orders' => [(string) $ids[0]], 'freight' => [$ids[0] => '$188.00'], 'weights' => [$locs[0] => '144']];
$res  = $shipWriter($cms, $fake)->execute('ship.create_waybill', $in, $now);
t_check($res->ok(), 'the waybill committed');
foreach ([855512, 855513, 855514, 855515] as $k => $log) {
    t_xml_same($shipNorm(cms_fixture(ShippingRequests::SAVE, $log)), $shipNorm($fake->sent[$k] ?? ''), "request " . ($k + 1) . " matches log {$log}");
}

t_section('Shipping flow: an order with a delivery confirmation (log 863747)');
$fake = $shipFake();
[$cms, $ids, $locs, $now] = $shipWorld(863747, $fake, 18520, 55950);
$res  = $shipWriter($cms, $fake)->execute('ship.create_waybill', ['orders' => $ids], $now);
t_check($res->ok() && count($fake->sent) === 4, 'one order makes four requests');
t_xml_same($shipNorm(cms_fixture(ShippingRequests::SAVE, 863747)), $shipNorm($fake->sent[0] ?? ''), 'first request matches log 863747');

t_section('Shipping flow: three orders (log 859033) and typed weights');
$fake = $shipFake();
[$cms, $ids, $locs, $now] = $shipWorld(859033, $fake, 18412, 55500);
$res  = $shipWriter($cms, $fake)->execute('ship.create_waybill', ['orders' => $ids], $now);
t_check($res->ok() && count($fake->sent) === 6, 'three orders make six requests');
t_xml_same($shipNorm(cms_fixture(ShippingRequests::SAVE, 859033)), $shipNorm($fake->sent[0]), 'first request matches log 859033');
$fake = $shipFake();
[$cms, $ids, $locs, $now] = $shipWorld(859033, $fake, 18412, 55500);
$shipWriter($cms, $fake)->execute('ship.create_waybill', ['orders' => $ids, 'weights' => [$locs[1] => '29.6']], $now);
t_check(str_contains($fake->sent[0], 'ActWeightKg="13.426334152" ActWeightLb="29.6"'), 'a typed weight replaces the one CMS worked out');

t_section('Shipping flow: create refusals');
$world = static function (?callable $tweak = null) use ($shipWorld, $shipFake): array {
    $fake = $shipFake();
    $w    = $shipWorld(861915, $fake, 18476, 55779);
    if ($tweak !== null) {
        $tweak($w[0]);
    }
    return [$w[0], $fake, $w[1], $w[3]];
};
$orderRow = static fn (int $id, array $over): array => $over + [
    'Ordr' => $id, 'Version' => 3, 'Status' => 'RTS', 'Owner' => '4', 'ShipTo' => 101427, 'BillTo' => 101427, 'IsQuote' => 0,
    'CreditHold' => 0, 'ReserveAmount' => '0.0000', 'PoNumber' => '', 'DateRequired' => null, 'DateCompleted' => null,
    'ShipVia' => null, 'ShipViaName' => null, 'ShipToName' => 'Company 12', 'BillToName' => 'Company 12'];
foreach ([
    'no orders chosen'    => [null, ['orders' => ' '], 'at least one order'],
    'not a number'        => [null, ['orders' => '19x'], 'not an order number'],
    'too many orders'     => [null, ['orders' => '1,2,3,4,5,6,7'], 'Too many orders'],
    'unknown order'       => [null, ['orders' => '5'], 'not found'],
    'order on hold'       => [static fn ($c) => $c->when('FROM CMS.dbo.Ordr o LEFT JOIN', static fn (array $p) => [$orderRow($p[0], ['Status' => 'CRH'])]), null, 'cannot be shipped'],
    'two customers'       => [static fn ($c) => $c->when('FROM CMS.dbo.Ordr o LEFT JOIN', static fn (array $p) => [$orderRow($p[0], ['ShipTo' => $p[0]])]), null, 'different customer'],
    'nothing assembled'   => [static fn ($c) => $c->when('/* assembled */', []), null, 'Nothing is assembled'],
    'no ship-to address'  => [static fn ($c) => $c->when("FROM CMS.dbo.AddressRef a WHERE a.TableName = 'Ordr'", []), null, 'no ship-to address'],
    'delivery confirmation with a freight account' => [static fn ($c) => $c->when('/* freight */', [['ShipVia' => 100738, 'FreightService' => null,
        'FreightOption' => null, 'FreightAccount' => 'A-100', 'PoNumber' => 'PO-1', 'DeliveryConfirmation' => 1]]), null, 'delivery confirmation and a freight account'],
    'delivery confirmation with a freight service' => [static fn ($c) => $c->when('/* freight */', [['ShipVia' => 100738, 'FreightService' => 'GND',
        'FreightOption' => null, 'FreightAccount' => null, 'PoNumber' => 'PO-1', 'DeliveryConfirmation' => 1]]), null, 'Ship it in CMS'],
    'bad weight'          => [null, ['weights' => [118880 => 'heavy']], 'not a valid number'],
    'bad freight'         => [null, ['freight' => [194349 => '-5']], 'not a valid number'],
] as $name => [$tweak, $input, $needle]) {
    [$cms, $fake, $ids, $now] = $world($tweak);
    $input = ($input ?? []) + ['orders' => $ids];
    $shipRefused(fn () => $shipWriter($cms, $fake)->preview('ship.create_waybill', $input, $now), $needle, $name);
    t_same(0, count($fake->sent), "{$name}: nothing sent");
}
[$cms, $fake, $ids, $now] = $world();
$shipRefused(fn () => $shipWriter($cms, $fake, ['write.ship.reject_waybill'])->preview('ship.create_waybill', ['orders' => $ids], $now), 'permission', 'shipping needs its own permission');
t_same([194349, 194277], CreateWaybill::orderNumbers("194349 194277,194349"), 'order numbers are read from a typed list, repeats dropped');

t_section('Shipping flow: a waybill that stops part way');
[$cms, $fake, $ids, $now] = $world();
$fake->willFail('Record has been changed by another user.');
$res = $shipWriter($cms, $fake)->execute('ship.create_waybill', ['orders' => $ids], $now);
t_check(!$res->ok(), 'a failed request stops the sequence');
t_same(2, count($fake->sent), 'nothing is sent after the request that failed');

[$cms, $fake, $ids, $now] = $world(static fn ($c) => $c->when('FROM CMS.dbo.WaybillInProcess p WHERE p.Waybill = ?', [
    ['WaybillInProcess' => 1, 'IsDone' => 0, 'Ordr' => 5, 'Action' => 'CreateShipmentsByOrder', 'FreightCharge' => null]]));
$res = $shipWriter($cms, $fake)->execute('ship.create_waybill', ['orders' => $ids], $now);
t_check(!$res->ok() && count($fake->sent) === 1, 'an unexpected to-do list stops after the first request');
t_check(str_contains(json_encode($res), 'Finish it in CMS'), 'and says where to finish');

/** A waybill as ShippingState reads it. */
$waybillCms = static function (array $head, array|\Closure $todo, ?\Closure $version = null, array $containers = []): \PII\Cms\Write\FakeCmsReader {
    return (new \PII\Cms\Write\FakeCmsReader())
        ->when('FROM CMS.dbo.Waybill w LEFT JOIN', [$head + ['Waybill' => 18476, 'Version' => 4, 'Status' => 'CMP',
            'DateShipped' => '2026-10-30T11:06:18', 'Address' => 100668, 'ShipToName' => 'Company 16']])
        ->when('/* waybill shipments */', [
            ['Ordr' => 194349, 'ChangeSet' => 700001, 'Trans' => 800001, 'TransDocument' => '90001'],
            ['Ordr' => 194277, 'ChangeSet' => 700002, 'Trans' => 800002, 'TransDocument' => '90002']])
        ->when('/* waybill containers */', $containers)
        ->when('FROM CMS.dbo.WaybillInProcess p WHERE p.Waybill = ?', $todo)
        ->when('/* waybill version */', $version ?? static fn () => [['Version' => 4]]);
};
$todoRow = static fn (int $id, int $done, string $action, ?int $ordr = null): array =>
    ['WaybillInProcess' => $id, 'IsDone' => $done, 'Ordr' => $ordr, 'Action' => $action, 'FreightCharge' => null];
$todo476 = static fn (int $done): array => [
    $todoRow(55779, (int) ($done > 0), 'CreateShipmentsByOrder', 194349), $todoRow(55780, (int) ($done > 1), 'CreateShipmentsByOrder', 194277),
    $todoRow(55781, (int) ($done > 2), 'SaveShippedPallets'), $todoRow(55782, (int) ($done > 3), 'MarkCompleted')];

t_section('Shipping flow: finish a waybill (logs 299822 to 299824)');
// Waybill 3863 stopped after its first order shipped; the client finished it with these three requests.
$todo3863 = static fn (int $done): array => [
    $todoRow(11548, (int) ($done > 0), 'CreateShipmentsByOrder', 130254), $todoRow(11549, (int) ($done > 1), 'CreateShipmentsByOrder', 130255),
    $todoRow(11550, (int) ($done > 2), 'SaveShippedPallets'), $todoRow(11551, (int) ($done > 3), 'MarkCompleted')];
$fake = $shipFake();
$cms  = $waybillCms(['Waybill' => 3863, 'Status' => null, 'Version' => 1], static fn () => $todo3863(1 + count($fake->sent)),
    static fn () => [['Version' => 1 + count($fake->sent)]]);
$w    = $shipWriter($cms, $fake);
$now  = new DateTimeImmutable('2026-10-30T11:30:00');
$p    = $w->preview('ship.resume_waybill', ['waybill' => 3863], $now);
t_same(3, count($p->rendered->stages), 'the three open steps are shown');
$res  = $w->execute('ship.resume_waybill', ['waybill' => 3863], $now, $p->hash);
t_check($res->ok() && count($fake->sent) === 3, 'the three open steps were sent');
// The logged requests were made under another owner; the owner is the writer's, so it is left out of the comparison.
$noOwner = static fn (string $xml): string => (string) preg_replace('/^(<request [^>]*?) Owner="\d+"/', '$1 Owner="N"', $xml);
foreach ([299822, 299823, 299824] as $k => $log) {
    t_xml_same($noOwner($shipNorm(cms_fixture(ShippingRequests::RESUME, $log))), $noOwner($shipNorm($fake->sent[$k] ?? '')), "request " . ($k + 1) . " matches log {$log}");
}

$fake  = $shipFake();
$reads = 0;
$cms   = $waybillCms(['Status' => null, 'Version' => 2], static function () use (&$reads, $todo476): array {
    return $todo476(++$reads === 1 ? 1 : 2);   // someone else ships the second order after the plan is made
}, static fn () => [['Version' => 3 + count($fake->sent)]]);
$res = $shipWriter($cms, $fake)->execute('ship.resume_waybill', ['waybill' => 18476], $now);
t_check($res->ok() && count($fake->sent) === 2, 'a step CMS already shows as done is skipped');
t_check(str_contains($fake->sent[0], 'WaybillInProcess="55781"'), 'and the next open step is sent');

$shipRefused(fn () => $shipWriter($waybillCms([], $todo476(4)), $shipFake())->preview('ship.resume_waybill', ['waybill' => 18476], $now), 'already completed', 'a completed waybill has nothing to finish');
$shipRefused(fn () => $shipWriter($waybillCms(['Status' => null], $todo476(4)), $shipFake())->preview('ship.resume_waybill', ['waybill' => 18476], $now), 'nothing left', 'no open steps');
$shipRefused(fn () => $shipWriter($waybillCms(['Status' => null], [$todoRow(1, 0, 'SomethingNew')]), $shipFake())->preview('ship.resume_waybill', ['waybill' => 18476], $now), 'does not know', 'an unknown step is left to CMS');
$shipRefused(fn () => $shipWriter($waybillCms([], [])->when('FROM CMS.dbo.Waybill w LEFT JOIN', []), $shipFake())->preview('ship.resume_waybill', ['waybill' => 9], $now), 'not found', 'unknown waybill');

t_section('Shipping flow: reject a waybill (log 848374)');
$fake = $shipFake();
$cms  = $waybillCms(['Waybill' => 18162, 'Version' => 6], $todo476(4));
$now  = new DateTimeImmutable('2026-09-17T14:48:11');
$w    = $shipWriter($cms, $fake);
$p    = $w->preview('ship.reject_waybill', ['waybill' => 18162], $now);
t_check(str_contains(json_encode($p->plan->summary), 'invoice 90002'), 'the preview names the invoices that are reversed');
$res  = $w->execute('ship.reject_waybill', ['waybill' => 18162], $now, $p->hash);
t_check($res->ok() && count($fake->sent) === 1, 'reject is one request');
t_xml_same(cms_fixture(ShippingRequests::REJECT, 848374), preg_replace('/Application="[^"]+"/', 'Application="Chemical Management System"', $fake->sent[0]), 'reject matches log 848374, timestamp included');
$shipRefused(fn () => $shipWriter($waybillCms(['Status' => 'REJ'], []), $shipFake())->preview('ship.reject_waybill', ['waybill' => 18476], $now), 'already rejected', 'rejecting twice');
$shipRefused(fn () => $shipWriter($waybillCms(['Status' => null], []), $shipFake())->preview('ship.reject_waybill', ['waybill' => 18476], $now), 'not completed', 'an unfinished waybill is not rejected here');
$shipRefused(fn () => $shipWriter($waybillCms([], []), $shipFake(), ['write.ship.create_waybill'])->preview('ship.reject_waybill', ['waybill' => 18476], $now), 'permission', 'reject needs its own permission');

t_section('Shipping flow: record the labels printed (logs 861920, 861921)');
$boxes = static fn (?int $a, ?int $b): array => [
    ['Location' => 118880, 'LocationCode' => 'ASM1', 'LabelAddress' => $a], ['Location' => 118895, 'LocationCode' => 'ASM2', 'LabelAddress' => $b]];
$fake = $shipFake();
$now  = new DateTimeImmutable('2026-10-30T11:24:13');
$res  = $shipWriter($waybillCms([], $todo476(4), null, $boxes(null, null)), $fake)->execute('ship.label_address', ['waybill' => 18476], $now);
t_check($res->ok() && count($fake->sent) === 2, 'one request per container');
foreach ([861920, 861921] as $k => $log) {
    t_same($shipActions(cms_fixture(ShippingRequests::LABEL, $log)), $shipActions($fake->sent[$k] ?? ''), "request " . ($k + 1) . " matches log {$log}");
}
$fake = $shipFake();
$res  = $shipWriter($waybillCms([], $todo476(4), null, $boxes(100668, 55)), $fake)->execute('ship.label_address', ['waybill' => 18476], $now);
t_check(count($fake->sent) === 1 && str_contains($fake->sent[0], 'Operation="Update"><keys TableID="118895"'), 'only a container whose label differs is sent, as an update');
$shipRefused(fn () => $shipWriter($waybillCms([], $todo476(4), null, $boxes(100668, 100668)), $shipFake())->preview('ship.label_address', ['waybill' => 18476], $now), 'already recorded', 'labels already recorded');
$shipRefused(fn () => $shipWriter($waybillCms(['Status' => null], [], null, $boxes(null, null)), $shipFake())->preview('ship.label_address', ['waybill' => 18476], $now), 'not completed', 'labels wait for a completed waybill');
$shipRefused(fn () => $shipWriter($waybillCms([], [], null, []), $shipFake())->preview('ship.label_address', ['waybill' => 18476], $now), 'no containers', 'a waybill with no containers');

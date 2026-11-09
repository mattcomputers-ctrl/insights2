<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\FakeCmsReader;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Ops\MasterData\EntityRequests as ER;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

t_section('Customer golden: EntityUpdateDataSet.SaveChanges');
$enShapes = [];
$enCustomer = [ER::CMS_SHIP_TO, ER::CMS_BILL_TO];
foreach (cms_fixture_ids(ER::SAVE) as $log) {
    $xml = cms_fixture(ER::SAVE, $log);
    $f   = cms_fixture_parse($xml);
    $s   = $f['steps'];
    $ops = implode(' ', array_map(static fn (array $x): string => $x['operation'] . ' ' . $x['object'], $s));
    $create = 'Insert Entity' . str_repeat(' Insert EntityDocument', 6) . ' Insert AddressRef';
    if ($ops === $create && $f['program'] === ER::CMS_SHIP_TO) {
        $enShapes['ship-to create'] = true;
        $d = $s[0]['data'];
        $a = $s[7]['data'];
        unset($a['Residential']);
        t_xml_same($xml, ER::shipToCreate($d['EntityCode'], $d['Parent'], $d['ShipVia'], $d['FreightAccount'] ?? '', $a)->toXml($f['env']), "log {$log} (ship-to create)");
    } elseif ($ops === 'Update Entity' && in_array($f['program'], $enCustomer, true) && array_diff(array_keys($s[0]['data']), ER::SHIPPING) === []) {
        $enShapes['shipping'] = true;
        t_xml_same($xml, ER::shippingUpdate($s[0]['keys']['Entity'], $s[0]['keys']['Version'], $s[0]['data'], $f['program'])->toXml($f['env']), "log {$log} (carrier and freight account)");
    } elseif ($ops === 'UpdateVersion Entity Update AddressRef' && in_array($f['program'], $enCustomer, true)
        && $s[1]['keys']['Reference'] === 'Address' && array_diff(array_keys($s[1]['data']), ER::ADDRESS) === []) {
        $enShapes['address'] = true;
        t_xml_same($xml, ER::addressUpdate($s[0]['keys']['Entity'], $s[0]['keys']['Version'], $s[1]['data'], $f['program'])->toXml($f['env']), "log {$log} (address)");
    } else {
        // New bill-to accounts and suppliers, second addresses, memos and sales reps stay in the client.
        $enShapes['not built'] = true;
        t_check($f['program'] !== ER::CMS_SHIP_TO || str_contains($ops, 'MemoRef') || str_contains($ops, 'Insert AddressRef'),
            "log {$log}: a shape that is left to the client ({$f['program']}: {$ops})");
    }
}
ksort($enShapes);
t_same(['address', 'not built', 'ship-to create', 'shipping'], array_keys($enShapes), 'fixtures cover what is built');
t_throws(\InvalidArgumentException::class, static fn () => ER::shipToCreate('X', 1, 2, '', ['Name' => 'N', 'AddrLine1' => 'L', 'City' => 'C', 'State' => 'GA']));
t_throws(\InvalidArgumentException::class, static fn () => ER::shippingUpdate(1, 1, [], ER::CMS_SHIP_TO));
t_throws(\InvalidArgumentException::class, static fn () => ER::addressUpdate(1, 1, ['Fax' => '1'], ER::CMS_SHIP_TO));
$enNoAccount = ER::shipToCreate('X', 1, 2, '', ['Name' => 'N', 'AddrLine1' => 'L', 'City' => 'C', 'State' => 'GA', 'ZipCode' => '1'])->steps();
t_check(!array_key_exists('FreightAccount', $enNoAccount[0]->first('data')), 'a ship-to without a freight account leaves the column out');
t_same(['Name', 'AddrLine1', 'City', 'State', 'ZipCode', 'Residential'], array_keys($enNoAccount[7]->first('data')), 'blank address fields are left out');

/* ── Flows ── */

$enPerms  = ['write.customer.create_shipto', 'write.customer.edit'];
$enWriter = static fn (FakeCmsReader $cms, $transport, ?array $perms = null): \PII\Cms\Write\CmsWriter => new \PII\Cms\Write\CmsWriter(
    $cms, $transport, static fn (string $p) => in_array($p, $perms ?? $enPerms, true), 'pic\\user1', 'WS1', true, '7.15.0.680',
    static function (array $row): void {});
$enNorm = static fn (string $xml): string => preg_replace(['/Application="[^"]+"/', '/ TransDate="[^"]+"/'], ['Application="A"', ' TransDate="T"'], $xml);
$enRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle),
        "refused: {$label}" . ($e !== null ? ' [' . $e->getMessage() . ']' : ' (not refused)'));
};
/**
 * A fake CMS. Rows: [Entity, EntityCode, EntityName, Parent, Version, IsBillTo, IsShipTo, IsShipVia, Inactive,
 * ShipVia, FreightAccount, address or null].
 */
$enCms = static function (array $rows): FakeCmsReader {
    $find = static function (int $col, mixed $v) use ($rows): ?array {
        foreach ($rows as $r) {
            if (strcasecmp((string) $r[$col], (string) $v) === 0) {
                return $r;
            }
        }
        return null;
    };
    $row = static fn (?array $r): array => $r === null ? [] : [[
        'Entity' => $r[0], 'EntityCode' => $r[1], 'EntityName' => $r[2], 'Parent' => $r[3], 'Version' => $r[4], 'IsBillTo' => $r[5],
        'IsShipTo' => $r[6], 'IsShipVia' => $r[7], 'Inactive' => $r[8], 'ShipVia' => $r[9], 'FreightAccount' => $r[10]]];
    return (new FakeCmsReader())
        ->when('WHERE e.EntityCode = ? /* customer */', static fn (array $p) => $row($find(1, $p[0])))
        ->when('WHERE e.EntityCode = ? /* code in use */', static fn (array $p) => $row($find(1, $p[0])))
        ->when('WHERE e.Entity = ? /* carrier */', static fn (array $p) => $row($find(0, $p[0])))
        ->when("AND a.Reference = 'Address' /* customer */", static function (array $p) use ($find): array {
            $r = $find(0, $p[0]);
            return $r === null || $r[11] === null ? [] : [$r[11] + ['Name' => $r[2], 'AddrLine2' => null, 'AddrLine3' => null,
                'State' => 'GA', 'Country' => null, 'Email' => null, 'Phone' => null]];
        })
        ->when('FROM CMS.dbo.State s WHERE s.State = ?', static fn (array $p) => in_array($p[0], ['GA', 'ON'], true) ? [['State' => $p[0]]] : []);
};
$enRows = [
    [100893, 'COMPANY2', 'Company 2', 100893, 12, 1, 1, 0, 0, 100753, null, ['AddrLine1' => '9 Main St', 'City' => 'Anytown', 'ZipCode' => '00000']],
    [101529, 'COMPANY5', 'Company 5', 100893, 1, 0, 1, 0, 0, 100753, null, ['AddrLine1' => '9 Main St', 'City' => 'Anytown', 'ZipCode' => '00000']],
    [100896, 'COMPANY6', 'Company 6', 100893, 9, 0, 1, 0, 0, 100753, 'OLD', ['AddrLine1' => '5 Old  Rd', 'City' => 'Oldtown', 'ZipCode' => '11111', 'State' => 'ga']],
    [100281, 'COMPANY7', 'Company 7', 100281, 17, 1, 1, 0, 0, null, null, ['AddrLine1' => '9 Main St', 'City' => 'Anytown', 'ZipCode' => '00000', 'Email' => 'old@example.com', 'AddrLine2' => 'Suite 2']],
    [100300, 'COMPANY8', 'Company 8', 100300, 2, 1, 0, 0, 1, null, null, null],
    [100753, 'TRUCK', 'Truck Line', null, 1, 0, 0, 1, 0, null, null, null],
    [100752, 'PARCEL', 'Parcel Co', null, 1, 0, 0, 1, 0, null, null, null],
    [100751, 'OLDSHIP', 'Old Carrier', null, 1, 0, 0, 1, 1, null, null, null],
    [100484, 'ACME', 'Acme Pigments', null, 1, 0, 0, 0, 0, null, null, null],
];
$enNow = new DateTimeImmutable('2026-11-09T11:00:05');

t_section('Customer registry');
$enAll = \PII\Cms\Write\OperationRegistry::permissionsFor('sales_orders');
t_same('Write to CMS: add ship-to addresses to customers', $enAll['write.customer.create_shipto'] ?? null, 'adding a ship-to has its own permission');
t_same('Write to CMS: change the address, carrier and freight account of customers', $enAll['write.customer.edit'] ?? null, 'one permission for the two changes');

t_section('Customer flow: new ship-to');
$fake = new FakeTransport();
$wr   = $enWriter($enCms($enRows), $fake);
$enNew = ['parent' => 'company2', 'code' => ' company3 ', 'name' => 'Company 4', 'line1' => '1 Example St', 'line2' => '2  Example St',
    'city' => 'Anytown', 'state' => 'ga', 'zip' => '00000', 'email' => '', 'phone' => '555-0100', 'ship_via' => '100753', 'freight_account' => 'ACCT'];
$p = $wr->preview('customer.shipto_create', $enNew, $enNow);
t_check(str_contains(json_encode($p->plan->summary), 'Truck Line on freight account ACCT'), 'the review names the carrier and the account');
t_check($wr->execute('customer.shipto_create', $enNew, $enNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($enNorm(cms_fixture(ER::SAVE, 864934)), $enNorm($fake->sent[0] ?? ''), 'the request is the one in log 864934');
foreach ([
    'no bill-to'         => [['parent' => ''], 'Choose the customer'],
    'unknown bill-to'    => [['parent' => 'NOPE'], 'was not found'],
    'a supplier'         => [['parent' => 'ACME'], 'was not found'],
    'under a ship-to'    => [['parent' => 'COMPANY5'], 'is a ship-to itself'],
    'inactive bill-to'   => [['parent' => 'COMPANY8'], 'inactive'],
    'no code'            => [['code' => ' '], 'Enter a code'],
    'code in use'        => [['code' => 'company5'], 'already used'],
    'code of a supplier' => [['code' => 'ACME'], 'already used'],
    'code too long'      => [['code' => str_repeat('A', 21)], 'too long'],
    'no name'            => [['name' => ''], 'Enter the name'],
    'no address'         => [['line1' => ''], 'Enter the address line 1'],
    'no city'            => [['city' => ''], 'Enter the city'],
    'no state'           => [['state' => ''], 'Enter the state'],
    'unknown state'      => [['state' => 'ZZ'], 'no state or province'],
    'no zip'             => [['zip' => ''], 'Enter the zip code'],
    'bad email'          => [['email' => 'nobody'], 'does not look right'],
    'no carrier'         => [['ship_via' => ''], 'Choose the carrier'],
    'not a carrier'      => [['ship_via' => '100484'], 'was not found'],
    'inactive carrier'   => [['ship_via' => '100751'], 'inactive'],
    'long account'       => [['freight_account' => str_repeat('9', 21)], 'too long'],
] as $label => [$over, $needle]) {
    $enRefused(static fn () => $wr->preview('customer.shipto_create', $over + $enNew, $enNow), $needle, $label);
}
t_check(!$enWriter($enCms($enRows), $fake, ['write.customer.edit'])->allowed('customer.shipto_create'), 'needs the ship-to permission');

t_section('Customer flow: carrier and freight account');
$fake = new FakeTransport();
$wr   = $enWriter($enCms($enRows), $fake);
$in   = ['customer' => 'COMPANY5', 'ship_via' => '100752', 'freight_account' => 'ACCT'];
$p    = $wr->preview('customer.shipping', $in, $enNow);
t_check($wr->execute('customer.shipping', $in, $enNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($enNorm(cms_fixture(ER::SAVE, 864695)), $enNorm($fake->sent[0] ?? ''), 'the request is the one in log 864695');
$p  = $wr->preview('customer.shipping', ['customer' => 'COMPANY6', 'ship_via' => '100753', 'freight_account' => ''], $enNow);
$st = cms_fixture_parse($p->rendered->stages[0]['xml']);
t_same([['FreightAccount' => ''], ER::CMS_SHIP_TO], [$st['steps'][0]['data'], $st['program']], 'removing the account sends it empty, and only it');
$p  = $wr->preview('customer.shipping', ['customer' => 'COMPANY7', 'ship_via' => '100752', 'freight_account' => ''], $enNow);
$st = cms_fixture_parse($p->rendered->stages[0]['xml']);
t_same([['ShipVia' => '100752'], ER::CMS_BILL_TO], [$st['steps'][0]['data'], $st['program']], 'a bill-to account is changed as its own screen does');
foreach ([
    'unchanged'      => [[], 'Nothing was changed'],
    'not shipped to' => [['customer' => 'COMPANY8'], 'not shipped to'],
    'no carrier'     => [['ship_via' => '0'], 'Choose the carrier'],
] as $label => [$over, $needle]) {
    $enRefused(static fn () => $wr->preview('customer.shipping', $over + ['customer' => 'COMPANY6', 'ship_via' => '100753', 'freight_account' => 'OLD'], $enNow), $needle, $label);
}

t_section('Customer flow: address');
$fake = new FakeTransport();
$wr   = $enWriter($enCms($enRows), $fake);
$in   = ['customer' => 'COMPANY6', 'name' => 'Company 6', 'line1' => '1 Example St', 'line2' => '', 'city' => 'Anytown', 'state' => 'GA',
    'zip' => '00000', 'email' => '', 'phone' => ''];
$p    = $wr->preview('customer.address', $in, $enNow);
t_check(str_contains(json_encode($p->plan->summary), '1 Example St (was 5 Old  Rd)'), 'the review shows the new and the old line');
t_check($wr->execute('customer.address', $in, $enNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($enNorm(cms_fixture(ER::SAVE, 866298)), $enNorm($fake->sent[0] ?? ''), 'only the changed fields are sent, as in log 866298');
$enSeven = ['customer' => 'COMPANY7', 'name' => 'Company 7', 'line1' => '9 Main St', 'line2' => 'Suite 2', 'city' => 'Anytown', 'state' => 'GA',
    'zip' => '00000', 'email' => 'a@example.com', 'phone' => ''];
$p = $wr->preview('customer.address', $enSeven, $enNow);
t_check($wr->execute('customer.address', $enSeven, $enNow, $p->hash)->ok(), 'committed');
t_xml_same($enNorm(cms_fixture(ER::SAVE, 860665)), $enNorm($fake->sent[1] ?? ''), 'a bill-to email change is the one in log 860665');
$p  = $wr->preview('customer.address', ['line2' => '', 'email' => ''] + $enSeven, $enNow);
t_same(['AddrLine2' => '', 'Email' => ''], cms_fixture_parse($p->rendered->stages[0]['xml'])['steps'][1]['data'], 'removing a second line and an email sends them empty');
foreach ([
    'unchanged'       => [['email' => 'old@example.com'], 'Nothing was changed'],
    'name emptied'    => [['name' => ''], 'Name cannot be emptied'],
    'city emptied'    => [['city' => ' '], 'City cannot be emptied'],
    'unknown state'   => [['state' => 'QQ'], 'no state or province'],
    'no address yet'  => [['customer' => 'COMPANY8'], 'has no address'],
    'not a customer'  => [['customer' => 'TRUCK'], 'was not found'],
] as $label => [$over, $needle]) {
    $enRefused(static fn () => $wr->preview('customer.address', $over + $enSeven, $enNow), $needle, $label);
}
$enRefused(static fn () => $wr->preview('customer.address', ['line1' => '5 Old Rd', 'city' => 'Oldtown', 'zip' => '11111'] + $in, $enNow),
    'Nothing was changed', 'stray spaces and letter case alone are not a change');
t_check(!$enWriter($enCms($enRows), $fake, ['write.customer.create_shipto'])->allowed('customer.address'), 'needs the edit permission');

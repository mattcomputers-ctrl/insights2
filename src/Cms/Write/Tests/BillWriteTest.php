<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\FakeCmsReader;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Ops\Purchasing\BillRequests as BR;
use PII\Cms\Write\Ops\Purchasing\BillState;

/* ── Golden: the template reproduces the logged requests byte for byte ── */

t_section('Bill golden: BillUpdateDataSet.SaveChanges');
$blShapes = [];
foreach (cms_fixture_ids(BR::SAVE) as $log) {
    $xml = cms_fixture(BR::SAVE, $log);
    $f   = cms_fixture_parse($xml);
    $s   = $f['steps'];
    if ($s[0]['operation'] === 'Insert' && !str_contains($xml, '<event')) {
        $blShapes['create'] = true;
        $d = $s[0]['data'];
        $lines = [];
        foreach (array_slice($s, 1, -1) as $x) {
            $lines[] = ['billDetail' => $x['keys']['BillDetail'], 'amount' => $x['data']['Amount'] ?? null, 'addCost' => $x['data']['AddCost'] ?? null];
        }
        t_xml_same($xml, BR::create(['supplier' => $d['Supplier'], 'invoice' => $d['Invoice'], 'invoiceDate' => substr($d['InvoiceDate'], 0, 10),
            'terms' => $d['Terms'], 'amount' => $d['Amount'], 'currency' => $d['Currency']], $lines)->toXml($f['env']), "log {$log} (new bill)");
    } else {
        // Bills that need a signature, changed bills and deleted bills stay in the client.
        $blShapes['not built'] = true;
        t_check(str_contains($xml, '<event') || in_array($s[0]['operation'], ['Update', 'Delete'], true), "log {$log}: a shape that is left to the client");
    }
}
ksort($blShapes);
t_same(['create', 'not built'], array_keys($blShapes), 'fixtures cover what is built');
$blHead = ['supplier' => 7, 'invoice' => 'A1', 'invoiceDate' => '2026-11-02', 'terms' => 'Net 30', 'amount' => '5', 'currency' => 'USD'];
t_throws(\InvalidArgumentException::class, static fn () => BR::create($blHead, []));
t_throws(\InvalidArgumentException::class, static fn () => BR::create(['terms' => ''] + $blHead, [['billDetail' => 1]]));
t_throws(\InvalidArgumentException::class, static fn () => BR::create(['invoiceDate' => '11/2/2026'] + $blHead, [['billDetail' => 1]]));
t_throws(\InvalidArgumentException::class, static fn () => BR::create($blHead, [['billDetail' => 1, 'amount' => '5']]));

t_section('Bill money');
t_same(34742920, BillState::units('3474.2920'), 'four decimals read');
t_same(-700, BillState::units('-0.07'), 'a negative amount');
t_same(12616500, BillState::units('1,261.65', 2), 'thousands separators are dropped');
t_same(750000, BillState::units('75', 2), 'a whole amount');
t_same(5000, BillState::units('.5', 2), 'no leading zero');
t_same(null, BillState::units('1.234', 2), 'more decimals than allowed');
t_same(null, BillState::units('abc'), 'not a number');
t_same(null, BillState::units('.'), 'a lone point');
t_same(null, BillState::units(''), 'blank');
t_same(34742900, BillState::cents(34742920), 'rounds down to cents');
t_same(23147300, BillState::cents(23147250), 'a half rounds up');
t_same(-23147300, BillState::cents(-23147250), 'a negative half rounds away from zero');
t_same('1323', BillState::text(13230000), 'whole amounts have no decimals');
t_same('1261.65', BillState::text(12616500), 'cents as they are');
t_same('-0.07', BillState::text(-700), 'a negative amount as the client writes it');
t_same('4680.1', BillState::text(46801000), 'spare zeros are dropped');
t_same('1,323.00', BillState::show(13230000), 'shown with two decimals');
t_same('3,474.292', BillState::show(34742920), 'shown with more when used');
t_same('-0.07', BillState::show(-700), 'shown negative');

/* ── Flow ── */

$blPerms  = ['write.bill.create'];
$blWriter = static fn (FakeCmsReader $cms, $transport, ?array $perms = null): \PII\Cms\Write\CmsWriter => new \PII\Cms\Write\CmsWriter(
    $cms, $transport, static fn (string $p) => in_array($p, $perms ?? $blPerms, true), 'pic\\user1', 'WS1', true, '7.15.0.680',
    static function (array $row): void {});
$blNorm = static fn (string $xml): string => preg_replace(['/Application="[^"]+"/', '/ TransDate="[^"]+"/'], ['Application="A"', ' TransDate="T"'], $xml);
$blRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle),
        "refused: {$label}" . ($e !== null ? ' [' . $e->getMessage() . ']' : ' (not refused)'));
};
/**
 * A fake CMS.
 * Suppliers: [Entity, EntityCode, EntityName, Context, Inactive, Currency, Terms].
 * Waiting:   [BillDetail, Supplier, Ordr, Receipt, Item, PackingSlip, Amount, order Terms, LandingFactor, Pending].
 * Bills:     [Supplier, Invoice].
 */
$blCms = static function (array $suppliers, array $waiting, array $bills): FakeCmsReader {
    return (new FakeCmsReader())
        ->when('WHERE e.Entity = ?', static fn (array $p) => array_values(array_map(
            static fn (array $s): array => ['Entity' => $s[0], 'EntityCode' => $s[1], 'EntityName' => $s[2], 'Context' => $s[3], 'Inactive' => $s[4],
                'Currency' => $s[5], 'Terms' => $s[6], 'Incoterms' => null, 'ShipVia' => null],
            array_filter($suppliers, static fn (array $s): bool => $s[0] === (int) $p[0]))))
        ->when('FROM CMS.dbo.MemoRef', [])
        ->when('FROM CMS.dbo.AddressRef', [])
        ->when('/* unbilled receipts */', static fn (array $p) => array_values(array_map(
            static fn (array $w): array => ['BillDetail' => $w[0], 'Supplier' => $w[1], 'SupplierCode' => 'CODE', 'EntityName' => 'Name', 'Ordr' => $w[2],
                'Receipt' => $w[3], 'DateReceived' => '2026-10-30 09:15:00', 'Item' => $w[4], 'Description' => 'Thing', 'PackingSlip' => $w[5],
                'Qty' => '4.4000000000000000e+001', 'Unit' => 'lb', 'Amount' => $w[6], 'Currency' => 'USD', 'LandingFactor' => $w[8], 'Pending' => $w[9],
                'Terms' => $w[7]],
            array_filter($waiting, static fn (array $w): bool => $p === [] || $w[1] === (int) $p[0]))))
        ->when('/* bill by invoice */', static fn (array $p) => array_values(array_map(static fn (array $b): array => ['Bill' => 1],
            array_filter($bills, static fn (array $b): bool => $b[0] === (int) $p[0] && strcasecmp($b[1], (string) $p[1]) === 0))));
};
$blSuppliers = [
    [100437, 'ACME', 'Acme Pigments', 'SP', 0, 'USD', 'Net 45'],
    [100360, 'FREIGHTCO', 'Freight Co', 'SP', 0, 'USD', 'Net 30'],
    [100486, 'MAPLE', 'Maple Resins', 'SP', 0, 'CAD', 'Net 30'],
    [100487, 'GONE', 'Gone Co', 'SP', 1, 'USD', 'Net 30'],
    [100893, 'COMPANY2', 'Company 2', 'BT,ST', 0, 'USD', 'Net 30'],
    [100488, 'NOTERMS', 'No Terms Co', 'SP', 0, 'USD', null],
];
$blWaiting = [
    [10421, 100437, 194000, 138404, 'PIG-BLUE', '455', '4680.0661', 'Net 30', 'Inventory', 0],
    [10422, 100437, 194000, 138405, 'PIG-RED', '455', '3692.0412', 'Net 30', 'Inventory', 0],
    [10423, 100437, 194001, 138406, 'PIG-GREEN', '', '100.0000', 'Net 60', 'Inventory', 0],
    [10424, 100437, 194001, 138407, 'PIG-ODD', '', '10.0000', 'Net 30', 'Freight', 0],
    [10425, 100437, 194001, 138408, 'PIG-WAIT', '', '10.0000', 'Net 30', 'Inventory', 1],
    [10431, 100360, 194002, 138428, 'RESIN-1', '9', '1186.6500', 'Net 30', 'Inventory', 0],
    [10440, 100487, 194003, 138500, 'RESIN-2', '', '50.0000', 'Net 30', 'Inventory', 0],
    [10441, 100488, 194004, 138501, 'RESIN-3', '', '50.0000', '', 'Inventory', 0],
    [10442, 100486, 194005, 138502, 'RESIN-4', '', '50.0000', 'Net 30', 'Inventory', 0],
];
$blBills = [[100437, 'DUP-1']];
$blNow   = new \DateTimeImmutable('2026-11-02 12:37:49');

t_section('Bill state');
$st = BillState::unbilled($blCms($blSuppliers, $blWaiting, $blBills));
t_same(9, count($st), 'every waiting line is read');
t_same(['billDetail' => 10421, 'supplier' => 100437, 'supplierCode' => 'CODE', 'supplierName' => 'Name', 'ordr' => 194000, 'receipt' => 138404,
    'received' => '2026-10-30', 'item' => 'PIG-BLUE', 'description' => 'Thing', 'packingSlip' => '455', 'qty' => 44.0, 'unit' => 'lb',
    'amount' => 46800661, 'currency' => 'USD', 'landingFactor' => 'Inventory', 'pending' => false, 'terms' => 'Net 30'], $st[0], 'a waiting line');
t_same(1, count(BillState::unbilled($blCms($blSuppliers, $blWaiting, $blBills), 100360)), 'one supplier on its own');
t_check(BillState::invoiceExists($blCms($blSuppliers, $blWaiting, $blBills), 100437, 'dup-1'), 'an invoice number already used');
t_check(!BillState::invoiceExists($blCms($blSuppliers, $blWaiting, $blBills), 100360, 'DUP-1'), 'the same number at another supplier is free');

t_section('Bill flow: enter a bill');
$fake = new FakeTransport();
$wr   = $blWriter($blCms($blSuppliers, $blWaiting, $blBills), $fake);
$blIn = ['supplier' => '100437', 'invoice' => ' 455745 ', 'invoice_date' => '2026-10-28', 'total' => '8,372.00',
    'lines' => ['10422', '10421'], 'add_cost' => ['10421' => '-0.07', '10422' => '-.04']];
$p = $wr->preview('bill.create', $blIn, $blNow);
$blSummary = json_encode($p->plan->summary);
t_check(str_contains($blSummary, 'Acme Pigments (ACME)'), 'the review names the supplier');
t_check(str_contains($blSummary, '455745, dated 2026-10-28'), 'the review shows the invoice');
t_check(str_contains($blSummary, 'PIG-BLUE on PO 194000, packing slip 455, received 2026-10-30: 4,680.07 and -0.07 extra cost'), 'the review lists a line');
t_check(str_contains($blSummary, '8,372.00 USD'), 'the review shows the total');
t_check($wr->execute('bill.create', $blIn, $blNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($blNorm(cms_fixture(BR::SAVE, 862429)), $blNorm($fake->sent[0] ?? ''), 'the request is the one in log 862429');

$fake = new FakeTransport();
$wr   = $blWriter($blCms($blSuppliers, $blWaiting, $blBills), $fake);
$blFr = ['supplier' => 100360, 'invoice' => '343425', 'invoice_date' => '2026-10-29', 'total' => '1261.65', 'lines' => [10431], 'add_cost' => [10431 => '75']];
$p = $wr->preview('bill.create', $blFr, new \DateTimeImmutable('2026-11-02 15:56:37'));
t_check($wr->execute('bill.create', $blFr, new \DateTimeImmutable('2026-11-02 15:56:37'), $p->hash)->ok(), 'a bill with freight is committed');
t_xml_same($blNorm(cms_fixture(BR::SAVE, 863069)), $blNorm($fake->sent[0] ?? ''), 'the request is the one in log 863069');

$wr = $blWriter($blCms($blSuppliers, $blWaiting, $blBills), new FakeTransport());
$p  = $wr->preview('bill.create', ['total' => '4680.07', 'lines' => ['10421'], 'add_cost' => ['10421' => '0']] + $blIn, $blNow);
t_check(str_contains($p->rendered->stages[0]['xml'], 'Terms="Net 30" Amount="4680.07"'), 'the bill takes the terms of the purchase order and the rounded amount');
t_check(str_contains($p->rendered->stages[0]['xml'], '<keys BillDetail="10421"/><subs FromStep="1" FromTable="Bill" FromKey="Bill" Section="data"/></action>'),
    'a line without an extra cost carries no values, even when its amount has part cents');
t_same(1, count($p->plan->warnings), 'only the stock cost note');
$p = $wr->preview('bill.create', ['total' => '3792.04', 'lines' => ['10422', '10423'], 'add_cost' => []] + $blIn, $blNow);
t_check(str_contains(implode(' ', $p->plan->warnings), 'different payment terms'), 'mixed terms are pointed out');
t_check(str_contains($p->rendered->stages[0]['xml'], 'Terms="Net 30"'), 'the first line decides the terms');
$p = $wr->preview('bill.create', ['invoice_date' => '2026-10-12'] + $blIn, $blNow);
t_check(str_contains($p->rendered->stages[0]['xml'], 'InvoiceDate="2026-10-12T00:00:00"'), 'an invoice dated exactly 21 days back is taken');
$p = $wr->preview('bill.create', ['supplier' => 100487, 'total' => '50', 'lines' => ['10440'], 'add_cost' => []] + $blIn, $blNow);
t_check(str_contains(implode(' ', $p->plan->warnings), 'inactive'), 'an inactive supplier can still be billed, with a note');

foreach ([
    'no supplier'        => [['supplier' => ''], 'Choose the supplier'],
    'unknown supplier'   => [['supplier' => '999'], 'was not found'],
    'not a supplier'     => [['supplier' => '100893'], 'was not found'],
    'other currency'     => [['supplier' => '100486', 'lines' => ['10442']], 'is paid in CAD'],
    'no invoice'         => [['invoice' => ' '], 'invoice number'],
    'invoice too long'   => [['invoice' => str_repeat('9', 21)], 'too long'],
    'no date'            => [['invoice_date' => ''], 'Enter the date'],
    'bad date'           => [['invoice_date' => '2026-02-30'], 'not a valid date'],
    'future date'        => [['invoice_date' => '2026-11-03'], 'in the future'],
    'old date'           => [['invoice_date' => '2026-10-11'], 'needs a signature'],
    'no lines'           => [['lines' => []], 'Tick the received lines'],
    'bad line'           => [['lines' => ['x']], 'not valid'],
    'line of another'    => [['lines' => ['10431']], 'is not waiting for a bill'],
    'unknown line'       => [['lines' => ['1']], 'is not waiting for a bill'],
    'not a stock line'   => [['lines' => ['10424']], 'not an ordinary stock receipt'],
    'pending line'       => [['lines' => ['10425']], 'marked pending'],
    'bad extra cost'     => [['add_cost' => ['10421' => '1.234']], 'dollars and cents'],
    'no total'           => [['total' => ''], 'Enter the total'],
    'total with mills'   => [['total' => '8372.001'], 'Enter the total'],
    'total differs'      => [['total' => '8372.11'], 'The invoice is 0.11 more'],
    'total short'        => [['add_cost' => []], 'The invoice is 0.11 less'],
    'invoice used'       => [['invoice' => 'dup-1'], 'already has a bill for invoice'],
    'no terms anywhere'  => [['supplier' => '100488', 'lines' => ['10441'], 'total' => '50', 'add_cost' => []], 'payment terms'],
] as $label => [$over, $needle]) {
    $blRefused(static fn () => $wr->preview('bill.create', $over + $blIn, $blNow), $needle, $label);
}
$blRefused(static fn () => $blWriter($blCms($blSuppliers, $blWaiting, $blBills), new FakeTransport(), [])->preview('bill.create', $blIn, $blNow),
    '', 'without the permission');

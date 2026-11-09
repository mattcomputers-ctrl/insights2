<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\FakeCmsReader;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Ops\Shipping\InvoiceRequests as IR;
use PII\Cms\Write\Ops\Shipping\InvoiceState;

/* ── Golden: the template reproduces the logged requests byte for byte ── */

t_section('Invoice golden: InvoiceDataSet.SaveChanges');
$ivCount = 0;
foreach (cms_fixture_ids(IR::SAVE) as $log) {
    $xml = cms_fixture(IR::SAVE, $log);
    $f   = cms_fixture_parse($xml);
    $s   = $f['steps'];
    $d   = $s[1]['data'];
    $n   = intdiv(count($s) - 8, 2);
    $lines = [];
    foreach (array_slice($s, 2 + $n + 1, $n) as $x) {
        $lines[] = ['ordDetail' => $x['data']['OrdDetail'], 'item' => $x['data']['Item'], 'price' => $x['data']['Price'],
                    'qty' => $x['data']['Qty'], 'unit' => $x['data']['Unit']];
    }
    $newHead = $s[2 + $n]['data'];
    $memo    = $s[3 + 2 * $n];
    $set     = $s[5 + 2 * $n];
    t_xml_same($xml, IR::reissue([
        'trans' => $s[0]['keys']['Trans'], 'version' => $s[0]['keys']['Version'], 'owner' => $d['Owner'],
        'transDate' => substr($d['TransDate'], 0, 10), 'salesman' => $d['Salesman'] ?? null, 'document' => $d['TransDocument'],
        'documentDate' => $d['DocumentDate'], 'ordr' => $d['Ordr'], 'entity' => $d['Entity'], 'billTo' => $d['BillTo'],
        'currency' => $d['Currency'], 'freight' => isset($d['FreightCharge']) ? ltrim($d['FreightCharge'], '-') : null, 'poNumber' => $d['PoNumber'],
    ], $lines, $newHead['FreightCharge'] ?? null, ['memo' => $memo['data']['Memo'], 'data' => $memo['data']['Data']],
        ['changeSet' => $set['keys']['ChangeSet'], 'version' => $set['keys']['Version']])->toXml($f['env']), "log {$log} (invoice re-issued)");
    $ivCount++;
}
t_check($ivCount >= 4, 'fixtures cover an invoice with and without a freight charge');
$ivHead = ['trans' => 5, 'version' => 1, 'owner' => '4', 'transDate' => '2026-11-06', 'salesman' => null, 'document' => 'N1',
    'documentDate' => '2026-11-06T08:15:21', 'ordr' => 9, 'entity' => 8, 'billTo' => 8, 'currency' => 'USD', 'freight' => null, 'poNumber' => ''];
$ivLine = ['ordDetail' => 1, 'item' => 2, 'price' => '1.0000', 'qty' => '5', 'unit' => 'lb'];
$ivXml  = IR::reissue($ivHead, [$ivLine, ['qty' => '98.8'] + $ivLine], '12.5', ['memo' => 276, 'data' => ''], ['changeSet' => 3, 'version' => 2])->toXml(cms_fixture_parse(cms_fixture(IR::SAVE, 865454))['env']);
t_check(!str_contains($ivXml, 'Salesman='), 'no sales rep is sent when the invoice has none');
t_check(str_contains($ivXml, 'Currency="USD" PoNumber="" ReversedTrans="5"'), 'a blank PO number is still sent');
t_check(substr_count($ivXml, 'Object="TransDetail"') === 4 && str_contains($ivXml, 'Qty="-98.8"') && str_contains($ivXml, 'Qty="98.8"'), 'every line is reversed and issued again');
t_check(str_contains($ivXml, 'Step="5" Operation="Insert"><keys Trans="@@identity" Version="1"/>') && str_contains($ivXml, '<subs FromStep="5" FromTable="Trans" FromKey="Trans" Section="keys" ToName="TableID"/>'),
    'the note hangs off the re-issued invoice');
t_throws(\InvalidArgumentException::class, static fn () => IR::reissue($ivHead, [], '1', ['memo' => 1, 'data' => ''], ['changeSet' => 3, 'version' => 2]));
t_throws(\InvalidArgumentException::class, static fn () => IR::reissue(['freight' => '12.5'] + $ivHead, [$ivLine], '1', ['memo' => 1, 'data' => ''], ['changeSet' => 3, 'version' => 2]));
t_throws(\InvalidArgumentException::class, static fn () => IR::reissue(['documentDate' => '2026-11-06'] + $ivHead, [$ivLine], '1', ['memo' => 1, 'data' => ''], ['changeSet' => 3, 'version' => 2]));
t_throws(\InvalidArgumentException::class, static fn () => IR::reissue($ivHead, [['qty' => '-5'] + $ivLine], '1', ['memo' => 1, 'data' => ''], ['changeSet' => 3, 'version' => 2]));
t_throws(\InvalidArgumentException::class, static fn () => IR::reissue(['poNumber' => null] + $ivHead, [$ivLine], '1', ['memo' => 1, 'data' => ''], ['changeSet' => 3, 'version' => 2]));

/* ── Flow ── */

$ivPerms  = ['write.invoice.freight'];
$ivWriter = static fn (FakeCmsReader $cms, $transport, ?array $perms = null): \PII\Cms\Write\CmsWriter => new \PII\Cms\Write\CmsWriter(
    $cms, $transport, static fn (string $p) => in_array($p, $perms ?? $ivPerms, true), 'pic\\user1', 'WS1', true, '7.15.0.680',
    static function (array $row): void {});
$ivNorm = static fn (string $xml): string => preg_replace(['/Application="[^"]+"/', '/ TransDate="[^"T]+T[0-9:]+">/'], ['Application="A"', ' TransDate="T">'], $xml);
$ivRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle),
        "refused: {$label}" . ($e !== null ? ' [' . $e->getMessage() . ']' : ' (not refused)'));
};
/**
 * A fake CMS.
 * Invoices: Trans => head overrides. Lines: Trans => list of line overrides.
 * Memos: Trans => list of [Memo, Data]. Sets: Trans => list of [ChangeSet, Version, Context].
 */
$ivCms = static function (array $heads, array $lines, array $memos, array $sets): FakeCmsReader {
    $head = ['Version' => 1, 'Owner' => '4', 'TransDate' => '2026-11-06', 'DocumentDate' => '2026-11-06T08:15:21', 'Salesman' => 101048,
        'TransDocument' => 'N00133714', 'Ordr' => 194073, 'Entity' => 101288, 'BillTo' => 101288, 'Currency' => 'USD', 'FreightCharge' => null,
        'Tax1Group' => null, 'Tax2Group' => null, 'Tax3Group' => null, 'PoNumber' => 'PO101492', 'ReversedTrans' => null, 'Division' => null,
        'CurrencyRate' => '1.0000000000000000e+000', 'Reversals' => 0, 'EntityName' => 'Northfield Print'];
    $line = ['Context' => 'SH', 'OrdDetail' => 561278, 'Item' => 116170, 'ItemCode' => 'INK-1', 'Price' => '20.4000', 'Qty' => '6.0000000000000000e+002',
        'Unit' => 'lb', 'QtyEntered' => null, 'ItemQtyPerQty' => '1.0000000000000000e+000', 'Taxed' => 0, 'Commented' => 0];
    return (new FakeCmsReader())
        ->when('/* invoice head */', static fn (array $p) => isset($heads[(int) $p[0]]) ? [$heads[(int) $p[0]] + ['Trans' => (int) $p[0]] + $head] : [])
        ->when('/* invoice lines */', static fn (array $p) => array_map(static fn (array $l): array => $l + $line, $lines[(int) $p[0]] ?? []))
        ->when('/* invoice memo */', static fn (array $p) => array_map(static fn (array $m): array => ['Memo' => $m[0], 'Data' => $m[1]], $memos[(int) $p[0]] ?? []))
        ->when('/* invoice shipment */', static fn (array $p) => array_map(
            static fn (array $c): array => ['ChangeSet' => $c[0], 'Version' => $c[1], 'Context' => $c[2]], $sets[(int) $p[0]] ?? []));
};
$ivHeads = [
    24104 => [],
    23258 => ['TransDate' => '2026-08-21', 'DocumentDate' => '2026-08-21T12:36:18', 'TransDocument' => 'N00133118', 'Ordr' => 190802,
              'Entity' => 101082, 'BillTo' => 101082, 'FreightCharge' => '338.2600', 'PoNumber' => '4415'],
    30001 => ['ReversedTrans' => 24104],
    30002 => ['Reversals' => 1],
    30003 => ['Currency' => 'CAD'],
    30004 => ['Tax1Group' => 3],
    30005 => ['PoNumber' => null],
    30006 => [],
    30007 => [],
    30008 => [],
    30009 => [],
    30010 => [],
    30011 => ['Salesman' => null, 'PoNumber' => '', 'FreightCharge' => '0.0000'],
    30012 => [],
    30013 => ['CurrencyRate' => '1.3500000000000001e+000'],
];
$ivOne   = [[]];
$ivLines = [24104 => $ivOne, 23258 => [['OrdDetail' => 539006, 'Item' => 115645, 'Price' => '7.5900', 'Qty' => '3.1060000000000000e+003']],
    30001 => $ivOne, 30002 => $ivOne, 30003 => $ivOne, 30004 => $ivOne, 30005 => $ivOne,
    30006 => [[], ['OrdDetail' => null]], 30007 => [['QtyEntered' => '1.0000000000000000e+000']], 30008 => $ivOne, 30009 => $ivOne, 30010 => [],
    30011 => [[], ['OrdDetail' => 561279, 'Qty' => '9.8799999999999997e+001']], 30012 => [['Taxed' => 1]], 30013 => $ivOne];
$ivMemos = [];
$ivSets  = [];
foreach (array_keys($ivHeads) as $t) {
    $ivMemos[$t] = [[276, null]];
    $ivSets[$t]  = [[138909, 1, 'SH']];
}
$ivSets[23258]  = [[134078, 2, 'SH']];
$ivMemos[30008] = [];
$ivSets[30009]  = [[1, 1, 'SH'], [2, 1, 'SH']];
$ivMemos[30011] = [[1509, "Line one\r\nLine two"]];
$ivNow = new \DateTimeImmutable('2026-11-06 08:17:39');
$ivAll = static fn (): FakeCmsReader => $ivCms($ivHeads, $ivLines, $ivMemos, $ivSets);

t_section('Invoice state');
$st = InvoiceState::invoice($ivAll(), 24104);
t_same(['context' => 'SH', 'ordDetail' => 561278, 'item' => 116170, 'itemCode' => 'INK-1', 'price' => '20.4000', 'qty' => 600.0, 'unit' => 'lb', 'plain' => true],
    $st['lines'][0], 'an invoice line');
t_same([24104, 1, '2026-11-06', '2026-11-06T08:15:21', 'N00133714', null, 'PO101492', false, false],
    [$st['trans'], $st['version'], $st['transDate'], $st['documentDate'], $st['document'], $st['freight'], $st['poNumber'], $st['reversal'], $st['reversed']], 'the invoice header');
t_same([['memo' => 276, 'data' => '']], $st['memos'], 'the invoice note, blank when it has no text of its own');
t_same([['changeSet' => 138909, 'version' => 1, 'context' => 'SH']], $st['shipments'], 'the shipment record');
t_same(null, InvoiceState::blocker($st), 'a plain invoice can be corrected');
t_same(null, InvoiceState::invoice($ivAll(), 1), 'an unknown invoice');
t_same('98.8', InvoiceState::qty(98.799999999999997), 'a quantity as the client prints it');

t_section('Invoice flow: set the freight');
$fake = new FakeTransport();
$wr   = $ivWriter($ivAll(), $fake);
$ivIn = ['trans' => '24104', 'freight' => ' 271.18 '];
$p = $wr->preview('invoice.freight', $ivIn, $ivNow);
$ivSummary = json_encode($p->plan->summary);
t_check(str_contains($ivSummary, 'N00133714, dated 2026-11-06, to Northfield Print'), 'the review names the invoice');
t_check(str_contains($ivSummary, 'none becomes 271.18 USD'), 'the review shows the freight change');
t_check(str_contains(implode(' ', $p->plan->warnings), 'reverses invoice N00133714'), 'the review says the invoice is re-issued');
t_same(1, count($p->plan->warnings), 'no age note on a fresh invoice');
t_check($wr->execute('invoice.freight', $ivIn, $ivNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($ivNorm(cms_fixture(IR::SAVE, 865454)), $ivNorm($fake->sent[0] ?? ''), 'the request is the one in log 865454');

$fake = new FakeTransport();
$wr   = $ivWriter($ivAll(), $fake);
$ivCh = ['trans' => 23258, 'freight' => '510.69'];
$ivT  = new \DateTimeImmutable('2026-08-21 12:38:41');
$p = $wr->preview('invoice.freight', $ivCh, $ivT);
t_check(str_contains(json_encode($p->plan->summary), '338.26 becomes 510.69 USD'), 'changing a freight charge shows both');
t_check($wr->execute('invoice.freight', $ivCh, $ivT, $p->hash)->ok(), 'a changed freight charge is committed');
t_xml_same($ivNorm(cms_fixture(IR::SAVE, 839758)), $ivNorm($fake->sent[0] ?? ''), 'the request is the one in log 839758');

$wr = $ivWriter($ivAll(), new FakeTransport());
$p  = $wr->preview('invoice.freight', ['trans' => 30011, 'freight' => '1,300'], $ivNow);
$x  = $p->rendered->stages[0]['xml'];
t_check(str_contains($x, 'Owner="4" TransDocument=') && str_contains($x, 'Currency="USD" FreightCharge="-0.0000" PoNumber="" ReversedTrans="30011"'),
    'no sales rep, a blank PO number and a zero freight charge are carried as they are');
t_check(str_contains($x, 'Currency="USD" FreightCharge="1300" PoNumber=""/>'), 'the new freight charge as the client writes it');
t_check(str_contains($x, 'Qty="-98.8"') && str_contains($x, 'Step="8" Operation="Insert"><keys TableName="Trans" Reference="InvoiceMemo"/>'), 'two lines, reversed then issued again');
t_check(str_contains($x, 'Memo="1509" Data="Line one&#xD;&#xA;Line two"') || str_contains($x, 'Memo="1509" Data="Line one&#x0D;&#x0A;Line two"'),
    'the text of the invoice note is carried over');
t_check(str_contains(json_encode($p->plan->summary), 'none becomes 1,300.00 USD') && str_contains(json_encode($p->plan->summary), '2 lines'), 'the review counts the lines');
$p = $wr->preview('invoice.freight', $ivIn, new \DateTimeImmutable('2026-12-20 09:00:00'));
t_check(str_contains(implode(' ', $p->plan->warnings), 'more than 31 days old'), 'an old invoice gets a note about the books');

foreach ([
    'no invoice'          => [['trans' => ''], 'was not found'],
    'unknown invoice'     => [['trans' => '1'], 'was not found'],
    'a reversal'          => [['trans' => 30001], 'is the reversal'],
    'already reversed'    => [['trans' => 30002], 'has been reversed'],
    'other currency'      => [['trans' => 30003], 'not a plain US dollar'],
    'other rate'          => [['trans' => 30013], 'not a plain US dollar'],
    'taxed'               => [['trans' => 30004], 'carries tax'],
    'no PO number held'   => [['trans' => 30005], 'missing details'],
    'a charge line'       => [['trans' => 30006], 'not an ordinary shipped order line'],
    'entered quantity'    => [['trans' => 30007], 'not an ordinary shipped order line'],
    'taxed line'          => [['trans' => 30012], 'not an ordinary shipped order line'],
    'no note'             => [['trans' => 30008], 'exactly one invoice note'],
    'two shipments'       => [['trans' => 30009], 'exactly one shipment'],
    'no lines'            => [['trans' => 30010], 'has no lines'],
    'no freight'          => [['freight' => ''], 'dollars and cents'],
    'freight with mills'  => [['freight' => '1.234'], 'dollars and cents'],
    'negative freight'    => [['freight' => '-5'], 'dollars and cents'],
    'zero on none'        => [['freight' => '0'], 'Enter the freight charge to put'],
    'taking freight off'  => [['trans' => 23258, 'freight' => '0.00'], 'is done in CMS'],
    'same freight'        => [['trans' => 23258, 'freight' => '338.26'], 'already carries 338.26'],
    'absurd freight'      => [['freight' => '50000.01'], 'larger than this page accepts'],
] as $label => [$over, $needle]) {
    $ivRefused(static fn () => $wr->preview('invoice.freight', $over + $ivIn, $ivNow), $needle, $label);
}
$ivRefused(static fn () => $ivWriter($ivAll(), new FakeTransport(), [])->preview('invoice.freight', $ivIn, $ivNow), '', 'without the permission');

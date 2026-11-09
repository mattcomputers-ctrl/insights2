<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\DryRunTransport;
use PII\Cms\Write\FakeCmsReader;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingRequests;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingState;
use PII\Cms\Write\TransportResult;

/* ── Flows: what a user's input becomes, against a fake CMS ── */

$mfPerms  = array_merge(array_keys(\PII\Cms\Write\OperationRegistry::permissionsFor('batch_tickets')),
    array_keys(\PII\Cms\Write\OperationRegistry::permissionsFor('recipes')));
$mfWriter = static fn (FakeCmsReader $cms, $transport): \PII\Cms\Write\CmsWriter => new \PII\Cms\Write\CmsWriter(
    $cms, $transport, static fn (string $p) => in_array($p, $mfPerms, true), 'pic\\user1', 'WS1', true, '7.15.0.680',
    static function (array $row): void {});
/** Level what legitimately differs from the log: the program names, the clock, the counter, the last digits of a volume. */
$mfNorm = static fn (string $xml): string => preg_replace_callback('/TotalVolume="([^"]+)"/',
    static fn (array $m): string => 'TotalVolume="' . sprintf('%.10F', (float) $m[1]) . '"',
    preg_replace(['/Application="[^"]+"/', '/ TransDate="[^"]+"/', '/ChangeNumber="[^"]+"/'],
        ['Application="A"', ' TransDate="T"', 'ChangeNumber="N"'], $xml));
$mfRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle),
        "refused: {$label}" . ($e !== null ? ' [' . $e->getMessage() . ']' : ' (not refused)'));
};

t_section('Manufacturing registry and arithmetic');
t_same(13, count($mfPerms), 'thirteen manufacturing write permissions, the floor ones included');
t_same('4.34', ManufacturingState::qty(0.434 * 10), 'a scaled quantity is written to fifteen significant digits');
t_same('0.050000001', ManufacturingState::qty(0.0050000001 * 10), 'small recipe fractions survive scaling');

/** One recipe line as the reader returns it. */
$mfRd = static fn (int $id, string $ctx, array $over = []): array => $over + [
    'RecipeDetail' => $id, 'Context' => $ctx, 'AltID' => null, 'Line' => null, 'Item' => null, 'Parent' => null, 'Phase' => null,
    'BatchType' => null, 'TimeToAddUnit' => null, 'ExecOrder' => null, 'Description' => null, 'UseFrom' => null, 'QtyReqd' => null,
    'AdjControlValue' => null, 'Special' => 0, 'ItemCode' => null, 'Unit' => null, 'ItemStatus' => null, 'SpecificGravity' => null,
];
$mfUi = static fn (int $id, int $alt, int $line, int $exec, int $item, string $qty, string $sg): array => $mfRd($id, 'UI', [
    'AltID' => $alt, 'Line' => $line, 'ExecOrder' => $exec, 'Item' => $item, 'ItemCode' => "R{$item}", 'Unit' => 'lb',
    'QtyReqd' => $qty, 'SpecificGravity' => $sg]);

/** Recipe 19257 (bulk, six ingredients) and 19255 (its 5 lb can) as CMS held them before logs 866303 to 866305. */
$mfRecipes = [
    19257 => [
        'head'  => ['Recipe' => 19257, 'Version' => 3, 'Context' => 'RMBA', 'Owner' => '4', 'RecipeNumber' => 'B121303', 'Comment' => 'NEW',
                    'PercentPrecision' => 2, 'IsPublished' => 1, 'Inactive' => 0],
        'lines' => [
            $mfRd(273329, 'BA', ['AltID' => 8, 'Phase' => 'PHASE', 'BatchType' => '2', 'TimeToAddUnit' => 'hr', 'ExecOrder' => 1]),
            $mfUi(273330, 2, 1, 3, 101268, '4.340000000000000e-001', '9.549816103214213e-001'),
            $mfUi(273331, 3, 2, 4, 108604, '4.000000000000000e-002', '1.000000000000000e+000'),
            $mfUi(273332, 4, 3, 5, 101267, '5.000000000000000e-001', '1.9148936170220714e+000'),
            $mfUi(273333, 5, 4, 6, 101264, '5.000000100000000e-003', '1.0054376123241993e+000'),
            $mfUi(273334, 6, 5, 7, 100846, '1.000000100000000e-003', '1.0664551986127375e+000'),
            $mfUi(273335, 7, 6, 8, 101270, '2.000000000000000e-002', '9.966446297465893e-001'),
            $mfRd(273336, 'INSTR', ['AltID' => 9, 'Description' => 'WEIGH ALL', 'ExecOrder' => 2]),
            $mfRd(273337, 'INSTR', ['AltID' => 10, 'Description' => 'MIX WELL', 'ExecOrder' => 9]),
            $mfRd(273338, 'PK', ['AltID' => 1, 'Item' => 121303, 'ItemCode' => 'B121303', 'Unit' => 'lb', 'QtyReqd' => '1.0e+000', 'Parent' => 273329]),
            $mfRd(273339, 'UB', ['AltID' => 11, 'UseFrom' => 8, 'Parent' => 273338]),
        ],
    ],
    19255 => [
        'head'  => ['Recipe' => 19255, 'Version' => 1, 'Context' => 'RMPP', 'Owner' => '4', 'RecipeNumber' => 'P121304',
                    'Comment' => '5LB SLIP LID CAN', 'PercentPrecision' => 2, 'IsPublished' => 1, 'Inactive' => 0],
        'lines' => [
            $mfRd(273315, 'PK', ['Item' => 121304, 'ItemCode' => 'P121304', 'Unit' => 'ea', 'QtyReqd' => '1.0e+000']),
            $mfRd(273316, 'UI', ['Item' => 111075, 'ItemCode' => 'C111075', 'Unit' => 'ea', 'QtyReqd' => '2.150000000000000e-001']),
            $mfRd(273317, 'UI', ['Item' => 121303, 'ItemCode' => 'B121303', 'Unit' => 'lb', 'QtyReqd' => '1.0e+000']),
        ],
    ],
    // Recipe 17127 before log 866471. Its lines are given here in line order; CMS stores line 1 after lines 2 to 4.
    17127 => [
        'head'  => ['Recipe' => 17127, 'Version' => 2, 'Context' => 'RMBA', 'Owner' => '4', 'RecipeNumber' => 'B103606', 'Comment' => 'NOTE 1',
                    'PercentPrecision' => 2, 'IsPublished' => 1, 'Inactive' => 0],
        'lines' => [
            $mfRd(249450, 'UB', ['AltID' => 21, 'UseFrom' => 18, 'Parent' => 249449]),
            $mfRd(249449, 'PK', ['AltID' => 1, 'Item' => 103606, 'ItemCode' => 'B103606', 'Unit' => 'lb', 'QtyReqd' => '1.0e+000', 'Parent' => 249442]),
            $mfRd(249448, 'INSTR', ['AltID' => 20, 'Description' => 'WEIGH ALL', 'ExecOrder' => 2]),
            $mfUi(249446, 17, 1, 3, 101268, '8.0000000000000002e-002', '9.5498161032142126e-001'),
            $mfUi(249443, 10, 2, 4, 117273, '5.0000000000000000e-001', '1.0315931328248533e+000'),
            $mfUi(249444, 11, 3, 5, 117272, '3.1000000000000000e-001', '1.0938655451068200e+000'),
            $mfUi(249445, 12, 4, 6, 101258, '1.1000000000000000e-001', '9.7965759953566989e-001'),
            $mfRd(249447, 'INSTR', ['AltID' => 19, 'Description' => 'MIX WELL', 'ExecOrder' => 7]),
            $mfRd(249442, 'BA', ['AltID' => 18, 'Phase' => 'PHASE', 'BatchType' => '2', 'TimeToAddUnit' => 'hr', 'ExecOrder' => 1]),
        ],
    ],
];
$mfItems = [
    'B103606' => ['Item' => 103606, 'ItemCode' => 'B103606', 'Description' => 'TEST RED', 'Unit' => 'lb', 'Status' => null, 'recipe' => 17127],
    'B121303' => ['Item' => 121303, 'ItemCode' => 'B121303', 'Description' => 'TEST BLUE', 'Unit' => 'lb', 'Status' => null, 'recipe' => 19257],
    'P121304' => ['Item' => 121304, 'ItemCode' => 'P121304', 'Description' => 'TEST BLUE 5LB', 'Unit' => 'ea', 'Status' => null, 'recipe' => 19255],
    'OLD1'    => ['Item' => 7, 'ItemCode' => 'OLD1', 'Description' => 'OLD', 'Unit' => 'lb', 'Status' => 'OBS', 'recipe' => null],
    'RAW1'    => ['Item' => 8, 'ItemCode' => 'RAW1', 'Description' => 'RAW', 'Unit' => 'lb', 'Status' => null, 'recipe' => null],
];
$mfTests = [
    ['Test' => 'DRAWDOWN', 'TestGroup' => 'DEFAULT', 'Grade' => 'GMP', 'Line' => 1, 'Deferrable' => 0, 'Min' => null, 'Max' => null, 'Special' => 0],
    ['Test' => 'GRIND', 'TestGroup' => 'DEFAULT', 'Grade' => 'GMP', 'Line' => 2, 'Deferrable' => 0, 'Min' => '0.0e+000', 'Max' => '2.0e+000', 'Special' => 0],
];
/** A ticket line as the reader returns it. */
$mfOd = static fn (int $id, string $ctx, array $over = []): array => $over + [
    'OrdDetail' => $id, 'Context' => $ctx, 'AltID' => null, 'Line' => null, 'Item' => null, 'Parent' => null, 'IsOpen' => 0,
    'ExecOrder' => null, 'IsRepackaging' => 0, 'QtyReqd' => null, 'ItemCode' => null, 'Description' => null, 'Unit' => null,
];
$mfHead = static fn (array $over = []): array => $over + [
    'Ordr' => 194778, 'Version' => 1, 'Context' => 'MFBA', 'Status' => 'NST', 'UserHold' => 'HOLD', 'Owner' => '4', 'Recipe' => 19257,
    'BillTo' => 101427, 'AllowOvercommit' => 1, 'DateRequired' => '2026-11-09T12:07:44', 'PlanStartDate' => '2026-11-09T12:07:44',
    'DateReleased' => null, 'BillToName' => 'Company 1',
];
$mfLines = static function () use ($mfOd): array {
    $l = [$mfOd(566301, 'BA', ['AltID' => 8, 'ExecOrder' => 1]),
          $mfOd(566302, 'PK', ['AltID' => 1, 'Item' => 121303, 'ItemCode' => 'B121303', 'Description' => 'TEST BLUE', 'Unit' => 'lb',
                               'IsOpen' => 1, 'QtyReqd' => '1.0e+001', 'Parent' => 566301]),
          $mfOd(566303, 'UB', ['AltID' => 11])];
    foreach ([101268, 108604, 101267, 101264, 100846, 101270] as $i => $item) {
        $l[] = $mfOd(566304 + $i, 'UI', ['AltID' => $i + 2, 'Line' => $i + 1, 'ExecOrder' => $i + 3, 'Item' => $item, 'ItemCode' => "R{$item}",
                                         'Unit' => 'lb', 'IsOpen' => 1, 'QtyReqd' => '1.0e+000']);
    }
    $l[] = $mfOd(566310, 'INSTR', ['AltID' => 9, 'ExecOrder' => 2]);
    $l[] = $mfOd(566311, 'INSTR', ['AltID' => 10, 'ExecOrder' => 9]);
    return $l;
};
/**
 * @param array|\Closure $head ticket header row (or a closure giving the current one)
 */
$mfCms = static function (array|\Closure|null $head = null, ?array $lines = null, array $packs = [], ?array $tests = null, ?array $recipes = null)
    use ($mfRecipes, $mfItems, $mfTests, $mfHead, $mfLines): FakeCmsReader {
    $recipes ??= $mfRecipes;
    $head    ??= $mfHead();
    return (new FakeCmsReader())
        ->when('FROM CMS.dbo.Item i WHERE i.ItemCode = ?', static fn (array $p) => isset($mfItems[$p[0]]) ? [$mfItems[$p[0]]] : [])
        ->when('JOIN CMS.dbo.RecipeDetail pk', static function (array $p) use ($mfItems, $recipes): array {
            $out = [];
            foreach ($mfItems as $i) {
                if ($i['Item'] === (int) $p[0] && $i['recipe'] !== null && isset($recipes[$i['recipe']])) {
                    $out[] = $recipes[$i['recipe']]['head'];
                }
            }
            return $out;
        })
        ->when('FROM CMS.dbo.Recipe r WHERE r.Recipe = ?', static fn (array $p) => isset($recipes[(int) $p[0]]) ? [$recipes[(int) $p[0]]['head']] : [])
        ->when('FROM CMS.dbo.RecipeDetail rd', static fn (array $p) => $recipes[(int) $p[0]]['lines'] ?? [])
        ->when('FROM CMS.dbo.Ordr o', static function (array $p) use ($head): array {
            $h = $head instanceof \Closure ? $head() : $head;
            return $h !== null && (int) $h['Ordr'] === (int) $p[0] ? [$h] : [];
        })
        ->when('FROM CMS.dbo.OrdDetail od', $lines ?? $mfLines())
        ->when('FROM CMS.dbo.OrdDetailCommit c', $packs)
        ->when('FROM CMS.dbo.ItemTest t', $tests ?? $mfTests)
        ->when('FROM CMS.dbo.Entities e WHERE', static fn (array $p) => in_array((int) $p[0], [101427, 101387, 100235], true)
            ? [['Entity' => (int) $p[0], 'EntityName' => 'Company 1']] : []);
};

t_section('Manufacturing flow: cut a ticket with a packaging ticket and release it');
$mfNow = new DateTimeImmutable('2026-11-09T12:07:44');
$mfIn  = ['item' => 'B121303', 'qty' => '10', 'required' => '2026-11-09', 'bill_to' => '101427', 'release' => '1',
          'packs' => [['code' => 'P121304', 'qty' => '10'], ['code' => '', 'qty' => '']]];
$mfVersion = 1;
$mfLive    = $mfCms(static function () use ($mfHead, &$mfVersion): array {
    return $mfHead(['Version' => $mfVersion++]);
});
$mfFake = new FakeTransport();
$mfW    = $mfWriter($mfLive, $mfFake);
$mfP    = $mfW->preview('mf.create_ticket', $mfIn, $mfNow);
t_same(0, count($mfFake->sent), 'preview sends nothing');
t_same(3, count($mfP->rendered->stages), 'three requests: ticket, packaging ticket, release');
t_same([], $mfP->plan->warnings, 'a fully packed batch with gravities raises no warning');
$mfJson = json_encode($mfP->rendered);
t_check(str_contains($mfJson, '{next ChangeNumber}') && str_contains($mfJson, '{stage 1: new Ordr}') && str_contains($mfJson, '{stage 1: new OrdDetail}'),
    'the preview shows placeholders for the counter and the ids CMS will assign');
t_check(str_contains($mfP->rendered->stages[2]['xml'], 'Version="2"') && str_contains($mfP->rendered->stages[1]['xml'], 'Version="1"'),
    'the preview predicts the ticket version each later request will meet');

$mfFake->willReturn(new TransportResult(TransportResult::COMMITTED, 866303, 0, null, 'ok', [
    ['step' => 1, 'table' => 'Ordr', 'field' => 'Ordr', 'value' => '194778'],
    ['step' => 2, 'table' => 'OrdDetail', 'field' => 'OrdDetail', 'value' => '566301'],
    ['step' => 3, 'table' => 'OrdDetail', 'field' => 'OrdDetail', 'value' => '566302'],
]));
$mfRes = $mfW->execute('mf.create_ticket', $mfIn, $mfNow, $mfP->hash);
t_check($mfRes->ok(), 'all three requests committed on the fake transport: ' . $mfRes->describe());
t_same(3, count($mfFake->sent), 'three requests sent');
t_same(['ChangeNumber'], $mfFake->generated, 'one change number taken, at send time');
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::SAVE, 866303)), $mfNorm($mfFake->sent[0] ?? ''), 'the bulk ticket matches log 866303 (volume to ten decimals)');
$mfPackDate = static fn (string $x): string => preg_replace('/DateOrdered="[^"]+"/', 'DateOrdered="D"', $x);
t_xml_same($mfPackDate($mfNorm(cms_fixture(ManufacturingRequests::SAVE, 866304))), $mfPackDate($mfNorm($mfFake->sent[1] ?? '')), 'the packaging ticket matches log 866304');
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::RELEASE, 866305)), $mfNorm($mfFake->sent[2] ?? ''), 'the release matches log 866305');
t_check(str_contains($mfFake->sent[0], 'TotalWeight="4.5359237009071851"'), 'the batch weight in kilograms is exact');

$mfFake = new FakeTransport();
$mfRes  = $mfWriter($mfCms(), $mfFake)->execute('mf.create_ticket',
    ['item' => 'B103606', 'qty' => '20', 'required' => '2026-11-09', 'bill_to' => '100235'], new DateTimeImmutable('2026-11-09T16:29:31'));
t_check($mfRes->ok(), 'a ticket from a recipe whose lines are not stored in line order committed');
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::SAVE, 866471)), $mfNorm($mfFake->sent[0] ?? ''),
    'ingredients and instructions go out in the order the recipe stores them: matches log 866471');

$mfRes = $mfWriter($mfCms(), new DryRunTransport())->execute('mf.create_ticket', $mfIn, $mfNow);
t_check($mfRes->dryRun() && count($mfRes->stages) === 3, 'the whole sequence runs end to end in dry-run');

$mfP = $mfWriter($mfCms(), new FakeTransport())->preview('mf.create_ticket',
    ['item' => 'B121303', 'qty' => '25', 'required' => '2026-11-12', 'start' => '2026-11-10', 'overcommit' => '0',
     'packs' => [['code' => 'P121304', 'qty' => '20']]], $mfNow);
t_same(2, count($mfP->rendered->stages), 'without release there is no third request');
$mfX = $mfP->rendered->stages[0]['xml'];
t_check(str_contains($mfX, 'DateRequired="2026-11-12T12:07:44" PlanStartDate="2026-11-10T12:07:44"') && str_contains($mfX, 'AllowOvercommit="false"')
    && !str_contains($mfX, 'BillTo='), 'dates carry the time of day; overcommit can be switched off; no customer means no BillTo');
t_check(str_contains($mfX, 'QtyEntered="10.85 lb" QtyReqd="10.85" StdQty="0.434"') && str_contains($mfX, 'QtyReqd="0.1250000025"'), 'ingredients scale with the batch');
t_check(count($mfP->plan->warnings) === 1 && str_contains($mfP->plan->warnings[0], '5 lb of the batch is not assigned'), 'warns about bulk left unpacked: ' . json_encode($mfP->plan->warnings));
t_check(str_contains($mfP->rendered->stages[1]['xml'], 'QtyEntered="4.3 ea" QtyReqd="4.3" StdQty="0.215"') && str_contains($mfP->rendered->stages[1]['xml'], 'SrcOrdDetail="{stage 1: new OrdDetail}" Qty="20"'),
    'the packaging ticket claims its share of the bulk');
$mfLb = $mfWriter($mfCms(), new FakeTransport())->preview('mf.create_ticket',
    ['item' => 'B121303', 'qty' => '25', 'required' => '2026-11-12', 'start' => '2026-11-10', 'overcommit' => '0',
     'packs' => [['code' => 'P121304', 'qty' => '20', 'by' => 'lb']]], $mfNow);
t_same($mfP->rendered->stages[1]['xml'], $mfLb->rendered->stages[1]['xml'], 'a packaging quantity given in pounds of bulk becomes units of the packaged item');
$mfR2 = $mfRecipes;
$mfR2[19255]['lines'][2]['QtyReqd'] = '5.0e+000';
$mfLb = $mfWriter($mfCms(null, null, [], null, $mfR2), new FakeTransport())->preview('mf.create_ticket',
    ['item' => 'B121303', 'qty' => '25', 'required' => '2026-11-12', 'packs' => [['code' => 'P121304', 'qty' => '20', 'by' => 'lb']]], $mfNow);
t_check(str_contains(json_encode($mfLb->plan->summary), '4 ea of P121304') && str_contains($mfLb->rendered->stages[1]['xml'], 'Qty="20"'),
    'twenty pounds into a five-pound pack is four of them: ' . json_encode($mfLb->plan->summary));

$mfP = $mfWriter($mfCms(null, null, [], []), new FakeTransport())->preview('mf.create_ticket', ['packs' => []] + $mfIn, $mfNow);
t_check(str_contains($mfP->rendered->stages[1]['xml'], 'Status="Approved"') && str_contains($mfP->rendered->stages[1]['xml'], 'ManfDate="2026-11-09T12:07:44"')
    && !str_contains($mfP->rendered->stages[1]['xml'], 'OrdDetailTest'), 'an item with no specification tests gets its lot approved at release');

$mfBad = static function (array $lineOver, array $headOver = []) use ($mfRecipes): array {
    $r = $mfRecipes;
    $r[19257]['head']     = $headOver + $r[19257]['head'];
    $r[19257]['lines'][1] = $lineOver + $r[19257]['lines'][1];
    return $r;
};
foreach ([
    'unknown item'            => [['item' => 'NOPE'], null, 'was not found'],
    'obsolete item'           => [['item' => 'OLD1'], null, 'obsolete'],
    'item with no recipe'     => [['item' => 'RAW1'], null, 'no published recipe'],
    'packaged item as bulk'   => [['item' => 'P121304'], null, 'not made from a bulk recipe'],
    'zero batch'              => [['qty' => '0'], null, 'more than zero'],
    'past required date'      => [['required' => '2026-11-08'], null, 'in the past'],
    'start after required'    => [['start' => '2026-11-10'], null, 'after the required date'],
    'unknown customer'        => [['bill_to' => '5'], null, 'customer was not found'],
    'packs over the batch'    => [['packs' => [['code' => 'P121304', 'qty' => '11']]], null, 'draw 11 lb but the batch is 10 lb'],
    'pack listed twice'       => [['packs' => [['code' => 'P121304', 'qty' => '2'], ['code' => 'P121304', 'qty' => '3']]], null, 'listed twice'],
    'pack of another bulk'    => [['packs' => [['code' => 'B121303', 'qty' => '2']]], null, 'not a packaged item'],
    'special recipe line'     => [[], $mfBad(['Special' => 1]), 'special handling'],
    'ingredient not in lb'    => [[], $mfBad(['Unit' => 'gal']), 'not stocked by the pound'],
    'incomplete ingredient'   => [[], $mfBad(['ExecOrder' => null]), 'incomplete'],
] as $mfName => [$mfOver, $mfR, $mfNeedle]) {
    $mfRefused(fn () => $mfWriter($mfCms(null, null, [], null, $mfR), new FakeTransport())->preview('mf.create_ticket', $mfOver + $mfIn, $mfNow), $mfNeedle, $mfName);
}
$mfR = $mfRecipes;
$mfR[19257]['lines'][3]['SpecificGravity'] = null;
$mfP = $mfWriter($mfCms(null, null, [], null, $mfR), new FakeTransport())->preview('mf.create_ticket', $mfIn, $mfNow);
t_check(count($mfP->plan->warnings) === 1 && str_contains($mfP->plan->warnings[0], 'No specific gravity in CMS for R101267'), 'a missing gravity is warned about, not refused');

t_section('Manufacturing flow: stopping part way');
$mfFake = new FakeTransport();
$mfFake->willReturn(new TransportResult(TransportResult::COMMITTED, 866303, 0, null, 'ok', [
    ['step' => 1, 'table' => 'Ordr', 'field' => 'Ordr', 'value' => '194778'],
]));
$mfFake->willFail('Order 194778 is currently locked by pic\a at PC1');
$mfVersion = 1;
$mfRes = $mfWriter($mfLive, $mfFake)->execute('mf.create_ticket', $mfIn, $mfNow);
t_same(['committed', 'failed', 'not-run'], array_column($mfRes->stages, 'status'), 'a failed packaging ticket stops the release');
t_check(str_contains($mfRes->describe(), '1 earlier step is already saved'), 'the user is told the ticket itself exists');
$mfFake = new FakeTransport();
$mfFake->willReturn(new TransportResult(TransportResult::COMMITTED, 866303, 0, null, 'ok', [
    ['step' => 1, 'table' => 'Ordr', 'field' => 'Ordr', 'value' => '194999'],
]));
$mfRes = $mfWriter($mfCms(), $mfFake)->execute('mf.create_ticket', $mfIn, $mfNow);
t_check(($mfRes->stages[1]['status'] ?? '') === 'failed' && count($mfFake->sent) === 1 && str_contains($mfRes->describe(), 'could not be read back'),
    'a new ticket that cannot be read back is not built on');

t_section('Manufacturing flow: add a packaging ticket');
$mfNowP = new DateTimeImmutable('2026-11-09T12:08:42');
$mfFake = new FakeTransport();
$mfRes  = $mfWriter($mfCms(), $mfFake)->execute('mf.add_pack_ticket', ['ordr' => 194778, 'code' => 'P121304', 'qty' => '10'], $mfNowP);
t_check($mfRes->ok(), 'packaging ticket committed');
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::SAVE, 866304)), $mfNorm($mfFake->sent[0] ?? ''), 'matches log 866304');
$mfPackRow = [['Ordr' => 194779, 'Status' => 'NST', 'ItemCode' => 'P121304', 'Qty' => '6.0e+000']];
$mfRefused(fn () => $mfWriter($mfCms(null, null, $mfPackRow), new FakeTransport())->preview('mf.add_pack_ticket', ['ordr' => 194778, 'code' => 'P121304', 'qty' => '1'], $mfNowP),
    'already has a packaging ticket', 'the same packaged item twice');
$mfRefused(fn () => $mfWriter($mfCms(null, null, [['ItemCode' => 'P9'] + $mfPackRow[0]]), new FakeTransport())->preview('mf.add_pack_ticket', ['ordr' => 194778, 'code' => 'P121304', 'qty' => '5'], $mfNowP),
    '6 lb is already assigned', 'more than the batch has left');
$mfRefused(fn () => $mfWriter($mfCms($mfHead(['Status' => 'RLS', 'UserHold' => '', 'DateReleased' => '2026-11-09T00:00:00'])), new FakeTransport())
    ->preview('mf.add_pack_ticket', ['ordr' => 194778, 'code' => 'P121304', 'qty' => '5'], $mfNowP), 'is released', 'a released ticket');
$mfRefused(fn () => $mfWriter($mfCms($mfHead(['Context' => 'MFPP'])), new FakeTransport())->preview('mf.add_pack_ticket', ['ordr' => 194778, 'code' => 'P121304', 'qty' => '5'], $mfNowP),
    'is a packaging ticket', 'a packaging ticket as the parent');

t_section('Manufacturing flow: release');
$mfNowR = new DateTimeImmutable('2026-11-09T12:08:45');
$mfFake = new FakeTransport();
$mfW    = $mfWriter($mfCms($mfHead(['Version' => 2])), $mfFake);
$mfP    = $mfW->preview('mf.release_ticket', ['ordr' => 194778], $mfNowR);
t_same([], $mfP->plan->warnings, 'no warning when overcommit is allowed');
$mfRes = $mfW->execute('mf.release_ticket', ['ordr' => 194778], $mfNowR, $mfP->hash);
t_check($mfRes->ok(), 'release committed');
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::RELEASE, 866305)), $mfNorm($mfFake->sent[0] ?? ''), 'matches log 866305');

$mfFake = new FakeTransport();
$mfLines823 = [$mfOd(566178, 'BA', ['AltID' => 3, 'ExecOrder' => 1]),
               $mfOd(566179, 'PK', ['AltID' => 1, 'Item' => 101029, 'ItemCode' => 'B101029', 'Unit' => 'lb', 'IsOpen' => 1, 'QtyReqd' => '5.0e+001']),
               $mfOd(566180, 'UI', ['AltID' => 2, 'Line' => 1, 'ExecOrder' => 2, 'Item' => 5, 'ItemCode' => 'R5', 'Unit' => 'lb', 'IsOpen' => 1])];
$mfCms823 = $mfCms($mfHead(['Ordr' => 194759, 'Version' => 2, 'AllowOvercommit' => 0, 'PlanStartDate' => '2026-11-09T12:20:21']), $mfLines823, [], []);
$mfW   = $mfWriter($mfCms823, $mfFake);
$mfP   = $mfW->preview('mf.release_ticket', ['ordr' => 194759], new DateTimeImmutable('2026-11-06T12:22:19'));
t_check(count($mfP->plan->warnings) === 1 && str_contains($mfP->plan->warnings[0], 'Overcommit is not allowed'), 'warns that CMS refuses a short release');
$mfW->execute('mf.release_ticket', ['ordr' => 194759], new DateTimeImmutable('2026-11-06T12:22:19'));
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::RELEASE, 865823)), $mfNorm($mfFake->sent[0] ?? ''), 'a product with no tests matches log 865823');

// Six tests whose line numbers are not in name order (PULL is line 6): the client writes them by name.
$mfT = static fn (string $name, int $line, ?string $min, ?string $max): array => [
    'Test' => $name, 'TestGroup' => 'DEFAULT', 'Grade' => 'GMP', 'Line' => $line, 'Deferrable' => 0, 'Min' => $min, 'Max' => $max, 'Special' => 0];
$mfTests641 = [
    $mfT('DRAWDOWN', 1, null, null), $mfT('GRIND', 2, '0.0e+000', '2.0e+000'), $mfT('TACK 1200', 3, '1.95e+001', '2.05e+001'),
    $mfT('VISC', 4, '2.0025e+002', '3.3375e+002'), $mfT('YIELD', 5, '1.6545e+003', '2.7575e+003'), $mfT('PULL', 6, '5.5e+000', '7.0e+000'),
];
$mfLines641 = [$mfOd(566116, 'BA', ['AltID' => 15, 'ExecOrder' => 1]),
               $mfOd(566117, 'PK', ['AltID' => 1, 'Item' => 113083, 'ItemCode' => 'B113083', 'Unit' => 'lb', 'IsOpen' => 1, 'QtyReqd' => '3.6e+002']),
               $mfOd(566118, 'UI', ['AltID' => 2, 'Line' => 1, 'ExecOrder' => 3, 'Item' => 5, 'ItemCode' => 'R5', 'Unit' => 'lb', 'IsOpen' => 1]),
               $mfOd(566119, 'INSTR', ['AltID' => 19, 'ExecOrder' => 17])];
$mfHead641  = $mfHead(['Ordr' => 194751, 'Version' => 2, 'PlanStartDate' => '2026-11-06T10:58:41']);
$mfFake = new FakeTransport();
$mfWriter($mfCms($mfHead641, $mfLines641, [], $mfTests641), $mfFake)
    ->execute('mf.release_ticket', ['ordr' => 194751], new DateTimeImmutable('2026-11-06T11:00:33'));
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::RELEASE, 865641)), $mfNorm($mfFake->sent[0] ?? ''),
    'specification tests go out in name order, not line order: matches log 865641');
$mfRefused(fn () => $mfWriter($mfCms($mfHead641, $mfLines641, [], [...$mfTests641, $mfT('VISC #3 ZAHN', 7, null, null), $mfT('VISC @20RPM', 8, null, null)]), new FakeTransport())
    ->preview('mf.release_ticket', ['ordr' => 194751], $mfNowR), 'cannot put in the order CMS writes them', 'test names whose order is not known');
$mfRefused(fn () => $mfWriter($mfCms($mfHead641, $mfLines641, [], [...$mfTests641, $mfT('Grind', 7, null, null)]), new FakeTransport())
    ->preview('mf.release_ticket', ['ordr' => 194751], $mfNowR), 'cannot put in the order CMS writes them', 'test names that differ in case');
$mfP = $mfWriter($mfCms($mfHead641, $mfLines641, [], [$mfT('VISC #3 ZAHN', 1, null, null), $mfT('VISC', 2, null, null), $mfT('CURE', 3, null, null)]), new FakeTransport())
    ->preview('mf.release_ticket', ['ordr' => 194751], $mfNowR);
t_check(preg_match('/Test="CURE".*Test="VISC".*Test="VISC #3 ZAHN"/s', $mfP->rendered->stages[0]['xml']) === 1,
    'a longer name follows the name it starts with');

$mfRefused(fn () => $mfWriter($mfCms($mfHead(['Status' => 'RLS', 'UserHold' => '', 'DateReleased' => '2026-11-09T00:00:00'])), new FakeTransport())
    ->preview('mf.release_ticket', ['ordr' => 194778], $mfNowR), 'is released already', 'releasing twice');
$mfRefused(fn () => $mfWriter($mfCms(null, [...$mfLines(), $mfOd(566312, 'IPT', ['AltID' => 12])]), new FakeTransport())
    ->preview('mf.release_ticket', ['ordr' => 194778], $mfNowR), 'already has a test step', 'a ticket that already has a test step');
$mfRefused(fn () => $mfWriter($mfCms($mfHead(['Recipe' => null])), new FakeTransport())->preview('mf.release_ticket', ['ordr' => 194778], $mfNowR),
    'not cut from a single recipe', 'a hand-built ticket');
$mfRefused(fn () => $mfWriter($mfCms(null, null, [], [['Special' => 1] + $mfTests[0]]), new FakeTransport())->preview('mf.release_ticket', ['ordr' => 194778], $mfNowR),
    'has a test Insights does not copy', 'a specification with a target or text test');
$mfRefused(fn () => $mfWriter($mfCms(), new FakeTransport())->preview('mf.release_ticket', ['ordr' => 5], $mfNowR), 'was not found', 'an unknown ticket');

t_section('Manufacturing flow: close');
$mfFake = new FakeTransport();
$mfWriter($mfCms($mfHead(['Ordr' => 193251, 'Version' => 7, 'Status' => 'CMP', 'UserHold' => '', 'DateReleased' => '2026-10-01T00:00:00'])), $mfFake)
    ->execute('mf.close_ticket', ['ordr' => 193251], new DateTimeImmutable('2026-10-09T08:18:38'));
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::CLOSE, 854877)), $mfNorm($mfFake->sent[0] ?? ''), 'closing a completed ticket matches log 854877');

$mfClose = cms_fixture_parse(cms_fixture(ManufacturingRequests::CLOSE, 863585));
$mfIds   = [];
foreach ($mfClose['steps'] as $mfS) {
    if ($mfS['object'] === 'OrdDetail') {
        $mfIds[] = (int) $mfS['keys']['OrdDetail'];
    }
}
$mfLines585 = [$mfOd(564479, 'BA', ['AltID' => 1]), $mfOd(564481, 'UB'), $mfOd(564499, 'INSTR')];
foreach ($mfIds as $mfI => $mfId) {
    $mfLines585[] = $mfI === 0 ? $mfOd($mfId, 'PK', ['IsOpen' => 1, 'Item' => 1, 'ItemCode' => 'B1', 'Unit' => 'lb', 'QtyReqd' => '1.0e+002'])
                               : $mfOd($mfId, 'UI', ['IsOpen' => 1, 'Line' => $mfI, 'Item' => 100 + $mfI]);
}
usort($mfLines585, static fn (array $a, array $b): int => $a['OrdDetail'] <=> $b['OrdDetail']);
$mfFake = new FakeTransport();
$mfW    = $mfWriter($mfCms($mfHead(['Ordr' => 194521, 'Version' => 2]), $mfLines585, [['Ordr' => 194522, 'Status' => 'NST', 'ItemCode' => 'P1', 'Qty' => '1.0e+002']]), $mfFake);
$mfP    = $mfW->preview('mf.close_ticket', ['ordr' => 194521], new DateTimeImmutable('2026-11-03T16:46:28'));
t_check(count($mfP->plan->warnings) === 1 && str_contains($mfP->plan->warnings[0], 'cancels it'), 'closing an unreleased ticket warns that it cancels it');
t_check(str_contains(json_encode($mfP->plan->summary), '194522 (P1)'), 'the linked packaging ticket is named');
$mfW->execute('mf.close_ticket', ['ordr' => 194521], new DateTimeImmutable('2026-11-03T16:46:28'));
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::CLOSE, 863585)), $mfNorm($mfFake->sent[0] ?? ''), 'cancelling an unreleased ticket matches log 863585');
// Ticket 191511 as CMS held it before log 852174: lines 4 and 6 were stored after line 12, and a repack
// ingredient line (no line number, no parent) was added last.
$mfLines174 = [$mfOd(544074, 'BA', ['AltID' => 14, 'ExecOrder' => 1]),
               $mfOd(544075, 'PK', ['AltID' => 1, 'IsOpen' => 1, 'Item' => 112872, 'ItemCode' => 'B112872', 'Unit' => 'lb', 'QtyReqd' => '1.0e+002', 'Parent' => 544074]),
               $mfOd(544076, 'UB', ['AltID' => 18, 'Parent' => 544075])];
foreach ([1, 2, 3, 5, 7, 8, 9, 10, 11, 12, 4, 6] as $mfI => $mfLineNo) {
    $mfLines174[] = $mfOd(544077 + $mfI, 'UI', ['AltID' => $mfI + 2, 'IsOpen' => 1, 'Line' => $mfLineNo, 'Item' => 200 + $mfI, 'Parent' => 544074]);
}
foreach ([544089, 544090, 544091] as $mfId) {
    $mfLines174[] = $mfOd($mfId, 'INSTR', ['Parent' => 544074]);
}
$mfLines174[] = $mfOd(544092, 'UI', ['AltID' => 19, 'IsOpen' => 1, 'IsRepackaging' => 1, 'Item' => 112872, 'ItemCode' => 'B112872', 'Unit' => 'lb', 'QtyReqd' => '1.0e+002']);
$mfFake = new FakeTransport();
$mfWriter($mfCms($mfHead(['Ordr' => 191511, 'Version' => 1]), $mfLines174), $mfFake)
    ->execute('mf.close_ticket', ['ordr' => 191511], new DateTimeImmutable('2026-09-30T13:35:26'));
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::CLOSE, 852174)), $mfNorm($mfFake->sent[0] ?? ''),
    'a repack line is closed last with its commitment zeroed, the others in line order: matches log 852174');

// Ticket 193899, a repack ticket: a product and one repack ingredient line, no batch.
$mfLines233 = [$mfOd(560070, 'PK', ['AltID' => 1, 'IsOpen' => 1, 'Item' => 112871, 'ItemCode' => 'B112871', 'Unit' => 'lb']),
               $mfOd(560071, 'UI', ['AltID' => 2, 'IsOpen' => 1, 'IsRepackaging' => 1, 'Item' => 114623, 'ItemCode' => 'R114623', 'Unit' => 'lb', 'QtyReqd' => '1.08e+002'])];
$mfFake = new FakeTransport();
$mfWriter($mfCms($mfHead(['Ordr' => 193899, 'Version' => 3]), $mfLines233), $mfFake)
    ->execute('mf.close_ticket', ['ordr' => 193899], new DateTimeImmutable('2026-10-20T09:38:16'));
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::CLOSE, 858233)), $mfNorm($mfFake->sent[0] ?? ''), 'cancelling a repack ticket matches log 858233');

$mfRefused(fn () => $mfWriter($mfCms($mfHead(['Ordr' => 193899]), [...$mfLines233, $mfOd(560072, 'UI', ['IsOpen' => 1, 'IsRepackaging' => 1])]), new FakeTransport())
    ->preview('mf.close_ticket', ['ordr' => 193899], $mfNowR), 'Close it in CMS', 'two repack lines');
$mfRefused(fn () => $mfWriter($mfCms($mfHead(['Ordr' => 193899]), [...$mfLines233, $mfOd(560072, 'UI', ['IsOpen' => 1])]), new FakeTransport())
    ->preview('mf.close_ticket', ['ordr' => 193899], $mfNowR), 'Close it in CMS', 'an ordinary ingredient line without a line number');
$mfRefused(fn () => $mfWriter($mfCms(null, [...$mfLines(), $mfOd(566312, 'UI', ['IsOpen' => 1, 'Line' => 3])]), new FakeTransport())
    ->preview('mf.close_ticket', ['ordr' => 194778], $mfNowR), 'Close it in CMS', 'two ingredient lines with the same line number');

$mfRefused(fn () => $mfWriter($mfCms($mfHead(['Status' => 'RLS', 'UserHold' => '', 'DateReleased' => '2026-11-09T00:00:00'])), new FakeTransport())
    ->preview('mf.close_ticket', ['ordr' => 194778], $mfNowR), 'Only a completed ticket', 'a ticket on the floor');
$mfRefused(fn () => $mfWriter($mfCms($mfHead(['Status' => 'CLS', 'UserHold' => ''])), new FakeTransport())->preview('mf.close_ticket', ['ordr' => 194778], $mfNowR),
    'is closed', 'a closed ticket');

t_section('Manufacturing flow: change a ticket');
$mfFake = new FakeTransport();
$mfWriter($mfCms($mfHead(['Ordr' => 193011, 'Version' => 2, 'BillTo' => null, 'BillToName' => null])), $mfFake)
    ->execute('mf.edit_ticket', ['ordr' => 193011, 'required' => '2026-11-09', 'start' => '2026-11-09', 'overcommit' => '1', 'bill_to' => '101387'],
        new DateTimeImmutable('2026-09-25T11:04:02'));
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::SAVE, 850590)), $mfNorm($mfFake->sent[0] ?? ''), 'only the changed customer is sent: log 850590');
$mfFake = new FakeTransport();
$mfRls  = $mfHead(['Ordr' => 191599, 'Version' => 3, 'Status' => 'RLS', 'UserHold' => '', 'DateReleased' => '2026-08-18T00:00:00',
                   'DateRequired' => '2026-08-19T09:00:00', 'PlanStartDate' => '2026-08-19T09:00:00']);
$mfW    = $mfWriter($mfCms($mfRls), $mfFake);
$mfP    = $mfW->preview('mf.edit_ticket', ['ordr' => 191599, 'required' => '2026-08-20', 'start' => '2026-08-20'], new DateTimeImmutable('2026-08-19T15:58:51'));
t_check(count($mfP->plan->warnings) === 1 && str_contains($mfP->plan->warnings[0], 'already released'), 'changing a released ticket says so');
$mfW->execute('mf.edit_ticket', ['ordr' => 191599, 'required' => '2026-08-20', 'start' => '2026-08-20'], new DateTimeImmutable('2026-08-19T15:58:51'));
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::SAVE, 839011)), $mfNorm($mfFake->sent[0] ?? ''), 'moving a released ticket matches log 839011');
$mfP = $mfWriter($mfCms(), new FakeTransport())->preview('mf.edit_ticket', ['ordr' => 194778, 'overcommit' => '0'], $mfNowR);
t_check(str_contains($mfP->rendered->stages[0]['xml'], '<data AllowOvercommit="false"/>'), 'overcommit can be switched off on its own');
$mfRefused(fn () => $mfWriter($mfCms(), new FakeTransport())->preview('mf.edit_ticket', ['ordr' => 194778, 'required' => '2026-11-09', 'overcommit' => '1', 'bill_to' => '101427'], $mfNowR),
    'Nothing was changed', 'a save with no differences');
$mfRefused(fn () => $mfWriter($mfCms(), new FakeTransport())->preview('mf.edit_ticket', ['ordr' => 194778, 'required' => '2026-11-08'], $mfNowR),
    'after the required date', 'a required date before the start');
$mfRefused(fn () => $mfWriter($mfCms($mfHead(['Status' => 'CMP', 'UserHold' => ''])), new FakeTransport())->preview('mf.edit_ticket', ['ordr' => 194778, 'required' => '2026-11-20'], $mfNowR),
    'is completed', 'a completed ticket');

t_section('Manufacturing flow: recipes');
$mfDraft = [21046 => [
    'head'  => ['Recipe' => 21046, 'Version' => 2, 'Context' => 'RMBA', 'Owner' => '4', 'RecipeNumber' => 'B9', 'Comment' => 'DRAFT',
                'PercentPrecision' => 2, 'IsPublished' => 0, 'Inactive' => 0],
    'lines' => [
        $mfRd(294435, 'PK', ['AltID' => 1, 'Item' => 9, 'ItemCode' => 'B9', 'Unit' => 'lb', 'QtyReqd' => '1.0e+000']),
        $mfUi(294436, 2, 1, 2, 101268, '6.0e-001', '1.0e+000'),
        $mfUi(294437, 3, 2, 3, 108604, '4.0e-001', '1.0e+000'),
        $mfRd(294440, 'BA', ['AltID' => 6, 'Phase' => 'PHASE', 'BatchType' => '2', 'TimeToAddUnit' => 'hr', 'ExecOrder' => 1]),
        $mfRd(294441, 'INSTR', ['AltID' => 8, 'Description' => 'MIX', 'ExecOrder' => 4]),
    ],
]];
$mfFake = new FakeTransport();
$mfW    = $mfWriter($mfCms(null, null, [], null, $mfDraft), $mfFake);
$mfP    = $mfW->preview('mf.publish_recipe', ['recipe' => 21046], new DateTimeImmutable('2026-10-29T08:16:53'));
t_same([], $mfP->plan->warnings, 'a recipe that adds up raises no warning');
$mfW->execute('mf.publish_recipe', ['recipe' => 21046], new DateTimeImmutable('2026-10-29T08:16:53'));
t_xml_same($mfNorm(cms_fixture(ManufacturingRequests::PUBLISH, 861271)), $mfNorm($mfFake->sent[0] ?? ''), 'publishing matches log 861271');
$mfD2 = $mfDraft;
$mfD2[21046]['lines'][1]['QtyReqd'] = '5.0e-001';
$mfP  = $mfWriter($mfCms(null, null, [], null, $mfD2), new FakeTransport())->preview('mf.publish_recipe', ['recipe' => 21046], $mfNowR);
t_check(count($mfP->plan->warnings) === 1 && str_contains($mfP->plan->warnings[0], 'add up to 0.9'), 'a recipe that does not add up is warned about');
$mfRefused(fn () => $mfWriter($mfCms(), new FakeTransport())->preview('mf.publish_recipe', ['recipe' => 19257], $mfNowR), 'already published', 'publishing twice');
$mfRefused(fn () => $mfWriter($mfCms(), new FakeTransport())->preview('mf.publish_recipe', ['recipe' => 3], $mfNowR), 'was not found', 'an unknown recipe');
$mfD2 = $mfDraft;
$mfD2[21046]['head']['Context'] = 'RMPP';
$mfRefused(fn () => $mfWriter($mfCms(null, null, [], null, $mfD2), new FakeTransport())->preview('mf.publish_recipe', ['recipe' => 21046], $mfNowR), 'not a bulk recipe', 'a packaging recipe');
$mfD2 = $mfDraft;
unset($mfD2[21046]['lines'][1], $mfD2[21046]['lines'][2]);
$mfRefused(fn () => $mfWriter($mfCms(null, null, [], null, $mfD2), new FakeTransport())->preview('mf.publish_recipe', ['recipe' => 21046], $mfNowR), 'no ingredients', 'a recipe with no ingredients');

$mfFake = new FakeTransport();
$mfWriter($mfCms(), $mfFake)->execute('mf.recipe_active', ['recipe' => 19257, 'active' => '0'], $mfNowR);
t_check(str_contains($mfFake->sent[0] ?? '', 'Program="Batching Recipe" Action="ManufacturingOrderDataSet.ChangeActiveState"')
    && str_contains($mfFake->sent[0], '<keys Recipe="19257" Version="3"/><data Inactive="true"/>'), 'retiring a recipe sets Inactive on its current version');
$mfRefused(fn () => $mfWriter($mfCms(), new FakeTransport())->preview('mf.recipe_active', ['recipe' => 19257, 'active' => '1'], $mfNowR), 'already active', 'activating an active recipe');

t_section('Manufacturing permissions');
$mfNone = new \PII\Cms\Write\CmsWriter($mfCms(), new FakeTransport(), static fn (string $p) => false, 'pic\\user1', 'WS1', true, '7.15.0.680',
    static function (array $row): void {});
foreach (['mf.create_ticket', 'mf.add_pack_ticket', 'mf.edit_ticket', 'mf.release_ticket', 'mf.close_ticket', 'mf.publish_recipe', 'mf.recipe_active'] as $mfOp) {
    t_check(!$mfNone->allowed($mfOp), "{$mfOp} needs its own permission");
}

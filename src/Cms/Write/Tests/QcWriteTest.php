<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\DryRunTransport;
use PII\Cms\Write\FakeCmsReader;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Ops\Qc\QcRequests;
use PII\Cms\Write\StalePreview;
use PII\Cms\Write\WriteRefused;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

t_section('QC golden: TestsDataSet.SaveChanges');
foreach (cms_fixture_ids('TestsDataSet.SaveChanges') as $log) {
    $xml = cms_fixture('TestsDataSet.SaveChanges', $log);
    $f   = cms_fixture_parse($xml);
    $tests = [];
    foreach (array_slice($f['steps'], 2) as $s) {
        $tests[] = [
            'id'         => (int) $s['keys']['LocationSampleTest'],
            'version'    => (int) $s['keys']['Version'],
            'result'     => $s['data']['Result'] ?? null,
            'passed'     => $s['data']['Passed'] === 'true',
            'testedTime' => new DateTimeImmutable($s['data']['TestedTime']),
        ];
    }
    $req = QcRequests::saveResults(
        (int) $f['steps'][0]['keys']['SampleSet'], (int) $f['steps'][0]['keys']['Version'],
        (int) $f['steps'][1]['keys']['Location'], (int) $f['steps'][1]['keys']['Version'],
        $tests, $f['env']->user,
    );
    t_xml_same($xml, $req->toXml($f['env']), "log {$log}");
}

foreach (['ReleaseDataSet.Approve' => true, 'ReleaseDataSet.Reject' => false] as $action => $approve) {
    t_section("QC golden: {$action}");
    foreach (cms_fixture_ids($action) as $log) {
        $xml = cms_fixture($action, $log);
        $f   = cms_fixture_parse($xml);
        $s   = $f['steps'];
        $req = QcRequests::disposition(
            $approve,
            (int) $s[0]['keys']['SubLot'], (int) $s[0]['keys']['Version'],
            (int) $s[1]['keys']['Lot'], (int) $s[1]['keys']['Version'],
            (int) $s[2]['keys']['SampleSet'], (int) $s[2]['keys']['Version'],
            new DateTimeImmutable($s[3]['data']['ReleaseDate']),
            $s[3]['data']['ReleasedBy'],
            $approve ? ['ordr' => (int) $s[6]['keys']['Ordr'], 'version' => (int) $s[6]['keys']['Version'],
                        'ordDetail' => (int) $s[7]['data']['OrdDetail']] : null,
        );
        t_xml_same($xml, $req->toXml($f['env']), "log {$log}");
    }
}

/* ── Flows through the one door (CmsWriter) against a fake CMS ── */

/** CMS as it stood just before log 866317 / 866318 (sample set 28751). */
$cmsFor = static function (array $over = [], ?array $tests = null): FakeCmsReader {
    $head = $over + [
        'SampleSet' => 28751, 'Sublot' => 61576, 'BeingTested' => 1, 'SampleSetVersion' => 1, 'IptOrdDetail' => 566200,
        'SublotVersion' => 2, 'Lot' => 261106053, 'LotVersion' => 1, 'ItemCode' => 'E9135', 'ItemDescription' => 'Ink',
        'Ordr' => 194761, 'OrdrVersion' => 5,
    ];
    $tests ??= [
        ['LocationSampleTest' => 77081, 'Location' => 119590, 'Test' => 'DRAWDOWN', 'Version' => 1, 'Result' => null,
         'Passed' => null, 'TestedTime' => null, 'TestedBy' => null, 'TestName' => 'Check appearance', 'TestUnit' => null,
         'TestResultType' => 'BOOL', 'LocationVersion' => 1, 'SpecMin' => null, 'SpecTarget' => null, 'SpecMax' => null],
        ['LocationSampleTest' => 77082, 'Location' => 119590, 'Test' => 'GRIND', 'Version' => 1, 'Result' => null,
         'Passed' => null, 'TestedTime' => null, 'TestedBy' => null, 'TestName' => 'Check grind', 'TestUnit' => null,
         'TestResultType' => 'NUM', 'LocationVersion' => 1, 'SpecMin' => 0, 'SpecTarget' => null, 'SpecMax' => 3],
    ];
    return (new FakeCmsReader())
        ->when('FROM CMS.dbo.SampleSet ss', [$head])
        ->when('FROM CMS.dbo.LocationSampleTest lst', $tests);
};
$writer = static function (FakeCmsReader $cms, $transport, array $perms, string $cmsUser = 'pic\\user1', ?array &$audit = null): CmsWriter {
    $sink = function (array $row) use (&$audit) { $audit[] = $row; };
    return new CmsWriter($cms, $transport, static fn (string $p) => in_array($p, $perms, true), $cmsUser,
        'WS1', true, '7.15.0.680', $sink);
};
$all = ['write.qc.save_results', 'write.qc.approve_sublot', 'write.qc.reject_sublot'];
$normalise = static fn (string $xml): string => preg_replace(
    ['/Application="[^"]+"/', '/TestedTime="[^"]+"/'],
    ['Application="Chemical Management System"', 'TestedTime="T"'], $xml);

t_section('QC flow: save results');
$fake = new FakeTransport();
$now  = new DateTimeImmutable('2026-11-09T12:25:22');
$input = ['sample_set' => 28751, 'results' => [77081 => ['result' => '', 'passed' => '1'], 77082 => ['result' => '2.0', 'passed' => '1']]];
$w = $writer($cmsFor(), $fake, $all);
$preview = $w->preview('qc.save_results', $input, $now);
t_same(0, count($fake->sent), 'preview sends nothing');
$res = $w->execute('qc.save_results', $input, $now, $preview->hash);
t_check($res->ok(), 'committed on the fake transport');
t_xml_same($normalise(cms_fixture('TestsDataSet.SaveChanges', 866317)), $normalise($fake->sent[0]), 'flow output matches log 866317 (bar per-test times)');
t_check(str_contains($fake->sent[0], 'TestedTime="2026-11-09T12:25:22"'), 'tests are stamped with the confirm time');
t_check(str_contains($fake->sent[0], 'Application="Precision Ink Insights"'), 'request is attributed to Insights');

$only = $writer($cmsFor(), new FakeTransport(), $all)->preview('qc.save_results',
    ['sample_set' => 28751, 'results' => [77082 => ['result' => '5', 'passed' => '1']]], $now);
t_same(3, substr_count($only->rendered->stages[0]['xml'], '<action '), 'only entered tests are sent');
t_check($only->plan->warnings !== [] && str_contains($only->plan->warnings[0], 'outside'), 'out-of-spec value marked Pass is flagged');

$refusals = [
    'unknown sample set'    => [(new FakeCmsReader())->when('FROM CMS.dbo.SampleSet ss', []), $input, 'not found'],
    'already dispositioned' => [$cmsFor(['BeingTested' => 0]), $input, 'already been approved or rejected'],
    'nothing entered'       => [$cmsFor(), ['sample_set' => 28751, 'results' => []], 'Nothing to save'],
    'number missing'        => [$cmsFor(), ['sample_set' => 28751, 'results' => [77082 => ['result' => '', 'passed' => '1']]], 'Enter a value'],
    'not a number'          => [$cmsFor(), ['sample_set' => 28751, 'results' => [77082 => ['result' => 'thick', 'passed' => '0']]], 'needs a number'],
    'value without verdict' => [$cmsFor(), ['sample_set' => 28751, 'results' => [77082 => ['result' => '2', 'passed' => '']]], 'Choose Pass or Fail'],
];
foreach ($refusals as $name => [$cms, $in, $needle]) {
    $e = t_throws(WriteRefused::class, fn () => $writer($cms, new FakeTransport(), $all)->preview('qc.save_results', $in, $now));
    t_check($e !== null && str_contains($e->getMessage(), $needle), "refused: {$name}");
}

t_section('QC flow: approve / reject');
$after = ['SampleSetVersion' => 2];   // the save above bumped it
$fake  = new FakeTransport();
$now   = new DateTimeImmutable('2026-11-09T12:25:26');
$res   = $writer($cmsFor($after), $fake, $all)->execute('qc.approve_sublot', ['sample_set' => 28751], $now);
t_check($res->ok(), 'approve committed');
t_xml_same($normalise(cms_fixture('ReleaseDataSet.Approve', 866318)), $normalise($fake->sent[0]), 'approve flow matches log 866318');

$fake = new FakeTransport();
$now  = new DateTimeImmutable('2026-11-04T11:34:23');
$rej  = ['SampleSet' => 28618, 'Sublot' => 60922, 'Lot' => 261020034, 'SampleSetVersion' => 2];
$writer($cmsFor($rej), $fake, $all)->execute('qc.reject_sublot', ['sample_set' => 28618], $now);
t_xml_same($normalise(cms_fixture('ReleaseDataSet.Reject', 863803)), $normalise($fake->sent[0]), 'reject flow matches log 863803');

$p = $writer($cmsFor($after), new FakeTransport(), $all)->preview('qc.approve_sublot', ['sample_set' => 28751], $now);
t_check(count($p->plan->warnings) === 1 && str_contains($p->plan->warnings[0], 'no result yet'), 'approving untested work is flagged');
$e = t_throws(WriteRefused::class, fn () => $writer($cmsFor(['IptOrdDetail' => null, 'Ordr' => null, 'OrdrVersion' => null]), new FakeTransport(), $all)
    ->preview('qc.approve_sublot', ['sample_set' => 28751], $now));
t_check($e !== null, 'approve refused without a batch ticket testing step');
$e = t_throws(WriteRefused::class, fn () => $writer($cmsFor(['BeingTested' => 0]), new FakeTransport(), $all)
    ->preview('qc.reject_sublot', ['sample_set' => 28751], $now));
t_check($e !== null, 'disposition refused twice');

t_section('CmsWriter: allowlist, permission, identity, stale preview, audit');
$e = t_throws(WriteRefused::class, fn () => $writer($cmsFor(), new FakeTransport(), $all)->preview('qc.drop_everything', [], $now));
t_check($e !== null && str_contains($e->getMessage(), 'not an allowed'), 'unlisted operation refused');
$e = t_throws(WriteRefused::class, fn () => $writer($cmsFor(), new FakeTransport(), ['write.qc.save_results'])->preview('qc.approve_sublot', ['sample_set' => 28751], $now));
t_check($e !== null && str_contains($e->getMessage(), 'permission'), 'each operation needs its own permission');
t_check(!$writer($cmsFor(), new FakeTransport(), ['write.qc.save_results'])->allowed('qc.approve_sublot'), 'allowed() mirrors it');
$e = t_throws(WriteRefused::class, fn () => $writer($cmsFor(), new FakeTransport(), $all, '')->preview('qc.save_results', $input, $now));
t_check($e !== null && str_contains($e->getMessage(), 'not linked to a CMS user'), 'no CMS user, no write');

$version = 1;
$moving  = $cmsFor()->when('FROM CMS.dbo.SampleSet ss', function () use (&$version) {
    return [[
        'SampleSet' => 28751, 'Sublot' => 61576, 'BeingTested' => 1, 'SampleSetVersion' => $version, 'IptOrdDetail' => 566200,
        'SublotVersion' => 2, 'Lot' => 261106053, 'LotVersion' => 1, 'ItemCode' => 'E9135', 'ItemDescription' => 'Ink',
        'Ordr' => 194761, 'OrdrVersion' => 5,
    ]];
});
$fake = new FakeTransport();
$w    = $writer($moving, $fake, $all);
$seen = $w->preview('qc.save_results', $input, $now);
$version = 2;   // someone else saved in between
$e = t_throws(StalePreview::class, fn () => $w->execute('qc.save_results', $input, $now, $seen->hash));
t_check($e instanceof StalePreview && $e->fresh->hash !== $seen->hash, 'changed CMS state forces a new preview');
t_same(0, count($fake->sent), 'nothing sent on a stale preview');

$audit = [];
$dry   = $writer($cmsFor(), new DryRunTransport(), $all, 'pic\\user1', $audit);
$res   = $dry->execute('qc.save_results', $input, $now);
t_check($res->dryRun(), 'dry-run transport by default path');
t_same(1, count($audit), 'dry run recorded once');
t_same('qc.save_results', $audit[0]['operation'], 'audit names the operation');
t_same('28751', $audit[0]['entityId'], 'audit names the record');
t_same('dry-run', $audit[0]['status'], 'audit status');
t_check(str_contains((string) $audit[0]['xml'], 'TestsDataSet.SaveChanges'), 'audit keeps the request XML');

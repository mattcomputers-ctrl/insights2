<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\DryRunTransport;
use PII\Cms\Write\Envelope;
use PII\Cms\Write\ErrorMapper;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Request;
use PII\Cms\Write\RunResult;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\SequenceRunner;
use PII\Cms\Write\SequenceState;
use PII\Cms\Write\Step;
use PII\Cms\Write\TransportResult;
use PII\Cms\Write\WriteRefused;

$env = new Envelope('pic\\user1', 'INSIGHTS', new DateTimeImmutable('2026-11-09T12:00:00'));

t_section('Fmt');
t_same('true', Fmt::bool(true), 'bool true');
t_same('false', Fmt::scalar(false), 'bool false');
t_same('', Fmt::scalar(null), 'null is blank');
t_same('72', Fmt::num(72.0), 'whole float');
t_same('20.01', Fmt::num('20.010'), 'trailing zeros');
t_same('0.5', Fmt::num(0.5), 'fraction');
t_same('-5', Fmt::num('-5'), 'negative int string');
t_same('0', Fmt::num(-0.0), 'negative zero');
t_same('2026-11-09T12:25:26', Fmt::dateTime(new DateTimeImmutable('2026-11-09 12:25:26.900')), 'dateTime drops ms');
t_same('2026-11-09T00:00:00', Fmt::date(new DateTimeImmutable('2026-11-09 12:25:26')), 'date is midnight');
t_same('A &amp; B &lt;x&gt; &quot;q&quot;&#x0D;&#x0A;', Fmt::attr("A & B <x> \"q\"\r\n"), 'attr escaping');
t_check(t_throws(InvalidArgumentException::class, fn () => Fmt::num('abc')) !== null, 'non-number refused');

t_section('Request builder');
$r = new Request('Insights QC', 'Demo.Action', 'CMS Program');
$one = $r->add(Step::insert('Thing')->keys(['Thing' => Step::IDENTITY])->data(['Name' => 'A & B', 'Qty' => 1.50, 'On' => true, 'Note' => null]));
$r->add(Step::execute('DoIt')->subs($one, 'Thing', 'Thing')->subs($one, 'Thing', 'Thing', 'keys', 'TableID')->data(['X' => 1]));
$r->add(Step::log('Journal'));
t_same(1, $one, 'add returns step number');
t_same(
    '<request Application="Precision Ink Insights" AppVersion="2.0.0" Program="Insights QC" Action="Demo.Action" Workstation="INSIGHTS" Owner="4" User="pic\user1" TransDate="2026-11-09T12:00:00">'
    . '<action Object="Thing" Step="1" Operation="Insert"><keys Thing="@@identity"/><data Name="A &amp; B" Qty="1.5" On="true" Note=""/></action>'
    . '<action Object="DoIt" Step="2" Operation="Execute"><subs FromStep="1" FromTable="Thing" FromKey="Thing" Section="data"/><subs FromStep="1" FromTable="Thing" FromKey="Thing" Section="keys" ToName="TableID"/><data X="1"/></action>'
    . '<action Object="Journal" Step="3" Operation="Log"/></request>',
    $r->toXml($env),
    'rendered request'
);
$cmsEnv = new Envelope('pic\\user1', 'WS1', new DateTimeImmutable('2026-11-09T12:00:00'), '4', 'X', '1', null, true);
t_check(str_contains($r->toXml($cmsEnv), 'Program="CMS Program"'), 'cmsPrograms switches the Program name');
t_check(str_contains($r->toXml($env->withProgram('Forced')), 'Program="Forced"'), 'program override wins');
t_check(t_throws(InvalidArgumentException::class, fn () => Step::execute('Bad Name; DROP')) !== null, 'object name validated');
t_check(t_throws(InvalidArgumentException::class, fn () => Step::update('T')->data(['bad name' => 1])) !== null, 'attribute name validated');
t_check(t_throws(InvalidArgumentException::class, fn () => Step::update('T')->subs(1, 'T', 'T', 'elsewhere')) !== null, 'subs section validated');
t_check(t_throws(LogicException::class, fn () => (new Request('P', 'A'))->toXml($env)) !== null, 'empty request refused');
t_check(t_throws(InvalidArgumentException::class, fn () => new Envelope('', 'W', new DateTimeImmutable())) !== null, 'envelope needs a user');

t_section('ErrorMapper');
$rt = '<Result Level="16" State="1" Procedure="ProcessXMLRequest" Line="171" Message="Order 186936 is currently locked by pic\x at CS-PC"/>';
t_same('Order 186936 is currently locked by pic\x at CS-PC', ErrorMapper::message($rt), 'message extracted');
t_check(str_contains(ErrorMapper::fromResultText($rt), 'open for editing by pic\x on CS-PC'), 'lock explained');
t_check(str_contains(ErrorMapper::explain("Quantity of the item 'E1234' is negative"), 'Not enough stock'), 'negative stock explained');
t_check(str_contains(ErrorMapper::explain('Something new'), 'Something new'), 'unknown message passed through');
t_check(ErrorMapper::explain('') !== '', 'blank message still says something');

$mk = static function (string $name): Closure {
    return static function (SequenceState $s) use ($name): Request {
        $r = new Request('Insights Test', $name);
        $r->add(Step::execute('Do')->data(['N' => $name]));
        return $r;
    };
};

t_section('SequenceRunner: preview and dry-run');
$seq = (new Sequence('Three'))->stage('one', $mk('A.One'))->stage('two', $mk('A.Two'))->stage('three', $mk('A.Three'));
$audit = [];
$runner = new SequenceRunner(new DryRunTransport(), function (array $row) use (&$audit) { $audit[] = $row; });
$p = $runner->preview($seq, $env);
t_same(3, count($p->stages), 'preview renders every stage');
t_same(RunResult::PREVIEW, $p->stages[0]['status'], 'preview status');
t_same([], $audit, 'preview is not audited');
$d = $runner->run($seq, $env);
t_check($d->dryRun() && !$d->ok(), 'dry run is not "ok" (nothing saved)');
t_same(3, count($audit), 'dry run audits each request');
t_same('dry-run', $audit[0]['mode'], 'audit carries the mode');
t_check(str_contains($d->describe(), 'nothing sent'), 'dry run described');
t_same($p->stages[1]['xml'], $d->stages[1]['xml'] === null ? null : preg_replace('/TransDate="[^"]+"/', 'TransDate="2026-11-09T12:00:00"', $d->stages[1]['xml']), 'dry-run XML equals the preview (bar later-stage timestamps)');

t_section('SequenceRunner: stop on first failure');
$fake = (new FakeTransport());
$fake->willReturn(new TransportResult(TransportResult::COMMITTED, 900001, 0, null, 'ok'));
$fake->willFail('Order 5 is currently locked by pic\a at PC1');
$audit = [];
$res = (new SequenceRunner($fake, function (array $row) use (&$audit) { $audit[] = $row; }))->run($seq, $env);
t_same(['committed', 'failed', 'not-run'], array_column($res->stages, 'status'), 'statuses per stage');
t_same(2, count($fake->sent), 'third request never sent');
t_same([900001], $res->committedLogIds(), 'committed log ids reported');
t_same(2, $res->failure()['no'], 'failure names the stage');
t_check(!$res->ok(), 'run is not ok');
t_check(str_contains($res->describe(), 'Step 2 of 3') && str_contains($res->describe(), '1 earlier step is already saved'), 'description says what committed');
t_same(2, count($audit), 'only sent requests are audited');

t_section('SequenceRunner: resume, ids between stages, refusal');
$done = (new Sequence('Resume'))
    ->stage('one', $mk('A.One'), static fn () => true)
    ->stage('two', static function (SequenceState $s): Request {
        $r = new Request('Insights Test', 'A.Two');
        $r->add(Step::insert('Waybill')->keys(['Waybill' => Step::IDENTITY]));
        return $r;
    })
    ->stage('three', static function (SequenceState $s): Request {
        $r = new Request('Insights Test', 'A.Three');
        $r->add(Step::update('Waybill')->keys(['Waybill' => $s->output(2, 1, 'Waybill', 'Waybill')]));
        return $r;
    });
$fake = new FakeTransport();
$res  = (new SequenceRunner($fake))->run($done, $env);
t_same(['skipped', 'committed', 'committed'], array_column($res->stages, 'status'), 'done stage skipped');
t_check($res->ok(), 'skipped + committed is ok');
t_check(str_contains($fake->sent[1], 'Waybill="5000001"'), 'later stage uses the id the earlier one produced');
t_check(str_contains((new SequenceRunner($fake))->preview($done, $env)->stages[2]['xml'], '{stage 2: new Waybill}'), 'preview shows a placeholder for a future id');

$refuse = (new Sequence('Refuse'))->stage('one', $mk('A.One'))
    ->stage('two', static fn () => throw new WriteRefused('The order changed.'))
    ->stage('three', $mk('A.Three'));
$fake = new FakeTransport();
$res  = (new SequenceRunner($fake))->run($refuse, $env);
t_same(['committed', 'failed', 'not-run'], array_column($res->stages, 'status'), 'refusal while building halts the run');
t_same(1, count($fake->sent), 'nothing sent after a refusal');

t_section('SequenceRunner: later stages are stamped when sent');
$tick = new DateTimeImmutable('2026-11-09T12:00:07');
$fake = new FakeTransport();
(new SequenceRunner($fake, null, static fn () => $tick))->run($seq, $env);
t_check(str_contains($fake->sent[0], 'TransDate="2026-11-09T12:00:00"'), 'first stage keeps the confirmed time');
t_check(str_contains($fake->sent[1], 'TransDate="2026-11-09T12:00:07"'), 'second stage uses the clock');

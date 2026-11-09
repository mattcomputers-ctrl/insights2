<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\AuditSink;
use PII\Cms\Write\DryRunTransport;
use PII\Cms\Write\Envelope;
use PII\Cms\Write\ErrorMapper;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\LiveTransport;
use PII\Cms\Write\PendingWrites;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Request;
use PII\Cms\Write\RunResult;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\SequenceRunner;
use PII\Cms\Write\SequenceState;
use PII\Cms\Write\Step;
use PII\Cms\Write\Transport;
use PII\Cms\Write\TransportResult;
use PII\Cms\Write\WriteAudit;
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
$rt = '<Result Level="16" State="1" Procedure="ProcessXMLRequest" Line="171" Message="Order 186936 is currently locked by pic\x at WS1"/>';
t_same('Order 186936 is currently locked by pic\x at WS1', ErrorMapper::message($rt), 'message extracted');
t_check(str_contains(ErrorMapper::fromResultText($rt), 'open for editing by pic\x on WS1'), 'lock explained');
t_check(str_contains(ErrorMapper::explain("Quantity of the item 'E1234' is negative"), 'Not enough stock'), 'negative stock explained');
t_check(str_contains(ErrorMapper::explain("Ordr ([Ordr] = '115517') has changed since last read"), 'Someone else saved'), 'stale version explained');
t_check(str_contains(ErrorMapper::explain("Ordr ([Ordr] = '0') does not exist"), 'Ordr 0 no longer exists'), 'missing row explained');
t_check(str_contains(ErrorMapper::explain('ChangeSet 20878 already billed'), 'supplier bill'), 'billed receipt explained');
t_check(str_contains(ErrorMapper::explain("Insufficient inventory for item(s) 'A,B'"), 'Not enough stock to commit: A,B'), 'insufficient inventory explained');
t_check(str_contains(ErrorMapper::explain("Cannot insert duplicate key row in object 'dbo.Item' with unique index 'IX_Item_ItemCode'. The duplicate key value is (X1)."), 'Item code X1 is already in use'), 'duplicate item code explained');
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

t_section('Request: warning notes and start/end stamps');
$noteEnv = new Envelope('pic\user1', 'WS1', new DateTimeImmutable('2026-11-09T12:00:07'));
$note    = (new Request('Insights Test', 'X.Y'))->span(true, 3)
    ->event(['Method' => 'Plain'])
    ->event(['Method' => 'Empty'], '')
    ->event(['Method' => 'Text'], 'a < b & c', ['Kind' => 'MFBA']);
$note->add(Step::execute('Thing')->data(['A' => '1']));
$noteXml = $note->toXml($noteEnv);
t_check(str_contains($noteXml, 'TransDate="2026-11-09T12:00:07" StartDate="2026-11-09T12:00:04" EndDate="2026-11-09T12:00:07">'),
    'start and end follow the transaction date');
t_check(str_contains($noteXml, '<event Method="Plain"/><event Method="Empty"><Memo/></event>'
    . '<event Method="Text"><Memo>a &lt; b &amp; c</Memo><properties Kind="MFBA"/></event><action '),
    'notes render before the steps, with memo and properties');
$open = (new Request('Insights Test', 'X.Y'))->span(false);
$open->add(Step::execute('Thing'));
t_check(str_contains($open->toXml($noteEnv), 'StartDate="2026-11-09T12:00:07">'), 'a request can carry a start with no end');
t_check(t_throws(\InvalidArgumentException::class, static fn () => (new Request('P', 'A'))->span(true, -1)) !== null,
    'a start after the transaction date is refused');
t_check(t_throws(\InvalidArgumentException::class, static fn () => (new Request('P', 'A'))->event(['M' => 'x'], null, ['bad name' => 'y'])) !== null,
    'a malformed property name is refused');

// ---------------------------------------------------------------------
// The runner always answers, and the audit row comes before the send
// ---------------------------------------------------------------------

if (!class_exists('FdAuditRecorder', false)) {
    /** Audit sink that notes the order of things and can be told to break. */
    final class FdAuditRecorder implements AuditSink
    {
        /** @var list<string> "begin N" / "send N" / "finish N" in the order they happened */
        public array $events = [];
        /** @var array<int,array> handle => row as first written */
        public array $intent = [];
        /** @var array<int,array> handle => row as last updated */
        public array $rows = [];
        public ?int $failBeginAt = null;
        public ?int $failFinishAt = null;

        public function begin(array $row): int|string
        {
            if ($this->failBeginAt === $row['no']) {
                throw new RuntimeException('MySQL server has gone away');
            }
            $this->events[] = 'begin ' . $row['no'];
            $id = count($this->intent) + 1;
            $this->intent[$id] = $row;
            $this->rows[$id]   = $row;
            return $id;
        }

        public function finish(int|string $handle, array $row): void
        {
            if ($this->failFinishAt === $row['no']) {
                throw new RuntimeException('Lock wait timeout exceeded');
            }
            $this->events[] = 'finish ' . $row['no'];
            $this->rows[(int) $handle] = $row;
        }
    }

    /** Transport that notes each send in the recorder's event list, then answers like the fake. */
    final class FdOrderedTransport implements Transport
    {
        public FakeTransport $inner;
        /** @var list<array> the meta of every send */
        public array $metas = [];
        public ?int $throwAt = null;

        public function __construct(private readonly FdAuditRecorder $rec, private readonly string $mode = 'fake')
        {
            $this->inner = new FakeTransport();
        }

        public function mode(): string
        {
            return $this->mode;
        }

        public function send(string $xml, array $meta): TransportResult
        {
            $n = count($this->metas) + 1;
            $this->metas[]       = $meta;
            $this->rec->events[] = 'send ' . $n;
            if ($this->throwAt === $n) {
                throw new RuntimeException('connection reset by peer');
            }
            return $this->mode === 'dry-run'
                ? (new DryRunTransport())->send($xml, $meta)
                : $this->inner->send($xml, $meta);
        }

        public function generateId(string $kind, int $min, int $max): ?string
        {
            return $this->inner->generateId($kind, $min, $max);
        }
    }

    /** Stand-in for the CMS write connection: answers each statement from a closure. */
    final class FdStubPdo extends \PDO
    {
        /** @var list<array{0:string,1:array}> every statement run, with its parameters */
        public array $log = [];

        /** @param \Closure(string,array):list<array<string,mixed>> $answer may throw PDOException */
        public function __construct(public \Closure $answer) {}

        /** What lastInsertId() answers (the Insights database stand-in). */
        public string $insertId = '0';

        public function lastInsertId(?string $name = null): string|false
        {
            return $this->insertId;
        }

        public function prepare(string $query, array $options = []): \PDOStatement|false
        {
            return new FdStubStatement($this, $query);
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
        {
            $stmt = new FdStubStatement($this, $query);
            $stmt->execute();
            return $stmt;
        }
    }

    final class FdStubStatement extends \PDOStatement
    {
        /** @var list<array<string,mixed>> */
        private array $rows = [];

        public function __construct(private readonly FdStubPdo $pdo, private readonly string $sql) {}

        public function execute(?array $params = null): bool
        {
            $this->pdo->log[] = [$this->sql, $params ?? []];
            $this->rows = ($this->pdo->answer)($this->sql, $params ?? []);
            return true;
        }

        public function columnCount(): int
        {
            return $this->rows === [] ? 0 : count($this->rows[0]);
        }

        public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            $rows = $this->rows;
            $this->rows = [];
            return $rows;
        }

        public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        {
            return $this->rows === [] ? false : array_shift($this->rows);
        }

        public function fetchColumn(int $column = 0): mixed
        {
            $row = $this->fetch();
            return $row === false ? false : array_values($row)[$column];
        }

        public function nextRowset(): bool
        {
            return false;
        }
    }
}

t_section('SequenceRunner: the audit row is written before the send');
$fdRec = new FdAuditRecorder();
$fdTr  = new FdOrderedTransport($fdRec);
$fdRes = (new SequenceRunner($fdTr, $fdRec, null, ['operation' => 'demo.op', 'input' => ['Qty' => '5']]))->run($seq, $env);
t_check($fdRes->ok(), 'three requests committed');
t_same(['begin 1', 'send 1', 'finish 1', 'begin 2', 'send 2', 'finish 2', 'begin 3', 'send 3', 'finish 3'], $fdRec->events,
    'each request is recorded, then sent, then its outcome recorded');
t_same(3, count($fdRec->rows), 'one audit row per request');
t_same(SequenceRunner::SENDING, $fdRec->intent[1]['status'], 'the row is written as "sending"');
t_check(str_contains((string) $fdRec->intent[2]['xml'], 'Action="A.Two"'), 'the intent row already holds the request XML');
t_same(['Qty' => '5'], $fdRec->intent[1]['input'], 'the intent row carries what the user entered');
t_same('demo.op', $fdRec->intent[1]['operation'], 'and the operation');
t_same($fdRes->runId, $fdRec->intent[3]['runId'], 'and the run id');
t_same('fake', $fdRec->intent[1]['mode'], 'and the mode');
t_same('committed', $fdRec->rows[1]['status'], 'the same row is updated with the outcome');
t_same($fdRes->stages[0]['logId'], $fdRec->rows[1]['logId'], 'and the CMS log id');
t_same('pic\\user1', $fdTr->metas[0]['user'], 'the transport is told the CMS user');
t_check($fdRes->attempted() && $fdRes->auditGap() === null, 'the run counts as sent, with a complete audit');

t_section('SequenceRunner: no audit row, no send');
$fdRec = new FdAuditRecorder();
$fdRec->failBeginAt = 2;
$fdTr  = new FdOrderedTransport($fdRec);
$fdRes = (new SequenceRunner($fdTr, $fdRec))->run($seq, $env);
t_same(['committed', 'failed', 'not-run'], array_column($fdRes->stages, 'status'), 'the run stops at the request that could not be recorded');
t_same(1, count($fdTr->metas), 'that request was not sent, nor the one after it');
t_check(str_contains($fdRes->stages[1]['message'], 'was not sent') && str_contains($fdRes->stages[1]['message'], 'gone away'), 'the step says why it was not sent');
t_check(str_contains($fdRes->describe(), 'Step 2 of 3') && str_contains($fdRes->describe(), '1 earlier step is already saved in CMS (log 900001)'),
    'the report names the saved request');
$fdRec = new FdAuditRecorder();
$fdRec->failBeginAt = 1;
$fdTr  = new FdOrderedTransport($fdRec);
$fdRes = (new SequenceRunner($fdTr, $fdRec))->run($seq, $env);
t_check(!$fdRes->attempted() && $fdTr->metas === [], 'when the first request cannot be recorded nothing is sent at all');
t_check(str_contains($fdRes->describe(), 'Nothing was saved.'), 'and the report says so');

t_section('SequenceRunner: outcome not recorded after a send');
$fdRec = new FdAuditRecorder();
$fdRec->failFinishAt = 1;
$fdTr  = new FdOrderedTransport($fdRec);
$fdRes = (new SequenceRunner($fdTr, $fdRec))->run($seq, $env);
t_same(['committed', 'not-run', 'not-run'], array_column($fdRes->stages, 'status'), 'the saved request stays reported as saved and the run stops');
t_same(1, count($fdTr->metas), 'nothing is sent after it');
t_check(!$fdRes->ok() && $fdRes->attempted() && $fdRes->failure() === null, 'not ok, sent, and no CMS failure');
t_same(1, $fdRes->auditGap()['no'], 'the stage with the incomplete audit is named');
t_same([900001], $fdRes->committedLogIds(), 'its log id is reported');
t_same(SequenceRunner::SENDING, $fdRec->rows[1]['status'], 'the intent row is still there as evidence');
t_check(str_contains($fdRes->describe(), 'Step 1 of 3 (one) is saved in CMS (log 900001), but Insights could not record the outcome')
    && str_contains($fdRes->describe(), 'The remaining 2 steps were not run') && !str_contains($fdRes->describe(), 'Nothing was'),
    'the report says it is saved, that the log is incomplete and that the rest did not run: ' . $fdRes->describe());
$fdRec = new FdAuditRecorder();
$fdRec->failFinishAt = 3;
$fdRes = (new SequenceRunner(new FdOrderedTransport($fdRec), $fdRec))->run($seq, $env);
t_check(!$fdRes->ok() && count($fdRes->committedLogIds()) === 3 && str_contains($fdRes->describe(), 'Saved so far: log 900001, 900002, 900003'),
    'a gap on the last request still lists everything saved: ' . $fdRes->describe());

t_section('SequenceRunner: an exception at any point still gives a report');
// The "already done" check of a later stage breaks (CMS not readable between requests).
$fdSeq = (new Sequence('Broken check'))->stage('one', $mk('A.One'))
    ->stage('two', $mk('A.Two'), static fn () => throw new RuntimeException('SQLSTATE[08S01]: Communication link failure'))
    ->stage('three', $mk('A.Three'));
$fake  = new FakeTransport();
$audit = [];
$fdRes = (new SequenceRunner($fake, function (array $row) use (&$audit) { $audit[] = $row; }))->run($fdSeq, $env);
t_same(['committed', 'failed', 'not-run'], array_column($fdRes->stages, 'status'), 'a broken check stops the run with a report');
t_same(1, count($fake->sent), 'nothing sent after it');
t_check($fdRes->attempted(), 'the run counts as sent');
t_same([900001], $fdRes->committedLogIds(), 'the committed request is still reported');
t_check(str_contains($fdRes->describe(), 'Step 2 of 3 (two)') && str_contains($fdRes->describe(), 'Communication link failure')
    && str_contains($fdRes->describe(), '1 earlier step is already saved in CMS (log 900001)') && !str_contains($fdRes->describe(), 'Nothing was'),
    'the report says which step failed, why, and what is saved: ' . $fdRes->describe());
t_same(1, count($audit), 'only the sent request is audited');

// The build of a later stage breaks with something other than a refusal.
$fdSeq = (new Sequence('Broken build'))->stage('one', $mk('A.One'))
    ->stage('two', static fn () => throw new LogicException('Paused for the nightly backup.'))
    ->stage('three', $mk('A.Three'));
$fake  = new FakeTransport();
$fdRes = (new SequenceRunner($fake))->run($fdSeq, $env);
t_same(['committed', 'failed', 'not-run'], array_column($fdRes->stages, 'status'), 'a broken build stops the run with a report');
t_check(str_contains($fdRes->stages[1]['message'], 'was not sent (Paused for the nightly backup)'), 'the step says it was not sent and why');
t_same([1, 1], [count($fake->sent), count($fdRes->committedLogIds())], 'one sent, one saved');

// The same before anything was sent.
$fdSeq = (new Sequence('Broken first'))
    ->stage('one', static fn () => throw new RuntimeException('no connection'))
    ->stage('two', $mk('A.Two'));
$fake  = new FakeTransport();
$fdRes = (new SequenceRunner($fake))->run($fdSeq, $env);
t_same(['failed', 'not-run'], array_column($fdRes->stages, 'status'), 'a break in the first stage is reported too');
t_check(!$fdRes->attempted() && $fake->sent === [], 'and only then does the run count as "nothing sent"');
t_check(str_contains($fdRes->describe(), 'Nothing was saved.'), 'which the report says');

// The transport itself throws.
$fdRec = new FdAuditRecorder();
$fdTr  = new FdOrderedTransport($fdRec);
$fdTr->throwAt = 2;
$fdRes = (new SequenceRunner($fdTr, $fdRec))->run($seq, $env);
t_same(['committed', 'unknown', 'not-run'], array_column($fdRes->stages, 'status'), 'a transport that breaks leaves the stage unknown');
t_same(2, count($fdTr->metas), 'nothing sent after it');
t_same('unknown', $fdRec->rows[2]['status'], 'the audit row records the unknown outcome');
t_check($fdRes->attempted() && str_contains($fdRes->describe(), 'may or may not have been saved')
    && str_contains($fdRes->describe(), '1 earlier step is already saved in CMS (log 900001)') && !str_contains($fdRes->describe(), 'Nothing was'),
    'the report does not claim nothing was saved: ' . $fdRes->describe());
$fdRec = new FdAuditRecorder();
$fdTr  = new FdOrderedTransport($fdRec);
$fdTr->throwAt = 1;
$fdRes = (new SequenceRunner($fdTr, $fdRec))->run($seq, $env);
t_check($fdRes->attempted() && !str_contains($fdRes->describe(), 'Nothing was saved'),
    'unknown on the first request is never "nothing was saved": ' . $fdRes->describe());

// A plain closure sink that throws after the send.
$fake  = new FakeTransport();
$fdRes = (new SequenceRunner($fake, static fn (array $row) => throw new RuntimeException('disk full')))->run($seq, $env);
t_same(['committed', 'not-run', 'not-run'], array_column($fdRes->stages, 'status'), 'a failing closure sink stops the run after the saved request');
t_check($fdRes->auditGap() !== null && count($fake->sent) === 1, 'and it is reported as an audit gap');

t_section('SequenceRunner: dry run with the two-step audit');
$fdRec = new FdAuditRecorder();
$fdTr  = new FdOrderedTransport($fdRec, 'dry-run');
$fdRes = (new SequenceRunner($fdTr, $fdRec))->run($seq, $env);
t_check($fdRes->dryRun() && !$fdRes->attempted(), 'a dry run never counts as sent');
t_same(3, count($fdRec->rows), 'a dry run keeps one row per request');
t_same(['dry-run', 'dry-run', 'dry-run'], array_column($fdRec->rows, 'status'), 'each ends as dry-run');
t_check(str_contains($fdRes->describe(), '3 requests prepared and recorded, nothing sent'), 'described as before');
$fdRec = new FdAuditRecorder();
$fdRec->failBeginAt = 2;
$fdRes = (new SequenceRunner(new FdOrderedTransport($fdRec, 'dry-run'), $fdRec))->run($seq, $env);
t_check(str_contains($fdRes->describe(), 'Dry run stopped at step 2 of 3') && str_contains($fdRes->describe(), 'Nothing was sent to CMS'),
    'a dry run that could not be recorded says so: ' . $fdRes->describe());

t_section('LiveTransport: finding the log row when the call errors');
$fdMeta = ['application' => 'Precision Ink Insights', 'action' => 'A.One', 'workstation' => 'INSIGHTS',
           'transDate' => '2026-11-09T12:00:00', 'user' => 'pic\\user1'];
$fdCms = static function (int $matches, ?int $logId): FdStubPdo {
    return new FdStubPdo(static function (string $sql, array $params) use ($matches, $logId): array {
        if (str_contains($sql, 'ProcessXMLRequest')) {
            throw new PDOException('SQLSTATE[42000]: Quantity of the item is negative');
        }
        if (str_contains($sql, 'COUNT(*) AS Matches')) {
            return [['Matches' => $matches, 'LogID' => $logId]];
        }
        if (str_contains($sql, 'SELECT MAX([Log])')) {
            return [['m' => 700000]];
        }
        if (str_contains($sql, 'FROM dbo.[Log] l')) {
            return [['ResultCode' => 50000, 'ResultText' => '<Result Message="Quantity of the item is negative"/>', 'Completed' => 0]];
        }
        return [];
    });
};
$fdPdo = $fdCms(1, 700001);
$fdOut = (new LiveTransport($fdPdo))->send('<request/>', $fdMeta);
t_same(['failed', 700001, 50000], [$fdOut->status, $fdOut->logId, $fdOut->resultCode], 'one matching row is taken as this request');
$fdFind = array_values(array_filter($fdPdo->log, static fn (array $q) => str_contains($q[0], 'AS Matches')))[0];
t_check(str_contains($fdFind[0], 'AND LoggedInUser = ?'), 'the lookup filters on the CMS user');
t_same([700000, 'Precision Ink Insights', 'A.One', 'INSIGHTS', '2026-11-09 12:00:00', 'pic\\user1'], $fdFind[1], 'with the envelope values and the user');
$fdOut = (new LiveTransport($fdCms(2, 700002)))->send('<request/>', $fdMeta);
t_same(['unknown', null], [$fdOut->status, $fdOut->logId], 'two matching rows: unknown, no guess');
t_check(str_contains($fdOut->message, '2 log rows could be this request'), 'and the message says why');
$fdOut = (new LiveTransport($fdCms(0, null)))->send('<request/>', $fdMeta);
t_same('unknown', $fdOut->status, 'no matching row: unknown');
$fdPdo = $fdCms(1, 700001);
$fdOut = (new LiveTransport($fdPdo))->send('<request/>', ['user' => ''] + $fdMeta);
t_same('unknown', $fdOut->status, 'no user to match on: unknown');
t_same([], array_filter($fdPdo->log, static fn (array $q) => str_contains($q[0], 'AS Matches')), 'and no lookup without the user');

$fdPdo = new FdStubPdo(static fn (string $sql, array $params): array => throw new PDOException('SQLSTATE[08001]: no route to host'));
$fdOut = (new LiveTransport($fdPdo))->send('<request/>', $fdMeta);
t_same('failed', $fdOut->status, 'a connection that is down before the call: not sent');
t_check(str_contains($fdOut->message, 'was not sent') && count($fdPdo->log) === 1, 'the procedure was never called');

$fdPdo = new FdStubPdo(static function (string $sql, array $params): array {
    if (str_contains($sql, 'ProcessXMLRequest')) {
        return [['LogID' => 700005]];
    }
    if (str_contains($sql, 'SELECT MAX([Log])')) {
        return [['m' => 700000]];
    }
    throw new PDOException('SQLSTATE[08S01]: connection lost');
});
$fdOut = (new LiveTransport($fdPdo))->send('<request/>', $fdMeta);
t_same(['unknown', 700005], [$fdOut->status, $fdOut->logId], 'a log id but no readable outcome: unknown, with the id');

t_section('SequenceRunner: a request the transport says never left');
// The write connection is down before the call: the stage failed and counts as not sent.
$fdPdo = new FdStubPdo(static fn (string $sql, array $params): array => throw new PDOException('SQLSTATE[08001]: no route to host'));
$fdRec = new FdAuditRecorder();
$fdRes = (new SequenceRunner(new LiveTransport($fdPdo), $fdRec))->run($seq, $env);
t_same(['failed', 'not-run', 'not-run'], array_column($fdRes->stages, 'status'), 'the run stops at the first request');
t_check($fdRes->stages[0]['sent'] === false && !$fdRes->attempted(), 'the stage and the run count as not sent');
t_same('failed', $fdRec->rows[1]['status'], 'the audit row records it as failed');
t_check(str_contains($fdRes->describe(), 'was not sent') && str_contains($fdRes->describe(), 'Nothing was saved.'), 'and the report says so: ' . $fdRes->describe());
// A request CMS rejected did leave.
$fake  = (new FakeTransport())->willFail('Order 5 is currently locked by pic\a at PC1');
$fdRes = (new SequenceRunner($fake))->run($seq, $env);
t_check($fdRes->stages[0]['sent'] === true && $fdRes->attempted(), 'a request CMS rejected counts as sent');
// The call itself errors after the high-water mark: sent, whatever came of it.
$fdRes = (new SequenceRunner(new LiveTransport($fdCms(0, null))))->run($seq, $env);
t_check($fdRes->stages[0]['status'] === 'unknown' && $fdRes->attempted(), 'a call that errored counts as sent');

t_section('WriteAudit: the row before the send and the outcome on the same row');
// The Insights database stand-in: remembers the status the last UPDATE set.
$fdDb = static function (string $insertId = '41', bool $updateTakes = true): FdStubPdo {
    $status = SequenceRunner::SENDING;
    $pdo = new FdStubPdo(static function (string $sql, array $params) use (&$status, $updateTakes): array {
        if (str_starts_with($sql, 'UPDATE') && $updateTakes) {
            $status = $params[0];
        }
        if (str_starts_with($sql, 'SELECT status')) {
            return [['status' => $status]];
        }
        return [];
    });
    $pdo->insertId = $insertId;
    return $pdo;
};
$fdRow = ['no' => 2, 'name' => 'two', 'status' => SequenceRunner::SENDING, 'xml' => '<request Action="A.Two"/>', 'action' => 'A.Two',
          'logId' => null, 'resultCode' => null, 'resultText' => null, 'message' => 'Recorded before sending.',
          'runId' => 'abcdef0123456789', 'mode' => 'live', 'operation' => 'demo.op', 'entityType' => 'Order', 'entityId' => '194761',
          'cmsUser' => 'pic\\user1', 'input' => ['qty' => '5', 'note' => 'café / "x"']];
$fdPdo = $fdDb();
$fdH   = (new WriteAudit(7, $fdPdo))->begin($fdRow);
t_same(41, $fdH, 'begin hands back the new row id');
t_same(1, count($fdPdo->log), 'begin is one statement');
t_check(preg_match('/^INSERT INTO cms_write_audit \(`([a-z_`, ]+)`\) VALUES \(\?(, \?)*\)$/', $fdPdo->log[0][0], $fdM) === 1, 'and it is an insert');
$fdCols = explode('`, `', $fdM[1] ?? '');
t_same(count($fdCols), count($fdPdo->log[0][1]), 'one value per column');
$fdIns = array_combine($fdCols, $fdPdo->log[0][1]);
t_same(['abcdef0123456789', 7, 'pic\\user1', 'demo.op', 2, 'two', 'live', 'A.Two', '<request Action="A.Two"/>', 'sending', 'Order', '194761'],
    [$fdIns['run_id'], $fdIns['user_id'], $fdIns['cms_user'], $fdIns['operation'], $fdIns['stage_no'], $fdIns['stage_name'], $fdIns['mode'],
     $fdIns['cms_action'], $fdIns['request_xml'], $fdIns['status'], $fdIns['entity_type'], $fdIns['entity_id']],
    'who, the CMS user, the operation, the step, the mode, the request and the record are on the row');
t_same('{"qty":"5","note":"café / \"x\""}', $fdIns['input_json'], 'what the user entered is stored as JSON');

$fdOutRow = ['status' => 'failed', 'logId' => 700001, 'resultCode' => 50000, 'resultText' => '<Result Message="No"/>', 'message' => 'CMS said no.'] + $fdRow;
(new WriteAudit(7, $fdPdo))->finish($fdH, $fdOutRow);
t_check(preg_match('/^UPDATE cms_write_audit\s+SET (.+?)\s+WHERE id = \?$/s', $fdPdo->log[1][0], $fdM) === 1, 'finish updates that row');
t_same(['failed', 'CMS said no.', 700001, 50000, '<Result Message="No"/>', 41], $fdPdo->log[1][1], 'with the status, message, log id, result and the row id');
t_check(str_contains($fdPdo->log[2][0], 'SELECT status FROM cms_write_audit WHERE id = ?') && $fdPdo->log[2][1] === [41], 'and reads the row back');

// Every column the two statements name exists in the migrations.
$fdSchema = (string) file_get_contents(PII_TEST_ROOT . '/migrations/021_cms_write.sql')
          . (string) file_get_contents(PII_TEST_ROOT . '/migrations/022_cms_write_audit_intent.sql');
preg_match_all('/(\w+) = /', $fdM[1] ?? '', $fdSet);
$fdMissing = [];
foreach (array_merge($fdCols, $fdSet[1], ['id']) as $fdCol) {
    if (!preg_match('/^\s+' . $fdCol . '\s+[A-Z]/m', $fdSchema) && !preg_match('/ADD COLUMN IF NOT EXISTS ' . $fdCol . ' /', $fdSchema)) {
        $fdMissing[] = $fdCol;
    }
}
t_same([], $fdMissing, 'every column written is in migration 021 or 022');
t_same(6, count($fdSet[1]), 'the update sets six columns');

// Values are cut to the column widths.
$fdPdo = $fdDb();
$fdH   = (new WriteAudit(0, $fdPdo))->begin(['operation' => str_repeat('o', 200), 'action' => str_repeat('a', 200), 'entityId' => str_repeat('9', 200),
    'name' => str_repeat('n', 300), 'message' => str_repeat('é', 1500), 'cmsUser' => str_repeat('u', 150)] + $fdRow);
$fdIns = array_combine($fdCols, $fdPdo->log[0][1]);
t_same([80, 120, 60, 190, 1000, 100],
    [strlen($fdIns['operation']), strlen($fdIns['cms_action']), strlen($fdIns['entity_id']), strlen($fdIns['stage_name']), mb_strlen($fdIns['message']), strlen($fdIns['cms_user'])],
    'long values are cut to their columns');
t_same(null, $fdIns['user_id'], 'no Insights user: the user column is empty');
(new WriteAudit(0, $fdPdo))->finish($fdH, ['status' => 'failed', 'resultText' => str_repeat('é', 40000), 'message' => str_repeat('m', 1200)] + $fdOutRow);
t_check(strlen($fdPdo->log[1][1][4]) === 60000 && mb_check_encoding($fdPdo->log[1][1][4], 'UTF-8') && strlen($fdPdo->log[1][1][1]) === 1000,
    'the result text is cut to fit, on a whole character, and so is the message');
unset($fdRow['input']);
$fdPdo = $fdDb();
(new WriteAudit(7, $fdPdo))->begin($fdRow);
t_same(null, array_combine($fdCols, $fdPdo->log[0][1])['input_json'], 'no input given: the column is empty');
$fdRow['input'] = ['qty' => '5'];

// The sink refuses to pretend.
$e = t_throws(RuntimeException::class, static fn () => (new WriteAudit(7, $fdDb('0')))->begin($fdRow));
t_check($e !== null && str_contains($e->getMessage(), 'row id'), 'no row id back from the insert: begin throws');
$e = t_throws(RuntimeException::class, static fn () => (new WriteAudit(7, $fdDb('41', false)))->finish(41, $fdOutRow));
t_check($e !== null && str_contains($e->getMessage(), 'could not be updated'), 'a row still at "sending" after the update: finish throws');
$fdPdo = new FdStubPdo(static fn (string $sql, array $params): array => []);
$e = t_throws(RuntimeException::class, static fn () => (new WriteAudit(7, $fdPdo))->finish(41, $fdOutRow));
t_check($e !== null, 'a row that is gone: finish throws');
$e = t_throws(PDOException::class, static fn () => (new WriteAudit(7, new FdStubPdo(
    static fn (string $sql, array $params): array => throw new PDOException("SQLSTATE[42S22]: Unknown column 'input_json'"))))->begin($fdRow));
t_check($e !== null, 'a database error in begin is passed on');

// Through the runner: the real sink in front of a transport.
$fdPdo = $fdDb();
$fake  = new FakeTransport();
$fdRes = (new SequenceRunner($fake, new WriteAudit(7, $fdPdo), null, ['operation' => 'demo.op', 'cmsUser' => 'pic\\user1', 'input' => ['qty' => '5']]))
    ->run((new Sequence('One'))->stage('one', $mk('A.One')), $env);
t_check($fdRes->ok() && $fdRes->auditGap() === null, 'a run with the real sink is complete');
t_same(['INSERT', 'UPDATE', 'SELECT'], array_map(static fn (array $q) => substr($q[0], 0, 6), $fdPdo->log), 'insert, then update, then the read-back');
t_same(['committed', 900001], [$fdPdo->log[1][1][0], $fdPdo->log[1][1][2]], 'the outcome and the CMS log id go on the row');
$fake  = new FakeTransport();
$fdRes = (new SequenceRunner($fake, new WriteAudit(7, new FdStubPdo(
    static fn (string $sql, array $params): array => throw new PDOException("SQLSTATE[42S22]: Unknown column 'input_json'")))))
    ->run((new Sequence('One'))->stage('one', $mk('A.One')), $env);
t_check($fake->sent === [] && !$fdRes->attempted() && str_contains($fdRes->describe(), 'was not sent'),
    'with the write log table not writable nothing is sent: ' . $fdRes->describe());

t_section('PendingWrites: a confirmation is used up when it is taken');
$fdSession = $_SESSION ?? null;
$_SESSION  = [];
$fdTok  = PendingWrites::put('demo.op', ['qty' => '5'], new DateTimeImmutable('2026-11-09T12:00:00'), 'hash-1', '/orders/5');
$fdPend = PendingWrites::take($fdTok);
t_same(['demo.op', ['qty' => '5'], 'hash-1', '/orders/5', '2026-11-09T12:00:00'],
    [$fdPend['op'], $fdPend['input'], $fdPend['hash'], $fdPend['return'], $fdPend['now']->format('Y-m-d\TH:i:s')], 'take hands out the pending write');
t_check(PendingWrites::get($fdTok) === null && PendingWrites::take($fdTok) === null, 'and the token is gone: a second confirm finds nothing');
PendingWrites::putBack($fdTok, $fdPend, 'hash-2');
$fdAgain = PendingWrites::get($fdTok);
t_same(['demo.op', ['qty' => '5'], 'hash-2', '/orders/5', $fdPend['created']],
    [$fdAgain['op'], $fdAgain['input'], $fdAgain['hash'], $fdAgain['return'], $fdAgain['created']], 'put back (nothing was sent): confirmable again, same age, new fingerprint');
t_same($fdPend['now']->format('c'), $fdAgain['now']->format('c'), 'with the same moment of action');
PendingWrites::putBack($fdTok, ['created' => time() - 1000] + $fdPend);
t_same(null, PendingWrites::get($fdTok), 'putting back does not make an expired page young again');
t_same(null, PendingWrites::take('no-such-token'), 'an unknown token gives nothing');
if ($fdSession === null) {
    unset($_SESSION);
} else {
    $_SESSION = $fdSession;
}

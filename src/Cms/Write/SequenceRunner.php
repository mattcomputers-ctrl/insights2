<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Runs a Sequence one request at a time: build (fresh Versions), record
 * the intent, send, record the outcome, and stop at the first request
 * that does not commit.
 *
 * run() never throws. Whatever goes wrong, at any stage, the caller gets
 * a RunResult that says which requests were saved, which stage stopped
 * the run and why.
 */
final class SequenceRunner
{
    /** Audit status of a request that is recorded but whose outcome is not in yet. */
    public const SENDING = 'sending';

    /** @var \Closure():\DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param null|AuditSink|\Closure(array):void $audit an AuditSink is told before and after each send;
     *        a plain Closure (tests) receives one row per request, after it was sent
     * @param null|\Closure():\DateTimeImmutable $clock
     * @param array<string,mixed> $auditContext added to every audit row (operation, record, input)
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly AuditSink|\Closure|null $audit = null,
        ?\Closure $clock = null,
        private readonly array $auditContext = [],
    ) {
        $this->clock = $clock ?? static fn () => new \DateTimeImmutable('now');
    }

    /**
     * Render every stage without sending anything. Deterministic for a
     * given Envelope and CMS state, which is what lets the confirm step
     * detect that CMS changed after the preview was shown.
     */
    public function preview(Sequence $sequence, Envelope $env): RunResult
    {
        $state  = new SequenceState(true);
        $stages = [];
        foreach ($sequence->stages() as $i => $stage) {
            $no = $i + 1;
            if ($stage->alreadyDone !== null && ($stage->alreadyDone)($state)) {
                $stages[] = self::row($no, $stage->name, RunResult::SKIPPED, null, null, null, 'Already done in CMS.');
                continue;
            }
            /** @var Request $request */
            $request  = ($stage->build)($state);
            $stages[] = self::row($no, $stage->name, RunResult::PREVIEW, $request->toXml($env), $request->action, null, '');
        }
        return new RunResult(self::newRunId(), 'preview', $stages);
    }

    /**
     * Send the stages in order. The first stage carries the Envelope's
     * TransDate (the moment the user confirmed); later stages are stamped
     * when they are actually sent.
     */
    public function run(Sequence $sequence, Envelope $env, ?string $runId = null): RunResult
    {
        $runId  = $runId ?? self::newRunId();
        $mode   = $this->transport->mode();
        $state  = new SequenceState(
            $mode === 'dry-run',
            fn (string $kind, int $min, int $max): ?string => $this->transport->generateId($kind, $min, $max),
        );
        $stages = [];
        $halted = false;
        $first  = true;

        foreach ($sequence->stages() as $i => $stage) {
            $no = $i + 1;
            if ($halted) {
                $stages[] = self::row($no, $stage->name, RunResult::NOT_RUN, null, null, null, 'Not run.');
                continue;
            }

            // Check and build. Nothing has left for CMS yet, so any problem
            // here means this step was not sent.
            try {
                if ($stage->alreadyDone !== null && ($stage->alreadyDone)($state)) {
                    $stages[] = self::row($no, $stage->name, RunResult::SKIPPED, null, null, null, 'Already done in CMS.');
                    continue;
                }
                $stageEnv = $first ? $env : $env->withTransDate(($this->clock)());
                /** @var Request $request */
                $request = ($stage->build)($state);
                $xml     = $request->toXml($stageEnv);
                $action  = $request->action;
                $first   = false;
            } catch (WriteRefused $e) {
                $stages[] = self::row($no, $stage->name, TransportResult::FAILED, null, null, null, $e->getMessage());
                $halted   = true;
                continue;
            } catch (\Throwable $e) {
                $stages[] = self::row($no, $stage->name, TransportResult::FAILED, null, null, null,
                    'This step could not be prepared, so it was not sent (' . self::reason($e) . ').');
                $halted   = true;
                continue;
            }

            $context = ['runId' => $runId, 'mode' => $mode] + $this->auditContext;

            // Intent first: no record, no send.
            $handle = 0;
            if ($this->audit instanceof AuditSink) {
                try {
                    $handle = $this->audit->begin(
                        self::row($no, $stage->name, self::SENDING, $xml, $action, null,
                            'Recorded before sending. If this row stays like this, the outcome was never recorded: '
                            . 'look for the request in the CMS log.') + $context
                    );
                } catch (\Throwable $e) {
                    $stages[] = self::row($no, $stage->name, TransportResult::FAILED, $xml, $action, null,
                        'This step was not sent, because Insights could not record it in its write log ('
                        . self::reason($e) . ').');
                    $halted   = true;
                    continue;
                }
            }

            try {
                $result = $this->transport->send($xml, [
                    'application' => $stageEnv->application,
                    'action'      => $action,
                    'workstation' => $stageEnv->workstation,
                    'transDate'   => Fmt::dateTime($stageEnv->transDate),
                    'user'        => $stageEnv->user,
                ]);
            } catch (\Throwable $e) {
                $result = new TransportResult(
                    TransportResult::UNKNOWN,
                    message: 'The call to CMS broke off (' . self::reason($e) . '). '
                        . 'Check the CMS log before trying again: the request may or may not have been saved.',
                );
            }

            $row = self::row($no, $stage->name, $result->status, $xml, $action, $result, $result->message);
            // Not sent: a dry run, or a transport that says the request never left.
            $row['sent'] = $result->status !== TransportResult::DRY_RUN && !$result->notSent;

            try {
                $state->record($no, $result);
                if ($this->audit instanceof AuditSink) {
                    $this->audit->finish($handle, $row + $context);
                } elseif ($this->audit !== null) {
                    ($this->audit)($row + $context);
                }
            } catch (\Throwable $e) {
                // The intent row stays as the evidence. Stop here.
                $row['auditIncomplete'] = true;
                $row['message'] = trim($row['message'] . ' Insights could not record this outcome in its write log ('
                    . self::reason($e) . ').');
                $halted = true;
            }
            $stages[] = $row;

            if ($result->status === TransportResult::FAILED || $result->status === TransportResult::UNKNOWN) {
                $halted = true;
            }
        }

        return new RunResult($runId, $mode, $stages);
    }

    private static function row(int $no, string $name, string $status, ?string $xml, ?string $action, ?TransportResult $r, string $message): array
    {
        return [
            'no'         => $no,
            'name'       => $name,
            'status'     => $status,
            'xml'        => $xml,
            'action'     => $action,
            'logId'      => $r?->logId,
            'resultCode' => $r?->resultCode,
            'resultText' => $r?->resultText,
            'message'    => $message,
            // true once the request was handed to CMS; false for a dry run and
            // for a request the transport says never left
            'sent'       => false,
            // true when the outcome could not be written to the audit after the send
            'auditIncomplete' => false,
        ];
    }

    /** A short, one-line reason from an exception. */
    private static function reason(\Throwable $e): string
    {
        $msg = rtrim(trim((string) preg_replace('/\s+/', ' ', $e->getMessage())), '.');
        return $msg !== '' ? mb_substr($msg, 0, 300) : (new \ReflectionClass($e))->getShortName();
    }

    public static function newRunId(): string
    {
        return bin2hex(random_bytes(8));
    }
}

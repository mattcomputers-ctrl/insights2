<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Runs a Sequence one request at a time: build (fresh Versions), send,
 * record, and stop at the first request that does not commit.
 */
final class SequenceRunner
{
    /** @var \Closure():\DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param null|\Closure(array):void $audit receives one row per stage that was sent
     * @param null|\Closure():\DateTimeImmutable $clock
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly ?\Closure $audit = null,
        ?\Closure $clock = null,
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
        $state  = new SequenceState($this->transport->mode() === 'dry-run');
        $stages = [];
        $halted = false;
        $first  = true;

        foreach ($sequence->stages() as $i => $stage) {
            $no = $i + 1;
            if ($halted) {
                $stages[] = self::row($no, $stage->name, RunResult::NOT_RUN, null, null, null, 'Not run.');
                continue;
            }
            if ($stage->alreadyDone !== null && ($stage->alreadyDone)($state)) {
                $stages[] = self::row($no, $stage->name, RunResult::SKIPPED, null, null, null, 'Already done in CMS.');
                continue;
            }

            $stageEnv = $first ? $env : $env->withTransDate(($this->clock)());
            $first    = false;

            try {
                /** @var Request $request */
                $request = ($stage->build)($state);
                $xml     = $request->toXml($stageEnv);
            } catch (WriteRefused $e) {
                $stages[] = self::row($no, $stage->name, TransportResult::FAILED, null, null, null, $e->getMessage());
                $halted   = true;
                continue;
            }

            $result = $this->transport->send($xml, [
                'application' => $stageEnv->application,
                'action'      => $request->action,
                'workstation' => $stageEnv->workstation,
                'transDate'   => Fmt::dateTime($stageEnv->transDate),
            ]);
            $state->record($no, $result);

            $row = self::row($no, $stage->name, $result->status, $xml, $request->action, $result, $result->message);
            $stages[] = $row;
            if ($this->audit !== null) {
                ($this->audit)($row + ['runId' => $runId, 'mode' => $this->transport->mode()]);
            }

            if ($result->status === TransportResult::FAILED || $result->status === TransportResult::UNKNOWN) {
                $halted = true;
            }
        }

        return new RunResult($runId, $this->transport->mode(), $stages);
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
        ];
    }

    public static function newRunId(): string
    {
        return bin2hex(random_bytes(8));
    }
}

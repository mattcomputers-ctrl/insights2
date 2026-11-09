<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Outcome of running (or previewing) a Sequence: one entry per stage, in
 * order, saying exactly which requests committed.
 */
final class RunResult
{
    public const SKIPPED = 'skipped';     // already done in CMS (resumed flow)
    public const NOT_RUN = 'not-run';     // an earlier stage failed
    public const PREVIEW = 'preview';

    /**
     * @param list<array{no:int,name:string,status:string,xml:?string,action:?string,logId:?int,resultCode:?int,resultText:?string,message:string}> $stages
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $mode,
        public readonly array $stages,
    ) {}

    /** Every stage committed (or was already done). */
    public function ok(): bool
    {
        foreach ($this->stages as $s) {
            if ($s['status'] !== TransportResult::COMMITTED && $s['status'] !== self::SKIPPED) {
                return false;
            }
        }
        return $this->stages !== [];
    }

    public function dryRun(): bool
    {
        return $this->mode === 'dry-run';
    }

    /** @return list<int> CMS Log ids of the requests that committed, in order */
    public function committedLogIds(): array
    {
        $ids = [];
        foreach ($this->stages as $s) {
            if ($s['status'] === TransportResult::COMMITTED && $s['logId'] !== null) {
                $ids[] = $s['logId'];
            }
        }
        return $ids;
    }

    /** The first stage that did not commit, or null. */
    public function failure(): ?array
    {
        foreach ($this->stages as $s) {
            if (in_array($s['status'], [TransportResult::FAILED, TransportResult::UNKNOWN], true)) {
                return $s;
            }
        }
        return null;
    }

    /** One paragraph for a flash message. */
    public function describe(): string
    {
        $total = count($this->stages);
        if ($this->dryRun()) {
            return 'Dry run: ' . $total . ' request' . ($total === 1 ? '' : 's')
                . ' prepared and recorded, nothing sent to CMS.';
        }
        $fail = $this->failure();
        $done = count($this->committedLogIds());
        if ($fail === null) {
            $ids = implode(', ', $this->committedLogIds());
            return 'Saved in CMS' . ($ids !== '' ? " (log {$ids})" : '') . '.';
        }
        $msg = $fail['message'];
        if ($total === 1) {
            return $msg . ($fail['status'] === TransportResult::FAILED ? ' Nothing was saved.' : '');
        }
        return "Step {$fail['no']} of {$total} ({$fail['name']}) did not go through: {$msg} "
            . ($done > 0
                ? "{$done} earlier step" . ($done === 1 ? ' is' : 's are') . ' already saved in CMS (log '
                    . implode(', ', $this->committedLogIds()) . '); the rest were not run. Open the record again to finish it.'
                : 'Nothing was saved.');
    }
}

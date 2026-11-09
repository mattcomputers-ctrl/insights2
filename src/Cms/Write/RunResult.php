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
     * @param list<array{no:int,name:string,status:string,xml:?string,action:?string,logId:?int,resultCode:?int,resultText:?string,message:string,sent:bool,auditIncomplete:bool}> $stages
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $mode,
        public readonly array $stages,
    ) {}

    /** Every stage committed (or was already done) and every outcome is on record. */
    public function ok(): bool
    {
        foreach ($this->stages as $s) {
            if (!empty($s['auditIncomplete'])) {
                return false;
            }
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

    /**
     * At least one request was handed to CMS (whatever came of it). Once
     * this is true the run must not be reported as "nothing was sent" and
     * must not be confirmed again.
     */
    public function attempted(): bool
    {
        foreach ($this->stages as $s) {
            if (!empty($s['sent'])) {
                return true;
            }
        }
        return false;
    }

    /** The stage whose outcome could not be written to the audit after it was sent, or null. */
    public function auditGap(): ?array
    {
        foreach ($this->stages as $s) {
            if (!empty($s['auditIncomplete'])) {
                return $s;
            }
        }
        return null;
    }

    /** One paragraph for a flash message. */
    public function describe(): string
    {
        $total = count($this->stages);
        $gap   = $this->auditGap();
        if ($this->dryRun()) {
            $fail = $this->failure();
            if ($fail !== null) {
                return "Dry run stopped at step {$fail['no']} of {$total} ({$fail['name']}): {$fail['message']} Nothing was sent to CMS.";
            }
            if ($gap !== null) {
                return "Dry run stopped at step {$gap['no']} of {$total} ({$gap['name']}): {$gap['message']} Nothing was sent to CMS.";
            }
            return 'Dry run: ' . $total . ' request' . ($total === 1 ? '' : 's')
                . ' prepared and recorded, nothing sent to CMS.';
        }
        $fail = $this->failure();
        $done = count($this->committedLogIds());
        $ids  = implode(', ', $this->committedLogIds());
        if ($fail === null && $gap !== null) {
            // Saved in CMS, but the write log has no outcome for it and the run stopped there.
            $rest = $total - $gap['no'];
            return ($total === 1 ? 'Saved in CMS' : "Step {$gap['no']} of {$total} ({$gap['name']}) is saved in CMS")
                . ($gap['logId'] !== null ? " (log {$gap['logId']})" : '')
                . ', but Insights could not record the outcome in its write log.'
                . ($done > 1 ? " Saved so far: log {$ids}." : '')
                . ($rest > 0
                    ? " The remaining {$rest} step" . ($rest === 1 ? ' was' : 's were') . ' not run. Open the record again to finish it.'
                    : '')
                . ' Tell an administrator: ' . $gap['message'];
        }
        if ($fail === null) {
            return 'Saved in CMS' . ($ids !== '' ? " (log {$ids})" : '') . '.';
        }
        $msg = $fail['message'];
        if ($total === 1) {
            return $msg . ($fail['status'] === TransportResult::FAILED ? ' Nothing was saved.' : '');
        }
        return "Step {$fail['no']} of {$total} ({$fail['name']}) did not go through: {$msg} "
            . ($done > 0
                ? "{$done} earlier step" . ($done === 1 ? ' is' : 's are') . ' already saved in CMS (log '
                    . $ids . '); the rest were not run. Open the record again to finish it.'
                : ($fail['status'] === TransportResult::FAILED
                    ? 'Nothing was saved.'
                    : 'No earlier step was saved and the rest were not run.'));
    }
}

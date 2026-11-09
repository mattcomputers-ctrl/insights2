<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * What happened to one request.
 *
 *  committed — CMS ran it (ResultCode 0 and a LogCompleted row)
 *  failed    — CMS rejected it and rolled the whole request back
 *  dry-run   — nothing was sent
 *  unknown   — the call errored and no Log row could be found for it;
 *              a human must check the CMS log before retrying
 */
final class TransportResult
{
    public const COMMITTED = 'committed';
    public const FAILED    = 'failed';
    public const DRY_RUN   = 'dry-run';
    public const UNKNOWN   = 'unknown';

    /**
     * @param list<array{step:int,table:string,field:string,value:string}> $outputs LogResult rows
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $logId = null,
        public readonly ?int $resultCode = null,
        public readonly ?string $resultText = null,
        public readonly string $message = '',
        public readonly array $outputs = [],
    ) {}

    public function committed(): bool
    {
        return $this->status === self::COMMITTED;
    }

    /** First value a step produced for table.field (e.g. the new Waybill id), or null. */
    public function output(int $step, string $table, string $field): ?string
    {
        foreach ($this->outputs as $o) {
            if ($o['step'] === $step && strcasecmp($o['table'], $table) === 0 && strcasecmp($o['field'], $field) === 0) {
                return $o['value'];
            }
        }
        return null;
    }
}

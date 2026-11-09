<?php

declare(strict_types=1);

namespace PII\Core;

/**
 * Thrown when CMS access is administratively blocked — by the CMS kill
 * switch or a backup blackout window — as opposed to a connection failure.
 * Pages catch this and show a calm, friendly message instead of an error.
 */
class CMSUnavailableException extends \RuntimeException
{
    public const KILL_SWITCH = 'kill_switch';
    public const BLACKOUT    = 'blackout';

    public function __construct(
        public readonly string $reason,
        public readonly ?string $until = null,   // 'g:i A' end time for blackouts
    ) {
        parent::__construct($this->friendlyMessage());
    }

    public function friendlyMessage(): string
    {
        if ($this->reason === self::BLACKOUT) {
            return 'Paused for the nightly backup'
                . ($this->until !== null ? ' until ' . $this->until : '')
                . '. Live CMS data is temporarily unavailable.';
        }
        return 'CMS access is switched off by an administrator (CMS maintenance). Try again later.';
    }
}

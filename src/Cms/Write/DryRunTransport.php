<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/** Sends nothing. The default transport until live writing is configured and switched on. */
final class DryRunTransport implements Transport
{
    public function mode(): string
    {
        return 'dry-run';
    }

    public function send(string $xml, array $meta): TransportResult
    {
        return new TransportResult(
            TransportResult::DRY_RUN,
            message: 'Dry run — nothing was sent to CMS.',
        );
    }

    public function generateId(string $kind, int $min, int $max): ?string
    {
        return null;
    }
}

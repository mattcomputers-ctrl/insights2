<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * A validated, ready-to-preview write: the Sequence plus the plain-English
 * summary shown above the XML on the confirmation page.
 */
final class Plan
{
    /**
     * @param list<array{0:string,1:string}> $summary label/value lines describing the change
     * @param list<string> $warnings things the user should know before confirming
     */
    public function __construct(
        public readonly Sequence $sequence,
        public readonly array $summary = [],
        public readonly array $warnings = [],
        public readonly ?string $entityType = null,
        public readonly ?string $entityId = null,
    ) {}
}

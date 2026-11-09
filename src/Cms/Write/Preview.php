<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/** What the confirmation page shows: the plan, the exact XML, and its fingerprint. */
final class Preview
{
    public function __construct(
        public readonly OperationDef $operation,
        public readonly Plan $plan,
        public readonly RunResult $rendered,
        public readonly string $hash,
        public readonly string $mode,
    ) {}

    /** Fingerprint of the rendered requests, so confirm can tell if CMS moved since the preview. */
    public static function hashOf(RunResult $rendered): string
    {
        $parts = [];
        foreach ($rendered->stages as $s) {
            $parts[] = $s['status'] . "\n" . ($s['xml'] ?? '');
        }
        return hash('sha256', implode("\n--\n", $parts));
    }
}

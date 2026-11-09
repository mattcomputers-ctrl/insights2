<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/** One entry of the write allowlist. */
final class OperationDef
{
    /**
     * @param string $key        stable id, "<module>.<verb>" (e.g. "qc.save_results")
     * @param string $module     Insights module key the operation belongs to
     * @param string $label      what the user is doing, for the permission editor and audit
     * @param string $permission named permission required (one per operation)
     * @param class-string<Flow> $flow
     * @param list<string> $cmsActions CMS Action name(s) the operation sends
     */
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly string $label,
        public readonly string $permission,
        public readonly string $flow,
        public readonly array $cmsActions,
    ) {}
}

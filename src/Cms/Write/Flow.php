<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * One allowlisted write operation as the user sees it ("save test
 * results", "approve sublot"). A Flow validates plain input, checks the
 * record's current state in CMS, and returns a Plan whose Sequence builds
 * the request(s) from typed templates.
 *
 * Flows must be deterministic for a given (input, $ctx->now, CMS state):
 * the confirm step re-plans and compares against what was previewed.
 */
interface Flow
{
    /**
     * @param array<string,mixed> $input plain scalars/arrays (survives the session round trip)
     * @throws WriteRefused with a message for the user
     */
    public function plan(array $input, FlowContext $ctx): Plan;
}

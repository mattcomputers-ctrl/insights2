<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Execution;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Send a made batch for its specification tests: CMS creates the sample
 * on the vessel and the lot shows in the QC queue.
 * Input: ordr (int).
 */
final class SendToQc implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $t = ExecutionState::bulk($ctx->cms, $input);
        ExecutionState::made($t);
        if ($t['vessel'] === null || $t['sublot'] === null) {
            throw new WriteRefused("The vessel or the lot of ticket {$t['Ordr']} was not found. Send it for testing in CMS.");
        }
        $tests = array_values(array_filter($t['steps'], static fn (array $l): bool => $l['context'] === 'IPT' && !$l['done']));
        usort($tests, static fn (array $a, array $b): int => [$a['execOrder'] ?? PHP_INT_MAX, $a['id']] <=> [$b['execOrder'] ?? PHP_INT_MAX, $b['id']]);
        if ($tests === []) {
            $any = array_filter($t['steps'], static fn (array $l): bool => $l['context'] === 'IPT');
            throw new WriteRefused($any === []
                ? "Ticket {$t['Ordr']} has no test step: its product has no specification tests."
                : "Ticket {$t['Ordr']} has been sent for testing already.");
        }
        $request = ExecutionRequests::inProcessTesting($t['Ordr'], $t['batch'], $t['vessel']['id'], $t['vessel']['version'],
            $tests[0]['id'], $t['sublot'], $ctx->cmsUser, Fmt::dateTime($ctx->now));

        return new Plan(
            Sequence::single("Send batch ticket {$t['Ordr']} for testing", static fn () => $request),
            [
                ['Batch ticket', "{$t['Ordr']}: {$t['product']['code']} {$t['product']['description']}"],
                ['Result', "A sample is taken from vessel {$t['vessel']['code']} now, by {$ctx->cmsUser}; the lot waits in the QC queue"],
            ],
            [], 'Ordr', (string) $t['Ordr'],
        );
    }
}

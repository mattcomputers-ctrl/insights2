<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Execution;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;

/**
 * Mark a made ticket complete on the floor.
 * Input: ordr (int).
 */
final class CompleteTicket implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $t = ExecutionState::bulk($ctx->cms, $input);
        ExecutionState::made($t);
        $packed   = ExecutionState::packouts($ctx->cms, $t);
        $warnings = [];
        if ($packed === [] && !$t['packedHere']) {
            $warnings[] = $t['packs'] === []
                ? 'Nothing has been packed from this batch and it has no packaging tickets.'
                : 'Nothing has been packed from this batch yet. After completing it, pack it in CMS.';
        }
        $waiting = array_filter($t['steps'], static fn (array $l): bool => $l['context'] === 'IPT' && !$l['done']);
        if ($waiting !== []) {
            $warnings[] = 'The batch has not been sent for testing.';
        }
        $request = ExecutionRequests::markComplete($t['Ordr'], $t['Version']);

        $summary = [
            ['Batch ticket', "{$t['Ordr']}: {$t['product']['code']} {$t['product']['description']}"],
            ['Result', 'The ticket is completed; it can then be closed'],
        ];
        if ($packed !== []) {
            $summary[] = ['Packed', count($packed) . ' packout' . (count($packed) === 1 ? '' : 's') . ' on '
                . implode(', ', array_unique(array_map(static fn (array $p): string => "{$p['ordr']} ({$p['code']})", $packed)))];
        }

        return new Plan(
            Sequence::single("Complete batch ticket {$t['Ordr']}", static fn () => $request),
            $summary, $warnings, 'Ordr', (string) $t['Ordr'],
        );
    }
}

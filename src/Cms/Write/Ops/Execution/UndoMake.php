<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Execution;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Undo the make of a batch: the ingredients go back to stock and the
 * ticket can be made again.
 * Input: ordr (int).
 */
final class UndoMake implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $t = ExecutionState::bulk($ctx->cms, $input);
        ExecutionState::made($t);
        $packed = ExecutionState::packouts($ctx->cms, $t);
        if ($packed !== [] || $t['packedHere']) {
            throw new WriteRefused("Ticket {$t['Ordr']} has been packed. Undo its packouts first.");
        }
        $lines  = array_map(static fn (array $l): int => $l['id'], ExecutionState::ingredients($t));
        $retest = array_filter($t['steps'], static fn (array $l): bool => $l['context'] === 'IPT' && $l['done']) !== [];
        $request = ExecutionRequests::reverseReceipt($t['Ordr'], $t['Version'], $t['receipt'], $lines, $retest);

        $warnings = $retest
            ? ['The batch was sent for testing. CMS adds a fresh test step; the sample already taken stays in the QC queue.']
            : [];

        return new Plan(
            Sequence::single("Undo the make of batch ticket {$t['Ordr']}", static fn () => $request),
            [
                ['Batch ticket', "{$t['Ordr']}: {$t['product']['code']} {$t['product']['description']}"],
                ['Result', 'The ingredients go back to stock; the ticket is ready to be made again'],
            ],
            $warnings, 'Ordr', (string) $t['Ordr'],
        );
    }
}

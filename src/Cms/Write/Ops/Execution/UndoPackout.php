<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Execution;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Undo one packout: the packaged stock is taken back, the bulk returns
 * to the vessel and the components to stock.
 * Input: ordr (int, the bulk ticket), pack (int, its packaging ticket),
 * changeSet (int, the packout).
 */
final class UndoPackout implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $t = ExecutionState::bulk($ctx->cms, $input);
        ExecutionState::onFloor($t);
        $p = ExecutionState::pack($ctx->cms, $t, (int) ($input['pack'] ?? 0));
        if (in_array($p['Status'], ['CMP', 'CLS'], true)) {
            throw new WriteRefused("Packaging ticket {$p['Ordr']} is completed. Undo its packout in CMS.");
        }
        $cs = (int) ($input['changeSet'] ?? 0);
        if (!in_array($cs, $p['packouts'], true)) {
            throw new WriteRefused("That packout was not found on packaging ticket {$p['Ordr']}, or it has been undone already.");
        }
        $used = [];
        foreach ($p['steps'] as $l) {
            if ($l['context'] === 'UI') {
                $used[] = $l['id'];
            }
        }
        $request = ExecutionRequests::unpackaging($t['Ordr'], $t['Version'], $cs, $used, $p['product']['id']);

        $n = array_search($cs, $p['packouts'], true) + 1;
        return new Plan(
            Sequence::single("Undo a packout of batch ticket {$t['Ordr']}", static fn () => $request),
            [
                ['Batch ticket', "{$t['Ordr']}: {$t['product']['code']} {$t['product']['description']}"],
                ['Packout undone', "number {$n} of " . count($p['packouts']) . " on packaging ticket {$p['Ordr']} ({$p['product']['code']})"],
                ['Result', 'The packaged stock is taken back; the bulk returns to the vessel and the components to stock'],
            ],
            ['CMS refuses this if the packaged stock has already been moved, committed or shipped.'],
            'Ordr', (string) $t['Ordr'],
        );
    }
}

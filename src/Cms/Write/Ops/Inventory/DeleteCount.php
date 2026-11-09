<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Inventory;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;

/**
 * Delete a count that is not posted yet, with its lines. No stock changes.
 *
 * Input: count (int).
 */
final class DeleteCount implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $c  = InventoryState::open($ctx->cms, $input['count'] ?? 0);
        $id = $c['InventoryCount'];
        $n  = count($c['lines']);

        $request = InventoryRequests::deleteCount($id, $c['Version'], array_column($c['lines'], 'detail'));
        return new Plan(
            Sequence::single("Delete inventory count {$id}", static fn (): Request => $request),
            [['Count', "{$id}" . ($c['Description'] !== '' ? ": {$c['Description']}" : '') . ', ' . substr($c['EffectiveDate'], 0, 10)],
             ['Deletes', $n . ' line' . ($n === 1 ? '' : 's') . ' and the count itself. Stock is not changed.']],
            [],
            'InventoryCount',
            (string) $id,
        );
    }
}

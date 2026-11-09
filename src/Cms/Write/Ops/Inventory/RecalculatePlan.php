<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Inventory;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;

/**
 * Have CMS work out the planned dates of open orders again from stock,
 * purchase orders and tickets. Takes several seconds. No input.
 */
final class RecalculatePlan implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $request = InventoryRequests::recalculatePlan($ctx->owner);
        return new Plan(
            Sequence::single('Recalculate planned dates', static fn (): Request => $request),
            [['Recalculates', 'the planned dates of every open order, from stock on hand, purchase orders and batch tickets'],
             ['Takes', 'several seconds']],
            [],
            'Owner',
            $ctx->owner,
        );
    }
}

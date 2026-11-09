<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Sales;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;

/** Release a sales order from credit hold. Input: ordr (int). */
final class ReleaseOrder implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $o = SalesOrderGuard::load($ctx, $input, ['CSH'], 'released from credit hold');

        $request = SalesRequests::editOrder(
            $o['Ordr'], $o['Version'], ['Status' => 'RTS', 'CreditHold' => false], [], ['mode' => 'release']
        );

        return new Plan(
            Sequence::single("Release order {$o['Ordr']} from credit hold", static fn () => $request),
            [
                ['Order', $o['Ordr'] . ' for ' . $o['ShipToName']],
                ['Billed to', $o['BillToName']],
                ['Order value held', $o['ReserveAmount']],
                ['Result', 'Status becomes open (ready to ship)'],
            ],
            ['This overrides the credit check for this order.'],
            'Ordr',
            (string) $o['Ordr'],
        );
    }
}

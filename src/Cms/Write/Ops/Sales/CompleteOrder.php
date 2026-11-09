<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Sales;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;

/**
 * Mark a sales order complete: close every line still open and set the
 * order's status to completed. Nothing ships. Input: ordr (int).
 */
final class CompleteOrder implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $o = SalesOrderGuard::load($ctx, $input, ['RTS', 'CSH'], 'completed');

        $changes  = [];
        $summary  = [['Order', $o['Ordr'] . ' for ' . $o['ShipToName']]];
        $shipped  = false;
        foreach ($o['lines'] as $l) {
            $shipped = $shipped || (float) $l['shipped'] !== 0.0;
            if (!$l['isOpen']) {
                continue;
            }
            $changes[] = ['op' => 'updateLine', 'ordDetail' => $l['ordDetail'],
                          'changes' => ['IsOpen' => false, 'QtyCommitted' => '']];
            $left      = (float) $l['qty'] - (float) $l['shipped'];
            $summary[] = ['Close line', "{$l['code']}: ordered {$l['qty']}, shipped {$l['shipped']}"
                . ($left > 0 ? ', ' . Fmt::num($left) . " {$l['unit']} will not ship" : '')];
        }
        $summary[] = ['Result', 'Status becomes completed'];

        $warnings = [];
        if (!$shipped) {
            $warnings[] = 'Nothing has shipped on this order. Completing it closes it without shipping or invoicing anything.';
        }

        // No credit check for an order on credit hold: the client completes it with the
        // tax step only, as it does for an edit, and the hold flag stays set.
        $onHold = $o['Status'] === 'CSH' || $o['CreditHold'];
        if ($onHold) {
            $warnings[] = 'This order is on credit hold. It is completed without being released, so it keeps its credit hold mark.';
        }

        $request = SalesRequests::editOrder(
            $o['Ordr'], $o['Version'],
            ['DateCompleted' => Fmt::dateTime($ctx->now), 'Status' => 'CMP'],
            $changes,
            $onHold ? ['mode' => 'none'] : ['mode' => 'check', 'oldReserve' => $o['ReserveAmount']],
        );

        return new Plan(
            Sequence::single("Complete order {$o['Ordr']}", static fn () => $request),
            $summary,
            $warnings,
            'Ordr',
            (string) $o['Ordr'],
        );
    }
}

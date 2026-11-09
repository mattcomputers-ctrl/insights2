<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Sales;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * "Complete sale": ship and invoice the order's open lines straight from
 * the order, with no waybill (CMS uses it for credits, sundry charges and
 * fees). Input: ordr (int).
 */
final class CompleteSale implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $o = SalesOrderGuard::load($ctx, $input, ['RTS'], 'shipped and invoiced');

        $open = array_values(array_filter($o['lines'], static fn ($l) => $l['isOpen']));
        if ($open === []) {
            throw new WriteRefused("Order {$o['Ordr']} has no open lines to ship.");
        }

        $summary = [['Order', $o['Ordr'] . ' for ' . $o['ShipToName']], ['Billed to', $o['BillToName']]];
        $total   = 0.0;
        $stock   = false;
        foreach ($open as $l) {
            $left      = (float) $l['qty'] - (float) $l['shipped'];
            $total    += $left * (float) $l['price'];
            $stock     = $stock || $left > 0;
            $summary[] = ['Ship and invoice', SalesState::plain(number_format($left, 4, '.', ''))
                . " {$l['unit']} of {$l['code']} at {$l['price']}"];
        }
        $summary[] = ['Invoice total before tax', SalesInput::money($total)];
        $summary[] = ['Result', 'CMS creates the shipment and the invoice, and completes the order'];

        $warnings = ['This ships and invoices the order immediately, without a waybill. It cannot be undone from Insights.'];
        if ($stock) {
            $warnings[] = 'Stock for these lines is taken straight from the default stock location, not from an assembled container.';
        }

        $request = SalesRequests::completeSale($o['Ordr'], $o['Version']);

        return new Plan(
            Sequence::single("Complete sale for order {$o['Ordr']}", static fn () => $request),
            $summary,
            $warnings,
            'Ordr',
            (string) $o['Ordr'],
        );
    }
}

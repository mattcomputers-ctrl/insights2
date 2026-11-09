<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Purchasing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;

/**
 * Mark a purchase order complete: close every line still open and set the
 * order's status to completed. Nothing more can be received against it.
 * Input: ordr (int).
 */
final class CompletePurchaseOrder implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $o = PurchasingInput::order($ctx->cms, $input, [''], 'completed');

        $changes  = [];
        $summary  = [['Purchase order', $o['Ordr'] . ' to ' . $o['SupplierName']]];
        $received = false;
        $short    = false;
        foreach ($o['lines'] as $l) {
            $received = $received || (float) $l['received'] !== 0.0;
            if (!$l['isOpen']) {
                continue;
            }
            $changes[] = ['op' => 'updateLine', 'ordDetail' => $l['ordDetail'], 'changes' => ['IsOpen' => false]];
            $left      = (float) $l['qty'] - (float) $l['received'];
            $short     = $short || $left > 0;
            $summary[] = ['Close line', "{$l['code']}: ordered {$l['qty']} {$l['unit']}, received {$l['received']}"
                . ($left > 0 ? ', ' . Fmt::num($left) . " {$l['unit']} will not be received" : '')];
        }
        $summary[] = ['Result', 'Status becomes completed'];

        $warnings = [];
        if (!$received) {
            $warnings[] = 'Nothing has been received on this PO. Completing it closes it with nothing received.';
        } elseif ($short) {
            $warnings[] = 'Some lines are not fully received. Completing the PO closes them as they are.';
        }

        $request = PurchasingRequests::editOrder(
            $o['Ordr'], $o['Version'],
            ['DateCompleted' => Fmt::dateTime($ctx->now), 'Status' => 'CMP'],
            $changes,
        );

        return new Plan(
            Sequence::single("Complete purchase order {$o['Ordr']}", static fn () => $request),
            $summary,
            $warnings,
            'Ordr',
            (string) $o['Ordr'],
        );
    }
}

<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Shipping;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Reject (reverse) a completed waybill: its shipments and invoices are
 * reversed, the stock goes back into the containers and the orders go
 * back to ready to ship.
 *
 * Input: waybill (int).
 */
final class RejectWaybill implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $id = (int) ($input['waybill'] ?? 0);
        $w  = $id > 0 ? ShippingState::waybill($ctx->cms, $id) : null;
        if ($w === null) {
            throw new WriteRefused('That waybill was not found in CMS.');
        }
        if ($w['Status'] === 'REJ') {
            throw new WriteRefused("Waybill {$id} is already rejected.");
        }
        if ($w['Status'] !== 'CMP') {
            throw new WriteRefused("Waybill {$id} is not completed, so it cannot be rejected here.");
        }

        $summary = [['Waybill', "{$id}" . ($w['ShipToName'] !== '' ? " to {$w['ShipToName']}" : '')
            . ($w['DateShipped'] !== '' ? ', shipped ' . substr($w['DateShipped'], 0, 10) : '')]];
        foreach ($w['orders'] as $o) {
            $summary[] = ['Reverses', "the shipment of order {$o['ordr']}" . ($o['invoice'] !== '' ? " and invoice {$o['invoice']}" : '')];
        }
        $summary[] = ['Result', 'The stock goes back into its containers and the orders are ready to ship again'];

        $request = ShippingRequests::rejectWaybill($id, $w['Version']);
        return new Plan(
            Sequence::single("Reject waybill {$id}", static fn () => $request),
            $summary,
            ['This reverses the invoices on this waybill. Ship again on a new waybill.'],
            'Waybill',
            (string) $id,
        );
    }
}

<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Purchasing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Reverse a whole purchase receipt: take the stock back out of the
 * receiving location and reopen the PO line.
 *
 * Input: ordr (int), change_set (int, the receipt number).
 */
final class ReverseReceipt implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $o  = PurchasingInput::order($ctx->cms, $input, ['', 'CMP'], 'changed');
        $id = (int) ($input['change_set'] ?? 0);
        $r  = null;
        foreach ($o['receipts'] as $x) {
            if ($x['changeSet'] === $id) {
                $r = $x;
            }
        }
        if ($r === null) {
            throw new WriteRefused("Receipt {$id} was not found on PO {$o['Ordr']}.");
        }
        if ($r['lineCount'] !== 1) {
            throw new WriteRefused("Receipt {$id} covers more than one line. Reverse it in CMS.");
        }
        if ((float) $r['qty'] === 0.0) {
            throw new WriteRefused("Receipt {$id} has already been reversed.");
        }
        if ((float) $r['qty'] < 0.0) {
            throw new WriteRefused("Receipt {$id} is not an ordinary receipt. Reverse it in CMS.");
        }
        if ($r['billed']) {
            throw new WriteRefused("Receipt {$id} is already on a supplier bill, so it cannot be reversed here.");
        }
        $code = '';
        foreach ($o['lines'] as $l) {
            if ($l['ordDetail'] === $r['ordDetail']) {
                $code = $l['code'];
            }
        }

        $summary = [
            ['Purchase order', $o['Ordr'] . ' to ' . $o['SupplierName']],
            ['Reverse receipt', "{$id}" . ($r['shipment'] !== '' ? ", packing slip {$r['shipment']}" : '')
                . ($r['date'] !== '' ? ', received ' . substr($r['date'], 0, 10) : '')],
            ['Takes back', "{$r['qty']} {$r['unit']}" . ($code !== '' ? " of {$code}" : '') . ' from the receiving location'],
            ['PO line', 'reopened, with the received quantity reduced'],
        ];
        $warnings = ['The stock must still be in the receiving location; CMS refuses the reversal if it has been moved or used.'];
        if ($o['Status'] === 'CMP') {
            $warnings[] = "PO {$o['Ordr']} is completed. The line is reopened; check the PO's status in CMS afterwards.";
        }

        $request = PurchasingRequests::reverseReceipt(
            $o['Ordr'], $o['Version'], $o['Owner'], $id, $r['version'],
            $r['ordDetail'], $r['item'], $r['qty'], $r['inventoryCost'], $r['unitCost'],
        );

        return new Plan(
            Sequence::single("Reverse receipt {$id} on purchase order {$o['Ordr']}", static fn () => $request),
            $summary,
            $warnings,
            'ChangeSet',
            (string) $id,
        );
    }
}

<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Purchasing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Ops\Sales\SalesInput;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Receive one purchase order line into the receiving location (CMS:
 * Purchase Receipt, new). One receipt per line, as the client sends them.
 *
 * Input: ordr (int), ord_detail (int), qty (in the item's stock unit),
 * packing_slip (the supplier's packing slip number).
 */
final class ReceiveLine implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $o    = PurchasingInput::order($ctx->cms, $input, [''], 'received against');
        $id   = (int) ($input['ord_detail'] ?? 0);
        $line = null;
        foreach ($o['lines'] as $l) {
            if ($l['ordDetail'] === $id) {
                $line = $l;
            }
        }
        if ($line === null) {
            throw new WriteRefused("That line is no longer on PO {$o['Ordr']}. Reload the page.");
        }
        $code = $line['code'];
        if (!$line['isOpen']) {
            throw new WriteRefused("{$code} is a closed line on PO {$o['Ordr']}; nothing more can be received against it.");
        }
        if ($o['Currency'] !== 'USD') {
            throw new WriteRefused("PO {$o['Ordr']} is in {$o['Currency']}. Receive it in CMS, where the exchange rate is applied.");
        }
        if ($line['pricing'] === null || $line['priceByPackage']
            || $line['unit'] === '' || strcasecmp($line['entityUnit'], $line['unit']) !== 0) {
            throw new WriteRefused("{$code} is priced in a way Insights does not cost (per package, or in a unit other than its stock unit). Receive it in CMS.");
        }

        $qty = (float) SalesInput::number($input['qty'] ?? '', 'Quantity received');
        if ($qty <= 0.0) {
            throw new WriteRefused('Quantity received must be more than zero. To take a receipt back, reverse it.');
        }
        $slip = SalesInput::text($input['packing_slip'] ?? '', 20, 'Packing slip number');
        if ($slip === '' || str_contains($slip, "\n")) {
            throw new WriteRefused('Enter the packing slip number.');
        }

        // Cost per stock unit, worked the way the client does: price per
        // supplier unit, times and divided by the pack size.
        $costed   = !in_array(strtoupper($line['treatment']), ['', 'NONE'], true);
        $perPack  = (float) $line['perPack'];
        $unitCost = $costed ? PurchasingState::dbl((float) $line['price'] * $perPack / $perPack) : null;

        $qtyText  = PurchasingState::dbl($qty);
        $after    = (float) $line['received'] + $qty;
        $ordered  = (float) $line['qty'];
        $summary  = [
            ['Purchase order', $o['Ordr'] . ' to ' . $o['SupplierName']],
            ['Receive', "{$qtyText} {$line['unit']} of {$code} ({$line['description']})"],
            ['Packing slip', $slip],
            ['On this line', "ordered {$line['qty']} {$line['unit']}, received so far {$line['received']}, after this " . Fmt::num($after)],
            ['Goes to', 'the receiving location'],
            ['Cost', $unitCost === null ? 'this item does not carry inventory cost' : Fmt::num((float) $unitCost) . " per {$line['unit']}"],
        ];
        $warnings = [];
        if ($after > $ordered * (1 + $line['percentOver'] / 100) + 1e-9) {
            $warnings[] = "This takes {$code} to " . Fmt::num($after) . " {$line['unit']} received against {$line['qty']} ordered"
                . ($line['percentOver'] > 0 ? ', more than the ' . Fmt::num($line['percentOver']) . '% over-receipt allowance' : '') . '.';
        }
        foreach ($o['receipts'] as $r) {
            if ($r['ordDetail'] === $id && (float) $r['qty'] !== 0.0 && strcasecmp($r['shipment'], $slip) === 0) {
                $warnings[] = "Packing slip {$slip} has already been received against this line (receipt {$r['changeSet']}).";
            }
        }

        $request = PurchasingRequests::receive(
            $o['Ordr'], $o['Version'], $o['Owner'], Fmt::dateTime($ctx->now), $slip,
            $id, $line['item'], $qtyText, $line['unit'], $unitCost,
        );

        return new Plan(
            Sequence::single("Receive {$code} on purchase order {$o['Ordr']}", static fn () => $request),
            $summary,
            $warnings,
            'Ordr',
            (string) $o['Ordr'],
        );
    }
}

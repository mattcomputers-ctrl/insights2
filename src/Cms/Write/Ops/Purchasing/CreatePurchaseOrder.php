<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Purchasing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Ops\Sales\SalesInput;
use PII\Cms\Write\Ops\Sales\SalesState;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Create a purchase order (CMS: Purchase Order, new). CMS releases a PO as
 * it is created.
 *
 * Input: supplier (int), incoterms (absent = the supplier's usual), ship_via
 * (int, 0 = the supplier's usual carrier), memo (absent or null = the supplier's
 * standing PO memo, '' = none), lines: list of {code, qty, price ('' = price
 * list), promised (Y-m-d or '')}. Quantities are in the supplier's ordering
 * units (packages where the price list sells by the package).
 */
final class CreatePurchaseOrder implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $id       = (int) ($input['supplier'] ?? 0);
        $supplier = $id > 0 ? PurchasingState::supplier($ctx->cms, $id) : null;
        if ($supplier === null) {
            throw new WriteRefused('Choose a supplier that exists in CMS.');
        }
        if ($supplier['inactive']) {
            throw new WriteRefused("{$supplier['name']} is marked inactive in CMS.");
        }
        if ($supplier['currency'] === '') {
            throw new WriteRefused("{$supplier['name']} has no currency set in CMS, so an order cannot be priced.");
        }
        if (($supplier['address']['Address'] ?? '') === '') {
            throw new WriteRefused("{$supplier['name']} has no address in CMS. Add one there first.");
        }
        $shipTo = PurchasingState::address($ctx->cms, (int) $ctx->owner);
        if (($shipTo['Address'] ?? '') === '') {
            throw new WriteRefused('Our own receiving address was not found in CMS.');
        }

        $rawLines = array_values(array_filter(
            is_array($input['lines'] ?? null) ? $input['lines'] : [],
            static fn ($l) => is_array($l) && trim((string) ($l['code'] ?? '')) !== ''
        ));
        if ($rawLines === []) {
            throw new WriteRefused('Add at least one line.');
        }
        $lines    = [];
        $warnings = [];
        $total    = 0.0;
        $seen     = [];
        foreach ($rawLines as $i => $raw) {
            $l        = PurchasingInput::line($ctx->cms, $raw, $i + 1, $id, $supplier['currency'], $ctx->now);
            $lines[]  = $l;
            $total   += $l['amount'];
            $warnings = array_merge($warnings, $l['warnings']);
            if (isset($seen[$l['line']['item']])) {
                $warnings[] = "{$l['code']} is on this order more than once.";
            }
            $seen[$l['line']['item']] = true;
        }

        $incoterms = array_key_exists('incoterms', $input) && $input['incoterms'] !== null
            ? PurchasingInput::incoterms($input['incoterms'])
            : $supplier['incoterms'];
        $shipVia = (int) ($input['ship_via'] ?? 0);
        if ($shipVia > 0 && !SalesState::isCarrier($ctx->cms, $shipVia)) {
            throw new WriteRefused('The chosen carrier was not found in CMS.');
        }
        if ($shipVia <= 0) {
            $shipVia = $supplier['shipVia'];
        }

        $memo = $supplier['memo'];
        if (array_key_exists('memo', $input) && $input['memo'] !== null) {
            $text = SalesInput::text($input['memo'], 4000, 'Memo');
            if ($text === '') {
                $memo = null;
            } elseif ($memo === null || $text !== SalesInput::text($memo, 100000, 'Memo')) {
                $memo = $text;
            }
        }

        $header = ['Owner' => $ctx->owner, 'Entity' => $id, 'Currency' => $supplier['currency']];
        if ($supplier['terms'] !== '') {
            $header['Terms'] = $supplier['terms'];
        }
        if ($incoterms !== '') {
            $header['Incoterms'] = $incoterms;
        }
        if ($shipVia > 0) {
            $header['ShipVia'] = $shipVia;
        }
        $header += ['PlacedBy' => $ctx->cmsUser, 'DateOrdered' => Fmt::dateTime($ctx->now)];

        $summary = [
            ['Supplier', $supplier['name']],
            ['Terms', $supplier['terms'] === '' ? '(none)' : $supplier['terms']],
            ['Delivery terms', $incoterms === '' ? '(none)' : $incoterms],
        ];
        foreach ($lines as $l) {
            $summary[] = ['Line', $l['text']];
        }
        $summary[] = ['Order total', SalesInput::money($total) . ' ' . $supplier['currency']];
        if ($memo !== null) {
            $summary[] = ['Memo on the PO', $memo];
        }

        $request = PurchasingRequests::createOrder(
            $header, array_map(static fn ($l) => $l['line'], $lines), $supplier['address'], $shipTo, $memo,
        );

        return new Plan(
            Sequence::single("New purchase order for {$supplier['name']}", static fn () => $request),
            $summary,
            $warnings,
            'Ordr',
            null,
        );
    }
}

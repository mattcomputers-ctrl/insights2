<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Sales;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\SequenceState;
use PII\Cms\Write\WriteRefused;

/**
 * Create a sales order or a quote (CMS: Shipping Order, new).
 *
 * Input: ship_to (int), po_number, date_required (Y-m-d), ship_via (int, 0 = the
 * customer's usual carrier), is_quote (bool), internal_memo (null = the customer's
 * standing memo), shipping_memo, duplicate_po_ok (bool),
 * lines: list of {code, qty, price ('' = price list)}.
 */
final class CreateOrder implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $shipTo   = (int) ($input['ship_to'] ?? 0);
        $customer = $shipTo > 0 ? SalesState::customer($ctx->cms, $shipTo) : null;
        if ($customer === null) {
            throw new WriteRefused('Choose a customer (ship-to) that exists in CMS.');
        }
        if ($customer['inactive']) {
            throw new WriteRefused("{$customer['shipToName']} is marked inactive in CMS.");
        }
        if ($customer['currency'] === '') {
            throw new WriteRefused("{$customer['billToName']} has no currency set in CMS, so an order cannot be priced.");
        }
        if (($customer['address']['Address'] ?? '') === '') {
            throw new WriteRefused("{$customer['shipToName']} has no address in CMS. Add one there first.");
        }

        $quote    = !empty($input['is_quote']);
        $required = SalesInput::date($input['date_required'] ?? '', 'Required date');
        if ($required->format('Y-m-d') < $ctx->now->format('Y-m-d')) {
            throw new WriteRefused('The required date is in the past.');
        }
        $po = SalesInput::text($input['po_number'] ?? '', 50, 'PO number');

        $rawLines = array_values(array_filter(
            is_array($input['lines'] ?? null) ? $input['lines'] : [],
            static fn ($l) => is_array($l) && trim((string) ($l['code'] ?? '')) !== ''
        ));
        if ($rawLines === []) {
            throw new WriteRefused('Add at least one line.');
        }
        $lines = [];
        $total = 0.0;
        foreach ($rawLines as $i => $raw) {
            $line    = SalesInput::line($ctx->cms, $raw, $i + 1, $customer['billTo'], $shipTo, $quote, $ctx->now);
            $lines[] = $line;
            $total  += $line['amount'];
        }

        $warnings  = [];
        $duplicate = SalesState::duplicatePo($ctx->cms, $customer['billTo'], $po);
        if ($duplicate !== null && empty($input['duplicate_po_ok'])) {
            throw new WriteRefused(
                "PO number \"{$po}\" is already on order {$duplicate} for this customer. "
                . 'Tick "Duplicate PO number is fine" to save anyway.'
            );
        }
        if ($duplicate !== null) {
            $warnings[] = "PO number \"{$po}\" is also on order {$duplicate}.";
        }
        if (!$quote && $po === '') {
            $warnings[] = 'No PO number.';
        }

        $shipVia = (int) ($input['ship_via'] ?? 0);
        if ($shipVia > 0 && !SalesState::isCarrier($ctx->cms, $shipVia)) {
            throw new WriteRefused('The chosen carrier was not found in CMS.');
        }
        if ($shipVia <= 0) {
            $shipVia = (int) $customer['shipVia'];
        }

        $header = [
            'Owner'        => $ctx->owner,
            'DateOrdered'  => Fmt::dateTime($ctx->now),
            'DateRequired' => $required->format('Y-m-d') . 'T' . $ctx->now->format('H:i:s'),
            'Status'       => $quote ? 'QUO' : 'RTS',
            'PlacedBy'     => $ctx->cmsUser,
            'Currency'     => $customer['currency'],
            'ShipTo'       => $shipTo,
            'BillTo'       => $customer['billTo'],
            'IsQuote'      => $quote,
        ];
        $optional = [
            'ShipVia'        => $shipVia > 0 ? $shipVia : '',
            'FreightService' => $customer['freightService'],
            'FreightOption'  => $customer['freightOption'],
            'FreightAccount' => $customer['freightAccount'],
            'PoNumber'       => $po,
            'IncoTerms'      => $customer['incoTerms'],
            'Terms'          => $customer['terms'],
            'Salesman'       => $customer['salesman'] === '' ? '' : (int) $customer['salesman'],
            'Tax1Group'      => $customer['tax1Group'],
            'Tax1Number'     => $customer['tax1Number'],
            'Tax2Number'     => $customer['tax2Number'],
        ];
        foreach ($optional as $name => $value) {
            if ($value !== '') {
                $header[$name] = $value;
            }
        }

        $internal = $customer['memo'];
        if (array_key_exists('internal_memo', $input) && $input['internal_memo'] !== null) {
            $text = SalesInput::text($input['internal_memo'], 4000, 'Internal memo');
            if ($text === '') {
                $internal = null;
            } elseif ($internal === null || $text !== SalesInput::text($internal['text'], 100000, 'Memo')) {
                $internal = ['text' => $text];
            }
        }
        $shippingText = SalesInput::text($input['shipping_memo'] ?? '', 4000, 'Shipping memo');
        $shipping     = $shippingText === '' ? null : ['text' => $shippingText];

        $address       = $customer['address'];
        $templateLines = array_map(static fn ($l) => SalesInput::templateLine($l), $lines);
        $sendEvent     = $duplicate !== null;

        $what    = $quote ? 'quote' : 'sales order';
        $summary = [
            ['Customer', $customer['shipToName'] . ($customer['billTo'] !== $shipTo ? " (billed to {$customer['billToName']})" : '')],
            ['PO number', $po === '' ? '(none)' : $po],
            ['Required', $required->format('Y-m-d')],
            ['Terms', $customer['terms'] === '' ? '(none)' : $customer['terms']],
        ];
        foreach ($lines as $l) {
            $summary[] = ['Line', "{$l['qty']} {$l['unit']} of {$l['code']} at {$l['price']}"
                . ($l['manualPrice'] ? '' : ' (price list)') . ' = ' . SalesInput::money($l['amount'])];
        }
        $summary[] = ['Total before tax', SalesInput::money($total) . ' ' . $customer['currency']];
        if (!$quote) {
            $summary[] = ['Credit', 'CMS runs its credit check when the order is saved; the order may come back on credit hold.'];
        }

        return new Plan(
            Sequence::single("Create {$what} for {$customer['shipToName']}", static function (SequenceState $s) use (
                $header, $templateLines, $address, $internal, $shipping, $quote, $sendEvent
            ) {
                $header['ChangeNumber'] = $s->newId(
                    SalesInput::CHANGE_NUMBER, SalesInput::CHANGE_NUMBER_MIN, SalesInput::CHANGE_NUMBER_MAX
                );
                return SalesRequests::createOrder($header, $templateLines, $address, $internal, $shipping, !$quote, $sendEvent);
            }),
            $summary,
            $warnings,
            'Ordr',
            null,
        );
    }
}

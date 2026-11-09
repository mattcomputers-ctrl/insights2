<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Sales;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Save changes to an open sales order or quote. Only what differs from
 * what CMS holds is sent, as the client does.
 *
 * Input: ordr (int) and any of: po_number, date_required (Y-m-d), ship_via (int),
 * lines {ordDetail: {qty, price}}, remove [ordDetail, …], add [{code, qty, price}, …],
 * internal_memo, shipping_memo, duplicate_po_ok. A key that is absent is left alone.
 */
final class EditOrder implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $o = SalesOrderGuard::load($ctx, $input, ['RTS', 'CSH', 'QUO'], 'changed');

        $header   = [];
        $summary  = [['Order', $o['Ordr'] . ' for ' . $o['ShipToName']]];
        $warnings = [];
        $event    = false;

        if (array_key_exists('po_number', $input)) {
            $po = SalesInput::text($input['po_number'], 50, 'PO number');
            if ($po !== trim($o['PoNumber'])) {
                $duplicate = SalesState::duplicatePo($ctx->cms, $o['BillTo'], $po, $o['Ordr']);
                if ($duplicate !== null && empty($input['duplicate_po_ok'])) {
                    throw new WriteRefused(
                        "PO number \"{$po}\" is already on order {$duplicate} for this customer. "
                        . 'Tick "Duplicate PO number is fine" to save anyway.'
                    );
                }
                if ($duplicate !== null) {
                    $warnings[] = "PO number \"{$po}\" is also on order {$duplicate}.";
                    $event      = true;
                }
                $header['PoNumber'] = $po;
                $summary[] = ['PO number', ($o['PoNumber'] === '' ? '(none)' : $o['PoNumber']) . ' → ' . ($po === '' ? '(none)' : $po)];
            }
        }
        if (array_key_exists('date_required', $input)) {
            $date = SalesInput::date($input['date_required'], 'Required date')->format('Y-m-d');
            if ($date !== substr($o['DateRequired'], 0, 10)) {
                $time = strlen($o['DateRequired']) >= 19 ? substr($o['DateRequired'], 11, 8) : '00:00:00';
                $header['DateRequired'] = $date . 'T' . $time;
                $summary[] = ['Required', substr($o['DateRequired'], 0, 10) . ' → ' . $date];
                if ($date < $ctx->now->format('Y-m-d')) {
                    $warnings[] = 'The new required date is in the past.';
                }
            }
        }
        if (array_key_exists('ship_via', $input) && (int) $input['ship_via'] > 0 && (int) $input['ship_via'] !== $o['ShipVia']) {
            if (!SalesState::isCarrier($ctx->cms, (int) $input['ship_via'])) {
                throw new WriteRefused('The chosen carrier was not found in CMS.');
            }
            $header['ShipVia'] = (int) $input['ship_via'];
            $summary[] = ['Ship via', 'changed' . ($o['ShipViaName'] !== '' ? " from {$o['ShipViaName']}" : '')];
        }

        $byId    = [];
        foreach ($o['lines'] as $l) {
            $byId[$l['ordDetail']] = $l;
        }
        $remove  = array_map('intval', is_array($input['remove'] ?? null) ? $input['remove'] : []);
        $updates = [];
        $deletes = [];
        $inserts = [];

        foreach (is_array($input['lines'] ?? null) ? $input['lines'] : [] as $id => $in) {
            $id = (int) $id;
            if (!is_array($in) || in_array($id, $remove, true)) {
                continue;
            }
            $l = $byId[$id] ?? null;
            if ($l === null) {
                throw new WriteRefused("Line {$id} is no longer on order {$o['Ordr']}. Reload the page.");
            }
            $qty   = SalesInput::number($in['qty'] ?? $l['qty'], "{$l['code']}: quantity");
            $price = SalesInput::number($in['price'] ?? $l['price'], "{$l['code']}: price");
            $c     = [];
            if ((float) $price !== (float) $l['price']) {
                if ((float) $price < 0) {
                    throw new WriteRefused("{$l['code']}: price cannot be negative.");
                }
                if (!$l['manualPrice']) {
                    $c['ManualPrice'] = true;
                }
                $c['Price'] = $price;
            }
            if ((float) $qty !== (float) $l['qty']) {
                if ((float) $qty === 0.0) {
                    throw new WriteRefused("{$l['code']}: quantity cannot be zero. Remove the line instead.");
                }
                if ((float) $l['shipped'] !== 0.0) {
                    throw new WriteRefused("{$l['code']} has already shipped in part; change its quantity in CMS.");
                }
                if (!$o['IsQuote'] && !$l['isOpen']) {
                    throw new WriteRefused("{$l['code']} is a closed line; its quantity cannot be changed.");
                }
                $c['QtyReqd'] = $qty;
                if (!$o['IsQuote']) {
                    $c['QtyCommitted'] = (float) $qty < 0 ? '0' : $qty;
                }
                $c['SupQty']        = $qty;
                $c['SupQtyEntered'] = $qty;
            }
            if ($c !== []) {
                $updates[] = ['op' => 'updateLine', 'ordDetail' => $id, 'changes' => $c];
                $summary[] = ['Change line', "{$l['code']}: {$l['qty']} at {$l['price']} → {$qty} at {$price}"];
            }
        }

        foreach ($remove as $id) {
            $l = $byId[$id] ?? null;
            if ($l === null) {
                throw new WriteRefused("Line {$id} is no longer on order {$o['Ordr']}. Reload the page.");
            }
            if ((float) $l['shipped'] !== 0.0) {
                throw new WriteRefused("{$l['code']} has shipped, so the line cannot be removed.");
            }
            if ($l['pricing'] === null) {
                throw new WriteRefused("{$l['code']}: this line has no pricing row in CMS; remove it in CMS.");
            }
            $deletes[] = ['op' => 'deleteLine', 'ordDetail' => $id, 'pricing' => $l['pricing']];
            $summary[] = ['Remove line', "{$l['qty']} {$l['unit']} of {$l['code']}"];
        }

        $n = 0;
        foreach (is_array($input['add'] ?? null) ? $input['add'] : [] as $raw) {
            if (!is_array($raw) || trim((string) ($raw['code'] ?? '')) === '') {
                continue;
            }
            $line      = SalesInput::line($ctx->cms, $raw, ++$n, $o['BillTo'], $o['ShipTo'], $o['IsQuote'], $ctx->now);
            $inserts[] = ['op' => 'addLine', 'line' => SalesInput::templateLine($line, $o['Owner'])];
            $summary[] = ['Add line', "{$line['qty']} {$line['unit']} of {$line['code']} at {$line['price']}"
                . ($line['manualPrice'] ? '' : ' (price list)')];
        }

        $memos = [];
        foreach (['internal_memo' => 'InternalMemo', 'shipping_memo' => 'ShippingMemo'] as $key => $reference) {
            if (!array_key_exists($key, $input) || $input[$key] === null) {
                continue;
            }
            $text    = SalesInput::text($input[$key], 4000, 'Memo');
            $exists  = array_key_exists($reference, $o['memos']);
            $current = $exists ? SalesInput::text($o['memos'][$reference], 100000, 'Memo') : '';
            if ($text === $current) {
                continue;
            }
            $memos[]   = ['op' => $exists ? 'updateMemo' : 'addMemo', 'reference' => $reference, 'text' => $text, 'memo' => null];
            $summary[] = [$reference === 'InternalMemo' ? 'Internal memo' : 'Shipping memo', $text === '' ? '(cleared)' : $text];
        }

        $changes = array_merge($updates, $deletes, $inserts, $memos);
        if ($header === [] && $changes === []) {
            throw new WriteRefused('Nothing was changed.');
        }

        $credit  = $o['IsQuote'] ? ['mode' => 'none'] : ['mode' => 'check', 'oldReserve' => $o['ReserveAmount']];
        $request = SalesRequests::editOrder($o['Ordr'], $o['Version'], $header, $changes, $credit, $event);

        return new Plan(
            Sequence::single("Change order {$o['Ordr']}", static fn () => $request),
            $summary,
            $warnings,
            'Ordr',
            (string) $o['Ordr'],
        );
    }
}

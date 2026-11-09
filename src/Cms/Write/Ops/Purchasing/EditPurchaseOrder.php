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
 * Save changes to an open purchase order. Only what differs from what CMS
 * holds is sent, as the client does.
 *
 * Input: ordr (int) and any of: lines {ordDetail: {qty, promised}}, remove
 * [ordDetail, …], add [{code, qty, price, promised}, …], memo, incoterms,
 * ship_via (int). A key that is absent is left alone. Line quantities are in
 * the supplier's ordering units, as the PO shows them.
 */
final class EditPurchaseOrder implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $o = PurchasingInput::order($ctx->cms, $input, [''], 'changed');

        $header   = [];
        $summary  = [['Purchase order', $o['Ordr'] . ' to ' . $o['SupplierName']]];
        $warnings = [];

        if (array_key_exists('incoterms', $input) && $input['incoterms'] !== null) {
            $inco = PurchasingInput::incoterms($input['incoterms']);
            if ($inco !== $o['Incoterms']) {
                $header['Incoterms'] = $inco;
                $summary[] = ['Delivery terms', ($o['Incoterms'] === '' ? '(none)' : $o['Incoterms']) . ' → ' . ($inco === '' ? '(none)' : $inco)];
            }
        }
        if (array_key_exists('ship_via', $input) && (int) $input['ship_via'] > 0 && (int) $input['ship_via'] !== $o['ShipVia']) {
            if (!SalesState::isCarrier($ctx->cms, (int) $input['ship_via'])) {
                throw new WriteRefused('The chosen carrier was not found in CMS.');
            }
            $header['ShipVia'] = (int) $input['ship_via'];
            $summary[] = ['Ship via', 'changed' . ($o['ShipViaName'] !== '' ? " from {$o['ShipViaName']}" : '')];
        }

        $byId = [];
        foreach ($o['lines'] as $l) {
            $byId[$l['ordDetail']] = $l;
        }
        $remove  = array_values(array_unique(array_map('intval', is_array($input['remove'] ?? null) ? $input['remove'] : [])));
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
                throw new WriteRefused("Line {$id} is no longer on PO {$o['Ordr']}. Reload the page.");
            }
            $c    = [];
            $said = [];
            if (array_key_exists('qty', $in)) {
                $qty = (float) SalesInput::number($in['qty'], "{$l['code']}: quantity");
                if ($qty !== (float) $l['supQty']) {
                    if ($qty <= 0.0) {
                        throw new WriteRefused("{$l['code']}: quantity must be more than zero. Remove the line instead.");
                    }
                    if ((float) $l['received'] !== 0.0) {
                        throw new WriteRefused("{$l['code']} has already been received in part; change its quantity in CMS.");
                    }
                    if (!$l['isOpen']) {
                        throw new WriteRefused("{$l['code']} is a closed line; its quantity cannot be changed.");
                    }
                    $sup = PurchasingState::dbl($qty);
                    $c['QtyReqd']       = PurchasingState::dbl($qty * (float) $l['perPack']);
                    $c['SupQty']        = $sup;
                    $c['SupQtyEntered'] = $sup;
                    $said[] = "quantity {$l['supQty']} → {$sup}" . ($l['supUnit'] === ''
                        ? " packages ({$c['QtyReqd']} {$l['unit']})" : " {$l['unit']}");
                }
            }
            if (array_key_exists('promised', $in)) {
                $typed = trim((string) $in['promised']);
                $date  = $typed === '' ? '' : SalesInput::date($typed, "{$l['code']}: promised date")->format('Y-m-d');
                if ($date !== $l['promised']) {
                    $c['DatePromised'] = $date === '' ? '' : $date . 'T00:00:00';
                    $said[] = 'promised ' . ($l['promised'] === '' ? '(none)' : $l['promised']) . ' → ' . ($date === '' ? '(none)' : $date);
                }
            }
            if ($c !== []) {
                $updates[] = ['op' => 'updateLine', 'ordDetail' => $id, 'changes' => $c];
                $summary[] = ['Change line', "{$l['code']}: " . implode(', ', $said)];
            }
        }

        foreach ($remove as $id) {
            $l = $byId[$id] ?? null;
            if ($l === null) {
                throw new WriteRefused("Line {$id} is no longer on PO {$o['Ordr']}. Reload the page.");
            }
            if ((float) $l['received'] !== 0.0) {
                throw new WriteRefused("{$l['code']} has been received, so the line cannot be removed.");
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
            $new       = PurchasingInput::line($ctx->cms, $raw, ++$n, $o['Supplier'], $o['Currency'], $ctx->now);
            $inserts[] = ['op' => 'addLine', 'line' => $new['line'], 'owner' => $o['Owner']];
            $summary[] = ['Add line', $new['text']];
            $warnings  = array_merge($warnings, $new['warnings']);
        }
        if (count($o['lines']) - count($deletes) + count($inserts) < 1) {
            throw new WriteRefused('A purchase order needs at least one line. Complete the PO instead of removing its last line.');
        }

        $memos = [];
        if (array_key_exists('memo', $input) && $input['memo'] !== null) {
            $text = SalesInput::text($input['memo'], 4000, 'Memo');
            $old  = $o['memo'] === null ? '' : SalesInput::text($o['memo'], 100000, 'Memo');
            if ($text !== $old) {
                $memos[]   = ['op' => $o['memo'] === null ? 'addMemo' : 'updateMemo', 'text' => $text];
                $summary[] = ['Memo on the PO', $text === '' ? '(cleared)' : $text];
            }
        }

        $changes = array_merge($updates, $deletes, $inserts, $memos);
        if ($header === [] && $changes === []) {
            throw new WriteRefused('Nothing was changed.');
        }
        $request = PurchasingRequests::editOrder($o['Ordr'], $o['Version'], $header, $changes);

        return new Plan(
            Sequence::single("Change purchase order {$o['Ordr']}", static fn () => $request),
            $summary,
            $warnings,
            'Ordr',
            (string) $o['Ordr'],
        );
    }
}

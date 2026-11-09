<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Shipping;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Ops\Sales\SalesOrderGuard;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Take stock out of a shipping container and put it back in the stock
 * location, before the order ships.
 *
 * Input: order (int), container (location id), qty (order line id =>
 * quantity to take out; blank lines stay in). Without qty, everything
 * in the container comes out.
 */
final class UnassembleOrder implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $cms = $ctx->cms;
        $o   = SalesOrderGuard::load($ctx, ['ordr' => (int) ($input['order'] ?? 0)], ['RTS'], 'changed');
        $id  = $o['Ordr'];

        $target = AssembleOrder::target($input['container'] ?? '', ShippingState::assemblies($cms, $id), $id);
        if ($target === null) {
            throw new WriteRefused('Choose the container to take stock out of.');
        }
        $inside = [];
        foreach (ShippingState::contents($cms, $target['location']) as $in) {
            $inside[$in['ordDetail']] = $in;
        }
        if ($inside === []) {
            throw new WriteRefused("Container {$target['code']} is empty.");
        }
        $stock = ShippingState::locationVersion($cms, (int) ShippingRequests::STOCK);
        if ($stock === null) {
            throw new WriteRefused('The stock location was not found in CMS.');
        }

        $all = !is_array($input['qty'] ?? null);
        $ask = $all ? array_map(static fn (array $in): string => Fmt::num(round($in['qty'], 6)), $inside) : $input['qty'];

        $lines   = [];
        $summary = [['Order', "{$id}" . ($o['ShipToName'] !== '' ? " for {$o['ShipToName']}" : '')],
                    ['Out of', "container {$target['code']}"]];
        foreach ($ask as $ordDetail => $text) {
            if (is_array($text) || trim((string) $text) === '') {
                continue;
            }
            $in = $inside[(int) $ordDetail] ?? null;
            if ($in === null) {
                throw new WriteRefused("Container {$target['code']} has changed in CMS. Open the order again.");
            }
            $what = "Quantity of {$in['code']}";
            $qty  = AssembleOrder::quantity($text, $what);
            if ((float) $qty > $in['qty'] + 1e-9) {
                throw new WriteRefused("{$what}: the container holds " . Fmt::num(round($in['qty'], 6)) . " {$in['unit']}.");
            }
            $lines[]   = ['ordDetail' => $in['ordDetail'], 'item' => $in['item'], 'qty' => $qty];
            $summary[] = [$in['code'], "{$qty} {$in['unit']} back to stock"
                . ((float) $qty < $in['qty'] - 1e-9 ? ' (' . Fmt::num(round($in['qty'] - (float) $qty, 6)) . " {$in['unit']} stays in)" : '')];
        }
        if ($lines === []) {
            throw new WriteRefused('Enter the quantity to take out for at least one line.');
        }
        $summary[] = ['Result', 'The stock is no longer reserved to the order. The container stays in CMS, even when empty.'];

        $request = ShippingRequests::assembleRemove($id, $o['Version'], $ctx->owner, $target['location'], $target['version'], $stock, $lines);
        return new Plan(
            Sequence::single("Take stock out of container {$target['code']}", static fn (): Request => $request),
            $summary,
            [],
            'Ordr',
            (string) $id,
        );
    }
}

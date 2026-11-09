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
 * Pick stock for a sales order's lines into a shipping container: a new
 * one, or one already started for this order. The stock is reserved to
 * the order and waits there for a waybill.
 *
 * Input: order (int), container (location id of a container already
 * assembled for this order; empty or "new" for a new one),
 * qty (order line id => quantity in the item's stock unit; blank lines
 * are left out).
 */
final class AssembleOrder implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $cms = $ctx->cms;
        $o   = SalesOrderGuard::load($ctx, ['ordr' => (int) ($input['order'] ?? 0)], ['RTS'], 'assembled');
        $id  = $o['Ordr'];

        $containers = ShippingState::assemblies($cms, $id);
        $target     = self::target($input['container'] ?? '', $containers, $id);
        $assembled  = self::assembled($cms, $containers);

        $byLine = array_column($o['lines'], null, 'ordDetail');
        $lines  = [];
        $summary = [['Order', "{$id}" . ($o['ShipToName'] !== '' ? " for {$o['ShipToName']}" : '')],
                    ['Into', $target === null ? 'a new container' : "container {$target['code']}"]];
        foreach (is_array($input['qty'] ?? null) ? $input['qty'] : [] as $ordDetail => $text) {
            if (is_array($text) || trim((string) $text) === '') {
                continue;
            }
            $l = $byLine[(int) $ordDetail] ?? null;
            if ($l === null) {
                throw new WriteRefused("Order {$id} has changed in CMS. Open it again.");
            }
            $what = "Quantity of {$l['code']}";
            $qty  = self::quantity($text, $what);
            if (!$l['isOpen']) {
                throw new WriteRefused("The line for {$l['code']} is closed, so nothing more can be assembled for it.");
            }
            $left = (float) $l['qty'] - (float) $l['shipped'] - ($assembled[$l['ordDetail']] ?? 0.0);
            if ((float) $qty > $left + 1e-9) {
                throw new WriteRefused("{$what}: only " . Fmt::num(round(max(0.0, $left), 6)) . " {$l['unit']} is left to assemble on this line"
                    . ' (ordered ' . Fmt::num($l['qty']) . ', shipped ' . Fmt::num($l['shipped'])
                    . ', already in containers ' . Fmt::num(round($assembled[$l['ordDetail']] ?? 0.0, 6)) . ').');
            }
            $onHand = ShippingState::onHand($cms, $l['item']);
            if ((float) $qty > $onHand + 1e-9) {
                throw new WriteRefused("{$what}: only " . Fmt::num(round(max(0.0, $onHand), 6)) . " {$l['unit']} of {$l['itemCode']} is in stock.");
            }
            $lines[]   = ['ordDetail' => $l['ordDetail'], 'item' => $l['item'], 'qty' => $qty];
            $summary[] = [trim("{$l['code']} {$l['description']}"), "{$qty} {$l['unit']} from stock"
                . ((float) $qty < $left - 1e-9 ? ' (' . Fmt::num(round($left - (float) $qty, 6)) . " {$l['unit']} still to assemble)" : '')];
        }
        if ($lines === []) {
            throw new WriteRefused('Enter the quantity picked for at least one line.');
        }
        $summary[] = ['Result', 'The stock is reserved to the order and waits in the container for a waybill.'];

        $request = $target === null
            ? ShippingRequests::assembleNew($id, $o['Version'], $ctx->owner, $lines)
            : ShippingRequests::assembleAdd($id, $o['Version'], $ctx->owner, $target['location'], $target['version'], $lines);
        return new Plan(
            Sequence::single("Assemble order {$id}", static fn (): Request => $request),
            $summary,
            [],
            'Ordr',
            (string) $id,
        );
    }

    /**
     * The container named in the input, which must be one already assembled for this order; null = a new one.
     *
     * @param list<array{location:int,code:string,version:int}> $containers
     * @return null|array{location:int,code:string,version:int}
     */
    public static function target(mixed $raw, array $containers, int $ordr): ?array
    {
        $raw = is_array($raw) ? '' : strtolower(trim((string) $raw));
        if ($raw === '' || $raw === 'new' || $raw === '0') {
            return null;
        }
        foreach ($containers as $c) {
            if ((string) $c['location'] === $raw) {
                return $c;
            }
        }
        throw new WriteRefused("That container is not assembled for order {$ordr}. Open the order again.");
    }

    /**
     * How much of each order line already sits in the order's containers.
     *
     * @param list<array{location:int}> $containers
     * @return array<int,float> order line => quantity
     */
    public static function assembled(\PII\Cms\Write\CmsReader $cms, array $containers): array
    {
        $out = [];
        foreach ($containers as $c) {
            foreach (ShippingState::contents($cms, $c['location']) as $in) {
                $out[$in['ordDetail']] = ($out[$in['ordDetail']] ?? 0.0) + $in['qty'];
            }
        }
        return $out;
    }

    /** A typed quantity above zero, as CMS text. */
    public static function quantity(mixed $v, string $what): string
    {
        $s = is_array($v) ? '' : str_replace(',', '', trim((string) $v));
        if ($s === '' || !preg_match('/^\d*\.?\d+$|^\d+\.$/', $s) || (float) $s <= 0) {
            throw new WriteRefused("{$what} needs a number above zero.");
        }
        return Fmt::num(round((float) $s, 6));
    }
}

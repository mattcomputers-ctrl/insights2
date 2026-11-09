<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Purchasing;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Ops\Sales\SalesInput;
use PII\Cms\Write\Ops\Sales\SalesState;
use PII\Cms\Write\WriteRefused;

/** Validation shared by the purchasing flows: typed PO lines and the order guard. */
final class PurchasingInput
{
    private const STATUS = ['' => 'open', 'CMP' => 'completed', 'CLS' => 'closed'];

    /**
     * Turn one typed line (code, qty, price, promised) into the template's line.
     *
     * The quantity is in the supplier's ordering units: packages when the
     * supplier's price list sells the item by the package, otherwise the
     * item's stock unit.
     *
     * @param array<string,mixed> $raw
     * @return array{line:array<string,mixed>,code:string,amount:float,text:string,warnings:list<string>}
     */
    public static function line(CmsReader $cms, array $raw, int $n, int $supplier, string $currency, \DateTimeInterface $on): array
    {
        $code = trim((string) ($raw['code'] ?? ''));
        if ($code === '') {
            throw new WriteRefused("Line {$n}: enter an item.");
        }
        $item = PurchasingState::resolveLine($cms, $supplier, $code, $on);
        if (isset($item['error'])) {
            throw new WriteRefused("Line {$n}: {$item['error']}");
        }
        if ($item['unit'] === '') {
            throw new WriteRefused("Line {$n}: item {$item['code']} has no unit in CMS.");
        }
        $qty = (float) SalesInput::number($raw['qty'] ?? '', "Line {$n}: quantity");
        if ($qty <= 0.0) {
            throw new WriteRefused("Line {$n}: quantity must be more than zero.");
        }
        $typed = trim((string) ($raw['price'] ?? ''));
        if ($typed !== '') {
            $typed = SalesInput::number($typed, "Line {$n}: price");
            if ((float) $typed < 0) {
                throw new WriteRefused("Line {$n}: price cannot be negative.");
            }
        }
        $promised = trim((string) ($raw['promised'] ?? ''));
        $promised = $promised === '' ? null : Fmt::date(SalesInput::date($promised, "Line {$n}: promised date"));

        $row      = $item['row'];
        $warnings = [];
        $perPack  = 1.0;
        $supUnit  = $item['unit'];
        if ($row === null) {
            if ($typed === '') {
                throw new WriteRefused("Line {$n}: enter a price for {$item['code']} (it is not on this supplier's price list).");
            }
            $price   = $typed;
            $pricing = ['Item' => $item['item'], 'Currency' => $currency, 'EntityUnit' => $item['unit'],
                        'PriceByPackage' => false, 'QtyPerEntityQty' => '1', 'EntityUnit2' => $item['unit']];
            $warnings[] = "{$item['code']} is not on this supplier's price list.";
        } else {
            if ($row['priceByPackage']) {
                throw new WriteRefused("Line {$n}: {$item['code']} is priced per package on this supplier's price list. Enter this order in CMS.");
            }
            if (strcasecmp($row['unit'], $item['unit']) !== 0) {
                throw new WriteRefused("Line {$n}: the supplier sells {$item['code']} by the {$row['unit']} but it is stocked by the {$item['unit']}. Enter this order in CMS.");
            }
            if ($row['currency'] !== '' && strcasecmp($row['currency'], $currency) !== 0) {
                throw new WriteRefused("Line {$n}: the price list for {$item['code']} is in {$row['currency']}, the order is in {$currency}.");
            }
            if ($row['tiers'] === []) {
                throw new WriteRefused("Line {$n}: the price list has no price for {$item['code']}. Enter this order in CMS.");
            }
            $packaged = $row['perPack'] !== null;
            if ($packaged) {
                $perPack = $row['perPack'];
                $supUnit = '';
            }
            if ($typed !== '') {
                $price = $typed;
            } elseif (count($row['tiers']) > 1 && $packaged) {
                throw new WriteRefused("Line {$n}: {$item['code']} has quantity-break prices and is sold by the package. Enter the price.");
            } else {
                $tier = $row['tiers'][0];
                foreach ($row['tiers'] as $t) {
                    if ($t['min'] <= $qty && $t['min'] >= $tier['min']) {
                        $tier = $t;
                    }
                }
                if (!$packaged && $qty < $row['tiers'][0]['min']) {
                    $warnings[] = "{$item['code']}: " . PurchasingState::dbl($qty) . " {$item['unit']} is below the supplier's minimum order of "
                        . PurchasingState::dbl($row['tiers'][0]['min']) . " {$item['unit']}.";
                }
                $price = SalesState::plain($tier['price']);
            }

            $pricing = ['PriceDetail' => $row['priceDetail'], 'PriceVersion' => $row['priceVersion'], 'Item' => $item['item']];
            foreach (['EntityItemCode' => $row['supplierCode'], 'Description' => $row['description'],
                      'Comment' => SalesInput::text($row['comment'], 100000, 'Comment'), 'Currency' => $row['currency']] as $k => $v) {
                if ($v !== '') {
                    $pricing[$k] = $v;
                }
            }
            if ($packaged) {
                $pricing['PkgType']        = $row['pkgType'];
                $pricing['EntityQuantity'] = PurchasingState::dbl($perPack);
            }
            $pricing['EntityUnit']     = $row['unit'];
            $pricing['PriceByPackage'] = false;
            foreach ($row['tiers'] as $t) {
                $pricing["MinOrder{$t['n']}"] = PurchasingState::dbl($t['min']);
                $pricing["Price{$t['n']}"]    = $t['price'];
            }
            $pricing['QtyPerEntityQty'] = PurchasingState::dbl($perPack);
            if ($packaged) {
                $pricing['PkgType2']        = $row['pkgType'];
                $pricing['EntityQuantity2'] = PurchasingState::dbl($perPack);
            }
            $pricing['EntityUnit2'] = $row['unit'];
        }

        $stockQty = $qty * $perPack;
        $text     = $supUnit === ''
            ? PurchasingState::dbl($qty) . ' × ' . PurchasingState::dbl($perPack) . " {$item['unit']} = " . Fmt::num($stockQty) . " {$item['unit']}"
            : PurchasingState::dbl($qty) . " {$item['unit']}";
        $description = ($row !== null && $row['description'] !== '') ? $row['description'] : $item['description'];

        return [
            'line' => [
                'item'         => $item['item'],
                'description'  => $description,
                'qtyReqd'      => PurchasingState::dbl($stockQty),
                'supQty'       => PurchasingState::dbl($qty),
                'supUnit'      => $supUnit,
                'percentUnder' => $item['percentUnder'],
                'percentOver'  => $item['percentOver'],
                'datePromised' => $promised,
                'manualPrice'  => $typed !== '' || $row === null,
                'price'        => $price,
                'pricing'      => $pricing,
            ],
            'code'     => $item['code'],
            'amount'   => $stockQty * (float) $price,
            'text'     => "{$item['code']}: {$text} at {$price} per {$item['unit']}"
                . ($typed !== '' ? ' (typed price)' : '') . ($promised !== null ? ', promised ' . substr($promised, 0, 10) : ''),
            'warnings' => $warnings,
        ];
    }

    /**
     * Load the purchase order a flow acts on; refuse when it is missing or
     * not in a state the action applies to.
     *
     * @param list<string> $allowed statuses ('' = open)
     * @return array<string,mixed> PurchasingState::order()
     */
    public static function order(CmsReader $cms, array $input, array $allowed, string $verb): array
    {
        $id = (int) ($input['ordr'] ?? 0);
        $o  = $id > 0 ? PurchasingState::order($cms, $id) : null;
        if ($o === null) {
            throw new WriteRefused('That purchase order was not found in CMS.');
        }
        if (!in_array($o['Status'], $allowed, true)) {
            $is = self::STATUS[$o['Status']] ?? "in status {$o['Status']}";
            throw new WriteRefused("PO {$id} is {$is}, so it cannot be {$verb} here.");
        }
        return $o;
    }

    /** The delivery terms as typed, or refuse. '' = none. */
    public static function incoterms(mixed $v): string
    {
        $s = trim((string) $v);
        foreach (PurchasingState::INCOTERMS as $known) {
            if (strcasecmp($s, $known) === 0) {
                return $known;
            }
        }
        if ($s !== '') {
            throw new WriteRefused('Delivery terms must be one of: ' . implode(', ', PurchasingState::INCOTERMS) . '.');
        }
        return '';
    }
}

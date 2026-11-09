<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Add an item to the price list a supplier has in effect, at one price.
 *
 * Input: supplier (code), item (our code), supplier_code, package (package
 * type item, blank when sold loose), per_pack, price, min_order (1 when
 * blank), description (the item's own when blank).
 */
final class AddPriceLine implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $supplier = ItemState::supplier($ctx->cms, $input['supplier'] ?? '');
        $list     = PriceListState::currentOrRefuse($ctx->cms, $supplier, $ctx->now);
        if ($supplier['currency'] !== '' && strcasecmp($supplier['currency'], 'USD') !== 0) {
            throw new WriteRefused("Supplier {$supplier['code']} is paid in {$supplier['currency']}. Add the item to its price list in CMS.");
        }
        $item = ItemState::stocked($ctx->cms, $input['item'] ?? '');
        if ($item['unit'] === '') {
            throw new WriteRefused("Item {$item['code']} has no stock unit in CMS.");
        }

        $description = trim((string) (is_scalar($input['description'] ?? null) ? $input['description'] : ''));
        $description = $description === '' ? $item['description'] : ItemInput::description($description);
        if ($description === '') {
            throw new WriteRefused('Enter a description.');
        }
        $code     = PriceListInput::supplierCode($input['supplier_code'] ?? '');
        $price    = PriceListInput::price($input['price'] ?? '');
        $minText  = trim((string) (is_scalar($input['min_order'] ?? null) ? $input['min_order'] : ''));
        $minOrder = $minText === '' ? '1' : PriceListInput::quantity($minText, 'the minimum order');

        $package = null;
        $perPack = null;
        $pkgText = trim((string) (is_scalar($input['package'] ?? null) ? $input['package'] : ''));
        $perText = trim((string) (is_scalar($input['per_pack'] ?? null) ? $input['per_pack'] : ''));
        if ($pkgText !== '') {
            foreach (PriceListState::packages($ctx->cms) as $p) {
                if ((string) $p['item'] === $pkgText) {
                    $package = $p;
                }
            }
            if ($package === null) {
                throw new WriteRefused('Choose the package from the list.');
            }
            $perPack = PriceListInput::quantity($perText, "how much {$item['unit']} is in one {$package['code']}");
        } elseif ($perText !== '') {
            throw new WriteRefused('Choose the package the quantity is for, or leave the quantity empty.');
        }

        foreach (PriceListState::lines($ctx->cms, $list['priceVersion']) as $l) {
            $samePack = $package === null
                ? $l['pkgType'] === null
                : ($l['pkgType'] === $package['item'] && $l['perPack'] !== null && abs($l['perPack'] - (float) $perPack) < 0.00005);
            if ($l['item'] === $item['item'] && $samePack) {
                throw new WriteRefused("{$item['code']} is already on this price list, " . PriceListState::pack($l) . '. Change its price instead.');
            }
        }

        $line = [
            'item' => $item['item'], 'description' => $description, 'unit' => $item['unit'], 'minOrder' => $minOrder,
            'price' => $price, 'currency' => 'USD', 'pkgType' => $package['item'] ?? null, 'perPack' => $perPack, 'supplierCode' => $code,
        ];
        $summary = [
            ['Supplier', trim("{$supplier['code']} {$supplier['name']}")],
            ['Price list', "in effect since {$list['effective']}"],
            ['Item', trim("{$item['code']} {$description}")],
            ['Sold', $package === null ? "by the {$item['unit']}" : "{$package['code']} of {$perPack} {$item['unit']}"],
            ['Price', PriceListState::money($price) . " USD per {$item['unit']}" . ($minOrder === '1' ? '' : ", from {$minOrder}")],
        ];
        if ($code !== '') {
            $summary[] = ["Supplier's code", $code];
        }
        $warnings = [];
        if ($list['later'] !== null) {
            $warnings[] = "A price list dated {$list['later']} takes over later. The item is not added to it; add it in CMS too if it should be.";
        }

        $request = PriceListRequests::lineInsert($list['priceVersion'], $list['version'], $line);
        return new Plan(
            Sequence::single("Add {$item['code']} to the price list of {$supplier['code']}", static fn (): Request => $request),
            $summary,
            $warnings,
            'PriceVersion',
            (string) $list['priceVersion'],
        );
    }
}

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
 * Change one line of the price list a supplier has in effect: its price,
 * the supplier's own code for the item, or both.
 *
 * Input: supplier (code), line (PriceDetail), price, supplier_code.
 */
final class EditPriceLine implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $supplier = ItemState::supplier($ctx->cms, $input['supplier'] ?? '');
        $list     = PriceListState::currentOrRefuse($ctx->cms, $supplier, $ctx->now);
        $id       = is_scalar($input['line'] ?? null) && ctype_digit(trim((string) $input['line'])) ? (int) trim((string) $input['line']) : 0;
        $line     = null;
        foreach (PriceListState::lines($ctx->cms, $list['priceVersion']) as $l) {
            if ($l['priceDetail'] === $id) {
                $line = $l;
            }
        }
        if ($line === null) {
            throw new WriteRefused("That line is not on the price list {$supplier['code']} has in effect. Open the price list again.");
        }

        $price = PriceListInput::price($input['price'] ?? '');
        $code  = PriceListInput::supplierCode($input['supplier_code'] ?? '');
        $unit  = $line['unit'] !== '' ? $line['unit'] : 'unit';

        $changes = [];
        $summary = [
            ['Supplier', trim("{$supplier['code']} {$supplier['name']}")],
            ['Item', trim("{$line['itemCode']} {$line['description']}") . ', ' . PriceListState::pack($line)],
        ];
        if ($line['price'] === null || abs((float) $price - (float) $line['price']) >= 0.00005) {
            if ($line['tiered']) {
                throw new WriteRefused("{$line['itemCode']} has prices by quantity on this list. Change those in CMS.");
            }
            $changes['Price1'] = $price;
            $summary[] = ['Price', PriceListState::money($price) . " {$line['currency']} per " . ($line['byPackage'] ? 'package' : $unit)
                . ($line['price'] === null ? ' (none before)' : " (was {$line['price']})")];
        }
        if ($code !== $line['supplierCode']) {
            if ($code === '') {
                throw new WriteRefused("The supplier's code cannot be removed here. Remove it in CMS.");
            }
            $changes['EntityItemCode'] = $code;
            $summary[] = ["Supplier's code", $code . ($line['supplierCode'] === '' ? ' (none before)' : " (was {$line['supplierCode']})")];
        }
        if ($changes === []) {
            throw new WriteRefused('Nothing was changed.');
        }

        $warnings = ['Purchase orders that are already entered keep the price and code they have.'];
        if ($list['later'] !== null) {
            $warnings[] = "A price list dated {$list['later']} takes over later. It is not changed; change it in CMS too if it should be.";
        }
        $request = PriceListRequests::lineUpdate($list['priceVersion'], $list['version'], $line['priceDetail'], $line['version'], $changes);
        return new Plan(
            Sequence::single("Change {$line['itemCode']} on the price list of {$supplier['code']}", static fn (): Request => $request),
            $summary,
            $warnings,
            'PriceVersion',
            (string) $list['priceVersion'],
        );
    }
}

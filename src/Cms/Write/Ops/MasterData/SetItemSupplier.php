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
 * Set the preferred supplier of an item: the supplier Need to Order
 * proposes to buy it from.
 *
 * Input: item (code), supplier (the supplier's code).
 */
final class SetItemSupplier implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $item = ItemState::stocked($ctx->cms, $input['item'] ?? '');
        if ($item['context'] !== 'SUNDRY') {
            throw new WriteRefused("Item {$item['code']} is a pack size of a product that is made here, so it has no supplier.");
        }
        $supplier = ItemState::supplier($ctx->cms, $input['supplier'] ?? '');
        if ($item['supplier'] === $supplier['entity']) {
            throw new WriteRefused('Nothing was changed.');
        }

        $request = ItemRequests::supplierUpdate($item['item'], $item['version'], $supplier['entity'], $ctx->owner);
        return new Plan(
            Sequence::single("Set the preferred supplier of item {$item['code']}", static fn (): Request => $request),
            [
                ['Item', trim("{$item['code']} {$item['description']}")],
                ['Preferred supplier', trim("{$supplier['code']} {$supplier['name']}") . ($item['supplier'] === null ? ' (none before)' : '')],
            ],
            [],
            'Item',
            (string) $item['item'],
        );
    }
}

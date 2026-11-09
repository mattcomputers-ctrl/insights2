<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Set the minimum stock of a stock item: the level Need to Order and the
 * low-stock lists work from.
 *
 * Input: item (code), minimum (a number from 0 up, in the item's stock unit).
 */
final class SetMinimumStock implements Flow
{
    /** The minimum that Insights shows as "discontinued" (a convention of the item page). */
    public const DISCONTINUED = 99.0;

    public function plan(array $input, FlowContext $ctx): Plan
    {
        $item     = ItemState::stocked($ctx->cms, $input['item'] ?? '');
        $minimum  = ItemInput::minimum($input['minimum'] ?? '');
        $settings = ItemState::stockSettings($ctx->cms, $item['item'], $ctx->owner);
        $was      = $settings['minimum'] ?? null;
        if ($was !== null && abs($was - (float) $minimum) < 1e-9) {
            throw new WriteRefused('Nothing was changed.');
        }

        $request = $settings === null
            ? ItemRequests::minimumStockInsert($item['item'], $item['version'], $minimum, $ctx->owner)
            : ItemRequests::minimumStockUpdate($item['item'], $item['version'], $settings['itemEntity'], $minimum, $ctx->owner);
        $warnings = abs((float) $minimum - self::DISCONTINUED) < 1e-9
            ? ['A minimum stock of 99 marks the item as discontinued in Insights.'] : [];
        return new Plan(
            Sequence::single("Set the minimum stock of item {$item['code']}", static fn (): Request => $request),
            [
                ['Item', trim("{$item['code']} {$item['description']}")],
                ['Minimum stock', trim("{$minimum} {$item['unit']}") . ($was !== null ? ' (was ' . Fmt::num($was) . ')' : ' (none before)')],
            ],
            $warnings,
            'Item',
            (string) $item['item'],
        );
    }
}

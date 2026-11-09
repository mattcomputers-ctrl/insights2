<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;

/**
 * Give a stock item another name (a customer's or a supplier's code for it).
 *
 * Input: item (code of the stock item), code (the alias),
 * description (blank = the item's own description).
 */
final class CreateAlias implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $item  = ItemState::target($ctx->cms, $input['item'] ?? '');
        $code  = ItemInput::newCode($ctx->cms, $input['code'] ?? '');
        $about = trim((string) ($input['description'] ?? '')) === '' && $item['description'] !== ''
            ? $item['description'] : ItemInput::description($input['description'] ?? '');

        $request = ItemRequests::aliasCreate($code, $about, $item['item'], $ctx->cmsUser, $ctx->owner);
        return new Plan(
            Sequence::single("Add alias {$code} to item {$item['code']}", static fn (): Request => $request),
            [['Alias', $code], ['Description', $about], ['Stands for', trim("{$item['code']} {$item['description']}")]],
            [],
            'Item',
            '',
        );
    }
}

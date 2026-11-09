<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;

/**
 * Create an item that needs no pack sizes, tests or recipe: a raw
 * material or a packaging item. Products that are made here are created
 * in the client, where their pack sizes and tests are set up with them.
 *
 * Input: code, description, kind (a key of ItemRequests::KINDS).
 */
final class CreateItem implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $kind  = ItemRequests::KINDS[ItemInput::kind($input['kind'] ?? '')];
        $code  = ItemInput::newCode($ctx->cms, $input['code'] ?? '');
        $about = ItemInput::description($input['description'] ?? '');

        $request = ItemRequests::itemCreate($code, $about, $kind['unit'], $kind['glGroup'], $kind['group'],
            $kind['shipInContainer'], $ctx->cmsUser, $ctx->owner);
        $warnings = ['The item is created without a supplier, a minimum stock or a cost. Set those afterwards.'];
        if ($kind['glGroup'] === 'Packaging') {
            // In the client about half of new packaging items are set not
            // to be tracked. Only the tracked kind is created here.
            $warnings[] = 'The item is created with its stock tracked. A packaging item that is not tracked is created in CMS.';
        }
        return new Plan(
            Sequence::single("Create item {$code}", static fn (): Request => $request),
            [['Item', $code], ['Description', $about], ['Kind', $kind['label']], ['Stock unit', $kind['unit']]],
            $warnings,
            'Item',
            '',
        );
    }
}

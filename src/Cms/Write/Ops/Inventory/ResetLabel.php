<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Inventory;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Mark a container as labelled: clears the "label needed" flag CMS sets
 * when a container is filled, as the client does after printing.
 *
 * Input: location (int, the container).
 */
final class ResetLabel implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $l = InventoryState::container($ctx->cms, (int) ($input['location'] ?? 0));
        if ($l === null) {
            throw new WriteRefused('That container was not found in CMS.');
        }
        if (!$l['LabelReqd']) {
            throw new WriteRefused("Container {$l['Code']} is not waiting for a label.");
        }
        $request = InventoryRequests::resetLabel($l['Location']);
        return new Plan(
            Sequence::single("Mark container {$l['Code']} as labelled", static fn (): Request => $request),
            [['Container', $l['Code']], ['Result', 'CMS no longer lists this container as needing a label. Nothing is printed.']],
            [],
            'Location',
            (string) $l['Location'],
        );
    }
}

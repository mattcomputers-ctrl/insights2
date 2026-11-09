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
 * Post a saved count: stock on hand of every item on it becomes the
 * counted quantity. The adjustment of each line is counted minus what
 * CMS has on hand at the moment of posting.
 *
 * Input: count (int).
 */
final class PostCount implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $c  = InventoryState::open($ctx->cms, $input['count'] ?? 0);
        $id = $c['InventoryCount'];
        if ($c['lines'] === []) {
            throw new WriteRefused("Count {$id} has no lines to post.");
        }

        $summary = [['Count', "{$id}" . ($c['Description'] !== '' ? ": {$c['Description']}" : '') . ', ' . substr($c['EffectiveDate'], 0, 10)]];
        $post    = [];
        foreach ($c['lines'] as $l) {
            $post[] = [
                'detail' => $l['detail'], 'item' => $l['item'], 'location' => $l['location'],
                'qty'    => $l['entered'] !== '' ? $l['entered'] : InventoryRequests::dbl($l['qty']),
                'adjust' => InventoryRequests::adjustment($l['qty'], $l['onHand']),
            ];
            $summary[] = [trim("{$l['code']} {$l['description']}"), CountInput::effect($l['unit'], $l['qty'], $l['onHand'])];
        }
        $summary[] = ['Result', 'Stock on hand becomes the counted quantity for every item above.'];

        $request = InventoryRequests::postCount($id, $c['Version'], $c['Owner'], $c['EffectiveDate'], $post);
        return new Plan(
            Sequence::single("Post inventory count {$id}", static fn (): Request => $request),
            $summary,
            ['Posting changes stock on hand and cannot be undone here.'],
            'InventoryCount',
            (string) $id,
        );
    }
}

<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Inventory;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\SequenceState;
use PII\Cms\Write\WriteRefused;

/**
 * Start an inventory count: a date, an optional description and the
 * counted quantity of each item. Saving changes no stock; the count is
 * posted afterwards (or straight away, see CreateAndPostCount).
 *
 * Input: date (Y-m-d), description (text), lines (list of item code + qty).
 */
class CreateCount implements Flow
{
    protected const POST = false;

    public function plan(array $input, FlowContext $ctx): Plan
    {
        $cms   = $ctx->cms;
        $date  = CountInput::date($input['date'] ?? '', $ctx->now);
        $about = CountInput::description($input['description'] ?? '');
        $lines = [];
        $seen  = [];
        foreach (is_array($input['lines'] ?? null) ? $input['lines'] : [] as $raw) {
            $code = trim((string) (is_array($raw) ? ($raw['item'] ?? '') : ''));
            $qty  = trim((string) (is_array($raw) ? ($raw['qty'] ?? '') : ''));
            if ($code === '' && $qty === '') {
                continue;
            }
            if ($code === '') {
                throw new WriteRefused("A quantity ({$qty}) has no item next to it.");
            }
            $item = InventoryState::item($cms, $code);
            if (isset($seen[$item['item']])) {
                throw new WriteRefused("Item {$item['code']} is on the count twice.");
            }
            $seen[$item['item']] = true;
            $lines[] = $item + ['qty' => CountInput::qty($qty, "The quantity of {$item['code']}"),
                                'onHand' => InventoryState::onHand($cms, $item['item'])];
        }
        if ($lines === []) {
            throw new WriteRefused('Enter at least one item and its counted quantity.');
        }
        if (count($lines) > CountInput::MAX_LINES) {
            throw new WriteRefused('A count here takes at most ' . CountInput::MAX_LINES . ' items.');
        }

        $day     = InventoryRequests::day($date);
        $owner   = $ctx->owner;
        $create  = InventoryRequests::createCount($owner, $about, $day, $lines);
        $summary = [['Count', $date->format('M j, Y') . ($about !== '' ? ": {$about}" : '')]];
        foreach ($lines as $l) {
            $summary[] = [trim("{$l['code']} {$l['description']}"), CountInput::effect($l['unit'], (float) $l['qty'], $l['onHand'])];
        }

        if (!static::POST) {
            $summary[] = ['Result', 'The count is saved. Stock changes only when the count is posted.'];
            return new Plan(Sequence::single('Save a new inventory count', static fn (): Request => $create), $summary, [], 'InventoryCount', '');
        }

        $seq = new Sequence('Save and post a new inventory count');
        $seq->stage('Save the count', static fn (): Request => $create);
        $seq->stage('Post the count', static function (SequenceState $s) use ($lines, $owner, $day): Request {
            $post = [];
            foreach ($lines as $i => $l) {
                $post[] = [
                    'detail' => $s->output(1, InventoryRequests::STEP_COUNT + 1 + $i, 'InventoryCountDetail', 'InventoryCountDetail'),
                    'item'   => $l['item'], 'qty' => $l['qty'],
                    'adjust' => InventoryRequests::adjustment((float) $l['qty'], $l['onHand']),
                ];
            }
            // A count that was just saved is at version 1.
            return InventoryRequests::postCount(
                $s->output(1, InventoryRequests::STEP_COUNT, 'InventoryCount', 'InventoryCount'), 1, $owner, $day, $post);
        });
        $summary[] = ['Result', 'Stock on hand becomes the counted quantity for every item above.'];
        return new Plan($seq, $summary, ['Posting changes stock on hand and cannot be undone here.'], 'InventoryCount', '');
    }
}

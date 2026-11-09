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
 * Change a count that is not posted yet: its description, its date and
 * the counted quantity of its lines. Adding or removing items is not
 * done here (delete the count and start a new one).
 *
 * Input: count (int), description (text, optional), date (Y-m-d, optional),
 * qty (line id => quantity; a blank or missing line is left as it is).
 */
final class EditCount implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $c  = InventoryState::open($ctx->cms, $input['count'] ?? 0);
        $id = $c['InventoryCount'];

        $head    = [];
        $summary = [['Count', "{$id}" . ($c['Description'] !== '' ? ": {$c['Description']}" : '')]];
        if (array_key_exists('description', $input)) {
            $about = CountInput::description($input['description']);
            if ($about !== $c['Description']) {
                $head['Description'] = $about;
                $summary[] = ['Description', $about === '' ? '(cleared)' : $about];
            }
        }
        if (trim((string) ($input['date'] ?? '')) !== '') {
            $day = InventoryRequests::day(CountInput::date($input['date'], $ctx->now));
            if ($day !== $c['EffectiveDate']) {
                $head['EffectiveDate'] = $day;
                $summary[] = ['Count date', substr($day, 0, 10) . ' (was ' . substr($c['EffectiveDate'], 0, 10) . ')'];
            }
        }

        $typed = is_array($input['qty'] ?? null) ? $input['qty'] : [];
        $known = array_column($c['lines'], null, 'detail');
        foreach ($typed as $detail => $text) {
            if (!isset($known[(int) $detail])) {
                throw new WriteRefused("Count {$id} has changed in CMS. Open it again.");
            }
        }
        $lines = [];
        foreach ($c['lines'] as $l) {
            $text = trim((string) ($typed[$l['detail']] ?? ''));
            if ($text === '') {
                continue;
            }
            $qty = CountInput::qty($text, "The quantity of {$l['code']}");
            if ((float) $qty === $l['qty']) {
                continue;
            }
            $lines[]   = ['detail' => $l['detail'], 'qty' => $qty];
            $summary[] = [trim("{$l['code']} {$l['description']}"), "{$qty} {$l['unit']} counted (was " . InventoryState::show($l['qty']) . ')'];
        }
        if ($head === [] && $lines === []) {
            throw new WriteRefused('Nothing was changed.');
        }

        $request = InventoryRequests::editCount($id, $c['Version'], $head, $lines);
        return new Plan(Sequence::single("Change inventory count {$id}", static fn (): Request => $request),
            $summary, [], 'InventoryCount', (string) $id);
    }
}

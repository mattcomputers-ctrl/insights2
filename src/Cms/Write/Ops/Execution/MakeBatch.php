<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Execution;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingInput;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingState;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Make the batch in one go: the ingredients are consumed from stock into
 * the ticket's vessel and its steps are marked done.
 * Input: ordr (int), qty (line id => quantity actually used; a line left
 * out uses the ticket quantity, a blank or zero means not used).
 */
final class MakeBatch implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $t = ExecutionState::bulk($ctx->cms, $input);
        ExecutionState::onFloor($t);
        if ($t['made']) {
            throw new WriteRefused("Ticket {$t['Ordr']} has been made already.");
        }
        $typed = is_array($input['qty'] ?? null) ? $input['qty'] : [];

        $moves    = [];
        $list     = [];
        $changed  = [];
        $skipped  = [];
        $noCost   = [];
        $total    = 0.0;
        $value    = 0.0;
        foreach (ExecutionState::ingredients($t) as $l) {
            $planned = $l['qty'] ?? 0.0;
            if (array_key_exists($l['id'], $typed)) {
                $text = trim((string) $typed[$l['id']]);
                $qty  = $text === '' || (is_numeric($text) && (float) $text == 0.0)
                    ? 0.0 : ManufacturingInput::quantity($text, "Quantity of {$l['code']}");
            } else {
                $qty = $planned;
            }
            if ($qty <= 0.0) {
                $skipped[] = $l['code'];
                continue;
            }
            $q       = ManufacturingState::qty($qty);
            $moves[] = ['line' => $l['id'], 'item' => $l['item'], 'qty' => $q];
            $list[]  = "{$q} {$l['unit']} {$l['code']}";
            if (abs($qty - $planned) > 1e-9) {
                $changed[] = "{$l['code']}: {$q} instead of " . ManufacturingState::qty($planned);
            }
            if ($l['cost'] === null || $l['cost'] <= 0.0) {
                $noCost[] = $l['code'];
            }
            $total += $qty;
            $value += $qty * ($l['cost'] ?? 0.0);
        }
        if ($moves === []) {
            throw new WriteRefused("Ticket {$t['Ordr']} has no ingredient with a quantity to use.");
        }

        // The steps still open, in the order the floor works them; the final test step goes last.
        $open = array_values(array_filter($t['steps'],
            static fn (array $l): bool => in_array($l['context'], ['INSTR', 'UI', 'FT'], true) && !$l['done']));
        usort($open, static fn (array $a, array $b): int
            => [$a['context'] === 'FT' ? 1 : 0, $a['execOrder'] ?? PHP_INT_MAX, $a['id']]
            <=> [$b['context'] === 'FT' ? 1 : 0, $b['execOrder'] ?? PHP_INT_MAX, $b['id']]);
        $done = array_map(static fn (array $l): array => ['line' => $l['id'], 'weighed' => $l['context'] === 'UI'], $open);

        $size = (float) ($t['product']['qty'] ?? 0.0);
        if ($t['changeSet'] !== null) {
            // The make was undone before: CMS keeps the production record and the vessel, and they are used again.
            if ($t['vessel'] === null) {
                throw new WriteRefused("Ticket {$t['Ordr']} was made and undone before, but its vessel was not found. Make it in CMS.");
            }
            $into = ['changeSet' => $t['changeSet'], 'vessel' => $t['vessel']['id']];
            $cost = null;
        } else {
            $cost = $size > 0.0 ? ManufacturingState::qty(round($value / $size, 6)) : '0';
            $into = ['item' => (int) $t['product']['item'], 'unitCost' => $cost];
        }
        $request = ExecutionRequests::makeBatch($t['Ordr'], $t['Version'], $t['Owner'], $t['batch'], $moves, $done, $into);

        $summary = [
            ['Batch ticket', "{$t['Ordr']}: " . ManufacturingState::qty($size) . " lb of {$t['product']['code']} {$t['product']['description']}"],
            ['Ingredients used', implode(', ', $list)],
            ['Total used', ManufacturingState::qty($total)],
            ['Result', 'The ingredients leave stock and the batch is in vessel VO' . $t['Ordr']
                . ($t['changeSet'] !== null ? ' again (this make was undone before)' : '')],
        ];
        if ($cost !== null) {
            $summary[] = ['Estimated cost', "{$cost} per lb, from the ingredients' replacement costs"];
        }
        $warnings = [];
        if ($changed !== []) {
            $warnings[] = 'Quantities differ from the ticket: ' . implode('; ', $changed) . '.';
        }
        if ($skipped !== []) {
            $warnings[] = 'Not used in this batch: ' . implode(', ', $skipped) . '.';
        }
        if ($cost !== null && $noCost !== []) {
            $warnings[] = 'No replacement cost in CMS for ' . implode(', ', $noCost) . ': the estimated cost leaves them out.';
        }

        return new Plan(
            Sequence::single("Make batch ticket {$t['Ordr']}", static fn () => $request),
            $summary, $warnings, 'Ordr', (string) $t['Ordr'],
        );
    }
}

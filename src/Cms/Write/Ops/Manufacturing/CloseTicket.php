<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Manufacturing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Close a bulk ticket: either one production has completed, or one that
 * was never released (which cancels it). A ticket on the floor is closed
 * in CMS, where what was made and used is settled first.
 * Input: ordr (int).
 */
final class CloseTicket implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $t    = ManufacturingInput::ticket($ctx->cms, $input);
        $what = [];
        foreach ($t['products'] as $p) {
            $what[] = ManufacturingState::qty((float) ($p['qty'] ?? 0.0)) . " {$p['unit']} of {$p['code']}";
        }
        $summary  = [['Batch ticket', "{$t['Ordr']}: " . implode(', ', $what)]];
        $warnings = [];
        $packs    = array_filter($t['packs'], static fn (array $p): bool => $p['status'] !== 'CLS');

        if ($t['Status'] === 'CMP') {
            $request   = ManufacturingRequests::close($t['Ordr'], $t['Version']);
            $summary[] = ['Result', 'The completed ticket is closed'];
        } elseif (ManufacturingInput::unreleased($t)) {
            $open = array_values(array_filter($t['lines'], static fn (array $l): bool => $l['isOpen']));
            $pk   = array_filter($open, static fn (array $l): bool => $l['context'] === 'PK');
            $ui   = array_filter($open, static fn (array $l): bool => $l['context'] === 'UI' && !$l['repack']);
            // A repack ingredient line has no line number. The client closes it last and zeroes what it had committed.
            $repack = array_values(array_filter($open, static fn (array $l): bool => $l['context'] === 'UI' && $l['repack']));
            $lineNo = array_map(static fn (array $l): ?int => $l['line'], $ui);
            if (count($repack) > 1 || in_array(null, $lineNo, true) || count(array_unique($lineNo)) !== count($lineNo)) {
                throw new WriteRefused("Ticket {$t['Ordr']} has ingredient lines Insights cannot put in the order CMS closes them. Close it in CMS.");
            }
            usort($ui, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
            $ids       = static fn (array $lines): array => array_map(static fn (array $l): int => $l['id'], $lines);
            $request   = ManufacturingRequests::close($t['Ordr'], $t['Version'], Fmt::dateTime($ctx->now),
                $ids([...$pk, ...$ui]), $ids($repack));
            $summary[]  = ['Result', 'The ticket is cancelled: closed without being made'];
            $warnings[] = 'This ticket was never released. Closing it cancels it; it cannot be reopened here.';
        } else {
            throw new WriteRefused("Ticket {$t['Ordr']} is " . ManufacturingInput::statusText($t)
                . '. Only a completed ticket, or one never released, is closed here.');
        }
        if ($packs !== []) {
            $summary[] = ['Packaging tickets', 'CMS closes the linked ones with it: '
                . implode(', ', array_map(static fn (array $p): string => "{$p['ordr']} ({$p['code']})", $packs))];
        }

        return new Plan(
            Sequence::single("Close batch ticket {$t['Ordr']}", static fn () => $request),
            $summary, $warnings, 'Ordr', (string) $t['Ordr'],
        );
    }
}

<?php

declare(strict_types=1);

namespace PII\Modules\Scheduling;

/**
 * ScheduleEngine — builds the weekly production schedule.
 *
 * v2 (Matt, 2026-11-04): the schedule now describes REALITY instead of
 * re-deriving need from inventory math. Demand units come pre-assembled
 * (SchedulingDataService::workload(), built from the Order Readiness
 * snapshot) in four tiers:
 *
 *   tier 0  OPEN BULK TICKETS (RLS/NST) — committed work already cut in
 *           CMS. Previously invisible: the old engine re-planned batches
 *           that were already sitting on the mill-room desk AND pretended
 *           their hours didn't exist. Always scheduled (no milling gate,
 *           missing config just warns), exact ticket quantity, sequenced
 *           by materials-readiness then due date then ladder. A ticket
 *           short raw materials is flagged and placed after runnable
 *           tickets — it can't physically run first.
 *   tier 1  ORDER SHORTFALLS on make-to-order items (coverage engine's
 *           short lines, items with no minimum) — exact lbs, MTO.
 *   tier 2  BELOW MINIMUM (production-plan suggestions: already credit
 *           stock + open tickets − open demand, honor marks and the
 *           99-discontinued rule) — suggested, pre-rounded quantities.
 *   tier 3  NEAR MINIMUM — fill leftover capacity only.
 *
 * Unchanged mill mechanics:
 *   - MILLING GATE (tiers 1-3): formulas matching neither dry-grind nor
 *     milling triggers are blends — excluded unless a batch exceeds 50 lbs.
 *   - SEQUENCE: within each tier, dry-grind batches place before standard
 *     ones (dry work only runs on capable mills); each partition is
 *     ladder-sorted to minimise washups.
 *   - PLACEMENT: earliest completion; ties by washup penalty; standard
 *     batches prefer non-dry-capable mills absolutely; carryover across
 *     days supported; washups charged before a run (like/next/deep).
 */
class ScheduleEngine
{
    private const MIN_SPLIT_HOURS = 0.5;  // don't start a batch with less than this left in a day

    /** Non-trigger (blend) items still schedule when any batch exceeds this. */
    private const UNMILLED_MIN_LBS = 50.0;

    /** @var list<string> */
    private array $colorOrder;
    /** @var array<string,int> color → ladder index */
    private array $colorIndex;

    public function __construct(array $colorOrder)
    {
        $this->colorOrder = array_values($colorOrder);
        $this->colorIndex = array_flip($this->colorOrder);
    }

    /**
     * @param list<array> $demands  from SchedulingDataService::workload():
     *        {bulk, lbs, tier(0-3), source('ticket'|'order'|'min'|'near'),
     *         ticket?, due?, materials_short?, description?, packs?}
     * @param array<string,array> $itemConfigs   bulk → {color,batch_size_1,batch_size_2,not_milled}
     * @param list<array> $mills           sched_mills rows (active)
     * @param array<string,float> $popularity    bulk → trailing shipped lbs
     * @param string $weekStart            Monday date YYYY-MM-DD
     * @param list<int> $enabledDays       7 flags Mon..Sun (1 = run)
     * @param array<string,int> $passesByBulk   derived (dry-grind) passes; absent = 1
     * @param array<string,bool> $dryByBulk     derived dry-grind flag
     * @param array<string,string> $bulkDescriptions  bulk → Item.Description
     * @param array<string,bool> $milledByBulk  derived "needs milling"; absent = milled (fail-open)
     * @param list<float> $defaultSizes   global default batch sizes (largest
     *        first) used when an item has no config (Matt, 2026-11-04)
     * @param array<string,string> $guessedColors  bulk → color guessed from
     *        alias prefixes, used only when the item has no config
     */
    public function build(
        array $demands,
        array $itemConfigs,
        array $mills,
        array $popularity,
        string $weekStart,
        array $enabledDays,
        array $passesByBulk = [],
        array $dryByBulk = [],
        array $bulkDescriptions = [],
        array $milledByBulk = [],
        array $defaultSizes = [],
        array $guessedColors = []
    ): array {
        $warnings = [];
        $unconfiguredTickets = [];
        $noQtyAssumed = [];
        $noQtySkipped = [];
        $unconfiguredWork = [];
        $defaultedWork = [];
        $ladderEnd = count($this->colorOrder);   // unknown colors sort last

        $defaultSizes = array_values(array_filter(array_map('floatval', $defaultSizes), fn($v) => $v > 0));
        rsort($defaultSizes);

        // Configured sizes (up to 5), largest first; [] when unconfigured.
        $sizesFor = function (?array $cfg) use ($defaultSizes): array {
            if ($cfg === null) {
                return $defaultSizes;
            }
            $sizes = $cfg['sizes'] ?? array_values(array_filter(
                [(float) $cfg['batch_size_1'], (float) ($cfg['batch_size_2'] ?? 0)],
                fn($v) => $v > 0
            ));
            rsort($sizes);
            return $sizes !== [] ? $sizes : $defaultSizes;
        };

        // ── explode demand units into batches ──────────────────────────
        $batches = [];
        foreach ($demands as $d) {
            $bulk = (string) $d['bulk'];
            $tier = (int) $d['tier'];
            $cfg  = $itemConfigs[$bulk] ?? null;

            if ($tier === 0) {
                // Committed ticket: it exists, so it schedules — config
                // only adds color/size knowledge.
                $sizes = $sizesFor($cfg);
                $lbs = (float) ($d['lbs'] ?? 0);
                if ($lbs <= 0 && $sizes !== []) {
                    $lbs = $sizes[0];
                    $noQtyAssumed[] = "{$d['ticket']} ({$bulk})";
                } elseif ($lbs <= 0) {
                    $noQtySkipped[] = "{$d['ticket']} ({$bulk})";
                    continue;
                }
                if ($cfg === null) {
                    $unconfiguredTickets[] = "{$d['ticket']} ({$bulk})";
                }
                $color = $cfg['color'] ?? ($guessedColors[$bulk] ?? null);
                $batches[] = $this->batchRow($d, $bulk, 1, 1, $lbs, $color,
                    $color !== null ? ($this->colorIndex[$color] ?? $ladderEnd) : $ladderEnd,
                    $passesByBulk, $dryByBulk, $bulkDescriptions, $popularity);
                continue;
            }

            // Tiers 1-3: suggested work — config and milling gate apply.
            if ((float) $d['lbs'] < 0.5) {
                continue;   // sub-pound rounding noise, not a batch
            }
            $sizes = $sizesFor($cfg);
            if ($cfg === null && $sizes === []) {
                $unconfiguredWork[] = "{$bulk} (" . round((float) $d['lbs']) . " lbs, {$d['source']})";
                continue;
            }
            if ($cfg !== null && !empty($cfg['not_milled'])) {
                continue;   // flagged Not Milled / Not Scheduled — silently excluded
            }
            $color = $cfg['color'] ?? ($guessedColors[$bulk] ?? null);
            if ($cfg !== null && !isset($this->colorIndex[$cfg['color']])) {
                $warnings[] = "Bulk item {$bulk} has unknown color '{$cfg['color']}' — not scheduled.";
                continue;
            }
            if ($cfg === null) {
                // No config but global default sizes exist — schedule with
                // the defaults and the alias-guessed color (Matt, 2026-11-04).
                $defaultedWork[] = "{$bulk}" . ($color !== null ? " ({$color}?)" : '');
            }
            $colorIdx = $color !== null ? ($this->colorIndex[$color] ?? $ladderEnd) : $ladderEnd;

            if ($sizes === []) {
                $unconfiguredWork[] = "{$bulk} (" . round((float) $d['lbs']) . " lbs, {$d['source']})";
                continue;
            }

            // Chunk at the largest standard size; the last chunk is exact.
            // (Below-minimum quantities arrive pre-rounded by the production
            // plan; order shortfalls are make-to-order and must stay exact.)
            $batchLbsList = [];
            $remaining = (float) $d['lbs'];
            while ($remaining > 1e-9) {
                $chunk = min($remaining, $sizes[0]);
                $batchLbsList[] = round($chunk, 1);
                $remaining -= $chunk;
            }

            // Milling gate: blends of pre-ground materials are bench work,
            // not mill work — unless the batch is big enough to plan anyway.
            $isMilled = array_key_exists($bulk, $milledByBulk) ? (bool) $milledByBulk[$bulk] : true;
            if (!$isMilled && ($batchLbsList === [] || max($batchLbsList) <= self::UNMILLED_MIN_LBS)) {
                continue;
            }

            foreach ($batchLbsList as $i => $lbs) {
                $batches[] = $this->batchRow($d, $bulk, $i + 1, count($batchLbsList), $lbs,
                    $color, $colorIdx,
                    $passesByBulk, $dryByBulk, $bulkDescriptions, $popularity);
            }
        }

        $summarize = function (array $list, string $text) use (&$warnings): void {
            if ($list === []) {
                return;
            }
            $shown = array_slice($list, 0, 8);
            $more = count($list) - count($shown);
            $warnings[] = count($list) . ' ' . $text . ': ' . implode(', ', $shown)
                . ($more > 0 ? " and {$more} more" : '') . '.';
        };
        $summarize($unconfiguredWork, 'item(s) need production but have no scheduling config (color/batch size) and no default batch sizes are set — NOT scheduled; configure them in Scheduling → Settings');
        $summarize($defaultedWork, 'item(s) have no scheduling config — scheduled with the default batch sizes and a color guessed from alias prefixes or the item description (? = guessed; blank = unknown, worst-case washups). Configure them in Scheduling → Settings');
        $summarize($noQtyAssumed, 'open ticket(s) have no quantity in CMS — assumed one standard batch each');
        $summarize($noQtySkipped, 'open ticket(s) have no quantity in CMS and no batch size configured — NOT scheduled');
        if ($unconfiguredTickets !== []) {
            $shown = array_slice($unconfiguredTickets, 0, 8);
            $more = count($unconfiguredTickets) - count($shown);
            $warnings[] = count($unconfiguredTickets) . ' open ticket(s) have no scheduling config — scheduled with '
                . 'a color guessed from alias prefixes or the item description where possible (otherwise worst-case washups): ' . implode(', ', $shown)
                . ($more > 0 ? " and {$more} more" : '') . '. Configure them in Scheduling → Settings.';
        }

        // ── sequence ───────────────────────────────────────────────────
        // Partitions: (tier 0..3) × (dry first, then standard). Tier-0
        // sorts runnable-first, then due date, then ladder; suggested
        // tiers sort pure ladder (minimise washups — they all "can" wait).
        $sortTier0 = fn(array $a, array $b): int =>
            [(int) $a['materials_short'], $a['due'] ?? '9999', $a['color_idx'], $a['bulk']]
            <=> [(int) $b['materials_short'], $b['due'] ?? '9999', $b['color_idx'], $b['bulk']];
        $ladderSort = fn(array $a, array $b): int =>
            [$a['color_idx'], $a['bulk'], $a['batch_no']] <=> [$b['color_idx'], $b['bulk'], $b['batch_no']];

        $sequence = [];
        for ($tier = 0; $tier <= 3; $tier++) {
            foreach ([true, false] as $dry) {
                $part = array_values(array_filter(
                    $batches,
                    fn($b) => $b['tier'] === $tier && (bool) ($b['dry_grind'] ?? false) === $dry
                ));
                usort($part, $tier === 0 ? $sortTier0 : $ladderSort);
                $sequence = array_merge($sequence, $part);
            }
        }

        // ── placement ──────────────────────────────────────────────────
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = [
                'date'    => date('Y-m-d', strtotime($weekStart . " +{$i} day")),
                'dow'     => date('D', strtotime($weekStart . " +{$i} day")),
                'enabled' => !empty($enabledDays[$i]),
            ];
        }

        $millState = [];
        foreach ($mills as $m) {
            $remain = [];
            foreach ($days as $i => $d) {
                $remain[$i] = $d['enabled'] ? (float) $m['hours_per_day'] : 0.0;
            }
            $millState[$m['id']] = [
                'mill'      => $m,
                'remain'    => $remain,
                'last_color'=> null,
                'runs'      => array_fill(0, 7, []),
            ];
        }

        $unscheduled = [];
        foreach ($sequence as $batch) {
            if (!$this->placeBatch($batch, $millState, $days)) {
                $batch['reason'] = $this->unscheduledReason($batch, $mills);
                $unscheduled[] = $batch;
            }
        }
        usort($unscheduled, fn($a, $b) =>
            [$a['tier'], -$b['popularity']] <=> [$b['tier'], -$a['popularity']]);

        // ── output ─────────────────────────────────────────────────────
        $millsOut = [];
        foreach ($millState as $st) {
            $daysOut = [];
            foreach ($days as $i => $d) {
                $daysOut[] = [
                    'date'    => $d['date'],
                    'dow'     => $d['dow'],
                    'enabled' => $d['enabled'],
                    'hours_total'  => $d['enabled'] ? (float) $st['mill']['hours_per_day'] : 0.0,
                    'hours_used'   => $d['enabled'] ? round((float) $st['mill']['hours_per_day'] - $st['remain'][$i], 2) : 0.0,
                    'runs'    => $st['runs'][$i],
                ];
            }
            $millsOut[] = [
                'mill_id'   => (int) $st['mill']['id'],
                'mill_name' => (string) $st['mill']['name'],
                'max_batch_lbs' => (float) $st['mill']['max_batch_lbs'],
                'days'      => $daysOut,
            ];
        }

        return [
            'week_start'  => $weekStart,
            'days'        => $days,
            'mills'       => $millsOut,
            'unscheduled' => array_map(fn($b) => [
                'bulk' => $b['bulk'], 'lbs' => $b['lbs'], 'color' => $b['color'] ?? '(unknown)',
                'description' => $b['description'] ?? '',
                'tier' => $b['tier'], 'source' => $b['source'],
                'ticket' => $b['ticket'], 'due' => $b['due'],
                'materials_short' => $b['materials_short'],
                'tier1' => $b['tier'] <= 1,
                'popularity' => round($b['popularity']),
                'dry_grind' => $b['dry_grind'] ?? false,
                'mto' => $b['mto'] ?? false,
                'reason' => $b['reason'] ?? 'no capacity this week',
                'pack_breakdown' => $b['pack_breakdown'],
            ], $unscheduled),
            'warnings'    => $warnings,
            'color_order' => $this->colorOrder,
        ];
    }

    /** One batch row from a demand unit. */
    private function batchRow(
        array $d, string $bulk, int $no, int $count, float $lbs,
        ?string $color, int $colorIdx,
        array $passesByBulk, array $dryByBulk, array $bulkDescriptions, array $popularity
    ): array {
        // Pack breakdown: committed tickets carry their real pack runs;
        // suggested batches pro-rate the listed packs by need share.
        $breakdown = [];
        $packs = $d['packs'] ?? [];
        $totalNeed = array_sum(array_map(fn($p) => (float) ($p['lbs'] ?? 0), $packs));
        foreach ($packs as $p) {
            $breakdown[] = [
                'pack'        => (string) $p['pack'],
                'description' => (string) ($p['description'] ?? ''),
                'lbs'         => (int) $d['tier'] === 0
                    ? round((float) ($p['lbs'] ?? 0), 1)
                    : round($totalNeed > 0 ? $lbs * ((float) $p['lbs'] / $totalNeed) : 0, 1),
            ];
        }

        return [
            'id'          => ($d['ticket'] ?? $bulk) . '#' . $no,
            'bulk'        => $bulk,
            'description' => $d['description'] ?? ($bulkDescriptions[$bulk] ?? ''),
            'batch_no'    => $no,
            'batch_count' => $count,
            'color'       => $color,
            'color_idx'   => $colorIdx,
            'passes'      => max(1, (int) ($passesByBulk[$bulk] ?? 1)),
            'dry_grind'   => (bool) ($dryByBulk[$bulk] ?? false),
            'tier'        => (int) $d['tier'],
            'source'      => (string) $d['source'],
            'ticket'      => $d['ticket'] ?? null,
            'due'         => $d['due'] ?? null,
            'materials_short' => (bool) ($d['materials_short'] ?? false),
            'mto'         => $d['source'] === 'order',
            'lbs'         => $lbs,
            'popularity'  => $popularity[$bulk] ?? 0.0,
            'pack_breakdown' => $breakdown,
        ];
    }

    /**
     * Try to place a batch on the best (mill, day). Mutates $millState.
     * Supports carryover: a batch may span consecutive enabled days.
     */
    private function placeBatch(array $batch, array &$millState, array $days): bool
    {
        $best = null;

        foreach ($millState as $millId => $st) {
            $m = $st['mill'];
            if (!empty($batch['dry_grind']) && empty($m['dry_grind_capable'])) {
                continue;
            }
            if ((float) $m['max_batch_lbs'] > 0 && $batch['lbs'] > (float) $m['max_batch_lbs']) {
                continue;
            }
            $rate = !empty($batch['dry_grind'])
                ? (float) ($m['lbs_per_hour_dry'] ?? 0)
                : (float) $m['lbs_per_hour'];
            if ($rate <= 0) continue;

            $washTier = $this->washupTier($st['last_color'], $batch['color_idx']);
            $washHours = $this->washupHours($m, $washTier);

            $millHours = ($batch['lbs'] * $batch['passes']) / $rate;
            $totalHours = $millHours + $washHours;

            for ($d = 0; $d < 7; $d++) {
                if ($st['remain'][$d] < self::MIN_SPLIT_HOURS) continue;

                $needed = $totalHours;
                $endDay = null;
                for ($e = $d; $e < 7 && $needed > 1e-9; $e++) {
                    if ($st['remain'][$e] <= 0) {
                        continue;
                    }
                    $take = min($st['remain'][$e], $needed);
                    $needed -= $take;
                    $endDay = $e;
                }
                if ($needed > 1e-9) {
                    break;
                }

                // Standard batches prefer NON-dry-capable mills absolutely.
                $capablePenalty = (empty($batch['dry_grind']) && !empty($m['dry_grind_capable'])) ? 1 : 0;

                $completionKey = [$capablePenalty, $endDay, $d, $washTier];
                if ($best === null || $completionKey < $best['key']) {
                    $best = [
                        'mill_id' => $millId, 'start' => $d, 'end' => $endDay,
                        'wash_tier' => $washTier, 'wash_hours' => $washHours,
                        'mill_hours' => $millHours, 'key' => $completionKey,
                    ];
                }
                break;
            }
        }

        if ($best === null) {
            return false;
        }

        $st = &$millState[$best['mill_id']];
        $needed = $best['mill_hours'] + $best['wash_hours'];
        $first  = true;
        for ($e = $best['start']; $e < 7 && $needed > 1e-9; $e++) {
            if ($st['remain'][$e] <= 0) continue;
            $take = min($st['remain'][$e], $needed);
            $st['remain'][$e] -= $take;
            $needed -= $take;

            $st['runs'][$e][] = [
                'run_id'         => $batch['id'],
                'bulk'           => $batch['bulk'],
                'description'    => $batch['description'] ?? '',
                'batch_no'       => $batch['batch_no'],
                'batch_count'    => $batch['batch_count'],
                'color'          => $batch['color'] ?? '(unknown)',
                'lbs'            => $batch['lbs'],
                'passes'         => $batch['passes'],
                'dry_grind'      => $batch['dry_grind'] ?? false,
                'mto'            => $batch['mto'] ?? false,
                'tier'           => $batch['tier'],
                'source'         => $batch['source'],
                'ticket'         => $batch['ticket'],
                'due'            => $batch['due'],
                'materials_short'=> $batch['materials_short'],
                'tier1'          => $batch['tier'] <= 1,
                'carryover'      => !$first,
                'washup'         => $first ? $best['wash_tier'] : null,
                'pack_breakdown' => $batch['pack_breakdown'],
            ];
            $first = false;
        }

        $st['last_color'] = $batch['color_idx'];
        unset($st);
        return true;
    }

    /** Why couldn't this batch place anywhere — for the Unscheduled report. */
    private function unscheduledReason(array $batch, array $mills): string
    {
        $eligible = array_filter($mills, function ($m) use ($batch) {
            if (!empty($batch['dry_grind'])) {
                if (empty($m['dry_grind_capable'])) return false;
                if ((float) ($m['lbs_per_hour_dry'] ?? 0) <= 0) return false;
            } elseif ((float) $m['lbs_per_hour'] <= 0) {
                return false;
            }
            return true;
        });
        if (empty($eligible)) {
            return !empty($batch['dry_grind'])
                ? 'no dry-grind-capable mill (or dry rate not set)'
                : 'no mill with a usable throughput rate';
        }
        $fitsSize = array_filter($eligible, fn($m) =>
            (float) $m['max_batch_lbs'] <= 0 || $batch['lbs'] <= (float) $m['max_batch_lbs']
        );
        if (empty($fitsSize)) {
            return 'exceeds max batch size of every eligible mill';
        }
        return 'no capacity this week';
    }

    /** 'like' | 'next' | 'deep' | 'none' (first run of week on the mill) */
    private function washupTier(?int $lastColorIdx, int $newColorIdx): string
    {
        if ($lastColorIdx === null)         return 'none';
        if ($newColorIdx === $lastColorIdx) return 'like';
        if ($newColorIdx > $lastColorIdx)   return 'next';
        return 'deep';
    }

    private function washupHours(array $mill, string $tier): float
    {
        return match ($tier) {
            'like' => ((float) $mill['washup_like_minutes']) / 60,
            'next' => ((float) $mill['washup_next_minutes']) / 60,
            'deep' => ((float) $mill['washup_deep_minutes']) / 60,
            default => 0.0,
        };
    }
}

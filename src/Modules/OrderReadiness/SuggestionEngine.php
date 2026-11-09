<?php

declare(strict_types=1);

namespace PII\Modules\OrderReadiness;

/**
 * SuggestionEngine — pure production-suggestion math (SPEC §8.3).
 *
 * projected = on hand + open ticket output − open order demand not yet shipped
 *
 * Eligible: items that HAVE a recipe and are NOT discontinued, when
 *   - projected < 0                        → 'negative' (top of the list;
 *     includes items with no minimum at all, §14 Q8), or
 *   - minimum set and projected < minimum  → 'below', or
 *   - minimum set and projected < minimum × (1 + nearPct/100) → 'near'.
 *
 * suggested_qty = shortfall (to minimum, or to zero when no minimum),
 * rounded UP to the item's standard batch size when one is configured.
 *
 * Input rows: [item_code => ['has_recipe','is_discontinued','minimum_stock',
 *   'on_hand','on_tickets','open_demand','recipe_number','last_made']]
 * Batch sizes: [item_code => float] (Insights-configured, §14 Q7)
 */
class SuggestionEngine
{
    public static function compute(array $items, array $batchSizes, float $nearPct): array
    {
        $out = [];
        foreach ($items as $code => $it) {
            if (empty($it['has_recipe']) || !empty($it['is_discontinued'])) {
                continue;   // discontinued items are NEVER suggested (§8.2)
            }
            $min       = $it['minimum_stock'];
            $min       = $min === null ? null : (float) $min;
            $onHand    = (float) ($it['on_hand'] ?? 0);
            $onTickets = (float) ($it['on_tickets'] ?? 0);
            $demand    = (float) ($it['open_demand'] ?? 0);
            $projected = $onHand + $onTickets - $demand;

            $severity = null;
            if ($projected < 0) {
                $severity = 'negative';
            } elseif ($min !== null && $min > 0 && $projected < $min) {
                $severity = 'below';
            } elseif ($min !== null && $min > 0 && $projected < $min * (1 + $nearPct / 100)) {
                $severity = 'near';
            }
            if ($severity === null) {
                continue;
            }

            $target    = max(0.0, $min ?? 0.0);
            $shortfall = max(0.0, $target - $projected);
            $suggested = $shortfall;
            $batch     = (float) ($batchSizes[$code] ?? 0);
            if ($batch > 0 && $suggested > 0) {
                $suggested = ceil($suggested / $batch) * $batch;
            }

            $out[$code] = [
                'item_code'     => $code,
                'severity'      => $severity,
                'minimum_stock' => $min,
                'on_hand'       => round($onHand, 4),
                'on_tickets'    => round($onTickets, 4),
                'open_demand'   => round($demand, 4),
                'projected'     => round($projected, 4),
                'shortfall'     => round($shortfall, 4),
                'suggested_qty' => round($suggested, 4),
                'recipe_number' => $it['recipe_number'] ?? null,
                'last_made'     => $it['last_made'] ?? null,
                // An open ticket already producing this item (main batch
                // number) — shown so nobody cuts a duplicate batch.
                'open_ticket'   => $it['open_ticket'] ?? null,
            ];
        }

        // Negative projected first, then below, then near; biggest shortfall first.
        uasort($out, function ($a, $b) {
            $rank = ['negative' => 0, 'below' => 1, 'near' => 2];
            return [$rank[$a['severity']], -$a['shortfall']] <=> [$rank[$b['severity']], -$b['shortfall']];
        });

        return $out;
    }
}

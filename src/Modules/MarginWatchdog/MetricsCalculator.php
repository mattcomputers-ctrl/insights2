<?php

declare(strict_types=1);

namespace PII\Modules\MarginWatchdog;

/**
 * MetricsCalculator — Derive the executive metrics from raw rollup totals.
 *
 * Input rows are produced by ShipmentService and contain:
 *   baseline_revenue, baseline_cost, baseline_qty
 *   comparison_revenue, comparison_cost, comparison_qty
 *
 * The same calculator is used for top-summary, Bill-To, and Item rows
 * so the maths is consistent everywhere.
 *
 * Per the spec, weighted (by quantity) averages — not simple averages
 * of per-line averages.
 */
class MetricsCalculator
{
    /**
     * Compute all derived metrics from a totals row.
     *
     * Returns:
     *   revenue           : period revenues + diff in $ + diff in %
     *   packed_cost       : period packed costs + diff in $ + diff in %
     *   dollars_over_cost : period $ over packed cost + diff in $ + diff in %
     *   cost_pct_revenue  : period packed-cost-as-%-of-revenue + delta in pp
     *   avg_sale          : weighted avg sale price per unit (by qty) + diff in $ + diff in %
     *   avg_cost          : weighted avg packed cost per unit (by qty) + diff in $ + diff in %
     *   avg_cost_pct      : avg cost ÷ avg sale (per period) + delta in pp
     *   qty               : qty totals
     *
     * NOTE: percent fields are NULL when a period has zero baseline that
     * would make the % undefined. Callers should treat null as "N/A".
     */
    public static function compute(array $row): array
    {
        $bRev = (float) ($row['baseline_revenue']   ?? 0);
        $bCost = (float) ($row['baseline_cost']     ?? 0);
        $bQty = (float) ($row['baseline_qty']       ?? 0);
        $cRev = (float) ($row['comparison_revenue'] ?? 0);
        $cCost = (float) ($row['comparison_cost']   ?? 0);
        $cQty = (float) ($row['comparison_qty']     ?? 0);

        // Forward-looking: today's per-unit replacement cost (from CMS Item.ReplacementCost
        // on the inventory item — pre-computed bulk + packaging). Null = no rep cost on file.
        $repCost = $row['replacement_cost'] ?? null;
        $repCost = $repCost === null ? null : (float) $repCost;

        // Dollars over packed cost
        $bDoc = $bRev - $bCost;
        $cDoc = $cRev - $cCost;

        // Packed cost as % of revenue
        $bCostPct = $bRev != 0.0 ? ($bCost / $bRev) * 100 : null;
        $cCostPct = $cRev != 0.0 ? ($cCost / $cRev) * 100 : null;

        // Weighted avg sale price per unit (qty weighted)
        $bAvgSale = $bQty != 0.0 ? $bRev / $bQty : null;
        $cAvgSale = $cQty != 0.0 ? $cRev / $cQty : null;

        // Weighted avg cost per unit (qty weighted)
        $bAvgCost = $bQty != 0.0 ? $bCost / $bQty : null;
        $cAvgCost = $cQty != 0.0 ? $cCost / $cQty : null;

        // Avg cost as % of avg sale (per period)
        $bAvgCostPct = ($bAvgSale !== null && $bAvgSale != 0.0 && $bAvgCost !== null) ? ($bAvgCost / $bAvgSale) * 100 : null;
        $cAvgCostPct = ($cAvgSale !== null && $cAvgSale != 0.0 && $cAvgCost !== null) ? ($cAvgCost / $cAvgSale) * 100 : null;

        // Determine "exists in both periods" so callers can suppress
        // % change when one side is missing.
        $inBoth = ($bRev != 0.0 || $bQty != 0.0) && ($cRev != 0.0 || $cQty != 0.0);

        // Forward-looking margin pressure:
        //   expected_cost_pct_of_comparison_sale = repCost / cAvgSale × 100
        // What it means: at today's replacement cost, how big a slice of the
        // comparison-period sale price would the cost eat? Compare to the
        // existing avg_cost_pct.comparison to see margin erosion that hasn't
        // happened yet but is on the horizon. Down = good (lower = healthier).
        $expectedCostPctOfCompSale = null;
        $horizonDeltaPp            = null;
        if ($repCost !== null && $cAvgSale !== null && $cAvgSale != 0.0) {
            $expectedCostPctOfCompSale = ($repCost / $cAvgSale) * 100;
            // Δ vs the comparison-period actual cost % of sale: positive = pressure
            if ($cAvgCostPct !== null) {
                $horizonDeltaPp = $expectedCostPctOfCompSale - $cAvgCostPct;
            }
        }

        return [
            'in_both_periods' => $inBoth,
            'qty' => [
                'baseline'      => $bQty,
                'comparison'    => $cQty,
                'diff_units'    => $cQty - $bQty,
                'diff_pct'      => self::pctChange($bQty, $cQty),
            ],
            'revenue' => [
                'baseline'      => $bRev,
                'comparison'    => $cRev,
                'diff_dollars'  => $cRev - $bRev,
                'diff_pct'      => self::pctChange($bRev, $cRev),
            ],
            'packed_cost' => [
                'baseline'      => $bCost,
                'comparison'    => $cCost,
                'diff_dollars'  => $cCost - $bCost,
                'diff_pct'      => self::pctChange($bCost, $cCost),
            ],
            'dollars_over_cost' => [
                'baseline'      => $bDoc,
                'comparison'    => $cDoc,
                'diff_dollars'  => $cDoc - $bDoc,
                'diff_pct'      => self::pctChange($bDoc, $cDoc),
            ],
            'cost_pct_revenue' => [
                'baseline'      => $bCostPct,
                'comparison'    => $cCostPct,
                'diff_pp'       => ($bCostPct !== null && $cCostPct !== null) ? $cCostPct - $bCostPct : null,
            ],
            'avg_sale' => [
                'baseline'      => $bAvgSale,
                'comparison'    => $cAvgSale,
                'diff_dollars'  => ($bAvgSale !== null && $cAvgSale !== null) ? $cAvgSale - $bAvgSale : null,
                'diff_pct'      => self::pctChange($bAvgSale, $cAvgSale),
            ],
            'avg_cost' => [
                'baseline'      => $bAvgCost,
                'comparison'    => $cAvgCost,
                'diff_dollars'  => ($bAvgCost !== null && $cAvgCost !== null) ? $cAvgCost - $bAvgCost : null,
                'diff_pct'      => self::pctChange($bAvgCost, $cAvgCost),
            ],
            'avg_cost_pct' => [
                'baseline'      => $bAvgCostPct,
                'comparison'    => $cAvgCostPct,
                'diff_pp'       => ($bAvgCostPct !== null && $cAvgCostPct !== null) ? $cAvgCostPct - $bAvgCostPct : null,
            ],
            'expected_packed_cost' => $repCost,
            'expected_cost_pct_of_comparison_sale' => [
                'value'                 => $expectedCostPctOfCompSale,
                'horizon_delta_pp'      => $horizonDeltaPp,  // vs comparison-period avg cost %
            ],
        ];
    }

    private static function pctChange(?float $b, ?float $c): ?float
    {
        if ($b === null || $c === null) return null;
        if ($b == 0.0) return null;
        return (($c - $b) / abs($b)) * 100;
    }
}

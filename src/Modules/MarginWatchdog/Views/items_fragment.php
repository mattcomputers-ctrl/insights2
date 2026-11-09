<?php
/**
 * AJAX fragment: items table for one Bill To.
 *
 * @var string $billTo
 * @var array  $items     ['raw'=>..., 'metrics'=>...]
 * @var string $viewMode  'both' | 'either'
 * @var array  $thresholds
 * @var array  $rangeParams
 */
use PII\Modules\MarginWatchdog\Thresholds;

// User pref colors_on isn't needed here — the wrapping <body> class drives it.
$colorsOn = true;

if (!function_exists('mw_item_class')) {
    function mw_item_class(string $metric, ?float $diff, array $thresholds, bool $colorsOn, bool $inBoth): string {
        if (!$colorsOn || !$inBoth) return '';
        $c = \PII\Modules\MarginWatchdog\Thresholds::classify($metric, $diff, $thresholds);
        return $c === 'neutral' ? '' : 'diff-' . $c;
    }
}
?>
<?php if (empty($items)): ?>
    <p class="muted-empty">
        <?php if ($viewMode === 'both'): ?>
            No items sold in BOTH periods. Switch to "EITHER period" to see items sold in only one.
        <?php else: ?>
            No items found.
        <?php endif; ?>
    </p>
<?php else: ?>
<table class="table tabular" style="font-size:0.85rem;">
    <thead>
        <tr>
            <th>Alias</th>
            <th>Description</th>
            <th class="text-right">Qty<br><span class="text-dim" style="font-weight:400;font-size:0.7rem;">Baseline → Comp</span></th>
            <th class="text-right">Revenue<br><span class="text-dim" style="font-weight:400;font-size:0.7rem;">B → C → Δ%</span></th>
            <th class="text-right">Avg Sale/Unit<br><span class="text-dim" style="font-weight:400;font-size:0.7rem;">B → C → Δ%</span></th>
            <th class="text-right">Avg Cost/Unit<br><span class="text-dim" style="font-weight:400;font-size:0.7rem;">B → C → Δ%</span></th>
            <th class="text-right">Avg Cost % of Sale<br><span class="text-dim" style="font-weight:400;font-size:0.7rem;">B → C → Δ%</span></th>
            <th class="text-right" title="Today's packed replacement cost per unit, sourced from CMS Item.ReplacementCost (bulk + packaging, pre-computed by CMS)">Expected Packed Cost<br><span class="text-dim" style="font-weight:400;font-size:0.7rem;">today, /unit</span></th>
            <th class="text-right" title="Today's expected packed cost as a percentage of the comparison-period average sale price. Forward-looking: shows margin pressure that hasn't shown up in shipments yet.">Expected Cost % of Comp Sale<br><span class="text-dim" style="font-weight:400;font-size:0.7rem;">value · Δ vs comp actual</span></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $it):
        $r = $it['raw']; $m = $it['metrics'];
        $unit = $r['unit'] !== '' ? ' ' . e($r['unit']) : '';
    ?>
        <tr>
            <td><span class="tag"><?= e($r['item_name']) ?></span></td>
            <td>
                <?= e($r['description']) ?>
                <?php if (!empty($r['unit_mixed'])): ?>
                    <span class="pill" style="background:rgba(241,196,15,0.18);color:var(--warn);" title="This alias was sold in multiple units of measure during the selected ranges. Totals are summed across units — verify before trusting.">⚠ mixed UoM</span>
                <?php endif; ?>
            </td>
            <td class="text-right">
                <?= fmt_number($m['qty']['baseline'], 0) ?><?= $unit ?> →
                <?= fmt_number($m['qty']['comparison'], 0) ?><?= $unit ?>
            </td>
            <td class="text-right">
                <?= fmt_money($m['revenue']['baseline']) ?> →
                <?= fmt_money($m['revenue']['comparison']) ?>
                <span class="<?= mw_item_class('revenue', $m['revenue']['diff_pct'], $thresholds, $colorsOn, $m['in_both_periods']) ?>" style="margin-left:0.4rem;">
                    <?= $m['revenue']['diff_pct'] === null ? 'N/A' : fmt_signed_pct($m['revenue']['diff_pct']) ?>
                </span>
            </td>
            <td class="text-right">
                <?= $m['avg_sale']['baseline']   === null ? '—' : fmt_money($m['avg_sale']['baseline'], 4) ?> →
                <?= $m['avg_sale']['comparison'] === null ? '—' : fmt_money($m['avg_sale']['comparison'], 4) ?>
                <span class="<?= mw_item_class('avg_sale', $m['avg_sale']['diff_pct'], $thresholds, $colorsOn, $m['in_both_periods']) ?>" style="margin-left:0.4rem;">
                    <?= $m['avg_sale']['diff_pct'] === null ? 'N/A' : fmt_signed_pct($m['avg_sale']['diff_pct']) ?>
                </span>
            </td>
            <td class="text-right">
                <?= $m['avg_cost']['baseline']   === null ? '—' : fmt_money($m['avg_cost']['baseline'], 4) ?> →
                <?= $m['avg_cost']['comparison'] === null ? '—' : fmt_money($m['avg_cost']['comparison'], 4) ?>
                <span class="<?= mw_item_class('avg_cost', $m['avg_cost']['diff_pct'], $thresholds, $colorsOn, $m['in_both_periods']) ?>" style="margin-left:0.4rem;">
                    <?= $m['avg_cost']['diff_pct'] === null ? 'N/A' : fmt_signed_pct($m['avg_cost']['diff_pct']) ?>
                </span>
            </td>
            <td class="text-right">
                <?= $m['avg_cost_pct']['baseline']   === null ? '—' : fmt_pct($m['avg_cost_pct']['baseline']) ?> →
                <?= $m['avg_cost_pct']['comparison'] === null ? '—' : fmt_pct($m['avg_cost_pct']['comparison']) ?>
                <span class="<?= mw_item_class('avg_cost_pct', $m['avg_cost_pct']['diff_pp'], $thresholds, $colorsOn, $m['in_both_periods']) ?>" style="margin-left:0.4rem;">
                    <?= $m['avg_cost_pct']['diff_pp'] === null ? 'N/A' : fmt_pp($m['avg_cost_pct']['diff_pp']) ?>
                </span>
            </td>
            <td class="text-right">
                <?= $m['expected_packed_cost'] === null ? '<span class="text-dim">N/A</span>' : fmt_money($m['expected_packed_cost'], 4) ?>
            </td>
            <td class="text-right">
                <?php
                    $expValue   = $m['expected_cost_pct_of_comparison_sale']['value'];
                    $horizonPp  = $m['expected_cost_pct_of_comparison_sale']['horizon_delta_pp'];
                    // Coloring: only when we have BOTH the comp-period actual and the
                    // forward-looking value (so the delta is meaningful). The single
                    // value itself is just informational.
                    $hasBoth    = $expValue !== null && $horizonPp !== null;
                    $cls        = mw_item_class('expected_cost_pct', $horizonPp, $thresholds, $colorsOn, $hasBoth);
                ?>
                <?= $expValue === null ? '<span class="text-dim">N/A</span>' : fmt_pct($expValue) ?>
                <span class="<?= $cls ?>" style="margin-left:0.4rem;">
                    <?= $horizonPp === null ? 'N/A' : fmt_pp($horizonPp) ?>
                </span>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

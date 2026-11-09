<?php
/**
 * Top-level company-wide summary card.
 *
 * @var array $summary
 * @var array $thresholds
 * @var array $params
 * @var bool  $colorsOn
 * @var array $billTos
 * @var string $sort
 */
use PII\Modules\MarginWatchdog\Thresholds;

$bsLabel = fmt_date($params['baseline_start']) . ' — ' . fmt_date($params['baseline_end']);
$ccLabel = fmt_date($params['comparison_start']) . ' — ' . fmt_date($params['comparison_end']);

if (!function_exists('mw_class_for')) {
    function mw_class_for(string $metric, ?float $diff, array $thresholds, bool $colorsOn, bool $inBoth): string {
        if (!$colorsOn || !$inBoth) return '';   // no color for items in only one period
        $cls = \PII\Modules\MarginWatchdog\Thresholds::classify($metric, $diff, $thresholds);
        return $cls === 'neutral' ? '' : 'diff-' . $cls;
    }
}

// Build URL for export with current params
$exportUrl = '/margin-watchdog/export?' . http_build_query([
    'baseline_start'   => $params['baseline_start'],
    'baseline_end'     => $params['baseline_end'],
    'comparison_start' => $params['comparison_start'],
    'comparison_end'   => $params['comparison_end'],
    'sort'             => $sort,
    'view_mode'        => 'both',
]);
?>
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Company-wide summary</h2>
            <div class="card-subtitle">
                Baseline: <strong><?= e($bsLabel) ?></strong> &nbsp;·&nbsp;
                Comparison: <strong><?= e($ccLabel) ?></strong> &nbsp;·&nbsp;
                <span title="This report queries CMS live when you run it.">generated <?= e(date('g:i A')) ?></span>
            </div>
        </div>
        <div class="flex gap-1 items-center">
            <span class="legend">
                <span><span class="legend-swatch" style="background:var(--good);"></span>improved</span>
                <span><span class="legend-swatch" style="background:var(--warn);"></span>flat</span>
                <span><span class="legend-swatch" style="background:var(--bad);"></span>worse</span>
            </span>
            <?php if (user_can('margin.export', 'export')): ?>
                <a href="<?= e($exportUrl) ?>" class="btn btn-sm" data-export="1">⬇ Export to Excel</a>
            <?php endif; ?>
        </div>
    </div>

    <table class="table table-summary tabular">
        <thead>
            <tr>
                <th>Metric</th>
                <th class="text-right">Baseline</th>
                <th class="text-right">Comparison</th>
                <th class="text-right">Difference</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Total Revenue</td>
                <td class="text-right"><?= fmt_money($summary['revenue']['baseline']) ?></td>
                <td class="text-right"><?= fmt_money($summary['revenue']['comparison']) ?></td>
                <td class="text-right <?= mw_class_for('revenue', $summary['revenue']['diff_pct'], $thresholds, $colorsOn, $summary['in_both_periods']) ?>">
                    <?= fmt_signed_money($summary['revenue']['diff_dollars']) ?>
                    <span class="text-dim">/</span>
                    <?= $summary['revenue']['diff_pct'] === null ? 'N/A' : fmt_signed_pct($summary['revenue']['diff_pct']) ?>
                </td>
            </tr>
            <tr>
                <td>Total Packed Cost</td>
                <td class="text-right"><?= fmt_money($summary['packed_cost']['baseline']) ?></td>
                <td class="text-right"><?= fmt_money($summary['packed_cost']['comparison']) ?></td>
                <td class="text-right <?= mw_class_for('packed_cost', $summary['packed_cost']['diff_pct'], $thresholds, $colorsOn, $summary['in_both_periods']) ?>">
                    <?= fmt_signed_money($summary['packed_cost']['diff_dollars']) ?>
                    <span class="text-dim">/</span>
                    <?= $summary['packed_cost']['diff_pct'] === null ? 'N/A' : fmt_signed_pct($summary['packed_cost']['diff_pct']) ?>
                </td>
            </tr>
            <tr>
                <td>$ Over Packed Cost</td>
                <td class="text-right"><?= fmt_money($summary['dollars_over_cost']['baseline']) ?></td>
                <td class="text-right"><?= fmt_money($summary['dollars_over_cost']['comparison']) ?></td>
                <td class="text-right <?= mw_class_for('dollars_over_cost', $summary['dollars_over_cost']['diff_pct'], $thresholds, $colorsOn, $summary['in_both_periods']) ?>">
                    <?= fmt_signed_money($summary['dollars_over_cost']['diff_dollars']) ?>
                    <span class="text-dim">/</span>
                    <?= $summary['dollars_over_cost']['diff_pct'] === null ? 'N/A' : fmt_signed_pct($summary['dollars_over_cost']['diff_pct']) ?>
                </td>
            </tr>
            <tr>
                <td>Packed Cost % of Revenue</td>
                <td class="text-right"><?= $summary['cost_pct_revenue']['baseline'] === null ? '—' : fmt_pct($summary['cost_pct_revenue']['baseline']) ?></td>
                <td class="text-right"><?= $summary['cost_pct_revenue']['comparison'] === null ? '—' : fmt_pct($summary['cost_pct_revenue']['comparison']) ?></td>
                <td class="text-right <?= mw_class_for('cost_pct_revenue', $summary['cost_pct_revenue']['diff_pp'], $thresholds, $colorsOn, $summary['in_both_periods']) ?>">
                    <?= $summary['cost_pct_revenue']['diff_pp'] === null ? 'N/A' : fmt_pp($summary['cost_pct_revenue']['diff_pp']) ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

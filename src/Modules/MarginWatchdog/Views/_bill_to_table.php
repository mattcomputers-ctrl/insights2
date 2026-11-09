<?php
/**
 * Bill To breakdown table — expandable rows with item drill-down.
 *
 * @var array  $billTos
 * @var array  $thresholds
 * @var array  $params
 * @var bool   $colorsOn
 * @var string $sort
 */
use PII\Modules\MarginWatchdog\Thresholds;

$exportUrl = '/margin-watchdog?' . http_build_query([
    'baseline_start'   => $params['baseline_start'],
    'baseline_end'     => $params['baseline_end'],
    'comparison_start' => $params['comparison_start'],
    'comparison_end'   => $params['comparison_end'],
    'sort'             => $sort,
]);

$sortLink = function (string $key) use ($params, $sort): string {
    $url = '/margin-watchdog?' . http_build_query([
        'baseline_start'   => $params['baseline_start'],
        'baseline_end'     => $params['baseline_end'],
        'comparison_start' => $params['comparison_start'],
        'comparison_end'   => $params['comparison_end'],
        'sort'             => $key,
    ]);
    return $url;
};

if (!function_exists('mw_class_for')) {
    function mw_class_for(string $metric, ?float $diff, array $thresholds, bool $colorsOn, bool $inBoth): string {
        if (!$colorsOn || !$inBoth) return '';
        $c = \PII\Modules\MarginWatchdog\Thresholds::classify($metric, $diff, $thresholds);
        return $c === 'neutral' ? '' : 'diff-' . $c;
    }
}
?>
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Bill To breakdown</h2>
            <div class="card-subtitle"><?= count($billTos) ?> customer<?= count($billTos) === 1 ? '' : 's' ?> with revenue in either period — click ▶ to expand items</div>
        </div>
        <div class="toolbar" style="margin-bottom:0;">
            <span class="text-muted" style="margin-right:0.5rem;">Sort by:</span>
            <a href="<?= e($sortLink('name')) ?>" class="btn btn-sm <?= $sort === 'name' ? 'btn-primary' : '' ?>">Name (A→Z)</a>
            <a href="<?= e($sortLink('baseline')) ?>" class="btn btn-sm <?= $sort === 'baseline' ? 'btn-primary' : '' ?>">Baseline revenue</a>
            <a href="<?= e($sortLink('comparison')) ?>" class="btn btn-sm <?= $sort === 'comparison' ? 'btn-primary' : '' ?>">Comparison revenue</a>
        </div>
    </div>

    <?php if (empty($billTos)): ?>
        <p class="muted-empty">No customers had revenue in either period.</p>
    <?php else: ?>
    <table class="table tabular">
        <thead>
            <tr>
                <th style="width:36px;"></th>
                <th>Bill To</th>
                <th class="text-right">Revenue<br><span class="text-dim" style="font-weight:400;font-size:0.7rem;">B → C → Δ%</span></th>
                <th class="text-right">$ Over Cost<br><span class="text-dim" style="font-weight:400;font-size:0.7rem;">B → C → Δ%</span></th>
                <th class="text-right">Cost % of Rev<br><span class="text-dim" style="font-weight:400;font-size:0.7rem;">B → C → Δ%</span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($billTos as $i => $bt):
            $r = $bt['raw']; $m = $bt['metrics'];
            $rowId = 'btchild_' . md5($r['bill_to'] . '|' . $i);
        ?>
            <tr>
                <td>
                    <button type="button" class="row-expand-toggle"
                            data-target="<?= e($rowId) ?>"
                            data-billto="<?= e($r['bill_to']) ?>"
                            data-baseline-start="<?= e($params['baseline_start']) ?>"
                            data-baseline-end="<?= e($params['baseline_end']) ?>"
                            data-comparison-start="<?= e($params['comparison_start']) ?>"
                            data-comparison-end="<?= e($params['comparison_end']) ?>">▶</button>
                </td>
                <td>
                    <strong><?= e($r['bill_to_name'] !== '' ? $r['bill_to_name'] : $r['bill_to']) ?></strong>
                    <?php if ($r['bill_to_name'] !== ''): ?><span class="tag" style="margin-left:0.4rem;"><?= e($r['bill_to']) ?></span><?php endif; ?>
                </td>
                <td class="text-right">
                    <?= fmt_money($m['revenue']['baseline']) ?> →
                    <?= fmt_money($m['revenue']['comparison']) ?>
                    <span class="<?= mw_class_for('revenue', $m['revenue']['diff_pct'], $thresholds, $colorsOn, $m['in_both_periods']) ?>" style="margin-left:0.5rem;">
                        <?= $m['revenue']['diff_pct'] === null ? 'N/A' : fmt_signed_pct($m['revenue']['diff_pct']) ?>
                    </span>
                </td>
                <td class="text-right">
                    <?= fmt_money($m['dollars_over_cost']['baseline']) ?> →
                    <?= fmt_money($m['dollars_over_cost']['comparison']) ?>
                    <span class="<?= mw_class_for('dollars_over_cost', $m['dollars_over_cost']['diff_pct'], $thresholds, $colorsOn, $m['in_both_periods']) ?>" style="margin-left:0.5rem;">
                        <?= $m['dollars_over_cost']['diff_pct'] === null ? 'N/A' : fmt_signed_pct($m['dollars_over_cost']['diff_pct']) ?>
                    </span>
                </td>
                <td class="text-right">
                    <?= $m['cost_pct_revenue']['baseline'] === null ? '—' : fmt_pct($m['cost_pct_revenue']['baseline']) ?> →
                    <?= $m['cost_pct_revenue']['comparison'] === null ? '—' : fmt_pct($m['cost_pct_revenue']['comparison']) ?>
                    <span class="<?= mw_class_for('cost_pct_revenue', $m['cost_pct_revenue']['diff_pp'], $thresholds, $colorsOn, $m['in_both_periods']) ?>" style="margin-left:0.5rem;">
                        <?= $m['cost_pct_revenue']['diff_pp'] === null ? 'N/A' : fmt_pp($m['cost_pct_revenue']['diff_pp']) ?>
                    </span>
                </td>
            </tr>
            <tr id="<?= e($rowId) ?>" class="row-children" style="display:none;" data-loaded="0">
                <td colspan="5">
                    <div class="children-toolbar" style="padding:0.75rem 1.5rem 0;">
                        <strong style="color:var(--text);">Items sold to <?= e($r['bill_to_name'] !== '' ? $r['bill_to_name'] : $r['bill_to']) ?></strong>
                        <span class="text-dim">·</span>
                        <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;font-weight:500;">
                            <input type="radio" name="vm_<?= e($rowId) ?>" value="both" class="item-view-toggle" checked>
                            Items sold in BOTH periods
                        </label>
                        <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;font-weight:500;">
                            <input type="radio" name="vm_<?= e($rowId) ?>" value="either" class="item-view-toggle">
                            Items sold in EITHER period
                        </label>
                    </div>
                    <div class="children-wrap">
                        <div class="children-loading"><span class="spinner"></span> Loading items…</div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

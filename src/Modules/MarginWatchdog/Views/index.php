<?php
/**
 * @var array $params       baseline + comparison range
 * @var string $sort
 * @var array $errors
 * @var array|null $summary metrics for top-level rollup, or null
 * @var array $billTos      list of ['raw'=>..., 'metrics'=>...]
 * @var array $thresholds
 * @var array $presets
 * @var bool  $colorsOn
 * @var bool  $hasCms
 */
$today = date('Y-m-d');

// Defaults if not provided (last 90 days vs 90 days prior)
$bsDefault = $params['baseline_start']   !== '' ? $params['baseline_start']   : date('Y-m-d', strtotime('-180 days'));
$beDefault = $params['baseline_end']     !== '' ? $params['baseline_end']     : date('Y-m-d', strtotime('-91 days'));
$csDefault = $params['comparison_start'] !== '' ? $params['comparison_start'] : date('Y-m-d', strtotime('-90 days'));
$ceDefault = $params['comparison_end']   !== '' ? $params['comparison_end']   : $today;
?>

<?php if (($btn = sync_now_button()) !== ''): ?>
    <div class="toolbar" style="margin-top:-0.5rem;">
        <span class="text-muted" style="font-size:0.85rem;">Reports query CMS live when you run them.</span>
        <?= $btn ?>
    </div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (!$hasCms): ?>
    <div class="alert alert-warning">
        <strong>CMS database not configured.</strong>
        Edit <code>config/config.php</code> and add the <code>cms_db</code> section with the SQL Server host, port, database name, and read-only credentials.
    </div>
<?php endif; ?>

<div class="card">
    <form method="GET" action="/margin-watchdog">
        <?php if (!empty($quickPresets)): ?>
        <div class="form-row" style="margin-bottom:0.5rem;">
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
                <span class="text-muted" style="font-size:0.8rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Quick:</span>
                <?php foreach ($quickPresets as $qp):
                    $tip = sprintf(
                        'Baseline: %s — %s · Comparison: %s — %s',
                        fmt_date($qp['bs']), fmt_date($qp['be']),
                        fmt_date($qp['cs']), fmt_date($qp['ce'])
                    );
                ?>
                    <button type="button" class="btn btn-sm btn-outline"
                            title="<?= e($tip) ?>"
                            onclick="
                                this.form.baseline_start.value   = '<?= e($qp['bs']) ?>';
                                this.form.baseline_end.value     = '<?= e($qp['be']) ?>';
                                this.form.comparison_start.value = '<?= e($qp['cs']) ?>';
                                this.form.comparison_end.value   = '<?= e($qp['ce']) ?>';
                            "><?= e($qp['name']) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label>Baseline start</label>
                <input type="date" name="baseline_start" value="<?= e($bsDefault) ?>" max="<?= e($today) ?>">
            </div>
            <div class="form-group">
                <label>Baseline end</label>
                <input type="date" name="baseline_end" value="<?= e($beDefault) ?>" max="<?= e($today) ?>">
            </div>
            <div class="form-group">
                <label>Comparison start</label>
                <input type="date" name="comparison_start" value="<?= e($csDefault) ?>" max="<?= e($today) ?>">
            </div>
            <div class="form-group">
                <label>Comparison end</label>
                <input type="date" name="comparison_end" value="<?= e($ceDefault) ?>" max="<?= e($today) ?>">
            </div>
            <div class="form-group" style="flex:0 0 auto;">
                <label>&nbsp;</label>
                <div style="display:flex;gap:0.5rem;">
                    <button type="submit" class="btn btn-primary">Run report</button>
                </div>
            </div>
        </div>

        <?php if (!empty($presets)): ?>
        <div class="form-row" style="margin-top:0.5rem;">
            <div class="form-group">
                <label>Saved presets</label>
                <select onchange="
                    if (this.value) {
                        const v = JSON.parse(this.value);
                        this.form.baseline_start.value   = v.bs;
                        this.form.baseline_end.value     = v.be;
                        this.form.comparison_start.value = v.cs;
                        this.form.comparison_end.value   = v.ce;
                    }
                ">
                    <option value="">— Load preset —</option>
                    <?php foreach ($presets as $p): ?>
                        <option value='<?= e(json_encode([
                            'bs' => $p['baseline_start'],   'be' => $p['baseline_end'],
                            'cs' => $p['comparison_start'], 'ce' => $p['comparison_end'],
                        ])) ?>'><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <a href="/presets" class="btn btn-sm btn-outline">Manage presets</a>
            </div>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($summary !== null):
    require __DIR__ . '/_summary_card.php';
    require __DIR__ . '/_bill_to_table.php';
elseif ($params['ready'] && $hasCms): ?>
    <div class="card"><p class="muted-empty">No shipments found in either date range.</p></div>
<?php endif; ?>

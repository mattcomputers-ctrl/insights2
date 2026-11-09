<?php
/**
 * @var \PII\Core\Module[] $modules
 * @var array|null $shipments    yesterdayShipments() result, or null
 * @var array|null $inventory    yesterdayInventory() result, or null
 * @var string|null $cmsError    set if CMS query failed or is unconfigured
 */
$user = $_SESSION['_user'] ?? null;
$shipDateLabel = $shipments
    ? fmt_date($shipments['date'], 'l, F j, Y')
    : fmt_date(date('Y-m-d', strtotime('-1 day')), 'l, F j, Y');
$invDateLabel = $inventory
    ? fmt_date($inventory['date'], 'l, F j, Y')
    : null;
?>

<?php $user = current_user(); ?>
<p class="text-muted" style="margin-top:-0.5rem;">
    Welcome, <?= e($user['display_name'] ?: $user['username']) ?>. Pick a tool to get started.
</p>

<?php if (empty($modules)): ?>
    <div class="empty-state">
        <span class="empty-icon">🔒</span>
        No tools are available to your account yet.<br>
        Ask an administrator to add you to a group.
    </div>
<?php else: ?>
    <div class="tile-grid">
        <?php foreach ($modules as $m): ?>
            <a href="<?= e($m->basePath()) ?>" class="tile">
                <span class="tile-icon"><?= $m->icon() !== '' ? $m->icon() : '▪' ?></span>
                <div class="tile-name"><?= e($m->name()) ?></div>
                <div class="tile-desc"><?= e($m->description()) ?></div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($cmsError): ?>
    <div class="alert alert-warning">
        <strong>CMS unavailable —</strong> dashboard metrics couldn't load. <span class="text-muted">(<?= e($cmsError) ?>)</span>
    </div>
<?php endif; ?>

<?php if (!empty($showMetrics) && !$cmsError && $inventory === null): ?>
    <div class="alert alert-info">
        <strong>Inventory snapshots not yet captured.</strong>
        Snapshots are generated nightly by <code>cron/snapshot-inventory.php</code>; the inventory total + GL-group breakdown will appear here after the first run.
        Admin can backfill now with <code>php cron/snapshot-inventory.php --backfill-days=30</code> (takes ~25 minutes; the CMS TVF is slow).
    </div>
<?php endif; ?>

<?php if ($shipments !== null || $inventory !== null): ?>
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Yesterday — <?= e($shipDateLabel) ?></h2>
            <div class="card-subtitle">Revenue and margin from yesterday's shipments<?= $inventory && $inventory['date'] !== ($shipments['date'] ?? '') ? ' · inventory snapshot from ' . e(fmt_date($inventory['date'], 'M j')) : '' ?></div>
        </div>
    </div>

    <div class="stat-grid">
        <?php if ($shipments !== null):
            // Variance display: positive = green, negative = warn (matches inventory total card).
            $varCol = static function (?float $v): string {
                if ($v === null) return 'var(--text-muted)';
                if ($v > 0) return 'var(--good)';
                if ($v < 0) return 'var(--warn)';
                return 'var(--text-muted)';
            };
        ?>
            <div class="stat-card">
                <div class="stat-label">Revenue</div>
                <div class="stat-value"><?= fmt_money($shipments['revenue']) ?></div>
                <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;">
                    <?php if ($shipments['revenue_avg_30d'] !== null): ?>
                        30-day avg: <?= fmt_money($shipments['revenue_avg_30d'], 0) ?>
                        <?php if ($shipments['revenue_variance_pct'] !== null): ?>
                            <span style="margin-left:0.3rem;color:<?= $varCol($shipments['revenue_variance_pct']) ?>;">
                                (<?= fmt_signed_pct($shipments['revenue_variance_pct'], 1) ?> vs avg)
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= fmt_number($shipments['lines']) ?> shipment line<?= $shipments['lines'] === 1 ? '' : 's' ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Packed Cost % of Sales</div>
                <div class="stat-value">
                    <?= $shipments['cost_pct'] === null ? '—' : fmt_pct($shipments['cost_pct'], 1) ?>
                </div>
                <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;">
                    Cost: <?= fmt_money($shipments['cost'], 0) ?>
                    <?php if ($shipments['cost_avg_30d'] !== null): ?>
                        · 30-day avg: <?= fmt_money($shipments['cost_avg_30d'], 0) ?>
                        <?php if ($shipments['cost_variance_pct'] !== null): ?>
                            <span style="margin-left:0.2rem;color:<?= $varCol($shipments['cost_variance_pct']) ?>;">
                                (<?= fmt_signed_pct($shipments['cost_variance_pct'], 1) ?>)
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($inventory !== null): ?>
            <div class="stat-card">
                <div class="stat-label">Inventory Total</div>
                <div class="stat-value"><?= fmt_money($inventory['total_value'], 0) ?></div>
                <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;">
                    <?php if ($inventory['total_avg_30d'] !== null): ?>
                        30-day avg: <?= fmt_money($inventory['total_avg_30d'], 0) ?>
                        <?php if ($inventory['total_variance_pct'] !== null): ?>
                            <span style="margin-left:0.3rem;color:<?= $inventory['total_variance_pct'] > 0 ? 'var(--good)' : ($inventory['total_variance_pct'] < 0 ? 'var(--warn)' : 'var(--text-muted)') ?>;">
                                (<?= fmt_signed_pct($inventory['total_variance_pct'], 1) ?> vs avg)
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        Actual-cost basis · 30-day avg not yet available
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($inventory !== null && !empty($inventory['by_gl_group'])): ?>
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Inventory by GL Group</h2>
            <div class="card-subtitle">As of <?= e($invDateLabel) ?> · actual-cost basis · 30-day average where available</div>
        </div>
    </div>

    <table class="table tabular">
        <thead>
            <tr>
                <th>GL Group</th>
                <th class="text-right">Quantity</th>
                <th class="text-right">Actual Value</th>
                <th class="text-right" style="width:140px;">% of Total</th>
                <th class="text-right">30-Day Avg</th>
                <th class="text-right">Variance %</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($inventory['by_gl_group'] as $g):
            $vp = $g['variance_pct'];
            // Variance is informational, not judgmental: a few percent
            // either way is normal; only flag larger swings as orange.
            $varCls = '';
            if ($vp !== null && abs($vp) >= 10) {
                $varCls = $vp > 0 ? 'text-good' : 'text-warn';
            }
        ?>
            <tr>
                <td><strong><?= e($g['gl_group']) ?></strong></td>
                <td class="text-right"><?= fmt_number($g['qty'], 0) ?></td>
                <td class="text-right"><?= fmt_money($g['value'], 0) ?></td>
                <td class="text-right">
                    <div style="display:flex;align-items:center;gap:0.5rem;justify-content:flex-end;">
                        <span style="display:inline-block;width:60px;height:6px;background:var(--border);border-radius:3px;overflow:hidden;">
                            <span style="display:block;height:100%;width:<?= number_format(min(100, $g['pct_of_total']), 2) ?>%;background:var(--primary);"></span>
                        </span>
                        <span style="min-width:48px;text-align:right;"><?= fmt_pct($g['pct_of_total'], 1) ?></span>
                    </div>
                </td>
                <td class="text-right"><?= $g['avg_30d'] === null ? '<span class="text-dim">—</span>' : fmt_money($g['avg_30d'], 0) ?></td>
                <td class="text-right <?= $varCls ?>">
                    <?= $vp === null ? '<span class="text-dim">—</span>' : fmt_signed_pct($vp, 1) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:600;background:var(--bg-elev2);">
                <td>Total</td>
                <td class="text-right"><?= fmt_number($inventory['total_qty'], 0) ?></td>
                <td class="text-right"><?= fmt_money($inventory['total_value'], 0) ?></td>
                <td class="text-right">100.0%</td>
                <td class="text-right"><?= $inventory['total_avg_30d'] === null ? '<span class="text-dim">—</span>' : fmt_money($inventory['total_avg_30d'], 0) ?></td>
                <td class="text-right"><?= $inventory['total_variance_pct'] === null ? '<span class="text-dim">—</span>' : fmt_signed_pct($inventory['total_variance_pct'], 1) ?></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>


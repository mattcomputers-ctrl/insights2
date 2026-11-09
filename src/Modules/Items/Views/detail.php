<?php
/**
 * Item 360 (live CMS): master data, aliases, recipes, QC specs, stock by
 * container/lot, recent sales and purchases, where-used.
 * @var string $code  @var ?array $head  @var array $aliases  @var array $recipes
 * @var array $specs  @var array $stock  @var ?array $totals
 * @var array $shipments  @var array $purchases  @var array $whereUsed
 * @var ?string $resolvedFrom  @var bool $showCost  @var ?string $error  @var ?string $asOf
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/items" class="btn btn-sm">← Items</a>
</div>

<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
    <?php return; ?>
<?php endif; ?>

<div class="card">
    <?php if ($resolvedFrom !== null): ?>
        <div class="alert alert-info"><?= e($resolvedFrom) ?> is an alias — showing its inventory item.</div>
    <?php endif; ?>
    <div class="toolbar">
        <div>
            <h2 class="card-title"><?= item_ctx((string) $head['ItemCode']) ?>
                <?php if ($head['MinimumStock'] !== null && abs((float) $head['MinimumStock'] - 99.0) < 1e-9): ?>
                    <span class="pill pill-danger">DISCONTINUED</span>
                <?php endif; ?>
            </h2>
            <div class="card-subtitle"><?= e((string) ($head['Description'] ?? '')) ?>
                <?= $head['AltDescription'] ? ' · ' . e((string) $head['AltDescription']) : '' ?></div>
        </div>
    </div>

    <div class="tile-grid" style="margin-bottom:0.5rem;">
        <div class="tile"><div class="tile-name"><?= e((string) ($head['Unit'] ?? '—')) ?></div><div class="tile-desc">Unit</div></div>
        <div class="tile"><div class="tile-name"><?= e((string) ($head['GLGroup'] ?? '—')) ?></div><div class="tile-desc">GL group</div></div>
        <div class="tile"><div class="tile-name"><?= $head['MinimumStock'] !== null ? fmt_number((float) $head['MinimumStock'], 1) : '—' ?></div><div class="tile-desc">Minimum stock</div></div>
        <div class="tile"><div class="tile-name"><?= $totals !== null && $totals['usable'] !== null ? fmt_number((float) $totals['usable'], 1) : '0' ?></div>
            <div class="tile-desc">Usable on hand<?= $totals !== null && (float) ($totals['hold'] ?? 0) > 0 ? ' (+' . fmt_number((float) $totals['hold'], 1) . ' on hold)' : '' ?></div></div>
        <?php if ($head['PreferredVendor']): ?>
            <div class="tile"><div class="tile-name" style="font-size:1rem;"><?= e((string) $head['PreferredVendor']) ?></div><div class="tile-desc">Preferred vendor</div></div>
        <?php endif; ?>
        <?php if ($showCost && $head['StandardCost'] !== null): ?>
            <div class="tile"><div class="tile-name"><?= fmt_money((float) $head['StandardCost'], 4) ?></div><div class="tile-desc">Standard cost</div></div>
        <?php endif; ?>
        <?php if ($head['SpecificGravity'] !== null): ?>
            <div class="tile"><div class="tile-name"><?= fmt_number((float) $head['SpecificGravity'], 3) ?></div><div class="tile-desc">Specific gravity</div></div>
        <?php endif; ?>
    </div>
    <?php if (!empty($head['Comment'])): ?>
        <div class="alert alert-info" style="white-space:pre-wrap;"><?= e((string) $head['Comment']) ?></div>
    <?php endif; ?>

    <?php if ($recipes !== []): ?>
        <h3 style="margin:0.75rem 0 0.3rem;">Recipes</h3>
        <div class="flex gap-1" style="flex-wrap:wrap;">
            <?php foreach ($recipes as $r): ?>
                <button type="button" class="recipe-open pill <?= (int) $r['IsActive'] === 1 ? 'pill-active' : ((int) $r['Inactive'] === 1 ? '' : 'pill-info') ?>"
                        style="cursor:pointer;border:none;" data-recipe="<?= (int) $r['Recipe'] ?>"
                        title="<?= (int) $r['IsActive'] === 1 ? 'Active costing recipe' : ((int) $r['Inactive'] === 1 ? 'Inactive' : 'Older version') ?><?= $r['DateCreated'] ? ', created ' . e($r['DateCreated']) : '' ?>">
                    <?= e((string) $r['RecipeNumber']) ?><?= (int) $r['IsActive'] === 1 ? ' ✔' : '' ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($aliases !== []): ?>
        <h3 style="margin:0.75rem 0 0.3rem;">Aliases (<?= count($aliases) ?>)</h3>
        <div style="font-size:0.9rem;columns:2;column-gap:2rem;">
            <?php foreach ($aliases as $a): ?>
                <div class="nowrap"><strong><?= e((string) $a['ItemCode']) ?></strong>
                    <?php if ($a['Description']): ?><span class="text-muted">— <?= e((string) $a['Description']) ?></span><?php endif; ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($specs !== []): ?>
<div class="card">
    <h2 class="card-title">QC specs</h2>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Test</th><th class="text-right">Min</th><th class="text-right">Target</th><th class="text-right">Max</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($specs as $s): ?>
                <tr>
                    <td><?= e((string) $s['TestName']) ?><?= $s['TestUnit'] ? ' <span class="text-muted">(' . e((string) $s['TestUnit']) . ')</span>' : '' ?></td>
                    <td class="text-right tabular"><?= $s['Min'] !== null ? e((string) $s['Min']) : '—' ?></td>
                    <td class="text-right tabular"><?= $s['Target'] !== null ? e((string) $s['Target']) : '—' ?></td>
                    <td class="text-right tabular"><?= $s['Max'] !== null ? e((string) $s['Max']) : '—' ?></td>
                    <td class="text-muted" style="font-size:0.85rem;">
                        <?= implode(', ', array_filter([
                            (int) ($s['OnReceipt'] ?? 0) === 1 ? 'receipt' : null,
                            (int) ($s['OnProduction'] ?? 0) === 1 ? 'production' : null,
                            (int) ($s['OnRetest'] ?? 0) === 1 ? 'retest' : null,
                        ])) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">Stock on hand by container</h2>
    <?php if ($stock === []): ?>
        <div class="empty-state"><span class="empty-icon">📭</span>Nothing on hand.</div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Container</th><th class="text-right">Qty</th><th>Status</th><th>Lot</th><th>Their lot</th><th>Received / made</th><th>Supplier</th></tr></thead>
            <tbody>
            <?php foreach ($stock as $s): ?>
                <tr>
                    <td class="nowrap"><strong><?= e((string) $s['LocationCode']) ?></strong>
                        <?php if ($s['LocCtx']): ?><span class="tag"><?= e((string) $s['LocCtx']) ?></span><?php endif; ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $s['Qty'], 2) ?></td>
                    <td class="text-muted"><?= e((string) ($s['Status'] ?? '')) ?></td>
                    <td><?= lot_ctx($s['SublotCode'] ?? '') ?></td>
                    <td class="text-muted"><?= e((string) ($s['SupLot'] ?? '')) ?></td>
                    <td><?= e(fmt_date($s['ReceivedDate'] ?: $s['ManfDate'])) ?></td>
                    <td class="text-muted"><?= e((string) ($s['Supplier'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title">Recent activity</h2>
    <div class="flex gap-2" style="flex-wrap:wrap;align-items:flex-start;">
        <div style="flex:1;min-width:320px;">
            <h3 style="margin:0 0 0.3rem;">Last shipments</h3>
            <?php if ($shipments === []): ?><p class="text-muted">None.</p><?php else: ?>
            <table class="table">
                <thead><tr><th>Date</th><th>Order</th><th>Customer</th><th class="text-right">Qty</th><th>Invoice</th></tr></thead>
                <tbody>
                <?php foreach ($shipments as $s): ?>
                    <tr>
                        <td><?= e(fmt_date($s['DateShipped'])) ?></td>
                        <td><?php if (user_can('sales.view')): ?><a href="/sales-orders/<?= (int) $s['Ordr'] ?>"><?= (int) $s['Ordr'] ?></a><?php else: ?><?= (int) $s['Ordr'] ?><?php endif; ?></td>
                        <td><?= e((string) ($s['ShipToName'] ?? '')) ?></td>
                        <td class="text-right tabular"><?= fmt_number((float) $s['QtyShipped'], 1) ?></td>
                        <td class="text-muted"><?= e((string) ($s['TransDocument'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div style="flex:1;min-width:320px;">
            <h3 style="margin:0 0 0.3rem;">Last purchases</h3>
            <?php if ($purchases === []): ?><p class="text-muted">None.</p><?php else: ?>
            <table class="table">
                <thead><tr><th>Date</th><th>PO</th><th>Vendor</th><th>Their code</th><th class="text-right">Ordered</th></tr></thead>
                <tbody>
                <?php foreach ($purchases as $p): ?>
                    <tr>
                        <td><?= e(fmt_date($p['DateOrdered'])) ?></td>
                        <td><?php if (user_can('purchasing.view')): ?><a href="/purchase-orders/<?= (int) $p['Ordr'] ?>"><?= (int) $p['Ordr'] ?></a><?php else: ?><?= (int) $p['Ordr'] ?><?php endif; ?></td>
                        <td><?= e((string) ($p['VendorName'] ?? '')) ?></td>
                        <td class="text-muted"><?= e((string) ($p['TheirCode'] ?? '')) ?></td>
                        <td class="text-right tabular"><?= fmt_number((float) $p['QtyReqd'], 1) ?> <?= e((string) ($p['Unit'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($whereUsed !== []): ?>
<div class="card">
    <h2 class="card-title">Used in (active recipes)</h2>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Item</th><th>Description</th><th class="text-right">% of formula</th></tr></thead>
            <tbody>
            <?php foreach ($whereUsed as $w): ?>
                <tr>
                    <td class="nowrap"><a href="/items/view?code=<?= urlencode((string) $w['ItemCode']) ?>"><?= item_ctx((string) $w['ItemCode']) ?></a></td>
                    <td><?= e((string) ($w['Description'] ?? '')) ?></td>
                    <td class="text-right tabular"><?= rtrim(rtrim(number_format((float) $w['QtyReqd'] * 100, 3, '.', ','), '0'), '.') ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

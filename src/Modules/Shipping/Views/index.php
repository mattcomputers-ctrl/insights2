<?php
/**
 * Shipping browser (live CMS): waybills newest first.
 * @var string $q  @var array $waybills  @var bool $truncated
 * @var ?string $error  @var ?string $asOf
 */
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Shipping</h2>
            <div class="card-subtitle">Every waybill, live from CMS — when it shipped, how, and what was in each box.</div>
        </div>
        <form method="GET" action="/shipping" class="flex gap-1">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Waybill #, order #, customer, tracking #, item…" style="min-width:280px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($waybills === []): ?>
        <div class="empty-state"><span class="empty-icon">🚚</span><?= $q !== '' ? 'No shipments match your search.' : 'No shipments yet.' ?></div>
    <?php else: ?>
        <?php if ($truncated): ?>
            <div class="alert alert-info">Showing the latest <?= count($waybills) ?> waybills — narrow the search for older ones.</div>
        <?php endif; ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Waybill</th><th>Shipped</th><th>Ship via</th><th>Customer</th>
                        <th>Orders</th><th class="text-right">Assemblies</th><th class="text-right">Weight</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($waybills as $w): ?>
                    <tr>
                        <td><a href="/shipping/<?= (int) $w['Waybill'] ?>"><strong><?= (int) $w['Waybill'] ?></strong></a></td>
                        <td class="nowrap"><?= e(fmt_date($w['DateShipped'], 'M j, Y g:i A')) ?></td>
                        <td><?= e((string) ($w['ShipVia'] ?? '')) ?></td>
                        <td>
                            <?php $cs = $w['customers'] ?? []; ?>
                            <?= e(implode(', ', array_slice($cs, 0, 2))) ?><?= count($cs) > 2 ? ' +' . (count($cs) - 2) : '' ?>
                        </td>
                        <td class="text-muted">
                            <?php foreach (array_slice($w['orders'] ?? [], 0, 4) as $o): ?>
                                <?php if (user_can('sales.view')): ?><a href="/sales-orders/<?= (int) $o ?>"><?= (int) $o ?></a><?php else: ?><?= (int) $o ?><?php endif; ?>
                            <?php endforeach; ?>
                            <?= count($w['orders'] ?? []) > 4 ? '…' : '' ?>
                        </td>
                        <td class="text-right tabular"><?= $w['Assemblies'] !== null && (int) $w['Assemblies'] > 0 ? (int) $w['Assemblies'] : '—' ?></td>
                        <td class="text-right tabular"><?= $w['WeightLb'] !== null ? fmt_number((float) $w['WeightLb'], 1) . ' lb' : '—' ?></td>
                        <td><?= $w['Status'] === 'CMP' ? '<span class="pill pill-info">Shipped</span>' : '<span class="pill pill-active">' . e((string) $w['Status']) . '</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

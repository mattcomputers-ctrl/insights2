<?php
/**
 * Sales order browser (live CMS).
 * @var string $q  @var string $tab  @var string $sort  @var array $orders
 * @var bool $truncated  @var ?string $error  @var ?string $asOf
 * @var array<string,bool> $can
 */
$pill = fn(string $s): string => match ($s) {
    'RTS' => '<span class="pill pill-active">Open (RTS)</span>',
    'CSH' => '<span class="pill pill-warn">Credit hold (CSH)</span>',
    'CMP' => '<span class="pill pill-info">Shipped (CMP)</span>',
    'CLS' => '<span class="pill">Closed (CLS)</span>',
    default => '<span class="pill">' . e($s) . '</span>',
};
$tabs = ['open' => 'Open', 'completed' => 'Completed', 'all' => 'All'];
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Sales Orders</h2>
            <div class="card-subtitle">Live from CMS — headers, lines, shipments and invoice numbers.</div>
        </div>
        <div class="flex gap-1">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="/sales-orders?tab=<?= e($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                   class="btn btn-sm <?= $tab === $key ? 'btn-primary' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <?php if (!empty($can['sales.create_order'])): ?>
                <a href="/sales-orders/new?clear=1" class="btn btn-sm btn-primary">New order…</a>
            <?php endif; ?>
            <?php if (\PII\Modules\SalesOrders\CustomersController::canAny()): ?>
                <a href="/sales-orders/customers" class="btn btn-sm">Customers…</a>
            <?php endif; ?>
        </div>
        <form method="GET" action="/sales-orders" class="flex gap-1 items-center">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <?php if ($tab !== 'completed'): ?>
                <select name="sort" onchange="this.form.submit()" title="Sort order">
                    <option value="required" <?= $sort === 'required' ? 'selected' : '' ?>>Earliest required first</option>
                    <option value="ordered" <?= $sort === 'ordered' ? 'selected' : '' ?>>Oldest ordered first</option>
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                </select>
            <?php endif; ?>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Order #, customer, PO #, item…" style="min-width:240px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($orders === []): ?>
        <div class="empty-state"><span class="empty-icon">📭</span><?= $q !== '' ? 'No orders match your search.' : 'No orders in this tab.' ?></div>
    <?php else: ?>
        <?php if ($truncated): ?>
            <div class="alert alert-info">Showing the latest <?= count($orders) ?> orders — narrow the search for older ones.</div>
        <?php endif; ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Order #</th><th>Status</th><th>Customer</th><th>Ship to</th><th>PO #</th>
                        <th>Ship via</th><th>Ordered</th><th>Required</th><th>Shipped</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><a href="/sales-orders/<?= (int) $o['Ordr'] ?>"><strong><?= (int) $o['Ordr'] ?></strong></a></td>
                        <td class="nowrap"><?= $pill((string) $o['Status']) ?><?php if ((int) ($o['IsBackorder'] ?? 0) === 1): ?> <span class="pill pill-warn" title="Open with some material already shipped">Backorder</span><?php endif; ?></td>
                        <td><?= e((string) ($o['BillToName'] ?: $o['BillTo'])) ?></td>
                        <td class="text-muted"><?= e((string) ($o['ShipToName'] ?? '')) ?></td>
                        <td class="text-muted"><?= e((string) ($o['PoNumber'] ?? '')) ?></td>
                        <td><?= e((string) ($o['ShipVia'] ?? '')) ?></td>
                        <td><?= e(fmt_date($o['DateOrdered'])) ?></td>
                        <td><?= e(fmt_date($o['DateRequired'])) ?></td>
                        <td><?= $o['LastShipped'] ?? null ? e(fmt_date($o['LastShipped'])) : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

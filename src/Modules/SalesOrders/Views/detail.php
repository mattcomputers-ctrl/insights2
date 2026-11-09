<?php
/**
 * One sales order: header, lines, shipments/invoices (live CMS).
 * @var int $ordr  @var ?array $head  @var array $lines  @var array $shipments
 * @var ?string $error  @var ?string $asOf
 * @var array<string,bool> $can  @var string $mode
 */
$status  = $head !== null ? trim((string) $head['Status']) : '';
$hasOpen = false;
foreach ($lines as $l) {
    $hasOpen = $hasOpen || (int) ($l['IsOpen'] ?? 0) === 1;
}
$post = static function (string $path, string $label, string $class = '') use ($ordr): string {
    return '<form method="POST" action="/sales-orders/' . (int) $ordr . '/' . $path . '" style="display:inline;">'
        . csrf_field() . '<button type="submit" class="btn btn-sm ' . $class . '">' . e($label) . '</button></form>';
};
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/sales-orders" class="btn btn-sm">← Sales Orders</a>
    <?php if ($head !== null && in_array($head['Status'], ['RTS', 'CSH'], true) && user_can('orders.view')): ?>
        <a href="/orders/list?tab=all&q=<?= (int) $ordr ?>" class="btn btn-sm">Coverage in Order Readiness</a>
    <?php endif; ?>
    <?php if ($head !== null && $error === null): ?>
        <?php if ($can['sales.edit_order'] && in_array($status, ['RTS', 'CSH', 'QUO'], true)): ?>
            <a href="/sales-orders/<?= (int) $ordr ?>/edit" class="btn btn-sm">Change order…</a>
        <?php endif; ?>
        <?php if ($can['sales.release_order'] && $status === 'CSH'): ?>
            <?= $post('release', 'Release from credit hold…') ?>
        <?php endif; ?>
        <?php if ($can['sales.complete_sale'] && $status === 'RTS' && $hasOpen): ?>
            <?= $post('complete-sale', 'Complete sale (ship and invoice)…') ?>
        <?php endif; ?>
        <?php if ($can['sales.complete_order'] && in_array($status, ['RTS', 'CSH'], true)): ?>
            <?= $post('complete', 'Complete order…') ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($mode !== 'live' && in_array(true, $can, true)): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
    <?php return; ?>
<?php endif; ?>

<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Sales order <?= (int) $ordr ?>
                <?php if ($head['Status'] === 'RTS'): ?><span class="pill pill-active">Open (RTS)</span>
                <?php elseif ($head['Status'] === 'CSH'): ?><span class="pill pill-warn">Credit hold (CSH)</span>
                <?php elseif ($head['Status'] === 'CMP'): ?><span class="pill pill-info">Shipped (CMP)</span>
                <?php else: ?><span class="pill"><?= e((string) $head['Status']) ?></span><?php endif; ?>
                <?php if ((int) ($head['IsBackorder'] ?? 0) === 1): ?><span class="pill pill-warn" title="Open with some material already shipped">Backorder</span><?php endif; ?>
            </h2>
            <div class="card-subtitle">
                <strong><?= e((string) ($head['BillToName'] ?: $head['BillTo'])) ?></strong>
                <?php if ($head['ShipToName']): ?> · ships to <?= e((string) $head['ShipToName']) ?><?php endif; ?>
                <?php if (!empty($head['ShipVia'])): ?> · via <strong><?= e((string) $head['ShipVia']) ?></strong><?php endif; ?>
                <?php if ($head['PoNumber']): ?> · PO <?= e((string) $head['PoNumber']) ?><?php endif; ?>
                <?php if ($head['SalesmanName']): ?> · salesman <?= e((string) $head['SalesmanName']) ?><?php endif; ?>
                <br>
                Ordered <?= e(fmt_date($head['DateOrdered'])) ?> · required <?= e(fmt_date($head['DateRequired'])) ?>
                <?php if ($head['DontShipBefore']): ?> · don't ship before <?= e(fmt_date($head['DontShipBefore'])) ?><?php endif; ?>
                <?php if ($head['PlacedBy']): ?> · placed by <?= e((string) $head['PlacedBy']) ?><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if (!empty($head['Comment'])): ?>
        <div class="alert alert-info" style="white-space:pre-wrap;"><?= e((string) $head['Comment']) ?></div>
    <?php endif; ?>

    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Item (as ordered)</th><th>Inventory item</th><th>Description</th>
                    <th class="text-right">Ordered</th><th class="text-right">Shipped</th>
                    <th class="text-right">Price</th><th class="text-right">Amount</th><th>Line</th>
                </tr>
            </thead>
            <tbody>
            <?php $total = 0.0; foreach ($lines as $l):
                $amount = (float) $l['QtyReqd'] * (float) ($l['Price'] ?? 0);
                $total += $amount;
            ?>
                <tr>
                    <td class="nowrap"><?= item_ctx((string) $l['ItemCode'], (string) ($l['AliasCode'] ?: $l['ItemCode'])) ?></td>
                    <td class="nowrap text-muted"><?= item_ctx((string) ($l['ItemCode'] ?? ''), null, false) ?></td>
                    <td><?= e((string) ($l['AliasDescription'] ?: $l['Description'] ?? '')) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $l['QtyReqd'], 1) ?> <?= e((string) $l['Unit']) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) ($l['QtyUsed'] ?? 0), 1) ?></td>
                    <td class="text-right tabular"><?= $l['Price'] !== null ? fmt_money((float) $l['Price'], 4) : '—' ?></td>
                    <td class="text-right tabular"><?= fmt_money($amount) ?></td>
                    <td><?= ((int) ($l['IsOpen'] ?? 0)) === 1 ? '<span class="pill pill-active">open</span>' : '<span class="pill">done</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="6" class="text-right text-muted">order total</td>
                    <td class="text-right tabular"><strong><?= fmt_money($total) ?></strong></td><td></td></tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="card">
    <h2 class="card-title">Shipments &amp; invoices</h2>
    <?php if ($shipments === []): ?>
        <div class="empty-state"><span class="empty-icon">🚚</span>Nothing shipped on this order yet.</div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr><th>Shipped</th><th>Invoice #</th><th>Item</th><th>As ordered</th>
                        <th class="text-right">Qty shipped</th><th>Waybill</th><th>Shipment</th></tr>
                </thead>
                <tbody>
                <?php foreach ($shipments as $s): ?>
                    <tr>
                        <td><?= e(fmt_date($s['DateShipped'])) ?></td>
                        <td><strong><?= e((string) ($s['TransDocument'] ?? '')) ?></strong></td>
                        <td class="nowrap"><?= item_ctx((string) ($s['ItemCode'] ?? '')) ?></td>
                        <td class="text-muted"><?= e((string) ($s['ItemName'] ?? '')) ?></td>
                        <td class="text-right tabular"><?= fmt_number((float) $s['QtyShipped'], 1) ?></td>
                        <td class="text-muted"><?= e((string) ($s['WayBill'] ?? '')) ?></td>
                        <td class="text-muted"><?= e((string) ($s['ChangeSet'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

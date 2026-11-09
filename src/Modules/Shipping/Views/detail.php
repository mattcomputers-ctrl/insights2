<?php
/**
 * One waybill: how/when it shipped, the assemblies (tracking, weight,
 * contents) and the orders/invoices on it. Live CMS.
 * @var int $waybill  @var ?array $head  @var array $assemblies
 * @var array $shipments  @var ?string $error  @var ?string $asOf
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/shipping" class="btn btn-sm">← Shipping</a>
</div>

<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
    <?php return; ?>
<?php endif; ?>

<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Waybill <?= (int) $waybill ?>
                <?= $head['Status'] === 'CMP' ? '<span class="pill pill-info">Shipped</span>' : '<span class="pill pill-active">' . e((string) $head['Status']) . '</span>' ?>
            </h2>
            <div class="card-subtitle">
                <?php if ($head['DateShipped']): ?>Shipped <?= e(fmt_date($head['DateShipped'], 'M j, Y g:i A')) ?><?php endif; ?>
                <?php if ($head['ShipVia']): ?> · via <strong><?= e((string) $head['ShipVia']) ?></strong><?php endif; ?>
                <?php if ($head['FreightService']): ?> · <?= e((string) $head['FreightService']) ?><?php endif; ?>
                <?php if ($head['PlacedBy']): ?> · by <?= e((string) $head['PlacedBy']) ?><?php endif; ?>
                <br>
                <?php if ($head['TrailerNumber']): ?>Trailer <?= e((string) $head['TrailerNumber']) ?> · <?php endif; ?>
                <?php if ($head['SealNumbers']): ?>Seals <?= e((string) $head['SealNumbers']) ?> · <?php endif; ?>
                <?php if ($head['FreightAccount']): ?>Freight acct <?= e((string) $head['FreightAccount']) ?> · <?php endif; ?>
                <?php if ($head['DeliveryConfirmation']): ?>Delivery conf. <?= e((string) $head['DeliveryConfirmation']) ?><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if (!empty($head['Comment'])): ?>
        <div class="alert alert-info" style="white-space:pre-wrap;"><?= e((string) $head['Comment']) ?></div>
    <?php endif; ?>

    <h3 style="margin:0.5rem 0 0.4rem;">Assemblies</h3>
    <?php if ($assemblies === []): ?>
        <div class="empty-state"><span class="empty-icon">📦</span>No assemblies recorded on this waybill.</div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr><th>Assembly</th><th>Tracking #</th><th class="text-right">Weight</th>
                    <th class="text-right">Freight</th><th>Contents</th></tr>
            </thead>
            <tbody>
            <?php foreach ($assemblies as $a): ?>
                <tr>
                    <td class="nowrap"><strong><?= e($a['code']) ?></strong></td>
                    <td class="tabular"><?= $a['tracking'] !== null && $a['tracking'] !== '' ? e($a['tracking']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-right tabular"><?= $a['weight'] !== null ? fmt_number($a['weight'], 1) . ' lb' : '—' ?></td>
                    <td class="text-right tabular"><?= $a['cost'] !== null && $a['cost'] > 0 ? fmt_money($a['cost']) : '<span class="text-muted">—</span>' ?></td>
                    <td style="font-size:0.85rem;">
                        <?php if ($a['contents'] === []): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                            <?php foreach ($a['contents'] as $cline): ?>
                                <div class="nowrap">
                                    <?= item_ctx((string) ($cline['ItemCode'] ?? ''), (string) ($cline['AliasCode'] ?: $cline['ItemCode'] ?? '')) ?>:
                                    <?= fmt_number((float) $cline['Qty'], 1) ?>
                                    <?php if ($cline['Ordr'] !== null): ?>
                                        <span class="text-muted">· order
                                        <?php if (user_can('sales.view')): ?><a href="/sales-orders/<?= (int) $cline['Ordr'] ?>"><?= (int) $cline['Ordr'] ?></a><?php else: ?><?= (int) $cline['Ordr'] ?><?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title">Orders &amp; invoices on this waybill</h2>
    <?php if ($shipments === []): ?>
        <div class="empty-state"><span class="empty-icon">🧾</span>No shipment lines found.</div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr><th>Order</th><th>Customer</th><th>PO #</th><th>Invoice</th>
                    <th>Item (as ordered)</th><th class="text-right">Qty shipped</th></tr>
            </thead>
            <tbody>
            <?php foreach ($shipments as $s): ?>
                <tr>
                    <td>
                        <?php if (user_can('sales.view')): ?><a href="/sales-orders/<?= (int) $s['Ordr'] ?>"><strong><?= (int) $s['Ordr'] ?></strong></a><?php else: ?><strong><?= (int) $s['Ordr'] ?></strong><?php endif; ?>
                    </td>
                    <td><?= e((string) ($s['ShipToName'] ?? '')) ?></td>
                    <td class="text-muted"><?= e((string) ($s['PoNumber'] ?? '')) ?></td>
                    <td><?= e((string) ($s['TransDocument'] ?? '')) ?></td>
                    <td class="nowrap"><?= item_ctx((string) ($s['ItemCode'] ?? ''), (string) ($s['ItemName'] ?: $s['ItemCode'] ?? '')) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $s['QtyShipped'], 1) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
/**
 * One waybill: how/when it shipped, the assemblies (tracking, weight,
 * contents) and the orders/invoices on it. Live CMS.
 * @var int $waybill  @var ?array $head  @var array $assemblies
 * @var array $shipments  @var ?string $error  @var ?string $asOf
 * @var ?array $state  @var array<string,bool> $can  @var string $mode
 */
$status     = $state['Status'] ?? (string) ($head['Status'] ?? '');
$openSteps  = $state !== null ? count(array_filter($state['todo'], static fn (array $r): bool => !$r['done'])) : 0;
$unlabelled = $state !== null && $state['Address'] !== null
    ? count(array_filter($state['containers'], static fn (array $c): bool => $c['labelAddress'] !== $state['Address'])) : 0;
$docs = [];
foreach ($state['orders'] ?? [] as $o) {
    $docs[$o['ordr']] = $o;
}
$canFinish = ($can['ship.resume_waybill'] ?? false) && !in_array($status, ['CMP', 'REJ'], true) && $openSteps > 0;
$canReject = ($can['ship.reject_waybill'] ?? false) && $status === 'CMP';
$canLabel  = ($can['ship.label_address'] ?? false) && $status === 'CMP' && $unlabelled > 0;
$pill = match ($status) {
    'CMP'   => '<span class="pill pill-info">Shipped</span>',
    'REJ'   => '<span class="pill pill-danger">Rejected</span>',
    default => '<span class="pill pill-active">' . e($status !== '' ? $status : 'Open') . '</span>',
};
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/shipping" class="btn btn-sm">← Shipping</a>
</div>

<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
    <?php return; ?>
<?php endif; ?>

<?php if (($canFinish || $canReject || $canLabel) && $mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>
<?php if ($openSteps > 0 && !in_array($status, ['CMP', 'REJ'], true)): ?>
    <div class="alert alert-danger">This waybill was started and did not finish: <?= (int) $openSteps ?> step<?= $openSteps === 1 ? '' : 's' ?> left.</div>
<?php endif; ?>

<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Waybill <?= (int) $waybill ?>
                <?= $pill ?>
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
        <?php if ($canFinish || $canReject || $canLabel): ?>
        <div class="flex gap-1 no-print">
            <?php if ($canFinish): ?>
                <form method="POST" action="/shipping/<?= (int) $waybill ?>/finish"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-primary">Finish this waybill</button></form>
            <?php endif; ?>
            <?php if ($canLabel): ?>
                <form method="POST" action="/shipping/<?= (int) $waybill ?>/labels"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm" title="Tell CMS the shipping labels for this waybill were printed">Mark labels printed</button></form>
            <?php endif; ?>
            <?php if ($canReject): ?>
                <form method="POST" action="/shipping/<?= (int) $waybill ?>/reject"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger">Reject waybill</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
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
                    <th>Item (as ordered)</th><th class="text-right">Qty shipped</th><th class="no-print"></th></tr>
            </thead>
            <tbody>
            <?php $seen = []; foreach ($shipments as $s):
                $doc = $docs[(int) $s['Ordr']] ?? null;
                $first = !isset($seen[(int) $s['Ordr']]);
                $seen[(int) $s['Ordr']] = true; ?>
                <tr>
                    <td>
                        <?php if (user_can('sales.view')): ?><a href="/sales-orders/<?= (int) $s['Ordr'] ?>"><strong><?= (int) $s['Ordr'] ?></strong></a><?php else: ?><strong><?= (int) $s['Ordr'] ?></strong><?php endif; ?>
                    </td>
                    <td><?= e((string) ($s['ShipToName'] ?? '')) ?></td>
                    <td class="text-muted"><?= e((string) ($s['PoNumber'] ?? '')) ?></td>
                    <td><?= e((string) ($s['TransDocument'] ?? '')) ?></td>
                    <td class="nowrap"><?= item_ctx((string) ($s['ItemCode'] ?? ''), (string) ($s['ItemName'] ?: $s['ItemCode'] ?? '')) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $s['QtyShipped'], 1) ?></td>
                    <td class="nowrap no-print">
                        <?php if ($first && $doc !== null): ?>
                            <a href="/shipping/packing-slip/<?= (int) $doc['changeSet'] ?>" class="btn btn-sm">Packing slip</a>
                            <?php if ($doc['trans'] !== null && user_can('shipping.invoice')): ?>
                                <a href="/shipping/invoice/<?= (int) $doc['trans'] ?>" class="btn btn-sm">Invoice</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

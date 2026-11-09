<?php
/**
 * One purchase order: header, lines, receipts (live CMS).
 * @var int $ordr  @var ?array $head  @var array $lines  @var array $receipts
 * @var ?string $error  @var ?string $asOf
 * @var array<string,bool> $can  @var string $mode
 */
$status  = $head !== null ? trim((string) ($head['Status'] ?? '')) : null;
$isOpen  = $status === '';
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/purchase-orders" class="btn btn-sm">← Purchase Orders</a>
    <?php if ($status !== null): ?>
    <div class="flex gap-1 items-center">
        <?php if ($can['po.edit_order'] && $isOpen): ?>
            <a href="/purchase-orders/<?= (int) $ordr ?>/edit" class="btn btn-sm">Change PO…</a>
        <?php endif; ?>
        <?php if (($can['po.receive'] && $isOpen)
            || ($can['po.reverse_receipt'] && in_array($status, ['', 'CMP'], true) && $receipts !== [])): ?>
            <a href="/purchase-orders/<?= (int) $ordr ?>/receive" class="btn btn-sm <?= $isOpen ? 'btn-primary' : '' ?>"><?= $isOpen ? 'Receiving…' : 'Receipts…' ?></a>
        <?php endif; ?>
        <?php if ($can['po.complete_order'] && $isOpen): ?>
            <form method="POST" action="/purchase-orders/<?= (int) $ordr ?>/complete" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm">Complete PO…</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($mode !== 'live' && in_array(true, $can, true)): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
    <?php return; ?>
<?php endif; ?>

<?php
$byLine = [];
foreach ($receipts as $r) {
    $byLine[(int) $r['OrdDetail']][] = $r;
}
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Purchase order <?= (int) $ordr ?>
                <?php if ($head['Status'] === 'CMP'): ?><span class="pill pill-info">Received (CMP)</span>
                <?php elseif ($head['Status'] === 'CLS'): ?><span class="pill">Closed (CLS)</span>
                <?php else: ?><span class="pill pill-active">Open</span><?php endif; ?>
            </h2>
            <div class="card-subtitle">
                <strong><?= e((string) ($head['VendorName'] ?: $head['Vendor'] ?? '')) ?></strong>
                · ordered <?= e(fmt_date($head['DateOrdered'])) ?>
                <?php if ($head['DateRequired']): ?> · required <?= e(fmt_date($head['DateRequired'])) ?><?php endif; ?>
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
                    <th>Item</th><th>Description</th><th>Vendor's code</th>
                    <th class="text-right">Ordered</th><th class="text-right">Received</th>
                    <th>Promised</th><th class="text-right">Price</th><th class="text-right">Value</th>
                    <th>Line</th><th>Receipts</th>
                </tr>
            </thead>
            <tbody>
            <?php $total = 0.0; foreach ($lines as $l):
                $total += (float) ($l['Value'] ?? 0);
            ?>
                <tr>
                    <td class="nowrap"><?= item_ctx((string) $l['ItemCode']) ?></td>
                    <td><?= e((string) ($l['ItemDescription'] ?? '')) ?></td>
                    <td class="text-muted"><?= e((string) ($l['TheirCode'] ?? '')) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $l['QtyReqd'], 1) ?> <?= e((string) $l['Unit']) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) ($l['QtyReceived'] ?? 0), 1) ?></td>
                    <td><?= e(fmt_date($l['DatePromised'])) ?></td>
                    <td class="text-right tabular"><?= $l['Price'] !== null ? fmt_money((float) $l['Price'], 4) : '—' ?></td>
                    <td class="text-right tabular"><?= $l['Value'] !== null ? fmt_money((float) $l['Value']) : '—' ?></td>
                    <td><?= ((int) ($l['IsOpen'] ?? 0)) === 1 ? '<span class="pill pill-active">open</span>' : '<span class="pill">done</span>' ?></td>
                    <td style="font-size:0.85rem;" class="text-muted">
                        <?php foreach ($byLine[(int) $l['OrdDetail']] ?? [] as $r): ?>
                            <div class="nowrap">
                                <?= e(fmt_date($r['ReceivedAt'], 'M j, Y')) ?>:
                                <?= fmt_number((float) $r['PSQty'], 1) ?> <?= e((string) ($r['PSUnit'] ?? '')) ?>
                                <?php if ($r['SublotCode']): ?>· lot <?= lot_ctx($r['SublotCode']) ?><?php endif; ?>
                                <?php if ($r['SupLot']): ?>· their lot <?= e((string) $r['SupLot']) ?><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="7" class="text-right text-muted">PO total</td>
                    <td class="text-right tabular"><strong><?= fmt_money($total) ?></strong></td><td colspan="2"></td></tr>
            </tfoot>
        </table>
    </div>
</div>

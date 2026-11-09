<?php
/**
 * Receiving against one purchase order: receive an open line, or reverse
 * a receipt. Each button leads to the check-then-confirm step.
 * @var int $ordr  @var ?array $order  @var array<string,bool> $can
 * @var ?string $error  @var string $mode
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/purchase-orders/<?= (int) $ordr ?>" class="btn btn-sm">← PO <?= (int) $ordr ?></a>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
<?php endif; ?>
<?php if ($order === null) { return; } ?>

<?php
$open     = $order['Status'] === '';
$byLine   = [];
foreach ($order['lines'] as $l) {
    $byLine[$l['ordDetail']] = $l;
}
$openLines = array_values(array_filter($order['lines'], static fn ($l) => $l['isOpen']));
$receipts  = array_values(array_filter($order['receipts'], static fn ($r) => (float) $r['qty'] !== 0.0));
?>
<div class="card">
    <h2 class="card-title">Receive purchase order <?= (int) $ordr ?></h2>
    <div class="card-subtitle">
        <strong><?= e($order['SupplierName']) ?></strong>. One receipt per line; stock goes to the receiving location.
        Quantities are in the item's stock unit.
    </div>
    <?php if (!$open): ?>
        <div class="alert alert-info">This PO is completed, so nothing more can be received against it.</div>
    <?php elseif (!$can['po.receive']): ?>
        <div class="alert alert-info">You do not have permission to receive.</div>
    <?php elseif ($openLines === []): ?>
        <div class="empty-state">Every line on this PO is closed.</div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr><th>Item</th><th>Description</th><th class="text-right">Ordered</th><th class="text-right">Received</th>
                        <th>Receive now</th><th>Packing slip number</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($openLines as $l):
                    $left   = max(0.0, (float) $l['qty'] - (float) $l['received']);
                    $formId = 'po-receive-' . (int) $l['ordDetail'];
                ?>
                    <tr>
                        <td class="nowrap"><?= item_ctx($l['code']) ?>
                            <?php if ($l['supplierCode'] !== ''): ?><div class="tag" title="The supplier's code"><?= e($l['supplierCode']) ?></div><?php endif; ?>
                        </td>
                        <td><?= e($l['description']) ?></td>
                        <td class="text-right tabular"><?= e($l['qty']) ?> <?= e($l['unit']) ?></td>
                        <td class="text-right tabular"><?= e($l['received']) ?></td>
                        <td class="nowrap">
                            <input form="<?= $formId ?>" type="text" name="qty" value="<?= $left > 0 ? e(\PII\Cms\Write\Ops\Purchasing\PurchasingState::dbl(round($left, 6))) : '' ?>"
                                   inputmode="decimal" style="width:7rem;" autocomplete="off" required aria-label="Quantity of <?= e($l['code']) ?> to receive">
                            <?= e($l['unit']) ?>
                        </td>
                        <td><input form="<?= $formId ?>" type="text" name="packing_slip" maxlength="20" style="width:11rem;" autocomplete="off" required
                                   aria-label="Packing slip number for <?= e($l['code']) ?>"></td>
                        <td>
                            <form id="<?= $formId ?>" method="POST" action="/purchase-orders/<?= (int) $ordr ?>/receive" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="ord_detail" value="<?= (int) $l['ordDetail'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary">Check receipt…</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title">Receipts so far</h2>
    <?php if ($receipts === []): ?>
        <div class="empty-state">Nothing has been received on this PO.</div>
    <?php else: ?>
        <div class="card-subtitle">Reversing a receipt takes the stock back out of the receiving location and reopens the line. The stock must not have been moved or used.</div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Receipt</th><th>Received</th><th>Packing slip</th><th>Item</th><th class="text-right">Quantity</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($receipts as $r):
                    $code = $byLine[$r['ordDetail']]['code'] ?? '';
                ?>
                    <tr>
                        <td class="tabular"><?= (int) $r['changeSet'] ?></td>
                        <td><?= e(fmt_date(substr($r['date'], 0, 10))) ?></td>
                        <td><?= e($r['shipment']) ?></td>
                        <td class="nowrap"><?= $code !== '' ? item_ctx($code) : '' ?></td>
                        <td class="text-right tabular"><?= e($r['qty']) ?> <?= e($r['unit']) ?></td>
                        <td class="text-right">
                            <?php if (!$can['po.reverse_receipt']): ?>
                            <?php elseif ($r['billed']): ?>
                                <span class="text-muted">on a supplier bill</span>
                            <?php elseif ((float) $r['qty'] < 0 || $r['lineCount'] !== 1 || !in_array($order['Status'], ['', 'CMP'], true)): ?>
                                <span class="text-muted">reverse in CMS</span>
                            <?php else: ?>
                                <form method="POST" action="/purchase-orders/<?= (int) $ordr ?>/reverse-receipt" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="change_set" value="<?= (int) $r['changeSet'] ?>">
                                    <button type="submit" class="btn btn-sm">Reverse…</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
/**
 * Change an open sales order or quote. Only what differs from CMS is sent.
 * @var int $ordr  @var ?array $order  @var array $carriers  @var int $addRows
 * @var ?string $error  @var string $mode
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/sales-orders/<?= (int) $ordr ?>" class="btn btn-sm">← Order <?= (int) $ordr ?></a>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
<?php endif; ?>
<?php if ($order === null) { return; } ?>

<form method="POST" action="/sales-orders/<?= (int) $ordr ?>/edit">
    <?= csrf_field() ?>

    <div class="card">
        <h2 class="card-title"><?= $order['IsQuote'] ? 'Quote' : 'Sales order' ?> <?= (int) $ordr ?>
            <?php if ($order['Status'] === 'CSH'): ?><span class="pill pill-warn">Credit hold</span><?php endif; ?>
        </h2>
        <div class="card-subtitle">
            Ships to <strong><?= e($order['ShipToName']) ?></strong>
            <?php if ($order['BillTo'] !== $order['ShipTo']): ?> · billed to <?= e($order['BillToName']) ?><?php endif; ?>
        </div>

        <div class="form-row" style="margin-top:0.75rem;">
            <div class="form-group">
                <label for="so-po">Customer PO number</label>
                <input type="text" id="so-po" name="po_number" maxlength="50" value="<?= e($order['PoNumber']) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="so-date">Required date</label>
                <input type="date" id="so-date" name="date_required" value="<?= e(substr($order['DateRequired'], 0, 10)) ?>" required>
            </div>
            <div class="form-group">
                <label for="so-via">Ship via</label>
                <select id="so-via" name="ship_via">
                    <?php if ($order['ShipVia'] === null): ?><option value="0" selected>(none)</option><?php endif; ?>
                    <?php $listed = false; foreach ($carriers as $c): $sel = $order['ShipVia'] === (int) $c['Entity']; $listed = $listed || $sel; ?>
                        <option value="<?= (int) $c['Entity'] ?>" <?= $sel ? 'selected' : '' ?>><?= e((string) $c['EntityName']) ?></option>
                    <?php endforeach; ?>
                    <?php if ($order['ShipVia'] !== null && !$listed): ?>
                        <option value="<?= (int) $order['ShipVia'] ?>" selected><?= e($order['ShipViaName'] !== '' ? $order['ShipViaName'] : 'Current carrier') ?></option>
                    <?php endif; ?>
                </select>
            </div>
        </div>
        <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;">
            <input type="checkbox" name="duplicate_po_ok" value="1"> Duplicate PO number is fine
        </label>
    </div>

    <div class="card">
        <h2 class="card-title">Lines</h2>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr><th>Item</th><th>Description</th><th>Quantity</th><th class="text-right">Shipped</th><th>Price each</th><th>Remove</th></tr>
                </thead>
                <tbody>
                <?php foreach ($order['lines'] as $l):
                    $id      = (int) $l['ordDetail'];
                    $shipped = (float) $l['shipped'] !== 0.0;
                    $qtyLock = $shipped || (!$order['IsQuote'] && !$l['isOpen']);
                ?>
                    <tr>
                        <td class="nowrap"><?= item_ctx($l['itemCode'], $l['code']) ?></td>
                        <td><?= e($l['description']) ?></td>
                        <td class="nowrap">
                            <input type="text" name="lines[<?= $id ?>][qty]" value="<?= e($l['qty']) ?>" inputmode="decimal" style="width:7rem;"
                                   autocomplete="off" <?= $qtyLock ? 'readonly title="' . ($shipped ? 'Already shipped in part' : 'Closed line') . '"' : '' ?>>
                            <?= e($l['unit']) ?>
                        </td>
                        <td class="text-right tabular"><?= e($l['shipped']) ?></td>
                        <td><input type="text" name="lines[<?= $id ?>][price]" value="<?= e($l['price']) ?>" inputmode="decimal" style="width:7rem;" autocomplete="off"></td>
                        <td>
                            <?php if (!$shipped && $l['pricing'] !== null): ?>
                                <input type="checkbox" name="remove[]" value="<?= $id ?>" aria-label="Remove <?= e($l['code']) ?>">
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php for ($i = 0; $i < $addRows; $i++): ?>
                    <tr>
                        <td><input type="text" name="add[<?= $i ?>][code]" placeholder="Add an item" style="width:14rem;" autocomplete="off"></td>
                        <td class="text-muted">New line</td>
                        <td><input type="text" name="add[<?= $i ?>][qty]" inputmode="decimal" style="width:7rem;" autocomplete="off"></td>
                        <td></td>
                        <td><input type="text" name="add[<?= $i ?>][price]" inputmode="decimal" placeholder="price list" style="width:7rem;" autocomplete="off"></td>
                        <td></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Memos</h2>
        <div class="form-row">
            <div class="form-group" style="flex:1;min-width:280px;">
                <label for="so-imemo">Internal memo</label>
                <textarea id="so-imemo" name="internal_memo" rows="5" maxlength="4000" style="width:100%;"><?= e((string) ($order['memos']['InternalMemo'] ?? '')) ?></textarea>
            </div>
            <div class="form-group" style="flex:1;min-width:280px;">
                <label for="so-smemo">Shipping memo</label>
                <textarea id="so-smemo" name="shipping_memo" rows="5" maxlength="4000" style="width:100%;"><?= e((string) ($order['memos']['ShippingMemo'] ?? '')) ?></textarea>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Check changes…</button>
        <a href="/sales-orders/<?= (int) $ordr ?>" class="btn">Cancel</a>
    </div>
</form>

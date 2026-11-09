<?php
/**
 * Change an open purchase order. Only what differs from CMS is sent.
 * @var int $ordr  @var ?array $order  @var array $carriers  @var int $addRows
 * @var list<string> $incoterms  @var ?string $error  @var string $mode
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

<form method="POST" action="/purchase-orders/<?= (int) $ordr ?>/edit">
    <?= csrf_field() ?>

    <div class="card">
        <h2 class="card-title">Purchase order <?= (int) $ordr ?></h2>
        <div class="card-subtitle">
            <strong><?= e($order['SupplierName']) ?></strong>
            <?php if ($order['Terms'] !== ''): ?> · terms <?= e($order['Terms']) ?><?php endif; ?>
            <?php if ($order['Currency'] !== ''): ?> · <?= e($order['Currency']) ?><?php endif; ?>
        </div>

        <div class="form-row" style="margin-top:0.75rem;">
            <div class="form-group">
                <label for="po-inco">Delivery terms</label>
                <select id="po-inco" name="incoterms">
                    <option value="">(none)</option>
                    <?php $listed = $order['Incoterms'] === ''; foreach ($incoterms as $t): $sel = strcasecmp($order['Incoterms'], $t) === 0; $listed = $listed || $sel; ?>
                        <option value="<?= e($t) ?>" <?= $sel ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                    <?php if (!$listed): ?><option value="<?= e($order['Incoterms']) ?>" selected><?= e($order['Incoterms']) ?></option><?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="po-via">Ship via</label>
                <select id="po-via" name="ship_via">
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
    </div>

    <div class="card">
        <h2 class="card-title">Lines</h2>
        <div class="card-subtitle">Quantities are in the supplier's ordering units, as the PO prints them. To change a price, use CMS.</div>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr><th>Item</th><th>Description</th><th>Quantity</th><th class="text-right">Ordered</th><th class="text-right">Received</th>
                        <th class="text-right">Price</th><th>Promised date</th><th>Remove</th></tr>
                </thead>
                <tbody>
                <?php foreach ($order['lines'] as $l):
                    $id       = (int) $l['ordDetail'];
                    $received = (float) $l['received'] !== 0.0;
                    $qtyLock  = $received || !$l['isOpen'];
                    $packs    = $l['supUnit'] === '';
                ?>
                    <tr>
                        <td class="nowrap"><?= item_ctx($l['code']) ?>
                            <?php if ($l['supplierCode'] !== ''): ?><div class="tag" title="The supplier's code"><?= e($l['supplierCode']) ?></div><?php endif; ?>
                        </td>
                        <td><?= e($l['description']) ?></td>
                        <td class="nowrap">
                            <?php if ($qtyLock): ?>
                                <span title="<?= $received ? 'Already received in part' : 'Closed line' ?>"><?= e($l['supQty']) ?></span>
                            <?php else: ?>
                                <input type="text" name="lines[<?= $id ?>][qty]" value="<?= e($l['supQty']) ?>" inputmode="decimal" style="width:6rem;" autocomplete="off">
                            <?php endif; ?>
                            <?= $packs ? '× ' . e($l['perPack']) . ' ' . e($l['unit']) : e($l['supUnit']) ?>
                        </td>
                        <td class="text-right tabular"><?= e($l['qty']) ?> <?= e($l['unit']) ?></td>
                        <td class="text-right tabular"><?= e($l['received']) ?></td>
                        <td class="text-right tabular"><?= e($l['price']) ?></td>
                        <td><input type="date" name="lines[<?= $id ?>][promised]" value="<?= e($l['promised']) ?>"></td>
                        <td>
                            <?php if (!$received && $l['pricing'] !== null): ?>
                                <input type="checkbox" name="remove[]" value="<?= $id ?>" aria-label="Remove <?= e($l['code']) ?>">
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php for ($i = 0; $i < $addRows; $i++): ?>
                    <tr>
                        <td><input type="text" name="add[<?= $i ?>][code]" placeholder="Add an item" style="width:12rem;" autocomplete="off"></td>
                        <td class="text-muted">New line</td>
                        <td><input type="text" name="add[<?= $i ?>][qty]" inputmode="decimal" style="width:6rem;" autocomplete="off"></td>
                        <td></td>
                        <td></td>
                        <td class="text-right"><input type="text" name="add[<?= $i ?>][price]" inputmode="decimal" placeholder="price list" style="width:7rem;" autocomplete="off"></td>
                        <td><input type="date" name="add[<?= $i ?>][promised]"></td>
                        <td></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Memo</h2>
        <div class="form-group" style="max-width:640px;">
            <label for="po-memo">Memo printed on the PO</label>
            <textarea id="po-memo" name="memo" rows="5" maxlength="4000" style="width:100%;"><?= e((string) ($order['memo'] ?? '')) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Check changes…</button>
        <a href="/purchase-orders/<?= (int) $ordr ?>" class="btn">Cancel</a>
    </div>
</form>

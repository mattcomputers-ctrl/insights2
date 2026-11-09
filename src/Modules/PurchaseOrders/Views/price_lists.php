<?php
/**
 * Supplier price lists: search, then the list one supplier has in effect.
 * @var string $q  @var array $matches  @var ?array $supplier  @var ?array $list  @var array $lines  @var array $packages
 * @var array<string,string> $draft  @var bool $canEdit  @var bool $canAdd  @var ?string $error  @var string $mode
 */
use PII\Cms\Write\Ops\MasterData\PriceListState;
use PII\Cms\Write\Ops\Purchasing\PurchasingState;
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/purchase-orders" class="btn btn-sm">← Purchase Orders</a>
    <?php if ($supplier !== null): ?>
        <a href="/purchase-orders/price-lists" class="btn btn-sm">Find another supplier</a>
    <?php endif; ?>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="alert alert-info"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($supplier === null): ?>
<div class="card">
    <h2 class="card-title">Price lists</h2>
    <div class="card-subtitle">Find a supplier to change a price or add an item to its price list.</div>
    <form method="GET" action="/purchase-orders/price-lists" class="flex gap-1 items-center" style="margin:0.75rem 0;">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Supplier name or code" style="min-width:280px;" autofocus>
        <button type="submit" class="btn btn-sm btn-primary">Search</button>
    </form>
    <?php if ($q !== '' && $matches === [] && $error === null): ?>
        <div class="empty-state">No active suppliers match "<?= e($q) ?>".</div>
    <?php elseif ($matches !== []): ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Supplier</th><th>Code</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($matches as $m): ?>
                    <tr>
                        <td><strong><?= e((string) $m['EntityName']) ?></strong></td>
                        <td class="text-muted"><?= e((string) ($m['EntityCode'] ?? '')) ?></td>
                        <td class="text-right"><a class="btn btn-sm" href="/purchase-orders/price-lists?supplier=<?= e(rawurlencode(trim((string) ($m['EntityCode'] ?? '')))) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php return; endif; ?>

<div class="card">
    <h2 class="card-title"><?= e($supplier['name'] !== '' ? $supplier['name'] : $supplier['code']) ?></h2>
    <div class="card-subtitle">
        <?= e($supplier['code']) ?> ·
        <?php if ($list === null): ?>
            no price list in effect
        <?php else: ?>
            price list in effect since <?= e($list['effective']) ?>, <?= count($lines) ?> <?= count($lines) === 1 ? 'line' : 'lines' ?>
        <?php endif; ?>
    </div>
    <?php if ($list === null): ?>
        <div class="empty-state">This supplier has no price list yet. Start its first price list in CMS; after that, prices can be changed here.</div>
    <?php elseif ($list['later'] !== null): ?>
        <div class="alert alert-info">A price list dated <?= e($list['later']) ?> takes over later. Changes made here go to the list in effect now, not to that one.</div>
    <?php endif; ?>
</div>

<?php if ($list !== null): ?>
<div class="card">
    <h2 class="card-title">Lines</h2>
    <?php if ($lines === []): ?>
        <div class="empty-state">This price list has no lines.</div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Item</th><th>Sold</th><th class="text-right">From</th><th>Price</th><th>Supplier's code</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lines as $l): $formId = 'line-' . (int) $l['priceDetail']; ?>
                    <tr>
                        <td><strong><?= e($l['itemCode']) ?></strong><br><span class="text-muted"><?= e($l['description']) ?></span></td>
                        <td><?= e(PriceListState::pack($l)) ?></td>
                        <td class="text-right"><?= $l['minOrder'] === null ? '' : e(PurchasingState::dbl($l['minOrder'])) ?></td>
                        <?php if ($canEdit): ?>
                            <td>
                                <form method="POST" action="/purchase-orders/price-lists/line" id="<?= e($formId) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="supplier" value="<?= e($supplier['code']) ?>">
                                    <input type="hidden" name="line" value="<?= (int) $l['priceDetail'] ?>">
                                </form>
                                <?php if ($l['tiered']): ?>
                                    <input type="hidden" name="price" value="<?= e((string) $l['price']) ?>" form="<?= e($formId) ?>">
                                    <?= e((string) $l['price']) ?> <span class="text-muted">and prices by quantity (in CMS)</span>
                                <?php else: ?>
                                    <input type="text" name="price" value="<?= e((string) $l['price']) ?>" form="<?= e($formId) ?>" inputmode="decimal"
                                           aria-label="Price of <?= e($l['itemCode']) ?>" style="width:7rem;" required>
                                    <span class="text-muted"><?= e($l['currency']) ?> / <?= e($l['byPackage'] ? 'package' : $l['unit']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><input type="text" name="supplier_code" value="<?= e($l['supplierCode']) ?>" form="<?= e($formId) ?>" maxlength="20"
                                       aria-label="Supplier's code for <?= e($l['itemCode']) ?>" style="width:12rem;"></td>
                            <td class="text-right"><button type="submit" form="<?= e($formId) ?>" class="btn btn-sm">Change…</button></td>
                        <?php else: ?>
                            <td><?= e((string) $l['price']) ?> <span class="text-muted"><?= e($l['currency']) ?> / <?= e($l['byPackage'] ? 'package' : $l['unit']) ?><?= $l['tiered'] ? ', and prices by quantity' : '' ?></span></td>
                            <td><?= e($l['supplierCode']) ?></td>
                            <td></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($canAdd): ?>
<div class="card" id="add">
    <h2 class="card-title">Add an item</h2>
    <div class="card-subtitle">One price per line. An item bought in more than one size of package gets a line for each size.</div>
    <form method="POST" action="/purchase-orders/price-lists/add">
        <?= csrf_field() ?>
        <input type="hidden" name="supplier" value="<?= e($supplier['code']) ?>">
        <div class="form-row" style="flex-wrap:wrap;">
            <div class="form-group" style="width:14rem;">
                <label for="add-item">Our item code</label>
                <input type="text" id="add-item" name="item" value="<?= e($draft['item']) ?>" maxlength="30" required style="text-transform:uppercase;">
            </div>
            <div class="form-group" style="width:14rem;">
                <label for="add-supplier-code">Supplier's code (optional)</label>
                <input type="text" id="add-supplier-code" name="supplier_code" value="<?= e($draft['supplier_code']) ?>" maxlength="20">
            </div>
            <div class="form-group" style="min-width:16rem;flex:1;">
                <label for="add-description">Description (optional)</label>
                <input type="text" id="add-description" name="description" value="<?= e($draft['description']) ?>" maxlength="256" placeholder="The item's own description">
            </div>
        </div>
        <div class="form-row" style="flex-wrap:wrap;">
            <div class="form-group">
                <label for="add-package">Package</label>
                <select id="add-package" name="package">
                    <option value="">Sold loose, not in a package</option>
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= (int) $p['item'] ?>" <?= (string) $p['item'] === $draft['package'] ? 'selected' : '' ?>><?= e($p['code']) ?><?= $p['description'] !== '' && strcasecmp($p['description'], $p['code']) !== 0 ? ' · ' . e($p['description']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="width:10rem;">
                <label for="add-per-pack">Quantity in one package</label>
                <input type="text" id="add-per-pack" name="per_pack" value="<?= e($draft['per_pack']) ?>" inputmode="decimal">
            </div>
            <div class="form-group" style="width:10rem;">
                <label for="add-price">Price per stock unit</label>
                <input type="text" id="add-price" name="price" value="<?= e($draft['price']) ?>" inputmode="decimal" required>
            </div>
            <div class="form-group" style="width:10rem;">
                <label for="add-min-order">Minimum order</label>
                <input type="text" id="add-min-order" name="min_order" value="<?= e($draft['min_order']) ?>" inputmode="decimal" placeholder="1">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Review new line…</button>
    </form>
</div>
<?php endif; ?>
<?php endif; ?>

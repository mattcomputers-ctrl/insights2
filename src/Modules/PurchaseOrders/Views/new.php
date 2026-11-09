<?php
/**
 * New purchase order: pick the supplier, then fill in the order.
 * @var string $q  @var array $matches  @var ?array $supplier  @var array $carriers
 * @var array $draft  @var array $lines  @var int $needCount  @var list<string> $incoterms
 * @var ?string $error  @var string $mode
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/purchase-orders" class="btn btn-sm">← Purchase Orders</a>
    <?php if ($supplier !== null): ?>
        <a href="/purchase-orders/new?clear=1" class="btn btn-sm">Choose a different supplier</a>
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
    <h2 class="card-title">New purchase order</h2>
    <div class="card-subtitle">
        Find the supplier to order from.
        <?php if ($needCount > 0): ?>
            The <?= (int) $needCount ?> item<?= $needCount === 1 ? '' : 's' ?> from Need to Order will be filled in once you choose.
        <?php endif; ?>
    </div>
    <form method="GET" action="/purchase-orders/new" class="flex gap-1 items-center" style="margin:0.75rem 0;">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Supplier name or code" style="min-width:280px;" autofocus>
        <button type="submit" class="btn btn-sm btn-primary">Search</button>
    </form>
    <?php if ($q !== '' && $matches === [] && $error === null): ?>
        <div class="empty-state">No active suppliers match "<?= e($q) ?>".</div>
    <?php elseif ($matches !== []): ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Supplier</th><th>Code</th><th>Terms</th><th>Currency</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($matches as $m): ?>
                    <tr>
                        <td><strong><?= e((string) $m['EntityName']) ?></strong></td>
                        <td class="text-muted"><?= e((string) ($m['EntityCode'] ?? '')) ?></td>
                        <td class="text-muted"><?= e((string) ($m['Terms'] ?? '')) ?></td>
                        <td class="text-muted"><?= e((string) ($m['Currency'] ?? '')) ?></td>
                        <td class="text-right"><a class="btn btn-sm" href="/purchase-orders/new?supplier=<?= (int) $m['Entity'] ?>">Choose</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php return; endif; ?>

<?php
$a    = $supplier['address'];
$addr = array_filter([
    $a['AddrLine1'] ?? '', $a['AddrLine2'] ?? '', $a['AddrLine3'] ?? '',
    trim(($a['City'] ?? '') . ', ' . ($a['State'] ?? '') . ' ' . ($a['ZipCode'] ?? ''), ' ,'),
], static fn ($v) => trim((string) $v) !== '');
$memo    = array_key_exists('memo', $draft) ? (string) $draft['memo'] : (string) ($supplier['memo'] ?? '');
$inco    = array_key_exists('incoterms', $draft) ? (string) $draft['incoterms'] : $supplier['incoterms'];
$shipVia = (int) ($draft['ship_via'] ?? 0);
$usual   = '';
foreach ($carriers as $c) {
    if ((int) $c['Entity'] === (int) $supplier['shipVia']) {
        $usual = (string) $c['EntityName'];
    }
}
$hasNotes = false;
foreach ($lines as $l) {
    $hasNotes = $hasNotes || trim((string) ($l['note'] ?? '')) !== '';
}
?>
<form method="POST" action="/purchase-orders/new">
    <?= csrf_field() ?>
    <input type="hidden" name="supplier" value="<?= (int) $supplier['entity'] ?>">

    <div class="card">
        <h2 class="card-title">New purchase order for <?= e($supplier['name']) ?></h2>
        <div class="card-subtitle">
            <?= $addr === [] ? 'No address in CMS' : e(implode(', ', $addr)) ?>
            <?php if ($supplier['terms'] !== ''): ?> · terms <?= e($supplier['terms']) ?><?php endif; ?>
            <?php if ($supplier['currency'] !== ''): ?> · <?= e($supplier['currency']) ?><?php endif; ?>
        </div>
        <?php if ($supplier['inactive']): ?>
            <div class="alert alert-warning">This supplier is marked inactive in CMS.</div>
        <?php endif; ?>

        <div class="form-row" style="margin-top:0.75rem;">
            <div class="form-group">
                <label for="po-inco">Delivery terms</label>
                <select id="po-inco" name="incoterms">
                    <option value="">(none)</option>
                    <?php foreach ($incoterms as $t): ?>
                        <option value="<?= e($t) ?>" <?= strcasecmp($inco, $t) === 0 ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="po-via">Ship via</label>
                <select id="po-via" name="ship_via">
                    <option value="0"><?= $usual !== '' ? "Supplier's usual carrier (" . e($usual) . ')' : '(none)' ?></option>
                    <?php foreach ($carriers as $c): ?>
                        <option value="<?= (int) $c['Entity'] ?>" <?= $shipVia === (int) $c['Entity'] ? 'selected' : '' ?>><?= e((string) $c['EntityName']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Lines</h2>
        <div class="card-subtitle">
            Enter our item code or the supplier's. Quantity is in the supplier's ordering units: whole packages where
            their price list sells by the package, otherwise the item's own unit. Leave the price blank to use the price list.
        </div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Item</th><th>Quantity</th><th>Price each</th><th>Promised date</th><?= $hasNotes ? '<th>From Need to Order</th>' : '' ?></tr></thead>
                <tbody>
                <?php foreach ($lines as $i => $l): ?>
                    <tr>
                        <td><input type="text" name="lines[<?= (int) $i ?>][code]" value="<?= e((string) ($l['code'] ?? '')) ?>" style="width:16rem;" autocomplete="off"></td>
                        <td><input type="text" name="lines[<?= (int) $i ?>][qty]" value="<?= e((string) ($l['qty'] ?? '')) ?>" inputmode="decimal" style="width:8rem;" autocomplete="off"></td>
                        <td><input type="text" name="lines[<?= (int) $i ?>][price]" value="<?= e((string) ($l['price'] ?? '')) ?>" inputmode="decimal" placeholder="price list" style="width:8rem;" autocomplete="off"></td>
                        <td><input type="date" name="lines[<?= (int) $i ?>][promised]" value="<?= e((string) ($l['promised'] ?? '')) ?>"></td>
                        <?php if ($hasNotes): ?><td class="text-muted" style="font-size:0.85rem;"><?= e((string) ($l['note'] ?? '')) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Memo</h2>
        <div class="form-group" style="max-width:640px;">
            <label for="po-memo">Memo printed on the PO<?= $supplier['memo'] !== null ? " (starts as the supplier's standing memo)" : '' ?></label>
            <textarea id="po-memo" name="memo" rows="5" maxlength="4000" style="width:100%;"><?= e($memo) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Check order…</button>
        <span class="text-muted" style="margin-left:0.5rem;">You will see exactly what is sent before anything is saved.</span>
    </div>
</form>

<?php
/**
 * New sales order or quote: pick the customer, then fill in the order.
 * @var string $q  @var array $matches  @var ?array $customer  @var array $carriers
 * @var array $draft  @var array $lines  @var ?string $error  @var string $mode
 */
$val = static fn (string $key, string $default = ''): string => (string) ($draft[$key] ?? $default);
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/sales-orders" class="btn btn-sm">← Sales Orders</a>
    <?php if ($customer !== null): ?>
        <a href="/sales-orders/new?clear=1" class="btn btn-sm">Choose a different customer</a>
    <?php endif; ?>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="alert alert-info"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($customer === null): ?>
<div class="card">
    <h2 class="card-title">New sales order</h2>
    <div class="card-subtitle">Find the customer the order ships to.</div>
    <form method="GET" action="/sales-orders/new" class="flex gap-1 items-center" style="margin:0.75rem 0;">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Customer name or code" style="min-width:280px;" autofocus>
        <button type="submit" class="btn btn-sm btn-primary">Search</button>
    </form>
    <?php if ($q !== '' && $matches === [] && $error === null): ?>
        <div class="empty-state">No active customers match "<?= e($q) ?>".</div>
    <?php elseif ($matches !== []): ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Ships to</th><th>Code</th><th>Billed to</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($matches as $m): ?>
                    <tr>
                        <td><strong><?= e((string) $m['EntityName']) ?></strong></td>
                        <td class="text-muted"><?= e((string) ($m['EntityCode'] ?? '')) ?></td>
                        <td class="text-muted"><?= e((string) ($m['BillToName'] ?: $m['EntityName'])) ?></td>
                        <td class="text-right"><a class="btn btn-sm" href="/sales-orders/new?ship_to=<?= (int) $m['Entity'] ?>">Choose</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php return; endif; ?>

<?php
$a    = $customer['address'];
$addr = array_filter([
    $a['AddrLine1'] ?? '', $a['AddrLine2'] ?? '', $a['AddrLine3'] ?? '',
    trim(($a['City'] ?? '') . ', ' . ($a['State'] ?? '') . ' ' . ($a['ZipCode'] ?? ''), ' ,'),
], static fn ($v) => trim((string) $v) !== '');
$memo    = array_key_exists('internal_memo', $draft) ? (string) $draft['internal_memo'] : (string) ($customer['memo']['text'] ?? '');
$shipVia = (int) ($draft['ship_via'] ?? 0);
$usual   = '';
foreach ($carriers as $c) {
    if ((string) $c['Entity'] === (string) $customer['shipVia']) {
        $usual = (string) $c['EntityName'];
    }
}
?>
<form method="POST" action="/sales-orders/new">
    <?= csrf_field() ?>
    <input type="hidden" name="ship_to" value="<?= (int) $customer['shipTo'] ?>">

    <div class="card">
        <h2 class="card-title">New sales order for <?= e($customer['shipToName']) ?></h2>
        <div class="card-subtitle">
            <?php if ($customer['billTo'] !== $customer['shipTo']): ?>Billed to <strong><?= e($customer['billToName']) ?></strong> · <?php endif; ?>
            <?= $addr === [] ? 'No address in CMS' : 'Ships to ' . e(implode(', ', $addr)) ?>
            <?php if ($customer['terms'] !== ''): ?> · terms <?= e($customer['terms']) ?><?php endif; ?>
            <?php if ($customer['currency'] !== ''): ?> · <?= e($customer['currency']) ?><?php endif; ?>
        </div>
        <?php if ($customer['inactive']): ?>
            <div class="alert alert-warning">This customer is marked inactive in CMS.</div>
        <?php endif; ?>

        <div class="form-row" style="margin-top:0.75rem;">
            <div class="form-group">
                <label for="so-po">Customer PO number</label>
                <input type="text" id="so-po" name="po_number" maxlength="50" value="<?= e($val('po_number')) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="so-date">Required date</label>
                <input type="date" id="so-date" name="date_required" value="<?= e($val('date_required')) ?>" required>
            </div>
            <div class="form-group">
                <label for="so-via">Ship via</label>
                <select id="so-via" name="ship_via">
                    <option value="0">Customer's usual carrier<?= $usual !== '' ? ' (' . e($usual) . ')' : '' ?></option>
                    <?php foreach ($carriers as $c): ?>
                        <option value="<?= (int) $c['Entity'] ?>" <?= $shipVia === (int) $c['Entity'] ? 'selected' : '' ?>><?= e((string) $c['EntityName']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
            <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;">
                <input type="checkbox" name="is_quote" value="1" <?= !empty($draft['is_quote']) ? 'checked' : '' ?>> This is a quote, not an order
            </label>
            <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;">
                <input type="checkbox" name="duplicate_po_ok" value="1" <?= !empty($draft['duplicate_po_ok']) ? 'checked' : '' ?>> Duplicate PO number is fine
            </label>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Lines</h2>
        <div class="card-subtitle">Enter the item the way the customer orders it. Leave the price blank to use the customer's price list.</div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Item</th><th>Quantity</th><th>Price each</th></tr></thead>
                <tbody>
                <?php foreach ($lines as $i => $l): ?>
                    <tr>
                        <td><input type="text" name="lines[<?= (int) $i ?>][code]" value="<?= e((string) ($l['code'] ?? '')) ?>" style="width:16rem;" autocomplete="off"></td>
                        <td><input type="text" name="lines[<?= (int) $i ?>][qty]" value="<?= e((string) ($l['qty'] ?? '')) ?>" inputmode="decimal" style="width:8rem;" autocomplete="off"></td>
                        <td><input type="text" name="lines[<?= (int) $i ?>][price]" value="<?= e((string) ($l['price'] ?? '')) ?>" inputmode="decimal" placeholder="price list" style="width:8rem;" autocomplete="off"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Memos</h2>
        <div class="form-row">
            <div class="form-group" style="flex:1;min-width:280px;">
                <label for="so-imemo">Internal memo<?= $customer['memo'] !== null ? ' (starts as the customer\'s standing memo)' : '' ?></label>
                <textarea id="so-imemo" name="internal_memo" rows="5" maxlength="4000" style="width:100%;"><?= e($memo) ?></textarea>
            </div>
            <div class="form-group" style="flex:1;min-width:280px;">
                <label for="so-smemo">Shipping memo</label>
                <textarea id="so-smemo" name="shipping_memo" rows="5" maxlength="4000" style="width:100%;"><?= e($val('shipping_memo')) ?></textarea>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Check order…</button>
        <span class="text-muted" style="margin-left:0.5rem;">You will see exactly what is sent before anything is saved.</span>
    </div>
</form>

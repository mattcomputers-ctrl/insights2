<?php
/**
 * Customers: search, then one customer with its address and shipping settings.
 * @var string $q  @var array $matches  @var ?array $customer  @var ?array $billTo  @var array $carriers
 * @var array<string,array{label:string,max:int,input:string}> $fields
 * @var bool $canAddress  @var bool $canShipping  @var bool $canShipTo  @var ?string $error  @var string $mode
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/sales-orders" class="btn btn-sm">← Sales Orders</a>
    <?php if ($customer !== null): ?>
        <a href="/sales-orders/customers" class="btn btn-sm">Find another customer</a>
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
    <h2 class="card-title">Customers</h2>
    <div class="card-subtitle">Find a customer to change its address or how it ships, or to add a ship-to.</div>
    <form method="GET" action="/sales-orders/customers" class="flex gap-1 items-center" style="margin:0.75rem 0;">
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
                        <td class="text-right"><a class="btn btn-sm" href="/sales-orders/customers?code=<?= e(rawurlencode((string) ($m['EntityCode'] ?? ''))) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php return; endif; ?>

<?php $a = $customer['address']; ?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title"><?= e($customer['name'] !== '' ? $customer['name'] : $customer['code']) ?></h2>
            <div class="card-subtitle">
                <?= e($customer['code']) ?> ·
                <?php if ($customer['isBillTo']): ?>
                    Bill-to account<?= $customer['isShipTo'] ? ', also shipped to' : '' ?>
                <?php else: ?>
                    Ship-to<?php if ($billTo !== null): ?>, billed to
                        <a href="/sales-orders/customers?code=<?= e(rawurlencode(trim((string) $billTo['EntityCode']))) ?>"><?= e(trim((string) ($billTo['EntityName'] ?: $billTo['EntityCode']))) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
                <?= $customer['inactive'] ? ' · inactive' : '' ?>
            </div>
        </div>
        <?php if ($canShipTo && $customer['isBillTo'] && !$customer['inactive']): ?>
            <a href="/sales-orders/customers/new?parent=<?= e(rawurlencode($customer['code'])) ?>" class="btn btn-sm btn-primary">New ship-to…</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2 class="card-title">Address</h2>
    <?php if (!$customer['hasAddress']): ?>
        <div class="empty-state">This customer has no address in CMS yet.</div>
    <?php elseif ($canAddress): ?>
        <form method="POST" action="/sales-orders/customers/address">
            <?= csrf_field() ?>
            <input type="hidden" name="customer" value="<?= e($customer['code']) ?>">
            <div class="form-row" style="flex-wrap:wrap;">
                <?php foreach ($fields as $field => $def): ?>
                    <div class="form-group" style="<?= in_array($field, ['State', 'ZipCode'], true) ? 'width:8rem;' : 'min-width:16rem;flex:1;' ?>">
                        <label for="addr-<?= e($def['input']) ?>"><?= e($def['label']) ?></label>
                        <input type="<?= $field === 'Email' ? 'email' : 'text' ?>" id="addr-<?= e($def['input']) ?>" name="<?= e($def['input']) ?>"
                               value="<?= e($a[$field]) ?>" maxlength="<?= (int) $def['max'] ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($a['AddrLine3'] !== '' || $a['Country'] !== ''): ?>
                <p class="text-muted">Also in CMS, not changed here: <?= e(trim($a['AddrLine3'] . ' ' . $a['Country'])) ?></p>
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-primary">Change address…</button>
        </form>
    <?php else: ?>
        <p><?= e(implode(', ', array_filter([$a['Name'], $a['AddrLine1'], $a['AddrLine2'], $a['AddrLine3'],
            trim($a['City'] . ', ' . $a['State'] . ' ' . $a['ZipCode'], ' ,'), $a['Country'], $a['Phone'], $a['Email']]))) ?></p>
    <?php endif; ?>
</div>

<?php if ($customer['isShipTo']): ?>
<div class="card">
    <h2 class="card-title">Shipping</h2>
    <?php
    $carrierName = '';
    foreach ($carriers as $c) {
        if ((int) $c['Entity'] === (int) ($customer['shipVia'] ?? 0)) {
            $carrierName = (string) $c['EntityName'];
        }
    }
    ?>
    <?php if ($canShipping): ?>
        <form method="POST" action="/sales-orders/customers/shipping" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="customer" value="<?= e($customer['code']) ?>">
            <div class="form-group" style="margin-bottom:0;">
                <label for="ship-via">Carrier</label>
                <select id="ship-via" name="ship_via" required>
                    <?php if ($carrierName === ''): ?><option value="">Choose…</option><?php endif; ?>
                    <?php foreach ($carriers as $c): ?>
                        <option value="<?= (int) $c['Entity'] ?>" <?= (int) $c['Entity'] === (int) ($customer['shipVia'] ?? 0) ? 'selected' : '' ?>><?= e((string) $c['EntityName']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label for="freight-account">Freight account</label>
                <input type="text" id="freight-account" name="freight_account" value="<?= e($customer['freightAccount']) ?>" maxlength="20" style="width:12rem;">
            </div>
            <button type="submit" class="btn btn-sm">Change shipping…</button>
        </form>
    <?php else: ?>
        <p>Carrier: <?= e($carrierName !== '' ? $carrierName : 'none') ?> · Freight account: <?= e($customer['freightAccount'] !== '' ? $customer['freightAccount'] : 'none') ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

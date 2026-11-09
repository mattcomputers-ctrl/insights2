<?php
/**
 * New ship-to under a bill-to account, created in CMS after a review.
 * @var ?array $parent  @var array $carriers  @var array<string,string> $draft
 * @var array<string,array{label:string,max:int,input:string}> $fields
 * @var ?string $error  @var string $mode
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <?php if ($parent !== null): ?>
        <a href="/sales-orders/customers?code=<?= e(rawurlencode($parent['code'])) ?>" class="btn btn-sm">← <?= e($parent['code']) ?></a>
    <?php else: ?>
        <a href="/sales-orders/customers" class="btn btn-sm">← Customers</a>
    <?php endif; ?>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: the ship-to is checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="alert alert-info"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($parent !== null): ?>
<div class="card">
    <h2 class="card-title">New ship-to</h2>
    <div class="card-subtitle">A delivery address of <?= e($parent['name'] !== '' ? $parent['name'] : $parent['code']) ?> (<?= e($parent['code']) ?>). Invoices keep going to the account.</div>
    <form method="POST" action="/sales-orders/customers/new">
        <?= csrf_field() ?>
        <input type="hidden" name="parent" value="<?= e($parent['code']) ?>">
        <div class="form-row" style="flex-wrap:wrap;">
            <div class="form-group" style="width:14rem;">
                <label for="new-code">Ship-to code</label>
                <input type="text" id="new-code" name="code" value="<?= e($draft['code']) ?>" maxlength="20" required style="text-transform:uppercase;">
            </div>
            <?php foreach ($fields as $field => $def): $optional = in_array($field, ['AddrLine2', 'Email', 'Phone'], true); ?>
                <div class="form-group" style="<?= in_array($field, ['State', 'ZipCode'], true) ? 'width:8rem;' : 'min-width:16rem;flex:1;' ?>">
                    <label for="new-<?= e($def['input']) ?>"><?= e($def['label']) ?><?= $optional ? ' (optional)' : '' ?></label>
                    <input type="<?= $field === 'Email' ? 'email' : 'text' ?>" id="new-<?= e($def['input']) ?>" name="<?= e($def['input']) ?>"
                           value="<?= e($draft[$def['input']]) ?>" maxlength="<?= (int) $def['max'] ?>" <?= $optional ? '' : 'required' ?>>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="new-ship-via">Carrier</label>
                <select id="new-ship-via" name="ship_via" required>
                    <option value="">Choose…</option>
                    <?php foreach ($carriers as $c): ?>
                        <option value="<?= (int) $c['Entity'] ?>" <?= (string) (int) $c['Entity'] === $draft['ship_via'] ? 'selected' : '' ?>><?= e((string) $c['EntityName']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="new-freight-account">Freight account (optional)</label>
                <input type="text" id="new-freight-account" name="freight_account" value="<?= e($draft['freight_account']) ?>" maxlength="20" style="width:12rem;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Review new ship-to…</button>
    </form>
</div>
<?php endif; ?>

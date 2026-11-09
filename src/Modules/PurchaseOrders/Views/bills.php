<?php
/**
 * Supplier bills: received lines waiting for a bill, by supplier, each with the form to enter the invoice.
 * @var ?int $supplier  @var list<array<string,mixed>> $groups  @var array<string,string> $draft
 * @var array<int,bool> $ticked  @var array<int,string> $extra  @var int $limitDays  @var ?string $error  @var string $mode
 */
use PII\Cms\Write\Ops\Purchasing\BillState;
use PII\Cms\Write\Ops\Purchasing\PurchasingState;
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/purchase-orders" class="btn btn-sm">← Purchase Orders</a>
    <?php if ($supplier !== null): ?>
        <a href="/purchase-orders/bills" class="btn btn-sm">All suppliers</a>
    <?php endif; ?>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: bills are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="alert alert-info"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($supplier === null): ?>
<div class="card">
    <h2 class="card-title">Received and waiting for a bill</h2>
    <div class="card-subtitle">Everything received on a purchase order that no supplier invoice has been entered for yet.</div>
    <?php if ($groups === [] && $error === null): ?>
        <div class="empty-state">Nothing is waiting for a bill.</div>
    <?php elseif ($groups !== []): ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Supplier</th><th class="text-right">Lines</th><th>Oldest receipt</th><th class="text-right">Received value</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td><strong><?= e($g['name'] !== '' ? $g['name'] : $g['code']) ?></strong><br><span class="text-muted"><?= e($g['code']) ?></span></td>
                        <td class="text-right"><?= count($g['lines']) ?></td>
                        <td><?= e($g['oldest']) ?></td>
                        <td class="text-right"><?= e(BillState::show($g['total'])) ?></td>
                        <td class="text-right"><a class="btn btn-sm btn-primary" href="/purchase-orders/bills?supplier=<?= (int) $g['supplier'] ?>">Enter a bill…</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php return; endif; ?>

<?php if ($groups === []): ?>
    <?php if ($error === null): ?>
    <div class="card">
        <h2 class="card-title">Supplier bills</h2>
        <div class="empty-state">Nothing received from this supplier is waiting for a bill.</div>
    </div>
    <?php endif; ?>
<?php return; endif; ?>

<?php $g = $groups[0]; ?>
<form method="POST" action="/purchase-orders/bills/create">
    <?= csrf_field() ?>
    <input type="hidden" name="supplier" value="<?= (int) $g['supplier'] ?>">
    <div class="card">
        <h2 class="card-title">Bill from <?= e($g['name'] !== '' ? $g['name'] : $g['code']) ?></h2>
        <div class="card-subtitle">Tick the received lines the invoice covers. Freight, a surcharge or a credit goes on a line as an extra cost.</div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th></th><th>Item</th><th>PO</th><th>Received</th><th>Packing slip</th><th class="text-right">Quantity</th><th class="text-right">Value</th><th>Extra cost</th></tr></thead>
                <tbody>
                <?php foreach ($g['lines'] as $l): $id = (int) $l['billDetail']; ?>
                    <tr>
                        <td><input type="checkbox" name="lines[]" value="<?= $id ?>" id="line-<?= $id ?>" <?= isset($ticked[$id]) ? 'checked' : '' ?>
                                   aria-label="Include <?= e($l['item']) ?> received <?= e($l['received']) ?>"></td>
                        <td><label for="line-<?= $id ?>"><strong><?= e($l['item']) ?></strong></label><br><span class="text-muted"><?= e($l['description']) ?></span></td>
                        <td><a href="/purchase-orders/<?= (int) $l['ordr'] ?>"><?= (int) $l['ordr'] ?></a></td>
                        <td><?= e($l['received']) ?></td>
                        <td><?= e($l['packingSlip']) ?></td>
                        <td class="text-right"><?= $l['qty'] === null ? '' : e(PurchasingState::dbl($l['qty'])) ?> <?= e($l['unit']) ?></td>
                        <td class="text-right"><?= e(BillState::show(BillState::cents($l['amount']))) ?></td>
                        <td><input type="text" name="add_cost[<?= $id ?>]" value="<?= e($extra[$id] ?? '') ?>" inputmode="decimal" style="width:7rem;"
                                   aria-label="Extra cost for <?= e($l['item']) ?>" placeholder="0.00"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h2 class="card-title">The invoice</h2>
        <div class="card-subtitle">The total has to match the ticked lines and their extra costs. An invoice dated more than <?= (int) $limitDays ?> days back is entered in CMS.</div>
        <div class="form-row" style="flex-wrap:wrap;">
            <div class="form-group" style="width:14rem;">
                <label for="bill-invoice">Invoice number</label>
                <input type="text" id="bill-invoice" name="invoice" value="<?= e($draft['invoice']) ?>" maxlength="20" required>
            </div>
            <div class="form-group" style="width:12rem;">
                <label for="bill-date">Invoice date</label>
                <input type="date" id="bill-date" name="invoice_date" value="<?= e($draft['invoice_date']) ?>" max="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="form-group" style="width:12rem;">
                <label for="bill-total">Invoice total (USD)</label>
                <input type="text" id="bill-total" name="total" value="<?= e($draft['total']) ?>" inputmode="decimal" required>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Review bill…</button>
    </div>
</form>

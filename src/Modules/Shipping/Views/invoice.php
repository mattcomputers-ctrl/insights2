<?php
/**
 * Invoice as CMS recorded it. The toolbar never prints, only the sheet does.
 * @var ?array $head  @var array $lines  @var array $taxes  @var ?string $error  @var string $logo
 * @var ?array{blocker:?string,current:?string} $freight  set when the user may change the freight charge of this invoice
 * @var string $draft  @var string $mode
 */
$company = $head !== null && trim((string) ($head['DivName'] ?? '')) !== ''
    ? (string) $head['DivName'] : (string) \PII\Core\App::config('company.name', '');
$block = static function (array $h, string $p): string {
    $rows = [];
    foreach ([$p . 'Name', $p . 'AddrLine1', $p . 'AddrLine2', $p . 'AddrLine3', $p . 'CityStateZip', $p . 'Country'] as $k) {
        $v = trim((string) ($h[$k] ?? ''));
        if ($v !== '') {
            $rows[] = $k === $p . 'Name' ? '<strong>' . e($v) . '</strong>' : e($v);
        }
    }
    return implode('<br>', $rows);
};
$money = static fn ($v): string => fmt_money((float) ($v ?? 0));
?>
<div class="toolbar no-print" style="margin-bottom:1rem;">
    <?php if ($head !== null && $head['Waybill'] !== null): ?>
        <a href="/shipping/<?= (int) $head['Waybill'] ?>" class="btn btn-sm">← Waybill <?= (int) $head['Waybill'] ?></a>
    <?php else: ?>
        <a href="/shipping" class="btn btn-sm">← Shipping</a>
    <?php endif; ?>
    <?php if ($head !== null): ?>
        <button type="button" class="btn" onclick="window.print()">🖨 Print / save PDF</button>
    <?php endif; ?>
</div>

<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
    <?php return; ?>
<?php endif; ?>

<?php
$from = array_filter([
    trim((string) ($head['DivAddrLine1'] ?? '')), trim((string) ($head['DivAddrLine2'] ?? '')), trim((string) ($head['DivAddrLine3'] ?? '')),
    trim(trim((string) ($head['DivCity'] ?? '')) . ' ' . trim((string) ($head['DivState'] ?? '')) . ' ' . trim((string) ($head['DivZipCode'] ?? ''))),
    trim((string) ($head['DivPhone'] ?? '')),
], static fn (string $v): bool => $v !== '');
$reversed = in_array($head['IsReversed'] ?? 0, [1, '1', true], true);
?>
<?php if ($freight !== null): ?>
<div class="card no-print">
    <h2 class="card-title">Freight charge</h2>
    <?php if ($freight['blocker'] !== null): ?>
        <div class="card-subtitle"><?= e($freight['blocker']) ?></div>
    <?php else: ?>
        <div class="card-subtitle">CMS reverses the invoice and issues it again under the same number with the freight charge you enter. Nothing else on it changes.</div>
        <?php if ($mode !== 'live'): ?>
            <div class="alert alert-info">Practice mode: the correction is checked and previewed, but nothing is sent to CMS.</div>
        <?php endif; ?>
        <form method="POST" action="/shipping/invoice/<?= (int) $head['Trans'] ?>/freight" class="form-row" style="align-items:flex-end;">
            <?= csrf_field() ?>
            <div class="form-group" style="width:14rem;">
                <label for="invoice-freight">Freight charge (USD)</label>
                <input type="text" id="invoice-freight" name="freight" inputmode="decimal" required
                       value="<?= e($draft !== '' ? $draft : '') ?>"
                       placeholder="<?= $freight['current'] !== null && (float) $freight['current'] != 0.0 ? e(number_format((float) $freight['current'], 2, '.', '')) : '0.00' ?>">
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary"><?= $freight['current'] !== null && (float) $freight['current'] != 0.0 ? 'Change freight…' : 'Add freight…' ?></button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="card coa-sheet">
    <div class="coa-head">
        <div>
            <?php if ($logo !== ''): ?>
                <img src="<?= e($logo) ?>" alt="<?= e($company) ?>" class="coa-logo">
            <?php else: ?>
                <div class="coa-company"><?= e($company) ?></div>
            <?php endif; ?>
            <?php if ($from !== []): ?><div class="text-muted" style="font-size:0.8rem;"><?= e(implode(' · ', $from)) ?></div><?php endif; ?>
        </div>
        <h1 class="coa-title">Invoice</h1>
    </div>

    <?php if ($reversed): ?>
        <div class="alert alert-danger"><strong>Reversed.</strong> This invoice was reversed in CMS.</div>
    <?php endif; ?>

    <div style="display:flex;gap:2rem;flex-wrap:wrap;margin-bottom:1rem;">
        <div style="flex:1;min-width:14rem;">
            <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Bill to</div>
            <?= $block($head, 'BillTo') ?>
        </div>
        <div style="flex:1;min-width:14rem;">
            <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Ship to</div>
            <?= $block($head, 'ShipTo') ?>
        </div>
        <table class="coa-meta" style="margin:0;">
            <tr><td>Invoice</td><td><strong><?= e((string) ($head['Invoice'] ?? '')) ?></strong></td></tr>
            <?php if ($head['DateShipped']): ?><tr><td>Date</td><td><?= e(fmt_date($head['DateShipped'], 'F j, Y')) ?></td></tr><?php endif; ?>
            <?php if ($head['Ordr'] !== null): ?><tr><td>Order</td><td><?= (int) $head['Ordr'] ?></td></tr><?php endif; ?>
            <?php if (trim((string) ($head['PoNumber'] ?? '')) !== ''): ?><tr><td>PO number</td><td><strong><?= e((string) $head['PoNumber']) ?></strong></td></tr><?php endif; ?>
            <?php if (trim((string) ($head['TermsDescription'] ?? '')) !== ''): ?><tr><td>Terms</td><td><?= e((string) $head['TermsDescription']) ?></td></tr><?php endif; ?>
            <?php if (trim((string) ($head['ShipViaName'] ?? '')) !== ''): ?><tr><td>Ship via</td><td><?= e((string) $head['ShipViaName']) ?></td></tr><?php endif; ?>
            <?php if (trim((string) ($head['SalesmanName'] ?? '')) !== ''): ?><tr><td>Salesperson</td><td><?= e((string) $head['SalesmanName']) ?></td></tr><?php endif; ?>
        </table>
    </div>

    <?php if ($lines === []): ?>
        <div class="alert alert-warn">No lines are recorded on this invoice.</div>
    <?php else: ?>
        <table class="table coa-results">
            <thead>
                <tr><th>Item</th><th>Description</th><th class="text-right">Ordered</th><th class="text-right">Shipped</th>
                    <th>Unit</th><th class="text-right">Price</th><th class="text-right">Amount</th></tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $l):
                $their = trim((string) ($l['TheirCode'] ?? '')); ?>
                <tr>
                    <td class="nowrap"><strong><?= e($their !== '' ? $their : (string) ($l['ItemCode'] ?? '')) ?></strong></td>
                    <td><?= e((string) ($l['ItemDescription'] ?? '')) ?>
                        <?php if (trim((string) ($l['OrdDetailComment'] ?? '')) !== ''): ?><div class="text-muted" style="font-size:0.8rem;white-space:pre-wrap;"><?= e(trim((string) $l['OrdDetailComment'])) ?></div><?php endif; ?>
                    </td>
                    <td class="text-right tabular"><?= $l['QtyReqd'] !== null ? fmt_number((float) $l['QtyReqd'], 2) : '' ?></td>
                    <td class="text-right tabular"><?= $l['QtyShipped'] !== null ? fmt_number((float) $l['QtyShipped'], 2) : '' ?></td>
                    <td><?= e((string) ($l['Unit'] ?? '')) ?></td>
                    <td class="text-right tabular nowrap"><?= $l['Price'] !== null ? fmt_money((float) $l['Price'], 4) : '' ?><?= trim((string) ($l['PricingUnit'] ?? '')) !== '' ? ' / ' . e((string) $l['PricingUnit']) : '' ?></td>
                    <td class="text-right tabular"><?= $l['Value'] !== null ? $money($l['Value']) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <table class="coa-meta" style="margin:1rem 0 0 auto;">
        <tr><td>Subtotal</td><td class="text-right tabular"><?= $money($head['Amount']) ?></td></tr>
        <?php if ((float) ($head['FreightCharge'] ?? 0) != 0.0): ?>
            <tr><td>Freight</td><td class="text-right tabular"><?= $money($head['FreightCharge']) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($taxes as $t): ?>
            <tr><td><?= e(trim((string) ($t['TaxDescription'] ?? '')) !== '' ? (string) $t['TaxDescription'] : 'Tax') ?></td>
                <td class="text-right tabular"><?= $money($t['TaxAmount']) ?></td></tr>
        <?php endforeach; ?>
        <tr><td><strong>Total<?= trim((string) ($head['Currency'] ?? '')) !== '' ? ' (' . e((string) $head['Currency']) . ')' : '' ?></strong></td>
            <td class="text-right tabular"><strong><?= $money($head['TotalAmount']) ?></strong></td></tr>
        <?php if ($head['NetAmountDue'] !== null && (float) $head['NetAmountDue'] != (float) ($head['TotalAmount'] ?? 0)): ?>
            <tr><td>Amount due</td><td class="text-right tabular"><?= $money($head['NetAmountDue']) ?></td></tr>
        <?php endif; ?>
    </table>

    <?php if (trim((string) ($head['InvoiceMemo'] ?? '')) !== ''): ?>
        <p style="margin-top:1rem;white-space:pre-wrap;"><?= e((string) $head['InvoiceMemo']) ?></p>
    <?php endif; ?>
</div>

<?php
/**
 * Packing slip for one order's shipment on a waybill. The toolbar never
 * prints, only the sheet does.
 * @var ?array $head  @var array $lines  @var ?string $error  @var string $logo
 */
$company = (string) \PII\Core\App::config('company.name', '');
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

<div class="card coa-sheet">
    <div class="coa-head">
        <?php if ($logo !== ''): ?>
            <img src="<?= e($logo) ?>" alt="<?= e($company) ?>" class="coa-logo">
        <?php else: ?>
            <div class="coa-company"><?= e($company) ?></div>
        <?php endif; ?>
        <h1 class="coa-title">Packing Slip</h1>
    </div>

    <div style="display:flex;gap:2rem;flex-wrap:wrap;margin-bottom:1rem;">
        <div style="flex:1;min-width:14rem;">
            <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Ship to</div>
            <?= $block($head, 'ShipTo') ?>
            <?php if (trim((string) ($head['ShipToContact'] ?? '')) !== ''): ?><br>Attn: <?= e((string) $head['ShipToContact']) ?><?php endif; ?>
            <?php if (trim((string) ($head['ShipToPhone'] ?? '')) !== ''): ?><br><?= e((string) $head['ShipToPhone']) ?><?php endif; ?>
        </div>
        <div style="flex:1;min-width:14rem;">
            <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Bill to</div>
            <?= $block($head, 'BillTo') ?>
        </div>
        <table class="coa-meta" style="margin:0;">
            <tr><td>Order</td><td><strong><?= (int) $head['Ordr'] ?></strong></td></tr>
            <?php if (trim((string) ($head['PoNumber'] ?? '')) !== ''): ?><tr><td>PO number</td><td><strong><?= e((string) $head['PoNumber']) ?></strong></td></tr><?php endif; ?>
            <?php if ($head['Waybill'] !== null): ?><tr><td>Waybill</td><td><?= (int) $head['Waybill'] ?></td></tr><?php endif; ?>
            <?php if ($head['DateShipped']): ?><tr><td>Shipped</td><td><?= e(fmt_date($head['DateShipped'], 'F j, Y')) ?></td></tr><?php endif; ?>
            <?php if ($head['DateOrdered']): ?><tr><td>Ordered</td><td><?= e(fmt_date($head['DateOrdered'], 'F j, Y')) ?></td></tr><?php endif; ?>
            <?php if (trim((string) ($head['ShipViaName'] ?? '')) !== ''): ?><tr><td>Ship via</td><td><?= e((string) $head['ShipViaName']) ?></td></tr><?php endif; ?>
            <?php if (trim((string) ($head['Incoterms'] ?? '')) !== ''): ?><tr><td>Terms of delivery</td><td><?= e((string) $head['Incoterms']) ?></td></tr><?php endif; ?>
        </table>
    </div>

    <?php if ($lines === []): ?>
        <div class="alert alert-warn">No shipped lines are recorded for this shipment.</div>
    <?php else: ?>
        <table class="table coa-results">
            <thead>
                <tr><th>Item</th><th>Description</th><th class="text-right">Ordered</th><th class="text-right">Shipped</th><th>Unit</th><th>Container</th></tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <tr>
                    <td class="nowrap"><strong><?= e($l['ItemName'] !== '' ? $l['ItemName'] : $l['ItemCode']) ?></strong></td>
                    <td><?= e($l['Description']) ?>
                        <?php if ($l['Comment'] !== ''): ?><div class="text-muted" style="font-size:0.8rem;white-space:pre-wrap;"><?= e($l['Comment']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-right tabular"><?= fmt_number($l['QtyOrdered'], 2) ?></td>
                    <td class="text-right tabular"><strong><?= fmt_number($l['QtyShipped'], 2) ?></strong></td>
                    <td><?= e($l['Unit']) ?></td>
                    <td class="text-muted"><?= e(implode(', ', $l['Containers'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (trim((string) ($head['ShippingMemo'] ?? '')) !== ''): ?>
        <p style="margin-top:1rem;white-space:pre-wrap;"><?= e((string) $head['ShippingMemo']) ?></p>
    <?php endif; ?>
</div>

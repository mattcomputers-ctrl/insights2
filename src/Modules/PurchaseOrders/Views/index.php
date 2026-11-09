<?php
/**
 * Purchase order browser (live CMS).
 * @var string $q  @var string $tab  @var array $orders
 * @var bool $truncated  @var ?string $error  @var ?string $asOf
 */
$pill = function (?string $s, int $openLines): string {
    if ($s === 'CMP') return '<span class="pill pill-info">Received (CMP)</span>';
    if ($s === 'CLS') return '<span class="pill">Closed (CLS)</span>';
    return $openLines > 0
        ? '<span class="pill pill-active">Open</span>'
        : '<span class="pill pill-active">Open</span> <span class="pill">all lines received</span>';
};
$tabs = ['open' => 'Open', 'completed' => 'Completed', 'all' => 'All'];
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Purchase Orders</h2>
            <div class="card-subtitle">Live from CMS — lines, vendor codes and receipts.</div>
        </div>
        <div class="flex gap-1">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="/purchase-orders?tab=<?= e($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                   class="btn btn-sm <?= $tab === $key ? 'btn-primary' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <?php if (!empty($can['po.create_order'])): ?>
                <a href="/purchase-orders/new?clear=1" class="btn btn-sm btn-primary">New PO…</a>
            <?php endif; ?>
        </div>
        <form method="GET" action="/purchase-orders" class="flex gap-1">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="PO #, vendor, item, vendor code…" style="min-width:240px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($orders === []): ?>
        <div class="empty-state"><span class="empty-icon">📭</span><?= $q !== '' ? 'No POs match your search.' : 'No purchase orders in this tab.' ?></div>
    <?php else: ?>
        <?php if ($truncated): ?>
            <div class="alert alert-info">Showing the latest <?= count($orders) ?> POs — narrow the search for older ones.</div>
        <?php endif; ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>PO #</th><th>Status</th><th>Vendor</th>
                        <th>Ordered</th><th>Required</th>
                        <th class="text-right">Lines</th><th class="text-right">Open lines</th><th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><a href="/purchase-orders/<?= (int) $o['Ordr'] ?>"><strong><?= (int) $o['Ordr'] ?></strong></a></td>
                        <td class="nowrap"><?= $pill($o['Status'] !== null ? (string) $o['Status'] : null, (int) ($o['open_lines'] ?? 0)) ?></td>
                        <td><?= e((string) ($o['VendorName'] ?: $o['Vendor'] ?? '')) ?></td>
                        <td><?= e(fmt_date($o['DateOrdered'])) ?></td>
                        <td><?= e(fmt_date($o['DateRequired'])) ?></td>
                        <td class="text-right tabular"><?= (int) $o['line_count'] ?></td>
                        <td class="text-right tabular"><?= (int) ($o['open_lines'] ?? 0) ?></td>
                        <td class="text-right tabular"><?= $o['amount'] !== null ? fmt_money((float) $o['amount']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

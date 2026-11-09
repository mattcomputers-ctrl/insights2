<?php
/**
 * Coverage per item: who gets the stock.
 * @var array $rows  @var array $supply  @var array $allocByItem
 * @var string $search  @var ?string $asOf
 */
require __DIR__ . '/_subnav.php';
?>
<div class="card">
    <div class="toolbar">
        <h2 class="card-title">Coverage by item</h2>
        <form method="GET" action="/orders/by-item" class="flex gap-1">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Item code or description…" style="min-width:220px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-state"><span class="empty-icon">📭</span>No open demand<?= $search !== '' ? ' matching your search' : '' ?>.</div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th></th>
                    <th>Item</th>
                    <th>Description</th>
                    <th class="text-right">Open demand</th>
                    <th class="text-right">Available</th>
                    <th class="text-right">On tickets</th>
                    <th class="text-right">Orders</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
                $avail = (float) $r['available'];
                $demand = (float) $r['open_demand'];
                $tick = (float) ($supply[$r['item_code']] ?? 0);
                $coverClass = ($avail >= $demand) ? 'diff-good' : (($avail + $tick >= $demand) ? 'text-warn' : 'diff-bad');
            ?>
                <tr>
                    <td><button type="button" class="btn btn-sm item-expand" data-target="item-allocs-<?= $i ?>">▶</button></td>
                    <td class="nowrap"><?= item_ctx($r['item_code']) ?>
                        <?php if ((int) $r['is_discontinued'] === 1): ?><span class="pill pill-danger">DISCONTINUED</span><?php endif; ?>
                    </td>
                    <td><?= e((string) $r['description']) ?></td>
                    <td class="text-right tabular"><?= fmt_number($demand, 1) ?> <?= e((string) $r['unit']) ?></td>
                    <td class="text-right tabular <?= $coverClass ?>"><?= fmt_number($avail, 1) ?></td>
                    <td class="text-right tabular"><?= fmt_number($tick, 1) ?></td>
                    <td class="text-right"><?= (int) $r['order_count'] ?></td>
                    <td></td>
                </tr>
                <tr id="item-allocs-<?= $i ?>" style="display:none;">
                    <td colspan="8" style="background:var(--surface-2);">
                        <div class="children-wrap">
                            <strong style="font-size:0.85rem;">Who gets it (priority order):</strong>
                            <?php if (empty($allocByItem[$r['item_code']])): ?>
                                <span class="text-muted">No allocations.</span>
                            <?php else: ?>
                                <ul style="margin:0.3rem 0 0;padding-left:1.1rem;font-size:0.88rem;">
                                <?php foreach ($allocByItem[$r['item_code']] as $cr):
                                    $allocs = json_decode((string) $cr['allocations'], true) ?: [];
                                    $invQty = 0.0; $tktQty = 0.0;
                                    foreach ($allocs as $a) {
                                        if ($a['type'] === 'inventory') $invQty += $a['qty'];
                                        else $tktQty += $a['qty'];
                                    }
                                ?>
                                    <li>
                                        Order <strong><?= (int) $cr['ordr'] ?></strong> (<?= e((string) $cr['bill_to_name']) ?>) —
                                        needs <?= fmt_number((float) $cr['qty_remaining'], 1) ?>:
                                        <?= $invQty > 0 ? fmt_number($invQty, 1) . ' from stock' : '' ?>
                                        <?= $invQty > 0 && $tktQty > 0 ? ' + ' : '' ?>
                                        <?= $tktQty > 0 ? fmt_number($tktQty, 1) . ' from tickets' : '' ?>
                                        <?= $invQty + $tktQty == 0 ? '<span class="diff-bad">nothing — short</span>' : '' ?>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.item-expand');
    if (!btn) return;
    const row = document.getElementById(btn.dataset.target);
    const open = row.style.display === 'none';
    row.style.display = open ? '' : 'none';
    btn.textContent = open ? '▼' : '▶';
});
</script>

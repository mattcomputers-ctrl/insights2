<?php
/**
 * Assemble orders: pick a ready sales order, then move stock for its
 * lines into shipping containers, or take it back out (live CMS).
 * @var array $open  @var ?array $order  @var array $lines  @var array $boxes
 * @var ?string $problem  @var ?string $error  @var bool $canShip  @var string $mode
 */
$n = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/shipping" class="btn btn-sm">← Shipping</a>
    <?php if ($order !== null): ?><a href="/shipping/assemble" class="btn btn-sm">Choose a different order</a><?php endif; ?>
    <?php if ($canShip): ?><a href="/shipping/new" class="btn btn-sm">Ship orders</a><?php endif; ?>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
    <?php return; ?>
<?php endif; ?>

<?php if ($order === null): ?>
<div class="card">
    <h2 class="card-title">Assemble orders</h2>
    <div class="card-subtitle">Sales orders that are ready to ship and still have open lines, live from CMS. Open one to pick its stock into containers.</div>
    <?php if ($problem !== null): ?>
        <div class="alert alert-danger"><?= e($problem) ?></div>
    <?php endif; ?>
    <form method="GET" action="/shipping/assemble" class="form-row" style="margin-bottom:0.75rem;">
        <div class="form-group">
            <label for="asm-order">Order number</label>
            <input type="text" inputmode="numeric" id="asm-order" name="order" style="width:9rem;">
        </div>
        <div class="form-group" style="align-self:flex-end;">
            <button type="submit" class="btn btn-sm btn-primary">Open</button>
        </div>
    </form>
    <?php if ($open === []): ?>
        <div class="empty-state"><span class="empty-icon">📦</span>No orders are waiting to be assembled.</div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Order</th><th>Ship to</th><th>PO #</th><th>Required</th><th class="text-right">Open lines</th></tr></thead>
            <tbody>
            <?php foreach ($open as $r): ?>
                <tr>
                    <td><a href="/shipping/assemble?order=<?= (int) $r['Ordr'] ?>"><strong><?= (int) $r['Ordr'] ?></strong></a></td>
                    <td><?= e($r['ShipToName']) ?></td>
                    <td class="text-muted"><?= e($r['PoNumber']) ?></td>
                    <td class="nowrap"><?= $r['DateRequired'] !== '' ? e(fmt_date($r['DateRequired'])) : '' ?></td>
                    <td class="text-right tabular"><?= (int) $r['Lines'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php return; ?>
<?php endif; ?>

<?php
$id       = (int) $order['Ordr'];
$pickable = array_filter($lines, static fn (array $l): bool => $l['isOpen'] && $l['left'] > 0);
?>
<div class="card">
    <h2 class="card-title">
        Assemble order <?php if (user_can('sales.view')): ?><a href="/sales-orders/<?= $id ?>"><?= $id ?></a><?php else: ?><?= $id ?><?php endif; ?>
    </h2>
    <div class="card-subtitle">
        <?= e($order['ShipToName']) ?><?= ($order['PoNumber'] ?? '') !== '' ? ' · PO ' . e($order['PoNumber']) : '' ?>.
        Enter what was picked for each line, in the item's stock unit. The stock is reserved to the order and waits in the container for a waybill.
    </div>
    <form method="POST" action="/shipping/assemble">
        <?= csrf_field() ?>
        <input type="hidden" name="order" value="<?= $id ?>">
        <div class="table-scroll">
            <table class="table">
                <thead><tr>
                    <th>Item</th><th>Description</th><th class="text-right">Ordered</th><th class="text-right">Shipped</th>
                    <th class="text-right">In containers</th><th class="text-right">Left</th><th class="text-right">In stock</th><th class="text-right">Pick now</th>
                </tr></thead>
                <tbody>
                <?php foreach ($lines as $l): ?>
                    <tr>
                        <td class="nowrap"><strong><?= e($l['code']) ?></strong></td>
                        <td style="font-size:0.85rem;"><?= e($l['description']) ?></td>
                        <td class="text-right tabular"><?= e($n($l['qty'])) ?> <?= e($l['unit']) ?></td>
                        <td class="text-right tabular"><?= e($n($l['shipped'])) ?></td>
                        <td class="text-right tabular"><?= e($n($l['assembled'])) ?></td>
                        <td class="text-right tabular"><?= e($n($l['left'])) ?></td>
                        <?php if (!$l['isOpen']): ?>
                            <td class="text-right text-muted" colspan="2">Line closed</td>
                        <?php elseif ($l['left'] <= 0): ?>
                            <td class="text-right text-muted" colspan="2">Fully assembled</td>
                        <?php else: ?>
                            <td class="text-right tabular<?= $l['onHand'] + 1e-9 < $l['left'] ? ' text-danger' : '' ?>"><?= e($n($l['onHand'])) ?></td>
                            <td class="text-right">
                                <input type="text" inputmode="decimal" name="qty[<?= (int) $l['ordDetail'] ?>]"
                                       value="<?= $l['onHand'] + 1e-9 >= $l['left'] ? e(rtrim(rtrim(number_format($l['left'], 4, '.', ''), '0'), '.')) : '' ?>"
                                       style="width:7rem;text-align:right;" aria-label="Quantity of <?= e($l['code']) ?> to pick">
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pickable === []): ?>
            <p class="text-muted" style="margin:0.75rem 0 0;">Nothing is left to assemble on this order.</p>
        <?php else: ?>
        <div class="form-row" style="margin-top:0.75rem;">
            <div class="form-group">
                <label for="asm-into">Into</label>
                <select id="asm-into" name="container">
                    <option value="new">A new container</option>
                    <?php foreach ($boxes as $b): ?>
                        <option value="<?= (int) $b['location'] ?>">Container <?= e($b['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button type="submit" class="btn btn-primary">Review and assemble…</button>
            </div>
        </div>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Containers for this order</h2>
            <div class="card-subtitle">Assembled and waiting for a waybill.</div>
        </div>
        <?php if ($canShip && $boxes !== []): ?>
            <a href="/shipping/new?orders=<?= $id ?>" class="btn btn-sm btn-primary">Ship this order</a>
        <?php endif; ?>
    </div>
    <?php if ($boxes === []): ?>
        <div class="empty-state"><span class="empty-icon">📦</span>Nothing is assembled for this order yet.</div>
    <?php endif; ?>
    <?php foreach ($boxes as $b): ?>
        <h3 style="margin:0.9rem 0 0.4rem;">Container <?= e($b['code']) ?></h3>
        <?php if ($b['inside'] === []): ?>
            <p class="text-muted" style="margin:0;">Empty.</p>
            <?php continue; ?>
        <?php endif; ?>
        <form method="POST" action="/shipping/assemble/remove">
            <?= csrf_field() ?>
            <input type="hidden" name="order" value="<?= $id ?>">
            <input type="hidden" name="container" value="<?= (int) $b['location'] ?>">
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Item</th><th class="text-right">In the container</th><th class="text-right">Take out</th></tr></thead>
                    <tbody>
                    <?php foreach ($b['inside'] as $in): ?>
                        <tr>
                            <td class="nowrap"><strong><?= e($in['code']) ?></strong></td>
                            <td class="text-right tabular"><?= e($n($in['qty'])) ?> <?= e($in['unit']) ?></td>
                            <td class="text-right">
                                <input type="text" inputmode="decimal" name="qty[<?= (int) $in['ordDetail'] ?>]" value=""
                                       style="width:7rem;text-align:right;" aria-label="Quantity of <?= e($in['code']) ?> to take out">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:0.5rem;">
                <button type="submit" class="btn btn-sm">Take out the quantities entered…</button>
                <button type="submit" name="all" value="1" class="btn btn-sm">Take out everything…</button>
            </div>
        </form>
    <?php endforeach; ?>
</div>

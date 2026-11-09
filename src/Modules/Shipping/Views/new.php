<?php
/**
 * Ship orders: pick orders that have something assembled, check the
 * containers and weights, then confirm (live CMS).
 * @var array $ready  @var array $unfinished  @var array $chosen  @var int[] $picked
 * @var ?string $problem  @var ?string $error  @var int $maxOrders  @var string $mode
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/shipping" class="btn btn-sm">← Shipping</a>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
    <?php return; ?>
<?php endif; ?>

<?php if ($unfinished !== []): ?>
<div class="card">
    <h2 class="card-title">Waybills that did not finish</h2>
    <div class="card-subtitle">These were started and stopped part way. Finish them before shipping more.</div>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Waybill</th><th class="text-right">Steps left</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($unfinished as $u): ?>
                <tr>
                    <td><a href="/shipping/<?= (int) $u['Waybill'] ?>"><strong><?= (int) $u['Waybill'] ?></strong></a></td>
                    <td class="text-right tabular"><?= (int) $u['Open'] ?></td>
                    <td class="text-right">
                        <form method="POST" action="/shipping/<?= (int) $u['Waybill'] ?>/finish" style="display:inline;">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-primary">Finish</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($chosen !== []): ?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Ship <?= count($chosen) === 1 ? 'order ' . (int) $chosen[0]['Ordr'] : count($chosen) . ' orders' ?> on a new waybill</h2>
            <div class="card-subtitle">Everything assembled for these orders ships and is invoiced. Check the weights, then continue to the confirm step.</div>
        </div>
        <a href="/shipping/new" class="btn btn-sm">Choose different orders</a>
    </div>
    <form method="POST" action="/shipping/new">
        <?= csrf_field() ?>
        <?php foreach ($chosen as $o): ?>
            <input type="hidden" name="orders[]" value="<?= (int) $o['Ordr'] ?>">
            <h3 style="margin:0.9rem 0 0.4rem;">
                Order <?php if (user_can('sales.view')): ?><a href="/sales-orders/<?= (int) $o['Ordr'] ?>"><?= (int) $o['Ordr'] ?></a><?php else: ?><?= (int) $o['Ordr'] ?><?php endif; ?>
                <span class="text-muted" style="font-weight:400;">· <?= e($o['ShipToName']) ?><?= $o['PoNumber'] !== '' ? ' · PO ' . e($o['PoNumber']) : '' ?></span>
            </h3>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Container</th><th>Contents</th><th class="text-right">Weight (lb)</th></tr></thead>
                    <tbody>
                    <?php foreach ($o['containers'] as $c): ?>
                        <tr>
                            <td class="nowrap"><strong><?= e($c['code']) ?></strong></td>
                            <td style="font-size:0.85rem;"><?= e(implode(', ', $c['contents'])) ?></td>
                            <td class="text-right">
                                <input type="text" inputmode="decimal" name="weights[<?= (int) $c['location'] ?>]"
                                       value="<?= e(rtrim(rtrim(number_format((float) $c['lb'], 4, '.', ''), '0'), '.')) ?>"
                                       style="width:7rem;text-align:right;" aria-label="Weight of <?= e($c['code']) ?> in pounds">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <label style="display:block;margin:0.4rem 0 0;">
                Freight charge to invoice on order <?= (int) $o['Ordr'] ?> (optional)
                <input type="text" inputmode="decimal" name="freight[<?= (int) $o['Ordr'] ?>]" value="" placeholder="0.00" style="width:8rem;margin-left:0.5rem;">
            </label>
        <?php endforeach; ?>
        <div style="margin-top:1rem;">
            <button type="submit" class="btn btn-primary">Review and ship</button>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <h2 class="card-title">Ship orders</h2>
    <div class="card-subtitle">
        Orders with something assembled and waiting to ship, live from CMS. Tick the orders that go on one waybill
        (same ship-to, up to <?= (int) $maxOrders ?>).
    </div>
    <?php if ($problem !== null): ?>
        <div class="alert alert-danger"><?= e($problem) ?></div>
    <?php endif; ?>
    <?php if ($ready === []): ?>
        <div class="empty-state"><span class="empty-icon">📦</span>Nothing is assembled and waiting to ship.</div>
    <?php else: ?>
    <form method="GET" action="/shipping/new">
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th></th><th>Order</th><th>Ship to</th><th>PO #</th><th>Required</th><th class="text-right">Containers</th></tr></thead>
                <tbody>
                <?php foreach ($ready as $r): ?>
                    <tr>
                        <td><input type="checkbox" name="orders[]" value="<?= (int) $r['Ordr'] ?>" id="ship-<?= (int) $r['Ordr'] ?>"
                                   <?= in_array((int) $r['Ordr'], $picked, true) ? 'checked' : '' ?>></td>
                        <td><label for="ship-<?= (int) $r['Ordr'] ?>"><strong><?= (int) $r['Ordr'] ?></strong></label></td>
                        <td><?= e($r['ShipToName']) ?></td>
                        <td class="text-muted"><?= e($r['PoNumber']) ?></td>
                        <td class="nowrap"><?= $r['DateRequired'] !== '' ? e(fmt_date($r['DateRequired'])) : '' ?></td>
                        <td class="text-right tabular"><?= (int) $r['Containers'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:0.75rem;">
            <button type="submit" class="btn btn-primary">Continue</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
/**
 * Need to Order — the buy list, two lenses (Matt, 2026-11-02):
 *   view=orders  what's needed to complete orders & open tickets
 *   view=min     minimum stock triggered on PROJECTED stock
 * Flat (priority order) or grouped by preferred / last-purchased vendor.
 * @var array $rows  @var array $groups  @var string $groupBy  ''|'preferred'|'last'
 * @var string $view  'orders'|'min'  @var string $search  @var ?string $asOf
 * @var bool $canCreatePo  the user may raise purchase orders
 */
$today = date('Y-m-d');
$isMin = $view === 'min';
// $extra FIRST: PHP's + keeps the left side on key collisions, so the
// override must come first or toggle links silently keep the old value.
$qs = fn(array $extra) => '/need-to-order?' . http_build_query(array_filter(
    $extra + ['view' => $isMin ? 'min' : '', 'q' => $search, 'group' => $groupBy], fn($v) => $v !== ''));

$renderRows = function (array $list) use ($today, $isMin): void {
    foreach ($list as $r):
        $overdue = $r['first_needed'] !== null && $r['first_needed'] < $today;
    ?>
        <tr>
            <td class="nowrap"><?= item_ctx($r['item_code']) ?>
                <?php if ((int) $r['has_recipe'] === 1): ?>
                    <div class="tag" title="This item has a recipe — it can also be made in-house instead of bought">can be made</div>
                <?php endif; ?>
            </td>
            <td><?= e($r['description']) ?></td>
            <td class="nowrap">
                <?php if (!empty($r['preferred_vendor'])): ?>
                    <?= e($r['preferred_vendor']) ?>
                <?php else: ?>
                    <span class="text-muted" title="No preferred vendor set on the item in CMS">—</span>
                <?php endif; ?>
            </td>
            <td class="nowrap">
                <?php if ($r['vendor'] !== null && $r['vendor'] !== ''): ?>
                    <?= e($r['vendor']) ?>
                    <?php if ($r['vendor_code']): ?><div class="tag" title="Their item code on the last PO"><?= e($r['vendor_code']) ?></div><?php endif; ?>
                    <?php if ((int) $r['vendor_count'] > 1): ?><div class="text-muted" style="font-size:0.78rem;"><?= (int) $r['vendor_count'] ?> vendors in history</div><?php endif; ?>
                <?php else: ?>
                    <span class="text-muted" title="Never purchased — no vendor on file">—</span>
                <?php endif; ?>
            </td>
            <td class="text-right tabular text-danger"><strong><?= fmt_number((float) $r['qty_needed'], 1) ?></strong> <?= e($r['unit']) ?></td>
            <?php if ($isMin): ?>
                <td class="text-right tabular"><?= fmt_number((float) $r['minimum_stock'], 1) ?></td>
                <td class="text-right tabular"><?= fmt_number((float) $r['on_hand'], 1) ?></td>
                <td class="text-right tabular <?= (float) $r['projected'] < 0 ? 'text-danger' : '' ?>"
                    title="On hand minus everything open orders and open tickets will consume"><?= fmt_number((float) $r['projected'], 1) ?></td>
            <?php endif; ?>
            <td class="text-right tabular"><?= (float) $r['on_po'] > 0 ? fmt_number((float) $r['on_po'], 1) : '<span class="text-muted">—</span>' ?></td>
            <td class="<?= $overdue ? 'text-danger' : '' ?>"><?= e(fmt_date($r['first_needed'])) ?><?= $overdue ? ' ⚠' : '' ?></td>
            <td class="nowrap">
                <?php if ((int) $r['src_ticket'] === 1): ?><span class="pill pill-danger" title="An open batch ticket is waiting on this">ticket</span><?php endif; ?>
                <?php if ((int) $r['src_order'] === 1): ?><span class="pill pill-info" title="A batch still to be cut for an open sales order needs this">new batch</span><?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:0.85rem;">
                <?php foreach (array_slice($r['refs_list'], 0, 6) as $ref): ?>
                    <div class="nowrap"><?= e($ref['ref']) ?></div>
                <?php endforeach; ?>
                <?php if (count($r['refs_list']) > 6): ?>
                    <div>… and <?= count($r['refs_list']) - 6 ?> more</div>
                <?php endif; ?>
                <?php if ($r['via_list'] !== []): ?>
                    <div title="This material is consumed by making these in-house items">to make <?= e(implode(' → ', $r['via_list'])) ?></div>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach;
};
$thead = '<tr>'
    . '<th>Material</th><th>Description</th><th>Preferred vendor</th><th>Last purchased from</th>'
    . '<th class="text-right">To order</th>'
    . ($isMin ? '<th class="text-right">Min</th><th class="text-right">On hand</th><th class="text-right">Projected</th>' : '')
    . '<th class="text-right">On open POs</th>'
    . '<th>First needed</th><th>Why</th><th>Needed by</th></tr>';
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Need to Order</h2>
            <div class="card-subtitle">
                <?php if ($isMin): ?>
                    Minimum stock, forward-looking: on-hand minus everything open orders and open
                    batch tickets will consume — anything landing below its minimum is topped back UP to minimum.
                    What's already on an open PO counts as on its way; only the remaining gap is listed.
                <?php else: ?>
                    Buy this and every open ticket — and every batch still to be cut for open orders — can run.
                    Netted against stock, open tickets and open POs; nothing is counted twice.
                <?php endif; ?>
                <?= $asOf !== null ? 'Data as of ' . e(fmt_date($asOf, 'M j, g:i A')) . '.' : '' ?>
            </div>
            <div class="flex gap-1" style="margin-top:0.5rem;">
                <a href="<?= e($qs(['view' => ''])) ?>" class="btn btn-sm <?= !$isMin ? 'btn-primary' : '' ?>">To complete orders</a>
                <a href="<?= e($qs(['view' => 'min'])) ?>" class="btn btn-sm <?= $isMin ? 'btn-primary' : '' ?>">Minimum stock</a>
            </div>
        </div>
        <form method="GET" action="/need-to-order" class="flex gap-1">
            <?php if ($isMin): ?><input type="hidden" name="view" value="min"><?php endif; ?>
            <?php if ($groupBy !== ''): ?><input type="hidden" name="group" value="<?= e($groupBy) ?>"><?php endif; ?>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Material, vendor or vendor code…" style="min-width:220px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
        <div class="flex gap-1 items-center">
            <a href="<?= e($qs(['group' => $groupBy === 'preferred' ? '' : 'preferred'])) ?>"
               class="btn btn-sm <?= $groupBy === 'preferred' ? 'btn-primary' : '' ?>"><?= $groupBy === 'preferred' ? '✔ ' : '' ?>By preferred vendor</a>
            <a href="<?= e($qs(['group' => $groupBy === 'last' ? '' : 'last'])) ?>"
               class="btn btn-sm <?= $groupBy === 'last' ? 'btn-primary' : '' ?>"><?= $groupBy === 'last' ? '✔ ' : '' ?>By last vendor</a>
            <?php if (user_can('export')): ?>
                <a href="<?= e('/need-to-order/export?' . http_build_query(array_filter(['view' => $isMin ? 'min' : '', 'q' => $search, 'group' => $groupBy]))) ?>"
                   class="btn btn-sm" data-export="1">⬇ Excel</a>
            <?php endif; ?>
            <?= sync_now_button() ?>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-state">
            <span class="empty-icon">✅</span>
            <?= $search !== ''
                ? 'Nothing on the buy list matches your search.'
                : ($asOf === null ? 'No data yet — run a sync.'
                    : ($isMin ? 'Nothing triggered — every item stays at or above its minimum even after completing everything open.'
                        : 'Nothing to order — every open ticket and every pending batch is covered.')) ?>
        </div>
    <?php elseif ($groupBy !== ''): ?>
        <div style="margin:0.5rem 0;">
            <button type="button" class="btn btn-sm" id="nto-toggle-all" data-state="closed">Expand all</button>
        </div>
        <?php foreach ($groups as $vendor => $list):
            $earliest = null;
            foreach ($list as $r) {
                if ($r['first_needed'] !== null && ($earliest === null || $r['first_needed'] < $earliest)) {
                    $earliest = $r['first_needed'];
                }
            }
        ?>
            <details class="vendor-group" <?= $search !== '' ? 'open' : '' ?>>
                <summary>
                    <strong><?= $vendor === ''
                        ? ($groupBy === 'preferred' ? 'No preferred vendor set' : 'Never purchased')
                        : e($vendor) ?></strong>
                    <span class="text-muted" style="font-size:0.85rem;">
                        <?= count($list) ?> item<?= count($list) === 1 ? '' : 's' ?>
                        <?php if ($earliest !== null): ?>
                            · first needed <span class="<?= $earliest < $today ? 'text-danger' : '' ?>"><?= e(fmt_date($earliest)) ?><?= $earliest < $today ? ' ⚠' : '' ?></span>
                        <?php endif; ?>
                    </span>
                </summary>
                <?php if ($canCreatePo && $vendor !== ''): ?>
                    <form method="POST" action="/purchase-orders/from-need-to-order" style="margin:0.5rem 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="vendor" value="<?= e((string) $vendor) ?>">
                        <?php foreach (array_values($list) as $n => $r): ?>
                            <input type="hidden" name="items[<?= (int) $n ?>][code]" value="<?= e($r['item_code']) ?>">
                            <input type="hidden" name="items[<?= (int) $n ?>][qty]" value="<?= e((string) (float) $r['qty_needed']) ?>">
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-sm btn-primary">Start a PO for these <?= count($list) ?> item<?= count($list) === 1 ? '' : 's' ?>…</button>
                        <span class="text-muted" style="font-size:0.85rem;">Opens a filled-in PO form to check; nothing is sent yet.</span>
                    </form>
                <?php endif; ?>
                <div class="table-scroll">
                    <table class="table">
                        <thead><?= $thead ?></thead>
                        <tbody><?php $renderRows($list); ?></tbody>
                    </table>
                </div>
            </details>
        <?php endforeach; ?>
        <script>
        (function () {
            'use strict';
            var btn = document.getElementById('nto-toggle-all');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var open = btn.dataset.state === 'closed';
                document.querySelectorAll('details.vendor-group').forEach(function (d) { d.open = open; });
                btn.dataset.state = open ? 'open' : 'closed';
                btn.textContent = open ? 'Collapse all' : 'Expand all';
            });
        })();
        </script>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><?= $thead ?></thead>
                <tbody><?php $renderRows($rows); ?></tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

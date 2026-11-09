<?php
/**
 * Inventory Browser. Only permitted columns are rendered (and were queried).
 * @var array $rows  @var array $columns  @var array $vendorCodes
 * @var string $search  @var bool $showAll
 * @var int $total  @var ?string $asOf
 */
// $extra FIRST: PHP's + keeps the left side on key collisions, so the
// override must come first or toggle links silently keep the old value.
$qs = fn(array $extra) => '/inventory?' . http_build_query(array_filter($extra + [
    'q' => $search, 'all' => $showAll ? '1' : '', ], fn($v) => $v !== ''));
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <form method="GET" action="/inventory" class="flex gap-1" style="flex:1;min-width:280px;max-width:560px;">
        <input type="text" name="q" value="<?= e($search) ?>" style="flex:1;"
               placeholder="Item code, description<?= isset($columns['vendor_codes']) ? ', vendor code or vendor' : '' ?>…">
        <?php if ($showAll): ?><input type="hidden" name="all" value="1"><?php endif; ?>
        <button type="submit" class="btn">Search</button>
    </form>
    <div class="flex gap-1 items-center">
        <label style="display:inline-flex;gap:0.35rem;align-items:center;cursor:pointer;font-size:0.9rem;">
            <input type="checkbox" onchange="location.href='<?= e($qs(['all' => $showAll ? '' : '1'])) ?>'" <?= $showAll ? 'checked' : '' ?>>
            Include items with no stock or activity
        </label>
        <?php if (user_can('export')): ?>
            <a href="<?= e('/inventory/export?' . http_build_query(array_filter(['q' => $search, 'all' => $showAll ? '1' : '']))) ?>"
               class="btn btn-sm" data-export="1">⬇ Excel</a>
        <?php endif; ?>
        <?php if (user_can('write.inv.count') || user_can('write.inv.count_post')): ?>
            <a href="/inventory/counts" class="btn btn-sm">Counts</a>
        <?php endif; ?>
        <?php if (user_can('write.inv.reset_label')): ?>
            <a href="/inventory/labels" class="btn btn-sm">Labels waiting</a>
        <?php endif; ?>
        <?= sync_now_button() ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= fmt_number($total) ?> item<?= $total === 1 ? '' : 's' ?></h2>
            <div class="card-subtitle">
                <?= $asOf !== null ? 'Data as of ' . e(fmt_date($asOf, 'M j, g:i A')) : 'Waiting for the first sync' ?>
            </div>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-state">
            <span class="empty-icon">🗃</span>
            <?= $search !== '' ? 'Nothing matches your search.' : ($asOf === null ? 'No data yet — run a sync.' : 'Nothing to show.') ?>
        </div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Description</th>
                    <th>Unit</th>
                    <?php foreach ($columns as $key => $col): ?>
                        <th class="<?= $key === 'vendor_codes' ? '' : 'text-right' ?>"><?= e($col[1]) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><?= item_ctx($r['item_code']) ?></td>
                    <td><?= e($r['description']) ?></td>
                    <td class="text-muted"><?= e($r['unit']) ?></td>
                    <?php foreach ($columns as $key => $col): ?>
                        <?php if ($key === 'vendor_codes'): ?>
                            <td style="font-size:0.85rem;">
                                <?php foreach ($vendorCodes[$r['item_code']] ?? [] as $v): ?>
                                    <div class="nowrap" title="<?= e($v['po_count']) ?> PO<?= (int) $v['po_count'] === 1 ? '' : 's' ?>, last <?= e(fmt_date($v['last_po'])) ?>">
                                        <strong><?= e($v['vendor_code']) ?></strong>
                                        <span class="text-muted">— <?= e($v['vendor']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        <?php else: ?>
                            <td class="text-right tabular"><?= (float) $r[$col[2]] != 0.0 ? fmt_number((float) $r[$col[2]], 1) : '<span class="text-muted">—</span>' ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

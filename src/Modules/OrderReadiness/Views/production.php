<?php
/**
 * Production plan (§8.3) — print-friendly for the production meeting.
 * @var array $rows  @var array $badges  @var bool $canMark
 * @var string $search  @var ?string $asOf
 */
require __DIR__ . '/_subnav.php';

$sevPill = fn(string $s) => match ($s) {
    'negative' => '<span class="pill pill-danger">⚠ Negative</span>',
    'below'    => '<span class="pill pill-danger">⚠ Below minimum</span>',
    'near'     => '<span class="pill pill-warn">⚠ Near minimum</span>',
    default    => e($s),
};
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Production plan</h2>
            <div class="card-subtitle">
                Items with a recipe whose projected stock (on hand + open tickets − open orders)
                is near or below their minimum. Discontinued items never appear here.
            </div>
        </div>
        <form method="GET" action="/orders/production" class="flex gap-1">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Item, description or recipe…" style="min-width:220px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
        <div class="flex gap-1">
            <button type="button" class="btn btn-sm" onclick="window.print()">🖨 Print</button>
            <?php if (user_can('export')): ?>
                <a href="/orders/export/production" class="btn btn-sm" data-export="1">⬇ Excel</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-state"><span class="empty-icon">🎉</span><?= $search !== '' ? 'Nothing on the plan matches your search.' : 'Nothing needs making — everything is above minimum.' ?></div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th><th>Description</th><th>Severity</th>
                    <th class="text-right">Min</th><th class="text-right">On hand</th>
                    <th class="text-right">On tickets</th><th class="text-right">Open demand</th>
                    <th class="text-right">Projected</th><th class="text-right">Suggested</th>
                    <th>Recipe</th><th>Last made</th>
                    <?php if ($canMark): ?><th>Mark</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $skipActive = $r['mark'] === 'skip' && ($r['skip_until'] === null || $r['skip_until'] >= date('Y-m-d'));
            ?>
                <tr style="<?= $skipActive ? 'opacity:0.55;' : '' ?>">
                    <td class="nowrap"><?= item_ctx($r['item_code']) ?>
                        <?php if (($badges[$r['item_code']] ?? '') === 'reactivated'): ?>
                            <span class="pill pill-info" title="Recently taken off discontinued (minimum moved off 99)">Reactivated</span>
                        <?php elseif (($badges[$r['item_code']] ?? '') === 'newly_discontinued'): ?>
                            <span class="pill pill-danger">Newly discontinued</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($r['description'] ?? '')) ?></td>
                    <td class="nowrap"><?= $sevPill($r['severity']) ?>
                        <?php if (!empty($r['open_ticket'])): ?>
                            <span class="pill pill-info" title="An open batch ticket already exists for this item — check it before cutting another.">⏳ ticket <?= (int) $r['open_ticket'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right tabular"><?= $r['minimum_stock'] !== null ? fmt_number((float) $r['minimum_stock'], 0) : '—' ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $r['on_hand'], 1) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $r['on_tickets'], 1) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $r['open_demand'], 1) ?></td>
                    <td class="text-right tabular <?= (float) $r['projected'] < 0 ? 'diff-bad' : '' ?>"><?= fmt_number((float) $r['projected'], 1) ?></td>
                    <td class="text-right tabular"><strong><?= fmt_number((float) $r['suggested_qty'], 0) ?></strong></td>
                    <td class="text-muted"><?= e((string) ($r['recipe_number'] ?? '')) ?></td>
                    <td class="text-muted nowrap"><?= e(fmt_date($r['last_made'])) ?></td>
                    <?php if ($canMark): ?>
                    <td class="nowrap">
                        <?php if ($r['mark'] !== null): ?>
                            <span class="pill <?= $r['mark'] === 'scheduled' ? 'pill-active' : '' ?>"
                                  title="<?= e(trim(($r['marked_by'] ?? '') . ' ' . ($r['mark_note'] ?? ''))) ?>">
                                <?= $r['mark'] === 'scheduled' ? '✔ Scheduled' : '⏭ Skip' . ($r['skip_until'] ? ' until ' . fmt_date($r['skip_until']) : '') ?>
                            </span>
                            <form method="POST" action="/orders/production/mark" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="item_code" value="<?= e($r['item_code']) ?>">
                                <input type="hidden" name="mark" value="clear">
                                <button type="submit" class="btn btn-sm" title="Clear mark">×</button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm mark-open" data-item="<?= e($r['item_code']) ?>">Mark…</button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($canMark): ?>
<div class="modal-backdrop" id="mark-modal" style="display:none;">
    <div class="modal">
        <h2 class="card-title">Mark <span id="mark-item-label"></span></h2>
        <form method="POST" action="/orders/production/mark">
            <?= csrf_field() ?>
            <input type="hidden" name="item_code" id="mark-item-code">
            <div class="form-group">
                <label style="display:flex;gap:0.4rem;align-items:center;cursor:pointer;">
                    <input type="radio" name="mark" value="scheduled" checked>
                    Scheduled — a batch is planned (clears automatically when the ticket appears in CMS)
                </label>
                <label style="display:flex;gap:0.4rem;align-items:center;cursor:pointer;">
                    <input type="radio" name="mark" value="skip">
                    Skip until <input type="date" name="skip_until" style="min-height:36px;">
                </label>
            </div>
            <div class="form-group">
                <label>Note <span class="text-muted">(visible to everyone)</span></label>
                <input type="text" name="note" maxlength="255" placeholder="e.g. waiting on pigment delivery">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn" id="mark-cancel">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('click', (e) => {
    const open = e.target.closest('.mark-open');
    if (open) {
        document.getElementById('mark-item-code').value = open.dataset.item;
        document.getElementById('mark-item-label').textContent = open.dataset.item;
        document.getElementById('mark-modal').style.display = '';
    }
    if (e.target.id === 'mark-cancel' || e.target.id === 'mark-modal') {
        document.getElementById('mark-modal').style.display = 'none';
    }
});
</script>
<?php endif; ?>

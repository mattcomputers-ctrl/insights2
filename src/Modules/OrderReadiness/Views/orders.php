<?php
/**
 * @var string $tab  @var string $search  @var array $orders
 * @var array $tabCounts  @var array $noteCounts  @var ?string $asOf
 * @var bool $canReorder
 * @var bool $canRelease  @var bool $canShip  may send a ready order on to CMS from this list
 * @var string $mode
 */
require __DIR__ . '/_subnav.php';

$statusPill = function (string $s): string {
    return match ($s) {
        'ready'              => '<span class="pill pill-active">✔ Ready to ship</span>',
        'waiting'            => '<span class="pill pill-info">⏳ Waiting on batch</span>',
        'short'              => '<span class="pill pill-danger">⚠ Short</span>',
        'short_discontinued' => '<span class="pill pill-danger">⚠ Short</span> <span class="pill pill-danger">DISCONTINUED</span>',
        default              => e($s),
    };
};
$today = date('Y-m-d');
$tabs = [
    'ready'        => 'Ready to ship (' . $tabCounts['ready'] . ')',
    'waiting'      => 'Waiting on batches (' . $tabCounts['waiting'] . ')',
    'short'        => 'Short (' . $tabCounts['short'] . ')',
    'discontinued' => 'Discontinued (' . $tabCounts['discontinued'] . ')',
    'all'          => 'All (' . $tabCounts['all'] . ')',
];
if ($canReorder) {
    $tabs['priority'] = 'Priority order';
}
?>
<div class="card">
    <div class="toolbar">
        <div class="flex gap-1" style="flex-wrap:wrap;">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="/orders/list?tab=<?= e($key) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
                   class="btn btn-sm <?= $tab === $key ? 'btn-primary' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" action="/orders/list" class="flex gap-1">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Order #, customer, item…" style="min-width:220px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
        <?php if (user_can('export')): ?>
            <a href="/orders/export/orders" class="btn btn-sm" data-export="1">⬇ Excel</a>
        <?php endif; ?>
    </div>

    <?php if (($canRelease || $canShip) && $mode !== 'live' && $tab !== 'priority'): ?>
        <div class="alert alert-info">Practice mode: releasing or completing an order here is checked and previewed, but nothing is sent to CMS.</div>
    <?php endif; ?>

    <?php if ($tab === 'priority'): ?>
        <p class="text-muted" style="font-size:0.9rem;">
            Stock and batch output are given to orders from the top of this list down.
            Drag rows (or use the arrows) to change who gets covered first — everything recalculates when you save.
        </p>
    <?php endif; ?>

    <?php if ($orders === []): ?>
        <div class="empty-state">
            <span class="empty-icon">📭</span>
            <?= $search !== '' ? 'No orders match your search.' : 'Nothing in this tab right now.' ?>
        </div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table" id="orders-table">
            <thead>
                <tr>
                    <?php if ($tab === 'priority'): ?><th style="width:110px;">Priority</th><?php endif; ?>
                    <th></th>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Ship to</th>
                    <th>Required</th>
                    <th class="text-right">Lines</th>
                    <th>Status</th>
                    <th>Why</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o):
                $overdue = $o['date_required'] !== null && $o['date_required'] < $today && $o['status'] !== 'ready';
            ?>
                <tr class="order-row" data-ordr="<?= (int) $o['ordr'] ?>" <?= $tab === 'priority' && $canReorder ? 'draggable="true"' : '' ?>>
                    <?php if ($tab === 'priority'): ?>
                        <td class="nowrap">
                            <span class="tag">#<?= (int) $o['position'] ?></span>
                            <?php if ($canReorder): ?>
                                <button type="button" class="btn btn-sm prio-up" title="Move up">↑</button>
                                <button type="button" class="btn btn-sm prio-down" title="Move down">↓</button>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td>
                        <button type="button" class="btn btn-sm order-expand" data-ordr="<?= (int) $o['ordr'] ?>">▶</button>
                    </td>
                    <td><strong><?= (int) $o['ordr'] ?></strong>
                        <?php if ($o['status_code'] === 'CSH'): ?><span class="pill pill-warn" title="Open, on credit hold">Credit hold (CSH)</span><?php endif; ?>
                    </td>
                    <td><?= e($o['bill_to_name'] ?: $o['bill_to']) ?></td>
                    <td class="text-muted"><?= e($o['ship_to_name']) ?></td>
                    <td class="<?= $overdue ? 'text-danger' : '' ?>">
                        <?= e(fmt_date($o['date_required'])) ?><?= $overdue ? ' ⚠ overdue' : '' ?>
                    </td>
                    <td class="text-right"><?= (int) $o['line_count'] ?></td>
                    <td><?= $statusPill($o['status']) ?>
                        <?php if ((int) ($o['is_backorder'] ?? 0) === 1): ?>
                            <span class="pill pill-warn" title="Open with some material already shipped">Backorder</span>
                        <?php endif; ?>
                        <?php if (!empty($o['materials_short'])): ?>
                            <span class="pill pill-warn" title="Batch ticket<?= count($o['materials_short']) === 1 ? '' : 's' ?> <?= e(implode(', ', $o['materials_short'])) ?> can't be made yet — short raw materials (see Batch tickets)">⚠ batch short materials</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="font-size:0.85rem;"><?= e($o['summary']) ?></td>
                    <td class="nowrap">
                        <?php if (($noteCounts[$o['ordr']] ?? 0) > 0): ?>
                            <span class="pill" title="This order has notes">🗒 <?= (int) $noteCounts[$o['ordr']] ?></span>
                        <?php endif; ?>
                        <?php
                        // A fully covered order can go straight on: off credit hold, or shipped and invoiced.
                        $next = null;
                        if ($tab !== 'priority' && $o['status'] === 'ready') {
                            if ($o['status_code'] === 'CSH' && $canRelease) {
                                $next = ['release', 'Release from credit hold…'];
                            } elseif ($o['status_code'] === 'RTS' && $canShip) {
                                $next = ['complete-sale', 'Complete sale…'];
                            }
                        }
                        ?>
                        <?php if ($next !== null): ?>
                            <form method="POST" action="/sales-orders/<?= (int) $o['ordr'] ?>/<?= $next[0] ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="from" value="readiness">
                                <button type="submit" class="btn btn-sm"><?= $next[1] ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="order-lines-row" id="order-lines-<?= (int) $o['ordr'] ?>" style="display:none;">
                    <td colspan="<?= $tab === 'priority' ? 10 : 9 ?>" style="background:var(--surface-2);">
                        <div class="order-lines-wrap"><span class="spinner"></span> Loading…</div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'priority' && $canReorder && $orders !== []): ?>
        <div class="form-actions">
            <button type="button" id="save-priority" class="btn btn-primary" data-busy-label="Recalculating…">Save priority order</button>
            <span class="text-muted" id="priority-hint" style="align-self:center;"></span>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';

    // Expand an order → lazy-load its lines + notes.
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.order-expand');
        if (!btn) return;
        const ordr = btn.dataset.ordr;
        const row = document.getElementById('order-lines-' + ordr);
        const open = row.style.display === 'none';
        row.style.display = open ? '' : 'none';
        btn.textContent = open ? '▼' : '▶';
        if (open && row.dataset.loaded !== '1') {
            const wrap = row.querySelector('.order-lines-wrap');
            try {
                const resp = await fetch('/orders/order/' + ordr + '/lines', { headers: { 'Accept': 'text/html' } });
                wrap.innerHTML = await resp.text();
                row.dataset.loaded = '1';
            } catch (err) {
                wrap.innerHTML = '<div class="alert alert-danger">Could not load lines: ' + err.message + '</div>';
            }
        }
    });

    // Notes: submit inline without losing the expanded row.
    document.addEventListener('submit', async (e) => {
        const form = e.target.closest('.order-note-form');
        if (!form) return;
        e.preventDefault();
        const resp = await fetch(form.action, { method: 'POST', body: new FormData(form) });
        const j = await resp.json().catch(() => ({ ok: false, message: 'Unexpected response.' }));
        if (j.ok) {
            const row = form.closest('.order-lines-row');
            row.dataset.loaded = '';
            row.querySelector('.order-lines-wrap').innerHTML = '<span class="spinner"></span> Refreshing…';
            const ordr = form.dataset.ordr;
            const r = await fetch('/orders/order/' + ordr + '/lines', { headers: { 'Accept': 'text/html' } });
            row.querySelector('.order-lines-wrap').innerHTML = await r.text();
            row.dataset.loaded = '1';
            window.PII.toast('Note added.');
        } else {
            window.PII.toast(j.message || 'Could not save the note.', '✖');
        }
    });

    <?php if ($tab === 'priority' && $canReorder): ?>
    // Priority reordering: drag rows or use the arrows, then Save.
    const tbody = document.querySelector('#orders-table tbody');
    const hint = document.getElementById('priority-hint');
    let dirty = false;
    const markDirty = () => { dirty = true; if (hint) hint.textContent = 'Unsaved changes — click Save.'; };

    function orderRows() {
        return Array.from(tbody.querySelectorAll('tr.order-row'));
    }
    function moveWithLines(row, before) {
        const lines = document.getElementById('order-lines-' + row.dataset.ordr);
        tbody.insertBefore(row, before);
        tbody.insertBefore(lines, row.nextSibling);
    }
    document.addEventListener('click', (e) => {
        const up = e.target.closest('.prio-up');
        const down = e.target.closest('.prio-down');
        if (!up && !down) return;
        const row = e.target.closest('tr.order-row');
        const rows = orderRows();
        const i = rows.indexOf(row);
        if (up && i > 0) { moveWithLines(row, rows[i - 1]); markDirty(); }
        if (down && i < rows.length - 1) { moveWithLines(rows[i + 1], row); markDirty(); }
    });

    let dragging = null;
    tbody.addEventListener('dragstart', (e) => {
        dragging = e.target.closest('tr.order-row');
        e.dataTransfer.effectAllowed = 'move';
    });
    tbody.addEventListener('dragover', (e) => {
        e.preventDefault();
        const over = e.target.closest('tr.order-row');
        if (!over || over === dragging || !dragging) return;
        const rows = orderRows();
        if (rows.indexOf(dragging) < rows.indexOf(over)) {
            moveWithLines(dragging, over.nextElementSibling ? document.getElementById('order-lines-' + over.dataset.ordr).nextElementSibling : null);
        } else {
            moveWithLines(dragging, over);
        }
        markDirty();
    });
    tbody.addEventListener('drop', (e) => e.preventDefault());

    document.getElementById('save-priority')?.addEventListener('click', async (e) => {
        const btn = e.target;
        const ids = orderRows().map(r => r.dataset.ordr);
        btn.disabled = true;
        try {
            const body = new URLSearchParams({ _csrf_token: window.PII.csrfToken, positions: ids.join(',') });
            const resp = await fetch('/orders/priority', { method: 'POST', body });
            const j = await resp.json();
            if (j.ok) {
                window.PII.toast('Priority saved — coverage recalculated.');
                setTimeout(() => window.location.reload(), 600);
            } else {
                window.PII.toast(j.message || 'Could not save.', '✖');
                btn.disabled = false;
            }
        } catch (err) {
            window.PII.toast('Could not reach the server.', '✖');
            btn.disabled = false;
        }
    });
    <?php endif; ?>
})();
</script>

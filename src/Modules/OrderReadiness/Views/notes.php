<?php
/**
 * Order-notes lookup — notes are kept forever, including for orders that
 * have since shipped/closed, so history is always searchable.
 * @var array $rows  @var string $search
 */
$asOf = null;
require __DIR__ . '/_subnav.php';
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Order notes</h2>
            <div class="card-subtitle">Every note ever added, newest first — including orders that have since shipped.</div>
        </div>
        <form method="GET" action="/orders/notes" class="flex gap-1">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Order #, customer, or note text…" style="min-width:260px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-state">
            <span class="empty-icon">🗒</span>
            <?= $search !== '' ? 'No notes match your search.' : 'No notes yet. Add them from any order on the Orders page.' ?>
        </div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr><th>When</th><th>Order #</th><th>Customer</th><th>By</th><th>Note</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $n): ?>
                <tr>
                    <td class="nowrap text-muted"><?= e(fmt_date($n['created_at'], 'M j, Y g:i A')) ?></td>
                    <td><strong><?= (int) $n['ordr'] ?></strong></td>
                    <td><?= e((string) ($n['bill_to_name'] ?? '')) ?: '<span class="text-muted">order closed</span>' ?></td>
                    <td class="text-muted"><?= e($n['display_name'] ?: ($n['username'] ?? '')) ?></td>
                    <td style="white-space:pre-wrap;max-width:520px;"><?= e($n['note']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

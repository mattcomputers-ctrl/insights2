<?php /** @var array $rows */ ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Last 500 audit log entries</h2>
    </div>

    <?php if (empty($rows)): ?>
        <p class="muted-empty">No audit entries yet.</p>
    <?php else: ?>
    <table class="table" style="font-size:0.85rem;">
        <thead>
            <tr>
                <th>When</th>
                <th>User</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Details</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap"><?= e(fmt_date($r['created_at'], 'm/d/Y H:i:s')) ?></td>
                <td><?= e((string) ($r['username'] ?? '—')) ?></td>
                <td><span class="tag"><?= e($r['action']) ?></span></td>
                <td>
                    <?= e($r['entity_type']) ?>
                    <?php if ($r['entity_id']): ?> <span class="text-muted">#<?= e($r['entity_id']) ?></span><?php endif; ?>
                </td>
                <td class="text-muted" style="max-width:400px;word-break:break-all;font-family:Consolas,monospace;font-size:0.78rem;">
                    <?= e((string) ($r['details'] ?? '')) ?>
                </td>
                <td class="text-muted"><?= e((string) ($r['ip_address'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

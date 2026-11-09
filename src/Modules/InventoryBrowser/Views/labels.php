<?php
/**
 * Containers that hold stock and still ask for a label, live from CMS.
 * @var array $rows  @var ?string $error  @var string $mode
 */
use PII\Cms\Write\Ops\Inventory\InventoryState;

?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/inventory" class="btn btn-sm">← Inventory</a>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">Containers waiting for a label</h2>
    <div class="card-subtitle">
        CMS marks a container as needing a label when it is filled. Once the label is on the container, mark it here.
        Nothing is printed from this page.
    </div>
    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($rows === []): ?>
        <div class="empty-state"><span class="empty-icon">🏷</span>No container with stock is waiting for a label.</div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Container</th><th>Item</th><th>Description</th><th class="text-right">Quantity</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><strong><?= e(trim((string) ($r['LocationCode'] ?? ''))) ?></strong></td>
                    <td class="nowrap"><?= e(trim((string) ($r['ItemCode'] ?? ''))) ?></td>
                    <td><?= e(trim((string) ($r['Description'] ?? ''))) ?></td>
                    <td class="text-right tabular nowrap"><?= e(InventoryState::show(InventoryState::float($r['Qty'] ?? null) ?? 0.0)) ?> <?= e(trim((string) ($r['Unit'] ?? ''))) ?></td>
                    <td class="text-right">
                        <form method="POST" action="/inventory/labels/<?= (int) $r['Location'] ?>" style="display:inline;">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm">Mark labelled…</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
/**
 * Inventory counts, live from CMS: open ones first.
 * @var array $counts  @var ?string $error  @var bool $canSave  @var bool $canPlan  @var bool $canLabel  @var string $mode
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/inventory" class="btn btn-sm">← Inventory</a>
    <div class="flex gap-1 items-center">
        <?php if ($canLabel): ?>
            <a href="/inventory/labels" class="btn btn-sm">Labels waiting</a>
        <?php endif; ?>
        <?php if ($canPlan): ?>
            <form method="POST" action="/inventory/recalculate" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm" title="Have CMS work out the planned dates of open orders again">Recalculate planned dates…</button>
            </form>
        <?php endif; ?>
        <?php if ($canSave): ?>
            <a href="/inventory/counts/new" class="btn btn-sm btn-primary">New count</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">Inventory counts</h2>
    <div class="card-subtitle">A count sets stock on hand to what was counted when it is posted. Counts not posted yet are listed first.</div>
    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($counts === []): ?>
        <div class="empty-state"><span class="empty-icon">🗃</span>No counts yet.</div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Count</th><th>Date</th><th>Description</th><th class="text-right">Items</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($counts as $c): ?>
                <?php $posted = in_array($c['Posted'], [1, '1', true], true); ?>
                <tr>
                    <td><a href="/inventory/counts/<?= (int) $c['InventoryCount'] ?>"><strong><?= (int) $c['InventoryCount'] ?></strong></a></td>
                    <td class="nowrap"><?= ($c['EffectiveDate'] ?? '') !== '' ? e(fmt_date((string) $c['EffectiveDate'])) : '' ?></td>
                    <td><?= e(trim((string) ($c['Description'] ?? ''))) ?></td>
                    <td class="text-right tabular"><?= (int) $c['Lines'] ?></td>
                    <td><?= $posted ? '<span class="text-muted">Posted</span>' : '<strong>Not posted</strong>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

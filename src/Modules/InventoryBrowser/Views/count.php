<?php
/**
 * One inventory count, live from CMS: change, post or delete it while it is not posted.
 * @var ?array $count  @var ?string $error  @var bool $canSave  @var bool $canPost  @var bool $canDelete  @var string $mode
 */
use PII\Cms\Write\Ops\Inventory\CountInput;
use PII\Cms\Write\Ops\Inventory\InventoryState;

?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/inventory/counts" class="btn btn-sm">← Counts</a>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>

<?php if ($error !== null || $count === null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error ?? 'That count was not found.') ?></div></div>
    <?php return; ?>
<?php endif; ?>

<?php
$id     = (int) $count['InventoryCount'];
$posted = (bool) $count['Posted'];
$edit   = !$posted && $canSave;
$day    = substr((string) $count['EffectiveDate'], 0, 10);
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Inventory count <?= $id ?></h2>
            <div class="card-subtitle">
                <?= $posted
                    ? 'Posted. Stock was set to the counted quantities; the count can no longer be changed.'
                    : 'Not posted yet. Stock changes only when the count is posted.' ?>
            </div>
        </div>
        <?php if (!$posted): ?>
        <div class="flex gap-1 items-center no-print">
            <?php if ($canDelete): ?>
                <form method="POST" action="/inventory/counts/<?= $id ?>/delete" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm">Delete…</button>
                </form>
            <?php endif; ?>
            <?php if ($canPost && $count['lines'] !== []): ?>
                <form method="POST" action="/inventory/counts/<?= $id ?>/post" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-primary">Check and post…</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($edit): ?><form method="POST" action="/inventory/counts/<?= $id ?>"><?= csrf_field() ?><?php endif; ?>
    <div class="form-row">
        <div class="form-group">
            <label for="count-date">Count date</label>
            <?php if ($edit): ?>
                <input type="date" id="count-date" name="date" value="<?= e($day) ?>" max="<?= e(date('Y-m-d')) ?>" required>
            <?php else: ?>
                <div><?= e(fmt_date($day)) ?></div>
            <?php endif; ?>
        </div>
        <div class="form-group" style="flex:2;">
            <label for="count-about">Description</label>
            <?php if ($edit): ?>
                <input type="text" id="count-about" name="description" value="<?= e($count['Description']) ?>" maxlength="50">
            <?php else: ?>
                <div><?= $count['Description'] !== '' ? e($count['Description']) : '<span class="text-muted">None</span>' ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($count['lines'] === []): ?>
        <div class="empty-state">This count has no items.</div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th><th>Description</th><th class="text-right">Counted</th><th>Unit</th>
                    <?php if ($posted): ?>
                        <th class="text-right">Adjustment posted</th>
                    <?php else: ?>
                        <th class="text-right">On hand now</th><th>If posted now</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($count['lines'] as $l): ?>
                <tr>
                    <td class="nowrap"><strong><?= e($l['code']) ?></strong></td>
                    <td><?= e($l['description']) ?></td>
                    <td class="text-right tabular">
                        <?php if ($edit): ?>
                            <input type="text" inputmode="decimal" name="qty[<?= (int) $l['detail'] ?>]" value="<?= e(InventoryState::show($l['qty'])) ?>"
                                   maxlength="20" autocomplete="off" style="width:8rem;text-align:right;" aria-label="Quantity counted of <?= e($l['code']) ?>">
                        <?php else: ?>
                            <?= e(InventoryState::show($l['qty'])) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e($l['unit']) ?></td>
                    <?php if ($posted): ?>
                        <td class="text-right tabular"><?= $l['adjust'] === null ? '' : e(($l['adjust'] > 0 ? '+' : '') . InventoryState::show($l['adjust'])) ?></td>
                    <?php else: ?>
                        <td class="text-right tabular"><?= e(InventoryState::show($l['onHand'])) ?></td>
                        <td style="font-size:0.85rem;"><?= e(CountInput::effect($l['unit'], $l['qty'], $l['onHand'])) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($edit): ?>
        <div class="no-print" style="margin-top:1rem;">
            <button type="submit" class="btn btn-primary">Check and save changes…</button>
            <span class="text-muted" style="font-size:0.85rem;margin-left:0.5rem;">To add or remove items, delete this count and start a new one.</span>
        </div>
        </form>
    <?php endif; ?>
</div>

<?php
/**
 * New item: a raw material or a packaging item, created in CMS after a review.
 * @var array<string,array{label:string,unit:string}> $kinds
 * @var string $code  @var string $description  @var string $kind  @var string $mode
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/items" class="btn btn-sm">← Items</a>
</div>

<div class="card">
    <h2 class="card-title">New item</h2>
    <div class="card-subtitle">A raw material or a packaging item. Products that are made here are created in CMS,
        where their pack sizes and tests are set up with them.</div>
    <?php if ($mode !== 'live'): ?>
        <div class="alert alert-info">Practice mode: the item is checked and previewed, but nothing is sent to CMS.</div>
    <?php endif; ?>
    <form method="POST" action="/items/new">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group">
                <label for="new-kind">Kind</label>
                <select id="new-kind" name="kind" required>
                    <option value="">Choose…</option>
                    <?php foreach ($kinds as $key => $k): ?>
                        <option value="<?= e($key) ?>" <?= $key === $kind ? 'selected' : '' ?>><?= e($k['label']) ?> (stocked in <?= e($k['unit']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="new-code">Item code</label>
                <input type="text" id="new-code" name="code" value="<?= e($code) ?>" maxlength="30" required style="width:14rem;text-transform:uppercase;">
            </div>
            <div class="form-group" style="flex:1;min-width:18rem;">
                <label for="new-description">Description</label>
                <input type="text" id="new-description" name="description" value="<?= e($description) ?>" maxlength="256" required>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Review new item…</button>
    </form>
</div>

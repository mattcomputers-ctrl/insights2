<?php
/**
 * Item search (live CMS) — results link to the item 360 page.
 * @var string $q  @var array $items  @var bool $truncated
 * @var ?string $error  @var ?string $asOf  @var bool $canCreate
 */
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Items</h2>
            <div class="card-subtitle">Search by item code, description, alias or alias description — live from CMS.</div>
        </div>
        <form method="GET" action="/items" class="flex gap-1" style="flex:1;min-width:280px;max-width:520px;">
            <input type="text" name="q" value="<?= e($q) ?>" style="flex:1;" autofocus placeholder="Item #, description or alias…">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
        <?php if ($canCreate): ?>
            <a href="/items/new" class="btn btn-sm">New item…</a>
        <?php endif; ?>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($q === ''): ?>
        <div class="empty-state"><span class="empty-icon">🏷</span>Type something to search.</div>
    <?php elseif ($items === []): ?>
        <div class="empty-state"><span class="empty-icon">🔍</span>Nothing matches "<?= e($q) ?>".</div>
    <?php else: ?>
        <?php if ($truncated): ?>
            <div class="alert alert-info">Lots of matches — showing the first <?= count($items) ?>. Narrow the search for the rest.</div>
        <?php endif; ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Item</th><th>Description</th><th>Unit</th><th>Matched via</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="nowrap">
                            <a href="/items/view?code=<?= urlencode((string) $it['ItemCode']) ?>"><?= item_ctx((string) $it['ItemCode']) ?></a>
                        </td>
                        <td><?= e((string) ($it['Description'] ?? '')) ?></td>
                        <td class="text-muted"><?= e((string) ($it['Unit'] ?? '')) ?></td>
                        <td class="text-muted"><?= $it['via'] !== null ? 'alias ' . e((string) $it['via']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

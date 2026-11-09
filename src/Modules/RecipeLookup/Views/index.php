<?php
/**
 * Item & Recipe Lookup: search items / aliases / recipe numbers; recipes
 * open in an in-page window (modal) with nested drill-down + back.
 * @var string $q  @var array $items  @var array $recipeHits
 * @var bool $truncated  @var ?string $error  @var ?string $asOf
 */
$recipeChip = function (array $r, bool $active): string {
    $cls = $active ? 'pill pill-active' : ((int) $r['Inactive'] === 1 ? 'pill' : 'pill pill-info');
    $title = ($active ? 'Active costing recipe' : ((int) $r['Inactive'] === 1 ? 'Inactive' : 'Older version'))
        . ($r['DateCreated'] ? ', created ' . $r['DateCreated'] : '');
    return '<button type="button" class="recipe-open ' . $cls . '" data-recipe="' . (int) $r['Recipe'] . '"'
        . ' title="' . e($title) . '" style="cursor:pointer;border:none;">'
        . e((string) $r['RecipeNumber']) . ($active ? ' ✔' : ((int) $r['Inactive'] === 1 ? ' ✖' : ''))
        . '</button>';
};
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Item &amp; Recipe Lookup</h2>
            <div class="card-subtitle">
                Search by item number, description, alias, alias description or recipe number.
                Click a recipe to open it — 100% formula with the procedure, straight from CMS.
            </div>
        </div>
        <form method="GET" action="/recipes" class="flex gap-1" style="flex:1;min-width:280px;max-width:520px;">
            <input type="text" name="q" value="<?= e($q) ?>" style="flex:1;" autofocus
                   placeholder="Item #, description, alias or recipe #…">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($q === ''): ?>
        <div class="empty-state"><span class="empty-icon">⚗</span>Type something to search — e.g. an ink number, a customer's alias, or a recipe number.</div>
    <?php elseif ($items === [] && $recipeHits === []): ?>
        <div class="empty-state"><span class="empty-icon">🔍</span>Nothing matches "<?= e($q) ?>".</div>
    <?php else: ?>
        <?php if ($truncated): ?>
            <div class="alert alert-info">Lots of matches — showing the first <?= count($items) ?> items. Narrow the search for the rest.</div>
        <?php endif; ?>

        <?php if ($items !== []): ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr><th>Item</th><th>Description</th><th>Unit</th><th>Matched alias</th><th>Recipes (click to open)</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="nowrap"><?= item_ctx($it['item_code']) ?>
                            <?php if ($it['context'] === 'PP'): ?><div class="tag">pack</div><?php endif; ?>
                        </td>
                        <td><?= e($it['description']) ?></td>
                        <td class="text-muted"><?= e($it['unit']) ?></td>
                        <td style="font-size:0.85rem;">
                            <?php foreach (array_slice($it['aliases'], 0, 4) as $a): ?>
                                <div class="nowrap"><strong><?= e($a['code']) ?></strong>
                                    <?php if ($a['description'] !== ''): ?><span class="text-muted">— <?= e($a['description']) ?></span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($it['aliases']) > 4): ?><div class="text-muted">… +<?= count($it['aliases']) - 4 ?> more</div><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($it['recipes'] === []): ?>
                                <span class="text-muted">— no recipe</span>
                            <?php else: ?>
                                <div class="flex gap-1" style="flex-wrap:wrap;">
                                    <?php foreach ($it['recipes'] as $r): ?>
                                        <?= $recipeChip($r, $it['costing_recipe'] !== null && (int) $r['Recipe'] === $it['costing_recipe']) ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($recipeHits !== []): ?>
            <h3 style="margin:1rem 0 0.4rem;">Recipes matching "<?= e($q) ?>"</h3>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Recipe</th><th>Makes</th><th>Description</th><th>Created</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recipeHits as $r): ?>
                        <tr>
                            <td><button type="button" class="recipe-open pill pill-info" style="cursor:pointer;border:none;"
                                        data-recipe="<?= (int) $r['Recipe'] ?>"><?= e((string) $r['RecipeNumber']) ?></button></td>
                            <td class="nowrap"><strong><?= e((string) ($r['MakesCode'] ?? '')) ?></strong></td>
                            <td><?= e((string) ($r['MakesDescription'] ?? '')) ?></td>
                            <td class="text-muted"><?= e(fmt_date($r['DateCreated'])) ?></td>
                            <td>
                                <?php if ((int) $r['IsActive'] === 1): ?><span class="pill pill-active">✔ active</span>
                                <?php elseif ((int) $r['Inactive'] === 1): ?><span class="pill">inactive</span>
                                <?php else: ?><span class="pill pill-info">older version</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- The in-page recipe window + .recipe-open handling live in the layout/app.js -->


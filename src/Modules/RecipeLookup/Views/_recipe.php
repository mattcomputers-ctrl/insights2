<?php
/**
 * Modal fragment: one recipe — 100% formula with the procedure steps in
 * batch-sheet order (ExecOrder interleaves INSTR with ingredients).
 * @var array $head  @var array $lines  @var ?array $actions (recipe changes the user may propose)
 */
$pct = function (?float $f): string {
    return rtrim(rtrim(number_format((float) $f * 100, 4, '.', ','), '0'), '.');
};
$sum = 0.0;
foreach ($lines as $l) {
    if ($l['Context'] === 'UI') {
        $sum += (float) $l['QtyReqd'];
    }
}
$step = 0;
?>
<div class="recipe-sheet">
    <div class="flex-between" style="flex-wrap:wrap;gap:0.5rem;margin-bottom:0.3rem;">
        <h2 style="margin:0;">Recipe <?= e((string) $head['RecipeNumber']) ?></h2>
        <div class="flex gap-1 items-center">
            <?php if ((int) $head['IsActive'] === 1): ?>
                <span class="pill pill-active">✔ Active costing recipe</span>
            <?php elseif ((int) $head['Inactive'] === 1): ?>
                <span class="pill">✖ Inactive</span>
            <?php else: ?>
                <span class="pill pill-info">Older version</span>
            <?php endif; ?>
            <button type="button" class="btn btn-sm" onclick="window.print()">🖨 Print</button>
        </div>
    </div>
    <div class="text-muted" style="margin-bottom:0.8rem;">
        Makes <strong><?= e((string) ($head['MakesCode'] ?? '?')) ?></strong>
        <?= $head['MakesDescription'] ? '— ' . e((string) $head['MakesDescription']) : '' ?>
        <?php if (!empty($head['DateCreated'])): ?> · created <?= e(fmt_date($head['DateCreated'])) ?><?php endif; ?>
        <?php if (!empty($head['DateUpdated'])): ?> · updated <?= e(fmt_date($head['DateUpdated'])) ?><?php endif; ?>
    </div>
    <?php if (!empty($head['Comment'])): ?>
        <div class="alert alert-info" style="white-space:pre-wrap;"><?= e((string) $head['Comment']) ?></div>
    <?php endif; ?>
    <?php if (!empty($actions)): ?>
        <div class="flex gap-1 items-center no-print" style="flex-wrap:wrap;margin-bottom:0.8rem;">
            <?php if ($actions['publish']): ?>
                <form method="POST" action="/recipes/recipe/<?= (int) $head['Recipe'] ?>/publish">
                    <?= csrf_field() ?>
                    <input type="hidden" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>">
                    <button type="submit" class="btn btn-sm btn-primary">Publish this recipe…</button>
                </form>
            <?php endif; ?>
            <?php if ($actions['retire'] || $actions['reactivate']): ?>
                <form method="POST" action="/recipes/recipe/<?= (int) $head['Recipe'] ?>/active">
                    <?= csrf_field() ?>
                    <input type="hidden" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>">
                    <input type="hidden" name="active" value="<?= $actions['reactivate'] ? '1' : '0' ?>">
                    <button type="submit" class="btn btn-sm"><?= $actions['reactivate'] ? 'Make this recipe active again…' : 'Retire this recipe…' ?></button>
                </form>
            <?php endif; ?>
            <span class="text-muted" style="font-size:0.85rem;">
                <?= $actions['mode'] !== 'live' ? 'Practice mode: nothing is sent to CMS.' : 'You will see exactly what is sent before anything is saved.' ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($lines === []): ?>
        <div class="empty-state"><span class="empty-icon">📄</span>This recipe has no formula lines in CMS.</div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr><th style="width:90px;" class="text-right">%</th><th>Ingredient / step</th><th>Description</th></tr>
        </thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
            <?php if ($l['Context'] === 'INSTR'): ?>
                <tr class="recipe-step">
                    <td class="text-right text-muted tabular"><?= ++$step ?>.</td>
                    <td colspan="2" style="font-style:italic;"><?= e((string) ($l['InstrText'] ?? '')) ?></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td class="text-right tabular"><strong><?= $pct((float) $l['QtyReqd']) ?></strong></td>
                    <td class="nowrap">
                        <?php if ($l['CostingRecipe'] !== null): ?>
                            <button type="button" class="recipe-open pill pill-info" style="cursor:pointer;border:none;"
                                    title="Made in-house — click to open its recipe"
                                    data-recipe="<?= (int) $l['CostingRecipe'] ?>"><?= e((string) $l['ItemCode']) ?> ⚗</button>
                        <?php else: ?>
                            <strong><?= e((string) ($l['ItemCode'] ?? '')) ?></strong>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($l['ItemDescription'] ?? '')) ?></td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="text-right tabular"><strong><?= $pct($sum) ?></strong></td>
                <td colspan="2" class="<?= abs($sum - 1.0) > 0.001 ? 'text-danger' : 'text-muted' ?>">
                    total<?= abs($sum - 1.0) > 0.001 ? ' — does not add up to 100% in CMS' : '' ?>
                </td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

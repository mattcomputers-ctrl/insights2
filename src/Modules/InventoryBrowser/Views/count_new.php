<?php
/**
 * New inventory count: item codes and what was counted.
 * @var list<array{0:string,1:string}> $codes  @var int $rows  @var string $date  @var string $about
 * @var bool $canSave  @var bool $canPost  @var string $mode
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/inventory/counts" class="btn btn-sm">← Counts</a>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">New inventory count</h2>
    <div class="card-subtitle">
        Enter each item code and the quantity counted, in the item's stock unit. Leave unused rows blank.
        The next step shows what posting would add or remove before anything is sent.
    </div>
    <form method="POST" action="/inventory/counts/new">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group">
                <label for="count-date">Count date</label>
                <input type="date" id="count-date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="form-group" style="flex:2;">
                <label for="count-about">Description (optional)</label>
                <input type="text" id="count-about" name="description" value="<?= e($about) ?>" maxlength="50">
            </div>
        </div>
        <div class="table-scroll">
            <table class="table" style="max-width:34rem;">
                <thead><tr><th>Item code</th><th class="text-right">Quantity counted</th></tr></thead>
                <tbody>
                <?php for ($i = 0; $i < $rows; $i++): ?>
                    <tr>
                        <td><input type="text" name="item[<?= $i ?>]" value="<?= e($codes[$i][0] ?? '') ?>" maxlength="40"
                                   autocomplete="off" style="width:100%;" aria-label="Item code, row <?= $i + 1 ?>"></td>
                        <td class="text-right"><input type="text" inputmode="decimal" name="qty[<?= $i ?>]" value="<?= e($codes[$i][1] ?? '') ?>"
                                   maxlength="20" autocomplete="off" style="width:9rem;text-align:right;" aria-label="Quantity counted, row <?= $i + 1 ?>"></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <div class="flex gap-1" style="margin-top:1rem;">
            <?php if ($canSave): ?>
                <button type="submit" name="then" value="save" class="btn <?= $canPost ? '' : 'btn-primary' ?>">Check and save…</button>
            <?php endif; ?>
            <?php if ($canPost): ?>
                <button type="submit" name="then" value="post" class="btn btn-primary">Check, save and post…</button>
            <?php endif; ?>
        </div>
    </form>
</div>

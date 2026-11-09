<?php
/**
 * New batch ticket: bulk item and size, dates, packaging tickets, release.
 * @var array $draft  @var array $packs  @var bool $fromBoard  @var string $mode
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/batch-tickets" class="btn btn-sm">← Batch Tickets</a>
    <a href="/batch-tickets/new?clear=1" class="btn btn-sm">Start over</a>
</div>

<?php if ($mode !== 'live'): ?>
    <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
<?php endif; ?>
<?php if ($fromBoard): ?>
    <div class="alert alert-info">Filled in from the Scheduling board. Check the size, dates and packaging before you continue.</div>
<?php endif; ?>

<form method="POST" action="/batch-tickets/new">
    <?= csrf_field() ?>

    <div class="card">
        <h2 class="card-title">New batch ticket</h2>
        <div class="card-subtitle">
            The ticket is cut from the item's published recipe, scaled to the batch size.
            Hand-built and repack tickets are still cut in CMS.
        </div>

        <div class="form-row" style="margin-top:0.75rem;">
            <div class="form-group">
                <label for="bt-item">Bulk item</label>
                <input id="bt-item" type="text" name="item" value="<?= e((string) $draft['item']) ?>" style="width:16rem;" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label for="bt-qty">Batch size (lb)</label>
                <input id="bt-qty" type="text" name="qty" value="<?= e((string) $draft['qty']) ?>" inputmode="decimal" style="width:8rem;" autocomplete="off" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="bt-start">Planned start</label>
                <input id="bt-start" type="date" name="start" value="<?= e((string) $draft['start']) ?>">
            </div>
            <div class="form-group">
                <label for="bt-required">Required date</label>
                <input id="bt-required" type="date" name="required" value="<?= e((string) $draft['required']) ?>" required>
            </div>
            <div class="form-group">
                <label for="bt-bill">Customer number (optional)</label>
                <input id="bt-bill" type="text" name="bill_to" value="<?= e((string) $draft['bill_to']) ?>" inputmode="numeric" style="width:8rem;" autocomplete="off">
            </div>
        </div>
        <label style="display:block;margin-top:0.25rem;">
            <input type="checkbox" name="overcommit" value="1" <?= $draft['overcommit'] ? 'checked' : '' ?>>
            Allow release when stock is short (overcommit)
        </label>
    </div>

    <div class="card">
        <h2 class="card-title">Packaging tickets</h2>
        <div class="card-subtitle">
            One line per packaged item made from this batch. Give the quantity as a count of the packaged item,
            or as pounds of bulk to put into it. Leave the lines blank to cut the bulk ticket alone.
        </div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Packaged item</th><th>Quantity</th><th>Quantity is in</th></tr></thead>
                <tbody>
                <?php foreach ($packs as $i => $p): ?>
                    <tr>
                        <td><input type="text" name="packs[<?= (int) $i ?>][code]" value="<?= e((string) $p['code']) ?>" style="width:16rem;" autocomplete="off"></td>
                        <td><input type="text" name="packs[<?= (int) $i ?>][qty]" value="<?= e((string) $p['qty']) ?>" inputmode="decimal" style="width:8rem;" autocomplete="off"></td>
                        <td>
                            <select name="packs[<?= (int) $i ?>][by]">
                                <option value="units" <?= $p['by'] !== 'lb' ? 'selected' : '' ?>>units of the packaged item</option>
                                <option value="lb" <?= $p['by'] === 'lb' ? 'selected' : '' ?>>pounds of bulk</option>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Release</h2>
        <label style="display:block;margin-bottom:0.75rem;">
            <input type="checkbox" name="release" value="1" <?= $draft['release'] ? 'checked' : '' ?>>
            Release the ticket to the floor straight away (creates its lot)
        </label>
        <button type="submit" class="btn btn-primary">Check ticket…</button>
        <span class="text-muted" style="margin-left:0.5rem;">You will see exactly what is sent before anything is saved.</span>
    </div>
</form>

<?php
/** @var array $presets */
$today = date('Y-m-d');
?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Save a new preset</h2>
    </div>

    <form method="POST" action="/presets">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group">
                <label>Preset name</label>
                <input type="text" name="name" required maxlength="128" placeholder="e.g. Last Quarter vs. Same Quarter Prior Year">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Baseline start</label>
                <input type="date" name="baseline_start" required max="<?= e($today) ?>">
            </div>
            <div class="form-group">
                <label>Baseline end</label>
                <input type="date" name="baseline_end" required max="<?= e($today) ?>">
            </div>
            <div class="form-group">
                <label>Comparison start</label>
                <input type="date" name="comparison_start" required max="<?= e($today) ?>">
            </div>
            <div class="form-group">
                <label>Comparison end</label>
                <input type="date" name="comparison_end" required max="<?= e($today) ?>">
            </div>
            <div class="form-group" style="flex:0 0 auto;">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">Save preset</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Your presets</h2>
    </div>

    <?php if (empty($presets)): ?>
        <p class="muted-empty">No presets yet.</p>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Baseline</th>
                <th>Comparison</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($presets as $p): ?>
            <tr>
                <td><strong><?= e($p['name']) ?></strong></td>
                <td class="tabular"><?= fmt_date($p['baseline_start']) ?> — <?= fmt_date($p['baseline_end']) ?></td>
                <td class="tabular"><?= fmt_date($p['comparison_start']) ?> — <?= fmt_date($p['comparison_end']) ?></td>
                <td class="text-muted"><?= fmt_date($p['created_at']) ?></td>
                <td class="text-right">
                    <a href="<?= e('/margin-watchdog?' . http_build_query([
                        'baseline_start'   => $p['baseline_start'],
                        'baseline_end'     => $p['baseline_end'],
                        'comparison_start' => $p['comparison_start'],
                        'comparison_end'   => $p['comparison_end'],
                    ])) ?>" class="btn btn-sm btn-primary">Run report</a>
                    <form method="POST" action="<?= e('/presets/' . $p['id'] . '/delete') ?>" style="display:inline;"
                          onsubmit="return confirm('Delete preset “<?= e(addslashes($p['name'])) ?>”?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
/**
 * @var array $thresholds      per-metric config from Thresholds::forUser()
 * @var array $systemDefaults  metrics that are currently customised at the system level
 * @var bool  $colorsOn
 * @var array $labels          metric → human-readable label
 * @var bool  $isAdmin         current user is in the Administrators group
 */
use PII\Modules\MarginWatchdog\Thresholds;
$hasSystemDefaults = !empty($systemDefaults);
?>
<form method="POST" action="/preferences">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Color indicators</h2>
                <div class="card-subtitle">When on, difference cells in Margin Watchdog turn green/yellow/red based on the thresholds below. The N/A indicator (item present in only one period) is never colored regardless.</div>
            </div>
        </div>
        <label style="display:inline-flex;gap:0.6rem;align-items:center;cursor:pointer;font-weight:500;">
            <span class="switch">
                <input type="checkbox" name="colors_enabled" value="1" <?= $colorsOn ? 'checked' : '' ?>>
                <span class="slider"></span>
            </span>
            <span>Show color indicators on difference cells</span>
        </label>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Margin Watchdog thresholds</h2>
                <div class="card-subtitle">
                    Difference is colored <span class="text-good">green</span> when it crosses the green threshold in the good direction,
                    <span class="text-bad">red</span> when it crosses the red threshold in the bad direction, and
                    <span class="text-warn">yellow</span> in between.
                </div>
                <div class="card-subtitle" style="margin-top:0.4rem;">
                    Resolution order: <strong>your custom value</strong>
                    <?php if ($hasSystemDefaults): ?>→ <strong>system default</strong> (admin-set)<?php endif; ?>
                    → <strong>code default</strong>.
                    <?php if ($isAdmin): ?>
                        As an administrator, the values you save here apply only to you;
                        use the <em>Save as system defaults</em> button below to publish the same
                        values as the fallback for any user who hasn't customised that metric.
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="threshold-row" style="font-weight:600;color:var(--text-muted);">
            <div>Metric</div>
            <div>Direction (what's good)</div>
            <div class="text-right">Green threshold (good ≥)</div>
            <div class="text-right">Red threshold (bad ≤ −)</div>
        </div>
        <?php foreach ($labels as $metric => $label):
            $cfg  = $thresholds[$metric] ?? Thresholds::DEFAULTS[$metric];
            // pp-unit thresholds are point deltas; we display as "%" because the
            // adjacent two cells are already percentages so context disambiguates.
            $unit = '%';
            $dirClass = $cfg['direction'] === 'up_good' ? 'good-up' : 'good-down';
            $dirText  = $cfg['direction'] === 'up_good' ? 'UP is good' : 'DOWN is good';
        ?>
        <div class="threshold-row">
            <div><strong><?= e($label) ?></strong></div>
            <div class="threshold-direction <?= $dirClass ?>" title="<?= e($dirText) ?>"></div>
            <div class="text-right">
                <input type="number" step="0.01" name="thresholds[<?= e($metric) ?>][green]"
                       value="<?= e((string) $cfg['green']) ?>"
                       style="width:120px;display:inline-block;">
                <span class="text-muted"><?= e($unit) ?></span>
            </div>
            <div class="text-right">
                <input type="number" step="0.01" name="thresholds[<?= e($metric) ?>][red]"
                       value="<?= e((string) $cfg['red']) ?>"
                       style="width:120px;display:inline-block;">
                <span class="text-muted"><?= e($unit) ?></span>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save preferences</button>
            <?php if ($isAdmin): ?>
                <!-- Admin-only: save the same form values as system-wide defaults.
                     Uses formaction so the SAME form posts to a different endpoint. -->
                <button type="submit"
                        formaction="/preferences/save-as-system"
                        class="btn"
                        title="Publishes the values currently in this form as the fallback for every user who hasn't customised them. Doesn't change other users' overrides."
                        onclick="return confirm('Save the values in this form as system-wide default thresholds? Users who already customised their own values keep them; everyone else falls through to these.');">
                    📌 Save as system defaults (admin)
                </button>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="flex gap-2 mt-2" style="flex-wrap:wrap;">
    <form method="POST" action="/preferences/reset"
          onsubmit="return confirm('Reset YOUR thresholds — fall back to system or code defaults?');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline">Reset my thresholds</button>
    </form>

    <?php if ($isAdmin && $hasSystemDefaults): ?>
        <form method="POST" action="/preferences/reset-system-defaults"
              onsubmit="return confirm('Clear the system-wide default thresholds? Users without their own customisation will fall through to the code defaults.');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline">Clear system defaults (admin)</button>
        </form>
    <?php endif; ?>
</div>

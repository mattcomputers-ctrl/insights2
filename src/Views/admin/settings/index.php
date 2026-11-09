<?php
/**
 * @var string $appNameValue    current DB override (empty if not set)
 * @var string $configAppName   the config.php fallback value
 * @var array  $snapshotInfo    ['latest' => YYYY-MM-DD|null, 'earliest' => YYYY-MM-DD|null, 'days_captured' => int]
 */
$daysCaptured = (int) ($snapshotInfo['days_captured'] ?? 0);
$latest       = $snapshotInfo['latest']   ?? null;
$earliest     = $snapshotInfo['earliest'] ?? null;
?>
<form method="POST" action="/admin/settings">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">System name</h2>
                <div class="card-subtitle">
                    The name shown in the browser tab, the topbar brand, and the login screen.
                    Affects every signed-in user immediately.
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Display name</label>
            <input type="text" name="app_name" maxlength="128"
                   value="<?= e($appNameValue) ?>"
                   placeholder="<?= e($configAppName) ?>">
            <small class="text-muted" style="display:block;margin-top:0.4rem;">
                <?php if ($appNameValue !== ''): ?>
                    Currently overriding the config default of "<strong><?= e($configAppName) ?></strong>".
                    Clear this field and save to revert to the config value.
                <?php else: ?>
                    No override set — using the config default of "<strong><?= e($configAppName) ?></strong>".
                <?php endif; ?>
            </small>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save settings</button>
            <a href="/admin/users" class="btn">Cancel</a>
        </div>
    </div>
</form>

<form method="POST" action="/admin/settings/coa" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Certificates of Analysis</h2>
                <div class="card-subtitle">
                    The logo and disclaimer printed on every COA (QC Results → Certificate of Analysis).
                </div>
            </div>
        </div>
        <?php $coaLogo = \PII\Services\Settings::get('coa.logo'); ?>
        <div class="form-group">
            <label>Company logo</label>
            <?php if ($coaLogo): ?>
                <div style="margin:0.4rem 0;">
                    <img src="<?= e($coaLogo) ?>" alt="COA logo" style="max-height:70px;max-width:260px;border:1px solid var(--border);border-radius:6px;padding:4px;background:#fff;">
                </div>
                <label style="display:inline-flex;gap:0.35rem;align-items:center;font-weight:normal;cursor:pointer;">
                    <input type="checkbox" name="remove_logo" value="1"> Remove the current logo
                </label>
            <?php endif; ?>
            <input type="file" name="coa_logo" accept=".png,.jpg,.jpeg,.gif,.webp">
            <div class="form-hint">PNG, JPG, GIF or WEBP, up to 2 MB. Shown at the top of the certificate.</div>
        </div>
        <div class="form-group">
            <label for="coa_disclaimer">Disclaimer statement</label>
            <textarea name="coa_disclaimer" id="coa_disclaimer" rows="4" style="width:100%;"
                placeholder="e.g. The results above apply only to the lot stated. This certificate is provided…"><?= e((string) (\PII\Services\Settings::get('coa.disclaimer') ?? '')) ?></textarea>
            <div class="form-hint">Printed at the bottom of every certificate. Leave empty for none.</div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save COA settings</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Inventory snapshots</h2>
            <div class="card-subtitle">
                Daily inventory captures power the dashboard's "as of yesterday" totals and 30-day comparison.
                A nightly cron runs automatically at <strong>03:00</strong>; the buttons below let you trigger one ad-hoc.
            </div>
        </div>
    </div>

    <div class="stat-grid" style="margin-bottom:1rem;">
        <div class="stat-card">
            <div class="stat-label">Days captured</div>
            <div class="stat-value"><?= fmt_number($daysCaptured) ?></div>
            <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;">
                <?php if ($daysCaptured === 0): ?>
                    No snapshots yet — click "Backfill 30 days" to populate
                <?php else: ?>
                    <?= e(fmt_date($earliest, 'M j, Y')) ?> → <?= e(fmt_date($latest, 'M j, Y')) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Latest snapshot</div>
            <div class="stat-value"><?= $latest ? e(fmt_date($latest, 'M j')) : '—' ?></div>
            <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;">
                <?php if ($latest && $latest === date('Y-m-d', strtotime('-1 day'))): ?>
                    Up to date (yesterday)
                <?php elseif ($latest): ?>
                    <?= e(fmt_date($latest, 'l, F j, Y')) ?>
                <?php else: ?>
                    No snapshots yet
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Progress bar — hidden until JS detects an active run -->
    <div id="snapshot-progress" style="display:none;margin:0.5rem 0 1rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem;">
            <span style="font-weight:600;font-size:0.88rem;">
                <span class="spinner" style="margin-right:0.4rem;"></span>
                <span id="snap-headline">Snapshot running…</span>
            </span>
            <span class="text-muted" style="font-size:0.78rem;" id="snap-meta"></span>
        </div>
        <div style="background:var(--border);border-radius:6px;height:14px;overflow:hidden;">
            <div id="snap-bar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--primary) 0%,var(--primary-light) 100%);transition:width 0.4s ease;"></div>
        </div>
        <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;" id="snap-detail"></div>
    </div>

    <div class="form-actions" style="border-top:none;padding-top:0;">
        <form method="POST" action="/admin/settings/run-snapshot" style="display:inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary"
                    title="Captures yesterday's snapshot. Skips if already captured. Takes ~45 seconds.">
                ▶ Run snapshot for yesterday
            </button>
        </form>
        <form method="POST" action="/admin/settings/backfill-snapshot" style="display:inline;"
              onsubmit="return confirm('Start a 30-day inventory backfill? This will take about 25 minutes (CMS query is slow). It runs in the background — you can keep using the system, and the progress bar will track it. Continue?');">
            <?= csrf_field() ?>
            <button type="submit" class="btn"
                    title="Captures every day in the last 30 that's not already cached. ~25 minutes total.">
                ↻ Backfill last 30 days
            </button>
        </form>
        <span style="flex:1;"></span>
        <a href="/admin/audit-log" class="btn btn-sm btn-outline">View audit log</a>
    </div>

    <div class="text-muted" style="font-size:0.78rem;margin-top:0.75rem;">
        Background runs append to <code>storage/logs/snapshot-inventory.log</code>.
        Both buttons skip dates that are already cached, so re-clicking is safe.
    </div>
</div>

<script>
(function () {
    'use strict';
    const wrap     = document.getElementById('snapshot-progress');
    const bar      = document.getElementById('snap-bar');
    const headline = document.getElementById('snap-headline');
    const meta     = document.getElementById('snap-meta');
    const detail   = document.getElementById('snap-detail');
    if (!wrap) return;

    let prevState = null;
    let timer     = null;

    async function poll() {
        try {
            const resp = await fetch('/admin/settings/snapshot-status', {
                headers: { 'Accept': 'application/json' },
                cache:   'no-store'
            });
            const json = await resp.json();

            const wasRunning  = (prevState === 'running');
            const justFinished = wasRunning && json.state === 'finished';

            if (justFinished) {
                // Hold the bar at 100% briefly so the user sees completion,
                // then reload so the stat tiles refresh.
                wrap.style.display = '';
                bar.style.width    = '100%';
                headline.innerHTML = '✓ Snapshot complete!';
                meta.textContent   = '100%';
                detail.textContent = 'Refreshing…';
                setTimeout(() => window.location.reload(), 1500);
                return;  // stop polling — page is about to reload
            }

            apply(json);
            prevState = json.state;

            // ALWAYS reschedule. The race when starting a run is the main reason
            // the bar would otherwise disappear: page renders before script writes
            // its first status, JS sees 'idle', and would stop. Slower interval
            // when nothing's happening keeps the cost minimal.
            const next = (json.state === 'running' || json.state === 'stale') ? 3000 : 5000;
            timer = setTimeout(poll, next);
        } catch (err) {
            // Network blip — back off and try again
            timer = setTimeout(poll, 10000);
        }
    }

    function apply(json) {
        if (json.state !== 'running' && json.state !== 'stale') {
            wrap.style.display = 'none';
            return;
        }
        wrap.style.display = '';

        const d   = json.data || {};
        const pct = json.progress_pct || 0;
        bar.style.width = pct + '%';

        const total     = d.total_dates || 0;
        const completed = d.completed_dates || 0;

        if (json.state === 'stale') {
            headline.textContent = 'Snapshot run appears to have stalled';
            meta.textContent     = '';
            detail.innerHTML     = '<span class="text-warn">Heartbeat older than 90 s. Check storage/logs/snapshot-inventory.log for the failure reason.</span>';
            return;
        }

        if (total <= 1) {
            headline.textContent = 'Capturing yesterday\'s snapshot…';
        } else {
            headline.textContent = 'Backfilling inventory snapshots — ' + completed + ' of ' + total;
        }
        meta.textContent = pct.toFixed(1) + '%';

        const phaseTxt = d.current_phase || '';
        const dateTxt  = d.current_date  ? ('· ' + d.current_date) : '';
        const errTxt   = d.error ? ('  · last error: ' + d.error) : '';
        detail.textContent = phaseTxt + (dateTxt ? ' ' + dateTxt : '') + errTxt;
    }

    poll();
})();
</script>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">System</h2>
            <div class="card-subtitle">Version, updates and backups (SPEC §5).</div>
        </div>
    </div>

    <p><strong>Installed version:</strong> <code><?= e($version) ?></code></p>

    <?php if (empty($helperReady)): ?>
        <div class="alert alert-info">
            Web-triggered updates and backups need the privileged helper.
            Run <code>sudo bash install.sh</code> once on the server to install it.
        </div>
    <?php else: ?>
        <div class="form-actions">
            <form method="POST" action="/admin/settings/update-now"
                  onsubmit="return confirm('Update now? The app backs itself up, pulls the latest code, migrates, runs the test suite and rolls back automatically if anything fails.');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary" data-busy-label="Starting update…">⬆ Update now</button>
            </form>
            <form method="POST" action="/admin/settings/backup-now">
                <?= csrf_field() ?>
                <button type="submit" class="btn" data-busy-label="Backing up…">💾 Back up now</button>
            </form>
            <?php if (!empty($backups)): ?>
                <a href="/admin/settings/download-backup" class="btn" data-export="1">⬇ Download latest backup</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($backups)): ?>
        <h3 style="margin-top:1rem;font-size:0.95rem;">Recent backups</h3>
        <table class="table" style="max-width:560px;">
            <thead><tr><th>File</th><th>Size</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($backups as $b): ?>
                <tr>
                    <td><code><?= e($b['file']) ?></code></td>
                    <td><?= fmt_number($b['size'] / 1024, 0) ?> KB</td>
                    <td class="text-muted"><?= e(date('M j, Y g:i A', $b['time'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($updateLog)): ?>
        <h3 style="margin-top:1rem;font-size:0.95rem;">Update log (last 30 lines — reload to refresh)</h3>
        <pre style="background:var(--surface-2);padding:0.75rem;border-radius:8px;font-size:0.78rem;overflow-x:auto;max-height:300px;"><?= e($updateLog) ?></pre>
    <?php endif; ?>
</div>

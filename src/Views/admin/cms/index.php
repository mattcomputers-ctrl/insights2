<?php
/**
 * Admin → CMS & Sync.
 * @var string $cmsHost  @var string $cmsName  @var string $cmsUser
 * @var bool $hasPassword  @var ?string $driver
 * @var bool $killSwitch  @var bool $autoOn  @var int $interval
 * @var array $blackouts  @var array $history  @var ?array $testResult
 */
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
?>

<?php if ($driver === null): ?>
    <div class="alert alert-danger">
        <strong>SQL Server driver not installed.</strong>
        CMS access needs the <code>pdo_sqlsrv</code> (preferred) or <code>pdo_dblib</code> PHP extension.
        Run the updater, or install it manually, then reload this page.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">CMS database access</h2>
            <div class="card-subtitle">Read-only connection to the Mar-Kov CMS SQL Server<?= $driver ? " · driver: {$driver}" : '' ?></div>
        </div>
    </div>

    <form method="POST" action="/admin/cms/connection">
        <?= csrf_field() ?>
        <div class="form-grid-2">
            <div class="form-group">
                <label>Host</label>
                <input type="text" name="host" required value="<?= e($cmsHost) ?>" placeholder="10.10.10.11">
            </div>
            <div class="form-group">
                <label>Database</label>
                <input type="text" name="name" value="<?= e($cmsName) ?>" placeholder="CMS">
            </div>
            <div class="form-group">
                <label>Read-only username</label>
                <input type="text" name="user" required value="<?= e($cmsUser) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Password <?= $hasPassword ? '<span class="text-muted">(set — leave blank to keep)</span>' : '' ?></label>
                <input type="password" name="password" autocomplete="new-password" <?= $hasPassword ? '' : 'required' ?>>
            </div>
        </div>
        <div class="form-group">
            <label style="display:inline-flex;gap:0.4rem;align-items:flex-start;cursor:pointer;font-weight:400;">
                <input type="checkbox" name="trust_server_certificate" value="1" <?= !empty($cmsTrustCert) ? 'checked' : '' ?>>
                <span>Trust the CMS server's self-signed certificate
                    <span class="text-muted">(needed with the Microsoft ODBC 18 driver — the CMS SQL Server has no CA-signed
                    certificate; the connection stays encrypted either way)</span></span>
            </label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save connection</button>
        </div>
    </form>

    <form method="POST" action="/admin/cms/test" style="margin-top:0.5rem;">
        <?= csrf_field() ?>
        <div class="form-actions">
            <button type="submit" class="btn">Test connection</button>
            <?php if ($testResult !== null): ?>
                <span class="<?= $testResult['ok'] ? 'text-success' : 'text-danger' ?>" style="align-self:center;">
                    <?= $testResult['ok'] ? '✔' : '✖' ?> <?= e($testResult['message']) ?>
                </span>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Kill switch</h2>
            <div class="card-subtitle">Stops ALL CMS access (every tool, every user) while CMS is being worked on.</div>
        </div>
    </div>
    <form method="POST" action="/admin/cms/kill-switch">
        <?= csrf_field() ?>
        <input type="hidden" name="enabled" value="<?= $killSwitch ? '0' : '1' ?>">
        <p style="margin-bottom:0.75rem;">
            CMS access is currently
            <?= $killSwitch
                ? '<strong class="text-danger">OFF — nothing can reach CMS</strong>'
                : '<strong class="text-success">on</strong>' ?>.
        </p>
        <button type="submit" class="btn <?= $killSwitch ? 'btn-primary' : 'btn-danger' ?>"
                <?= $killSwitch ? '' : 'onclick="return confirm(\'Turn OFF all CMS access for everyone?\');"' ?>>
            <?= $killSwitch ? 'Turn CMS access back on' : 'Turn off all CMS access' ?>
        </button>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Automatic sync</h2>
            <div class="card-subtitle">The background worker refreshes snapshot data from CMS on this schedule.</div>
        </div>
    </div>
    <form method="POST" action="/admin/cms/sync-settings">
        <?= csrf_field() ?>
        <div class="form-grid-2">
            <div class="form-group">
                <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;">
                    <input type="checkbox" name="auto_enabled" value="1" <?= $autoOn ? 'checked' : '' ?>>
                    Automatic sync on
                </label>
            </div>
            <div class="form-group">
                <label>Frequency (minutes, 5–240)</label>
                <input type="number" name="interval" min="5" max="240" value="<?= (int) $interval ?>">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save sync settings</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Blackout windows</h2>
            <div class="card-subtitle">
                For offsite database backups. During a window: no automatic sync, manual sync is refused
                (admins can override), and live CMS pages pause with a friendly message.
                Windows may cross midnight (e.g. 23:30–02:00).
            </div>
        </div>
    </div>
    <form method="POST" action="/admin/cms/blackouts">
        <?= csrf_field() ?>
        <table class="table" id="blackout-table" style="max-width:720px;">
            <thead>
                <tr><th>Days</th><th style="width:120px;">Start</th><th style="width:120px;">End</th><th style="width:60px;"></th></tr>
            </thead>
            <tbody>
            <?php foreach (array_merge($blackouts, [null]) as $i => $w): ?>
                <tr>
                    <td>
                        <?php foreach ($dayNames as $d => $label): ?>
                            <label style="display:inline-flex;gap:0.2rem;align-items:center;margin-right:0.5rem;cursor:pointer;">
                                <input type="checkbox" name="blackouts[<?= $i ?>][days][]" value="<?= $d ?>"
                                       <?= ($w !== null && in_array($d, $w['days'], true)) ? 'checked' : '' ?>>
                                <?= $label ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                    <td><input type="time" name="blackouts[<?= $i ?>][start]" value="<?= e($w['start'] ?? '') ?>"></td>
                    <td><input type="time" name="blackouts[<?= $i ?>][end]" value="<?= e($w['end'] ?? '') ?>"></td>
                    <td class="text-muted"><?= $w === null ? 'new' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted" style="font-size:0.85rem;">To remove a window, untick all its days and save. To add more than one new window, save and come back.</p>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save blackout windows</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Sync history</h2>
            <div class="card-subtitle">Last <?= count($history) ?> runs (90 days kept).</div>
        </div>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>#</th><th>Started</th><th>Trigger</th><th>Status</th>
                <th>Duration</th><th>Rows</th><th>Slowest query</th><th>Error</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($history === []): ?>
            <tr><td colspan="8" class="text-muted">No sync runs yet. The background worker will start them once it's running.</td></tr>
        <?php endif; ?>
        <?php foreach ($history as $r):
            $stats   = json_decode((string) ($r['stats'] ?? ''), true) ?: [];
            $rows    = 0;
            foreach (($stats['steps'] ?? []) as $s) { $rows += (int) ($s['rows'] ?? 0); }
            $slowest = null;
            foreach (($stats['queries'] ?? []) as $q) {
                if ($slowest === null || $q['seconds'] > $slowest['seconds']) { $slowest = $q; }
            }
            $dur = $stats['duration'] ?? (($r['started_at'] && $r['finished_at'])
                ? (strtotime($r['finished_at']) - strtotime($r['started_at'])) : null);
            $who = $r['trigger_type'] === 'manual'
                ? 'manual — ' . e(($r['display_name'] ?: $r['username']) ?? '?')
                : 'auto';
            $pill = match ($r['status']) {
                'success' => 'pill-active',
                'failed'  => 'pill-danger',
                default   => '',
            };
        ?>
            <tr>
                <td><?= (int) $r['id'] ?></td>
                <td><?= e((string) ($r['started_at'] ?? $r['created_at'])) ?></td>
                <td><?= $who ?><?= ((int) $r['override_blackout'] === 1) ? ' <span class="tag">override</span>' : '' ?></td>
                <td><span class="pill <?= $pill ?>"><?= e($r['status']) ?></span></td>
                <td><?= $dur !== null ? e((string) $dur) . ' s' : '—' ?></td>
                <td><?= $rows > 0 ? number_format($rows) : '—' ?></td>
                <td class="text-muted"><?= $slowest ? e($slowest['label']) . ' (' . $slowest['seconds'] . ' s)' : '—' ?></td>
                <td class="text-muted" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="<?= e((string) ($r['error'] ?? '')) ?>"><?= e((string) ($r['error'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

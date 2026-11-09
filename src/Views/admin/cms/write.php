<?php
/**
 * Admin → CMS writing.
 * @var string $host @var string $name @var string $user @var bool $trustCert @var bool $hasConnection
 * @var bool $liveSetting @var string $mode @var string $workstation @var bool $cmsPrograms
 * @var array<string,\PII\Cms\Write\OperationDef> $operations @var array $audit @var array $unlinked
 */
?>
<div class="alert <?= $mode === 'live' ? 'alert-warning' : 'alert-info' ?>">
    <?php if ($mode === 'live'): ?>
        <strong>Live sending is ON.</strong> Confirmed writes change CMS.
    <?php else: ?>
        <strong>Dry run.</strong> Nothing Insights does is sent to CMS. Every confirmed write is recorded below with the request it would have sent.
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Write connection</h2>
            <div class="card-subtitle">A separate SQL login used only to call <code>ProcessXMLRequest</code>. Stored in <code>config/cms.write.local.php</code> on this server, never in the database.</div>
        </div>
    </div>
    <form method="POST" action="/admin/cms/write/connection">
        <?= csrf_field() ?>
        <div class="form-grid-2">
            <div class="form-group">
                <label>Host</label>
                <input type="text" name="host" required value="<?= e($host) ?>">
            </div>
            <div class="form-group">
                <label>Database</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="CMS">
            </div>
            <div class="form-group">
                <label>Write username</label>
                <input type="text" name="user" required value="<?= e($user) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Password <?= $hasConnection ? '<span class="text-muted">(set — leave blank to keep)</span>' : '' ?></label>
                <input type="password" name="password" autocomplete="new-password" <?= $hasConnection ? '' : 'required' ?>>
            </div>
        </div>
        <div class="form-group">
            <label style="display:inline-flex;gap:0.4rem;align-items:flex-start;cursor:pointer;font-weight:400;">
                <input type="checkbox" name="trust_server_certificate" value="1" <?= $trustCert ? 'checked' : '' ?>>
                <span>Trust the server's self-signed certificate</span>
            </label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save write connection</button>
        </div>
    </form>
    <?php if ($hasConnection): ?>
        <div class="form-actions" style="margin-top:0.5rem;">
            <form method="POST" action="/admin/cms/write/test" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" class="btn">Test (sends nothing)</button>
            </form>
            <form method="POST" action="/admin/cms/write/connection/remove" style="display:inline;"
                  onsubmit="return confirm('Remove the write connection? All writes become dry-run.');">
                <?= csrf_field() ?>
                <button type="submit" class="btn">Remove</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Sending</h2>
            <div class="card-subtitle">Live sending needs a saved write connection and this switch. The CMS kill switch and backup blackouts stop writes too.</div>
        </div>
    </div>
    <form method="POST" action="/admin/cms/write/settings">
        <?= csrf_field() ?>
        <div class="form-group">
            <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;">
                <input type="checkbox" name="live_enabled" value="1" <?= $liveSetting ? 'checked' : '' ?> <?= $hasConnection ? '' : 'disabled' ?>>
                <span>Send confirmed writes to CMS (live)</span>
            </label>
        </div>
        <div class="form-grid-2">
            <div class="form-group">
                <label>Workstation name on requests</label>
                <input type="text" name="workstation" value="<?= e($workstation) ?>" maxlength="30">
            </div>
            <div class="form-group">
                <label>Program name on requests</label>
                <select name="program_mode">
                    <option value="insights" <?= $cmsPrograms ? '' : 'selected' ?>>Insights module name (default)</option>
                    <option value="cms" <?= $cmsPrograms ? 'selected' : '' ?>>Same name the CMS client uses</option>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>

<?php if ($unlinked !== []): ?>
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Users without a CMS user name</h2>
            <div class="card-subtitle">Writes are sent under the person's own CMS user. These accounts cannot write until one is set under Users.</div>
        </div>
    </div>
    <p><?= e(implode(', ', array_map(static fn ($u) => $u['display_name'] ?: $u['username'], $unlinked))) ?></p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Allowed operations</h2>
            <div class="card-subtitle">Insights can only send these. Each has its own permission in Users &amp; groups.</div>
        </div>
    </div>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Operation</th><th>Module</th><th>Permission</th><th>CMS action</th></tr></thead>
            <tbody>
            <?php foreach ($operations as $op): ?>
                <tr>
                    <td><?= e(ucfirst($op->label)) ?></td>
                    <td><?= e($op->module) ?></td>
                    <td><code><?= e($op->permission) ?></code></td>
                    <td class="text-muted"><?= e(implode(', ', $op->cmsActions)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Write log</h2>
            <div class="card-subtitle">Latest <?= count($audit) ?> requests, newest first.</div>
        </div>
    </div>
    <?php if ($audit === []): ?>
        <div class="empty-state">Nothing has been written yet.</div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>When</th><th>Who</th><th>Operation</th><th>Record</th><th>Mode</th><th>Result</th><th>CMS log</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($audit as $a): ?>
                <tr>
                    <td class="nowrap"><?= e(fmt_date($a['created_at'], 'M j, g:i:s A')) ?></td>
                    <td><?= e((string) ($a['display_name'] ?: $a['username'])) ?></td>
                    <td><?= e((string) $a['operation']) ?><?= (int) $a['stage_no'] > 1 ? ' <span class="text-muted">(step ' . (int) $a['stage_no'] . ')</span>' : '' ?></td>
                    <td class="text-muted"><?= e(trim(($a['entity_type'] ?? '') . ' ' . ($a['entity_id'] ?? ''))) ?></td>
                    <td><?= e((string) $a['mode']) ?></td>
                    <td>
                        <?php if ($a['status'] === 'committed'): ?><span class="pill pill-active">saved</span>
                        <?php elseif ($a['status'] === 'dry-run'): ?><span class="pill">dry run</span>
                        <?php else: ?><span class="pill pill-danger"><?= e((string) $a['status']) ?></span>
                            <div class="text-muted" style="font-size:0.8rem;"><?= e((string) $a['message']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= $a['log_id'] !== null ? (int) $a['log_id'] : '' ?></td>
                    <td><a href="/admin/cms/write/log/<?= (int) $a['id'] ?>">request</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

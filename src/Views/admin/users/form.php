<?php
/**
 * @var array|null $user
 * @var array $groups
 * @var array $memberOf   array of group ids the user belongs to
 * @var \PII\Core\Module[] $modules
 * @var array|null $effective  PermissionService::effectiveForUser() (edit only)
 * @var array $permLabels      permission key => label (edit only)
 */
$isEdit = $user !== null;
$action = $isEdit ? '/admin/users/' . $user['id'] : '/admin/users';
?>
<div class="card">
    <form method="POST" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <div class="form-grid-2">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required autocomplete="off"
                       value="<?= e($user['username'] ?? old('username')) ?>">
            </div>
            <div class="form-group">
                <label>Display name</label>
                <input type="text" name="display_name"
                       value="<?= e($user['display_name'] ?? old('display_name')) ?>">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email"
                       value="<?= e($user['email'] ?? old('email')) ?>">
            </div>
            <div class="form-group">
                <label>Password <?= $isEdit ? '(leave blank to keep)' : '(min 8 chars)' ?></label>
                <input type="password" name="password" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
            </div>
        </div>

        <div class="form-group">
            <label>Permission groups</label>
            <div class="flex gap-2" style="flex-wrap:wrap;">
                <?php foreach ($groups as $g): ?>
                    <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;font-weight:500;">
                        <input type="checkbox" name="groups[]" value="<?= (int) $g['id'] ?>"
                               <?= in_array((int) $g['id'], array_map('intval', $memberOf), true) ? 'checked' : '' ?>>
                        <?= e($g['name']) ?>
                        <?php if ($g['is_admin']): ?><span class="pill pill-active">admin</span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ($user === null || $user['is_active']) ? 'checked' : '' ?>>
                    Active
                </label>
                <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;margin-left:1.5rem;">
                    <input type="checkbox" name="must_change_password" value="1"
                           <?= (!$isEdit || !empty($user['must_change_password'])) ? 'checked' : '' ?>>
                    Must change password at next login
                </label>
            </div>
            <div class="form-group">
                <label>Landing page override <span class="text-muted">(optional — normally the group default decides)</span></label>
                <select name="landing_module">
                    <option value="">— use group default —</option>
                    <?php foreach ($modules as $m): ?>
                        <option value="<?= e($m->key()) ?>" <?= ($user['landing_module'] ?? '') === $m->key() ? 'selected' : '' ?>>
                            <?= e($m->name()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
            <a href="/admin/users" class="btn">Cancel</a>
        </div>
    </form>

    <?php if ($isEdit && (int) $user['id'] !== (int) current_user_id()): ?>
        <div class="form-actions" style="justify-content:flex-end;border-top:1px solid rgba(128,128,128,0.25);margin-top:1rem;padding-top:1rem;">
            <form method="POST" action="<?= e('/admin/users/' . $user['id'] . '/delete') ?>"
                  onsubmit="return confirm('Delete user “<?= e(addslashes($user['username'])) ?>”? This cannot be undone.');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">Delete user</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if ($isEdit && !empty($effective)): ?>
<div class="card" style="margin-top:1rem;">
    <div class="card-header">
        <h2 class="card-title">What this user actually sees</h2>
        <div class="card-subtitle">Effective access from their group memberships<?= $effective['is_admin'] ? ' — administrator: everything' : '' ?></div>
    </div>

    <div class="form-grid-2">
        <div>
            <h3 style="margin-bottom:0.5rem;">Modules</h3>
            <?php if ($effective['visible_modules'] === [] && $effective['hidden_modules'] === []): ?>
                <p class="text-muted">No modules.</p>
            <?php endif; ?>
            <ul style="list-style:none;padding:0;margin:0;">
                <?php foreach ($effective['visible_modules'] as $name): ?>
                    <li>✔ <?= e($name) ?></li>
                <?php endforeach; ?>
                <?php foreach ($effective['hidden_modules'] as $info): ?>
                    <li class="text-muted">✖ <?= e($info['name']) ?> — hidden by group “<?= e($info['hidden_by']) ?>”</li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div>
            <h3 style="margin-bottom:0.5rem;">Permissions</h3>
            <?php if ($effective['permissions'] === []): ?>
                <p class="text-muted">None.</p>
            <?php else: ?>
                <ul style="list-style:none;padding:0;margin:0;">
                    <?php foreach ($effective['permissions'] as $perm): ?>
                        <li><?= e($permLabels[$perm] ?? $perm) ?> <span class="tag"><?= e($perm) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

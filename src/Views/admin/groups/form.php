<?php
/**
 * @var array|null $group
 * @var list<string> $grants         permission keys this group holds
 * @var list<string> $hiddenModules  module keys hidden from this group
 * @var \PII\Core\Module[] $modules
 * @var array<string,array<string,string>> $catalog  section label => (perm key => label)
 */
$isEdit = $group !== null;
$action = $isEdit ? '/admin/groups/' . $group['id'] : '/admin/groups';
?>
<div class="card">
    <form method="POST" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <div class="form-grid-2">
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" required value="<?= e($group['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" value="<?= e($group['description'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;">
                <input type="checkbox" name="is_admin" value="1" <?= ($group['is_admin'] ?? 0) ? 'checked' : '' ?>>
                Administrator group (members get full access to everything and are never hidden anything)
            </label>
        </div>

        <h3 style="margin-top:1.25rem;margin-bottom:0.5rem;">Permissions</h3>
        <p class="text-muted" style="font-size:0.85rem;margin-bottom:0.75rem;">
            A user gets the combined permissions of all their groups.
            Anything not ticked is completely invisible to members — not greyed out, gone.
        </p>

        <div class="form-grid-2">
            <?php foreach ($catalog as $section => $perms): ?>
                <div class="form-group">
                    <label style="font-weight:700;"><?= e($section) ?></label>
                    <?php foreach ($perms as $key => $label): ?>
                        <label style="display:flex;gap:0.4rem;align-items:baseline;cursor:pointer;font-weight:400;margin:0.2rem 0;">
                            <input type="checkbox" name="grants[<?= e($key) ?>]" value="1"
                                   <?= in_array($key, $grants, true) ? 'checked' : '' ?>>
                            <span><?= e($label) ?> <span class="tag"><?= e($key) ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <h3 style="margin-top:1.25rem;margin-bottom:0.5rem;">Modules</h3>
        <p class="text-muted" style="font-size:0.85rem;margin-bottom:0.75rem;">
            Hiding a module makes its existence invisible to this group's members —
            even if another of their groups grants permissions in it (hidden wins; administrators excepted).
            Permission ticks inside a hidden module are kept, so un-hiding restores them.
        </p>
        <table class="table" style="max-width:640px;">
            <thead>
                <tr><th>Module</th><th>Visibility</th></tr>
            </thead>
            <tbody>
            <?php foreach ($modules as $m): ?>
                <tr>
                    <td><strong><?= e($m->name()) ?></strong>
                        <?php if ($m->description() !== ''): ?>
                            <div class="text-muted" style="font-size:0.8rem;"><?= e($m->description()) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;">
                            <input type="checkbox" name="hidden_modules[<?= e($m->key()) ?>]" value="1"
                                   <?= in_array($m->key(), $hiddenModules, true) ? 'checked' : '' ?>>
                            Hidden from this group
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="form-group" style="max-width:640px;">
            <label>Default module <span class="text-muted">(where members land after login; must be visible and permitted)</span></label>
            <select name="default_module">
                <option value="">— Home / Dashboard —</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?= e($m->key()) ?>" <?= ($group['default_module'] ?? '') === $m->key() ? 'selected' : '' ?>>
                        <?= e($m->name()) ?>
                    </option>
                <?php endforeach; ?>
                <?php
                // A stored default for a module that hasn't shipped yet
                // (e.g. order_readiness) is kept and shown as pending.
                $stored = $group['default_module'] ?? '';
                $registered = array_map(fn($m) => $m->key(), $modules);
                if ($stored !== '' && !in_array($stored, $registered, true)): ?>
                    <option value="<?= e($stored) ?>" selected><?= e($stored) ?> (not installed yet)</option>
                <?php endif; ?>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create group' ?></button>
            <a href="/admin/groups" class="btn">Cancel</a>
        </div>
    </form>

    <?php if ($isEdit): ?>
        <div class="form-actions" style="justify-content:flex-end;border-top:1px solid rgba(128,128,128,0.25);margin-top:1rem;padding-top:1rem;">
            <form method="POST" action="<?= e('/admin/groups/' . $group['id'] . '/delete') ?>"
                  onsubmit="return confirm('Delete group “<?= e(addslashes($group['name'])) ?>”?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">Delete group</button>
            </form>
        </div>
    <?php endif; ?>
</div>

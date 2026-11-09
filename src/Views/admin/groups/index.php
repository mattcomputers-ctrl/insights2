<?php
/**
 * @var array $groups                ordered by priority
 * @var \PII\Core\Module[] $modules
 */
$moduleNames = [];
foreach ($modules as $m) {
    $moduleNames[$m->key()] = $m->name();
}
$last = count($groups) - 1;
?>
<div class="toolbar">
    <div class="flex gap-1">
        <a href="/admin/users" class="btn btn-sm">Users</a>
        <a href="/admin/groups" class="btn btn-sm btn-primary">Groups &amp; permissions</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= count($groups) ?> permission group<?= count($groups) === 1 ? '' : 's' ?></h2>
            <div class="card-subtitle">
                Order matters: for a user in several groups, the highest group in this list
                with a usable default module decides where they land after login.
            </div>
        </div>
        <a href="/admin/groups/create" class="btn btn-primary btn-sm">+ New group</a>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th style="width:90px;">Priority</th>
                <th>Name</th>
                <th>Description</th>
                <th>Default module</th>
                <th>Members</th>
                <th>Admin?</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $i => $g): ?>
            <tr>
                <td style="white-space:nowrap;">
                    <form method="POST" action="<?= e('/admin/groups/' . $g['id'] . '/move') ?>" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="dir" value="up">
                        <button type="submit" class="btn btn-sm" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
                    </form>
                    <form method="POST" action="<?= e('/admin/groups/' . $g['id'] . '/move') ?>" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="dir" value="down">
                        <button type="submit" class="btn btn-sm" title="Move down" <?= $i === $last ? 'disabled' : '' ?>>↓</button>
                    </form>
                </td>
                <td><strong><?= e($g['name']) ?></strong></td>
                <td class="text-muted"><?= e($g['description']) ?></td>
                <td>
                    <?php if (!empty($g['default_module'])): ?>
                        <?= e($moduleNames[$g['default_module']] ?? $g['default_module'] . ' (not installed yet)') ?>
                    <?php else: ?>
                        <span class="text-muted">Home</span>
                    <?php endif; ?>
                </td>
                <td><?= (int) $g['member_count'] ?></td>
                <td><?= $g['is_admin'] ? '<span class="pill pill-active">yes</span>' : '<span class="text-muted">no</span>' ?></td>
                <td class="text-right">
                    <a href="<?= e('/admin/groups/' . $g['id'] . '/edit') ?>" class="btn btn-sm">Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

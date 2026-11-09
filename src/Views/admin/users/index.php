<?php
/** @var array $users */
/** @var array $userGroups */
?>
<div class="toolbar">
    <div class="flex gap-1">
        <a href="/admin/users" class="btn btn-sm btn-primary">Users</a>
        <a href="/admin/groups" class="btn btn-sm">Groups &amp; permissions</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?></h2>
        <a href="/admin/users/create" class="btn btn-primary btn-sm">+ New user</a>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Display name</th>
                <th>Email</th>
                <th>Groups</th>
                <th>Status</th>
                <th>Last login</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $groups = $userGroups[$u['id']] ?? [];
        ?>
            <tr>
                <td><strong><?= e($u['username']) ?></strong></td>
                <td><?= e($u['display_name']) ?></td>
                <td class="text-muted"><?= e((string) ($u['email'] ?? '')) ?></td>
                <td>
                    <?php foreach ($groups as $g): ?>
                        <span class="pill <?= $g['is_admin'] ? 'pill-active' : '' ?>"><?= e($g['name']) ?></span>
                    <?php endforeach; ?>
                </td>
                <td>
                    <span class="pill <?= $u['is_active'] ? 'pill-active' : 'pill-inactive' ?>">
                        <?= $u['is_active'] ? 'active' : 'disabled' ?>
                    </span>
                </td>
                <td class="text-muted"><?= $u['last_login'] ? fmt_date($u['last_login'], 'm/d/Y H:i') : '—' ?></td>
                <td class="text-right">
                    <a href="<?= e('/admin/users/' . $u['id'] . '/edit') ?>" class="btn btn-sm">Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

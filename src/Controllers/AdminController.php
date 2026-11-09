<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\App;
use PII\Core\CSRF;
use PII\Core\Database;
use PII\Models\User;
use PII\Services\PermissionCatalog;
use PII\Services\PermissionService;

require_once dirname(__DIR__) . '/Helpers/layout.php';

class AdminController
{
    /* ------------------------------------------------------------------
     *  Guards and audit helpers
     * ----------------------------------------------------------------*/

    /** Count active admin users other than $excludeUserId. */
    private function otherActiveAdmins(int $excludeUserId): int
    {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(DISTINCT u.id) AS n
             FROM users u
             JOIN user_group_members ugm ON ugm.user_id = u.id
             JOIN permission_groups pg   ON pg.id = ugm.group_id
             WHERE pg.is_admin = 1 AND u.is_active = 1 AND u.id <> ?",
            [$excludeUserId]
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Throw if applying $newActive/$newGroupIds to $userId would leave the
     * system without a single active administrator (spec §2.3: the
     * bootstrap admin can only be demoted/deactivated by ANOTHER admin —
     * and never the last one).
     */
    private function assertAdminRemains(int $userId, bool $newActive, array $newGroupIds): void
    {
        if (!PermissionService::isAdminUser($userId)) {
            return; // target isn't an admin; can't reduce admin coverage
        }
        $staysAdmin = false;
        if ($newActive && $newGroupIds !== []) {
            $in  = implode(',', array_fill(0, count($newGroupIds), '?'));
            $row = Database::getInstance()->fetch(
                "SELECT 1 FROM permission_groups WHERE is_admin = 1 AND id IN ({$in}) LIMIT 1",
                array_map('intval', $newGroupIds)
            );
            $staysAdmin = (bool) $row;
        }
        if (!$staysAdmin && $this->otherActiveAdmins($userId) === 0) {
            throw new \RuntimeException(
                'This change would leave the system with no active administrator.'
            );
        }
    }

    private function audit(string $entityType, ?string $entityId, string $action, array $details): void
    {
        Database::getInstance()->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'details'     => json_encode($details, JSON_UNESCAPED_SLASHES),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /* ------------------------------------------------------------------
     *  Users
     * ----------------------------------------------------------------*/

    public function users(): void
    {
        $users = User::all();
        $userGroups = [];
        foreach ($users as $u) {
            $userGroups[$u['id']] = User::getGroups((int) $u['id']);
        }

        layout('admin/users/index', [
            'pageTitle' => 'Users',
            'users'     => $users,
            'userGroups' => $userGroups,
        ]);
    }

    public function createUser(): void
    {
        $groups = Database::getInstance()->fetchAll(
            "SELECT id, name, is_admin FROM permission_groups ORDER BY priority, name"
        );

        layout('admin/users/form', [
            'pageTitle' => 'New user',
            'user'      => null,
            'groups'    => $groups,
            'memberOf'  => [],
            'modules'   => App::modules()->all(),
            'effective' => null,
        ]);
    }

    public function storeUser(): void
    {
        CSRF::validateRequest();

        try {
            $id = User::create([
                'username'             => $_POST['username']     ?? '',
                'email'                => $_POST['email']        ?? '',
                'display_name'         => $_POST['display_name'] ?? '',
                'cms_user'             => $_POST['cms_user']     ?? '',
                'password'             => $_POST['password']     ?? '',
                'is_active'            => isset($_POST['is_active']) ? 1 : 0,
                'must_change_password' => isset($_POST['must_change_password']) ? 1 : 0,
                'landing_module'       => $this->validLandingModule($_POST['landing_module'] ?? ''),
            ]);
            $groupIds = $_POST['groups'] ?? [];
            User::setGroups($id, is_array($groupIds) ? $groupIds : []);
            $this->audit('user', (string) $id, 'create', [
                'username' => $_POST['username'] ?? '',
                'groups'   => array_values(is_array($groupIds) ? $groupIds : []),
            ]);
            $_SESSION['_flash']['success'] = 'User created.';
            redirect('/admin/users');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/admin/users/create');
        }
    }

    public function editUser(string $id): void
    {
        $user = User::findById((int) $id);
        if (!$user) {
            $_SESSION['_flash']['error'] = 'User not found.';
            redirect('/admin/users');
        }
        $groups   = Database::getInstance()->fetchAll("SELECT id, name, is_admin FROM permission_groups ORDER BY priority, name");
        $memberOf = array_column(User::getGroups((int) $id), 'id');

        layout('admin/users/form', [
            'pageTitle' => 'Edit user — ' . ($user['display_name'] ?: $user['username']),
            'user'      => $user,
            'groups'    => $groups,
            'memberOf'  => $memberOf,
            'modules'   => App::modules()->all(),
            'effective' => PermissionService::effectiveForUser((int) $id),
            'permLabels' => PermissionCatalog::all(),
        ]);
    }

    public function updateUser(string $id): void
    {
        CSRF::validateRequest();
        $userId = (int) $id;

        try {
            $before    = User::findById($userId) ?? [];
            $beforeGrp = array_column(User::getGroups($userId), 'id');

            $newActive = isset($_POST['is_active']);
            $groupIds  = $_POST['groups'] ?? [];
            $groupIds  = array_map('intval', is_array($groupIds) ? $groupIds : []);
            $this->assertAdminRemains($userId, $newActive, $groupIds);

            $data = [
                'username'             => $_POST['username']     ?? null,
                'email'                => $_POST['email']        ?? null,
                'display_name'         => $_POST['display_name'] ?? null,
                'cms_user'             => $_POST['cms_user']     ?? '',
                'is_active'            => $newActive ? 1 : 0,
                'must_change_password' => isset($_POST['must_change_password']) ? 1 : 0,
                'landing_module'       => $this->validLandingModule($_POST['landing_module'] ?? ''),
            ];
            // Skip blank field updates
            foreach (['username', 'display_name'] as $k) {
                if ($data[$k] === null || trim($data[$k]) === '') {
                    unset($data[$k]);
                }
            }
            if (!empty($_POST['password'])) {
                $data['password'] = $_POST['password'];
            }

            User::updateUser($userId, $data);
            User::setGroups($userId, $groupIds);
            PermissionService::flushMemo();

            $this->audit('user', (string) $userId, 'update', [
                'before' => [
                    'is_active' => $before['is_active'] ?? null,
                    'groups'    => $beforeGrp,
                    'landing'   => $before['landing_module'] ?? null,
                ],
                'after' => [
                    'is_active' => $newActive ? 1 : 0,
                    'groups'    => $groupIds,
                    'landing'   => $data['landing_module'] ?? null,
                    'password_reset'  => !empty($_POST['password']),
                    'must_change_pwd' => isset($_POST['must_change_password']),
                ],
            ]);

            $_SESSION['_flash']['success'] = 'User updated.';
            redirect('/admin/users');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect('/admin/users/' . $userId . '/edit');
        }
    }

    public function deleteUser(string $id): void
    {
        CSRF::validateRequest();
        $userId = (int) $id;
        $current = current_user_id();
        if ($userId === $current) {
            $_SESSION['_flash']['error'] = 'You cannot delete your own account.';
            redirect('/admin/users');
        }
        try {
            $this->assertAdminRemains($userId, false, []);
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect('/admin/users');
        }
        $before = User::findById($userId);
        User::delete($userId);
        $this->audit('user', (string) $userId, 'delete', ['username' => $before['username'] ?? null]);
        $_SESSION['_flash']['success'] = 'User deleted.';
        redirect('/admin/users');
    }

    /** Landing override must name a registered module (or be empty). */
    private function validLandingModule(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        return App::modules()->get($key) !== null ? $key : null;
    }

    /* ------------------------------------------------------------------
     *  Groups
     * ----------------------------------------------------------------*/

    public function groups(): void
    {
        $groups = Database::getInstance()->fetchAll(
            "SELECT pg.id, pg.name, pg.description, pg.is_admin, pg.priority, pg.default_module,
                    (SELECT COUNT(*) FROM user_group_members ugm WHERE ugm.group_id = pg.id) AS member_count
             FROM permission_groups pg
             ORDER BY pg.priority, pg.id"
        );

        layout('admin/groups/index', [
            'pageTitle' => 'Permission groups',
            'groups'    => $groups,
            'modules'   => App::modules()->all(),
        ]);
    }

    public function createGroup(): void
    {
        layout('admin/groups/form', [
            'pageTitle'     => 'New permission group',
            'group'         => null,
            'grants'        => [],
            'hiddenModules' => [],
            'modules'       => App::modules()->all(),
            'catalog'       => PermissionCatalog::grouped(),
        ]);
    }

    public function storeGroup(): void
    {
        CSRF::validateRequest();
        $db   = Database::getInstance();
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $_SESSION['_flash']['error'] = 'Group name is required.';
            redirect('/admin/groups/create');
        }
        try {
            $id = (int) $db->insert('permission_groups', [
                'name'           => $name,
                'description'    => trim($_POST['description'] ?? ''),
                'is_admin'       => isset($_POST['is_admin']) ? 1 : 0,
                'priority'       => 1 + (int) ($db->fetch("SELECT MAX(priority) AS p FROM permission_groups")['p'] ?? 0),
                'default_module' => null,
            ]);
            $this->saveGroupConfig($id, null);
            $_SESSION['_flash']['success'] = 'Group created.';
            redirect('/admin/groups');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect('/admin/groups/create');
        }
    }

    public function editGroup(string $id): void
    {
        $db = Database::getInstance();
        $group = $db->fetch("SELECT * FROM permission_groups WHERE id = ?", [(int) $id]);
        if (!$group) {
            $_SESSION['_flash']['error'] = 'Group not found.';
            redirect('/admin/groups');
        }

        $grants = array_column($db->fetchAll(
            "SELECT permission FROM group_permission_grants WHERE group_id = ?", [(int) $id]
        ), 'permission');

        $hiddenModules = array_column($db->fetchAll(
            "SELECT module_key FROM group_module_visibility WHERE group_id = ? AND is_hidden = 1", [(int) $id]
        ), 'module_key');

        layout('admin/groups/form', [
            'pageTitle'     => 'Edit group — ' . $group['name'],
            'group'         => $group,
            'grants'        => $grants,
            'hiddenModules' => $hiddenModules,
            'modules'       => App::modules()->all(),
            'catalog'       => PermissionCatalog::grouped(),
        ]);
    }

    public function updateGroup(string $id): void
    {
        CSRF::validateRequest();
        $db    = Database::getInstance();
        $gid   = (int) $id;
        $group = $db->fetch("SELECT * FROM permission_groups WHERE id = ?", [$gid]);
        if (!$group) {
            $_SESSION['_flash']['error'] = 'Group not found.';
            redirect('/admin/groups');
        }

        // Demoting an admin group must not strand the system without admins.
        if ((int) $group['is_admin'] === 1 && !isset($_POST['is_admin'])) {
            $stillAdmin = $db->fetch(
                "SELECT COUNT(DISTINCT u.id) AS n
                 FROM users u
                 JOIN user_group_members ugm ON ugm.user_id = u.id
                 JOIN permission_groups pg   ON pg.id = ugm.group_id
                 WHERE pg.is_admin = 1 AND pg.id <> ? AND u.is_active = 1",
                [$gid]
            );
            if ((int) ($stillAdmin['n'] ?? 0) === 0) {
                $_SESSION['_flash']['error'] = 'This change would leave the system with no active administrator.';
                redirect('/admin/groups/' . $gid . '/edit');
            }
        }

        $db->update('permission_groups', [
            'name'        => trim($_POST['name'] ?? $group['name']),
            'description' => trim($_POST['description'] ?? ''),
            'is_admin'    => isset($_POST['is_admin']) ? 1 : 0,
        ], 'id = ?', [$gid]);

        $this->saveGroupConfig($gid, $group);
        $_SESSION['_flash']['success'] = 'Group updated.';
        redirect('/admin/groups');
    }

    /**
     * POST /admin/groups/{id}/move (dir=up|down) — swap priority with the
     * neighbouring group. Priority decides whose default module wins for
     * users in several groups (lower number = higher priority).
     */
    public function moveGroup(string $id): void
    {
        CSRF::validateRequest();
        $db  = Database::getInstance();
        $gid = (int) $id;
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';

        $me = $db->fetch("SELECT id, priority FROM permission_groups WHERE id = ?", [$gid]);
        if ($me) {
            $op    = $dir === 'up' ? '<' : '>';
            $order = $dir === 'up' ? 'DESC' : 'ASC';
            $other = $db->fetch(
                "SELECT id, priority FROM permission_groups
                 WHERE priority {$op} ? OR (priority = ? AND id {$op} ?)
                 ORDER BY priority {$order}, id {$order} LIMIT 1",
                [(int) $me['priority'], (int) $me['priority'], $gid]
            );
            if ($other) {
                // Swap; nudge apart if equal so the order is stable.
                $a = (int) $me['priority'];
                $b = (int) $other['priority'];
                if ($a === $b) {
                    $b += ($dir === 'up' ? 1 : -1);
                }
                $db->update('permission_groups', ['priority' => $b], 'id = ?', [$gid]);
                $db->update('permission_groups', ['priority' => $a], 'id = ?', [(int) $other['id']]);
                $this->audit('group', (string) $gid, 'move', ['dir' => $dir]);
            }
        }
        redirect('/admin/groups');
    }

    public function deleteGroup(string $id): void
    {
        CSRF::validateRequest();
        $db  = Database::getInstance();
        $gid = (int) $id;
        $group = $db->fetch("SELECT * FROM permission_groups WHERE id = ?", [$gid]);
        if (!$group) {
            redirect('/admin/groups');
        }

        if ((int) $group['is_admin'] === 1) {
            $otherAdmins = $db->fetch(
                "SELECT COUNT(DISTINCT u.id) AS n
                 FROM users u
                 JOIN user_group_members ugm ON ugm.user_id = u.id
                 JOIN permission_groups pg   ON pg.id = ugm.group_id
                 WHERE pg.is_admin = 1 AND pg.id <> ? AND u.is_active = 1",
                [$gid]
            );
            if ((int) ($otherAdmins['n'] ?? 0) === 0) {
                $_SESSION['_flash']['error'] = 'Deleting this group would leave the system with no active administrator.';
                redirect('/admin/groups');
            }
        }

        $db->delete('permission_groups', 'id = ?', [$gid]);
        $this->audit('group', (string) $gid, 'delete', ['name' => $group['name']]);
        $_SESSION['_flash']['success'] = 'Group deleted.';
        redirect('/admin/groups');
    }

    /**
     * Save a group's permission grants, module visibility and default
     * module from the submitted form, with a before/after audit entry.
     *
     * Grants: only permissions the catalog currently knows are touched —
     * grants seeded for future modules (e.g. Order Readiness before it
     * ships) are preserved untouched so they light up when the module
     * registers.
     */
    private function saveGroupConfig(int $groupId, ?array $before): void
    {
        $db = Database::getInstance();

        $known   = array_keys(PermissionCatalog::all());
        $checked = $_POST['grants'] ?? [];
        $checked = array_values(array_intersect(
            is_array($checked) ? array_map('strval', array_keys($checked)) : [],
            $known
        ));

        $beforeGrants = array_column($db->fetchAll(
            "SELECT permission FROM group_permission_grants WHERE group_id = ?", [$groupId]
        ), 'permission');
        $beforeHidden = array_column($db->fetchAll(
            "SELECT module_key FROM group_module_visibility WHERE group_id = ? AND is_hidden = 1", [$groupId]
        ), 'module_key');

        $db->beginTransaction();
        try {
            if ($known !== []) {
                $in = implode(',', array_fill(0, count($known), '?'));
                $db->delete('group_permission_grants', "group_id = ? AND permission IN ({$in})", array_merge([$groupId], $known));
            }
            foreach ($checked as $perm) {
                $db->insert('group_permission_grants', ['group_id' => $groupId, 'permission' => $perm]);
            }

            // Module visibility: replace rows for registered modules.
            $moduleKeys = array_map(fn($m) => $m->key(), App::modules()->all());
            $hiddenPost = $_POST['hidden_modules'] ?? [];
            $hiddenPost = is_array($hiddenPost) ? array_map('strval', array_keys($hiddenPost)) : [];
            foreach ($moduleKeys as $mk) {
                $db->delete('group_module_visibility', 'group_id = ? AND module_key = ?', [$groupId, $mk]);
                if (in_array($mk, $hiddenPost, true)) {
                    $db->insert('group_module_visibility', ['group_id' => $groupId, 'module_key' => $mk, 'is_hidden' => 1]);
                }
            }

            // Default module: must be registered, not hidden from this
            // group, and covered by the group's grants (admins exempt).
            $default = trim((string) ($_POST['default_module'] ?? ''));
            $valid   = null;
            if ($default !== '') {
                $module = App::modules()->get($default);
                $isAdminGroup = isset($_POST['is_admin']);
                if ($module !== null
                    && !in_array($default, $hiddenPost, true)
                    && ($isAdminGroup || in_array($module->permissionKey(), $checked, true))
                ) {
                    $valid = $default;
                } elseif ($module === null && $default !== '') {
                    // Not registered yet (e.g. order_readiness before step 7):
                    // keep the stored value so it activates when the module ships.
                    $valid = $default;
                }
            }
            $db->update('permission_groups', ['default_module' => $valid], 'id = ?', [$groupId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        PermissionService::flushMemo();

        $this->audit('group', (string) $groupId, $before === null ? 'create' : 'update', [
            'before' => $before === null ? null : [
                'grants' => $beforeGrants,
                'hidden' => $beforeHidden,
                'default_module' => $before['default_module'] ?? null,
                'is_admin' => $before['is_admin'] ?? null,
            ],
            'after' => [
                'grants' => $checked,
                'hidden' => $hiddenPost,
                'default_module' => $valid,
                'is_admin' => isset($_POST['is_admin']) ? 1 : 0,
            ],
        ]);
    }

    /* ------------------------------------------------------------------
     *  System settings
     * ----------------------------------------------------------------*/

    public function settings(): void
    {
        $db = Database::getInstance();

        // Pull current overrides; if absent, fall through to config defaults
        // when displaying — this lets admins know which fields are using config
        // vs DB-overridden values without surprising them.
        $overrides = [];
        foreach ($db->fetchAll("SELECT `key`, `value` FROM settings") as $row) {
            $overrides[$row['key']] = (string) ($row['value'] ?? '');
        }

        // Inventory snapshot status for the manual-trigger UI
        $snapshotInfo = $db->fetch(
            "SELECT MAX(snapshot_date) AS latest, MIN(snapshot_date) AS earliest,
                    COUNT(DISTINCT snapshot_date) AS days_captured
               FROM inventory_snapshots"
        ) ?: [];

        // Version + update/backup state (SPEC §5 Admin → System)
        $base = App::basePath();
        $version = trim((string) @shell_exec(
            'git -C ' . escapeshellarg($base) . ' log -1 --format="%h · %ad · %s" --date=format:"%Y-%m-%d %H:%M" 2>/dev/null'
        ));
        $updateLog = '';
        $logFile = $base . '/storage/logs/update.log';
        if (file_exists($logFile)) {
            $lines = file($logFile) ?: [];
            $updateLog = implode('', array_slice($lines, -30));
        }
        $backups = [];
        foreach (glob('/var/backups/insights/insights-*.sql.gz') ?: [] as $f) {
            $backups[] = ['file' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
        }
        usort($backups, fn($a, $b) => $b['time'] <=> $a['time']);

        layout('admin/settings/index', [
            'pageTitle'     => 'System settings',
            'appNameValue'  => $overrides['app.name'] ?? '',
            'configAppName' => (string) App::config('app.name', 'Precision Ink Insights'),
            'snapshotInfo'  => $snapshotInfo,
            'version'       => $version !== '' ? $version : 'unknown (not a git checkout?)',
            'updateLog'     => $updateLog,
            'backups'       => array_slice($backups, 0, 10),
            'helperReady'   => is_file('/usr/local/sbin/insights-helper'),
        ]);
    }

    /** POST /admin/settings/update-now — run the updater via the helper, in the background. */
    public function updateNow(): void
    {
        CSRF::validateRequest();
        $log = App::basePath() . '/storage/logs/update.log';
        if (!is_file('/usr/local/sbin/insights-helper')) {
            $_SESSION['_flash']['error'] = 'The privileged helper is not installed yet — run install.sh once on the server.';
            redirect('/admin/settings');
        }
        @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] Update requested from the web by " . (current_user()['username'] ?? '?') . "\n", FILE_APPEND);
        shell_exec('nohup sudo /usr/local/sbin/insights-helper update >> ' . escapeshellarg($log) . ' 2>&1 &');
        $this->audit('system', null, 'update_now', []);
        $_SESSION['_flash']['success'] = 'Update started — the log below refreshes when you reload this page.';
        redirect('/admin/settings');
    }

    /** POST /admin/settings/backup-now */
    public function backupNow(): void
    {
        CSRF::validateRequest();
        if (!is_file('/usr/local/sbin/insights-helper')) {
            $_SESSION['_flash']['error'] = 'The privileged helper is not installed yet — run install.sh once on the server.';
            redirect('/admin/settings');
        }
        $out = (string) shell_exec('sudo /usr/local/sbin/insights-helper backup 2>&1');
        $this->audit('system', null, 'backup_now', ['output' => trim($out)]);
        $_SESSION['_flash'][str_contains($out, 'Backup written') ? 'success' : 'error'] = trim($out) ?: 'No output from the backup helper.';
        redirect('/admin/settings');
    }

    /** GET /admin/settings/download-backup — stream the newest backup. */
    public function downloadBackup(): void
    {
        $files = glob('/var/backups/insights/insights-*.sql.gz') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        if ($files === [] || !is_readable($files[0])) {
            $_SESSION['_flash']['error'] = 'No readable backup found.';
            redirect('/admin/settings');
        }
        $this->audit('system', null, 'download_backup', ['file' => basename($files[0])]);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($files[0]) . '"');
        header('Content-Length: ' . filesize($files[0]));
        readfile($files[0]);
        exit;
    }

    /* ------------------------------------------------------------------
     *  Network (SPEC §5 Admin → Network)
     * ----------------------------------------------------------------*/

    public function network(): void
    {
        require_permission('admin.network');
        $interfaces = [];
        foreach (explode("\n", (string) @shell_exec('ip -o -4 addr show 2>/dev/null')) as $line) {
            if (preg_match('/^\d+:\s+(\S+)\s+inet\s+(\S+)/', trim($line), $m) && $m[1] !== 'lo') {
                $interfaces[] = ['name' => $m[1], 'addr' => $m[2]];
            }
        }
        layout('admin/network/index', [
            'pageTitle'   => 'Network',
            'interfaces'  => $interfaces,
            'helperReady' => is_file('/usr/local/sbin/insights-helper'),
        ]);
    }

    public function saveNetwork(): void
    {
        require_permission('admin.network');
        CSRF::validateRequest();

        $iface = trim((string) ($_POST['iface'] ?? ''));
        $cidr  = trim((string) ($_POST['cidr'] ?? ''));
        $gw    = trim((string) ($_POST['gateway'] ?? ''));
        $dns   = preg_split('/[\s,]+/', trim((string) ($_POST['dns'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (!preg_match('/^[a-z0-9]+$/', $iface) || !preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $cidr)
            || !preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $gw) || $dns === []) {
            $_SESSION['_flash']['error'] = 'Check the values — interface, IP/CIDR, gateway and at least one DNS server are required.';
            redirect('/admin/network');
        }

        $this->audit('network', $iface, 'set', ['cidr' => $cidr, 'gw' => $gw, 'dns' => $dns]);

        // The connection will drop when the address changes — run detached.
        $cmd = 'nohup sudo /usr/local/sbin/insights-helper set-network '
             . escapeshellarg($iface) . ' ' . escapeshellarg($cidr) . ' ' . escapeshellarg($gw);
        foreach ($dns as $d) {
            $cmd .= ' ' . escapeshellarg($d);
        }
        $log = App::basePath() . '/storage/logs/network.log';
        shell_exec($cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 &');

        $ip = explode('/', $cidr)[0];
        $_SESSION['_flash']['warning'] = "Network change submitted. In ~10 seconds the server moves to {$ip} — reconnect your browser at http://{$ip}/ .";
        redirect('/admin/network');
    }

    /**
     * Kick off a snapshot run in the background. Returns immediately so
     * the click responds instantly even though the underlying CMS query
     * takes 45+ seconds (or 25 minutes for a 30-day backfill).
     */
    public function runInventorySnapshot(): void
    {
        CSRF::validateRequest();
        $this->spawnSnapshot([], 'manual_yesterday');
        $_SESSION['_flash']['success'] = 'Snapshot for yesterday started in the background. Refresh in about a minute.';
        redirect('/admin/settings');
    }

    public function backfillInventorySnapshots(): void
    {
        CSRF::validateRequest();
        $this->spawnSnapshot(['--backfill-days=30'], 'manual_backfill_30');
        $_SESSION['_flash']['success'] = '30-day backfill started in the background. This usually takes about 25 minutes — you can keep using the system while it runs. The progress bar below will update as it goes.';
        redirect('/admin/settings');
    }

    /**
     * GET /admin/settings/snapshot-status (JSON)
     *
     * Returns the current state of any in-flight or recently-finished
     * snapshot run. Polled by the settings-page progress bar.
     *
     * State machine:
     *   - "idle"     — no status file (nothing has run since boot)
     *   - "running"  — script is actively writing heartbeats (fresh < 90s)
     *   - "finished" — script wrote finished_at and exited cleanly
     *   - "stale"    — heartbeat is older than 90s and never finished
     *                  (likely the process was killed)
     */
    public function snapshotStatus(): void
    {
        // Polled every 3-5 seconds — make sure no caching layer between
        // here and the browser holds onto a stale answer.
        header('Cache-Control: no-store, must-revalidate');
        header('Pragma: no-cache');

        $statusFile = App::basePath() . '/storage/cache/snapshot-status.json';
        if (!file_exists($statusFile)) {
            json_response(['state' => 'idle']);
        }

        $raw = @file_get_contents($statusFile);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            json_response(['state' => 'idle']);
        }

        $state = 'running';
        if (!empty($data['finished_at'])) {
            $state = 'finished';
        } elseif (!empty($data['heartbeat'])) {
            $heartbeatAge = time() - strtotime($data['heartbeat']);
            if ($heartbeatAge > 90) {
                $state = 'stale';
            }
        }

        $total     = max(1, (int) ($data['total_dates'] ?? 0));   // avoid div-by-zero
        $completed = (int) ($data['completed_dates'] ?? 0);
        $progress  = $total > 0 ? min(100, ($completed / $total) * 100) : 0;

        json_response([
            'state'        => $state,
            'progress_pct' => round($progress, 1),
            'data'         => $data,
        ]);
    }

    /**
     * Spawn cron/snapshot-inventory.php as a detached background process.
     * Stdout + stderr go to the same log the cron job uses. Falls back to
     * a synchronous run if exec() is disabled (rare but possible on
     * locked-down PHP installs).
     *
     * @param list<string> $extraArgs CLI args to pass through (e.g. --backfill-days=30)
     */
    private function spawnSnapshot(array $extraArgs, string $auditEntityId): void
    {
        $base       = App::basePath();
        $scriptPath = $base . '/cron/snapshot-inventory.php';
        $logPath    = $base . '/storage/logs/snapshot-inventory.log';

        $argString = '';
        foreach ($extraArgs as $a) {
            $argString .= ' ' . escapeshellarg($a);
        }
        $cmd = sprintf(
            'nohup php %s%s >> %s 2>&1 &',
            escapeshellarg($scriptPath),
            $argString,
            escapeshellarg($logPath)
        );

        if (function_exists('exec')) {
            exec($cmd);
        }

        Database::getInstance()->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => 'inventory_snapshot',
            'entity_id'   => $auditEntityId,
            'action'      => 'run',
            'details'     => json_encode(['args' => $extraArgs]),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    public function saveSettings(): void
    {
        CSRF::validateRequest();
        $db = Database::getInstance();

        $appName = trim((string) ($_POST['app_name'] ?? ''));

        // Empty value → DELETE the override so the config default takes over.
        if ($appName === '') {
            $db->delete('settings', '`key` = ?', ['app.name']);
        } else {
            $existing = $db->fetch("SELECT 1 FROM settings WHERE `key` = ?", ['app.name']);
            if ($existing) {
                $db->update('settings', ['value' => $appName], '`key` = ?', ['app.name']);
            } else {
                $db->insert('settings', ['key' => 'app.name', 'value' => $appName]);
            }
        }

        $db->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => 'settings',
            'entity_id'   => 'app.name',
            'action'      => 'update',
            'details'     => json_encode(['new_value' => $appName === '' ? '(cleared — using config default)' : $appName]),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $_SESSION['_flash']['success'] = 'Settings saved.';
        redirect('/admin/settings');
    }

    /**
     * COA settings (Matt, 2026-11-04): company logo + disclaimer printed
     * on every Certificate of Analysis.
     */
    public function saveCoaSettings(): void
    {
        CSRF::validateRequest();

        \PII\Services\Settings::set('coa.disclaimer', trim((string) ($_POST['coa_disclaimer'] ?? '')));

        $uploadDir = App::basePath() . '/public/uploads';
        if (($_POST['remove_logo'] ?? '') === '1') {
            foreach (glob($uploadDir . '/coa-logo.*') ?: [] as $f) {
                @unlink($f);
            }
            \PII\Services\Settings::set('coa.logo', '');
        } elseif (!empty($_FILES['coa_logo']['tmp_name']) && is_uploaded_file($_FILES['coa_logo']['tmp_name'])) {
            $imageInfo = @getimagesize($_FILES['coa_logo']['tmp_name']);
            $extByType = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
            if ($imageInfo === false || !isset($extByType[$imageInfo[2]])) {
                $_SESSION['_flash']['error'] = 'The logo must be a PNG, JPG, GIF or WEBP image.';
                redirect('/admin/settings');
            }
            if (($_FILES['coa_logo']['size'] ?? 0) > 2 * 1024 * 1024) {
                $_SESSION['_flash']['error'] = 'The logo must be under 2 MB.';
                redirect('/admin/settings');
            }
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }
            foreach (glob($uploadDir . '/coa-logo.*') ?: [] as $f) {
                @unlink($f);
            }
            $ext = $extByType[$imageInfo[2]];
            move_uploaded_file($_FILES['coa_logo']['tmp_name'], $uploadDir . '/coa-logo.' . $ext);
            \PII\Services\Settings::set('coa.logo', '/uploads/coa-logo.' . $ext . '?v=' . time());
        }

        Database::getInstance()->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => 'settings',
            'entity_id'   => 'coa',
            'action'      => 'update',
            'details'     => json_encode(['logo' => \PII\Services\Settings::get('coa.logo')]),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $_SESSION['_flash']['success'] = 'COA settings saved.';
        redirect('/admin/settings');
    }

    /** Hidden shared-component reference page (SPEC §4). */
    public function uiKit(): void
    {
        layout('admin/ui-kit', ['pageTitle' => 'UI kit']);
    }

    /* ------------------------------------------------------------------
     *  Audit log
     * ----------------------------------------------------------------*/

    public function auditLog(): void
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT al.id, al.user_id, al.entity_type, al.entity_id, al.action,
                    al.details, al.ip_address, al.created_at, u.username
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.id DESC
             LIMIT 500"
        );

        layout('admin/audit/index', [
            'pageTitle' => 'Audit log',
            'rows'      => $rows,
        ]);
    }
}

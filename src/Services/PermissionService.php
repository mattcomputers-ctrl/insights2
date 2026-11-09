<?php

declare(strict_types=1);

namespace PII\Services;

use PII\Core\App;
use PII\Core\Database;
use PII\Core\Module;

/**
 * PermissionService — named permissions, granted per group.
 *
 * A user's permissions are the UNION of their groups' grants
 * (group_permission_grants). Members of any admin group bypass all
 * checks and always see every module.
 *
 * Module visibility (group_module_visibility) is separate from
 * permissions: if ANY of a user's groups hides a module, the module is
 * invisible to that user — "hidden beats granted" — admins excepted.
 *
 * Checks are per-request against the database (no long-lived caching),
 * so revoking access applies on the user's next click. Per-request
 * memoisation only.
 */
class PermissionService
{
    /** @var array<int,array<string,bool>> per-request memo: userId => perm => bool */
    private static array $canMemo = [];
    /** @var array<int,bool> */
    private static array $adminMemo = [];

    public static function isAdminUser(int $userId): bool
    {
        if (isset(self::$adminMemo[$userId])) {
            return self::$adminMemo[$userId];
        }
        $row = Database::getInstance()->fetch(
            "SELECT 1
             FROM user_group_members ugm
             JOIN permission_groups pg ON pg.id = ugm.group_id
             WHERE ugm.user_id = ? AND pg.is_admin = 1
             LIMIT 1",
            [$userId]
        );
        return self::$adminMemo[$userId] = (bool) $row;
    }

    /** Does the user hold this permission (via any group)? */
    public static function can(int $userId, string $permission): bool
    {
        if (self::isAdminUser($userId)) {
            return true;
        }
        if (isset(self::$canMemo[$userId][$permission])) {
            return self::$canMemo[$userId][$permission];
        }
        $row = Database::getInstance()->fetch(
            "SELECT 1
             FROM group_permission_grants gpg
             JOIN user_group_members ugm ON ugm.group_id = gpg.group_id
             WHERE ugm.user_id = ? AND gpg.permission = ?
             LIMIT 1",
            [$userId, $permission]
        );
        return self::$canMemo[$userId][$permission] = (bool) $row;
    }

    /** All of the given permissions. */
    public static function canAll(int $userId, string ...$permissions): bool
    {
        foreach ($permissions as $p) {
            if (!self::can($userId, $p)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Name of a group that hides this module from the user, or null if
     * none does. Admins are never hidden anything.
     */
    public static function moduleHiddenBy(int $userId, string $moduleKey): ?string
    {
        if (self::isAdminUser($userId)) {
            return null;
        }
        $row = Database::getInstance()->fetch(
            "SELECT pg.name
             FROM group_module_visibility gmv
             JOIN user_group_members ugm ON ugm.group_id = gmv.group_id
             JOIN permission_groups pg   ON pg.id = gmv.group_id
             WHERE ugm.user_id = ? AND gmv.module_key = ? AND gmv.is_hidden = 1
             LIMIT 1",
            [$userId, $moduleKey]
        );
        return $row['name'] ?? null;
    }

    /**
     * Can the user see this module at all? (Not hidden by any group, and
     * holds the module's view permission.) This is the test for nav,
     * Home tiles, search — and the middleware's 404 gate.
     */
    public static function canSeeModule(int $userId, Module $module): bool
    {
        if (self::isAdminUser($userId)) {
            return true;
        }
        if (self::moduleHiddenBy($userId, $module->key()) !== null) {
            return false;
        }
        return self::can($userId, $module->permissionKey());
    }

    /** @return Module[] modules the user may see, in display order */
    public static function visibleModules(int $userId): array
    {
        $out = [];
        foreach (App::modules()->all() as $module) {
            if (self::canSeeModule($userId, $module)) {
                $out[] = $module;
            }
        }
        return $out;
    }

    /**
     * Where this user lands after login / clicking the logo:
     *   1. their own landing_module override (admin-set), if usable
     *   2. the default module of their highest-priority group, if usable
     *   3. their only visible module, if they have exactly one
     *   4. the Dashboard ('/')
     * A "usable" module is registered, visible and permitted — a default
     * that is hidden or unpermitted is skipped with no error (spec §2.3).
     */
    public static function landingPath(int $userId): string
    {
        $db = Database::getInstance();

        $candidates = [];
        $own = $db->fetch("SELECT landing_module FROM users WHERE id = ?", [$userId]);
        if (!empty($own['landing_module'])) {
            $candidates[] = $own['landing_module'];
        }
        foreach ($db->fetchAll(
            "SELECT pg.default_module
             FROM permission_groups pg
             JOIN user_group_members ugm ON ugm.group_id = pg.id
             WHERE ugm.user_id = ? AND pg.default_module IS NOT NULL AND pg.default_module <> ''
             ORDER BY pg.priority ASC, pg.id ASC",
            [$userId]
        ) as $row) {
            $candidates[] = $row['default_module'];
        }

        foreach ($candidates as $key) {
            $module = App::modules()->get($key);
            if ($module !== null && self::canSeeModule($userId, $module)) {
                return $module->basePath();
            }
        }

        $visible = self::visibleModules($userId);
        if (count($visible) === 1 && !self::isAdminUser($userId)) {
            return $visible[0]->basePath();
        }

        return '/';
    }

    /**
     * Effective access for the admin "what does this user actually see"
     * panel: visible modules, hidden modules (and which group hides
     * them), and the union of named permissions.
     */
    public static function effectiveForUser(int $userId): array
    {
        $isAdmin = self::isAdminUser($userId);

        $visible = [];
        $hidden  = [];
        foreach (App::modules()->all() as $module) {
            $hiddenBy = self::moduleHiddenBy($userId, $module->key());
            if ($hiddenBy !== null) {
                $hidden[$module->key()] = ['name' => $module->name(), 'hidden_by' => $hiddenBy];
            } elseif (self::canSeeModule($userId, $module)) {
                $visible[$module->key()] = $module->name();
            }
        }

        if ($isAdmin) {
            $permissions = array_keys(PermissionCatalog::all());
        } else {
            $permissions = array_column(Database::getInstance()->fetchAll(
                "SELECT DISTINCT gpg.permission
                 FROM group_permission_grants gpg
                 JOIN user_group_members ugm ON ugm.group_id = gpg.group_id
                 WHERE ugm.user_id = ?
                 ORDER BY gpg.permission",
                [$userId]
            ), 'permission');
        }

        return [
            'is_admin'        => $isAdmin,
            'visible_modules' => $visible,
            'hidden_modules'  => $hidden,
            'permissions'     => $permissions,
        ];
    }

    /** Clear per-request memo (used by tests and after admin changes). */
    public static function flushMemo(): void
    {
        self::$canMemo = [];
        self::$adminMemo = [];
    }
}

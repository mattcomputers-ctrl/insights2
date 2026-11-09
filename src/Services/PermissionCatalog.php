<?php

declare(strict_types=1);

namespace PII\Services;

use PII\Core\App;

/**
 * PermissionCatalog — every permission the platform knows about,
 * with plain-English labels for the group editor.
 *
 * Core permissions are defined here; module permissions come from each
 * module's manifest (Module::permissions()). Grants for keys that no
 * module currently declares are kept in the database but not shown in
 * the editor (e.g. Order Readiness grants seeded before that module
 * ships become visible the day it registers).
 */
class PermissionCatalog
{
    /** @return array<string,string> permission key => label */
    public static function core(): array
    {
        return [
            'sync.manual'            => 'Run a data update from CMS (Sync now)',
            'sync.override_blackout' => 'Start a sync during a backup blackout window',
            'export'                 => 'Export data to Excel / CSV',
            'admin.users'            => 'Manage users and permission groups',
            'admin.cms'              => 'Change the CMS database connection',
            'admin.sync'             => 'Configure automatic sync and blackout windows',
            'admin.network'          => 'Change the server network settings',
            'admin.system'           => 'System settings, updates, backups and the audit log',
        ];
    }

    /**
     * All permissions grouped by section for the group editor.
     *
     * @return array<string, array<string,string>> section label => (key => label)
     */
    public static function grouped(): array
    {
        $sections = [];
        foreach (App::modules()->all() as $module) {
            $perms = $module->permissions();
            if ($perms !== []) {
                $sections[$module->name()] = $perms;
            }
        }
        $sections['Platform'] = self::core();
        return $sections;
    }

    /** Flat key => label map of every known permission. */
    public static function all(): array
    {
        $all = [];
        foreach (self::grouped() as $perms) {
            $all += $perms;
        }
        return $all;
    }

    public static function isKnown(string $permission): bool
    {
        return array_key_exists($permission, self::all());
    }
}

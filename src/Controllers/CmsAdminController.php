<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\App;
use PII\Core\CMSDatabase;
use PII\Core\CSRF;
use PII\Core\Database;
use PII\Services\Settings;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__) . '/Helpers/layout.php';

/**
 * CmsAdminController — Admin → CMS & Sync (SPEC §3.1).
 *
 * Connection settings write to config/cms.local.php (gitignored, outside
 * the database); everything else lives in the settings table.
 */
class CmsAdminController
{
    public function index(): void
    {
        $config  = CMSDatabase::effectiveConfig() ?? [];
        $drivers = \PDO::getAvailableDrivers();
        $driver  = in_array('sqlsrv', $drivers, true) ? 'sqlsrv'
                 : (in_array('dblib', $drivers, true) ? 'dblib' : null);

        $history = Database::getInstance()->fetchAll(
            "SELECT sr.*, u.display_name, u.username
             FROM sync_runs sr LEFT JOIN users u ON u.id = sr.triggered_by
             ORDER BY sr.id DESC LIMIT 100"
        );

        layout('admin/cms/index', [
            'pageTitle'   => 'CMS & Sync',
            'cmsHost'     => (string) ($config['host'] ?? ''),
            'cmsName'     => (string) ($config['name'] ?? 'CMS'),
            'cmsUser'     => (string) ($config['user'] ?? ''),
            'cmsTrustCert' => !empty($config['trust_server_certificate']),
            'hasPassword' => ($config['password'] ?? 'CHANGE_ME') !== 'CHANGE_ME' && !empty($config['password']),
            'driver'      => $driver,
            'killSwitch'  => SyncSettings::killSwitch(),
            'autoOn'      => SyncSettings::autoEnabled(),
            'interval'    => SyncSettings::intervalMinutes(),
            'blackouts'   => SyncSettings::blackouts(),
            'history'     => $history,
            'testResult'  => $_SESSION['_flash']['cms_test'] ?? null,
        ]);
        unset($_SESSION['_flash']['cms_test']);
    }

    /** POST /admin/cms/connection — write config/cms.local.php. */
    public function saveConnection(): void
    {
        require_permission('admin.cms');
        CSRF::validateRequest();

        $current = CMSDatabase::effectiveConfig() ?? [];
        $new = [
            'host' => trim($_POST['host'] ?? ''),
            'port' => 1433,
            'name' => trim($_POST['name'] ?? 'CMS') ?: 'CMS',
            'user' => trim($_POST['user'] ?? ''),
            // Blank password = keep the existing one.
            'password' => ($_POST['password'] ?? '') !== ''
                ? (string) $_POST['password']
                : (string) ($current['password'] ?? ''),
            // Explicit admin opt-in for the CMS server's self-signed
            // certificate (ODBC 18 validates certs by default).
            'trust_server_certificate' => isset($_POST['trust_server_certificate']),
        ];

        if ($new['host'] === '' || $new['user'] === '') {
            $_SESSION['_flash']['error'] = 'Host and username are required.';
            redirect('/admin/cms');
        }

        $file = App::basePath() . '/config/cms.local.php';
        $php  = "<?php\n// Written from Admin → CMS & Sync. Keep out of git (see .gitignore).\nreturn "
              . var_export($new, true) . ";\n";
        if (@file_put_contents($file, $php, LOCK_EX) === false) {
            $_SESSION['_flash']['error'] = "Couldn't write {$file} — check file permissions.";
            redirect('/admin/cms');
        }
        @chmod($file, 0640);

        $this->audit('cms_connection', 'update', [
            'host' => $new['host'], 'name' => $new['name'], 'user' => $new['user'],
            'password_changed' => ($_POST['password'] ?? '') !== '',
            'trust_server_certificate' => $new['trust_server_certificate'],
        ]);

        $_SESSION['_flash']['success'] = 'CMS connection settings saved.';
        redirect('/admin/cms');
    }

    /** POST /admin/cms/test — try the stored (or just-submitted) settings. */
    public function testConnection(): void
    {
        require_permission('admin.cms');
        CSRF::validateRequest();

        $result = CMSDatabase::testConnection();
        $_SESSION['_flash']['cms_test'] = $result;
        $_SESSION['_flash'][$result['ok'] ? 'success' : 'error'] =
            $result['ok'] ? 'CMS connection OK.' : 'CMS connection failed: ' . $result['message'];
        redirect('/admin/cms');
    }

    /** POST /admin/cms/kill-switch */
    public function killSwitch(): void
    {
        require_permission('admin.cms');
        CSRF::validateRequest();

        $on = ($_POST['enabled'] ?? '') === '1';
        Settings::set('cms.kill_switch', $on ? '1' : '0');
        $this->audit('cms_kill_switch', $on ? 'enable' : 'disable', []);
        $_SESSION['_flash'][$on ? 'warning' : 'success'] = $on
            ? 'CMS access is now OFF for the whole platform.'
            : 'CMS access is back on.';
        redirect('/admin/cms');
    }

    /** POST /admin/cms/sync-settings — auto on/off + interval. */
    public function saveSyncSettings(): void
    {
        require_permission('admin.sync');
        CSRF::validateRequest();

        $auto     = isset($_POST['auto_enabled']);
        $interval = max(5, min(240, (int) ($_POST['interval'] ?? 15)));
        Settings::set('sync.auto_enabled', $auto ? '1' : '0');
        Settings::set('sync.interval_minutes', (string) $interval);

        $this->audit('sync_settings', 'update', ['auto' => $auto, 'interval' => $interval]);
        $_SESSION['_flash']['success'] = 'Sync settings saved.';
        redirect('/admin/cms');
    }

    /** POST /admin/cms/blackouts — rows of {days[], start, end}. */
    public function saveBlackouts(): void
    {
        require_permission('admin.sync');
        CSRF::validateRequest();

        $rows = $_POST['blackouts'] ?? [];
        $clean = [];
        foreach ((array) $rows as $row) {
            $start = trim((string) ($row['start'] ?? ''));
            $end   = trim((string) ($row['end'] ?? ''));
            $days  = array_values(array_filter(array_map('intval', (array) ($row['days'] ?? [])),
                fn($d) => $d >= 1 && $d <= 7));
            if ($start === '' || $end === '' || $days === []) {
                continue;   // incomplete row = removed
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
                $_SESSION['_flash']['error'] = 'Blackout times must be HH:MM (24-hour).';
                redirect('/admin/cms');
            }
            $clean[] = ['days' => $days, 'start' => $start, 'end' => $end];
        }
        Settings::set('sync.blackouts', json_encode($clean));

        $this->audit('sync_blackouts', 'update', ['windows' => $clean]);
        $_SESSION['_flash']['success'] = count($clean) . ' blackout window' . (count($clean) === 1 ? '' : 's') . ' saved.';
        redirect('/admin/cms');
    }

    private function audit(string $entity, string $action, array $details): void
    {
        Database::getInstance()->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => $entity,
            'entity_id'   => null,
            'action'      => $action,
            'details'     => json_encode($details, JSON_UNESCAPED_SLASHES),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}

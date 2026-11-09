<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Cms\Write\OperationRegistry;
use PII\Cms\Write\WriteAudit;
use PII\Cms\Write\WriteConfig;
use PII\Core\CMSDatabase;
use PII\Core\CSRF;
use PII\Core\Database;
use PII\Services\Settings;

require_once dirname(__DIR__) . '/Helpers/layout.php';

/**
 * Admin → CMS writing: the separate write connection, the live switch,
 * the allowlist and the write log. Under /admin/cms, so it needs the
 * admin.cms permission (AuthMiddleware).
 */
class CmsWriteAdminController
{
    public function index(): void
    {
        $conn = WriteConfig::connection() ?? [];
        layout('admin/cms/write', [
            'pageTitle'    => 'CMS writing',
            'host'         => (string) ($conn['host'] ?? ''),
            'name'         => (string) ($conn['name'] ?? 'CMS'),
            'user'         => (string) ($conn['user'] ?? ''),
            'trustCert'    => !empty($conn['trust_server_certificate']),
            'hasConnection' => $conn !== [],
            'liveSetting'  => Settings::getBool(WriteConfig::LIVE_SETTING, false),
            'mode'         => WriteConfig::mode(),
            'workstation'  => WriteConfig::workstation(),
            'cmsPrograms'  => WriteConfig::cmsPrograms(),
            'operations'   => OperationRegistry::all(),
            'audit'        => WriteAudit::recent(100),
            'unlinked'     => Database::getInstance()->fetchAll(
                "SELECT username, display_name FROM users
                 WHERE is_active = 1 AND (cms_user IS NULL OR cms_user = '') ORDER BY username"
            ),
        ]);
    }

    /** GET /admin/cms/write/log/{id} — one audited request in full. */
    public function logEntry(string $id): void
    {
        $row = WriteAudit::find((int) $id);
        if ($row === null) {
            render_not_found();
        }
        layout('admin/cms/write_log', ['pageTitle' => 'CMS write log #' . (int) $id, 'row' => $row]);
    }

    /** POST /admin/cms/write/connection — write config/cms.write.local.php. */
    public function saveConnection(): void
    {
        require_permission('admin.cms');
        CSRF::validateRequest();

        $current = WriteConfig::connection() ?? [];
        $new = [
            'host' => trim((string) ($_POST['host'] ?? '')),
            'port' => 1433,
            'name' => trim((string) ($_POST['name'] ?? 'CMS')) ?: 'CMS',
            'user' => trim((string) ($_POST['user'] ?? '')),
            'password' => ($_POST['password'] ?? '') !== ''
                ? (string) $_POST['password']
                : (string) ($current['password'] ?? ''),
            'trust_server_certificate' => isset($_POST['trust_server_certificate']),
        ];
        if ($new['host'] === '' || $new['user'] === '' || $new['password'] === '') {
            $_SESSION['_flash']['error'] = 'Host, username and password are required.';
            redirect('/admin/cms/write');
        }

        $file = WriteConfig::connectionFile();
        $php  = "<?php\n// CMS WRITE connection. Written from Admin → CMS writing. Keep out of git (see .gitignore).\nreturn "
              . var_export($new, true) . ";\n";
        if (@file_put_contents($file, $php, LOCK_EX) === false) {
            $_SESSION['_flash']['error'] = "Couldn't write {$file} — check file permissions.";
            redirect('/admin/cms/write');
        }
        @chmod($file, 0640);

        // A changed connection always starts switched off.
        Settings::set(WriteConfig::LIVE_SETTING, '0');
        $this->audit('cms_write_connection', 'update', [
            'host' => $new['host'], 'name' => $new['name'], 'user' => $new['user'],
            'password_changed' => ($_POST['password'] ?? '') !== '',
        ]);
        $_SESSION['_flash']['success'] = 'Write connection saved. Live sending is off until you switch it on.';
        redirect('/admin/cms/write');
    }

    /** POST /admin/cms/write/connection/remove */
    public function removeConnection(): void
    {
        require_permission('admin.cms');
        CSRF::validateRequest();
        $file = WriteConfig::connectionFile();
        if (is_file($file)) {
            @unlink($file);
        }
        Settings::set(WriteConfig::LIVE_SETTING, '0');
        $this->audit('cms_write_connection', 'delete', []);
        $_SESSION['_flash']['success'] = 'Write connection removed. All writes are dry-run.';
        redirect('/admin/cms/write');
    }

    /** POST /admin/cms/write/test — can the write login connect and see ProcessXMLRequest? Sends nothing. */
    public function testConnection(): void
    {
        require_permission('admin.cms');
        CSRF::validateRequest();
        $conn = WriteConfig::connection();
        if ($conn === null) {
            $_SESSION['_flash']['error'] = 'No write connection is saved yet.';
            redirect('/admin/cms/write');
        }
        try {
            CMSDatabase::assertNotBlocked();
            $pdo = (new CMSDatabase($conn))->getPdo();
            $row = $pdo->query(
                "SELECT DB_NAME() AS db, SUSER_SNAME() AS login,
                        HAS_PERMS_BY_NAME('dbo.ProcessXMLRequest', 'OBJECT', 'EXECUTE') AS can_exec"
            )->fetch();
            if ((int) ($row['can_exec'] ?? 0) === 1) {
                $_SESSION['_flash']['success'] = "Connected to {$row['db']} as {$row['login']}; it may run ProcessXMLRequest.";
            } else {
                $_SESSION['_flash']['error'] = "Connected to {$row['db']} as {$row['login']}, but that login has no EXECUTE permission on ProcessXMLRequest.";
            }
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Write connection failed: ' . $e->getMessage();
        }
        redirect('/admin/cms/write');
    }

    /** POST /admin/cms/write/settings — live switch, workstation name, Program naming. */
    public function saveSettings(): void
    {
        require_permission('admin.cms');
        CSRF::validateRequest();

        $live = isset($_POST['live_enabled']);
        if ($live && WriteConfig::connection() === null) {
            $_SESSION['_flash']['error'] = 'Save a write connection before switching live sending on.';
            redirect('/admin/cms/write');
        }
        $ws = strtoupper(trim((string) ($_POST['workstation'] ?? '')));
        if (!preg_match('/^[A-Z0-9_-]{1,30}$/', $ws)) {
            $ws = 'INSIGHTS';
        }
        $programs = ($_POST['program_mode'] ?? '') === 'cms' ? 'cms' : 'insights';

        Settings::set(WriteConfig::LIVE_SETTING, $live ? '1' : '0');
        Settings::set(WriteConfig::WORKSTATION_SETTING, $ws);
        Settings::set(WriteConfig::PROGRAM_SETTING, $programs);
        $this->audit('cms_write_settings', 'update', ['live' => $live, 'workstation' => $ws, 'program_mode' => $programs]);

        $_SESSION['_flash'][$live ? 'warning' : 'success'] = $live
            ? 'Live sending is ON: confirmed writes now change CMS.'
            : 'Saved. Writes are dry-run.';
        redirect('/admin/cms/write');
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

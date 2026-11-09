<?php

declare(strict_types=1);

namespace PII\Middleware;

use PII\Core\App;
use PII\Core\Database;
use PII\Services\PermissionService;

/**
 * AuthMiddleware — authentication + the "hidden, not disabled" gate.
 *
 * Rules (SPEC-order-readiness §2.1):
 *  - Anonymous users are redirected to /login.
 *  - Anything the user lacks permission for — a module that is hidden
 *    from them, a module view permission they don't hold, an admin area
 *    outside their admin.* grants — renders the SAME plain 404 page an
 *    unknown URL gets, so nothing reveals the page exists. The attempt
 *    is logged to audit_log.
 *  - A user flagged must_change_password can reach only /password and
 *    /logout until they change it.
 *
 * is_admin and must_change_password are re-read from the database every
 * request, so admin changes apply on the user's next click, not their
 * next login.
 */
class AuthMiddleware
{
    /** @var list<string> URI prefixes that bypass authentication. */
    private const PUBLIC_PATHS = [
        '/login',
        '/logout',
        '/css',
        '/js',
        '/assets',
        '/favicon.ico',
    ];

    /**
     * Admin URI prefix => required permission. Longest match wins.
     * Admin-group members bypass (PermissionService::can returns true).
     */
    private const ADMIN_AREAS = [
        '/admin/users'     => 'admin.users',
        '/admin/groups'    => 'admin.users',
        '/admin/settings'  => 'admin.system',
        '/admin/audit-log' => 'admin.system',
        '/admin/cms'       => 'admin.cms',   // sync-settings POSTs re-check admin.sync
        '/admin/ui-kit'    => 'admin.system',
        '/admin/network'   => 'admin.network',
    ];

    /**
     * Non-module URI prefix => module key whose visibility it follows.
     * Preferences (colour thresholds) and saved presets are Margin
     * Watchdog per-user settings — invisible along with the module.
     */
    private const MODULE_AREAS = [
        '/preferences' => 'margin_watchdog',
        '/presets'     => 'margin_watchdog',
    ];

    public function handle(callable $next): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($this->isPublicPath($uri)) {
            $next();
            return;
        }

        if (!isset($_SESSION['_user'])) {
            $_SESSION['_flash']['intended_url'] = $uri;
            redirect('/login');
        }

        $userId = (int) $_SESSION['_user']['id'];

        // Re-read volatile account state each request.
        $state = Database::getInstance()->fetch(
            "SELECT is_active, must_change_password FROM users WHERE id = ?",
            [$userId]
        );
        if (!$state || !(int) $state['is_active']) {
            // Deactivated (or deleted) mid-session: end the session.
            $_SESSION = [];
            session_destroy();
            redirect('/login');
        }
        $_SESSION['_user']['is_admin'] = PermissionService::isAdminUser($userId) ? 1 : 0;

        if ((int) $state['must_change_password'] === 1 && $uri !== '/password') {
            redirect('/password');
        }

        // ── Admin areas: granular admin.* permissions ────────────────
        if (str_starts_with($uri, '/admin')) {
            $required = null;
            foreach (self::ADMIN_AREAS as $prefix => $perm) {
                if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                    $required = $perm;
                    break;
                }
            }
            // Unmapped /admin URL: admins only.
            $allowed = $required !== null
                ? PermissionService::can($userId, $required)
                : PermissionService::isAdminUser($userId);
            if (!$allowed) {
                $this->deny($userId, $uri, $required ?? 'admin');
            }
            $next();
            return;
        }

        // ── Module pages: hidden-or-unpermitted → 404 ────────────────
        $module = App::modules()->moduleForPath($uri);
        if ($module === null) {
            foreach (self::MODULE_AREAS as $prefix => $moduleKey) {
                if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                    $module = App::modules()->get($moduleKey);
                    break;
                }
            }
        }
        if ($module !== null && !PermissionService::canSeeModule($userId, $module)) {
            $this->deny($userId, $uri, $module->permissionKey());
        }

        $next();
    }

    private function isPublicPath(string $uri): bool
    {
        foreach (self::PUBLIC_PATHS as $prefix) {
            if ($uri === $prefix
                || str_starts_with($uri, $prefix . '/')
                || str_starts_with($uri, $prefix . '?')
            ) {
                return true;
            }
        }
        return false;
    }

    /** Log the denied attempt and render the plain 404. */
    private function deny(int $userId, string $uri, string $missing): never
    {
        try {
            Database::getInstance()->insert('audit_log', [
                'user_id'     => $userId,
                'entity_type' => 'access',
                'entity_id'   => $uri,
                'action'      => 'denied',
                'details'     => json_encode(['missing' => $missing]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // auditing must never turn a denial into a 500
        }
        render_not_found();
    }
}

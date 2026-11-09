<?php

declare(strict_types=1);

namespace PII\Core;

use PII\Middleware\AuthMiddleware;
use PII\Modules\BatchTickets\BatchTicketsModule;
use PII\Modules\InventoryBrowser\InventoryBrowserModule;
use PII\Modules\Items\ItemsModule;
use PII\Modules\MarginWatchdog\MarginWatchdogModule;
use PII\Modules\NeedToOrder\NeedToOrderModule;
use PII\Modules\OrderReadiness\OrderReadinessModule;
use PII\Modules\PurchaseOrders\PurchaseOrdersModule;
use PII\Modules\Qc\QcModule;
use PII\Modules\RecipeLookup\RecipeLookupModule;
use PII\Modules\SalesOrders\SalesOrdersModule;
use PII\Modules\Shipping\ShippingModule;
use PII\Modules\Scheduling\SchedulingModule;

/**
 * App — Application bootstrap.
 *
 * Loads config, initialises core services, registers tab modules,
 * and dispatches the incoming HTTP request.
 */
class App
{
    private static array $config = [];
    private static Database $database;
    private static Session $session;
    private static ModuleRegistry $modules;
    private static string $basePath;

    public function __construct()
    {
        self::init();
        self::$session = new Session();
        self::$session->start(self::$config['session'] ?? []);
    }

    /**
     * Bootstrap for CLI processes (sync worker, cron scripts): everything
     * the web app gets except the HTTP session and router.
     */
    public static function bootConsole(): void
    {
        self::init();
    }

    private static function init(): void
    {
        self::$basePath = dirname(__DIR__, 2);

        $configFile = self::$basePath . '/config/config.php';
        if (!file_exists($configFile)) {
            throw new \RuntimeException('Configuration file not found: ' . $configFile);
        }
        self::$config = require $configFile;

        date_default_timezone_set(self::config('app.timezone', 'America/New_York'));

        // Errors ALWAYS go to PHP's error_log (= Apache vhost error log
        // when running under mod_php). display_errors only controls
        // whether they appear in the HTTP response, which we never want
        // in production. Without this, error_reporting(0) silenced
        // everything — making 500s un-debuggable from server-side logs.
        error_reporting(E_ALL);
        ini_set('log_errors', '1');
        if (self::config('app.debug', false)) {
            ini_set('display_errors', '1');
        } else {
            ini_set('display_errors', '0');
        }

        self::$database = Database::init(self::$config['db']);
        self::$modules  = self::buildModuleRegistry();
    }

    /* ------------------------------------------------------------------
     *  Static accessors
     * ----------------------------------------------------------------*/

    public static function config(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = self::$config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function db(): Database
    {
        return self::$database;
    }

    public static function session(): Session
    {
        return self::$session;
    }

    public static function modules(): ModuleRegistry
    {
        return self::$modules;
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    /* ------------------------------------------------------------------
     *  Module registration
     * ----------------------------------------------------------------*/

    /**
     * Build and populate the ModuleRegistry.
     *
     * To add a new tab, instantiate its Module class and register it here.
     * Order matters — modules display in the tab nav in registration order.
     */
    private static function buildModuleRegistry(): ModuleRegistry
    {
        $registry = new ModuleRegistry();
        // NOTE: NeedToOrder's sync job consumes OrderReadiness's in-run
        // hand-off (SyncContext::share), so it must register AFTER it.
        $registry->register(new OrderReadinessModule());
        $registry->register(new NeedToOrderModule());
        $registry->register(new SalesOrdersModule());
        $registry->register(new ShippingModule());
        $registry->register(new PurchaseOrdersModule());
        $registry->register(new BatchTicketsModule());
        $registry->register(new QcModule());
        $registry->register(new InventoryBrowserModule());
        $registry->register(new ItemsModule());
        $registry->register(new RecipeLookupModule());
        $registry->register(new MarginWatchdogModule());
        $registry->register(new SchedulingModule());
        // Future tabs go here:
        //   $registry->register(new ARAgingModule());
        return $registry;
    }

    /* ------------------------------------------------------------------
     *  Run
     * ----------------------------------------------------------------*/

    public function run(): void
    {
        $router = new Router();

        $auth = new AuthMiddleware();
        $router->addMiddleware([$auth, 'handle']);

        // ── Auth ──────────────────────────────────────────────────────
        $router->get('/login',  'AuthController@loginForm');
        $router->post('/login', 'AuthController@login');
        $router->get('/logout', 'AuthController@logout');
        $router->get('/password',  'AuthController@passwordForm');
        $router->post('/password', 'AuthController@passwordSave');
        $router->post('/theme',    'PreferencesController@toggleTheme');

        // ── Dashboard (landing page after login) ─────────────────────
        $router->get('/', 'DashboardController@index');

        // ── User preferences (per-user threshold/colour config) ──────
        $router->get('/preferences',                       'PreferencesController@index');
        $router->post('/preferences',                      'PreferencesController@save');
        $router->post('/preferences/save-as-system',       'PreferencesController@saveAsSystemDefault');
        $router->post('/preferences/reset',                'PreferencesController@reset');
        $router->post('/preferences/reset-system-defaults','PreferencesController@resetSystemDefaults');

        // ── Date range presets (saved per user) ──────────────────────
        $router->get('/presets',               'PresetController@index');
        $router->post('/presets',              'PresetController@create');
        $router->post('/presets/{id}/delete',  'PresetController@delete');

        // ── Admin: user & group management ───────────────────────────
        $router->group('/admin', function (Router $r) {
            $r->get('/users',              'AdminController@users');
            $r->get('/users/create',       'AdminController@createUser');
            $r->post('/users',             'AdminController@storeUser');
            $r->get('/users/{id}/edit',    'AdminController@editUser');
            $r->post('/users/{id}',        'AdminController@updateUser');
            $r->post('/users/{id}/delete', 'AdminController@deleteUser');

            $r->get('/groups',              'AdminController@groups');
            $r->get('/groups/create',       'AdminController@createGroup');
            $r->post('/groups',             'AdminController@storeGroup');
            $r->get('/groups/{id}/edit',    'AdminController@editGroup');
            $r->post('/groups/{id}',        'AdminController@updateGroup');
            $r->post('/groups/{id}/delete', 'AdminController@deleteGroup');
            $r->post('/groups/{id}/move',   'AdminController@moveGroup');

            $r->get('/settings',  'AdminController@settings');
            $r->post('/settings', 'AdminController@saveSettings');
            $r->post('/settings/run-snapshot',     'AdminController@runInventorySnapshot');
            $r->post('/settings/backfill-snapshot', 'AdminController@backfillInventorySnapshots');
            $r->get('/settings/snapshot-status',    'AdminController@snapshotStatus');
            $r->post('/settings/coa',              'AdminController@saveCoaSettings');
            $r->post('/settings/update-now',       'AdminController@updateNow');
            $r->post('/settings/backup-now',       'AdminController@backupNow');
            $r->get('/settings/download-backup',   'AdminController@downloadBackup');

            $r->get('/network',   'AdminController@network');
            $r->post('/network',  'AdminController@saveNetwork');

            $r->get('/audit-log', 'AdminController@auditLog');
            $r->get('/ui-kit',    'AdminController@uiKit');

            // CMS & Sync (SPEC §3.1)
            $r->get('/cms',                 'CmsAdminController@index');
            $r->post('/cms/connection',     'CmsAdminController@saveConnection');
            $r->post('/cms/test',           'CmsAdminController@testConnection');
            $r->post('/cms/kill-switch',    'CmsAdminController@killSwitch');
            $r->post('/cms/sync-settings',  'CmsAdminController@saveSyncSettings');
            $r->post('/cms/blackouts',      'CmsAdminController@saveBlackouts');
        });

        // ── Sync status bar endpoints ─────────────────────────────────
        $router->get('/api/sync/status', 'SyncController@status');
        $router->post('/api/sync/run',   'SyncController@run');

        // ── Module routes ────────────────────────────────────────────
        // Each registered module gets to register its own routes.
        foreach (self::$modules->all() as $module) {
            $module->registerRoutes($router);
        }

        // ── Dispatch ─────────────────────────────────────────────────
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';
        $router->dispatch($method, $uri);
    }
}

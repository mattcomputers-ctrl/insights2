<?php

declare(strict_types=1);

/**
 * Permission crawl (SPEC-order-readiness §2.1): log in as a user from each
 * seeded group and hit every GET route, asserting that status codes, nav
 * links and in-page controls match that group's permissions — and that
 * everything unpermitted is a plain 404, not a 403 or an error.
 *
 * Runs against a live instance (tests/integration/run-local.sh starts one
 * in Docker). Requires config/config.php to point at a DISPOSABLE test
 * database: this script inserts and deletes crawl_* users.
 *
 * Usage: php tests/integration/crawl.php [base-url]   (default http://127.0.0.1:8080)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$ROOT = dirname(__DIR__, 2);

$config = require $ROOT . '/config/config.php';
if (!str_contains($config['db']['name'], 'test')) {
    fwrite(STDERR, "Refusing to run: database '{$config['db']['name']}' doesn't look like a test database.\n");
    exit(2);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db']['host'], $config['db']['port'] ?? 3306, $config['db']['name']),
    $config['db']['user'], $config['db']['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/* ── Seed one user per group ─────────────────────────────────────── */

const PASSWORD = 'CrawlTest123!';

$userGroups = [
    'crawl_admin' => 'Administrators',
    'crawl_exec'  => 'Executives',
    'crawl_prod'  => 'Production',
    'crawl_ship'  => 'Shipping',
    'crawl_sales' => 'Sales',
    'crawl_ro'    => 'Read only',
    'crawl_none'  => null,
];

$pdo->exec("DELETE FROM users WHERE username LIKE 'crawl\\_%'");
$hash = password_hash(PASSWORD, PASSWORD_ARGON2ID);
foreach ($userGroups as $username => $groupName) {
    $pdo->prepare("INSERT INTO users (username, password_hash, display_name, is_active) VALUES (?,?,?,1)")
        ->execute([$username, $hash, $username]);
    $uid = (int) $pdo->lastInsertId();
    if ($groupName !== null) {
        $g = $pdo->prepare("SELECT id FROM permission_groups WHERE name = ?");
        $g->execute([$groupName]);
        $gid = $g->fetchColumn();
        if ($gid === false) {
            fwrite(STDERR, "Seed group '{$groupName}' missing — did migrations run?\n");
            exit(2);
        }
        $pdo->prepare("INSERT INTO user_group_members (user_id, group_id) VALUES (?,?)")
            ->execute([$uid, (int) $gid]);
    }
}

/* ── Tiny cookie-aware HTTP client (shared) ──────────────────────── */

require_once __DIR__ . '/http-client.php';

/* ── Assertion plumbing ──────────────────────────────────────────── */

$checks = 0;
$failures = 0;
function ok(bool $cond, string $msg): void
{
    global $checks, $failures;
    $checks++;
    if (!$cond) {
        $failures++;
        echo "  FAIL: {$msg}\n";
    }
}

/* ── Route/status matrix ─────────────────────────────────────────── */
/* Codes: 200 OK · 302 redirect · 400 bad request · 404 hidden/unknown */

$routes = [
    //  path                                  admin exec prod ship sales ro  none
    ['/',                                      200, 200, 200, 200, 200, 200, 200],
    ['/password',                              200, 200, 200, 200, 200, 200, 200],
    ['/margin-watchdog',                       200, 200, 404, 404, 404, 404, 404],
    ['/margin-watchdog/items',                 400, 400, 404, 404, 404, 404, 404],
    ['/margin-watchdog/export',                302, 302, 404, 404, 404, 404, 404],
    ['/preferences',                           200, 200, 404, 404, 404, 404, 404],
    ['/presets',                               200, 200, 404, 404, 404, 404, 404],
    ['/scheduling',                            200, 200, 200, 404, 404, 404, 404],
    ['/scheduling/generate?week=bad',          400, 400, 400, 404, 404, 404, 404],
    ['/scheduling/settings',                   200, 404, 200, 404, 404, 404, 404],
    ['/orders',                                200, 200, 200, 200, 200, 200, 404],
    ['/orders/list',                           200, 200, 200, 200, 200, 200, 404],
    ['/orders/by-item',                        200, 200, 200, 200, 200, 404, 404],
    ['/orders/tickets',                        200, 200, 200, 200, 404, 404, 404],
    ['/orders/short-materials',                200, 200, 200, 200, 404, 404, 404],
    ['/orders/production',                     200, 200, 200, 404, 404, 404, 404],
    ['/orders/notes',                          200, 200, 200, 200, 200, 200, 404],
    ['/orders/export/orders?format=csv',       200, 200, 200, 200, 200, 404, 404],
    ['/inventory',                             200, 404, 404, 404, 404, 404, 404],
    ['/inventory/export',                      200, 404, 404, 404, 404, 404, 404],
    ['/need-to-order',                         200, 404, 404, 404, 404, 404, 404],
    ['/need-to-order/export',                  200, 404, 404, 404, 404, 404, 404],
    ['/recipes',                               200, 404, 404, 404, 404, 404, 404],
    ['/recipes?q=E1202',                       200, 404, 404, 404, 404, 404, 404],
    // CMS is unreachable in this environment: the fragment answers 500
    // with a friendly alert (not a stack trace) for permitted users.
    ['/recipes/active?code=TEST',              500, 404, 404, 404, 404, 404, 404],
    // Read-only operational viewers (live CMS: pages render the friendly
    // "CMS unavailable" alert here, never an error dump).
    ['/sales-orders',                          200, 404, 404, 404, 404, 404, 404],
    ['/sales-orders/999999',                   200, 404, 404, 404, 404, 404, 404],
    ['/sales-orders/new',                      200, 404, 404, 404, 404, 404, 404],
    ['/sales-orders/999999/edit',              200, 404, 404, 404, 404, 404, 404],
    ['/purchase-orders',                       200, 404, 404, 404, 404, 404, 404],
    ['/purchase-orders/999999',                200, 404, 404, 404, 404, 404, 404],
    ['/purchase-orders/new',                   200, 404, 404, 404, 404, 404, 404],
    ['/purchase-orders/999999/edit',           200, 404, 404, 404, 404, 404, 404],
    ['/purchase-orders/999999/receive',        200, 404, 404, 404, 404, 404, 404],
    ['/batch-tickets',                         200, 200, 200, 200, 404, 404, 404],
    ['/batch-tickets/new',                     200, 404, 404, 404, 404, 404, 404],
    ['/batch-tickets/new?item=TEST&qty=500&start=2030-01-02&release=1&packs[0][code]=TEST-5G&packs[0][qty]=250&packs[0][by]=lb',
                                               200, 404, 404, 404, 404, 404, 404],
    ['/batch-tickets/999999',                  200, 200, 200, 200, 404, 404, 404],
    ['/qc',                                    200, 404, 404, 404, 404, 404, 404],
    ['/qc/coa',                                200, 404, 404, 404, 404, 404, 404],
    ['/qc/queue',                              200, 404, 404, 404, 404, 404, 404],
    ['/qc/sample/999999',                      200, 404, 404, 404, 404, 404, 404],
    ['/admin/cms/write',                       200, 404, 404, 404, 404, 404, 404],
    ['/shipping',                              200, 404, 404, 404, 404, 404, 404],
    ['/shipping/999999',                       200, 404, 404, 404, 404, 404, 404],
    ['/items',                                 200, 404, 404, 404, 404, 404, 404],
    ['/items/view?code=ZZZ-NOPE',              200, 404, 404, 404, 404, 404, 404],
    ['/admin/users',                           200, 404, 404, 404, 404, 404, 404],
    ['/admin/groups',                          200, 404, 404, 404, 404, 404, 404],
    ['/admin/settings',                        200, 404, 404, 404, 404, 404, 404],
    ['/admin/audit-log',                       200, 404, 404, 404, 404, 404, 404],
    ['/admin/not-a-real-admin-page',           404, 404, 404, 404, 404, 404, 404],
    ['/not-a-real-page',                       404, 404, 404, 404, 404, 404, 404],
];
$cols = ['crawl_admin', 'crawl_exec', 'crawl_prod', 'crawl_ship', 'crawl_sales', 'crawl_ro', 'crawl_none'];

/* Post-login landing (PermissionService::landingPath) */
$expectedLanding = [
    'crawl_admin' => '/',                 // admins: no group default → Home
    'crawl_exec'  => '/margin-watchdog',  // Executives group default
    'crawl_prod'  => '/orders',           // Production group default (Order Readiness)
    'crawl_ship'  => '/orders',
    'crawl_sales' => '/orders',
    'crawl_ro'    => '/orders',           // only visible module anyway
    'crawl_none'  => '/',
];

/* Dashboard content: [must contain, must NOT contain] */
$navExpectations = [
    'crawl_admin' => [
        ['href="/margin-watchdog"', 'href="/scheduling"', 'href="/admin/users"', 'href="/admin/settings"', 'href="/preferences"', 'href="/presets"'],
        [],
    ],
    'crawl_exec' => [
        ['href="/margin-watchdog"', 'href="/scheduling"', 'href="/preferences"', 'href="/presets"', 'CMS unavailable'],
        ['href="/admin/users"', 'href="/admin/settings"', 'href="/admin/audit-log"'],
    ],
    'crawl_prod' => [
        ['href="/scheduling"', 'href="/orders"'],
        ['href="/margin-watchdog"', 'href="/preferences"', 'href="/presets"', 'href="/admin/users"', 'CMS unavailable', 'Margin Watchdog'],
    ],
    'crawl_ship' => [
        ['href="/orders"', 'Order Readiness'],
        ['href="/margin-watchdog"', 'href="/scheduling"', 'href="/admin/users"', 'CMS unavailable', 'Margin Watchdog', 'Scheduling'],
    ],
    'crawl_none' => [
        [],
        ['href="/margin-watchdog"', 'href="/scheduling"', 'href="/orders"', 'href="/admin/users"', 'CMS unavailable'],
    ],
];

echo "Crawling {$BASE} as each seeded group…\n";

foreach ($cols as $i => $username) {
    echo "\n== {$username}\n";
    $http = new Http($BASE);
    $landing = $http->login($username, PASSWORD);
    ok($landing === $expectedLanding[$username],
        "login landing: expected {$expectedLanding[$username]}, got " . var_export($landing, true));

    foreach ($routes as $route) {
        $path = $route[0];
        $expected = $route[$i + 1];
        $resp = $http->get($path);
        ok($resp['status'] === $expected, "GET {$path}: expected {$expected}, got {$resp['status']}");

        // No page may ever be a PHP error dump, whatever its status.
        ok(!str_contains($resp['body'], 'Fatal error') && !str_contains($resp['body'], 'Stack trace'),
            "GET {$path}: page contains a PHP error dump");

        // Denials must be the plain 404 page, never a 403 or stack trace.
        if ($expected === 404) {
            ok(str_contains($resp['body'], "doesn't exist or isn't available"),
                "GET {$path}: 404 body should be the plain not-found page");
        }
    }

    if (isset($navExpectations[$username])) {
        $dash = $http->get('/')['body'];
        [$must, $mustNot] = $navExpectations[$username];
        foreach ($must as $needle) {
            ok(str_contains($dash, $needle), "dashboard for {$username} should contain [{$needle}]");
        }
        foreach ($mustNot as $needle) {
            ok(!str_contains($dash, $needle), "dashboard for {$username} must NOT contain [{$needle}]");
        }
    }

    // In-page control gating on the Scheduling page
    if (in_array($username, ['crawl_exec', 'crawl_prod'], true)) {
        $sched = $http->get('/scheduling')['body'];
        $shouldManage = $username === 'crawl_prod';
        ok(str_contains($sched, 'href="/scheduling/settings"') === $shouldManage,
            "scheduling settings link for {$username}: should be " . ($shouldManage ? 'visible' : 'hidden'));
        ok(str_contains($sched, 'const CAN_EXPORT = true') === true,
            "scheduling CAN_EXPORT for {$username} (both groups hold export)");
    }
}

/* ── Forced password change flow ─────────────────────────────────── */

echo "\n== must_change_password flow (crawl_ship)\n";
$pdo->exec("UPDATE users SET must_change_password = 1 WHERE username = 'crawl_ship'");
$http = new Http($BASE);
$loc = $http->login('crawl_ship', PASSWORD);
ok($loc === '/password', "forced-change login should land on /password, got " . var_export($loc, true));
$resp = $http->get('/');
ok($resp['status'] === 302 && $resp['location'] === '/password',
    'any page while flagged should redirect to /password');
$token = $http->csrfFrom('/password');
$resp = $http->post('/password', [
    '_csrf_token'      => $token,
    'current_password' => PASSWORD,
    'new_password'     => 'CrawlTest456!',
    'confirm_password' => 'CrawlTest456!',
]);
ok($resp['status'] === 302 && $resp['location'] !== '/password',
    'password change should redirect away from /password');
ok($http->get('/')['status'] === 200, 'site usable after forced change');

/* ── Lockout after repeated failures ─────────────────────────────── */

echo "\n== lockout flow (crawl_sales)\n";
$http = new Http($BASE);
for ($i = 0; $i < 5; $i++) {
    $http->login('crawl_sales', 'wrong-password');
}
$loc = (new Http($BASE))->login('crawl_sales', PASSWORD);
ok($loc === '/login', 'correct password during lockout must still be refused');
$pdo->exec("UPDATE users SET lockout_until = NULL, failed_logins = 0 WHERE username = 'crawl_sales'");
$loc = (new Http($BASE))->login('crawl_sales', PASSWORD);
ok($loc !== '/login', 'login works again once lockout cleared');

/* ── Deactivation ends an existing session ───────────────────────── */

echo "\n== mid-session deactivation (crawl_ro)\n";
$http = new Http($BASE);
$http->login('crawl_ro', PASSWORD);
ok($http->get('/')['status'] === 200, 'read-only user logged in');
$pdo->exec("UPDATE users SET is_active = 0 WHERE username = 'crawl_ro'");
$resp = $http->get('/');
ok($resp['status'] === 302 && $resp['location'] === '/login',
    'deactivated user is logged out on next request');

/* ── Cleanup ─────────────────────────────────────────────────────── */

$pdo->exec("DELETE FROM users WHERE username LIKE 'crawl\\_%'");

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);

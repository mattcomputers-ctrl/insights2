<?php

declare(strict_types=1);

/**
 * Sync engine end-to-end (step 4): the /api/sync endpoints, the worker,
 * blackout and kill-switch behaviour — against the same disposable
 * instance the permission crawl uses.
 *
 * Usage: php tests/integration/sync-flow.php [base-url]
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$ROOT = dirname(__DIR__, 2);

$config = require $ROOT . '/config/config.php';
if (!str_contains($config['db']['name'], 'test')) {
    fwrite(STDERR, "Refusing to run on non-test database.\n");
    exit(2);
}
// Match the app's clock — blackout windows and timestamps are compared there.
date_default_timezone_set($config['app']['timezone'] ?? 'America/Chicago');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db']['host'], $config['db']['port'] ?? 3306, $config['db']['name']),
    $config['db']['user'], $config['db']['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

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
function setting(PDO $pdo, string $key, ?string $value): void
{
    $pdo->prepare("DELETE FROM settings WHERE `key` = ?")->execute([$key]);
    if ($value !== null) {
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?)")->execute([$key, $value]);
    }
}

/* reuse the crawl's Http client */
require_once __DIR__ . '/http-client.php';

/* seed an admin + a shipping (non-override) user */
$hash = password_hash('SyncTest123!', PASSWORD_ARGON2ID);
$pdo->exec("DELETE FROM users WHERE username LIKE 'syncf\\_%'");
foreach (['syncf_admin' => 'Administrators', 'syncf_ship' => 'Shipping'] as $u => $g) {
    $pdo->prepare("INSERT INTO users (username,password_hash,display_name,is_active) VALUES (?,?,?,1)")
        ->execute([$u, $hash, $u]);
    $uid = (int) $pdo->lastInsertId();
    $gid = $pdo->query("SELECT id FROM permission_groups WHERE name = " . $pdo->quote($g))->fetchColumn();
    $pdo->prepare("INSERT INTO user_group_members VALUES (?,?)")->execute([$uid, (int) $gid]);
}

$pdo->exec("DELETE FROM sync_runs");
setting($pdo, 'sync.blackouts', null);
setting($pdo, 'cms.kill_switch', '0');
setting($pdo, 'sync.last_success_at', null);

/* Make isConfigured() true without a reachable CMS: blackout/kill checks
   fire BEFORE any connection attempt, and an actual run fails cleanly. */
file_put_contents($ROOT . '/config/cms.local.php',
    "<?php\nreturn ['host' => '192.0.2.1', 'port' => 1433, 'name' => 'CMS', 'user' => 'x', 'password' => 'y'];\n");

echo "== status endpoint baseline\n";
$admin = new Http($BASE);
$admin->login('syncf_admin', 'SyncTest123!');
$st = json_decode($admin->get('/api/sync/status')['body'], true);
ok(($st['state'] ?? '') === 'never', "state should be 'never' before any sync, got " . ($st['state'] ?? '?'));
ok(($st['can_sync'] ?? false) === true, 'admin can_sync true');

echo "== manual sync via API + worker --once\n";
$resp = $admin->post('/api/sync/run', ['_csrf_token' => $admin->csrfFrom('/')]);
$j = json_decode($resp['body'], true);
ok(($j['ok'] ?? false) === true, 'manual sync queued: ' . $resp['body']);

$st = json_decode($admin->get('/api/sync/status')['body'], true);
ok(($st['state'] ?? '') === 'syncing', "state 'syncing' while queued, got " . ($st['state'] ?? '?'));

// A second request while queued reports who started it instead of queueing another.
$resp = $admin->post('/api/sync/run', ['_csrf_token' => $admin->csrfFrom('/')]);
$j = json_decode($resp['body'], true);
ok(($j['already'] ?? false) === true, 'second sync request says already running');
ok((int) $pdo->query("SELECT COUNT(*) FROM sync_runs WHERE status='queued'")->fetchColumn() === 1,
    'only one queued run');

// Worker processes it; with an unreachable CMS the run must FAIL cleanly.
passthru('php ' . escapeshellarg($ROOT . '/bin/sync-worker.php') . ' --once > /tmp/worker.log 2>&1', $rc);
$run = $pdo->query("SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($run['status'] === 'failed', "run should fail against unreachable CMS, got {$run['status']}");
ok(!empty($run['error']), 'failure reason stored');
$st = json_decode($admin->get('/api/sync/status')['body'], true);
ok(($st['state'] ?? '') === 'problem', "state 'problem' after failed run, got " . ($st['state'] ?? '?'));

echo "== blackout behaviour\n";
// A window covering right now, every day.
$now = new DateTimeImmutable('now');
$start = $now->modify('-10 minutes')->format('H:i');
$end   = $now->modify('+50 minutes')->format('H:i');
setting($pdo, 'sync.blackouts', json_encode([['days' => [1,2,3,4,5,6,7], 'start' => $start, 'end' => $end]]));
$pdo->exec("DELETE FROM sync_runs");

$ship = new Http($BASE);
$ship->login('syncf_ship', 'SyncTest123!');
$resp = $ship->post('/api/sync/run', ['_csrf_token' => $ship->csrfFrom('/')]);
$j = json_decode($resp['body'], true);
ok(($j['blackout'] ?? false) === true && ($j['ok'] ?? true) === false,
    'shipping user refused during blackout: ' . $resp['body']);
ok(($j['can_override'] ?? true) === false, 'shipping user has no override');

$st = json_decode($ship->get('/api/sync/status')['body'], true);
ok(($st['state'] ?? '') === 'blackout', "status state 'blackout', got " . ($st['state'] ?? '?'));

// Margin Watchdog live queries pause with the friendly message (spec §14 Q9).
$exec = new Http($BASE);
$pdo->prepare("INSERT INTO users (username,password_hash,display_name,is_active) VALUES ('syncf_exec',?,?,1)")
    ->execute([$hash, 'syncf_exec']);
$uid = (int) $pdo->lastInsertId();
$gid = $pdo->query("SELECT id FROM permission_groups WHERE name='Executives'")->fetchColumn();
$pdo->prepare("INSERT INTO user_group_members VALUES (?,?)")->execute([$uid, (int) $gid]);
$exec->login('syncf_exec', 'SyncTest123!');
$mw = $exec->get('/margin-watchdog?baseline_start=2026-01-01&baseline_end=2026-01-31&comparison_start=2026-02-01&comparison_end=2026-02-28');
ok($mw['status'] === 200 && str_contains($mw['body'], 'Paused for the nightly backup'),
    'Margin Watchdog shows the blackout pause message');

// Admin override queues a run.
$resp = $admin->post('/api/sync/run', ['_csrf_token' => $admin->csrfFrom('/'), 'override' => '1']);
$j = json_decode($resp['body'], true);
ok(($j['ok'] ?? false) === true, 'admin override queues during blackout: ' . $resp['body']);
ok((int) $pdo->query("SELECT override_blackout FROM sync_runs ORDER BY id DESC LIMIT 1")->fetchColumn() === 1,
    'override flag recorded');
$pdo->exec("DELETE FROM sync_runs WHERE status='queued'");

echo "== kill switch\n";
setting($pdo, 'sync.blackouts', null);
setting($pdo, 'cms.kill_switch', '1');
$st = json_decode($admin->get('/api/sync/status')['body'], true);
ok(($st['state'] ?? '') === 'killed', "status 'killed', got " . ($st['state'] ?? '?'));
$resp = $admin->post('/api/sync/run', ['_csrf_token' => $admin->csrfFrom('/')]);
$j = json_decode($resp['body'], true);
ok(($j['ok'] ?? true) === false, 'sync refused while kill switch on');
$mw = $exec->get('/margin-watchdog?baseline_start=2026-01-01&baseline_end=2026-01-31&comparison_start=2026-02-01&comparison_end=2026-02-28');
ok($mw['status'] === 200 && str_contains($mw['body'], 'switched off by an administrator'),
    'Margin Watchdog shows the kill-switch message');

echo "== recovery to ok\n";
setting($pdo, 'cms.kill_switch', '0');
$pdo->exec("DELETE FROM sync_runs");
setting($pdo, 'sync.last_success_at', date('Y-m-d H:i:s'));
$st = json_decode($admin->get('/api/sync/status')['body'], true);
ok(($st['state'] ?? '') === 'ok', "status back to 'ok', got " . ($st['state'] ?? '?'));

setting($pdo, 'sync.last_success_at', date('Y-m-d H:i:s', time() - 4 * 3600));
$st = json_decode($admin->get('/api/sync/status')['body'], true);
ok(($st['state'] ?? '') === 'stale', "4h-old data with 15-min interval is 'stale', got " . ($st['state'] ?? '?'));

setting($pdo, 'sync.auto_enabled', '0');
$st = json_decode($admin->get('/api/sync/status')['body'], true);
ok(($st['state'] ?? '') === 'auto_off', "auto off state, got " . ($st['state'] ?? '?'));
setting($pdo, 'sync.auto_enabled', '1');

echo "== admin CMS & Sync page\n";
$page = $admin->get('/admin/cms');
ok($page['status'] === 200, 'admin cms page loads');
ok(str_contains($page['body'], 'Kill switch') && str_contains($page['body'], 'Blackout windows'),
    'admin cms page has its sections');
$noAdmin = $ship->get('/admin/cms');
ok($noAdmin['status'] === 404, 'shipping user gets 404 on /admin/cms');

/* cleanup */
setting($pdo, 'sync.last_success_at', null);
unlink($ROOT . '/config/cms.local.php');
$pdo->exec("DELETE FROM users WHERE username LIKE 'syncf\\_%'");
$pdo->exec("DELETE FROM sync_runs");

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);

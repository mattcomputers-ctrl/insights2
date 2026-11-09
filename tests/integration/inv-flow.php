<?php

declare(strict_types=1);

/**
 * Inventory Browser — per-column permission gating end-to-end.
 * A user granted only some columns must not receive the others in the
 * HTML, and must not be able to search by hidden vendor codes.
 *
 * Usage: php tests/integration/inv-flow.php [base-url]
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
require_once __DIR__ . '/http-client.php';

/* ── Seed data ───────────────────────────────────────────────────── */
$pdo->exec("DELETE FROM inv_items");
$pdo->exec("DELETE FROM inv_vendor_codes");
$pdo->exec("INSERT INTO inv_items VALUES
    ('SOC0403', 'TEST MONOMER', 'lb', 'SUNDRY', 1448.0, 0, 0, 120.5, 1763.7),
    ('ZERO-01', 'NO ACTIVITY ITEM', 'lb', 'SUNDRY', 0, 0, 0, 0, 0)");
$pdo->exec("INSERT INTO inv_vendor_codes VALUES
    ('SOC0403', 'AAL CHEM', 'EM2387-TF', 10, '2026-10-27'),
    ('SOC0403', 'ARKEMA', 'SR9020', 1, '2026-08-10')");

$pdo->exec("DELETE FROM users WHERE username LIKE 'invf\\_%'");
$pdo->exec("DELETE FROM permission_groups WHERE name = 'InvTest'");
$pdo->exec("INSERT INTO permission_groups (name, description, is_admin, priority) VALUES ('InvTest', 'inv test', 0, 80)");
$gid = (int) $pdo->lastInsertId();
foreach (['inventory.view', 'inventory.col_on_hand', 'export'] as $perm) {
    $pdo->prepare("INSERT INTO group_permission_grants VALUES (?,?)")->execute([$gid, $perm]);
}
$hash = password_hash('InvFlow123!', PASSWORD_ARGON2ID);
$pdo->prepare("INSERT INTO users (username,password_hash,display_name,is_active) VALUES ('invf_user',?,?,1)")
    ->execute([$hash, 'invf_user']);
$uid = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_group_members VALUES (?,?)")->execute([$uid, $gid]);
$pdo->prepare("INSERT INTO users (username,password_hash,display_name,is_active) VALUES ('invf_admin',?,?,1)")
    ->execute([$hash, 'invf_admin']);
$uid = (int) $pdo->lastInsertId();
$agid = (int) $pdo->query("SELECT id FROM permission_groups WHERE name='Administrators'")->fetchColumn();
$pdo->prepare("INSERT INTO user_group_members VALUES (?,?)")->execute([$uid, $agid]);

echo "== partial-column user\n";
$u = new Http($BASE);
$u->login('invf_user', 'InvFlow123!');
$page = $u->get('/inventory');
ok($page['status'] === 200, 'inventory page loads');
ok(str_contains($page['body'], 'SOC0403'), 'item shown');
ok(str_contains($page['body'], 'On hand'), 'granted column rendered');
ok(str_contains($page['body'], '1,448'), 'on-hand quantity rendered');
ok(!str_contains($page['body'], 'Vendor codes'), 'ungranted vendor-codes column absent');
ok(!str_contains($page['body'], 'EM2387'), 'vendor code values never sent');
ok(!str_contains($page['body'], 'On purchase orders'), 'ungranted PO column absent');
ok(!str_contains($page['body'], '1,763'), 'PO quantity never sent');

// Hidden columns are not searchable either.
$hit = $u->get('/inventory?q=EM2387');
ok(!str_contains($hit['body'], 'SOC0403'), 'search by hidden vendor code finds nothing');

// Zero-activity items hidden by default, shown with the toggle.
ok(!str_contains($page['body'], 'ZERO-01'), 'zero-activity item hidden by default');
ok(str_contains($page['body'], 'all=1'), 'show-all toggle link carries the new state');
$all = $u->get('/inventory?all=1');
ok(str_contains($all['body'], 'ZERO-01'), 'show-all includes zero-activity items');

// Export carries only granted columns.
$csv = $u->get('/inventory/export?format=csv');
ok($csv['status'] === 200 && str_contains($csv['body'], 'On hand'), 'export includes granted column');
ok(!str_contains($csv['body'], 'EM2387') && !str_contains($csv['body'], 'Vendor codes'), 'export excludes hidden columns');

echo "== admin sees everything\n";
$a = new Http($BASE);
$a->login('invf_admin', 'InvFlow123!');
$page = $a->get('/inventory');
// Right-click menu (Matt, 2026-11-04): items carry the ctx markup, and
// the admin's menu config has the cross-module entries.
ok(str_contains($page['body'], 'item-ctx') && str_contains($page['body'], 'data-item="SOC0403"'),
    'item codes carry the right-click menu markup');
ok(str_contains($page['body'], 'PII_ITEM_MENU') && str_contains($page['body'], 'View active recipe')
    && str_contains($page['body'], '/orders/by-item?q={code}'),
    'admin menu config carries the cross-module links');
ok(str_contains($page['body'], 'Vendor codes') && str_contains($page['body'], 'EM2387-TF'),
    'admin sees vendor codes (one line per code)');
ok(str_contains($page['body'], 'SR9020'), 'second vendor code listed');
$hit = $a->get('/inventory?q=EM2387');
ok(str_contains($hit['body'], 'SOC0403'), 'admin can search by vendor code');

/* cleanup */
$pdo->exec("DELETE FROM users WHERE username LIKE 'invf\\_%'");
$pdo->exec("DELETE FROM permission_groups WHERE name = 'InvTest'");
$pdo->exec("DELETE FROM inv_items");
$pdo->exec("DELETE FROM inv_vendor_codes");

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);

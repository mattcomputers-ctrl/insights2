<?php

declare(strict_types=1);

/**
 * Order Readiness end-to-end (steps 7–8): seeded snapshot data through the
 * real pages — list, expand, notes, priority drag → recompute, production
 * marks, exports. Runs against the same disposable instance as the crawl.
 *
 * Usage: php tests/integration/or-flow.php [base-url]
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

require_once __DIR__ . '/http-client.php';

/* ── Seed users ──────────────────────────────────────────────────── */
$hash = password_hash('OrFlow123!', PASSWORD_ARGON2ID);
$pdo->exec("DELETE FROM users WHERE username LIKE 'orf\\_%'");
foreach (['orf_prod' => 'Production', 'orf_ship' => 'Shipping'] as $u => $g) {
    $pdo->prepare("INSERT INTO users (username,password_hash,display_name,is_active) VALUES (?,?,?,1)")
        ->execute([$u, $hash, $u]);
    $uid = (int) $pdo->lastInsertId();
    $gid = $pdo->query("SELECT id FROM permission_groups WHERE name = " . $pdo->quote($g))->fetchColumn();
    $pdo->prepare("INSERT INTO user_group_members VALUES (?,?)")->execute([$uid, (int) $gid]);
}

/* ── Seed a snapshot scenario ───────────────────────────────────────
   Order 9001 (PRIORITY CO, due first): 100 lb of A-50 — stock has 80.
   Order 9002 (SECOND CO): 50 lb of A-50 + 10 lb of D-50 (discontinued).
   Ticket 7001 (MFPP, RLS) makes 60 lb of A-50.
   With 9001 first: 9001 gets 80 stock + 20 ticket → waiting;
   9002 gets 40 ticket → short 10; D-50 line short_discontinued.        */
foreach (['or_items','or_inventory','or_orders','or_order_lines','or_tickets',
          'or_ticket_links','or_ticket_materials','or_ticket_readiness',
          'or_coverage_results','or_production_suggestions',
          'or_order_priority','or_order_notes','or_suggestion_marks','nto_items','nto_min_items'] as $t) {
    $pdo->exec("DELETE FROM {$t}");
}
$pdo->exec("INSERT INTO or_items (item_code, description, unit, is_pack, has_recipe, recipe_number, minimum_stock, is_discontinued) VALUES
    ('A-50', 'TEST BLUE INK 50LB', 'lb', 1, 1, 'A.03', 100, 0),
    ('D-50', 'TEST DISCONTINUED INK', 'lb', 1, 1, 'D.01', 99, 1),
    ('VEC-7', 'TEST VEHICLE RESIN', 'lb', 0, 0, NULL, NULL, 0)");
$pdo->exec("INSERT INTO or_inventory (item_code, qty_usable, qty_soh, qty_hold, qty_unusable) VALUES
    ('A-50', 80, 85, 5, 0), ('D-50', 0, 0, 0, 0)");
$pdo->exec("INSERT INTO or_orders (ordr, status, bill_to, bill_to_name, ship_to, ship_to_name, po_number, date_ordered, date_required) VALUES
    (9001, 'RTS', 'PRIO', 'PRIORITY CO', 'PRIO', 'PRIORITY CO DOCK', 'PO-1', '2026-10-01', '2026-10-20'),
    (9002, 'RTS', 'SECO', 'SECOND CO',   'SECO', 'SECOND CO DOCK',  'PO-2', '2026-10-02', '2026-10-25')");
$pdo->exec("INSERT INTO or_order_lines (ord_detail, ordr, item_code, alias_code, alias_description, qty_reqd, qty_used, qty_remaining, qty_committed_cms, unit) VALUES
    (1, 9001, 'A-50', 'CUST-A', 'CUSTOMER BLUE', 100, 0, 100, 0, 'lb'),
    (2, 9002, 'A-50', 'CUST-A', 'CUSTOMER BLUE',  50, 0,  50, 0, 'lb'),
    (3, 9002, 'D-50', 'CUST-D', 'CUSTOMER OLD RED', 10, 0, 10, 0, 'lb')");
$pdo->exec("INSERT INTO or_tickets (ordr, context, status, item_code, qty, unit, date_required, customer_hint, batch_ordr) VALUES
    (7001, 'MFPP', 'RLS', 'A-50', 60, 'lb', '2026-11-03', 'PRIORITY CO', 7000)");
// Materials check: ticket 7001 is short 12.5 lb of VEC-7 (Matt, 2026-10-29).
$pdo->exec("INSERT INTO or_ticket_materials (ordr, item_code, qty_reqd, unit) VALUES
    (7001, 'VEC-7', 12.5, 'lb')");
$pdo->exec("INSERT INTO or_ticket_readiness (ordr, status, shortages, from_mfba, mfba_short) VALUES
    (7001, 'short', '[{\"item_code\":\"VEC-7\",\"need\":12.5,\"short\":12.5,\"unit\":\"lb\"}]', 0, 0)");
$pdo->exec("UPDATE or_orders SET is_backorder = 1 WHERE ordr = 9001");
$pdo->exec("INSERT INTO or_order_priority (ordr, position) VALUES (9001, 1), (9002, 2)");
$pdo->exec("INSERT INTO or_production_suggestions (item_code, severity, minimum_stock, on_hand, on_tickets, open_demand, projected, shortfall, suggested_qty, recipe_number) VALUES
    ('A-50', 'below', 100, 80, 60, 150, -10, 110, 150, 'A.03')");

/* ── Priority save triggers the pure recompute from the snapshot ─── */
echo "== priority save recomputes coverage\n";
$prod = new Http($BASE);
$prod->login('orf_prod', 'OrFlow123!');
$resp = $prod->post('/orders/priority', [
    '_csrf_token' => $prod->csrfFrom('/orders'),
    'positions'   => '9001,9002',
]);
$j = json_decode($resp['body'], true);
ok(($j['ok'] ?? false) === true, 'priority save ok: ' . $resp['body']);

$cov = [];
foreach ($pdo->query("SELECT ord_detail, status, shortfall, reason FROM or_coverage_results") as $r) {
    $cov[(int) $r['ord_detail']] = $r;
}
ok(count($cov) === 3, 'coverage computed for all 3 lines');
ok(($cov[1]['status'] ?? '') === 'waiting', 'priority order line waits on the ticket (80 stock + 20 ticket)');
ok(($cov[2]['status'] ?? '') === 'short' && abs((float) $cov[2]['shortfall'] - 10.0) < 1e-6,
    'second order shorts by 10 (got ' . ($cov[2]['shortfall'] ?? '?') . ')');
ok(($cov[3]['status'] ?? '') === 'short_discontinued', 'discontinued line flagged');
ok(str_contains((string) $cov[1]['reason'], 'batch ticket 7000'), 'reason names the MAIN batch number (7000), not the packaging order');

/* ── Reversed priority flips who gets the stock ─────────────────── */
$resp = $prod->post('/orders/priority', [
    '_csrf_token' => $prod->csrfFrom('/orders'),
    'positions'   => '9002,9001',
]);
$j = json_decode($resp['body'], true);
ok(($j['ok'] ?? false) === true, 'reversed priority save ok');
$cov = [];
foreach ($pdo->query("SELECT ord_detail, status, shortfall FROM or_coverage_results") as $r) {
    $cov[(int) $r['ord_detail']] = $r;
}
ok(($cov[2]['status'] ?? '') === 'ready', 'now SECOND CO line is fully covered from stock');
ok(($cov[1]['status'] ?? '') === 'short' && abs((float) $cov[1]['shortfall'] - 10.0) < 1e-6,
    'PRIORITY CO now shorts by 10 after losing first claim');

// Shipping user cannot reorder (production.mark required).
$ship = new Http($BASE);
$ship->login('orf_ship', 'OrFlow123!');
$resp = $ship->post('/orders/priority', [
    '_csrf_token' => $ship->csrfFrom('/orders'),
    'positions'   => '9001,9002',
]);
ok($resp['status'] === 404, 'shipping user denied priority reorder (404)');

/* ── Pages render the scenario ──────────────────────────────────── */
echo "== pages\n";
$page = $ship->get('/orders/list?tab=all');
ok($page['status'] === 200 && str_contains($page['body'], '9001') && str_contains($page['body'], 'PRIORITY CO'),
    'orders list shows the seeded orders');
ok(str_contains($page['body'], 'Backorder'), 'partially shipped open order is flagged Backorder');
$page = $ship->get('/orders/list?tab=discontinued');
ok(str_contains($page['body'], '9002'), 'discontinued tab shows order 9002');
ok(str_contains($page['body'], 'DISCONTINUED'), 'discontinued badge visible');

$frag = $ship->get('/orders/order/9002/lines');
ok(str_contains($frag['body'], 'CUST-D') && str_contains($frag['body'], 'DISCONTINUED'),
    'line fragment shows discontinued line');
ok(str_contains($frag['body'], 'Needs 10 lb'), 'plain-English reason rendered');

$page = $ship->get('/orders/by-item');
ok($page['status'] === 200 && str_contains($page['body'], 'A-50'), 'by-item page shows demand');
// Right-click menu config is permission-gated: shipping has by-item but
// neither recipes nor inventory nor purchasing.
ok(str_contains($page['body'], '/orders/by-item?q={code}')
    && !str_contains($page['body'], 'View active recipe')
    && !str_contains($page['body'], '/inventory?q={code}')
    && !str_contains($page['body'], '/need-to-order?q={code}'),
    'item menu only offers what the user may see');
$page = $ship->get('/orders/tickets');
ok($page['status'] === 200 && str_contains($page['body'], '7000') && str_contains($page['body'], 'PRIORITY CO'),
    'tickets page shows the master batch + cut-for hint');
ok(str_contains($page['body'], 'Packaging order 7001'),
    'the pack is listed INSIDE the master batch line');
ok(!str_contains($page['body'], '<strong>7001</strong>'),
    'the pack never gets a batch line of its own');

/* ── Materials check surfaces (Matt, 2026-10-29) ────────────────── */
echo "== materials check\n";
ok(str_contains($page['body'], 'Short materials'), 'tickets page flags the materials-short ticket');
ok(str_contains($page['body'], 'VEC-7'), 'tickets page names the missing material');
ok(str_contains($page['body'], '12.5'), 'tickets page shows the missing quantity');
ok(str_contains($page['body'], 'short the materials needed'), 'tickets subtitle counts short tickets');

$page = $ship->get('/orders/list?tab=all');
ok(str_contains($page['body'], 'batch short materials'), 'orders list warns when a covering batch lacks materials');

$frag = $ship->get('/orders/order/9001/lines');
ok(str_contains($frag['body'], 'Batch ticket 7000') && str_contains($frag['body'], "can't be made yet"),
    'order drill-down explains the batch is short materials (by MAIN batch number)');
ok(str_contains($frag['body'], 'VEC-7'), 'drill-down names the missing material');

// Consolidated short-materials tab (Matt, 2026-10-29).
$page = $ship->get('/orders/short-materials');
ok($page['status'] === 200 && str_contains($page['body'], 'VEC-7'), 'short-materials tab lists the material');
ok(str_contains($page['body'], 'TEST VEHICLE RESIN'), 'material description shown');
ok(str_contains($page['body'], '12.5'), 'total short quantity shown');
ok(str_contains($page['body'], 'batch 7000'), 'names the master batch needing it');
$page = $ship->get('/orders/short-materials?q=VEC');
ok(str_contains($page['body'], 'VEC-7'), 'short-materials search matches');
$page = $ship->get('/orders/short-materials?q=zzz-none');
ok(!str_contains($page['body'], 'VEC-7'), 'short-materials search filters out non-matches');
$csv = $ship->get('/orders/export/short-materials?format=csv');
ok($csv['status'] === 200 && str_contains($csv['body'], 'VEC-7') && str_contains($csv['body'], '12.5'),
    'short-materials export works');

// Search on batch tickets and the production plan (Matt, 2026-10-29).
$page = $ship->get('/orders/tickets?q=A-50');
ok(str_contains($page['body'], '7000'), 'tickets search finds the batch by pack item');
$page = $ship->get('/orders/tickets?q=zzz-none');
ok(!str_contains($page['body'], '7000') && str_contains($page['body'], 'No batch matches'),
    'tickets search filters out non-matches');
$page = $prod->get('/orders/production?q=A-50');
ok(str_contains($page['body'], 'A-50'), 'production search finds the item');
$page = $prod->get('/orders/production?q=zzz-none');
ok(!str_contains($page['body'], 'TEST BLUE INK') && str_contains($page['body'], 'matches your search'),
    'production search filters out non-matches');

$csv = $ship->get('/orders/export/tickets?format=csv');
if ($csv['status'] === 200) {
    ok(str_contains($csv['body'], 'Materials') && str_contains($csv['body'], 'VEC-7 -12.5 lb'),
        'tickets export carries the materials column');
    ok(str_contains($csv['body'], 'Batch #') && !str_contains($csv['body'], '7001,'),
        'export is one row per master batch');
} else {
    ok($csv['status'] === 404, 'shipping user without export permission gets a plain 404');
}

/* ── Notes: add, render, search ─────────────────────────────────── */
echo "== order notes\n";
$resp = $ship->post('/orders/order/9001/notes', [
    '_csrf_token' => $ship->csrfFrom('/orders'),
    'note'        => 'Customer called: hold shipment until the PO is amended.',
]);
$j = json_decode($resp['body'], true);
ok(($j['ok'] ?? false) === true, 'note added: ' . $resp['body']);

$frag = $ship->get('/orders/order/9001/lines');
ok(str_contains($frag['body'], 'hold shipment until the PO is amended'), 'note shows on the order');
ok(str_contains($frag['body'], 'orf_ship'), 'note shows who wrote it');

$page = $ship->get('/orders/notes?q=amended');
ok(str_contains($page['body'], 'hold shipment') && str_contains($page['body'], '9001'),
    'notes search finds it by text');
$page = $ship->get('/orders/notes?q=9001');
ok(str_contains($page['body'], 'hold shipment'), 'notes search finds it by order number');
$page = $ship->get('/orders/notes?q=zzz-no-match');
ok(!str_contains($page['body'], 'hold shipment'), 'non-matching search excludes it');

// Notes survive the order leaving the snapshot (prior-order lookup).
$pdo->exec("DELETE FROM or_orders WHERE ordr = 9001");
$page = $ship->get('/orders/notes?q=amended');
ok(str_contains($page['body'], 'hold shipment'), 'note still searchable after the order closed');

/* ── Need to Order (Matt, 2026-11-02) ───────────────────────────── */
echo "== need to order\n";
$pdo->exec("DELETE FROM users WHERE username = 'orf_buyer'");
$pdo->exec("DELETE FROM permission_groups WHERE name = 'OrfBuyers'");
$pdo->exec("INSERT INTO permission_groups (name, description, is_admin, priority) VALUES ('OrfBuyers', 'nto test', 0, 70)");
$bgid = (int) $pdo->lastInsertId();
foreach (['purchasing.view', 'export'] as $perm) {
    $pdo->prepare("INSERT INTO group_permission_grants VALUES (?,?)")->execute([$bgid, $perm]);
}
$pdo->prepare("INSERT INTO users (username,password_hash,display_name,is_active) VALUES ('orf_buyer',?,?,1)")
    ->execute([$hash, 'orf_buyer']);
$pdo->prepare("INSERT INTO user_group_members VALUES (?,?)")
    ->execute([(int) $pdo->lastInsertId(), $bgid]);

$pdo->exec("INSERT INTO nto_items (item_code, description, unit, qty_needed, on_po, first_needed, src_ticket, src_order, has_recipe, preferred_vendor, vendor, vendor_code, vendor_count, refs, via) VALUES
    ('SOC0403', 'TEST MONOMER', 'lb', 120.5, 50, '2026-11-10', 1, 0, 0, 'AAL CHEM', 'AAL CHEM', 'EM2387-TF', 2,
     '[{\"ref\":\"batch 7000 (A-50)\",\"src\":\"ticket\"}]', '[]'),
    ('PGR1000', 'TEST PIGMENT', 'lb', 40, 0, '2026-11-05', 0, 1, 1, 'OTHER PREF CO', 'SUN CHEMICAL', 'PGR-X', 1,
     '[{\"ref\":\"order 9001 (CUST-A)\",\"src\":\"order\"}]', '[\"E1000\"]'),
    ('NOVEND', 'TEST NEVER BOUGHT', 'lb', 5, 0, NULL, 1, 0, 0, NULL, NULL, NULL, 0,
     '[{\"ref\":\"batch 7000 (A-50)\",\"src\":\"ticket\"}]', '[]')");
$pdo->exec("INSERT INTO nto_min_items (item_code, description, unit, qty_needed, minimum_stock, on_hand, projected, on_po, first_needed, src_ticket, src_order, has_recipe, preferred_vendor, vendor, vendor_code, vendor_count, refs, via) VALUES
    ('MINRAW', 'TEST MIN STOCK RAW', 'lb', 120, 100, 60, -20, 0, '2026-11-12', 1, 0, 0, 'AAL CHEM', 'AAL CHEM', 'MR-77', 1,
     '[{\"ref\":\"batch 7000 (A-50)\",\"src\":\"ticket\"}]', '[]')");

$buyer = new Http($BASE);
$buyer->login('orf_buyer', 'OrFlow123!');
$page = $buyer->get('/need-to-order');
ok($page['status'] === 200 && str_contains($page['body'], 'SOC0403') && str_contains($page['body'], 'PGR1000'),
    'need-to-order lists the buy list');
ok(str_contains($page['body'], 'AAL CHEM') && str_contains($page['body'], 'EM2387-TF'),
    'vendor and their item code shown');
ok(str_contains($page['body'], 'Preferred vendor') && str_contains($page['body'], 'OTHER PREF CO'),
    'preferred vendor column shown');
ok(str_contains($page['body'], 'batch 7000') && str_contains($page['body'], 'order 9001'),
    'root references shown (ticket + order)');
ok(str_contains($page['body'], 'to make E1000'), 'made-via chain shown');
ok(strpos($page['body'], 'SOC0403') < strpos($page['body'], 'PGR1000'),
    'open-ticket needs come before new-batch needs even when due later');

// The group buttons must actually carry the new state in their hrefs
// (regression: PHP's + kept the old value and the buttons did nothing).
ok(str_contains($page['body'], 'group=preferred') && str_contains($page['body'], 'group=last'),
    'group-by buttons link to the grouped views');

$page = $buyer->get('/need-to-order?group=last');
ok(str_contains($page['body'], 'Never purchased'), 'group by LAST vendor: never-purchased group present');
ok(str_contains($page['body'], '<details class="vendor-group"') && !str_contains($page['body'], '<details class="vendor-group" open'),
    'vendor groups are collapsible and start collapsed');
ok(str_contains($page['body'], 'Expand all'), 'expand-all control present');
$hit = $buyer->get('/need-to-order?group=last&q=PGR');
ok(str_contains($hit['body'], '<details class="vendor-group" open'),
    'groups auto-open while searching');
ok(strpos($page['body'], 'AAL CHEM') < strpos($page['body'], 'SUN CHEMICAL'),
    'last-vendor groups are alphabetical');

$page = $buyer->get('/need-to-order?group=preferred');
ok(str_contains($page['body'], 'No preferred vendor set'), 'group by PREFERRED vendor: unset group present');
ok(strpos($page['body'], 'AAL CHEM') < strpos($page['body'], 'OTHER PREF CO'),
    'preferred-vendor groups are alphabetical');

$page = $buyer->get('/need-to-order?q=PGR');
ok(str_contains($page['body'], 'PGR1000') && !str_contains($page['body'], 'SOC0403'),
    'need-to-order search filters');

$csv = $buyer->get('/need-to-order/export?format=csv');
ok($csv['status'] === 200 && str_contains($csv['body'], 'Vendor code') && str_contains($csv['body'], 'EM2387-TF'),
    'need-to-order export works');

// Minimum-stock lens (Matt, 2026-11-02): projected stock, topped back to min.
$page = $buyer->get('/need-to-order');
ok(str_contains($page['body'], 'view=min') && !str_contains($page['body'], 'MINRAW'),
    'orders lens links to the min lens and hides min-only rows');
$page = $buyer->get('/need-to-order?view=min');
ok(str_contains($page['body'], 'MINRAW') && !str_contains($page['body'], 'SOC0403'),
    'min lens shows min-stock rows only');
ok(str_contains($page['body'], 'Projected') && str_contains($page['body'], '-20'),
    'projected column rendered');
$page = $buyer->get('/need-to-order?view=min&group=preferred');
ok(str_contains($page['body'], 'AAL CHEM') && str_contains($page['body'], 'vendor-group'),
    'min lens groups by vendor too');
$csv = $buyer->get('/need-to-order/export?view=min&format=csv');
ok($csv['status'] === 200 && str_contains($csv['body'], 'Minimum') && str_contains($csv['body'], 'MINRAW'),
    'min lens export carries the minimum/projected columns');

$page = $prod->get('/need-to-order');
ok($page['status'] === 404, 'user without purchasing.view gets a plain 404');

/* ── Production marks ───────────────────────────────────────────── */
echo "== production marks\n";
$page = $prod->get('/orders/production');
ok($page['status'] === 200 && str_contains($page['body'], 'A-50'), 'production plan lists A-50');
$prod->post('/orders/production/mark', [
    '_csrf_token' => $prod->csrfFrom('/orders/production'),
    'item_code'   => 'A-50',
    'mark'        => 'scheduled',
    'note'        => 'Milling Thursday',
]);
$row = $pdo->query("SELECT mark, note FROM or_suggestion_marks WHERE item_code='A-50'")->fetch(PDO::FETCH_ASSOC);
ok(($row['mark'] ?? '') === 'scheduled' && $row['note'] === 'Milling Thursday', 'mark persisted');
$page = $prod->get('/orders/production');
ok(str_contains($page['body'], 'Scheduled'), 'mark visible on the plan');
$page = $ship->get('/orders/production');
ok($page['status'] === 404, 'shipping user has no production plan');

/* ── Exports ────────────────────────────────────────────────────── */
echo "== exports\n";
$csv = $prod->get('/orders/export/orders?format=csv');
ok($csv['status'] === 200 && str_contains($csv['body'], '9002'), 'orders CSV export works');
$csv = $prod->get('/orders/export/production?format=csv');
ok($csv['status'] === 200 && str_contains($csv['body'], 'A-50'), 'production CSV export works');
$xlsx = $prod->get('/orders/export/tickets');
ok($xlsx['status'] === 200 && str_starts_with($xlsx['body'], 'PK'), 'tickets XLSX export streams a zip');

/* ── Cleanup ────────────────────────────────────────────────────── */
foreach (['or_items','or_inventory','or_orders','or_order_lines','or_tickets',
          'or_ticket_links','or_ticket_materials','or_ticket_readiness',
          'or_coverage_results','or_production_suggestions',
          'or_order_priority','or_order_notes','or_suggestion_marks','nto_items'] as $t) {
    $pdo->exec("DELETE FROM {$t}");
}
$pdo->exec("DELETE FROM users WHERE username LIKE 'orf\\_%'");
$pdo->exec("DELETE FROM permission_groups WHERE name = 'OrfBuyers'");

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);

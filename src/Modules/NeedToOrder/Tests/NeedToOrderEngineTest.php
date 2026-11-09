<?php

declare(strict_types=1);

/**
 * NeedToOrderEngine — pure-function tests (Matt, 2026-11-02: the buy list
 * behind the Need to Order module; items with an active recipe are MADE,
 * never bought, so they never appear). Run via tests/run.php.
 */

require_once PII_TEST_ROOT . '/src/Modules/NeedToOrder/NeedToOrderEngine.php';

use PII\Modules\NeedToOrder\NeedToOrderEngine;

$d = fn(string $item, float $qty, ?string $due, string $ref, string $src = 'ticket') =>
    ['item' => $item, 'qty' => $qty, 'due' => $due, 'ref' => $ref, 'src' => $src];

t_section('NeedToOrderEngine: ticket shortages pass straight through');

// A recipe-less material short on a ticket goes on the list as-is.
$r = NeedToOrderEngine::compute(
    [$d('VEC-1', 120, '2026-11-10', 'batch 1001 (E1000)')],
    [], [], []
);
t_check(isset($r['VEC-1']) && abs($r['VEC-1']['qty'] - 120.0) < 1e-6, 'shortage listed in full');
t_check($r['VEC-1']['due'] === '2026-11-10', 'due date carried');
t_check($r['VEC-1']['refs'][0]['ref'] === 'batch 1001 (E1000)', 'root reference carried');
t_check($r['VEC-1']['srcs']['ticket'] === true && $r['VEC-1']['srcs']['order'] === false, 'source tracked');

// Leftover stock and incoming ticket output net it down first.
$r = NeedToOrderEngine::compute(
    [$d('VEC-1', 120, '2026-11-10', 'batch 1001 (E1000)')],
    ['VEC-1' => 30], ['VEC-1' => 40], []
);
t_check(abs($r['VEC-1']['qty'] - 50.0) < 1e-6, 'netted against stock then ticket output (120-30-40)');

$r = NeedToOrderEngine::compute(
    [$d('VEC-1', 60, null, 'batch 1001 (E1000)')],
    ['VEC-1' => 100], [], []
);
t_check($r === [], 'fully stocked demand orders nothing');

t_section('NeedToOrderEngine: order shortfalls explode to raw materials');

// Order needs 100 of pack P; pack = bulk 1.0/lb + pails 0.2/lb;
// bulk = 60% vehicle + 40% pigment. Nothing anywhere in stock.
$recipes = [
    'P-50'   => [['item' => 'BULK-P', 'qty_per' => 1.0], ['item' => 'PAIL', 'qty_per' => 0.2]],
    'BULK-P' => [['item' => 'VEH-1', 'qty_per' => 0.6], ['item' => 'PIG-1', 'qty_per' => 0.4]],
];
$r = NeedToOrderEngine::compute(
    [$d('P-50', 100, '2026-11-20', 'order 9001 (P-50)', 'order')],
    [], [], $recipes
);
t_check(abs(($r['VEH-1']['qty'] ?? 0) - 60.0) < 1e-6, 'vehicle = 100 × 60%');
t_check(abs(($r['PIG-1']['qty'] ?? 0) - 40.0) < 1e-6, 'pigment = 100 × 40%');
t_check(abs(($r['PAIL']['qty'] ?? 0) - 20.0) < 1e-6, 'pails = 100 × 0.2/lb');
t_check(!isset($r['BULK-P']) && !isset($r['P-50']), 'items with recipes are exploded, never listed');
t_check(($r['VEH-1']['via'] ?? []) === ['P-50', 'BULK-P'], 'chain of what gets made is carried');
t_check($r['VEH-1']['refs'][0]['ref'] === 'order 9001 (P-50)', 'root order reference survives explosion');

// Bulk on hand and bulk already on an open batch reduce the explosion.
$r = NeedToOrderEngine::compute(
    [$d('P-50', 100, '2026-11-20', 'order 9001 (P-50)', 'order')],
    ['BULK-P' => 30], ['BULK-P' => 50], $recipes
);
t_check(abs(($r['VEH-1']['qty'] ?? 0) - 12.0) < 1e-6, 'only the uncovered 20 lb of bulk explodes (20 × 60%)');

// Standard batch size rounds the batch UP before exploding.
$r = NeedToOrderEngine::compute(
    [$d('P-50', 100, '2026-11-20', 'order 9001 (P-50)', 'order')],
    [], [], $recipes, ['BULK-P' => 300]
);
t_check(abs(($r['VEH-1']['qty'] ?? 0) - 180.0) < 1e-6, 'batch rounded to 300 → vehicle 180');
t_check(abs(($r['PAIL']['qty'] ?? 0) - 20.0) < 1e-6, 'pails follow the PACK qty, not the bulk batch');

t_section('NeedToOrderEngine: recipe always means make, never buy');

// Even an item we have BOUGHT before explodes when it has an active
// recipe — it must not appear on the buy list (Matt, 2026-11-02).
$r = NeedToOrderEngine::compute(
    [$d('INT-1', 50, '2026-11-15', 'batch 1001 (E1000)')],
    [], [], ['INT-1' => [['item' => 'RAW-1', 'qty_per' => 0.5], ['item' => 'RAW-2', 'qty_per' => 0.5]]]
);
t_check(!isset($r['INT-1']), 'item with an active recipe never shows on the list');
t_check(abs(($r['RAW-1']['qty'] ?? 0) - 25.0) < 1e-6 && abs(($r['RAW-2']['qty'] ?? 0) - 25.0) < 1e-6,
    'its ingredients show instead');

// Stock of the intermediate still counts before exploding.
$r = NeedToOrderEngine::compute(
    [$d('INT-1', 50, null, 'batch 1001 (E1000)')],
    ['INT-1' => 50], [], ['INT-1' => [['item' => 'RAW-1', 'qty_per' => 1.0]]]
);
t_check($r === [], 'intermediate fully on hand explodes nothing');

// Never purchased, NO recipe → still listed (buyer investigates).
$r = NeedToOrderEngine::compute(
    [$d('MYSTERY', 5, null, 'batch 1001 (E1000)')],
    [], [], []
);
t_check(isset($r['MYSTERY']), 'unknown item still surfaces on the list');

t_section('NeedToOrderEngine: contention, merging, safety');

// Two demands for the same material: stock goes to the EARLIER due date.
$r = NeedToOrderEngine::compute(
    [
        $d('VEC-1', 80, '2026-12-01', 'batch 1002 (E2000)'),
        $d('VEC-1', 80, '2026-11-05', 'batch 1001 (E1000)'),
        $d('VEC-1', 40, null, 'order 9009 (X-1)', 'order'),
    ],
    ['VEC-1' => 100], [], []
);
t_check(abs($r['VEC-1']['qty'] - 100.0) < 1e-6, 'one consolidated line (200 total − 100 stock)');
t_check($r['VEC-1']['due'] === '2026-11-05', 'earliest due date wins');
t_check(count($r['VEC-1']['refs']) === 3, 'every root reference listed');
t_check($r['VEC-1']['srcs']['ticket'] && $r['VEC-1']['srcs']['order'], 'both sources flagged');

// Ticket demand and an exploded order demand merge on the same material.
$r = NeedToOrderEngine::compute(
    [
        $d('PIG-1', 10, '2026-11-08', 'batch 1001 (E1000)'),
        $d('P-50', 100, '2026-11-20', 'order 9001 (P-50)', 'order'),
    ],
    [], [], $recipes
);
t_check(abs(($r['PIG-1']['qty'] ?? 0) - 50.0) < 1e-6, 'ticket 10 + exploded 40 = 50');
t_check($r['PIG-1']['due'] === '2026-11-08', 'earliest root due kept across merge');

// A recipe that references itself can't loop forever.
$r = NeedToOrderEngine::compute(
    [$d('LOOP', 10, null, 'batch 1 (LOOP)')],
    [], [], ['LOOP' => [['item' => 'LOOP', 'qty_per' => 1.0]]]
);
t_check($r === [], 'self-referencing recipe terminates with nothing to buy');

// A two-item recipe cycle hits the depth cap and surfaces the remainder
// instead of silently dropping the demand.
$r = NeedToOrderEngine::compute(
    [$d('A', 10, null, 'batch 1 (A)')],
    [], [], [
        'A' => [['item' => 'B', 'qty_per' => 1.0]],
        'B' => [['item' => 'A', 'qty_per' => 1.0]],
    ]
);
t_check($r !== [] && abs(array_sum(array_column($r, 'qty')) - 10.0) < 1e-6,
    'recipe cycle terminates and the demand still surfaces');

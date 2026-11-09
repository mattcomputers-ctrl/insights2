<?php

declare(strict_types=1);

/**
 * CoverageEngine + SuggestionEngine — pure-function tests (SPEC §8.1/§8.3).
 * Run via tests/run.php.
 */

require_once PII_TEST_ROOT . '/src/Modules/OrderReadiness/CoverageEngine.php';
require_once PII_TEST_ROOT . '/src/Modules/OrderReadiness/SuggestionEngine.php';

use PII\Modules\OrderReadiness\CoverageEngine;
use PII\Modules\OrderReadiness\SuggestionEngine;

t_section('CoverageEngine: basic allocation');

$orders = [100 => ['position' => 1], 200 => ['position' => 2]];
$items  = ['A-50' => ['unit' => 'lb', 'is_discontinued' => false, 'description' => 'A']];

// Fully covered by inventory → ready.
$r = CoverageEngine::compute($orders,
    [['ord_detail' => 1, 'ordr' => 100, 'item_code' => 'A-50', 'qty_remaining' => 100, 'unit' => 'lb', 'alias_code' => 'X']],
    $items, ['A-50' => 250.0], []);
t_check($r[1]['status'] === 'ready', 'inventory-covered line is ready');
t_check($r[1]['shortfall'] == 0.0, 'no shortfall');
t_check(str_contains($r[1]['reason'], '100 lb on hand'), 'reason mentions on-hand allocation');
t_check(str_contains($r[1]['reason'], 'Covered.'), 'reason ends Covered');

// Inventory + ticket → waiting, with ticket detail in the reason.
$r = CoverageEngine::compute($orders,
    [['ord_detail' => 1, 'ordr' => 100, 'item_code' => 'A-50', 'qty_remaining' => 400, 'unit' => 'lb', 'alias_code' => 'X']],
    $items, ['A-50' => 250.0],
    [['ordr' => 88123, 'item_code' => 'A-50', 'qty' => 150, 'unit' => 'lb', 'status' => 'RLS', 'date_required' => '2026-11-03', 'context' => 'MFPP']]);
t_check($r[1]['status'] === 'waiting', 'inventory+ticket line is waiting');
t_check(str_contains($r[1]['reason'], 'batch ticket 88123'), 'reason names the ticket');
t_check(str_contains($r[1]['reason'], 'released'), 'reason has the status word');
t_check(str_contains($r[1]['reason'], 'Nov 3'), 'reason has the due date');

// An MFPP packaging ticket must be named by its MAIN batch (MFBA) number.
$r = CoverageEngine::compute($orders,
    [['ord_detail' => 1, 'ordr' => 100, 'item_code' => 'A-50', 'qty_remaining' => 50, 'unit' => 'lb', 'alias_code' => 'X']],
    $items, [],
    [['ordr' => 193803, 'batch_ordr' => 193802, 'item_code' => 'A-50', 'qty' => 50, 'unit' => 'lb', 'status' => 'NST', 'date_required' => '2026-10-19', 'context' => 'MFPP']]);
t_check(str_contains($r[1]['reason'], 'batch ticket 193802'), 'reason names the MAIN batch number');
t_check(!str_contains($r[1]['reason'], '193803'), 'reason never shows the internal packaging order');
t_check(($r[1]['allocations'][0]['batch'] ?? null) === 193802, 'allocation carries the batch number');
t_check(($r[1]['allocations'][0]['ticket'] ?? null) === 193803, 'allocation keeps the internal id for joins');

t_section('CoverageEngine: competing orders, priority wins');

$lines = [
    ['ord_detail' => 2, 'ordr' => 200, 'item_code' => 'A-50', 'qty_remaining' => 80, 'unit' => 'lb', 'alias_code' => 'X'],
    ['ord_detail' => 1, 'ordr' => 100, 'item_code' => 'A-50', 'qty_remaining' => 80, 'unit' => 'lb', 'alias_code' => 'X'],
];
$r = CoverageEngine::compute($orders, $lines, $items, ['A-50' => 100.0], []);
t_check($r[1]['status'] === 'ready', 'priority order (pos 1) gets the stock first');
t_check($r[2]['status'] === 'short' && abs($r[2]['shortfall'] - 60.0) < 1e-6,
    'lower-priority order shorts by 60 (got ' . $r[2]['shortfall'] . ')');
t_check($r[1]['covered_qty'] + $r[2]['covered_qty'] <= 100.0 + 1e-6, 'stock never double-counted');

t_section('CoverageEngine: ticket pools drain in date order');

$tickets = [
    ['ordr' => 300, 'item_code' => 'A-50', 'qty' => 50, 'unit' => 'lb', 'status' => 'NST', 'date_required' => '2026-12-01', 'context' => 'MFPP'],
    ['ordr' => 299, 'item_code' => 'A-50', 'qty' => 50, 'unit' => 'lb', 'status' => 'RLS', 'date_required' => '2026-11-01', 'context' => 'MFPP'],
];
$r = CoverageEngine::compute($orders,
    [['ord_detail' => 1, 'ordr' => 100, 'item_code' => 'A-50', 'qty_remaining' => 60, 'unit' => 'lb', 'alias_code' => 'X']],
    $items, [], $tickets);
t_check($r[1]['allocations'][0]['ticket'] === 299, 'earliest-due ticket allocated first');
t_check(abs($r[1]['allocations'][0]['qty'] - 50.0) < 1e-6 && abs($r[1]['allocations'][1]['qty'] - 10.0) < 1e-6,
    'ticket split 50 + 10');

t_section('CoverageEngine: edge cases');

// Negative inventory never allocates.
$r = CoverageEngine::compute($orders,
    [['ord_detail' => 1, 'ordr' => 100, 'item_code' => 'A-50', 'qty_remaining' => 10, 'unit' => 'lb', 'alias_code' => 'X']],
    $items, ['A-50' => -25.0], []);
t_check($r[1]['status'] === 'short' && $r[1]['covered_qty'] == 0.0, 'negative inventory treated as zero');

// Discontinued short.
$items2 = ['D-50' => ['unit' => 'lb', 'is_discontinued' => true, 'description' => 'D']];
$r = CoverageEngine::compute($orders,
    [['ord_detail' => 1, 'ordr' => 100, 'item_code' => 'D-50', 'qty_remaining' => 10, 'unit' => 'lb', 'alias_code' => 'X']],
    $items2, [], []);
t_check($r[1]['status'] === 'short_discontinued', 'short discontinued status');
t_check(str_contains($r[1]['reason'], 'DISCONTINUED'), 'reason calls out discontinued');

// Discontinued but covered by stock → still ships fine.
$r = CoverageEngine::compute($orders,
    [['ord_detail' => 1, 'ordr' => 100, 'item_code' => 'D-50', 'qty_remaining' => 10, 'unit' => 'lb', 'alias_code' => 'X']],
    $items2, ['D-50' => 50.0], []);
t_check($r[1]['status'] === 'ready', 'discontinued with stock on hand is still ready');

// Unit mismatch refuses to allocate.
$r = CoverageEngine::compute($orders,
    [['ord_detail' => 1, 'ordr' => 100, 'item_code' => 'A-50', 'qty_remaining' => 10, 'unit' => 'EA', 'alias_code' => 'X']],
    $items, ['A-50' => 500.0], []);
t_check($r[1]['status'] === 'short' && str_contains($r[1]['reason'], 'EA'), 'unit mismatch shorts with explanation');

// Unknown order (no priority row) still allocates, after prioritised ones.
$r = CoverageEngine::compute([],
    [['ord_detail' => 9, 'ordr' => 999, 'item_code' => 'A-50', 'qty_remaining' => 5, 'unit' => 'lb', 'alias_code' => 'X']],
    $items, ['A-50' => 10.0], []);
t_check($r[9]['status'] === 'ready', 'line without priority row still handled');

t_section('CoverageEngine: order roll-up');

$roll = CoverageEngine::rollUp(['ready', 'ready']);
t_check($roll['status'] === 'ready', 'all-ready order rolls up ready');
$roll = CoverageEngine::rollUp(['ready', 'waiting', 'ready']);
t_check($roll['status'] === 'waiting', 'waiting beats ready');
$roll = CoverageEngine::rollUp(['ready', 'short', 'waiting', 'short', 'ready']);
t_check($roll['status'] === 'short' && str_contains($roll['summary'], '2 of 5'), "worst status + '2 of 5 lines short'");
$roll = CoverageEngine::rollUp(['short', 'short_discontinued']);
t_check($roll['status'] === 'short_discontinued', 'discontinued-short is worst');

t_section('SuggestionEngine');

$items3 = [
    'OK-50'   => ['has_recipe' => 1, 'is_discontinued' => 0, 'minimum_stock' => 100.0, 'on_hand' => 500, 'on_tickets' => 0, 'open_demand' => 100],
    'LOW-50'  => ['has_recipe' => 1, 'is_discontinued' => 0, 'minimum_stock' => 100.0, 'on_hand' => 60,  'on_tickets' => 10, 'open_demand' => 0],
    'NEAR-50' => ['has_recipe' => 1, 'is_discontinued' => 0, 'minimum_stock' => 100.0, 'on_hand' => 115, 'on_tickets' => 0, 'open_demand' => 0],
    'NEG-50'  => ['has_recipe' => 1, 'is_discontinued' => 0, 'minimum_stock' => null,  'on_hand' => 20,  'on_tickets' => 0, 'open_demand' => 95],
    'DISC-50' => ['has_recipe' => 1, 'is_discontinued' => 1, 'minimum_stock' => 99.0,  'on_hand' => 0,   'on_tickets' => 0, 'open_demand' => 50],
    'NOREC'   => ['has_recipe' => 0, 'is_discontinued' => 0, 'minimum_stock' => 50.0,  'on_hand' => 0,   'on_tickets' => 0, 'open_demand' => 0],
    'NOMIN'   => ['has_recipe' => 1, 'is_discontinued' => 0, 'minimum_stock' => null,  'on_hand' => 5,   'on_tickets' => 0, 'open_demand' => 0],
];
$s = SuggestionEngine::compute($items3, ['LOW-50' => 90.0], 20.0);

t_check(!isset($s['OK-50']),   'healthy item not suggested');
t_check(!isset($s['DISC-50']), 'discontinued NEVER suggested');
t_check(!isset($s['NOREC']),   'item without recipe not suggested');
t_check(!isset($s['NOMIN']),   'no-minimum item with positive stock not suggested');
t_check(($s['LOW-50']['severity'] ?? '') === 'below', 'below-minimum severity');
t_check(($s['NEAR-50']['severity'] ?? '') === 'near', 'near-minimum severity (115 < 120)');
t_check(($s['NEG-50']['severity'] ?? '') === 'negative', 'negative projected, no minimum → still listed (§14 Q8)');
t_check(array_key_first($s) === 'NEG-50', 'negative severity sorts to the top');

// LOW-50: projected 70, shortfall 30, batch 90 → suggest 90.
t_same(30.0, $s['LOW-50']['shortfall'], 'LOW-50 shortfall');
t_same(90.0, $s['LOW-50']['suggested_qty'], 'LOW-50 rounded up to one standard batch');
// NEG-50: projected -75, target 0 → shortfall 75, no batch size → 75 raw.
t_same(75.0, $s['NEG-50']['suggested_qty'], 'NEG-50 suggests raw shortfall');

// Open-ticket passthrough: the plan shows which batch is already cut.
$s2 = SuggestionEngine::compute([
    'BULK' => ['has_recipe' => 1, 'is_discontinued' => 0, 'minimum_stock' => 150.0,
               'on_hand' => 2, 'on_tickets' => 0, 'open_demand' => 0, 'open_ticket' => 194236],
], [], 20.0);
t_check(($s2['BULK']['open_ticket'] ?? null) === 194236, 'open_ticket carried onto the suggestion row');

t_section('CoverageEngine: service charges are never short');

// Wire fees / shipping charges are not inventoried (Matt, 2026-11-04).
$r = CoverageEngine::compute([100 => ['position' => 1]],
    [['ord_detail' => 9, 'ordr' => 100, 'item_code' => 'SHIPPING CHARGE', 'qty_remaining' => 1, 'unit' => 'ea', 'alias_code' => null]],
    ['SHIPPING CHARGE' => ['unit' => 'ea', 'is_discontinued' => false, 'description' => '', 'is_service' => true]],
    [], []);
t_check($r[9]['status'] === 'ready', 'service line is ready with zero stock');
t_check($r[9]['shortfall'] == 0.0, 'service line never short');
t_check(str_contains($r[9]['reason'], 'Service charge'), 'reason says it is a service charge');

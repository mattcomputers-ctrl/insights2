<?php

declare(strict_types=1);

/**
 * ScheduleEngine v2 — pure-function tests (Matt, 2026-11-04: the schedule
 * describes reality — open tickets first, then order shortfalls, then the
 * production plan). Run via tests/run.php.
 */

require_once PII_TEST_ROOT . '/src/Modules/Scheduling/ScheduleEngine.php';

use PII\Modules\Scheduling\ScheduleEngine;

$LADDER = ['extender', 'yellow', 'red', 'blue', 'black'];
$mill = fn(int $id, array $o = []) => array_merge([
    'id' => $id, 'name' => 'Mill ' . $id, 'lbs_per_hour' => 100.0, 'lbs_per_hour_dry' => 0.0,
    'washup_like_minutes' => 0.0, 'washup_next_minutes' => 30.0, 'washup_deep_minutes' => 60.0,
    'hours_per_day' => 8.0, 'max_batch_lbs' => 0.0, 'dry_grind_capable' => 0,
], $o);
$cfg = fn(string $color, float $b1, ?float $b2 = null) =>
    ['color' => $color, 'batch_size_1' => $b1, 'batch_size_2' => $b2, 'not_milled' => false];
$week = '2026-11-09';
$days = [1, 1, 1, 1, 1, 0, 0];

$allRuns = function (array $sched): array {
    $runs = [];
    foreach ($sched['mills'] as $m) {
        foreach ($m['days'] as $d) {
            foreach ($d['runs'] as $r) {
                $runs[] = $r;
            }
        }
    }
    return $runs;
};
$firstRuns = function (array $sched): array {
    $runs = [];
    foreach ($sched['mills'] as $m) {
        foreach ($m['days'] as $d) {
            foreach ($d['runs'] as $r) {
                if (empty($r['carryover'])) {
                    $runs[] = $r;
                }
            }
        }
    }
    return $runs;
};

t_section('ScheduleEngine v2: tickets are committed work');

$e = new ScheduleEngine($LADDER);

// An open ticket schedules even with NO config (unknown color, warning).
$s = $e->build(
    [['bulk' => 'E1', 'lbs' => 400, 'tier' => 0, 'source' => 'ticket', 'ticket' => 1001,
      'due' => '2026-11-12', 'materials_short' => false, 'packs' => []]],
    [], [$mill(1)], [], $week, $days
);
$runs = $firstRuns($s);
t_check(count($runs) === 1 && $runs[0]['bulk'] === 'E1', 'unconfigured ticket still schedules');
t_check($runs[0]['ticket'] === 1001 && $runs[0]['source'] === 'ticket', 'run carries its ticket number');
t_check((bool) array_filter($s['warnings'], fn($w) => str_contains($w, 'no scheduling config')),
    'missing config warns');

// Exact ticket quantity — never re-chunked into standard batches.
$s = $e->build(
    [['bulk' => 'E1', 'lbs' => 777, 'tier' => 0, 'source' => 'ticket', 'ticket' => 1001,
      'due' => null, 'materials_short' => false, 'packs' => []]],
    ['E1' => $cfg('red', 500)], [$mill(1)], [], $week, $days
);
$runs = $firstRuns($s);
t_check(count($runs) === 1 && abs($runs[0]['lbs'] - 777.0) < 1e-6, 'ticket quantity stays exact (777, not 2×500)');

// A ticket with no CMS quantity assumes one standard batch.
$s = $e->build(
    [['bulk' => 'E1', 'lbs' => 0, 'tier' => 0, 'source' => 'ticket', 'ticket' => 1002,
      'due' => null, 'materials_short' => false, 'packs' => []]],
    ['E1' => $cfg('red', 500)], [$mill(1)], [], $week, $days
);
$runs = $firstRuns($s);
t_check(count($runs) === 1 && abs($runs[0]['lbs'] - 500.0) < 1e-6, 'unquantified ticket assumes one standard batch');

t_section('ScheduleEngine v2: tier order and materials sequencing');

// Ticket beats order beats below-min beats near-min — whatever the ladder says.
$s = $e->build(
    [
        ['bulk' => 'N1', 'lbs' => 100, 'tier' => 3, 'source' => 'near', 'ticket' => null, 'due' => null, 'materials_short' => false, 'packs' => []],
        ['bulk' => 'M1', 'lbs' => 100, 'tier' => 2, 'source' => 'min', 'ticket' => null, 'due' => null, 'materials_short' => false, 'packs' => []],
        ['bulk' => 'O1', 'lbs' => 100, 'tier' => 1, 'source' => 'order', 'ticket' => null, 'due' => null, 'materials_short' => false, 'packs' => []],
        ['bulk' => 'T1', 'lbs' => 100, 'tier' => 0, 'source' => 'ticket', 'ticket' => 1, 'due' => null, 'materials_short' => false, 'packs' => []],
    ],
    ['N1' => $cfg('extender', 100), 'M1' => $cfg('extender', 100), 'O1' => $cfg('extender', 100), 'T1' => $cfg('black', 100)],
    [$mill(1)], [], $week, $days
);
$order = array_column($firstRuns($s), 'bulk');
t_check($order === ['T1', 'O1', 'M1', 'N1'],
    'tiers sequence ticket→order→min→near (got ' . implode(',', $order) . ')');

// Within tier 0: runnable tickets before materials-short ones, then due date.
$s = $e->build(
    [
        ['bulk' => 'A', 'lbs' => 100, 'tier' => 0, 'source' => 'ticket', 'ticket' => 1, 'due' => '2026-11-10', 'materials_short' => true, 'packs' => []],
        ['bulk' => 'B', 'lbs' => 100, 'tier' => 0, 'source' => 'ticket', 'ticket' => 2, 'due' => '2026-11-13', 'materials_short' => false, 'packs' => []],
        ['bulk' => 'C', 'lbs' => 100, 'tier' => 0, 'source' => 'ticket', 'ticket' => 3, 'due' => '2026-11-11', 'materials_short' => false, 'packs' => []],
    ],
    ['A' => $cfg('red', 100), 'B' => $cfg('red', 100), 'C' => $cfg('red', 100)],
    [$mill(1)], [], $week, $days
);
$order = array_column($firstRuns($s), 'ticket');
t_check($order === [3, 2, 1],
    'runnable tickets by due date first, materials-short last (got ' . implode(',', $order) . ')');

t_section('ScheduleEngine v2: suggested work keeps the old rules');

// Order shortfall chunks exactly at the largest size (MTO — no stock build).
$s = $e->build(
    [['bulk' => 'O1', 'lbs' => 750, 'tier' => 1, 'source' => 'order', 'ticket' => null, 'due' => null, 'materials_short' => false, 'packs' => []]],
    ['O1' => $cfg('red', 500)], [$mill(1)], [], $week, $days
);
$runs = $firstRuns($s);
t_check(count($runs) === 2 && abs($runs[0]['lbs'] - 500.0) < 1e-6 && abs($runs[1]['lbs'] - 250.0) < 1e-6,
    'order shortfall chunks 750 → 500 + 250 exact');
t_check($runs[0]['mto'] === true, 'order-driven batches are MTO');

// Missing config on suggested work warns and skips (unlike tickets).
$s = $e->build(
    [['bulk' => 'M9', 'lbs' => 100, 'tier' => 2, 'source' => 'min', 'ticket' => null, 'due' => null, 'materials_short' => false, 'packs' => []]],
    [], [$mill(1)], [], $week, $days
);
t_check($firstRuns($s) === [] && (bool) array_filter($s['warnings'], fn($w) => str_contains($w, 'M9')),
    'suggested work without config warns and skips');

// Milling gate still applies to suggested work (small blends drop)…
$s = $e->build(
    [['bulk' => 'BL1', 'lbs' => 40, 'tier' => 2, 'source' => 'min', 'ticket' => null, 'due' => null, 'materials_short' => false, 'packs' => []]],
    ['BL1' => $cfg('red', 40)], [$mill(1)], [], $week, $days,
    [], [], [], ['BL1' => false]
);
t_check($firstRuns($s) === [], 'small non-milled blend stays off the schedule');

// …but never to tickets (it exists, it runs).
$s = $e->build(
    [['bulk' => 'BL1', 'lbs' => 40, 'tier' => 0, 'source' => 'ticket', 'ticket' => 7, 'due' => null, 'materials_short' => false, 'packs' => []]],
    ['BL1' => $cfg('red', 40)], [$mill(1)], [], $week, $days,
    [], [], [], ['BL1' => false]
);
t_check(count($firstRuns($s)) === 1, 'a cut ticket schedules even for a non-milled blend');

t_section('ScheduleEngine: batch size tiers + defaults + guessed colors');

// Up to five configured sizes — chunking still fills at the largest.
$cfg5 = ['color' => 'red', 'batch_size_1' => 1000.0, 'batch_size_2' => 500.0,
         'sizes' => [1000.0, 500.0, 250.0, 100.0, 50.0], 'not_milled' => false];
$s = $e->build(
    [['bulk' => 'F1', 'lbs' => 1250, 'tier' => 1, 'source' => 'order', 'ticket' => null, 'due' => null, 'materials_short' => false, 'packs' => []]],
    ['F1' => $cfg5], [$mill(1, ['hours_per_day' => 24.0])], [], $week, $days
);
$runs = $firstRuns($s);
t_check(count($runs) === 2 && abs($runs[0]['lbs'] - 1000.0) < 1e-6 && abs($runs[1]['lbs'] - 250.0) < 1e-6,
    'five-tier config chunks 1250 → 1000 + 250');

// Unconfigured suggested work SCHEDULES when default sizes exist,
// with the alias-guessed color.
$s = $e->build(
    [['bulk' => 'U1', 'lbs' => 750, 'tier' => 2, 'source' => 'min', 'ticket' => null, 'due' => null, 'materials_short' => false, 'packs' => []]],
    [], [$mill(1)], [], $week, $days,
    [], [], [], [], [500.0, 250.0], ['U1' => 'blue']
);
$runs = $firstRuns($s);
t_check(count($runs) === 2 && abs($runs[0]['lbs'] - 500.0) < 1e-6 && abs($runs[1]['lbs'] - 250.0) < 1e-6,
    'unconfigured work schedules with default sizes (750 → 500 + 250)');
t_check($runs !== [] && $runs[0]['color'] === 'blue', 'guessed color applied to defaulted work');
t_check((bool) array_filter($s['warnings'], fn($w) => str_contains($w, 'default batch sizes')),
    'defaulted work is called out in a warning');

// …and still skips with a warning when there are no defaults either.
$s = $e->build(
    [['bulk' => 'U2', 'lbs' => 100, 'tier' => 2, 'source' => 'min', 'ticket' => null, 'due' => null, 'materials_short' => false, 'packs' => []]],
    [], [$mill(1)], [], $week, $days
);
t_check($firstRuns($s) === [] && (bool) array_filter($s['warnings'], fn($w) => str_contains($w, 'U2')),
    'no config AND no defaults still warns and skips');

// An unconfigured ticket picks up the guessed color too (better washups).
$s = $e->build(
    [['bulk' => 'T9', 'lbs' => 300, 'tier' => 0, 'source' => 'ticket', 'ticket' => 2001, 'due' => null, 'materials_short' => false, 'packs' => []]],
    [], [$mill(1)], [], $week, $days,
    [], [], [], [], [], ['T9' => 'yellow']
);
$runs = $firstRuns($s);
t_check($runs !== [] && $runs[0]['color'] === 'yellow', 'unconfigured ticket uses the guessed color');

// An unquantified ticket with no config falls back to the largest default.
$s = $e->build(
    [['bulk' => 'T8', 'lbs' => 0, 'tier' => 0, 'source' => 'ticket', 'ticket' => 2002, 'due' => null, 'materials_short' => false, 'packs' => []]],
    [], [$mill(1)], [], $week, $days,
    [], [], [], [], [400.0, 200.0], []
);
$runs = $firstRuns($s);
t_check(count($runs) === 1 && abs($runs[0]['lbs'] - 400.0) < 1e-6,
    'unquantified unconfigured ticket assumes one default batch');

t_section('ScheduleEngine v2: mill mechanics unchanged');

// Dry grind only lands on capable mills, at the dry rate.
$s = $e->build(
    [['bulk' => 'D1', 'lbs' => 100, 'tier' => 0, 'source' => 'ticket', 'ticket' => 5, 'due' => null, 'materials_short' => false, 'packs' => []]],
    ['D1' => $cfg('black', 100)],
    [$mill(1), $mill(2, ['dry_grind_capable' => 1, 'lbs_per_hour_dry' => 50.0])],
    [], $week, $days, ['D1' => 3], ['D1' => true]
);
$placedMill = null;
foreach ($s['mills'] as $m) {
    foreach ($m['days'] as $d) {
        if ($d['runs'] !== []) $placedMill = $m['mill_id'];
    }
}
t_check($placedMill === 2, 'dry grind placed on the capable mill only');

// Over-capacity near-min work lands in Unscheduled with its source.
$s = $e->build(
    [
        ['bulk' => 'T1', 'lbs' => 700, 'tier' => 0, 'source' => 'ticket', 'ticket' => 9, 'due' => null, 'materials_short' => false, 'packs' => []],
        ['bulk' => 'N1', 'lbs' => 700, 'tier' => 3, 'source' => 'near', 'ticket' => null, 'due' => null, 'materials_short' => false, 'packs' => []],
    ],
    ['T1' => $cfg('red', 700), 'N1' => $cfg('red', 700)],
    [$mill(1, ['hours_per_day' => 8.0])],   // 800 lbs/day capacity, 1 day would fit one batch
    [], $week, [1, 0, 0, 0, 0, 0, 0]
);
t_check(count($s['unscheduled']) === 1 && $s['unscheduled'][0]['bulk'] === 'N1'
    && $s['unscheduled'][0]['source'] === 'near',
    'ticket placed, near-min batch unscheduled with its source');

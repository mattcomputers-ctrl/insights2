<?php

declare(strict_types=1);

/**
 * Blackout window math (SyncSettings::windowCovers) — pure-function tests,
 * including the cross-midnight case from the spec (23:30–02:00).
 * Run via tests/run.php.
 */

require_once PII_TEST_ROOT . '/src/Services/Sync/SyncSettings.php';

use PII\Services\Sync\SyncSettings;

t_section('Blackout window coverage');

$at = fn(string $s) => new DateTimeImmutable($s);

// Simple same-day window: Mon–Fri 01:00–03:00. 2026-10-26 is a Monday.
$w = ['days' => [1, 2, 3, 4, 5], 'start' => '01:00', 'end' => '03:00'];
t_check(SyncSettings::windowCovers($w, $at('2026-10-26 02:00')) !== null, 'inside same-day window');
t_check(SyncSettings::windowCovers($w, $at('2026-10-26 00:59')) === null,  'just before window');
t_check(SyncSettings::windowCovers($w, $at('2026-10-26 03:00')) === null,  'at end (exclusive)');
t_check(SyncSettings::windowCovers($w, $at('2026-10-25 02:00')) === null,  'Sunday not in days');
$end = SyncSettings::windowCovers($w, $at('2026-10-26 01:00'));
t_check($end !== null && $end->format('Y-m-d H:i') === '2026-10-26 03:00', 'end time right');

// Cross-midnight window: Fri 23:30–02:00 (ends Saturday morning).
$w = ['days' => [5], 'start' => '23:30', 'end' => '02:00'];
t_check(SyncSettings::windowCovers($w, $at('2026-10-30 23:45')) !== null, 'Fri 23:45 inside');
$end = SyncSettings::windowCovers($w, $at('2026-10-31 01:30'));           // Saturday 01:30
t_check($end !== null, 'Sat 01:30 still inside (window started Friday)');
t_check($end !== null && $end->format('Y-m-d H:i') === '2026-10-31 02:00', 'cross-midnight end right');
t_check(SyncSettings::windowCovers($w, $at('2026-10-31 02:30')) === null, 'Sat 02:30 outside');
t_check(SyncSettings::windowCovers($w, $at('2026-10-31 23:45')) === null, 'Sat 23:45 outside (window is Friday-start only)');
t_check(SyncSettings::windowCovers($w, $at('2026-10-30 02:00')) === null, 'Fri early morning outside (previous day was Thu)');

// Every-day cross-midnight window behaves on consecutive days.
$w = ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '23:30', 'end' => '02:00'];
t_check(SyncSettings::windowCovers($w, $at('2026-10-27 00:30')) !== null, 'daily window: after midnight covered');
t_check(SyncSettings::windowCovers($w, $at('2026-10-27 23:45')) !== null, 'daily window: before midnight covered');
t_check(SyncSettings::windowCovers($w, $at('2026-10-27 12:00')) === null, 'daily window: midday outside');

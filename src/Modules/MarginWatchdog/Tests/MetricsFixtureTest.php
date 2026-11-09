<?php

declare(strict_types=1);

/**
 * Margin Watchdog regression test (run via tests/run.php).
 *
 * Feeds the frozen raw rows from tests/fixtures/margin-watchdog/ through the
 * real MetricsCalculator::compute() and asserts full equality with the
 * expected metrics captured at commit 0edeea0 (delta 1e-9). This is the
 * contract from SPEC-order-readiness §1: the module-structure move (and any
 * later refactor) must not change a single Margin Watchdog number.
 *
 * See tests/fixtures/margin-watchdog/README.md for what each fixture holds.
 */

require_once PII_TEST_ROOT . '/src/Modules/MarginWatchdog/MetricsCalculator.php';

use PII\Modules\MarginWatchdog\MetricsCalculator;

$fixtureDir = PII_TEST_ROOT . '/tests/fixtures/margin-watchdog';
$fixtures   = glob($fixtureDir . '/*.json') ?: [];

t_section('Margin Watchdog metrics vs frozen fixtures');
t_check($fixtures !== [], "no fixtures found in {$fixtureDir}");

$rowsChecked = 0;

foreach ($fixtures as $file) {
    $name = basename($file);
    $data = json_decode((string) file_get_contents($file), true);
    t_check(is_array($data), "{$name}: could not parse JSON");
    if (!is_array($data)) {
        continue;
    }

    if (isset($data['raw'], $data['expected_metrics'])) {
        // Summary fixture: one raw row, one expected block.
        t_same($data['expected_metrics'], MetricsCalculator::compute($data['raw']), $name);
        $rowsChecked++;
    } elseif (isset($data['rows'])) {
        // Bill-To / item fixture: many rows.
        foreach ($data['rows'] as $i => $row) {
            if (!isset($row['raw'], $row['expected_metrics'])) {
                t_check(false, "{$name}[{$i}]: row missing raw/expected_metrics");
                continue;
            }
            $label = $row['raw']['bill_to'] ?? $row['raw']['item_name'] ?? (string) $i;
            t_same($row['expected_metrics'], MetricsCalculator::compute($row['raw']), "{$name}[{$label}]");
            $rowsChecked++;
        }
    } else {
        t_check(false, "{$name}: unrecognised fixture shape");
    }
}

// Guard against the test silently passing because fixtures went missing:
// 2 summaries + 180 bill-tos + 3 item drill-downs ⇒ well over 150 rows.
t_check($rowsChecked >= 150, "only {$rowsChecked} fixture rows checked — fixtures missing?");
echo "  {$rowsChecked} fixture rows verified\n";

/* ── Null-semantics spot checks (behaviour the fixtures imply but that a
      refactor could quietly break for inputs the capture didn't cover) ── */

t_section('Margin Watchdog metrics edge cases');

$zero = MetricsCalculator::compute([]);
t_check($zero['in_both_periods'] === false, 'all-zero row: in_both_periods should be false');
t_check($zero['revenue']['diff_pct'] === null, 'all-zero row: pct change on zero baseline must be null');
t_check($zero['avg_sale']['baseline'] === null, 'all-zero row: avg over zero qty must be null');
t_check($zero['expected_packed_cost'] === null, 'no replacement_cost: expected_packed_cost must be null');

$oneSide = MetricsCalculator::compute([
    'comparison_revenue' => 100.0, 'comparison_cost' => 40.0, 'comparison_qty' => 10.0,
]);
t_check($oneSide['in_both_periods'] === false, 'comparison-only row: in_both_periods false');
t_check($oneSide['revenue']['diff_pct'] === null, 'comparison-only row: revenue diff_pct null');
t_check($oneSide['avg_cost_pct']['comparison'] !== null, 'comparison-only row: comparison avg cost % computed');

$withRep = MetricsCalculator::compute([
    'baseline_revenue' => 200.0, 'baseline_cost' => 80.0, 'baseline_qty' => 20.0,
    'comparison_revenue' => 100.0, 'comparison_cost' => 40.0, 'comparison_qty' => 10.0,
    'replacement_cost' => 5.0,
]);
t_same(50.0, $withRep['expected_cost_pct_of_comparison_sale']['value'], 'rep-cost row: 5 / (100/10) × 100');
t_same(10.0, $withRep['expected_cost_pct_of_comparison_sale']['horizon_delta_pp'], 'rep-cost row: 50 − 40 pp');

<?php

declare(strict_types=1);

/**
 * tests/run.php — zero-dependency test runner.
 *
 * Discovers and includes every src/Modules/{Name}/Tests/*Test.php (plus any
 * tests/*Test.php), giving each the tiny assertion helpers below. Exits
 * non-zero on any failure, so update.sh / CI can gate on it.
 *
 * No PHPUnit on purpose: production installs run composer --no-dev, and the
 * dev box runs this inside a bare php:cli Docker container. If/when a real
 * PHPUnit suite lands (spec step 9), these files port over trivially.
 *
 * Usage:  php tests/run.php
 * Docker: docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/run.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PII_TEST_ROOT', dirname(__DIR__));

$GLOBALS['__t'] = ['checks' => 0, 'failures' => 0, 'section' => ''];

function t_section(string $name): void
{
    $GLOBALS['__t']['section'] = $name;
    echo "\n== {$name}\n";
}

function t_check(bool $ok, string $msg): void
{
    $GLOBALS['__t']['checks']++;
    if (!$ok) {
        $GLOBALS['__t']['failures']++;
        echo "  FAIL: {$msg}\n";
    }
}

/**
 * Deep-compare $actual against $expected. Numbers compare with $delta
 * tolerance; bools/nulls/strings exact; arrays recurse and must have the
 * same keys. Reports each mismatch with its path.
 */
function t_same(mixed $expected, mixed $actual, string $path, float $delta = 1e-9): void
{
    if (is_array($expected) || is_array($actual)) {
        if (!is_array($expected) || !is_array($actual)) {
            t_check(false, "{$path}: type mismatch (" . gettype($expected) . ' vs ' . gettype($actual) . ')');
            return;
        }
        $keys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
        foreach ($keys as $k) {
            if (!array_key_exists($k, $expected)) { t_check(false, "{$path}.{$k}: unexpected key in actual"); continue; }
            if (!array_key_exists($k, $actual))   { t_check(false, "{$path}.{$k}: missing from actual");     continue; }
            t_same($expected[$k], $actual[$k], "{$path}.{$k}", $delta);
        }
        return;
    }

    if ($expected === null || $actual === null || is_bool($expected) || is_bool($actual)) {
        t_check($expected === $actual, "{$path}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
        return;
    }

    if (is_int($expected) || is_float($expected)) {
        if (!is_int($actual) && !is_float($actual)) {
            t_check(false, "{$path}: expected number, got " . gettype($actual));
            return;
        }
        t_check(abs((float) $expected - (float) $actual) <= $delta,
            "{$path}: expected {$expected}, got {$actual}");
        return;
    }

    t_check($expected === $actual, "{$path}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/* ── Discover and run ────────────────────────────────────────────── */

$files = array_merge(
    glob(PII_TEST_ROOT . '/src/Modules/*/Tests/*Test.php') ?: [],
    glob(PII_TEST_ROOT . '/tests/*Test.php') ?: []
);

if ($files === []) {
    fwrite(STDERR, "No test files found.\n");
    exit(1);
}

foreach ($files as $file) {
    echo 'Running ' . substr($file, strlen(PII_TEST_ROOT) + 1) . "\n";
    require $file;
}

$t = $GLOBALS['__t'];
echo "\n{$t['checks']} checks, {$t['failures']} failures\n";
exit($t['failures'] === 0 ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * ONE-TIME backfill (Matt, 2026-11-05): for every item already in
 * sched_item_config, pull the batch sizes it has actually been made at
 * (most common planned quantities on its past MFBA tickets) and fill
 * batch_size_1..5 — largest first, so batch_size_1 stays the primary.
 *
 * Items with no batch history are left exactly as they are, as are
 * not-milled items (their sizes are placeholders and never schedule).
 *
 * OUTLIER FILTER (Matt's dry run, 2026-11-05): a one-off oversize batch
 * must not become the primary size (the engine chunks at the largest —
 * e.g. 3AC3200 was made at 750 lbs 263 times but a single 1600 lb batch
 * would have taken the top slot). A size therefore only counts when it
 * was made at least TWICE and at least 5% as often as the item's most
 * common size. Items whose entire history is one-offs are left alone.
 * Pass --raw to skip the filter and take the top 5 as-is.
 *
 * --create-missing (Matt, 2026-11-05, after the production backfill):
 * also CREATE configs for unconfigured bulks that appear in the current
 * workload (open tickets, order shortfalls, the production plan) — but
 * only when the item has filtered batch history AND an alias-prefix
 * color guess. Anything missing either stays unconfigured and keeps its
 * generate-time warning, so nothing is invented.
 *
 * Usage (on the server, from the app root):
 *   php bin/backfill-batch-sizes.php --dry-run                    # show what would change
 *   php bin/backfill-batch-sizes.php                              # apply
 *   php bin/backfill-batch-sizes.php --create-missing --dry-run   # + preview new configs
 *   php bin/backfill-batch-sizes.php --create-missing             # + create them
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use PII\Core\App;
use PII\Core\Database;
use PII\Modules\Scheduling\SchedulingDataService;

App::bootConsole();

$dryRun = in_array('--dry-run', $argv, true);
$raw    = in_array('--raw', $argv, true);
$createMissing = in_array('--create-missing', $argv, true);
$db  = Database::getInstance();
$svc = new SchedulingDataService();

/** Outlier filter: made at least twice AND at least 5% as often as the
 *  item's most common size. --raw keeps everything. */
$filterSizes = function (array $history) use ($raw): array {
    if ($raw || $history === []) {
        return $history;
    }
    $top = max(array_column($history, 'n'));
    return array_values(array_filter(
        $history,
        fn($h) => $h['n'] >= 2 && $h['n'] >= 0.05 * $top
    ));
};
$evidenceOf = fn(array $h) => implode(' · ', array_map(fn($x) => round($x['lbs']) . "×{$x['n']}", $h));
$fmt = fn(array $a) => $a === [] ? '(none)' : implode('/', array_map(fn($v) => (string) round((float) $v), $a));

$configs = $db->fetchAll("SELECT * FROM sched_item_config ORDER BY bulk_item_code");
echo count($configs) . " configured item(s)"
    . ($dryRun ? ' — DRY RUN, nothing will be written' : '')
    . ($raw ? ' — RAW (outlier filter off)' : '') . "\n\n";

$updated = 0;
$noHistory = 0;
$tooThin = 0;
$skippedNotMilled = 0;

foreach ($configs as $c) {
    $code = (string) $c['bulk_item_code'];

    if (!empty($c['not_milled'])) {
        $skippedNotMilled++;
        echo "  {$code}: not milled — skipped\n";
        continue;
    }

    $history = $svc->historicalBatchSizes($code);
    if ($history === []) {
        $noHistory++;
        echo "  {$code}: no batch history — left as is\n";
        continue;
    }

    $kept = $filterSizes($history);
    if ($kept === []) {
        $tooThin++;
        echo "  {$code}: history too thin (all one-offs) — left as is   [{$evidenceOf($history)}]\n";
        continue;
    }

    $sizes = array_column($kept, 'lbs');
    rsort($sizes);

    $old = [];
    for ($i = 1; $i <= 5; $i++) {
        $v = $c['batch_size_' . $i] ?? null;
        if ($v !== null && (float) $v > 0) {
            $old[] = round((float) $v);
        }
    }

    $evidence = $evidenceOf($kept);
    $dropped = array_udiff($history, $kept, fn($a, $b) => $a['lbs'] <=> $b['lbs']);
    if ($dropped !== []) {
        $evidence .= ' | dropped as outliers: ' . $evidenceOf($dropped);
    }
    echo "  {$code}: {$fmt($old)} -> {$fmt($sizes)}   [{$evidence}]\n";

    if (!$dryRun) {
        $db->update('sched_item_config', [
            'batch_size_1' => $sizes[0],
            'batch_size_2' => $sizes[1] ?? null,
            'batch_size_3' => $sizes[2] ?? null,
            'batch_size_4' => $sizes[3] ?? null,
            'batch_size_5' => $sizes[4] ?? null,
        ], 'bulk_item_code = ?', [$code]);
    }
    $updated++;
}

echo "\n" . ($dryRun ? 'Would update' : 'Updated') . " {$updated} item(s); "
    . "{$noHistory} with no history and {$tooThin} with only one-off batches left untouched; "
    . "{$skippedNotMilled} not-milled skipped.\n";

if (!$createMissing) {
    exit(0);
}

/* ── create configs for unconfigured bulks in the current workload ── */

echo "\nUnconfigured bulks in the current workload (open tickets + order\n"
    . "shortfalls + production plan), from the latest sync:\n\n";

try {
    $packToBulk = $svc->packToBulkMap()['map'];
} catch (\Throwable $e) {
    $packToBulk = [];
    echo "  (CMS pack→bulk map unavailable — order-shortfall grouping may be incomplete)\n";
}

$configured = array_flip(array_column($configs, 'bulk_item_code'));
$missing = [];
foreach ($svc->workload($packToBulk) as $d) {
    $bulk = (string) $d['bulk'];
    if (!isset($configured[$bulk]) && !in_array($bulk, $missing, true)) {
        $missing[] = $bulk;
    }
}
sort($missing);

if ($missing === []) {
    echo "  none — every bulk in the workload is configured.\n";
    exit(0);
}

$guesses = $svc->guessColors($missing);   // alias prefix first, description words fill the gaps

$created = 0;
$skippedNoColor = 0;
$skippedNoSizes = 0;
foreach ($missing as $code) {
    $history = $svc->historicalBatchSizes($code);
    $kept = $filterSizes($history);
    $color = $guesses[$code] ?? null;

    if ($kept === []) {
        $skippedNoSizes++;
        echo "  {$code}: " . ($history === [] ? 'no batch history' : "history too thin [{$evidenceOf($history)}]")
            . " — not created (will keep using the default batch sizes)\n";
        continue;
    }
    if ($color === null) {
        $skippedNoColor++;
        echo "  {$code}: no color guess from aliases or description — not created, configure manually   [{$evidenceOf($kept)}]\n";
        continue;
    }

    $sizes = array_column($kept, 'lbs');
    rsort($sizes);
    echo "  {$code}: CREATE color={$color} sizes={$fmt($sizes)}   [{$evidenceOf($kept)}]\n";

    if (!$dryRun) {
        $db->insert('sched_item_config', [
            'bulk_item_code' => $code,
            'color'          => $color,
            'batch_size_1'   => $sizes[0],
            'batch_size_2'   => $sizes[1] ?? null,
            'batch_size_3'   => $sizes[2] ?? null,
            'batch_size_4'   => $sizes[3] ?? null,
            'batch_size_5'   => $sizes[4] ?? null,
        ]);
    }
    $created++;
}

echo "\n" . ($dryRun ? 'Would create' : 'Created') . " {$created} config(s); "
    . "{$skippedNoSizes} skipped for thin/no history, {$skippedNoColor} for no color guess "
    . "(both keep their generate-time warnings).\n"
    . "Review the created colors in Scheduling → Settings — they are alias-prefix/description guesses.\n";

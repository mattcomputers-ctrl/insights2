<?php
/**
 * cron/snapshot-inventory.php
 *
 * Captures one or more inventory snapshots from CMS into the local
 * inventory_snapshots cache. The CMS GetInventoryAtDate TVF takes
 * ~45 seconds per call so we never want the dashboard to hit it
 * directly — this script runs nightly and populates the cache.
 *
 * Usage:
 *   # Default: snapshot yesterday (skip if already captured)
 *   php cron/snapshot-inventory.php
 *
 *   # Backfill missing snapshots for the last N days
 *   php cron/snapshot-inventory.php --backfill-days=30
 *
 *   # Force a specific date (re-captures even if already present)
 *   php cron/snapshot-inventory.php --date=2026-05-06
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use PII\Core\App;
use PII\Core\CMSDatabase;
use PII\Core\Database;

// Bootstrap (sessions are skipped in CLI by Session::start()).
new App();

$opts = getopt('', ['backfill-days::', 'date::', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php cron/snapshot-inventory.php [--backfill-days=N] [--date=YYYY-MM-DD]\n";
    exit(0);
}

if (!CMSDatabase::isConfigured()) {
    fwrite(STDERR, "CMS database not configured. Edit config/config.php.\n");
    exit(1);
}

$mysql = Database::getInstance();
$cms   = CMSDatabase::getInstance();

// Status file used by /admin/settings to render a progress bar.
// Atomic write (tmp + rename) so polled reads never see a partial file.
$STATUS_FILE = App::basePath() . '/storage/cache/snapshot-status.json';
@mkdir(dirname($STATUS_FILE), 0775, true);

$status_write = static function (array $data) use ($STATUS_FILE): void {
    $data['heartbeat'] = date('Y-m-d H:i:s');
    $tmp = $STATUS_FILE . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) !== false) {
        @rename($tmp, $STATUS_FILE);
    }
};
$status_state = [
    'pid'             => getmypid(),
    'started_at'      => date('Y-m-d H:i:s'),
    'finished_at'     => null,
    'total_dates'     => 0,
    'completed_dates' => 0,
    'current_date'    => null,
    'current_phase'   => 'starting',
    'error'           => null,
    'trigger'         => isset($opts['backfill-days']) ? 'backfill' : (isset($opts['date']) ? 'manual_date' : 'auto'),
];
$status_write($status_state);

// Always finalize status on exit so the dashboard's progress poller
// sees a clean "finished" state rather than waiting 90s for the
// heartbeat to go stale. Covers early exits AND fatal errors.
register_shutdown_function(function () use (&$status_state, $status_write): void {
    if (empty($status_state['finished_at'])) {
        $status_state['finished_at']   = date('Y-m-d H:i:s');
        $status_state['current_phase'] = $status_state['error'] ? 'error' : 'done';
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $status_state['error'] = $err['message'];
        }
        $status_write($status_state);
    }
});

// Build the list of dates to (re)capture.
$dates = [];
if (isset($opts['date'])) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $opts['date'])) {
        fwrite(STDERR, "Bad --date format. Use YYYY-MM-DD.\n");
        exit(1);
    }
    $dates[] = $opts['date'];
} elseif (isset($opts['backfill-days'])) {
    $n = max(1, (int) $opts['backfill-days']);
    // Skip dates that are already captured to avoid redoing the slow TVF call.
    $existing = [];
    foreach ($mysql->fetchAll("SELECT DISTINCT snapshot_date FROM inventory_snapshots") as $r) {
        $existing[$r['snapshot_date']] = true;
    }
    for ($i = 1; $i <= $n; $i++) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        if (!isset($existing[$d])) {
            $dates[] = $d;
        }
    }
    if (empty($dates)) {
        echo "No missing dates in the last {$n} days — nothing to do.\n";
        exit(0);
    }
} else {
    // Default: yesterday only, skip if already captured
    $d = date('Y-m-d', strtotime('-1 day'));
    $exists = $mysql->fetch("SELECT 1 FROM inventory_snapshots WHERE snapshot_date = ? LIMIT 1", [$d]);
    if ($exists) {
        echo "Snapshot for {$d} already exists — nothing to do.\n";
        exit(0);
    }
    $dates[] = $d;
}

sort($dates);

echo sprintf("Capturing %d snapshot(s): %s\n", count($dates), implode(', ', $dates));

$status_state['total_dates']   = count($dates);
$status_state['current_phase'] = 'preparing';
$status_write($status_state);

$sql = "
    SELECT GLGroup,
           COALESCE(SUM(Qty), 0)              AS total_qty,
           COALESCE(SUM(ActualValue), 0)      AS total_actual_value,
           COALESCE(SUM(ReplacementValue), 0) AS total_replacement_value,
           COUNT(*)                           AS item_count
      FROM CMS.dbo.GetInventoryAtDate(?, NULL)
     WHERE Qty > 0
     GROUP BY GLGroup
";

foreach ($dates as $date) {
    $start = microtime(true);
    echo "  [{$date}] querying CMS… ";

    $status_state['current_date']  = $date;
    $status_state['current_phase'] = 'querying CMS';
    $status_write($status_state);

    try {
        $rows = $cms->fetchAll($sql, [$date]);
    } catch (\Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        $status_state['error'] = "[{$date}] " . $e->getMessage();
        $status_write($status_state);
        continue;
    }

    $elapsed = round(microtime(true) - $start, 1);

    if (empty($rows)) {
        echo "no inventory data (skipped, {$elapsed}s)\n";
        $status_state['completed_dates']++;
        $status_write($status_state);
        continue;
    }

    $status_state['current_phase'] = 'writing local cache';
    $status_write($status_state);

    $mysql->beginTransaction();
    try {
        // Re-capture on this date wipes the prior rows for that date so
        // we never accumulate stale partial data.
        $mysql->delete('inventory_snapshots', 'snapshot_date = ?', [$date]);

        foreach ($rows as $r) {
            $mysql->insert('inventory_snapshots', [
                'snapshot_date'           => $date,
                'gl_group'                => (string) ($r['GLGroup'] ?? '(unspecified)'),
                'total_qty'               => (float)  ($r['total_qty']               ?? 0),
                'total_actual_value'      => (float)  ($r['total_actual_value']      ?? 0),
                'total_replacement_value' => (float)  ($r['total_replacement_value'] ?? 0),
                'item_count'              => (int)    ($r['item_count']              ?? 0),
            ]);
        }
        $mysql->commit();
        echo sprintf("captured %d GL groups (%ss)\n", count($rows), $elapsed);
    } catch (\Throwable $e) {
        $mysql->rollback();
        echo "DB FAILED: " . $e->getMessage() . "\n";
        $status_state['error'] = "[{$date}] DB: " . $e->getMessage();
    }

    $status_state['completed_dates']++;
    $status_write($status_state);
}

$status_state['current_date']  = null;
$status_state['current_phase'] = 'done';
$status_state['finished_at']   = date('Y-m-d H:i:s');
$status_write($status_state);

echo "Done.\n";

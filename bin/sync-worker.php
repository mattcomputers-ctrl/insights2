<?php

declare(strict_types=1);

/**
 * sync-worker.php — the background CMS sync worker (SPEC §3).
 *
 * Runs as a systemd service (insights-sync-worker.service) under www-data.
 * Loop: claim a queued manual run, or start an automatic run when due
 * (auto sync on, interval elapsed, no blackout, kill switch off), execute
 * it through SyncEngine, sleep 2 s. One run at a time is guaranteed by a
 * MySQL named lock, so a second worker instance is harmless.
 *
 * Flags:
 *   --once   process at most one run, then exit (tests / cron fallback)
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PII\Core\App;
use PII\Core\CMSDatabase;
use PII\Core\Database;
use PII\Services\Settings;
use PII\Services\Sync\SyncEngine;
use PII\Services\Sync\SyncSettings;

App::bootConsole();
// The worker applies blackout/kill policy itself (step boundaries, overrides).
CMSDatabase::setAdministrativeChecks(false);

$once = in_array('--once', $argv, true);
$log = function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
};
$log('Sync worker started' . ($once ? ' (--once)' : '') . '.');

$lastPrune = 0;

while (true) {
    try {
        Settings::refresh();
        $db  = Database::getInstance();
        $pdo = $db->getPdo();

        // One sync at a time, across however many workers exist.
        $got = $pdo->query("SELECT GET_LOCK('pii_sync_run', 0)")->fetchColumn();
        if ((string) $got === '1') {
            try {
                $run = $db->fetch("SELECT * FROM sync_runs WHERE status = 'queued' ORDER BY id ASC LIMIT 1");

                if (!$run && autoDue($db)) {
                    $id = $db->insert('sync_runs', ['trigger_type' => 'auto', 'status' => 'queued']);
                    $run = $db->fetch("SELECT * FROM sync_runs WHERE id = ?", [(int) $id]);
                }

                if ($run) {
                    $log("Run #{$run['id']} ({$run['trigger_type']}) starting…");
                    $status = SyncEngine::execute($run);
                    $log("Run #{$run['id']} finished: {$status}");
                }
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('pii_sync_run')");
            }
        }

        // Keep 90 days of history (spec §3); prune at most hourly.
        if (time() - $lastPrune > 3600) {
            $db->getPdo()->exec("DELETE FROM sync_runs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            $lastPrune = time();
        }
    } catch (\Throwable $e) {
        $log('Worker error: ' . $e->getMessage());
        // Lost DB connections etc.: back off, systemd keeps us alive anyway.
        sleep(10);
    }

    if ($once) {
        break;
    }
    sleep(2);
}

/** An automatic run is due when auto sync is on, outside blackout/kill, and the interval has elapsed since the last attempt. */
function autoDue(Database $db): bool
{
    if (!SyncSettings::autoEnabled() || SyncSettings::killSwitch() || SyncSettings::activeBlackout() !== null) {
        return false;
    }
    if (!CMSDatabase::isConfigured()) {
        return false;
    }
    // created_at is written by the DATABASE clock, so measure the elapsed
    // time with that same clock. Comparing it against PHP's time() breaks
    // whenever MySQL and PHP run in different timezones (e.g. UTC vs
    // America/New_York): the last run looks hours in the future and the
    // schedule never fires.
    $last = $db->fetch("SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS s FROM sync_runs");
    if ($last['s'] === null) {
        return true;
    }
    return (int) $last['s'] >= SyncSettings::intervalMinutes() * 60;
}

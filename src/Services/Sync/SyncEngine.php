<?php

declare(strict_types=1);

namespace PII\Services\Sync;

use PII\Core\App;
use PII\Core\CMSDatabase;
use PII\Core\Database;
use PII\Services\Settings;

/**
 * SyncEngine — executes one sync run (SPEC-order-readiness §3).
 *
 * Modules register jobs via Module::syncJobs():
 *   [['key' => 'order_readiness', 'label' => 'Order Readiness',
 *     'steps' => [['name' => 'Reading open orders', 'run' => fn(SyncContext $ctx) => ...], …]]]
 *
 * Each step fills staging tables ({table}__staging) through the context and
 * queues them for swapping. After EVERY step succeeds, all queued tables are
 * validated (minimum row counts) and swapped in with one atomic RENAME, so
 * pages never see half a sync; on any failure the old snapshot stays.
 *
 * Between steps the engine re-checks the blackout/kill switch (a run already
 * going when a window opens stops at the next step boundary, keeping the old
 * snapshot) and the overall timeout.
 */
class SyncEngine
{
    /** Execute the given queued sync_runs row. Returns final status. */
    public static function execute(array $run): string
    {
        $db   = Database::getInstance();
        $id   = (int) $run['id'];
        $override = (int) ($run['override_blackout'] ?? 0) === 1;

        $jobs = self::collectJobs();
        $steps = [['name' => 'Connecting to CMS', 'run' => function (SyncContext $ctx) {
            $ctx->cms()->fetch('SELECT 1 AS ok');
            return ['rows' => 1];
        }]];
        foreach ($jobs as $job) {
            foreach ($job['steps'] as $step) {
                $steps[] = $step;
            }
        }
        $total = count($steps);

        $db->update('sync_runs', [
            'status'     => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'step_total' => $total,
        ], 'id = ?', [$id]);

        $ctx      = new SyncContext();
        $started  = microtime(true);
        $timeout  = SyncSettings::timeoutSeconds();
        $stats    = ['steps' => []];

        try {
            foreach ($steps as $i => $step) {
                // Step-boundary policy checks.
                Settings::refresh();
                if (SyncSettings::killSwitch()) {
                    return self::finish($id, 'aborted', 'Stopped: CMS kill switch was turned on. Previous data kept.', $stats);
                }
                if (!$override && SyncSettings::activeBlackout() !== null) {
                    return self::finish($id, 'aborted', 'Stopped for the backup blackout window. Previous data kept.', $stats);
                }
                if (microtime(true) - $started > $timeout) {
                    return self::finish($id, 'failed', "Run exceeded the {$timeout}s time limit. Previous data kept.", $stats);
                }

                $db->update('sync_runs', [
                    'current_step' => $step['name'],
                    'step_num'     => $i + 1,
                ], 'id = ?', [$id]);

                $stepStart = microtime(true);
                $result = ($step['run'])($ctx) ?? [];
                $stats['steps'][] = [
                    'name'    => $step['name'],
                    'seconds' => round(microtime(true) - $stepStart, 2),
                    'rows'    => $result['rows'] ?? null,
                ];
            }

            // Validate + swap everything in at once.
            $ctx->swapAll();

            $stats['queries']     = $ctx->queryLog();
            $stats['swapped']     = $ctx->swappedTables();
            $stats['duration']    = round(microtime(true) - $started, 2);
            Settings::set('sync.last_success_at', date('Y-m-d H:i:s'));

            return self::finish($id, 'success', null, $stats);
        } catch (\Throwable $e) {
            $stats['queries'] = $ctx->queryLog();
            return self::finish($id, 'failed', self::plainError($e), $stats);
        } finally {
            $ctx->dropLeftoverStaging();
        }
    }

    /** All registered sync jobs, in module order. */
    public static function collectJobs(): array
    {
        $jobs = [];
        foreach (App::modules()->all() as $module) {
            foreach ($module->syncJobs() as $job) {
                $jobs[] = $job;
            }
        }
        return $jobs;
    }

    private static function finish(int $id, string $status, ?string $error, array $stats): string
    {
        Database::getInstance()->update('sync_runs', [
            'status'      => $status,
            'finished_at' => date('Y-m-d H:i:s'),
            'error'       => $error,
            'stats'       => json_encode($stats, JSON_UNESCAPED_SLASHES),
        ], 'id = ?', [(int) $id]);
        return $status;
    }

    /** Store failures in plain English, not driver gibberish, where we can. */
    private static function plainError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Unable to connect') || str_contains($msg, 'Login timeout')
            || str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Connection refused')) {
            return "Couldn't reach the CMS server. ({$msg})";
        }
        if (str_contains($msg, 'Login failed')) {
            return "CMS refused the login — check the CMS username and password. ({$msg})";
        }
        return $msg;
    }
}

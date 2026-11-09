<?php

declare(strict_types=1);

namespace PII\Services\Sync;

use PII\Core\Database;

/**
 * SyncStatus — the one-state-at-a-time summary behind the status bar and
 * /api/sync/status. Cheap: a couple of indexed MySQL reads, never CMS.
 *
 * States (priority order): syncing · killed · blackout · problem ·
 * auto_off · stale · ok · never (no successful sync yet).
 */
class SyncStatus
{
    public static function current(): array
    {
        $db = Database::getInstance();

        $asOf  = SyncSettings::lastSuccessAt();
        $base  = [
            'as_of'      => $asOf,
            'as_of_unix' => $asOf !== null ? strtotime($asOf) : null,
        ];

        // Syncing / queued?
        // queued_secs comes from the database clock (created_at's writer);
        // PHP's time() may be in a different timezone than MySQL.
        $active = $db->fetch(
            "SELECT sr.*, u.display_name, u.username,
                    TIMESTAMPDIFF(SECOND, sr.created_at, NOW()) AS queued_secs
             FROM sync_runs sr
             LEFT JOIN users u ON u.id = sr.triggered_by
             WHERE sr.status IN ('queued','running')
             ORDER BY sr.id ASC LIMIT 1"
        );
        if ($active) {
            // A run that sits queued and never starts means the background
            // worker is down — say so instead of showing "Updating…" forever.
            if ($active['status'] === 'queued' && (int) $active['queued_secs'] > 90) {
                return $base + [
                    'state'     => 'problem',
                    'failed_at' => $active['created_at'],
                    'error'     => "The update was requested but never started — the background worker "
                        . "(insights-sync-worker service) doesn't seem to be running. "
                        . "On the server: sudo systemctl restart insights-sync-worker",
                ];
            }
            $startedBy = $active['trigger_type'] === 'manual'
                ? (($active['display_name'] ?: $active['username']) ?? 'someone')
                : 'automatic schedule';
            return $base + [
                'state'       => 'syncing',
                'run_id'      => (int) $active['id'],
                'step'        => $active['current_step'],
                'step_num'    => $active['step_num'] !== null ? (int) $active['step_num'] : null,
                'step_total'  => $active['step_total'] !== null ? (int) $active['step_total'] : null,
                'elapsed'     => $active['started_at'] ? max(0, time() - strtotime($active['started_at'])) : 0,
                'started_by'  => $startedBy,
            ];
        }

        if (SyncSettings::killSwitch()) {
            return $base + ['state' => 'killed'];
        }

        $blackout = SyncSettings::activeBlackout();
        if ($blackout !== null) {
            return $base + [
                'state' => 'blackout',
                'until' => $blackout['until']->format('g:i A'),
            ];
        }

        // Latest finished run failed more recently than the last success?
        $latest = $db->fetch(
            "SELECT status, finished_at, error FROM sync_runs
             WHERE status IN ('success','failed','aborted')
             ORDER BY id DESC LIMIT 1"
        );
        if ($latest && $latest['status'] === 'failed') {
            return $base + [
                'state'     => 'problem',
                'failed_at' => $latest['finished_at'],
                'error'     => $latest['error'],
            ];
        }

        if (!SyncSettings::autoEnabled()) {
            return $base + ['state' => 'auto_off'];
        }

        if ($asOf === null) {
            return $base + ['state' => 'never'];
        }

        $staleAfter = SyncSettings::intervalMinutes() * 3 * 60;
        if (time() - strtotime($asOf) > $staleAfter) {
            return $base + ['state' => 'stale'];
        }

        return $base + ['state' => 'ok'];
    }
}

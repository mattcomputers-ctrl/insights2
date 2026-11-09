<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\CSRF;
use PII\Core\Database;
use PII\Services\PermissionService;
use PII\Services\Sync\SyncSettings;
use PII\Services\Sync\SyncStatus;

/**
 * SyncController — the tiny endpoints behind the always-visible status bar.
 *
 *   GET  /api/sync/status  cheap state poll (every 2 s while syncing, 15 s otherwise)
 *   POST /api/sync/run     queue a manual sync (sync.manual; override needs
 *                          sync.override_blackout and override=1)
 */
class SyncController
{
    public function status(): void
    {
        header('Cache-Control: no-store, must-revalidate');
        $userId = (int) current_user_id();

        $status = SyncStatus::current();
        $status['can_sync']     = PermissionService::can($userId, 'sync.manual');
        $status['can_override'] = PermissionService::can($userId, 'sync.override_blackout');
        $status['server_unix']  = time();
        json_response($status);
    }

    public function run(): void
    {
        require_permission('sync.manual');
        try {
            CSRF::validateRequest();
        } catch (\RuntimeException $e) {
            json_response(['ok' => false, 'message' => 'Session expired — reload the page and try again.'], 400);
        }

        $userId = (int) current_user_id();
        $db     = Database::getInstance();

        // Already running or queued?
        $active = $db->fetch(
            "SELECT sr.*, u.display_name, u.username
             FROM sync_runs sr LEFT JOIN users u ON u.id = sr.triggered_by
             WHERE sr.status IN ('queued','running') ORDER BY sr.id ASC LIMIT 1"
        );
        if ($active) {
            $who = $active['trigger_type'] === 'manual'
                ? (($active['display_name'] ?: $active['username']) ?? 'someone')
                : 'the automatic schedule';
            $ago = $active['started_at'] ? (time() - strtotime($active['started_at'])) : 0;
            json_response([
                'ok'      => false,
                'already' => true,
                'message' => "Already updating — started by {$who}" . ($ago > 0 ? " {$ago} s ago" : '') . '.',
            ]);
        }

        if (SyncSettings::killSwitch()) {
            json_response(['ok' => false, 'message' => 'CMS access is switched off by an administrator.']);
        }

        $override = false;
        if (SyncSettings::activeBlackout() !== null) {
            $wantsOverride = ($_POST['override'] ?? '') === '1';
            if (!$wantsOverride || !PermissionService::can($userId, 'sync.override_blackout')) {
                $until = SyncSettings::activeBlackout()['until']->format('g:i A');
                json_response([
                    'ok'            => false,
                    'blackout'      => true,
                    'message'       => "Updates are paused for the nightly backup until {$until}.",
                    'can_override'  => PermissionService::can($userId, 'sync.override_blackout'),
                ]);
            }
            $override = true;
        }

        $id = $db->insert('sync_runs', [
            'trigger_type'      => 'manual',
            'triggered_by'      => $userId,
            'override_blackout' => $override ? 1 : 0,
            'status'            => 'queued',
        ]);

        $db->insert('audit_log', [
            'user_id'     => $userId,
            'entity_type' => 'sync',
            'entity_id'   => (string) $id,
            'action'      => $override ? 'manual_sync_override' : 'manual_sync',
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        json_response(['ok' => true, 'run_id' => (int) $id]);
    }
}

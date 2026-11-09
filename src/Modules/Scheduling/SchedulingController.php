<?php

declare(strict_types=1);

namespace PII\Modules\Scheduling;

use PII\Core\App;
use PII\Core\CMSDatabase;
use PII\Core\CSRF;
use PII\Core\Database;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

class SchedulingController
{
    /* ------------------------------------------------------------------
     *  Main schedule page
     * ----------------------------------------------------------------*/

    public function index(): void
    {
        $svc = new SchedulingDataService();

        layout('scheduling::index', [
            'pageTitle' => 'Production Scheduling',
            'mills'     => $svc->mills(),
            'hasCms'    => CMSDatabase::isConfigured(),
        ], 'scheduling');
    }

    /**
     * GET /scheduling/generate?week=YYYY-MM-DD&days=1,1,1,1,1,0,0
     * Returns the generated schedule as JSON (client renders + drag-drops).
     */
    public function generate(): void
    {
        $week = (string) ($_GET['week'] ?? '');
        if (!valid_date($week)) {
            json_response(['error' => 'Invalid week start date'], 400);
        }
        // Normalise to the Monday of that week
        $dow = (int) date('N', strtotime($week));   // 1 = Monday
        $weekStart = date('Y-m-d', strtotime($week . ' -' . ($dow - 1) . ' day'));

        $daysCsv = (string) ($_GET['days'] ?? '1,1,1,1,1,0,0');
        $enabledDays = array_map('intval', array_pad(explode(',', $daysCsv), 7, 0));

        try {
            $svc = new SchedulingDataService();

            $mills = $svc->mills();
            if (empty($mills)) {
                json_response(['error' => 'No active mills configured. Add equipment in Scheduling → Settings.'], 400);
            }

            $itemConfigs = $svc->itemConfigs();

            // The workload comes from the Order Readiness snapshot (open
            // tickets, order shortfalls, the production plan) — CMS is only
            // consulted for pack→bulk mapping, popularity and dry-grind
            // derivation, all best-effort.
            $cmsWarnings = [];
            $packToBulk = [];
            $bulkDescs = [];
            $popularity = [];
            $derived = ['passes' => [], 'dry' => [], 'milled' => []];
            if (CMSDatabase::isConfigured()) {
                try {
                    $ptb = $svc->packToBulkMap();
                    $packToBulk = $ptb['map'];
                    $bulkDescs  = $ptb['descriptions'];
                    $popularity = $svc->popularityByBulk($packToBulk);
                    $derived    = $svc->derivePasses(array_keys($itemConfigs));
                } catch (\Throwable $e) {
                    $cmsWarnings[] = 'CMS is unavailable right now — scheduling from the latest sync. '
                        . 'Dry-grind derivation and popularity ranking are skipped this run.';
                }
            } else {
                $cmsWarnings[] = 'CMS is not configured — scheduling from the latest sync; dry-grind derivation and popularity are skipped.';
            }

            $workload = $svc->workload($packToBulk);
            if ($workload === []) {
                $cmsWarnings[] = 'The snapshot has no open tickets and nothing on the production plan — run a sync if this looks wrong.';
            }

            // Items with no config: guess a ladder color from alias prefixes
            // (CMS best-effort) and fall back to the global default sizes.
            $defaultSizes = $svc->defaultBatchSizes();
            $guessedColors = [];
            $unconfigured = array_values(array_unique(array_filter(
                array_column($workload, 'bulk'),
                fn($b) => !isset($itemConfigs[$b])
            )));
            if ($unconfigured !== [] && CMSDatabase::isConfigured()) {
                try {
                    $guessedColors = $svc->guessColors($unconfigured);
                } catch (\Throwable $e) {
                    // CMS down — colors stay unknown; already warned above.
                }
            }

            $engine   = new ScheduleEngine($svc->colorOrder());
            $schedule = $engine->build(
                $workload, $itemConfigs,
                $mills, $popularity, $weekStart, $enabledDays,
                $derived['passes'], $derived['dry'], $bulkDescs,
                $derived['milled'], $defaultSizes, $guessedColors
            );

            // Standard batch sizes per bulk — the size picker when the user
            // edits a run's lbs on the schedule (Matt, 2026-11-04).
            $sizeOptions = [];
            foreach ($itemConfigs as $bulk => $cfg) {
                if (!empty($cfg['sizes'])) {
                    $sizeOptions[$bulk] = $cfg['sizes'];
                }
            }
            $schedule['size_options']  = $sizeOptions;
            $schedule['default_sizes'] = $defaultSizes;
            if (!empty($cmsWarnings)) {
                $schedule['warnings'] = array_merge($cmsWarnings, $schedule['warnings'] ?? []);
            }

            // Config foot-gun warnings — surface silently-inert trigger setups.
            $preWarnings = [];
            $dryEmpty  = empty($svc->dryGrindTriggers());
            $millEmpty = empty($svc->millTriggers());
            if ($dryEmpty && $millEmpty) {
                $preWarnings[] = 'No dry-grind OR milling trigger patterns configured — nothing derives as needing milling, so ONLY batches over 50 lbs are being scheduled. Add trigger patterns in Scheduling → Settings.';
            } elseif ($dryEmpty) {
                $preWarnings[] = 'No dry-grind trigger patterns configured — no items will derive as dry grinds. Add triggers in Scheduling → Settings.';
            }
            $lazyMills = [];
            foreach ($mills as $m) {
                if (!empty($m['dry_grind_capable'])
                    && (float) ($m['lbs_per_hour_dry'] ?? 0) === (float) $m['lbs_per_hour']) {
                    $lazyMills[] = $m['name'];
                }
            }
            if (!empty($lazyMills)) {
                $preWarnings[] = 'Dry-grind rate equals the standard rate on: ' . implode(', ', $lazyMills)
                    . ' — dry grinding will not run slower until you set the real "Lbs/hr dry" for these mills.';
            }
            if (!empty($preWarnings)) {
                $schedule['warnings'] = array_merge($preWarnings, $schedule['warnings'] ?? []);
            }

            Database::getInstance()->insert('audit_log', [
                'user_id'     => current_user_id(),
                'entity_type' => 'scheduling',
                'entity_id'   => $weekStart,
                'action'      => 'generate',
                'details'     => json_encode(['days' => $enabledDays]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            json_response($schedule);
        } catch (\Throwable $e) {
            json_response(['error' => 'Schedule generation failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /scheduling/export — body contains the (possibly drag-drop
     * edited) schedule JSON; streams the xlsx.
     */
    public function export(): void
    {
        require_permission('export');
        CSRF::validateRequest();

        $raw = $_POST['schedule'] ?? '';
        $schedule = json_decode((string) $raw, true);
        if (!is_array($schedule) || empty($schedule['mills'])) {
            $_SESSION['_flash']['error'] = 'No schedule to export — generate one first.';
            redirect('/scheduling');
        }

        Database::getInstance()->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => 'scheduling',
            'entity_id'   => (string) ($schedule['week_start'] ?? ''),
            'action'      => 'export_excel',
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        (new ScheduleExporter())->stream($schedule, app_name());
    }

    /* ------------------------------------------------------------------
     *  Settings
     * ----------------------------------------------------------------*/

    public function settings(): void
    {
        require_permission('scheduling.manage');
        $svc = new SchedulingDataService();
        $db  = Database::getInstance();

        $configs = $db->fetchAll("SELECT * FROM sched_item_config ORDER BY bulk_item_code");

        // Worklist: bulks with min-stock packs that aren't configured yet.
        // CMS-dependent; degrade gracefully when CMS is down.
        $needsConfig    = [];
        $worklistError  = null;
        $derivedDry     = null;   // bulk => bool, for the derived column on config rows
        $derivedPasses  = null;
        $derivedMilled  = null;
        if (CMSDatabase::isConfigured()) {
            try {
                $configured = array_flip(array_column($configs, 'bulk_item_code'));
                foreach ($svc->bulksWithMinStock() as $b) {
                    if (!isset($configured[$b['bulk']])) {
                        $needsConfig[] = $b;
                    }
                }
                $derived       = $svc->derivePasses(array_column($configs, 'bulk_item_code'));
                $derivedDry    = $derived['dry'];
                $derivedPasses = $derived['passes'];
                $derivedMilled = $derived['milled'];
            } catch (\Throwable $e) {
                $worklistError = $e->getMessage();
            }
        } else {
            $worklistError = 'CMS database not configured.';
        }

        layout('scheduling::settings', [
            'pageTitle'      => 'Scheduling settings',
            'mills'          => $svc->mills(false),
            'colorOrder'     => $svc->colorOrder(),
            'colors'         => SchedulingDataService::COLORS,
            'configs'        => $configs,
            'defaultSizes'   => $svc->defaultBatchSizes(),
            'needsConfig'    => $needsConfig,
            'worklistError'  => $worklistError,
            'dryTriggers'    => $svc->dryGrindTriggers(),
            'dryPasses'      => $svc->dryGrindPasses(),
            'millTriggers'   => $svc->millTriggers(),
            'derivedDry'     => $derivedDry,
            'derivedPasses'  => $derivedPasses,
            'derivedMilled'  => $derivedMilled,
        ], 'scheduling');
    }

    /* ── Dry grind settings ─────────────────────────────────────── */

    public function saveDryGrind(): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        $svc = new SchedulingDataService();
        $svc->saveDryGrindPasses((int) ($_POST['dry_grind_passes'] ?? 3));

        $newPattern = trim((string) ($_POST['new_pattern'] ?? ''));
        if ($newPattern !== '') {
            try {
                $svc->addDryGrindTrigger($newPattern);
            } catch (\Throwable $e) {
                $_SESSION['_flash']['error'] = $e->getMessage();
                redirect('/scheduling/settings');
            }
        }

        $_SESSION['_flash']['success'] = 'Dry grind settings saved.';
        redirect('/scheduling/settings');
    }

    public function deleteDryGrindTrigger(string $id): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        (new SchedulingDataService())->deleteDryGrindTrigger((int) $id);
        $_SESSION['_flash']['success'] = 'Trigger removed.';
        redirect('/scheduling/settings');
    }

    public function addMillTriggerAction(): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        $pattern = trim((string) ($_POST['new_pattern'] ?? ''));
        if ($pattern !== '') {
            try {
                (new SchedulingDataService())->addMillTrigger($pattern);
                $_SESSION['_flash']['success'] = 'Milling trigger added.';
            } catch (\Throwable $e) {
                $_SESSION['_flash']['error'] = $e->getMessage();
            }
        }
        redirect('/scheduling/settings');
    }

    public function deleteMillTriggerAction(string $id): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        (new SchedulingDataService())->deleteMillTrigger((int) $id);
        $_SESSION['_flash']['success'] = 'Milling trigger removed.';
        redirect('/scheduling/settings');
    }

    public function storeMill(): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        $this->saveMillFromPost(null);
        $_SESSION['_flash']['success'] = 'Mill added.';
        redirect('/scheduling/settings');
    }

    public function updateMill(string $id): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        $this->saveMillFromPost((int) $id);
        $_SESSION['_flash']['success'] = 'Mill updated.';
        redirect('/scheduling/settings');
    }

    public function deleteMill(string $id): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        Database::getInstance()->delete('sched_mills', 'id = ?', [(int) $id]);
        $_SESSION['_flash']['success'] = 'Mill deleted.';
        redirect('/scheduling/settings');
    }

    private function saveMillFromPost(?int $id): void
    {
        $data = [
            'name'                => trim((string) ($_POST['name'] ?? '')),
            'lbs_per_hour'        => (float) ($_POST['lbs_per_hour'] ?? 0),
            'lbs_per_hour_dry'    => (float) ($_POST['lbs_per_hour_dry'] ?? 0),
            'washup_like_minutes' => (float) ($_POST['washup_like_minutes'] ?? 0),
            'washup_next_minutes' => (float) ($_POST['washup_next_minutes'] ?? 0),
            'washup_deep_minutes' => (float) ($_POST['washup_deep_minutes'] ?? 0),
            'hours_per_day'       => (float) ($_POST['hours_per_day'] ?? 8),
            'max_batch_lbs'       => (float) ($_POST['max_batch_lbs'] ?? 0),
            'dry_grind_capable'   => isset($_POST['dry_grind_capable']) ? 1 : 0,
            'is_active'           => isset($_POST['is_active']) ? 1 : 0,
            'sort_order'          => (int) ($_POST['sort_order'] ?? 0),
        ];
        if ($data['name'] === '') {
            $_SESSION['_flash']['error'] = 'Mill name is required.';
            redirect('/scheduling/settings');
        }

        $db = Database::getInstance();
        if ($id === null) {
            $db->insert('sched_mills', $data);
        } else {
            $db->update('sched_mills', $data, 'id = ?', [$id]);
        }
    }

    public function saveColorOrder(): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        $order = $_POST['color_order'] ?? [];
        try {
            (new SchedulingDataService())->saveColorOrder(is_array($order) ? $order : []);
            $_SESSION['_flash']['success'] = 'Color order saved.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }
        redirect('/scheduling/settings');
    }

    /** GET /scheduling/settings/item-search?q=E10 → JSON matches from CMS */
    public function itemSearch(): void
    {
        require_permission('scheduling.manage');
        $q = trim((string) ($_GET['q'] ?? ''));
        if (strlen($q) < 2) {
            json_response([]);
        }
        try {
            json_response((new SchedulingDataService())->searchItems($q));
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 500);
        }
    }

    public function saveItemConfig(): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        $code      = trim((string) ($_POST['bulk_item_code'] ?? ''));
        $color     = trim((string) ($_POST['color'] ?? ''));
        $notMilled = isset($_POST['not_milled']) ? 1 : 0;

        // Up to 5 batch sizes; blanks drop out, kept largest-first so
        // batch_size_1 is always the primary (largest) size.
        $sizes = [];
        for ($i = 1; $i <= 5; $i++) {
            $raw = trim((string) ($_POST['batch_size_' . $i] ?? ''));
            if ($raw !== '' && (float) $raw > 0) {
                $sizes[] = (float) $raw;
            }
        }
        rsort($sizes);
        $sizes = array_slice($sizes, 0, 5);
        $b1 = $sizes[0] ?? 0.0;

        // Not-milled items never schedule, so color/batch are irrelevant —
        // default them so checking the box alone is a complete config.
        if ($notMilled) {
            if (!in_array($color, SchedulingDataService::COLORS, true)) $color = 'extender';
            if ($b1 <= 0) { $b1 = 1; $sizes = [1.0]; }
        }

        if ($code === '' || $b1 <= 0 || !in_array($color, SchedulingDataService::COLORS, true)) {
            $_SESSION['_flash']['error'] = 'Item code, a valid color, and at least one positive batch size are required.';
            redirect('/scheduling/settings');
        }

        $db = Database::getInstance();
        $exists = $db->fetch("SELECT 1 FROM sched_item_config WHERE bulk_item_code = ?", [$code]);
        $data = [
            'color' => $color,
            'batch_size_1' => $b1,
            'batch_size_2' => $sizes[1] ?? null,
            'batch_size_3' => $sizes[2] ?? null,
            'batch_size_4' => $sizes[3] ?? null,
            'batch_size_5' => $sizes[4] ?? null,
            'not_milled' => $notMilled,
        ];
        if ($exists) {
            $db->update('sched_item_config', $data, 'bulk_item_code = ?', [$code]);
        } else {
            $db->insert('sched_item_config', array_merge(['bulk_item_code' => $code], $data));
        }

        $_SESSION['_flash']['success'] = "Scheduling config saved for {$code}.";
        redirect('/scheduling/settings');
    }

    /** POST /scheduling/settings/default-sizes — global fallback batch sizes. */
    public function saveDefaultSizes(): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        $sizes = [];
        for ($i = 1; $i <= 5; $i++) {
            $raw = trim((string) ($_POST['default_size_' . $i] ?? ''));
            if ($raw !== '' && (float) $raw > 0) {
                $sizes[] = (float) $raw;
            }
        }
        (new SchedulingDataService())->saveDefaultBatchSizes($sizes);
        $_SESSION['_flash']['success'] = $sizes === []
            ? 'Default batch sizes cleared — unconfigured items will not be scheduled for suggested work.'
            : 'Default batch sizes saved.';
        redirect('/scheduling/settings');
    }

    /**
     * GET /scheduling/settings/item-defaults?code=E1000 — the starting point
     * for a new item config: batch sizes the item has actually been made at
     * and a color guessed from its alias prefixes (Matt, 2026-11-04).
     */
    public function itemDefaults(): void
    {
        require_permission('scheduling.manage');
        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            json_response(['error' => 'code required'], 400);
        }
        try {
            $svc = new SchedulingDataService();
            $history = $svc->historicalBatchSizes($code);   // most frequent first
            $sizes = array_column($history, 'lbs');
            rsort($sizes);
            $fromAlias = $svc->guessColorsFromAliases([$code]);
            $guess = $fromAlias + $svc->guessColorsFromDescriptions(isset($fromAlias[$code]) ? [] : [$code]);
            json_response([
                'sizes' => $sizes,
                'history' => $history,
                'color' => $guess[$code] ?? null,
                'color_source' => isset($fromAlias[$code]) ? 'alias prefix' : (isset($guess[$code]) ? 'description' : null),
                'from_history' => $sizes !== [],
                'default_sizes' => $svc->defaultBatchSizes(),
            ]);
        } catch (\Throwable $e) {
            json_response(['error' => 'CMS lookup failed: ' . $e->getMessage()], 500);
        }
    }

    public function deleteItemConfig(): void
    {
        require_permission('scheduling.manage');
        CSRF::validateRequest();
        $code = trim((string) ($_POST['bulk_item_code'] ?? ''));
        Database::getInstance()->delete('sched_item_config', 'bulk_item_code = ?', [$code]);
        $_SESSION['_flash']['success'] = "Config removed for {$code}.";
        redirect('/scheduling/settings');
    }
}

<?php

declare(strict_types=1);

namespace PII\Modules\OrderReadiness;

use PII\Cms\Write\CmsWriter;
use PII\Core\CSRF;
use PII\Core\Database;
use PII\Services\Settings;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * OrderReadinessController — snapshot-table reads only (never CMS).
 */
class OrderReadinessController
{
    /* ── Overview ──────────────────────────────────────────────── */

    public function overview(): void
    {
        $db = Database::getInstance();
        $rollups = $this->orderRollups();

        $counts = ['ready' => 0, 'waiting' => 0, 'short' => 0, 'short_discontinued' => 0];
        foreach ($rollups as $r) {
            $counts[$r['status']]++;
        }

        $sug = ['below' => 0, 'near' => 0, 'negative' => 0];
        foreach ($db->fetchAll("SELECT severity, COUNT(*) AS n FROM or_production_suggestions GROUP BY severity") as $r) {
            $sug[$r['severity']] = (int) $r['n'];
        }

        layout('order_readiness::overview', [
            'pageTitle' => 'Order Readiness',
            'counts'    => $counts,
            'sug'       => $sug,
            'ticketsShortMaterials' => $this->shortBatchCount(),
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'order_readiness');
    }

    /* ── Orders list (tabs per status) ─────────────────────────── */

    public function orders(): void
    {
        $tab    = $_GET['tab'] ?? 'ready';
        $tab    = in_array($tab, ['ready', 'waiting', 'short', 'discontinued', 'all', 'priority'], true) ? $tab : 'ready';
        $search = trim((string) ($_GET['q'] ?? ''));

        $rollups = $this->orderRollups();

        // Attach note counts.
        $noteCounts = [];
        foreach (Database::getInstance()->fetchAll(
            "SELECT ordr, COUNT(*) AS n FROM or_order_notes GROUP BY ordr"
        ) as $r) {
            $noteCounts[(int) $r['ordr']] = (int) $r['n'];
        }

        $filtered = array_filter($rollups, function ($o) use ($tab, $search) {
            $match = match ($tab) {
                'ready'        => $o['status'] === 'ready',
                'waiting'      => $o['status'] === 'waiting',
                'short'        => in_array($o['status'], ['short', 'short_discontinued'], true),
                'discontinued' => $o['status'] === 'short_discontinued',
                default        => true,
            };
            if ($match && $search !== '') {
                $hay = $o['ordr'] . ' ' . $o['bill_to'] . ' ' . $o['bill_to_name'] . ' '
                     . $o['ship_to_name'] . ' ' . $o['po_number'] . ' ' . $o['items'];
                $match = stripos($hay, $search) !== false;
            }
            return $match;
        });

        if ($tab === 'priority') {
            usort($filtered, fn($a, $b) => $a['position'] <=> $b['position']);
        } else {
            usort($filtered, fn($a, $b) =>
                [$a['date_required'] ?? '9999', $a['ordr']] <=> [$b['date_required'] ?? '9999', $b['ordr']]);
        }

        $tabCounts = ['ready' => 0, 'waiting' => 0, 'short' => 0, 'discontinued' => 0, 'all' => count($rollups)];
        foreach ($rollups as $o) {
            if ($o['status'] === 'ready')   $tabCounts['ready']++;
            if ($o['status'] === 'waiting') $tabCounts['waiting']++;
            if (in_array($o['status'], ['short', 'short_discontinued'], true)) $tabCounts['short']++;
            if ($o['status'] === 'short_discontinued') $tabCounts['discontinued']++;
        }

        $writer = CmsWriter::forCurrentUser();
        layout('order_readiness::orders', [
            'canRelease'  => $writer->allowed('sales.release_order'),
            'canShip'     => $writer->allowed('sales.complete_sale'),
            'mode'        => $writer->mode(),
            'pageTitle'  => 'Orders',
            'tab'        => $tab,
            'search'     => $search,
            'orders'     => array_values($filtered),
            'tabCounts'  => $tabCounts,
            'noteCounts' => $noteCounts,
            'asOf'       => SyncSettings::lastSuccessAt(),
            'canReorder' => user_can('production.mark'),
        ], 'order_readiness');
    }

    /** AJAX: the expanded line view for one order (+ notes). */
    public function orderLines(string $ordr): void
    {
        $db   = Database::getInstance();
        $ordr = (int) $ordr;

        $lines = $db->fetchAll(
            "SELECT l.*, c.status AS cov_status, c.reason, c.shortfall, c.allocations
             FROM or_order_lines l
             LEFT JOIN or_coverage_results c ON c.ord_detail = l.ord_detail
             WHERE l.ordr = ?
             ORDER BY l.ord_detail",
            [$ordr]
        );
        $notes = $db->fetchAll(
            "SELECT n.*, u.display_name, u.username
             FROM or_order_notes n LEFT JOIN users u ON u.id = n.user_id
             WHERE n.ordr = ? ORDER BY n.id DESC",
            [$ordr]
        );

        view('order_readiness::_order_lines', [
            'ordr'      => $ordr,
            'lines'     => $lines,
            'notes'     => $notes,
            'readiness' => $this->ticketReadiness(),
        ]);
    }

    /** POST: add a note to an order (kept forever; searchable later). */
    public function addNote(string $ordr): void
    {
        CSRF::validateRequest();
        $ordr = (int) $ordr;
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '' || $ordr <= 0) {
            json_response(['ok' => false, 'message' => 'Write something first.'], 400);
        }
        if (mb_strlen($note) > 2000) {
            json_response(['ok' => false, 'message' => 'Notes are limited to 2,000 characters.'], 400);
        }
        Database::getInstance()->insert('or_order_notes', [
            'ordr'       => $ordr,
            'note'       => $note,
            'user_id'    => current_user_id(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        json_response(['ok' => true]);
    }

    /** POST: new priority order (drag-and-drop list of order numbers). */
    public function savePriority(): void
    {
        require_permission('production.mark');
        CSRF::validateRequest();

        $raw = $_POST['positions'] ?? '';
        $ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw))));
        if ($ids === []) {
            json_response(['ok' => false, 'message' => 'Nothing to save.'], 400);
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // Keep any orders not in the submitted list after the listed ones.
            $existing = array_column($db->fetchAll("SELECT ordr FROM or_order_priority ORDER BY position"), 'ordr');
            $rest = array_values(array_diff(array_map('intval', $existing), $ids));
            $db->delete('or_order_priority', '1=1');
            foreach (array_merge($ids, $rest) as $i => $o) {
                $db->insert('or_order_priority', ['ordr' => $o, 'position' => $i + 1]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            json_response(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        // Recompute coverage from the current snapshot with the new priority.
        $this->recomputeCoverageFromSnapshot();

        Database::getInstance()->insert('audit_log', [
            'user_id' => current_user_id(), 'entity_type' => 'order_priority',
            'entity_id' => null, 'action' => 'reorder',
            'details' => json_encode(['order' => $ids]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        json_response(['ok' => true]);
    }

    /* ── By item ───────────────────────────────────────────────── */

    public function byItem(): void
    {
        require_permission('orders.items_view');
        $db = Database::getInstance();
        $search = trim((string) ($_GET['q'] ?? ''));

        $rows = $db->fetchAll(
            "SELECT l.item_code,
                    MAX(COALESCE(l.alias_description, i.description)) AS description,
                    MAX(i.unit) AS unit,
                    SUM(l.qty_remaining) AS open_demand,
                    MAX(COALESCE(v.qty_usable, 0)) AS available,
                    MAX(COALESCE(i.is_discontinued, 0)) AS is_discontinued,
                    COUNT(DISTINCT l.ordr) AS order_count
             FROM or_order_lines l
             LEFT JOIN or_items i     ON i.item_code = l.item_code
             LEFT JOIN or_inventory v ON v.item_code = l.item_code
             GROUP BY l.item_code
             ORDER BY l.item_code"
        );

        $supply = [];
        foreach ($db->fetchAll("SELECT item_code, SUM(qty) AS q FROM or_tickets WHERE context='MFPP' GROUP BY item_code") as $t) {
            $supply[$t['item_code']] = (float) $t['q'];
        }

        // Who gets the stock: allocations per item from coverage results.
        $allocByItem = [];
        foreach ($db->fetchAll(
            "SELECT c.ord_detail, c.ordr, c.status, c.allocations, l.item_code, l.qty_remaining, o.bill_to_name
             FROM or_coverage_results c
             JOIN or_order_lines l ON l.ord_detail = c.ord_detail
             LEFT JOIN or_orders o ON o.ordr = c.ordr"
        ) as $cr) {
            $allocByItem[$cr['item_code']][] = $cr;
        }

        if ($search !== '') {
            $rows = array_values(array_filter($rows, fn($r) =>
                stripos($r['item_code'] . ' ' . $r['description'], $search) !== false));
        }

        layout('order_readiness::by_item', [
            'pageTitle'   => 'Coverage by item',
            'rows'        => $rows,
            'supply'      => $supply,
            'allocByItem' => $allocByItem,
            'search'      => $search,
            'asOf'        => SyncSettings::lastSuccessAt(),
        ], 'order_readiness');
    }

    /* ── Batch tickets ─────────────────────────────────────────── */

    public function tickets(): void
    {
        require_permission('orders.tickets_view');
        $db = Database::getInstance();

        $tickets = $db->fetchAll(
            "SELECT t.*, i.description
             FROM or_tickets t
             LEFT JOIN or_items i ON i.item_code = t.item_code
             ORDER BY t.date_required, t.ordr"
        );

        // Materials check per ticket (+ names for the short materials).
        $readiness = $this->ticketReadiness();
        $matNames = [];
        $shortCodes = [];
        foreach ($readiness as $r) {
            foreach ($r['shortages'] as $s) {
                $shortCodes[$s['item_code']] = true;
            }
        }
        if ($shortCodes !== []) {
            $in = implode(',', array_fill(0, count($shortCodes), '?'));
            foreach ($db->fetchAll("SELECT item_code, description FROM or_items WHERE item_code IN ({$in})", array_keys($shortCodes)) as $r) {
                $matNames[$r['item_code']] = $r['description'];
            }
        }

        // Which order lines each ticket is covering (from allocations JSON).
        $covering = [];
        foreach ($db->fetchAll(
            "SELECT c.allocations, c.ordr, o.bill_to_name, l.item_code, l.alias_code
             FROM or_coverage_results c
             JOIN or_order_lines l ON l.ord_detail = c.ord_detail
             LEFT JOIN or_orders o ON o.ordr = c.ordr
             WHERE c.allocations LIKE '%ticket%'"
        ) as $cr) {
            foreach (json_decode((string) $cr['allocations'], true) ?: [] as $a) {
                if (($a['type'] ?? '') === 'ticket') {
                    $covering[(int) $a['ticket']][] = [
                        'ordr' => (int) $cr['ordr'],
                        'bill_to_name' => $cr['bill_to_name'],
                        'qty' => (float) $a['qty'],
                    ];
                }
            }
        }

        $batches = $this->batchGroups($tickets);

        $search = trim((string) ($_GET['q'] ?? ''));
        if ($search !== '') {
            $batches = array_values(array_filter($batches, function ($g) use ($search) {
                $hay = $g['batch'] . ' ' . ($g['customer_hint'] ?? '');
                foreach (array_merge($g['mfba'] !== null ? [$g['mfba']] : [], $g['packs']) as $t) {
                    $hay .= ' ' . $t['item_code'] . ' ' . ($t['description'] ?? '') . ' ' . ($t['bulk_code'] ?? '');
                }
                return stripos($hay, $search) !== false;
            }));
        }

        layout('order_readiness::tickets', [
            'pageTitle' => 'Batch tickets',
            'batches'   => $batches,
            'search'    => $search,
            'covering'  => $covering,
            'readiness' => $readiness,
            'matNames'  => $matNames,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'order_readiness');
    }

    /* ── Short materials (consolidated across all open tickets) ── */

    public function shortMaterials(): void
    {
        require_permission('orders.tickets_view');
        $search = trim((string) ($_GET['q'] ?? ''));

        $rows = $this->shortMaterialRows();
        if ($search !== '') {
            $rows = array_values(array_filter($rows, fn($r) =>
                stripos($r['item_code'] . ' ' . $r['description'], $search) !== false));
        }

        layout('order_readiness::short_materials', [
            'pageTitle' => 'Short materials',
            'rows'      => $rows,
            'search'    => $search,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'order_readiness');
    }

    /**
     * One row per material that any open ticket is short of: how much is
     * missing in total to complete the tickets on hand, plus what's on
     * hand / already on order, and which batches need it (Matt, 2026-10-29).
     */
    private function shortMaterialRows(): array
    {
        $db = Database::getInstance();

        $tickets = [];
        foreach ($db->fetchAll("SELECT ordr, context, batch_ordr, item_code, date_required FROM or_tickets") as $t) {
            $tickets[(int) $t['ordr']] = $t;
        }

        $byMat = [];
        foreach ($this->ticketReadiness() as $ordr => $r) {
            if ($r['status'] !== 'short') {
                continue;
            }
            $t = $tickets[$ordr] ?? null;
            foreach ($r['shortages'] as $s) {
                $code = $s['item_code'];
                $byMat[$code] ??= [
                    'item_code' => $code, 'unit' => (string) ($s['unit'] ?? ''),
                    'short' => 0.0, 'need' => 0.0, 'batches' => [], 'earliest' => null,
                ];
                $byMat[$code]['short'] += (float) $s['short'];
                $byMat[$code]['need']  += (float) $s['need'];
                $due = $t['date_required'] ?? null;
                $byMat[$code]['batches'][] = [
                    'batch' => (int) ($t['batch_ordr'] ?? $ordr),
                    'makes' => (string) ($t['item_code'] ?? ''),
                    'short' => (float) $s['short'],
                    'due'   => $due,
                ];
                if ($due !== null && ($byMat[$code]['earliest'] === null || $due < $byMat[$code]['earliest'])) {
                    $byMat[$code]['earliest'] = $due;
                }
            }
        }
        if ($byMat === []) {
            return [];
        }

        // Names, on-hand and on-order for just these materials.
        $in = implode(',', array_fill(0, count($byMat), '?'));
        $codes = array_keys($byMat);
        foreach ($db->fetchAll(
            "SELECT i.item_code, i.description, COALESCE(v.qty_usable, 0) AS on_hand
             FROM or_items i LEFT JOIN or_inventory v ON v.item_code = i.item_code
             WHERE i.item_code IN ({$in})", $codes) as $r) {
            $byMat[$r['item_code']]['description'] = $r['description'];
            $byMat[$r['item_code']]['on_hand'] = (float) $r['on_hand'];
        }
        try {
            foreach ($db->fetchAll(
                "SELECT item_code, qty_on_po FROM inv_items WHERE item_code IN ({$in})", $codes) as $r) {
                $byMat[$r['item_code']]['on_po'] = (float) $r['qty_on_po'];
            }
        } catch (\Throwable $e) {
            // Inventory Browser snapshot not there yet — column shows "—"
        }

        foreach ($byMat as &$m) {
            $m += ['description' => '', 'on_hand' => 0.0, 'on_po' => null];
            usort($m['batches'], fn($a, $b) => [$a['due'] ?? '9999', $a['batch']] <=> [$b['due'] ?? '9999', $b['batch']]);
        }
        unset($m);

        $rows = array_values($byMat);
        usort($rows, fn($a, $b) =>
            [$a['earliest'] ?? '9999', -$a['short']] <=> [$b['earliest'] ?? '9999', -$b['short']]);
        return $rows;
    }

    /* ── Production plan ───────────────────────────────────────── */

    public function production(): void
    {
        require_permission('production.view');
        $db = Database::getInstance();

        $rows = $db->fetchAll(
            "SELECT s.*, i.description, m.mark, m.skip_until, m.note AS mark_note,
                    u.display_name AS marked_by
             FROM or_production_suggestions s
             LEFT JOIN or_items i ON i.item_code = s.item_code
             LEFT JOIN or_suggestion_marks m ON m.item_code = s.item_code
             LEFT JOIN users u ON u.id = m.user_id
             ORDER BY FIELD(s.severity, 'negative','below','near'), s.shortfall DESC"
        );

        $search = trim((string) ($_GET['q'] ?? ''));
        if ($search !== '') {
            $rows = array_values(array_filter($rows, fn($r) =>
                stripos($r['item_code'] . ' ' . ($r['description'] ?? '') . ' ' . ($r['recipe_number'] ?? ''), $search) !== false));
        }

        // Reactivated / newly-discontinued badges (§8.2).
        $badgeDays = Settings::getInt('or.badge_days', 14);
        $badges = [];
        foreach ($db->fetchAll(
            "SELECT item_code, old_value, new_value, seen_at
             FROM or_item_min_stock_history
             WHERE seen_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY id",
            [$badgeDays]
        ) as $h) {
            $was99 = $h['old_value'] !== null && abs((float) $h['old_value'] - 99.0) < 1e-9;
            $is99  = $h['new_value'] !== null && abs((float) $h['new_value'] - 99.0) < 1e-9;
            if ($was99 && !$is99) {
                $badges[$h['item_code']] = 'reactivated';
            } elseif (!$was99 && $is99) {
                $badges[$h['item_code']] = 'newly_discontinued';
            }
        }

        layout('order_readiness::production', [
            'pageTitle' => 'Production plan',
            'rows'      => $rows,
            'badges'    => $badges,
            'canMark'   => user_can('production.mark'),
            'search'    => $search,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'order_readiness');
    }

    public function markSuggestion(): void
    {
        require_permission('production.mark');
        CSRF::validateRequest();
        $db   = Database::getInstance();
        $code = trim((string) ($_POST['item_code'] ?? ''));
        $mark = (string) ($_POST['mark'] ?? '');

        if ($code === '') {
            back();
        }
        if ($mark === 'clear') {
            $db->delete('or_suggestion_marks', 'item_code = ?', [$code]);
        } elseif (in_array($mark, ['scheduled', 'skip'], true)) {
            $until = ($mark === 'skip' && valid_date((string) ($_POST['skip_until'] ?? ''))) ? $_POST['skip_until'] : null;
            $db->delete('or_suggestion_marks', 'item_code = ?', [$code]);
            $db->insert('or_suggestion_marks', [
                'item_code'  => $code,
                'mark'       => $mark,
                'skip_until' => $until,
                'note'       => mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255),
                'user_id'    => current_user_id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $db->insert('audit_log', [
            'user_id' => current_user_id(), 'entity_type' => 'production_mark',
            'entity_id' => $code, 'action' => $mark,
            'details' => json_encode(['note' => $_POST['note'] ?? '']),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        $_SESSION['_flash']['success'] = 'Saved.';
        back();
    }

    /* ── Notes lookup ──────────────────────────────────────────── */

    public function notes(): void
    {
        $db = Database::getInstance();
        $search = trim((string) ($_GET['q'] ?? ''));

        $sql = "SELECT n.*, u.display_name, u.username, o.bill_to_name
                FROM or_order_notes n
                LEFT JOIN users u ON u.id = n.user_id
                LEFT JOIN or_orders o ON o.ordr = n.ordr";
        $params = [];
        if ($search !== '') {
            $sql .= " WHERE CAST(n.ordr AS CHAR) LIKE ? OR n.note LIKE ? OR o.bill_to_name LIKE ?";
            $like = '%' . $search . '%';
            $params = [$like, $like, $like];
        }
        $sql .= " ORDER BY n.id DESC LIMIT 300";

        layout('order_readiness::notes', [
            'pageTitle' => 'Order notes',
            'rows'      => $db->fetchAll($sql, $params),
            'search'    => $search,
        ], 'order_readiness');
    }

    /* ── Exports ───────────────────────────────────────────────── */

    public function export(string $what): void
    {
        require_permission('export');
        $db = Database::getInstance();
        $format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        [$title, $header, $rows] = match ($what) {
            'orders' => $this->exportOrders(),
            'production' => $this->exportProduction($db),
            'tickets' => $this->exportTickets($db),
            'short-materials' => $this->exportShortMaterials(),
            default => [null, [], []],
        };
        if ($title === null) {
            render_not_found();
        }

        Database::getInstance()->insert('audit_log', [
            'user_id' => current_user_id(), 'entity_type' => 'order_readiness',
            'entity_id' => $what, 'action' => 'export_' . $format,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        ListExporter::stream($title, $header, $rows, $format);
    }

    private function exportOrders(): array
    {
        $rollups = $this->orderRollups();
        usort($rollups, fn($a, $b) => [$a['date_required'] ?? '9999', $a['ordr']] <=> [$b['date_required'] ?? '9999', $b['ordr']]);
        $statusLabel = ['ready' => 'Ready to ship', 'waiting' => 'Waiting on batch', 'short' => 'Short', 'short_discontinued' => 'Short - discontinued'];
        $rows = [];
        foreach ($rollups as $o) {
            $rows[] = [
                $o['ordr'], $o['status_code'], $o['bill_to_name'], $o['ship_to_name'],
                $o['date_required'], $o['line_count'], $statusLabel[$o['status']], $o['summary'], $o['po_number'],
            ];
        }
        return ['Open orders', ['Order #', 'CMS status', 'Customer', 'Ship to', 'Required', 'Lines', 'Readiness', 'Why', 'PO number'], $rows];
    }

    private function exportProduction(Database $db): array
    {
        $rows = [];
        foreach ($db->fetchAll(
            "SELECT s.*, i.description FROM or_production_suggestions s
             LEFT JOIN or_items i ON i.item_code = s.item_code
             ORDER BY FIELD(s.severity,'negative','below','near'), s.shortfall DESC"
        ) as $s) {
            $rows[] = [
                $s['item_code'], $s['description'], ucfirst($s['severity']), $s['minimum_stock'],
                $s['on_hand'], $s['on_tickets'], $s['open_demand'], $s['projected'],
                $s['shortfall'], $s['suggested_qty'], $s['open_ticket'] ?? '', $s['recipe_number'], $s['last_made'],
            ];
        }
        return ['Production plan', ['Item', 'Description', 'Severity', 'Minimum', 'On hand', 'On tickets', 'Open demand', 'Projected', 'Shortfall', 'Suggested qty', 'Open ticket #', 'Recipe', 'Last made'], $rows];
    }

    private function exportTickets(Database $db): array
    {
        $readiness = $this->ticketReadiness();
        $tickets = $db->fetchAll(
            "SELECT t.*, i.description FROM or_tickets t
             LEFT JOIN or_items i ON i.item_code = t.item_code
             ORDER BY t.date_required, t.ordr"
        );

        $shortText = fn(array $r): string => implode('; ', array_map(
            fn($s) => $s['item_code'] . ' -' . rtrim(rtrim(number_format((float) $s['short'], 2, '.', ''), '0'), '.') . ' ' . $s['unit'],
            $r['shortages']
        ));
        $statusWord = fn(string $s): string => match ($s) {
            'RLS' => 'released', 'NST' => 'waits for the batch', 'BAT' => 'closing out', default => strtolower($s),
        };

        $rows = [];
        foreach ($this->batchGroups($tickets) as $g) {
            $m = $g['mfba'];

            $materials = [];
            $checked = false;
            foreach (array_merge($m !== null ? [$m] : [], $g['packs']) as $t) {
                $r = $readiness[(int) $t['ordr']] ?? null;
                if ($r === null) {
                    continue;
                }
                $checked = true;
                if ($r['status'] === 'short') {
                    $materials[] = ($t['context'] === 'MFPP' ? 'for ' . $t['item_code'] . ': ' : '') . $shortText($r);
                }
            }
            $materialsText = !$checked ? '' : ($materials === [] ? 'On hand' : 'Short: ' . implode(' | ', $materials));

            $packsText = implode("\n", array_map(
                fn($p) => $p['item_code'] . ' ' . rtrim(rtrim(number_format((float) $p['qty'], 2, '.', ''), '0'), '.')
                    . ' ' . $p['unit'] . ' (' . $statusWord((string) $p['status']) . ')',
                $g['packs']
            ));

            $rows[] = [
                $g['batch'],
                $m !== null ? $m['status'] : 'batch done',
                $materialsText,
                $m['item_code'] ?? ($g['packs'][0]['bulk_code'] ?? ''),
                $m['description'] ?? ($g['packs'][0]['description'] ?? ''),
                $m !== null ? $m['qty'] : '',
                $m !== null ? $m['unit'] : '',
                $g['date_required'],
                $packsText,
                $g['customer_hint'],
            ];
        }
        return ['Open batch tickets', ['Batch #', 'Status', 'Materials', 'Bulk item', 'Description', 'Batch qty', 'Unit', 'Required', 'Packaging', 'Cut for'], $rows];
    }

    private function exportShortMaterials(): array
    {
        $rows = [];
        foreach ($this->shortMaterialRows() as $m) {
            $rows[] = [
                $m['item_code'], $m['description'], $m['unit'],
                round($m['short'], 2), $m['on_hand'], $m['on_po'],
                $m['earliest'],
                implode("\n", array_map(
                    fn($b) => 'batch ' . $b['batch'] . ' (' . $b['makes'] . '): '
                        . rtrim(rtrim(number_format($b['short'], 2, '.', ''), '0'), '.')
                        . ($b['due'] !== null ? ', due ' . $b['due'] : ''),
                    $m['batches']
                )),
            ];
        }
        return ['Short materials', ['Material', 'Description', 'Unit', 'Short (to complete open tickets)', 'On hand', 'On open POs', 'First needed', 'Needed by batches'], $rows];
    }

    /* ── Shared helpers ────────────────────────────────────────── */

    /**
     * One entry per MASTER BATCH; its packaging orders live inside the
     * entry, never as rows of their own (Matt, 2026-10-29). Sorted by
     * earliest required date in the group, then batch number.
     *
     * @return list<array{batch:int,mfba:?array,packs:list<array>,date_required:?string,customer_hint:?string}>
     */
    private function batchGroups(array $tickets): array
    {
        $groups = [];
        foreach ($tickets as $t) {
            $key = (int) ($t['batch_ordr'] ?? $t['ordr']);
            $groups[$key] ??= ['batch' => $key, 'mfba' => null, 'packs' => []];
            if ($t['context'] === 'MFBA') {
                $groups[$key]['mfba'] = $t;
            } else {
                $groups[$key]['packs'][] = $t;
            }
        }
        foreach ($groups as &$g) {
            $dates = [];
            $g['customer_hint'] = $g['mfba']['customer_hint'] ?? null;
            foreach (array_merge($g['mfba'] !== null ? [$g['mfba']] : [], $g['packs']) as $t) {
                if (!empty($t['date_required'])) {
                    $dates[] = $t['date_required'];
                }
                $g['customer_hint'] ??= $t['customer_hint'];
            }
            $g['date_required'] = $dates === [] ? null : min($dates);
        }
        unset($g);
        $batches = array_values($groups);
        usort($batches, fn($a, $b) =>
            [$a['date_required'] ?? '9999', $a['batch']] <=> [$b['date_required'] ?? '9999', $b['batch']]);
        return $batches;
    }

    /** How many MASTER BATCHES are short materials (Matt: count batches, not rows). */
    private function shortBatchCount(): int
    {
        try {
            return (int) (Database::getInstance()->fetch(
                "SELECT COUNT(DISTINCT COALESCE(t.batch_ordr, t.ordr)) AS n
                 FROM or_ticket_readiness r
                 JOIN or_tickets t ON t.ordr = r.ordr
                 WHERE r.status = 'short'"
            )['n'] ?? 0);
        } catch (\Throwable $e) {
            return 0;   // table not there yet (pre-migration page view)
        }
    }

    /**
     * Materials check per ticket, keyed by ticket ordr, shortages decoded.
     * Empty until the first sync after the 014 migration — pages degrade
     * to simply not showing materials info.
     *
     * @return array<int,array{status:string,shortages:list<array>,from_mfba:float,mfba_short:int}>
     */
    private function ticketReadiness(): array
    {
        $out = [];
        try {
            foreach (Database::getInstance()->fetchAll("SELECT * FROM or_ticket_readiness") as $r) {
                $out[(int) $r['ordr']] = [
                    'status'     => (string) $r['status'],
                    'shortages'  => json_decode((string) ($r['shortages'] ?? '[]'), true) ?: [],
                    'from_mfba'  => (float) $r['from_mfba'],
                    'mfba_short' => (int) $r['mfba_short'],
                ];
            }
        } catch (\Throwable $e) {
            // table not there yet (pre-migration page view) — degrade quietly
        }
        return $out;
    }

    /** One row per open order with its rolled-up coverage status. */
    private function orderRollups(): array
    {
        $db = Database::getInstance();
        $orders = [];
        foreach ($db->fetchAll(
            "SELECT o.*, COALESCE(p.position, 999999) AS position
             FROM or_orders o
             LEFT JOIN or_order_priority p ON p.ordr = o.ordr"
        ) as $o) {
            $o['lines'] = [];
            $orders[(int) $o['ordr']] = $o;
        }
        $readiness = $this->ticketReadiness();
        foreach ($db->fetchAll(
            "SELECT c.ordr, c.status, c.allocations, l.item_code, l.alias_code
             FROM or_coverage_results c JOIN or_order_lines l ON l.ord_detail = c.ord_detail"
        ) as $c) {
            if (isset($orders[(int) $c['ordr']])) {
                $orders[(int) $c['ordr']]['lines'][] = $c;
            }
        }

        $out = [];
        foreach ($orders as $o) {
            if ($o['lines'] === []) {
                continue;   // nothing open to cover
            }
            $roll = CoverageEngine::rollUp(array_column($o['lines'], 'status'));

            // Does any batch this order is waiting on lack materials?
            $materialsShort = [];
            foreach ($o['lines'] as $ln) {
                foreach (json_decode((string) ($ln['allocations'] ?? '[]'), true) ?: [] as $a) {
                    if (($a['type'] ?? '') !== 'ticket') {
                        continue;
                    }
                    $r = $readiness[(int) ($a['ticket'] ?? 0)] ?? null;
                    if ($r !== null && ($r['status'] === 'short' || $r['mfba_short'] === 1)) {
                        $materialsShort[(int) ($a['batch'] ?? $a['ticket'])] = true;
                    }
                }
            }
            $out[] = [
                'ordr'          => (int) $o['ordr'],
                'status_code'   => $o['status'],
                'bill_to'       => $o['bill_to'],
                'bill_to_name'  => $o['bill_to_name'],
                'ship_to_name'  => $o['ship_to_name'],
                'po_number'     => $o['po_number'],
                'date_required' => $o['date_required'],
                'date_ordered'  => $o['date_ordered'],
                'position'      => (int) $o['position'],
                'line_count'    => count($o['lines']),
                'status'        => $roll['status'],
                'summary'       => $roll['summary'],
                'is_backorder'  => (int) ($o['is_backorder'] ?? 0),
                'materials_short' => array_keys($materialsShort),
                'items'         => implode(' ', array_column($o['lines'], 'alias_code')),
            ];
        }
        return $out;
    }

    /** Re-run the pure coverage engine against the CURRENT snapshot (no CMS). */
    private function recomputeCoverageFromSnapshot(): void
    {
        $db = Database::getInstance();

        $positions = [];
        foreach ($db->fetchAll("SELECT ordr, position FROM or_order_priority") as $p) {
            $positions[(int) $p['ordr']] = ['position' => (int) $p['position']];
        }
        $items = [];
        foreach ($db->fetchAll("SELECT * FROM or_items") as $i) {
            $items[$i['item_code']] = [
                'unit' => $i['unit'], 'is_discontinued' => (bool) $i['is_discontinued'],
                'is_service' => (bool) ($i['is_service'] ?? 0),
                'description' => $i['description'],
            ];
        }
        $inventory = [];
        foreach ($db->fetchAll("SELECT item_code, qty_usable FROM or_inventory") as $v) {
            $inventory[$v['item_code']] = (float) $v['qty_usable'];
        }
        $lines = $db->fetchAll("SELECT * FROM or_order_lines");
        foreach ($lines as &$l) {
            $l['ord_detail'] = (int) $l['ord_detail'];
            $l['ordr'] = (int) $l['ordr'];
            $l['qty_remaining'] = (float) $l['qty_remaining'];
        }
        unset($l);
        $tickets = $db->fetchAll("SELECT * FROM or_tickets WHERE context = 'MFPP'");
        foreach ($tickets as &$t) {
            $t['ordr'] = (int) $t['ordr'];
            $t['qty'] = (float) $t['qty'];
        }
        unset($t);

        $results = CoverageEngine::compute($positions, $lines, $items, $inventory, $tickets);

        $db->beginTransaction();
        try {
            $db->delete('or_coverage_results', '1=1');
            foreach ($results as $detail => $res) {
                $ordr = 0;
                foreach ($lines as $l) {
                    if ($l['ord_detail'] === $detail) { $ordr = $l['ordr']; break; }
                }
                $db->insert('or_coverage_results', [
                    'ord_detail'  => $detail,
                    'ordr'        => $ordr,
                    'status'      => $res['status'],
                    'allocations' => json_encode($res['allocations'], JSON_UNESCAPED_SLASHES),
                    'covered_qty' => $res['covered_qty'],
                    'shortfall'   => $res['shortfall'],
                    'reason'      => $res['reason'],
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}

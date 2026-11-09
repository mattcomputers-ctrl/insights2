<?php

declare(strict_types=1);

namespace PII\Modules\NeedToOrder;

use PII\Core\Database;
use PII\Modules\OrderReadiness\ListExporter;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * NeedToOrderController — snapshot reads only (nto_items), flat or
 * grouped by vendor. Priority = first-needed date, open tickets first.
 */
class NeedToOrderController
{
    /** group=preferred|last → the nto_items field to group on. */
    private function groupField(): ?string
    {
        return match ($_GET['group'] ?? '') {
            'preferred'       => 'preferred_vendor',
            'last', 'vendor'  => 'vendor',
            default           => null,
        };
    }

    /** Which lens: 'orders' (to complete orders) or 'min' (minimum stock). */
    private function viewMode(): string
    {
        return ($_GET['view'] ?? '') === 'min' ? 'min' : 'orders';
    }

    public function index(): void
    {
        $search = trim((string) ($_GET['q'] ?? ''));
        $field  = $this->groupField();
        $view   = $this->viewMode();

        $rows = $this->rows($search, $view);

        $groups = [];
        if ($field !== null) {
            foreach ($rows as $r) {
                $groups[trim((string) ($r[$field] ?? ''))][] = $r;
            }
            // Vendors alphabetically; "no vendor on file" last.
            uksort($groups, fn($a, $b) => [$a === '', $a] <=> [$b === '', $b]);
        }

        layout('need_to_order::index', [
            'pageTitle' => 'Need to Order',
            'rows'      => $rows,
            'groups'    => $groups,
            'groupBy'   => $field === null ? '' : ($field === 'vendor' ? 'last' : 'preferred'),
            'view'      => $view,
            'search'    => $search,
            'asOf'      => SyncSettings::lastSuccessAt(),
            'canCreatePo' => \PII\Cms\Write\CmsWriter::forCurrentUser()->allowed('po.create_order'),
        ], 'need_to_order');
    }

    public function export(): void
    {
        require_permission('export');
        $field = $this->groupField();
        $view  = $this->viewMode();
        $rows = $this->rows(trim((string) ($_GET['q'] ?? '')), $view);
        if ($field !== null) {
            usort($rows, fn($a, $b) =>
                [($a[$field] ?? '') === '', $a[$field] ?? '', $a['first_needed'] ?? '9999']
                <=> [($b[$field] ?? '') === '', $b[$field] ?? '', $b['first_needed'] ?? '9999']);
        }

        $out = [];
        foreach ($rows as $r) {
            $line = [
                $r['preferred_vendor'], $r['vendor'], $r['vendor_code'], $r['item_code'], $r['description'],
                round((float) $r['qty_needed'], 2), $r['unit'],
            ];
            if ($view === 'min') {
                $line[] = (float) $r['minimum_stock'];
                $line[] = (float) $r['on_hand'];
                $line[] = (float) $r['projected'];
            }
            $out[] = array_merge($line, [
                (float) $r['on_po'],
                $r['first_needed'],
                ($r['src_ticket'] ? 'open tickets' : '') . ($r['src_ticket'] && $r['src_order'] ? ' + ' : '') . ($r['src_order'] ? 'new batches' : ''),
                implode('; ', array_column($r['refs_list'], 'ref')),
                implode(' → ', $r['via_list']),
            ]);
        }

        Database::getInstance()->insert('audit_log', [
            'user_id' => current_user_id(), 'entity_type' => 'need_to_order',
            'entity_id' => null, 'action' => 'export',
            'details' => json_encode(['rows' => count($out), 'view' => $view, 'group' => $_GET['group'] ?? 'none']),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $header = ['Preferred vendor', 'Last purchased from', 'Vendor code', 'Item', 'Description', 'Qty to order', 'Unit'];
        if ($view === 'min') {
            $header = array_merge($header, ['Minimum', 'On hand', 'Projected']);
        }
        $header = array_merge($header, ['On open POs', 'First needed', 'For', 'Needed by', 'To make']);

        ListExporter::stream($view === 'min' ? 'Need to order - minimum stock' : 'Need to order',
            $header, $out, ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx');
    }

    /** Snapshot rows, decoded + filtered + priority-sorted. */
    private function rows(string $search, string $view = 'orders'): array
    {
        $table = $view === 'min' ? 'nto_min_items' : 'nto_items';
        $rows = Database::getInstance()->fetchAll("SELECT * FROM {$table}");
        foreach ($rows as &$r) {
            $r['refs_list'] = json_decode((string) ($r['refs'] ?? '[]'), true) ?: [];
            $r['via_list']  = json_decode((string) ($r['via'] ?? '[]'), true) ?: [];
        }
        unset($r);

        if ($search !== '') {
            $rows = array_values(array_filter($rows, fn($r) =>
                stripos($r['item_code'] . ' ' . $r['description'] . ' ' . ($r['preferred_vendor'] ?? '')
                    . ' ' . ($r['vendor'] ?? '') . ' ' . ($r['vendor_code'] ?? ''), $search) !== false));
        }

        // Priority: what open tickets wait on first, then soonest needed.
        usort($rows, fn($a, $b) =>
            [-(int) $a['src_ticket'], $a['first_needed'] ?? '9999', -(float) $a['qty_needed']]
            <=> [-(int) $b['src_ticket'], $b['first_needed'] ?? '9999', -(float) $b['qty_needed']]);
        return $rows;
    }
}

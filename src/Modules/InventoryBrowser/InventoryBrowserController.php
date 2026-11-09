<?php

declare(strict_types=1);

namespace PII\Modules\InventoryBrowser;

use PII\Core\Database;
use PII\Modules\OrderReadiness\ListExporter;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * InventoryBrowserController — snapshot reads only.
 *
 * Column visibility is permission-driven (SPEC §2.1 applied per column):
 * a column the user may not see is neither queried, rendered, searched,
 * nor exported.
 */
class InventoryBrowserController
{
    /** Column key => [permission, heading, inv_items field] */
    private const COLUMNS = [
        'vendor_codes' => ['inventory.col_vendor_codes', 'Vendor codes', null],
        'on_hand'      => ['inventory.col_on_hand', 'On hand', 'qty_on_hand'],
        'on_so'        => ['inventory.col_on_so', 'On sales orders', 'qty_on_so'],
        'making'       => ['inventory.col_making', 'Being made', 'qty_making'],
        'consuming'    => ['inventory.col_consuming', 'Used in production', 'qty_consuming'],
        'on_po'        => ['inventory.col_on_po', 'On purchase orders', 'qty_on_po'],
    ];

    /** @return array<string,array{0:string,1:string,2:?string}> columns this user may see */
    private function visibleColumns(): array
    {
        return array_filter(self::COLUMNS, fn($col) => user_can($col[0]));
    }

    public function index(): void
    {
        $db      = Database::getInstance();
        $columns = $this->visibleColumns();
        $search  = trim((string) ($_GET['q'] ?? ''));
        $showAll = ($_GET['all'] ?? '') === '1';

        [$where, $params] = $this->buildFilter($search, $showAll, isset($columns['vendor_codes']));

        // Everything on one page — the whole list renders at once.
        $rows = $db->fetchAll("SELECT i.* FROM inv_items i {$where} ORDER BY i.item_code", $params);

        // Vendor codes (only when the column is permitted). The whole table
        // is small, so fetch it once and keep only the items being shown.
        $vendorCodes = [];
        if (isset($columns['vendor_codes']) && $rows !== []) {
            $shown = array_fill_keys(array_column($rows, 'item_code'), true);
            foreach ($db->fetchAll(
                "SELECT item_code, vendor, vendor_code, po_count, last_po
                 FROM inv_vendor_codes ORDER BY item_code, last_po DESC"
            ) as $v) {
                if (isset($shown[$v['item_code']])) {
                    $vendorCodes[$v['item_code']][] = $v;
                }
            }
        }

        layout('inventory::index', [
            'pageTitle'   => 'Inventory',
            'rows'        => $rows,
            'columns'     => $columns,
            'vendorCodes' => $vendorCodes,
            'search'      => $search,
            'showAll'     => $showAll,
            'total'       => count($rows),
            'asOf'        => SyncSettings::lastSuccessAt(),
        ], 'inventory');
    }

    public function export(): void
    {
        require_permission('export');
        $db      = Database::getInstance();
        $columns = $this->visibleColumns();
        $search  = trim((string) ($_GET['q'] ?? ''));
        $showAll = ($_GET['all'] ?? '') === '1';

        [$where, $params] = $this->buildFilter($search, $showAll, isset($columns['vendor_codes']));
        $rows = $db->fetchAll("SELECT i.* FROM inv_items i {$where} ORDER BY i.item_code LIMIT 20000", $params);

        $vendorByItem = [];
        if (isset($columns['vendor_codes'])) {
            foreach ($db->fetchAll("SELECT item_code, vendor, vendor_code FROM inv_vendor_codes ORDER BY item_code, last_po DESC") as $v) {
                $vendorByItem[$v['item_code']][] = $v['vendor'] . ': ' . $v['vendor_code'];
            }
        }

        $header = ['Item', 'Description', 'Unit'];
        foreach ($columns as $col) {
            $header[] = $col[1];
        }
        $out = [];
        foreach ($rows as $r) {
            $line = [$r['item_code'], $r['description'], $r['unit']];
            foreach ($columns as $key => $col) {
                $line[] = $key === 'vendor_codes'
                    ? implode("\n", $vendorByItem[$r['item_code']] ?? [])
                    : (float) $r[$col[2]];
            }
            $out[] = $line;
        }

        Database::getInstance()->insert('audit_log', [
            'user_id' => current_user_id(), 'entity_type' => 'inventory_browser',
            'entity_id' => null, 'action' => 'export',
            'details' => json_encode(['q' => $search, 'rows' => count($out)]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        ListExporter::stream('Inventory', $header, $out, ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx');
    }

    /**
     * WHERE clause: search matches item code / description — and vendor
     * codes only when that column is permitted (no leaking hidden data
     * through search). Without "show all", zero-activity items are hidden.
     *
     * @return array{0:string,1:array}
     */
    private function buildFilter(string $search, bool $showAll, bool $canSeeVendorCodes): array
    {
        $conds = [];
        $params = [];
        if ($search !== '') {
            $like = '%' . $search . '%';
            $sub = "(i.item_code LIKE ? OR i.description LIKE ?";
            $params = [$like, $like];
            if ($canSeeVendorCodes) {
                $sub .= " OR EXISTS (SELECT 1 FROM inv_vendor_codes vc
                          WHERE vc.item_code = i.item_code
                            AND (vc.vendor_code LIKE ? OR vc.vendor LIKE ?))";
                $params[] = $like;
                $params[] = $like;
            }
            $conds[] = $sub . ')';
        }
        if (!$showAll) {
            $conds[] = "(i.qty_on_hand <> 0 OR i.qty_on_so <> 0 OR i.qty_making <> 0 OR i.qty_consuming <> 0 OR i.qty_on_po <> 0)";
        }
        return [$conds === [] ? '' : 'WHERE ' . implode(' AND ', $conds), $params];
    }
}

<?php

declare(strict_types=1);

namespace PII\Modules\InventoryBrowser;

use PII\Services\Sync\SyncContext;

/**
 * InventoryBrowserSync — snapshot every real inventory item with its
 * activity quantities and purchase-history vendor codes.
 *
 * Sources (verified live, see docs/CMS_DATA_MAP.md):
 *   on hand    InventoryTotal.QtyUsable summed across owners
 *   on SO      open SH lines (IsOpen=1): QtyReqd − QtyUsed
 *   making     open MFBA/MFPP tickets' PK line output
 *   consuming  open tickets' UI lines: QtyReqd − QtyUsed (still to issue)
 *   on PO      open PO lines (IsOpen=1): QtyReqd − QtyUsed
 *   vendor codes  PurchaseOrderDetail.TheirCode per vendor (whole history)
 */
class InventoryBrowserSync
{
    private array $items = [];

    public function steps(): array
    {
        return [
            ['name' => 'Reading item inventory',           'run' => fn(SyncContext $c) => $this->readItems($c)],
            ['name' => 'Reading item activity (orders, production, purchasing)', 'run' => fn(SyncContext $c) => $this->readActivity($c)],
            ['name' => 'Reading vendor codes',              'run' => fn(SyncContext $c) => $this->readVendorCodes($c)],
        ];
    }

    private function readItems(SyncContext $c): array
    {
        $rows = $c->cmsFetchAll('items + stock on hand', "
            SELECT i.ItemCode, i.Description, i.Unit, i.Context AS ItemContext,
                   COALESCE(SUM(it.QtyUsable), 0) AS qty_on_hand
            FROM CMS.dbo.Item i
            LEFT JOIN CMS.dbo.InventoryTotal it ON it.Item = i.Item
            WHERE i.ReplacedBy IS NULL
            GROUP BY i.ItemCode, i.Description, i.Unit, i.Context");

        $this->items = [];
        foreach ($rows as $r) {
            $this->items[$r['ItemCode']] = [
                'item_code'     => $r['ItemCode'],
                'description'   => (string) ($r['Description'] ?? ''),
                'unit'          => (string) ($r['Unit'] ?? ''),
                'item_context'  => (string) ($r['ItemContext'] ?? ''),
                'qty_on_hand'   => (float) $r['qty_on_hand'],
                'qty_on_so'     => 0.0,
                'qty_making'    => 0.0,
                'qty_consuming' => 0.0,
                'qty_on_po'     => 0.0,
            ];
        }
        return ['rows' => count($this->items)];
    }

    private function readActivity(SyncContext $c): array
    {
        $apply = function (array $rows, string $field): int {
            foreach ($rows as $r) {
                if (isset($this->items[$r['ItemCode']])) {
                    $this->items[$r['ItemCode']][$field] = (float) $r['qty'];
                }
            }
            return count($rows);
        };

        $n = $apply($c->cmsFetchAll('open sales-order demand', "
            SELECT i.ItemCode, SUM(od.QtyReqd - COALESCE(od.QtyUsed, 0)) AS qty
            FROM CMS.dbo.OrdDetail od
            JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
            JOIN CMS.dbo.Item i ON i.Item = od.Item
            WHERE o.Context = 'SH' AND o.Status IN ('RTS','CSH')
              AND od.Context = 'SH' AND od.IsOpen = 1
            GROUP BY i.ItemCode"), 'qty_on_so');

        $n += $apply($c->cmsFetchAll('production output (PK lines)', "
            SELECT i.ItemCode, SUM(COALESCE(od.QtyReqd, 0)) AS qty
            FROM CMS.dbo.OrdDetail od
            JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
            JOIN CMS.dbo.Item i ON i.Item = od.Item
            WHERE o.Context IN ('MFBA','MFPP') AND o.Status NOT IN ('CMP','CLS')
              AND od.Context = 'PK'
            GROUP BY i.ItemCode"), 'qty_making');

        $n += $apply($c->cmsFetchAll('production consumption (UI lines)', "
            SELECT i.ItemCode,
                   SUM(CASE WHEN od.QtyReqd - COALESCE(od.QtyUsed, 0) > 0
                            THEN od.QtyReqd - COALESCE(od.QtyUsed, 0) ELSE 0 END) AS qty
            FROM CMS.dbo.OrdDetail od
            JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
            JOIN CMS.dbo.Item i ON i.Item = od.Item
            WHERE o.Context IN ('MFBA','MFPP') AND o.Status NOT IN ('CMP','CLS')
              AND od.Context = 'UI'
            GROUP BY i.ItemCode"), 'qty_consuming');

        $n += $apply($c->cmsFetchAll('open purchase orders', "
            SELECT i.ItemCode, SUM(od.QtyReqd - COALESCE(od.QtyUsed, 0)) AS qty
            FROM CMS.dbo.OrdDetail od
            JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
            JOIN CMS.dbo.Item i ON i.Item = od.Item
            WHERE o.Context = 'PO' AND (o.Status IS NULL OR o.Status NOT IN ('CMP','CLS'))
              AND od.Context = 'PO' AND od.IsOpen = 1
            GROUP BY i.ItemCode"), 'qty_on_po');

        $c->stage('inv_items', 'CREATE TABLE {table} LIKE inv_items');
        $c->insertRows('inv_items', array_values($this->items));
        $c->queueSwap('inv_items', min(100, count($this->items)));

        return ['rows' => $n];
    }

    private function readVendorCodes(SyncContext $c): array
    {
        $rows = $c->cmsFetchAll('vendor codes from purchase history', "
            SELECT pod.ItemCode, e.EntityCode AS vendor, pod.TheirCode AS vendor_code,
                   COUNT(*) AS po_count,
                   CONVERT(VARCHAR(10), MAX(o.DateOrdered), 23) AS last_po
            FROM CMS.dbo.PurchaseOrderDetail pod
            JOIN CMS.dbo.Ordr o ON o.Ordr = pod.Ordr
            LEFT JOIN CMS.dbo.Entities e ON e.Entity = o.Entity
            WHERE pod.TheirCode IS NOT NULL AND pod.TheirCode <> ''
            GROUP BY pod.ItemCode, e.EntityCode, pod.TheirCode");

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'item_code'   => (string) $r['ItemCode'],
                'vendor'      => (string) ($r['vendor'] ?? ''),
                'vendor_code' => (string) $r['vendor_code'],
                'po_count'    => (int) $r['po_count'],
                'last_po'     => $r['last_po'] ?: null,
            ];
        }

        $c->stage('inv_vendor_codes', 'CREATE TABLE {table} LIKE inv_vendor_codes');
        $c->insertRows('inv_vendor_codes', $out);
        $c->queueSwap('inv_vendor_codes', 0);

        return ['rows' => count($out)];
    }
}

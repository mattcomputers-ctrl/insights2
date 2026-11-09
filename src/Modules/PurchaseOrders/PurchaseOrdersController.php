<?php

declare(strict_types=1);

namespace PII\Modules\PurchaseOrders;

use PII\Core\CMSDatabase;
use PII\Core\CMSUnavailableException;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * PurchaseOrdersController — live, read-only CMS queries.
 * Open PO = Status blank/NULL (verified live); CMP/CLS = done.
 */
class PurchaseOrdersController
{
    private const MAX_ROWS = 200;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $tab = in_array($_GET['tab'] ?? '', ['open', 'completed', 'all'], true) ? $_GET['tab'] : 'open';
        $orders = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $where = match ($tab) {
                'open'      => "(o.Status IS NULL OR o.Status NOT IN ('CMP','CLS'))",
                'completed' => "o.Status IN ('CMP','CLS')",
                default     => '1=1',
            };
            $params = [];
            if ($q !== '') {
                $like = '%' . cms_like_escape($q) . '%';
                $where .= " AND (CAST(o.Ordr AS VARCHAR(20)) LIKE ? ESCAPE '!'
                    OR v.EntityCode LIKE ? ESCAPE '!' OR v.EntityName LIKE ? ESCAPE '!'
                    OR EXISTS (SELECT 1 FROM CMS.dbo.PurchaseOrderDetail pod
                               WHERE pod.Ordr = o.Ordr
                                 AND (pod.ItemCode LIKE ? ESCAPE '!' OR pod.TheirCode LIKE ? ESCAPE '!')))";
                $params = array_fill(0, 5, $like);
            }

            $orders = $cms->fetchAll("
                SELECT TOP " . (self::MAX_ROWS + 1) . "
                       o.Ordr, o.Status,
                       v.EntityCode AS Vendor, v.EntityName AS VendorName,
                       CONVERT(VARCHAR(10), o.DateOrdered, 23)  AS DateOrdered,
                       CONVERT(VARCHAR(10), o.DateRequired, 23) AS DateRequired,
                       ln.line_count, ln.amount, ln.open_lines
                FROM CMS.dbo.Ordr o
                LEFT JOIN CMS.dbo.Entities v ON v.Entity = o.Entity
                OUTER APPLY (
                    SELECT COUNT(*) AS line_count,
                           SUM(od.QtyReqd * COALESCE(od.Price, 0)) AS amount,
                           SUM(CASE WHEN od.IsOpen = 1 THEN 1 ELSE 0 END) AS open_lines
                    FROM CMS.dbo.OrdDetail od
                    WHERE od.Ordr = o.Ordr AND od.Context = 'PO'
                ) ln
                WHERE o.Context = 'PO' AND {$where}
                ORDER BY o.Ordr DESC", $params);
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        $truncated = count($orders) > self::MAX_ROWS;
        layout('purchase_orders::index', [
            'pageTitle' => 'Purchase Orders',
            'q'         => $q,
            'tab'       => $tab,
            'orders'    => array_slice($orders, 0, self::MAX_ROWS),
            'truncated' => $truncated,
            'error'     => $error,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'purchase_orders');
    }

    public function detail(string $ordr): void
    {
        $ordr = (int) $ordr;
        $head = null;
        $lines = [];
        $receipts = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $head = $cms->fetch("
                SELECT o.Ordr, o.Status, CONVERT(VARCHAR(2000), o.Comment) AS Comment, o.PlacedBy,
                       v.EntityCode AS Vendor, v.EntityName AS VendorName,
                       CONVERT(VARCHAR(10), o.DateOrdered, 23)  AS DateOrdered,
                       CONVERT(VARCHAR(10), o.DateRequired, 23) AS DateRequired
                FROM CMS.dbo.Ordr o
                LEFT JOIN CMS.dbo.Entities v ON v.Entity = o.Entity
                WHERE o.Ordr = ? AND o.Context = 'PO'", [$ordr]);

            if ($head) {
                $lines = $cms->fetchAll("
                    SELECT pod.OrdDetail, pod.ItemCode, pod.ItemDescription, pod.TheirCode,
                           pod.QtyReqd, pod.QtyReceived, pod.IsOpen, pod.Unit, pod.Price, pod.Value,
                           CONVERT(VARCHAR(10), pod.DatePromised, 23) AS DatePromised
                    FROM CMS.dbo.PurchaseOrderDetail pod
                    WHERE pod.Ordr = ?
                    ORDER BY pod.OrdDetail", [$ordr]);

                // Receipts: what actually arrived against each line (lot + date).
                $receipts = $cms->fetchAll("
                    SELECT csr.OrdDetail, CONVERT(VARCHAR(16), cs.ChangeDate, 120) AS ReceivedAt,
                           i.ItemCode, csr.PSQty, csr.PSUnit, csr.NumberOfContainers,
                           sl.SublotCode, lot.SupLot
                    FROM CMS.dbo.ChangeSetReceipt csr
                    JOIN CMS.dbo.OrdDetail od ON od.OrdDetail = csr.OrdDetail AND od.Ordr = ?
                    LEFT JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = csr.ChangeSet
                    LEFT JOIN CMS.dbo.Item i ON i.Item = csr.Item
                    LEFT JOIN CMS.dbo.Sublot sl ON sl.Sublot = csr.Sublot
                    LEFT JOIN CMS.dbo.Lot lot ON lot.Lot = sl.Lot
                    ORDER BY cs.ChangeDate, csr.ChangeSet", [$ordr]);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        if ($head === null && $error === null) {
            render_not_found();
        }

        layout('purchase_orders::detail', [
            'pageTitle' => 'PO ' . $ordr,
            'ordr'      => $ordr,
            'head'      => $head,
            'lines'     => $lines,
            'receipts'  => $receipts,
            'error'     => $error,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'purchase_orders');
    }
}

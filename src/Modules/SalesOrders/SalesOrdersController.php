<?php

declare(strict_types=1);

namespace PII\Modules\SalesOrders;

use PII\Core\CMSDatabase;
use PII\Core\CMSUnavailableException;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * SalesOrdersController — live, read-only CMS queries (Margin Watchdog
 * pattern: friendly pause during blackout/kill switch).
 */
class SalesOrdersController
{
    private const MAX_ROWS = 200;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $tab = in_array($_GET['tab'] ?? '', ['open', 'completed', 'all'], true) ? $_GET['tab'] : 'open';
        // Open orders sort by earliest required date by default; ordered-date
        // (oldest first) and newest-first are a click away (Matt, 2026-11-04).
        $sort = in_array($_GET['sort'] ?? '', ['required', 'ordered', 'newest'], true)
            ? $_GET['sort']
            : ($tab === 'open' ? 'required' : 'newest');
        $orders = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $where = match ($tab) {
                'open'      => "o.Status IN ('RTS','CSH')",
                'completed' => "o.Status IN ('CMP','CLS')",
                default     => '1=1',
            };
            $params = [];
            if ($q !== '') {
                $like = '%' . cms_like_escape($q) . '%';
                $where .= " AND (CAST(o.Ordr AS VARCHAR(20)) LIKE ? ESCAPE '!'
                    OR bt.EntityCode LIKE ? ESCAPE '!' OR bt.EntityName LIKE ? ESCAPE '!'
                    OR st.EntityName LIKE ? ESCAPE '!' OR o.PoNumber LIKE ? ESCAPE '!'
                    OR EXISTS (SELECT 1 FROM CMS.dbo.OrdDetail sod
                               LEFT JOIN CMS.dbo.Item si ON si.Item = sod.Item
                               LEFT JOIN CMS.dbo.Item sa ON sa.Item = sod.ItemName
                               WHERE sod.Ordr = o.Ordr AND sod.Context = 'SH'
                                 AND (si.ItemCode LIKE ? ESCAPE '!' OR sa.ItemCode LIKE ? ESCAPE '!')))";
                $params = array_fill(0, 7, $like);
            }

            $orders = $cms->fetchAll("
                SELECT TOP " . (self::MAX_ROWS + 1) . "
                       o.Ordr, o.Status, o.PoNumber,
                       bt.EntityCode AS BillTo, bt.EntityName AS BillToName,
                       st.EntityName AS ShipToName,
                       sv.EntityName AS ShipVia,
                       CONVERT(VARCHAR(10), o.DateOrdered, 23)  AS DateOrdered,
                       CONVERT(VARCHAR(10), o.DateRequired, 23) AS DateRequired,
                       CASE WHEN o.Status IN ('RTS','CSH') AND EXISTS (
                                SELECT 1 FROM CMS.dbo.OrdDetail bod
                                WHERE bod.Ordr = o.Ordr AND bod.Context = 'SH'
                                  AND COALESCE(bod.QtyUsed, 0) > 0)
                            THEN 1 ELSE 0 END AS IsBackorder
                FROM CMS.dbo.Ordr o
                LEFT JOIN CMS.dbo.Entities bt ON bt.Entity = o.BillTo
                LEFT JOIN CMS.dbo.Entities st ON st.Entity = o.ShipTo
                LEFT JOIN CMS.dbo.Entities sv ON sv.Entity = o.ShipVia
                WHERE o.Context = 'SH' AND {$where}
                ORDER BY " . ($tab === 'completed'
                    // Most recently SHIPPED first (DateCompleted = the final
                    // shipment) — order numbers alone bury old orders that
                    // shipped late (Matt, 2026-11-04).
                    ? 'o.DateCompleted DESC, o.Ordr DESC'
                    : match ($sort) {
                        'required' => 'CASE WHEN o.DateRequired IS NULL THEN 1 ELSE 0 END, o.DateRequired ASC, o.Ordr ASC',
                        'ordered'  => 'CASE WHEN o.DateOrdered IS NULL THEN 1 ELSE 0 END, o.DateOrdered ASC, o.Ordr ASC',
                        default    => 'o.Ordr DESC',
                    }), $params);

            $truncated = count($orders) > self::MAX_ROWS;
            $orders = array_slice($orders, 0, self::MAX_ROWS);

            // Last shipped date per order, one query for the page.
            if ($orders !== []) {
                $ids = array_column($orders, 'Ordr');
                $in = implode(',', array_fill(0, count($ids), '?'));
                $shippedAt = [];
                foreach ($cms->fetchAll("
                    SELECT sd.Ordr, CONVERT(VARCHAR(10), MAX(sd.DateShipped), 23) AS LastShipped
                    FROM CMS.dbo.ShipmentDetails sd
                    WHERE sd.Ordr IN ({$in})
                    GROUP BY sd.Ordr", $ids) as $r) {
                    $shippedAt[(int) $r['Ordr']] = $r['LastShipped'];
                }
                foreach ($orders as &$o) {
                    $o['LastShipped'] = $shippedAt[(int) $o['Ordr']] ?? null;
                }
                unset($o);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
            $truncated = false;
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
            $truncated = false;
        }

        layout('sales_orders::index', [
            'pageTitle' => 'Sales Orders',
            'q'         => $q,
            'tab'       => $tab,
            'sort'      => $sort,
            'orders'    => $orders,
            'truncated' => $truncated,
            'error'     => $error,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'sales_orders');
    }

    public function detail(string $ordr): void
    {
        $ordr = (int) $ordr;
        $head = null;
        $lines = [];
        $shipments = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $head = $cms->fetch("
                SELECT o.Ordr, o.Status, o.PoNumber, o.Currency, CONVERT(VARCHAR(2000), o.Comment) AS Comment,
                       bt.EntityCode AS BillTo, bt.EntityName AS BillToName,
                       st.EntityCode AS ShipTo, st.EntityName AS ShipToName,
                       sv.EntityName AS ShipVia,
                       sm.EntityName AS SalesmanName,
                       CASE WHEN o.Status IN ('RTS','CSH') AND EXISTS (
                                SELECT 1 FROM CMS.dbo.OrdDetail bod
                                WHERE bod.Ordr = o.Ordr AND bod.Context = 'SH'
                                  AND COALESCE(bod.QtyUsed, 0) > 0)
                            THEN 1 ELSE 0 END AS IsBackorder,
                       o.PlacedBy,
                       CONVERT(VARCHAR(10), o.DateOrdered, 23)    AS DateOrdered,
                       CONVERT(VARCHAR(10), o.DateRequired, 23)   AS DateRequired,
                       CONVERT(VARCHAR(10), o.DontShipBefore, 23) AS DontShipBefore
                FROM CMS.dbo.Ordr o
                LEFT JOIN CMS.dbo.Entities bt ON bt.Entity = o.BillTo
                LEFT JOIN CMS.dbo.Entities st ON st.Entity = o.ShipTo
                LEFT JOIN CMS.dbo.Entities sv ON sv.Entity = o.ShipVia
                LEFT JOIN CMS.dbo.Entities sm ON sm.Entity = o.Salesman
                WHERE o.Ordr = ? AND o.Context = 'SH'", [$ordr]);

            if ($head) {
                $lines = $cms->fetchAll("
                    SELECT od.OrdDetail, od.QtyReqd, od.QtyUsed, od.IsOpen, od.Price,
                           COALESCE(NULLIF(od.SupUnit, ''), i.Unit) AS Unit,
                           i.ItemCode, i.Description,
                           al.ItemCode AS AliasCode, al.Description AS AliasDescription
                    FROM CMS.dbo.OrdDetail od
                    LEFT JOIN CMS.dbo.Item i  ON i.Item = od.Item
                    LEFT JOIN CMS.dbo.Item al ON al.Item = od.ItemName
                    WHERE od.Ordr = ? AND od.Context = 'SH'
                    ORDER BY od.OrdDetail", [$ordr]);

                $shipments = $cms->fetchAll("
                    SELECT sd.TransDocument, sd.ChangeSet, sd.WayBill, sd.ItemCode, sd.ItemName,
                           sd.QtyShipped, CONVERT(VARCHAR(10), sd.DateShipped, 23) AS DateShipped
                    FROM CMS.dbo.ShipmentDetails sd
                    WHERE sd.Ordr = ?
                    ORDER BY sd.DateShipped, sd.ChangeSet", [$ordr]);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        if ($head === null && $error === null) {
            render_not_found();
        }

        layout('sales_orders::detail', [
            'pageTitle' => 'Order ' . $ordr,
            'ordr'      => $ordr,
            'head'      => $head,
            'lines'     => $lines,
            'shipments' => $shipments,
            'error'     => $error,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'sales_orders');
    }
}

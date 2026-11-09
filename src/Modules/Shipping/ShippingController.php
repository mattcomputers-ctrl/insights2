<?php

declare(strict_types=1);

namespace PII\Modules\Shipping;

use PII\Core\CMSDatabase;
use PII\Core\CMSUnavailableException;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * ShippingController — live, read-only CMS queries over waybills.
 *
 * Data map (verified live 2026-11-04): Waybill.ShipVia → Entities (the
 * carrier); WaybillTrackingNumber = one row per ASSEMBLY (an ASM
 * Location — the carton/pallet) with tracking number, freight cost and
 * actual weight; assembly CONTENTS come from the shipment movement lines
 * (ChangeSetShipment → InvMovement 'SH' → InvMovementDtl, negative qty,
 * detail Location = the assembly); orders/invoices via ShipmentDetails.
 */
class ShippingController
{
    private const MAX_ROWS = 150;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $waybills = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $where = '1=1';
            $params = [];
            if ($q !== '') {
                $like = '%' . cms_like_escape($q) . '%';
                $where .= " AND (CAST(w.Waybill AS VARCHAR(20)) LIKE ? ESCAPE '!'
                    OR e.EntityName LIKE ? ESCAPE '!'
                    OR EXISTS (SELECT 1 FROM CMS.dbo.WaybillTrackingNumber wt2
                               WHERE wt2.Waybill = w.Waybill AND wt2.TrackingNumber LIKE ? ESCAPE '!')
                    OR EXISTS (SELECT 1 FROM CMS.dbo.ShipmentDetails sd2
                               WHERE sd2.WayBill = w.Waybill
                                 AND (CAST(sd2.Ordr AS VARCHAR(20)) LIKE ? ESCAPE '!'
                                      OR sd2.ShipToName LIKE ? ESCAPE '!'
                                      OR sd2.ItemCode LIKE ? ESCAPE '!'
                                      OR sd2.ItemName LIKE ? ESCAPE '!')))";
                $params = array_fill(0, 7, $like);
            }

            $waybills = $cms->fetchAll("
                SELECT TOP " . (self::MAX_ROWS + 1) . "
                       w.Waybill, w.Status,
                       CONVERT(VARCHAR(16), w.DateShipped, 120) AS DateShipped,
                       e.EntityName AS ShipVia,
                       trk.n AS Assemblies, trk.weight AS WeightLb
                FROM CMS.dbo.Waybill w
                LEFT JOIN CMS.dbo.Entities e ON e.Entity = w.ShipVia
                OUTER APPLY (
                    SELECT COUNT(*) AS n, SUM(wt.ActWeightLb) AS weight
                    FROM CMS.dbo.WaybillTrackingNumber wt WHERE wt.Waybill = w.Waybill
                ) trk
                WHERE {$where}
                ORDER BY w.Waybill DESC", $params);

            $truncated = count($waybills) > self::MAX_ROWS;
            $waybills = array_slice($waybills, 0, self::MAX_ROWS);

            // Customers + orders per waybill, one query for the page.
            if ($waybills !== []) {
                $ids = array_column($waybills, 'Waybill');
                $in = implode(',', array_fill(0, count($ids), '?'));
                $agg = [];
                foreach ($cms->fetchAll("
                    SELECT sd.WayBill, sd.ShipToName, sd.Ordr
                    FROM CMS.dbo.ShipmentDetails sd
                    WHERE sd.WayBill IN ({$in})", $ids) as $r) {
                    $wb = (int) $r['WayBill'];
                    $agg[$wb]['customers'][(string) $r['ShipToName']] = true;
                    $agg[$wb]['orders'][(int) $r['Ordr']] = true;
                }
                foreach ($waybills as &$w) {
                    $a = $agg[(int) $w['Waybill']] ?? null;
                    $w['customers'] = $a !== null ? array_keys($a['customers']) : [];
                    $w['orders']    = $a !== null ? array_keys($a['orders']) : [];
                }
                unset($w);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
            $truncated = false;
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
            $truncated = false;
        }

        layout('shipping::index', [
            'pageTitle' => 'Shipping',
            'q'         => $q,
            'waybills'  => $waybills,
            'truncated' => $truncated,
            'error'     => $error,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'shipping');
    }

    public function detail(string $waybill): void
    {
        $waybill = (int) $waybill;
        $head = null;
        $assemblies = [];
        $shipments = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $head = $cms->fetch("
                SELECT w.Waybill, w.Status, w.PlacedBy, w.TrailerNumber, w.SealNumbers,
                       w.DeliveryConfirmation, w.PoNumber, w.FreightAccount,
                       CONVERT(VARCHAR(2000), w.Comment) AS Comment,
                       CONVERT(VARCHAR(16), w.DateShipped, 120) AS DateShipped,
                       e.EntityName AS ShipVia, fs.Description AS FreightService
                FROM CMS.dbo.Waybill w
                LEFT JOIN CMS.dbo.Entities e ON e.Entity = w.ShipVia
                LEFT JOIN CMS.dbo.FreightService fs ON fs.FreightService = w.FreightService
                WHERE w.Waybill = ?", [$waybill]);

            if ($head) {
                // One entry per assembly: tracking + weight…
                foreach ($cms->fetchAll("
                    SELECT l.LocationCode, wt.TrackingNumber, wt.ActWeightLb, wt.FreightCost
                    FROM CMS.dbo.WaybillTrackingNumber wt
                    LEFT JOIN CMS.dbo.Location l ON l.Location = wt.Location
                    WHERE wt.Waybill = ?
                    ORDER BY l.LocationCode", [$waybill]) as $r) {
                    $key = (string) ($r['LocationCode'] ?? '?');
                    $assemblies[$key] = [
                        'code'     => $key,
                        'tracking' => $r['TrackingNumber'] !== null ? (string) $r['TrackingNumber'] : null,
                        'weight'   => $r['ActWeightLb'] !== null ? (float) $r['ActWeightLb'] : null,
                        'cost'     => $r['FreightCost'] !== null ? (float) $r['FreightCost'] : null,
                        'contents' => [],
                    ];
                }
                // …and what was inside each one.
                foreach ($cms->fetchAll("
                    SELECT l.LocationCode, i.ItemCode, -imd.Qty AS Qty, o.Ordr,
                           al.ItemCode AS AliasCode
                    FROM CMS.dbo.ChangeSetShipment css
                    JOIN CMS.dbo.InvMovement im ON im.ChangeSet = css.ChangeSet AND im.Context = 'SH'
                    JOIN CMS.dbo.InvMovementDtl imd ON imd.InvMovement = im.InvMovement AND imd.Qty < 0
                    LEFT JOIN CMS.dbo.Location l ON l.Location = imd.Location
                    LEFT JOIN CMS.dbo.Item i ON i.Item = im.Item
                    LEFT JOIN CMS.dbo.OrdDetail od ON od.OrdDetail = imd.OrdDetail
                    LEFT JOIN CMS.dbo.Item al ON al.Item = od.ItemName
                    LEFT JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
                    WHERE css.Waybill = ?
                    ORDER BY l.LocationCode, i.ItemCode", [$waybill]) as $r) {
                    $key = (string) ($r['LocationCode'] ?? '?');
                    $assemblies[$key] ??= ['code' => $key, 'tracking' => null, 'weight' => null, 'cost' => null, 'contents' => []];
                    $assemblies[$key]['contents'][] = $r;
                }
                $assemblies = array_values($assemblies);

                $shipments = $cms->fetchAll("
                    SELECT sd.Ordr, sd.TransDocument, sd.ShipToName, sd.ItemCode, sd.ItemName,
                           sd.QtyShipped, sd.PoNumber
                    FROM CMS.dbo.ShipmentDetails sd
                    WHERE sd.WayBill = ?
                    ORDER BY sd.Ordr, sd.ItemCode", [$waybill]);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        if ($head === null && $error === null) {
            render_not_found();
        }

        layout('shipping::detail', [
            'pageTitle'  => 'Waybill ' . $waybill,
            'waybill'    => $waybill,
            'head'       => $head,
            'assemblies' => $assemblies,
            'shipments'  => $shipments,
            'error'      => $error,
            'asOf'       => SyncSettings::lastSuccessAt(),
        ], 'shipping');
    }
}

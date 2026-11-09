<?php

declare(strict_types=1);

namespace PII\Modules\BatchTickets;

use PII\Core\CMSDatabase;
use PII\Core\CMSUnavailableException;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * BatchTicketsController — live, read-only CMS queries over MFBA/MFPP
 * tickets, open and completed.
 *
 * A bulk ticket's page carries its packaging runs INSIDE it (Matt,
 * 2026-11-04): opening a packaging ticket redirects to its bulk. The
 * durable bulk↔pack link is OrdDetailCommit (the pack's bulk line commits
 * against the bulk's PK line — BatchSheetDemand only covers OPEN chains).
 * Actual yields: a pack's PK QtyUsed = pounds actually packed; the bulk's
 * actual = the positive inventory movements out of the batch vessel on
 * the lot it produced (verified live: 193608 → 1,622.6 lb vs 1,600 plan).
 */
class BatchTicketsController
{
    private const MAX_ROWS = 200;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $tab = in_array($_GET['tab'] ?? '', ['open', 'completed', 'all'], true) ? $_GET['tab'] : 'open';
        $tickets = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $where = match ($tab) {
                'open'      => "o.Status NOT IN ('CMP','CLS')",
                'completed' => "o.Status IN ('CMP','CLS')",
                default     => '1=1',
            };
            $params = [];
            if ($q !== '') {
                $like = '%' . cms_like_escape($q) . '%';
                // Matches the batch itself, its lots, or ANY of its packs
                // (pack ticket #, pack item) — packs have no rows of their own.
                $where .= " AND (CAST(o.Ordr AS VARCHAR(20)) LIKE ? ESCAPE '!'
                    OR i.ItemCode LIKE ? ESCAPE '!' OR i.Description LIKE ? ESCAPE '!'
                    OR EXISTS (SELECT 1 FROM CMS.dbo.Lot plot
                               JOIN CMS.dbo.OrdDetail pod ON pod.OrdDetail = plot.OrdDetail
                               WHERE pod.Ordr = o.Ordr AND CAST(plot.Lot AS VARCHAR(20)) LIKE ? ESCAPE '!')
                    OR EXISTS (SELECT 1 FROM CMS.dbo.OrdDetailCommit pc
                               JOIN CMS.dbo.OrdDetail psrc ON psrc.OrdDetail = pc.SrcOrdDetail AND psrc.Ordr = o.Ordr
                               JOIN CMS.dbo.OrdDetail pdem ON pdem.OrdDetail = pc.OrdDetail
                               JOIN CMS.dbo.OrdDetail ppk ON ppk.Ordr = pdem.Ordr AND ppk.Context = 'PK'
                               JOIN CMS.dbo.Item ppi ON ppi.Item = ppk.Item
                               WHERE CAST(pdem.Ordr AS VARCHAR(20)) LIKE ? ESCAPE '!'
                                  OR ppi.ItemCode LIKE ? ESCAPE '!'))";
                $params = array_fill(0, 6, $like);
            }

            // One row per BULK ticket (packs live inside it); packaging
            // tickets with no bulk link (orphans) still get their own row.
            $tickets = $cms->fetchAll("
                SELECT TOP " . (self::MAX_ROWS + 1) . "
                       o.Ordr, o.Context, o.Status,
                       i.ItemCode, i.Description,
                       COALESCE(od.QtyReqd, od.RepackagingYield) AS Qty, i.Unit,
                       CONVERT(VARCHAR(10), o.DateRequired, 23)  AS DateRequired,
                       CONVERT(VARCHAR(10), o.DateCompleted, 23) AS DateCompleted,
                       packs.n AS PackCount,
                       CASE WHEN EXISTS (SELECT 1 FROM CMS.dbo.OrdDetail ru
                                         WHERE ru.Ordr = o.Ordr AND ru.Context = 'UI'
                                           AND ru.IsRepackaging = 1) THEN 1 ELSE 0 END AS IsRepack
                FROM CMS.dbo.Ordr o
                JOIN CMS.dbo.OrdDetail od ON od.Ordr = o.Ordr AND od.Context = 'PK'
                JOIN CMS.dbo.Item i ON i.Item = od.Item
                OUTER APPLY (
                    SELECT COUNT(DISTINCT pdem.Ordr) AS n
                    FROM CMS.dbo.OrdDetailCommit pc
                    JOIN CMS.dbo.OrdDetail psrc ON psrc.OrdDetail = pc.SrcOrdDetail AND psrc.Ordr = o.Ordr
                    JOIN CMS.dbo.OrdDetail pdem ON pdem.OrdDetail = pc.OrdDetail
                ) packs
                WHERE {$where}
                  AND (o.Context = 'MFBA'
                       OR (o.Context = 'MFPP' AND NOT EXISTS (
                           SELECT 1 FROM CMS.dbo.OrdDetailCommit oc
                           JOIN CMS.dbo.OrdDetail dd ON dd.OrdDetail = oc.OrdDetail AND dd.Ordr = o.Ordr
                           JOIN CMS.dbo.OrdDetail ss ON ss.OrdDetail = oc.SrcOrdDetail
                           JOIN CMS.dbo.Ordr so ON so.Ordr = ss.Ordr AND so.Context = 'MFBA')))
                ORDER BY o.Ordr DESC", $params);
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        $truncated = count($tickets) > self::MAX_ROWS;
        layout('batch_tickets::index', [
            'pageTitle' => 'Batch Tickets',
            'q'         => $q,
            'tab'       => $tab,
            'tickets'   => array_slice($tickets, 0, self::MAX_ROWS),
            'truncated' => $truncated,
            'error'     => $error,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'batch_tickets');
    }

    public function detail(string $ordr): void
    {
        $ordr = (int) $ordr;
        $head = null;
        $lines = [];
        $packs = [];
        $lots = [];
        $qc = [];
        $bulkActual = null;
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();

            // A packaging ticket lives INSIDE its bulk ticket's page.
            $bulk = $cms->fetch("
                SELECT TOP 1 so.Ordr
                FROM CMS.dbo.OrdDetailCommit oc
                JOIN CMS.dbo.OrdDetail dd ON dd.OrdDetail = oc.OrdDetail AND dd.Ordr = ?
                JOIN CMS.dbo.OrdDetail ss ON ss.OrdDetail = oc.SrcOrdDetail
                JOIN CMS.dbo.Ordr so ON so.Ordr = ss.Ordr AND so.Context = 'MFBA'", [$ordr]);
            if ($bulk && (int) $bulk['Ordr'] !== $ordr) {
                redirect('/batch-tickets/' . (int) $bulk['Ordr']);
            }

            $head = $cms->fetch("
                SELECT o.Ordr, o.Context, o.Status, CONVERT(VARCHAR(2000), o.Comment) AS Comment,
                       i.ItemCode, i.Description,
                       COALESCE(od.QtyReqd, od.RepackagingYield) AS Qty,
                       od.QtyUsed AS QtyActual, i.Unit,
                       CONVERT(VARCHAR(10), o.DateRequired, 23)  AS DateRequired,
                       CONVERT(VARCHAR(10), o.DateReleased, 23)  AS DateReleased,
                       CONVERT(VARCHAR(10), o.DateScheduled, 23) AS DateScheduled,
                       CONVERT(VARCHAR(10), o.DateCompleted, 23) AS DateCompleted
                FROM CMS.dbo.Ordr o
                JOIN CMS.dbo.OrdDetail od ON od.Ordr = o.Ordr AND od.Context = 'PK'
                JOIN CMS.dbo.Item i ON i.Item = od.Item
                WHERE o.Ordr = ? AND o.Context IN ('MFBA','MFPP')", [$ordr]);

            if ($head) {
                // The batch sheet: ingredients + instructions in execution
                // order — required vs actually consumed (QtyUsed).
                $lines = $cms->fetchAll("
                    SELECT od.Context, od.QtyReqd, od.QtyUsed, od.[Percent], od.IsRepackaging,
                           od.Description AS InstrText,
                           COALESCE(NULLIF(od.SupUnit, ''), i.Unit) AS Unit,
                           i.ItemCode, i.Description AS ItemDescription
                    FROM CMS.dbo.OrdDetail od
                    LEFT JOIN CMS.dbo.Item i ON i.Item = od.Item
                    WHERE od.Ordr = ? AND od.Context IN ('UI','INSTR')
                    ORDER BY COALESCE(od.ExecOrder, od.Line, 999999), od.OrdDetail", [$ordr]);

                // A ticket with no recipe behind it is a REPACK: its one UI
                // line pulls the item itself from stock (IsRepackaging) and
                // the pack runs put it into the new pack size.
                $isRepack = (bool) array_filter($lines, fn($l) => (int) ($l['IsRepackaging'] ?? 0) === 1);

                // Packaging runs on this batch: expected vs packed pounds,
                // plus each run's own material lines (bulk + containers).
                $packHeads = $cms->fetchAll("
                    SELECT DISTINCT po.Ordr, po.Status, pi.ItemCode, pi.Description,
                           ppk.QtyReqd AS Expected, ppk.QtyUsed AS Packed, pi.Unit,
                           CONVERT(VARCHAR(10), po.DateRequired, 23)  AS DateRequired,
                           CONVERT(VARCHAR(10), po.DateCompleted, 23) AS DateCompleted
                    FROM CMS.dbo.OrdDetailCommit oc
                    JOIN CMS.dbo.OrdDetail src ON src.OrdDetail = oc.SrcOrdDetail AND src.Ordr = ?
                    JOIN CMS.dbo.OrdDetail dem ON dem.OrdDetail = oc.OrdDetail
                    JOIN CMS.dbo.Ordr po ON po.Ordr = dem.Ordr
                    JOIN CMS.dbo.OrdDetail ppk ON ppk.Ordr = po.Ordr AND ppk.Context = 'PK'
                    JOIN CMS.dbo.Item pi ON pi.Item = ppk.Item
                    ORDER BY po.Ordr", [$ordr]);

                foreach ($packHeads as $p) {
                    $p['materials'] = $cms->fetchAll("
                        SELECT od.QtyReqd, od.QtyUsed,
                               COALESCE(NULLIF(od.SupUnit, ''), i.Unit) AS Unit,
                               i.ItemCode, i.Description AS ItemDescription
                        FROM CMS.dbo.OrdDetail od
                        LEFT JOIN CMS.dbo.Item i ON i.Item = od.Item
                        WHERE od.Ordr = ? AND od.Context = 'UI'
                        ORDER BY COALESCE(od.ExecOrder, od.Line, 999999), od.OrdDetail", [(int) $p['Ordr']]);
                    $packs[] = $p;
                }

                // Lots produced by the batch AND its packs (+ QC on each).
                $allOrdrs = array_merge([$ordr], array_map(fn($p) => (int) $p['Ordr'], $packs));
                $in = implode(',', array_fill(0, count($allOrdrs), '?'));
                $lots = $cms->fetchAll("
                    SELECT lot.Lot, i.ItemCode, od.Ordr AS MadeOn,
                           CONVERT(VARCHAR(10), lot.ManfDate, 23) AS ManfDate
                    FROM CMS.dbo.Lot lot
                    JOIN CMS.dbo.OrdDetail od ON od.OrdDetail = lot.OrdDetail AND od.Context = 'PK'
                    LEFT JOIN CMS.dbo.Item i ON i.Item = lot.Item
                    WHERE od.Ordr IN ({$in})
                    ORDER BY lot.Lot", $allOrdrs);

                if ($lots !== []) {
                    $lotIds = array_column($lots, 'Lot');
                    $inL = implode(',', array_fill(0, count($lotIds), '?'));
                    $qc = $cms->fetchAll("
                        SELECT lot2.Lot, t.Description AS TestName,
                               CONVERT(VARCHAR(400), lst.Result) AS Result, lst.Passed,
                               CONVERT(VARCHAR(16), lst.TestedTime, 120) AS TestedTime,
                               u.DisplayName AS TestedBy, CONVERT(VARCHAR(1000), lst.Comment) AS Comment
                        FROM CMS.dbo.LocationSampleTest lst
                        JOIN CMS.dbo.Test t ON t.Test = lst.Test
                        JOIN CMS.dbo.Inventory inv ON inv.Location = lst.Location
                        JOIN CMS.dbo.Sublot sl ON sl.Sublot = inv.Sublot
                        JOIN CMS.dbo.Lot lot2 ON lot2.Lot = sl.Lot
                        LEFT JOIN CMS.dbo.[User] u ON u.[User] = lst.TestedBy
                        WHERE lot2.Lot IN ({$inL})
                        ORDER BY lst.TestedTime", $lotIds);

                    // Bulk actually made = what was pumped out of the batch
                    // vessel (positive movements on the bulk lot's sublots).
                    if ($head['Context'] === 'MFBA') {
                        $bulkLots = array_column(
                            array_filter($lots, fn($l) => (int) $l['MadeOn'] === $ordr), 'Lot');
                        if ($bulkLots !== []) {
                            $inB = implode(',', array_fill(0, count($bulkLots), '?'));
                            $row = $cms->fetch("
                                SELECT SUM(CASE WHEN imd.Qty > 0 THEN imd.Qty ELSE 0 END) AS made
                                FROM CMS.dbo.InvMovement im
                                JOIN CMS.dbo.InvMovementDtl imd ON imd.InvMovement = im.InvMovement
                                JOIN CMS.dbo.Sublot sl ON sl.Sublot = im.Sublot
                                WHERE sl.Lot IN ({$inB}) AND im.Context IN ('PCKAGE','CMNGL')", $bulkLots);
                            $bulkActual = $row !== null && $row['made'] !== null ? (float) $row['made'] : null;
                        }
                    }
                }
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        if ($head === null && $error === null) {
            render_not_found();
        }

        layout('batch_tickets::detail', [
            'pageTitle'  => 'Ticket ' . $ordr,
            'ordr'       => $ordr,
            'head'       => $head,
            'isRepack'   => $isRepack ?? false,
            'lines'      => $lines,
            'packs'      => $packs,
            'lots'       => $lots,
            'qc'         => $qc,
            'bulkActual' => $bulkActual,
            'error'      => $error,
            'asOf'       => SyncSettings::lastSuccessAt(),
        ], 'batch_tickets');
    }
}

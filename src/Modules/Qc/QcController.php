<?php

declare(strict_types=1);

namespace PII\Modules\Qc;

use PII\Core\CMSDatabase;
use PII\Core\CMSUnavailableException;
use PII\Core\Database;
use PII\Services\Settings;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * QcController — live, read-only CMS queries over test results.
 *
 * Chain (verified live 2026-11-04): LocationSampleTest → Location (the
 * sample container) → Inventory → Sublot → Lot → Lot.OrdDetail → the
 * batch ticket that made the lot, + Lot.Item for the item tested.
 * Specs come from ItemTest for that item + test.
 */
class QcController
{
    private const MAX_ROWS = 200;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $failedOnly = ($_GET['failed'] ?? '') === '1';
        $results = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $where = '1=1';
            $params = [];
            if ($failedOnly) {
                $where .= ' AND lst.Passed = 0';
            }
            if ($q !== '') {
                $like = '%' . cms_like_escape($q) . '%';
                $where .= " AND (i.ItemCode LIKE ? ESCAPE '!' OR i.Description LIKE ? ESCAPE '!'
                    OR CAST(lot.Lot AS VARCHAR(20)) LIKE ? ESCAPE '!'
                    OR CAST(o.Ordr AS VARCHAR(20)) LIKE ? ESCAPE '!'
                    OR t.Description LIKE ? ESCAPE '!' OR u.DisplayName LIKE ? ESCAPE '!')";
                $params = array_fill(0, 6, $like);
            }

            $results = $cms->fetchAll("
                SELECT TOP " . (self::MAX_ROWS + 1) . "
                       CONVERT(VARCHAR(16), lst.TestedTime, 120) AS TestedTime,
                       t.Description AS TestName, t.Unit AS TestUnit,
                       CONVERT(VARCHAR(400), lst.Result) AS Result, lst.Passed,
                       CONVERT(VARCHAR(1000), lst.Comment) AS Comment,
                       u.DisplayName AS TestedBy,
                       i.ItemCode, i.Description AS ItemDescription,
                       lot.Lot, o.Ordr AS Ticket, o.Context AS TicketCtx,
                       spec.Min AS SpecMin, spec.Target AS SpecTarget, spec.Max AS SpecMax
                FROM CMS.dbo.LocationSampleTest lst
                JOIN CMS.dbo.Test t ON t.Test = lst.Test
                LEFT JOIN CMS.dbo.[User] u ON u.[User] = lst.TestedBy
                LEFT JOIN CMS.dbo.Inventory inv ON inv.Location = lst.Location
                LEFT JOIN CMS.dbo.Sublot sl ON sl.Sublot = inv.Sublot
                LEFT JOIN CMS.dbo.Lot lot ON lot.Lot = sl.Lot
                LEFT JOIN CMS.dbo.Item i ON i.Item = lot.Item
                LEFT JOIN CMS.dbo.OrdDetail od ON od.OrdDetail = lot.OrdDetail
                LEFT JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
                OUTER APPLY (
                    SELECT TOP 1 it.Min, it.Max, it.Target
                    FROM CMS.dbo.ItemTest it
                    WHERE it.Item = lot.Item AND it.Test = lst.Test
                ) spec
                WHERE {$where}
                ORDER BY lst.TestedTime DESC", $params);
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        $truncated = count($results) > self::MAX_ROWS;
        layout('qc::index', [
            'pageTitle'  => 'QC Results',
            'q'          => $q,
            'failedOnly' => $failedOnly,
            'results'    => array_slice($results, 0, self::MAX_ROWS),
            'truncated'  => $truncated,
            'error'      => $error,
            'asOf'       => SyncSettings::lastSuccessAt(),
        ], 'qc');
    }

    /**
     * Certificate of Analysis by lot number (Matt, 2026-11-04): pick the
     * alias shown on the certificate (its description prints too), choose
     * PASS/FAIL-only or full detailed data. Logo + disclaimer come from
     * Admin → System settings. Printable.
     */
    public function coa(): void
    {
        require_permission('qc.coa');
        $lot   = trim((string) ($_GET['lot'] ?? ''));
        $alias = trim((string) ($_GET['alias'] ?? ''));
        $mode  = ($_GET['mode'] ?? 'detail') === 'summary' ? 'summary' : 'detail';

        $info = null;
        $aliases = [];
        $chosen = null;
        $results = [];
        $error = null;

        if ($lot !== '') {
            try {
                $cms = CMSDatabase::getInstance();
                $info = $cms->fetch("
                    SELECT lot.Lot, i.Item, i.ItemCode, i.Description, i.Unit,
                           CONVERT(VARCHAR(10), lot.ManfDate, 23)     AS ManfDate,
                           CONVERT(VARCHAR(10), lot.ReceivedDate, 23) AS ReceivedDate,
                           o.Ordr AS Ticket, o.Context AS TicketCtx
                    FROM CMS.dbo.Lot lot
                    JOIN CMS.dbo.Item i ON i.Item = lot.Item
                    LEFT JOIN CMS.dbo.OrdDetail od ON od.OrdDetail = lot.OrdDetail
                    LEFT JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
                    WHERE lot.Lot = ?", [(int) $lot]);

                if ($info !== null) {
                    // QC results live on the batch's MASTER lot (the bulk lot
                    // that ends up in the QC sample jar), not on pack lots.
                    // Whatever lot was entered — a customer's pack lot
                    // included — resolve the whole batch family and pull the
                    // results from it (Matt, 2026-11-04).
                    $family = [(int) $lot];
                    $bulkOrdr = null;
                    if (($info['TicketCtx'] ?? '') === 'MFBA') {
                        $bulkOrdr = (int) $info['Ticket'];
                    } elseif (($info['TicketCtx'] ?? '') === 'MFPP') {
                        $b = $cms->fetch("
                            SELECT TOP 1 so.Ordr
                            FROM CMS.dbo.OrdDetailCommit oc
                            JOIN CMS.dbo.OrdDetail dd ON dd.OrdDetail = oc.OrdDetail AND dd.Ordr = ?
                            JOIN CMS.dbo.OrdDetail ss ON ss.OrdDetail = oc.SrcOrdDetail
                            JOIN CMS.dbo.Ordr so ON so.Ordr = ss.Ordr AND so.Context = 'MFBA'",
                            [(int) $info['Ticket']]);
                        $bulkOrdr = $b !== null ? (int) $b['Ordr'] : null;
                    }
                    if ($bulkOrdr !== null) {
                        foreach ($cms->fetchAll("
                            SELECT lot2.Lot FROM CMS.dbo.Lot lot2
                            JOIN CMS.dbo.OrdDetail od2 ON od2.OrdDetail = lot2.OrdDetail
                            WHERE od2.Ordr = ? AND od2.Context = 'PK'", [$bulkOrdr]) as $r) {
                            $family[] = (int) $r['Lot'];
                        }
                    }
                    $family = array_values(array_unique($family));
                    $inF = implode(',', array_fill(0, count($family), '?'));

                    // Alias choices: ALL aliases across the bulk and every
                    // pack size of it, shown WITHOUT the pack extension
                    // (Matt, 2026-11-04) — e.g. UVXL010-82 offers UVXL010.
                    $bulkCode = (string) $info['ItemCode'];
                    $bulkDesc = (string) ($info['Description'] ?? '');
                    if ($bulkOrdr !== null && (int) $info['Ticket'] !== $bulkOrdr) {
                        $b = $cms->fetch("
                            SELECT i2.ItemCode, i2.Description
                            FROM CMS.dbo.OrdDetail od2
                            JOIN CMS.dbo.Item i2 ON i2.Item = od2.Item
                            WHERE od2.Ordr = ? AND od2.Context = 'PK'", [$bulkOrdr]);
                        if ($b !== null) {
                            $bulkCode = (string) $b['ItemCode'];
                            $bulkDesc = (string) ($b['Description'] ?? '');
                        }
                    }
                    // In case the entered lot's item IS a pack (e.g. a pack
                    // lot with no open chain), fall back to its code stem.
                    $stem = $bulkCode;

                    $byCode = [$stem => ['ItemCode' => $stem, 'Description' => $bulkDesc]];
                    foreach ($cms->fetchAll("
                        SELECT al.ItemCode AS AliasCode, al.Description AS AliasDescription,
                               inv.ItemCode AS ParentCode
                        FROM CMS.dbo.Item al
                        JOIN CMS.dbo.Item inv ON inv.Item = al.ReplacedBy
                        WHERE inv.ItemCode = ? OR inv.ItemCode LIKE ? ESCAPE '!'
                        ORDER BY al.ItemCode", [$stem, cms_like_escape($stem) . '-%']) as $r) {
                        $code = (string) $r['AliasCode'];
                        // Strip the parent pack's extension off the alias.
                        $ext = substr((string) $r['ParentCode'], strlen($stem));   // '' or '-82' etc.
                        if ($ext !== '' && str_ends_with($code, $ext)) {
                            $code = substr($code, 0, -strlen($ext));
                        }
                        $byCode[$code] ??= ['ItemCode' => $code, 'Description' => (string) ($r['AliasDescription'] ?? '')];
                    }
                    $aliases = array_values($byCode);
                    $chosen = $aliases[0];
                    foreach ($aliases as $a) {
                        if ($a['ItemCode'] === $alias) {
                            $chosen = $a;
                            break;
                        }
                    }

                    $results = $cms->fetchAll("
                        SELECT t.Description AS TestName, t.Unit AS TestUnit, t.Method,
                               CONVERT(VARCHAR(400), lst.Result) AS Result, lst.Passed,
                               CONVERT(VARCHAR(10), lst.TestedTime, 23) AS TestedDate,
                               u.DisplayName AS TestedBy,
                               spec.Min AS SpecMin, spec.Target AS SpecTarget, spec.Max AS SpecMax
                        FROM CMS.dbo.LocationSampleTest lst
                        JOIN CMS.dbo.Test t ON t.Test = lst.Test
                        LEFT JOIN CMS.dbo.[User] u ON u.[User] = lst.TestedBy
                        JOIN CMS.dbo.Inventory inv ON inv.Location = lst.Location
                        JOIN CMS.dbo.Sublot sl ON sl.Sublot = inv.Sublot
                        JOIN CMS.dbo.Lot flot ON flot.Lot = sl.Lot
                        OUTER APPLY (
                            SELECT TOP 1 it.Min, it.Max, it.Target
                            FROM CMS.dbo.ItemTest it
                            WHERE it.Item = flot.Item AND it.Test = lst.Test
                        ) spec
                        WHERE sl.Lot IN ({$inF})
                        ORDER BY lst.TestedTime", $family);

                    Database::getInstance()->insert('audit_log', [
                        'user_id' => current_user_id(), 'entity_type' => 'coa',
                        'entity_id' => $lot, 'action' => 'generate',
                        'details' => json_encode(['item' => $info['ItemCode'], 'shown_as' => $chosen['ItemCode'], 'mode' => $mode]),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);
                }
            } catch (CMSUnavailableException $e) {
                $error = $e->friendlyMessage();
            } catch (\Throwable $e) {
                $error = 'CMS query failed: ' . $e->getMessage();
            }
        }

        layout('qc::coa', [
            'pageTitle'  => 'Certificate of Analysis',
            'lot'        => $lot,
            'info'       => $info,
            'aliases'    => $aliases,
            'chosen'     => $chosen,
            'mode'       => $mode,
            'results'    => $results,
            'logo'       => (string) (Settings::get('coa.logo') ?? ''),
            'disclaimer' => trim((string) (Settings::get('coa.disclaimer') ?? '')),
            'error'      => $error,
            'asOf'       => SyncSettings::lastSuccessAt(),
        ], 'qc');
    }
}

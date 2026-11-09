<?php

declare(strict_types=1);

namespace PII\Services;

use PII\Core\CMSDatabase;
use PII\Core\Database;

/**
 * DashboardService — read-only metrics shown on the landing dashboard.
 *
 * Yesterday-anchored numbers (revenue, packed cost %, inventory snapshot)
 * because intra-day numbers are noisy and the inventory snapshot is only
 * stable as of the prior end-of-day. Same shipment filters as Margin
 * Watchdog (returns/voids excluded) so the figures reconcile.
 */
class DashboardService
{
    /**
     * Yesterday's revenue + packed cost, plus the 30-day daily average
     * (over the 30 days preceding yesterday) and the variance % of
     * yesterday vs that average.
     *
     * Pulls 31 daily rows (yesterday + 30 prior days) in one CMS query,
     * splits yesterday off, averages the rest. Days with no shipments
     * (weekends, holidays) drop out of the average naturally — we
     * average over the days that actually have data, not calendar days.
     * That avoids weekend-dilution making yesterday look artificially
     * "above average".
     *
     * @return array{
     *   date: string,
     *   revenue: float,
     *   cost: float,
     *   cost_pct: ?float,
     *   lines: int,
     *   revenue_avg_30d: ?float,
     *   revenue_variance_pct: ?float,
     *   cost_avg_30d: ?float,
     *   cost_variance_pct: ?float,
     *   avg_days_used: int
     * }
     */
    public function yesterdayShipments(): array
    {
        $y     = date('Y-m-d', strtotime('-1 day'));
        $start = date('Y-m-d', strtotime($y . ' -30 day'));

        // CONVERT(..., 23) forces ISO YYYY-MM-DD as a string, regardless of
        // dblib/sqlsrv default date formatting. Without this, dblib returns
        // dates like "May  6 2026 12:00:00:000AM" and the per-day comparison
        // against $y === 'YYYY-MM-DD' silently never matches.
        $sql = "
            SELECT
                CONVERT(VARCHAR(10), sd.DateShipped, 23)      AS d,
                COALESCE(SUM(sd.TotalAmount), 0)              AS revenue,
                COALESCE(SUM(sd.UnitCost * sd.QtyShipped), 0) AS cost,
                COUNT(*)                                      AS lines
            FROM CMS.dbo.ShipmentDetails sd
            LEFT JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = sd.ChangeSet
            LEFT JOIN CMS.dbo.[Trans]    t ON t.[Trans]    = cs.[Trans]
            LEFT JOIN (
                SELECT DISTINCT ReversedTrans FROM CMS.dbo.[Trans] WHERE ReversedTrans IS NOT NULL
            ) ro ON ro.ReversedTrans = t.[Trans]
            WHERE sd.QtyShipped > 0
              AND t.ReversedTrans IS NULL
              AND ro.ReversedTrans IS NULL
              AND sd.DateShipped >= ?
              AND sd.DateShipped < DATEADD(day, 1, ?)
            GROUP BY CONVERT(VARCHAR(10), sd.DateShipped, 23)
        ";
        $rows = CMSDatabase::getInstance()->fetchAll($sql, [$start, $y]);

        $yesterdayRev = 0.0; $yesterdayCost = 0.0; $yesterdayLines = 0;
        $avgRevSum    = 0.0; $avgCostSum    = 0.0; $avgDays = 0;

        foreach ($rows as $r) {
            $d    = (string) $r['d'];
            $rev  = (float)  ($r['revenue'] ?? 0);
            $cost = (float)  ($r['cost']    ?? 0);
            $ln   = (int)    ($r['lines']   ?? 0);

            if ($d === $y) {
                $yesterdayRev   = $rev;
                $yesterdayCost  = $cost;
                $yesterdayLines = $ln;
            } else {
                $avgRevSum  += $rev;
                $avgCostSum += $cost;
                $avgDays++;
            }
        }

        $revAvg  = $avgDays > 0 ? $avgRevSum  / $avgDays : null;
        $costAvg = $avgDays > 0 ? $avgCostSum / $avgDays : null;

        return [
            'date'                 => $y,
            'revenue'              => $yesterdayRev,
            'cost'                 => $yesterdayCost,
            'cost_pct'             => $yesterdayRev != 0.0 ? ($yesterdayCost / $yesterdayRev) * 100 : null,
            'lines'                => $yesterdayLines,
            'revenue_avg_30d'      => $revAvg,
            'revenue_variance_pct' => ($revAvg  !== null && $revAvg  != 0.0) ? (($yesterdayRev  - $revAvg)  / $revAvg)  * 100 : null,
            'cost_avg_30d'         => $costAvg,
            'cost_variance_pct'    => ($costAvg !== null && $costAvg != 0.0) ? (($yesterdayCost - $costAvg) / $costAvg) * 100 : null,
            'avg_days_used'        => $avgDays,
        ];
    }

    /**
     * Yesterday's inventory snapshot + 30-day-average comparison, read
     * from the local inventory_snapshots cache (populated nightly by
     * cron/snapshot-inventory.php). The CMS GetInventoryAtDate TVF takes
     * ~45 seconds per call so we never query it on a page load.
     *
     * Values are ActualValue (book value), matching what people see in
     * the CMS Inventory Cost Set Viewer.
     *
     * Returns null if no snapshot is available — caller can then show
     * a "snapshot not yet captured" message instead of fake zeros.
     *
     * @return array{
     *   date: string,
     *   total_value: float,
     *   total_qty: float,
     *   total_avg_30d: float|null,
     *   total_variance_pct: float|null,
     *   by_gl_group: list<array{
     *     gl_group: string,
     *     qty: float,
     *     value: float,
     *     pct_of_total: float,
     *     avg_30d: float|null,
     *     variance_pct: float|null,
     *   }>
     * }|null
     */
    public function yesterdayInventory(): ?array
    {
        $db = Database::getInstance();
        $y  = date('Y-m-d', strtotime('-1 day'));

        // Latest snapshot we actually have. Usually = yesterday, but
        // fall back to the most-recent date so weekend/holiday outages
        // don't leave the dashboard blank.
        $latestRow = $db->fetch(
            "SELECT MAX(snapshot_date) AS d FROM inventory_snapshots WHERE snapshot_date <= ?",
            [$y]
        );
        $latestDate = $latestRow['d'] ?? null;
        if (!$latestDate) {
            return null;  // Cache empty — nothing to show
        }

        $latest = $db->fetchAll(
            "SELECT gl_group, total_qty, total_actual_value
               FROM inventory_snapshots
              WHERE snapshot_date = ?
              ORDER BY total_actual_value DESC",
            [$latestDate]
        );

        // 30-day rolling average per GL group, ending the day before $latestDate
        // so the most-recent value isn't included in its own benchmark.
        $avgEnd   = date('Y-m-d', strtotime($latestDate . ' -1 day'));
        $avgStart = date('Y-m-d', strtotime($latestDate . ' -30 day'));
        $avgRows  = $db->fetchAll(
            "SELECT gl_group, AVG(total_actual_value) AS avg_value
               FROM inventory_snapshots
              WHERE snapshot_date BETWEEN ? AND ?
              GROUP BY gl_group",
            [$avgStart, $avgEnd]
        );
        $avgByGroup = [];
        foreach ($avgRows as $r) {
            $avgByGroup[$r['gl_group']] = (float) $r['avg_value'];
        }

        // 30-day average of total (sum across GL groups per day, then average those daily totals)
        $totalAvgRow = $db->fetch(
            "SELECT AVG(daily_total) AS avg_total
               FROM (
                   SELECT snapshot_date, SUM(total_actual_value) AS daily_total
                     FROM inventory_snapshots
                    WHERE snapshot_date BETWEEN ? AND ?
                    GROUP BY snapshot_date
               ) d",
            [$avgStart, $avgEnd]
        );
        $totalAvg30d = $totalAvgRow && $totalAvgRow['avg_total'] !== null
            ? (float) $totalAvgRow['avg_total']
            : null;

        // Roll up the latest snapshot
        $totalValue = 0.0;
        $totalQty   = 0.0;
        foreach ($latest as $r) {
            $totalValue += (float) $r['total_actual_value'];
            $totalQty   += (float) $r['total_qty'];
        }

        $byGroup = [];
        foreach ($latest as $r) {
            $g    = (string) $r['gl_group'];
            $val  = (float)  $r['total_actual_value'];
            $avg  = $avgByGroup[$g] ?? null;
            $byGroup[] = [
                'gl_group'     => $g,
                'qty'          => (float) $r['total_qty'],
                'value'        => $val,
                'pct_of_total' => $totalValue > 0 ? ($val / $totalValue) * 100 : 0,
                'avg_30d'      => $avg,
                'variance_pct' => ($avg !== null && $avg != 0.0) ? (($val - $avg) / $avg) * 100 : null,
            ];
        }

        return [
            'date'               => $latestDate,
            'total_value'        => $totalValue,
            'total_qty'          => $totalQty,
            'total_avg_30d'      => $totalAvg30d,
            'total_variance_pct' => ($totalAvg30d !== null && $totalAvg30d != 0.0)
                ? (($totalValue - $totalAvg30d) / $totalAvg30d) * 100
                : null,
            'by_gl_group'        => $byGroup,
        ];
    }
}

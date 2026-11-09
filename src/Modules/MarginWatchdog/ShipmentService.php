<?php

declare(strict_types=1);

namespace PII\Modules\MarginWatchdog;

use PII\Core\CMSDatabase;

/**
 * ShipmentService — All MSSQL queries for Margin Watchdog.
 *
 * Drives every Margin Watchdog query off CMS.dbo.ShipmentDetails:
 *   - DateShipped     ← invoice/ship date (Waybill.DateShipped if CMP, else ChangeSet.ChangeDate)
 *   - BillTo          ← Entities.EntityCode via Ordr.BillTo
 *   - ItemName        ← alias item code (Item.ItemCode via OrdDetail.ItemName)
 *   - QtyShipped      ← positive for outbound (view negates the inventory movement); negative = return/credit
 *   - UnitPrice       ← sales unit price
 *   - UnitCost        ← packed raw cost per unit at time of shipment (canonical)
 *   - TotalAmount     ← line revenue (use this directly; do NOT recompute)
 *   - OrderedUnit     ← per-line UoM
 *
 * Filtering rules (per business owner):
 *   - QtyShipped > 0  → returns/credits (negative-qty shipments) are EXCLUDED
 *   - Trans.ReversedTrans → voided shipments EXCLUDED. CMS reverses an
 *     invoice by inserting a second Trans row with ReversedTrans pointing
 *     to the original. Both rows of that pair must be excluded (the
 *     original AND the reversal) — see the LEFT JOIN to a derived table
 *     of reversed originals in each query.
 *   - Description for items comes from the alias item (Item.Description
 *     joined on ItemCode = ShipmentDetails.ItemName), NOT the inventory
 *     item description that the view exposes by default.
 *
 * Conditional aggregation packs both date ranges into one round trip
 * to MSSQL. SCHEMA_NOTES.md documents the underlying tables, the
 * date-field semantics, and the filtering rules above.
 */
class ShipmentService
{
    private CMSDatabase $db;

    /** @var array<string, mixed> Per-request memoisation. Keyed by (b_start, b_end, c_start, c_end). */
    private static array $cache = [];

    public function __construct()
    {
        $this->db = CMSDatabase::getInstance();
    }

    private function cacheKey(string $bStart, string $bEnd, string $cStart, string $cEnd): string
    {
        return "$bStart|$bEnd|$cStart|$cEnd";
    }

    /* ------------------------------------------------------------------
     *  Top-level company-wide summary (one row)
     * ----------------------------------------------------------------*/

    /**
     * Return the company-wide rollup for two date ranges.
     *
     * @return array{
     *     baseline_revenue: float,
     *     baseline_cost: float,
     *     baseline_qty: float,
     *     comparison_revenue: float,
     *     comparison_cost: float,
     *     comparison_qty: float,
     * }
     */
    public function summary(string $bStart, string $bEnd, string $cStart, string $cEnd): array
    {
        $key = 'summary:' . $this->cacheKey($bStart, $bEnd, $cStart, $cEnd);
        if (isset(self::$cache[$key])) return self::$cache[$key];

        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.TotalAmount ELSE 0 END), 0) AS baseline_revenue,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.UnitCost * sd.QtyShipped ELSE 0 END), 0) AS baseline_cost,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.QtyShipped ELSE 0 END), 0) AS baseline_qty,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.TotalAmount ELSE 0 END), 0) AS comparison_revenue,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.UnitCost * sd.QtyShipped ELSE 0 END), 0) AS comparison_cost,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.QtyShipped ELSE 0 END), 0) AS comparison_qty
            FROM CMS.dbo.ShipmentDetails sd
            LEFT JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = sd.ChangeSet
            LEFT JOIN CMS.dbo.[Trans]    t  ON t.[Trans]   = cs.[Trans]
            LEFT JOIN (
                SELECT DISTINCT ReversedTrans FROM CMS.dbo.[Trans] WHERE ReversedTrans IS NOT NULL
            ) reversed_originals ON reversed_originals.ReversedTrans = t.[Trans]
            WHERE sd.QtyShipped > 0
              AND t.ReversedTrans IS NULL                    -- not a reversal trans
              AND reversed_originals.ReversedTrans IS NULL   -- and not been reversed
              AND ((sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?))
                OR (sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?)))
        ";

        $params = [
            $bStart, $bEnd,   // baseline_revenue
            $bStart, $bEnd,   // baseline_cost
            $bStart, $bEnd,   // baseline_qty
            $cStart, $cEnd,   // comparison_revenue
            $cStart, $cEnd,   // comparison_cost
            $cStart, $cEnd,   // comparison_qty
            $bStart, $bEnd,   // outer WHERE baseline
            $cStart, $cEnd,   // outer WHERE comparison
        ];

        $row = $this->db->fetch($sql, $params) ?? [];

        $out = [
            'baseline_revenue'   => (float) ($row['baseline_revenue']   ?? 0),
            'baseline_cost'      => (float) ($row['baseline_cost']      ?? 0),
            'baseline_qty'       => (float) ($row['baseline_qty']       ?? 0),
            'comparison_revenue' => (float) ($row['comparison_revenue'] ?? 0),
            'comparison_cost'    => (float) ($row['comparison_cost']    ?? 0),
            'comparison_qty'     => (float) ($row['comparison_qty']     ?? 0),
        ];

        return self::$cache[$key] = $out;
    }

    /* ------------------------------------------------------------------
     *  Bill To breakdown (one row per Bill To with revenue in either period)
     * ----------------------------------------------------------------*/

    /**
     * Return per-Bill-To rollup for the two date ranges.
     *
     * Includes any Bill To with revenue in EITHER period.
     *
     * @param string $sort  'name' | 'baseline' | 'comparison'
     * @return list<array{
     *     bill_to: string,
     *     bill_to_name: string,
     *     baseline_revenue: float,
     *     baseline_cost: float,
     *     baseline_qty: float,
     *     comparison_revenue: float,
     *     comparison_cost: float,
     *     comparison_qty: float,
     * }>
     */
    public function billTos(string $bStart, string $bEnd, string $cStart, string $cEnd, string $sort = 'comparison'): array
    {
        $key = 'billtos:' . $this->cacheKey($bStart, $bEnd, $cStart, $cEnd);
        if (!isset(self::$cache[$key])) {
            $sql = "
                SELECT
                    sd.BillTo,
                    MAX(e.EntityName) AS BillToName,
                    COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.TotalAmount ELSE 0 END), 0) AS baseline_revenue,
                    COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.UnitCost * sd.QtyShipped ELSE 0 END), 0) AS baseline_cost,
                    COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.QtyShipped ELSE 0 END), 0) AS baseline_qty,
                    COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.TotalAmount ELSE 0 END), 0) AS comparison_revenue,
                    COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.UnitCost * sd.QtyShipped ELSE 0 END), 0) AS comparison_cost,
                    COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.QtyShipped ELSE 0 END), 0) AS comparison_qty
                FROM CMS.dbo.ShipmentDetails sd
                LEFT JOIN CMS.dbo.Entities e      ON e.EntityCode = sd.BillTo
                LEFT JOIN CMS.dbo.ChangeSet cs    ON cs.ChangeSet = sd.ChangeSet
                LEFT JOIN CMS.dbo.[Trans]    t    ON t.[Trans]   = cs.[Trans]
                LEFT JOIN (
                    SELECT DISTINCT ReversedTrans FROM CMS.dbo.[Trans] WHERE ReversedTrans IS NOT NULL
                ) reversed_originals ON reversed_originals.ReversedTrans = t.[Trans]
                WHERE sd.QtyShipped > 0
                  AND t.ReversedTrans IS NULL                  -- not a reversal trans
                  AND reversed_originals.ReversedTrans IS NULL -- and not been reversed
                  AND ((sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?))
                    OR (sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?)))
                GROUP BY sd.BillTo
                HAVING SUM(sd.TotalAmount) <> 0 OR SUM(sd.QtyShipped) <> 0
            ";

            $params = [
                $bStart, $bEnd, $bStart, $bEnd, $bStart, $bEnd,
                $cStart, $cEnd, $cStart, $cEnd, $cStart, $cEnd,
                $bStart, $bEnd, $cStart, $cEnd,
            ];

            $rows = $this->db->fetchAll($sql, $params);

            $normalized = [];
            foreach ($rows as $r) {
                $normalized[] = [
                    'bill_to'            => (string) $r['BillTo'],
                    'bill_to_name'       => (string) ($r['BillToName'] ?? ''),
                    'baseline_revenue'   => (float)  ($r['baseline_revenue']   ?? 0),
                    'baseline_cost'      => (float)  ($r['baseline_cost']      ?? 0),
                    'baseline_qty'       => (float)  ($r['baseline_qty']       ?? 0),
                    'comparison_revenue' => (float)  ($r['comparison_revenue'] ?? 0),
                    'comparison_cost'    => (float)  ($r['comparison_cost']    ?? 0),
                    'comparison_qty'     => (float)  ($r['comparison_qty']     ?? 0),
                ];
            }
            self::$cache[$key] = $normalized;
        }

        $rows = self::$cache[$key];

        // Apply sort
        switch ($sort) {
            case 'name':
                usort($rows, fn($a, $b) => strcasecmp(
                    $a['bill_to_name'] !== '' ? $a['bill_to_name'] : $a['bill_to'],
                    $b['bill_to_name'] !== '' ? $b['bill_to_name'] : $b['bill_to']
                ));
                break;
            case 'baseline':
                usort($rows, fn($a, $b) => $b['baseline_revenue'] <=> $a['baseline_revenue']);
                break;
            case 'comparison':
            default:
                usort($rows, fn($a, $b) => $b['comparison_revenue'] <=> $a['comparison_revenue']);
                break;
        }

        return $rows;
    }

    /* ------------------------------------------------------------------
     *  Item drill-down (one row per alias for a given Bill To)
     * ----------------------------------------------------------------*/

    /**
     * Return per-item rollup for a single Bill To and two date ranges.
     *
     * @param string $viewMode  'both' (only items in BOTH periods) | 'either' (items in EITHER period; missing period shown as zeros)
     * @return list<array{
     *     item_name: string,
     *     description: string,
     *     unit: string,
     *     unit_mixed: bool,
     *     replacement_cost: float|null,
     *     baseline_revenue: float,
     *     baseline_cost: float,
     *     baseline_qty: float,
     *     comparison_revenue: float,
     *     comparison_cost: float,
     *     comparison_qty: float,
     * }>
     */
    public function itemsForBillTo(
        string $billTo,
        string $bStart, string $bEnd,
        string $cStart, string $cEnd,
        string $viewMode = 'both'
    ): array {
        // Item join chain:
        //   alias = CMS.dbo.Item matched on ItemCode = sd.ItemName (the alias row).
        //   inv   = the resolved inventory item (alias.ReplacedBy → Item.Item;
        //           if alias has no ReplacedBy it IS the inventory item, so we
        //           ISNULL back to alias.Item).
        //
        // Why both:
        //   - alias.Description is the alias's own description (what executives see).
        //   - inv.ReplacementCost holds the today's packed replacement cost — CMS
        //     pre-computes (bulk + packaging) and stores the result on the
        //     packaged inventory item. Aliases always store NULL ReplacementCost,
        //     so the value MUST come from the inventory item.
        $sql = "
            SELECT
                sd.ItemName,
                MAX(alias.Description) AS Description,
                MIN(sd.OrderedUnit) AS Unit,
                COUNT(DISTINCT sd.OrderedUnit) AS unit_count,
                MAX(inv.ReplacementCost) AS ReplacementCost,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.TotalAmount ELSE 0 END), 0) AS baseline_revenue,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.UnitCost * sd.QtyShipped ELSE 0 END), 0) AS baseline_cost,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.QtyShipped ELSE 0 END), 0) AS baseline_qty,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.TotalAmount ELSE 0 END), 0) AS comparison_revenue,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.UnitCost * sd.QtyShipped ELSE 0 END), 0) AS comparison_cost,
                COALESCE(SUM(CASE WHEN sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?) THEN sd.QtyShipped ELSE 0 END), 0) AS comparison_qty
            FROM CMS.dbo.ShipmentDetails sd
            LEFT JOIN CMS.dbo.Item alias       ON alias.ItemCode = sd.ItemName
            LEFT JOIN CMS.dbo.Item inv         ON inv.Item       = ISNULL(alias.ReplacedBy, alias.Item)
            LEFT JOIN CMS.dbo.ChangeSet cs     ON cs.ChangeSet   = sd.ChangeSet
            LEFT JOIN CMS.dbo.[Trans]    t     ON t.[Trans]      = cs.[Trans]
            LEFT JOIN (
                SELECT DISTINCT ReversedTrans FROM CMS.dbo.[Trans] WHERE ReversedTrans IS NOT NULL
            ) reversed_originals              ON reversed_originals.ReversedTrans = t.[Trans]
            WHERE sd.BillTo = ?
              AND sd.QtyShipped > 0
              AND t.ReversedTrans IS NULL                    -- not a reversal trans
              AND reversed_originals.ReversedTrans IS NULL   -- and not been reversed
              AND ((sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?))
                OR (sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?)))
            GROUP BY sd.ItemName
            ORDER BY sd.ItemName
        ";

        $params = [
            $bStart, $bEnd, $bStart, $bEnd, $bStart, $bEnd,
            $cStart, $cEnd, $cStart, $cEnd, $cStart, $cEnd,
            $billTo,
            $bStart, $bEnd, $cStart, $cEnd,
        ];

        $rows = $this->db->fetchAll($sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $bRev = (float) ($r['baseline_revenue']   ?? 0);
            $cRev = (float) ($r['comparison_revenue'] ?? 0);
            $bQty = (float) ($r['baseline_qty']       ?? 0);
            $cQty = (float) ($r['comparison_qty']     ?? 0);

            // 'both' view requires non-zero activity in both periods
            $inBaseline   = ($bRev != 0.0) || ($bQty != 0.0);
            $inComparison = ($cRev != 0.0) || ($cQty != 0.0);

            if ($viewMode === 'both' && !($inBaseline && $inComparison)) {
                continue;
            }
            if ($viewMode !== 'both' && !$inBaseline && !$inComparison) {
                continue;
            }

            $repCost = $r['ReplacementCost'] ?? null;
            $out[] = [
                'item_name'          => (string) $r['ItemName'],
                'description'        => (string) ($r['Description'] ?? ''),
                'unit'               => (string) ($r['Unit'] ?? ''),
                'unit_mixed'         => ((int) ($r['unit_count'] ?? 1)) > 1,
                'replacement_cost'   => $repCost === null ? null : (float) $repCost,
                'baseline_revenue'   => $bRev,
                'baseline_cost'      => (float) ($r['baseline_cost']    ?? 0),
                'baseline_qty'       => $bQty,
                'comparison_revenue' => $cRev,
                'comparison_cost'    => (float) ($r['comparison_cost']  ?? 0),
                'comparison_qty'     => $cQty,
            ];
        }

        return $out;
    }
}

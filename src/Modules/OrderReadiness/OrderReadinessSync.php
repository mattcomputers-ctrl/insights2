<?php

declare(strict_types=1);

namespace PII\Modules\OrderReadiness;

use PII\Services\Settings;
use PII\Services\Sync\SyncContext;

/**
 * OrderReadinessSync — the module's sync job (SPEC §9), registered with
 * the shared engine via OrderReadinessModule::syncJobs().
 *
 * Six steps (plus the engine's own "Connecting to CMS"), each a handful
 * of set-based CMS queries filtered to open/current rows. Snapshot
 * tables are staged and swapped atomically by the engine; coverage and
 * suggestions are computed from the just-read arrays so one run is
 * internally consistent.
 *
 * Query shapes are documented and verified in docs/CMS_DATA_MAP.md.
 */
class OrderReadinessSync
{
    /** Shared item-set filter: anything on an open order/ticket or with a minimum. */
    private const RELEVANT_ITEMS = "(
        SELECT od.Item FROM CMS.dbo.OrdDetail od
        JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
        WHERE o.Context = 'SH' AND o.Status IN ('RTS','CSH')
          AND od.Context = 'SH' AND od.IsOpen = 1
        UNION
        SELECT od.Item FROM CMS.dbo.OrdDetail od
        JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
        WHERE o.Context IN ('MFBA','MFPP') AND o.Status NOT IN ('CMP','CLS')
          AND od.Context IN ('PK','UI')
        UNION
        SELECT ie.Item FROM CMS.dbo.ItemEntity ie
        WHERE ie.Context = 'ST' AND ie.MinimumStock IS NOT NULL
    )";

    private array $items = [];        // item_code => row
    private array $inventory = [];    // item_code => row
    private array $orders = [];       // ordr => row
    private array $lines = [];        // list
    private array $tickets = [];      // list
    private array $links = [];        // list
    private array $materials = [];    // ticket ordr => [item_code => ['qty','unit']]
    private array $ticketAllocated = []; // item => ticket output coverage gave to orders

    public function steps(): array
    {
        return [
            ['name' => 'Reading items & minimums',  'run' => fn(SyncContext $c) => $this->readItems($c)],
            ['name' => 'Reading inventory',          'run' => fn(SyncContext $c) => $this->readInventory($c)],
            ['name' => 'Reading open orders',        'run' => fn(SyncContext $c) => $this->readOrders($c)],
            ['name' => 'Reading batch tickets',      'run' => fn(SyncContext $c) => $this->readTickets($c)],
            ['name' => 'Checking order coverage',    'run' => fn(SyncContext $c) => $this->computeCoverage($c)],
            ['name' => 'Building production list',   'run' => fn(SyncContext $c) => $this->buildSuggestions($c)],
        ];
    }

    /* ── Step 1: items & minimums ──────────────────────────────── */

    private function readItems(SyncContext $c): array
    {
        $rows = $c->cmsFetchAll('items & minimums', "
            SELECT i.ItemCode, i.Description, i.Unit, i.Context AS ItemContext,
                   CASE WHEN i.CostingRecipe IS NULL THEN 0 ELSE 1 END AS has_recipe,
                   i.GLGroup,
                   r.RecipeNumber,
                   ie.MinimumStock
            FROM CMS.dbo.Item i
            LEFT JOIN CMS.dbo.Recipe r ON r.Recipe = i.CostingRecipe
            LEFT JOIN CMS.dbo.ItemEntity ie ON ie.Item = i.Item AND ie.Context = 'ST'
            WHERE i.Item IN " . self::RELEVANT_ITEMS);

        $this->items = [];
        foreach ($rows as $r) {
            $min = $r['MinimumStock'] !== null ? (float) $r['MinimumStock'] : null;
            $this->items[$r['ItemCode']] = [
                'item_code'      => $r['ItemCode'],
                'description'    => (string) ($r['Description'] ?? ''),
                'unit'           => (string) ($r['Unit'] ?? ''),
                'is_pack'        => ($r['ItemContext'] ?? '') === 'PP' ? 1 : 0,
                'has_recipe'     => (int) $r['has_recipe'],
                'recipe_number'  => $r['RecipeNumber'] !== null ? (string) $r['RecipeNumber'] : null,
                'minimum_stock'  => $min,
                'is_discontinued' => ($min !== null && abs($min - 99.0) < 1e-9) ? 1 : 0,
                // Wire fees, shipping charges etc.: never inventoried, so
                // they can never be "short" (Matt, 2026-11-04).
                'is_service'     => ($r['GLGroup'] ?? '') === 'Services' ? 1 : 0,
                'last_made'      => null,   // filled in step 6
            ];
        }
        return ['rows' => count($this->items)];
    }

    /* ── Step 2: inventory ─────────────────────────────────────── */

    private function readInventory(SyncContext $c): array
    {
        // Availability = QtyUsable summed across owners (DECISIONS #28).
        $rows = $c->cmsFetchAll('inventory totals', "
            SELECT i.ItemCode,
                   SUM(it.QtyUsable)   AS qty_usable,
                   SUM(it.QtySOH)      AS qty_soh,
                   SUM(it.QtyHold)     AS qty_hold,
                   SUM(it.QtyUnusable) AS qty_unusable
            FROM CMS.dbo.InventoryTotal it
            JOIN CMS.dbo.Item i ON i.Item = it.Item
            WHERE it.Item IN " . self::RELEVANT_ITEMS . "
            GROUP BY i.ItemCode");

        $this->inventory = [];
        foreach ($rows as $r) {
            $this->inventory[$r['ItemCode']] = [
                'item_code'    => $r['ItemCode'],
                'qty_usable'   => (float) $r['qty_usable'],
                'qty_soh'      => (float) $r['qty_soh'],
                'qty_hold'     => (float) $r['qty_hold'],
                'qty_unusable' => (float) $r['qty_unusable'],
            ];
        }
        return ['rows' => count($this->inventory)];
    }

    /* ── Step 3: open sales orders + lines + priority upkeep ───── */

    private function readOrders(SyncContext $c): array
    {
        $orders = $c->cmsFetchAll('open sales orders', "
            SELECT o.Ordr, o.Status,
                   bt.EntityCode AS BillTo,  bt.EntityName AS BillToName,
                   st.EntityCode AS ShipTo,  st.EntityName AS ShipToName,
                   o.PoNumber,
                   CONVERT(VARCHAR(10), o.DateOrdered, 23)     AS DateOrdered,
                   CONVERT(VARCHAR(10), o.DateRequired, 23)    AS DateRequired,
                   CONVERT(VARCHAR(10), o.DontShipBefore, 23)  AS DontShipBefore
            FROM CMS.dbo.Ordr o
            LEFT JOIN CMS.dbo.Entities bt ON bt.Entity = o.BillTo
            LEFT JOIN CMS.dbo.Entities st ON st.Entity = o.ShipTo
            WHERE o.Context = 'SH' AND o.Status IN ('RTS','CSH')");

        // Open orders with ANY material already shipped are backorders
        // (Matt, 2026-11-04).
        $backordered = [];
        foreach ($c->cmsFetchAll('partially shipped orders', "
            SELECT DISTINCT od.Ordr
            FROM CMS.dbo.OrdDetail od
            JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
            WHERE o.Context = 'SH' AND o.Status IN ('RTS','CSH')
              AND od.Context = 'SH' AND COALESCE(od.QtyUsed, 0) > 0") as $r) {
            $backordered[(int) $r['Ordr']] = true;
        }

        $this->orders = [];
        foreach ($orders as $r) {
            $this->orders[(int) $r['Ordr']] = [
                'ordr'             => (int) $r['Ordr'],
                'status'           => (string) $r['Status'],
                'bill_to'          => (string) ($r['BillTo'] ?? ''),
                'bill_to_name'     => (string) ($r['BillToName'] ?? ''),
                'ship_to'          => (string) ($r['ShipTo'] ?? ''),
                'ship_to_name'     => (string) ($r['ShipToName'] ?? ''),
                'po_number'        => $r['PoNumber'] !== null ? (string) $r['PoNumber'] : null,
                'date_ordered'     => $r['DateOrdered'] ?: null,
                'date_required'    => $r['DateRequired'] ?: null,
                'dont_ship_before' => $r['DontShipBefore'] ?: null,
                'is_backorder'     => isset($backordered[(int) $r['Ordr']]) ? 1 : 0,
            ];
        }

        $lines = $c->cmsFetchAll('open order lines', "
            SELECT od.OrdDetail, od.Ordr,
                   i.ItemCode, al.ItemCode AS AliasCode, al.Description AS AliasDescription,
                   od.QtyReqd, od.QtyUsed, od.QtyCommitted,
                   COALESCE(NULLIF(od.SupUnit, ''), i.Unit) AS Unit
            FROM CMS.dbo.OrdDetail od
            JOIN CMS.dbo.Ordr o   ON o.Ordr = od.Ordr
            LEFT JOIN CMS.dbo.Item i  ON i.Item = od.Item
            LEFT JOIN CMS.dbo.Item al ON al.Item = od.ItemName
            WHERE o.Context = 'SH' AND o.Status IN ('RTS','CSH')
              AND od.Context = 'SH' AND od.IsOpen = 1");

        $this->lines = [];
        foreach ($lines as $r) {
            $reqd = (float) ($r['QtyReqd'] ?? 0);
            $used = (float) ($r['QtyUsed'] ?? 0);
            $this->lines[] = [
                'ord_detail'        => (int) $r['OrdDetail'],
                'ordr'              => (int) $r['Ordr'],
                'item_code'         => (string) ($r['ItemCode'] ?? ''),
                'alias_code'        => $r['AliasCode'] !== null ? (string) $r['AliasCode'] : null,
                'alias_description' => $r['AliasDescription'] !== null ? (string) $r['AliasDescription'] : null,
                'qty_reqd'          => $reqd,
                'qty_used'          => $used,
                'qty_remaining'     => max(0.0, $reqd - $used),
                'qty_committed_cms' => (float) ($r['QtyCommitted'] ?? 0),
                'unit'              => (string) ($r['Unit'] ?? ''),
            ];
        }

        $this->upkeepPriorities($c);

        return ['rows' => count($this->orders) + count($this->lines)];
    }

    /**
     * Keep or_order_priority covering exactly the open orders: drop closed
     * ones, slot new ones in by DateRequired (then DateOrdered, then order
     * number) without disturbing manual drag positions, renumber 1..n.
     */
    private function upkeepPriorities(SyncContext $c): void
    {
        $db = $c->db();
        $existing = $db->fetchAll("SELECT ordr, position FROM or_order_priority ORDER BY position, ordr");

        $current = [];
        foreach ($existing as $row) {
            $ordr = (int) $row['ordr'];
            if (isset($this->orders[$ordr])) {
                $current[] = $ordr;
            }
        }

        $known = array_flip($current);
        $new = [];
        foreach ($this->orders as $ordr => $o) {
            if (!isset($known[$ordr])) {
                $new[] = $ordr;
            }
        }
        usort($new, fn($a, $b) =>
            [$this->orders[$a]['date_required'] ?? '9999', $this->orders[$a]['date_ordered'] ?? '9999', $a]
            <=> [$this->orders[$b]['date_required'] ?? '9999', $this->orders[$b]['date_ordered'] ?? '9999', $b]);

        foreach ($new as $ordr) {
            $dr = [$this->orders[$ordr]['date_required'] ?? '9999', $this->orders[$ordr]['date_ordered'] ?? '9999', $ordr];
            $at = count($current);
            foreach ($current as $i => $other) {
                $do = [$this->orders[$other]['date_required'] ?? '9999', $this->orders[$other]['date_ordered'] ?? '9999', $other];
                if ($dr < $do) {
                    $at = $i;
                    break;
                }
            }
            array_splice($current, $at, 0, [$ordr]);
        }

        $db->beginTransaction();
        try {
            $db->delete('or_order_priority', '1=1');
            foreach ($current as $i => $ordr) {
                $db->insert('or_order_priority', ['ordr' => $ordr, 'position' => $i + 1]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /* ── Step 4: batch tickets + chains ────────────────────────── */

    private function readTickets(SyncContext $c): array
    {
        // Open tickets with their PK (produced item) line. MFPP also carries
        // the bulk it consumes (the UI line whose item is a SUNDRY bulk).
        $rows = $c->cmsFetchAll('open batch tickets', "
            SELECT o.Ordr, o.Context, o.Status,
                   i.ItemCode, od.QtyReqd AS Qty, i.Unit,
                   CONVERT(VARCHAR(10), o.DateRequired, 23)  AS DateRequired,
                   CONVERT(VARCHAR(10), o.PlanStartDate, 23) AS PlanStart,
                   CONVERT(VARCHAR(10), o.DateReleased, 23)  AS DateReleased,
                   CONVERT(VARCHAR(10), o.DateScheduled, 23) AS DateScheduled,
                   blk.ItemCode AS BulkCode
            FROM CMS.dbo.Ordr o
            JOIN CMS.dbo.OrdDetail od ON od.Ordr = o.Ordr AND od.Context = 'PK'
            JOIN CMS.dbo.Item i       ON i.Item = od.Item
            OUTER APPLY (
                -- The bulk ink consumed, NOT a container: MFPP UI lines also
                -- carry pails/lids/drums (Context SUNDRY too, unit 'ea', no
                -- recipe — verified live 2026-10-29). Only bulk has a recipe.
                SELECT TOP 1 bi.ItemCode
                FROM CMS.dbo.OrdDetail ub
                JOIN CMS.dbo.Item bi ON bi.Item = ub.Item
                WHERE ub.Ordr = o.Ordr AND ub.Context = 'UI' AND bi.Context = 'SUNDRY'
                  AND bi.CostingRecipe IS NOT NULL
                ORDER BY bi.ItemCode
            ) blk
            WHERE o.Context IN ('MFBA','MFPP') AND o.Status NOT IN ('CMP','CLS')");

        // BAT-age tracking survives snapshot swaps: carry bat_since forward.
        $prevBat = [];
        try {
            foreach ($c->db()->fetchAll("SELECT ordr, bat_since FROM or_tickets WHERE bat_since IS NOT NULL") as $p) {
                $prevBat[(int) $p['ordr']] = $p['bat_since'];
            }
        } catch (\Throwable $e) {
            // table may not exist on very first run — fine
        }

        $this->tickets = [];
        foreach ($rows as $r) {
            $ordr = (int) $r['Ordr'];
            $this->tickets[] = [
                'ordr'           => $ordr,
                'context'        => (string) $r['Context'],
                'status'         => (string) $r['Status'],
                'item_code'      => (string) $r['ItemCode'],
                'qty'            => (float) $r['Qty'],
                'unit'           => (string) ($r['Unit'] ?? ''),
                'date_required'  => $r['DateRequired'] ?: null,
                'plan_start'     => $r['PlanStart'] ?: null,
                'date_released'  => $r['DateReleased'] ?: null,
                'date_scheduled' => $r['DateScheduled'] ?: null,
                'customer_hint'  => null,   // filled from links below
                'bulk_code'      => $r['BulkCode'] !== null ? (string) $r['BulkCode'] : null,
                'bat_since'      => $r['Status'] === 'BAT'
                    ? ($prevBat[$ordr] ?? date('Y-m-d H:i:s'))
                    : null,
            ];
        }

        $links = $c->cmsFetchAll('ticket chains', "
            SELECT bsd.Ordr AS SupplyOrdr, bsd.DemandOrdr, bsd.MakeItemCode,
                   bsd.QtyMake, bsd.CustomerCode
            FROM CMS.dbo.BatchSheetDemand bsd
            JOIN CMS.dbo.Ordr so ON so.Ordr = bsd.Ordr
            WHERE so.Status NOT IN ('CMP','CLS')");

        $this->links = [];
        $hints = [];
        $batchOf = [];   // MFPP packaging order → its MAIN batch (MFBA) order
        foreach ($links as $r) {
            $this->links[] = [
                'supply_ordr'    => (int) $r['SupplyOrdr'],
                'demand_ordr'    => (int) $r['DemandOrdr'],
                'make_item_code' => (string) ($r['MakeItemCode'] ?? ''),
                'qty_make'       => (float) ($r['QtyMake'] ?? 0),
                'customer_code'  => $r['CustomerCode'] !== null && $r['CustomerCode'] !== '' ? (string) $r['CustomerCode'] : null,
            ];
            $batchOf[(int) $r['DemandOrdr']] = (int) $r['SupplyOrdr'];
            if (!empty($r['CustomerCode'])) {
                $hints[(int) $r['SupplyOrdr']] = (string) $r['CustomerCode'];
                $hints[(int) $r['DemandOrdr']] = (string) $r['CustomerCode'];
            }
        }
        // What each open MFBA produces (authoritative bulk item per batch).
        $mfbaMakes = [];
        foreach ($this->tickets as $t) {
            if ($t['context'] === 'MFBA') {
                $mfbaMakes[$t['ordr']] = $t['item_code'];
            }
        }

        foreach ($this->tickets as &$t) {
            $t['customer_hint'] = $hints[$t['ordr']] ?? null;
            // People know the MAIN batch order number (the MFBA), never the
            // packaging order's internal row (Matt, 2026-10-29).
            $t['batch_ordr'] = $t['context'] === 'MFBA'
                ? $t['ordr']
                : ($batchOf[$t['ordr']] ?? null);
            // The surest bulk id is what the linked batch MAKES; the SQL
            // recipe-based pick is only the fallback for orphan MFPPs.
            if ($t['context'] === 'MFPP' && $t['batch_ordr'] !== null
                && isset($mfbaMakes[$t['batch_ordr']])) {
                $t['bulk_code'] = $mfbaMakes[$t['batch_ordr']];
            }
        }
        unset($t);

        // Material (UI) lines per ticket: what each batch consumes. CMS can
        // list the same material twice on one ticket, so sum per item.
        // On open tickets nothing is issued yet (QtyUsed is always 0/NULL —
        // verified live 2026-10-29), but subtract it anyway for safety.
        $mats = $c->cmsFetchAll('ticket materials', "
            SELECT od.Ordr, i.ItemCode,
                   SUM(COALESCE(od.QtyReqd, 0) - COALESCE(od.QtyUsed, 0)) AS Remaining,
                   MAX(COALESCE(NULLIF(od.SupUnit, ''), i.Unit)) AS Unit
            FROM CMS.dbo.OrdDetail od
            JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
            JOIN CMS.dbo.Item i ON i.Item = od.Item
            WHERE o.Context IN ('MFBA','MFPP') AND o.Status NOT IN ('CMP','CLS')
              AND od.Context = 'UI'
            GROUP BY od.Ordr, i.ItemCode");

        $this->materials = [];
        foreach ($mats as $r) {
            $code = (string) $r['ItemCode'];
            // Fee pseudo-items riding on tickets are not materials.
            if (!empty($this->items[$code]['is_service'])) {
                continue;
            }
            $this->materials[(int) $r['Ordr']][$code] = [
                'qty'  => max(0.0, (float) $r['Remaining']),
                'unit' => (string) ($r['Unit'] ?? ''),
            ];
        }

        return ['rows' => count($this->tickets) + count($this->links) + count($mats)];
    }

    /* ── Step 5: coverage (stage all snapshot tables + results) ── */

    private function computeCoverage(SyncContext $c): array
    {
        // Priority map for the engine.
        $positions = [];
        foreach ($c->db()->fetchAll("SELECT ordr, position FROM or_order_priority") as $p) {
            $positions[(int) $p['ordr']] = ['position' => (int) $p['position']];
        }

        // Future pack supply = open MFPP output; bulk tickets don't cover
        // pack orders directly (§14 Q6: nothing moves until BAT/CMP — and
        // even then it arrives as the MFPP completes).
        $packTickets = array_values(array_filter($this->tickets, fn($t) => $t['context'] === 'MFPP'));

        $inventoryQty = [];
        foreach ($this->inventory as $code => $inv) {
            $inventoryQty[$code] = $inv['qty_usable'];
        }

        $results = CoverageEngine::compute($positions, $this->lines, $this->items, $inventoryQty, $packTickets);

        // Stage everything read so far + the computed coverage.
        $c->stage('or_items',     'CREATE TABLE {table} LIKE or_items');
        $c->stage('or_inventory', 'CREATE TABLE {table} LIKE or_inventory');
        $c->stage('or_orders',    'CREATE TABLE {table} LIKE or_orders');
        $c->stage('or_order_lines', 'CREATE TABLE {table} LIKE or_order_lines');
        $c->stage('or_tickets',   'CREATE TABLE {table} LIKE or_tickets');
        $c->stage('or_ticket_links', 'CREATE TABLE {table} LIKE or_ticket_links');
        $c->stage('or_ticket_materials', 'CREATE TABLE {table} LIKE or_ticket_materials');
        $c->stage('or_ticket_readiness', 'CREATE TABLE {table} LIKE or_ticket_readiness');
        $c->stage('or_coverage_results', 'CREATE TABLE {table} LIKE or_coverage_results');

        $c->insertRows('or_items', array_values($this->items));
        $c->insertRows('or_inventory', array_values($this->inventory));
        $c->insertRows('or_orders', array_values($this->orders));
        $c->insertRows('or_order_lines', $this->lines);
        $c->insertRows('or_tickets', $this->tickets);
        $c->insertRows('or_ticket_links', $this->links);

        // Materials check (Matt, 2026-10-29): do we have what each open
        // ticket consumes? Same inventory availability as coverage.
        $matRows = [];
        foreach ($this->materials as $ordr => $byItem) {
            foreach ($byItem as $code => $m) {
                $matRows[] = ['ordr' => $ordr, 'item_code' => $code, 'qty_reqd' => $m['qty'], 'unit' => $m['unit']];
            }
        }
        $c->insertRows('or_ticket_materials', $matRows);

        $leftover = null;
        $readiness = TicketMaterialsEngine::compute($this->tickets, $this->materials, $inventoryQty, $leftover);
        $readyRows = [];
        foreach ($readiness as $ordr => $r) {
            $readyRows[] = [
                'ordr'       => $ordr,
                'status'     => $r['status'],
                'shortages'  => json_encode($r['shortages'], JSON_UNESCAPED_SLASHES),
                'from_mfba'  => $r['from_mfba'],
                'mfba_short' => $r['mfba_short'],
            ];
        }
        $c->insertRows('or_ticket_readiness', $readyRows);

        $covRows = [];
        $byDetail = [];
        foreach ($this->lines as $line) {
            $byDetail[$line['ord_detail']] = $line;
        }
        // Ticket output the coverage engine PROMISED to order lines — that
        // output is spoken for and must not be credited again when Need to
        // Order nets the remaining shortfalls.
        $this->ticketAllocated = [];
        foreach ($results as $detail => $res) {
            foreach ($res['allocations'] as $a) {
                if (($a['type'] ?? '') === 'ticket' && isset($byDetail[$detail])) {
                    $code = $byDetail[$detail]['item_code'];
                    $this->ticketAllocated[$code] = ($this->ticketAllocated[$code] ?? 0) + (float) $a['qty'];
                }
            }
        }
        foreach ($results as $detail => $res) {
            $covRows[] = [
                'ord_detail'  => $detail,
                'ordr'        => $byDetail[$detail]['ordr'] ?? 0,
                'status'      => $res['status'],
                'allocations' => json_encode($res['allocations'], JSON_UNESCAPED_SLASHES),
                'covered_qty' => $res['covered_qty'],
                'shortfall'   => $res['shortfall'],
                'reason'      => $res['reason'],
            ];
        }
        $c->insertRows('or_coverage_results', $covRows);

        // ── Hand-off to the Need to Order job (runs later this run) ──
        // What the open tickets are short of (buy to finish them)…
        $byOrdr = [];
        foreach ($this->tickets as $t) {
            $byOrdr[(int) $t['ordr']] = $t;
        }
        $openMfbaSet = [];
        foreach ($this->tickets as $t) {
            if ($t['context'] === 'MFBA') {
                $openMfbaSet[(int) $t['ordr']] = true;
            }
        }
        $ticketDemands = [];
        foreach ($readiness as $ordr => $r) {
            if ($r['status'] !== 'short') {
                continue;
            }
            $t = $byOrdr[$ordr] ?? null;
            foreach ($r['shortages'] as $s) {
                $ticketDemands[] = [
                    'item' => $s['item_code'],
                    'qty'  => (float) $s['short'],
                    'due'  => $t['date_required'] ?? null,
                    'ref'  => 'batch ' . (int) ($t['batch_ordr'] ?? $ordr) . ' (' . ($t['item_code'] ?? '?') . ')',
                    'src'  => 'ticket',
                ];
            }
        }
        // NST packs behind an open batch are skipped by the materials check
        // (normal waiting state), but their CONTAINERS are still needed to
        // complete the ticket — feed them to Need to Order, where they net
        // against the stock those packs never claimed (Matt, 2026-11-02).
        foreach ($this->tickets as $t) {
            if ($t['context'] !== 'MFPP' || $t['status'] !== 'NST'
                || !isset($openMfbaSet[(int) ($t['batch_ordr'] ?? 0)])) {
                continue;
            }
            foreach ($this->materials[(int) $t['ordr']] ?? [] as $code => $m) {
                if ($code === ($t['bulk_code'] ?? null) || $m['qty'] <= 0) {
                    continue;   // the bulk comes from the batch itself
                }
                $ticketDemands[] = [
                    'item' => $code,
                    'qty'  => $m['qty'],
                    'due'  => $t['date_required'] ?? null,
                    'ref'  => 'batch ' . (int) $t['batch_ordr'] . ' (' . $t['item_code'] . ')',
                    'src'  => 'ticket',
                ];
            }
        }
        // …and what new batches must be cut for open sales orders
        // (coverage shortfalls; discontinued items are never made again).
        $orderDemands = [];
        foreach ($results as $detail => $res) {
            if ($res['status'] !== 'short' || $res['shortfall'] <= 0
                || str_starts_with((string) $res['reason'], "Can't check")) {
                continue;
            }
            $line = $byDetail[$detail] ?? null;
            if ($line === null) {
                continue;
            }
            $orderDemands[] = [
                'item' => $line['item_code'],
                'qty'  => (float) $res['shortfall'],
                'due'  => $this->orders[$line['ordr']]['date_required'] ?? null,
                'ref'  => 'order ' . $line['ordr'] . ' (' . ($line['alias_code'] ?: $line['item_code']) . ')',
                'src'  => 'order',
            ];
        }
        // …and the FULL material requirements of every open ticket (not just
        // the short part): the min-stock view projects stock after completing
        // everything (Matt, 2026-11-02). A pack's bulk line supplied by its
        // own open batch is internal, not a requirement on stock.
        $openMfba = [];
        foreach ($this->tickets as $t) {
            if ($t['context'] === 'MFBA') {
                $openMfba[(int) $t['ordr']] = true;
            }
        }
        $ticketRequirements = [];
        foreach ($this->tickets as $t) {
            foreach ($this->materials[(int) $t['ordr']] ?? [] as $code => $m) {
                if ($t['context'] === 'MFPP' && $code === ($t['bulk_code'] ?? null)
                    && isset($openMfba[(int) ($t['batch_ordr'] ?? 0)])) {
                    continue;
                }
                if ($m['qty'] <= 0) {
                    continue;
                }
                $ticketRequirements[] = [
                    'item' => $code,
                    'qty'  => $m['qty'],
                    'due'  => $t['date_required'] ?? null,
                    'ref'  => 'batch ' . (int) ($t['batch_ordr'] ?? $t['ordr']) . ' (' . $t['item_code'] . ')',
                    'src'  => 'ticket',
                ];
            }
        }
        $itemInfo = [];
        foreach ($this->items as $code => $it) {
            $itemInfo[$code] = [
                'min'             => $it['minimum_stock'],
                'has_recipe'      => (int) $it['has_recipe'],
                'is_discontinued' => (int) $it['is_discontinued'],
            ];
        }

        $c->share('or.leftover_materials', $leftover ?? []);
        $c->share('or.usable', $inventoryQty);
        $c->share('or.item_info', $itemInfo);
        $c->share('or.ticket_demands', $ticketDemands);
        $c->share('or.ticket_requirements', $ticketRequirements);
        $c->share('or.order_demands', $orderDemands);

        // Swap validations: items/inventory should never be near-empty when
        // there are open orders; others may legitimately be empty.
        $c->queueSwap('or_items', min(50, count($this->items)));
        $c->queueSwap('or_inventory', 0);
        $c->queueSwap('or_orders', 0);
        $c->queueSwap('or_order_lines', 0);
        $c->queueSwap('or_tickets', 0);
        $c->queueSwap('or_ticket_links', 0);
        $c->queueSwap('or_ticket_materials', 0);
        $c->queueSwap('or_ticket_readiness', 0);
        $c->queueSwap('or_coverage_results', 0);

        return ['rows' => count($covRows)];
    }

    /* ── Step 6: production list + min-stock history + marks ───── */

    private function buildSuggestions(SyncContext $c): array
    {
        // Last-made dates (latest completed ticket producing the item).
        $lastMade = $c->cmsFetchAll('last-made dates', "
            SELECT i.ItemCode, CONVERT(VARCHAR(19), MAX(o.DateCompleted), 120) AS LastMade
            FROM CMS.dbo.Ordr o
            JOIN CMS.dbo.OrdDetail od ON od.Ordr = o.Ordr AND od.Context = 'PK'
            JOIN CMS.dbo.Item i       ON i.Item = od.Item
            WHERE o.Context IN ('MFBA','MFPP') AND o.Status = 'CMP'
              AND od.Item IN " . self::RELEVANT_ITEMS . "
            GROUP BY i.ItemCode");
        foreach ($lastMade as $r) {
            if (isset($this->items[$r['ItemCode']])) {
                $this->items[$r['ItemCode']]['last_made'] = $r['LastMade'] ?: null;
            }
        }
        // Refresh the staged or_items rows with last_made now that we have it.
        $c->stage('or_items', 'CREATE TABLE {table} LIKE or_items');
        $c->insertRows('or_items', array_values($this->items));

        // Demand / supply aggregates per item.
        $demand = [];
        foreach ($this->lines as $l) {
            $demand[$l['item_code']] = ($demand[$l['item_code']] ?? 0) + $l['qty_remaining'];
        }

        // Open-ticket supply per item (Matt, 2026-10-29: the production plan
        // must take open tickets into account):
        //   MFPP  → + pack output, − the bulk it will consume
        //   MFBA  → + bulk output
        // A ticket whose qty CMS hasn't filled in yet credits nothing, but
        // still flags the item as "already on an open ticket" (open_ticket).
        $supply = [];
        $openTicketRef = [];
        foreach ($this->tickets as $t) {
            $ref = $t['batch_ordr'] ?? $t['ordr'];
            if ($t['context'] === 'MFPP') {
                $supply[$t['item_code']] = ($supply[$t['item_code']] ?? 0) + $t['qty'];
                $openTicketRef[$t['item_code']] ??= $ref;
                if ($t['bulk_code'] !== null) {
                    $supply[$t['bulk_code']] = ($supply[$t['bulk_code']] ?? 0) - $t['qty'];
                }
            } else {   // MFBA
                $supply[$t['item_code']] = ($supply[$t['item_code']] ?? 0) + $t['qty'];
                $openTicketRef[$t['item_code']] ??= $ref;
            }
        }

        $sugInput = [];
        foreach ($this->items as $code => $it) {
            $sugInput[$code] = [
                'has_recipe'      => $it['has_recipe'],
                'is_discontinued' => $it['is_discontinued'],
                'minimum_stock'   => $it['minimum_stock'],
                'on_hand'         => $this->inventory[$code]['qty_usable'] ?? 0,
                'on_tickets'      => $supply[$code] ?? 0,
                'open_demand'     => $demand[$code] ?? 0,
                'recipe_number'   => $it['recipe_number'],
                'last_made'       => $it['last_made'],
                'open_ticket'     => $openTicketRef[$code] ?? null,
            ];
        }

        // Standard batch sizes: reuse the Scheduling module's config, keyed
        // by bulk item (§14 Q7) — resolve pack → bulk via the pack recipe
        // map already snapshotted on or_tickets, falling back to code stem.
        $batchByBulk = [];
        foreach ($c->db()->fetchAll("SELECT bulk_item_code, batch_size_1 FROM sched_item_config WHERE batch_size_1 > 0") as $r) {
            $batchByBulk[$r['bulk_item_code']] = (float) $r['batch_size_1'];
        }
        $bulkOfPack = [];
        foreach ($this->tickets as $t) {
            if ($t['context'] === 'MFPP' && $t['bulk_code'] !== null) {
                $bulkOfPack[$t['item_code']] = $t['bulk_code'];
            }
        }
        $batchSizes = [];
        foreach ($sugInput as $code => $_) {
            $bulk = $bulkOfPack[$code] ?? preg_replace('/-[^-]+$/', '', $code);
            if (isset($batchByBulk[$bulk])) {
                $batchSizes[$code] = $batchByBulk[$bulk];
            }
        }

        // Hand-off to Need to Order: net ticket supply per item (a new batch's
        // need is first met by batches already cut) + standard batch sizes.
        // Output the coverage engine already promised to order lines is
        // spoken for — subtract it, or shortfalls would be netted against
        // supply that is covering OTHER orders.
        $incoming = [];
        foreach ($supply as $code => $q) {
            $q -= $this->ticketAllocated[$code] ?? 0.0;
            if ($q > 0) {
                $incoming[$code] = $q;
            }
        }
        $c->share('or.ticket_supply', $incoming);
        $c->share('or.batch_sizes', $batchByBulk);

        $nearPct = (float) Settings::getInt('or.near_minimum_pct', 20);
        $suggestions = SuggestionEngine::compute($sugInput, $batchSizes, $nearPct);

        $c->stage('or_production_suggestions', 'CREATE TABLE {table} LIKE or_production_suggestions');
        $c->insertRows('or_production_suggestions', array_values($suggestions));
        $c->queueSwap('or_production_suggestions', 0);

        $this->recordMinStockHistory($c);
        $this->clearStaleMarks($c, $suggestions);

        return ['rows' => count($suggestions)];
    }

    /** §8.2: track minimum-stock changes (newly discontinued / reactivated). */
    private function recordMinStockHistory(SyncContext $c): void
    {
        $db = $c->db();
        $prev = [];
        try {
            foreach ($db->fetchAll("SELECT item_code, minimum_stock FROM or_items") as $p) {
                $prev[$p['item_code']] = $p['minimum_stock'] !== null ? (float) $p['minimum_stock'] : null;
            }
        } catch (\Throwable $e) {
            return;   // first run: no previous snapshot to diff against
        }
        if ($prev === []) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        foreach ($this->items as $code => $it) {
            if (!array_key_exists($code, $prev)) {
                continue;   // newly relevant item, not a change
            }
            $old = $prev[$code];
            $new = $it['minimum_stock'];
            $same = ($old === null && $new === null)
                || ($old !== null && $new !== null && abs($old - $new) < 1e-9);
            if (!$same) {
                $db->insert('or_item_min_stock_history', [
                    'item_code' => $code,
                    'old_value' => $old,
                    'new_value' => $new,
                    'seen_at'   => $now,
                ]);
            }
        }
    }

    /**
     * §8.3: marks clear automatically when a matching ticket appears
     * (scheduled) or the item recovers off the suggestion list entirely.
     */
    private function clearStaleMarks(SyncContext $c, array $suggestions): void
    {
        $db = $c->db();
        $openTicketItems = [];
        foreach ($this->tickets as $t) {
            $openTicketItems[$t['item_code']] = true;
            if ($t['bulk_code'] !== null) {
                $openTicketItems[$t['bulk_code']] = true;
            }
        }
        foreach ($db->fetchAll("SELECT item_code, mark FROM or_suggestion_marks") as $m) {
            $code = $m['item_code'];
            $clear = false;
            if (!isset($suggestions[$code])) {
                $clear = true;    // recovered above minimum
            } elseif ($m['mark'] === 'scheduled' && isset($openTicketItems[$code])) {
                $clear = true;    // the promised ticket now exists in CMS
            }
            if ($clear) {
                $db->delete('or_suggestion_marks', 'item_code = ?', [$code]);
            }
        }
    }
}

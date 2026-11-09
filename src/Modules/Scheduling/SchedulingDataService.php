<?php

declare(strict_types=1);

namespace PII\Modules\Scheduling;

use PII\Core\CMSDatabase;
use PII\Core\Database;

/**
 * SchedulingDataService — all CMS reads for the Scheduling module.
 *
 * Data sources (see SCHEMA_NOTES.md §Scheduling):
 *   - InventoryTotal (per Item, summed across owners):
 *       QtySOH        physical stock on hand
 *       QtyBooked     open customer order demand   (OrdDetail UI/SH/KD/UAI, IsOpen=1)
 *       QtyOnOrder    incoming supply — released production / POs (PK/PKA/PO, IsOpen=1)
 *       QtyOpenToSell (SOH − unusable) + OnOrder − Booked  → negative = can't cover orders
 *   - ItemEntity.MinimumStock with Context='ST'  → per-pack min stock level
 *   - Pack items: Item.Context = 'PP'
 *   - Pack → bulk: the pack's CostingRecipe UI line with Unit='lb' and the
 *     largest QtyReqd (≈1.0) — verified across sampled packs
 *   - Popularity: trailing POPULARITY_DAYS shipped lbs (ShipmentDetails,
 *     same returns/void filters as Margin Watchdog), aggregated to bulk
 */
class SchedulingDataService
{
    /** Popularity window (days of shipment history). */
    public const POPULARITY_DAYS = 91;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * CMS connection, opened on first use. The constructor must stay
     * CMS-free: pages that only read local config (the schedule page
     * shell, settings) must render even when CMS is down or no SQL
     * Server driver is installed.
     */
    private function cms(): CMSDatabase
    {
        return CMSDatabase::getInstance();
    }

    /**
     * Pack-level inventory position for every PP item that has stock,
     * orders, or a minimum stock level.
     *
     * @return list<array{pack:string, description:string, unit:string,
     *   min_stock:float, soh:float, booked:float, on_order:float, open_to_sell:float}>
     */
    public function packPositions(): array
    {
        $sql = "
            SELECT
                i.ItemCode                          AS pack,
                i.Description                       AS description,
                i.Unit                              AS unit,
                COALESCE(ms.MinimumStock, 0)        AS min_stock,
                COALESCE(SUM(it.QtySOH), 0)         AS soh,
                COALESCE(SUM(it.QtyBooked), 0)      AS booked,
                COALESCE(SUM(it.QtyOnOrder), 0)     AS on_order,
                COALESCE(SUM(it.QtyOpenToSell), 0)  AS open_to_sell
            FROM CMS.dbo.Item i
            LEFT JOIN (
                SELECT Item, MAX(MinimumStock) AS MinimumStock
                  FROM CMS.dbo.ItemEntity
                 WHERE Context = 'ST'
                   -- 99 marks an item targeted for DISCONTINUATION, not a
                   -- stocking target (Matt, 2026-11-04): never a trigger.
                   AND ABS(MinimumStock - 99) > 0.0001
                 GROUP BY Item
            ) ms ON ms.Item = i.Item
            LEFT JOIN CMS.dbo.InventoryTotal it ON it.Item = i.Item
            WHERE i.Context = 'PP'
            GROUP BY i.ItemCode, i.Description, i.Unit, ms.MinimumStock
            HAVING COALESCE(SUM(it.QtySOH), 0)     <> 0
                OR COALESCE(SUM(it.QtyBooked), 0)  <> 0
                OR COALESCE(SUM(it.QtyOnOrder), 0) <> 0
                OR COALESCE(ms.MinimumStock, 0)     > 0
        ";
        $rows = $this->cms()->fetchAll($sql);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'pack'         => (string) $r['pack'],
                'description'  => (string) ($r['description'] ?? ''),
                'unit'         => (string) ($r['unit'] ?? ''),
                'min_stock'    => (float)  ($r['min_stock'] ?? 0),
                'soh'          => (float)  ($r['soh'] ?? 0),
                'booked'       => (float)  ($r['booked'] ?? 0),
                'on_order'     => (float)  ($r['on_order'] ?? 0),
                'open_to_sell' => (float)  ($r['open_to_sell'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Pack → bulk item map for every PP item, via the pack recipe's main
     * lb-unit UI ingredient. Falls back to code-prefix (E1055-50 → E1055)
     * when the recipe is missing. Also returns bulk item descriptions.
     *
     * @return array{map: array<string,string>, descriptions: array<string,string>}
     */
    public function packToBulkMap(): array
    {
        // NOTE: 'bulk' is a reserved T-SQL keyword (BULK INSERT) — alias as b.
        $sql = "
            SELECT pack.ItemCode AS pack, b.ItemCode AS bulk_code, b.Description AS bulk_desc, rd.QtyReqd
              FROM CMS.dbo.Item pack
              JOIN CMS.dbo.Recipe r        ON r.Recipe  = pack.CostingRecipe
              JOIN CMS.dbo.RecipeDetail rd ON rd.Recipe = r.Recipe AND rd.Context = 'UI'
              JOIN CMS.dbo.Item b          ON b.Item = rd.Item
             WHERE pack.Context = 'PP'
               AND b.Unit = 'lb'
        ";
        $rows = $this->cms()->fetchAll($sql);

        // Keep the ingredient with the largest QtyReqd per pack (the base ink ≈ 1.0/lb)
        $best = [];
        $descriptions = [];
        foreach ($rows as $r) {
            $p = (string) $r['pack'];
            $q = (float)  $r['QtyReqd'];
            if (!isset($best[$p]) || $q > $best[$p]['qty']) {
                $best[$p] = ['bulk' => (string) $r['bulk_code'], 'qty' => $q];
            }
            $descriptions[(string) $r['bulk_code']] = (string) ($r['bulk_desc'] ?? '');
        }

        $map = [];
        foreach ($best as $pack => $b) {
            $map[$pack] = $b['bulk'];
        }
        return ['map' => $map, 'descriptions' => $descriptions];
    }

    /** Code-prefix fallback: E1055-50 → E1055. Returns null when no dash. */
    public static function bulkFromCode(string $packCode): ?string
    {
        $pos = strrpos($packCode, '-');
        return $pos !== false && $pos > 0 ? substr($packCode, 0, $pos) : null;
    }

    /**
     * Trailing shipped lbs per bulk item (popularity ranking input).
     * Resolves shipment alias → pack (ReplacedBy) → bulk (recipe map).
     *
     * @param array<string,string> $packToBulk
     * @return array<string, float>  bulk ItemCode → shipped lbs
     */
    public function popularityByBulk(array $packToBulk): array
    {
        $start = date('Y-m-d', strtotime('-' . self::POPULARITY_DAYS . ' days'));
        $sql = "
            SELECT
                ISNULL(inv.ItemCode, sd.ItemName) AS pack_code,
                COALESCE(SUM(sd.QtyShipped), 0)   AS shipped
            FROM CMS.dbo.ShipmentDetails sd
            LEFT JOIN CMS.dbo.Item alias ON alias.ItemCode = sd.ItemName
            LEFT JOIN CMS.dbo.Item inv   ON inv.Item = alias.ReplacedBy
            LEFT JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = sd.ChangeSet
            LEFT JOIN CMS.dbo.[Trans]    t ON t.[Trans]    = cs.[Trans]
            LEFT JOIN (
                SELECT DISTINCT ReversedTrans FROM CMS.dbo.[Trans] WHERE ReversedTrans IS NOT NULL
            ) ro ON ro.ReversedTrans = t.[Trans]
            WHERE sd.QtyShipped > 0
              AND t.ReversedTrans IS NULL
              AND ro.ReversedTrans IS NULL
              AND sd.DateShipped >= ?
            GROUP BY ISNULL(inv.ItemCode, sd.ItemName)
        ";
        $rows = $this->cms()->fetchAll($sql, [$start]);

        $byBulk = [];
        foreach ($rows as $r) {
            $pack = (string) $r['pack_code'];
            $bulk = $packToBulk[$pack] ?? self::bulkFromCode($pack) ?? $pack;
            $byBulk[$bulk] = ($byBulk[$bulk] ?? 0.0) + (float) $r['shipped'];
        }
        return $byBulk;
    }

    /**
     * Search CMS items for the item-config UI. Bulk ink items are
     * Context='SUNDRY' Unit='lb' but we search broadly and show context.
     */
    public function searchItems(string $q, int $limit = 20): array
    {
        $sql = "
            SELECT TOP {$limit} i.ItemCode, i.Description, i.Context, i.Unit
              FROM CMS.dbo.Item i
             WHERE i.ItemCode LIKE ? AND i.ReplacedBy IS NULL
             ORDER BY i.ItemCode
        ";
        return $this->cms()->fetchAll($sql, [$q . '%']);
    }

    /* ------------------------------------------------------------------
     *  Workload (v2) — what the mills actually have to do, from the
     *  Order Readiness snapshot (Matt, 2026-11-04)
     * ----------------------------------------------------------------*/

    /**
     * Demand units for ScheduleEngine::build():
     *   tier 0  open bulk tickets (RLS/NST — BAT is already milled),
     *           with materials-readiness and their real pack runs
     *   tier 1  order shortfalls on make-to-order items (no minimum)
     *   tier 2  below-minimum production-plan suggestions (negative/below)
     *   tier 3  near-minimum suggestions (capacity fill)
     * Suggestions marked scheduled/skip, discontinued items, service items
     * and items already riding an open ticket are excluded upstream or here.
     *
     * @param array<string,string> $packToBulk  pack → bulk (best effort)
     * @return list<array>
     */
    public function workload(array $packToBulk = []): array
    {
        $db = $this->db;
        $demands = [];

        // ── tier 0: open bulk tickets ──────────────────────────────────
        $readiness = [];
        try {
            foreach ($db->fetchAll("SELECT ordr, status FROM or_ticket_readiness") as $r) {
                $readiness[(int) $r['ordr']] = (string) $r['status'];
            }
        } catch (\Throwable $e) {
            // pre-migration snapshot — tickets just won't carry the flag
        }
        $packRuns = [];   // batch ordr => its MFPP pack runs
        foreach ($db->fetchAll(
            "SELECT t.batch_ordr, t.item_code, t.qty, i.description
             FROM or_tickets t LEFT JOIN or_items i ON i.item_code = t.item_code
             WHERE t.context = 'MFPP' AND t.batch_ordr IS NOT NULL"
        ) as $p) {
            $packRuns[(int) $p['batch_ordr']][] = [
                'pack' => (string) $p['item_code'],
                'description' => (string) ($p['description'] ?? ''),
                'lbs' => (float) $p['qty'],
            ];
        }
        foreach ($db->fetchAll(
            "SELECT t.ordr, t.item_code, t.qty, t.date_required, i.description
             FROM or_tickets t LEFT JOIN or_items i ON i.item_code = t.item_code
             WHERE t.context = 'MFBA' AND t.status IN ('RLS','NST')
             ORDER BY t.date_required, t.ordr"
        ) as $t) {
            $demands[] = [
                'bulk'   => (string) $t['item_code'],
                'lbs'    => (float) $t['qty'],
                'tier'   => 0,
                'source' => 'ticket',
                'ticket' => (int) $t['ordr'],
                'due'    => $t['date_required'] ?: null,
                'materials_short' => ($readiness[(int) $t['ordr']] ?? '') === 'short',
                'description' => (string) ($t['description'] ?? ''),
                'packs'  => $packRuns[(int) $t['ordr']] ?? [],
            ];
        }

        // ── tier 1: order shortfalls on make-to-order items ────────────
        // (items with a minimum are tier 2's job — their suggestions
        // already include open demand in the projection)
        $mto = [];
        foreach ($db->fetchAll(
            "SELECT l.item_code, SUM(c.shortfall) AS short_lbs,
                    MIN(o.date_required) AS due,
                    MAX(COALESCE(i.description, '')) AS description
             FROM or_coverage_results c
             JOIN or_order_lines l ON l.ord_detail = c.ord_detail
             LEFT JOIN or_orders o ON o.ordr = c.ordr
             LEFT JOIN or_items i ON i.item_code = l.item_code
             WHERE c.status = 'short' AND c.shortfall > 0
               AND (i.minimum_stock IS NULL)
               AND COALESCE(i.is_service, 0) = 0
             GROUP BY l.item_code"
        ) as $r) {
            $pack = (string) $r['item_code'];
            $bulk = $packToBulk[$pack] ?? self::bulkFromCode($pack) ?? $pack;
            $key  = $bulk;
            $mto[$key] ??= ['lbs' => 0.0, 'due' => null, 'packs' => []];
            $mto[$key]['lbs'] += (float) $r['short_lbs'];
            if ($r['due'] !== null && ($mto[$key]['due'] === null || $r['due'] < $mto[$key]['due'])) {
                $mto[$key]['due'] = $r['due'];
            }
            $mto[$key]['packs'][] = [
                'pack' => $pack, 'description' => (string) $r['description'],
                'lbs' => (float) $r['short_lbs'],
            ];
        }
        foreach ($mto as $bulk => $m) {
            $demands[] = [
                'bulk' => $bulk, 'lbs' => $m['lbs'], 'tier' => 1, 'source' => 'order',
                'ticket' => null, 'due' => $m['due'], 'materials_short' => false,
                'packs' => $m['packs'],
            ];
        }

        // ── tiers 2-3: production-plan suggestions ─────────────────────
        // suggested_qty already credits stock + open tickets − open demand,
        // is batch-rounded, and the sync already excluded discontinued
        // items. Items with an open ticket (open_ticket set) ride tier 0.
        foreach ($db->fetchAll(
            "SELECT s.item_code, s.severity, s.suggested_qty, s.open_ticket,
                    m.mark, m.skip_until, i.description
             FROM or_production_suggestions s
             LEFT JOIN or_suggestion_marks m ON m.item_code = s.item_code
             LEFT JOIN or_items i ON i.item_code = s.item_code
             WHERE s.suggested_qty > 0"
        ) as $s) {
            if ($s['open_ticket'] !== null) {
                continue;   // already cut — it's on the schedule as a ticket
            }
            if ($s['mark'] === 'scheduled') {
                continue;   // production says a ticket is coming
            }
            if ($s['mark'] === 'skip'
                && ($s['skip_until'] === null || $s['skip_until'] >= date('Y-m-d'))) {
                continue;
            }
            $item = (string) $s['item_code'];
            $bulk = $packToBulk[$item] ?? $item;   // suggestions are usually bulks already
            $demands[] = [
                'bulk'   => $bulk,
                'lbs'    => (float) $s['suggested_qty'],
                'tier'   => $s['severity'] === 'near' ? 3 : 2,
                'source' => $s['severity'] === 'near' ? 'near' : 'min',
                'ticket' => null, 'due' => null, 'materials_short' => false,
                'description' => (string) ($s['description'] ?? ''),
                'packs'  => [['pack' => $item, 'description' => (string) ($s['description'] ?? ''), 'lbs' => (float) $s['suggested_qty']]],
            ];
        }

        return $demands;
    }

    /* ------------------------------------------------------------------
     *  Local config (MySQL)
     * ----------------------------------------------------------------*/

    /** @return list<array<string,mixed>> active mills in sort order */
    public function mills(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return $this->db->fetchAll(
            "SELECT * FROM sched_mills {$where} ORDER BY sort_order, name"
        );
    }

    /** @return array<string, array{color:string,batch_size_1:float,batch_size_2:?float,sizes:list<float>,not_milled:bool}> keyed by bulk code */
    public function itemConfigs(): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT * FROM sched_item_config") as $r) {
            $sizes = [];
            for ($i = 1; $i <= 5; $i++) {
                $v = $r['batch_size_' . $i] ?? null;
                if ($v !== null && (float) $v > 0) {
                    $sizes[] = (float) $v;
                }
            }
            $out[(string) $r['bulk_item_code']] = [
                'color'        => (string) $r['color'],
                'batch_size_1' => (float)  $r['batch_size_1'],
                'batch_size_2' => $r['batch_size_2'] !== null ? (float) $r['batch_size_2'] : null,
                'sizes'        => $sizes,
                'not_milled'   => !empty($r['not_milled']),
            ];
        }
        return $out;
    }

    /** Global default batch sizes (lbs, up to 5) for items with no config. */
    public function defaultBatchSizes(): array
    {
        $raw = (string) ($this->db->fetch(
            "SELECT `value` FROM settings WHERE `key` = 'sched.default_batch_sizes'"
        )['value'] ?? '');
        $sizes = [];
        foreach (explode(',', $raw) as $p) {
            $v = (float) trim($p);
            if ($v > 0) {
                $sizes[] = $v;
            }
        }
        return array_slice($sizes, 0, 5);
    }

    public function saveDefaultBatchSizes(array $sizes): void
    {
        $sizes = array_values(array_filter(array_map('floatval', $sizes), fn($v) => $v > 0));
        rsort($sizes);
        \PII\Services\Settings::set('sched.default_batch_sizes', implode(',', array_slice($sizes, 0, 5)));
    }

    /**
     * Batch sizes this item has ACTUALLY been made at (Matt, 2026-11-04):
     * the most common planned quantities on its past bulk tickets, most
     * frequent first — the starting point when configuring an item, and
     * the recommendation for already-configured ones (Matt, 2026-11-05).
     *
     * @return list<array{lbs: float, n: int}> up to 5, most frequent first
     */
    public function historicalBatchSizes(string $bulkCode): array
    {
        $rows = $this->cms()->fetchAll("
            SELECT TOP 5 ROUND(od.QtyReqd, 0) AS lbs, COUNT(*) AS n
            FROM CMS.dbo.Ordr o
            JOIN CMS.dbo.OrdDetail od ON od.Ordr = o.Ordr AND od.Context = 'PK'
            JOIN CMS.dbo.Item i ON i.Item = od.Item
            WHERE o.Context = 'MFBA' AND i.ItemCode = ? AND od.QtyReqd > 0
            GROUP BY ROUND(od.QtyReqd, 0)
            ORDER BY COUNT(*) DESC, ROUND(od.QtyReqd, 0) DESC", [$bulkCode]);
        $out = [];
        foreach ($rows as $r) {
            if ((float) $r['lbs'] > 0) {
                $out[] = ['lbs' => (float) $r['lbs'], 'n' => (int) $r['n']];
            }
        }
        return $out;
    }

    /**
     * Alias-prefix color map (Matt, 2026-11-04): customer alias codes lead
     * with a color letter — B2300A is a blue, R…, P… purple/violet, etc.
     */
    public const ALIAS_COLOR_PREFIXES = [
        'B' => 'blue',
        'R' => 'red',
        'P' => 'violet',
        'G' => 'green',
        'Y' => 'yellow',
        'O' => 'orange',
        'W' => 'opaque white',
        'K' => 'black',
        'X' => 'black',
        'N' => 'brown',
    ];

    /**
     * Guess ladder colors from the alias prefixes of each bulk's packs:
     * majority vote across the leading letter of every alias (a letter
     * followed by a digit — UV…, E… and other non-color codes don't vote).
     *
     * @param list<string> $bulkCodes
     * @return array<string,string> bulk → guessed ladder color
     */
    public function guessColorsFromAliases(array $bulkCodes): array
    {
        if ($bulkCodes === []) {
            return [];
        }
        $votes = [];
        foreach (array_chunk($bulkCodes, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            // Aliases of the bulk itself and of every pack whose code
            // starts with "<bulk>-".
            $rows = $this->cms()->fetchAll("
                SELECT b.ItemCode AS bulk_code, al.ItemCode AS alias_code
                FROM CMS.dbo.Item b
                JOIN CMS.dbo.Item inv ON inv.ReplacedBy IS NULL
                     AND (inv.Item = b.Item OR inv.ItemCode LIKE b.ItemCode + '-%')
                JOIN CMS.dbo.Item al ON al.ReplacedBy = inv.Item
                WHERE b.ItemCode IN ({$ph})", $chunk);
            foreach ($rows as $r) {
                $alias = strtoupper((string) $r['alias_code']);
                if (!preg_match('/^([A-Z])[0-9]/', $alias, $m)) {
                    continue;   // UVXL010 etc. — not a color-letter alias
                }
                $color = self::ALIAS_COLOR_PREFIXES[$m[1]] ?? null;
                if ($color !== null) {
                    $votes[(string) $r['bulk_code']][$color] = ($votes[(string) $r['bulk_code']][$color] ?? 0) + 1;
                }
            }
        }
        $out = [];
        foreach ($votes as $bulk => $v) {
            arsort($v);
            $out[$bulk] = (string) array_key_first($v);
        }
        return $out;
    }

    /**
     * Description color words (Matt, 2026-11-05): most item descriptions
     * name the color outright ("…HEPTA 7/D BLACK", "PROCESS YELLOW").
     * Priority order — first phrase found in the description wins, so
     * "OPAQUE WHITE" beats "WHITE" and "WARM RED" beats "RED".
     * Mappings for ink terms: CYAN → blue, MAGENTA → red (rubine family),
     * GRAY → black, VARNISH/CLEAR/EXTENDER → extender.
     */
    public const DESCRIPTION_COLOR_PHRASES = [
        'OPAQUE WHITE' => 'opaque white',
        'WARM RED'     => 'warm red',
        'REFLEX BLUE'  => 'reflex blue',
        'MAGENTA'      => 'red',
        'CYAN'         => 'blue',
        'VIOLET'       => 'violet',
        'PURPLE'       => 'violet',
        'YELLOW'       => 'yellow',
        'ORANGE'       => 'orange',
        'GREEN'        => 'green',
        'BROWN'        => 'brown',
        'BLACK'        => 'black',
        'GRAY'         => 'black',
        'GREY'         => 'black',
        'WHITE'        => 'opaque white',
        'RED'          => 'red',
        'BLUE'         => 'blue',
        'EXTENDER'     => 'extender',
        'VARNISH'      => 'extender',
        'CLEAR'        => 'extender',
    ];

    /**
     * Guess ladder colors from the bulk items' own descriptions.
     * Whole-word matches only ("REDUCER" never matches RED).
     *
     * @param list<string> $bulkCodes
     * @return array<string,string> bulk → guessed ladder color
     */
    public function guessColorsFromDescriptions(array $bulkCodes): array
    {
        if ($bulkCodes === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($bulkCodes, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->cms()->fetchAll("
                SELECT ItemCode, Description
                FROM CMS.dbo.Item
                WHERE ItemCode IN ({$ph})", $chunk);
            foreach ($rows as $r) {
                $desc = strtoupper((string) $r['Description']);
                foreach (self::DESCRIPTION_COLOR_PHRASES as $phrase => $color) {
                    if (preg_match('/\b' . str_replace(' ', '\s+', preg_quote($phrase, '/')) . '\b/', $desc)) {
                        $out[(string) $r['ItemCode']] = $color;
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Combined color guess: alias prefix first (Matt's primary signal),
     * description words fill the gaps.
     *
     * @param list<string> $bulkCodes
     * @return array<string,string> bulk → guessed ladder color
     */
    public function guessColors(array $bulkCodes): array
    {
        $out = $this->guessColorsFromAliases($bulkCodes);
        $missing = array_values(array_diff($bulkCodes, array_keys($out)));
        foreach ($this->guessColorsFromDescriptions($missing) as $bulk => $color) {
            $out[$bulk] = $color;
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     *  Dry-grind pass derivation
     * ----------------------------------------------------------------*/

    /** @return list<array{id:int,pattern:string}> */
    public function dryGrindTriggers(): array
    {
        return array_map(
            fn($r) => ['id' => (int) $r['id'], 'pattern' => (string) $r['pattern']],
            $this->db->fetchAll("SELECT id, pattern FROM sched_dry_grind_triggers ORDER BY pattern")
        );
    }

    public function addDryGrindTrigger(string $pattern): void
    {
        $pattern = strtoupper(trim($pattern));
        if ($pattern === '' || strlen($pattern) > 30) {
            throw new \InvalidArgumentException('Pattern must be 1-30 characters.');
        }
        $exists = $this->db->fetch("SELECT 1 FROM sched_dry_grind_triggers WHERE pattern = ?", [$pattern]);
        if (!$exists) {
            $this->db->insert('sched_dry_grind_triggers', ['pattern' => $pattern]);
        }
    }

    public function deleteDryGrindTrigger(int $id): void
    {
        $this->db->delete('sched_dry_grind_triggers', 'id = ?', [$id]);
    }

    /** @return list<array{id:int,pattern:string}> Non-dry milling triggers. */
    public function millTriggers(): array
    {
        return array_map(
            fn($r) => ['id' => (int) $r['id'], 'pattern' => (string) $r['pattern']],
            $this->db->fetchAll("SELECT id, pattern FROM sched_mill_triggers ORDER BY pattern")
        );
    }

    public function addMillTrigger(string $pattern): void
    {
        $pattern = strtoupper(trim($pattern));
        if ($pattern === '' || strlen($pattern) > 30) {
            throw new \InvalidArgumentException('Pattern must be 1-30 characters.');
        }
        $exists = $this->db->fetch("SELECT 1 FROM sched_mill_triggers WHERE pattern = ?", [$pattern]);
        if (!$exists) {
            $this->db->insert('sched_mill_triggers', ['pattern' => $pattern]);
        }
    }

    public function deleteMillTrigger(int $id): void
    {
        $this->db->delete('sched_mill_triggers', 'id = ?', [$id]);
    }

    /** Global dry-grind pass count (settings; default 3). */
    public function dryGrindPasses(): int
    {
        $row = $this->db->fetch("SELECT `value` FROM settings WHERE `key` = 'sched.dry_grind_passes'");
        $v = $row ? (int) $row['value'] : 0;
        return $v > 0 ? $v : 3;
    }

    public function saveDryGrindPasses(int $passes): void
    {
        $passes = max(1, $passes);
        $exists = $this->db->fetch("SELECT 1 FROM settings WHERE `key` = 'sched.dry_grind_passes'");
        if ($exists) {
            $this->db->update('settings', ['value' => (string) $passes], '`key` = ?', ['sched.dry_grind_passes']);
        } else {
            $this->db->insert('settings', ['key' => 'sched.dry_grind_passes', 'value' => (string) $passes]);
        }
    }

    /**
     * LIKE-style match: '%' is a wildcard (PGK% = starts with PGK).
     * Case-insensitive; no '%' = exact match. preg_quote leaves '%'
     * untouched, so replacing it with .* after quoting is safe.
     */
    public static function matchesTrigger(string $code, string $pattern): bool
    {
        $regex = '/^' . str_replace('%', '.*', preg_quote(strtoupper($pattern), '/')) . '$/';
        return (bool) preg_match($regex, strtoupper($code));
    }

    /**
     * Derive milling status + passes for the given bulk items from their
     * DIRECT recipe ingredients (Context='UI').
     *
     *   - dry grind:  any direct ingredient matches a dry-grind trigger
     *                 → milled, global dry-grind passes
     *   - standard:   any direct ingredient matches a (non-dry) milling
     *                 trigger → milled, 1 pass
     *   - not milled: matches NEITHER list → the formula is a blend of
     *                 pre-ground materials; it never appears on the mill
     *                 schedule at all.
     *
     * Intermediates never propagate their own status — only the direct
     * formula lines are inspected (they arrive pre-ground).
     *
     * @param list<string> $bulkCodes
     * @return array{passes: array<string,int>, dry: array<string,bool>, milled: array<string,bool>}
     */
    public function derivePasses(array $bulkCodes): array
    {
        $dryTriggers  = array_column($this->dryGrindTriggers(), 'pattern');
        $millTriggers = array_column($this->millTriggers(), 'pattern');
        $dryPasses    = $this->dryGrindPasses();

        $passes = [];
        $dry    = [];
        $milled = [];
        foreach ($bulkCodes as $c) {
            $passes[$c] = 1;
            $dry[$c]    = false;
            $milled[$c] = false;
        }
        if (empty($bulkCodes) || (empty($dryTriggers) && empty($millTriggers))) {
            return ['passes' => $passes, 'dry' => $dry, 'milled' => $milled];
        }

        // Direct UI ingredients for all requested bulks, chunked IN clause
        foreach (array_chunk($bulkCodes, 300) as $chunk) {
            $ph  = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "
                SELECT b.ItemCode AS bulk_code, ing.ItemCode AS ingredient
                  FROM CMS.dbo.Item b
                  JOIN CMS.dbo.Recipe r        ON r.Recipe  = b.CostingRecipe
                  JOIN CMS.dbo.RecipeDetail rd ON rd.Recipe = r.Recipe AND rd.Context = 'UI'
                  JOIN CMS.dbo.Item ing        ON ing.Item  = rd.Item
                 WHERE b.ItemCode IN ({$ph})
            ";
            foreach ($this->cms()->fetchAll($sql, $chunk) as $row) {
                $bulk = (string) $row['bulk_code'];
                if ($dry[$bulk] ?? false) continue;   // already at strongest tier
                $ing = (string) $row['ingredient'];

                foreach ($dryTriggers as $t) {
                    if (self::matchesTrigger($ing, $t)) {
                        $dry[$bulk]    = true;
                        $milled[$bulk] = true;
                        $passes[$bulk] = $dryPasses;
                        continue 2;   // next ingredient row
                    }
                }
                if (!$milled[$bulk]) {
                    foreach ($millTriggers as $t) {
                        if (self::matchesTrigger($ing, $t)) {
                            $milled[$bulk] = true;   // standard mill, passes stays 1
                            break;
                        }
                    }
                }
            }
        }

        return ['passes' => $passes, 'dry' => $dry, 'milled' => $milled];
    }

    /**
     * Worklist: bulk items whose packs carry a minimum stock level,
     * with current need — for the settings page "needs configuration"
     * card (caller filters out already-configured codes).
     *
     * @return list<array{bulk:string, packs_with_min:int, total_min_lbs:float, current_need_lbs:float, packs_short_on_orders:int}>
     */
    public function bulksWithMinStock(): array
    {
        $sql = "
            WITH PackMin AS (
                SELECT i.Item, i.ItemCode AS pack,
                       ms.MinimumStock,
                       COALESCE(it.OpenToSell, 0) AS open_to_sell
                  FROM CMS.dbo.Item i
                  JOIN (SELECT Item, MAX(MinimumStock) AS MinimumStock
                          FROM CMS.dbo.ItemEntity
                         WHERE Context='ST' AND ABS(MinimumStock - 99) > 0.0001
                         GROUP BY Item) ms
                    ON ms.Item = i.Item AND ms.MinimumStock > 0
                  LEFT JOIN (SELECT Item, SUM(QtyOpenToSell) AS OpenToSell
                               FROM CMS.dbo.InventoryTotal GROUP BY Item) it
                    ON it.Item = i.Item
                 WHERE i.Context = 'PP'
            ),
            PackBulk AS (
                SELECT pm.pack, pm.MinimumStock, pm.open_to_sell,
                       b.ItemCode AS bulk_code, b.Description AS bulk_desc, rd.QtyReqd,
                       ROW_NUMBER() OVER (PARTITION BY pm.pack ORDER BY rd.QtyReqd DESC) AS rn
                  FROM PackMin pm
                  JOIN CMS.dbo.Item pi ON pi.ItemCode = pm.pack
                  JOIN CMS.dbo.Recipe r ON r.Recipe = pi.CostingRecipe
                  JOIN CMS.dbo.RecipeDetail rd ON rd.Recipe = r.Recipe AND rd.Context='UI'
                  JOIN CMS.dbo.Item b ON b.Item = rd.Item AND b.Unit = 'lb'
            )
            SELECT bulk_code,
                   MAX(bulk_desc) AS bulk_desc,
                   COUNT(1) AS packs_with_min,
                   SUM(MinimumStock) AS total_min_lbs,
                   SUM(CASE WHEN MinimumStock - open_to_sell > 0 THEN MinimumStock - open_to_sell ELSE 0 END) AS current_need_lbs,
                   SUM(CASE WHEN open_to_sell < 0 THEN 1 ELSE 0 END) AS packs_short_on_orders
              FROM PackBulk
             WHERE rn = 1
             GROUP BY bulk_code
             ORDER BY current_need_lbs DESC, bulk_code
        ";
        $out = [];
        foreach ($this->cms()->fetchAll($sql) as $r) {
            $out[] = [
                'bulk'                  => (string) $r['bulk_code'],
                'description'           => (string) ($r['bulk_desc'] ?? ''),
                'packs_with_min'        => (int)    $r['packs_with_min'],
                'total_min_lbs'         => (float)  $r['total_min_lbs'],
                'current_need_lbs'      => (float)  $r['current_need_lbs'],
                'packs_short_on_orders' => (int)    $r['packs_short_on_orders'],
            ];
        }
        return $out;
    }

    /** The 12 fixed ladder colors (canonical keys). */
    public const COLORS = [
        'extender', 'opaque white', 'yellow', 'orange', 'warm red', 'red',
        'violet', 'reflex blue', 'blue', 'green', 'brown', 'black',
    ];

    /** Configured color order (settings override, else the default ladder). */
    public function colorOrder(): array
    {
        $row = $this->db->fetch("SELECT `value` FROM settings WHERE `key` = 'sched.color_order'");
        if ($row) {
            $decoded = json_decode((string) $row['value'], true);
            if (is_array($decoded) && count($decoded) === count(self::COLORS)) {
                return $decoded;
            }
        }
        return self::COLORS;
    }

    public function saveColorOrder(array $order): void
    {
        // Validate: must be a permutation of COLORS
        $sorted1 = $order;      sort($sorted1);
        $sorted2 = self::COLORS; sort($sorted2);
        if ($sorted1 !== $sorted2) {
            throw new \InvalidArgumentException('Color order must contain each ladder color exactly once.');
        }
        $val = json_encode(array_values($order));
        $existing = $this->db->fetch("SELECT 1 FROM settings WHERE `key` = 'sched.color_order'");
        if ($existing) {
            $this->db->update('settings', ['value' => $val], '`key` = ?', ['sched.color_order']);
        } else {
            $this->db->insert('settings', ['key' => 'sched.color_order', 'value' => $val]);
        }
    }
}

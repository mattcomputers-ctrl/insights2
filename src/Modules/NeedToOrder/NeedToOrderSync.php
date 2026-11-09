<?php

declare(strict_types=1);

namespace PII\Modules\NeedToOrder;

use PII\Services\Sync\SyncContext;

/**
 * NeedToOrderSync — builds both buy lists each sync (Matt, 2026-11-02):
 *
 *   nto_items      "to complete orders": what must be bought so every open
 *                  ticket — and every batch still to be cut for open sales
 *                  orders — can run (shortfalls only).
 *   nto_min_items  "minimum stock": purchased (recipe-less) items whose
 *                  PROJECTED stock (on hand − everything those tickets and
 *                  batches will consume) lands below their minimum; the
 *                  quantity tops them back UP to minimum.
 *
 * Consumes the Order Readiness job's same-run hand-off (SyncContext::share).
 * Adds from CMS: recipe explosion level by level, purchase history (last
 * vendor + their code), preferred vendor (Item.Supplier), open-PO qty.
 * The pure math lives in NeedToOrderEngine.
 */
class NeedToOrderSync
{
    public function steps(): array
    {
        return [
            ['name' => 'Building the buy lists', 'run' => fn(SyncContext $c) => $this->build($c)],
        ];
    }

    private function build(SyncContext $c): array
    {
        $ticketShort = $c->shared('or.ticket_demands', []);
        $ticketFull  = $c->shared('or.ticket_requirements', []);
        $orderDem    = $c->shared('or.order_demands', []);
        $leftover    = $c->shared('or.leftover_materials', []);
        $usable      = $c->shared('or.usable', []);
        $itemInfo    = $c->shared('or.item_info', []);
        $incoming    = $c->shared('or.ticket_supply', []);
        $batchSizes  = $c->shared('or.batch_sizes', []);

        $c->stage('nto_items', 'CREATE TABLE {table} LIKE nto_items');
        $c->stage('nto_min_items', 'CREATE TABLE {table} LIKE nto_min_items');

        $demandsA = array_merge($ticketShort, $orderDem);          // to complete
        $demandsB = array_merge($ticketFull, $orderDem);           // full projection
        if ($demandsB === [] && array_filter($itemInfo, fn($i) => $i['min'] !== null) === []) {
            $c->queueSwap('nto_items', 0);
            $c->queueSwap('nto_min_items', 0);
            return ['rows' => 0];
        }

        // Walk the recipe tree level by level: anything with an ACTIVE
        // recipe is made, never bought (Matt, 2026-11-02), so every recipe
        // found descends into its ingredients. One closure serves both
        // passes (the full requirements are a superset of the shortfalls).
        $recipes = [];     // item => [['item','qty_per'], …]
        $known   = [];     // item => true (already looked up)
        $frontier = array_values(array_unique(array_column($demandsB, 'item')));

        for ($depth = 0; $frontier !== [] && $depth < 6; $depth++) {
            $frontier = array_values(array_diff($frontier, array_keys($known)));
            if ($frontier === []) {
                break;
            }
            foreach ($frontier as $code) {
                $known[$code] = true;
            }
            $in = implode(',', array_fill(0, count($frontier), '?'));

            $next = [];
            foreach ($c->cmsFetchAll('recipes (level ' . ($depth + 1) . ')', "
                SELECT i.ItemCode AS Parent, ing.ItemCode AS Ing, rd.QtyReqd
                FROM CMS.dbo.Item i
                JOIN CMS.dbo.RecipeDetail rd ON rd.Recipe = i.CostingRecipe AND rd.Context = 'UI'
                JOIN CMS.dbo.Item ing ON ing.Item = rd.Item
                WHERE i.ItemCode IN ({$in})", $frontier) as $r) {
                $recipes[$r['Parent']][] = ['item' => (string) $r['Ing'], 'qty_per' => (float) $r['QtyReqd']];
                $next[] = (string) $r['Ing'];
            }
            $frontier = array_values(array_unique($next));
        }

        // Pass A — to complete orders: net shortfalls against leftover stock.
        $needs = $demandsA === [] ? []
            : NeedToOrderEngine::compute($demandsA, $leftover, $incoming, $recipes, $batchSizes);

        // Pass B — GROSS leaf requirements: intermediates still net against
        // their real stock and open-ticket output (a bulk on the shelf needs
        // no raw materials), but purchased leaves net against NOTHING, so
        // the result is everything the plan will consume per material.
        $stockIntermediates = array_intersect_key($usable, $recipes);
        $gross = $demandsB === [] ? []
            : NeedToOrderEngine::compute($demandsB, $stockIntermediates, $incoming, $recipes, $batchSizes);

        // Minimum-stock rows: projected = on hand − gross consumption; below
        // minimum → order back up TO the minimum. Items with an active
        // recipe belong to the production plan, never this list; minimum 99
        // means discontinued in CMS.
        $minRows = [];
        foreach ($itemInfo as $code => $info) {
            if ($info['min'] === null || $info['is_discontinued'] || $info['has_recipe']) {
                continue;
            }
            $g = $gross[$code] ?? null;
            $projected = (float) ($usable[$code] ?? 0.0) - ($g['qty'] ?? 0.0);
            $short = (float) $info['min'] - $projected;
            if ($short <= 1e-6) {
                continue;
            }
            $minRows[$code] = [
                'qty'       => round($short, 4),
                'minimum'   => (float) $info['min'],
                'on_hand'   => (float) ($usable[$code] ?? 0.0),
                'projected' => round($projected, 4),
                'due'       => $g['due'] ?? null,
                'refs'      => $g['refs'] ?? [],
                'via'       => $g['via'] ?? [],
                'srcs'      => $g['srcs'] ?? ['ticket' => false, 'order' => false],
            ];
        }

        $codes = array_values(array_unique(array_merge(array_keys($needs), array_keys($minRows))));
        if ($codes === []) {
            $c->queueSwap('nto_items', 0);
            $c->queueSwap('nto_min_items', 0);
            return ['rows' => 0];
        }
        $in = implode(',', array_fill(0, count($codes), '?'));

        // Last-purchased vendor (+ their code) for everything listed.
        $lastVendor = [];
        foreach ($c->cmsFetchAll('last-purchased vendors', "
            SELECT pod.ItemCode, e.EntityName AS Vendor, pod.TheirCode,
                   ROW_NUMBER() OVER (PARTITION BY pod.ItemCode ORDER BY o.DateOrdered DESC, o.Ordr DESC) AS rn
            FROM CMS.dbo.PurchaseOrderDetail pod
            JOIN CMS.dbo.Ordr o ON o.Ordr = pod.Ordr
            LEFT JOIN CMS.dbo.Entities e ON e.Entity = o.Entity
            WHERE pod.ItemCode IN ({$in})", $codes) as $r) {
            if ((int) $r['rn'] !== 1) {
                continue;
            }
            $lastVendor[$r['ItemCode']] = [
                'vendor'      => trim((string) ($r['Vendor'] ?? '')) !== '' ? trim((string) $r['Vendor']) : null,
                'vendor_code' => trim((string) ($r['TheirCode'] ?? '')) !== '' ? trim((string) $r['TheirCode']) : null,
            ];
        }

        // Names, units, preferred vendor, distinct-vendor counts, open-PO qty.
        $meta = [];
        foreach ($c->cmsFetchAll('item names & units', "
            SELECT i.ItemCode, i.Description, i.Unit, i.GLGroup,
                   CASE WHEN i.CostingRecipe IS NULL THEN 0 ELSE 1 END AS HasRecipe,
                   pe.EntityName AS PreferredVendor
            FROM CMS.dbo.Item i
            LEFT JOIN CMS.dbo.Entities pe ON pe.Entity = i.Supplier
            WHERE i.ItemCode IN ({$in})", $codes) as $r) {
            $meta[$r['ItemCode']] = $r;
        }
        // Fees and charges (wire fee, shipping) ride on tickets and POs as
        // pseudo-items, GLGroup 'Services' — never something to BUY.
        $isService = fn(string $code): bool => ($meta[$code]['GLGroup'] ?? '') === 'Services';
        $vendorCounts = [];
        foreach ($c->cmsFetchAll('vendor counts', "
            SELECT pod.ItemCode, COUNT(DISTINCT o.Entity) AS n
            FROM CMS.dbo.PurchaseOrderDetail pod
            JOIN CMS.dbo.Ordr o ON o.Ordr = pod.Ordr
            WHERE pod.ItemCode IN ({$in})
            GROUP BY pod.ItemCode", $codes) as $r) {
            $vendorCounts[$r['ItemCode']] = (int) $r['n'];
        }
        $onPo = [];
        foreach ($c->cmsFetchAll('already on open POs', "
            SELECT i.ItemCode, SUM(od.QtyReqd - COALESCE(od.QtyUsed, 0)) AS qty
            FROM CMS.dbo.OrdDetail od
            JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
            JOIN CMS.dbo.Item i ON i.Item = od.Item
            WHERE o.Context = 'PO' AND (o.Status IS NULL OR o.Status NOT IN ('CMP','CLS'))
              AND od.Context = 'PO' AND od.IsOpen = 1 AND i.ItemCode IN ({$in})
            GROUP BY i.ItemCode", $codes) as $r) {
            $onPo[$r['ItemCode']] = (float) $r['qty'];
        }

        $decorate = function (string $code) use ($meta, $lastVendor, $vendorCounts, $onPo): array {
            return [
                'item_code'    => $code,
                'description'  => (string) ($meta[$code]['Description'] ?? ''),
                'unit'         => (string) ($meta[$code]['Unit'] ?? ''),
                'on_po'        => $onPo[$code] ?? 0.0,
                'has_recipe'   => (int) ($meta[$code]['HasRecipe'] ?? 0),
                'preferred_vendor' => isset($meta[$code]['PreferredVendor']) && trim((string) $meta[$code]['PreferredVendor']) !== ''
                    ? trim((string) $meta[$code]['PreferredVendor']) : null,
                'vendor'       => $lastVendor[$code]['vendor'] ?? null,
                'vendor_code'  => $lastVendor[$code]['vendor_code'] ?? null,
                'vendor_count' => $vendorCounts[$code] ?? 0,
            ];
        };

        // Open POs count as incoming supply (Matt, 2026-11-02): a row only
        // appears when there is still a gap AFTER everything already on
        // order arrives, and only for the remainder.
        $rows = [];
        foreach ($needs as $code => $n) {
            $toOrder = $n['qty'] - ($onPo[$code] ?? 0.0);
            if ($toOrder <= 1e-6 || $isService($code)) {
                continue;
            }
            $rows[] = $decorate($code) + [
                'qty_needed'   => round($toOrder, 4),
                'first_needed' => $n['due'],
                'src_ticket'   => $n['srcs']['ticket'] ? 1 : 0,
                'src_order'    => $n['srcs']['order'] ? 1 : 0,
                'refs'         => json_encode($n['refs'], JSON_UNESCAPED_SLASHES),
                'via'          => json_encode($n['via'], JSON_UNESCAPED_SLASHES),
            ];
        }
        $c->insertRows('nto_items', $rows);
        $c->queueSwap('nto_items', 0);

        $mRows = [];
        foreach ($minRows as $code => $m) {
            $toOrder = $m['qty'] - ($onPo[$code] ?? 0.0);
            if ($toOrder <= 1e-6 || $isService($code)) {
                continue;   // covered by POs on their way, or a fee pseudo-item
            }
            $mRows[] = $decorate($code) + [
                'qty_needed'    => round($toOrder, 4),
                'minimum_stock' => $m['minimum'],
                'on_hand'       => $m['on_hand'],
                'projected'     => $m['projected'],
                'first_needed'  => $m['due'],
                'src_ticket'    => $m['srcs']['ticket'] ? 1 : 0,
                'src_order'     => $m['srcs']['order'] ? 1 : 0,
                'refs'          => json_encode(array_values($m['refs']), JSON_UNESCAPED_SLASHES),
                'via'           => json_encode($m['via'], JSON_UNESCAPED_SLASHES),
            ];
        }
        $c->insertRows('nto_min_items', $mRows);
        $c->queueSwap('nto_min_items', 0);

        return ['rows' => count($rows) + count($mRows)];
    }
}

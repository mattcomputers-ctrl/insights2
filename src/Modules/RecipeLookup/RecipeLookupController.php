<?php

declare(strict_types=1);

namespace PII\Modules\RecipeLookup;

use PII\Core\CMSDatabase;
use PII\Core\CMSUnavailableException;
use PII\Core\Database;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * RecipeLookupController — live CMS lookups (read-only), following the
 * Margin Watchdog pattern: friendly pause during blackout/kill switch.
 *
 * Search matches item code, item description, alias code, alias
 * description and recipe number. Each matched item lists every recipe
 * that PRODUCES it (the recipe's PK line — authoritative, no name
 * guessing), the active costing recipe first.
 */
class RecipeLookupController
{
    private const MAX_ITEMS = 80;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $items = [];
        $recipeHits = [];
        $error = null;
        $truncated = false;

        if ($q !== '') {
            try {
                [$items, $recipeHits, $truncated] = $this->search($q);
            } catch (CMSUnavailableException $e) {
                $error = $e->friendlyMessage();
            } catch (\Throwable $e) {
                $error = 'CMS query failed: ' . $e->getMessage();
            }
        }

        layout('recipes::index', [
            'pageTitle'  => 'Item & Recipe Lookup',
            'q'          => $q,
            'items'      => $items,
            'recipeHits' => $recipeHits,
            'truncated'  => $truncated,
            'error'      => $error,
            'asOf'       => SyncSettings::lastSuccessAt(),
        ], 'recipes');
    }

    /** Modal fragment: one recipe as a 100% formula + procedure. */
    public function recipe(string $id): void
    {
        $this->guarded(fn() => $this->renderRecipe((int) $id));
    }

    /**
     * Modal fragment: the ACTIVE recipe for an item CODE (alias-aware) —
     * the right-click menu's "View active recipe" (Matt, 2026-11-04).
     */
    public function active(): void
    {
        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            http_response_code(400);
            echo '<div class="alert alert-danger">No item code given.</div>';
            return;
        }
        $this->guarded(function () use ($code) {
            // An alias resolves to its inventory item's recipe.
            $row = CMSDatabase::getInstance()->fetch("
                SELECT COALESCE(parent.CostingRecipe, i.CostingRecipe) AS RecipeId,
                       COALESCE(parent.ItemCode, i.ItemCode) AS Code
                FROM CMS.dbo.Item i
                LEFT JOIN CMS.dbo.Item parent ON parent.Item = i.ReplacedBy
                WHERE i.ItemCode = ?", [$code]);
            if (!$row) {
                http_response_code(404);
                echo '<div class="alert alert-danger">Item ' . e($code) . ' not found in CMS.</div>';
                return;
            }
            if ($row['RecipeId'] === null) {
                echo '<div class="alert alert-info"><strong>' . e((string) $row['Code'])
                    . '</strong> has no active recipe in CMS — it is a purchased item.</div>';
                return;
            }
            $this->renderRecipe((int) $row['RecipeId']);
        });
    }

    /** Shared CMS error handling for modal fragments. */
    private function guarded(callable $fn): void
    {
        try {
            $fn();
        } catch (CMSUnavailableException $e) {
            http_response_code(503);
            echo '<div class="alert alert-info">' . e($e->friendlyMessage()) . '</div>';
        } catch (\Throwable $e) {
            http_response_code(500);
            echo '<div class="alert alert-danger">CMS query failed: ' . e($e->getMessage()) . '</div>';
        }
    }

    private function renderRecipe(int $id): void
    {
        $cms = CMSDatabase::getInstance();

        $head = $cms->fetch("
                SELECT r.Recipe, r.RecipeNumber, r.Version, r.Inactive, r.Comment,
                       CONVERT(VARCHAR(10), r.DateCreated, 23) AS DateCreated,
                       CONVERT(VARCHAR(10), r.DateUpdated, 23) AS DateUpdated,
                       i.ItemCode AS MakesCode, i.Description AS MakesDescription, i.Unit AS MakesUnit,
                       CASE WHEN act.Item IS NULL THEN 0 ELSE 1 END AS IsActive
                FROM CMS.dbo.Recipe r
                LEFT JOIN CMS.dbo.RecipeDetail pk ON pk.Recipe = r.Recipe AND pk.Context = 'PK'
                LEFT JOIN CMS.dbo.Item i ON i.Item = pk.Item
                OUTER APPLY (SELECT TOP 1 a.Item FROM CMS.dbo.Item a WHERE a.CostingRecipe = r.Recipe) act
                WHERE r.Recipe = ?", [$id]);
            if (!$head) {
                http_response_code(404);
                echo '<div class="alert alert-danger">Recipe not found.</div>';
                return;
            }

            // Formula + procedure in batch-sheet order (ExecOrder interleaves
            // instructions with ingredients — verified live 2026-11-04).
            $lines = $cms->fetchAll("
                SELECT rd.Context, rd.QtyReqd, rd.Description AS InstrText,
                       i.ItemCode, i.Description AS ItemDescription, i.Unit,
                       i.CostingRecipe
                FROM CMS.dbo.RecipeDetail rd
                LEFT JOIN CMS.dbo.Item i ON i.Item = rd.Item
                WHERE rd.Recipe = ? AND rd.Context IN ('UI','INSTR')
                ORDER BY COALESCE(rd.ExecOrder, rd.Line, 999999), rd.RecipeDetail", [$id]);

            Database::getInstance()->insert('audit_log', [
                'user_id' => current_user_id(), 'entity_type' => 'recipe_lookup',
                'entity_id' => (string) $id, 'action' => 'view_recipe',
                'details' => json_encode(['recipe' => $head['RecipeNumber']]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

        view('recipes::_recipe', ['head' => $head, 'lines' => $lines]);
    }

    /**
     * @return array{0: list<array>, 1: list<array>, 2: bool} [items, recipe hits, truncated]
     */
    private function search(string $q): array
    {
        $cms = CMSDatabase::getInstance();
        $like = '%' . self::escapeLike($q) . '%';
        $top = self::MAX_ITEMS + 1;

        // Inventory items matched directly…
        $byId = [];
        foreach ($cms->fetchAll("
            SELECT TOP {$top} i.Item, i.ItemCode, i.Description, i.Unit, i.Context, i.CostingRecipe
            FROM CMS.dbo.Item i
            WHERE i.ReplacedBy IS NULL
              AND (i.ItemCode LIKE ? ESCAPE '!' OR i.Description LIKE ? ESCAPE '!')
            ORDER BY i.ItemCode", [$like, $like]) as $r) {
            $byId[(int) $r['Item']] = $this->itemRow($r);
        }

        // …and via their aliases (customer names for the same item).
        foreach ($cms->fetchAll("
            SELECT TOP {$top} inv.Item, inv.ItemCode, inv.Description, inv.Unit, inv.Context, inv.CostingRecipe,
                   al.ItemCode AS AliasCode, al.Description AS AliasDescription
            FROM CMS.dbo.Item al
            JOIN CMS.dbo.Item inv ON inv.Item = al.ReplacedBy
            WHERE (al.ItemCode LIKE ? ESCAPE '!' OR al.Description LIKE ? ESCAPE '!')
            ORDER BY inv.ItemCode, al.ItemCode", [$like, $like]) as $r) {
            $id = (int) $r['Item'];
            $byId[$id] ??= $this->itemRow($r);
            $byId[$id]['aliases'][] = [
                'code'        => (string) $r['AliasCode'],
                'description' => (string) ($r['AliasDescription'] ?? ''),
            ];
        }

        $truncated = count($byId) > self::MAX_ITEMS;
        $items = array_slice(array_values($byId), 0, self::MAX_ITEMS);
        $ids = array_column($items, 'id');

        // Every recipe that PRODUCES one of these items (PK line), the
        // active costing recipe first, then newest.
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $recipesByItem = [];
            foreach ($cms->fetchAll("
                SELECT pk.Item, r.Recipe, r.RecipeNumber, r.Version, r.Inactive,
                       CONVERT(VARCHAR(10), r.DateCreated, 23) AS DateCreated
                FROM CMS.dbo.Recipe r
                JOIN CMS.dbo.RecipeDetail pk ON pk.Recipe = r.Recipe AND pk.Context = 'PK'
                WHERE pk.Item IN ({$in})
                ORDER BY r.DateCreated DESC, r.Recipe DESC", $ids) as $r) {
                $recipesByItem[(int) $r['Item']][] = $r;
            }
            foreach ($items as &$it) {
                $list = $recipesByItem[$it['id']] ?? [];
                $active = $it['costing_recipe'];
                usort($list, fn($a, $b) =>
                    [(int) $b['Recipe'] === $active, (string) ($b['DateCreated'] ?? '')]
                    <=> [(int) $a['Recipe'] === $active, (string) ($a['DateCreated'] ?? '')]);
                $it['recipes'] = $list;
            }
            unset($it);
        }

        // Recipe-number matches (their produced item shown alongside).
        $recipeHits = $cms->fetchAll("
            SELECT TOP 50 r.Recipe, r.RecipeNumber, r.Version, r.Inactive,
                   CONVERT(VARCHAR(10), r.DateCreated, 23) AS DateCreated,
                   i.ItemCode AS MakesCode, i.Description AS MakesDescription,
                   CASE WHEN act.Item IS NULL THEN 0 ELSE 1 END AS IsActive
            FROM CMS.dbo.Recipe r
            LEFT JOIN CMS.dbo.RecipeDetail pk ON pk.Recipe = r.Recipe AND pk.Context = 'PK'
            LEFT JOIN CMS.dbo.Item i ON i.Item = pk.Item
            OUTER APPLY (SELECT TOP 1 a.Item FROM CMS.dbo.Item a WHERE a.CostingRecipe = r.Recipe) act
            WHERE r.RecipeNumber LIKE ? ESCAPE '!'
            ORDER BY r.RecipeNumber", [$like]);

        return [$items, $recipeHits, $truncated];
    }

    private function itemRow(array $r): array
    {
        return [
            'id'             => (int) $r['Item'],
            'item_code'      => (string) $r['ItemCode'],
            'description'    => (string) ($r['Description'] ?? ''),
            'unit'           => (string) ($r['Unit'] ?? ''),
            'context'        => (string) ($r['Context'] ?? ''),
            'costing_recipe' => $r['CostingRecipe'] !== null ? (int) $r['CostingRecipe'] : null,
            'aliases'        => [],
            'recipes'        => [],
        ];
    }

    /** Escape LIKE wildcards in user input (SQL Server: % _ [ and the escape char). */
    private static function escapeLike(string $s): string
    {
        return str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $s);
    }
}

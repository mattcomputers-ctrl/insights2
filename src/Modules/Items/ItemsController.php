<?php

declare(strict_types=1);

namespace PII\Modules\Items;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\Ops\MasterData\ItemRequests;
use PII\Controllers\CmsWriteController;
use PII\Core\CMSDatabase;
use PII\Core\CMSUnavailableException;
use PII\Core\CSRF;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * ItemsController — the item 360 view, live read-only CMS queries.
 * Detail is addressed by item CODE via ?code= (codes contain spaces and
 * parentheses, so no path parameter). Costs only show with margin.view.
 * Users with the write permissions can add and change aliases, set the
 * minimum stock and the preferred supplier, and create raw material and
 * packaging items; each POST goes to the shared confirm step and is only
 * sent after a review.
 */
class ItemsController
{
    private const MAX_ROWS = 100;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $items = [];
        $error = null;
        $truncated = false;

        if ($q !== '') {
            try {
                $cms = CMSDatabase::getInstance();
                $like = '%' . cms_like_escape($q) . '%';
                $top = self::MAX_ROWS + 1;

                $byId = [];
                foreach ($cms->fetchAll("
                    SELECT TOP {$top} i.Item, i.ItemCode, i.Description, i.Unit, i.Context
                    FROM CMS.dbo.Item i
                    WHERE i.ReplacedBy IS NULL
                      AND (i.ItemCode LIKE ? ESCAPE '!' OR i.Description LIKE ? ESCAPE '!')
                    ORDER BY i.ItemCode", [$like, $like]) as $r) {
                    $byId[(int) $r['Item']] = $r + ['via' => null];
                }
                foreach ($cms->fetchAll("
                    SELECT TOP {$top} inv.Item, inv.ItemCode, inv.Description, inv.Unit, inv.Context,
                           al.ItemCode AS AliasCode
                    FROM CMS.dbo.Item al
                    JOIN CMS.dbo.Item inv ON inv.Item = al.ReplacedBy
                    WHERE al.ItemCode LIKE ? ESCAPE '!' OR al.Description LIKE ? ESCAPE '!'
                    ORDER BY inv.ItemCode", [$like, $like]) as $r) {
                    $byId[(int) $r['Item']] ??= $r + ['via' => $r['AliasCode']];
                }
                $truncated = count($byId) > self::MAX_ROWS;
                $items = array_slice(array_values($byId), 0, self::MAX_ROWS);
            } catch (CMSUnavailableException $e) {
                $error = $e->friendlyMessage();
            } catch (\Throwable $e) {
                $error = 'CMS query failed: ' . $e->getMessage();
            }
        }

        layout('items::index', [
            'pageTitle' => 'Items',
            'q'         => $q,
            'items'     => $items,
            'truncated' => $truncated,
            'error'     => $error,
            'canCreate' => CmsWriter::forCurrentUser()->allowed('item.create'),
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'items');
    }

    public function detail(): void
    {
        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            redirect('/items');
        }

        $d = ['head' => null, 'aliases' => [], 'recipes' => [], 'specs' => [],
              'stock' => [], 'totals' => null, 'shipments' => [], 'purchases' => [],
              'whereUsed' => [], 'resolvedFrom' => null, 'suppliers' => []];
        $error = null;
        $writer = CmsWriter::forCurrentUser();
        $canSettings = $writer->allowed('item.min_stock');

        try {
            $cms = CMSDatabase::getInstance();

            $head = $cms->fetch("
                SELECT i.Item, i.ItemCode, i.Description, i.AltDescription, i.Unit, i.Context,
                       i.GLGroup, i.StandardCost, i.SpecificGravity, CONVERT(VARCHAR(2000), i.Comment) AS Comment, i.ReplacedBy,
                       pe.EntityName AS PreferredVendor, i.Supplier,
                       ie.MinimumStock,
                       ic.Type AS CustomType
                FROM CMS.dbo.Item i
                LEFT JOIN CMS.dbo.Entities pe ON pe.Entity = i.Supplier
                LEFT JOIN CMS.dbo.ItemEntity ie ON ie.Item = i.Item AND ie.Context = 'ST'
                LEFT JOIN CMS.dbo.ItemCustom ic ON ic.Item = i.Item
                WHERE i.ItemCode = ?", [$code]);

            // An alias resolves to its inventory item.
            if ($head && $head['ReplacedBy'] !== null) {
                $d['resolvedFrom'] = $code;
                $head = $cms->fetch("
                    SELECT i.Item, i.ItemCode, i.Description, i.AltDescription, i.Unit, i.Context,
                           i.GLGroup, i.StandardCost, i.SpecificGravity, CONVERT(VARCHAR(2000), i.Comment) AS Comment, i.ReplacedBy,
                           pe.EntityName AS PreferredVendor, i.Supplier,
                           ie.MinimumStock,
                           ic.Type AS CustomType
                    FROM CMS.dbo.Item i
                    LEFT JOIN CMS.dbo.Entities pe ON pe.Entity = i.Supplier
                    LEFT JOIN CMS.dbo.ItemEntity ie ON ie.Item = i.Item AND ie.Context = 'ST'
                    LEFT JOIN CMS.dbo.ItemCustom ic ON ic.Item = i.Item
                    WHERE i.Item = ?", [(int) $head['ReplacedBy']]);
            }
            $d['head'] = $head;

            if ($head) {
                $id = (int) $head['Item'];
                $d['aliases'] = $cms->fetchAll("
                    SELECT al.Item, al.ItemCode, al.Description FROM CMS.dbo.Item al
                    WHERE al.ReplacedBy = ? ORDER BY al.ItemCode", [$id]);

                if ($canSettings && trim((string) $head['Context']) === 'SUNDRY') {
                    $d['suppliers'] = $cms->fetchAll("
                        SELECT e.Entity, e.EntityCode, n.EntityName
                        FROM CMS.dbo.Entity e
                        JOIN CMS.dbo.Entities n ON n.Entity = e.Entity
                        WHERE ',' + e.Context LIKE '%,SP,%' AND COALESCE(e.Inactive, 0) = 0
                        ORDER BY n.EntityName");
                }

                $d['recipes'] = $cms->fetchAll("
                    SELECT r.Recipe, r.RecipeNumber, r.Inactive,
                           CONVERT(VARCHAR(10), r.DateCreated, 23) AS DateCreated,
                           CASE WHEN i2.CostingRecipe = r.Recipe THEN 1 ELSE 0 END AS IsActive
                    FROM CMS.dbo.Recipe r
                    JOIN CMS.dbo.RecipeDetail pk ON pk.Recipe = r.Recipe AND pk.Context = 'PK'
                    JOIN CMS.dbo.Item i2 ON i2.Item = pk.Item
                    WHERE pk.Item = ?
                    ORDER BY CASE WHEN i2.CostingRecipe = r.Recipe THEN 0 ELSE 1 END, r.DateCreated DESC", [$id]);

                $d['specs'] = $cms->fetchAll("
                    SELECT t.Description AS TestName, t.Unit AS TestUnit, it.Min, it.Max, it.Target,
                           it.OnReceipt, it.OnProduction, it.OnRetest
                    FROM CMS.dbo.ItemTest it
                    JOIN CMS.dbo.Test t ON t.Test = it.Test
                    WHERE it.Item = ? ORDER BY it.Line, t.Description", [$id]);

                $d['totals'] = $cms->fetch("
                    SELECT SUM(it.QtyUsable) AS usable, SUM(it.QtySOH) AS soh,
                           SUM(it.QtyHold) AS hold, SUM(it.QtyUnusable) AS unusable
                    FROM CMS.dbo.InventoryTotal it WHERE it.Item = ?", [$id]);

                $d['stock'] = $cms->fetchAll("
                    SELECT TOP 100 l.LocationCode, l.Context AS LocCtx, inv.Qty, inv.Status,
                           sl.SublotCode, lot.SupLot,
                           CONVERT(VARCHAR(10), lot.ReceivedDate, 23) AS ReceivedDate,
                           CONVERT(VARCHAR(10), lot.ManfDate, 23)     AS ManfDate,
                           se.EntityName AS Supplier
                    FROM CMS.dbo.Inventory inv
                    JOIN CMS.dbo.Location l ON l.Location = inv.Location
                    LEFT JOIN CMS.dbo.Sublot sl ON sl.Sublot = inv.Sublot
                    LEFT JOIN CMS.dbo.Lot lot ON lot.Lot = sl.Lot
                    LEFT JOIN CMS.dbo.Entities se ON se.Entity = lot.Supplier
                    WHERE inv.Item = ? AND inv.Qty <> 0
                    ORDER BY sl.SublotCode DESC, l.LocationCode", [$id]);

                $d['shipments'] = $cms->fetchAll("
                    SELECT TOP 10 sd.Ordr, sd.TransDocument, sd.ShipToName, sd.QtyShipped,
                           CONVERT(VARCHAR(10), sd.DateShipped, 23) AS DateShipped
                    FROM CMS.dbo.ShipmentDetails sd
                    WHERE sd.ItemCode = ?
                    ORDER BY sd.DateShipped DESC", [$head['ItemCode']]);

                $d['purchases'] = $cms->fetchAll("
                    SELECT TOP 10 pod.Ordr, pod.TheirCode, pod.QtyReqd, pod.QtyReceived, pod.Unit,
                           CONVERT(VARCHAR(10), o.DateOrdered, 23) AS DateOrdered,
                           v.EntityName AS VendorName
                    FROM CMS.dbo.PurchaseOrderDetail pod
                    JOIN CMS.dbo.Ordr o ON o.Ordr = pod.Ordr
                    LEFT JOIN CMS.dbo.Entities v ON v.Entity = o.Entity
                    WHERE pod.ItemCode = ?
                    ORDER BY pod.Ordr DESC", [$head['ItemCode']]);

                $d['whereUsed'] = $cms->fetchAll("
                    SELECT TOP 30 parent.ItemCode, parent.Description, rd.QtyReqd
                    FROM CMS.dbo.RecipeDetail rd
                    JOIN CMS.dbo.Item parent ON parent.CostingRecipe = rd.Recipe
                    WHERE rd.Item = ? AND rd.Context = 'UI' AND parent.ReplacedBy IS NULL
                    ORDER BY rd.QtyReqd DESC", [$id]);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        if ($d['head'] === null && $error === null) {
            render_not_found();
        }

        layout('items::detail', $d + [
            'pageTitle' => 'Item ' . ($d['head']['ItemCode'] ?? $code),
            'code'      => $code,
            'error'     => $error,
            'showCost'  => user_can('margin.view'),
            'canAlias'  => $writer->allowed('item.alias_create'),
            'canSettings' => $canSettings,
            'mode'      => $writer->mode(),
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'items');
    }

    /** Add an alias to the item whose page the form was on. */
    public function aliasCreate(): void
    {
        CSRF::validateRequest();
        if (!CmsWriter::forCurrentUser()->allowed('item.alias_create')) {
            render_not_found();
        }
        $item = self::posted('item');
        CmsWriteController::propose('item.alias_create',
            ['item' => $item, 'code' => self::posted('code'), 'description' => self::posted('description')], self::back($item));
    }

    /** Change an alias: code, description, or the item it stands for. */
    public function aliasEdit(): void
    {
        CSRF::validateRequest();
        if (!CmsWriter::forCurrentUser()->allowed('item.alias_edit')) {
            render_not_found();
        }
        CmsWriteController::propose('item.alias_edit', [
            'alias' => self::posted('alias'), 'code' => self::posted('code'),
            'description' => self::posted('description'), 'item' => self::posted('move_to'),
        ], self::back(self::posted('item')));
    }

    /** Set the minimum stock of the item whose page the form was on. */
    public function minimumStock(): void
    {
        CSRF::validateRequest();
        if (!CmsWriter::forCurrentUser()->allowed('item.min_stock')) {
            render_not_found();
        }
        $item = self::posted('item');
        CmsWriteController::propose('item.min_stock', ['item' => $item, 'minimum' => self::posted('minimum')], self::back($item));
    }

    /** Set the preferred supplier of the item whose page the form was on. */
    public function supplier(): void
    {
        CSRF::validateRequest();
        if (!CmsWriter::forCurrentUser()->allowed('item.supplier')) {
            render_not_found();
        }
        $item = self::posted('item');
        CmsWriteController::propose('item.supplier', ['item' => $item, 'supplier' => self::posted('supplier')], self::back($item));
    }

    /** GET /items/new: the form for a new raw material or packaging item. */
    public function newItem(): void
    {
        $writer = CmsWriter::forCurrentUser();
        if (!$writer->allowed('item.create')) {
            render_not_found();
        }
        $get = static fn (string $k): string => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
        layout('items::new', [
            'pageTitle'   => 'New item',
            'kinds'       => ItemRequests::KINDS,
            'code'        => $get('code'),
            'description' => $get('description'),
            'kind'        => $get('kind'),
            'mode'        => $writer->mode(),
        ], 'items');
    }

    /** POST /items/new: review the new item. */
    public function create(): void
    {
        CSRF::validateRequest();
        if (!CmsWriter::forCurrentUser()->allowed('item.create')) {
            render_not_found();
        }
        $input = ['code' => self::posted('code'), 'description' => self::posted('description'), 'kind' => self::posted('kind')];
        CmsWriteController::propose('item.create', $input, '/items/new?' . http_build_query($input));
    }

    private static function posted(string $name): string
    {
        $v = $_POST[$name] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    private static function back(string $code): string
    {
        return $code !== '' ? '/items/view?code=' . rawurlencode($code) : '/items';
    }
}

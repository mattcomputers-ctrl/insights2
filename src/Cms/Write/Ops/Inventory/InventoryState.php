<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Inventory;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\WriteRefused;

/**
 * What the inventory flows read from CMS before planning a request:
 * counts and their lines, items, stock on hand, containers.
 *
 * Quantities are read as text with every digit (style 3), because a
 * posted adjustment is counted minus on hand to the last digit.
 */
final class InventoryState
{
    /**
     * One count with its lines, or null.
     *
     * @return null|array{InventoryCount:int,Version:int,Owner:string,Description:string,EffectiveDate:string,Posted:bool,
     *                    ChangeSet:?int,lines:list<array{detail:int,item:int,code:string,description:string,unit:string,
     *                    location:int,entered:string,qty:float,adjust:?float,onHand:float}>}
     */
    public static function count(CmsReader $cms, int $id): ?array
    {
        $h = $id > 0 ? $cms->fetch("
            SELECT c.InventoryCount, c.Version, c.Owner, c.Description,
                   CONVERT(VARCHAR(19), c.EffectiveDate, 126) AS EffectiveDate, c.Posted, c.ChangeSet
            FROM CMS.dbo.InventoryCount c
            WHERE c.InventoryCount = ?", [$id]) : null;
        if ($h === null) {
            return null;
        }
        $rows = $cms->fetchAll("
            SELECT d.InventoryCountDetail, d.Item, d.Location, d.QtyEntered,
                   CONVERT(VARCHAR(40), d.Qty, 3) AS Qty, CONVERT(VARCHAR(40), d.QtyAdjust, 3) AS QtyAdjust,
                   i.ItemCode, i.Description, i.Unit,
                   (SELECT CONVERT(VARCHAR(40), SUM(v.Qty), 3) FROM CMS.dbo.Inventory v
                     WHERE v.Item = d.Item AND v.Location = d.Location) AS OnHand
            FROM CMS.dbo.InventoryCountDetail d
            JOIN CMS.dbo.Item i ON i.Item = d.Item
            WHERE d.InventoryCount = ?
            ORDER BY d.InventoryCountDetail", [$id]);
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = [
                'detail'      => (int) $r['InventoryCountDetail'],
                'item'        => (int) $r['Item'],
                'code'        => trim((string) $r['ItemCode']),
                'description' => trim((string) ($r['Description'] ?? '')),
                'unit'        => trim((string) ($r['Unit'] ?? '')),
                'location'    => (int) $r['Location'],
                'entered'     => trim((string) ($r['QtyEntered'] ?? '')),
                'qty'         => self::float($r['Qty']) ?? 0.0,
                'adjust'      => self::float($r['QtyAdjust']),
                'onHand'      => self::float($r['OnHand']) ?? 0.0,
            ];
        }
        return [
            'InventoryCount' => (int) $h['InventoryCount'],
            'Version'        => (int) $h['Version'],
            'Owner'          => trim((string) $h['Owner']),
            'Description'    => trim((string) ($h['Description'] ?? '')),
            'EffectiveDate'  => (string) $h['EffectiveDate'],
            'Posted'         => self::truthy($h['Posted']),
            'ChangeSet'      => $h['ChangeSet'] === null ? null : (int) $h['ChangeSet'],
            'lines'          => $lines,
        ];
    }

    /** A count that can still be changed, or refuse. */
    public static function open(CmsReader $cms, mixed $id): array
    {
        $c = self::count($cms, (int) $id);
        if ($c === null) {
            throw new WriteRefused('That count was not found in CMS.');
        }
        if ($c['Posted']) {
            throw new WriteRefused("Count {$c['InventoryCount']} is already posted.");
        }
        return $c;
    }

    /**
     * The newest counts, for the list page.
     *
     * @return list<array<string,mixed>>
     */
    public static function counts(CmsReader $cms, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $cms->fetchAll("
            SELECT TOP {$limit} c.InventoryCount, c.Description,
                   CONVERT(VARCHAR(10), c.EffectiveDate, 126) AS EffectiveDate, c.Posted,
                   (SELECT COUNT(*) FROM CMS.dbo.InventoryCountDetail d WHERE d.InventoryCount = c.InventoryCount) AS Lines
            FROM CMS.dbo.InventoryCount c
            ORDER BY c.Posted, c.InventoryCount DESC");
    }

    /**
     * The inventory item a typed code names (not a customer's name for it).
     *
     * @return array{item:int,code:string,description:string,unit:string}
     */
    public static function item(CmsReader $cms, string $code): array
    {
        $code = trim($code);
        $r    = $code === '' ? null : $cms->fetch("
            SELECT TOP 1 i.Item, i.ItemCode, i.Description, i.Unit
            FROM CMS.dbo.Item i
            WHERE i.ItemCode = ? AND i.Context IN ('PP', 'SUNDRY')
            ORDER BY i.Item", [$code]);
        if ($r === null) {
            throw new WriteRefused("Item \"{$code}\" was not found in CMS as an inventory item.");
        }
        return ['item' => (int) $r['Item'], 'code' => trim((string) $r['ItemCode']),
                'description' => trim((string) ($r['Description'] ?? '')), 'unit' => trim((string) ($r['Unit'] ?? ''))];
    }

    /** Stock on hand of an item in the stock location (0 when it has no stock row). */
    public static function onHand(CmsReader $cms, int $item, int|string $location = InventoryRequests::STOCK): float
    {
        $r = $cms->fetch("
            SELECT CONVERT(VARCHAR(40), SUM(v.Qty), 3) AS OnHand
            FROM CMS.dbo.Inventory v
            WHERE v.Item = ? AND v.Location = ?", [$item, (int) $location]);
        return self::float($r['OnHand'] ?? null) ?? 0.0;
    }

    /**
     * A container by id, or null.
     *
     * @return null|array{Location:int,Code:string,Context:string,LabelReqd:bool}
     */
    public static function container(CmsReader $cms, int $id): ?array
    {
        $r = $id > 0 ? $cms->fetch("
            SELECT l.Location, l.LocationCode, l.Context, l.LabelReqd
            FROM CMS.dbo.Location l
            WHERE l.Location = ?", [$id]) : null;
        return $r === null ? null : ['Location' => (int) $r['Location'], 'Code' => trim((string) ($r['LocationCode'] ?? '')),
            'Context' => trim((string) ($r['Context'] ?? '')), 'LabelReqd' => self::truthy($r['LabelReqd'])];
    }

    /**
     * The newest containers that hold stock and still ask for a label.
     *
     * @return list<array<string,mixed>>
     */
    public static function labelsWaiting(CmsReader $cms, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $cms->fetchAll("
            SELECT TOP {$limit} l.Location, l.LocationCode, i.ItemCode, i.Description, i.Unit,
                   CONVERT(VARCHAR(40), v.Qty, 3) AS Qty
            FROM CMS.dbo.Location l
            JOIN CMS.dbo.Inventory v ON v.Location = l.Location AND v.Qty > 0
            JOIN CMS.dbo.Item i ON i.Item = v.Item
            WHERE l.LabelReqd = 1
            ORDER BY l.Location DESC");
    }

    /** A quantity for a person to read: up to 6 decimals, no trailing zeros. */
    public static function show(float $v): string
    {
        return Fmt::num(round($v, 6));
    }

    public static function float(mixed $v): ?float
    {
        if ($v === null || is_bool($v)) {
            return null;
        }
        $s = trim((string) $v);
        return ($s === '' || !is_numeric($s)) ? null : (float) $s;
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true');
    }
}

<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Purchasing;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Ops\Sales\SalesState;

/**
 * What CMS currently holds for a purchase order, a supplier or a supplier's
 * price list, read the way the purchasing flows need it. The entry screens
 * use the same readers, so what the user sees is what the flow plans from.
 *
 * Quantities are stored by CMS as floating-point numbers and the client
 * writes them out in full ("440.90000000000003"), so they are read without
 * rounding and printed the same way (see dbl()).
 */
final class PurchasingState
{
    /** The delivery terms in use on purchase orders. CMS keeps no list of its own. */
    public const INCOTERMS = ['Dest', 'Ship Point'];

    /**
     * @return null|array<string,mixed> head columns plus lines, memo (null = no memo row) and receipts
     */
    public static function order(CmsReader $cms, int $ordr): ?array
    {
        $h = $cms->fetch("
            SELECT o.Ordr, o.Version, o.Status, o.Owner, o.Entity, o.Currency, o.Terms, o.Incoterms, o.ShipVia,
                   CONVERT(VARCHAR(19), o.DateOrdered, 126) AS DateOrdered,
                   sv.EntityName AS ShipViaName, v.EntityName AS SupplierName
            FROM CMS.dbo.Ordr o
            LEFT JOIN CMS.dbo.Entities v ON v.Entity = o.Entity
            LEFT JOIN CMS.dbo.Entities sv ON sv.Entity = o.ShipVia
            WHERE o.Ordr = ? AND o.Context = 'PO'", [$ordr]);
        if ($h === null) {
            return null;
        }

        $lines = [];
        foreach ($cms->fetchAll("
            SELECT od.OrdDetail, od.Item, od.IsOpen, od.ManualPrice,
                   CONVERT(VARCHAR(30), od.Price, 2) AS Price,
                   CONVERT(VARCHAR(40), od.QtyReqd, 3) AS QtyReqd,
                   CONVERT(VARCHAR(40), COALESCE(od.QtyUsed, 0), 3) AS QtyUsed,
                   CONVERT(VARCHAR(40), od.SupQty, 3) AS SupQty, od.SupUnit,
                   CONVERT(VARCHAR(40), od.PercentOver, 3) AS PercentOver,
                   CONVERT(VARCHAR(19), od.DatePromised, 126) AS DatePromised,
                   i.ItemCode, i.Unit, i.Treatment, COALESCE(od.Description, i.Description) AS Description,
                   p.OrdDetailPricing, p.EntityItemCode, p.EntityUnit, p.PriceByPackage,
                   CONVERT(VARCHAR(40), p.QtyPerEntityQty, 3) AS QtyPerEntityQty
            FROM CMS.dbo.OrdDetail od
            LEFT JOIN CMS.dbo.Item i ON i.Item = od.Item
            LEFT JOIN CMS.dbo.OrdDetailPricing p ON p.OrdDetail = od.OrdDetail
            WHERE od.Ordr = ? AND od.Context = 'PO'
            ORDER BY od.OrdDetail", [$ordr]) as $l) {
            $perPack = self::float($l['QtyPerEntityQty']);
            $perPack = ($perPack === null || $perPack <= 0.0) ? 1.0 : $perPack;
            $qty     = self::float($l['QtyReqd']) ?? 0.0;
            $supQty  = self::float($l['SupQty']);
            $lines[] = [
                'ordDetail'      => (int) $l['OrdDetail'],
                'pricing'        => $l['OrdDetailPricing'] === null ? null : (int) $l['OrdDetailPricing'],
                'item'           => (int) $l['Item'],
                'code'           => (string) ($l['ItemCode'] ?? ''),
                'supplierCode'   => trim((string) ($l['EntityItemCode'] ?? '')),
                'description'    => (string) ($l['Description'] ?? ''),
                'unit'           => trim((string) ($l['Unit'] ?? '')),
                'treatment'      => trim((string) ($l['Treatment'] ?? '')),
                'isOpen'         => self::truthy($l['IsOpen']),
                'manualPrice'    => self::truthy($l['ManualPrice']),
                'price'          => SalesState::plain($l['Price']),
                'qty'            => self::dbl($qty),
                'received'       => self::dbl(self::float($l['QtyUsed']) ?? 0.0),
                'supQty'         => self::dbl($supQty ?? $qty / $perPack),
                'supUnit'        => trim((string) ($l['SupUnit'] ?? '')),
                'perPack'        => self::dbl($perPack),
                'percentOver'    => self::float($l['PercentOver']) ?? 0.0,
                'priceByPackage' => self::truthy($l['PriceByPackage']),
                'entityUnit'     => trim((string) ($l['EntityUnit'] ?? '')),
                'promised'       => substr((string) ($l['DatePromised'] ?? ''), 0, 10),
            ];
        }

        $memo = $cms->fetch("
            SELECT CONVERT(VARCHAR(8000), m.Data) AS Data
            FROM CMS.dbo.MemoRef m
            WHERE m.TableName = 'Ordr' AND m.TableID = ? AND m.Reference = 'POMemo'", [$ordr]);

        $receipts = [];
        foreach ($cms->fetchAll("
            SELECT cs.ChangeSet, cs.Version, CONVERT(VARCHAR(19), cs.ChangeDate, 126) AS ChangeDate, cs.Shipment,
                   r.OrdDetail, r.Item, CONVERT(VARCHAR(40), r.PSQty, 3) AS PSQty, r.PSUnit,
                   (SELECT COUNT(*) FROM CMS.dbo.BillDetail b WHERE b.Receipt = cs.ChangeSet AND b.Bill IS NOT NULL) AS Billed,
                   (SELECT COUNT(*) FROM CMS.dbo.ChangeSetReceipt x WHERE x.ChangeSet = cs.ChangeSet) AS LineCount,
                   c.InventoryCost, CONVERT(VARCHAR(40), c.UnitCostMK, 3) AS UnitCostMK
            FROM CMS.dbo.ChangeSet cs
            JOIN CMS.dbo.ChangeSetReceipt r ON r.ChangeSet = cs.ChangeSet
            OUTER APPLY (SELECT TOP 1 ic.InventoryCost, ic.UnitCostMK
                         FROM CMS.dbo.InvMovement m
                         JOIN CMS.dbo.InvMovementDtl d ON d.InvMovement = m.InvMovement
                         JOIN CMS.dbo.InventoryCost ic ON ic.InventoryCost = d.InventoryCost
                         WHERE m.ChangeSet = cs.ChangeSet AND d.OrdDetail = r.OrdDetail
                         ORDER BY d.InvMovementDtl) c
            WHERE cs.Ordr = ? AND cs.Context = 'PO'
            ORDER BY cs.ChangeSet", [$ordr]) as $r) {
            $cost = self::float($r['UnitCostMK']);
            $receipts[] = [
                'changeSet'     => (int) $r['ChangeSet'],
                'version'       => (int) $r['Version'],
                'date'          => (string) ($r['ChangeDate'] ?? ''),
                'shipment'      => trim((string) ($r['Shipment'] ?? '')),
                'ordDetail'     => (int) $r['OrdDetail'],
                'item'          => (int) $r['Item'],
                'qty'           => self::dbl(self::float($r['PSQty']) ?? 0.0),
                'unit'          => trim((string) ($r['PSUnit'] ?? '')),
                'billed'        => (int) $r['Billed'] > 0,
                'lineCount'     => (int) $r['LineCount'],
                'inventoryCost' => ($r['InventoryCost'] === null || $cost === null) ? null : (int) $r['InventoryCost'],
                'unitCost'      => ($r['InventoryCost'] === null || $cost === null) ? null : self::dbl($cost),
            ];
        }

        return [
            'Ordr'         => (int) $h['Ordr'],
            'Version'      => (int) $h['Version'],
            'Status'       => trim((string) ($h['Status'] ?? '')),
            'Owner'        => (string) $h['Owner'],
            'Supplier'     => (int) $h['Entity'],
            'SupplierName' => (string) ($h['SupplierName'] ?? ''),
            'Currency'     => trim((string) ($h['Currency'] ?? '')),
            'Terms'        => trim((string) ($h['Terms'] ?? '')),
            'Incoterms'    => trim((string) ($h['Incoterms'] ?? '')),
            'ShipVia'      => $h['ShipVia'] === null ? null : (int) $h['ShipVia'],
            'ShipViaName'  => (string) ($h['ShipViaName'] ?? ''),
            'DateOrdered'  => (string) ($h['DateOrdered'] ?? ''),
            'lines'        => $lines,
            'memo'         => $memo === null ? null : (string) ($memo['Data'] ?? ''),
            'receipts'     => $receipts,
        ];
    }

    /**
     * A supplier with everything a new purchase order copies from it.
     *
     * @return null|array<string,mixed>
     */
    public static function supplier(CmsReader $cms, int $entity): ?array
    {
        $s = $cms->fetch("
            SELECT e.Entity, e.EntityCode, n.EntityName, e.Context, e.Inactive, e.Currency, e.Terms, e.Incoterms, e.ShipVia
            FROM CMS.dbo.Entity e
            LEFT JOIN CMS.dbo.Entities n ON n.Entity = e.Entity
            WHERE e.Entity = ?", [$entity]);
        if ($s === null || !in_array('SP', array_map('trim', explode(',', (string) ($s['Context'] ?? ''))), true)) {
            return null;
        }
        $memo = $cms->fetch("
            SELECT CONVERT(VARCHAR(8000), m.Data) AS Data
            FROM CMS.dbo.MemoRef m
            WHERE m.TableName = 'Entity' AND m.TableID = ? AND m.Reference = 'POMemo'", [$entity]);
        return [
            'entity'    => (int) $s['Entity'],
            'code'      => (string) ($s['EntityCode'] ?? ''),
            'name'      => (string) ($s['EntityName'] ?? ''),
            'inactive'  => self::truthy($s['Inactive']),
            'currency'  => trim((string) ($s['Currency'] ?? '')),
            'terms'     => trim((string) ($s['Terms'] ?? '')),
            'incoterms' => trim((string) ($s['Incoterms'] ?? '')),
            'shipVia'   => $s['ShipVia'] === null ? 0 : (int) $s['ShipVia'],
            'address'   => self::address($cms, $entity),
            'memo'      => ($memo === null || trim((string) ($memo['Data'] ?? '')) === '') ? null : (string) $memo['Data'],
        ];
    }

    /** The address CMS holds for a supplier or for ourselves. @return array<string,string> blank when there is none */
    public static function address(CmsReader $cms, int $entity): array
    {
        $a = $cms->fetch("
            SELECT a.Address, a.Name, a.AddrLine1, a.AddrLine2, a.AddrLine3, a.City, a.State, a.ZipCode,
                   a.Contact, a.Email, a.Fax, a.Phone, a.CountryName
            FROM CMS.dbo.AddressRef a
            WHERE a.TableName = 'Entity' AND a.TableID = ? AND a.Reference = 'Address'", [$entity]);
        return $a === null ? [] : array_map(static fn ($v) => $v === null ? '' : rtrim((string) $v), $a);
    }

    /** Suppliers to pick from on the new-PO screen. @return list<array<string,mixed>> */
    public static function searchSuppliers(CmsReader $cms, string $q, int $limit = 30): array
    {
        $like = '%' . str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $q) . '%';
        return $cms->fetchAll("
            SELECT TOP " . max(1, $limit) . " e.Entity, e.EntityCode, n.EntityName, e.Terms, e.Currency
            FROM CMS.dbo.Entity e
            JOIN CMS.dbo.Entities n ON n.Entity = e.Entity
            WHERE ',' + e.Context LIKE '%,SP,%' AND COALESCE(e.Inactive, 0) = 0
              AND (e.EntityCode LIKE ? ESCAPE '!' OR n.EntityName LIKE ? ESCAPE '!')
            ORDER BY n.EntityName", [$like, $like]);
    }

    /**
     * Resolve the code typed for a PO line: our item code or the supplier's
     * own code for it. Returns the item and, when the supplier's current
     * price list has it, that price-list row.
     *
     * @return array{item:int,code:string,description:string,unit:string,treatment:string,
     *               percentUnder:string,percentOver:string,row:?array<string,mixed>}|array{error:string}
     */
    public static function resolveLine(CmsReader $cms, int $supplier, string $code, \DateTimeInterface $on): array
    {
        $code = trim($code);
        $at   = $on->format('Y-m-d H:i:s');
        $rows = $cms->fetchAll("
            SELECT pd.PriceDetail, pd.PriceVersion, pd.EntityItemCode, pd.Description, CONVERT(VARCHAR(8000), pd.Comment) AS Comment,
                   pd.Currency, pd.PkgType, CONVERT(VARCHAR(40), pd.EntityQuantity, 3) AS EntityQuantity, pd.EntityUnit, pd.PriceByPackage,
                   CONVERT(VARCHAR(40), pd.MinOrder1, 3) AS MinOrder1, CONVERT(VARCHAR(30), pd.Price1, 2) AS Price1,
                   CONVERT(VARCHAR(40), pd.MinOrder2, 3) AS MinOrder2, CONVERT(VARCHAR(30), pd.Price2, 2) AS Price2,
                   CONVERT(VARCHAR(40), pd.MinOrder3, 3) AS MinOrder3, CONVERT(VARCHAR(30), pd.Price3, 2) AS Price3,
                   CONVERT(VARCHAR(40), pd.MinOrder4, 3) AS MinOrder4, CONVERT(VARCHAR(30), pd.Price4, 2) AS Price4,
                   CONVERT(VARCHAR(40), pd.MinOrder5, 3) AS MinOrder5, CONVERT(VARCHAR(30), pd.Price5, 2) AS Price5,
                   i.Item, i.ItemCode, i.Description AS ItemDescription, i.Unit, i.Treatment,
                   CONVERT(VARCHAR(40), i.UnderReceive, 3) AS UnderReceive, CONVERT(VARCHAR(40), i.OverReceive, 3) AS OverReceive
            FROM CMS.dbo.PriceVersion pv
            JOIN CMS.dbo.PriceDetail pd ON pd.PriceVersion = pv.PriceVersion
            JOIN CMS.dbo.Item i ON i.Item = pd.InvItem
            WHERE pv.Entity = ? AND (i.ItemCode = ? OR pd.EntityItemCode = ?)
              AND pv.EffectiveDate = (SELECT MAX(x.EffectiveDate) FROM CMS.dbo.PriceVersion x
                                      WHERE x.Entity = pv.Entity AND x.EffectiveDate <= ?)
            ORDER BY pd.PriceDetail", [$supplier, $code, $code, $at]);

        if (count($rows) > 1) {
            $exact = array_values(array_filter($rows,
                static fn ($r) => strcasecmp(trim((string) ($r['EntityItemCode'] ?? '')), $code) === 0));
            if (count($exact) !== 1) {
                $codes = array_unique(array_filter(array_map(static fn ($r) => trim((string) ($r['EntityItemCode'] ?? '')), $rows)));
                return ['error' => "\"{$code}\" is on this supplier's price list more than once"
                    . ($codes === [] ? '. Enter the order in CMS.' : ' (' . implode(', ', $codes) . "). Enter the supplier's own code for the one you want.")];
            }
            $rows = $exact;
        }
        $row  = $rows[0] ?? null;
        $item = $row ?? $cms->fetch("
            SELECT i.Item, i.ItemCode, i.Description AS ItemDescription, i.Unit, i.Treatment,
                   CONVERT(VARCHAR(40), i.UnderReceive, 3) AS UnderReceive, CONVERT(VARCHAR(40), i.OverReceive, 3) AS OverReceive
            FROM CMS.dbo.Item i
            WHERE i.ItemCode = ? AND i.Context <> 'NAME'", [$code]);
        if ($item === null) {
            return ['error' => "Item \"{$code}\" was not found in CMS or on this supplier's price list."];
        }

        if ($row !== null) {
            $tiers = [];
            foreach ([1, 2, 3, 4, 5] as $n) {
                $min = self::float($row["MinOrder{$n}"]);
                if ($min !== null && $row["Price{$n}"] !== null) {
                    $tiers[] = ['n' => $n, 'min' => $min, 'price' => trim((string) $row["Price{$n}"])];
                }
            }
            $perPack = self::float($row['EntityQuantity']);
            $row = [
                'priceDetail'    => (int) $row['PriceDetail'],
                'priceVersion'   => (int) $row['PriceVersion'],
                'supplierCode'   => trim((string) ($row['EntityItemCode'] ?? '')),
                'description'    => (string) ($row['Description'] ?? ''),
                'comment'        => (string) ($row['Comment'] ?? ''),
                'currency'       => trim((string) ($row['Currency'] ?? '')),
                'pkgType'        => $row['PkgType'] === null ? null : (int) $row['PkgType'],
                'perPack'        => ($row['PkgType'] !== null && $perPack !== null && $perPack > 0.0) ? $perPack : null,
                'unit'           => trim((string) ($row['EntityUnit'] ?? '')),
                'priceByPackage' => self::truthy($row['PriceByPackage']),
                'tiers'          => $tiers,
            ];
        }
        $pct = static fn (mixed $v): string => self::float($v) === null ? '' : self::dbl((float) self::float($v));
        return [
            'item'         => (int) $item['Item'],
            'code'         => (string) $item['ItemCode'],
            'description'  => (string) ($item['ItemDescription'] ?? ''),
            'unit'         => trim((string) ($item['Unit'] ?? '')),
            'treatment'    => trim((string) ($item['Treatment'] ?? '')),
            'percentUnder' => $pct($item['UnderReceive']),
            'percentOver'  => $pct($item['OverReceive']),
            'row'          => $row,
        ];
    }

    /** A number read from a float column (plain or scientific text), or null. */
    public static function float(mixed $v): ?float
    {
        if ($v === null || is_bool($v)) {
            return null;
        }
        $s = trim((string) $v);
        return ($s === '' || !is_numeric($s)) ? null : (float) $s;
    }

    /**
     * A floating-point number the way the CMS client writes one: the shortest
     * text that reads back as the same number ("165", "44.09", "440.90000000000003").
     */
    public static function dbl(float $f): string
    {
        if (is_nan($f) || is_infinite($f)) {
            throw new \InvalidArgumentException('Not a finite number.');
        }
        $s = var_export($f, true);
        if (stripos($s, 'e') !== false) {
            return Fmt::num($f);
        }
        if (str_ends_with($s, '.0')) {
            $s = substr($s, 0, -2);
        }
        return $s === '-0' ? '0' : $s;
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || (is_string($v) && strcasecmp($v, 'true') === 0);
    }
}

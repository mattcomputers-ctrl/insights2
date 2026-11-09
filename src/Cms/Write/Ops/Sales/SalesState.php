<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Sales;

use PII\Cms\Write\CmsReader;

/**
 * What CMS currently holds for a sales order, a customer or an item, read
 * the way the sales flows need it. The entry screens use the same readers,
 * so what the user sees is what the flow plans from.
 */
final class SalesState
{
    /**
     * @return null|array{
     *   Ordr:int, Version:int, Status:string, Owner:string, ShipTo:int, BillTo:int, IsQuote:bool, CreditHold:bool,
     *   ReserveAmount:string, PoNumber:string, DateRequired:string, DateCompleted:string, ShipVia:?int,
     *   ShipViaName:string, ShipToName:string, BillToName:string,
     *   lines:list<array<string,mixed>>, memos:array<string,string>
     * }
     */
    public static function order(CmsReader $cms, int $ordr): ?array
    {
        $h = $cms->fetch("
            SELECT o.Ordr, o.Version, o.Status, o.Owner, o.ShipTo, o.BillTo, o.IsQuote, o.CreditHold,
                   CONVERT(VARCHAR(30), o.ReserveAmount, 2) AS ReserveAmount, o.PoNumber,
                   CONVERT(VARCHAR(19), o.DateRequired, 126) AS DateRequired,
                   CONVERT(VARCHAR(19), o.DateCompleted, 126) AS DateCompleted,
                   o.ShipVia, sv.EntityName AS ShipViaName, st.EntityName AS ShipToName, bt.EntityName AS BillToName
            FROM CMS.dbo.Ordr o
            LEFT JOIN CMS.dbo.Entities st ON st.Entity = o.ShipTo
            LEFT JOIN CMS.dbo.Entities bt ON bt.Entity = o.BillTo
            LEFT JOIN CMS.dbo.Entities sv ON sv.Entity = o.ShipVia
            WHERE o.Ordr = ? AND o.Context = 'SH'", [$ordr]);
        if ($h === null) {
            return null;
        }

        $lines = [];
        foreach ($cms->fetchAll("
            SELECT od.OrdDetail, od.Item, od.ItemName, od.IsOpen, od.ManualPrice,
                   CONVERT(VARCHAR(40), CONVERT(DECIMAL(19,4), od.Price)) AS Price,
                   CONVERT(VARCHAR(40), CONVERT(DECIMAL(19,4), od.QtyReqd)) AS QtyReqd,
                   CONVERT(VARCHAR(40), CONVERT(DECIMAL(19,4), COALESCE(od.QtyUsed, 0))) AS QtyUsed,
                   i.ItemCode, al.ItemCode AS AliasCode, COALESCE(od.Description, al.Description, i.Description) AS Description,
                   i.Unit, p.OrdDetailPricing
            FROM CMS.dbo.OrdDetail od
            LEFT JOIN CMS.dbo.Item i ON i.Item = od.Item
            LEFT JOIN CMS.dbo.Item al ON al.Item = od.ItemName
            LEFT JOIN CMS.dbo.OrdDetailPricing p ON p.OrdDetail = od.OrdDetail
            WHERE od.Ordr = ? AND od.Context = 'SH'
            ORDER BY od.OrdDetail", [$ordr]) as $l) {
            $lines[] = [
                'ordDetail'   => (int) $l['OrdDetail'],
                'pricing'     => $l['OrdDetailPricing'] === null ? null : (int) $l['OrdDetailPricing'],
                'item'        => (int) $l['Item'],
                'itemName'    => (int) ($l['ItemName'] ?? $l['Item']),
                'code'        => (string) ($l['AliasCode'] ?: $l['ItemCode']),
                'itemCode'    => (string) ($l['ItemCode'] ?? ''),
                'description' => (string) ($l['Description'] ?? ''),
                'unit'        => (string) ($l['Unit'] ?? ''),
                'isOpen'      => self::truthy($l['IsOpen']),
                'manualPrice' => self::truthy($l['ManualPrice']),
                'price'       => self::plain($l['Price']),
                'qty'         => self::plain($l['QtyReqd']),
                'shipped'     => self::plain($l['QtyUsed']),
            ];
        }

        $memos = [];
        foreach ($cms->fetchAll("
            SELECT m.Reference, CONVERT(VARCHAR(8000), m.Data) AS Data
            FROM CMS.dbo.MemoRef m
            WHERE m.TableName = 'Ordr' AND m.TableID = ?", [$ordr]) as $m) {
            $memos[(string) $m['Reference']] = (string) ($m['Data'] ?? '');
        }

        return [
            'Ordr'          => (int) $h['Ordr'],
            'Version'       => (int) $h['Version'],
            'Status'        => trim((string) $h['Status']),
            'Owner'         => (string) $h['Owner'],
            'ShipTo'        => (int) $h['ShipTo'],
            'BillTo'        => (int) $h['BillTo'],
            'IsQuote'       => self::truthy($h['IsQuote']),
            'CreditHold'    => self::truthy($h['CreditHold']),
            'ReserveAmount' => ($h['ReserveAmount'] === null || $h['ReserveAmount'] === '') ? '0.0000' : trim((string) $h['ReserveAmount']),
            'PoNumber'      => (string) ($h['PoNumber'] ?? ''),
            'DateRequired'  => (string) ($h['DateRequired'] ?? ''),
            'DateCompleted' => (string) ($h['DateCompleted'] ?? ''),
            'ShipVia'       => $h['ShipVia'] === null ? null : (int) $h['ShipVia'],
            'ShipViaName'   => (string) ($h['ShipViaName'] ?? ''),
            'ShipToName'    => (string) ($h['ShipToName'] ?? ''),
            'BillToName'    => (string) ($h['BillToName'] ?? ''),
            'lines'         => $lines,
            'memos'         => $memos,
        ];
    }

    /**
     * A ship-to customer with everything a new order copies from it and
     * from its bill-to (parent) account.
     *
     * @return null|array<string,mixed>
     */
    public static function customer(CmsReader $cms, int $shipTo): ?array
    {
        $sql = "
            SELECT e.Entity, e.EntityCode, n.EntityName, e.Parent, e.Context, e.Inactive, e.Currency, e.Terms,
                   e.Salesman, e.Tax1Group, e.Tax1Number, e.Tax2Number, e.Incoterms, e.ShipVia,
                   e.FreightService, e.FreightAccount, e.FreightOption
            FROM CMS.dbo.Entity e
            LEFT JOIN CMS.dbo.Entities n ON n.Entity = e.Entity
            WHERE e.Entity = ?";
        $s = $cms->fetch($sql, [$shipTo]);
        if ($s === null || !self::hasContext((string) ($s['Context'] ?? ''), 'SH')) {
            return null;
        }
        $billToId = ($s['Parent'] === null || (int) $s['Parent'] === 0) ? (int) $s['Entity'] : (int) $s['Parent'];
        $b = $billToId === (int) $s['Entity'] ? $s : $cms->fetch($sql, [$billToId]);
        if ($b === null) {
            return null;
        }

        $address = $cms->fetch("
            SELECT a.Address, a.Name, a.AddrLine1, a.AddrLine2, a.AddrLine3, a.City, a.State, a.ZipCode,
                   a.Country, a.CountryName, a.Contact, a.Email, a.Fax, a.Phone
            FROM CMS.dbo.AddressRef a
            WHERE a.TableName = 'Entity' AND a.TableID = ? AND a.Reference = 'Address'", [$shipTo]);
        $memo = $cms->fetch("
            SELECT m.Memo, CONVERT(VARCHAR(8000), m.Data) AS Data
            FROM CMS.dbo.MemoRef m
            WHERE m.TableName = 'Entity' AND m.TableID = ? AND m.Reference = 'InternalMemo'", [$billToId]);

        $pick = static fn (string $col): string => trim((string) ($s[$col] ?? '')) !== ''
            ? trim((string) $s[$col]) : trim((string) ($b[$col] ?? ''));

        return [
            'shipTo'       => (int) $s['Entity'],
            'shipToCode'   => (string) ($s['EntityCode'] ?? ''),
            'shipToName'   => (string) ($s['EntityName'] ?? ''),
            'billTo'       => $billToId,
            'billToCode'   => (string) ($b['EntityCode'] ?? ''),
            'billToName'   => (string) ($b['EntityName'] ?? ''),
            'inactive'     => self::truthy($s['Inactive']) || self::truthy($b['Inactive']),
            'currency'     => trim((string) ($b['Currency'] ?? '')),
            'terms'        => trim((string) ($b['Terms'] ?? '')),
            'tax1Group'    => trim((string) ($b['Tax1Group'] ?? '')),
            'tax1Number'   => trim((string) ($b['Tax1Number'] ?? '')),
            'tax2Number'   => trim((string) ($b['Tax2Number'] ?? '')),
            'salesman'     => $pick('Salesman'),
            'incoTerms'    => $pick('Incoterms'),
            'shipVia'      => $pick('ShipVia'),
            'freightService' => $pick('FreightService'),
            'freightAccount' => $pick('FreightAccount'),
            'freightOption'  => $pick('FreightOption'),
            'address'      => $address === null ? [] : array_map(static fn ($v) => $v === null ? '' : rtrim((string) $v), $address),
            'memo'         => ($memo === null || trim((string) ($memo['Data'] ?? '')) === '')
                ? null : ['memo' => (int) $memo['Memo'], 'text' => (string) $memo['Data']],
        ];
    }

    /** Customers to pick from on the new-order screen. @return list<array<string,mixed>> */
    public static function searchCustomers(CmsReader $cms, string $q, int $limit = 30): array
    {
        $like = '%' . str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $q) . '%';
        return $cms->fetchAll("
            SELECT TOP " . max(1, $limit) . " e.Entity, e.EntityCode, n.EntityName, p.EntityName AS BillToName
            FROM CMS.dbo.Entity e
            JOIN CMS.dbo.Entities n ON n.Entity = e.Entity
            LEFT JOIN CMS.dbo.Entities p ON p.Entity = e.Parent
            WHERE (',' + e.Context LIKE '%,SH,%' OR ',' + e.Context LIKE '%,BTSH,%') AND COALESCE(e.Inactive, 0) = 0
              AND (e.EntityCode LIKE ? ESCAPE '!' OR n.EntityName LIKE ? ESCAPE '!')
            ORDER BY n.EntityName", [$like, $like]);
    }

    /** Carriers for the ship-via list. @return list<array<string,mixed>> */
    public static function carriers(CmsReader $cms): array
    {
        return $cms->fetchAll("
            SELECT e.Entity, n.EntityName
            FROM CMS.dbo.Entity e
            JOIN CMS.dbo.Entities n ON n.Entity = e.Entity
            WHERE ',' + e.Context LIKE '%,SV,%' AND COALESCE(e.Inactive, 0) = 0
            ORDER BY n.EntityName");
    }

    public static function isCarrier(CmsReader $cms, int $entity): bool
    {
        $row = $cms->fetch("SELECT e.Context FROM CMS.dbo.Entity e WHERE e.Entity = ? /* carrier */", [$entity]);
        return $row !== null && self::hasContext((string) ($row['Context'] ?? ''), 'SV');
    }

    /**
     * Resolve the code a user typed for an order line: either the name the
     * customer orders by (an alias) or the inventory item itself.
     *
     * @return array{item:int,itemName:int,code:string,description:?string,unit:string}|array{error:string}
     */
    public static function resolveItem(CmsReader $cms, string $code): array
    {
        $code = trim($code);
        $rows = $cms->fetchAll("
            SELECT i.Item, i.ItemCode, i.Description, i.Unit, i.Context, i.ReplacedBy,
                   r.Unit AS RealUnit, r.ItemCode AS RealCode
            FROM CMS.dbo.Item i
            LEFT JOIN CMS.dbo.Item r ON r.Item = i.ReplacedBy
            WHERE i.ItemCode = ? AND i.Context IN ('NAME', 'PP', 'SUNDRY')", [$code]);
        if ($rows === []) {
            return ['error' => "Item \"{$code}\" was not found in CMS."];
        }
        $pickBy = static function (string $ctx) use ($rows): ?array {
            foreach ($rows as $r) {
                if (trim((string) $r['Context']) === $ctx) {
                    return $r;
                }
            }
            return null;
        };
        $name = $pickBy('NAME');
        if ($name !== null && $name['ReplacedBy'] !== null) {
            return [
                'item'        => (int) $name['ReplacedBy'],
                'itemName'    => (int) $name['Item'],
                'code'        => (string) $name['ItemCode'],
                'description' => self::blankToNull($name['Description']),
                'unit'        => trim((string) ($name['RealUnit'] ?? $name['Unit'] ?? '')),
            ];
        }
        $real = $pickBy('PP') ?? $pickBy('SUNDRY');
        if ($real === null) {
            return ['error' => "Item \"{$code}\" is a name that is not linked to an inventory item."];
        }
        $aliases = $cms->fetchAll("
            SELECT a.Item, a.ItemCode, a.Description
            FROM CMS.dbo.Item a
            WHERE a.Context = 'NAME' AND a.ReplacedBy = ?", [(int) $real['Item']]);
        if (count($aliases) > 1) {
            return ['error' => "Item \"{$code}\" is sold under more than one name ("
                . implode(', ', array_map(static fn ($a) => (string) $a['ItemCode'], $aliases))
                . '). Enter the name this customer orders by.'];
        }
        $alias = $aliases[0] ?? null;
        return [
            'item'        => (int) $real['Item'],
            'itemName'    => $alias !== null ? (int) $alias['Item'] : (int) $real['Item'],
            'code'        => (string) ($alias['ItemCode'] ?? $real['ItemCode']),
            'description' => self::blankToNull($alias !== null ? $alias['Description'] : $real['Description']),
            'unit'        => trim((string) ($real['Unit'] ?? '')),
        ];
    }

    /**
     * The customer's price-list row for an item, when there is one with a
     * single price break. (Rows with quantity breaks are left for a typed price.)
     *
     * @return null|array{priceDetail:int,priceVersion:int,currency:string,minOrder1:string,price1:string}
     */
    public static function priceListRow(CmsReader $cms, int $customer, int $item, int $itemName, \DateTimeInterface $on): ?array
    {
        $row = $cms->fetch("
            SELECT TOP 1 pd.PriceDetail, pd.PriceVersion, pd.Currency,
                   CONVERT(VARCHAR(40), CONVERT(DECIMAL(19,4), pd.MinOrder1)) AS MinOrder1,
                   CONVERT(VARCHAR(30), pd.Price1, 2) AS Price1, pd.MinOrder2
            FROM CMS.dbo.PriceVersion pv
            JOIN CMS.dbo.PriceDetail pd ON pd.PriceVersion = pv.PriceVersion
            WHERE pv.Entity = ? AND pv.EffectiveDate <= ? AND pd.InvItem = ? AND pd.Item = ?
              AND pv.EffectiveDate = (SELECT MAX(x.EffectiveDate) FROM CMS.dbo.PriceVersion x
                                      WHERE x.Entity = pv.Entity AND x.EffectiveDate <= ?)
            ORDER BY pd.PriceDetail", [$customer, $on->format('Y-m-d H:i:s'), $item, $itemName, $on->format('Y-m-d H:i:s')]);
        if ($row === null || $row['MinOrder2'] !== null || $row['Price1'] === null || $row['MinOrder1'] === null) {
            return null;
        }
        return [
            'priceDetail'  => (int) $row['PriceDetail'],
            'priceVersion' => (int) $row['PriceVersion'],
            'currency'     => trim((string) ($row['Currency'] ?? '')),
            'minOrder1'    => self::plain($row['MinOrder1']),
            'price1'       => trim((string) $row['Price1']),
        ];
    }

    /** Another order of the same bill-to that already carries this PO number, or null. */
    public static function duplicatePo(CmsReader $cms, int $billTo, string $poNumber, int $exceptOrdr = 0): ?int
    {
        if (trim($poNumber) === '') {
            return null;
        }
        $row = $cms->fetch("
            SELECT TOP 1 o.Ordr
            FROM CMS.dbo.Ordr o
            WHERE o.Context = 'SH' AND o.BillTo = ? AND o.PoNumber = ? AND o.Ordr <> ?
            ORDER BY o.Ordr DESC", [$billTo, trim($poNumber), $exceptOrdr]);
        return $row === null ? null : (int) $row['Ordr'];
    }

    /** "12.5000" → "12.5", "4.0000" → "4": numbers the way the client writes them. */
    public static function plain(mixed $v): string
    {
        $s = trim((string) $v);
        if ($s === '' || !is_numeric($s)) {
            return '0';
        }
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return ($s === '' || $s === '-0' || $s === '-') ? '0' : $s;
    }

    private static function hasContext(string $list, string $ctx): bool
    {
        // Entity.Context is a comma-terminated list ("SH,", "WH,SH,", "BTSH,"); BTSH = bill-to that also ships.
        $tokens = array_map('trim', explode(',', $list));
        return in_array($ctx, $tokens, true) || ($ctx === 'SH' && in_array('BTSH', $tokens, true));
    }

    private static function blankToNull(mixed $v): ?string
    {
        $s = (string) ($v ?? '');
        return trim($s) === '' ? null : $s;
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || (is_string($v) && strcasecmp($v, 'true') === 0);
    }
}

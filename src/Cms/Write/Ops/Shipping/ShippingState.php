<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Shipping;

use PII\Cms\Write\CmsReader;

/**
 * What CMS currently holds for the shipping flows: the containers
 * assembled for an order, an order's freight details and ship-to address,
 * and a waybill with its to-do list. The Shipping screens use the same
 * readers, so what the user sees is what the flow plans from.
 */
final class ShippingState
{
    private const ADDRESS_COLUMNS = 'a.Address, a.Name, a.Department, a.AddrLine1, a.AddrLine2, a.AddrLine3, a.City, a.State,
                   a.ZipCode, a.Country, a.Contact, a.Email, a.Fax, a.Phone, a.StateName, a.CountryName, a.URL,
                   a.Residential, a.EmergencyContact';

    /**
     * Containers assembled for an order and not yet shipped, with what is
     * in them. CMS's own label view is the source: it lists every active
     * assembly with its order and weight.
     *
     * @return list<array{ordr:int,location:int,code:string,version:int,lb:float,contents:list<string>}>
     */
    public static function assemblies(CmsReader $cms, int $ordr): array
    {
        $out = [];
        foreach ($cms->fetchAll("
            SELECT s.ContainerId, s.Container, s.WeightLb, s.ItemCode, l.Version /* assembled */
            FROM CMS.dbo.ShippingLabel s
            JOIN CMS.dbo.Location l ON l.Location = s.ContainerId
            WHERE s.Waybill IS NULL AND s.Ordr = ?
            ORDER BY s.ContainerId, s.ItemCode", [$ordr]) as $r) {
            $id = (int) $r['ContainerId'];
            $out[$id] ??= ['ordr' => $ordr, 'location' => $id, 'code' => (string) $r['Container'],
                           'version' => (int) $r['Version'], 'lb' => 0.0, 'contents' => []];
            $out[$id]['lb'] = max($out[$id]['lb'], (float) ($r['WeightLb'] ?? 0));
            $item = trim((string) ($r['ItemCode'] ?? ''));
            if ($item !== '' && !in_array($item, $out[$id]['contents'], true)) {
                $out[$id]['contents'][] = $item;
            }
        }
        return array_values($out);
    }

    /**
     * Orders that have something assembled and waiting to ship.
     *
     * @return list<array{Ordr:int,ShipToName:string,PoNumber:string,DateRequired:string,Containers:int}>
     */
    public static function readyOrders(CmsReader $cms): array
    {
        $out = [];
        foreach ($cms->fetchAll("
            SELECT s.Ordr, MAX(s.ShipToName) AS ShipToName, MAX(s.PoNumber) AS PoNumber,
                   CONVERT(VARCHAR(10), MAX(o.DateRequired), 23) AS DateRequired,
                   COUNT(DISTINCT s.ContainerId) AS Containers /* ready to ship */
            FROM CMS.dbo.ShippingLabel s
            JOIN CMS.dbo.Ordr o ON o.Ordr = s.Ordr
            WHERE s.Waybill IS NULL AND s.Ordr IS NOT NULL AND o.Context = 'SH' AND o.Status = 'RTS'
            GROUP BY s.Ordr
            ORDER BY s.Ordr") as $r) {
            $out[] = ['Ordr' => (int) $r['Ordr'], 'ShipToName' => (string) ($r['ShipToName'] ?? ''),
                      'PoNumber' => (string) ($r['PoNumber'] ?? ''), 'DateRequired' => (string) ($r['DateRequired'] ?? ''),
                      'Containers' => (int) $r['Containers']];
        }
        return $out;
    }

    /**
     * The freight details a waybill copies from its (first) order.
     *
     * @return array<string,string|int|null>
     */
    public static function orderFreight(CmsReader $cms, int $ordr): array
    {
        $r = $cms->fetch("
            SELECT o.ShipVia, o.FreightService, o.FreightOption, o.FreightAccount, o.PoNumber, o.DeliveryConfirmation /* freight */
            FROM CMS.dbo.Ordr o WHERE o.Ordr = ?", [$ordr]) ?? [];
        $text = static fn ($v): ?string => ($v === null || trim((string) $v) === '') ? null : (string) $v;
        return [
            'ShipVia'              => isset($r['ShipVia']) ? (int) $r['ShipVia'] : null,
            'FreightService'       => $text($r['FreightService'] ?? null),
            // The client sends 0 when the order has none.
            'FreightOption'        => $text($r['FreightOption'] ?? null) ?? '0',
            'FreightAccount'       => $text($r['FreightAccount'] ?? null),
            'PoNumber'             => $text($r['PoNumber'] ?? null),
            'DeliveryConfirmation' => isset($r['DeliveryConfirmation']) ? (int) $r['DeliveryConfirmation'] : null,
        ];
    }

    /** @return null|array<string,string|int> an order's ship-to address row, empty columns as '' */
    public static function orderAddress(CmsReader $cms, int $ordr): ?array
    {
        return self::address($cms->fetch('
            SELECT ' . self::ADDRESS_COLUMNS . "
            FROM CMS.dbo.AddressRef a WHERE a.TableName = 'Ordr' AND a.Reference = 'ShipToAddress' AND a.TableID = ?", [$ordr]));
    }

    /**
     * A waybill with its orders, containers and to-do list.
     *
     * @return null|array{
     *   Waybill:int, Version:int, Status:string, DateShipped:string, Address:?int, ShipToName:string,
     *   orders:list<array{ordr:int,invoice:string,changeSet:int,trans:?int}>,
     *   containers:list<array{location:int,code:string,labelAddress:?int}>,
     *   todo:list<array{id:int,done:bool,action:string,ordr:string,freight:string}>
     * }
     */
    public static function waybill(CmsReader $cms, int $waybill): ?array
    {
        $h = $cms->fetch("
            SELECT w.Waybill, w.Version, w.Status, CONVERT(VARCHAR(19), w.DateShipped, 126) AS DateShipped,
                   a.Address, a.Name AS ShipToName
            FROM CMS.dbo.Waybill w
            LEFT JOIN CMS.dbo.AddressRef a ON a.TableName = 'Waybill' AND a.Reference = 'ShipToAddress' AND a.TableID = w.Waybill
            WHERE w.Waybill = ?", [$waybill]);
        if ($h === null) {
            return null;
        }
        $orders = [];
        foreach ($cms->fetchAll("
            SELECT cs.Ordr, cs.ChangeSet, cs.Trans, t.TransDocument /* waybill shipments */
            FROM CMS.dbo.ChangeSetShipment csh
            JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = csh.ChangeSet
            LEFT JOIN CMS.dbo.Trans t ON t.Trans = cs.Trans
            WHERE csh.Waybill = ? AND cs.Context = 'SH'
            ORDER BY cs.ChangeSet", [$waybill]) as $r) {
            $orders[] = ['ordr' => (int) $r['Ordr'], 'invoice' => (string) ($r['TransDocument'] ?? ''),
                         'changeSet' => (int) $r['ChangeSet'], 'trans' => $r['Trans'] === null ? null : (int) $r['Trans']];
        }
        $containers = [];
        foreach ($cms->fetchAll("
            SELECT t.Location, l.LocationCode, r.Address AS LabelAddress /* waybill containers */
            FROM CMS.dbo.WaybillTrackingNumber t
            LEFT JOIN CMS.dbo.Location l ON l.Location = t.Location
            LEFT JOIN CMS.dbo.AddressReference r ON r.TableName = 'Location' AND r.Reference = 'ShipToAddress' AND r.TableID = t.Location
            WHERE t.Waybill = ?
            ORDER BY t.WaybillTrackingNumber", [$waybill]) as $r) {
            $containers[] = ['location' => (int) $r['Location'], 'code' => (string) ($r['LocationCode'] ?? ''),
                             'labelAddress' => $r['LabelAddress'] === null ? null : (int) $r['LabelAddress']];
        }
        return [
            'Waybill'     => (int) $h['Waybill'],
            'Version'     => (int) $h['Version'],
            'Status'      => trim((string) ($h['Status'] ?? '')),
            'DateShipped' => (string) ($h['DateShipped'] ?? ''),
            'Address'     => $h['Address'] === null ? null : (int) $h['Address'],
            'ShipToName'  => (string) ($h['ShipToName'] ?? ''),
            'orders'      => $orders,
            'containers'  => $containers,
            'todo'        => self::todo($cms, $waybill),
        ];
    }

    /** Current Version of a waybill, read immediately before a request that names it. */
    public static function waybillVersion(CmsReader $cms, int $waybill): ?int
    {
        $r = $cms->fetch('SELECT w.Version /* waybill version */ FROM CMS.dbo.Waybill w WHERE w.Waybill = ?', [$waybill]);
        return $r === null ? null : (int) $r['Version'];
    }

    /**
     * A waybill's to-do list in the order CMS wrote it.
     *
     * @return list<array{id:int,done:bool,action:string,ordr:string,freight:string}>
     */
    public static function todo(CmsReader $cms, int $waybill): array
    {
        $out = [];
        foreach ($cms->fetchAll("
            SELECT p.WaybillInProcess, p.IsDone, p.Ordr, p.Action, CONVERT(VARCHAR(30), p.FreightCharge, 2) AS FreightCharge
            FROM CMS.dbo.WaybillInProcess p WHERE p.Waybill = ?
            ORDER BY p.WaybillInProcess", [$waybill]) as $r) {
            $out[] = [
                'id'      => (int) $r['WaybillInProcess'],
                'done'    => in_array($r['IsDone'], [1, '1', true], true),
                'action'  => trim((string) ($r['Action'] ?? '')),
                'ordr'    => $r['Ordr'] === null ? '' : (string) (int) $r['Ordr'],
                'freight' => ($r['FreightCharge'] === null || trim((string) $r['FreightCharge']) === '')
                    ? '' : ShippingRequests::money(trim((string) $r['FreightCharge'])),
            ];
        }
        return $out;
    }

    /**
     * Waybills CMS started but did not finish (to-do rows still open).
     *
     * @return list<array{Waybill:int,Open:int}>
     */
    public static function unfinished(CmsReader $cms): array
    {
        $out = [];
        foreach ($cms->fetchAll("
            SELECT p.Waybill, COUNT(*) AS OpenRows /* unfinished waybills */
            FROM CMS.dbo.WaybillInProcess p
            JOIN CMS.dbo.Waybill w ON w.Waybill = p.Waybill
            WHERE p.IsDone = 0 AND (w.Status IS NULL OR w.Status NOT IN ('CMP', 'REJ'))
            GROUP BY p.Waybill ORDER BY p.Waybill") as $r) {
            $out[] = ['Waybill' => (int) $r['Waybill'], 'Open' => (int) $r['OpenRows']];
        }
        return $out;
    }

    /** @return null|array<string,string|int> */
    private static function address(?array $row): ?array
    {
        if ($row === null || $row['Address'] === null) {
            return null;
        }
        $out = [];
        foreach ($row as $name => $value) {
            if ($name === 'Address') {
                $out[$name] = (int) $value;
            } elseif ($name === 'Residential') {
                $out[$name] = $value === null ? '' : (in_array($value, [1, '1', true], true) ? 'true' : 'false');
            } else {
                $out[$name] = $value === null ? '' : (string) $value;
            }
        }
        return $out;
    }
}

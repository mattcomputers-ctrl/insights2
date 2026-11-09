<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\WriteRefused;

/** What the customer flows read from CMS before planning a request. */
final class EntityState
{
    /**
     * A customer (bill-to account or ship-to) by its code, with its address.
     *
     * @return array{entity:int,code:string,name:string,parent:?int,version:int,isBillTo:bool,isShipTo:bool,
     *               inactive:bool,shipVia:?int,freightAccount:string,address:array<string,string>,hasAddress:bool}
     */
    public static function customer(CmsReader $cms, mixed $code): array
    {
        $text = trim((string) (is_scalar($code) ? $code : ''));
        if ($text === '') {
            throw new WriteRefused('Choose the customer.');
        }
        $e = $cms->fetch("
            SELECT e.Entity, e.EntityCode, n.EntityName, e.Parent, e.Version, e.IsBillTo, e.IsShipTo,
                   COALESCE(e.Inactive, 0) AS Inactive, e.ShipVia, e.FreightAccount
            FROM CMS.dbo.Entity e
            LEFT JOIN CMS.dbo.Entities n ON n.Entity = e.Entity
            WHERE e.EntityCode = ? /* customer */", [$text]);
        if ($e === null || (!self::truthy($e['IsBillTo']) && !self::truthy($e['IsShipTo']))) {
            throw new WriteRefused("Customer {$text} was not found in CMS.");
        }
        $a = $cms->fetch("
            SELECT a.Name, a.AddrLine1, a.AddrLine2, a.AddrLine3, a.City, a.State, a.ZipCode, a.Country, a.Email, a.Phone
            FROM CMS.dbo.AddressRef a
            WHERE a.TableName = 'Entity' AND a.TableID = ? AND a.Reference = 'Address' /* customer */", [(int) $e['Entity']]);
        $address = [];
        foreach (['Name', 'AddrLine1', 'AddrLine2', 'AddrLine3', 'City', 'State', 'ZipCode', 'Country', 'Email', 'Phone'] as $field) {
            $address[$field] = trim((string) ($a[$field] ?? ''));
        }
        return [
            'entity'         => (int) $e['Entity'],
            'code'           => trim((string) $e['EntityCode']),
            'name'           => trim((string) ($e['EntityName'] ?? '')),
            'parent'         => ($e['Parent'] === null || (int) $e['Parent'] === 0) ? null : (int) $e['Parent'],
            'version'        => (int) $e['Version'],
            'isBillTo'       => self::truthy($e['IsBillTo']),
            'isShipTo'       => self::truthy($e['IsShipTo']),
            'inactive'       => self::truthy($e['Inactive']),
            'shipVia'        => $e['ShipVia'] === null ? null : (int) $e['ShipVia'],
            'freightAccount' => trim((string) ($e['FreightAccount'] ?? '')),
            'address'        => $address,
            'hasAddress'     => $a !== null,
        ];
    }

    /** The CMS screen a change to this customer comes from. */
    public static function program(array $customer): string
    {
        return $customer['isBillTo'] ? EntityRequests::CMS_BILL_TO : EntityRequests::CMS_SHIP_TO;
    }

    /** True when no entity of any kind has the code (codes are unique across customers, suppliers and carriers). */
    public static function codeFree(CmsReader $cms, string $code): bool
    {
        return $cms->fetch('SELECT e.Entity FROM CMS.dbo.Entity e WHERE e.EntityCode = ? /* code in use */', [$code]) === null;
    }

    /**
     * An active carrier by its entity number.
     *
     * @return array{entity:int,name:string}
     */
    public static function carrier(CmsReader $cms, mixed $id): array
    {
        $n = is_scalar($id) && ctype_digit(trim((string) $id)) ? (int) trim((string) $id) : 0;
        if ($n <= 0) {
            throw new WriteRefused('Choose the carrier.');
        }
        $c = $cms->fetch("
            SELECT e.Entity, n.EntityName, e.IsShipVia, COALESCE(e.Inactive, 0) AS Inactive
            FROM CMS.dbo.Entity e
            LEFT JOIN CMS.dbo.Entities n ON n.Entity = e.Entity
            WHERE e.Entity = ? /* carrier */", [$n]);
        if ($c === null || !self::truthy($c['IsShipVia'])) {
            throw new WriteRefused('That carrier was not found in CMS.');
        }
        if (self::truthy($c['Inactive'])) {
            throw new WriteRefused('That carrier is inactive in CMS.');
        }
        return ['entity' => (int) $c['Entity'], 'name' => trim((string) ($c['EntityName'] ?? ''))];
    }

    /** True when CMS knows the two-letter state or province code. */
    public static function stateKnown(CmsReader $cms, string $state): bool
    {
        return $cms->fetch('SELECT s.State FROM CMS.dbo.State s WHERE s.State = ?', [$state]) !== null;
    }

    private static function truthy(mixed $v): bool
    {
        return in_array($v, [1, '1', true, 'true', 'True'], true);
    }
}

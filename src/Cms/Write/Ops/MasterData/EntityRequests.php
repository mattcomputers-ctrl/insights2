<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for customers (CMS: Bill To Update and Ship To
 * Update). Shapes traced from the CMS log; see
 * docs/research/inventory-masterdata.md section 6 and the fixtures under
 * tests/fixtures/cms-requests/EntityUpdateDataSet.SaveChanges.
 *
 * A customer is a row of Entity. A bill-to account is its own parent; a
 * ship-to hangs under a bill-to through Parent. The address is kept
 * through the AddressRef view, keyed by the entity.
 */
final class EntityRequests
{
    public const PROGRAM     = 'Insights Customers';
    public const SAVE        = 'EntityUpdateDataSet.SaveChanges';
    public const CMS_SHIP_TO = 'Ship To Update';
    public const CMS_BILL_TO = 'Bill To Update';

    /** The address fields that can be sent, in the order the client sends them. */
    public const ADDRESS = ['Name', 'AddrLine1', 'AddrLine2', 'City', 'State', 'ZipCode', 'Email', 'Phone'];

    /** The shipping columns of a customer that can be changed, in the order the client sends them. */
    public const SHIPPING = ['ShipVia', 'FreightAccount'];

    /** The document rows every new customer gets, in the order the client sends them. */
    private const DOCUMENTS = ['W', 'I', 'P', 'S', 'C', 'N'];

    /**
     * A new ship-to under a bill-to account.
     *
     * @param array<string,string> $address fields of ADDRESS; Name, AddrLine1, City, State and ZipCode are needed,
     *                                      the others are left out when blank
     */
    public static function shipToCreate(string $code, int|string $parent, int|string $shipVia, string $freightAccount, array $address): Request
    {
        foreach (['Name', 'AddrLine1', 'City', 'State', 'ZipCode'] as $needed) {
            if (trim((string) ($address[$needed] ?? '')) === '') {
                throw new \InvalidArgumentException("A new ship-to needs {$needed}.");
            }
        }
        $entity = [
            'EntityCode' => $code, 'IsSupplier' => 'false', 'IsManufacturer' => 'false', 'IsSite' => 'false', 'IsLab' => 'false',
            'IsWarehouse' => 'false', 'IsShipVia' => 'false', 'IsDivision' => 'false', 'IsInstallation' => 'false', 'IsCMS' => 'false',
            'Parent' => $parent, 'IsShipTo' => 'true', 'IsBillTo' => 'false', 'IsPriceList' => 'false', 'IsSalesman' => 'false',
            'SdsOnChange' => 'false', 'NoBill' => 'false', 'Prototype' => 'false', 'ShipVia' => $shipVia, 'Inactive' => 'false',
            'CancelBackOrder' => 'false',
        ];
        if ($freightAccount !== '') {
            $entity['FreightAccount'] = $freightAccount;
        }
        $entity += [
            'PoRequired' => 'false', 'ShipComplete' => 'false', 'DoNotShip' => 'false', 'SendMSDS' => 'false',
            'SendCertificateOfAnalysis' => 'false', 'SendCertificateOfOrigin' => 'false', 'ShowCountryOfOrigin' => 'false',
            'PackingListRequired' => 'false', 'IsRetain' => 'false',
        ];

        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_SHIP_TO);
        $first = $r->add(Step::insert('Entity')->keys(['Entity' => Step::IDENTITY, 'Version' => ''])->data($entity));
        foreach (self::DOCUMENTS as $type) {
            $r->add(Step::insert('EntityDocument')->keys(['EntityDocument' => Step::IDENTITY])
                ->subs($first, 'Entity', 'Entity')
                ->data(['Type' => $type, 'Order' => 'false', 'BillTo' => 'false', 'Print' => 'false']));
        }
        $data = [];
        foreach (self::ADDRESS as $field) {
            $value = (string) ($address[$field] ?? '');
            if ($value !== '') {
                $data[$field] = $value;
            }
        }
        $data['Residential'] = 'false';
        $r->add(Step::insert('AddressRef')->keys(['TableName' => 'Entity', 'Reference' => 'Address'])
            ->subs($first, 'Entity', 'Entity', 'keys', 'TableID')
            ->data($data));
        return $r;
    }

    /**
     * Change how a customer ships: the carrier, the freight account, or both.
     *
     * @param array<string,int|string> $changes only the changed columns of SHIPPING
     */
    public static function shippingUpdate(int|string $entity, int|string $version, array $changes, string $cmsProgram): Request
    {
        $r = new Request(self::PROGRAM, self::SAVE, $cmsProgram);
        $r->add(Step::update('Entity')->keys(['Entity' => $entity, 'Version' => $version])
            ->data(self::ordered(self::SHIPPING, $changes, 'A shipping change')));
        return $r;
    }

    /**
     * Change the address of a customer.
     *
     * @param array<string,string> $changes only the changed fields of ADDRESS; an empty string clears a field
     */
    public static function addressUpdate(int|string $entity, int|string $version, array $changes, string $cmsProgram): Request
    {
        $r = new Request(self::PROGRAM, self::SAVE, $cmsProgram);
        $r->add(Step::updateVersion('Entity')->keys(['Entity' => $entity, 'Version' => $version]));
        $r->add(Step::update('AddressRef')->keys(['TableID' => $entity, 'TableName' => 'Entity', 'Reference' => 'Address'])
            ->data(self::ordered(self::ADDRESS, $changes, 'An address change')));
        return $r;
    }

    /**
     * @param list<string>             $columns
     * @param array<string,int|string> $changes
     * @return array<string,int|string>
     */
    private static function ordered(array $columns, array $changes, string $what): array
    {
        $data = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $changes)) {
                $data[$column] = $changes[$column];
            }
        }
        if ($data === [] || count($data) !== count($changes)) {
            throw new \InvalidArgumentException("{$what} needs one or more of: " . implode(', ', $columns) . '.');
        }
        return $data;
    }
}

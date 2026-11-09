<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for supplier price lists (CMS: PriceVersion
 * Update). Shapes traced from the CMS log; see
 * docs/research/inventory-masterdata.md section 7 and the fixtures under
 * tests/fixtures/cms-requests/PriceVersionUpdateDataSet.SaveChanges.
 *
 * A price list is a PriceVersion (supplier and effective date) with one
 * PriceDetail per item and package. Every change to a line also bumps the
 * version of the list it is on.
 */
final class PriceListRequests
{
    public const PROGRAM     = 'Insights Price Lists';
    public const SAVE        = 'PriceVersionUpdateDataSet.SaveChanges';
    public const CMS_PROGRAM = 'PriceVersion Update';

    /** The columns of a line that can be changed, in the order of the insert. */
    public const EDITABLE = ['Price1', 'EntityItemCode'];

    /**
     * Change the price of a line, the supplier's own code for it, or both.
     *
     * @param array<string,string> $changes only the changed columns of EDITABLE
     */
    public static function lineUpdate(int|string $priceVersion, int|string $listVersion, int|string $priceDetail,
        int|string $lineVersion, array $changes): Request
    {
        $data = [];
        foreach (self::EDITABLE as $column) {
            if (array_key_exists($column, $changes)) {
                $data[$column] = $changes[$column];
            }
        }
        if ($data === [] || count($data) !== count($changes)) {
            throw new \InvalidArgumentException('A price list change needs one or more of: ' . implode(', ', self::EDITABLE) . '.');
        }
        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_PROGRAM);
        $r->add(Step::updateVersion('PriceVersion')->keys(['PriceVersion' => $priceVersion, 'Version' => $listVersion]));
        $r->add(Step::update('PriceDetail')->keys(['PriceDetail' => $priceDetail, 'Version' => $lineVersion])->data($data));
        return $r;
    }

    /**
     * Add an item to an existing price list, at one price.
     *
     * @param array{item:int|string,description:string,unit:string,minOrder:string,price:string,currency:string,
     *              pkgType?:int|string|null,perPack?:?string,supplierCode?:string} $line
     *        pkgType and perPack go together: both for an item sold by the package, neither otherwise
     */
    public static function lineInsert(int|string $priceVersion, int|string $listVersion, array $line): Request
    {
        $pkg     = $line['pkgType'] ?? null;
        $perPack = $line['perPack'] ?? null;
        if (($pkg === null || $pkg === '') !== ($perPack === null || $perPack === '')) {
            throw new \InvalidArgumentException('A package type and the quantity per package go together.');
        }
        foreach (['description', 'unit', 'minOrder', 'price', 'currency'] as $needed) {
            if (trim((string) ($line[$needed] ?? '')) === '') {
                throw new \InvalidArgumentException("A new price list line needs {$needed}.");
            }
        }
        $data = ['PriceVersion' => $priceVersion, 'Item' => $line['item']];
        if ($pkg !== null && $pkg !== '') {
            $data['PkgType'] = $pkg;
        }
        $data['Description'] = $line['description'];
        if ($pkg !== null && $pkg !== '') {
            $data['EntityQuantity'] = $perPack;
        }
        $data += [
            'EntityUnit'     => $line['unit'],
            'PriceByPackage' => 'false',
            'MinOrder1'      => $line['minOrder'],
            'Price1'         => $line['price'],
            'Currency'       => $line['currency'],
            'InvItem'        => $line['item'],
        ];
        if (($line['supplierCode'] ?? '') !== '') {
            $data['EntityItemCode'] = $line['supplierCode'];
        }

        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_PROGRAM);
        $r->add(Step::updateVersion('PriceVersion')->keys(['PriceVersion' => $priceVersion, 'Version' => $listVersion]));
        $r->add(Step::insert('PriceDetail')->keys(['PriceDetail' => Step::IDENTITY, 'Version' => ''])->data($data));
        return $r;
    }
}

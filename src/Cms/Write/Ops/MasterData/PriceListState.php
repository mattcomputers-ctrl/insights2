<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Ops\Purchasing\PurchasingState;
use PII\Cms\Write\WriteRefused;

/** What the price list flows read from CMS before planning a request. */
final class PriceListState
{
    /**
     * The price list of a supplier that is in effect now: the one purchase
     * orders take their prices from.
     *
     * @return null|array{priceVersion:int,version:int,effective:string,later:?string}
     *         later is the date of a list that takes over in the future, if there is one
     */
    public static function current(CmsReader $cms, int $supplier, \DateTimeInterface $now): ?array
    {
        $at = $now->format('Y-m-d H:i:s');
        $v  = $cms->fetch("
            SELECT TOP 1 pv.PriceVersion, pv.Version, CONVERT(VARCHAR(10), pv.EffectiveDate, 120) AS EffectiveDate
            FROM CMS.dbo.PriceVersion pv
            WHERE pv.Entity = ? AND pv.EffectiveDate <= ? /* current price list */
            ORDER BY pv.EffectiveDate DESC", [$supplier, $at]);
        if ($v === null) {
            return null;
        }
        $later = $cms->fetch("
            SELECT TOP 1 CONVERT(VARCHAR(10), pv.EffectiveDate, 120) AS EffectiveDate
            FROM CMS.dbo.PriceVersion pv
            WHERE pv.Entity = ? AND pv.EffectiveDate > ? /* later price list */
            ORDER BY pv.EffectiveDate", [$supplier, $at]);
        return [
            'priceVersion' => (int) $v['PriceVersion'],
            'version'      => (int) $v['Version'],
            'effective'    => trim((string) $v['EffectiveDate']),
            'later'        => $later === null ? null : trim((string) $later['EffectiveDate']),
        ];
    }

    /**
     * The current price list of a supplier, or a refusal when it has none.
     *
     * @return array{priceVersion:int,version:int,effective:string,later:?string}
     */
    public static function currentOrRefuse(CmsReader $cms, array $supplier, \DateTimeInterface $now): array
    {
        $list = self::current($cms, $supplier['entity'], $now);
        if ($list === null) {
            throw new WriteRefused("Supplier {$supplier['code']} has no price list in effect. Start its first price list in CMS.");
        }
        return $list;
    }

    /**
     * The lines of a price list, by item code.
     *
     * @return list<array{priceDetail:int,version:int,item:int,itemCode:string,description:string,supplierCode:string,
     *                    pkgType:?int,pkgCode:string,perPack:?float,unit:string,byPackage:bool,currency:string,
     *                    minOrder:?float,price:?string,tiered:bool}>
     */
    public static function lines(CmsReader $cms, int $priceVersion): array
    {
        $out = [];
        foreach ($cms->fetchAll("
            SELECT pd.PriceDetail, pd.Version, pd.InvItem, i.ItemCode, pd.Description, pd.EntityItemCode, pd.PkgType,
                   p.ItemCode AS PkgCode, CONVERT(VARCHAR(40), pd.EntityQuantity, 3) AS EntityQuantity, pd.EntityUnit,
                   pd.PriceByPackage, pd.Currency,
                   CONVERT(VARCHAR(40), pd.MinOrder1, 3) AS MinOrder1, CONVERT(VARCHAR(30), pd.Price1, 2) AS Price1,
                   CONVERT(VARCHAR(40), pd.MinOrder2, 3) AS MinOrder2, CONVERT(VARCHAR(30), pd.Price2, 2) AS Price2
            FROM CMS.dbo.PriceDetail pd
            JOIN CMS.dbo.Item i ON i.Item = pd.InvItem
            LEFT JOIN CMS.dbo.Item p ON p.Item = pd.PkgType
            WHERE pd.PriceVersion = ? /* price list lines */
            ORDER BY i.ItemCode, pd.PriceDetail", [$priceVersion]) as $r) {
            $price = trim((string) ($r['Price1'] ?? ''));
            $out[] = [
                'priceDetail'  => (int) $r['PriceDetail'],
                'version'      => (int) ($r['Version'] ?? 0),
                'item'         => (int) $r['InvItem'],
                'itemCode'     => trim((string) $r['ItemCode']),
                'description'  => trim((string) ($r['Description'] ?? '')),
                'supplierCode' => trim((string) ($r['EntityItemCode'] ?? '')),
                'pkgType'      => ($r['PkgType'] ?? null) === null ? null : (int) $r['PkgType'],
                'pkgCode'      => trim((string) ($r['PkgCode'] ?? '')),
                'perPack'      => PurchasingState::float($r['EntityQuantity'] ?? null),
                'unit'         => trim((string) ($r['EntityUnit'] ?? '')),
                'byPackage'    => in_array($r['PriceByPackage'] ?? null, [1, '1', true, 'true', 'True'], true),
                'currency'     => trim((string) ($r['Currency'] ?? '')),
                'minOrder'     => PurchasingState::float($r['MinOrder1'] ?? null),
                'price'        => $price === '' ? null : self::money($price),
                'tiered'       => ($r['MinOrder2'] ?? null) !== null || ($r['Price2'] ?? null) !== null,
            ];
        }
        return $out;
    }

    /**
     * The package types a price list line can be sold in.
     *
     * @return list<array{item:int,code:string,description:string}>
     */
    public static function packages(CmsReader $cms): array
    {
        $out = [];
        foreach ($cms->fetchAll("
            SELECT i.Item, i.ItemCode, i.Description
            FROM CMS.dbo.Item i
            WHERE i.Context = 'PACKAGE' /* package types */
            ORDER BY i.ItemCode") as $r) {
            $out[] = ['item' => (int) $r['Item'], 'code' => trim((string) $r['ItemCode']), 'description' => trim((string) ($r['Description'] ?? ''))];
        }
        return $out;
    }

    /** A money amount as people write one: at least two decimals, no trailing zeros beyond them ("11.46", "6.2856", "9.00"). */
    public static function money(string $v): string
    {
        $v = trim($v);
        if (!is_numeric($v)) {
            return $v;
        }
        $s = number_format((float) $v, 4, '.', '');
        return preg_replace('/0{1,2}$/', '', $s) ?? $s;
    }

    /** What a line is sold in, for people: "DRUM of 440 lb" or "by the lb". */
    public static function pack(array $line): string
    {
        if ($line['pkgType'] === null || $line['perPack'] === null) {
            return 'by the ' . ($line['unit'] !== '' ? $line['unit'] : 'unit');
        }
        return trim(($line['pkgCode'] !== '' ? $line['pkgCode'] : 'package') . ' of ' . PurchasingState::dbl($line['perPack']) . ' ' . $line['unit']);
    }
}

<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Purchasing;

use PII\Cms\Write\CmsReader;

/**
 * What CMS holds about received lines that are waiting for a supplier bill,
 * and the money arithmetic of a bill. Money is kept as whole ten-thousandths
 * (CMS stores four decimals), so nothing is rounded by accident.
 */
final class BillState
{
    /** A bill dated more than this many days back needs a signature in CMS. */
    public const INVOICE_DATE_LIMIT_DAYS = 21;

    /**
     * Received lines that are not on a bill yet, oldest receipt first within
     * each supplier. Pass a supplier to read only that supplier's lines.
     *
     * @return list<array{billDetail:int,supplier:int,supplierCode:string,supplierName:string,ordr:int,receipt:int,
     *                    received:string,item:string,description:string,packingSlip:string,qty:?float,unit:string,
     *                    amount:int,currency:string,landingFactor:string,pending:bool,terms:string}>
     */
    public static function unbilled(CmsReader $cms, ?int $supplier = null): array
    {
        $rows = $cms->fetchAll("
            SELECT /* unbilled receipts */ d.BillDetail, d.Supplier, d.SupplierCode, n.EntityName, d.Ordr, d.Receipt,
                   CONVERT(VARCHAR(19), d.DateReceived, 120) AS DateReceived, d.Item, d.Description, d.PackingSlip,
                   CONVERT(VARCHAR(40), d.Qty, 3) AS Qty, d.Unit, CONVERT(VARCHAR(30), d.Amount, 2) AS Amount,
                   d.Currency, d.LandingFactor, COALESCE(d.Pending, 0) AS Pending, o.Terms
            FROM CMS.dbo.vwBillEntry_BillDetail d
            JOIN CMS.dbo.Ordr o ON o.Ordr = d.Ordr
            LEFT JOIN CMS.dbo.Entities n ON n.Entity = d.Supplier
            WHERE d.Bill IS NULL" . ($supplier === null ? '' : ' AND d.Supplier = ?') . "
            ORDER BY d.SupplierCode, d.BillDetail", $supplier === null ? [] : [$supplier]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'billDetail'    => (int) $r['BillDetail'],
                'supplier'      => (int) $r['Supplier'],
                'supplierCode'  => trim((string) ($r['SupplierCode'] ?? '')),
                'supplierName'  => trim((string) ($r['EntityName'] ?? '')),
                'ordr'          => (int) $r['Ordr'],
                'receipt'       => (int) $r['Receipt'],
                'received'      => substr((string) ($r['DateReceived'] ?? ''), 0, 10),
                'item'          => trim((string) ($r['Item'] ?? '')),
                'description'   => trim((string) ($r['Description'] ?? '')),
                'packingSlip'   => trim((string) ($r['PackingSlip'] ?? '')),
                'qty'           => PurchasingState::float($r['Qty'] ?? null),
                'unit'          => trim((string) ($r['Unit'] ?? '')),
                'amount'        => self::units((string) ($r['Amount'] ?? '0')) ?? 0,
                'currency'      => trim((string) ($r['Currency'] ?? '')),
                'landingFactor' => trim((string) ($r['LandingFactor'] ?? '')),
                'pending'       => in_array($r['Pending'] ?? 0, [1, '1', true, 'true', 'True'], true),
                'terms'         => trim((string) ($r['Terms'] ?? '')),
            ];
        }
        return $out;
    }

    /** True when the supplier already has a bill with this invoice number. */
    public static function invoiceExists(CmsReader $cms, int $supplier, string $invoice): bool
    {
        return $cms->fetch("
            SELECT /* bill by invoice */ TOP 1 b.Bill
            FROM CMS.dbo.Bill b
            WHERE b.Supplier = ? AND b.Invoice = ?", [$supplier, $invoice]) !== null;
    }

    /**
     * Money text ("1261.65", "-0.07", "3474.2920") as whole ten-thousandths,
     * or null when it is not a plain number with at most $decimals decimals.
     */
    public static function units(string $text, int $decimals = 4): ?int
    {
        $t = str_replace(',', '', trim($text));
        if (!preg_match('/^(-?)(\d{1,11})?(?:\.(\d*))?$/', $t, $m) || (($m[2] ?? '') === '' && ($m[3] ?? '') === '')) {
            return null;
        }
        $frac = $m[3] ?? '';
        if (strlen(rtrim($frac, '0')) > $decimals) {
            return null;
        }
        $n = (int) (($m[2] ?? '') === '' ? '0' : $m[2]) * 10000 + (int) str_pad(substr($frac, 0, 4), 4, '0');
        return $m[1] === '-' ? -$n : $n;
    }

    /** Round ten-thousandths to whole cents, halves away from zero, as CMS rounds money. */
    public static function cents(int $units): int
    {
        $abs = intdiv(abs($units) + 50, 100) * 100;
        return $units < 0 ? -$abs : $abs;
    }

    /** Ten-thousandths as the client writes money: "1323", "1261.65", "-0.07". */
    public static function text(int $units): string
    {
        $abs  = abs($units);
        $frac = rtrim(str_pad((string) ($abs % 10000), 4, '0', STR_PAD_LEFT), '0');
        $s    = intdiv($abs, 10000) . ($frac === '' ? '' : '.' . $frac);
        return ($units < 0 ? '-' : '') . $s;
    }

    /** Ten-thousandths for people: always two decimals, more only when used ("1,323.00", "3,474.292"). */
    public static function show(int $units): string
    {
        $abs  = abs($units);
        $frac = str_pad((string) ($abs % 10000), 4, '0', STR_PAD_LEFT);
        $frac = strlen(rtrim($frac, '0')) <= 2 ? substr($frac, 0, 2) : rtrim($frac, '0');
        return ($units < 0 ? '-' : '') . number_format(intdiv($abs, 10000)) . '.' . $frac;
    }
}

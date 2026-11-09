<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Shipping;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Fmt;

/**
 * What an invoice correction needs to know about an invoice: its header as
 * CMS holds it, its lines, its note and the shipment record pointing at it.
 */
final class InvoiceState
{
    /**
     * One customer invoice, or null when there is no such invoice.
     *
     * @return ?array<string,mixed>
     */
    public static function invoice(CmsReader $cms, int $trans): ?array
    {
        $h = $cms->fetch("
            SELECT /* invoice head */ t.Trans, t.Version, t.Owner, CONVERT(VARCHAR(10), t.TransDate, 23) AS TransDate,
                   CONVERT(VARCHAR(19), t.DocumentDate, 126) AS DocumentDate, t.Salesman, t.TransDocument, t.Ordr, t.Entity, t.BillTo,
                   t.Currency, CONVERT(VARCHAR(30), t.FreightCharge, 2) AS FreightCharge, t.Tax1Group, t.Tax2Group, t.Tax3Group,
                   t.PoNumber, t.ReversedTrans, t.Division, CONVERT(VARCHAR(40), t.CurrencyRate, 3) AS CurrencyRate,
                   (SELECT COUNT(*) FROM CMS.dbo.Trans r WHERE r.ReversedTrans = t.Trans) AS Reversals,
                   e.EntityName
            FROM CMS.dbo.Trans t
            LEFT JOIN CMS.dbo.Entities e ON e.Entity = t.Entity
            WHERE t.Trans = ? AND t.Context = 'CI'", [$trans]);
        if ($h === null) {
            return null;
        }
        $lines = [];
        foreach ($cms->fetchAll("
            SELECT /* invoice lines */ d.TransDetail, d.Context, d.OrdDetail, d.Item, i.ItemCode,
                   CONVERT(VARCHAR(30), d.Price, 2) AS Price, CONVERT(VARCHAR(40), d.Qty, 3) AS Qty, d.Unit,
                   CONVERT(VARCHAR(40), d.QtyEntered, 3) AS QtyEntered, CONVERT(VARCHAR(40), d.ItemQtyPerQty, 3) AS ItemQtyPerQty,
                   CASE WHEN d.Tax1Amount IS NULL AND d.Tax2Amount IS NULL AND d.Tax3Amount IS NULL THEN 0 ELSE 1 END AS Taxed,
                   CASE WHEN d.Comment IS NULL THEN 0 ELSE 1 END AS Commented
            FROM CMS.dbo.TransDetail d
            LEFT JOIN CMS.dbo.Item i ON i.Item = d.Item
            WHERE d.Trans = ?
            ORDER BY d.TransDetail", [$trans]) as $r) {
            $lines[] = [
                'context'   => trim((string) ($r['Context'] ?? '')),
                'ordDetail' => $r['OrdDetail'] === null ? null : (int) $r['OrdDetail'],
                'item'      => $r['Item'] === null ? null : (int) $r['Item'],
                'itemCode'  => (string) ($r['ItemCode'] ?? ''),
                'price'     => $r['Price'] === null ? null : (string) $r['Price'],
                'qty'       => $r['Qty'] === null ? null : (float) $r['Qty'],
                'unit'      => (string) ($r['Unit'] ?? ''),
                'plain'     => $r['QtyEntered'] === null && $r['ItemQtyPerQty'] !== null && (float) $r['ItemQtyPerQty'] === 1.0
                               && (int) $r['Taxed'] === 0 && (int) $r['Commented'] === 0,
            ];
        }
        $memos = $cms->fetchAll("
            SELECT /* invoice memo */ m.Memo, CONVERT(NVARCHAR(MAX), m.Data) AS Data
            FROM CMS.dbo.MemoRef m
            WHERE m.TableName = 'Trans' AND m.Reference = 'InvoiceMemo' AND m.TableID = ?", [$trans]);
        $sets = $cms->fetchAll("
            SELECT /* invoice shipment */ cs.ChangeSet, cs.Version, cs.Context
            FROM CMS.dbo.ChangeSet cs
            WHERE cs.Trans = ?", [$trans]);

        return [
            'trans'        => (int) $h['Trans'],
            'version'      => (int) $h['Version'],
            'owner'        => trim((string) ($h['Owner'] ?? '')),
            'transDate'    => (string) ($h['TransDate'] ?? ''),
            'documentDate' => (string) ($h['DocumentDate'] ?? ''),
            'salesman'     => $h['Salesman'] === null ? null : (int) $h['Salesman'],
            'document'     => trim((string) ($h['TransDocument'] ?? '')),
            'ordr'         => $h['Ordr'] === null ? null : (int) $h['Ordr'],
            'entity'       => $h['Entity'] === null ? null : (int) $h['Entity'],
            'customer'     => (string) ($h['EntityName'] ?? ''),
            'billTo'       => $h['BillTo'] === null ? null : (int) $h['BillTo'],
            'currency'     => trim((string) ($h['Currency'] ?? '')),
            'freight'      => $h['FreightCharge'] === null ? null : (string) $h['FreightCharge'],
            'taxed'        => $h['Tax1Group'] !== null || $h['Tax2Group'] !== null || $h['Tax3Group'] !== null,
            'poNumber'     => $h['PoNumber'] === null ? null : (string) $h['PoNumber'],
            'reversal'     => $h['ReversedTrans'] !== null,
            'reversed'     => (int) $h['Reversals'] > 0,
            'division'     => $h['Division'] !== null,
            'rate'         => $h['CurrencyRate'] === null ? null : (float) $h['CurrencyRate'],
            'lines'        => $lines,
            'memos'        => array_map(static fn (array $m): array => ['memo' => (int) $m['Memo'], 'data' => (string) ($m['Data'] ?? '')], $memos),
            'shipments'    => array_map(static fn (array $s): array => ['changeSet' => (int) $s['ChangeSet'], 'version' => (int) $s['Version'],
                                  'context' => trim((string) ($s['Context'] ?? ''))], $sets),
        ];
    }

    /**
     * Why this invoice cannot be corrected here, or null when it can. Only
     * the plain kind is handled: US dollars, no tax, every line a shipped
     * order line, one note and one shipment record.
     *
     * @param array<string,mixed> $inv
     */
    public static function blocker(array $inv): ?string
    {
        $n = $inv['document'] !== '' ? "Invoice {$inv['document']}" : 'This invoice';
        if ($inv['reversal']) {
            return "{$n} is the reversal of an earlier invoice.";
        }
        if ($inv['reversed']) {
            return "{$n} has been reversed. Open the invoice that replaced it.";
        }
        $inClient = ' Correct it in CMS.';
        if (strcasecmp($inv['currency'], 'USD') !== 0 || ($inv['rate'] !== null && $inv['rate'] !== 1.0)) {
            return "{$n} is not a plain US dollar invoice." . $inClient;
        }
        if ($inv['taxed']) {
            return "{$n} carries tax." . $inClient;
        }
        if ($inv['division'] || $inv['owner'] === '' || $inv['document'] === '' || $inv['transDate'] === '' || $inv['documentDate'] === ''
            || $inv['ordr'] === null || $inv['entity'] === null || $inv['billTo'] === null || $inv['poNumber'] === null) {
            return "{$n} is missing details this page relies on (order, bill-to or PO number)." . $inClient;
        }
        if ($inv['lines'] === []) {
            return "{$n} has no lines." . $inClient;
        }
        if (count($inv['lines']) > SetInvoiceFreight::MAX_LINES) {
            return "{$n} has more than " . SetInvoiceFreight::MAX_LINES . ' lines.' . $inClient;
        }
        foreach ($inv['lines'] as $l) {
            if ($l['context'] !== 'SH' || $l['ordDetail'] === null || $l['item'] === null || $l['price'] === null || $l['qty'] === null
                || $l['qty'] <= 0.0 || $l['unit'] === '' || !$l['plain']) {
                return "{$n} has a line that is not an ordinary shipped order line." . $inClient;
            }
        }
        if (count($inv['memos']) !== 1) {
            return "{$n} does not have exactly one invoice note." . $inClient;
        }
        if (count($inv['shipments']) !== 1 || $inv['shipments'][0]['context'] !== 'SH') {
            return "{$n} is not tied to exactly one shipment." . $inClient;
        }
        return null;
    }

    /** A quantity as the client prints it ("600", "98.8"). */
    public static function qty(float $q): string
    {
        return Fmt::num($q);
    }
}

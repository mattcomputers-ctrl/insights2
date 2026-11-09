<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Shipping;

use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request template for correcting an issued invoice. CMS never edits
 * an invoice in place: one request reverses it and issues it again under the
 * same invoice number. Shape traced from the CMS log; see
 * docs/research/sales-shipping.md section 7 and the fixtures under
 * tests/fixtures/cms-requests/InvoiceDataSet.SaveChanges.
 *
 * The template owns the step order, the attribute order and the constants;
 * callers only supply values.
 */
final class InvoiceRequests
{
    public const PROGRAM     = 'Insights Shipping';
    public const CMS_PROGRAM = 'Invoice';
    public const SAVE        = 'InvoiceDataSet.SaveChanges';

    /**
     * Reverse an invoice and issue it again with a (possibly) different
     * freight charge. Everything else is carried over unchanged.
     *
     * @param array{trans:int|string,version:int|string,owner:string,transDate:string,salesman:int|string|null,document:string,
     *        documentDate:string,ordr:int|string,entity:int|string,billTo:int|string,currency:string,freight:?string,poNumber:string} $invoice
     *        transDate is Y-m-d; documentDate is Y-m-dTH:i:s; freight is the charge the invoice holds now, as CMS stores it ("338.2600"), null for none
     * @param list<array{ordDetail:int|string,item:int|string,price:string,qty:string,unit:string}> $lines qty positive, as the client prints it
     * @param ?string $newFreight the freight charge of the re-issued invoice, null for none
     * @param array{memo:int|string,data:string} $memo the invoice note carried over
     * @param array{changeSet:int|string,version:int|string} $shipment the shipment record that points at the invoice
     */
    public static function reissue(array $invoice, array $lines, ?string $newFreight, array $memo, array $shipment): Request
    {
        foreach (['trans', 'version', 'owner', 'transDate', 'document', 'documentDate', 'ordr', 'entity', 'billTo', 'currency'] as $k) {
            if (trim((string) ($invoice[$k] ?? '')) === '') {
                throw new \InvalidArgumentException("An invoice correction needs {$k}.");
            }
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoice['transDate'])) {
            throw new \InvalidArgumentException('The invoice date must be Y-m-d.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $invoice['documentDate'])) {
            throw new \InvalidArgumentException('The document date must be Y-m-dTH:i:s.');
        }
        if (!array_key_exists('poNumber', $invoice) || !is_string($invoice['poNumber'])) {
            throw new \InvalidArgumentException('An invoice correction needs the PO number.');
        }
        if ($lines === []) {
            throw new \InvalidArgumentException('An invoice correction needs at least one line.');
        }
        $old = $invoice['freight'] ?? null;
        if ($old !== null && (!preg_match('/^\d+\.\d{4}$/', $old))) {
            throw new \InvalidArgumentException('The current freight charge must be money with four decimals.');
        }
        foreach ($lines as $l) {
            if (!preg_match('/^\d+(\.\d+)?$/', (string) $l['qty']) || (float) $l['qty'] <= 0.0) {
                throw new \InvalidArgumentException('An invoice line needs a positive quantity.');
            }
        }

        $head = static function (?string $freight, bool $reversal) use ($invoice): array {
            $d = [
                'Context'   => 'CI',
                'TransDate' => $invoice['transDate'] . 'T00:00:00',
                'Owner'     => $invoice['owner'],
            ];
            if (($invoice['salesman'] ?? null) !== null && (string) $invoice['salesman'] !== '') {
                $d['Salesman'] = (string) $invoice['salesman'];
            }
            $d += [
                'TransDocument' => $invoice['document'],
                'DocumentDate'  => $invoice['documentDate'],
                'Ordr'          => (string) $invoice['ordr'],
                'Entity'        => (string) $invoice['entity'],
                'BillTo'        => (string) $invoice['billTo'],
                'Currency'      => $invoice['currency'],
            ];
            if ($freight !== null) {
                $d['FreightCharge'] = $freight;
            }
            $d['PoNumber'] = $invoice['poNumber'];
            if ($reversal) {
                $d['ReversedTrans'] = (string) $invoice['trans'];
            }
            return $d;
        };
        $detail = static function (array $l, bool $reversal, int $from): Step {
            return Step::insert('TransDetail')->keys(['TransDetail' => Step::IDENTITY])->subs($from, 'Trans', 'Trans')->data([
                'Context'       => 'SH',
                'OrdDetail'     => (string) $l['ordDetail'],
                'Item'          => (string) $l['item'],
                'Price'         => $l['price'],
                'Qty'           => ($reversal ? '-' : '') . $l['qty'],
                'Unit'          => $l['unit'],
                'ItemQtyPerQty' => '1',
            ]);
        };

        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_PROGRAM);
        $r->add(Step::updateVersion('Trans')->keys(['Trans' => (string) $invoice['trans'], 'Version' => (string) $invoice['version']]));
        $rev = $r->add(Step::insert('Trans')->keys(['Trans' => Step::IDENTITY, 'Version' => '1'])
            ->data($head($old === null ? null : '-' . $old, true)));
        foreach ($lines as $l) {
            $r->add($detail($l, true, $rev));
        }
        $new = $r->add(Step::insert('Trans')->keys(['Trans' => Step::IDENTITY, 'Version' => '1'])->data($head($newFreight, false)));
        foreach ($lines as $l) {
            $r->add($detail($l, false, $new));
        }
        $r->add(Step::insert('MemoRef')->keys(['TableName' => 'Trans', 'Reference' => 'InvoiceMemo'])
            ->subs($new, 'Trans', 'Trans', 'keys', 'TableID')
            ->data(['Memo' => (string) $memo['memo'], 'Data' => $memo['data']]));
        $r->add(Step::execute('UpdateInvoiceTax')->subs($new, 'Trans', 'Trans'));
        $r->add(Step::update('ChangeSet')->keys(['ChangeSet' => (string) $shipment['changeSet'], 'Version' => (string) $shipment['version']])
            ->subs($new, 'Trans', 'Trans'));
        $r->add(Step::execute('CheckInvoiceQty')->subs($new, 'Trans', 'Trans'));
        $r->add(Step::execute('UpdateShipmentPoNumber')->subs($new, 'Trans', 'Trans')->data(['PoNumber' => $invoice['poNumber']]));
        return $r;
    }
}

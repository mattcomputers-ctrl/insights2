<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Purchasing;

use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request template for entering a supplier bill against received
 * purchase order lines. Shape traced from the CMS log; see
 * docs/research/purchasing.md section 6 and the fixtures under
 * tests/fixtures/cms-requests/BillUpdateDataSet.SaveChanges.
 *
 * The template owns the step order, the attribute order and the constants;
 * callers only supply values.
 */
final class BillRequests
{
    public const PROGRAM     = 'Insights Purchasing';
    public const CMS_PROGRAM = 'Bill Entry (Inventory)';
    public const SAVE        = 'BillUpdateDataSet.SaveChanges';

    /**
     * A new bill covering received lines.
     *
     * Each line is ['billDetail' => id] plus, when an extra cost was entered
     * for it, 'addCost' and (when the amount CMS holds has to be rounded to
     * cents first) 'amount'. An amount is never sent without an extra cost.
     *
     * @param array{supplier:int|string,invoice:string,invoiceDate:string,terms:string,amount:string,currency:string} $bill
     *        invoiceDate is Y-m-d
     * @param list<array{billDetail:int|string,amount?:?string,addCost?:?string}> $lines
     */
    public static function create(array $bill, array $lines): Request
    {
        foreach (['supplier', 'invoice', 'invoiceDate', 'terms', 'amount', 'currency'] as $k) {
            if (trim((string) ($bill[$k] ?? '')) === '') {
                throw new \InvalidArgumentException("A bill needs {$k}.");
            }
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bill['invoiceDate'])) {
            throw new \InvalidArgumentException('The invoice date must be Y-m-d.');
        }
        if ($lines === []) {
            throw new \InvalidArgumentException('A bill needs at least one received line.');
        }

        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_PROGRAM);
        $head = $r->add(Step::insert('Bill')->keys(['Bill' => Step::IDENTITY])->data([
            'Supplier'      => (string) $bill['supplier'],
            'LandingFactor' => 'Inventory',
            'Invoice'       => $bill['invoice'],
            'InvoiceDate'   => $bill['invoiceDate'] . 'T00:00:00',
            'Terms'         => $bill['terms'],
            'Amount'        => $bill['amount'],
            'Currency'      => $bill['currency'],
            'CurrencyRate'  => '1',
        ]));
        foreach ($lines as $line) {
            $amount  = $line['amount'] ?? null;
            $addCost = $line['addCost'] ?? null;
            if ($amount !== null && $addCost === null) {
                throw new \InvalidArgumentException('A line amount is only sent together with an extra cost.');
            }
            $step = Step::update('BillDetail')->keys(['BillDetail' => (string) $line['billDetail']])->subs($head, 'Bill', 'Bill');
            $data = [];
            if ($amount !== null) {
                $data['Amount'] = $amount;
            }
            if ($addCost !== null) {
                $data['AddCost'] = $addCost;
            }
            if ($data !== []) {
                $step->data($data);
            }
            $r->add($step);
        }
        $r->add(Step::execute('UpdateBillCost')->subs($head, 'Bill', 'Bill'));
        return $r;
    }
}

<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Shipping;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Ops\Purchasing\BillState;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Put a freight charge on an issued invoice, or change the one it has.
 * CMS reverses the invoice and issues it again under the same number with
 * the new freight charge; nothing else on it changes.
 *
 * Input: trans (int, the invoice's CMS number), freight (the freight charge
 * the invoice should carry, in dollars and cents).
 */
final class SetInvoiceFreight implements Flow
{
    public const MAX_LINES   = 40;
    /** Ten-thousandths: no freight charge above this is accepted here. */
    public const MAX_FREIGHT = 500000000;
    /** An invoice older than this gets a note that the books may already hold it. */
    public const OLD_DAYS    = 31;

    public function plan(array $input, FlowContext $ctx): Plan
    {
        $id  = (int) (is_scalar($input['trans'] ?? null) ? $input['trans'] : 0);
        $inv = $id > 0 ? InvoiceState::invoice($ctx->cms, $id) : null;
        if ($inv === null) {
            throw new WriteRefused('That invoice was not found in CMS.');
        }
        $blocker = InvoiceState::blocker($inv);
        if ($blocker !== null) {
            throw new WriteRefused($blocker);
        }

        $new = BillState::units(trim((string) (is_scalar($input['freight'] ?? null) ? $input['freight'] : '')), 2);
        if ($new === null || $new < 0) {
            throw new WriteRefused('Enter the freight charge in dollars and cents.');
        }
        $old = $inv['freight'] === null ? null : BillState::units($inv['freight']);
        if ($inv['freight'] !== null && ($old === null || $old < 0)) {
            throw new WriteRefused("Invoice {$inv['document']} holds a freight charge this page cannot read. Correct it in CMS.");
        }
        if ($new === 0) {
            throw new WriteRefused(($old ?? 0) === 0 ? 'Enter the freight charge to put on the invoice.'
                : 'Taking the freight charge off an invoice is done in CMS.');
        }
        if ($new === $old) {
            throw new WriteRefused("Invoice {$inv['document']} already carries " . BillState::show($new) . ' freight.');
        }
        if ($new > self::MAX_FREIGHT) {
            throw new WriteRefused('That freight charge is larger than this page accepts. Check the amount, or correct the invoice in CMS.');
        }

        $lines = [];
        foreach ($inv['lines'] as $l) {
            $lines[] = ['ordDetail' => $l['ordDetail'], 'item' => $l['item'], 'price' => $l['price'],
                        'qty' => InvoiceState::qty($l['qty']), 'unit' => $l['unit']];
        }
        $request = InvoiceRequests::reissue([
            'trans'        => $inv['trans'],
            'version'      => $inv['version'],
            'owner'        => $inv['owner'],
            'transDate'    => $inv['transDate'],
            'salesman'     => $inv['salesman'],
            'document'     => $inv['document'],
            'documentDate' => $inv['documentDate'],
            'ordr'         => $inv['ordr'],
            'entity'       => $inv['entity'],
            'billTo'       => $inv['billTo'],
            'currency'     => $inv['currency'],
            'freight'      => $inv['freight'],
            'poNumber'     => $inv['poNumber'],
        ], $lines, BillState::text($new), $inv['memos'][0], $inv['shipments'][0]);

        $summary = [
            ['Invoice', $inv['document'] . ', dated ' . $inv['transDate'] . ($inv['customer'] !== '' ? ", to {$inv['customer']}" : '')],
            ['Order', "{$inv['ordr']}" . ($inv['poNumber'] !== '' ? ", PO {$inv['poNumber']}" : '')],
            ['Freight', ($old === null || $old === 0 ? 'none' : BillState::show($old)) . ' becomes ' . BillState::show($new) . ' USD'],
            ['Lines', count($lines) . (count($lines) === 1 ? ' line' : ' lines') . ', carried over unchanged'],
        ];
        $warnings = ["CMS reverses invoice {$inv['document']} and issues it again under the same number. Send the customer the corrected copy."];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $inv['transDate']);
        if ($date !== false && (int) $date->diff($ctx->now->setTime(0, 0))->days > self::OLD_DAYS && $date < $ctx->now) {
            $warnings[] = "This invoice is more than " . self::OLD_DAYS . ' days old. Check that the books can still take a correction.';
        }

        return new Plan(
            Sequence::single("Set the freight on invoice {$inv['document']}", static fn () => $request),
            $summary,
            $warnings,
            'Trans',
            (string) $inv['trans'],
        );
    }
}

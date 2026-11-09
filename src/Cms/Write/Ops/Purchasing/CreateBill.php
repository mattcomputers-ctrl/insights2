<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Purchasing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Enter a supplier's invoice against received purchase order lines.
 *
 * Input: supplier (int, the supplier's CMS number), invoice (the supplier's
 * invoice number), invoice_date (Y-m-d), total (what the invoice comes to),
 * lines (list of received-line numbers), add_cost (map of received-line
 * number to an extra cost for that line: freight, a surcharge or a rounding
 * difference; may be negative; blank for none).
 *
 * The total typed from the invoice has to equal what the chosen lines and
 * their extra costs come to; that is the check that the right receipts were
 * ticked.
 */
final class CreateBill implements Flow
{
    public const MAX_LINES = 40;

    public function plan(array $input, FlowContext $ctx): Plan
    {
        $entity = (int) (is_scalar($input['supplier'] ?? null) ? $input['supplier'] : 0);
        if ($entity <= 0) {
            throw new WriteRefused('Choose the supplier.');
        }
        $supplier = PurchasingState::supplier($ctx->cms, $entity);
        if ($supplier === null) {
            throw new WriteRefused("Supplier {$entity} was not found in CMS.");
        }
        $name = $supplier['name'] !== '' ? $supplier['name'] : $supplier['code'];
        if (strcasecmp($supplier['currency'], 'USD') !== 0) {
            throw new WriteRefused("{$name} is paid in " . ($supplier['currency'] !== '' ? $supplier['currency'] : 'an unknown currency')
                . '. Enter this bill in CMS.');
        }

        $invoice = trim((string) (is_scalar($input['invoice'] ?? null) ? $input['invoice'] : ''));
        if ($invoice === '') {
            throw new WriteRefused("Enter the supplier's invoice number.");
        }
        if (strlen($invoice) > 20 || preg_match('/[\x00-\x1F]/', $invoice)) {
            throw new WriteRefused('The invoice number is too long: CMS holds 20 characters.');
        }

        $date  = self::date($input['invoice_date'] ?? null);
        $today = $ctx->now->setTime(0, 0);
        if ($date > $today) {
            throw new WriteRefused('The invoice date is in the future.');
        }
        $age = (int) $date->diff($today)->days;
        if ($age > BillState::INVOICE_DATE_LIMIT_DAYS) {
            throw new WriteRefused('The invoice is dated ' . $date->format('Y-m-d') . ", {$age} days ago. A bill dated more than "
                . BillState::INVOICE_DATE_LIMIT_DAYS . ' days back needs a signature: enter it in CMS.');
        }

        $ids = [];
        foreach (is_array($input['lines'] ?? null) ? $input['lines'] : [] as $v) {
            if (is_scalar($v) && ctype_digit(trim((string) $v)) && (int) $v > 0) {
                $ids[(int) $v] = true;
            } else {
                throw new WriteRefused('One of the chosen received lines is not valid.');
            }
        }
        if ($ids === []) {
            throw new WriteRefused('Tick the received lines this invoice covers.');
        }
        if (count($ids) > self::MAX_LINES) {
            throw new WriteRefused('A bill can cover at most ' . self::MAX_LINES . ' received lines here.');
        }
        ksort($ids);

        $waiting = [];
        foreach (BillState::unbilled($ctx->cms, $entity) as $l) {
            $waiting[$l['billDetail']] = $l;
        }
        $extra = is_array($input['add_cost'] ?? null) ? $input['add_cost'] : [];
        $lines = [];
        $shown = [];
        $sum   = 0;
        $terms = null;
        $mixed = false;
        foreach (array_keys($ids) as $id) {
            $l = $waiting[$id] ?? null;
            if ($l === null) {
                throw new WriteRefused("Received line {$id} is not waiting for a bill from {$name}. It may have been billed already.");
            }
            if ($l['landingFactor'] !== 'Inventory') {
                throw new WriteRefused("Received line {$id} is not an ordinary stock receipt. Enter this bill in CMS.");
            }
            if ($l['pending']) {
                throw new WriteRefused("Received line {$id} is marked pending in CMS. Enter this bill in CMS.");
            }
            $what = "{$l['item']} on PO {$l['ordr']}";
            $add  = self::addCost($extra[$id] ?? ($extra[(string) $id] ?? null), $what);
            $cents = BillState::cents($l['amount']);
            $line  = ['billDetail' => $id];
            if ($add !== null) {
                if ($cents !== $l['amount']) {
                    $line['amount'] = BillState::text($cents);
                }
                $line['addCost'] = BillState::text($add);
            }
            $lines[] = $line;
            $sum    += $cents + ($add ?? 0);
            $shown[] = ['Received line', $what . ($l['packingSlip'] !== '' ? ", packing slip {$l['packingSlip']}" : '')
                . ($l['received'] !== '' ? ", received {$l['received']}" : '') . ': ' . BillState::show($cents)
                . ($add !== null ? ' and ' . BillState::show($add) . ' extra cost' : '')];
            if ($terms === null) {
                $terms = $l['terms'];
            } elseif ($terms !== $l['terms']) {
                $mixed = true;
            }
        }
        if ($terms === null || $terms === '') {
            $terms = $supplier['terms'];
        }
        if ($terms === '') {
            throw new WriteRefused("Neither the purchase order nor {$name} has payment terms. Enter this bill in CMS.");
        }

        $total = BillState::units(trim((string) (is_scalar($input['total'] ?? null) ? $input['total'] : '')), 2);
        if ($total === null) {
            throw new WriteRefused('Enter the total of the invoice, in dollars and cents.');
        }
        if ($total !== $sum) {
            $diff = $total - $sum;
            throw new WriteRefused('The invoice total ' . BillState::show($total) . ' is not what the ticked lines come to, '
                . BillState::show($sum) . '. The invoice is ' . BillState::show(abs($diff)) . ($diff > 0 ? ' more' : ' less')
                . ': tick the right lines, or put the difference on a line as an extra cost (freight, a surcharge, a credit).');
        }
        if (BillState::invoiceExists($ctx->cms, $entity, $invoice)) {
            throw new WriteRefused("{$name} already has a bill for invoice {$invoice}.");
        }

        $summary = array_merge([
            ['Supplier', $name . ($supplier['code'] !== '' && $supplier['code'] !== $name ? " ({$supplier['code']})" : '')],
            ['Invoice', $invoice . ', dated ' . $date->format('Y-m-d')],
            ['Terms', $terms],
        ], $shown, [
            ['Bill total', BillState::show($sum) . ' USD'],
        ]);
        $warnings = ['The cost of the received stock is updated to what this bill says.'];
        if ($mixed) {
            $warnings[] = "The ticked lines come from purchase orders with different payment terms. The bill takes {$terms}, from the first line.";
        }
        if ($supplier['inactive']) {
            $warnings[] = "{$name} is inactive in CMS.";
        }

        $request = BillRequests::create([
            'supplier'    => $entity,
            'invoice'     => $invoice,
            'invoiceDate' => $date->format('Y-m-d'),
            'terms'       => $terms,
            'amount'      => BillState::text($sum),
            'currency'    => 'USD',
        ], $lines);

        return new Plan(
            Sequence::single("Enter invoice {$invoice} from {$name}", static fn () => $request),
            $summary,
            $warnings,
            'Entity',
            (string) $entity,
        );
    }

    private static function date(mixed $v): \DateTimeImmutable
    {
        $text = trim((string) (is_scalar($v) ? $v : ''));
        if ($text === '') {
            throw new WriteRefused('Enter the date on the invoice.');
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($d === false || $d->format('Y-m-d') !== $text) {
            throw new WriteRefused('The invoice date is not a valid date.');
        }
        return $d;
    }

    /** An extra cost in ten-thousandths, or null when none was entered. */
    private static function addCost(mixed $v, string $what): ?int
    {
        $text = trim((string) (is_scalar($v) ? $v : ''));
        if ($text === '') {
            return null;
        }
        $units = BillState::units($text, 2);
        if ($units === null) {
            throw new WriteRefused("The extra cost for {$what} must be an amount in dollars and cents.");
        }
        return $units === 0 ? null : $units;
    }
}

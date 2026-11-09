<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Sales;

use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for sales orders (CMS: Shipping Order).
 * Shapes traced from the CMS log; see docs/research/sales-shipping.md and
 * the fixtures under tests/fixtures/cms-requests/ShippingOrderDataSet.*.
 *
 * Every save of a sales order is ONE request: the header step, then the
 * line / memo / address changes, then the tax recalculation and (usually)
 * the credit check. The templates own the step order, the attribute order
 * and the constants; callers only supply values.
 */
final class SalesRequests
{
    public const PROGRAM     = 'Insights Sales Orders';
    public const CMS_PROGRAM = 'Shipping Order';
    public const SAVE        = 'ShippingOrderDataSet.SaveChanges';

    /** Ordr columns in the order the client writes them. Constants on create are filled in by the template. */
    private const HEADER = [
        'Owner', 'DateCompleted', 'DateOrdered', 'DateRequired', 'Status', 'PlacedBy', 'Currency', 'ShipTo', 'BillTo',
        'ShipVia', 'Contact', 'FreightService', 'FreightOption', 'FreightAccount', 'PoNumber',
        'BasePriority', 'CancelBackOrder', 'ShipComplete', 'CreditHold', 'AllowOvercommit',
        'IncoTerms', 'Terms', 'Salesman', 'Tax1Group', 'ChangeNumber', 'IsQuote', 'ReserveAmount',
        'HeadsUpSent', 'ClearPO', 'Tax1Number', 'Tax2Number', 'DeliveryConfirmation',
    ];
    private const HEADER_REQUIRED = ['Owner', 'DateOrdered', 'DateRequired', 'Status', 'PlacedBy', 'Currency', 'ShipTo', 'BillTo', 'ChangeNumber', 'IsQuote'];
    private const HEADER_CONSTANTS = [
        'BasePriority' => '100', 'CancelBackOrder' => false, 'ShipComplete' => false, 'CreditHold' => false,
        'AllowOvercommit' => true, 'HeadsUpSent' => false, 'ClearPO' => false,
    ];

    /** OrdDetail columns a line update may change, in client order. */
    private const LINE_CHANGES = ['IsOpen', 'ManualPrice', 'Price', 'QtyReqd', 'QtyCommitted', 'Description', 'SupQty', 'SupQtyEntered'];

    private const ADDRESS = ['Address', 'Name', 'AddrLine1', 'AddrLine2', 'AddrLine3', 'City', 'State', 'ZipCode',
        'Country', 'CountryName', 'Contact', 'Email', 'Fax', 'Phone'];

    private const PRICING = ['PriceDetail', 'PriceVersion', 'Item', 'Description', 'Currency', 'EntityUnit',
        'PriceByPackage', 'MinOrder1', 'Price1', 'QtyPerEntityQty', 'EntityUnit2'];

    /**
     * Create a sales order (or a quote).
     *
     * @param array<string,string|int|bool> $header  Ordr values keyed by column (see HEADER); the fixed
     *        defaults (BasePriority, CancelBackOrder, …) are supplied here unless overridden
     * @param list<array<string,mixed>> $lines        see line()
     * @param array<string,string|int> $address       ship-to address columns (see ADDRESS); empty values are left out
     * @param null|array{memo?:?int,text:string} $internalMemo  memo = the customer's shared memo row when the text is unchanged
     * @param null|array{memo?:?int,text:string} $shippingMemo
     * @param bool $creditCheck        false only for quotes, which the client saves without a credit check
     * @param bool $duplicatePoAccepted the user acknowledged the "duplicate PO number" warning
     * @param string $copiedVersion    Version of the order this one was copied from ('' when not a copy)
     */
    public static function createOrder(
        array $header,
        array $lines,
        array $address,
        ?array $internalMemo = null,
        ?array $shippingMemo = null,
        bool $creditCheck = true,
        bool $duplicatePoAccepted = false,
        string $copiedVersion = '',
    ): Request {
        foreach (self::HEADER_REQUIRED as $name) {
            if (!array_key_exists($name, $header) || $header[$name] === '' || $header[$name] === null) {
                throw new \InvalidArgumentException("A new order needs {$name}.");
            }
        }
        $r = self::request($duplicatePoAccepted);
        $data = ['Context' => 'SH'] + self::ordered($header + self::HEADER_CONSTANTS, self::HEADER, 'order');
        $r->add(Step::insert('Ordr')->keys(['Ordr' => Step::IDENTITY, 'Version' => $copiedVersion])->data($data));

        $owner = $header['Owner'];
        foreach ($lines as $line) {
            self::addLine($r, $line, $owner, null);
        }
        if ($internalMemo !== null) {
            self::addMemo($r, 'InternalMemo', $internalMemo, null);
        }
        $r->add(Step::insert('AddressRef')
            ->keys(['TableName' => 'Ordr', 'Reference' => 'ShipToAddress'])
            ->subs(1, 'Ordr', 'Ordr', 'keys', 'TableID')
            ->data(self::ordered(self::nonEmpty($address), self::ADDRESS, 'address')));
        if ($shippingMemo !== null) {
            self::addMemo($r, 'ShippingMemo', $shippingMemo, null);
        }

        $r->add(Step::execute('UpdateOrderTaxes')->subs(1, 'Ordr', 'Ordr', 'data', null, ''));
        if ($creditCheck) {
            $r->add(Step::execute('CheckOrderCredit')->subs(1, 'Ordr', 'Ordr', 'data', null, '')
                ->data(['OldReserveAmount' => '0']));
        }
        return $r;
    }

    /**
     * Save changes to an existing order.
     *
     * @param array<string,string|int|bool> $headerChanges changed Ordr columns only; [] = no header change
     * @param list<array<string,mixed>> $changes ordered row changes, each one of
     *        ['op'=>'updateLine','ordDetail'=>int,'changes'=>[col=>value]]
     *        ['op'=>'deleteLine','ordDetail'=>int,'pricing'=>int]
     *        ['op'=>'addLine','line'=>line()]
     *        ['op'=>'addMemo'|'updateMemo','reference'=>'InternalMemo'|'ShippingMemo','text'=>string,'memo'=>?int]
     *        ['op'=>'updateAddress','changes'=>[col=>value]]
     * @param array{mode:'check',oldReserve:string}|array{mode:'release'}|array{mode:'none'} $credit
     *        check: the usual trailer, oldReserve = Ordr.ReserveAmount as read (4 decimals);
     *        release: release the order from credit hold; none: no credit step
     */
    public static function editOrder(
        int $ordr,
        int $version,
        array $headerChanges,
        array $changes,
        array $credit,
        bool $duplicatePoAccepted = false,
    ): Request {
        $r    = self::request($duplicatePoAccepted);
        $keys = ['Ordr' => $ordr, 'Version' => $version];
        if ($headerChanges === []) {
            $r->add(Step::updateVersion('Ordr')->keys($keys));
        } else {
            $r->add(Step::update('Ordr')->keys($keys)->data(self::ordered($headerChanges, self::HEADER, 'order')));
        }

        foreach ($changes as $c) {
            switch ($c['op'] ?? '') {
                case 'updateLine':
                    if (($c['changes'] ?? []) === []) {
                        throw new \InvalidArgumentException('A line update needs at least one change.');
                    }
                    $r->add(Step::update('OrdDetail')->keys(['OrdDetail' => (int) $c['ordDetail']])
                        ->data(self::ordered($c['changes'], self::LINE_CHANGES, 'order line')));
                    break;
                case 'deleteLine':
                    $r->add(Step::delete('OrdDetailPricing')->keys(['OrdDetailPricing' => (int) $c['pricing']]));
                    $r->add(Step::delete('OrdDetail')->keys(['OrdDetail' => (int) $c['ordDetail']]));
                    break;
                case 'addLine':
                    self::addLine($r, $c['line'], $c['line']['Owner'] ?? '4', $ordr);
                    break;
                case 'addMemo':
                    self::addMemo($r, (string) $c['reference'], $c, $ordr);
                    break;
                case 'updateMemo':
                    self::memoReference((string) $c['reference']);
                    $r->add(Step::update('MemoRef')
                        ->keys(['TableID' => $ordr, 'TableName' => 'Ordr', 'Reference' => (string) $c['reference']])
                        ->data(['Data' => (string) $c['text']]));
                    break;
                case 'updateAddress':
                    if (($c['changes'] ?? []) === []) {
                        throw new \InvalidArgumentException('An address update needs at least one change.');
                    }
                    $r->add(Step::update('AddressRef')
                        ->keys(['TableID' => $ordr, 'TableName' => 'Ordr', 'Reference' => 'ShipToAddress'])
                        ->data(self::ordered($c['changes'], self::ADDRESS, 'address')));
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown order change.');
            }
        }

        $r->add(Step::execute('UpdateOrderTaxes')->data(['Ordr' => $ordr]));
        switch ($credit['mode'] ?? '') {
            case 'check':
                $r->add(Step::execute('CheckOrderCredit')
                    ->data(['Ordr' => $ordr, 'OldReserveAmount' => (string) $credit['oldReserve']]));
                break;
            case 'release':
                $r->add(Step::execute('CheckOrderCredit')->data(['Ordr' => $ordr, 'Release' => true]));
                break;
            case 'none':
                break;
            default:
                throw new \InvalidArgumentException('Unknown credit mode.');
        }
        return $r;
    }

    /** ShippingOrderDataSet.CompleteSale: ship and invoice the order's open lines without a waybill. */
    public static function completeSale(int $ordr, int $version): Request
    {
        $r = new Request(self::PROGRAM, 'ShippingOrderDataSet.CompleteSale', self::CMS_PROGRAM);
        $r->add(Step::updateVersion('Ordr')->keys(['Ordr' => $ordr, 'Version' => $version]));
        $r->add(Step::execute('CreateShipmentsByOrder')->data(['Ordr' => $ordr]));
        return $r;
    }

    private static function request(bool $duplicatePoAccepted): Request
    {
        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_PROGRAM);
        if ($duplicatePoAccepted) {
            $r->event(['DataSet' => 'ShippingOrderDataSet', 'Method' => 'DuplicateCustomerPO', 'Caption' => 'Duplicate PO#']);
        }
        return $r;
    }

    /**
     * One order line = an OrdDetail insert followed by its OrdDetailPricing insert.
     *
     * @param array{isOpen:bool,manualPrice:bool,price:string,qty:string,qtyCommitted:?string,item:int,
     *              itemName:int,description:?string,unit:string,
     *              priceList?:null|array{priceDetail:int,priceVersion:int,currency:string,minOrder1:string,price1:string}} $line
     *        qtyCommitted null = leave the attribute out (quote lines)
     * @param ?int $ordr existing order, or null when the order is created in step 1 of this request
     */
    private static function addLine(Request $r, array $line, string|int $owner, ?int $ordr): void
    {
        $data = [];
        if ($ordr !== null) {
            $data['Ordr'] = $ordr;
        }
        $data += ['Context' => 'SH', 'Owner' => $owner, 'IsOpen' => (bool) $line['isOpen'],
            'ManualPrice' => (bool) $line['manualPrice'], 'Price' => (string) $line['price'], 'QtyReqd' => (string) $line['qty']];
        if (($line['qtyCommitted'] ?? null) !== null) {
            $data['QtyCommitted'] = (string) $line['qtyCommitted'];
        }
        $data['Item'] = (int) $line['item'];
        $description  = (string) ($line['description'] ?? '');
        if ($description !== '') {
            $data['Description'] = $description;
        }
        $data += ['SupQty' => (string) $line['qty'], 'SupQtyEntered' => (string) $line['qty'], 'ItemName' => (int) $line['itemName']];

        $step = Step::insert('OrdDetail')->keys(['OrdDetail' => Step::IDENTITY]);
        if ($ordr === null) {
            $step->subs(1, 'Ordr', 'Ordr', 'data');
        }
        $detailStep = $r->add($step->data($data));

        $pl      = $line['priceList'] ?? null;
        $pricing = [];
        if ($pl !== null) {
            $pricing += ['PriceDetail' => (int) $pl['priceDetail'], 'PriceVersion' => (int) $pl['priceVersion']];
        }
        $pricing['Item'] = (int) $line['item'];
        if ($description !== '') {
            $pricing['Description'] = $description;
        }
        if ($pl !== null) {
            $pricing['Currency'] = (string) $pl['currency'];
        }
        $pricing += ['EntityUnit' => (string) $line['unit'], 'PriceByPackage' => false];
        if ($pl !== null) {
            $pricing += ['MinOrder1' => (string) $pl['minOrder1'], 'Price1' => (string) $pl['price1']];
        }
        $pricing += ['QtyPerEntityQty' => '1', 'EntityUnit2' => (string) $line['unit']];
        $r->add(Step::insert('OrdDetailPricing')->keys(['OrdDetailPricing' => Step::IDENTITY])
            ->subs($detailStep, 'OrdDetail', 'OrdDetail', 'data')
            ->data(self::ordered($pricing, self::PRICING, 'line pricing')));
    }

    /** @param array{memo?:?int,text:string} $memo */
    private static function addMemo(Request $r, string $reference, array $memo, ?int $ordr): void
    {
        self::memoReference($reference);
        $data = [];
        if (($memo['memo'] ?? null) !== null) {
            $data['Memo'] = (int) $memo['memo'];
        }
        $data['Data'] = (string) $memo['text'];
        if ($ordr === null) {
            $r->add(Step::insert('MemoRef')->keys(['TableName' => 'Ordr', 'Reference' => $reference])
                ->subs(1, 'Ordr', 'Ordr', 'keys', 'TableID')->data($data));
        } else {
            $r->add(Step::insert('MemoRef')
                ->keys(['TableID' => $ordr, 'TableName' => 'Ordr', 'Reference' => $reference])->data($data));
        }
    }

    private static function memoReference(string $reference): void
    {
        if ($reference !== 'InternalMemo' && $reference !== 'ShippingMemo') {
            throw new \InvalidArgumentException("Unknown memo '{$reference}'.");
        }
    }

    /** @return array<string,mixed> */
    private static function nonEmpty(array $values): array
    {
        return array_filter($values, static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Keep only known columns, in the client's order; an unknown column is a programming error.
     *
     * @param array<string,mixed> $values
     * @param list<string> $order
     * @return array<string,mixed>
     */
    private static function ordered(array $values, array $order, string $what): array
    {
        $unknown = array_diff(array_keys($values), $order);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("Unknown {$what} column: " . implode(', ', $unknown) . '.');
        }
        $out = [];
        foreach ($order as $name) {
            if (array_key_exists($name, $values)) {
                $out[$name] = $values[$name];
            }
        }
        return $out;
    }
}

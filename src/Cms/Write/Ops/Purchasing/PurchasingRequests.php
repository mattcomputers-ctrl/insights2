<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Purchasing;

use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for purchase orders and purchase receipts.
 * Shapes traced from the CMS log; see docs/research/purchasing.md and the
 * fixtures under tests/fixtures/cms-requests/PurchaseOrderDataSet.* and
 * PurchaseReceiptDataSet.*.
 *
 * The templates own the step order, the attribute order and the constants;
 * callers only supply values.
 */
final class PurchasingRequests
{
    public const PROGRAM          = 'Insights Purchasing';
    public const CMS_ORDER        = 'Purchase Order';
    public const CMS_RECEIPT      = 'Purchase Receipt';
    public const SAVE             = 'PurchaseOrderDataSet.SaveChanges';
    public const MARK_COMPLETE    = 'PurchaseOrderDataSet.MarkComplete';
    public const RECEIPT_SAVE     = 'PurchaseReceiptDataSet.SaveChanges';
    public const RECEIPT_REVERSE  = 'PurchaseReceiptDataSet.ReverseReceipt';

    /** Where received goods land: the receiving location every logged receipt uses. */
    public const RECEIVING_LOCATION = '10669';

    /** Ordr columns in the order the client writes them. */
    private const HEADER = ['Owner', 'Entity', 'Currency', 'Terms', 'Incoterms', 'ShipVia', 'NoBill', 'PlacedBy',
        'CancelBackorder', 'DateOrdered', 'DateReleased', 'DateCompleted', 'Status'];
    private const HEADER_REQUIRED = ['Owner', 'Entity', 'Currency', 'PlacedBy', 'DateOrdered'];
    /** Columns an edit may change. */
    private const HEADER_CHANGES = ['Terms', 'Incoterms', 'ShipVia', 'NoBill', 'DateCompleted', 'Status'];

    /** OrdDetail columns a line update may change, in client order. */
    private const LINE_CHANGES = ['QtyReqd', 'SupQty', 'SupUnit', 'IsOpen', 'PercentUnder', 'PercentOver',
        'DatePromised', 'ManualPrice', 'Price', 'SupQtyEntered'];

    private const PRICING = ['PriceDetail', 'PriceVersion', 'Item', 'EntityItemCode', 'Description', 'Comment', 'Currency',
        'PkgType', 'EntityQuantity', 'EntityUnit', 'PriceByPackage',
        'MinOrder1', 'Price1', 'MinOrder2', 'Price2', 'MinOrder3', 'Price3', 'MinOrder4', 'Price4', 'MinOrder5', 'Price5',
        'QtyPerEntityQty', 'PkgType2', 'EntityQuantity2', 'EntityUnit2'];

    private const ADDRESS = ['Address', 'Name', 'AddrLine1', 'AddrLine2', 'AddrLine3', 'City', 'State', 'ZipCode',
        'Contact', 'Email', 'Fax', 'Phone', 'CountryName'];

    /**
     * Create a purchase order. CMS releases a PO as it is created, so
     * DateReleased is always sent equal to DateOrdered.
     *
     * @param array<string,string|int|bool> $header Ordr values keyed by column (Owner, Entity, Currency,
     *        PlacedBy, DateOrdered; optional Terms, Incoterms, ShipVia)
     * @param list<array<string,mixed>> $lines see addLine()
     * @param array<string,string|int> $supplierAddress the supplier's address columns; empty values are left out
     * @param array<string,string|int> $shipToAddress   our own receiving address
     * @param ?string $memo the note printed on the PO, or null for none
     */
    public static function createOrder(array $header, array $lines, array $supplierAddress, array $shipToAddress, ?string $memo = null): Request
    {
        foreach (self::HEADER_REQUIRED as $name) {
            if (!array_key_exists($name, $header) || $header[$name] === '' || $header[$name] === null) {
                throw new \InvalidArgumentException("A new purchase order needs {$name}.");
            }
        }
        if ($lines === []) {
            throw new \InvalidArgumentException('A new purchase order needs at least one line.');
        }
        $header += ['CancelBackorder' => false, 'DateReleased' => $header['DateOrdered']];

        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_ORDER);
        $r->add(Step::insert('Ordr')->keys(['Ordr' => Step::IDENTITY, 'Version' => ''])
            ->data(['Context' => 'PO'] + self::ordered($header, self::HEADER, 'purchase order')));
        foreach ($lines as $line) {
            self::addLine($r, $line, $header['Owner'], null);
        }
        $r->add(self::address('SupplierAddress', $supplierAddress));
        if ($memo !== null && $memo !== '') {
            $r->add(Step::insert('MemoRef')->keys(['TableName' => 'Ordr', 'Reference' => 'POMemo'])
                ->subs(1, 'Ordr', 'Ordr', 'keys', 'TableID')
                ->data(['Memo' => '-1', 'Data' => $memo]));
        }
        $r->add(self::address('ShipToAddress', $shipToAddress));
        return $r;
    }

    /**
     * Save changes to an existing purchase order.
     *
     * @param array<string,string|int|bool> $headerChanges changed Ordr columns only; [] = no header change
     * @param list<array<string,mixed>> $changes ordered row changes, each one of
     *        ['op'=>'updateLine','ordDetail'=>int,'changes'=>[col=>value]]
     *        ['op'=>'deleteLine','ordDetail'=>int,'pricing'=>int]
     *        ['op'=>'addLine','line'=>addLine() line,'owner'=>string]
     *        ['op'=>'addMemo'|'updateMemo','text'=>string]
     * @param string $action SAVE, or MARK_COMPLETE for the client's "mark complete" button (same shape)
     */
    public static function editOrder(int $ordr, int $version, array $headerChanges, array $changes, string $action = self::SAVE): Request
    {
        if ($action !== self::SAVE && $action !== self::MARK_COMPLETE) {
            throw new \InvalidArgumentException('Unknown purchase order action.');
        }
        $r    = new Request(self::PROGRAM, $action, self::CMS_ORDER);
        $keys = ['Ordr' => $ordr, 'Version' => $version];
        if ($headerChanges === []) {
            if ($changes === []) {
                throw new \InvalidArgumentException('A purchase order save needs at least one change.');
            }
            $r->add(Step::updateVersion('Ordr')->keys($keys));
        } else {
            $r->add(Step::update('Ordr')->keys($keys)->data(self::ordered($headerChanges, self::HEADER_CHANGES, 'purchase order')));
        }

        foreach ($changes as $c) {
            switch ($c['op'] ?? '') {
                case 'updateLine':
                    if (($c['changes'] ?? []) === []) {
                        throw new \InvalidArgumentException('A line update needs at least one change.');
                    }
                    $r->add(Step::update('OrdDetail')->keys(['OrdDetail' => (int) $c['ordDetail']])
                        ->data(self::ordered($c['changes'], self::LINE_CHANGES, 'purchase order line')));
                    break;
                case 'deleteLine':
                    $r->add(Step::delete('OrdDetailPricing')->keys(['OrdDetailPricing' => (int) $c['pricing']]));
                    $r->add(Step::delete('OrdDetail')->keys(['OrdDetail' => (int) $c['ordDetail']]));
                    break;
                case 'addLine':
                    self::addLine($r, $c['line'], $c['owner'] ?? '4', $ordr);
                    break;
                case 'addMemo':
                case 'updateMemo':
                    $step = $c['op'] === 'addMemo' ? Step::insert('MemoRef') : Step::update('MemoRef');
                    $r->add($step->keys(['TableID' => $ordr, 'TableName' => 'Ordr', 'Reference' => 'POMemo'])
                        ->data(['Data' => (string) $c['text']]));
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown purchase order change.');
            }
        }
        return $r;
    }

    /**
     * Receive one purchase order line. The client sends one request per line.
     *
     * @param string  $qty      quantity received, in the item's stock unit
     * @param string  $shipment the supplier's packing slip number
     * @param ?string $unitCost cost per stock unit for items that carry inventory cost; null for items that do not
     */
    public static function receive(
        int $ordr, int $version, string $owner, string $changeDate, string $shipment,
        int $ordDetail, int $item, string $qty, string $unit, ?string $unitCost,
    ): Request {
        $r = new Request(self::PROGRAM, self::RECEIPT_SAVE, self::CMS_RECEIPT);
        $r->add(Step::updateVersion('Ordr')->keys(['Ordr' => $ordr, 'Version' => $version]));
        $cs = $r->add(Step::execute('CreateChangeSet')->data([
            'Context' => 'PO', 'Owner' => $owner, 'Ordr' => $ordr, 'ChangeDate' => $changeDate, 'Shipment' => $shipment,
        ]));
        $r->add(Step::insert('ChangeSetReceipt')->keys(['OrdDetail' => $ordDetail])
            ->subs($cs, 'ChangeSet', 'ChangeSet', 'keys')
            ->data(['PSQty' => $qty, 'PSQtyEntered' => $qty, 'PSUnit' => $unit, 'QtyPerPSQty' => '1',
                    'NumberOfContainers' => '1', 'Item' => $item]));

        $move = Step::execute('MoveInventory')->subs($cs, 'ChangeSet', 'ChangeSet', 'data');
        $data = ['Owner' => $owner, 'Context' => 'PO', 'ValidateStorageRules' => false, 'TrgOrdDetail' => $ordDetail,
                 'Quantity' => $qty, 'QtyEntered' => $qty, 'TareEntered' => '', 'RecvdContainer' => '',
                 'TrgLocation' => self::RECEIVING_LOCATION, 'Item' => $item];
        if ($unitCost !== null) {
            $cost = $r->add(Step::execute('CreateInventoryCost')
                ->data(['Owner' => $owner, 'Item' => $item, 'QtyMK' => $qty, 'UnitCostMK' => $unitCost]));
            $move->subs($cost, 'InventoryCost', 'InventoryCost', 'data');
            $data['UnitCost'] = $unitCost;
        }
        $r->add($move->data($data));
        $r->add(Step::execute('UpdateOrdDetailQtyUsed')->data(['OrdDetail' => $ordDetail, 'QtyDelta' => $qty]));
        $r->add(Step::execute('CreateUpdateBillDetail')->subs($cs, 'ChangeSet', 'ChangeSet', 'data'));
        $r->add(Step::execute('NotifyPurchaseReceiptCreated')->subs($cs, 'ChangeSet', 'ChangeSet', 'data')
            ->data(['SubLot' => '']));
        return $r;
    }

    /**
     * Reverse a whole receipt: take the stock back out, reopen the PO line.
     *
     * @param string $qty the quantity the receipt brought in (positive); sent negated
     * @param ?int   $inventoryCost the cost record the receipt created, for items that carry cost
     */
    public static function reverseReceipt(
        int $ordr, int $version, string $owner, int $changeSet, int $changeSetVersion,
        int $ordDetail, int $item, string $qty, ?int $inventoryCost = null, ?string $unitCost = null,
    ): Request {
        if (($inventoryCost === null) !== ($unitCost === null)) {
            throw new \InvalidArgumentException('A costed reversal needs both the cost record and its unit cost.');
        }
        if (str_starts_with($qty, '-') || (float) $qty === 0.0) {
            throw new \InvalidArgumentException('The received quantity must be positive.');
        }
        $back = '-' . $qty;
        $r = new Request(self::PROGRAM, self::RECEIPT_REVERSE, self::CMS_RECEIPT);
        $r->add(Step::updateVersion('Ordr')->keys(['Ordr' => $ordr, 'Version' => $version]));
        $r->add(Step::updateVersion('ChangeSet')->keys(['ChangeSet' => $changeSet, 'Version' => $changeSetVersion]));
        $r->add(Step::update('ChangeSetReceipt')->keys(['ChangeSet' => $changeSet, 'OrdDetail' => $ordDetail])
            ->data(['PSQtyEntered' => '', 'PSQty' => '0']));
        $data = ['Owner' => $owner, 'Context' => 'PO', 'ValidateStorageRules' => false, 'TrgOrdDetail' => $ordDetail,
                 'Quantity' => $back, 'QtyEntered' => '', 'ChangeSet' => $changeSet,
                 'TrgLocation' => self::RECEIVING_LOCATION, 'Item' => $item];
        if ($inventoryCost !== null) {
            $data += ['InventoryCost' => $inventoryCost, 'UnitCost' => $unitCost];
        }
        $r->add(Step::execute('MoveInventory')->data($data));
        if ($inventoryCost !== null) {
            $r->add(Step::execute('UpdateInventoryCost')->data(['InventoryCost' => $inventoryCost, 'QtyMK' => '0']));
        }
        $r->add(Step::execute('UpdateOrdDetailQtyUsed')
            ->data(['OrdDetail' => $ordDetail, 'QtyDelta' => $back, 'ReopenOrdDetail' => true]));
        $r->add(Step::execute('CreateUpdateBillDetail')->data(['ChangeSet' => $changeSet]));
        $r->add(Step::execute('NotifyPurchaseReceiptReversed')->data(['ChangeSet' => $changeSet, 'SubLot' => '']));
        return $r;
    }

    /**
     * One PO line = an OrdDetail insert followed by its OrdDetailPricing insert.
     *
     * @param array{item:int,description:?string,qtyReqd:string,supQty:string,supUnit:string,
     *              percentUnder:string,percentOver:string,datePromised:?string,manualPrice:bool,price:string,
     *              pricing:array<string,mixed>} $line
     *        qtyReqd is in stock units, supQty in the supplier's ordering units (packages when supUnit is '');
     *        pricing is the copy of the supplier's price-list row (see PRICING), given as it should be stored
     * @param ?int $ordr existing order, or null when the order is created in step 1 of this request
     */
    private static function addLine(Request $r, array $line, string|int $owner, ?int $ordr): void
    {
        $data = [];
        if ($ordr !== null) {
            $data['Ordr'] = $ordr;
        }
        $data += ['Context' => 'PO', 'Owner' => $owner, 'Item' => (int) $line['item']];
        if ((string) ($line['description'] ?? '') !== '') {
            $data['Description'] = (string) $line['description'];
        }
        $data += ['QtyReqd' => (string) $line['qtyReqd'], 'SupQty' => (string) $line['supQty'],
                  'SupUnit' => (string) $line['supUnit'], 'IsOpen' => true,
                  'PercentUnder' => (string) $line['percentUnder'], 'PercentOver' => (string) $line['percentOver']];
        if (($line['datePromised'] ?? null) !== null) {
            $data['DatePromised'] = (string) $line['datePromised'];
        }
        $data += ['ManualPrice' => (bool) $line['manualPrice'], 'Price' => (string) $line['price'],
                  'SupQtyEntered' => (string) $line['supQty']];

        $step = Step::insert('OrdDetail')->keys(['OrdDetail' => Step::IDENTITY]);
        if ($ordr === null) {
            $step->subs(1, 'Ordr', 'Ordr', 'data');
        }
        $detailStep = $r->add($step->data($data));

        $pricing = $line['pricing'] ?? [];
        if (!isset($pricing['Item'], $pricing['EntityUnit'], $pricing['QtyPerEntityQty'])) {
            throw new \InvalidArgumentException('Line pricing needs Item, EntityUnit and QtyPerEntityQty.');
        }
        $r->add(Step::insert('OrdDetailPricing')->keys(['OrdDetailPricing' => Step::IDENTITY])
            ->subs($detailStep, 'OrdDetail', 'OrdDetail', 'data')
            ->data(self::ordered($pricing, self::PRICING, 'line pricing')));
    }

    /** @param array<string,string|int> $address */
    private static function address(string $reference, array $address): Step
    {
        $address = array_filter($address, static fn ($v) => $v !== null && $v !== '');
        if (!isset($address['Address'])) {
            throw new \InvalidArgumentException("The {$reference} needs an address.");
        }
        return Step::insert('AddressRef')->keys(['TableName' => 'Ordr', 'Reference' => $reference])
            ->subs(1, 'Ordr', 'Ordr', 'keys', 'TableID')
            ->data(self::ordered($address, self::ADDRESS, 'address'));
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

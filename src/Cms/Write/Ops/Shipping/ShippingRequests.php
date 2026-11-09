<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Shipping;

use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for shipping (CMS: Waybill, PrintShippingLabel).
 * Shapes traced from the CMS log; see docs/research/sales-shipping.md and
 * the fixtures under tests/fixtures/cms-requests/WaybillDataSet.* and
 * PrintShippingLabel.*.
 *
 * Shipping a waybill is several requests. The first creates the waybill
 * and a to-do list (WaybillInProcess rows); each later request ticks one
 * row off in the same transaction as the work it does.
 */
final class ShippingRequests
{
    public const PROGRAM       = 'Insights Shipping';
    public const CMS_PROGRAM   = 'Waybill';
    public const LABEL_PROGRAM = 'PrintShippingLabel';
    public const SAVE          = 'WaybillDataSet.SaveChanges';
    public const RESUME        = 'WaybillDataSet.ProcessOutstandingRequests';
    public const REJECT        = 'WaybillDataSet.RejectWaybill';
    public const LABEL         = 'PrintShippingLabel.UpdateShippingLabelAddress';

    public const ASSEMBLY_PROGRAM = 'Shipping Assembly';
    public const ASSEMBLE         = 'AssembleDataSet.SaveChanges';
    /** The stock location picks come from, the place new containers are created in, and their package type. */
    public const STOCK      = '10669';
    public const ASM_PARENT = '2';
    public const ASM_PKG    = '1';

    public const TODO_SHIP      = 'CreateShipmentsByOrder';
    public const TODO_PALLETS   = 'SaveShippedPallets';
    public const TODO_COMPLETE  = 'MarkCompleted';

    public const KG_PER_LB = 0.45359237;

    /** Waybill columns copied from the order, in the order the client writes them; empty ones are left out. */
    private const FROM_ORDER = ['ShipVia', 'FreightService', 'FreightOption', 'FreightAccount', 'PoNumber', 'DeliveryConfirmation'];

    /** The waybill's ship-to address row, every column, in client order. */
    private const ADDRESS = ['Address', 'Name', 'Department', 'AddrLine1', 'AddrLine2', 'AddrLine3', 'City', 'State',
        'ZipCode', 'Country', 'Contact', 'Email', 'Fax', 'Phone', 'StateName', 'CountryName', 'URL', 'Residential'];

    /** Step number of the Waybill insert in the first request. */
    public static function waybillStep(int $orders, int $containers): int
    {
        return $orders + $containers + 1;
    }

    /**
     * First request: lock the orders and their assembly containers, create
     * the waybill with its containers and ship-to address, and write the
     * to-do list.
     *
     * @param list<array{ordr:int|string,version:int|string,freight?:string}> $orders freight = freight charge to invoice ('' = none)
     * @param list<array{location:int|string,version:int|string,lb:float}> $containers assembly containers and their weight in pounds
     * @param array<string,string|int|null> $fromOrder ShipVia, FreightService, FreightOption, … of the first order
     * @param array<string,string|int|bool|null> $address the order's ship-to address row (see ADDRESS)
     * @param string $addressTableId the client fills this with a number of its own; CMS takes the real id from the Waybill step
     */
    public static function createWaybill(
        string $owner,
        string $placedBy,
        string $dateShipped,
        array $orders,
        array $containers,
        array $fromOrder,
        array $address,
        string $addressTableId = '0',
    ): Request {
        if ($orders === [] || $containers === []) {
            throw new \InvalidArgumentException('A waybill needs at least one order and one container.');
        }
        if (!isset($address['Address']) || (string) $address['Address'] === '') {
            throw new \InvalidArgumentException('A waybill needs a ship-to address.');
        }
        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_PROGRAM);
        foreach ($orders as $o) {
            $r->add(Step::updateVersion('Ordr')->keys(['Ordr' => $o['ordr'], 'Version' => $o['version']]));
        }
        foreach ($containers as $c) {
            $r->add(Step::updateVersion('Location')->keys(['Location' => $c['location'], 'Version' => $c['version']]));
        }

        $data = ['Owner' => $owner, 'PlacedBy' => $placedBy, 'DateShipped' => $dateShipped, 'ShipAsIs' => false,
                 'TrailerNumber' => '', 'SealNumbers' => '', 'Comment' => ''];
        foreach ($fromOrder as $name => $value) {
            if (!in_array($name, self::FROM_ORDER, true)) {
                throw new \InvalidArgumentException("Unknown waybill column {$name}.");
            }
        }
        foreach (self::FROM_ORDER as $name) {
            $value = $fromOrder[$name] ?? null;
            if ($value !== null && (string) $value !== '') {
                $data[$name] = $value;
            }
        }
        $wb = $r->add(Step::insert('Waybill')->keys(['Waybill' => Step::IDENTITY, 'Version' => ''])->data($data));

        foreach ($containers as $c) {
            $lb = (float) $c['lb'];
            if ($lb < 0 || is_nan($lb) || is_infinite($lb)) {
                throw new \InvalidArgumentException('A container weight must be zero or more.');
            }
            $r->add(Step::insert('WaybillTrackingNumber')->keys(['WaybillTrackingNumber' => Step::IDENTITY])
                ->subs($wb, 'Waybill', 'Waybill')
                ->data(['Location' => $c['location'], 'TrackingNumber' => '', 'FreightCost' => '',
                        'ActWeightKg' => self::dbl($lb * self::KG_PER_LB), 'ActWeightLb' => self::dbl($lb)]));
        }

        $row = [];
        foreach (self::ADDRESS as $name) {
            $row[$name] = $address[$name] ?? '';
        }
        $row += ['TableID' => $addressTableId, 'TableName' => 'Waybill', 'Reference' => 'ShipToAddress',
                 'EmergencyContact' => $address['EmergencyContact'] ?? ''];
        $r->add(Step::insert('AddressRef')->keys(['TableName' => 'Waybill', 'Reference' => 'ShipToAddress'])
            ->subs($wb, 'Waybill', 'Waybill', 'keys', 'TableID')->data($row));

        $r->add(Step::execute('CreateDocument')->subs($wb, 'Waybill', 'Waybill', 'data', 'TableID')
            ->data(['TableName' => 'Waybill', 'Role' => 'Waybill']));

        foreach ($orders as $o) {
            $todo = ['Ordr' => $o['ordr'], 'Action' => self::TODO_SHIP, 'IsShipped' => true];
            if (($o['freight'] ?? '') !== '') {
                $todo['FreightCharge'] = $o['freight'];
            }
            $r->add(Step::insert('WaybillInProcess')->subs($wb, 'Waybill', 'Waybill')->data($todo));
        }
        $r->add(Step::insert('WaybillInProcess')->subs($wb, 'Waybill', 'Waybill')->data(['Action' => self::TODO_PALLETS]));
        $r->add(Step::insert('WaybillInProcess')->subs($wb, 'Waybill', 'Waybill')->data(['Action' => self::TODO_COMPLETE]));
        return $r;
    }

    /**
     * One later request: tick a to-do row off and do its work.
     *
     * @param string $todo one of the TODO_* actions
     * @param string $ordr the order to ship (TODO_SHIP only)
     * @param string $freight the to-do row's freight charge as CMS holds it ("188.0000"), '' for none
     * @param bool $resume finishing a waybill left part way (the client names that request differently)
     */
    public static function todo(
        string $todo,
        string|int $todoId,
        string|int $waybill,
        string|int $version,
        string $ordr = '',
        string $freight = '',
        bool $resume = false,
    ): Request {
        $r = new Request(self::PROGRAM, $resume ? self::RESUME : self::SAVE, self::CMS_PROGRAM);
        $r->add(Step::update('WaybillInProcess')->keys(['WaybillInProcess' => $todoId])->data(['IsDone' => true]));
        $keys = ['Waybill' => $waybill, 'Version' => $version];
        switch ($todo) {
            case self::TODO_SHIP:
                if ($ordr === '') {
                    throw new \InvalidArgumentException('Shipping an order needs the order.');
                }
                $r->add(Step::updateVersion('Waybill')->keys($keys));
                $r->add(Step::execute(self::TODO_SHIP)
                    ->data(['Waybill' => $waybill, 'Ordr' => $ordr, 'IsShipped' => true, 'FreightCharge' => $freight]));
                break;
            case self::TODO_PALLETS:
                $r->add(Step::updateVersion('Waybill')->keys($keys));
                $r->add(Step::execute(self::TODO_PALLETS)->data(['Waybill' => $waybill]));
                break;
            case self::TODO_COMPLETE:
                $r->add(Step::update('Waybill')->keys($keys)->data(['Status' => 'CMP']));
                break;
            default:
                throw new \InvalidArgumentException("Unknown waybill to-do {$todo}.");
        }
        return $r;
    }

    /** Reverse a completed waybill: shipments and invoices are reversed, the orders go back to ready to ship. */
    public static function rejectWaybill(int $waybill, int $version): Request
    {
        $r = new Request(self::PROGRAM, self::REJECT, self::CMS_PROGRAM);
        $r->add(Step::update('Waybill')->keys(['Waybill' => $waybill, 'Version' => $version])->data(['Status' => 'REJ']));
        $r->add(Step::execute('RejectWaybill')->data(['Waybill' => $waybill]));
        return $r;
    }

    /**
     * Pick stock for order lines into a new shipping container. CMS creates
     * the container and hands its id to each move.
     *
     * @param list<array{ordDetail:int|string,item:int|string,qty:string}> $lines
     */
    public static function assembleNew(int $ordr, int $version, string $owner, array $lines): Request
    {
        $r = self::assembly($ordr, $version, $lines);
        $made = $r->add(Step::execute('CreateAssembly')->data(['Owner' => $owner, 'Location' => self::ASM_PARENT, 'PkgType' => self::ASM_PKG]));
        foreach ($lines as $l) {
            $r->add(Step::execute('MoveInventory')
                ->subs($made, 'Location', 'Location', 'data', 'TrgLocation')
                ->data(self::pick($owner, null, $l['qty'], $l['qty'], $l)));
        }
        return $r;
    }

    /**
     * Pick more stock into a container that already exists.
     *
     * @param list<array{ordDetail:int|string,item:int|string,qty:string}> $lines
     */
    public static function assembleAdd(int $ordr, int $version, string $owner, int $container, int $containerVersion, array $lines): Request
    {
        $r = self::assembly($ordr, $version, $lines);
        $r->add(Step::updateVersion('Location')->keys(['Location' => $container, 'Version' => $containerVersion]));
        foreach ($lines as $l) {
            $r->add(Step::execute('MoveInventory')->data(self::pick($owner, $container, $l['qty'], $l['qty'], $l)));
        }
        return $r;
    }

    /**
     * Put stock from a container back into the stock location. The client
     * checks the stock location's version before each move.
     *
     * @param list<array{ordDetail:int|string,item:int|string,qty:string}> $lines qty = how much comes out (positive)
     */
    public static function assembleRemove(int $ordr, int $version, string $owner, int $container, int $containerVersion,
        int $stockVersion, array $lines): Request
    {
        $r = self::assembly($ordr, $version, $lines);
        $r->add(Step::updateVersion('Location')->keys(['Location' => $container, 'Version' => $containerVersion]));
        foreach ($lines as $l) {
            $r->add(Step::checkVersion('Location')->keys(['Location' => self::STOCK, 'Version' => $stockVersion]));
            $r->add(Step::execute('MoveInventory')->data(self::pick($owner, $container, '-' . $l['qty'], $l['qty'], $l)));
        }
        return $r;
    }

    /** @param list<array<string,mixed>> $lines */
    private static function assembly(int $ordr, int $version, array $lines): Request
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('An assembly request needs at least one line.');
        }
        foreach ($lines as $l) {
            if (!is_numeric($l['qty'] ?? null) || (float) $l['qty'] <= 0) {
                throw new \InvalidArgumentException('An assembly line needs a quantity above zero.');
            }
        }
        $r = new Request(self::PROGRAM, self::ASSEMBLE, self::ASSEMBLY_PROGRAM);
        $r->add(Step::updateVersion('Ordr')->keys(['Ordr' => $ordr, 'Version' => $version]));
        return $r;
    }

    /** @return array<string,string|int> the MoveInventory data, in client order */
    private static function pick(string $owner, ?int $container, string $quantity, string $entered, array $line): array
    {
        return ['Owner' => $owner, 'Context' => 'PICK', 'SrcLocation' => self::STOCK]
            + ($container === null ? [] : ['TrgLocation' => $container])
            + ['Quantity' => $quantity, 'QtyEntered' => $entered, 'TrgOrdDetail' => $line['ordDetail'], 'Item' => $line['item'],
               'Reserve' => 'true', 'ValidateStorageRules' => 'false', 'UseUnreservedInventory' => 'true'];
    }

    /**
     * Record the address a container's shipping label was printed with.
     *
     * @param bool $replace the container already has a label address on record (a reprint to another address)
     */
    public static function labelAddress(int $container, int $address, bool $replace = false): Request
    {
        $r    = new Request(self::PROGRAM, self::LABEL, self::LABEL_PROGRAM);
        $step = $replace ? Step::update('AddressReference') : Step::insert('AddressReference');
        $r->add($step->keys(['TableID' => $container, 'TableName' => 'Location', 'Reference' => 'ShipToAddress'])
            ->data(['Address' => $address]));
        return $r;
    }

    /**
     * A floating-point number the way the client writes one: 15
     * significant digits when that reads back as the same number,
     * otherwise 17 ("29.6", "13.426334152", "10.886216880000001").
     *
     * Kilograms are pounds times KG_PER_LB. The client adds a container's
     * kilograms up line by line, so for a container holding several lines
     * its figure can differ from ours in the last digits (around the 15th
     * significant digit).
     */
    public static function dbl(float $f): string
    {
        if (is_nan($f) || is_infinite($f)) {
            throw new \InvalidArgumentException('Not a finite number.');
        }
        if ($f == 0.0) {
            return '0';
        }
        $s = sprintf('%.15g', $f);
        if ((float) $s !== $f) {
            $s = sprintf('%.17g', $f);
        }
        if (stripos($s, 'e') !== false) {
            $s = rtrim(rtrim(number_format($f, 17, '.', ''), '0'), '.');
        }
        return $s;
    }

    /** A money value the way CMS returns one: four decimals ("188.0000"). */
    public static function money(string|float $v): string
    {
        return number_format((float) $v, 4, '.', '');
    }
}

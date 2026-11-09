<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Execution;

use PII\Cms\Write\Ops\Manufacturing\ManufacturingRequests;
use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for running a batch ticket on the floor: add an
 * ingredient, make the batch, send it for testing, pack it out, complete
 * it, and the two undo requests. Shapes traced from the CMS log; see the
 * fixtures under tests/fixtures/cms-requests/BatchExecutionDataSet.*.
 *
 * The templates own the step order, the attribute order and the constants;
 * callers only supply values. The client also attaches a note of each
 * warning it showed on screen; Insights shows its own warnings and sends
 * none (the golden tests add the logged ones back).
 */
final class ExecutionRequests
{
    public const PROGRAM = ManufacturingRequests::PROGRAM;
    public const CMS     = 'Batch Execution Express';

    public const NEW_INGREDIENT = 'BatchExecutionDataSet.NewIngredient';
    public const MAKE           = 'BatchExecutionDataSet.BatchExecutionExpress';
    public const TESTING        = 'BatchExecutionDataSet.InProcessTesting';
    public const PACK_PHASE     = 'BatchExecutionDataSet.ExecutePackagingPhase';
    public const PACK_REQUESTED = 'BatchExecutionDataSet.PackoutRequested';
    public const CHANGE_SET     = 'BatchExecutionDataSet.CreateChangeSet';
    public const PACKAGING      = 'BatchExecutionDataSet.Packaging';
    public const COMPLETE       = 'BatchExecutionDataSet.MarkOrderComplete';
    public const UNPACKAGING    = 'BatchExecutionDataSet.Unpackaging';
    public const REVERSE        = 'BatchExecutionDataSet.ReverseReceipt';

    /** The stock location every logged execution move draws from and makes into. */
    public const STOCK = '10669';
    /** The plant location the logged requests name for sampling, the packaging phase and completion. */
    public const PLANT = '2';
    /** The item the client moves once per package filled. */
    public const PACKAGE_ITEM = '1';

    /** Steps whose new ids later steps or requests need. */
    public const STEP_CHANGE_SET = 2;

    /**
     * Add an ingredient to a ticket on the floor.
     *
     * @param string $entered the quantity as typed (the client sends both the number and the typed text)
     */
    public static function newIngredient(string|int $ordr, string|int $version, string|int $item, string $qty, ?string $entered = null): Request
    {
        $r = (new Request(self::PROGRAM, self::NEW_INGREDIENT, self::CMS))->span();
        $r->add(self::lock($ordr, $version));
        $r->add(Step::execute('NewIngredient')->data(['Ordr' => $ordr, 'Item' => $item, 'QtyReqd' => $qty, 'QtyEntered' => $entered ?? $qty]));
        $r->add(self::orderStatus($ordr));
        return $r;
    }

    /**
     * Make the batch in one go: consume the ingredients into the ticket's
     * vessel and mark the steps done.
     *
     * @param list<array{line:int|string,item:int|string,qty:string}> $moves the ingredients consumed, in line order
     * @param list<array{line:int|string,weighed:bool}> $done the steps to mark complete (weighed for an ingredient)
     * @param array{item:int|string,unitCost:string}|array{changeSet:int|string,vessel:int|string} $into
     *        the product and its estimated cost on a first make; the existing production record and vessel when
     *        a make was undone and is run again
     */
    public static function makeBatch(
        string|int $ordr, string|int $version, string $owner, string|int $batchLine, array $moves, array $done, array $into,
    ): Request {
        $again = isset($into['changeSet']);
        if ($again ? !isset($into['vessel']) : !isset($into['item'], $into['unitCost'])) {
            throw new \InvalidArgumentException('A make needs either the product and its cost or the existing production record and vessel.');
        }
        $r = new Request(self::PROGRAM, self::MAKE, self::CMS);
        $r->add(self::lock($ordr, $version));
        if ($again) {
            $r->add(Step::execute('CreateMFReceipt')->data(['ChangeSet' => $into['changeSet']]));
        } else {
            $cs = $r->add(Step::execute('CreateChangeSet')->data(['Context' => 'MF', 'Owner' => $owner, 'Ordr' => $ordr]));
            $r->add(Step::execute('CreateInventoryCost')->subs($cs, 'ChangeSet', 'ChangeSet', 'data')
                ->data(['Owner' => $owner, 'Item' => $into['item'], 'QtyMK' => '0', 'IsBulk' => true, 'UnitCostMK' => $into['unitCost']]));
            $r->add(Step::execute('CreateMFReceipt')->subs($cs, 'ChangeSet', 'ChangeSet', 'data'));
            $vessel = $r->add(Step::execute('CreateLocationRecord')->subs($cs, 'ChangeSet', 'ChangeSet', 'data')
                ->data(['Owner' => $owner, 'InLocation' => '', 'LocationCode' => "VO{$ordr}", 'Context' => 'VSL',
                        'OrdDetail' => $batchLine, 'MarkAsDeletedIfAlreadyExists' => true]));
        }
        foreach ($moves as $m) {
            $move = Step::execute('MoveInventory');
            $data = ['Owner' => $owner, 'Context' => 'CMNGL'];
            if ($again) {
                $data += ['ChangeSet' => $into['changeSet'], 'SrcLocation' => self::STOCK, 'TrgLocation' => $into['vessel']];
            } else {
                $move->subs($cs, 'ChangeSet', 'ChangeSet', 'data')->subs($vessel, 'Location', 'Location', 'data', 'TrgLocation');
                $data += ['SrcLocation' => self::STOCK];
            }
            $r->add($move->data($data + ['Quantity' => $m['qty'], 'SrcOrdDetail' => $m['line'], 'TrgOrdDetail' => $batchLine,
                'Item' => $m['item'], 'QtyEntered' => $m['qty'], 'UseContext' => 'US', 'MakeContext' => 'MKB',
                'UseUnreservedInventory' => true]));
            $r->add(self::qtyUsed($m['line']));
        }
        foreach ($done as $d) {
            if ($d['weighed']) {
                $r->add(Step::execute('UpdateManufacturingOrdDetailPreweighStatus')->data(['OrdDetail' => $d['line'], 'Status' => 'CMP']));
            }
            $r->add(Step::execute('UpdateManufacturingOrdDetailExecStatus')->data(['OrdDetail' => $d['line'], 'Status' => 'CMP']));
        }
        $r->add(Step::execute('UpdateManufacturingOrdDetailExecStatus')->data(['OrdDetail' => $batchLine]));
        $r->add(self::orderStatus($ordr));
        return $r;
    }

    /** Send the made batch for its specification tests: CMS creates the sample request on the vessel. */
    public static function inProcessTesting(
        string|int $ordr, string|int $batchLine, string|int $vessel, string|int $vesselVersion, string|int $testLine,
        string|int $sublot, string $user, string $sampleDate,
    ): Request {
        $r = (new Request(self::PROGRAM, self::TESTING, self::CMS))->span();
        $r->add(Step::updateVersion('OrdDetail')->keys(['OrdDetail' => $batchLine, 'Version' => '']));
        $r->add(Step::checkVersion('Location')->keys(['Location' => $vessel, 'Version' => $vesselVersion]));
        $r->add(Step::execute('UpdateManufacturingOrdDetailExecStatus')->data(['OrdDetail' => $testLine]));
        $r->add(self::orderStatus($ordr));
        $r->add(Step::execute('CreateSamplingRequest')->data(['SubLot' => $sublot, 'CreateSample' => true, 'SampleDate' => $sampleDate,
            'SampledBy' => $user, 'SampleLocation' => self::PLANT, 'Vessel' => $vessel, 'OrdDetail' => $testLine]));
        return $r;
    }

    /** Open the packaging phase of a bulk ticket (sent once, before its first packout). */
    public static function executePackagingPhase(string|int $productLine): Request
    {
        $r = (new Request(self::PROGRAM, self::PACK_PHASE, self::CMS))->span();
        $r->add(Step::execute('ExecutePackagingPhase')->data(['OrdDetail' => $productLine, 'Location' => self::PLANT]));
        return $r;
    }

    /**
     * Release a packaging ticket from the floor, as the execution screen does
     * just before the first packout.
     *
     * @param ?array{item:int|string,productLine:int|string,lotPrefix:string,user:string,manfDate:string} $lot
     *        the lot to create when the packaging ticket has none yet
     */
    public static function releasePack(string|int $ordr, string|int $version, string $releaseDate, ?array $lot = null): Request
    {
        $r = new Request(self::PROGRAM, ManufacturingRequests::RELEASE, self::CMS);
        $r->add(Step::update('Ordr')->keys(['Ordr' => $ordr, 'Version' => $version])
            ->data(['DateReleased' => $releaseDate, 'Status' => 'RLS', 'UserHold' => '', 'AllowOvercommit' => true]));
        $r->add(Step::execute('UpdateOrderQtyCommitted')->data(['Ordr' => $ordr]));
        if ($lot !== null) {
            $new = $r->add(Step::execute('CreateLot')->data(['Item' => $lot['item'], 'OrdDetail' => $lot['productLine'],
                'ManfLotSameAsLot' => true, 'Status' => 'Approved', 'Manufacturer' => ManufacturingRequests::MANUFACTURER,
                'LotPrefix' => $lot['lotPrefix'], 'Length' => ManufacturingRequests::LOT_LENGTH,
                'ReleasedBy' => $lot['user'], 'ManfDate' => $lot['manfDate']]));
            $r->add(Step::execute('CreateLotIngredient')->subs($new, 'Lot', 'Lot', 'data', null, ''));
            $r->add(Step::execute('CreateLotComponent')->subs($new, 'Lot', 'Lot', 'data', null, ''));
        }
        $r->add(Step::execute('ModifyMFOrderProcedureDetails')->subs(1, 'Ordr', 'Ordr', 'data', null, '')
            ->data(['UpdateStatus' => false, 'UpdateLot' => false]));
        $r->add(Step::execute('NotifyMFOrder')->subs(1, 'Ordr', 'Ordr', 'data', null, '')
            ->data(['NotificationCode' => 'MFO Released Notification']));
        return $r;
    }

    /** Tell CMS a packout of this packaged item is wanted from the bulk ticket. */
    public static function packoutRequested(string|int $bulkOrdr, string|int $packItem): Request
    {
        $r = (new Request(self::PROGRAM, self::PACK_REQUESTED, self::CMS))->span();
        $r->add(Step::execute('PackoutRequested')->data(['Ordr' => $bulkOrdr, 'Item' => $packItem]));
        return $r;
    }

    /** Give a packaging ticket its production record, before its first packout. */
    public static function createChangeSet(string|int $packOrdr, string|int $version, string $owner): Request
    {
        $r = new Request(self::PROGRAM, self::CHANGE_SET, self::CMS);
        $r->add(self::lock($packOrdr, $version));
        $r->add(Step::execute('CreateChangeSet')->data(['Context' => 'MF', 'Owner' => $owner, 'Ordr' => $packOrdr]));
        return $r;
    }

    /**
     * One packout: fill packages from the vessel into the packaging
     * ticket's container and make the packaged item into stock.
     *
     * @param array{ordr:int|string,line:int|string,item:int|string} $pack the packaging ticket, its product line and item
     * @param list<array{line:int|string,item:int|string,qty:string}> $components packaging components used
     * @param array{line:int|string,item:int|string,sublot:int|string,vessel:int|string,qty:string} $bulk the draw from the vessel
     * @param array{changeSet:int|string,unitCost:string}|array{container:int|string,inventoryCost:int|string} $into
     *        the packaging ticket's production record and estimated cost on its first packout; its existing
     *        container and cost record on a later one
     * @param int $secondsOpen how long the packout was open on screen (the client stamps its start)
     */
    public static function packaging(
        string|int $bulkOrdr, string|int $bulkVersion, string $owner, array $pack, string $packages, array $components,
        array $bulk, string $made, array $into, int $secondsOpen = 0,
    ): Request {
        $later = isset($into['container']);
        if ($later ? !isset($into['inventoryCost']) : !isset($into['changeSet'], $into['unitCost'])) {
            throw new \InvalidArgumentException('A packout needs either the production record and cost or the existing container and cost record.');
        }
        $r = (new Request(self::PROGRAM, self::PACKAGING, self::CMS))->span(true, $secondsOpen);
        $r->add(self::lock($bulkOrdr, $bulkVersion));
        $r->add(Step::execute('PackoutStarted')->data(['Ordr' => $bulkOrdr]));
        if (!$later) {
            $cost = $r->add(Step::execute('CreateInventoryCost')->data(['Owner' => $owner, 'ChangeSet' => $into['changeSet'],
                'Item' => $pack['item'], 'QtyMK' => '0', 'UnitCostMK' => $into['unitCost']]));
            $container = $r->add(Step::execute('CreateContainer')->data(['ChangeSet' => $into['changeSet'], 'Owner' => $owner,
                'InLocation' => '', 'Prefix' => 'V', 'OrdDetail' => $pack['line']]));
        }
        $cs = $r->add(Step::execute('CreateChangeSet')->data(['Context' => 'MFP', 'Owner' => $owner, 'Ordr' => $pack['ordr']]));

        // A move into the container: the container comes from this request on a first packout.
        $fill = static function (array $data) use ($r, $cs, $later, $into, $owner, &$container): void {
            $step = Step::execute('MoveInventory')->subs($cs, 'ChangeSet', 'ChangeSet', 'data');
            $head = ['Owner' => $owner, 'Context' => 'PCKAGE'];
            if ($later) {
                $head['TrgLocation'] = $into['container'];
            } else {
                $step->subs($container, 'Location', 'Location', 'data', 'TrgLocation');
            }
            $r->add($step->data($head + $data));
        };
        $fill(['TrgOrdDetail' => $pack['line'], 'Item' => self::PACKAGE_ITEM, 'MakeContext' => 'MKB', 'UseUnreservedInventory' => true,
               'SrcLocation' => self::STOCK, 'Quantity' => $packages, 'UseContext' => 'US']);
        foreach ($components as $c) {
            $fill(['SrcOrdDetail' => $c['line'], 'TrgOrdDetail' => $pack['line'], 'Item' => $c['item'], 'MakeContext' => 'MKB',
                   'UseUnreservedInventory' => true, 'SrcLocation' => self::STOCK, 'Quantity' => $c['qty'], 'UseContext' => 'US']);
            $r->add(self::qtyUsed($c['line']));
        }
        $fill(['SrcOrdDetail' => $bulk['line'], 'TrgOrdDetail' => $pack['line'], 'Item' => $bulk['item'], 'MakeContext' => 'MKB',
               'UseUnreservedInventory' => true, 'Sublot' => $bulk['sublot'], 'TareEntered' => '0', 'SrcLocation' => $bulk['vessel'],
               'Quantity' => $bulk['qty'], 'UseContext' => 'USB']);
        $r->add(self::qtyUsed($bulk['line']));

        $make = Step::execute('MoveInventory')->subs($cs, 'ChangeSet', 'ChangeSet', 'data');
        $data = ['Owner' => $owner, 'Context' => 'PCKAGE'];
        if ($later) {
            $data['SrcLocation'] = $into['container'];
        } else {
            $make->subs($container, 'Location', 'Location', 'data', 'SrcLocation')->subs($cost, 'InventoryCost', 'InventoryCost', 'data');
        }
        $data += ['TrgLocation' => self::STOCK, 'Quantity' => $made, 'QtyEntered' => $made, 'TareEntered' => '0',
                  'TrgOrdDetail' => $pack['line'], 'Item' => $pack['item'], 'UseContext' => 'USB', 'MakeContext' => 'MK'];
        if ($later) {
            $data['InventoryCost'] = $into['inventoryCost'];
        }
        $r->add($make->data($data));
        $r->add(Step::execute('UpdateManufacturingOrdDetailQtyMade')->data(['OrdDetail' => $pack['line']]));
        $r->add(Step::execute('UpdateManufacturingOrdDetailPackoutStatus')->data(['OrdDetail' => $pack['line']]));
        $r->add(self::orderStatus($bulkOrdr));
        return $r;
    }

    /** Mark a ticket complete on the floor. */
    public static function markComplete(string|int $ordr, string|int $version): Request
    {
        $r = (new Request(self::PROGRAM, self::COMPLETE, self::CMS))->span(false);
        $r->add(self::lock($ordr, $version));
        $r->add(Step::execute('MarkManufacturingOrderComplete')->data(['Ordr' => $ordr, 'CurrentLocation' => self::PLANT]));
        return $r;
    }

    /**
     * Undo one packout.
     *
     * @param list<int|string> $usedLines the lines of the packaging ticket the packout drew from
     */
    public static function unpackaging(string|int $bulkOrdr, string|int $bulkVersion, string|int $changeSet, array $usedLines, string|int $packLine): Request
    {
        $r = (new Request(self::PROGRAM, self::UNPACKAGING, self::CMS))->span();
        $r->add(self::lock($bulkOrdr, $bulkVersion));
        $r->add(Step::execute('ReverseChangeSet')->data(['ChangeSet' => $changeSet]));
        foreach ($usedLines as $line) {
            $r->add(self::qtyUsed($line));
        }
        $r->add(Step::execute('UpdateManufacturingOrdDetailQtyMade')->data(['OrdDetail' => $packLine]));
        $r->add(Step::execute('UpdateManufacturingOrdDetailPackoutStatus')->data(['OrdDetail' => $packLine]));
        $r->add(self::orderStatus($bulkOrdr));
        return $r;
    }

    /**
     * Undo the make: the ingredients go back to stock.
     *
     * @param list<int|string> $ingredientLines the ticket's ingredient lines to recount
     * @param bool $retest add a fresh test step (the batch had been sent for testing)
     */
    public static function reverseReceipt(string|int $ordr, string|int $version, string|int $receipt, array $ingredientLines, bool $retest): Request
    {
        $r = (new Request(self::PROGRAM, self::REVERSE, self::CMS))->span();
        $r->add(self::lock($ordr, $version));
        $r->add(Step::execute('ReverseReceipt')->data(['Receipt' => $receipt]));
        foreach ($ingredientLines as $line) {
            $r->add(self::qtyUsed($line));
        }
        if ($retest) {
            $r->add(Step::execute('CloneFIPT')->data(['Ordr' => $ordr]));
        }
        $r->add(self::orderStatus($ordr));
        return $r;
    }

    private static function lock(string|int $ordr, string|int $version): Step
    {
        return Step::updateVersion('Ordr')->keys(['Ordr' => $ordr, 'Version' => $version]);
    }

    private static function qtyUsed(string|int $line): Step
    {
        return Step::execute('UpdateManufacturingOrdDetailQtyUsed')->data(['OrdDetail' => $line]);
    }

    private static function orderStatus(string|int $ordr): Step
    {
        return Step::execute('UpdateManufacturingOrderStatus')->data(['Ordr' => $ordr]);
    }
}

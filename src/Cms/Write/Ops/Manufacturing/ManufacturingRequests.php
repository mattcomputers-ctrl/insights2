<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Manufacturing;

use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for batch tickets and recipes. Shapes traced from
 * the CMS log; see docs/research/manufacturing.md and the fixtures under
 * tests/fixtures/cms-requests/ManufacturingOrderDataSet.*.
 *
 * The templates own the step order, the attribute order and the constants;
 * callers only supply values. Ids that an earlier request of the same
 * sequence produces may be passed as text (a placeholder in a preview).
 */
final class ManufacturingRequests
{
    public const PROGRAM    = 'Insights Manufacturing';
    public const CMS_ORDER  = 'Batching Order';
    public const CMS_RECIPE = 'Batching Recipe';
    public const SAVE       = 'ManufacturingOrderDataSet.SaveChanges';
    public const RELEASE    = 'ManufacturingOrderDataSet.ReleaseOrder';
    public const CLOSE      = 'ManufacturingOrderDataSet.CloseOrder';
    public const PUBLISH    = 'ManufacturingOrderDataSet.PublishRecipe';
    public const ACTIVE     = 'ManufacturingOrderDataSet.ChangeActiveState';

    /** Steps of createTicket() whose new ids later requests need. */
    public const STEP_ORDR    = 1;
    public const STEP_BATCH   = 2;
    public const STEP_PRODUCT = 3;

    /** The manufacturer every logged production lot is created under (ourselves). */
    public const MANUFACTURER = '9';
    /** Digits of the running number after the yyMMdd lot prefix. */
    public const LOT_LENGTH = '3';

    private const BULK_HEADER = ['Context', 'Owner', 'Recipe', 'DateOrdered', 'DateRequired', 'PlanStartDate', 'Status',
        'UserHold', 'PlacedBy', 'BasePriority', 'AllowOvercommit', 'Comment', 'PreweighZone', 'ExecutionZone',
        'PackagingZone', 'PercentPrecision', 'ChangeNumber', 'Revision', 'SimpleFinalPhase', 'PreweighOnly', 'BillTo', 'Ready'];
    private const BULK_REQUIRED = ['Owner', 'Recipe', 'DateOrdered', 'DateRequired', 'PlanStartDate', 'PlacedBy', 'ChangeNumber'];

    private const PACK_HEADER = ['Context', 'Owner', 'Recipe', 'DateOrdered', 'DateRequired', 'PlanStartDate', 'Status',
        'UserHold', 'PlacedBy', 'BasePriority', 'AllowOvercommit', 'Comment', 'PreweighZone', 'PercentPrecision',
        'Revision', 'SimpleFinalPhase', 'PreweighOnly', 'Ready'];
    private const PACK_REQUIRED = ['Owner', 'Recipe', 'DateOrdered', 'DateRequired', 'PlanStartDate', 'PlacedBy'];

    /** Header columns an edit may change, in client order. */
    private const HEADER_CHANGES = ['DateRequired', 'PlanStartDate', 'AllowOvercommit', 'BillTo'];

    private const BATCH = ['RecipeDetailReference', 'Context', 'AltID', 'Owner', 'Phase', 'BatchType', 'TimeToAddUnit',
        'TotalWeight', 'TotalVolume', 'Status', 'ExecStatus', 'ExecOrder', 'Discarded', 'Inactive'];
    private const PRODUCT = ['RecipeDetailReference', 'Context', 'AltID', 'Owner', 'Item', 'QtyEntered', 'QtyReqd', 'StdQty',
        'TotalWeight', 'TotalWeightPercent', 'TotalVolume', 'TotalVolumePercent', 'IsOpen', 'Status', 'ExecStatus',
        'Discarded', 'RepackagingYield', 'Inactive'];
    private const USE_BATCH = ['RecipeDetailReference', 'Context', 'AltID', 'Owner', 'MustPreweigh', 'Status', 'UseFrom',
        'ExecStatus', 'Discarded', 'IsCommentFormatted', 'Inactive'];
    private const INGREDIENT = ['RecipeDetailReference', 'Context', 'AltID', 'Owner', 'Line', 'Item', 'QtyEntered', 'QtyReqd',
        'StdQty', 'IsOpen', 'HoldAllowed', 'MustPreweigh', 'IgnoreDrug', 'Status', 'ExecStatus', 'ExecOrder', 'Discarded',
        'IsCommentFormatted', 'AdjControlValue', 'Inactive'];
    private const INSTRUCTION = ['RecipeDetailReference', 'Context', 'AltID', 'Owner', 'Description', 'Status', 'ExecStatus',
        'ExecOrder', 'Discarded', 'RecordFirst', 'RecordLast', 'Inactive'];
    private const TEST = ['Test', 'Min', 'Max', 'TestGroup', 'Grade', 'Line', 'Deferrable'];

    /**
     * Create a bulk batch ticket from a published recipe. The ticket is
     * created not started and on hold, as the client creates it.
     *
     * @param array<string,string|int|bool> $header Owner, Recipe, DateOrdered, DateRequired, PlanStartDate, PlacedBy,
     *        ChangeNumber; optional AllowOvercommit (default false), Comment, PercentPrecision, BillTo
     * @param array<string,string|int> $batch       RecipeDetailReference, AltID, Phase, BatchType, TimeToAddUnit,
     *        TotalWeight, TotalVolume, ExecOrder
     * @param array<string,string|int> $product     RecipeDetailReference, AltID, Item, QtyReqd, TotalWeight, TotalVolume
     * @param array<string,string|int> $useBatch    RecipeDetailReference, AltID, UseFrom
     * @param list<array<string,string|int>> $ingredients each RecipeDetailReference, AltID, Line, Item, QtyEntered,
     *        QtyReqd, StdQty, ExecOrder, AdjControlValue; in the order to write them
     * @param list<array<string,string|int>> $instructions each RecipeDetailReference, AltID, Description, ExecOrder
     */
    public static function createTicket(
        array $header, array $batch, array $product, array $useBatch, array $ingredients, array $instructions,
    ): Request {
        self::need($header, self::BULK_REQUIRED, 'A new batch ticket');
        if ($ingredients === []) {
            throw new \InvalidArgumentException('A new batch ticket needs at least one ingredient.');
        }
        $owner  = $header['Owner'];
        $header = ['Context' => 'MFBA'] + $header + [
            'Status' => 'NST', 'UserHold' => 'HOLD', 'BasePriority' => '100', 'AllowOvercommit' => false,
            'PreweighZone' => '1', 'ExecutionZone' => '3', 'PackagingZone' => '4', 'Revision' => '0',
            'SimpleFinalPhase' => false, 'PreweighOnly' => false, 'Ready' => false,
        ];

        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_ORDER);
        $r->event(['Method' => 'ScaleOrderQuantity', 'Caption' => 'ScaleOrderQuantity']);
        $r->event(['Method' => 'ScaleOrderQuantity']);
        $ordr = $r->add(Step::insert('Ordr')->keys(['Ordr' => Step::IDENTITY, 'Version' => ''])
            ->data(self::ordered(self::present($header), self::BULK_HEADER, 'batch ticket')));

        $state = ['Status' => 'NST', 'ExecStatus' => 'NST', 'Discarded' => false, 'Inactive' => false];
        $ba = $r->add(self::line($ordr, null, self::BATCH, 'batch',
            ['Context' => 'BA', 'Owner' => $owner] + $batch + $state));
        $pk = $r->add(self::line($ordr, $ba, self::PRODUCT, 'product',
            ['Context' => 'PK', 'Owner' => $owner, 'QtyEntered' => $product['QtyReqd'] ?? null, 'StdQty' => '1',
             'TotalWeightPercent' => '100', 'TotalVolumePercent' => '100', 'IsOpen' => true, 'RepackagingYield' => '0']
            + $product + $state));
        $r->add(self::line($ordr, $pk, self::USE_BATCH, 'use-batch',
            ['Context' => 'UB', 'Owner' => $owner, 'MustPreweigh' => '0', 'IsCommentFormatted' => false] + $useBatch + $state));
        foreach ($ingredients as $ui) {
            $r->add(self::line($ordr, $ba, self::INGREDIENT, 'ingredient',
                ['Context' => 'UI', 'Owner' => $owner, 'IsOpen' => true, 'HoldAllowed' => false, 'MustPreweigh' => '0',
                 'IgnoreDrug' => false, 'IsCommentFormatted' => false, 'AdjControlValue' => '100'] + $ui + $state));
        }
        foreach ($instructions as $in) {
            $r->add(self::line($ordr, $ba, self::INSTRUCTION, 'instruction',
                ['Context' => 'INSTR', 'Owner' => $owner, 'RecordFirst' => false, 'RecordLast' => false] + $in + $state));
        }

        $r->add(self::onOrder('CheckOrderItemsStatus'));
        $r->add(Step::execute('CreateDocument')->subs($ordr, 'Ordr', 'Ordr', 'data', 'TableID')
            ->data(['TableName' => 'Ordr', 'Role' => 'Ordr', 'Prefix' => 'OO', 'Description' => 'Batching Order']));
        self::saveTrailer($r, (string) $owner);
        $r->add(self::onOrder('NotifyMFOrder')->data(['NotificationCode' => 'MFO Created Notification']));
        return $r;
    }

    /**
     * Add one packaging ticket to a bulk ticket: the pack ticket itself, its
     * lines, and the claim its bulk line makes on the bulk ticket's product.
     *
     * @param string|int $bulkOrdr / $bulkVersion the bulk ticket and its current version
     * @param array<string,string|int|bool> $header Owner, Recipe, DateOrdered, DateRequired, PlanStartDate, PlacedBy;
     *        optional Comment, PercentPrecision
     * @param array<string,string|int> $product RecipeDetailReference, Item, QtyReqd
     * @param list<array<string,string|int|bool>> $ingredients each RecipeDetailReference, AltID, Item, QtyEntered,
     *        QtyReqd, StdQty; exactly one also has 'bulk' => true (the line that draws the bulk product)
     * @param string|int $bulkProductLine the bulk ticket's product line (OrdDetail) the pack draws from
     */
    public static function addPackTicket(
        string|int $bulkOrdr, string|int $bulkVersion, array $header, array $product, array $ingredients,
        string|int $bulkProductLine,
    ): Request {
        self::need($header, self::PACK_REQUIRED, 'A new packaging ticket');
        $bulk = array_keys(array_filter($ingredients, static fn (array $ui): bool => ($ui['bulk'] ?? false) === true));
        if (count($bulk) !== 1) {
            throw new \InvalidArgumentException('A packaging ticket needs exactly one line that draws the bulk product.');
        }
        $owner  = $header['Owner'];
        $header = ['Context' => 'MFPP'] + $header + [
            'Status' => 'NST', 'UserHold' => 'HOLD', 'BasePriority' => '100', 'AllowOvercommit' => false,
            'PreweighZone' => '1', 'Revision' => '0', 'SimpleFinalPhase' => false, 'PreweighOnly' => false, 'Ready' => false,
        ];

        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_ORDER);
        $r->add(Step::updateVersion('Ordr')->keys(['Ordr' => $bulkOrdr, 'Version' => $bulkVersion]));
        $ordr = $r->add(Step::insert('Ordr')->keys(['Ordr' => Step::IDENTITY, 'Version' => ''])
            ->data(self::ordered(self::present($header), self::PACK_HEADER, 'packaging ticket')));

        $state   = ['Status' => 'NST', 'ExecStatus' => 'NST', 'Discarded' => false, 'Inactive' => false];
        $columns = array_values(array_diff(self::PRODUCT, ['TotalWeight', 'TotalVolume', 'RepackagingYield']));
        $pk = $r->add(self::line($ordr, null, $columns, 'product',
            ['Context' => 'PK', 'AltID' => '1', 'Owner' => $owner, 'QtyEntered' => $product['QtyReqd'] ?? null, 'StdQty' => '1',
             'TotalWeightPercent' => '100', 'TotalVolumePercent' => '100', 'IsOpen' => true] + $product + $state));
        $columns  = array_values(array_diff(self::INGREDIENT, ['Line', 'ExecOrder', 'AdjControlValue']));
        $bulkStep = 0;
        $bulkQty  = '';
        foreach ($ingredients as $i => $ui) {
            $isBulk = $i === $bulk[0];
            unset($ui['bulk']);
            $step = $r->add(self::line($ordr, $pk, $columns, 'ingredient',
                ['Context' => 'UI', 'Owner' => $owner, 'IsOpen' => true, 'HoldAllowed' => false, 'MustPreweigh' => '0',
                 'IgnoreDrug' => false, 'IsCommentFormatted' => false] + $ui + $state));
            if ($isBulk) {
                $bulkStep = $step;
                $bulkQty  = (string) $ui['QtyReqd'];
            }
        }
        $r->add(Step::insert('OrdDetailCommit')->keys(['OrdDetailCommit' => Step::IDENTITY])
            ->subs($bulkStep, 'OrdDetail', 'OrdDetail', 'data')
            ->data(['SrcOrdDetail' => $bulkProductLine, 'Qty' => $bulkQty]));
        self::saveTrailer($r, (string) $owner);
        return $r;
    }

    /**
     * Change header values of a ticket: required date, planned start,
     * the overcommit flag, the customer it is for.
     *
     * @param array<string,string|int|bool> $changes changed Ordr columns only
     * @param ?array{user:string,lotPrefix:string} $released set for a released ticket: the client then also
     *        refreshes the lots of the linked packaging tickets
     */
    public static function editTicket(int $ordr, int $version, array $changes, string $owner, ?array $released = null): Request
    {
        if ($changes === []) {
            throw new \InvalidArgumentException('A ticket save needs at least one change.');
        }
        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_ORDER);
        $r->add(Step::update('Ordr')->keys(['Ordr' => $ordr, 'Version' => $version])
            ->data(self::ordered($changes, self::HEADER_CHANGES, 'batch ticket')));
        self::saveTrailer($r, $owner);
        if ($released !== null) {
            $r->add(self::packLots($released['user'], $released['lotPrefix']));
        }
        return $r;
    }

    /**
     * Release a bulk ticket to the floor: status, the specification tests
     * of the product, the commitments and the production lot.
     *
     * @param string|int $ordr / $version / $batchLine / $productLine ids, or placeholders when the ticket is
     *        created earlier in the same sequence
     * @param string $releaseDate midnight timestamp of the release day
     * @param ?array{altId:int|string,execOrder:int|string,owner:string,tests:list<array<string,string|int|bool>>} $spec
     *        the in-process test line to add, with the product's specification tests (Test, optional Min and
     *        Max, TestGroup, Grade, Line, Deferrable); null when the product has none
     * @param array{item:int|string,lotPrefix:string,user:string,manfDate:?string} $lot manfDate is only used
     *        when there are no tests (the lot is then created approved)
     */
    public static function release(
        string|int $ordr, string|int $version, string $releaseDate, string|int $batchLine, string|int $productLine,
        ?array $spec, array $lot,
    ): Request {
        $r = new Request(self::PROGRAM, self::RELEASE, self::CMS_ORDER);
        $r->event(['Method' => 'ManfLotNotSpecified', 'Caption' => 'ManfLotNotSpecified']);
        $r->event(['Method' => 'manflotnotspecified', 'Caption' => 'manflotnotspecified']);
        $r->add(Step::update('Ordr')->keys(['Ordr' => $ordr, 'Version' => $version])
            ->data(['DateReleased' => $releaseDate, 'Status' => 'RLS', 'UserHold' => '']));

        if ($spec !== null) {
            if ($spec['tests'] === []) {
                throw new \InvalidArgumentException('A specification test line needs at least one test.');
            }
            $ipt = $r->add(Step::insert('OrdDetail')->keys(['OrdDetail' => Step::IDENTITY])->data([
                'Ordr' => $ordr, 'Context' => 'IPT', 'AltID' => $spec['altId'], 'Owner' => $spec['owner'],
                'Parent' => $batchLine, 'Description' => 'Specification Test', 'Qualifier' => 'F', 'Status' => 'NST',
                'ExecStatus' => 'NST', 'ExecOrder' => $spec['execOrder'], 'Discarded' => false, 'RecordLast' => false,
                'RecordFirst' => false, 'AutoApprove' => true, 'Inactive' => false,
            ]));
            foreach ($spec['tests'] as $test) {
                $r->add(Step::insert('OrdDetailTest')->keys(['OrdDetailTest' => Step::IDENTITY, 'Version' => ''])
                    ->subs($ipt, 'OrdDetail', 'OrdDetail', 'data')
                    ->data(self::ordered(self::present($test), self::TEST, 'specification test')));
            }
        }

        $r->add(Step::execute('UpdateOrderQtyCommitted')->data(['Ordr' => $ordr]));
        $data = ['Item' => $lot['item'], 'OrdDetail' => $productLine, 'ManfLotSameAsLot' => true,
                 'Status' => $spec !== null ? 'Hold' : 'Approved', 'Manufacturer' => self::MANUFACTURER,
                 'LotPrefix' => $lot['lotPrefix'], 'Length' => self::LOT_LENGTH];
        if ($spec === null) {
            if (($lot['manfDate'] ?? null) === null) {
                throw new \InvalidArgumentException('A lot created approved needs its manufacture date.');
            }
            $data += ['ReleasedBy' => $lot['user'], 'ManfDate' => $lot['manfDate']];
        }
        $newLot = $r->add(Step::execute('CreateLot')->data($data));
        $r->add(Step::execute('CreateLotIngredient')->subs($newLot, 'Lot', 'Lot', 'data', null, ''));
        $r->add(Step::execute('CreateLotComponent')->subs($newLot, 'Lot', 'Lot', 'data', null, ''));
        $r->add(self::onOrder('CreateMFOrderFinalTests'));
        $r->add(self::packLots($lot['user'], $lot['lotPrefix']));
        $r->add(self::procedureDetails());
        $r->add(self::onOrder('NotifyMFOrder')->data(['NotificationCode' => 'MFO Released Notification']));
        return $r;
    }

    /**
     * Close a ticket.
     *
     * @param ?string $dateCompleted null for a ticket production has completed; the closing moment for a
     *        ticket that never ran (it is then cancelled: its open lines are closed with it)
     * @param list<int> $openLines the lines to close on a ticket that never ran: product first, then ingredients
     * @param list<int> $repackLines the repack ingredient lines of such a ticket; they go last and
     *        their committed quantity is set to zero
     */
    public static function close(
        int $ordr, int $version, ?string $dateCompleted = null, array $openLines = [], array $repackLines = [],
    ): Request {
        if ($dateCompleted === null && ($openLines !== [] || $repackLines !== [])) {
            throw new \InvalidArgumentException('Lines are only closed on a ticket that never ran.');
        }
        $r = new Request(self::PROGRAM, self::CLOSE, self::CMS_ORDER);
        $r->add(Step::update('Ordr')->keys(['Ordr' => $ordr, 'Version' => $version])
            ->data(($dateCompleted === null ? [] : ['DateCompleted' => $dateCompleted]) + ['Status' => 'CLS']));
        foreach ($openLines as $line) {
            $r->add(Step::update('OrdDetail')->keys(['OrdDetail' => $line])->data(['IsOpen' => false]));
        }
        foreach ($repackLines as $line) {
            $r->add(Step::update('OrdDetail')->keys(['OrdDetail' => $line])->data(['IsOpen' => false, 'QtyCommitted' => 0]));
        }
        $r->add(Step::execute('CloseLinkedPackagingOrders')->data(['Ordr' => $ordr]));
        $r->add(Step::execute('ExportTransaction')->data(['OrderCompleted' => $ordr]));
        return $r;
    }

    /**
     * Publish a recipe: hang the product under the batch, add the line that
     * says the product uses that batch, then let CMS publish.
     */
    public static function publishRecipe(
        int $recipe, int $version, string $owner, int $productLine, int $batchLine, int $batchAltId, int $newAltId,
    ): Request {
        $r = new Request(self::PROGRAM, self::PUBLISH, self::CMS_RECIPE);
        $r->add(Step::updateVersion('Recipe')->keys(['Recipe' => $recipe, 'Version' => $version]));
        $r->add(Step::update('RecipeDetail')->keys(['RecipeDetail' => $productLine])->data(['Parent' => $batchLine]));
        $r->add(Step::insert('RecipeDetail')->keys(['RecipeDetail' => Step::IDENTITY])->data([
            'Recipe' => $recipe, 'Context' => 'UB', 'AltID' => $newAltId, 'Owner' => $owner, 'Parent' => $productLine,
            'UseFrom' => $batchAltId, 'IsCommentFormatted' => false,
        ]));
        $r->add(Step::execute('PublishRecipe')->data(['Recipe' => $recipe]));
        return $r;
    }

    /** Take a recipe out of use, or put it back. */
    public static function setRecipeInactive(int $recipe, int $version, bool $inactive): Request
    {
        $r = new Request(self::PROGRAM, self::ACTIVE, self::CMS_RECIPE);
        $r->add(Step::update('Recipe')->keys(['Recipe' => $recipe, 'Version' => $version])->data(['Inactive' => $inactive]));
        return $r;
    }

    /** The three checks the client runs after every ticket save, all on step 1's order. */
    private static function saveTrailer(Request $r, string $owner): void
    {
        $r->add(self::onOrder('VerifyESD')->data(['Owner' => $owner]));
        $r->add(self::procedureDetails());
        $r->add(self::onOrder('UpdateLinkedPackagingOrders'));
    }

    private static function procedureDetails(): Step
    {
        return self::onOrder('ModifyMFOrderProcedureDetails')->data(['UpdateStatus' => false, 'UpdateLot' => false]);
    }

    private static function packLots(string $user, string $lotPrefix): Step
    {
        return Step::execute('CreateLotForPackagingProductOrders')->subs(1, 'Ordr', 'Ordr', 'data', 'ParentOrdr', '')
            ->data(['User' => $user, 'LotPrefix' => $lotPrefix, 'LotLength' => self::LOT_LENGTH]);
    }

    /** A business step that works on the order of step 1. */
    private static function onOrder(string $object): Step
    {
        return Step::execute($object)->subs(1, 'Ordr', 'Ordr', 'data', null, '');
    }

    /**
     * One ticket line under the order of step $ordrStep and, when given, the parent line of step $parentStep.
     *
     * @param list<string> $columns
     * @param array<string,mixed> $values
     */
    private static function line(int $ordrStep, ?int $parentStep, array $columns, string $what, array $values): Step
    {
        $step = Step::insert('OrdDetail')->keys(['OrdDetail' => Step::IDENTITY])->subs($ordrStep, 'Ordr', 'Ordr', 'data');
        if ($parentStep !== null) {
            $step->subs($parentStep, 'OrdDetail', 'OrdDetail', 'data', 'Parent');
        }
        $data = self::ordered($values, $columns, "ticket {$what} line");
        foreach ($columns as $name) {
            if (!array_key_exists($name, $data) || $data[$name] === null || $data[$name] === '') {
                throw new \InvalidArgumentException("A ticket {$what} line needs {$name}.");
            }
        }
        return $step->data($data);
    }

    /** @param array<string,mixed> $values @param list<string> $names */
    private static function need(array $values, array $names, string $what): void
    {
        foreach ($names as $name) {
            if (!array_key_exists($name, $values) || $values[$name] === '' || $values[$name] === null) {
                throw new \InvalidArgumentException("{$what} needs {$name}.");
            }
        }
    }

    /** Drop the optional columns that have no value. @param array<string,mixed> $values */
    private static function present(array $values): array
    {
        return array_filter($values, static fn ($v): bool => $v !== null && $v !== '');
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

<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\Fmt;
use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for item master data (CMS: Item Update and Item
 * Name Update). Shapes traced from the CMS log; see
 * docs/research/inventory-masterdata.md and the fixtures under
 * tests/fixtures/cms-requests/ItemUpdateDataSet.* and ItemNameUpdateDataSet.*.
 *
 * An alias is a row of Item with Context NAME whose ReplacedBy points at
 * the inventory item it is another name for.
 */
final class ItemRequests
{
    public const PROGRAM    = 'Insights Items';
    public const CMS_ALIAS  = 'Item Name Update';
    public const ALIAS_SAVE = 'ItemNameUpdateDataSet.SaveChanges';

    public const CMS_ITEM   = 'Item Update';
    public const ITEM_SAVE  = 'ItemUpdateDataSet.SaveChanges';

    /**
     * The kinds of item that are created without pack sizes or a recipe,
     * with the settings every such item in CMS has.
     *
     * @var array<string,array{label:string,unit:string,glGroup:string,group:string,shipInContainer:bool}>
     */
    public const KINDS = [
        'raw'       => ['label' => 'Raw material', 'unit' => 'lb', 'glGroup' => 'Raw Material', 'group' => 'Raw Material', 'shipInContainer' => true],
        'packaging' => ['label' => 'Packaging', 'unit' => 'ea', 'glGroup' => 'Packaging', 'group' => 'Packaging Material', 'shipInContainer' => false],
    ];

    /** The columns of an alias that can be changed, in the order the client sends them. */
    public const ALIAS_COLUMNS = ['ItemCode', 'Description', 'ReplacedBy'];

    /** A new alias of an inventory item. */
    public static function aliasCreate(string $code, string $description, int|string $replacedBy, string $user, string $area): Request
    {
        $r = new Request(self::PROGRAM, self::ALIAS_SAVE, self::CMS_ALIAS);
        $r->add(Step::insert('Item')->keys(['Item' => Step::IDENTITY, 'Version' => ''])->data([
            'ItemCode' => $code, 'Prototype' => 'false', 'Description' => $description, 'Context' => 'NAME',
            'ReceivedApproved' => 'false', 'IsKit' => 'false', 'ReplacedBy' => $replacedBy, 'NoExpiry' => 'false',
            'AutoApprove' => 'false', 'ByVolume' => 'false', 'CreateUser' => $user, 'CreateArea' => $area,
            'ShipInContainer' => 'false', 'CertifiedOrganic' => 'false', 'NotInHost' => 'false', 'ControlledSubstance' => 'false',
        ]));
        $r->add(self::fixup());
        return $r;
    }

    /**
     * Change an alias: its code, its description, or the item it stands for.
     *
     * @param array<string,int|string> $changes only the changed columns of ALIAS_COLUMNS
     */
    public static function aliasEdit(int|string $item, int|string $version, array $changes): Request
    {
        $data = [];
        foreach (self::ALIAS_COLUMNS as $column) {
            if (array_key_exists($column, $changes)) {
                $data[$column] = $changes[$column];
            }
        }
        if ($data === [] || count($data) !== count($changes)) {
            throw new \InvalidArgumentException('An alias change needs one or more of: ' . implode(', ', self::ALIAS_COLUMNS) . '.');
        }
        $r = new Request(self::PROGRAM, self::ALIAS_SAVE, self::CMS_ALIAS);
        $r->add(Step::update('Item')->keys(['Item' => $item, 'Version' => $version])->data($data));
        $r->add(self::fixup());
        return $r;
    }

    /** Change the minimum stock of an item that already has stock settings. */
    public static function minimumStockUpdate(int|string $item, int|string $version, int|string $itemEntity, string $minimum, string $owner): Request
    {
        $r = new Request(self::PROGRAM, self::ITEM_SAVE, self::CMS_ITEM);
        $r->add(Step::updateVersion('Item')->keys(['Item' => $item, 'Version' => $version]));
        $r->add(Step::update('ItemEntity')->keys(['ItemEntity' => $itemEntity])->data(['MinimumStock' => $minimum]));
        return self::close($r, $owner);
    }

    /** Give an item its stock settings row, with a minimum stock. */
    public static function minimumStockInsert(int|string $item, int|string $version, string $minimum, string $owner): Request
    {
        $r = new Request(self::PROGRAM, self::ITEM_SAVE, self::CMS_ITEM);
        $r->add(Step::updateVersion('Item')->keys(['Item' => $item, 'Version' => $version]));
        $r->add(Step::insert('ItemEntity')->keys(['ItemEntity' => Step::IDENTITY])->data([
            'Item' => $item, 'Entity' => $owner, 'Context' => 'ST', 'MinimumStock' => $minimum,
            'Inactive' => 'false', 'ByRequestOnly' => 'false',
        ]));
        return self::close($r, $owner);
    }

    /** Set the preferred supplier of an item. */
    public static function supplierUpdate(int|string $item, int|string $version, int|string $supplier, string $owner): Request
    {
        $r = new Request(self::PROGRAM, self::ITEM_SAVE, self::CMS_ITEM);
        $r->add(Step::update('Item')->keys(['Item' => $item, 'Version' => $version])->data(['Supplier' => $supplier]));
        return self::close($r, $owner);
    }

    /**
     * A new item without pack sizes, tests or a recipe (a raw material or a
     * packaging item). The client sends a Version on the insert that the
     * new row does not take (it starts at 1 whatever is sent).
     */
    public static function itemCreate(string $code, string $description, string $unit, string $glGroup, string $group,
        bool $shipInContainer, string $user, string $area, string $keyVersion = '1'): Request
    {
        $r = new Request(self::PROGRAM, self::ITEM_SAVE, self::CMS_ITEM);
        $r->add(Step::insert('Item')->keys(['Item' => Step::IDENTITY, 'Version' => $keyVersion])->data([
            'ItemCode' => $code, 'Prototype' => 'false', 'Description' => $description, 'Context' => 'SUNDRY', 'Unit' => $unit,
            'PkgType' => '1', 'Treatment' => 'WHS', 'ReceivedApproved' => 'false', 'IsKit' => 'false', 'LotRequired' => '0',
            'QtyPerPackage' => '1', 'NoExpiry' => 'true', 'AutoApprove' => 'true', 'SpecificGravity' => '1',
            'PerPackageTolerance' => '100', 'PkgPerOuter' => '1', 'ByVolume' => 'false', 'UnderReceive' => '1', 'OverReceive' => '1',
            'GLGroup' => $glGroup, 'StandardCost' => '0.0000', 'CreateUser' => $user, 'CreateArea' => $area,
            'ShipInContainer' => Fmt::bool($shipInContainer), 'CertifiedOrganic' => 'false',
        ]));
        $no = static fn (array $columns): array => array_fill_keys($columns, 'false');
        $r->add(Step::insert('ItemChemical')->subs(1, 'Item', 'Item', 'keys')->data(
            ['ReverseWeightAllowed' => 'false', 'PPE' => 'A', 'DisposalGroup' => 'PENDING', 'HMISHealth' => '0', 'HMISFire' => '0', 'HMISReact' => '0']
            + $no(['HMISCarcinogen', 'HMISMutagen', 'HMISTeratogen', 'HMISIrritant', 'HMISSensitizer', 'HMISPoison', 'HMISToxic',
                   'HMISCorrosive', 'HMISOxidizer', 'HMISFlammable', 'HMISCombustible', 'Api', 'Drug', 'InSolution'])));
        $r->add(Step::insert('ItemCustom')->subs(1, 'Item', 'Item', 'keys')->data(
            $no(['ListCarcinogen', 'ListEDSTA', 'ListHPV', 'ListPBTP', 'ListPOP', 'Sara302', 'Sara313', 'Seveso', 'ListCRTQ'])
            + ['Group' => $group]));
        return self::close($r, $area);
    }

    /** What the item screen of the client ends every save with. */
    private static function close(Request $r, string $owner): Request
    {
        $r->add(Step::execute('InheritItemsPropertiesByPackagedProducts')->subs(1, 'Item', 'Item', 'data', null, '')
            ->data(['Owner' => $owner, 'ChangeTests' => 'false']));
        $r->add(self::fixup());
        return $r;
    }

    /** The manufacturer fix-up CMS runs on the item of step 1 after every save. */
    private static function fixup(): Step
    {
        return Step::execute('FixupItemManf')->subs(1, 'Item', 'Item', 'data', null, '');
    }
}

<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for item master data (CMS: Item Name Update).
 * Shapes traced from the CMS log; see docs/research/inventory-masterdata.md
 * and the fixtures under tests/fixtures/cms-requests/ItemNameUpdateDataSet.*.
 *
 * An alias is a row of Item with Context NAME whose ReplacedBy points at
 * the inventory item it is another name for.
 */
final class ItemRequests
{
    public const PROGRAM    = 'Insights Items';
    public const CMS_ALIAS  = 'Item Name Update';
    public const ALIAS_SAVE = 'ItemNameUpdateDataSet.SaveChanges';

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

    /** The manufacturer fix-up CMS runs on the item of step 1 after every save. */
    private static function fixup(): Step
    {
        return Step::execute('FixupItemManf')->subs(1, 'Item', 'Item', 'data', null, '');
    }
}

<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\WriteRefused;

/** What the item master-data flows read from CMS before planning a request. */
final class ItemState
{
    /** Contexts of items that hold stock, and so can have aliases. */
    public const STOCKED = ['PP', 'SUNDRY'];

    private const COLUMNS = 'i.Item, i.ItemCode, i.Description, i.Context, i.Version, i.ReplacedBy, i.Unit';

    /** @return null|array{item:int,code:string,description:string,context:string,version:int,replacedBy:?int,unit:string} */
    public static function byId(CmsReader $cms, int $id): ?array
    {
        return $id > 0 ? self::row($cms->fetch('SELECT ' . self::COLUMNS . ' FROM CMS.dbo.Item i WHERE i.Item = ?', [$id])) : null;
    }

    /** @return null|array{item:int,code:string,description:string,context:string,version:int,replacedBy:?int,unit:string} */
    public static function byCode(CmsReader $cms, string $code): ?array
    {
        return $code !== '' ? self::row($cms->fetch('SELECT ' . self::COLUMNS . ' FROM CMS.dbo.Item i WHERE i.ItemCode = ?', [$code])) : null;
    }

    /**
     * The inventory item an alias may stand for, by its code.
     * A code that is itself an alias resolves to the item behind it.
     *
     * @return array{item:int,code:string,description:string,context:string,version:int,replacedBy:?int,unit:string}
     */
    public static function target(CmsReader $cms, mixed $code): array
    {
        $text = strtoupper(trim((string) (is_scalar($code) ? $code : '')));
        if ($text === '') {
            throw new WriteRefused('Choose the item the alias stands for.');
        }
        $item = self::byCode($cms, $text);
        if ($item !== null && $item['replacedBy'] !== null) {
            $item = self::byId($cms, $item['replacedBy']);
        }
        if ($item === null) {
            throw new WriteRefused("Item {$text} was not found in CMS.");
        }
        if (!in_array($item['context'], self::STOCKED, true)) {
            throw new WriteRefused("Item {$item['code']} is not a stock item, so it cannot have an alias.");
        }
        return $item;
    }

    /** An alias by id. */
    public static function alias(CmsReader $cms, mixed $id): array
    {
        $alias = self::byId($cms, is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0);
        if ($alias === null) {
            throw new WriteRefused('The alias was not found in CMS. It may have been deleted.');
        }
        if ($alias['context'] !== 'NAME' || $alias['replacedBy'] === null) {
            throw new WriteRefused("{$alias['code']} is an item, not an alias.");
        }
        return $alias;
    }

    private static function row(?array $r): ?array
    {
        if ($r === null) {
            return null;
        }
        return [
            'item'        => (int) $r['Item'],
            'code'        => trim((string) $r['ItemCode']),
            'description' => trim((string) ($r['Description'] ?? '')),
            'context'     => trim((string) ($r['Context'] ?? '')),
            'version'     => (int) ($r['Version'] ?? 0),
            'replacedBy'  => ($r['ReplacedBy'] ?? null) === null ? null : (int) $r['ReplacedBy'],
            'unit'        => trim((string) ($r['Unit'] ?? '')),
        ];
    }
}

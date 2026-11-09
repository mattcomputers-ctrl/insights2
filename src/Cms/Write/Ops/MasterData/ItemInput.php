<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\WriteRefused;

/** Reading what a user typed for an item or an alias. */
final class ItemInput
{
    public const CODE_MAX        = 30;
    public const DESCRIPTION_MAX = 256;

    /** An item code: upper case as every code in CMS is, no control characters, at most 30 characters. */
    public static function code(mixed $v): string
    {
        $s = strtoupper(trim(preg_replace('/\s+/', ' ', is_scalar($v) ? (string) $v : '') ?? ''));
        if ($s === '') {
            throw new WriteRefused('Enter a code.');
        }
        if (!preg_match('/^[\x20-\x7E]+$/', $s)) {
            throw new WriteRefused('The code can only have plain letters, digits and punctuation.');
        }
        if (strlen($s) > self::CODE_MAX) {
            throw new WriteRefused('The code is too long (limit ' . self::CODE_MAX . ' characters).');
        }
        return $s;
    }

    /** A code no item or alias has yet. */
    public static function newCode(CmsReader $cms, mixed $v): string
    {
        $code  = self::code($v);
        $taken = ItemState::byCode($cms, $code);
        if ($taken !== null) {
            throw new WriteRefused("The code {$code} is already used" . ($taken['context'] === 'NAME' ? ' by another alias.' : ' by an item.'));
        }
        return $code;
    }

    public static function description(mixed $v): string
    {
        $s = trim(preg_replace('/\s+/', ' ', is_scalar($v) ? (string) $v : '') ?? '');
        if ($s === '') {
            throw new WriteRefused('Enter a description.');
        }
        if (mb_strlen($s) > self::DESCRIPTION_MAX) {
            throw new WriteRefused('The description is too long (limit ' . self::DESCRIPTION_MAX . ' characters).');
        }
        return $s;
    }

    /** A minimum stock: a number from 0 up, in the stock unit of the item. */
    public static function minimum(mixed $v): string
    {
        $s = str_replace(',', '', trim(is_scalar($v) ? (string) $v : ''));
        if ($s === '') {
            throw new WriteRefused('Enter the minimum stock.');
        }
        if (!preg_match('/^\d{1,7}(\.\d{1,4})?$/', $s)) {
            throw new WriteRefused('The minimum stock must be a number from 0 to 9,999,999.');
        }
        return Fmt::num($s);
    }

    /** One of the kinds of item that can be created here. */
    public static function kind(mixed $v): string
    {
        $k = is_scalar($v) ? trim((string) $v) : '';
        if (!isset(ItemRequests::KINDS[$k])) {
            throw new WriteRefused('Choose what kind of item it is.');
        }
        return $k;
    }
}

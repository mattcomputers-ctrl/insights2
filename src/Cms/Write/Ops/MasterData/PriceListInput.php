<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\Fmt;
use PII\Cms\Write\WriteRefused;

/** Reading what a user typed for a price list line. */
final class PriceListInput
{
    public const SUPPLIER_CODE_MAX = 20;

    /** A price: more than zero, at most four decimals. Sent as it was typed, as the client does. */
    public static function price(mixed $v): string
    {
        $s = str_replace([',', '$', ' '], '', trim(is_scalar($v) ? (string) $v : ''));
        if ($s === '') {
            throw new WriteRefused('Enter the price.');
        }
        if (!preg_match('/^\d{1,7}(\.\d{1,4})?$/', $s) || (float) $s <= 0.0) {
            throw new WriteRefused('The price must be a number above 0 with at most four decimals.');
        }
        return $s;
    }

    /** The supplier's own code for an item: at most 20 characters, may be blank. */
    public static function supplierCode(mixed $v): string
    {
        $s = trim(preg_replace('/\s+/', ' ', is_scalar($v) ? (string) $v : '') ?? '');
        if (!preg_match('/^[\x20-\x7E]*$/', $s)) {
            throw new WriteRefused("The supplier's code can only have plain letters, digits and punctuation.");
        }
        if (strlen($s) > self::SUPPLIER_CODE_MAX) {
            throw new WriteRefused("The supplier's code is too long (limit " . self::SUPPLIER_CODE_MAX . ' characters).');
        }
        return $s;
    }

    /** A quantity above zero (per package, or a minimum order), as CMS writes a number. */
    public static function quantity(mixed $v, string $what): string
    {
        $s = str_replace(',', '', trim(is_scalar($v) ? (string) $v : ''));
        if ($s === '') {
            throw new WriteRefused("Enter {$what}.");
        }
        if (!preg_match('/^\d{1,7}(\.\d{1,4})?$/', $s) || (float) $s <= 0.0) {
            throw new WriteRefused(ucfirst($what) . ' must be a number above 0.');
        }
        return Fmt::num($s);
    }
}

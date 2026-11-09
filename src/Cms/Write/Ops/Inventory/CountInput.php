<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Inventory;

use PII\Cms\Write\Fmt;
use PII\Cms\Write\WriteRefused;

/** Reading what a user typed for a count. */
final class CountInput
{
    public const MAX_LINES = 200;

    /** A counted quantity: a plain number, zero or more. */
    public static function qty(mixed $v, string $what): string
    {
        $s = str_replace(',', '', trim((string) $v));
        if ($s === '' || !preg_match('/^\d*\.?\d+$/', $s)) {
            throw new WriteRefused("{$what} needs a number, zero or more.");
        }
        if ((float) $s > 1.0e9) {
            throw new WriteRefused("{$what} is too large.");
        }
        return Fmt::num($s);
    }

    public static function description(mixed $v): string
    {
        $s = trim(preg_replace('/\s+/', ' ', (string) $v) ?? '');
        if (mb_strlen($s) > 50) {
            throw new WriteRefused('The description is too long (limit 50 characters).');
        }
        return $s;
    }

    /** The date of a count (Y-m-d), not in the future. */
    public static function date(mixed $v, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $s = trim((string) $v);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) {
            throw new WriteRefused('The count date is not a valid date.');
        }
        if ($d->format('Y-m-d') > $now->format('Y-m-d')) {
            throw new WriteRefused('The count date cannot be in the future.');
        }
        return $d;
    }

    /** One line of the review: what posting will do to an item. */
    public static function effect(string $unit, float $counted, float $onHand): string
    {
        $diff = $counted - $onHand;
        $text = InventoryState::show($counted) . " {$unit} counted, " . InventoryState::show($onHand) . ' on hand';
        if (abs($diff) < 5.0e-7) {
            return "{$text}: no change";
        }
        return "{$text}: " . ($diff > 0 ? 'adds ' : 'removes ') . InventoryState::show(abs($diff)) . " {$unit}";
    }
}

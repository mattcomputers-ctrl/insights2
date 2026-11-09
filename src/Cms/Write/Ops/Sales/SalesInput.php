<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Sales;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\WriteRefused;

/** Validation shared by the sales flows: typed lines, dates, numbers. */
final class SalesInput
{
    /** CMS's own counter for Ordr.ChangeNumber (bounds as its sequence is defined). */
    public const CHANGE_NUMBER     = 'ChangeNumber';
    public const CHANGE_NUMBER_MIN = 12750;
    public const CHANGE_NUMBER_MAX = 2147483647;

    /**
     * Turn one typed line (code, qty, price) into the template's line.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed> template line plus 'code' and 'amount' for the summary
     */
    public static function line(CmsReader $cms, array $raw, int $n, int $billTo, int $shipTo, bool $quote, \DateTimeInterface $on): array
    {
        $code = trim((string) ($raw['code'] ?? ''));
        if ($code === '') {
            throw new WriteRefused("Line {$n}: enter an item.");
        }
        $item = SalesState::resolveItem($cms, $code);
        if (isset($item['error'])) {
            throw new WriteRefused("Line {$n}: {$item['error']}");
        }
        if ($item['unit'] === '') {
            throw new WriteRefused("Line {$n}: item {$item['code']} has no unit in CMS.");
        }
        $qty = self::number($raw['qty'] ?? '', "Line {$n}: quantity");
        if ((float) $qty === 0.0) {
            throw new WriteRefused("Line {$n}: quantity cannot be zero.");
        }

        $typed     = trim((string) ($raw['price'] ?? ''));
        $priceList = null;
        if ($typed === '') {
            $priceList = SalesState::priceListRow($cms, $shipTo, $item['item'], $item['itemName'], $on)
                ?? ($billTo !== $shipTo ? SalesState::priceListRow($cms, $billTo, $item['item'], $item['itemName'], $on) : null);
            if ($priceList === null) {
                throw new WriteRefused("Line {$n}: enter a price for {$item['code']} (this customer has no price-list price for it).");
            }
            $price = SalesState::plain($priceList['price1']);
        } else {
            $price = self::number($typed, "Line {$n}: price");
            if ((float) $price < 0) {
                throw new WriteRefused("Line {$n}: price cannot be negative. Use a negative quantity for a credit.");
            }
        }

        return [
            'isOpen'       => !$quote,
            'manualPrice'  => $priceList === null,
            'price'        => $price,
            'qty'          => $qty,
            'qtyCommitted' => $quote ? null : ((float) $qty < 0 ? '0' : $qty),
            'item'         => $item['item'],
            'itemName'     => $item['itemName'],
            'description'  => $item['description'],
            'unit'         => $item['unit'],
            'priceList'    => $priceList,
            'code'         => $item['code'],
            'amount'       => (float) $qty * (float) $price,
        ];
    }

    /** Strip the summary-only keys before a line goes to the template. */
    public static function templateLine(array $line, ?string $owner = null): array
    {
        unset($line['code'], $line['amount']);
        if ($owner !== null) {
            $line['Owner'] = $owner;
        }
        return $line;
    }

    public static function number(mixed $v, string $what): string
    {
        $s = str_replace(',', '', trim((string) $v));
        if ($s === '' || !is_numeric($s) || !preg_match('/^-?\d*\.?\d+$/', $s)) {
            throw new WriteRefused("{$what} needs a number.");
        }
        return Fmt::num($s);
    }

    /** A typed Y-m-d date, or refuse. */
    public static function date(mixed $v, string $what): \DateTimeImmutable
    {
        $s = trim((string) $v);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) {
            throw new WriteRefused("{$what} is not a valid date.");
        }
        return $d;
    }

    public static function text(mixed $v, int $max, string $what): string
    {
        $s = trim(str_replace(["\r\n", "\r"], "\n", (string) $v));
        if (mb_strlen($s) > $max) {
            throw new WriteRefused("{$what} is too long (limit {$max} characters).");
        }
        return str_replace("\n", "\r\n", $s);
    }

    public static function money(float $v): string
    {
        return number_format($v, 2, '.', ',');
    }
}

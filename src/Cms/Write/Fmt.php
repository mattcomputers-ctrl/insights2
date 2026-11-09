<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Literal value formats the CMS request XML uses, as observed in the log
 * corpus: booleans are true/false, timestamps are local ISO without
 * milliseconds or zone, blank means "no value".
 */
final class Fmt
{
    public static function bool(bool $v): string
    {
        return $v ? 'true' : 'false';
    }

    public static function dateTime(\DateTimeInterface $dt): string
    {
        return $dt->format('Y-m-d\TH:i:s');
    }

    /** Midnight timestamp for a date-only field (CMS sends 2026-11-09T00:00:00). */
    public static function date(\DateTimeInterface $dt): string
    {
        return $dt->format('Y-m-d') . 'T00:00:00';
    }

    /**
     * A number the way the CMS client prints it: no thousands separators,
     * no exponent, no trailing zeros ("72", "20.01", "0.5", "-5").
     */
    public static function num(int|float|string $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            $v = trim($v);
            if (!is_numeric($v)) {
                throw new \InvalidArgumentException("Not a number: '{$v}'.");
            }
            if (preg_match('/^-?\d+$/', $v)) {
                return $v;
            }
            $v = (float) $v;
        }
        if (is_nan($v) || is_infinite($v)) {
            throw new \InvalidArgumentException('Not a finite number.');
        }
        $s = number_format($v, 10, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return ($s === '-0' || $s === '') ? '0' : $s;
    }

    public static function scalar(string|int|float|bool|null $v): string
    {
        return match (true) {
            $v === null   => '',
            is_bool($v)   => self::bool($v),
            is_string($v) => $v,
            default       => self::num($v),
        };
    }

    /**
     * Escape an attribute value the way SQL Server serialises its xml type
     * (which is what the Log table shows): & < > " and control whitespace.
     */
    public static function attr(string $v): string
    {
        return strtr($v, [
            '&'  => '&amp;',
            '<'  => '&lt;',
            '>'  => '&gt;',
            '"'  => '&quot;',
            "\r" => '&#x0D;',
            "\n" => '&#x0A;',
            "\t" => '&#x09;',
        ]);
    }
}

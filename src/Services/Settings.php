<?php

declare(strict_types=1);

namespace PII\Services;

use PII\Core\Database;

/**
 * Settings — typed access to the `settings` key/value table.
 * Values are strings in the table; JSON for structured values.
 */
class Settings
{
    /** @var array<string,?string> per-request cache */
    private static array $cache = [];
    private static bool $loaded = false;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::loadAll();
        return self::$cache[$key] ?? $default;
    }

    public static function getInt(string $key, int $default): int
    {
        $v = self::get($key);
        return ($v === null || !is_numeric($v)) ? $default : (int) $v;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : $v === '1';
    }

    public static function getJson(string $key, array $default = []): array
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        $decoded = json_decode($v, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function set(string $key, ?string $value): void
    {
        $db = Database::getInstance();
        if ($value === null) {
            $db->delete('settings', '`key` = ?', [$key]);
        } else {
            $existing = $db->fetch("SELECT 1 FROM settings WHERE `key` = ?", [$key]);
            if ($existing) {
                $db->update('settings', ['value' => $value], '`key` = ?', [$key]);
            } else {
                $db->insert('settings', ['key' => $key, 'value' => $value]);
            }
        }
        self::$cache[$key] = $value;
    }

    /** Re-read from the DB (worker loop calls this each tick). */
    public static function refresh(): void
    {
        self::$loaded = false;
        self::$cache = [];
    }

    private static function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }
        foreach (Database::getInstance()->fetchAll("SELECT `key`, `value` FROM settings") as $row) {
            self::$cache[$row['key']] = $row['value'];
        }
        self::$loaded = true;
    }
}

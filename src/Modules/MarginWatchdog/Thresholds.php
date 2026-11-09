<?php

declare(strict_types=1);

namespace PII\Modules\MarginWatchdog;

use PII\Core\Database;

/**
 * Thresholds — Per-user color thresholds for difference cells.
 *
 * Three "good direction" cases:
 *
 *   - revenue / dollars over packed cost / avg sale per unit / qty:
 *     UP is good. Default greens at +5%, reds at −5%.
 *
 *   - packed cost ($) / avg cost per unit / packed cost % of revenue / avg cost % of sale:
 *     DOWN is good. For percent-point fields the threshold is in pp,
 *     for percent-change fields it's in %.
 *
 * Stored under user_preferences with prefix "mw.threshold." so the
 * preferences page can render and update them generically.
 */
class Thresholds
{
    /**
     * Default thresholds. Each entry:
     *   direction: 'up_good'   → up = good (revenue, dollars over cost, avg sale, qty)
     *              'down_good' → down = good (packed cost, avg cost, cost % of revenue)
     *   unit:      'pct' (percentage change) | 'pp' (percentage points)
     *   green:     threshold for green
     *   red:       threshold for red
     *   yellow_window: |x| ≤ window → yellow neutral band
     *
     * For 'up_good': good when diff ≥ green, bad when diff ≤ -red, yellow in between.
     * For 'down_good': good when diff ≤ -green, bad when diff ≥ red, yellow in between.
     */
    public const DEFAULTS = [
        'revenue'           => ['direction' => 'up_good',   'unit' => 'pct', 'green' => 5.0, 'red' => 5.0],
        'packed_cost'       => ['direction' => 'down_good', 'unit' => 'pct', 'green' => 5.0, 'red' => 5.0],
        'dollars_over_cost' => ['direction' => 'up_good',   'unit' => 'pct', 'green' => 5.0, 'red' => 5.0],
        'cost_pct_revenue'  => ['direction' => 'down_good', 'unit' => 'pp',  'green' => 1.0, 'red' => 1.0],
        'avg_sale'          => ['direction' => 'up_good',   'unit' => 'pct', 'green' => 3.0, 'red' => 3.0],
        'avg_cost'          => ['direction' => 'down_good', 'unit' => 'pct', 'green' => 3.0, 'red' => 3.0],
        'avg_cost_pct'      => ['direction' => 'down_good', 'unit' => 'pp',  'green' => 1.0, 'red' => 1.0],
        'expected_cost_pct' => ['direction' => 'down_good', 'unit' => 'pp',  'green' => 1.0, 'red' => 1.0],
        'qty'               => ['direction' => 'up_good',   'unit' => 'pct', 'green' => 5.0, 'red' => 5.0],
    ];

    /** Human-readable labels for the threshold config UI. */
    public const LABELS = [
        'revenue'           => 'Revenue',
        'packed_cost'       => 'Packed cost ($)',
        'dollars_over_cost' => '$ over packed cost',
        'cost_pct_revenue'  => 'Packed cost % of revenue',
        'avg_sale'          => 'Avg sale per unit',
        'avg_cost'          => 'Avg cost per unit',
        'avg_cost_pct'      => 'Avg cost % of avg sale',
        'expected_cost_pct' => 'Expected cost % of comparison sale (forward-looking)',
        'qty'               => 'Quantity sold',
    ];

    /**
     * Load the user's thresholds with the three-tier resolution:
     *
     *     user preference   ←  highest priority (this user's customisation)
     *         ↓ fallback
     *     system default    ←  admin-set, applies to anyone without their own
     *         ↓ fallback
     *     code DEFAULTS     ←  shipped with the app
     *
     * Each metric is resolved independently — a user can override only the
     * thresholds they care about and inherit the rest from the system or
     * code defaults.
     */
    public static function forUser(int $userId): array
    {
        // Tier 2: system defaults (admin-set, all users)
        $system = self::systemDefaults();

        // Tier 1: this user's overrides
        $rows = Database::getInstance()->fetchAll(
            "SELECT `key`, `value` FROM user_preferences
             WHERE user_id = ? AND `key` LIKE 'mw.threshold.%'",
            [$userId]
        );
        $custom = [];
        foreach ($rows as $r) {
            $shortKey = substr($r['key'], strlen('mw.threshold.'));
            $decoded  = json_decode((string) $r['value'], true);
            if (is_array($decoded)) {
                $custom[$shortKey] = $decoded;
            }
        }

        $out = [];
        foreach (self::DEFAULTS as $metric => $codeDefault) {
            $out[$metric] = array_merge(
                $codeDefault,
                $system[$metric] ?? [],
                $custom[$metric] ?? []
            );
        }
        return $out;
    }

    /**
     * System-wide threshold defaults (admin-set via /preferences while
     * logged in as admin). Stored in the `settings` table under keys
     * `mw.threshold.system.{metric}`. Returns only metrics that have
     * been customised at the system level — others fall through to
     * DEFAULTS at the call site.
     *
     * @return array<string, array{direction:string, unit:string, green:float, red:float}>
     */
    public static function systemDefaults(): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'mw.threshold.system.%'"
        );
        $out = [];
        foreach ($rows as $r) {
            $shortKey = substr($r['key'], strlen('mw.threshold.system.'));
            $decoded  = json_decode((string) ($r['value'] ?? ''), true);
            if (is_array($decoded) && isset(self::DEFAULTS[$shortKey])) {
                $out[$shortKey] = $decoded;
            }
        }
        return $out;
    }

    /**
     * Save one metric's threshold as a system-wide default. Direction
     * and unit come from DEFAULTS (not user-editable). Admin only —
     * gating is enforced in the controller.
     */
    public static function saveSystemDefault(string $metric, float $green, float $red): void
    {
        if (!isset(self::DEFAULTS[$metric])) {
            throw new \InvalidArgumentException("Unknown metric: $metric");
        }
        $merged = array_merge(self::DEFAULTS[$metric], [
            'green' => max(0.0, $green),
            'red'   => max(0.0, $red),
        ]);
        $key = 'mw.threshold.system.' . $metric;
        $val = json_encode($merged);

        $db = Database::getInstance();
        $existing = $db->fetch("SELECT 1 FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            $db->update('settings', ['value' => $val], '`key` = ?', [$key]);
        } else {
            $db->insert('settings', ['key' => $key, 'value' => $val]);
        }
    }

    public static function resetSystemDefaults(): void
    {
        Database::getInstance()->delete(
            'settings',
            "`key` LIKE 'mw.threshold.system.%'",
            []
        );
    }

    /** Save a per-user threshold. Only green and red are user-editable; direction/unit are fixed. */
    public static function save(int $userId, string $metric, float $green, float $red): void
    {
        if (!isset(self::DEFAULTS[$metric])) {
            throw new \InvalidArgumentException("Unknown metric: $metric");
        }
        $merged = array_merge(self::DEFAULTS[$metric], [
            'green' => max(0.0, $green),
            'red'   => max(0.0, $red),
        ]);

        $db   = Database::getInstance();
        $key  = 'mw.threshold.' . $metric;
        $val  = json_encode($merged);
        // Upsert
        $existing = $db->fetch("SELECT 1 FROM user_preferences WHERE user_id = ? AND `key` = ?", [$userId, $key]);
        if ($existing) {
            $db->update('user_preferences', ['value' => $val], 'user_id = ? AND `key` = ?', [$userId, $key]);
        } else {
            $db->insert('user_preferences', ['user_id' => $userId, 'key' => $key, 'value' => $val]);
        }
    }

    public static function resetAll(int $userId): void
    {
        Database::getInstance()->delete(
            'user_preferences',
            "user_id = ? AND `key` LIKE 'mw.threshold.%'",
            [$userId]
        );
    }

    /**
     * Classify a difference value against a metric's thresholds.
     * Returns 'good' | 'bad' | 'warn' | 'neutral'.
     */
    public static function classify(string $metric, ?float $diff, array $thresholds): string
    {
        if ($diff === null) return 'neutral';

        $cfg = $thresholds[$metric] ?? self::DEFAULTS[$metric] ?? null;
        if ($cfg === null) return 'neutral';

        $green = (float) $cfg['green'];
        $red   = (float) $cfg['red'];
        $direction = $cfg['direction'];

        if ($direction === 'up_good') {
            if ($diff >=  $green) return 'good';
            if ($diff <= -$red)   return 'bad';
            return 'warn';
        }
        // down_good
        if ($diff <= -$green) return 'good';
        if ($diff >=  $red)   return 'bad';
        return 'warn';
    }

    /** Whether colors are enabled for this user (defaults to on). */
    public static function colorsEnabled(int $userId): bool
    {
        $row = Database::getInstance()->fetch(
            "SELECT `value` FROM user_preferences WHERE user_id = ? AND `key` = 'colors_enabled'",
            [$userId]
        );
        if ($row === null) return true;
        return $row['value'] !== '0';
    }

    public static function setColorsEnabled(int $userId, bool $enabled): void
    {
        $db   = Database::getInstance();
        $val  = $enabled ? '1' : '0';
        $existing = $db->fetch("SELECT 1 FROM user_preferences WHERE user_id = ? AND `key` = 'colors_enabled'", [$userId]);
        if ($existing) {
            $db->update('user_preferences', ['value' => $val], 'user_id = ? AND `key` = ?', [$userId, 'colors_enabled']);
        } else {
            $db->insert('user_preferences', ['user_id' => $userId, 'key' => 'colors_enabled', 'value' => $val]);
        }
    }
}

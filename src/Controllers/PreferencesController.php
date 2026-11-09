<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\CSRF;
use PII\Modules\MarginWatchdog\Thresholds;

require_once dirname(__DIR__) . '/Helpers/layout.php';

class PreferencesController
{
    public function index(): void
    {
        $userId = (int) current_user_id();

        layout('preferences/index', [
            'pageTitle'      => 'Preferences',
            'thresholds'     => Thresholds::forUser($userId),
            'systemDefaults' => Thresholds::systemDefaults(),
            'colorsOn'       => Thresholds::colorsEnabled($userId),
            'labels'         => Thresholds::LABELS,
            'isAdmin'        => is_admin(),
        ]);
    }

    public function save(): void
    {
        CSRF::validateRequest();
        $userId = (int) current_user_id();

        // Colors toggle
        $colorsEnabled = !empty($_POST['colors_enabled']);
        Thresholds::setColorsEnabled($userId, $colorsEnabled);

        // Per-metric thresholds — saved to this user's prefs
        $thresholds = $_POST['thresholds'] ?? [];
        foreach (array_keys(Thresholds::DEFAULTS) as $metric) {
            $green = (float) ($thresholds[$metric]['green'] ?? Thresholds::DEFAULTS[$metric]['green']);
            $red   = (float) ($thresholds[$metric]['red']   ?? Thresholds::DEFAULTS[$metric]['red']);
            Thresholds::save($userId, $metric, $green, $red);
        }

        $_SESSION['_flash']['success'] = 'Preferences saved.';
        redirect('/preferences');
    }

    /**
     * Admin-only: take whatever's currently in the form and save those
     * values as the system-wide default thresholds. Does NOT modify the
     * admin's own user preferences, and does NOT touch other users'
     * existing overrides — only acts as the fallback for users who
     * haven't customised a particular metric.
     */
    public function saveAsSystemDefault(): void
    {
        CSRF::validateRequest();
        if (!is_admin()) {
            http_response_code(403);
            echo 'Forbidden — admin only.';
            return;
        }

        $thresholds = $_POST['thresholds'] ?? [];
        foreach (array_keys(Thresholds::DEFAULTS) as $metric) {
            $green = (float) ($thresholds[$metric]['green'] ?? Thresholds::DEFAULTS[$metric]['green']);
            $red   = (float) ($thresholds[$metric]['red']   ?? Thresholds::DEFAULTS[$metric]['red']);
            Thresholds::saveSystemDefault($metric, $green, $red);
        }

        \PII\Core\Database::getInstance()->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => 'mw_threshold_system_default',
            'entity_id'   => 'all',
            'action'      => 'update',
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $_SESSION['_flash']['success'] = 'System-wide threshold defaults updated. Users without their own custom values will see these immediately.';
        redirect('/preferences');
    }

    public function reset(): void
    {
        CSRF::validateRequest();
        $userId = (int) current_user_id();
        Thresholds::resetAll($userId);
        $_SESSION['_flash']['success'] = 'Your thresholds reset to defaults (system or code).';
        redirect('/preferences');
    }

    /**
     * POST /theme — flip the current user's light/dark preference.
     * Available to every logged-in user (routed outside /preferences,
     * which follows Margin Watchdog's visibility).
     */
    public function toggleTheme(): void
    {
        CSRF::validateRequest();
        $userId = (int) current_user_id();
        $db     = \PII\Core\Database::getInstance();

        $row = $db->fetch(
            "SELECT `value` FROM user_preferences WHERE user_id = ? AND `key` = 'theme'",
            [$userId]
        );
        $new = (($row['value'] ?? 'light') === 'dark') ? 'light' : 'dark';
        if ($row) {
            $db->update('user_preferences', ['value' => $new], "user_id = ? AND `key` = 'theme'", [$userId]);
        } else {
            $db->insert('user_preferences', ['user_id' => $userId, 'key' => 'theme', 'value' => $new]);
        }
        back();
    }

    public function resetSystemDefaults(): void
    {
        CSRF::validateRequest();
        if (!is_admin()) {
            http_response_code(403);
            echo 'Forbidden — admin only.';
            return;
        }
        Thresholds::resetSystemDefaults();
        $_SESSION['_flash']['success'] = 'System-wide threshold defaults cleared. Users now fall through to code defaults.';
        redirect('/preferences');
    }
}

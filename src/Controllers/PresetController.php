<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\CSRF;
use PII\Core\Database;

require_once dirname(__DIR__) . '/Helpers/layout.php';

class PresetController
{
    public function index(): void
    {
        $userId = (int) current_user_id();

        $presets = Database::getInstance()->fetchAll(
            "SELECT id, name, baseline_start, baseline_end, comparison_start, comparison_end, created_at
             FROM date_range_presets
             WHERE user_id = ?
             ORDER BY name",
            [$userId]
        );

        layout('presets/index', [
            'pageTitle' => 'Saved date range presets',
            'presets'   => $presets,
        ]);
    }

    public function create(): void
    {
        CSRF::validateRequest();
        $userId = (int) current_user_id();

        $name   = trim((string) ($_POST['name'] ?? ''));
        $bs     = (string) ($_POST['baseline_start']   ?? '');
        $be     = (string) ($_POST['baseline_end']     ?? '');
        $cs     = (string) ($_POST['comparison_start'] ?? '');
        $ce     = (string) ($_POST['comparison_end']   ?? '');

        if ($name === '') {
            $_SESSION['_flash']['error'] = 'Preset name is required.';
            redirect('/presets');
        }
        foreach ([$bs, $be, $cs, $ce] as $d) {
            if (!valid_date($d)) {
                $_SESSION['_flash']['error'] = 'All four dates must be valid YYYY-MM-DD values.';
                redirect('/presets');
            }
        }
        if ($bs > $be || $cs > $ce) {
            $_SESSION['_flash']['error'] = 'Each range start must be on or before its end.';
            redirect('/presets');
        }

        $db = Database::getInstance();
        $exists = $db->fetch("SELECT id FROM date_range_presets WHERE user_id = ? AND name = ?", [$userId, $name]);
        if ($exists) {
            $_SESSION['_flash']['error'] = 'A preset with that name already exists.';
            redirect('/presets');
        }

        $db->insert('date_range_presets', [
            'user_id' => $userId,
            'name'    => $name,
            'baseline_start'   => $bs,
            'baseline_end'     => $be,
            'comparison_start' => $cs,
            'comparison_end'   => $ce,
        ]);

        $_SESSION['_flash']['success'] = 'Preset saved.';
        redirect('/presets');
    }

    public function delete(string $id): void
    {
        CSRF::validateRequest();
        $userId = (int) current_user_id();

        Database::getInstance()->delete(
            'date_range_presets',
            'id = ? AND user_id = ?',
            [(int) $id, $userId]
        );

        $_SESSION['_flash']['success'] = 'Preset deleted.';
        redirect('/presets');
    }
}

<?php

declare(strict_types=1);

namespace PII\Cms\Write;

use PII\Core\App;
use PII\Core\CMSDatabase;
use PII\Services\Settings;

/**
 * Where the writer's switches live.
 *
 *  - The write login is a SEPARATE connection kept in the untracked file
 *    config/cms.write.local.php (never in git, never in MariaDB). The
 *    everyday read connection is never used to write.
 *  - Live sending needs that file AND the admin setting
 *    cms_write.live_enabled. Anything else is dry-run.
 */
final class WriteConfig
{
    public const LIVE_SETTING        = 'cms_write.live_enabled';
    public const WORKSTATION_SETTING = 'cms_write.workstation';
    public const PROGRAM_SETTING     = 'cms_write.program_mode';

    public static function connectionFile(): string
    {
        return App::basePath() . '/config/cms.write.local.php';
    }

    /** The write connection settings, or null when none have been saved. */
    public static function connection(): ?array
    {
        $file = self::connectionFile();
        if (!is_file($file)) {
            return null;
        }
        $cfg = require $file;
        if (!is_array($cfg) || empty($cfg['host']) || empty($cfg['user']) || empty($cfg['name'])) {
            return null;
        }
        return $cfg;
    }

    public static function liveEnabled(): bool
    {
        return self::connection() !== null && Settings::getBool(self::LIVE_SETTING, false);
    }

    public static function mode(): string
    {
        return self::liveEnabled() ? 'live' : 'dry-run';
    }

    public static function workstation(): string
    {
        $ws = trim((string) Settings::get(self::WORKSTATION_SETTING, ''));
        return $ws !== '' ? $ws : 'INSIGHTS';
    }

    /** true = send the CMS client's own Program names instead of the Insights ones. */
    public static function cmsPrograms(): bool
    {
        return Settings::get(self::PROGRAM_SETTING, 'insights') === 'cms';
    }

    public static function appVersion(): string
    {
        return (string) App::config('app.version', '2.0.0');
    }

    /** The transport for this request: live only when fully configured and switched on. */
    public static function transport(): Transport
    {
        if (!self::liveEnabled()) {
            return new DryRunTransport();
        }
        // The kill switch and backup blackouts stop writes exactly as they stop reads.
        CMSDatabase::assertNotBlocked();
        return new LiveTransport((new CMSDatabase(self::connection()))->getPdo());
    }
}

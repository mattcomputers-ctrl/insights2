<?php

declare(strict_types=1);

namespace PII\Services\Sync;

use PII\Services\Settings;

/**
 * SyncSettings — the sync engine's knobs, read from the settings table.
 *
 * Blackout windows: JSON list of {days: [1..7 ISO, Mon=1], start: 'HH:MM',
 * end: 'HH:MM'}; a window may cross midnight (e.g. 23:30–02:00), in which
 * case it belongs to the day it STARTS on.
 */
class SyncSettings
{
    public static function autoEnabled(): bool
    {
        return Settings::getBool('sync.auto_enabled', true);
    }

    public static function intervalMinutes(): int
    {
        return max(5, min(240, Settings::getInt('sync.interval_minutes', 15)));
    }

    public static function timeoutSeconds(): int
    {
        return max(60, Settings::getInt('sync.timeout_seconds', 300));
    }

    public static function killSwitch(): bool
    {
        return Settings::getBool('cms.kill_switch', false);
    }

    /** @return list<array{days:list<int>,start:string,end:string}> */
    public static function blackouts(): array
    {
        $out = [];
        foreach (Settings::getJson('sync.blackouts') as $w) {
            if (!is_array($w) || empty($w['start']) || empty($w['end'])) {
                continue;
            }
            $days = array_values(array_filter(array_map('intval', (array) ($w['days'] ?? [])),
                fn($d) => $d >= 1 && $d <= 7));
            if ($days === []) {
                continue;
            }
            $out[] = ['days' => $days, 'start' => (string) $w['start'], 'end' => (string) $w['end']];
        }
        return $out;
    }

    /**
     * The blackout window covering $at (default now), or null.
     * Returns ['until' => DateTimeImmutable end of the window].
     */
    public static function activeBlackout(?\DateTimeImmutable $at = null): ?array
    {
        $at = $at ?? new \DateTimeImmutable('now');

        foreach (self::blackouts() as $w) {
            $end = self::windowCovers($w, $at);
            if ($end !== null) {
                return ['until' => $end];
            }
        }
        return null;
    }

    /**
     * Does this window cover $at? Returns the window's end time, or null.
     * Pure function (unit-tested): a window belongs to the ISO day it
     * STARTS on; one that crosses midnight may have started yesterday.
     *
     * @param array{days:list<int>,start:string,end:string} $w
     */
    public static function windowCovers(array $w, \DateTimeImmutable $at): ?\DateTimeImmutable
    {
        [$sh, $sm] = array_map('intval', explode(':', $w['start'] . ':0'));
        [$eh, $em] = array_map('intval', explode(':', $w['end'] . ':0'));
        $crossesMidnight = ($eh * 60 + $em) <= ($sh * 60 + $sm);

        foreach ($crossesMidnight ? [0, 1] : [0] as $daysAgo) {
            $startDay = $at->modify("-{$daysAgo} day");
            if (!in_array((int) $startDay->format('N'), $w['days'], true)) {
                continue;
            }
            $start = $startDay->setTime($sh, $sm);
            $end   = $start->setTime($eh, $em);
            if ($crossesMidnight) {
                $end = $end->modify('+1 day');
            }
            if ($at >= $start && $at < $end) {
                return $end;
            }
        }
        return null;
    }

    public static function lastSuccessAt(): ?string
    {
        return Settings::get('sync.last_success_at');
    }
}

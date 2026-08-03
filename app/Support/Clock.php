<?php

declare(strict_types=1);

namespace Prospector\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * All scheduling and display dates run through here so the app has one
 * definition of "today" — the business timezone, not the server's.
 */
final class Clock
{
    private static string $timezone = 'America/Chicago';

    public static function setTimezone(string $timezone): void
    {
        if ($timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            self::$timezone = $timezone;
        }
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::$timezone);
    }

    public static function timezoneName(): string
    {
        return self::$timezone;
    }

    /** Current local time in the business timezone. */
    public static function local(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone());
    }

    /** UTC timestamp string used for every stored date column. */
    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /** Local business date, e.g. 2026-08-03. */
    public static function today(): string
    {
        return self::local()->format('Y-m-d');
    }

    /** Render a stored UTC timestamp in the business timezone. */
    public static function display(?string $utc, string $format = 'M j, Y g:ia'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }

        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(self::timezone())
                ->format($format);
        } catch (\Exception) {
            return $utc;
        }
    }

    public static function relative(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }

        try {
            $then = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return $utc;
        }

        $seconds = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $then->getTimestamp();

        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);

            return $m . ($m === 1 ? ' min ago' : ' mins ago');
        }
        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);

            return $h . ($h === 1 ? ' hour ago' : ' hours ago');
        }
        $d = intdiv($seconds, 86400);
        if ($d < 30) {
            return $d . ($d === 1 ? ' day ago' : ' days ago');
        }

        return self::display($utc, 'M j, Y');
    }

    public static function isWeekend(?DateTimeImmutable $when = null): bool
    {
        $when ??= self::local();

        return in_array((int) $when->format('N'), [6, 7], true);
    }
}

<?php

declare(strict_types=1);

namespace Zuste\Helpers;

/**
 * Minimal five-field cron expression matcher, used by `cron.php --due` to
 * run every scheduled job from a single crontab entry.
 *
 * Fields: minute, hour, day of month, month, day of week. Each accepts `*`,
 * a number, a range `a-b`, a step `*\/s`, `a-b/s` or `a/s`, and comma lists.
 * Numbers only, no month or weekday names. Day of week takes 0-7, where 0
 * and 7 are both Sunday. As in Vixie cron, when day of month and day of week
 * are both restricted, either one matching is enough.
 */
final class CronExpression
{
    /** Inclusive bounds per field. */
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    public static function isValid(string $expression): bool
    {
        return self::parse($expression) !== null;
    }

    /** Does the expression fire in the minute that contains $at? */
    public static function matches(string $expression, \DateTimeInterface $at): bool
    {
        $fields = self::parse($expression);

        if ($fields === null) {
            return false;
        }

        [$minutes, $hours, $daysOfMonth, $months, $daysOfWeek, $domRestricted, $dowRestricted] = $fields;

        if (!isset($minutes[(int) $at->format('i')], $hours[(int) $at->format('G')], $months[(int) $at->format('n')])) {
            return false;
        }

        $weekday  = (int) $at->format('w');
        $domMatch = isset($daysOfMonth[(int) $at->format('j')]);
        $dowMatch = isset($daysOfWeek[$weekday]) || ($weekday === 0 && isset($daysOfWeek[7]));

        if ($domRestricted && $dowRestricted) {
            return $domMatch || $dowMatch;
        }

        return $domMatch && $dowMatch;
    }

    /**
     * @return array{0:array<int,true>,1:array<int,true>,2:array<int,true>,3:array<int,true>,4:array<int,true>,5:bool,6:bool}|null
     */
    private static function parse(string $expression): ?array
    {
        $parts = preg_split('/\s+/', trim($expression));

        if ($parts === false || count($parts) !== 5) {
            return null;
        }

        $sets = [];

        foreach ($parts as $index => $part) {
            [$min, $max] = self::RANGES[$index];
            $set         = [];

            foreach (explode(',', $part) as $item) {
                if (preg_match('/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/', $item, $m) !== 1) {
                    return null;
                }

                $step = isset($m[2]) ? (int) $m[2] : 1;

                if ($m[1] === '*') {
                    [$from, $to] = [$min, $max];
                } elseif (str_contains($m[1], '-')) {
                    [$from, $to] = array_map('intval', explode('-', $m[1]));
                } else {
                    // `5/15` means "from 5, every 15"; a bare number is itself.
                    $from = (int) $m[1];
                    $to   = isset($m[2]) ? $max : $from;
                }

                if ($step < 1 || $from < $min || $to > $max || $from > $to) {
                    return null;
                }

                for ($value = $from; $value <= $to; $value += $step) {
                    $set[$value] = true;
                }
            }

            $sets[] = $set;
        }

        $sets[] = $parts[2] !== '*';
        $sets[] = $parts[4] !== '*';

        return $sets;
    }
}

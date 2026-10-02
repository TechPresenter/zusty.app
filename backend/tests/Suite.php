<?php

declare(strict_types=1);

namespace Zuste\Tests;

/**
 * Assertion and reporting harness for the API integration suite.
 *
 * Kept deliberately small — no PHPUnit dependency, because the backend ships
 * with no Composer autoloader and the whole point is to exercise the deployed
 * artefact exactly as it runs in production.
 *
 * A check never throws: a failing assertion records the failure and returns
 * false, so one broken endpoint does not abort the remaining 200 checks.
 */
final class Suite
{
    /** @var list<array{group:string,name:string,ok:bool,detail:string,ms:float}> */
    private static array $results = [];

    private static string $group = 'general';

    /** Groups that were skipped wholesale, with the reason. */
    private static array $skipped = [];

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    /**
     * Clears the rate-limit buckets so a suite starts with a full budget.
     *
     * A run makes hundreds of calls in seconds — far faster than any real
     * client — so without this the throttle fires part-way through and every
     * downstream assertion in that group fails on a 429 rather than on the
     * behaviour it was written to check. Those cascades are noise that hides
     * genuine defects.
     *
     * Call it at the TOP of a suite. The security suite deliberately does not,
     * because proving the throttle actually fires is its job.
     */
    public static function clearRateLimits(): void
    {
        try {
            \Zuste\Core\Database::execute('DELETE FROM `rate_limits`');
        } catch (\Throwable $e) {
            echo "  \033[33mnote\033[0m  could not reset rate limits: {$e->getMessage()}\n";
        }
    }

    /** Records that a whole group could not run, and why. Never silent. */
    public static function skipGroup(string $name, string $reason): void
    {
        self::$skipped[$name] = $reason;
        echo "\n\033[1m{$name}\033[0m\n";
        echo "  \033[33mSKIP\033[0m  {$reason}\n";
    }

    /**
     * The core assertion.
     *
     * @param bool|callable():bool $condition
     */
    public static function check(string $name, mixed $condition, string $detail = ''): bool
    {
        $started = microtime(true);

        try {
            $ok = is_callable($condition) ? (bool) $condition() : (bool) $condition;
        } catch (\Throwable $e) {
            $ok = false;
            $detail = get_class($e) . ': ' . $e->getMessage();
        }

        $ms = (microtime(true) - $started) * 1000;

        self::$results[] = [
            'group'  => self::$group,
            'name'   => $name,
            'ok'     => $ok,
            'detail' => $ok ? '' : $detail,
            'ms'     => $ms,
        ];

        if ($ok) {
            echo "  \033[32mPASS\033[0m  {$name}\n";
        } else {
            echo "  \033[31mFAIL\033[0m  {$name}";
            echo $detail === '' ? "\n" : "\n        {$detail}\n";
        }

        return $ok;
    }

    /** Asserts a response has the expected status, showing the body when it does not. */
    public static function status(string $name, Res $res, int|array $expected): bool
    {
        $allowed = is_array($expected) ? $expected : [$expected];

        return self::check(
            $name,
            in_array($res->status, $allowed, true),
            sprintf('expected %s, got %s', implode('|', $allowed), $res->describe())
        );
    }

    /** Asserts the envelope reports success AND the status is 2xx. */
    public static function ok(string $name, Res $res, int|array $expected = [200, 201]): bool
    {
        $allowed = is_array($expected) ? $expected : [$expected];

        return self::check(
            $name,
            in_array($res->status, $allowed, true) && $res->success(),
            sprintf('expected success %s, got %s', implode('|', $allowed), $res->describe())
        );
    }

    public static function equals(string $name, mixed $actual, mixed $expected): bool
    {
        return self::check(
            $name,
            $actual === $expected,
            sprintf('expected %s, got %s', json_encode($expected), json_encode($actual))
        );
    }

    public static function notEmpty(string $name, mixed $value, string $detail = ''): bool
    {
        $empty = $value === null || $value === '' || $value === [] || $value === 0;

        return self::check($name, !$empty, $detail !== '' ? $detail : 'value was empty: ' . json_encode($value));
    }

    // -----------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------

    public static function passed(): int
    {
        return count(array_filter(self::$results, static fn (array $r): bool => $r['ok']));
    }

    public static function failed(): int
    {
        return count(array_filter(self::$results, static fn (array $r): bool => !$r['ok']));
    }

    public static function total(): int
    {
        return count(self::$results);
    }

    /** @return list<array{group:string,name:string,ok:bool,detail:string,ms:float}> */
    public static function results(): array
    {
        return self::$results;
    }

    public static function skipped(): array
    {
        return self::$skipped;
    }

    public static function summary(): void
    {
        $failed = array_values(array_filter(self::$results, static fn (array $r): bool => !$r['ok']));

        echo "\n" . str_repeat('=', 72) . "\n";

        if ($failed !== []) {
            echo "\033[1mFailures\033[0m\n\n";

            foreach ($failed as $failure) {
                echo "  \033[31m✗\033[0m {$failure['group']} › {$failure['name']}\n";
                if ($failure['detail'] !== '') {
                    echo "      {$failure['detail']}\n";
                }
            }

            echo "\n";
        }

        if (self::$skipped !== []) {
            echo "\033[1mSkipped groups\033[0m\n\n";
            foreach (self::$skipped as $group => $reason) {
                echo "  \033[33m•\033[0m {$group}: {$reason}\n";
            }
            echo "\n";
        }

        // Per-group tally, so it is obvious which subsystem is weak.
        $byGroup = [];
        foreach (self::$results as $result) {
            $byGroup[$result['group']] ??= ['pass' => 0, 'fail' => 0];
            $byGroup[$result['group']][$result['ok'] ? 'pass' : 'fail']++;
        }

        echo "\033[1mBy group\033[0m\n\n";
        foreach ($byGroup as $group => $tally) {
            $mark = $tally['fail'] === 0 ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
            printf("  %s %-42s %3d passed  %3d failed\n", $mark, $group, $tally['pass'], $tally['fail']);
        }

        printf(
            "\n%s  %d checks — \033[32m%d passed\033[0m, %s%d failed\033[0m"
            . "   (%d HTTP requests, %.1fs)\n",
            self::failed() === 0 ? "\033[42m\033[30m PASS \033[0m" : "\033[41m\033[37m FAIL \033[0m",
            self::total(),
            self::passed(),
            self::failed() === 0 ? "\033[32m" : "\033[31m",
            self::failed(),
            Http::$requestCount,
            Http::$totalMs / 1000
        );
    }

    /** Writes a machine-readable run record for the final audit report. */
    public static function writeJson(string $path): void
    {
        $payload = [
            'total'    => self::total(),
            'passed'   => self::passed(),
            'failed'   => self::failed(),
            'requests' => Http::$requestCount,
            'httpMs'   => round(Http::$totalMs, 1),
            'skipped'  => self::$skipped,
            'slowest'  => Http::slowest(),
            'results'  => self::$results,
        ];

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

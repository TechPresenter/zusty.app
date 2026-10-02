<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Minimal .env loader.
 *
 * Deliberately dependency-free: Hostinger shared hosting has no Composer,
 * so we parse the file ourselves. Values are cached in a static array —
 * we never write to $_ENV/putenv() so a `phpinfo()` leak cannot expose
 * secrets.
 */
final class Env
{
    /** @var array<string,string>|null */
    private static ?array $values = null;

    private static string $path = '';

    /**
     * Parse the given .env file. Safe to call more than once; the first
     * successful load wins unless $force is set.
     */
    public static function load(string $path, bool $force = false): void
    {
        if (self::$values !== null && !$force) {
            return;
        }

        self::$path   = $path;
        self::$values = [];

        if (!is_readable($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || str_starts_with($line, '//')) {
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            if ($key === '') {
                continue;
            }

            // Trailing inline comments. `.env.example` documents its keys with
            // them ("SESSION_SECURE=true   # requires HTTPS"), so without this
            // the comment lands inside the value and getBool()/getString() read
            // the wrong thing. A quoted value ends at its closing quote;
            // otherwise a hash only starts a comment when whitespace precedes
            // it, which keeps `COLOR=#fff` and `URL=https://x/a#b` intact.
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $end = strpos($value, $value[0], 1);
                if ($end !== false) {
                    $value = substr($value, 0, $end + 1);
                }
            } elseif ($value !== '' && preg_match('/\s#/', $value, $m, PREG_OFFSET_CAPTURE) === 1) {
                $value = rtrim(substr($value, 0, (int) $m[0][1]));
            }

            $value = self::stripQuotes($value);

            self::$values[$key] = $value;
        }

        fclose($handle);
    }

    /**
     * Read a value, falling back to real environment variables (useful for
     * container deployments) and finally to $default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$values === null) {
            self::$values = [];
        }

        if (array_key_exists($key, self::$values)) {
            return self::cast(self::$values[$key]);
        }

        $fromServer = $_SERVER[$key] ?? getenv($key);
        if ($fromServer !== false && $fromServer !== null) {
            return self::cast(self::stripQuotes((string) $fromServer));
        }

        return $default;
    }

    public static function getString(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    public static function getList(string $key, string $separator = ','): array
    {
        $raw = self::getString($key);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode($separator, $raw)), static fn ($v) => $v !== ''));
    }

    public static function has(string $key): bool
    {
        return self::$values !== null && array_key_exists($key, self::$values);
    }

    public static function path(): string
    {
        return self::$path;
    }

    /**
     * Convert the literal strings true/false/null into real PHP types.
     * Everything else stays a string so numeric ids keep leading zeros.
     */
    private static function cast(string $value): mixed
    {
        return match (strtolower($value)) {
            'true'           => true,
            'false'          => false,
            'null', '(null)' => null,
            'empty'          => '',
            default          => $value,
        };
    }

    private static function stripQuotes(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last  = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}

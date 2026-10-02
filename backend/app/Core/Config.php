<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Two-tier configuration.
 *
 *  1. File config  — `config/*.php`, resolved from .env. Immutable at runtime,
 *                    used for infrastructure (database, keys, paths).
 *  2. DB settings  — the `settings` table, editable from the admin panel and
 *                    used for business rules (fees, limits, toggles).
 *
 * Business code should read business rules through `Config::setting()` so an
 * operator can change them without a deploy, and infrastructure through
 * `Config::get()`.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    /** @var array<string,array<string,string|null>>|null */
    private static ?array $settings = null;

    private static bool $loaded = false;

    /**
     * Load every PHP file in the config directory.
     *
     * Each file returns a map of top-level sections (`['app' => [...], 'database' => [...]]`)
     * which are merged into one dotted namespace, so `Config::get('database.host')`
     * works regardless of which file declared the section.
     */
    public static function load(string $configDir): void
    {
        if (self::$loaded) {
            return;
        }

        foreach (glob(rtrim($configDir, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $sections = require $file;

            if (!is_array($sections)) {
                continue;
            }

            foreach ($sections as $section => $values) {
                self::$items[$section] = is_array($values) && isset(self::$items[$section]) && is_array(self::$items[$section])
                    ? array_replace_recursive(self::$items[$section], $values)
                    : $values;
            }
        }

        self::$loaded = true;
    }

    /** Read file config with dot notation, e.g. `Config::get('database.host')`. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $cursor   = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /** Override a file config value at runtime (tests, CLI overrides). */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor   = &self::$items;

        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }

        $cursor = $value;
    }

    // -----------------------------------------------------------------
    // Database-backed settings
    // -----------------------------------------------------------------

    /**
     * Read an editable platform setting, e.g. `Config::setting('commission', 'platform_fee_percent')`.
     * Encrypted settings are decrypted transparently.
     */
    public static function setting(string $group, string $key, mixed $default = null): mixed
    {
        self::loadSettings();

        $row = self::$settings[$group][$key] ?? null;
        if ($row === null) {
            return $default;
        }

        return $row;
    }

    public static function settingString(string $group, string $key, string $default = ''): string
    {
        $value = self::setting($group, $key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function settingInt(string $group, string $key, int $default = 0): int
    {
        $value = self::setting($group, $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function settingFloat(string $group, string $key, float $default = 0.0): float
    {
        $value = self::setting($group, $key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public static function settingBool(string $group, string $key, bool $default = false): bool
    {
        $value = self::setting($group, $key, null);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<mixed> */
    public static function settingArray(string $group, string $key, array $default = []): array
    {
        $value = self::setting($group, $key, null);
        if ($value === null || $value === '') {
            return $default;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    /** @return array<string,string|null> */
    public static function settingGroup(string $group): array
    {
        self::loadSettings();

        return self::$settings[$group] ?? [];
    }

    /** Drop the in-process cache so the next read hits the database. */
    public static function flushSettings(): void
    {
        self::$settings = null;
    }

    private static function loadSettings(): void
    {
        if (self::$settings !== null) {
            return;
        }

        self::$settings = [];

        try {
            $rows = Database::select(
                'SELECT `group_key`, `setting_key`, `value`, `is_encrypted` FROM `settings`'
            );
        } catch (\Throwable $e) {
            // The settings table may not exist yet during installation.
            return;
        }

        foreach ($rows as $row) {
            $value = $row['value'];

            if ((int) $row['is_encrypted'] === 1 && is_string($value) && $value !== '') {
                $value = Crypto::decrypt($value) ?? '';
            }

            self::$settings[$row['group_key']][$row['setting_key']] = $value;
        }
    }
}

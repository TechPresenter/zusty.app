<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Two-tier cache: an in-process array for the current request, backed by the
 * `cache_entries` table for persistence across requests.
 *
 * Shared hosting rarely offers Redis, Memcached or even APCu, so a database
 * cache is the only option that works everywhere. It is still a large win:
 * a cached settings read is one indexed primary-key lookup instead of a
 * full table scan plus decryption on every request.
 *
 * When APCu *is* available it is used as an additional layer in front of the
 * database, because a local memory hit avoids the round trip entirely.
 */
final class Cache
{
    /** @var array<string,mixed> Per-request memoisation. */
    private static array $memory = [];

    /** @var array<string,int> Namespaced key => hits seen this request, not yet written. */
    private static array $pendingHits = [];

    private static bool $hitFlushScheduled = false;

    private static bool $apcuAvailable;

    private static bool $tableAvailable = true;

    /** Resolved once per request from the `cache` settings group. */
    private static ?bool $enabled = null;

    private static ?int $configuredTtl = null;

    /** Latch: Config may one day be cached, and must not recurse into here. */
    private static bool $readingSettings = false;

    /** Read a value, or null when absent or expired. */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::enabled()) {
            return $default;
        }

        if (array_key_exists($key, self::$memory)) {
            return self::$memory[$key];
        }

        if (self::apcu()) {
            $value = apcu_fetch(self::namespaced($key), $hit);

            if ($hit) {
                return self::$memory[$key] = $value;
            }
        }

        if (!self::$tableAvailable) {
            return $default;
        }

        try {
            $row = Database::selectOne(
                'SELECT `value` FROM `cache_entries`
                 WHERE `cache_key` = :key AND (`expires_at` IS NULL OR `expires_at` > NOW())',
                ['key' => self::namespaced($key)]
            );
        } catch (\Throwable) {
            // The table may not exist yet during installation.
            self::$tableAvailable = false;

            return $default;
        }

        if ($row === null) {
            return $default;
        }

        $value = self::decode((string) $row['value']);

        // Hit counting is useful for the Cache Manager screen, but it must
        // never slow a read down. PHP has no fire-and-forget statement, so the
        // increments are accumulated here and written once, after the response.
        self::recordHit(self::namespaced($key));

        if (self::apcu()) {
            apcu_store(self::namespaced($key), $value, 300);
        }

        return self::$memory[$key] = $value;
    }

    /**
     * Store a value.
     *
     * @param int|null $ttl Seconds; null means it never expires. Omit it and
     *                      the `cache.default_ttl` setting is used.
     */
    public static function put(string $key, mixed $value, ?int $ttl = 3600, string $group = 'default'): bool
    {
        if (!self::enabled()) {
            return false;
        }

        // Distinguishing "omitted" from "explicitly 3600" keeps every existing
        // caller on the TTL it asked for while honouring the configured default.
        if (func_num_args() < 3) {
            $ttl = self::defaultTtl();
        }

        self::$memory[$key] = $value;

        // The write below resets `hits` to 0, so any increment still queued for
        // this key belongs to the entry being replaced.
        unset(self::$pendingHits[self::namespaced($key)]);

        if (self::apcu()) {
            apcu_store(self::namespaced($key), $value, $ttl ?? 0);
        }

        if (!self::$tableAvailable) {
            return false;
        }

        try {
            Database::execute(
                'INSERT INTO `cache_entries` (`cache_key`, `cache_group`, `value`, `expires_at`, `created_at`)
                 VALUES (:key, :group, :value, :expires, NOW())
                 ON DUPLICATE KEY UPDATE
                    `value` = VALUES(`value`),
                    `cache_group` = VALUES(`cache_group`),
                    `expires_at` = VALUES(`expires_at`),
                    `hits` = 0,
                    `created_at` = NOW()',
                [
                    'key'     => self::namespaced($key),
                    'group'   => $group,
                    'value'   => self::encode($value),
                    'expires' => $ttl === null ? null : date('Y-m-d H:i:s', time() + $ttl),
                ]
            );

            return true;
        } catch (\Throwable $e) {
            Logger::warning('Cache write failed', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Read through: return the cached value, or compute, store and return it.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function remember(string $key, ?int $ttl, callable $callback, string $group = 'default'): mixed
    {
        $cached = self::get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();

        // Never cache null — it is indistinguishable from a miss.
        if ($value !== null) {
            self::put($key, $value, $ttl, $group);
        }

        return $value;
    }

    public static function forget(string $key): bool
    {
        unset(self::$memory[$key], self::$pendingHits[self::namespaced($key)]);

        if (self::apcu()) {
            apcu_delete(self::namespaced($key));
        }

        try {
            Database::execute('DELETE FROM `cache_entries` WHERE `cache_key` = :key', ['key' => self::namespaced($key)]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Drop every entry in a group — used after a settings or CMS change. */
    public static function forgetGroup(string $group): int
    {
        self::$memory      = [];
        self::$pendingHits = [];

        if (self::apcu()) {
            apcu_clear_cache();
        }

        try {
            return Database::execute('DELETE FROM `cache_entries` WHERE `cache_group` = :group', ['group' => $group]);
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function flush(): int
    {
        self::$memory      = [];
        self::$pendingHits = [];

        if (self::apcu()) {
            apcu_clear_cache();
        }

        try {
            return Database::execute('DELETE FROM `cache_entries`');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Write the hit counters accumulated by this request.
     *
     * Registered as a shutdown handler the first time a read hits, so the write
     * happens after the response instead of in the middle of it. Public because
     * a long-running CLI worker wants to flush between batches rather than hold
     * every increment until the process ends.
     *
     * @return int Rows touched.
     */
    public static function flushHits(): int
    {
        $pending           = self::$pendingHits;
        self::$pendingHits = [];

        if ($pending === [] || !self::$tableAvailable) {
            return 0;
        }

        $cases  = [];
        $in     = [];
        $params = [];
        $i      = 0;

        foreach ($pending as $namespacedKey => $count) {
            $params['k' . $i] = $namespacedKey;
            $params['w' . $i] = $namespacedKey;
            $params['n' . $i] = $count;

            $cases[] = 'WHEN :k' . $i . ' THEN `hits` + :n' . $i;
            $in[]    = ':w' . $i;

            $i++;
        }

        try {
            return Database::execute(
                'UPDATE `cache_entries`
                    SET `hits` = CASE `cache_key` ' . implode(' ', $cases) . ' ELSE `hits` END
                  WHERE `cache_key` IN (' . implode(', ', $in) . ')',
                $params
            );
        } catch (\Throwable) {
            // The counter is decorative; never let it surface as an error.
            return 0;
        }
    }

    /** Remove only expired rows. Called by the tokens:prune job. */
    public static function prune(): int
    {
        try {
            return Database::execute('DELETE FROM `cache_entries` WHERE `expires_at` IS NOT NULL AND `expires_at` <= NOW()');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Statistics for the Cache Manager screen.
     *
     * @return array<string,mixed>
     */
    public static function stats(): array
    {
        // The Cache Manager is the one screen that reads the counters, so pay
        // for the batched write here rather than leave it a request behind.
        self::flushHits();

        try {
            $summary = Database::selectOne(
                'SELECT COUNT(*) AS `entries`,
                        COALESCE(SUM(CHAR_LENGTH(`value`)), 0) AS `bytes`,
                        COALESCE(SUM(`hits`), 0) AS `hits`,
                        SUM(CASE WHEN `expires_at` IS NOT NULL AND `expires_at` <= NOW() THEN 1 ELSE 0 END) AS `expired`
                 FROM `cache_entries`'
            ) ?? [];

            $groups = Database::select(
                'SELECT `cache_group`, COUNT(*) AS `entries`,
                        COALESCE(SUM(CHAR_LENGTH(`value`)), 0) AS `bytes`,
                        COALESCE(SUM(`hits`), 0) AS `hits`
                 FROM `cache_entries` GROUP BY `cache_group` ORDER BY `entries` DESC'
            );
        } catch (\Throwable) {
            return ['entries' => 0, 'bytes' => 0, 'hits' => 0, 'expired' => 0, 'groups' => [], 'driver' => 'unavailable'];
        }

        return [
            'entries' => (int) ($summary['entries'] ?? 0),
            'bytes'   => (int) ($summary['bytes'] ?? 0),
            'hits'    => (int) ($summary['hits'] ?? 0),
            'expired' => (int) ($summary['expired'] ?? 0),
            'groups'  => $groups,
            'driver'  => self::apcu() ? 'database + apcu' : 'database',
        ];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * The `cache.enabled` kill switch. Reads and writes become no-ops when it
     * is off; the management operations (forget, flush, prune, stats) keep
     * working so the Cache Manager can still clear what is already stored.
     */
    private static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        if (self::$readingSettings) {
            return true;   // Fail open rather than recurse.
        }

        self::$readingSettings = true;

        try {
            self::$enabled = Config::settingBool('cache', 'enabled', true);
        } catch (\Throwable) {
            self::$enabled = true;
        } finally {
            self::$readingSettings = false;
        }

        return self::$enabled;
    }

    /** TTL applied when a caller does not name one. */
    private static function defaultTtl(): int
    {
        if (self::$configuredTtl !== null) {
            return self::$configuredTtl;
        }

        if (self::$readingSettings) {
            return 3600;
        }

        self::$readingSettings = true;

        try {
            $ttl = Config::settingInt('cache', 'default_ttl', 3600);
        } catch (\Throwable) {
            $ttl = 3600;
        } finally {
            self::$readingSettings = false;
        }

        return self::$configuredTtl = $ttl > 0 ? $ttl : 3600;
    }

    /** Queue a hit for the batched write, scheduling the flush on first use. */
    private static function recordHit(string $namespacedKey): void
    {
        self::$pendingHits[$namespacedKey] = (self::$pendingHits[$namespacedKey] ?? 0) + 1;

        if (!self::$hitFlushScheduled) {
            self::$hitFlushScheduled = true;
            register_shutdown_function([self::class, 'flushHits']);
        }
    }

    /** Namespacing by app version means a deploy invalidates stale shapes. */
    private static function namespaced(string $key): string
    {
        return substr('z' . (string) Config::get('app.version', '1') . ':' . $key, 0, 191);
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode(['v' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decode(string $raw): mixed
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) && array_key_exists('v', $decoded) ? $decoded['v'] : null;
    }

    private static function apcu(): bool
    {
        return self::$apcuAvailable ??= (
            function_exists('apcu_fetch')
            && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOL)
            && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL))
        );
    }
}

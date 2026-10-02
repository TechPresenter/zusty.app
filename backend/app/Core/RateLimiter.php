<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Fixed-window rate limiter backed by the `rate_limits` table.
 *
 * A single atomic upsert both increments the counter and resets an expired
 * window, so concurrent requests cannot race past the limit.
 */
final class RateLimiter
{
    /**
     * Register a hit and report whether the caller is still within budget.
     *
     * @return array{allowed:bool,hits:int,remaining:int,retry_after:int}
     */
    public static function hit(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $bucket = self::bucketKey($key);
        $now    = time();

        // One statement: insert a fresh window, or increment the live one,
        // or restart the window if the stored one has already expired.
        Database::execute(
            'INSERT INTO `rate_limits` (`bucket_key`, `hits`, `expires_at`)
             VALUES (:key, 1, :expires)
             ON DUPLICATE KEY UPDATE
                `hits`       = IF(`expires_at` <= NOW(), 1, `hits` + 1),
                `expires_at` = IF(`expires_at` <= NOW(), VALUES(`expires_at`), `expires_at`)',
            [
                'key'     => $bucket,
                'expires' => date('Y-m-d H:i:s', $now + $windowSeconds),
            ]
        );

        $row = Database::selectOne(
            'SELECT `hits`, UNIX_TIMESTAMP(`expires_at`) AS `expires_ts`
             FROM `rate_limits` WHERE `bucket_key` = :key',
            ['key' => $bucket]
        );

        $hits       = (int) ($row['hits'] ?? 1);
        $expiresTs  = (int) ($row['expires_ts'] ?? ($now + $windowSeconds));
        $retryAfter = max(1, $expiresTs - $now);

        return [
            'allowed'     => $hits <= $maxAttempts,
            'hits'        => $hits,
            'remaining'   => max(0, $maxAttempts - $hits),
            'retry_after' => $retryAfter,
        ];
    }

    /** Read the current count without incrementing it. */
    public static function attempts(string $key): int
    {
        $hits = Database::scalar(
            'SELECT `hits` FROM `rate_limits` WHERE `bucket_key` = :key AND `expires_at` > NOW()',
            ['key' => self::bucketKey($key)]
        );

        return (int) ($hits ?? 0);
    }

    public static function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return self::attempts($key) >= $maxAttempts;
    }

    /** Clear a bucket, e.g. after a successful login. */
    public static function clear(string $key): void
    {
        Database::execute('DELETE FROM `rate_limits` WHERE `bucket_key` = :key', ['key' => self::bucketKey($key)]);
    }

    /** Seconds until the window resets. */
    public static function availableIn(string $key): int
    {
        $expires = Database::scalar(
            'SELECT UNIX_TIMESTAMP(`expires_at`) FROM `rate_limits` WHERE `bucket_key` = :key AND `expires_at` > NOW()',
            ['key' => self::bucketKey($key)]
        );

        return $expires === null ? 0 : max(0, (int) $expires - time());
    }

    /** Remove expired buckets (called by the tokens:prune job). */
    public static function prune(): int
    {
        return Database::execute('DELETE FROM `rate_limits` WHERE `expires_at` <= NOW()');
    }

    /**
     * Hash the key so a long identifier (IP + path + user id) still fits the
     * VARCHAR(191) primary key, and so raw identifiers are not stored.
     */
    private static function bucketKey(string $key): string
    {
        return substr(hash('sha256', $key), 0, 64);
    }
}

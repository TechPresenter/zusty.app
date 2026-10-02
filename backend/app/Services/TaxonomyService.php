<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Cache;
use Zuste\Core\Config;
use Zuste\Core\Database;

/**
 * The option lists the apps render.
 *
 * Every dropdown, chip row and filter in the Flutter clients used to be a
 * `const` map in Dart, so changing a label meant a store release. Those lists
 * now live in `taxonomies` / `taxonomy_terms` and travel to the apps in one
 * bundled payload.
 *
 * The bundle is cached and stamped with a version derived from the newest
 * `updated_at` across both tables, which gives the API a cheap, correct ETag:
 * edit a term in the admin panel and the stamp changes, so every client
 * refetches on its next launch. Nothing else has to be invalidated by hand.
 */
final class TaxonomyService
{
    /**
     * The cache key carries the version stamp, so the cache invalidates
     * itself whenever a taxonomy or term changes.
     *
     * It used to be a bare constant flushed by flush() on admin writes, which
     * covered the admin panel and nothing else. A migration, a seed or a
     * direct SQL edit that added terms left every client holding the previous
     * bundle for up to an hour — the apps could not see a taxonomy that
     * demonstrably existed. Two taxonomies added by migration 029 surfaced
     * exactly that, and the integration suite caught it.
     *
     * version() is four indexed COUNT/MAX subqueries and the endpoint already
     * calls it to build the ETag, so this costs nothing the request was not
     * already paying.
     */
    private const CACHE_KEY = 'taxonomies.bundle';
    private const CACHE_TTL = 3600;

    /**
     * Every active taxonomy with its active terms, keyed by taxonomy key.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public static function all(): array
    {
        $key    = self::CACHE_KEY . ':' . self::version();
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $rows = Database::select(
            'SELECT t.`key` AS `taxonomy_key`,
                    tt.`value`, tt.`label`, tt.`description`, tt.`icon`,
                    tt.`color_hex`, tt.`image_url`, tt.`metadata`,
                    tt.`is_default`, tt.`sort_order`
               FROM `taxonomy_terms` tt
               INNER JOIN `taxonomies` t ON t.`id` = tt.`taxonomy_id`
              WHERE t.`is_active` = 1 AND tt.`is_active` = 1
              ORDER BY t.`sort_order`, tt.`sort_order`, tt.`id`'
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row['taxonomy_key']][] = [
                'value'       => $row['value'],
                'label'       => $row['label'],
                'description' => $row['description'],
                'icon'        => $row['icon'],
                'color'       => $row['color_hex'],
                'image_url'   => $row['image_url'],
                'metadata'    => self::decodeMetadata($row['metadata']),
                'is_default'  => (bool) $row['is_default'],
            ];
        }

        Cache::put($key, $grouped, self::CACHE_TTL);

        return $grouped;
    }

    /**
     * One taxonomy's terms.
     *
     * @return list<array<string,mixed>>
     */
    public static function terms(string $key): array
    {
        return self::all()[$key] ?? [];
    }

    /**
     * A `value => label` map, which is the shape most callers actually want.
     *
     * @return array<string,string>
     */
    public static function map(string $key): array
    {
        $map = [];

        foreach (self::terms($key) as $term) {
            $map[(string) $term['value']] = (string) $term['label'];
        }

        return $map;
    }

    /** The label for one value, falling back to the value itself. */
    public static function label(string $key, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::map($key)[$value] ?? ucwords(str_replace('_', ' ', $value));
    }

    /** The term flagged as default, if any. */
    public static function defaultValue(string $key): ?string
    {
        foreach (self::terms($key) as $term) {
            if ($term['is_default'] === true) {
                return (string) $term['value'];
            }
        }

        return null;
    }

    /** Whether a submitted value is a known, active term. */
    public static function isValid(string $key, string $value): bool
    {
        return array_key_exists($value, self::map($key));
    }

    /**
     * Ordering rank for a term, read from its `metadata.rank`.
     *
     * Used by comparisons that must stay correct when an admin reorders a
     * list — "responds within 6 hours" has to keep matching creators who
     * respond within 1 hour, whatever order the terms are displayed in.
     */
    public static function rank(string $key, ?string $value, int $fallback = 99): int
    {
        if ($value === null) {
            return $fallback;
        }

        foreach (self::terms($key) as $term) {
            if ((string) $term['value'] === $value) {
                $rank = $term['metadata']['rank'] ?? null;
                return is_numeric($rank) ? (int) $rank : $fallback;
            }
        }

        return $fallback;
    }

    // -----------------------------------------------------------------
    // Bundling
    // -----------------------------------------------------------------

    /**
     * The complete payload sent to the apps: every taxonomy plus the numeric
     * bounds and free-text lists that belong with them.
     *
     * @return array<string,mixed>
     */
    public static function bundle(): array
    {
        return [
            'version'    => self::version(),
            'taxonomies' => self::all(),
            'discovery'  => [
                'max_budget'        => Config::settingInt('discovery', 'max_budget_filter', 200000),
                'max_followers'     => Config::settingInt('discovery', 'max_follower_filter', 1000000),
                'default_page_size' => Config::settingInt('discovery', 'default_page_size', 20),
                'trending_searches' => Config::settingArray('discovery', 'trending_searches', []),
            ],
        ];
    }

    /**
     * A stamp that changes whenever any taxonomy or term is touched.
     *
     * Derived from the row counts and the newest `updated_at`, so it moves on
     * insert, update *and* delete — a plain MAX(updated_at) would miss a
     * deletion and leave clients holding a stale list.
     */
    public static function version(): string
    {
        $row = Database::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM `taxonomies`)                AS `tax_count`,
                (SELECT COUNT(*) FROM `taxonomy_terms`)            AS `term_count`,
                (SELECT COALESCE(MAX(`updated_at`), "0") FROM `taxonomies`)     AS `tax_touched`,
                (SELECT COALESCE(MAX(`updated_at`), "0") FROM `taxonomy_terms`) AS `term_touched`'
        ) ?? [];

        return substr(md5((string) json_encode($row)), 0, 16);
    }

    /**
     * Drop the cached bundle.
     *
     * Since the cache key now carries the version stamp, a write invalidates
     * itself and this is belt-and-braces — it only has to clear the entry for
     * the version that is current at the moment it is called. Kept because
     * callers rely on it and because an explicit flush is the right escape
     * hatch when something has gone wrong.
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY . ':' . self::version());
        Cache::forget(self::CACHE_KEY);   // the pre-versioned entry
    }

    /**
     * @param  mixed $raw
     * @return array<string,mixed>|null
     */
    private static function decodeMetadata(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}

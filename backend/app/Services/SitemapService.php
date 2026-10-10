<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Helpers\SiteUrl;

/**
 * XML sitemap and robots.txt generator.
 *
 * Collects every publicly visible URL from the CMS and the marketplace, writes
 * a sitemaps.org 0.9 document into the web root, and keeps a small state file
 * so the admin screen can say what changed since the previous run.
 *
 * Two rules shape the whole class:
 *
 *  1. The base URL comes from configuration only — see baseUrl().
 *  2. Files are written to a temporary name and then renamed over the target,
 *     so a crawler can never fetch a half-written sitemap.
 *
 * Every tunable lives in the `settings` table under the `seo` group, so an
 * operator can change priorities, change frequencies and the static path list
 * without a deploy. Missing rows fall back to the documented defaults below.
 */
final class SitemapService
{
    /** sitemaps.org caps one file at 50,000 URLs. Above that we write an index. */
    public const MAX_URLS_PER_FILE = 50000;

    /** Rows read per keyset page while collecting URLs. */
    private const BATCH = 2000;

    /** The only values <changefreq> may legally take. */
    public const CHANGE_FREQUENCIES = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    /** Settings group that holds every sitemap tunable. */
    private const GROUP = 'seo';

    /**
     * URL sources, in the order they are written.
     *
     * `table` and `pattern` are compile-time constants — no part of them ever
     * comes from user input, which is what keeps the SQL below safe.
     */
    private const SOURCES = [
        'static' => [
            // A shape, not a sample: the live list is read from settings and
            // rendered by sources(), so the screen never shows a stale example.
            'label'   => 'Static paths',
            'pattern' => '/{path}',
            'table'   => null,
        ],
        // Only the CMS pages the website publishes (PageController::SLUGS), at
        // their canonical /{slug}: /pages/{slug} answers with a redirect, and
        // the other CMS pages exist only inside the app.
        'pages' => [
            'label'   => 'CMS pages',
            'pattern' => '/{slug}',
            'table'   => 'pages',
        ],
        'blogs' => [
            'label'   => 'Blog posts',
            'pattern' => '/blog/{slug}',
            'table'   => 'blogs',
        ],
        'blog_categories' => [
            'label'   => 'Blog categories',
            'pattern' => '/blog/category/{slug}',
            'table'   => 'blog_categories',
        ],
        'categories' => [
            'label'   => 'Marketplace categories',
            'pattern' => '/categories/{slug}',
            'table'   => 'categories',
        ],
        'creators' => [
            'label'   => 'Creator profiles',
            'pattern' => '/creators/{handle}',
            'table'   => 'creators',
        ],
    ];

    /**
     * Documented defaults for <priority> and <changefreq>.
     *
     * Reasoning, so a later reader can argue with it rather than guess:
     *  · home            1.0 / daily    — the entry point, and the listings on it move every day.
     *  · creators        0.9 / daily    — the money pages; profiles, prices and availability change constantly.
     *  · static          0.8 / monthly  — hand-built landing pages, edited rarely but important.
     *  · blogs           0.7 / weekly   — new posts matter, published ones settle quickly.
     *  · categories      0.7 / weekly   — browse pages whose creator lists shift week to week.
     *  · pages           0.6 / monthly  — terms, privacy, refunds: needed in the index, seldom edited.
     *  · blog_categories 0.5 / weekly   — thin aggregation pages, lowest value of the set.
     *
     * Override any of them with the `seo` settings
     * `sitemap_priority_<key>` and `sitemap_changefreq_<key>`.
     */
    private const TUNABLES = [
        'home'            => ['priority' => '1.0', 'changefreq' => 'daily'],
        'static'          => ['priority' => '0.8', 'changefreq' => 'monthly'],
        'pages'           => ['priority' => '0.6', 'changefreq' => 'monthly'],
        'blogs'           => ['priority' => '0.7', 'changefreq' => 'weekly'],
        'blog_categories' => ['priority' => '0.5', 'changefreq' => 'weekly'],
        'categories'      => ['priority' => '0.7', 'changefreq' => 'weekly'],
        'creators'        => ['priority' => '0.9', 'changefreq' => 'daily'],
    ];

    private const DEFAULT_STATIC_PATHS = "/\n/about\n/contact\n/account-deletion";

    private const DEFAULT_ROBOTS_DISALLOW = "/admin\n/api";

    // =================================================================
    // Configuration
    // =================================================================

    /**
     * The absolute base URL every <loc> is built from.
     *
     * Deliberately NOT derived from the incoming request. A sitemap built from
     * $_SERVER['HTTP_HOST'] can be poisoned by one request carrying a forged
     * Host header: the attacker's domain would be baked into every <loc>, the
     * file written to disk, and then served to crawlers from our own domain
     * long after the request is gone. Configuration is the only trustworthy
     * source.
     *
     * The rule is SiteUrl::base(), the same one the landing and legal pages
     * use for their canonical links: APP_URL, refined by Settings → SEO →
     * canonical_base only when that is https on the same host, and the public
     * domain when APP_URL is a localhost address. One rule means the sitemap,
     * creator share links and QR codes can never name a different domain from
     * the pages' own canonicals (a stale canonical_base of https://zustee.com
     * used to leak into all three).
     */
    public static function baseUrl(): string
    {
        return SiteUrl::base();
    }

    /**
     * Why the next run will use the base URL it is about to use, or null when
     * there is nothing to say.
     *
     * Warns when Settings → SEO → canonical_base names something other than
     * the base actually used (another host, plain http, or malformed), so an
     * operator who set it is not left wondering why it had no effect.
     */
    public static function baseUrlWarning(): ?string
    {
        $configured = rtrim(trim(Config::settingString(self::GROUP, 'canonical_base', '')), '/');
        $used       = self::baseUrl();

        if ($configured === '' || $configured === $used) {
            return null;
        }

        return 'The canonical base URL under Settings → SEO ("' . $configured . '") is not used: it must be an '
             . 'https address on the same host as APP_URL. Every URL is built from ' . $used . ' instead.';
    }

    /**
     * The web root: the directory the server actually publishes.
     *
     * Verified rather than assumed — public/index.php is the API front
     * controller, so its presence proves this is the document root.
     */
    public static function publicPath(): string
    {
        $base      = rtrim((string) Config::get('app.base_path', ''), '/\\');
        $directory = $base . DIRECTORY_SEPARATOR . 'public';

        if ($base === '' || !is_dir($directory) || !is_file($directory . DIRECTORY_SEPARATOR . 'index.php')) {
            throw HttpException::server(
                'The web root could not be located. Expected the front controller at ' . $directory . '/index.php.'
            );
        }

        return $directory;
    }

    /** Is a source switched on? Absent setting means on. */
    public static function enabled(string $source): bool
    {
        if (!isset(self::SOURCES[$source])) {
            return false;
        }

        return Config::settingBool(self::GROUP, 'sitemap_include_' . $source, true);
    }

    /** Configured <priority> for a source (or 'home'), clamped to 0.0–1.0. */
    public static function priority(string $key): string
    {
        $default = self::TUNABLES[$key]['priority'] ?? '0.5';
        $value   = Config::settingString(self::GROUP, 'sitemap_priority_' . $key, $default);

        if (!is_numeric($value)) {
            return $default;
        }

        return number_format(max(0.0, min(1.0, (float) $value)), 1, '.', '');
    }

    /** Configured <changefreq> for a source (or 'home'), whitelisted. */
    public static function changefreq(string $key): string
    {
        $default = self::TUNABLES[$key]['changefreq'] ?? 'weekly';
        $value   = strtolower(trim(Config::settingString(self::GROUP, 'sitemap_changefreq_' . $key, $default)));

        return in_array($value, self::CHANGE_FREQUENCIES, true) ? $value : $default;
    }

    /**
     * Site-relative static paths, de-duplicated and normalised.
     *
     * @return list<string>
     */
    public static function staticPaths(): array
    {
        $raw   = Config::settingString(self::GROUP, 'sitemap_static_paths', self::DEFAULT_STATIC_PATHS);
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $paths = [];

        foreach ($parts as $part) {
            $path = trim((string) $part);

            if ($path === '') {
                continue;
            }

            // A fragment is meaningless to a crawler; drop it. explode() rather
            // than strtok(), which would turn a bare "#anchor" into "anchor"
            // and publish a path the operator never asked for.
            $path = trim(explode('#', $path, 2)[0]);

            // Anything absolute, protocol-relative or containing traversal would
            // put a foreign or bogus host into a published file. Refuse it.
            if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//') || str_contains($path, '..')) {
                continue;
            }

            if (!str_starts_with($path, '/')) {
                $path = '/' . $path;
            }

            $path = rtrim($path, '/');

            if ($path === '') {
                $path = '/';
            }

            $paths[$path] = true;
        }

        return array_keys($paths);
    }

    /**
     * The website's CMS page slugs as a quoted SQL list. PageController::SLUGS
     * is a compile-time constant of [a-z-] slugs, never user input; anything
     * else is dropped all the same.
     */
    private static function webPageSlugs(): string
    {
        $slugs = array_filter(
            \Zuste\Controllers\Web\PageController::SLUGS,
            static fn (string $slug): bool => preg_match('/^[a-z0-9-]+$/', $slug) === 1
        );

        return $slugs === [] ? "''" : "'" . implode("','", $slugs) . "'";
    }

    /**
     * Paths robots.txt tells crawlers to skip.
     *
     * @return list<string>
     */
    public static function robotsDisallow(): array
    {
        $raw   = Config::settingString(self::GROUP, 'sitemap_robots_disallow', self::DEFAULT_ROBOTS_DISALLOW);
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $paths = [];

        foreach ($parts as $part) {
            // "#" starts a comment in robots.txt, so anything after one would
            // be dropped by the crawler anyway — cut it here instead of
            // writing a directive that silently means something else.
            $path = trim(explode('#', trim((string) $part), 2)[0]);

            // Wildcards (*) and end-anchors ($) are legal in a Disallow and are
            // deliberately left alone; a foreign host or a traversal is not.
            if ($path === ''
                || str_contains($path, '://')
                || str_starts_with($path, '//')
                || str_contains($path, '..')) {
                continue;
            }

            if (!str_starts_with($path, '/')) {
                $path = '/' . $path;
            }

            $paths[$path] = true;
        }

        return array_keys($paths);
    }

    // =================================================================
    // Inventory
    // =================================================================

    /**
     * Every source with its live count and current tuning — what the admin
     * screen renders, and cheap enough to run on every page load.
     *
     * @return array<string,array{key:string,label:string,pattern:string,enabled:bool,count:int,priority:string,changefreq:string}>
     */
    public static function sources(): array
    {
        $sources = [];

        foreach (self::SOURCES as $key => $meta) {
            $sources[$key] = [
                'key'        => $key,
                'label'      => $meta['label'],
                // Static paths have no shape of their own — show the real list.
                'pattern'    => $key === 'static' ? self::staticPathSummary() : $meta['pattern'],
                'enabled'    => self::enabled($key),
                'count'      => self::countFor($key),
                'priority'   => self::priority($key),
                'changefreq' => self::changefreq($key),
            ];
        }

        return $sources;
    }

    /** The configured static paths, short enough for a table cell. */
    private static function staticPathSummary(): string
    {
        $paths = self::staticPaths();

        if ($paths === []) {
            return 'none configured';
        }

        $shown     = array_slice($paths, 0, 3);
        $remaining = count($paths) - count($shown);

        return implode(', ', $shown) . ($remaining > 0 ? ', +' . $remaining . ' more' : '');
    }

    /** How many URLs a source currently contributes, whether enabled or not. */
    public static function countFor(string $source): int
    {
        return match ($source) {
            'static'          => count(self::staticPaths()),
            'pages'           => (int) Database::scalar(
                "SELECT COUNT(*) FROM `pages` WHERE `is_active` = 1 AND `slug` IN (" . self::webPageSlugs() . ')'
            ),
            // The publish window matters: ContentController::blogs() hides a
            // post whose published_at is still in the future, so listing it
            // here would advertise a URL the public site does not serve yet.
            'blogs'           => (int) Database::scalar(
                "SELECT COUNT(*) FROM `blogs`
                  WHERE `status` = 'published' AND `deleted_at` IS NULL AND `slug` <> ''
                    AND (`published_at` IS NULL OR `published_at` <= NOW())"
            ),
            'blog_categories' => (int) Database::scalar(
                "SELECT COUNT(*) FROM `blog_categories` WHERE `is_active` = 1 AND `slug` <> ''"
            ),
            'categories'      => (int) Database::scalar(
                "SELECT COUNT(*) FROM `categories` WHERE `is_active` = 1 AND `deleted_at` IS NULL AND `slug` <> ''"
            ),
            // CreatorService::search() also requires the owning account to be
            // active, so a suspended or banned creator is not in the public
            // directory and must not be handed to a crawler either.
            'creators'        => (int) Database::scalar(
                "SELECT COUNT(*) FROM `creators` c
                   INNER JOIN `users` u ON u.`id` = c.`user_id`
                  WHERE c.`status` = 'approved' AND c.`is_public` = 1
                    AND c.`deleted_at` IS NULL AND c.`handle` <> ''
                    AND u.`status` = 'active'"
            ),
            default           => 0,
        };
    }

    /** Total URLs the next run would write. */
    public static function totalUrls(): int
    {
        $total = 0;

        foreach (array_keys(self::SOURCES) as $key) {
            if (self::enabled($key)) {
                $total += self::countFor($key);
            }
        }

        return $total;
    }

    // =================================================================
    // Building
    // =================================================================

    /**
     * Collect every publicly visible URL.
     *
     * @param  int|null $limit Stop after this many entries — used by the preview
     *                         so a large catalogue is never fully materialised.
     * @return list<array{loc:string,path:string,lastmod:?string,changefreq:string,priority:string,source:string}>
     */
    public static function build(?int $limit = null): array
    {
        $entries = [];
        $seen    = [];

        foreach (array_keys(self::SOURCES) as $source) {
            if (!self::enabled($source)) {
                continue;
            }

            if ($limit !== null && count($entries) >= $limit) {
                break;
            }

            self::collect($source, $entries, $seen, $limit);
        }

        return $entries;
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param array<string,true>        $seen
     */
    private static function collect(string $source, array &$entries, array &$seen, ?int $limit): void
    {
        if ($source === 'static') {
            foreach (self::staticPaths() as $path) {
                if ($limit !== null && count($entries) >= $limit) {
                    return;
                }

                self::push($entries, $seen, $path, null, 'static');
            }

            return;
        }

        // Keyset pagination on the primary key: constant cost per page, unlike
        // OFFSET, which degrades badly once a table has tens of thousands of rows.
        $sql = match ($source) {
            'pages' => "SELECT `id`, `slug`, `updated_at` AS `lastmod`
                          FROM `pages`
                         WHERE `is_active` = 1 AND `slug` IN (" . self::webPageSlugs() . ") AND `id` > :after
                         ORDER BY `id` LIMIT " . self::BATCH,

            'blogs' => "SELECT `id`, `slug`, `updated_at` AS `lastmod`
                          FROM `blogs`
                         WHERE `status` = 'published' AND `deleted_at` IS NULL AND `slug` <> ''
                           AND (`published_at` IS NULL OR `published_at` <= NOW())
                           AND `id` > :after
                         ORDER BY `id` LIMIT " . self::BATCH,

            // blog_categories carries no timestamp of its own, so the freshest
            // published post in the category is the honest lastmod.
            'blog_categories' => "SELECT c.`id`, c.`slug`,
                                         (SELECT MAX(b.`updated_at`)
                                            FROM `blogs` b
                                           WHERE b.`category_id` = c.`id`
                                             AND b.`status` = 'published'
                                             AND b.`deleted_at` IS NULL
                                             AND (b.`published_at` IS NULL OR b.`published_at` <= NOW())) AS `lastmod`
                                    FROM `blog_categories` c
                                   WHERE c.`is_active` = 1 AND c.`slug` <> '' AND c.`id` > :after
                                   ORDER BY c.`id` LIMIT " . self::BATCH,

            'categories' => "SELECT `id`, `slug`, `updated_at` AS `lastmod`
                               FROM `categories`
                              WHERE `is_active` = 1 AND `deleted_at` IS NULL AND `slug` <> '' AND `id` > :after
                              ORDER BY `id` LIMIT " . self::BATCH,

            // `is_public` matters more here than anywhere else: a creator who
            // switches their profile off and stays in the sitemap is actively
            // inviting search engines to a page that now 404s.
            'creators' => "SELECT c.`id`, c.`handle` AS `slug`, c.`updated_at` AS `lastmod`
                             FROM `creators` c
                             INNER JOIN `users` u ON u.`id` = c.`user_id`
                            WHERE c.`status` = 'approved' AND c.`is_public` = 1
                              AND c.`deleted_at` IS NULL AND c.`handle` <> ''
                              AND u.`status` = 'active'
                              AND c.`id` > :after
                            ORDER BY c.`id` LIMIT " . self::BATCH,

            default => '',
        };

        if ($sql === '') {
            return;
        }

        $prefix = match ($source) {
            'pages'           => '',
            'blogs'           => '/blog',
            'blog_categories' => '/blog/category',
            'categories'      => '/categories',
            'creators'        => '/creators',
            default           => '',
        };

        $after = 0;

        while (true) {
            $rows = Database::select($sql, ['after' => $after]);

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $slug  = trim((string) $row['slug']);

                if ($slug === '') {
                    continue;
                }

                self::push($entries, $seen, $prefix . '/' . $slug, $row['lastmod'] ?? null, $source);

                if ($limit !== null && count($entries) >= $limit) {
                    return;
                }
            }

            if (count($rows) < self::BATCH) {
                return;
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param array<string,true>        $seen
     */
    private static function push(array &$entries, array &$seen, string $path, mixed $lastmod, string $source): void
    {
        $loc = self::baseUrl() . self::encodePath($path);

        // A repeated <loc> is wasted crawl budget, so the first one wins.
        if (isset($seen[$loc])) {
            return;
        }

        $seen[$loc] = true;
        $isHome     = rtrim($path, '/') === '';

        $entries[] = [
            'loc'        => $loc,
            'path'       => $path,
            'lastmod'    => self::formatLastmod(is_scalar($lastmod) ? (string) $lastmod : null),
            'changefreq' => self::changefreq($isHome ? 'home' : $source),
            'priority'   => self::priority($isHome ? 'home' : $source),
            'source'     => $source,
        ];
    }

    /**
     * Percent-encode a site-relative path without touching its structure.
     *
     * Segments are encoded individually so "/" stays a separator, and a query
     * string is encoded key by key so "?", "&" and "=" keep their meaning —
     * encoding the whole string at once would turn /search?q=hello into
     * /search%3Fq%3Dhello, which is a different URL and a 404.
     */
    private static function encodePath(string $path): string
    {
        $query = '';
        $mark  = strpos($path, '?');

        if ($mark !== false) {
            $query = substr($path, $mark + 1);
            $path  = substr($path, 0, $mark);
        }

        $segments = explode('/', trim($path, '/'));
        $encoded  = '/' . implode('/', array_map(static fn (string $s): string => rawurlencode($s), $segments));

        if ($query === '') {
            return $encoded;
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);

            $pairs[] = $value === null
                ? rawurlencode($name)
                : rawurlencode($name) . '=' . rawurlencode($value);
        }

        return $pairs === [] ? $encoded : $encoded . '?' . implode('&', $pairs);
    }

    /** W3C datetime, which is what <lastmod> expects. */
    private static function formatLastmod(?string $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('c', $timestamp);
    }

    // =================================================================
    // Writing
    // =================================================================

    /**
     * Write the sitemap into the web root.
     *
     * Under 50,000 URLs that is a single /sitemap.xml. Above it, /sitemap.xml
     * becomes a sitemap index pointing at /sitemap-1.xml, /sitemap-2.xml …, so
     * the published entry point never changes and nothing has to be resubmitted
     * to a search engine when the catalogue crosses the threshold.
     *
     * The whole run holds an exclusive lock. Two runs at once — an operator
     * pressing Generate while the cron job is mid-flight, or a double click —
     * would otherwise interleave: the shorter one can delete the chunk files
     * the longer one has just written and referenced from the index.
     *
     * @return array{total:int,is_index:bool,files:list<array<string,mixed>>,counts:array<string,int>,removed:list<string>,generated_at:string,previous:array<string,mixed>|null,changes:array<string,int>}
     */
    public static function writeXml(): array
    {
        $lock = self::acquireLock();

        try {
            return self::writeXmlLocked();
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * @return array{total:int,is_index:bool,files:list<array<string,mixed>>,counts:array<string,int>,removed:list<string>,generated_at:string,previous:array<string,mixed>|null,changes:array<string,int>}
     */
    private static function writeXmlLocked(): array
    {
        $directory = self::publicPath();

        if (!is_writable($directory)) {
            throw HttpException::server('The web root is not writable: ' . $directory);
        }

        $entries = self::build();

        if ($entries === []) {
            throw HttpException::unprocessable(
                'There is nothing to publish. Every source is either switched off or empty, '
                . 'and a sitemap with no URLs is not a valid document.'
            );
        }

        $baseUrl  = self::baseUrl();
        $previous = self::state();
        $counts   = [];

        foreach ($entries as $entry) {
            $counts[$entry['source']] = ($counts[$entry['source']] ?? 0) + 1;
        }

        $total    = count($entries);
        $isIndex  = $total > self::MAX_URLS_PER_FILE;
        $files    = [];
        $keep     = ['sitemap.xml'];
        $stamp    = date('c');

        if ($isIndex) {
            $chunks = array_chunk($entries, self::MAX_URLS_PER_FILE);

            foreach ($chunks as $number => $chunk) {
                $name  = 'sitemap-' . ($number + 1) . '.xml';
                $keep[] = $name;

                $bytes = self::writeAtomic(
                    $directory . DIRECTORY_SEPARATOR . $name,
                    static function ($handle) use ($chunk): void {
                        self::streamUrlSet($handle, $chunk);
                    }
                );

                $files[] = [
                    'name'  => $name,
                    'path'  => $directory . DIRECTORY_SEPARATOR . $name,
                    'url'   => $baseUrl . '/' . $name,
                    'urls'  => count($chunk),
                    'bytes' => $bytes,
                ];
            }

            $indexBytes = self::writeAtomic(
                $directory . DIRECTORY_SEPARATOR . 'sitemap.xml',
                static function ($handle) use ($files, $stamp): void {
                    self::streamIndex($handle, $files, $stamp);
                }
            );

            array_unshift($files, [
                'name'  => 'sitemap.xml',
                'path'  => $directory . DIRECTORY_SEPARATOR . 'sitemap.xml',
                'url'   => $baseUrl . '/sitemap.xml',
                'urls'  => count($files),
                'bytes' => $indexBytes,
            ]);
        } else {
            $bytes = self::writeAtomic(
                $directory . DIRECTORY_SEPARATOR . 'sitemap.xml',
                static function ($handle) use ($entries): void {
                    self::streamUrlSet($handle, $entries);
                }
            );

            $files[] = [
                'name'  => 'sitemap.xml',
                'path'  => $directory . DIRECTORY_SEPARATOR . 'sitemap.xml',
                'url'   => $baseUrl . '/sitemap.xml',
                'urls'  => $total,
                'bytes' => $bytes,
            ];
        }

        $removed = self::removeStaleChunks($directory, $keep);

        $changes = [];

        foreach ($counts as $source => $count) {
            $before = (int) ($previous['counts'][$source] ?? 0);

            if ($before !== $count) {
                $changes[$source] = $count - $before;
            }
        }

        foreach ((array) ($previous['counts'] ?? []) as $source => $before) {
            if (!isset($counts[$source]) && (int) $before !== 0) {
                $changes[(string) $source] = -(int) $before;
            }
        }

        $result = [
            'total'        => $total,
            'is_index'     => $isIndex,
            'files'        => $files,
            'counts'       => $counts,
            'removed'      => $removed,
            'generated_at' => date('Y-m-d H:i:s'),
            'previous'     => $previous,
            'changes'      => $changes,
        ];

        self::saveState($result);

        Logger::info('Sitemap generated', [
            'total'    => $total,
            'files'    => count($files),
            'is_index' => $isIndex,
            'base_url' => $baseUrl,
        ]);

        return $result;
    }

    /**
     * Write robots.txt. Deliberately separate from writeXml() because it
     * overwrites a file an operator may have hand-edited.
     *
     * @return array{path:string,url:string,bytes:int}
     */
    public static function writeRobots(): array
    {
        $directory = self::publicPath();

        if (!is_writable($directory)) {
            throw HttpException::server('The web root is not writable: ' . $directory);
        }

        $content = self::robotsTxt();
        $path    = $directory . DIRECTORY_SEPARATOR . 'robots.txt';

        $bytes = self::writeAtomic($path, static function ($handle) use ($content): void {
            fwrite($handle, $content);
        });

        Logger::info('robots.txt written', ['bytes' => $bytes]);

        return ['path' => $path, 'url' => self::baseUrl() . '/robots.txt', 'bytes' => $bytes];
    }

    /** The robots.txt body, including the Sitemap: line. */
    public static function robotsTxt(): string
    {
        $baseUrl   = self::baseUrl();
        $disallow  = self::robotsDisallow();

        $lines = [
            '# robots.txt for ' . $baseUrl,
            '# Written by the Zusty admin panel on ' . date('c') . '.',
            '# Edits made here by hand are replaced the next time it is written.',
            '',
            'User-agent: *',
        ];

        if ($disallow === []) {
            // An empty Disallow is the canonical "everything is allowed".
            $lines[] = 'Disallow:';
        } else {
            foreach ($disallow as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
        }

        $lines[] = '';
        $lines[] = 'Sitemap: ' . $baseUrl . '/sitemap.xml';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param resource                                    $handle
     * @param list<array<string,mixed>>                   $entries
     */
    private static function streamUrlSet($handle, array $entries): void
    {
        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($handle, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");

        foreach ($entries as $entry) {
            // Element order is fixed by the 0.9 schema: loc, lastmod, changefreq, priority.
            $xml = '  <url>' . "\n"
                 . '    <loc>' . self::xml((string) $entry['loc']) . '</loc>' . "\n";

            if (!empty($entry['lastmod'])) {
                $xml .= '    <lastmod>' . self::xml((string) $entry['lastmod']) . '</lastmod>' . "\n";
            }

            $xml .= '    <changefreq>' . self::xml((string) $entry['changefreq']) . '</changefreq>' . "\n"
                  . '    <priority>' . self::xml((string) $entry['priority']) . '</priority>' . "\n"
                  . '  </url>' . "\n";

            fwrite($handle, $xml);
        }

        fwrite($handle, '</urlset>' . "\n");
    }

    /**
     * @param resource                  $handle
     * @param list<array<string,mixed>> $files
     */
    private static function streamIndex($handle, array $files, string $lastmod): void
    {
        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($handle, '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");

        foreach ($files as $file) {
            fwrite(
                $handle,
                '  <sitemap>' . "\n"
                . '    <loc>' . self::xml((string) $file['url']) . '</loc>' . "\n"
                . '    <lastmod>' . self::xml($lastmod) . '</lastmod>' . "\n"
                . '  </sitemap>' . "\n"
            );
        }

        fwrite($handle, '</sitemapindex>' . "\n");
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Take the exclusive run lock, or refuse.
     *
     * The lock is advisory and held by an open handle, so it disappears with
     * the process — a crashed run can never leave the feature wedged.
     *
     * @return resource
     */
    private static function acquireLock()
    {
        $directory = dirname(self::statePath());

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw HttpException::server('The cache directory could not be created: ' . $directory);
        }

        $handle = @fopen($directory . DIRECTORY_SEPARATOR . 'sitemap.lock', 'c');

        if ($handle === false) {
            throw HttpException::server('Could not open the sitemap lock file in ' . $directory . '.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw HttpException::conflict(
                'A sitemap run is already in progress. Wait for it to finish, then try again.'
            );
        }

        return $handle;
    }

    /** @param resource $handle */
    private static function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Write through a temporary file and rename it into place.
     *
     * The temporary name starts with a dot, which public/.htaccess refuses to
     * serve, and the rename is atomic on both POSIX and Windows — so a crawler
     * either gets the previous complete file or the new complete file, never a
     * truncated one.
     *
     * @param  callable(resource):void $writer
     * @return int Bytes written.
     */
    private static function writeAtomic(string $target, callable $writer): int
    {
        $directory = dirname($target);
        $temporary = $directory . DIRECTORY_SEPARATOR . '.sitemap-' . bin2hex(random_bytes(8)) . '.tmp';

        $handle = @fopen($temporary, 'wb');

        if ($handle === false) {
            throw HttpException::server('Could not create a temporary file in ' . $directory . '.');
        }

        try {
            $writer($handle);
            fflush($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($temporary);

            throw $e;
        }

        fclose($handle);
        @chmod($temporary, 0644);

        if (!@rename($temporary, $target)) {
            // A few filesystems refuse to replace an existing file in one step.
            @unlink($target);

            if (!@rename($temporary, $target)) {
                @unlink($temporary);

                throw HttpException::server('Could not move the generated file into place: ' . $target);
            }
        }

        clearstatcache(true, $target);

        return (int) (@filesize($target) ?: 0);
    }

    /**
     * Delete chunk files left behind by a larger previous run.
     *
     * @param  list<string> $keep
     * @return list<string>
     */
    private static function removeStaleChunks(string $directory, array $keep): array
    {
        $removed = [];

        foreach (glob($directory . DIRECTORY_SEPARATOR . 'sitemap-*.xml') ?: [] as $path) {
            $name = basename($path);

            // Only ever touch files this generator owns.
            if (preg_match('/^sitemap-\d+\.xml$/', $name) !== 1 || in_array($name, $keep, true)) {
                continue;
            }

            if (@unlink($path)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    // =================================================================
    // On-disk status
    // =================================================================

    /**
     * What is actually on disk right now — sizes and timestamps are read from
     * the filesystem, never inferred from the last run.
     *
     * @return array{directory:string,writable:bool,base_url:string,sitemap_url:string,robots_url:string,files:list<array<string,mixed>>,total_bytes:int,url_count:int,is_index:bool,exists:bool,modified_at:?string,robots:array<string,mixed>}
     */
    public static function status(): array
    {
        $directory = self::publicPath();
        $baseUrl   = self::baseUrl();

        $names = ['sitemap.xml'];

        foreach (glob($directory . DIRECTORY_SEPARATOR . 'sitemap-*.xml') ?: [] as $path) {
            $name = basename($path);

            if (preg_match('/^sitemap-(\d+)\.xml$/', $name) === 1) {
                $names[] = $name;
            }
        }

        // sitemap-2 must sort after sitemap-10's neighbours numerically, not lexically.
        usort($names, static function (string $a, string $b): int {
            $order = static fn (string $n): int => $n === 'sitemap.xml'
                ? -1
                : (int) (preg_match('/(\d+)/', $n, $m) === 1 ? $m[1] : 0);

            return $order($a) <=> $order($b);
        });

        $files      = [];
        $totalBytes = 0;

        foreach ($names as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            clearstatcache(true, $path);

            if (!is_file($path)) {
                continue;
            }

            $bytes       = (int) (@filesize($path) ?: 0);
            $totalBytes += $bytes;

            $files[] = [
                'name'        => $name,
                'path'        => $path,
                'url'         => $baseUrl . '/' . $name,
                'bytes'       => $bytes,
                'modified_at' => date('Y-m-d H:i:s', (int) (@filemtime($path) ?: 0)),
                'urls'        => self::countLocs($path),
            ];
        }

        $main = $directory . DIRECTORY_SEPARATOR . 'sitemap.xml';
        clearstatcache(true, $main);

        $robotsPath = $directory . DIRECTORY_SEPARATOR . 'robots.txt';
        clearstatcache(true, $robotsPath);

        $robotsBody = is_file($robotsPath) ? (string) @file_get_contents($robotsPath) : '';

        // Published URL count, counted in the files themselves. When sitemap.xml
        // is an index it holds one <loc> per chunk, not per page, so the chunks
        // are the ones to add up.
        $chunks = array_filter($files, static fn (array $file): bool => $file['name'] !== 'sitemap.xml');

        $urlCount = $chunks === []
            ? (int) ($files[0]['urls'] ?? 0)
            : array_sum(array_column($chunks, 'urls'));

        return [
            'directory'   => $directory,
            'writable'    => is_writable($directory),
            'base_url'    => $baseUrl,
            'sitemap_url' => $baseUrl . '/sitemap.xml',
            'robots_url'  => $baseUrl . '/robots.txt',
            'files'       => $files,
            'total_bytes' => $totalBytes,
            'url_count'   => (int) $urlCount,
            'is_index'    => $chunks !== [],
            'exists'      => is_file($main),
            'modified_at' => is_file($main) ? date('Y-m-d H:i:s', (int) (@filemtime($main) ?: 0)) : null,
            'robots'      => [
                'path'             => $robotsPath,
                'exists'           => $robotsBody !== '',
                'bytes'            => (int) (@filesize($robotsPath) ?: 0),
                'modified_at'      => is_file($robotsPath) ? date('Y-m-d H:i:s', (int) (@filemtime($robotsPath) ?: 0)) : null,
                'has_sitemap_line' => stripos($robotsBody, 'Sitemap:') !== false,
                // Compared on directives only: robotsTxt() stamps the current
                // time into a comment, so a byte comparison would always differ.
                'in_sync'          => $robotsBody !== '' && self::directives($robotsBody) === self::directives(self::robotsTxt()),
                'body'             => $robotsBody,
            ],
        ];
    }

    /** The meaningful lines of a robots.txt — comments and blanks removed. */
    private static function directives(string $body): string
    {
        $lines = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim((string) $line);

            if ($line !== '' && !str_starts_with($line, '#')) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /** Count <loc> elements in a written file, so the screen reports the file, not a guess. */
    private static function countLocs(string $path): int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $count = 0;

        while (($line = fgets($handle)) !== false) {
            $count += substr_count($line, '<loc>');
        }

        fclose($handle);

        return $count;
    }

    // =================================================================
    // Run state (so the screen can say what changed)
    // =================================================================

    private static function statePath(): string
    {
        $storage = rtrim((string) Config::get('storage.base_path', ''), '/\\');

        return $storage . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'sitemap-state.json';
    }

    /**
     * The previous run, or null when there has never been one.
     *
     * @return array<string,mixed>|null
     */
    public static function state(): ?array
    {
        $path = self::statePath();

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $result */
    private static function saveState(array $result): void
    {
        $path      = self::statePath();
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return;
        }

        $payload = [
            'generated_at' => $result['generated_at'],
            'total'        => $result['total'],
            'is_index'     => $result['is_index'],
            'counts'       => $result['counts'],
            'files'        => array_map(
                static fn (array $file): array => [
                    'name'  => $file['name'],
                    'urls'  => $file['urls'],
                    'bytes' => $file['bytes'],
                ],
                $result['files']
            ),
        ];

        @file_put_contents($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * A one-line summary of what a run changed, for the toast and the log.
     *
     * @param array{total:int,files:list<array<string,mixed>>,changes:array<string,int>,previous:array<string,mixed>|null} $result
     */
    public static function changeSummary(array $result): string
    {
        $fileCount = count($result['files']);
        $headline  = 'Sitemap rebuilt with ' . number_format($result['total']) . ' URL'
                   . ($result['total'] === 1 ? '' : 's')
                   . ' across ' . $fileCount . ' file' . ($fileCount === 1 ? '' : 's') . '.';

        if ($result['previous'] === null) {
            return $headline . ' This is the first run.';
        }

        if ($result['changes'] === []) {
            return $headline . ' Nothing changed since the previous run.';
        }

        $parts = [];

        foreach ($result['changes'] as $source => $delta) {
            $label   = self::SOURCES[$source]['label'] ?? $source;
            $parts[] = $label . ' ' . ($delta > 0 ? '+' : '−') . abs($delta);
        }

        return $headline . ' Changed: ' . implode(', ', $parts) . '.';
    }

    // =================================================================
    // Persisting the tunables
    // =================================================================

    /**
     * Save the source toggles and tuning into the `seo` settings group.
     *
     * Rows are created on first save with their label and type, so they also
     * appear correctly on Settings → SEO without needing a migration.
     *
     * The whole save is one transaction: a form carries a dozen rows and half
     * a configuration is worse than none — a run using six new toggles with
     * the old priorities would publish something nobody asked for.
     *
     * Every value read out of the three arrays goes through scalarValue(),
     * because the arrays come straight off the request. A crafted post such as
     * `include[static][]=1` puts an array where a string is expected, and this
     * codebase promotes the resulting warning to an exception.
     *
     * @param  array<string,mixed> $include    Source key => any truthy value for "on"
     * @param  array<string,mixed> $changefreq Source key (or 'home') => frequency
     * @param  array<string,mixed> $priority   Source key (or 'home') => 0.0–1.0
     * @return list<string> The setting keys that actually changed.
     */
    public static function saveConfiguration(
        array $include,
        array $changefreq,
        array $priority,
        string $staticPaths,
        string $robotsDisallow
    ): array {
        /** @var list<string> $changed */
        $changed = Database::transaction(
            static function () use ($include, $changefreq, $priority, $staticPaths, $robotsDisallow): array {
                $changed = [];

                foreach (self::SOURCES as $key => $meta) {
                    $on = in_array(strtolower(self::scalarValue($include, $key)), ['1', 'true', 'on', 'yes'], true);

                    $changed = array_merge($changed, self::putSetting(
                        'sitemap_include_' . $key,
                        $on ? '1' : '0',
                        'boolean',
                        'Sitemap: include ' . strtolower($meta['label']),
                        'Publish ' . $meta['pattern'] . ' URLs in sitemap.xml.'
                    ));
                }

                foreach (array_keys(self::TUNABLES) as $key) {
                    $label = $key === 'home' ? 'home page' : strtolower(self::SOURCES[$key]['label'] ?? $key);

                    $frequency = strtolower(self::scalarValue($changefreq, $key));

                    if (in_array($frequency, self::CHANGE_FREQUENCIES, true)) {
                        $changed = array_merge($changed, self::putSetting(
                            'sitemap_changefreq_' . $key,
                            $frequency,
                            'string',
                            'Sitemap: change frequency for ' . $label,
                            'One of: ' . implode(', ', self::CHANGE_FREQUENCIES) . '.'
                        ));
                    }

                    $weight = self::scalarValue($priority, $key);

                    if (is_numeric($weight)) {
                        $changed = array_merge($changed, self::putSetting(
                            'sitemap_priority_' . $key,
                            number_format(max(0.0, min(1.0, (float) $weight)), 1, '.', ''),
                            'decimal',
                            'Sitemap: priority for ' . $label,
                            'Relative importance from 0.0 to 1.0.'
                        ));
                    }
                }

                $changed = array_merge($changed, self::putSetting(
                    'sitemap_static_paths',
                    self::normaliseList($staticPaths),
                    'text',
                    'Sitemap: static paths',
                    'Site-relative paths with no database row of their own, one per line.'
                ));

                return array_merge($changed, self::putSetting(
                    'sitemap_robots_disallow',
                    self::normaliseList($robotsDisallow),
                    'text',
                    'robots.txt: disallowed paths',
                    'Paths crawlers are asked to skip, one per line.'
                ));
            }
        );

        Config::flushSettings();

        return $changed;
    }

    /**
     * One trimmed scalar out of a submitted array; anything else reads as ''.
     *
     * @param array<string,mixed> $values
     */
    private static function scalarValue(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** Collapse a textarea into one trimmed value per line. */
    private static function normaliseList(string $raw): string
    {
        $lines = [];

        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $line = trim((string) $line);

            if ($line !== '') {
                $lines[$line] = true;
            }
        }

        return implode("\n", array_keys($lines));
    }

    /**
     * Insert or update one setting row.
     *
     * @return list<string> The key, when the stored value actually changed.
     */
    private static function putSetting(string $key, string $value, string $type, string $label, string $description): array
    {
        $current = Database::scalar(
            'SELECT `value` FROM `settings` WHERE `group_key` = :group AND `setting_key` = :key',
            ['group' => self::GROUP, 'key' => $key]
        );

        if ($current !== null && (string) $current === $value) {
            return [];
        }

        Database::execute(
            'INSERT INTO `settings`
                (`group_key`, `setting_key`, `value`, `type`, `label`, `description`,
                 `is_public`, `is_encrypted`, `sort_order`, `updated_at`)
             VALUES (:group, :key, :value, :type, :label, :description, 0, 0, 100, NOW())
             ON DUPLICATE KEY UPDATE
                `value` = VALUES(`value`),
                `type` = VALUES(`type`),
                `label` = VALUES(`label`),
                `description` = VALUES(`description`),
                `updated_at` = NOW()',
            [
                'group'       => self::GROUP,
                'key'         => $key,
                'value'       => $value,
                'type'        => $type,
                'label'       => $label,
                'description' => $description,
            ]
        );

        return [$key];
    }

    // =================================================================
    // Scheduled entry point
    // =================================================================

    /**
     * Cron entry point.
     *
     * Register it in CronRunner as the job `sitemap:generate`; the match arm is
     *
     *     'sitemap:generate' => SitemapService::cron(),
     *
     * The return value is deliberately small — `scheduled_jobs.last_output` is
     * a varchar(2000) and stores this JSON-encoded.
     *
     * robots.txt is not touched here: writing it is an explicit operator action.
     *
     * @return array<string,mixed>
     */
    public static function cron(): array
    {
        $result = self::writeXml();

        return [
            'total'    => $result['total'],
            'files'    => count($result['files']),
            'is_index' => $result['is_index'],
            'counts'   => $result['counts'],
            'changes'  => $result['changes'],
        ];
    }
}

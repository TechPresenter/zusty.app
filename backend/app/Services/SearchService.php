<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * Cross-module search for the admin panel.
 *
 * One term, several tables, grouped results. Two rules govern the whole thing:
 *
 *  1. A source is only queried when the caller holds its permission. The check
 *     happens before the SQL runs, so a support agent's search never touches
 *     the payments or admins tables at all — there is no row to leak and no
 *     timing signal to read.
 *  2. The term is escaped for LIKE before binding. A `%` typed by an operator
 *     is a literal percent sign, not a wildcard that would drag the whole
 *     table back.
 */
final class SearchService
{
    /** Shorter than this and almost every row matches, so we do not bother. */
    public const MIN_TERM_LENGTH = 2;

    /**
     * Rows returned per source unless the caller asks for fewer.
     *
     * A group therefore reports how many rows it is *showing*, not how many
     * rows matched. Callers that print a headline must say so — the "open in
     * <module>" link is what gives the operator the complete set.
     */
    public const DEFAULT_LIMIT = 8;

    /** Hard ceiling on rows per source, whatever the caller asks for. */
    private const MAX_LIMIT = 25;

    /**
     * MySQL's default LIKE escape is the backslash; stating it keeps the
     * behaviour explicit and matches the rest of the codebase.
     */
    private const LIKE_ESCAPE = " ESCAPE '\\\\'";

    /**
     * Every searchable source, in the order results are presented.
     *
     * `permission` gates the source outright. `index` is the module's own list
     * screen, so "see all" hands the same term to the real filter.
     *
     * @var array<string,array{label:string,icon:string,permission:string,index:string,fields:string}>
     */
    private const SOURCES = [
        'users' => [
            'label' => 'Users', 'icon' => 'users', 'permission' => 'users.view',
            'index' => '/admin/users', 'fields' => 'name, email or phone',
        ],
        'creators' => [
            'label' => 'Creators', 'icon' => 'star', 'permission' => 'creators.view',
            'index' => '/admin/creators', 'fields' => 'display name or handle',
        ],
        'bookings' => [
            'label' => 'Bookings', 'icon' => 'calendar', 'permission' => 'bookings.view',
            'index' => '/admin/bookings', 'fields' => 'booking code',
        ],
        'support_tickets' => [
            'label' => 'Support tickets', 'icon' => 'headset', 'permission' => 'support.view',
            'index' => '/admin/tickets', 'fields' => 'ticket code or subject',
        ],
        'categories' => [
            'label' => 'Categories', 'icon' => 'grid', 'permission' => 'categories.view',
            'index' => '/admin/categories', 'fields' => 'name or slug',
        ],
        'pages' => [
            'label' => 'Pages', 'icon' => 'file', 'permission' => 'cms.view',
            'index' => '/admin/pages', 'fields' => 'title or slug',
        ],
        'blogs' => [
            'label' => 'Blog articles', 'icon' => 'file', 'permission' => 'cms.view',
            'index' => '/admin/blogs', 'fields' => 'title or slug',
        ],
        'coupons' => [
            'label' => 'Coupons', 'icon' => 'card', 'permission' => 'marketing.view',
            'index' => '/admin/coupons', 'fields' => 'code or name',
        ],
        'admins' => [
            'label' => 'Admin users', 'icon' => 'user-check', 'permission' => 'admins.view',
            'index' => '/admin/admins', 'fields' => 'name or email',
        ],
    ];

    /**
     * Search every source the caller is allowed to see.
     *
     * @param  list<string> $permissions effective permission slugs
     * @return list<array{key:string,label:string,icon:string,index:string,count:int,capped:bool,results:list<array<string,string>>}>
     */
    public static function search(string $term, array $permissions, int $limit = self::DEFAULT_LIMIT): array
    {
        $term  = trim($term);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return [];
        }

        $like   = Str::likeTerm($term);
        $groups = [];

        foreach (self::SOURCES as $key => $source) {
            if (!in_array($source['permission'], $permissions, true)) {
                continue;
            }

            $results = self::query($key, $like, $limit);

            if ($results === []) {
                continue;
            }

            $groups[] = [
                'key'     => $key,
                'label'   => $source['label'],
                'icon'    => $source['icon'],
                'index'   => $source['index'] . '?q=' . rawurlencode($term),
                'count'   => count($results),
                // The source filled its allowance, so there are probably more
                // rows behind the "open in <module>" link.
                'capped'  => count($results) >= $limit,
                'results' => $results,
            ];
        }

        return $groups;
    }

    /**
     * Total hits across every group — used for the "N results" headline.
     *
     * @param list<array{count:int}> $groups
     */
    public static function total(array $groups): int
    {
        $total = 0;

        foreach ($groups as $group) {
            $total += (int) $group['count'];
        }

        return $total;
    }

    /**
     * The sources this admin can actually search, for the "we looked in…" note.
     *
     * @param  list<string> $permissions
     * @return list<array{key:string,label:string,icon:string,fields:string}>
     */
    public static function availableSources(array $permissions): array
    {
        $available = [];

        foreach (self::SOURCES as $key => $source) {
            if (!in_array($source['permission'], $permissions, true)) {
                continue;
            }

            $available[] = [
                'key'    => $key,
                'label'  => $source['label'],
                'icon'   => $source['icon'],
                'fields' => $source['fields'],
            ];
        }

        return $available;
    }

    // -----------------------------------------------------------------
    // Per-source queries
    // -----------------------------------------------------------------

    /**
     * @return list<array<string,string>>
     */
    private static function query(string $key, string $like, int $limit): array
    {
        $esc = self::LIKE_ESCAPE;

        return match ($key) {
            'users'           => self::users($like, $limit, $esc),
            'creators'        => self::creators($like, $limit, $esc),
            'bookings'        => self::bookings($like, $limit, $esc),
            'support_tickets' => self::tickets($like, $limit, $esc),
            'categories'      => self::categories($like, $limit, $esc),
            'pages'           => self::pages($like, $limit, $esc),
            'blogs'           => self::blogs($like, $limit, $esc),
            'coupons'         => self::coupons($like, $limit, $esc),
            'admins'          => self::admins($like, $limit, $esc),
            default           => [],
        };
    }

    /** @return list<array<string,string>> */
    private static function users(string $like, int $limit, string $esc): array
    {
        $rows = Database::select(
            'SELECT `id`, `name`, `email`, `phone`, `phone_country_code`, `account_type`, `status`
               FROM `users`
              WHERE `deleted_at` IS NULL
                AND (`name` LIKE :term' . $esc . '
                  OR `email` LIKE :term' . $esc . '
                  OR `phone` LIKE :term' . $esc . ')
              ORDER BY `name` ASC
              LIMIT ' . $limit,
            ['term' => $like]
        );

        $results = [];

        foreach ($rows as $row) {
            // A country code on its own tells the operator nothing.
            $number = trim((string) ($row['phone'] ?? ''));
            $phone  = $number === '' ? '' : trim((string) $row['phone_country_code'] . ' ' . $number);

            $results[] = [
                'label'    => (string) $row['name'],
                'sublabel' => trim(implode(' · ', array_filter([
                    (string) ($row['email'] ?? ''),
                    $phone,
                    ucfirst((string) $row['account_type']),
                ]))),
                'icon'  => 'users',
                'url'   => '/admin/users/' . (int) $row['id'],
                'chip'  => self::humanise((string) $row['status']),
                'tone'  => self::tone((string) $row['status']),
            ];
        }

        return $results;
    }

    /** @return list<array<string,string>> */
    private static function creators(string $like, int $limit, string $esc): array
    {
        $rows = Database::select(
            'SELECT `id`, `display_name`, `handle`, `city`, `status`, `is_verified`
               FROM `creators`
              WHERE `deleted_at` IS NULL
                AND (`display_name` LIKE :term' . $esc . ' OR `handle` LIKE :term' . $esc . ')
              ORDER BY `display_name` ASC
              LIMIT ' . $limit,
            ['term' => $like]
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'label'    => (string) $row['display_name'],
                'sublabel' => trim(implode(' · ', array_filter([
                    '@' . (string) $row['handle'],
                    (string) ($row['city'] ?? ''),
                    (int) $row['is_verified'] === 1 ? 'Verified' : '',
                ]))),
                'icon' => 'star',
                'url'  => '/admin/creators/' . (int) $row['id'],
                'chip' => self::humanise((string) $row['status']),
                'tone' => self::tone((string) $row['status']),
            ];
        }

        return $results;
    }

    /** @return list<array<string,string>> */
    private static function bookings(string $like, int $limit, string $esc): array
    {
        $rows = Database::select(
            'SELECT `id`, `code`, `title`, `status`, `total_amount`, `currency`
               FROM `bookings`
              WHERE `deleted_at` IS NULL
                AND `code` LIKE :term' . $esc . '
              ORDER BY `id` DESC
              LIMIT ' . $limit,
            ['term' => $like]
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'label'    => (string) $row['code'],
                'sublabel' => Str::limit((string) $row['title'], 70)
                    . ' · ' . Money::format(
                        (float) $row['total_amount'],
                        $row['currency'] === null ? null : (string) $row['currency']
                    ),
                'icon' => 'calendar',
                'url'  => '/admin/bookings/' . (int) $row['id'],
                'chip' => self::humanise((string) $row['status']),
                'tone' => self::tone((string) $row['status']),
            ];
        }

        return $results;
    }

    /** @return list<array<string,string>> */
    private static function tickets(string $like, int $limit, string $esc): array
    {
        $rows = Database::select(
            'SELECT `id`, `code`, `subject`, `status`, `priority`
               FROM `support_tickets`
              WHERE `code` LIKE :term' . $esc . ' OR `subject` LIKE :term' . $esc . '
              ORDER BY `id` DESC
              LIMIT ' . $limit,
            ['term' => $like]
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'label'    => (string) $row['code'],
                'sublabel' => Str::limit((string) $row['subject'], 80)
                    . ' · ' . self::humanise((string) $row['priority']) . ' priority',
                'icon' => 'headset',
                'url'  => '/admin/tickets/' . (int) $row['id'],
                'chip' => self::humanise((string) $row['status']),
                'tone' => self::tone((string) $row['status']),
            ];
        }

        return $results;
    }

    /** @return list<array<string,string>> */
    private static function categories(string $like, int $limit, string $esc): array
    {
        $rows = Database::select(
            'SELECT `id`, `name`, `slug`, `creator_count`, `is_active`
               FROM `categories`
              WHERE `deleted_at` IS NULL
                AND (`name` LIKE :term' . $esc . ' OR `slug` LIKE :term' . $esc . ')
              ORDER BY `sort_order` ASC, `name` ASC
              LIMIT ' . $limit,
            ['term' => $like]
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'label'    => (string) $row['name'],
                'sublabel' => '/' . (string) $row['slug'] . ' · ' . (int) $row['creator_count'] . ' creator(s)',
                'icon'     => 'grid',
                'url'      => '/admin/categories/' . (int) $row['id'] . '/edit',
                'chip'     => (int) $row['is_active'] === 1 ? 'Active' : 'Inactive',
                'tone'     => (int) $row['is_active'] === 1 ? 'success' : 'neutral',
            ];
        }

        return $results;
    }

    /** @return list<array<string,string>> */
    private static function pages(string $like, int $limit, string $esc): array
    {
        $rows = Database::select(
            'SELECT `id`, `title`, `slug`, `template`, `is_active`
               FROM `pages`
              WHERE `title` LIKE :term' . $esc . ' OR `slug` LIKE :term' . $esc . '
              ORDER BY `sort_order` ASC, `title` ASC
              LIMIT ' . $limit,
            ['term' => $like]
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'label'    => (string) $row['title'],
                'sublabel' => '/pages/' . (string) $row['slug'] . ' · ' . self::humanise((string) $row['template']),
                'icon'     => 'file',
                'url'      => '/admin/pages/' . (int) $row['id'] . '/edit',
                'chip'     => (int) $row['is_active'] === 1 ? 'Published' : 'Draft',
                'tone'     => (int) $row['is_active'] === 1 ? 'success' : 'neutral',
            ];
        }

        return $results;
    }

    /** @return list<array<string,string>> */
    private static function blogs(string $like, int $limit, string $esc): array
    {
        $rows = Database::select(
            'SELECT `id`, `title`, `slug`, `status`, `author_name`
               FROM `blogs`
              WHERE `deleted_at` IS NULL
                AND (`title` LIKE :term' . $esc . ' OR `slug` LIKE :term' . $esc . ')
              ORDER BY `id` DESC
              LIMIT ' . $limit,
            ['term' => $like]
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'label'    => (string) $row['title'],
                'sublabel' => trim('/' . (string) $row['slug'] . ' · ' . (string) ($row['author_name'] ?? ''), ' ·'),
                'icon'     => 'file',
                'url'      => '/admin/blogs/' . (int) $row['id'] . '/edit',
                'chip'     => self::humanise((string) $row['status']),
                'tone'     => self::tone((string) $row['status']),
            ];
        }

        return $results;
    }

    /** @return list<array<string,string>> */
    private static function coupons(string $like, int $limit, string $esc): array
    {
        $rows = Database::select(
            'SELECT `id`, `code`, `name`, `discount_type`, `discount_value`, `used_count`, `is_active`
               FROM `coupons`
              WHERE `deleted_at` IS NULL
                AND (`code` LIKE :term' . $esc . ' OR `name` LIKE :term' . $esc . ')
              ORDER BY `id` DESC
              LIMIT ' . $limit,
            ['term' => $like]
        );

        $results = [];

        foreach ($rows as $row) {
            $discount = (string) $row['discount_type'] === 'percentage'
                ? rtrim(rtrim(number_format((float) $row['discount_value'], 2), '0'), '.') . '% off'
                : 'Flat ' . Money::format((float) $row['discount_value']) . ' off';

            $results[] = [
                'label'    => (string) $row['code'],
                'sublabel' => (string) $row['name'] . ' · ' . $discount . ' · used ' . (int) $row['used_count'] . '×',
                'icon'     => 'card',
                'url'      => '/admin/coupons/' . (int) $row['id'] . '/edit',
                'chip'     => (int) $row['is_active'] === 1 ? 'Active' : 'Inactive',
                'tone'     => (int) $row['is_active'] === 1 ? 'success' : 'neutral',
            ];
        }

        return $results;
    }

    /** @return list<array<string,string>> */
    private static function admins(string $like, int $limit, string $esc): array
    {
        $rows = Database::select(
            'SELECT a.`id`, a.`name`, a.`email`, a.`status`, r.`name` AS `role_name`
               FROM `admins` a
               LEFT JOIN `roles` r ON r.`id` = a.`role_id`
              WHERE a.`deleted_at` IS NULL
                AND (a.`name` LIKE :term' . $esc . ' OR a.`email` LIKE :term' . $esc . ')
              ORDER BY a.`name` ASC
              LIMIT ' . $limit,
            ['term' => $like]
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'label'    => (string) $row['name'],
                'sublabel' => (string) $row['email'] . ' · ' . (string) ($row['role_name'] ?? 'No role'),
                'icon'     => 'user-check',
                // There is no per-admin page; the list screen filters by term.
                'url'      => '/admin/admins?q=' . rawurlencode((string) $row['email']),
                'chip'     => self::humanise((string) $row['status']),
                'tone'     => self::tone((string) $row['status']),
            ];
        }

        return $results;
    }

    // -----------------------------------------------------------------
    // Presentation helpers
    // -----------------------------------------------------------------

    private static function humanise(string $value): string
    {
        return $value === '' ? '' : ucfirst(str_replace('_', ' ', $value));
    }

    /** Map a status vocabulary shared across modules onto a chip tone. */
    private static function tone(string $status): string
    {
        return match ($status) {
            'active', 'approved', 'completed', 'published', 'resolved', 'verified' => 'success',
            'pending', 'pending_review', 'pending_agent', 'pending_user',
            'scheduled', 'in_progress', 'processing', 'open', 'high'               => 'warning',
            'suspended', 'banned', 'deleted', 'rejected', 'cancelled',
            'refunded', 'disputed', 'failed', 'urgent'                             => 'error',
            default                                                                 => 'neutral',
        };
    }
}

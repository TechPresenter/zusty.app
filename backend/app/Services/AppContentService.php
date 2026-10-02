<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;

/**
 * Admin-managed app content: feature flags, the home screen layout, and popups.
 *
 * These three tables already had full admin CRUD but were never read by the
 * API, so editing them changed nothing in the apps. This service is the bridge:
 * it evaluates each row against the caller — their account type, platform, app
 * version — and returns only what that specific client should see.
 *
 * The home layout in particular moves from a hard-coded section order to
 * whatever `homepage_sections` says, so an admin can reorder, retitle, or
 * remove a rail without a release.
 */
final class AppContentService
{
    // -----------------------------------------------------------------
    // Feature flags
    // -----------------------------------------------------------------

    /**
     * Flags that evaluate true for this caller.
     *
     * A flag has to clear four gates: enabled, audience, platform, and minimum
     * app version. Percentage rollout is resolved deterministically from the
     * user id, so a given user stays on the same side of the split between
     * launches instead of flickering.
     *
     * @return array<string,bool>
     */
    public static function featureFlags(
        ?int $userId,
        string $accountType = 'brand',
        string $platform = 'android',
        string $appVersion = '1.0.0'
    ): array {
        $rows = Database::select('SELECT * FROM `feature_flags`');
        $flags = [];

        foreach ($rows as $row) {
            $flags[(string) $row['flag_key']] = self::evaluateFlag(
                $row,
                $userId,
                $accountType,
                $platform,
                $appVersion
            );
        }

        return $flags;
    }

    /** @param array<string,mixed> $flag */
    private static function evaluateFlag(
        array $flag,
        ?int $userId,
        string $accountType,
        string $platform,
        string $appVersion
    ): bool {
        if ((int) $flag['is_enabled'] !== 1) {
            return false;
        }

        if (!self::audienceMatches((string) $flag['audience'], $accountType, $userId !== null)) {
            return false;
        }

        $platforms = self::decodeList($flag['platforms'] ?? null);
        if ($platforms !== [] && !in_array($platform, $platforms, true)) {
            return false;
        }

        $minVersion = (string) ($flag['min_app_version'] ?? '');
        if ($minVersion !== '' && version_compare($appVersion, $minVersion, '<')) {
            return false;
        }

        $rollout = (int) $flag['rollout_percent'];
        if ($rollout >= 100) {
            return true;
        }
        if ($rollout <= 0) {
            return false;
        }

        // Stable per-user bucket: hashing the flag key with the user id keeps
        // the same person in the same bucket across sessions, and keeps the
        // buckets independent between flags.
        $seed   = $userId === null ? 'anon' : (string) $userId;
        $bucket = hexdec(substr(md5($flag['flag_key'] . ':' . $seed), 0, 8)) % 100;

        return $bucket < $rollout;
    }

    // -----------------------------------------------------------------
    // Home layout
    // -----------------------------------------------------------------

    /**
     * The home screen, as configured in `homepage_sections`.
     *
     * Each section carries its own type, layout and resolved items, so the
     * client renders whatever it is given rather than assuming a fixed order.
     *
     * @return list<array<string,mixed>>
     */
    public static function homeSections(?int $userId, string $accountType = 'brand'): array
    {
        $rows = Database::select(
            'SELECT * FROM `homepage_sections`
              WHERE `is_active` = 1
              ORDER BY `sort_order`, `id`'
        );

        $sections = [];

        foreach ($rows as $row) {
            if (!self::audienceMatches((string) $row['audience'], $accountType, $userId !== null)) {
                continue;
            }

            $section = [
                'id'         => (int) $row['id'],
                'type'       => $row['section_type'],
                'title'      => $row['title'],
                'subtitle'   => $row['subtitle'],
                'layout'     => $row['layout'],
                'cta_label'  => $row['cta_label'],
                'cta_action' => $row['cta_action'],
                'items'      => self::resolveItems($row, $userId, $accountType),
            ];

            if ($row['section_type'] === 'custom_html') {
                $section['html'] = $row['custom_html'];
            }

            // A rail with nothing in it is worse than no rail at all.
            if ($section['items'] === [] && !in_array(
                $row['section_type'],
                ['custom_html', 'cta_card', 'stats'],
                true
            )) {
                continue;
            }

            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * Fetch the rows a section should display, based on its data source.
     *
     * @param  array<string,mixed> $section
     * @return list<array<string,mixed>>
     */
    private static function resolveItems(
        array $section,
        ?int $userId,
        string $accountType = 'brand'
    ): array {
        $limit = max(1, min(30, (int) $section['item_limit']));
        $type  = (string) $section['section_type'];
        $value = trim((string) ($section['source_value'] ?? ''));

        return match ($type) {
            // Position and audience are applied here for the same reason
            // ContentController::home applies them to its flat `banners` key.
            // Without them a guest was shown brand-recruitment and
            // creator-recruitment copy side by side, and a `home_mid` banner
            // surfaced in the hero rail. The section's `source_value` selects
            // the position, defaulting to the hero slot.
            'banner_carousel' => Database::select(
                'SELECT `id`, `title`, `subtitle`, `image_url`, `image_url_dark`,
                        `cta_label`, `action_type`, `action_value`
                   FROM `banners`
                  WHERE `is_active` = 1
                    AND `position` = :position
                    AND (`audience` = "all" OR `audience` = :audience)
                    AND (`starts_at` IS NULL OR `starts_at` <= NOW())
                    AND (`ends_at`   IS NULL OR `ends_at`   >= NOW())
                  ORDER BY `sort_order`, `id`
                  LIMIT ' . $limit,
                [
                    'position' => $value !== '' ? $value : 'home_hero',
                    'audience' => $userId === null
                        ? 'guests'
                        : ($accountType === 'creator' ? 'creators' : 'brands'),
                ]
            ),

            'category_strip' => Database::select(
                'SELECT `id`, `name`, `slug`, `icon`, `color_hex`, `cover_image`, `creator_count`
                   FROM `categories`
                  WHERE `is_active` = 1 AND `deleted_at` IS NULL
                  ORDER BY `sort_order`, `name`
                  LIMIT ' . $limit
            ),

            'creator_grid', 'creator_carousel' =>
                self::creatorsFor($section, $userId, $limit),

            'testimonials' => Database::select(
                'SELECT `id`, `author_name`, `author_role`, `avatar_url`, `rating`, `content`
                   FROM `testimonials`
                  WHERE `is_active` = 1
                  ORDER BY `sort_order`, `id`
                  LIMIT ' . $limit
            ),

            'blog_strip' => Database::select(
                "SELECT `id`, `title`, `slug`, `excerpt`, `cover_image`, `published_at`
                   FROM `blogs`
                  WHERE `status` = 'published' AND `deleted_at` IS NULL
                  ORDER BY `published_at` DESC
                  LIMIT " . $limit
            ),

            default => [],
        };
    }

    /**
     * Creators for a rail, honouring the section's chosen source.
     *
     * @param  array<string,mixed> $section
     * @return list<array<string,mixed>>
     */
    private static function creatorsFor(array $section, ?int $userId, int $limit): array
    {
        $source = (string) $section['data_source'];
        $value  = trim((string) ($section['source_value'] ?? ''));

        $filters = ['per_page' => $limit];

        switch ($source) {
            case 'featured':
                $filters['featured'] = 1;
                break;
            case 'top_rated':
                $filters['sort'] = 'rating';
                break;
            case 'trending':
                $filters['sort'] = 'popular';
                break;
            case 'newest':
                $filters['sort'] = 'newest';
                break;
            case 'category':
                if ($value !== '') {
                    $filters['category_slug'] = $value;
                }
                break;
            case 'saved':
                // The viewer's own favourites. Anonymous callers have none, and
                // an empty rail is suppressed by homeSections() — which is the
                // right outcome: a "Saved creators" heading over strangers is
                // worse than no rail.
                if ($userId === null) {
                    return [];
                }

                $rows = Database::select(
                    'SELECT c.*, u.`avatar_url`, u.`name` AS `account_name`,
                            cat.`name` AS `category_name`, cat.`slug` AS `category_slug`,
                            1 AS `is_favorite`
                       FROM `favorites` f
                       INNER JOIN `creators` c ON c.`id` = f.`creator_id`
                       INNER JOIN `users` u ON u.`id` = c.`user_id`
                       LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`
                      WHERE f.`user_id` = :user_id
                        AND c.`deleted_at` IS NULL
                        AND c.`status` = "approved"
                      ORDER BY f.`created_at` DESC
                      LIMIT ' . $limit,
                    ['user_id' => $userId]
                );

                return array_map([CreatorService::class, 'presentCard'], $rows);

            case 'manual':
                // An explicit id list; preserve the admin's ordering.
                $ids = array_values(array_filter(array_map(
                    'intval',
                    explode(',', $value)
                )));

                if ($ids === []) {
                    return [];
                }

                [$placeholders, $bindings] = Database::inClause($ids);

                $rows = Database::select(
                    'SELECT c.*, u.`avatar_url`, u.`name` AS `account_name`,
                            cat.`name` AS `category_name`, cat.`slug` AS `category_slug`
                       FROM `creators` c
                       INNER JOIN `users` u ON u.`id` = c.`user_id`
                       LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`
                      WHERE c.`id` IN (' . $placeholders . ') AND c.`deleted_at` IS NULL
                      ORDER BY FIELD(c.`id`, ' . implode(',', $ids) . ')',
                    $bindings
                );

                return array_map([CreatorService::class, 'presentCard'], $rows);
        }

        return CreatorService::search($filters, $userId)['items'];
    }

    // -----------------------------------------------------------------
    // Onboarding
    // -----------------------------------------------------------------

    /**
     * The intro slides shown before sign-up.
     *
     * Unauthenticated by definition, so `audience` narrows only by the path the
     * visitor picked (brand or creator), not by an account they do not have.
     *
     * @return list<array<string,mixed>>
     */
    public static function onboardingSlides(string $audience = 'all'): array
    {
        $rows = Database::select(
            "SELECT `id`, `title`, `subtitle`, `image_url`, `image_url_2`, `image_url_3`,
                    `icon`, `cta_label`
               FROM `onboarding_slides`
              WHERE `is_active` = 1
                AND (`audience` = 'all' OR `audience` = :audience)
              ORDER BY `sort_order`, `id`",
            ['audience' => $audience]
        );

        return array_map(
            static fn (array $row): array => [
                'id'        => (int) $row['id'],
                'title'     => $row['title'],
                'subtitle'  => $row['subtitle'],
                'image_url' => $row['image_url'],
                // The whole collage in display order, blanks dropped: the
                // hero first, then the supporting photos. `image_url` stays
                // for builds that only know about one picture.
                'images'    => array_values(array_filter(
                    [$row['image_url'], $row['image_url_2'], $row['image_url_3']],
                    static fn ($url): bool => is_string($url) && trim($url) !== ''
                )),
                'icon'      => $row['icon'],
                'cta_label' => $row['cta_label'],
            ],
            $rows
        );
    }

    // -----------------------------------------------------------------
    // Popups
    // -----------------------------------------------------------------

    /**
     * Popups eligible for this screen and caller.
     *
     * The client decides *when* to show one from `trigger_type`/`frequency`;
     * the server only decides *which* are eligible at all.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * Popups the caller is eligible to see.
     *
     * @param string $placement the screen asking, e.g. `home`. The default
     *                          `all` means "every placement" — the caller
     *                          fetches once and routes them itself, using the
     *                          `placement` field on each row. Passing a
     *                          specific screen narrows to that screen plus the
     *                          ones marked `all`.
     */
    public static function popups(
        ?int $userId,
        string $accountType = 'brand',
        string $placement = 'all'
    ): array {
        $rows = Database::select(
            "SELECT * FROM `popups`
              WHERE `is_active` = 1
                AND `deleted_at` IS NULL
                AND (`starts_at` IS NULL OR `starts_at` <= NOW())
                AND (`ends_at`   IS NULL OR `ends_at`   >= NOW())
                AND (:placement = '' OR `placement` = 'all' OR `placement` = :placement)
              ORDER BY `priority` DESC, `id`",
            ['placement' => $placement === 'all' ? '' : $placement]
        );

        $eligible = [];

        foreach ($rows as $row) {
            if (!self::audienceMatches((string) $row['audience'], $accountType, $userId !== null)) {
                continue;
            }

            $eligible[] = [
                'id'             => (int) $row['id'],
                'type'           => $row['type'],
                'title'          => $row['title'],
                'content'        => $row['content'],
                'image_url'      => $row['image_url'],
                'background'     => $row['background_color'],
                'text_color'     => $row['text_color'],
                'cta_label'      => $row['cta_label'],
                'cta_action'     => $row['cta_action'],
                'cta_value'      => $row['cta_value'],
                // Included so a client that fetched every placement can show
                // each popup on the right screen without another round trip.
                'placement'      => $row['placement'],
                'trigger'        => $row['trigger_type'],
                'trigger_value'  => (int) $row['trigger_value'],
                'frequency'      => $row['frequency'],
                'is_dismissible' => (bool) $row['is_dismissible'],
            ];
        }

        return $eligible;
    }

    /**
     * The admin-built navigation menus, keyed by location.
     *
     * The admin has had a full menu builder — reorder, nest, audience, badges,
     * five link types — since the panel was written, and nothing read a single
     * row of it. 84 items sat in the database (7 intended, duplicated twelve
     * times by a seed with no idempotency guard) with no way to reach the app.
     *
     * Only the two app-facing locations are returned. `web_header`,
     * `web_footer` and `admin` are rendered server-side and would be noise in
     * a mobile payload.
     *
     * @param  list<string> $locations
     * @return array<string,list<array<string,mixed>>>
     */
    public static function menus(
        ?int $userId,
        string $accountType = 'brand',
        array $locations = ['app_drawer', 'app_bottom']
    ): array {
        if ($locations === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($locations), '?'));

        $rows = Database::select(
            "SELECT i.*, m.`location`
               FROM `menu_items` i
               INNER JOIN `menus` m ON m.`id` = i.`menu_id`
              WHERE m.`is_active` = 1
                AND i.`is_active` = 1
                AND m.`location` IN ({$placeholders})
              ORDER BY m.`location`, i.`sort_order`, i.`id`",
            $locations
        );

        $authenticated = $userId !== null;

        // Two passes. The first keeps only what this caller may see; the second
        // nests children under parents. Doing it in one pass would attach a
        // child to a parent that the audience filter had already removed, and
        // the child would then vanish with it even when it was addressed to
        // this caller in its own right.
        $visible = [];

        foreach ($rows as $row) {
            if (!self::audienceMatches((string) $row['audience'], $accountType, $authenticated)) {
                continue;
            }

            $visible[(int) $row['id']] = $row;
        }

        $menus    = [];
        $children = [];

        foreach ($visible as $id => $row) {
            $parentId = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];

            // A child whose parent this caller cannot see is promoted to the
            // top level rather than dropped: it passed the audience test on its
            // own terms, so hiding it would be a second, unasked-for filter.
            if ($parentId > 0 && isset($visible[$parentId])) {
                $children[$parentId][] = self::presentMenuItem($row);
                continue;
            }

            $menus[(string) $row['location']][$id] = self::presentMenuItem($row);
        }

        $out = [];

        foreach ($menus as $location => $items) {
            $out[$location] = array_values(array_map(
                static function (array $item) use ($children): array {
                    $item['children'] = $children[$item['id']] ?? [];

                    return $item;
                },
                $items
            ));
        }

        return $out;
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function presentMenuItem(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'label'         => (string) $row['label'],
            // A Material icon name. The client maps it onto a bundled glyph —
            // Flutter tree-shakes icons, so a font codepoint sent from the
            // server would render as a blank box in a release build.
            'icon'          => $row['icon'],
            'link_type'     => (string) $row['link_type'],
            'link_value'    => $row['link_value'],
            'badge_text'    => $row['badge_text'],
            'badge_color'   => $row['badge_color'],
            'open_external' => (bool) $row['open_external'],
            'children'      => [],
        ];
    }

    /** Bump the impression counter when a client reports showing a popup. */
    public static function recordPopupImpression(int $popupId): void
    {
        Database::execute(
            'UPDATE `popups` SET `impressions` = `impressions` + 1 WHERE `id` = :id',
            ['id' => $popupId]
        );
    }

    // -----------------------------------------------------------------
    // Shared
    // -----------------------------------------------------------------

    /**
     * Whether an `audience` value admits this caller.
     *
     * `new_users` and `beta`/`internal` are intentionally conservative: without
     * a signal to evaluate them against, they resolve to false rather than
     * leaking an unfinished feature to everyone.
     */
    private static function audienceMatches(
        string $audience,
        string $accountType,
        bool $isAuthenticated
    ): bool {
        // `brands` and `creators` require a signed-in account of that type.
        // Callers pass 'brand' as the default account type for an anonymous
        // request, so without the authentication test a signed-out visitor
        // matched every brand-targeted popup, flag and menu entry — including,
        // once menus began rendering, drawer links to screens they cannot open.
        return match ($audience) {
            'all'                 => true,
            'brands'              => $isAuthenticated && $accountType === 'brand',
            'creators'            => $isAuthenticated && $accountType === 'creator',
            'guests'              => !$isAuthenticated,
            'new_users'           => $isAuthenticated,
            'beta', 'internal'    => false,
            default               => true,
        };
    }

    /**
     * @param  mixed $raw
     * @return list<string>
     */
    private static function decodeList(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? array_values(array_map('strval', $decoded))
            : [];
    }
}

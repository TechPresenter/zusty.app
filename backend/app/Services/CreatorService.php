<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * Creator profiles: discovery, search, portfolio and derived statistics.
 *
 * Search is the busiest read path in the product, so the query here is built
 * once with an allow-listed sort column and bound filters — never string
 * concatenation of user input.
 */
final class CreatorService
{
    private const SORTABLE = [
        'relevance'  => 'c.`search_boost` DESC, c.`rating_avg` DESC, c.`rating_count` DESC',
        'rating'     => 'c.`rating_avg` DESC, c.`rating_count` DESC',
        'popular'    => 'c.`followers_total` DESC',
        'price_low'  => 'c.`price_min` ASC',
        'price_high' => 'c.`price_max` DESC',
        'newest'     => 'c.`created_at` DESC',
        'collabs'    => 'c.`collabs_count` DESC',
    ];

    /** Create the empty profile a new creator account fills in during onboarding. */
    public static function createDraftProfile(int $userId, string $displayName): int
    {
        $handle = self::uniqueHandle($displayName);

        return Database::insert('creators', [
            'user_id'      => $userId,
            'handle'       => $handle,
            'display_name' => mb_substr($displayName, 0, 120),
            'currency'     => Money::defaultCurrency(),
            'status'       => 'draft',
            // Honours creator.default_profile_visibility, which an operator can
            // set to 'private' when they want new creators to opt in to being
            // listed. The column defaults to 1 in the schema, so without this
            // the setting was read by nothing and could never take effect.
            'is_public'    => CreatorEngagementService::defaultIsPublic() ? 1 : 0,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Paginated, filtered creator search.
     *
     * @param array{
     *   q?:string, category_id?:int, category_slug?:string, city?:string,
     *   platform?:string, min_price?:float, max_price?:float, min_rating?:float,
     *   min_followers?:int, max_followers?:int, min_engagement?:float,
     *   language?:string, gender?:string, response_time?:int,
     *   verified_only?:bool, availability?:string,
     *   sort?:string, page?:int, per_page?:int
     * } $filters
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public static function search(array $filters, ?int $viewerId = null): array
    {
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        // `is_public` is the creator's own switch, distinct from `status`
        // (the platform's verdict). Migration 039 added it; without this clause
        // the column would have shipped inert, and a creator who took their
        // profile down would have gone on appearing in every brand search.
        $clauses  = [
            "c.`status` = 'approved'",
            'c.`is_public` = 1',
            'c.`deleted_at` IS NULL',
            "u.`status` = 'active'",
        ];
        $bindings = [];
        $joins    = '';

        // A block either way keeps the two out of each other's search, and so
        // out of every rail, recommendation and AI match built on it.
        if ($viewerId !== null && $viewerId > 0) {
            $clauses[]                = BlockService::notBlockedSql('c.`user_id`');
            $bindings['block_viewer'] = $viewerId;
        }

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $clauses[] = "(c.`display_name` LIKE :term ESCAPE '\\\\'
                        OR c.`headline` LIKE :term ESCAPE '\\\\'
                        OR c.`handle` LIKE :term ESCAPE '\\\\'
                        OR c.`city` LIKE :term ESCAPE '\\\\')";
            $bindings['term'] = Str::likeTerm($term);
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);

        if ($categoryId === 0 && !empty($filters['category_slug'])) {
            $categoryId = (int) (Database::scalar(
                'SELECT `id` FROM `categories` WHERE `slug` = :slug AND `is_active` = 1',
                ['slug' => (string) $filters['category_slug']]
            ) ?? 0);
        }

        if ($categoryId > 0) {
            $joins    .= ' INNER JOIN `creator_categories` cc ON cc.`creator_id` = c.`id` AND cc.`category_id` = :category_id';
            $bindings['category_id'] = $categoryId;
        }

        if (!empty($filters['city'])) {
            $clauses[]        = 'c.`city` = :city';
            $bindings['city'] = (string) $filters['city'];
        }

        if (!empty($filters['platform'])) {
            $joins    .= ' INNER JOIN `creator_social_accounts` csa ON csa.`creator_id` = c.`id` AND csa.`platform` = :platform';
            $bindings['platform'] = (string) $filters['platform'];
        }

        if (isset($filters['min_price']) && (float) $filters['min_price'] > 0) {
            $clauses[]             = 'c.`price_max` >= :min_price';
            $bindings['min_price'] = (float) $filters['min_price'];
        }

        if (isset($filters['max_price']) && (float) $filters['max_price'] > 0) {
            $clauses[]             = 'c.`price_min` <= :max_price';
            $bindings['max_price'] = (float) $filters['max_price'];
        }

        if (isset($filters['min_rating']) && (float) $filters['min_rating'] > 0) {
            $clauses[]              = 'c.`rating_avg` >= :min_rating';
            $bindings['min_rating'] = (float) $filters['min_rating'];
        }

        if (isset($filters['min_followers']) && (int) $filters['min_followers'] > 0) {
            $clauses[]                 = 'c.`followers_total` >= :min_followers';
            $bindings['min_followers'] = (int) $filters['min_followers'];
        }

        if (isset($filters['max_followers']) && (int) $filters['max_followers'] > 0) {
            $clauses[]                 = 'c.`followers_total` <= :max_followers';
            $bindings['max_followers'] = (int) $filters['max_followers'];
        }

        // `languages` is a JSON array, e.g. ["English","Hindi","Marathi"].
        // JSON_CONTAINS needs the needle as a JSON scalar, hence the encode.
        if (!empty($filters['language'])) {
            $clauses[]            = 'JSON_CONTAINS(c.`languages`, :language)';
            $bindings['language'] = (string) json_encode((string) $filters['language']);
        }

        if (!empty($filters['gender'])) {
            $clauses[]          = 'c.`gender` = :gender';
            $bindings['gender'] = (string) $filters['gender'];
        }

        // "Responds within N hours" — smaller is better, so this is an upper bound.
        if (isset($filters['response_time']) && (int) $filters['response_time'] > 0) {
            $clauses[]                 = 'c.`response_time_hours` <= :response_time';
            $bindings['response_time'] = (int) $filters['response_time'];
        }

        // Engagement lives on the linked social accounts rather than the
        // creator row, so this is a correlated EXISTS instead of a join —
        // a join would multiply rows when a creator has several platforms.
        if (isset($filters['min_engagement']) && (float) $filters['min_engagement'] > 0) {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM `creator_social_accounts` cse
                 WHERE cse.`creator_id` = c.`id`
                   AND cse.`engagement_rate` >= :min_engagement
            )';
            $bindings['min_engagement'] = (float) $filters['min_engagement'];
        }

        if (!empty($filters['verified_only'])) {
            $clauses[] = 'c.`is_verified` = 1';
        }

        if (!empty($filters['availability'])) {
            $clauses[]                = 'c.`availability_status` = :availability';
            $bindings['availability'] = (string) $filters['availability'];
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        // The sort key is looked up in a constant map, so nothing user-supplied
        // ever becomes SQL.
        $orderBy = self::SORTABLE[(string) ($filters['sort'] ?? 'relevance')] ?? self::SORTABLE['relevance'];

        $total = (int) Database::scalar(
            'SELECT COUNT(DISTINCT c.`id`) FROM `creators` c
             INNER JOIN `users` u ON u.`id` = c.`user_id`' . $joins . $where,
            $bindings
        );

        $favoriteSelect = 'NULL AS `is_favorite`';
        $followSelect   = 'NULL AS `is_following`';

        if ($viewerId !== null && $viewerId > 0) {
            $favoriteSelect = 'EXISTS(SELECT 1 FROM `favorites` f WHERE f.`creator_id` = c.`id` AND f.`user_id` = :viewer_id) AS `is_favorite`';
            $followSelect   = 'EXISTS(SELECT 1 FROM `follows` fo WHERE fo.`creator_id` = c.`id` AND fo.`follower_id` = :viewer_id) AS `is_following`';
            $bindings['viewer_id'] = $viewerId;
        }

        $items = Database::select(
            'SELECT DISTINCT c.`id`, c.`user_id`, c.`handle`, c.`display_name`, c.`headline`,
                    c.`city`, c.`state`, c.`country`, c.`price_min`, c.`price_max`, c.`currency`,
                    c.`rating_avg`, c.`rating_count`, c.`followers_total`, c.`posts_count`,
                    c.`collabs_count`, c.`is_verified`, c.`is_featured`, c.`availability_status`,
                    c.`primary_category_id`,
                    u.`avatar_url`, u.`name` AS `account_name`,
                    cat.`name` AS `category_name`, cat.`slug` AS `category_slug`,
                    ' . $favoriteSelect . ', ' . $followSelect . '
             FROM `creators` c
             INNER JOIN `users` u ON u.`id` = c.`user_id`
             LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`' . $joins . $where . '
             ORDER BY c.`is_featured` DESC, ' . $orderBy . '
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings
        );

        return [
            'items'    => array_map([self::class, 'presentCard'], self::withCardSocials($items)),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Expire the creator's KYC approval if it ran past its date since the
     * nightly sweep, so the tick (and every KYC-derived badge) is gone before
     * anything is read for display.
     *
     * One indexed query when nothing has lapsed — it only finds the owner when
     * an approved profile is past its expiry date. Never fatal to the page.
     */
    private static function expireLapsedTick(int|string $key, bool $isNumeric): void
    {
        try {
            $userId = Database::scalar(
                "SELECT c.`user_id` FROM `creators` c
                  WHERE " . ($isNumeric ? 'c.`id` = :key' : 'c.`handle` = :key') . "
                    AND c.`deleted_at` IS NULL
                    AND EXISTS (SELECT 1 FROM `kyc_profiles` p
                                 WHERE p.`user_id` = c.`user_id` AND p.`status` = 'approved'
                                   AND p.`expires_on` IS NOT NULL AND p.`expires_on` < CURDATE()
                                   AND p.`deleted_at` IS NULL)
                  LIMIT 1",
                ['key' => $key]
            );

            if ($userId !== null) {
                KycService::expireIfLapsed((int) $userId);
            }
        } catch (\Throwable $e) {
            Logger::warning('Could not expire a lapsed KYC approval before showing a creator profile', [
                'creator' => (string) $key,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Full public profile for the Creator Detail screen.
     *
     * @return array<string,mixed>
     */
    public static function profile(int|string $idOrHandle, ?int $viewerId = null): array
    {
        $isNumeric = is_int($idOrHandle) || ctype_digit((string) $idOrHandle);

        // An approval that lapsed since the nightly sweep is expired BEFORE the
        // row is read: the expiry drops `creators.is_verified`, and reading the
        // row first served the tick one more time beside a badge list that no
        // longer had it. One indexed read when nothing has lapsed.
        self::expireLapsedTick($isNumeric ? (int) $idOrHandle : (string) $idOrHandle, $isNumeric);

        $creator = Database::selectOne(
            'SELECT c.*, u.`avatar_url`, u.`name` AS `account_name`, u.`last_seen_at`, u.`created_at` AS `joined_at`,
                    cat.`name` AS `category_name`, cat.`slug` AS `category_slug`
             FROM `creators` c
             INNER JOIN `users` u ON u.`id` = c.`user_id`
             LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`
             WHERE ' . ($isNumeric ? 'c.`id` = :key' : 'c.`handle` = :key') . '
               AND c.`deleted_at` IS NULL',
            ['key' => $isNumeric ? (int) $idOrHandle : (string) $idOrHandle]
        );

        if ($creator === null) {
            throw HttpException::notFound('Creator not found.');
        }

        // Only the owner may view a profile that is not live.
        //
        // Two separate reasons a profile is not live: the platform has not
        // approved it, or the creator has switched it off themselves. The
        // owner sees their own either way — that is what makes "preview my
        // public profile" work without a second code path.
        $isOwner = (int) $creator['user_id'] === (int) $viewerId;

        if (!$isOwner && ($creator['status'] !== 'approved' || (int) $creator['is_public'] !== 1)) {
            throw HttpException::notFound('Creator not found.');
        }

        // A creator who blocked the viewer is not there for them, exactly as
        // in search. One the viewer blocked still opens, flagged, so the app
        // can offer to unblock instead of Message and Book.
        $blockedByViewer = false;

        if (!$isOwner && $viewerId !== null && $viewerId > 0) {
            if (BlockService::hasBlocked((int) $creator['user_id'], $viewerId)) {
                throw HttpException::notFound('Creator not found.');
            }

            $blockedByViewer = BlockService::hasBlocked($viewerId, (int) $creator['user_id']);
        }

        $creatorId = (int) $creator['id'];

        $creator['categories'] = Database::select(
            'SELECT cat.`id`, cat.`name`, cat.`slug`, cat.`icon`, cat.`color_hex`
             FROM `creator_categories` cc
             INNER JOIN `categories` cat ON cat.`id` = cc.`category_id`
             WHERE cc.`creator_id` = :id',
            ['id' => $creatorId]
        );

        // Ordered by the creator's own arrangement, not by follower count.
        // `sort_order` is what PUT /me/creator/social/order writes, and reading
        // by followers meant reordering changed nothing anyone could see.
        // is_primary leads within that so the account they nominated is first.
        $creator['social_accounts'] = Database::select(
            'SELECT `id`, `platform`, `label`, `handle`, `profile_url`,
                    `followers`, `following`, `posts`, `avg_likes`, `avg_comments`, `avg_views`,
                    `engagement_rate`, `is_verified`, `is_primary`, `sort_order`, `last_synced_at`
             FROM `creator_social_accounts`
             WHERE `creator_id` = :id
             ORDER BY `is_primary` DESC, `sort_order`, `followers` DESC',
            ['id' => $creatorId]
        );

        // Present each account from the `social_platform` taxonomy, so a client
        // renders a network's name, icon and colour without holding a list of
        // its own. Before this every app hardcoded a switch over eight
        // platforms, and the seven added since — Threads, TikTok, Moj, Josh,
        // ShareChat and the link-only entries — fell through to the raw slug.
        $platforms = TaxonomyService::terms('social_platform');
        $byValue   = [];

        foreach ($platforms as $term) {
            $byValue[(string) $term['value']] = $term;
        }

        foreach ($creator['social_accounts'] as &$account) {
            $term = $byValue[(string) $account['platform']] ?? null;

            $meta = [];

            if ($term !== null && !empty($term['metadata'])) {
                $decoded = is_array($term['metadata'])
                    ? $term['metadata']
                    : json_decode((string) $term['metadata'], true);

                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            // A custom link carries the name the creator gave it ("My
            // Substack"); a real network takes its name from the taxonomy,
            // whatever an older row's label says, so no Instagram row can
            // be shown to brands under a name its creator typed.
            $ownLabel                  = ($meta['custom_label'] ?? false) === true ? (string) ($account['label'] ?? '') : '';
            $account['platform_label'] = (string) ($ownLabel ?: ($term['label'] ?? $account['platform']));
            $account['icon']           = (string) ($term['icon'] ?? 'link');
            // TaxonomyService normalises `color_hex` to `color` on the way out.
            $account['color']          = (string) ($term['color'] ?? '#64748B');

            // Followers and engagement are meaningless for a website or a blog,
            // so the client is told not to render them rather than showing
            // three zeroes.
            $account['has_metrics'] = (bool) ($meta['metrics'] ?? false);

            $account['followers']       = (int) $account['followers'];
            $account['following']       = (int) $account['following'];
            $account['posts']           = (int) $account['posts'];
            $account['avg_views']       = (int) $account['avg_views'];
            $account['engagement_rate'] = (float) $account['engagement_rate'];
            $account['is_verified']     = (bool) $account['is_verified'];
            $account['is_primary']      = (bool) $account['is_primary'];
        }
        unset($account);

        $creator['packages'] = Database::select(
            'SELECT `id`, `title`, `description`, `platform`, `deliverable_type`, `quantity`,
                    `price`, `currency`, `turnaround_days`, `revisions`, `includes`
             FROM `creator_packages`
             WHERE `creator_id` = :id AND `is_active` = 1 AND `deleted_at` IS NULL
             ORDER BY `sort_order`, `price`',
            ['id' => $creatorId]
        );

        foreach ($creator['packages'] as &$package) {
            $package['includes']       = json_decode((string) ($package['includes'] ?? '[]'), true) ?: [];
            $package['price']          = Money::round((float) $package['price']);
            $package['price_formatted'] = Money::formatShort((float) $package['price'], (string) $package['currency']);
        }
        unset($package);

        $creator['portfolio_counts'] = self::portfolioCounts($creatorId);

        $creator['recent_portfolio'] = Database::select(
            'SELECT `id`, `media_kind`, `title`, `media_url`, `thumbnail_url`, `external_url`,
                    `aspect_ratio`, `duration_secs`, `views_count`, `likes_count`
             FROM `creator_portfolio`
             WHERE `creator_id` = :id AND `is_active` = 1 AND `deleted_at` IS NULL AND `moderation_status` = "approved"
             ORDER BY `is_featured` DESC, `sort_order`, `id` DESC
             LIMIT 12',
            ['id' => $creatorId]
        );

        // A visitor does not see reviews by someone they have a block with; the
        // creator previewing their own page sees every review, to reply to or
        // report it. The rating still counts them all.
        $reviewBindings = ['id' => $creatorId];
        $reviewFilter   = '';

        if (!$isOwner && $viewerId !== null && $viewerId > 0) {
            $reviewFilter                   = ' AND ' . BlockService::notBlockedSql('r.`reviewer_id`');
            $reviewBindings['block_viewer'] = $viewerId;
        }

        $creator['reviews'] = Database::select(
            "SELECT r.`id`, r.`rating`, r.`title`, r.`comment`, r.`created_at`, r.`reply`, r.`replied_at`,
                    u.`name` AS `reviewer_name`, u.`avatar_url` AS `reviewer_avatar`
             FROM `reviews` r
             INNER JOIN `users` u ON u.`id` = r.`reviewer_id`
             WHERE r.`creator_id` = :id AND r.`is_published` = 1 AND r.`deleted_at` IS NULL
               AND r.`direction` = 'brand_to_creator'" . $reviewFilter . "
             ORDER BY r.`id` DESC LIMIT 10",
            $reviewBindings
        );

        $creator['rating_breakdown'] = self::ratingBreakdown($creatorId);

        if ($viewerId !== null && $viewerId > 0) {
            $creator['is_favorite'] = Database::scalar(
                'SELECT 1 FROM `favorites` WHERE `user_id` = :user_id AND `creator_id` = :creator_id',
                ['user_id' => $viewerId, 'creator_id' => $creatorId]
            ) !== null;

            $creator['is_following'] = Database::scalar(
                'SELECT 1 FROM `follows` WHERE `follower_id` = :user_id AND `creator_id` = :creator_id',
                ['user_id' => $viewerId, 'creator_id' => $creatorId]
            ) !== null;

            $creator['is_owner'] = (int) $creator['user_id'] === $viewerId;
        } else {
            $creator['is_favorite']  = false;
            $creator['is_following'] = false;
            $creator['is_owner']     = false;
        }

        $creator['is_blocked'] = $blockedByViewer;

        $creator['languages']       = json_decode((string) ($creator['languages'] ?? '[]'), true) ?: [];
        $creator['price_formatted'] = Money::formatRange(
            (float) $creator['price_min'],
            (float) $creator['price_max'],
            (string) $creator['currency']
        );
        $creator['is_online'] = $creator['last_seen_at'] !== null
            && strtotime((string) $creator['last_seen_at']) > time() - 300;

        // The badges this creator actually holds, each carrying its own label,
        // icon, colour and award date. `is_verified` remains on the row for
        // the search and ranking code that already reads it; this is the
        // richer view every client renders the profile from. The lapsed-KYC
        // check already ran above, before the row was loaded.
        $creator['badges'] = CreatorBadgeService::forCreator($creatorId, false);

        // -- The search card's keys, on the profile too ------------------------
        // The profile is `c.*`, so it carried `availability_status`,
        // `followers_total` and `rating_avg` where the card (presentCard) says
        // `availability`, `followers` and `rating`. A client building one
        // Creator model from either payload read the card names and silently
        // got defaults on the profile: every creator "Available", "0"
        // followers, no location. Added alongside the raw columns, never in
        // place of them, so nothing that reads the column names changes.
        $creator['availability']    = (string) $creator['availability_status'];
        $creator['followers']       = (int) $creator['followers_total'];
        $creator['followers_label'] = Str::compactNumber((int) $creator['followers_total']);
        $creator['rating']          = round((float) $creator['rating_avg'], 1);
        $creator['location']        = trim(implode(', ', array_filter([
            $creator['city'] ?? null,
            ($creator['state'] ?? null) ?: ($creator['country'] ?? null),
        ])));
        $creator['platforms'] = array_values(array_unique(array_map(
            static fn (array $account): string => (string) $account['platform'],
            $creator['social_accounts']
        )));

        // Follower-weighted, over the accounts that have audience figures at
        // all. A plain mean let a 300-follower side account move the headline
        // rate as much as the 50K main one; a website link has no rate and
        // must not drag the average towards zero.
        $reach    = 0;
        $weighted = 0.0;

        foreach ($creator['social_accounts'] as $account) {
            if (!$account['has_metrics'] || $account['followers'] <= 0) {
                continue;
            }

            $reach    += $account['followers'];
            $weighted += $account['engagement_rate'] * $account['followers'];
        }

        $creator['engagement_rate'] = $reach > 0 ? round($weighted / $reach, 2) : 0.0;

        // -- Insights ---------------------------------------------------------
        // Who the creator reaches and how brands rated the work, for the
        // profile's Insights tab. Both are null when there is nothing real to
        // show, so a client hides the block rather than drawing empty charts.
        $creator['audience']       = self::audienceSummary($creatorId);
        $creator['rating_aspects'] = self::ratingAspects($creatorId);

        // This is a public endpoint, but the row above is `c.*`. A visitor
        // must not receive the creator's date of birth, home address, the
        // platform's commission on them, a moderator's rejection note, or
        // internal ranking inputs. The owner keeps everything, so "preview my
        // profile" and the edit screens still see their own data.
        if (!$isOwner) {
            $private = [
                'date_of_birth', 'commission_rate', 'rejection_reason',
                'approved_by', 'approved_at', 'profile_score', 'search_boost',
                'featured_until', 'deleted_at',
            ];

            // The address is the creator's to publish: hidden unless they chose
            // to show it.
            if ((int) ($creator['address_is_public'] ?? 0) !== 1) {
                array_push($private, 'address_line1', 'address_line2', 'pin_code');
            }

            foreach ($private as $column) {
                unset($creator[$column]);
            }
        }

        return $creator;
    }

    /**
     * The creator's audience split — gender, age band and top locations —
     * across their linked accounts, weighted by each account's followers.
     *
     * `creator_social_accounts.audience_stats` is filled by the platform sync
     * (and by the seed) but was never read by anything, so a brand deciding
     * who to book could not see who the creator actually reaches. Weighted
     * rather than averaged so the account that holds the audience decides the
     * split. Locations come back largest first; gender and age keep the order
     * the sync wrote them in, which is the order they read naturally.
     *
     * @return array{accounts:int,gender:array<string,float>,age:array<string,float>,geo:array<string,float>}|null
     *         Null when no account has audience figures.
     */
    public static function audienceSummary(int $creatorId): ?array
    {
        $rows = Database::select(
            'SELECT `followers`, `audience_stats`
             FROM `creator_social_accounts`
             WHERE `creator_id` = :id AND `audience_stats` IS NOT NULL',
            ['id' => $creatorId]
        );

        $dimensions = ['gender', 'age', 'geo'];
        $sums       = array_fill_keys($dimensions, []);
        $weights    = array_fill_keys($dimensions, 0.0);
        $accounts   = 0;

        foreach ($rows as $row) {
            $stats = json_decode((string) $row['audience_stats'], true);

            if (!is_array($stats)) {
                continue;
            }

            // An account whose follower count has not synced yet still
            // describes its audience; a floor of 1 lets it count rather than
            // vanish from a split it is the only source for.
            $weight = (float) max(1, (int) $row['followers']);
            $used   = false;

            foreach ($dimensions as $dimension) {
                $split = $stats[$dimension] ?? null;

                if (!is_array($split) || $split === []) {
                    continue;
                }

                foreach ($split as $bucket => $share) {
                    if (!is_numeric($share)) {
                        continue;
                    }

                    $key = (string) $bucket;
                    $sums[$dimension][$key] = ($sums[$dimension][$key] ?? 0.0) + (float) $share * $weight;
                }

                $weights[$dimension] += $weight;
                $used = true;
            }

            if ($used) {
                $accounts++;
            }
        }

        if ($accounts === 0) {
            return null;
        }

        $summary = ['accounts' => $accounts];

        foreach ($dimensions as $dimension) {
            $shares = [];

            foreach ($sums[$dimension] as $bucket => $sum) {
                $shares[$bucket] = $weights[$dimension] > 0 ? round($sum / $weights[$dimension], 1) : 0.0;
            }

            if ($dimension === 'geo') {
                arsort($shares);
            }

            $summary[$dimension] = $shares;
        }

        return $summary;
    }

    /**
     * Average quality, communication and timeliness scores from published
     * brand reviews — the three questions the review form asks besides the
     * overall star rating.
     *
     * Each aspect is optional on a review, so each is averaged over the
     * reviews that answered it and is null when none did.
     *
     * @return array{count:int,quality:?float,communication:?float,timeliness:?float}|null
     *         Null when the creator has no published reviews.
     */
    public static function ratingAspects(int $creatorId): ?array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS `total`,
                    AVG(NULLIF(`quality_rating`, 0))       AS `quality`,
                    AVG(NULLIF(`communication_rating`, 0)) AS `communication`,
                    AVG(NULLIF(`timeliness_rating`, 0))    AS `timeliness`
             FROM `reviews`
             WHERE `creator_id` = :id AND `is_published` = 1 AND `deleted_at` IS NULL
               AND `direction` = 'brand_to_creator'",
            ['id' => $creatorId]
        );

        $total = (int) ($row['total'] ?? 0);

        if ($total === 0) {
            return null;
        }

        $average = static fn (mixed $value): ?float => $value === null ? null : round((float) $value, 1);

        return [
            'count'         => $total,
            'quality'       => $average($row['quality']),
            'communication' => $average($row['communication']),
            'timeliness'    => $average($row['timeliness']),
        ];
    }

    /**
     * Portfolio items filtered by tab (posts / reels / photos / videos).
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function portfolio(int $creatorId, string $kind = 'post', int $page = 1, int $perPage = 24): array
    {
        // Validated against the `portfolio_kind` taxonomy. This was a list of
        // four written here, and it SILENTLY fell back to 'post' — so after
        // migration 035 added carousel, short, live, audio, document and
        // case_study, asking for one of those returned the posts tab with a
        // 200 and no indication anything had been substituted.
        $kind    = TaxonomyService::isValid('portfolio_kind', $kind)
            ? $kind
            : (TaxonomyService::defaultValue('portfolio_kind') ?? 'post');
        $perPage = max(1, min(60, $perPage));
        $offset  = (max(1, $page) - 1) * $perPage;

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_portfolio`
             WHERE `creator_id` = :id AND `media_kind` = :kind AND `is_active` = 1 AND `deleted_at` IS NULL AND `moderation_status` = "approved"
              ',
            ['id' => $creatorId, 'kind' => $kind]
        );

        $items = Database::select(
            'SELECT `id`, `media_kind`, `title`, `caption`, `media_url`, `thumbnail_url`, `external_url`,
                    `source_platform`, `aspect_ratio`, `duration_secs`, `views_count`, `likes_count`,
                    `comments_count`, `published_at`
             FROM `creator_portfolio`
             WHERE `creator_id` = :id AND `media_kind` = :kind AND `is_active` = 1 AND `deleted_at` IS NULL AND `moderation_status` = "approved"
              
             ORDER BY `is_featured` DESC, `sort_order`, `id` DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            ['id' => $creatorId, 'kind' => $kind]
        );

        foreach ($items as &$item) {
            $item['views_label']    = Str::compactNumber((int) $item['views_count']);
            $item['likes_label']    = Str::compactNumber((int) $item['likes_count']);
            $item['duration_label'] = $item['duration_secs'] === null
                ? null
                : gmdate((int) $item['duration_secs'] >= 3600 ? 'H:i:s' : 'i:s', (int) $item['duration_secs']);
        }
        unset($item);

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string,int> */
    public static function portfolioCounts(int $creatorId): array
    {
        $rows = Database::select(
            'SELECT `media_kind`, COUNT(*) AS `total`
             FROM `creator_portfolio`
             WHERE `creator_id` = :id AND `is_active` = 1 AND `deleted_at` IS NULL AND `moderation_status` = "approved"
             GROUP BY `media_kind`',
            ['id' => $creatorId]
        );

        // Seeded from the taxonomy so every kind is present with a zero rather
        // than missing entirely. The map was four hardcoded keys, so a client
        // rendering tabs from this response could never show the six kinds
        // migration 035 added — they simply were not in the payload.
        $counts = [];

        foreach (TaxonomyService::terms('portfolio_kind') as $term) {
            $counts[(string) $term['value']] = 0;
        }

        foreach ($rows as $row) {
            $counts[(string) $row['media_kind']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array<int,int> star => count */
    public static function ratingBreakdown(int $creatorId): array
    {
        $rows = Database::select(
            "SELECT `rating`, COUNT(*) AS `total`
             FROM `reviews`
             WHERE `creator_id` = :id AND `is_published` = 1 AND `deleted_at` IS NULL
             GROUP BY `rating`",
            ['id' => $creatorId]
        );

        $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        foreach ($rows as $row) {
            $breakdown[(int) $row['rating']] = (int) $row['total'];
        }

        return $breakdown;
    }

    /** Toggle a favourite. Returns the new state. */
    public static function toggleFavorite(int $userId, int $creatorId): bool
    {
        $exists = Database::scalar(
            'SELECT 1 FROM `favorites` WHERE `user_id` = :user_id AND `creator_id` = :creator_id',
            ['user_id' => $userId, 'creator_id' => $creatorId]
        );

        if ($exists !== null) {
            Database::delete('favorites', ['user_id' => $userId, 'creator_id' => $creatorId]);

            return false;
        }

        $ownerId = Database::scalar('SELECT `user_id` FROM `creators` WHERE `id` = :id', ['id' => $creatorId]);

        if ($ownerId === null) {
            throw HttpException::notFound('Creator not found.');
        }

        // Removing a saved creator always works; saving one across a block
        // does not (blocking removed it already).
        BlockService::assertNotBlocked($userId, (int) $ownerId);

        Database::insertIgnore('favorites', [
            'user_id'    => $userId,
            'creator_id' => $creatorId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /** Toggle a follow and keep the denormalised counter in step. */
    public static function toggleFollow(int $userId, int $creatorId): bool
    {
        $exists = Database::scalar(
            'SELECT 1 FROM `follows` WHERE `follower_id` = :user_id AND `creator_id` = :creator_id',
            ['user_id' => $userId, 'creator_id' => $creatorId]
        );

        // Unfollowing always works; following across a block does not.
        if ($exists === null) {
            BlockService::assertNotBlocked(
                $userId,
                (int) (Database::scalar('SELECT `user_id` FROM `creators` WHERE `id` = :id', ['id' => $creatorId]) ?? 0)
            );
        }

        return Database::transaction(static function () use ($exists, $userId, $creatorId): bool {
            if ($exists !== null) {
                Database::delete('follows', ['follower_id' => $userId, 'creator_id' => $creatorId]);
                Database::execute(
                    'UPDATE `creators` SET `platform_followers` = GREATEST(0, `platform_followers` - 1) WHERE `id` = :id',
                    ['id' => $creatorId]
                );

                return false;
            }

            Database::insertIgnore('follows', [
                'follower_id' => $userId,
                'creator_id'  => $creatorId,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            Database::execute(
                'UPDATE `creators` SET `platform_followers` = `platform_followers` + 1 WHERE `id` = :id',
                ['id' => $creatorId]
            );

            return true;
        });
    }

    /** Record a profile view for analytics (deduplicated per viewer per day). */
    public static function recordView(int $creatorId, ?int $viewerId, string $source = 'direct'): void
    {
        if ($viewerId !== null) {
            $ownProfile = Database::scalar(
                'SELECT 1 FROM `creators` WHERE `id` = :id AND `user_id` = :user_id',
                ['id' => $creatorId, 'user_id' => $viewerId]
            );

            if ($ownProfile !== null) {
                return; // Do not count a creator viewing themselves.
            }

            $alreadyToday = Database::scalar(
                'SELECT 1 FROM `creator_profile_views`
                 WHERE `creator_id` = :id AND `viewer_id` = :viewer AND `viewed_on` = CURDATE() LIMIT 1',
                ['id' => $creatorId, 'viewer' => $viewerId]
            );

            if ($alreadyToday !== null) {
                return;
            }
        }

        Database::insert('creator_profile_views', [
            'creator_id' => $creatorId,
            'viewer_id'  => $viewerId,
            // Validated against the `profile_view_source` taxonomy rather than
            // a list written here. Migration 039 replaced the ENUM behind this
            // column with that taxonomy precisely so an operator could start
            // counting a new acquisition path without a release; a hardcoded
            // whitelist would have gone on coercing every new term to 'direct',
            // leaving the terms unreachable and the column effectively frozen.
            'source'     => TaxonomyService::isValid('profile_view_source', $source) ? $source : 'direct',
            'viewed_on'  => date('Y-m-d'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Recompute derived statistics. Run nightly and after review changes.
     *
     * The price range comes from LIVE packages only and falls to 0 when none
     * is left. It used to fall back to the old value, so a creator who hid or
     * deleted their only package kept a "Starting at" no package offered.
     * Nothing else writes these columns (the profile form does not take a
     * price), and 0 is what the profile, the cards and the price filters read
     * as "no price".
     *
     * @return int Number of creators refreshed.
     */
    public static function refreshStatistics(?int $creatorId = null): int
    {
        $where    = $creatorId !== null ? ' WHERE c.`id` = :id' : '';
        $bindings = $creatorId !== null ? ['id' => $creatorId] : [];

        $updated = Database::execute(
            'UPDATE `creators` c
             SET c.`rating_avg` = COALESCE((
                    SELECT ROUND(AVG(r.`rating`), 2) FROM `reviews` r
                    WHERE r.`creator_id` = c.`id` AND r.`is_published` = 1 AND r.`deleted_at` IS NULL
                 ), 0),
                 c.`rating_count` = (
                    SELECT COUNT(*) FROM `reviews` r
                    WHERE r.`creator_id` = c.`id` AND r.`is_published` = 1 AND r.`deleted_at` IS NULL
                 ),
                 c.`followers_total` = COALESCE((
                    SELECT SUM(s.`followers`) FROM `creator_social_accounts` s WHERE s.`creator_id` = c.`id`
                 ), 0),
                 c.`posts_count` = (
                    SELECT COUNT(*) FROM `creator_portfolio` p
                    WHERE p.`creator_id` = c.`id` AND p.`is_active` = 1 AND p.`deleted_at` IS NULL AND p.`moderation_status` = "approved"
                 ),
                 c.`collabs_count` = (
                    SELECT COUNT(*) FROM `bookings` b
                    WHERE b.`creator_id` = c.`id` AND b.`status` = "completed" AND b.`deleted_at` IS NULL
                 ),
                 c.`price_min` = COALESCE((
                    SELECT MIN(p.`price`) FROM `creator_packages` p
                    WHERE p.`creator_id` = c.`id` AND p.`is_active` = 1 AND p.`deleted_at` IS NULL
                 ), 0),
                 c.`price_max` = COALESCE((
                    SELECT MAX(p.`price`) FROM `creator_packages` p
                    WHERE p.`creator_id` = c.`id` AND p.`is_active` = 1 AND p.`deleted_at` IS NULL
                 ), 0)' . $where,
            $bindings
        );

        // Completion rate: completed vs everything that reached acceptance.
        Database::execute(
            'UPDATE `creators` c
             SET c.`completion_rate` = COALESCE((
                SELECT ROUND(
                    100 * SUM(CASE WHEN b.`status` = "completed" THEN 1 ELSE 0 END)
                    / NULLIF(COUNT(*), 0), 2)
                FROM `bookings` b
                WHERE b.`creator_id` = c.`id`
                  AND b.`status` IN ("completed","cancelled","disputed","refunded")
                  AND b.`deleted_at` IS NULL
             ), 0)' . $where,
            $bindings
        );

        // Refresh the cached per-category creator counts used on the Categories screen.
        if ($creatorId === null) {
            Database::execute(
                'UPDATE `categories` cat
                 SET cat.`creator_count` = (
                    SELECT COUNT(DISTINCT cc.`creator_id`)
                    FROM `creator_categories` cc
                    INNER JOIN `creators` cr ON cr.`id` = cc.`creator_id`
                    WHERE cc.`category_id` = cat.`id` AND cr.`status` = "approved" AND cr.`deleted_at` IS NULL
                 )'
            );
        }

        return $updated;
    }

    /** Profile completeness, shown as a nudge on the creator dashboard. */
    public static function computeProfileScore(int $creatorId): int
    {
        $creator = Database::selectOne('SELECT * FROM `creators` WHERE `id` = :id', ['id' => $creatorId]);

        if ($creator === null) {
            return 0;
        }

        $score = 0;

        $score += !empty($creator['display_name']) ? 10 : 0;
        $score += !empty($creator['headline'])     ? 10 : 0;
        $score += mb_strlen((string) ($creator['about'] ?? '')) >= 80 ? 15 : 0;
        $score += !empty($creator['city'])         ? 5  : 0;
        $score += !empty($creator['primary_category_id']) ? 10 : 0;

        $socials = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_social_accounts` WHERE `creator_id` = :id',
            ['id' => $creatorId]
        );
        $score += min(15, $socials * 8);

        $packages = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_packages` WHERE `creator_id` = :id AND `is_active` = 1 AND `deleted_at` IS NULL',
            ['id' => $creatorId]
        );
        $score += min(20, $packages * 10);

        $portfolio = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_portfolio` WHERE `creator_id` = :id AND `is_active` = 1 AND `deleted_at` IS NULL AND `moderation_status` = "approved"',
            ['id' => $creatorId]
        );
        $score += min(15, $portfolio * 3);

        $score = min(100, $score);

        Database::update('creators', ['profile_score' => $score], ['id' => $creatorId]);

        return $score;
    }

    /** Ensure a URL-safe, unique handle. */
    public static function uniqueHandle(string $base): string
    {
        $slug = preg_replace('/[^a-z0-9._]/', '', strtolower(Str::transliterate($base))) ?: 'creator';
        $slug = substr(trim($slug, '._'), 0, 40);

        if (strlen($slug) < 3) {
            $slug = 'creator' . random_int(100, 999);
        }

        $handle  = $slug;
        $counter = 1;

        while (Database::scalar('SELECT 1 FROM `creators` WHERE `handle` = :handle LIMIT 1', ['handle' => $handle]) !== null) {
            $counter++;
            $handle = substr($slug, 0, 50) . $counter;
        }

        return $handle;
    }

    /**
     * Adds what a card shows about a creator's networks: which platforms they
     * are on, and their engagement across them. One query for the whole page.
     *
     * Engagement is weighted by followers, so a 2% rate on a 500k account is
     * not averaged level with 9% on a 3k one.
     *
     * @param  list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function withCardSocials(array $items): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $items)));

        if ($ids === []) {
            return $items;
        }

        $placeholders = [];
        $bindings     = [];

        foreach ($ids as $i => $id) {
            $placeholders[]     = ':c' . $i;
            $bindings['c' . $i] = $id;
        }

        $accounts = Database::select(
            'SELECT `creator_id`, `platform`, `followers`, `engagement_rate`
             FROM `creator_social_accounts`
             WHERE `creator_id` IN (' . implode(', ', $placeholders) . ')
             ORDER BY `creator_id`, `is_primary` DESC, `sort_order`, `followers` DESC',
            $bindings
        );

        $byCreator = [];

        foreach ($accounts as $account) {
            $byCreator[(int) $account['creator_id']][] = $account;
        }

        foreach ($items as &$row) {
            $platforms = [];
            $weighted  = 0.0;
            $reach     = 0;

            foreach ($byCreator[(int) $row['id']] ?? [] as $account) {
                $platform = (string) $account['platform'];

                if (!in_array($platform, $platforms, true)) {
                    $platforms[] = $platform;
                }

                $followers = (int) $account['followers'];
                $rate      = (float) $account['engagement_rate'];

                if ($followers > 0 && $rate > 0) {
                    $weighted += $rate * $followers;
                    $reach    += $followers;
                }
            }

            $row['platforms']       = $platforms;
            $row['engagement_rate'] = $reach > 0 ? round($weighted / $reach, 2) : 0.0;
        }
        unset($row);

        return $items;
    }

    /**
     * Shape a search row for the creator card in the app.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function presentCard(array $row): array
    {
        return [
            'id'                 => (int) $row['id'],
            'user_id'            => (int) $row['user_id'],
            'handle'             => (string) $row['handle'],
            'display_name'       => (string) $row['display_name'],
            'headline'           => (string) ($row['headline'] ?? ''),
            'avatar_url'         => $row['avatar_url'] ?? null,
            'city'               => $row['city'] ?? null,
            'location'           => trim(implode(', ', array_filter([$row['city'] ?? null, $row['country'] ?? null]))),
            'category'           => $row['category_name'] ?? null,
            'category_slug'      => $row['category_slug'] ?? null,
            'rating'             => round((float) $row['rating_avg'], 1),
            'rating_count'       => (int) $row['rating_count'],
            'followers'          => (int) $row['followers_total'],
            'followers_label'    => Str::compactNumber((int) $row['followers_total']),
            'posts_count'        => (int) ($row['posts_count'] ?? 0),
            'collabs_count'      => (int) ($row['collabs_count'] ?? 0),
            'price_min'          => Money::round((float) $row['price_min']),
            'price_max'          => Money::round((float) $row['price_max']),
            'price_label'        => Money::formatRange((float) $row['price_min'], (float) $row['price_max'], (string) $row['currency']),
            'currency'           => (string) $row['currency'],
            'is_verified'        => (bool) $row['is_verified'],
            'is_featured'        => (bool) $row['is_featured'],
            'availability'       => (string) $row['availability_status'],
            'is_favorite'        => (bool) ($row['is_favorite'] ?? false),
            'is_following'       => (bool) ($row['is_following'] ?? false),
            'engagement_rate'    => (float) ($row['engagement_rate'] ?? 0),
            'platforms'          => array_values((array) ($row['platforms'] ?? [])),
        ];
    }
}

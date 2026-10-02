<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;
use Zuste\Services\ActivityLogger;
use Zuste\Services\CreatorBadgeService;
use Zuste\Services\CreatorChangeService;
use Zuste\Services\CreatorProfileLockService;
use Zuste\Services\CreatorService;
use Zuste\Services\IdentityLockService;
use Zuste\Services\ProfileCompletionService;
use Zuste\Services\TaxonomyService;
use Zuste\Services\UploadService;

/**
 * Creator discovery (public) and creator self-management (authenticated).
 *
 * Routes: /api/v1/creators/*, /api/v1/categories, /api/v1/search
 */
final class CreatorController extends BaseApiController
{
    // -----------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------

    /** GET /creators — the search / explore listing. */
    public function index(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);

        // Several facets are accepted under two names. The Flutter client sends
        // `category_slug`, `verified` and `available`; earlier API builds used
        // `category`, `verified_only` and `availability`. Both are honoured
        // because a shipped app build cannot be corrected retroactively —
        // without the aliases the filter sheet appears to work while the API
        // quietly returns the unfiltered catalogue.
        $categorySlug = $request->string('category')
            ?: $request->string('category_slug');

        $verifiedOnly = $request->bool('verified_only') || $request->bool('verified');

        $availability = $request->string('availability');

        if ($availability === '' && $request->bool('available')) {
            $availability = 'available';
        }

        $result = CreatorService::search([
            'q'              => $request->string('q'),
            'category_id'    => $request->int('category_id'),
            'category_slug'  => $categorySlug,
            'city'           => $request->string('city'),
            'platform'       => $request->string('platform'),
            'min_price'      => $request->float('min_price'),
            'max_price'      => $request->float('max_price'),
            'min_rating'     => $request->float('min_rating'),
            'min_followers'  => $request->int('min_followers'),
            'max_followers'  => $request->int('max_followers'),
            'min_engagement' => $request->float('min_engagement'),
            'language'       => $request->string('language'),
            'gender'         => $request->string('gender'),
            'response_time'  => self::responseTimeHours($request),
            'verified_only'  => $verifiedOnly,
            'availability'   => $availability,
            'sort'           => $request->string('sort', 'relevance'),
            'page'           => $pagination['page'],
            'per_page'       => $pagination['per_page'],
        ], $request->userId() ?: null);

        // Record the search so "Recent" and "Popular" stay meaningful.
        $term = trim($request->string('q'));

        if ($term !== '' && mb_strlen($term) >= 2) {
            $this->recordSearch($term, $request->userId(), $result['total']);
        }

        Response::paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page'],
            $result['total'] === 0 ? 'No creators matched your filters.' : 'OK'
        );
    }

    /**
     * The `response_time` facet as a number of hours.
     *
     * The filter sheet is driven by the `response_time` taxonomy, so it sends
     * a term (`within_6h`), while CreatorService compares against
     * `creators.response_time_hours`. Reading the term with `int()` produced 0
     * and the constraint was dropped in silence — the facet looked applied and
     * changed nothing. The hours live on the term's own metadata, so an
     * operator who adds or retimes a bucket needs no code change.
     *
     * A bare number is still accepted, which is what older API clients send.
     */
    private static function responseTimeHours(Request $request): int
    {
        $value = trim($request->string('response_time'));

        if ($value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        foreach (TaxonomyService::terms('response_time') as $term) {
            if ((string) $term['value'] !== $value) {
                continue;
            }

            $hours = $term['metadata']['hours'] ?? null;

            return is_numeric($hours) ? max(0, (int) $hours) : 0;
        }

        // An unknown term narrows nothing rather than emptying the results —
        // the same way every other unrecognised facet behaves here.
        return 0;
    }

    /** GET /creators/{handle} */
    public function show(Request $request): never
    {
        $key      = (string) $request->routeParam('handle', '');
        $viewerId = $request->userId() ?: null;

        $creator = CreatorService::profile($key, $viewerId);

        CreatorService::recordView((int) $creator['id'], $viewerId, $request->string('source', 'direct'));

        Response::success(['creator' => $creator]);
    }

    /** GET /creators/{id}/portfolio?kind=reel */
    public function portfolio(Request $request): never
    {
        $creatorId = $request->routeParamInt('id');
        $kind      = $request->string('kind', 'post');

        $result = CreatorService::portfolio(
            $creatorId,
            $kind,
            $request->page(),
            $request->perPage(24, 60)
        );

        Response::paginated($result['items'], $result['total'], $request->page(), $request->perPage(24, 60), 'OK', [
            'counts' => CreatorService::portfolioCounts($creatorId),
        ]);
    }

    /** GET /creators/{id}/reviews */
    public function reviews(Request $request): never
    {
        $creatorId  = $request->routeParamInt('id');
        $pagination = $this->pagination($request, 10, 50);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM `reviews`
             WHERE `creator_id` = :id AND `is_published` = 1 AND `deleted_at` IS NULL
               AND `direction` = 'brand_to_creator'",
            ['id' => $creatorId]
        );

        $reviews = Database::select(
            "SELECT r.`id`, r.`rating`, r.`quality_rating`, r.`communication_rating`, r.`timeliness_rating`,
                    r.`title`, r.`comment`, r.`reply`, r.`replied_at`, r.`created_at`, r.`helpful_count`,
                    u.`name` AS `reviewer_name`, u.`avatar_url` AS `reviewer_avatar`, u.`company_name`,
                    b.`campaign_type`
             FROM `reviews` r
             INNER JOIN `users` u ON u.`id` = r.`reviewer_id`
             INNER JOIN `bookings` b ON b.`id` = r.`booking_id`
             WHERE r.`creator_id` = :id AND r.`is_published` = 1 AND r.`deleted_at` IS NULL
               AND r.`direction` = 'brand_to_creator'
             ORDER BY r.`id` DESC
             LIMIT " . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            ['id' => $creatorId]
        );

        Response::paginated($reviews, $total, $pagination['page'], $pagination['per_page'], 'OK', [
            'breakdown' => CreatorService::ratingBreakdown($creatorId),
        ]);
    }

    /** GET /creators/{id}/availability?month=2026-08 */
    public function availability(Request $request): never
    {
        $creatorId = $request->routeParamInt('id');
        $month     = $request->string('month', date('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = date('Y-m');
        }

        $days = Database::select(
            'SELECT `available_on`, `is_available`, `slots`, `max_bookings`, `booked_count`
             FROM `creator_availability`
             WHERE `creator_id` = :id AND `available_on` LIKE :month
             ORDER BY `available_on`',
            ['id' => $creatorId, 'month' => $month . '-%']
        );

        foreach ($days as &$day) {
            $day['slots']     = json_decode((string) ($day['slots'] ?? '[]'), true) ?: [];
            $day['is_bookable'] = (bool) $day['is_available'] && (int) $day['booked_count'] < (int) $day['max_bookings'];
        }
        unset($day);

        Response::success(['month' => $month, 'days' => $days]);
    }

    // -----------------------------------------------------------------
    // Favourites and follows
    // -----------------------------------------------------------------

    /** POST /creators/{id}/favorite */
    public function toggleFavorite(Request $request): never
    {
        $isFavorite = CreatorService::toggleFavorite($this->userId($request), $request->routeParamInt('id'));

        Response::success(
            ['is_favorite' => $isFavorite],
            $isFavorite ? 'Saved to your creators.' : 'Removed from saved creators.'
        );
    }

    /** POST /creators/{id}/follow */
    public function toggleFollow(Request $request): never
    {
        $isFollowing = CreatorService::toggleFollow($this->userId($request), $request->routeParamInt('id'));

        Response::success(
            ['is_following' => $isFollowing],
            $isFollowing ? 'Following.' : 'Unfollowed.'
        );
    }

    /** GET /me/favorites — the Saved Creators screen. */
    public function favorites(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);
        $userId     = $this->userId($request);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `favorites` f
             INNER JOIN `creators` c ON c.`id` = f.`creator_id` AND c.`deleted_at` IS NULL
             WHERE f.`user_id` = :id',
            ['id' => $userId]
        );

        $rows = Database::select(
            'SELECT c.`id`, c.`user_id`, c.`handle`, c.`display_name`, c.`headline`, c.`city`, c.`country`,
                    c.`price_min`, c.`price_max`, c.`currency`, c.`rating_avg`, c.`rating_count`,
                    c.`followers_total`, c.`posts_count`, c.`collabs_count`, c.`is_verified`, c.`is_featured`,
                    c.`availability_status`, c.`primary_category_id`,
                    u.`avatar_url`, u.`name` AS `account_name`,
                    cat.`name` AS `category_name`, cat.`slug` AS `category_slug`,
                    1 AS `is_favorite`,
                    EXISTS(SELECT 1 FROM `follows` fo WHERE fo.`creator_id` = c.`id` AND fo.`follower_id` = :id) AS `is_following`
             FROM `favorites` f
             INNER JOIN `creators` c ON c.`id` = f.`creator_id` AND c.`deleted_at` IS NULL
             INNER JOIN `users` u ON u.`id` = c.`user_id`
             LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`
             WHERE f.`user_id` = :id
             ORDER BY f.`created_at` DESC
             LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            ['id' => $userId]
        );

        Response::paginated(
            array_map([CreatorService::class, 'presentCard'], $rows),
            $total,
            $pagination['page'],
            $pagination['per_page']
        );
    }

    // -----------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------

    /** GET /categories */
    public function categories(Request $request): never
    {
        $featuredOnly = $request->bool('featured');

        // creator_count is computed here rather than read from the
        // denormalised column: nothing schedules a refresh of that column, so
        // it sat at 0 forever and every category rendered "0+ Creators".
        // COALESCE keeps a category with no creators at 0 rather than null.
        $categories = Database::select(
            'SELECT cat.`id`, cat.`parent_id`, cat.`name`, cat.`slug`, cat.`description`,
                    cat.`icon`, cat.`icon_url`, cat.`cover_image`, cat.`color_hex`,
                    cat.`is_featured`,
                    COALESCE(live.`n`, 0) AS `creator_count`
             FROM `categories` cat
             LEFT JOIN (
                 SELECT cc.`category_id`, COUNT(DISTINCT cc.`creator_id`) AS `n`
                   FROM `creator_categories` cc
                   INNER JOIN `creators` c
                           ON c.`id` = cc.`creator_id`
                          AND c.`status` = "approved"
                          AND c.`deleted_at` IS NULL
                  GROUP BY cc.`category_id`
             ) live ON live.`category_id` = cat.`id`
             WHERE cat.`is_active` = 1 AND cat.`deleted_at` IS NULL'
             . ($featuredOnly ? ' AND cat.`is_featured` = 1' : '') . '
             ORDER BY cat.`sort_order`, cat.`name`'
        );

        foreach ($categories as &$category) {
            $category['id']            = (int) $category['id'];
            $category['creator_count'] = (int) $category['creator_count'];
            $category['creator_label'] = $category['creator_count'] === 0
                ? 'No creators yet'
                : Str::compactNumber((int) $category['creator_count']) . '+ Creators';
        }
        unset($category);

        Response::success(['categories' => $categories]);
    }

    // -----------------------------------------------------------------
    // Search support
    // -----------------------------------------------------------------

    /**
     * GET /search/suggestions
     *
     * Serves both callers: the empty search landing (recent + popular +
     * suggested creators) and the type-ahead, which passes `q` and wants a
     * plain list of completions. The typed term used to be ignored entirely,
     * and the flat `suggestions` array the app reads was never sent — so the
     * autocomplete was empty on every keystroke.
     */
    public function searchSuggestions(Request $request): never
    {
        $userId = $request->userId();
        $query  = trim($request->string('q'));

        $recent = $userId > 0
            ? Database::column(
                'SELECT DISTINCT `term` FROM `search_histories`
                 WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 8',
                ['id' => $userId]
            )
            : [];

        $popular = Database::column(
            'SELECT `term` FROM `popular_searches`
             WHERE `is_active` = 1
             ORDER BY `is_curated` DESC, `hits` DESC
             LIMIT 8'
        );

        $recent  = array_values(array_map('strval', $recent));
        $popular = array_values(array_map('strval', $popular));

        if ($query !== '') {
            $matches = static fn (string $term): bool => stripos($term, $query) !== false;
            $recent  = array_values(array_filter($recent, $matches));
            $popular = array_values(array_filter($popular, $matches));
        }

        $suggested = CreatorService::search(
            ['q' => $query, 'sort' => $query === '' ? 'rating' : 'relevance', 'per_page' => 6],
            $userId ?: null
        );

        Response::success([
            'recent_searches'    => $recent,
            'popular_searches'   => $popular,
            'suggested_creators' => $suggested['items'],
            // Flat completions for the type-ahead, in the order the user is
            // most likely to want them: their own history, then creators, then
            // what everyone else searches for.
            'suggestions' => self::mergeSuggestions(
                $recent,
                array_map(
                    static fn (array $creator): string => (string) ($creator['display_name'] ?? ''),
                    $suggested['items']
                ),
                $popular
            ),
        ]);
    }

    /**
     * Case-insensitive de-duplicated merge, capped so the type-ahead list stays
     * usable on a phone.
     *
     * @param  list<string> ...$lists
     * @return list<string>
     */
    private static function mergeSuggestions(array ...$lists): array
    {
        $seen = [];

        foreach ($lists as $list) {
            foreach ($list as $term) {
                $term = trim($term);

                if ($term === '' || count($seen) >= 12) {
                    continue;
                }

                $seen[mb_strtolower($term)] ??= $term;
            }
        }

        return array_values($seen);
    }

    /** DELETE /search/history */
    public function clearSearchHistory(Request $request): never
    {
        $term = $request->string('term');

        if ($term !== '') {
            Database::execute(
                'DELETE FROM `search_histories` WHERE `user_id` = :id AND `term` = :term',
                ['id' => $this->userId($request), 'term' => $term]
            );
        } else {
            Database::execute('DELETE FROM `search_histories` WHERE `user_id` = :id', ['id' => $this->userId($request)]);
        }

        Response::noContent('Search history cleared.');
    }

    // -----------------------------------------------------------------
    // Creator self-management
    // -----------------------------------------------------------------

    /** GET /me/creator — the signed-in creator's own profile. */
    public function myProfile(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $creator = CreatorService::profile($creatorId, $this->userId($request));
        $creator['profile_score'] = CreatorService::computeProfileScore($creatorId);

        // Which sections an administrator has frozen, so the apps can render
        // them read-only rather than letting the creator fill in a form the
        // API will then refuse.
        $creator['locked_sections'] = CreatorProfileLockService::lockedSections($creatorId);

        Response::success([
            'creator' => $creator,
            // The full catalogue travels with the creator's own profile so the
            // apps can show what is not yet earned as well as what is, without
            // shipping a hardcoded badge list of their own.
            'badges'  => CreatorBadgeService::adminOverview($creatorId),
        ]);
    }

    /**
     * GET /me/creator/completion
     *
     * The creator's own profile-completion breakdown and what it unlocks.
     *
     * The admin panel has had this since the completion engine shipped; the
     * creator — the only person who can act on it — had no way to see it. A
     * percentage with no list of what is missing is a scold rather than a
     * prompt, so the section breakdown and the gates travel together.
     */
    public function completion(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        Response::success([
            'completion' => ProfileCompletionService::breakdown($creatorId),
            // What each percentage actually buys. Sent alongside so the app can
            // say "reach 80% to apply for campaigns" instead of showing a bar
            // with nothing attached to it.
            'gates'      => ProfileCompletionService::gatesFor($creatorId),
        ]);
    }

    /** PUT /me/creator */
    /**
     * Record a creator's own change to their profile.
     *
     * Every write method below routes through this. They previously wrote
     * nothing at all: CreatorController imported ActivityLogger and never
     * called it across all eight write paths, so a creator could rename
     * themselves, rewrite their bio, swap every social handle, change all
     * their pricing and delete portfolio items leaving no record anywhere.
     * The admin side logged its own moderation actions, which made the gap
     * easy to miss — the log looked populated.
     *
     * `properties` carries the changed field names rather than their values:
     * enough to answer "what did they touch and when", without copying a bio
     * or a bank-adjacent field into a second table.
     *
     * @param list<string>|array<string,mixed> $properties
     */
    private function logCreatorChange(
        Request $request,
        string $action,
        int $subjectId,
        string $description,
        array $properties = []
    ): void {
        $user = $request->user();

        if (!is_array($user)) {
            return;
        }

        ActivityLogger::user($user, $action, 'creator', $subjectId, $description, $properties);
    }

    public function updateProfile(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, [
            'display_name'        => 'nullable|string|min:2|max:120',
            'handle'              => 'nullable|handle|max:60',
            'headline'            => 'nullable|string|max:150',
            'about'               => 'nullable|string|max:5000',
            'primary_category_id' => 'nullable|integer|exists:categories,id',
            'city'                => 'nullable|string|max:100',
            'state'               => 'nullable|string|max:100',
            'country'             => 'nullable|string|max:100',
            'gender'              => 'nullable|in:male,female,non_binary,prefer_not_to_say',
            'date_of_birth'       => 'nullable|date',
            'languages'           => 'nullable|array',
            'availability_status' => 'nullable|in:available,busy,unavailable',
            'cover_image_url'     => 'nullable|url|max:500',
            'category_ids'        => 'nullable|array',
        ]);

        $existing = Database::selectOne('SELECT * FROM `creators` WHERE `id` = :id', ['id' => $creatorId]);

        if ($existing === null) {
            throw HttpException::notFound('Creator profile not found.');
        }

        // An administrator can freeze one section of a profile without freezing
        // the whole account. Checked per field and only where the submitted
        // value actually differs, because the apps PUT the entire form: a
        // creator saving an unrelated change should not be refused for a
        // locked field they never touched.
        $lockedBy = [
            'display_name'        => 'display_name',
            'handle'              => 'username',
            'headline'            => 'headline',
            'about'               => 'bio',
            'primary_category_id' => 'categories',
            'category_ids'        => 'categories',
            'city'                => 'location',
            'state'               => 'location',
            'country'             => 'location',
            'cover_image_url'     => 'photos',
            'availability_status' => 'availability',
        ];

        foreach ($lockedBy as $field => $section) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            // category_ids is a set, not a scalar, so it has no single column
            // to compare against; any submission of it counts as a change.
            $changed = $field === 'category_ids'
                || (string) $data[$field] !== (string) ($existing[$field] ?? '');

            if ($changed) {
                CreatorProfileLockService::assertUnlocked($creatorId, $section);
            }
        }

        // While the blue tick is showing, the name it is drawn next to is
        // frozen (IdentityLockService). Checked before the handle-collision
        // test so a ticked creator is told why, not that a handle is taken.
        // Nulls are skipped exactly as the update below skips them.
        IdentityLockService::assertNameUnchanged(
            (int) $existing['user_id'],
            array_filter(
                array_intersect_key($data, array_flip(['display_name', 'handle'])),
                static fn ($value): bool => $value !== null
            ),
            $existing
        );

        // A handle change must not collide with another creator.
        if (!empty($data['handle']) && $data['handle'] !== $existing['handle']) {
            $taken = Database::scalar(
                'SELECT 1 FROM `creators` WHERE `handle` = :handle AND `id` <> :id LIMIT 1',
                ['handle' => $data['handle'], 'id' => $creatorId]
            );

            if ($taken !== null) {
                throw HttpException::unprocessable('That handle is already taken.', 'handle_taken');
            }
        }

        $updates = array_filter([
            'display_name'        => $data['display_name'] ?? null,
            'handle'              => $data['handle'] ?? null,
            'headline'            => $data['headline'] ?? null,
            'about'               => $data['about'] ?? null,
            'primary_category_id' => isset($data['primary_category_id']) ? (int) $data['primary_category_id'] : null,
            'city'                => $data['city'] ?? null,
            'state'               => $data['state'] ?? null,
            'country'             => $data['country'] ?? null,
            'gender'              => $data['gender'] ?? null,
            'date_of_birth'       => $data['date_of_birth'] ?? null,
            'availability_status' => $data['availability_status'] ?? null,
            'cover_image_url'     => $data['cover_image_url'] ?? null,
        ], static fn ($value): bool => $value !== null);

        if (isset($data['languages']) && is_array($data['languages'])) {
            $updates['languages'] = json_encode(array_values(array_map('strval', $data['languages'])), JSON_UNESCAPED_UNICODE);
        }

        // A draft always goes for review once it is edited. A LIVE profile goes
        // back only when a reviewed field changes, and only while the operator
        // has that switched on.
        //
        // This used to read:
        //
        //     $needsReview = $existing['status'] === 'draft' || <field changed>;
        //     if ($needsReview && $existing['status'] === 'draft') { … }
        //
        // — the `||` computed exactly the live-profile case the comment above
        // it described, and the next line `&&`-ed it away. `$needsReview`
        // collapsed to "is a draft" and the field test was dead code, so an
        // APPROVED creator could rewrite their display name, headline and bio
        // and stay approved with no administrator ever seeing it.
        //
        // Which fields count, and whether the rule applies at all, are settings
        // rather than literals: an operator running a closed marketplace wants
        // every edit reviewed, and one running an open one wants none.
        $reviewed = array_values(array_filter(array_map(
            'trim',
            explode(',', Config::settingString('creator', 'reviewed_sections', 'display_name,about,headline'))
        )));

        $touchedReviewed = $reviewed !== [] && array_intersect_key($updates, array_flip($reviewed)) !== [];

        $needsReview = $existing['status'] === 'draft'
            || ($touchedReviewed && Config::settingBool('creator', 'approve_profile_edits', true));

        if ($needsReview) {
            $updates['status'] = 'pending_review';
        }

        // Hold reviewable edits instead of publishing them, when the operator
        // has asked for that.
        //
        // The rule above sends the whole PROFILE back for review but writes the
        // new value straight into `creators` first — so the edit is already
        // live while it waits, and the old value is gone, which makes
        // "approve this change" a decision taken without ever seeing what the
        // change was. screenUpdates() splits the update: fields whose section
        // is marked requires_review are parked in creator_change_requests with
        // their before and after side by side, and the rest are applied now.
        //
        // Gated on creator.hold_changes_for_review, which seeds to 0, so this
        // is inert until an operator turns it on and today's behaviour is
        // unchanged.
        $screened = CreatorChangeService::screenUpdates($creatorId, $updates, $existing, [
            'actor_type' => 'creator',
            'actor_id'   => $this->userId($request),
            'ip'         => $request->ip(),
        ]);

        $updates = $screened['apply'];
        $held    = $screened['held'];

        Database::transaction(static function () use ($creatorId, $updates, $data): void {
            if ($updates !== []) {
                Database::update('creators', $updates, ['id' => $creatorId]);
            }

            if (isset($data['category_ids']) && is_array($data['category_ids'])) {
                Database::delete('creator_categories', ['creator_id' => $creatorId]);

                foreach (array_slice(array_unique(array_map('intval', $data['category_ids'])), 0, 5) as $categoryId) {
                    if ($categoryId > 0) {
                        Database::insertIgnore('creator_categories', [
                            'creator_id'  => $creatorId,
                            'category_id' => $categoryId,
                        ]);
                    }
                }
            }
        });

        $this->logCreatorChange(
            $request,
            $needsReview ? 'creator.profile_submitted' : 'creator.profile_updated',
            $creatorId,
            $needsReview
                ? 'Profile edited and sent for review.'
                : 'Profile updated.',
            array_keys($updates)
        );

        CreatorService::computeProfileScore($creatorId);

        // A held field is not an error, but the creator must be told — without
        // this the app would redraw the old value with a "Profile updated"
        // message and look like it had silently discarded their edit.
        Response::success(
            [
                'creator'       => CreatorService::profile($creatorId, $this->userId($request)),
                'held_fields'   => array_keys($held),
                'change_request_id' => $screened['request_id'],
            ],
            $screened['message']
                ?? ($needsReview && $existing['status'] === 'draft'
                    ? 'Profile submitted for review. We usually respond within two business days.'
                    : 'Profile updated.')
        );
    }

    /** POST /me/creator/packages */
    public function createPackage(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        CreatorProfileLockService::assertUnlocked($creatorId, 'pricing');

        $data = $this->validate($request, [
            'title'            => 'required|string|min:3|max:150',
            'description'      => 'nullable|string|max:1000',
            'platform'         => $this->taxonomyRule('platform', 'required', 'required|string|max:40'),
            'deliverable_type' => $this->taxonomyRule('deliverable_type', 'required', 'required|string|max:40'),
            'quantity'         => 'nullable|integer|min_value:1|max_value:100',
            'price'            => 'required|numeric|min_value:1',
            'turnaround_days'  => 'nullable|integer|min_value:1|max_value:180',
            'revisions'        => 'nullable|integer|min_value:0|max_value:10',
            'includes'         => 'nullable|array',
        ]);

        $packageId = Database::insert('creator_packages', [
            'creator_id'       => $creatorId,
            'title'            => (string) $data['title'],
            'description'      => $data['description'] ?? null,
            'platform'         => (string) $data['platform'],
            'deliverable_type' => (string) $data['deliverable_type'],
            'quantity'         => (int) ($data['quantity'] ?? 1),
            'price'            => Money::round((float) $data['price']),
            'currency'         => Money::defaultCurrency(),
            'turnaround_days'  => (int) ($data['turnaround_days'] ?? 7),
            'revisions'        => (int) ($data['revisions'] ?? 1),
            'includes'         => isset($data['includes']) ? json_encode(array_values((array) $data['includes']), JSON_UNESCAPED_UNICODE) : null,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        CreatorService::refreshStatistics($creatorId);
        CreatorService::computeProfileScore($creatorId);

        $this->logCreatorChange($request, 'creator.package_created', $creatorId,
            'Created pricing package #' . $packageId . '.', ['package_id' => $packageId]);

        Response::created(
            ['package' => Database::selectOne('SELECT * FROM `creator_packages` WHERE `id` = :id', ['id' => $packageId])],
            'Package created.'
        );
    }

    /** PUT /me/creator/packages/{id} */
    public function updatePackage(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $packageId = $request->routeParamInt('id');

        CreatorProfileLockService::assertUnlocked($creatorId, 'pricing');

        $this->assertOwnsPackage($creatorId, $packageId);

        $data = $this->validate($request, [
            'title'            => 'nullable|string|min:3|max:150',
            'description'      => 'nullable|string|max:1000',
            'platform'         => $this->taxonomyRule('platform', 'nullable', 'nullable|string|max:40'),
            'deliverable_type' => $this->taxonomyRule('deliverable_type', 'nullable', 'nullable|string|max:40'),
            'quantity'         => 'nullable|integer|min_value:1|max_value:100',
            'price'            => 'nullable|numeric|min_value:1',
            'turnaround_days'  => 'nullable|integer|min_value:1|max_value:180',
            'revisions'        => 'nullable|integer|min_value:0|max_value:10',
            'is_active'        => 'nullable|boolean',
            'includes'         => 'nullable|array',
        ]);

        $updates = array_filter([
            'title'            => $data['title'] ?? null,
            'description'      => $data['description'] ?? null,
            'platform'         => $data['platform'] ?? null,
            'deliverable_type' => $data['deliverable_type'] ?? null,
            'quantity'         => isset($data['quantity']) ? (int) $data['quantity'] : null,
            'price'            => isset($data['price']) ? Money::round((float) $data['price']) : null,
            'turnaround_days'  => isset($data['turnaround_days']) ? (int) $data['turnaround_days'] : null,
            'revisions'        => isset($data['revisions']) ? (int) $data['revisions'] : null,
        ], static fn ($value): bool => $value !== null);

        if (array_key_exists('is_active', $data)) {
            $updates['is_active'] = $data['is_active'] ? 1 : 0;
        }

        if (isset($data['includes'])) {
            $updates['includes'] = json_encode(array_values((array) $data['includes']), JSON_UNESCAPED_UNICODE);
        }

        if ($updates !== []) {
            Database::update('creator_packages', $updates, ['id' => $packageId]);
            CreatorService::refreshStatistics($creatorId);
        }

        $this->logCreatorChange($request, 'creator.package_updated', $creatorId,
            'Updated pricing package #' . $packageId . '.', ['package_id' => $packageId]);

        Response::success(
            ['package' => Database::selectOne('SELECT * FROM `creator_packages` WHERE `id` = :id', ['id' => $packageId])],
            'Package updated.'
        );
    }

    /** DELETE /me/creator/packages/{id} */
    public function deletePackage(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $packageId = $request->routeParamInt('id');

        CreatorProfileLockService::assertUnlocked($creatorId, 'pricing');

        $this->assertOwnsPackage($creatorId, $packageId);

        Database::update('creator_packages', ['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0], ['id' => $packageId]);
        CreatorService::refreshStatistics($creatorId);

        $this->logCreatorChange($request, 'creator.package_deleted', $creatorId,
            'Removed pricing package #' . $packageId . '.', ['package_id' => $packageId]);

        Response::noContent('Package removed.');
    }

    /** POST /me/creator/portfolio */
    public function addPortfolioItem(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        CreatorProfileLockService::assertUnlocked($creatorId, 'portfolio');

        $data = $this->validate($request, [
            'media_kind'    => $this->taxonomyRule('portfolio_kind', 'required', 'required|string|max:40'),
            'title'         => 'nullable|string|max:191',
            'caption'       => 'nullable|string|max:1000',
            'external_url'  => 'nullable|url|max:500',
            'aspect_ratio'  => 'nullable|string|max:10',
            'duration_secs' => 'nullable|integer|min_value:0',
        ]);

        $mediaUrl     = $request->string('media_url');
        $thumbnailUrl = $request->string('thumbnail_url') ?: null;

        // Accept either a direct upload or a pre-uploaded URL.
        if ($request->hasFile('media')) {
            $kind   = in_array($data['media_kind'], ['reel', 'video'], true) ? 'video' : 'image';
            $upload = UploadService::store(
                (array) $request->file('media'),
                'portfolio',
                $kind,
                'user',
                $this->userId($request)
            );

            $mediaUrl     = $upload['url'];
            $thumbnailUrl ??= $upload['url'];
        }

        if ($mediaUrl === '') {
            throw HttpException::unprocessable('Upload a file or provide a media URL.');
        }

        $itemId = Database::insert('creator_portfolio', [
            'creator_id'      => $creatorId,
            'media_kind'      => (string) $data['media_kind'],
            'title'           => $data['title'] ?? null,
            'caption'         => $data['caption'] ?? null,
            'media_url'       => $mediaUrl,
            'thumbnail_url'   => $thumbnailUrl,
            'external_url'    => $data['external_url'] ?? null,
            'source_platform' => !empty($data['external_url']) ? $this->detectPlatform((string) $data['external_url']) : 'upload',
            'aspect_ratio'    => $data['aspect_ratio'] ?? null,
            'duration_secs'   => isset($data['duration_secs']) ? (int) $data['duration_secs'] : null,
            // 'pending' when the operator has creator.moderate_gallery_uploads
            // on, 'approved' when they do not. That switch has been ON in the
            // live settings the whole time with nowhere to record its outcome,
            // so every upload published itself regardless.
            'moderation_status' => CreatorChangeService::initialModerationStatus(),
            'published_at'    => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        CreatorService::refreshStatistics($creatorId);
        CreatorService::computeProfileScore($creatorId);

        $this->logCreatorChange($request, 'creator.portfolio_added', $creatorId,
            'Added portfolio item #' . $itemId . '.', ['item_id' => $itemId]);

        Response::created(
            ['item' => Database::selectOne('SELECT * FROM `creator_portfolio` WHERE `id` = :id', ['id' => $itemId])],
            'Added to your portfolio.'
        );
    }

    /** DELETE /me/creator/portfolio/{id} */
    public function deletePortfolioItem(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $itemId    = $request->routeParamInt('id');

        CreatorProfileLockService::assertUnlocked($creatorId, 'portfolio');

        $owned = Database::scalar(
            'SELECT 1 FROM `creator_portfolio` WHERE `id` = :id AND `creator_id` = :creator_id',
            ['id' => $itemId, 'creator_id' => $creatorId]
        );

        if ($owned === null) {
            throw HttpException::notFound('Portfolio item not found.');
        }

        Database::update('creator_portfolio', ['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0], ['id' => $itemId]);
        CreatorService::refreshStatistics($creatorId);

        $this->logCreatorChange($request, 'creator.portfolio_removed', $creatorId,
            'Removed portfolio item #' . $itemId . '.', ['item_id' => $itemId]);

        Response::noContent('Removed from your portfolio.');
    }

    /** PUT /me/creator/social */
    public function upsertSocialAccount(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        CreatorProfileLockService::assertUnlocked($creatorId, 'social');

        $data = $this->validate($request, [
            'id'              => 'nullable|integer',
            // Deliberately not an `in:` list. It used to name nine networks,
            // so the four the product needs in this market — Moj, Josh,
            // ShareChat — and every link-only entry were refused at the door
            // even once the column could hold them. The allowlist now comes
            // from the `social_platform` taxonomy, so an operator adding a
            // network makes it postable with no release.
            'platform'        => 'required|string|max:40',
            'handle'          => 'required|string|max:120',
            'profile_url'     => 'required|url|max:500',
            'label'           => 'nullable|string|max:80',
            'followers'       => 'nullable|integer|min_value:0',
            'engagement_rate' => 'nullable|numeric|min_value:0|max_value:100',
        ]);

        $platform = strtolower(trim((string) $data['platform']));

        if (!TaxonomyService::isValid('social_platform', $platform)) {
            throw HttpException::unprocessable('That is not a network we support.');
        }

        $handle = ltrim(trim((string) $data['handle']), '@');

        if ($handle === '') {
            throw HttpException::unprocessable('Enter the handle or the link.');
        }

        // A network that reports follower counts holds one account per
        // creator; a link-only entry — website, blog, a custom link — may
        // repeat. The taxonomy term says which, so the rule is data.
        $term      = self::socialTerm($platform);
        $metadata  = is_array($term['metadata'] ?? null) ? $term['metadata'] : [];
        $singleton = ($metadata['metrics'] ?? false) === true;

        $existingId = (int) ($data['id'] ?? 0);

        if ($existingId > 0) {
            $owns = Database::scalar(
                'SELECT 1 FROM `creator_social_accounts` WHERE `id` = :id AND `creator_id` = :creator_id',
                ['id' => $existingId, 'creator_id' => $creatorId]
            );

            if ($owns === null) {
                throw HttpException::notFound('That linked account was not found.');
            }
        } elseif ($singleton) {
            // The row to write is now chosen explicitly rather than left to
            // the index. The old blind ON DUPLICATE KEY UPDATE relied on a
            // unique key of (creator_id, platform); that key is now
            // (creator_id, platform, handle), because a creator may hold
            // several custom links — so changing an Instagram handle would
            // have inserted a SECOND Instagram row instead of updating the
            // first.
            $existingId = (int) (Database::scalar(
                'SELECT `id` FROM `creator_social_accounts` WHERE `creator_id` = :c AND `platform` = :p LIMIT 1',
                ['c' => $creatorId, 'p' => $platform]
            ) ?? 0);
        }

        $limit = Config::settingInt('creator', 'max_social_accounts', 20);

        if ($existingId === 0 && $limit > 0) {
            $count = (int) (Database::scalar(
                'SELECT COUNT(*) FROM `creator_social_accounts` WHERE `creator_id` = :c',
                ['c' => $creatorId]
            ) ?? 0);

            if ($count >= $limit) {
                throw HttpException::unprocessable("You can link up to {$limit} accounts.");
            }
        }

        $fields = [
            'handle'          => $handle,
            'profile_url'     => (string) $data['profile_url'],
            'label'           => $data['label'] ?? null,
            'followers'       => (int) ($data['followers'] ?? 0),
            'engagement_rate' => (float) ($data['engagement_rate'] ?? 0),
            'last_synced_at'  => date('Y-m-d H:i:s'),
        ];

        if ($existingId > 0) {
            Database::update('creator_social_accounts', $fields, ['id' => $existingId]);
        } else {
            // New rows sort last; the creator reorders from there.
            $next = (int) (Database::scalar(
                'SELECT COALESCE(MAX(`sort_order`), 0) + 1 FROM `creator_social_accounts` WHERE `creator_id` = :c',
                ['c' => $creatorId]
            ) ?? 1);

            $existingId = Database::insert('creator_social_accounts', $fields + [
                'creator_id' => $creatorId,
                'platform'   => $platform,
                'sort_order' => $next,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        CreatorService::refreshStatistics($creatorId);
        CreatorService::computeProfileScore($creatorId);

        $this->logCreatorChange($request, 'creator.social_updated', $creatorId,
            'Updated the ' . $platform . ' account link.',
            ['platform' => $platform, 'account_id' => $existingId]);

        Response::success(
            ['social_accounts' => $this->socialAccounts($creatorId)],
            'Social account saved.'
        );
    }

    /**
     * DELETE /me/creator/social/{id} — unlink an account.
     *
     * The spec asks for add, edit, remove, verify and reorder. Only add and
     * edit existed, so a creator who linked the wrong account could never take
     * it down.
     */
    public function deleteSocialAccount(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $accountId = $request->routeParamInt('id');

        CreatorProfileLockService::assertUnlocked($creatorId, 'social');

        $owns = Database::scalar(
            'SELECT 1 FROM `creator_social_accounts` WHERE `id` = :id AND `creator_id` = :creator_id',
            ['id' => $accountId, 'creator_id' => $creatorId]
        );

        if ($owns === null) {
            throw HttpException::notFound('That linked account was not found.');
        }

        Database::delete('creator_social_accounts', ['id' => $accountId]);

        CreatorService::refreshStatistics($creatorId);
        CreatorService::computeProfileScore($creatorId);

        $this->logCreatorChange($request, 'creator.social_removed', $creatorId,
            'Unlinked a social account.', ['account_id' => $accountId]);

        Response::success(
            ['social_accounts' => $this->socialAccounts($creatorId)],
            'Account unlinked.'
        );
    }

    /**
     * PUT /me/creator/social/order — set the display order.
     *
     * Takes the ids in the order they should appear. An id that is not the
     * caller's is skipped rather than refused, so a stale client cannot lock
     * the creator out of reordering everything else.
     */
    public function reorderSocialAccounts(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        CreatorProfileLockService::assertUnlocked($creatorId, 'social');

        $ids = array_values(array_filter(
            array_map('intval', $request->array('ids')),
            static fn (int $id): bool => $id > 0
        ));

        if ($ids === []) {
            throw HttpException::unprocessable('Send the account ids in the order you want them.');
        }

        Database::transaction(static function () use ($creatorId, $ids): void {
            foreach ($ids as $position => $id) {
                Database::execute(
                    'UPDATE `creator_social_accounts` SET `sort_order` = :sort
                      WHERE `id` = :id AND `creator_id` = :creator_id',
                    ['sort' => $position + 1, 'id' => $id, 'creator_id' => $creatorId]
                );
            }
        });

        $this->logCreatorChange($request, 'creator.social_reordered', $creatorId,
            'Reordered linked accounts.', ['count' => count($ids)]);

        Response::success(
            ['social_accounts' => $this->socialAccounts($creatorId)],
            'Order saved.'
        );
    }

    /**
     * The `social_platform` terms keyed by value.
     *
     * TaxonomyService::map() returns value => LABEL, not value => term, so
     * indexing it for ['metadata'] yields null against a string and every
     * network reads as link-only. terms() carries the whole row.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function socialTerms(): array
    {
        $keyed = [];

        foreach (TaxonomyService::terms('social_platform') as $term) {
            $keyed[(string) $term['value']] = $term;
        }

        return $keyed;
    }

    /** @return array<string,mixed> */
    private static function socialTerm(string $platform): array
    {
        return self::socialTerms()[$platform] ?? [];
    }

    /**
     * The caller's linked accounts, decorated from the taxonomy.
     *
     * Label, icon, brand colour and whether the network reports follower
     * figures all come from the term, so no client needs to know anything
     * about any specific network — which is exactly what lets an operator add
     * one without a release.
     *
     * @return list<array<string,mixed>>
     */
    private function socialAccounts(int $creatorId): array
    {
        $terms = self::socialTerms();

        $rows = Database::select(
            'SELECT * FROM `creator_social_accounts`
              WHERE `creator_id` = :id
              ORDER BY `sort_order`, `id`',
            ['id' => $creatorId]
        );

        foreach ($rows as &$row) {
            $term     = $terms[(string) $row['platform']] ?? [];
            $metadata = is_array($term['metadata'] ?? null) ? $term['metadata'] : [];

            // A custom link shows the creator's own label; everything else
            // shows the network's name.
            $row['display_name'] = (string) ($row['label'] ?: ($term['label'] ?? ucfirst((string) $row['platform'])));
            $row['icon']         = $term['icon'] ?? 'link';
            $row['color']        = $term['color'] ?? null;
            $row['has_metrics']  = ($metadata['metrics'] ?? false) === true;
            $row['is_custom']    = ($metadata['custom_label'] ?? false) === true;
        }
        unset($row);

        return $rows;
    }


    /** PUT /me/creator/availability */
    public function setAvailability(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        CreatorProfileLockService::assertUnlocked($creatorId, 'availability');

        $data = $this->validate($request, [
            'dates'        => 'required|array',
            'is_available' => 'required|boolean',
            'max_bookings' => 'nullable|integer|min_value:1|max_value:10',
        ]);

        $dates = array_slice(array_values((array) $data['dates']), 0, 90);

        Database::transaction(static function () use ($creatorId, $dates, $data): void {
            foreach ($dates as $date) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) !== 1) {
                    continue;
                }

                Database::execute(
                    'INSERT INTO `creator_availability`
                        (`creator_id`, `available_on`, `is_available`, `max_bookings`, `created_at`)
                     VALUES (:creator_id, :date, :is_available, :max_bookings, NOW())
                     ON DUPLICATE KEY UPDATE
                        `is_available` = VALUES(`is_available`),
                        `max_bookings` = VALUES(`max_bookings`)',
                    [
                        'creator_id'   => $creatorId,
                        'date'         => (string) $date,
                        'is_available' => $data['is_available'] ? 1 : 0,
                        'max_bookings' => (int) ($data['max_bookings'] ?? 1),
                    ]
                );
            }
        });

        $this->logCreatorChange($request, 'creator.availability_updated', $creatorId,
            'Updated the availability calendar.');

        Response::success(null, 'Availability updated.');
    }

    /** GET /me/creator/analytics */
    public function analytics(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $days      = max(7, min(365, $request->int('days', 30)));

        $summary = Database::selectOne(
            'SELECT
                COALESCE(SUM(`profile_views`), 0)      AS `profile_views`,
                COALESCE(SUM(`booking_requests`), 0)   AS `booking_requests`,
                COALESCE(SUM(`bookings_completed`), 0) AS `bookings_completed`,
                COALESCE(SUM(`earnings`), 0)           AS `earnings`,
                COALESCE(SUM(`new_followers`), 0)      AS `new_followers`
             FROM `creator_analytics_daily`
             WHERE `creator_id` = :id AND `stat_date` >= DATE_SUB(CURDATE(), INTERVAL :days DAY)',
            ['id' => $creatorId, 'days' => $days]
        ) ?? [];

        $series = Database::select(
            'SELECT `stat_date`, `profile_views`, `booking_requests`, `bookings_completed`, `earnings`
             FROM `creator_analytics_daily`
             WHERE `creator_id` = :id AND `stat_date` >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             ORDER BY `stat_date`',
            ['id' => $creatorId, 'days' => $days]
        );

        $creator = Database::selectOne(
            'SELECT `rating_avg`, `rating_count`, `collabs_count`, `completion_rate`,
                    `followers_total`, `platform_followers`, `profile_score`
             FROM `creators` WHERE `id` = :id',
            ['id' => $creatorId]
        ) ?? [];

        $lifetimeEarnings = (float) Database::scalar(
            "SELECT COALESCE(SUM(`creator_earning`), 0) FROM `bookings`
             WHERE `creator_id` = :id AND `status` = 'completed'",
            ['id' => $creatorId]
        );

        Response::success([
            'range_days' => $days,
            'summary'    => [
                'profile_views'      => (int) ($summary['profile_views'] ?? 0),
                'booking_requests'   => (int) ($summary['booking_requests'] ?? 0),
                'bookings_completed' => (int) ($summary['bookings_completed'] ?? 0),
                'earnings'           => Money::round((float) ($summary['earnings'] ?? 0)),
                'earnings_label'     => Money::compact((float) ($summary['earnings'] ?? 0)),
                'new_followers'      => (int) ($summary['new_followers'] ?? 0),
                'lifetime_earnings'  => Money::round($lifetimeEarnings),
            ],
            'profile' => [
                'rating'          => round((float) ($creator['rating_avg'] ?? 0), 1),
                'rating_count'    => (int) ($creator['rating_count'] ?? 0),
                'collabs'         => (int) ($creator['collabs_count'] ?? 0),
                'completion_rate' => (float) ($creator['completion_rate'] ?? 0),
                'followers'       => (int) ($creator['followers_total'] ?? 0),
                'profile_score'   => (int) ($creator['profile_score'] ?? 0),
            ],
            'series' => $series,
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Build an `in:` validation rule from an admin-managed taxonomy.
     *
     * The platform, deliverable-type and portfolio-kind rules used to name
     * their options inline. That made the taxonomy decorative: an operator
     * could add a term, see it in every picker, and then watch the API reject
     * it — and migration 035's six new portfolio kinds were unusable on the
     * day they shipped for exactly that reason.
     *
     * Falls back to the supplied rule if the taxonomy is empty, so emptying a
     * list in the admin panel cannot accidentally make a field accept
     * anything at all.
     */
    private function taxonomyRule(string $taxonomy, string $prefix, string $fallback): string
    {
        $values = array_column(TaxonomyService::terms($taxonomy), 'value');

        return $values === []
            ? $fallback
            : $prefix . '|in:' . implode(',', $values);
    }

    private function assertOwnsPackage(int $creatorId, int $packageId): void
    {
        $owned = Database::scalar(
            'SELECT 1 FROM `creator_packages` WHERE `id` = :id AND `creator_id` = :creator_id AND `deleted_at` IS NULL',
            ['id' => $packageId, 'creator_id' => $creatorId]
        );

        if ($owned === null) {
            throw HttpException::notFound('Package not found.');
        }
    }

    private function detectPlatform(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            str_contains($host, 'instagram') => 'instagram',
            str_contains($host, 'youtu')     => 'youtube',
            str_contains($host, 'tiktok')    => 'tiktok',
            default                          => 'other',
        };
    }

    private function recordSearch(string $term, int $userId, int $resultCount): void
    {
        if ($userId > 0) {
            Database::insert('search_histories', [
                'user_id'      => $userId,
                'term'         => mb_substr($term, 0, 150),
                'result_count' => $resultCount,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        Database::execute(
            'INSERT INTO `popular_searches` (`term`, `hits`, `last_hit_at`)
             VALUES (:term, 1, NOW())
             ON DUPLICATE KEY UPDATE `hits` = `hits` + 1, `last_hit_at` = NOW()',
            ['term' => mb_substr($term, 0, 150)]
        );
    }
}

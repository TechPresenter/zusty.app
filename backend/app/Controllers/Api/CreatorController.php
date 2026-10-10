<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\ValidationException;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;
use Zuste\Services\ActivityLogger;
use Zuste\Services\BlockService;
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
    /** Most "what's included" lines one package may list. */
    private const PACKAGE_INCLUDES_MAX = 10;

    /** Longest single "what's included" line, in characters. */
    private const PACKAGE_INCLUDE_MAX_CHARS = 120;

    /** The `social_platform` term that marks a direct upload, not a network. */
    private const UPLOAD_SOURCE = 'upload';

    /** creator.max_portfolio_items when the setting row is missing. */
    private const PORTFOLIO_ITEMS_DEFAULT = 100;

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

        // A creator the viewer blocked is not "viewed" in their analytics.
        if (empty($creator['is_blocked'])) {
            CreatorService::recordView((int) $creator['id'], $viewerId, $request->string('source', 'direct'));
        }

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

        // As on the profile: a visitor does not see reviews by someone they
        // have a block with. The owner sees them all; the breakdown counts all.
        $viewerId = $request->userId();
        $filter   = '';
        $bindings = ['id' => $creatorId];

        if ($viewerId > 0 && Database::scalar(
            'SELECT 1 FROM `creators` WHERE `id` = :id AND `user_id` = :viewer',
            ['id' => $creatorId, 'viewer' => $viewerId]
        ) === null) {
            $filter                   = ' AND ' . BlockService::notBlockedSql('r.`reviewer_id`');
            $bindings['block_viewer'] = $viewerId;
        }

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM `reviews` r
             WHERE r.`creator_id` = :id AND r.`is_published` = 1 AND r.`deleted_at` IS NULL
               AND r.`direction` = 'brand_to_creator'" . $filter,
            $bindings
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
               AND r.`direction` = 'brand_to_creator'" . $filter . "
             ORDER BY r.`id` DESC
             LIMIT " . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            $bindings
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
            // shipping a hardcoded badge list of their own. The creator-facing
            // projection: never the admin overview, which carries the awarding
            // admin's id and notes and ignores the KYC standing behind the
            // KYC-derived badges.
            'badges'  => CreatorBadgeService::ownerOverview($creatorId),
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

    /**
     * GET /me/creator/packages — every package the creator owns, hidden ones
     * included.
     *
     * The profile payload lists ACTIVE packages only, because it is what a
     * brand sees. Read from there, a package the creator hid could never be
     * found again to show or delete, so the editor reads its own list. The
     * price bounds travel with it so the form can say what the API will
     * accept before the creator presses Save.
     */
    public function myPackages(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $bounds    = self::packagePriceBounds();

        $rows = Database::select(
            'SELECT `id`, `title`, `description`, `platform`, `deliverable_type`, `quantity`,
                    `price`, `currency`, `turnaround_days`, `revisions`, `includes`, `is_active`,
                    `sort_order`, `created_at`, `updated_at`
               FROM `creator_packages`
              WHERE `creator_id` = :id AND `deleted_at` IS NULL
              ORDER BY `is_active` DESC, `sort_order`, `price`, `id`',
            ['id' => $creatorId]
        );

        Response::success([
            'packages' => array_map(static fn (array $row): array => self::presentPackage($row), $rows),
            'locked'   => CreatorProfileLockService::isLocked($creatorId, 'pricing'),
            'limits'   => [
                'min_price'          => $bounds['min'],
                'max_price'          => $bounds['max'],
                'max_includes'       => self::PACKAGE_INCLUDES_MAX,
                'include_max_length' => self::PACKAGE_INCLUDE_MAX_CHARS,
            ],
        ]);
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
            'price'            => self::packagePriceRule('required'),
            'turnaround_days'  => 'nullable|integer|min_value:1|max_value:180',
            'revisions'        => 'nullable|integer|min_value:0|max_value:10',
            'includes'         => 'nullable|array',
        ], self::packagePriceMessages());

        $includes = isset($data['includes']) ? self::packageIncludes((array) $data['includes']) : [];

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
            'includes'         => $includes === [] ? null : json_encode($includes, JSON_UNESCAPED_UNICODE),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        CreatorService::refreshStatistics($creatorId);
        CreatorService::computeProfileScore($creatorId);

        $this->logCreatorChange($request, 'creator.package_created', $creatorId,
            'Created pricing package #' . $packageId . '.', ['package_id' => $packageId]);

        Response::created(
            ['package' => $this->ownPackage($packageId)],
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
            'price'            => self::packagePriceRule('nullable'),
            'turnaround_days'  => 'nullable|integer|min_value:1|max_value:180',
            'revisions'        => 'nullable|integer|min_value:0|max_value:10',
            'is_active'        => 'nullable|boolean',
            'includes'         => 'nullable|array',
        ], self::packagePriceMessages());

        $updates = array_filter([
            'title'            => $data['title'] ?? null,
            'platform'         => $data['platform'] ?? null,
            'deliverable_type' => $data['deliverable_type'] ?? null,
            'quantity'         => isset($data['quantity']) ? (int) $data['quantity'] : null,
            'price'            => isset($data['price']) ? Money::round((float) $data['price']) : null,
            'turnaround_days'  => isset($data['turnaround_days']) ? (int) $data['turnaround_days'] : null,
            'revisions'        => isset($data['revisions']) ? (int) $data['revisions'] : null,
        ], static fn ($value): bool => $value !== null);

        // The two optional texts are cleared by sending them empty. Filtered
        // with the rest above, an emptied description or "what's included"
        // list was dropped as "not sent" and the old copy stayed on the card.
        if (array_key_exists('description', $data)) {
            $updates['description'] = $data['description'];
        }

        if (array_key_exists('includes', $data)) {
            $includes = self::packageIncludes((array) ($data['includes'] ?? []));

            $updates['includes'] = $includes === [] ? null : json_encode($includes, JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $updates['is_active'] = $data['is_active'] ? 1 : 0;
        }

        if ($updates !== []) {
            Database::update('creator_packages', $updates, ['id' => $packageId]);
            CreatorService::refreshStatistics($creatorId);
            CreatorService::computeProfileScore($creatorId);
        }

        $this->logCreatorChange($request, 'creator.package_updated', $creatorId,
            'Updated pricing package #' . $packageId . '.',
            ['package_id' => $packageId, 'fields' => array_keys($updates)]);

        Response::success(
            ['package' => $this->ownPackage($packageId)],
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
        CreatorService::computeProfileScore($creatorId);

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
            // Both were read raw, so any string at all — `javascript:…`, a
            // file path, 2,000 characters — went into a column the profile
            // and the admin moderation queue render as a link and an image.
            'media_url'     => 'nullable|url|max:500',
            'thumbnail_url' => 'nullable|url|max:500',
            'aspect_ratio'  => 'nullable|string|max:10',
            'duration_secs' => 'nullable|integer|min_value:0',
        ]);

        // Every item is a stored file and a media row, so there is a ceiling,
        // checked before anything is written. 0 means none.
        $cap = self::portfolioCap();

        if ($cap > 0 && self::portfolioCount($creatorId) >= $cap) {
            throw HttpException::unprocessable(
                "Your portfolio holds up to {$cap} items. Remove one to add another.",
                'portfolio_full'
            );
        }

        $mediaUrl     = (string) ($data['media_url'] ?? '');
        $thumbnailUrl = $data['thumbnail_url'] ?? null;

        // Accept either a direct upload or a pre-uploaded URL.
        if ($request->hasFile('media')) {
            $file = (array) $request->file('media');

            // What is stored is decided by the FILE, not by the tab it is
            // filed under. The app shows every item as a picture and plays
            // a reel or video from its link, so a reel arrives as its cover
            // image — which the old kind-based choice refused as "not a
            // video". An actual video file under a video kind still goes
            // through the video rules and limits.
            $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $isImage   = in_array($extension, UploadService::limitsFor('image')[0], true);
            $kind      = !$isImage && in_array($data['media_kind'], ['reel', 'video'], true) ? 'video' : 'image';

            $upload = UploadService::store(
                $file,
                'portfolio',
                $kind,
                'user',
                $this->userId($request)
            );

            $mediaUrl     = $upload['url'];
            $thumbnailUrl ??= $upload['url'];

            // The grid and the viewer size each tile from this; an upload
            // knows its own shape, so the creator is not asked for it.
            $data['aspect_ratio'] ??= self::aspectRatio($upload['width'] ?? null, $upload['height'] ?? null);
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

        $item = self::presentPortfolioItem(
            Database::selectOne('SELECT * FROM `creator_portfolio` WHERE `id` = :id', ['id' => $itemId]) ?? []
        );

        Response::created(
            ['item' => $item],
            $item['moderation_status'] === 'pending'
                ? 'Added. It shows on your profile once our team has reviewed it.'
                : 'Added to your portfolio.'
        );
    }

    /**
     * GET /me/creator/portfolio — the creator's own items, newest first.
     *
     * The public views show approved, active items only. With
     * creator.moderate_gallery_uploads on, everything a creator adds starts
     * `pending`, so read from there their newest work simply vanished — and
     * an item the team turned down could never be found to remove. This
     * lists every item they have not deleted, with where it stands.
     */
    public function myPortfolio(Request $request): never
    {
        $creatorId  = $this->creatorId($request);
        $pagination = $this->pagination($request, 30, 60);

        $total = self::portfolioCount($creatorId);

        $rows = Database::select(
            'SELECT * FROM `creator_portfolio`
              WHERE `creator_id` = :id AND `deleted_at` IS NULL
              ORDER BY `id` DESC
              LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            ['id' => $creatorId]
        );

        Response::paginated(
            array_map(static fn (array $row): array => self::presentPortfolioItem($row), $rows),
            $total,
            $pagination['page'],
            $pagination['per_page'],
            'OK',
            [
                'locked'    => CreatorProfileLockService::isLocked($creatorId, 'portfolio'),
                // 0 means no ceiling, exactly as addPortfolioItem() reads it.
                'max_items' => self::portfolioCap(),
            ]
        );
    }

    /** creator.max_portfolio_items; 0 means no ceiling. */
    private static function portfolioCap(): int
    {
        return max(0, Config::settingInt('creator', 'max_portfolio_items', self::PORTFOLIO_ITEMS_DEFAULT));
    }

    /** Items the creator has not deleted, whatever their review status. */
    private static function portfolioCount(int $creatorId): int
    {
        return (int) (Database::scalar(
            'SELECT COUNT(*) FROM `creator_portfolio` WHERE `creator_id` = :id AND `deleted_at` IS NULL',
            ['id' => $creatorId]
        ) ?? 0);
    }

    /** DELETE /me/creator/portfolio/{id} */
    public function deletePortfolioItem(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $itemId    = $request->routeParamInt('id');

        CreatorProfileLockService::assertUnlocked($creatorId, 'portfolio');

        $owned = Database::scalar(
            'SELECT 1 FROM `creator_portfolio`
              WHERE `id` = :id AND `creator_id` = :creator_id AND `deleted_at` IS NULL',
            ['id' => $itemId, 'creator_id' => $creatorId]
        );

        if ($owned === null) {
            throw HttpException::notFound('Portfolio item not found.');
        }

        Database::update('creator_portfolio', ['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0], ['id' => $itemId]);
        CreatorService::refreshStatistics($creatorId);
        CreatorService::computeProfileScore($creatorId);

        $this->logCreatorChange($request, 'creator.portfolio_removed', $creatorId,
            'Removed portfolio item #' . $itemId . '.', ['item_id' => $itemId]);

        Response::noContent('Removed from your portfolio.');
    }

    /**
     * GET /me/creator/social — the creator's linked accounts, in their order.
     *
     * Every write below already answers with this list; the editor needs
     * the same list to open with, and the profile payload is the wrong
     * source for it (primary-first ordering, and a different set of keys).
     */
    public function mySocialAccounts(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        Response::success([
            'social_accounts' => $this->socialAccounts($creatorId),
            'locked'          => CreatorProfileLockService::isLocked($creatorId, 'social'),
            // 0 means no ceiling, exactly as upsertSocialAccount() reads it.
            'max_accounts'    => max(0, Config::settingInt('creator', 'max_social_accounts', 20)),
        ]);
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

        // `upload` is a term of this taxonomy only because it is the default
        // of creator_portfolio.source_platform (migration 035). It marks a
        // file sent straight to us; it is not a network anyone links.
        if ($platform === self::UPLOAD_SOURCE || !TaxonomyService::isValid('social_platform', $platform)) {
            throw ValidationException::forField('platform', 'That is not a network we support.');
        }

        $handle = ltrim(trim((string) $data['handle']), '@');

        if ($handle === '') {
            throw ValidationException::forField('handle', 'Enter the handle or the link.');
        }

        // A network that reports follower counts holds one account per
        // creator; a link-only entry — website, blog, a custom link — may
        // repeat. The taxonomy term says which, so the rule is data.
        $term      = self::socialTerm($platform);
        $metadata  = is_array($term['metadata'] ?? null) ? $term['metadata'] : [];
        $singleton = ($metadata['metrics'] ?? false) === true;
        $custom    = ($metadata['custom_label'] ?? false) === true;

        // The label replaces the network's name wherever the account is shown
        // (socialAccounts(), CreatorService::profile()). It is the creator's
        // own name for a custom link and nothing else: kept on an Instagram
        // row it let a direct API call show "Instagram - Verified by Zusty"
        // next to the Instagram icon on the profile brands read.
        $label = $custom ? trim((string) ($data['label'] ?? '')) : '';

        if ($custom && $label === '') {
            throw ValidationException::forField('label', 'Give this link a name.');
        }

        $existingId = (int) ($data['id'] ?? 0);
        $existing   = null;

        if ($existingId > 0) {
            $existing = Database::selectOne(
                'SELECT `platform`, `handle`, `profile_url` FROM `creator_social_accounts`
                  WHERE `id` = :id AND `creator_id` = :creator_id',
                ['id' => $existingId, 'creator_id' => $creatorId]
            );

            if ($existing === null) {
                throw HttpException::notFound('That linked account was not found.');
            }

            // The update below never writes `platform`, so a link edited
            // "from Instagram to YouTube" kept saying Instagram while its
            // handle and URL said YouTube. Moving networks is unlink + add.
            if ((string) $existing['platform'] !== $platform) {
                throw ValidationException::forField(
                    'platform',
                    'A linked account cannot move to another network. Remove it and add the new one.'
                );
            }
        } elseif ($singleton) {
            // Without an id this is "link an account". It used to pick the
            // creator's existing row for the network and overwrite it — its
            // handle, its figures and its verified mark — while answering
            // "saved", so a creator adding a second Instagram replaced their
            // verified one. (The unique key is (creator_id, platform, handle),
            // so the index cannot stop a second row either.) A network with
            // figures holds one account; the creator is told to edit it.
            $linked = Database::scalar(
                'SELECT 1 FROM `creator_social_accounts`
                  WHERE `creator_id` = :c AND `platform` = :p LIMIT 1',
                ['c' => $creatorId, 'p' => $platform]
            );

            if ($linked !== null) {
                throw ValidationException::forField(
                    'platform',
                    'Your ' . (string) ($term['label'] ?? ucfirst($platform)) . ' account is already linked. Edit it instead.'
                );
            }
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

        // The unique key is (creator_id, platform, handle). A second link to
        // the same account — a repeated website, or an edit that renames one
        // link onto another — hit it as a raw duplicate-key error, a 500.
        $duplicate = Database::scalar(
            'SELECT 1 FROM `creator_social_accounts`
              WHERE `creator_id` = :c AND `platform` = :p AND `handle` = :h AND `id` <> :id
              LIMIT 1',
            ['c' => $creatorId, 'p' => $platform, 'h' => $handle, 'id' => $existingId]
        );

        if ($duplicate !== null) {
            throw ValidationException::forField('handle', 'That account is already linked.');
        }

        // Figures belong to networks that have them. On a website row they
        // were stored as sent and summed into the profile's follower total.
        // They are the creator's own numbers (nothing fetches them), and
        // `last_synced_at` records when the creator last entered them; the
        // apps label both as self-reported.
        $fields = [
            'handle'          => $handle,
            'profile_url'     => (string) $data['profile_url'],
            'label'           => $label === '' ? null : $label,
            'followers'       => $singleton ? (int) ($data['followers'] ?? 0) : 0,
            'engagement_rate' => $singleton ? (float) ($data['engagement_rate'] ?? 0) : 0.0,
            'last_synced_at'  => date('Y-m-d H:i:s'),
        ];

        // A verified mark belongs to the account that was checked. Pointing
        // the row at a different handle or URL must not carry it across, or
        // renaming a verified link would verify whatever it now points to —
        // and the `social_verified` badge is drawn from this flag.
        if ($existing !== null
            && ((string) $existing['handle'] !== $handle || (string) $existing['profile_url'] !== $fields['profile_url'])) {
            $fields['is_verified'] = 0;
        }

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
            // shows the network's name, whatever an older row's label says.
            $ownLabel            = ($metadata['custom_label'] ?? false) === true ? (string) ($row['label'] ?? '') : '';
            $row['display_name'] = (string) ($ownLabel ?: ($term['label'] ?? ucfirst((string) $row['platform'])));
            // The name the public profile sends it under, so one client model
            // reads both payloads.
            $row['platform_label'] = $row['display_name'];
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

    /**
     * The range a package price must fall within.
     *
     * A package IS a booking amount: once a brand picks one, BookingService
     * takes the package's price as the amount — after the min/max checks
     * have already run on what the brand typed. A package priced under
     * booking.min_booking_amount therefore slipped beneath the floor, and
     * one over the ceiling could be requested at a price no checkout would
     * take. Holding packages to the same bounds keeps every one bookable.
     *
     * @return array{min:float,max:float}
     */
    private static function packagePriceBounds(): array
    {
        $min = max(1.0, Config::settingFloat('booking', 'min_booking_amount', 1000));
        $max = max($min, Config::settingFloat('booking', 'max_booking_amount', 2000000));

        return ['min' => $min, 'max' => $max];
    }

    /** The `price` rule, `required` on create and `nullable` on update. */
    private static function packagePriceRule(string $presence): string
    {
        $bounds = self::packagePriceBounds();

        return $presence . '|numeric|min_value:' . $bounds['min'] . '|max_value:' . $bounds['max'];
    }

    /** @return array<string,string> */
    private static function packagePriceMessages(): array
    {
        $bounds = self::packagePriceBounds();

        return [
            'price.min_value' => 'The lowest price a package can have is ' . Money::formatShort($bounds['min']) . '.',
            'price.max_value' => 'The highest price a package can have is ' . Money::formatShort($bounds['max']) . '.',
        ];
    }

    /**
     * The "what's included" list as it is stored: trimmed lines, no blanks.
     *
     * The rule was `array` and nothing more, so a nested object, a number or
     * a line of ten thousand characters went straight into the column and
     * from there onto every brand's package card.
     *
     * @param  array<mixed> $lines
     * @return list<string>
     */
    private static function packageIncludes(array $lines): array
    {
        $clean = [];

        foreach ($lines as $line) {
            if (!is_string($line) && !is_int($line) && !is_float($line)) {
                throw ValidationException::forField('includes', 'List what is included as lines of text.');
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $line));

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > self::PACKAGE_INCLUDE_MAX_CHARS) {
                throw ValidationException::forField(
                    'includes',
                    'Keep each included item to ' . self::PACKAGE_INCLUDE_MAX_CHARS . ' characters.'
                );
            }

            $clean[] = $text;
        }

        if (count($clean) > self::PACKAGE_INCLUDES_MAX) {
            throw ValidationException::forField(
                'includes',
                'List up to ' . self::PACKAGE_INCLUDES_MAX . ' included items.'
            );
        }

        return $clean;
    }

    /** @return array<string,mixed> */
    private function ownPackage(int $packageId): array
    {
        return self::presentPackage(
            Database::selectOne('SELECT * FROM `creator_packages` WHERE `id` = :id', ['id' => $packageId]) ?? []
        );
    }

    /**
     * One package as the creator's editor reads it.
     *
     * The write endpoints used to answer with the raw row, where `includes`
     * is a JSON string and `is_active` a 0/1 — so the app could not use the
     * answer and had to reload. This is the profile's shape plus the two
     * fields only the owner needs, `is_active` and `sort_order`.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function presentPackage(array $row): array
    {
        $includes = json_decode((string) ($row['includes'] ?? ''), true);
        $price    = Money::round((float) ($row['price'] ?? 0));
        $currency = (string) ($row['currency'] ?? Money::defaultCurrency());

        return [
            'id'               => (int) ($row['id'] ?? 0),
            'title'            => (string) ($row['title'] ?? ''),
            'description'      => isset($row['description']) ? (string) $row['description'] : null,
            'platform'         => isset($row['platform']) ? (string) $row['platform'] : null,
            'deliverable_type' => isset($row['deliverable_type']) ? (string) $row['deliverable_type'] : null,
            'quantity'         => (int) ($row['quantity'] ?? 1),
            'price'            => $price,
            'currency'         => $currency,
            'price_formatted'  => Money::formatShort($price, $currency),
            'turnaround_days'  => (int) ($row['turnaround_days'] ?? 0),
            'revisions'        => (int) ($row['revisions'] ?? 0),
            'includes'         => is_array($includes)
                ? array_values(array_map('strval', array_filter($includes, 'is_scalar')))
                : [],
            'is_active'        => (int) ($row['is_active'] ?? 0) === 1,
            'sort_order'       => (int) ($row['sort_order'] ?? 0),
            'created_at'       => $row['created_at'] ?? null,
            'updated_at'       => $row['updated_at'] ?? null,
        ];
    }

    /**
     * One portfolio item as its owner sees it: the public fields, whether it
     * is live, and — when the team turned it down — the reason's label.
     *
     * The moderator's free-text note and the reviewer stay out, as they do
     * from the data export: they are the operator's working notes.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function presentPortfolioItem(array $row): array
    {
        $status = (string) ($row['moderation_status'] ?? 'approved');
        $reason = $row['moderation_reason'] ?? null;

        return [
            'id'                => (int) ($row['id'] ?? 0),
            'media_kind'        => (string) ($row['media_kind'] ?? ''),
            'collection'        => (string) ($row['collection'] ?? 'portfolio'),
            'title'             => $row['title'] ?? null,
            'caption'           => $row['caption'] ?? null,
            'media_url'         => $row['media_url'] ?? null,
            'thumbnail_url'     => $row['thumbnail_url'] ?? null,
            'external_url'      => $row['external_url'] ?? null,
            'source_platform'   => $row['source_platform'] ?? null,
            'aspect_ratio'      => $row['aspect_ratio'] ?? null,
            'duration_secs'     => isset($row['duration_secs']) ? (int) $row['duration_secs'] : null,
            'views_count'       => (int) ($row['views_count'] ?? 0),
            'likes_count'       => (int) ($row['likes_count'] ?? 0),
            'comments_count'    => (int) ($row['comments_count'] ?? 0),
            'published_at'      => $row['published_at'] ?? null,
            'is_active'         => (int) ($row['is_active'] ?? 0) === 1,
            'moderation_status' => $status,
            'reason_label'      => $status === 'rejected' && is_string($reason) && $reason !== ''
                ? TaxonomyService::label('moderation_reason', $reason)
                : null,
            'created_at'        => $row['created_at'] ?? null,
        ];
    }

    /**
     * "4:5" from an image's pixel size, or null when it is not known.
     *
     * Stored the way the column has always held it (`3:4`, `9:16`); reduced
     * so a 1080×1350 photo reads as 4:5. Sizes are capped by the upload
     * pipeline, so the result always fits the column's ten characters.
     */
    private static function aspectRatio(mixed $width, mixed $height): ?string
    {
        $w = is_numeric($width) ? (int) $width : 0;
        $h = is_numeric($height) ? (int) $height : 0;

        if ($w <= 0 || $h <= 0) {
            return null;
        }

        $a = $w;
        $b = $h;

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        $ratio = intdiv($w, $a) . ':' . intdiv($h, $a);

        return strlen($ratio) <= 10 ? $ratio : null;
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

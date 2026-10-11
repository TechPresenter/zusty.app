<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Admin\Resource;
use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;
use Zuste\Services\AiService;
use Zuste\Services\AppContentService;
use Zuste\Services\AuthConfigService;
use Zuste\Services\CreatorService;
use Zuste\Services\MessageService;
use Zuste\Services\NotificationService;
use Zuste\Services\SmsService;
use Zuste\Services\WalletService;

/**
 * Public content: the home feed, CMS pages, FAQs, blogs and app config.
 *
 * Routes: /api/v1/home, /api/v1/pages/*, /api/v1/faqs, /api/v1/blogs/*,
 *         /api/v1/settings, /api/v1/app/config, /api/v1/health
 */
final class ContentController extends BaseApiController
{
    /** GET /home — everything the Home screen needs in a single round trip. */
    public function home(Request $request): never
    {
        $userId   = $request->userId() ?: null;
        $audience = 'all';

        if ($userId !== null) {
            $accountType = (string) ($request->user()['account_type'] ?? 'brand');
            $audience    = $accountType === 'creator' ? 'creators' : 'brands';
        } else {
            $audience = 'guests';
        }

        $banners = Database::select(
            "SELECT `id`, `title`, `subtitle`, `cta_label`, `image_url`, `image_url_dark`,
                    `action_type`, `action_value`
             FROM `banners`
             WHERE `is_active` = 1 AND `position` = 'home_hero'
               AND (`audience` = 'all' OR `audience` = :audience)
               AND (`starts_at` IS NULL OR `starts_at` <= NOW())
               AND (`ends_at` IS NULL OR `ends_at` >= NOW())
             ORDER BY `sort_order`, `id`
             LIMIT 5",
            ['audience' => $audience]
        );

        $categories = Database::select(
            'SELECT `id`, `name`, `slug`, `icon`, `icon_url`, `color_hex`, `creator_count`
             FROM `categories`
             WHERE `is_active` = 1 AND `is_featured` = 1 AND `deleted_at` IS NULL
             ORDER BY `sort_order`
             LIMIT 10'
        );

        foreach ($categories as &$category) {
            $category['id']            = (int) $category['id'];
            $category['creator_count'] = (int) $category['creator_count'];
        }
        unset($category);

        $topCreators = CreatorService::search(['sort' => 'rating', 'per_page' => 8], $userId);
        $trending    = CreatorService::search(['sort' => 'popular', 'per_page' => 8], $userId);
        $newest      = CreatorService::search(['sort' => 'newest', 'per_page' => 8], $userId);

        $payload = [
            'banners'      => $banners,
            'categories'   => $categories,
            'top_creators' => $topCreators['items'],
            'trending'     => $trending['items'],
            'new_creators' => $newest['items'],
        ];

        if ($userId !== null) {
            $payload['badges'] = [
                'unread_notifications' => NotificationService::unreadCount($userId),
                'unread_messages'      => MessageService::totalUnread($userId),
            ];

            $rows = Database::select(
                "SELECT b.`id`, b.`code`, b.`title`, b.`status`, b.`total_amount`, b.`creator_earning`, b.`currency`,
                        b.`creator_user_id`,
                        c.`display_name` AS `creator_name`, c.`handle` AS `creator_handle`, c.`is_verified` AS `creator_verified`,
                        cu.`avatar_url` AS `creator_avatar`,
                        bu.`name` AS `brand_name`, bu.`company_name`, bu.`avatar_url` AS `brand_avatar`,
                        bu.`has_blue_tick` AS `brand_verified`
                 FROM `bookings` b
                 INNER JOIN `creators` c ON c.`id` = b.`creator_id`
                 INNER JOIN `users` cu ON cu.`id` = b.`creator_user_id`
                 INNER JOIN `users` bu ON bu.`id` = b.`brand_user_id`
                 WHERE (b.`brand_user_id` = :id OR b.`creator_user_id` = :id)
                   AND b.`status` IN ('pending','countered','accepted','awaiting_payment','confirmed','in_progress','delivered')
                   AND b.`deleted_at` IS NULL
                 ORDER BY b.`id` DESC LIMIT 5",
                ['id' => $userId]
            );

            // The app parses these with the same model as GET /bookings, so they
            // carry its `counterparty` and `amount` shape, seen from the viewer's
            // side: a creator sees the brand and their own earning. The flat
            // creator_* and total_amount keys stay for builds that read them.
            $payload['active_bookings'] = array_map(static function (array $row) use ($userId): array {
                $isCreatorView = (int) $row['creator_user_id'] === $userId;
                $amount        = (float) ($isCreatorView ? $row['creator_earning'] : $row['total_amount']);

                return [
                    'id'             => (int) $row['id'],
                    'code'           => (string) $row['code'],
                    'title'          => (string) $row['title'],
                    'status'         => (string) $row['status'],
                    'total_amount'   => $row['total_amount'],
                    'currency'       => (string) $row['currency'],
                    'creator_name'   => $row['creator_name'],
                    'creator_avatar' => $row['creator_avatar'],
                    'counterparty'   => [
                        'name'        => $isCreatorView ? (string) ($row['company_name'] ?: $row['brand_name']) : (string) $row['creator_name'],
                        'avatar_url'  => $isCreatorView ? $row['brand_avatar'] : $row['creator_avatar'],
                        'handle'      => $isCreatorView ? null : (string) $row['creator_handle'],
                        'is_verified' => $isCreatorView
                            ? (bool) $row['brand_verified']
                            : (bool) $row['creator_verified'],
                    ],
                    'amount'         => Money::round($amount),
                    'amount_label'   => Money::formatShort($amount, (string) $row['currency']),
                ];
            }, $rows);
        }

        // The admin-configured home layout. Clients that understand `sections`
        // render them in order; the flat keys above stay for older builds, so
        // rolling this out does not strand anyone on an existing release.
        $payload['sections'] = AppContentService::homeSections(
            $userId,
            (string) ($request->user()['account_type'] ?? 'brand')
        );

        Response::success($payload);
    }

    /** GET /explore — the Explore Creators screen. */
    public function explore(Request $request): never
    {
        $userId = $request->userId() ?: null;

        Response::success([
            'top_creators' => CreatorService::search(['sort' => 'rating', 'per_page' => 10], $userId)['items'],
            'trending'     => CreatorService::search(['sort' => 'popular', 'per_page' => 10], $userId)['items'],
            'categories'   => Database::select(
                'SELECT `id`, `name`, `slug`, `icon`, `color_hex`, `creator_count`, `cover_image`
                 FROM `categories` WHERE `is_active` = 1 AND `deleted_at` IS NULL
                 ORDER BY `sort_order` LIMIT 16'
            ),
        ]);
    }

    /** GET /pages — list of in-app pages (Terms, Privacy, About …). */
    public function pages(Request $request): never
    {
        $pages = Database::select(
            'SELECT `slug`, `title`, `subtitle`, `template`, `sort_order`
             FROM `pages` WHERE `is_active` = 1 AND `show_in_app` = 1
             ORDER BY `sort_order`, `title`'
        );

        Response::success(['pages' => $pages]);
    }

    /** GET /pages/{slug} */
    public function page(Request $request): never
    {
        $page = Database::selectOne(
            'SELECT `slug`, `title`, `subtitle`, `content`, `hero_image`, `template`,
                    `meta_title`, `meta_description`, `updated_at`
             FROM `pages` WHERE `slug` = :slug AND `is_active` = 1',
            ['slug' => (string) $request->routeParam('slug', '')]
        );

        if ($page === null) {
            throw HttpException::notFound('Page not found.');
        }

        Response::success(['page' => $page]);
    }

    /** GET /faqs */
    public function faqs(Request $request): never
    {
        $clauses  = ['`is_active` = 1'];
        $bindings = [];

        if ($request->filled('category')) {
            $clauses[]            = '`category` = :category';
            $bindings['category'] = $request->string('category');
        }

        if ($request->filled('audience')) {
            $clauses[]            = "(`audience` = 'all' OR `audience` = :audience)";
            $bindings['audience'] = $request->string('audience');
        }

        if ($request->filled('q')) {
            $clauses[]     = "(`question` LIKE :q ESCAPE '\\\\' OR `answer` LIKE :q ESCAPE '\\\\')";
            $bindings['q'] = Str::likeTerm($request->string('q'));
        }

        $faqs = Database::select(
            'SELECT `id`, `category`, `question`, `answer`, `audience`
             FROM `faqs` WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY `sort_order`, `id`',
            $bindings
        );

        // The app titles each accordion section and topic tile with the
        // category's name. Only the slug used to come back, and the client
        // guessed names from the `faq_category` taxonomy — whose values never
        // matched this column, so every topic chip filtered to nothing.
        $labels = self::faqCategoryLabels();

        // Group by category so the accordion can render sections, and count
        // each one for the help-centre topic tiles.
        $grouped    = [];
        $categories = [];

        foreach ($faqs as &$faq) {
            $slug = (string) $faq['category'];

            $faq['category_label'] = $labels[$slug]
                ?? ucfirst(str_replace('_', ' ', $slug));

            $grouped[$slug][] = $faq;

            $categories[$slug] ??= ['value' => $slug, 'label' => $faq['category_label'], 'count' => 0];
            $categories[$slug]['count']++;
        }
        unset($faq);

        Response::success([
            'faqs'       => $faqs,
            'grouped'    => $grouped,
            'categories' => array_values($categories),
        ]);
    }

    /**
     * FAQ category names, keyed by the slug stored on each row.
     *
     * Read from the admin form's own option list, so the app shows exactly
     * the names the operator picks from ("For creators", not "creator"), and
     * a category added there is named here without a second edit.
     *
     * @return array<string,string>
     */
    private static function faqCategoryLabels(): array
    {
        foreach (Resource::find('faqs')->fields as $field) {
            if ($field['name'] === 'category') {
                return $field['options'] ?? [];
            }
        }

        return [];
    }

    /** POST /faqs/{id}/helpful */
    public function rateFaq(Request $request): never
    {
        $column = $request->bool('helpful', true) ? 'helpful_yes' : 'helpful_no';
        $faqId  = $request->routeParamInt('id');

        // An UPDATE that matches nothing still "succeeds", so the endpoint
        // used to thank the caller for rating an FAQ that does not exist —
        // and a client could never tell a real vote from a no-op.
        $affected = Database::execute(
            'UPDATE `faqs` SET `' . $column . '` = `' . $column . '` + 1 WHERE `id` = :id AND `is_active` = 1',
            ['id' => $faqId]
        );

        if ($affected === 0) {
            throw HttpException::notFound('That question could not be found.');
        }

        Response::noContent('Thanks for the feedback.');
    }

    /** GET /blogs */
    public function blogs(Request $request): never
    {
        $pagination = $this->pagination($request, 10, 30);

        $clauses  = ["b.`status` = 'published'", 'b.`deleted_at` IS NULL', '(b.`published_at` IS NULL OR b.`published_at` <= NOW())'];
        $bindings = [];

        if ($request->filled('category')) {
            $clauses[]            = 'bc.`slug` = :category';
            $bindings['category'] = $request->string('category');
        }

        if ($request->filled('q')) {
            $clauses[]     = "(b.`title` LIKE :q ESCAPE '\\\\' OR b.`excerpt` LIKE :q ESCAPE '\\\\')";
            $bindings['q'] = Str::likeTerm($request->string('q'));
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `blogs` b LEFT JOIN `blog_categories` bc ON bc.`id` = b.`category_id`' . $where,
            $bindings
        );

        $blogs = Database::select(
            'SELECT b.`id`, b.`slug`, b.`title`, b.`excerpt`, b.`cover_image`, b.`author_name`,
                    b.`reading_minutes`, b.`views_count`, b.`published_at`, b.`is_featured`,
                    bc.`name` AS `category_name`, bc.`slug` AS `category_slug`
             FROM `blogs` b
             LEFT JOIN `blog_categories` bc ON bc.`id` = b.`category_id`' . $where . '
             ORDER BY b.`is_featured` DESC, b.`published_at` DESC
             LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            $bindings
        );

        Response::paginated($blogs, $total, $pagination['page'], $pagination['per_page']);
    }

    /** GET /blogs/{slug} */
    public function blog(Request $request): never
    {
        $slug = (string) $request->routeParam('slug', '');

        // The published_at guard matches the one in blogs() above: without it
        // a scheduled article is hidden from the index but readable by anyone
        // who guesses or is told its slug, which defeats the embargo.
        $blog = Database::selectOne(
            "SELECT b.*, bc.`name` AS `category_name`, bc.`slug` AS `category_slug`
             FROM `blogs` b
             LEFT JOIN `blog_categories` bc ON bc.`id` = b.`category_id`
             WHERE b.`slug` = :slug AND b.`status` = 'published' AND b.`deleted_at` IS NULL
               AND (b.`published_at` IS NULL OR b.`published_at` <= NOW())",
            ['slug' => $slug]
        );

        if ($blog === null) {
            throw HttpException::notFound('Article not found.');
        }

        Database::execute('UPDATE `blogs` SET `views_count` = `views_count` + 1 WHERE `id` = :id', ['id' => (int) $blog['id']]);

        $blog['tags']    = json_decode((string) ($blog['tags'] ?? '[]'), true) ?: [];
        $blog['related'] = Database::select(
            "SELECT `slug`, `title`, `excerpt`, `cover_image`, `reading_minutes`, `published_at`
             FROM `blogs`
             WHERE `status` = 'published' AND `deleted_at` IS NULL AND `id` <> :id
               AND (`category_id` = :category_id OR :category_id IS NULL)
             ORDER BY `published_at` DESC LIMIT 3",
            ['id' => (int) $blog['id'], 'category_id' => $blog['category_id']]
        );

        Response::success(['blog' => $blog]);
    }

    /** GET /testimonials */
    public function testimonials(Request $request): never
    {
        Response::success([
            'testimonials' => Database::select(
                'SELECT `author_name`, `author_role`, `company`, `avatar_url`, `content`, `rating`
                 FROM `testimonials` WHERE `is_active` = 1
                 ORDER BY `is_featured` DESC, `sort_order` LIMIT 12'
            ),
        ]);
    }

    /** POST /contact */
    public function contact(Request $request): never
    {
        $data = $this->validate($request, [
            'name'    => 'required|string|min:2|max:120',
            'email'   => 'required|email|max:191',
            'phone'   => 'nullable|phone',
            'subject' => 'required|string|min:3|max:191',
            'message' => 'required|string|min:10|max:5000',
        ]);

        Database::insert('contact_messages', [
            'name'       => (string) $data['name'],
            'email'      => (string) $data['email'],
            'phone'      => $data['phone'] ?? null,
            'subject'    => (string) $data['subject'],
            'message'    => (string) $data['message'],
            'source'     => 'app',
            'ip_address' => $request->ip(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Response::created(null, 'Thanks for reaching out. Our team will get back to you shortly.');
    }

    /**
     * GET /app/config — bootstrap payload the Flutter app fetches at launch.
     * Deliberately includes only public settings.
     */
    public function appConfig(Request $request): never
    {
        $platform = strtolower($request->header('x-platform') ?: $request->string('platform', 'android'));
        $version  = $request->header('x-app-version') ?: $request->string('app_version', '1.0.0');

        // The apps re-fetch this on every resume so an admin change reaches
        // them quickly. A validator keeps that cheap: unchanged config costs a
        // 304 with no body instead of the whole payload.
        //
        // The platform is part of the tag because the response differs by it —
        // without that, an iOS client could be handed an Android client ID from
        // a shared cache.
        // The validator has to cover everything the payload contains, not just
        // the auth config. Popups and feature flags are part of this response,
        // so adding a popup previously left the ETag unchanged and every
        // revalidating client kept serving a config with the popup missing.
        $contentStamp = (string) (Database::scalar(
            'SELECT CONCAT(
                (SELECT COUNT(*) FROM `popups`),  "-",
                (SELECT COALESCE(MAX(`updated_at`), "0") FROM `popups`), "-",
                (SELECT COUNT(*) FROM `feature_flags`), "-",
                (SELECT COALESCE(MAX(`updated_at`), "0") FROM `feature_flags`), "-",
                (SELECT COALESCE(MAX(`updated_at`), "0") FROM `settings`), "-",
                (SELECT COUNT(*) FROM `menu_items`), "-",
                (SELECT COALESCE(MAX(`updated_at`), "0") FROM `menu_items`), "-",
                (SELECT COALESCE(MAX(`updated_at`), "0") FROM `menus`), "-",
                (SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(":", `id`, `is_enabled`, `is_default`,
                        `api_key` IS NOT NULL AND `api_key` <> "", `deleted_at` IS NULL) ORDER BY `id`), "")
                   FROM `ai_providers`), "-",
                (SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(":", `code`, `is_active`) ORDER BY `id`), "")
                   FROM `ai_prompts`)
            )'
        ) ?? '');

        // The `ai` block depends on whether a provider is usable, which lives in
        // `ai_providers` rather than `settings` — so the columns that decide it
        // are part of the validator above. `updated_at` is deliberately not:
        // every AI call stamps `last_used_at`, which would bust every client's
        // cache for nothing.

        // The audience belongs in the validator for the same reason the
        // platform does: this response is resolved per audience — feature
        // flags, popups and now the menus are all filtered by whether the
        // caller is signed in and which account type they are. Without it,
        // signing out and signing back in as the other account type revalidates
        // to a 304 and the app keeps serving the previous account's menu and
        // popups. `Cache-Control: private` stops a shared proxy caching it; it
        // does nothing about two accounts on one device.
        $audience = $request->user() === null
            ? 'guest'
            : (string) ($request->user()['account_type'] ?? 'brand');

        $etag = '"' . AuthConfigService::version()
            . '-' . substr(md5($platform . '|' . $version . '|' . $audience . '|' . $contentStamp), 0, 12) . '"';

        if ($this->matchesEtag($request->header('if-none-match'), $etag)) {
            Response::header('ETag', $etag);
            Response::header('Cache-Control', 'private, max-age=0, must-revalidate');
            Response::json([], 304);
        }

        Response::header('ETag', $etag);
        Response::header('Cache-Control', 'private, max-age=0, must-revalidate');

        $minVersion    = Config::settingString('app', $platform === 'ios' ? 'ios_min_version' : 'android_min_version', '1.0.0');
        $latestVersion = Config::settingString('app', $platform === 'ios' ? 'ios_latest_version' : 'android_latest_version', '1.0.0');
        $storeUrl      = Config::settingString('app', $platform === 'ios' ? 'ios_store_url' : 'android_store_url', '');

        $forceUpdate = Config::settingBool('app', 'force_update', false)
            || version_compare($version, $minVersion, '<');

        Response::success([
            'app' => [
                'name'            => Config::settingString('general', 'app_name', 'Zusty'),
                'tagline'         => Config::settingString('general', 'app_tagline', 'Shoot. Connect. Grow.'),
                'currency'        => Money::defaultCurrency(),
                'currency_symbol' => Money::symbol(),
                'locale'          => Config::settingString('general', 'default_locale', 'en'),
                'timezone'        => Config::settingString('general', 'timezone', 'Asia/Kolkata'),
                'support_email'   => Config::settingString('general', 'support_email'),
                'support_phone'   => Config::settingString('general', 'support_phone'),
            ],
            'branding' => [
                'logo_url'        => Config::settingString('branding', 'logo_url'),
                'logo_dark_url'   => Config::settingString('branding', 'logo_dark_url'),
                'primary_color'   => Config::settingString('branding', 'primary_color', '#B80049'),
                'secondary_color' => Config::settingString('branding', 'secondary_color', '#632CE5'),
                'tertiary_color'  => Config::settingString('branding', 'tertiary_color', '#006A34'),
            ],
            'update' => [
                'force_update'    => $forceUpdate,
                'min_version'     => $minVersion,
                'latest_version'  => $latestVersion,
                'store_url'       => $storeUrl,
                'update_message'  => 'A newer version of Zusty is available with important improvements.',
            ],
            'maintenance' => [
                'enabled' => Config::settingBool('general', 'maintenance_mode', false),
                'message' => Config::settingString('general', 'maintenance_message'),
            ],
            // Settings-backed switches, plus every row in `feature_flags`
            // evaluated for this caller (audience, platform, version, rollout).
            'features' => array_merge(
                [
                    'wallet_enabled'         => Config::settingBool('wallet', 'enabled', true),
                    'referral_enabled'       => Config::settingBool('referral', 'enabled', true),
                    'counter_offers_enabled' => Config::settingBool('booking', 'allow_counter_offers', true),
                    'creator_signup_enabled' => Config::settingBool('app', 'creator_signup_enabled', true),
                    'brand_signup_enabled'   => Config::settingBool('app', 'brand_signup_enabled', true),
                    'chat_realtime'          => Config::settingBool('firebase', 'firestore_chat_mirror', true),
                    // Whether a text message can go out at all, so the app
                    // offers no SMS notification switch while it cannot.
                    'sms_enabled'            => SmsService::enabled(),
                ],
                AppContentService::featureFlags(
                    $request->user()['id'] ?? null,
                    (string) ($request->user()['account_type'] ?? 'brand'),
                    $platform,
                    $version
                )
            ),

            // Which AI features to offer (Settings -> AI). Never a key or a
            // provider: the app calls /ai/*, which enforces the same switches.
            'ai' => AiService::appFeatures(),

            // Which sign-in methods to offer, in what order, with the client
            // credentials each one needs for this platform. This is what makes
            // an admin toggle reach an installed app without a redeploy.
            'auth' => AuthConfigService::publicBlock($platform),

            // Announcements and modals the client may surface. The server picks
            // which are eligible; the client decides when to show them.
            // `?placement=home` narrows to one screen; with no parameter the
            // client gets every eligible popup and routes them itself using the
            // `placement` field on each.
            'popups' => AppContentService::popups(
                $request->user()['id'] ?? null,
                (string) ($request->user()['account_type'] ?? 'brand'),
                $request->string('placement', 'all')
            ),

            // The admin's menu builder, keyed by location. Riding on this
            // response rather than a route of its own means the drawer arrives
            // in the same round trip the app already makes on every resume,
            // and inherits the ETag above — so reordering an item in the admin
            // panel reaches installed apps without a deploy, and an unchanged
            // menu still costs a 304 with no body.
            'menus' => AppContentService::menus(
                $request->user()['id'] ?? null,
                (string) ($request->user()['account_type'] ?? 'brand')
            ),
            'commission' => [
                'platform_fee_percent' => Config::settingFloat('commission', 'platform_fee_percent', 10),
                'commission_percent'   => Config::settingFloat('commission', 'creator_commission_percent', 10),
                'tax_percent'          => Config::settingFloat('commission', 'tax_percent', 18),
                'tax_label'            => Config::settingString('commission', 'tax_label', 'GST'),
            ],
            'booking' => [
                'min_amount'             => Config::settingFloat('booking', 'min_booking_amount', 1000),
                'max_amount'             => Config::settingFloat('booking', 'max_booking_amount', 2000000),
                'request_expiry_hours'   => Config::settingInt('booking', 'request_expiry_hours', 72),
                'free_cancellation_days' => Config::settingInt('booking', 'free_cancellation_days', 7),
                // Days after delivery before an unanswered booking completes
                // and its held payment is released, as the auto-complete job
                // applies it (never under 1). The app quotes it wherever it
                // explains payment protection.
                'auto_complete_days'     => max(1, Config::settingInt('booking', 'auto_complete_days', 7)),
            ],
            // What the app may offer around the wallet. `topup_enabled` is off
            // by default (migration 056): brands pay bookings at checkout, so
            // every "Add money" button, preset and prompt stays hidden, and
            // POST /wallet/topup answers 403 regardless. `wallet_payment`
            // says whether a balance already held may pay for a booking.
            'wallet' => [
                'topup_enabled'  => WalletService::topupEnabled(),
                'wallet_payment' => Config::settingBool('payment', 'allow_wallet_payment', true),
            ],
            // Named explicitly rather than dumped as a group. settingGroup()
            // ignores is_public and returns values already decrypted, so a
            // whole-group dump on an unauthenticated endpoint publishes
            // whatever anyone later adds to that group. It used to carry a
            // mirrored Google client ID for exactly this reason.
            'social' => [
                'instagram' => Config::settingString('social', 'instagram'),
                'youtube'   => Config::settingString('social', 'youtube'),
                'linkedin'  => Config::settingString('social', 'linkedin'),
                'twitter'   => Config::settingString('social', 'twitter'),
                'facebook'  => Config::settingString('social', 'facebook'),
            ],
            'legal'  => [
                'terms_url'   => rtrim((string) Config::get('app.url'), '/') . '/terms',
                'privacy_url' => rtrim((string) Config::get('app.url'), '/') . '/privacy',
            ],
        ]);
    }

    /** GET /settings — the public subset of platform settings. */
    public function settings(Request $request): never
    {
        $rows = Database::select(
            'SELECT `group_key`, `setting_key`, `value`, `type`
             FROM `settings` WHERE `is_public` = 1 AND `is_encrypted` = 0
             ORDER BY `group_key`, `sort_order`'
        );

        $settings = [];

        foreach ($rows as $row) {
            $value = match ((string) $row['type']) {
                'integer' => (int) $row['value'],
                'decimal' => (float) $row['value'],
                'boolean' => in_array(strtolower((string) $row['value']), ['1', 'true', 'yes'], true),
                'json'    => json_decode((string) $row['value'], true),
                default   => $row['value'],
            };

            $settings[(string) $row['group_key']][(string) $row['setting_key']] = $value;
        }

        Response::success(['settings' => $settings]);
    }

    /** GET /health — used by uptime monitors and the deploy smoke test. */
    public function health(Request $request): never
    {
        $checks = ['database' => false];

        try {
            Database::scalar('SELECT 1');
            $checks['database'] = true;
        } catch (\Throwable) {
            $checks['database'] = false;
        }

        $checks['storage_writable'] = is_writable((string) Config::get('storage.base_path', ''));
        $healthy                    = !in_array(false, $checks, true);

        Response::json([
            'success'   => $healthy,
            'message'   => $healthy ? 'Healthy' : 'Degraded',
            'data'      => [
                'status'      => $healthy ? 'ok' : 'degraded',
                'version'     => (string) Config::get('app.version', '1.0.0'),
                'api_version' => (string) Config::get('app.api_version', 'v1'),
                'time'        => date('c'),
                'checks'      => $checks,
            ],
        ], $healthy ? 200 : 503);
    }
}

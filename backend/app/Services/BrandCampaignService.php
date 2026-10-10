<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\ValidationException;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * Brand influencer campaigns — the open brief a brand publishes and creators
 * apply to.
 *
 * Not to be confused with {@see CampaignService}, which drives the admin
 * panel's marketing broadcasts (email/SMS blasts with tracking pixels and
 * unsubscribe links). The two share the word "campaign" and nothing else.
 *
 * Ownership rule, applied everywhere: a campaign belongs to exactly one brand
 * user, and only that user may read a draft, edit, publish, close or manage
 * applications. Everyone else sees a published campaign only.
 */
final class BrandCampaignService
{
    /**
     * Legal status moves. Anything not listed is refused, so a client cannot
     * walk a campaign backwards into a state its data no longer supports.
     */
    private const TRANSITIONS = [
        'draft'       => ['open', 'cancelled', 'archived'],
        'open'        => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed'   => ['archived'],
        'cancelled'   => ['archived'],
        'archived'    => [],
    ];

    /** Application statuses the brand may set, and what each means. */
    private const BRAND_SETTABLE = ['shortlisted', 'hired', 'declined'];

    /** Every value of `campaign_applications`.`status`, in lifecycle order. */
    public const APPLICATION_STATUSES = ['invited', 'applied', 'shortlisted', 'hired', 'declined'];

    /**
     * Portfolio links a creator may attach to one application. Five is enough
     * to show range without turning the brand's review screen into a link dump.
     */
    public const PORTFOLIO_LINK_LIMIT = 5;

    /** Matches the `max:500` the campaign's own `brief_url` is held to. */
    private const PORTFOLIO_LINK_MAX_LENGTH = 500;

    /**
     * A brief is only in front of creators while its brand's account is
     * active: suspending a brand takes its briefs off the board with it.
     */
    private const ACTIVE_BRAND_SQL = "EXISTS (SELECT 1 FROM `users` ab
                                       WHERE ab.`id` = c.`brand_user_id` AND ab.`status` = 'active'
                                         AND ab.`deleted_at` IS NULL)";

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * List campaigns.
     *
     * A brand sees its own campaigns in every state. Anyone else sees only
     * published, public ones — which is what makes this endpoint safe to
     * expose to creators browsing for work.
     *
     * @param  array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $filters, int $viewerId, bool $ownedOnly): array
    {
        $clauses  = ['c.`deleted_at` IS NULL'];
        $bindings = [];

        if ($ownedOnly) {
            $clauses[]             = 'c.`brand_user_id` = :viewer';
            $bindings['viewer']    = $viewerId;
        } else {
            // A draft is private until published, and `is_public` lets a brand
            // run an invite-only campaign that never appears in the browse list.
            $clauses[] = "c.`status` IN ('open', 'in_progress') AND c.`is_public` = 1";

            // Nor does a brief from a brand with a block between it and the
            // viewer: neither side could act on it.
            $clauses[]                = BlockService::notBlockedSql('c.`brand_user_id`');
            $bindings['block_viewer'] = $viewerId;

            // Nor one from a brand the platform suspended, banned or erased.
            $clauses[] = self::ACTIVE_BRAND_SQL;
        }

        // `all` is what the app's "All" tab (and the brand dashboard) sends,
        // and it means "no status filter" — not a status called 'all'. It was
        // applied literally, so the default tab always came back empty.
        // A comma-separated list (`open,in_progress`) narrows to several
        // statuses in one request, one bound placeholder each.
        $statuses = array_values(array_unique(array_filter(
            array_map('trim', explode(',', strtolower((string) ($filters['status'] ?? '')))),
            static fn (string $s): bool => $s !== '' && $s !== 'all'
        )));

        if ($statuses !== []) {
            $placeholders = [];

            foreach ($statuses as $i => $status) {
                $placeholders[]          = ':status' . $i;
                $bindings['status' . $i] = $status;
            }

            $clauses[] = 'c.`status` IN (' . implode(', ', $placeholders) . ')';
        }

        if (!empty($filters['category_id'])) {
            $clauses[]               = 'c.`category_id` = :category_id';
            $bindings['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['platform'])) {
            $clauses[]            = 'c.`platform` = :platform';
            $bindings['platform'] = (string) $filters['platform'];
        }

        $term = trim((string) ($filters['q'] ?? ''));

        if ($term !== '') {
            $clauses[]       = "(c.`title` LIKE :term ESCAPE '\\\\' OR c.`description` LIKE :term ESCAPE '\\\\')";
            $bindings['term'] = Str::likeTerm($term);
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) (Database::scalar(
            'SELECT COUNT(*) FROM `campaigns` c' . $where,
            $bindings
        ) ?? 0);

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        // The sort key is resolved through a fixed map, so nothing the client
        // sends ever reaches the ORDER BY.
        $order = match ((string) ($filters['sort'] ?? 'newest')) {
            'budget_high' => 'c.`budget_max` DESC',
            'budget_low'  => 'c.`budget_min` ASC',
            'ending_soon' => 'c.`ends_on` IS NULL, c.`ends_on` ASC',
            'popular'     => 'c.`applications_count` DESC',
            default       => 'c.`created_at` DESC',
        };

        // On the public board each card also carries the viewer's own
        // application, so a creator sees "Applied" / "Shortlisted" in the list
        // instead of an Apply button that would only 409. One LEFT JOIN rather
        // than a lookup per card; (campaign, creator) is UNIQUE, so it can never
        // duplicate a row. A brand browsing the board simply gets null.
        $mine       = '';
        $mineSelect = '';
        $rowBindings = $bindings;

        if (!$ownedOnly) {
            $mine = ' LEFT JOIN `campaign_applications` ma
                        ON ma.`campaign_id` = c.`id`
                       AND ma.`creator_user_id` = :me
                       AND ma.`deleted_at` IS NULL';
            $mineSelect = ', ma.`id` AS `my_app_id`, ma.`status` AS `my_app_status`,
                            ma.`quoted_amount` AS `my_app_quoted`, ma.`booking_id` AS `my_app_booking`,
                            ma.`responded_at` AS `my_app_responded`';
            $rowBindings['me'] = $viewerId;
        }

        $rows = Database::select(
            'SELECT c.*, cat.`name` AS `category_name`, u.`name` AS `brand_name`,
                    u.`company_name` AS `brand_company`, u.`avatar_url` AS `brand_avatar`, u.`has_blue_tick` AS `brand_verified`'
            . $mineSelect . '
               FROM `campaigns` c
               LEFT JOIN `categories` cat ON cat.`id` = c.`category_id`
               INNER JOIN `users` u ON u.`id` = c.`brand_user_id`'
            . $mine
            . $where
            . ' ORDER BY ' . $order
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $rowBindings
        );

        $items = array_map(static function (array $row) use ($ownedOnly): array {
            $campaign = self::present($row);

            if (!$ownedOnly) {
                // The list's slice of `my_application`: enough to label the
                // card. The full record (pitch, links, reason) is on the detail.
                $campaign['my_application'] = $row['my_app_id'] === null ? null : [
                    'id'            => (int) $row['my_app_id'],
                    'status'        => $row['my_app_status'],
                    'status_label'  => self::applicationStatusLabel((string) $row['my_app_status']),
                    'quoted_amount' => $row['my_app_quoted'] === null ? null : (float) $row['my_app_quoted'],
                    'booking_id'    => $row['my_app_booking'] === null ? null : (int) $row['my_app_booking'],
                    'responded_at'  => $row['my_app_responded'],
                ];
            }

            return $campaign;
        }, $rows);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * One campaign, with its applications when the viewer is the owner.
     *
     * @return array<string,mixed>
     */
    public static function show(int $campaignId, int $viewerId): array
    {
        $row = Database::selectOne(
            'SELECT c.*, cat.`name` AS `category_name`, u.`name` AS `brand_name`,
                    u.`company_name` AS `brand_company`, u.`avatar_url` AS `brand_avatar`, u.`has_blue_tick` AS `brand_verified`,
                    (u.`status` = \'active\' AND u.`deleted_at` IS NULL) AS `brand_active`
               FROM `campaigns` c
               LEFT JOIN `categories` cat ON cat.`id` = c.`category_id`
               INNER JOIN `users` u ON u.`id` = c.`brand_user_id`
              WHERE c.`id` = :id AND c.`deleted_at` IS NULL',
            ['id' => $campaignId]
        );

        if ($row === null) {
            throw HttpException::notFound('Campaign not found.');
        }

        $isOwner = (int) $row['brand_user_id'] === $viewerId;

        // A draft is not merely hidden from listings — fetching it directly
        // must 404 too, or the list filter is decorative. So is a brief whose
        // brand the platform suspended, as on the board.
        if (!$isOwner && (!in_array((string) $row['status'], ['open', 'in_progress'], true) || (int) $row['brand_active'] !== 1)) {
            throw HttpException::notFound('Campaign not found.');
        }

        $campaign = self::present($row);

        // Applications are commercially sensitive: rates and pitches from
        // competing creators. Only the owning brand ever sees them.
        $campaign['recipients'] = $isOwner ? self::applications($campaignId) : [];

        if (!$isOwner) {
            // A creator browsing gets its own application back, so the UI can
            // show "Applied" instead of offering to apply twice.
            $campaign['my_application'] = self::applicationFor($campaignId, $viewerId);
        }

        return $campaign;
    }

    /**
     * The applications on a campaign, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function applications(int $campaignId): array
    {
        // The creator columns are selected under the exact names
        // CreatorService::presentCard expects, so the applicant payload is
        // identical to a creator card everywhere else in the app.
        $rows = Database::select(
            'SELECT a.`id` AS `application_id`, a.`status`, a.`quoted_amount`,
                    a.`currency` AS `application_currency`,
                    a.`pitch`, a.`portfolio_links`, a.`booking_id`, a.`responded_at`, a.`applied_at`,
                    c.`id`, c.`user_id`, c.`handle`, c.`display_name`, c.`headline`,
                    c.`city`, c.`state`, c.`country`, c.`price_min`, c.`price_max`, c.`currency`,
                    c.`rating_avg`, c.`rating_count`, c.`followers_total`, c.`posts_count`,
                    c.`collabs_count`, c.`is_verified`, c.`is_featured`, c.`availability_status`,
                    u.`avatar_url`,
                    cat.`name` AS `category_name`, cat.`slug` AS `category_slug`
               FROM `campaign_applications` a
               INNER JOIN `creators` c ON c.`id` = a.`creator_id`
               INNER JOIN `users` u ON u.`id` = c.`user_id`
               LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`
              WHERE a.`campaign_id` = :id AND a.`deleted_at` IS NULL
              ORDER BY FIELD(a.`status`, "hired", "shortlisted", "applied", "invited", "declined"),
                       a.`created_at` DESC',
            ['id' => $campaignId]
        );

        return array_map(
            static function (array $row): array {
                $creator = CreatorService::presentCard($row);

                // MySQL keeps the LAST `id` in a flat row, which is c.`id`
                // — so presentCard gets the creator id it needs, and the
                // application id is taken from the alias below.
                return [
                    'id'            => (int) $row['application_id'],
                    'status'        => $row['status'],
                    'quoted_amount' => $row['quoted_amount'] === null ? null : (float) $row['quoted_amount'],
                    'currency'      => $row['application_currency'],
                    'pitch'         => $row['pitch'],
                    // The brand is who the links are for; the creator only
                    // attaches them.
                    'portfolio_links' => self::decodeJson($row['portfolio_links'] ?? null),
                    'booking_id'    => $row['booking_id'] === null ? null : (int) $row['booking_id'],
                    'responded_at'  => $row['responded_at'],
                    'applied_at'    => $row['applied_at'],
                    'creator'       => $creator,
                ];
            },
            $rows
        );
    }

    /** @return array<string,mixed>|null */
    public static function applicationFor(int $campaignId, int $creatorUserId): ?array
    {
        $row = Database::selectOne(
            'SELECT * FROM `campaign_applications`
              WHERE `campaign_id` = :cid AND `creator_user_id` = :uid AND `deleted_at` IS NULL',
            ['cid' => $campaignId, 'uid' => $creatorUserId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'id'            => (int) $row['id'],
            'status'        => $row['status'],
            'quoted_amount' => $row['quoted_amount'] === null ? null : (float) $row['quoted_amount'],
            'pitch'         => $row['pitch'],
            'booking_id'    => $row['booking_id'] === null ? null : (int) $row['booking_id'],
            'responded_at'  => $row['responded_at'],
            // Added after the first release, so every key above keeps its
            // shape. The reason was always stored but never returned, which
            // left a declined creator with a bare "Declined" and no why.
            'status_label'   => self::applicationStatusLabel((string) $row['status']),
            'decline_reason' => $row['status'] === 'declined' ? $row['decline_reason'] : null,
            'portfolio_links' => self::decodeJson($row['portfolio_links'] ?? null),
            'applied_at'     => $row['applied_at'],
        ];
    }

    /**
     * Every campaign the creator has been invited to or applied for, newest
     * first — the creator's half of the board, which had no endpoint at all:
     * the only way to learn an application's fate was to reopen each campaign.
     *
     * `statuses` narrows to one or more application statuses, so the app can
     * fetch "active" (invited + applied + shortlisted) in one call instead of
     * three.
     *
     * Applications on a deleted or still-draft campaign are left out. Both
     * 404 from {@see show()} for a non-owner, so listing them would only offer
     * the creator a card that opens onto "not found".
     *
     * @param  list<string> $statuses
     * @return array{items:list<array<string,mixed>>,total:int,counts:array<string,int>}
     */
    public static function forCreator(int $creatorUserId, array $statuses, int $page, int $perPage): array
    {
        $base     = ' FROM `campaign_applications` a
                     INNER JOIN `campaigns` c ON c.`id` = a.`campaign_id`
                     LEFT JOIN `categories` cat ON cat.`id` = c.`category_id`
                     INNER JOIN `users` u ON u.`id` = c.`brand_user_id`
                     WHERE a.`creator_user_id` = :uid
                       AND a.`deleted_at` IS NULL
                       AND c.`deleted_at` IS NULL
                       AND c.`status` <> \'draft\'';
        $bindings = ['uid' => $creatorUserId];

        // Per-status totals across the whole (unfiltered) set, so the app can
        // label its tabs without a request per tab.
        $counts = array_fill_keys(self::APPLICATION_STATUSES, 0);

        foreach (Database::select('SELECT a.`status`, COUNT(*) AS `n`' . $base . ' GROUP BY a.`status`', $bindings) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        $where = $base;

        // Bound one placeholder per status rather than imploding strings into
        // the SQL; the controller has already restricted them to the ENUM.
        if ($statuses !== []) {
            $placeholders = [];

            foreach (array_values($statuses) as $i => $status) {
                $placeholders[]          = ':st' . $i;
                $bindings['st' . $i]     = $status;
            }

            $where .= ' AND a.`status` IN (' . implode(', ', $placeholders) . ')';
        }

        $total = (int) (Database::scalar('SELECT COUNT(*)' . $where, $bindings) ?? 0);

        $page    = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            'SELECT a.`id` AS `application_id`, a.`status` AS `application_status`,
                    a.`quoted_amount`, a.`currency` AS `application_currency`, a.`pitch`,
                    a.`portfolio_links`, a.`booking_id`, a.`decline_reason`,
                    a.`invited_at`, a.`applied_at`, a.`responded_at`,
                    a.`created_at` AS `application_created_at`,
                    c.*, cat.`name` AS `category_name`, u.`name` AS `brand_name`,
                    u.`company_name` AS `brand_company`, u.`avatar_url` AS `brand_avatar`, u.`has_blue_tick` AS `brand_verified`'
            . $where
            // "Newest" is when the creator last acted: an invitation that was
            // later answered sorts by the answer, not by the invite.
            . ' ORDER BY COALESCE(a.`applied_at`, a.`invited_at`, a.`created_at`) DESC, a.`id` DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings
        );

        $items = array_map(static function (array $row): array {
            $currency = (string) ($row['application_currency'] ?: $row['currency']);
            $quoted   = $row['quoted_amount'] === null ? null : (float) $row['quoted_amount'];
            $campaign = self::present($row);

            return [
                'id'                  => (int) $row['application_id'],
                'status'              => $row['application_status'],
                'status_label'        => self::applicationStatusLabel((string) $row['application_status']),
                'quoted_amount'       => $quoted,
                'quoted_amount_label' => $quoted === null ? null : Money::formatShort($quoted, $currency),
                'currency'            => $currency,
                'pitch'               => $row['pitch'],
                'portfolio_links'     => self::decodeJson($row['portfolio_links'] ?? null),
                'booking_id'          => $row['booking_id'] === null ? null : (int) $row['booking_id'],
                'decline_reason'      => $row['application_status'] === 'declined' ? $row['decline_reason'] : null,
                'invited_at'          => $row['invited_at'],
                'applied_at'          => $row['applied_at'],
                'responded_at'        => $row['responded_at'],
                'created_at'          => $row['application_created_at'],
                // The card's slice of the campaign, not the whole brief: the
                // description can run to 5,000 characters and a list of fifty
                // applications does not need fifty of them.
                'campaign' => [
                    'id'               => $campaign['id'],
                    'code'             => $campaign['code'],
                    'title'            => $campaign['title'],
                    'status'           => $campaign['status'],
                    'status_label'     => $campaign['status_label'],
                    'category_id'      => $campaign['category_id'],
                    'category_name'    => $campaign['category_name'],
                    'platform'         => $campaign['platform'],
                    'deliverable_type' => $campaign['deliverable_type'],
                    'posts_count'      => $campaign['posts_count'],
                    'budget_min'       => $campaign['budget_min'],
                    'budget_max'       => $campaign['budget_max'],
                    'budget_label'     => $campaign['budget_label'],
                    'currency'         => $campaign['currency'],
                    'starts_on'        => $campaign['starts_on'],
                    'ends_on'          => $campaign['ends_on'],
                    'brand'            => $campaign['brand'],
                ],
            ];
        }, $rows);

        return ['items' => $items, 'total' => $total, 'counts' => $counts];
    }

    /**
     * Cleans the optional portfolio links on an application.
     *
     * The shared Validator has no per-element array rules, so each entry is
     * checked here against the same test the `url` rule applies. Errors are
     * keyed `portfolio_links.N` so a form can put the message under the exact
     * field that is wrong. Blank entries are dropped (an empty optional text
     * box is not an error) and duplicates collapse to one.
     *
     * @return list<string>
     */
    public static function normalisePortfolioLinks(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        if (!is_array($raw)) {
            throw ValidationException::forField('portfolio_links', 'Portfolio links must be a list.');
        }

        $errors = [];
        $links  = [];

        foreach (array_values($raw) as $i => $value) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $url = is_string($value) ? trim($value) : '';

            if ($url === ''
                || mb_strlen($url) > self::PORTFOLIO_LINK_MAX_LENGTH
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || preg_match('#^https?://#i', $url) !== 1) {
                $errors['portfolio_links.' . $i] = ['Enter a valid http(s) link.'];
                continue;
            }

            // Exact match only: URL paths are case-sensitive (a YouTube id is),
            // so lower-casing to de-duplicate could merge two different videos.
            if (!in_array($url, $links, true)) {
                $links[] = $url;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if (count($links) > self::PORTFOLIO_LINK_LIMIT) {
            throw ValidationException::forField(
                'portfolio_links',
                sprintf('You can attach up to %d portfolio links.', self::PORTFOLIO_LINK_LIMIT)
            );
        }

        return array_values($links);
    }

    public static function applicationStatusLabel(string $status): string
    {
        return match ($status) {
            'invited'     => 'Invited',
            'applied'     => 'Applied',
            'shortlisted' => 'Shortlisted',
            'hired'       => 'Hired',
            'declined'    => 'Declined',
            default       => ucfirst($status),
        };
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function create(int $brandUserId, array $data): array
    {
        self::assertBudgetSane($data);

        $campaignId = Database::transaction(static function () use ($brandUserId, $data): int {
            $id = Database::insert('campaigns', array_merge(
                self::writableColumns($data),
                [
                    'code'          => 'PENDING',
                    'brand_user_id' => $brandUserId,
                    // A campaign always starts as a draft. Publishing is a
                    // separate, explicit action so a half-written brief is
                    // never visible to creators.
                    'status'        => 'draft',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]
            ));

            Database::update('campaigns', ['code' => Str::referenceCode('ZC', $id, 6)], ['id' => $id]);

            return $id;
        });

        ActivityLogger::log(
            'campaign.created',
            'campaign',
            $campaignId,
            'Campaign created',
            [],
            'user',
            $brandUserId
        );

        return self::show($campaignId, $brandUserId);
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function update(int $campaignId, int $brandUserId, array $data): array
    {
        $campaign = self::assertOwned($campaignId, $brandUserId);

        // Once creators have applied against a brief, changing the brief moves
        // the goalposts under them. Budget and dates stay editable; the terms
        // do not.
        if (in_array((string) $campaign['status'], ['completed', 'cancelled', 'archived'], true)) {
            throw HttpException::unprocessable('A closed campaign can no longer be edited.');
        }

        self::assertBudgetSane($data, $campaign);

        $columns = self::writableColumns($data);

        if ($columns !== []) {
            Database::update('campaigns', $columns, ['id' => $campaignId]);
        }

        ActivityLogger::log(
            'campaign.updated',
            'campaign',
            $campaignId,
            'Campaign updated',
            [],
            'user',
            $brandUserId
        );

        return self::show($campaignId, $brandUserId);
    }

    /** Soft-deletes a campaign the brand owns. */
    public static function delete(int $campaignId, int $brandUserId): void
    {
        $campaign = self::assertOwned($campaignId, $brandUserId);

        // Deleting a campaign creators are actively working on would orphan
        // their bookings.
        $hired = (int) (Database::scalar(
            "SELECT COUNT(*) FROM `campaign_applications`
              WHERE `campaign_id` = :id AND `status` = 'hired' AND `deleted_at` IS NULL",
            ['id' => $campaignId]
        ) ?? 0);

        if ($hired > 0) {
            throw HttpException::conflict(
                'This campaign has hired creators. Cancel it instead of deleting it.'
            );
        }

        Database::update('campaigns', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $campaignId]);

        ActivityLogger::log(
            'campaign.deleted',
            'campaign',
            $campaignId,
            'Campaign deleted',
            [],
            'user',
            $brandUserId
        );
    }

    /**
     * Move a campaign to [$to], refusing anything the state machine forbids.
     *
     * @return array<string,mixed>
     */
    public static function transition(int $campaignId, int $brandUserId, string $to): array
    {
        $campaign = self::assertOwned($campaignId, $brandUserId);
        $from     = (string) $campaign['status'];

        if ($from === $to) {
            // Idempotent: publishing an already-open campaign is not an error.
            return self::show($campaignId, $brandUserId);
        }

        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw HttpException::unprocessable(
                sprintf('A %s campaign cannot become %s.', $from, $to)
            );
        }

        // A brief with no budget or no description is not ready for creators.
        if ($to === 'open') {
            if ((float) $campaign['budget_max'] <= 0) {
                throw HttpException::unprocessable('Set a budget before publishing.');
            }

            if (trim((string) $campaign['description']) === '') {
                throw HttpException::unprocessable('Add a brief before publishing.');
            }
        }

        Database::update('campaigns', ['status' => $to], ['id' => $campaignId]);

        ActivityLogger::log(
            'campaign.' . $to,
            'campaign',
            $campaignId,
            sprintf('Campaign moved from %s to %s', $from, $to),
            ['from' => $from, 'to' => $to],
            'user',
            $brandUserId
        );

        // Tell every creator who applied that the brief closed, so nobody is
        // left waiting on an answer that will never come.
        if (in_array($to, ['cancelled', 'completed'], true)) {
            self::notifyApplicants($campaignId, $to);
        }

        return self::show($campaignId, $brandUserId);
    }

    /**
     * A moderator takes a reported brief down (ReportService::takeDown).
     *
     * A brief still in front of creators (draft, open or in progress) is
     * cancelled and made private, and the creators waiting on an answer are
     * told it closed, as when the brand cancels it. Bookings already made from
     * it are not touched: they carry on, or are cancelled, from their own
     * pages. A brief that had already finished is only made private.
     *
     * @return array<string,mixed>|null The campaign as it was, or null when it
     *                                  no longer exists.
     */
    public static function takeDown(int $campaignId, int $adminId): ?array
    {
        $campaign = Database::selectOne(
            'SELECT * FROM `campaigns` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $campaignId]
        );

        if ($campaign === null) {
            return null;
        }

        $from    = (string) $campaign['status'];
        $closing = in_array($from, ['draft', 'open', 'in_progress'], true);

        Database::update(
            'campaigns',
            $closing ? ['status' => 'cancelled', 'is_public' => 0] : ['is_public' => 0],
            ['id' => $campaignId]
        );

        ActivityLogger::log(
            'campaign.taken_down',
            'campaign',
            $campaignId,
            'Campaign brief taken down by a moderator',
            ['from' => $from, 'to' => $closing ? 'cancelled' : $from],
            'admin',
            $adminId
        );

        if ($closing) {
            self::notifyApplicants($campaignId, 'cancelled');
        }

        return $campaign;
    }

    // -----------------------------------------------------------------
    // Applications
    // -----------------------------------------------------------------

    /**
     * A brand invites a creator. Idempotent on (campaign, creator).
     *
     * @return array<string,mixed>
     */
    public static function invite(int $campaignId, int $brandUserId, int $creatorId): array
    {
        $campaign = self::assertOwned($campaignId, $brandUserId);

        if (in_array((string) $campaign['status'], ['completed', 'cancelled', 'archived'], true)) {
            throw HttpException::unprocessable('This campaign is closed to new invitations.');
        }

        $creator = Database::selectOne(
            "SELECT `id`, `user_id`, `display_name` FROM `creators`
              WHERE `id` = :id AND `status` = 'approved' AND `deleted_at` IS NULL",
            ['id' => $creatorId]
        );

        if ($creator === null) {
            throw HttpException::notFound('Creator not found.');
        }

        BlockService::assertNotBlocked($brandUserId, (int) $creator['user_id']);

        $existing = Database::selectOne(
            'SELECT `id`, `status` FROM `campaign_applications`
              WHERE `campaign_id` = :cid AND `creator_id` = :crid',
            ['cid' => $campaignId, 'crid' => $creatorId]
        );

        if ($existing !== null) {
            // Re-inviting somebody who already applied must not reset them to
            // 'invited' and lose their rate and pitch.
            return self::applications($campaignId)[0] ?? [];
        }

        Database::insert('campaign_applications', [
            'campaign_id'     => $campaignId,
            'creator_id'      => $creatorId,
            'creator_user_id' => (int) $creator['user_id'],
            'status'          => 'invited',
            'invited_at'      => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        NotificationService::send((int) $creator['user_id'], 'campaign.invited', [
            'campaign' => (string) $campaign['title'],
            'brand'    => (string) (Database::scalar(
                'SELECT COALESCE(`company_name`, `name`) FROM `users` WHERE `id` = :id',
                ['id' => $brandUserId]
            ) ?? 'A brand'),
        ], ['action_type' => 'url', 'action_value' => self::creatorLink($campaignId), 'actor_id' => $brandUserId]);

        self::recount($campaignId);

        return self::show($campaignId, $brandUserId);
    }

    /**
     * A creator applies to an open campaign.
     *
     * [$portfolioLinks] is optional and defaults to "none", so callers written
     * before it existed keep compiling and keep their behaviour.
     *
     * @param  list<string>|null $portfolioLinks already passed through
     *                                           {@see normalisePortfolioLinks()}
     * @return array<string,mixed>
     */
    public static function apply(
        int $campaignId,
        int $creatorUserId,
        int $creatorId,
        ?float $quotedAmount,
        ?string $pitch,
        ?array $portfolioLinks = null
    ): array {
        // An empty list is stored as NULL, the same "none supplied" every
        // application made before the column existed already carries.
        $linksJson = $portfolioLinks === null || $portfolioLinks === []
            ? null
            : (string) json_encode(array_values($portfolioLinks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // A suspended brand's brief is not on the board, so it cannot be
        // applied to either.
        $campaign = Database::selectOne(
            'SELECT c.* FROM `campaigns` c WHERE c.`id` = :id AND c.`deleted_at` IS NULL AND ' . self::ACTIVE_BRAND_SQL,
            ['id' => $campaignId]
        );

        if ($campaign === null) {
            throw HttpException::notFound('Campaign not found.');
        }

        if ((string) $campaign['status'] !== 'open') {
            throw HttpException::unprocessable('This campaign is not accepting applications.');
        }

        if ((int) $campaign['brand_user_id'] === $creatorUserId) {
            throw HttpException::forbidden('You cannot apply to your own campaign.');
        }

        BlockService::assertNotBlocked($creatorUserId, (int) $campaign['brand_user_id']);

        // A quote wildly outside the advertised budget wastes both parties'
        // time, so it is refused with the actual range rather than silently
        // accepted and ignored.
        if ($quotedAmount !== null && (float) $campaign['budget_max'] > 0
            && $quotedAmount > (float) $campaign['budget_max']) {
            throw HttpException::unprocessable(sprintf(
                'Your rate is above this campaign\'s budget of %s.',
                Money::format((float) $campaign['budget_max'], (string) $campaign['currency'])
            ));
        }

        $now = date('Y-m-d H:i:s');

        $existing = Database::selectOne(
            'SELECT `id`, `status` FROM `campaign_applications`
              WHERE `campaign_id` = :cid AND `creator_id` = :crid',
            ['cid' => $campaignId, 'crid' => $creatorId]
        );

        if ($existing !== null) {
            if (in_array((string) $existing['status'], ['hired', 'declined'], true)) {
                throw HttpException::conflict('This application has already been decided.');
            }

            // Converting an invitation into a real application, or updating a
            // quote before the brand has responded.
            Database::update('campaign_applications', [
                'status'          => 'applied',
                'quoted_amount'   => $quotedAmount,
                'pitch'           => $pitch,
                'portfolio_links' => $linksJson,
                'applied_at'      => $now,
            ], ['id' => (int) $existing['id']]);
        } else {
            Database::insert('campaign_applications', [
                'campaign_id'     => $campaignId,
                'creator_id'      => $creatorId,
                'creator_user_id' => $creatorUserId,
                'status'          => 'applied',
                'quoted_amount'   => $quotedAmount,
                'pitch'           => $pitch,
                'portfolio_links' => $linksJson,
                'applied_at'      => $now,
                'created_at'      => $now,
            ]);
        }

        NotificationService::send((int) $campaign['brand_user_id'], 'campaign.application', [
            'campaign' => (string) $campaign['title'],
            'creator'  => (string) (Database::scalar(
                'SELECT `display_name` FROM `creators` WHERE `id` = :id',
                ['id' => $creatorId]
            ) ?? 'A creator'),
        ], ['action_type' => 'url', 'action_value' => '/campaigns/' . $campaignId, 'actor_id' => $creatorUserId]);

        self::recount($campaignId);

        return self::applicationFor($campaignId, $creatorUserId) ?? [];
    }

    /**
     * The brand moves an application to shortlisted / hired / declined.
     *
     * @return array<string,mixed>
     */
    public static function decide(
        int $campaignId,
        int $applicationId,
        int $brandUserId,
        string $status,
        ?string $reason = null
    ): array {
        self::assertOwned($campaignId, $brandUserId);

        if (!in_array($status, self::BRAND_SETTABLE, true)) {
            throw HttpException::unprocessable(
                'A brand may only shortlist, hire or decline an application.'
            );
        }

        $application = Database::selectOne(
            'SELECT * FROM `campaign_applications`
              WHERE `id` = :id AND `campaign_id` = :cid AND `deleted_at` IS NULL',
            ['id' => $applicationId, 'cid' => $campaignId]
        );

        if ($application === null) {
            throw HttpException::notFound('Application not found.');
        }

        // Hiring somebody who never applied means agreeing to a rate they
        // never quoted.
        if ($status === 'hired' && (string) $application['status'] === 'invited') {
            throw HttpException::unprocessable(
                'This creator has not applied yet, so there is no rate to accept.'
            );
        }

        // Shortlisting and hiring move towards new work, which a block stops;
        // declining closes the application and is always allowed.
        if ($status !== 'declined') {
            BlockService::assertNotBlocked($brandUserId, (int) $application['creator_user_id']);
        }

        Database::update('campaign_applications', [
            'status'         => $status,
            'responded_at'   => date('Y-m-d H:i:s'),
            'decline_reason' => $status === 'declined' ? $reason : null,
        ], ['id' => $applicationId]);

        NotificationService::send(
            (int) $application['creator_user_id'],
            'campaign.' . $status,
            [
                'campaign' => (string) (Database::scalar(
                    'SELECT `title` FROM `campaigns` WHERE `id` = :id',
                    ['id' => $campaignId]
                ) ?? ''),
                'reason'   => $reason ?? '',
            ],
            ['action_type' => 'url', 'action_value' => self::creatorLink($campaignId), 'actor_id' => $brandUserId]
        );

        self::recount($campaignId);

        return self::show($campaignId, $brandUserId);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Loads a campaign and proves the caller owns it.
     *
     * Returns 404 rather than 403 for somebody else's campaign: a 403 would
     * confirm the id exists, which lets an attacker enumerate the table.
     *
     * @return array<string,mixed>
     */
    private static function assertOwned(int $campaignId, int $brandUserId): array
    {
        $campaign = Database::selectOne(
            'SELECT * FROM `campaigns` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $campaignId]
        );

        if ($campaign === null || (int) $campaign['brand_user_id'] !== $brandUserId) {
            throw HttpException::notFound('Campaign not found.');
        }

        return $campaign;
    }

    /**
     * @param array<string,mixed>      $data
     * @param array<string,mixed>|null $existing
     */
    private static function assertBudgetSane(array $data, ?array $existing = null): void
    {
        $min = array_key_exists('budget_min', $data)
            ? (float) $data['budget_min']
            : (float) ($existing['budget_min'] ?? 0);

        $max = array_key_exists('budget_max', $data)
            ? (float) $data['budget_max']
            : (float) ($existing['budget_max'] ?? 0);

        if ($min > 0 && $max > 0 && $min > $max) {
            throw HttpException::unprocessable('The minimum budget cannot exceed the maximum.');
        }
    }

    /**
     * Whitelists the columns a client may write.
     *
     * Explicitly excludes `status`, `code`, `brand_user_id`,
     * `applications_count` and `hired_count` — a client that could set those
     * could publish without validation, reassign a campaign, or fake its own
     * popularity.
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function writableColumns(array $data): array
    {
        $allowed = [
            'title', 'description', 'category_id', 'platform', 'deliverable_type',
            'posts_count', 'budget_min', 'budget_max', 'currency', 'brief_url',
            'starts_on', 'ends_on', 'is_public',
        ];

        $columns = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $columns[$key] = $data[$key];
            }
        }

        // Two JSON columns the client sends as arrays.
        foreach (['reference_urls', 'requirements'] as $key) {
            if (array_key_exists($key, $data)) {
                $columns[$key] = $data[$key] === null
                    ? null
                    : (string) json_encode($data[$key], JSON_UNESCAPED_UNICODE);
            }
        }

        return $columns;
    }

    /** Keeps the denormalised counters the list view reads in step. */
    private static function recount(int $campaignId): void
    {
        Database::execute(
            'UPDATE `campaigns` SET
                `applications_count` = (
                    SELECT COUNT(*) FROM `campaign_applications`
                     WHERE `campaign_id` = :id1
                       AND `status` IN ("applied", "shortlisted", "hired")
                       AND `deleted_at` IS NULL
                ),
                `hired_count` = (
                    SELECT COUNT(*) FROM `campaign_applications`
                     WHERE `campaign_id` = :id2 AND `status` = "hired" AND `deleted_at` IS NULL
                )
              WHERE `id` = :id3',
            ['id1' => $campaignId, 'id2' => $campaignId, 'id3' => $campaignId]
        );
    }

    private static function notifyApplicants(int $campaignId, string $outcome): void
    {
        $userIds = Database::column(
            'SELECT DISTINCT `creator_user_id` FROM `campaign_applications`
              WHERE `campaign_id` = :id AND `status` IN ("applied", "shortlisted")
                AND `deleted_at` IS NULL',
            ['id' => $campaignId]
        );

        $title = (string) (Database::scalar(
            'SELECT `title` FROM `campaigns` WHERE `id` = :id',
            ['id' => $campaignId]
        ) ?? '');

        foreach ($userIds as $userId) {
            NotificationService::send((int) $userId, 'campaign.' . $outcome, [
                'campaign' => $title,
            ], ['action_type' => 'url', 'action_value' => self::creatorLink($campaignId)]);
        }
    }

    /**
     * The in-app path a CREATOR's campaign notification opens.
     *
     * `/campaigns/{id}` is the brand's management screen; a creator tapping
     * "You've been shortlisted" belongs on the brief they applied to. Builds
     * that predate `/opportunities` do not recognise it and fall back to the
     * notification list — exactly what they did with `/campaigns/…`, which
     * they never recognised either. Brand-facing notifications keep
     * `/campaigns/{id}`.
     */
    private static function creatorLink(int $campaignId): string
    {
        return '/opportunities/' . $campaignId;
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id'                 => (int) $row['id'],
            'code'               => $row['code'],
            'title'              => $row['title'],
            'description'        => $row['description'],
            'status'             => $row['status'],
            'status_label'       => self::statusLabel((string) $row['status']),
            'category_id'        => $row['category_id'] === null ? null : (int) $row['category_id'],
            'category_name'      => $row['category_name'] ?? null,
            'platform'           => $row['platform'],
            'deliverable_type'   => $row['deliverable_type'],
            'posts_count'        => (int) $row['posts_count'],
            'budget_min'         => (float) $row['budget_min'],
            'budget_max'         => (float) $row['budget_max'],
            'budget_label'       => Money::formatRange(
                (float) $row['budget_min'],
                (float) $row['budget_max'],
                (string) $row['currency']
            ),
            'currency'           => $row['currency'],
            'brief_url'          => $row['brief_url'],
            'reference_urls'     => self::decodeJson($row['reference_urls'] ?? null),
            'requirements'       => self::decodeJson($row['requirements'] ?? null, true),
            'starts_on'          => $row['starts_on'],
            'ends_on'            => $row['ends_on'],
            'applications_count' => (int) $row['applications_count'],
            'hired_count'        => (int) $row['hired_count'],
            'is_public'          => (bool) $row['is_public'],
            'brand' => [
                'id'      => (int) $row['brand_user_id'],
                'name'    => $row['brand_company'] ?: ($row['brand_name'] ?? ''),
                'avatar_url' => $row['brand_avatar'] ?? null,
                // The admin-granted blue tick; never derived from KYC alone.
                'is_verified' => (bool) ($row['brand_verified'] ?? false),
            ],
            'created_at'         => $row['created_at'],
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'       => 'Draft',
            'open'        => 'Open',
            'in_progress' => 'In progress',
            'completed'   => 'Completed',
            'cancelled'   => 'Cancelled',
            'archived'    => 'Archived',
            default       => ucfirst($status),
        };
    }

    /** @return array<mixed> */
    private static function decodeJson(mixed $raw, bool $asObject = false): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return $asObject ? [] : [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

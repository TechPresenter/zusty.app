<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Core\Request;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;
use Zuste\Services\AuthService;
use Zuste\Services\CreatorBadgeService;
use Zuste\Services\KycService;
use Zuste\Services\CreatorProfileLockService;
use Zuste\Services\CreatorService;
use Zuste\Services\ExportService;
use Zuste\Services\NotificationService;
use Zuste\Services\WalletService;

/**
 * Users, creator profiles and KYC review.
 */
final class UserController extends BaseAdminController
{
    private const USER_SORTS    = ['id', 'name', 'email', 'created_at', 'last_login_at', 'status'];
    private const CREATOR_SORTS = ['id', 'display_name', 'rating_avg', 'followers_total', 'collabs_count', 'created_at', 'status'];

    /** Backstop for an export: past this a download should be a background job. */
    private const EXPORT_LIMIT = 10000;

    // =================================================================
    // Users
    // =================================================================

    /** GET /admin/users */
    public function index(Request $request): never
    {
        $this->authorize($request, 'users.view');

        $params   = $this->listParams($request, self::USER_SORTS, 'id');
        $clauses  = ['u.`deleted_at` IS NULL'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = "(u.`name` LIKE :search ESCAPE '\\\\' OR u.`email` LIKE :search ESCAPE '\\\\'
                                    OR u.`phone` LIKE :search ESCAPE '\\\\' OR u.`company_name` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        foreach (['status' => 'u.`status`', 'account_type' => 'u.`account_type`', 'kyc_status' => 'u.`kyc_status`'] as $key => $column) {
            if ($request->filled($key)) {
                $clauses[]        = $column . ' = :' . $key;
                $bindings[$key]   = $request->string($key);
            }
        }

        // Guests are accounts with no identity attached and there can be many
        // of them, so they are hidden from this screen unless explicitly asked
        // for — filter by account type = guest to inspect them.
        if (!$request->filled('account_type')) {
            $clauses[] = "u.`account_type` <> 'guest'";
        }

        if ($request->filled('city')) {
            $clauses[]        = 'u.`city` = :city';
            $bindings['city'] = $request->string('city');
        }

        $this->applyDateRange($clauses, $bindings, 'u.`created_at`', $params['from'], $params['to']);

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `users` u' . $where, $bindings);

        $listQuery = 'SELECT u.`id`, u.`uuid`, u.`name`, u.`email`, u.`phone_country_code`, u.`phone`, u.`avatar_url`,
                    u.`account_type`, u.`status`, u.`kyc_status`, u.`company_name`, u.`city`,
                    u.`email_verified_at`, u.`phone_verified_at`, u.`created_at`, u.`last_login_at`,
                    c.`id` AS `creator_id`, c.`handle`, c.`is_verified` AS `creator_verified`,
                    COALESCE(w.`balance`, 0) AS `wallet_balance`,
                    (SELECT COUNT(*) FROM `bookings` b
                      WHERE (b.`brand_user_id` = u.`id` OR b.`creator_user_id` = u.`id`) AND b.`deleted_at` IS NULL) AS `bookings_count`
             FROM `users` u
             LEFT JOIN `creators` c ON c.`user_id` = u.`id` AND c.`deleted_at` IS NULL
             LEFT JOIN `wallets` w ON w.`user_id` = u.`id`' . $where . '
             ORDER BY u.`' . $params['sort_by'] . '` ' . $params['sort_dir'];

        $exportAs = strtolower($request->string('export'));

        if (in_array($exportAs, ['csv', 'xlsx', 'pdf'], true)) {
            $this->authorize($request, 'export.data');

            // Re-query WITHOUT the page window. The export used to be handed the
            // paginated rows, so an operator who filtered 77 accounts and clicked
            // Export got the 25 on screen with nothing to say so. Same $where and
            // $bindings, so the file is exactly the filtered set — just complete.
            $exportRows = Database::select($listQuery . ' LIMIT ' . self::EXPORT_LIMIT, $bindings);

            $this->log(
                $request,
                'users.exported',
                'user',
                null,
                count($exportRows) . ' row(s) exported as ' . $exportAs
                    . (count($exportRows) === self::EXPORT_LIMIT ? ' (capped)' : '') . '.'
            );

            $this->exportList(
                $exportAs,
                'zuste-users',
                'Users',
                ['ID', 'Name', 'Email', 'Phone', 'Type', 'Status', 'KYC', 'City', 'Bookings', 'Wallet', 'Joined'],
                $exportRows,
                ['id', 'name', 'email', 'phone', 'account_type', 'status', 'kyc_status', 'city', 'bookings_count', 'wallet_balance', 'created_at']
            );
        }

        $rows = Database::select(
            $listQuery . ' LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $this->view($request, 'admin.users.index', [
            'pageTitle'    => 'Users',
            'pageSubtitle' => number_format($total) . ' accounts',
            'users'        => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
            'counts'       => $this->userCounts(),
        ]);
    }

    /** GET /admin/users/{id} */
    public function show(Request $request): never
    {
        $this->authorize($request, 'users.view');

        $userId = $request->routeParamInt('id');

        $user = Database::selectOne(
            'SELECT u.*, c.`id` AS `creator_id`, c.`handle`, c.`display_name`, c.`status` AS `creator_status`,
                    r.`name` AS `referrer_name`
             FROM `users` u
             LEFT JOIN `creators` c ON c.`user_id` = u.`id`
             LEFT JOIN `users` r ON r.`id` = u.`referred_by`
             WHERE u.`id` = :id',
            ['id' => $userId]
        );

        if ($user === null) {
            throw HttpException::notFound('User not found.');
        }

        unset($user['password_hash']);

        $canSeeKyc = $this->can($request, 'kyc.view');

        $this->view($request, 'admin.users.show', [
            'pageTitle'    => (string) $user['name'],
            'pageSubtitle' => ucfirst((string) $user['account_type']) . ' account · joined ' . date('d M Y', strtotime((string) $user['created_at'])),
            'user'         => $user,
            'wallet'       => WalletService::balance($userId),
            'bookings'     => Database::select(
                'SELECT b.`id`, b.`code`, b.`title`, b.`status`, b.`total_amount`, b.`currency`, b.`created_at`
                 FROM `bookings` b
                 WHERE (b.`brand_user_id` = :id OR b.`creator_user_id` = :id) AND b.`deleted_at` IS NULL
                 ORDER BY b.`id` DESC LIMIT 15',
                ['id' => $userId]
            ),
            'transactions' => Database::select(
                'SELECT * FROM `wallet_transactions` WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 15',
                ['id' => $userId]
            ),
            // Which identity documents a person submitted, and their review
            // state, is KYC data: only reviewers holding kyc.view may see it.
            // Everyone else with users.view (finance, support, moderators) gets
            // nothing, and the card is not drawn.
            'kyc'          => $canSeeKyc
                ? Database::select(
                    'SELECT * FROM `kyc_documents` WHERE `user_id` = :id ORDER BY `id` DESC',
                    ['id' => $userId]
                )
                : [],
            'devices'      => Database::select(
                'SELECT `platform`, `device_model`, `os_version`, `app_version`, `last_active_at`
                 FROM `user_devices` WHERE `user_id` = :id ORDER BY `last_active_at` DESC LIMIT 10',
                ['id' => $userId]
            ),
            'activity'     => Database::select(
                "SELECT * FROM `activity_logs`
                 WHERE `actor_type` = 'user' AND `actor_id` = :id ORDER BY `id` DESC LIMIT 20",
                ['id' => $userId]
            ),
        ]);
    }

    /** POST /admin/users/{id}/status */
    public function updateStatus(Request $request): never
    {
        $this->authorize($request, 'users.edit');

        $data = $this->validate($request, [
            'status' => 'required|in:active,suspended,banned,pending',
            'reason' => 'nullable|string|max:255',
        ]);

        $userId = $request->routeParamInt('id');
        $before = Database::selectOne('SELECT `status`, `name` FROM `users` WHERE `id` = :id', ['id' => $userId]);

        if ($before === null) {
            throw HttpException::notFound('User not found.');
        }

        Database::update('users', [
            'status'           => (string) $data['status'],
            'suspended_reason' => $data['reason'] ?? null,
        ], ['id' => $userId]);

        // Suspending or banning must terminate every live session immediately.
        if (in_array($data['status'], ['suspended', 'banned'], true)) {
            AuthService::revokeAllTokens($userId, 'account_' . (string) $data['status']);
        }

        $this->log(
            $request,
            'user.status_changed',
            'user',
            $userId,
            sprintf('%s → %s', (string) $before['status'], (string) $data['status']),
            ['reason' => $data['reason'] ?? null]
        );

        $this->respond($request, 'User status updated to ' . (string) $data['status'] . '.');
    }

    /** POST /admin/users/{id}/wallet-adjust */
    public function adjustWallet(Request $request): never
    {
        $this->authorize($request, 'wallet.adjust');

        $data = $this->validate($request, [
            'direction'  => 'required|in:credit,debit',
            'amount'     => 'required|numeric|min_value:1',
            'description' => 'required|string|min:3|max:255',
        ]);

        $userId  = $request->routeParamInt('id');
        $adminId = $this->adminId($request);

        $user = Database::selectOne(
            'SELECT `id`, `name`, `deleted_at` FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        );

        if ($user === null) {
            throw HttpException::notFound('User not found.');
        }

        if ($user['deleted_at'] !== null) {
            $this->back('error', 'This account is in the recycle bin. Restore it before adjusting its wallet.');
        }

        $options = [
            'category'          => 'adjustment',
            'description'       => (string) $data['description'],
            'reference_type'    => 'manual',
            'performed_by_type' => 'admin',
            'performed_by_id'   => $adminId,
        ];

        $transaction = $data['direction'] === 'credit'
            ? WalletService::credit($userId, (float) $data['amount'], $options)
            : WalletService::debit($userId, (float) $data['amount'], $options);

        $this->log(
            $request,
            'wallet.adjusted',
            'user',
            $userId,
            sprintf('%s %s', (string) $data['direction'], Money::format((float) $data['amount'])),
            ['reference' => $transaction['reference'], 'description' => $data['description']]
        );

        NotificationService::send(
            $userId,
            $data['direction'] === 'credit' ? 'wallet.credited' : 'wallet.debited',
            [
                'amount'      => Money::groupIndian((float) $data['amount'], 0),
                'currency'    => Money::symbol(),
                'description' => (string) $data['description'],
            ],
            ['action_type' => 'wallet']
        );

        $this->respond($request, 'Wallet adjusted. Reference ' . (string) $transaction['reference'] . '.');
    }

    /** POST /admin/users/{id}/delete */
    public function destroy(Request $request): never
    {
        $this->authorize($request, 'users.delete');

        $userId = $request->routeParamInt('id');

        $openBookings = (int) Database::scalar(
            "SELECT COUNT(*) FROM `bookings`
             WHERE (`brand_user_id` = :id OR `creator_user_id` = :id)
               AND `status` IN ('pending','countered','accepted','awaiting_payment','confirmed','in_progress','delivered','disputed')
               AND `deleted_at` IS NULL",
            ['id' => $userId]
        );

        if ($openBookings > 0) {
            $this->back('error', 'This user has ' . $openBookings . ' booking(s) in progress. Resolve them first.');
        }

        // Identity documents do not outlive the account. Erased FIRST, so a
        // failure leaves the account as it was and the operator can simply try
        // again (purgeForUser is idempotent) — the other order could close the
        // account while its Aadhaar and PAN images stayed in storage, listed
        // and previewable in the KYC panel. The profile row and its event
        // trail are kept as the record that a verification happened.
        try {
            $kycPurge = KycService::purgeForUser($userId, 'Account deleted by an administrator.');
        } catch (\Throwable $e) {
            Logger::error('Could not erase KYC data while deleting a user', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);

            $this->back('error', 'The identity documents could not be erased, so the account was not deleted. Try again.');
        }

        Database::transaction(static function () use ($userId): void {
            Database::update('users', [
                'status'     => 'deleted',
                'deleted_at' => date('Y-m-d H:i:s'),
            ], ['id' => $userId]);

            AuthService::revokeAllTokens($userId, 'deleted_by_admin');
            Database::execute('DELETE FROM `user_devices` WHERE `user_id` = :id', ['id' => $userId]);
        });

        $this->log($request, 'user.deleted', 'user', $userId, 'Account deleted by an administrator.', [
            'kyc_profiles_purged'  => $kycPurge['profiles'],
            'kyc_documents_purged' => $kycPurge['documents'],
        ]);

        $this->back(
            'success',
            $kycPurge['documents'] > 0 || $kycPurge['profiles'] > 0
                ? 'User account deleted. Their identity documents were erased.'
                : 'User account deleted.',
            '/admin/users'
        );
    }

    // =================================================================
    // Creators
    // =================================================================

    /** GET /admin/creators */
    public function creators(Request $request): never
    {
        $this->authorize($request, 'creators.view');

        $params   = $this->listParams($request, self::CREATOR_SORTS, 'id');
        $clauses  = ['c.`deleted_at` IS NULL'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = "(c.`display_name` LIKE :search ESCAPE '\\\\' OR c.`handle` LIKE :search ESCAPE '\\\\'
                                    OR u.`email` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        if ($request->filled('status')) {
            $clauses[]          = 'c.`status` = :status';
            $bindings['status'] = $request->string('status');
        }

        if ($request->filled('category_id')) {
            $clauses[]               = 'c.`primary_category_id` = :category_id';
            $bindings['category_id'] = $request->int('category_id');
        }

        if ($request->bool('verified_only')) {
            $clauses[] = 'c.`is_verified` = 1';
        }

        if ($request->bool('featured_only')) {
            $clauses[] = 'c.`is_featured` = 1';
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creators` c INNER JOIN `users` u ON u.`id` = c.`user_id`' . $where,
            $bindings
        );

        $listQuery = 'SELECT c.`id`, c.`user_id`, c.`handle`, c.`display_name`, c.`headline`, c.`city`,
                    c.`price_min`, c.`price_max`, c.`currency`, c.`rating_avg`, c.`rating_count`,
                    c.`followers_total`, c.`collabs_count`, c.`is_verified`, c.`is_featured`,
                    c.`status`, c.`profile_score`, c.`created_at`,
                    u.`avatar_url`, u.`email`, u.`status` AS `user_status`,
                    cat.`name` AS `category_name`
             FROM `creators` c
             INNER JOIN `users` u ON u.`id` = c.`user_id`
             LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`' . $where . '
             ORDER BY c.`' . $params['sort_by'] . '` ' . $params['sort_dir'];

        $exportAs = strtolower($request->string('export'));

        if (in_array($exportAs, ['csv', 'xlsx', 'pdf'], true)) {
            $this->authorize($request, 'export.data');

            // Same reasoning as the users list: export the filtered set, not the
            // page that happens to be on screen.
            $exportRows = Database::select($listQuery . ' LIMIT ' . self::EXPORT_LIMIT, $bindings);

            $this->log(
                $request,
                'creators.exported',
                'creator',
                null,
                count($exportRows) . ' row(s) exported as ' . $exportAs
                    . (count($exportRows) === self::EXPORT_LIMIT ? ' (capped)' : '') . '.'
            );

            $this->exportList(
                $exportAs,
                'zuste-creators',
                'Creators',
                ['ID', 'Name', 'Handle', 'Email', 'Category', 'City', 'Rating', 'Reviews', 'Followers', 'Collabs', 'Status', 'Verified'],
                $exportRows,
                ['id', 'display_name', 'handle', 'email', 'category_name', 'city', 'rating_avg', 'rating_count', 'followers_total', 'collabs_count', 'status', 'is_verified']
            );
        }

        $rows = Database::select(
            $listQuery . ' LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $this->view($request, 'admin.creators.index', [
            'pageTitle'    => 'Creators',
            'pageSubtitle' => number_format($total) . ' profiles',
            'creators'     => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
            'categories'   => Database::select('SELECT `id`, `name` FROM `categories` WHERE `is_active` = 1 ORDER BY `sort_order`'),
            'counts'       => $this->creatorCounts(),
        ]);
    }

    /** GET /admin/creators/{id} */
    public function creatorShow(Request $request): never
    {
        $this->authorize($request, 'creators.view');

        $creatorId = $request->routeParamInt('id');

        $creator = Database::selectOne(
            'SELECT c.*, u.`name` AS `account_name`, u.`email`, u.`phone`, u.`phone_country_code`,
                    u.`avatar_url`, u.`status` AS `user_status`, u.`kyc_status`, u.`created_at` AS `joined_at`,
                    cat.`name` AS `category_name`,
                    a.`name` AS `approved_by_name`
             FROM `creators` c
             INNER JOIN `users` u ON u.`id` = c.`user_id`
             LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`
             LEFT JOIN `admins` a ON a.`id` = c.`approved_by`
             WHERE c.`id` = :id',
            ['id' => $creatorId]
        );

        if ($creator === null) {
            throw HttpException::notFound('Creator not found.');
        }

        $creator['languages'] = json_decode((string) ($creator['languages'] ?? '[]'), true) ?: [];

        $this->view($request, 'admin.creators.show', [
            'pageTitle'    => (string) $creator['display_name'],
            'pageSubtitle' => '@' . (string) $creator['handle'] . ' · ' . ucfirst(str_replace('_', ' ', (string) $creator['status'])),
            'creator'      => $creator,
            // Verification badges and per-section locks. Both tables were built
            // in migration 029 and had no screen until now.
            'badges'       => CreatorBadgeService::adminOverview($creatorId),
            'badgeHistory' => CreatorBadgeService::history($creatorId, 25),
            'locks'        => CreatorProfileLockService::overview($creatorId),
            'categories'   => Database::select(
                'SELECT cat.`name`, cat.`slug` FROM `creator_categories` cc
                 INNER JOIN `categories` cat ON cat.`id` = cc.`category_id`
                 WHERE cc.`creator_id` = :id',
                ['id' => $creatorId]
            ),
            'socials'      => Database::select(
                'SELECT * FROM `creator_social_accounts` WHERE `creator_id` = :id ORDER BY `followers` DESC',
                ['id' => $creatorId]
            ),
            'packages'     => Database::select(
                'SELECT * FROM `creator_packages` WHERE `creator_id` = :id AND `deleted_at` IS NULL ORDER BY `price`',
                ['id' => $creatorId]
            ),
            'portfolio'    => Database::select(
                'SELECT * FROM `creator_portfolio` WHERE `creator_id` = :id AND `deleted_at` IS NULL
                 ORDER BY `id` DESC LIMIT 24',
                ['id' => $creatorId]
            ),
            'bookings'     => Database::select(
                'SELECT `id`, `code`, `title`, `status`, `total_amount`, `creator_earning`, `currency`, `created_at`
                 FROM `bookings` WHERE `creator_id` = :id AND `deleted_at` IS NULL
                 ORDER BY `id` DESC LIMIT 15',
                ['id' => $creatorId]
            ),
            'reviews'      => Database::select(
                'SELECT r.*, u.`name` AS `reviewer_name` FROM `reviews` r
                 INNER JOIN `users` u ON u.`id` = r.`reviewer_id`
                 WHERE r.`creator_id` = :id AND r.`deleted_at` IS NULL
                 ORDER BY r.`id` DESC LIMIT 10',
                ['id' => $creatorId]
            ),
            'portfolioCounts' => CreatorService::portfolioCounts($creatorId),
        ]);
    }

    /** POST /admin/creators/{id}/moderate */
    public function moderateCreator(Request $request): never
    {
        $this->authorize($request, 'creators.approve');

        $data = $this->validate($request, [
            'action' => 'required|in:approve,reject,suspend,reinstate',
            'reason' => 'nullable|string|max:500',
        ]);

        $creatorId = $request->routeParamInt('id');

        $creator = Database::selectOne('SELECT * FROM `creators` WHERE `id` = :id', ['id' => $creatorId]);

        if ($creator === null) {
            throw HttpException::notFound('Creator not found.');
        }

        $action = (string) $data['action'];
        $reason = (string) ($data['reason'] ?? '');

        if (in_array($action, ['reject', 'suspend'], true) && $reason === '') {
            $this->back('error', 'Give the creator a reason so they know what to fix.');
        }

        $status = match ($action) {
            'approve'   => 'approved',
            'reject'    => 'rejected',
            'suspend'   => 'suspended',
            'reinstate' => 'approved',
        };

        Database::update('creators', [
            'status'           => $status,
            'rejection_reason' => in_array($action, ['reject', 'suspend'], true) ? $reason : null,
            'approved_by'      => $status === 'approved' ? $this->adminId($request) : null,
            'approved_at'      => $status === 'approved' ? date('Y-m-d H:i:s') : null,
        ], ['id' => $creatorId]);

        CreatorService::refreshStatistics($creatorId);

        NotificationService::send(
            (int) $creator['user_id'],
            $status === 'approved' ? 'creator.approved' : 'creator.rejected',
            ['reason' => $reason],
            ['action_type' => 'creator', 'action_value' => (string) $creatorId]
        );

        $this->log($request, 'creator.' . $action, 'creator', $creatorId, ucfirst($action) . 'd creator profile.', ['reason' => $reason]);

        $this->respond($request, 'Creator ' . $action . 'd.');
    }

    /** POST /admin/creators/{id}/flags */
    public function toggleCreatorFlags(Request $request): never
    {
        $this->authorize($request, 'creators.verify');

        $creatorId = $request->routeParamInt('id');
        $updates   = [];

        $creator = Database::selectOne(
            'SELECT `id`, `user_id`, `is_verified`, `is_featured`, `commission_rate` FROM `creators` WHERE `id` = :id',
            ['id' => $creatorId]
        );

        if ($creator === null) {
            throw HttpException::notFound('Creator not found.');
        }

        // The commission is read first, so a bad value refuses the whole save
        // before any badge has moved.
        //
        // Blank means "no override": NULL, and the platform rate applies. This
        // used to go through Request::float(), which reads '' as 0.0 — and the
        // number input is always posted, empty when blank — so saving this form
        // in exactly the state its hint asks for ("Leave blank to use the
        // platform default") gave the creator a 0% platform commission.
        if ($request->has('commission_rate')) {
            $raw = $request->string('commission_rate');

            if ($raw === '') {
                $rate = null;
            } elseif (is_numeric($raw) && (float) $raw >= 0 && (float) $raw <= 100) {
                $rate = round((float) $raw, 2);
            } else {
                $this->back('error', 'The commission override must be a number from 0 to 100, or blank to use the platform rate. Nothing was saved.');
            }

            $current = $creator['commission_rate'] === null ? null : round((float) $creator['commission_rate'], 2);

            // Written only when it actually changes, so re-saving the form does
            // not stamp the activity log with a commission nobody touched.
            if ($rate !== $current) {
                $updates['commission_rate'] = $rate;
            }
        }

        // These two switches predate the badge system, and both representations
        // are now live: `is_verified` / `is_featured` are what discovery and
        // search rank on, while `creator_badges` carries the award date,
        // verifier, expiry and history. Writing the column directly here would
        // leave the badge saying one thing and the column another.
        //
        // So the switches drive the badge, and CreatorBadgeService writes the
        // column back. The screen is unchanged; the two agree afterwards.
        $badgeContext = [
            'actor_type' => 'admin',
            'actor_id'   => $this->adminId($request),
            'actor_name' => (string) ($this->admin($request)['name'] ?? ''),
            'ip'         => $request->ip(),
        ];

        // Which switches actually moved, for the activity log.
        $flagChanges = [];

        // The form posts every switch on every save (each checkbox has a hidden
        // "0" in front of it, so an unticked switch says so instead of saying
        // nothing). A switch therefore acts only when its posted state differs
        // from what the creator shows now. Before that, a moderator changing
        // only the commission on a ticked creator re-posted is_verified=1 and
        // was bounced for lacking kyc.badge, or hit grantBadge()'s KYC check,
        // and lost the commission edit with it — while unticking posted nothing
        // at all, so the tick could never be removed from here.
        //
        // "Verified" IS the blue tick, and the blue tick has exactly one path:
        // KycService::grantBadge() / revokeBadge(), which insist on an approved
        // KYC and keep the profile, users.has_blue_tick, the creator_verified
        // badge and creators.is_verified in agreement. Awarding the badge
        // directly here used to put a tick on accounts nobody had verified.
        if ($request->has('is_verified')) {
            $verified = $request->bool('is_verified');

            if ($verified !== ((int) $creator['is_verified'] === 1)) {
                if (!$this->can($request, 'kyc.badge')) {
                    $this->back('error', 'Changing the blue tick needs the blue-tick permission. Nothing was saved.');
                }

                $profileId = KycService::creatorProfileId($creatorId);
                $context   = ['ip' => $request->ip(), 'agent' => $request->userAgent()];

                if ($profileId === null) {
                    if ($verified) {
                        $this->back('error', 'This creator has not completed KYC. The blue tick can only be granted after KYC is approved. Nothing was saved.');
                    }

                    // No KYC profile to revoke through, so this is a tick that
                    // predates the badge model. It must still be removable: the
                    // badge row (if any) and every column that draws a tick.
                    $userId = (int) $creator['user_id'];

                    Database::transaction(static function () use ($creatorId, $userId, $badgeContext): void {
                        CreatorBadgeService::revoke($creatorId, 'creator_verified', $badgeContext + [
                            'reason' => 'Verification withdrawn from the creator screen.',
                        ]);
                        Database::update('creators', ['is_verified' => 0, 'verified_at' => null], ['id' => $creatorId]);
                        Database::update('users', ['has_blue_tick' => 0, 'blue_tick_at' => null], ['id' => $userId]);
                    });
                } else {
                    try {
                        $verified
                            ? KycService::grantBadge($profileId, $this->adminId($request), 'Granted from the creator screen.', $context)
                            : KycService::revokeBadge($profileId, $this->adminId($request), 'Withdrawn from the creator screen.', $context);
                    } catch (HttpException $e) {
                        $this->back('error', $e->getMessage() . ' Nothing was saved.');
                    }
                }

                $flagChanges['is_verified'] = $verified ? 1 : 0;
            }
        }

        if ($request->has('is_featured')) {
            $featured = $request->bool('is_featured');

            if ($featured !== ((int) $creator['is_featured'] === 1)) {
                $this->authorize($request, 'creators.feature');

                if ($featured) {
                    CreatorBadgeService::award($creatorId, 'featured_creator', $badgeContext + [
                        'note' => 'Featured from the creator screen.',
                    ]);
                } else {
                    CreatorBadgeService::revoke($creatorId, 'featured_creator', $badgeContext + [
                        'reason' => 'Featuring withdrawn from the creator screen.',
                    ]);

                    // revoke() is a no-op without a badge row; a featured flag
                    // that predates the badge table must still come off.
                    Database::update('creators', ['is_featured' => 0, 'featured_until' => null], ['id' => $creatorId]);
                }

                $flagChanges['is_featured'] = $featured ? 1 : 0;
            }
        }

        if ($updates === [] && $flagChanges === []) {
            $this->back('info', 'Nothing changed.');
        }

        // Only the commission override still writes a column from here; the two
        // flags are written by the badge services above.
        if ($updates !== []) {
            Database::update('creators', $updates, ['id' => $creatorId]);
        }

        $this->log($request, 'creator.flags_updated', 'creator', $creatorId, 'Updated creator flags.', $updates + $flagChanges);

        $this->respond($request, 'Creator updated.');
    }

    // =================================================================
    // KYC
    // =================================================================

    /**
     * The old legacy-document list.
     *
     * No longer routed: GET /admin/kyc-legacy is served by
     * KycController::legacy(). Kept (not removed) and held to the same rules
     * — kyc.view, legacy documents only — so re-pointing a route at it can
     * never re-open the leak it had.
     */
    public function kyc(Request $request): never
    {
        $this->authorize($request, 'users.kyc');
        $this->authorize($request, 'kyc.view');

        $params = $this->listParams($request, ['id', 'created_at', 'status'], 'id');

        // Legacy documents only — the ones the retired single-document flow
        // wrote, which belong to no verification profile. Without this the
        // page listed the verification centre's documents too (applicant,
        // email, document type, the reviewer's remarks) to anyone holding
        // users.kyc, a permission the manager role has without kyc.view.
        // Documents that belong to a profile are seen in the KYC panel, under
        // its own permissions.
        $clauses  = ['k.`profile_id` IS NULL'];
        $bindings = [];

        $status = $request->string('status', 'pending');

        if ($status !== 'all') {
            $clauses[]          = 'k.`status` = :status';
            $bindings['status'] = $status;
        }

        if ($params['search'] !== '') {
            $clauses[]          = "(u.`name` LIKE :search ESCAPE '\\\\' OR u.`email` LIKE :search ESCAPE '\\\\' OR k.`holder_name` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `kyc_documents` k INNER JOIN `users` u ON u.`id` = k.`user_id`' . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT k.*, u.`name` AS `user_name`, u.`email`, u.`avatar_url`, u.`account_type`
             FROM `kyc_documents` k
             INNER JOIN `users` u ON u.`id` = k.`user_id`' . $where . '
             ORDER BY k.`' . $params['sort_by'] . '` ' . $params['sort_dir'] . '
             LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        // Decrypt the document number only for the reviewer's eyes, and mask
        // all but the last four characters.
        foreach ($rows as &$row) {
            $plain                     = Crypto::decryptNullable($row['document_number'] ?? null);
            $row['document_number']    = $plain === null ? '—' : Crypto::mask($plain, 4);
        }
        unset($row);

        $this->view($request, 'admin.kyc.legacy', [
            'pageTitle'    => 'KYC verification',
            'pageSubtitle' => number_format($total) . ' submissions',
            'documents'    => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
        ]);
    }

    /**
     * POST /admin/kyc-legacy/{id}/review — retired.
     *
     * This approved a single legacy document straight to `kyc_status =
     * verified`, outside the verification state machine: no completeness
     * check, no audit event, no profile. Decisions are made in the KYC panel
     * (/admin/kyc), which is the only path that can approve an account.
     */
    public function reviewKyc(Request $request): never
    {
        $this->authorize($request, 'users.kyc');

        $this->back(
            'error',
            'The legacy queue is read-only. Review verifications in the KYC panel.',
            '/admin/kyc'
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Write a list export in the format the toolbar asked for.
     *
     * The list toolbar offers CSV, Excel and PDF and states that all three
     * cover the current filter set. Only CSV was wired up here, so ?export=xlsx
     * and ?export=pdf fell through and re-rendered the HTML list — the operator
     * clicked Excel and got a web page. Flattening once means a value can never
     * appear in one format and not another.
     *
     * @param list<string>              $labels
     * @param list<array<string,mixed>> $rows
     * @param list<string>              $columns
     */
    private function exportList(
        string $format,
        string $filename,
        string $title,
        array $labels,
        array $rows,
        array $columns
    ): never {
        if ($format === 'csv') {
            $this->exportCsv($filename, $labels, $rows, $columns);
        }

        $matrix = [];

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $value  = $row[$column] ?? '';
                $line[] = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
            }

            $matrix[] = $line;
        }

        if ($format === 'xlsx') {
            ExportService::xlsx($filename, $title, $labels, $matrix);
        }

        // Delivered as a print-ready A4 page that fires the browser's print
        // dialog, exactly as ResourceController does it.
        ExportService::pdf(
            $title,
            $labels,
            $matrix,
            'Exported ' . date('d M Y, g:i A') . ' — ' . count($matrix) . ' row(s)'
        );
    }

    /** @return array<string,int> */
    private function userCounts(): array
    {
        $rows = Database::select(
            'SELECT `status`, `account_type`, COUNT(*) AS `total`
             FROM `users` WHERE `deleted_at` IS NULL GROUP BY `status`, `account_type`'
        );

        $counts = ['all' => 0, 'active' => 0, 'suspended' => 0, 'banned' => 0, 'brand' => 0, 'creator' => 0];

        foreach ($rows as $row) {
            $total = (int) $row['total'];

            $counts['all']                              += $total;
            $counts[(string) $row['status']]             = ($counts[(string) $row['status']] ?? 0) + $total;
            $counts[(string) $row['account_type']]       = ($counts[(string) $row['account_type']] ?? 0) + $total;
        }

        return $counts;
    }

    /** @return array<string,int> */
    private function creatorCounts(): array
    {
        $rows = Database::select(
            'SELECT `status`, COUNT(*) AS `total` FROM `creators` WHERE `deleted_at` IS NULL GROUP BY `status`'
        );

        $counts = ['all' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
            $counts['all']                  += (int) $row['total'];
        }

        return $counts;
    }
}

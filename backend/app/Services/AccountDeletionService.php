<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Jwt;
use Zuste\Core\Logger;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * "Delete my account": a self-service erasure for brands and creators.
 *
 * This is not a soft-delete that hides a row. Personal data is removed, or
 * anonymised where a record has to survive. The `users` row itself stays as
 * an anonymous tombstone ("Deleted user", no email, phone, password or
 * photo), because bookings, payments, invoices, refunds and the wallet
 * ledger must keep pointing at something: Indian tax and accounting law
 * requires those records to be retained, and 031 made their foreign keys
 * RESTRICT so nothing could cascade them away.
 *
 * What goes and what stays is listed in {@see ERASED} and {@see RETAINED}.
 * The app shows those lists before the user confirms, so the screen and the
 * behaviour cannot drift apart.
 *
 * Order matters:
 *   1. Refuse while anything is still in motion: open bookings, money in the
 *      wallet, a withdrawal or refund in flight ({@see blockers()}).
 *   2. One transaction erases and anonymises every row.
 *   3. Only after it commits: delete files, the KYC module's purge, the
 *      Firebase Auth record, revoke this access token, send the goodbye
 *      email. Each is best-effort and logged. None of them can roll back an
 *      erasure that has already happened.
 */
final class AccountDeletionService
{
    /** Booking statuses where work or money is still in motion. */
    private const OPEN_BOOKING_STATUSES = [
        'pending', 'countered', 'accepted', 'awaiting_payment', 'confirmed', 'in_progress', 'delivered', 'disputed',
    ];

    /**
     * Upload folders whose files belong to records that are kept: booking
     * deliverables and chat attachments are the other party's record too,
     * and KYC files are the KYC module's to retain or purge.
     */
    private const RETAINED_MEDIA_FOLDERS = ['deliverables', 'chat', 'kyc'];

    /** @var list<array{key:string,title:string,detail:string}> */
    private const ERASED = [
        ['key' => 'profile',  'title' => 'Your profile',
         'detail' => 'Name, photo, bio, city, company details, and every email address and phone number on your account.'],
        ['key' => 'creator',  'title' => 'Your creator profile',
         'detail' => 'Public profile, portfolio, packages, social accounts, credentials, experience, FAQs and availability. It leaves search straight away.'],
        ['key' => 'campaigns', 'title' => 'Your campaigns',
         'detail' => 'Open and draft campaigns are closed and removed, and their applicants are told the campaign has closed.'],
        ['key' => 'activity', 'title' => 'Saved items and activity',
         'detail' => 'Saved creators, follows, search history and notifications.'],
        ['key' => 'signin',   'title' => 'Sign-in and devices',
         'detail' => 'Your password, linked Google or Apple sign-in, and every session and device. You are signed out everywhere.'],
        ['key' => 'payouts',  'title' => 'Payout details',
         'detail' => 'Saved bank accounts and UPI IDs.'],
        ['key' => 'files',    'title' => 'Files you uploaded',
         'detail' => 'Photos, videos and documents, except files attached to bookings or sent in chat.'],
        ['key' => 'kyc',      'title' => 'Identity documents',
         'detail' => 'KYC document images and numbers are deleted, except a minimal record we must keep for the legal period.'],
        ['key' => 'exports',  'title' => 'Data exports',
         'detail' => 'Any copies of your data you downloaded from Zusty, and their links.'],
    ];

    /** @var list<array{key:string,title:string,detail:string}> */
    private const RETAINED = [
        ['key' => 'financial', 'title' => 'Bookings, payments and invoices',
         'detail' => 'Kept for up to 8 years, as Indian tax and accounting law requires, showing "Deleted user" instead of your name. This includes the wallet ledger, refunds and payouts.'],
        ['key' => 'messages',  'title' => 'Messages you sent',
         'detail' => 'They stay in the other person\'s conversation, shown as from "Deleted user". They are part of that person\'s record and may be needed to resolve a dispute.'],
        ['key' => 'reviews',   'title' => 'Reviews you wrote',
         'detail' => 'They stay on the creator\'s or brand\'s page without your name or photo, so ratings stay honest.'],
        ['key' => 'safety',    'title' => 'Support tickets and reports',
         'detail' => 'Kept without your name, for safety, fraud prevention and legal claims.'],
        ['key' => 'security',  'title' => 'Security logs',
         'detail' => 'Sign-in and account activity logs, including IP addresses, kept to detect fraud and abuse.'],
        ['key' => 'record',    'title' => 'A deletion record',
         'detail' => 'That an account was deleted, when, and the reason you chose, with no name or contact details.'],
    ];

    // -----------------------------------------------------------------
    // Before
    // -----------------------------------------------------------------

    /**
     * Everything deletion erases and keeps, for the public account-deletion
     * page (Web\PageController), which must say the same as the app.
     *
     * @return array{erased:list<array{key:string,title:string,detail:string}>,retained:list<array{key:string,title:string,detail:string}>}
     */
    public static function policy(): array
    {
        return ['erased' => self::ERASED, 'retained' => self::RETAINED];
    }

    /**
     * What the deletion screen shows before the user commits.
     *
     * @param  array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function preflight(array $user): array
    {
        $userId      = (int) $user['id'];
        $accountType = (string) ($user['account_type'] ?? 'brand');
        $blockers    = self::blockers($userId);

        $erased = array_values(array_filter(
            self::ERASED,
            static fn (array $item): bool => match ($item['key']) {
                'creator'   => $accountType === 'creator',
                'campaigns' => $accountType === 'brand',
                default     => true,
            }
        ));

        return [
            'can_delete'      => $blockers === [],
            'blockers'        => $blockers,
            'confirm_phrase'  => PrivacyService::CONFIRM_PHRASE,
            'reasons'         => self::reasons($accountType),
            'erased'          => $erased,
            'retained'        => self::RETAINED,
            'retained_counts' => self::retainedCounts($userId),
        ];
    }

    /**
     * Optional reasons for leaving. The code is stored, the label is shown.
     *
     * @return list<array{code:string,label:string}>
     */
    public static function reasons(string $accountType): array
    {
        $reasons = [
            'not_useful'    => 'I don\'t use Zusty any more',
            'opportunities' => $accountType === 'creator'
                ? 'Not enough bookings or opportunities'
                : 'I couldn\'t find the right creators',
            'fees'          => 'The fees are too high',
            'privacy'       => 'I\'m worried about my privacy',
            'notifications' => 'Too many emails or notifications',
            'experience'    => 'I had a bad experience',
            'other_account' => 'I have another account',
            'other'         => 'Something else',
        ];

        $list = [];

        foreach ($reasons as $code => $label) {
            $list[] = ['code' => $code, 'label' => $label];
        }

        return $list;
    }

    /**
     * Everything that must be settled first, each with what to do about it.
     *
     * Deleting mid-booking would strand the other party with work or money
     * in limbo, and deleting with money in the wallet would forfeit it. So
     * the account waits, and the app shows the user exactly why.
     *
     * @return list<array{code:string,title:string,message:string,action:string,count?:int,amount?:float}>
     */
    public static function blockers(int $userId): array
    {
        $blockers     = [];
        $placeholders = "'" . implode("','", self::OPEN_BOOKING_STATUSES) . "'";

        $openBookings = (int) Database::scalar(
            "SELECT COUNT(*) FROM `bookings`
              WHERE (`brand_user_id` = :id OR `creator_user_id` = :id)
                AND `status` IN ({$placeholders}) AND `deleted_at` IS NULL",
            ['id' => $userId]
        );

        if ($openBookings > 0) {
            $blockers[] = [
                'code'    => 'open_bookings',
                'title'   => 'Bookings in progress',
                'message' => $openBookings === 1
                    ? 'You have a booking in progress. Complete or cancel it first.'
                    : "You have {$openBookings} bookings in progress. Complete or cancel them first.",
                'action'  => 'bookings',
                'count'   => $openBookings,
            ];
        }

        $openDisputes = (int) Database::scalar(
            "SELECT COUNT(*) FROM `booking_disputes` d
               JOIN `bookings` b ON b.`id` = d.`booking_id`
              WHERE (b.`brand_user_id` = :id OR b.`creator_user_id` = :id)
                AND d.`status` IN ('open', 'under_review')
                AND b.`status` NOT IN ({$placeholders})",
            ['id' => $userId]
        );

        if ($openDisputes > 0) {
            $blockers[] = [
                'code'    => 'open_disputes',
                'title'   => 'A dispute is being reviewed',
                'message' => 'Our team is still reviewing a dispute on one of your bookings. You can delete your account once it is resolved.',
                'action'  => 'bookings',
                'count'   => $openDisputes,
            ];
        }

        $wallet = WalletService::balance($userId);

        if ($wallet['is_frozen']) {
            $blockers[] = [
                'code'    => 'wallet_on_hold',
                'title'   => 'Your wallet is on hold',
                'message' => 'Your wallet is on hold while our team reviews it. Contact support to close your account.',
                'action'  => 'support',
            ];
        } elseif ($wallet['total'] > 0) {
            $currency = (string) $wallet['currency'];
            $message  = 'You have ' . Money::format((float) $wallet['total'], $currency) . ' in your wallet';
            $message .= $wallet['locked'] > 0
                ? ', including ' . Money::format((float) $wallet['locked'], $currency) . ' still being released from escrow. Withdraw it all before deleting your account.'
                : '. Withdraw it before deleting your account so you don\'t lose it.';

            $blockers[] = [
                'code'    => 'wallet_balance',
                'title'   => 'Money in your wallet',
                'message' => $message,
                'action'  => 'wallet',
                'amount'  => (float) $wallet['total'],
            ];
        }

        $withdrawals = Database::selectOne(
            "SELECT COUNT(*) AS `n`, COALESCE(SUM(`amount`), 0) AS `total`, MAX(`currency`) AS `currency`
               FROM `withdrawal_requests`
              WHERE `user_id` = :id AND `status` IN ('pending', 'approved', 'processing')",
            ['id' => $userId]
        );

        if ((int) ($withdrawals['n'] ?? 0) > 0) {
            $blockers[] = [
                'code'    => 'pending_withdrawals',
                'title'   => 'A withdrawal is on its way',
                'message' => 'A withdrawal of ' . Money::format((float) $withdrawals['total'], (string) ($withdrawals['currency'] ?: null))
                    . ' is still being processed. You can delete your account once it has been paid out.',
                'action'  => 'wallet',
                'count'   => (int) $withdrawals['n'],
                'amount'  => (float) $withdrawals['total'],
            ];
        }

        $refunds = (int) Database::scalar(
            "SELECT COUNT(*) FROM `refunds` WHERE `user_id` = :id AND `status` IN ('pending', 'processing')",
            ['id' => $userId]
        );

        if ($refunds > 0) {
            $blockers[] = [
                'code'    => 'pending_refunds',
                'title'   => 'A refund is on its way',
                'message' => 'A refund to you is still being processed. You can delete your account once it arrives.',
                'action'  => 'wallet',
                'count'   => $refunds,
            ];
        }

        return $blockers;
    }

    /**
     * Refuse, with every reason at once, while anything blocks deletion.
     *
     * The controller calls this before re-authenticating, so a one-time code
     * is not spent on a deletion that was always going to be refused. The
     * erasure calls it again, in case something changed in between.
     */
    public static function assertDeletable(int $userId): void
    {
        $blockers = self::blockers($userId);

        if ($blockers === []) {
            return;
        }

        $errors = [];

        foreach ($blockers as $blocker) {
            $errors[$blocker['code']] = [$blocker['message']];
        }

        throw new HttpException(
            count($blockers) === 1
                ? $blockers[0]['message']
                : 'A few things need to be settled before your account can be deleted.',
            409,
            'deletion_blocked',
            $errors
        );
    }

    /**
     * How many records of each kept kind this account has, so the screen can
     * say "3 bookings and 2 invoices will be kept" rather than a vague maybe.
     *
     * @return array<string,int>
     */
    public static function retainedCounts(int $userId): array
    {
        $p = ['id' => $userId];

        return [
            'bookings'        => (int) Database::scalar(
                'SELECT COUNT(*) FROM `bookings` WHERE (`brand_user_id` = :id OR `creator_user_id` = :id) AND `deleted_at` IS NULL',
                $p
            ),
            'payments'        => (int) Database::scalar('SELECT COUNT(*) FROM `payments` WHERE `user_id` = :id', $p),
            'invoices'        => (int) Database::scalar('SELECT COUNT(*) FROM `invoices` WHERE `user_id` = :id', $p),
            'wallet_entries'  => (int) Database::scalar('SELECT COUNT(*) FROM `wallet_transactions` WHERE `user_id` = :id', $p),
            'messages'        => (int) Database::scalar(
                "SELECT COUNT(*) FROM `messages` WHERE `sender_id` = :id AND `sender_type` = 'user' AND `deleted_at` IS NULL",
                $p
            ),
            'reviews_written' => (int) Database::scalar(
                'SELECT COUNT(*) FROM `reviews` WHERE `reviewer_id` = :id AND `deleted_at` IS NULL',
                $p
            ),
            'support_tickets' => (int) Database::scalar('SELECT COUNT(*) FROM `support_tickets` WHERE `user_id` = :id', $p),
        ];
    }

    // -----------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------

    /**
     * Erase the account. The caller has already re-authenticated the user
     * and checked the typed confirmation.
     *
     * @param  array<string,mixed> $user    The authenticated user row.
     * @param  'password'|'email_code'|'sms_code' $verifiedVia
     * @param  array<string,mixed> $context jti / token_exp / platform
     * @return array{deletion_id:int,erased:array<string,int>,retained:array<string,int>}
     */
    public static function delete(
        array $user,
        string $verifiedVia,
        ?string $reasonCode = null,
        ?string $reasonText = null,
        array $context = []
    ): array {
        $userId      = (int) $user['id'];
        $accountType = (string) ($user['account_type'] ?? '');

        if (!in_array($accountType, ['brand', 'creator'], true)) {
            throw HttpException::forbidden('This account cannot be deleted here.');
        }

        self::assertDeletable($userId);

        $codes = array_column(self::reasons($accountType), 'code');

        if ($reasonCode !== null && $reasonCode !== '' && !in_array($reasonCode, $codes, true)) {
            $reasonCode = 'other';
        }

        // Read everything needed after the erasure before it happens.
        $email       = trim((string) ($user['email'] ?? ''));
        $name        = (string) ($user['name'] ?? '');
        $firebaseUid = trim((string) ($user['firebase_uid'] ?? ''));
        $phone       = !empty($user['phone'])
            ? Str::normalizePhone((string) ($user['phone_country_code'] ?? '') . (string) $user['phone'])
            : '';

        $creatorId = (int) (Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :id', ['id' => $userId]) ?? 0);

        $files = self::personalFiles($userId, $creatorId);

        $platform = in_array($context['platform'] ?? '', ['android', 'ios', 'web'], true) ? (string) $context['platform'] : 'unknown';

        $summary = Database::transaction(static function () use (
            $userId,
            $accountType,
            $creatorId,
            $email,
            $phone,
            $verifiedVia,
            $reasonCode,
            $reasonText,
            $platform
        ): array {
            $now = date('Y-m-d H:i:s');
            $p   = ['id' => $userId];

            $deletionId = Database::insert('account_deletion_requests', [
                'user_id'      => $userId,
                'account_type' => $accountType,
                'reason_code'  => $reasonCode === '' ? null : $reasonCode,
                'reason_text'  => $reasonText === null || trim($reasonText) === '' ? null : mb_substr(trim($reasonText), 0, 500),
                'verified_via' => $verifiedVia,
                'platform'     => $platform,
                'created_at'   => $now,
            ]);

            $erased = [];

            if ($creatorId > 0) {
                $erased += self::eraseCreator($userId, $creatorId, $now);
            }

            if ($accountType === 'brand') {
                $erased += self::closeCampaigns($userId, $now);
            }

            // Everything that exists only for this person's benefit.
            $tables = [
                'user_devices'                => 'user_id',
                'refresh_tokens'              => 'user_id',
                'otp_verifications'           => 'user_id',
                'password_resets'             => 'user_id',
                'notifications'               => 'user_id',
                'search_histories'            => 'user_id',
                'favorites'                   => 'user_id',
                'follows'                     => 'follower_id',
                'contact_change_requests'     => 'user_id',
                'contact_verification_events' => 'user_id',
                'user_contact_points'         => 'user_id',
                'message_receipts'            => 'user_id',
                'bank_accounts'               => 'user_id',
            ];

            foreach ($tables as $table => $column) {
                $erased[$table] = Database::execute("DELETE FROM `{$table}` WHERE `{$column}` = :id", $p);
            }

            // Codes sent before the account existed carry no user id, only
            // the address they went to.
            foreach (array_filter([$email !== '' ? strtolower($email) : '', $phone]) as $destination) {
                $erased['otp_verifications'] += Database::execute(
                    'DELETE FROM `otp_verifications` WHERE `destination` = :destination',
                    ['destination' => $destination]
                );
            }

            $erased['newsletter_subscribers'] = Database::execute(
                "DELETE FROM `newsletter_subscribers` WHERE `user_id` = :id OR (`email` = :email AND `email` <> '')",
                ['id' => $userId, 'email' => $email]
            );

            // Undelivered mail, SMS and push addressed to this person.
            $erased['queued_messages'] = Database::execute(
                "DELETE FROM `push_queue` WHERE `target_type` = 'user' AND `target_value` = :id AND `sent_at` IS NULL",
                ['id' => (string) $userId]
            );

            if ($email !== '') {
                $erased['queued_messages'] += Database::execute(
                    "DELETE FROM `email_queue` WHERE `to_email` = :email AND `sent_at` IS NULL",
                    ['email' => $email]
                );
            }

            if ($phone !== '') {
                $erased['queued_messages'] += Database::execute(
                    "DELETE FROM `sms_queue` WHERE `to_phone` = :phone AND `sent_at` IS NULL",
                    ['phone' => $phone]
                );
            }

            // Kept records lose the name that was copied into them.
            Database::execute(
                "UPDATE `support_ticket_messages` SET `sender_name` = 'Deleted user'
                  WHERE `sender_type` = 'user' AND `sender_id` = :id",
                $p
            );
            Database::execute(
                "UPDATE `activity_logs` SET `actor_name` = 'Deleted user'
                  WHERE `actor_type` = 'user' AND `actor_id` = :id",
                $p
            );
            Database::execute(
                "UPDATE `testimonials` SET `author_name` = 'Deleted user', `author_role` = NULL, `company` = NULL, `avatar_url` = NULL
                  WHERE `review_id` IN (SELECT `id` FROM `reviews` WHERE `reviewer_id` = :id)",
                $p
            );
            Database::execute(
                'UPDATE `conversation_participants` SET `is_typing` = 0, `typing_until` = NULL WHERE `user_id` = :id',
                $p
            );

            // The tombstone. Every identifying column is cleared. The row
            // stays because retained financial records point at it, and
            // `deleted_at` frees the email and phone for a future sign-up
            // through the *_active unique keys.
            Database::update('users', [
                'name'                  => 'Deleted user',
                'email'                 => null,
                'phone'                 => null,
                'password_hash'         => null,
                'firebase_uid'          => null,
                'avatar_url'            => null,
                'bio'                   => null,
                'company_name'          => null,
                'website'               => null,
                'gst_number'            => null,
                'city'                  => null,
                'state'                 => null,
                'postal_code'           => null,
                'auth_providers'        => null,
                'notification_prefs'    => null,
                'last_login_ip'         => null,
                'referral_code'         => 'DEL' . strtoupper(bin2hex(random_bytes(6))),
                'suspended_reason'      => null,
                'status'                => 'deleted',
                'deletion_requested_at' => $now,
                'deleted_at'            => $now,
            ], ['id' => $userId]);

            $retained = self::retainedCounts($userId);

            Database::update('account_deletion_requests', [
                'summary'      => json_encode(['erased' => $erased, 'retained' => $retained], JSON_UNESCAPED_UNICODE),
                'completed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $deletionId]);

            return ['deletion_id' => $deletionId, 'erased' => $erased, 'retained' => $retained];
        });

        // ---- After the commit: best-effort, logged, never rolled back ----

        $summary['erased']['files'] = self::removeFiles($files);

        try {
            $summary['erased']['data_exports'] = DataExportService::purgeForUser($userId);
        } catch (\Throwable $e) {
            Logger::error('Account deletion: export purge failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        self::purgeKyc($userId);
        self::deleteFirebaseUser($userId, $firebaseUid);

        // Every refresh token is gone and the user row no longer loads, so no
        // access token works any more. The current one is still blacklisted
        // explicitly, so the guarantee does not rest on that lookup alone.
        $jti = (string) ($context['jti'] ?? '');
        $exp = (int) ($context['token_exp'] ?? 0);

        if ($jti !== '' && $exp > time()) {
            Jwt::revoke($jti, $userId, $exp);
        }

        self::sendGoodbye($email, $name, $summary['retained']);

        ActivityLogger::log(
            'account.deleted',
            'user',
            $userId,
            'Account deleted by its owner.',
            [
                'deletion_id'  => $summary['deletion_id'],
                'verified_via' => $verifiedVia,
                'reason_code'  => $reasonCode,
                'account_type' => $accountType,
            ],
            'user',
            $userId,
            'Deleted user'
        );

        return $summary;
    }

    /**
     * The creator profile and everything hanging off it.
     *
     * The `creators` row is anonymised rather than deleted: bookings and
     * reviews reference it, and bookings.creator_id cascades, so a hard
     * delete would take retained financial records with it.
     *
     * @return array<string,int>
     */
    private static function eraseCreator(int $userId, int $creatorId, string $now): array
    {
        $c      = ['cid' => $creatorId];
        $erased = [];

        $tables = [
            'creator_portfolio', 'creator_packages', 'creator_social_accounts', 'creator_credentials',
            'creator_experience', 'creator_faqs', 'creator_working_hours', 'creator_availability',
            'creator_categories', 'creator_profile_terms', 'creator_handle_history', 'creator_profile_completion',
            'creator_profile_locks', 'creator_feature_overrides', 'creator_change_requests', 'creator_badges',
            'creator_badge_events', 'creator_profile_views', 'creator_engagement_events', 'testimonials',
            'favorites', 'follows',
        ];

        foreach ($tables as $table) {
            $erased[$table === 'favorites' || $table === 'follows' ? "saved_by_others_{$table}" : $table]
                = Database::execute("DELETE FROM `{$table}` WHERE `creator_id` = :cid", $c);
        }

        // Applications that became bookings stay as the link between the
        // campaign and the booking, without the pitch. The rest go, and the
        // campaigns' counters are recomputed so brands see true numbers.
        $campaignIds = array_map('intval', array_column(Database::select(
            'SELECT DISTINCT `campaign_id` FROM `campaign_applications` WHERE `creator_user_id` = :id',
            ['id' => $userId]
        ), 'campaign_id'));

        Database::execute(
            'UPDATE `campaign_applications` SET `pitch` = NULL, `portfolio_links` = NULL
              WHERE `creator_user_id` = :id AND `booking_id` IS NOT NULL',
            ['id' => $userId]
        );
        $erased['campaign_applications'] = Database::execute(
            'DELETE FROM `campaign_applications` WHERE `creator_user_id` = :id AND `booking_id` IS NULL',
            ['id' => $userId]
        );

        foreach ($campaignIds as $campaignId) {
            Database::execute(
                'UPDATE `campaigns` SET `applications_count` = (
                    SELECT COUNT(*) FROM `campaign_applications` a
                     WHERE a.`campaign_id` = :campaign AND a.`deleted_at` IS NULL
                 ) WHERE `id` = :campaign2',
                ['campaign' => $campaignId, 'campaign2' => $campaignId]
            );
        }

        Database::update('creators', [
            'handle'                    => 'deleted-' . $creatorId,
            'display_name'              => 'Deleted creator',
            'headline'                  => null,
            'about'                     => null,
            'cover_image_url'           => null,
            'intro_video_url'           => null,
            'intro_video_thumbnail_url' => null,
            'media_kit_url'             => null,
            'resume_url'                => null,
            'city'                      => null,
            'state'                     => null,
            'address_line1'             => null,
            'address_line2'             => null,
            'pin_code'                  => null,
            'address_is_public'         => 0,
            'gender'                    => null,
            'date_of_birth'             => null,
            'languages'                 => null,
            'is_public'                 => 0,
            'is_featured'               => 0,
            'featured_until'            => null,
            'availability_status'       => 'unavailable',
            'meta_title'                => null,
            'meta_description'          => null,
            'deleted_at'                => $now,
        ], ['id' => $creatorId]);

        $erased['creator_profile'] = 1;

        return $erased;
    }

    /**
     * A brand's campaigns: the ones still open or in draft are cancelled and
     * their applicants told, and every campaign leaves public view. The rows
     * stay, because bookings reference the campaign they came from.
     *
     * @return array<string,int>
     */
    private static function closeCampaigns(int $userId, string $now): array
    {
        $p = ['id' => $userId];

        Database::execute(
            "UPDATE `campaign_applications` a
               JOIN `campaigns` c ON c.`id` = a.`campaign_id`
                SET a.`status` = 'declined', a.`decline_reason` = 'This campaign has closed.', a.`responded_at` = :now
              WHERE c.`brand_user_id` = :id AND a.`status` IN ('invited', 'applied', 'shortlisted')",
            ['id' => $userId, 'now' => $now]
        );

        $closed = Database::execute(
            "UPDATE `campaigns` SET `status` = 'cancelled' WHERE `brand_user_id` = :id AND `status` IN ('draft', 'open')",
            $p
        );

        Database::execute(
            'UPDATE `campaigns` SET `is_public` = 0, `deleted_at` = COALESCE(`deleted_at`, :now) WHERE `brand_user_id` = :id',
            ['id' => $userId, 'now' => $now]
        );

        return ['campaigns_closed' => $closed];
    }

    // -----------------------------------------------------------------
    // After the commit
    // -----------------------------------------------------------------

    /**
     * The uploads that belong to this person alone: media rows they
     * uploaded outside the retained folders, plus profile files referenced
     * only by URL (older uploads predate the media table).
     *
     * @return array{media:list<int>,urls:list<string>}
     */
    private static function personalFiles(int $userId, int $creatorId): array
    {
        $retained = "'" . implode("','", self::RETAINED_MEDIA_FOLDERS) . "'";

        $media = array_map('intval', array_column(Database::select(
            "SELECT `id` FROM `media`
              WHERE `uploader_type` = 'user' AND `uploader_id` = :id AND `folder` NOT IN ({$retained})",
            ['id' => $userId]
        ), 'id'));

        $urls = [(string) Database::scalar('SELECT `avatar_url` FROM `users` WHERE `id` = :id', ['id' => $userId])];

        if ($creatorId > 0) {
            $creator = Database::selectOne(
                'SELECT `cover_image_url`, `intro_video_url`, `intro_video_thumbnail_url`, `media_kit_url`, `resume_url`
                   FROM `creators` WHERE `id` = :cid',
                ['cid' => $creatorId]
            ) ?? [];

            $urls = array_merge($urls, array_map('strval', array_values($creator)));

            foreach (Database::select(
                'SELECT `media_url`, `thumbnail_url` FROM `creator_portfolio` WHERE `creator_id` = :cid',
                ['cid' => $creatorId]
            ) as $item) {
                $urls[] = (string) $item['media_url'];
                $urls[] = (string) $item['thumbnail_url'];
            }
        }

        return ['media' => $media, 'urls' => array_values(array_unique(array_filter($urls)))];
    }

    /** @param array{media:list<int>,urls:list<string>} $files */
    private static function removeFiles(array $files): int
    {
        $removed = 0;

        foreach ($files['media'] as $mediaId) {
            try {
                UploadService::purge($mediaId);
                $removed++;
            } catch (\Throwable $e) {
                Logger::warning('Account deletion: media purge failed', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
            }
        }

        $root      = realpath(rtrim((string) Config::get('storage.upload_path'), '/\\'));
        $urlPrefix = rtrim((string) Config::get('storage.upload_url'), '/') . '/';

        if ($root === false) {
            return $removed;
        }

        foreach ($files['urls'] as $url) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

            // Only this server's own uploads. Seeded and third-party media
            // (picsum, YouTube thumbnails) is not ours to delete.
            if (!str_starts_with($path, $urlPrefix)) {
                continue;
            }

            $relative = substr($path, strlen($urlPrefix));
            $folder   = strtok($relative, '/');

            if ($folder === false || in_array($folder, self::RETAINED_MEDIA_FOLDERS, true)) {
                continue;
            }

            $target = realpath($root . DIRECTORY_SEPARATOR . str_replace(['\\', '..'], ['/', ''], $relative));

            if ($target !== false && str_starts_with($target, $root . DIRECTORY_SEPARATOR) && is_file($target) && @unlink($target)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Identity documents are the KYC module's to purge under its retention
     * rules. It deletes document images and numbers and keeps the minimal
     * record the law requires. This only asks it to.
     */
    private static function purgeKyc(int $userId): void
    {
        if (!class_exists(KycService::class) || !method_exists(KycService::class, 'purgeForUser')) {
            Logger::warning('Account deletion: KycService::purgeForUser() is not available, so KYC data was left for the KYC module.', [
                'user_id' => $userId,
            ]);

            return;
        }

        try {
            KycService::purgeForUser($userId, 'account_deleted');
        } catch (\Throwable $e) {
            Logger::error('Account deletion: KYC purge failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Google, Apple and phone sign-ins also leave a copy of the email, name
     * and phone in Firebase Authentication. Deleting it there means a later
     * Google sign-in starts a genuinely new account.
     */
    private static function deleteFirebaseUser(int $userId, string $firebaseUid): void
    {
        if ($firebaseUid === '') {
            return;
        }

        try {
            if (!FirebaseService::deleteAuthUser($firebaseUid)) {
                Logger::warning('Account deletion: the Firebase Auth user could not be deleted.', ['user_id' => $userId]);
            }
        } catch (\Throwable $e) {
            Logger::warning('Account deletion: Firebase Auth deletion failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Confirmation to the address the account had, sent once and never
     * stored again: the queue row is the only copy, and it goes when sent.
     *
     * @param array<string,int> $retained
     */
    private static function sendGoodbye(string $email, string $name, array $retained): void
    {
        if ($email === '') {
            return;
        }

        $kept = [];

        foreach (['bookings' => 'booking', 'invoices' => 'invoice', 'payments' => 'payment'] as $key => $noun) {
            $count = (int) ($retained[$key] ?? 0);

            if ($count > 0) {
                $kept[] = $count . ' ' . $noun . ($count === 1 ? '' : 's');
            }
        }

        $body = 'Your Zusty account has been deleted, and your profile, contact details, saved items and sign-in '
            . 'methods have been erased. You have been signed out on every device. '
            . ($kept !== []
                ? 'As the law requires, we keep the financial records of your ' . implode(', ', $kept)
                  . ', without your name. '
                : '')
            . 'If you did not do this, contact us immediately by replying to this email.';

        $subject = 'Your Zusty account has been deleted';

        try {
            $html = MailService::renderDefault('Your account has been deleted', $body, $name);

            if (!MailService::sendNow($email, $name, $subject, $html)) {
                MailService::queue($email, $name, $subject, $html);
            }
        } catch (\Throwable $e) {
            Logger::warning('Account deletion: goodbye email could not be sent', ['error' => $e->getMessage()]);
        }
    }
}

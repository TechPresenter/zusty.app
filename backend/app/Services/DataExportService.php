<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;

/**
 * "Download my data": a ZIP of JSON files holding the personal data Zusty
 * keeps about one account.
 *
 * The archive is built synchronously. One person's history is a few hundred
 * rows, far below the point where a queue would earn its keep, and building
 * it inline means the user gets their file while they are still looking at
 * the screen rather than "we'll email you". The file is stored under
 * storage/exports, outside the web root, and is only reachable through a
 * download token. The token is long, bound to one export, expires, and is
 * capped in uses. The `privacy:purge-exports` job deletes the file when any
 * of those runs out.
 *
 * What is deliberately NOT in the archive: secrets (password and token
 * hashes, push tokens), full bank account numbers, KYC document numbers and
 * images, and other people's messages. The README inside says so in plain
 * words, so the user is not left wondering whether something is missing.
 */
final class DataExportService
{
    /** Row cap per section, generous for one person, bounded for safety. */
    private const MAX_ROWS = 5000;

    /** Columns that never leave the database, whichever table they are in. */
    private const NEVER_EXPORT = [
        'password_hash', 'token_hash', 'code_hash', 'download_token', 'fcm_token',
        'gateway_signature', 'gateway_response', 'idempotency_key', 'unsubscribe_token',
        'account_number', 'checksum', 'open_key',
    ];

    /**
     * What the archive contains, for the app to show before the user asks.
     * Keep this in step with {@see collect()}.
     *
     * @var list<array{title:string,detail:string}>
     */
    private const INCLUDES = [
        ['title' => 'Profile & account', 'detail' => 'Name, contact details, preferences, verification status and linked sign-in methods.'],
        ['title' => 'Creator profile',   'detail' => 'Packages, portfolio, social accounts, credentials, experience and FAQs (creators).'],
        ['title' => 'Campaigns',         'detail' => 'Campaigns you created, or the ones you applied to.'],
        ['title' => 'Bookings',          'detail' => 'Every booking, offer and dispute you were part of.'],
        ['title' => 'Messages',          'detail' => 'Your conversations and the messages you sent.'],
        ['title' => 'Payments & wallet', 'detail' => 'Payments, refunds, invoices, wallet transactions, withdrawals and payout methods (masked).'],
        ['title' => 'Reviews',           'detail' => 'Reviews you wrote and reviews you received.'],
        ['title' => 'Activity',          'detail' => 'Saved creators, searches, notifications, support tickets, reports, referrals, devices and sign-ins.'],
    ];

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public static function cooldownHours(): int
    {
        return self::bounded('privacy_export_cooldown_hours', 24, 1, 720);
    }

    public static function linkHours(): int
    {
        return self::bounded('privacy_export_link_hours', 72, 1, 720);
    }

    public static function maxDownloads(): int
    {
        return self::bounded('privacy_export_max_downloads', 5, 1, 50);
    }

    /**
     * An operator-editable number, clamped. A zero cooldown would let anyone
     * hammer the most expensive query set in the app, and a zero lifetime
     * would hand out links that are dead on arrival.
     */
    private static function bounded(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, Config::settingInt('security', $key, $default)));
    }

    // -----------------------------------------------------------------
    // Status
    // -----------------------------------------------------------------

    /**
     * The latest export, whether another may be requested now, and what an
     * export contains.
     *
     * @return array<string,mixed>
     */
    public static function status(int $userId): array
    {
        $latest = Database::selectOne(
            'SELECT * FROM `data_export_requests` WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 1',
            ['id' => $userId]
        );

        $next = self::nextAvailableAt($userId);

        return [
            'latest'            => $latest === null ? null : self::present($latest),
            'can_request'       => $next === null,
            'next_available_at' => $next === null ? null : date('c', $next),
            'cooldown_hours'    => self::cooldownHours(),
            'link_hours'        => self::linkHours(),
            'max_downloads'     => self::maxDownloads(),
            'format'            => 'ZIP archive of JSON files',
            'includes'          => self::INCLUDES,
        ];
    }

    /**
     * When the next export may be requested, or null if it may be now.
     *
     * A failed attempt does not start the clock. The user got nothing, so
     * making them wait a day to retry would punish them for our failure.
     */
    private static function nextAvailableAt(int $userId): ?int
    {
        $last = Database::scalar(
            "SELECT MAX(`created_at`) FROM `data_export_requests`
              WHERE `user_id` = :id AND `status` <> 'failed'",
            ['id' => $userId]
        );

        if ($last === null) {
            return null;
        }

        $next = (int) strtotime((string) $last) + self::cooldownHours() * 3600;

        return $next > time() ? $next : null;
    }

    // -----------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------

    /**
     * Build the archive now, store it, and return the export with its link.
     *
     * The caller has already re-authenticated the user.
     *
     * @param  array<string,mixed> $user        The authenticated user row.
     * @param  'password'|'email_code'|'sms_code' $verifiedVia
     * @param  array<string,mixed> $device      ip / user_agent
     * @return array<string,mixed>
     */
    public static function create(array $user, string $verifiedVia, array $device = []): array
    {
        $userId = (int) $user['id'];
        $next   = self::nextAvailableAt($userId);

        if ($next !== null) {
            $exception = HttpException::tooManyRequests(
                'You can request a new copy of your data after ' . self::humanTime($next) . '.',
                $next - time()
            );

            throw $exception;
        }

        $exportId = Database::insert('data_export_requests', [
            'user_id'       => $userId,
            'status'        => 'processing',
            'max_downloads' => self::maxDownloads(),
            'verified_via'  => $verifiedVia,
            'ip_address'    => isset($device['ip']) ? mb_substr((string) $device['ip'], 0, 45) : null,
            'user_agent'    => isset($device['user_agent']) ? mb_substr((string) $device['user_agent'], 0, 255) : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        try {
            [$relative, $size, $checksum] = self::writeArchive($userId, $exportId, self::collect($user, $exportId));
        } catch (\Throwable $e) {
            Logger::error('Personal data export failed', [
                'user_id'   => $userId,
                'export_id' => $exportId,
                'error'     => $e->getMessage(),
            ]);

            Database::update('data_export_requests', [
                'status'       => 'failed',
                'error'        => mb_substr($e->getMessage(), 0, 255),
                'completed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $exportId]);

            throw new HttpException(
                'We could not prepare your data right now. Please try again in a few minutes.',
                500,
                'export_failed'
            );
        }

        $token     = Crypto::randomToken(32);
        $expiresAt = time() + self::linkHours() * 3600;

        Database::update('data_export_requests', [
            'status'         => 'ready',
            'file_path'      => $relative,
            'file_size'      => $size,
            'checksum'       => $checksum,
            'token_hash'     => Crypto::hashToken($token),
            'download_token' => Crypto::encrypt($token),
            'expires_at'     => date('Y-m-d H:i:s', $expiresAt),
            'completed_at'   => date('Y-m-d H:i:s'),
        ], ['id' => $exportId]);

        ActivityLogger::log(
            'privacy.export_created',
            'user',
            $userId,
            'Personal data export prepared.',
            ['export_id' => $exportId, 'verified_via' => $verifiedVia, 'bytes' => $size],
            'user',
            $userId,
            (string) $user['name']
        );

        self::notify($user, self::downloadUrl($token), $expiresAt);

        $row = Database::selectOne('SELECT * FROM `data_export_requests` WHERE `id` = :id', ['id' => $exportId]);

        return self::present((array) $row);
    }

    /**
     * Security notice and link, sent whatever the user's email preferences.
     * This is an account-security email, not a notification: if someone
     * else asked for the export, this email is how the owner finds out.
     *
     * @param array<string,mixed> $user
     */
    private static function notify(array $user, string $url, int $expiresAt): void
    {
        $email = trim((string) ($user['email'] ?? ''));

        if ($email === '') {
            return;
        }

        $support = Config::settingString('general', 'support_email');
        $subject = 'Your Zusty data export is ready';
        $body    = 'You asked for a copy of the personal data on your Zusty account. '
            . 'Your archive is ready. The link below works until ' . self::humanTime($expiresAt)
            . ' and can be used ' . self::maxDownloads() . ' times. '
            . 'If you did not ask for this, change your password straight away'
            . ($support !== '' ? ' and contact us at ' . $support : '') . '.';

        try {
            $html = MailService::renderDefault('Your data export is ready', $body, (string) $user['name'], 'url', $url);

            if (!MailService::sendNow($email, (string) $user['name'], $subject, $html)) {
                MailService::queue($email, (string) $user['name'], $subject, $html);
            }
        } catch (\Throwable $e) {
            // The user already has the link on screen. A mail outage must not
            // turn a finished export into an error.
            Logger::warning('Data export email could not be sent', ['user_id' => (int) $user['id'], 'error' => $e->getMessage()]);
        }
    }

    // -----------------------------------------------------------------
    // Download
    // -----------------------------------------------------------------

    /**
     * Resolve a download token to the archive, counting the use.
     *
     * @return array{path:string,filename:string,export_id:int,user_id:int}
     */
    public static function consume(string $token): array
    {
        $invalid = HttpException::notFound('This download link is not valid. Request a new copy of your data from the app.');

        if ($token === '' || strlen($token) > 128) {
            throw $invalid;
        }

        $row = Database::selectOne(
            'SELECT e.*, u.`deleted_at` AS `account_deleted_at`
               FROM `data_export_requests` e
               JOIN `users` u ON u.`id` = e.`user_id`
              WHERE e.`token_hash` = :hash',
            ['hash' => Crypto::hashToken($token)]
        );

        if ($row === null || $row['account_deleted_at'] !== null) {
            throw $invalid;
        }

        $expired = $row['status'] === 'expired'
            || ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time());

        if ($expired) {
            throw new HttpException(
                'This download link has expired. Request a new copy of your data from the app.',
                410,
                'export_expired'
            );
        }

        if ($row['status'] !== 'ready' || $row['file_path'] === null) {
            throw $invalid;
        }

        $absolute = self::absolutePath((string) $row['file_path']);

        if ($absolute === null) {
            throw new HttpException(
                'This download link has expired. Request a new copy of your data from the app.',
                410,
                'export_expired'
            );
        }

        // Counted atomically, so two simultaneous clicks cannot both squeeze
        // past the last remaining use.
        $counted = Database::execute(
            'UPDATE `data_export_requests`
                SET `download_count` = `download_count` + 1, `last_downloaded_at` = NOW()
              WHERE `id` = :id AND `download_count` < `max_downloads`',
            ['id' => (int) $row['id']]
        );

        if ($counted === 0) {
            throw new HttpException(
                'This download link has been used the maximum number of times. Request a new copy of your data from the app.',
                410,
                'export_download_limit'
            );
        }

        ActivityLogger::log(
            'privacy.export_downloaded',
            'user',
            (int) $row['user_id'],
            'Personal data export downloaded.',
            ['export_id' => (int) $row['id'], 'download' => (int) $row['download_count'] + 1],
            'system'
        );

        return [
            'path'      => $absolute,
            'filename'  => 'zuste-data-export-' . date('Y-m-d', (int) strtotime((string) $row['created_at'])) . '.zip',
            'export_id' => (int) $row['id'],
            'user_id'   => (int) $row['user_id'],
        ];
    }

    public static function downloadUrl(string $token): string
    {
        return rtrim((string) Config::get('app.api_url'), '/') . '/v1/privacy/exports/download?token=' . rawurlencode($token);
    }

    // -----------------------------------------------------------------
    // Housekeeping
    // -----------------------------------------------------------------

    /**
     * Cron: delete archives whose link expired or was used up, and fail any
     * request a crashed process left in `processing`.
     *
     * @return array{purged:int,stuck:int}
     */
    public static function purgeExpired(): array
    {
        $rows = Database::select(
            "SELECT `id`, `status`, `file_path` FROM `data_export_requests`
              WHERE `file_path` IS NOT NULL
                AND (`status` IN ('failed', 'expired')
                     OR (`expires_at` IS NOT NULL AND `expires_at` <= NOW())
                     OR `download_count` >= `max_downloads`)"
        );

        foreach ($rows as $row) {
            self::deleteFile((string) $row['file_path']);

            Database::update('data_export_requests', [
                'status'         => $row['status'] === 'failed' ? 'failed' : 'expired',
                'file_path'      => null,
                'token_hash'     => null,
                'download_token' => null,
            ], ['id' => (int) $row['id']]);
        }

        $stuck = Database::execute(
            "UPDATE `data_export_requests`
                SET `status` = 'failed', `error` = 'Timed out while preparing.', `completed_at` = NOW()
              WHERE `status` = 'processing' AND `created_at` < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );

        // Per-user folders left empty, either by the deletions above or by an
        // account deletion whose rmdir lost a race with a virus scanner still
        // holding the archive open (Windows keeps a deleted file "pending"
        // until the last handle closes).
        foreach (glob(self::root() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (count(scandir($directory) ?: []) <= 2) {
                @rmdir($directory);
            }
        }

        return ['purged' => count($rows), 'stuck' => $stuck];
    }

    /**
     * Account deletion: remove every archive and every record of one. The
     * rows carry the IP and browser of each request, which is personal data
     * like everything else being erased.
     */
    public static function purgeForUser(int $userId): int
    {
        $rows = Database::select(
            'SELECT `file_path` FROM `data_export_requests` WHERE `user_id` = :id AND `file_path` IS NOT NULL',
            ['id' => $userId]
        );

        foreach ($rows as $row) {
            self::deleteFile((string) $row['file_path']);
        }

        $directory = self::root() . DIRECTORY_SEPARATOR . $userId;

        if (is_dir($directory)) {
            @rmdir($directory);
        }

        return Database::execute('DELETE FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $userId]);
    }

    // -----------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function present(array $row): array
    {
        $status = (string) $row['status'];

        if ($status === 'ready' && $row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time()) {
            $status = 'expired';
        }

        $left        = max(0, (int) $row['max_downloads'] - (int) $row['download_count']);
        $canDownload = $status === 'ready' && $left > 0 && !empty($row['download_token']);
        $token       = $canDownload ? Crypto::decrypt((string) $row['download_token']) : null;

        return [
            'id'                 => (int) $row['id'],
            'status'             => $status,
            'file_size'          => $row['file_size'] === null ? null : (int) $row['file_size'],
            'download_count'     => (int) $row['download_count'],
            'max_downloads'      => (int) $row['max_downloads'],
            'downloads_left'     => $left,
            'can_download'       => $canDownload && $token !== null,
            'download_url'       => $token === null ? null : self::downloadUrl($token),
            'created_at'         => self::iso($row['created_at'] ?? null),
            'completed_at'       => self::iso($row['completed_at'] ?? null),
            'expires_at'         => self::iso($row['expires_at'] ?? null),
            'last_downloaded_at' => self::iso($row['last_downloaded_at'] ?? null),
        ];
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : date('c', (int) strtotime((string) $value));
    }

    private static function humanTime(int $timestamp): string
    {
        return date('j M Y, g:i A', $timestamp);
    }

    // -----------------------------------------------------------------
    // The archive
    // -----------------------------------------------------------------

    /**
     * Every file in the archive, keyed by name.
     *
     * Each section is collected on its own: if one table's shape ever drifts,
     * that file says so and the rest of the export still arrives, rather than
     * the user getting nothing.
     *
     * @param  array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function collect(array $user, int $exportId = 0): array
    {
        $id        = (int) $user['id'];
        $creatorId = (int) ($user['creator_id'] ?? 0);
        $isBrand   = ($user['account_type'] ?? '') === 'brand';
        $p         = ['id' => $id];

        $files = [];

        $files['account.json'] = self::section(static fn (): array => [
            'account'        => self::row(
                'SELECT * FROM `users` WHERE `id` = :id',
                $p,
                ['suspended_reason', 'deletion_requested_at', 'guest_expires_at', 'converted_at', 'deleted_at', 'kyc_profile_id']
            ),
            'contact_points' => self::rows(
                'SELECT `channel`, `label`, `value`, `phone_country_code`, `verified_at`, `is_recovery`, `created_at`
                   FROM `user_contact_points` WHERE `user_id` = :id AND `deleted_at` IS NULL ORDER BY `sort_order`, `id`',
                $p
            ),
        ]);

        $files['security.json'] = self::section(static fn (): array => [
            'sessions'         => self::rows(
                'SELECT `device_name`, `platform`, `ip_address`, `user_agent`, `created_at`, `last_used_at`,
                        `expires_at`, `revoked_at`, `revoked_reason`
                   FROM `refresh_tokens` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p,
                [],
                500
            ),
            'devices'          => self::rows(
                'SELECT `platform`, `device_model`, `os_version`, `app_version`, `locale`, `push_enabled`,
                        `last_active_at`, `created_at`
                   FROM `user_devices` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p
            ),
            'account_activity' => self::rows(
                "SELECT `action`, `description`, `ip_address`, `user_agent`, `created_at`
                   FROM `activity_logs` WHERE `actor_type` = 'user' AND `actor_id` = :id ORDER BY `id` DESC",
                $p,
                [],
                1000
            ),
        ]);

        if ($creatorId > 0) {
            $c = ['cid' => $creatorId];

            $files['creator_profile.json'] = self::section(static fn (): array => [
                'profile'        => self::row('SELECT * FROM `creators` WHERE `id` = :cid', $c, ['search_boost', 'approved_by']),
                'categories'     => self::rows(
                    'SELECT cat.`name` FROM `creator_categories` cc
                       JOIN `categories` cat ON cat.`id` = cc.`category_id` WHERE cc.`creator_id` = :cid',
                    $c
                ),
                'profile_fields' => self::rows(
                    'SELECT pt.`taxonomy_key`, tt.`label`, pt.`is_primary`, pt.`is_visible`
                       FROM `creator_profile_terms` pt
                       LEFT JOIN `taxonomy_terms` tt ON tt.`id` = pt.`term_id`
                      WHERE pt.`creator_id` = :cid ORDER BY pt.`taxonomy_key`, pt.`sort_order`',
                    $c
                ),
                'packages'        => self::rows('SELECT * FROM `creator_packages` WHERE `creator_id` = :cid AND `deleted_at` IS NULL', $c, ['id', 'creator_id', 'deleted_at']),
                'portfolio'       => self::rows(
                    'SELECT * FROM `creator_portfolio` WHERE `creator_id` = :cid AND `deleted_at` IS NULL',
                    $c,
                    ['id', 'creator_id', 'reviewed_by', 'moderation_note', 'deleted_at']
                ),
                'social_accounts' => self::rows('SELECT * FROM `creator_social_accounts` WHERE `creator_id` = :cid', $c, ['id', 'creator_id']),
                'credentials'     => self::rows('SELECT * FROM `creator_credentials` WHERE `creator_id` = :cid', $c, ['id', 'creator_id', 'verified_by']),
                'experience'      => self::rows('SELECT * FROM `creator_experience` WHERE `creator_id` = :cid', $c, ['id', 'creator_id']),
                'faqs'            => self::rows('SELECT `question`, `answer`, `is_visible`, `created_at` FROM `creator_faqs` WHERE `creator_id` = :cid', $c),
                'working_hours'   => self::rows('SELECT `weekday`, `is_working`, `starts_at`, `ends_at`, `note` FROM `creator_working_hours` WHERE `creator_id` = :cid', $c),
                'availability'    => self::rows(
                    'SELECT `available_on`, `is_available`, `slots`, `max_bookings`, `booked_count`, `note`
                       FROM `creator_availability` WHERE `creator_id` = :cid ORDER BY `available_on`',
                    $c
                ),
                'badges'          => self::rows('SELECT `badge`, `status`, `awarded_at`, `expires_at`, `revoked_at` FROM `creator_badges` WHERE `creator_id` = :cid', $c),
                'handle_history'  => self::rows('SELECT `old_handle`, `new_handle`, `reason`, `created_at` FROM `creator_handle_history` WHERE `creator_id` = :cid', $c),
            ]);
        }

        $files['campaigns.json'] = self::section(static fn (): array => $isBrand
            ? [
                'campaigns_created' => self::rows(
                    'SELECT `code`, `title`, `description`, `platform`, `deliverable_type`, `posts_count`, `budget_min`,
                            `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`,
                            `applications_count`, `hired_count`, `status`, `is_public`, `created_at`
                       FROM `campaigns` WHERE `brand_user_id` = :id AND `deleted_at` IS NULL ORDER BY `id` DESC',
                    $p
                ),
            ]
            : [
                'applications' => self::rows(
                    'SELECT c.`code` AS `campaign_code`, c.`title` AS `campaign_title`, ca.`status`, ca.`quoted_amount`,
                            ca.`currency`, ca.`pitch`, ca.`portfolio_links`, ca.`invited_at`, ca.`applied_at`,
                            ca.`responded_at`, ca.`decline_reason`
                       FROM `campaign_applications` ca
                       LEFT JOIN `campaigns` c ON c.`id` = ca.`campaign_id`
                      WHERE ca.`creator_user_id` = :id AND ca.`deleted_at` IS NULL ORDER BY ca.`id` DESC',
                    $p
                ),
            ]);

        $files['bookings.json'] = self::section(static fn (): array => [
            'bookings' => self::rows(
                "SELECT b.`code`, IF(b.`brand_user_id` = :id, 'brand', 'creator') AS `your_role`,
                        bu.`name` AS `brand_name`, cu.`name` AS `creator_name`,
                        b.`title`, b.`description`, b.`campaign_type`, b.`platform`, b.`deliverable_type`, b.`posts_count`,
                        b.`currency`, b.`amount`, b.`platform_fee`, b.`commission_amount`, b.`tax_amount`, b.`tax_rate`,
                        b.`discount_amount`, b.`total_amount`, b.`creator_earning`, b.`status`, b.`payment_status`,
                        b.`escrow_status`, b.`requested_at`, b.`accepted_at`, b.`paid_at`, b.`started_at`,
                        b.`delivery_due_at`, b.`delivered_at`, b.`completed_at`, b.`cancelled_at`,
                        b.`cancellation_reason`, b.`rejection_reason`, b.`created_at`
                   FROM `bookings` b
                   LEFT JOIN `users` bu ON bu.`id` = b.`brand_user_id`
                   LEFT JOIN `users` cu ON cu.`id` = b.`creator_user_id`
                  WHERE (b.`brand_user_id` = :id OR b.`creator_user_id` = :id) AND b.`deleted_at` IS NULL
                  ORDER BY b.`id` DESC",
                $p
            ),
            'offers_you_made' => self::rows(
                'SELECT b.`code` AS `booking_code`, o.`amount`, o.`posts_count`, o.`delivery_days`, o.`message`,
                        o.`status`, o.`responded_at`, o.`created_at`
                   FROM `booking_offers` o JOIN `bookings` b ON b.`id` = o.`booking_id`
                  WHERE o.`offered_by_id` = :id ORDER BY o.`id` DESC',
                $p
            ),
            'disputes' => self::rows(
                'SELECT b.`code` AS `booking_code`, d.`raised_by`, d.`reason`, d.`description`, d.`evidence_urls`,
                        d.`status`, d.`refund_amount`, d.`payout_amount`, d.`resolution_note`, d.`resolved_at`, d.`created_at`
                   FROM `booking_disputes` d JOIN `bookings` b ON b.`id` = d.`booking_id`
                  WHERE b.`brand_user_id` = :id OR b.`creator_user_id` = :id ORDER BY d.`id` DESC',
                $p
            ),
        ]);

        $files['messages.json'] = self::section(static fn (): array => [
            'note'          => 'Only messages you sent are included. Messages other people sent you are their personal data.',
            'conversations' => self::rows(
                'SELECT c.`id` AS `conversation_id`, c.`type`, c.`subject`, b.`code` AS `booking_code`,
                        (SELECT GROUP_CONCAT(u.`name` SEPARATOR \', \')
                           FROM `conversation_participants` p2 JOIN `users` u ON u.`id` = p2.`user_id`
                          WHERE p2.`conversation_id` = c.`id` AND p2.`user_id` <> :id) AS `with`,
                        p.`role` AS `your_role`, p.`joined_at`, p.`last_read_at`, p.`is_muted`,
                        c.`messages_count`, c.`created_at`, c.`last_message_at`
                   FROM `conversation_participants` p
                   JOIN `conversations` c ON c.`id` = p.`conversation_id`
                   LEFT JOIN `bookings` b ON b.`id` = c.`booking_id`
                  WHERE p.`user_id` = :id ORDER BY c.`id` DESC',
                $p
            ),
            'messages_sent' => self::rows(
                "SELECT `conversation_id`, `type`, `body`, `media_url`, `created_at`, `edited_at`
                   FROM `messages` WHERE `sender_id` = :id AND `sender_type` = 'user' AND `deleted_at` IS NULL ORDER BY `id`",
                $p,
                [],
                20000
            ),
        ]);

        $files['payments.json'] = self::section(static fn (): array => [
            'wallet'              => self::row(
                'SELECT `currency`, `balance`, `locked_balance`, `lifetime_credit`, `lifetime_debit`, `is_frozen`, `created_at`
                   FROM `wallets` WHERE `user_id` = :id',
                $p
            ),
            'wallet_transactions' => self::rows(
                'SELECT `reference`, `direction`, `category`, `amount`, `balance_before`, `balance_after`, `currency`,
                        `status`, `reference_type`, `description`, `created_at`
                   FROM `wallet_transactions` WHERE `user_id` = :id ORDER BY `id`',
                $p
            ),
            'payments'            => self::rows(
                'SELECT p.`reference`, b.`code` AS `booking_code`, p.`purpose`, p.`gateway`, p.`method`, p.`amount`,
                        p.`fee_amount`, p.`amount_refunded`, p.`currency`, p.`status`, p.`failure_reason`, p.`card_last4`,
                        p.`card_network`, p.`upi_vpa`, p.`bank_name`, p.`paid_at`, p.`created_at`
                   FROM `payments` p LEFT JOIN `bookings` b ON b.`id` = p.`booking_id`
                  WHERE p.`user_id` = :id ORDER BY p.`id`',
                $p
            ),
            'refunds'             => self::rows(
                'SELECT r.`reference`, b.`code` AS `booking_code`, r.`amount`, r.`currency`, r.`destination`, r.`reason`,
                        r.`notes`, r.`status`, r.`processed_at`, r.`created_at`
                   FROM `refunds` r LEFT JOIN `bookings` b ON b.`id` = r.`booking_id`
                  WHERE r.`user_id` = :id ORDER BY r.`id`',
                $p
            ),
            'invoices'            => self::rows(
                'SELECT i.`invoice_number`, b.`code` AS `booking_code`, i.`type`, i.`bill_to_name`, i.`bill_to_email`,
                        i.`bill_to_address`, i.`bill_to_gst`, i.`line_items`, i.`subtotal`, i.`fee_amount`, i.`tax_amount`,
                        i.`tax_rate`, i.`discount_amount`, i.`total`, i.`currency`, i.`status`, i.`issued_at`, i.`paid_at`
                   FROM `invoices` i LEFT JOIN `bookings` b ON b.`id` = i.`booking_id`
                  WHERE i.`user_id` = :id ORDER BY i.`id`',
                $p
            ),
            'withdrawals'         => self::rows(
                'SELECT `reference`, `amount`, `fee_amount`, `net_amount`, `currency`, `method`, `status`,
                        `payout_reference`, `remarks`, `rejection_reason`, `approved_at`, `processed_at`, `created_at`
                   FROM `withdrawal_requests` WHERE `user_id` = :id ORDER BY `id`',
                $p
            ),
            'payout_methods'      => self::rows(
                'SELECT `method`, `account_holder_name`, `account_last4`, `ifsc_code`, `bank_name`, `branch_name`,
                        `upi_id`, `is_primary`, `is_verified`, `created_at`
                   FROM `bank_accounts` WHERE `user_id` = :id AND `deleted_at` IS NULL',
                $p
            ),
            'coupons_used'        => self::rows(
                'SELECT b.`code` AS `booking_code`, cr.`discount_amount`, cr.`created_at`
                   FROM `coupon_redemptions` cr LEFT JOIN `bookings` b ON b.`id` = cr.`booking_id`
                  WHERE cr.`user_id` = :id',
                $p
            ),
        ]);

        $files['reviews.json'] = self::section(static fn (): array => [
            'written'  => self::rows(
                'SELECT b.`code` AS `booking_code`, u.`name` AS `about`, r.`direction`, r.`rating`, r.`quality_rating`,
                        r.`communication_rating`, r.`timeliness_rating`, r.`title`, r.`comment`, r.`media_urls`,
                        r.`reply`, r.`is_published`, r.`created_at`
                   FROM `reviews` r
                   LEFT JOIN `bookings` b ON b.`id` = r.`booking_id`
                   LEFT JOIN `users` u ON u.`id` = r.`reviewee_id`
                  WHERE r.`reviewer_id` = :id AND r.`deleted_at` IS NULL ORDER BY r.`id`',
                $p
            ),
            'received' => self::rows(
                'SELECT b.`code` AS `booking_code`, u.`name` AS `from`, r.`direction`, r.`rating`, r.`quality_rating`,
                        r.`communication_rating`, r.`timeliness_rating`, r.`title`, r.`comment`, r.`reply`,
                        r.`replied_at`, r.`is_published`, r.`created_at`
                   FROM `reviews` r
                   LEFT JOIN `bookings` b ON b.`id` = r.`booking_id`
                   LEFT JOIN `users` u ON u.`id` = r.`reviewer_id`
                  WHERE r.`reviewee_id` = :id AND r.`deleted_at` IS NULL ORDER BY r.`id`',
                $p
            ),
        ]);

        $files['verification.json'] = self::section(static fn (): array => self::verificationSummary($id));

        $files['activity.json'] = self::section(static fn (): array => [
            'saved_creators'  => self::rows(
                'SELECT c.`handle`, c.`display_name`, f.`created_at`
                   FROM `favorites` f JOIN `creators` c ON c.`id` = f.`creator_id` WHERE f.`user_id` = :id',
                $p
            ),
            'following'       => self::rows(
                'SELECT c.`handle`, c.`display_name`, fl.`created_at`
                   FROM `follows` fl JOIN `creators` c ON c.`id` = fl.`creator_id` WHERE fl.`follower_id` = :id',
                $p
            ),
            'search_history'  => self::rows(
                'SELECT `term`, `filters`, `result_count`, `created_at` FROM `search_histories` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p,
                [],
                1000
            ),
            'notifications'   => self::rows(
                'SELECT `type`, `category`, `title`, `body`, `is_read`, `read_at`, `created_at`
                   FROM `notifications` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p,
                [],
                1000
            ),
            'support_tickets' => self::rows(
                'SELECT `code`, `subject`, `category`, `priority`, `status`, `created_at`, `resolved_at`, `closed_at`,
                        `satisfaction_rating`, `satisfaction_comment`
                   FROM `support_tickets` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p
            ),
            // Internal agent notes are the support team's working papers about
            // a ticket, not something the user wrote or was sent.
            'support_messages' => self::rows(
                'SELECT t.`code` AS `ticket`, m.`sender_type`, m.`message`, m.`attachments`, m.`created_at`
                   FROM `support_ticket_messages` m JOIN `support_tickets` t ON t.`id` = m.`ticket_id`
                  WHERE t.`user_id` = :id AND m.`is_internal` = 0 ORDER BY m.`id`',
                $p
            ),
            'reports_filed'   => self::rows(
                'SELECT `reportable_type`, `reason`, `description`, `status`, `created_at` FROM `reports` WHERE `reporter_id` = :id ORDER BY `id`',
                $p
            ),
            'referrals_made'  => self::rows(
                'SELECT `code`, `referrer_reward` AS `your_reward`, `status`, `qualified_at`, `rewarded_at`, `created_at`
                   FROM `referrals` WHERE `referrer_user_id` = :id ORDER BY `id`',
                $p
            ),
            'referred_by'     => self::row(
                'SELECT `code`, `referred_reward` AS `your_reward`, `status`, `qualified_at`, `rewarded_at`, `created_at`
                   FROM `referrals` WHERE `referred_user_id` = :id LIMIT 1',
                $p
            ),
            'newsletter'      => self::row(
                'SELECT `email`, `status`, `subscribed_at`, `unsubscribed_at` FROM `newsletter_subscribers`
                  WHERE `user_id` = :id OR (`email` = :email AND `email` <> \'\') LIMIT 1',
                ['id' => $id, 'email' => (string) ($user['email'] ?? '')]
            ),
        ]);

        $files['README.txt'] = self::readme($user, $exportId, array_keys($files));

        return $files;
    }

    /**
     * Identity verification, as the KYC module chooses to describe it.
     *
     * KYC belongs to its own module, which decides what may leave it (types
     * and statuses, masked values, never document images or numbers). The
     * fallback below follows the same rule for installs that predate
     * KycService::exportSummary().
     *
     * @return array<string,mixed>
     */
    private static function verificationSummary(int $userId): array
    {
        if (class_exists(KycService::class) && method_exists(KycService::class, 'exportSummary')) {
            return (array) KycService::exportSummary($userId);
        }

        return [
            'profile'   => self::row(
                'SELECT `kind`, `status`, `reference`, `submitted_at`, `approved_at`, `expires_on`,
                        `is_identity_verified`, `is_business_verified`, `is_premium_verified`, `created_at`
                   FROM `kyc_profiles` WHERE `user_id` = :id AND `deleted_at` IS NULL ORDER BY `id` DESC LIMIT 1',
                ['id' => $userId]
            ),
            'documents' => self::rows(
                'SELECT `document_type`, `status`, `created_at`, `reviewed_at`
                   FROM `kyc_documents` WHERE `user_id` = :id AND `deleted_at` IS NULL ORDER BY `id`',
                ['id' => $userId]
            ),
            'note'      => 'Document numbers and images are not included in this export.',
        ];
    }

    /**
     * Run one section, turning a failure into an honest note in that file.
     *
     * @param  callable():array<string,mixed> $build
     * @return array<string,mixed>
     */
    private static function section(callable $build): array
    {
        try {
            return $build();
        } catch (\Throwable $e) {
            Logger::error('Data export section failed', ['error' => $e->getMessage()]);

            return ['error' => 'This part of your data could not be exported. Please contact support.'];
        }
    }

    /**
     * @param  array<string,mixed> $params
     * @param  list<string>        $omit
     * @return list<array<string,mixed>>
     */
    private static function rows(string $sql, array $params, array $omit = [], int $limit = self::MAX_ROWS): array
    {
        $rows = Database::select($sql . ' LIMIT ' . max(1, $limit), $params);

        return array_map(static fn (array $row): array => self::clean($row, $omit), $rows);
    }

    /**
     * @param  array<string,mixed> $params
     * @param  list<string>        $omit
     * @return array<string,mixed>|null
     */
    private static function row(string $sql, array $params, array $omit = []): ?array
    {
        $row = Database::selectOne($sql, $params);

        return $row === null ? null : self::clean($row, $omit);
    }

    /**
     * Drop the columns that never leave, and unpack JSON stored as text so
     * the reader sees structure rather than an escaped string.
     *
     * @param  array<string,mixed> $row
     * @param  list<string>        $omit
     * @return array<string,mixed>
     */
    private static function clean(array $row, array $omit): array
    {
        foreach ($row as $key => $value) {
            if (in_array($key, self::NEVER_EXPORT, true) || in_array($key, $omit, true)) {
                unset($row[$key]);
                continue;
            }

            if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $row[$key] = $decoded;
                }
            }
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $user
     * @param list<string>        $files
     */
    private static function readme(array $user, int $exportId, array $files): string
    {
        $descriptions = [
            'account.json'         => 'Your profile, contact details, preferences and verification flags',
            'security.json'        => 'Devices and sessions signed in to your account, and your recent account activity',
            'creator_profile.json' => 'Your creator profile: packages, portfolio, social accounts, credentials, experience, FAQs and availability',
            'campaigns.json'       => 'Campaigns you created (brands) or applied to (creators)',
            'bookings.json'        => 'Your bookings, the offers you made, and any disputes',
            'messages.json'        => 'Your conversations and the messages you sent',
            'payments.json'        => 'Payments, refunds, invoices, wallet transactions, withdrawals and payout methods',
            'reviews.json'         => 'Reviews you wrote and reviews you received',
            'verification.json'    => 'Your identity verification (KYC) status and the document types you submitted',
            'activity.json'        => 'Saved creators, follows, searches, notifications, support tickets, reports and referrals',
        ];

        $lines = [];

        foreach ($files as $file) {
            if (isset($descriptions[$file])) {
                $lines[] = str_pad($file, 22) . $descriptions[$file];
            }
        }

        $support = Config::settingString('general', 'support_email');
        $since   = !empty($user['created_at']) ? date('j M Y', (int) strtotime((string) $user['created_at'])) : 'unknown';

        return implode("\n", [
            'Zusty - a copy of your personal data',
            '====================================',
            '',
            'Account:     ' . (string) $user['name'] . ' (' . (string) $user['account_type'] . '), member since ' . $since,
            'Prepared:    ' . date('j M Y, g:i A') . ' (' . date_default_timezone_get() . ')',
            'Export ID:   ' . $exportId,
            '',
            'This archive holds the personal data Zusty keeps about your account, as',
            'JSON files. JSON is plain text: any text editor can open it, and most',
            'spreadsheet tools can import it. Dates and times are in the timezone above.',
            '',
            'Files',
            '-----',
            ...$lines,
            '',
            'What is not included, and why',
            '-----------------------------',
            '- Your password, security codes and sign-in tokens. Zusty never stores your',
            '  password itself, only a one-way fingerprint of it.',
            '- Full bank account numbers. Only the last four digits are shown.',
            '- Identity document numbers and images. Only their type and status are listed.',
            '- Files you uploaded (photos, videos, documents) are listed with their links',
            '  rather than copied in. The links keep working while your account is open.',
            '- Messages other people sent you. Those are their personal data. Your own',
            '  messages are included, with who you were talking to.',
            '',
            $support !== ''
                ? 'Questions about your data? Write to ' . $support . '.'
                : 'Questions about your data? Contact Zusty support from the app.',
            '',
        ]);
    }

    // -----------------------------------------------------------------
    // Files
    // -----------------------------------------------------------------

    /**
     * @param  array<string,mixed> $files
     * @return array{0:string,1:int,2:string} relative path, size, sha256
     */
    private static function writeArchive(int $userId, int $exportId, array $files): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is not installed.');
        }

        $directory = self::root() . DIRECTORY_SEPARATOR . $userId;

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the export directory.');
        }

        // The name is unguessable as well as unique. The token is what guards
        // the file, and this keeps a directory listing from being a map of it.
        $name     = 'export-' . $exportId . '-' . bin2hex(random_bytes(12)) . '.zip';
        $absolute = $directory . DIRECTORY_SEPARATOR . $name;

        $zip = new \ZipArchive();

        if ($zip->open($absolute, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Could not create the export archive.');
        }

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

        foreach ($files as $filename => $content) {
            $zip->addFromString(
                'zuste-data-export/' . $filename,
                is_string($content) ? $content : (string) json_encode($content, $flags)
            );
        }

        if (!$zip->close()) {
            @unlink($absolute);

            throw new \RuntimeException('Could not finish writing the export archive.');
        }

        clearstatcache(true, $absolute);

        return [$userId . '/' . $name, (int) filesize($absolute), (string) hash_file('sha256', $absolute)];
    }

    private static function root(): string
    {
        return rtrim((string) Config::get('storage.base_path'), '/\\') . DIRECTORY_SEPARATOR . 'exports';
    }

    /** The archive's absolute path, or null if it is gone or escapes the root. */
    private static function absolutePath(string $relative): ?string
    {
        $root   = realpath(self::root());
        $target = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . str_replace(['\\', '..'], ['/', ''], $relative));

        if ($root === false || $target === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR) || !is_file($target)) {
            return null;
        }

        return $target;
    }

    private static function deleteFile(string $relative): void
    {
        $absolute = self::absolutePath($relative);

        if ($absolute !== null) {
            @unlink($absolute);
        }
    }
}

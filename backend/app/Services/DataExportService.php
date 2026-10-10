<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Helpers\Str;

/**
 * "Download my data": a ZIP of JSON files holding the personal data Zusty
 * keeps about one account.
 *
 * The archive is built synchronously. One person's history is a few hundred
 * rows, far below the point where a queue would earn its keep, and building
 * it inline means the user gets their file while they are still looking at
 * the screen rather than "we'll email you". The file is stored under
 * storage/exports, outside the web root, and is only reachable through a
 * download link.
 *
 * The link's token is long, bound to one export, expires, and is capped in
 * uses. It is shown once, when it is issued, and only its hash is stored.
 * Another link for the same copy takes the same proof again
 * ({@see reissueLink()}) and retires the earlier one; changing the password
 * or signing out everywhere retires it too ({@see revokeLinks()}). Opening
 * the link shows a page, and only that page's button counts a download, so
 * link scanners cannot use the downloads up. The email that goes with an
 * export is a security notice with no link in it. The `privacy:purge-exports`
 * job deletes the file when the link expires or is used up, and so does the
 * owner's next visit to the privacy screen.
 *
 * What is deliberately NOT in the archive: secrets (password and token
 * hashes, push tokens), full bank account numbers, KYC document images and
 * full numbers, other people's messages and dispute statements, and our
 * team's internal notes. The README inside says so in plain words, so the
 * user is not left wondering whether something is missing.
 */
final class DataExportService
{
    /** Row cap per section, generous for one person, bounded for safety. */
    private const MAX_ROWS = 5000;

    /**
     * A request still `processing` after this long was abandoned: the PHP
     * time limit is 110 seconds, so nothing legitimately runs this long. It
     * then counts as failed, for the cooldown, the app and the purge job.
     */
    private const STUCK_MINUTES = 15;

    /**
     * The orphan sweep leaves files younger than this alone: an archive being
     * written right now, and the temporary file libzip writes beside it.
     */
    private const ORPHAN_GRACE_SECONDS = 7200;

    /** Columns that never leave the database, whichever table they are in. */
    private const NEVER_EXPORT = [
        'password_hash', 'token_hash', 'code_hash', 'download_token', 'fcm_token',
        'gateway_signature', 'gateway_response', 'idempotency_key', 'unsubscribe_token',
        'account_number', 'checksum', 'open_key',
    ];

    /**
     * Rows whose archive has to go: failed or expired, past the link's
     * lifetime, or out of downloads. Shared by the hourly purge and by the
     * per-user tidy-up that runs before an owner is shown their exports.
     */
    private const DEAD_ROWS = "SELECT `id`, `status`, `file_path` FROM `data_export_requests`
         WHERE `file_path` IS NOT NULL
           AND (`status` IN ('failed', 'expired')
                OR (`expires_at` IS NOT NULL AND `expires_at` <= NOW())
                OR `download_count` >= `max_downloads`)";

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
        ['title' => 'Bookings',          'detail' => 'Every booking and dispute you were part of, and the offers you made.'],
        ['title' => 'Messages',          'detail' => 'Your conversations and the messages you sent.'],
        ['title' => 'Payments & wallet', 'detail' => 'Payments, refunds, invoices, wallet transactions, withdrawals and payout methods (bank account numbers masked).'],
        ['title' => 'Reviews',           'detail' => 'Reviews you wrote and reviews you received.'],
        ['title' => 'Activity',          'detail' => 'Saved creators, searches, notifications, support tickets, reports, referrals, devices, sessions and your sign-in history.'],
    ];

    /**
     * Sections cut at their row cap during the current {@see collect()}, by
     * file and section, so the README and each file can say so.
     *
     * @var array<string,array<string,array{included:int,total:?int}>>
     */
    private static array $truncated = [];

    /** The archive file {@see section()} is building, for {@see rows()}. */
    private static string $currentFile = '';

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
     * `latest` never carries a link: a link is shown once, when it is issued
     * ({@see create()}, {@see reissueLink()}). `can_download` says a copy is
     * still on the server, so the app can offer to issue a new link.
     *
     * @return array<string,mixed>
     */
    public static function status(int $userId): array
    {
        // Retention does not rest on cron alone: whatever has run out is
        // deleted before the owner is shown it, so "the file was deleted"
        // is true whenever they can read it.
        self::purgeDeadFor($userId);

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
     * Refuse a new export while the cooldown runs.
     *
     * Called before the user re-authenticates as well as inside
     * {@see create()}, so a refused request never spends a one-time code.
     *
     * @throws HttpException 429 `export_cooldown`, with Retry-After
     */
    public static function assertCanRequest(int $userId): void
    {
        $next = self::nextAvailableAt($userId);

        if ($next !== null) {
            throw HttpException::tooManyRequests(
                'You can request a new copy of your data after ' . self::humanTime($next) . '.',
                $next - time(),
                'export_cooldown'
            );
        }
    }

    /**
     * When the next export may be requested, or null if it may be now.
     *
     * A failed attempt does not start the clock. The user got nothing, so
     * making them wait a day to retry would punish them for our failure. An
     * export still being prepared does count, unless it has been
     * `processing` for longer than any real export takes, which means the
     * process died and it is failed in all but name.
     */
    private static function nextAvailableAt(int $userId): ?int
    {
        $last = Database::scalar(
            "SELECT MAX(`created_at`) FROM `data_export_requests`
              WHERE `user_id` = :id
                AND (`status` IN ('ready', 'expired')
                     OR (`status` = 'processing' AND `created_at` >= NOW() - INTERVAL " . self::STUCK_MINUTES . ' MINUTE))',
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
     * The caller has already re-authenticated the user, or, for 'operator',
     * is a staff member acting on a support request whose sender was
     * confirmed to control the account's email address.
     *
     * The returned `download_url` is the only time this link is shown. The
     * app may open it; admin and CLI callers must never display it, because
     * an operator export is emailed to the account's own address instead.
     *
     * @param  array<string,mixed> $user The account row (the authenticated user).
     * @param  'password'|'email_code'|'sms_code'|'firebase'|'operator' $verifiedVia
     *         How the request was confirmed: the password, a one-time code by
     *         email or SMS, a fresh Google/Apple sign-in ('firebase'), or
     *         'operator' for staff acting on a confirmed support request.
     * @param  array<string,mixed> $device  ip / user_agent
     * @param  int|null            $adminId The staff member, for 'operator' from the panel.
     * @return array<string,mixed> {@see present()} plus `security_notice`:
     *         {channel: 'email'|'sms', destination: masked} or null.
     */
    public static function create(array $user, string $verifiedVia, array $device = [], ?int $adminId = null): array
    {
        $userId     = (int) $user['id'];
        $isOperator = $verifiedVia === 'operator';

        self::purgeDeadFor($userId);

        // An operator export is emailed to the account's own address. Without
        // one there is nowhere safe to send it, so nothing is prepared at all.
        if ($isOperator && trim((string) ($user['email'] ?? '')) === '') {
            throw HttpException::unprocessable('This account has no email address to send the copy to.', 'no_email');
        }

        $exportId = Database::transaction(static function () use ($userId, $verifiedVia, $device, $adminId, $isOperator): int {
            // Locking the account row serialises one account's exports: a
            // second request waits here, then sees the first one's row and
            // is refused. It also serialises against the account being
            // deleted, which updates the same row inside its transaction.
            $account = Database::lockForUpdate('users', 'id', $userId);

            if ($account === null || $account['deleted_at'] !== null) {
                throw HttpException::notFound('Account not found.');
            }

            self::assertCanRequest($userId);

            $row = [
                'user_id'       => $userId,
                'status'        => 'processing',
                'max_downloads' => self::maxDownloads(),
                'verified_via'  => $verifiedVia,
                'ip_address'    => isset($device['ip']) ? mb_substr((string) $device['ip'], 0, 45) : null,
                'user_agent'    => isset($device['user_agent']) ? mb_substr((string) $device['user_agent'], 0, 255) : null,
                'created_at'    => date('Y-m-d H:i:s'),
            ];

            if ($isOperator && $adminId !== null) {
                $row['requested_by_admin_id'] = $adminId;
            }

            return Database::insert('data_export_requests', $row);
        });

        $finished = false;
        $relative = null;

        // A fatal error (the time or memory limit) never reaches the catch
        // below. This removes a half-finished archive and fails the row, so
        // neither an orphaned file nor a phantom cooldown is left behind. A
        // killed process is covered by STUCK_MINUTES and the orphan sweep.
        register_shutdown_function(static function () use (&$finished, &$relative, $exportId): void {
            if ($finished) {
                return;
            }

            try {
                if ($relative !== null) {
                    self::deleteFile((string) $relative);
                }

                Database::execute(
                    "UPDATE `data_export_requests`
                        SET `status` = 'failed', `error` = 'Interrupted while preparing.', `completed_at` = NOW()
                      WHERE `id` = :id AND `status` = 'processing'",
                    ['id' => $exportId]
                );
            } catch (\Throwable) {
                // Nothing more can be done at shutdown.
            }
        });

        // Minted before anything is written, so a failure here leaves no file.
        $token     = Crypto::randomToken(32);
        $tokenHash = Crypto::hashToken($token);

        try {
            [$relative, $size, $checksum] = self::writeArchive($userId, $exportId, self::collect($user, $exportId));

            $expiresAt = time() + self::linkHours() * 3600;

            // Only while the account still exists and the row is still this
            // request's: an account deleted meanwhile, or a row the stuck
            // sweep already failed, must not end up with a live archive. The
            // account row is locked first, the order every other writer of
            // these two tables uses (a password change revoking links, an
            // account deletion), so this can never deadlock with them.
            $finalized = Database::transaction(static fn (): int => Database::lockForUpdate('users', 'id', $userId) === null
                ? 0
                : Database::execute(
                    "UPDATE `data_export_requests` e
                       JOIN `users` u ON u.`id` = e.`user_id` AND u.`deleted_at` IS NULL
                        SET e.`status` = 'ready', e.`file_path` = :path, e.`file_size` = :size, e.`checksum` = :sum,
                            e.`token_hash` = :hash, e.`download_token` = NULL, e.`expires_at` = :exp,
                            e.`completed_at` = NOW()
                      WHERE e.`id` = :id AND e.`status` = 'processing'",
                    [
                        'path' => $relative,
                        'size' => $size,
                        'sum'  => $checksum,
                        'hash' => $tokenHash,
                        'exp'  => date('Y-m-d H:i:s', $expiresAt),
                        'id'   => $exportId,
                    ]
                ));

            if ($finalized === 0) {
                throw new \RuntimeException('cancelled while preparing');
            }
        } catch (\Throwable $e) {
            if ($relative !== null) {
                self::deleteFile((string) $relative);
            }

            try {
                Database::execute(
                    "UPDATE `data_export_requests`
                        SET `status` = 'failed', `error` = :error, `completed_at` = NOW()
                      WHERE `id` = :id AND `status` = 'processing'",
                    ['error' => mb_substr($e->getMessage(), 0, 255), 'id' => $exportId]
                );
            } catch (\Throwable) {
                // The crash marker and the stuck sweep both retry this.
            }

            Logger::error('Personal data export failed', [
                'user_id'   => $userId,
                'export_id' => $exportId,
                'error'     => $e->getMessage(),
            ]);

            $finished = true;

            try {
                $gone = Database::scalar(
                    'SELECT 1 FROM `users` WHERE `id` = :id AND `deleted_at` IS NULL',
                    ['id' => $userId]
                ) === null;
            } catch (\Throwable) {
                $gone = false;
            }

            if ($gone) {
                throw HttpException::notFound('Account not found.');
            }

            throw new HttpException(
                'We could not prepare your data right now. Please try again in a few minutes.',
                500,
                'export_failed'
            );
        }

        $finished = true;

        ActivityLogger::log(
            'privacy.export_created',
            'user',
            $userId,
            $isOperator ? 'Personal data export prepared at the account holder\'s request.' : 'Personal data export prepared.',
            ['export_id' => $exportId, 'verified_via' => $verifiedVia, 'bytes' => $size]
                + ($isOperator ? ['requested_by_admin_id' => $adminId] : []),
            $isOperator ? ($adminId !== null ? 'admin' : 'system') : 'user',
            $isOperator ? $adminId : $userId,
            $isOperator ? null : (string) ($user['name'] ?? '')
        );

        $notice = self::notify($user, $expiresAt, $isOperator ? self::downloadUrl($token) : null);

        $row = Database::selectOne('SELECT * FROM `data_export_requests` WHERE `id` = :id', ['id' => $exportId]);

        return self::present((array) $row, $token) + ['security_notice' => $notice];
    }

    /**
     * The owner's security notice, sent whatever their email preferences:
     * this is an account-security message, not a notification. If someone
     * else asked for the export, it is how the owner finds out.
     *
     * Self-service, the notice carries no link. The link was shown in the app
     * to whoever confirmed the request, and an emailed link would sit in mail
     * queues and inboxes, be fetched by link scanners, and reach an address
     * nobody has verified. It is queued, so the request never waits on SMTP.
     * It also goes to the previous email address when the address changed
     * recently, and by SMS to an account that only has a phone number.
     *
     * An operator export ($link given) is different: there is no app session
     * to show the link in, so it goes to the account's own email address, once.
     *
     * Never throws: the export is finished either way.
     *
     * @param  array<string,mixed> $user
     * @return array{channel:string,destination:string}|null where the notice went
     */
    private static function notify(array $user, int $expiresAt, ?string $link = null): ?array
    {
        try {
            $userId   = (int) $user['id'];
            $name     = (string) ($user['name'] ?? '');
            $email    = trim((string) ($user['email'] ?? ''));
            $support  = trim(Config::settingString('general', 'support_email'));
            $replyTo  = $support !== '' ? $support : null;
            $subject  = 'Your Zusty data export is ready';
            $until    = self::humanTime($expiresAt);
            $uses     = self::maxDownloads();
            $usesText = $uses === 1 ? '1 download' : $uses . ' downloads';

            if ($link !== null) {
                if ($email === '') {
                    return null;
                }

                $body = 'You asked our support team for a copy of the personal data on your Zusty account, and it is ready. '
                    . 'Use the button below to download it. The link works until ' . $until . ' for up to ' . $usesText
                    . ', then the copy is deleted. Keep this email private: anyone with the link can download your data. '
                    . 'If you did not ask for this, contact us straight away'
                    . ($support !== '' ? ' at ' . $support : '') . '.';

                $html = MailService::renderDefault('Your data export is ready', $body, $name, 'url', $link);
                $text = ($name !== '' ? 'Hi ' . $name . ",\n\n" : "Hello,\n\n") . $body . "\n\nDownload your data: " . $link . "\n";

                if (!MailService::sendNow($email, $name, $subject, $html, $text, $replyTo)) {
                    Logger::warning('Data export email was not delivered straight away', ['user_id' => $userId]);
                }

                return ['channel' => 'email', 'destination' => Str::maskEmail($email)];
            }

            $body = 'You asked for a copy of the personal data on your Zusty account, and it is ready. '
                . 'To download it, open the Zusty app and go to Settings → Privacy & data → Download my data. '
                . 'It is available until ' . $until . ' for up to ' . $usesText . ', then it is deleted. '
                . 'If you did not ask for this, change your password straight away (that also cancels the download link)'
                . ($support !== '' ? ' and contact us at ' . $support : '') . '.';

            $html   = MailService::renderDefault('Your data export is ready', $body, $name);
            $notice = null;

            if ($email !== '' && MailService::queue($email, $name, $subject, $html, null, $replyTo) > 0) {
                $notice = ['channel' => 'email', 'destination' => Str::maskEmail($email)];
            }

            // The address in use until a recent change. If someone else made
            // that change, the real owner still hears about this export.
            try {
                $previous = PrivacyService::previousEmail($userId);

                if ($previous !== null && $previous !== '' && strcasecmp($previous, $email) !== 0) {
                    MailService::queue($previous, $name, $subject, $html, null, $replyTo);
                }
            } catch (\Throwable $e) {
                Logger::warning('Data export notice could not be sent to the previous address', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
            }

            if ($notice === null) {
                $phone = trim((string) ($user['phone'] ?? ''));
                $to    = $phone === '' ? '' : Str::normalizePhone((string) ($user['phone_country_code'] ?? '') . $phone);

                // SmsService::queue() sends nothing, and returns 0, while SMS is off.
                if ($to !== '' && SmsService::queue(
                    $to,
                    'Zusty: a copy of your account data was prepared. If this wasn\'t you, change your password and contact '
                        . ($support !== '' ? $support : 'Zusty support') . '.'
                ) > 0) {
                    $notice = [
                        'channel'     => 'sms',
                        'destination' => trim((string) ($user['phone_country_code'] ?? '') . ' ' . Str::maskPhone($phone)),
                    ];
                }
            }

            return $notice;
        } catch (\Throwable $e) {
            // The export is ready either way. A mail or SMS outage must not
            // turn it into an error.
            Logger::warning('Data export notice could not be sent', ['user_id' => (int) $user['id'], 'error' => $e->getMessage()]);

            return null;
        }
    }

    // -----------------------------------------------------------------
    // Links
    // -----------------------------------------------------------------

    /**
     * Refuse to issue a link for a copy that is not the user's, or that can
     * no longer be downloaded. Run before the user re-authenticates, so a
     * dead copy does not spend a one-time code.
     *
     * @throws HttpException 404 `not_found`, 410 `export_unavailable`
     */
    public static function assertLinkable(int $userId, int $exportId): void
    {
        self::assertRowLinkable(Database::selectOne(
            'SELECT * FROM `data_export_requests` WHERE `id` = :id AND `user_id` = :user',
            ['id' => $exportId, 'user' => $userId]
        ));
    }

    /**
     * Issue a new link for a copy still on the server, and retire every
     * earlier one (an old email, a browser history entry, a revoked link).
     *
     * The copy's expiry and download count stay as they are: a new link
     * never extends how long the archive is kept or how often it can be used.
     * The caller has re-authenticated the user.
     *
     * @return array<string,mixed> {@see present()} with the new `download_url`,
     *                             and `security_notice` null.
     */
    public static function reissueLink(int $userId, int $exportId): array
    {
        $token = Crypto::randomToken(32);

        $row = Database::transaction(static function () use ($userId, $exportId, $token): array {
            $row = Database::selectOne(
                'SELECT * FROM `data_export_requests` WHERE `id` = :id AND `user_id` = :user FOR UPDATE',
                ['id' => $exportId, 'user' => $userId]
            );

            self::assertRowLinkable($row);

            Database::update('data_export_requests', [
                'token_hash'     => Crypto::hashToken($token),
                'download_token' => null,
            ], ['id' => $exportId]);

            return (array) $row;
        });

        $name = Database::scalar('SELECT `name` FROM `users` WHERE `id` = :id', ['id' => $userId]);

        ActivityLogger::log(
            'privacy.export_link_issued',
            'user',
            $userId,
            'A new download link was issued for a personal data export.',
            ['export_id' => $exportId],
            'user',
            $userId,
            is_string($name) ? $name : null
        );

        return self::present($row, $token) + ['security_notice' => null];
    }

    /**
     * Retire every live download link of one account. The archives stay
     * until they expire, so the owner can get a new link after proving it is
     * them again.
     *
     * Called when an account's sessions are revoked: password change or
     * reset, sign out everywhere, refresh-token reuse, staff action. One
     * plain UPDATE, no transaction of its own and no file work, so it is safe
     * inside the caller's transaction. Returns the rows changed.
     */
    public static function revokeLinks(int $userId): int
    {
        return Database::execute(
            'UPDATE `data_export_requests` SET `token_hash` = NULL, `download_token` = NULL
              WHERE `user_id` = :id AND `token_hash` IS NOT NULL',
            ['id' => $userId]
        );
    }

    /**
     * @param array<string,mixed>|null $row
     */
    private static function assertRowLinkable(?array $row): void
    {
        if ($row === null) {
            throw HttpException::notFound('That copy of your data was not found.');
        }

        $expired = !empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time();
        $left    = (int) $row['max_downloads'] - (int) $row['download_count'];

        if ($row['status'] !== 'ready' || $expired || $left <= 0 || empty($row['file_path'])
            || self::absolutePath((string) $row['file_path']) === null) {
            throw new HttpException('This copy has expired or been used up. Request a new one.', 410, 'export_unavailable');
        }
    }

    // -----------------------------------------------------------------
    // Download
    // -----------------------------------------------------------------

    /**
     * What a download link points at, without using it: for the page a link
     * opens. Never counts, never deletes.
     *
     * @return array{export_id:int,user_id:int,file_size:int,expires_at:?string,downloads_left:int,filename:string}
     */
    public static function peek(string $token): array
    {
        $row = self::resolve($token);

        return [
            'export_id'      => (int) $row['id'],
            'user_id'        => (int) $row['user_id'],
            'file_size'      => (int) $row['file_size'],
            'expires_at'     => self::iso($row['expires_at'] ?? null),
            'downloads_left' => max(0, (int) $row['max_downloads'] - (int) $row['download_count']),
            'filename'       => self::filename($row),
        ];
    }

    /**
     * Resolve a download token to the archive, counting the use.
     *
     * A link that has expired or run out of downloads is cleaned up on the
     * spot (archive deleted, token forgotten), so it answers 404 from then on.
     *
     * @return array{path:string,filename:string,export_id:int,user_id:int}
     */
    public static function consume(string $token): array
    {
        $found = null;

        try {
            $row = self::resolve($token, $found);
        } catch (HttpException $e) {
            if ($found !== null && in_array($e->errorCode(), ['export_expired', 'export_download_limit'], true)) {
                self::expireRow($found);
            }

            throw $e;
        }

        // Counted atomically, so two simultaneous clicks cannot both squeeze
        // past the last remaining use.
        $counted = Database::execute(
            "UPDATE `data_export_requests`
                SET `download_count` = `download_count` + 1, `last_downloaded_at` = NOW()
              WHERE `id` = :id AND `status` = 'ready' AND `download_count` < `max_downloads`",
            ['id' => (int) $row['id']]
        );

        if ($counted === 0) {
            self::expireRow($row);

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
            'path'      => (string) self::absolutePath((string) $row['file_path']),
            'filename'  => self::filename($row),
            'export_id' => (int) $row['id'],
            'user_id'   => (int) $row['user_id'],
        ];
    }

    /**
     * Look a token up and check it can still be used. Reads only.
     *
     * @param  array<string,mixed>|null $found The row looked up, set even when
     *                                         a check fails, for clean-up.
     * @return array<string,mixed>
     */
    private static function resolve(string $token, ?array &$found = null): array
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

        $found   = $row;
        $expired = new HttpException(
            'This download link has expired. Request a new copy of your data from the app.',
            410,
            'export_expired'
        );

        if ($row['status'] === 'expired'
            || ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time())) {
            throw $expired;
        }

        if ($row['status'] !== 'ready' || $row['file_path'] === null) {
            throw $invalid;
        }

        if ((int) $row['download_count'] >= (int) $row['max_downloads']) {
            throw new HttpException(
                'This download link has been used the maximum number of times. Request a new copy of your data from the app.',
                410,
                'export_download_limit'
            );
        }

        // Missing or unreadable: refused before anything is counted.
        if (self::absolutePath((string) $row['file_path']) === null) {
            throw $expired;
        }

        return $row;
    }

    public static function downloadUrl(string $token): string
    {
        return rtrim((string) Config::get('app.api_url'), '/') . '/v1/privacy/exports/download?token=' . rawurlencode($token);
    }

    /**
     * Where the link's page posts its form: the download URL's path, with no
     * host and no query, so the token travels only in the request body.
     */
    public static function downloadPath(): string
    {
        $path = parse_url(self::downloadUrl('x'), PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/api/v1/privacy/exports/download';
    }

    /** @param array<string,mixed> $row */
    private static function filename(array $row): string
    {
        return 'zuste-data-export-' . date('Y-m-d', (int) strtotime((string) $row['created_at'])) . '.zip';
    }

    // -----------------------------------------------------------------
    // Housekeeping
    // -----------------------------------------------------------------

    /**
     * Cron: delete archives whose link expired or was used up, fail any
     * request a crashed process left in `processing`, and delete archive
     * files that no live export points at.
     *
     * @return array{purged:int,stuck:int,orphans:int,failed_deletes:int}
     */
    public static function purgeExpired(): array
    {
        $purged = 0;
        $failed = 0;

        foreach (Database::select(self::DEAD_ROWS) as $row) {
            if (self::expireRow($row)) {
                $purged++;
            } else {
                $failed++;
            }
        }

        $stuck = Database::execute(
            "UPDATE `data_export_requests`
                SET `status` = 'failed', `error` = 'Timed out while preparing.', `completed_at` = NOW()
              WHERE `status` = 'processing' AND `created_at` < NOW() - INTERVAL " . self::STUCK_MINUTES . ' MINUTE'
        );

        [$orphans, $orphanFailures] = self::sweepOrphans();

        // Per-user folders left empty, either by the deletions above or by an
        // account deletion whose rmdir lost a race with a virus scanner still
        // holding the archive open (Windows keeps a deleted file "pending"
        // until the last handle closes).
        foreach (glob(self::root() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (count(scandir($directory) ?: []) <= 2) {
                @rmdir($directory);
            }
        }

        return [
            'purged'         => $purged,
            'stuck'          => $stuck,
            'orphans'        => $orphans,
            'failed_deletes' => $failed + $orphanFailures,
        ];
    }

    /**
     * Account deletion: remove every archive and every record of one. The
     * rows carry the IP and browser of each request, which is personal data
     * like everything else being erased.
     *
     * A row is deleted only once its file is gone, so a file that could not
     * be deleted is retried by the next call rather than forgotten. Files no
     * row points at (a write that failed half-way) go too.
     *
     * @return int The rows still left for the user: 0 means done.
     */
    public static function purgeForUser(int $userId): int
    {
        foreach (Database::select(
            'SELECT `id`, `file_path` FROM `data_export_requests` WHERE `user_id` = :id',
            ['id' => $userId]
        ) as $row) {
            $path = (string) ($row['file_path'] ?? '');

            if ($path === '' || self::deleteFile($path)) {
                Database::execute('DELETE FROM `data_export_requests` WHERE `id` = :id', ['id' => (int) $row['id']]);
            }
        }

        $directory = self::root() . DIRECTORY_SEPARATOR . $userId;
        $stuck     = [];

        if (is_dir($directory)) {
            foreach (scandir($directory) ?: [] as $name) {
                $file = $directory . DIRECTORY_SEPARATOR . $name;

                if (!str_starts_with($name, 'export-') || !is_file($file)) {
                    continue;
                }

                @unlink($file);
                clearstatcache(true, $file);

                if (is_file($file)) {
                    $stuck[] = $name;
                }
            }

            @rmdir($directory);
        }

        if ($stuck !== []) {
            Logger::warning('Data export files could not be deleted for an erased account', [
                'user_id' => $userId,
                'files'   => $stuck,
            ]);
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `data_export_requests` WHERE `user_id` = :id',
            ['id' => $userId]
        );
    }

    /**
     * Delete one export's archive, then settle its row: 'failed' stays
     * 'failed', anything else becomes 'expired', and the file path and the
     * token are cleared. A file that could not be deleted leaves the row as
     * it is, so the next run tries again rather than losing track of it.
     *
     * @param  array<string,mixed> $row id, status, file_path
     * @return bool true when the archive is gone and the row settled
     */
    private static function expireRow(array $row): bool
    {
        $path = (string) ($row['file_path'] ?? '');

        if ($path !== '' && !self::deleteFile($path)) {
            Logger::warning('A data export archive could not be deleted; it will be retried', [
                'export_id' => (int) $row['id'],
            ]);

            return false;
        }

        Database::execute(
            "UPDATE `data_export_requests`
                SET `status` = IF(`status` = 'failed', 'failed', 'expired'),
                    `file_path` = NULL, `token_hash` = NULL, `download_token` = NULL
              WHERE `id` = :id",
            ['id' => (int) $row['id']]
        );

        return true;
    }

    /**
     * The purge job's selection, for one account: run before the owner is
     * shown their exports and before a new one is made. Best effort: a
     * failure here is logged and never blocks the screen.
     */
    private static function purgeDeadFor(int $userId): void
    {
        try {
            foreach (Database::select(self::DEAD_ROWS . ' AND `user_id` = :id', ['id' => $userId]) as $row) {
                self::expireRow($row);
            }
        } catch (\Throwable $e) {
            Logger::warning('Could not tidy up expired data exports', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Archive files no live export points at: a write that failed after the
     * file was created, a process killed mid-way, a row deleted without its
     * file. A file is kept only while a 'ready' row with its id names it.
     *
     * @return array{0:int,1:int} files deleted, files that could not be
     */
    private static function sweepOrphans(): array
    {
        $deleted = 0;
        $failed  = 0;

        foreach (glob(self::root() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'export-*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $modified = @filemtime($file);

            if ($modified === false || $modified > time() - self::ORPHAN_GRACE_SECONDS) {
                continue;
            }

            $name     = basename($file);
            $relative = basename(dirname($file)) . '/' . $name;
            $id       = preg_match('/^export-(\d+)-/', $name, $match) === 1 ? (int) $match[1] : 0;

            $live = $id > 0 && Database::scalar(
                "SELECT 1 FROM `data_export_requests` WHERE `id` = :id AND `status` = 'ready' AND `file_path` = :path",
                ['id' => $id, 'path' => $relative]
            ) !== null;

            if ($live) {
                continue;
            }

            @unlink($file);
            clearstatcache(true, $file);

            if (is_file($file)) {
                $failed++;
                Logger::warning('An orphaned data export file could not be deleted', ['file' => $relative]);
            } else {
                $deleted++;
            }
        }

        return [$deleted, $failed];
    }

    // -----------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------

    /**
     * Where an export stands, in the terms the admin shows.
     *
     * Needs `status`, `created_at`, `expires_at`, `download_count` and
     * `max_downloads`. `has_link` (token_hash IS NOT NULL) is optional: only
     * when it is present and false is a copy reported 'revoked'. A row left
     * `processing` for longer than any export takes is reported 'failed'.
     *
     * @param  array<string,mixed> $row
     * @return 'processing'|'failed'|'expired'|'used_up'|'revoked'|'ready'
     */
    public static function state(array $row): string
    {
        $status = (string) ($row['status'] ?? '');

        if ($status === 'processing') {
            $created = strtotime((string) ($row['created_at'] ?? ''));

            return $created !== false && $created < time() - self::STUCK_MINUTES * 60 ? 'failed' : 'processing';
        }

        if ($status !== 'ready') {
            return $status === 'expired' ? 'expired' : 'failed';
        }

        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time()) {
            return 'expired';
        }

        if ((int) ($row['download_count'] ?? 0) >= (int) ($row['max_downloads'] ?? 0)) {
            return 'used_up';
        }

        if (array_key_exists('has_link', $row) && !$row['has_link']) {
            return 'revoked';
        }

        return 'ready';
    }

    /**
     * One export as the app sees it.
     *
     * `status` keeps the four values the app knows: a used-up copy is 'ready'
     * with `can_download` false, and an abandoned one is 'failed'.
     * `can_download` means the copy is still on the server and a link can be
     * issued for it, also after its last link was revoked. `download_url` is
     * set only when the caller passes a link it has just minted.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function present(array $row, ?string $token = null): array
    {
        $state  = self::state($row);
        $status = in_array($state, ['used_up', 'revoked'], true) ? 'ready' : $state;
        $left   = max(0, (int) $row['max_downloads'] - (int) $row['download_count']);

        $canDownload = in_array($state, ['ready', 'revoked'], true)
            && $left > 0
            && !empty($row['file_path'])
            && self::absolutePath((string) $row['file_path']) !== null;

        return [
            'id'                 => (int) $row['id'],
            'status'             => $status,
            'file_size'          => $row['file_size'] === null ? null : (int) $row['file_size'],
            'download_count'     => (int) $row['download_count'],
            'max_downloads'      => (int) $row['max_downloads'],
            'downloads_left'     => $left,
            'can_download'       => $canDownload,
            'download_url'       => $canDownload && $token !== null ? self::downloadUrl($token) : null,
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
     * the user getting nothing. A section cut at its row cap is listed in the
     * file's `truncated` map and in the README.
     *
     * @param  array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function collect(array $user, int $exportId = 0): array
    {
        $id        = (int) $user['id'];
        $creatorId = array_key_exists('creator_id', $user)
            ? (int) ($user['creator_id'] ?? 0)
            : (int) (Database::scalar(
                'SELECT `id` FROM `creators` WHERE `user_id` = :id AND `deleted_at` IS NULL',
                ['id' => $id]
            ) ?? 0);
        $isBrand   = ($user['account_type'] ?? '') === 'brand';
        $p         = ['id' => $id];

        self::$truncated = [];

        $files = [];

        $files['account.json'] = self::section('account.json', static fn (): array => [
            'account'        => self::row(
                'SELECT * FROM `users` WHERE `id` = :id',
                $p,
                ['suspended_reason', 'deletion_requested_at', 'guest_expires_at', 'converted_at', 'deleted_at', 'kyc_profile_id']
            ),
            'contact_points' => self::rows(
                'SELECT `channel`, `label`, `value`, `phone_country_code`, `verified_at`, `is_recovery`, `created_at`
                   FROM `user_contact_points` WHERE `user_id` = :id AND `deleted_at` IS NULL ORDER BY `sort_order`, `id`',
                $p,
                label: 'contact_points'
            ),
        ]);

        $files['security.json'] = self::section('security.json', static fn (): array => [
            'sessions'             => self::rows(
                'SELECT `device_name`, `platform`, `ip_address`, `user_agent`, `created_at`, `last_used_at`,
                        `expires_at`, `revoked_at`, `revoked_reason`
                   FROM `refresh_tokens` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p,
                label: 'sessions'
            ),
            'devices'              => self::rows(
                'SELECT `platform`, `device_model`, `os_version`, `app_version`, `locale`, `push_enabled`,
                        `last_active_at`, `created_at`
                   FROM `user_devices` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p,
                label: 'devices'
            ),
            // Every sign-in attempt on the account, failed ones included. The
            // identifier typed is left out: it is the email or phone already
            // in account.json, or someone else's guess.
            'sign_in_history'      => self::rows(
                "SELECT `outcome`, `auth_method`, `ip_address`, `user_agent`, `country`, `device_type`, `is_new_device`,
                        `failure_reason`, `created_at`
                   FROM `login_history` WHERE `actor_type` = 'user' AND `actor_id` = :id ORDER BY `id` DESC",
                $p,
                label: 'sign_in_history'
            ),
            'account_activity'     => self::rows(
                "SELECT `action`, `description`, `ip_address`, `user_agent`, `created_at`
                   FROM `activity_logs` WHERE `actor_type` = 'user' AND `actor_id` = :id ORDER BY `id` DESC",
                $p,
                label: 'account_activity'
            ),
            // Staff review notes and who handled the request stay internal.
            'contact_changes'      => self::rows(
                'SELECT `channel`, `target`, `current_value`, `new_value`, `new_phone_country_code`, `reason_code`,
                        `reason_note`, `status`, INET6_NTOA(`ip_address`) AS `ip_address`, `user_agent`, `created_at`,
                        `verified_at`, `applied_at`, `closed_at`
                   FROM `contact_change_requests` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p,
                label: 'contact_changes'
            ),
            'contact_verification' => self::rows(
                'SELECT `channel`, `target`, `destination`, `action`, `from_status`, `to_status`,
                        INET6_NTOA(`ip_address`) AS `ip_address`, `user_agent`, `created_at`
                   FROM `contact_verification_events` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p,
                label: 'contact_verification'
            ),
            // Earlier exports. For one made by our support team, the network
            // details are the staff member's, not the account holder's.
            'data_exports'         => self::rows(
                "SELECT `status`, `verified_via`,
                        IF(`verified_via` = 'operator', NULL, `ip_address`) AS `ip_address`,
                        IF(`verified_via` = 'operator', NULL, `user_agent`) AS `user_agent`,
                        `download_count`, `max_downloads`, `created_at`, `completed_at`, `expires_at`, `last_downloaded_at`
                   FROM `data_export_requests` WHERE `user_id` = :id AND `id` <> :export ORDER BY `id` DESC",
                ['id' => $id, 'export' => $exportId],
                label: 'data_exports'
            ),
        ]);

        if ($creatorId > 0) {
            $c = ['cid' => $creatorId];

            $files['creator_profile.json'] = self::section('creator_profile.json', static fn (): array => [
                'profile'        => self::row('SELECT * FROM `creators` WHERE `id` = :cid', $c, ['search_boost', 'approved_by']),
                'categories'     => self::rows(
                    'SELECT cat.`name` FROM `creator_categories` cc
                       JOIN `categories` cat ON cat.`id` = cc.`category_id` WHERE cc.`creator_id` = :cid',
                    $c,
                    label: 'categories'
                ),
                'profile_fields' => self::rows(
                    'SELECT pt.`taxonomy_key`, tt.`label`, pt.`is_primary`, pt.`is_visible`
                       FROM `creator_profile_terms` pt
                       LEFT JOIN `taxonomy_terms` tt ON tt.`id` = pt.`term_id`
                      WHERE pt.`creator_id` = :cid ORDER BY pt.`taxonomy_key`, pt.`sort_order`',
                    $c,
                    label: 'profile_fields'
                ),
                'packages'        => self::rows('SELECT * FROM `creator_packages` WHERE `creator_id` = :cid AND `deleted_at` IS NULL', $c, ['id', 'creator_id', 'deleted_at'], label: 'packages'),
                'portfolio'       => self::rows(
                    'SELECT * FROM `creator_portfolio` WHERE `creator_id` = :cid AND `deleted_at` IS NULL',
                    $c,
                    ['id', 'creator_id', 'reviewed_by', 'moderation_note', 'deleted_at'],
                    label: 'portfolio'
                ),
                'social_accounts' => self::rows('SELECT * FROM `creator_social_accounts` WHERE `creator_id` = :cid', $c, ['id', 'creator_id'], label: 'social_accounts'),
                'credentials'     => self::rows('SELECT * FROM `creator_credentials` WHERE `creator_id` = :cid', $c, ['id', 'creator_id', 'verified_by'], label: 'credentials'),
                'experience'      => self::rows('SELECT * FROM `creator_experience` WHERE `creator_id` = :cid', $c, ['id', 'creator_id'], label: 'experience'),
                'faqs'            => self::rows('SELECT `question`, `answer`, `is_visible`, `created_at` FROM `creator_faqs` WHERE `creator_id` = :cid', $c, label: 'faqs'),
                'working_hours'   => self::rows('SELECT `weekday`, `is_working`, `starts_at`, `ends_at`, `note` FROM `creator_working_hours` WHERE `creator_id` = :cid', $c, label: 'working_hours'),
                'availability'    => self::rows(
                    'SELECT `available_on`, `is_available`, `slots`, `max_bookings`, `booked_count`, `note`
                       FROM `creator_availability` WHERE `creator_id` = :cid ORDER BY `available_on`',
                    $c,
                    label: 'availability'
                ),
                'badges'          => self::rows('SELECT `badge`, `status`, `awarded_at`, `expires_at`, `revoked_at` FROM `creator_badges` WHERE `creator_id` = :cid', $c, label: 'badges'),
                'handle_history'  => self::rows('SELECT `old_handle`, `new_handle`, `reason`, `created_at` FROM `creator_handle_history` WHERE `creator_id` = :cid', $c, label: 'handle_history'),
            ]);
        }

        $files['campaigns.json'] = self::section('campaigns.json', static fn (): array => $isBrand
            ? [
                'campaigns_created' => self::rows(
                    'SELECT `code`, `title`, `description`, `platform`, `deliverable_type`, `posts_count`, `budget_min`,
                            `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`,
                            `applications_count`, `hired_count`, `status`, `is_public`, `created_at`
                       FROM `campaigns` WHERE `brand_user_id` = :id AND `deleted_at` IS NULL ORDER BY `id` DESC',
                    $p,
                    label: 'campaigns_created'
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
                    $p,
                    label: 'applications'
                ),
            ]);

        $files['bookings.json'] = self::section('bookings.json', static fn (): array => [
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
                $p,
                label: 'bookings'
            ),
            'deliverables' => self::rows(
                'SELECT b.`code` AS `booking_code`, d.`title`, d.`description`, d.`content_url`, d.`media_url`,
                        d.`thumbnail_url`, d.`metrics`, d.`status`, d.`revision_count`, d.`remarks`, d.`submitted_at`,
                        d.`reviewed_at`, d.`created_at`
                   FROM `booking_deliverables` d JOIN `bookings` b ON b.`id` = d.`booking_id`
                  WHERE (b.`brand_user_id` = :id OR b.`creator_user_id` = :id) AND b.`deleted_at` IS NULL
                  ORDER BY d.`id` DESC',
                $p,
                label: 'deliverables'
            ),
            // The booking's status history, as both parties see it in the app.
            'timeline' => self::rows(
                'SELECT b.`code` AS `booking_code`, h.`from_status`, h.`to_status`, h.`changed_by_type`, h.`note`, h.`created_at`
                   FROM `booking_status_history` h JOIN `bookings` b ON b.`id` = h.`booking_id`
                  WHERE (b.`brand_user_id` = :id OR b.`creator_user_id` = :id) AND b.`deleted_at` IS NULL
                  ORDER BY h.`id` DESC',
                $p,
                label: 'timeline'
            ),
            'offers_you_made' => self::rows(
                'SELECT b.`code` AS `booking_code`, o.`amount`, o.`posts_count`, o.`delivery_days`, o.`message`,
                        o.`status`, o.`responded_at`, o.`created_at`
                   FROM `booking_offers` o JOIN `bookings` b ON b.`id` = o.`booking_id`
                  WHERE o.`offered_by_id` = :id ORDER BY o.`id` DESC',
                $p,
                label: 'offers_you_made'
            ),
            // Every dispute on the user's bookings, with its outcome. What the
            // other party wrote and submitted is theirs, and the team's
            // decision notes are internal, so neither is included.
            'disputes' => self::rows(
                'SELECT b.`code` AS `booking_code`, d.`raised_by`, IF(d.`raised_by_id` = :id, 1, 0) AS `raised_by_you`,
                        d.`reason`, IF(d.`raised_by_id` = :id, d.`description`, NULL) AS `description`,
                        IF(d.`raised_by_id` = :id, d.`evidence_urls`, NULL) AS `evidence_urls`,
                        d.`status`, d.`refund_amount`, d.`payout_amount`, d.`resolved_at`, d.`created_at`
                   FROM `booking_disputes` d JOIN `bookings` b ON b.`id` = d.`booking_id`
                  WHERE b.`brand_user_id` = :id OR b.`creator_user_id` = :id ORDER BY d.`id` DESC',
                $p,
                label: 'disputes'
            ),
        ]);

        $files['messages.json'] = self::section('messages.json', static fn (): array => [
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
                $p,
                label: 'conversations'
            ),
            'messages_sent' => self::rows(
                "SELECT `conversation_id`, `type`, `body`, `media_url`, `created_at`, `edited_at`
                   FROM `messages` WHERE `sender_id` = :id AND `sender_type` = 'user' AND `deleted_at` IS NULL ORDER BY `id`",
                $p,
                [],
                20000,
                'messages_sent'
            ),
        ]);

        $files['payments.json'] = self::section('payments.json', static fn (): array => [
            'wallet'              => self::row(
                'SELECT `currency`, `balance`, `locked_balance`, `lifetime_credit`, `lifetime_debit`, `is_frozen`, `created_at`
                   FROM `wallets` WHERE `user_id` = :id',
                $p
            ),
            'wallet_transactions' => self::rows(
                'SELECT `reference`, `direction`, `category`, `amount`, `balance_before`, `balance_after`, `currency`,
                        `status`, `reference_type`, `description`, `created_at`
                   FROM `wallet_transactions` WHERE `user_id` = :id ORDER BY `id`',
                $p,
                label: 'wallet_transactions'
            ),
            'payments'            => self::rows(
                'SELECT p.`reference`, b.`code` AS `booking_code`, p.`purpose`, p.`gateway`, p.`method`, p.`amount`,
                        p.`fee_amount`, p.`amount_refunded`, p.`currency`, p.`status`, p.`failure_reason`, p.`card_last4`,
                        p.`card_network`, p.`upi_vpa`, p.`bank_name`, p.`paid_at`, p.`created_at`
                   FROM `payments` p LEFT JOIN `bookings` b ON b.`id` = p.`booking_id`
                  WHERE p.`user_id` = :id ORDER BY p.`id`',
                $p,
                label: 'payments'
            ),
            // The team's notes on a refund are internal working notes.
            'refunds'             => self::rows(
                'SELECT r.`reference`, b.`code` AS `booking_code`, r.`amount`, r.`currency`, r.`destination`, r.`reason`,
                        r.`status`, r.`processed_at`, r.`created_at`
                   FROM `refunds` r LEFT JOIN `bookings` b ON b.`id` = r.`booking_id`
                  WHERE r.`user_id` = :id ORDER BY r.`id`',
                $p,
                label: 'refunds'
            ),
            'invoices'            => self::rows(
                'SELECT i.`invoice_number`, b.`code` AS `booking_code`, i.`type`, i.`bill_to_name`, i.`bill_to_email`,
                        i.`bill_to_address`, i.`bill_to_gst`, i.`line_items`, i.`subtotal`, i.`fee_amount`, i.`tax_amount`,
                        i.`tax_rate`, i.`discount_amount`, i.`total`, i.`currency`, i.`status`, i.`issued_at`, i.`paid_at`
                   FROM `invoices` i LEFT JOIN `bookings` b ON b.`id` = i.`booking_id`
                  WHERE i.`user_id` = :id ORDER BY i.`id`',
                $p,
                label: 'invoices'
            ),
            'withdrawals'         => self::rows(
                'SELECT `reference`, `amount`, `fee_amount`, `net_amount`, `currency`, `method`, `status`,
                        `payout_reference`, `remarks`, `rejection_reason`, `approved_at`, `processed_at`, `created_at`
                   FROM `withdrawal_requests` WHERE `user_id` = :id ORDER BY `id`',
                $p,
                label: 'withdrawals'
            ),
            'payout_methods'      => self::rows(
                'SELECT `method`, `account_holder_name`, `account_last4`, `ifsc_code`, `bank_name`, `branch_name`,
                        `upi_id`, `is_primary`, `is_verified`, `created_at`
                   FROM `bank_accounts` WHERE `user_id` = :id AND `deleted_at` IS NULL',
                $p,
                label: 'payout_methods'
            ),
            'coupons_used'        => self::rows(
                'SELECT b.`code` AS `booking_code`, cr.`discount_amount`, cr.`created_at`
                   FROM `coupon_redemptions` cr LEFT JOIN `bookings` b ON b.`id` = cr.`booking_id`
                  WHERE cr.`user_id` = :id',
                $p,
                label: 'coupons_used'
            ),
        ]);

        $files['reviews.json'] = self::section('reviews.json', static fn (): array => [
            // The user's own reviews, including any our moderators hid, so
            // they can see that it happened.
            'written'  => self::rows(
                'SELECT b.`code` AS `booking_code`, u.`name` AS `about`, r.`direction`, r.`rating`, r.`quality_rating`,
                        r.`communication_rating`, r.`timeliness_rating`, r.`title`, r.`comment`, r.`media_urls`,
                        r.`reply`, r.`is_published`, r.`created_at`
                   FROM `reviews` r
                   LEFT JOIN `bookings` b ON b.`id` = r.`booking_id`
                   LEFT JOIN `users` u ON u.`id` = r.`reviewee_id`
                  WHERE r.`reviewer_id` = :id AND r.`deleted_at` IS NULL ORDER BY r.`id`',
                $p,
                label: 'written'
            ),
            // Reviews of the user as published. One our moderators hid is the
            // reviewer's words and a moderation decision, not the user's data.
            'received' => self::rows(
                'SELECT b.`code` AS `booking_code`, u.`name` AS `from`, r.`direction`, r.`rating`, r.`quality_rating`,
                        r.`communication_rating`, r.`timeliness_rating`, r.`title`, r.`comment`, r.`reply`,
                        r.`replied_at`, r.`created_at`
                   FROM `reviews` r
                   LEFT JOIN `bookings` b ON b.`id` = r.`booking_id`
                   LEFT JOIN `users` u ON u.`id` = r.`reviewer_id`
                  WHERE r.`reviewee_id` = :id AND r.`is_published` = 1 AND r.`deleted_at` IS NULL ORDER BY r.`id`',
                $p,
                label: 'received'
            ),
        ]);

        $files['verification.json'] = self::section('verification.json', static fn (): array => self::verificationSummary($id));

        $files['activity.json'] = self::section('activity.json', static fn (): array => [
            'saved_creators'  => self::rows(
                'SELECT c.`handle`, c.`display_name`, f.`created_at`
                   FROM `favorites` f JOIN `creators` c ON c.`id` = f.`creator_id` WHERE f.`user_id` = :id',
                $p,
                label: 'saved_creators'
            ),
            'following'       => self::rows(
                'SELECT c.`handle`, c.`display_name`, fl.`created_at`
                   FROM `follows` fl JOIN `creators` c ON c.`id` = fl.`creator_id` WHERE fl.`follower_id` = :id',
                $p,
                label: 'following'
            ),
            'search_history'  => self::rows(
                'SELECT `term`, `filters`, `result_count`, `created_at` FROM `search_histories` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p,
                label: 'search_history'
            ),
            'notifications'   => self::rows(
                'SELECT `type`, `category`, `title`, `body`, `is_read`, `read_at`, `created_at`
                   FROM `notifications` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p,
                label: 'notifications'
            ),
            'support_tickets' => self::rows(
                'SELECT `code`, `subject`, `category`, `priority`, `status`, `created_at`, `resolved_at`, `closed_at`,
                        `satisfaction_rating`, `satisfaction_comment`
                   FROM `support_tickets` WHERE `user_id` = :id ORDER BY `id` DESC',
                $p,
                label: 'support_tickets'
            ),
            // Internal agent notes are the support team's working papers about
            // a ticket, not something the user wrote or was sent.
            'support_messages' => self::rows(
                'SELECT t.`code` AS `ticket`, m.`sender_type`, m.`message`, m.`attachments`, m.`created_at`
                   FROM `support_ticket_messages` m JOIN `support_tickets` t ON t.`id` = m.`ticket_id`
                  WHERE t.`user_id` = :id AND m.`is_internal` = 0 ORDER BY m.`id`',
                $p,
                label: 'support_messages'
            ),
            'reports_filed'   => self::rows(
                'SELECT `reportable_type`, `reason`, `description`, `status`, `created_at` FROM `reports` WHERE `reporter_id` = :id ORDER BY `id`',
                $p,
                label: 'reports_filed'
            ),
            'referrals_made'  => self::rows(
                'SELECT `code`, `referrer_reward` AS `your_reward`, `status`, `qualified_at`, `rewarded_at`, `created_at`
                   FROM `referrals` WHERE `referrer_user_id` = :id ORDER BY `id`',
                $p,
                label: 'referrals_made'
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
            // Listed with their links. Identity documents are not here: they
            // belong to verification.json, which never includes the files.
            'uploads'         => self::rows(
                "SELECT `folder`, `original_name`, `url`, `mime_type`, `size_bytes`, `created_at`
                   FROM `media`
                  WHERE `uploader_type` = 'user' AND `uploader_id` = :id AND `deleted_at` IS NULL AND `folder` <> 'kyc'
                  ORDER BY `id` DESC",
                $p,
                label: 'uploads'
            ),
            // The subject the user saw. The campaign's name is an internal
            // label for the audience it was sent to.
            'marketing_messages' => self::rows(
                'SELECT mc.`channel`, mc.`subject`, cr.`destination` AS `sent_to`, cr.`status`, cr.`sent_at`,
                        cr.`opened_at`, cr.`clicked_at`
                   FROM `campaign_recipients` cr JOIN `marketing_campaigns` mc ON mc.`id` = cr.`campaign_id`
                  WHERE cr.`user_id` = :id ORDER BY cr.`id` DESC',
                $p,
                label: 'marketing_messages'
            ),
            'profiles_viewed' => self::rows(
                'SELECT c.`handle`, v.`viewed_on`
                   FROM `creator_profile_views` v JOIN `creators` c ON c.`id` = v.`creator_id`
                  WHERE v.`viewer_id` = :id ORDER BY v.`id` DESC',
                $p,
                label: 'profiles_viewed'
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
                ['id' => $userId],
                label: 'documents'
            ),
            'note'      => 'Document numbers and images are not included in this export.',
        ];
    }

    /**
     * Run one section, turning a failure into an honest note in that file.
     *
     * A file whose content is a set of named sections also gets a
     * `truncated` map: each section cut at its row cap, with how many rows
     * are included and how many there are. A file that is a plain list (the
     * KYC module's summary) is left exactly as the module shaped it.
     *
     * @param  callable():array<string,mixed> $build
     * @return array<string,mixed>
     */
    private static function section(string $file, callable $build): array
    {
        self::$currentFile = $file;

        try {
            $data = $build();
        } catch (\Throwable $e) {
            Logger::error('Data export section failed', ['file' => $file, 'error' => $e->getMessage()]);

            $data = ['error' => 'This part of your data could not be exported. Please contact support.'];
        } finally {
            self::$currentFile = '';
        }

        if (!array_is_list($data)) {
            $data['truncated'] = (object) (self::$truncated[$file] ?? []);
        }

        return $data;
    }

    /**
     * Up to $limit rows. One more is fetched to tell a section that fits
     * from one that was cut; a cut section is recorded under $label, with
     * its full count, for the file's `truncated` map and the README.
     *
     * @param  array<string,mixed> $params
     * @param  list<string>        $omit
     * @return list<array<string,mixed>>
     */
    private static function rows(string $sql, array $params, array $omit = [], int $limit = self::MAX_ROWS, ?string $label = null): array
    {
        $limit = max(1, $limit);
        $rows  = Database::select($sql . ' LIMIT ' . ($limit + 1), $params);

        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);

            if ($label !== null) {
                try {
                    $total = (int) Database::scalar('SELECT COUNT(*) FROM (' . $sql . ') AS `counted`', $params);
                } catch (\Throwable) {
                    $total = null;
                }

                self::$truncated[self::$currentFile !== '' ? self::$currentFile : 'other'][$label] = [
                    'included' => $limit,
                    'total'    => $total,
                ];
            }
        }

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
            'security.json'        => 'Devices and sessions signed in to your account, your sign-in history and your recent account activity, including changes to your email or phone and your earlier data exports',
            'creator_profile.json' => 'Your creator profile: packages, portfolio, social accounts, credentials, experience, FAQs and availability',
            'campaigns.json'       => 'Campaigns you created (brands) or applied to (creators)',
            'bookings.json'        => 'Your bookings with their deliverables and status history, the offers you made, and any disputes',
            'messages.json'        => 'Your conversations and the messages you sent',
            'payments.json'        => 'Payments, refunds, invoices, wallet transactions, withdrawals and payout methods (bank account numbers masked)',
            'reviews.json'         => 'Reviews you wrote and reviews you received',
            'verification.json'    => 'Your identity verification (KYC): reference, status, dates, the documents you submitted and masked document numbers',
            'activity.json'        => 'Saved creators, follows, searches, notifications, support tickets, reports, referrals, files you uploaded, marketing messages we sent you and the creator profiles you viewed',
        ];

        $lines = [];

        foreach ($files as $file) {
            if (isset($descriptions[$file])) {
                $lines[] = str_pad($file, 22) . wordwrap($descriptions[$file], 54, "\n" . str_repeat(' ', 22));
            }
        }

        $support = Config::settingString('general', 'support_email');
        $since   = !empty($user['created_at']) ? date('j M Y', (int) strtotime((string) $user['created_at'])) : 'unknown';

        $shortened = [];

        foreach (self::$truncated as $file => $sections) {
            foreach ($sections as $label => $counts) {
                $shortened[] = '- ' . $file . ', ' . $label . ': ' . number_format($counts['included']) . ' of '
                    . ($counts['total'] === null ? 'more' : number_format($counts['total'])) . ' rows included';
            }
        }

        $shortenedBlock = $shortened === [] ? [] : [
            'Shortened sections',
            '------------------',
            'These sections were too long to include in full (each file\'s "truncated"',
            'entry lists them too).',
            $support !== ''
                ? 'Write to ' . $support . ' and we will send you the rest.'
                : 'Contact Zusty support from the app and we will send you the rest.',
            ...$shortened,
            '',
        ];

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
            ...$shortenedBlock,
            'What is not included, and why',
            '-----------------------------',
            '- Your password, security codes and sign-in tokens. Zusty never stores your',
            '  password itself, only a one-way fingerprint of it.',
            '- Full bank account numbers. Only the last four digits are shown.',
            '- Identity document images and full document numbers. verification.json',
            '  shows only the last four characters of your PAN, GSTIN, CIN or Aadhaar.',
            '- Files you uploaded (photos, videos, documents) are listed with their links',
            '  rather than copied in. The links keep working while your account is open.',
            '- Messages other people sent you. Those are their personal data. Your own',
            '  messages are included, with who you were talking to.',
            '- What the other person submitted in a dispute on your booking, and reviews',
            '  of you that our moderators hid. Every dispute is still listed with its',
            '  outcome.',
            '- Our team\'s internal notes: support agents\' notes, notes on dispute',
            '  decisions and refund approvals, and moderation notes.',
            '- Technical request logs we keep for a short time for security.',
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

    /**
     * The archive's absolute path, or null if it is gone, cannot be read, or
     * escapes the root. Unreadable counts as gone, so a download is refused
     * before a use is counted rather than after.
     */
    private static function absolutePath(string $relative): ?string
    {
        $root   = realpath(self::root());
        $target = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . str_replace(['\\', '..'], ['/', ''], $relative));

        if ($root === false || $target === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)
            || !is_file($target) || !is_readable($target)) {
            return null;
        }

        return $target;
    }

    /**
     * Delete one archive.
     *
     * @return bool true when the file no longer exists (deleted now, already
     *              gone, or a path that never pointed inside the exports
     *              folder); false when it is still there.
     */
    private static function deleteFile(string $relative): bool
    {
        $root = realpath(self::root());

        if ($root === false) {
            return true;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . str_replace(['\\', '..'], ['/', ''], $relative);
        $target    = realpath($candidate);

        if ($target === false) {
            return !file_exists($candidate);
        }

        if (!str_starts_with($target, $root . DIRECTORY_SEPARATOR) || !is_file($target)) {
            return true;
        }

        @unlink($target);
        clearstatcache(true, $target);

        return !is_file($target);
    }
}

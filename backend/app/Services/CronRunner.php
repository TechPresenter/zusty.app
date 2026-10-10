<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\Jwt;
use Zuste\Core\Logger;
use Zuste\Core\RateLimiter;
use Zuste\Helpers\Money;

/**
 * Scheduled jobs.
 *
 * One registry drives both the CLI runner (`scripts/cron.php`) and the HTTP
 * trigger, so a job behaves identically however it is invoked, and every run
 * is recorded in `scheduled_jobs` for the admin System screen.
 */
final class CronRunner
{
    /** @return list<string> */
    public static function jobs(): array
    {
        return [
            'queue:dispatch',
            'marketing:dispatch',
            'automation:run',
            'bookings:expire',
            'bookings:autocomplete',
            'escrow:release',
            'analytics:rollup',
            'creators:refresh',
            'creators:badges',
            'creators:completion',
            'kyc:expiry',
            'kyc:retention',
            'auth:purge-guests',
            'privacy:purge-exports',
            'privacy:finish-deletions',
            'tokens:prune',
            'backup:database',
            'logs:rotate',
            'integrations:sync',
        ];
    }

    public static function exists(string $job): bool
    {
        return in_array($job, self::jobs(), true);
    }

    /**
     * Run a job, recording status and duration.
     *
     * @return array<string,mixed>
     */
    public static function run(string $job): array
    {
        if (!self::exists($job)) {
            throw new \InvalidArgumentException('Unknown job: ' . $job);
        }

        $enabled = Database::scalar(
            'SELECT `is_enabled` FROM `scheduled_jobs` WHERE `job_key` = :key',
            ['key' => $job]
        );

        if ($enabled !== null && (int) $enabled === 0) {
            return ['skipped' => true, 'reason' => 'Job is disabled in the admin panel.'];
        }

        self::markRunning($job);
        $started = microtime(true);

        try {
            $result = match ($job) {
                'queue:dispatch'        => self::dispatchQueues(),
                'marketing:dispatch'    => CampaignService::runDue(),
                'automation:run'        => AutomationService::runDue(),
                'bookings:expire'       => ['expired' => BookingService::expireStale()],
                'bookings:autocomplete' => ['completed' => BookingService::autoCompleteDelivered()],
                'escrow:release'        => ['released' => WalletService::releaseMaturedHolds()],
                'analytics:rollup'      => self::rollupAnalytics(),
                'creators:refresh'      => ['refreshed' => CreatorService::refreshStatistics()],
                // Retire badges whose validity has run out, then re-evaluate
                // the automatic ones. Expiry runs first so a badge that lapsed
                // and is still earned is re-awarded in the same pass rather
                // than spending a day withdrawn.
                'creators:badges'       => ['expired' => CreatorBadgeService::expireDue()]
                    + CreatorBadgeService::syncAllAutomatic(),
                // Scheduled by migration 037 at 00:55; the admin Completion
                // page promises the scores are refreshed by this run.
                'creators:completion'   => ProfileCompletionService::recomputeAll(),
                'kyc:expiry'            => KycService::runExpirySweep(),
                // Identity documents are deleted once they have no reason to
                // be kept: rejected / abandoned / closed profiles, replaced
                // versions, orphaned blobs. Approved, active ones stay.
                'kyc:retention'         => KycService::runRetentionSweep(),
                'auth:purge-guests'     => AuthService::purgeGuests(),
                'privacy:purge-exports' => DataExportService::purgeExpired(),
                // An erasure commits in one transaction; deleting the files,
                // data exports, KYC documents and the Firebase sign-in record
                // comes after it and can fail. This retries what is left,
                // with a growing pause, until every step has succeeded.
                'privacy:finish-deletions' => AccountDeletionService::finishPending(),
                'tokens:prune'          => self::pruneTokens(),
                'backup:database'       => BackupService::createDatabaseBackup('cron'),
                'logs:rotate'           => self::rotateLogs(),
                'integrations:sync'     => self::syncIntegrations(),
                default                 => [],
            };

            self::markFinished($job, true, (int) round((microtime(true) - $started) * 1000), $result);

            return $result;
        } catch (\Throwable $e) {
            self::markFinished($job, false, (int) round((microtime(true) - $started) * 1000), ['error' => $e->getMessage()]);

            throw $e;
        }
    }

    // -----------------------------------------------------------------
    // Jobs
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function dispatchQueues(): array
    {
        return [
            'email' => MailService::drainQueue(50),
            'sms'   => SmsService::drainQueue(50),
            'push'  => NotificationService::drainPushQueue(100),
        ];
    }

    /** @return array<string,mixed> */
    private static function pruneTokens(): array
    {
        $refreshTokens = Database::execute(
            'DELETE FROM `refresh_tokens`
             WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 30 DAY)
                OR (`revoked_at` IS NOT NULL AND `revoked_at` < DATE_SUB(NOW(), INTERVAL 30 DAY))'
        );

        $otps = Database::execute(
            'DELETE FROM `otp_verifications` WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );

        $resets = Database::execute(
            'DELETE FROM `password_resets` WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );

        $sessions = Database::execute(
            'DELETE FROM `admin_sessions` WHERE `last_activity` < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );

        // Gateway callbacks carry the payer's details. Ninety days covers any
        // reconciliation or dispute over a callback, whatever its outcome;
        // the payment rows themselves are the lasting record.
        $webhooks = Database::execute(
            'DELETE FROM `payment_webhooks` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY)'
        );

        return [
            'refresh_tokens' => $refreshTokens,
            'otps'           => $otps,
            'password_resets' => $resets,
            'admin_sessions' => $sessions,
            'blacklist'      => Jwt::pruneBlacklist(),
            'rate_limits'    => RateLimiter::prune(),
            'email_queue'    => self::pruneQueue('email_queue'),
            'sms_queue'      => self::pruneQueue('sms_queue'),
            'push_queue'     => self::pruneQueue('push_queue'),
            'webhooks'       => $webhooks,
            // Run here as well as after each backup, so the retention window
            // the privacy policy states holds even when backups stop.
            'backups'        => BackupService::pruneExpired(),
        ];
    }

    /**
     * Our copies of the messages sent to people: the address, the name and
     * the whole message body. A delivered copy has done its job after a
     * week. A failed or never-sent one is kept for a month, so an operator
     * can still see why it did not go.
     *
     * A row left in 'sending' (a worker that died mid-send; nothing retries
     * it) counts as failed: after the same month it goes too, so its address
     * and body never outlive the retention the privacy policy states. A
     * 'sent' row with no sent_at falls back to when it was queued.
     */
    private static function pruneQueue(string $table): int
    {
        return Database::execute(
            "DELETE FROM `{$table}`
              WHERE (`status` = 'sent' AND COALESCE(`sent_at`, `created_at`) < DATE_SUB(NOW(), INTERVAL 7 DAY))
                 OR (`status` IN ('failed', 'pending', 'sending') AND `created_at` < DATE_SUB(NOW(), INTERVAL 30 DAY))"
        );
    }

    /**
     * Rebuild yesterday's and today's analytics rows plus the settlement
     * summary. Re-running is safe — every write is an upsert.
     *
     * @return array<string,mixed>
     */
    private static function rollupAnalytics(): array
    {
        $dates = [date('Y-m-d', strtotime('-1 day')), date('Y-m-d')];

        foreach ($dates as $date) {
            Database::execute(
                'INSERT INTO `analytics_daily`
                    (`stat_date`, `users_new`, `brands_new`, `creators_new`, `users_active`,
                     `bookings_created`, `bookings_accepted`, `bookings_completed`, `bookings_cancelled`,
                     `gmv`, `revenue`, `refunds`, `payouts`, `wallet_topups`,
                     `messages_sent`, `reviews_posted`, `searches`, `profile_views`, `tickets_opened`)
                 VALUES (
                    :date,
                    -- Guest rows are not registrations, so they must not show up
                    -- as new users in the daily analytics the dashboard reads.
                    (SELECT COUNT(*) FROM `users` WHERE DATE(`created_at`) = :date AND `account_type` <> "guest"),
                    (SELECT COUNT(*) FROM `users` WHERE DATE(`created_at`) = :date AND `account_type` = "brand"),
                    (SELECT COUNT(*) FROM `users` WHERE DATE(`created_at`) = :date AND `account_type` = "creator"),
                    (SELECT COUNT(DISTINCT `id`) FROM `users` WHERE DATE(`last_seen_at`) = :date),
                    (SELECT COUNT(*) FROM `bookings` WHERE DATE(`created_at`) = :date),
                    (SELECT COUNT(*) FROM `bookings` WHERE DATE(`accepted_at`) = :date),
                    (SELECT COUNT(*) FROM `bookings` WHERE DATE(`completed_at`) = :date),
                    (SELECT COUNT(*) FROM `bookings` WHERE DATE(`cancelled_at`) = :date),
                    (SELECT COALESCE(SUM(`total_amount`), 0) FROM `bookings` WHERE DATE(`paid_at`) = :date),
                    (SELECT ' . EarningsService::net('total') . ' FROM `platform_earnings` WHERE `earned_on` = :date AND ' . EarningsService::LIVE . '),
                    (SELECT COALESCE(SUM(`amount`), 0) FROM `refunds` WHERE DATE(`processed_at`) = :date AND `status` = "completed"),
                    (SELECT COALESCE(SUM(`net_amount`), 0) FROM `withdrawal_requests` WHERE DATE(`processed_at`) = :date AND `status` = "completed"),
                    (SELECT COALESCE(SUM(`amount`), 0) FROM `payments` WHERE DATE(`paid_at`) = :date AND `purpose` = "wallet_topup" AND `status` = "captured"),
                    (SELECT COUNT(*) FROM `messages` WHERE DATE(`created_at`) = :date),
                    (SELECT COUNT(*) FROM `reviews` WHERE DATE(`created_at`) = :date),
                    (SELECT COUNT(*) FROM `search_histories` WHERE DATE(`created_at`) = :date),
                    (SELECT COUNT(*) FROM `creator_profile_views` WHERE `viewed_on` = :date),
                    (SELECT COUNT(*) FROM `support_tickets` WHERE DATE(`created_at`) = :date)
                 )
                 ON DUPLICATE KEY UPDATE
                    `users_new` = VALUES(`users_new`), `brands_new` = VALUES(`brands_new`),
                    `creators_new` = VALUES(`creators_new`), `users_active` = VALUES(`users_active`),
                    `bookings_created` = VALUES(`bookings_created`), `bookings_accepted` = VALUES(`bookings_accepted`),
                    `bookings_completed` = VALUES(`bookings_completed`), `bookings_cancelled` = VALUES(`bookings_cancelled`),
                    `gmv` = VALUES(`gmv`), `revenue` = VALUES(`revenue`), `refunds` = VALUES(`refunds`),
                    `payouts` = VALUES(`payouts`), `wallet_topups` = VALUES(`wallet_topups`),
                    `messages_sent` = VALUES(`messages_sent`), `reviews_posted` = VALUES(`reviews_posted`),
                    `searches` = VALUES(`searches`), `profile_views` = VALUES(`profile_views`),
                    `tickets_opened` = VALUES(`tickets_opened`)',
                ['date' => $date]
            );

            // Per-creator analytics for the creator dashboard.
            Database::execute(
                'INSERT INTO `creator_analytics_daily`
                    (`creator_id`, `stat_date`, `profile_views`, `booking_requests`, `bookings_accepted`,
                     `bookings_completed`, `earnings`, `new_followers`, `avg_rating`)
                 SELECT c.`id`, :date,
                    (SELECT COUNT(*) FROM `creator_profile_views` v WHERE v.`creator_id` = c.`id` AND v.`viewed_on` = :date),
                    (SELECT COUNT(*) FROM `bookings` b WHERE b.`creator_id` = c.`id` AND DATE(b.`created_at`) = :date),
                    (SELECT COUNT(*) FROM `bookings` b WHERE b.`creator_id` = c.`id` AND DATE(b.`accepted_at`) = :date),
                    (SELECT COUNT(*) FROM `bookings` b WHERE b.`creator_id` = c.`id` AND DATE(b.`completed_at`) = :date),
                    (SELECT COALESCE(SUM(b.`creator_earning`), 0) FROM `bookings` b WHERE b.`creator_id` = c.`id` AND DATE(b.`completed_at`) = :date),
                    (SELECT COUNT(*) FROM `follows` f WHERE f.`creator_id` = c.`id` AND DATE(f.`created_at`) = :date),
                    c.`rating_avg`
                 FROM `creators` c
                 WHERE c.`status` = "approved" AND c.`deleted_at` IS NULL
                 ON DUPLICATE KEY UPDATE
                    `profile_views` = VALUES(`profile_views`),
                    `booking_requests` = VALUES(`booking_requests`),
                    `bookings_accepted` = VALUES(`bookings_accepted`),
                    `bookings_completed` = VALUES(`bookings_completed`),
                    `earnings` = VALUES(`earnings`),
                    `new_followers` = VALUES(`new_followers`),
                    `avg_rating` = VALUES(`avg_rating`)',
                ['date' => $date]
            );

            // Daily settlement summary for finance.
            Database::execute(
                'INSERT INTO `settlements`
                    (`settlement_date`, `gross_volume`, `platform_fees`, `commissions`, `taxes`,
                     `refunds_total`, `payouts_total`, `net_revenue`, `bookings_count`, `payments_count`, `currency`)
                 VALUES (
                    :date,
                    (SELECT COALESCE(SUM(`total_amount`), 0) FROM `bookings` WHERE DATE(`paid_at`) = :date),
                    (SELECT ' . EarningsService::net('platform_fee') . ' FROM `platform_earnings` WHERE `earned_on` = :date AND ' . EarningsService::LIVE . '),
                    (SELECT ' . EarningsService::net('commission') . ' FROM `platform_earnings` WHERE `earned_on` = :date AND ' . EarningsService::LIVE . '),
                    (SELECT ' . EarningsService::net('tax_collected') . ' FROM `platform_earnings` WHERE `earned_on` = :date AND ' . EarningsService::LIVE . '),
                    (SELECT COALESCE(SUM(`amount`), 0) FROM `refunds` WHERE DATE(`processed_at`) = :date AND `status` = "completed"),
                    (SELECT COALESCE(SUM(`net_amount`), 0) FROM `withdrawal_requests` WHERE DATE(`processed_at`) = :date AND `status` = "completed"),
                    (SELECT ' . EarningsService::net('total') . ' FROM `platform_earnings` WHERE `earned_on` = :date AND ' . EarningsService::LIVE . '),
                    (SELECT COUNT(*) FROM `bookings` WHERE DATE(`paid_at`) = :date),
                    (SELECT COUNT(*) FROM `payments` WHERE DATE(`paid_at`) = :date AND `status` = "captured"),
                    :currency
                 )
                 ON DUPLICATE KEY UPDATE
                    `gross_volume` = VALUES(`gross_volume`), `platform_fees` = VALUES(`platform_fees`),
                    `commissions` = VALUES(`commissions`), `taxes` = VALUES(`taxes`),
                    `refunds_total` = VALUES(`refunds_total`), `payouts_total` = VALUES(`payouts_total`),
                    `net_revenue` = VALUES(`net_revenue`), `bookings_count` = VALUES(`bookings_count`),
                    `payments_count` = VALUES(`payments_count`)',
                ['date' => $date, 'currency' => Money::defaultCurrency()]
            );
        }

        return ['dates' => $dates];
    }

    /** @return array<string,mixed> */
    /**
     * Run every integration whose auto-sync is due.
     *
     * One failing integration must not stop the rest, so each is wrapped
     * individually and the outcome counted either way.
     *
     * @return array<string,mixed>
     */
    private static function syncIntegrations(): array
    {
        $due      = \Zuste\Services\IntegrationService::due();
        $synced   = 0;
        $failed   = 0;
        $problems = [];

        foreach ($due as $key) {
            try {
                $result = \Zuste\Services\IntegrationService::sync($key, 0);

                if ($result['ok']) {
                    $synced++;
                } else {
                    $failed++;
                    $problems[] = $key;
                }
            } catch (\Throwable $e) {
                $failed++;
                $problems[] = $key;

                \Zuste\Core\Logger::error('Integration sync threw', [
                    'integration' => $key,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return [
            'due'      => count($due),
            'synced'   => $synced,
            'failed'   => $failed,
            'problems' => $problems,
        ];
    }

    /** @return array<string,int> */
    private static function rotateLogs(): array
    {
        $deletedRows = Database::execute(
            "DELETE FROM `system_logs`
             WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 60 DAY)
               AND `level` IN ('debug','info','notice')"
        );

        $activityRows = Database::execute(
            'DELETE FROM `activity_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 365 DAY)'
        );

        // Sign-in history is kept for a year to detect fraud and abuse, which
        // is the period the privacy policy states for security logs.
        $loginRows = Database::execute(
            'DELETE FROM `login_history` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 365 DAY)'
        );

        // API request logs: 180 days, the period CERT-In asks for.
        $apiRows = ApiLogService::prune(180);

        $directory = (string) \Zuste\Core\Config::get('log.path', '');
        $files     = 0;

        if ($directory !== '' && is_dir($directory)) {
            foreach (glob($directory . '/app-*.log') ?: [] as $file) {
                if (filemtime($file) < time() - (30 * 86400)) {
                    @unlink($file);
                    $files++;
                }
            }
        }

        return [
            'system_logs'   => $deletedRows,
            'activity_logs' => $activityRows,
            'login_history' => $loginRows,
            'api_logs'      => $apiRows,
            'files_removed' => $files,
        ];
    }

    // -----------------------------------------------------------------
    // Bookkeeping
    // -----------------------------------------------------------------

    private static function markRunning(string $job): void
    {
        Database::execute(
            "UPDATE `scheduled_jobs` SET `last_status` = 'running', `last_run_at` = NOW() WHERE `job_key` = :key",
            ['key' => $job]
        );
    }

    private static function markFinished(string $job, bool $success, int $durationMs, array $result): void
    {
        Database::execute(
            'UPDATE `scheduled_jobs`
             SET `last_status` = :status,
                 `last_duration_ms` = :duration,
                 `last_output` = :output,
                 `run_count` = `run_count` + 1,
                 `failure_count` = `failure_count` + :failed
             WHERE `job_key` = :key',
            [
                'status'   => $success ? 'success' : 'failed',
                'duration' => $durationMs,
                'output'   => mb_substr((string) json_encode($result, JSON_UNESCAPED_UNICODE), 0, 2000),
                'failed'   => $success ? 0 : 1,
                'key'      => $job,
            ]
        );

        Logger::info('Cron job finished', [
            'job'         => $job,
            'success'     => $success,
            'duration_ms' => $durationMs,
        ]);
    }
}

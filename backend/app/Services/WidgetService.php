<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Admin\Widget;
use Zuste\Core\Cache;
use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * Resolves widget data.
 *
 * Every method here is named by a widget's `resolver` key. Resolution is
 * dispatched through a whitelist — the registry — so a crafted widget key
 * can never reach an arbitrary method.
 *
 * Counters are cached briefly: a dashboard with 16 widgets would otherwise
 * fire 16 aggregate queries on every auto-refresh tick.
 */
final class WidgetService
{
    private const CACHE_GROUP = 'widgets';

    /**
     * Resolve one widget for an admin.
     *
     * @param  array<string,mixed> $admin
     * @param  array<string,mixed> $options Per-admin widget options.
     * @return array<string,mixed>
     */
    public static function resolve(string $key, array $admin, array $options = []): array
    {
        $widget = Widget::find($key);

        if ($widget === null) {
            throw HttpException::notFound('Unknown widget: ' . $key);
        }

        $permission = (string) ($widget['permission'] ?? '');

        if ($permission !== '' && !PermissionService::adminCan((int) $admin['id'], $permission)) {
            throw HttpException::forbidden('You do not have access to this widget.');
        }

        $resolver = (string) $widget['resolver'];

        if (!method_exists(self::class, $resolver)) {
            throw HttpException::server('Widget resolver is missing: ' . $resolver);
        }

        $options = array_merge((array) ($widget['options'] ?? []), $options);

        // Widgets that read per-admin data must never share a cache entry.
        $isPersonal = in_array($resolver, ['todoList', 'stickyNotes', 'myRecentLogins', 'quickActions', 'announcements'], true);
        $cacheKey   = 'widget:' . $key . ':' . ($isPersonal ? (int) $admin['id'] : 'all') . ':' . md5((string) json_encode($options));

        $ttl = $isPersonal ? 0 : 30;

        $data = $ttl > 0
            ? Cache::remember($cacheKey, $ttl, static fn (): array => (array) self::{$resolver}($options, $admin), self::CACHE_GROUP)
            : (array) self::{$resolver}($options, $admin);

        return [
            'key'         => $key,
            'title'       => $widget['title'],
            'type'        => $widget['type'],
            'icon'        => $widget['icon'],
            'tone'        => $widget['tone'],
            'link'        => $widget['link'],
            'format'      => $widget['format'],
            'size'        => $widget['size'],
            'refresh'     => $widget['refresh'],
            'chart'       => $widget['chart'] ?? null,
            'data'        => $data,
            'generated_at' => date('c'),
        ];
    }

    // =================================================================
    // Statistics — counters
    // =================================================================

    private static function countUsers(): array
    {
        return self::statWithDelta(
            (int) Database::scalar('SELECT COUNT(*) FROM `users` WHERE `deleted_at` IS NULL AND `account_type` <> "guest"'),
            (int) Database::scalar('SELECT COUNT(*) FROM `users` WHERE `deleted_at` IS NULL AND `account_type` <> "guest"
             AND `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
            'new this month'
        );
    }

    private static function countCreators(): array
    {
        return self::statWithDelta(
            (int) Database::scalar('SELECT COUNT(*) FROM `creators` WHERE `deleted_at` IS NULL'),
            (int) Database::scalar('SELECT COUNT(*) FROM `creators` WHERE `deleted_at` IS NULL AND `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
            'new this month'
        );
    }

    private static function countActiveUsers(): array
    {
        return self::stat((int) Database::scalar("SELECT COUNT(*) FROM `users` WHERE `status` = 'active' AND `deleted_at` IS NULL AND `account_type` <> 'guest'"));
    }

    private static function countApprovedCreators(): array
    {
        return self::stat((int) Database::scalar("SELECT COUNT(*) FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL"));
    }

    private static function countVerifiedCreators(): array
    {
        return self::stat((int) Database::scalar("SELECT COUNT(*) FROM `creators` WHERE `is_verified` = 1 AND `deleted_at` IS NULL"));
    }

    private static function countPendingCreators(): array
    {
        $count = (int) Database::scalar("SELECT COUNT(*) FROM `creators` WHERE `status` = 'pending_review' AND `deleted_at` IS NULL");

        return self::stat($count, $count > 0 ? 'warning' : 'ok', $count > 0 ? 'Needs review' : 'All clear');
    }

    private static function countPendingKyc(): array
    {
        $count = (int) Database::scalar("SELECT COUNT(*) FROM `kyc_documents` WHERE `status` IN ('pending','under_review')");

        return self::stat($count, $count > 0 ? 'warning' : 'ok', $count > 0 ? 'Awaiting review' : 'All clear');
    }

    private static function countUsersToday(): array
    {
        $today     = (int) Database::scalar('SELECT COUNT(*) FROM `users` WHERE DATE(`created_at`) = CURDATE() AND `deleted_at` IS NULL AND `account_type` <> "guest"');
        $yesterday = (int) Database::scalar('SELECT COUNT(*) FROM `users` WHERE DATE(`created_at`) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND `deleted_at` IS NULL AND `account_type` <> "guest"');

        return self::statWithChange($today, $yesterday, 'vs yesterday');
    }

    private static function countCampaigns(): array
    {
        return self::stat((int) Database::scalar('SELECT COUNT(*) FROM `campaigns` WHERE `deleted_at` IS NULL'));
    }

    private static function countActiveCampaigns(): array
    {
        return self::stat((int) Database::scalar(
            "SELECT COUNT(*) FROM `bookings` WHERE `status` IN ('confirmed','in_progress') AND `deleted_at` IS NULL"
        ));
    }

    private static function countCompletedBookings(): array
    {
        return self::stat((int) Database::scalar("SELECT COUNT(*) FROM `bookings` WHERE `status` = 'completed' AND `deleted_at` IS NULL"));
    }

    private static function countCancelledBookings(): array
    {
        return self::stat((int) Database::scalar(
            "SELECT COUNT(*) FROM `bookings` WHERE `status` IN ('cancelled','rejected','expired') AND `deleted_at` IS NULL"
        ));
    }

    private static function countBookings(): array
    {
        return self::statWithDelta(
            (int) Database::scalar('SELECT COUNT(*) FROM `bookings` WHERE `deleted_at` IS NULL'),
            (int) Database::scalar('SELECT COUNT(*) FROM `bookings` WHERE `deleted_at` IS NULL AND `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
            'this month'
        );
    }

    private static function countBookingsToday(): array
    {
        $today     = (int) Database::scalar('SELECT COUNT(*) FROM `bookings` WHERE DATE(`created_at`) = CURDATE() AND `deleted_at` IS NULL');
        $yesterday = (int) Database::scalar('SELECT COUNT(*) FROM `bookings` WHERE DATE(`created_at`) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND `deleted_at` IS NULL');

        return self::statWithChange($today, $yesterday, 'vs yesterday');
    }

    private static function countBookingsWeek(): array
    {
        return self::stat((int) Database::scalar(
            'SELECT COUNT(*) FROM `bookings` WHERE `created_at` >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND `deleted_at` IS NULL'
        ));
    }

    private static function countBookingsMonth(): array
    {
        $current  = (int) Database::scalar("SELECT COUNT(*) FROM `bookings` WHERE `created_at` >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND `deleted_at` IS NULL");
        $previous = (int) Database::scalar(
            "SELECT COUNT(*) FROM `bookings`
             WHERE `created_at` >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
               AND `created_at` <  DATE_FORMAT(CURDATE(), '%Y-%m-01') AND `deleted_at` IS NULL"
        );

        return self::statWithChange($current, $previous, 'vs last month');
    }

    private static function countActiveBookings(): array
    {
        return self::stat((int) Database::scalar(
            "SELECT COUNT(*) FROM `bookings` WHERE `status` IN ('confirmed','in_progress','delivered') AND `deleted_at` IS NULL"
        ));
    }

    private static function countOpenDisputes(): array
    {
        $count = (int) Database::scalar("SELECT COUNT(*) FROM `booking_disputes` WHERE `status` IN ('open','under_review')");

        return self::stat($count, $count > 0 ? 'critical' : 'ok', $count > 0 ? 'Needs arbitration' : 'None open');
    }

    private static function countReviews(): array
    {
        return self::stat((int) Database::scalar('SELECT COUNT(*) FROM `reviews` WHERE `deleted_at` IS NULL'));
    }

    private static function averageRating(): array
    {
        $average = (float) Database::scalar(
            'SELECT COALESCE(AVG(`rating`), 0) FROM `reviews` WHERE `is_published` = 1 AND `deleted_at` IS NULL'
        );

        return self::stat(round($average, 2), $average >= 4.0 ? 'ok' : ($average > 0 ? 'warning' : 'neutral'));
    }

    private static function countCategories(): array
    {
        return self::stat((int) Database::scalar('SELECT COUNT(*) FROM `categories` WHERE `deleted_at` IS NULL'));
    }

    private static function countBlogs(): array
    {
        return self::stat((int) Database::scalar('SELECT COUNT(*) FROM `blogs` WHERE `deleted_at` IS NULL'));
    }

    private static function countNotifications(): array
    {
        return self::stat((int) Database::scalar('SELECT COUNT(*) FROM `notifications`'));
    }

    private static function onlineUsers(): array
    {
        return self::stat((int) Database::scalar(
            'SELECT COUNT(*) FROM `users` WHERE `last_seen_at` >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND `deleted_at` IS NULL
               AND `account_type` <> "guest"'
        ));
    }

    // =================================================================
    // Finance
    // =================================================================

    private static function gmv30d(): array
    {
        $current  = self::sumAnalytics('gmv', 30, 0);
        $previous = self::sumAnalytics('gmv', 60, 30);

        return self::statWithChange($current, $previous, 'vs previous 30d');
    }

    private static function revenue30d(): array
    {
        return self::statWithChange(self::sumAnalytics('revenue', 30, 0), self::sumAnalytics('revenue', 60, 30), 'vs previous 30d');
    }

    private static function revenueToday(): array
    {
        $today     = (float) Database::scalar('SELECT ' . EarningsService::net('total') . ' FROM `platform_earnings` WHERE `earned_on` = CURDATE() AND ' . EarningsService::LIVE);
        $yesterday = (float) Database::scalar('SELECT ' . EarningsService::net('total') . ' FROM `platform_earnings` WHERE `earned_on` = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND ' . EarningsService::LIVE);

        return self::statWithChange($today, $yesterday, 'vs yesterday');
    }

    private static function revenueMonth(): array
    {
        return self::stat((float) Database::scalar(
            'SELECT ' . EarningsService::net('total') . " FROM `platform_earnings` WHERE `earned_on` >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND " . EarningsService::LIVE
        ));
    }

    private static function commission30d(): array
    {
        return self::stat((float) Database::scalar(
            'SELECT ' . EarningsService::net('commission') . ' FROM `platform_earnings` WHERE `earned_on` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND ' . EarningsService::LIVE
        ));
    }

    private static function escrowHeld(): array
    {
        return self::stat((float) Database::scalar(
            "SELECT COALESCE(SUM(`total_amount`), 0) FROM `bookings` WHERE `escrow_status` = 'held' AND `deleted_at` IS NULL"
        ));
    }

    private static function walletFloat(): array
    {
        $row = Database::selectOne(
            'SELECT COALESCE(SUM(`balance`), 0) AS `available`, COALESCE(SUM(`locked_balance`), 0) AS `locked` FROM `wallets`'
        ) ?? [];

        return self::stat(
            Money::add((float) ($row['available'] ?? 0), (float) ($row['locked'] ?? 0)),
            'neutral',
            Money::formatShort((float) ($row['locked'] ?? 0)) . ' locked'
        );
    }

    private static function pendingWithdrawals(): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS `count`, COALESCE(SUM(`amount`), 0) AS `total`
             FROM `withdrawal_requests` WHERE `status` = 'pending'"
        ) ?? [];

        $count = (int) ($row['count'] ?? 0);

        return self::stat(
            (float) ($row['total'] ?? 0),
            $count > 0 ? 'warning' : 'ok',
            $count . ' request' . ($count === 1 ? '' : 's')
        );
    }

    private static function completedWithdrawals(): array
    {
        return self::stat((float) Database::scalar(
            "SELECT COALESCE(SUM(`net_amount`), 0) FROM `withdrawal_requests` WHERE `status` = 'completed'"
        ));
    }

    private static function pendingPayments(): array
    {
        return self::stat((int) Database::scalar("SELECT COUNT(*) FROM `payments` WHERE `status` IN ('created','pending','authorized')"));
    }

    private static function failedPayments(): array
    {
        $count = (int) Database::scalar(
            "SELECT COUNT(*) FROM `payments` WHERE `status` = 'failed' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return self::stat($count, $count > 5 ? 'warning' : 'neutral', 'last 7 days');
    }

    private static function refundTotal(): array
    {
        return self::stat((float) Database::scalar(
            "SELECT COALESCE(SUM(`amount`), 0) FROM `refunds` WHERE `status` = 'completed'"
        ));
    }

    private static function taxCollected(): array
    {
        return self::stat((float) Database::scalar(
            'SELECT ' . EarningsService::net('tax_collected') . ' FROM `platform_earnings` WHERE `earned_on` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND ' . EarningsService::LIVE
        ));
    }

    private static function countTransactions(): array
    {
        return self::stat((int) Database::scalar('SELECT COUNT(*) FROM `wallet_transactions`'));
    }

    private static function revenueSummary(): array
    {
        $row = Database::selectOne(
            'SELECT ' . EarningsService::net('platform_fee') . ' AS `fees`,
                    ' . EarningsService::net('commission') . ' AS `commission`,
                    ' . EarningsService::net('tax_collected') . ' AS `tax`,
                    ' . EarningsService::net('total') . ' AS `total`
             FROM `platform_earnings` WHERE `earned_on` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND ' . EarningsService::LIVE
        ) ?? [];

        return ['rows' => [
            ['label' => 'Platform fees', 'value' => Money::formatShort((float) ($row['fees'] ?? 0))],
            ['label' => 'Commission',    'value' => Money::formatShort((float) ($row['commission'] ?? 0))],
            ['label' => 'Tax collected', 'value' => Money::formatShort((float) ($row['tax'] ?? 0))],
            ['label' => 'Net revenue',   'value' => Money::formatShort((float) ($row['total'] ?? 0)), 'emphasis' => true],
        ]];
    }

    private static function payoutOverview(): array
    {
        $rows = Database::select(
            'SELECT `status`, COUNT(*) AS `count`, COALESCE(SUM(`amount`), 0) AS `total`
             FROM `withdrawal_requests` GROUP BY `status`'
        );

        $summary = [];

        foreach ($rows as $row) {
            $summary[] = [
                'label' => ucfirst((string) $row['status']),
                'value' => Money::formatShort((float) $row['total']),
                'meta'  => (int) $row['count'] . ' request' . ((int) $row['count'] === 1 ? '' : 's'),
            ];
        }

        return ['rows' => $summary ?: [['label' => 'No payouts yet', 'value' => '—']]];
    }

    // =================================================================
    // Charts
    // =================================================================

    private static function chartRevenue(array $options): array
    {
        return ['series' => self::analyticsSeries('revenue', (int) ($options['days'] ?? 30)), 'suffix' => ''];
    }

    private static function chartBookings(array $options): array
    {
        return ['series' => self::analyticsSeries('bookings_created', (int) ($options['days'] ?? 14))];
    }

    private static function chartUserGrowth(array $options): array
    {
        return ['series' => self::analyticsSeries('users_new', (int) ($options['days'] ?? 30))];
    }

    private static function chartCreatorGrowth(array $options): array
    {
        return ['series' => self::analyticsSeries('creators_new', (int) ($options['days'] ?? 30))];
    }

    private static function chartActiveUsers(array $options): array
    {
        return ['series' => self::analyticsSeries('users_active', (int) ($options['days'] ?? 30))];
    }

    private static function funnelBookings(): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS `requested`,
                    SUM(CASE WHEN `accepted_at` IS NOT NULL THEN 1 ELSE 0 END) AS `accepted`,
                    SUM(CASE WHEN `paid_at` IS NOT NULL THEN 1 ELSE 0 END) AS `paid`,
                    SUM(CASE WHEN `status` = 'completed' THEN 1 ELSE 0 END) AS `completed`
             FROM `bookings` WHERE `deleted_at` IS NULL AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        ) ?? [];

        $requested = (int) ($row['requested'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);

        return [
            'stages' => [
                ['label' => 'Requested', 'value' => $requested,                    'tone' => '#8F6F73'],
                ['label' => 'Accepted',  'value' => (int) ($row['accepted'] ?? 0), 'tone' => '#632CE5'],
                ['label' => 'Paid',      'value' => (int) ($row['paid'] ?? 0),     'tone' => '#B80049'],
                ['label' => 'Completed', 'value' => $completed,                    'tone' => '#006A34'],
            ],
            'conversion' => $requested > 0 ? round(($completed / $requested) * 100, 1) : 0.0,
        ];
    }

    private static function topCategories(): array
    {
        $rows = Database::select(
            "SELECT cat.`name` AS `label`, cat.`color_hex` AS `tone`, COUNT(DISTINCT b.`id`) AS `value`
             FROM `categories` cat
             LEFT JOIN `creator_categories` cc ON cc.`category_id` = cat.`id`
             LEFT JOIN `bookings` b ON b.`creator_id` = cc.`creator_id` AND b.`deleted_at` IS NULL
             WHERE cat.`is_active` = 1 AND cat.`deleted_at` IS NULL
             GROUP BY cat.`id` ORDER BY `value` DESC LIMIT 6"
        );

        return ['bars' => $rows];
    }

    private static function topCreators(): array
    {
        $rows = Database::select(
            "SELECT c.`id`, c.`display_name` AS `label`, c.`rating_avg`, u.`avatar_url`,
                    COALESCE(SUM(b.`creator_earning`), 0) AS `value`, COUNT(b.`id`) AS `count`
             FROM `creators` c
             INNER JOIN `users` u ON u.`id` = c.`user_id`
             LEFT JOIN `bookings` b ON b.`creator_id` = c.`id` AND b.`status` = 'completed' AND b.`deleted_at` IS NULL
             WHERE c.`deleted_at` IS NULL
             GROUP BY c.`id` ORDER BY `value` DESC LIMIT 6"
        );

        foreach ($rows as &$row) {
            $row['value_label'] = Money::formatShort((float) $row['value']);
            $row['meta']        = (int) $row['count'] . ' collabs · ★ ' . number_format((float) $row['rating_avg'], 1);
            $row['href']        = '/admin/creators/' . (int) $row['id'];
        }
        unset($row);

        return ['items' => $rows];
    }

    private static function topBrands(): array
    {
        $rows = Database::select(
            "SELECT u.`id`, COALESCE(u.`company_name`, u.`name`) AS `label`, u.`avatar_url`,
                    COALESCE(SUM(b.`total_amount`), 0) AS `value`, COUNT(b.`id`) AS `count`
             FROM `users` u
             INNER JOIN `bookings` b ON b.`brand_user_id` = u.`id` AND b.`status` = 'completed' AND b.`deleted_at` IS NULL
             WHERE u.`deleted_at` IS NULL
             GROUP BY u.`id` ORDER BY `value` DESC LIMIT 6"
        );

        foreach ($rows as &$row) {
            $row['value_label'] = Money::formatShort((float) $row['value']);
            $row['meta']        = (int) $row['count'] . ' bookings';
            $row['href']        = '/admin/users/' . (int) $row['id'];
        }
        unset($row);

        return ['items' => $rows];
    }

    private static function statsByDimension(array $options): array
    {
        $dimension = (string) ($options['dimension'] ?? 'device');

        $allowed = ['device', 'browser', 'os', 'country', 'city', 'language', 'platform', 'source'];

        if (!in_array($dimension, $allowed, true)) {
            $dimension = 'device';
        }

        $rows = Database::select(
            'SELECT `value` AS `label`, SUM(`visits`) AS `value`
             FROM `visit_stats`
             WHERE `dimension` = :dimension AND `stat_date` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY `value` ORDER BY `value` DESC LIMIT 6',
            ['dimension' => $dimension]
        );

        // Fall back to registered devices, which every install has.
        if ($rows === [] && $dimension === 'platform') {
            $rows = Database::select(
                'SELECT `platform` AS `label`, COUNT(*) AS `value` FROM `user_devices` GROUP BY `platform` ORDER BY `value` DESC'
            );
        }

        $palette = ['#B80049', '#632CE5', '#006A34', '#B45309', '#7C4DFF', '#E2165F'];

        foreach ($rows as $index => &$row) {
            $row['tone'] = $palette[$index % count($palette)];
        }
        unset($row);

        return ['segments' => $rows, 'total' => array_sum(array_map(static fn (array $r): int => (int) $r['value'], $rows))];
    }

    private static function topCities(): array
    {
        $rows = Database::select(
            "SELECT `city` AS `label`, COUNT(*) AS `value` FROM `creators`
             WHERE `status` = 'approved' AND `deleted_at` IS NULL AND `city` IS NOT NULL AND `city` <> ''
             GROUP BY `city` ORDER BY `value` DESC LIMIT 6"
        );

        return ['bars' => $rows];
    }

    private static function retentionRate(): array
    {
        // Users who registered 30–60 days ago and have been seen in the last week.
        $cohort = (int) Database::scalar(
            'SELECT COUNT(*) FROM `users`
             WHERE `created_at` BETWEEN DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND DATE_SUB(CURDATE(), INTERVAL 30 DAY)
               AND `deleted_at` IS NULL AND `account_type` <> "guest"'
        );

        if ($cohort === 0) {
            return self::stat(0.0, 'neutral', 'no cohort yet');
        }

        $retained = (int) Database::scalar(
            'SELECT COUNT(*) FROM `users`
             WHERE `created_at` BETWEEN DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND DATE_SUB(CURDATE(), INTERVAL 30 DAY)
               AND `last_seen_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND `deleted_at` IS NULL
               AND `account_type` <> "guest"'
        );

        $rate = round(($retained / $cohort) * 100, 1);

        return self::stat($rate, $rate >= 40 ? 'ok' : 'warning', $retained . ' of ' . $cohort);
    }

    // =================================================================
    // Tables and lists
    // =================================================================

    private static function latestUsers(): array
    {
        return ['columns' => ['User', 'Type', 'Status', 'Joined'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => (string) $r['name'], 'sub' => (string) ($r['email'] ?? ''), 'avatar' => $r['avatar_url']],
                    ['chip' => ucfirst((string) $r['account_type']), 'tone' => $r['account_type'] === 'creator' ? 'info' : 'primary'],
                    ['chip' => ucfirst((string) $r['status']), 'tone' => $r['status'] === 'active' ? 'success' : 'warning'],
                    ['text' => Str::timeAgo((string) $r['created_at']), 'muted' => true],
                ],
                'href' => '/admin/users/' . (int) $r['id'],
            ],
            Database::select(
                'SELECT `id`, `name`, `email`, `avatar_url`, `account_type`, `status`, `created_at`
                 FROM `users` WHERE `deleted_at` IS NULL ORDER BY `id` DESC LIMIT 8'
            )
        )];
    }

    private static function latestCreators(): array
    {
        return ['columns' => ['Creator', 'Category', 'Status', 'Joined'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => (string) $r['display_name'], 'sub' => '@' . (string) $r['handle'], 'avatar' => $r['avatar_url']],
                    ['text' => (string) ($r['category_name'] ?? '—'), 'muted' => true],
                    ['chip' => ucfirst(str_replace('_', ' ', (string) $r['status'])),
                     'tone' => $r['status'] === 'approved' ? 'success' : ($r['status'] === 'pending_review' ? 'warning' : 'neutral')],
                    ['text' => Str::timeAgo((string) $r['created_at']), 'muted' => true],
                ],
                'href' => '/admin/creators/' . (int) $r['id'],
            ],
            Database::select(
                'SELECT c.`id`, c.`display_name`, c.`handle`, c.`status`, c.`created_at`,
                        u.`avatar_url`, cat.`name` AS `category_name`
                 FROM `creators` c
                 INNER JOIN `users` u ON u.`id` = c.`user_id`
                 LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`
                 WHERE c.`deleted_at` IS NULL ORDER BY c.`id` DESC LIMIT 8'
            )
        )];
    }

    private static function recentlyVerified(): array
    {
        $rows = Database::select(
            'SELECT c.`id`, c.`display_name` AS `label`, c.`verified_at`, u.`avatar_url`
             FROM `creators` c INNER JOIN `users` u ON u.`id` = c.`user_id`
             WHERE c.`is_verified` = 1 AND c.`verified_at` IS NOT NULL AND c.`deleted_at` IS NULL
             ORDER BY c.`verified_at` DESC LIMIT 6'
        );

        foreach ($rows as &$row) {
            $row['meta'] = Str::timeAgo((string) $row['verified_at']);
            $row['href'] = '/admin/creators/' . (int) $row['id'];
        }
        unset($row);

        return ['items' => $rows];
    }

    private static function suspendedAccounts(): array
    {
        return ['columns' => ['User', 'Status', 'Reason'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => (string) $r['name'], 'sub' => (string) ($r['email'] ?? ''), 'avatar' => $r['avatar_url']],
                    ['chip' => ucfirst((string) $r['status']), 'tone' => 'error'],
                    ['text' => Str::limit((string) ($r['suspended_reason'] ?? '—'), 40), 'muted' => true],
                ],
                'href' => '/admin/users/' . (int) $r['id'],
            ],
            Database::select(
                "SELECT `id`, `name`, `email`, `avatar_url`, `status`, `suspended_reason`
                 FROM `users` WHERE `status` IN ('suspended','banned') AND `deleted_at` IS NULL
                 ORDER BY `updated_at` DESC LIMIT 6"
            )
        )];
    }

    private static function deviceActivity(): array
    {
        $rows = Database::select(
            'SELECT `platform` AS `label`, COUNT(*) AS `value` FROM `user_devices` GROUP BY `platform` ORDER BY `value` DESC'
        );

        $palette = ['#B80049', '#632CE5', '#006A34'];

        foreach ($rows as $index => &$row) {
            $row['tone'] = $palette[$index % count($palette)];
        }
        unset($row);

        return ['segments' => $rows, 'total' => array_sum(array_map(static fn (array $r): int => (int) $r['value'], $rows))];
    }

    private static function latestBookings(): array
    {
        return ['columns' => ['Booking', 'Brand', 'Creator', 'Amount', 'Status'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => '#' . (string) $r['code'], 'sub' => Str::limit((string) $r['title'], 30), 'mono' => true],
                    ['text' => Str::limit((string) $r['brand_name'], 20), 'muted' => true],
                    ['text' => Str::limit((string) $r['creator_name'], 20), 'muted' => true],
                    ['text' => Money::formatShort((float) $r['total_amount'], (string) $r['currency']), 'strong' => true],
                    ['chip' => ucfirst(str_replace('_', ' ', (string) $r['status'])), 'tone' => self::bookingTone((string) $r['status'])],
                ],
                'href' => '/admin/bookings/' . (int) $r['id'],
            ],
            Database::select(
                'SELECT b.`id`, b.`code`, b.`title`, b.`status`, b.`total_amount`, b.`currency`,
                        COALESCE(bu.`company_name`, bu.`name`) AS `brand_name`, c.`display_name` AS `creator_name`
                 FROM `bookings` b
                 INNER JOIN `users` bu ON bu.`id` = b.`brand_user_id`
                 INNER JOIN `creators` c ON c.`id` = b.`creator_id`
                 WHERE b.`deleted_at` IS NULL ORDER BY b.`id` DESC LIMIT 8'
            )
        )];
    }

    private static function bookingStatusBreakdown(): array
    {
        $rows = Database::select(
            'SELECT `status` AS `label`, COUNT(*) AS `value` FROM `bookings`
             WHERE `deleted_at` IS NULL GROUP BY `status` ORDER BY `value` DESC LIMIT 8'
        );

        $palette = ['#B80049', '#632CE5', '#006A34', '#B45309', '#7C4DFF', '#E2165F', '#BA1A1A', '#8F6F73'];

        foreach ($rows as $index => &$row) {
            $row['label'] = ucfirst(str_replace('_', ' ', (string) $row['label']));
            $row['tone']  = $palette[$index % count($palette)];
        }
        unset($row);

        return ['segments' => $rows, 'total' => array_sum(array_map(static fn (array $r): int => (int) $r['value'], $rows))];
    }

    private static function upcomingDeliveries(): array
    {
        return ['columns' => ['Booking', 'Creator', 'Due'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => '#' . (string) $r['code'], 'mono' => true],
                    ['text' => Str::limit((string) $r['creator_name'], 22), 'muted' => true],
                    ['text' => Str::timeAgo((string) $r['delivery_due_at']), 'tone' => 'warning'],
                ],
                'href' => '/admin/bookings/' . (int) $r['id'],
            ],
            Database::select(
                "SELECT b.`id`, b.`code`, b.`delivery_due_at`, c.`display_name` AS `creator_name`
                 FROM `bookings` b INNER JOIN `creators` c ON c.`id` = b.`creator_id`
                 WHERE b.`status` IN ('confirmed','in_progress') AND b.`delivery_due_at` IS NOT NULL
                   AND b.`deleted_at` IS NULL
                 ORDER BY b.`delivery_due_at` ASC LIMIT 6"
            )
        )];
    }

    private static function pendingApprovals(): array
    {
        return ['columns' => ['Creator', 'Category', 'Waiting'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => (string) $r['display_name'], 'sub' => '@' . (string) $r['handle'], 'avatar' => $r['avatar_url']],
                    ['text' => (string) ($r['category_name'] ?? '—'), 'muted' => true],
                    ['text' => Str::timeAgo((string) $r['created_at']), 'tone' => 'warning'],
                ],
                'href' => '/admin/creators/' . (int) $r['id'],
            ],
            Database::select(
                "SELECT c.`id`, c.`display_name`, c.`handle`, c.`created_at`, u.`avatar_url`, cat.`name` AS `category_name`
                 FROM `creators` c
                 INNER JOIN `users` u ON u.`id` = c.`user_id`
                 LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`
                 WHERE c.`status` = 'pending_review' AND c.`deleted_at` IS NULL
                 ORDER BY c.`id` ASC LIMIT 6"
            )
        )];
    }

    // =================================================================
    // Support
    // =================================================================

    private static function openTickets(): array
    {
        $count = (int) Database::scalar("SELECT COUNT(*) FROM `support_tickets` WHERE `status` IN ('open','pending_agent')");

        return self::stat($count, $count > 0 ? 'warning' : 'ok', $count > 0 ? 'Needs a reply' : 'Inbox zero');
    }

    private static function pendingTickets(): array
    {
        return self::stat((int) Database::scalar("SELECT COUNT(*) FROM `support_tickets` WHERE `status` = 'pending_user'"));
    }

    private static function closedTickets(): array
    {
        return self::stat((int) Database::scalar("SELECT COUNT(*) FROM `support_tickets` WHERE `status` IN ('resolved','closed')"));
    }

    private static function recentTickets(): array
    {
        return ['columns' => ['Ticket', 'Customer', 'Priority', 'Status'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => (string) $r['code'], 'sub' => Str::limit((string) $r['subject'], 30), 'mono' => true],
                    ['text' => Str::limit((string) $r['user_name'], 20), 'muted' => true],
                    ['chip' => ucfirst((string) $r['priority']),
                     'tone' => $r['priority'] === 'urgent' ? 'error' : ($r['priority'] === 'high' ? 'warning' : 'neutral')],
                    ['chip' => ucfirst(str_replace('_', ' ', (string) $r['status'])),
                     'tone' => in_array($r['status'], ['resolved', 'closed'], true) ? 'success' : 'warning'],
                ],
                'href' => '/admin/tickets/' . (int) $r['id'],
            ],
            Database::select(
                'SELECT t.`id`, t.`code`, t.`subject`, t.`priority`, t.`status`, u.`name` AS `user_name`
                 FROM `support_tickets` t INNER JOIN `users` u ON u.`id` = t.`user_id`
                 ORDER BY t.`last_reply_at` DESC LIMIT 8'
            )
        )];
    }

    private static function ticketsByPriority(): array
    {
        return ['bars' => Database::select(
            "SELECT `priority` AS `label`, COUNT(*) AS `value` FROM `support_tickets`
             WHERE `status` IN ('open','pending_agent') GROUP BY `priority` ORDER BY `value` DESC"
        )];
    }

    private static function openReports(): array
    {
        $count = (int) Database::scalar("SELECT COUNT(*) FROM `reports` WHERE `status` IN ('open','investigating')");

        return self::stat($count, $count > 0 ? 'warning' : 'ok');
    }

    private static function newContactMessages(): array
    {
        return self::stat((int) Database::scalar("SELECT COUNT(*) FROM `contact_messages` WHERE `status` = 'new'"));
    }

    // =================================================================
    // Content
    // =================================================================

    private static function recentBlogs(): array
    {
        return ['columns' => ['Article', 'Status', 'Views'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => Str::limit((string) $r['title'], 34)],
                    ['chip' => ucfirst((string) $r['status']), 'tone' => $r['status'] === 'published' ? 'success' : 'neutral'],
                    ['text' => Str::compactNumber((int) $r['views_count']), 'mono' => true],
                ],
                'href' => '/admin/blogs/' . (int) $r['id'] . '/edit',
            ],
            Database::select(
                'SELECT `id`, `title`, `status`, `views_count` FROM `blogs`
                 WHERE `deleted_at` IS NULL ORDER BY `id` DESC LIMIT 6'
            )
        )];
    }

    private static function bannerPerformance(): array
    {
        return ['columns' => ['Banner', 'Views', 'Clicks', 'CTR'], 'rows' => array_map(
            static function (array $r): array {
                $impressions = (int) $r['impressions'];
                $ctr = $impressions > 0 ? round(((int) $r['clicks'] / $impressions) * 100, 1) : 0.0;

                return [
                    'cells' => [
                        ['text' => Str::limit((string) $r['title'], 26)],
                        ['text' => Str::compactNumber($impressions), 'mono' => true],
                        ['text' => Str::compactNumber((int) $r['clicks']), 'mono' => true],
                        ['text' => $ctr . '%', 'tone' => $ctr >= 2 ? 'success' : 'neutral'],
                    ],
                    'href' => '/admin/banners/' . (int) $r['id'] . '/edit',
                ];
            },
            Database::select('SELECT `id`, `title`, `impressions`, `clicks` FROM `banners` ORDER BY `impressions` DESC LIMIT 6')
        )];
    }

    private static function faqStats(): array
    {
        return ['columns' => ['Question', 'Helpful', 'Not helpful'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => Str::limit((string) $r['question'], 40)],
                    ['text' => (string) $r['helpful_yes'], 'tone' => 'success', 'mono' => true],
                    ['text' => (string) $r['helpful_no'], 'tone' => (int) $r['helpful_no'] > (int) $r['helpful_yes'] ? 'error' : 'neutral', 'mono' => true],
                ],
                'href' => '/admin/faqs/' . (int) $r['id'] . '/edit',
            ],
            Database::select(
                'SELECT `id`, `question`, `helpful_yes`, `helpful_no` FROM `faqs`
                 WHERE `is_active` = 1 ORDER BY (`helpful_yes` + `helpful_no`) DESC LIMIT 6'
            )
        )];
    }

    private static function mediaStorage(): array
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS `files`, COALESCE(SUM(`size_bytes`), 0) AS `bytes` FROM `media` WHERE `deleted_at` IS NULL'
        ) ?? [];

        $bytes = (int) ($row['bytes'] ?? 0);
        $quota = 5 * 1024 * 1024 * 1024; // 5 GB reference quota

        return [
            'value'   => self::formatBytes($bytes),
            'percent' => min(100, (int) round(($bytes / $quota) * 100)),
            'meta'    => number_format((int) ($row['files'] ?? 0)) . ' files',
        ];
    }

    // =================================================================
    // Security
    // =================================================================

    private static function failedLogins24h(): array
    {
        $count = (int) Database::scalar(
            "SELECT COUNT(*) FROM `login_history`
             WHERE `outcome` <> 'success' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        return self::stat($count, $count > 20 ? 'critical' : ($count > 5 ? 'warning' : 'ok'), 'last 24 hours');
    }

    private static function activeAdminSessions(): array
    {
        return self::stat((int) Database::scalar(
            'SELECT COUNT(*) FROM `admin_sessions` WHERE `last_activity` >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)'
        ));
    }

    private static function recentLogins(): array
    {
        return ['columns' => ['Identifier', 'Outcome', 'IP', 'When'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => Str::maskEmail((string) $r['identifier']), 'sub' => ucfirst((string) $r['actor_type'])],
                    ['chip' => ucfirst(str_replace('_', ' ', (string) $r['outcome'])),
                     'tone' => $r['outcome'] === 'success' ? 'success' : 'error'],
                    ['text' => (string) ($r['ip_address'] ?? '—'), 'mono' => true, 'muted' => true],
                    ['text' => Str::timeAgo((string) $r['created_at']), 'muted' => true],
                ],
            ],
            Database::select('SELECT * FROM `login_history` ORDER BY `id` DESC LIMIT 8')
        )];
    }

    private static function recentActivity(): array
    {
        return ['columns' => ['Actor', 'Action', 'Subject', 'When'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => (string) ($r['actor_name'] ?? ucfirst((string) $r['actor_type']))],
                    ['text' => (string) $r['action'], 'mono' => true],
                    ['text' => trim((string) ($r['subject_type'] ?? '—') . ' ' . ($r['subject_id'] ? '#' . (int) $r['subject_id'] : '')), 'muted' => true],
                    ['text' => Str::timeAgo((string) $r['created_at']), 'muted' => true],
                ],
            ],
            Database::select('SELECT * FROM `activity_logs` ORDER BY `id` DESC LIMIT 8')
        )];
    }

    private static function securityAlerts(): array
    {
        $alerts = [];

        $repeatOffenders = Database::select(
            "SELECT `ip_address`, COUNT(*) AS `attempts` FROM `login_history`
             WHERE `outcome` <> 'success' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
             GROUP BY `ip_address` HAVING `attempts` >= 5 ORDER BY `attempts` DESC LIMIT 3"
        );

        foreach ($repeatOffenders as $offender) {
            $alerts[] = [
                'label' => (string) $offender['ip_address'],
                'meta'  => (int) $offender['attempts'] . ' failed attempts in the last hour',
                'tone'  => 'error',
                'href'  => '/admin/security/login-history?q=' . urlencode((string) $offender['ip_address']),
            ];
        }

        $lockedAdmins = (int) Database::scalar('SELECT COUNT(*) FROM `admins` WHERE `locked_until` > NOW()');

        if ($lockedAdmins > 0) {
            $alerts[] = ['label' => $lockedAdmins . ' admin account(s) locked', 'meta' => 'Too many failed sign-ins', 'tone' => 'warning', 'href' => '/admin/admins'];
        }

        $noTwoFactor = (int) Database::scalar(
            "SELECT COUNT(*) FROM `admins` a INNER JOIN `roles` r ON r.`id` = a.`role_id`
             WHERE a.`two_factor_enabled` = 0 AND a.`status` = 'active' AND a.`deleted_at` IS NULL AND r.`level` >= 70"
        );

        if ($noTwoFactor > 0) {
            $alerts[] = ['label' => $noTwoFactor . ' privileged admin(s) without 2FA', 'meta' => 'Recommend enabling two-factor', 'tone' => 'warning', 'href' => '/admin/admins'];
        }

        if ($alerts === []) {
            $alerts[] = ['label' => 'No security alerts', 'meta' => 'Nothing needs attention', 'tone' => 'success'];
        }

        return ['items' => $alerts];
    }

    private static function apiRequests24h(): array
    {
        return self::stat((int) Database::scalar(
            'SELECT COUNT(*) FROM `api_logs` WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        ));
    }

    private static function apiErrors24h(): array
    {
        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM `api_logs` WHERE `status_code` >= 500 AND `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );

        return self::stat($count, $count > 0 ? 'warning' : 'ok', 'last 24 hours');
    }

    private static function recentErrors(): array
    {
        return ['columns' => ['Level', 'Message', 'When'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['chip' => (string) $r['level'], 'tone' => in_array($r['level'], ['critical', 'error', 'alert'], true) ? 'error' : 'warning'],
                    ['text' => Str::limit((string) $r['message'], 60)],
                    ['text' => Str::timeAgo((string) $r['created_at']), 'muted' => true],
                ],
            ],
            Database::select(
                "SELECT `level`, `message`, `created_at` FROM `system_logs`
                 WHERE `level` IN ('warning','error','critical','alert','emergency')
                 ORDER BY `id` DESC LIMIT 8"
            )
        )];
    }

    // =================================================================
    // Server
    // =================================================================

    private static function systemHealth(): array
    {
        $checks = [];

        try {
            $started = microtime(true);
            Database::scalar('SELECT 1');
            $checks[] = ['label' => 'Database', 'value' => round((microtime(true) - $started) * 1000, 1) . 'ms', 'tone' => 'success'];
        } catch (\Throwable) {
            $checks[] = ['label' => 'Database', 'value' => 'Unreachable', 'tone' => 'error'];
        }

        $uploadPath = (string) Config::get('storage.upload_path');
        $logPath    = (string) Config::get('log.path');

        $checks[] = ['label' => 'Uploads writable', 'value' => is_writable($uploadPath) ? 'Yes' : 'No', 'tone' => is_writable($uploadPath) ? 'success' : 'error'];
        $checks[] = ['label' => 'Logs writable',    'value' => is_writable($logPath) ? 'Yes' : 'No',    'tone' => is_writable($logPath) ? 'success' : 'error'];
        $checks[] = ['label' => 'PHP',              'value' => PHP_VERSION, 'tone' => version_compare(PHP_VERSION, '8.1', '>=') ? 'success' : 'warning'];
        $checks[] = ['label' => 'Debug mode',       'value' => Config::get('app.debug') ? 'ON' : 'off',
                     'tone' => Config::get('app.debug') && Config::get('app.env') === 'production' ? 'error' : 'success'];

        $failedJobs = (int) Database::scalar("SELECT COUNT(*) FROM `scheduled_jobs` WHERE `last_status` = 'failed'");
        $checks[]   = ['label' => 'Scheduled jobs', 'value' => $failedJobs === 0 ? 'Healthy' : $failedJobs . ' failing',
                       'tone' => $failedJobs === 0 ? 'success' : 'warning'];

        return ['rows' => $checks];
    }

    private static function diskUsage(): array
    {
        $base  = (string) Config::get('app.base_path');
        $free  = (float) (@disk_free_space($base) ?: 0);
        $total = (float) (@disk_total_space($base) ?: 0);
        $used  = $total - $free;

        return [
            'value'   => self::formatBytes((int) $used) . ' / ' . self::formatBytes((int) $total),
            'percent' => $total > 0 ? (int) round(($used / $total) * 100) : 0,
            'meta'    => self::formatBytes((int) $free) . ' free',
        ];
    }

    private static function databaseSize(): array
    {
        $bytes = (float) Database::scalar(
            'SELECT COALESCE(SUM(`DATA_LENGTH` + `INDEX_LENGTH`), 0) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = :schema',
            ['schema' => (string) Config::get('database.name')]
        );

        return self::stat($bytes);
    }

    private static function memoryUsage(): array
    {
        $limitRaw = (string) ini_get('memory_limit');
        $limit    = self::parseBytes($limitRaw);
        $used     = memory_get_peak_usage(true);

        return [
            'value'   => self::formatBytes($used),
            'percent' => $limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : 0,
            'meta'    => 'limit ' . $limitRaw,
        ];
    }

    private static function backupStatus(): array
    {
        $latest = Database::selectOne("SELECT * FROM `backups` WHERE `status` = 'completed' ORDER BY `id` DESC LIMIT 1");

        if ($latest === null) {
            return ['rows' => [['label' => 'No backups yet', 'value' => '—', 'tone' => 'error']]];
        }

        $ageHours = (time() - strtotime((string) $latest['created_at'])) / 3600;

        return ['rows' => [
            ['label' => 'Latest backup', 'value' => Str::timeAgo((string) $latest['created_at']),
             'tone' => $ageHours > 48 ? 'error' : ($ageHours > 26 ? 'warning' : 'success')],
            ['label' => 'Size',  'value' => self::formatBytes((int) $latest['size_bytes'])],
            ['label' => 'Total', 'value' => (string) Database::scalar('SELECT COUNT(*) FROM `backups`') . ' kept'],
        ]];
    }

    private static function cronStatus(): array
    {
        return ['columns' => ['Job', 'Last run', 'Status'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => (string) $r['job_key'], 'mono' => true],
                    ['text' => $r['last_run_at'] ? Str::timeAgo((string) $r['last_run_at']) : 'Never', 'muted' => true],
                    ['chip' => ucfirst((string) $r['last_status']),
                     'tone' => match ((string) $r['last_status']) { 'success' => 'success', 'failed' => 'error', 'running' => 'warning', default => 'neutral' }],
                ],
            ],
            Database::select('SELECT `job_key`, `last_run_at`, `last_status` FROM `scheduled_jobs` ORDER BY `id`')
        )];
    }

    private static function queueStatus(): array
    {
        $rows = [];

        foreach (['email_queue' => 'Email', 'sms_queue' => 'SMS', 'push_queue' => 'Push'] as $table => $label) {
            $pending = (int) Database::scalar(
                'SELECT COUNT(*) FROM ' . Database::quoteIdentifier($table) . " WHERE `status` = 'pending'"
            );
            $failed  = (int) Database::scalar(
                'SELECT COUNT(*) FROM ' . Database::quoteIdentifier($table) . " WHERE `status` = 'failed'"
            );

            $rows[] = [
                'label' => $label,
                'value' => $pending . ' pending',
                'meta'  => $failed > 0 ? $failed . ' failed' : null,
                'tone'  => $failed > 0 ? 'error' : ($pending > 100 ? 'warning' : 'success'),
            ];
        }

        return ['rows' => $rows];
    }

    private static function cacheStatus(): array
    {
        $stats = Cache::stats();

        return ['rows' => [
            ['label' => 'Driver',  'value' => (string) $stats['driver']],
            ['label' => 'Entries', 'value' => number_format((int) $stats['entries'])],
            ['label' => 'Size',    'value' => self::formatBytes((int) $stats['bytes'])],
            ['label' => 'Hits',    'value' => Str::compactNumber((int) $stats['hits'])],
        ]];
    }

    // =================================================================
    // Marketing
    // =================================================================

    private static function newsletterSubscribers(): array
    {
        return self::stat((int) Database::scalar("SELECT COUNT(*) FROM `newsletter_subscribers` WHERE `status` = 'subscribed'"));
    }

    private static function couponUsage(): array
    {
        return ['columns' => ['Code', 'Used', 'Limit', 'Status'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => (string) $r['code'], 'mono' => true, 'strong' => true],
                    ['text' => (string) $r['used_count'], 'mono' => true],
                    ['text' => $r['usage_limit'] === null ? '∞' : (string) $r['usage_limit'], 'muted' => true],
                    ['chip' => $r['is_active'] ? 'Active' : 'Off', 'tone' => $r['is_active'] ? 'success' : 'neutral'],
                ],
                'href' => '/admin/coupons/' . (int) $r['id'] . '/edit',
            ],
            Database::select(
                'SELECT `id`, `code`, `used_count`, `usage_limit`, `is_active` FROM `coupons`
                 WHERE `deleted_at` IS NULL ORDER BY `used_count` DESC LIMIT 6'
            )
        )];
    }

    private static function referralStats(): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS `total`,
                    SUM(CASE WHEN `status` = 'rewarded' THEN 1 ELSE 0 END) AS `rewarded`,
                    COALESCE(SUM(CASE WHEN `status` = 'rewarded' THEN `referrer_reward` + `referred_reward` ELSE 0 END), 0) AS `paid`
             FROM `referrals`"
        ) ?? [];

        return ['rows' => [
            ['label' => 'Total invites',   'value' => number_format((int) ($row['total'] ?? 0))],
            ['label' => 'Rewarded',        'value' => number_format((int) ($row['rewarded'] ?? 0))],
            ['label' => 'Rewards paid',    'value' => Money::formatShort((float) ($row['paid'] ?? 0)), 'emphasis' => true],
        ]];
    }

    private static function campaignPerformance(): array
    {
        return ['columns' => ['Campaign', 'Channel', 'Sent', 'Opened', 'Clicked'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => Str::limit((string) $r['name'], 28)],
                    ['chip' => ucfirst((string) $r['channel']), 'tone' => 'info'],
                    ['text' => number_format((int) $r['sent_count']), 'mono' => true],
                    ['text' => number_format((int) $r['opened_count']), 'mono' => true],
                    ['text' => number_format((int) $r['clicked_count']), 'mono' => true],
                ],
                'href' => '/admin/marketing/campaigns/' . (int) $r['id'],
            ],
            Database::select(
                'SELECT `id`, `name`, `channel`, `sent_count`, `opened_count`, `clicked_count`
                 FROM `marketing_campaigns` WHERE `deleted_at` IS NULL ORDER BY `id` DESC LIMIT 6'
            )
        )];
    }

    private static function popupPerformance(): array
    {
        return ['columns' => ['Popup', 'Views', 'Clicks', 'CTR'], 'rows' => array_map(
            static function (array $r): array {
                $impressions = (int) $r['impressions'];
                $ctr = $impressions > 0 ? round(((int) $r['clicks'] / $impressions) * 100, 1) : 0.0;

                return [
                    'cells' => [
                        ['text' => Str::limit((string) $r['name'], 26)],
                        ['text' => Str::compactNumber($impressions), 'mono' => true],
                        ['text' => Str::compactNumber((int) $r['clicks']), 'mono' => true],
                        ['text' => $ctr . '%', 'tone' => $ctr >= 3 ? 'success' : 'neutral'],
                    ],
                    'href' => '/admin/popups/' . (int) $r['id'] . '/edit',
                ];
            },
            Database::select(
                'SELECT `id`, `name`, `impressions`, `clicks` FROM `popups`
                 WHERE `deleted_at` IS NULL ORDER BY `impressions` DESC LIMIT 6'
            )
        )];
    }

    // =================================================================
    // AI
    // =================================================================

    private static function aiRequests(): array
    {
        return self::stat((int) Database::scalar(
            'SELECT COUNT(*) FROM `ai_logs` WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        ));
    }

    private static function aiTokens(): array
    {
        return self::stat((int) Database::scalar(
            'SELECT COALESCE(SUM(`input_tokens` + `output_tokens`), 0) FROM `ai_logs`
             WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        ));
    }

    private static function aiCost(): array
    {
        return self::stat((float) Database::scalar(
            'SELECT COALESCE(SUM(`cost`), 0) FROM `ai_logs` WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        ));
    }

    private static function aiErrors(): array
    {
        $count = (int) Database::scalar(
            "SELECT COUNT(*) FROM `ai_logs` WHERE `status` <> 'success' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        return self::stat($count, $count > 0 ? 'warning' : 'ok');
    }

    private static function aiActivity(): array
    {
        return ['columns' => ['Prompt', 'Model', 'Tokens', 'Status', 'When'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['text' => (string) ($r['prompt_code'] ?? '—'), 'mono' => true],
                    ['text' => (string) ($r['model'] ?? '—'), 'muted' => true],
                    ['text' => number_format((int) $r['input_tokens'] + (int) $r['output_tokens']), 'mono' => true],
                    ['chip' => ucfirst((string) $r['status']), 'tone' => $r['status'] === 'success' ? 'success' : 'error'],
                    ['text' => Str::timeAgo((string) $r['created_at']), 'muted' => true],
                ],
            ],
            Database::select('SELECT * FROM `ai_logs` ORDER BY `id` DESC LIMIT 8')
        )];
    }

    // =================================================================
    // Productivity
    // =================================================================

    private static function quickActions(array $options, array $admin): array
    {
        $permissions = PermissionService::forAdmin((int) $admin['id']);

        $candidates = [
            ['label' => 'Add coupon',       'href' => '/admin/coupons/create',    'icon' => 'card',      'permission' => 'marketing.manage', 'tone' => '#B80049'],
            ['label' => 'Send broadcast',   'href' => '/admin/notifications',     'icon' => 'megaphone', 'permission' => 'notifications.send', 'tone' => '#632CE5'],
            ['label' => 'Review creators',  'href' => '/admin/creators?status=pending_review', 'icon' => 'star', 'permission' => 'creators.approve', 'tone' => '#B45309'],
            ['label' => 'Approve payouts',  'href' => '/admin/withdrawals?status=pending', 'icon' => 'wallet', 'permission' => 'wallet.withdrawals', 'tone' => '#006A34'],
            ['label' => 'New article',      'href' => '/admin/blogs/create',      'icon' => 'file',      'permission' => 'cms.blogs', 'tone' => '#7C4DFF'],
            ['label' => 'Upload media',     'href' => '/admin/media',             'icon' => 'upload',    'permission' => 'cms.media', 'tone' => '#E2165F'],
            ['label' => 'Backup database',  'href' => '/admin/system?tab=backups', 'icon' => 'database', 'permission' => 'system.backup', 'tone' => '#4F00D0'],
            ['label' => 'Open settings',    'href' => '/admin/settings',          'icon' => 'settings',  'permission' => 'settings.view', 'tone' => '#8F6F73'],
        ];

        $actions = array_values(array_filter(
            $candidates,
            static fn (array $a): bool => in_array($a['permission'], $permissions, true)
        ));

        return ['actions' => array_slice($actions, 0, 6)];
    }

    private static function todoList(array $options, array $admin): array
    {
        return ['tasks' => Database::select(
            'SELECT `id`, `title`, `is_done`, `priority`, `due_on` FROM `admin_tasks`
             WHERE `admin_id` = :id ORDER BY `is_done`, `sort_order`, `id` DESC LIMIT 12',
            ['id' => (int) $admin['id']]
        )];
    }

    private static function stickyNotes(array $options, array $admin): array
    {
        return ['notes' => Database::select(
            'SELECT `id`, `body`, `color`, `is_pinned`, `updated_at` FROM `admin_notes`
             WHERE `admin_id` = :id ORDER BY `is_pinned` DESC, `id` DESC LIMIT 6',
            ['id' => (int) $admin['id']]
        )];
    }

    private static function announcements(array $options, array $admin): array
    {
        return ['items' => array_map(
            static fn (array $r): array => [
                'label' => (string) $r['title'],
                'meta'  => Str::limit(strip_tags((string) $r['body']), 90),
                'tone'  => match ((string) $r['severity']) {
                    'critical' => 'error', 'warning' => 'warning', 'success' => 'success', default => 'info',
                },
            ],
            Database::select(
                'SELECT * FROM `admin_announcements`
                 WHERE `is_active` = 1
                   AND (`starts_at` IS NULL OR `starts_at` <= NOW())
                   AND (`ends_at` IS NULL OR `ends_at` >= NOW())
                   AND (`role_id` IS NULL OR `role_id` = :role_id)
                 ORDER BY `id` DESC LIMIT 5',
                ['role_id' => (int) $admin['role_id']]
            )
        )];
    }

    private static function myRecentLogins(array $options, array $admin): array
    {
        return ['columns' => ['Outcome', 'IP', 'When'], 'rows' => array_map(
            static fn (array $r): array => [
                'cells' => [
                    ['chip' => ucfirst(str_replace('_', ' ', (string) $r['outcome'])),
                     'tone' => $r['outcome'] === 'success' ? 'success' : 'error'],
                    ['text' => (string) ($r['ip_address'] ?? '—'), 'mono' => true, 'muted' => true],
                    ['text' => Str::timeAgo((string) $r['created_at']), 'muted' => true],
                ],
            ],
            Database::select(
                "SELECT * FROM `login_history` WHERE `actor_type` = 'admin' AND `actor_id` = :id
                 ORDER BY `id` DESC LIMIT 6",
                ['id' => (int) $admin['id']]
            )
        )];
    }

    // =================================================================
    // Shaping helpers
    // =================================================================

    /** @return array<string,mixed> */
    private static function stat(int|float $value, string $tone = 'neutral', ?string $meta = null): array
    {
        return ['value' => $value, 'status' => $tone, 'meta' => $meta];
    }

    /** @return array<string,mixed> */
    private static function statWithDelta(int|float $value, int|float $recent, string $label): array
    {
        return ['value' => $value, 'status' => 'neutral', 'meta' => '+' . number_format((float) $recent) . ' ' . $label];
    }

    /** Percentage change against a comparison period. */
    private static function statWithChange(int|float $current, int|float $previous, string $label): array
    {
        $change = null;

        if ($previous > 0) {
            $change = round((($current - $previous) / $previous) * 100, 1);
        } elseif ($current > 0) {
            $change = 100.0;
        }

        return [
            'value'        => $current,
            'status'       => 'neutral',
            'change'       => $change,
            'change_label' => $label,
        ];
    }

    private static function sumAnalytics(string $column, int $daysBack, int $daysOffset): float
    {
        $allowed = ['gmv', 'revenue', 'refunds', 'payouts', 'wallet_topups'];

        if (!in_array($column, $allowed, true)) {
            return 0.0;
        }

        return (float) Database::scalar(
            'SELECT COALESCE(SUM(`' . $column . '`), 0) FROM `analytics_daily`
             WHERE `stat_date` >= DATE_SUB(CURDATE(), INTERVAL :back DAY)
               AND `stat_date` <  DATE_SUB(CURDATE(), INTERVAL :offset DAY)',
            ['back' => $daysBack, 'offset' => $daysOffset]
        );
    }

    /**
     * A gap-filled daily series so charts always have an unbroken axis.
     *
     * @return list<array{label:string,value:float}>
     */
    private static function analyticsSeries(string $column, int $days): array
    {
        $allowed = [
            'revenue', 'gmv', 'users_new', 'creators_new', 'users_active',
            'bookings_created', 'bookings_completed', 'payouts', 'refunds',
        ];

        if (!in_array($column, $allowed, true)) {
            $column = 'revenue';
        }

        $days = max(7, min(365, $days));

        $rows = Database::select(
            'SELECT `stat_date`, `' . $column . '` AS `value` FROM `analytics_daily`
             WHERE `stat_date` >= DATE_SUB(CURDATE(), INTERVAL :days DAY) ORDER BY `stat_date`',
            ['days' => $days]
        );

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['stat_date']] = (float) $row['value'];
        }

        $series = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date     = date('Y-m-d', strtotime('-' . $offset . ' days'));
            $series[] = ['label' => date('d M', strtotime($date)), 'value' => $byDate[$date] ?? 0.0];
        }

        return $series;
    }

    private static function bookingTone(string $status): string
    {
        return match ($status) {
            'completed'                                                => 'success',
            'pending', 'countered', 'awaiting_payment', 'accepted'     => 'warning',
            'confirmed', 'in_progress', 'delivered'                    => 'info',
            'cancelled', 'rejected', 'expired', 'disputed', 'refunded' => 'error',
            default                                                    => 'neutral',
        };
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, $index > 1 ? 1 : 0) . ' ' . $units[$index];
    }

    private static function parseBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}

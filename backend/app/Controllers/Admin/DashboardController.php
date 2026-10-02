<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Money;
use Zuste\Services\ExportService;

/**
 * Dashboard and analytics.
 *
 * Every figure is read from the pre-aggregated `analytics_daily` table where
 * possible — the roll-up cron does the heavy lifting so the dashboard stays
 * fast no matter how large the transaction tables grow.
 */
final class DashboardController extends BaseAdminController
{
    /** GET /admin */
    public function index(Request $request): never
    {
        $this->authorize($request, 'dashboard.view');

        $this->view($request, 'admin.dashboard.index', [
            'pageTitle'          => 'Dashboard',
            'pageSubtitle'       => 'Marketplace health at a glance',
            'kpis'               => $this->kpis(),
            'revenueSeries'      => $this->series('revenue', 30),
            'signupSeries'       => $this->series('users_new', 14),
            'funnel'             => $this->funnel(),
            'recentBookings'     => $this->recentBookings(),
            'pendingCreators'    => $this->pendingCreators(),
            'pendingWithdrawals' => $this->pendingWithdrawals(),
            'topCreators'        => $this->topCreators(),
        ]);
    }

    /** GET /admin/analytics */
    public function analytics(Request $request): never
    {
        $this->authorize($request, 'analytics.view');

        $days = max(7, min(365, $request->int('days', 30)));

        $daily = Database::select(
            'SELECT * FROM `analytics_daily`
             WHERE `stat_date` >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             ORDER BY `stat_date`',
            ['days' => $days]
        );

        $totals = Database::selectOne(
            'SELECT
                COALESCE(SUM(`users_new`), 0)          AS `users_new`,
                COALESCE(SUM(`creators_new`), 0)       AS `creators_new`,
                COALESCE(SUM(`bookings_created`), 0)   AS `bookings_created`,
                COALESCE(SUM(`bookings_completed`), 0) AS `bookings_completed`,
                COALESCE(SUM(`gmv`), 0)                AS `gmv`,
                COALESCE(SUM(`revenue`), 0)            AS `revenue`,
                COALESCE(SUM(`refunds`), 0)            AS `refunds`,
                COALESCE(SUM(`payouts`), 0)            AS `payouts`,
                COALESCE(SUM(`messages_sent`), 0)      AS `messages_sent`,
                COALESCE(SUM(`searches`), 0)           AS `searches`,
                COALESCE(SUM(`profile_views`), 0)      AS `profile_views`
             FROM `analytics_daily`
             WHERE `stat_date` >= DATE_SUB(CURDATE(), INTERVAL :days DAY)',
            ['days' => $days]
        ) ?? [];

        // Category performance drives the "where is demand coming from" view.
        $categories = Database::select(
            "SELECT cat.`name`, cat.`slug`, cat.`color_hex`,
                    COUNT(DISTINCT c.`id`)  AS `creators`,
                    COUNT(DISTINCT b.`id`)  AS `bookings`,
                    COALESCE(SUM(CASE WHEN b.`status` = 'completed' THEN b.`total_amount` ELSE 0 END), 0) AS `gmv`
             FROM `categories` cat
             LEFT JOIN `creator_categories` cc ON cc.`category_id` = cat.`id`
             LEFT JOIN `creators` c ON c.`id` = cc.`creator_id` AND c.`status` = 'approved' AND c.`deleted_at` IS NULL
             LEFT JOIN `bookings` b ON b.`creator_id` = c.`id` AND b.`deleted_at` IS NULL
                    AND b.`created_at` >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             WHERE cat.`is_active` = 1 AND cat.`deleted_at` IS NULL
             GROUP BY cat.`id`
             ORDER BY `gmv` DESC, `creators` DESC
             LIMIT 12",
            ['days' => $days]
        );

        $cities = Database::select(
            "SELECT c.`city`, COUNT(*) AS `creators`,
                    COALESCE(AVG(c.`rating_avg`), 0) AS `avg_rating`
             FROM `creators` c
             WHERE c.`status` = 'approved' AND c.`deleted_at` IS NULL AND c.`city` IS NOT NULL AND c.`city` <> ''
             GROUP BY c.`city` ORDER BY `creators` DESC LIMIT 10"
        );

        $platforms = Database::select(
            "SELECT `platform`, COUNT(*) AS `bookings`,
                    COALESCE(SUM(`total_amount`), 0) AS `volume`
             FROM `bookings`
             WHERE `deleted_at` IS NULL AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY `platform` ORDER BY `bookings` DESC",
            ['days' => $days]
        );

        // Accept every format the export buttons offer. Testing for 'csv'
        // alone let ?export=xlsx and ?export=pdf fall through to the HTML
        // list below, so those buttons silently re-rendered the screen
        // instead of downloading anything.
        $exportAs = strtolower($request->string('export'));

        if (in_array($exportAs, ['csv', 'xlsx', 'pdf'], true)) {
            $this->authorize($request, 'export.data');

            $labels  = ['Date', 'New users', 'New creators', 'Bookings', 'Completed', 'GMV', 'Revenue', 'Refunds', 'Payouts'];
            $columns = ['stat_date', 'users_new', 'creators_new', 'bookings_created', 'bookings_completed', 'gmv', 'revenue', 'refunds', 'payouts'];

            switch ($exportAs) {
                case 'xlsx':
                    ExportService::xlsx('zuste-analytics', 'Analytics', $labels, ExportService::matrix($daily, $columns));
                    // no break — xlsx() never returns

                case 'pdf':
                    // A print-ready page that fires the browser's print dialog;
                    // the browser writes the PDF, which is why no PDF library
                    // is needed.
                    ExportService::pdf(
                        'Analytics',
                        $labels,
                        ExportService::matrix($daily, $columns),
                        'Last ' . $days . ' days — ' . count($daily) . ' day(s), exported ' . date('d M Y, g:i A')
                    );
                    // no break — pdf() never returns

                default:
                    $this->exportCsv('zuste-analytics', $labels, $daily, $columns);
            }
        }

        $this->view($request, 'admin.analytics.index', [
            'pageTitle'    => 'Analytics',
            'pageSubtitle' => 'Last ' . $days . ' days',
            'days'         => $days,
            'daily'        => $daily,
            'totals'       => $totals,
            'categories'   => $categories,
            'cities'       => $cities,
            'platforms'    => $platforms,
            'gmvSeries'    => array_map(
                static fn (array $row): array => ['label' => date('d M', strtotime((string) $row['stat_date'])), 'value' => (float) $row['gmv']],
                $daily
            ),
            'revenueSeries' => array_map(
                static fn (array $row): array => ['label' => date('d M', strtotime((string) $row['stat_date'])), 'value' => (float) $row['revenue']],
                $daily
            ),
            'bookingSeries' => array_map(
                static fn (array $row): array => ['label' => date('d M', strtotime((string) $row['stat_date'])), 'value' => (int) $row['bookings_created']],
                $daily
            ),
        ]);
    }

    /** GET /admin/api/stats — JSON feed for live tiles. */
    public function stats(Request $request): never
    {
        $this->authorize($request, 'dashboard.view');

        Response::success([
            'kpis'   => $this->kpis(),
            'badges' => $this->badges(),
        ]);
    }

    // -----------------------------------------------------------------
    // Data
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function kpis(): array
    {
        $current = Database::selectOne(
            'SELECT COALESCE(SUM(`gmv`), 0) AS `gmv`, COALESCE(SUM(`revenue`), 0) AS `revenue`,
                    COALESCE(SUM(`bookings_created`), 0) AS `bookings`, COALESCE(SUM(`users_new`), 0) AS `users`
             FROM `analytics_daily` WHERE `stat_date` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
        ) ?? [];

        $previous = Database::selectOne(
            'SELECT COALESCE(SUM(`gmv`), 0) AS `gmv`, COALESCE(SUM(`revenue`), 0) AS `revenue`,
                    COALESCE(SUM(`bookings_created`), 0) AS `bookings`, COALESCE(SUM(`users_new`), 0) AS `users`
             FROM `analytics_daily`
             WHERE `stat_date` >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
               AND `stat_date` <  DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
        ) ?? [];

        $delta = static function (float $now, float $before): ?float {
            if ($before <= 0.0) {
                return $now > 0 ? 100.0 : null;
            }

            return round((($now - $before) / $before) * 100, 1);
        };

        return [
            'gmv_30d'            => Money::round((float) ($current['gmv'] ?? 0)),
            'revenue_30d'        => Money::round((float) ($current['revenue'] ?? 0)),
            'bookings_30d'       => (int) ($current['bookings'] ?? 0),
            'users_30d'          => (int) ($current['users'] ?? 0),
            'gmv_delta'          => $delta((float) ($current['gmv'] ?? 0), (float) ($previous['gmv'] ?? 0)),
            'revenue_delta'      => $delta((float) ($current['revenue'] ?? 0), (float) ($previous['revenue'] ?? 0)),
            'bookings_delta'     => $delta((float) ($current['bookings'] ?? 0), (float) ($previous['bookings'] ?? 0)),
            'users_delta'        => $delta((float) ($current['users'] ?? 0), (float) ($previous['users'] ?? 0)),

            // Guests are real `users` rows but not registrations, so they are
            // excluded from anything an operator reads as "how many users do we
            // have". They are counted on the Authentication screen instead.
            'total_users'        => (int) Database::scalar(
                "SELECT COUNT(*) FROM `users` WHERE `deleted_at` IS NULL AND `account_type` <> 'guest'"
            ),
            'total_creators'     => (int) Database::scalar("SELECT COUNT(*) FROM `creators` WHERE `deleted_at` IS NULL"),
            'approved_creators'  => (int) Database::scalar("SELECT COUNT(*) FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL"),
            'pending_creators'   => (int) Database::scalar("SELECT COUNT(*) FROM `creators` WHERE `status` = 'pending_review' AND `deleted_at` IS NULL"),
            'active_bookings'    => (int) Database::scalar(
                "SELECT COUNT(*) FROM `bookings`
                 WHERE `status` IN ('confirmed','in_progress','delivered') AND `deleted_at` IS NULL"
            ),
            'escrow_held'        => Money::round((float) Database::scalar(
                "SELECT COALESCE(SUM(`total_amount`), 0) FROM `bookings` WHERE `escrow_status` = 'held' AND `deleted_at` IS NULL"
            )),
            'pending_withdrawals' => (int) Database::scalar("SELECT COUNT(*) FROM `withdrawal_requests` WHERE `status` = 'pending'"),
            'pending_payouts'    => Money::round((float) Database::scalar(
                "SELECT COALESCE(SUM(`amount`), 0) FROM `withdrawal_requests` WHERE `status` IN ('pending','approved','processing')"
            )),
        ];
    }

    /**
     * @return list<array{label:string,value:float}>
     */
    private function series(string $column, int $days): array
    {
        $allowed = ['revenue', 'gmv', 'users_new', 'bookings_created', 'bookings_completed', 'payouts', 'refunds'];

        if (!in_array($column, $allowed, true)) {
            $column = 'revenue';
        }

        $rows = Database::select(
            'SELECT `stat_date`, `' . $column . '` AS `value`
             FROM `analytics_daily`
             WHERE `stat_date` >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             ORDER BY `stat_date`',
            ['days' => $days]
        );

        // Fill gaps so the chart has an unbroken x-axis even before the
        // roll-up job has produced a row for every day.
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

    /** @return array<string,mixed> */
    private function funnel(): array
    {
        $row = Database::selectOne(
            "SELECT
                COUNT(*) AS `requested`,
                SUM(CASE WHEN `accepted_at` IS NOT NULL THEN 1 ELSE 0 END)  AS `accepted`,
                SUM(CASE WHEN `paid_at` IS NOT NULL THEN 1 ELSE 0 END)      AS `paid`,
                SUM(CASE WHEN `status` = 'completed' THEN 1 ELSE 0 END)     AS `completed`
             FROM `bookings`
             WHERE `deleted_at` IS NULL AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        ) ?? [];

        $requested = (int) ($row['requested'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);

        return [
            'requested'  => $requested,
            'accepted'   => (int) ($row['accepted'] ?? 0),
            'paid'       => (int) ($row['paid'] ?? 0),
            'completed'  => $completed,
            'conversion' => $requested > 0 ? round(($completed / $requested) * 100, 1) : 0.0,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function recentBookings(): array
    {
        return Database::select(
            'SELECT b.`id`, b.`code`, b.`title`, b.`status`, b.`total_amount`, b.`currency`, b.`created_at`,
                    COALESCE(bu.`company_name`, bu.`name`) AS `brand_name`,
                    c.`display_name` AS `creator_name`
             FROM `bookings` b
             INNER JOIN `users` bu ON bu.`id` = b.`brand_user_id`
             INNER JOIN `creators` c ON c.`id` = b.`creator_id`
             WHERE b.`deleted_at` IS NULL
             ORDER BY b.`id` DESC LIMIT 8'
        );
    }

    /** @return list<array<string,mixed>> */
    private function pendingCreators(): array
    {
        return Database::select(
            "SELECT c.`id`, c.`display_name`, c.`handle`, c.`created_at`,
                    u.`avatar_url`, cat.`name` AS `category_name`
             FROM `creators` c
             INNER JOIN `users` u ON u.`id` = c.`user_id`
             LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`
             WHERE c.`status` = 'pending_review' AND c.`deleted_at` IS NULL
             ORDER BY c.`id` ASC LIMIT 6"
        );
    }

    /** @return list<array<string,mixed>> */
    private function pendingWithdrawals(): array
    {
        return Database::select(
            "SELECT w.`id`, w.`reference`, w.`amount`, w.`currency`, w.`created_at`, u.`name` AS `user_name`
             FROM `withdrawal_requests` w
             INNER JOIN `users` u ON u.`id` = w.`user_id`
             WHERE w.`status` = 'pending'
             ORDER BY w.`id` ASC LIMIT 6"
        );
    }

    /** @return list<array<string,mixed>> */
    private function topCreators(): array
    {
        return Database::select(
            "SELECT c.`display_name`, c.`rating_avg`, u.`avatar_url`,
                    COUNT(b.`id`) AS `collabs`,
                    COALESCE(SUM(b.`creator_earning`), 0) AS `earnings`
             FROM `creators` c
             INNER JOIN `users` u ON u.`id` = c.`user_id`
             INNER JOIN `bookings` b ON b.`creator_id` = c.`id` AND b.`status` = 'completed' AND b.`deleted_at` IS NULL
             WHERE c.`deleted_at` IS NULL
             GROUP BY c.`id`
             ORDER BY `earnings` DESC
             LIMIT 7"
        );
    }
}

<?php

declare(strict_types=1);

namespace Zuste\Admin;

/**
 * Declarative dashboard widget registry.
 *
 * Every widget on the dashboard is a row in this registry rather than a file
 * of its own. One tested renderer and one tested AJAX endpoint then serve all
 * of them, which is why ~120 widgets cost a few hundred lines instead of a
 * few hundred files — and why a fix to formatting, permissions or refresh
 * behaviour lands everywhere at once.
 *
 * A widget declares:
 *   type       stat | chart | table | list | custom
 *   resolver   a WidgetService method that returns the data
 *   link       where clicking navigates, including pre-applied filters
 *   tone       its own colour, so cards stay visually distinct
 */
final class Widget
{
    /** Card sizes, in 12-column grid units. */
    public const SIZE_XS = 2;
    public const SIZE_SM = 3;
    public const SIZE_MD = 4;
    public const SIZE_LG = 6;
    public const SIZE_XL = 12;

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $registry = null;

    /**
     * Every widget, keyed by its stable identifier.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return self::$registry ??= array_merge(
            self::statistics(),
            self::analytics(),
            self::finance(),
            self::users(),
            self::bookings(),
            self::support(),
            self::content(),
            self::security(),
            self::server(),
            self::marketing(),
            self::ai(),
            self::productivity(),
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * Widgets the given permission set may see, grouped for the picker.
     *
     * @param  list<string> $permissions
     * @return array<string,array<string,array<string,mixed>>>
     */
    public static function grouped(array $permissions): array
    {
        $grouped = [];

        foreach (self::all() as $key => $widget) {
            $required = (string) ($widget['permission'] ?? '');

            if ($required !== '' && !in_array($required, $permissions, true)) {
                continue;
            }

            $grouped[(string) $widget['group']][$key] = $widget;
        }

        return $grouped;
    }

    /**
     * The default layout for a role level, used before an admin saves one.
     *
     * @return list<array{key:string,size:int}>
     */
    public static function defaultLayout(int $roleLevel = 50): array
    {
        // Finance-heavy roles lead with money; everyone else with growth.
        $keys = $roleLevel >= 90
            ? ['gmv_30d', 'revenue_30d', 'bookings_30d', 'users_30d',
               'pending_creators', 'pending_withdrawals', 'open_tickets', 'open_disputes',
               'revenue_chart', 'booking_funnel', 'user_growth_chart', 'top_creators',
               'latest_bookings', 'recent_activity', 'system_health', 'quick_actions']
            : ['total_users', 'total_creators', 'bookings_today', 'revenue_today',
               'pending_creators', 'open_tickets', 'pending_kyc', 'active_bookings',
               'revenue_chart', 'booking_trend_chart', 'latest_bookings', 'recent_tickets',
               'quick_actions', 'todo_list'];

        $layout = [];

        foreach ($keys as $key) {
            $widget = self::find($key);

            if ($widget !== null) {
                $layout[] = ['key' => $key, 'size' => (int) $widget['size']];
            }
        }

        return $layout;
    }

    // =================================================================
    // Definition helpers
    // =================================================================

    /**
     * A single-number KPI card.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function stat(
        string $title,
        string $group,
        string $icon,
        string $tone,
        string $permission,
        string $resolver,
        string $link = '',
        string $format = 'number',
        array $extra = []
    ): array {
        return array_merge([
            'title'      => $title,
            'group'      => $group,
            'type'       => 'stat',
            'icon'       => $icon,
            'tone'       => $tone,
            'permission' => $permission,
            'resolver'   => $resolver,
            'link'       => $link,
            'format'     => $format,
            'size'       => self::SIZE_SM,
            'refresh'    => 60,
        ], $extra);
    }

    /** @return array<string,mixed> */
    private static function panel(
        string $title,
        string $group,
        string $type,
        string $icon,
        string $tone,
        string $permission,
        string $resolver,
        int $size = self::SIZE_LG,
        array $extra = []
    ): array {
        return array_merge([
            'title'      => $title,
            'group'      => $group,
            'type'       => $type,
            'icon'       => $icon,
            'tone'       => $tone,
            'permission' => $permission,
            'resolver'   => $resolver,
            'link'       => '',
            'format'     => 'raw',
            'size'       => $size,
            'refresh'    => 300,
        ], $extra);
    }

    // =================================================================
    // Statistics
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function statistics(): array
    {
        $g = 'Statistics';

        return [
            'total_users' => self::stat('Total users', $g, 'users', '#B80049', 'users.view',
                'countUsers', '/admin/users'),

            'total_creators' => self::stat('Total creators', $g, 'star', '#632CE5', 'creators.view',
                'countCreators', '/admin/creators'),

            'active_users' => self::stat('Active users', $g, 'user-check', '#006A34', 'users.view',
                'countActiveUsers', '/admin/users?status=active'),

            'active_creators' => self::stat('Live creators', $g, 'star', '#008644', 'creators.view',
                'countApprovedCreators', '/admin/creators?status=approved'),

            'verified_creators' => self::stat('Verified creators', $g, 'check-circle', '#7C4DFF', 'creators.view',
                'countVerifiedCreators', '/admin/creators?verified_only=1'),

            'pending_creators' => self::stat('Awaiting review', $g, 'alert', '#B45309', 'creators.approve',
                'countPendingCreators', '/admin/creators?status=pending_review', 'number', ['refresh' => 30]),

            // kyc.view, the permission the queue it links to requires.
            'pending_kyc' => self::stat('KYC pending', $g, 'shield', '#E2165F', 'kyc.view',
                'countPendingKyc', '/admin/kyc?status=pending', 'number', ['refresh' => 30]),

            'new_users_today' => self::stat('New today', $g, 'trending-up', '#4F00D0', 'users.view',
                'countUsersToday', '/admin/users?from=' . date('Y-m-d')),

            'total_campaigns' => self::stat('Total campaigns', $g, 'megaphone', '#B80049', 'bookings.view',
                'countCampaigns', '/admin/bookings'),

            'active_campaigns' => self::stat('Active campaigns', $g, 'megaphone', '#006A34', 'bookings.view',
                'countActiveCampaigns', '/admin/bookings?status=in_progress'),

            'completed_campaigns' => self::stat('Completed', $g, 'check-circle', '#008644', 'bookings.view',
                'countCompletedBookings', '/admin/bookings?status=completed'),

            'cancelled_campaigns' => self::stat('Cancelled', $g, 'x', '#BA1A1A', 'bookings.view',
                'countCancelledBookings', '/admin/bookings?status=cancelled'),

            'total_bookings' => self::stat('Total bookings', $g, 'calendar', '#B80049', 'bookings.view',
                'countBookings', '/admin/bookings'),

            'bookings_today' => self::stat("Today's bookings", $g, 'calendar', '#E2165F', 'bookings.view',
                'countBookingsToday', '/admin/bookings?from=' . date('Y-m-d'), 'number', ['refresh' => 30]),

            'bookings_week' => self::stat('This week', $g, 'calendar', '#632CE5', 'bookings.view',
                'countBookingsWeek', '/admin/bookings?from=' . date('Y-m-d', strtotime('monday this week'))),

            'bookings_month' => self::stat('This month', $g, 'calendar', '#7C4DFF', 'bookings.view',
                'countBookingsMonth', '/admin/bookings?from=' . date('Y-m-01')),

            'active_bookings' => self::stat('In progress', $g, 'refresh', '#006A34', 'bookings.view',
                'countActiveBookings', '/admin/bookings?status=in_progress'),

            'open_disputes' => self::stat('Open disputes', $g, 'alert', '#BA1A1A', 'bookings.dispute',
                'countOpenDisputes', '/admin/disputes?status=open', 'number', ['refresh' => 30]),

            'total_reviews' => self::stat('Total reviews', $g, 'star', '#B45309', 'reviews.view',
                'countReviews', '/admin/reviews'),

            'average_rating' => self::stat('Average rating', $g, 'star', '#E2165F', 'reviews.view',
                'averageRating', '/admin/reviews', 'rating'),

            'total_categories' => self::stat('Categories', $g, 'grid', '#632CE5', 'categories.view',
                'countCategories', '/admin/categories'),

            'total_blogs' => self::stat('Blog articles', $g, 'file', '#4F00D0', 'cms.view',
                'countBlogs', '/admin/blogs'),

            'total_notifications' => self::stat('Notifications sent', $g, 'bell', '#7C4DFF', 'notifications.view',
                'countNotifications', '/admin/notifications'),
        ];
    }

    // =================================================================
    // Analytics
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function analytics(): array
    {
        $g = 'Analytics';

        return [
            'revenue_chart' => self::panel('Revenue', $g, 'chart', 'chart', '#B80049', 'analytics.view',
                'chartRevenue', self::SIZE_LG, ['chart' => 'area', 'options' => ['days' => 30]]),

            'booking_trend_chart' => self::panel('Booking trend', $g, 'chart', 'calendar', '#632CE5', 'analytics.view',
                'chartBookings', self::SIZE_LG, ['chart' => 'bar', 'options' => ['days' => 14]]),

            'user_growth_chart' => self::panel('User growth', $g, 'chart', 'users', '#006A34', 'analytics.view',
                'chartUserGrowth', self::SIZE_LG, ['chart' => 'line', 'options' => ['days' => 30]]),

            'creator_growth_chart' => self::panel('Creator growth', $g, 'chart', 'star', '#7C4DFF', 'analytics.view',
                'chartCreatorGrowth', self::SIZE_LG, ['chart' => 'line', 'options' => ['days' => 30]]),

            'booking_funnel' => self::panel('Booking funnel', $g, 'funnel', 'filter', '#E2165F', 'analytics.view',
                'funnelBookings', self::SIZE_MD),

            'top_categories' => self::panel('Top categories', $g, 'bars', 'grid', '#B80049', 'analytics.view',
                'topCategories', self::SIZE_MD),

            'top_creators' => self::panel('Top creators', $g, 'list', 'star', '#632CE5', 'analytics.view',
                'topCreators', self::SIZE_MD),

            'top_brands' => self::panel('Top brands', $g, 'list', 'users', '#4F00D0', 'analytics.view',
                'topBrands', self::SIZE_MD),

            'device_stats' => self::panel('Devices', $g, 'donut', 'card', '#006A34', 'analytics.view',
                'statsByDimension', self::SIZE_SM, ['options' => ['dimension' => 'device']]),

            'platform_stats' => self::panel('Platforms', $g, 'donut', 'card', '#7C4DFF', 'analytics.view',
                'statsByDimension', self::SIZE_SM, ['options' => ['dimension' => 'platform']]),

            'country_stats' => self::panel('Countries', $g, 'bars', 'external', '#B45309', 'analytics.view',
                'statsByDimension', self::SIZE_SM, ['options' => ['dimension' => 'country']]),

            'city_stats' => self::panel('Top cities', $g, 'bars', 'external', '#E2165F', 'analytics.view',
                'topCities', self::SIZE_SM),

            'active_users_chart' => self::panel('Daily active users', $g, 'chart', 'trending-up', '#008644', 'analytics.view',
                'chartActiveUsers', self::SIZE_LG, ['chart' => 'area', 'options' => ['days' => 30]]),

            'retention_rate' => self::stat('30-day retention', $g, 'refresh', '#632CE5', 'analytics.view',
                'retentionRate', '/admin/analytics', 'percent'),
        ];
    }

    // =================================================================
    // Finance
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function finance(): array
    {
        $g = 'Finance';

        return [
            'gmv_30d' => self::stat('Gross volume (30d)', $g, 'trending-up', '#B80049', 'payments.view',
                'gmv30d', '/admin/settlements', 'money', ['size' => self::SIZE_SM]),

            'revenue_30d' => self::stat('Revenue (30d)', $g, 'wallet', '#632CE5', 'payments.view',
                'revenue30d', '/admin/settlements', 'money'),

            'revenue_today' => self::stat("Today's revenue", $g, 'wallet', '#008644', 'payments.view',
                'revenueToday', '/admin/settlements?from=' . date('Y-m-d'), 'money', ['refresh' => 60]),

            'revenue_month' => self::stat('Revenue this month', $g, 'chart', '#7C4DFF', 'payments.view',
                'revenueMonth', '/admin/settlements?from=' . date('Y-m-01'), 'money'),

            'platform_commission' => self::stat('Commission (30d)', $g, 'chart', '#E2165F', 'payments.view',
                'commission30d', '/admin/settlements', 'money'),

            'escrow_held' => self::stat('Held in escrow', $g, 'shield', '#B45309', 'wallet.view',
                'escrowHeld', '/admin/bookings?payment_status=paid', 'money'),

            'wallet_balance' => self::stat('Wallet float', $g, 'wallet', '#4F00D0', 'wallet.view',
                'walletFloat', '/admin/wallets?with_balance=1', 'money'),

            'pending_withdrawals' => self::stat('Payouts pending', $g, 'card', '#BA1A1A', 'wallet.withdrawals',
                'pendingWithdrawals', '/admin/withdrawals?status=pending', 'money', ['refresh' => 30]),

            'completed_withdrawals' => self::stat('Payouts settled', $g, 'check-circle', '#006A34', 'wallet.withdrawals',
                'completedWithdrawals', '/admin/withdrawals?status=completed', 'money'),

            'pending_payments' => self::stat('Payments pending', $g, 'card', '#B45309', 'payments.view',
                'pendingPayments', '/admin/payments?status=created'),

            'failed_payments' => self::stat('Failed payments', $g, 'alert', '#BA1A1A', 'payments.view',
                'failedPayments', '/admin/payments?status=failed', 'number', ['refresh' => 60]),

            'refund_requests' => self::stat('Refunds issued', $g, 'refresh', '#E2165F', 'payments.view',
                'refundTotal', '/admin/refunds', 'money'),

            'gst_summary' => self::stat('Tax collected (30d)', $g, 'file', '#632CE5', 'payments.view',
                'taxCollected', '/admin/settlements', 'money'),

            'total_transactions' => self::stat('Ledger entries', $g, 'database', '#7C4DFF', 'wallet.view',
                'countTransactions', '/admin/wallets'),

            'revenue_summary' => self::panel('Revenue summary', $g, 'summary', 'wallet', '#B80049', 'payments.view',
                'revenueSummary', self::SIZE_MD),

            'payout_overview' => self::panel('Payout overview', $g, 'summary', 'card', '#006A34', 'wallet.withdrawals',
                'payoutOverview', self::SIZE_MD),
        ];
    }

    // =================================================================
    // Users
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function users(): array
    {
        $g = 'Users';

        return [
            'latest_users' => self::panel('Latest users', $g, 'table', 'users', '#B80049', 'users.view',
                'latestUsers', self::SIZE_LG),

            'latest_creators' => self::panel('Latest creators', $g, 'table', 'star', '#632CE5', 'creators.view',
                'latestCreators', self::SIZE_LG),

            'recently_verified' => self::panel('Recently verified', $g, 'list', 'check-circle', '#006A34', 'creators.view',
                'recentlyVerified', self::SIZE_MD),

            'suspended_accounts' => self::panel('Suspended accounts', $g, 'table', 'alert', '#BA1A1A', 'users.view',
                'suspendedAccounts', self::SIZE_MD),

            'online_users' => self::stat('Online now', $g, 'user-check', '#008644', 'users.view',
                'onlineUsers', '/admin/users', 'number', ['refresh' => 30]),

            'device_activity' => self::panel('Device activity', $g, 'donut', 'card', '#7C4DFF', 'users.view',
                'deviceActivity', self::SIZE_SM),
        ];
    }

    // =================================================================
    // Bookings
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function bookings(): array
    {
        $g = 'Bookings';

        return [
            'latest_bookings' => self::panel('Latest bookings', $g, 'table', 'calendar', '#B80049', 'bookings.view',
                'latestBookings', self::SIZE_XL),

            'booking_status' => self::panel('Booking status', $g, 'donut', 'chart', '#632CE5', 'bookings.view',
                'bookingStatusBreakdown', self::SIZE_MD),

            'upcoming_deliveries' => self::panel('Upcoming deliveries', $g, 'table', 'calendar', '#B45309', 'bookings.view',
                'upcomingDeliveries', self::SIZE_MD),

            'pending_approvals' => self::panel('Pending approvals', $g, 'table', 'alert', '#E2165F', 'creators.approve',
                'pendingApprovals', self::SIZE_MD, ['refresh' => 30]),
        ];
    }

    // =================================================================
    // Support
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function support(): array
    {
        $g = 'Support';

        return [
            'open_tickets' => self::stat('Open tickets', $g, 'headset', '#B45309', 'support.view',
                'openTickets', '/admin/tickets?status=open', 'number', ['refresh' => 30]),

            'pending_tickets' => self::stat('Awaiting customer', $g, 'chat', '#632CE5', 'support.view',
                'pendingTickets', '/admin/tickets?status=pending_user'),

            'closed_tickets' => self::stat('Resolved tickets', $g, 'check-circle', '#006A34', 'support.view',
                'closedTickets', '/admin/tickets?status=resolved'),

            'recent_tickets' => self::panel('Recent tickets', $g, 'table', 'headset', '#B80049', 'support.view',
                'recentTickets', self::SIZE_LG, ['refresh' => 60]),

            'ticket_priority' => self::panel('By priority', $g, 'bars', 'filter', '#E2165F', 'support.view',
                'ticketsByPriority', self::SIZE_SM),

            'open_reports' => self::stat('Abuse reports', $g, 'flag', '#BA1A1A', 'reports.view',
                'openReports', '/admin/reports?status=open', 'number', ['refresh' => 30]),

            'contact_inbox' => self::stat('New enquiries', $g, 'inbox', '#7C4DFF', 'cms.view',
                'newContactMessages', '/admin/contact?status=new'),
        ];
    }

    // =================================================================
    // Content
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function content(): array
    {
        $g = 'Content';

        return [
            'recent_blogs' => self::panel('Recent articles', $g, 'table', 'file', '#632CE5', 'cms.view',
                'recentBlogs', self::SIZE_MD),

            'banner_performance' => self::panel('Banner performance', $g, 'table', 'image', '#B80049', 'cms.view',
                'bannerPerformance', self::SIZE_MD),

            'faq_stats' => self::panel('FAQ helpfulness', $g, 'table', 'info', '#4F00D0', 'cms.view',
                'faqStats', self::SIZE_MD),

            'media_storage' => self::panel('Media storage', $g, 'gauge', 'image', '#7C4DFF', 'cms.media',
                'mediaStorage', self::SIZE_SM),
        ];
    }

    // =================================================================
    // Security
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function security(): array
    {
        $g = 'Security';

        return [
            'failed_logins' => self::stat('Failed logins (24h)', $g, 'shield', '#BA1A1A', 'logs.activity',
                'failedLogins24h', '/admin/security/login-history?outcome=bad_credentials', 'number', ['refresh' => 60]),

            'active_sessions' => self::stat('Admin sessions', $g, 'user-check', '#006A34', 'logs.activity',
                'activeAdminSessions', '/admin/security/sessions', 'number', ['refresh' => 60]),

            'login_history' => self::panel('Login history', $g, 'table', 'key', '#632CE5', 'logs.activity',
                'recentLogins', self::SIZE_LG, ['refresh' => 60]),

            'recent_activity' => self::panel('Recent activity', $g, 'table', 'shield', '#B80049', 'logs.activity',
                'recentActivity', self::SIZE_LG, ['refresh' => 60]),

            'security_alerts' => self::panel('Security alerts', $g, 'list', 'alert', '#BA1A1A', 'logs.system',
                'securityAlerts', self::SIZE_MD, ['refresh' => 60]),

            'api_requests' => self::stat('API calls (24h)', $g, 'database', '#7C4DFF', 'logs.system',
                'apiRequests24h', '/admin/developer/api-logs'),

            'api_errors' => self::stat('API errors (24h)', $g, 'alert', '#B45309', 'logs.system',
                'apiErrors24h', '/admin/developer/api-logs?status=error', 'number', ['refresh' => 60]),

            'error_logs' => self::panel('Error log', $g, 'table', 'alert', '#BA1A1A', 'logs.system',
                'recentErrors', self::SIZE_LG, ['refresh' => 60]),
        ];
    }

    // =================================================================
    // Server
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function server(): array
    {
        $g = 'Server';

        return [
            'system_health' => self::panel('System health', $g, 'health', 'database', '#006A34', 'logs.system',
                'systemHealth', self::SIZE_MD, ['refresh' => 120]),

            'disk_usage' => self::panel('Disk usage', $g, 'gauge', 'database', '#B45309', 'logs.system',
                'diskUsage', self::SIZE_SM, ['refresh' => 300]),

            'database_size' => self::stat('Database size', $g, 'database', '#632CE5', 'logs.system',
                'databaseSize', '/admin/system?tab=database', 'bytes'),

            'memory_usage' => self::panel('Memory', $g, 'gauge', 'chart', '#7C4DFF', 'logs.system',
                'memoryUsage', self::SIZE_SM, ['refresh' => 60]),

            'backup_status' => self::panel('Backups', $g, 'summary', 'database', '#008644', 'system.backup',
                'backupStatus', self::SIZE_SM, ['refresh' => 300]),

            'cron_status' => self::panel('Scheduled jobs', $g, 'table', 'refresh', '#B80049', 'logs.system',
                'cronStatus', self::SIZE_MD, ['refresh' => 60]),

            'queue_status' => self::panel('Delivery queues', $g, 'summary', 'inbox', '#E2165F', 'logs.system',
                'queueStatus', self::SIZE_SM, ['refresh' => 30]),

            'cache_status' => self::panel('Cache', $g, 'summary', 'database', '#4F00D0', 'logs.system',
                'cacheStatus', self::SIZE_SM, ['refresh' => 120]),
        ];
    }

    // =================================================================
    // Marketing
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function marketing(): array
    {
        $g = 'Marketing';

        return [
            'newsletter_subscribers' => self::stat('Subscribers', $g, 'inbox', '#632CE5', 'marketing.view',
                'newsletterSubscribers', '/admin/marketing/subscribers'),

            'coupon_usage' => self::panel('Coupon usage', $g, 'table', 'card', '#B80049', 'marketing.view',
                'couponUsage', self::SIZE_MD),

            'referral_stats' => self::panel('Referrals', $g, 'summary', 'users', '#008644', 'analytics.view',
                'referralStats', self::SIZE_SM),

            'campaign_performance' => self::panel('Campaign performance', $g, 'table', 'megaphone', '#E2165F', 'marketing.view',
                'campaignPerformance', self::SIZE_LG),

            'popup_performance' => self::panel('Popup performance', $g, 'table', 'megaphone', '#7C4DFF', 'marketing.view',
                'popupPerformance', self::SIZE_MD),
        ];
    }

    // =================================================================
    // AI
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function ai(): array
    {
        $g = 'AI';

        return [
            'ai_requests' => self::stat('AI requests (30d)', $g, 'star', '#632CE5', 'ai.view',
                'aiRequests', '/admin/ai/logs'),

            'ai_tokens' => self::stat('Tokens used (30d)', $g, 'chart', '#7C4DFF', 'ai.view',
                'aiTokens', '/admin/ai/logs', 'compact'),

            // ai_logs.cost is USD, never converted. The widget runtime formats
            // 'money' with the single platform currency symbol, so the unit
            // lives in the title instead of being mislabelled in the value.
            'ai_cost' => self::stat('AI spend, USD (30d)', $g, 'wallet', '#B80049', 'ai.view',
                'aiCost', '/admin/ai/logs', 'number'),

            'ai_errors' => self::stat('AI failures (30d)', $g, 'alert', '#BA1A1A', 'ai.view',
                'aiErrors', '/admin/ai/logs?status=failed'),

            'ai_activity' => self::panel('AI activity', $g, 'table', 'star', '#4F00D0', 'ai.view',
                'aiActivity', self::SIZE_LG),
        ];
    }

    // =================================================================
    // Productivity
    // =================================================================

    /** @return array<string,array<string,mixed>> */
    private static function productivity(): array
    {
        $g = 'Productivity';

        return [
            'quick_actions' => self::panel('Quick actions', $g, 'actions', 'plus', '#B80049', 'dashboard.view',
                'quickActions', self::SIZE_MD, ['refresh' => 0]),

            'todo_list' => self::panel('My tasks', $g, 'todo', 'check', '#632CE5', 'dashboard.view',
                'todoList', self::SIZE_MD, ['refresh' => 0]),

            'sticky_notes' => self::panel('Notes', $g, 'notes', 'file', '#B45309', 'dashboard.view',
                'stickyNotes', self::SIZE_MD, ['refresh' => 0]),

            'announcements' => self::panel('Announcements', $g, 'list', 'megaphone', '#E2165F', 'dashboard.view',
                'announcements', self::SIZE_MD, ['refresh' => 300]),

            'recent_logins' => self::panel('Your recent sign-ins', $g, 'table', 'key', '#7C4DFF', 'dashboard.view',
                'myRecentLogins', self::SIZE_MD),
        ];
    }
}

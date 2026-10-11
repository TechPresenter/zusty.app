<?php

declare(strict_types=1);

/**
 * The demo dataset (database/seeds/demo, loaded by install.php --with-demo)
 * is what the Google Play screenshots are taken from, so it has to be
 * unmistakably fictional, unable to reach a real person, worded the way the
 * app is worded, and current.
 *
 *  - Accounts sign in on example.com, phones follow +91 90000 0xxxx, creator
 *    handles read <name>.sample.<niche>, brand GSTINs cannot be real ones,
 *    IP addresses come from the documentation ranges, and nothing waiting to
 *    be mailed (demo or not) points at a real mail domain.
 *  - User-visible demo copy never says "escrow", "nodal", "trust account",
 *    "guaranteed" or "100% safe" (decision P-ESCROW): the payment is "held by
 *    Zusty". Internal names (escrow_status, the escrow_hold category) are not
 *    copy and are not checked. Nor does it use the old app names or domain.
 *  - The screenshot records hold together: booking 452 (Velvetfern Skincare
 *    and Aanya Kapoor) is priced consistently with every deliverable in for
 *    review and its notifications dated with it, its chat is on the brief,
 *    open briefs exist in Food, Lifestyle and Fashion and end in the future,
 *    packages offer two revision rounds, no live popup covers creator
 *    screens, and the screenshot accounts' demo notifications are read and
 *    point at their own records.
 *
 * Read-only. Skipped when the demo dataset is not loaded.
 */

use Zuste\Core\Database;
use Zuste\Helpers\KycIdentifiers;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

$demoLoaded = (int) (Database::scalar(
    "SELECT COUNT(*) FROM `users` WHERE `id` BETWEEN 1001 AND 1020 AND `account_type` = 'brand'"
) ?? 0) === 20;

if (!$demoLoaded) {
    Suite::skipGroup('Demo data', 'the demo dataset is not loaded (install.php --with-demo)');

    return;
}

/** True when an address is on a domain reserved for documentation or testing. */
$ddReserved = static function (?string $address): bool {
    if ($address === null || $address === '' || !str_contains($address, '@')) {
        return true;
    }

    $domain = strtolower(substr($address, strrpos($address, '@') + 1));

    return in_array($domain, ['example.com', 'example.net', 'example.org'], true)
        || preg_match('/(^|\.)(test|example|invalid|localhost|local)$/', $domain) === 1;
};

/** Rows whose text matches a pattern: [source => [ids]]. */
$ddMatching = static function (array $sources, string $pattern): array {
    $hits = [];

    foreach ($sources as $what => $sql) {
        foreach (Database::select($sql) as $row) {
            if (preg_match($pattern, (string) $row['t']) === 1) {
                $hits[$what][] = (int) $row['id'];
            }
        }
    }

    return $hits;
};

// ---------------------------------------------------------------------
Suite::group('Demo data — fictional people and businesses');
// ---------------------------------------------------------------------

$ddUsers = Database::select(
    'SELECT `id`, `email`, `phone`, `notification_prefs`, `auth_providers`, `company_name`, `gst_number` FROM `users`
      WHERE `id` BETWEEN 1001 AND 1020 OR `id` BETWEEN 2001 AND 2045'
);

Suite::equals('all 65 demo accounts are present', count($ddUsers), 65);

Suite::equals(
    'every demo account signs in on example.com',
    array_values(array_filter($ddUsers, static fn (array $u): bool => !str_ends_with((string) $u['email'], '@example.com'))),
    []
);

Suite::equals(
    'every demo phone is +91 90000 0 followed by the user id',
    array_values(array_filter(
        $ddUsers,
        static fn (array $u): bool => (string) $u['phone'] !== '90000' . str_pad((string) $u['id'], 5, '0', STR_PAD_LEFT)
    )),
    []
);

Suite::equals(
    'no demo account has SMS notifications or phone sign-in',
    array_values(array_filter(
        $ddUsers,
        static fn (array $u): bool => preg_match('/"sms"\s*:\s*true/', (string) $u['notification_prefs']) === 1
            || str_contains((string) $u['auth_providers'], '"phone"')
    )),
    []
);

$ddGstins = array_values(array_filter($ddUsers, static fn (array $u): bool => (int) $u['id'] <= 1020));

Suite::equals(
    'every demo brand GSTIN carries the brand initial and an invalid check character',
    array_values(array_map(
        static fn (array $u): string => $u['id'] . ':' . $u['gst_number'],
        array_filter(
            $ddGstins,
            static fn (array $u): bool => strlen((string) $u['gst_number']) !== 15
                || ((string) $u['gst_number'])[6] !== strtoupper(((string) $u['company_name'])[0])
                || KycIdentifiers::gstinChecksumValid((string) $u['gst_number'])
        )
    )),
    []
);

Suite::equals(
    'demo invoices print only those GSTINs',
    array_column(Database::select(
        "SELECT i.`id` FROM `invoices` i
           JOIN `users` u ON u.`id` = i.`user_id`
          WHERE i.`booking_id` BETWEEN 401 AND 480 AND i.`bill_to_gst` IS NOT NULL
            AND u.`id` BETWEEN 1001 AND 1020 AND NOT (i.`bill_to_gst` <=> u.`gst_number`)"
    ), 'id'),
    []
);

$ddHandles = Database::select('SELECT `id`, `handle` FROM `creators` WHERE `id` BETWEEN 101 AND 145');

Suite::equals(
    'every demo creator handle reads <name>.sample.<niche>',
    array_values(array_filter($ddHandles, static fn (array $c): bool => preg_match('/^[a-z]+\.sample\.[a-z]+$/', (string) $c['handle']) !== 1)),
    []
);

Suite::equals(
    'every demo social account uses its creator\'s sample handle',
    Database::select(
        'SELECT s.`id`, s.`handle`, s.`profile_url` FROM `creator_social_accounts` s
           JOIN `creators` c ON c.`id` = s.`creator_id`
          WHERE s.`id` BETWEEN 3001 AND 3118
            AND (s.`handle` <> c.`handle` OR s.`profile_url` NOT LIKE CONCAT(\'%\', c.`handle`, \'%\'))'
    ),
    []
);

$ddAddresses = array_merge(
    array_column(Database::select('SELECT `destination` AS a FROM `campaign_recipients` WHERE `campaign_id` BETWEEN 301 AND 330'), 'a'),
    array_column(Database::select('SELECT `email` AS a FROM `contact_messages` WHERE `id` BETWEEN 1801 AND 1818'), 'a'),
    array_column(Database::select('SELECT `email` AS a FROM `newsletter_subscribers` WHERE `id` BETWEEN 1851 AND 1890'), 'a')
);

Suite::equals(
    'nothing in the demo campaigns, contact list or newsletter can be mailed to a real domain',
    array_values(array_unique(array_filter($ddAddresses, static fn (?string $a): bool => !$ddReserved($a)))),
    []
);

// Not only demo rows: a local run must never be able to mail a third party.
Suite::equals(
    'no email waiting in the queue is addressed to a real domain',
    array_values(array_unique(array_filter(
        array_column(Database::select("SELECT `to_email` AS a FROM `email_queue` WHERE `status` <> 'sent'"), 'a'),
        static fn (?string $a): bool => !$ddReserved($a)
    ))),
    []
);

Suite::equals(
    'no pending campaign recipient is addressed to a real domain',
    array_values(array_unique(array_filter(
        array_column(Database::select("SELECT `destination` AS a FROM `campaign_recipients` WHERE `status` = 'pending'"), 'a'),
        static fn (?string $a): bool => !$ddReserved($a)
    ))),
    []
);

Suite::equals(
    'no demo UPI ID sits on a real payment app handle',
    Database::select(
        "SELECT 'payment' AS t, `id`, `upi_vpa` AS v FROM `payments` WHERE `id` BETWEEN 501 AND 580 AND `upi_vpa` REGEXP '@(ok[a-z]+|ybl|paytm|upi|axl|ibl|apl)$'
         UNION ALL
         SELECT 'bank_account', `id`, `upi_id` FROM `bank_accounts` WHERE `id` BETWEEN 1971 AND 1990 AND `upi_id` REGEXP '@(ok[a-z]+|ybl|paytm|upi|axl|ibl|apl)$'"
    ),
    []
);

Suite::equals(
    'demo IP addresses come from the documentation ranges (RFC 5737)',
    Database::select(
        "SELECT 'contact' AS t, `id`, `ip_address` AS ip FROM `contact_messages` WHERE `id` BETWEEN 1801 AND 1818
            AND `ip_address` IS NOT NULL AND `ip_address` NOT REGEXP '^(192\\.0\\.2|198\\.51\\.100|203\\.0\\.113)\\.'
         UNION ALL
         SELECT 'payment', `id`, `ip_address` FROM `payments` WHERE `id` BETWEEN 501 AND 580
            AND `ip_address` IS NOT NULL AND `ip_address` NOT REGEXP '^(192\\.0\\.2|198\\.51\\.100|203\\.0\\.113)\\.'"
    ),
    []
);

$ddProfile = Http::get('/creators/aanya.sample.life');
Suite::ok('the API serves a demo creator by her sample handle', $ddProfile);
Suite::equals('…and the profile carries that handle', $ddProfile->at('data.creator.handle'), 'aanya.sample.life');

// ---------------------------------------------------------------------
Suite::group('Demo data — payment protection wording');
// ---------------------------------------------------------------------

$ddCopy = [
    'booking timeline notes'  => "SELECT `id` FROM `booking_status_history` WHERE `booking_id` BETWEEN 401 AND 480 AND `note` LIKE '%escrow%'",
    'chat messages'           => "SELECT `id` FROM `messages` WHERE `id` BETWEEN 901 AND 1300 AND `body` LIKE '%escrow%'",
    'notifications'           => "SELECT `id` FROM `notifications` WHERE `id` BETWEEN 2101 AND 2300 AND CONCAT_WS(' ', `title`, `body`) LIKE '%escrow%'",
    'wallet ledger lines'     => "SELECT `id` FROM `wallet_transactions` WHERE `id` BETWEEN 1701 AND 1950 AND `description` LIKE '%escrow%'",
    'reviews'                 => "SELECT `id` FROM `reviews` WHERE `id` BETWEEN 701 AND 760 AND `comment` LIKE '%escrow%'",
    'support replies'         => "SELECT `id` FROM `support_ticket_messages` WHERE `ticket_id` BETWEEN 1601 AND 1620 AND `message` LIKE '%escrow%'",
    'support ticket subjects' => "SELECT `id` FROM `support_tickets` WHERE `id` BETWEEN 1601 AND 1620 AND `subject` LIKE '%escrow%'",
    'blog posts'              => "SELECT `id` FROM `blogs` WHERE `id` BETWEEN 1301 AND 1330 AND CONCAT_WS(' ', `title`, `slug`, `excerpt`, `content`, `meta_title`, `meta_description`) LIKE '%escrow%'",
    'broadcasts'              => "SELECT `id` FROM `notification_broadcasts` WHERE `id` BETWEEN 1 AND 30 AND CONCAT_WS(' ', `title`, `body`) LIKE '%escrow%'",
    'marketing messages'      => "SELECT `id` FROM `marketing_campaigns` WHERE `id` BETWEEN 301 AND 499 AND CONCAT_WS(' ', `subject`, `preheader`, `content`) LIKE '%escrow%'",
];

foreach ($ddCopy as $what => $sql) {
    Suite::equals("no demo {$what} say \"escrow\"", array_column(Database::select($sql), 'id'), []);
}

// Every user-visible demo text, for the word lists below.
$ddText = [
    'briefs'             => "SELECT `id`, CONCAT_WS(' ', `title`, `description`, `brief_url`, `reference_urls`) AS t FROM `campaigns` WHERE `id` BETWEEN 301 AND 330",
    'chat messages'      => 'SELECT `id`, `body` AS t FROM `messages` WHERE `id` BETWEEN 901 AND 1300',
    'chat previews'      => 'SELECT `id`, `last_message_text` AS t FROM `conversations` WHERE `id` BETWEEN 801 AND 831',
    'notifications'      => "SELECT `id`, CONCAT_WS(' ', `title`, `body`) AS t FROM `notifications` WHERE `id` BETWEEN 2101 AND 2300",
    'broadcasts'         => "SELECT `id`, CONCAT_WS(' ', `title`, `body`) AS t FROM `notification_broadcasts` WHERE `id` BETWEEN 1 AND 30",
    'marketing messages' => "SELECT `id`, CONCAT_WS(' ', `name`, `subject`, `preheader`, `content`) AS t FROM `marketing_campaigns` WHERE `id` BETWEEN 301 AND 499",
    'blog posts'         => "SELECT `id`, CONCAT_WS(' ', `title`, `slug`, `excerpt`, `content`, `meta_title`, `meta_description`, `meta_keywords`) AS t FROM `blogs` WHERE `id` BETWEEN 1301 AND 1330",
    'support tickets'    => "SELECT `id`, CONCAT_WS(' ', `subject`, `satisfaction_comment`) AS t FROM `support_tickets` WHERE `id` BETWEEN 1601 AND 1620",
    'support replies'    => 'SELECT `id`, `message` AS t FROM `support_ticket_messages` WHERE `ticket_id` BETWEEN 1601 AND 1620',
    'contact messages'   => "SELECT `id`, CONCAT_WS(' ', `subject`, `message`, `reply_body`) AS t FROM `contact_messages` WHERE `id` BETWEEN 1801 AND 1818",
    'popups'             => "SELECT `id`, CONCAT_WS(' ', `title`, `content`, `cta_label`) AS t FROM `popups` WHERE `id` BETWEEN 1516 AND 1520",
    'reviews'            => 'SELECT `id`, `comment` AS t FROM `reviews` WHERE `id` BETWEEN 701 AND 760',
];

Suite::equals(
    'no demo copy says nodal, trust account, guaranteed or 100% safe',
    $ddMatching($ddText, '/\bnodal\b|trust account|guarantee|100\s*%\s*safe/i'),
    []
);

Suite::equals(
    'no demo copy uses the old app names or the old domain',
    $ddMatching($ddText, '/Zuste\+|Zustee|\bZuste\b|zustee\.com|-zuste(-|\b)/'),
    []
);

Suite::equals(
    'no demo copy promises milestone payments or instant payouts',
    $ddMatching($ddText, '/milestone|instant payout|within minutes/i'),
    []
);

Suite::equals(
    'no demo marketing message went out by SMS',
    array_column(Database::select("SELECT `id` FROM `marketing_campaigns` WHERE `id` BETWEEN 301 AND 499 AND `channel` = 'sms'"), 'id'),
    []
);

// ---------------------------------------------------------------------
Suite::group('Demo data — store screenshot records');
// ---------------------------------------------------------------------

$ddBooking = Database::selectOne('SELECT * FROM `bookings` WHERE `id` = 452');

Suite::check(
    'booking 452 is Velvetfern Skincare (1001) booking Aanya Kapoor (2001)',
    $ddBooking !== null && (int) $ddBooking['brand_user_id'] === 1001 && (int) $ddBooking['creator_user_id'] === 2001,
    json_encode($ddBooking === null ? null : [$ddBooking['brand_user_id'], $ddBooking['creator_user_id']])
);

if ($ddBooking !== null) {
    $ddCents = static fn (mixed $v): int => (int) round((float) $v * 100);

    Suite::check(
        'its price adds up: fee + platform fee + GST = total, fee - commission = earning',
        $ddCents($ddBooking['amount']) + $ddCents($ddBooking['platform_fee']) + $ddCents($ddBooking['tax_amount']) === $ddCents($ddBooking['total_amount'])
            && $ddCents($ddBooking['amount']) - $ddCents($ddBooking['commission_amount']) === $ddCents($ddBooking['creator_earning']),
        json_encode([$ddBooking['amount'], $ddBooking['platform_fee'], $ddBooking['tax_amount'], $ddBooking['total_amount'], $ddBooking['creator_earning']])
    );

    Suite::check(
        'its total fits a phone screen (under Rs 1,00,000)',
        (float) $ddBooking['total_amount'] > 0 && (float) $ddBooking['total_amount'] < 100000,
        (string) $ddBooking['total_amount']
    );

    Suite::equals(
        'no coupon redemption claims a discount on it',
        array_column(Database::select('SELECT `id` FROM `coupon_redemptions` WHERE `booking_id` = 452'), 'id'),
        []
    );

    // Approving it from the app (the screenshot's own button) moves it on;
    // that is fine, so only a delivered 452 has to be awaiting review.
    if ((string) $ddBooking['status'] === 'delivered') {
        Suite::equals(
            'every deliverable on the delivered screenshot booking is in for review',
            array_column(Database::select("SELECT `status` FROM `booking_deliverables` WHERE `booking_id` = 452 ORDER BY `id`"), 'status'),
            ['submitted', 'submitted', 'submitted']
        );
    }

    Suite::equals(
        'its delivery notifications are dated after the booking was requested',
        array_column(Database::select(
            'SELECT n.`id` FROM `notifications` n JOIN `bookings` b ON b.`id` = 452
              WHERE n.`id` IN (2102, 2103) AND n.`created_at` < b.`requested_at`'
        ), 'id'),
        []
    );
}

$ddThread = Database::select(
    'SELECT m.`body` FROM `messages` m JOIN `conversations` c ON c.`id` = m.`conversation_id`
      WHERE c.`booking_id` = 452 AND m.`deleted_at` IS NULL'
);

Suite::check('booking 452 has its chat', count($ddThread) >= 5, count($ddThread) . ' messages');
Suite::equals(
    'that chat asks for no review or rating and promises no cover',
    array_values(array_filter(
        array_column($ddThread, 'body'),
        static fn (?string $b): bool => preg_match('/google|review us|rate us|\bstars?\b|rating|covered|protected|guarantee/i', (string) $b) === 1
    )),
    []
);

$ddOpen = Database::select(
    "SELECT cat.`name` AS category, c.`ends_on` FROM `campaigns` c
       JOIN `categories` cat ON cat.`id` = c.`category_id`
      WHERE c.`id` BETWEEN 301 AND 330 AND c.`status` = 'open' AND c.`is_public` = 1 AND c.`deleted_at` IS NULL"
);

foreach (['Food', 'Lifestyle', 'Fashion'] as $ddCategory) {
    Suite::check(
        "an open {$ddCategory} brief ends in the future",
        array_filter($ddOpen, static fn (array $c): bool => $c['category'] === $ddCategory && (string) $c['ends_on'] >= date('Y-m-d')) !== [],
        json_encode($ddOpen)
    );
}

Suite::equals(
    'every demo package offers at least two revision rounds',
    array_column(Database::select('SELECT `id` FROM `creator_packages` WHERE `id` BETWEEN 1101 AND 1300 AND `revisions` < 2'), 'id'),
    []
);

Suite::equals(
    'no live demo popup reaches creators or everyone (screenshots show creator screens)',
    array_column(Database::select(
        "SELECT `id` FROM `popups`
          WHERE `id` BETWEEN 1516 AND 1520 AND `is_active` = 1 AND `audience` IN ('creators', 'all')
            AND (`starts_at` IS NULL OR `starts_at` <= NOW())
            AND (`ends_at` IS NULL OR `ends_at` > NOW())"
    ), 'id'),
    []
);

Suite::equals(
    'the screenshot accounts have no unread demo notification',
    array_column(Database::select(
        'SELECT `id` FROM `notifications` WHERE `id` BETWEEN 2101 AND 2300 AND `user_id` IN (1001, 2001, 2012) AND `is_read` = 0'
    ), 'id'),
    []
);

Suite::equals(
    'every demo notification of the screenshot accounts opens one of their own records',
    array_column(Database::select(
        "SELECT n.`id` FROM `notifications` n
          WHERE n.`id` BETWEEN 2101 AND 2300 AND n.`user_id` IN (1001, 2001, 2012)
            AND (
                 (n.`action_type` = 'booking' AND NOT EXISTS (
                     SELECT 1 FROM `bookings` b WHERE b.`id` = n.`action_value` AND n.`user_id` IN (b.`brand_user_id`, b.`creator_user_id`)))
              OR (n.`action_type` = 'chat' AND NOT EXISTS (
                     SELECT 1 FROM `conversation_participants` p WHERE p.`conversation_id` = n.`action_value` AND p.`user_id` = n.`user_id`))
              OR (n.`action_type` = 'ticket' AND NOT EXISTS (
                     SELECT 1 FROM `support_tickets` t WHERE t.`id` = n.`action_value` AND t.`user_id` = n.`user_id`))
            )"
    ), 'id'),
    []
);

Suite::check(
    'the c_kyc creator (Preeti, 2012) has not started verification and has no tick',
    (static function (): bool {
        $row = Database::selectOne(
            'SELECT u.`kyc_status`, c.`is_verified` FROM `users` u JOIN `creators` c ON c.`user_id` = u.`id` WHERE u.`id` = 2012'
        );

        return $row !== null && in_array((string) $row['kyc_status'], ['none', 'draft', ''], true) && (int) $row['is_verified'] === 0;
    })()
);

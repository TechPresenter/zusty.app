<?php

declare(strict_types=1);

/**
 * Public web pages served beside the API (Web\PageController).
 *
 * The app's sign-up screen links to app.url/terms and app.url/privacy (from
 * `/app/config`), and the Google Play listing needs a public privacy policy and
 * an account-deletion page. These are HTML documents for a browser, not API
 * calls, so the suite checks what a browser needs: a 200 text/html page that
 * carries the CMS content, a CSP that lets the page style itself and show its
 * logo but run no script, and a real 404 for anything that is not a
 * published page.
 *
 * It also checks that the published copy says what the app does (migration
 * 053): the privacy policy covers what the Play Data safety form declares,
 * the terms quote the auto-complete window and per-booking Cashfree payment,
 * nothing offers a wallet top-up, a text message or a phone number, and no
 * banner or onboarding slide makes a promise the app cannot keep. Since
 * migration 059 nothing a user reads calls the held payment "escrow", the
 * privacy policy names the Grievance Officer and says what the code keeps
 * of a payment, and the store and invite links use the current names.
 * Since migration 060 no copy says "safely", the app's banners, inline
 * popups and onboarding slides fit where the app cuts them off, no Home
 * section claims a hand-picked list, the privacy policy names the wallet
 * corrections our team makes, and no stored notification keeps the old
 * escrow sentences.
 *
 * Fixtures: the "unpublished page" check switches one seeded page off and
 * restores its original state in a `finally`.
 */

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Services\AccountDeletionService;
use Zuste\Services\PaymentService;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// The pages live at the site root, beside /api/v1.
$origin = preg_replace('#/api/v\d+$#', '', Http::baseUrl()) ?? Http::baseUrl();
$e      = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ---------------------------------------------------------------------
Suite::group('Public pages — the legal pages the app links to');
// ---------------------------------------------------------------------

foreach (['terms', 'privacy'] as $slug) {
    $res   = Http::get($origin . '/' . $slug);
    $title = (string) Database::scalar('SELECT `title` FROM `pages` WHERE `slug` = :slug', ['slug' => $slug]);

    Suite::status("/{$slug} is a public page", $res, 200);
    Suite::check("/{$slug} is served as HTML", str_starts_with((string) $res->header('content-type'), 'text/html'));
    Suite::check("/{$slug} shows its CMS page", $title !== '' && str_contains($res->body, $e($title)), $title);
}

$config = Http::get('/app/config')->json()['data'] ?? [];
$legal  = $config['legal'] ?? [];
Suite::check(
    '/app/config points the app at these pages',
    str_ends_with((string) ($legal['terms_url'] ?? ''), '/terms') && str_ends_with((string) ($legal['privacy_url'] ?? ''), '/privacy'),
    json_encode($legal) ?: ''
);

$csp = (string) Http::get($origin . '/privacy')->header('content-security-policy');
Suite::check('the page may style itself and show its logo', str_contains($csp, "style-src 'unsafe-inline'") && str_contains($csp, "img-src 'self'"), $csp);
Suite::check('the page runs no script of its own', !preg_match("/script-src[^;]*'(unsafe-inline|self)'/", $csp), $csp);

// ---------------------------------------------------------------------
Suite::group('Public pages — account deletion');
// ---------------------------------------------------------------------

$res = Http::get($origin . '/account-deletion');
Suite::status('/account-deletion is a public page', $res, 200);
Suite::check('it gives the in-app path', str_contains($res->body, 'Privacy &amp; data → Delete my account'));

$policy  = AccountDeletionService::policy();
$missing = array_values(array_filter(
    array_merge($policy['erased'], $policy['retained']),
    static fn (array $item): bool => !str_contains($res->body, $e($item['title']))
));
Suite::check('it lists everything the app says is deleted or kept', $missing === [], implode(', ', array_column($missing, 'title')));

$support = trim(Config::settingString('general', 'support_email', ''));
Suite::check(
    'it offers a way to ask without the app',
    $support !== '' ? str_contains($res->body, 'mailto:' . $e($support)) : str_contains($res->body, 'href="/contact"')
);
Suite::check('it mentions no text messages', !str_contains($res->body, 'texts and push'));
// Case-sensitive: the page's font stack names "-apple-system".
Suite::check('it mentions no Apple sign-in (switched off)', !str_contains($res->body, 'Apple'));

// ---------------------------------------------------------------------
Suite::group('Public pages — the policies say what the app does');
// ---------------------------------------------------------------------

// Google Play compares the privacy policy with the Data safety form, so each
// phrase below stands for a declaration the form makes (migration 053). Read
// from the published CMS content, not the page chrome, which prints the
// support address in its footer anyway.
$pageText = static fn (string $slug): string => html_entity_decode(
    strip_tags((string) Http::get('/pages/' . $slug)->at('data.page.content')),
    ENT_QUOTES | ENT_HTML5,
    'UTF-8'
);

$privacy = $pageText('privacy');
foreach ([
    'the payment provider'        => 'Cashfree',
    'Firebase'                    => 'Firebase',
    'the crash reporter'          => 'Crashlytics',
    'approximate location'        => 'approximate location',
    'the push token'              => 'push token',
    'voice search'                => 'voice search',
    'the masked Aadhaar rule'     => 'last four digits',
    'the hosting provider'        => 'Hostinger',
    'the support mailbox'         => 'support@zusty.app',
    'the operator'                => 'Appsgain Technologies Private Limited',
] as $what => $phrase) {
    Suite::check("the privacy policy names {$what}", stripos($privacy, $phrase) !== false, "missing '{$phrase}'");
}
Suite::check('…says marketing is opt-in', preg_match('/\bopt[- ]in\b/i', $privacy) === 1);
Suite::check('…says nothing is sold or shared for ads', stripos($privacy, 'do not sell') !== false && stripos($privacy, 'no ads') !== false);
Suite::check('…sends codes to the email only', $privacy !== '' && stripos($privacy, 'sent to your email or phone') === false);
Suite::check('…is for adults only', stripos($privacy, 'aged 18 and over') !== false);
// 053's phone-item sweep needs a number, so the policy's own disclosure of
// the optional phone number (no number in it) survives.
Suite::check('…still discloses the optional phone number', stripos($privacy, 'A phone number is optional') !== false);

// Migration 055 keeps the "marketing": true that sign-up used to store for
// every account. While any such account exists, the policy must say so.
$legacyOptIns = (int) Database::scalar(
    "SELECT COUNT(*) FROM `users`
      WHERE `deleted_at` IS NULL AND `terms_accepted_at` IS NULL
        AND JSON_VALID(`notification_prefs`) = 1
        AND JSON_UNQUOTE(JSON_EXTRACT(`notification_prefs`, '$.marketing')) IN ('true', '1')"
);
Suite::check(
    '…says older accounts had product updates on by default',
    $legacyOptIns === 0 || stripos($privacy, 'switched on by default') !== false,
    "{$legacyOptIns} account(s) hold marketing on from before the sign-up opt-in"
);

$reviewDays = max(1, Config::settingInt('booking', 'auto_complete_days', 7));
$window     = $reviewDays === 1 ? '1 day' : $reviewDays . ' days';
$terms      = $pageText('terms');
Suite::check('the terms say bookings are paid through Cashfree', str_contains($terms, 'Cashfree'));
Suite::check(
    '…and quote the review window the auto-complete job applies',
    str_contains($terms, 'within ' . $window . ' of delivery'),
    "booking.auto_complete_days = {$reviewDays}"
);
Suite::check('…and say nobody pays money into the wallet', str_contains($terms, 'You cannot pay money into the wallet'));
Suite::check('the refund policy sends refunds to the original payment method', str_contains($pageText('refund-policy'), 'original payment method by default'));

$guidelines = $pageText('community-guidelines');
Suite::check('the community guidelines explain blocking', stripos($guidelines, 'block someone') !== false);
Suite::check('…and how moderation works', str_contains($guidelines, 'How moderation works'));
// There is no response rate; the completion rate (CreatorService) is the
// figure a creator's profile shows and cancellations count against.
Suite::check(
    '…and name only figures the profile shows',
    stripos($guidelines, 'response rate') === false && stripos($guidelines, 'response and completion') === false
        && stripos($guidelines, 'completion rate') !== false
);

// U5: support@zusty.app is the only contact; no phone number anywhere.
$contact = (string) Http::get('/pages/contact')->at('data.page.content');
Suite::check('the contact page lists no phone', $contact !== '' && preg_match('/<li\b[^>]*>\s*(?:<(?:strong|b)>\s*)?(?:Phone|Telephone|Call us|WhatsApp)\b/i', $contact) === 0, $contact);
Suite::check('…and names the support mailbox', str_contains($contact, 'support@zusty.app'));
Suite::equals('/app/config serves no support phone', (string) (($config['app'] ?? [])['support_phone'] ?? ''), '');

// No published page or FAQ offers a wallet top-up, a text message or a
// phone number. Read straight from the tables, so every active row counts.
$copy = [];
foreach (Database::select('SELECT `slug`, `title`, `subtitle`, `content`, `meta_title`, `meta_description` FROM `pages` WHERE `is_active` = 1') as $row) {
    $copy['page ' . $row['slug']] = html_entity_decode(strip_tags(implode(' ', array_map('strval', $row))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
foreach (Database::select('SELECT `id`, `question`, `answer` FROM `faqs` WHERE `is_active` = 1') as $row) {
    $copy['FAQ ' . $row['id']] = $row['question'] . ' ' . $row['answer'];
}
$offenders = static fn (string $pattern): array => array_keys(array_filter($copy, static fn (string $text): bool => preg_match($pattern, $text) === 1));

Suite::equals('no page or FAQ offers a wallet top-up', $offenders('/\btop[- ]?ups?\b|\badd(?:ing)? money\b|\bload(?:ing)? (?:money|your wallet)\b/i'), []);
Suite::equals('no page or FAQ mentions SMS', $offenders('/\bSMS\b|\btext message code/'), []);
Suite::equals('no page or FAQ prints a phone number', $offenders('/\+91[\s-]?\d|\b[6-9]\d{4}[\s-]?\d{5}\b/'), []);

$promises = Database::select(
    "SELECT CONCAT('banner ', `id`) AS `row`, CONCAT_WS(' ', `title`, `subtitle`) AS `text` FROM `banners` WHERE `is_active` = 1
     UNION ALL
     SELECT CONCAT('slide ', `id`), CONCAT_WS(' ', `title`, `subtitle`) FROM `onboarding_slides` WHERE `is_active` = 1"
);
Suite::equals(
    'no home banner or onboarding slide promises what the app cannot back',
    array_column(array_filter($promises, static fn (array $r): bool => preg_match('/\bevery time\b|\bguarantee|\bevery creator\b|\beffortless/i', (string) $r['text']) === 1), 'row'),
    []
);

// ---------------------------------------------------------------------
Suite::group('Public pages — payment protection and the Grievance Officer');
// ---------------------------------------------------------------------

// P-ESCROW (migration 059): the money settles to Zusty's own account, so no
// copy a user or a reviewer reads may call it escrow (or nodal, a trust
// account, guaranteed or 100% safe). Every active row of every table the app
// or the website shows as text, plus the templates notifications are sent
// from. Internal names (escrow_status, escrow:release) are not copy.
$userCopy = $copy;
$userTables = [
    'template' => "SELECT `code` AS `k`, CONCAT_WS(' ', `title`, `body`, `email_subject`, `email_body`, `sms_body`) AS `t` FROM `notification_templates` WHERE `is_active` = 1",
    'banner'   => "SELECT `id` AS `k`, CONCAT_WS(' ', `title`, `subtitle`, `cta_label`) AS `t` FROM `banners` WHERE `is_active` = 1",
    'slide'    => "SELECT `id` AS `k`, CONCAT_WS(' ', `title`, `subtitle`, `cta_label`) AS `t` FROM `onboarding_slides` WHERE `is_active` = 1",
    'popup'    => "SELECT `id` AS `k`, CONCAT_WS(' ', `title`, `content`, `cta_label`) AS `t` FROM `popups` WHERE `is_active` = 1 AND `deleted_at` IS NULL",
    'testimonial' => "SELECT `id` AS `k`, `content` AS `t` FROM `testimonials` WHERE `is_active` = 1",
];
foreach ($userTables as $label => $sql) {
    foreach (Database::select($sql) as $row) {
        $userCopy[$label . ' ' . $row['k']] = html_entity_decode(strip_tags((string) $row['t']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
Suite::equals(
    'no page, FAQ, template, banner, slide, popup or testimonial says escrow, nodal, trust account, guaranteed or 100% safe',
    array_keys(array_filter($userCopy, static fn (string $text): bool => preg_match('/escrow|\bnodal\b|trust account|guaranteed|100\s*%\s*safe/i', $text) === 1)),
    []
);
// A switched-off testimonial is one panel toggle away from the home screen.
Suite::equals(
    '…nor does a switched-off testimonial',
    array_column(Database::select("SELECT `id` FROM `testimonials` WHERE `content` LIKE '%escrow%'"), 'id'),
    []
);
// "Safely" is a promise, not a description (migration 060 took it out of the
// third onboarding slide): say what happens to the money instead.
Suite::equals(
    'no page, FAQ, template, banner, slide, popup or testimonial says "safely"',
    array_keys(array_filter($userCopy, static fn (string $text): bool => preg_match('/\bsafely\b/i', $text) === 1)),
    []
);
Suite::check(
    'the terms say who holds the payment, when it is released and that disputes are reviewed first',
    str_contains($terms, 'held by Zusty and released to the creator')
        && str_contains($terms, 'our team reviews it before any money is released')
);

// P-GRIEVANCE: a designation at the one public mailbox, with the IT Rules
// timelines, and the DPDP Act contact. No personal name, address or phone:
// the officer is named by the company, and no page prints a number (above).
Suite::check(
    'the privacy policy names the Grievance Officer of Appsgain at support@zusty.app',
    str_contains($privacy, 'Grievance Officer, Appsgain Technologies Private Limited, at support@zusty.app'),
    'missing the designation and mailbox'
);
Suite::check(
    '…acknowledges within 24 hours and resolves within 15 days',
    str_contains($privacy, 'within 24 hours') && str_contains($privacy, 'within 15 days')
);
Suite::check('…and takes data-protection requests under the DPDP Act', str_contains($privacy, 'Digital Personal Data Protection Act'));
Suite::check(
    '…and gives no phone number or postal address for it',
    preg_match('/\+91|\b\d{3}[\s-]?\d{3}[\s-]?\d{4}\b|\b\d{6}\b|\b(?:road|floor|street|nagar|sector)\b/i', $privacy) === 0
);
Suite::check('the terms point complaints to the Grievance Officer', str_contains($terms, 'Grievance Officer'));
Suite::check('the contact page does too', str_contains($contact, 'Grievance Officer') && str_contains($contact, '15 days'));

// What the code keeps (PaymentService::scrubGatewayResponse drops the card,
// UPI and contact fields; the card_last4, upi_vpa and bank_name columns are
// never written), and what the wallet holds (ReferralService credits it).
Suite::check(
    'the privacy policy does not claim to keep card digits or the UPI ID',
    $privacy !== '' && stripos($privacy, 'card network and last four digits') === false
        && str_contains($privacy, 'with the card details, UPI ID and contact details taken out')
);
// Whichever method the payer picked: Cashfree's app (wallet), pay_later and
// cardless_emi objects carry the payer's phone, and a card EMI carries the
// card under `emi`. The method type and provider stay.
$scrubbed = PaymentService::scrubGatewayResponse([
    'cf_payment_id'  => 'cf_1',
    'payment_group'  => 'wallet',
    'payment_amount' => 100,
    'payment_method' => [
        'app'          => ['channel' => 'link', 'provider' => 'paytm', 'phone' => '9876543210'],
        'pay_later'    => ['channel' => 'link', 'provider' => 'simpl', 'phone' => '9876543210'],
        'cardless_emi' => ['channel' => 'link', 'provider' => 'zestmoney', 'phone' => '9876543210'],
        'emi'          => ['card_number' => 'XXXXXXXXXXXX1111', 'card_network' => 'visa', 'emi_tenure' => 3],
        'upi'          => ['channel' => 'collect', 'upi_id' => 'payer@upi'],
        'netbanking'   => ['channel' => 'link', 'netbanking_bank_name' => 'Example Bank'],
    ],
]);
$scrubbedJson = (string) json_encode($scrubbed);
Suite::check(
    '…and every stored Cashfree reply loses the payer\'s phone, card and UPI ID, whatever the method',
    !str_contains($scrubbedJson, '9876543210') && !str_contains($scrubbedJson, '1111') && !str_contains($scrubbedJson, 'payer@upi')
        && !array_key_exists('emi', $scrubbed['payment_method'] ?? [])
        && ($scrubbed['payment_group'] ?? '') === 'wallet'
        && (($scrubbed['payment_method'] ?? [])['app']['provider'] ?? '') === 'paytm',
    $scrubbedJson
);
Suite::check(
    '…says the checkout phone goes to Cashfree with that order and is kept only masked',
    str_contains($privacy, 'send it to Cashfree with that order only') && str_contains($privacy, 'shows only its last four digits')
);
Suite::check(
    '…and lists referral rewards among what the wallet holds',
    !Config::settingBool('referral', 'enabled', true) || stripos($privacy, 'referral rewards') !== false
);
// An admin can credit or debit a wallet by hand (UserController::adjustWallet,
// ledger category 'adjustment'), so the policy says so (migration 060).
Suite::check(
    '…and the corrections our team makes to it',
    str_contains($privacy, 'any corrections our team makes to fix an error')
);

// The links the app hands out (migration 059 fixed live installs that still
// had the old package name and domain).
$storeSetting = trim(Config::settingString('app', 'android_store_url', ''));
Suite::check(
    'the Play Store URL names the app\'s package, com.zuste.zuste',
    str_contains($storeSetting, 'id=com.zuste.zuste') && preg_match('/[?&]id=com\.zuste\.app\b/', $storeSetting) === 0,
    $storeSetting
);
$shareSetting = trim(Config::settingString('referral', 'share_base_url', ''));
Suite::check(
    'invite links are on zusty.app, not the old domains',
    $shareSetting !== '' && preg_match('#^https?://([a-z0-9-]+\.)*(zuste\.app|zustee\.com)(:\d+)?(/|$)#i', $shareSetting) === 0,
    $shareSetting
);
Suite::equals(
    '/app/config tells the app the review window it quotes',
    (int) (($config['booking'] ?? [])['auto_complete_days'] ?? 0),
    $reviewDays
);

// Search orders by featured, search_boost and rating only, and withdrawals
// want a confirmed email OR phone: the completion hints served to the app
// (ProfileCompletionService) may not promise a ranking or a phone rule.
Suite::equals(
    'no profile-completion hint promises a search ranking or a phone-only rule',
    array_column(Database::select(
        "SELECT `rule_key` FROM `creator_profile_rules`
          WHERE `is_active` = 1
            AND (`description` REGEXP '(?i)\\\\brank' OR `description` = 'Required before withdrawals.')"
    ), 'rule_key'),
    []
);

// ---------------------------------------------------------------------
Suite::group('Public pages — app copy that fits and says what it is (migration 060)');
// ---------------------------------------------------------------------

// The app cuts this copy off with "…" rather than wrapping it: a Home banner
// subtitle after two lines (home_screen.dart, about 33 characters a line on
// a 360 dp phone), an inline popup's text after two small lines beside its
// call to action (app_popup_host.dart), an onboarding slide's text after
// three lines (onboarding_screen.dart). Every active row, by length.
$tooLong = static function (string $sql, int $max): array {
    $rows = array_filter(
        Database::select($sql),
        static fn (array $r): bool => mb_strlen(trim(html_entity_decode(strip_tags((string) $r['t']), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) > $max
    );

    return array_values(array_map(static fn (array $r): string => (string) $r['k'], $rows));
};
Suite::equals(
    'no active banner subtitle is over 65 characters (two lines on a 360 dp phone)',
    $tooLong('SELECT `id` AS `k`, `subtitle` AS `t` FROM `banners` WHERE `is_active` = 1', 65),
    []
);
Suite::equals(
    'no active inline popup (banner or announcement bar) has over 60 characters of text',
    $tooLong(
        "SELECT `id` AS `k`, `content` AS `t` FROM `popups`
          WHERE `is_active` = 1 AND `deleted_at` IS NULL AND `type` IN ('banner', 'announcement_bar')",
        60
    ),
    []
);
Suite::equals(
    'no active onboarding slide subtitle is over 95 characters',
    $tooLong('SELECT `id` AS `k`, `subtitle` AS `t` FROM `onboarding_slides` WHERE `is_active` = 1', 95),
    []
);

// A Home section built by a query (AppContentService::creatorsFor) lists the
// same creators for every viewer: top_rated sorts by rating, trending by the
// follower totals creators report, newest by sign-up. Nobody picks them and
// nothing counts bookings, so the heading may not say so.
Suite::equals(
    'no query-built home section claims a hand-picked, personal or most-booked list',
    array_column(Database::select(
        "SELECT `id` FROM `homepage_sections`
          WHERE `is_active` = 1
            AND `data_source` IN ('top_rated', 'trending', 'newest', 'category')
            AND CONCAT_WS(' ', `title`, `subtitle`) REGEXP 'hand[- ]?picked|for you|curated|most booked'"
    ), 'id'),
    []
);

// Notifications stored before migration 059 changed the templates keep the
// text they were sent with; 060 replaced the three templated sentences.
Suite::equals(
    'no stored notification keeps a templated escrow sentence',
    (int) Database::scalar(
        "SELECT COUNT(*) FROM `notifications`
          WHERE (`type` = 'payment.success' AND `body` LIKE BINARY '%Your funds are held safely in escrow.%')
             OR (`type` = 'campaign.hired' AND `body` LIKE BINARY '%so the payment can be held in escrow.%')
             OR (`type` = 'booking.disputed' AND `body` LIKE BINARY '%The payment stays in escrow until our team has reviewed it.%')"
    ),
    0
);

// ---------------------------------------------------------------------
Suite::group('Public pages — where shared links land');
// ---------------------------------------------------------------------

// A referral link from the app: referral.share_base_url + the code.
$invite = Http::get($origin . '/invite/ZT4TEST9');
Suite::status('an invite link is a public page', $invite, 200);
Suite::check('it shows the code to enter at sign-up', str_contains($invite->body, 'ZT4TEST9'));
Suite::check('it is kept out of search results', str_contains((string) $invite->header('x-robots-tag'), 'noindex'));
Suite::status('a malformed invite code is a 404', Http::get($origin . '/invite/' . rawurlencode('<b>x</b>')), 404);

// A creator's shared profile or QR code (CreatorEngagementService::shareUrl).
$publicCreator = Database::selectOne(
    "SELECT c.`handle`, c.`display_name` FROM `creators` c
       INNER JOIN `users` u ON u.`id` = c.`user_id`
      WHERE c.`status` = 'approved' AND c.`is_public` = 1
        AND c.`deleted_at` IS NULL AND u.`deleted_at` IS NULL
      ORDER BY c.`id` LIMIT 1"
);

if ($publicCreator === null) {
    Suite::check('a public creator is seeded', false);
} else {
    $profile = Http::get($origin . '/creators/' . rawurlencode((string) $publicCreator['handle']));
    Suite::status('a public creator\'s shared link is a page', $profile, 200);
    Suite::check(
        'it names the creator',
        str_contains($profile->body, $e((string) ($publicCreator['display_name'] ?: '@' . $publicCreator['handle'])))
    );
}

$hiddenHandle = Database::scalar(
    "SELECT `handle` FROM `creators` WHERE `is_public` = 0 OR `status` <> 'approved' ORDER BY `id` LIMIT 1"
);
if ($hiddenHandle !== null) {
    Suite::status(
        'a creator who is not public is the same 404 as no creator',
        Http::get($origin . '/creators/' . rawurlencode((string) $hiddenHandle)),
        404
    );
}
Suite::status('an unknown creator is a 404', Http::get($origin . '/creators/no.such.creator.zz'), 404);

$shareBase = rtrim(Config::settingString('referral', 'share_base_url', ''), '/');
Suite::check(
    'invite links are built on the invite page path',
    $shareBase === '' || str_ends_with($shareBase, '/invite'),
    $shareBase
);

// ---------------------------------------------------------------------
Suite::group('Public pages — only published pages');
// ---------------------------------------------------------------------

Suite::status('an unknown path is still a 404', Http::get($origin . '/not-a-page'), 404);

$wasActive = Database::scalar("SELECT `is_active` FROM `pages` WHERE `slug` = 'community-guidelines'");
if ($wasActive === null) {
    Suite::check('the community-guidelines page is seeded', false);
} else {
    try {
        Database::update('pages', ['is_active' => 0], ['slug' => 'community-guidelines']);
        Suite::status('an unpublished page is a 404', Http::get($origin . '/community-guidelines'), 404);
    } finally {
        Database::update('pages', ['is_active' => (int) $wasActive], ['slug' => 'community-guidelines']);
    }
}

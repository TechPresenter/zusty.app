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
 * banner or onboarding slide makes a promise the app cannot keep.
 *
 * Fixtures: the "unpublished page" check switches one seeded page off and
 * restores its original state in a `finally`.
 */

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Services\AccountDeletionService;
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

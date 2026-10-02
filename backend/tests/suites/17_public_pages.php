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

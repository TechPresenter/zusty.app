<?php

declare(strict_types=1);

/**
 * Links between the app and the public web pages (Web\PageController).
 *
 * The page editor saves alignment as classes (z-ta-left, z-ta-center,
 * z-ta-right, z-ta-justify), and the app opens these pages at /pages/{slug}
 * while the website serves them at /{slug}. A page an operator writes must
 * look and link the same on both: the website styles the alignment classes,
 * and /pages/{slug} permanently redirects to /{slug}, so either link form
 * works wherever the content is shown. The account-deletion page describes
 * what has to be settled first in the same terms as the app.
 *
 * Read-only: no actors, no fixtures, nothing to clean up.
 */

use Zuste\Controllers\Web\PageController;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// The pages live at the site root, beside /api/v1.
$origin = preg_replace('#/api/v\d+$#', '', Http::baseUrl()) ?? Http::baseUrl();

// ---------------------------------------------------------------------
Suite::group('Public page links — the editor\'s alignment');
// ---------------------------------------------------------------------

$res = Http::get($origin . '/privacy');
Suite::status('/privacy is a public page', $res, 200);
foreach (['left', 'center', 'right', 'justify'] as $align) {
    Suite::check(
        "the page styles the editor's z-ta-{$align} class",
        preg_match('/\.prose\s+\.z-ta-' . $align . '\s*\{\s*text-align:\s*' . $align . '\b/', $res->body) === 1
    );
}

// ---------------------------------------------------------------------
Suite::group('Public page links — /pages/{slug} reaches the same page');
// ---------------------------------------------------------------------

foreach (PageController::SLUGS as $slug) {
    $res      = Http::get($origin . '/pages/' . $slug);
    $location = (string) $res->header('location');

    Suite::status("/pages/{$slug} is a permanent redirect", $res, 301);
    Suite::check(
        "/pages/{$slug} points at /{$slug}",
        str_ends_with((string) parse_url($location, PHP_URL_PATH), '/' . $slug)
            && !str_contains($location, '/pages/'),
        $location
    );
}

Suite::status('/pages/ with an unknown slug is a 404', Http::get($origin . '/pages/not-a-page'), 404);
Suite::status('/pages/ with no slug is a 404', Http::get($origin . '/pages/'), 404);

// ---------------------------------------------------------------------
Suite::group('Public page links — account deletion copy');
// ---------------------------------------------------------------------

$res = Http::get($origin . '/account-deletion');
Suite::status('/account-deletion is a public page', $res, 200);
Suite::check(
    'it names what must be settled first, refunds included',
    str_contains($res->body, 'money in your wallet and withdrawals or refunds still being processed need settling first')
);
Suite::check(
    'it says a small balance can be given up instead',
    str_contains($res->body, "A small balance that can't be paid out can be given up instead.")
);
Suite::check('it still points to the app for the details', str_contains($res->body, 'The app shows exactly what is in the way.'));

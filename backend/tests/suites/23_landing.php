<?php

declare(strict_types=1);

/**
 * The public landing page at "/" (Web\LandingController).
 *
 * zusty.app answered "/" with the API's JSON 404. The landing page is the
 * first thing a visitor, a store reviewer or a link preview sees, so the suite
 * checks what a browser and a crawler need: a 200 text/html document that
 * names the app, links the Play listing (or says it is coming soon, without a
 * dead link), links every public legal page, carries the canonical, Open
 * Graph, Twitter and JSON-LD tags, runs no script under the document CSP,
 * loads nothing from another host, revalidates with a 304 and answers HEAD.
 *
 * The page's interactivity is CSS only, so the suite also checks the markup
 * that makes it work: the feature tabs are radio inputs with a label each and
 * a screenshot each, the FAQ is <details>, every in-page link has a target,
 * images carry their size and load lazily below the hero, and the only links
 * that leave the site are the Play listing and the social profiles. The copy
 * names one mailbox (the support address) and makes no rating, ranking or
 * user-count claim.
 *
 * Every expected value is read from the same settings the controller reads,
 * so the checks hold whatever an admin has configured.
 *
 * Read-only: no actors, no fixtures, no writes. No rate-limit reset either:
 * "/" is not throttled.
 */

use Zuste\Controllers\Web\PageController;
use Zuste\Core\Config;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

// The page lives at the site root, beside /api/v1.
$origin = preg_replace('#/api/v\d+$#', '', Http::baseUrl()) ?? Http::baseUrl();

/** Parse a page for XPath queries; libxml's HTML5 complaints are not ours. */
$parse = static function (string $html): DOMXPath {
    $dom      = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($dom);
};

/** How many nodes match; -1 for a query libxml rejects. */
$count = static function (DOMXPath $xp, string $query): int {
    $nodes = $xp->query($query);

    return $nodes === false ? -1 : $nodes->length;
};

/** A string as an XPath literal. */
$lit = static fn (string $value): string => str_contains($value, "'") ? '"' . $value . '"' : "'" . $value . "'";

/** First match: an attribute's value or an element's text, else null. */
$attr = static function (DOMXPath $xp, string $query): ?string {
    $nodes = $xp->query($query);
    $node  = $nodes === false ? null : $nodes->item(0);

    return $node instanceof DOMAttr ? $node->value : ($node instanceof DOMElement ? $node->textContent : null);
};

/** <meta name|property="$key" content> */
$meta = static fn (DOMXPath $xp, string $key): ?string =>
    $attr($xp, "//meta[@name='{$key}' or @property='{$key}']/@content");

/** Visible text with whitespace collapsed. */
$text = static fn (DOMXPath $xp, string $query = '//body'): string =>
    trim((string) preg_replace('/\s+/u', ' ', (string) $attr($xp, $query)));

/** HEAD without waiting for a body (Http::raw's custom verb would). */
$head = static function (string $url): Res {
    $headers = [];
    $handle  = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    $body   = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($handle);
    curl_close($handle);

    return new Res($status, is_string($body) ? $body : '', $headers, 0.0, $error === '' ? null : $error);
};

// What the page should show, from settings, by the controller's rules.
$appName = trim(Config::settingString('general', 'app_name', 'Zusty')) ?: 'Zusty';
$company = trim(Config::settingString('general', 'company_name', '')) ?: $appName;
$support = trim(Config::settingString('general', 'support_email', ''));
$support = filter_var($support, FILTER_VALIDATE_EMAIL) !== false ? $support : '';
$title   = trim(Config::settingString('seo', 'site_title', '')) ?: $appName . ' — Influencer Marketplace';
$summary = trim(Config::settingString('seo', 'site_description', ''));

$storeUrl = trim(Config::settingString('app', 'android_store_url', ''));
$live     = Config::setting('app', 'android_store_live', null);
if (($live !== null && !Config::settingBool('app', 'android_store_live', false))
    || filter_var($storeUrl, FILTER_VALIDATE_URL) === false
    || strtolower((string) parse_url($storeUrl, PHP_URL_SCHEME)) !== 'https') {
    $storeUrl = '';
}

// The site's origin (Helpers\SiteUrl), restated here rather than called: app.url
// is the source; SEO canonical_base refines it only when it is https on the
// same host; an unusable (or localhost) app.url gives https://zusty.app.
$usableBase = static function (string $value): ?string {
    $value = rtrim(trim($value), '/');

    return preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $value) === 1
        && !str_ends_with(strtolower((string) parse_url($value, PHP_URL_HOST)), 'localhost')
        ? $value
        : null;
};
$appBase = $usableBase((string) Config::get('app.url', ''));
$seoBase = $usableBase(Config::settingString('seo', 'canonical_base', ''));
$siteBase = $appBase === null
    ? 'https://zusty.app'
    : ($seoBase !== null
        && strtolower((string) parse_url($seoBase, PHP_URL_SCHEME)) === 'https'
        && strtolower((string) parse_url($seoBase, PHP_URL_HOST)) === strtolower((string) parse_url($appBase, PHP_URL_HOST))
            ? $seoBase
            : $appBase);
$canonical = $siteBase . '/';

// ---------------------------------------------------------------------
Suite::group('Landing page — the document');
// ---------------------------------------------------------------------

$res  = Http::get($origin . '/');
$body = $res->body;
$xp   = $parse($body);

Suite::status('/ is a public page', $res, 200);
Suite::check('/ is served as HTML', str_starts_with((string) $res->header('content-type'), 'text/html'), (string) $res->header('content-type'));
Suite::check('it is a complete HTML5 document', str_starts_with(ltrim($body), '<!doctype html>') && str_contains($body, '<html lang="en">') && str_contains($body, '</html>'));
Suite::check(
    'no PHP notice, warning or error leaked into it',
    preg_match('#<b>(Notice|Warning|Deprecated|Fatal error|Parse error)</b>|\b(Notice|Warning|Deprecated|Fatal error|Parse error): .* on line \d+|Stack trace:#', $body) === 0
);
Suite::equals('the title is the SEO title', $attr($xp, '//title'), $title);
Suite::check('it names the app', str_contains($text($xp), $appName), $appName);
Suite::equals('it has exactly one h1', $count($xp, '//h1'), 1);
Suite::check('every image has alt text', $count($xp, '//img') > 0 && $count($xp, '//img[not(@alt) or @alt=""]') === 0);
Suite::check('it has a skip link to the main content', $count($xp, "//a[@href='#main']") === 1 && $count($xp, "//main[@id='main']") === 1);

// ---------------------------------------------------------------------
Suite::group('Landing page — the Play Store call to action');
// ---------------------------------------------------------------------

$storeLinks = $count($xp, "//body//a[contains(@href, 'play.google.com')]");
$headerCta  = $attr($xp, "//header//a[normalize-space(.)='Get the app']/@href");

if ($storeUrl !== '') {
    Suite::check('the Play Store listing is linked', $count($xp, '//body//a[@href=' . $lit($storeUrl) . ']') >= 2, $storeUrl);
    Suite::equals('"Get the app" goes to the listing', $headerCta, $storeUrl);
    Suite::check('it does not also say "coming soon"', !str_contains($text($xp), 'Coming soon on Google Play'));
} else {
    Suite::check('it says the app is coming soon on Google Play', str_contains($text($xp), 'Coming soon on Google Play'));
    Suite::equals('no link points at Google Play', $storeLinks, 0);
    Suite::equals('"Get the app" goes to the download section', $headerCta, '#download');
}
Suite::check('the #download section exists', $count($xp, "//*[@id='download']") === 1);
Suite::check('no link is empty or a bare "#"', $count($xp, "//a[not(@href) or @href='' or @href='#']") === 0);

// ---------------------------------------------------------------------
Suite::group('Landing page — sections, links and CSS-only interaction');
// ---------------------------------------------------------------------

foreach (['brands', 'creators', 'how', 'safety', 'download', 'faq'] as $section) {
    Suite::check("the #{$section} section exists once", $count($xp, "//*[@id='{$section}']") === 1);
}

$missingTargets = [];
foreach ($xp->query("//a[starts-with(@href, '#')]/@href") ?: [] as $node) {
    $id = substr((string) $node->nodeValue, 1);
    if ($count($xp, '//*[@id=' . $lit($id) . ']') !== 1) {
        $missingTargets[] = '#' . $id;
    }
}
Suite::check('every in-page link has exactly one target', $missingTargets === [], implode(', ', array_unique($missingTargets)));

// Links that leave the site: only the Play listing and the social profiles
// from settings. Everything else is a path on this site or a mailto.
$allowedOut = $storeUrl !== '' ? [$storeUrl] : [];
foreach (['instagram', 'youtube', 'linkedin', 'twitter', 'facebook'] as $network) {
    $url = trim(Config::settingString('social', $network, ''));
    if ($url !== '') {
        $allowedOut[] = $url;
    }
}
$outbound = [];
foreach ($xp->query('//a/@href') ?: [] as $node) {
    $href = (string) $node->nodeValue;
    if (preg_match('#^(https?:)?//#i', $href) === 1 && !in_array($href, $allowedOut, true)) {
        $outbound[] = $href;
    }
}
Suite::check('links leave the site only for the Play listing and social profiles', $outbound === [], implode(', ', $outbound));

$mailboxes = [];
foreach ($xp->query("//a[starts-with(@href, 'mailto:')]/@href") ?: [] as $node) {
    $mailboxes[] = strtolower((string) preg_replace('/\?.*$/', '', substr((string) $node->nodeValue, 7)));
}
Suite::check(
    'the only mailbox it names is the support address',
    array_values(array_unique($mailboxes)) === ($support !== '' ? [strtolower($support)] : [])
        && preg_match('/\b(press|partners|privacy|legal)@/i', $body) === 0,
    implode(', ', array_unique($mailboxes))
);

// The feature tabs: radio inputs, each with its own label and screenshot.
$radios = $count($xp, "//input[@type='radio' and @name='feature']");
Suite::equals('the feature tabs are five radio inputs', $radios, 5);
Suite::equals('exactly one tab starts selected', $count($xp, "//input[@name='feature' and @checked]"), 1);
$unlabelled = 0;
foreach ($xp->query("//input[@name='feature']/@id") ?: [] as $node) {
    $id = (string) $node->nodeValue;
    if ($count($xp, '//label[@for=' . $lit($id) . ']') !== 1) {
        $unlabelled++;
    }
}
Suite::equals('every tab has one label', $unlabelled, 0);
Suite::equals('every tab has its own screenshot', $count($xp, "//*[contains(@class, 'tab-media')]//img[contains(@class, 'shot-')]"), $radios);
Suite::check('the FAQ is native <details>', $count($xp, "//*[@id='faq']//details/summary") >= 5);

// Images: sized, and only the hero's loads before it is needed.
Suite::check('every screenshot carries its width and height', $count($xp, "//img[contains(@src, '/screens/')][not(@width) or not(@height)]") === 0);
Suite::equals('one image is fetched first (the hero screenshot)', $count($xp, "//img[@fetchpriority='high']"), 1);
Suite::check(
    'every other image in the main content loads lazily',
    $count($xp, "//main//img[not(@fetchpriority='high')][not(@loading='lazy')]") === 0
);

// Truthful copy: no ratings, rankings or user counts, and no SMS sign-in.
$visible = $text($xp);
Suite::check(
    'it makes no rating, ranking or user-count claim',
    preg_match('/★|\bstar rating|\brated\b|#\s?1\b|\bnumber one\b|\bbest\b|\btestimonial|\b\d[\d,.]*\s*(k|m|lakh|crore|million)?\+?\s+(users|downloads|happy|customers)\b/iu', $visible) === 0
);
Suite::check('it does not mention SMS or phone OTP', preg_match('/\bSMS\b|\bOTP\b/', $visible) === 0);

// The fee the FAQ quotes is the one the booking maths uses.
$feeSetting = Config::settingFloat('commission', 'platform_fee_percent', 10.0);
if ($feeSetting > 0) {
    $feeText = rtrim(rtrim(number_format($feeSetting, 2, '.', ''), '0'), '.');
    Suite::check('the FAQ quotes the platform fee from settings', str_contains($visible, $feeText . '% platform fee'), $feeText . '%');
}

// ---------------------------------------------------------------------
Suite::group('Landing page — footer');
// ---------------------------------------------------------------------

foreach (array_merge(PageController::SLUGS, ['account-deletion']) as $slug) {
    Suite::check("the footer links /{$slug}", $count($xp, "//footer//a[@href='/{$slug}']") === 1);
    Suite::status("/{$slug} is not a dead link", Http::get($origin . '/' . $slug), 200);
}

Suite::check(
    'it carries the copyright line the legal pages use',
    str_contains($text($xp, '//footer'), '© ' . date('Y') . ' ' . $company),
    '© ' . date('Y') . ' ' . $company
);
if ($support !== '') {
    Suite::check('it shows the support address', $count($xp, '//footer//a[@href=' . $lit('mailto:' . $support) . ']') === 1, $support);
}
foreach (['instagram', 'youtube', 'linkedin', 'twitter', 'facebook'] as $network) {
    $url = trim(Config::settingString('social', $network, ''));
    if (filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1) {
        Suite::check("it links the {$network} profile", $count($xp, '//footer//a[@href=' . $lit($url) . ']') === 1, $url);
    }
}

// ---------------------------------------------------------------------
Suite::group('Landing page — search and sharing tags');
// ---------------------------------------------------------------------

Suite::equals('the canonical URL is the site root', $attr($xp, "//link[@rel='canonical']/@href"), $canonical);

// The legal pages build their canonical on the same origin (one source, not two).
$termsXp = $parse(Http::get($origin . '/terms')->body);
Suite::equals('the legal pages use the same canonical origin', $attr($termsXp, "//link[@rel='canonical']/@href"), $siteBase . '/terms');

$seoRaw = rtrim(trim(Config::settingString('seo', 'canonical_base', '')), '/');
if ($seoRaw !== '' && $seoBase !== null && $siteBase !== $seoBase) {
    Suite::check(
        'a canonical_base on another host (or not https) is not used',
        !str_contains($body, 'href="' . $seoRaw . '/"') && !str_contains($body, 'content="' . $seoRaw . '/"'),
        $seoRaw
    );
}
Suite::check('the meta description is set', ($meta($xp, 'description') ?? '') !== '' && ($summary === '' || $meta($xp, 'description') === $summary), (string) $meta($xp, 'description'));
Suite::check('robots may index it', str_contains((string) $meta($xp, 'robots'), 'index') && !str_contains((string) $meta($xp, 'robots'), 'noindex'));
Suite::equals('og:url is the canonical URL', $meta($xp, 'og:url'), $canonical);
Suite::equals('og:title is the page title', $meta($xp, 'og:title'), $title);
Suite::equals('og:description is the meta description', $meta($xp, 'og:description'), $meta($xp, 'description'));
Suite::equals('og:type is website', $meta($xp, 'og:type'), 'website');
Suite::equals('og:site_name is the app name', $meta($xp, 'og:site_name'), $appName);
Suite::equals('the Twitter card is a large image', $meta($xp, 'twitter:card'), 'summary_large_image');

$ogImage = (string) $meta($xp, 'og:image');
Suite::check('og:image is an absolute URL', preg_match('#^https?://#', $ogImage) === 1, $ogImage);
Suite::equals('twitter:image is the same image', $meta($xp, 'twitter:image'), $ogImage);
if (str_starts_with($ogImage, rtrim($canonical, '/') . '/')) {
    // Fetched from this server, not from the canonical host.
    $image = Http::get($origin . (string) parse_url($ogImage, PHP_URL_PATH));
    Suite::check('the share image exists here', $image->status === 200 && str_starts_with((string) $image->header('content-type'), 'image/'), $image->describe(60));
}

$ld    = json_decode((string) $attr($xp, "//script[@type='application/ld+json']"), true);
$graph = is_array($ld) ? array_column($ld['@graph'] ?? [], null, '@type') : [];
Suite::check('JSON-LD parses', is_array($ld) && ($ld['@context'] ?? '') === 'https://schema.org');
Suite::check('JSON-LD describes the organisation', ($graph['Organization']['name'] ?? '') === $appName && ($graph['Organization']['url'] ?? '') === $canonical);
Suite::check('JSON-LD describes the Android app', ($graph['MobileApplication']['operatingSystem'] ?? '') === 'Android');
Suite::equals(
    'JSON-LD links the listing only when it is live',
    $graph['MobileApplication']['installUrl'] ?? '',
    $storeUrl
);

// ---------------------------------------------------------------------
Suite::group('Landing page — CSP, assets and caching');
// ---------------------------------------------------------------------

$csp = (string) $res->header('content-security-policy');
Suite::check('the page may style itself and show its own images', str_contains($csp, "style-src 'unsafe-inline'") && str_contains($csp, "img-src 'self'"), $csp);
Suite::check('the CSP allows no script of the page\'s own', !preg_match("/script-src[^;]*'(unsafe-inline|self)'/", $csp), $csp);
Suite::check('no executable script in the page', $count($xp, "//script[not(@type='application/ld+json')]") === 0);
Suite::check('no inline event handlers', preg_match('#<[^>]+\son[a-z]+\s*=#i', $body) === 0);
Suite::check('no forms and no frames', $count($xp, '//form | //iframe') === 0);
Suite::check('no external stylesheet or font', $count($xp, "//link[@rel='stylesheet' or @rel='preconnect' or @rel='dns-prefetch']") === 0);

$assets = [];
foreach ($xp->query("//img/@src | //source/@srcset | //link[@rel='icon' or @rel='apple-touch-icon']/@href") ?: [] as $node) {
    $assets[] = (string) $node->nodeValue;
}
$assets  = array_values(array_unique($assets));
$foreign = array_values(array_filter($assets, static fn (string $url): bool => !str_starts_with($url, '/') || str_starts_with($url, '//')));
Suite::check('every image and icon is served from this site', $assets !== [] && $foreign === [], implode(', ', $foreign));
foreach ($assets as $asset) {
    if (in_array($asset, $foreign, true)) {
        continue;
    }
    $file = Http::get($origin . $asset);
    Suite::check("{$asset} loads", $file->status === 200 && str_starts_with((string) $file->header('content-type'), 'image/'), $file->describe(60));
}

$cache = (string) $res->header('cache-control');
$etag  = (string) $res->header('etag');
Suite::check('it may be cached publicly for a short while', str_contains($cache, 'public') && preg_match('/max-age=([1-9]\d*)/', $cache) === 1, $cache);
Suite::check('it carries an ETag', $etag !== '');
if ($etag !== '') {
    $again = Http::get($origin . '/', [], null, ['If-None-Match' => $etag]);
    Suite::status('an unchanged page revalidates with a 304', $again, 304);
    Suite::equals('the 304 has no body', $again->body, '');
}

// ---------------------------------------------------------------------
Suite::group('Landing page — other methods');
// ---------------------------------------------------------------------

$headRes = $head($origin . '/');
Suite::status('HEAD / answers like GET', $headRes, 200);
Suite::check('HEAD / is HTML', str_starts_with((string) $headRes->header('content-type'), 'text/html'), (string) $headRes->header('content-type'));
Suite::equals('HEAD / sends no body', $headRes->body, '');
Suite::status('POST / is not allowed', Http::post($origin . '/'), 405);

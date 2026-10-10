<?php

declare(strict_types=1);

namespace Zuste\Helpers;

use Zuste\Core\Config;

/**
 * The public website's origin, for the canonical, Open Graph and
 * structured-data URLs of the pages the backend serves: the landing page
 * (Web\LandingController) and the legal pages, /account-deletion, invite and
 * creator pages (Web\PageController). One rule for all of them:
 *
 *  1. app.url (APP_URL) is the source.
 *  2. Settings → SEO → canonical base may refine it (typically to https, or
 *     to a path prefix), but only when it is an https URL on the same host
 *     as app.url. A canonical base that names any other host — an old domain,
 *     a typo — is ignored, so a stale setting can never point search engines
 *     or link previews somewhere else.
 *  3. When app.url is unusable (unset, malformed, or a localhost address),
 *     the public domain https://zusty.app is used.
 *
 * Never read from the request: a forged Host header must not reach a
 * canonical link. The result never ends in a slash.
 */
final class SiteUrl
{
    /** The public domain, used when app.url is not a usable origin. */
    public const DEFAULT_BASE = 'https://zusty.app';

    public static function base(): string
    {
        $app = self::usable((string) Config::get('app.url', ''));

        if ($app === null) {
            return self::DEFAULT_BASE;
        }

        $seo = self::usable(Config::settingString('seo', 'canonical_base', ''));

        if ($seo !== null
            && strtolower((string) parse_url($seo, PHP_URL_SCHEME)) === 'https'
            && strtolower((string) parse_url($seo, PHP_URL_HOST)) === strtolower((string) parse_url($app, PHP_URL_HOST))) {
            return $seo;
        }

        return $app;
    }

    /** An absolute http(s) base without a trailing slash, or null when it cannot be one. */
    private static function usable(string $value): ?string
    {
        $value = rtrim(trim($value), '/');

        if (preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $value) !== 1) {
            return null;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        return $host === '' || str_ends_with($host, 'localhost') ? null : $value;
    }
}

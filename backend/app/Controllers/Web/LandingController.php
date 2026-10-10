<?php

declare(strict_types=1);

namespace Zuste\Controllers\Web;

use Zuste\Core\App;
use Zuste\Core\Config;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\SiteUrl;
use Zuste\Helpers\Str;

/**
 * The public landing page at "/".
 *
 * A static marketing page for the website, beside the legal pages that
 * PageController serves. Everything an operator may want to change without a
 * deploy comes from the same settings the app reads through /app/config:
 * the app name and tagline, the support address, the SEO title, description
 * and share image, the Play Store link, the social links and the fee
 * percentages quoted in the copy. Nothing about the visitor is read or stored,
 * and the page has no form and no script, so it renders under the document
 * CSP that Response::html() sends.
 *
 * The template is app/Views/web/landing.php. It receives plain values and
 * escapes each one where it prints it.
 */
final class LandingController
{
    /** Where the page's own optimised images live (public/assets/landing). */
    private const ASSETS = '/assets/landing/';

    /** How long a browser or the CDN may reuse the page before revalidating. */
    private const MAX_AGE = 300;

    /**
     * Footer links to the public pages PageController serves, in the order
     * and with the labels its own footer uses.
     */
    private const LEGAL_LINKS = [
        '/about'                => 'About',
        '/terms'                => 'Terms',
        '/privacy'              => 'Privacy',
        '/refund-policy'        => 'Refunds',
        '/community-guidelines' => 'Community guidelines',
        '/contact'              => 'Contact',
        '/account-deletion'     => 'Delete your account',
    ];

    /** settings.social keys, with the label the footer shows. */
    private const SOCIAL = [
        'instagram' => 'Instagram',
        'youtube'   => 'YouTube',
        'linkedin'  => 'LinkedIn',
        'twitter'   => 'X (Twitter)',
        'facebook'  => 'Facebook',
    ];

    /**
     * Branding paths the seed ships. While a setting still holds one of
     * these, the page uses its own resized copy (a few KB instead of a few
     * hundred); once an admin points the setting somewhere else, the page
     * follows the admin.
     */
    private const DEFAULT_BRANDING = [
        '/uploads/branding/zusty-logo.png',
        '/uploads/branding/zusty-logo-dark.png',
        '/uploads/branding/zusty-favicon.png',
        '/uploads/branding/og-image.png',
        '/admin/assets/img/zusty-logo.png',
        '/admin/assets/img/zusty-logo-dark.png',
        '/admin/assets/img/favicon.png',
    ];

    /** GET|HEAD / */
    public function index(Request $request): never
    {
        $html = $this->render($this->viewData());
        $etag = '"' . substr(hash('sha256', $html), 0, 32) . '"';

        // Public and identical for every visitor, so a browser or the CDN may
        // keep it briefly; an admin's settings change shows within MAX_AGE.
        Response::header('Cache-Control', 'public, max-age=' . self::MAX_AGE);
        Response::header('ETag', $etag);

        if ($this->etagMatches($request->header('if-none-match'), $etag)) {
            Response::html('', 304);
        }

        Response::html($html);
    }

    /**
     * Everything the template prints, as plain (unescaped) values.
     *
     * @return array<string,mixed>
     */
    private function viewData(): array
    {
        $appName = trim(Config::settingString('general', 'app_name', 'Zusty')) ?: 'Zusty';
        $company = trim(Config::settingString('general', 'company_name', '')) ?: $appName;
        $tagline = trim(Config::settingString('general', 'app_tagline', ''));
        $base    = $this->baseUrl();

        $title = trim(Config::settingString('seo', 'site_title', ''))
            ?: $appName . ' — Influencer Marketplace';
        $description = trim(Config::settingString('seo', 'site_description', ''))
            ?: $appName . ' connects brands with verified creators. Browse creators, book campaigns and pay securely.';

        $support = trim(Config::settingString('general', 'support_email', ''));
        $support = filter_var($support, FILTER_VALIDATE_EMAIL) !== false ? $support : '';

        $twitter = trim(Config::settingString('seo', 'twitter_handle', ''));
        $twitter = preg_match('/^@[A-Za-z0-9_]{1,15}$/', $twitter) === 1 ? $twitter : '';

        $social = [];
        foreach (self::SOCIAL as $key => $label) {
            $url = $this->httpUrl(Config::settingString('social', $key, ''));
            if ($url !== '') {
                $social[] = ['label' => $label, 'url' => $url];
            }
        }

        $storeUrl = $this->storeUrl();

        // The share image an admin set under SEO, if it is really there;
        // otherwise the page's own 1200x630 card.
        $ogImage = $this->brandAsset(Config::settingString('seo', 'og_image', ''), self::ASSETS . 'og-image.jpg');
        $ogSize  = $ogImage === self::ASSETS . 'og-image.jpg' ? [1200, 630] : $this->imageSize($ogImage);

        $canonical = $base . '/';
        $ogImageAbs = $this->absolute($ogImage, $base);

        // The numbers the pricing copy quotes, as the booking maths uses them
        // (Money::breakdown): a fee the brand pays, tax on that fee, and the
        // standard commission taken from the creator's price.
        $fee        = Config::settingFloat('commission', 'platform_fee_percent', 10.0);
        $commission = Config::settingFloat('commission', 'creator_commission_percent', 10.0);
        $taxPercent = Config::settingFloat('commission', 'tax_percent', 18.0);
        $taxLabel   = trim(Config::settingString('commission', 'tax_label', 'GST')) ?: 'tax';

        return [
            'appName'      => $appName,
            'company'      => $company,
            'tagline'      => $tagline,
            'title'        => $title,
            'description'  => $description,
            'canonical'    => $canonical,
            'support'      => $support,
            'twitter'      => $twitter,
            'social'       => $social,
            'storeUrl'     => $storeUrl,
            'legalLinks'   => self::LEGAL_LINKS,
            'year'         => date('Y'),
            'assets'       => self::ASSETS,
            'logo'         => $this->brandAsset(Config::settingString('branding', 'logo_url', ''), ''),
            'logoDark'     => $this->brandAsset(Config::settingString('branding', 'logo_dark_url', ''), ''),
            'favicon'      => $this->brandAsset(Config::settingString('branding', 'favicon_url', ''), self::ASSETS . 'favicon-32.png'),
            'ogImage'      => $ogImageAbs,
            'ogWidth'      => $ogSize[0] ?? null,
            'ogHeight'     => $ogSize[1] ?? null,
            'fee'          => $fee > 0 ? $this->percent($fee) : '',
            'commission'   => $commission > 0 ? $this->percent($commission) : '',
            'taxLabel'     => $fee > 0 && $taxPercent > 0 ? $taxLabel : '',
            'autoComplete' => max(1, Config::settingInt('booking', 'auto_complete_days', 7)),
            'counterOffers' => Config::settingBool('booking', 'allow_counter_offers', true),
            'jsonLd'       => $this->jsonLd($appName, $company, $description, $base, $support, $social, $storeUrl, $ogImageAbs),
            'e'            => static fn (mixed $value): string => Str::e($value),
        ];
    }

    /** Render the template to a string, discarding its output if it fails. */
    private function render(array $vars): string
    {
        $file  = App::instance()->basePath('app/Views/web/landing.php');
        $level = ob_get_level();

        ob_start();

        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }
    }

    /**
     * The site's origin for canonical and share URLs, by the same rule as the
     * legal pages (SiteUrl::base()): app.url, refined by SEO → canonical base
     * only when that is https on the same host, else zusty.app. Never ends in
     * a slash.
     */
    private function baseUrl(): string
    {
        return SiteUrl::base();
    }

    /**
     * The Play Store link, or '' to show "Coming soon".
     *
     * app.android_store_url is also the app's update link, so it is set long
     * before the listing is public. An optional app.android_store_live
     * setting hides the link until the listing is live; while that row does
     * not exist, a set URL is shown.
     */
    private function storeUrl(): string
    {
        $live = Config::setting('app', 'android_store_live', null);

        if ($live !== null && !Config::settingBool('app', 'android_store_live', false)) {
            return '';
        }

        return $this->httpUrl(Config::settingString('app', 'android_store_url', ''), true);
    }

    /** An absolute http(s) URL from settings, or '' when it is not one. */
    private function httpUrl(string $url, bool $httpsOnly = false): string
    {
        $url = trim($url);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' || (!$httpsOnly && $scheme === 'http') ? $url : '';
    }

    /**
     * A branding image from settings: a same-origin path that exists under
     * public/, or an https URL. Seeded defaults and anything unusable give
     * $fallback, which the template reads as "use the page's own copy" when
     * it is ''.
     */
    private function brandAsset(string $value, string $fallback): string
    {
        $value = trim($value);

        if ($value === '' || in_array($value, self::DEFAULT_BRANDING, true)) {
            return $fallback;
        }

        if (str_starts_with($value, 'https://')) {
            return $this->httpUrl($value, true) ?: $fallback;
        }

        if (preg_match('#^/(?!/)[A-Za-z0-9._~/-]+$#', $value) !== 1 || str_contains($value, '..')) {
            return $fallback;
        }

        return is_file(App::instance()->basePath('public' . $value)) ? $value : $fallback;
    }

    /** @return array{0:int,1:int}|array{} */
    private function imageSize(string $path): array
    {
        if (!str_starts_with($path, '/')) {
            return [];
        }

        $size = @getimagesize(App::instance()->basePath('public' . $path));

        return is_array($size) && $size[0] > 0 && $size[1] > 0 ? [(int) $size[0], (int) $size[1]] : [];
    }

    private function absolute(string $pathOrUrl, string $base): string
    {
        return str_starts_with($pathOrUrl, '/') ? $base . $pathOrUrl : $pathOrUrl;
    }

    /** 10.0 -> "10", 12.5 -> "12.5". */
    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Organization, WebSite and MobileApplication structured data, encoded so
     * nothing in it can close the <script> data block it is printed in.
     *
     * @param list<array{label:string,url:string}> $social
     */
    private function jsonLd(
        string $appName,
        string $company,
        string $description,
        string $base,
        string $support,
        array $social,
        string $storeUrl,
        string $image
    ): string {
        $organization = array_filter([
            '@type'     => 'Organization',
            '@id'       => $base . '/#organization',
            'name'      => $appName,
            'legalName' => $company !== $appName ? $company : null,
            'url'       => $base . '/',
            'logo'      => [
                '@type'  => 'ImageObject',
                'url'    => $base . self::ASSETS . 'icon-192.png',
                'width'  => 192,
                'height' => 192,
            ],
            'email'     => $support !== '' ? $support : null,
            'sameAs'    => $social !== [] ? array_column($social, 'url') : null,
        ], static fn (mixed $value): bool => $value !== null);

        $application = array_filter([
            '@type'               => 'MobileApplication',
            '@id'                 => $base . '/#app',
            'name'                => $appName,
            'description'         => $description,
            'url'                 => $base . '/',
            'image'               => $image,
            'operatingSystem'     => 'Android',
            'applicationCategory' => 'BusinessApplication',
            'installUrl'          => $storeUrl !== '' ? $storeUrl : null,
            'offers'              => [
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => trim(Config::settingString('general', 'default_currency', 'INR')) ?: 'INR',
            ],
            'publisher'           => ['@id' => $base . '/#organization'],
        ], static fn (mixed $value): bool => $value !== null);

        $website = [
            '@type'     => 'WebSite',
            '@id'       => $base . '/#website',
            'name'      => $appName,
            'url'       => $base . '/',
            'publisher' => ['@id' => $base . '/#organization'],
        ];

        return (string) json_encode(
            ['@context' => 'https://schema.org', '@graph' => [$organization, $website, $application]],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /** If-None-Match against our tag, tolerating W/ and a CDN's -gzip suffix. */
    private function etagMatches(string $header, string $etag): bool
    {
        if ($header === '') {
            return false;
        }

        $bare = trim($etag, '"');

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            $candidate = trim((string) preg_replace('#^W/#', '', $candidate), '"');
            $candidate = (string) preg_replace('/-(gzip|br|zstd|deflate)$/', '', $candidate);

            if (hash_equals($bare, $candidate)) {
                return true;
            }
        }

        return false;
    }
}

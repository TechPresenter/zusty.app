<?php

declare(strict_types=1);

namespace Zuste\Controllers\Web;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Str;
use Zuste\Services\AccountDeletionService;
use Zuste\Services\PrivacyService;

/**
 * Public web pages served beside the API.
 *
 * The app links to app.url/terms and app.url/privacy (GET /app/config, used
 * on the sign-up screen), and Google Play needs a public privacy policy and
 * an account-deletion page. The legal pages render the CMS pages admins edit
 * under Content → Pages; the deletion page uses the same erased/retained copy
 * as the app's own deletion screen.
 */
final class PageController
{
    /** CMS pages published on the web. routes/api.php routes exactly these. */
    public const SLUGS = ['about', 'terms', 'privacy', 'refund-policy', 'community-guidelines', 'contact'];

    /** Footer links, in order. */
    private const LINKS = [
        '/about'                => 'About',
        '/terms'                => 'Terms',
        '/privacy'              => 'Privacy',
        '/refund-policy'        => 'Refunds',
        '/community-guidelines' => 'Community guidelines',
        '/contact'              => 'Contact',
        '/account-deletion'     => 'Delete your account',
    ];

    /** GET /{slug} — one of SLUGS. */
    public function show(Request $request): never
    {
        $slug = (string) $request->routeParam('slug', '');
        $page = in_array($slug, self::SLUGS, true)
            ? Database::selectOne(
                'SELECT `slug`, `title`, `subtitle`, `content`, `meta_title`, `meta_description`, `updated_at`
                   FROM `pages` WHERE `slug` = :slug AND `is_active` = 1',
                ['slug' => $slug]
            )
            : null;

        if ($page === null) {
            $this->render('Page not found', '<p class="lead">This page does not exist or is no longer published.</p>', status: 404);
        }

        $subtitle = trim((string) ($page['subtitle'] ?? ''));
        // A legal page's subtitle carries its own effective date ("Last
        // updated: 1 January 2026"). That date wins over the row's edit time,
        // which any copy fix or migration moves.
        $dated    = preg_match('/\b(updated|effective)\b/i', $subtitle) === 1;
        $updated  = $dated ? false : strtotime((string) $page['updated_at']);

        $body = ($subtitle !== '' ? '<p class="lead">' . Str::e($subtitle) . '</p>' : '')
            . ($updated !== false ? '<p class="meta">Last updated ' . date('j F Y', $updated) . '</p>' : '')
            // Rich text from the admin page editor, published as written. The
            // document's CSP (Response::html) runs no script whatever it holds.
            . '<div class="prose">' . (string) $page['content'] . '</div>';

        $this->render(
            (string) $page['title'],
            $body,
            (string) ($page['meta_title'] ?: $page['title']),
            (string) ($page['meta_description'] ?? ''),
            '/' . $slug
        );
    }

    /** GET /account-deletion — the page Google Play asks for. */
    public function accountDeletion(Request $request): never
    {
        $policy  = AccountDeletionService::policy();
        $appName = Str::e(Config::settingString('general', 'app_name', 'Zusty'));
        $support = trim(Config::settingString('general', 'support_email', ''));
        $phrase  = Str::e(PrivacyService::CONFIRM_PHRASE);

        $list = static fn (array $items): string => '<ul class="items">' . implode('', array_map(
            static fn (array $item): string => '<li><strong>' . Str::e($item['title']) . '</strong>'
                . '<span>' . Str::e($item['detail']) . '</span></li>',
            $items
        )) . '</ul>';

        $contact = $support !== ''
            ? 'Email <a href="mailto:' . Str::e($support) . '?subject=Delete%20my%20account">' . Str::e($support) . '</a>'
                . ' from the email address on your account, with the subject “Delete my account”.'
            : 'Write to us through the <a href="/contact">contact page</a>, using the email address on your account.';

        $erased   = $list($policy['erased']);
        $retained = $list($policy['retained']);

        $body = <<<HTML
            <p class="lead">You can delete your {$appName} account, brand or creator, at any time. Deleting it is permanent and can't be undone.</p>

            <h2>Delete it in the app</h2>
            <ol>
              <li>Open the {$appName} app and sign in.</li>
              <li>Go to <strong>Settings → Privacy &amp; data → Delete my account</strong>.</li>
              <li>Confirm it's you with your password or a one-time code, type <strong>{$phrase}</strong> and confirm.</li>
            </ol>
            <p>You're signed out on every device straight away.</p>
            <p>Want a copy of your data first? Use <strong>Settings → Privacy &amp; data → Download my data</strong> before you delete.</p>
            <p>Bookings in progress, open disputes, a wallet balance or a pending withdrawal need settling first. The app shows exactly what is in the way.</p>

            <h2>Can't use the app?</h2>
            <p>{$contact} We'll confirm the request comes from you, then delete your account and the data listed below.</p>

            <h2>What we delete</h2>
            {$erased}

            <h2>What we keep, and why</h2>
            {$retained}
            HTML;

        $this->render(
            'Delete your ' . Config::settingString('general', 'app_name', 'Zusty') . ' account',
            $body,
            description: 'How to delete your account and what happens to your data.',
            path: '/account-deletion'
        );
    }

    /** Wrap a page body in the site chrome and send it. */
    private function render(
        string $heading,
        string $body,
        ?string $title = null,
        string $description = '',
        ?string $path = null,
        int $status = 200
    ): never {
        $appName = Config::settingString('general', 'app_name', 'Zusty');
        $company = Config::settingString('general', 'company_name', $appName);
        $baseUrl = rtrim((string) Config::get('app.url', ''), '/');

        // Admins usually write the app name into a page's meta title already.
        $fullTitle = $title ?? $heading;
        $fullTitle = str_contains($fullTitle, $appName) ? $fullTitle : $fullTitle . ' · ' . $appName;

        $h1        = Str::e($heading);
        $pageTitle = Str::e($fullTitle);
        $meta      = $description !== '' ? '<meta name="description" content="' . Str::e($description) . '">' : '';
        $canonical = $path !== null && $baseUrl !== '' ? '<link rel="canonical" href="' . Str::e($baseUrl . $path) . '">' : '';
        $logoAlt   = Str::e($appName);
        $year      = date('Y');
        $owner     = Str::e($company);

        $links = '';
        foreach (self::LINKS as $href => $label) {
            $current = $href === $path ? ' aria-current="page"' : '';
            $links  .= '<a href="' . $href . '"' . $current . '>' . Str::e($label) . '</a>';
        }

        Response::html(<<<HTML
            <!doctype html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>{$pageTitle}</title>
              {$meta}
              {$canonical}
              <link rel="icon" type="image/png" sizes="64x64" href="/admin/assets/img/favicon.png">
              <style>
                * { box-sizing: border-box; }
                body { margin: 0; background: #fff7f9; color: #28171a; line-height: 1.65;
                       font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
                header { background: #fff; border-bottom: 1px solid #f3dde2; }
                .bar, main, footer { max-width: 820px; margin: 0 auto; padding: 0 20px; }
                .bar { padding-top: 14px; padding-bottom: 14px; }
                .bar img { display: block; height: 32px; width: auto; }
                main { margin-top: 32px; margin-bottom: 32px; }
                article { background: #fff; border-radius: 20px; padding: 36px 32px;
                          box-shadow: 0 8px 32px rgba(40, 23, 26, .06); overflow-wrap: anywhere; }
                h1 { font-size: 30px; line-height: 1.25; letter-spacing: -.01em; margin: 0 0 8px; }
                h2 { font-size: 20px; line-height: 1.35; margin: 32px 0 8px; }
                h3 { font-size: 17px; margin: 24px 0 6px; }
                p, li { color: #3d2a2d; }
                .lead { font-size: 18px; color: #5b3f43; }
                .meta { color: #8f6f73; font-size: 14px; margin: 0 0 24px; }
                a { color: #b80049; }
                ul, ol { padding-left: 1.4em; }
                li { margin: 6px 0; }
                .items { list-style: none; padding: 0; margin: 0; }
                .items li { margin: 0; padding: 12px 0; border-bottom: 1px solid #f6e4e8; }
                .items li:last-child { border-bottom: 0; }
                .items strong { display: block; color: #28171a; }
                .items span { color: #5b3f43; }
                table { border-collapse: collapse; width: 100%; margin: 16px 0; }
                th, td { border: 1px solid #f0d6dc; padding: 8px 10px; text-align: left; vertical-align: top; }
                img { max-width: 100%; height: auto; }
                footer { margin-bottom: 40px; font-size: 14px; color: #8f6f73; }
                footer nav { display: flex; flex-wrap: wrap; gap: 6px 18px; margin-bottom: 10px; }
                footer a { color: #5b3f43; text-decoration: none; }
                footer a:hover, footer a[aria-current] { color: #b80049; text-decoration: underline; }
                @media (max-width: 600px) {
                  main { margin-top: 20px; }
                  article { padding: 24px 18px; border-radius: 16px; }
                  h1 { font-size: 24px; }
                }
              </style>
            </head>
            <body>
              <header><div class="bar"><a href="/about"><img src="/admin/assets/img/zusty-logo.png" width="148" height="32" alt="{$logoAlt}"></a></div></header>
              <main><article><h1>{$h1}</h1>{$body}</article></main>
              <footer><nav aria-label="Legal">{$links}</nav>&copy; {$year} {$owner}</footer>
            </body>
            </html>
            HTML, $status);
    }
}

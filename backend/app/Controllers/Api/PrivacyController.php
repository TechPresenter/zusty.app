<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Core\RateLimiter;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Str;
use Zuste\Services\AccountDeletionService;
use Zuste\Services\DataExportService;
use Zuste\Services\PrivacyService;

/**
 * Privacy & account management: "Download my data" and "Delete my account",
 * for brands and creators alike.
 *
 * Routes: /api/v1/me/privacy/* and the token-guarded export download link
 * (a landing page on GET, the archive itself on POST).
 */
final class PrivacyController extends BaseApiController
{
    /**
     * Proof of ownership for a step-up action: the password, a one-time code
     * (with the channel it was sent on), or a fresh Google/Apple sign-in.
     */
    private const PROOF_RULES = [
        'password' => 'nullable|string|max:128',
        'code'     => 'nullable|string|min:4|max:10',
        'channel'  => 'nullable|in:email,sms',
        'id_token' => 'nullable|string|max:4096',
    ];

    /** Requests per IP per hour on each download route (the page, the archive). */
    private const DOWNLOAD_ATTEMPTS = 30;

    private const DOWNLOAD_WINDOW = 3600;

    /** GET /me/privacy: verification options, export status, deletion preflight. */
    public function overview(Request $request): never
    {
        Response::success(PrivacyService::overview($this->user($request)));
    }

    /** POST /me/privacy/verification-code: a one-time code to the account's own email or phone. */
    public function sendCode(Request $request): never
    {
        $data = $this->validate($request, [
            'purpose' => 'required|in:' . implode(',', PrivacyService::PURPOSES),
            'channel' => 'required|in:email,sms',
        ]);

        $result = PrivacyService::sendCode($this->user($request), (string) $data['purpose'], (string) $data['channel']);

        Response::success($result, 'We sent a verification code to ' . $result['destination'] . '.');
    }

    /** POST /me/privacy/exports: re-authenticate, then build the archive. */
    public function requestExport(Request $request): never
    {
        $data = $this->validate($request, self::PROOF_RULES);

        $user = $this->user($request);

        // Refuse inside the cooldown before re-authenticating, so a refused
        // request does not spend the one-time code the user just typed.
        DataExportService::assertCanRequest((int) $user['id']);

        $device      = $this->deviceContext($request);
        $verifiedVia = PrivacyService::verify($user, PrivacyService::PURPOSE_EXPORT, $data, $device);
        $export      = DataExportService::create($user, $verifiedVia, $device);

        Response::created(['export' => $export], 'Your data is ready to download.');
    }

    /**
     * POST /me/privacy/exports/{id}/link: the same proof again, then a fresh
     * link for a copy that is still on the server. Every earlier link to it
     * stops working.
     */
    public function exportLink(Request $request): never
    {
        $data     = $this->validate($request, self::PROOF_RULES);
        $user     = $this->user($request);
        $userId   = (int) $user['id'];
        $exportId = $request->routeParamInt('id');

        // Checked before re-authenticating, for the same reason as above: a
        // copy that is not the user's, or no longer there, must not spend a code.
        DataExportService::assertLinkable($userId, $exportId);

        PrivacyService::verify($user, PrivacyService::PURPOSE_EXPORT, $data, $this->deviceContext($request));

        Response::success(
            ['export' => DataExportService::reissueLink($userId, $exportId)],
            'Here is a fresh download link.'
        );
    }

    /**
     * GET /privacy/exports/download?token=…
     *
     * A page, never the archive. Link scanners, chat unfurlers and browser
     * prefetching all fetch a link with GET, so a GET that counted a download
     * would let them use the downloads up. This page has no side effects;
     * its button posts the token, in the form body, to downloadArchive().
     *
     * Usually opened in a browser, so every failure is a readable page too.
     */
    public function download(Request $request): never
    {
        try {
            self::throttle('throttle:privacy-download:ip:' . $request->ip());

            $token  = self::tokenFrom($request->query('token', ''));
            $export = DataExportService::peek($token);

            self::privatePage();
            Response::html($this->landingPage($token, $export), 200, true);
        } catch (\Throwable $e) {
            $this->downloadFailed($request, $e);
        }
    }

    /**
     * POST /privacy/exports/download, form body token=…
     *
     * The download itself: one use is counted and the ZIP is streamed. The
     * token travels in the body, so it stays out of this request's URL.
     */
    public function downloadArchive(Request $request): never
    {
        try {
            self::throttle('throttle:privacy-download-post:ip:' . $request->ip());

            $file    = DataExportService::consume(self::tokenFrom($request->input('token', '')));
            $content = @file_get_contents($file['path']);

            if ($content === false) {
                throw HttpException::server('Your archive could not be read. Request a new copy of your data from the app.');
            }
        } catch (\Throwable $e) {
            $this->downloadFailed($request, $e);
        }

        Response::download($content, $file['filename'], 'application/zip');
    }

    /** POST /me/privacy/delete-account: typed confirmation, re-authentication, erasure. */
    public function deleteAccount(Request $request): never
    {
        $data = $this->validate($request, self::PROOF_RULES + [
            'confirmation'    => 'required|string|max:20',
            'reason_code'     => 'nullable|string|max:40',
            'reason'          => 'nullable|string|max:500',
            'forfeit_balance' => 'nullable|numeric|min_value:0',
        ], [
            'confirmation.required' => 'Type ' . PrivacyService::CONFIRM_PHRASE . ' to confirm.',
        ]);

        if (strtoupper(trim((string) $data['confirmation'])) !== PrivacyService::CONFIRM_PHRASE) {
            throw new HttpException(
                'Type ' . PrivacyService::CONFIRM_PHRASE . ' to confirm.',
                422,
                'confirmation_mismatch',
                ['confirmation' => ['Type ' . PrivacyService::CONFIRM_PHRASE . ' exactly to confirm.']]
            );
        }

        $user    = $this->user($request);
        $forfeit = isset($data['forfeit_balance']) ? (float) $data['forfeit_balance'] : null;

        // Refuse before re-authenticating, so a blocked deletion does not
        // spend the one-time code the user just typed. A wallet balance that
        // can only be given up is refused here too until the user agrees to
        // give up exactly that amount.
        AccountDeletionService::assertDeletable((int) $user['id'], $forfeit);

        $verifiedVia = PrivacyService::verify($user, PrivacyService::PURPOSE_DELETE, $data, $this->deviceContext($request));

        $summary = AccountDeletionService::delete(
            $user,
            $verifiedVia,
            isset($data['reason_code']) ? (string) $data['reason_code'] : null,
            isset($data['reason']) ? (string) $data['reason'] : null,
            [
                'jti'             => (string) $request->attribute('token_jti', ''),
                'token_exp'       => (int) $request->attribute('token_exp', 0),
                'platform'        => strtolower($request->header('x-platform')),
                'forfeit_balance' => $forfeit,
            ]
        );

        Response::success(
            [
                'deleted'  => true,
                'retained' => $summary['retained'],
                'notice'   => $summary['notice'] ?? null,
            ],
            'Your account has been deleted. We are sorry to see you go.'
        );
    }

    // -----------------------------------------------------------------
    // The download pages
    // -----------------------------------------------------------------

    /**
     * Per-IP budget for the download routes. Kept here rather than on the
     * route so that a browser over the limit still gets a readable page.
     */
    private static function throttle(string $key): void
    {
        $hit = RateLimiter::hit($key, self::DOWNLOAD_ATTEMPTS, self::DOWNLOAD_WINDOW);

        if (!$hit['allowed']) {
            $minutes = max(1, (int) ceil($hit['retry_after'] / 60));

            throw HttpException::tooManyRequests(
                'Too many download attempts from your network. Please try again in '
                    . $minutes . ($minutes === 1 ? ' minute.' : ' minutes.'),
                $hit['retry_after']
            );
        }
    }

    private static function tokenFrom(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Headers for every download page. Its URL carries the token, so the page
     * is never cached and its address is never sent on as a referrer.
     */
    private static function privatePage(): void
    {
        Response::header('Cache-Control', 'no-store');
        Response::header('Referrer-Policy', 'no-referrer');
    }

    /**
     * A download route failed: a readable page for a browser, the usual JSON
     * envelope for anything else (the app, scripts).
     */
    private function downloadFailed(Request $request, \Throwable $e): never
    {
        if (!str_contains(strtolower($request->header('accept')), 'text/html')) {
            throw $e;
        }

        $status = $e instanceof HttpException ? $e->status() : 500;

        if ($status >= 500) {
            Logger::exception($e);
        }

        if ($e instanceof HttpException && $status === 429 && $e->retryAfter() !== null) {
            Response::header('Retry-After', (string) $e->retryAfter());
        }

        if ($status === 429) {
            $page = $this->page('Please wait a little', $e->getMessage(), false);
        } elseif ($status >= 500) {
            $page = $this->page(
                'Something went wrong',
                $e instanceof HttpException
                    ? $e->getMessage()
                    : 'We could not get your data ready to download just now. Please try again in a few minutes.'
            );
        } else {
            $page = $this->page('This link no longer works', $e->getMessage());
        }

        self::privatePage();
        Response::html($page, $status);
    }

    /**
     * What the link opens: the size, how long it works and how many downloads
     * are left, and the one button that downloads it. Nothing about whose
     * data it is.
     *
     * @param array{file_size:int,expires_at:?string,downloads_left:int} $export
     */
    private function landingPage(string $token, array $export): string
    {
        $action = Str::e(DataExportService::downloadPath());
        $value  = Str::e($token);
        $size   = Str::e(self::readableSize((int) $export['file_size']));
        $left   = (int) $export['downloads_left'];
        $uses   = $left === 1 ? '1 download left' : $left . ' downloads left';
        $until  = $export['expires_at'] !== null
            ? 'It works until ' . Str::e(date('j M Y, g:i A', (int) strtotime((string) $export['expires_at']))) . ', with ' . $uses . '.'
            : 'It has ' . $uses . '.';

        return $this->document(<<<HTML
                <h1>Your data is ready</h1>
                <p>A copy of the personal data on your Zusty account, as a ZIP archive of {$size}.</p>
                <p>{$until}</p>
                <form method="post" action="{$action}">
                  <input type="hidden" name="token" value="{$value}">
                  <button type="submit">Download my data</button>
                </form>
                <p class="hint">Keep this link private: anyone who has it can download your data.</p>
            HTML);
    }

    /** A small, self-contained page for a download link that did not work. */
    private function page(string $heading, string $message, bool $hint = true): string
    {
        $title = Str::e($heading);
        $text  = Str::e($message);
        $help  = $hint
            ? '<p class="hint">In the app, open <strong>Settings → Privacy &amp; data → Download my data</strong>.</p>'
            : '';

        return $this->document(<<<HTML
                <h1>{$title}</h1>
                <p>{$text}</p>
                {$help}
            HTML);
    }

    /** The shell both pages share: inline styles only, nothing loaded from elsewhere. */
    private function document(string $main): string
    {
        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <meta name="robots" content="noindex">
              <meta name="referrer" content="no-referrer">
              <title>Zusty data export</title>
              <style>
                body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #fff7f9;
                       font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1f1a1c; }
                main { max-width: 420px; margin: 24px; padding: 32px 28px; background: #fff; border-radius: 20px;
                       box-shadow: 0 8px 32px rgba(31, 26, 28, .08); text-align: center; }
                .mark { display: block; width: 64px; height: 64px; margin: 0 auto 16px; }
                h1 { font-size: 20px; margin: 0 0 8px; }
                p { margin: 0 0 8px; line-height: 1.55; color: #5c5357; }
                form { margin: 20px 0 12px; }
                button { font: inherit; font-weight: 600; color: #fff; background: #b80049; border: 0;
                         border-radius: 12px; padding: 14px 28px; cursor: pointer; }
                button:focus-visible { outline: 3px solid #632ce5; outline-offset: 2px; }
                .hint { margin-top: 16px; font-size: 14px; }
              </style>
            </head>
            <body>
              <main>
                <img class="mark" src="/admin/assets/img/zusty-mark.png" width="64" height="64" alt="Zusty">
            {$main}
              </main>
            </body>
            </html>
            HTML;
    }

    private static function readableSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' bytes';
        }

        if ($bytes < 1024 * 1024) {
            return max(1, (int) round($bytes / 1024)) . ' KB';
        }

        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }
}

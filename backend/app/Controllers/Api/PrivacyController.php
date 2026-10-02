<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\HttpException;
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
 * Routes: /api/v1/me/privacy/* and the token-guarded export download link.
 */
final class PrivacyController extends BaseApiController
{
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
        $data = $this->validate($request, [
            'password' => 'nullable|string|max:128',
            'code'     => 'nullable|string|min:4|max:10',
            'channel'  => 'nullable|in:email,sms',
        ]);

        $user        = $this->user($request);
        $verifiedVia = PrivacyService::verify($user, PrivacyService::PURPOSE_EXPORT, $data);
        $export      = DataExportService::create($user, $verifiedVia, $this->deviceContext($request));

        Response::created(['export' => $export], 'Your data is ready to download.');
    }

    /**
     * GET /privacy/exports/download?token=…
     *
     * Usually opened in a browser, from the app or the email, so a dead link
     * gets a readable page rather than a JSON error.
     */
    public function download(Request $request): never
    {
        try {
            $file = DataExportService::consume((string) $request->query('token', ''));
        } catch (HttpException $e) {
            if (str_contains(strtolower($request->header('accept')), 'text/html')) {
                Response::html($this->linkPage($e->getMessage()), $e->status());
            }

            throw $e;
        }

        $content = @file_get_contents($file['path']);

        if ($content === false) {
            throw HttpException::server('Your archive could not be read. Request a new copy of your data from the app.');
        }

        Response::download($content, $file['filename'], 'application/zip');
    }

    /** POST /me/privacy/delete-account: typed confirmation, re-authentication, erasure. */
    public function deleteAccount(Request $request): never
    {
        $data = $this->validate($request, [
            'password'     => 'nullable|string|max:128',
            'code'         => 'nullable|string|min:4|max:10',
            'channel'      => 'nullable|in:email,sms',
            'confirmation' => 'required|string|max:20',
            'reason_code'  => 'nullable|string|max:40',
            'reason'       => 'nullable|string|max:500',
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

        $user = $this->user($request);

        // Refuse before re-authenticating, so a blocked deletion does not
        // spend the one-time code the user just typed.
        AccountDeletionService::assertDeletable((int) $user['id']);

        $verifiedVia = PrivacyService::verify($user, PrivacyService::PURPOSE_DELETE, $data);

        $summary = AccountDeletionService::delete(
            $user,
            $verifiedVia,
            isset($data['reason_code']) ? (string) $data['reason_code'] : null,
            isset($data['reason']) ? (string) $data['reason'] : null,
            [
                'jti'       => (string) $request->attribute('token_jti', ''),
                'token_exp' => (int) $request->attribute('token_exp', 0),
                'platform'  => strtolower($request->header('x-platform')),
            ]
        );

        Response::success(
            ['deleted' => true, 'retained' => $summary['retained']],
            'Your account has been deleted. We are sorry to see you go.'
        );
    }

    /** A small, self-contained page for a download link that no longer works. */
    private function linkPage(string $message): string
    {
        $text = Str::e($message);

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <meta name="robots" content="noindex">
              <title>Zusty data export</title>
              <style>
                body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #fff7f9;
                       font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1f1a1c; }
                main { max-width: 420px; margin: 24px; padding: 32px 28px; background: #fff; border-radius: 20px;
                       box-shadow: 0 8px 32px rgba(31, 26, 28, .08); text-align: center; }
                .mark { display: block; width: 64px; height: 64px; margin: 0 auto 16px; }
                h1 { font-size: 20px; margin: 0 0 8px; }
                p { margin: 0 0 8px; line-height: 1.55; color: #5c5357; }
                .hint { margin-top: 16px; font-size: 14px; }
              </style>
            </head>
            <body>
              <main>
                <img class="mark" src="/admin/assets/img/zusty-mark.png" width="64" height="64" alt="Zusty">
                <h1>This link no longer works</h1>
                <p>{$text}</p>
                <p class="hint">In the app, open <strong>Settings → Privacy &amp; data → Download my data</strong>.</p>
              </main>
            </body>
            </html>
            HTML;
    }
}

<?php

declare(strict_types=1);

namespace Zuste\Middleware;

use Zuste\Core\Logger;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\Session;
use Zuste\Helpers\Csrf;

/**
 * CSRF verification for state-changing admin requests.
 *
 * Safe verbs pass through and record the URL so a failed validation can
 * redirect the operator back to the form they were filling in.
 */
final class CsrfMiddleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function handle(Request $request): void
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            Session::rememberUrl($request->path());

            return;
        }

        $token = $request->input(Csrf::fieldName());

        if (!is_string($token) || $token === '') {
            $token = $request->header(Csrf::headerName());
        }

        if (Csrf::verify(is_string($token) ? $token : null)) {
            return;
        }

        Logger::warning('CSRF token mismatch', [
            'path'   => $request->path(),
            'method' => $request->method(),
            'ip'     => $request->ip(),
        ]);

        if ($request->expectsJson()) {
            Response::error(
                'Your session expired. Refresh the page and try again.',
                419,
                'csrf_token_mismatch'
            );
        }

        Session::toast('error', 'Your session expired. Please try again.');
        Response::redirect(Session::previousUrl() ?? '/admin');
    }
}

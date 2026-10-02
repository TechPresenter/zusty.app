<?php

declare(strict_types=1);

namespace Zuste\Middleware;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Core\Request;

/**
 * Guards the HTTP cron triggers.
 *
 * Hostinger's cron can call a URL instead of running PHP CLI, so these
 * endpoints exist — but they must never be publicly invokable. The shared
 * secret is compared in constant time and may be supplied either as a header
 * or as a query parameter (Hostinger's UI only accepts a plain URL).
 */
final class CronMiddleware
{
    public static function handle(Request $request): void
    {
        $expected = (string) Config::get('cron.secret', '');

        if ($expected === '') {
            Logger::error('Cron endpoint called but CRON_SECRET is not configured.');
            throw HttpException::forbidden('Cron endpoints are disabled.');
        }

        $provided = $request->header('x-cron-secret');

        if ($provided === '') {
            $provided = (string) $request->query('secret', '');
        }

        if ($provided === '' || !Crypto::secureEquals($expected, $provided)) {
            Logger::warning('Rejected cron request with an invalid secret.', [
                'ip'   => $request->ip(),
                'path' => $request->path(),
            ]);

            throw HttpException::forbidden('Invalid cron secret.');
        }
    }
}

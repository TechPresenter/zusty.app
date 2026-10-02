<?php

declare(strict_types=1);

namespace Zuste\Middleware;

use Zuste\Core\Config;
use Zuste\Core\Request;
use Zuste\Core\Response;

/**
 * Blocks the public API with HTTP 503 while maintenance mode is on.
 *
 * Health checks and the settings endpoint stay reachable so the apps can
 * discover *why* they are being refused and show the operator's message
 * instead of a generic network error.
 */
final class MaintenanceMiddleware
{
    private const ALWAYS_ALLOWED = [
        '/api/v1/health',
        '/api/v1/settings',
        '/api/v1/app/config',
    ];

    public static function handle(Request $request): void
    {
        if (!Config::settingBool('general', 'maintenance_mode', false)) {
            return;
        }

        if (in_array($request->path(), self::ALWAYS_ALLOWED, true)) {
            return;
        }

        Response::serviceUnavailable(
            Config::settingString(
                'general',
                'maintenance_message',
                'We are performing scheduled maintenance. Please try again shortly.'
            )
        );
    }
}

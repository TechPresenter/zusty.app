<?php

declare(strict_types=1);

namespace Zuste\Middleware;

use Zuste\Core\Config;
use Zuste\Core\Logger;
use Zuste\Core\RateLimiter;
use Zuste\Core\Request;
use Zuste\Core\Response;

/**
 * Request throttling.
 *
 * Usage in a route definition:
 *   ->middleware('throttle')            → global default from settings
 *   ->middleware('throttle:10,300')     → 10 requests per 300 seconds
 *   ->middleware('throttle:5,900,otp')  → named bucket, isolated from others
 *
 * Authenticated callers are keyed by user id, anonymous ones by IP, so one
 * abusive client on a shared NAT cannot lock out everyone behind it once
 * they have signed in.
 */
final class ThrottleMiddleware
{
    public static function handle(Request $request, ?string $argument = null): void
    {
        [$maxAttempts, $windowSeconds, $bucket] = self::parse($argument);

        $identity = self::identity($request);
        $key      = sprintf('throttle:%s:%s', $bucket, $identity);

        $result = RateLimiter::hit($key, $maxAttempts, $windowSeconds);

        Response::header('X-RateLimit-Limit', (string) $maxAttempts);
        Response::header('X-RateLimit-Remaining', (string) $result['remaining']);

        if (!$result['allowed']) {
            Logger::warning('Rate limit exceeded', [
                'bucket'   => $bucket,
                'identity' => $identity,
                'path'     => $request->path(),
                'hits'     => $result['hits'],
            ]);

            Response::tooManyRequests(
                $result['retry_after'],
                'Too many requests. Please try again in ' . $result['retry_after'] . ' seconds.'
            );
        }
    }

    /**
     * @return array{0:int,1:int,2:string}
     */
    private static function parse(?string $argument): array
    {
        $defaultMax    = Config::settingInt('security', 'api_rate_limit', (int) Config::get('security.rate_limit', 120));
        $defaultWindow = Config::settingInt('security', 'api_rate_window', (int) Config::get('security.rate_window', 60));

        if ($argument === null || $argument === '') {
            return [$defaultMax, $defaultWindow, 'global'];
        }

        $parts = explode(',', $argument);

        $max    = isset($parts[0]) && is_numeric($parts[0]) ? max(1, (int) $parts[0]) : $defaultMax;
        $window = isset($parts[1]) && is_numeric($parts[1]) ? max(1, (int) $parts[1]) : $defaultWindow;
        $bucket = $parts[2] ?? 'global';

        return [$max, $window, preg_replace('/[^a-z0-9_.-]/i', '', $bucket) ?: 'global'];
    }

    private static function identity(Request $request): string
    {
        $userId = $request->userId();

        if ($userId > 0) {
            return 'user:' . $userId;
        }

        // Distinguish endpoints for anonymous callers so a burst of logins
        // does not consume the budget for reading the creator catalogue.
        return 'ip:' . $request->ip() . ':' . $request->path();
    }
}

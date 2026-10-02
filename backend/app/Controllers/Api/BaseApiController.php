<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Validator;

/**
 * Shared helpers for API controllers.
 */
abstract class BaseApiController
{
    /**
     * Validate the request body against a rule set and return only the
     * validated keys.
     *
     * @param  array<string,string> $rules
     * @param  array<string,string> $messages
     * @return array<string,mixed>
     */
    protected function validate(Request $request, array $rules, array $messages = []): array
    {
        return Validator::validate($request->all(), $rules, $messages);
    }

    /** The authenticated user, guaranteed non-null on `auth` routes. */
    protected function user(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    protected function userId(Request $request): int
    {
        return (int) $this->user($request)['id'];
    }

    /** The creator profile id for the authenticated creator. */
    protected function creatorId(Request $request): int
    {
        $creatorId = (int) ($this->user($request)['creator_id'] ?? 0);

        if ($creatorId <= 0) {
            throw HttpException::forbidden('Complete your creator profile to continue.');
        }

        return $creatorId;
    }

    /**
     * Device metadata sent by the Flutter client on auth calls.
     *
     * @return array<string,mixed>
     */
    /**
     * Does the caller's `If-None-Match` cover this validator?
     *
     * The header may carry several validators and may prefix any of them with
     * `W/`; `*` matches anything. Shared here because both the taxonomy bundle
     * and the app config answer 304, and two copies of this would eventually
     * disagree.
     */
    protected function matchesEtag(string $header, string $etag): bool
    {
        if ($header === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === '*' || $candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    protected function deviceContext(Request $request): array
    {
        return [
            'device_id'    => $request->header('x-device-id') ?: $request->string('device_id'),
            'device_name'  => $request->string('device_name'),
            'device_model' => $request->string('device_model'),
            'os_version'   => $request->string('os_version'),
            'app_version'  => $request->header('x-app-version') ?: $request->string('app_version'),
            'platform'     => strtolower($request->header('x-platform') ?: $request->string('platform', 'unknown')),
            'locale'       => $request->string('locale', 'en'),
            'fcm_token'    => $request->string('fcm_token'),
            'ip'           => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ];
    }

    /**
     * Standard pagination arguments.
     *
     * @return array{page:int,per_page:int,offset:int}
     */
    protected function pagination(Request $request, int $default = 20, int $max = 50): array
    {
        $page    = $request->page();
        $perPage = $request->perPage($default, $max);

        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }
}

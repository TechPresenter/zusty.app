<?php

declare(strict_types=1);

namespace Zuste\Middleware;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Jwt;
use Zuste\Core\Request;
use Zuste\Core\TokenException;

/**
 * JWT bearer authentication for the mobile/web API.
 *
 * On success the full user row is attached to the request as the `user`
 * attribute, so downstream code never has to re-query it.
 */
final class AuthMiddleware
{
    /**
     * @param bool $required When false the request continues unauthenticated,
     *                       which lets public endpoints personalise responses
     *                       (is_favorite, is_following) for signed-in callers.
     */
    public static function handle(Request $request, bool $required = true): void
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            if ($required) {
                throw HttpException::unauthorized('Authentication token is missing.');
            }

            return;
        }

        try {
            $payload = Jwt::decode($token);
        } catch (TokenException $e) {
            if ($required) {
                throw $e;
            }

            return;
        }

        if (($payload['typ'] ?? '') !== 'access') {
            if ($required) {
                throw HttpException::unauthorized('Invalid token type.', 'invalid_token');
            }

            return;
        }

        $jti = (string) ($payload['jti'] ?? '');
        if ($jti !== '' && Jwt::isRevoked($jti)) {
            if ($required) {
                throw HttpException::unauthorized('This session has been revoked. Please sign in again.', 'token_revoked');
            }

            return;
        }

        $userId = (int) ($payload['sub'] ?? 0);
        $user   = $userId > 0 ? self::loadUser($userId) : null;

        if ($user === null) {
            if ($required) {
                throw HttpException::unauthorized('Account not found.', 'account_missing');
            }

            return;
        }

        // A password change revoked every refresh token, but the access token
        // is a stateless JWT: it stayed usable for the rest of its TTL (up to
        // jwt_access_ttl, an hour by default) on every device that already held
        // one — including the one the password was changed away from. Anything
        // issued before the credentials moved is no longer trustworthy.
        if (self::credentialsChangedSince($userId, (int) ($payload['iat'] ?? 0))) {
            if ($required) {
                throw HttpException::unauthorized(
                    'Your password was changed. Please sign in again.',
                    'token_revoked'
                );
            }

            return;
        }

        self::assertUsable($user, $required);

        $request->setAttribute('user', $user);
        $request->setAttribute('token_jti', $jti);
        $request->setAttribute('token_exp', (int) ($payload['exp'] ?? 0));

        self::touchLastSeen($userId);
    }

    /** Restrict a route to brands or creators. */
    public static function requireAccountType(Request $request, string $type): void
    {
        $user = $request->user();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        if (($user['account_type'] ?? '') !== $type) {
            throw HttpException::forbidden(
                $type === 'creator'
                    ? 'This action is only available to creator accounts.'
                    : 'This action is only available to brand accounts.'
            );
        }

        // A creator route additionally needs an approved creator profile.
        if ($type === 'creator' && (int) ($user['creator_id'] ?? 0) === 0) {
            throw HttpException::forbidden('Complete your creator profile to continue.');
        }
    }

    /** Require a verified email or phone (used for payments and payouts). */
    public static function requireVerified(Request $request): void
    {
        $user = $request->user();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        if (empty($user['email_verified_at']) && empty($user['phone_verified_at'])) {
            throw new HttpException(
                'Verify your email or phone number to continue.',
                403,
                'verification_required'
            );
        }
    }

    /**
     * Refuse a guest session on a route that needs a real account.
     *
     * Most guest restrictions need no code at all: requireAccountType() already
     * rejects anything that is not 'brand'/'creator', and requireVerified()
     * already rejects an account with neither a verified email nor phone — and
     * a guest has neither. This covers the remainder: routes that only ask for
     * `auth` and would otherwise accept a guest.
     *
     * The distinct error code is what lets the client open the sign-up sheet
     * instead of showing a dead end.
     */
    public static function denyGuest(Request $request): void
    {
        $user = $request->user();

        if ($user !== null && ($user['account_type'] ?? '') === 'guest') {
            throw new HttpException(
                'Create a free account to continue.',
                403,
                'guest_upgrade_required'
            );
        }
    }

    /**
     * Reasons a refresh-token revocation also invalidates outstanding access
     * tokens.
     *
     * Deliberately only the two that mean "the credential itself moved".
     * `logout`, `rotated`, `revoked_by_user` and `guest_upgraded` are per-token
     * or per-device events — treating them as account-wide would sign a user
     * out of every other device when they signed out of one.
     */
    private const CREDENTIAL_CHANGE_REASONS = ['password_changed', 'password_reset'];

    /**
     * Were this account's credentials changed after the token was issued?
     *
     * Reads the watermark straight off `refresh_tokens`, which AuthService
     * already stamps with `revoked_reason` = 'password_changed' /
     * 'password_reset' inside the same transaction as the password write. No
     * new column, no new bookkeeping, and nothing to keep in step: the row that
     * records the revocation IS the cutoff.
     *
     * `revoked_at > iat` is strict, so a token issued in the same second as the
     * change — i.e. by a sign-in that used the new password — is kept.
     *
     * Indexed by `idx_rt_user` (user_id, revoked_at), so this is a single
     * covered lookup rather than a scan.
     */
    private static function credentialsChangedSince(int $userId, int $issuedAt): bool
    {
        if ($userId <= 0 || $issuedAt <= 0) {
            return false;
        }

        $placeholders = [];
        $params       = ['id' => $userId, 'iat' => $issuedAt];

        foreach (self::CREDENTIAL_CHANGE_REASONS as $index => $reason) {
            $placeholders[]          = ':reason' . $index;
            $params['reason' . $index] = $reason;
        }

        return Database::scalar(
            'SELECT 1 FROM `refresh_tokens`
              WHERE `user_id` = :id
                AND `revoked_at` > FROM_UNIXTIME(:iat)
                AND `revoked_reason` IN (' . implode(', ', $placeholders) . ')
              LIMIT 1',
            $params
        ) !== null;
    }

    /** @return array<string,mixed>|null */
    private static function loadUser(int $userId): ?array
    {
        return Database::selectOne(
            'SELECT u.*, c.`id` AS `creator_id`, c.`status` AS `creator_status`, c.`is_verified` AS `creator_verified`
             FROM `users` u
             LEFT JOIN `creators` c ON c.`user_id` = u.`id` AND c.`deleted_at` IS NULL
             WHERE u.`id` = :id AND u.`deleted_at` IS NULL
             LIMIT 1',
            ['id' => $userId]
        );
    }

    /** @param array<string,mixed> $user */
    private static function assertUsable(array $user, bool $required): void
    {
        $status = (string) ($user['status'] ?? '');

        if ($status === 'active' || !$required) {
            return;
        }

        throw match ($status) {
            'suspended' => new HttpException(
                'Your account is suspended. ' . (string) ($user['suspended_reason'] ?? 'Contact support for assistance.'),
                403,
                'account_suspended'
            ),
            'banned' => new HttpException(
                'Your account has been permanently closed.',
                403,
                'account_banned'
            ),
            'pending' => new HttpException(
                'Your account is pending verification.',
                403,
                'account_pending'
            ),
            default => new HttpException('Your account is not active.', 403, 'account_inactive'),
        };
    }

    /**
     * Update presence at most once a minute — a write on every request would
     * be a needless hot row.
     */
    private static function touchLastSeen(int $userId): void
    {
        Database::execute(
            'UPDATE `users`
             SET `last_seen_at` = NOW()
             WHERE `id` = :id AND (`last_seen_at` IS NULL OR `last_seen_at` < DATE_SUB(NOW(), INTERVAL 1 MINUTE))',
            ['id' => $userId]
        );
    }
}

<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Str;
use Zuste\Services\AccountDeletionService;
use Zuste\Services\ActivityLogger;
use Zuste\Services\AuthService;
use Zuste\Services\PrivacyService;

/**
 * Authentication endpoints.
 *
 * Routes: /api/v1/auth/*
 */
final class AuthController extends BaseApiController
{
    /** POST /auth/register */
    public function register(Request $request): never
    {
        $data = $this->validate($request, [
            'name'          => 'required|string|min:2|max:120',
            'email'         => 'nullable|email|max:191',
            'phone'         => 'nullable|phone',
            'password'      => 'required|string|password|max:128',
            'account_type'  => 'nullable|in:brand,creator',
            'referral_code' => 'nullable|string|max:16',
            'accept_terms'  => 'required|boolean',
        ], [
            'accept_terms.required' => 'You must accept the Terms & Conditions and Privacy Policy.',
        ]);

        if (empty($data['accept_terms'])) {
            throw HttpException::unprocessable('You must accept the Terms & Conditions to create an account.');
        }

        $result = AuthService::register($data, $this->deviceContext($request));

        AuthService::registerDevice((int) $result['user']['id'], $this->deviceContext($request));

        Response::created([
            'user'   => $result['user'],
            'tokens' => $result['tokens'],
        ], 'Welcome to Zusty! Your account is ready.');
    }

    /** POST /auth/login */
    public function login(Request $request): never
    {
        $data = $this->validate($request, [
            'identifier' => 'required|string|max:191',
            'password'   => 'required|string|max:128',
        ], [
            'identifier.required' => 'Enter your email address or phone number.',
        ]);

        $result = AuthService::login(
            (string) $data['identifier'],
            (string) $data['password'],
            $request->ip(),
            $this->deviceContext($request)
        );

        AuthService::registerDevice((int) $result['user']['id'], $this->deviceContext($request));

        Response::success($result, 'Signed in successfully.');
    }

    /**
     * POST /auth/firebase
     *
     * Handles Google, Apple and phone-OTP sign-in. The client completes the
     * flow with the Firebase SDK and posts the resulting ID token here.
     */
    public function firebase(Request $request): never
    {
        $data = $this->validate($request, [
            'id_token'      => 'required|string',
            'account_type'  => 'nullable|in:brand,creator',
            'name'          => 'nullable|string|max:120',
            'referral_code' => 'nullable|string|max:16',
        ]);

        $result = AuthService::loginWithFirebase(
            (string) $data['id_token'],
            $request->ip(),
            $this->deviceContext($request),
            $data
        );

        AuthService::registerDevice((int) $result['user']['id'], $this->deviceContext($request));

        Response::success(
            ['user' => $result['user'], 'tokens' => $result['tokens'], 'is_new_account' => $result['is_new']],
            $result['is_new'] ? 'Welcome to Zusty!' :'Signed in successfully.'
        );
    }

    /**
     * POST /auth/guest
     *
     * Start browsing without an account. Returns an ordinary token pair whose
     * role claim is `guest`, so nothing downstream needs a special case.
     */
    public function guest(Request $request): never
    {
        $result = AuthService::loginAsGuest($request->ip(), $this->deviceContext($request));

        Response::success(
            ['user' => $result['user'], 'tokens' => $result['tokens']],
            'Browsing as a guest.'
        );
    }

    /**
     * POST /auth/guest/upgrade
     *
     * Turn the current guest session into a real account without losing
     * anything it did — the row is updated in place, so favourites, history
     * and everything else keyed on the user id survive.
     */
    public function upgradeGuest(Request $request): never
    {
        $data = $this->validate($request, [
            'account_type'  => 'nullable|in:brand,creator',
            'name'          => 'nullable|string|max:120',
            'email'         => 'nullable|email|max:191',
            'phone'         => 'nullable|string|max:20',
            'password'      => 'nullable|string|max:191',
            'id_token'      => 'nullable|string',
            'destination'   => 'nullable|string|max:191',
            'code'          => 'nullable|string|max:10',
            'referral_code' => 'nullable|string|max:16',
        ]);

        $result = AuthService::upgradeGuest(
            $this->userId($request),
            $data,
            $request->ip(),
            $this->deviceContext($request),
            // So the guest's own access token can be blacklisted: its
            // privileges have just changed, and it would otherwise remain
            // usable for the rest of its lifetime.
            (string) $request->attribute('token_jti', ''),
            (int) $request->attribute('token_exp', 0)
        );

        AuthService::registerDevice((int) $result['user']['id'], $this->deviceContext($request));

        Response::success(
            ['user' => $result['user'], 'tokens' => $result['tokens']],
            'Welcome to Zusty! Everything you saved as a guest is still here.'
        );
    }

    /** POST /auth/refresh */
    public function refresh(Request $request): never
    {
        $data = $this->validate($request, ['refresh_token' => 'required|string']);

        $result = AuthService::refresh((string) $data['refresh_token'], $this->deviceContext($request));

        Response::success($result, 'Session refreshed.');
    }

    /** POST /auth/logout */
    public function logout(Request $request): never
    {
        $userId = $this->userId($request);

        // Read the device up front so logout can revoke this device's refresh
        // token even when the client sends only its access token (the app
        // never puts the refresh token in the logout body).
        $deviceId = $request->header('x-device-id') ?: $request->string('device_id');

        AuthService::logout(
            $userId,
            $request->string('refresh_token') ?: null,
            (string) $request->attribute('token_jti', ''),
            (int) $request->attribute('token_exp', 0),
            $deviceId ?: null
        );

        if ($deviceId !== '') {
            Database::execute(
                'UPDATE `user_devices` SET `fcm_token` = NULL, `push_enabled` = 0
                 WHERE `user_id` = :user_id AND `device_id` = :device_id',
                ['user_id' => $userId, 'device_id' => $deviceId]
            );
        }

        Response::noContent('Signed out.');
    }

    /** POST /auth/logout-all */
    public function logoutAll(Request $request): never
    {
        $userId  = $this->userId($request);
        $revoked = AuthService::revokeAllTokens($userId, 'logout_all');

        Database::execute('DELETE FROM `user_devices` WHERE `user_id` = :id', ['id' => $userId]);

        Response::success(['sessions_revoked' => $revoked], 'Signed out on every device.');
    }

    /** POST /auth/forgot-password */
    public function forgotPassword(Request $request): never
    {
        $data = $this->validate($request, ['email' => 'required|email']);

        AuthService::requestPasswordReset((string) $data['email'], $request->ip());

        // Deliberately identical whether or not the account exists.
        Response::success(null, 'If an account exists for that address, a reset link is on its way.');
    }

    /** POST /auth/reset-password */
    public function resetPassword(Request $request): never
    {
        $data = $this->validate($request, [
            'token'    => 'required|string',
            'password' => 'required|string|password|confirmed|max:128',
        ]);

        AuthService::resetPassword((string) $data['token'], (string) $data['password']);

        Response::success(null, 'Your password has been reset. Please sign in.');
    }

    /** POST /auth/change-password */
    public function changePassword(Request $request): never
    {
        $data = $this->validate($request, [
            'current_password' => 'nullable|string|max:128',
            'password'         => 'required|string|password|confirmed|max:128',
        ]);

        $userId = $this->userId($request);

        AuthService::changePassword($userId, (string) ($data['current_password'] ?? ''), (string) $data['password']);

        ActivityLogger::user($this->user($request), 'password.changed', 'user', $userId, 'Password changed.');

        Response::success(null, 'Password updated. Please sign in again.');
    }

    /** POST /auth/otp/send */
    public function sendOtp(Request $request): never
    {
        $data = $this->validate($request, [
            'destination' => 'required|string|max:191',
            'channel'     => 'required|in:sms,email',
            'purpose'     => 'required|in:register,login,verify_phone,verify_email,reset_password,delete_account',
        ]);

        $destination = (string) $data['destination'];

        if ($data['channel'] === 'sms') {
            $destination = Str::normalizePhone($destination);

            if ($destination === '') {
                throw HttpException::unprocessable('Enter a valid phone number.');
            }
        } elseif (filter_var($destination, FILTER_VALIDATE_EMAIL) === false) {
            throw HttpException::unprocessable('Enter a valid email address.');
        }

        $result = AuthService::sendOtp(
            $destination,
            (string) $data['channel'],
            (string) $data['purpose'],
            // This route carries no auth middleware, so userId() is 0 for every
            // caller and `otp_verifications`.`user_id` was always NULL. Resolve
            // the account the destination belongs to instead, which is also the
            // right answer for the signup flow this endpoint exists for.
            $request->userId() ?: self::ownerOfDestination($destination)
        );

        Response::success($result, 'Verification code sent.');
    }

    /** POST /auth/otp/verify */
    public function verifyOtp(Request $request): never
    {
        $data = $this->validate($request, [
            'destination' => 'required|string|max:191',
            'code'        => 'required|string|min:4|max:8',
            'purpose'     => 'required|in:register,login,verify_phone,verify_email,reset_password,delete_account',
        ]);

        $destination = (string) $data['destination'];
        $purpose     = (string) $data['purpose'];

        $record = AuthService::verifyOtp($destination, (string) $data['code'], $purpose);

        // Mark the identifier verified when that was the point of the code.
        if (in_array($purpose, ['verify_phone', 'verify_email'], true)) {
            // Neither /auth/otp/send nor /auth/otp/verify carries auth
            // middleware, so userId() is always 0 and — because send could not
            // resolve an owner either — `user_id` on the row was always NULL.
            // Both operands were therefore permanently zero, the UPDATE never
            // ran, and the endpoint answered {"verified":true} while
            // email_verified_at / phone_verified_at stayed NULL forever.
            //
            // The destination itself is the third source of truth, and the
            // strongest one here: verifyOtp() has just proved the caller
            // controls it.
            $userId = $request->userId()
                ?: (int) ($record['user_id'] ?? 0)
                ?: (self::ownerOfDestination($destination, $purpose) ?? 0);

            if ($userId > 0) {
                Database::update(
                    'users',
                    [$purpose === 'verify_phone' ? 'phone_verified_at' : 'email_verified_at' => date('Y-m-d H:i:s')],
                    ['id' => $userId]
                );
            }
        }

        Response::success(['verified' => true], 'Verification successful.');
    }

    /**
     * The account an OTP destination belongs to, or null when it belongs to
     * nobody yet (the sign-up case, which is legitimate).
     *
     * `$purpose` guards the shape: a `verify_email` code must not be able to
     * stamp a phone as verified, or the other way round.
     */
    private static function ownerOfDestination(string $destination, string $purpose = ''): ?int
    {
        if ($purpose === 'verify_email' && !str_contains($destination, '@')) {
            return null;
        }

        if ($purpose === 'verify_phone' && str_contains($destination, '@')) {
            return null;
        }

        $owner = AuthService::findByIdentifier($destination);

        return $owner !== null ? (int) $owner['id'] : null;
    }

    /** GET /auth/me */
    public function me(Request $request): never
    {
        $user = AuthService::publicUser(AuthService::loadUser($this->userId($request)));

        Response::success(['user' => $user]);
    }

    /** GET /auth/sessions — active devices, so a user can audit their logins. */
    public function sessions(Request $request): never
    {
        $sessions = Database::select(
            'SELECT `id`, `device_name`, `platform`, `ip_address`, `created_at`, `last_used_at`, `expires_at`
             FROM `refresh_tokens`
             WHERE `user_id` = :id AND `revoked_at` IS NULL AND `expires_at` > NOW()
             ORDER BY `id` DESC',
            ['id' => $this->userId($request)]
        );

        Response::success(['sessions' => $sessions]);
    }

    /** DELETE /auth/sessions/{id} */
    public function revokeSession(Request $request): never
    {
        $sessionId = $request->routeParamInt('id');

        $revoked = Database::execute(
            "UPDATE `refresh_tokens`
             SET `revoked_at` = NOW(), `revoked_reason` = 'revoked_by_user'
             WHERE `id` = :id AND `user_id` = :user_id AND `revoked_at` IS NULL",
            ['id' => $sessionId, 'user_id' => $this->userId($request)]
        );

        if ($revoked === 0) {
            throw HttpException::notFound('Session not found.');
        }

        Response::noContent('Session revoked.');
    }

    /**
     * DELETE /auth/account: the original deletion endpoint, kept for older
     * app builds.
     *
     * A signed-up account goes through the same erasure and the same
     * re-authentication as POST /me/privacy/delete-account. That means the
     * password, or a one-time code for an account without one: it used to
     * delete a Google- or Apple-only account with no proof at all. Older
     * builds send no typed confirmation, so none is asked for here. A guest
     * has no personal data and is simply closed.
     */
    public function deleteAccount(Request $request): never
    {
        $data = $this->validate($request, [
            'password' => 'nullable|string|max:128',
            'code'     => 'nullable|string|min:4|max:10',
            'channel'  => 'nullable|in:email,sms',
            'reason'   => 'nullable|string|max:500',
        ]);

        $user = $this->user($request);

        if (($user['account_type'] ?? '') === 'guest') {
            AuthService::closeGuest((int) $user['id']);

            Response::success(null, 'Your account has been deleted.');
        }

        AccountDeletionService::assertDeletable((int) $user['id']);

        $verifiedVia = PrivacyService::verify($user, PrivacyService::PURPOSE_DELETE, $data);

        AccountDeletionService::delete(
            $user,
            $verifiedVia,
            null,
            isset($data['reason']) ? (string) $data['reason'] : null,
            [
                'jti'       => (string) $request->attribute('token_jti', ''),
                'token_exp' => (int) $request->attribute('token_exp', 0),
                'platform'  => strtolower($request->header('x-platform')),
            ]
        );

        Response::success(null, 'Your account has been deleted. We are sorry to see you go.');
    }
}

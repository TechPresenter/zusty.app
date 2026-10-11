<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Core\RateLimiter;
use Zuste\Helpers\Str;

/**
 * Privacy & account management: the parts the data export, the account
 * deletion, setting a first password and changing a primary email or mobile
 * number share, i.e. who may confirm what, and how.
 *
 * All four are step-up actions. A valid session is not enough on its own,
 * because whoever holds a stolen token could otherwise walk off with a copy
 * of everything, erase the account, give it a password they know, or move
 * its email somewhere they read. So each request also carries proof made on
 * the spot, one of:
 *
 *   - a one-time code sent to the account's OWN email or phone. The
 *     destination is never taken from the client, so a code can only ever go
 *     somewhere the account already controls;
 *   - a fresh Google, Apple or phone sign-in through Firebase, bound to the
 *     account's firebase_uid (never matched by email or phone);
 *   - the account password, but only one the account has proved. A password
 *     set by a session without proof (possible before set_password existed)
 *     is not in auth_providers and is refused as password_unproven.
 *
 * Three limits sit around that:
 *
 *   - THE GATE. Every verify() call counts against one per-user budget
 *     (security.privacy_reauth_max_attempts per
 *     security.privacy_reauth_window_minutes), across codes, routes and
 *     purposes. Counting before checking anything is what makes it race-safe,
 *     and once it is full codes are not even sent.
 *   - THE HOLD. For security.privacy_contact_hold_hours after a primary email
 *     or mobile number changes, that channel receives no codes. A session that
 *     moved the email to a mailbox it reads cannot then use that mailbox to
 *     confirm an export or a deletion, and the owner has time to notice the
 *     notice sent to the old address.
 *   - THE AUDIT. Every failed proof is written to activity_logs
 *     (privacy.reauth_failed) and login_history, so the Security Centre's
 *     brute-force views see step-up guessing as well as sign-in guessing.
 *
 * A failed step-up is never a 401: the app treats a 401 as the end of the
 * session.
 *
 * @see DataExportService
 * @see AccountDeletionService
 */
final class PrivacyService
{
    /** What the user types as the final, deliberate step of a deletion. */
    public const CONFIRM_PHRASE = 'DELETE';

    /** One-time-code purposes, one per action, so a code cannot cross over. */
    public const PURPOSE_EXPORT       = 'data_export';
    public const PURPOSE_DELETE       = 'delete_account';
    public const PURPOSE_SET_PASSWORD = 'set_password';
    public const PURPOSE_CONTACT      = 'confirm_contact_change';

    /**
     * Issued only by the signed-in POST /me/privacy/verification-code, never
     * by the public /auth/otp routes: a code for these must be bound to the
     * account that asked for it.
     *
     * @var list<string>
     */
    public const PURPOSES = [
        self::PURPOSE_EXPORT,
        self::PURPOSE_DELETE,
        self::PURPOSE_SET_PASSWORD,
        self::PURPOSE_CONTACT,
    ];

    /** Firebase sign-in providers that can re-authenticate, and the login method that governs each. */
    private const FIREBASE_PROVIDERS = [
        'google.com' => 'google_login',
        'apple.com'  => 'apple_login',
        'phone'      => 'mobile_otp',
    ];

    /** How a Firebase provider is named in `login_history`.`auth_method`. */
    private const FIREBASE_AUDIT = [
        'google.com' => 'google',
        'apple.com'  => 'apple',
        'phone'      => 'phone_otp',
    ];

    /**
     * Everything the privacy screen needs in one call.
     *
     * @param  array<string,mixed> $user The authenticated user row.
     * @return array<string,mixed>
     */
    public static function overview(array $user): array
    {
        return [
            'verification' => self::verificationOptions($user),
            'export'       => DataExportService::status((int) $user['id']),
            'deletion'     => AccountDeletionService::preflight($user),
        ];
    }

    /**
     * The ways this account can prove it is its owner, and the ways it cannot
     * right now and why, so the app can say so instead of offering a button
     * the API will refuse.
     *
     * @param  array<string,mixed> $user A full users row.
     * @return array{
     *   password:bool,
     *   has_password:bool,
     *   channels:list<array{channel:string,destination:string}>,
     *   unavailable:list<array{channel:string,destination:string,reason:string,available_at:?string}>,
     *   providers:list<string>
     * }
     */
    public static function verificationOptions(array $user): array
    {
        $channels    = [];
        $unavailable = [];

        if (!empty($user['email'])) {
            $masked = Str::maskEmail((string) $user['email']);
            $held   = self::heldUntil($user, 'email');

            if ($held === null) {
                $channels[] = ['channel' => 'email', 'destination' => $masked];
            } else {
                $unavailable[] = [
                    'channel'      => 'email',
                    'destination'  => $masked,
                    'reason'       => 'contact_recently_changed',
                    'available_at' => date('c', $held),
                ];
            }
        }

        if (!empty($user['phone'])) {
            $masked = self::maskedPhone($user);

            if (!self::smsAvailable() || !self::smsReaches($user)) {
                $unavailable[] = [
                    'channel'      => 'sms',
                    'destination'  => $masked,
                    'reason'       => 'sms_unavailable',
                    'available_at' => null,
                ];
            } elseif (($held = self::heldUntil($user, 'phone')) !== null) {
                $unavailable[] = [
                    'channel'      => 'sms',
                    'destination'  => $masked,
                    'reason'       => 'contact_recently_changed',
                    'available_at' => date('c', $held),
                ];
            } else {
                $channels[] = ['channel' => 'sms', 'destination' => $masked];
            }
        }

        return [
            'password'     => self::passwordProven($user),
            'has_password' => !empty($user['password_hash']),
            'channels'     => $channels,
            'unavailable'  => $unavailable,
            'providers'    => self::providers($user),
        ];
    }

    /**
     * Send a one-time code to the account's own email or phone.
     *
     * Refused while the gate is full: a code that could not be used would
     * only cost an email or an SMS.
     *
     * @param  array<string,mixed> $user
     * @return array{expires_in:int,length:int,channel:string,destination:string,resend_in:int}
     */
    public static function sendCode(array $user, string $purpose, string $channel): array
    {
        self::assertPurpose($purpose);

        $userId = (int) $user['id'];
        $gate   = self::gateKey($userId);

        if (RateLimiter::attempts($gate) >= self::gateMax()) {
            throw self::locked(max(1, RateLimiter::availableIn($gate)));
        }

        $destination = self::destination($user, $channel);
        $result      = AuthService::sendOtp($destination, $channel, $purpose, $userId);

        return [
            'expires_in'  => (int) $result['expires_in'],
            'length'      => (int) $result['length'],
            'channel'     => $channel,
            'destination' => $channel === 'sms' ? self::maskedPhone($user) : Str::maskEmail($destination),
            'resend_in'   => (int) ($result['resend_in'] ?? 0),
        ];
    }

    /**
     * Prove the request comes from the account's owner, right now.
     *
     * A code wins over a Firebase token, and both win over a password, when
     * more than one is sent. The app only ever sends one of them; the order
     * is strongest first, because a code or a fresh sign-in shows control of
     * something as well as knowledge of a secret. For set_password a password
     * is never proof: it is the thing being set.
     *
     * @param  array<string,mixed> $user    A full users row (SELECT u.*).
     * @param  array<string,mixed> $input   `code` with `channel`, `id_token`, or `password`
     * @param  array<string,mixed> $context BaseApiController::deviceContext(): `ip`, `user_agent`
     * @return 'password'|'email_code'|'sms_code'|'firebase'
     */
    public static function verify(array $user, string $purpose, array $input, array $context = []): string
    {
        self::assertPurpose($purpose);

        $userId = (int) $user['id'];

        // First, before anything is checked: every call counts, right or
        // wrong. Checking and then counting would let parallel guesses all
        // pass the check before any of them was counted.
        $gate = RateLimiter::hit(
            self::gateKey($userId),
            self::gateMax(),
            60 * max(1, Config::settingInt('security', 'privacy_reauth_window_minutes', 60))
        );

        if (!$gate['allowed']) {
            throw self::locked($gate['retry_after']);
        }

        $code     = trim((string) ($input['code'] ?? ''));
        $idToken  = trim((string) ($input['id_token'] ?? ''));
        $password = $purpose === self::PURPOSE_SET_PASSWORD ? '' : (string) ($input['password'] ?? '');
        $channel  = (string) ($input['channel'] ?? '') !== '' ? (string) $input['channel'] : 'email';
        $provider = null;

        if ($code !== '') {
            $method = $channel === 'sms' ? 'sms_code' : 'email_code';
        } elseif ($idToken !== '') {
            $method = 'firebase';
        } elseif ($password !== '') {
            $method = 'password';
        } else {
            throw HttpException::unprocessable(self::proofRequiredMessage($user, $purpose), 'reauth_required');
        }

        try {
            if ($method === 'password') {
                self::checkPassword($user, $password);

                return 'password';
            }

            if ($method === 'firebase') {
                self::checkFirebase($user, $idToken, $provider);

                return 'firebase';
            }

            // Throws its own 422s (otp_not_found, otp_expired, otp_invalid,
            // otp_used) and a 429 otp_attempts_exceeded once the code's
            // attempts are spent. Bound to this account, so a code minted for
            // somebody else at the same address can never be redeemed here.
            AuthService::verifyOtp(self::destination($user, $channel), $code, $purpose, $userId);

            return $method;
        } catch (HttpException $e) {
            // A provider outage is not a failed proof, so it is not counted as
            // one in the brute-force views.
            if ($e->status() < 500) {
                self::auditFailure($user, $purpose, $method, $channel, $provider, $e, $context);
            }

            throw $e;
        }
    }

    /**
     * When the hold on a changed primary email or mobile number ends.
     *
     * @param  array<string,mixed> $user    A full users row.
     * @param  string              $channel 'email', or 'phone' / 'sms'
     * @return int|null unix time the hold ends, or null when the channel is not held
     */
    public static function heldUntil(array $user, string $channel): ?int
    {
        $hours = self::holdHours();

        if ($hours === 0) {
            return null;
        }

        $column = match ($channel) {
            'email'        => 'email_changed_at',
            'phone', 'sms' => 'phone_changed_at',
            default        => null,
        };

        $changedAt = $column === null ? '' : (string) ($user[$column] ?? '');

        if ($changedAt === '') {
            return null;
        }

        $changed = strtotime($changedAt);

        if ($changed === false) {
            return null;
        }

        $until = $changed + ($hours * 3600);

        return $until > time() ? $until : null;
    }

    /**
     * The primary email this account had before a change applied inside the
     * hold window, so notices about the account can reach the owner there too.
     *
     * Reads contact_change_requests, so a caller that erases that table must
     * call this first.
     */
    public static function previousEmail(int $userId): ?string
    {
        $hours = self::holdHours();

        if ($hours === 0) {
            return null;
        }

        $previous = strtolower(trim((string) (Database::scalar(
            "SELECT `current_value` FROM `contact_change_requests`
              WHERE `user_id` = :id AND `target` = 'primary' AND `channel` = 'email' AND `status` = 'applied'
                AND `applied_at` IS NOT NULL AND `applied_at` >= NOW() - INTERVAL :hours HOUR
              ORDER BY `applied_at` DESC, `id` DESC
              LIMIT 1",
            ['id' => $userId, 'hours' => $hours]
        ) ?? '')));

        if ($previous === '' || filter_var($previous, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $current = strtolower(trim((string) (Database::scalar(
            'SELECT `email` FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        ) ?? '')));

        return $previous === $current ? null : $previous;
    }

    /**
     * Where a code for this account goes on a channel. It is always one of
     * the account's own contact points and never a value from the request,
     * and never one that changed inside the hold window.
     *
     * @param array<string,mixed> $user
     */
    private static function destination(array $user, string $channel): string
    {
        if ($channel === 'sms') {
            if (empty($user['phone'])) {
                throw HttpException::unprocessable('There is no mobile number on your account. Use email instead.', 'no_phone');
            }

            if (!self::smsAvailable() || !self::smsReaches($user)) {
                throw HttpException::unprocessable('Codes by SMS are not available right now. Use email instead.', 'sms_unavailable');
            }

            self::assertNotHeld($user, 'phone');

            $phone = Str::normalizePhone((string) ($user['phone_country_code'] ?? '') . (string) $user['phone']);

            if ($phone === '') {
                throw HttpException::unprocessable('The mobile number on your account cannot receive codes. Use email instead.', 'no_phone');
            }

            return $phone;
        }

        if ($channel !== 'email') {
            throw HttpException::unprocessable('Choose where to send the code: email or SMS.', 'invalid_channel');
        }

        if (empty($user['email'])) {
            throw HttpException::unprocessable('There is no email address on your account. Use SMS instead.', 'no_email');
        }

        self::assertNotHeld($user, 'email');

        return strtolower(trim((string) $user['email']));
    }

    /**
     * Refuse a channel inside its hold. This also stops a code minted before
     * the change from being redeemed, because verify() resolves the
     * destination through here.
     *
     * @param array<string,mixed> $user
     */
    private static function assertNotHeld(array $user, string $channel): void
    {
        $until = self::heldUntil($user, $channel);

        if ($until === null) {
            return;
        }

        $when = date('j M Y, g:i A', $until);

        throw HttpException::unprocessable(
            $channel === 'email'
                ? "Your email address changed recently, so we can't send codes there until {$when}. Use another way to confirm."
                : "Your mobile number changed recently, so we can't send codes there until {$when}. Use another way to confirm.",
            'contact_recently_changed'
        );
    }

    /** @param array<string,mixed> $user */
    private static function checkPassword(array $user, string $password): void
    {
        if (empty($user['password_hash'])) {
            throw new HttpException(
                'Your account does not have a password. Confirm with a verification code instead.',
                422,
                'password_not_set',
                ['password' => ['Your account does not have a password.']]
            );
        }

        // Checked before the password itself, so an unproven password is not
        // even tested: whoever set it without proof would learn nothing here.
        if (!self::passwordProven($user)) {
            throw new HttpException(
                "This password can't confirm it is you. Confirm with a code instead.",
                422,
                'password_unproven',
                ['password' => ['Confirm with a code instead.']]
            );
        }

        if (!Crypto::verifyPassword($password, (string) $user['password_hash'])) {
            throw new HttpException(
                'Incorrect password.',
                422,
                'invalid_credentials',
                ['password' => ['Incorrect password.']]
            );
        }
    }

    /**
     * A fresh Firebase sign-in as proof: the same Google, Apple or phone
     * identity the account is linked to, signed in within the last few
     * minutes, through a method that is switched on, and used once.
     *
     * @param array<string,mixed> $user
     * @param string|null         $provider set to the token's provider once it is known, for the audit
     */
    private static function checkFirebase(array $user, string $idToken, ?string &$provider): void
    {
        try {
            $claims = FirebaseService::verifyIdToken($idToken);
        } catch (HttpException $e) {
            if ($e->status() >= 500) {
                self::firebaseUnavailable($e);
            }

            // FirebaseService answers a bad token with a 401, and a 401 here
            // would make the app end the session.
            throw HttpException::unprocessable(
                'We could not check that sign-in. Sign in with Google again, or confirm another way.',
                'invalid_firebase_token'
            );
        } catch (\Throwable $e) {
            self::firebaseUnavailable($e);
        }

        $provider    = (string) ($claims['provider'] ?? '');
        $firebaseUid = trim((string) ($user['firebase_uid'] ?? ''));

        // Bound to the uid and nothing else. An email or phone match is never
        // enough: those are exactly what a session can change.
        if ($firebaseUid === '' || !hash_equals($firebaseUid, (string) ($claims['uid'] ?? ''))) {
            throw HttpException::unprocessable(
                'That sign-in belongs to a different account. Use the Google account you sign in to Zusty with.',
                'reauth_mismatch'
            );
        }

        $maxAge = max(1, Config::settingInt('security', 'privacy_reauth_max_age_seconds', 300));

        if ((int) ($claims['auth_time'] ?? 0) < time() - $maxAge) {
            throw HttpException::unprocessable(
                'That sign-in is too old to confirm it is you. Sign in again and try once more.',
                'reauth_stale'
            );
        }

        $method = self::FIREBASE_PROVIDERS[$provider] ?? null;

        if ($method === null || !AuthConfigService::enabled($method, true)) {
            throw HttpException::unprocessable(
                "That sign-in method can't confirm it is you right now. Use another way to confirm.",
                'reauth_method_unavailable'
            );
        }

        $once = RateLimiter::hit('privacy:idtoken:' . hash('sha256', $idToken), 1, 3600);

        if (!$once['allowed']) {
            throw HttpException::unprocessable(
                'That sign-in has already been used. Sign in again to confirm it is you.',
                'reauth_reused'
            );
        }
    }

    private static function firebaseUnavailable(\Throwable $e): never
    {
        Logger::error('Firebase re-authentication is unavailable', ['error' => $e->getMessage()]);

        throw new HttpException(
            'Google sign-in is not available right now. Confirm another way, or try again later.',
            503,
            'provider_unavailable'
        );
    }

    /**
     * Record a failed proof in the activity log and in the sign-in history.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $context
     */
    private static function auditFailure(
        array $user,
        string $purpose,
        string $method,
        string $channel,
        ?string $provider,
        HttpException $e,
        array $context
    ): void {
        $userId = (int) $user['id'];

        ActivityLogger::log(
            'privacy.reauth_failed',
            'user',
            $userId,
            'Failed re-authentication for ' . str_replace('_', ' ', $purpose) . '.',
            ['purpose' => $purpose, 'method' => $method, 'error' => $e->errorCode()],
            'user',
            $userId,
            (string) ($user['name'] ?? '')
        );

        $authMethod = match ($method) {
            'password'   => 'password',
            'email_code' => 'email_otp',
            'sms_code'   => 'phone_otp',
            default      => self::FIREBASE_AUDIT[(string) $provider]
                ?? (in_array('apple.com', self::authProviders($user), true)
                    && !in_array('google.com', self::authProviders($user), true) ? 'apple' : 'google'),
        };

        // The masked address the proof was aimed at, never the full one: the
        // identifier column outlives the account's own copy of it.
        if ($method === 'sms_code' && !empty($user['phone'])) {
            $identifier = self::maskedPhone($user);
        } elseif (!empty($user['email'])) {
            $identifier = Str::maskEmail((string) $user['email']);
        } else {
            $identifier = !empty($user['phone']) ? self::maskedPhone($user) : '';
        }

        SecurityService::recordLogin(
            'user',
            $userId,
            $identifier,
            $method === 'password' ? SecurityService::OUTCOME_BAD_CREDS : SecurityService::OUTCOME_2FA_FAILED,
            $authMethod,
            null,
            'Step-up for ' . $purpose . ' failed (' . $e->errorCode() . ')',
            (string) ($context['ip'] ?? ''),
            (string) ($context['user_agent'] ?? '')
        );
    }

    /**
     * Is the password one the account proved?
     *
     * Every path that sets a password with proof records 'password' in
     * auth_providers: registration, a guest upgrade, a reset link, and setting
     * a first password with a code. Only the old proofless set path never did.
     *
     * @param array<string,mixed> $user
     */
    private static function passwordProven(array $user): bool
    {
        return !empty($user['password_hash']) && in_array('password', self::authProviders($user), true);
    }

    /**
     * The Firebase sign-ins that can re-authenticate this account.
     *
     * @param  array<string,mixed> $user
     * @return list<string>
     */
    private static function providers(array $user): array
    {
        if (trim((string) ($user['firebase_uid'] ?? '')) === '') {
            return [];
        }

        $linked    = self::authProviders($user);
        $providers = [];

        foreach (self::FIREBASE_PROVIDERS as $provider => $method) {
            if (in_array($provider, $linked, true) && AuthConfigService::enabled($method, true)) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * @param  array<string,mixed> $user
     * @return list<string>
     */
    private static function authProviders(array $user): array
    {
        $raw = $user['auth_providers'] ?? null;

        if (is_array($raw)) {
            return array_values(array_map('strval', $raw));
        }

        $decoded = json_decode((string) ($raw ?? ''), true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * What to say when no proof came with the request.
     *
     * @param array<string,mixed> $user
     */
    private static function proofRequiredMessage(array $user, string $purpose): string
    {
        if ($purpose !== self::PURPOSE_SET_PASSWORD) {
            return 'Confirm it is you with your password or a verification code.';
        }

        $options  = self::verificationOptions($user);
        $channels = array_column($options['channels'], 'channel');

        if (in_array('email', $channels, true)) {
            return 'To add a password, confirm with a code we send to your email.';
        }

        if (in_array('sms', $channels, true)) {
            return 'To add a password, confirm with a code we send to your phone.';
        }

        return 'To add a password, confirm it is you by signing in with Google again.';
    }

    private static function locked(int $retryAfter): HttpException
    {
        $minutes = max(1, (int) ceil($retryAfter / 60));

        return HttpException::tooManyRequests(
            'Too many attempts to confirm it is you. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.',
            $retryAfter,
            'reauth_locked'
        );
    }

    private static function gateKey(int $userId): string
    {
        return 'privacy:reauth:' . $userId;
    }

    private static function gateMax(): int
    {
        return max(1, Config::settingInt('security', 'privacy_reauth_max_attempts', 10));
    }

    /** 0 turns the hold off; capped at 30 days. */
    private static function holdHours(): int
    {
        return max(0, min(720, Config::settingInt('security', 'privacy_contact_hold_hours', 72)));
    }

    /**
     * SMS codes need both the OTP sign-in method and an SMS provider. The
     * same two switches gate {@see AuthService::sendOtp()}, so the channel
     * is only offered when a code would actually be sent.
     */
    private static function smsAvailable(): bool
    {
        return AuthConfigService::enabled('mobile_otp', true) && SmsService::enabled();
    }

    /**
     * Whether the operator's OTP phone rules let a code be texted to this
     * account's number (AuthService::sendOtp() applies the same rules).
     *
     * @param array<string,mixed> $user
     */
    private static function smsReaches(array $user): bool
    {
        $phone = Str::normalizePhone((string) ($user['phone_country_code'] ?? '') . (string) ($user['phone'] ?? ''));

        return $phone !== '' && AuthService::otpPhoneRefusal($phone) === null;
    }

    /** @param array<string,mixed> $user */
    private static function maskedPhone(array $user): string
    {
        return trim((string) ($user['phone_country_code'] ?? '') . ' ' . Str::maskPhone((string) $user['phone']));
    }

    private static function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw HttpException::unprocessable('Unknown verification purpose.', 'invalid_purpose');
        }
    }
}

<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Jwt;
use Zuste\Core\Logger;
use Zuste\Core\RateLimiter;
use Zuste\Helpers\Str;

/**
 * Registration, login, token issuance and account lifecycle.
 *
 * Two credential paths are supported and can coexist on one account:
 *  - email + password held locally (bcrypt, cost 12);
 *  - a Firebase ID token from Google / Apple / phone OTP, verified locally
 *    against Google's signing keys.
 *
 * Whichever path is used, the client ends up with the same pair of tokens:
 * a short-lived JWT access token and a rotating opaque refresh token.
 */
final class AuthService
{
    // -----------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------

    /**
     * Create an account: email sign-up, and the first Google sign-in through
     * loginWithFirebase().
     *
     * Every path into here needs the Terms and Privacy Policy accepted
     * (`terms_accepted`, recorded in `users`.`terms_accepted_at`) and an email
     * address: SMS is off, so a number alone could never be verified or
     * reached. Marketing stays off unless `marketing_opt_in` is true.
     *
     * @param array{
     *   name:string, email?:string|null, phone?:string|null, password?:string|null,
     *   account_type?:string, referral_code?:string|null, firebase_uid?:string|null,
     *   avatar_url?:string|null, auth_provider?:string, terms_accepted?:bool,
     *   marketing_opt_in?:bool
     * } $input
     *
     * @return array{user:array<string,mixed>,tokens:array<string,mixed>}
     */
    public static function register(array $input, array $device = []): array
    {
        $accountType = ($input['account_type'] ?? 'brand') === 'creator' ? 'creator' : 'brand';

        $signupOpen = $accountType === 'creator'
            ? Config::settingBool('app', 'creator_signup_enabled', true)
            : Config::settingBool('app', 'brand_signup_enabled', true);

        if (!$signupOpen) {
            throw HttpException::forbidden('New ' . $accountType . ' registrations are paused. Please try again later.');
        }

        // loginWithFirebase() routes first-time social sign-ups through here
        // carrying the provider, so this one gate also blocks account creation
        // by a method an operator has switched off — defence in depth behind
        // the gate in loginWithFirebase() itself.
        $provider     = (string) ($input['auth_provider'] ?? 'password');
        $signupMethod = $provider === 'password'
            ? 'email_password'
            : self::methodForFirebaseProvider($provider);

        if ($signupMethod !== null) {
            self::assertMethodEnabled($signupMethod, $signupMethod !== 'guest_login', $device);
        }

        self::assertTermsAccepted(!empty($input['terms_accepted']));

        $email = isset($input['email']) && trim((string) $input['email']) !== '' ? strtolower(trim((string) $input['email'])) : null;
        $phone = null;
        $countryCode = '+91';

        if (!empty($input['phone'])) {
            [$countryCode, $phone] = Str::splitPhone((string) $input['phone']);
        }

        self::assertEmailPresent($email);
        self::assertIdentifiersAvailable($email, $countryCode, $phone);

        $referrer = !empty($input['referral_code'])
            ? ReferralService::resolveReferrer((string) $input['referral_code'])
            : null;

        $password = $input['password'] ?? null;

        $result = Database::transaction(static function () use ($input, $accountType, $email, $phone, $countryCode, $password, $referrer, $device): array {
            $now = date('Y-m-d H:i:s');

            $userId = Database::insert('users', [
                'uuid'               => Crypto::uuid4(),
                'firebase_uid'       => $input['firebase_uid'] ?? null,
                'account_type'       => $accountType,
                'name'               => mb_substr(trim((string) $input['name']), 0, 120),
                'email'              => $email,
                'phone_country_code' => $countryCode,
                'phone'              => $phone,
                'password_hash'      => ($password !== null && $password !== '') ? Crypto::hashPassword((string) $password) : null,
                'avatar_url'         => $input['avatar_url'] ?? null,
                'auth_providers'     => json_encode(
                    array_values(array_unique(array_filter([
                        ($password !== null && $password !== '') ? 'password' : null,
                        $input['auth_provider'] ?? null,
                    ]))),
                    JSON_UNESCAPED_UNICODE
                ),
                'status'             => 'active',
                'email_verified_at'  => !empty($input['email_verified']) ? $now : null,
                'phone_verified_at'  => !empty($input['phone_verified']) ? $now : null,
                'referral_code'      => ReferralService::generateCode(),
                'referred_by'        => $referrer !== null ? (int) $referrer['id'] : null,
                'notification_prefs' => self::signupPreferences(!empty($input['marketing_opt_in'])),
                'terms_accepted_at'  => $now,
                'created_at'         => $now,
            ]);

            WalletService::forUser($userId);

            if ($referrer !== null) {
                ReferralService::record($userId, (int) $referrer['id'], (string) $input['referral_code']);
            }

            // A creator account starts with a draft profile so the onboarding
            // flow has something to fill in.
            if ($accountType === 'creator') {
                CreatorService::createDraftProfile($userId, (string) $input['name']);
            }

            $user   = self::loadUser($userId);
            $tokens = self::issueTokens($userId, $accountType, $device);

            NotificationService::send($userId, 'account.welcome', ['name' => (string) $user['name']]);

            ActivityLogger::user($user, 'registered', 'user', $userId, 'New ' . $accountType . ' account created.');

            return ['user' => self::publicUser($user), 'tokens' => $tokens];
        });

        // Outside the transaction: an audit write must never be able to roll
        // back a completed registration. A sign-up is the account's first
        // sign-in — the tokens are issued here — so it belongs in the trail
        // alongside every later one. loginWithFirebase() routes first-time
        // social sign-ups through this method, which is why it audits only its
        // returning-user branch.
        self::auditLogin(
            SecurityService::OUTCOME_SUCCESS,
            $signupMethod !== null ? (self::METHOD_AUDIT[$signupMethod] ?? 'password') : 'password',
            (string) ($email ?? ($phone !== null ? $countryCode . $phone : '')),
            (int) $result['user']['id'],
            $device,
            (string) ($device['ip'] ?? ''),
            'New ' . $accountType . ' account created.'
        );

        return $result;
    }

    // -----------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------

    /**
     * Email/phone + password login.
     *
     * @return array{user:array<string,mixed>,tokens:array<string,mixed>}
     */
    public static function login(string $identifier, string $password, string $ip, array $device = []): array
    {
        $identifier = trim($identifier);

        // Before the account lookup, so a disabled method costs no query and
        // cannot be turned into an account-existence oracle.
        self::assertMethodEnabled('email_password', true, $device + ['ip' => $ip, 'identifier' => $identifier]);

        $throttleKey = 'login:' . strtolower($identifier) . ':' . $ip;

        $maxAttempts = Config::settingInt('security', 'max_login_attempts', 5);
        $lockMinutes = Config::settingInt('security', 'lockout_minutes', 15);

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $waitSeconds = max(1, RateLimiter::availableIn($throttleKey));

            self::auditLogin(
                SecurityService::OUTCOME_THROTTLED,
                'password',
                $identifier,
                null,
                $device,
                $ip,
                'Rate limit reached; retry in ' . max(1, (int) ceil($waitSeconds / 60)) . ' minute(s).'
            );

            throw HttpException::tooManyRequests(
                'Too many failed attempts. Try again in '
                    . max(1, (int) ceil($waitSeconds / 60)) . ' minutes.',
                $waitSeconds
            );
        }

        $user = self::findByIdentifier($identifier);

        // Always burn an attempt, whether or not the account exists — that is
        // what keeps this from being a user-enumeration oracle.
        RateLimiter::hit($throttleKey, $maxAttempts, $lockMinutes * 60);

        if ($user === null || empty($user['password_hash'])) {
            // Spend comparable time so timing does not reveal existence.
            Crypto::verifyPassword($password, '$2y$12$usesomesillystringfaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

            self::auditLogin(
                SecurityService::OUTCOME_BAD_CREDS,
                'password',
                $identifier,
                $user !== null ? (int) $user['id'] : null,
                $device,
                $ip,
                $user === null ? 'No account with that email or phone.' : 'Account has no password set.'
            );

            throw HttpException::unauthorized('Incorrect email/phone or password.', 'invalid_credentials');
        }

        if (!Crypto::verifyPassword($password, (string) $user['password_hash'])) {
            Logger::info('Failed login attempt', ['user_id' => $user['id'], 'ip' => $ip]);

            self::auditLogin(
                SecurityService::OUTCOME_BAD_CREDS,
                'password',
                $identifier,
                (int) $user['id'],
                $device,
                $ip,
                'Password did not match.'
            );

            throw HttpException::unauthorized('Incorrect email/phone or password.', 'invalid_credentials');
        }

        try {
            self::assertLoginAllowed($user);
        } catch (HttpException $e) {
            self::auditLogin(
                SecurityService::OUTCOME_SUSPENDED,
                'password',
                $identifier,
                (int) $user['id'],
                $device,
                $ip,
                'Account status is ' . (string) $user['status'] . '.'
            );

            throw $e;
        }

        RateLimiter::clear($throttleKey);

        // Opportunistically upgrade an old hash on a successful login.
        if (Crypto::passwordNeedsRehash((string) $user['password_hash'])) {
            Database::update('users', ['password_hash' => Crypto::hashPassword($password)], ['id' => (int) $user['id']]);
        }

        $result = self::completeLogin($user, $ip, $device);

        self::auditLogin(
            SecurityService::OUTCOME_SUCCESS,
            'password',
            $identifier,
            (int) $user['id'],
            $device,
            $ip
        );

        return $result;
    }

    /**
     * Sign in (or sign up) with a Firebase ID token — Google, Apple or phone.
     *
     * @return array{user:array<string,mixed>,tokens:array<string,mixed>,is_new:bool}
     */
    public static function loginWithFirebase(string $idToken, string $ip, array $device = [], array $extra = []): array
    {
        $claims = self::verifyFirebaseToken($idToken, $ip, $device);

        // The token is valid, but valid-for-what? FirebaseService already
        // extracts the originating provider and nothing has ever branched on
        // it, which is why disabling Google server-side was impossible before.
        //
        // Gating here rather than inside verifyIdToken keeps that method a pure
        // token verifier, reusable for purposes that are not sign-in.
        $method = self::methodForFirebaseProvider((string) ($claims['provider'] ?? ''));

        if ($method !== null) {
            // Anonymous Firebase tokens map to guest_login and default to
            // refused. Today they are accepted and silently auto-create a real
            // account, which is an unintended anonymous signup path.
            self::assertMethodEnabled(
                $method,
                $method !== 'guest_login',
                $device + ['ip' => $ip, 'identifier' => (string) ($claims['email'] ?? '')]
            );
        }

        if ((string) ($claims['provider'] ?? '') === 'phone') {
            self::assertPhoneSignInAllowed($device + ['ip' => $ip, 'identifier' => (string) ($claims['phone'] ?? '')]);
        }

        // An unrecognised provider stays ungated (see FIREBASE_METHOD_MAP) and
        // is audited as 'password', the column's own default.
        $auditMethod = $method !== null ? (self::METHOD_AUDIT[$method] ?? 'password') : 'password';

        $user = self::firebaseLinkTarget($claims);

        if ($user === null) {
            // A first sign-in creates the account, so it accepts the terms the
            // way the email sign-up does: the app shows "By continuing, you
            // agree to the Terms and Privacy Policy" above its Google button
            // and sends accept_terms with the token. Without it the answer is
            // 422 terms_required. A returning account is never asked again.
            // Marketing stays off; it can be turned on in Settings.
            $result = self::register([
                'name'           => $claims['name'] ?? ($extra['name'] ?? 'Zusty User'),
                'email'          => $claims['email'],
                'phone'          => $claims['phone'],
                'account_type'   => ($extra['account_type'] ?? 'brand'),
                'firebase_uid'   => $claims['uid'],
                'avatar_url'     => $claims['picture'],
                'referral_code'  => $extra['referral_code'] ?? null,
                'auth_provider'  => $claims['provider'],
                'email_verified' => $claims['email_verified'],
                'phone_verified' => $claims['phone'] !== null,
                'terms_accepted' => !empty($extra['accept_terms']),
            ], $device);

            return ['user' => $result['user'], 'tokens' => $result['tokens'], 'is_new' => true];
        }

        try {
            self::assertLoginAllowed($user);
        } catch (HttpException $e) {
            self::auditLogin(
                SecurityService::OUTCOME_SUSPENDED,
                $auditMethod,
                (string) ($claims['email'] ?? $claims['phone'] ?? $claims['uid']),
                (int) $user['id'],
                $device,
                $ip,
                'Account status is ' . (string) $user['status'] . '.'
            );

            throw $e;
        }

        // Link the Firebase identity to the existing account.
        $updates = [];

        if (empty($user['firebase_uid'])) {
            $updates['firebase_uid'] = $claims['uid'];
        }

        // Only when the claim is about the address the account actually holds:
        // a uid-matched account may have moved its email or phone since, and a
        // provider vouching for one address says nothing about another.
        if ($claims['email_verified'] && empty($user['email_verified_at'])
            && $claims['email'] !== null
            && strtolower(trim((string) $claims['email'])) === strtolower(trim((string) ($user['email'] ?? '')))) {
            $updates['email_verified_at'] = date('Y-m-d H:i:s');
        }

        if ($claims['phone'] !== null && empty($user['phone_verified_at']) && !empty($user['phone'])) {
            [$claimCountryCode, $claimNational] = Str::splitPhone((string) $claims['phone']);

            if ($claimNational === (string) $user['phone'] && $claimCountryCode === (string) $user['phone_country_code']) {
                $updates['phone_verified_at'] = date('Y-m-d H:i:s');
            }
        }

        $providers = json_decode((string) ($user['auth_providers'] ?? '[]'), true);
        $providers = is_array($providers) ? $providers : [];

        if (!in_array($claims['provider'], $providers, true)) {
            $providers[]              = $claims['provider'];
            $updates['auth_providers'] = json_encode(array_values($providers), JSON_UNESCAPED_UNICODE);
        }

        if ($updates !== []) {
            Database::update('users', $updates, ['id' => (int) $user['id']]);
            $user = self::loadUser((int) $user['id']);
        }

        $result = self::completeLogin($user, $ip, $device);

        // The first-time branch above returns through register(), which does its
        // own auditing, so only the returning-user path is recorded here.
        self::auditLogin(
            SecurityService::OUTCOME_SUCCESS,
            $auditMethod,
            (string) ($claims['email'] ?? $claims['phone'] ?? $claims['uid']),
            (int) $user['id'],
            $device,
            $ip
        );

        return ['user' => $result['user'], 'tokens' => $result['tokens'], 'is_new' => false];
    }

    /**
     * The existing account a Firebase identity signs in to, or null for a new
     * one.
     *
     * The uid decides first, because it is the identity itself. Falling back
     * to an email or phone is what lets somebody who registered with a
     * password later use Google without getting a second account, but it is
     * also a way into that account, so it is only taken on an identifier
     * Firebase vouches for and the provider owns:
     *
     *  - an email only when email_verified is true AND the provider is Google
     *    or Apple, never for a password, Facebook, custom or anonymous
     *    sign-in, whose email nobody has proved;
     *  - a phone only for a Firebase phone sign-in, whose number is the proof.
     *
     * The match is refused when the account is already linked to a different
     * Firebase identity, and while that email or phone is inside the hold
     * after a change: a session that moved the email to a mailbox it controls
     * must not be able to turn that into a Google sign-in for the account.
     *
     * @param  array<string,mixed> $claims as returned by FirebaseService::verifyIdToken()
     * @return array<string,mixed>|null the full users row
     */
    public static function firebaseLinkTarget(array $claims): ?array
    {
        $uid      = (string) ($claims['uid'] ?? '');
        $provider = (string) ($claims['provider'] ?? '');

        if ($uid !== '') {
            $user = Database::selectOne(
                'SELECT * FROM `users` WHERE `firebase_uid` = :uid AND `deleted_at` IS NULL',
                ['uid' => $uid]
            );

            if ($user !== null) {
                return $user;
            }
        }

        $user    = null;
        $channel = '';
        $email   = strtolower(trim((string) ($claims['email'] ?? '')));
        $phone   = trim((string) ($claims['phone'] ?? ''));

        if ($email !== ''
            && ($claims['email_verified'] ?? false) === true
            && in_array($provider, ['google.com', 'apple.com'], true)) {
            $user = Database::selectOne(
                'SELECT * FROM `users` WHERE `email` = :email AND `deleted_at` IS NULL',
                ['email' => $email]
            );
            $channel = 'email';
        }

        if ($user === null && $phone !== '' && $provider === 'phone') {
            [$countryCode, $national] = Str::splitPhone($phone);

            $user = $national === '' ? null : Database::selectOne(
                'SELECT * FROM `users` WHERE `phone_country_code` = :cc AND `phone` = :phone AND `deleted_at` IS NULL',
                ['cc' => $countryCode, 'phone' => $national]
            );
            $channel = 'phone';
        }

        if ($user === null) {
            return null;
        }

        $linkedUid = (string) ($user['firebase_uid'] ?? '');

        if ($linkedUid !== '' && !hash_equals($linkedUid, $uid)) {
            throw HttpException::conflict(
                $channel === 'email'
                    ? 'This email is already linked to another sign-in. Sign in that way.'
                    : 'This mobile number is already linked to another sign-in. Sign in that way.',
                'account_link_conflict'
            );
        }

        if (PrivacyService::heldUntil($user, $channel) !== null) {
            throw HttpException::conflict(
                'This email address or mobile number changed recently. Sign in with your password, or try again later.',
                'account_link_held'
            );
        }

        return $user;
    }

    /**
     * Sign in with a one-time code sent by POST /auth/otp/send (purpose login),
     * to the account's email address or, while SMS is offered, its number.
     *
     * Only for an account that already exists: auto-creating one here would
     * make accounts with no password and no other way back in. Locked out per
     * destination and IP exactly as the password sign-in is.
     *
     * @return array{user:array<string,mixed>,tokens:array<string,mixed>}
     */
    public static function loginWithOtp(string $destination, string $code, string $ip, array $device = []): array
    {
        $destination = trim($destination);
        $context     = $device + ['ip' => $ip, 'identifier' => $destination];
        $auditMethod = str_contains($destination, '@') ? 'email_otp' : 'phone_otp';

        self::assertMethodEnabled('mobile_otp', true, $context);

        if (!Config::settingBool('auth', 'otp_login_enabled', false)) {
            throw new HttpException('OTP sign-in is currently unavailable.', 403, 'auth_method_disabled');
        }

        // A number can only be used while SMS codes are offered; an email
        // address always can.
        if ($auditMethod === 'phone_otp') {
            self::assertPhoneSignInAllowed($context);
        }

        $throttleKey = 'login-otp:' . strtolower($destination) . ':' . $ip;

        $maxAttempts = Config::settingInt('security', 'max_login_attempts', 5);
        $lockMinutes = Config::settingInt('security', 'lockout_minutes', 15);

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $waitSeconds = max(1, RateLimiter::availableIn($throttleKey));

            self::auditLogin(
                SecurityService::OUTCOME_THROTTLED,
                $auditMethod,
                $destination,
                null,
                $device,
                $ip,
                'Rate limit reached; retry in ' . max(1, (int) ceil($waitSeconds / 60)) . ' minute(s).'
            );

            throw HttpException::tooManyRequests(
                'Too many failed attempts. Try again in '
                    . max(1, (int) ceil($waitSeconds / 60)) . ' minutes.',
                $waitSeconds
            );
        }

        // Burnt whatever happens next, as in login().
        RateLimiter::hit($throttleKey, $maxAttempts, $lockMinutes * 60);

        try {
            self::verifyOtp($destination, $code, 'login');
        } catch (HttpException $e) {
            self::auditLogin(
                SecurityService::OUTCOME_BAD_CREDS,
                $auditMethod,
                $destination,
                null,
                $device,
                $ip,
                'Sign-in code refused (' . $e->errorCode() . ').'
            );

            throw $e;
        }

        $user = self::findByIdentifier($destination);

        if ($user === null) {
            self::auditLogin(
                SecurityService::OUTCOME_BAD_CREDS,
                $auditMethod,
                $destination,
                null,
                $device,
                $ip,
                'No account with that email or phone.'
            );

            // Said only now, after the code proved the caller controls the
            // address, so the send step never tells anyone which addresses
            // have an account. No account is created here.
            throw new HttpException(
                $auditMethod === 'email_otp'
                    ? 'No Zusty account uses this email address. Create an account first.'
                    : 'No account uses this number. Sign up first.',
                404,
                'account_not_found'
            );
        }

        try {
            self::assertLoginAllowed($user);
        } catch (HttpException $e) {
            self::auditLogin(
                SecurityService::OUTCOME_SUSPENDED,
                $auditMethod,
                $destination,
                (int) $user['id'],
                $device,
                $ip,
                'Account status is ' . (string) $user['status'] . '.'
            );

            throw $e;
        }

        RateLimiter::clear($throttleKey);

        $result = self::completeLogin($user, $ip, $device);

        self::auditLogin(
            SecurityService::OUTCOME_SUCCESS,
            $auditMethod,
            $destination,
            (int) $user['id'],
            $device,
            $ip
        );

        return $result;
    }

    // -----------------------------------------------------------------
    // Guest access
    // -----------------------------------------------------------------

    /**
     * Start a guest session.
     *
     * A guest is a real `users` row, not a synthetic token. That decision is
     * what makes the upgrade below lossless: because the row id never changes,
     * every foreign key the guest created — favourites, search history, view
     * history, notifications — survives the conversion untouched. A synthetic
     * guest would have forced a merge, and merging two rows means rewriting
     * every FK in the schema.
     *
     * @return array{user:array<string,mixed>,tokens:array<string,mixed>}
     */
    public static function loginAsGuest(string $ip, array $device = []): array
    {
        self::assertMethodEnabled('guest_login', false, $device + ['ip' => $ip]);

        // On top of the route throttle: a guest account costs nothing to create
        // and would otherwise be a free way to inflate the user table.
        $perIp = max(1, Config::settingInt('auth', 'guest_max_per_ip_per_day', 5));
        $key   = 'guest:' . $ip;

        if (RateLimiter::tooManyAttempts($key, $perIp)) {
            throw HttpException::tooManyRequests(
                'Too many guest sessions have been started from this network today. Please create an account.'
            );
        }

        RateLimiter::hit($key, $perIp, 86400);

        $days = max(1, Config::settingInt('auth', 'guest_session_days', 30));
        $now  = date('Y-m-d H:i:s');

        // Deliberately NOT register(): that creates a wallet, a welcome
        // notification, a referral record and — for creators — a draft profile.
        // A guest should leave no trace beyond its own row until it converts.
        $guestId = Database::insert('users', [
            'uuid'             => Crypto::uuid4(),
            'account_type'     => 'guest',
            'name'             => 'Guest',
            'status'           => 'active',
            'auth_providers'   => (string) json_encode(['guest']),
            'referral_code'    => ReferralService::generateCode(),
            'guest_expires_at' => date('Y-m-d H:i:s', time() + ($days * 86400)),
            'last_seen_at'     => $now,
            'last_login_at'    => $now,
            'last_login_ip'    => $ip,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        // The JWT role claim is 'guest', so AuthMiddleware's existing
        // account-type and verification guards reject it everywhere that
        // matters without a single new check.
        $tokens = self::issueTokens($guestId, 'guest', $device);

        SecurityService::recordLogin(
            'user',
            $guestId,
            'guest',
            SecurityService::OUTCOME_SUCCESS,
            'guest',
            null,
            '',
            $ip,
            (string) ($device['user_agent'] ?? '')
        );

        return ['user' => self::publicUser(self::loadUser($guestId)), 'tokens' => $tokens];
    }

    /**
     * Turn a guest into a real account, in place.
     *
     * Accepts any of the three identities the platform supports: a Firebase
     * token, an email/phone plus password, or a verified OTP.
     *
     * @return array{user:array<string,mixed>,tokens:array<string,mixed>}
     */
    public static function upgradeGuest(
        int $guestId,
        array $input,
        string $ip,
        array $device = [],
        string $currentJti = '',
        int $currentExp = 0
    ): array {
        $guest = self::loadUser($guestId);

        if ($guest === null || (string) $guest['account_type'] !== 'guest') {
            throw new HttpException('This account is already a full account.', 403, 'not_a_guest');
        }

        $accountType = ($input['account_type'] ?? Config::settingString('auth', 'default_signup_role', 'brand')) === 'creator'
            ? 'creator'
            : 'brand';

        $signupOpen = $accountType === 'creator'
            ? Config::settingBool('app', 'creator_signup_enabled', true)
            : Config::settingBool('app', 'brand_signup_enabled', true);

        if (!$signupOpen) {
            throw HttpException::forbidden('New ' . $accountType . ' registrations are paused. Please try again later.');
        }

        // Becoming a real account is a sign-up like any other: the terms are
        // accepted and the account has an email address, as in register().
        self::assertTermsAccepted(!empty($input['accept_terms']));

        // Said before any code is spent: a Google identity brings its own
        // address, every other way needs one typed.
        if (empty($input['id_token'])) {
            self::assertEmailPresent(trim((string) ($input['email'] ?? '')));
        }

        $identity = self::resolveUpgradeIdentity($input, $device);

        self::assertEmailPresent($identity['email']);

        // If the identity already belongs to someone else, do NOT merge. A
        // merge would have to rewrite every foreign key in the schema, and
        // getting it subtly wrong silently mixes two people's data. Telling the
        // client to offer "sign in to that account instead" is the honest
        // outcome.
        self::assertIdentifiersAvailable($identity['email'], $identity['country_code'], $identity['phone']);

        $now = date('Y-m-d H:i:s');

        $displayName = trim((string) ($input['name'] ?? '')) ?: 'Zusty User';

        Database::transaction(static function () use ($guestId, $identity, $accountType, $input, $now, $displayName): void {
            // Two kinds of column here, and conflating them is a live trap:
            //
            //   * conversion columns must be written even when the value IS
            //     null — clearing guest_expires_at is the whole point;
            //   * identity columns must be skipped when null, so an upgrade by
            //     phone does not blank an email the guest somehow already had.
            //
            // Filtering nulls across both left guests permanently expiring.
            $changes = [
                'account_type'       => $accountType,
                'name'               => $displayName,
                'auth_providers'     => (string) json_encode($identity['providers']),
                'guest_expires_at'   => null,
                'converted_at'       => $now,
                'updated_at'         => $now,
                'email_verified_at'  => $identity['email_verified'] ? $now : null,
                'phone_verified_at'  => $identity['phone_verified'] ? $now : null,
                // A guest row never had preferences; it gets a new account's.
                'notification_prefs' => self::signupPreferences(!empty($input['marketing_opt_in'])),
                'terms_accepted_at'  => $now,
            ];

            foreach ([
                'email'         => $identity['email'],
                'phone'         => $identity['phone'],
                'password_hash' => $identity['password_hash'],
                'firebase_uid'  => $identity['firebase_uid'],
            ] as $column => $value) {
                if ($value !== null) {
                    $changes[$column] = $value;
                }
            }

            if ($identity['phone'] !== null) {
                $changes['phone_country_code'] = $identity['country_code'];
            }

            Database::update('users', $changes, ['id' => $guestId]);

            // The things loginAsGuest deliberately skipped, now that there is a
            // real person behind the row.
            WalletService::forUser($guestId);

            if (!empty($input['referral_code'])) {
                $referrer = ReferralService::resolveReferrer((string) $input['referral_code']);
                ReferralService::record($guestId, $referrer !== null ? (int) $referrer['id'] : null, (string) $input['referral_code']);
            }

            if ($accountType === 'creator') {
                CreatorService::createDraftProfile($guestId, $displayName);
            }
        });

        NotificationService::send($guestId, 'account.welcome', []);

        ActivityLogger::user(
            self::loadUser($guestId),
            'guest.upgraded',
            'user',
            $guestId,
            'Guest account converted to a ' . $accountType . ' account.'
        );

        // The old tokens carry role=guest, so they must not survive the change.
        // revokeAllTokens only kills refresh tokens — an access token is a
        // stateless JWT and would otherwise stay valid for the rest of its TTL.
        // Blacklisting its jti closes that window, which matters here because
        // the account's privileges have just changed.
        self::revokeAllTokens($guestId, 'guest_upgraded');

        if ($currentJti !== '') {
            Jwt::revoke($currentJti, $guestId, $currentExp > 0 ? $currentExp : time() + 3600);
        }

        $result = self::completeLogin(self::loadUser($guestId), $ip, $device);

        SecurityService::recordLogin(
            'user',
            $guestId,
            (string) ($identity['email'] ?? $identity['phone'] ?? 'guest'),
            SecurityService::OUTCOME_SUCCESS,
            $identity['audit_method'],
            null,
            '',
            $ip,
            (string) ($device['user_agent'] ?? '')
        );

        return $result;
    }

    /**
     * Work out who the upgrading guest actually is.
     *
     * @return array<string,mixed>
     */
    private static function resolveUpgradeIdentity(array $input, array $device): array
    {
        $identity = [
            'email'          => null,
            'phone'          => null,
            'country_code'   => '+91',
            'password_hash'  => null,
            'firebase_uid'   => null,
            'providers'      => [],
            'email_verified' => false,
            'phone_verified' => false,
            'audit_method'   => 'password',
        ];

        // 1. A social or phone identity proven by Firebase.
        if (!empty($input['id_token'])) {
            $claims   = FirebaseService::verifyIdToken((string) $input['id_token']);
            $provider = (string) ($claims['provider'] ?? '');
            $method   = self::methodForFirebaseProvider($provider);

            if ($method !== null) {
                self::assertMethodEnabled($method, $method !== 'guest_login', $device);
            }

            if ($provider === 'phone') {
                self::assertPhoneSignInAllowed($device);
            }

            $identity['firebase_uid']   = (string) $claims['uid'];
            $identity['email']          = !empty($claims['email']) ? strtolower((string) $claims['email']) : null;
            $identity['email_verified'] = (bool) ($claims['email_verified'] ?? false);
            $identity['providers']      = [$provider];
            $identity['audit_method']   = self::METHOD_AUDIT[$method] ?? 'password';

            if (!empty($claims['phone_number'])) {
                [$identity['country_code'], $identity['phone']] = Str::splitPhone((string) $claims['phone_number']);
                $identity['phone_verified'] = true;
            }

            return $identity;
        }

        // 2. A phone proven by a one-time code.
        if (!empty($input['destination']) && !empty($input['code'])) {
            self::assertMethodEnabled('mobile_otp', true, $device);
            self::assertPhoneSignInAllowed($device);
            self::verifyOtp((string) $input['destination'], (string) $input['code'], 'register');

            [$identity['country_code'], $identity['phone']] = Str::splitPhone((string) $input['destination']);
            $identity['phone_verified'] = true;
            $identity['providers']      = ['phone'];
            $identity['audit_method']   = 'phone_otp';

            if (!empty($input['email'])) {
                $identity['email'] = strtolower(trim((string) $input['email']));
            }

            if (!empty($input['password'])) {
                $identity['password_hash'] = Crypto::hashPassword((string) $input['password']);
                $identity['providers'][]   = 'password';
            }

            return $identity;
        }

        // 3. An email or phone with a password.
        if (!empty($input['password']) && (!empty($input['email']) || !empty($input['phone']))) {
            self::assertMethodEnabled('email_password', true, $device);

            $minLength = Config::settingInt('security', 'password_min_length', 8);

            if (mb_strlen((string) $input['password']) < $minLength) {
                throw HttpException::unprocessable('Choose a password of at least ' . $minLength . ' characters.');
            }

            if (!empty($input['email'])) {
                $identity['email'] = strtolower(trim((string) $input['email']));
            }

            if (!empty($input['phone'])) {
                [$identity['country_code'], $identity['phone']] = Str::splitPhone((string) $input['phone']);
            }

            $identity['password_hash'] = Crypto::hashPassword((string) $input['password']);
            $identity['providers']     = ['password'];

            return $identity;
        }

        throw HttpException::unprocessable(
            'Provide a Firebase token, a verified one-time code, or an email or phone with a password.'
        );
    }

    /**
     * Soft-delete guest rows that never became accounts and have gone quiet.
     *
     * Soft, not hard: a guest may have left reviews, reports or messages that
     * other rows still reference, and the recycle bin is the established way to
     * make a removal reversible.
     *
     * @return array{purged:int,retention_days:int}
     */
    public static function purgeGuests(): array
    {
        $days = max(1, Config::settingInt('auth', 'guest_purge_days', 90));

        $purged = Database::execute(
            "UPDATE `users`
                SET `deleted_at` = NOW()
              WHERE `account_type` = 'guest'
                AND `converted_at` IS NULL
                AND `deleted_at` IS NULL
                AND (`last_seen_at` IS NULL OR `last_seen_at` < DATE_SUB(NOW(), INTERVAL :days DAY))",
            ['days' => $days]
        );

        return ['purged' => $purged, 'retention_days' => $days];
    }

    private static function completeLogin(array $user, string $ip, array $device): array
    {
        $userId = (int) $user['id'];

        Database::update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
            'last_seen_at'  => date('Y-m-d H:i:s'),
        ], ['id' => $userId]);

        $tokens = self::issueTokens($userId, (string) $user['account_type'], $device);

        return ['user' => self::publicUser(self::loadUser($userId)), 'tokens' => $tokens];
    }

    // -----------------------------------------------------------------
    // Tokens
    // -----------------------------------------------------------------

    /**
     * @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,expires_at:string}
     */
    public static function issueTokens(int $userId, string $accountType, array $device = []): array
    {
        $accessTtl  = Config::settingInt('security', 'jwt_access_ttl', (int) Config::get('jwt.access_ttl', 3600));
        $refreshTtl = Config::settingInt('security', 'jwt_refresh_ttl', (int) Config::get('jwt.refresh_ttl', 2592000));

        $accessToken  = Jwt::issueAccessToken($userId, $accountType);
        $refreshToken = Crypto::randomToken(48);

        Database::insert('refresh_tokens', [
            'user_id'     => $userId,
            'token_hash'  => Crypto::hashToken($refreshToken),
            'device_id'   => $device['device_id'] ?? null,
            'device_name' => $device['device_name'] ?? null,
            'platform'    => in_array($device['platform'] ?? '', ['android', 'ios', 'web'], true) ? $device['platform'] : 'unknown',
            'ip_address'  => $device['ip'] ?? null,
            'user_agent'  => isset($device['user_agent']) ? mb_substr((string) $device['user_agent'], 0, 255) : null,
            'expires_at'  => date('Y-m-d H:i:s', time() + $refreshTtl),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $accessTtl,
            'expires_at'    => date('c', time() + $accessTtl),
        ];
    }

    /**
     * Rotate a refresh token.
     *
     * Reuse detection: if a token that was already rotated is presented
     * again, the whole family is revoked — that is the standard mitigation
     * for a stolen refresh token.
     */
    public static function refresh(string $refreshToken, array $device = []): array
    {
        $hash = Crypto::hashToken($refreshToken);

        $stored = Database::selectOne(
            'SELECT * FROM `refresh_tokens` WHERE `token_hash` = :hash',
            ['hash' => $hash]
        );

        if ($stored === null) {
            throw HttpException::unauthorized('Invalid refresh token.', 'invalid_refresh_token');
        }

        if ($stored['revoked_at'] !== null) {
            Logger::warning('Refresh token reuse detected — revoking the family.', [
                'user_id'  => $stored['user_id'],
                'token_id' => $stored['id'],
            ]);

            self::revokeAllTokens((int) $stored['user_id'], 'refresh_token_reuse');

            throw HttpException::unauthorized('This session has been revoked. Please sign in again.', 'token_revoked');
        }

        if (strtotime((string) $stored['expires_at']) <= time()) {
            throw HttpException::unauthorized('Your session has expired. Please sign in again.', 'refresh_token_expired');
        }

        $user = self::loadUser((int) $stored['user_id']);

        self::assertLoginAllowed($user);

        return Database::transaction(static function () use ($stored, $user, $device): array {
            Database::update('refresh_tokens', [
                'revoked_at'     => date('Y-m-d H:i:s'),
                'revoked_reason' => 'rotated',
                'last_used_at'   => date('Y-m-d H:i:s'),
            ], ['id' => (int) $stored['id']]);

            $tokens = self::issueTokens((int) $user['id'], (string) $user['account_type'], $device);

            Database::execute(
                'UPDATE `refresh_tokens` SET `parent_id` = :parent WHERE `token_hash` = :hash',
                ['parent' => (int) $stored['id'], 'hash' => Crypto::hashToken($tokens['refresh_token'])]
            );

            return ['user' => self::publicUser($user), 'tokens' => $tokens];
        });
    }

    /** Sign out one device. */
    public static function logout(
        int $userId,
        ?string $refreshToken,
        ?string $jti,
        int $tokenExpiry,
        ?string $deviceId = null
    ): void {
        if ($refreshToken !== null && $refreshToken !== '') {
            // The client handed us the exact token to kill.
            Database::execute(
                "UPDATE `refresh_tokens`
                 SET `revoked_at` = NOW(), `revoked_reason` = 'logout'
                 WHERE `token_hash` = :hash AND `user_id` = :user_id AND `revoked_at` IS NULL",
                ['hash' => Crypto::hashToken($refreshToken), 'user_id' => $userId]
            );
        } elseif ($deviceId !== null && $deviceId !== '') {
            // The app calls logout with only its access token and its device
            // header — never the refresh token in the body. Without this
            // branch the refresh token survived logout and stayed valid for
            // its full 30-day life, so a captured token still minted access
            // tokens after the user had signed out. Revoking by device logs
            // this session out server-side without touching the user's other
            // devices (that is what logout-all is for).
            Database::execute(
                "UPDATE `refresh_tokens`
                 SET `revoked_at` = NOW(), `revoked_reason` = 'logout'
                 WHERE `user_id` = :user_id AND `device_id` = :device_id AND `revoked_at` IS NULL",
                ['user_id' => $userId, 'device_id' => $deviceId]
            );
        }

        // Blacklist the current access token so it dies immediately rather
        // than lingering until its natural expiry.
        if ($jti !== null && $jti !== '' && $tokenExpiry > time()) {
            Jwt::revoke($jti, $userId, $tokenExpiry);
        }
    }

    /**
     * Sign out everywhere (password change, suspected compromise).
     *
     * Live data-export links die with the sessions: a link is as good as a
     * session for reading the account's data, and every reason to sign out
     * everywhere is a reason to stop trusting what was handed out before.
     * The archive stays, so the owner can get a new link with step-up.
     *
     * @return int the refresh tokens revoked
     */
    public static function revokeAllTokens(int $userId, string $reason = 'logout_all'): int
    {
        $revoked = Database::execute(
            'UPDATE `refresh_tokens`
             SET `revoked_at` = NOW(), `revoked_reason` = :reason
             WHERE `user_id` = :user_id AND `revoked_at` IS NULL',
            ['reason' => $reason, 'user_id' => $userId]
        );

        DataExportService::revokeLinks($userId);

        return $revoked;
    }

    // -----------------------------------------------------------------
    // Password management
    // -----------------------------------------------------------------

    /** Start a reset. Always reports success so emails cannot be enumerated. */
    public static function requestPasswordReset(string $email, string $ip): void
    {
        // Returns silently rather than 403, matching the unknown-address branch
        // below. A 403 here would tell an attacker the address exists whenever
        // recovery happened to be switched off.
        if (!AuthConfigService::enabled('email_password', true)
            || !Config::settingBool('auth', 'forgot_password_enabled', true)) {
            Logger::info('Password reset requested while the method is disabled.', ['ip' => $ip]);

            return;
        }

        $user = Database::selectOne(
            'SELECT * FROM `users` WHERE `email` = :email AND `deleted_at` IS NULL',
            ['email' => strtolower(trim($email))]
        );

        if ($user === null) {
            Logger::info('Password reset requested for an unknown address.', ['ip' => $ip]);

            return;
        }

        $token = Crypto::randomToken(32);

        // Was hardcoded to one hour while the app's own copy told users
        // "30 minutes". Now one operator-visible number drives both the link
        // and what the apps say about it.
        $ttlMinutes = max(5, min(1440, Config::settingInt('auth', 'reset_token_ttl_minutes', 60)));

        Database::insert('password_resets', [
            'user_id'    => (int) $user['id'],
            'token_hash' => Crypto::hashToken($token),
            'expires_at' => date('Y-m-d H:i:s', time() + ($ttlMinutes * 60)),
            'ip_address' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $resetUrl = rtrim((string) Config::get('app.url'), '/') . '/reset-password?token=' . urlencode($token);

        $subject = 'Reset your Zusty password';
        $bodyHtml = MailService::renderDefault(
            'Reset your password',
            'We received a request to reset your password. This link is valid for '
            . ($ttlMinutes >= 60
                ? (int) round($ttlMinutes / 60) . ' hour' . ((int) round($ttlMinutes / 60) === 1 ? '' : 's')
                : $ttlMinutes . ' minutes')
            . '. If you did not request it, you can safely ignore this email — your password will not change.',
            (string) $user['name'],
            'url',
            $resetUrl
        );

        // sendNow() swallows every delivery failure and returns false, and on
        // an SMTP failure it has already put the message in the queue, where
        // drainQueue() retries it with backoff. So this only records that it
        // went the slow way: queueing it again here sent the link twice.
        if (!MailService::sendNow((string) $user['email'], (string) $user['name'], $subject, $bodyHtml)) {
            Logger::warning('Password reset email could not be sent immediately; it waits in the mail queue.', [
                'user_id' => (int) $user['id'],
            ]);
        }
    }

    /** Complete a reset and invalidate every existing session. */
    public static function resetPassword(string $token, string $newPassword): void
    {
        // A hard refusal here, unlike the request step: the caller already holds
        // a valid token, so there is no enumeration to protect against.
        self::assertMethodEnabled('email_password');

        $record = Database::selectOne(
            'SELECT * FROM `password_resets`
             WHERE `token_hash` = :hash AND `used_at` IS NULL AND `expires_at` > NOW()',
            ['hash' => Crypto::hashToken($token)]
        );

        if ($record === null) {
            throw HttpException::unprocessable('This reset link is invalid or has expired.', 'invalid_reset_token');
        }

        $userId = (int) $record['user_id'];

        Database::transaction(static function () use ($record, $newPassword, $userId): void {
            Database::update('users', [
                'password_hash'  => Crypto::hashPassword($newPassword),
                // The emailed link proved the mailbox, so this password may
                // confirm privacy actions from now on.
                'auth_providers' => self::providersWith($userId, 'password'),
            ], ['id' => $userId]);

            Database::update('password_resets', ['used_at' => date('Y-m-d H:i:s')], ['id' => (int) $record['id']]);

            self::revokeAllTokens($userId, 'password_reset');
        });

        $user = Database::selectOne(
            'SELECT `email`, `name` FROM `users` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $userId]
        );

        if ($user !== null) {
            self::queuePasswordNotice($user, false);
        }
    }

    /**
     * Change a password while signed in, or set a first one.
     *
     * Changing needs the current password. Setting a first one needs proof
     * made on the spot: a code to the account's own email or phone, or a
     * fresh Google or Apple sign-in. A password is a way back in, so a session
     * that could set one with no proof could give a stolen account a password
     * the thief knows, and then use it to confirm a deletion or an export.
     *
     * @param array<string,mixed> $proof   `code` with `channel`, or `id_token`; a password is never proof here
     * @param array<string,mixed> $context BaseApiController::deviceContext(): `ip`, `user_agent`
     */
    public static function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        array $proof = [],
        array $context = []
    ): void {
        $user        = self::loadUser($userId);
        $hadPassword = !empty($user['password_hash']);

        if ($hadPassword) {
            if (!Crypto::verifyPassword($currentPassword, (string) $user['password_hash'])) {
                throw HttpException::unprocessable('Your current password is incorrect.', 'invalid_current_password');
            }
        } else {
            // An account created purely through Google, Apple or a phone code
            // has no password yet, so one is being set rather than changed.
            $options = PrivacyService::verificationOptions($user);

            if ($options['channels'] === [] && $options['providers'] === []) {
                $held = array_values(array_filter(
                    array_column(
                        array_filter(
                            $options['unavailable'],
                            static fn (array $entry): bool => $entry['reason'] === 'contact_recently_changed'
                        ),
                        'available_at'
                    )
                ));

                throw HttpException::unprocessable(
                    $held !== []
                        ? 'Your email address or mobile number changed recently, so we can\'t confirm it\'s you until '
                            . date('j M Y, g:i A', (int) strtotime((string) min($held))) . '. Try again then.'
                        : "We can't confirm it's you here. Contact support to add a password.",
                    'reauth_unavailable'
                );
            }

            PrivacyService::verify($user, PrivacyService::PURPOSE_SET_PASSWORD, $proof, $context);
        }

        Database::transaction(static function () use ($userId, $newPassword, $hadPassword): void {
            $updates = ['password_hash' => Crypto::hashPassword($newPassword)];

            // Set with proof, so it may confirm privacy actions from now on. A
            // change keeps whatever standing the old password had: knowing a
            // password that was set without proof proves nothing more.
            if (!$hadPassword) {
                $updates['auth_providers'] = self::providersWith($userId, 'password');
            }

            Database::update('users', $updates, ['id' => $userId]);

            self::revokeAllTokens($userId, 'password_changed');
        });

        self::queuePasswordNotice($user, !$hadPassword);
    }

    /**
     * The account's auth_providers JSON with one more provider in it. Locks the
     * row, so call it inside the transaction that writes the result.
     */
    private static function providersWith(int $userId, string $provider): string
    {
        $current = json_decode((string) (Database::scalar(
            'SELECT `auth_providers` FROM `users` WHERE `id` = :id FOR UPDATE',
            ['id' => $userId]
        ) ?? ''), true);

        $providers = is_array($current)
            ? array_values(array_filter(array_map('strval', $current), static fn (string $p): bool => $p !== ''))
            : [];

        if (!in_array($provider, $providers, true)) {
            $providers[] = $provider;
        }

        return (string) json_encode(array_values($providers), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Tell the account's own address that its password was added or changed,
     * so an owner who did not do it finds out.
     *
     * @param array<string,mixed> $user `email`, `name`
     */
    private static function queuePasswordNotice(array $user, bool $added): void
    {
        $email = trim((string) ($user['email'] ?? ''));

        if ($email === '') {
            return;
        }

        $name    = (string) ($user['name'] ?? '');
        $support = MailService::supportAddress();
        $subject = $added ? 'A password was added to your Zusty account' : 'Your Zusty password was changed';
        $body    = ($added
                ? 'A password was just added to your Zusty account, and every device was signed out.'
                : 'The password for your Zusty account was just changed, and every device was signed out.')
            . " If this wasn't you, contact " . $support . ' straight away.';

        MailService::queue(
            $email,
            $name,
            $subject,
            MailService::renderDefault($subject, $body, $name),
            null,
            $support !== '' ? $support : null
        );
    }

    // -----------------------------------------------------------------
    // OTP
    // -----------------------------------------------------------------

    /**
     * Issue an OTP to a phone or email.
     *
     * The new code is written first and delivered second. Only once it has
     * actually gone out are the earlier live codes for the same destination
     * and purpose expired; a failed delivery deletes the new row instead, so
     * the previous code stays the newest live one and keeps working, and a
     * failing send can never be used to expire somebody else's code.
     *
     * @return array{expires_in:int,length:int,channel:string,resend_in:int,otp_id:int}
     *         otp_id is for callers that bind a code to a request; never send it to a client.
     */
    public static function sendOtp(string $destination, string $channel, string $purpose, ?int $userId = null): array
    {
        // A step-up code must belong to the account that asked for it. Only the
        // signed-in privacy endpoint issues these, and it always says whose.
        if ($userId === null && in_array($purpose, PrivacyService::PURPOSES, true)) {
            throw HttpException::unprocessable('Unknown verification purpose.', 'invalid_purpose');
        }

        // Only the `login` purpose is gated on the OTP method. Codes sent to
        // verify an email or phone, or to confirm account deletion, are a
        // verification channel rather than a way in — gating those would break
        // email verification for a Google-only account, and would strand
        // anyone trying to close their account.
        if ($purpose === 'login') {
            self::assertMethodEnabled('mobile_otp', true, ['identifier' => $destination], $userId);
        }

        // SMS delivery itself belongs to the OTP method whatever the purpose:
        // if an operator has switched it off, no code should go out by SMS.
        if ($channel === 'sms') {
            self::assertMethodEnabled('mobile_otp', true, ['identifier' => $destination], $userId);
            self::assertOtpPhoneAllowed($destination);

            // With SMS delivery off (Settings → SMS) a text can never arrive.
            // Said up front, before a code row is written or the send limits
            // are spent, rather than as a delivery failure after the fact.
            if (!SmsService::enabled()) {
                throw new HttpException(
                    "Codes can't be sent by text message. Use your email address.",
                    422,
                    'channel_not_allowed',
                    ['channel' => ['This way of receiving a code is not available.']]
                );
            }
        }

        // Two limits per destination and purpose, whoever is asking: a cooldown
        // between codes, and a ceiling on codes per window. Without them the
        // public send endpoint could pump codes at one number or mailbox from
        // as many IPs as an attacker has.
        $address  = strtolower(trim($destination));
        $cooldown = max(0, Config::settingInt('auth', 'otp_resend_seconds', 45));

        if ($cooldown > 0) {
            $gate = RateLimiter::hit('otp:cooldown:' . $purpose . ':' . $address, 1, $cooldown);

            if (!$gate['allowed']) {
                throw HttpException::tooManyRequests(
                    'Wait ' . $gate['retry_after'] . ' seconds before asking for another code.',
                    $gate['retry_after'],
                    'otp_cooldown'
                );
            }
        }

        $ceiling = RateLimiter::hit(
            'otp:send:' . $purpose . ':' . $address,
            1 + max(0, Config::settingInt('auth', 'otp_max_resends', 3)),
            60 * max(1, Config::settingInt('security', 'otp_send_window_minutes', 60))
        );

        if (!$ceiling['allowed']) {
            throw HttpException::tooManyRequests(
                'Too many codes have been sent to this address. Try again later.',
                $ceiling['retry_after'],
                'otp_send_limit'
            );
        }

        $length  = Config::settingInt('security', 'otp_length', 6);
        $ttl     = Config::settingInt('security', 'otp_ttl', 300);
        $code    = Crypto::randomOtp($length);

        $otpId = Database::insert('otp_verifications', [
            'user_id'     => $userId,
            'channel'     => $channel,
            'destination' => $destination,
            'purpose'     => $purpose,
            'code_hash'   => Crypto::hashToken($code),
            'expires_at'  => date('Y-m-d H:i:s', time() + $ttl),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $minutes = max(1, (int) round($ttl / 60));

        if ($channel === 'sms') {
            // sendNow() returns false when SMS is switched off or the provider
            // rejected the message. That boolean used to be discarded, so the
            // API answered "Verification code sent." while nothing had been
            // sent and the user sat waiting for a code that did not exist.
            $sent = SmsService::sendNow(
                $destination,
                sprintf('%s is your Zusty verification code. It expires in %d minutes. Do not share it with anyone.', $code, $minutes)
            );
        } else {
            // No fallback copy in the queue: a code that turns up after the
            // app has said it could not be sent is worse than none, and by
            // then a newer code may have replaced it.
            $sent = MailService::sendNow(
                $destination,
                '',
                $purpose === 'login' ? 'Your Zusty sign-in code' : 'Your Zusty verification code',
                MailService::renderOtp($code, $purpose, $minutes),
                null,
                null,
                false
            );
        }

        if (!$sent) {
            Database::execute('DELETE FROM `otp_verifications` WHERE `id` = :id', ['id' => $otpId]);

            throw new HttpException(
                'We could not send the verification code right now. Please try again shortly.',
                503,
                'otp_delivery_failed'
            );
        }

        // Delivered, so the earlier live codes for this destination and purpose
        // are retired: only the newest one may ever be redeemed.
        Database::execute(
            'UPDATE `otp_verifications` SET `expires_at` = NOW()
             WHERE `destination` = :destination AND `purpose` = :purpose AND `verified_at` IS NULL AND `id` <> :id',
            ['destination' => $destination, 'purpose' => $purpose, 'id' => $otpId]
        );

        return [
            'expires_in' => $ttl,
            'length'     => $length,
            'channel'    => $channel,
            'resend_in'  => $cooldown,
            'otp_id'     => $otpId,
        ];
    }

    /**
     * Why the operator's OTP phone rules (auth.otp_allowed_country_codes,
     * auth.otp_phone_digits) refuse to text a code to this number, or null
     * when they do not. PrivacyService asks too, so it never offers an SMS
     * channel that sendOtp() would refuse.
     *
     * @return 'phone_country_not_supported'|'invalid_phone'|null
     */
    public static function otpPhoneRefusal(string $destination): ?string
    {
        $defaultCode = Config::settingString('auth', 'otp_default_country_code', '+91');

        [$countryCode, $national] = Str::splitPhone($destination, $defaultCode);

        $allowed = array_values(array_filter(
            array_map('strval', Config::settingArray('auth', 'otp_allowed_country_codes', [])),
            static fn (string $code): bool => $code !== ''
        ));

        if ($allowed !== [] && !in_array($countryCode, $allowed, true)) {
            return 'phone_country_not_supported';
        }

        $digits = Config::settingInt('auth', 'otp_phone_digits', 10);

        if ($countryCode === $defaultCode && $digits > 0 && strlen($national) !== $digits) {
            return 'invalid_phone';
        }

        return null;
    }

    /** Refuse an SMS code the operator's OTP phone rules do not allow. */
    private static function assertOtpPhoneAllowed(string $destination): void
    {
        $refusal = self::otpPhoneRefusal($destination);

        if ($refusal === null) {
            return;
        }

        $message = $refusal === 'phone_country_not_supported'
            ? "Codes can't be sent to numbers in that country."
            : 'Enter a valid mobile number.';

        throw new HttpException($message, 422, $refusal, ['destination' => [$message]]);
    }

    /**
     * Verify an OTP. Returns the matched row so the caller can act on it.
     *
     * Both writes are conditional UPDATEs whose row count decides the outcome,
     * so two requests racing with the same code cannot both get past the
     * attempt cap or both redeem it: the database serialises them on the row.
     *
     * $userId binds the lookup to the account the code was issued for. Step-up
     * and contact-change codes always carry it; the public sign-up codes do
     * not, because the account may not exist yet.
     */
    public static function verifyOtp(string $destination, string $code, string $purpose, ?int $userId = null): array
    {
        // As in sendOtp: only sign-in is gated. Verification and deletion codes
        // must keep working for accounts that never use OTP to sign in.
        if ($purpose === 'login') {
            self::assertMethodEnabled('mobile_otp', true, ['identifier' => $destination]);
        }

        $bindings = ['destination' => $destination, 'purpose' => $purpose];

        if ($userId !== null) {
            $bindings['uid'] = $userId;
        }

        // The newest code, used or not. sendOtp() expires every other live code
        // once a new one is delivered, so when the newest has been redeemed
        // there is no live code at all, and "already used" is the honest answer.
        $record = Database::selectOne(
            'SELECT * FROM `otp_verifications`
             WHERE `destination` = :destination AND `purpose` = :purpose'
                . ($userId !== null ? ' AND `user_id` = :uid' : '') . '
             ORDER BY `id` DESC LIMIT 1',
            $bindings
        );

        if ($record === null) {
            throw HttpException::unprocessable('No verification code was requested for this destination.', 'otp_not_found');
        }

        if ($record['verified_at'] !== null) {
            throw self::otpUsed();
        }

        if (strtotime((string) $record['expires_at']) <= time()) {
            throw HttpException::unprocessable('This code has expired. Request a new one.', 'otp_expired');
        }

        $claimed = Database::execute(
            'UPDATE `otp_verifications` SET `attempts` = `attempts` + 1
              WHERE `id` = :id AND `verified_at` IS NULL AND `attempts` < `max_attempts` AND `expires_at` > NOW()',
            ['id' => (int) $record['id']]
        );

        if ($claimed === 0) {
            $current = Database::selectOne(
                'SELECT `verified_at`, (`expires_at` <= NOW()) AS `expired` FROM `otp_verifications` WHERE `id` = :id',
                ['id' => (int) $record['id']]
            );

            if ($current === null) {
                throw HttpException::unprocessable('No verification code was requested for this destination.', 'otp_not_found');
            }

            if ((int) $current['expired'] === 1) {
                throw HttpException::unprocessable('This code has expired. Request a new one.', 'otp_expired');
            }

            if ($current['verified_at'] !== null) {
                throw self::otpUsed();
            }

            throw HttpException::tooManyRequests('Too many incorrect attempts. Request a new code.', 60, 'otp_attempts_exceeded');
        }

        if (!Crypto::secureEquals((string) $record['code_hash'], Crypto::hashToken($code))) {
            throw HttpException::unprocessable('The verification code is incorrect.', 'otp_invalid');
        }

        $consumed = Database::execute(
            'UPDATE `otp_verifications` SET `verified_at` = NOW() WHERE `id` = :id AND `verified_at` IS NULL',
            ['id' => (int) $record['id']]
        );

        if ($consumed === 0) {
            throw self::otpUsed();
        }

        return $record;
    }

    private static function otpUsed(): HttpException
    {
        return HttpException::unprocessable('This code has already been used. Request a new one.', 'otp_used');
    }

    // -----------------------------------------------------------------
    // Account lifecycle
    // -----------------------------------------------------------------

    /**
     * Close a guest account.
     *
     * A guest never gave a name, email or phone, so closing one is a
     * soft-delete with its sessions revoked. A signed-up account is not
     * closed here. It goes through {@see AccountDeletionService::delete()},
     * which erases its personal data. This used to be that path too, and it
     * only hid the row, leaving the name, contact details, profile and files
     * of a "deleted" account in place.
     */
    public static function closeGuest(int $userId): void
    {
        $user = self::loadUser($userId);

        if (($user['account_type'] ?? '') !== 'guest') {
            throw new \LogicException('A registered account is deleted through AccountDeletionService::delete().');
        }

        Database::transaction(static function () use ($userId): void {
            Database::update('users', [
                'status'                => 'deleted',
                'deletion_requested_at' => date('Y-m-d H:i:s'),
                'deleted_at'            => date('Y-m-d H:i:s'),
            ], ['id' => $userId]);

            self::revokeAllTokens($userId, 'account_deleted');

            Database::execute('DELETE FROM `user_devices` WHERE `user_id` = :id', ['id' => $userId]);
        });

        ActivityLogger::log('account.deleted', 'user', $userId, 'Guest account closed.', [], 'user', $userId);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public static function loadUser(int $userId): array
    {
        $user = Database::selectOne(
            'SELECT u.*, c.`id` AS `creator_id`, c.`handle` AS `creator_handle`,
                    c.`status` AS `creator_status`, c.`is_verified` AS `creator_verified`
             FROM `users` u
             LEFT JOIN `creators` c ON c.`user_id` = u.`id` AND c.`deleted_at` IS NULL
             WHERE u.`id` = :id AND u.`deleted_at` IS NULL',
            ['id' => $userId]
        );

        if ($user === null) {
            throw HttpException::notFound('Account not found.');
        }

        return $user;
    }

    /** @return array<string,mixed>|null */
    public static function findByIdentifier(string $identifier): ?array
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            return Database::selectOne(
                'SELECT * FROM `users` WHERE `email` = :email AND `deleted_at` IS NULL',
                ['email' => strtolower($identifier)]
            );
        }

        [$countryCode, $phone] = Str::splitPhone($identifier);

        if ($phone === '') {
            return null;
        }

        // Match with or without the country code so users can type either.
        return Database::selectOne(
            'SELECT * FROM `users`
             WHERE `phone` = :phone AND (`phone_country_code` = :cc OR :cc = "")
               AND `deleted_at` IS NULL
             LIMIT 1',
            ['phone' => $phone, 'cc' => $countryCode]
        );
    }

    /**
     * Strip everything the client must never see.
     *
     * @param  array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function publicUser(array $user): array
    {
        // Read before the hash is stripped: the app offers "Set a password" or
        // "Change password" from this.
        $user['has_password'] = !empty($user['password_hash']);

        unset(
            $user['password_hash'],
            $user['last_login_ip'],
            $user['suspended_reason'],
            $user['deleted_at']
        );

        $user['id']                = (int) $user['id'];
        $user['is_creator']        = !empty($user['creator_id']);
        $user['creator_id']        = isset($user['creator_id']) ? (int) $user['creator_id'] : null;
        $user['email_verified']    = !empty($user['email_verified_at']);
        $user['phone_verified']    = !empty($user['phone_verified_at']);
        $user['full_phone']        = ($user['phone'] ?? null) !== null
            ? (string) $user['phone_country_code'] . (string) $user['phone']
            : null;
        $user['auth_providers']    = json_decode((string) ($user['auth_providers'] ?? '[]'), true) ?: [];
        // Every key, so the app never has to guess one: a key the account has
        // never set reads as its default, and marketing's default is off.
        $storedPrefs                = json_decode((string) ($user['notification_prefs'] ?? '{}'), true);
        $user['notification_prefs'] = array_merge(
            NotificationService::DEFAULT_PREFERENCES,
            is_array($storedPrefs) ? $storedPrefs : []
        );
        // Whether the blue tick freezes the public name. It is here, not only
        // on GET/PUT /me, because the app restores its user from /auth/me at
        // startup and must show the name read-only from the first frame.
        $user['identity_locked']    = IdentityLockService::isLocked($user['id']);

        return $user;
    }

    /**
     * A new account's notification_prefs JSON: the defaults, with marketing on
     * only when the person ticked the opt-in.
     */
    private static function signupPreferences(bool $marketingOptIn): string
    {
        return (string) json_encode(
            array_merge(NotificationService::DEFAULT_PREFERENCES, ['marketing' => $marketingOptIn]),
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Refuse to create an account whose Terms and Privacy Policy acceptance
     * is missing. Google Play's user-generated content policy needs it on
     * every way an account is made, a Google sign-up included.
     */
    private static function assertTermsAccepted(bool $accepted): void
    {
        if ($accepted) {
            return;
        }

        $message = 'Accept the Terms & Conditions and Privacy Policy to create your account.';

        throw new HttpException($message, 422, 'terms_required', ['accept_terms' => [$message]]);
    }

    /**
     * Refuse to create an account without an email address. SMS is off, so a
     * number could never be verified, and the email is how the account is
     * reached, recovered and signed in to with a code.
     */
    private static function assertEmailPresent(?string $email): void
    {
        if ($email !== null && $email !== '') {
            return;
        }

        $message = 'Enter your email address. Every Zusty account is created with one.';

        throw new HttpException($message, 422, 'email_required', ['email' => [$message]]);
    }

    private static function assertIdentifiersAvailable(?string $email, string $countryCode, ?string $phone): void
    {
        if ($email !== null) {
            $taken = Database::scalar(
                'SELECT 1 FROM `users` WHERE `email` = :email AND `deleted_at` IS NULL LIMIT 1',
                ['email' => $email]
            );

            if ($taken !== null) {
                throw new HttpException(
                    'An account already exists with this email address.',
                    422,
                    'email_taken',
                    ['email' => ['An account already exists with this email address.']]
                );
            }
        }

        if ($phone !== null) {
            $taken = Database::scalar(
                'SELECT 1 FROM `users` WHERE `phone_country_code` = :cc AND `phone` = :phone AND `deleted_at` IS NULL LIMIT 1',
                ['cc' => $countryCode, 'phone' => $phone]
            );

            if ($taken !== null) {
                throw new HttpException(
                    'An account already exists with this phone number.',
                    422,
                    'phone_taken',
                    ['phone' => ['An account already exists with this phone number.']]
                );
            }
        }
    }

    /** @param array<string,mixed> $user */
    private static function assertLoginAllowed(array $user): void
    {
        $status = (string) $user['status'];

        if ($status === 'active') {
            return;
        }

        throw match ($status) {
            'suspended' => new HttpException(
                'Your account is suspended. ' . (string) ($user['suspended_reason'] ?? 'Contact support for assistance.'),
                403,
                'account_suspended'
            ),
            'banned'  => new HttpException('Your account has been permanently closed.', 403, 'account_banned'),
            'deleted' => new HttpException('This account has been deleted.', 403, 'account_deleted'),
            default   => new HttpException('Your account is not active yet.', 403, 'account_inactive'),
        };
    }

    // -----------------------------------------------------------------
    // Sign-in method gates
    //
    // An admin toggle that only hides a button is not a control — anyone can
    // still call the endpoint. These are the server-side half, placed so that
    // no sign-in path can reach an account lookup without passing one.
    // -----------------------------------------------------------------

    /**
     * Firebase `sign_in_provider` => the login method key that governs it.
     *
     * `custom` and anything unrecognised map to null and stay ungated, which is
     * today's behaviour — this table can only ever add restrictions.
     */
    private const FIREBASE_METHOD_MAP = [
        'google.com'   => 'google_login',
        'apple.com'    => 'apple_login',
        'facebook.com' => 'facebook_login',
        'phone'        => 'mobile_otp',
        'password'     => 'email_password',
        'anonymous'    => 'guest_login',
    ];

    /** How each method is named when it is refused. */
    private const METHOD_LABELS = [
        'email_password' => 'Email and password sign-in',
        'mobile_otp'     => 'OTP sign-in',
        'google_login'   => 'Google Sign-In',
        'apple_login'    => 'Sign in with Apple',
        'facebook_login' => 'Facebook Login',
        'guest_login'    => 'Guest access',
    ];

    /** How each method is recorded in `login_history`.`auth_method`. */
    private const METHOD_AUDIT = [
        'email_password' => 'password',
        'mobile_otp'     => 'phone_otp',
        'google_login'   => 'google',
        'apple_login'    => 'apple',
        'facebook_login' => 'facebook',
        'guest_login'    => 'guest',
    ];

    private static function methodForFirebaseProvider(string $provider): ?string
    {
        return self::FIREBASE_METHOD_MAP[$provider] ?? null;
    }

    /**
     * Verify a Firebase ID token without leaking how the server is configured.
     *
     * FirebaseService throws HttpException::server() when the project id or the
     * signing certificate is missing, and the message names an admin-panel
     * navigation path ("Set the project id in Admin → Settings → Firebase").
     * That reached unauthenticated app clients as a 500 `server_error`, which
     * both hands out an internal configuration instruction and reports a known
     * configuration state as a crash.
     *
     * Only server-side failures are translated. A 401 for a malformed, expired
     * or wrongly-signed token is the honest answer to a bad token and passes
     * through unchanged, so nothing about the normal contract moves.
     *
     * @param  array<string,mixed> $device
     * @return array<string,mixed>
     */
    private static function verifyFirebaseToken(string $idToken, string $ip, array $device): array
    {
        try {
            return FirebaseService::verifyIdToken($idToken);
        } catch (HttpException $e) {
            if ($e->status() < 500) {
                throw $e;
            }

            self::reportFirebaseUnavailable($e, $ip, $device);
        } catch (\Throwable $e) {
            // Missing service-account credentials surface as a RuntimeException,
            // and a certificate fetch can fail on the network. Neither is the
            // caller's fault and neither should read as a crash.
            self::reportFirebaseUnavailable($e, $ip, $device);
        }
    }

    /** @param array<string,mixed> $device */
    private static function reportFirebaseUnavailable(\Throwable $e, string $ip, array $device): never
    {
        Logger::error('Firebase token verification is unavailable', ['error' => $e->getMessage()]);

        self::auditLogin(
            SecurityService::OUTCOME_PROVIDER_ERROR,
            'google',
            (string) ($device['identifier'] ?? ''),
            null,
            $device,
            $ip,
            'Firebase verification unavailable.'
        );

        throw new HttpException(
            'Social sign-in is temporarily unavailable. Please try another way to sign in.',
            503,
            'provider_unavailable'
        );
    }

    /**
     * Append an app-user sign-in attempt to `login_history`.
     *
     * Every sign-in path through this service used to be invisible to the
     * audit trail: only guest starts, guest upgrades and method_disabled
     * refusals were recorded, so /admin/login-methods and the Security Centre
     * showed a permanent zero for password and social activity and a
     * brute-force campaign left no database trace at all. Failed attempts went
     * to the file log only.
     *
     * A thin wrapper rather than seven copies of the nine-argument call, and
     * recordLogin() itself never throws — auditing must not be able to turn a
     * good sign-in into a 500 or a bad one into an account-existence oracle.
     *
     * @param array<string,mixed> $device
     */
    private static function auditLogin(
        string $outcome,
        string $authMethod,
        string $identifier,
        ?int $userId = null,
        array $device = [],
        string $ip = '',
        string $reason = ''
    ): void {
        SecurityService::recordLogin(
            'user',
            $userId,
            $identifier,
            $outcome,
            $authMethod,
            null,
            $reason,
            $ip !== '' ? $ip : (string) ($device['ip'] ?? ''),
            (string) ($device['user_agent'] ?? '')
        );
    }

    /**
     * Refuse the request when an operator has switched this method off.
     *
     * @param bool $default what to do when the method has no row at all —
     *                      callers pass the behaviour the API had before this
     *                      module existed, so nothing changes for an install
     *                      that has not been seeded
     */
    private static function assertMethodEnabled(string $key, bool $default = true, array $device = [], ?int $userId = null): void
    {
        if (AuthConfigService::enabled($key, $default)) {
            return;
        }

        SecurityService::recordLogin(
            'user',
            $userId,
            (string) ($device['identifier'] ?? ''),
            SecurityService::OUTCOME_METHOD_DISABLED,
            self::METHOD_AUDIT[$key] ?? 'password',
            null,
            'Sign-in method disabled by an administrator',
            (string) ($device['ip'] ?? ''),
            (string) ($device['user_agent'] ?? '')
        );

        // Constructed rather than HttpException::forbidden(), which hardcodes
        // the 'forbidden' error code — the client needs to tell "you may not"
        // apart from "this method is switched off" to render the right screen.
        throw new HttpException(
            (self::METHOD_LABELS[$key] ?? 'This sign-in method') . ' is currently unavailable.',
            403,
            'auth_method_disabled'
        );
    }

    /**
     * A sign-in proved by a code sent to a phone (an SMS code, or Firebase
     * phone sign-in) only while the operator offers SMS codes in
     * auth.otp_channels. With email-only codes there is no phone sign-in at
     * all, whichever service would have sent the text.
     *
     * @param array<string,mixed> $device
     */
    private static function assertPhoneSignInAllowed(array $device = [], ?int $userId = null): void
    {
        if (AuthConfigService::phoneSignInAllowed()) {
            return;
        }

        SecurityService::recordLogin(
            'user',
            $userId,
            (string) ($device['identifier'] ?? ''),
            SecurityService::OUTCOME_METHOD_DISABLED,
            'phone_otp',
            null,
            'Phone sign-in is not offered (auth.otp_channels has no sms)',
            (string) ($device['ip'] ?? ''),
            (string) ($device['user_agent'] ?? '')
        );

        throw new HttpException(
            'Signing in with a phone number is not available. Use your email address or Google.',
            403,
            'auth_method_disabled'
        );
    }

    /**
     * Register or refresh a device's FCM token.
     *
     * Written as INSERT ... SELECT from the live account row, so a device can
     * never be attached to an account that is being, or has been, deleted: the
     * SELECT waits for a deletion holding the row and then finds nothing, and
     * nothing is inserted.
     */
    public static function registerDevice(int $userId, array $device): void
    {
        $deviceId = trim((string) ($device['device_id'] ?? ''));

        if ($deviceId === '') {
            return;
        }

        Database::execute(
            'INSERT INTO `user_devices`
                (`user_id`, `device_id`, `fcm_token`, `platform`, `device_model`, `os_version`, `app_version`, `locale`, `last_active_at`, `created_at`)
             SELECT :user_id, :device_id, :fcm_token, :platform, :model, :os, :app_version, :locale, NOW(), NOW()
               FROM `users` u
              WHERE u.`id` = :user_id AND u.`deleted_at` IS NULL
             ON DUPLICATE KEY UPDATE
                `fcm_token`      = VALUES(`fcm_token`),
                -- Logout zeroes this (AuthController::logout). Without
                -- restoring it here the flag is a one-way latch: the device
                -- re-registers a perfectly good token on the next sign-in and
                -- sendToUser still skips it, so push dies permanently after
                -- the first logout. Re-registering a token IS the statement of
                -- intent to receive push.
                `push_enabled`   = 1,
                `platform`       = VALUES(`platform`),
                `device_model`   = VALUES(`device_model`),
                `os_version`     = VALUES(`os_version`),
                `app_version`    = VALUES(`app_version`),
                `locale`         = VALUES(`locale`),
                `last_active_at` = NOW()',
            [
                'user_id'     => $userId,
                'device_id'   => mb_substr($deviceId, 0, 191),
                'fcm_token'   => $device['fcm_token'] ?? null,
                'platform'    => in_array($device['platform'] ?? '', ['android', 'ios', 'web'], true) ? $device['platform'] : 'android',
                'model'       => isset($device['device_model']) ? mb_substr((string) $device['device_model'], 0, 120) : null,
                'os'          => isset($device['os_version']) ? mb_substr((string) $device['os_version'], 0, 40) : null,
                'app_version' => isset($device['app_version']) ? mb_substr((string) $device['app_version'], 0, 20) : null,
                'locale'      => isset($device['locale']) ? mb_substr((string) $device['locale'], 0, 10) : null,
            ]
        );
    }
}

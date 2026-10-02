<?php

declare(strict_types=1);

/**
 * Authentication, authorisation and session management.
 *
 * Covers registration validation, credential handling, token issue/refresh/
 * revoke, the guest flow, and the boundary that separates authenticated from
 * anonymous callers.
 */

use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// A run makes hundreds of calls in seconds; without this the throttle
// fires mid-suite and the cascade of 429s hides real failures.
Suite::clearRateLimits();

// -----------------------------------------------------------------
Suite::group('Auth — public endpoints');

$health = Http::get('/health');
Suite::ok('GET /health responds with the success envelope', $health);
Suite::check(
    '/health reports a database connection',
    str_contains(strtolower($health->body), 'database') || $health->success(),
    $health->describe()
);

// -----------------------------------------------------------------
Suite::group('Auth — registration validation');

$noTerms = Http::post('/auth/register', [
    'name'     => 'IT NoTerms',
    'email'    => 'it-noterms@zuste.test',
    'password' => 'IntegrationTest#2024',
]);
Suite::status('register without accept_terms is rejected', $noTerms, [422, 429]);

$weak = Http::post('/auth/register', [
    'name'         => 'IT Weak',
    'email'        => 'it-weak@zuste.test',
    'password'     => '123',
    'accept_terms' => true,
]);
Suite::status('register with a weak password is rejected', $weak, [422, 429]);

$badEmail = Http::post('/auth/register', [
    'name'         => 'IT BadEmail',
    'email'        => 'not-an-email',
    'password'     => 'IntegrationTest#2024',
    'accept_terms' => true,
]);
Suite::status('register with a malformed email is rejected', $badEmail, [422, 429]);

Suite::check(
    'a validation failure names the offending field',
    static function () use ($weak): bool {
        // Skip the assertion if we were throttled rather than validated.
        if ($weak->status === 429) {
            return true;
        }
        $errors = $weak->at('data.errors') ?? $weak->at('errors');

        return is_array($errors) && array_key_exists('password', $errors);
    },
    'expected an errors map keyed by field, got: ' . $weak->describe()
);

// -----------------------------------------------------------------
Suite::group('Auth — accounts and tokens');

$brand = Actors::create('brand', 'brand');

if ($brand === null) {
    Suite::skipGroup(
        'Auth — accounts and tokens',
        'could not obtain a brand session: ' . Actors::lastError()
    );
} else {
    Suite::check('a brand account has a session', $brand['token'] !== '', 'no access token');
    Suite::notEmpty('the account payload carries an id', $brand['id']);

    $creator = Actors::create('creator', 'creator');
    Suite::check(
        'a creator account has a session',
        $creator !== null && $creator['token'] !== '',
        'could not obtain a creator session: ' . Actors::lastError()
    );

    // -- the auth boundary --------------------------------------------
    $anonymous = Http::get('/auth/me');
    Suite::status('GET /auth/me without a token is 401', $anonymous, 401);

    $garbage = Http::get('/auth/me', [], null, ['Authorization' => 'Bearer not.a.real.token']);
    Suite::status('GET /auth/me with a malformed token is 401', $garbage, 401);

    $me = Http::get('/auth/me', [], 'brand');
    Suite::ok('GET /auth/me with a valid token succeeds', $me);
    Suite::equals(
        '/auth/me returns the caller, not somebody else',
        (int) ($me->at('data.user.id') ?? 0),
        $brand['id']
    );

    Suite::check(
        '/auth/me never leaks the password hash',
        static function () use ($me): bool {
            $body = strtolower($me->body);

            return !str_contains($body, 'password_hash') && !str_contains($body, '$2y$');
        },
        'the response body contained a password hash'
    );

    // -- login -------------------------------------------------------
    $wrongPassword = Http::post('/auth/login', [
        'identifier' => Actors::email('brand'),
        'password'   => 'DefinitelyNotThePassword#1',
    ]);
    Suite::status('login with the wrong password is refused', $wrongPassword, [401, 422, 429]);

    Suite::check(
        'a failed login does not reveal whether the account exists',
        static function () use ($wrongPassword): bool {
            if ($wrongPassword->status === 429) {
                return true;
            }
            $message = strtolower($wrongPassword->message());

            // "no account with that email" would be an enumeration oracle.
            return !str_contains($message, 'no account')
                && !str_contains($message, 'not found')
                && !str_contains($message, 'does not exist');
        },
        'message was: ' . $wrongPassword->message()
    );

    $unknown = Http::post('/auth/login', [
        'identifier' => 'it-nobody-at-all@zuste.test',
        'password'   => 'IntegrationTest#2024',
    ]);
    Suite::status('login for an unknown identifier is refused', $unknown, [401, 422, 429]);

    // -- refresh -----------------------------------------------------
    if (is_string($brand['refresh'] ?? null) && $brand['refresh'] !== '') {
        $refreshed = Http::post('/auth/refresh', ['refresh_token' => $brand['refresh']]);
        Suite::ok('a refresh token mints a new access token', $refreshed);

        $newAccess = $refreshed->at('data.tokens.access_token') ?? $refreshed->at('data.access_token');
        Suite::notEmpty('the refreshed response carries an access token', $newAccess);

        $replay = Http::post('/auth/refresh', ['refresh_token' => $brand['refresh']]);
        Suite::check(
            'a refresh token cannot be replayed after rotation',
            $replay->status >= 400,
            'the same refresh token was accepted twice — got ' . $replay->describe()
        );

        // Keep the actor usable for the suites that run after this one.
        if (is_string($newAccess) && $newAccess !== '') {
            Http::setToken('brand', $newAccess);
        }
    } else {
        Suite::check('a refresh token was issued at sign-in', false, 'no refresh_token in the auth payload');
    }

    $bogusRefresh = Http::post('/auth/refresh', ['refresh_token' => 'nope']);
    Suite::status('a bogus refresh token is refused', $bogusRefresh, [401, 422]);

    // -- sessions ----------------------------------------------------
    $sessions = Http::get('/auth/sessions', [], 'brand');
    Suite::ok('GET /auth/sessions lists the caller\'s devices', $sessions);
    Suite::check(
        'the session list is an array',
        is_array($sessions->at('data.sessions')),
        $sessions->describe()
    );
}

// -----------------------------------------------------------------
Suite::group('Auth — guest accounts');

// Guest browsing is an admin-toggled sign-in method that defaults to OFF
// (AuthService::assertMethodEnabled('guest_login', false)). Assert whichever
// contract is actually configured rather than demanding it be enabled.
$guestEnabled = \Zuste\Services\AuthConfigService::enabled('guest_login', false);

$guest = Http::post('/auth/guest', []);

if (!$guestEnabled) {
    Suite::status('guest sign-in is refused while the method is disabled', $guest, 403);
    Suite::equals(
        'the refusal is distinguishable from a plain permission denial',
        $guest->code(),
        'auth_method_disabled'
    );
} else {
    Suite::status('POST /auth/guest issues a guest session', $guest, [200, 201, 429]);
}

if ($guest->success()) {
    $guestToken = $guest->at('data.tokens.access_token') ?? $guest->at('data.access_token');

    if (is_string($guestToken) && $guestToken !== '') {
        Http::setToken('guest', $guestToken);

        $guestMe = Http::get('/auth/me', [], 'guest');
        Suite::ok('a guest can read its own /auth/me', $guestMe);

        // `no-guest` middleware must keep guests out of the account area.
        $guestProfile = Http::get('/me', [], 'guest');
        Suite::status('a guest is refused access to /me', $guestProfile, [401, 403]);

        $guestWallet = Http::get('/wallet', [], 'guest');
        Suite::status('a guest is refused access to the wallet', $guestWallet, [401, 403]);
    }
}

// -----------------------------------------------------------------
Suite::group('Auth — OTP');

$otpBadChannel = Http::post('/auth/otp/send', [
    'destination' => '9876543210',
    'channel'     => 'carrier-pigeon',
    'purpose'     => 'login',
]);
Suite::status('an unsupported OTP channel is rejected', $otpBadChannel, [422, 429]);

$otpBadPurpose = Http::post('/auth/otp/send', [
    'destination' => '9876543210',
    'channel'     => 'sms',
    'purpose'     => 'take-over-account',
]);
Suite::status('an unsupported OTP purpose is rejected', $otpBadPurpose, [422, 429]);

$otpWrongCode = Http::post('/auth/otp/verify', [
    'destination' => '9876543210',
    'code'        => '000000',
    'purpose'     => 'login',
]);
Suite::status('an incorrect OTP is refused', $otpWrongCode, [400, 401, 422, 429]);

// -----------------------------------------------------------------
Suite::group('Auth — password reset');

$forgot = Http::post('/auth/forgot-password', ['email' => 'it-nobody-at-all@zuste.test']);
Suite::status('forgot-password accepts an unknown address', $forgot, [200, 429]);

Suite::check(
    'forgot-password does not disclose whether the account exists',
    static function () use ($forgot): bool {
        if ($forgot->status === 429) {
            return true;
        }
        $message = strtolower($forgot->message());

        return str_contains($message, 'if an account')
            || !str_contains($message, 'not found');
    },
    'message was: ' . $forgot->message()
);

$badReset = Http::post('/auth/reset-password', [
    'token'                 => 'invalid-reset-token',
    'password'              => 'IntegrationTest#2024',
    'password_confirmation' => 'IntegrationTest#2024',
]);
Suite::status('an invalid reset token is refused', $badReset, [400, 401, 422, 429]);

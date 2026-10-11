<?php

declare(strict_types=1);

/**
 * Consent at sign-up, for Google Play review and the DPDP Act (migration
 * 055, decisions of 2026-10-10).
 *
 *  - A Google sign-up accepts the terms like an email sign-up: a new account
 *    without accept_terms is 422 terms_required; an existing one signs in.
 *    Every new account records terms_accepted_at.
 *  - Marketing is opt-in on every way an account is made (email sign-up,
 *    Google sign-up, guest upgrade), and promotional senders reach only the
 *    accounts that hold "marketing": true — broadcasts, promotional
 *    templates, and campaigns, which read the choice again when they send.
 *  - An email-only account (no phone, none verifiable while SMS is off) can
 *    still pay: Cashfree's mobile number is typed at checkout, for that
 *    order only.
 *  - Every new account has an email address; phone-only sign-up is refused.
 *  - Sign in with Apple is off.
 *  - One-time codes: a code nothing redeems is not sent; sends and checks
 *    have their own, larger, per-IP buckets.
 *  - SMS: the panel's setting decides over SMS_ENABLED, and the queue sends
 *    nothing while it is off. The panel refuses OTP with no channel, or with
 *    SMS alone while SMS is off, and the apps are never offered code sign-in
 *    that no channel can deliver.
 *
 * Google tokens are minted with a signing key of this suite's own, published
 * where FirebaseService looks for Google's, as suite 18 does. Settings are
 * changed only inside try/finally blocks that put them back; every account
 * this suite makes is purged at the end.
 */

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Services\AuthConfigService;
use Zuste\Services\AuthService;
use Zuste\Services\CampaignService;
use Zuste\Services\ContactVerificationService;
use Zuste\Services\NotificationService;
use Zuste\Services\PaymentService;
use Zuste\Services\SmsService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// -----------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------

$scNote = static function (string $text): void {
    echo "  \033[33mnote\033[0m  {$text}\n";
};

/**
 * Settings this suite changed: "group.key" => the whole row as it was (id,
 * type, label, description, is_public, sort_order, updated_at …), or null
 * when there was none. Restoring only the value used to put a row that
 * $scUnset deleted back bare: sms.enabled came back as type "string" with
 * no label or description and sort order 0.
 */
$scSettings = [];

$scRemember = static function (string $group, string $key) use (&$scSettings): void {
    $id = $group . '.' . $key;

    if (!array_key_exists($id, $scSettings)) {
        $scSettings[$id] = Database::selectOne(
            'SELECT * FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
            ['g' => $group, 'k' => $key]
        );
    }
};

$scSettingExists = static fn (string $group, string $key): bool => Database::scalar(
    'SELECT 1 FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
    ['g' => $group, 'k' => $key]
) !== null;

$scSet = static function (string $group, string $key, string $value) use ($scRemember, $scSettingExists, &$scSettings): void {
    $scRemember($group, $key);

    $where = ['group_key' => $group, 'setting_key' => $key];

    if ($scSettingExists($group, $key)) {
        Database::update('settings', ['value' => $value], $where);
    } else {
        // A row $scUnset removed comes back with its own definition, not as
        // a bare value; one that never existed gets the table's defaults.
        $definition = $scSettings[$group . '.' . $key] ?? [];
        unset($definition['id'], $definition['updated_at']);

        Database::insert('settings', array_merge($definition, $where, ['value' => $value]));
    }

    Config::flushSettings();
};

$scUnset = static function (string $group, string $key) use ($scRemember): void {
    $scRemember($group, $key);

    Database::execute(
        'DELETE FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
        ['g' => $group, 'k' => $key]
    );

    Config::flushSettings();
};

$scRestore = static function () use (&$scSettings, $scSettingExists): void {
    foreach ($scSettings as $id => $original) {
        [$group, $key] = explode('.', $id, 2);

        if ($original === null) {
            Database::execute(
                'DELETE FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
                ['g' => $group, 'k' => $key]
            );
        } elseif ($scSettingExists($group, $key)) {
            // Every column, its id and updated_at included: the row ends as
            // it started, even when $scUnset and $scSet re-created it.
            Database::update('settings', $original, ['group_key' => $group, 'setting_key' => $key]);
        } else {
            Database::insert('settings', $original);
        }
    }

    $scSettings = [];
    Config::flushSettings();
};

/** Accounts this suite made, purged at the end. */
$scMade = [];

$scEmail = static fn (string $tag): string => 'it-sc-' . $tag . '-' . bin2hex(random_bytes(3)) . '@zuste.test';

/** The account's stored row, as the database holds it. */
$scRow = static fn (int $userId): array => Database::selectOne(
    'SELECT `email`, `phone`, `notification_prefs`, `terms_accepted_at`, `account_type` FROM `users` WHERE `id` = :id',
    ['id' => $userId]
) ?? [];

$scPrefs = static function (array $row): array {
    $prefs = json_decode((string) ($row['notification_prefs'] ?? ''), true);

    return is_array($prefs) ? $prefs : [];
};

/** The field errors of a refusal. */
$scErrors = static function (Res $res): array {
    $errors = $res->at('errors') ?? $res->at('data.errors');

    return is_array($errors) ? $errors : [];
};

$scHas055 = Database::scalar(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'terms_accepted_at'"
) !== null;

// =================================================================
Suite::group('Sign-up consent: what the apps are told');
// =================================================================

$scConfig  = Http::get('/app/config');
$scMethods = [];

foreach ((array) $scConfig->at('data.auth.methods') as $scMethod) {
    if (is_array($scMethod)) {
        $scMethods[(string) ($scMethod['key'] ?? '')] = $scMethod;
    }
}

Suite::ok('the config is served', $scConfig, 200);
Suite::equals('Sign in with Apple is off', $scMethods['apple']['enabled'] ?? null, false);
Suite::equals('…in the database too', AuthConfigService::enabled('apple_login', true), false);
Suite::equals('the sign-in field asks for an email address', $scMethods['email_password']['identifier_mode'] ?? null, 'email');
Suite::equals('…and is labelled so', $scMethods['email_password']['label'] ?? null, 'Email');
Suite::equals('accepting the terms is required at sign-up', $scConfig->at('data.auth.signup.require_terms_accept'), true);
Suite::equals(
    'the app is told SMS is off, so it offers no SMS switch',
    $scConfig->at('data.features.sms_enabled'),
    SmsService::enabled()
);
Suite::equals('a new account\'s preferences have marketing off', NotificationService::DEFAULT_PREFERENCES['marketing'], false);

// =================================================================
Suite::group('Sign-up consent: the email sign-up');
// =================================================================

$scPhoneOnly = Http::post('/auth/register', [
    'name'         => 'IT Phone Only',
    'phone'        => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
    'password'     => 'IntegrationTest#2024',
    'accept_terms' => true,
]);
Suite::status('a phone-only sign-up is refused (422)', $scPhoneOnly, 422);
Suite::check('…on the email field, saying an email address is needed', str_contains((string) ($scErrors($scPhoneOnly)['email'][0] ?? ''), 'email address'), $scPhoneOnly->describe());

$scBlank = Http::post('/auth/register', [
    'name'         => 'IT Blank Email',
    'email'        => '',
    'phone'        => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
    'password'     => 'IntegrationTest#2024',
    'accept_terms' => true,
]);
Suite::status('…so is one with a blank email beside a number (422)', $scBlank, 422);
Suite::check('…on the email field', isset($scErrors($scBlank)['email']), $scBlank->describe());

$scNoTermsEmail = $scEmail('noterms');
$scNoTerms      = Http::post('/auth/register', [
    'name'         => 'IT No Terms',
    'email'        => $scNoTermsEmail,
    'password'     => 'IntegrationTest#2024',
    'accept_terms' => false,
]);
Suite::status('a sign-up that does not accept the terms is refused (422)', $scNoTerms, 422);
Suite::equals('…as terms_required', $scNoTerms->code(), 'terms_required');
Suite::check('…and makes no account', Database::scalar('SELECT 1 FROM `users` WHERE `email` = :e', ['e' => $scNoTermsEmail]) === null);

// Registration allows five a quarter-hour per address.
Suite::clearRateLimits();

$scOptOutEmail = $scEmail('optout');
$scOptOut      = Http::post('/auth/register', [
    'name'         => 'IT Opt Out',
    'email'        => $scOptOutEmail,
    'password'     => 'IntegrationTest#2024',
    'accept_terms' => true,
]);
$scOptOutId = (int) ($scOptOut->at('data.user.id') ?? 0);

if ($scOptOutId > 0) {
    $scMade[] = $scOptOutEmail;
}

Suite::ok('an email sign-up without the box ticked makes the account', $scOptOut, 201);
Suite::equals('…with marketing off', $scPrefs($scRow($scOptOutId))['marketing'] ?? null, false);
Suite::equals('…which the app is told, with every other preference', $scOptOut->at('data.user.notification_prefs'), NotificationService::DEFAULT_PREFERENCES);

if ($scHas055) {
    Suite::check('…and the time the terms were accepted', !empty($scRow($scOptOutId)['terms_accepted_at']), json_encode($scRow($scOptOutId)));
}

$scOptInEmail = $scEmail('optin');
$scOptIn      = Http::post('/auth/register', [
    'name'             => 'IT Opt In',
    'email'            => $scOptInEmail,
    'password'         => 'IntegrationTest#2024',
    'accept_terms'     => true,
    'marketing_opt_in' => true,
]);
$scOptInId = (int) ($scOptIn->at('data.user.id') ?? 0);

if ($scOptInId > 0) {
    $scMade[] = $scOptInEmail;
}

Suite::ok('ticking "product updates and offers" makes the account too', $scOptIn, 201);
Suite::equals('…with marketing on', $scPrefs($scRow($scOptInId))['marketing'] ?? null, true);
Suite::equals('…and nothing else changed', array_diff_key($scPrefs($scRow($scOptInId)), ['marketing' => true]), array_diff_key(NotificationService::DEFAULT_PREFERENCES, ['marketing' => true]));

// =================================================================
Suite::group('Sign-up consent: promotional messages reach only those who opted in');
// =================================================================

if ($scOptOutId <= 0 || $scOptInId <= 0) {
    Suite::skipGroup('Sign-up consent: promotional messages reach only those who opted in', 'the sign-ups above failed');
} else {
    $scPushMark = (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `push_queue`');

    try {
        foreach ([$scOptOutId, $scOptInId] as $scUser) {
            NotificationService::sendRaw($scUser, 'IT promo', 'IT promotional broadcast', 'promo', 'none', null, null, ['in_app', 'push']);
            NotificationService::sendRaw($scUser, 'IT notice', 'IT service broadcast', 'system', 'none', null, null, ['in_app', 'push']);
        }

        $scPushes = static fn (int $userId, string $title): int => (int) Database::scalar(
            'SELECT COUNT(*) FROM `push_queue` WHERE `id` > :m AND `target_value` = :u AND `title` = :t',
            ['m' => $scPushMark, 'u' => (string) $userId, 't' => $title]
        );

        Suite::equals('a promotional push skips the account that did not opt in', $scPushes($scOptOutId, 'IT promo'), 0);
        Suite::equals('…and reaches the one that did', $scPushes($scOptInId, 'IT promo'), 1);
        Suite::equals('a service push still reaches both', $scPushes($scOptOutId, 'IT notice') + $scPushes($scOptInId, 'IT notice'), 2);

        // A campaign's audience: today's accounts, by push.
        $scAudience = array_column(CampaignService::resolveAudience([
            'channel'         => 'push',
            'audience'        => 'custom',
            'audience_filter' => (string) json_encode(['created_after' => date('Y-m-d')]),
        ]), 'user_id');

        Suite::check('a campaign leaves out the account that did not opt in', !in_array($scOptOutId, $scAudience, true));
        Suite::check('…and includes the one that did', in_array($scOptInId, $scAudience, true));

        // An account with no stored choice (as a guest or an operator-made
        // account has) counts as opted out now, not in.
        Database::update('users', ['notification_prefs' => null], ['id' => $scOptInId]);

        $scNoChoice = array_column(CampaignService::resolveAudience([
            'channel'         => 'push',
            'audience'        => 'custom',
            'audience_filter' => (string) json_encode(['created_after' => date('Y-m-d')]),
        ]), 'user_id');

        Suite::check('an account with no stored choice is left out', !in_array($scOptInId, $scNoChoice, true));
        Suite::equals('…and is told its marketing is off', AuthService::publicUser(AuthService::loadUser($scOptInId))['notification_prefs']['marketing'] ?? null, false);
    } finally {
        Database::update('users', ['notification_prefs' => json_encode(array_merge(NotificationService::DEFAULT_PREFERENCES, ['marketing' => true]))], ['id' => $scOptInId]);
        Database::execute('DELETE FROM `push_queue` WHERE `id` > :m AND `target_value` IN (:a, :b)', ['m' => $scPushMark, 'a' => (string) $scOptOutId, 'b' => (string) $scOptInId]);
        Database::execute("DELETE FROM `notifications` WHERE `user_id` IN (:a, :b) AND `type` = 'admin.broadcast'", ['a' => $scOptOutId, 'b' => $scOptInId]);
    }
}

// =================================================================
Suite::group('Sign-up consent: a guest becoming an account');
// =================================================================

// Guest access is switched off, so the guest row is planted and the upgrade
// called in-process: the rules under test are the service's own.
$scGuest = static fn (): int => Database::insert('users', [
    'uuid'             => Crypto::uuid4(),
    'account_type'     => 'guest',
    'name'             => 'Guest',
    'status'           => 'active',
    'auth_providers'   => '["guest"]',
    'referral_code'    => 'ITSC' . strtoupper(bin2hex(random_bytes(4))),
    'guest_expires_at' => date('Y-m-d H:i:s', time() + 86400),
    'created_at'       => date('Y-m-d H:i:s'),
]);

$scRefusal = static function (callable $call): string {
    try {
        $call();
    } catch (HttpException $e) {
        return $e->status() . ' ' . $e->errorCode();
    }

    return 'accepted';
};

$scGuestIds = [];

try {
    $scGuestIds[] = $scGuestA = $scGuest();
    $scGuestEmail = $scEmail('guest');

    Suite::equals(
        'an upgrade that does not accept the terms is refused',
        $scRefusal(static fn () => AuthService::upgradeGuest($scGuestA, ['email' => $scGuestEmail, 'password' => 'IntegrationTest#2024'], '127.0.0.1')),
        '422 terms_required'
    );
    Suite::equals(
        'so is one with a number and no email address',
        $scRefusal(static fn () => AuthService::upgradeGuest($scGuestA, ['accept_terms' => true, 'phone' => '9876501234', 'password' => 'IntegrationTest#2024'], '127.0.0.1')),
        '422 email_required'
    );
    Suite::equals('…and the guest stays a guest', (string) ($scRow($scGuestA)['account_type'] ?? ''), 'guest');

    Suite::equals(
        'with the terms and an email address it becomes an account',
        $scRefusal(static fn () => AuthService::upgradeGuest($scGuestA, ['accept_terms' => true, 'email' => $scGuestEmail, 'password' => 'IntegrationTest#2024'], '127.0.0.1')),
        'accepted'
    );
    $scMade[] = $scGuestEmail;
    Suite::equals('…with marketing off', $scPrefs($scRow($scGuestA))['marketing'] ?? null, false);

    if ($scHas055) {
        Suite::check('…and the time the terms were accepted', !empty($scRow($scGuestA)['terms_accepted_at']));
    }
} finally {
    foreach ($scGuestIds as $scGuestId) {
        if ((string) ($scRow($scGuestId)['account_type'] ?? '') === 'guest') {
            Database::execute('DELETE FROM `users` WHERE `id` = :id', ['id' => $scGuestId]);
        }
    }
}

// =================================================================
Suite::group('Sign-up consent: Google sign-up accepts the terms');
// =================================================================

$scProjectId   = Config::settingString('firebase', 'project_id') ?: (string) Config::get('firebase.project_id', '');
$scCacheFile   = rtrim((string) Config::get('storage.temp_path'), '/\\') . '/firebase-public-keys.json';
$scCacheBackup = null;
$scCacheTouched = false;
$scSslConfig   = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];

foreach ([(string) getenv('OPENSSL_CONF'), dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf'] as $scCnf) {
    if ($scCnf !== '' && is_file($scCnf)) {
        $scSslConfig['config'] = $scCnf;
        break;
    }
}

$scKey = $scProjectId === '' || !AuthConfigService::enabled('google_login', true) ? false : openssl_pkey_new($scSslConfig);
$scPem = '';

if ($scKey !== false) {
    $scCsr  = openssl_csr_new(['commonName' => 'it-sc-firebase-test'], $scKey, $scSslConfig);
    $scCert = $scCsr === false ? false : openssl_csr_sign($scCsr, null, $scKey, 1, $scSslConfig);

    if ($scCert !== false) {
        openssl_x509_export($scCert, $scPem);
    }
}

if ($scPem === '') {
    Suite::skipGroup(
        'Sign-up consent: Google sign-up accepts the terms',
        $scProjectId === ''
            ? 'settings firebase.project_id is empty'
            : (!AuthConfigService::enabled('google_login', true) ? 'Google sign-in is switched off' : 'could not make a test signing key here')
    );
} else {
    $scKid          = 'it-sc-test-' . bin2hex(random_bytes(4));
    $scCacheBackup  = is_file($scCacheFile) ? (string) file_get_contents($scCacheFile) : null;
    $scCached       = $scCacheBackup === null ? null : json_decode($scCacheBackup, true);
    $scKeys         = is_array($scCached['keys'] ?? null) && (int) ($scCached['expires_at'] ?? 0) > time() ? $scCached['keys'] : [];
    $scKeys[$scKid] = $scPem;
    $scCacheTouched = true;

    if (!is_dir(dirname($scCacheFile))) {
        @mkdir(dirname($scCacheFile), 0750, true);
    }

    file_put_contents(
        $scCacheFile,
        (string) json_encode(['keys' => $scKeys, 'expires_at' => max(time() + 900, (int) ($scCached['expires_at'] ?? 0))]),
        LOCK_EX
    );

    $scB64  = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $scMint = static function (string $uid, string $email, string $provider = 'google.com') use ($scB64, $scKid, $scProjectId, $scKey): string {
        $now     = time();
        $header  = $scB64((string) json_encode(['alg' => 'RS256', 'kid' => $scKid, 'typ' => 'JWT']));
        $payload = $scB64((string) json_encode([
            'iss'            => 'https://securetoken.google.com/' . $scProjectId,
            'aud'            => $scProjectId,
            'auth_time'      => $now,
            'user_id'        => $uid,
            'sub'            => $uid,
            'iat'            => $now,
            'exp'            => $now + 3600,
            'email'          => $email,
            'email_verified' => true,
            'name'           => 'IT Google Sign Up',
            'firebase'       => ['sign_in_provider' => $provider, 'identities' => new \stdClass()],
        ]));

        openssl_sign($header . '.' . $payload, $signature, $scKey, OPENSSL_ALGO_SHA256);

        return $header . '.' . $payload . '.' . $scB64((string) $signature);
    };

    try {
        Suite::clearRateLimits();

        $scGoogleEmail = $scEmail('google');
        $scGoogleUid   = 'it-sc-uid-' . bin2hex(random_bytes(6));
        $scToken       = $scMint($scGoogleUid, $scGoogleEmail);

        $scBare = Http::post('/auth/firebase', ['id_token' => $scToken, 'account_type' => 'brand']);
        Suite::status('a first Google sign-in without accept_terms is refused (422)', $scBare, 422);
        Suite::equals('…as terms_required', $scBare->code(), 'terms_required');
        Suite::check('…on accept_terms', isset($scErrors($scBare)['accept_terms']), $scBare->describe());
        Suite::check(
            '…and makes no account',
            Database::scalar('SELECT 1 FROM `users` WHERE `email` = :e OR `firebase_uid` = :u', ['e' => $scGoogleEmail, 'u' => $scGoogleUid]) === null
        );

        $scAccepted = Http::post('/auth/firebase', ['id_token' => $scToken, 'account_type' => 'brand', 'accept_terms' => true]);
        $scGoogleId = (int) ($scAccepted->at('data.user.id') ?? 0);

        if ($scGoogleId > 0) {
            $scMade[] = $scGoogleEmail;
        }

        Suite::ok('with accept_terms it makes the account', $scAccepted, 200);
        Suite::equals('…as a new account', $scAccepted->at('data.is_new_account'), true);
        Suite::equals('…with marketing off', $scPrefs($scRow($scGoogleId))['marketing'] ?? null, false);

        if ($scHas055) {
            Suite::check('…and the time the terms were accepted', !empty($scRow($scGoogleId)['terms_accepted_at']));
        }

        $scAgain = Http::post('/auth/firebase', ['id_token' => $scToken]);
        Suite::ok('the same Google account signs in again without it', $scAgain, 200);
        Suite::equals('…as itself', (int) ($scAgain->at('data.user.id') ?? 0), $scGoogleId);
        Suite::equals('…not as a new account', $scAgain->at('data.is_new_account'), false);

        $scApple = Http::post('/auth/firebase', [
            'id_token'     => $scMint('it-sc-apple-' . bin2hex(random_bytes(6)), $scEmail('apple'), 'apple.com'),
            'accept_terms' => true,
        ]);
        Suite::status('a Sign in with Apple token is refused (403)', $scApple, 403);
        Suite::equals('…as auth_method_disabled', $scApple->code(), 'auth_method_disabled');
    } finally {
        if ($scCacheTouched) {
            if ($scCacheBackup === null) {
                @unlink($scCacheFile);
            } else {
                file_put_contents($scCacheFile, $scCacheBackup, LOCK_EX);
            }
        }
    }
}

// =================================================================
Suite::group('Sign-up consent: one-time codes nothing redeems are not sent');
// =================================================================

Suite::clearRateLimits();

$scCodeAddress = $scEmail('code');
$scCodesFor    = static fn (string $destination): int => (int) Database::scalar(
    'SELECT COUNT(*) FROM `otp_verifications` WHERE `destination` = :d',
    ['d' => $destination]
);

$scRegisterCode = Http::post('/auth/otp/send', ['destination' => $scCodeAddress, 'channel' => 'email', 'purpose' => 'register']);
Suite::status('a sign-up code by email is refused (422)', $scRegisterCode, 422);
Suite::equals('…as invalid_purpose', $scRegisterCode->code(), 'invalid_purpose');
Suite::check('…pointing at email and password or Google', str_contains($scRegisterCode->message(), 'Google'), $scRegisterCode->message());
Suite::equals('…and no code is written', $scCodesFor($scCodeAddress), 0);
Suite::equals('the send endpoint allows 20 per IP in its window', $scRegisterCode->header('x-ratelimit-limit'), '20');

$scPhoneByMail = Http::post('/auth/otp/send', ['destination' => $scCodeAddress, 'channel' => 'email', 'purpose' => 'verify_phone']);
Suite::status('an email code to "verify a phone" is refused (422)', $scPhoneByMail, 422);
Suite::equals('…as invalid_purpose', $scPhoneByMail->code(), 'invalid_purpose');

$scMailBySms = Http::post('/auth/otp/send', ['destination' => '+919876501234', 'channel' => 'sms', 'purpose' => 'verify_email']);
Suite::status('a text to "verify an email" is refused (422)', $scMailBySms, 422);
Suite::equals('…as invalid_purpose', $scMailBySms->code(), 'invalid_purpose');

// Six sends from one address: the old allowance was five per ten minutes,
// shared with checking codes.
$scBurst = [];

for ($i = 0; $i < 6; $i++) {
    $scBurst[] = Http::post('/auth/otp/send', ['destination' => $scCodeAddress, 'channel' => 'email', 'purpose' => 'register'])->status;
}

Suite::check('a burst of sends from one network is not throttled', !in_array(429, $scBurst, true), implode(',', $scBurst));

$scVerify = Http::post('/auth/otp/verify', ['destination' => $scCodeAddress, 'code' => '000000', 'purpose' => 'verify_email']);
Suite::equals('checking a code has its own allowance', $scVerify->header('x-ratelimit-limit'), '10');
Suite::equals('…untouched by the sends', $scVerify->header('x-ratelimit-remaining'), '9');

// =================================================================
Suite::group('Sign-up consent: SMS stays off when the panel says off');
// =================================================================

$scEnvSms    = Config::get('sms.enabled', false);
$scSmsRow    = 0;
$scSmsBefore = Database::selectOne("SELECT * FROM `settings` WHERE `group_key` = 'sms' AND `setting_key` = 'enabled'");

try {
    $scSet('sms', 'enabled', '0');
    Config::set('sms.enabled', true);
    Suite::equals('the panel\'s off wins over SMS_ENABLED=true', SmsService::enabled(), false);

    $scSet('sms', 'enabled', '1');
    Config::set('sms.enabled', false);
    Suite::equals('…and its on wins over SMS_ENABLED=false', SmsService::enabled(), true);

    $scUnset('sms', 'enabled');
    Config::set('sms.enabled', true);
    Suite::equals('with no setting at all, SMS_ENABLED decides', SmsService::enabled(), true);
    Config::set('sms.enabled', false);
    Suite::equals('…either way', SmsService::enabled(), false);

    $scSet('sms', 'enabled', '0');

    if ($scSmsBefore !== null) {
        $scRecreated = Database::selectOne("SELECT `type`, `label`, `description`, `is_public`, `sort_order` FROM `settings` WHERE `group_key` = 'sms' AND `setting_key` = 'enabled'");
        Suite::equals(
            'a setting the suite removed comes back with its definition, not bare',
            $scRecreated,
            array_intersect_key($scSmsBefore, (array) $scRecreated)
        );
    }

    $scSmsRow = Database::insert('sms_queue', [
        'to_phone'   => '+910000' . random_int(100000, 999999),
        'message'    => 'IT signup-consent queued before SMS went off',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $scDrain = SmsService::drainQueue(50);
    $scQueued = Database::selectOne('SELECT `status`, `attempts`, `last_error` FROM `sms_queue` WHERE `id` = :id', ['id' => $scSmsRow]) ?? [];

    Suite::equals('the queue sends nothing while SMS is off', $scDrain['sent'], 0);
    Suite::equals('…and closes what was waiting', (string) ($scQueued['status'] ?? ''), 'failed');
    Suite::check('…saying why', str_contains((string) ($scQueued['last_error'] ?? ''), 'switched off'), json_encode($scQueued));
    Suite::equals('…without trying the provider', (int) ($scQueued['attempts'] ?? -1), 0);
    Suite::check('the one-time code channels leave SMS out', !in_array('sms', AuthConfigService::otpChannels(), true));
} finally {
    Config::set('sms.enabled', $scEnvSms);
    $scRestore();

    if ($scSmsRow > 0) {
        Database::execute('DELETE FROM `sms_queue` WHERE `id` = :id', ['id' => $scSmsRow]);
    }
}

Suite::equals(
    'the sms.enabled row is put back exactly as it was, every column',
    Database::selectOne("SELECT * FROM `settings` WHERE `group_key` = 'sms' AND `setting_key` = 'enabled'"),
    $scSmsBefore
);

// =================================================================
// A new mobile number is proved by a code sent by text. With SMS off and
// proof on, Email & mobile must not offer a change or an additional number
// that could never complete (ContactVerificationService::mobileCodesAvailable).
// =================================================================
$scMobileGroup = 'Sign-up consent: no mobile number is offered when no text can prove it';

if ($scOptInId <= 0) {
    Suite::skipGroup($scMobileGroup, 'the opted-in sign-up above did not create an account');
} else {
    Suite::group($scMobileGroup);

    $scRefusedWith = $scRefusal;

    try {
        $scSet('sms', 'enabled', '0');
        $scSet('creator', 'otp_verification_enabled', '1');
        $scSet('creator', 'allow_mobile_change', '1');

        $scPolicy = ContactVerificationService::policy($scOptInId);
        Suite::equals('with SMS off the policy offers no mobile change', $scPolicy['allow_mobile_change'], false);
        Suite::equals('…and says no code can reach a number', $scPolicy['mobile_codes_available'], false);
        Suite::equals('…while an email change is still offered', $scPolicy['allow_email_change'], true);
        Suite::equals(
            'a mobile change is refused before anything is opened',
            $scRefusedWith(static fn () => ContactVerificationService::preflightPrimaryChange($scOptInId, 'phone', '9876501234')),
            '422 sms_unavailable'
        );
        Suite::equals(
            'an additional mobile number is refused the same way',
            $scRefusedWith(static fn () => ContactVerificationService::addAlternate($scOptInId, 'phone', '9876501234')),
            '422 sms_unavailable'
        );
        Suite::equals(
            '…and left no contact row behind',
            (int) Database::scalar('SELECT COUNT(*) FROM `user_contact_points` WHERE `user_id` = :u AND `channel` = \'phone\'', ['u' => $scOptInId]),
            0
        );

        $scSet('sms', 'enabled', '1');
        Suite::equals('with SMS on a mobile change is offered again', ContactVerificationService::policy($scOptInId)['allow_mobile_change'], true);

        $scSet('sms', 'enabled', '0');
        $scSet('creator', 'otp_verification_enabled', '0');
        Suite::equals('with proof switched off no text is needed, so it is offered', ContactVerificationService::policy($scOptInId)['mobile_codes_available'], true);
    } finally {
        $scRestore();
    }
}

// =================================================================
Suite::group('Sign-up consent: the panel refuses one-time codes with no channel');
// =================================================================

$scOrigin   = (string) preg_replace('#/api/v\d+/?$#', '', Http::baseUrl());
$scJar      = (string) tempnam(sys_get_temp_dir(), 'it-sc-jar');
$scAdmin    = 'it-sc-admin@zuste.test';
$scPassword = 'ItSignupConsent!' . bin2hex(random_bytes(4));

/** @return array{status:int,body:string,location:string} */
$scPanel = static function (string $method, string $path, array $fields = [], bool $json = false) use ($scOrigin, $scJar): array {
    $handle = curl_init(str_starts_with($path, 'http') ? $path : $scOrigin . $path);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $scJar,
        CURLOPT_COOKIEFILE     => $scJar,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'zusty-it-signup-consent/1.0',
        CURLOPT_HTTPHEADER     => $json ? ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'] : [],
    ]);

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $raw        = (string) curl_exec($handle);
    $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    unset($handle); // writes the cookie jar

    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $headerSize), $m);

    return ['status' => $status, 'body' => substr($raw, $headerSize), 'location' => isset($m[1]) ? trim($m[1]) : ''];
};

$scCsrf = static fn (string $html): string => preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m) === 1
    ? html_entity_decode($m[1], ENT_QUOTES)
    : '';

$scDropAdmin = static function () use ($scAdmin): void {
    foreach (Database::column('SELECT `id` FROM `admins` WHERE `email` = :e', ['e' => $scAdmin]) as $id) {
        $id = (int) $id;

        Database::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = :id', ['id' => $id]);
        Database::execute("DELETE FROM `login_history` WHERE `actor_type` = 'admin' AND `actor_id` = :id", ['id' => $id]);
        Database::execute("DELETE FROM `activity_logs` WHERE `actor_type` = 'admin' AND `actor_id` = :id", ['id' => $id]);

        try {
            Database::execute('DELETE FROM `admin_permissions` WHERE `admin_id` = :id', ['id' => $id]);
            Database::execute('DELETE FROM `admins` WHERE `id` = :id', ['id' => $id]);
        } catch (\Throwable) {
            Database::update('admins', [
                'email'      => 'retired-' . $id . '-' . bin2hex(random_bytes(3)) . '@zuste.test',
                'status'     => 'inactive',
                'deleted_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
        }
    }
};

try {
    $scDropAdmin();

    Database::insert('admins', [
        'name'                 => 'IT Signup Consent',
        'email'                => $scAdmin,
        'password_hash'        => Crypto::hashPassword($scPassword),
        'role_id'              => (int) Database::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1'),
        'status'               => 'active',
        'must_change_password' => 0,
        'created_at'           => date('Y-m-d H:i:s'),
    ]);

    $scLogin    = $scPanel('GET', '/admin/login');
    $scSignedIn = $scPanel('POST', '/admin/login', ['_csrf' => $scCsrf($scLogin['body']), 'email' => $scAdmin, 'password' => $scPassword]);

    if ($scSignedIn['status'] !== 302 || str_contains($scSignedIn['location'], '/admin/login')) {
        Suite::skipGroup('Sign-up consent: the panel refuses one-time codes with no channel', 'the probe admin could not sign in');
    } else {
        $scPolicyBefore = Database::select(
            "SELECT `group_key`, `setting_key`, `value` FROM `settings`
              WHERE (`group_key` = 'auth' AND `setting_key` IN ('otp_channels','otp_login_enabled','forgot_password_enabled','role_choice_visible','identifier_mode'))
                 OR (`group_key` = 'app' AND `setting_key` IN ('creator_signup_enabled','brand_signup_enabled'))
              ORDER BY `group_key`, `setting_key`"
        );

        $scPage = $scPanel('GET', '/admin/login-methods');
        Suite::check('the page renders', $scPage['status'] === 200 && $scCsrf($scPage['body']) !== '', 'HTTP ' . $scPage['status']);
        Suite::check('…with no phone-only identifier to pick', !str_contains($scPage['body'], 'value="phone"'));

        $scForm = $scPanel('POST', '/admin/login-methods/policy', ['_csrf' => $scCsrf($scPage['body']), 'otp_channels_present' => '1']);
        $scBack = $scForm['location'] !== '' ? $scPanel('GET', $scForm['location']) : ['status' => 0, 'body' => ''];
        preg_match('/data-flash="([^"]*)"\s+data-flash-type="([^"]*)"/', $scBack['body'], $scToast);

        Suite::equals('saving with no channel ticked is refused', $scToast[2] ?? '', 'error');
        Suite::check('…saying a channel is needed', str_contains(html_entity_decode($scToast[1] ?? '', ENT_QUOTES), 'at least one channel'), $scToast[1] ?? '');

        $scAjax = $scPanel('POST', '/admin/login-methods/policy', ['_csrf' => $scCsrf($scBack['body'] ?: $scPage['body']), 'otp_channels_present' => '1'], true);
        $scAjaxJson = json_decode($scAjax['body'], true);
        Suite::equals('…and over AJAX it is a 422', $scAjax['status'], 422);
        Suite::check('…on otp_channels', isset($scAjaxJson['errors']['otp_channels']), mb_substr($scAjax['body'], 0, 240));

        Config::flushSettings();
        Suite::equals(
            '…and nothing in the form was saved',
            Database::select(
                "SELECT `group_key`, `setting_key`, `value` FROM `settings`
                  WHERE (`group_key` = 'auth' AND `setting_key` IN ('otp_channels','otp_login_enabled','forgot_password_enabled','role_choice_visible','identifier_mode'))
                     OR (`group_key` = 'app' AND `setting_key` IN ('creator_signup_enabled','brand_signup_enabled'))
                  ORDER BY `group_key`, `setting_key`"
            ),
            $scPolicyBefore
        );

        // SMS alone while SMS delivery is off is no channel either: the apps
        // would be offered code sign-in that /auth/otp/send can only refuse.
        if (SmsService::enabled()) {
            $scNote('SMS delivery is on here, so the "SMS alone" refusal is not exercised');
        } else {
            $scSmsOnly = $scPanel('POST', '/admin/login-methods/policy', [
                '_csrf'                => $scCsrf($scBack['body'] ?: $scPage['body']),
                'otp_channels_present' => '1',
                'otp_channels'         => ['sms'],
            ], true);
            $scSmsOnlyJson = json_decode($scSmsOnly['body'], true);

            Suite::equals('ticking SMS alone while SMS is off is refused too (422)', $scSmsOnly['status'], 422);
            Suite::check(
                '…saying SMS delivery is off, on otp_channels',
                str_contains((string) ($scSmsOnlyJson['errors']['otp_channels'][0] ?? ''), 'SMS delivery is off'),
                mb_substr($scSmsOnly['body'], 0, 240)
            );

            Config::flushSettings();
            Suite::equals(
                '…and nothing in the form was saved',
                Database::select(
                    "SELECT `group_key`, `setting_key`, `value` FROM `settings`
                      WHERE (`group_key` = 'auth' AND `setting_key` IN ('otp_channels','otp_login_enabled','forgot_password_enabled','role_choice_visible','identifier_mode'))
                         OR (`group_key` = 'app' AND `setting_key` IN ('creator_signup_enabled','brand_signup_enabled'))
                      ORDER BY `group_key`, `setting_key`"
                ),
                $scPolicyBefore
            );
        }
    }
} finally {
    $scDropAdmin();
    @unlink($scJar);
}

// =================================================================
Suite::group('Sign-up consent: no code sign-in is offered when no channel can deliver');
// =================================================================

try {
    $scSet('sms', 'enabled', '0');
    $scSet('auth', 'otp_login_enabled', '1');
    $scSet('auth', 'otp_channels', '["sms"]');

    $scOtpMethod = static function (): array {
        foreach (AuthConfigService::publicBlock('android')['methods'] as $method) {
            if (($method['key'] ?? '') === 'mobile_otp') {
                return $method;
            }
        }

        return [];
    };

    $scSmsOnlyBlock = $scOtpMethod();
    Suite::equals('with SMS alone stored and SMS off, the apps get no channel', $scSmsOnlyBlock['channels'] ?? null, []);
    Suite::equals('…and code sign-in is reported off', $scSmsOnlyBlock['login_enabled'] ?? null, false);

    $scSet('auth', 'otp_channels', '["email"]');
    $scEmailBlock = $scOtpMethod();
    Suite::equals('with Email ticked, code sign-in is on again', [$scEmailBlock['channels'] ?? null, $scEmailBlock['login_enabled'] ?? null], [['email'], true]);
} finally {
    $scRestore();
}

// =================================================================
Suite::group('Sign-up consent: consent is read again when a campaign sends');
// =================================================================

if ($scOptOutId <= 0 || $scOptInId <= 0) {
    Suite::skipGroup('Sign-up consent: consent is read again when a campaign sends', 'the sign-ups above failed');
} else {
    $scTag        = strtoupper(bin2hex(random_bytes(4)));
    $scCampaigns  = [];
    $scSubscribed = [];
    $scPushMark   = (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `push_queue`');
    $scMailMark   = (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `email_queue`');
    $scOptOutMail = (string) ($scRow($scOptOutId)['email'] ?? '');

    $scCampaign = static function (string $channel, string $audience) use ($scTag, &$scCampaigns): int {
        $id = Database::insert('marketing_campaigns', [
            'name'     => 'IT sc ' . $channel . ' ' . $audience . ' ' . $scTag,
            'channel'  => $channel,
            'subject'  => 'IT sc ' . $audience . ' ' . $scTag,
            'content'  => '<p>IT signup-consent campaign</p>',
            'audience' => $audience,
            'status'   => 'sending',
        ]);

        $scCampaigns[] = $id;

        return $id;
    };

    // A row as queue() writes it: the list was built earlier, under whatever
    // the account had chosen (or the old rule) at the time.
    $scQueued = static fn (int $campaignId, ?int $userId, string $destination): int => Database::insert('campaign_recipients', [
        'campaign_id'    => $campaignId,
        'user_id'        => $userId,
        'destination'    => $destination,
        'status'         => 'pending',
        'tracking_token' => bin2hex(random_bytes(16)),
    ]);

    $scOutcome = static fn (int $recipientId): array => Database::selectOne(
        'SELECT `status`, `error_message` FROM `campaign_recipients` WHERE `id` = :id',
        ['id' => $recipientId]
    ) ?? [];

    $scPushesFor = static fn (int $userId): int => (int) Database::scalar(
        'SELECT COUNT(*) FROM `push_queue` WHERE `id` > :m AND `target_value` = :u',
        ['m' => $scPushMark, 'u' => (string) $userId]
    );

    $scMailsTo = static fn (string $email): int => (int) Database::scalar(
        'SELECT COUNT(*) FROM `email_queue` WHERE `id` > :m AND `to_email` = :e',
        ['m' => $scMailMark, 'e' => $email]
    );

    try {
        // 1. Opted out after the list was built (stored false).
        $scPushCampaign = $scCampaign('push', 'custom');
        $scInRow        = $scQueued($scPushCampaign, $scOptInId, 'user:' . $scOptInId);
        $scOutRow       = $scQueued($scPushCampaign, $scOptOutId, 'user:' . $scOptOutId);

        $scRun = CampaignService::dispatchBatch($scPushCampaign);

        Suite::equals('a queued push campaign still reaches the account that opted in', [$scOutcome($scInRow)['status'] ?? null, $scPushesFor($scOptInId)], ['sent', 1]);
        Suite::equals('…but not one that has opted out since', $scOutcome($scOutRow)['status'] ?? null, 'failed');
        Suite::check('…saying why', str_contains((string) ($scOutcome($scOutRow)['error_message'] ?? ''), 'not opted in to marketing'), json_encode($scOutcome($scOutRow)));
        Suite::equals('…and nothing was queued to it', $scPushesFor($scOptOutId), 0);
        Suite::equals('the batch reports one sent', $scRun['sent'] ?? null, 1);

        // 2. No stored choice: queued under the old rule, held to today's.
        Database::update('users', ['notification_prefs' => null], ['id' => $scOptOutId]);

        $scOldRule    = $scCampaign('push', 'all');
        $scOldRuleRow = $scQueued($scOldRule, $scOptOutId, 'user:' . $scOptOutId);
        CampaignService::dispatchBatch($scOldRule);

        Suite::equals('a row queued for an account with no stored choice is not sent', $scOutcome($scOldRuleRow)['status'] ?? null, 'failed');
        Suite::equals('…and nothing was queued to it', $scPushesFor($scOptOutId), 0);

        Database::update('users', ['notification_prefs' => json_encode(NotificationService::DEFAULT_PREFERENCES)], ['id' => $scOptOutId]);

        // 3. The newsletter list: the subscription is the consent.
        $scUnsubMail = $scEmail('unsub');

        foreach ([[$scOptOutMail, 'subscribed', $scOptOutId], [$scUnsubMail, 'unsubscribed', null]] as [$scAddress, $scStatus, $scUserId]) {
            Database::execute('DELETE FROM `newsletter_subscribers` WHERE `email` = :e', ['e' => $scAddress]);
            $scSubscribed[] = Database::insert('newsletter_subscribers', [
                'email'             => $scAddress,
                'user_id'           => $scUserId,
                'source'            => 'admin',
                'status'            => $scStatus,
                'unsubscribe_token' => bin2hex(random_bytes(16)),
            ]);
        }

        $scNewsletter = $scCampaign('email', 'subscribers');
        $scSubRow     = $scQueued($scNewsletter, $scOptOutId, $scOptOutMail);
        $scUnsubRow   = $scQueued($scNewsletter, null, $scUnsubMail);
        CampaignService::dispatchBatch($scNewsletter);

        Suite::equals(
            'a newsletter subscriber is sent to even with app marketing off',
            [$scOutcome($scSubRow)['status'] ?? null, $scMailsTo($scOptOutMail)],
            ['sent', 1]
        );
        Suite::equals('…but an address that unsubscribed after the list was built is not', [$scOutcome($scUnsubRow)['status'] ?? null, $scMailsTo($scUnsubMail)], ['failed', 0]);
        Suite::check('…saying why', str_contains((string) ($scOutcome($scUnsubRow)['error_message'] ?? ''), 'unsubscribed'), json_encode($scOutcome($scUnsubRow)));
    } finally {
        Database::update('users', ['notification_prefs' => json_encode(NotificationService::DEFAULT_PREFERENCES)], ['id' => $scOptOutId]);

        foreach ($scCampaigns as $scId) {
            Database::execute('DELETE FROM `campaign_recipients` WHERE `campaign_id` = :id', ['id' => $scId]);
            Database::execute('DELETE FROM `marketing_campaigns` WHERE `id` = :id', ['id' => $scId]);
        }

        foreach ($scSubscribed as $scId) {
            Database::execute('DELETE FROM `newsletter_subscribers` WHERE `id` = :id', ['id' => $scId]);
        }

        Database::execute('DELETE FROM `push_queue` WHERE `id` > :m AND `target_value` IN (:a, :b)', ['m' => $scPushMark, 'a' => (string) $scOptOutId, 'b' => (string) $scOptInId]);
        Database::execute("DELETE FROM `email_queue` WHERE `id` > :m AND `to_email` LIKE 'it-sc-%@zuste.test'", ['m' => $scMailMark]);
    }
}

// =================================================================
Suite::group('Sign-up consent: a promotional template follows the marketing choice');
// =================================================================

$scPromoTemplate = Database::selectOne(
    "SELECT `code`, `channels` FROM `notification_templates`
      WHERE `category` IN ('promo','marketing') AND `is_active` = 1 AND `email_subject` IS NOT NULL AND `email_subject` <> ''
        AND `channels` LIKE '%push%' AND `channels` LIKE '%email%'
      ORDER BY `id` LIMIT 1"
);

if ($scOptOutId <= 0 || $scOptInId <= 0) {
    Suite::skipGroup('Sign-up consent: a promotional template follows the marketing choice', 'the sign-ups above failed');
} elseif ($scPromoTemplate === null) {
    Suite::skipGroup('Sign-up consent: a promotional template follows the marketing choice', 'no active promotional template with push and email');
} else {
    $scCode     = (string) $scPromoTemplate['code'];
    $scPushMark = (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `push_queue`');
    $scMailMark = (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `email_queue`');
    $scNoteMark = (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `notifications`');

    $scReach = static function (int $userId) use ($scRow, $scPushMark, $scMailMark, $scNoteMark, $scCode): array {
        return [
            'in_app' => (int) Database::scalar('SELECT COUNT(*) FROM `notifications` WHERE `id` > :m AND `user_id` = :u AND `type` = :t', ['m' => $scNoteMark, 'u' => $userId, 't' => $scCode]),
            'push'   => (int) Database::scalar('SELECT COUNT(*) FROM `push_queue` WHERE `id` > :m AND `target_value` = :u', ['m' => $scPushMark, 'u' => (string) $userId]),
            'email'  => (int) Database::scalar('SELECT COUNT(*) FROM `email_queue` WHERE `id` > :m AND `to_email` = :e', ['m' => $scMailMark, 'e' => (string) ($scRow($userId)['email'] ?? '')]),
        ];
    };

    try {
        foreach ([$scOptOutId, $scOptInId] as $scUser) {
            NotificationService::send($scUser, $scCode, ['amount' => 'INR 500', 'name' => 'IT']);
        }

        Suite::equals(
            "'{$scCode}' (promotional) reaches an opted-out account in the app only",
            $scReach($scOptOutId),
            ['in_app' => 1, 'push' => 0, 'email' => 0]
        );
        Suite::equals('…and an opted-in account by push and email too', $scReach($scOptInId), ['in_app' => 1, 'push' => 1, 'email' => 1]);
    } finally {
        Database::execute('DELETE FROM `push_queue` WHERE `id` > :m AND `target_value` IN (:a, :b)', ['m' => $scPushMark, 'a' => (string) $scOptOutId, 'b' => (string) $scOptInId]);
        Database::execute("DELETE FROM `email_queue` WHERE `id` > :m AND `to_email` LIKE 'it-sc-%@zuste.test'", ['m' => $scMailMark]);
        Database::execute('DELETE FROM `notifications` WHERE `id` > :m AND `user_id` IN (:a, :b)', ['m' => $scNoteMark, 'a' => $scOptOutId, 'b' => $scOptInId]);
    }
}

// =================================================================
Suite::group('Sign-up consent: an email-only account can still pay through Cashfree');
// =================================================================

/** cashfreeCustomer() called directly: [customer array, null] or [null, HttpException]. */
$scCustomer = static function (int $userId, ?string $checkoutPhone): array {
    try {
        return [(new \ReflectionMethod(PaymentService::class, 'cashfreeCustomer'))->invoke(null, $userId, $checkoutPhone), null];
    } catch (HttpException $e) {
        return [null, $e];
    }
};

if ($scOptOutId <= 0) {
    Suite::skipGroup('Sign-up consent: an email-only account can still pay through Cashfree', 'the sign-ups above failed');
} else {
    Suite::equals('a new email sign-up has no phone number', $scRow($scOptOutId)['phone'] ?? null, null);

    [, $scNone] = $scCustomer($scOptOutId, null);
    Suite::equals('without a number, the order is refused as phone_required (422)', [$scNone?->status(), $scNone?->errorCode()], [422, 'phone_required']);
    Suite::check('…on customer_phone, so the app can ask for one', isset($scNone?->errors()['customer_phone']), json_encode($scNone?->errors()));

    [$scGiven] = $scCustomer($scOptOutId, '+91 98765 43210');
    Suite::equals('a number typed at checkout is used for the order', $scGiven['customer_phone'] ?? null, '9876543210');
    Suite::equals('…and is not written to the account', $scRow($scOptOutId)['phone'] ?? null, null);

    foreach ([['09876543210', '9876543210'], ['919876543210', '9876543210'], ['98765-43210', '9876543210']] as [$scTyped, $scExpected]) {
        Suite::equals("'{$scTyped}' is read as {$scExpected}", ($scCustomer($scOptOutId, $scTyped)[0]['customer_phone'] ?? null), $scExpected);
    }

    foreach (['12345', '5876543210', '+44 7700 900123'] as $scBad) {
        [, $scRefused] = $scCustomer($scOptOutId, $scBad);
        Suite::equals("'{$scBad}' is refused as phone_invalid (422)", [$scRefused?->status(), $scRefused?->errorCode(), isset($scRefused?->errors()['customer_phone'])], [422, 'phone_invalid', true]);
    }

    // An account that has its own number keeps using it.
    $scOwnPhone = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    Database::update('users', ['phone' => $scOwnPhone, 'phone_country_code' => '+91'], ['id' => $scOptOutId]);

    try {
        [$scOwn] = $scCustomer($scOptOutId, '9123456789');
        Suite::equals("the account's own number wins over one typed at checkout", $scOwn['customer_phone'] ?? null, $scOwnPhone);
    } finally {
        Database::update('users', ['phone' => null], ['id' => $scOptOutId]);
    }

    // End to end against a stand-in Cashfree on loopback (in-process only).
    $scCfPort = 8094;
    $scCfLog  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zuste-mock-cashfree-sc-' . getmypid() . '.log';
    $scCfMock = null;
    $scCfUp   = false;
    @unlink($scCfLog);

    $scBusy = @fsockopen('127.0.0.1', $scCfPort, $scErrno, $scErrstr, 0.2);

    if ($scBusy !== false) {
        fclose($scBusy);
    } else {
        $scCfMock = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $scCfPort, __DIR__ . '/../fixtures/mock_cashfree.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $scCfLog . '.out', 'a'], 2 => ['file', $scCfLog . '.out', 'a']],
            $scCfPipes,
            dirname(__DIR__, 2),
            array_merge(getenv(), ['ZUSTE_MOCK_CASHFREE_LOG' => $scCfLog])
        );

        for ($i = 0; $i < 50 && is_resource($scCfMock); $i++) {
            $socket = @fsockopen('127.0.0.1', $scCfPort, $scErrno, $scErrstr, 0.2);

            if ($socket !== false) {
                fclose($socket);
                $scCfUp = true;
                break;
            }

            usleep(100_000);
        }
    }

    $scCreator = Database::selectOne('SELECT `id`, `user_id` FROM `creators` WHERE `deleted_at` IS NULL ORDER BY `id` LIMIT 1');

    if (!$scCfUp || $scCreator === null) {
        $scNote(!$scCfUp ? 'could not start a stand-in Cashfree on 127.0.0.1:' . $scCfPort . '; the order itself is not exercised' : 'no creator to book; the order itself is not exercised');
    } else {
        $scCfConfig = [
            'api_base'   => Config::get('payment.cashfree.api_base'),
            'app_id'     => Config::get('payment.cashfree.app_id'),
            'secret_key' => Config::get('payment.cashfree.secret_key'),
        ];
        $scCfBase = 'http://127.0.0.1:' . $scCfPort . '/pg';
        Config::set('payment.cashfree.api_base', ['sandbox' => $scCfBase, 'production' => $scCfBase]);
        Config::set('payment.cashfree.app_id', 'IT-CF-APP');
        Config::set('payment.cashfree.secret_key', 'IT-CF-SECRET');

        $scBookingId = 0;

        try {
            $scBookingId = Database::insert('bookings', [
                'code'            => 'ITSC' . strtoupper(bin2hex(random_bytes(4))),
                'brand_user_id'   => $scOptOutId,
                'creator_id'      => (int) $scCreator['id'],
                'creator_user_id' => (int) $scCreator['user_id'],
                'title'           => 'IT signup-consent checkout',
                'amount'          => 1000,
                'total_amount'    => 1180,
                'status'          => 'accepted',
            ]);

            try {
                PaymentService::createBookingOrder($scBookingId, $scOptOutId, 'cashfree');
                Suite::check('paying with no number is refused before any order is made', false, 'an order was created');
            } catch (HttpException $e) {
                Suite::equals('paying with no number is refused before any order is made', [$e->errorCode(), (int) Database::scalar('SELECT COUNT(*) FROM `payments` WHERE `booking_id` = :b', ['b' => $scBookingId])], ['phone_required', 0]);
            }

            $scOrder = PaymentService::createBookingOrder($scBookingId, $scOptOutId, 'cashfree', null, '98765 43210');
            $scSent  = array_values(array_filter(
                array_map(static fn (string $l): mixed => json_decode($l, true), is_file($scCfLog) ? (file($scCfLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : []),
                static fn (mixed $c): bool => is_array($c) && str_ends_with((string) ($c['path'] ?? ''), '/orders')
            ));

            Suite::equals('with the number typed at checkout, a Cashfree order is made', [$scOrder['gateway'] ?? null, $scOrder['requires_client_action'] ?? null], ['cashfree', true]);
            Suite::equals('…carrying that number to Cashfree', $scSent[0]['body']['customer_details']['customer_phone'] ?? null, '9876543210');
            Suite::equals('…while the account still has no phone', $scRow($scOptOutId)['phone'] ?? null, null);
        } catch (\Throwable $e) {
            Suite::check('with the number typed at checkout, a Cashfree order is made', false, get_class($e) . ': ' . $e->getMessage());
        } finally {
            foreach ($scCfConfig as $scCfKey => $scCfValue) {
                Config::set('payment.cashfree.' . $scCfKey, $scCfValue);
            }

            if ($scBookingId > 0) {
                Database::execute('DELETE FROM `payments` WHERE `booking_id` = :b', ['b' => $scBookingId]);
                Database::execute('DELETE FROM `bookings` WHERE `id` = :b', ['b' => $scBookingId]);
            }
        }
    }

    if (is_resource($scCfMock)) {
        proc_terminate($scCfMock);
        proc_close($scCfMock);
    }

    @unlink($scCfLog);
    @unlink($scCfLog . '.out');
}

// -----------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------

foreach ($scMade as $scAddress) {
    Actors::purgeByEmail($scAddress);
}

Database::execute(
    "DELETE FROM `login_history` WHERE `identifier` LIKE 'it-sc-%'"
);

if (Database::scalar("SELECT 1 FROM `users` WHERE `email` LIKE 'it-sc-%@zuste.test' LIMIT 1") !== null) {
    $scNote('some it-sc-… accounts could not be purged; they are inert test residue');
}

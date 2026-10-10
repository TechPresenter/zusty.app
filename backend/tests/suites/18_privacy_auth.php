<?php

declare(strict_types=1);

/**
 * Privacy, authentication side: who can prove what, and how that can fail.
 *
 * Step-up proofs (codes, passwords, a fresh Firebase sign-in), the per-user
 * guessing gate, the hold after a contact change, contact changes that need
 * the owner's proof, setting a first password, the public OTP endpoints, OTP
 * sign-in, Firebase account linking, export links dying with sessions, and
 * what the old token of a deleted account is told.
 *
 * One-time codes are planted straight into otp_verifications, always with a
 * user_id, hashed as the server hashes them; the code path that consumes them
 * is the real one. Settings are changed only inside try/finally blocks that
 * restore them. Actors are named pa-…; a group that changes an actor's
 * email, phone or password puts it back, so the next run signs in to the
 * same accounts. The one deliberate tombstone per run stays.
 *
 * Races are asserted in-process, because php -S serves one request at a time.
 */

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Jwt;
use Zuste\Core\RateLimiter;
use Zuste\Services\AuthConfigService;
use Zuste\Services\AuthService;
use Zuste\Services\CronRunner;
use Zuste\Services\MailService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// -----------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------

$paNote = static function (string $text): void {
    echo "  \033[33mnote\033[0m  {$text}\n";
};

/** Settings this run changed: "group.key" => ['value' => original], or null when the row did not exist. */
$paSettings = [];

$setSetting = static function (string $group, string $key, string $value) use (&$paSettings): void {
    $id = $group . '.' . $key;

    if (!array_key_exists($id, $paSettings)) {
        $row = Database::selectOne(
            'SELECT `value` FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
            ['g' => $group, 'k' => $key]
        );

        $paSettings[$id] = $row === null ? null : ['value' => $row['value']];
    }

    Database::execute(
        'INSERT INTO `settings` (`group_key`, `setting_key`, `value`) VALUES (:g, :k, :v)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
        ['g' => $group, 'k' => $key, 'v' => $value]
    );

    Config::flushSettings();
};

$restoreSettings = static function () use (&$paSettings): void {
    foreach ($paSettings as $id => $original) {
        [$group, $key] = explode('.', $id, 2);

        if ($original === null) {
            Database::execute(
                'DELETE FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
                ['g' => $group, 'k' => $key]
            );
        } else {
            Database::execute(
                'UPDATE `settings` SET `value` = :v WHERE `group_key` = :g AND `setting_key` = :k',
                ['v' => $original['value'], 'g' => $group, 'k' => $key]
            );
        }
    }

    $paSettings = [];
    Config::flushSettings();
};

/**
 * A known one-time code, planted where the server will look for it.
 *
 * @return array{id:int,code:string,wrong:string}
 */
$plant = static function (int $userId, string $destination, string $purpose, string $channel = 'email'): array {
    $code = (string) random_int(100000, 999999);

    $id = Database::insert('otp_verifications', [
        'user_id'     => $userId,
        'channel'     => $channel,
        'destination' => $channel === 'email' ? strtolower($destination) : $destination,
        'purpose'     => $purpose,
        'code_hash'   => Crypto::hashToken($code),
        'expires_at'  => date('Y-m-d H:i:s', time() + 300),
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    return ['id' => $id, 'code' => $code, 'wrong' => $code === '111111' ? '222222' : '111111'];
};

/** The exception a call throws, or null. */
$throws = static function (callable $call): ?HttpException {
    try {
        $call();
    } catch (HttpException $e) {
        return $e;
    }

    return null;
};

/** The field errors of a refusal. */
$errorsOf = static function (Res $res): array {
    $errors = $res->at('errors') ?? $res->at('data.errors');

    return is_array($errors) ? $errors : [];
};

$maxId = static function (string $table): int {
    return (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `' . $table . '`');
};

/** Remove an account's exports, archives first. */
$purgeExports = static function (int $userId): void {
    if (Database::scalar('SELECT 1 FROM `data_export_requests` WHERE `user_id` = :id LIMIT 1', ['id' => $userId]) === null) {
        return;
    }

    Database::execute(
        'UPDATE `data_export_requests` SET `expires_at` = NOW() - INTERVAL 1 DAY WHERE `user_id` = :id',
        ['id' => $userId]
    );
    CronRunner::run('privacy:purge-exports');
    Database::execute('DELETE FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $userId]);
};

$gateKey = static fn (int $userId): string => 'privacy:reauth:' . $userId;

/** What migration 048 adds that these checks rely on. */
$has048 = Database::scalar(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_changed_at'"
) !== null
    && str_contains(
        (string) (Database::selectOne("SHOW COLUMNS FROM `otp_verifications` LIKE 'purpose'")['Type'] ?? ''),
        'confirm_contact_change'
    );

$hasFirebaseVia = str_contains(
    (string) (Database::selectOne("SHOW COLUMNS FROM `data_export_requests` LIKE 'verified_via'")['Type'] ?? ''),
    'firebase'
);

// -----------------------------------------------------------------
// Actors. Registration is throttled per IP, so the buckets are cleared
// before every few accounts, here at the top and never inside a group.
// -----------------------------------------------------------------

$tombName = 'pa-tomb-' . substr(bin2hex(random_bytes(4)), 0, 6);
$paActors = [];

foreach (['pa-hold', 'pa-nopass', 'pa-gate', 'pa-otp', 'pa-mail', 'pa-phone', 'pa-link', $tombName] as $index => $name) {
    if ($index % 4 === 0) {
        Suite::clearRateLimits();
    }

    $actor           = Actors::create($name, 'brand');
    $paActors[$name] = $actor === null || (int) $actor['id'] <= 0 ? null : $actor + ['name' => $name];
}

Suite::clearRateLimits();

$actorId = static fn (string $name): int => (int) ($paActors[$name]['id'] ?? 0);

foreach ($paActors as $actor) {
    if ($actor !== null) {
        RateLimiter::clear($gateKey((int) $actor['id']));
    }
}

// =================================================================
Suite::group('Privacy auth: PUT /me no longer moves the email or phone');
// =================================================================

if ($paActors['pa-hold'] === null) {
    Suite::skipGroup('Privacy auth: PUT /me no longer moves the email or phone', 'could not create pa-hold: ' . Actors::lastError());
} else {
    $holdId    = $actorId('pa-hold');
    $holdEmail = strtolower($paActors['pa-hold']['email']);

    $moved = Http::put('/me', ['email' => 'it-pa-new-' . bin2hex(random_bytes(4)) . '@zuste.test'], 'pa-hold');
    Suite::status('a new email through PUT /me is refused (422)', $moved, 422);
    Suite::equals('…as contact_change_required', $moved->code(), 'contact_change_required');
    Suite::check('…on the email field', isset($errorsOf($moved)['email']), $moved->describe());
    Suite::equals(
        '…and the email is unchanged',
        strtolower((string) Database::scalar('SELECT `email` FROM `users` WHERE `id` = :id', ['id' => $holdId])),
        $holdEmail
    );

    $me    = Http::get('/me', [], 'pa-hold');
    $phone = (string) ($me->at('data.user.phone') ?? '');

    $same = Http::put('/me', array_filter(['email' => $paActors['pa-hold']['email'], 'phone' => $phone]), 'pa-hold');
    Suite::ok('re-sending the current email and phone is not a change (200)', $same, 200);

    if (preg_match('/^\d{10}$/', $phone) === 1) {
        // Same length, last digit moved on by one: valid, and not the stored number.
        $otherPhone = substr($phone, 0, -1) . (string) (((int) substr($phone, -1) + 1) % 10);
        $newPhone   = Http::put('/me', ['phone' => $otherPhone], 'pa-hold');
        Suite::equals('a different phone through PUT /me is refused too', $newPhone->code(), 'contact_change_required');
    }
}

// =================================================================
Suite::group('Privacy auth: a changed email is held from step-up');
// =================================================================

if ($paActors['pa-hold'] === null || !$has048) {
    Suite::skipGroup(
        'Privacy auth: a changed email is held from step-up',
        $paActors['pa-hold'] === null ? 'no pa-hold actor' : 'migration 048 (users.email_changed_at) is not applied'
    );
} else {
    $holdId    = $actorId('pa-hold');
    $holdEmail = strtolower($paActors['pa-hold']['email']);

    try {
        $setSetting('security', 'privacy_contact_hold_hours', '72');
        RateLimiter::clear($gateKey($holdId));
        $purgeExports($holdId);

        Database::execute('UPDATE `users` SET `email_changed_at` = NOW() WHERE `id` = :id', ['id' => $holdId]);

        $view     = Http::get('/me/privacy', [], 'pa-hold');
        $channels = array_column((array) $view->at('data.verification.channels'), 'channel');
        $held     = (array) ($view->at('data.verification.unavailable')[0] ?? []);

        Suite::check('the changed email is not offered for codes', !in_array('email', $channels, true), json_encode($channels));
        Suite::check(
            '…it is listed as unavailable, with when it is back',
            ($held['channel'] ?? '') === 'email' && ($held['reason'] ?? '') === 'contact_recently_changed'
                && !empty($held['available_at']),
            json_encode($view->at('data.verification.unavailable'))
        );

        $send = Http::post('/me/privacy/verification-code', ['purpose' => 'data_export', 'channel' => 'email'], 'pa-hold');
        Suite::status('no code is sent there (422)', $send, 422);
        Suite::equals('…as contact_recently_changed', $send->code(), 'contact_recently_changed');

        $planted = $plant($holdId, $holdEmail, 'data_export');
        $refused = Http::post('/me/privacy/exports', ['code' => $planted['code'], 'channel' => 'email'], 'pa-hold');
        Suite::status('a code minted before the change is refused too (422)', $refused, 422);
        Suite::equals('…as contact_recently_changed', $refused->code(), 'contact_recently_changed');
        Suite::equals(
            '…and stays unspent',
            Database::scalar('SELECT `verified_at` FROM `otp_verifications` WHERE `id` = :id', ['id' => $planted['id']]),
            null
        );

        Database::execute(
            'UPDATE `users` SET `email_changed_at` = NOW() - INTERVAL 73 HOUR WHERE `id` = :id',
            ['id' => $holdId]
        );

        $after = Http::post('/me/privacy/exports', ['code' => $planted['code'], 'channel' => 'email'], 'pa-hold');
        Suite::ok('once the hold is over the same code confirms (201)', $after, 201);
    } finally {
        $restoreSettings();
        Database::execute('UPDATE `users` SET `email_changed_at` = NULL WHERE `id` = :id', ['id' => $holdId]);
        $purgeExports($holdId);
        RateLimiter::clear($gateKey($holdId));
    }
}

// =================================================================
Suite::group('Privacy auth: a contact change needs the owner\'s proof');
// =================================================================

if ($paActors['pa-hold'] === null || !$has048) {
    Suite::skipGroup(
        'Privacy auth: a contact change needs the owner\'s proof',
        $paActors['pa-hold'] === null ? 'no pa-hold actor' : 'migration 048 (users.email_changed_at) is not applied'
    );
} else {
    $holdId    = $actorId('pa-hold');
    $holdEmail = strtolower($paActors['pa-hold']['email']);
    $original  = Database::selectOne(
        'SELECT `email`, `email_verified_at`, `email_changed_at` FROM `users` WHERE `id` = :id',
        ['id' => $holdId]
    ) ?? [];
    $requestMark = $maxId('contact_change_requests');
    $eventMark   = $maxId('contact_verification_events');

    try {
        // The log driver "delivers" the code to the new address, so this
        // checks the proof rather than the machine's SMTP settings.
        $setSetting('mail', 'driver', 'log');
        $setSetting('creator', 'otp_verification_enabled', '1');
        $setSetting('creator', 'allow_email_change', '1');
        $setSetting('security', 'privacy_contact_hold_hours', '72');
        RateLimiter::clear($gateKey($holdId));

        $newEmail = 'it-pa-moved-' . bin2hex(random_bytes(4)) . '@zuste.test';

        $noProof = Http::post('/me/contacts/change', ['channel' => 'email', 'value' => $newEmail], 'pa-hold');
        Suite::status('a change without proof is refused (422)', $noProof, 422);
        Suite::equals('…as reauth_required', $noProof->code(), 'reauth_required');

        $opened = Http::post('/me/contacts/change', [
            'channel'  => 'email',
            'value'    => $newEmail,
            'password' => $paActors['pa-hold']['password'],
        ], 'pa-hold');
        Suite::ok('with the password the change is opened (201)', $opened, 201);

        $requestId = (int) $opened->at('data.request.id');

        if ($requestId > 0) {
            $queueMark = $maxId('email_queue');

            $code = $plant($holdId, $newEmail, 'change_email');
            Database::update('contact_change_requests', ['otp_id' => $code['id']], ['id' => $requestId]);

            $confirmed = Http::post('/me/contacts/requests/' . $requestId . '/verify', ['code' => $code['code']], 'pa-hold');
            Suite::ok('the code sent to the new address confirms it', $confirmed, 200);

            $row = Database::selectOne(
                'SELECT `email`, `email_changed_at` FROM `users` WHERE `id` = :id',
                ['id' => $holdId]
            ) ?? [];
            Suite::equals('the account has the new email', strtolower((string) ($row['email'] ?? '')), $newEmail);
            Suite::check('…and records when it changed', !empty($row['email_changed_at']), json_encode($row));
            Suite::equals(
                'exactly one notice went to the old address',
                (int) Database::scalar(
                    'SELECT COUNT(*) FROM `email_queue` WHERE `id` > :m AND `to_email` = :to',
                    ['m' => $queueMark, 'to' => $holdEmail]
                ),
                1
            );
            Suite::equals(
                '…saying the email was changed',
                Database::scalar(
                    'SELECT `subject` FROM `email_queue` WHERE `id` > :m AND `to_email` = :to ORDER BY `id` DESC LIMIT 1',
                    ['m' => $queueMark, 'to' => $holdEmail]
                ),
                'Your Zusty email address was changed'
            );

            $view = Http::get('/me/privacy', [], 'pa-hold');
            Suite::check(
                'the new address is now held from step-up',
                in_array('email', array_column((array) $view->at('data.verification.unavailable'), 'channel'), true),
                json_encode($view->at('data.verification'))
            );

            $link = $throws(static fn () => AuthService::firebaseLinkTarget([
                'uid'            => 'it-pa-held-' . bin2hex(random_bytes(4)),
                'email'          => $newEmail,
                'email_verified' => true,
                'phone'          => null,
                'provider'       => 'google.com',
            ]));
            Suite::check(
                '…and cannot be linked to a Google sign-in (409 account_link_held)',
                $link !== null && $link->status() === 409 && $link->errorCode() === 'account_link_held',
                $link === null ? 'no exception' : $link->errorCode()
            );
        }
    } finally {
        $restoreSettings();

        // Put the actor back so the next run signs in to it again.
        Database::update('users', [
            'email'             => $original['email'] ?? $paActors['pa-hold']['email'],
            'email_verified_at' => $original['email_verified_at'] ?? null,
            'email_changed_at'  => null,
        ], ['id' => $holdId]);
        Database::execute(
            'DELETE FROM `contact_verification_events` WHERE `user_id` = :u AND `id` > :m',
            ['u' => $holdId, 'm' => $eventMark]
        );
        Database::execute(
            'DELETE FROM `contact_change_requests` WHERE `user_id` = :u AND `id` > :m',
            ['u' => $holdId, 'm' => $requestMark]
        );
        RateLimiter::clear($gateKey($holdId));
    }
}

// =================================================================
Suite::group('Privacy auth: a session cannot mint a password');
// =================================================================

if ($paActors['pa-nopass'] === null || !$has048) {
    Suite::skipGroup(
        'Privacy auth: a session cannot mint a password',
        $paActors['pa-nopass'] === null ? 'no pa-nopass actor' : 'migration 048 (set_password purpose) is not applied'
    );
} else {
    $npId    = $actorId('pa-nopass');
    $npEmail = strtolower($paActors['pa-nopass']['email']);
    $newPass = 'PaNewPass#2024x';

    try {
        RateLimiter::clear($gateKey($npId));
        RateLimiter::clear('throttle:password:user:' . $npId);
        $purgeExports($npId);

        Database::update('users', [
            'password_hash'  => null,
            'auth_providers' => '["google.com"]',
            'firebase_uid'   => null,
        ], ['id' => $npId]);
        Database::execute('UPDATE `users` SET `email_changed_at` = NULL WHERE `id` = :id', ['id' => $npId]);

        $view = Http::get('/me/privacy', [], 'pa-nopass');
        Suite::equals('a passwordless account says so', $view->at('data.verification.has_password'), false);

        $bare = Http::post('/auth/change-password', ['password' => $newPass, 'password_confirmation' => $newPass], 'pa-nopass');
        Suite::status('setting a password with no proof is refused (422)', $bare, 422);
        Suite::equals('…as reauth_required', $bare->code(), 'reauth_required');
        Suite::equals(
            '…and no password was set',
            Database::scalar('SELECT `password_hash` FROM `users` WHERE `id` = :id', ['id' => $npId]),
            null
        );

        $exportCode = $plant($npId, $npEmail, 'data_export');
        $crossed    = Http::post('/auth/change-password', [
            'password'              => $newPass,
            'password_confirmation' => $newPass,
            'code'                  => $exportCode['code'],
            'channel'               => 'email',
        ], 'pa-nopass');
        Suite::status('a data-export code cannot set a password (422)', $crossed, 422);

        $queueMark = $maxId('email_queue');
        $setCode   = $plant($npId, $npEmail, 'set_password');
        $set       = Http::post('/auth/change-password', [
            'password'              => $newPass,
            'password_confirmation' => $newPass,
            'code'                  => $setCode['code'],
            'channel'               => 'email',
        ], 'pa-nopass');
        Suite::ok('a set_password code sets it', $set, 200);

        $row = Database::selectOne('SELECT `password_hash`, `auth_providers` FROM `users` WHERE `id` = :id', ['id' => $npId]) ?? [];
        Suite::check('the password is stored', !empty($row['password_hash']), 'password_hash is empty');
        Suite::check(
            '…and recorded as proved',
            in_array('password', (array) json_decode((string) ($row['auth_providers'] ?? ''), true), true),
            (string) ($row['auth_providers'] ?? '')
        );
        Suite::equals(
            'the account is told a password was added',
            (int) Database::scalar(
                "SELECT COUNT(*) FROM `email_queue` WHERE `id` > :m AND `to_email` = :to
                    AND `subject` = 'A password was added to your Zusty account'",
                ['m' => $queueMark, 'to' => $npEmail]
            ),
            1
        );

        // A password minted before this fix: set, but never proved.
        Database::update('users', [
            'password_hash'  => Crypto::hashPassword('Known#2024a'),
            'auth_providers' => '["google.com"]',
        ], ['id' => $npId]);

        $login = Http::post('/auth/login', ['identifier' => $npEmail, 'password' => 'Known#2024a']);
        $token = (string) ($login->at('data.tokens.access_token') ?? '');

        if ($token === '') {
            Suite::check('the unproven password still signs in', false, $login->describe());
        } else {
            Http::setToken('pa-nopass', $token);
            RateLimiter::clear($gateKey($npId));

            $backstop = Http::get('/me/privacy', [], 'pa-nopass');
            Suite::equals('an unproven password is not offered as proof', $backstop->at('data.verification.password'), false);
            Suite::equals('…though the account has one', $backstop->at('data.verification.has_password'), true);

            $unproven = Http::post('/me/privacy/exports', ['password' => 'Known#2024a'], 'pa-nopass');
            Suite::status('…and is refused for an export (422)', $unproven, 422);
            Suite::equals('…as password_unproven', $unproven->code(), 'password_unproven');
            Suite::check('…on the password field', isset($errorsOf($unproven)['password']), $unproven->describe());
        }
    } finally {
        // Back to an ordinary password account.
        Database::update('users', [
            'password_hash'  => Crypto::hashPassword($paActors['pa-nopass']['password']),
            'auth_providers' => '["password"]',
        ], ['id' => $npId]);

        $relogin = Http::post('/auth/login', ['identifier' => $npEmail, 'password' => $paActors['pa-nopass']['password']]);
        $fresh   = (string) ($relogin->at('data.tokens.access_token') ?? '');

        if ($fresh !== '') {
            Http::setToken('pa-nopass', $fresh);
        }

        $purgeExports($npId);
        RateLimiter::clear($gateKey($npId));
        RateLimiter::clear('throttle:password:user:' . $npId);
    }
}

// =================================================================
Suite::group('Privacy auth: step-up guessing is bounded');
// =================================================================

if ($paActors['pa-gate'] === null) {
    Suite::skipGroup('Privacy auth: step-up guessing is bounded', 'could not create pa-gate: ' . Actors::lastError());
} else {
    $gateId    = $actorId('pa-gate');
    $gateEmail = strtolower($paActors['pa-gate']['email']);

    try {
        $setSetting('security', 'privacy_reauth_max_attempts', '10');
        $setSetting('security', 'privacy_reauth_window_minutes', '60');
        RateLimiter::clear($gateKey($gateId));
        RateLimiter::clear('throttle:privacy-export:user:' . $gateId);
        RateLimiter::clear('throttle:privacy-delete:user:' . $gateId);
        $purgeExports($gateId);

        $logMark     = $maxId('activity_logs');
        $historyMark = $maxId('login_history');

        $exportCode = $plant($gateId, $gateEmail, 'data_export');
        $deleteCode = $plant($gateId, $gateEmail, 'delete_account');

        $wrong = [];

        for ($i = 0; $i < 5; $i++) {
            $wrong[] = Http::post(
                '/me/privacy/exports',
                ['code' => $exportCode['wrong'], 'channel' => 'email'],
                'pa-gate'
            )->status;
            $wrong[] = Http::post(
                '/me/privacy/delete-account',
                ['code' => $deleteCode['wrong'], 'channel' => 'email', 'confirmation' => 'DELETE'],
                'pa-gate'
            )->status;
        }

        Suite::check('ten wrong codes across export and deletion are each refused (422)', $wrong === array_fill(0, 10, 422), implode(',', $wrong));

        $locked = Http::post(
            '/me/privacy/delete-account',
            ['code' => $deleteCode['code'], 'channel' => 'email', 'confirmation' => 'DELETE'],
            'pa-gate'
        );
        Suite::status('the right code is then refused (429)', $locked, 429);
        Suite::equals('…as reauth_locked', $locked->code(), 'reauth_locked');
        Suite::check('…with Retry-After', (int) $locked->header('retry-after') > 0, $locked->describe());
        Suite::equals(
            'the account is still intact',
            Database::scalar('SELECT `status` FROM `users` WHERE `id` = :id', ['id' => $gateId]),
            'active'
        );
        Suite::equals(
            '…and the deletion code is unspent',
            Database::scalar('SELECT `verified_at` FROM `otp_verifications` WHERE `id` = :id', ['id' => $deleteCode['id']]),
            null
        );
        Suite::equals(
            'each failure is in the activity log',
            (int) Database::scalar(
                "SELECT COUNT(*) FROM `activity_logs` WHERE `id` > :m AND `action` = 'privacy.reauth_failed'
                    AND `subject_type` = 'user' AND `subject_id` = :u",
                ['m' => $logMark, 'u' => $gateId]
            ),
            10
        );
        Suite::equals(
            '…and in the sign-in history',
            (int) Database::scalar(
                "SELECT COUNT(*) FROM `login_history` WHERE `id` > :m AND `actor_type` = 'user' AND `actor_id` = :u
                    AND `outcome` IN ('bad_credentials', '2fa_failed')",
                ['m' => $historyMark, 'u' => $gateId]
            ),
            10
        );

        $noSend = Http::post('/me/privacy/verification-code', ['purpose' => 'data_export', 'channel' => 'email'], 'pa-gate');
        Suite::equals('no code is even sent while locked', $noSend->code(), 'reauth_locked');

        // The attempt counter is a conditional UPDATE: asserted in-process,
        // because php -S serves one request at a time.
        $last = $plant($gateId, $gateEmail, 'data_export');
        Database::execute(
            'UPDATE `otp_verifications` SET `attempts` = `max_attempts` - 1 WHERE `id` = :id',
            ['id' => $last['id']]
        );

        $wrongOnce = $throws(static fn () => AuthService::verifyOtp($gateEmail, $last['wrong'], 'data_export', $gateId));
        Suite::equals('the last attempt, wrong, is refused as wrong', $wrongOnce?->errorCode(), 'otp_invalid');

        $spent = $throws(static fn () => AuthService::verifyOtp($gateEmail, $last['code'], 'data_export', $gateId));
        Suite::check(
            '…and then even the right code is refused: no attempts left',
            $spent !== null && $spent->status() === 429 && $spent->errorCode() === 'otp_attempts_exceeded',
            $spent === null ? 'accepted' : $spent->status() . ' ' . $spent->errorCode()
        );

        $once  = $plant($gateId, $gateEmail, 'data_export');
        $first = $throws(static fn () => AuthService::verifyOtp($gateEmail, $once['code'], 'data_export', $gateId));
        $again = $throws(static fn () => AuthService::verifyOtp($gateEmail, $once['code'], 'data_export', $gateId));
        Suite::check(
            'a code redeems once',
            $first === null && $again !== null && $again->errorCode() === 'otp_used',
            ($first?->errorCode() ?? 'first ok') . ' / ' . ($again?->errorCode() ?? 'second accepted')
        );

        // The retired endpoint shares the deletion throttle.
        RateLimiter::clear($gateKey($gateId));
        RateLimiter::clear('throttle:privacy-delete:user:' . $gateId);

        $retired = [];

        for ($i = 0; $i < 10; $i++) {
            $res       = Http::delete('/auth/account', [], 'pa-gate');
            $retired[] = $res->status . ':' . $res->code();
        }

        Suite::check(
            'DELETE /auth/account answers 410 endpoint_retired',
            $retired === array_fill(0, 10, '410:endpoint_retired'),
            implode(', ', array_unique($retired))
        );
        Suite::status('…and the eleventh call is throttled (429)', Http::delete('/auth/account', [], 'pa-gate'), 429);

        // A wrong password, so a throttle that is not shared fails safe.
        $shared = Http::post(
            '/me/privacy/delete-account',
            ['password' => 'not-the-password', 'confirmation' => 'DELETE'],
            'pa-gate'
        );
        Suite::status('…in the same bucket as POST /me/privacy/delete-account', $shared, 429);
    } finally {
        $restoreSettings();
        RateLimiter::clear($gateKey($gateId));
        RateLimiter::clear('throttle:privacy-export:user:' . $gateId);
        RateLimiter::clear('throttle:privacy-delete:user:' . $gateId);
        $purgeExports($gateId);
    }
}

// =================================================================
Suite::group('Privacy auth: the public OTP routes never touch step-up codes');
// =================================================================

if ($paActors['pa-otp'] === null) {
    Suite::skipGroup('Privacy auth: the public OTP routes never touch step-up codes', 'could not create pa-otp: ' . Actors::lastError());
} else {
    $otpId    = $actorId('pa-otp');
    $otpEmail = strtolower($paActors['pa-otp']['email']);
    $count    = static fn (string $purpose): int => (int) Database::scalar(
        'SELECT COUNT(*) FROM `otp_verifications` WHERE `destination` = :d AND `purpose` = :p',
        ['d' => $otpEmail, 'p' => $purpose]
    );

    RateLimiter::clear($gateKey($otpId));
    $purgeExports($otpId);

    $before = $count('delete_account');
    $send   = Http::post('/auth/otp/send', ['destination' => $otpEmail, 'channel' => 'email', 'purpose' => 'delete_account']);
    Suite::status('the public send refuses a deletion code (422)', $send, 422);
    Suite::check('…on the purpose field', isset($errorsOf($send)['purpose']), $send->describe());
    Suite::equals('…and writes no code', $count('delete_account'), $before);

    $planted = $plant($otpId, $otpEmail, 'delete_account');
    $burn    = Http::post('/auth/otp/verify', ['destination' => $otpEmail, 'code' => '000000', 'purpose' => 'delete_account']);
    Suite::status('the public verify refuses a deletion code (422)', $burn, 422);
    Suite::check('…on the purpose field', isset($errorsOf($burn)['purpose']), $burn->describe());
    Suite::equals(
        '…and the planted code keeps all its attempts',
        (int) Database::scalar('SELECT `attempts` FROM `otp_verifications` WHERE `id` = :id', ['id' => $planted['id']]),
        0
    );

    $other = $actorId('pa-gate') ?: $actorId('pa-link');

    if ($other > 0) {
        $foreign = $plant($other, $otpEmail, 'data_export');
        $bound   = Http::post('/me/privacy/exports', ['code' => $foreign['code'], 'channel' => 'email'], 'pa-otp');
        Suite::status('a code issued to another account at the same address is refused (422)', $bound, 422);
        Suite::equals('…as otp_not_found', $bound->code(), 'otp_not_found');
    } else {
        $paNote('binding check skipped: no second actor');
    }

    RateLimiter::clear($gateKey($otpId));
}

// =================================================================
Suite::group('Privacy auth: a failed send leaves the last code working');
// =================================================================

if ($paActors['pa-mail'] === null) {
    Suite::skipGroup('Privacy auth: a failed send leaves the last code working', 'could not create pa-mail: ' . Actors::lastError());
} else {
    $mailId    = $actorId('pa-mail');
    $mailEmail = strtolower($paActors['pa-mail']['email']);

    try {
        $setSetting('mail', 'driver', 'smtp');
        $setSetting('mail', 'host', '127.0.0.1');
        $setSetting('mail', 'port', '1');
        RateLimiter::clear($gateKey($mailId));
        RateLimiter::clear('otp:cooldown:data_export:' . $mailEmail);
        RateLimiter::clear('otp:send:data_export:' . $mailEmail);
        $purgeExports($mailId);

        $queueMark = $maxId('email_queue');
        $live      = $plant($mailId, $mailEmail, 'data_export');

        $send = Http::post('/me/privacy/verification-code', ['purpose' => 'data_export', 'channel' => 'email'], 'pa-mail');
        Suite::status('with the mail server down no code is sent (503)', $send, 503);
        Suite::equals('…as otp_delivery_failed', $send->code(), 'otp_delivery_failed');
        Suite::equals(
            '…no copy waits in the queue to arrive late',
            (int) Database::scalar(
                "SELECT COUNT(*) FROM `email_queue` WHERE `id` > :m AND `to_email` = :to AND `subject` = 'Your Zusty verification code'",
                ['m' => $queueMark, 'to' => $mailEmail]
            ),
            0
        );
        Suite::equals(
            '…and no second live code is left behind',
            (int) Database::scalar(
                "SELECT COUNT(*) FROM `otp_verifications`
                  WHERE `destination` = :d AND `purpose` = 'data_export' AND `user_id` = :u
                    AND `verified_at` IS NULL AND `expires_at` > NOW()",
                ['d' => $mailEmail, 'u' => $mailId]
            ),
            1
        );

        $confirmed = Http::post('/me/privacy/exports', ['code' => $live['code'], 'channel' => 'email'], 'pa-mail');
        Suite::ok('the code sent before still confirms an export', $confirmed, 201);
    } finally {
        $restoreSettings();
        RateLimiter::clear($gateKey($mailId));
        RateLimiter::clear('otp:cooldown:data_export:' . $mailEmail);
        RateLimiter::clear('otp:send:data_export:' . $mailEmail);
        $purgeExports($mailId);
    }
}

// =================================================================
Suite::group('Privacy auth: a contact change nobody can prove is refused');
// =================================================================

if ($paActors['pa-phone'] === null) {
    Suite::skipGroup('Privacy auth: a contact change nobody can prove is refused', 'could not create pa-phone: ' . Actors::lastError());
} else {
    $ucId   = $actorId('pa-phone');
    $ucRow  = Database::selectOne(
        'SELECT `email`, `email_verified_at`, `password_hash`, `auth_providers`, `firebase_uid` FROM `users` WHERE `id` = :id',
        ['id' => $ucId]
    ) ?? [];
    $ucMark = $maxId('contact_change_requests');
    $ucOpened = static fn (): int => (int) Database::scalar(
        'SELECT COUNT(*) FROM `contact_change_requests` WHERE `user_id` = :u AND `id` > :m',
        ['u' => $ucId, 'm' => $ucMark]
    );
    $ucNewEmail = static fn (): string => 'it-pa-unproven-' . bin2hex(random_bytes(4)) . '@zuste.test';

    try {
        $setSetting('sms', 'enabled', '0');
        $setSetting('creator', 'allow_email_change', '1');
        $setSetting('security', 'privacy_contact_hold_hours', '72');
        RateLimiter::clear($gateKey($ucId));

        // A phone-only account while SMS is off: no password, no code channel,
        // no Firebase sign-in to repeat.
        Database::update('users', [
            'email'          => null,
            'password_hash'  => null,
            'auth_providers' => '["phone"]',
            'firebase_uid'   => null,
        ], ['id' => $ucId]);

        $phoneOnly = Http::post('/me/contacts/change', ['channel' => 'email', 'value' => $ucNewEmail()], 'pa-phone');
        Suite::status('a phone-only account with SMS off cannot change its email unproven (409)', $phoneOnly, 409);
        Suite::equals('…as contact_change_unverifiable', $phoneOnly->code(), 'contact_change_unverifiable');
        Suite::check(
            '…and is told to write to support',
            str_contains($phoneOnly->message(), MailService::supportAddress()),
            $phoneOnly->describe()
        );
        Suite::check('…never a zustee.com mailbox', !str_contains($phoneOnly->message(), '@zustee.com'), $phoneOnly->message());
        Suite::equals('…and no change request was opened', $ucOpened(), 0);
        Suite::equals(
            '…and the account still has no email',
            Database::scalar('SELECT `email` FROM `users` WHERE `id` = :id', ['id' => $ucId]),
            null
        );

        if ($has048) {
            // Its only channel is the email it just changed to, held from step-up.
            Database::update('users', [
                'email'          => $ucRow['email'] ?? $paActors['pa-phone']['email'],
                'password_hash'  => null,
                'auth_providers' => '["google.com"]',
                'firebase_uid'   => null,
            ], ['id' => $ucId]);
            Database::execute('UPDATE `users` SET `email_changed_at` = NOW() WHERE `id` = :id', ['id' => $ucId]);

            $held = Http::post('/me/contacts/change', ['channel' => 'email', 'value' => $ucNewEmail()], 'pa-phone');
            Suite::status('an account whose only channel is held cannot change it again unproven (409)', $held, 409);
            Suite::equals('…as contact_change_unverifiable', $held->code(), 'contact_change_unverifiable');
            Suite::check(
                '…saying the contact changed recently and where to write',
                str_contains($held->message(), 'changed recently') && str_contains($held->message(), MailService::supportAddress()),
                $held->describe()
            );
            Suite::equals('…and no change request was opened', $ucOpened(), 0);

            // Once the hold is over a code can prove it, so proof is asked for instead.
            Database::execute('UPDATE `users` SET `email_changed_at` = NULL WHERE `id` = :id', ['id' => $ucId]);

            $provable = Http::post('/me/contacts/change', ['channel' => 'email', 'value' => $ucNewEmail()], 'pa-phone');
            Suite::status('with the hold over the same change asks for proof instead (422)', $provable, 422);
            Suite::equals('…as reauth_required', $provable->code(), 'reauth_required');
            Suite::equals('…and still nothing was opened', $ucOpened(), 0);
        } else {
            $paNote('held-channel refusal skipped: migration 048 (users.email_changed_at) is not applied');
        }
    } finally {
        $restoreSettings();
        Database::update('users', [
            'email'             => $ucRow['email'] ?? $paActors['pa-phone']['email'],
            'email_verified_at' => $ucRow['email_verified_at'] ?? null,
            'password_hash'     => $ucRow['password_hash'] ?? Crypto::hashPassword($paActors['pa-phone']['password']),
            'auth_providers'    => $ucRow['auth_providers'] ?? '["password"]',
            'firebase_uid'      => $ucRow['firebase_uid'] ?? null,
        ], ['id' => $ucId]);

        if ($has048) {
            Database::execute('UPDATE `users` SET `email_changed_at` = NULL WHERE `id` = :id', ['id' => $ucId]);
        }

        Database::execute(
            'DELETE FROM `contact_change_requests` WHERE `user_id` = :u AND `id` > :m',
            ['u' => $ucId, 'm' => $ucMark]
        );
        RateLimiter::clear($gateKey($ucId));
    }
}

// =================================================================
Suite::group('Privacy auth: a phone-only account and Firebase re-authentication');
// =================================================================

if ($paActors['pa-phone'] === null) {
    Suite::skipGroup('Privacy auth: a phone-only account and Firebase re-authentication', 'could not create pa-phone: ' . Actors::lastError());
} else {
    $phId      = $actorId('pa-phone');
    $phoneRow  = Database::selectOne(
        'SELECT `email`, `password_hash`, `auth_providers`, `firebase_uid` FROM `users` WHERE `id` = :id',
        ['id' => $phId]
    ) ?? [];
    $cacheFile   = rtrim((string) Config::get('storage.temp_path'), '/\\') . '/firebase-public-keys.json';
    $cacheBackup = null;
    $cacheTouched = false;

    try {
        $setSetting('sms', 'enabled', '0');
        $setSetting('security', 'privacy_reauth_max_age_seconds', '300');
        RateLimiter::clear($gateKey($phId));
        RateLimiter::clear('throttle:privacy-export:user:' . $phId);
        RateLimiter::clear('throttle:password:user:' . $phId);
        $purgeExports($phId);

        Database::update('users', [
            'email'          => null,
            'password_hash'  => null,
            'auth_providers' => '["phone"]',
            'firebase_uid'   => null,
        ], ['id' => $phId]);

        $view = Http::get('/me/privacy', [], 'pa-phone');
        Suite::equals('no code channel is offered while SMS is off', $view->at('data.verification.channels'), []);
        Suite::equals('…no password', $view->at('data.verification.password'), false);
        Suite::check(
            '…and the app is told why: SMS is unavailable',
            in_array(
                ['sms', 'sms_unavailable'],
                array_map(
                    static fn (array $u): array => [$u['channel'] ?? '', $u['reason'] ?? ''],
                    (array) $view->at('data.verification.unavailable')
                ),
                true
            ),
            json_encode($view->at('data.verification.unavailable'))
        );

        $firebaseUid = 'it-pa-uid-' . bin2hex(random_bytes(6));
        Database::update('users', ['firebase_uid' => $firebaseUid, 'auth_providers' => '["google.com"]'], ['id' => $phId]);

        $linked = Http::get('/me/privacy', [], 'pa-phone');
        Suite::equals(
            'a linked Google sign-in is offered instead',
            $linked->at('data.verification.providers'),
            AuthConfigService::enabled('google_login', true) ? ['google.com'] : []
        );

        $garbage = Http::post('/me/privacy/exports', ['id_token' => 'not-a-token'], 'pa-phone');
        Suite::status('a malformed id_token is refused, and not as a 401 (422)', $garbage, 422);
        Suite::equals('…as invalid_firebase_token', $garbage->code(), 'invalid_firebase_token');

        $projectId = Config::settingString('firebase', 'project_id') ?: (string) Config::get('firebase.project_id', '');

        // A signing key of our own, published where FirebaseService looks
        // for Google's, so real tokens can be minted for the checks below.
        $sslConfig = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];

        foreach ([(string) getenv('OPENSSL_CONF'), dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf'] as $cnf) {
            if ($cnf !== '' && is_file($cnf)) {
                $sslConfig['config'] = $cnf;
                break;
            }
        }

        $privateKey = $projectId === '' || !AuthConfigService::enabled('google_login', true) ? false : openssl_pkey_new($sslConfig);
        $pem        = '';

        if ($privateKey !== false) {
            $csr  = openssl_csr_new(['commonName' => 'it-pa-firebase-test'], $privateKey, $sslConfig);
            $cert = $csr === false ? false : openssl_csr_sign($csr, null, $privateKey, 1, $sslConfig);

            if ($cert !== false) {
                openssl_x509_export($cert, $pem);
            }
        }

        if ($pem === '' || !$hasFirebaseVia) {
            $paNote(
                $projectId === ''
                    ? 'signed Firebase tokens skipped: settings firebase.project_id is empty'
                    : (!$hasFirebaseVia
                        ? 'signed Firebase tokens skipped: data_export_requests.verified_via has no firebase (migration 048)'
                        : 'signed Firebase tokens skipped: could not make a test signing key here')
            );
        } else {
            $kid          = 'it-pa-test-' . bin2hex(random_bytes(4));
            $cacheBackup  = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : null;
            $cached       = $cacheBackup === null ? null : json_decode($cacheBackup, true);
            $keys         = is_array($cached['keys'] ?? null) && (int) ($cached['expires_at'] ?? 0) > time() ? $cached['keys'] : [];
            $keys[$kid]   = $pem;
            $cacheTouched = true;

            if (!is_dir(dirname($cacheFile))) {
                @mkdir(dirname($cacheFile), 0750, true);
            }

            file_put_contents(
                $cacheFile,
                (string) json_encode(['keys' => $keys, 'expires_at' => max(time() + 900, (int) ($cached['expires_at'] ?? 0))]),
                LOCK_EX
            );

            $b64  = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
            $mint = static function (string $uid, int $authTime) use ($b64, $kid, $projectId, $privateKey): string {
                $now     = time();
                $header  = $b64((string) json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT']));
                $payload = $b64((string) json_encode([
                    'iss'       => 'https://securetoken.google.com/' . $projectId,
                    'aud'       => $projectId,
                    'auth_time' => $authTime,
                    'user_id'   => $uid,
                    'sub'       => $uid,
                    'iat'       => $now,
                    'exp'       => $now + 3600,
                    'firebase'  => ['sign_in_provider' => 'google.com', 'identities' => new \stdClass()],
                ]));

                openssl_sign($header . '.' . $payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);

                return $header . '.' . $payload . '.' . $b64((string) $signature);
            };

            $mismatch = Http::post('/me/privacy/exports', ['id_token' => $mint('it-pa-someone-else', time())], 'pa-phone');
            Suite::equals('a sign-in of another Firebase account is refused (reauth_mismatch)', $mismatch->code(), 'reauth_mismatch');

            $stale = Http::post('/me/privacy/exports', ['id_token' => $mint($firebaseUid, time() - 600)], 'pa-phone');
            Suite::equals('a sign-in from ten minutes ago is refused (reauth_stale)', $stale->code(), 'reauth_stale');

            $fresh    = $mint($firebaseUid, time());
            $accepted = Http::post('/me/privacy/exports', ['id_token' => $fresh], 'pa-phone');
            Suite::ok('a fresh sign-in of the linked account confirms an export (201)', $accepted, 201);
            Suite::equals(
                '…recorded as confirmed by Firebase',
                Database::scalar(
                    'SELECT `verified_via` FROM `data_export_requests` WHERE `id` = :id',
                    ['id' => (int) $accepted->at('data.export.id')]
                ),
                'firebase'
            );

            // Another route, because the export cooldown now refuses before
            // any proof is looked at.
            $reused = Http::post('/auth/change-password', [
                'password'              => 'PaPhone#2024x',
                'password_confirmation' => 'PaPhone#2024x',
                'id_token'              => $fresh,
            ], 'pa-phone');
            Suite::equals('the same sign-in cannot confirm a second action (reauth_reused)', $reused->code(), 'reauth_reused');
            Suite::equals(
                '…and no password was set with it',
                Database::scalar('SELECT `password_hash` FROM `users` WHERE `id` = :id', ['id' => $phId]),
                null
            );
        }
    } finally {
        if ($cacheTouched) {
            if ($cacheBackup === null) {
                @unlink($cacheFile);
            } else {
                file_put_contents($cacheFile, $cacheBackup, LOCK_EX);
            }
        }

        $restoreSettings();
        Database::update('users', [
            'email'          => $phoneRow['email'] ?? $paActors['pa-phone']['email'],
            'password_hash'  => $phoneRow['password_hash'] ?? Crypto::hashPassword($paActors['pa-phone']['password']),
            'auth_providers' => $phoneRow['auth_providers'] ?? '["password"]',
            'firebase_uid'   => $phoneRow['firebase_uid'] ?? null,
        ], ['id' => $phId]);
        RateLimiter::clear($gateKey($phId));
        RateLimiter::clear('throttle:privacy-export:user:' . $phId);
        RateLimiter::clear('throttle:password:user:' . $phId);
        $purgeExports($phId);
    }
}

// =================================================================
Suite::group('Privacy auth: OTP sign-in');
// =================================================================

if ($paActors['pa-link'] === null) {
    Suite::skipGroup('Privacy auth: OTP sign-in', 'could not create pa-link: ' . Actors::lastError());
} else {
    $linkId   = $actorId('pa-link');
    $linkUser = Database::selectOne('SELECT `phone`, `phone_country_code` FROM `users` WHERE `id` = :id', ['id' => $linkId]) ?? [];
    $phone    = (string) ($linkUser['phone_country_code'] ?? '+91') . (string) ($linkUser['phone'] ?? '');

    try {
        $setSetting('auth', 'otp_login_enabled', '0');

        $off = Http::post('/auth/otp/verify', ['destination' => $phone, 'code' => '123456', 'purpose' => 'login']);
        Suite::status('OTP sign-in is refused while switched off (403)', $off, 403);
        Suite::equals('…as auth_method_disabled, not a fake success', $off->code(), 'auth_method_disabled');

        if (empty($linkUser['phone']) || !AuthConfigService::enabled('mobile_otp', true)) {
            $paNote('OTP sign-in success skipped: ' . (empty($linkUser['phone']) ? 'pa-link has no phone' : 'mobile_otp is switched off'));
        } else {
            $setSetting('auth', 'otp_login_enabled', '1');

            $code = $plant($linkId, $phone, 'login', 'sms');
            $in   = Http::post('/auth/otp/verify', ['destination' => $phone, 'code' => $code['code'], 'purpose' => 'login']);
            Suite::ok('a right code signs in', $in, 200);
            Suite::equals('…as the account the number belongs to', (int) ($in->at('data.user.id') ?? 0), $linkId);
            Suite::notEmpty('…with a session', $in->at('data.tokens.access_token'));

            do {
                $nobody = '+919' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            } while (Database::scalar(
                "SELECT 1 FROM `users` WHERE `phone` = :p AND `phone_country_code` = '+91'",
                ['p' => substr($nobody, 3)]
            ) !== null);

            $unknownCode = $plant($linkId, $nobody, 'login', 'sms');
            $unknown     = Http::post('/auth/otp/verify', ['destination' => $nobody, 'code' => $unknownCode['code'], 'purpose' => 'login']);
            Suite::status('a number with no account is told to sign up (404)', $unknown, 404);
            Suite::equals('…as account_not_found', $unknown->code(), 'account_not_found');

            Database::execute('DELETE FROM `otp_verifications` WHERE `destination` = :d', ['d' => $nobody]);
        }
    } finally {
        $restoreSettings();
    }
}

// =================================================================
Suite::group('Privacy auth: OTP sends are limited per address');
// =================================================================

$otpDestination = 'it-pa-otp-' . bin2hex(random_bytes(4)) . '@zuste.test';

try {
    $setSetting('mail', 'driver', 'log');
    $setSetting('auth', 'otp_resend_seconds', '45');
    $setSetting('auth', 'otp_allowed_country_codes', '["+91"]');

    $first = Http::post('/auth/otp/send', ['destination' => $otpDestination, 'channel' => 'email', 'purpose' => 'verify_email']);
    Suite::ok('a first code goes out', $first, 200);
    Suite::check('…and the app is told when it may ask again', (int) ($first->at('data.resend_in') ?? 0) > 0, $first->describe());
    Suite::check('…without the code row id', $first->at('data.otp_id') === null, $first->describe());

    $second = Http::post('/auth/otp/send', ['destination' => $otpDestination, 'channel' => 'email', 'purpose' => 'verify_email']);
    Suite::status('a second one straight away is refused (429)', $second, 429);
    Suite::equals('…as otp_cooldown', $second->code(), 'otp_cooldown');
    Suite::check('…with Retry-After', (int) $second->header('retry-after') > 0, $second->describe());

    $abroadBefore = (int) Database::scalar(
        "SELECT COUNT(*) FROM `otp_verifications` WHERE `destination` = '+447911123456'"
    );
    $abroad = Http::post('/auth/otp/send', ['destination' => '+447911123456', 'channel' => 'sms', 'purpose' => 'login']);
    Suite::status('a number in a country not allowed is refused (422)', $abroad, 422);
    Suite::equals('…as phone_country_not_supported', $abroad->code(), 'phone_country_not_supported');
    Suite::equals(
        '…and no code is written',
        (int) Database::scalar("SELECT COUNT(*) FROM `otp_verifications` WHERE `destination` = '+447911123456'"),
        $abroadBefore
    );
} finally {
    $restoreSettings();
    Database::execute('DELETE FROM `otp_verifications` WHERE `destination` = :d', ['d' => $otpDestination]);
    RateLimiter::clear('otp:cooldown:verify_email:' . $otpDestination);
    RateLimiter::clear('otp:send:verify_email:' . $otpDestination);
}

// =================================================================
Suite::group('Privacy auth: Firebase links only a vouched-for identity');
// =================================================================

if ($paActors['pa-link'] === null) {
    Suite::skipGroup('Privacy auth: Firebase links only a vouched-for identity', 'could not create pa-link: ' . Actors::lastError());
} else {
    $linkId    = $actorId('pa-link');
    $linkRow   = Database::selectOne('SELECT `email`, `phone`, `phone_country_code`, `firebase_uid` FROM `users` WHERE `id` = :id', ['id' => $linkId]) ?? [];
    $linkEmail = (string) ($linkRow['email'] ?? '');
    $linkPhone = (string) ($linkRow['phone_country_code'] ?? '') . (string) ($linkRow['phone'] ?? '');
    $claims    = static fn (array $over): array => $over + [
        'uid'            => 'it-pa-new-' . bin2hex(random_bytes(4)),
        'email'          => null,
        'email_verified' => false,
        'phone'          => null,
        'provider'       => 'google.com',
    ];

    try {
        $setSetting('security', 'privacy_contact_hold_hours', '72');
        Database::update('users', ['firebase_uid' => null], ['id' => $linkId]);

        if ($has048) {
            Database::execute('UPDATE `users` SET `email_changed_at` = NULL, `phone_changed_at` = NULL WHERE `id` = :id', ['id' => $linkId]);
        }

        // What a lookup answers: the matched account id, 0 for none, or the
        // refusal's error code, so a surprise refusal fails a check instead of
        // stopping the run.
        $target = static function (array $claims): int|string {
            try {
                $row = AuthService::firebaseLinkTarget($claims);
            } catch (HttpException $e) {
                return $e->status() . ' ' . $e->errorCode();
            }

            return $row === null ? 0 : (int) $row['id'];
        };

        Suite::equals(
            'an unverified email from a password sign-in links nothing',
            $target($claims(['email' => $linkEmail, 'email_verified' => false, 'provider' => 'password'])),
            0
        );
        Suite::equals(
            'nor does a verified one from a provider that does not own it',
            $target($claims(['email' => $linkEmail, 'email_verified' => true, 'provider' => 'password'])),
            0
        );
        Suite::equals(
            'a Google-verified email finds the account',
            $target($claims(['email' => strtoupper($linkEmail), 'email_verified' => true])),
            $linkId
        );

        Database::update('users', ['firebase_uid' => 'it-pa-other-' . $linkId], ['id' => $linkId]);

        Suite::equals(
            'an account linked to another Firebase identity is not taken over (409)',
            $target($claims(['email' => $linkEmail, 'email_verified' => true])),
            '409 account_link_conflict'
        );
        Suite::equals(
            '…while its own identity still signs in by uid',
            $target($claims(['uid' => 'it-pa-other-' . $linkId])),
            $linkId
        );

        Database::update('users', ['firebase_uid' => null], ['id' => $linkId]);

        if ($linkPhone !== '' && !empty($linkRow['phone'])) {
            Suite::equals(
                'a Firebase phone sign-in finds the account by its number',
                $target($claims(['phone' => $linkPhone, 'provider' => 'phone'])),
                $linkId
            );
            Suite::equals(
                '…but a phone claim from another provider does not',
                $target($claims(['phone' => $linkPhone, 'provider' => 'google.com'])),
                0
            );
        }

        if ($has048) {
            Database::execute('UPDATE `users` SET `email_changed_at` = NOW() WHERE `id` = :id', ['id' => $linkId]);

            Suite::equals(
                'a recently changed email cannot be linked (409)',
                $target($claims(['email' => $linkEmail, 'email_verified' => true])),
                '409 account_link_held'
            );
        }
    } finally {
        $restoreSettings();
        Database::update('users', ['firebase_uid' => $linkRow['firebase_uid'] ?? null], ['id' => $linkId]);

        if ($has048) {
            Database::execute('UPDATE `users` SET `email_changed_at` = NULL, `phone_changed_at` = NULL WHERE `id` = :id', ['id' => $linkId]);
        }
    }
}

// =================================================================
Suite::group('Privacy auth: export links die with sessions');
// =================================================================

if ($paActors['pa-link'] === null) {
    Suite::skipGroup('Privacy auth: export links die with sessions', 'could not create pa-link: ' . Actors::lastError());
} else {
    $linkId  = $actorId('pa-link');
    $seeded  = [];
    $seed    = static function (int $userId) use (&$seeded): int {
        $id = Database::insert('data_export_requests', [
            'user_id'       => $userId,
            'status'        => 'ready',
            'token_hash'    => Crypto::hashToken(Crypto::randomToken(32)),
            'verified_via'  => 'password',
            'max_downloads' => 5,
            'expires_at'    => date('Y-m-d H:i:s', time() + 86400),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $seeded[] = $id;

        return $id;
    };
    $tokenHash = static fn (int $id): mixed => Database::scalar(
        'SELECT `token_hash` FROM `data_export_requests` WHERE `id` = :id',
        ['id' => $id]
    );

    try {
        $first = $seed($linkId);
        AuthService::revokeAllTokens($linkId);
        Suite::equals('signing out everywhere retires the export link', $tokenHash($first), null);

        $login   = Http::post('/auth/login', ['identifier' => $paActors['pa-link']['email'], 'password' => $paActors['pa-link']['password']]);
        $session = (int) Database::scalar(
            'SELECT `id` FROM `refresh_tokens` WHERE `user_id` = :u AND `revoked_at` IS NULL ORDER BY `id` DESC LIMIT 1',
            ['u' => $linkId]
        );

        if ($session === 0) {
            Suite::check('a session to sign out exists', false, $login->describe());
        } else {
            $second  = $seed($linkId);
            $revoked = Http::delete('/auth/sessions/' . $session, null, 'pa-link');
            Suite::ok('a device is signed out', $revoked, [200, 204]);
            Suite::equals('…and that retires the export link too', $tokenHash($second), null);
        }
    } finally {
        foreach ($seeded as $id) {
            Database::execute('DELETE FROM `data_export_requests` WHERE `id` = :id', ['id' => $id]);
        }
    }
}

// =================================================================
Suite::group('Privacy auth: a deleted account\'s old token says so');
// =================================================================

if ($paActors[$tombName] === null) {
    Suite::skipGroup('Privacy auth: a deleted account\'s old token says so', 'could not create the tombstone actor: ' . Actors::lastError());
} else {
    $tombId     = $actorId($tombName);
    $deletionId = 0;
    $blacklist  = '';

    try {
        $tablet = Http::post('/auth/login', ['identifier' => $paActors[$tombName]['email'], 'password' => $paActors[$tombName]['password']]);
        $tabletToken = (string) ($tablet->at('data.tokens.access_token') ?? '');
        Http::setToken($tombName . '-tablet', $tabletToken);

        // Soft-deleted the way staff do it: no deletion record.
        Database::execute(
            "UPDATE `users` SET `status` = 'deleted', `deleted_at` = NOW() WHERE `id` = :id",
            ['id' => $tombId]
        );

        $missing = Http::get('/auth/me', [], $tombName);
        Suite::status('a staff soft-delete ends the session (401)', $missing, 401);
        Suite::equals('…as account_missing', $missing->code(), 'account_missing');

        AuthService::registerDevice($tombId, ['device_id' => 'it-pa-tomb-device', 'platform' => 'android']);
        Suite::equals(
            'a device cannot be registered to a deleted account',
            (int) Database::scalar(
                "SELECT COUNT(*) FROM `user_devices` WHERE `user_id` = :u AND `device_id` = 'it-pa-tomb-device'",
                ['u' => $tombId]
            ),
            0
        );

        // Now erased by its owner: the deletion record is what tells them apart.
        try {
            $deletionId = Database::insert('account_deletion_requests', [
                'user_id'      => $tombId,
                'account_type' => 'brand',
                'verified_via' => 'password',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Suite::check('a deletion record can be written for the tombstone', false, $e->getMessage());
        }

        $claims = $tabletToken === '' ? null : Jwt::peek($tabletToken);

        if ($deletionId === 0) {
            $paNote('account_deleted checks skipped: no deletion record');
        } elseif (!is_array($claims) || empty($claims['jti'])) {
            $gone = Http::get('/auth/me', [], $tombName);
            Suite::equals('an erased account\'s token is refused as account_deleted', $gone->code(), 'account_deleted');
            Suite::check('a second session was signed in', false, $tablet->describe());
        } else {
            $gone = Http::get('/auth/me', [], $tombName);
            Suite::status('an erased account\'s token is refused (401)', $gone, 401);
            Suite::equals('…as account_deleted', $gone->code(), 'account_deleted');

            $blacklist = (string) $claims['jti'];
            Jwt::revoke($blacklist, $tombId, (int) ($claims['exp'] ?? time() + 3600));

            $revoked = Http::get('/auth/me', [], $tombName . '-tablet');
            Suite::status('a revoked token of an erased account is refused (401)', $revoked, 401);
            Suite::equals('…as account_deleted too', $revoked->code(), 'account_deleted');
        }
    } finally {
        // Left as an anonymous tombstone, like a real erasure.
        Database::execute(
            "UPDATE `users` SET `status` = 'deleted', `deleted_at` = COALESCE(`deleted_at`, NOW()),
                    `email` = NULL, `phone` = NULL, `password_hash` = NULL, `name` = 'Deleted user'
              WHERE `id` = :id",
            ['id' => $tombId]
        );

        if ($deletionId > 0) {
            Database::execute('DELETE FROM `account_deletion_requests` WHERE `id` = :id', ['id' => $deletionId]);
        }

        if ($blacklist !== '') {
            Database::execute('DELETE FROM `token_blacklist` WHERE `jti` = :j', ['j' => $blacklist]);
        }

        Database::execute('DELETE FROM `refresh_tokens` WHERE `user_id` = :id', ['id' => $tombId]);
        Http::forgetToken($tombName . '-tablet');
    }
}

// =================================================================
// Cleanup: codes, queued mail and audit rows this run produced.
// =================================================================

foreach ($paActors as $actor) {
    if ($actor === null) {
        continue;
    }

    $uid = (int) $actor['id'];

    Database::execute('DELETE FROM `otp_verifications` WHERE `user_id` = :id', ['id' => $uid]);
    Database::execute(
        "DELETE FROM `activity_logs` WHERE `action` = 'privacy.reauth_failed' AND `subject_type` = 'user' AND `subject_id` = :id",
        ['id' => $uid]
    );
    RateLimiter::clear($gateKey($uid));
}

Database::execute("DELETE FROM `email_queue` WHERE `to_email` LIKE 'it-pa-%@zuste.test'");

// Nothing here erases an account on purpose. Should a refusal above ever fail
// open, the erasure ledger must not carry this suite's accounts into a
// restore, so their lines are dropped.
$paLedger = rtrim((string) Config::get('storage.base_path'), '/\\') . '/privacy/erasure-ledger.jsonl';
$paIds    = array_map(static fn (array $a): int => (int) $a['id'], array_values(array_filter($paActors)));

if ($paIds !== [] && is_file($paLedger)) {
    $lines = file($paLedger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $kept  = array_values(array_filter(
        $lines,
        static fn (string $line): bool => !in_array((int) (json_decode($line, true)['user_id'] ?? 0), $paIds, true)
    ));

    if (count($kept) !== count($lines)) {
        file_put_contents($paLedger, $kept === [] ? '' : implode("\n", $kept) . "\n", LOCK_EX);
    }
}

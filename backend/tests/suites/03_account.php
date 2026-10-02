<?php

declare(strict_types=1);

/**
 * The authenticated account area.
 *
 * GET/PUT /me, notification preferences, devices, /me/upload, favourites,
 * referrals, reviews received and sessions.
 *
 * The centre of gravity here is mass assignment: /me is the one endpoint an
 * ordinary user is allowed to write to, and the columns next door to the
 * writable ones decide account type, moderation status, KYC state and money.
 * Every escalation attempt below is followed by a fresh read, because a
 * response body that merely echoes the request proves nothing.
 */

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// A run makes hundreds of calls in seconds; without this the throttle
// fires mid-suite and the cascade of 429s hides real failures.
Suite::clearRateLimits();

// -----------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------

$account = [
    'brand'   => Actors::create('brand', 'brand'),
    'creator' => Actors::create('creator', 'creator'),
];

/** Files created in the system temp dir for the upload group. */
$tempFiles = [];

/** Media rows created through /me/upload, removed at the end of the suite. */
$uploadedMedia = [];

/**
 * Reads a single column straight off the caller's own /me payload.
 *
 * Read-back always goes through the API rather than the database, so the
 * assertion covers the serialiser as well as the write.
 */
$readProfile = static function (string $actor): array {
    $me = Http::get('/me', [], $actor);
    $user = $me->at('data.user');

    return is_array($user) ? $user : [];
};

// -----------------------------------------------------------------
Suite::group('Account — the authenticated boundary');

// Every route in the /me group carries `auth`; a missing or malformed bearer
// must never fall through to a partially populated response.
$protected = [
    ['GET', '/me'],
    ['PUT', '/me'],
    ['POST', '/me'],
    ['PUT', '/me/notification-preferences'],
    ['POST', '/me/devices'],
    ['POST', '/me/upload'],
    ['GET', '/me/favorites'],
    ['GET', '/me/referrals'],
    ['GET', '/me/reviews'],
    ['GET', '/auth/sessions'],
];

foreach ($protected as [$method, $path]) {
    $anonymous = match ($method) {
        'GET'   => Http::get($path),
        'PUT'   => Http::put($path, []),
        default => Http::post($path, []),
    };

    Suite::check(
        sprintf('%s %s without a token is 401', $method, $path),
        $anonymous->status === 401 && $anonymous->code() === 'unauthenticated',
        $anonymous->describe()
    );
}

$forged = Http::get('/me', [], null, ['Authorization' => 'Bearer eyJ.forged.token']);
Suite::status('GET /me with a forged token is 401', $forged, 401);

$creatorFavouritesAnon = Http::post('/creators/1/favorite', [], null);
Suite::status('favouriting without a token is 401', $creatorFavouritesAnon, 401);

if ($account['brand'] === null) {
    Suite::skipGroup(
        'Account — everything requiring a session',
        'could not obtain a brand session: ' . Actors::lastError()
    );

    return;
}

$brandId = Actors::id('brand');

// -----------------------------------------------------------------
Suite::group('Account — reading the profile');

$me = Http::get('/me', [], 'brand');
Suite::ok('GET /me returns the success envelope', $me);
Suite::equals('GET /me returns the caller, not another account', (int) ($me->at('data.user.id') ?? 0), $brandId);
Suite::equals('GET /me returns the caller\'s email', (string) ($me->at('data.user.email') ?? ''), Actors::email('brand'));

Suite::check(
    'GET /me never leaks the password hash or the last login IP',
    static function () use ($me): bool {
        $user = is_array($me->at('data.user')) ? $me->at('data.user') : [];

        return !array_key_exists('password_hash', $user)
            && !array_key_exists('last_login_ip', $user)
            && !str_contains($me->body, '$2y$');
    },
    'sensitive columns were present in: ' . $me->describe()
);

Suite::check(
    'GET /me carries the notification preference map',
    is_array($me->at('data.user.notification_prefs')),
    $me->describe()
);

Suite::check(
    'GET /me carries the unread badge counts the app renders',
    is_int($me->at('data.badges.unread_notifications')) && is_int($me->at('data.badges.unread_messages')),
    $me->describe()
);

// -----------------------------------------------------------------
Suite::group('Account — updating the profile persists');

$stamp = substr((string) time(), -6);

$firstWrite = Http::put('/me', [
    'name'         => 'IT Brand ' . $stamp,
    'bio'          => 'Integration bio ' . $stamp,
    'company_name' => 'IT Co ' . $stamp,
    'city'         => 'Pune',
    'state'        => 'Maharashtra',
    'postal_code'  => '411001',
    'website'      => 'https://zuste.test/it-' . $stamp,
], 'brand');

Suite::ok('PUT /me accepts a legitimate profile update', $firstWrite);
Suite::equals('the update response echoes the new name', (string) ($firstWrite->at('data.user.name') ?? ''), 'IT Brand ' . $stamp);

// The response body is the controller's own re-read; a separate GET proves the
// row on disk actually changed rather than the payload being reflected back.
$afterFirst = $readProfile('brand');

Suite::equals('the new name survives a fresh read', (string) ($afterFirst['name'] ?? ''), 'IT Brand ' . $stamp);
Suite::equals('the new bio survives a fresh read', (string) ($afterFirst['bio'] ?? ''), 'Integration bio ' . $stamp);
Suite::equals('the new company name survives a fresh read', (string) ($afterFirst['company_name'] ?? ''), 'IT Co ' . $stamp);
Suite::equals('the new city survives a fresh read', (string) ($afterFirst['city'] ?? ''), 'Pune');
Suite::equals('the new postal code survives a fresh read', (string) ($afterFirst['postal_code'] ?? ''), '411001');
Suite::equals('the new website survives a fresh read', (string) ($afterFirst['website'] ?? ''), 'https://zuste.test/it-' . $stamp);

// A second, different write rules out a fixture that happened to match.
$secondWrite = Http::put('/me', ['city' => 'Bengaluru', 'state' => 'Karnataka'], 'brand');
Suite::ok('PUT /me accepts a second update', $secondWrite);

$afterSecond = $readProfile('brand');
Suite::equals('the second update replaced the first', (string) ($afterSecond['city'] ?? ''), 'Bengaluru');
Suite::equals(
    'a field left out of the second update is untouched',
    (string) ($afterSecond['name'] ?? ''),
    'IT Brand ' . $stamp
);

// -----------------------------------------------------------------
Suite::group('Account — mass assignment');

// Snapshot everything an attacker would want to move, through the API.
$before        = $readProfile('brand');
$walletBefore  = Http::get('/wallet', [], 'brand');
$referralBefore = Http::get('/me/referrals', [], 'brand');

$balanceBefore  = $walletBefore->at('data.balance.balance');
$referralBeforeCode = (string) ($referralBefore->at('data.summary.code') ?? '');

Suite::ok('the wallet is readable before the escalation attempt', $walletBefore);
Suite::notEmpty('a referral code is readable before the escalation attempt', $referralBeforeCode);

// The canary rides along in the same request: if it lands, the request was
// genuinely processed and the protected fields were *ignored*, not merely
// rejected wholesale.
$canary = 'IT Canary ' . $stamp;

$escalationPayload = [
    'name'                 => $canary,
    'id'                   => 999999,
    'uuid'                 => '00000000-0000-0000-0000-000000000000',
    'account_type'         => 'creator',
    'status'               => 'suspended',
    'kyc_status'           => 'verified',
    'is_identity_verified' => 1,
    'is_business_verified' => 1,
    'is_premium_verified'  => 1,
    'role'                 => 'admin',
    'is_admin'             => true,
    'balance'              => 1000000,
    'wallet_balance'       => 1000000,
    'locked_balance'       => 1000000,
    'referral_code'        => 'HACKED01',
    'referred_by'          => 1,
    'email_verified_at'    => '2020-01-01 00:00:00',
    'phone_verified_at'    => '2020-01-01 00:00:00',
    'password_hash'        => '$2y$10$abcdefghijklmnopqrstuv',
    'deleted_at'           => null,
];

$escalation = Http::put('/me', $escalationPayload, 'brand');

Suite::check(
    'PUT /me either refuses the privileged fields or ignores them',
    in_array($escalation->status, [200, 400, 422], true),
    'expected the request to be accepted-and-ignored or rejected, got ' . $escalation->describe()
);

// ---- the read-back, which is the assertion that actually matters ----
$after = $readProfile('brand');

$protectedFields = [
    'id'                   => $before['id'] ?? null,
    'uuid'                 => $before['uuid'] ?? null,
    'account_type'         => $before['account_type'] ?? null,
    'status'               => $before['status'] ?? null,
    'kyc_status'           => $before['kyc_status'] ?? null,
    'is_identity_verified' => $before['is_identity_verified'] ?? null,
    'is_business_verified' => $before['is_business_verified'] ?? null,
    'is_premium_verified'  => $before['is_premium_verified'] ?? null,
    'referred_by'          => $before['referred_by'] ?? null,
    'email_verified_at'    => $before['email_verified_at'] ?? null,
    'phone_verified_at'    => $before['phone_verified_at'] ?? null,
];

foreach ($protectedFields as $field => $expected) {
    Suite::check(
        sprintf('read-back: `%s` did not change', $field),
        ($after[$field] ?? null) === $expected,
        sprintf(
            'expected %s, got %s — PUT /me wrote a column the caller must not control',
            json_encode($expected),
            json_encode($after[$field] ?? null)
        )
    );
}

Suite::check(
    'read-back: the account is still active and unverified',
    ($after['status'] ?? null) === 'active' && empty($after['is_identity_verified']),
    'status=' . json_encode($after['status'] ?? null)
        . ' is_identity_verified=' . json_encode($after['is_identity_verified'] ?? null)
);

Suite::check(
    'read-back: no `role` or `is_admin` column was invented on the row',
    !array_key_exists('role', $after) && !array_key_exists('is_admin', $after),
    'the escalation payload created a privilege column: ' . json_encode(array_intersect_key($after, ['role' => 1, 'is_admin' => 1]))
);

$walletAfter = Http::get('/wallet', [], 'brand');
Suite::equals(
    'read-back: the wallet balance did not move',
    $walletAfter->at('data.balance.balance'),
    $balanceBefore
);
Suite::equals(
    'read-back: the locked wallet balance did not move',
    $walletAfter->at('data.balance.locked'),
    $walletBefore->at('data.balance.locked')
);

$referralAfter = Http::get('/me/referrals', [], 'brand');
Suite::equals(
    'read-back: the referral code was not overwritten',
    (string) ($referralAfter->at('data.summary.code') ?? ''),
    $referralBeforeCode
);

Suite::check(
    'the escalation request was processed, so the protected fields were ignored rather than the whole call refused',
    ($after['name'] ?? null) === $canary || $escalation->status === 422,
    'the canary field did not land and the call was not rejected either — '
        . 'this check exists so "nothing changed" cannot be explained by the request never running'
);

// The multipart-friendly POST alias reaches the same controller, so it needs
// the same guarantee.
$escalationPost = Http::post('/me', $escalationPayload, 'brand');
Suite::check(
    'POST /me either refuses the privileged fields or ignores them',
    in_array($escalationPost->status, [200, 400, 422], true),
    $escalationPost->describe()
);

$afterPost = $readProfile('brand');
Suite::check(
    'read-back after POST /me: account_type, status and kyc_status are unchanged',
    ($afterPost['account_type'] ?? null) === ($before['account_type'] ?? null)
        && ($afterPost['status'] ?? null) === ($before['status'] ?? null)
        && ($afterPost['kyc_status'] ?? null) === ($before['kyc_status'] ?? null),
    'account_type=' . json_encode($afterPost['account_type'] ?? null)
        . ' status=' . json_encode($afterPost['status'] ?? null)
        . ' kyc_status=' . json_encode($afterPost['kyc_status'] ?? null)
);

// A signed-in caller must not be able to edit somebody else by id, either.
$crossWrite = Http::put('/me', ['id' => Actors::id('creator'), 'name' => 'IT Hijacked ' . $stamp], 'brand');
Suite::check(
    'PUT /me cannot be aimed at another account by passing its id',
    in_array($crossWrite->status, [200, 422], true),
    $crossWrite->describe()
);

$creatorAfter = $readProfile('creator');
Suite::check(
    'read-back: the other account\'s name is untouched',
    ($creatorAfter['name'] ?? '') !== 'IT Hijacked ' . $stamp,
    'the creator account was renamed through the brand\'s /me: ' . json_encode($creatorAfter['name'] ?? null)
);

// -----------------------------------------------------------------
Suite::group('Account — profile validation');

$invalidUpdates = [
    'a malformed email is rejected'          => [['email' => 'not-an-email'], 'email'],
    'an over-length bio is rejected'         => [['bio' => str_repeat('x', 1001)], 'bio'],
    'an over-length postal code is rejected' => [['postal_code' => str_repeat('9', 13)], 'postal_code'],
    'an over-length company name is rejected' => [['company_name' => str_repeat('c', 151)], 'company_name'],
    'a non-http website URL is rejected'     => [['website' => 'javascript:alert(1)'], 'website'],
    'a malformed website URL is rejected'    => [['website' => 'zuste dot test'], 'website'],
    'a one-character name is rejected'       => [['name' => 'A'], 'name'],
    'a malformed phone number is rejected'   => [['phone' => '12'], 'phone'],
];

foreach ($invalidUpdates as $label => [$payload, $field]) {
    $response = Http::put('/me', $payload, 'brand');

    Suite::status($label, $response, 422);
    Suite::check(
        $label . ' — and names the offending field',
        static function () use ($response, $field): bool {
            $errors = $response->at('errors') ?? $response->at('data.errors');

            return is_array($errors) && array_key_exists($field, $errors);
        },
        'expected an errors map containing `' . $field . '`, got ' . $response->describe()
    );
}

// The email uniqueness rule has to ignore the caller's own row but still
// catch a collision with somebody else's.
if ($account['creator'] !== null) {
    $stealEmail = Http::put('/me', ['email' => Actors::email('creator')], 'brand');
    Suite::status('an email already used by another account is rejected', $stealEmail, 422);

    $ownEmail = Http::put('/me', ['email' => Actors::email('brand')], 'brand');
    Suite::ok('the caller may re-submit its own email without tripping the unique rule', $ownEmail);
} else {
    Suite::skipGroup(
        'Account — profile validation (email collision)',
        'no second actor: ' . Actors::lastError()
    );
}

$rejected = $readProfile('brand');
Suite::equals(
    'no rejected update leaked through — the email is unchanged',
    strtolower((string) ($rejected['email'] ?? '')),
    strtolower(Actors::email('brand'))
);

// `avatar_url` is read straight off the request in AccountController::updateProfile
// (lines 104-106) instead of going through the validator, so unlike `website` it
// accepts any string at all. Asserted here as the requirement, not the behaviour.
$avatarBefore = (string) ($rejected['avatar_url'] ?? '');
$badAvatar    = Http::put('/me', ['avatar_url' => 'javascript:alert(document.domain)'], 'brand');
$afterAvatar  = $readProfile('brand');

Suite::check(
    'an avatar_url that is not an http(s) URL is refused',
    $badAvatar->status === 422
        || (string) ($afterAvatar['avatar_url'] ?? '') !== 'javascript:alert(document.domain)',
    'PUT /me stored avatar_url = ' . json_encode($afterAvatar['avatar_url'] ?? null)
        . ' — the field bypasses the validator entirely (AccountController.php:104)'
);

// Put a sane value back so the residue is harmless whatever the outcome.
Http::put('/me', ['avatar_url' => $avatarBefore !== '' ? $avatarBefore : 'https://zuste.test/it-avatar.png'], 'brand');

// -----------------------------------------------------------------
Suite::group('Account — notification preferences');

$prefsWrite = Http::put('/me/notification-preferences', [
    'push'      => false,
    'sms'       => true,
    'marketing' => false,
], 'brand');

Suite::ok('PUT /me/notification-preferences saves', $prefsWrite);

Suite::check(
    'the response returns the full preference map, not just the changed keys',
    static function () use ($prefsWrite): bool {
        $prefs = $prefsWrite->at('data.preferences');

        return is_array($prefs)
            && array_key_exists('push', $prefs)
            && array_key_exists('email', $prefs)
            && array_key_exists('sms', $prefs)
            && array_key_exists('marketing', $prefs);
    },
    $prefsWrite->describe()
);

$prefsAfter = $readProfile('brand')['notification_prefs'] ?? [];

Suite::equals('push=false round-trips through GET /me', $prefsAfter['push'] ?? null, false);
Suite::equals('sms=true round-trips through GET /me', $prefsAfter['sms'] ?? null, true);
Suite::equals('marketing=false round-trips through GET /me', $prefsAfter['marketing'] ?? null, false);
Suite::check(
    'a channel that was not sent keeps its previous value',
    ($prefsAfter['email'] ?? null) === ($prefsWrite->at('data.preferences.email')),
    'email flipped unexpectedly: ' . json_encode($prefsAfter['email'] ?? null)
);

// Flip back, so the round trip is proved in both directions rather than
// matching a value that happened to already be set.
$prefsRestore = Http::put('/me/notification-preferences', ['push' => true, 'sms' => false, 'marketing' => true], 'brand');
Suite::ok('preferences can be flipped back', $prefsRestore);

$prefsRestored = $readProfile('brand')['notification_prefs'] ?? [];
Suite::equals('push=true round-trips back', $prefsRestored['push'] ?? null, true);
Suite::equals('sms=false round-trips back', $prefsRestored['sms'] ?? null, false);

$prefsBad = Http::put('/me/notification-preferences', ['push' => 'maybe'], 'brand');
Suite::status('a non-boolean preference is rejected', $prefsBad, 422);
Suite::check(
    'the rejection names the offending channel',
    static function () use ($prefsBad): bool {
        $errors = $prefsBad->at('errors') ?? $prefsBad->at('data.errors');

        return is_array($errors) && array_key_exists('push', $errors);
    },
    $prefsBad->describe()
);

Suite::equals(
    'the rejected preference write changed nothing',
    ($readProfile('brand')['notification_prefs'] ?? [])['push'] ?? null,
    true
);

// -----------------------------------------------------------------
Suite::group('Account — device registration');

// There is no read endpoint for devices, so the register is verified against
// `user_devices` directly — the row is the whole point of the call.
$deviceId = 'it-device-' . $stamp;

$deviceWrite = Http::post('/me/devices', [
    'device_id'    => $deviceId,
    'device_model' => 'IT Handset',
    'os_version'   => '14',
    'app_version'  => '1.0.0',
    'fcm_token'    => 'it-fcm-' . $stamp,
    'locale'       => 'en',
], 'brand');

Suite::ok('POST /me/devices registers a device', $deviceWrite);

$deviceRow = Database::selectOne(
    'SELECT * FROM `user_devices` WHERE `user_id` = :user AND `device_id` = :device',
    ['user' => $brandId, 'device' => $deviceId]
);

Suite::check('the device row exists and belongs to the caller', $deviceRow !== null, 'no user_devices row for ' . $deviceId);
Suite::equals('the FCM token was stored', (string) ($deviceRow['fcm_token'] ?? ''), 'it-fcm-' . $stamp);
Suite::equals('the device model was stored', (string) ($deviceRow['device_model'] ?? ''), 'IT Handset');

// Re-registering the same device must refresh it, not accumulate rows —
// otherwise every app launch leaks a push target.
$deviceRefresh = Http::post('/me/devices', [
    'device_id' => $deviceId,
    'fcm_token' => 'it-fcm-rotated-' . $stamp,
], 'brand');

Suite::ok('re-registering the same device id succeeds', $deviceRefresh);

$deviceCount = (int) Database::scalar(
    'SELECT COUNT(*) FROM `user_devices` WHERE `user_id` = :user AND `device_id` = :device',
    ['user' => $brandId, 'device' => $deviceId]
);
Suite::equals('re-registering updates in place rather than duplicating', $deviceCount, 1);

Suite::equals(
    'the rotated FCM token replaced the old one',
    (string) (Database::scalar(
        'SELECT `fcm_token` FROM `user_devices` WHERE `user_id` = :user AND `device_id` = :device',
        ['user' => $brandId, 'device' => $deviceId]
    ) ?? ''),
    'it-fcm-rotated-' . $stamp
);

// A registration with no device id stores nothing (AuthService::registerDevice
// returns early on an empty id), so reporting success is a false promise to a
// client that now believes push is wired up.
$devicesBeforeNoop = (int) Database::scalar(
    'SELECT COUNT(*) FROM `user_devices` WHERE `user_id` = :user',
    ['user' => $brandId]
);

$deviceNoId = Http::post('/me/devices', ['fcm_token' => 'it-orphan-' . $stamp], 'brand');

Suite::status('a device registration with no device id is rejected', $deviceNoId, [400, 422]);

Suite::equals(
    'a device registration with no device id creates no row',
    (int) Database::scalar('SELECT COUNT(*) FROM `user_devices` WHERE `user_id` = :user', ['user' => $brandId]),
    $devicesBeforeNoop
);

// -----------------------------------------------------------------
Suite::group('Account — file upload');

$temp = rtrim(sys_get_temp_dir(), '/\\');

// A real PNG, built here rather than committed, so the bytes are genuine and
// survive the GD re-encode the service runs on every image.
$pngPath = $temp . '/it-account-' . $stamp . '.png';
$pngW    = 1;
$pngH    = 1;

if (extension_loaded('gd')) {
    $canvas = imagecreatetruecolor(32, 24);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 184, 0, 73));
    imagepng($canvas, $pngPath);
    imagedestroy($canvas);
    $pngW = 32;
    $pngH = 24;
} else {
    file_put_contents($pngPath, (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true
    ));
}

$phpPath    = $temp . '/it-account-' . $stamp . '.php';
$doublePath = $temp . '/it-account-evil-' . $stamp . '.php.png';

// Deliberately *not* a real webshell: on-access antivirus quarantines an
// eval($_REQUEST) payload before curl can read it, which would break the test
// rather than the server. Executable PHP source is enough — the server decides
// on the extension and on the sniffed content type, not on how nasty the code is.
$phpSource = "<?php echo 'zuste integration upload probe'; ?>\n";

file_put_contents($phpPath, $phpSource);
file_put_contents($doublePath, $phpSource);

$tempFiles = [$pngPath, $phpPath, $doublePath];

if (!is_file($pngPath) || !is_file($phpPath) || !is_file($doublePath)) {
    Suite::skipGroup(
        'Account — file upload',
        'could not create the fixture files under ' . $temp . ' (on-access antivirus or a read-only temp dir)'
    );
} else {
    $pngUpload = Http::upload('/me/upload', ['file' => $pngPath], ['folder' => 'general', 'kind' => 'image'], 'brand');

    Suite::ok('POST /me/upload accepts a real PNG', $pngUpload, [200, 201]);
    Suite::notEmpty('the upload returns a URL', $pngUpload->at('data.url'));
    Suite::equals('the upload reports the real MIME type', (string) ($pngUpload->at('data.mime') ?? ''), 'image/png');
    Suite::equals('the upload reports the image width', (int) ($pngUpload->at('data.width') ?? 0), $pngW);
    Suite::equals('the upload reports the image height', (int) ($pngUpload->at('data.height') ?? 0), $pngH);

    Suite::check(
        'the stored file is renamed, so the caller\'s filename never reaches disk',
        static function () use ($pngUpload, $stamp): bool {
            $path = (string) ($pngUpload->at('data.path') ?? '');

            return $path !== ''
                && !str_contains($path, 'it-account-' . $stamp)
                && str_ends_with($path, '.png');
        },
        'path was ' . json_encode($pngUpload->at('data.path'))
    );

    if (is_int($pngUpload->at('data.id'))) {
        $uploadedMedia[] = (int) $pngUpload->at('data.id');
    }

    // -- the two rejections the task turns on ---------------------------
    $phpUpload = Http::upload('/me/upload', ['file' => $phpPath], ['folder' => 'general', 'kind' => 'image'], 'brand');

    Suite::status('a .php upload is refused', $phpUpload, [400, 415, 422]);
    Suite::check(
        'the .php refusal does not create a media row',
        $phpUpload->at('data.id') === null,
        $phpUpload->describe()
    );

    // Double extension: the server takes the *last* extension, so the allow-list
    // alone would wave this through — content sniffing is what has to stop it.
    $doubleUpload = Http::upload('/me/upload', ['file' => $doublePath], ['folder' => 'general', 'kind' => 'image'], 'brand');

    Suite::status('an evil.php.png upload is refused', $doubleUpload, [400, 415, 422]);
    Suite::check(
        'the evil.php.png refusal does not create a media row',
        $doubleUpload->at('data.id') === null,
        $doubleUpload->describe()
    );

    Suite::check(
        'neither refusal left a .php file in the uploads tree',
        static function (): bool {
            $root = rtrim((string) Config::get('storage.upload_path'), '/\\');

            if ($root === '' || !is_dir($root)) {
                return true;
            }

            $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

            foreach ($found as $file) {
                if (preg_match('/\.(php[0-9]?|phtml|phar)$/i', $file->getFilename()) === 1) {
                    return false;
                }
            }

            return true;
        },
        'an executable file was written under the uploads root'
    );

    $noFile = Http::post('/me/upload', ['folder' => 'general'], 'brand');
    Suite::status('an upload with no file at all is refused', $noFile, [400, 422]);

    // A folder outside the allow-list must fall back rather than traverse.
    $traversal = Http::upload('/me/upload', ['file' => $pngPath], ['folder' => '../../public', 'kind' => 'image'], 'brand');

    if ($traversal->status === 201 || $traversal->status === 200) {
        Suite::check(
            'an unknown upload folder falls back to `general` instead of escaping the tree',
            static function () use ($traversal): bool {
                $path = (string) ($traversal->at('data.path') ?? '');

                return str_starts_with($path, 'general/') && !str_contains($path, '..');
            },
            'path was ' . json_encode($traversal->at('data.path'))
        );

        if (is_int($traversal->at('data.id'))) {
            $uploadedMedia[] = (int) $traversal->at('data.id');
        }
    } else {
        Suite::status('an unknown upload folder is refused outright', $traversal, [400, 422]);
    }
}

// -----------------------------------------------------------------
Suite::group('Account — favourites');

$creatorProfileId = (int) ($readProfile('creator')['creator_id'] ?? 0);

if ($creatorProfileId <= 0) {
    $creatorProfileId = (int) (Database::scalar(
        'SELECT `id` FROM `creators` WHERE `deleted_at` IS NULL ORDER BY `id` LIMIT 1'
    ) ?? 0);
}

if ($creatorProfileId <= 0) {
    Suite::skipGroup('Account — favourites', 'no creator profile exists to favourite');
} else {
    $favouritesBefore = Http::get('/me/favorites', [], 'brand');
    Suite::ok('GET /me/favorites returns the success envelope', $favouritesBefore);
    Suite::check(
        'the favourites list is a paginated collection',
        is_array($favouritesBefore->at('data')) && is_array($favouritesBefore->at('meta.pagination')),
        $favouritesBefore->describe()
    );

    $totalBefore = (int) ($favouritesBefore->at('meta.pagination.total') ?? -1);
    Suite::check('the favourites list reports a total', $totalBefore >= 0, $favouritesBefore->describe());

    // Captured before the write so the isolation check below compares like
    // with like, whatever the second actor already had saved.
    $otherTotalBefore = $account['creator'] === null
        ? 0
        : (int) (Http::get('/me/favorites', [], 'creator')->at('meta.pagination.total') ?? -1);

    // Start from a known state: if it is already saved, clear it first.
    $alreadySaved = in_array($creatorProfileId, array_map('intval', array_column($favouritesBefore->list(), 'id')), true);

    if ($alreadySaved) {
        Http::post('/creators/' . $creatorProfileId . '/favorite', [], 'brand');
        $favouritesBefore = Http::get('/me/favorites', [], 'brand');
        $totalBefore      = (int) ($favouritesBefore->at('meta.pagination.total') ?? 0);
    }

    // -- add -------------------------------------------------------
    $add = Http::post('/creators/' . $creatorProfileId . '/favorite', [], 'brand');
    Suite::ok('favouriting a creator succeeds', $add);
    Suite::equals('the toggle reports the creator as favourited', $add->at('data.is_favorite'), true);

    $favouritesAfterAdd = Http::get('/me/favorites', [], 'brand');
    $idsAfterAdd        = array_map('intval', array_column($favouritesAfterAdd->list(), 'id'));

    Suite::check(
        'the favourited creator appears in the caller\'s list',
        in_array($creatorProfileId, $idsAfterAdd, true),
        'list held: ' . json_encode($idsAfterAdd)
    );
    Suite::equals(
        'the pagination total grew by exactly one',
        (int) ($favouritesAfterAdd->at('meta.pagination.total') ?? -1),
        $totalBefore + 1
    );

    Suite::check(
        'every row in the list is flagged is_favorite',
        static function () use ($favouritesAfterAdd): bool {
            $rows = $favouritesAfterAdd->list();

            if ($rows === []) {
                return false;
            }

            foreach ($rows as $row) {
                if (($row['is_favorite'] ?? null) !== true) {
                    return false;
                }
            }

            return true;
        },
        $favouritesAfterAdd->describe()
    );

    Suite::check(
        'per_page is capped rather than honoured blindly',
        (int) (Http::get('/me/favorites', ['per_page' => 500], 'brand')->at('meta.pagination.per_page') ?? 0) <= 50,
        'the endpoint accepted an unbounded page size'
    );

    // -- the authorisation boundary --------------------------------
    if ($account['creator'] !== null) {
        $otherList = Http::get('/me/favorites', [], 'creator');
        Suite::ok('a second actor can read its own favourites', $otherList);

        Suite::check(
            'one actor cannot see another actor\'s favourites',
            !in_array($creatorProfileId, array_map('intval', array_column($otherList->list(), 'id')), true),
            'the creator\'s list leaked the brand\'s saved creator: ' . $otherList->describe()
        );

        Suite::equals(
            'the other actor\'s total is unmoved by the brand\'s write',
            (int) ($otherList->at('meta.pagination.total') ?? -1),
            $otherTotalBefore
        );
    } else {
        Suite::skipGroup('Account — favourites (cross-actor)', 'no second actor: ' . Actors::lastError());
    }

    // -- remove ----------------------------------------------------
    $remove = Http::post('/creators/' . $creatorProfileId . '/favorite', [], 'brand');
    Suite::ok('un-favouriting a creator succeeds', $remove);
    Suite::equals('the toggle reports the creator as no longer favourited', $remove->at('data.is_favorite'), false);

    $favouritesAfterRemove = Http::get('/me/favorites', [], 'brand');
    Suite::check(
        'the creator is gone from the list after removal',
        !in_array(
            $creatorProfileId,
            array_map('intval', array_column($favouritesAfterRemove->list(), 'id')),
            true
        ),
        $favouritesAfterRemove->describe()
    );
    Suite::equals(
        'the pagination total returned to its starting value',
        (int) ($favouritesAfterRemove->at('meta.pagination.total') ?? -1),
        $totalBefore
    );

    $missing = Http::post('/creators/999999999/favorite', [], 'brand');
    Suite::status('favouriting a creator that does not exist is 404', $missing, 404);
}

// -----------------------------------------------------------------
Suite::group('Account — referrals');

$referrals = Http::get('/me/referrals', [], 'brand');
Suite::ok('GET /me/referrals returns the Invite & Earn payload', $referrals);

$referralCode = (string) ($referrals->at('data.summary.code') ?? '');
Suite::notEmpty('the caller has a referral code', $referralCode);

Suite::check(
    'the share URL embeds the caller\'s own code',
    $referralCode !== '' && str_contains((string) ($referrals->at('data.summary.share_url') ?? ''), $referralCode),
    'share_url was ' . json_encode($referrals->at('data.summary.share_url'))
);

Suite::check(
    'the referral counters are integers, not strings from the driver',
    is_int($referrals->at('data.summary.total_invites'))
        && is_int($referrals->at('data.summary.pending_invites'))
        && is_int($referrals->at('data.summary.rewarded_invites')),
    $referrals->describe()
);

Suite::check('the invitee list is an array', is_array($referrals->at('data.invitees')), $referrals->describe());

if ($account['creator'] !== null) {
    $otherReferrals = Http::get('/me/referrals', [], 'creator');
    Suite::ok('the second actor gets its own referral payload', $otherReferrals);
    Suite::check(
        'two accounts do not share a referral code',
        $referralCode !== '' && (string) ($otherReferrals->at('data.summary.code') ?? '') !== $referralCode,
        'both accounts returned ' . $referralCode
    );
}

// -----------------------------------------------------------------
$reviewsSeeded = (int) (Database::scalar(
    'SELECT COUNT(*) FROM `reviews` WHERE `is_published` = 1 AND `deleted_at` IS NULL'
) ?? 0);

Suite::group('Account — reviews received');

$reviews = Http::get('/me/reviews', [], 'brand');
Suite::ok('GET /me/reviews returns the success envelope', $reviews);
Suite::check(
    'the review list is a paginated collection',
    is_array($reviews->at('data')) && is_array($reviews->at('meta.pagination')),
    $reviews->describe()
);
Suite::check(
    'the review pagination meta is complete',
    static function () use ($reviews): bool {
        $meta = $reviews->at('meta.pagination');

        return is_array($meta)
            && array_key_exists('total', $meta)
            && array_key_exists('per_page', $meta)
            && array_key_exists('current_page', $meta)
            && array_key_exists('last_page', $meta)
            && array_key_exists('has_more', $meta);
    },
    $reviews->describe()
);

Suite::check(
    'per_page on /me/reviews is capped',
    (int) (Http::get('/me/reviews', ['per_page' => 500], 'brand')->at('meta.pagination.per_page') ?? 0) <= 50,
    'the endpoint accepted an unbounded page size'
);

// The total must agree with what the caller can actually be shown, so a
// mismatched COUNT/SELECT pair (a classic scoping bug) does not slip through.
Suite::equals(
    'the reported total matches the caller\'s published reviews',
    (int) ($reviews->at('meta.pagination.total') ?? -1),
    (int) (Database::scalar(
        'SELECT COUNT(*) FROM `reviews` WHERE `reviewee_id` = :id AND `is_published` = 1 AND `deleted_at` IS NULL',
        ['id' => $brandId]
    ) ?? 0)
);

if ($reviewsSeeded === 0) {
    Suite::skipGroup(
        'Account — reviews received (content)',
        'no published reviews in the database, so row shape and reviewee scoping cannot be proved against real rows'
    );
} else {
    Suite::check(
        'every returned review is addressed to the caller',
        static function () use ($reviews, $brandId): bool {
            foreach ($reviews->list() as $row) {
                if ((int) ($row['reviewee_id'] ?? 0) !== $brandId) {
                    return false;
                }
            }

            return true;
        },
        'a review belonging to another account was returned: ' . $reviews->describe()
    );

    // A caller with no reviews must not be handed the whole table.
    Suite::check(
        'the list is scoped, not the full reviews table',
        count($reviews->list()) <= (int) ($reviews->at('meta.pagination.total') ?? 0),
        'more rows were returned than the reported total: ' . $reviews->describe()
    );
}

// -----------------------------------------------------------------
Suite::group('Account — sessions');

// Sign in again first.
//
// Sessions are backed by `refresh_tokens`, and the auth suite deliberately
// exercises refresh rotation — which revokes the row this shared actor signed
// in with. Without a fresh login the list is legitimately empty here, and the
// assertions below would be measuring suite order rather than the endpoint.
$reauth = Http::post('/auth/login', [
    'identifier' => Actors::email('brand'),
    'password'   => Actors::password('brand'),
]);

$reauthToken = $reauth->at('data.tokens.access_token') ?? $reauth->at('data.access_token');

if (is_string($reauthToken) && $reauthToken !== '') {
    Http::setToken('brand', $reauthToken);
}

$sessions = Http::get('/auth/sessions', [], 'brand');
Suite::ok('GET /auth/sessions lists the caller\'s sessions', $sessions);

$sessionRows = is_array($sessions->at('data.sessions')) ? $sessions->at('data.sessions') : [];
Suite::check('the session list is an array', is_array($sessions->at('data.sessions')), $sessions->describe());
Suite::check('the caller has at least the session it is using', count($sessionRows) >= 1, $sessions->describe());

Suite::check(
    'the session list never exposes the refresh token itself',
    static function () use ($sessions): bool {
        $body = strtolower($sessions->body);

        return !str_contains($body, 'token_hash') && !str_contains($body, '"token"');
    },
    'the session payload contained token material'
);

$brandSessionIds = array_map('intval', array_column($sessionRows, 'id'));

Suite::check(
    'every listed session belongs to the caller',
    static function () use ($brandSessionIds, $brandId): bool {
        if ($brandSessionIds === []) {
            return false;
        }

        $owned = Database::column(
            'SELECT `id` FROM `refresh_tokens` WHERE `user_id` = :id',
            ['id' => $brandId]
        );

        return array_diff($brandSessionIds, array_map('intval', $owned)) === [];
    },
    'the list contained a session id the caller does not own'
);

if ($account['creator'] !== null) {
    $otherSessions   = Http::get('/auth/sessions', [], 'creator');
    $otherSessionIds = array_map('intval', array_column(
        is_array($otherSessions->at('data.sessions')) ? $otherSessions->at('data.sessions') : [],
        'id'
    ));

    Suite::check(
        'two accounts do not share sessions',
        array_intersect($brandSessionIds, $otherSessionIds) === [],
        'session ids overlapped: ' . json_encode(array_intersect($brandSessionIds, $otherSessionIds))
    );

    if ($otherSessionIds !== []) {
        $target = $otherSessionIds[0];

        $hijack = Http::delete('/auth/sessions/' . $target, null, 'brand');
        Suite::status('one actor cannot revoke another actor\'s session', $hijack, [403, 404]);

        // A 404 that still performed the UPDATE would be the worst of both
        // worlds, so confirm the victim's session survived.
        Suite::check(
            'the other actor\'s session survived the attempt',
            (int) (Database::scalar(
                'SELECT COUNT(*) FROM `refresh_tokens` WHERE `id` = :id AND `revoked_at` IS NULL',
                ['id' => $target]
            ) ?? 0) === 1,
            'session ' . $target . ' was revoked by a caller who does not own it'
        );
    } else {
        Suite::skipGroup('Account — sessions (cross-actor revoke)', 'the second actor has no live session to target');
    }
}

$revokeMissing = Http::delete('/auth/sessions/999999999', null, 'brand');
Suite::status('revoking a session that does not exist is 404', $revokeMissing, 404);

// Revoke the caller's own OLDEST session: the newest one is backing this run.
if (count($brandSessionIds) > 1) {
    $own = min($brandSessionIds);

    $revokeOwn = Http::delete('/auth/sessions/' . $own, null, 'brand');
    Suite::ok('a caller can revoke one of its own sessions', $revokeOwn);

    $sessionsAfter = Http::get('/auth/sessions', [], 'brand');
    Suite::check(
        'the revoked session disappears from the list',
        !in_array($own, array_map('intval', array_column(
            is_array($sessionsAfter->at('data.sessions')) ? $sessionsAfter->at('data.sessions') : [],
            'id'
        )), true),
        $sessionsAfter->describe()
    );

    Suite::ok('the caller\'s current session still works after revoking another', Http::get('/me', [], 'brand'));
} else {
    Suite::skipGroup(
        'Account — sessions (self revoke)',
        'only one live session, and revoking it would invalidate the rest of the run'
    );
}

// -----------------------------------------------------------------
Suite::group('Account — cleanup');

// Media rows are not covered by Actors::cleanup, so remove the files and rows
// this suite put on disk.
$mediaRemoved = 0;
$uploadRoot   = rtrim((string) Config::get('storage.upload_path'), '/\\');

foreach ($uploadedMedia as $mediaId) {
    $row = Database::selectOne('SELECT `path` FROM `media` WHERE `id` = :id', ['id' => $mediaId]);

    if ($row !== null && $uploadRoot !== '') {
        $absolute = $uploadRoot . '/' . (string) $row['path'];

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    Database::execute('DELETE FROM `media` WHERE `id` = :id', ['id' => $mediaId]);
    $mediaRemoved++;
}

Suite::equals('every uploaded media row was removed', $mediaRemoved, count($uploadedMedia));

$leftOnDisk = 0;

foreach ($tempFiles as $file) {
    if (is_file($file)) {
        @unlink($file);
    }

    if (is_file($file)) {
        $leftOnDisk++;
    }
}

Suite::equals('every temp fixture file was removed', $leftOnDisk, 0);

// Leave the actor's profile in a recognisable state for the next suite.
Http::put('/me', [
    'name' => 'IT Brand',
    'bio'  => '',
    'city' => '',
], 'brand');

Suite::ok('the brand actor is still usable at the end of the suite', Http::get('/me', [], 'brand'));

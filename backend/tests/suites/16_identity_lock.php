<?php

declare(strict_types=1);

/**
 * The verified name, the admin user screens and the recycle bin around KYC.
 *
 *   * Identity lock (API): while an account shows a blue tick, PUT /me cannot
 *     change `name` / `company_name` and PUT /me/creator cannot change
 *     `display_name` / `handle` — 409 `identity_locked`. Re-sending the
 *     current value alongside an unrelated edit is not a rename and goes
 *     through. GET /me says `identity_locked` so the app can show the name
 *     read-only.
 *   * The creator screen's "Badges & commission" form, driven over HTTP as two
 *     throwaway administrators (full, and a moderator-like role holding
 *     creators.verify but not kyc.badge / kyc.view): an untick really removes
 *     the tick, an unchanged tick never blocks a commission edit, a blank
 *     commission is "no override" (NULL), not 0%.
 *   * KYC document records on the user screens are for kyc.view holders only,
 *     and the legacy queue lists legacy documents only.
 *   * Deleting a user from the panel erases their identity documents.
 *   * The recycle bin never hard-deletes an account with a KYC history (the
 *     kyc_events trail would cascade away without the append-only triggers
 *     firing) and handles a row a RESTRICT foreign key still points at.
 *
 * The panel is session + CSRF protected, so it is driven the way a browser
 * drives it: sign in, read the form's token, post, read the toast. Everything
 * written belongs to `it-idlock-*` accounts and administrators created here,
 * and is removed at the end. "Empty this type" is deliberately NOT exercised:
 * it would hard-delete every binned account in a development database. It
 * shares hardDeleteBinned() with the two paths that are.
 */

use Zuste\Core\Database;
use Zuste\Services\DocumentVault;
use Zuste\Services\KycService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// -----------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------

$ilLockMessage = 'Your name is locked while your account has the verified badge. Contact support to change it.';
$ilStamp       = date('His') . random_int(100, 999);

$ilBrand   = Actors::create('idlock-brand', 'brand');
$ilCreator = Actors::create('idlock-creator', 'creator');

$ilUserId = static function (string $actor): int {
    $id = Actors::id($actor);

    return $id > 0 ? $id : (int) (Database::scalar(
        'SELECT `id` FROM `users` WHERE `email` = :email',
        ['email' => Actors::email($actor)]
    ) ?? 0);
};

$ilBrandId     = $ilBrand === null ? 0 : $ilUserId('idlock-brand');
$ilCreatorUser = $ilCreator === null ? 0 : $ilUserId('idlock-creator');
$ilCreatorId   = $ilCreatorUser > 0
    ? (int) (Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :u', ['u' => $ilCreatorUser]) ?? 0)
    : 0;

/** Strip every way the suite can put a tick or a KYC profile on an actor. */
$ilClearTick = static function (int $userId): void {
    if ($userId <= 0) {
        return;
    }

    Database::execute('UPDATE `users` SET `has_blue_tick` = 0, `blue_tick_at` = NULL, `kyc_profile_id` = NULL WHERE `id` = :u', ['u' => $userId]);
    Database::execute('UPDATE `creators` SET `is_verified` = 0, `verified_at` = NULL WHERE `user_id` = :u', ['u' => $userId]);

    foreach (Database::select('SELECT `file_path` FROM `kyc_documents` WHERE `user_id` = :u', ['u' => $userId]) as $row) {
        DocumentVault::purge((string) ($row['file_path'] ?? ''));
    }

    // kyc_events cascade from kyc_profiles (a foreign-key cascade does not
    // fire the append-only triggers) — strictly the suite's own accounts.
    Database::execute('DELETE FROM `kyc_documents` WHERE `user_id` = :u', ['u' => $userId]);
    Database::execute('DELETE FROM `kyc_profiles` WHERE `user_id` = :u', ['u' => $userId]);
};

$ilSnapshot = static function (string $table, int $id): array {
    return Database::selectOne('SELECT * FROM ' . Database::quoteIdentifier($table) . ' WHERE `id` = :id', ['id' => $id]) ?? [];
};

$ilRestore = static function (string $table, array $row): void {
    if (!isset($row['id'])) {
        return;
    }

    $id = (int) $row['id'];
    unset($row['id']);
    Database::update($table, $row, ['id' => $id]);
};

/** A bare account row for the delete / recycle-bin groups (no signup throttle). */
$ilInsertUser = static function (string $label, ?string $deletedAt = null) use ($ilStamp): int {
    $hex = bin2hex(random_bytes(16));

    return Database::insert('users', [
        'uuid'          => sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)),
        'name'          => 'IT IdLock ' . ucfirst($label),
        'email'         => 'it-idlock-' . $label . '-' . $ilStamp . '@zuste.test',
        'account_type'  => 'creator',
        'status'        => $deletedAt === null ? 'active' : 'deleted',
        'referral_code' => 'ITL' . strtoupper(bin2hex(random_bytes(4))),
        'deleted_at'    => $deletedAt,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
};

$ilPng = sys_get_temp_dir() . '/it-idlock-proof.png';
file_put_contents($ilPng, (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    true
));

/** Is a vault file still on disk? */
$ilFileExists = static function (string $relativePath): bool {
    if ($relativePath === '') {
        return false;
    }

    try {
        DocumentVault::read(['file_path' => $relativePath, 'original_name' => 'x.png', 'mime_type' => 'image/png']);

        return true;
    } catch (\Throwable) {
        return false;
    }
};

// --- the admin panel, driven like a browser ---------------------------

$ilAdminBase = (string) preg_replace('#/api/v\d+/?$#', '', Http::baseUrl());

/** @return array{status:int,body:string,location:?string} */
$ilPanel = static function (string $jar, string $method, string $path, array $fields = []) use ($ilAdminBase): array {
    $ch = curl_init($ilAdminBase . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'zusty-it-identity-lock/1.0',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $raw        = (string) curl_exec($ch);
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    unset($ch); // writes the cookie jar

    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $headerSize), $m);

    return ['status' => $status, 'body' => substr($raw, $headerSize), 'location' => isset($m[1]) ? trim($m[1]) : null];
};

$ilCsrf = static fn (string $body): string => preg_match('/name="_csrf"\s+value="([^"]+)"/', $body, $m) === 1 ? $m[1] : '';

/** @return array{0:string,1:string} [type, message] of the toast a page carries. */
$ilToastOf = static function (string $body): array {
    preg_match('/data-flash="([^"]*)"\s+data-flash-type="([^"]*)"/', $body, $m);

    return [$m[2] ?? '', html_entity_decode($m[1] ?? '', ENT_QUOTES)];
};

/** Post a panel form with a fresh token read from [$from], then read the toast the redirect left. */
$ilSubmit = static function (string $jar, string $from, string $path, array $fields) use ($ilPanel, $ilCsrf, $ilToastOf): array {
    $page = $ilPanel($jar, 'GET', $from);
    $post = $ilPanel($jar, 'POST', $path, ['_csrf' => $ilCsrf($page['body'])] + $fields);
    $next = $ilPanel($jar, 'GET', $from);

    return ['post' => $post, 'toast' => $ilToastOf($next['body']), 'page' => $next];
};

$ilPassword  = 'ItIdLock!' . bin2hex(random_bytes(6));
$ilFullEmail = 'it-idlock-admin@zuste.test';
$ilModEmail  = 'it-idlock-mod@zuste.test';
$ilFullJar   = (string) tempnam(sys_get_temp_dir(), 'zil');
$ilModJar    = (string) tempnam(sys_get_temp_dir(), 'zil');
$ilAdminIds  = [];
$ilRoleId    = 0;

$ilRemoveAdmins = static function (): void {
    Database::execute("DELETE FROM `admins` WHERE `email` IN ('it-idlock-admin@zuste.test', 'it-idlock-mod@zuste.test')");
    Database::execute("DELETE FROM `roles` WHERE `slug` = 'it-idlock-moderator'");
};

$ilSignIn = static function (string $jar, string $email) use ($ilPanel, $ilCsrf, $ilPassword): bool {
    file_put_contents($jar, '');
    $login = $ilPanel($jar, 'GET', '/admin/login');
    $post  = $ilPanel($jar, 'POST', '/admin/login', ['_csrf' => $ilCsrf($login['body']), 'email' => $email, 'password' => $ilPassword]);

    return $post['status'] === 302 && str_ends_with((string) $post['location'], '/admin');
};

$ilPanelReady = false;

try {
    $ilRemoveAdmins();

    $ilAdminIds[] = Database::insert('admins', [
        'name' => 'IT IdLock Admin', 'email' => $ilFullEmail,
        'password_hash' => password_hash($ilPassword, PASSWORD_BCRYPT),
        'role_id' => (int) Database::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1'),
        'status' => 'active', 'must_change_password' => 0, 'created_at' => date('Y-m-d H:i:s'),
    ]);

    // Moderator-like: may verify creators and open users / the legacy KYC
    // page, but holds neither kyc.badge nor kyc.view.
    $ilRoleId = Database::insert('roles', [
        'name' => 'IT IdLock moderator', 'slug' => 'it-idlock-moderator', 'level' => 40, 'is_system' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    foreach (['dashboard.view', 'users.view', 'users.kyc', 'creators.view', 'creators.verify'] as $slug) {
        $permissionId = (int) Database::scalar('SELECT `id` FROM `permissions` WHERE `slug` = :s', ['s' => $slug]);

        if ($permissionId > 0) {
            Database::insertIgnore('role_permissions', ['role_id' => $ilRoleId, 'permission_id' => $permissionId]);
        }
    }

    $ilAdminIds[] = Database::insert('admins', [
        'name' => 'IT IdLock Moderator', 'email' => $ilModEmail,
        'password_hash' => password_hash($ilPassword, PASSWORD_BCRYPT),
        'role_id' => $ilRoleId,
        'status' => 'active', 'must_change_password' => 0, 'created_at' => date('Y-m-d H:i:s'),
    ]);

    \Zuste\Services\PermissionService::flush();

    $ilPanelReady = $ilSignIn($ilFullJar, $ilFullEmail) && $ilSignIn($ilModJar, $ilModEmail);
} catch (\Throwable $e) {
    echo "  \033[33mnote\033[0m  could not prepare the panel administrators: {$e->getMessage()}\n";
}

// -----------------------------------------------------------------
Suite::group('Identity lock — a brand name under the blue tick');

if ($ilBrandId <= 0) {
    Suite::skipGroup('Identity lock — a brand name under the blue tick', 'no brand session: ' . Actors::lastError());
} else {
    $ilClearTick($ilBrandId);
    Database::update('users', ['company_name' => 'IT Lock Foods'], ['id' => $ilBrandId]);
    $ilBrandName = (string) Database::scalar('SELECT `name` FROM `users` WHERE `id` = :u', ['u' => $ilBrandId]);

    try {
        $free = Http::put('/me', ['company_name' => 'IT Lock Foods Renamed'], 'idlock-brand');
        Suite::ok('without a tick, a brand can rename itself', $free);
        Suite::equals('GET /me says the name is not locked', Http::get('/me', [], 'idlock-brand')->at('data.user.identity_locked'), false);
        Http::put('/me', ['company_name' => 'IT Lock Foods'], 'idlock-brand');

        // The tick as the panel grants it shows up on users.has_blue_tick.
        Database::update('users', ['has_blue_tick' => 1, 'blue_tick_at' => date('Y-m-d H:i:s')], ['id' => $ilBrandId]);

        Suite::equals('with the tick, GET /me says the name is locked', Http::get('/me', [], 'idlock-brand')->at('data.user.identity_locked'), true);

        $rename = Http::put('/me', ['company_name' => 'Famous FMCG Brand'], 'idlock-brand');
        Suite::status('renaming the company under the tick is 409', $rename, 409);
        Suite::equals('with the code identity_locked', $rename->code(), 'identity_locked');
        Suite::equals('and the agreed message', $rename->message(), $ilLockMessage);
        Suite::equals(
            'and the stored company name did not move',
            Database::scalar('SELECT `company_name` FROM `users` WHERE `id` = :u', ['u' => $ilBrandId]),
            'IT Lock Foods'
        );

        $person = Http::put('/me', ['name' => 'IT Someone Else'], 'idlock-brand');
        Suite::status('renaming the person under the tick is 409 too', $person, 409);
        Suite::equals('also identity_locked', $person->code(), 'identity_locked');

        $cleared = Http::put('/me', ['company_name' => null], 'idlock-brand');
        Suite::status('clearing the company name is a change, and is refused', $cleared, 409);

        $same = Http::put('/me', ['name' => $ilBrandName, 'company_name' => 'IT Lock Foods', 'city' => 'Pune'], 'idlock-brand');
        Suite::ok('re-sending the current names with an unrelated edit goes through', $same);
        Suite::equals('and the unrelated edit is saved', Database::scalar('SELECT `city` FROM `users` WHERE `id` = :u', ['u' => $ilBrandId]), 'Pune');

        Suite::ok(
            'surrounding whitespace is not a rename',
            Http::put('/me', ['company_name' => '  IT Lock Foods  '], 'idlock-brand')
        );

        // A granted KYC badge locks the name even if the users column lags.
        Database::update('users', ['has_blue_tick' => 0, 'blue_tick_at' => null], ['id' => $ilBrandId]);
        $profile = KycService::profileFor($ilBrandId, 'brand');
        Database::update('kyc_profiles', ['badge_status' => 'granted', 'badge_granted_at' => date('Y-m-d H:i:s')], ['id' => (int) $profile['id']]);

        Suite::status(
            'a granted KYC badge locks the name as well',
            Http::put('/me', ['company_name' => 'Famous FMCG Brand'], 'idlock-brand'),
            409
        );

        Database::update('kyc_profiles', ['badge_status' => 'revoked', 'badge_revoked_at' => date('Y-m-d H:i:s')], ['id' => (int) $profile['id']]);

        Suite::ok(
            'once the tick is revoked the name can change again',
            Http::put('/me', ['company_name' => 'IT Lock Foods Again'], 'idlock-brand')
        );
    } finally {
        $ilClearTick($ilBrandId);
        Database::update('users', ['company_name' => 'IT Lock Foods', 'city' => null], ['id' => $ilBrandId]);
    }
}

// -----------------------------------------------------------------
Suite::group('Identity lock — a creator name and handle under the blue tick');

if ($ilCreatorId <= 0) {
    Suite::skipGroup('Identity lock — a creator name and handle under the blue tick', 'no creator session or profile: ' . Actors::lastError());
} else {
    $ilClearTick($ilCreatorUser);
    $ilCreatorSnapshot = $ilSnapshot('creators', $ilCreatorId);

    try {
        Suite::ok(
            'without a tick, a creator can change their display name',
            Http::put('/me/creator', ['display_name' => 'IT Lock Creator ' . $ilStamp], 'idlock-creator')
        );

        $current = Database::selectOne('SELECT `display_name`, `handle` FROM `creators` WHERE `id` = :id', ['id' => $ilCreatorId]) ?? [];

        // A tick that predates the KYC badge model: only creators.is_verified.
        Database::update('creators', ['is_verified' => 1, 'verified_at' => date('Y-m-d H:i:s')], ['id' => $ilCreatorId]);

        Suite::equals('GET /me says the name is locked', Http::get('/me', [], 'idlock-creator')->at('data.user.identity_locked'), true);

        $rename = Http::put('/me/creator', ['display_name' => 'Famous Star'], 'idlock-creator');
        Suite::status('a display-name change under the tick is 409', $rename, 409);
        Suite::equals('with the code identity_locked', $rename->code(), 'identity_locked');
        Suite::equals('and the agreed message', $rename->message(), $ilLockMessage);

        $handle = Http::put('/me/creator', ['handle' => 'itfamous.star' . substr($ilStamp, -3)], 'idlock-creator');
        Suite::status('a handle change under the tick is 409', $handle, 409);
        Suite::equals(
            'and the stored handle and name did not move',
            Database::selectOne('SELECT `display_name`, `handle` FROM `creators` WHERE `id` = :id', ['id' => $ilCreatorId]),
            $current
        );

        Suite::ok(
            'the current name and handle re-sent with another edit go through',
            Http::put('/me/creator', [
                'display_name' => (string) ($current['display_name'] ?? ''),
                'handle'       => (string) ($current['handle'] ?? ''),
                'headline'     => 'IT headline ' . $ilStamp,
            ], 'idlock-creator')
        );

        Suite::status(
            'the account name is locked for a ticked creator too',
            Http::put('/me', ['name' => 'IT Someone Famous'], 'idlock-creator'),
            409
        );
    } finally {
        $ilRestore('creators', $ilCreatorSnapshot);
        $ilClearTick($ilCreatorUser);
    }
}

// -----------------------------------------------------------------
Suite::group('Admin panel — the creator screen\'s verified switch and commission');

if (!$ilPanelReady || $ilCreatorId <= 0) {
    Suite::skipGroup(
        'Admin panel — the creator screen\'s verified switch and commission',
        $ilPanelReady ? 'no creator profile to work on' : 'the throwaway administrators could not sign in'
    );
} else {
    $ilCreatorSnapshot = $ilSnapshot('creators', $ilCreatorId);
    $ilFrom            = '/admin/creators/' . $ilCreatorId;
    $ilFlags           = $ilFrom . '/flags';
    $ilState           = static fn (): array => [
        'tick'       => (int) Database::scalar('SELECT `is_verified` FROM `creators` WHERE `id` = :id', ['id' => $ilCreatorId]),
        'user_tick'  => (int) Database::scalar('SELECT `has_blue_tick` FROM `users` WHERE `id` = :u', ['u' => $ilCreatorUser]),
        'commission' => Database::scalar('SELECT `commission_rate` FROM `creators` WHERE `id` = :id', ['id' => $ilCreatorId]),
    ];

    try {
        // A legacy tick with no KYC profile: the case no control could clear.
        $ilClearTick($ilCreatorUser);
        Database::update('creators', ['is_verified' => 1, 'verified_at' => date('Y-m-d H:i:s'), 'commission_rate' => null], ['id' => $ilCreatorId]);
        Database::update('users', ['has_blue_tick' => 1, 'blue_tick_at' => date('Y-m-d H:i:s')], ['id' => $ilCreatorUser]);

        $modPage = $ilPanel($ilModJar, 'GET', $ilFrom);
        Suite::check(
            'without kyc.badge the switch is shown but not posted',
            $modPage['status'] === 200 && !str_contains($modPage['body'], 'name="is_verified"') && str_contains($modPage['body'], 'Verified badge'),
            'status ' . $modPage['status']
        );

        $r = $ilSubmit($ilModJar, $ilFrom, $ilFlags, ['commission_rate' => '12.5']);
        Suite::equals('a moderator can change the commission of a ticked creator', $r['toast'][0], 'success');
        Suite::check('the commission is saved and the tick kept', $ilState()['commission'] === '12.50' && $ilState()['tick'] === 1, json_encode($ilState()));

        // An older page re-posts the unchanged tick: still not a badge decision.
        $r = $ilSubmit($ilModJar, $ilFrom, $ilFlags, ['is_verified' => '1', 'commission_rate' => '15']);
        Suite::equals('re-posting the unchanged tick does not need kyc.badge', $r['toast'][0], 'success');
        Suite::equals('and the commission edit lands', $ilState()['commission'], '15.00');

        $r = $ilSubmit($ilModJar, $ilFrom, $ilFlags, ['commission_rate' => '']);
        Suite::equals('a blank commission saves', $r['toast'][0], 'success');
        Suite::equals('as no override (NULL), not 0%', $ilState()['commission'], null);

        $r = $ilSubmit($ilModJar, $ilFrom, $ilFlags, ['commission_rate' => '150']);
        Suite::equals('a commission over 100 is refused', $r['toast'][0], 'error');
        Suite::equals('and nothing is written', $ilState()['commission'], null);

        $r = $ilSubmit($ilModJar, $ilFrom, $ilFlags, ['is_verified' => '0', 'commission_rate' => '20']);
        Suite::check(
            'unticking without kyc.badge is refused with a reason',
            $r['toast'][0] === 'error' && str_contains($r['toast'][1], 'blue-tick permission'),
            implode(': ', $r['toast'])
        );
        Suite::check('and nothing at all is saved', $ilState() === ['tick' => 1, 'user_tick' => 1, 'commission' => null], json_encode($ilState()));

        $fullPage = $ilPanel($ilFullJar, 'GET', $ilFrom);
        Suite::check(
            'with kyc.badge the switch posts an explicit 0 when unticked',
            str_contains($fullPage['body'], '<input type="hidden" name="is_verified" value="0">'),
            'no hidden fallback for is_verified on the creator screen'
        );

        $r = $ilSubmit($ilFullJar, $ilFrom, $ilFlags, ['is_verified' => '0', 'commission_rate' => '']);
        Suite::equals('unticking saves', $r['toast'][0], 'success');
        Suite::check(
            'and really removes a legacy tick everywhere it is drawn',
            $ilState()['tick'] === 0 && $ilState()['user_tick'] === 0,
            json_encode($ilState())
        );
        Suite::equals('the app stops locking the name', Http::get('/me', [], 'idlock-creator')->at('data.user.identity_locked'), false);

        $r = $ilSubmit($ilFullJar, $ilFrom, $ilFlags, ['is_verified' => '1', 'commission_rate' => '']);
        Suite::check(
            'the tick cannot be granted without approved KYC',
            $r['toast'][0] === 'error' && str_contains($r['toast'][1], 'KYC'),
            implode(': ', $r['toast'])
        );
        Suite::equals('and stays off', $ilState()['tick'], 0);

        $r = $ilSubmit($ilFullJar, $ilFrom, $ilFlags, ['is_verified' => '0', 'commission_rate' => '']);
        Suite::equals('saving an unchanged form changes nothing', $r['toast'], ['info', 'Nothing changed.']);
    } finally {
        $ilRestore('creators', $ilCreatorSnapshot);
        $ilClearTick($ilCreatorUser);
    }
}

// -----------------------------------------------------------------
Suite::group('Admin panel — KYC records on the user screens');

if (!$ilPanelReady || $ilBrandId <= 0) {
    Suite::skipGroup(
        'Admin panel — KYC records on the user screens',
        $ilPanelReady ? 'no brand account to work on' : 'the throwaway administrators could not sign in'
    );
} else {
    $ilClearTick($ilBrandId);

    try {
        $profile = KycService::profileFor($ilBrandId, 'brand');

        Database::insert('kyc_documents', [
            'user_id' => $ilBrandId, 'profile_id' => (int) $profile['id'], 'document_type' => 'pan', 'slot' => 'pan',
            'holder_name' => 'IT Profile Holder ' . $ilStamp, 'status' => 'pending', 'is_current' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Database::insert('kyc_documents', [
            'user_id' => $ilBrandId, 'profile_id' => null, 'document_type' => 'pan',
            'holder_name' => 'IT Legacy Holder ' . $ilStamp, 'status' => 'pending', 'is_current' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $modUser = $ilPanel($ilModJar, 'GET', '/admin/users/' . $ilBrandId);
        Suite::check(
            'without kyc.view the user screen shows no KYC records',
            $modUser['status'] === 200
                && !str_contains($modUser['body'], 'KYC submissions')
                && !str_contains($modUser['body'], (string) $profile['reference']),
            'status ' . $modUser['status']
        );

        $fullUser = $ilPanel($ilFullJar, 'GET', '/admin/users/' . $ilBrandId);
        Suite::check(
            'with kyc.view it does',
            $fullUser['status'] === 200
                && (str_contains($fullUser['body'], 'KYC submissions') || str_contains($fullUser['body'], (string) $profile['reference'])),
            'status ' . $fullUser['status']
        );

        $legacyPath = '/admin/kyc-legacy?status=all&q=' . rawurlencode(Actors::email('idlock-brand'));

        $modLegacy = $ilPanel($ilModJar, 'GET', $legacyPath);
        Suite::check(
            'without kyc.view the legacy KYC queue is refused',
            $modLegacy['status'] !== 200 && !str_contains($modLegacy['body'], 'IT Legacy Holder ' . $ilStamp),
            'status ' . $modLegacy['status']
        );

        $legacy = $ilPanel($ilFullJar, 'GET', $legacyPath);
        Suite::check(
            'with kyc.view the legacy queue lists a legacy document',
            $legacy['status'] === 200 && str_contains($legacy['body'], 'IT Legacy Holder ' . $ilStamp),
            'status ' . $legacy['status']
        );
        Suite::check(
            'but not a verification-centre document',
            !str_contains($legacy['body'], 'IT Profile Holder ' . $ilStamp),
            'a document that belongs to a KYC profile was listed on the legacy page'
        );
    } finally {
        $ilClearTick($ilBrandId);
    }
}

// -----------------------------------------------------------------
Suite::group('Admin panel — deleting a user erases their KYC documents');

$ilDeleted    = 0;
$ilNoKyc      = 0;
$ilReferenced = 0;
$ilPaymentId  = 0;
$ilDocPath    = '';
$ilProfileId  = 0;

try {
    if (!$ilPanelReady) {
        Suite::skipGroup('Admin panel — deleting a user erases their KYC documents', 'the throwaway administrators could not sign in');
    } else {
        $ilDeleted   = $ilInsertUser('deleted');
        $ilProfileId = (int) KycService::profileFor($ilDeleted, 'creator')['id'];
        $stored      = DocumentVault::storeLocalFile($ilPng, $ilDeleted);
        $ilDocPath   = (string) $stored['path'];

        $docId = Database::insert('kyc_documents', [
            'user_id' => $ilDeleted, 'profile_id' => $ilProfileId, 'document_type' => 'pan', 'slot' => 'pan',
            'holder_name' => 'IT Deleted Holder', 'file_path' => $ilDocPath, 'original_name' => 'pan.png',
            'mime_type' => $stored['mime'], 'file_size' => $stored['size'], 'checksum' => $stored['checksum'],
            'status' => 'pending', 'is_current' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        Suite::check('the fixture document is in the vault', $ilFileExists($ilDocPath));

        $r = $ilSubmit($ilFullJar, '/admin/users/' . $ilDeleted, '/admin/users/' . $ilDeleted . '/delete', []);
        Suite::check(
            'the panel deletes the account and says the documents went',
            $r['toast'][0] === 'success' && str_contains($r['toast'][1], 'identity documents were erased'),
            implode(': ', $r['toast'])
        );

        $user = Database::selectOne('SELECT `status`, `deleted_at` FROM `users` WHERE `id` = :u', ['u' => $ilDeleted]) ?? [];
        Suite::check('the account is closed and binned', ($user['status'] ?? '') === 'deleted' && ($user['deleted_at'] ?? null) !== null, json_encode($user));
        Suite::check('the identity document file is gone from the vault', !$ilFileExists($ilDocPath));

        $doc = Database::selectOne('SELECT `purged_at`, `file_path`, `holder_name` FROM `kyc_documents` WHERE `id` = :id', ['id' => $docId]) ?? [];
        Suite::check(
            'the document row is marked purged with nothing identifying left',
            ($doc['purged_at'] ?? null) !== null && $doc['file_path'] === null && $doc['holder_name'] === null,
            json_encode($doc)
        );
        Suite::check(
            'the verification record itself is kept, with a purge event',
            Database::scalar('SELECT `purged_at` FROM `kyc_profiles` WHERE `id` = :p', ['p' => $ilProfileId]) !== null
                && (int) Database::scalar("SELECT COUNT(*) FROM `kyc_events` WHERE `profile_id` = :p AND `action` = 'purged'", ['p' => $ilProfileId]) > 0
        );
    }

    // -----------------------------------------------------------------
    Suite::group('Recycle bin — accounts with a KYC history are kept');

    if (!$ilPanelReady || $ilDeleted <= 0) {
        Suite::skipGroup('Recycle bin — accounts with a KYC history are kept', 'the previous group did not produce a binned account with KYC');
    } else {
        $longAgo = date('Y-m-d H:i:s', time() - 4000 * 86400);

        Database::update('users', ['deleted_at' => $longAgo], ['id' => $ilDeleted]);
        $ilNoKyc      = $ilInsertUser('nokyc', $longAgo);
        $ilReferenced = $ilInsertUser('paid', $longAgo);
        $ilPaymentId  = Database::insert('payments', [
            'reference' => 'IT-IDL-' . $ilStamp, 'user_id' => $ilReferenced, 'gateway' => 'manual', 'amount' => 1.00,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $events  = static fn (): int => (int) Database::scalar('SELECT COUNT(*) FROM `kyc_events` WHERE `profile_id` = :p', ['p' => $ilProfileId]);
        $exists  = static fn (int $id): bool => Database::scalar('SELECT 1 FROM `users` WHERE `id` = :u', ['u' => $id]) !== null;
        $before  = $events();
        $binPage = '/admin/recycle-bin?type=users';

        $list = $ilPanel($ilFullJar, 'GET', $binPage . '&q=' . rawurlencode('it-idlock-deleted-' . $ilStamp));
        Suite::check(
            'the bin marks the account as kept for its KYC history',
            $list['status'] === 200 && str_contains($list['body'], 'KYC history — kept'),
            'status ' . $list['status']
        );

        $r = $ilSubmit($ilFullJar, $binPage, '/admin/recycle-bin/delete', ['type' => 'users', 'id' => $ilDeleted]);
        Suite::check(
            'a permanent delete of that account is refused, with the reason',
            $r['toast'][0] === 'warning' && str_contains($r['toast'][1], 'KYC verification history'),
            implode(': ', $r['toast'])
        );
        Suite::check('the account row is still there', $exists($ilDeleted));
        Suite::equals('and not one KYC event was lost', $events(), $before);

        $r = $ilSubmit($ilFullJar, $binPage, '/admin/recycle-bin/delete', ['type' => 'users', 'id' => $ilReferenced]);
        Suite::check(
            'an account a payment still points at is refused cleanly (no database error)',
            $r['post']['status'] === 302 && $r['toast'][0] === 'error' && str_contains($r['toast'][1], 'still referenced'),
            'status ' . $r['post']['status'] . ' — ' . implode(': ', $r['toast'])
        );
        Suite::check('and stays in the bin', $exists($ilReferenced));

        // "Purge older than" sweeps every binned type. Only run it when the
        // fixtures above are the only rows that old, so no real data goes.
        $recyclable = (new \ReflectionClassConstant(\Zuste\Controllers\Admin\ProductivityController::class, 'RECYCLABLE'))->getValue();
        $softTables = array_map('strval', Database::column(
            "SELECT `TABLE_NAME` FROM `information_schema`.`COLUMNS`
              WHERE `TABLE_SCHEMA` = DATABASE() AND `COLUMN_NAME` = 'deleted_at'"
        ));
        $others = 0;

        foreach (array_unique(array_column($recyclable, 'table')) as $table) {
            if (!in_array($table, $softTables, true)) {
                continue;
            }

            $others += (int) Database::scalar(
                'SELECT COUNT(*) FROM ' . Database::quoteIdentifier($table) . '
                  WHERE `deleted_at` < DATE_SUB(NOW(), INTERVAL 3650 DAY)'
                . ($table === 'users' ? ' AND `id` NOT IN (' . $ilDeleted . ', ' . $ilNoKyc . ', ' . $ilReferenced . ')' : '')
            );
        }

        if ($others > 0) {
            Suite::skipGroup(
                'Recycle bin — "purge older than"',
                $others . ' real row(s) were binned over 3650 days ago, so the sweep would delete real data; not run'
            );
        } else {
            $r = $ilSubmit($ilFullJar, '/admin/recycle-bin', '/admin/recycle-bin/purge', ['days' => 3650]);

            Suite::check('"purge older than" deletes an ordinary old account', !$exists($ilNoKyc));
            Suite::check('keeps the account with a KYC history', $exists($ilDeleted));
            Suite::check('keeps the account a payment points at', $exists($ilReferenced));
            Suite::equals('without losing a KYC event', $events(), $before);
            Suite::check(
                'and tells the operator why each was kept',
                str_contains($r['toast'][1], 'KYC verification history') && str_contains($r['toast'][1], 'still referenced'),
                implode(': ', $r['toast'])
            );
        }
    }
} finally {
    if ($ilPaymentId > 0) {
        Database::execute('DELETE FROM `payments` WHERE `id` = :id', ['id' => $ilPaymentId]);
    }

    foreach ([$ilDeleted, $ilNoKyc, $ilReferenced] as $uid) {
        if ($uid > 0) {
            $ilClearTick($uid);
            Database::execute('DELETE FROM `users` WHERE `id` = :u', ['u' => $uid]);
        }
    }

    if ($ilDocPath !== '') {
        DocumentVault::purge($ilDocPath);
    }

    Database::execute(
        'DELETE FROM `admin_sessions` WHERE `admin_id` IN (SELECT `id` FROM `admins` WHERE `email` IN (:a, :b))',
        ['a' => $ilFullEmail, 'b' => $ilModEmail]
    );
    $ilRemoveAdmins();
    \Zuste\Services\PermissionService::flush();

    @unlink($ilFullJar);
    @unlink($ilModJar);
    @unlink($ilPng);
}

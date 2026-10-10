<?php

declare(strict_types=1);

/**
 * Privacy, the data export: who can get a link and for how long, what the
 * archive holds, and how it is cleaned up.
 *
 *   - A link is shown once, when it is issued. The overview never repeats
 *     it; a new one takes the same proof and retires the old one, and
 *     signing out everywhere retires it too.
 *   - Opening a link shows a page. Only the page's POST counts a download.
 *   - The email is a security notice with no link in it, sent once.
 *   - The archive leaves out other people's statements and internal notes,
 *     includes sign-in history and deliverables, and says when a section
 *     was cut short.
 *   - Expired and orphaned archives are deleted, the cooldown is checked
 *     before a one-time code is spent, an abandoned export does not hold
 *     the cooldown, and an operator export goes to the account's own email.
 *
 * Accounts are `pe-*` (it-pe-…@zuste.test) and are reused from run to run.
 * Nothing here erases or deletes a user: every row and file the suite makes
 * is removed at the end, and every account it changes is put back. php -S
 * serves one request at a time, so the race between two exports is asserted
 * in-process; curl_multi only shows the server keeps them in order.
 */

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\RateLimiter;
use Zuste\Services\ApiLogService;
use Zuste\Services\AuthService;
use Zuste\Services\CronRunner;
use Zuste\Services\DataExportService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();
Config::flushSettings();

$peSubject  = 'Your Zusty data export is ready';
$peRoot     = rtrim((string) Config::get('storage.base_path'), '/\\') . '/exports';
$pePassword = 'IntegrationTest#2024';

/** Where an export's archive sits on disk. */
$peAbsolute = static fn (string $relative): string => $peRoot . '/' . $relative;

/** The token in a download URL. */
$peToken = static function (?string $url): string {
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    return (string) ($query['token'] ?? '');
};

/** One file out of a ZIP body. */
$peZip = static function (string $body, string $name): ?string {
    $path = tempnam(sys_get_temp_dir(), 'zpe');
    file_put_contents($path, $body);

    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        @unlink($path);

        return null;
    }

    $content = $zip->getFromName('zuste-data-export/' . $name);
    $zip->close();
    @unlink($path);

    return $content === false ? null : $content;
};

/** The landing page's form, posted as a browser posts it. Errors come back as JSON. */
$pePost = static fn (string $token): Res => Http::rawBody(
    'POST',
    '/privacy/exports/download',
    'token=' . rawurlencode($token),
    ['Content-Type' => 'application/x-www-form-urlencoded']
);

/**
 * A request made the way a browser makes it. The harness always sends its
 * own JSON Accept header, so this one goes out directly.
 */
$peBrowser = static function (string $method, string $path, ?string $form = null): Res {
    $headers = [];
    $handle  = curl_init(Http::baseUrl() . $path);

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $form === null
            ? ['Accept: text/html,application/xhtml+xml']
            : ['Accept: text/html,application/xhtml+xml', 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);

    if ($form !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $form);
    }

    $raw    = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($handle);
    curl_close($handle);

    return new Res(
        status: $status,
        body: is_string($raw) ? $raw : '',
        headers: $headers,
        ms: 0.0,
        transportError: $error === '' ? null : $error,
    );
};

/** Every export of one account gone: the archives through the purge job, then the rows. */
$peReset = static function (int $userId): void {
    Database::execute(
        'UPDATE `data_export_requests` SET `expires_at` = NOW() - INTERVAL 1 DAY
          WHERE `user_id` = :id AND `file_path` IS NOT NULL',
        ['id' => $userId]
    );
    CronRunner::run('privacy:purge-exports');
    Database::execute('DELETE FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $userId]);
};

/** A full step-up budget for one account, without wiping anyone else's limits. */
$peBudget = static function (int $userId): void {
    foreach (['privacy:reauth:', 'throttle:privacy-export:user:', 'throttle:privacy-link:user:'] as $prefix) {
        RateLimiter::clear($prefix . $userId);
    }
};

/** The addresses the server may see this client as, for its per-IP download buckets. */
$peIps = array_values(array_unique(array_filter([
    '127.0.0.1',
    '::1',
    gethostbyname((string) parse_url(Http::baseUrl(), PHP_URL_HOST)),
])));

$peClearDownloadBuckets = static function () use ($peIps): void {
    foreach ($peIps as $ip) {
        RateLimiter::clear('throttle:privacy-download:ip:' . $ip);
        RateLimiter::clear('throttle:privacy-download-post:ip:' . $ip);
    }
};

/** A known one-time code for the account's own address, planted where the server looks. */
$pePlant = static function (int $userId, string $destination, string $purpose): array {
    $code = (string) random_int(100000, 999999);

    $id = Database::insert('otp_verifications', [
        'user_id'     => $userId,
        'channel'     => 'email',
        'destination' => strtolower($destination),
        'purpose'     => $purpose,
        'code_hash'   => Crypto::hashToken($code),
        'expires_at'  => date('Y-m-d H:i:s', time() + 300),
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    return ['code' => $code, 'id' => $id];
};

/** Export notices queued to one address since a moment. */
$peNotices = static fn (string $email, string $since): array => Database::select(
    'SELECT `body_html`, `body_text`, `reply_to` FROM `email_queue`
      WHERE `to_email` = :e AND `subject` = :s AND `created_at` >= :since ORDER BY `id`',
    ['e' => $email, 's' => $peSubject, 'since' => $since]
);

/** Settings as they are now, to put back afterwards. */
$peSettings = static function (string $group, array $keys): array {
    $values = [];

    foreach ($keys as $key) {
        $values[$key] = Database::scalar(
            'SELECT `value` FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
            ['g' => $group, 'k' => $key]
        );
    }

    return $values;
};

$peSetSettings = static function (string $group, array $values): void {
    foreach ($values as $key => $value) {
        Database::execute(
            'UPDATE `settings` SET `value` = :v WHERE `group_key` = :g AND `setting_key` = :k',
            ['v' => $value, 'g' => $group, 'k' => $key]
        );
    }

    Config::flushSettings();
};

/** An export made in-process, for a scenario that is not about making one. */
$peMake = static function (int $userId) use ($peReset, $peToken): array {
    $peReset($userId);

    $export = DataExportService::create(AuthService::loadUser($userId), 'password');

    return [
        'id'    => (int) $export['id'],
        'token' => $peToken($export['download_url'] ?? null),
        'file'  => (string) Database::scalar(
            'SELECT `file_path` FROM `data_export_requests` WHERE `id` = :id',
            ['id' => (int) $export['id']]
        ),
    ];
};

// =================================================================
// Accounts. Reused from run to run. Registration allows 5 per IP per
// 15 minutes, so a first run registers them four at a time.
// =================================================================

// A run that stopped half-way may have left an account changed: give it
// back its email address, and lift the deletion flag this suite sets.
try {
    foreach (['pe-phone', 'pe-operator'] as $peName) {
        $peEmail = Actors::PREFIX . $peName . '@zuste.test';

        if (Database::scalar('SELECT 1 FROM `users` WHERE `email` = :e', ['e' => $peEmail]) === null) {
            Database::execute(
                "UPDATE `users` SET `email` = :e
                  WHERE `email` IS NULL AND `name` = :n AND `status` <> 'deleted' ORDER BY `id` LIMIT 1",
                ['e' => $peEmail, 'n' => 'IT ' . ucfirst($peName)]
            );
        }
    }

    Database::execute(
        "UPDATE `users` SET `deleted_at` = NULL WHERE `email` = :e AND `status` <> 'deleted'",
        ['e' => Actors::PREFIX . 'pe-gone@zuste.test']
    );
} catch (\Throwable $peErr) {
    echo "  \033[33mnote\033[0m  could not restore a test account: {$peErr->getMessage()}\n";
}

$peActors = [];

foreach ([['pe-brand', 'pe-other', 'pe-expiry', 'pe-cool'], ['pe-race', 'pe-phone', 'pe-operator', 'pe-gone']] as $peBatch) {
    Suite::clearRateLimits();

    foreach ($peBatch as $peName) {
        $peActor = Actors::create($peName, 'brand');

        if ($peActor !== null && $peActor['id'] > 0) {
            $peActors[$peName] = $peActor + ['name' => $peName];
        }
    }
}

Suite::clearRateLimits();

if (!isset($peActors['pe-brand'])) {
    Suite::skipGroup('Privacy export', 'could not create the pe-brand account: ' . Actors::lastError());

    return;
}

$peBrandId    = (int) $peActors['pe-brand']['id'];
$peBrandEmail = Actors::email('pe-brand');

// =================================================================
Suite::group('Privacy export: a link is shown once and re-issued with proof');
// =================================================================

$peReset($peBrandId);
$peBudget($peBrandId);
Database::execute(
    'DELETE FROM `email_queue` WHERE `to_email` = :e AND `subject` = :s',
    ['e' => $peBrandEmail, 's' => $peSubject]
);

$peSince  = date('Y-m-d H:i:s');
$peExport = Http::post('/me/privacy/exports', ['password' => $pePassword], 'pe-brand');
Suite::ok('an export with the password is prepared', $peExport, 201);

$peExportId = (int) $peExport->at('data.export.id');
$peLink1    = $peToken($peExport->at('data.export.download_url'));
Suite::check('the response carries the link, this once', strlen($peLink1) >= 32, (string) $peExport->at('data.export.download_url'));
Suite::equals('…and says a security notice went by email', $peExport->at('data.export.security_notice.channel'), 'email');
Suite::check(
    '…to a masked address',
    str_contains((string) $peExport->at('data.export.security_notice.destination'), '*')
        && !str_contains((string) $peExport->at('data.export.security_notice.destination'), $peBrandEmail),
    (string) json_encode($peExport->at('data.export.security_notice'))
);

// One notice, and no link in it.
$peQueued = $peNotices($peBrandEmail, $peSince);
Suite::equals('exactly one security notice is queued', count($peQueued), 1);

$peBody = (string) ($peQueued[0]['body_html'] ?? '') . "\n" . (string) ($peQueued[0]['body_text'] ?? '');
Suite::check('…telling the owner where to find the copy in the app', str_contains($peBody, 'Download my data'), mb_substr($peBody, 0, 300));
Suite::check(
    '…with no download link and no token in it',
    $peLink1 !== '' && !str_contains($peBody, 'exports/download') && !str_contains($peBody, $peLink1),
    mb_substr($peBody, 0, 300)
);

$peSupport = trim(Config::settingString('general', 'support_email'));
Suite::equals('…and replies go to support', $peQueued[0]['reply_to'] ?? null, $peSupport !== '' ? $peSupport : null);

// The overview never repeats the link.
$peView = Http::get('/me/privacy', [], 'pe-brand');
Suite::ok('GET /me/privacy answers', $peView, 200);
Suite::equals('the overview does not show the link again', $peView->at('data.export.latest.download_url'), null);
Suite::equals('…but says a copy can be downloaded', $peView->at('data.export.latest.can_download'), true);
Suite::equals(
    'no copy of the link is stored',
    Database::scalar('SELECT `download_token` FROM `data_export_requests` WHERE `id` = :id', ['id' => $peExportId]),
    null
);
Suite::check(
    'the list of what an export holds mentions the offers you made',
    str_contains((string) json_encode($peView->at('data.export.includes')), 'offers you made'),
    (string) json_encode($peView->at('data.export.includes'))
);

// Opening the link shows a page, and opening it costs nothing.
$peLanding = $peBrowser('GET', '/privacy/exports/download?token=' . rawurlencode($peLink1));
Suite::status('opening the link shows a page', $peLanding, 200);
Suite::check(
    '…an HTML page, not the archive',
    str_contains((string) $peLanding->header('content-type'), 'text/html'),
    (string) $peLanding->header('content-type')
);
Suite::check('…with a form that posts', str_contains($peLanding->body, '<form method="post"'), mb_substr($peLanding->body, 0, 300));
Suite::check(
    '…to the download path, with no token in the address',
    str_contains($peLanding->body, 'action="' . DataExportService::downloadPath() . '"'),
    DataExportService::downloadPath()
);
Suite::check('…carrying the token in a hidden field', str_contains($peLanding->body, 'name="token" value="' . $peLink1 . '"'));
Suite::check('…and a Download my data button', str_contains($peLanding->body, 'Download my data'));
Suite::check(
    '…and nothing about whose data it is',
    !str_contains($peLanding->body, $peBrandEmail) && !str_contains($peLanding->body, 'IT Pe-brand')
);
Suite::check(
    "its CSP lets the form post to this site only",
    str_contains((string) $peLanding->header('content-security-policy'), "form-action 'self'"),
    (string) $peLanding->header('content-security-policy')
);
Suite::check('…it is never cached', str_contains((string) $peLanding->header('cache-control'), 'no-store'), (string) $peLanding->header('cache-control'));
Suite::equals('…and its address is never sent on', $peLanding->header('referrer-policy'), 'no-referrer');

$peBrowser('GET', '/privacy/exports/download?token=' . rawurlencode($peLink1));
Suite::status(
    'the app opening the link gets the same page',
    Http::get('/privacy/exports/download', ['token' => $peLink1]),
    200
);
Suite::equals(
    'opening the link three times uses no download',
    (int) Database::scalar('SELECT `download_count` FROM `data_export_requests` WHERE `id` = :id', ['id' => $peExportId]),
    0
);

// A new link takes the same proof, and retires the old one.
$peNoProof = Http::post('/me/privacy/exports/' . $peExportId . '/link', [], 'pe-brand');
Suite::status('a new link without proof is refused (422)', $peNoProof, 422);
Suite::equals('…as reauth_required', $peNoProof->code(), 'reauth_required');

$peReissued = Http::post('/me/privacy/exports/' . $peExportId . '/link', ['password' => $pePassword], 'pe-brand');
Suite::ok('with the password a new link is issued', $peReissued, 200);

$peLink2 = $peToken($peReissued->at('data.export.download_url'));
Suite::check('…with a new token', strlen($peLink2) >= 32 && $peLink2 !== $peLink1, (string) $peReissued->at('data.export.download_url'));
Suite::equals('…and no second security notice', $peReissued->at('data.export.security_notice'), null);
Suite::equals("…keeping the copy's expiry", $peReissued->at('data.export.expires_at'), $peExport->at('data.export.expires_at'));
Suite::equals('…and its download count', $peReissued->at('data.export.download_count'), 0);

Suite::status('the earlier link stops working (404)', $pePost($peLink1), 404);

$peDownload = $pePost($peLink2);
Suite::status('the new link downloads the archive', $peDownload, 200);
Suite::check(
    '…as a ZIP attachment',
    str_contains((string) $peDownload->header('content-type'), 'application/zip')
        && str_contains((string) $peDownload->header('content-disposition'), 'attachment')
        && str_contains((string) $peDownload->header('content-disposition'), 'zuste-data-export-'),
    (string) $peDownload->header('content-type') . ' / ' . (string) $peDownload->header('content-disposition')
);
Suite::check(
    '…holding the README',
    str_contains((string) $peZip($peDownload->body, 'README.txt'), 'What is not included, and why')
);
Suite::equals(
    'the download is counted once',
    (int) Database::scalar('SELECT `download_count` FROM `data_export_requests` WHERE `id` = :id', ['id' => $peExportId]),
    1
);

if (isset($peActors['pe-other'])) {
    $peBudget((int) $peActors['pe-other']['id']);

    Suite::status(
        "another account's copy is not found (404)",
        Http::post('/me/privacy/exports/' . $peExportId . '/link', ['password' => $pePassword], 'pe-other'),
        404
    );
}

// Signing out everywhere retires the link; the copy stays for its owner.
Suite::ok('signing out everywhere works', Http::post('/auth/logout-all', [], 'pe-brand'), 200);

$peLogin  = Http::post('/auth/login', ['identifier' => $peBrandEmail, 'password' => $pePassword]);
$peAccess = $peLogin->at('data.tokens.access_token');

if (is_string($peAccess) && $peAccess !== '') {
    Http::setToken('pe-brand', $peAccess);
}

Suite::ok('…and signing in again works', $peLogin, 200);
Suite::equals(
    'signing out everywhere retires the export link',
    Database::scalar('SELECT `token_hash` FROM `data_export_requests` WHERE `id` = :id', ['id' => $peExportId]),
    null
);
Suite::status('…so it no longer downloads (404)', $pePost($peLink2), 404);

$peFile = (string) Database::scalar('SELECT `file_path` FROM `data_export_requests` WHERE `id` = :id', ['id' => $peExportId]);
Suite::check('…while the copy stays on the server', $peFile !== '' && is_file($peAbsolute($peFile)), $peFile);

$peAfter = Http::get('/me/privacy', [], 'pe-brand');
Suite::check(
    '…and the app can still offer it',
    $peAfter->at('data.export.latest.can_download') === true && $peAfter->at('data.export.latest.download_url') === null,
    $peAfter->describe()
);

$peBudget($peBrandId);
$peReissued = Http::post('/me/privacy/exports/' . $peExportId . '/link', ['password' => $pePassword], 'pe-brand');
Suite::ok('the password issues a working link again', $peReissued, 200);

$peLink3 = $peToken($peReissued->at('data.export.download_url'));
Suite::status('…which downloads', $pePost($peLink3), 200);

// A used-up copy: the page says so and deletes nothing; the download
// deletes the archive and forgets the link.
Database::execute(
    'UPDATE `data_export_requests` SET `max_downloads` = `download_count` WHERE `id` = :id',
    ['id' => $peExportId]
);

$peUsedPage = Http::get('/privacy/exports/download', ['token' => $peLink3]);
Suite::status('a used-up link answers 410 on the page', $peUsedPage, 410);
Suite::equals('…as export_download_limit', $peUsedPage->code(), 'export_download_limit');
clearstatcache();
Suite::check('…and opening it deletes nothing', is_file($peAbsolute($peFile)), $peFile);

$peUsed = $pePost($peLink3);
Suite::status('downloading a used-up copy answers 410', $peUsed, 410);
Suite::equals('…as export_download_limit', $peUsed->code(), 'export_download_limit');
clearstatcache();
Suite::check('…and deletes the archive there and then', !is_file($peAbsolute($peFile)), $peFile);
Suite::status('…so the link is gone for good (404)', $pePost($peLink3), 404);

$peReset($peBrandId);

// =================================================================
Suite::group('Privacy export: copies that run out are deleted');
// =================================================================

if (!isset($peActors['pe-expiry'])) {
    Suite::skipGroup('Privacy export: copies that run out are deleted', 'registration failed: ' . Actors::lastError());
} else {
    $peExpiryId = (int) $peActors['pe-expiry']['id'];

    // The owner's own visit deletes an expired archive, without waiting for cron.
    $peA = $peMake($peExpiryId);
    Suite::check('a copy is prepared for the scenario', $peA['token'] !== '' && is_file($peAbsolute($peA['file'])), (string) json_encode($peA));

    Database::execute('UPDATE `data_export_requests` SET `expires_at` = NOW() - INTERVAL 1 MINUTE WHERE `id` = :id', ['id' => $peA['id']]);

    $peSeen = Http::get('/me/privacy', [], 'pe-expiry');
    clearstatcache();
    Suite::check('opening the privacy screen deletes an expired archive', !is_file($peAbsolute($peA['file'])), $peA['file']);

    $peRowA = Database::selectOne(
        'SELECT `status`, `file_path`, `token_hash`, `download_token` FROM `data_export_requests` WHERE `id` = :id',
        ['id' => $peA['id']]
    ) ?? [];
    Suite::check(
        '…marks it expired and forgets the link',
        ($peRowA['status'] ?? '') === 'expired' && $peRowA['file_path'] === null
            && $peRowA['token_hash'] === null && $peRowA['download_token'] === null,
        (string) json_encode($peRowA)
    );
    Suite::equals('…and the app is told it expired', $peSeen->at('data.export.latest.status'), 'expired');
    Suite::equals('…with nothing left to download', $peSeen->at('data.export.latest.can_download'), false);

    // An expired link answers 410 until the purge, and 404 after it.
    $peB = $peMake($peExpiryId);
    Database::execute('UPDATE `data_export_requests` SET `expires_at` = NOW() - INTERVAL 1 MINUTE WHERE `id` = :id', ['id' => $peB['id']]);

    $peLapsed = Http::get('/privacy/exports/download', ['token' => $peB['token']]);
    Suite::status('an expired link answers 410 until the purge runs', $peLapsed, 410);
    Suite::equals('…as export_expired', $peLapsed->code(), 'export_expired');
    clearstatcache();
    Suite::check('…and opening it deletes nothing', is_file($peAbsolute($peB['file'])), $peB['file']);

    CronRunner::run('privacy:purge-exports');
    clearstatcache();
    Suite::check('the purge job deletes the archive', !is_file($peAbsolute($peB['file'])), $peB['file']);
    Suite::status(
        '…after which the same link answers 404',
        Http::get('/privacy/exports/download', ['token' => $peB['token']]),
        404
    );

    // Downloading an expired copy deletes it there and then.
    $peC = $peMake($peExpiryId);
    Database::execute('UPDATE `data_export_requests` SET `expires_at` = NOW() - INTERVAL 1 MINUTE WHERE `id` = :id', ['id' => $peC['id']]);

    $peLate = $pePost($peC['token']);
    Suite::status('downloading an expired copy answers 410', $peLate, 410);
    Suite::equals('…as export_expired', $peLate->code(), 'export_expired');
    clearstatcache();
    Suite::check('…and deletes the archive there and then', !is_file($peAbsolute($peC['file'])), $peC['file']);
    Suite::status('…so the link is gone for good (404)', $pePost($peC['token']), 404);

    $peReset($peExpiryId);
}

// =================================================================
Suite::group('Privacy export: the cooldown is checked before a code is spent');
// =================================================================

if (!isset($peActors['pe-cool'])) {
    Suite::skipGroup('Privacy export: the cooldown is checked before a code is spent', 'registration failed: ' . Actors::lastError());
} else {
    $peCoolId = (int) $peActors['pe-cool']['id'];

    $peReset($peCoolId);
    $peBudget($peCoolId);

    Suite::ok(
        'a first export is prepared',
        Http::post('/me/privacy/exports', ['password' => $pePassword], 'pe-cool'),
        201
    );

    $pePlanted = $pePlant($peCoolId, Actors::email('pe-cool'), 'data_export');
    $peRefused = Http::post('/me/privacy/exports', ['code' => $pePlanted['code'], 'channel' => 'email'], 'pe-cool');
    Suite::status('a second export inside the cooldown is refused (429)', $peRefused, 429);
    Suite::equals('…as export_cooldown', $peRefused->code(), 'export_cooldown');
    Suite::check('…with Retry-After', (int) $peRefused->header('retry-after') > 0, $peRefused->describe());

    $peOtp = Database::selectOne(
        'SELECT `verified_at`, `attempts` FROM `otp_verifications` WHERE `id` = :id',
        ['id' => $pePlanted['id']]
    ) ?? [];
    Suite::check(
        '…before the code is checked, so it is still unused',
        array_key_exists('verified_at', $peOtp) && $peOtp['verified_at'] === null && (int) $peOtp['attempts'] === 0,
        (string) json_encode($peOtp)
    );

    Suite::equals(
        'the same with a password',
        Http::post('/me/privacy/exports', ['password' => $pePassword], 'pe-cool')->code(),
        'export_cooldown'
    );

    $peReset($peCoolId);
    Database::execute('DELETE FROM `otp_verifications` WHERE `user_id` = :id', ['id' => $peCoolId]);
}

// =================================================================
Suite::group('Privacy export: an export abandoned while being prepared');
// =================================================================

if (!isset($peActors['pe-race'])) {
    Suite::skipGroup('Privacy export: an export abandoned while being prepared', 'registration failed: ' . Actors::lastError());
} else {
    $peRaceId = (int) $peActors['pe-race']['id'];

    $peReset($peRaceId);

    $peStuck = Database::insert('data_export_requests', [
        'user_id'       => $peRaceId,
        'status'        => 'processing',
        'max_downloads' => 5,
        'verified_via'  => 'password',
        'created_at'    => date('Y-m-d H:i:s', time() - 20 * 60),
    ]);

    $peStuckView = Http::get('/me/privacy', [], 'pe-race');
    Suite::equals('an export preparing for 20 minutes does not hold the cooldown', $peStuckView->at('data.export.can_request'), true);
    Suite::equals('…and is shown as failed', $peStuckView->at('data.export.latest.status'), 'failed');

    $peYoung = Database::insert('data_export_requests', [
        'user_id'       => $peRaceId,
        'status'        => 'processing',
        'max_downloads' => 5,
        'verified_via'  => 'password',
        'created_at'    => date('Y-m-d H:i:s', time() - 60),
    ]);

    $peYoungView = Http::get('/me/privacy', [], 'pe-race');
    Suite::equals('one preparing for a minute does', $peYoungView->at('data.export.can_request'), false);
    Suite::equals('…and is shown as preparing', $peYoungView->at('data.export.latest.status'), 'processing');

    CronRunner::run('privacy:purge-exports');
    Suite::equals(
        'the purge job marks the abandoned one failed',
        Database::scalar('SELECT `status` FROM `data_export_requests` WHERE `id` = :id', ['id' => $peStuck]),
        'failed'
    );
    Suite::equals(
        '…and leaves the one still preparing alone',
        Database::scalar('SELECT `status` FROM `data_export_requests` WHERE `id` = :id', ['id' => $peYoung]),
        'processing'
    );

    Database::execute('DELETE FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $peRaceId]);

    // The account lock and the cooldown, in-process.
    $peRaceRow = AuthService::loadUser($peRaceId);
    $peMade    = DataExportService::create($peRaceRow, 'password');
    Suite::equals('an export made in-process is ready', $peMade['status'] ?? null, 'ready');

    try {
        DataExportService::create($peRaceRow, 'password');
        $peSecond = 'none';
    } catch (HttpException $peErr) {
        $peSecond = $peErr->status() . ' ' . $peErr->errorCode();
    }

    Suite::equals('a second one straight after is refused', $peSecond, '429 export_cooldown');
    Suite::equals(
        '…and only one was made',
        (int) Database::scalar('SELECT COUNT(*) FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $peRaceId]),
        1
    );

    // Two requests at once over HTTP: the server keeps them in order.
    $peReset($peRaceId);
    $peBudget($peRaceId);

    $peMulti   = curl_multi_init();
    $peHandles = [];

    for ($peI = 0; $peI < 2; $peI++) {
        $peHandle = curl_init(Http::baseUrl() . '/me/privacy/exports');

        curl_setopt_array($peHandle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => (string) json_encode(['password' => $pePassword]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Platform: test',
                'Authorization: Bearer ' . (string) Http::token('pe-race'),
            ],
        ]);

        curl_multi_add_handle($peMulti, $peHandle);
        $peHandles[] = $peHandle;
    }

    do {
        $peState = curl_multi_exec($peMulti, $peRunning);

        if ($peRunning > 0) {
            curl_multi_select($peMulti, 1.0);
        }
    } while ($peRunning > 0 && $peState === CURLM_OK);

    $peOutcomes = [];

    foreach ($peHandles as $peHandle) {
        $peReply      = json_decode((string) curl_multi_getcontent($peHandle), true);
        $peOutcomes[] = curl_getinfo($peHandle, CURLINFO_RESPONSE_CODE) . ' ' . (is_array($peReply) ? (string) ($peReply['code'] ?? 'ok') : 'unreadable');
        curl_multi_remove_handle($peMulti, $peHandle);
        curl_close($peHandle);
    }

    curl_multi_close($peMulti);
    sort($peOutcomes);

    Suite::equals('two exports sent at once: one is prepared, one refused', $peOutcomes, ['201 ok', '429 export_cooldown']);
    Suite::equals(
        '…and only one is made',
        (int) Database::scalar('SELECT COUNT(*) FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $peRaceId]),
        1
    );

    $peReset($peRaceId);
}

// =================================================================
Suite::group('Privacy export: who is told about an export');
// =================================================================

if (!isset($peActors['pe-phone'])) {
    Suite::skipGroup('Privacy export: who is told about an export', 'registration failed: ' . Actors::lastError());
} else {
    $pePhoneId    = (int) $peActors['pe-phone']['id'];
    $pePhoneEmail = Actors::email('pe-phone');
    $peSms        = $peSettings('sms', ['enabled']);

    try {
        $peSetSettings('sms', ['enabled' => '0']);
        Database::execute('UPDATE `users` SET `email` = NULL WHERE `id` = :id', ['id' => $pePhoneId]);
        $peReset($pePhoneId);
        $peBudget($pePhoneId);

        $peSince     = date('Y-m-d H:i:s');
        $pePhoneOnly = Http::post('/me/privacy/exports', ['password' => $pePassword], 'pe-phone');
        Suite::ok('an account with only a phone number can export', $pePhoneOnly, 201);
        Suite::check(
            '…and is told no notice was sent while SMS is off',
            array_key_exists('security_notice', (array) $pePhoneOnly->at('data.export'))
                && $pePhoneOnly->at('data.export.security_notice') === null,
            $pePhoneOnly->describe()
        );
        Suite::equals('…and no email was queued', count($peNotices($pePhoneEmail, $peSince)), 0);
    } finally {
        Database::execute('UPDATE `users` SET `email` = :e WHERE `id` = :id', ['e' => $pePhoneEmail, 'id' => $pePhoneId]);
        $peSetSettings('sms', $peSms);
        $peReset($pePhoneId);
    }
}

// =================================================================
Suite::group('Privacy export: an export made by our support team');
// =================================================================

if (!isset($peActors['pe-operator'])) {
    Suite::skipGroup('Privacy export: an export made by our support team', 'registration failed: ' . Actors::lastError());
} else {
    $peOpId    = (int) $peActors['pe-operator']['id'];
    $peOpEmail = Actors::email('pe-operator');
    $peAdminId = (int) (Database::scalar('SELECT `id` FROM `admins` WHERE `deleted_at` IS NULL ORDER BY `id` LIMIT 1') ?? 0);
    $peMail    = $peSettings('mail', ['driver', 'host', 'port', 'encryption']);

    try {
        // A mail server that refuses at once: the email falls back to the
        // queue, where the suite can read it.
        $peSetSettings('mail', ['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => '1', 'encryption' => '']);
        $peReset($peOpId);
        Database::execute(
            'DELETE FROM `email_queue` WHERE `to_email` = :e AND `subject` = :s',
            ['e' => $peOpEmail, 's' => $peSubject]
        );

        $peSince = date('Y-m-d H:i:s');
        $peOp    = DataExportService::create(AuthService::loadUser($peOpId), 'operator', [], $peAdminId > 0 ? $peAdminId : null);

        $peOpRow = Database::selectOne(
            'SELECT `verified_via`, `requested_by_admin_id`, `status` FROM `data_export_requests` WHERE `id` = :id',
            ['id' => (int) ($peOp['id'] ?? 0)]
        ) ?? [];
        Suite::equals('it is recorded as made by staff', $peOpRow['verified_via'] ?? null, 'operator');
        Suite::equals('…naming the staff member', (int) ($peOpRow['requested_by_admin_id'] ?? 0), $peAdminId);
        Suite::equals('…and it is ready', $peOpRow['status'] ?? null, 'ready');

        $peOpMail = $peNotices($peOpEmail, $peSince);
        Suite::equals("one email goes to the account's own address", count($peOpMail), 1);
        Suite::check(
            '…with the download link in it',
            !empty($peOp['download_url'])
                && str_contains((string) ($peOpMail[0]['body_html'] ?? '') . (string) ($peOpMail[0]['body_text'] ?? ''), (string) $peOp['download_url']),
            (string) ($peOpMail[0]['body_text'] ?? '')
        );
        Suite::equals('…and the panel is told where it went', $peOp['security_notice']['channel'] ?? null, 'email');

        // No email address: nothing is prepared at all.
        Database::execute('UPDATE `users` SET `email` = NULL WHERE `id` = :id', ['id' => $peOpId]);
        $peRowsBefore = (int) Database::scalar('SELECT COUNT(*) FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $peOpId]);

        try {
            DataExportService::create(AuthService::loadUser($peOpId), 'operator', [], $peAdminId > 0 ? $peAdminId : null);
            $peNoEmail = 'none';
        } catch (HttpException $peErr) {
            $peNoEmail = $peErr->status() . ' ' . $peErr->errorCode();
        }

        Suite::equals('an account with no email address is refused', $peNoEmail, '422 no_email');
        Suite::equals(
            '…before anything is written',
            (int) Database::scalar('SELECT COUNT(*) FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $peOpId]),
            $peRowsBefore
        );
    } finally {
        Database::execute('UPDATE `users` SET `email` = :e WHERE `id` = :id', ['e' => $peOpEmail, 'id' => $peOpId]);
        $peSetSettings('mail', $peMail);
        $peReset($peOpId);
    }
}

// =================================================================
Suite::group("Privacy export: what the archive leaves out, and what it adds");
// =================================================================

if (!isset($peActors['pe-other'])) {
    Suite::skipGroup("Privacy export: what the archive leaves out, and what it adds", 'registration failed: ' . Actors::lastError());
} else {
    $peOtherId = (int) $peActors['pe-other']['id'];
    $peCreator = Database::selectOne(
        'SELECT c.`id`, c.`user_id` FROM `creators` c JOIN `users` u ON u.`id` = c.`user_id`
          WHERE c.`deleted_at` IS NULL AND u.`deleted_at` IS NULL AND u.`id` <> :me ORDER BY c.`id` LIMIT 1',
        ['me' => $peOtherId]
    );

    if ($peCreator === null) {
        Suite::skipGroup("Privacy export: what the archive leaves out, and what it adds", 'no creator to book');
    } else {
        $peBookingId = 0;
        $pePaymentId = 0;
        $peRefundId  = 0;
        $peSignInId  = 0;

        try {
            $peBookingId = Database::insert('bookings', [
                'code'            => 'ITPE' . strtoupper(bin2hex(random_bytes(4))),
                'brand_user_id'   => $peOtherId,
                'creator_id'      => (int) $peCreator['id'],
                'creator_user_id' => (int) $peCreator['user_id'],
                'title'           => 'IT-PE booking',
                'amount'          => 1000,
                'total_amount'    => 1000,
            ]);
            Database::insert('booking_disputes', [
                'booking_id'      => $peBookingId,
                'raised_by'       => 'creator',
                'raised_by_id'    => (int) $peCreator['user_id'],
                'reason'          => 'other',
                'description'     => 'IT-COUNTERPARTY',
                'evidence_urls'   => (string) json_encode(['https://x.test/e.png']),
                'resolution_note' => 'IT-INTERNAL',
            ]);
            Database::insert('booking_disputes', [
                'booking_id'   => $peBookingId,
                'raised_by'    => 'brand',
                'raised_by_id' => $peOtherId,
                'reason'       => 'other',
                'description'  => 'IT-MINE',
            ]);
            $pePaymentId = Database::insert('payments', [
                'reference'  => 'ITPE' . strtoupper(bin2hex(random_bytes(5))),
                'user_id'    => $peOtherId,
                'booking_id' => $peBookingId,
                'gateway'    => 'manual',
                'amount'     => 1000,
            ]);
            $peRefundId = Database::insert('refunds', [
                'reference'  => 'ITPR' . strtoupper(bin2hex(random_bytes(5))),
                'payment_id' => $pePaymentId,
                'booking_id' => $peBookingId,
                'user_id'    => $peOtherId,
                'amount'     => 100,
                'reason'     => 'other',
                'notes'      => 'IT-REFUND-NOTE',
            ]);
            Database::insert('reviews', [
                'booking_id'   => $peBookingId,
                'direction'    => 'creator_to_brand',
                'reviewer_id'  => (int) $peCreator['user_id'],
                'reviewee_id'  => $peOtherId,
                'rating'       => 2,
                'comment'      => 'IT-HIDDEN',
                'is_published' => 0,
            ]);
            Database::insert('booking_deliverables', [
                'booking_id' => $peBookingId,
                'title'      => 'IT-PE-DELIVERABLE',
                'status'     => 'submitted',
            ]);
            $peSignInId = Database::insert('login_history', [
                'actor_type'     => 'user',
                'actor_id'       => $peOtherId,
                'identifier'     => Actors::email('pe-other'),
                'outcome'        => 'bad_credentials',
                'auth_method'    => 'password',
                'ip_address'     => '203.0.113.7',
                'failure_reason' => 'IT-PE-SIGNIN',
                'created_at'     => date('Y-m-d H:i:s'),
            ]);

            $peFiles = DataExportService::collect(AuthService::loadUser($peOtherId), 0);
            $peJson  = (string) json_encode($peFiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            foreach ([
                'IT-COUNTERPARTY'      => "the other party's dispute statement",
                'https://x.test/e.png' => "…and their evidence",
                'IT-INTERNAL'          => "our team's decision note",
                'IT-REFUND-NOTE'       => "our team's refund note",
                'IT-HIDDEN'            => 'a review our moderators hid',
            ] as $peMarker => $peWhat) {
                Suite::check("the export leaves out {$peWhat}", !str_contains($peJson, $peMarker), $peMarker);
            }

            Suite::check("it keeps the user's own dispute statement", str_contains($peJson, 'IT-MINE'));

            $peTheirs = array_values(array_filter(
                (array) ($peFiles['bookings.json']['disputes'] ?? []),
                static fn (array $dispute): bool => ($dispute['raised_by'] ?? '') === 'creator'
            ));
            Suite::check(
                "the other party's dispute is still listed, without their words",
                count($peTheirs) === 1 && $peTheirs[0]['description'] === null && $peTheirs[0]['evidence_urls'] === null
                    && (int) $peTheirs[0]['raised_by_you'] === 0 && !array_key_exists('resolution_note', $peTheirs[0]),
                (string) json_encode($peTheirs)
            );

            Suite::check(
                'security.json holds the sign-in history',
                in_array('IT-PE-SIGNIN', array_column((array) ($peFiles['security.json']['sign_in_history'] ?? []), 'failure_reason'), true),
                (string) json_encode(array_slice((array) ($peFiles['security.json']['sign_in_history'] ?? []), 0, 2))
            );
            Suite::check(
                'bookings.json holds the deliverables',
                in_array('IT-PE-DELIVERABLE', array_column((array) ($peFiles['bookings.json']['deliverables'] ?? []), 'title'), true)
            );
            Suite::check('…and each booking\'s status history', is_array($peFiles['bookings.json']['timeline'] ?? null));
            Suite::check(
                'the README says what is left out, and why',
                str_contains((string) $peFiles['README.txt'], 'last four')
                    && !str_contains((string) $peFiles['README.txt'], 'Only their type and status')
                    && str_contains((string) $peFiles['README.txt'], 'internal notes'),
                (string) $peFiles['README.txt']
            );
            Suite::check(
                'each file says whether a section was cut short',
                isset($peFiles['activity.json']['truncated'], $peFiles['security.json']['truncated'])
            );

            // A section longer than the cap is cut, and says so.
            Database::execute('DELETE FROM `notifications` WHERE `user_id` = :id', ['id' => $peOtherId]);

            $peDigits = 'SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                         UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9';

            Database::execute(
                "INSERT INTO `notifications` (`user_id`, `type`, `category`, `title`, `body`, `created_at`)
                 SELECT :id, 'it_pe_bulk', 'system', CONCAT('IT bulk ', n.v), 'IT bulk notification', NOW()
                   FROM (SELECT a.d + b.d * 10 + c.d * 100 + e.d * 1000 AS v
                           FROM ({$peDigits}) a CROSS JOIN ({$peDigits}) b
                           CROSS JOIN ({$peDigits}) c CROSS JOIN ({$peDigits}) e) n
                  WHERE n.v < 5001",
                ['id' => $peOtherId]
            );

            $peBig = DataExportService::collect(AuthService::loadUser($peOtherId), 0);
            Suite::equals('a section longer than 5,000 rows is cut at 5,000', count((array) ($peBig['activity.json']['notifications'] ?? [])), 5000);
            Suite::equals(
                '…and the file says how many there are',
                json_decode((string) json_encode($peBig['activity.json']['truncated'] ?? null), true)['notifications'] ?? null,
                ['included' => 5000, 'total' => 5001]
            );
            Suite::check('…and so does the README', str_contains((string) $peBig['README.txt'], 'Shortened'));
        } finally {
            Database::execute('DELETE FROM `notifications` WHERE `user_id` = :id', ['id' => $peOtherId]);

            if ($peSignInId > 0) {
                Database::execute('DELETE FROM `login_history` WHERE `id` = :id', ['id' => $peSignInId]);
            }

            if ($peRefundId > 0) {
                Database::execute('DELETE FROM `refunds` WHERE `id` = :id', ['id' => $peRefundId]);
            }

            if ($pePaymentId > 0) {
                Database::execute('DELETE FROM `payments` WHERE `id` = :id', ['id' => $pePaymentId]);
            }

            // Its disputes, review and deliverable go with it (ON DELETE CASCADE).
            if ($peBookingId > 0) {
                Database::execute('DELETE FROM `bookings` WHERE `id` = :id', ['id' => $peBookingId]);
            }
        }
    }
}

// =================================================================
Suite::group('Privacy export: archives no export points at are deleted');
// =================================================================

// A file left by a failed write. The purge keeps anything written in the
// last two hours, because it may still be being written.
$peOrphanOwner = isset($peActors['pe-other']) ? (int) $peActors['pe-other']['id'] : $peBrandId;
$peOrphanDir   = $peRoot . '/' . $peOrphanOwner;

if (!is_dir($peOrphanDir)) {
    @mkdir($peOrphanDir, 0750, true);
}

$peOldOrphan   = $peOrphanDir . '/export-999999999-deadbeef.zip';
$peFreshOrphan = $peOrphanDir . '/export-999999998-cafef00d.zip';
file_put_contents($peOldOrphan, 'IT-PE orphan');
touch($peOldOrphan, time() - 3 * 3600);
file_put_contents($peFreshOrphan, 'IT-PE still being written');

$peSweep = CronRunner::run('privacy:purge-exports');
clearstatcache();
Suite::check('the purge job deletes an old archive no export points at', !is_file($peOldOrphan), (string) json_encode($peSweep));
Suite::check('…but leaves one written in the last two hours', is_file($peFreshOrphan));
Suite::check('…and reports it', (int) ($peSweep['orphans'] ?? 0) >= 1, (string) json_encode($peSweep));

@unlink($peOldOrphan);
@unlink($peFreshOrphan);
@rmdir($peOrphanDir);

if (!isset($peActors['pe-gone'])) {
    Suite::skipGroup('Privacy export: an account deleted while its export is prepared', 'registration failed: ' . Actors::lastError());
} else {
    $peGoneId  = (int) $peActors['pe-gone']['id'];
    $peGoneDir = $peRoot . '/' . $peGoneId;

    // An erased account's exports: rows, archives and stray files, all of it.
    $peReset($peGoneId);
    @mkdir($peGoneDir, 0750, true);
    file_put_contents($peGoneDir . '/export-1-stray.zip', 'IT-PE stray');

    $peLeft = DataExportService::purgeForUser($peGoneId);
    clearstatcache();
    Suite::equals("clearing an erased account's exports leaves no rows", $peLeft, 0);
    Suite::check('…and removes stray archives and the folder', !is_dir($peGoneDir), $peGoneDir);

    // The account disappears between being loaded and the export being made.
    $peGoneRow = AuthService::loadUser($peGoneId);
    $peSince   = date('Y-m-d H:i:s');

    try {
        Database::execute('UPDATE `users` SET `deleted_at` = NOW() WHERE `id` = :id', ['id' => $peGoneId]);

        try {
            DataExportService::create($peGoneRow, 'password');
            $peGoneOutcome = 'none';
        } catch (HttpException $peErr) {
            $peGoneOutcome = (string) $peErr->status();
        }

        clearstatcache();
        Suite::equals('an export for an account deleted meanwhile is refused', $peGoneOutcome, '404');
        Suite::check('…leaving no archive', (glob($peGoneDir . '/export-*') ?: []) === [], $peGoneDir);
        Suite::equals(
            '…no ready export',
            (int) Database::scalar(
                "SELECT COUNT(*) FROM `data_export_requests` WHERE `user_id` = :id AND `status` = 'ready'",
                ['id' => $peGoneId]
            ),
            0
        );
        Suite::equals(
            '…and no record of one being made',
            (int) Database::scalar(
                "SELECT COUNT(*) FROM `activity_logs`
                  WHERE `action` = 'privacy.export_created' AND `subject_type` = 'user' AND `subject_id` = :id
                    AND `actor_name` = :name AND `created_at` >= :since",
                ['id' => $peGoneId, 'name' => (string) $peGoneRow['name'], 'since' => $peSince]
            ),
            0
        );
    } finally {
        Database::execute('UPDATE `users` SET `deleted_at` = NULL WHERE `id` = :id', ['id' => $peGoneId]);
        $peReset($peGoneId);
        @rmdir($peGoneDir);
    }
}

// =================================================================
Suite::group('Privacy export: logs keep no download token or code');
// =================================================================

// The threshold Database enforces in this process (read once, then cached).
$peThreshold = ApiLogService::slowQueryThresholdMs();
$peCached    = (new ReflectionProperty(Database::class, 'slowQueryMs'))->getValue();

if (is_int($peCached)) {
    $peThreshold = $peCached;
}

if ($peThreshold <= 0) {
    Suite::skipGroup('Privacy export: the slow-query log route', 'slow-query recording is switched off here');
} else {
    $peMarker = 'qlprobe_' . bin2hex(random_bytes(4));
    $peUri    = $_SERVER['REQUEST_URI'] ?? null;

    try {
        $_SERVER['REQUEST_URI'] = '/api/v1/privacy/exports/download?token=it-pe-probe';

        Database::select('SELECT SLEEP(' . number_format($peThreshold / 1000 + 0.1, 2, '.', '') . ') AS ' . $peMarker);

        Suite::equals(
            'the slow-query log keeps the path, never the query string',
            Database::scalar('SELECT `route` FROM `query_log` WHERE `sql_text` LIKE :m', ['m' => '%' . $peMarker . '%']),
            '/api/v1/privacy/exports/download'
        );
    } finally {
        if ($peUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $peUri;
        }

        Database::execute('DELETE FROM `query_log` WHERE `sql_text` LIKE :m', ['m' => '%' . $peMarker . '%']);
    }
}

$peRedacted = ApiLogService::redact('{"code":"123456","channel":"email","reason_code":"fees"}');
Suite::check('a one-time code is redacted from the request log', !str_contains($peRedacted, '123456'), $peRedacted);
Suite::check('…while reason_code is kept', str_contains($peRedacted, '"reason_code":"fees"'), $peRedacted);

$peMasked = ApiLogService::redact('{"email":"a.b@x.com","phone":"+919876543210","identifier":"9876543210"}');
Suite::check(
    'an email address, phone number or identifier is masked, not kept',
    !str_contains($peMasked, 'a.b@x.com') && str_contains($peMasked, '@x.com')
        && !str_contains($peMasked, '9876543210') && str_contains($peMasked, '3210'),
    $peMasked
);

// =================================================================
Suite::group('Privacy export: a browser that hits the limit gets a page');
// =================================================================

$peClearDownloadBuckets();

$peUnknown = $peBrowser('GET', '/privacy/exports/download?token=it-pe-not-a-token');
Suite::status('a browser opening an unknown link gets 404', $peUnknown, 404);
Suite::check(
    '…as a readable page',
    str_contains((string) $peUnknown->header('content-type'), 'text/html') && str_contains($peUnknown->body, 'This link no longer works'),
    mb_substr($peUnknown->body, 0, 200)
);
Suite::check(
    '…styled by its own CSS',
    str_contains((string) $peUnknown->header('content-security-policy'), "style-src 'unsafe-inline'"),
    (string) $peUnknown->header('content-security-policy')
);
Suite::check(
    '…with no form allowed on it',
    str_contains((string) $peUnknown->header('content-security-policy'), "form-action 'none'"),
    (string) $peUnknown->header('content-security-policy')
);
Suite::equals('…and its address is never sent on', $peUnknown->header('referrer-policy'), 'no-referrer');

$peClearDownloadBuckets();

for ($peI = 1; $peI <= 30; $peI++) {
    Http::get('/privacy/exports/download', ['token' => 'it-pe-bad-' . $peI]);
}

$peLimited = Http::get('/privacy/exports/download', ['token' => 'it-pe-bad-31']);
Suite::status('the 31st try in an hour from one network is refused (429)', $peLimited, 429);
Suite::equals('…as rate_limited for the app', $peLimited->code(), 'rate_limited');
Suite::check('…with Retry-After', (int) $peLimited->header('retry-after') > 0, $peLimited->describe());

$peWait = $peBrowser('GET', '/privacy/exports/download?token=it-pe-bad-32');
Suite::status('a browser over the limit gets 429 too', $peWait, 429);
Suite::check(
    '…as a page that says to wait',
    str_contains((string) $peWait->header('content-type'), 'text/html') && str_contains($peWait->body, 'Please wait a little'),
    mb_substr($peWait->body, 0, 200)
);
Suite::check('…with Retry-After', (int) $peWait->header('retry-after') > 0, (string) json_encode($peWait->headers));
Suite::check(
    '…styled by its own CSS',
    str_contains((string) $peWait->header('content-security-policy'), "style-src 'unsafe-inline'"),
    (string) $peWait->header('content-security-policy')
);

Suite::status('the download itself has its own budget', $pePost('it-pe-bad-post'), 404);

$peClearDownloadBuckets();

// =================================================================
// Cleanup: every export, code, notice and log line this suite made.
// The accounts stay for the next run; none is erased or deleted.
// =================================================================

$peIds = array_map(static fn (array $actor): int => (int) $actor['id'], array_values($peActors));

foreach ($peIds as $peUid) {
    Database::execute(
        'UPDATE `data_export_requests` SET `expires_at` = NOW() - INTERVAL 1 DAY
          WHERE `user_id` = :id AND `file_path` IS NOT NULL',
        ['id' => $peUid]
    );
}

CronRunner::run('privacy:purge-exports');

foreach ($peIds as $peUid) {
    Database::execute('DELETE FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $peUid]);
    Database::execute('DELETE FROM `otp_verifications` WHERE `user_id` = :id', ['id' => $peUid]);
    Database::execute(
        "DELETE FROM `activity_logs` WHERE `subject_type` = 'user' AND `subject_id` = :id AND `action` LIKE 'privacy.%'",
        ['id' => $peUid]
    );
    $peBudget($peUid);
}

Database::execute("DELETE FROM `email_queue` WHERE `to_email` LIKE 'it-pe-%@zuste.test'");
$peClearDownloadBuckets();

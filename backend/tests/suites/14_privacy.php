<?php

declare(strict_types=1);

/**
 * Privacy & account management: /me/privacy, the data export and its
 * download link, account deletion, and the retired DELETE /auth/account.
 *
 * Everything runs against the real server. One-time codes are planted
 * straight into `otp_verifications` (a known code, hashed as the server
 * hashes it), because email delivery is not something this suite can read.
 * The code path that consumes them is the real one.
 *
 * Deletion uses throwaway accounts registered for this run. The ones that
 * are erased stay as anonymous tombstones, exactly as in production (their
 * deletion record is ON DELETE RESTRICT); their lines are stripped from the
 * erasure ledger. Throwaways that were never erased are hard-deleted, along
 * with every export, code, sign-in row and queued email the run produced.
 *
 * The strict versions of the one-time-code and public-profile checks live in
 * 21_privacy_ops.php; the money rules in depth in 20_privacy_deletion.php.
 */

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\RateLimiter;
use Zuste\Helpers\Money;
use Zuste\Services\CronRunner;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

/** Users this run created and must remove, whatever happens. */
$privacyCleanup = [];

/** A known one-time code, planted where the server will look for it. */
$plantCode = static function (int $userId, string $destination, string $purpose): string {
    $code = (string) random_int(100000, 999999);

    Database::insert('otp_verifications', [
        'user_id'     => $userId,
        'channel'     => 'email',
        'destination' => strtolower($destination),
        'purpose'     => $purpose,
        'code_hash'   => Crypto::hashToken($code),
        'expires_at'  => date('Y-m-d H:i:s', time() + 300),
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    return $code;
};

/** The token in an export's download URL. */
$tokenOf = static function (?string $url): string {
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    return (string) ($query['token'] ?? '');
};

/**
 * The archive itself: the landing page's form, posted as a browser posts it.
 * A GET of the link only shows that page and never counts a download.
 */
$downloadZip = static fn (string $token): Res => Http::rawBody(
    'POST',
    '/privacy/exports/download',
    'token=' . rawurlencode($token),
    ['Content-Type' => 'application/x-www-form-urlencoded']
);

/** Unzip a response body to read one file from it. */
$readZip = static function (Res $res, string $name): ?string {
    $path = tempnam(sys_get_temp_dir(), 'zexp');
    file_put_contents($path, $res->body);

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

/**
 * The registration and sign-in buckets of this client. This suite registers
 * more accounts than /auth/register allows per IP (5 per 15 minutes), so
 * those two buckets are emptied before each one; nothing else is touched.
 */
$privacyApiPath     = rtrim((string) parse_url(Http::baseUrl(), PHP_URL_PATH), '/');
$clearSignupBuckets = static function () use ($privacyApiPath): void {
    foreach (['127.0.0.1', '::1'] as $ip) {
        RateLimiter::clear('throttle:register:ip:' . $ip . ':' . $privacyApiPath . '/auth/register');
        RateLimiter::clear('throttle:login:ip:' . $ip . ':' . $privacyApiPath . '/auth/login');
    }
};

/** A fresh account for one deletion scenario. */
$throwaway = static function (string $label, string $type) use (&$privacyCleanup, $clearSignupBuckets): ?array {
    $clearSignupBuckets();

    $name  = 'privacy-' . $label . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    $actor = Actors::create($name, $type);

    if ($actor !== null && $actor['id'] > 0) {
        $privacyCleanup[] = (int) $actor['id'];
    }

    return $actor === null ? null : $actor + ['name' => $name];
};

/** An erased account: what the erasure leaves behind. */
$isTombstone = static fn (int $userId): bool => (int) Database::scalar(
    "SELECT COUNT(*) FROM `users` WHERE `id` = :id AND `status` = 'deleted' AND `name` = 'Deleted user' AND `email` IS NULL",
    ['id' => $userId]
) === 1;

// =================================================================
Suite::group('Privacy: overview');
// =================================================================

Suite::status('GET /me/privacy needs a session', Http::get('/me/privacy'), 401);

$brand = Actors::create('privacy-brand', 'brand');

if ($brand === null) {
    Suite::skipGroup('Privacy: overview', 'could not create the brand actor: ' . Actors::lastError());

    return;
}

$brandId = Actors::id('privacy-brand');

// Only this account's confirmation gate: every password or code it checks
// counts towards it, right or wrong.
RateLimiter::clear('privacy:reauth:' . $brandId);

$overview = Http::get('/me/privacy', [], 'privacy-brand');
Suite::ok('GET /me/privacy answers', $overview, 200);
Suite::equals('a password account can confirm with its password', $overview->at('data.verification.password'), true);
Suite::check(
    'email is offered as a code channel, masked',
    in_array('email', array_column((array) $overview->at('data.verification.channels'), 'channel'), true)
        && !str_contains(json_encode($overview->at('data.verification.channels')), Actors::email('privacy-brand')),
    json_encode($overview->at('data.verification.channels'))
);
Suite::equals('the export says what it contains', count((array) $overview->at('data.export.includes')) >= 6, true);
Suite::equals('typed confirmation phrase', $overview->at('data.deletion.confirm_phrase'), 'DELETE');
Suite::equals('there are 8 reasons to choose from', count((array) $overview->at('data.deletion.reasons')), 8);

$erasedKeys = array_column((array) $overview->at('data.deletion.erased'), 'key');
Suite::check('a brand is told its campaigns close', in_array('campaigns', $erasedKeys, true), implode(',', $erasedKeys));
Suite::check('a brand is not told about a creator profile', !in_array('creator', $erasedKeys, true), implode(',', $erasedKeys));
Suite::check(
    'retained records are listed with counts',
    count((array) $overview->at('data.deletion.retained')) >= 5 && is_array($overview->at('data.deletion.retained_counts')),
    $overview->describe()
);

// =================================================================
Suite::group('Privacy: verification codes');
// =================================================================

Suite::status(
    'an unknown purpose is refused',
    Http::post('/me/privacy/verification-code', ['purpose' => 'login', 'channel' => 'email'], 'privacy-brand'),
    422
);

// SMS is off on most installs. Either a code goes out, or it is refused with
// its reason; a provider failure is otp_delivery_failed. Never a 500.
$sms = Http::post('/me/privacy/verification-code', ['purpose' => 'data_export', 'channel' => 'sms'], 'privacy-brand');
Suite::check(
    'an SMS code is sent, or refused with its reason',
    $sms->status === 200
        || ($sms->status === 422 && in_array($sms->code(), ['sms_unavailable', 'no_phone'], true))
        || ($sms->status === 503 && $sms->code() === 'otp_delivery_failed'),
    $sms->describe()
);

// Email delivery depends on the SMTP settings of the machine running this.
$email = Http::post('/me/privacy/verification-code', ['purpose' => 'data_export', 'channel' => 'email'], 'privacy-brand');
Suite::check(
    'an email code is sent, or the outage is reported as otp_delivery_failed',
    $email->status === 200 || ($email->status === 503 && $email->code() === 'otp_delivery_failed'),
    $email->describe()
);

if ($email->status === 200) {
    Suite::check(
        'the destination comes back masked',
        str_contains((string) $email->at('data.destination'), '*'),
        (string) $email->at('data.destination')
    );
}

// =================================================================
Suite::group('Privacy: data export');
// =================================================================

// A clean slate: earlier runs left exports that would trip the cooldown.
foreach (Database::select('SELECT `id` FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $brandId]) as $old) {
    Database::execute("UPDATE `data_export_requests` SET `expires_at` = NOW() - INTERVAL 1 DAY WHERE `id` = :id", ['id' => (int) $old['id']]);
}
CronRunner::run('privacy:purge-exports');
Database::execute('DELETE FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $brandId]);

$none = Http::post('/me/privacy/exports', [], 'privacy-brand');
Suite::status('an export without re-authentication is refused', $none, 422);
Suite::equals('…saying why', $none->code(), 'reauth_required');

$wrong = Http::post('/me/privacy/exports', ['password' => 'not-my-password'], 'privacy-brand');
Suite::status('a wrong password is refused', $wrong, 422);
Suite::equals('…as invalid_credentials', $wrong->code(), 'invalid_credentials');
Suite::check('…with the error on the password field', $wrong->at('errors.password') !== null, $wrong->describe());

$export = Http::post('/me/privacy/exports', ['password' => Actors::password('privacy-brand')], 'privacy-brand');
Suite::ok('an export with the password is prepared', $export, 201);
Suite::equals('the export is ready', $export->at('data.export.status'), 'ready');
Suite::equals('it can be downloaded', $export->at('data.export.can_download'), true);

$token = $tokenOf($export->at('data.export.download_url'));
Suite::check('it comes with a download link', strlen($token) >= 32, (string) $export->at('data.export.download_url'));

// Opening the link shows a page with a form; it costs no download.
$landing = Http::get('/privacy/exports/download', ['token' => $token]);
Suite::status('opening the link shows a page', $landing, 200);
Suite::check(
    '…an HTML page with a form that posts, not the archive',
    str_contains((string) $landing->header('content-type'), 'text/html') && str_contains($landing->body, '<form method="post"'),
    (string) $landing->header('content-type')
);

$download = $downloadZip($token);
// status(), not ok(): ok() reads the JSON envelope, and this body is a ZIP.
Suite::status('the form downloads the archive', $download, 200);
Suite::check(
    'as a ZIP attachment',
    str_contains((string) $download->header('content-type'), 'application/zip')
        && str_contains((string) $download->header('content-disposition'), 'attachment'),
    (string) $download->header('content-type')
);

$account = json_decode((string) $readZip($download, 'account.json'), true);
Suite::equals('account.json holds the account email', $account['account']['email'] ?? null, Actors::email('privacy-brand'));
Suite::check(
    'account.json never holds the password hash',
    is_array($account['account'] ?? null) && !array_key_exists('password_hash', $account['account']),
    'keys: ' . implode(',', array_keys($account['account'] ?? []))
);
Suite::check('a README explains the files', str_contains((string) $readZip($download, 'README.txt'), 'What is not included'));
Suite::check('security.json lists sessions', is_array(json_decode((string) $readZip($download, 'security.json'), true)['sessions'] ?? null));
Suite::check('a brand export has no creator profile', $readZip($download, 'creator_profile.json') === null);

$after = Http::get('/me/privacy', [], 'privacy-brand');
Suite::equals('the download was counted once', $after->at('data.export.latest.download_count'), 1);
Suite::equals('…and another export must wait', $after->at('data.export.can_request'), false);

$again = Http::post('/me/privacy/exports', ['password' => Actors::password('privacy-brand')], 'privacy-brand');
Suite::status('a second export inside the cooldown is refused', $again, 429);
Suite::equals('…as export_cooldown', $again->code(), 'export_cooldown');
Suite::check('…with Retry-After', (int) $again->header('retry-after') > 0, $again->describe());

$bad = Http::get('/privacy/exports/download', ['token' => 'not-a-real-token']);
Suite::status('an unknown token is a 404', $bad, 404);

// Asked the way a browser asks. The harness always sends its own JSON
// Accept header, so this one goes out directly.
$pageBody = @file_get_contents(
    Http::baseUrl() . '/privacy/exports/download?token=not-a-real-token',
    false,
    stream_context_create(['http' => [
        'header'        => "Accept: text/html,application/xhtml+xml\r\n",
        'ignore_errors' => true,
    ]])
);
Suite::check(
    'a browser gets a readable page, not JSON',
    is_string($pageBody) && str_contains($pageBody, 'This link no longer works'),
    substr((string) $pageBody, 0, 200)
);

$exportId = (int) $export->at('data.export.id');

// The 410s are read with GET, which only looks: a POST that hits them deletes
// the archive on the spot, and the checks below need it on disk.
Database::execute('UPDATE `data_export_requests` SET `max_downloads` = `download_count` WHERE `id` = :id', ['id' => $exportId]);
$used = Http::get('/privacy/exports/download', ['token' => $token]);
Suite::status('a used-up link is gone (410)', $used, 410);
Suite::equals('…as export_download_limit', $used->code(), 'export_download_limit');

Database::execute('UPDATE `data_export_requests` SET `max_downloads` = 5, `expires_at` = NOW() - INTERVAL 1 MINUTE WHERE `id` = :id', ['id' => $exportId]);
$expired = Http::get('/privacy/exports/download', ['token' => $token]);
Suite::status('an expired link is gone (410)', $expired, 410);
Suite::equals('…as export_expired', $expired->code(), 'export_expired');

$filePath = (string) Database::scalar('SELECT `file_path` FROM `data_export_requests` WHERE `id` = :id', ['id' => $exportId]);
$absolute = rtrim((string) Config::get('storage.base_path'), '/\\') . '/exports/' . $filePath;
Suite::check('the archive sits outside the web root', $filePath !== '' && is_file($absolute) && !str_contains($absolute, 'public'), $absolute);

$purge = CronRunner::run('privacy:purge-exports');
clearstatcache();
Suite::check('the purge job deletes the expired archive', !is_file($absolute), json_encode($purge));

$purged = Database::selectOne('SELECT `status`, `token_hash`, `download_token` FROM `data_export_requests` WHERE `id` = :id', ['id' => $exportId]);
Suite::check(
    '…marks it expired and forgets the token',
    ($purged['status'] ?? '') === 'expired' && $purged['token_hash'] === null && $purged['download_token'] === null,
    json_encode($purged)
);

// A one-time code instead of the password. The route allows 5 export
// attempts an hour, and the checks above used them up: only that bucket
// and this account's confirmation gate are emptied.
Database::execute('DELETE FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $brandId]);
RateLimiter::clear('throttle:privacy-export:user:' . $brandId);
RateLimiter::clear('privacy:reauth:' . $brandId);

$wrongPurpose = $plantCode($brandId, Actors::email('privacy-brand'), 'delete_account');
$crossed = Http::post('/me/privacy/exports', ['code' => $wrongPurpose, 'channel' => 'email'], 'privacy-brand');
Suite::status('a deletion code cannot confirm an export', $crossed, 422);

$code = $plantCode($brandId, Actors::email('privacy-brand'), 'data_export');
$byCode = Http::post('/me/privacy/exports', ['code' => $code, 'channel' => 'email'], 'privacy-brand');
Suite::ok('an export confirmed by email code is prepared', $byCode, 201);
Suite::equals(
    '…and recorded as confirmed by email code',
    Database::scalar('SELECT `verified_via` FROM `data_export_requests` WHERE `id` = :id', ['id' => (int) $byCode->at('data.export.id')]),
    'email_code'
);

// Out of the cooldown first (failed exports do not count), so the reused
// code itself is what gets refused.
Database::execute("UPDATE `data_export_requests` SET `status` = 'failed' WHERE `user_id` = :id", ['id' => $brandId]);
$reused = Http::post('/me/privacy/exports', ['code' => $code, 'channel' => 'email'], 'privacy-brand');
Suite::status('a code works once', $reused, 422);
Suite::check('…refused as a spent code', str_starts_with($reused->code(), 'otp_'), $reused->describe());

// =================================================================
Suite::group('Privacy: deletion asks for proof');
// =================================================================

$blocked = $throwaway('blocked', 'brand');

if ($blocked === null) {
    Suite::skipGroup('Privacy: deletion asks for proof', 'registration failed: ' . Actors::lastError());
} else {
    Suite::status(
        'no typed confirmation is refused',
        Http::post('/me/privacy/delete-account', ['password' => $blocked['password']], $blocked['name']),
        422
    );

    $mismatch = Http::post('/me/privacy/delete-account', [
        'password'     => $blocked['password'],
        'confirmation' => 'delete me',
    ], $blocked['name']);
    Suite::equals('the wrong phrase is refused', $mismatch->code(), 'confirmation_mismatch');

    $noProof = Http::post('/me/privacy/delete-account', ['confirmation' => 'DELETE'], $blocked['name']);
    Suite::equals('no password or code is refused', $noProof->code(), 'reauth_required');

    $badPassword = Http::post('/me/privacy/delete-account', [
        'password'     => 'wrong-password',
        'confirmation' => 'DELETE',
    ], $blocked['name']);
    Suite::equals('a wrong password is refused', $badPassword->code(), 'invalid_credentials');

    Suite::equals(
        'the account is still intact',
        Database::scalar('SELECT `status` FROM `users` WHERE `id` = :id', ['id' => $blocked['id']]),
        'active'
    );
}

// =================================================================
Suite::group('Privacy: money in the wallet');
// =================================================================

if ($blocked === null) {
    Suite::skipGroup('Privacy: money in the wallet', 'registration failed: ' . Actors::lastError());
} else {
    $blockedId = (int) $blocked['id'];
    RateLimiter::clear('privacy:reauth:' . $blockedId);

    // ₹250 is below the minimum withdrawal: it cannot be paid out, so it can
    // be given up instead of blocking the deletion.
    Database::execute(
        "INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `locked_balance`) VALUES (:id, 'INR', 250, 0)
         ON DUPLICATE KEY UPDATE `balance` = 250, `locked_balance` = 0",
        ['id' => $blockedId]
    );

    $view = Http::get('/me/privacy', [], $blocked['name']);
    Suite::equals('a balance below the minimum withdrawal does not block the deletion', $view->at('data.deletion.can_delete'), true);
    Suite::check(
        '…it is offered to be given up',
        Money::equals((float) $view->at('data.deletion.forfeit.amount'), 250.0),
        (string) json_encode($view->at('data.deletion.forfeit'))
    );
    Suite::check(
        '…and is not a wallet_balance blocker',
        !in_array('wallet_balance', array_column((array) $view->at('data.deletion.blockers'), 'code'), true),
        (string) json_encode($view->at('data.deletion.blockers'))
    );

    $refused = Http::post('/me/privacy/delete-account', [
        'password'     => $blocked['password'],
        'confirmation' => 'DELETE',
    ], $blocked['name']);
    Suite::status('deleting without agreeing to give it up is refused (409)', $refused, 409);
    Suite::equals('…as forfeit_required', $refused->code(), 'forfeit_required');
    Suite::check('…naming the wallet', $refused->at('errors.wallet_balance') !== null, $refused->describe());
    Suite::equals(
        '…and nothing was erased',
        Database::scalar('SELECT `email` FROM `users` WHERE `id` = :id', ['id' => $blockedId]),
        $blocked['email']
    );

    $wrongAmount = Http::post('/me/privacy/delete-account', [
        'password'        => $blocked['password'],
        'confirmation'    => 'DELETE',
        'forfeit_balance' => 100,
    ], $blocked['name']);
    Suite::check(
        'agreeing to a different amount is refused too',
        $wrongAmount->status === 409 && $wrongAmount->code() === 'forfeit_required',
        $wrongAmount->describe()
    );

    $agreed = Http::post('/me/privacy/delete-account', [
        'password'        => $blocked['password'],
        'confirmation'    => 'DELETE',
        'forfeit_balance' => 250,
    ], $blocked['name']);
    Suite::ok('agreeing to give up exactly that amount deletes the account', $agreed, 200);

    $record = Database::selectOne('SELECT `id`, `forfeited_amount` FROM `account_deletion_requests` WHERE `user_id` = :id', ['id' => $blockedId]) ?? [];
    $debit  = Database::selectOne(
        'SELECT `direction`, `amount` FROM `wallet_transactions` WHERE `user_id` = :id AND `idempotency_key` = :key',
        ['id' => $blockedId, 'key' => 'deletion-forfeit:' . (int) ($record['id'] ?? 0)]
    ) ?? [];
    Suite::check(
        'the balance leaves through one ledger debit',
        ($debit['direction'] ?? '') === 'debit' && Money::equals((float) ($debit['amount'] ?? 0), 250.0),
        (string) json_encode($debit)
    );
    Suite::check(
        'the deletion record says what was given up',
        Money::equals((float) ($record['forfeited_amount'] ?? 0), 250.0),
        (string) json_encode($record)
    );
}

// A balance too large to give up still blocks, with a way out.
$rich = $throwaway('rich', 'brand');

if ($rich === null) {
    Suite::skipGroup('Privacy: money in the wallet (a large balance)', 'registration failed: ' . Actors::lastError());
} else {
    $richId = (int) $rich['id'];

    Database::execute(
        "INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `locked_balance`) VALUES (:id, 'INR', 5000, 0)
         ON DUPLICATE KEY UPDATE `balance` = 5000, `locked_balance` = 0",
        ['id' => $richId]
    );
    Database::update('users', ['kyc_status' => 'none'], ['id' => $richId]);

    try {
        $richView = Http::get('/me/privacy', [], $rich['name']);
        $blocker  = null;

        foreach ((array) $richView->at('data.deletion.blockers') as $candidate) {
            if (($candidate['code'] ?? '') === 'wallet_balance') {
                $blocker = $candidate;
            }
        }

        Suite::equals('a large balance blocks the deletion', $richView->at('data.deletion.can_delete'), false);
        Suite::check(
            '…as wallet_balance, pointing to KYC or support',
            $blocker !== null && in_array($blocker['action'] ?? '', ['kyc', 'support'], true),
            (string) json_encode($richView->at('data.deletion.blockers'))
        );

        $richDelete = Http::post('/me/privacy/delete-account', [
            'password'     => $rich['password'],
            'confirmation' => 'DELETE',
        ], $rich['name']);
        Suite::status('the deletion is refused (409)', $richDelete, 409);
        Suite::equals('…as deletion_blocked', $richDelete->code(), 'deletion_blocked');
        Suite::check('…naming the wallet', $richDelete->at('errors.wallet_balance') !== null, $richDelete->describe());
        Suite::equals(
            'and nothing was erased',
            Database::scalar('SELECT `email` FROM `users` WHERE `id` = :id', ['id' => $richId]),
            $rich['email']
        );
    } finally {
        Database::execute('UPDATE `wallets` SET `balance` = 0, `locked_balance` = 0 WHERE `user_id` = :id', ['id' => $richId]);
    }
}

// =================================================================
Suite::group('Privacy: a creator deletes their account');
// =================================================================

$creator = $throwaway('creator', 'creator');

if ($creator === null) {
    Suite::skipGroup('Privacy: a creator deletes their account', 'registration failed: ' . Actors::lastError());
} else {
    $uid       = (int) $creator['id'];
    $creatorId = (int) Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :id', ['id' => $uid]);
    $oldHandle = (string) Database::scalar('SELECT `handle` FROM `creators` WHERE `id` = :id', ['id' => $creatorId]);

    Suite::check('registration made a creator profile', $creatorId > 0, 'creator id ' . $creatorId);

    // Live and public, so the 404s after the deletion prove something.
    Database::update('creators', ['status' => 'approved', 'is_public' => 1], ['id' => $creatorId]);
    Suite::status('before deletion the public profile is visible', Http::get('/creators/' . rawurlencode($oldHandle)), 200);

    // Personal data spread across the tables the erasure must reach.
    Database::update('users', ['bio' => 'My private bio', 'city' => 'Pune'], ['id' => $uid]);
    Database::update('creators', ['about' => 'All about me', 'city' => 'Pune'], ['id' => $creatorId]);
    Database::insert('creator_faqs', ['creator_id' => $creatorId, 'question' => 'Rates?', 'answer' => 'Ask me']);
    Database::insert('notifications', [
        'user_id' => $uid, 'type' => 'test', 'category' => 'system', 'title' => 'Hello', 'body' => 'Private note',
        'action_type' => 'none', 'created_at' => date('Y-m-d H:i:s'),
    ]);
    Database::insert('bank_accounts', [
        'user_id' => $uid, 'method' => 'upi', 'account_holder_name' => 'IT Person', 'upi_id' => 'itperson@upi',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    Database::insert('search_histories', ['user_id' => $uid, 'term' => 'private search', 'created_at' => date('Y-m-d H:i:s')]);

    // A conversation with someone else, which must survive for them.
    $other = $brandId;
    $conversationId = Database::insert('conversations', ['uuid' => Crypto::uuid4(), 'type' => 'direct']);
    Database::insert('conversation_participants', ['conversation_id' => $conversationId, 'user_id' => $uid, 'role' => 'creator']);
    Database::insert('conversation_participants', ['conversation_id' => $conversationId, 'user_id' => $other, 'role' => 'brand']);
    Database::insert('messages', [
        'uuid' => Crypto::uuid4(), 'conversation_id' => $conversationId, 'sender_id' => $uid,
        'sender_type' => 'user', 'type' => 'text', 'body' => 'Hi, here is my quote',
    ]);

    // An export made before leaving, whose link must die with the account.
    $before = Http::post('/me/privacy/exports', ['password' => $creator['password']], $creator['name']);
    Suite::ok('the creator exports their data first', $before, 201);
    $beforeToken = $tokenOf($before->at('data.export.download_url'));
    $beforeFile  = (string) Database::scalar(
        'SELECT `file_path` FROM `data_export_requests` WHERE `id` = :id',
        ['id' => (int) $before->at('data.export.id')]
    );

    $profile = json_decode((string) $readZip($downloadZip($beforeToken), 'creator_profile.json'), true);
    Suite::check(
        "the creator's export holds their profile and FAQs",
        ($profile['profile']['about'] ?? '') === 'All about me' && count($profile['faqs'] ?? []) === 1,
        json_encode(array_keys($profile ?? []))
    );

    $deleted = Http::post('/me/privacy/delete-account', [
        'password'     => $creator['password'],
        'confirmation' => 'DELETE',
        'reason_code'  => 'privacy',
        'reason'       => 'Testing the erasure',
    ], $creator['name']);

    Suite::ok('the creator deletes their account', $deleted, 200);
    Suite::equals('…and is told so', $deleted->at('data.deleted'), true);

    $row = Database::selectOne('SELECT * FROM `users` WHERE `id` = :id', ['id' => $uid]) ?? [];
    Suite::equals('the name is gone', $row['name'] ?? null, 'Deleted user');
    Suite::check(
        'email, phone, password, bio and city are gone',
        $row['email'] === null && $row['phone'] === null && $row['password_hash'] === null && $row['bio'] === null && $row['city'] === null,
        json_encode(array_intersect_key($row, array_flip(['email', 'phone', 'password_hash', 'bio', 'city'])))
    );
    Suite::check(
        'the row is a deleted tombstone',
        $row['status'] === 'deleted' && $row['deleted_at'] !== null && str_starts_with((string) $row['referral_code'], 'DEL'),
        json_encode(array_intersect_key($row, array_flip(['status', 'deleted_at', 'referral_code'])))
    );

    $creatorRow = Database::selectOne('SELECT `handle`, `display_name`, `about`, `city`, `is_public`, `deleted_at` FROM `creators` WHERE `id` = :id', ['id' => $creatorId]) ?? [];
    Suite::check(
        'the creator profile is anonymised and hidden',
        $creatorRow['handle'] === 'deleted-' . $creatorId && $creatorRow['display_name'] === 'Deleted creator'
            && $creatorRow['about'] === null && (int) $creatorRow['is_public'] === 0 && $creatorRow['deleted_at'] !== null,
        json_encode($creatorRow)
    );
    Suite::status('the old public profile is gone by handle', Http::get('/creators/' . rawurlencode($oldHandle)), 404);
    Suite::status('…and by id', Http::get('/creators/' . $creatorId), 404);

    foreach ([
        'creator_faqs'      => ['creator_id', $creatorId],
        'notifications'     => ['user_id', $uid],
        'bank_accounts'     => ['user_id', $uid],
        'search_histories'  => ['user_id', $uid],
        'refresh_tokens'    => ['user_id', $uid],
        'user_devices'      => ['user_id', $uid],
        'data_export_requests' => ['user_id', $uid],
    ] as $table => [$column, $value]) {
        Suite::equals(
            "{$table} rows are erased",
            (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :v", ['v' => $value]),
            0
        );
    }

    clearstatcache();
    Suite::check(
        'the export archive is deleted from disk',
        $beforeFile !== '' && !is_file(rtrim((string) Config::get('storage.base_path'), '/\\') . '/exports/' . $beforeFile),
        $beforeFile
    );
    Suite::status('…and its link no longer works', Http::get('/privacy/exports/download', ['token' => $beforeToken]), 404);

    Suite::equals(
        'the message stays in the other person\'s conversation',
        (int) Database::scalar('SELECT COUNT(*) FROM `messages` WHERE `conversation_id` = :c AND `sender_id` = :u', ['c' => $conversationId, 'u' => $uid]),
        1
    );

    $record = Database::selectOne('SELECT * FROM `account_deletion_requests` WHERE `user_id` = :id', ['id' => $uid]) ?? [];
    Suite::check(
        'a deletion record keeps the reason and how it was confirmed',
        ($record['reason_code'] ?? '') === 'privacy' && ($record['reason_text'] ?? '') === 'Testing the erasure'
            && ($record['verified_via'] ?? '') === 'password' && ($record['account_type'] ?? '') === 'creator',
        json_encode($record)
    );
    $summary = json_decode((string) ($record['summary'] ?? ''), true);
    Suite::check('…with what was erased and kept', isset($summary['erased'], $summary['retained']), (string) ($record['summary'] ?? ''));

    Suite::check(
        'the audit log records the deletion without a name',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM `activity_logs` WHERE `action` = 'account.deleted' AND `subject_id` = :id AND `actor_name` = 'Deleted user'",
            ['id' => $uid]
        ) === 1
    );

    // Signed out, everywhere, for good.
    $dead = Http::get('/auth/me', [], $creator['name']);
    Suite::status('the old access token is dead', $dead, 401);
    Suite::equals('…and says the account was deleted', $dead->code(), 'account_deleted');

    $login = Http::post('/auth/login', ['identifier' => $creator['email'], 'password' => $creator['password']]);
    Suite::check('the old credentials no longer sign in', in_array($login->status, [401, 422], true), $login->describe());

    // The email address is free for a new account.
    $clearSignupBuckets();
    $again = Http::post('/auth/register', [
        'name'         => 'IT Returning',
        'email'        => $creator['email'],
        'password'     => $creator['password'],
        'account_type' => 'creator',
        'accept_terms' => true,
    ]);
    Suite::ok('the email can sign up again', $again, 201);

    if ((int) $again->at('data.user.id') > 0) {
        $privacyCleanup[] = (int) $again->at('data.user.id');
    }

    Database::execute('DELETE FROM `messages` WHERE `conversation_id` = :c', ['c' => $conversationId]);
    Database::execute('DELETE FROM `conversations` WHERE `id` = :c', ['c' => $conversationId]);
}

// =================================================================
Suite::group('Privacy: a brand deletes by email code, closing its campaigns');
// =================================================================

$leaving   = $throwaway('brand', 'brand');
$applicant = $throwaway('applicant', 'creator');
$invitee   = $throwaway('invitee', 'creator');

if ($leaving === null || $applicant === null || $invitee === null) {
    Suite::skipGroup('Privacy: a brand deletes by email code, closing its campaigns', 'registration failed: ' . Actors::lastError());
} else {
    $bid           = (int) $leaving['id'];
    $campaignTitle = 'IT privacy campaign ' . bin2hex(random_bytes(3));

    $campaignId = Database::insert('campaigns', [
        'code'          => 'ITP' . strtoupper(bin2hex(random_bytes(3))),
        'brand_user_id' => $bid,
        'title'         => $campaignTitle,
        'description'   => 'A campaign that should close.',
        'platform'      => 'instagram',
        'deliverable_type' => 'reel',
        'status'        => 'open',
        'is_public'     => 1,
    ]);

    // Throwaway creators only: the erasure tells them (in the app and by
    // email) that the campaign closed.
    $applications = [];

    foreach (['applied' => $applicant, 'invited' => $invitee] as $status => $person) {
        $applications[$status] = [
            'user' => (int) $person['id'],
            'id'   => Database::insert('campaign_applications', [
                'campaign_id'     => $campaignId,
                'creator_id'      => (int) Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :id', ['id' => (int) $person['id']]),
                'creator_user_id' => (int) $person['id'],
                'status'          => $status,
                'pitch'           => $status === 'applied' ? 'Pick me' : null,
            ]),
        ];
    }

    $code = $plantCode($bid, $leaving['email'], 'delete_account');

    $gone = Http::post('/me/privacy/delete-account', [
        'code'         => $code,
        'channel'      => 'email',
        'confirmation' => 'delete',
        'reason_code'  => 'not-a-real-reason',
    ], $leaving['name']);

    Suite::ok('a brand deletes with an email code (case-insensitive phrase)', $gone, 200);
    Suite::equals(
        '…recorded as confirmed by email code, with an unknown reason filed as "other"',
        Database::selectOne('SELECT `verified_via`, `reason_code` FROM `account_deletion_requests` WHERE `user_id` = :id', ['id' => $bid]),
        ['verified_via' => 'email_code', 'reason_code' => 'other']
    );

    $campaign = Database::selectOne('SELECT `status`, `is_public`, `deleted_at` FROM `campaigns` WHERE `id` = :id', ['id' => $campaignId]) ?? [];
    Suite::check(
        'the open campaign is cancelled and hidden',
        $campaign['status'] === 'cancelled' && (int) $campaign['is_public'] === 0 && $campaign['deleted_at'] !== null,
        json_encode($campaign)
    );

    foreach ($applications as $status => $application) {
        $row = Database::selectOne('SELECT `status`, `decline_reason` FROM `campaign_applications` WHERE `id` = :id', ['id' => $application['id']]) ?? [];
        Suite::check(
            "its {$status} creator's application is closed",
            $row['status'] === 'declined' && str_contains((string) $row['decline_reason'], 'closed'),
            json_encode($row)
        );
        Suite::equals(
            "…and the {$status} creator is told the campaign closed",
            (int) Database::scalar(
                "SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :u AND `type` = 'campaign.cancelled' AND `body` LIKE :t",
                ['u' => $application['user'], 't' => '%' . $campaignTitle . '%']
            ),
            1
        );

        Database::execute('DELETE FROM `campaign_applications` WHERE `id` = :id', ['id' => $application['id']]);
        Database::execute("DELETE FROM `notifications` WHERE `user_id` = :u AND `type` = 'campaign.cancelled'", ['u' => $application['user']]);
    }

    Database::execute('DELETE FROM `campaigns` WHERE `id` = :id', ['id' => $campaignId]);
}

// =================================================================
Suite::group('Privacy: the retired DELETE /auth/account');
// =================================================================

$legacy = $throwaway('legacy', 'brand');

if ($legacy === null) {
    Suite::skipGroup('Privacy: the retired DELETE /auth/account', 'registration failed: ' . Actors::lastError());
} else {
    $retired = Http::delete('/auth/account', ['password' => $legacy['password'], 'reason' => 'legacy'], $legacy['name']);
    Suite::status('a brand or creator account is told to use the app (410)', $retired, 410);
    Suite::equals('…as endpoint_retired', $retired->code(), 'endpoint_retired');
    Suite::equals(
        '…and nothing was erased',
        Database::scalar('SELECT `email` FROM `users` WHERE `id` = :id', ['id' => (int) $legacy['id']]),
        $legacy['email']
    );
}

// =================================================================
// Cleanup
// =================================================================

$privacyErased = [];

foreach (array_unique($privacyCleanup) as $userId) {
    $userId = (int) $userId;

    Database::execute('DELETE FROM `otp_verifications` WHERE `user_id` = :id', ['id' => $userId]);
    Database::execute("DELETE FROM `login_history` WHERE `actor_type` = 'user' AND `actor_id` = :id", ['id' => $userId]);
    RateLimiter::clear('privacy:reauth:' . $userId);

    // An erased account stays as its anonymous tombstone, as in production:
    // its deletion record (and any given-up balance's ledger debit) must not
    // be cascaded away.
    if ($isTombstone($userId)) {
        $privacyErased[] = $userId;

        continue;
    }

    Database::execute("DELETE FROM `activity_logs` WHERE `actor_type` = 'user' AND `actor_id` = :id", ['id' => $userId]);
    Database::execute("DELETE FROM `activity_logs` WHERE `subject_type` = 'user' AND `subject_id` = :id", ['id' => $userId]);
    Database::execute('DELETE FROM `wallets` WHERE `user_id` = :id', ['id' => $userId]);

    try {
        Database::execute('DELETE FROM `users` WHERE `id` = :id', ['id' => $userId]);
    } catch (\Throwable $e) {
        Suite::check("cleanup removed user {$userId}", false, $e->getMessage());
    }
}

foreach (Database::select('SELECT `id` FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $brandId]) as $old) {
    Database::execute("UPDATE `data_export_requests` SET `expires_at` = NOW() - INTERVAL 1 DAY WHERE `id` = :id", ['id' => (int) $old['id']]);
}
CronRunner::run('privacy:purge-exports');
Database::execute('DELETE FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $brandId]);
Database::execute('DELETE FROM `otp_verifications` WHERE `user_id` = :id', ['id' => $brandId]);
Database::execute(
    "DELETE FROM `activity_logs` WHERE `subject_type` = 'user' AND `subject_id` = :id AND `action` LIKE 'privacy.%'",
    ['id' => $brandId]
);
Database::execute("DELETE FROM `login_history` WHERE `identifier` LIKE 'it-privacy-%@zuste.test'");
Database::execute("DELETE FROM `email_queue` WHERE `to_email` LIKE 'it-privacy-%@zuste.test'");
RateLimiter::clear('privacy:reauth:' . $brandId);
RateLimiter::clear('throttle:privacy-export:user:' . $brandId);

// The erasure ledger must not carry this run's accounts into a restore.
$privacyLedger = rtrim((string) Config::get('storage.base_path'), '/\\') . '/privacy/erasure-ledger.jsonl';

if ($privacyErased !== [] && is_file($privacyLedger)) {
    $lines = file($privacyLedger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $kept  = array_values(array_filter(
        $lines,
        static fn (string $line): bool => !in_array((int) (json_decode($line, true)['user_id'] ?? 0), $privacyErased, true)
    ));

    if (count($kept) !== count($lines)) {
        file_put_contents($privacyLedger, $kept === [] ? '' : implode("\n", $kept) . "\n", LOCK_EX);
    }
}

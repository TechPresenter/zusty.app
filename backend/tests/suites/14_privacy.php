<?php

declare(strict_types=1);

/**
 * Privacy & account management: /me/privacy, the data export and its
 * download link, account deletion, and the legacy DELETE /auth/account.
 *
 * Everything runs against the real server. One-time codes are planted
 * straight into `otp_verifications` (a known code, hashed as the server
 * hashes it), because email delivery is not something this suite can read.
 * The code path that consumes them is the real one.
 *
 * Deletion uses throwaway accounts registered for this run. They end as
 * anonymous tombstones, which are hard-deleted at the end along with every
 * export, code and queued email the run produced.
 */

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
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
 * A fresh account for one deletion scenario.
 *
 * Registration is throttled per IP (5 per 15 minutes), and this suite
 * registers more than that on its own, so the buckets are cleared first.
 */
$throwaway = static function (string $label, string $type) use (&$privacyCleanup): ?array {
    Suite::clearRateLimits();

    $name  = 'privacy-' . $label . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    $actor = Actors::create($name, $type);

    if ($actor !== null && $actor['id'] > 0) {
        $privacyCleanup[] = (int) $actor['id'];
    }

    return $actor === null ? null : $actor + ['name' => $name];
};

// =================================================================
Suite::group('Privacy: overview');
// =================================================================

Suite::status('GET /me/privacy needs a session', Http::get('/me/privacy'), 401);

$brand = Actors::create('privacy-brand', 'brand');

if ($brand === null) {
    Suite::skipGroup('Privacy: overview', 'could not create the brand actor: ' . Actors::lastError());

    return;
}

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

// SMS is off on most installs. Either it is refused with its reason, or a
// code goes out. It must never be a 500.
$sms = Http::post('/me/privacy/verification-code', ['purpose' => 'data_export', 'channel' => 'sms'], 'privacy-brand');
Suite::check(
    'an SMS code is sent, or refused with a reason',
    in_array($sms->status, [200, 422, 503], true),
    $sms->describe()
);

// Email delivery depends on the SMTP settings of the machine running this.
$email = Http::post('/me/privacy/verification-code', ['purpose' => 'data_export', 'channel' => 'email'], 'privacy-brand');
Suite::check(
    'an email code is sent, or the outage is reported as 503',
    in_array($email->status, [200, 503], true),
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

$brandId = Actors::id('privacy-brand');

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

$download = Http::get('/privacy/exports/download', ['token' => $token]);
// status(), not ok(): ok() reads the JSON envelope, and this body is a ZIP.
Suite::status('the link downloads the archive', $download, 200);
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
Suite::equals('the download was counted', $after->at('data.export.latest.download_count'), 1);
Suite::equals('…and another export must wait', $after->at('data.export.can_request'), false);

$again = Http::post('/me/privacy/exports', ['password' => Actors::password('privacy-brand')], 'privacy-brand');
Suite::status('a second export inside the cooldown is refused', $again, 429);
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
// attempts an hour, and the checks above used them up.
Database::execute('DELETE FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $brandId]);
Suite::clearRateLimits();

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

$reused = Http::post('/me/privacy/exports', ['code' => $code, 'channel' => 'email'], 'privacy-brand');
Suite::check('a code works once', in_array($reused->status, [422, 429], true), $reused->describe());

// =================================================================
Suite::group('Privacy: deletion is refused while money is in play');
// =================================================================

$blocked = $throwaway('blocked', 'brand');

if ($blocked === null) {
    Suite::skipGroup('Privacy: deletion is refused while money is in play', 'registration failed: ' . Actors::lastError());
} else {
    Database::execute(
        "INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `locked_balance`) VALUES (:id, 'INR', 250, 0)
         ON DUPLICATE KEY UPDATE `balance` = 250",
        ['id' => $blocked['id']]
    );

    $view = Http::get('/me/privacy', [], $blocked['name']);
    Suite::equals('the overview says it cannot be deleted yet', $view->at('data.deletion.can_delete'), false);
    Suite::check(
        '…because of the wallet balance',
        in_array('wallet_balance', array_column((array) $view->at('data.deletion.blockers'), 'code'), true),
        json_encode($view->at('data.deletion.blockers'))
    );

    $refused = Http::post('/me/privacy/delete-account', [
        'password'     => $blocked['password'],
        'confirmation' => 'DELETE',
    ], $blocked['name']);
    Suite::status('the deletion is refused (409)', $refused, 409);
    Suite::equals('…as deletion_blocked', $refused->code(), 'deletion_blocked');
    Suite::check('…naming the wallet', $refused->at('errors.wallet_balance') !== null, $refused->describe());
    Suite::equals(
        'and nothing was erased',
        Database::scalar('SELECT `email` FROM `users` WHERE `id` = :id', ['id' => $blocked['id']]),
        $blocked['email']
    );

    Database::execute('UPDATE `wallets` SET `balance` = 0 WHERE `user_id` = :id', ['id' => $blocked['id']]);
}

// =================================================================
Suite::group('Privacy: deletion asks for proof');
// =================================================================

if ($blocked !== null) {
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
    $other = Actors::id('privacy-brand');
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

    $profile = json_decode(
        (string) $readZip(Http::get('/privacy/exports/download', ['token' => $beforeToken]), 'creator_profile.json'),
        true
    );
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
    Suite::status('the old public profile is gone', Http::get('/creators/' . rawurlencode($oldHandle)), 404);

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
    Suite::status('the old access token is dead', Http::get('/auth/me', [], $creator['name']), 401);

    $login = Http::post('/auth/login', ['identifier' => $creator['email'], 'password' => $creator['password']]);
    Suite::check('the old credentials no longer sign in', in_array($login->status, [401, 422], true), $login->describe());

    // The email address is free for a new account.
    Suite::clearRateLimits();
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

$leaving = $throwaway('brand', 'brand');

if ($leaving === null) {
    Suite::skipGroup('Privacy: a brand deletes by email code, closing its campaigns', 'registration failed: ' . Actors::lastError());
} else {
    $bid = (int) $leaving['id'];

    $campaignId = Database::insert('campaigns', [
        'code'          => 'ITP' . strtoupper(bin2hex(random_bytes(3))),
        'brand_user_id' => $bid,
        'title'         => 'IT privacy campaign',
        'description'   => 'A campaign that should close.',
        'platform'      => 'instagram',
        'deliverable_type' => 'reel',
        'status'        => 'open',
        'is_public'     => 1,
    ]);

    $applicant = Database::selectOne(
        "SELECT `id`, `user_id` FROM `creators` WHERE `deleted_at` IS NULL AND `user_id` <> :id ORDER BY `id` LIMIT 1",
        ['id' => $bid]
    );

    $applicationId = $applicant === null ? 0 : Database::insert('campaign_applications', [
        'campaign_id'     => $campaignId,
        'creator_id'      => (int) $applicant['id'],
        'creator_user_id' => (int) $applicant['user_id'],
        'status'          => 'applied',
        'pitch'           => 'Pick me',
    ]);

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

    if ($applicationId > 0) {
        $application = Database::selectOne('SELECT `status`, `decline_reason` FROM `campaign_applications` WHERE `id` = :id', ['id' => $applicationId]) ?? [];
        Suite::check(
            'its applicant is told the campaign closed',
            $application['status'] === 'declined' && str_contains((string) $application['decline_reason'], 'closed'),
            json_encode($application)
        );

        Database::execute('DELETE FROM `campaign_applications` WHERE `id` = :id', ['id' => $applicationId]);
    }

    Database::execute('DELETE FROM `campaigns` WHERE `id` = :id', ['id' => $campaignId]);
}

// =================================================================
Suite::group('Privacy: the legacy DELETE /auth/account');
// =================================================================

$legacy = $throwaway('legacy', 'brand');

if ($legacy === null) {
    Suite::skipGroup('Privacy: the legacy DELETE /auth/account', 'registration failed: ' . Actors::lastError());
} else {
    $noProof = Http::delete('/auth/account', [], $legacy['name']);
    Suite::status('without a password it is refused', $noProof, 422);
    Suite::equals('…as reauth_required', $noProof->code(), 'reauth_required');

    $done = Http::delete('/auth/account', ['password' => $legacy['password'], 'reason' => 'legacy'], $legacy['name']);
    Suite::ok('with the password it deletes', $done, 200);
    Suite::check(
        '…through the same erasure',
        Database::selectOne('SELECT `email`, `name` FROM `users` WHERE `id` = :id', ['id' => (int) $legacy['id']]) === ['email' => null, 'name' => 'Deleted user']
    );
}

// =================================================================
// Cleanup
// =================================================================

foreach (array_unique($privacyCleanup) as $userId) {
    Database::execute("DELETE FROM `activity_logs` WHERE `actor_type` = 'user' AND `actor_id` = :id", ['id' => $userId]);
    Database::execute("DELETE FROM `activity_logs` WHERE `subject_type` = 'user' AND `subject_id` = :id", ['id' => $userId]);
    Database::execute('DELETE FROM `otp_verifications` WHERE `user_id` = :id', ['id' => $userId]);
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
Database::execute("DELETE FROM `email_queue` WHERE `to_email` LIKE 'it-privacy-%@zuste.test'");

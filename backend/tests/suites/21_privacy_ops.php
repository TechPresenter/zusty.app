<?php

declare(strict_types=1);

/**
 * Privacy operations: the published policy, the scheduled jobs that keep its
 * retention promises, backups and the crontab, and the tools support uses to
 * act on a request — Admin → Privacy requests, Users → Erase account, the
 * recycle bin and the command-line scripts. Also the strict versions of two
 * weak checks in 14_privacy: a one-time code works once (P55), and a deleted
 * creator's profile really leaves public view (P56).
 *
 * Conventions (C22): actors are po-… (it-po-…@zuste.test). Rate limits are
 * cleared at the top, and once more between groups before the second batch
 * of throwaway registrations; inside a group only named buckets are cleared.
 * No setting is changed. Nothing is deleted from `users`: every throwaway
 * ends as an erased tombstone, and the suite deletes only rows it inserted
 * itself. Its lines are stripped from storage/privacy/erasure-ledger.jsonl
 * at the end. The jobs run here (tokens:prune, logs:rotate,
 * privacy:finish-deletions, privacy:purge-exports) do their normal work on
 * the whole database, as they would at night.
 */

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\RateLimiter;
use Zuste\Helpers\Str;
use Zuste\Services\AccountDeletionService;
use Zuste\Services\BackupService;
use Zuste\Services\CronRunner;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

$poStarted  = date('Y-m-d H:i:s', time() - 1);
$poOrigin   = (string) preg_replace('#/api/v\d+/?$#', '', Http::baseUrl());
$poBackend  = dirname(__DIR__, 2);

/** Accounts this suite erased: left as tombstones, stripped from the ledger at the end. */
$poErased = [];

/** Rows this suite inserted itself, by table, deleted at the end. */
$poRows = [
    'email_queue' => [], 'sms_queue' => [], 'push_queue' => [], 'payment_webhooks' => [],
    'login_history' => [], 'api_logs' => [], 'activity_logs' => [], 'campaigns' => [],
];

/** Files this suite planted in the backup folder. */
$poFiles = [];

/** A throwaway account for one scenario, registered through the API. */
$poThrowaway = static function (string $label, string $type): ?array {
    $name  = 'po-' . $label . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    $actor = Actors::create($name, $type);

    if ($actor === null) {
        return null;
    }

    $id = (int) ($actor['id'] ?: Database::scalar('SELECT `id` FROM `users` WHERE `email` = :e', ['e' => $actor['email']]));

    return $id > 0 ? ['id' => $id, 'name' => $name] + $actor : null;
};

/** A users row inserted directly, for states the API cannot produce. */
$poInsertUser = static function (string $label, string $name, ?string $deletedAt): int {
    return (int) Database::insert('users', [
        'uuid'          => Crypto::uuid4(),
        'account_type'  => 'brand',
        'name'          => $name,
        'email'         => 'it-po-' . $label . '-' . bin2hex(random_bytes(3)) . '@zuste.test',
        'referral_code' => 'ITPO' . strtoupper(bin2hex(random_bytes(5))),
        'status'        => $deletedAt === null ? 'active' : 'deleted',
        'deleted_at'    => $deletedAt,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
};

/**
 * A known one-time code, planted where the server will look for it, always
 * bound to its user.
 *
 * @return array{0:string,1:int} the code and its row id
 */
$poPlantCode = static function (int $userId, string $destination, string $purpose): array {
    $code = (string) random_int(100000, 999999);
    $id   = (int) Database::insert('otp_verifications', [
        'user_id'     => $userId,
        'channel'     => 'email',
        'destination' => strtolower($destination),
        'purpose'     => $purpose,
        'code_hash'   => Crypto::hashToken($code),
        'expires_at'  => date('Y-m-d H:i:s', time() + 300),
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    return [$code, $id];
};

/** A page as a browser asks for it. The harness always sends a JSON Accept header. */
$poBrowser = static function (string $url): string {
    $body = @file_get_contents($url, false, stream_context_create(['http' => [
        'header'        => "Accept: text/html,application/xhtml+xml\r\n",
        'ignore_errors' => true,
        'timeout'       => 30,
    ]]));

    return is_string($body) ? $body : '';
};

/**
 * Run one of the backend's scripts, as an operator would.
 *
 * @return array{0:int,1:string} exit code and output
 */
$poScript = static function (string $script, array $arguments) use ($poBackend): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($poBackend . '/scripts/' . $script);

    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    $output = [];
    $code   = 0;
    exec($command . ' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
};

/** The deletion record of an account, with its summary decoded. */
$poDeletion = static function (int $userId): ?array {
    $row = Database::selectOne(
        'SELECT * FROM `account_deletion_requests` WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 1',
        ['id' => $userId]
    );

    if ($row !== null) {
        $row['summary'] = json_decode((string) ($row['summary'] ?? ''), true) ?: [];
    }

    return $row;
};

/** Is the account an erased tombstone? */
$poIsTombstone = static function (int $userId): bool {
    $row = Database::selectOne('SELECT `name`, `email`, `status`, `deleted_at` FROM `users` WHERE `id` = :id', ['id' => $userId]);

    return $row !== null && $row['name'] === 'Deleted user' && $row['email'] === null
        && $row['status'] === 'deleted' && $row['deleted_at'] !== null;
};

// =================================================================
Suite::group('Privacy ops: the published policy matches the feature');
// =================================================================

$poPage    = Http::get('/pages/privacy');
$poContent = (string) $poPage->at('data.page.content');
Suite::ok('GET /pages/privacy answers', $poPage, 200);

foreach (['Privacy &amp; data → Delete my account', 'cannot be undone'] as $poPhrase) {
    Suite::check("the policy says '{$poPhrase}'", str_contains($poContent, $poPhrase), 'not in the published page');
}

Suite::check('the policy no longer promises an erasure "within 30 days"', !str_contains($poContent, 'within 30 days'));
Suite::check(
    'its "Last updated" line moved on',
    !in_array((string) $poPage->at('data.page.subtitle'), ['Last updated: 1 January 2026', 'Last updated: 2 October 2026'], true),
    (string) $poPage->at('data.page.subtitle')
);

// zustee.com takes no mail (migration 050): nothing published may send anyone
// to a mailbox there. URLs on that host are not mailboxes and are not checked.
$poDeadMailbox = '/[A-Za-z0-9._%+-]@zustee\.com(?![A-Za-z0-9_-]|\.[A-Za-z0-9_-])/i';

Suite::check('the policy names no @zustee.com mailbox', preg_match($poDeadMailbox, $poContent) === 0);
Suite::check(
    '…and sends requests by email to an address at zusty.app',
    preg_match('/mailto:[A-Za-z0-9._%+-]+@zusty\.app/i', $poContent) === 1,
    'no mailto: at zusty.app'
);

$poDeadPages = array_values(array_filter(
    Database::select('SELECT `slug`, `title`, `subtitle`, `content`, `meta_title`, `meta_description`, `meta_keywords` FROM `pages`'),
    static fn (array $row): bool => preg_match($poDeadMailbox, implode(' ', array_map('strval', $row))) === 1
));
Suite::equals('no CMS page names a @zustee.com mailbox', array_column($poDeadPages, 'slug'), []);

$poDeadSettings = array_values(array_filter(
    Database::select('SELECT `group_key`, `setting_key`, `value` FROM `settings` WHERE `is_encrypted` = 0'),
    static fn (array $row): bool => preg_match($poDeadMailbox, (string) $row['value']) === 1
));
Suite::equals(
    'no setting holds a @zustee.com mailbox',
    array_map(static fn (array $row): string => $row['group_key'] . '.' . $row['setting_key'], $poDeadSettings),
    []
);
Suite::check(
    'the support address mail falls back to is at zusty.app',
    str_ends_with(\Zuste\Services\MailService::DEFAULT_SUPPORT_ADDRESS, '@zusty.app')
        && str_ends_with(\Zuste\Services\MailService::DEFAULT_FROM_ADDRESS, '@zusty.app')
        && !str_contains(\Zuste\Services\MailService::supportAddress(), '@zustee.com'),
    \Zuste\Services\MailService::supportAddress()
);

// Drift guard (C19): every kind of record the app says it keeps is named in
// the policy. A new key in AccountDeletionService::retained() fails here until
// the policy text and this map are updated together.
$poPhrases = [
    'financial'    => 'invoices',
    'messages'     => 'Messages you sent',
    'reviews'      => 'Reviews you wrote',
    'safety'       => 'Support tickets',
    'security'     => 'Security logs',
    'verification' => 'identity verification',
    'record'       => 'A deletion record',
    'backups'      => 'Backups',
];
$poRetainedKeys = array_column(AccountDeletionService::policy()['retained'], 'key');
$poUnmapped     = array_values(array_diff($poRetainedKeys, array_keys($poPhrases)));

Suite::check('every record the app keeps has a policy phrase mapped', $poUnmapped === [], 'unmapped: ' . implode(', ', $poUnmapped));

foreach ($poRetainedKeys as $poKey) {
    if (isset($poPhrases[$poKey])) {
        Suite::check(
            "the policy names what the app keeps: {$poKey}",
            str_contains($poContent, $poPhrases[$poKey]),
            "missing '{$poPhrases[$poKey]}'"
        );
    }
}

$poFaq = null;

foreach ((array) Http::get('/faqs', ['q' => 'delete'])->at('data.faqs') as $poItem) {
    if (($poItem['question'] ?? '') === 'How do I delete my account?') {
        $poFaq = (string) ($poItem['answer'] ?? '');
    }
}

Suite::check('the FAQ "How do I delete my account?" is published', $poFaq !== null);
Suite::check(
    '…and points at Privacy & data → Delete my account',
    $poFaq !== null && str_contains($poFaq, 'Privacy & data → Delete my account'),
    (string) $poFaq
);
Suite::check(
    '…without the old promises',
    $poFaq !== null && !str_contains($poFaq, 'within 30 days') && !str_contains($poFaq, 'close any open bookings'),
    (string) $poFaq
);

$poPrivacyHtml = $poBrowser($poOrigin . '/privacy');
Suite::check('/privacy shows how to delete an account', str_contains($poPrivacyHtml, 'Delete my account'), substr($poPrivacyHtml, 0, 200));
Suite::check('/privacy no longer says "within 30 days"', $poPrivacyHtml !== '' && !str_contains($poPrivacyHtml, 'within 30 days'));
Suite::check('/privacy links the account-deletion page', str_contains($poPrivacyHtml, 'href="/account-deletion"'));

// Financial and support records keep their names on file; only reviews and
// messages show "Deleted user". The page must never say otherwise.
$poDeletionHtml = $poBrowser($poOrigin . '/account-deletion');
Suite::check('/account-deletion renders', str_contains($poDeletionHtml, 'Delete my account'), substr($poDeletionHtml, 0, 200));
Suite::check(
    '/account-deletion never says records are kept "without your name"',
    $poDeletionHtml !== '' && !str_contains(str_replace('without your name or photo', '', $poDeletionHtml), 'without your name')
);

$poTerms = (string) Http::get('/pages/terms')->at('data.page.content');
Suite::check(
    'the terms name everything to settle before closing an account',
    str_contains($poTerms, 'once bookings in progress, disputes, your wallet balance') && !str_contains($poTerms, 'once open bookings are settled')
);

// =================================================================
Suite::group('Privacy ops: cron.php prints the crontab');
// =================================================================

[$poCode, $poOutput] = $poScript('cron.php', ['--crontab']);
Suite::equals('php scripts/cron.php --crontab succeeds', $poCode, 0);

$poLineFor = static function (string $output, string $job): string {
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/cron\.php\s+' . preg_quote($job, '/') . '\s*$/', trim($line)) === 1) {
            return trim($line);
        }
    }

    return '';
};

$poPurgeLine  = $poLineFor($poOutput, 'privacy:purge-exports');
$poFinishLine = $poLineFor($poOutput, 'privacy:finish-deletions');

Suite::check('it prints privacy:purge-exports hourly at :15', str_starts_with($poPurgeLine, '15 * * * * '), $poPurgeLine ?: $poOutput);
Suite::check('it prints privacy:finish-deletions every 10 minutes', str_starts_with($poFinishLine, '*/10 * * * * '), $poFinishLine ?: $poOutput);
Suite::check(
    'every line names this PHP binary',
    $poPurgeLine !== '' && str_contains($poPurgeLine, PHP_BINARY),
    $poPurgeLine
);

// =================================================================
// Static check of the installable crontab (P07): every enabled job, at the
// schedule System → Jobs shows.
// =================================================================

$poCrontab = (string) @file_get_contents($poBackend . '/deploy/cron/zustee-crontab');
$poCronJobs = [];

foreach (explode("\n", $poCrontab) as $poLine) {
    $poLine = trim($poLine);

    if ($poLine === '' || str_starts_with($poLine, '#')) {
        continue;
    }

    if (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+.*cron\.php\s+([a-z:-]+)/', $poLine, $poMatch) === 1) {
        $poCronJobs[$poMatch[2]] = (string) preg_replace('/\s+/', ' ', $poMatch[1]);
    }
}

if (!isset($poCronJobs['privacy:finish-deletions'])) {
    Suite::skipGroup('Privacy ops: the installable crontab lists every job', 'deferred peer crontab change pending');
} else {
    Suite::group('Privacy ops: the installable crontab lists every job');

    foreach (Database::select('SELECT `job_key`, `schedule_hint` FROM `scheduled_jobs` WHERE `is_enabled` = 1 ORDER BY `id`') as $poJob) {
        $poKey = (string) $poJob['job_key'];

        if (!CronRunner::exists($poKey)) {
            continue;
        }

        $poHint = (string) preg_replace('/\s+/', ' ', trim((string) $poJob['schedule_hint']));

        Suite::equals("deploy/cron/zustee-crontab runs {$poKey} at {$poHint}", $poCronJobs[$poKey] ?? 'missing', $poHint);
    }
}

// =================================================================
Suite::group('Privacy ops: privacy:finish-deletions is registered and runs');
// =================================================================

Suite::check('the job is in the registry', CronRunner::exists('privacy:finish-deletions'));

$poJobRow = Database::selectOne(
    "SELECT `name`, `schedule_hint`, `is_enabled` FROM `scheduled_jobs` WHERE `job_key` = 'privacy:finish-deletions'"
);
Suite::check(
    'System → Jobs lists it, enabled, every 10 minutes',
    $poJobRow !== null && (int) $poJobRow['is_enabled'] === 1 && $poJobRow['schedule_hint'] === '*/10 * * * *',
    json_encode($poJobRow) ?: ''
);

try {
    $poRun = CronRunner::run('privacy:finish-deletions');
    Suite::check(
        'a run reports what it found and finished',
        isset($poRun['due'], $poRun['completed'], $poRun['pending'], $poRun['needs_attention']),
        json_encode($poRun) ?: ''
    );
} catch (\Throwable $e) {
    Suite::check('a run reports what it found and finished', false, $e->getMessage());
}

Suite::equals(
    'the run is recorded as a success',
    Database::scalar("SELECT `last_status` FROM `scheduled_jobs` WHERE `job_key` = 'privacy:finish-deletions'"),
    'success'
);

// =================================================================
Suite::group('Privacy ops: tokens:prune keeps the retention promises');
// =================================================================

$poAgo = static fn (int $days): string => date('Y-m-d H:i:s', time() - $days * 86400);
$poTag = bin2hex(random_bytes(3));

$poQueued = [
    // [table, row, expected to survive]
    ['email_queue', ['to_email' => "it-po-ret-{$poTag}@zuste.test", 'subject' => 'IT retention sent 8 days ago', 'body_html' => 'x', 'status' => 'sent', 'sent_at' => $poAgo(8), 'created_at' => $poAgo(8)], false],
    ['email_queue', ['to_email' => "it-po-ret-{$poTag}@zuste.test", 'subject' => 'IT retention sent yesterday', 'body_html' => 'x', 'status' => 'sent', 'sent_at' => $poAgo(1), 'created_at' => $poAgo(1)], true],
    ['email_queue', ['to_email' => "it-po-ret-{$poTag}@zuste.test", 'subject' => 'IT retention failed 31 days ago', 'body_html' => 'x', 'status' => 'failed', 'created_at' => $poAgo(31)], false],
    ['email_queue', ['to_email' => "it-po-ret-{$poTag}@zuste.test", 'subject' => 'IT retention unsent for 29 days', 'body_html' => 'x', 'status' => 'pending', 'created_at' => $poAgo(29)], true],
    // A worker that died mid-send leaves 'sending' behind, and nothing retries
    // it: after the failed/unsent month it goes like a failed copy.
    ['email_queue', ['to_email' => "it-po-ret-{$poTag}@zuste.test", 'subject' => 'IT retention stuck sending for 31 days', 'body_html' => 'x', 'status' => 'sending', 'created_at' => $poAgo(31)], false],
    ['email_queue', ['to_email' => "it-po-ret-{$poTag}@zuste.test", 'subject' => 'IT retention sending since yesterday', 'body_html' => 'x', 'status' => 'sending', 'created_at' => $poAgo(1)], true],
    ['sms_queue', ['to_phone' => '+910000' . random_int(100000, 999999), 'message' => 'IT retention stuck sending for 31 days', 'status' => 'sending', 'created_at' => $poAgo(31)], false],
    ['push_queue', ['target_type' => 'topic', 'target_value' => "it-po-ret-{$poTag}", 'title' => 'IT', 'body' => 'IT retention stuck sending for 31 days', 'status' => 'sending', 'created_at' => $poAgo(31)], false],
    ['sms_queue', ['to_phone' => '+910000' . random_int(100000, 999999), 'message' => 'IT retention sent 8 days ago', 'status' => 'sent', 'sent_at' => $poAgo(8), 'created_at' => $poAgo(8)], false],
    ['sms_queue', ['to_phone' => '+910000' . random_int(100000, 999999), 'message' => 'IT retention sent yesterday', 'status' => 'sent', 'sent_at' => $poAgo(1), 'created_at' => $poAgo(1)], true],
    ['push_queue', ['target_type' => 'topic', 'target_value' => "it-po-ret-{$poTag}", 'title' => 'IT', 'body' => 'IT retention sent 8 days ago', 'status' => 'sent', 'sent_at' => $poAgo(8), 'created_at' => $poAgo(8)], false],
    ['push_queue', ['target_type' => 'topic', 'target_value' => "it-po-ret-{$poTag}", 'title' => 'IT', 'body' => 'IT retention sent yesterday', 'status' => 'sent', 'sent_at' => $poAgo(1), 'created_at' => $poAgo(1)], true],
    ['payment_webhooks', ['gateway' => 'razorpay', 'event_id' => "it-po-{$poTag}-old", 'event_type' => 'it.retention', 'payload' => '{}', 'status' => 'failed', 'created_at' => $poAgo(91)], false],
    ['payment_webhooks', ['gateway' => 'razorpay', 'event_id' => "it-po-{$poTag}-new", 'event_type' => 'it.retention', 'payload' => '{}', 'status' => 'received', 'created_at' => $poAgo(89)], true],
];

foreach ($poQueued as $poIndex => [$poTable, $poRow]) {
    $poQueued[$poIndex][3] = (int) Database::insert($poTable, $poRow);
    $poRows[$poTable][]    = $poQueued[$poIndex][3];
}

try {
    $poPrune = CronRunner::run('tokens:prune');
} catch (\Throwable $e) {
    $poPrune = ['error' => $e->getMessage()];
}

Suite::check(
    'the job reports the queues, webhooks and backups it pruned',
    isset($poPrune['email_queue'], $poPrune['sms_queue'], $poPrune['push_queue'], $poPrune['webhooks'], $poPrune['backups']['files'], $poPrune['backups']['rows']),
    json_encode($poPrune) ?: ''
);

foreach ($poQueued as [$poTable, $poRow, $poSurvives, $poId]) {
    $poExists = Database::scalar('SELECT 1 FROM `' . $poTable . '` WHERE `id` = :id', ['id' => $poId]) !== null;
    $poLabel  = $poTable . ': ' . ($poRow['subject'] ?? $poRow['message'] ?? $poRow['body'] ?? ($poRow['status'] . ' ' . $poRow['created_at']));

    Suite::check(($poSurvives ? 'kept — ' : 'deleted — ') . $poLabel, $poExists === $poSurvives, $poExists ? 'still there' : 'gone');
}

// =================================================================
Suite::group('Privacy ops: logs:rotate trims sign-in history and API logs');
// =================================================================

$poLogins = [
    [(int) Database::insert('login_history', [
        'actor_type' => 'user', 'identifier' => 'it-po-rot@zuste.test', 'outcome' => 'bad_credentials',
        'auth_method' => 'password', 'ip_address' => '203.0.113.40', 'created_at' => $poAgo(400),
    ]), false, 'a 400-day-old sign-in'],
    [(int) Database::insert('login_history', [
        'actor_type' => 'user', 'identifier' => 'it-po-rot@zuste.test', 'outcome' => 'bad_credentials',
        'auth_method' => 'password', 'ip_address' => '203.0.113.41', 'created_at' => $poAgo(10),
    ]), true, 'a 10-day-old sign-in'],
];
$poApiLogs = [
    [(int) Database::insert('api_logs', [
        'method' => 'GET', 'path' => '/it-po-rotation', 'status_code' => 200, 'created_at' => $poAgo(200),
    ]), false, 'a 200-day-old API log'],
    [(int) Database::insert('api_logs', [
        'method' => 'GET', 'path' => '/it-po-rotation', 'status_code' => 200, 'created_at' => $poAgo(10),
    ]), true, 'a 10-day-old API log'],
];

foreach ($poLogins as [$poId]) {
    $poRows['login_history'][] = $poId;
}

foreach ($poApiLogs as [$poId]) {
    $poRows['api_logs'][] = $poId;
}

try {
    $poRotate = CronRunner::run('logs:rotate');
} catch (\Throwable $e) {
    $poRotate = ['error' => $e->getMessage()];
}

Suite::check('the job reports sign-in history and API logs', isset($poRotate['login_history'], $poRotate['api_logs']), json_encode($poRotate) ?: '');

foreach ([['login_history', $poLogins], ['api_logs', $poApiLogs]] as [$poTable, $poList]) {
    foreach ($poList as [$poId, $poSurvives, $poLabel]) {
        $poExists = Database::scalar('SELECT 1 FROM `' . $poTable . '` WHERE `id` = :id', ['id' => $poId]) !== null;
        Suite::check(($poSurvives ? 'kept — ' : 'deleted — ') . $poLabel, $poExists === $poSurvives, $poExists ? 'still there' : 'gone');
    }
}

// =================================================================
Suite::group('Privacy ops: backups past their retention are deleted');
// =================================================================

$poDays     = max(1, (int) Config::get('backup.retention_days', 14));
$poBackups  = (array) (array_values(array_filter(
    AccountDeletionService::policy()['retained'],
    static fn (array $item): bool => $item['key'] === 'backups'
))[0] ?? []);

Suite::check(
    'the app tells users how long backups are kept',
    str_contains((string) ($poBackups['detail'] ?? ''), $poDays . ' days'),
    (string) ($poBackups['detail'] ?? 'no backups entry')
);

$poBackupDir = rtrim((string) Config::get('storage.backup_path', ''), '/\\');

if ($poBackupDir === '' || (!is_dir($poBackupDir) && !@mkdir($poBackupDir, 0750, true) && !is_dir($poBackupDir))) {
    Suite::check('the backup folder is usable', false, $poBackupDir);
} else {
    $poOld = [$poBackupDir . '/zuste-db-it-po-old-' . $poTag . '.sql', $poBackupDir . '/it-po-predeploy-' . $poTag . '.sql.gz'];
    $poNew = $poBackupDir . '/zuste-db-it-po-new-' . $poTag . '.sql';

    foreach ([...$poOld, $poNew] as $poFile) {
        file_put_contents($poFile, "-- IT privacy ops\n");
        $poFiles[] = $poFile;
    }

    foreach ($poOld as $poFile) {
        touch($poFile, time() - ($poDays + 2) * 86400);
    }

    $poPruned = BackupService::pruneExpired();
    clearstatcache();

    Suite::check(
        'pruneExpired() reports files and rows',
        is_int($poPruned['files'] ?? null) && is_int($poPruned['rows'] ?? null) && $poPruned['files'] >= 2,
        json_encode($poPruned) ?: ''
    );
    Suite::check('an old panel dump is deleted', !is_file($poOld[0]));
    Suite::check("an old deploy-script dump is deleted too", !is_file($poOld[1]));
    Suite::check('a recent dump is kept', is_file($poNew));
}

// =================================================================
Suite::group('Privacy ops: a one-time code works once (strict)');
// =================================================================

$poBrand   = Actors::create('po-brand', 'brand');
$poBrandId = $poBrand === null ? 0 : (Actors::id('po-brand') ?: (int) Database::scalar(
    'SELECT `id` FROM `users` WHERE `email` = :e AND `deleted_at` IS NULL',
    ['e' => Actors::email('po-brand')]
));
$poBrandEmail = strtolower(Actors::email('po-brand'));

if ($poBrand === null || $poBrandId <= 0) {
    Suite::skipGroup('Privacy ops: a one-time code works once (strict)', 'could not create po-brand: ' . Actors::lastError());
} else {
    // Only this account's buckets: the confirmation gate, and the export and
    // code routes, which earlier runs within the hour may have filled.
    RateLimiter::clear('privacy:reauth:' . $poBrandId);
    RateLimiter::clear('throttle:privacy-export:user:' . $poBrandId);
    RateLimiter::clear('throttle:privacy-code:user:' . $poBrandId);

    // Out of the export cooldown: failed exports do not count, and their
    // archives go with the next purge.
    $poUncool = static fn () => Database::execute(
        "UPDATE `data_export_requests` SET `status` = 'failed' WHERE `user_id` = :id",
        ['id' => $poBrandId]
    );
    $poUncool();

    [$poExportCode, $poExportCodeId] = $poPlantCode($poBrandId, $poBrandEmail, 'data_export');

    $poFirst = Http::post('/me/privacy/exports', ['code' => $poExportCode, 'channel' => 'email'], 'po-brand');
    Suite::status('a planted export code confirms an export', $poFirst, 201);
    Suite::check(
        '…and is marked used',
        Database::scalar('SELECT `verified_at` FROM `otp_verifications` WHERE `id` = :id', ['id' => $poExportCodeId]) !== null
    );

    $poUncool();

    $poReused = Http::post('/me/privacy/exports', ['code' => $poExportCode, 'channel' => 'email'], 'po-brand');
    Suite::status('a code works once', $poReused, 422);
    Suite::check('…refused as a spent code', str_starts_with($poReused->code(), 'otp_'), $poReused->describe());

    // Email: the server picks the destination, and a failed send leaves nothing behind.
    RateLimiter::clear('otp:cooldown:data_export:' . $poBrandEmail);
    RateLimiter::clear('otp:send:data_export:' . $poBrandEmail);

    $poBefore = (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `otp_verifications`');
    $poSend   = Http::post('/me/privacy/verification-code', ['purpose' => 'data_export', 'channel' => 'email'], 'po-brand');

    Suite::check(
        'an email code is sent, or the provider failure is reported as otp_delivery_failed',
        $poSend->status === 200 || ($poSend->status === 503 && $poSend->code() === 'otp_delivery_failed'),
        $poSend->describe()
    );

    $poStored = Database::select(
        "SELECT `destination` FROM `otp_verifications`
          WHERE `id` > :before AND `user_id` = :id AND `purpose` = 'data_export' AND `channel` = 'email'",
        ['before' => $poBefore, 'id' => $poBrandId]
    );

    if ($poSend->status === 200) {
        Suite::check(
            '…exactly one code, stored for the account\'s own address',
            count($poStored) === 1 && $poStored[0]['destination'] === $poBrandEmail,
            json_encode($poStored) ?: ''
        );
        Suite::check('…and the address comes back masked', str_contains((string) $poSend->at('data.destination'), '*'), $poSend->describe());
    } else {
        Suite::equals('…and the failed send left no code behind', count($poStored), 0);
    }

    // SMS: offered or refused with its reason, never anything else.
    $poChannels = array_column((array) Http::get('/me/privacy', [], 'po-brand')->at('data.verification.channels'), 'channel');
    $poUserRow  = Database::selectOne('SELECT `phone`, `phone_country_code` FROM `users` WHERE `id` = :id', ['id' => $poBrandId]) ?? [];
    $poPhone    = !empty($poUserRow['phone'])
        ? Str::normalizePhone((string) ($poUserRow['phone_country_code'] ?? '') . (string) $poUserRow['phone'])
        : '';

    if ($poPhone !== '') {
        RateLimiter::clear('otp:cooldown:data_export:' . strtolower($poPhone));
        RateLimiter::clear('otp:send:data_export:' . strtolower($poPhone));
    }

    $poBefore = (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `otp_verifications`');
    $poSms    = Http::post('/me/privacy/verification-code', ['purpose' => 'data_export', 'channel' => 'sms'], 'po-brand');
    $poSmsRows = Database::select(
        "SELECT `destination` FROM `otp_verifications`
          WHERE `id` > :before AND `user_id` = :id AND `purpose` = 'data_export' AND `channel` = 'sms'",
        ['before' => $poBefore, 'id' => $poBrandId]
    );

    if (!in_array('sms', $poChannels, true)) {
        Suite::check(
            'SMS is not offered, so it is refused with its reason',
            $poSms->status === 422 && in_array($poSms->code(), ['sms_unavailable', 'no_phone'], true),
            $poSms->describe()
        );
        Suite::equals('…and no SMS code was stored', count($poSmsRows), 0);
    } else {
        Suite::check(
            'an SMS code is sent, or the provider failure is reported as otp_delivery_failed',
            $poSms->status === 200 || ($poSms->status === 503 && $poSms->code() === 'otp_delivery_failed'),
            $poSms->describe()
        );
        Suite::check(
            '…leaving exactly the code that went out, for the account\'s own number',
            $poSms->status === 200
                ? count($poSmsRows) === 1 && $poSmsRows[0]['destination'] === $poPhone
                : $poSmsRows === [],
            json_encode($poSmsRows) ?: ''
        );
    }
}

// =================================================================
Suite::group('Privacy ops: a deleted creator leaves public view (strict)');
// =================================================================

$poCreator = $poThrowaway('creator', 'creator');

if ($poCreator === null) {
    Suite::skipGroup('Privacy ops: a deleted creator leaves public view (strict)', 'registration failed: ' . Actors::lastError());
} else {
    $poCreatorUser = (int) $poCreator['id'];
    $poCreatorId   = (int) Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :id', ['id' => $poCreatorUser]);
    $poHandle      = (string) Database::scalar('SELECT `handle` FROM `creators` WHERE `id` = :id', ['id' => $poCreatorId]);

    Suite::check('registration made a creator profile', $poCreatorId > 0 && $poHandle !== '');

    // Live and public, so the 404s afterwards prove something.
    Database::update('creators', ['status' => 'approved', 'is_public' => 1], ['id' => $poCreatorId]);

    $poListed = static function () use ($poCreatorId, $poHandle): bool {
        // GET /creators answers `data` as a bare list of cards.
        $ids = array_map('intval', array_column(Http::get('/creators', ['q' => $poHandle])->list(), 'id'));

        return in_array($poCreatorId, $ids, true);
    };

    Suite::status('before deletion the public profile is visible', Http::get('/creators/' . rawurlencode($poHandle)), 200);
    $poListedBefore = $poListed();

    $poGone = Http::post('/me/privacy/delete-account', [
        'password'     => $poCreator['password'],
        'confirmation' => 'DELETE',
    ], $poCreator['name']);
    Suite::ok('the creator deletes their account', $poGone, 200);

    if ($poIsTombstone($poCreatorUser)) {
        $poErased[] = $poCreatorUser;
    }

    Suite::status('the old public profile is gone by handle', Http::get('/creators/' . rawurlencode($poHandle)), 404);
    Suite::status('…and by id', Http::get('/creators/' . $poCreatorId), 404);

    if ($poListedBefore) {
        Suite::check('…and it has left search', !$poListed());
    } else {
        echo "  \033[33mnote\033[0m  search did not list the new profile before deletion (a listing rule hides it), so leaving search is not asserted\n";
    }
}

// =================================================================
// Admin panel: the act tab, Users → Erase account and the recycle bin.
// =================================================================

// Between groups, before the second batch of registrations.
Suite::clearRateLimits();

$poJar           = (string) tempnam(sys_get_temp_dir(), 'it-po-jar');
$poAdminEmail    = 'it-po-admin@zuste.test';
$poAdminName     = 'IT Privacy Ops';
$poAdminPassword = 'ItPrivacyOps!' . bin2hex(random_bytes(4));

/** @return array{status:int,body:string,location:string} */
$poPanel = static function (string $method, string $path, array $fields = []) use ($poOrigin, $poJar): array {
    $handle = curl_init(str_starts_with($path, 'http') ? $path : $poOrigin . $path);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $poJar,
        CURLOPT_COOKIEFILE     => $poJar,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_USERAGENT      => 'zusty-it-privacy-ops/1.0',
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

$poCsrf = static fn (string $html): string => preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m) === 1
    ? html_entity_decode($m[1], ENT_QUOTES)
    : '';

/** @return array{0:string,1:string} [type, message] of the toast a page carries */
$poToast = static function (string $html): array {
    preg_match('/data-flash="([^"]*)"\s+data-flash-type="([^"]*)"/', $html, $m);

    return [$m[2] ?? '', html_entity_decode($m[1] ?? '', ENT_QUOTES)];
};

/** Post a panel form with a token read from [$from], then read the toast where the redirect lands. */
$poSubmit = static function (string $from, string $path, array $fields) use ($poPanel, $poCsrf, $poToast): array {
    $page    = $poPanel('GET', $from);
    $post    = $poPanel('POST', $path, ['_csrf' => $poCsrf($page['body'])] + $fields);
    $landing = $post['location'] !== '' ? $poPanel('GET', $post['location']) : ['status' => 0, 'body' => ''];

    return ['post' => $post, 'toast' => $poToast($landing['body']), 'page' => $landing];
};

$poDropAdmin = static function (string $email): void {
    foreach (Database::column('SELECT `id` FROM `admins` WHERE `email` = :e', ['e' => $email]) as $id) {
        $id = (int) $id;

        Database::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = :id', ['id' => $id]);
        Database::execute("DELETE FROM `login_history` WHERE `actor_type` = 'admin' AND `actor_id` = :id", ['id' => $id]);
        Database::execute("DELETE FROM `activity_logs` WHERE `actor_type` = 'admin' AND `actor_id` = :id", ['id' => $id]);

        try {
            Database::execute('DELETE FROM `admin_permissions` WHERE `admin_id` = :id', ['id' => $id]);
            Database::execute('DELETE FROM `admins` WHERE `id` = :id', ['id' => $id]);
        } catch (\Throwable) {
            // Still referenced somewhere: retire it under another address.
            Database::update('admins', [
                'email'      => 'retired-' . $id . '-' . bin2hex(random_bytes(3)) . '@zuste.test',
                'status'     => 'inactive',
                'deleted_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
        }
    }
};

$poDropAdmin($poAdminEmail);

$poAdminId = (int) Database::insert('admins', [
    'name'                 => $poAdminName,
    'email'                => $poAdminEmail,
    'password_hash'        => Crypto::hashPassword($poAdminPassword),
    'role_id'              => (int) Database::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1'),
    'status'               => 'active',
    'must_change_password' => 0,
    'created_at'           => date('Y-m-d H:i:s'),
]);

$poLogin    = $poPanel('GET', '/admin/login');
$poSignedIn = $poPanel('POST', '/admin/login', [
    '_csrf'    => $poCsrf($poLogin['body']),
    'email'    => $poAdminEmail,
    'password' => $poAdminPassword,
]);
$poPanelReady = $poSignedIn['status'] === 302 && !str_contains($poSignedIn['location'], '/admin/login');

// =================================================================
Suite::group('Privacy ops: support erases and exports from the panel');
// =================================================================

$poPanelUser = $poPanelReady ? $poThrowaway('panel', 'brand') : null;

if (!$poPanelReady || $poPanelUser === null) {
    Suite::skipGroup(
        'Privacy ops: support erases and exports from the panel',
        !$poPanelReady ? 'the probe admin could not sign in' : 'registration failed: ' . Actors::lastError()
    );
} else {
    $poPanelId = (int) $poPanelUser['id'];
    $poActPath = '/admin/privacy-requests?tab=act&user=' . $poPanelId;
    $poAct     = $poPanel('GET', $poActPath);

    Suite::status('the act tab renders for the account', new \Zuste\Tests\Res($poAct['status'], $poAct['body'], [], 0.0), 200);
    Suite::check(
        'it shows what an erasure would do',
        str_contains($poAct['body'], 'Erase the account') && str_contains($poAct['body'], 'What is erased')
            && str_contains($poAct['body'], 'Your profile') && str_contains($poAct['body'], 'Bookings, payments, invoices and payouts'),
        mb_substr(strip_tags($poAct['body']), 0, 300)
    );

    $poExport = $poSubmit($poActPath, '/admin/privacy-requests/export', ['user_id' => $poPanelId]);

    if ($poExport['post']['status'] === 404) {
        Suite::skipGroup('Privacy ops: support erases and exports from the panel (actions)', 'the deferred admin routes are not in place yet');
    } else {
        [$poType, $poMessage] = $poExport['toast'];

        Suite::check(
            'sending a copy says where the link went, masked',
            $poType === 'success' && str_contains($poMessage, '*') && str_contains($poMessage, '@zuste.test'),
            $poType . ': ' . $poMessage
        );
        Suite::check(
            '…and never shows the link',
            !str_contains($poMessage, 'token=') && !str_contains($poMessage, 'exports/download')
                && !str_contains($poExport['page']['body'], 'exports/download?token'),
            $poMessage
        );
        Suite::check(
            '…recorded as a support request by this admin',
            Database::selectOne(
                "SELECT `verified_via`, `requested_by_admin_id` FROM `data_export_requests`
                  WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 1",
                ['id' => $poPanelId]
            ) == ['verified_via' => 'operator', 'requested_by_admin_id' => (string) $poAdminId]
        );

        $poNote = 'IT: confirmed by a reply from the account email';

        // A campaign of this brand, so the erasure has a brand campaign to
        // close and bin (checked in the recycle-bin group below).
        $poCampaignId = (int) Database::insert('campaigns', [
            'code'          => 'ITPO' . strtoupper(bin2hex(random_bytes(4))),
            'brand_user_id' => $poPanelId,
            'title'         => 'IT erased brand campaign ' . $poTag,
            'description'   => 'IT privacy ops: a campaign of a brand that is erased.',
        ]);
        $poRows['campaigns'][] = $poCampaignId;

        $poRefused = $poSubmit($poActPath, '/admin/privacy-requests/erase', [
            'user_id'      => $poPanelId,
            'confirmation' => 'delete me',
            'note'         => $poNote,
        ]);
        Suite::check('an erasure without the typed DELETE is refused', $poRefused['toast'][0] === 'error', implode(': ', $poRefused['toast']));
        Suite::check('…and nothing was erased', !$poIsTombstone($poPanelId) && $poDeletion($poPanelId) === null);

        $poErase = $poSubmit($poActPath, '/admin/privacy-requests/erase', [
            'user_id'      => $poPanelId,
            'confirmation' => 'DELETE',
            'note'         => $poNote,
        ]);
        Suite::check(
            'with DELETE and a note, the account is erased',
            $poErase['toast'][0] === 'success' && str_contains($poErase['toast'][1], 'erased'),
            implode(': ', $poErase['toast'])
        );
        Suite::check('…leaving an anonymous tombstone', $poIsTombstone($poPanelId));

        if ($poIsTombstone($poPanelId)) {
            $poErased[] = $poPanelId;
        }

        $poRecord = $poDeletion($poPanelId) ?? [];
        Suite::check(
            '…recorded as a support request, by this admin, from Support, with the note',
            ($poRecord['verified_via'] ?? '') === 'operator' && ($poRecord['platform'] ?? '') === 'admin'
                && (int) ($poRecord['performed_by_admin_id'] ?? 0) === $poAdminId
                && ($poRecord['summary']['operator']['note'] ?? '') === $poNote
                && ($poRecord['summary']['operator']['via'] ?? '') === 'panel',
            json_encode(array_intersect_key($poRecord, array_flip(['verified_via', 'platform', 'performed_by_admin_id', 'summary']))) ?: ''
        );

        $poAgain = $poSubmit($poActPath, '/admin/privacy-requests/erase', [
            'user_id'      => $poPanelId,
            'confirmation' => 'DELETE',
            'note'         => $poNote,
        ]);
        Suite::check(
            'erasing it again changes nothing and says so',
            str_contains($poAgain['toast'][1], 'already erased') || str_contains($poAgain['page']['body'], 'This account was erased on'),
            implode(': ', $poAgain['toast'])
        );

        $poList = $poPanel('GET', '/admin/privacy-requests');
        Suite::check(
            'the deletions tab shows the support erasure and who did it',
            str_contains($poList['body'], 'Support request (staff)') && str_contains($poList['body'], $poAdminName),
            'status ' . $poList['status']
        );

        // An account staff hid before Delete erased anything is listed, and erased deliberately.
        $poBinned    = $poInsertUser('binned', 'IT Binned', date('Y-m-d H:i:s', time() - 86400));
        $poBinnedRow = Database::selectOne('SELECT `email` FROM `users` WHERE `id` = :id', ['id' => $poBinned]) ?? [];
        $poTab       = $poPanel('GET', '/admin/privacy-requests?tab=act');

        Suite::check(
            '"Deleted by staff but not erased" lists a soft-deleted account',
            str_contains($poTab['body'], 'Deleted by staff but not erased') && str_contains($poTab['body'], (string) $poBinnedRow['email'])
        );

        $poBinErase = $poSubmit('/admin/privacy-requests?tab=act&user=' . $poBinned, '/admin/privacy-requests/erase', [
            'user_id'      => $poBinned,
            'confirmation' => 'DELETE',
            'note'         => 'IT: staff-deleted account erased',
        ]);
        Suite::check(
            '…and the panel erases it',
            $poBinErase['toast'][0] === 'success' && $poIsTombstone($poBinned) && ($poDeletion($poBinned)['verified_via'] ?? '') === 'operator',
            implode(': ', $poBinErase['toast'])
        );

        if ($poIsTombstone($poBinned)) {
            $poErased[] = $poBinned;
        }

        // =================================================================
        Suite::group('Privacy ops: erased accounts are not in the recycle bin');
        // =================================================================

        $poDeletedAt = Database::scalar('SELECT `deleted_at` FROM `users` WHERE `id` = :id', ['id' => $poPanelId]);
        $poBin       = $poPanel('GET', '/admin/recycle-bin?type=users');

        Suite::check(
            'the users bin does not list the tombstone',
            $poBin['status'] === 200 && !str_contains($poBin['body'], 'name="id" value="' . $poPanelId . '"'),
            'status ' . $poBin['status']
        );

        $poRestore = $poSubmit('/admin/recycle-bin?type=users', '/admin/recycle-bin/restore', ['type' => 'users', 'id' => $poPanelId]);
        Suite::check('restoring it is refused', $poRestore['toast'][0] === 'error', implode(': ', $poRestore['toast']));
        Suite::equals(
            '…and it stays deleted, untouched',
            Database::scalar('SELECT `deleted_at` FROM `users` WHERE `id` = :id', ['id' => $poPanelId]),
            $poDeletedAt
        );

        $poPurge = $poSubmit('/admin/recycle-bin?type=users', '/admin/recycle-bin/delete', ['type' => 'users', 'id' => $poPanelId]);
        Suite::check(
            'a permanent delete is refused too, and the row survives',
            $poPurge['toast'][0] === 'error'
                && Database::scalar('SELECT 1 FROM `users` WHERE `id` = :id', ['id' => $poPanelId]) !== null,
            implode(': ', $poPurge['toast'])
        );

        // =================================================================
        Suite::group('Privacy ops: rows of erased accounts are not in the recycle bin');
        // =================================================================

        // [type, table, id, what] for rows the erasures above left soft-deleted.
        $poOwned = [];

        $poCampaignGone = Database::scalar('SELECT `deleted_at` FROM `campaigns` WHERE `id` = :id', ['id' => $poCampaignId]);
        Suite::check('the erasure closed and binned the brand\'s campaign', $poCampaignGone !== null);

        if ($poCampaignGone !== null) {
            $poOwned[] = ['campaigns', 'campaigns', $poCampaignId, 'the erased brand\'s campaign'];
        }

        if (isset($poCreatorId, $poCreatorUser) && $poCreatorId > 0 && $poIsTombstone((int) $poCreatorUser)
            && Database::scalar('SELECT `deleted_at` FROM `creators` WHERE `id` = :id', ['id' => $poCreatorId]) !== null) {
            $poOwned[] = ['creators', 'creators', (int) $poCreatorId, 'the erased creator\'s profile'];
        } else {
            echo "  \033[33mnote\033[0m  no erased creator profile from the earlier group, so the creators bin is not checked\n";
        }

        $poErasedOwner = new \ReflectionMethod(\Zuste\Controllers\Admin\ProductivityController::class, 'erasedOwner');
        $poHardDelete  = new \ReflectionMethod(\Zuste\Controllers\Admin\ProductivityController::class, 'hardDeleteBinned');
        $poController  = new \Zuste\Controllers\Admin\ProductivityController();

        foreach ($poOwned as [$poType, $poTable, $poRowId, $poWhat]) {
            $poBinPage  = $poPanel('GET', '/admin/recycle-bin?type=' . $poType);
            $poBinnedAt = Database::scalar('SELECT `deleted_at` FROM `' . $poTable . '` WHERE `id` = :id', ['id' => $poRowId]);

            Suite::check(
                "the {$poType} bin does not list {$poWhat}",
                $poBinPage['status'] === 200 && !str_contains($poBinPage['body'], 'name="id" value="' . $poRowId . '"'),
                'status ' . $poBinPage['status']
            );

            $poClause = (string) $poErasedOwner->invoke(null, $poType);
            Suite::check(
                '…nor counts it',
                $poClause !== '' && (int) Database::scalar(
                    'SELECT COUNT(*) FROM `' . $poTable . '` WHERE `id` = :id AND `deleted_at` IS NOT NULL AND NOT ' . $poClause,
                    ['id' => $poRowId]
                ) === 0
            );

            $poRestoreOwned = $poSubmit('/admin/recycle-bin?type=' . $poType, '/admin/recycle-bin/restore', ['type' => $poType, 'id' => $poRowId]);
            Suite::check('restoring it is refused', $poRestoreOwned['toast'][0] === 'error', implode(': ', $poRestoreOwned['toast']));
            Suite::equals(
                '…and it stays deleted, untouched',
                Database::scalar('SELECT `deleted_at` FROM `' . $poTable . '` WHERE `id` = :id', ['id' => $poRowId]),
                $poBinnedAt
            );

            $poPurgeOwned = $poSubmit('/admin/recycle-bin?type=' . $poType, '/admin/recycle-bin/delete', ['type' => $poType, 'id' => $poRowId]);
            Suite::check(
                'a permanent delete is refused, and the row survives',
                $poPurgeOwned['toast'][0] === 'error'
                    && Database::scalar('SELECT 1 FROM `' . $poTable . '` WHERE `id` = :id', ['id' => $poRowId]) !== null,
                implode(': ', $poPurgeOwned['toast'])
            );

            // Empty and the retention purge both delete through
            // hardDeleteBinned(); run it, in-process, on this row alone, as
            // the retention purge would see it a year from now. Emptying a
            // whole type here would destroy other binned rows in this database.
            Database::execute(
                'UPDATE `' . $poTable . '` SET `deleted_at` = NOW() - INTERVAL 400 DAY WHERE `id` = :id',
                ['id' => $poRowId]
            );

            try {
                $poSwept = (array) $poHardDelete->invoke(
                    $poController,
                    $poType,
                    '`deleted_at` < DATE_SUB(NOW(), INTERVAL :days DAY) AND `id` = :bin_only',
                    ['days' => 30, 'bin_only' => $poRowId]
                );
            } finally {
                Database::execute(
                    'UPDATE `' . $poTable . '` SET `deleted_at` = :at WHERE `id` = :id',
                    ['at' => $poBinnedAt, 'id' => $poRowId]
                );
            }

            Suite::check(
                '…and Empty or the retention purge leave it too',
                ($poSwept['deleted'] ?? -1) === 0
                    && Database::scalar('SELECT 1 FROM `' . $poTable . '` WHERE `id` = :id', ['id' => $poRowId]) !== null,
                json_encode($poSwept) ?: ''
            );
        }
    }
}

// =================================================================
Suite::group('Privacy ops: Users → Erase account erases');
// =================================================================

$poUsersUser = $poPanelReady ? $poThrowaway('users', 'brand') : null;

if (!$poPanelReady || $poUsersUser === null) {
    Suite::skipGroup(
        'Privacy ops: Users → Erase account erases',
        !$poPanelReady ? 'the probe admin could not sign in' : 'registration failed: ' . Actors::lastError()
    );
} else {
    $poUsersId   = (int) $poUsersUser['id'];
    $poUsersPage = '/admin/users/' . $poUsersId;
    $poUsersNote = 'IT: confirmed by a reply from the account email (user page)';

    // The page asks for both, and the server checks both: an erasure cannot be undone.
    $poUsersForm = $poPanel('GET', $poUsersPage);
    Suite::check(
        'the Danger zone asks for DELETE and how the request was confirmed',
        str_contains($poUsersForm['body'], 'name="confirmation"') && str_contains($poUsersForm['body'], 'name="note"'),
        'status ' . $poUsersForm['status']
    );

    $poBare = $poSubmit($poUsersPage, $poUsersPage . '/delete', []);
    Suite::check('Erase account with nothing typed is refused', $poBare['toast'][0] === 'error' && str_contains($poBare['toast'][1], 'DELETE'), implode(': ', $poBare['toast']));
    Suite::check('…and nothing was erased', !$poIsTombstone($poUsersId) && $poDeletion($poUsersId) === null);

    $poWrongPhrase = $poSubmit($poUsersPage, $poUsersPage . '/delete', ['confirmation' => 'delete me', 'note' => $poUsersNote]);
    Suite::check('…or with the wrong phrase', $poWrongPhrase['toast'][0] === 'error', implode(': ', $poWrongPhrase['toast']));
    Suite::check('…and nothing was erased', !$poIsTombstone($poUsersId) && $poDeletion($poUsersId) === null);

    $poNoNote = $poSubmit($poUsersPage, $poUsersPage . '/delete', ['confirmation' => 'DELETE', 'note' => '  ']);
    Suite::check(
        '…or without a note of how it was confirmed',
        $poNoNote['toast'][0] === 'error' && str_contains($poNoNote['toast'][1], 'confirmed'),
        implode(': ', $poNoNote['toast'])
    );
    Suite::check('…and nothing was erased', !$poIsTombstone($poUsersId) && $poDeletion($poUsersId) === null);

    // A balance too large to give up blocks it, with the reason.
    Database::execute(
        "INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `locked_balance`) VALUES (:id, 'INR', 5000, 0)
         ON DUPLICATE KEY UPDATE `balance` = 5000",
        ['id' => $poUsersId]
    );

    try {
        $poBlocked = $poSubmit($poUsersPage, $poUsersPage . '/delete', ['confirmation' => 'DELETE', 'note' => $poUsersNote]);
        Suite::check(
            'a blocked account is not erased, and the reason is shown',
            $poBlocked['toast'][0] === 'error' && str_contains($poBlocked['toast'][1], 'not erased'),
            implode(': ', $poBlocked['toast'])
        );
        Suite::check('…and it is intact', !$poIsTombstone($poUsersId) && $poDeletion($poUsersId) === null);
    } finally {
        Database::execute('UPDATE `wallets` SET `balance` = 0 WHERE `user_id` = :id', ['id' => $poUsersId]);
    }

    $poUsersErase = $poSubmit($poUsersPage, $poUsersPage . '/delete', ['confirmation' => 'delete', 'note' => $poUsersNote]);
    Suite::check(
        'with DELETE (any case) and a note, Erase account erases it',
        $poUsersErase['toast'][0] === 'success' && str_contains($poUsersErase['toast'][1], 'cannot be restored'),
        implode(': ', $poUsersErase['toast'])
    );
    Suite::check('…leaving an anonymous tombstone, not a soft-deleted row', $poIsTombstone($poUsersId));

    if ($poIsTombstone($poUsersId)) {
        $poErased[] = $poUsersId;
    }

    $poUsersRecord = $poDeletion($poUsersId) ?? [];
    Suite::check(
        '…recorded as a support erasure by this admin, with the note',
        ($poUsersRecord['verified_via'] ?? '') === 'operator'
            && (int) ($poUsersRecord['performed_by_admin_id'] ?? 0) === $poAdminId
            && ($poUsersRecord['summary']['operator']['note'] ?? null) === $poUsersNote,
        json_encode(array_intersect_key($poUsersRecord, array_flip(['verified_via', 'performed_by_admin_id', 'summary']))) ?: ''
    );
}

// =================================================================
Suite::group('Privacy ops: the command-line tools');
// =================================================================

$poCliUser = $poPanelReady ? $poThrowaway('cli', 'brand') : null;

if (!$poPanelReady || $poCliUser === null) {
    Suite::skipGroup(
        'Privacy ops: the command-line tools',
        !$poPanelReady ? 'no probe admin to act as' : 'registration failed: ' . Actors::lastError()
    );
} else {
    $poCliId = (int) $poCliUser['id'];

    [$poCode, $poOutput] = $poScript('privacy_support_request.php', ['export', '--user=' . $poCliId, '--admin=' . $poAdminEmail]);
    Suite::equals('export succeeds', $poCode, 0);
    Suite::check(
        '…and prints only the masked address the link went to',
        str_contains($poOutput, '*') && str_contains($poOutput, '@zuste.test') && !str_contains($poOutput, 'token=')
            && !str_contains($poOutput, 'exports/download') && !str_contains($poOutput, $poCliUser['email']),
        $poOutput
    );

    [$poCode, $poOutput] = $poScript('privacy_support_request.php', ['delete', '--user=' . $poCliId, '--admin=nobody-' . $poTag . '@zuste.test']);
    Suite::check('delete refuses an admin that does not exist', $poCode === 1 && str_contains($poOutput, 'not an active admin'), $poOutput);

    [$poCode, $poOutput] = $poScript('privacy_support_request.php', ['delete', '--user=' . $poCliId, '--admin=' . $poAdminEmail]);
    Suite::check(
        'delete without --confirm shows what would happen and changes nothing',
        $poCode === 0 && str_contains($poOutput, 'Nothing was changed') && !$poIsTombstone($poCliId),
        $poOutput
    );

    [$poCode, $poOutput] = $poScript('privacy_support_request.php', [
        'delete', '--user=' . $poCliId, '--admin=' . $poAdminEmail, '--note=IT: confirmed by reply', '--confirm',
    ]);
    Suite::check('delete --confirm erases the account', $poCode === 0 && $poIsTombstone($poCliId), $poOutput);

    if ($poIsTombstone($poCliId)) {
        $poErased[] = $poCliId;
    }

    $poCliRecord = $poDeletion($poCliId) ?? [];
    Suite::check(
        '…recorded as a support request made on the command line',
        ($poCliRecord['verified_via'] ?? '') === 'operator' && (int) ($poCliRecord['performed_by_admin_id'] ?? 0) === $poAdminId
            && ($poCliRecord['summary']['operator']['via'] ?? '') === 'cli',
        json_encode(array_intersect_key($poCliRecord, array_flip(['verified_via', 'performed_by_admin_id', 'summary']))) ?: ''
    );

    // An account closed in the app before erasure existed.
    $poLegacy = $poInsertUser('legacy', 'IT Legacy', date('Y-m-d H:i:s', time() - 86400));
    $poRows['activity_logs'][] = (int) Database::insert('activity_logs', [
        'actor_type'   => 'user',
        'actor_id'     => $poLegacy,
        'action'       => 'account.deleted',
        'subject_type' => 'user',
        'subject_id'   => $poLegacy,
        'description'  => 'Account deletion requested.',
        'created_at'   => date('Y-m-d H:i:s', time() - 86400),
    ]);

    [$poCode, $poOutput] = $poScript('privacy_erase_legacy_deletions.php', ['--dry-run', '--user=' . $poLegacy]);
    Suite::check(
        'the legacy script lists the account on a dry run and changes nothing',
        $poCode === 0 && str_contains($poOutput, '#' . $poLegacy) && str_contains($poOutput, 'Dry run') && !$poIsTombstone($poLegacy),
        $poOutput
    );

    [$poCode, $poOutput] = $poScript('privacy_erase_legacy_deletions.php', ['--user=' . $poLegacy]);
    Suite::check(
        'without --dry-run it erases it, recorded as closed before erasure existed',
        $poCode === 0 && $poIsTombstone($poLegacy) && ($poDeletion($poLegacy)['verified_via'] ?? '') === 'legacy',
        $poOutput
    );

    if ($poIsTombstone($poLegacy)) {
        $poErased[] = $poLegacy;
    }
}

// =================================================================
// Cleanup: only what this suite created. Tombstones stay.
// =================================================================

foreach ($poRows as $poTable => $poIds) {
    foreach (array_unique($poIds) as $poId) {
        Database::execute('DELETE FROM `' . $poTable . '` WHERE `id` = :id', ['id' => $poId]);
    }
}

foreach ($poFiles as $poFile) {
    if (is_file($poFile)) {
        @unlink($poFile);
    }
}

if ($poBrandId > 0) {
    // Archives first, through the job that deletes them, then the rows.
    Database::execute(
        'UPDATE `data_export_requests` SET `expires_at` = NOW() - INTERVAL 1 DAY WHERE `user_id` = :id',
        ['id' => $poBrandId]
    );

    try {
        CronRunner::run('privacy:purge-exports');
    } catch (\Throwable $e) {
        Suite::check('cleanup purged the brand\'s archives', false, $e->getMessage());
    }

    Database::execute('DELETE FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $poBrandId]);
    Database::execute('DELETE FROM `otp_verifications` WHERE `user_id` = :id', ['id' => $poBrandId]);
    Database::execute(
        "DELETE FROM `activity_logs` WHERE `subject_type` = 'user' AND `subject_id` = :id AND `action` LIKE 'privacy.%'",
        ['id' => $poBrandId]
    );

    if (isset($poPhone) && $poPhone !== '') {
        Database::execute(
            'DELETE FROM `sms_queue` WHERE `to_phone` = :phone AND `created_at` >= :since',
            ['phone' => $poPhone, 'since' => $poStarted]
        );
    }
}

Database::execute("DELETE FROM `email_queue` WHERE `to_email` LIKE 'it-po-%@zuste.test'");
Database::execute("DELETE FROM `login_history` WHERE `identifier` LIKE 'it-po-%@zuste.test'");

foreach (array_unique($poErased) as $poId) {
    Database::execute("DELETE FROM `login_history` WHERE `actor_type` = 'user' AND `actor_id` = :id", ['id' => $poId]);
}

$poDropAdmin($poAdminEmail);
@unlink($poJar);

// The erasure ledger outlives the database on purpose; this run's lines go.
$poLedger = AccountDeletionService::ledgerPath();

if ($poErased !== [] && is_file($poLedger)) {
    $poKeep = array_values(array_filter(
        file($poLedger, FILE_IGNORE_NEW_LINES) ?: [],
        static function (string $line) use ($poErased): bool {
            $entry = json_decode($line, true);

            return !is_array($entry) || !in_array((int) ($entry['user_id'] ?? 0), $poErased, true);
        }
    ));

    file_put_contents($poLedger, $poKeep === [] ? '' : implode("\n", $poKeep) . "\n", LOCK_EX);
}

<?php

declare(strict_types=1);

/**
 * Account deletion, in depth: how far the erasure reaches, the money rules
 * (blockers, a given-up balance, closed wallets), the clean-up that is
 * retried after the commit, and the ways in other than the app (support,
 * legacy accounts, a database restore).
 *
 * Runs against the real server. Where a race or a failure has to be staged,
 * the services are called in-process; php -S is single-threaded, so the
 * guards are asserted rather than raced.
 *
 * Conventions (C22): actors are it-pd-…@zuste.test. Users are never
 * hard-deleted: erased accounts stay as anonymous tombstones, exactly as in
 * production. Only rows this suite planted are removed, children before their
 * RESTRICT parents. Settings change only inside try/finally. This suite's
 * lines are stripped from the erasure ledger at the end.
 */

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;
use Zuste\Services\AccountDeletionService;
use Zuste\Services\BookingService;
use Zuste\Services\CampaignService;
use Zuste\Services\PaymentService;
use Zuste\Services\ReferralService;
use Zuste\Services\UploadService;
use Zuste\Services\WalletService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// The deletion part of migration 048 must be in place: everything below
// reads or writes its columns.
$pdMigrated = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND ((TABLE_NAME = 'account_deletion_requests' AND COLUMN_NAME IN ('pending_steps', 'forfeited_amount', 'performed_by_admin_id'))
          OR (TABLE_NAME = 'wallets' AND COLUMN_NAME = 'closed_at'))"
) === 4;

if (!$pdMigrated) {
    Suite::skipGroup('Deletion (048)', 'migration 048 is not applied: account_deletion_requests / wallets.closed_at columns are missing');

    return;
}

// -----------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------

/** Every account this suite erased, for the ledger strip at the end. */
$pdErased = [];

/** Rows this suite planted, as [table, id], removed newest first. */
$pdPlanted = [];

$pdNow = static fn (): string => date('Y-m-d H:i:s');

$pdPlant = static function (string $table, array $row) use (&$pdPlanted): int {
    $id = Database::insert($table, $row);
    $pdPlanted[] = [$table, $id];

    return $id;
};

$pdRow = static fn (int $id): array => Database::selectOne('SELECT * FROM `users` WHERE `id` = :id', ['id' => $id]) ?? [];

$pdTombstoned = static function (int $id): bool {
    $row = Database::selectOne('SELECT `status`, `name`, `email`, `deleted_at` FROM `users` WHERE `id` = :id', ['id' => $id]);

    return $row !== null && $row['status'] === 'deleted' && $row['name'] === 'Deleted user'
        && $row['email'] === null && $row['deleted_at'] !== null;
};

$pdDeletion = static fn (int $userId): array => Database::selectOne(
    'SELECT * FROM `account_deletion_requests` WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 1',
    ['id' => $userId]
) ?? [];

$pdGoodbyes = static fn (string $email): int => (int) Database::scalar(
    "SELECT COUNT(*) FROM `email_queue` WHERE `to_email` = :e AND `subject` = 'Your Zusty account has been deleted'",
    ['e' => $email]
);

$pdBalance = static fn (int $userId): float => (float) (Database::scalar(
    'SELECT `balance` FROM `wallets` WHERE `user_id` = :id',
    ['id' => $userId]
) ?? 0);

$pdDelete = static fn (array $actor, array $extra = []): Res => Http::post(
    '/me/privacy/delete-account',
    ['password' => $actor['password'], 'confirmation' => 'DELETE'] + $extra,
    $actor['name']
);

$pdCodes = static fn (mixed $blockers): array => array_column(is_array($blockers) ? $blockers : [], 'code');

/** A known one-time code, planted where the server looks for it. */
$pdPlantCode = static function (int $userId, string $destination, string $purpose): string {
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

$pdSetting = static fn (string $group, string $key): ?string => ($value = Database::scalar(
    'SELECT `value` FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
    ['g' => $group, 'k' => $key]
)) === null ? null : (string) $value;

$pdPutSetting = static function (string $group, string $key, ?string $value): void {
    Database::execute(
        'UPDATE `settings` SET `value` = :v WHERE `group_key` = :g AND `setting_key` = :k',
        ['v' => $value, 'g' => $group, 'k' => $key]
    );
};

$pdLedgerHas = static function (int $userId): bool {
    $path = AccountDeletionService::ledgerPath();

    if (!is_file($path)) {
        return false;
    }

    foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $entry = json_decode((string) $line, true);

        if (is_array($entry) && (int) ($entry['user_id'] ?? 0) === $userId) {
            return true;
        }
    }

    return false;
};

$pdStripLedger = static function (array $userIds): void {
    $path = AccountDeletionService::ledgerPath();

    if ($userIds === [] || !is_file($path)) {
        return;
    }

    $handle = fopen($path, 'c+');

    if ($handle === false) {
        return;
    }

    try {
        flock($handle, LOCK_EX);
        $kept = [];

        while (($line = fgets($handle)) !== false) {
            $entry = json_decode(trim($line), true);

            if (!is_array($entry) || !in_array((int) ($entry['user_id'] ?? 0), $userIds, true)) {
                $kept[] = rtrim($line, "\r\n");
            }
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $kept === [] ? '' : implode("\n", $kept) . "\n");
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
};

$pdAdminId = (int) (Database::scalar(
    "SELECT `id` FROM `admins` WHERE `deleted_at` IS NULL AND `status` = 'active' ORDER BY `id` LIMIT 1"
) ?? 0);

// =================================================================
Suite::group('Deletion: setup');
// =================================================================

// Every account is registered up front, in batches of four with the
// registration throttle cleared before each batch, so no group has to wipe
// rate limits part-way through. Fixed names stay live and are reused by the
// next run; the "fresh" ones end as erased tombstones.
$pdSpecs = [
    'partner'   => ['partner', 'brand', false],
    'applicant' => ['applicant', 'creator', false],
    'invitee'   => ['invitee', 'creator', false],
    'rich'      => ['rich', 'brand', false],
    'escrow'    => ['escrow', 'brand', false],
    'creator'   => ['creator', 'creator', true],
    'brand'     => ['brand', 'brand', true],
    'forfeit'   => ['forfeit', 'brand', true],
    'topup'     => ['topup', 'brand', true],
    'referrer'  => ['referrer', 'brand', true],
    'referred'  => ['referred', 'brand', true],
    'stale'     => ['stale', 'creator', true],
    'phoneonly' => ['phoneonly', 'brand', true],
    'operator'  => ['operator', 'brand', true],
    'softdel'   => ['softdel', 'brand', true],
    'legacy'    => ['legacy', 'brand', true],
    'slow'      => ['slow', 'brand', true],
    'files'     => ['files', 'brand', true],
];

$pd = [];

foreach (array_chunk($pdSpecs, 4, true) as $pdChunk) {
    Suite::clearRateLimits();

    foreach ($pdChunk as $pdKey => [$pdLabel, $pdType, $pdFresh]) {
        $pdName  = 'pd-' . $pdLabel . ($pdFresh ? '-' . bin2hex(random_bytes(3)) : '');
        $pdActor = Actors::create($pdName, $pdType);
        $pd[$pdKey] = $pdActor === null || (int) $pdActor['id'] <= 0 ? null : $pdActor + ['name' => $pdName];
    }
}

$pdMissing = array_keys(array_filter($pd, static fn (?array $actor): bool => $actor === null));
Suite::check('every actor of this suite is registered', $pdMissing === [], 'missing: ' . implode(', ', $pdMissing) . ' — ' . Actors::lastError());

// =================================================================
Suite::group('Deletion: a creator is erased everywhere they appear');
// =================================================================

$pdC       = $pd['creator'] ?? null;
$pdPartner = $pd['partner'] ?? null;

if ($pdC === null || $pdPartner === null) {
    Suite::skipGroup('Deletion: a creator is erased everywhere they appear', 'registration failed: ' . Actors::lastError());
} else {
    $cUid      = (int) $pdC['id'];
    $cEmail    = (string) $pdC['email'];
    $partnerId = (int) $pdPartner['id'];
    $creatorId = (int) Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :id', ['id' => $cUid]);
    $cTag      = strtoupper(bin2hex(random_bytes(4)));
    $display   = 'Pd Display ' . $cTag;

    Database::update('creators', ['display_name' => $display], ['id' => $creatorId]);

    $cUser  = $pdRow($cUid);
    $cPhone = Str::normalizePhone((string) $cUser['phone_country_code'] . (string) $cUser['phone']);

    // P16: kept financial records.
    $bookingId = $pdPlant('bookings', [
        'code' => 'ITPD' . $cTag, 'brand_user_id' => $partnerId, 'creator_id' => $creatorId, 'creator_user_id' => $cUid,
        'title' => 'IT pd booking', 'amount' => 1000, 'total_amount' => 1180, 'status' => 'completed',
        'payment_status' => 'paid', 'escrow_status' => 'released', 'created_at' => $pdNow(),
    ]);
    $invoiceId = $pdPlant('invoices', [
        'invoice_number' => 'IT/PD/' . $cTag, 'booking_id' => $bookingId, 'user_id' => $cUid, 'type' => 'creator_payout',
        'bill_to_name' => 'IT Person', 'bill_to_email' => $cEmail, 'bill_to_address' => '12 Private Lane',
        'line_items' => '[]', 'subtotal' => 1000, 'total' => 1000,
    ]);
    $withdrawalId = $pdPlant('withdrawal_requests', [
        'reference' => 'ITPDW' . $cTag, 'user_id' => $cUid, 'amount' => 1000, 'net_amount' => 990, 'method' => 'upi',
        'status' => 'completed',
        'snapshot' => json_encode(['method' => 'upi', 'holder' => 'IT Person', 'upi_id' => 'itperson@upi', 'account_last4' => null]),
        'created_at' => $pdNow(),
    ]);
    $paymentId = $pdPlant('payments', [
        'reference' => 'ITPDP' . $cTag, 'user_id' => $cUid, 'purpose' => 'wallet_topup', 'gateway' => 'razorpay',
        'amount' => 100, 'status' => 'captured', 'upi_vpa' => 'itperson@upi',
        'gateway_response' => json_encode([
            'id' => 'pay_ITPD' . $cTag, 'amount' => 10000, 'status' => 'captured', 'method' => 'upi',
            'email' => $cEmail, 'contact' => $cPhone, 'vpa' => 'itperson@upi',
        ]),
        'created_at' => $pdNow(),
    ]);

    // P17: our copies of what was sent to them, at every address.
    $alt = 'it-pd-alt-' . strtolower($cTag) . '@zuste.test';
    $pdPlant('user_contact_points', [
        'user_id' => $cUid, 'channel' => 'email', 'value' => $alt, 'verified_at' => $pdNow(), 'verified_via' => 'it-pd', 'created_at' => $pdNow(),
    ]);
    $pdPlant('email_queue', [
        'to_email' => $cEmail, 'to_name' => 'IT', 'subject' => 'IT pd sent', 'body_html' => '<p>Private</p>',
        'status' => 'sent', 'sent_at' => $pdNow(), 'created_at' => $pdNow(),
    ]);
    $pdPlant('email_queue', [
        'to_email' => $cEmail, 'to_name' => 'IT', 'subject' => 'IT pd pending', 'body_html' => '<p>Private</p>',
        'status' => 'pending', 'created_at' => $pdNow(),
    ]);
    $pdPlant('email_queue', [
        'to_email' => $alt, 'subject' => 'IT pd alternate', 'body_html' => '<p>Private</p>',
        'status' => 'sent', 'sent_at' => $pdNow(), 'created_at' => $pdNow(),
    ]);
    $pdPlant('push_queue', [
        'target_type' => 'user', 'target_value' => (string) $cUid, 'title' => 'IT pd', 'body' => 'Private push',
        'status' => 'sent', 'sent_at' => $pdNow(), 'created_at' => $pdNow(),
    ]);
    $pdPlant('sms_queue', [
        'to_phone' => $cPhone, 'message' => 'IT pd private text', 'status' => 'sent', 'sent_at' => $pdNow(), 'created_at' => $pdNow(),
    ]);

    // P19: the profile's edit history, and a testimonial's.
    $pdPlant('revisions', [
        'entity_type' => 'creator', 'entity_id' => $creatorId, 'version' => 1,
        'snapshot' => json_encode(['address_line1' => '12 Private Lane', 'date_of_birth' => '1999-01-01']),
        'actor_type' => 'user', 'actor_id' => $cUid, 'created_at' => $pdNow(),
    ]);
    $testimonialId = $pdPlant('testimonials', [
        'creator_id' => $creatorId, 'author_name' => 'IT Fan', 'content' => 'Lovely work', 'rating' => 5, 'created_at' => $pdNow(),
    ]);
    $pdPlant('revisions', [
        'entity_type' => 'testimonials', 'entity_id' => $testimonialId, 'version' => 1,
        'snapshot' => json_encode(['author_name' => 'IT Fan']), 'created_at' => $pdNow(),
    ]);
    $pageRevisionId = $pdPlant('revisions', [
        'entity_type' => 'pages', 'entity_id' => 900000000 + random_int(1, 99999999), 'version' => 1,
        'snapshot' => '{}', 'created_at' => $pdNow(),
    ]);

    // P20: sign-in history and an API error log carrying the address.
    $loginId = $pdPlant('login_history', [
        'actor_type' => 'user', 'actor_id' => $cUid, 'identifier' => $cEmail, 'outcome' => 'success',
        'auth_method' => 'password', 'ip_address' => '203.0.113.9', 'created_at' => $pdNow(),
    ]);
    $apiLogId = $pdPlant('api_logs', [
        'method' => 'POST', 'path' => '/api/v1/it-pd', 'status_code' => 422, 'user_id' => $cUid,
        'request_body' => json_encode(['email' => $cEmail]), 'created_at' => $pdNow(),
    ]);

    // P22: the name in someone else's notifications, about a conversation
    // they shared (renamed) and about one they did not (left alone).
    $convA = $pdPlant('conversations', ['uuid' => Crypto::uuid4(), 'type' => 'direct']);
    Database::insert('conversation_participants', ['conversation_id' => $convA, 'user_id' => $cUid, 'role' => 'creator']);
    Database::insert('conversation_participants', ['conversation_id' => $convA, 'user_id' => $partnerId, 'role' => 'brand']);
    $convB = $pdPlant('conversations', ['uuid' => Crypto::uuid4(), 'type' => 'direct']);
    Database::insert('conversation_participants', ['conversation_id' => $convB, 'user_id' => $partnerId, 'role' => 'brand']);

    $quote  = $display . ': Hi, here is my quote';
    $notifA = $pdPlant('notifications', [
        'user_id' => $partnerId, 'type' => 'message.new', 'category' => 'message', 'title' => 'New message', 'body' => $quote,
        'action_type' => 'chat', 'action_value' => (string) $convA, 'created_at' => $pdNow(),
    ]);
    $notifB = $pdPlant('notifications', [
        'user_id' => $partnerId, 'type' => 'message.new', 'category' => 'message', 'title' => 'New message', 'body' => $quote,
        'action_type' => 'chat', 'action_value' => (string) $convB, 'created_at' => $pdNow(),
    ]);

    // NEW-docs-tests-copy-1: a contact-form message they sent.
    $cmId = $pdPlant('contact_messages', [
        'name' => 'IT Creator', 'email' => $cEmail, 'phone' => (string) $cUser['phone'], 'subject' => 'IT pd',
        'message' => 'Please call me', 'ip_address' => '203.0.113.5', 'created_at' => $pdNow(),
    ]);

    // What the screen promises, before the user commits.
    $view     = Http::get('/me/privacy', [], $pdC['name']);
    $retained = (array) $view->at('data.deletion.retained');
    $details  = array_column($retained, 'detail', 'key');
    $days     = max(1, (int) Config::get('backup.retention_days', 14));

    Suite::check(
        'what is kept includes the identity verification record and backups',
        isset($details['verification'], $details['backups']),
        implode(',', array_keys($details))
    );
    Suite::check(
        'invoices are kept "as issued", and nothing claims to be kept "without your name"',
        str_contains((string) ($details['financial'] ?? ''), 'as issued') && !str_contains((string) json_encode($retained), 'without your name'),
        (string) ($details['financial'] ?? '')
    );
    Suite::check('backups name the configured period', str_contains((string) ($details['backups'] ?? ''), $days . ' days'), (string) ($details['backups'] ?? ''));
    Suite::check(
        'exports say downloaded copies stay on the device',
        str_contains((string) (array_column((array) $view->at('data.deletion.erased'), 'detail', 'key')['exports'] ?? ''), 'stay on your device')
    );

    $gone = $pdDelete($pdC, ['reason_code' => 'privacy', 'reason' => 'IT pd erasure']);
    Suite::ok('the creator deletes their account', $gone, 200);
    $pdErased[] = $cUid;

    Suite::check('the account is an erased tombstone', $pdTombstoned($cUid));
    Suite::check(
        'the answer says where the confirmation went, masked (P43)',
        $gone->at('data.notice.channel') === 'email'
            && str_contains((string) $gone->at('data.notice.destination'), '*')
            && !str_contains((string) $gone->at('data.notice.destination'), $cEmail),
        (string) json_encode($gone->at('data.notice'))
    );

    // P16
    $invoice = Database::selectOne('SELECT `bill_to_name`, `bill_to_address`, `bill_to_email` FROM `invoices` WHERE `id` = :id', ['id' => $invoiceId]) ?? [];
    Suite::equals(
        'the payout invoice keeps the name and address as issued, but not the email',
        $invoice,
        ['bill_to_name' => 'IT Person', 'bill_to_address' => '12 Private Lane', 'bill_to_email' => null]
    );

    $snapshot = json_decode((string) Database::scalar('SELECT `snapshot` FROM `withdrawal_requests` WHERE `id` = :id', ['id' => $withdrawalId]), true);
    $upi      = (string) ($snapshot['upi_id'] ?? '');
    Suite::check(
        'the payout snapshot keeps the holder and masks the UPI ID',
        ($snapshot['holder'] ?? '') === 'IT Person' && $upi !== 'itperson@upi' && str_ends_with($upi, '@upi') && str_contains($upi, '*'),
        (string) json_encode($snapshot)
    );

    $payment  = Database::selectOne('SELECT `upi_vpa`, `gateway_response` FROM `payments` WHERE `id` = :id', ['id' => $paymentId]) ?? [];
    $gateway  = json_decode((string) ($payment['gateway_response'] ?? ''), true);
    $gateway  = is_array($gateway) ? $gateway : [];
    Suite::check('the payment loses its UPI ID', array_key_exists('upi_vpa', $payment) && $payment['upi_vpa'] === null, (string) json_encode($payment));
    Suite::check(
        "the gateway payload loses the payer's email, phone and UPI ID, and keeps its id and amount (erasure-2)",
        !array_key_exists('email', $gateway) && !array_key_exists('contact', $gateway) && !array_key_exists('vpa', $gateway)
            && ($gateway['id'] ?? '') === 'pay_ITPD' . $cTag && ($gateway['amount'] ?? null) === 10000,
        (string) json_encode($gateway)
    );

    // P17 + P60
    Suite::equals(
        'only the goodbye is left of the mail to the old address',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM `email_queue` WHERE `to_email` = :e AND `subject` <> 'Your Zusty account has been deleted'",
            ['e' => $cEmail]
        ),
        0
    );
    Suite::equals('…and exactly one goodbye is queued', $pdGoodbyes($cEmail), 1);
    Suite::equals('mail to their other address is gone', (int) Database::scalar('SELECT COUNT(*) FROM `email_queue` WHERE `to_email` = :e', ['e' => $alt]), 0);
    Suite::equals(
        'push messages to them are gone',
        (int) Database::scalar("SELECT COUNT(*) FROM `push_queue` WHERE `target_type` = 'user' AND `target_value` = :id", ['id' => (string) $cUid]),
        0
    );
    Suite::equals('texts to their phone are gone', (int) Database::scalar('SELECT COUNT(*) FROM `sms_queue` WHERE `to_phone` = :p', ['p' => $cPhone]), 0);

    $support = trim(Config::settingString('general', 'support_email', ''));
    $goodbye = Database::selectOne(
        "SELECT `to_name`, `reply_to`, `body_text` FROM `email_queue`
          WHERE `to_email` = :e AND `subject` = 'Your Zusty account has been deleted' ORDER BY `id` DESC LIMIT 1",
        ['e' => $cEmail]
    ) ?? [];
    Suite::check(
        'the goodbye answers to support and never asks to be replied to (P60)',
        array_key_exists('reply_to', $goodbye) && $goodbye['reply_to'] === ($support !== '' ? $support : null)
            && stripos((string) ($goodbye['body_text'] ?? ''), 'replying') === false,
        (string) json_encode($goodbye)
    );
    Suite::check(
        'it names the records kept, which invoices keep as issued',
        str_contains((string) ($goodbye['body_text'] ?? ''), 'your 1 booking and 1 invoice')
            && str_contains((string) ($goodbye['body_text'] ?? ''), 'exactly as they were issued'),
        (string) ($goodbye['body_text'] ?? '')
    );

    // P19
    Suite::equals(
        "the creator profile's edit history is gone",
        (int) Database::scalar("SELECT COUNT(*) FROM `revisions` WHERE `entity_type` = 'creator' AND `entity_id` = :id", ['id' => $creatorId]),
        0
    );
    Suite::equals(
        "…and so is its testimonial's",
        (int) Database::scalar("SELECT COUNT(*) FROM `revisions` WHERE `entity_type` = 'testimonials' AND `entity_id` = :id", ['id' => $testimonialId]),
        0
    );
    Suite::equals('an unrelated page revision is untouched', (int) Database::scalar('SELECT COUNT(*) FROM `revisions` WHERE `id` = :id', ['id' => $pageRevisionId]), 1);

    // P20
    $login = Database::selectOne('SELECT `identifier`, `ip_address` FROM `login_history` WHERE `id` = :id', ['id' => $loginId]) ?? [];
    Suite::check(
        'sign-in history keeps the IP and time but not the address',
        str_starts_with((string) ($login['identifier'] ?? ''), 'deleted-user-') && !str_contains((string) ($login['identifier'] ?? ''), '@')
            && ($login['ip_address'] ?? '') === '203.0.113.9',
        (string) json_encode($login)
    );
    Suite::equals('no sign-in row names the old address', (int) Database::scalar('SELECT COUNT(*) FROM `login_history` WHERE `identifier` = :e', ['e' => $cEmail]), 0);
    Suite::equals('the API error log loses its body', Database::selectOne('SELECT `request_body` FROM `api_logs` WHERE `id` = :id', ['id' => $apiLogId]), ['request_body' => null]);

    // P22
    $bodyA = (string) Database::scalar('SELECT `body` FROM `notifications` WHERE `id` = :id', ['id' => $notifA]);
    $bodyB = (string) Database::scalar('SELECT `body` FROM `notifications` WHERE `id` = :id', ['id' => $notifB]);
    Suite::check('a notification about their conversation loses the name', str_contains($bodyA, 'Deleted') && !str_contains($bodyA, $display), $bodyA);
    Suite::equals('a notification about another conversation is unchanged', $bodyB, $quote);

    // NEW-docs-tests-copy-1
    Suite::equals(
        'their contact-form message keeps what was written, not who wrote it',
        Database::selectOne('SELECT `name`, `email`, `phone`, `ip_address`, `message` FROM `contact_messages` WHERE `id` = :id', ['id' => $cmId]),
        ['name' => 'Deleted user', 'email' => '', 'phone' => null, 'ip_address' => null, 'message' => 'Please call me']
    );

    // The deletion record and the clean-up after it (P21).
    $record = $pdDeletion($cUid);
    Suite::check(
        'the deletion record keeps the reason and how it was confirmed',
        ($record['verified_via'] ?? '') === 'password' && ($record['reason_code'] ?? '') === 'privacy'
            && ($record['reason_text'] ?? '') === 'IT pd erasure' && ($record['account_type'] ?? '') === 'creator',
        (string) json_encode(array_intersect_key($record, array_flip(['verified_via', 'reason_code', 'reason_text', 'account_type'])))
    );
    Suite::check(
        'the clean-up after the commit has finished (P21)',
        $record !== [] && $record['completed_at'] !== null && $record['pending_steps'] === null && $record['firebase_uid_enc'] === null,
        (string) json_encode(array_intersect_key($record, array_flip(['completed_at', 'pending_steps', 'last_error', 'attempts'])))
    );

    // Signed out everywhere, and told why (C9).
    $me = Http::get('/auth/me', [], $pdC['name']);
    Suite::check('the old session is told the account was deleted', $me->status === 401 && $me->code() === 'account_deleted', $me->describe());

    // P25: the ledger, and a restore that brings the account back.
    Suite::check('the erasure ledger records the deletion', $pdLedgerHas($cUid), AccountDeletionService::ledgerPath());

    Database::update('users', [
        'status'     => 'active',
        'deleted_at' => null,
        'name'       => 'Restored',
        'email'      => 'it-pd-restored-' . strtolower($cTag) . '@zuste.test',
    ], ['id' => $cUid]);

    $reapplied = AccountDeletionService::reapplyAfterRestore();
    Suite::check('an account a restore brought back is erased again', $reapplied >= 1 && $pdTombstoned($cUid), 'reapplied: ' . $reapplied);

    $summaries = array_map(
        static fn (array $row): array => (array) json_decode((string) $row['summary'], true),
        Database::select('SELECT `summary` FROM `account_deletion_requests` WHERE `user_id` = :id ORDER BY `id`', ['id' => $cUid])
    );
    Suite::check(
        '…with a second deletion record that says why',
        count($summaries) === 2 && ($summaries[1]['reapplied_after_restore'] ?? false) === true,
        'records: ' . count($summaries)
    );

    // NEW-deletion-flow-1: the record cannot be cascaded away.
    $refusedWith = null;
    Database::beginTransaction();

    try {
        Database::execute('DELETE FROM `users` WHERE `id` = :id', ['id' => $cUid]);
    } catch (\PDOException $e) {
        $refusedWith = (string) ($e->errorInfo[1] ?? $e->getCode());
    } finally {
        // Whatever happened, nothing is kept.
        Database::rollBack();
    }

    Suite::equals('an erased account cannot be hard-deleted (1451)', $refusedWith, '1451');
    Suite::check('…and its deletion record survives', $pdDeletion($cUid) !== []);
    Suite::equals(
        'the deletion record is never cascaded away with its account (fk_adr_user RESTRICT)',
        Database::scalar(
            "SELECT `DELETE_RULE` FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE `CONSTRAINT_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'account_deletion_requests'
                AND `CONSTRAINT_NAME` = 'fk_adr_user'"
        ),
        'RESTRICT'
    );
}

// =================================================================
Suite::group('Deletion: a brand is erased, its campaigns close, its marketing rows go');
// =================================================================

$pdB         = $pd['brand'] ?? null;
$pdApplicant = $pd['applicant'] ?? null;
$pdInvitee   = $pd['invitee'] ?? null;

if ($pdB === null || $pdApplicant === null || $pdInvitee === null) {
    Suite::skipGroup('Deletion: a brand is erased, its campaigns close, its marketing rows go', 'registration failed: ' . Actors::lastError());
} else {
    $bid    = (int) $pdB['id'];
    $bEmail = (string) $pdB['email'];
    $bUser  = $pdRow($bid);
    $bTag   = strtoupper(bin2hex(random_bytes(4)));
    $title  = 'IT pd campaign ' . $bTag;

    $applicantCreator = (int) Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :id', ['id' => (int) $pdApplicant['id']]);
    $inviteeCreator   = (int) Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :id', ['id' => (int) $pdInvitee['id']]);

    // P23: creators who applied or were invited are told it closed.
    $campaignId = $pdPlant('campaigns', [
        'code' => 'ITPD' . $bTag, 'brand_user_id' => $bid, 'title' => $title, 'description' => 'A campaign that closes.',
        'platform' => 'instagram', 'deliverable_type' => 'reel', 'status' => 'open', 'is_public' => 1,
    ]);
    $pdPlant('campaign_applications', [
        'campaign_id' => $campaignId, 'creator_id' => $applicantCreator, 'creator_user_id' => (int) $pdApplicant['id'],
        'status' => 'applied', 'pitch' => 'Pick me',
    ]);
    $pdPlant('campaign_applications', [
        'campaign_id' => $campaignId, 'creator_id' => $inviteeCreator, 'creator_user_id' => (int) $pdInvitee['id'],
        'status' => 'invited',
    ]);

    // P24: their view of another creator.
    $viewId = $pdPlant('creator_profile_views', [
        'creator_id' => $applicantCreator, 'viewer_id' => $bid, 'source' => 'direct', 'viewed_on' => date('Y-m-d'),
    ]);

    // P18: a campaign still sending, and one already sent.
    $mcA = $pdPlant('marketing_campaigns', [
        'name' => 'IT pd A ' . $bTag, 'channel' => 'email', 'subject' => 'IT pd A ' . $bTag, 'content' => '<p>Hello</p>',
        'audience' => 'custom', 'status' => 'sending',
    ]);
    $recipientA = Database::insert('campaign_recipients', [
        'campaign_id' => $mcA, 'user_id' => $bid, 'destination' => $bEmail, 'status' => 'pending', 'tracking_token' => bin2hex(random_bytes(16)),
    ]);
    $mcB = $pdPlant('marketing_campaigns', [
        'name' => 'IT pd B ' . $bTag, 'channel' => 'email', 'subject' => 'IT pd B ' . $bTag, 'content' => '<p>Hello</p>',
        'audience' => 'custom', 'status' => 'sent',
    ]);
    $recipientB = Database::insert('campaign_recipients', [
        'campaign_id' => $mcB, 'user_id' => $bid, 'destination' => $bEmail, 'status' => 'opened',
        'tracking_token' => bin2hex(random_bytes(16)), 'sent_at' => $pdNow(), 'opened_at' => $pdNow(),
    ]);

    // NEW-deletion-erasure-2: a captured payment with the payer's details.
    $bPaymentId = $pdPlant('payments', [
        'reference' => 'ITPDB' . $bTag, 'user_id' => $bid, 'purpose' => 'wallet_topup', 'gateway' => 'razorpay',
        'amount' => 100, 'status' => 'captured',
        'gateway_response' => json_encode(['id' => 'pay_IT', 'amount' => 100, 'email' => $bEmail, 'contact' => '+919999999999', 'vpa' => 'it@upi']),
        'created_at' => $pdNow(),
    ]);

    // NEW-deletion-erasure-3: their contact-form message, and someone else's.
    $cmOwn = $pdPlant('contact_messages', [
        'name' => 'IT Brand', 'email' => $bEmail, 'phone' => '+91 ' . (string) $bUser['phone'], 'subject' => 'IT pd help',
        'message' => 'Please help with my campaign', 'ip_address' => '203.0.113.7', 'created_at' => $pdNow(),
    ]);
    $otherEmail = 'it-pd-other-' . strtolower($bTag) . '@zuste.test';
    $cmOther    = $pdPlant('contact_messages', [
        'name' => 'IT Someone', 'email' => $otherEmail, 'phone' => '+44 7700 900123', 'subject' => 'IT pd other',
        'message' => 'Unrelated', 'ip_address' => '203.0.113.8', 'created_at' => $pdNow(),
    ]);

    $code = $pdPlantCode($bid, $bEmail, 'delete_account');
    $gone = Http::post('/me/privacy/delete-account', ['code' => $code, 'channel' => 'email', 'confirmation' => 'DELETE'], $pdB['name']);
    Suite::ok('the brand deletes with an email code', $gone, 200);
    $pdErased[] = $bid;
    Suite::check('…and is an erased tombstone, recorded as confirmed by email code', $pdTombstoned($bid) && ($pdDeletion($bid)['verified_via'] ?? '') === 'email_code');

    // P23
    foreach (['applicant' => $pdApplicant, 'invitee' => $pdInvitee] as $pdLabel => $pdActor) {
        $note = Database::selectOne(
            "SELECT `action_type`, `body` FROM `notifications`
              WHERE `user_id` = :u AND `type` = 'campaign.cancelled' AND `body` LIKE :t ORDER BY `id` DESC LIMIT 1",
            ['u' => (int) $pdActor['id'], 't' => '%' . $title . '%']
        ) ?? [];
        Suite::check("the {$pdLabel} is told the campaign closed, with no link", ($note['action_type'] ?? '') === 'none', (string) json_encode($note));
    }

    // P24
    Suite::equals(
        'their view of another creator stays counted, unlinked',
        Database::selectOne('SELECT `viewer_id` FROM `creator_profile_views` WHERE `id` = :id', ['id' => $viewId]),
        ['viewer_id' => null]
    );

    // P18
    Suite::equals('the unsent row of the sending campaign is gone', (int) Database::scalar('SELECT COUNT(*) FROM `campaign_recipients` WHERE `id` = :id', ['id' => $recipientA]), 0);

    $rowB = Database::selectOne('SELECT `user_id`, `destination`, `tracking_token` FROM `campaign_recipients` WHERE `id` = :id', ['id' => $recipientB]) ?? [];
    Suite::check(
        'the sent row stays for the totals, anonymised',
        $rowB !== [] && $rowB['user_id'] === null && str_starts_with((string) $rowB['destination'], 'deleted:') && $rowB['tracking_token'] === null,
        (string) json_encode($rowB)
    );

    $dispatch = CampaignService::dispatchBatch($mcA);
    Suite::equals('dispatching the sending campaign sends nothing', $dispatch['sent'] ?? null, 0);
    Suite::equals(
        '…and queues nothing to the old address',
        (int) Database::scalar('SELECT COUNT(*) FROM `email_queue` WHERE `to_email` = :e AND `subject` = :s', ['e' => $bEmail, 's' => 'IT pd A ' . $bTag]),
        0
    );

    // NEW-deletion-erasure-2
    $bGateway = json_decode((string) Database::scalar('SELECT `gateway_response` FROM `payments` WHERE `id` = :id', ['id' => $bPaymentId]), true);
    $bGateway = is_array($bGateway) ? $bGateway : [];
    Suite::check(
        "the gateway payload loses the payer's details and keeps its id and amount",
        !isset($bGateway['email']) && !isset($bGateway['contact']) && !isset($bGateway['vpa'])
            && ($bGateway['id'] ?? '') === 'pay_IT' && ($bGateway['amount'] ?? null) === 100,
        (string) json_encode($bGateway)
    );
    Suite::equals(
        'a scrubbed payload keeps only what is not about the payer',
        PaymentService::scrubGatewayResponse(['id' => 'o1', 'customer_details' => ['customer_email' => 'x@y.z']]),
        ['id' => 'o1']
    );

    // A webhook is stored without the payer's details too, even one that is
    // refused for its signature (no webhook secret is needed for this).
    $hookOrder = 'order_ITPDH' . $bTag;
    $hookBody  = (string) json_encode([
        'event'   => 'payment.captured',
        'payload' => ['payment' => ['entity' => [
            'id' => 'pay_ITPDH' . $bTag, 'order_id' => $hookOrder, 'amount' => 10000, 'currency' => 'INR', 'status' => 'captured',
            'method' => 'upi', 'email' => 'it-pd-hook@zuste.test', 'contact' => '+919800000001', 'vpa' => 'it-pd-hook@upi',
        ]]],
    ]);
    $hook = Http::rawBody('POST', '/webhooks/razorpay', $hookBody, [
        'Content-Type'         => 'application/json',
        'X-Razorpay-Signature' => 'it-pd-not-a-signature',
    ]);
    $storedHook = Database::selectOne(
        "SELECT `id`, `payload` FROM `payment_webhooks` WHERE `gateway` = 'razorpay' AND `payload` LIKE :order ORDER BY `id` DESC LIMIT 1",
        ['order' => '%' . $hookOrder . '%']
    );

    if ($storedHook !== null) {
        $pdPlanted[] = ['payment_webhooks', (int) $storedHook['id']];
    }

    $hookPayload = (string) ($storedHook['payload'] ?? '');
    Suite::check(
        "a refused webhook is kept without the payer's email, phone and UPI ID (erasure-2)",
        $hook->status === 401 && $storedHook !== null && str_contains($hookPayload, 'pay_ITPDH' . $bTag)
            && !str_contains($hookPayload, 'it-pd-hook@') && !str_contains($hookPayload, '+919800000001'),
        $hook->describe() . ' | ' . mb_substr($hookPayload, 0, 200)
    );

    // NEW-deletion-erasure-3
    Suite::equals(
        'their contact-form message loses the name, address, phone and IP',
        Database::selectOne('SELECT `name`, `email`, `phone`, `ip_address`, `message` FROM `contact_messages` WHERE `id` = :id', ['id' => $cmOwn]),
        ['name' => 'Deleted user', 'email' => '', 'phone' => null, 'ip_address' => null, 'message' => 'Please help with my campaign']
    );
    Suite::equals(
        "someone else's message is untouched",
        Database::selectOne('SELECT `name`, `email` FROM `contact_messages` WHERE `id` = :id', ['id' => $cmOther]),
        ['name' => 'IT Someone', 'email' => $otherEmail]
    );
}

// =================================================================
Suite::group('Deletion: accounts staff only hid (campaign backstop, support erasure)');
// =================================================================

$pdS = $pd['softdel'] ?? null;

if ($pdS === null) {
    Suite::skipGroup('Deletion: accounts staff only hid (campaign backstop, support erasure)', 'registration failed: ' . Actors::lastError());
} else {
    $sid    = (int) $pdS['id'];
    $sEmail = (string) $pdS['email'];
    $sTag   = strtoupper(bin2hex(random_bytes(4)));

    // How the admin panel used to "delete" an account: hidden, data kept.
    Database::update('users', ['status' => 'deleted', 'deleted_at' => $pdNow()], ['id' => $sid]);

    $mcC = $pdPlant('marketing_campaigns', [
        'name' => 'IT pd C ' . $sTag, 'channel' => 'email', 'subject' => 'IT pd C ' . $sTag, 'content' => '<p>Hello</p>',
        'audience' => 'custom', 'status' => 'sending',
    ]);
    $recipientC = Database::insert('campaign_recipients', [
        'campaign_id' => $mcC, 'user_id' => $sid, 'destination' => $sEmail, 'status' => 'pending', 'tracking_token' => bin2hex(random_bytes(16)),
    ]);

    CampaignService::dispatchBatch($mcC);
    $rowC = Database::selectOne('SELECT `status`, `error_message` FROM `campaign_recipients` WHERE `id` = :id', ['id' => $recipientC]) ?? [];
    Suite::check(
        'a pending row for an account hidden after the list was built is failed, not sent (P18)',
        ($rowC['status'] ?? '') === 'failed' && str_contains((string) ($rowC['error_message'] ?? ''), 'closed or blocked'),
        (string) json_encode($rowC)
    );
    Suite::equals(
        '…and nothing is queued to it',
        (int) Database::scalar('SELECT COUNT(*) FROM `email_queue` WHERE `to_email` = :e AND `subject` = :s', ['e' => $sEmail, 's' => 'IT pd C ' . $sTag]),
        0
    );

    $selfStatus = null;

    try {
        AccountDeletionService::delete($pdRow($sid), 'password');
    } catch (HttpException $e) {
        $selfStatus = $e->status();
    }

    Suite::equals('the owner cannot finish erasing an account staff hid (404)', $selfStatus, 404);

    if ($pdAdminId === 0) {
        Suite::skipGroup('Deletion: support erases an account staff hid', 'no active admin to act as the operator');
    } else {
        $erasedHidden = AccountDeletionService::delete($pdRow($sid), 'operator', 'other', null, [
            'admin_id' => $pdAdminId, 'platform' => 'admin', 'note' => 'IT pd: hidden by staff earlier', 'via' => 'cli',
        ]);
        $pdErased[] = $sid;

        Suite::check(
            'support erases it (NEW-deletion-erasure-5)',
            $erasedHidden['already_deleted'] === false && $pdTombstoned($sid) && ($pdDeletion($sid)['verified_via'] ?? '') === 'operator'
        );
    }
}

// =================================================================
Suite::group('Deletion: support erases an account on request (operator)');
// =================================================================

$pdO = $pd['operator'] ?? null;

if ($pdO === null || $pdAdminId === 0) {
    Suite::skipGroup('Deletion: support erases an account on request (operator)', $pdO === null ? 'registration failed' : 'no active admin');
} else {
    $oid     = (int) $pdO['id'];
    $summary = AccountDeletionService::delete($pdRow($oid), 'operator', 'other', null, [
        'admin_id' => $pdAdminId, 'platform' => 'admin', 'note' => 'Ticket IT-1', 'via' => 'cli',
    ]);
    $pdErased[] = $oid;

    $record   = $pdDeletion($oid);
    $recorded = (array) json_decode((string) ($record['summary'] ?? ''), true);

    Suite::check('the account is erased', $pdTombstoned($oid));
    Suite::check(
        'the result has the C8 shape',
        is_int($summary['deletion_id'] ?? null) && $summary['already_deleted'] === false && is_array($summary['retained'] ?? null)
            && array_key_exists('notice', $summary) && array_key_exists('forfeited', $summary)
    );
    Suite::check(
        'the record says support did it, and who',
        ($record['verified_via'] ?? '') === 'operator' && ($record['platform'] ?? '') === 'admin'
            && (int) ($record['performed_by_admin_id'] ?? 0) === $pdAdminId,
        (string) json_encode(array_intersect_key($record, array_flip(['verified_via', 'platform', 'performed_by_admin_id'])))
    );
    Suite::check(
        '…with the note and where it was done',
        ($recorded['operator']['note'] ?? '') === 'Ticket IT-1' && ($recorded['operator']['via'] ?? '') === 'cli',
        (string) ($record['summary'] ?? '')
    );
    Suite::equals(
        'the audit row names the admin',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM `activity_logs`
              WHERE `action` = 'account.deleted' AND `subject_id` = :id AND `actor_type` = 'admin' AND `actor_id` = :admin",
            ['id' => $oid, 'admin' => $pdAdminId]
        ),
        1
    );
    Suite::check(
        'the goodbye says our support team did it',
        str_contains(
            (string) Database::scalar(
                "SELECT `body_text` FROM `email_queue` WHERE `to_email` = :e AND `subject` = 'Your Zusty account has been deleted' ORDER BY `id` DESC LIMIT 1",
                ['e' => (string) $pdO['email']]
            ),
            'Our support team deleted your account at your request.'
        )
    );
}

// =================================================================
Suite::group('Deletion: a balance that cannot be paid out can be given up');
// =================================================================

$pdF    = $pd['forfeit'] ?? null;
$pdRich = $pd['rich'] ?? null;

if ($pdF === null || $pdRich === null) {
    Suite::skipGroup('Deletion: a balance that cannot be paid out can be given up', 'registration failed: ' . Actors::lastError());
} else {
    $fid = (int) $pdF['id'];

    Database::execute(
        "INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `locked_balance`) VALUES (:id, 'INR', 250, 0)
         ON DUPLICATE KEY UPDATE `balance` = 250, `locked_balance` = 0",
        ['id' => $fid]
    );

    $view = Http::get('/me/privacy', [], $pdF['name']);
    Suite::equals('₹250 below the minimum withdrawal does not block the deletion', $view->at('data.deletion.can_delete'), true);
    Suite::check(
        '…it can be given up instead, and support is offered',
        Money::equals((float) $view->at('data.deletion.forfeit.amount'), 250.0)
            && $view->at('data.deletion.forfeit.reason') === 'below_minimum'
            && str_contains((string) $view->at('data.deletion.forfeit.message'), 'contact support'),
        (string) json_encode($view->at('data.deletion.forfeit'))
    );
    Suite::check(
        '…with no wallet_balance blocker',
        !in_array('wallet_balance', $pdCodes($view->at('data.deletion.blockers')), true),
        (string) json_encode($view->at('data.deletion.blockers'))
    );

    $noAck = $pdDelete($pdF);
    Suite::status('deleting without agreeing to give it up is refused (409)', $noAck, 409);
    Suite::check('…as forfeit_required, naming the wallet', $noAck->code() === 'forfeit_required' && $noAck->at('errors.wallet_balance') !== null, $noAck->describe());
    Suite::equals('…and nothing was erased', Database::scalar('SELECT `email` FROM `users` WHERE `id` = :id', ['id' => $fid]), $pdF['email']);

    $wrongAmount = $pdDelete($pdF, ['forfeit_balance' => 100]);
    Suite::check('agreeing to a different amount is refused too', $wrongAmount->status === 409 && $wrongAmount->code() === 'forfeit_required', $wrongAmount->describe());

    $agreed = $pdDelete($pdF, ['forfeit_balance' => 250]);
    Suite::ok('agreeing to give up exactly ₹250 deletes the account', $agreed, 200);
    $pdErased[] = $fid;

    $record = $pdDeletion($fid);
    $debit  = Database::selectOne(
        'SELECT `direction`, `category`, `amount`, `performed_by_type` FROM `wallet_transactions` WHERE `user_id` = :id AND `idempotency_key` = :key',
        ['id' => $fid, 'key' => 'deletion-forfeit:' . (int) ($record['id'] ?? 0)]
    ) ?? [];
    $wallet = Database::selectOne('SELECT `balance`, `closed_at` FROM `wallets` WHERE `user_id` = :id', ['id' => $fid]) ?? [];

    Suite::check(
        'the balance leaves through one ledger debit',
        ($debit['direction'] ?? '') === 'debit' && ($debit['category'] ?? '') === 'adjustment'
            && Money::equals((float) ($debit['amount'] ?? 0), 250.0) && ($debit['performed_by_type'] ?? '') === 'user'
            && Money::equals((float) ($wallet['balance'] ?? -1), 0.0),
        (string) json_encode(['debit' => $debit, 'wallet' => $wallet])
    );
    Suite::check(
        'the deletion record says what was given up',
        Money::equals((float) ($record['forfeited_amount'] ?? 0), 250.0) && ($record['forfeited_currency'] ?? '') === 'INR',
        (string) json_encode(array_intersect_key($record, array_flip(['forfeited_amount', 'forfeited_currency'])))
    );
    Suite::check('the wallet is closed', ($wallet['closed_at'] ?? null) !== null);
    Suite::check(
        'the goodbye says what was given up',
        str_contains(
            (string) Database::scalar(
                "SELECT `body_text` FROM `email_queue` WHERE `to_email` = :e AND `subject` = 'Your Zusty account has been deleted' ORDER BY `id` DESC LIMIT 1",
                ['e' => (string) $pdF['email']]
            ),
            'You gave up ₹250.00'
        )
    );

    // A balance too large to give up stays a blocker, with a way out.
    $rid = (int) $pdRich['id'];

    Database::execute(
        "INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `locked_balance`) VALUES (:id, 'INR', 5000, 0)
         ON DUPLICATE KEY UPDATE `balance` = 5000, `locked_balance` = 0",
        ['id' => $rid]
    );
    Database::update('users', ['kyc_status' => 'none'], ['id' => $rid]);

    try {
        $richView = Http::get('/me/privacy', [], $pdRich['name']);
        $blocker  = null;

        foreach ((array) $richView->at('data.deletion.blockers') as $candidate) {
            if (($candidate['code'] ?? '') === 'wallet_balance') {
                $blocker = $candidate;
            }
        }

        Suite::check(
            '₹5,000 without KYC cannot be given up: the blocker points to KYC or support',
            $blocker !== null && in_array($blocker['action'] ?? '', ['kyc', 'support'], true)
                && str_contains((string) ($blocker['message'] ?? ''), 'support') && $richView->at('data.deletion.forfeit') === null,
            (string) json_encode($richView->at('data.deletion'))
        );

        $richDelete = $pdDelete($pdRich);
        Suite::check(
            '…and the deletion is refused as deletion_blocked',
            $richDelete->status === 409 && $richDelete->code() === 'deletion_blocked' && $richDelete->at('errors.wallet_balance') !== null,
            $richDelete->describe()
        );
    } finally {
        Database::execute('UPDATE `wallets` SET `balance` = 0, `locked_balance` = 0 WHERE `user_id` = :id', ['id' => $rid]);
    }
}

// =================================================================
Suite::group('Deletion: no money lands in a deleted account');
// =================================================================

$pdT  = $pd['topup'] ?? null;
$pdA  = $pd['referrer'] ?? null;
$pdBr = $pd['referred'] ?? null;

if ($pdT === null || $pdA === null || $pdBr === null || $pdPartner === null) {
    Suite::skipGroup('Deletion: no money lands in a deleted account', 'registration failed: ' . Actors::lastError());
} else {
    $tid  = (int) $pdT['id'];
    $tTag = strtoupper(bin2hex(random_bytes(4)));

    $topupId = $pdPlant('payments', [
        'reference' => 'ITPDT' . $tTag, 'user_id' => $tid, 'purpose' => 'wallet_topup', 'gateway' => 'razorpay',
        'gateway_order_id' => 'order_ITPD' . $tTag, 'amount' => 500, 'status' => 'created', 'created_at' => $pdNow(),
    ]);
    $voidId = $pdPlant('referrals', [
        'referrer_user_id' => (int) $pdPartner['id'], 'referred_user_id' => $tid, 'code' => 'ITPD',
        'referrer_reward' => 200, 'referred_reward' => 200, 'status' => 'pending', 'created_at' => $pdNow(),
    ]);

    $view = Http::get('/me/privacy', [], $pdT['name']);
    Suite::check(
        'a top-up still being confirmed blocks the deletion',
        in_array('pending_topup', $pdCodes($view->at('data.deletion.blockers')), true),
        (string) json_encode($view->at('data.deletion.blockers'))
    );

    $refused = $pdDelete($pdT);
    Suite::check(
        '…with a 409',
        $refused->status === 409 && $refused->code() === 'deletion_blocked' && $refused->at('errors.pending_topup') !== null,
        $refused->describe()
    );

    Database::execute('UPDATE `payments` SET `created_at` = NOW() - INTERVAL 2 HOUR WHERE `id` = :id', ['id' => $topupId]);

    $gone = $pdDelete($pdT);
    Suite::ok('an order abandoned hours ago does not block it', $gone, 200);
    $pdErased[] = $tid;

    Suite::check(
        'the wallet is closed',
        Database::scalar('SELECT `closed_at` FROM `wallets` WHERE `user_id` = :id', ['id' => $tid]) !== null
    );
    Suite::equals('a pending referral of the deleted account is void', Database::scalar('SELECT `status` FROM `referrals` WHERE `id` = :id', ['id' => $voidId]), 'void');

    // Only admin postings reach a closed wallet.
    $before     = $pdBalance($tid);
    $closedCode = null;

    try {
        WalletService::credit($tid, 100, ['category' => 'adjustment', 'description' => 'IT pd probe']);
    } catch (HttpException $e) {
        $closedCode = $e->errorCode();
    }

    Suite::equals('a credit to the closed wallet is refused as wallet_closed', $closedCode, 'wallet_closed');
    Suite::check('…leaving the balance as it was', Money::equals($pdBalance($tid), $before));

    $adminPosting = WalletService::credit($tid, 100, [
        'category' => 'adjustment', 'description' => 'IT pd settlement probe', 'performed_by_type' => 'admin',
        'performed_by_id' => $pdAdminId > 0 ? $pdAdminId : null,
    ]);
    Suite::check('an admin posting still goes through', (int) ($adminPosting['id'] ?? 0) > 0 && Money::equals($pdBalance($tid), Money::add($before, 100.0)));
    WalletService::debit($tid, 100, [
        'category' => 'adjustment', 'description' => 'IT pd settlement probe, reversed', 'performed_by_type' => 'admin',
        'performed_by_id' => $pdAdminId > 0 ? $pdAdminId : null,
    ]);

    // A late capture of the abandoned order goes back to its source.
    $webhookSecret = Config::settingString('payment', 'razorpay_webhook_secret') ?: (string) Config::get('payment.razorpay.webhook_secret', '');

    if ($webhookSecret === '') {
        Suite::skipGroup('Deletion: a late top-up capture is refunded', 'payment.razorpay_webhook_secret is not configured');
    } else {
        $event = (string) json_encode([
            'event'   => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_ITPD' . $tTag, 'order_id' => 'order_ITPD' . $tTag, 'amount' => 50000, 'currency' => 'INR',
                'status' => 'captured', 'method' => 'upi', 'email' => 'it-pd-payer@zuste.test', 'contact' => '+919999999999', 'vpa' => 'payer@upi',
            ]]],
            'created_at' => time(),
        ]);

        Http::rawBody('POST', '/webhooks/razorpay', $event, [
            'Content-Type'         => 'application/json',
            'X-Razorpay-Signature' => hash_hmac('sha256', $event, $webhookSecret),
        ]);

        foreach (Database::column(
            "SELECT `id` FROM `payment_webhooks` WHERE `gateway` = 'razorpay' AND `payload` LIKE :order",
            ['order' => '%order_ITPD' . $tTag . '%']
        ) as $pdHookId) {
            $pdPlanted[] = ['payment_webhooks', (int) $pdHookId];
        }

        Suite::equals('the late capture is recorded', Database::scalar('SELECT `status` FROM `payments` WHERE `id` = :id', ['id' => $topupId]), 'captured');
        Suite::check('…but never credited to the deleted account', Money::equals($pdBalance($tid), $before));
        Suite::equals(
            '…and is refunded to its source',
            (int) Database::scalar("SELECT COUNT(*) FROM `refunds` WHERE `payment_id` = :id AND `destination` = 'source'", ['id' => $topupId]),
            1
        );
    }

    // Referrals: the deleted referrer gets nothing, their friend still does.
    $aid  = (int) $pdA['id'];
    $brid = (int) $pdBr['id'];

    $refId = $pdPlant('referrals', [
        'referrer_user_id' => $aid, 'referred_user_id' => $brid, 'code' => 'ITPD',
        'referrer_reward' => 200, 'referred_reward' => 200, 'status' => 'pending', 'created_at' => $pdNow(),
    ]);

    AccountDeletionService::delete($pdRow($aid), 'password');
    $pdErased[] = $aid;

    Suite::check(
        "the deleted referrer's pending reward is cancelled",
        Money::equals((float) Database::scalar('SELECT `referrer_reward` FROM `referrals` WHERE `id` = :id', ['id' => $refId]), 0.0)
    );

    if (!Config::settingBool('referral', 'enabled', true)) {
        Suite::skipGroup('Deletion: a referral after the referrer left', 'referrals are switched off');
    } else {
        $referrerBefore = $pdBalance($aid);
        ReferralService::qualifyForUser($brid);

        Suite::check(
            'the friend they invited is still rewarded',
            Database::scalar('SELECT `status` FROM `referrals` WHERE `id` = :id', ['id' => $refId]) === 'rewarded'
                && Money::equals($pdBalance($brid), 200.0),
            'balance ' . $pdBalance($brid)
        );
        Suite::check("…and nothing reaches the deleted referrer's wallet", Money::equals($pdBalance($aid), $referrerBefore));

        // The friend can give that reward up to leave too (in-process forfeit).
        $friendGone = AccountDeletionService::delete($pdRow($brid), 'password', null, null, ['forfeit_balance' => 200.0]);
        $pdErased[] = $brid;

        Suite::check(
            '…and can give the ₹200 up to delete their own account',
            $pdTombstoned($brid) && Money::equals((float) ($friendGone['forfeited']['amount'] ?? 0), 200.0) && Money::equals($pdBalance($brid), 0.0)
        );
    }
}

// =================================================================
Suite::group('Deletion: a stale or repeated request erases once');
// =================================================================

$pdSt = $pd['stale'] ?? null;

if ($pdSt === null || $pdPartner === null) {
    Suite::skipGroup('Deletion: a stale or repeated request erases once', 'registration failed: ' . Actors::lastError());
} else {
    $stid         = (int) $pdSt['id'];
    $stEmail      = (string) $pdSt['email'];
    $stCreatorId  = (int) Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :id', ['id' => $stid]);
    $staleRow     = $pdRow($stid);

    // Both requests start from the same snapshot, as two racing ones would.
    $first  = AccountDeletionService::delete($staleRow, 'password');
    $second = AccountDeletionService::delete($staleRow, 'password');
    $pdErased[] = $stid;

    Suite::check('the first request erases the account', $first['already_deleted'] === false && $pdTombstoned($stid));
    Suite::check('the second finds it already deleted', $second['already_deleted'] === true && $second['deletion_id'] === $first['deletion_id']);
    Suite::equals('…one deletion record', (int) Database::scalar('SELECT COUNT(*) FROM `account_deletion_requests` WHERE `user_id` = :id', ['id' => $stid]), 1);
    Suite::equals(
        '…one audit row',
        (int) Database::scalar("SELECT COUNT(*) FROM `activity_logs` WHERE `action` = 'account.deleted' AND `subject_id` = :id", ['id' => $stid]),
        1
    );
    Suite::equals('…and one goodbye', $pdGoodbyes($stEmail), 1);

    $bookingStatus = null;

    try {
        BookingService::create((int) $pdPartner['id'], ['creator_id' => $stCreatorId, 'amount' => 1000, 'title' => 'IT pd booking']);
    } catch (HttpException $e) {
        $bookingStatus = $e->status();
    }

    Suite::equals('a booking for the erased creator is refused (404)', $bookingStatus, 404);

    // P21: a clean-up step left pending is finished by the job.
    $stRecord = $pdDeletion($stid);
    $kycId    = $pdPlant('kyc_profiles', [
        'user_id' => $stid, 'kind' => 'creator', 'reference' => 'ITPD' . strtoupper(bin2hex(random_bytes(6))),
        'status' => 'draft', 'created_at' => $pdNow(),
    ]);

    Database::execute(
        "UPDATE `account_deletion_requests`
            SET `pending_steps` = '[\"kyc\"]', `completed_at` = NULL, `next_attempt_at` = NOW() - INTERVAL 30 DAY
          WHERE `id` = :id",
        ['id' => (int) ($stRecord['id'] ?? 0)]
    );

    $run   = AccountDeletionService::finishPending(25);
    $after = Database::selectOne('SELECT `completed_at`, `pending_steps` FROM `account_deletion_requests` WHERE `id` = :id', ['id' => (int) ($stRecord['id'] ?? 0)]) ?? [];

    Suite::check(
        'a KYC clean-up left pending is finished by privacy:finish-deletions',
        $after !== [] && $after['completed_at'] !== null && $after['pending_steps'] === null
            && Database::scalar('SELECT `purged_at` FROM `kyc_profiles` WHERE `id` = :id', ['id' => $kycId]) !== null,
        (string) json_encode(['run' => $run, 'row' => $after])
    );
}

// =================================================================
Suite::group('Deletion: an account with only a phone number');
// =================================================================

$pdP        = $pd['phoneonly'] ?? null;
$pdSmsPhone = null;

if ($pdP === null) {
    Suite::skipGroup('Deletion: an account with only a phone number', 'registration failed: ' . Actors::lastError());
} else {
    $pid        = (int) $pdP['id'];
    $pUser      = $pdRow($pid);
    $pdSmsPhone = Str::normalizePhone((string) $pUser['phone_country_code'] . (string) $pUser['phone']);

    Database::update('users', ['email' => null], ['id' => $pid]);

    $summary = AccountDeletionService::delete($pdRow($pid), 'password');
    $pdErased[] = $pid;

    $smsOn = Config::settingBool('sms', 'enabled', false) || (bool) Config::get('sms.enabled', false);

    Suite::check(
        'the goodbye goes by text when SMS is on, and nowhere otherwise (P43)',
        $smsOn ? (($summary['notice']['channel'] ?? '') === 'sms') : $summary['notice'] === null,
        (string) json_encode($summary['notice'])
    );
    Suite::equals('…never to an address the account no longer has', $pdGoodbyes((string) $pdP['email']), 0);
}

// =================================================================
Suite::group('Deletion: the goodbye email');
// =================================================================

$pdSupport = trim(Config::settingString('general', 'support_email', ''));
$pdMessage = AccountDeletionService::goodbyeMessage(['bookings' => 2, 'invoices' => 1], null, 'password');

Suite::equals('it has the deletion subject', $pdMessage['subject'], 'Your Zusty account has been deleted');
Suite::equals('its Reply-To is the support address (P60)', $pdMessage['reply_to'], $pdSupport !== '' ? $pdSupport : null);
Suite::check(
    'the body gives the support address, or the Contact page',
    $pdSupport !== '' ? str_contains($pdMessage['html'], $pdSupport) : str_contains($pdMessage['text'], 'Contact page')
);
Suite::check('it never asks to be replied to', stripos($pdMessage['html'] . $pdMessage['text'], 'replying') === false);
Suite::check(
    'it names the records kept, and that invoices stay as issued',
    str_contains($pdMessage['text'], 'your 2 bookings and 1 invoice') && str_contains($pdMessage['text'], 'exactly as they were issued'),
    $pdMessage['text']
);

$pdOperatorMessage = AccountDeletionService::goodbyeMessage([], 200.0, 'operator', 'INR');
Suite::check(
    'a given-up balance and an erasure by support are both said',
    str_contains($pdOperatorMessage['text'], 'You gave up ₹200.00') && str_contains($pdOperatorMessage['text'], 'Our support team deleted your account at your request.'),
    $pdOperatorMessage['text']
);

// =================================================================
Suite::group('Deletion: money already on its way out is not called escrow');
// =================================================================

$pdE = $pd['escrow'] ?? null;

if ($pdE === null) {
    Suite::skipGroup('Deletion: money already on its way out is not called escrow', 'registration failed: ' . Actors::lastError());
} else {
    $eid  = (int) $pdE['id'];
    $eTag = strtoupper(bin2hex(random_bytes(4)));

    try {
        Database::execute(
            "INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `locked_balance`) VALUES (:id, 'INR', 0, 1500)
             ON DUPLICATE KEY UPDATE `balance` = 0, `locked_balance` = 1500",
            ['id' => $eid]
        );
        $pdPlant('withdrawal_requests', [
            'reference' => 'ITPDE' . $eTag, 'user_id' => $eid, 'amount' => 1500, 'net_amount' => 1490, 'method' => 'upi',
            'status' => 'pending', 'snapshot' => json_encode(['method' => 'upi']), 'created_at' => $pdNow(),
        ]);

        $flight = AccountDeletionService::preflight($pdRow($eid));
        Suite::equals('a withdrawal being paid out is the only blocker', $pdCodes($flight['blockers']), ['pending_withdrawals']);

        Database::execute('UPDATE `wallets` SET `locked_balance` = 2000 WHERE `user_id` = :id', ['id' => $eid]);

        $escrow  = AccountDeletionService::preflight($pdRow($eid));
        $blocker = null;

        foreach ($escrow['blockers'] as $candidate) {
            if ($candidate['code'] === 'wallet_balance') {
                $blocker = $candidate;
            }
        }

        Suite::check(
            'only the money still in escrow is named (₹500.00, not ₹2,000.00)',
            $blocker !== null && str_contains((string) $blocker['message'], '₹500.00') && !str_contains((string) $blocker['message'], '₹2,000.00'),
            (string) json_encode($blocker)
        );
    } finally {
        Database::execute('UPDATE `wallets` SET `balance` = 0, `locked_balance` = 0 WHERE `user_id` = :id', ['id' => $eid]);
    }
}

// =================================================================
Suite::group('Deletion: a slow mail server or Firebase never holds up the answer');
// =================================================================

$pdSl             = $pd['slow'] ?? null;
$pdSlowDeletionId = 0;

if ($pdSl === null) {
    Suite::skipGroup('Deletion: a slow mail server or Firebase never holds up the answer', 'registration failed: ' . Actors::lastError());
} else {
    $slid    = (int) $pdSl['id'];
    $fakeUid = 'it-pd-fake-' . bin2hex(random_bytes(8));

    Database::update('users', ['firebase_uid' => $fakeUid], ['id' => $slid]);

    // Unreadable service-account credentials make the Firebase step fail at
    // once, before any call to Google with a made-up uid. Blanking the
    // setting is not enough: FirebaseService then falls back to the
    // credentials file named in .env, which would really be used.
    $mailHost       = $pdSetting('mail', 'host');
    $serviceAccount = $pdSetting('firebase', 'service_account_json');
    $saEncrypted    = (int) (Database::scalar(
        "SELECT `is_encrypted` FROM `settings` WHERE `group_key` = 'firebase' AND `setting_key` = 'service_account_json'"
    ) ?? 0) === 1;
    $canStage       = $serviceAccount !== null || trim((string) Config::get('firebase.credentials', '')) === '';

    try {
        // An unroutable mail host would hang any SMTP attempt made while the
        // user waits.
        $pdPutSetting('mail', 'host', '10.255.255.1');
        $pdPutSetting('firebase', 'service_account_json', $saEncrypted ? Crypto::encrypt('{}') : '{}');

        $slow = $pdDelete($pdSl);
    } finally {
        $pdPutSetting('mail', 'host', $mailHost);
        $pdPutSetting('firebase', 'service_account_json', $serviceAccount);
    }

    Suite::ok('the deletion answers', $slow, 200);
    $pdErased[] = $slid;

    Suite::check('…within 5 seconds', $slow->ms < 5000, round($slow->ms) . ' ms');
    Suite::equals('the goodbye is queued, not sent while the user waits', $pdGoodbyes((string) $pdSl['email']), 1);

    $record           = $pdDeletion($slid);
    $steps            = json_decode((string) ($record['pending_steps'] ?? ''), true);
    $pdSlowDeletionId = (int) ($record['id'] ?? 0);

    if (!$canStage) {
        Suite::skipGroup(
            'Deletion: the Firebase step is retried',
            'no firebase.service_account_json setting to override while .env names a credentials file'
        );
    } else {
        Suite::check(
            'the Firebase step is left for the retry job, its uid kept encrypted',
            $record !== [] && $record['completed_at'] === null && is_array($steps) && in_array('firebase', $steps, true)
                && !empty($record['firebase_uid_enc']) && !str_contains((string) $record['firebase_uid_enc'], $fakeUid),
            (string) json_encode(array_intersect_key($record, array_flip(['pending_steps', 'completed_at', 'attempts', 'last_error'])))
        );
    }
}

// =================================================================
Suite::group('Deletion: files that cannot be deleted yet are retried');
// =================================================================

$pdFi       = $pd['files'] ?? null;
$uploadRoot = realpath(rtrim((string) Config::get('storage.upload_path'), '/\\'));

if ($pdFi === null || $uploadRoot === false) {
    Suite::skipGroup('Deletion: files that cannot be deleted yet are retried', $pdFi === null ? 'registration failed' : 'no uploads folder');
} else {
    $fiid    = (int) $pdFi['id'];
    $dirName = 'it-pd-' . bin2hex(random_bytes(4));
    $dir     = $uploadRoot . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $dirName;
    $windows = PHP_OS_FAMILY === 'Windows';

    @mkdir($dir, 0755, true);

    // Named apart from run.php's own $files / $file: suites share its scope.
    $pdFiles = ['locked' => $dir . DIRECTORY_SEPARATOR . 'locked.jpg', 'probe' => $dir . DIRECTORY_SEPARATOR . 'probe.jpg'];
    $media   = [];

    foreach ($pdFiles as $key => $pdFile) {
        file_put_contents($pdFile, 'it-pd');
        $media[$key] = $pdPlant('media', [
            'folder' => 'avatars', 'file_name' => basename($pdFile), 'original_name' => basename($pdFile),
            'path' => 'avatars/' . $dirName . '/' . basename($pdFile),
            'url' => rtrim((string) Config::get('storage.upload_url'), '/') . '/avatars/' . $dirName . '/' . basename($pdFile),
            'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'uploader_type' => 'user', 'uploader_id' => $fiid,
            'created_at' => $pdNow(),
        ]);
    }

    // Read-only files on Windows; a read-only folder elsewhere.
    $lock = static function (bool $locked) use ($windows, $dir, $pdFiles): void {
        if ($windows) {
            foreach ($pdFiles as $pdFile) {
                if (is_file($pdFile)) {
                    @chmod($pdFile, $locked ? 0444 : 0644);
                }
            }
        } else {
            @chmod($dir, $locked ? 0555 : 0755);
        }

        clearstatcache();
    };

    try {
        $lock(true);

        if ($windows ? is_writable($pdFiles['locked']) : is_writable($dir)) {
            Suite::skipGroup('Deletion: files that cannot be deleted yet are retried', 'cannot make a file undeletable here (running as an administrator?)');
        } else {
            // In-process: the row stays while its file does.
            Suite::equals('purgeFile() reports a file it could not delete', UploadService::purgeFile($media['probe']), false);
            Suite::check(
                '…and keeps its row and file',
                (int) Database::scalar('SELECT COUNT(*) FROM `media` WHERE `id` = :id', ['id' => $media['probe']]) === 1 && is_file($pdFiles['probe'])
            );

            $gone = $pdDelete($pdFi);
            Suite::ok('the account is still deleted', $gone, 200);
            $pdErased[] = $fiid;

            $record = $pdDeletion($fiid);
            $steps  = json_decode((string) ($record['pending_steps'] ?? ''), true);

            Suite::check(
                '…with the files left pending and the failure recorded',
                $pdTombstoned($fiid) && $record !== [] && $record['completed_at'] === null && is_array($steps)
                    && in_array('files', $steps, true) && trim((string) $record['last_error']) !== ''
                    && (int) Database::scalar('SELECT COUNT(*) FROM `media` WHERE `id` = :id', ['id' => $media['locked']]) === 1,
                (string) json_encode(array_intersect_key($record, array_flip(['pending_steps', 'completed_at', 'attempts', 'last_error'])))
            );

            $lock(false);

            Suite::equals('once the file can be deleted, purgeFile() removes it', UploadService::purgeFile($media['probe']), true);
            clearstatcache();
            Suite::check(
                '…file and row',
                !is_file($pdFiles['probe']) && (int) Database::scalar('SELECT COUNT(*) FROM `media` WHERE `id` = :id', ['id' => $media['probe']]) === 0
            );

            Database::execute(
                'UPDATE `account_deletion_requests` SET `next_attempt_at` = NOW() - INTERVAL 30 DAY WHERE `id` = :id',
                ['id' => (int) ($record['id'] ?? 0)]
            );

            $run   = AccountDeletionService::finishPending(25);
            $after = Database::selectOne('SELECT `completed_at`, `pending_steps`, `pending_files` FROM `account_deletion_requests` WHERE `id` = :id', ['id' => (int) ($record['id'] ?? 0)]) ?? [];
            clearstatcache();

            Suite::check(
                'the retry job then finishes the clean-up',
                $after !== [] && $after['completed_at'] !== null && $after['pending_files'] === null && !is_file($pdFiles['locked'])
                    && (int) Database::scalar('SELECT COUNT(*) FROM `media` WHERE `id` = :id', ['id' => $media['locked']]) === 0,
                (string) json_encode(['run' => $run, 'row' => $after])
            );
        }
    } finally {
        $lock(false);

        foreach ($pdFiles as $pdFile) {
            if (is_file($pdFile)) {
                @unlink($pdFile);
            }
        }

        @rmdir($dir);
    }
}

// =================================================================
Suite::group('Deletion: an account closed before erasure existed (legacy)');
// =================================================================

$pdL = $pd['legacy'] ?? null;

if ($pdL === null) {
    Suite::skipGroup('Deletion: an account closed before erasure existed (legacy)', 'registration failed: ' . Actors::lastError());
} else {
    $lid = (int) $pdL['id'];

    // What the pre-043 self-service path did: hide the row, log the request.
    Database::update('users', [
        'status' => 'deleted', 'deleted_at' => date('Y-m-d H:i:s', time() - 86400), 'deletion_requested_at' => date('Y-m-d H:i:s', time() - 86400),
    ], ['id' => $lid]);
    Database::insert('activity_logs', [
        'actor_type' => 'user', 'actor_id' => $lid, 'actor_name' => 'IT Legacy', 'action' => 'account.deleted',
        'subject_type' => 'user', 'subject_id' => $lid, 'description' => 'Account deletion requested.', 'created_at' => $pdNow(),
    ]);

    $legacy = AccountDeletionService::eraseLegacyDeletion($lid);
    $pdErased[] = $lid;

    Suite::check('it becomes an erased tombstone', $legacy['already_deleted'] === false && $pdTombstoned($lid));
    Suite::equals('…recorded as a legacy erasure', $pdDeletion($lid)['verified_via'] ?? null, 'legacy');
    Suite::equals('…with no goodbye', $pdGoodbyes((string) $pdL['email']), 0);
    Suite::check('…and a line in the erasure ledger', $pdLedgerHas($lid));
}

// =================================================================
// Cleanup
// =================================================================

// Planted rows, newest first: children go before their RESTRICT parents.
foreach (array_reverse($pdPlanted) as [$pdTable, $pdId]) {
    try {
        Database::execute("DELETE FROM `{$pdTable}` WHERE `id` = :id", ['id' => $pdId]);
    } catch (\Throwable $e) {
        Suite::check("cleanup removed {$pdTable} #{$pdId}", false, $e->getMessage());
    }
}

// What the erasures queued to this suite's addresses, and the applicants'
// cancellation notices.
Database::execute("DELETE FROM `email_queue` WHERE `to_email` LIKE 'it-pd-%@zuste.test'");

foreach (['applicant', 'invitee'] as $pdKey) {
    if (($pd[$pdKey] ?? null) !== null) {
        Database::execute(
            "DELETE FROM `notifications` WHERE `user_id` = :id AND `type` = 'campaign.cancelled' AND `body` LIKE '%IT pd campaign%'",
            ['id' => (int) $pd[$pdKey]['id']]
        );
    }
}

if ($pdSmsPhone !== null && $pdSmsPhone !== '') {
    Database::execute('DELETE FROM `sms_queue` WHERE `to_phone` = :p', ['p' => $pdSmsPhone]);
}

// The staged Firebase step carries a made-up uid: close it here, so the
// retry job never asks Google about it. The deletion record itself stays.
if ($pdSlowDeletionId > 0) {
    Database::execute(
        'UPDATE `account_deletion_requests`
            SET `completed_at` = COALESCE(`completed_at`, NOW()), `pending_steps` = NULL, `pending_files` = NULL,
                `firebase_uid_enc` = NULL, `next_attempt_at` = NULL, `last_error` = NULL
          WHERE `id` = :id',
        ['id' => $pdSlowDeletionId]
    );
}

// Erased tombstones stay, as in production; their ledger lines do not.
$pdStripLedger(array_values(array_unique($pdErased)));

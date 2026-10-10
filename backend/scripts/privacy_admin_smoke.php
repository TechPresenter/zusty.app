<?php

declare(strict_types=1);

/**
 * Smoke test for Admin → Privacy requests (/admin/privacy-requests).
 *
 *   php scripts/privacy_admin_smoke.php
 *
 * Signs in as a temporary probe admin through the real login form, seeds
 * account deletions (one by the owner, one by support with a balance given
 * up, one whose clean-up is still pending) and data exports in every state
 * against accounts it creates itself, and checks that all three tabs render
 * them: reasons totalled, the user's own words escaped, how each deletion was
 * confirmed and by whom, the clean-up state, the export states and the live
 * link count. It then removes everything it created, including the probe
 * admin's sign-in history and audit rows. It never touches a real account.
 *
 * Needs the site running and migration 048 applied. Set ZUSTE_ADMIN_URL to
 * point at another host. Two runs must not overlap.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$basePath = dirname(__DIR__);
$base     = getenv('ZUSTE_ADMIN_URL') ?: 'http://localhost:8000';

require $basePath . '/app/Core/Autoloader.php';

(new Zuste\Core\Autoloader($basePath . '/storage/cache/classmap.php'))
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

Zuste\Core\App::boot($basePath);

use Zuste\Core\Database as D;

$jar = tempnam(sys_get_temp_dir(), 'zjar');

/** @return array{status:int,body:string,location:?string} */
function http(string $method, string $url, array $fields = []): array
{
    global $jar;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'zuste-privacy-probe/1.0',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $raw        = (string) curl_exec($ch);
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $headerSize), $m);

    return ['status' => $status, 'body' => substr($raw, $headerSize), 'location' => isset($m[1]) ? trim($m[1]) : null];
}

function csrf(string $body): string
{
    preg_match('/name="_csrf"\s+value="([^"]+)"/', $body, $m);

    return $m[1] ?? '';
}

/** The figure a stat tile shows, digits only. */
function statFigure(string $body, string $label): ?int
{
    if (preg_match('#stat__value">([^<]*)</div>\s*<div class="stat__label">' . preg_quote($label, '#') . '<#', $body, $m) !== 1) {
        return null;
    }

    return (int) preg_replace('/\D/', '', $m[1]);
}

$passed = 0;
$failed = 0;

$check = static function (string $name, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? "  \033[32mPASS\033[0m  " : "  \033[31mFAIL\033[0m  ") . $name . ($ok || $detail === '' ? '' : "\n        {$detail}") . "\n";
};

$probeEmail = 'probe-privacy@zuste.test';
$liveEmail  = 'probe-privacy-live@zuste.test';
$password   = 'ProbePrivacy!2026';
$now        = date('Y-m-d H:i:s');

// -----------------------------------------------------------------
// Leftovers of a run that died half-way. Everything this script creates is
// recognisable: the two addresses above and the DELPRB referral prefix of
// its tombstones. Children first: deletion records hold their user (RESTRICT).
// -----------------------------------------------------------------

$leftoverUsers = array_map('intval', D::column(
    "SELECT `id` FROM `users` WHERE `email` = ? OR `referral_code` LIKE 'DELPRB%'",
    [$liveEmail]
));

foreach ($leftoverUsers as $leftover) {
    D::execute('DELETE FROM `data_export_requests` WHERE `user_id` = ?', [$leftover]);
    D::execute('DELETE FROM `account_deletion_requests` WHERE `user_id` = ?', [$leftover]);
    D::execute('DELETE FROM `users` WHERE `id` = ?', [$leftover]);
}

foreach (D::column('SELECT `id` FROM `admins` WHERE `email` = ?', [$probeEmail]) as $oldAdmin) {
    D::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = ?', [(int) $oldAdmin]);
    D::execute("DELETE FROM `login_history` WHERE `actor_type` = 'admin' AND `actor_id` = ?", [(int) $oldAdmin]);
    D::execute("DELETE FROM `activity_logs` WHERE `actor_type` = 'admin' AND `actor_id` = ?", [(int) $oldAdmin]);
    D::execute('DELETE FROM `admins` WHERE `id` = ?', [(int) $oldAdmin]);
}

// -----------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------

$adminId = D::insert('admins', [
    'name' => 'Privacy Probe', 'email' => $probeEmail, 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'role_id' => (int) D::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1'),
    'status' => 'active', 'must_change_password' => 0, 'created_at' => $now,
]);

/** A tombstone, as an erasure leaves one. */
$tombstone = static fn (string $type): int => D::insert('users', [
    'uuid'          => Zuste\Core\Crypto::uuid4(),
    'account_type'  => $type,
    'name'          => 'Deleted user',
    'referral_code' => 'DELPRB' . strtoupper(bin2hex(random_bytes(4))),
    'status'        => 'deleted',
    'deleted_at'    => $now,
    'created_at'    => $now,
]);

$tombstoneId = $tombstone('creator');
$operatorId  = $tombstone('brand');
$pendingId   = $tombstone('creator');

// A live account of this script's own, never a real user's.
$liveId = D::insert('users', [
    'uuid'          => Zuste\Core\Crypto::uuid4(),
    'account_type'  => 'brand',
    'name'          => 'Privacy Probe Live',
    'email'         => $liveEmail,
    'referral_code' => 'PRBLIVE' . strtoupper(bin2hex(random_bytes(4))),
    'status'        => 'active',
    'created_at'    => $now,
]);
$liveName = 'Privacy Probe Live';

$deletionIds = [
    // By the owner, in the app.
    D::insert('account_deletion_requests', [
        'user_id'      => $tombstoneId,
        'account_type' => 'creator',
        'reason_code'  => 'fees',
        'reason_text'  => 'Too pricey <script>alert(1)</script>',
        'verified_via' => 'email_code',
        'platform'     => 'android',
        'summary'      => json_encode(['erased' => [], 'retained' => ['bookings' => 2, 'invoices' => 1]]),
        'created_at'   => $now,
        'completed_at' => $now,
    ]),
    // By support, on a confirmed request, giving up a small balance.
    D::insert('account_deletion_requests', [
        'user_id'               => $operatorId,
        'account_type'          => 'brand',
        'reason_code'           => 'other',
        'verified_via'          => 'operator',
        'platform'              => 'admin',
        'performed_by_admin_id' => $adminId,
        'forfeited_amount'      => 200.00,
        'forfeited_currency'    => 'INR',
        'summary'               => json_encode(['erased' => [], 'retained' => [], 'operator' => ['admin_id' => $adminId, 'note' => 'Probe', 'via' => 'panel']]),
        'created_at'            => $now,
        'completed_at'          => $now,
    ]),
    // Erased, but its clean-up is still being retried.
    D::insert('account_deletion_requests', [
        'user_id'         => $pendingId,
        'account_type'    => 'creator',
        'reason_code'     => 'privacy',
        'verified_via'    => 'password',
        'platform'        => 'ios',
        'summary'         => json_encode(['erased' => [], 'retained' => []]),
        'pending_steps'   => json_encode(['files', 'kyc']),
        'attempts'        => 1,
        'next_attempt_at' => date('Y-m-d H:i:s', time() + 600),
        'created_at'      => $now,
        'completed_at'    => null,
    ]),
];

$liveHash = static fn (): string => hash('sha256', random_bytes(32));

$exportIds = [
    // Live: ready, downloads left, link not revoked.
    D::insert('data_export_requests', [
        'user_id' => $liveId, 'status' => 'ready', 'file_size' => 20480, 'download_count' => 1, 'max_downloads' => 5,
        'token_hash' => $liveHash(), 'verified_via' => 'password', 'ip_address' => '203.0.113.7',
        'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'created_at' => $now, 'completed_at' => $now,
    ]),
    // Still 'ready' in the table, but every download used.
    D::insert('data_export_requests', [
        'user_id' => $liveId, 'status' => 'ready', 'file_size' => 20480, 'download_count' => 5, 'max_downloads' => 5,
        'token_hash' => $liveHash(), 'verified_via' => 'email_code', 'ip_address' => '203.0.113.8',
        'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'created_at' => $now, 'completed_at' => $now,
    ]),
    // Still 'ready' in the table, but the link has expired.
    D::insert('data_export_requests', [
        'user_id' => $liveId, 'status' => 'ready', 'file_size' => 20480, 'download_count' => 0, 'max_downloads' => 5,
        'token_hash' => $liveHash(), 'verified_via' => 'password', 'ip_address' => '203.0.113.9',
        'expires_at' => date('Y-m-d H:i:s', time() - 3600), 'created_at' => $now, 'completed_at' => $now,
    ]),
    D::insert('data_export_requests', [
        'user_id' => $tombstoneId, 'status' => 'failed', 'verified_via' => 'sms_code', 'error' => 'Probe failure',
        'created_at' => $now, 'completed_at' => $now,
    ]),
];

echo "\nAdmin -> Privacy requests\n" . str_repeat('=', 64) . "\n\n";

try {
    // What "Export links still live" must say, by the panel's own rule.
    $expectedLive = (int) D::scalar(
        "SELECT COUNT(*) FROM `data_export_requests`
          WHERE `status` = 'ready' AND `expires_at` > NOW()
            AND `download_count` < `max_downloads` AND `token_hash` IS NOT NULL"
    );

    file_put_contents($jar, '');
    $login = http('GET', $base . '/admin/login');
    $post  = http('POST', $base . '/admin/login', ['_csrf' => csrf($login['body']), 'email' => $probeEmail, 'password' => $password]);
    $check('the probe admin signs in', $post['status'] === 302, 'status ' . $post['status']);

    $page = http('GET', $base . '/admin/privacy-requests');
    $body = $page['body'];
    $check('the deletions tab renders', $page['status'] === 200, 'status ' . $page['status']);
    $check('the navigation links to it', str_contains($body, 'href="/admin/privacy-requests"'));
    $check('reasons are totalled under "Why people leave"', str_contains($body, 'Why people leave') && str_contains($body, 'The fees are too high'));
    $check('the user\'s words are shown, escaped', str_contains($body, 'Too pricey &lt;script&gt;') && !str_contains($body, '<script>alert(1)'));
    $check('how it was confirmed and where from', str_contains($body, 'Email code') && str_contains($body, 'Android'));
    $check('what was kept', str_contains($body, '2 bookings, 1 invoice'));
    $check('no name is shown for a deletion', str_contains($body, 'User #' . $tombstoneId));
    $check('a support erasure says so, and who did it', str_contains($body, 'Support request (staff)') && str_contains($body, 'Privacy Probe') && str_contains($body, '>Support<'));
    $check('a balance given up is shown', str_contains($body, 'Gave up ₹200.00') || str_contains($body, 'Gave up'));
    $check('a finished clean-up is marked Completed', str_contains($body, 'Completed'));
    $check('an unfinished clean-up is marked pending, with what is left', str_contains($body, 'Clean-up pending') && str_contains($body, 'uploaded files, identity documents'));
    $check('the note says invoices keep their billing details', str_contains($body, 'invoices keep the billing details printed on them') && !str_contains($body, 'anonymised'));
    $check('the clean-ups stat counts the pending one', (statFigure($body, 'Clean-ups pending') ?? 0) >= 1, 'stat ' . var_export(statFigure($body, 'Clean-ups pending'), true));

    $exports = http('GET', $base . '/admin/privacy-requests?tab=exports');
    $body    = $exports['body'];
    $check('the exports tab renders', $exports['status'] === 200, 'status ' . $exports['status']);
    $check('a live user\'s export shows who asked', str_contains($body, htmlspecialchars($liveName, ENT_QUOTES)) && str_contains($body, '203.0.113.7'));
    $check('downloads used and status', str_contains($body, '1 / 5') && str_contains($body, 'Ready'));
    $check('a used-up link reads Used up, not Ready', str_contains($body, 'Used up') && str_contains($body, '5 / 5'));
    $check('an expired link reads Expired', str_contains($body, 'Expired'));
    $check('a deleted user\'s export says so', str_contains($body, 'Deleted user') && str_contains($body, 'Probe failure'));
    $check(
        '"Export links still live" counts only usable links',
        statFigure($body, 'Export links still live') === $expectedLive,
        'shown ' . var_export(statFigure($body, 'Export links still live'), true) . ', expected ' . $expectedLive
    );

    $act = http('GET', $base . '/admin/privacy-requests?tab=act&user=' . $liveId);
    $body = $act['body'];
    $check('the act tab renders for an account', $act['status'] === 200, 'status ' . $act['status']);
    $check('it shows what an erasure would do', str_contains($body, 'Erase the account') && str_contains($body, 'What is erased') && str_contains($body, 'What is kept'));
    $check('it offers to email a copy, to a masked address', str_contains($body, 'Email a copy of the data') && str_contains($body, 'pr***') && !str_contains($body, 'exports/download'));
    $check('it lists accounts deleted by staff but not erased', str_contains($body, 'Deleted by staff but not erased'));
} finally {
    D::execute('DELETE FROM `data_export_requests` WHERE `id` IN (' . implode(',', array_map('intval', $exportIds)) . ')');
    D::execute('DELETE FROM `account_deletion_requests` WHERE `id` IN (' . implode(',', array_map('intval', $deletionIds)) . ')');

    foreach ([$tombstoneId, $operatorId, $pendingId, $liveId] as $userId) {
        D::execute('DELETE FROM `data_export_requests` WHERE `user_id` = ?', [$userId]);
        D::execute('DELETE FROM `users` WHERE `id` = ?', [$userId]);
    }

    D::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = ?', [$adminId]);
    D::execute("DELETE FROM `login_history` WHERE `actor_type` = 'admin' AND `actor_id` = ?", [$adminId]);
    D::execute("DELETE FROM `activity_logs` WHERE `actor_type` = 'admin' AND `actor_id` = ?", [$adminId]);
    D::execute('DELETE FROM `admins` WHERE `id` = ?', [$adminId]);
    @unlink($jar);

    echo "\n\033[2m(probe admin, its sign-in history and every seeded row removed)\033[0m\n";
}

echo "\n" . str_repeat('=', 64) . "\n";
echo ($failed === 0 ? "\033[42m\033[30m PASS \033[0m" : "\033[41m\033[37m FAIL \033[0m") . "  {$passed} passed, {$failed} failed\n\n";

exit($failed === 0 ? 0 : 1);

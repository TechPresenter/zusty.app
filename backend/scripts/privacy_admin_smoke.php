<?php

declare(strict_types=1);

/**
 * Smoke test for Admin → Privacy requests (/admin/privacy-requests).
 *
 *   php scripts/privacy_admin_smoke.php
 *
 * Signs in as a temporary probe admin through the real login form, seeds one
 * account deletion and two data exports, and checks both tabs render them,
 * reasons totalled and the user's own words escaped, then removes everything
 * it created.
 *
 * Needs the site running. Set ZUSTE_ADMIN_URL to point at another host.
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

$passed = 0;
$failed = 0;

$check = static function (string $name, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? "  \033[32mPASS\033[0m  " : "  \033[31mFAIL\033[0m  ") . $name . ($ok || $detail === '' ? '' : "\n        {$detail}") . "\n";
};

$probeEmail = 'probe-privacy@zuste.test';
$password   = 'ProbePrivacy!2026';

D::execute('DELETE FROM `admins` WHERE `email` = ?', [$probeEmail]);

$adminId = D::insert('admins', [
    'name' => 'Privacy Probe', 'email' => $probeEmail, 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'role_id' => (int) D::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1'),
    'status' => 'active', 'must_change_password' => 0, 'created_at' => date('Y-m-d H:i:s'),
]);

// A tombstone, as an erasure leaves one, and a live account for comparison.
$tombstoneId = D::insert('users', [
    'uuid'          => Zuste\Core\Crypto::uuid4(),
    'account_type'  => 'creator',
    'name'          => 'Deleted user',
    'referral_code' => 'DELPRB' . strtoupper(bin2hex(random_bytes(4))),
    'status'        => 'deleted',
    'deleted_at'    => date('Y-m-d H:i:s'),
    'created_at'    => date('Y-m-d H:i:s'),
]);
$liveId = (int) D::scalar("SELECT `id` FROM `users` WHERE `deleted_at` IS NULL AND `account_type` <> 'guest' ORDER BY `id` LIMIT 1");
$liveName = (string) D::scalar('SELECT `name` FROM `users` WHERE `id` = ?', [$liveId]);

$deletionId = D::insert('account_deletion_requests', [
    'user_id'      => $tombstoneId,
    'account_type' => 'creator',
    'reason_code'  => 'fees',
    'reason_text'  => 'Too pricey <script>alert(1)</script>',
    'verified_via' => 'email_code',
    'platform'     => 'android',
    'summary'      => json_encode(['erased' => [], 'retained' => ['bookings' => 2, 'invoices' => 1]]),
    'created_at'   => date('Y-m-d H:i:s'),
    'completed_at' => date('Y-m-d H:i:s'),
]);

$exportIds = [
    D::insert('data_export_requests', [
        'user_id' => $liveId, 'status' => 'ready', 'file_size' => 20480, 'download_count' => 1, 'max_downloads' => 5,
        'verified_via' => 'password', 'ip_address' => '203.0.113.7', 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        'created_at' => date('Y-m-d H:i:s'), 'completed_at' => date('Y-m-d H:i:s'),
    ]),
    D::insert('data_export_requests', [
        'user_id' => $tombstoneId, 'status' => 'failed', 'verified_via' => 'sms_code', 'error' => 'Probe failure',
        'created_at' => date('Y-m-d H:i:s'), 'completed_at' => date('Y-m-d H:i:s'),
    ]),
];

echo "\nAdmin -> Privacy requests\n" . str_repeat('=', 64) . "\n\n";

try {
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

    $exports = http('GET', $base . '/admin/privacy-requests?tab=exports');
    $body    = $exports['body'];
    $check('the exports tab renders', $exports['status'] === 200, 'status ' . $exports['status']);
    $check('a live user\'s export shows who asked', str_contains($body, htmlspecialchars($liveName, ENT_QUOTES)) && str_contains($body, '203.0.113.7'));
    $check('downloads used and status', str_contains($body, '1 / 5') && str_contains($body, 'Ready'));
    $check('a deleted user\'s export says so', str_contains($body, 'Deleted user') && str_contains($body, 'Probe failure'));
} finally {
    D::execute('DELETE FROM `data_export_requests` WHERE `id` IN (?, ?)', $exportIds);
    D::execute('DELETE FROM `account_deletion_requests` WHERE `id` = ?', [$deletionId]);
    D::execute('DELETE FROM `users` WHERE `id` = ?', [$tombstoneId]);
    D::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = ?', [$adminId]);
    D::execute('DELETE FROM `admins` WHERE `id` = ?', [$adminId]);
    @unlink($jar);

    echo "\n\033[2m(probe admin and seeded rows removed)\033[0m\n";
}

echo "\n" . str_repeat('=', 64) . "\n";
echo ($failed === 0 ? "\033[42m\033[30m PASS \033[0m" : "\033[41m\033[37m FAIL \033[0m") . "  {$passed} passed, {$failed} failed\n\n";

exit($failed === 0 ? 0 : 1);

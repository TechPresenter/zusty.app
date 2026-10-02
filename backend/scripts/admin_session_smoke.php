<?php

declare(strict_types=1);

/**
 * Does revoking an admin session actually end it?
 *
 *     php scripts/admin_session_smoke.php
 *
 * The panel tells an admin "Other sessions have been signed out" after a
 * password change and "Session revoked." in the Security Centre. Both
 * statements delete a row from `admin_sessions`. Nothing read that table, so
 * both messages were false: the other browser stayed signed in and renewed its
 * idle window on every request, and a stolen cookie survived a password reset
 * indefinitely.
 *
 * AdminAuthMiddleware now requires the row to exist. This walks a real browser
 * session over HTTP and proves both halves of that: a revoked session is
 * refused, and — just as important — a live one is not. A revocation check that
 * signs honest admins out at random is worse than none, and MySQL reporting
 * zero affected rows for an UPDATE that changes nothing makes that an easy
 * mistake, so the rapid-request case is checked explicitly.
 *
 * Needs the site running. Set ZUSTE_ADMIN_URL to point at another host.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.
");
}

$basePath = dirname(__DIR__);
$base     = getenv('ZUSTE_ADMIN_URL') ?: 'http://127.0.0.1:8000';

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
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'zuste-session-probe/1.0',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body    = substr($raw, $headerSize);

    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

    return ['status' => $status, 'body' => $body, 'location' => isset($m[1]) ? trim($m[1]) : null];
}

$failed = 0;
$passed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failed, $passed;

    if ($ok) {
        $passed++;
        printf("  \033[32mok\033[0m    %s%s\n", $label, $detail === '' ? '' : "  \033[2m{$detail}\033[0m");
        return;
    }

    $failed++;
    printf("  \033[31mFAIL\033[0m  %s  %s\n", $label, $detail);
}

// ---------------------------------------------------------------
// A throwaway admin, removed at the end.
// ---------------------------------------------------------------

$email    = 'probe-session@zuste.test';
$password = 'ProbeSession!2026';

D::execute('DELETE FROM `admins` WHERE `email` = ?', [$email]);

$roleId = (int) D::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1');

$adminId = D::insert('admins', [
    'name'                 => 'Session Probe',
    'email'                => $email,
    'password_hash'        => password_hash($password, PASSWORD_BCRYPT),
    'role_id'              => $roleId,
    'status'               => 'active',
    'must_change_password' => 0,
    'created_at'           => date('Y-m-d H:i:s'),
]);

echo "\nAdmin session revocation\n" . str_repeat('=', 64) . "\n\n";

try {
    // --- sign in -------------------------------------------------
    $login = http('GET', $base . '/admin/login');
    preg_match('/name="_csrf"\s+value="([^"]+)"/', $login['body'], $t);

    $token = $t[1] ?? '';

    check('the login page issues a CSRF token', $token !== '', 'token was empty');

    $post = http('POST', $base . '/admin/login', [
        '_csrf'   => $token,
        'email'   => $email,
        'password'   => $password,
    ]);

    check(
        'signing in redirects into the panel',
        $post['status'] === 302 && str_contains((string) $post['location'], '/admin'),
        "status={$post['status']} location=" . (string) $post['location']
    );

    $rows = (int) D::scalar('SELECT COUNT(*) FROM `admin_sessions` WHERE `admin_id` = ?', [$adminId]);
    check('a session row is recorded', $rows === 1, "{$rows} row(s)");

    // --- the session works ---------------------------------------
    $dash = http('GET', $base . '/admin');
    check(
        'the signed-in admin can open the panel',
        $dash['status'] === 200,
        "status={$dash['status']} location=" . (string) $dash['location']
    );

    // --- revoke it, exactly as the Security Centre does -----------
    $deleted = D::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = ?', [$adminId]);
    check('the session row is deleted', $deleted === 1, "{$deleted} row(s) removed");

    $after = http('GET', $base . '/admin');

    check(
        'the revoked session is refused',
        $after['status'] === 302 && str_contains((string) $after['location'], '/admin/login'),
        "status={$after['status']} location=" . (string) $after['location']
            . ' — the admin was still signed in after their session was revoked'
    );

    // The cookie must be dead, not merely bounced once.
    $again = http('GET', $base . '/admin/users');
    check(
        'and the same cookie cannot reach another page',
        $again['status'] === 302 && str_contains((string) $again['location'], '/admin/login'),
        "status={$again['status']} location=" . (string) $again['location']
    );

    // --- signing in again works ----------------------------------
    $login2 = http('GET', $base . '/admin/login');
    preg_match('/name="_csrf"\s+value="([^"]+)"/', $login2['body'], $t2);

    $post2 = http('POST', $base . '/admin/login', [
        '_csrf'    => $t2[1] ?? '',
        'email'    => $email,
        'password' => $password,
    ]);

    $dash2 = http('GET', $base . '/admin');

    check(
        'a fresh sign-in still works',
        $post2['status'] === 302 && $dash2['status'] === 200,
        "login={$post2['status']} dashboard={$dash2['status']} — the fix must not lock anyone out"
    );

    // --- repeated requests inside one second ---------------------
    // MySQL reports 0 affected rows for an UPDATE that changes nothing, so a
    // naive affected-row check would sign the admin out at random here.
    $rapid = array_map(static fn (): int => http('GET', $base . '/admin')['status'], range(1, 5));

    check(
        'rapid requests do not trip a false revocation',
        array_unique($rapid) === [200],
        'statuses were: ' . implode(', ', $rapid)
    );
} finally {
    D::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = ?', [$adminId]);
    D::execute('DELETE FROM `admins` WHERE `id` = ?', [$adminId]);
    @unlink($jar);

    echo "\n\033[2m(probe admin removed)\033[0m\n";
}

echo "\n" . str_repeat('=', 64) . "\n";
printf(
    $failed === 0 ? "  \033[32m%d checks passed.\033[0m\n\n" : "  \033[31m%d passed, {$failed} FAILED.\033[0m\n\n",
    $passed
);

exit($failed === 0 ? 0 : 1);

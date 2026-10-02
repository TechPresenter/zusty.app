<?php

declare(strict_types=1);

/**
 * Can an admin set, replace and remove their own profile photo?
 *
 *     php scripts/admin_avatar_smoke.php
 *
 * The photo is managed from My account (/admin/account) and shows in the top
 * bar and on the Admins screen. This walks a real browser session over HTTP
 * with a throwaway admin and checks the parts a click-through would miss:
 *
 *  - a replaced or removed photo is deleted from disk and from the media
 *    library, not left behind as an orphan or as a restorable row whose
 *    bytes are gone;
 *  - a bad file (disguised script, wrong type, oversized) is refused with a
 *    message on the account page rather than an error screen, and the current
 *    photo survives;
 *  - the upload needs a signed-in admin and a CSRF token.
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

use Zuste\Core\Config;
use Zuste\Core\Database as D;
use Zuste\Services\UploadService;

$jar       = tempnam(sys_get_temp_dir(), 'zjar');
$tempFiles = [];

/**
 * @param array<string,string>    $fields
 * @param array<string,\CURLFile> $files
 * @return array{status:int,body:string,location:?string}
 */
function http(string $method, string $url, array $fields = [], array $files = []): array
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
        CURLOPT_USERAGENT      => 'zuste-avatar-probe/1.0',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        // An array body makes curl send multipart/form-data, as the browser does.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $files === [] ? http_build_query($fields) : $fields + $files);
    }

    $raw        = (string) curl_exec($ch);
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body    = substr($raw, $headerSize);

    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

    return ['status' => $status, 'body' => $body, 'location' => isset($m[1]) ? trim($m[1]) : null];
}

function csrf(string $body): string
{
    preg_match('/name="_csrf"\s+value="([^"]+)"/', $body, $m);

    return $m[1] ?? '';
}

/**
 * The toast a page carries, as [type, message].
 *
 * @return array{0:string,1:string}
 */
function flash(string $body): array
{
    preg_match('/data-flash="([^"]*)"\s+data-flash-type="([^"]*)"/', $body, $m);

    return [$m[2] ?? '', html_entity_decode($m[1] ?? '', ENT_QUOTES)];
}

/** Write bytes to a temp file that is removed at the end. */
function tempFile(string $bytes): string
{
    global $tempFiles;

    $path        = (string) tempnam(sys_get_temp_dir(), 'zimg');
    $tempFiles[] = $path;
    file_put_contents($path, $bytes);

    return $path;
}

/** A small real image, encoded by GD. */
function image(string $format): string
{
    $canvas = imagecreatetruecolor(320, 320);
    imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, random_int(0, 255), 140, 210));

    ob_start();
    match ($format) {
        'png'   => imagepng($canvas),
        'gif'   => imagegif($canvas),
        default => imagejpeg($canvas, null, 85),
    };
    imagedestroy($canvas);

    return tempFile((string) ob_get_clean());
}

/** Post a file to the avatar form, with a fresh CSRF token from My account. */
function upload(string $path, string $name, string $mime): array
{
    global $base;

    $page = http('GET', $base . '/admin/account');

    return http(
        'POST',
        $base . '/admin/account/avatar',
        ['_csrf' => csrf($page['body'])],
        ['avatar' => new CURLFile($path, $mime, $name)]
    );
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
// A throwaway admin, removed at the end with anything it uploaded.
// ---------------------------------------------------------------

$email    = 'probe-avatar@zuste.test';
$password = 'ProbeAvatar!2026';

$uploadRoot = rtrim((string) Config::get('storage.upload_path'), '/\\');
$uploadUrl  = rtrim((string) Config::get('storage.upload_url'), '/');

$purgeProbe = static function (int $adminId): void {
    foreach (D::select("SELECT `id` FROM `media` WHERE `uploader_type` = 'admin' AND `uploader_id` = ?", [$adminId]) as $row) {
        UploadService::purge((int) $row['id']);
    }

    D::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = ?', [$adminId]);
    D::execute('DELETE FROM `admins` WHERE `id` = ?', [$adminId]);
};

$stale = D::scalar('SELECT `id` FROM `admins` WHERE `email` = ?', [$email]);
if ($stale !== null) {
    $purgeProbe((int) $stale);
}

$roleId = (int) D::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1');

$adminId = D::insert('admins', [
    'name'                 => 'Avatar Probe',
    'email'                => $email,
    'password_hash'        => password_hash($password, PASSWORD_BCRYPT),
    'role_id'              => $roleId,
    'status'               => 'active',
    'must_change_password' => 0,
    'created_at'           => date('Y-m-d H:i:s'),
]);

$avatar     = static fn (): ?string => D::scalar('SELECT `avatar` FROM `admins` WHERE `id` = ?', [$adminId]);
$mediaCount = static fn (): int => (int) D::scalar(
    "SELECT COUNT(*) FROM `media` WHERE `uploader_type` = 'admin' AND `uploader_id` = ?",
    [$adminId]
);
$onDisk = static function (string $url) use ($uploadRoot, $uploadUrl): bool {
    // PHP caches stat results, and the same path is checked before and after
    // a delete — without this the second check reports the stale answer.
    clearstatcache();

    return $url !== '' && is_file($uploadRoot . '/' . ltrim(substr($url, strlen($uploadUrl)), '/'));
};

echo "\nAdmin profile photo\n" . str_repeat('=', 64) . "\n\n";

try {
    // --- sign in -------------------------------------------------
    $login = http('GET', $base . '/admin/login');
    $post  = http('POST', $base . '/admin/login', [
        '_csrf'    => csrf($login['body']),
        'email'    => $email,
        'password' => $password,
    ]);

    check(
        'signing in redirects into the panel',
        $post['status'] === 302 && str_ends_with((string) $post['location'], '/admin'),
        "status={$post['status']} location=" . (string) $post['location']
    );

    $page = http('GET', $base . '/admin/account');

    check(
        'My account offers the photo upload',
        $page['status'] === 200 && str_contains($page['body'], 'action="/admin/account/avatar"'),
        "status={$page['status']}"
    );
    check('and shows initials while no photo is set', str_contains($page['body'], 'avatar--fallback" id="avatar-preview"'));
    check('with no Remove button yet', !str_contains($page['body'], 'action="/admin/account/avatar/delete"'));

    // --- first photo ----------------------------------------------
    $res  = upload(image('png'), 'me.png', 'image/png');
    $page = http('GET', $base . '/admin/account');
    [$type, $message] = flash($page['body']);
    $first = (string) $avatar();

    check(
        'uploading a PNG returns to My account with a confirmation',
        $res['status'] === 302 && str_ends_with((string) $res['location'], '/admin/account')
            && $type === 'success' && $message === 'Profile photo updated.',
        "status={$res['status']} toast={$type}: {$message}"
    );
    check('the photo URL is saved on the admin', str_starts_with($first, $uploadUrl . '/avatars/'), $first);
    check('the file is on disk', $onDisk($first));

    $media = D::selectOne('SELECT `folder`, `uploader_type`, `uploader_id` FROM `media` WHERE `url` = ?', [$first]);
    check(
        'and recorded in the media library as this admin\'s upload',
        $media !== null && $media['folder'] === 'avatars' && $media['uploader_type'] === 'admin'
            && (int) $media['uploader_id'] === $adminId
    );
    check(
        'the top bar and the account page both show it',
        substr_count($page['body'], 'src="' . $first . '"') >= 2,
        substr_count($page['body'], 'src="' . $first . '"') . ' occurrence(s)'
    );

    $served = http('GET', $base . $first);
    check(
        'the photo itself is served as an image',
        $served['status'] === 200 && @getimagesizefromstring($served['body']) !== false,
        "status={$served['status']}"
    );

    // --- replace it -----------------------------------------------
    $res    = upload(image('jpg'), 'me.jpg', 'image/jpeg');
    $second = (string) $avatar();

    check(
        'replacing it with a JPEG saves the new photo',
        $res['status'] === 302 && $second !== $first && str_ends_with($second, '.jpg'),
        $second
    );
    check('the new file is on disk', $onDisk($second));
    check('the old file is deleted from disk', !$onDisk($first), $first);
    check(
        'and its media row is gone rather than left restorable in the trash',
        (int) D::scalar('SELECT COUNT(*) FROM `media` WHERE `url` = ?', [$first]) === 0
    );

    // --- bad files ------------------------------------------------
    [, $maxBytes] = UploadService::limitsFor('image');

    $badFiles = [
        'a PHP script named .jpg' => [tempFile("<?php echo 'pwned'; ?>"), 'me.jpg', 'image/jpeg', 'do not match its extension'],
        'a GIF (not allowed)'     => [image('gif'), 'me.gif', 'image/gif', 'Unsupported file type'],
        'a file over the limit'   => [tempFile(str_repeat("\0", $maxBytes + 1024)), 'me.png', 'image/png', 'too large'],
    ];

    foreach ($badFiles as $label => [$path, $name, $mime, $expected]) {
        $before = $mediaCount();
        $res    = upload($path, $name, $mime);
        $page   = http('GET', $base . '/admin/account');
        [$type, $message] = flash($page['body']);

        check(
            "{$label} is refused with a message on My account",
            $res['status'] === 302 && str_ends_with((string) $res['location'], '/admin/account')
                && $type === 'error' && str_contains($message, $expected),
            "status={$res['status']} toast={$type}: {$message}"
        );
        check(
            '  and the current photo is kept',
            $avatar() === $second && $onDisk($second) && $mediaCount() === $before
        );
    }

    // --- CSRF -----------------------------------------------------
    $before = $mediaCount();
    http('POST', $base . '/admin/account/avatar', [], ['avatar' => new CURLFile(image('png'), 'image/png', 'me.png')]);

    check(
        'an upload without a CSRF token changes nothing',
        $avatar() === $second && $mediaCount() === $before
    );

    // --- remove ---------------------------------------------------
    $page = http('GET', $base . '/admin/account');
    check('the Remove button appears once a photo is set', str_contains($page['body'], 'action="/admin/account/avatar/delete"'));

    $res  = http('POST', $base . '/admin/account/avatar/delete', ['_csrf' => csrf($page['body'])]);
    $page = http('GET', $base . '/admin/account');
    [$type, $message] = flash($page['body']);

    check(
        'removing the photo returns to My account with a confirmation',
        $res['status'] === 302 && $type === 'success' && $message === 'Profile photo removed.',
        "status={$res['status']} toast={$type}: {$message}"
    );
    check('the admin has no photo', $avatar() === null, var_export($avatar(), true));
    check('the file is deleted from disk', !$onDisk($second), $second);
    check('and from the media library', $mediaCount() === 0, $mediaCount() . ' row(s) left');
    check(
        'initials show again',
        str_contains($page['body'], 'avatar--fallback" id="avatar-preview"') && !str_contains($page['body'], $second)
    );

    $res  = http('POST', $base . '/admin/account/avatar/delete', ['_csrf' => csrf($page['body'])]);
    $page = http('GET', $base . '/admin/account');
    [$type, $message] = flash($page['body']);

    check(
        'removing again only says there is nothing to remove',
        $res['status'] === 302 && $type === 'info' && str_contains($message, 'no profile photo'),
        "toast={$type}: {$message}"
    );

    $logged = (int) D::scalar(
        "SELECT COUNT(*) FROM `activity_logs`
         WHERE `actor_type` = 'admin' AND `actor_id` = ?
           AND `action` IN ('account.avatar_updated', 'account.avatar_removed')",
        [$adminId]
    );
    check('every change is in the activity log', $logged === 3, "{$logged} of 3 entries");

    // --- signed out -----------------------------------------------
    file_put_contents($jar, '');

    $res = http('POST', $base . '/admin/account/avatar', [], ['avatar' => new CURLFile(image('png'), 'image/png', 'me.png')]);

    check(
        'a signed-out request cannot set a photo',
        $avatar() === null && $mediaCount() === 0,
        "status={$res['status']} location=" . (string) $res['location']
    );
} finally {
    $purgeProbe($adminId);

    foreach ($tempFiles as $path) {
        @unlink($path);
    }

    @unlink($jar);

    echo "\n\033[2m(probe admin and its uploads removed)\033[0m\n";
}

echo "\n" . str_repeat('=', 64) . "\n";
printf(
    $failed === 0 ? "  \033[32m%d checks passed.\033[0m\n\n" : "  \033[31m%d passed, {$failed} FAILED.\033[0m\n\n",
    $passed
);

exit($failed === 0 ? 0 : 1);

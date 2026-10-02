<?php
declare(strict_types=1);
/**
 * Can an admin grant themselves a permission they do not hold?
 *
 *     php scripts/privilege_escalation_smoke.php
 *
 * roles.manage used to be effectively `*`: create a role one level below your
 * own with every box ticked, then assign it to yourself. This drives the real
 * HTTP endpoints and checks the escalation is refused.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This script can only be run from the command line.
"); }

$basePath = dirname(__DIR__);
$base     = getenv('ZUSTE_ADMIN_URL') ?: 'http://127.0.0.1:8000';
require $basePath . '/app/Core/Autoloader.php';
(new Zuste\Core\Autoloader($basePath . '/storage/cache/classmap.php'))->addNamespace('Zuste', $basePath . '/app')->register();
Zuste\Core\App::boot($basePath);

use Zuste\Core\Database as D;
use Zuste\Services\PermissionService;

$jar = tempnam(sys_get_temp_dir(), 'zesc');

function http(string $method, string $url, array $fields = []): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>20]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)); }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status'=>$status, 'body'=>substr($raw,$hs), 'headers'=>substr($raw,0,$hs)];
}
function csrf(string $body): string { preg_match('/name="_csrf"\s+value="([^"]+)"/', $body, $m); return $m[1] ?? ''; }

$failed = 0; $passed = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $failed, $passed;
    if ($ok) { $passed++; printf("  \033[32mok\033[0m    %s%s\n", $l, $d === '' ? '' : "  \033[2m{$d}\033[0m"); return; }
    $failed++; printf("  \033[31mFAIL\033[0m  %s  %s\n", $l, $d);
}

$email = 'probe-escalate@zuste.test';
$password = 'ProbeEscalate!2026';
D::execute('DELETE FROM `admins` WHERE `email` = ?', [$email]);
D::execute('DELETE FROM `roles` WHERE `slug` LIKE "probe-%"');

// A mid-level role holding ONLY roles.manage and admins.manage.
$roleId = D::insert('roles', ['name'=>'Probe Manager','slug'=>'probe-manager','level'=>70,'is_system'=>0,'created_at'=>date('Y-m-d H:i:s')]);
foreach (['roles.manage','admins.manage','dashboard.view'] as $slug) {
    $pid = (int) D::scalar('SELECT `id` FROM `permissions` WHERE `slug` = ?', [$slug]);
    if ($pid > 0) D::insertIgnore('role_permissions', ['role_id'=>$roleId, 'permission_id'=>$pid]);
}
$adminId = D::insert('admins', ['name'=>'Escalation Probe','email'=>$email,'password_hash'=>password_hash($password, PASSWORD_BCRYPT),
    'role_id'=>$roleId,'status'=>'active','must_change_password'=>0,'created_at'=>date('Y-m-d H:i:s')]);
PermissionService::flush();

echo "\nPrivilege escalation through roles.manage\n" . str_repeat('=', 64) . "\n\n";
$newRoleId = 0;

try {
    $login = http('GET', $base . '/admin/login');
    http('POST', $base . '/admin/login', ['_csrf'=>csrf($login['body']), 'email'=>$email, 'password'=>$password]);
    $dash = http('GET', $base . '/admin');
    check('the probe admin can sign in', $dash['status'] === 200, "status={$dash['status']}");

    $mine = PermissionService::forAdmin($adminId);
    check('and holds only its three permissions', count($mine) === 3, implode(', ', $mine));

    $wanted = (int) D::scalar('SELECT `id` FROM `permissions` WHERE `slug` = ?', ['wallet.adjust']);
    check('wallet.adjust exists and is not held', $wanted > 0 && !in_array('wallet.adjust', $mine, true));

    // The escalation: a lower-level role carrying a permission the caller lacks.
    $form = http('GET', $base . '/admin/roles');
    $create = http('POST', $base . '/admin/roles', [
        '_csrf' => csrf($form['body']),
        'name'  => 'Probe Escalated',
        'level' => 69,
        'permissions' => [$wanted],
    ]);

    $newRoleId = (int) D::scalar('SELECT `id` FROM `roles` WHERE `name` = ?', ['Probe Escalated']);

    $granted = $newRoleId > 0
        ? (int) D::scalar('SELECT COUNT(*) FROM `role_permissions` WHERE `role_id` = ? AND `permission_id` = ?', [$newRoleId, $wanted])
        : 0;

    check(
        'a role cannot be given a permission the creator does not hold',
        $granted === 0,
        $granted > 0 ? 'wallet.adjust was granted — the caller can now assign this role to themselves' : 'refused'
    );

    // Whether or not the role row was created, the permission must not be on it.
    if ($newRoleId > 0) {
        $total = (int) D::scalar('SELECT COUNT(*) FROM `role_permissions` WHERE `role_id` = ?', [$newRoleId]);
        check('and no permission at all leaked onto it', $total === 0, "{$total} permission(s) attached");
    }

    // A grant the caller DOES hold must still work.
    $ok = (int) D::scalar('SELECT `id` FROM `permissions` WHERE `slug` = ?', ['dashboard.view']);
    $form2 = http('GET', $base . '/admin/roles');
    http('POST', $base . '/admin/roles', [
        '_csrf' => csrf($form2['body']),
        'name'  => 'Probe Legitimate',
        'level' => 68,
        'permissions' => [$ok],
    ]);

    $legitId = (int) D::scalar('SELECT `id` FROM `roles` WHERE `name` = ?', ['Probe Legitimate']);
    $legitGranted = $legitId > 0
        ? (int) D::scalar('SELECT COUNT(*) FROM `role_permissions` WHERE `role_id` = ? AND `permission_id` = ?', [$legitId, $ok])
        : 0;

    check(
        'a permission the caller DOES hold is still grantable',
        $legitGranted === 1,
        'the guard must not block legitimate delegation'
    );
} finally {
    D::execute('DELETE FROM `role_permissions` WHERE `role_id` IN (SELECT `id` FROM `roles` WHERE `name` LIKE "Probe %" OR `slug` LIKE "probe-%")');
    D::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = ?', [$adminId]);
    D::execute('DELETE FROM `admins` WHERE `id` = ?', [$adminId]);
    D::execute('DELETE FROM `roles` WHERE `name` LIKE "Probe %" OR `slug` LIKE "probe-%"');
    @unlink($jar);
    echo "\n\033[2m(probe rolled back)\033[0m\n";
}

echo "\n" . str_repeat('=', 64) . "\n";
printf($failed === 0 ? "  \033[32m%d checks passed.\033[0m\n\n" : "  \033[31m%d passed, {$failed} FAILED.\033[0m\n\n", $passed);
exit($failed === 0 ? 0 : 1);

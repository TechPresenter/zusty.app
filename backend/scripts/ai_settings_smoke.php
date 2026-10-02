<?php

declare(strict_types=1);

/**
 * Does Settings -> AI configure what the app gets?
 *
 *     php scripts/ai_settings_smoke.php
 *
 * Signs in as a throwaway admin and drives the AI tab over HTTP: saving a
 * provider and key, testing the connection, flipping the app's feature
 * switches and removing the key — and checks each change where it matters,
 * in the database and in the `ai` block of /api/v1/app/config.
 *
 * No real provider is called: a temporary provider points at
 * tests/fixtures/mock_openai.php, started here on 127.0.0.1:8099. Everything
 * the script changes is put back at the end.
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
use Zuste\Services\AiProviderService;

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
        CURLOPT_USERAGENT      => 'zuste-ai-settings-probe/1.0',
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

/** @return array{0:string,1:string} [type, message] of the toast a page carries. */
function flash(string $body): array
{
    preg_match('/data-flash="([^"]*)"\s+data-flash-type="([^"]*)"/', $body, $m);

    return [$m[2] ?? '', html_entity_decode($m[1] ?? '', ENT_QUOTES)];
}

/** POST a form on the AI tab with a fresh token, then read the toast it left. */
function submit(string $path, array $fields): array
{
    global $base;

    $page = http('GET', $base . '/admin/settings?group=ai');
    $post = http('POST', $base . $path, ['_csrf' => csrf($page['body'])] + $fields);
    $next = http('GET', $base . '/admin/settings?group=ai');

    return ['post' => $post, 'page' => $next, 'toast' => flash($next['body'])];
}

/** The `ai` block the app would receive right now. */
function appAi(): array
{
    global $base;

    $ch = curl_init($base . '/api/v1/app/config');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $json = json_decode((string) curl_exec($ch), true);
    curl_close($ch);

    return (array) ($json['data']['ai'] ?? []);
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
// Fixtures: a mock provider, a full admin and a settings-only admin.
// ---------------------------------------------------------------

$mockLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zuste-mock-ai-smoke.log';
$mock    = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:8099', $basePath . '/tests/fixtures/mock_openai.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $mockLog . '.out', 'a'], 2 => ['file', $mockLog . '.out', 'a']],
    $pipes,
    $basePath,
    array_merge(getenv(), ['ZUSTE_MOCK_AI_LOG' => $mockLog])
);

for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', 8099, $errno, $errstr, 0.2);
    if ($socket !== false) {
        fclose($socket);
        break;
    }
    usleep(100_000);
}

$originalSettings  = D::select("SELECT `setting_key`, `value` FROM `settings` WHERE `group_key` = 'ai'");
$originalProviders = D::select('SELECT `id`, `is_default`, `is_enabled` FROM `ai_providers`');

D::execute("DELETE FROM `ai_providers` WHERE `code` = 'it-smoke-openai'");

$providerId = D::insert('ai_providers', [
    'code'          => 'it-smoke-openai',
    'name'          => 'Smoke Mock OpenAI',
    'adapter'       => 'openai',
    'api_base_url'  => 'http://127.0.0.1:8099/v1',
    'default_model' => 'gpt-6-luna',
    'is_enabled'    => 0,
    'is_default'    => 0,
    'priority'      => 1,
    'timeout_seconds' => 20,
    'max_retries'   => 0,
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

$probeEmail    = 'probe-ai-settings@zuste.test';
$limitedEmail  = 'probe-ai-limited@zuste.test';
$password      = 'ProbeAiSettings!2026';

D::execute('DELETE FROM `admins` WHERE `email` IN (?, ?)', [$probeEmail, $limitedEmail]);
D::execute("DELETE FROM `roles` WHERE `slug` = 'probe-settings-only'");

$adminId = D::insert('admins', [
    'name' => 'AI Settings Probe', 'email' => $probeEmail, 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'role_id' => (int) D::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1'),
    'status' => 'active', 'must_change_password' => 0, 'created_at' => date('Y-m-d H:i:s'),
]);

// Can manage settings in general, but not AI.
$limitedRole = D::insert('roles', ['name' => 'Probe settings only', 'slug' => 'probe-settings-only', 'level' => 60, 'is_system' => 0, 'created_at' => date('Y-m-d H:i:s')]);

foreach (['settings.view', 'settings.manage', 'dashboard.view'] as $slug) {
    $permissionId = (int) D::scalar('SELECT `id` FROM `permissions` WHERE `slug` = ?', [$slug]);
    if ($permissionId > 0) {
        D::insertIgnore('role_permissions', ['role_id' => $limitedRole, 'permission_id' => $permissionId]);
    }
}

$limitedId = D::insert('admins', [
    'name' => 'AI Limited Probe', 'email' => $limitedEmail, 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'role_id' => $limitedRole, 'status' => 'active', 'must_change_password' => 0, 'created_at' => date('Y-m-d H:i:s'),
]);

\Zuste\Services\PermissionService::flush();

$signIn = static function (string $email) use ($base, $password, $jar): bool {
    file_put_contents($jar, '');
    $login = http('GET', $base . '/admin/login');
    $post  = http('POST', $base . '/admin/login', ['_csrf' => csrf($login['body']), 'email' => $email, 'password' => $password]);

    return $post['status'] === 302 && str_ends_with((string) $post['location'], '/admin');
};

$provider = static fn (): array => (array) D::selectOne('SELECT * FROM `ai_providers` WHERE `id` = ?', [$providerId]);
$key      = 'sk-test-smoke-' . bin2hex(random_bytes(6));

echo "\nSettings -> AI\n" . str_repeat('=', 64) . "\n\n";

try {
    check('the mock provider is listening', is_resource($mock) && @fsockopen('127.0.0.1', 8099) !== false);
    check('a full admin can sign in', $signIn($probeEmail));

    // --- the tab -------------------------------------------------
    $page = http('GET', $base . '/admin/settings?group=ai');

    check('Settings has an AI tab', $page['status'] === 200 && str_contains($page['body'], 'href="/admin/settings?group=ai"'), "status={$page['status']}");
    check('with the provider card', str_contains($page['body'], 'Provider &amp; API key') && str_contains($page['body'], 'Smoke Mock OpenAI'));
    check(
        'and the app switches and limits',
        str_contains($page['body'], 'name="settings[feature_bio_writer]"')
            && str_contains($page['body'], 'name="settings[feature_creator_match]"')
            && str_contains($page['body'], 'name="settings[daily_limit_per_user]"')
    );
    check(
        'but none of the old single-provider fields',
        !str_contains($page['body'], 'name="settings[api_key]"') && !str_contains($page['body'], 'name="settings[provider]"')
    );

    // --- a provider needs a key ----------------------------------
    $r = submit('/admin/settings/ai/provider', ['provider_id' => $providerId, 'model' => 'gpt-6-luna']);
    check('saving without a key is refused', $r['toast'][0] === 'error' && str_contains($r['toast'][1], 'API key'), implode(': ', $r['toast']));
    check('and changes nothing', (int) $provider()['is_enabled'] === 0);

    // --- a wrong key is caught at once ---------------------------
    $r = submit('/admin/settings/ai/provider', ['provider_id' => $providerId, 'model' => 'gpt-6-luna', 'api_key' => 'not-a-valid-key']);
    check('a wrong key is saved but flagged straight away', $r['toast'][0] === 'warning' && str_contains($r['toast'][1], 'rejected the API key'), implode(': ', $r['toast']));

    // --- a good key ----------------------------------------------
    $r = submit('/admin/settings/ai/provider', ['provider_id' => $providerId, 'model' => 'gpt-6-luna', 'api_key' => $key]);
    $p = $provider();

    check('a good key is saved and verified', $r['toast'][0] === 'success' && str_contains($r['toast'][1], 'key works'), implode(': ', $r['toast']));
    check('the provider becomes the enabled default', (int) $p['is_default'] === 1 && (int) $p['is_enabled'] === 1);
    check('with the chosen model', $p['default_model'] === 'gpt-6-luna', (string) $p['default_model']);
    check('the key is stored encrypted', $p['api_key'] !== $key && AiProviderService::apiKey($p) === $key);
    check(
        'and never appears on the page, not even encrypted',
        !str_contains($r['page']['body'], $key) && !str_contains($r['page']['body'], (string) $p['api_key'])
    );
    check('the page shows it as verified', str_contains($r['page']['body'], 'Key verified'));

    // --- test connection -----------------------------------------
    $page = http('GET', $base . '/admin/settings?group=ai');
    http('POST', $base . '/admin/settings/test', ['_csrf' => csrf($page['body']), 'target' => 'ai']);
    $toast = flash(http('GET', $base . '/admin/settings?group=ai')['body']);
    check('"Test connection" reaches the provider', $toast[0] === 'success' && str_contains($toast[1], 'answered in'), implode(': ', $toast));

    // --- the app's switches --------------------------------------
    $r = submit('/admin/settings', [
        'group'    => 'ai',
        'settings' => [
            'enabled'              => '1',
            'feature_bio_writer'   => '1',
            'feature_creator_match' => '1',
            // feature_campaign_brief left unticked = off
            'daily_limit_per_user' => '30',
            'monthly_budget'       => '10',
        ],
    ]);
    $ai = appAi();

    check('the switches save', $r['toast'][0] === 'success', implode(': ', $r['toast']));
    check(
        'and the app is told exactly what is on',
        ($ai['enabled'] ?? null) === true && ($ai['bio_writer'] ?? null) === true
            && ($ai['campaign_brief'] ?? null) === false && ($ai['creator_match'] ?? null) === true,
        json_encode($ai)
    );
    check('with the new allowance', ($ai['daily_limit'] ?? null) === 30, json_encode($ai['daily_limit'] ?? null));
    check('the card lists what is live', str_contains($r['page']['body'], 'Live in the app'));

    $r = submit('/admin/settings', ['group' => 'ai', 'settings' => ['enabled' => '1', 'daily_limit_per_user' => '0']]);
    check(
        'an allowance of zero is refused',
        (int) D::scalar("SELECT `value` FROM `settings` WHERE `group_key` = 'ai' AND `setting_key` = 'daily_limit_per_user'") === 30,
        implode(': ', $r['toast'])
    );

    // --- remove the key ------------------------------------------
    $r = submit('/admin/settings/ai/clear-key', ['provider_id' => $providerId]);
    $p = $provider();

    check('the key can be removed', $r['toast'][0] === 'success' && $p['api_key'] === null && (int) $p['is_enabled'] === 0, implode(': ', $r['toast']));
    check('and the app stops offering AI', (appAi()['enabled'] ?? null) === false, json_encode(appAi()));

    // --- the AI overview page ------------------------------------
    $overview = http('GET', $base . '/admin/ai');
    check('the AI page points to Settings', $overview['status'] === 200 && str_contains($overview['body'], 'Open Settings → AI'));
    check('and no longer has its own settings form', !str_contains($overview['body'], 'action="/admin/ai/settings"'));
    $old = http('POST', $base . '/admin/ai/settings', ['_csrf' => csrf($overview['body']), 'api_key' => 'x']);
    check('the old settings endpoint is gone', !in_array($old['status'], [200, 302], true), "status={$old['status']}");

    // --- an admin without ai.manage ------------------------------
    check('a settings-only admin can sign in', $signIn($limitedEmail));

    $page = http('GET', $base . '/admin/settings?group=general');
    check('they do not see the AI tab', $page['status'] === 200 && !str_contains($page['body'], 'href="/admin/settings?group=ai"'), "status={$page['status']}");
    // The panel refuses a browser by sending it back to the dashboard with a
    // toast (a JSON caller gets a 403), so that is what is asserted here.
    $open    = http('GET', $base . '/admin/settings?group=ai');
    $refusal = flash(http('GET', $base . '/admin')['body']);
    check(
        'cannot open it',
        $open['status'] === 302 && str_ends_with((string) $open['location'], '/admin') && str_contains($refusal[1], 'permission'),
        "status={$open['status']} toast=" . implode(': ', $refusal)
    );

    $post = http('POST', $base . '/admin/settings/ai/provider', [
        '_csrf' => csrf($page['body']), 'provider_id' => $providerId, 'model' => 'gpt-6-luna', 'api_key' => $key,
    ]);
    check('and cannot save a key', $post['status'] !== 200 && $provider()['api_key'] === null, "status={$post['status']}");
} finally {
    foreach ($originalSettings as $row) {
        D::execute(
            "UPDATE `settings` SET `value` = ? WHERE `group_key` = 'ai' AND `setting_key` = ?",
            [(string) ($row['value'] ?? ''), (string) $row['setting_key']]
        );
    }

    D::execute('DELETE FROM `ai_providers` WHERE `id` = ?', [$providerId]);

    foreach ($originalProviders as $row) {
        D::update('ai_providers', ['is_default' => (int) $row['is_default'], 'is_enabled' => (int) $row['is_enabled']], ['id' => (int) $row['id']]);
    }

    D::execute('DELETE FROM `admin_sessions` WHERE `admin_id` IN (?, ?)', [$adminId, $limitedId]);
    D::execute('DELETE FROM `admins` WHERE `id` IN (?, ?)', [$adminId, $limitedId]);
    D::execute('DELETE FROM `role_permissions` WHERE `role_id` = ?', [$limitedRole]);
    D::execute('DELETE FROM `roles` WHERE `id` = ?', [$limitedRole]);

    if (is_resource($mock)) {
        proc_terminate($mock);
        proc_close($mock);
    }

    @unlink($jar);
    @unlink($mockLog);
    @unlink($mockLog . '.out');

    echo "\n\033[2m(probe admins, role and provider removed; AI settings restored)\033[0m\n";
}

echo "\n" . str_repeat('=', 64) . "\n";
printf(
    $failed === 0 ? "  \033[32m%d checks passed.\033[0m\n\n" : "  \033[31m%d passed, {$failed} FAILED.\033[0m\n\n",
    $passed
);

exit($failed === 0 ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * Production preflight check.
 *
 * Refuses to give a clean bill of health unless the deployment is actually safe
 * to expose. Run it on the server, as the web user, before switching DNS:
 *
 *     php scripts/preflight.php
 *     php scripts/preflight.php --url=https://zustee.com   # also probe over HTTP
 *
 * Exit codes:  0 = ready   1 = warnings only   2 = blocking failures
 *
 * Every check states what it looked at and what it found, so a failure is
 * actionable without reading this file.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Env;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

(new Autoloader($basePath . '/storage/cache/classmap.php'))
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

App::boot($basePath);

// -----------------------------------------------------------------
// Harness
// -----------------------------------------------------------------

$fail = 0;
$warn = 0;
$pass = 0;

/** A blocking problem: do not go live. */
function fail(string $label, string $detail): void
{
    global $fail;
    $fail++;
    echo "  \033[31mFAIL\033[0m  {$label}\n        {$detail}\n";
}

/** Worth fixing, but not a launch blocker. */
function warn(string $label, string $detail): void
{
    global $warn;
    $warn++;
    echo "  \033[33mWARN\033[0m  {$label}\n        {$detail}\n";
}

function good(string $label, string $detail = ''): void
{
    global $pass;
    $pass++;
    echo "  \033[32mOK\033[0m    {$label}" . ($detail === '' ? '' : "  ({$detail})") . "\n";
}

function section(string $title): void
{
    echo "\n\033[1m{$title}\033[0m\n";
}

$targetUrl = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $targetUrl = rtrim(substr($arg, 6), '/');
    }
}

$isProduction = Env::getString('APP_ENV', 'production') === 'production';

echo "\n\033[1mZusty production preflight\033[0m\n";
echo str_repeat('=', 68) . "\n";
echo '  APP_ENV  ' . Env::getString('APP_ENV', '(unset)') . "\n";
echo '  PHP      ' . PHP_VERSION . "\n";

if (!$isProduction) {
    echo "\n\033[33mAPP_ENV is not 'production'. Checks still run, but environment\n";
    echo "checks are reported as warnings rather than failures.\033[0m\n";
}

/** In a non-production environment an unsafe setting is expected, so soften it. */
function envIssue(string $label, string $detail): void
{
    global $isProduction;
    $isProduction ? fail($label, $detail) : warn($label, $detail . ' (expected outside production)');
}

// -----------------------------------------------------------------
section('Runtime');

PHP_VERSION_ID >= 80200
    ? good('PHP 8.2 or newer', PHP_VERSION)
    : fail('PHP version', 'PHP ' . PHP_VERSION . ' — the codebase targets 8.2+');

foreach (['pdo_mysql', 'mbstring', 'json', 'openssl', 'curl', 'fileinfo'] as $extension) {
    extension_loaded($extension)
        ? good("ext-{$extension}")
        : fail("ext-{$extension}", 'required extension is not loaded');
}

extension_loaded('gd') || extension_loaded('imagick')
    ? good('image extension', extension_loaded('gd') ? 'gd' : 'imagick')
    : warn('image extension', 'neither gd nor imagick — image resizing on upload will fail');

extension_loaded('opcache') && (bool) ini_get('opcache.enable')
    ? good('OPcache enabled')
    : warn('OPcache', 'not enabled — expect a large avoidable latency cost on every request');

// display_errors leaks file paths and SQL into responses.
$displayErrors = strtolower((string) ini_get('display_errors'));

in_array($displayErrors, ['', '0', 'off'], true)
    ? good('display_errors off')
    : envIssue('display_errors', "php.ini has display_errors={$displayErrors} — errors will be shown to visitors");

// -----------------------------------------------------------------
section('Environment');

$envPath = $basePath . '/.env';

if (!is_file($envPath)) {
    fail('.env exists', "not found at {$envPath}");
} else {
    good('.env exists');

    $perms = substr(sprintf('%o', fileperms($envPath)), -3);

    // World-readable .env on a shared box hands over every secret.
    in_array($perms, ['600', '640', '660', '440'], true)
        ? good('.env permissions', $perms)
        : warn('.env permissions', "{$perms} — tighten to 640 (chmod 640 .env)");
}

// Any key documented in .env.example but absent here is a silent default.
$examplePath = $basePath . '/.env.example';

if (is_file($examplePath) && is_file($envPath)) {
    $keysIn = static function (string $path): array {
        $keys = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            $keys[] = trim(explode('=', $line, 2)[0]);
        }
        return $keys;
    };

    $missing = array_values(array_diff($keysIn($examplePath), $keysIn($envPath)));

    $missing === []
        ? good('.env covers every documented key')
        : warn('.env missing keys', implode(', ', $missing));
}

Env::getBool('APP_DEBUG', false)
    ? envIssue('APP_DEBUG', 'debug mode is ON — stack traces and queries will be exposed')
    : good('APP_DEBUG off');

$appUrl = Env::getString('APP_URL');

if ($appUrl === '') {
    fail('APP_URL', 'not set — absolute links, webhooks and emails will be wrong');
} elseif (!str_starts_with($appUrl, 'https://')) {
    envIssue('APP_URL scheme', "{$appUrl} is not https — webhook callbacks and cookies need TLS");
} else {
    good('APP_URL', $appUrl);
}

// -- secrets ------------------------------------------------------

/** Rejects short, absent, or obviously placeholder secrets. */
$checkSecret = static function (string $key, int $minLength) use (&$fail, &$pass): void {
    $value = Env::getString($key);

    if ($value === '') {
        fail($key, 'not set');
        return;
    }

    if (strlen($value) < $minLength) {
        fail($key, sprintf('only %d characters — needs at least %d', strlen($value), $minLength));
        return;
    }

    $placeholders = ['changeme', 'secret', 'password', 'your-', 'example', 'test', '123456'];

    foreach ($placeholders as $needle) {
        if (str_contains(strtolower($value), $needle)) {
            fail($key, "looks like a placeholder (contains \"{$needle}\")");
            return;
        }
    }

    good($key, strlen($value) . ' chars');
};

$checkSecret('APP_ENCRYPTION_KEY', 32);
$checkSecret('JWT_SECRET', 32);
$checkSecret('CRON_SECRET', 16);

// A shared secret between two roles is one compromise, not two.
Env::getString('JWT_SECRET') !== Env::getString('APP_ENCRYPTION_KEY')
    ? good('JWT and encryption keys differ')
    : fail('key reuse', 'JWT_SECRET and APP_ENCRYPTION_KEY are the same value');

// -- session and CORS ---------------------------------------------

Env::getBool('SESSION_SECURE', false)
    ? good('SESSION_SECURE on')
    : envIssue('SESSION_SECURE', 'admin session cookies will be sent over plain HTTP');

$sameSite = Env::getString('SESSION_SAMESITE', 'Lax');

in_array($sameSite, ['Lax', 'Strict'], true)
    ? good('SESSION_SAMESITE', $sameSite)
    : warn('SESSION_SAMESITE', "{$sameSite} — Lax or Strict is expected for CSRF resistance");

$origins = Env::getString('CORS_ALLOWED_ORIGINS');

if (str_contains($origins, '*')) {
    envIssue('CORS', 'a wildcard origin is allowed — any site could call the API with a user token');
} elseif ($origins === '') {
    warn('CORS', 'no allowed origins configured — the web build will be blocked by the browser');
} else {
    $localOnly = !str_contains($origins, 'https://');
    $localOnly
        ? envIssue('CORS origins', "only non-HTTPS origins are allowed: {$origins}")
        : good('CORS origins', $origins);
}

// -----------------------------------------------------------------
section('Database');

try {
    Database::connection();
    good('connection');

    $applied = (int) (Database::scalar('SELECT COUNT(*) FROM `migrations`') ?? 0);
    $onDisk  = count(glob($basePath . '/database/migrations/*.sql') ?: []);

    $applied >= $onDisk
        ? good('migrations applied', "{$applied}/{$onDisk}")
        : fail('migrations', ($onDisk - $applied) . ' migration(s) not applied — run php scripts/install.php --migrate-only');

    // An empty settings table means the app will fall back to code defaults for
    // everything an admin is supposed to control.
    $settings = (int) (Database::scalar('SELECT COUNT(*) FROM `settings`') ?? 0);
    $settings > 50
        ? good('settings seeded', (string) $settings)
        : fail('settings', "only {$settings} rows — run php scripts/install.php --seed-only");

    $admins = (int) (Database::scalar('SELECT COUNT(*) FROM `admins` WHERE `deleted_at` IS NULL') ?? 0);
    $admins > 0
        ? good('admin account exists', (string) $admins)
        : fail('admin account', 'no admin can sign in to the panel');

    // A demo/local admin address left in place on production is a real risk.
    $localAdmins = Database::column(
        "SELECT `email` FROM `admins` WHERE `email` LIKE '%.local' OR `email` LIKE '%@example.%' OR `email` LIKE '%.test'"
    );

    $localAdmins === []
        ? good('no placeholder admin accounts')
        : envIssue('placeholder admins', 'remove or rename: ' . implode(', ', $localAdmins));

    // Integration test residue must never reach production.
    $testUsers = (int) (Database::scalar(
        "SELECT COUNT(*) FROM `users` WHERE `email` LIKE 'it-%@zuste.test'"
    ) ?? 0);

    $testUsers === 0
        ? good('no integration-test accounts')
        : envIssue('test accounts', "{$testUsers} it-*@zuste.test users present — run the suite's cleanup");

    // The demo dataset used to be seeded by every install, because install.php
    // globbed the whole seeds directory. It is now behind --with-demo, but any
    // site installed before that change is still carrying 65 accounts that are
    // active, email-verified and share one password printed in the seed file.
    // Detected by the hash rather than by the address, so renaming the accounts
    // does not hide them.
    $demoUsers = (int) (Database::scalar(
        "SELECT COUNT(*) FROM `users`
          WHERE `password_hash` = '\$2y\$10\$v8JWUoBhM9Ti82yQacevE.fJutKY9H/6eppU/FxKfJ8dFs1sOq8Ky'
            AND `deleted_at` IS NULL"
    ) ?? 0);

    $demoUsers === 0
        ? good('no demo accounts with the shared seed password')
        : fail(
            'demo accounts',
            "{$demoUsers} account(s) still use the demo seed password, which is published in "
            . 'database/seeds/demo/020_demo_users_creators.sql — anyone can sign in as them. '
            . 'Delete them, or reset each password.'
        );
} catch (\Throwable $e) {
    fail('connection', $e->getMessage());
}

// -----------------------------------------------------------------
section('Filesystem');

foreach (['storage', 'storage/logs', 'storage/cache', 'storage/kyc', 'public/uploads'] as $relative) {
    $path = $basePath . '/' . $relative;

    if (!is_dir($path)) {
        // The vault creates its own tree lazily; the rest must exist.
        $relative === 'storage/kyc'
            ? good("{$relative}", 'created on first upload')
            : fail($relative, 'directory does not exist');
        continue;
    }

    is_writable($path)
        ? good($relative, 'writable')
        : fail($relative, 'not writable by the web user');
}

// KYC documents must never be reachable over HTTP.
$kycInsideWebroot = str_starts_with(
    (string) realpath($basePath . '/storage'),
    (string) realpath($basePath . '/public')
);

$kycInsideWebroot
    ? fail('storage location', 'storage/ is inside the web root — KYC documents would be publicly downloadable')
    : good('storage is outside the web root');

// An uploaded .php in the public tree is remote code execution.
$executables = [];

if (is_dir($basePath . '/public/uploads')) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath . '/public/uploads', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (preg_match('/\.(php\d?|phtml|phar|cgi|pl|sh)$/i', $file->getFilename()) === 1) {
            $executables[] = $file->getFilename();
        }
    }
}

$executables === []
    ? good('no executable files in public/uploads')
    : fail('executable uploads', 'remove immediately: ' . implode(', ', array_slice($executables, 0, 5)));

// -----------------------------------------------------------------
section('Web server');

$hasHtaccess = is_file($basePath . '/public/.htaccess');
$hasNginx    = is_file($basePath . '/deploy/nginx/zustee.conf');

if (!$hasHtaccess && !$hasNginx) {
    fail('rewrite rules', 'neither public/.htaccess nor deploy/nginx/zustee.conf exists');
} else {
    good('rewrite rules', implode(' + ', array_filter([
        $hasHtaccess ? 'apache/.htaccess' : null,
        $hasNginx ? 'nginx config' : null,
    ])));
}

// -----------------------------------------------------------------
section('Integrations');

// A gateway switched on with no credentials fails at the worst moment: checkout.
$gateways = [
    'razorpay' => ['razorpay_key_id', 'razorpay_key_secret'],
    'stripe'   => ['stripe_secret_key'],
    'cashfree' => ['cashfree_app_id', 'cashfree_secret_key'],
];

$anyGateway = false;

foreach ($gateways as $gateway => $keys) {
    if (!Config::settingBool('payment', $gateway . '_enabled', false)) {
        continue;
    }

    $anyGateway = true;
    $blank = array_values(array_filter(
        $keys,
        static fn (string $key): bool => Config::settingString('payment', $key) === ''
    ));

    $blank === []
        ? good("{$gateway} configured")
        : fail("{$gateway} credentials", 'enabled but missing: ' . implode(', ', $blank));
}

if (!$anyGateway) {
    Config::settingBool('payment', 'allow_wallet_payment', true)
        ? warn('payment gateways', 'none enabled — only wallet payments will work')
        : fail('payment gateways', 'no gateway enabled and wallet payments are off — nobody can pay');
}

// Cashfree in sandbox on a production host takes real money nowhere.
if (Config::settingBool('payment', 'cashfree_enabled', false)) {
    $mode = strtolower(Config::settingString('payment', 'cashfree_mode', 'sandbox'));

    $mode === 'production'
        ? good('cashfree mode', 'production')
        : envIssue('cashfree mode', "still '{$mode}' — live payments will not settle");
}

$mailDriver = Env::getString('MAIL_DRIVER', 'log');

$mailDriver === 'log'
    ? envIssue('mail driver', "'log' — password resets and receipts will never be delivered")
    : good('mail driver', $mailDriver);

Env::getString('FIREBASE_PROJECT_ID') !== '' || Config::settingString('firebase', 'project_id') !== ''
    ? good('Firebase project configured')
    : warn('Firebase', 'not configured — push notifications and social sign-in will not work');

// -----------------------------------------------------------------
section('Scheduled jobs');

try {
    $jobs = (int) (Database::scalar('SELECT COUNT(*) FROM `scheduled_jobs`') ?? 0);

    $jobs > 0
        ? good('jobs registered', (string) $jobs)
        : warn('scheduled jobs', 'none registered');

    // A registered job that has never run means cron is not wired up.
    $neverRan = (int) (Database::scalar(
        'SELECT COUNT(*) FROM `scheduled_jobs` WHERE `last_run_at` IS NULL AND `is_enabled` = 1'
    ) ?? 0);

    if ($neverRan > 0 && $isProduction) {
        warn('cron', "{$neverRan} enabled job(s) have never run — confirm the crontab is installed");
    } elseif ($jobs > 0) {
        good('cron has executed');
    }

    $failing = Database::column(
        "SELECT `job_key` FROM `scheduled_jobs` WHERE `last_status` = 'failed'"
    );

    $failing === []
        ? good('no failing jobs')
        : warn('failing jobs', implode(', ', $failing) . ' — check storage/logs');
} catch (\Throwable $e) {
    warn('scheduled jobs', $e->getMessage());
}

// -----------------------------------------------------------------
// Live HTTP probes — the only way to prove the web server actually enforces
// what the config claims.
// -----------------------------------------------------------------

if ($targetUrl !== null) {
    section("Live probes against {$targetUrl}");

    $probe = static function (string $path) use ($targetUrl): array {
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL            => $targetUrl . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw    = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        return ['status' => $status, 'raw' => $raw, 'error' => $error];
    };

    $health = $probe('/api/v1/health');

    $health['status'] === 200
        ? good('/api/v1/health', '200')
        : fail('/api/v1/health', $health['error'] !== '' ? $health['error'] : 'HTTP ' . $health['status']);

    // Each of these must NOT be served. A 200 here is a serious exposure.
    foreach ([
        '/.env'                  => 'environment file with every secret',
        '/app/Core/Database.php' => 'application source',
        '/storage/logs/app.log'  => 'application logs',
        '/database/migrations/'  => 'schema',
        '/composer.json'         => 'dependency manifest',
    ] as $path => $what) {
        $result = $probe($path);

        in_array($result['status'], [403, 404], true)
            ? good("blocked {$path}", (string) $result['status'])
            : fail("EXPOSED {$path}", "HTTP {$result['status']} — {$what} is publicly readable");
    }

    // Security headers, checked on a real response rather than in source.
    $headerBlob = strtolower($health['raw']);

    foreach ([
        'x-content-type-options'    => 'nosniff',
        'x-frame-options'           => null,
        'referrer-policy'           => null,
        'strict-transport-security' => null,
    ] as $header => $expected) {
        if (!str_contains($headerBlob, $header . ':')) {
            $header === 'strict-transport-security'
                ? warn("header {$header}", 'absent — set it at the proxy for HSTS')
                : fail("header {$header}", 'absent from the response');
            continue;
        }

        good("header {$header}");
    }
} else {
    section('Live probes');
    echo "  \033[33mSKIP\033[0m  pass --url=https://your-host to probe the deployed site\n";
    echo "        Without this, .env exposure and security headers are UNVERIFIED.\n";
}

// -----------------------------------------------------------------

echo "\n" . str_repeat('=', 68) . "\n";
printf(
    "  \033[32m%d passed\033[0m   \033[33m%d warnings\033[0m   \033[31m%d failures\033[0m\n\n",
    $pass,
    $warn,
    $fail
);

if ($fail > 0) {
    echo "  \033[41m\033[37m NOT READY \033[0m Fix the failures above before going live.\n\n";
    exit(2);
}

if ($warn > 0) {
    echo "  \033[43m\033[30m READY WITH WARNINGS \033[0m Review the warnings above.\n\n";
    exit(1);
}

echo "  \033[42m\033[30m READY \033[0m\n\n";
exit(0);

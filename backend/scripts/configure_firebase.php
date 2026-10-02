<?php

declare(strict_types=1);

/**
 * Zusty Firebase wiring helper.
 *
 * The app reads its Firebase configuration from the server, not from a bundled
 * google-services.json — so "connecting Firebase" means writing the values
 * from your Firebase project into the `settings` table. This script does that
 * safely: it reuses the app's own Crypto/Database classes, so the one secret
 * (the service-account JSON) is encrypted at rest exactly as the admin panel
 * would store it.
 *
 *   php scripts/configure_firebase.php --check
 *       Show the current Firebase settings and whether the app would consider
 *       the config usable. Writes nothing.
 *
 *   php scripts/configure_firebase.php path/to/firebase.json
 *       Apply the values in firebase.json (see the keys below). Only non-empty
 *       keys are written; anything you omit is left untouched.
 *
 *   php scripts/configure_firebase.php --template
 *       Print a blank firebase.json you can fill in.
 *
 * firebase.json keys (all optional — provide what you have):
 *
 *   project_id                 Firebase project id            -> firebase.project_id
 *   web_api_key                Web app "apiKey"               -> firebase.web_api_key
 *   messaging_sender_id        "messagingSenderId"            -> auth.firebase_messaging_sender_id
 *   storage_bucket             "storageBucket"                -> firebase.storage_bucket
 *   auth_domain                "authDomain" (auto if omitted) -> auth.firebase_auth_domain
 *   app_id_web                 Web app "appId"                -> auth.firebase_app_id_web
 *   app_id_android             Android app "appId"            -> auth.firebase_app_id_android
 *   app_id_ios                 iOS app "appId"                -> auth.firebase_app_id_ios
 *   google_web_client_id       OAuth 2.0 Web client id        -> auth.google_web_client_id
 *   google_android_client_id   OAuth 2.0 Android client id    -> auth.google_android_client_id
 *   google_ios_client_id       OAuth 2.0 iOS client id        -> auth.google_ios_client_id
 *   google_web_redirect_uri    OAuth web redirect (optional)  -> auth.google_web_redirect_uri
 *   apple_service_id           Apple Service ID (optional)    -> auth.apple_service_id
 *   apple_redirect_uri         Apple return URL (optional)    -> auth.apple_redirect_uri
 *   facebook_app_id            Facebook app id (optional)     -> auth.facebook_app_id
 *   service_account_json       Admin SDK service-account JSON -> firebase.service_account_json (encrypted)
 *                              (an object, a JSON string, or use
 *                               "service_account_json_file": "path/to/sa.json")
 */

use Zuste\Core\Autoloader;
use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\Env;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';
(new Autoloader())->addNamespace('Zuste', $basePath . '/app')->register();

$envPath = is_readable($basePath . '/.env') ? $basePath . '/.env' : dirname($basePath) . '/.env';
Env::load($envPath, true);
Config::load($basePath . '/config');

/** value key in firebase.json => [group_key, setting_key, encrypted] */
const MAP = [
    'project_id'               => ['firebase', 'project_id', false],
    'web_api_key'              => ['firebase', 'web_api_key', false],
    'storage_bucket'           => ['firebase', 'storage_bucket', false],
    'service_account_json'     => ['firebase', 'service_account_json', true],
    'messaging_sender_id'      => ['auth', 'firebase_messaging_sender_id', false],
    'auth_domain'              => ['auth', 'firebase_auth_domain', false],
    'app_id_web'               => ['auth', 'firebase_app_id_web', false],
    'app_id_android'           => ['auth', 'firebase_app_id_android', false],
    'app_id_ios'               => ['auth', 'firebase_app_id_ios', false],
    'google_web_client_id'     => ['auth', 'google_web_client_id', false],
    'google_android_client_id' => ['auth', 'google_android_client_id', false],
    'google_ios_client_id'     => ['auth', 'google_ios_client_id', false],
    'google_web_redirect_uri'  => ['auth', 'google_web_redirect_uri', false],
    'apple_service_id'         => ['auth', 'apple_service_id', false],
    'apple_redirect_uri'       => ['auth', 'apple_redirect_uri', false],
    'facebook_app_id'          => ['auth', 'facebook_app_id', false],
];

function line(string $s = ''): void
{
    fwrite(STDOUT, $s . PHP_EOL);
}

$arg = $argv[1] ?? '--check';

// ---------------------------------------------------------------------
// --template
// ---------------------------------------------------------------------
if ($arg === '--template') {
    $blank = [];
    foreach (array_keys(MAP) as $k) {
        $blank[$k] = '';
    }
    $blank['service_account_json'] = new stdClass();
    line((string) json_encode($blank, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit(0);
}

// ---------------------------------------------------------------------
// --check
// ---------------------------------------------------------------------
if ($arg === '--check') {
    line();
    line('  Current Firebase configuration');
    line('  ------------------------------');

    foreach (MAP as $valueKey => [$group, $key, $encrypted]) {
        $row = Database::select(
            'SELECT `value`, `is_encrypted` FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k LIMIT 1',
            ['g' => $group, 'k' => $key]
        )[0] ?? null;

        $state = '(missing row)';
        if ($row !== null) {
            $raw = (string) ($row['value'] ?? '');
            if ($raw === '') {
                $state = '(empty)';
            } elseif ((int) $row['is_encrypted'] === 1) {
                $state = 'set [encrypted]';
            } else {
                $state = 'set: ' . (strlen($raw) > 32 ? substr($raw, 0, 32) . '…' : $raw);
            }
        }

        line(sprintf('  %-26s %s.%-30s %s', $valueKey, $group, $key, $state));
    }

    $projectId = Config::settingString('firebase', 'project_id');
    $apiKey    = Config::settingString('firebase', 'web_api_key');
    $appIdWeb  = Config::settingString('auth', 'firebase_app_id_web');
    $usable    = $projectId !== '' && $apiKey !== '' && $appIdWeb !== '';

    line();
    line('  App would treat Firebase as: ' . ($usable ? 'USABLE — social sign-in can initialise' : 'NOT usable — project id / api key / web app id still needed'));
    line();
    exit(0);
}

// ---------------------------------------------------------------------
// Apply from a JSON file
// ---------------------------------------------------------------------
$file = $arg;
if (!is_readable($file)) {
    line("  ✖ Cannot read config file: $file");
    line("    Run with --template to print a blank one, or --check to see the current state.");
    exit(1);
}

$input = json_decode((string) file_get_contents($file), true);
if (!is_array($input)) {
    line("  ✖ $file is not valid JSON.");
    exit(1);
}

// Allow the service account to be supplied as a separate file path.
if (!empty($input['service_account_json_file'])) {
    $saPath = (string) $input['service_account_json_file'];
    if (!is_readable($saPath)) {
        line("  ✖ service_account_json_file not readable: $saPath");
        exit(1);
    }
    $input['service_account_json'] = (string) file_get_contents($saPath);
}

// Derive sensible defaults from the project id when the caller left them blank.
$projectId = trim((string) ($input['project_id'] ?? ''));
if ($projectId !== '') {
    if (empty($input['auth_domain'])) {
        $input['auth_domain'] = $projectId . '.firebaseapp.com';
    }
    if (empty($input['storage_bucket'])) {
        $input['storage_bucket'] = $projectId . '.appspot.com';
    }
}

$applied  = [];
$skipped  = [];

Database::transaction(static function () use ($input, &$applied, &$skipped): void {
    foreach (MAP as $valueKey => [$group, $key, $encrypted]) {
        if (!array_key_exists($valueKey, $input)) {
            continue;
        }

        $value = $input[$valueKey];

        // The service account may arrive as an object; store canonical JSON.
        if ($valueKey === 'service_account_json') {
            if (is_array($value)) {
                $value = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $decoded = json_decode($value, true);
                if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
                    line("  ✖ service_account_json is not a valid service account (needs client_email and private_key).");
                    throw new RuntimeException('Invalid service account JSON.');
                }
            }
        } else {
            $value = trim((string) $value);
        }

        if ($value === '') {
            continue;
        }

        $row = Database::select(
            'SELECT `id` FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k LIMIT 1',
            ['g' => $group, 'k' => $key]
        )[0] ?? null;

        if ($row === null) {
            $skipped[] = "$group.$key (no such setting row)";
            continue;
        }

        $stored = $encrypted ? Crypto::encrypt($value) : $value;

        Database::update('settings', ['value' => $stored], ['id' => (int) $row['id']]);

        $applied[] = $encrypted ? "$group.$key = [encrypted]" : "$group.$key = $value";
    }
});

line();
line('  Firebase wiring applied');
line('  -----------------------');
foreach ($applied as $a) {
    line('  ✓ ' . $a);
}
if ($skipped !== []) {
    line();
    foreach ($skipped as $s) {
        line('  ! skipped ' . $s);
    }
}
if ($applied === []) {
    line('  (nothing to write — every provided value was empty)');
}
line();
line('  Next: restart nothing — settings are read per request. Verify with:');
line('    php scripts/configure_firebase.php --check');
line('    curl -s http://localhost:8000/api/v1/app/config | php -r "echo json_encode(json_decode(file_get_contents(\"php://stdin\"),true)[\"data\"][\"auth\"][\"firebase\"] ?? [], JSON_PRETTY_PRINT);"');
line();
exit(0);

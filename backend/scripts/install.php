<?php

declare(strict_types=1);

/**
 * Zusty installer.
 *
 *   php scripts/install.php                     interactive
 *   php scripts/install.php --no-interaction    use ADMIN_* env vars
 *   php scripts/install.php --fresh             drop and rebuild the schema
 *   php scripts/install.php --migrate-only      run migrations, skip seeds
 *
 * The installer is idempotent: migrations are tracked in the `migrations`
 * table and every seed statement is an upsert, so re-running is safe.
 */

use Zuste\Core\Autoloader;
use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\Env;
use Zuste\Helpers\Str;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("The installer can only be run from the command line.\n");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

(new Autoloader())->addNamespace('Zuste', $basePath . '/app')->register();

$options        = getopt('', ['no-interaction', 'fresh', 'migrate-only', 'seed-only', 'with-demo', 'reseal:', 'help']);
$interactive    = !isset($options['no-interaction']);
$fresh          = isset($options['fresh']);
$migrateOnly    = isset($options['migrate-only']);
$seedOnly       = isset($options['seed-only']);
$withDemo       = isset($options['with-demo']);
$reseal         = isset($options['reseal']) ? (string) $options['reseal'] : '';

if (isset($options['help'])) {
    echo <<<TXT

    Zusty installer

      --no-interaction   Read the admin account from ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD
      --fresh            Drop every table first (DESTRUCTIVE)
      --migrate-only     Apply migrations only
      --seed-only        Apply seeds only
      --with-demo        Also load database/seeds/demo — 65 sample accounts, campaigns,
                         bookings and ledger rows for local work and screenshots.
                         NEVER on a live site: every demo account is active and shares
                         one password that is printed in the seed file.
      --reseal=NNN       Re-record the checksum of applied migration NNN, after you
                         have confirmed the live schema already matches the file.
                         Applies nothing — it only updates the ledger.
      --help             Show this message

    TXT;
    exit(0);
}

// ---------------------------------------------------------------------
// Terminal helpers
// ---------------------------------------------------------------------
function out(string $message = '', string $style = ''): void
{
    $codes = [
        'title'   => "\033[1;35m",
        'ok'      => "\033[0;32m",
        'warn'    => "\033[0;33m",
        'error'   => "\033[0;31m",
        'info'    => "\033[0;36m",
        'dim'     => "\033[2m",
    ];

    $prefix = $codes[$style] ?? '';
    $suffix = $prefix === '' ? '' : "\033[0m";

    echo $prefix . $message . $suffix . PHP_EOL;
}

function ask(string $question, string $default = '', bool $hidden = false): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo "  {$question}{$suffix}: ";

    if ($hidden && DIRECTORY_SEPARATOR !== '\\') {
        // Turn off terminal echo so a password is not printed.
        shell_exec('stty -echo 2>/dev/null');
        $answer = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;
    } else {
        $answer = trim((string) fgets(STDIN));
    }

    return $answer !== '' ? $answer : $default;
}

function fail(string $message): never
{
    out();
    out('  ✖ ' . $message, 'error');
    out();
    exit(1);
}

// ---------------------------------------------------------------------
// Banner
// ---------------------------------------------------------------------
out();
out('  ╔══════════════════════════════════════════════════╗', 'title');
out('  ║   Zusty  ·  Influencer Marketplace Installer     ║', 'title');
out('  ╚══════════════════════════════════════════════════╝', 'title');
out();

// ---------------------------------------------------------------------
// 1. Environment
// ---------------------------------------------------------------------
out('  [1/6] Environment', 'info');

$envCandidates = [dirname($basePath) . '/.env', $basePath . '/.env'];
$envPath       = null;

foreach ($envCandidates as $candidate) {
    if (is_readable($candidate)) {
        $envPath = $candidate;
        break;
    }
}

if ($envPath === null) {
    out('        No .env found. Creating one from .env.example …', 'warn');

    if (!is_readable($basePath . '/.env.example')) {
        fail('.env.example is missing. Re-download the backend package.');
    }

    $envPath = $basePath . '/.env';
    copy($basePath . '/.env.example', $envPath);
    out('        Created ' . $envPath, 'ok');
}

Env::load($envPath, true);
Config::load($basePath . '/config');

// Generate any secret that is still blank, and write it back.
$envContents = (string) file_get_contents($envPath);
$generated   = [];

foreach (['JWT_SECRET' => 32, 'APP_ENCRYPTION_KEY' => 32, 'CRON_SECRET' => 24] as $key => $bytes) {
    if (trim(Env::getString($key)) !== '') {
        continue;
    }

    $value       = bin2hex(random_bytes($bytes));
    $generated[] = $key;

    $envContents = preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $envContents) === 1
        ? (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $key . '=' . $value, $envContents)
        : rtrim($envContents) . PHP_EOL . $key . '=' . $value . PHP_EOL;
}

if ($generated !== []) {
    file_put_contents($envPath, $envContents);
    @chmod($envPath, 0600);

    Env::load($envPath, true);
    Config::load($basePath . '/config');

    out('        Generated secrets: ' . implode(', ', $generated), 'ok');
}

// PHP version and extensions.
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fail('PHP 8.1 or newer is required. This server runs ' . PHP_VERSION . '.');
}

$required = ['pdo_mysql', 'openssl', 'mbstring', 'json', 'curl', 'fileinfo'];
$missing  = array_values(array_filter($required, static fn (string $ext): bool => !extension_loaded($ext)));

if ($missing !== []) {
    fail('Missing required PHP extensions: ' . implode(', ', $missing));
}

if (!extension_loaded('gd')) {
    out('        Warning: the GD extension is missing — uploaded images will not be re-encoded.', 'warn');
}

out('        PHP ' . PHP_VERSION . ' with all required extensions.', 'ok');

// ---------------------------------------------------------------------
// 2. Database connection
// ---------------------------------------------------------------------
out();
out('  [2/6] Database', 'info');

// A belt as well as the braces of not globbing the demo directory: the demo
// accounts are active, email-verified and share one published password, so
// asking for them on a production host is almost certainly a mistake.
if ($withDemo && Config::get('app.env') === 'production') {
    fail(
        '--with-demo refused: APP_ENV is production. The demo dataset creates 65 sign-in-able '
        . 'accounts whose password is printed in the seed file.'
    );
}

if (trim((string) Config::get('database.name', '')) === '') {
    if (!$interactive) {
        fail('DB_NAME is not set in .env.');
    }

    out('        Database settings are incomplete. Enter them now:', 'warn');

    $host     = ask('MySQL host', '127.0.0.1');
    $port     = ask('MySQL port', '3306');
    $name     = ask('Database name');
    $user     = ask('Database user');
    $password = ask('Database password', '', true);

    foreach (['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $name, 'DB_USER' => $user, 'DB_PASSWORD' => $password] as $key => $value) {
        $envContents = preg_match('/^' . $key . '=.*$/m', $envContents) === 1
            ? (string) preg_replace('/^' . $key . '=.*$/m', $key . '=' . $value, $envContents)
            : rtrim($envContents) . PHP_EOL . $key . '=' . $value . PHP_EOL;
    }

    file_put_contents($envPath, $envContents);
    Env::load($envPath, true);
    Config::load($basePath . '/config');
}

try {
    Database::connection();
    $version = (string) Database::scalar('SELECT VERSION()');
    out('        Connected to ' . Config::get('database.name') . ' (' . $version . ')', 'ok');
} catch (Throwable $e) {
    fail('Could not connect to MySQL: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// 2b. Reseal a migration checksum (optional, non-destructive)
//
// For the case where an applied migration file was edited afterwards. The
// installer refuses to re-run it, and rightly so, but that leaves a permanent
// warning with no way to clear it. This re-records the checksum and applies
// nothing — so the operator has to have satisfied themselves that the live
// schema already matches the file. It cannot create or alter a table.
// ---------------------------------------------------------------------
if ($reseal !== '') {
    $version = str_pad(preg_replace('/\D/', '', $reseal) ?? '', 3, '0', STR_PAD_LEFT);
    $row     = Database::selectOne('SELECT * FROM `migrations` WHERE `version` = :v', ['v' => $version]);

    if ($row === null) {
        fail('No applied migration with version ' . $version . '. Nothing to reseal.');
    }

    $path = $basePath . '/database/migrations/' . $row['name'];

    if (!is_file($path)) {
        fail('Migration ' . $row['name'] . ' is recorded as applied but the file is missing.');
    }

    $current = hash('sha256', (string) file_get_contents($path));

    if ($current === $row['checksum']) {
        out('  ' . $row['name'] . ' already matches its recorded checksum. Nothing to do.', 'dim');
        exit(0);
    }

    out();
    out('  [!] Reseal ' . $row['name'] . ' — this records the file as applied WITHOUT running it.', 'warn');
    out('      Only do this if you have confirmed the live schema already matches the file.', 'dim');
    out('      recorded : ' . $row['checksum'], 'dim');
    out('      file now : ' . $current, 'dim');

    if ($interactive && strtolower(ask('        Type "yes" to reseal')) !== 'yes') {
        fail('Aborted.');
    }

    Database::execute(
        'UPDATE `migrations` SET `checksum` = :c WHERE `version` = :v',
        ['c' => $current, 'v' => $version]
    );

    out('  ✔ ' . $row['name'] . ' resealed.', 'ok');
    exit(0);
}

// ---------------------------------------------------------------------
// 3. Fresh install (optional, destructive)
// ---------------------------------------------------------------------
if ($fresh) {
    out();
    out('  [!] --fresh will DROP every table in ' . Config::get('database.name'), 'error');

    if ($interactive && strtolower(ask('        Type "yes" to continue')) !== 'yes') {
        fail('Aborted.');
    }

    /** @var list<string> $tables */
    $tables = Database::column('SHOW TABLES');

    Database::connection()->exec('SET FOREIGN_KEY_CHECKS=0');

    foreach ($tables as $table) {
        Database::connection()->exec('DROP TABLE IF EXISTS ' . Database::quoteIdentifier($table));
    }

    Database::connection()->exec('SET FOREIGN_KEY_CHECKS=1');

    out('        Dropped ' . count($tables) . ' tables.', 'ok');
}

// ---------------------------------------------------------------------
// 4. Migrations
// ---------------------------------------------------------------------
if (!$seedOnly) {
    out();
    out('  [3/6] Migrations', 'info');

    $files = glob($basePath . '/database/migrations/*.sql') ?: [];
    sort($files);

    if ($files === []) {
        fail('No migration files were found.');
    }

    // Bootstrap the ledger table itself.
    Database::connection()->exec(
        'CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `version` VARCHAR(20) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `checksum` CHAR(64) NOT NULL,
            `batch` INT UNSIGNED NOT NULL DEFAULT 1,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_migrations_version` (`version`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $batch = (int) (Database::scalar('SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`') ?? 1);
    $applied = 0;

    // Two files sharing a version number is a packaging mistake, not a normal
    // state: the ledger is keyed on version, so the second file would be
    // silently skipped and its tables would never exist. Catch it up front
    // rather than letting it look like an already-applied migration.
    $seenVersions = [];

    foreach ($files as $file) {
        $version = substr(basename($file), 0, 3);
        $seenVersions[$version][] = basename($file);
    }

    foreach ($seenVersions as $version => $names) {
        if (count($names) > 1) {
            out('        x Duplicate migration version ' . $version . ': ' . implode(', ', $names), 'error');
            out('          Renumber one of them — only the first would ever be applied.', 'error');

            throw new RuntimeException('Duplicate migration version: ' . $version);
        }
    }

    foreach ($files as $file) {
        $name    = basename($file);
        $version = substr($name, 0, 3);
        $sql     = (string) file_get_contents($file);
        $checksum = hash('sha256', $sql);

        $existing = Database::selectOne('SELECT * FROM `migrations` WHERE `version` = :v', ['v' => $version]);

        if ($existing !== null) {
            if ($existing['name'] !== $name) {
                // Same version, different file — the ledger already holds
                // another migration under this number.
                out('        x ' . $name . ' collides with applied migration ' . $existing['name']
                    . ' (both version ' . $version . ').', 'error');

                throw new RuntimeException('Migration version ' . $version . ' is already taken by ' . $existing['name']);
            }

            if ($existing['checksum'] !== $checksum) {
                // Skipping is the safe default — re-running an edited migration
                // could apply a statement twice. But the warning on its own
                // leaves the operator with no route forward, so say what to do:
                // either the edit was cosmetic and the ledger needs resealing,
                // or the schema really is behind and needs a new migration.
                out('        ! ' . $name . ' has changed since it was applied — skipping.', 'warn');
                out('          Compare the file against the live schema. If they match, the edit was'
                    . ' cosmetic — reseal with:', 'dim');
                out('            php scripts/install.php --reseal=' . $version, 'dim');
                out('          If the schema is genuinely behind, add a NEW migration instead —'
                    . ' never edit an applied one.', 'dim');
            } else {
                out('        · ' . $name . ' (already applied)', 'dim');
            }

            continue;
        }

        try {
            foreach (splitSqlStatements($sql) as $statement) {
                $trimmed = trim($statement);

                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }

                Database::connection()->exec($trimmed);
            }

            Database::insert('migrations', [
                'version'    => $version,
                'name'       => $name,
                'checksum'   => $checksum,
                'batch'      => $batch,
                'applied_at' => date('Y-m-d H:i:s'),
            ]);

            $applied++;
            out('        ✔ ' . $name, 'ok');
        } catch (Throwable $e) {
            fail('Migration ' . $name . ' failed: ' . $e->getMessage());
        }
    }

    out('        ' . $applied . ' migration(s) applied.', $applied > 0 ? 'ok' : 'dim');
}

// ---------------------------------------------------------------------
// 5. Seeds
// ---------------------------------------------------------------------
if (!$migrateOnly) {
    out();
    out('  [4/6] Seed data', 'info');

    // Only the reference seeds run by default. The demo dataset lives in
    // `seeds/demo/` and needs --with-demo, because it inserts 65 accounts whose
    // password is printed in the seed file itself. Globbing the whole directory
    // meant the documented production install shipped those accounts live,
    // active and email-verified, and nothing in the deployment docs said so.
    $seeds = glob($basePath . '/database/seeds/*.sql') ?: [];
    sort($seeds);

    if ($withDemo) {
        $demo = glob($basePath . '/database/seeds/demo/*.sql') ?: [];
        sort($demo);

        // After the reference data, never interleaved: the demo rows reference
        // categories, taxonomies and settings the reference seeds create.
        $seeds = array_merge($seeds, $demo);

        out('        demo dataset INCLUDED — do not do this on a live site.', 'warn');
    }

    foreach ($seeds as $file) {
        $name = basename($file);

        try {
            foreach (splitSqlStatements((string) file_get_contents($file)) as $statement) {
                $trimmed = trim($statement);

                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }

                Database::connection()->exec($trimmed);
            }

            out('        ✔ ' . $name, 'ok');
        } catch (Throwable $e) {
            fail('Seed ' . $name . ' failed: ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------
// 6. Super Admin
// ---------------------------------------------------------------------
out();
out('  [5/6] Administrator account', 'info');

$adminCount = (int) Database::scalar('SELECT COUNT(*) FROM `admins` WHERE `deleted_at` IS NULL');

if ($adminCount > 0) {
    out('        ' . $adminCount . ' admin account(s) already exist — skipping creation.', 'dim');
} else {
    if ($interactive) {
        out('        Create the first Super Admin.', 'dim');
        $adminName  = ask('Full name', 'Super Admin');
        $adminEmail = ask('Email address');
        $adminPass  = ask('Password (min 8 chars, mixed case + a number)', '', true);
    } else {
        $adminName  = Env::getString('ADMIN_NAME', 'Super Admin');
        $adminEmail = Env::getString('ADMIN_EMAIL');
        $adminPass  = Env::getString('ADMIN_PASSWORD');
    }

    if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
        fail('A valid administrator email address is required.');
    }

    if (strlen($adminPass) < 8
        || preg_match('/[a-z]/', $adminPass) !== 1
        || preg_match('/[A-Z]/', $adminPass) !== 1
        || preg_match('/\d/', $adminPass) !== 1) {
        fail('The administrator password must be at least 8 characters and contain an uppercase letter, a lowercase letter and a number.');
    }

    $roleId = (int) (Database::scalar("SELECT `id` FROM `roles` WHERE `slug` = 'super-admin'") ?? 1);

    Database::insert('admins', [
        'role_id'              => $roleId,
        'name'                 => $adminName,
        'email'                => strtolower($adminEmail),
        'password_hash'        => Crypto::hashPassword($adminPass),
        'status'               => 'active',
        'must_change_password' => 0,
        'password_changed_at'  => date('Y-m-d H:i:s'),
        'created_at'           => date('Y-m-d H:i:s'),
    ]);

    out('        ✔ Super Admin created: ' . strtolower($adminEmail), 'ok');
}

// ---------------------------------------------------------------------
// 7. Directories and permissions
// ---------------------------------------------------------------------
out();
out('  [6/6] Storage', 'info');

$directories = [
    $basePath . '/storage/logs',
    $basePath . '/storage/backups',
    $basePath . '/storage/tmp',
    $basePath . '/storage/cache',
    $basePath . '/public/uploads/avatars',
    $basePath . '/public/uploads/portfolio',
    $basePath . '/public/uploads/kyc',
    $basePath . '/public/uploads/chat',
    $basePath . '/public/uploads/banners',
    $basePath . '/public/uploads/blogs',
    $basePath . '/public/uploads/branding',
    $basePath . '/public/uploads/deliverables',
    $basePath . '/public/uploads/general',
];

foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }

    if (!is_writable($directory)) {
        out('        ! Not writable: ' . $directory, 'warn');
    }
}

// storage/ must never be reachable over HTTP.
@file_put_contents($basePath . '/storage/.htaccess', "Require all denied\nOptions -Indexes\n");

// uploads/ must never execute code.
@file_put_contents($basePath . '/public/uploads/.htaccess', <<<'CONF'
php_flag engine off
AddType text/plain .php .phtml .phar .pl .py .jsp .asp .sh .cgi
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule \.(php[0-9]?|phtml|phar|pl|py|jsp|asp|sh|cgi)$ - [F,L,NC]
</IfModule>
Options -Indexes -ExecCGI
CONF);

out('        Storage directories ready.', 'ok');

// ---------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------
$appUrl   = rtrim((string) Config::get('app.url', 'https://your-domain.com'), '/');
$cronKey  = Env::getString('CRON_SECRET');

out();
out('  ══════════════════════════════════════════════════════', 'ok');
out('   Installation complete.', 'ok');
out('  ══════════════════════════════════════════════════════', 'ok');
out();
out('   Admin panel   ' . $appUrl . '/admin', 'info');
out('   API base      ' . $appUrl . '/api/v1', 'info');
out('   Health check  ' . $appUrl . '/api/v1/health', 'info');
out();
out('   Next steps:', 'title');
out('     1. Add your Firebase, payment and SMTP credentials in');
out('        Admin → Settings, or in .env.');
out('     2. Register the cron jobs (see docs/DEPLOYMENT.md):');
out('        * * * * * php ' . $basePath . '/scripts/cron.php queue:dispatch', 'dim');
out('        0 2 * * * php ' . $basePath . '/scripts/cron.php backup:database', 'dim');
out('     3. Verify APP_DEBUG=false and APP_ENV=production before going live.');

if ($cronKey !== '') {
    out();
    out('   HTTP cron secret (if you cannot use CLI cron):', 'dim');
    out('     ' . $appUrl . '/api/v1/cron/queue:dispatch?secret=' . $cronKey, 'dim');
}

out();
exit(0);

/**
 * Split a SQL file into statements, honouring quoted strings and comments.
 *
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer     = '';
    $inString   = false;
    $quote      = '';
    $inComment  = false;
    $inBlock    = false;
    $length     = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        // A /* ... */ comment is consumed whole.
        //
        // Without this branch a decorative rule such as
        //   /* ---------------- LIFE ---------------- */
        // is misread: the `--` inside it trips the line-comment rule below,
        // the rest of the line — including the closing `*/` — is swallowed,
        // and the statement reaches MySQL with an unterminated block comment.
        if ($inBlock) {
            if ($char === '*' && $next === '/') {
                $inBlock = false;
                $i++;
            }
            continue;
        }

        if ($inComment) {
            if ($char === "\n") {
                $inComment = false;
                $buffer   .= $char;
            }
            continue;
        }

        if (!$inString && $char === '/' && $next === '*') {
            $inBlock = true;
            $i++;
            continue;
        }

        if (!$inString && $char === '-' && $next === '-') {
            $inComment = true;
            $i++;
            continue;
        }

        if ($inString) {
            if ($char === '\\') {
                $buffer .= $char . $next;
                $i++;
                continue;
            }

            if ($char === $quote) {
                $inString = false;
            }

            $buffer .= $char;
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $inString = true;
            $quote    = $char;
            $buffer  .= $char;
            continue;
        }

        if ($char === ';') {
            $statements[] = $buffer;
            $buffer       = '';
            continue;
        }

        $buffer .= $char;
    }

    if (trim($buffer) !== '') {
        $statements[] = $buffer;
    }

    return $statements;
}

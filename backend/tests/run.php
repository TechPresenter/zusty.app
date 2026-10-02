<?php

declare(strict_types=1);

/**
 * End-to-end API integration suite.
 *
 * Boots the framework for database access, then drives the REST API over a real
 * HTTP socket — the same path the Flutter app takes. Routing, middleware, auth,
 * rate limiting, CORS and security headers are all in scope, because all of them
 * are skipped if you call controllers in-process.
 *
 *   php tests/run.php                      # every suite
 *   php tests/run.php auth discovery       # named suites only
 *   php tests/run.php --list               # what is available
 *   php tests/run.php --url=https://host/api/v1
 *
 * The dev server must be running:  php scripts/serve.php
 *
 * Safe against a seeded development database: the suite creates its own
 * throwaway accounts, prefixes anything it writes with `IT-`, and cleans up
 * after itself. It never mutates seeded demo rows.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

(new Autoloader($basePath . '/storage/cache/classmap.php'))
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

// The harness lives outside app/, so it gets its own require.
require __DIR__ . '/Http.php';
require __DIR__ . '/Suite.php';

App::boot($basePath);

// -----------------------------------------------------------------
// Arguments
// -----------------------------------------------------------------

$args     = array_slice($argv, 1);
$selected = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--url=')) {
        Http::setBaseUrl(substr($arg, 6));
        continue;
    }

    if ($arg === '--list') {
        echo "Available suites:\n";
        foreach (glob(__DIR__ . '/suites/*.php') ?: [] as $file) {
            echo '  ' . basename($file, '.php') . "\n";
        }
        exit(0);
    }

    if (!str_starts_with($arg, '--')) {
        $selected[] = $arg;
    }
}

// -----------------------------------------------------------------
// Preflight — fail loudly rather than reporting 200 phantom failures
// -----------------------------------------------------------------

echo "\n\033[1mZusty API integration suite\033[0m\n";
echo str_repeat('=', 72) . "\n";
echo '  target   ' . Http::baseUrl() . "\n";

$health = Http::get('/health');

if ($health->transportError !== null || $health->status === 0) {
    echo "\n\033[31mThe API is not reachable at " . Http::baseUrl() . "\033[0m\n";
    echo "Start it with:  php scripts/serve.php\n\n";
    exit(2);
}

if ($health->status !== 200) {
    echo "\n\033[31m/health returned {$health->status}\033[0m — the backend is up but unhealthy.\n";
    echo $health->body . "\n\n";
    exit(2);
}

echo '  health   OK (' . round($health->ms) . "ms)\n";

// A suite run against an empty database produces meaningless passes, so say so.
$creatorCount = (int) (\Zuste\Core\Database::scalar(
    "SELECT COUNT(*) FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL"
) ?? 0);

echo '  data     ' . $creatorCount . " approved creators\n";

if ($creatorCount === 0) {
    echo "\n\033[33mWarning: no creator data. Listing, filter, sort and search checks\n";
    echo "cannot prove anything against an empty table. Seed first:\n";
    echo "  php scripts/install.php --seed-only\033[0m\n";
}

// -----------------------------------------------------------------
// Run
// -----------------------------------------------------------------

$files = glob(__DIR__ . '/suites/*.php') ?: [];
sort($files);

$ran = 0;

foreach ($files as $file) {
    $name = basename($file, '.php');

    // Files are prefixed `01_auth.php` for ordering; match on either form.
    $bare = preg_replace('/^\d+_/', '', $name) ?? $name;

    if ($selected !== [] && !in_array($name, $selected, true) && !in_array($bare, $selected, true)) {
        continue;
    }

    require $file;
    $ran++;
}

if ($ran === 0) {
    echo "\n\033[31mNo suites matched.\033[0m Use --list to see what is available.\n";
    exit(2);
}

Suite::summary();
Suite::writeJson($basePath . '/storage/logs/integration-run.json');

echo "\nRun record: storage/logs/integration-run.json\n\n";

exit(Suite::failed() === 0 ? 0 : 1);

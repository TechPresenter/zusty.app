<?php

declare(strict_types=1);

/**
 * Zusty scheduled job runner (CLI).
 *
 *   php scripts/cron.php queue:dispatch
 *   php scripts/cron.php --list
 *   php scripts/cron.php --all
 *
 * Crontab schedules (the exact lines are in docs/DEPLOYMENT.md — they are not
 * repeated here because a crontab step expression contains the sequence that
 * would terminate this comment block):
 *
 *   every minute      queue:dispatch
 *   every 5 minutes   marketing:dispatch, automation:run
 *   every 15 minutes  bookings:expire
 *   hourly            escrow:release, bookings:autocomplete
 *   hourly at :15     privacy:purge-exports
 *   daily 00:10       analytics:rollup
 *   daily 00:30       creators:refresh
 *   daily 01:20       kyc:expiry
 *   daily 01:40       kyc:retention
 *   daily 02:00       backup:database
 *   daily 03:00       tokens:prune
 *   weekly Sun 04:00  logs:rotate
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Logger;
use Zuste\Services\CronRunner;

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

// Long jobs (backups, roll-ups) must not be killed by the default limits.
set_time_limit(0);
ini_set('memory_limit', '512M');

$arguments = array_slice($argv, 1);

if ($arguments === [] || in_array($arguments[0], ['--help', '-h'], true)) {
    echo "\nZusty job runner\n\n";
    echo "  Usage: php scripts/cron.php <job>\n";
    echo "         php scripts/cron.php --list\n";
    echo "         php scripts/cron.php --all\n\n";
    echo "  Jobs:\n";

    foreach (CronRunner::jobs() as $job) {
        echo '    · ' . $job . "\n";
    }

    echo "\n";
    exit(0);
}

if ($arguments[0] === '--list') {
    foreach (CronRunner::jobs() as $job) {
        echo $job . "\n";
    }

    exit(0);
}

$jobs = $arguments[0] === '--all' ? CronRunner::jobs() : [$arguments[0]];

$exitCode = 0;

// A file lock prevents overlapping runs when a minute-ly job runs long.
$lockFile   = $basePath . '/storage/tmp/cron-' . preg_replace('/[^a-z0-9]/i', '_', $jobs[0]) . '.lock';
$lockHandle = @fopen($lockFile, 'c');

if ($lockHandle !== false && !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[skip] A previous run of {$jobs[0]} is still in progress.\n";
    exit(0);
}

foreach ($jobs as $job) {
    if (!CronRunner::exists($job)) {
        fwrite(STDERR, "[error] Unknown job: {$job}\n");
        $exitCode = 1;
        continue;
    }

    $started = microtime(true);

    try {
        $result   = CronRunner::run($job);
        $duration = round((microtime(true) - $started) * 1000);

        printf(
            "[ok] %-24s %6dms  %s\n",
            $job,
            $duration,
            (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    } catch (Throwable $e) {
        $exitCode = 1;

        fwrite(STDERR, sprintf("[fail] %-24s %s\n", $job, $e->getMessage()));

        Logger::error('Cron job threw', [
            'job'   => $job,
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
        ]);
    }
}

if ($lockHandle !== false) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit($exitCode);

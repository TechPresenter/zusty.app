<?php

declare(strict_types=1);

/**
 * Zusty scheduled job runner (CLI).
 *
 *   php scripts/cron.php queue:dispatch
 *   php scripts/cron.php --list
 *   php scripts/cron.php --all
 *   php scripts/cron.php --due
 *
 * --due runs the enabled jobs whose schedule (scheduled_jobs.schedule_hint,
 * editable under System → Jobs) has come round since the last --due run. One
 * crontab entry that runs it every minute replaces the per-job entries below,
 * which is the practical setup on shared hosting such as Hostinger.
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
use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Helpers\CronExpression;
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
    echo "         php scripts/cron.php --all\n";
    echo "         php scripts/cron.php --due     (run every minute: starts the jobs that are due)\n\n";
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

$jobs = match ($arguments[0]) {
    '--all' => CronRunner::jobs(),
    '--due' => dueJobs($basePath),
    default => [$arguments[0]],
};

$exitCode = 0;

foreach ($jobs as $job) {
    if (!CronRunner::exists($job)) {
        fwrite(STDERR, "[error] Unknown job: {$job}\n");
        $exitCode = 1;
        continue;
    }

    // One lock per job prevents overlapping runs when a job runs long,
    // without a slow job (the backup) holding up the others (the mail queue).
    $lockHandle = @fopen($basePath . '/storage/tmp/cron-' . preg_replace('/[^a-z0-9]/i', '_', $job) . '.lock', 'c');

    if ($lockHandle !== false && !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        echo "[skip] A previous run of {$job} is still in progress.\n";
        fclose($lockHandle);
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
    } finally {
        if ($lockHandle !== false) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

exit($exitCode);

/**
 * The enabled jobs whose schedule has come round since the previous --due run.
 *
 * Minutes a late or coarser crontab skipped are caught up, for at most an
 * hour, and no job runs twice for the same minute: the last minute evaluated
 * is kept in storage/tmp/cron-due.state under an exclusive lock.
 *
 * @return list<string>
 */
function dueJobs(string $basePath): array
{
    $now   = new DateTimeImmutable('now');
    $nowTs = $now->getTimestamp() - ($now->getTimestamp() % 60);

    // Read the schedules before touching the state, so a database outage
    // leaves the window open and the next run catches the missed minutes up.
    $schedules = [];

    foreach (Database::select('SELECT `job_key`, `schedule_hint` FROM `scheduled_jobs` WHERE `is_enabled` = 1 ORDER BY `id`') as $row) {
        $job        = (string) $row['job_key'];
        $expression = (string) $row['schedule_hint'];

        if (!CronRunner::exists($job)) {
            continue;
        }

        if (!CronExpression::isValid($expression)) {
            fwrite(STDERR, "[warn] {$job}: schedule '{$expression}' is not a valid cron expression, so it never runs.\n");
            continue;
        }

        $schedules[$job] = $expression;
    }

    $handle = @fopen($basePath . '/storage/tmp/cron-due.state', 'c+');

    if ($handle === false) {
        fwrite(STDERR, "[error] storage/tmp is not writable, so due jobs cannot be tracked.\n");

        return [];
    }

    flock($handle, LOCK_EX);
    $last = (int) trim((string) stream_get_contents($handle));
    $from = $last > 0 ? max($last + 60, $nowTs - 3600) : $nowTs;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) max($last, $nowTs));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    $due = [];

    foreach ($schedules as $job => $expression) {
        for ($minute = $from; $minute <= $nowTs; $minute += 60) {
            if (CronExpression::matches($expression, $now->setTimestamp($minute))) {
                $due[] = $job;
                break;
            }
        }
    }

    return $due;
}

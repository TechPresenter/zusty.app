<?php

declare(strict_types=1);

/**
 * Liveness and health heartbeat.
 *
 * Answers the question monitoring actually needs: "is anything silently
 * broken right now?" — as opposed to /health, which only proves the web
 * server and database are up.
 *
 *     php scripts/heartbeat.php              # human-readable
 *     php scripts/heartbeat.php --json       # for a monitoring agent
 *     php scripts/heartbeat.php --ping=URL   # also ping an uptime service
 *
 * Run it from cron every 10 minutes. Exit codes:
 *   0 = healthy   1 = degraded   2 = critical
 *
 * The last result is always written to storage/logs/heartbeat.json, so the
 * admin dashboard and an external agent can read the same state without
 * re-running the checks.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
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

$asJson  = in_array('--json', $argv, true);
$pingUrl = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--ping=')) {
        $pingUrl = substr($arg, 7);
    }
}

/** @var list<array{check:string,level:string,detail:string}> */
$findings = [];

/** @param 'ok'|'warn'|'critical' $level */
function record(string $check, string $level, string $detail): void
{
    global $findings;
    $findings[] = ['check' => $check, 'level' => $level, 'detail' => $detail];
}

// -----------------------------------------------------------------
// Database
// -----------------------------------------------------------------

try {
    $started = microtime(true);
    Database::scalar('SELECT 1');
    $ms = (microtime(true) - $started) * 1000;

    $ms < 200
        ? record('database', 'ok', sprintf('%.0fms', $ms))
        : record('database', 'warn', sprintf('slow ping: %.0fms', $ms));
} catch (\Throwable $e) {
    record('database', 'critical', $e->getMessage());

    // Nothing else can be checked without a database.
    finish($findings, $asJson, $pingUrl, $basePath);
}

// -----------------------------------------------------------------
// Cron
//
// The queue job runs every minute. If its last run is more than 10 minutes
// old, cron itself has stopped — and no other alarm will tell you, because
// the alarms are also delivered by the queue.
// -----------------------------------------------------------------

$queue = Database::selectOne(
    "SELECT `last_run_at`, `last_status`, `failure_count`
       FROM `scheduled_jobs` WHERE `job_key` = 'queue:dispatch'"
);

if ($queue === null) {
    record('cron', 'warn', 'queue:dispatch is not registered');
} elseif ($queue['last_run_at'] === null) {
    record('cron', 'critical', 'queue:dispatch has never run — the crontab is not installed');
} else {
    $ageMinutes = (time() - strtotime((string) $queue['last_run_at'])) / 60;

    if ($ageMinutes > 15) {
        record('cron', 'critical', sprintf(
            'queue:dispatch last ran %.0f minutes ago — cron has stopped',
            $ageMinutes
        ));
    } elseif ($ageMinutes > 5) {
        record('cron', 'warn', sprintf('queue:dispatch is %.0f minutes behind', $ageMinutes));
    } else {
        record('cron', 'ok', sprintf('queue %.0fm ago', $ageMinutes));
    }
}

$failing = Database::column(
    "SELECT `job_key` FROM `scheduled_jobs` WHERE `last_status` = 'failed'"
);

$failing === []
    ? record('jobs', 'ok', 'none failing')
    : record('jobs', 'warn', 'failing: ' . implode(', ', $failing));

// A job stuck in `running` means a previous invocation died holding its lock.
$stuck = Database::column(
    "SELECT `job_key` FROM `scheduled_jobs`
      WHERE `last_status` = 'running'
        AND `last_run_at` < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
);

$stuck === []
    ? record('locks', 'ok', 'no stuck jobs')
    : record('locks', 'critical', 'stuck since last run: ' . implode(', ', $stuck));

// -----------------------------------------------------------------
// Outbound queues
//
// A queue that is growing means messages are not reaching users: password
// resets, booking notifications, OTPs.
// -----------------------------------------------------------------

foreach ([
    'email_queue' => 'email',
    'push_queue'  => 'push',
    'sms_queue'   => 'sms',
] as $table => $label) {
    try {
        $pending = (int) (Database::scalar(
            "SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'pending'"
        ) ?? 0);

        $stale = (int) (Database::scalar(
            "SELECT COUNT(*) FROM `{$table}`
              WHERE `status` = 'pending' AND `created_at` < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        ) ?? 0);

        if ($stale > 0) {
            record($label . ' queue', 'critical', "{$stale} message(s) pending for over 30 minutes");
        } elseif ($pending > 500) {
            record($label . ' queue', 'warn', "{$pending} pending — draining slower than it fills");
        } else {
            record($label . ' queue', 'ok', "{$pending} pending");
        }
    } catch (\Throwable) {
        // Table absent in this schema version; not a health signal.
    }
}

// -----------------------------------------------------------------
// Payments
//
// A webhook that arrived but never processed is money the platform does not
// know it received. This is the highest-consequence silent failure here.
// -----------------------------------------------------------------

try {
    $unprocessed = (int) (Database::scalar(
        "SELECT COUNT(*) FROM `payment_webhooks`
          WHERE `status` IN ('received', 'failed')
            AND `created_at` < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    ) ?? 0);

    $unprocessed === 0
        ? record('payment webhooks', 'ok', 'all processed')
        : record('payment webhooks', 'critical', "{$unprocessed} webhook(s) unprocessed for over 15 minutes");

    // A payment left 'created' has taken a customer to checkout and never
    // come back. A few are normal (abandoned carts); a spike is not.
    $stranded = (int) (Database::scalar(
        "SELECT COUNT(*) FROM `payments`
          WHERE `status` = 'created' AND `created_at` < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
    ) ?? 0);

    $stranded > 20
        ? record('stranded payments', 'warn', "{$stranded} orders created but never resolved in the last hours")
        : record('stranded payments', 'ok', (string) $stranded);
} catch (\Throwable $e) {
    record('payments', 'warn', $e->getMessage());
}

// -----------------------------------------------------------------
// Disk
// -----------------------------------------------------------------

$free  = @disk_free_space($basePath);
$total = @disk_total_space($basePath);

if (is_float($free) && is_float($total) && $total > 0) {
    $percentFree = $free / $total * 100;
    $gbFree      = $free / 1024 ** 3;

    if ($percentFree < 5) {
        record('disk', 'critical', sprintf('%.1f%% free (%.1f GB)', $percentFree, $gbFree));
    } elseif ($percentFree < 15) {
        record('disk', 'warn', sprintf('%.1f%% free (%.1f GB)', $percentFree, $gbFree));
    } else {
        record('disk', 'ok', sprintf('%.1f%% free', $percentFree));
    }
}

// -----------------------------------------------------------------
// Backups
// -----------------------------------------------------------------

try {
    $latest = Database::selectOne(
        'SELECT `created_at`, `status` FROM `backups` ORDER BY `id` DESC LIMIT 1'
    );

    if ($latest === null) {
        record('backups', 'warn', 'no backup has ever been recorded');
    } else {
        $ageHours = (time() - strtotime((string) $latest['created_at'])) / 3600;

        if ($ageHours > 48) {
            record('backups', 'critical', sprintf('newest backup is %.0f hours old', $ageHours));
        } elseif ($ageHours > 26) {
            record('backups', 'warn', sprintf('newest backup is %.0f hours old', $ageHours));
        } else {
            record('backups', 'ok', sprintf('%.0fh ago', $ageHours));
        }
    }
} catch (\Throwable) {
    record('backups', 'warn', 'backup table unavailable');
}

// -----------------------------------------------------------------
// Error rate
// -----------------------------------------------------------------

try {
    $errors = (int) (Database::scalar(
        "SELECT COUNT(*) FROM `api_logs`
          WHERE `status_code` >= 500 AND `created_at` > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    ) ?? 0);

    $requests = (int) (Database::scalar(
        'SELECT COUNT(*) FROM `api_logs` WHERE `created_at` > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    ) ?? 0);

    if ($requests > 20 && $errors / max($requests, 1) > 0.05) {
        record('error rate', 'critical', sprintf(
            '%d of %d requests returned 5xx (%.1f%%)',
            $errors,
            $requests,
            $errors / $requests * 100
        ));
    } else {
        record('error rate', 'ok', "{$errors} 5xx in the last 15 minutes");
    }
} catch (\Throwable) {
    // api_logs may be pruned or disabled.
}

finish($findings, $asJson, $pingUrl, $basePath);

// -----------------------------------------------------------------

/**
 * @param list<array{check:string,level:string,detail:string}> $findings
 */
function finish(array $findings, bool $asJson, ?string $pingUrl, string $basePath): never
{
    $critical = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'critical'));
    $warnings = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'warn'));

    $status = $critical !== [] ? 'critical' : ($warnings !== [] ? 'degraded' : 'healthy');

    $payload = [
        'status'     => $status,
        'checked_at' => date('c'),
        'findings'   => $findings,
    ];

    // Always persist, even on failure — the last known state is what an
    // operator wants when the box stops answering.
    @mkdir($basePath . '/storage/logs', 0775, true);
    @file_put_contents(
        $basePath . '/storage/logs/heartbeat.json',
        (string) json_encode($payload, JSON_PRETTY_PRINT)
    );

    if ($pingUrl !== null && $status === 'healthy') {
        // Dead-man's-switch services (healthchecks.io, Better Uptime) alert on
        // the ABSENCE of a ping, so only ping when genuinely healthy.
        $handle = curl_init($pingUrl);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        @curl_exec($handle);
        curl_close($handle);
    }

    if ($asJson) {
        echo json_encode($payload, JSON_PRETTY_PRINT), "\n";
    } else {
        // Only print when something is wrong: cron mails any output, and a
        // heartbeat that chats every 10 minutes trains people to ignore it.
        if ($status !== 'healthy') {
            echo '[' . date('Y-m-d H:i:s') . "] {$status}\n";

            foreach ([...$critical, ...$warnings] as $finding) {
                printf("  %-8s %-20s %s\n", $finding['level'], $finding['check'], $finding['detail']);
            }
        }
    }

    exit(match ($status) {
        'critical' => 2,
        'degraded' => 1,
        default    => 0,
    });
}

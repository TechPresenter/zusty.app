<?php

declare(strict_types=1);

/**
 * Deployment helper — the parts of deploy.sh that need the application booted.
 *
 * Kept as one small CLI so the shell script stays readable and every database
 * operation goes through the same tested Core classes the app itself uses.
 *
 *   php scripts/deploy_helper.php check              connectivity + schema sanity
 *   php scripts/deploy_helper.php maintenance:on     enable maintenance mode
 *   php scripts/deploy_helper.php maintenance:off    disable maintenance mode
 *   php scripts/deploy_helper.php maintenance:status report the current state
 *   php scripts/deploy_helper.php cache:rebuild      clear and warm caches
 *   php scripts/deploy_helper.php restore <file> <confirmation>
 *   php scripts/deploy_helper.php summary            counts for the deploy report
 *
 * Exit code 0 means success; anything else aborts the deployment.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI only.\n");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Cache;
use Zuste\Core\Database;

(new Autoloader())
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

$command = $argv[1] ?? '';

if ($command === '' || in_array($command, ['-h', '--help', 'help'], true)) {
    fwrite(STDOUT, "Usage: php scripts/deploy_helper.php <command>\n\n");
    fwrite(STDOUT, "  check | maintenance:on | maintenance:off | maintenance:status\n");
    fwrite(STDOUT, "  cache:rebuild | restore <file> <confirmation> | summary\n");
    exit(0);
}

try {
    App::boot($basePath);
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not boot the application: ' . $e->getMessage() . "\n");
    exit(1);
}

/** Write a setting straight to the table, then drop the cached copy. */
function setSetting(string $group, string $key, string $value): void
{
    $affected = Database::execute(
        'UPDATE `settings` SET `value` = :value, `updated_at` = NOW()
          WHERE `group_key` = :group AND `setting_key` = :key',
        ['value' => $value, 'group' => $group, 'key' => $key]
    );

    if ($affected === 0) {
        // The row may be absent on an older install; create it so the toggle works.
        Database::execute(
            'INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `is_public`, `is_encrypted`, `sort_order`, `updated_at`)
             VALUES (:group, :key, :value, :type, :label, 1, 0, 90, NOW())
             ON DUPLICATE KEY UPDATE `value` = :value, `updated_at` = NOW()',
            [
                'group' => $group,
                'key'   => $key,
                'value' => $value,
                'type'  => $key === 'maintenance_mode' ? 'boolean' : 'string',
                'label' => ucwords(str_replace('_', ' ', $key)),
            ]
        );
    }

    // Settings are cached; a stale copy would keep the site down after a deploy.
    Cache::forgetGroup('settings');
    Cache::forget('settings:all');
}

try {
    switch ($command) {
        case 'check':
            $version = (string) Database::scalar('SELECT VERSION()');
            $tables  = (int) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
            );

            printf("  server   : %s\n", $version);
            printf("  database : %s\n", (string) Database::scalar('SELECT DATABASE()'));
            printf("  tables   : %d\n", $tables);

            if ($tables === 0) {
                fwrite(STDERR, "  The database is empty. Run install.php before deploying.\n");
                exit(1);
            }

            // The migrations ledger must exist, or install.php cannot tell what
            // has already been applied and would try to re-run everything.
            $ledger = Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'migrations'"
            );

            if ((int) $ledger === 0) {
                fwrite(STDERR, "  The `migrations` table is missing. Run install.php first.\n");
                exit(1);
            }

            printf("  applied  : %d migration(s)\n", (int) Database::scalar('SELECT COUNT(*) FROM `migrations`'));
            exit(0);

        case 'maintenance:on':
            setSetting('general', 'maintenance_mode', '1');
            echo "  maintenance mode ON\n";
            exit(0);

        case 'maintenance:off':
            setSetting('general', 'maintenance_mode', '0');
            echo "  maintenance mode OFF\n";
            exit(0);

        case 'maintenance:status':
            $on = \Zuste\Core\Config::settingBool('general', 'maintenance_mode', false);
            echo '  maintenance mode is ' . ($on ? 'ON' : 'OFF') . "\n";
            exit($on ? 1 : 0);

        case 'cache:rebuild':
            $cleared = Cache::flush();
            $pruned  = Cache::prune();

            printf("  cleared %d entr(ies), pruned %d expired\n", $cleared, $pruned);

            // Touch the settings so the next request repopulates the hot path
            // rather than the first real visitor paying for it.
            \Zuste\Core\Config::settingString('general', 'app_name', 'Zusty');
            echo "  settings warmed\n";
            exit(0);

        case 'restore':
            $file         = $argv[2] ?? '';
            $confirmation = $argv[3] ?? '';

            if ($file === '' || !is_readable($file)) {
                fwrite(STDERR, "  Backup file not readable: {$file}\n");
                exit(1);
            }

            $expected = (string) Database::scalar('SELECT DATABASE()');

            if ($confirmation !== $expected && $confirmation !== '--force') {
                fwrite(STDERR, "  Confirmation did not match the database name.\n");
                exit(1);
            }

            $sql = str_ends_with($file, '.gz')
                ? (string) gzdecode((string) file_get_contents($file))
                : (string) file_get_contents($file);

            if ($sql === '') {
                fwrite(STDERR, "  The backup is empty or could not be decompressed.\n");
                exit(1);
            }

            $pdo = Database::connection();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            try {
                $pdo->exec($sql);
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable $e) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                fwrite(STDERR, '  Restore failed: ' . $e->getMessage() . "\n");
                exit(1);
            }

            Cache::flush();
            echo "  restored from " . basename($file) . "\n";
            exit(0);

        case 'summary':
            $rows = [
                'admins'   => 'SELECT COUNT(*) FROM `admins` WHERE `deleted_at` IS NULL',
                'users'    => 'SELECT COUNT(*) FROM `users` WHERE `deleted_at` IS NULL',
                'creators' => 'SELECT COUNT(*) FROM `creators` WHERE `deleted_at` IS NULL',
                'bookings' => 'SELECT COUNT(*) FROM `bookings`',
            ];

            foreach ($rows as $label => $sql) {
                printf("  %-10s %d\n", $label, (int) Database::scalar($sql));
            }
            exit(0);

        default:
            fwrite(STDERR, "Unknown command: {$command}\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '  ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

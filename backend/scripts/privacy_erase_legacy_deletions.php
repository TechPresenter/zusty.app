<?php

declare(strict_types=1);

/**
 * Erase the accounts that were "deleted" in the app before account deletion
 * erased anything. One-off, safe to re-run.
 *
 *   php scripts/privacy_erase_legacy_deletions.php --dry-run   # list them, change nothing
 *   php scripts/privacy_erase_legacy_deletions.php             # erase them
 *   php scripts/privacy_erase_legacy_deletions.php --user=ID   # only that account (with or without --dry-run)
 *
 * Before migration 043 the app's "Delete account" only hid the account: its
 * name, contact details, profile and files stayed in place. Those accounts are
 * recognised by what that path left behind: status 'deleted', a brand or
 * creator that is not already an anonymous tombstone, the audit row it wrote
 * (action 'account.deleted', "Account deletion requested.") and no deletion
 * record. Accounts staff deleted from the admin panel never wrote that row and
 * are left alone; they are listed under Admin → Privacy requests → Act on a
 * request, to be erased one by one.
 *
 * Each one goes through AccountDeletionService::eraseLegacyDeletion(): the
 * same erasure as today's, recorded as verified_via 'legacy', without the
 * blockers (the owner asked long ago), the goodbye email or a session to end.
 * Its clean-up (files, exports, KYC documents, the Firebase sign-in record) is
 * retried by privacy:finish-deletions like any other.
 *
 * Run it once after deploying, with --dry-run first. It prints account
 * numbers, types and masked email addresses only.
 *
 * Exit codes: 0 done (or a dry run), 1 when any erasure failed.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Database;
use Zuste\Helpers\Str;
use Zuste\Services\AccountDeletionService;

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

$arguments = array_slice($argv, 1);
$dryRun    = in_array('--dry-run', $arguments, true);
$onlyUser  = 0;

foreach ($arguments as $argument) {
    if (preg_match('/^--user=(\d+)$/', $argument, $m) === 1) {
        $onlyUser = (int) $m[1];
    } elseif ($argument !== '--dry-run') {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        fwrite(STDERR, "Usage: php scripts/privacy_erase_legacy_deletions.php [--dry-run] [--user=ID]\n");
        exit(1);
    }
}

$sql = "SELECT u.`id`, u.`account_type`, u.`email`, u.`deleted_at`
          FROM `users` u
         WHERE u.`status` = 'deleted'
           AND u.`account_type` IN ('brand', 'creator')
           AND u.`name` <> 'Deleted user'
           AND EXISTS (SELECT 1 FROM `activity_logs` l
                        WHERE l.`action` = 'account.deleted'
                          AND l.`description` = 'Account deletion requested.'
                          AND l.`subject_type` = 'user' AND l.`subject_id` = u.`id`)
           AND NOT EXISTS (SELECT 1 FROM `account_deletion_requests` d WHERE d.`user_id` = u.`id`)"
    . ($onlyUser > 0 ? ' AND u.`id` = :only' : '')
    . ' ORDER BY u.`id`';

$accounts = Database::select($sql, $onlyUser > 0 ? ['only' => $onlyUser] : []);

echo "\nAccounts closed in the app before erasure existed\n" . str_repeat('=', 56) . "\n\n";

if ($accounts === []) {
    echo $onlyUser > 0
        ? "Account #{$onlyUser} is not one of them (already erased, never closed that way, or not found).\n\n"
        : "None left. Nothing to do.\n\n";
    exit(0);
}

foreach ($accounts as $account) {
    $email = trim((string) ($account['email'] ?? ''));

    printf(
        "  #%-8d %-8s closed %s  %s\n",
        (int) $account['id'],
        (string) $account['account_type'],
        $account['deleted_at'] !== null ? date('j M Y', (int) strtotime((string) $account['deleted_at'])) : '(no date)',
        $email !== '' ? Str::maskEmail($email) : '(no email)'
    );
}

echo "\n" . count($accounts) . ' account' . (count($accounts) === 1 ? '' : 's') . ".\n";

if ($dryRun) {
    echo "Dry run: nothing was changed. Run again without --dry-run to erase them.\n\n";
    exit(0);
}

$erased = 0;
$failed = 0;

foreach ($accounts as $account) {
    $userId = (int) $account['id'];

    try {
        $summary = AccountDeletionService::eraseLegacyDeletion($userId);
        $erased++;

        echo '  erased #' . $userId . (isset($summary['deletion_id']) ? ' (deletion #' . (int) $summary['deletion_id'] . ')' : '') . "\n";
    } catch (Throwable $e) {
        $failed++;

        fwrite(STDERR, '  FAILED #' . $userId . ': ' . $e->getMessage() . "\n");
    }
}

echo "\nErased {$erased}, failed {$failed}.\n";

if ($failed > 0) {
    echo "Fix what failed and run the script again; it only picks up accounts still left.\n";
}

echo "\n";
exit($failed > 0 ? 1 : 0);

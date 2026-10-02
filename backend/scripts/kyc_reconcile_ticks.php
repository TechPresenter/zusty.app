<?php

declare(strict_types=1);

/**
 * Find (and optionally remove) blue ticks nobody granted.
 *
 *     php scripts/kyc_reconcile_ticks.php              # dry run (default): list only
 *     php scripts/kyc_reconcile_ticks.php --apply      # revoke every listed tick
 *     php scripts/kyc_reconcile_ticks.php --json       # machine-readable listing
 *
 * Before migration 044 the tick was a side effect: approving a creator's KYC
 * set `creators.is_verified`, and the admin "verified" switch awarded it with
 * no KYC at all. Since 044 the tick has one path — KycService::grantBadge(),
 * which needs an approved KYC and an administrator — and its state lives on
 * the KYC profile (`badge_status = granted`).
 *
 * Every account whose tick is showing without a granted badge is therefore a
 * tick from the old model. The migration deliberately did not reset them (it
 * must not rewrite seeded or live data on its own). This script lists them,
 * split by whether their KYC is at least approved — an operator may prefer to
 * grant those properly from the KYC panel rather than revoke — and, only with
 * --apply, revokes them through the same code path as a manual revocation, so
 * each one is recorded in the audit trail and the badge history.
 *
 * Users are not notified by --apply: these ticks were never a decision anyone
 * communicated to them, and a batch of "your tick was removed" messages for a
 * data clean-up would read as a sanction.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Database;
use Zuste\Services\CreatorBadgeService;
use Zuste\Services\KycService;

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

$args  = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$json  = in_array('--json', $args, true);

// Every account showing a tick: a creator's `is_verified`, or anyone's
// `users.has_blue_tick` — joined to the KYC profile that should explain it.
$rows = Database::select(
    "SELECT u.`id` AS `user_id`, u.`name`, u.`email`, u.`account_type`,
            u.`has_blue_tick`, c.`id` AS `creator_id`, c.`is_verified` AS `creator_verified`,
            p.`id` AS `profile_id`, p.`reference`, p.`status` AS `kyc_status`, p.`badge_status`
       FROM `users` u
       LEFT JOIN `creators` c ON c.`user_id` = u.`id` AND c.`deleted_at` IS NULL
       LEFT JOIN `kyc_profiles` p
              ON p.`user_id` = u.`id`
             AND p.`kind` = CASE WHEN u.`account_type` = 'creator' THEN 'creator' ELSE 'brand' END
             AND p.`deleted_at` IS NULL
      WHERE (u.`has_blue_tick` = 1 OR c.`is_verified` = 1)
        AND COALESCE(p.`badge_status`, 'none') <> 'granted'
      ORDER BY u.`account_type`, u.`id`"
);

$approved   = array_values(array_filter($rows, static fn (array $r): bool => $r['kyc_status'] === 'approved'));
$unapproved = array_values(array_filter($rows, static fn (array $r): bool => $r['kyc_status'] !== 'approved'));

if ($json && !$apply) {
    echo json_encode([
        'total'            => count($rows),
        'kyc_approved'     => count($approved),
        'kyc_not_approved' => count($unapproved),
        'accounts'         => array_map(static fn (array $r): array => [
            'user_id'      => (int) $r['user_id'],
            'account_type' => $r['account_type'],
            'reference'    => $r['reference'],
            'kyc_status'   => $r['kyc_status'] ?? 'none',
            'badge_status' => $r['badge_status'] ?? 'none',
        ], $rows),
    ], JSON_PRETTY_PRINT) . "\n";

    exit(0);
}

echo "\nBlue ticks without a granted badge" . ($apply ? ' — APPLYING' : ' — dry run') . "\n";
echo str_repeat('-', 72) . "\n";

foreach (['KYC approved (could be granted from the KYC panel instead)' => $approved,
          'KYC not approved (no basis for a tick)'                       => $unapproved] as $title => $group) {
    echo "\n{$title}: " . count($group) . "\n";

    foreach ($group as $row) {
        printf(
            "  #%-6d %-8s %-32s %-14s kyc=%-13s badge=%s\n",
            (int) $row['user_id'],
            (string) $row['account_type'],
            mb_substr((string) $row['email'], 0, 32),
            (string) ($row['reference'] ?? '—'),
            (string) ($row['kyc_status'] ?? 'none'),
            (string) ($row['badge_status'] ?? 'none')
        );
    }
}

echo "\nTotal: " . count($rows) . "\n";

if (!$apply) {
    echo "\nNothing was changed. Re-run with --apply to revoke these ticks.\n\n";
    exit(0);
}

$revoked = 0;

foreach ($rows as $row) {
    $reason = 'Reconciliation: tick predates the manual blue-tick approval and no badge was granted.';

    try {
        Database::transaction(static function () use ($row, $reason): void {
            Database::update('users', ['has_blue_tick' => 0, 'blue_tick_at' => null], ['id' => (int) $row['user_id']]);

            if ($row['creator_id'] !== null) {
                CreatorBadgeService::revoke((int) $row['creator_id'], 'creator_verified', [
                    'actor_type' => 'system',
                    'reason'     => $reason,
                ]);
                // A legacy tick has no badge row for revoke() to act on.
                Database::update('creators', ['is_verified' => 0, 'verified_at' => null], ['id' => (int) $row['creator_id']]);
            }

            // Recorded on the KYC trail when there is one. No notification
            // (see the header).
            if ($row['profile_id'] !== null) {
                KycService::recordEvent((int) $row['profile_id'], 'badge_revoked', [
                    'actor_type' => 'system',
                    'note'       => $reason,
                    'metadata'   => ['source' => 'kyc_reconcile_ticks'],
                ]);
            }
        });

        $revoked++;
    } catch (\Throwable $e) {
        echo '  ! #' . (int) $row['user_id'] . ': ' . $e->getMessage() . "\n";
    }
}

echo "\nRevoked {$revoked} of " . count($rows) . " ticks.\n\n";

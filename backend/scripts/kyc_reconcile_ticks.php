<?php

declare(strict_types=1);

/**
 * Find (and optionally fix) verification state nobody decided.
 *
 *     php scripts/kyc_reconcile_ticks.php              # dry run (default): list only
 *     php scripts/kyc_reconcile_ticks.php --apply      # fix every listed row
 *     php scripts/kyc_reconcile_ticks.php --json       # machine-readable listing
 *
 * Before migration 044 the tick was a side effect: approving a creator's KYC
 * set `creators.is_verified`, and the admin "verified" switch awarded it with
 * no KYC at all. Since 044 the tick has one path — KycService::grantBadge(),
 * which needs an approved KYC and an administrator — and its state lives on
 * the KYC profile (`badge_status = granted`). Seeded and legacy data also
 * carries KYC-derived public badges and `users` flags with no approved KYC
 * behind them. The migrations deliberately did not reset any of it (they must
 * not rewrite seeded or live data on their own). This script lists it:
 *
 *   1. ticks showing (users.has_blue_tick / creators.is_verified) with no
 *      granted badge — split by whether the KYC is at least approved, since an
 *      operator may prefer to grant those properly from the KYC panel;
 *   2. ticks granted on a KYC that is no longer approved and current
 *      (rejected, suspended, expired or purged);
 *   3. active `creator_verified` badge rows (the creator's public tick) that
 *      no granted, current tick backs;
 *   4. KYC-derived public badges (identity_verified, kyc_verified,
 *      business_verified) that disagree with the account's KYC standing — an
 *      active badge with no current approved KYC, or the reverse;
 *   5. `users.kyc_status`, `is_identity_verified` and `is_business_verified`
 *      that disagree with the account's KYC profile.
 *
 * With --apply it fixes 1-5 through the same code paths a manual decision
 * uses, so every change is in the badge history, the KYC trail (when there is
 * a profile) or the activity log. Users are not notified: none of this was a
 * decision anyone communicated to them, and a batch of "your tick was
 * removed" messages for a data clean-up would read as a sanction.
 *
 * Left alone, and only reported:
 *
 *   * approvals past their expiry date not yet expired — the expiry job (or
 *     the owner's next visit) expires them and tells the user, as designed;
 *     their accounts are excluded from 2-5 until then;
 *   * accounts with documents from the retired single-document flow and no
 *     verification profile — their `kyc_status` is the only record of that
 *     review, and resetting it would also make the retention job delete those
 *     documents;
 *   * deleted accounts whose KYC data was never erased — the kyc:retention
 *     job erases them.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Services\ActivityLogger;
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

// What "the KYC is approved and current" means everywhere below — the same
// predicate as KycService::isVerified() and CreatorBadgeService::kycStanding().
$current = "p.`status` = 'approved' AND p.`purged_at` IS NULL
            AND (p.`expires_on` IS NULL OR p.`expires_on` >= CURDATE())";

// -----------------------------------------------------------------
// 0. Approvals past their date that nobody has expired yet (report only)
// -----------------------------------------------------------------

$lapsed = Database::select(
    "SELECT p.`id` AS `profile_id`, p.`user_id`, p.`reference`, p.`kind`, p.`expires_on`, u.`email`
       FROM `kyc_profiles` p
       JOIN `users` u ON u.`id` = p.`user_id`
      WHERE p.`deleted_at` IS NULL AND p.`status` = 'approved'
        AND p.`expires_on` IS NOT NULL AND p.`expires_on` < CURDATE()
      ORDER BY p.`user_id`"
);
$lapsedUsers = array_fill_keys(array_map(static fn (array $r): int => (int) $r['user_id'], $lapsed), true);

// -----------------------------------------------------------------
// 1. Ticks showing with no granted badge (the original reconciliation)
// -----------------------------------------------------------------

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
$tickUsers  = array_fill_keys(array_map(static fn (array $r): int => (int) $r['user_id'], $rows), true);

// -----------------------------------------------------------------
// 2. Ticks granted on a KYC that is not approved and current
// -----------------------------------------------------------------

$grantedStale = array_values(array_filter(Database::select(
    "SELECT u.`id` AS `user_id`, u.`email`, u.`account_type`, p.`id` AS `profile_id`, p.`reference`,
            p.`status` AS `kyc_status`, p.`badge_status`, p.`expires_on`, p.`purged_at`
       FROM `kyc_profiles` p
       JOIN `users` u ON u.`id` = p.`user_id`
      WHERE p.`deleted_at` IS NULL AND p.`badge_status` = 'granted'
        AND NOT ({$current})
      ORDER BY u.`id`"
), static fn (array $r): bool => !isset($lapsedUsers[(int) $r['user_id']])));

foreach ($grantedStale as $row) {
    $tickUsers[(int) $row['user_id']] = true;
}

// -----------------------------------------------------------------
// 3. Active creator_verified rows no granted, current tick backs
// -----------------------------------------------------------------

$badgeTicks = array_values(array_filter(Database::select(
    "SELECT b.`creator_id`, c.`user_id`, u.`email`, u.`account_type`,
            p.`id` AS `profile_id`, p.`reference`, p.`status` AS `kyc_status`, p.`badge_status`
       FROM `creator_badges` b
       JOIN `creators` c ON c.`id` = b.`creator_id`
       JOIN `users` u ON u.`id` = c.`user_id`
       LEFT JOIN `kyc_profiles` p
              ON p.`user_id` = c.`user_id` AND p.`kind` = 'creator' AND p.`deleted_at` IS NULL
      WHERE b.`badge` = 'creator_verified' AND b.`status` = 'active'
        AND (b.`expires_at` IS NULL OR b.`expires_at` > NOW())
        AND NOT (p.`id` IS NOT NULL AND p.`badge_status` = 'granted' AND {$current})
      ORDER BY c.`user_id`"
), static fn (array $r): bool => !isset($lapsedUsers[(int) $r['user_id']]) && !isset($tickUsers[(int) $r['user_id']])));

// -----------------------------------------------------------------
// 4. KYC-derived public badges against the KYC standing
// -----------------------------------------------------------------

/** @var array<int,array{creator:bool,brand:bool}> $standing user id => kinds with a current approval */
$standing = [];

foreach (Database::select(
    "SELECT p.`user_id`, p.`kind` FROM `kyc_profiles` p WHERE p.`deleted_at` IS NULL AND {$current}"
) as $row) {
    $standing[(int) $row['user_id']] ??= ['creator' => false, 'brand' => false];
    $standing[(int) $row['user_id']][(string) $row['kind']] = true;
}

$catalogue  = CreatorBadgeService::catalogue();
$kycBadges  = array_values(array_filter(CreatorBadgeService::KYC_BADGES, static fn (string $b): bool => isset($catalogue[$b])));
$qualifies  = static function (int $userId, string $badge) use ($standing): bool {
    $kinds = $standing[$userId] ?? ['creator' => false, 'brand' => false];

    return $badge === 'business_verified' ? $kinds['brand'] : $kinds['creator'];
};

$staleBadges   = [];
$missingBadges = [];

if ($kycBadges !== []) {
    [$in, $badgeBindings] = Database::inClause($kycBadges);

    // Active rows with no current approval behind them (deleted creators
    // included: their rows must lose the badges too).
    foreach (Database::select(
        'SELECT b.`creator_id`, b.`badge`, c.`user_id`, u.`email`
           FROM `creator_badges` b
           JOIN `creators` c ON c.`id` = b.`creator_id`
           JOIN `users` u ON u.`id` = c.`user_id`
          WHERE b.`status` = \'active\' AND b.`badge` IN ' . $in . '
          ORDER BY c.`user_id`, b.`badge`',
        $badgeBindings
    ) as $row) {
        if (!isset($lapsedUsers[(int) $row['user_id']]) && !$qualifies((int) $row['user_id'], (string) $row['badge'])) {
            $staleBadges[] = $row;
        }
    }

    // Live creators whose current approval is not reflected by the badge.
    foreach (Database::select(
        'SELECT c.`id` AS `creator_id`, c.`user_id`, u.`email` FROM `creators` c
           JOIN `users` u ON u.`id` = c.`user_id`
          WHERE c.`deleted_at` IS NULL
          ORDER BY c.`user_id`'
    ) as $creator) {
        $userId = (int) $creator['user_id'];

        if (!isset($standing[$userId])) {
            continue;
        }

        foreach ($kycBadges as $badge) {
            if (!$qualifies($userId, $badge)) {
                continue;
            }

            $held = (int) Database::scalar(
                "SELECT COUNT(*) FROM `creator_badges`
                  WHERE `creator_id` = :c AND `badge` = :b AND `status` = 'active'
                    AND (`expires_at` IS NULL OR `expires_at` > NOW())",
                ['c' => (int) $creator['creator_id'], 'b' => $badge]
            ) > 0;

            if (!$held) {
                $missingBadges[] = $creator + ['badge' => $badge];
            }
        }
    }
}

// -----------------------------------------------------------------
// 5. users.kyc_status / is_identity_verified / is_business_verified
// -----------------------------------------------------------------

/** users.kyc_status for a profile, as the state machine writes it. */
$statusFor = static function (?array $profile): string {
    if ($profile === null) {
        return 'none';
    }

    $status = (string) $profile['status'];

    // purgeForUser() resets an erased account to "none"; an approval whose
    // record was erased is not a verification any more.
    if ($profile['purged_at'] !== null && $status === 'approved') {
        return 'none';
    }

    return match ($status) {
        'draft'    => 'none',
        'approved' => 'verified',
        default    => $status,
    };
};

/** Statuses that also agree with a profile: a draft never writes users.kyc_status. */
$alsoFine = static function (?array $profile): array {
    if ($profile === null) {
        return [];
    }

    if ((string) $profile['status'] === 'draft') {
        return ['draft'];
    }

    // The retention job erases a rejected / closed profile without touching
    // users.kyc_status, and account erasure writes "none".
    return $profile['purged_at'] !== null ? ['none'] : [];
};

$profiles = [];

foreach (Database::select(
    'SELECT `user_id`, `kind`, `status`, `purged_at` FROM `kyc_profiles` WHERE `deleted_at` IS NULL'
) as $row) {
    $profiles[(int) $row['user_id']][(string) $row['kind']] = $row;
}

$legacyUsers = array_fill_keys(array_map('intval', Database::column(
    'SELECT DISTINCT `user_id` FROM `kyc_documents` WHERE `profile_id` IS NULL AND `purged_at` IS NULL'
)), true);

$flagRows   = [];
$legacyRows = [];

foreach (Database::select(
    'SELECT `id`, `email`, `account_type`, `kyc_status`, `is_identity_verified`, `is_business_verified`
       FROM `users` WHERE `deleted_at` IS NULL ORDER BY `id`'
) as $user) {
    $userId = (int) $user['id'];

    if (isset($lapsedUsers[$userId])) {
        continue;
    }

    $kind    = $user['account_type'] === 'creator' ? 'creator' : 'brand';
    $profile = $profiles[$userId][$kind] ?? null;

    $expected = [
        'kyc_status'           => $statusFor($profile),
        'is_identity_verified' => ($standing[$userId]['creator'] ?? false) ? 1 : 0,
        'is_business_verified' => ($standing[$userId]['brand'] ?? false) ? 1 : 0,
    ];

    $statusOk = (string) $user['kyc_status'] === $expected['kyc_status']
        || in_array((string) $user['kyc_status'], $alsoFine($profile), true);

    $agrees = $statusOk
        && (int) $user['is_identity_verified'] === $expected['is_identity_verified']
        && (int) $user['is_business_verified'] === $expected['is_business_verified'];

    if ($agrees) {
        continue;
    }

    // A review from the retired single-document flow has no profile; its
    // users.kyc_status is the only record of it. Reported, never changed.
    if ($profile === null && ($profiles[$userId] ?? []) === [] && isset($legacyUsers[$userId])) {
        $legacyRows[] = $user;
        continue;
    }

    $flagRows[] = ['user' => $user, 'expected' => $expected];
}

// -----------------------------------------------------------------
// Deleted accounts still holding KYC data (report only)
// -----------------------------------------------------------------

$unerased = (int) Database::scalar(
    "SELECT COUNT(*) FROM `users` u
      WHERE u.`deleted_at` IS NOT NULL
        AND (EXISTS (SELECT 1 FROM `kyc_profiles` p WHERE p.`user_id` = u.`id` AND p.`purged_at` IS NULL)
          OR EXISTS (SELECT 1 FROM `kyc_documents` d WHERE d.`user_id` = u.`id` AND d.`purged_at` IS NULL))"
);

// -----------------------------------------------------------------
// Report
// -----------------------------------------------------------------

$summary = [
    'ticks_without_granted_badge' => count($rows),
    'ticks_on_stale_kyc'          => count($grantedStale),
    'stale_creator_verified_rows' => count($badgeTicks),
    'stale_kyc_badges'            => count($staleBadges),
    'missing_kyc_badges'          => count($missingBadges),
    'user_flags_mismatched'       => count($flagRows),
    'lapsed_approvals_not_expired' => count($lapsed),
    'legacy_accounts_left_alone'  => count($legacyRows),
    'deleted_accounts_unerased'   => $unerased,
];

if ($json && !$apply) {
    echo json_encode([
        // The original listing, unchanged.
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
        'summary'          => $summary,
        'ticks_on_stale_kyc' => array_map(static fn (array $r): array => [
            'user_id'    => (int) $r['user_id'],
            'reference'  => $r['reference'],
            'kyc_status' => $r['kyc_status'],
        ], $grantedStale),
        'stale_creator_verified_rows' => array_map(static fn (array $r): array => [
            'user_id'      => (int) $r['user_id'],
            'creator_id'   => (int) $r['creator_id'],
            'kyc_status'   => $r['kyc_status'] ?? 'none',
            'badge_status' => $r['badge_status'] ?? 'none',
        ], $badgeTicks),
        'stale_kyc_badges' => array_map(static fn (array $r): array => [
            'user_id'    => (int) $r['user_id'],
            'creator_id' => (int) $r['creator_id'],
            'badge'      => $r['badge'],
        ], $staleBadges),
        'missing_kyc_badges' => array_map(static fn (array $r): array => [
            'user_id'    => (int) $r['user_id'],
            'creator_id' => (int) $r['creator_id'],
            'badge'      => $r['badge'],
        ], $missingBadges),
        'user_flags' => array_map(static fn (array $r): array => [
            'user_id'  => (int) $r['user']['id'],
            'current'  => [
                'kyc_status'           => $r['user']['kyc_status'],
                'is_identity_verified' => (int) $r['user']['is_identity_verified'],
                'is_business_verified' => (int) $r['user']['is_business_verified'],
            ],
            'expected' => $r['expected'],
        ], $flagRows),
    ], JSON_PRETTY_PRINT) . "\n";

    exit(0);
}

$line = static function (string $text): void {
    echo $text . "\n";
};

$line("\nKYC reconciliation" . ($apply ? ' — APPLYING' : ' — dry run'));
$line(str_repeat('-', 72));

$line("\n1. Blue ticks without a granted badge");

foreach (['KYC approved (could be granted from the KYC panel instead)' => $approved,
          'KYC not approved (no basis for a tick)'                       => $unapproved] as $title => $group) {
    $line("\n   {$title}: " . count($group));

    foreach ($group as $row) {
        printf(
            "     #%-6d %-8s %-32s %-14s kyc=%-13s badge=%s\n",
            (int) $row['user_id'],
            (string) $row['account_type'],
            mb_substr((string) $row['email'], 0, 32),
            (string) ($row['reference'] ?? '—'),
            (string) ($row['kyc_status'] ?? 'none'),
            (string) ($row['badge_status'] ?? 'none')
        );
    }
}

$line("\n2. Ticks granted on a KYC that is not approved and current: " . count($grantedStale));
foreach ($grantedStale as $row) {
    printf("     #%-6d %-8s %-32s %-14s kyc=%s%s\n", (int) $row['user_id'], (string) $row['account_type'],
        mb_substr((string) $row['email'], 0, 32), (string) $row['reference'], (string) $row['kyc_status'],
        $row['purged_at'] !== null ? ' (purged)' : '');
}

$line("\n3. Active creator_verified badge rows with no granted, current tick: " . count($badgeTicks));
foreach ($badgeTicks as $row) {
    printf("     #%-6d creator %-6d %-32s kyc=%-13s badge=%s\n", (int) $row['user_id'], (int) $row['creator_id'],
        mb_substr((string) $row['email'], 0, 32), (string) ($row['kyc_status'] ?? 'none'), (string) ($row['badge_status'] ?? 'none'));
}

$line("\n4. KYC-derived badges that disagree with the KYC standing");
$line('   Active with no current approved KYC (stale): ' . count($staleBadges));
foreach ($staleBadges as $row) {
    printf("     #%-6d creator %-6d %-32s %s\n", (int) $row['user_id'], (int) $row['creator_id'],
        mb_substr((string) $row['email'], 0, 32), (string) $row['badge']);
}
$line('   Approved and current but not awarded (missing): ' . count($missingBadges));
foreach ($missingBadges as $row) {
    printf("     #%-6d creator %-6d %-32s %s\n", (int) $row['user_id'], (int) $row['creator_id'],
        mb_substr((string) $row['email'], 0, 32), (string) $row['badge']);
}

$line("\n5. users.kyc_status / is_identity_verified / is_business_verified out of line with the KYC profile: " . count($flagRows));
foreach ($flagRows as $row) {
    printf(
        "     #%-6d %-8s %-32s %s/%d/%d -> %s/%d/%d\n",
        (int) $row['user']['id'],
        (string) $row['user']['account_type'],
        mb_substr((string) $row['user']['email'], 0, 32),
        (string) $row['user']['kyc_status'],
        (int) $row['user']['is_identity_verified'],
        (int) $row['user']['is_business_verified'],
        $row['expected']['kyc_status'],
        $row['expected']['is_identity_verified'],
        $row['expected']['is_business_verified']
    );
}

$line("\nLeft alone (report only)");
$line('   Approvals past their date, not yet expired (the expiry job expires them and tells the user): ' . count($lapsed));
foreach ($lapsed as $row) {
    printf("     #%-6d %-8s %-14s expired on %s\n", (int) $row['user_id'], (string) $row['kind'], (string) $row['reference'], (string) $row['expires_on']);
}
$line('   Accounts verified in the retired single-document flow, with no profile: ' . count($legacyRows));
$line('   Deleted accounts whose KYC data was never erased (the kyc:retention job erases them): ' . $unerased);

$line("\nSummary: " . json_encode($summary));

if (!$apply) {
    $line("\nNothing was changed. Re-run with --apply to fix sections 1-5.\n");
    exit(0);
}

// -----------------------------------------------------------------
// Apply
// -----------------------------------------------------------------

/**
 * Take a tick off an account — the columns, the creator_verified badge and,
 * when the profile says it was granted, the profile — with a badge_revoked
 * event on the KYC trail when there is a profile. No notification.
 */
$dropTick = static function (int $userId, ?int $profileId, string $reason): void {
    Database::transaction(static function () use ($userId, $profileId, $reason): void {
        Database::update('users', ['has_blue_tick' => 0, 'blue_tick_at' => null], ['id' => $userId]);

        foreach (Database::column('SELECT `id` FROM `creators` WHERE `user_id` = :u', ['u' => $userId]) as $creatorId) {
            try {
                CreatorBadgeService::revoke((int) $creatorId, 'creator_verified', [
                    'actor_type' => 'system',
                    'reason'     => $reason,
                ]);
            } catch (HttpException) {
                // The catalogue term was removed; the columns below still go.
            }

            // A legacy tick has no badge row for revoke() to act on.
            Database::update('creators', ['is_verified' => 0, 'verified_at' => null], ['id' => (int) $creatorId]);
        }

        if ($profileId === null) {
            return;
        }

        Database::execute(
            "UPDATE `kyc_profiles`
                SET `badge_status` = 'revoked', `badge_revoked_at` = NOW(), `badge_revoke_reason` = :reason
              WHERE `id` = :id AND `badge_status` = 'granted'",
            ['id' => $profileId, 'reason' => mb_substr($reason, 0, 500)]
        );

        KycService::recordEvent($profileId, 'badge_revoked', [
            'actor_type' => 'system',
            'note'       => $reason,
            'metadata'   => ['source' => 'kyc_reconcile_ticks'],
        ]);
    });
};

$done = ['ticks' => 0, 'badges_changed' => 0, 'flags' => 0, 'errors' => 0];

$tickFixes = [];

foreach ($rows as $row) {
    $tickFixes[(int) $row['user_id']] ??= [
        $row['profile_id'] === null ? null : (int) $row['profile_id'],
        'Reconciliation: tick predates the manual blue-tick approval and no badge was granted.',
    ];
}

foreach ($grantedStale as $row) {
    $tickFixes[(int) $row['user_id']] ??= [
        (int) $row['profile_id'],
        'Reconciliation: the KYC behind this tick is no longer approved and current.',
    ];
}

foreach ($badgeTicks as $row) {
    $tickFixes[(int) $row['user_id']] ??= [
        $row['profile_id'] === null ? null : (int) $row['profile_id'],
        'Reconciliation: creator_verified badge with no granted blue tick behind it.',
    ];
}

foreach ($tickFixes as $userId => [$profileId, $reason]) {
    try {
        $dropTick($userId, $profileId, $reason);
        $done['ticks']++;
    } catch (\Throwable $e) {
        $done['errors']++;
        echo '  ! tick #' . $userId . ': ' . $e->getMessage() . "\n";
    }
}

// The KYC-derived badges, through the platform's own sync (system actor,
// every change in creator_badge_events).
$creators = [];

foreach (array_merge($staleBadges, $missingBadges) as $row) {
    $creators[(int) $row['creator_id']] = true;
}

foreach (array_keys($creators) as $creatorId) {
    try {
        $done['badges_changed'] += CreatorBadgeService::syncKycBadges($creatorId);
    } catch (\Throwable $e) {
        $done['errors']++;
        echo '  ! badges of creator ' . $creatorId . ': ' . $e->getMessage() . "\n";
    }
}

foreach ($flagRows as $row) {
    $userId = (int) $row['user']['id'];

    try {
        Database::update('users', $row['expected'], ['id' => $userId]);

        ActivityLogger::log(
            'kyc.flags_reconciled',
            'user',
            $userId,
            'KYC flags brought in line with the verification profile (kyc_reconcile_ticks).',
            [
                'before' => [
                    'kyc_status'           => $row['user']['kyc_status'],
                    'is_identity_verified' => (int) $row['user']['is_identity_verified'],
                    'is_business_verified' => (int) $row['user']['is_business_verified'],
                ],
                'after'  => $row['expected'],
            ],
            'system',
            null,
            'kyc_reconcile_ticks'
        );

        $done['flags']++;
    } catch (\Throwable $e) {
        $done['errors']++;
        echo '  ! flags #' . $userId . ': ' . $e->getMessage() . "\n";
    }
}

$line("\nApplied: " . json_encode($done) . "\n");

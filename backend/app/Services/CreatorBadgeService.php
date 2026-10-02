<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;

/**
 * Creator verification badges.
 *
 * Migration 029 built `creator_badges` and `creator_badge_events` and seeded
 * the catalogue as a `verification_badge` taxonomy, but nothing ever read or
 * wrote them: every badge in the product was really one of two booleans on
 * `creators`, with no award date, no verifier, no expiry and no history.
 *
 * The catalogue stays in the taxonomy so an operator can add a badge without a
 * release. This service owns only the per-creator state: who holds what, on
 * whose authority, until when, and what happened before.
 *
 * Badges are either AUTOMATIC — the platform awards them from a rule over data
 * it already holds — or MANUAL, handed out by an administrator. Which is which
 * comes from the term's `metadata.auto`, so that too is configuration rather
 * than code.
 */
final class CreatorBadgeService
{
    /** The taxonomy holding the badge catalogue. */
    public const TAXONOMY = 'verification_badge';

    /**
     * Badges whose meaning is "the KYC review approved this account".
     *
     * They are derived ONLY from a current, approved KYC profile — never
     * handed out by hand, whatever the catalogue's `auto` flag says (the
     * seeded `business_verified` term is marked manual) — and they go the
     * moment that approval stops being current: KycService re-syncs them on
     * every decision, and {@see forCreator()} re-checks them on read, so a
     * stale row can never put "Identity verified" on a public profile.
     */
    public const KYC_BADGES = ['identity_verified', 'kyc_verified', 'business_verified'];

    /** Is [$badge] one of the KYC-derived badges? */
    public static function isKycBadge(string $badge): bool
    {
        return in_array($badge, self::KYC_BADGES, true);
    }

    /**
     * Refuse a badge an administrator must not hand out or withdraw by hand.
     *
     *   * KYC-derived badges ("KYC verified", "Identity verified", "Business
     *     verified") say the KYC review approved the account. They follow the
     *     KYC profile only; a role holding creators.verify but no kyc.*
     *     permission used to be able to put them on an account with no KYC.
     *   * Automatic badges follow a rule over data the platform holds; a hand
     *     award would be undone (or contradicted) by the next sync. "Re-check"
     *     re-evaluates them.
     *   * `creator_verified` is the blue tick, which has its own path through
     *     KycService::grantBadge() / revokeBadge().
     *
     * @throws HttpException 409
     */
    public static function assertManuallyManageable(string $badge): void
    {
        $definition = self::definition($badge);

        if (self::isKycBadge($badge)) {
            throw HttpException::conflict(
                'This badge follows the account\'s KYC review and cannot be awarded or removed by hand. '
                . 'Approve, reject or suspend the verification instead.',
                'kyc_managed_badge'
            );
        }

        if ($badge === 'creator_verified') {
            throw HttpException::conflict(
                'The blue tick is granted and removed from the creator\'s KYC verification.',
                'kyc_managed_badge'
            );
        }

        if ($definition['automatic']) {
            throw HttpException::conflict(
                'This badge is awarded automatically from the creator\'s data. Use "Re-check" to re-evaluate it.',
                'automatic_badge'
            );
        }
    }

    /**
     * Does the creator's account hold a CURRENT approved KYC, per kind?
     *
     * The same predicate KycService::isVerified() applies: status approved,
     * not purged, not deleted, and not past its expiry date. `creator` backs
     * "Identity verified" and "KYC verified"; `brand` backs "Business
     * verified" (an account only ever has the KYC kind of its account type,
     * so for a creator that one is normally false).
     *
     * @return array{creator:bool,brand:bool}
     */
    public static function kycStanding(int $creatorId): array
    {
        $standing = ['creator' => false, 'brand' => false];
        $userId   = self::userIdFor($creatorId);

        if ($userId === null) {
            return $standing;
        }

        foreach (Database::column(
            "SELECT `kind` FROM `kyc_profiles`
              WHERE `user_id` = :u AND `status` = 'approved'
                AND `purged_at` IS NULL AND `deleted_at` IS NULL
                AND (`expires_on` IS NULL OR `expires_on` >= CURDATE())",
            ['u' => $userId]
        ) as $kind) {
            if (isset($standing[(string) $kind])) {
                $standing[(string) $kind] = true;
            }
        }

        return $standing;
    }

    /**
     * Bring the KYC-derived badges in line with the KYC profile, now.
     *
     * KycService calls this after every decision (approve, reject, suspend,
     * reinstate, expiry, purge, tick grant / revoke) so a public "Identity
     * verified" never outlives the approval behind it until a nightly job
     * happens to run. Awards and revocations are made as the platform
     * ("system"), the only actor allowed to touch these badges. A badge
     * missing from the catalogue is skipped.
     *
     * @return int Badges whose state changed.
     */
    public static function syncKycBadges(int $creatorId): int
    {
        $catalogue = self::catalogue();
        $standing  = self::kycStanding($creatorId);
        $changed   = 0;

        foreach (self::KYC_BADGES as $badge) {
            if (!isset($catalogue[$badge])) {
                continue;
            }

            $qualifies = $badge === 'business_verified' ? $standing['brand'] : $standing['creator'];
            $before    = self::statusOf($creatorId, $badge);

            if ($qualifies) {
                // award() refuses a deleted creator; there is nothing to show then.
                if (!self::creatorIsLive($creatorId)) {
                    continue;
                }

                self::award($creatorId, $badge, [
                    'actor_type' => 'system',
                    'note'       => 'Awarded from the account\'s approved KYC verification.',
                ]);
            } elseif ($before === 'active') {
                self::revoke($creatorId, $badge, [
                    'actor_type' => 'system',
                    'reason'     => 'Withdrawn: the account no longer holds a current approved KYC verification.',
                ]);
            }

            if (self::statusOf($creatorId, $badge) !== $before) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Every badge in the catalogue, with its rules resolved.
     *
     * @return array<string,array<string,mixed>> Keyed by badge value.
     */
    public static function catalogue(): array
    {
        $out = [];

        foreach (TaxonomyService::terms(self::TAXONOMY) as $term) {
            $meta = [];

            if (!empty($term['metadata'])) {
                $decoded = is_array($term['metadata'])
                    ? $term['metadata']
                    : json_decode((string) $term['metadata'], true);

                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            $out[(string) $term['value']] = [
                'value'        => (string) $term['value'],
                'label'        => (string) $term['label'],
                'description'  => (string) ($term['description'] ?? ''),
                'icon'         => (string) ($term['icon'] ?? 'award'),
                // TaxonomyService normalises `color_hex` to `color` on the way
                // out; reading the column name gave every badge the fallback.
                'color'        => (string) ($term['color'] ?? '#64748B'),
                'automatic'    => (bool) ($meta['auto'] ?? false),
                'expires_days' => isset($meta['expires_days']) && $meta['expires_days'] !== null
                    ? (int) $meta['expires_days']
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Reject a badge that is not in the catalogue.
     *
     * @return array<string,mixed> The catalogue entry.
     */
    private static function definition(string $badge): array
    {
        $catalogue = self::catalogue();

        if (!isset($catalogue[$badge])) {
            throw HttpException::unprocessable("Unknown verification badge: {$badge}");
        }

        return $catalogue[$badge];
    }

    // =================================================================
    // Reading
    // =================================================================

    /**
     * The badges a creator currently holds, presented for display.
     *
     * Expired rows are filtered by date as well as status, so a badge that
     * lapsed since the nightly job last ran is not shown as live.
     *
     * @return list<array<string,mixed>>
     */
    public static function forCreator(int $creatorId): array
    {
        // An approval that lapsed since the nightly sweep is expired the first
        // time the profile is looked at — the blue tick and the KYC badges go
        // with it — rather than being shown until the cron next runs. One
        // indexed read when nothing has lapsed; never fatal to the page.
        $userId = self::userIdFor($creatorId);

        if ($userId !== null) {
            try {
                KycService::expireIfLapsed($userId);
            } catch (\Throwable $e) {
                Logger::warning('Could not expire a lapsed KYC approval on read', [
                    'creator_id' => $creatorId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $catalogue = self::catalogue();

        $held = [];

        foreach (Database::select(
            'SELECT * FROM `creator_badges`
              WHERE `creator_id` = :id
                AND `status` = "active"
                AND (`expires_at` IS NULL OR `expires_at` > NOW())',
            ['id' => $creatorId]
        ) as $row) {
            $held[(string) $row['badge']] = $row;
        }

        $out = [];

        // KYC-derived badges are re-checked against the KYC profile itself on
        // every read: a row left active by a failed sync, or by an approval
        // that lapsed before the nightly sweep, must not reach a public
        // profile. Resolved once, and only when such a badge is held.
        $kycHeld = array_intersect(array_keys($held), self::KYC_BADGES) !== [];
        $kycOk   = $kycHeld ? self::kycStanding($creatorId) : ['creator' => false, 'brand' => false];

        // Iterating the catalogue rather than the rows keeps badges in the
        // operator's chosen taxonomy order without needing a sort key.
        foreach ($catalogue as $badge => $definition) {
            $row = $held[$badge] ?? null;

            if ($row === null) {
                continue;
            }

            if (self::isKycBadge($badge)
                && !($badge === 'business_verified' ? $kycOk['brand'] : $kycOk['creator'])) {
                continue;
            }

            // A badge whose term was dropped from the catalogue is no longer
            // part of the product. Its row stays for audit, but iterating the
            // catalogue means it simply never appears here.
            $out[] = $definition + [
                'awarded_at' => $row['awarded_at'],
                'expires_at' => $row['expires_at'],
            ];
        }

        return $out;
    }

    /**
     * Every badge row for a creator including revoked and expired ones, for
     * the admin screen. Presented against the catalogue so an operator sees
     * the badges a creator does NOT hold as well as those they do.
     *
     * @return list<array<string,mixed>>
     */
    public static function adminOverview(int $creatorId): array
    {
        $held = [];

        foreach (Database::select(
            'SELECT * FROM `creator_badges` WHERE `creator_id` = :id',
            ['id' => $creatorId]
        ) as $row) {
            $held[(string) $row['badge']] = $row;
        }

        $out = [];

        foreach (self::catalogue() as $badge => $definition) {
            $row = $held[$badge] ?? null;

            $status = $row === null ? 'none' : (string) $row['status'];

            // Lapsed but not yet swept by the nightly job.
            if (
                $status === 'active'
                && $row !== null
                && $row['expires_at'] !== null
                && strtotime((string) $row['expires_at']) < time()
            ) {
                $status = 'expired';
            }

            $out[] = $definition + [
                // KYC-derived and automatic badges follow data, not an
                // operator: the award/revoke routes refuse them, so the
                // screen should not offer the buttons either.
                'kyc_derived'   => self::isKycBadge($badge),
                'manageable'    => !$definition['automatic'] && !self::isKycBadge($badge)
                    && $badge !== 'creator_verified',
                'status'        => $status,
                'held'          => $status === 'active',
                'awarded_at'    => $row['awarded_at'] ?? null,
                'awarded_by'    => $row['awarded_by'] ?? null,
                'awarded_note'  => $row['awarded_note'] ?? null,
                'expires_at'    => $row['expires_at'] ?? null,
                'revoked_at'    => $row['revoked_at'] ?? null,
                'revoke_reason' => $row['revoke_reason'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * The audit trail for one creator's badges.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $creatorId, int $limit = 100): array
    {
        return Database::select(
            'SELECT * FROM `creator_badge_events`
              WHERE `creator_id` = :id
              ORDER BY `created_at` DESC, `id` DESC
              LIMIT ' . max(1, min(500, $limit)),
            ['id' => $creatorId]
        );
    }

    // =================================================================
    // Writing
    // =================================================================

    /**
     * Award a badge, or re-award one that was revoked or had expired.
     *
     * One row per creator per badge, so this is an upsert: re-awarding clears
     * the revocation and appends to the event log rather than accumulating
     * duplicate rows.
     *
     * @param array<string,mixed> $context actor_type, actor_id, actor_name,
     *                                     note, expires_at, ip.
     */
    public static function award(int $creatorId, string $badge, array $context = []): void
    {
        $definition = self::definition($badge);

        // A KYC-derived badge is a statement that the KYC review approved the
        // account. Only the platform's own sync (actor "system", driven by the
        // KYC profile) may award one; no administrator can hand it out.
        if (self::isKycBadge($badge) && (string) ($context['actor_type'] ?? 'system') !== 'system') {
            throw HttpException::conflict(
                'This badge follows the account\'s KYC review and cannot be awarded by hand.',
                'kyc_managed_badge'
            );
        }

        self::assertCreatorExists($creatorId);

        $existing = Database::selectOne(
            'SELECT * FROM `creator_badges` WHERE `creator_id` = :c AND `badge` = :b',
            ['c' => $creatorId, 'b' => $badge]
        );

        // Already held and not lapsed — nothing to do, and no event, so a
        // nightly re-sync does not fill the audit trail with noise.
        if (
            $existing !== null
            && $existing['status'] === 'active'
            && ($existing['expires_at'] === null || strtotime((string) $existing['expires_at']) > time())
        ) {
            return;
        }

        $expiresAt = $context['expires_at'] ?? null;

        if ($expiresAt === null && $definition['expires_days'] !== null) {
            $expiresAt = date('Y-m-d H:i:s', time() + $definition['expires_days'] * 86400);
        }

        $now       = date('Y-m-d H:i:s');
        $actorType = (string) ($context['actor_type'] ?? 'system');
        $actorId   = $context['actor_id'] ?? null;

        $payload = [
            'creator_id'    => $creatorId,
            'badge'         => $badge,
            'status'        => 'active',
            'awarded_at'    => $now,
            'awarded_by'    => $actorType === 'admin' && $actorId !== null ? (int) $actorId : null,
            'awarded_note'  => isset($context['note']) ? mb_substr((string) $context['note'], 0, 500) : null,
            'expires_at'    => $expiresAt,
            // Re-awarding must clear the previous revocation, or the row would
            // read as simultaneously active and revoked.
            'revoked_at'    => null,
            'revoked_by'    => null,
            'revoke_reason' => null,
            'updated_at'    => $now,
        ];

        if ($existing === null) {
            Database::insert('creator_badges', $payload + ['created_at' => $now]);
        } else {
            Database::update('creator_badges', $payload, ['id' => (int) $existing['id']]);
        }

        self::recordEvent($creatorId, $badge, 'awarded', [
            'from' => $existing['status'] ?? null,
            'to'   => 'active',
        ] + $context);

        self::syncLegacyFlags($creatorId, $badge);
    }

    /**
     * Withdraw a badge.
     *
     * The row is kept and marked revoked rather than deleted: "this creator
     * was verified and no longer is" is the fact an audit needs, and a delete
     * would erase it.
     *
     * @param array<string,mixed> $context actor_type, actor_id, reason, ip.
     */
    public static function revoke(int $creatorId, string $badge, array $context = []): void
    {
        self::definition($badge);

        // Same rule as award(): only the KYC-driven sync withdraws these. An
        // administrator who wants "Identity verified" gone suspends or
        // rejects the verification itself — otherwise the next sync would
        // put the badge straight back while the KYC is still approved.
        if (self::isKycBadge($badge) && (string) ($context['actor_type'] ?? 'system') !== 'system') {
            throw HttpException::conflict(
                'This badge follows the account\'s KYC review and cannot be removed by hand.',
                'kyc_managed_badge'
            );
        }

        $existing = Database::selectOne(
            'SELECT * FROM `creator_badges` WHERE `creator_id` = :c AND `badge` = :b',
            ['c' => $creatorId, 'b' => $badge]
        );

        if ($existing === null || $existing['status'] === 'revoked') {
            return;
        }

        $actorType = (string) ($context['actor_type'] ?? 'system');
        $actorId   = $context['actor_id'] ?? null;
        $reason    = isset($context['reason']) ? mb_substr((string) $context['reason'], 0, 500) : null;

        Database::update('creator_badges', [
            'status'        => 'revoked',
            'revoked_at'    => date('Y-m-d H:i:s'),
            'revoked_by'    => $actorType === 'admin' && $actorId !== null ? (int) $actorId : null,
            'revoke_reason' => $reason,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], ['id' => (int) $existing['id']]);

        self::recordEvent($creatorId, $badge, 'revoked', [
            'from' => (string) $existing['status'],
            'to'   => 'revoked',
            'note' => $reason,
        ] + $context);

        self::syncLegacyFlags($creatorId, $badge);
    }

    /**
     * Sweep badges whose validity has run out.
     *
     * Called nightly. Returns the number expired so the job log says what it
     * did rather than only that it ran.
     */
    public static function expireDue(): int
    {
        $due = Database::select(
            'SELECT `id`, `creator_id`, `badge` FROM `creator_badges`
              WHERE `status` = "active" AND `expires_at` IS NOT NULL AND `expires_at` <= NOW()'
        );

        foreach ($due as $row) {
            Database::update('creator_badges', [
                'status'     => 'expired',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);

            self::recordEvent((int) $row['creator_id'], (string) $row['badge'], 'expired', [
                'actor_type' => 'system',
                'from'       => 'active',
                'to'         => 'expired',
                'note'       => 'Validity period elapsed.',
            ]);

            self::syncLegacyFlags((int) $row['creator_id'], (string) $row['badge']);
        }

        return count($due);
    }

    // =================================================================
    // Automatic awards
    // =================================================================

    /**
     * Re-evaluate every automatic badge for one creator.
     *
     * Each rule reads data the platform already holds, so this is safe to run
     * as often as convenient — award() and revoke() are both no-ops when the
     * state already matches.
     *
     * Manual badges are never touched here: an administrator's decision must
     * not be undone by a nightly job.
     *
     * @return int Badges whose state changed.
     */
    public static function syncAutomatic(int $creatorId): int
    {
        $creator = Database::selectOne(
            'SELECT c.*, u.`email_verified_at`, u.`phone_verified_at`, u.`is_identity_verified`
               FROM `creators` c
               JOIN `users` u ON u.`id` = c.`user_id`
              WHERE c.`id` = :id',
            ['id' => $creatorId]
        );

        if ($creator === null) {
            return 0;
        }

        // The KYC-derived badges follow the KYC profile whatever the
        // catalogue's `auto` flag says (business_verified is seeded manual,
        // so a hand-awarded one used to survive every sync).
        $changed = self::syncKycBadges($creatorId);

        foreach (self::catalogue() as $badge => $definition) {
            if (!$definition['automatic'] || self::isKycBadge($badge)) {
                continue;
            }

            $qualifies = self::qualifiesFor($badge, $creator);

            // A rule the service does not implement must not silently revoke a
            // badge an operator may have granted by hand.
            if ($qualifies === null) {
                continue;
            }

            $before = (string) (Database::scalar(
                'SELECT `status` FROM `creator_badges` WHERE `creator_id` = :c AND `badge` = :b',
                ['c' => $creatorId, 'b' => $badge]
            ) ?? 'none');

            if ($qualifies) {
                self::award($creatorId, $badge, [
                    'actor_type' => 'system',
                    'note'       => 'Awarded automatically: the creator meets this badge\'s rule.',
                ]);
            } elseif ($before === 'active') {
                self::revoke($creatorId, $badge, [
                    'actor_type' => 'system',
                    'reason'     => 'Withdrawn automatically: the creator no longer meets this badge\'s rule.',
                ]);
            }

            $after = (string) (Database::scalar(
                'SELECT `status` FROM `creator_badges` WHERE `creator_id` = :c AND `badge` = :b',
                ['c' => $creatorId, 'b' => $badge]
            ) ?? 'none');

            if ($before !== $after) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Does this creator meet the rule for an automatic badge?
     *
     * Thresholds come from settings so an operator can retune what "top rated"
     * means without a release.
     *
     * @param  array<string,mixed> $creator Joined creators + users row.
     * @return bool|null Null when no rule is implemented for this badge.
     */
    private static function qualifiesFor(string $badge, array $creator): ?bool
    {
        $creatorId = (int) $creator['id'];

        return match ($badge) {
            'email_verified'  => $creator['email_verified_at'] !== null,
            'mobile_verified' => $creator['phone_verified_at'] !== null,

            // Both KYC badges come from the KYC profile itself — approved, not
            // purged or deleted, not lapsed, creator kind — never from the
            // denormalised users.is_identity_verified flag, which seeded and
            // legacy accounts carry with no approved KYC behind it.
            // (syncAutomatic() routes them through syncKycBadges(); this
            // answer is kept so the rule reads the same wherever it is asked.)
            'identity_verified', 'kyc_verified' => self::kycStanding($creatorId)['creator'],
            'business_verified' => self::kycStanding($creatorId)['brand'],

            'social_verified' => Database::scalar(
                'SELECT COUNT(*) FROM `creator_social_accounts` WHERE `creator_id` = :c AND `is_verified` = 1',
                ['c' => $creatorId]
            ) > 0,

            'top_rated' => (float) $creator['rating_avg'] >= Config::settingFloat('creator', 'top_rated_min_rating', 4.5)
                && (int) $creator['rating_count'] >= Config::settingInt('creator', 'top_rated_min_reviews', 10),

            'trusted_creator' => (int) $creator['collabs_count'] >= Config::settingInt('creator', 'trusted_min_collabs', 25)
                && Database::scalar(
                    'SELECT COUNT(*) FROM `bookings` WHERE `creator_id` = :c AND `status` = "disputed"',
                    ['c' => $creatorId]
                ) == 0,

            default => null,
        };
    }

    /**
     * Re-evaluate automatic badges across every active creator.
     *
     * @return array{creators:int,changed:int}
     */
    public static function syncAllAutomatic(): array
    {
        // 'approved' is what a live creator is on this table — the same filter
        // CreatorService::search uses. There is no 'active' status.
        $ids = Database::select(
            'SELECT `id` FROM `creators` WHERE `deleted_at` IS NULL AND `status` = "approved"'
        );

        $changed = 0;

        foreach ($ids as $row) {
            $changed += self::syncAutomatic((int) $row['id']);
        }

        return ['creators' => count($ids), 'changed' => $changed];
    }

    // =================================================================
    // Internals
    // =================================================================

    /**
     * Keep the two booleans on `creators` in step with the badges that
     * replaced them.
     *
     * Discovery, search ranking and several existing widgets read
     * `is_verified` and `is_featured` directly. Rewriting all of that was not
     * part of this work and would risk the search behaviour, so the badge
     * system drives those columns instead of competing with them.
     */
    private static function syncLegacyFlags(int $creatorId, string $badge): void
    {
        if ($badge === 'creator_verified') {
            $holds = self::holds($creatorId, 'creator_verified');

            Database::update('creators', [
                'is_verified' => $holds ? 1 : 0,
                'verified_at' => $holds ? date('Y-m-d H:i:s') : null,
            ], ['id' => $creatorId]);
        }

        if ($badge === 'featured_creator') {
            $row = Database::selectOne(
                'SELECT `status`, `expires_at` FROM `creator_badges`
                  WHERE `creator_id` = :c AND `badge` = "featured_creator"',
                ['c' => $creatorId]
            );

            $holds = $row !== null
                && $row['status'] === 'active'
                && ($row['expires_at'] === null || strtotime((string) $row['expires_at']) > time());

            Database::update('creators', [
                'is_featured'    => $holds ? 1 : 0,
                'featured_until' => $holds ? ($row['expires_at'] ?? null) : null,
            ], ['id' => $creatorId]);
        }
    }

    /** Does this creator currently hold this badge? */
    public static function holds(int $creatorId, string $badge): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_badges`
              WHERE `creator_id` = :c AND `badge` = :b AND `status` = "active"
                AND (`expires_at` IS NULL OR `expires_at` > NOW())',
            ['c' => $creatorId, 'b' => $badge]
        ) > 0;
    }

    /** The account behind a creator row (deleted or not), or null. */
    private static function userIdFor(int $creatorId): ?int
    {
        $userId = Database::scalar('SELECT `user_id` FROM `creators` WHERE `id` = :id', ['id' => $creatorId]);

        return $userId === null ? null : (int) $userId;
    }

    /** The stored status of one badge row: active / revoked / expired, or none. */
    private static function statusOf(int $creatorId, string $badge): string
    {
        return (string) (Database::scalar(
            'SELECT `status` FROM `creator_badges` WHERE `creator_id` = :c AND `badge` = :b',
            ['c' => $creatorId, 'b' => $badge]
        ) ?? 'none');
    }

    private static function creatorIsLive(int $creatorId): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `creators` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $creatorId]
        ) > 0;
    }

    private static function assertCreatorExists(int $creatorId): void
    {
        $exists = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creators` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $creatorId]
        );

        if ($exists === 0) {
            throw HttpException::notFound('Creator not found.');
        }
    }

    /**
     * Append to the badge audit trail.
     *
     * Same shape and vocabulary as `kyc_events` so an operator reading one
     * trail does not have to learn a second.
     *
     * @param array<string,mixed> $context
     */
    private static function recordEvent(int $creatorId, string $badge, string $action, array $context = []): void
    {
        $ip        = $context['ip'] ?? null;
        $actorType = (string) ($context['actor_type'] ?? 'system');
        $actorId   = $context['actor_id'] ?? null;

        Database::insert('creator_badge_events', [
            'creator_id'  => $creatorId,
            'badge'       => $badge,
            'actor_type'  => in_array($actorType, ['creator', 'admin', 'system'], true) ? $actorType : 'system',
            'actor_id'    => $actorId !== null ? (int) $actorId : null,
            'actor_name'  => isset($context['actor_name']) ? mb_substr((string) $context['actor_name'], 0, 150) : null,
            'action'      => $action,
            'from_status' => $context['from'] ?? null,
            'to_status'   => $context['to'] ?? null,
            'note'        => isset($context['note']) ? mb_substr((string) $context['note'], 0, 1000) : null,
            'metadata'    => isset($context['metadata']) ? json_encode($context['metadata']) : null,
            'ip_address'  => is_string($ip) ? (@inet_pton($ip) ?: null) : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}

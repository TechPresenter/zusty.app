<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\HttpException;

/**
 * Per-section creator profile locks.
 *
 * Migration 029 built `creator_profile_locks` and nothing ever used it, so an
 * administrator's only options were to leave a profile fully editable or to
 * suspend the account outright. This makes the middle ground real: freeze the
 * pricing on a creator under investigation, or the username on one who keeps
 * churning it, while the rest of the profile stays theirs to edit.
 *
 * Presence of the row IS the lock — there is no `is_locked` flag that could
 * fall out of step with it, and unlocking deletes.
 *
 * An administrator is never blocked by a lock. The point of a lock is to stop
 * the creator changing a section, not to stop the operator who set it.
 */
final class CreatorProfileLockService
{
    /**
     * The sections that can be locked.
     *
     * Held in the application rather than an ENUM so the vocabulary can grow
     * without a migration, but enumerated here so a typo in an admin request
     * cannot create a lock that nothing will ever check.
     *
     * @var array<string,string>
     */
    public const SECTIONS = [
        'username'      => 'Username',
        'display_name'  => 'Display name',
        'bio'           => 'Bio & about',
        'headline'      => 'Professional headline',
        'photos'        => 'Profile & cover photo',
        'categories'    => 'Categories & profession',
        'skills'        => 'Skills & interests',
        'pricing'       => 'Pricing & packages',
        'portfolio'     => 'Portfolio',
        'gallery'       => 'Gallery',
        'social'        => 'Social accounts',
        'contact'       => 'Contact details',
        'location'      => 'Location & address',
        'availability'  => 'Availability & working hours',
        'documents'     => 'Documents & media kit',
        'verification'  => 'Verification',
    ];

    /**
     * Every lockable section with its current state, for the admin screen.
     *
     * @return list<array<string,mixed>>
     */
    public static function overview(int $creatorId): array
    {
        $locked = self::locks($creatorId);

        $out = [];

        foreach (self::SECTIONS as $section => $label) {
            $row = $locked[$section] ?? null;

            $out[] = [
                'section'   => $section,
                'label'     => $label,
                'locked'    => $row !== null,
                'reason'    => $row['reason'] ?? null,
                'locked_at' => $row['locked_at'] ?? null,
                'locked_by' => $row['locked_by'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * The raw lock rows for a creator, keyed by section.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function locks(int $creatorId): array
    {
        $out = [];

        foreach (Database::select(
            'SELECT * FROM `creator_profile_locks` WHERE `creator_id` = :id',
            ['id' => $creatorId]
        ) as $row) {
            $out[(string) $row['section']] = $row;
        }

        return $out;
    }

    /**
     * Just the locked section names — what the apps need to grey out the
     * right fields instead of letting a creator fill in a form that the API
     * will then refuse.
     *
     * @return list<string>
     */
    public static function lockedSections(int $creatorId): array
    {
        return array_values(array_map(
            static fn(array $row): string => (string) $row['section'],
            Database::select(
                'SELECT `section` FROM `creator_profile_locks` WHERE `creator_id` = :id ORDER BY `section`',
                ['id' => $creatorId]
            )
        ));
    }

    public static function isLocked(int $creatorId, string $section): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_profile_locks` WHERE `creator_id` = :c AND `section` = :s',
            ['c' => $creatorId, 's' => $section]
        ) > 0;
    }

    /**
     * Refuse a creator's edit to a frozen section.
     *
     * The reason the administrator gave is included, because "this is locked"
     * with no explanation is an unactionable error for the person reading it.
     */
    public static function assertUnlocked(int $creatorId, string $section): void
    {
        $row = Database::selectOne(
            'SELECT `reason` FROM `creator_profile_locks` WHERE `creator_id` = :c AND `section` = :s',
            ['c' => $creatorId, 's' => $section]
        );

        if ($row === null) {
            return;
        }

        $label  = self::SECTIONS[$section] ?? $section;
        $reason = trim((string) ($row['reason'] ?? ''));

        throw HttpException::forbidden(
            $reason !== ''
                ? "{$label} is locked by an administrator: {$reason}"
                : "{$label} is locked by an administrator. Contact support if you need it changed."
        );
    }

    /**
     * Freeze a section.
     *
     * @param array<string,mixed> $context actor_id, actor_name, reason, ip.
     */
    public static function lock(int $creatorId, string $section, array $context = []): void
    {
        self::assertKnownSection($section);
        self::assertCreatorExists($creatorId);

        $reason = isset($context['reason']) ? mb_substr((string) $context['reason'], 0, 500) : null;

        // Re-locking an already-locked section updates the reason rather than
        // failing on the unique key, so an operator can correct what they
        // wrote without unlocking first.
        Database::execute(
            'INSERT INTO `creator_profile_locks` (`creator_id`, `section`, `locked_by`, `reason`, `locked_at`)
             VALUES (:c, :s, :by, :reason, NOW())
             ON DUPLICATE KEY UPDATE `reason` = VALUES(`reason`), `locked_by` = VALUES(`locked_by`), `locked_at` = NOW()',
            [
                'c'      => $creatorId,
                's'      => $section,
                'by'     => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
                'reason' => $reason,
            ]
        );

        self::recordEvent($creatorId, $section, 'locked', $reason, $context);
    }

    /**
     * Release a section.
     *
     * @param array<string,mixed> $context actor_id, actor_name, reason, ip.
     */
    public static function unlock(int $creatorId, string $section, array $context = []): void
    {
        self::assertKnownSection($section);

        $removed = Database::execute(
            'DELETE FROM `creator_profile_locks` WHERE `creator_id` = :c AND `section` = :s',
            ['c' => $creatorId, 's' => $section]
        );

        if ($removed > 0) {
            self::recordEvent($creatorId, $section, 'unlocked', null, $context);
        }
    }

    private static function assertKnownSection(string $section): void
    {
        if (!isset(self::SECTIONS[$section])) {
            throw HttpException::unprocessable("Not a lockable profile section: {$section}");
        }
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
     * Locks are an administrative action against a creator's account, so they
     * belong in the badge audit trail alongside every other such action —
     * reusing that table rather than adding a third almost-identical one.
     *
     * @param array<string,mixed> $context
     */
    private static function recordEvent(
        int $creatorId,
        string $section,
        string $action,
        ?string $reason,
        array $context
    ): void {
        $ip = $context['ip'] ?? null;

        Database::insert('creator_badge_events', [
            'creator_id'  => $creatorId,
            'badge'       => 'section:' . $section,
            'actor_type'  => 'admin',
            'actor_id'    => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
            'actor_name'  => isset($context['actor_name']) ? mb_substr((string) $context['actor_name'], 0, 150) : null,
            'action'      => $action,
            'from_status' => $action === 'locked' ? 'open' : 'locked',
            'to_status'   => $action === 'locked' ? 'locked' : 'open',
            'note'        => $reason,
            'ip_address'  => is_string($ip) ? (@inet_pton($ip) ?: null) : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}

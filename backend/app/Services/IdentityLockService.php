<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\HttpException;

/**
 * The public name of an account that carries the blue tick is frozen.
 *
 * The tick says "an administrator checked who this is". It is drawn next to
 * whatever name the account shows — users.name / users.company_name on brand
 * surfaces (campaigns, chat, bookings) and creators.display_name / handle on
 * creator ones — while the identity that was actually verified lives apart in
 * the KYC details. Letting the name move under a granted tick would turn the
 * tick into a way to borrow somebody else's reputation: pass KYC as a small
 * company, then rename to a famous brand and keep the badge.
 *
 * So the app cannot change those fields while a tick is showing. An
 * administrator still can, from the panel — the panel writes the columns
 * directly and never comes through here.
 *
 * "Showing" is read from every place a tick can be displayed from, not only a
 * granted KYC badge: users.has_blue_tick, a kyc_profiles row whose badge is
 * granted, and creators.is_verified (which also covers a tick that predates the
 * KYC badge model). Whatever the app draws as verified is what is frozen.
 */
final class IdentityLockService
{
    public const ERROR_CODE = 'identity_locked';

    public const MESSAGE = 'Your name is locked while your account has the verified badge. Contact support to change it.';

    /** Does this account currently display a blue tick anywhere? */
    public static function isLocked(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ((int) (Database::scalar(
            'SELECT `has_blue_tick` FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        ) ?? 0) === 1) {
            return true;
        }

        if (Database::scalar(
            "SELECT 1 FROM `kyc_profiles`
              WHERE `user_id` = :id AND `badge_status` = 'granted'
                AND `deleted_at` IS NULL AND `purged_at` IS NULL
              LIMIT 1",
            ['id' => $userId]
        ) !== null) {
            return true;
        }

        return Database::scalar(
            'SELECT 1 FROM `creators` WHERE `user_id` = :id AND `is_verified` = 1 AND `deleted_at` IS NULL LIMIT 1',
            ['id' => $userId]
        ) !== null;
    }

    /**
     * Refuse a change to a locked public-name field.
     *
     * Only fields whose submitted value actually differs from the stored one
     * count, because the apps PUT the whole form: re-sending the current name
     * alongside an unrelated edit (a city, a bio) is not a rename and must go
     * through. Compared after trimming, so " Acme " re-sent is not a change.
     *
     * @param array<string,mixed> $submitted field => value the request would write
     * @param array<string,mixed> $current   field => value stored now
     *
     * @throws HttpException 409 identity_locked
     */
    public static function assertNameUnchanged(int $userId, array $submitted, array $current): void
    {
        $changed = false;

        foreach ($submitted as $field => $value) {
            if (self::normalise($value) !== self::normalise($current[$field] ?? null)) {
                $changed = true;
                break;
            }
        }

        if ($changed && self::isLocked($userId)) {
            throw HttpException::conflict(self::MESSAGE, self::ERROR_CODE);
        }
    }

    private static function normalise(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

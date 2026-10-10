<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\HttpException;

/**
 * Blocking one account from another.
 *
 * A block is one person's decision — a `user_blocks` row from the blocker to
 * the blocked — but its effect is mutual. While a row exists in either
 * direction, neither side can start anything new with the other:
 *
 *   - messages, in any conversation the two share, and a new direct
 *     conversation (403 `blocked`);
 *   - booking requests, accepting a pending request, counter offers and
 *     accepting one;
 *   - campaign invitations, applications, shortlisting and hiring;
 *   - follows and saved creators, which the block also removes both ways;
 *   - notifications one causes the other (NotificationService `actor_id`).
 *
 * It also hides: the blocked creator from the blocker's search,
 * recommendations and home rails (and the reverse, so a blocked brand cannot
 * keep finding the creator who blocked it), and the shared conversations
 * from the blocker's inbox. Those stay readable, read-only, from the booking.
 *
 * The escrow rule — what a block never stops: a booking that already exists
 * can still be paid once accepted, delivered, approved, sent back for
 * changes, disputed, cancelled, rejected and reviewed, and the platform's own
 * notices about it still reach both sides. Money held in escrow must always
 * be able to settle, and a block must never become a way to walk away from a
 * paid job or to strand the other side's money.
 */
final class BlockService
{
    /** The most accounts one person may block: a bound on the table, far above real use. */
    public const MAX_BLOCKS = 1000;

    /** Why a blocked action was refused, by who made the block. */
    private const BY_YOU    = 'You blocked this account. Unblock it in Settings → Privacy & data → Blocked accounts to continue.';
    private const BY_OTHERS = 'This account is not accepting messages or requests from you.';

    // -----------------------------------------------------------------
    // Blocking and unblocking
    // -----------------------------------------------------------------

    /**
     * Block an account. Idempotent: blocking someone already blocked changes
     * nothing and reports `created` false.
     *
     * @return array{created:bool,block:array<string,mixed>}
     */
    public static function block(int $blockerId, int $blockedId): array
    {
        if ($blockedId === $blockerId) {
            throw HttpException::unprocessable('You cannot block yourself.');
        }

        // A guest has no profile anyone could see, and an erased account no
        // longer exists to block.
        $target = Database::selectOne(
            "SELECT `id` FROM `users`
              WHERE `id` = :id AND `deleted_at` IS NULL AND `status` <> 'deleted' AND `account_type` <> 'guest'",
            ['id' => $blockedId]
        );

        if ($target === null) {
            throw HttpException::notFound('That account could not be found.');
        }

        if (!self::hasBlocked($blockerId, $blockedId) && self::count($blockerId) >= self::MAX_BLOCKS) {
            throw HttpException::unprocessable('You have blocked as many accounts as you can. Unblock someone first.');
        }

        $created = Database::transaction(static function () use ($blockerId, $blockedId): bool {
            $inserted = Database::execute(
                'INSERT IGNORE INTO `user_blocks` (`blocker_id`, `blocked_id`, `created_at`)
                 VALUES (:blocker, :blocked, :now)',
                ['blocker' => $blockerId, 'blocked' => $blockedId, 'now' => date('Y-m-d H:i:s')]
            ) > 0;

            if ($inserted) {
                self::severTies($blockerId, $blockedId);
            }

            return $inserted;
        });

        return ['created' => $created, 'block' => self::entry($blockerId, $blockedId) ?? []];
    }

    /** Lift a block. Idempotent: true only when there was one to lift. */
    public static function unblock(int $blockerId, int $blockedId): bool
    {
        return Database::execute(
            'DELETE FROM `user_blocks` WHERE `blocker_id` = :blocker AND `blocked_id` = :blocked',
            ['blocker' => $blockerId, 'blocked' => $blockedId]
        ) > 0;
    }

    /**
     * What blocking ends at once, inside the block's transaction.
     *
     * Follows and saved creators go both ways (the follower counter moves
     * with them), and the blocker's unread count in the conversations the two
     * share is cleared: those threads leave the blocker's inbox, and a badge
     * nothing on screen can clear would be stuck there for good. None of it
     * comes back on an unblock; each side can follow or save again.
     */
    private static function severTies(int $blockerId, int $blockedId): void
    {
        foreach ([[$blockerId, $blockedId], [$blockedId, $blockerId]] as [$userId, $ownerId]) {
            $creatorId = (int) (Database::scalar(
                'SELECT `id` FROM `creators` WHERE `user_id` = :id',
                ['id' => $ownerId]
            ) ?? 0);

            if ($creatorId <= 0) {
                continue;
            }

            $unfollowed = Database::execute(
                'DELETE FROM `follows` WHERE `follower_id` = :user AND `creator_id` = :creator',
                ['user' => $userId, 'creator' => $creatorId]
            );

            if ($unfollowed > 0) {
                Database::execute(
                    'UPDATE `creators` SET `platform_followers` = GREATEST(0, CAST(`platform_followers` AS SIGNED) - :n)
                      WHERE `id` = :creator',
                    ['n' => $unfollowed, 'creator' => $creatorId]
                );
            }

            Database::execute(
                'DELETE FROM `favorites` WHERE `user_id` = :user AND `creator_id` = :creator',
                ['user' => $userId, 'creator' => $creatorId]
            );
        }

        Database::execute(
            'UPDATE `conversation_participants` p
               JOIN `conversation_participants` o
                 ON o.`conversation_id` = p.`conversation_id` AND o.`user_id` = :blocked
                SET p.`unread_count` = 0, p.`is_typing` = 0
              WHERE p.`user_id` = :blocker',
            ['blocker' => $blockerId, 'blocked' => $blockedId]
        );
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * The accounts someone blocked, newest first, as the Blocked accounts
     * screen shows them.
     *
     * @return list<array<string,mixed>>
     */
    public static function list(int $blockerId, int $limit = 50, int $offset = 0): array
    {
        $rows = Database::select(
            'SELECT b.`blocked_id`, b.`created_at`,
                    u.`name`, u.`company_name`, u.`avatar_url`, u.`account_type`,
                    c.`display_name`, c.`handle`
               FROM `user_blocks` b
               INNER JOIN `users` u ON u.`id` = b.`blocked_id`
               LEFT JOIN `creators` c ON c.`user_id` = u.`id` AND c.`deleted_at` IS NULL
              WHERE b.`blocker_id` = :id
              ORDER BY b.`id` DESC
              LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            ['id' => $blockerId]
        );

        return array_map([self::class, 'present'], $rows);
    }

    public static function count(int $blockerId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `user_blocks` WHERE `blocker_id` = :id',
            ['id' => $blockerId]
        );
    }

    /** One block as list() presents it, or null when there is none. */
    public static function entry(int $blockerId, int $blockedId): ?array
    {
        $row = Database::selectOne(
            'SELECT b.`blocked_id`, b.`created_at`,
                    u.`name`, u.`company_name`, u.`avatar_url`, u.`account_type`,
                    c.`display_name`, c.`handle`
               FROM `user_blocks` b
               INNER JOIN `users` u ON u.`id` = b.`blocked_id`
               LEFT JOIN `creators` c ON c.`user_id` = u.`id` AND c.`deleted_at` IS NULL
              WHERE b.`blocker_id` = :blocker AND b.`blocked_id` = :blocked',
            ['blocker' => $blockerId, 'blocked' => $blockedId]
        );

        return $row === null ? null : self::present($row);
    }

    /** Has [$blockerId] blocked [$blockedId]? One direction only. */
    public static function hasBlocked(int $blockerId, int $blockedId): bool
    {
        return Database::scalar(
            'SELECT 1 FROM `user_blocks` WHERE `blocker_id` = :blocker AND `blocked_id` = :blocked',
            ['blocker' => $blockerId, 'blocked' => $blockedId]
        ) !== null;
    }

    /** Is there a block between the two, made by either of them? */
    public static function isBlocked(int $userA, int $userB): bool
    {
        return self::blockersBetween($userA, $userB) !== [];
    }

    /**
     * Refuse an action [$actorId] is taking towards [$otherId] when a block
     * stands between them, saying which way it runs: someone who blocked the
     * other is told how to undo it.
     *
     * @throws HttpException 403 `blocked`
     */
    public static function assertNotBlocked(int $actorId, int $otherId): void
    {
        $blockers = self::blockersBetween($actorId, $otherId);

        if ($blockers === []) {
            return;
        }

        throw new HttpException(
            in_array($actorId, $blockers, true) ? self::BY_YOU : self::BY_OTHERS,
            403,
            'blocked'
        );
    }

    /**
     * The same refusal for a conversation: [$userId] may not post in it while
     * a block stands between them and anyone else in it.
     *
     * @throws HttpException 403 `blocked`
     */
    public static function assertCanMessage(int $conversationId, int $userId): void
    {
        $blockers = self::conversationBlockers($conversationId, $userId);

        if ($blockers === []) {
            return;
        }

        throw new HttpException(
            in_array($userId, $blockers, true) ? self::BY_YOU : self::BY_OTHERS,
            403,
            'blocked'
        );
    }

    /** Can [$userId] post in the conversation, as far as blocks go? */
    public static function canMessage(int $conversationId, int $userId): bool
    {
        return self::conversationBlockers($conversationId, $userId) === [];
    }

    /**
     * Everyone with a block between them and [$userId], in either direction.
     *
     * @return list<int>
     */
    public static function relatedIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return array_values(array_unique(array_map('intval', Database::column(
            'SELECT `blocked_id` FROM `user_blocks` WHERE `blocker_id` = :id
             UNION
             SELECT `blocker_id` FROM `user_blocks` WHERE `blocked_id` = :id',
            ['id' => $userId]
        ))));
    }

    /**
     * A WHERE fragment that keeps out every account with a block between it
     * and the viewer, for listings that join the account as [$column]. The
     * caller binds the viewer's id as [$placeholder].
     */
    public static function notBlockedSql(string $column, string $placeholder = 'block_viewer'): string
    {
        return 'NOT EXISTS (SELECT 1 FROM `user_blocks` blk
                 WHERE (blk.`blocker_id` = :' . $placeholder . ' AND blk.`blocked_id` = ' . $column . ')
                    OR (blk.`blocker_id` = ' . $column . ' AND blk.`blocked_id` = :' . $placeholder . '))';
    }

    /**
     * Both sides of a user's blocks, for the admin panel's user page.
     *
     * @return array{blocked:list<array<string,mixed>>,blocked_by:list<array<string,mixed>>,blocked_count:int,blocked_by_count:int}
     */
    public static function adminOverview(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $blocked = Database::select(
            'SELECT b.`blocked_id` AS `user_id`, b.`created_at`, u.`name`, u.`account_type`, u.`status`
               FROM `user_blocks` b INNER JOIN `users` u ON u.`id` = b.`blocked_id`
              WHERE b.`blocker_id` = :id ORDER BY b.`id` DESC LIMIT ' . $limit,
            ['id' => $userId]
        );

        $blockedBy = Database::select(
            'SELECT b.`blocker_id` AS `user_id`, b.`created_at`, u.`name`, u.`account_type`, u.`status`
               FROM `user_blocks` b INNER JOIN `users` u ON u.`id` = b.`blocker_id`
              WHERE b.`blocked_id` = :id ORDER BY b.`id` DESC LIMIT ' . $limit,
            ['id' => $userId]
        );

        return [
            'blocked'          => $blocked,
            'blocked_by'       => $blockedBy,
            'blocked_count'    => self::count($userId),
            'blocked_by_count' => (int) Database::scalar(
                'SELECT COUNT(*) FROM `user_blocks` WHERE `blocked_id` = :id',
                ['id' => $userId]
            ),
        ];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Who made the blocks between two accounts: none, one or both of them.
     *
     * @return list<int>
     */
    private static function blockersBetween(int $userA, int $userB): array
    {
        if ($userA <= 0 || $userB <= 0 || $userA === $userB) {
            return [];
        }

        return array_map('intval', Database::column(
            'SELECT `blocker_id` FROM `user_blocks`
              WHERE (`blocker_id` = :a AND `blocked_id` = :b) OR (`blocker_id` = :b AND `blocked_id` = :a)',
            ['a' => $userA, 'b' => $userB]
        ));
    }

    /**
     * Who made the blocks between [$userId] and the others in a conversation.
     *
     * @return list<int>
     */
    private static function conversationBlockers(int $conversationId, int $userId): array
    {
        return array_map('intval', Database::column(
            'SELECT blk.`blocker_id`
               FROM `conversation_participants` o
               INNER JOIN `user_blocks` blk
                       ON (blk.`blocker_id` = :user AND blk.`blocked_id` = o.`user_id`)
                       OR (blk.`blocker_id` = o.`user_id` AND blk.`blocked_id` = :user)
              WHERE o.`conversation_id` = :conversation AND o.`user_id` <> :user',
            ['user' => $userId, 'conversation' => $conversationId]
        ));
    }

    /**
     * A block as the app shows it: who, under the name they go by on Zusty.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function present(array $row): array
    {
        $name = trim((string) ($row['display_name'] ?? ''))
            ?: trim((string) ($row['company_name'] ?? ''))
            ?: trim((string) ($row['name'] ?? ''))
            ?: 'Zusty user';

        return [
            'user_id'      => (int) $row['blocked_id'],
            'name'         => $name,
            'avatar_url'   => $row['avatar_url'] ?? null,
            'account_type' => (string) ($row['account_type'] ?? ''),
            'handle'       => $row['handle'] ?? null,
            'blocked_at'   => $row['created_at'],
        ];
    }
}

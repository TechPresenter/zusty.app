<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Helpers\Str;

/**
 * Conversations and messaging.
 *
 * MySQL is the system of record. The client subscribes to Firestore for
 * realtime delivery, but a message is only "sent" once the row below exists —
 * the Firestore write is a mirror, not the source of truth.
 */
final class MessageService
{
    private const TYPING_TTL_SECONDS = 6;

    /** Find or create the conversation attached to a booking. */
    public static function ensureBookingConversation(int $bookingId, int $brandUserId, int $creatorUserId): array
    {
        $existing = Database::selectOne(
            'SELECT * FROM `conversations` WHERE `booking_id` = :booking_id LIMIT 1',
            ['booking_id' => $bookingId]
        );

        if ($existing !== null) {
            return $existing;
        }

        return Database::transaction(static function () use ($bookingId, $brandUserId, $creatorUserId): array {
            $conversationId = Database::insert('conversations', [
                'uuid'       => Crypto::uuid4(),
                'type'       => 'booking',
                'booking_id' => $bookingId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            Database::insertIgnore('conversation_participants', [
                'conversation_id' => $conversationId,
                'user_id'         => $brandUserId,
                'role'            => 'brand',
                'joined_at'       => date('Y-m-d H:i:s'),
            ]);

            Database::insertIgnore('conversation_participants', [
                'conversation_id' => $conversationId,
                'user_id'         => $creatorUserId,
                'role'            => 'creator',
                'joined_at'       => date('Y-m-d H:i:s'),
            ]);

            $conversation = Database::selectOne('SELECT * FROM `conversations` WHERE `id` = :id', ['id' => $conversationId]);

            if ($conversation === null) {
                throw HttpException::server('Failed to open the conversation.');
            }

            return $conversation;
        });
    }

    /** Find or create a direct conversation between two users. */
    public static function ensureDirectConversation(int $userA, int $userB): array
    {
        if ($userA === $userB) {
            throw HttpException::unprocessable('You cannot start a conversation with yourself.');
        }

        $existing = Database::selectOne(
            "SELECT c.* FROM `conversations` c
             INNER JOIN `conversation_participants` p1 ON p1.`conversation_id` = c.`id` AND p1.`user_id` = :a
             INNER JOIN `conversation_participants` p2 ON p2.`conversation_id` = c.`id` AND p2.`user_id` = :b
             WHERE c.`type` = 'direct'
             LIMIT 1",
            ['a' => $userA, 'b' => $userB]
        );

        if ($existing !== null) {
            return $existing;
        }

        return Database::transaction(static function () use ($userA, $userB): array {
            $conversationId = Database::insert('conversations', [
                'uuid'       => Crypto::uuid4(),
                'type'       => 'direct',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ([$userA, $userB] as $userId) {
                $accountType = (string) Database::scalar(
                    'SELECT `account_type` FROM `users` WHERE `id` = :id',
                    ['id' => $userId]
                );

                Database::insertIgnore('conversation_participants', [
                    'conversation_id' => $conversationId,
                    'user_id'         => $userId,
                    'role'            => $accountType === 'creator' ? 'creator' : 'brand',
                    'joined_at'       => date('Y-m-d H:i:s'),
                ]);
            }

            $conversation = Database::selectOne('SELECT * FROM `conversations` WHERE `id` = :id', ['id' => $conversationId]);

            if ($conversation === null) {
                throw HttpException::server('Failed to open the conversation.');
            }

            return $conversation;
        });
    }

    /**
     * Send a message.
     *
     * `$clientUuid` makes the send idempotent: a retry after a flaky network
     * returns the original row instead of posting a duplicate.
     *
     * @param array<string,mixed> $payload
     */
    public static function send(int $conversationId, int $senderId, array $payload): array
    {
        self::assertParticipant($conversationId, $senderId);

        $conversation = Database::selectOne('SELECT * FROM `conversations` WHERE `id` = :id', ['id' => $conversationId]);

        if ($conversation === null) {
            throw HttpException::notFound('Conversation not found.');
        }

        if ((int) $conversation['is_locked'] === 1) {
            throw HttpException::forbidden('This conversation is closed.');
        }

        // Either side's block closes every thread the two share, a booking's
        // included: the booking itself carries on through its own actions.
        BlockService::assertCanMessage($conversationId, $senderId);

        $uuid = (string) ($payload['uuid'] ?? '');
        if ($uuid !== '') {
            $existing = Database::selectOne('SELECT * FROM `messages` WHERE `uuid` = :uuid', ['uuid' => $uuid]);
            if ($existing !== null) {
                return $existing;
            }
        } else {
            $uuid = Crypto::uuid4();
        }

        $type = (string) ($payload['type'] ?? 'text');
        $body = isset($payload['body']) ? trim((string) $payload['body']) : null;

        if ($type === 'text' && ($body === null || $body === '')) {
            throw HttpException::unprocessable('Message text cannot be empty.');
        }

        if ($type === 'image' && empty($payload['media_url'])) {
            throw HttpException::unprocessable('An image message requires a media URL.');
        }

        return Database::transaction(static function () use ($conversationId, $senderId, $payload, $uuid, $type, $body): array {
            $messageId = Database::insert('messages', [
                'uuid'            => $uuid,
                'conversation_id' => $conversationId,
                'sender_id'       => $senderId,
                'sender_type'     => 'user',
                'type'            => $type,
                'body'            => $body === null ? null : mb_substr($body, 0, 4000),
                'media_url'       => $payload['media_url'] ?? null,
                'thumbnail_url'   => $payload['thumbnail_url'] ?? null,
                'media_mime'      => $payload['media_mime'] ?? null,
                'media_size'      => isset($payload['media_size']) ? (int) $payload['media_size'] : null,
                'media_width'     => isset($payload['media_width']) ? (int) $payload['media_width'] : null,
                'media_height'    => isset($payload['media_height']) ? (int) $payload['media_height'] : null,
                'metadata'        => isset($payload['metadata']) && is_array($payload['metadata'])
                    ? json_encode($payload['metadata'], JSON_UNESCAPED_UNICODE)
                    : null,
                'reply_to_id'     => isset($payload['reply_to_id']) ? (int) $payload['reply_to_id'] : null,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            $preview = match ($type) {
                'image'  => '📷 Photo',
                'file'   => '📎 Attachment',
                'audio'  => '🎤 Voice message',
                'offer'  => '💼 Offer',
                default  => Str::limit((string) $body, 120, '…'),
            };

            Database::update('conversations', [
                'last_message_id'   => $messageId,
                'last_message_text' => mb_substr($preview, 0, 255),
                'last_message_at'   => date('Y-m-d H:i:s'),
                'messages_count'    => (int) Database::scalar(
                    'SELECT COUNT(*) FROM `messages` WHERE `conversation_id` = :id',
                    ['id' => $conversationId]
                ),
            ], ['id' => $conversationId]);

            // Bump unread for everyone except the sender.
            Database::execute(
                'UPDATE `conversation_participants`
                 SET `unread_count` = `unread_count` + 1
                 WHERE `conversation_id` = :conversation_id AND `user_id` <> :sender_id',
                ['conversation_id' => $conversationId, 'sender_id' => $senderId]
            );

            // Two different lists, deliberately.
            //
            // Muting is about not being interrupted, not about not receiving.
            // Deriving the receipt rows from the un-muted list meant a muted
            // participant got no receipt at all, so the message could never be
            // marked read for them and the sender saw it as undelivered
            // forever. Receipts go to EVERY participant; only the push/email
            // fan-out honours the mute.

            /** @var list<int> $participants everyone but the sender */
            $participants = array_map('intval', Database::column(
                'SELECT `user_id` FROM `conversation_participants`
                 WHERE `conversation_id` = :conversation_id AND `user_id` <> :sender_id',
                ['conversation_id' => $conversationId, 'sender_id' => $senderId]
            ));

            foreach ($participants as $participantId) {
                Database::insertIgnore('message_receipts', [
                    'message_id' => $messageId,
                    'user_id'    => $participantId,
                ]);
            }

            /** @var list<int> $recipients those who should actually be notified */
            $recipients = array_map('intval', Database::column(
                'SELECT `user_id` FROM `conversation_participants`
                 WHERE `conversation_id` = :conversation_id AND `user_id` <> :sender_id AND `is_muted` = 0',
                ['conversation_id' => $conversationId, 'sender_id' => $senderId]
            ));

            $senderName = (string) Database::scalar('SELECT `name` FROM `users` WHERE `id` = :id', ['id' => $senderId]);

            NotificationService::sendMany($recipients, 'message.new', [
                'sender_name' => $senderName,
                'preview'     => $preview,
            ], [
                'action_type'  => 'chat',
                'action_value' => (string) $conversationId,
                'data'         => ['conversation_id' => (string) $conversationId],
                'actor_id'     => $senderId,
            ]);

            $message = Database::selectOne('SELECT * FROM `messages` WHERE `id` = :id', ['id' => $messageId]);

            if ($message === null) {
                throw HttpException::server('Failed to send the message.');
            }

            return $message;
        });
    }

    /** Post a system message into a booking conversation (status updates). */
    public static function systemMessage(int $conversationId, string $body, array $metadata = []): void
    {
        $messageId = Database::insert('messages', [
            'uuid'            => Crypto::uuid4(),
            'conversation_id' => $conversationId,
            'sender_id'       => null,
            'sender_type'     => 'system',
            'type'            => 'system',
            'body'            => mb_substr($body, 0, 4000),
            'metadata'        => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        Database::update('conversations', [
            'last_message_id'   => $messageId,
            'last_message_text' => mb_substr($body, 0, 255),
            'last_message_at'   => date('Y-m-d H:i:s'),
        ], ['id' => $conversationId]);
    }

    /**
     * Paginated message history, newest-first (the client reverses for display).
     *
     * @return array{items:list<array<string,mixed>>,has_more:bool,next_cursor:int|null}
     */
    public static function history(int $conversationId, int $viewerId, ?int $beforeId = null, int $limit = 30): array
    {
        self::assertParticipant($conversationId, $viewerId);

        $limit    = max(1, min(100, $limit));
        $bindings = ['conversation_id' => $conversationId];
        $cursor   = '';

        if ($beforeId !== null && $beforeId > 0) {
            $cursor              = ' AND `id` < :before_id';
            $bindings['before_id'] = $beforeId;
        }

        // Fetch one extra row to detect whether more history exists.
        $rows = Database::select(
            'SELECT * FROM `messages`
             WHERE `conversation_id` = :conversation_id AND `deleted_at` IS NULL' . $cursor . '
             ORDER BY `id` DESC LIMIT ' . ($limit + 1),
            $bindings
        );

        $hasMore = count($rows) > $limit;
        $items   = $hasMore ? array_slice($rows, 0, $limit) : $rows;

        return [
            'items'       => $items,
            'has_more'    => $hasMore,
            'next_cursor' => $hasMore && $items !== [] ? (int) $items[count($items) - 1]['id'] : null,
        ];
    }

    /**
     * Conversation list for the inbox, with the other party's details and
     * the caller's unread count.
     *
     * A thread with someone the caller blocked is left out: blocking hides
     * the conversation from the blocker, while the history stays readable
     * from the booking it belongs to. The other side still sees the thread,
     * with `other_blocked` set so the app shows it read-only.
     *
     * @return list<array<string,mixed>>
     */
    public static function inbox(int $userId, int $limit = 50, int $offset = 0): array
    {
        return Database::select(
            self::conversationSql() . ' AND c.`is_archived` = 0
               AND NOT EXISTS (SELECT 1 FROM `user_blocks` mb
                                WHERE mb.`blocker_id` = :user_id AND mb.`blocked_id` = op.`user_id`)
             ORDER BY COALESCE(c.`last_message_at`, c.`created_at`) DESC
             LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            ['user_id' => $userId]
        );
    }

    /**
     * One thread as its inbox row would read, for the chat screen: the other
     * person's name and profile, and whether a block closes it. Unlike the
     * inbox it is also returned for a thread with someone the caller blocked,
     * which the inbox leaves out but a booking can still open.
     *
     * @return array<string,mixed>|null
     */
    public static function conversationFor(int $conversationId, int $userId): ?array
    {
        return Database::selectOne(
            self::conversationSql() . ' AND c.`id` = :conversation_id LIMIT 1',
            ['user_id' => $userId, 'conversation_id' => $conversationId]
        );
    }

    /**
     * The caller's threads with the other party's details, open-ended for
     * the caller to finish the WHERE clause. Binds `:user_id`.
     *
     * `other_verified` is the other party's blue tick: a creator's lives on
     * `creators.is_verified`, a brand's on `users.has_blue_tick` — both
     * written only by KycService::grantBadge() / revokeBadge().
     *
     * `other_blocked` is set when the other party blocked the caller and
     * `me_blocked` when the caller blocked them. Across a block either way
     * the other party's presence is not given out: `other_last_seen` is null,
     * so nobody watches for someone who blocked them coming online.
     */
    private static function conversationSql(): string
    {
        return 'SELECT c.*, p.`unread_count`, p.`is_muted`, p.`last_read_at`,
                    other.`id`         AS `other_user_id`,
                    other.`name`       AS `other_name`,
                    other.`avatar_url` AS `other_avatar`,
                    cr.`display_name`  AS `other_display_name`,
                    cr.`headline`      AS `other_headline`,
                    cr.`handle`        AS `other_handle`,
                    CASE WHEN other.`account_type` = "brand"
                         THEN other.`has_blue_tick`
                         ELSE cr.`is_verified` END AS `other_verified`,
                    EXISTS(SELECT 1 FROM `user_blocks` ob
                            WHERE ob.`blocker_id` = op.`user_id` AND ob.`blocked_id` = :user_id) AS `other_blocked`,
                    EXISTS(SELECT 1 FROM `user_blocks` yb
                            WHERE yb.`blocker_id` = :user_id AND yb.`blocked_id` = op.`user_id`) AS `me_blocked`,
                    CASE WHEN EXISTS(SELECT 1 FROM `user_blocks` xb
                                      WHERE (xb.`blocker_id` = op.`user_id` AND xb.`blocked_id` = :user_id)
                                         OR (xb.`blocker_id` = :user_id AND xb.`blocked_id` = op.`user_id`))
                         THEN NULL ELSE other.`last_seen_at` END AS `other_last_seen`,
                    b.`code`           AS `booking_code`,
                    b.`status`         AS `booking_status`
             FROM `conversation_participants` p
             INNER JOIN `conversations` c ON c.`id` = p.`conversation_id`
             LEFT JOIN `conversation_participants` op
                    ON op.`conversation_id` = c.`id` AND op.`user_id` <> p.`user_id`
             LEFT JOIN `users` other ON other.`id` = op.`user_id`
             LEFT JOIN `creators` cr ON cr.`user_id` = other.`id`
             LEFT JOIN `bookings` b ON b.`id` = c.`booking_id`
             WHERE p.`user_id` = :user_id AND p.`left_at` IS NULL';
    }

    /**
     * A moderator takes a reported message down: it leaves the thread for
     * everyone in it, and the inbox preview stops quoting it. The row is
     * soft-deleted, so the report's copy and the audit trail still say what
     * it was.
     *
     * @return array<string,mixed>|null The message as it was, or null when it
     *                                  was already gone.
     */
    public static function removeByModerator(int $messageId): ?array
    {
        return Database::transaction(static function () use ($messageId): ?array {
            $message = Database::selectOne(
                'SELECT * FROM `messages` WHERE `id` = :id AND `deleted_at` IS NULL FOR UPDATE',
                ['id' => $messageId]
            );

            if ($message === null) {
                return null;
            }

            Database::update('messages', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $messageId]);

            $conversationId = (int) $message['conversation_id'];
            $latest         = Database::selectOne(
                'SELECT `id`, `type`, `body`, `created_at` FROM `messages`
                  WHERE `conversation_id` = :id AND `deleted_at` IS NULL
                  ORDER BY `id` DESC LIMIT 1',
                ['id' => $conversationId]
            );

            $preview = $latest === null ? null : match ((string) $latest['type']) {
                'image' => '📷 Photo',
                'file'  => '📎 Attachment',
                'audio' => '🎤 Voice message',
                'offer' => '💼 Offer',
                default => Str::limit((string) $latest['body'], 120, '…'),
            };

            Database::update('conversations', [
                'last_message_id'   => $latest === null ? null : (int) $latest['id'],
                'last_message_text' => $preview === null ? null : mb_substr($preview, 0, 255),
            ], ['id' => $conversationId]);

            return $message;
        });
    }

    /** Mark everything up to the newest message as read. */
    public static function markRead(int $conversationId, int $userId): int
    {
        self::assertParticipant($conversationId, $userId);

        return Database::transaction(static function () use ($conversationId, $userId): int {
            $lastMessageId = (int) (Database::scalar(
                'SELECT MAX(`id`) FROM `messages` WHERE `conversation_id` = :id',
                ['id' => $conversationId]
            ) ?? 0);

            Database::execute(
                'UPDATE `conversation_participants`
                 SET `unread_count` = 0, `last_read_at` = NOW(), `last_read_message_id` = :message_id
                 WHERE `conversation_id` = :conversation_id AND `user_id` = :user_id',
                ['message_id' => $lastMessageId ?: null, 'conversation_id' => $conversationId, 'user_id' => $userId]
            );

            $updated = Database::execute(
                'UPDATE `message_receipts` r
                 INNER JOIN `messages` m ON m.`id` = r.`message_id`
                 SET r.`read_at` = NOW(), r.`delivered_at` = COALESCE(r.`delivered_at`, NOW())
                 WHERE m.`conversation_id` = :conversation_id AND r.`user_id` = :user_id AND r.`read_at` IS NULL',
                ['conversation_id' => $conversationId, 'user_id' => $userId]
            );

            if ($updated > 0) {
                Database::execute(
                    'UPDATE `messages` m
                     SET m.`read_count` = (
                         SELECT COUNT(*) FROM `message_receipts` r
                         WHERE r.`message_id` = m.`id` AND r.`read_at` IS NOT NULL
                     )
                     WHERE m.`conversation_id` = :conversation_id AND m.`sender_id` <> :user_id',
                    ['conversation_id' => $conversationId, 'user_id' => $userId]
                );
            }

            return $updated;
        });
    }

    /** Publish a typing indicator that expires on its own. */
    public static function setTyping(int $conversationId, int $userId, bool $isTyping): void
    {
        self::assertParticipant($conversationId, $userId);

        // Nobody can post into a blocked thread, so nobody is shown typing.
        if ($isTyping && !BlockService::canMessage($conversationId, $userId)) {
            return;
        }

        Database::execute(
            'UPDATE `conversation_participants`
             SET `is_typing` = :is_typing, `typing_until` = :until
             WHERE `conversation_id` = :conversation_id AND `user_id` = :user_id',
            [
                'is_typing'       => $isTyping ? 1 : 0,
                'until'           => $isTyping ? date('Y-m-d H:i:s', time() + self::TYPING_TTL_SECONDS) : null,
                'conversation_id' => $conversationId,
                'user_id'         => $userId,
            ]
        );
    }

    /** Who else is currently typing in this conversation. */
    public static function typingUsers(int $conversationId, int $excludeUserId): array
    {
        return Database::select(
            'SELECT p.`user_id`, u.`name`
             FROM `conversation_participants` p
             INNER JOIN `users` u ON u.`id` = p.`user_id`
             WHERE p.`conversation_id` = :conversation_id
               AND p.`user_id` <> :exclude
               AND p.`is_typing` = 1
               AND p.`typing_until` > NOW()',
            ['conversation_id' => $conversationId, 'exclude' => $excludeUserId]
        );
    }

    public static function totalUnread(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COALESCE(SUM(`unread_count`), 0) FROM `conversation_participants`
             WHERE `user_id` = :user_id AND `left_at` IS NULL',
            ['user_id' => $userId]
        );
    }

    public static function assertParticipant(int $conversationId, int $userId): void
    {
        $isParticipant = Database::scalar(
            'SELECT 1 FROM `conversation_participants`
             WHERE `conversation_id` = :conversation_id AND `user_id` = :user_id AND `left_at` IS NULL',
            ['conversation_id' => $conversationId, 'user_id' => $userId]
        );

        if ($isParticipant === null) {
            throw HttpException::forbidden('You are not part of this conversation.');
        }
    }
}

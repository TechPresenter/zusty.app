<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Str;
use Zuste\Services\BlockService;
use Zuste\Services\MessageService;
use Zuste\Services\UploadService;

/**
 * Chat.
 *
 * Routes: /api/v1/conversations/*
 */
final class MessageController extends BaseApiController
{
    /** GET /conversations */
    public function index(Request $request): never
    {
        $userId     = $this->userId($request);
        $pagination = $this->pagination($request, 30, 100);

        $rows = MessageService::inbox($userId, $pagination['per_page'], $pagination['offset']);

        Response::success(['conversations' => array_map(self::presentConversation(...), $rows)], 'OK', 200, [
            'total_unread' => MessageService::totalUnread($userId),
        ]);
    }

    /**
     * One inbox row. GET /conversations/{id}/messages returns the same shape
     * as `conversation`, so the chat keeps its header and menu for a thread
     * the inbox leaves out (one with someone the caller blocked).
     *
     * @param  array<string,mixed> $row from MessageService::inbox()/conversationFor()
     * @return array<string,mixed>
     */
    private static function presentConversation(array $row): array
    {
        // Null across a block either way (MessageService::conversationSql).
        $lastSeen  = $row['other_last_seen'] ?? null;
        $iBlocked  = (bool) ($row['me_blocked'] ?? false);
        $theyBlock = (bool) ($row['other_blocked'] ?? false);

        return [
            'id'              => (int) $row['id'],
            'uuid'            => (string) $row['uuid'],
            'type'            => (string) $row['type'],
            'booking_id'      => $row['booking_id'] === null ? null : (int) $row['booking_id'],
            'booking_code'    => $row['booking_code'] ?? null,
            'booking_status'  => $row['booking_status'] ?? null,
            'participant'     => [
                'user_id'     => $row['other_user_id'] === null ? null : (int) $row['other_user_id'],
                'name'        => (string) ($row['other_display_name'] ?: $row['other_name'] ?: 'Zusty user'),
                'headline'    => $row['other_headline'] ?? null,
                // The creator's public handle, so the chat header can open
                // their profile. Null when the other party is a brand,
                // which has no public profile to open.
                'handle'      => $row['other_handle'] ?? null,
                'avatar_url'  => $row['other_avatar'] ?? null,
                'is_verified' => (bool) ($row['other_verified'] ?? false),
                'is_online'   => $lastSeen !== null && strtotime((string) $lastSeen) > time() - 300,
                // The caller blocked this person, so the app offers Unblock.
                'is_blocked'  => $iBlocked,
            ],
            'last_message'    => $row['last_message_text'],
            'last_message_at' => $row['last_message_at'],
            'time_ago'        => Str::timeAgo($row['last_message_at'] ?? $row['created_at']),
            'unread_count'    => (int) $row['unread_count'],
            'is_muted'        => (bool) $row['is_muted'],
            'is_locked'       => (bool) $row['is_locked'],
            // False across a block either way: the thread stays readable, and
            // the app swaps the composer for a notice.
            'can_message'     => !$theyBlock && !$iBlocked,
        ];
    }

    /** GET /conversations/{id}/messages */
    public function messages(Request $request): never
    {
        $conversationId = $request->routeParamInt('id');
        $userId         = $this->userId($request);

        $history = MessageService::history(
            $conversationId,
            $userId,
            $request->int('before_id') ?: null,
            $request->perPage(30, 100)
        );

        $items = array_map(static function (array $row) use ($userId): array {
            return [
                'id'            => (int) $row['id'],
                'uuid'          => (string) $row['uuid'],
                'type'          => (string) $row['type'],
                'body'          => $row['body'],
                'media_url'     => $row['media_url'],
                'thumbnail_url' => $row['thumbnail_url'],
                'media_width'   => $row['media_width'] === null ? null : (int) $row['media_width'],
                'media_height'  => $row['media_height'] === null ? null : (int) $row['media_height'],
                'metadata'      => json_decode((string) ($row['metadata'] ?? '{}'), true) ?: [],
                'sender_id'     => $row['sender_id'] === null ? null : (int) $row['sender_id'],
                'is_mine'       => (int) ($row['sender_id'] ?? 0) === $userId,
                'is_system'     => $row['sender_type'] === 'system',
                'read_count'    => (int) $row['read_count'],
                'is_read'       => (int) $row['read_count'] > 0,
                'created_at'    => $row['created_at'],
                'time_label'    => date('g:i A', strtotime((string) $row['created_at'])),
            ];
        }, $history['items']);

        // Opening the thread marks it read.
        MessageService::markRead($conversationId, $userId);

        $conversation = MessageService::conversationFor($conversationId, $userId);

        Response::success([
            'messages'     => $items,
            'has_more'     => $history['has_more'],
            'next_cursor'  => $history['next_cursor'],
            'typing'       => MessageService::typingUsers($conversationId, $userId),
            // A block either way leaves the thread readable but closed.
            'can_message'  => BlockService::canMessage($conversationId, $userId),
            // Who the thread is with, as the inbox row would say it.
            'conversation' => $conversation === null ? null : self::presentConversation($conversation),
        ]);
    }

    /** POST /conversations/{id}/messages */
    public function send(Request $request): never
    {
        $conversationId = $request->routeParamInt('id');
        $userId         = $this->userId($request);

        $payload = [
            'uuid'        => $request->string('uuid') ?: null,
            'type'        => $request->string('type', 'text'),
            'body'        => $request->string('body') ?: null,
            'reply_to_id' => $request->int('reply_to_id') ?: null,
        ];

        // Image sharing: accept a direct multipart upload.
        if ($request->hasFile('image')) {
            // Checked before the upload, so a refused photo is never stored.
            MessageService::assertParticipant($conversationId, $userId);
            BlockService::assertCanMessage($conversationId, $userId);

            $upload = UploadService::store((array) $request->file('image'), 'chat', 'image', 'user', $userId);

            $payload['type']          = 'image';
            $payload['media_url']     = $upload['url'];
            $payload['thumbnail_url'] = $upload['url'];
            $payload['media_mime']    = $upload['mime'];
            $payload['media_size']    = $upload['size'];
            $payload['media_width']   = $upload['width'];
            $payload['media_height']  = $upload['height'];
        } elseif ($request->filled('media_url')) {
            $payload['media_url']     = $request->string('media_url');
            $payload['thumbnail_url'] = $request->string('thumbnail_url') ?: $payload['media_url'];
        }

        $message = MessageService::send($conversationId, $userId, $payload);

        Response::created([
            'message' => [
                'id'            => (int) $message['id'],
                'uuid'          => (string) $message['uuid'],
                'type'          => (string) $message['type'],
                'body'          => $message['body'],
                'media_url'     => $message['media_url'],
                'thumbnail_url' => $message['thumbnail_url'],
                'is_mine'       => true,
                'created_at'    => $message['created_at'],
                'time_label'    => date('g:i A', strtotime((string) $message['created_at'])),
            ],
        ], 'Message sent.');
    }

    /** POST /conversations/{id}/read */
    public function markRead(Request $request): never
    {
        MessageService::markRead($request->routeParamInt('id'), $this->userId($request));

        Response::success(['total_unread' => MessageService::totalUnread($this->userId($request))]);
    }

    /** POST /conversations/{id}/typing */
    public function typing(Request $request): never
    {
        MessageService::setTyping(
            $request->routeParamInt('id'),
            $this->userId($request),
            $request->bool('is_typing', true)
        );

        Response::noContent();
    }

    /** GET /conversations/{id}/typing — polled by the client between pushes. */
    public function typingStatus(Request $request): never
    {
        $conversationId = $request->routeParamInt('id');
        $userId         = $this->userId($request);

        MessageService::assertParticipant($conversationId, $userId);

        Response::success(['typing' => MessageService::typingUsers($conversationId, $userId)]);
    }

    /** POST /conversations/start — open (or reuse) a direct conversation. */
    public function start(Request $request): never
    {
        $data = $this->validate($request, [
            'user_id'    => 'nullable|integer',
            'creator_id' => 'nullable|integer',
            'booking_id' => 'nullable|integer',
        ]);

        $userId = $this->userId($request);

        if (!empty($data['booking_id'])) {
            $booking = Database::selectOne(
                'SELECT * FROM `bookings` WHERE `id` = :id AND `deleted_at` IS NULL',
                ['id' => (int) $data['booking_id']]
            );

            if ($booking === null) {
                throw HttpException::notFound('Booking not found.');
            }

            if ((int) $booking['brand_user_id'] !== $userId && (int) $booking['creator_user_id'] !== $userId) {
                throw HttpException::forbidden('You are not a party to this booking.');
            }

            $conversation = MessageService::ensureBookingConversation(
                (int) $booking['id'],
                (int) $booking['brand_user_id'],
                (int) $booking['creator_user_id']
            );

            Response::success(['conversation_id' => (int) $conversation['id']]);
        }

        $otherUserId = (int) ($data['user_id'] ?? 0);

        if ($otherUserId === 0 && !empty($data['creator_id'])) {
            $otherUserId = (int) (Database::scalar(
                'SELECT `user_id` FROM `creators` WHERE `id` = :id AND `deleted_at` IS NULL',
                ['id' => (int) $data['creator_id']]
            ) ?? 0);
        }

        if ($otherUserId <= 0) {
            throw HttpException::unprocessable('Specify who you want to message.');
        }

        // The recipient must actually exist and be usable.
        //
        // Without this, starting a conversation against an arbitrary id
        // created a real `conversations` row with a single participant — an
        // orphan thread nobody can ever read or reply to, and a way to grow
        // the table without limit.
        $recipient = Database::selectOne(
            "SELECT `id`, `status` FROM `users`
              WHERE `id` = :id AND `deleted_at` IS NULL AND `account_type` <> 'guest'",
            ['id' => $otherUserId]
        );

        if ($recipient === null) {
            throw HttpException::notFound('That account could not be found.');
        }

        if (!in_array((string) $recipient['status'], ['active', 'pending'], true)) {
            throw HttpException::unprocessable('That account is not available for messaging.');
        }

        if ($otherUserId === $userId) {
            throw HttpException::unprocessable('You cannot start a conversation with yourself.');
        }

        // A booking's thread above opens whatever the two think of each
        // other, read-only once one has blocked the other; a new direct
        // conversation does not.
        BlockService::assertNotBlocked($userId, $otherUserId);

        $conversation = MessageService::ensureDirectConversation($userId, $otherUserId);

        Response::success(['conversation_id' => (int) $conversation['id']]);
    }

    /** POST /conversations/{id}/mute */
    public function mute(Request $request): never
    {
        $conversationId = $request->routeParamInt('id');
        $userId         = $this->userId($request);

        MessageService::assertParticipant($conversationId, $userId);

        // `muted` is what shipped app builds send. Honouring it means an
        // unmute from one of those actually unmutes instead of tripping the
        // default below and muting the thread again.
        $muted = match (true) {
            $request->has('is_muted') => $request->bool('is_muted'),
            $request->has('muted')    => $request->bool('muted'),
            // Nothing sent at all is still read as "mute this", which is what
            // the endpoint has always done for a bodiless call.
            default => true,
        };

        Database::execute(
            'UPDATE `conversation_participants` SET `is_muted` = :muted
             WHERE `conversation_id` = :conversation_id AND `user_id` = :user_id',
            ['muted' => $muted ? 1 : 0, 'conversation_id' => $conversationId, 'user_id' => $userId]
        );

        Response::success(['is_muted' => $muted], $muted ? 'Conversation muted.' : 'Conversation unmuted.');
    }
}

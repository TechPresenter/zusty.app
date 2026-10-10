<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Helpers\Str;

/**
 * Multi-channel notification dispatch.
 *
 * Copy lives in `notification_templates` so it is editable from the admin
 * panel. `send()` resolves the template, substitutes {{placeholders}}, writes
 * the in-app row immediately, and queues push/email/SMS for the cron worker —
 * so a slow SMTP server never delays an API response.
 */
final class NotificationService
{
    /**
     * Every member of the `notifications.action_type` ENUM.
     *
     * `notification_templates.action_type` is a VARCHAR, so an admin (or a
     * seed file) can put anything in it. Under STRICT_TRANS_TABLES an unknown
     * value makes the INSERT throw, which previously turned a cosmetic deep-link
     * mistake into a 500 on the action that triggered it — every KYC transition
     * failed this way. The value is now checked here instead.
     *
     * Keep in step with migration 021.
     */
    private const ACTION_TYPES = [
        'none', 'booking', 'creator', 'chat', 'wallet',
        'payment', 'url', 'review', 'ticket', 'kyc',
    ];

    /**
     * A new account's `users`.`notification_prefs`, and what a missing key
     * reads as.
     *
     * Marketing is off: product updates and offers go only to someone who
     * opted in, by ticking the box at sign-up or the switch in Settings →
     * Notifications (DPDP Act). Sign-up, the guest upgrade, the preferences
     * endpoint and every promotional sender read this one list.
     */
    public const DEFAULT_PREFERENCES = ['push' => true, 'email' => true, 'sms' => false, 'marketing' => false];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $templates = null;

    /**
     * Coerces an action type to something the column will accept.
     *
     * Degrades to `none` — the notification still reaches the user, it just
     * does not deep-link — and logs loudly so the bad template gets fixed.
     */
    private static function safeActionType(string $actionType, string $templateCode): string
    {
        if (in_array($actionType, self::ACTION_TYPES, true)) {
            return $actionType;
        }

        Logger::warning('Notification template has an unknown action_type', [
            'template'    => $templateCode,
            'action_type' => $actionType,
            'allowed'     => self::ACTION_TYPES,
        ]);

        return 'none';
    }

    /** Channels send() knows how to deliver on. */
    private const CHANNELS = ['in_app', 'push', 'email', 'sms'];

    /**
     * Send a templated notification to one user.
     *
     * `channels` in [$overrides] replaces the template's own channel list
     * (unknown names are dropped; an empty or unusable list falls back to the
     * template). A module whose settings own its channels — Settings → KYC →
     * "Notification channels" — passes it, so that setting is not a control
     * that does nothing.
     *
     * `actor_id` names the person whose action the notification is about.
     * Nothing is sent when a block stands between them and the recipient.
     * Platform notices about a booking that already exists leave it out on
     * purpose, so both sides still hear how the escrow settles (BlockService).
     *
     * @param array<string,mixed> $values      Placeholder substitutions.
     * @param array<string,mixed> $overrides   action_type / action_value / image_url / data / channels / actor_id.
     */
    public static function send(int $userId, string $templateCode, array $values = [], array $overrides = []): void
    {
        $template = self::template($templateCode);

        if ($template === null) {
            Logger::warning('Unknown notification template', ['code' => $templateCode]);

            return;
        }

        if ((int) $template['is_active'] !== 1) {
            return;
        }

        $actorId = (int) ($overrides['actor_id'] ?? 0);

        if ($actorId > 0 && BlockService::isBlocked($userId, $actorId)) {
            return;
        }

        $user = Database::selectOne(
            'SELECT `id`, `name`, `email`, `phone`, `phone_country_code`, `notification_prefs`, `status`
             FROM `users` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $userId]
        );

        if ($user === null || $user['status'] === 'banned') {
            return;
        }

        $preferences = self::preferences($user);
        $channels    = self::channels($template);

        // A template filed as `promo` or `marketing` is promotional, the same
        // rule sendRaw() applies to broadcasts: it reaches push, email and SMS
        // only for an account that opted in to marketing (off by default).
        // The in-app row below is still written — it is the account's own
        // notification centre, not an outbound message.
        $isPromotional = in_array((string) ($template['category'] ?? ''), ['promo', 'marketing'], true);
        $outboundOk    = !$isPromotional || $preferences['marketing'];

        if (is_array($overrides['channels'] ?? null)) {
            $chosen = array_values(array_intersect(
                self::CHANNELS,
                array_map('strval', array_filter($overrides['channels'], 'is_scalar'))
            ));

            if ($chosen !== []) {
                $channels = $chosen;
            }
        }

        $title = Str::interpolate((string) $template['title'], $values);
        $body  = Str::interpolate((string) $template['body'], $values);

        $actionType  = self::safeActionType(
            (string) ($overrides['action_type'] ?? $template['action_type'] ?? 'none'),
            $templateCode
        );
        $actionValue = isset($overrides['action_value']) ? (string) $overrides['action_value'] : null;
        $imageUrl    = isset($overrides['image_url']) ? (string) $overrides['image_url'] : null;

        $data = array_merge(
            ['type' => $templateCode, 'action_type' => $actionType],
            $actionValue !== null ? ['action_value' => $actionValue] : [],
            is_array($overrides['data'] ?? null) ? $overrides['data'] : []
        );

        $notificationId = 0;

        // 1. In-app — always written, this is the notification centre feed.
        if (in_array('in_app', $channels, true)) {
            $notificationId = Database::insert('notifications', [
                'user_id'      => $userId,
                'type'         => $templateCode,
                'category'     => (string) $template['category'],
                'title'        => mb_substr($title, 0, 191),
                'body'         => mb_substr($body, 0, 1000),
                'image_url'    => $imageUrl,
                'icon'         => $template['icon'] ?? null,
                'action_type'  => $actionType,
                'action_value' => $actionValue,
                'data'         => json_encode($data, JSON_UNESCAPED_UNICODE),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            $data['notification_id'] = (string) $notificationId;
        }

        // 2. Push.
        if (in_array('push', $channels, true) && ($preferences['push'] ?? true) && $outboundOk) {
            Database::insert('push_queue', [
                'target_type'  => 'user',
                'target_value' => (string) $userId,
                'title'        => mb_substr($title, 0, 191),
                'body'         => mb_substr($body, 0, 500),
                'image_url'    => $imageUrl,
                'data_payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            if ($notificationId > 0) {
                Database::update('notifications', ['sent_push' => 1], ['id' => $notificationId]);
            }
        }

        // 3. Email.
        if (in_array('email', $channels, true)
            && ($preferences['email'] ?? true)
            && $outboundOk
            && !empty($user['email'])
            && !empty($template['email_subject'])) {
            // The subject is a header, not HTML, so it keeps plain substitution.
            // The body is HTML: every value placed into it is escaped, because
            // the values are user- and admin-written text (names, reasons).
            $subject  = Str::interpolate((string) $template['email_subject'], $values);
            $htmlBody = !empty($template['email_body'])
                ? Str::interpolateHtml((string) $template['email_body'], $values)
                : MailService::renderDefault($title, $body, (string) $user['name'], $actionType, $actionValue);

            MailService::queue((string) $user['email'], (string) $user['name'], $subject, $htmlBody);

            if ($notificationId > 0) {
                Database::update('notifications', ['sent_email' => 1], ['id' => $notificationId]);
            }
        }

        // 4. SMS.
        if (in_array('sms', $channels, true)
            && ($preferences['sms'] ?? false)
            && $outboundOk
            && !empty($user['phone'])
            && !empty($template['sms_body'])) {
            $queued = SmsService::queue(
                (string) $user['phone_country_code'] . (string) $user['phone'],
                Str::interpolate((string) $template['sms_body'], $values)
            );

            // queue() returns 0 when SMS is switched off or the number is not
            // usable. Silently dropping it made a template look deliverable
            // when not one message would ever leave the building.
            if ($queued === 0) {
                Logger::warning('Templated SMS was not queued', [
                    'template' => $templateCode,
                    'user_id'  => $userId,
                    'reason'   => 'SMS is disabled or the number is not valid',
                ]);
            }
        }
    }

    /**
     * Send to several users at once (booking parties, broadcast segments).
     *
     * @param list<int> $userIds
     */
    public static function sendMany(array $userIds, string $templateCode, array $values = [], array $overrides = []): void
    {
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId > 0) {
                self::send($userId, $templateCode, $values, $overrides);
            }
        }
    }

    /**
     * Write a notification without a template — used by the admin broadcast
     * composer, where the operator supplies the copy directly.
     */
    public static function sendRaw(
        int $userId,
        string $title,
        string $body,
        string $category = 'system',
        string $actionType = 'none',
        ?string $actionValue = null,
        ?string $imageUrl = null,
        array $channels = ['in_app', 'push']
    ): int {
        $notificationId = 0;

        // The composer (and older seed rows) can carry a deep-link type the
        // `notifications` ENUM has never had — 'screen' being the one the UI
        // itself offered. Under STRICT_TRANS_TABLES that made the INSERT throw
        // on the first recipient and took the whole blast down with it, so the
        // value is coerced here exactly as send() does.
        $actionType = self::safeActionType($actionType, 'admin.broadcast');

        // Broadcasts used to ignore every per-user preference. The in-app row
        // is the user's own notification centre and is always written (send()
        // behaves the same way); push and email are opt-out channels.
        $user = Database::selectOne(
            'SELECT `notification_prefs` FROM `users` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $userId]
        );

        $preferences = self::preferences($user ?? []);

        // Broadcasts are written with the `promo` category — precisely the
        // traffic the marketing opt-out exists for.
        $isPromotional = in_array($category, ['promo', 'marketing'], true);

        if (in_array('in_app', $channels, true)) {
            $notificationId = Database::insert('notifications', [
                'user_id'      => $userId,
                'type'         => 'admin.broadcast',
                'category'     => $category,
                'title'        => mb_substr($title, 0, 191),
                'body'         => mb_substr($body, 0, 1000),
                'image_url'    => $imageUrl,
                'icon'         => 'campaign',
                'action_type'  => $actionType,
                'action_value' => $actionValue,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        if (in_array('push', $channels, true)
            && $preferences['push']
            && (!$isPromotional || $preferences['marketing'])) {
            Database::insert('push_queue', [
                'target_type'  => 'user',
                'target_value' => (string) $userId,
                'title'        => mb_substr($title, 0, 191),
                'body'         => mb_substr($body, 0, 500),
                'image_url'    => $imageUrl,
                'data_payload' => json_encode([
                    'type'            => 'admin.broadcast',
                    'action_type'     => $actionType,
                    'action_value'    => (string) $actionValue,
                    'notification_id' => (string) $notificationId,
                ], JSON_UNESCAPED_UNICODE),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            if ($notificationId > 0) {
                Database::update('notifications', ['sent_push' => 1], ['id' => $notificationId]);
            }
        }

        return $notificationId;
    }

    /**
     * Every value `notifications`.`action_type` will accept.
     *
     * Exposed so the admin composer's validator and its <select> cannot drift
     * away from the column again.
     *
     * @return list<string>
     */
    public static function actionTypes(): array
    {
        return self::ACTION_TYPES;
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    public static function unreadCount(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :user_id AND `is_read` = 0',
            ['user_id' => $userId]
        );
    }

    public static function markRead(int $userId, int $notificationId): bool
    {
        return Database::execute(
            'UPDATE `notifications` SET `is_read` = 1, `read_at` = NOW()
             WHERE `id` = :id AND `user_id` = :user_id AND `is_read` = 0',
            ['id' => $notificationId, 'user_id' => $userId]
        ) > 0;
    }

    public static function markAllRead(int $userId): int
    {
        return Database::execute(
            'UPDATE `notifications` SET `is_read` = 1, `read_at` = NOW()
             WHERE `user_id` = :user_id AND `is_read` = 0',
            ['user_id' => $userId]
        );
    }

    // -----------------------------------------------------------------
    // Queue draining (called by the queue:dispatch cron job)
    // -----------------------------------------------------------------

    /** @return array{sent:int,failed:int} */
    public static function drainPushQueue(int $limit = 100): array
    {
        $jobs = Database::select(
            "SELECT * FROM `push_queue`
             WHERE `status` = 'pending' AND `available_at` <= NOW() AND `attempts` < 3
             ORDER BY `id` LIMIT " . max(1, $limit)
        );

        $sent   = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];

            Database::update('push_queue', ['status' => 'sending', 'attempts' => (int) $job['attempts'] + 1], ['id' => $jobId]);

            /** @var array<string,string> $payload */
            $payload = json_decode((string) ($job['data_payload'] ?? '{}'), true) ?: [];

            try {
                $result = match ((string) $job['target_type']) {
                    'user'  => FirebaseService::sendToUser(
                        (int) $job['target_value'],
                        (string) $job['title'],
                        (string) $job['body'],
                        $payload,
                        $job['image_url'] ?? null
                    ),
                    'topic' => self::normaliseSingle(FirebaseService::sendToTopic(
                        (string) $job['target_value'],
                        (string) $job['title'],
                        (string) $job['body'],
                        $payload,
                        $job['image_url'] ?? null
                    )),
                    default => self::normaliseSingle(FirebaseService::sendToToken(
                        (string) $job['target_value'],
                        (string) $job['title'],
                        (string) $job['body'],
                        $payload,
                        $job['image_url'] ?? null
                    )),
                };

                $succeeded = $result['sent'] > 0;

                // A user with no registered device can never succeed, so
                // retrying twice more only hides the reason. Terminate it now
                // and say why.
                $noDevices = !$succeeded
                    && $result['failed'] === 0
                    && ($result['devices'] ?? null) === 0;

                $reason = $succeeded
                    ? null
                    : ($noDevices
                        ? 'No push-enabled device is registered for this user.'
                        : (string) ($result['error'] ?? 'The push provider rejected the message.'));

                Database::update('push_queue', [
                    'status'        => $succeeded
                        ? 'sent'
                        : (($noDevices || (int) $job['attempts'] + 1 >= 3) ? 'failed' : 'pending'),
                    'success_count' => $result['sent'],
                    'failure_count' => $result['failed'],
                    // Without this the only path that ever wrote last_error was
                    // the catch below, so a broken FCM config produced rows that
                    // failed three times carrying no explanation at all.
                    'last_error'    => $reason === null ? null : mb_substr($reason, 0, 500),
                    'sent_at'       => $succeeded ? date('Y-m-d H:i:s') : null,
                    'available_at'  => $succeeded ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', time() + 300),
                ], ['id' => $jobId]);

                $succeeded ? $sent++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;

                Database::update('push_queue', [
                    'status'       => (int) $job['attempts'] + 1 >= 3 ? 'failed' : 'pending',
                    'last_error'   => mb_substr($e->getMessage(), 0, 500),
                    'available_at' => date('Y-m-d H:i:s', time() + 300),
                ], ['id' => $jobId]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** @return array{sent:int,failed:int,error:string|null,devices:int} */
    private static function normaliseSingle(array $result): array
    {
        return [
            'sent'    => $result['ok'] ? 1 : 0,
            'failed'  => $result['ok'] ? 0 : 1,
            'error'   => $result['error'] ?? null,
            'devices' => 1,
        ];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** Is there a template with this code (active or not)? */
    public static function hasTemplate(string $code): bool
    {
        return self::template($code) !== null;
    }

    /** @return array<string,mixed>|null */
    private static function template(string $code): ?array
    {
        if (self::$templates === null) {
            self::$templates = [];

            foreach (Database::select('SELECT * FROM `notification_templates`') as $row) {
                self::$templates[(string) $row['code']] = $row;
            }
        }

        return self::$templates[$code] ?? null;
    }

    /** @return list<string> */
    private static function channels(array $template): array
    {
        $channels = json_decode((string) ($template['channels'] ?? '[]'), true);

        return is_array($channels) && $channels !== [] ? array_map('strval', $channels) : ['in_app'];
    }

    /**
     * @param  array<string,mixed> $user
     * @return array<string,bool>
     */
    private static function preferences(array $user): array
    {
        $stored = json_decode((string) ($user['notification_prefs'] ?? ''), true);

        return is_array($stored)
            ? array_merge(self::DEFAULT_PREFERENCES, array_map('boolval', $stored))
            : self::DEFAULT_PREFERENCES;
    }

    /** Clear the template cache after an admin edit. */
    public static function flushTemplates(): void
    {
        self::$templates = null;
    }
}

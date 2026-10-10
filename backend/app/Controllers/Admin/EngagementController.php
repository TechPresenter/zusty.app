<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Helpers\Str;
use Zuste\Services\MailService;
use Zuste\Services\NotificationService;
use Zuste\Services\UploadService;

/**
 * Support tickets, notification broadcasts, templates and the contact inbox.
 */
final class EngagementController extends BaseAdminController
{
    // =================================================================
    // Support tickets
    // =================================================================

    /** GET /admin/tickets */
    public function tickets(Request $request): never
    {
        $this->authorize($request, 'support.view');

        $params   = $this->listParams($request, ['id', 'created_at', 'last_reply_at', 'priority'], 'last_reply_at');
        $clauses  = ['1 = 1'];
        $bindings = [];

        $status = $request->string('status', 'open');

        if ($status === 'open') {
            $clauses[] = "t.`status` IN ('open','pending_agent')";
        } elseif ($status !== 'all') {
            $clauses[]          = 't.`status` = :status';
            $bindings['status'] = $status;
        }

        if ($request->filled('priority')) {
            $clauses[]            = 't.`priority` = :priority';
            $bindings['priority'] = $request->string('priority');
        }

        if ($request->filled('category')) {
            $clauses[]            = 't.`category` = :category';
            $bindings['category'] = $request->string('category');
        }

        if ($request->bool('mine')) {
            $clauses[]             = 't.`assigned_to` = :assignee';
            $bindings['assignee']  = $this->adminId($request);
        }

        if ($params['search'] !== '') {
            $clauses[]          = "(t.`code` LIKE :search ESCAPE '\\\\' OR t.`subject` LIKE :search ESCAPE '\\\\'
                                    OR u.`name` LIKE :search ESCAPE '\\\\' OR u.`email` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `support_tickets` t INNER JOIN `users` u ON u.`id` = t.`user_id`' . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT t.*, u.`name` AS `user_name`, u.`email`, u.`avatar_url`, u.`account_type`,
                    a.`name` AS `assignee_name`,
                    (SELECT COUNT(*) FROM `support_ticket_messages` m WHERE m.`ticket_id` = t.`id`) AS `message_count`
             FROM `support_tickets` t
             INNER JOIN `users` u ON u.`id` = t.`user_id`
             LEFT JOIN `admins` a ON a.`id` = t.`assigned_to`' . $where . '
             ORDER BY t.`' . $params['sort_by'] . '` ' . $params['sort_dir'] . '
             LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $this->view($request, 'admin.support.index', [
            'pageTitle'    => 'Support tickets',
            'pageSubtitle' => number_format($total) . ' tickets',
            'tickets'      => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
            'counts'       => $this->ticketCounts(),
            'agents'       => $this->agents(),
        ]);
    }

    /** GET /admin/tickets/{id} */
    public function ticket(Request $request): never
    {
        $this->authorize($request, 'support.view');

        $ticketId = $request->routeParamInt('id');

        $ticket = Database::selectOne(
            'SELECT t.*, u.`name` AS `user_name`, u.`email`, u.`phone`, u.`phone_country_code`,
                    u.`avatar_url`, u.`account_type`, u.`status` AS `user_status`, u.`created_at` AS `user_joined`,
                    a.`name` AS `assignee_name`, b.`code` AS `booking_code`
             FROM `support_tickets` t
             INNER JOIN `users` u ON u.`id` = t.`user_id`
             LEFT JOIN `admins` a ON a.`id` = t.`assigned_to`
             LEFT JOIN `bookings` b ON b.`id` = t.`booking_id`
             WHERE t.`id` = :id',
            ['id' => $ticketId]
        );

        if ($ticket === null) {
            throw HttpException::notFound('Ticket not found.');
        }

        $messages = Database::select(
            'SELECT * FROM `support_ticket_messages` WHERE `ticket_id` = :id ORDER BY `id`',
            ['id' => $ticketId]
        );

        foreach ($messages as &$message) {
            $message['attachments'] = json_decode((string) ($message['attachments'] ?? '[]'), true) ?: [];
        }
        unset($message);

        $this->view($request, 'admin.support.show', [
            'pageTitle'    => 'Ticket #' . (string) $ticket['code'],
            'pageSubtitle' => (string) $ticket['subject'],
            'ticket'       => $ticket,
            'messages'     => $messages,
            'agents'       => $this->agents(),
            'otherTickets' => Database::select(
                'SELECT `id`, `code`, `subject`, `status`, `created_at`
                 FROM `support_tickets` WHERE `user_id` = :user_id AND `id` <> :id
                 ORDER BY `id` DESC LIMIT 5',
                ['user_id' => (int) $ticket['user_id'], 'id' => $ticketId]
            ),
        ]);
    }

    /** POST /admin/tickets/{id}/reply */
    public function replyToTicket(Request $request): never
    {
        $this->authorize($request, 'support.reply');

        $data = $this->validate($request, [
            'message'     => 'required|string|min:1|max:5000',
            'is_internal' => 'nullable|boolean',
            'status'      => 'nullable|in:open,pending_user,pending_agent,resolved,closed',
        ]);

        $ticketId = $request->routeParamInt('id');
        $admin    = $this->admin($request);

        $ticket = Database::selectOne('SELECT * FROM `support_tickets` WHERE `id` = :id', ['id' => $ticketId]);

        if ($ticket === null) {
            throw HttpException::notFound('Ticket not found.');
        }

        $isInternal = $request->bool('is_internal');

        $attachments = [];

        if ($request->hasFile('attachment')) {
            $upload        = UploadService::store((array) $request->file('attachment'), 'general', 'document', 'admin', (int) $admin['id']);
            $attachments[] = ['url' => $upload['url'], 'mime' => $upload['mime'], 'size' => $upload['size']];
        }

        $newStatus = (string) ($data['status'] ?? ($isInternal ? (string) $ticket['status'] : 'pending_user'));

        Database::transaction(static function () use ($ticketId, $admin, $data, $isInternal, $attachments, $newStatus, $ticket): void {
            Database::insert('support_ticket_messages', [
                'ticket_id'   => $ticketId,
                'sender_type' => 'agent',
                'sender_id'   => (int) $admin['id'],
                'sender_name' => (string) $admin['name'],
                'message'     => (string) $data['message'],
                'attachments' => $attachments === [] ? null : json_encode($attachments, JSON_UNESCAPED_UNICODE),
                'is_internal' => $isInternal ? 1 : 0,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            $updates = [
                'status'        => $newStatus,
                'last_reply_at' => date('Y-m-d H:i:s'),
                'last_reply_by' => 'agent',
            ];

            if ($ticket['first_response_at'] === null && !$isInternal) {
                $updates['first_response_at'] = date('Y-m-d H:i:s');
            }

            if ($ticket['assigned_to'] === null) {
                $updates['assigned_to'] = (int) $admin['id'];
            }

            if (in_array($newStatus, ['resolved', 'closed'], true)) {
                $updates[$newStatus === 'closed' ? 'closed_at' : 'resolved_at'] = date('Y-m-d H:i:s');
            }

            Database::update('support_tickets', $updates, ['id' => $ticketId]);
        });

        // Internal notes stay invisible to the customer.
        if (!$isInternal) {
            NotificationService::send((int) $ticket['user_id'], 'ticket.replied', [
                'ticket_code'    => (string) $ticket['code'],
                'ticket_subject' => (string) $ticket['subject'],
            ], ['action_type' => 'ticket', 'action_value' => (string) $ticketId]);
        }

        $this->log($request, 'ticket.replied', 'ticket', $ticketId, $isInternal ? 'Internal note added.' : 'Replied to the customer.');

        $this->respond($request, $isInternal ? 'Internal note saved.' : 'Reply sent.', '/admin/tickets/' . $ticketId);
    }

    /** POST /admin/tickets/{id}/assign */
    public function assignTicket(Request $request): never
    {
        $this->authorize($request, 'support.assign');

        $data = $this->validate($request, [
            'assigned_to' => 'nullable|integer',
            'priority'    => 'nullable|in:low,normal,high,urgent',
            'status'      => 'nullable|in:open,pending_user,pending_agent,resolved,closed',
        ]);

        $ticketId = $request->routeParamInt('id');

        if (!Database::exists('support_tickets', ['id' => $ticketId])) {
            throw HttpException::notFound('Ticket not found.');
        }

        $updates = [];

        if (array_key_exists('assigned_to', $data)) {
            $assignee = (int) ($data['assigned_to'] ?? 0);

            if ($assignee > 0 && !Database::exists('admins', ['id' => $assignee])) {
                $this->back('error', 'That agent does not exist.');
            }

            $updates['assigned_to'] = $assignee > 0 ? $assignee : null;
        }

        if (!empty($data['priority'])) {
            $updates['priority'] = (string) $data['priority'];
        }

        if (!empty($data['status'])) {
            $updates['status'] = (string) $data['status'];

            if ($data['status'] === 'resolved') {
                $updates['resolved_at'] = date('Y-m-d H:i:s');
            }

            if ($data['status'] === 'closed') {
                $updates['closed_at'] = date('Y-m-d H:i:s');
            }
        }

        if ($updates === []) {
            $this->back('error', 'Nothing to update.');
        }

        Database::update('support_tickets', $updates, ['id' => $ticketId]);

        $this->log($request, 'ticket.updated', 'ticket', $ticketId, 'Ticket routing updated.', $updates);

        $this->respond($request, 'Ticket updated.', '/admin/tickets/' . $ticketId);
    }

    // =================================================================
    // Notifications
    // =================================================================

    /** GET /admin/notifications */
    public function notifications(Request $request): never
    {
        $this->authorize($request, 'notifications.view');

        $params = $this->listParams($request, ['id', 'created_at'], 'id');

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `notification_broadcasts`');

        $broadcasts = Database::select(
            'SELECT b.*, a.`name` AS `author_name`
             FROM `notification_broadcasts` b
             LEFT JOIN `admins` a ON a.`id` = b.`created_by`
             ORDER BY b.`id` DESC LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset']
        );

        $queueStats = Database::selectOne(
            "SELECT
                SUM(CASE WHEN `status` = 'pending' THEN 1 ELSE 0 END) AS `pending`,
                SUM(CASE WHEN `status` = 'sent'    THEN 1 ELSE 0 END) AS `sent`,
                SUM(CASE WHEN `status` = 'failed'  THEN 1 ELSE 0 END) AS `failed`
             FROM `push_queue`"
        ) ?? [];

        $this->view($request, 'admin.notifications.index', [
            'pageTitle'    => 'Notifications',
            'pageSubtitle' => 'Broadcasts and delivery queues',
            'broadcasts'   => $broadcasts,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'queueStats'   => $queueStats,
            'templates'    => Database::select('SELECT * FROM `notification_templates` ORDER BY `category`, `code`'),
            'audienceSizes' => $this->audienceSizes(),
        ]);
    }

    /** POST /admin/notifications/broadcast */
    public function broadcast(Request $request): never
    {
        $this->authorize($request, 'notifications.send');

        $data = $this->validate($request, [
            'title'        => 'required|string|min:3|max:191',
            'body'         => 'required|string|min:5|max:1000',
            'audience'     => 'required|in:all,brands,creators,verified_creators',
            // Driven off the `notifications`.`action_type` ENUM so the composer
            // can no longer offer a deep link the column will refuse.
            'action_type'  => 'nullable|in:' . implode(',', NotificationService::actionTypes()),
            'action_value' => 'nullable|string|max:255',
            'image_url'    => 'nullable|url|max:500',
        ]);

        $channels = $request->array('channels');
        $channels = array_values(array_intersect(
            array_map('strval', $channels),
            ['in_app', 'push', 'email']
        ));

        if ($channels === []) {
            $channels = ['in_app', 'push'];
        }

        $recipients = $this->audienceUserIds((string) $data['audience']);

        if ($recipients === []) {
            $this->back('error', 'That audience currently has no members.');
        }

        $adminId = $this->adminId($request);

        $broadcastId = Database::insert('notification_broadcasts', [
            'title'            => (string) $data['title'],
            'body'             => (string) $data['body'],
            'image_url'        => $data['image_url'] ?? null,
            'audience'         => (string) $data['audience'],
            'channels'         => json_encode($channels, JSON_UNESCAPED_UNICODE),
            'action_type'      => (string) ($data['action_type'] ?? 'none'),
            'action_value'     => $data['action_value'] ?? null,
            'status'           => 'sending',
            'recipients_count' => count($recipients),
            'created_by'       => $adminId,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        $delivered = 0;

        // A blast that throws half way through used to leave the row stuck on
        // 'sending' for ever — there is no retry or reconciliation job — and
        // skipped the audit entry entirely. Record the failure instead.
        try {
            foreach ($recipients as $userId) {
                NotificationService::sendRaw(
                    $userId,
                    (string) $data['title'],
                    (string) $data['body'],
                    'promo',
                    (string) ($data['action_type'] ?? 'none'),
                    $data['action_value'] ?? null,
                    $data['image_url'] ?? null,
                    $channels
                );

                $delivered++;
            }

            // Email goes through the queue so a large blast does not block here.
            if (in_array('email', $channels, true)) {
                // A broadcast is promotional traffic, so the email switch and
                // the marketing choice both have to be honoured here — this
                // arm never went through NotificationService. Marketing is
                // opt-in: no stored choice means no email.
                $emails = Database::select(
                    'SELECT `id`, `name`, `email` FROM `users`
                     WHERE `id` IN (' . implode(',', array_map('intval', $recipients)) . ')
                       AND `email` IS NOT NULL AND `email` <> "" AND `status` = "active"
                       AND JSON_VALID(`notification_prefs`) = 1
                       AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`notification_prefs`, "$.email")), "true") NOT IN ("false","0")
                       AND JSON_UNQUOTE(JSON_EXTRACT(`notification_prefs`, "$.marketing")) IN ("true","1")'
                );

                foreach ($emails as $recipient) {
                    MailService::queue(
                        (string) $recipient['email'],
                        (string) $recipient['name'],
                        (string) $data['title'],
                        MailService::renderDefault(
                            (string) $data['title'],
                            (string) $data['body'],
                            (string) $recipient['name'],
                            (string) ($data['action_type'] ?? 'none'),
                            $data['action_value'] ?? null
                        )
                    );
                }
            }
        } catch (\Throwable $e) {
            Database::update('notification_broadcasts', [
                'status'          => 'failed',
                'delivered_count' => $delivered,
            ], ['id' => $broadcastId]);

            $this->log(
                $request,
                'notification.broadcast_failed',
                'broadcast',
                $broadcastId,
                'Broadcast failed after ' . $delivered . ' of ' . count($recipients) . ' recipient(s).',
                ['error' => mb_substr($e->getMessage(), 0, 300)]
            );

            throw $e;
        }

        Database::update('notification_broadcasts', [
            'status'          => 'sent',
            'sent_at'         => date('Y-m-d H:i:s'),
            'delivered_count' => $delivered,
        ], ['id' => $broadcastId]);

        $this->log(
            $request,
            'notification.broadcast',
            'broadcast',
            $broadcastId,
            'Broadcast to ' . $delivered . ' recipient(s).',
            ['audience' => $data['audience'], 'channels' => $channels]
        );

        $this->respond($request, 'Broadcast queued for ' . number_format($delivered) . ' recipient(s).', '/admin/notifications');
    }

    /** POST /admin/notifications/templates/{id} */
    public function updateTemplate(Request $request): never
    {
        $this->authorize($request, 'notifications.templates');

        $data = $this->validate($request, [
            'title'         => 'required|string|max:191',
            'body'          => 'required|string|max:1000',
            'email_subject' => 'nullable|string|max:191',
            'sms_body'      => 'nullable|string|max:500',
            'is_active'     => 'nullable|boolean',
        ]);

        $templateId = $request->routeParamInt('id');

        if (!Database::exists('notification_templates', ['id' => $templateId])) {
            throw HttpException::notFound('Template not found.');
        }

        $channels = array_values(array_intersect(
            array_map('strval', $request->array('channels')),
            ['in_app', 'push', 'email', 'sms']
        ));

        Database::update('notification_templates', [
            'title'         => (string) $data['title'],
            'body'          => (string) $data['body'],
            'email_subject' => $data['email_subject'] ?? null,
            'sms_body'      => $data['sms_body'] ?? null,
            'channels'      => json_encode($channels === [] ? ['in_app'] : $channels, JSON_UNESCAPED_UNICODE),
            'is_active'     => $request->bool('is_active') ? 1 : 0,
        ], ['id' => $templateId]);

        NotificationService::flushTemplates();

        $this->log($request, 'notification.template_updated', 'template', $templateId, 'Notification template updated.');

        $this->respond($request, 'Template saved.', '/admin/notifications');
    }

    // =================================================================
    // Contact inbox
    // =================================================================

    /** GET /admin/contact */
    public function contact(Request $request): never
    {
        // Reading the inbox is a view permission; replying and changing status
        // are not — see replyToContact() and updateContactStatus() below.
        $this->authorize($request, 'cms.view');

        $params   = $this->listParams($request, ['id', 'created_at'], 'id');
        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($request->filled('status')) {
            $clauses[]          = '`status` = :status';
            $bindings['status'] = $request->string('status');
        }

        if ($params['search'] !== '') {
            $clauses[]          = "(`name` LIKE :search ESCAPE '\\\\' OR `email` LIKE :search ESCAPE '\\\\'
                                    OR `subject` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `contact_messages`' . $where, $bindings);

        $rows = Database::select(
            'SELECT * FROM `contact_messages`' . $where
            . ' ORDER BY `' . $params['sort_by'] . '` ' . $params['sort_dir']
            . ' LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $this->view($request, 'admin.contact.index', [
            'pageTitle'    => 'Contact inbox',
            'pageSubtitle' => number_format($total) . ' messages',
            'messages'     => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
        ]);
    }

    /** POST /admin/contact/{id}/reply */
    public function replyToContact(Request $request): never
    {
        $this->authorize($request, 'support.reply');

        $data = $this->validate($request, [
            'reply' => 'required|string|min:10|max:5000',
        ]);

        $messageId = $request->routeParamInt('id');

        $message = Database::selectOne('SELECT * FROM `contact_messages` WHERE `id` = :id', ['id' => $messageId]);

        if ($message === null) {
            throw HttpException::notFound('Message not found.');
        }

        MailService::queue(
            (string) $message['email'],
            (string) $message['name'],
            'Re: ' . (string) $message['subject'],
            MailService::renderDefault(
                'Re: ' . (string) $message['subject'],
                nl2br(htmlspecialchars((string) $data['reply'], ENT_QUOTES, 'UTF-8')),
                (string) $message['name']
            )
        );

        Database::update('contact_messages', [
            'status'     => 'replied',
            'reply_body' => (string) $data['reply'],
            'replied_by' => $this->adminId($request),
            'replied_at' => date('Y-m-d H:i:s'),
        ], ['id' => $messageId]);

        $this->log($request, 'contact.replied', 'contact', $messageId, 'Replied to a contact enquiry.');

        $this->respond($request, 'Reply queued for delivery.', '/admin/contact');
    }

    /** POST /admin/contact/{id}/status */
    public function updateContactStatus(Request $request): never
    {
        $this->authorize($request, 'support.reply');

        $data      = $this->validate($request, ['status' => 'required|in:new,read,replied,archived,spam']);
        $messageId = $request->routeParamInt('id');

        if (!Database::exists('contact_messages', ['id' => $messageId])) {
            throw HttpException::notFound('Message not found.');
        }

        Database::update(
            'contact_messages',
            ['status' => (string) $data['status']],
            ['id' => $messageId]
        );

        // Archiving or flagging a message as spam is a real mutation; it was
        // the only write in this controller that left no audit trail.
        $this->log(
            $request,
            'contact.status_changed',
            'contact',
            $messageId,
            'Marked as ' . (string) $data['status'] . '.',
            ['status' => (string) $data['status']]
        );

        $this->respond($request, 'Message marked as ' . (string) $data['status'] . '.', '/admin/contact');
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @return array<string,int> */
    private function ticketCounts(): array
    {
        $rows = Database::select('SELECT `status`, COUNT(*) AS `total` FROM `support_tickets` GROUP BY `status`');

        $counts = ['all' => 0, 'open' => 0];

        foreach ($rows as $row) {
            $status          = (string) $row['status'];
            $counts[$status] = (int) $row['total'];
            $counts['all']  += (int) $row['total'];

            if (in_array($status, ['open', 'pending_agent'], true)) {
                $counts['open'] += (int) $row['total'];
            }
        }

        return $counts;
    }

    /** @return list<array<string,mixed>> */
    private function agents(): array
    {
        return Database::select(
            "SELECT a.`id`, a.`name`, r.`name` AS `role_name`
             FROM `admins` a
             INNER JOIN `roles` r ON r.`id` = a.`role_id`
             WHERE a.`status` = 'active' AND a.`deleted_at` IS NULL
             ORDER BY a.`name`"
        );
    }

    /** @return array<string,int> */
    private function audienceSizes(): array
    {
        return [
            // Guests have neither an email nor a phone number, so including
            // them in an audience count promises a reach that does not exist.
            'all'               => (int) Database::scalar(
                "SELECT COUNT(*) FROM `users` WHERE `status` = 'active' AND `deleted_at` IS NULL AND `account_type` <> 'guest'"
            ),
            'brands'            => (int) Database::scalar("SELECT COUNT(*) FROM `users` WHERE `status` = 'active' AND `account_type` = 'brand' AND `deleted_at` IS NULL"),
            'creators'          => (int) Database::scalar("SELECT COUNT(*) FROM `users` WHERE `status` = 'active' AND `account_type` = 'creator' AND `deleted_at` IS NULL"),
            'verified_creators' => (int) Database::scalar(
                "SELECT COUNT(*) FROM `users` u INNER JOIN `creators` c ON c.`user_id` = u.`id`
                 WHERE u.`status` = 'active' AND c.`is_verified` = 1 AND c.`deleted_at` IS NULL"
            ),
        ];
    }

    /** @return list<int> */
    private function audienceUserIds(string $audience): array
    {
        $sql = match ($audience) {
            'brands'   => "SELECT `id` FROM `users` WHERE `status` = 'active' AND `account_type` = 'brand' AND `deleted_at` IS NULL",
            'creators' => "SELECT `id` FROM `users` WHERE `status` = 'active' AND `account_type` = 'creator' AND `deleted_at` IS NULL",
            'verified_creators' => "SELECT u.`id` FROM `users` u INNER JOIN `creators` c ON c.`user_id` = u.`id`
                                    WHERE u.`status` = 'active' AND c.`is_verified` = 1 AND c.`deleted_at` IS NULL",
            default    => "SELECT `id` FROM `users` WHERE `status` = 'active' AND `deleted_at` IS NULL",
        };

        return array_map('intval', Database::column($sql));
    }
}

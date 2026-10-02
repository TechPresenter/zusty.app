<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\ValidationException;
use Zuste\Core\Validator;
use Zuste\Helpers\Str;
use Zuste\Services\ActivityLogger;
use Zuste\Services\AuthService;
use Zuste\Services\IdentityLockService;
use Zuste\Services\MessageService;
use Zuste\Services\NotificationService;
use Zuste\Services\ReferralService;
use Zuste\Services\TaxonomyService;
use Zuste\Services\UploadService;

/**
 * The signed-in user's own account: profile, KYC, devices, notifications,
 * referrals and support tickets.
 *
 * Routes: /api/v1/me/*, /api/v1/notifications/*, /api/v1/support/*
 */
final class AccountController extends BaseApiController
{
    // -----------------------------------------------------------------
    // Profile
    // -----------------------------------------------------------------

    /** GET /me */
    public function profile(Request $request): never
    {
        $userId = $this->userId($request);
        $user   = self::presentUser($userId);

        Response::success([
            'user'  => $user,
            'badges' => [
                'unread_notifications' => NotificationService::unreadCount($userId),
                'unread_messages'      => MessageService::totalUnread($userId),
            ],
        ]);
    }

    /** PUT /me */
    public function updateProfile(Request $request): never
    {
        $userId = $this->userId($request);

        $data = $this->validate($request, [
            'name'         => 'nullable|string|min:2|max:120',
            'email'        => 'nullable|email|max:191|unique:users,email,' . $userId,
            'phone'        => 'nullable|phone',
            'bio'          => 'nullable|string|max:1000',
            'company_name' => 'nullable|string|max:150',
            'website'      => 'nullable|url|max:255',
            'gst_number'   => 'nullable|string|max:20',
            'city'         => 'nullable|string|max:100',
            'state'        => 'nullable|string|max:100',
            'country'      => 'nullable|string|max:100',
            'postal_code'  => 'nullable|string|max:12',
            'locale'       => 'nullable|string|max:10',
        ]);

        $existing = AuthService::loadUser($userId);
        $updates  = [];

        // The name a blue tick is drawn next to cannot move while the tick is
        // showing — otherwise a verified account could rename itself to a
        // better-known brand and keep the badge. Only a real change is refused:
        // the apps re-send the whole form, current name included.
        IdentityLockService::assertNameUnchanged(
            $userId,
            array_intersect_key($data, array_flip(['name', 'company_name'])),
            $existing
        );

        foreach (['name', 'bio', 'company_name', 'website', 'gst_number', 'city', 'state', 'country', 'postal_code', 'locale'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        // Changing a verified identifier resets its verification.
        if (!empty($data['email']) && strtolower((string) $data['email']) !== strtolower((string) ($existing['email'] ?? ''))) {
            $updates['email']             = strtolower((string) $data['email']);
            $updates['email_verified_at'] = null;
        }

        if (!empty($data['phone'])) {
            [$countryCode, $phone] = Str::splitPhone((string) $data['phone']);

            if ($phone !== (string) ($existing['phone'] ?? '')) {
                $taken = Database::scalar(
                    'SELECT 1 FROM `users` WHERE `phone_country_code` = :cc AND `phone` = :phone AND `id` <> :id AND `deleted_at` IS NULL',
                    ['cc' => $countryCode, 'phone' => $phone, 'id' => $userId]
                );

                if ($taken !== null) {
                    throw HttpException::unprocessable('That phone number is already in use.', 'phone_taken');
                }

                $updates['phone_country_code'] = $countryCode;
                $updates['phone']              = $phone;
                $updates['phone_verified_at']  = null;
            }
        }

        if ($request->hasFile('avatar')) {
            $upload               = UploadService::store((array) $request->file('avatar'), 'avatars', 'image', 'user', $userId);
            $updates['avatar_url'] = $upload['url'];
        } elseif ($request->filled('avatar_url')) {
            // Validated here rather than in the rule set above because the
            // field is only present on the non-multipart branch. Without this
            // a `javascript:` URL is stored verbatim and becomes stored XSS on
            // any client that renders the avatar as a link.
            $avatarUrl = $request->string('avatar_url');
            $scheme    = strtolower((string) parse_url($avatarUrl, PHP_URL_SCHEME));

            if (!in_array($scheme, ['http', 'https'], true) || mb_strlen($avatarUrl) > 500) {
                throw HttpException::unprocessable(
                    'The avatar URL must be a full http:// or https:// address.'
                );
            }

            $updates['avatar_url'] = $avatarUrl;
        }

        if ($updates !== []) {
            Database::update('users', $updates, ['id' => $userId]);
            ActivityLogger::user($existing, 'profile.updated', 'user', $userId, 'Profile updated.', array_keys($updates));
        }

        Response::success(
            ['user' => self::presentUser($userId)],
            'Profile updated.'
        );
    }

    /**
     * The account as the app sees it, plus whether its public name is frozen
     * by the blue tick — so the profile screen can show the name read-only
     * instead of offering an edit the API will refuse.
     *
     * @return array<string,mixed>
     */
    private static function presentUser(int $userId): array
    {
        $user = AuthService::publicUser(AuthService::loadUser($userId));
        $user['identity_locked'] = IdentityLockService::isLocked($userId);

        return $user;
    }

    /** PUT /me/notification-preferences */
    public function updateNotificationPreferences(Request $request): never
    {
        $data = $this->validate($request, [
            'push'      => 'nullable|boolean',
            'email'     => 'nullable|boolean',
            'sms'       => 'nullable|boolean',
            'marketing' => 'nullable|boolean',
        ]);

        $userId   = $this->userId($request);
        $existing = json_decode((string) (Database::scalar(
            'SELECT `notification_prefs` FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        ) ?? '{}'), true) ?: [];

        $preferences = array_merge(
            ['push' => true, 'email' => true, 'sms' => false, 'marketing' => true],
            $existing,
            array_map('boolval', array_filter($data, static fn ($v): bool => $v !== null))
        );

        Database::update('users', [
            'notification_prefs' => json_encode($preferences, JSON_UNESCAPED_UNICODE),
        ], ['id' => $userId]);

        Response::success(['preferences' => $preferences], 'Notification preferences saved.');
    }

    /** POST /me/devices — register or refresh an FCM token. */
    public function registerDevice(Request $request): never
    {
        $context = $this->deviceContext($request);

        // AuthService::registerDevice returns early on an empty device id, so
        // without this the endpoint answered "Device registered." having done
        // nothing at all — the client has no way to tell a real registration
        // from a silent no-op, and push delivery just never works.
        if (trim((string) $context['device_id']) === '') {
            throw HttpException::unprocessable(
                'A device id is required. Send it as the X-Device-Id header or a device_id field.'
            );
        }

        AuthService::registerDevice($this->userId($request), $context);

        Response::noContent('Device registered.');
    }

    /** POST /me/upload — generic authenticated upload used by several screens. */
    public function upload(Request $request): never
    {
        if (!$request->hasFile('file')) {
            throw HttpException::unprocessable('No file was uploaded.');
        }

        $folder = $request->string('folder', 'general');
        $kind   = $request->string('kind', 'image');

        // Identity documents never go through the generic uploader: it writes
        // to the public uploads tree, where anyone holding the URL can read
        // the file. They go to POST /me/kyc/documents, which encrypts them
        // into storage outside the web root.
        if ($folder === 'kyc') {
            throw HttpException::unprocessable(
                'Upload identity documents from the verification screen.',
                'kyc_upload_moved'
            );
        }

        $allowedFolders = ['avatars', 'portfolio', 'chat', 'deliverables', 'general'];

        if (!in_array($folder, $allowedFolders, true)) {
            $folder = 'general';
        }

        $upload = UploadService::store(
            (array) $request->file('file'),
            $folder,
            in_array($kind, ['image', 'video', 'document'], true) ? $kind : 'image',
            'user',
            $this->userId($request)
        );

        Response::created($upload, 'File uploaded.');
    }

    // -----------------------------------------------------------------
    // KYC
    // -----------------------------------------------------------------

    /** GET /me/kyc */
    public function kycStatus(Request $request): never
    {
        $userId = $this->userId($request);

        $documents = Database::select(
            'SELECT `id`, `document_type`, `holder_name`, `status`, `rejection_reason`, `reviewed_at`, `created_at`
             FROM `kyc_documents` WHERE `user_id` = :id ORDER BY `id` DESC',
            ['id' => $userId]
        );

        $status = (string) (Database::scalar('SELECT `kyc_status` FROM `users` WHERE `id` = :id', ['id' => $userId]) ?? 'none');

        Response::success([
            'kyc_status' => $status,
            'documents'  => $documents,
            'is_verified' => $status === 'verified',
            'accepted_types' => [
                ['value' => 'aadhaar', 'label' => 'Aadhaar card', 'requires_back' => true],
                ['value' => 'pan', 'label' => 'PAN card', 'requires_back' => false],
                ['value' => 'passport', 'label' => 'Passport', 'requires_back' => true],
                ['value' => 'driving_license', 'label' => 'Driving licence', 'requires_back' => true],
                ['value' => 'gst', 'label' => 'GST certificate', 'requires_back' => false],
            ],
        ]);
    }

    /**
     * POST /me/kyc/legacy — retired.
     *
     * The original single-document flow stored the uploaded ID images in the
     * PUBLIC uploads tree (readable by anyone with the URL), kept the full
     * document number, and was approved from a screen that bypassed the
     * verification state machine. Everything it did is now done by the
     * verification centre (/me/kyc), so it answers 410 Gone and points there.
     */
    public function submitKyc(Request $request): never
    {
        throw new HttpException('Use the verification centre.', 410, 'gone');
    }

    // -----------------------------------------------------------------
    // Notifications
    // -----------------------------------------------------------------

    /** GET /notifications */
    public function notifications(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);
        $userId     = $this->userId($request);

        $clauses  = ['`user_id` = :user_id'];
        $bindings = ['user_id' => $userId];

        if ($request->filled('category')) {
            $clauses[]            = '`category` = :category';
            $bindings['category'] = $request->string('category');
        }

        if ($request->bool('unread_only')) {
            $clauses[] = '`is_read` = 0';
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `notifications`' . $where, $bindings);

        $rows = Database::select(
            'SELECT * FROM `notifications`' . $where
            . ' ORDER BY `id` DESC LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            $bindings
        );

        foreach ($rows as &$row) {
            $row['id']         = (int) $row['id'];
            $row['is_read']    = (bool) $row['is_read'];
            $row['data']       = json_decode((string) ($row['data'] ?? '{}'), true) ?: [];
            $row['time_ago']   = Str::timeAgo((string) $row['created_at']);
        }
        unset($row);

        Response::paginated($rows, $total, $pagination['page'], $pagination['per_page'], 'OK', [
            'unread_count' => NotificationService::unreadCount($userId),
        ]);
    }

    /** POST /notifications/{id}/read */
    public function markNotificationRead(Request $request): never
    {
        NotificationService::markRead($this->userId($request), $request->routeParamInt('id'));

        Response::success(['unread_count' => NotificationService::unreadCount($this->userId($request))]);
    }

    /** POST /notifications/read-all */
    public function markAllNotificationsRead(Request $request): never
    {
        $updated = NotificationService::markAllRead($this->userId($request));

        Response::success(['marked' => $updated, 'unread_count' => 0], 'All notifications marked as read.');
    }

    /** DELETE /notifications/{id} */
    public function deleteNotification(Request $request): never
    {
        Database::execute(
            'DELETE FROM `notifications` WHERE `id` = :id AND `user_id` = :user_id',
            ['id' => $request->routeParamInt('id'), 'user_id' => $this->userId($request)]
        );

        Response::noContent('Notification removed.');
    }

    // -----------------------------------------------------------------
    // Referrals
    // -----------------------------------------------------------------

    /** GET /me/referrals — the Invite & Earn screen. */
    public function referrals(Request $request): never
    {
        $userId = $this->userId($request);

        Response::success([
            'summary'  => ReferralService::summary($userId),
            'invitees' => ReferralService::invitees($userId),
        ]);
    }

    // -----------------------------------------------------------------
    // Support
    // -----------------------------------------------------------------

    /** GET /support/tickets */
    public function tickets(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);
        $userId     = $this->userId($request);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `support_tickets` WHERE `user_id` = :id',
            ['id' => $userId]
        );

        $rows = Database::select(
            'SELECT `id`, `code`, `subject`, `category`, `priority`, `status`, `last_reply_at`, `created_at`
             FROM `support_tickets` WHERE `user_id` = :id
             ORDER BY `id` DESC LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            ['id' => $userId]
        );

        Response::paginated($rows, $total, $pagination['page'], $pagination['per_page']);
    }

    /** POST /support/tickets */
    public function createTicket(Request $request): never
    {
        [, $errors, $data] = Validator::check($request->all(), [
            'subject'    => 'required|string|min:3|max:191',
            'message'    => 'required|string|min:10|max:5000',
            'category'   => 'nullable|string|max:60',
            'booking_id' => 'nullable|integer',
        ]);

        // An unknown category is a field error like the others, reported in
        // the same response so the form can mark every bad field at once.
        $category = strtolower(trim($request->string('category')));

        if (!isset($errors['category']) && $category !== '' && !self::isKnownTicketCategory($category)) {
            $errors['category'][] = 'Category must be one of: '
                . implode(', ', array_keys(TaxonomyService::map('ticket_category')));
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // The picker is driven by the `ticket_category` taxonomy, so anything
        // it offers has to be accepted here — a hardcoded list rejected the
        // screen's own default ("general") and three other live terms. The
        // column is a narrower enum, so the term is mapped onto a storable
        // bucket rather than widening the schema.
        $data['category'] = self::ticketCategory($request->string('category'));

        $userId = $this->userId($request);

        // Check the booking reference up front. Letting the foreign key catch
        // it produces a 500 whose message carries the constraint, table and
        // schema names — a schema disclosure on an endpoint any user can hit.
        $bookingId = isset($data['booking_id']) && (int) $data['booking_id'] > 0
            ? (int) $data['booking_id']
            : null;

        if ($bookingId !== null) {
            $owns = Database::exists('bookings', ['id' => $bookingId, 'brand_user_id' => $userId])
                || Database::exists('bookings', ['id' => $bookingId, 'creator_user_id' => $userId]);

            // 404 rather than 403 for somebody else's booking, so the endpoint
            // cannot be used to probe which booking ids exist.
            if (!$owns) {
                throw HttpException::notFound('That booking could not be found on your account.');
            }
        }

        $data['booking_id'] = $bookingId;

        $ticketId = Database::transaction(static function () use ($userId, $data): int {
            $id = Database::insert('support_tickets', [
                'code'          => 'PENDING',
                'user_id'       => $userId,
                'booking_id'    => $data['booking_id'],
                'subject'       => (string) $data['subject'],
                'category'      => (string) ($data['category'] ?? 'other'),
                'status'        => 'open',
                'last_reply_at' => date('Y-m-d H:i:s'),
                'last_reply_by' => 'user',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            Database::update('support_tickets', ['code' => Str::referenceCode('ZT', $id, 6)], ['id' => $id]);

            Database::insert('support_ticket_messages', [
                'ticket_id'   => $id,
                'sender_type' => 'user',
                'sender_id'   => $userId,
                'message'     => (string) $data['message'],
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            return $id;
        });

        Response::created(
            ['ticket' => Database::selectOne('SELECT * FROM `support_tickets` WHERE `id` = :id', ['id' => $ticketId])],
            'Support ticket created. We usually reply within one business day.'
        );
    }

    /**
     * What `support_tickets`.`category` can physically hold.
     *
     * The taxonomy is the contract the app renders; this enum is the column.
     * The two drifted, so every taxonomy term is accepted and folded onto the
     * closest bucket instead of being refused.
     */
    private const TICKET_CATEGORIES = [
        'account', 'booking', 'payment', 'wallet', 'kyc', 'technical', 'abuse', 'other',
    ];

    /** Taxonomy terms whose bucket is not obvious from the value itself. */
    private const TICKET_CATEGORY_ALIASES = [
        'general'         => 'other',
        'booking_dispute' => 'booking',
        'payouts'         => 'wallet',
        'payout'          => 'wallet',
        'verification'    => 'kyc',
        'refund'          => 'payment',
    ];

    /** Resolves a submitted ticket category onto a storable one. */
    /** A storable bucket, or a term from the `ticket_category` taxonomy. */
    private static function isKnownTicketCategory(string $value): bool
    {
        return in_array($value, self::TICKET_CATEGORIES, true)
            || TaxonomyService::isValid('ticket_category', $value);
    }

    private static function ticketCategory(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return TaxonomyService::defaultValue('ticket_category') !== null
                ? self::ticketCategory((string) TaxonomyService::defaultValue('ticket_category'))
                : 'other';
        }

        if (in_array($value, self::TICKET_CATEGORIES, true)) {
            return $value;
        }

        if (!self::isKnownTicketCategory($value)) {
            throw HttpException::unprocessable(
                'Category must be one of: ' . implode(', ', array_keys(TaxonomyService::map('ticket_category')))
            );
        }

        if (isset(self::TICKET_CATEGORY_ALIASES[$value])) {
            return self::TICKET_CATEGORY_ALIASES[$value];
        }

        // An operator-added term: file it under whichever bucket it names.
        foreach (self::TICKET_CATEGORIES as $bucket) {
            if (str_contains($value, $bucket)) {
                return $bucket;
            }
        }

        return 'other';
    }

    /** GET /support/tickets/{id} */
    public function ticket(Request $request): never
    {
        $ticketId = $request->routeParamInt('id');
        $userId   = $this->userId($request);

        $ticket = Database::selectOne(
            'SELECT * FROM `support_tickets` WHERE `id` = :id AND `user_id` = :user_id',
            ['id' => $ticketId, 'user_id' => $userId]
        );

        if ($ticket === null) {
            throw HttpException::notFound('Ticket not found.');
        }

        $ticket['messages'] = Database::select(
            'SELECT `id`, `sender_type`, `sender_name`, `message`, `attachments`, `created_at`
             FROM `support_ticket_messages`
             WHERE `ticket_id` = :id AND `is_internal` = 0
             ORDER BY `id`',
            ['id' => $ticketId]
        );

        foreach ($ticket['messages'] as &$message) {
            $message['attachments'] = json_decode((string) ($message['attachments'] ?? '[]'), true) ?: [];
        }
        unset($message);

        Response::success(['ticket' => $ticket]);
    }

    /** POST /support/tickets/{id}/reply */
    public function replyToTicket(Request $request): never
    {
        $data = $this->validate($request, ['message' => 'required|string|min:1|max:5000']);

        $ticketId = $request->routeParamInt('id');
        $userId   = $this->userId($request);

        $ticket = Database::selectOne(
            'SELECT * FROM `support_tickets` WHERE `id` = :id AND `user_id` = :user_id',
            ['id' => $ticketId, 'user_id' => $userId]
        );

        if ($ticket === null) {
            throw HttpException::notFound('Ticket not found.');
        }

        if (in_array($ticket['status'], ['closed'], true)) {
            throw HttpException::conflict('This ticket is closed. Open a new one if you still need help.');
        }

        Database::transaction(static function () use ($ticketId, $userId, $data): void {
            Database::insert('support_ticket_messages', [
                'ticket_id'   => $ticketId,
                'sender_type' => 'user',
                'sender_id'   => $userId,
                'message'     => (string) $data['message'],
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            Database::update('support_tickets', [
                'status'        => 'pending_agent',
                'last_reply_at' => date('Y-m-d H:i:s'),
                'last_reply_by' => 'user',
            ], ['id' => $ticketId]);
        });

        Response::created(null, 'Reply sent.');
    }
}

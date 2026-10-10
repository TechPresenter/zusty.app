<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * The booking state machine — the heart of the marketplace.
 *
 *   pending ──accept──► accepted ──pay──► confirmed ──start──► in_progress
 *      │                    │                                      │
 *      ├──counter──► countered                                  deliver
 *      ├──reject───► rejected                                       │
 *      ├──cancel───► cancelled                                  delivered
 *      └──expire───► expired                                        │
 *                                                              approve
 *                                                                   ▼
 *                                                              completed
 *
 * Every transition is validated against ALLOWED_TRANSITIONS, recorded in
 * `booking_status_history`, and notifies the other party. Money only moves
 * through WalletService, never here.
 */
final class BookingService
{
    /** @var array<string,list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'pending'          => ['accepted', 'rejected', 'countered', 'cancelled', 'expired'],
        'countered'        => ['accepted', 'rejected', 'countered', 'cancelled', 'expired'],
        'accepted'         => ['awaiting_payment', 'confirmed', 'cancelled', 'expired'],
        'awaiting_payment' => ['confirmed', 'cancelled', 'expired'],
        'confirmed'        => ['in_progress', 'cancelled', 'disputed'],
        'in_progress'      => ['delivered', 'cancelled', 'disputed'],
        'delivered'        => ['completed', 'in_progress', 'disputed'],
        'completed'        => ['disputed'],
        'disputed'         => ['completed', 'refunded', 'cancelled'],
        'rejected'         => [],
        'cancelled'        => [],
        'expired'          => [],
        'refunded'         => [],
    ];

    // -----------------------------------------------------------------
    // Creation
    // -----------------------------------------------------------------

    /**
     * Create a booking request from a brand to a creator.
     *
     * @param array{
     *   creator_id:int, title:string, description?:string, campaign_type?:string,
     *   platform?:string, deliverable_type?:string, posts_count?:int,
     *   amount:float, package_id?:int|null, campaign_id?:int|null,
     *   delivery_due_at?:string|null
     * } $input
     *
     * @return array<string,mixed> The created booking.
     */
    public static function create(int $brandUserId, array $input): array
    {
        $creator = Database::selectOne(
            'SELECT c.*, u.`id` AS `creator_user_id`, u.`name` AS `creator_name`, u.`status` AS `creator_user_status`
             FROM `creators` c
             INNER JOIN `users` u ON u.`id` = c.`user_id`
             WHERE c.`id` = :id AND c.`deleted_at` IS NULL',
            ['id' => (int) $input['creator_id']]
        );

        if ($creator === null) {
            throw HttpException::notFound('Creator not found.');
        }

        if ($creator['status'] !== 'approved' || $creator['creator_user_status'] !== 'active') {
            throw HttpException::unprocessable('This creator is not accepting bookings right now.');
        }

        if ((int) $creator['creator_user_id'] === $brandUserId) {
            throw HttpException::unprocessable('You cannot book your own creator profile.');
        }

        BlockService::assertNotBlocked($brandUserId, (int) $creator['creator_user_id']);

        if ($creator['availability_status'] === 'unavailable') {
            throw HttpException::unprocessable('This creator is currently unavailable.');
        }

        $amount  = Money::round((float) $input['amount']);
        $minimum = Config::settingFloat('booking', 'min_booking_amount', 1000);
        $maximum = Config::settingFloat('booking', 'max_booking_amount', 2000000);

        // A package, when chosen, is the source of truth for the price so a
        // client cannot post a cheaper amount than the creator advertised.
        $packageId = isset($input['package_id']) ? (int) $input['package_id'] : null;
        $package   = null;

        if ($packageId !== null && $packageId > 0) {
            $package = Database::selectOne(
                'SELECT * FROM `creator_packages`
                 WHERE `id` = :id AND `creator_id` = :creator_id AND `is_active` = 1 AND `deleted_at` IS NULL',
                ['id' => $packageId, 'creator_id' => (int) $creator['id']]
            );

            if ($package === null) {
                throw HttpException::unprocessable('The selected package is no longer available.');
            }

            $amount = Money::round((float) $package['price']);
        }

        // The bounds apply to the amount actually booked, so they run after
        // the package swap. Run before it, they checked whatever the brand
        // typed, and a package priced outside them (one saved before the
        // editor enforced them, or before an operator raised the minimum)
        // could still be booked.
        if ($amount < $minimum) {
            throw HttpException::unprocessable($package !== null
                ? 'This package is priced under the minimum booking amount of ' . Money::format($minimum) . ', so it cannot be booked until the creator updates it.'
                : 'The minimum booking amount is ' . Money::format($minimum) . '.');
        }

        if ($amount > $maximum) {
            throw HttpException::unprocessable($package !== null
                ? 'This package is priced over the maximum booking amount of ' . Money::format($maximum) . ', so it cannot be booked until the creator updates it.'
                : 'The maximum booking amount is ' . Money::format($maximum) . '.');
        }

        $commissionOverride = $creator['commission_rate'] !== null ? (float) $creator['commission_rate'] : null;
        $pricing            = Money::breakdown($amount, $commissionOverride);

        $expiryHours  = Config::settingInt('booking', 'request_expiry_hours', 72);
        $deliveryDays = Config::settingInt('booking', 'default_delivery_days', 7);

        return Database::transaction(static function () use ($brandUserId, $creator, $input, $pricing, $packageId, $expiryHours, $deliveryDays): array {
            self::assertPartiesStillLive($brandUserId, (int) $creator['id'], (int) $creator['creator_user_id']);

            $bookingId = Database::insert('bookings', [
                'code'              => 'PENDING',
                'campaign_id'       => isset($input['campaign_id']) && (int) $input['campaign_id'] > 0 ? (int) $input['campaign_id'] : null,
                'brand_user_id'     => $brandUserId,
                'creator_id'        => (int) $creator['id'],
                'creator_user_id'   => (int) $creator['creator_user_id'],
                'package_id'        => $packageId ?: null,
                'title'             => mb_substr((string) $input['title'], 0, 191),
                'description'       => $input['description'] ?? null,
                'campaign_type'     => mb_substr((string) ($input['campaign_type'] ?? 'Instagram Post'), 0, 80),
                'platform'          => (string) ($input['platform'] ?? 'instagram'),
                'deliverable_type'  => (string) ($input['deliverable_type'] ?? 'post'),
                'posts_count'       => max(1, (int) ($input['posts_count'] ?? 1)),
                'currency'          => Money::defaultCurrency(),
                'amount'            => $pricing['amount'],
                'platform_fee'      => $pricing['platform_fee'],
                'commission_amount' => $pricing['commission_amount'],
                'tax_amount'        => $pricing['tax_amount'],
                'tax_rate'          => $pricing['tax_rate'],
                'total_amount'      => $pricing['total_amount'],
                'creator_earning'   => $pricing['creator_earning'],
                'status'            => 'pending',
                'payment_status'    => 'unpaid',
                'escrow_status'     => 'none',
                'requested_at'      => date('Y-m-d H:i:s'),
                'expires_at'        => date('Y-m-d H:i:s', time() + ($expiryHours * 3600)),
                'delivery_due_at'   => $input['delivery_due_at'] ?? date('Y-m-d H:i:s', time() + ($deliveryDays * 86400)),
                'created_at'        => date('Y-m-d H:i:s'),
            ]);

            $code = Str::referenceCode('ZB', $bookingId, 6);
            Database::update('bookings', ['code' => $code], ['id' => $bookingId]);

            self::recordHistory($bookingId, null, 'pending', 'brand', $brandUserId, 'Booking request created.');

            // Open the conversation up front so both parties can talk while
            // the request is still pending.
            MessageService::ensureBookingConversation($bookingId, $brandUserId, (int) $creator['creator_user_id']);

            $booking = Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id', ['id' => $bookingId]);

            if ($booking === null) {
                throw HttpException::server('Failed to create the booking.');
            }

            // Tell the creator a request has arrived.
            //
            // The `booking.requested` template has been seeded and active since
            // the beginning and was never sent by anything — so a creator only
            // discovered a booking by opening the app, and the request expiry
            // clock (request_expiry_hours) ran against them silently.
            NotificationService::send(
                (int) $creator['creator_user_id'],
                'booking.requested',
                [
                    'brand_name'    => self::displayName($brandUserId),
                    'amount'        => Money::groupIndian($pricing['total_amount'], 0),
                    'currency'      => Money::symbol(),
                    'campaign_type' => (string) ($input['campaign_type'] ?? 'a collaboration'),
                    'booking_code'  => $code,
                    'expires_in'    => (string) $expiryHours,
                ],
                ['action_type' => 'booking', 'action_value' => (string) $bookingId]
            );

            return $booking;
        });
    }

    /**
     * Re-read both parties with shared locks inside the booking's transaction.
     *
     * The checks above ran before it began. An account erasure locks the
     * users row, then the creator row: these wait for it in the same order
     * (users first, so the two never deadlock) and then see the committed
     * tombstone, so a booking can never be written onto a deleted account.
     */
    private static function assertPartiesStillLive(int $brandUserId, int $creatorId, int $creatorUserId): void
    {
        $creatorUser = Database::selectOne(
            'SELECT `status`, `deleted_at` FROM `users` WHERE `id` = :id LOCK IN SHARE MODE',
            ['id' => $creatorUserId]
        );

        $creator = Database::selectOne(
            'SELECT `status`, `deleted_at`, `user_id` FROM `creators` WHERE `id` = :id LOCK IN SHARE MODE',
            ['id' => $creatorId]
        );

        if ($creatorUser === null || $creator === null || $creatorUser['deleted_at'] !== null
            || $creator['deleted_at'] !== null || (int) $creator['user_id'] !== $creatorUserId) {
            throw HttpException::notFound('Creator not found.');
        }

        if ($creator['status'] !== 'approved' || $creatorUser['status'] !== 'active') {
            throw HttpException::unprocessable('This creator is not accepting bookings right now.');
        }

        $brand = Database::selectOne(
            'SELECT `status`, `deleted_at` FROM `users` WHERE `id` = :id LOCK IN SHARE MODE',
            ['id' => $brandUserId]
        );

        if ($brand === null || $brand['deleted_at'] !== null) {
            throw HttpException::notFound('Account not found.');
        }

        if ($brand['status'] !== 'active') {
            throw HttpException::unprocessable('This account cannot make bookings right now.');
        }
    }

    // -----------------------------------------------------------------
    // Transitions
    // -----------------------------------------------------------------

    /**
     * Creator accepts the request.
     *
     * Not across a block: accepting turns a request into a commitment, and a
     * block stops new ones. The request can still be rejected or cancelled.
     */
    public static function accept(int $bookingId, int $creatorUserId): array
    {
        $booking = self::lockAndAuthorise($bookingId, $creatorUserId, 'creator');

        self::assertTransition($booking, 'accepted');

        BlockService::assertNotBlocked($creatorUserId, (int) $booking['brand_user_id']);

        Database::update('bookings', [
            'status'       => 'accepted',
            'responded_at' => date('Y-m-d H:i:s'),
            'accepted_at'  => date('Y-m-d H:i:s'),
            'expires_at'   => date('Y-m-d H:i:s', time() + (Config::settingInt('booking', 'payment_window_hours', 48) * 3600)),
        ], ['id' => $bookingId]);

        self::recordHistory($bookingId, (string) $booking['status'], 'accepted', 'creator', $creatorUserId, 'Creator accepted the request.');

        NotificationService::send((int) $booking['brand_user_id'], 'booking.accepted', [
            'creator_name'   => self::displayName((int) $booking['creator_user_id']),
            'booking_code'   => (string) $booking['code'],
            'payment_window' => (string) Config::settingInt('booking', 'payment_window_hours', 48),
        ], ['action_type' => 'booking', 'action_value' => (string) $bookingId]);

        return self::find($bookingId);
    }

    /** Creator declines the request. */
    public static function reject(int $bookingId, int $creatorUserId, string $reason = ''): array
    {
        $booking = self::lockAndAuthorise($bookingId, $creatorUserId, 'creator');

        self::assertTransition($booking, 'rejected');

        Database::update('bookings', [
            'status'           => 'rejected',
            'responded_at'     => date('Y-m-d H:i:s'),
            'rejection_reason' => mb_substr($reason, 0, 500),
        ], ['id' => $bookingId]);

        self::recordHistory($bookingId, (string) $booking['status'], 'rejected', 'creator', $creatorUserId, $reason);

        NotificationService::send((int) $booking['brand_user_id'], 'booking.rejected', [
            'creator_name' => self::displayName((int) $booking['creator_user_id']),
            'booking_code' => (string) $booking['code'],
            'reason'       => $reason,
        ], ['action_type' => 'booking', 'action_value' => (string) $bookingId]);

        return self::find($bookingId);
    }

    /**
     * Either party proposes different terms. The booking moves to
     * `countered` and the offer chain is recorded for the timeline.
     */
    public static function counterOffer(int $bookingId, int $userId, float $amount, int $postsCount, string $message = ''): array
    {
        if (!Config::settingBool('booking', 'allow_counter_offers', true)) {
            throw HttpException::forbidden('Counter offers are disabled.');
        }

        $booking = self::find($bookingId);
        $side    = self::sideOf($booking, $userId);

        self::assertTransition($booking, 'countered');

        // An offer is a new proposal, which a block stops either way.
        BlockService::assertNotBlocked(
            $userId,
            $side === 'brand' ? (int) $booking['creator_user_id'] : (int) $booking['brand_user_id']
        );

        $offerCount = (int) Database::scalar(
            'SELECT COUNT(*) FROM `booking_offers` WHERE `booking_id` = :id',
            ['id' => $bookingId]
        );

        $maxOffers = Config::settingInt('booking', 'max_counter_offers', 3);

        if ($offerCount >= $maxOffers) {
            throw HttpException::unprocessable('The negotiation limit of ' . $maxOffers . ' offers has been reached.');
        }

        $amount = Money::round($amount);

        // Both bounds, exactly as create() asserts them.
        //
        // Only the floor used to be checked here, so a counter offer was a way
        // around max_booking_amount: either side could re-price a booking far
        // above the platform ceiling the operator sets for fraud and exposure
        // control, and acceptOffer() then wrote that price onto the booking.
        self::assertAmountWithinPolicy($amount);

        return Database::transaction(static function () use ($booking, $bookingId, $userId, $side, $amount, $postsCount, $message): array {
            // Supersede any offer still awaiting a response.
            Database::execute(
                "UPDATE `booking_offers` SET `status` = 'superseded'
                 WHERE `booking_id` = :id AND `status` = 'pending'",
                ['id' => $bookingId]
            );

            Database::insert('booking_offers', [
                'booking_id'    => $bookingId,
                'offered_by'    => $side,
                'offered_by_id' => $userId,
                'amount'        => $amount,
                'posts_count'   => max(1, $postsCount),
                'message'       => mb_substr($message, 0, 1000),
                'status'        => 'pending',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            Database::update('bookings', [
                'status'       => 'countered',
                'responded_at' => date('Y-m-d H:i:s'),
            ], ['id' => $bookingId]);

            self::recordHistory(
                $bookingId,
                (string) $booking['status'],
                'countered',
                $side,
                $userId,
                'Counter offer of ' . Money::format($amount)
            );

            $recipientId = $side === 'brand' ? (int) $booking['creator_user_id'] : (int) $booking['brand_user_id'];

            NotificationService::send($recipientId, 'booking.countered', [
                'sender_name'  => self::displayName($userId),
                'amount'       => Money::groupIndian($amount, 0),
                'currency'     => Money::symbol(),
                'posts_count'  => (string) $postsCount,
                'booking_code' => (string) $booking['code'],
            ], ['action_type' => 'booking', 'action_value' => (string) $bookingId]);

            return self::find($bookingId);
        });
    }

    /** Accept the pending counter offer and re-price the booking. */
    public static function acceptOffer(int $bookingId, int $userId): array
    {
        $booking = self::find($bookingId);
        $side    = self::sideOf($booking, $userId);

        // The BOOKING's state gates this, not just the offer's.
        //
        // Rejecting or cancelling a booking does not close its outstanding
        // offer rows, so a `pending` offer outlives the negotiation. Checking
        // only the offer meant accepting one could drag a rejected — or
        // expired, or cancelled — booking back to `accepted`, reviving a deal
        // both sides had walked away from.
        if (!in_array((string) $booking['status'], ['pending', 'countered'], true)) {
            throw HttpException::conflict(sprintf(
                'This booking is %s, so its offers can no longer be accepted.',
                str_replace('_', ' ', (string) $booking['status'])
            ));
        }

        $offer = Database::selectOne(
            "SELECT * FROM `booking_offers`
             WHERE `booking_id` = :id AND `status` = 'pending'
             ORDER BY `id` DESC LIMIT 1",
            ['id' => $bookingId]
        );

        if ($offer === null) {
            throw HttpException::notFound('There is no offer awaiting a response.');
        }

        if ($offer['offered_by'] === $side) {
            throw HttpException::forbidden('You cannot accept your own offer.');
        }

        BlockService::assertNotBlocked(
            $userId,
            $side === 'brand' ? (int) $booking['creator_user_id'] : (int) $booking['brand_user_id']
        );

        // Re-assert the policy at acceptance, not only when the offer was made.
        // An offer row outlives a change to min/max_booking_amount, and rows
        // written before the bound existed are still `pending`, so the price
        // that actually lands on the booking is checked here too.
        self::assertAmountWithinPolicy((float) $offer['amount']);

        $creator = Database::selectOne(
            'SELECT `commission_rate` FROM `creators` WHERE `id` = :id',
            ['id' => (int) $booking['creator_id']]
        );

        $pricing = Money::breakdown(
            (float) $offer['amount'],
            $creator !== null && $creator['commission_rate'] !== null ? (float) $creator['commission_rate'] : null
        );

        return Database::transaction(static function () use ($booking, $bookingId, $userId, $side, $offer, $pricing): array {
            Database::update('booking_offers', [
                'status'       => 'accepted',
                'responded_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $offer['id']]);

            Database::update('bookings', [
                'amount'            => $pricing['amount'],
                'platform_fee'      => $pricing['platform_fee'],
                'commission_amount' => $pricing['commission_amount'],
                'tax_amount'        => $pricing['tax_amount'],
                'tax_rate'          => $pricing['tax_rate'],
                'total_amount'      => $pricing['total_amount'],
                'creator_earning'   => $pricing['creator_earning'],
                'posts_count'       => (int) $offer['posts_count'],
                'status'            => 'accepted',
                'accepted_at'       => date('Y-m-d H:i:s'),
                'responded_at'      => date('Y-m-d H:i:s'),
                'expires_at'        => date('Y-m-d H:i:s', time() + (Config::settingInt('booking', 'payment_window_hours', 48) * 3600)),
            ], ['id' => $bookingId]);

            self::recordHistory(
                $bookingId,
                (string) $booking['status'],
                'accepted',
                $side,
                $userId,
                'Counter offer accepted at ' . Money::format((float) $offer['amount'])
            );

            NotificationService::send(
                $side === 'brand' ? (int) $booking['creator_user_id'] : (int) $booking['brand_user_id'],
                'booking.accepted',
                [
                    'creator_name'   => self::displayName((int) $booking['creator_user_id']),
                    'booking_code'   => (string) $booking['code'],
                    'payment_window' => (string) Config::settingInt('booking', 'payment_window_hours', 48),
                ],
                ['action_type' => 'booking', 'action_value' => (string) $bookingId]
            );

            return self::find($bookingId);
        });
    }

    /**
     * Mark a booking paid and hold the funds in escrow.
     * Called by PaymentService once a gateway capture is verified — never
     * directly from a controller.
     */
    public static function markPaid(int $bookingId, int $paymentId): array
    {
        return Database::transaction(static function () use ($bookingId, $paymentId): array {
            $booking = Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id FOR UPDATE', ['id' => $bookingId]);

            if ($booking === null) {
                throw HttpException::notFound('Booking not found.');
            }

            if ($booking['payment_status'] === 'paid') {
                return $booking; // Idempotent: a duplicate webhook changes nothing.
            }

            Database::update('bookings', [
                'status'         => 'confirmed',
                'payment_status' => 'paid',
                'escrow_status'  => 'held',
                'paid_at'        => date('Y-m-d H:i:s'),
                'started_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $bookingId]);

            self::recordHistory($bookingId, (string) $booking['status'], 'confirmed', 'system', null, 'Payment captured and held in escrow.');

            // Burn the coupon only now that money has actually moved. Every
            // gateway and the wallet funnel through here, and the early return
            // above makes a duplicate webhook a no-op, so a coupon is spent
            // exactly once per booking that is genuinely paid — and never at
            // all for a checkout the customer abandoned.
            CouponService::redeemForBooking($booking);

            // Recognise platform revenue at capture time.
            Database::insertIgnore('platform_earnings', [
                'booking_id'    => $bookingId,
                'payment_id'    => $paymentId,
                'platform_fee'  => (float) $booking['platform_fee'],
                'commission'    => (float) $booking['commission_amount'],
                'tax_collected' => (float) $booking['tax_amount'],
                'total'         => Money::add((float) $booking['platform_fee'], (float) $booking['commission_amount']),
                'currency'      => (string) $booking['currency'],
                'earned_on'     => date('Y-m-d'),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            InvoiceService::issueForBooking($bookingId);

            NotificationService::sendMany(
                [(int) $booking['brand_user_id'], (int) $booking['creator_user_id']],
                'booking.confirmed',
                [
                    'creator_name'   => self::displayName((int) $booking['creator_user_id']),
                    'campaign_title' => (string) $booking['title'],
                    'booking_code'   => (string) $booking['code'],
                ],
                ['action_type' => 'booking', 'action_value' => (string) $bookingId]
            );

            return self::find($bookingId);
        });
    }

    /** Creator submits the finished work. */
    /**
     * The brand sends delivered work back for changes.
     *
     * The middle option between approving and disputing: the deliverables go
     * to `revision_requested`, the booking drops back to `in_progress`, and
     * escrow stays held. The Flutter deliverable-review screen has offered this
     * as one of its three branches since it was built, and posted to an
     * endpoint that did not exist — the button reported success and nothing
     * happened, so the creator was never told and the booking stayed
     * "delivered" waiting on a brand who thought they had already replied.
     *
     * @return array<string,mixed>
     */
    public static function requestRevision(int $bookingId, int $brandUserId, string $note): array
    {
        return Database::transaction(static function () use ($bookingId, $brandUserId, $note): array {
            $booking = self::lockAndAuthorise($bookingId, $brandUserId, 'brand');

            if ($booking['status'] !== 'delivered') {
                throw HttpException::unprocessable(
                    'Changes can only be requested on work that has been delivered.'
                );
            }

            $limit = Config::settingInt('booking', 'max_revisions', 2);

            // The cap is per deliverable, and the highest count on the booking
            // decides — otherwise a brand could keep sending back one item
            // forever by rotating which one they name.
            $used = (int) (Database::scalar(
                'SELECT COALESCE(MAX(`revision_count`), 0) FROM `booking_deliverables` WHERE `booking_id` = :id',
                ['id' => $bookingId]
            ) ?? 0);

            if ($limit > 0 && $used >= $limit) {
                throw HttpException::unprocessable(sprintf(
                    'This booking has already used its %d revision%s. Approve the work or raise a dispute.',
                    $limit,
                    $limit === 1 ? '' : 's'
                ));
            }

            Database::update('bookings', [
                'status'     => 'in_progress',
                'started_at' => $booking['started_at'] ?? date('Y-m-d H:i:s'),
            ], ['id' => $bookingId]);

            Database::execute(
                "UPDATE `booking_deliverables`
                    SET `status` = 'revision_requested',
                        `revision_count` = `revision_count` + 1,
                        `remarks` = :note,
                        `reviewed_at` = NOW()
                  WHERE `booking_id` = :id AND `status` = 'submitted'",
                ['id' => $bookingId, 'note' => mb_substr($note, 0, 1000)]
            );

            self::recordHistory(
                $bookingId,
                'delivered',
                'in_progress',
                'brand',
                $brandUserId,
                'Changes requested: ' . mb_substr($note, 0, 400)
            );

            NotificationService::send(
                (int) $booking['creator_user_id'],
                'booking.revision_requested',
                [
                    'booking_code'   => (string) $booking['code'],
                    'campaign_title' => (string) $booking['title'],
                    'note'           => mb_substr($note, 0, 400),
                ],
                ['action_type' => 'booking', 'action_value' => (string) $bookingId]
            );

            return Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id', ['id' => $bookingId]) ?? $booking;
        });
    }

    /**
     * Either party escalates to the platform.
     *
     * Escrow is deliberately left held rather than refunded or released: the
     * whole point of a dispute is that neither side gets the money until an
     * admin rules. The booking moves to `disputed`, which the state machine
     * already allows from confirmed, in_progress, delivered and completed.
     *
     * @param  list<string> $evidenceUrls
     * @return array<string,mixed>
     */
    public static function dispute(
        int $bookingId,
        int $userId,
        string $reason,
        string $description = '',
        array $evidenceUrls = []
    ): array {
        return Database::transaction(static function () use ($bookingId, $userId, $reason, $description, $evidenceUrls): array {
            $booking = Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id FOR UPDATE', ['id' => $bookingId]);

            if ($booking === null) {
                throw HttpException::notFound('Booking not found.');
            }

            $side = self::sideOf($booking, $userId);

            // Nothing to dispute before money has moved, and nothing to gain
            // from disputing a booking that never went ahead.
            if ($booking['payment_status'] !== 'paid') {
                throw HttpException::unprocessable(
                    'A booking can only be disputed once it has been paid for.'
                );
            }

            if ($booking['status'] === 'disputed') {
                throw HttpException::conflict('This booking is already under dispute.');
            }

            self::assertTransition($booking, 'disputed');

            $existing = Database::selectOne(
                "SELECT `id` FROM `booking_disputes`
                  WHERE `booking_id` = :id AND `status` IN ('open', 'under_review') LIMIT 1",
                ['id' => $bookingId]
            );

            if ($existing !== null) {
                throw HttpException::conflict('A dispute on this booking is already open.');
            }

            $disputeId = Database::insert('booking_disputes', [
                'booking_id'    => $bookingId,
                'raised_by'     => $side,
                'raised_by_id'  => $userId,
                'reason'        => $reason,
                'description'   => mb_substr($description, 0, 2000),
                'evidence_urls' => $evidenceUrls === []
                    ? null
                    : json_encode(array_slice($evidenceUrls, 0, 10), JSON_UNESCAPED_SLASHES),
                'status'        => 'open',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            Database::update('bookings', ['status' => 'disputed'], ['id' => $bookingId]);

            self::recordHistory(
                $bookingId,
                (string) $booking['status'],
                'disputed',
                $side,
                $userId,
                'Dispute raised: ' . str_replace('_', ' ', $reason)
            );

            // Both parties are told, not just the other one: the person who
            // raised it needs the reference, and silence after an escalation
            // reads as the report having been lost.
            NotificationService::sendMany(
                [(int) $booking['brand_user_id'], (int) $booking['creator_user_id']],
                'booking.disputed',
                [
                    'booking_code'   => (string) $booking['code'],
                    'campaign_title' => (string) $booking['title'],
                    'reason'         => str_replace('_', ' ', $reason),
                ],
                ['action_type' => 'booking', 'action_value' => (string) $bookingId]
            );

            Logger::warning('Booking dispute raised', [
                'booking_id' => $bookingId,
                'dispute_id' => $disputeId,
                'raised_by'  => $side,
                'user_id'    => $userId,
                'reason'     => $reason,
            ]);

            $updated = Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id', ['id' => $bookingId]) ?? $booking;
            $updated['dispute_id'] = $disputeId;

            return $updated;
        });
    }

    public static function deliver(int $bookingId, int $creatorUserId, array $deliverables): array
    {
        $booking = self::lockAndAuthorise($bookingId, $creatorUserId, 'creator');

        if (!in_array($booking['status'], ['confirmed', 'in_progress'], true)) {
            throw HttpException::unprocessable('Only a confirmed booking can be delivered.');
        }

        if ($deliverables === []) {
            throw HttpException::unprocessable('Add at least one deliverable before submitting.');
        }

        return Database::transaction(static function () use ($booking, $bookingId, $creatorUserId, $deliverables): array {
            foreach ($deliverables as $deliverable) {
                Database::insert('booking_deliverables', [
                    'booking_id'    => $bookingId,
                    'title'         => mb_substr((string) ($deliverable['title'] ?? 'Deliverable'), 0, 191),
                    'description'   => $deliverable['description'] ?? null,
                    'content_url'   => $deliverable['content_url'] ?? null,
                    'media_url'     => $deliverable['media_url'] ?? null,
                    'thumbnail_url' => $deliverable['thumbnail_url'] ?? null,
                    'metrics'       => isset($deliverable['metrics']) && is_array($deliverable['metrics'])
                        ? json_encode($deliverable['metrics'], JSON_UNESCAPED_UNICODE)
                        : null,
                    'status'        => 'submitted',
                    'submitted_at'  => date('Y-m-d H:i:s'),
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            }

            Database::update('bookings', [
                'status'       => 'delivered',
                'delivered_at' => date('Y-m-d H:i:s'),
            ], ['id' => $bookingId]);

            self::recordHistory($bookingId, (string) $booking['status'], 'delivered', 'creator', $creatorUserId, 'Deliverables submitted for review.');

            NotificationService::send((int) $booking['brand_user_id'], 'booking.delivered', [
                'creator_name' => self::displayName($creatorUserId),
                'booking_code' => (string) $booking['code'],
            ], ['action_type' => 'booking', 'action_value' => (string) $bookingId]);

            return self::find($bookingId);
        });
    }

    /**
     * Brand approves the work: escrow is released and the creator is paid.
     *
     * @param int|null $actorId The brand's user id for 'brand', the admin's id
     *                          for 'admin', null for the cron.
     * @param string   $actor   'brand' | 'admin' | 'system' — the auto-complete
     *                          cron passes 'system', the admin panel 'admin'.
     */
    public static function complete(int $bookingId, ?int $actorId = null, string $actor = 'brand'): array
    {
        $brandUserId = $actorId;

        return Database::transaction(static function () use ($bookingId, $brandUserId, $actor): array {
            $booking = Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id FOR UPDATE', ['id' => $bookingId]);

            if ($booking === null) {
                throw HttpException::notFound('Booking not found.');
            }

            if ($actor === 'brand' && (int) $booking['brand_user_id'] !== (int) $brandUserId) {
                throw HttpException::forbidden('Only the brand that made this booking can approve it.');
            }

            if ($booking['status'] === 'completed') {
                return $booking;
            }

            if (!in_array($booking['status'], ['delivered', 'in_progress', 'disputed'], true)) {
                throw HttpException::unprocessable('This booking cannot be completed from its current status.');
            }

            // What is actually left in escrow, not what the booking was worth
            // when it was priced.
            //
            // A refund reduces the money the platform is holding, but
            // `bookings.creator_earning` is the original figure and never
            // moves, so completing a partially refunded booking used to pay
            // the creator the FULL earning out of a smaller pot — the platform
            // silently covered the difference. The retained share is applied
            // proportionally, which is the same basis the refund itself used.
            $payable = self::payableEarning($bookingId, (float) $booking['creator_earning']);

            Database::update('bookings', [
                'status'        => 'completed',
                // Nothing is released when the payment was refunded in full;
                // saying "released" there would contradict the ledger.
                'escrow_status' => $payable > 0 ? 'released' : (string) $booking['escrow_status'],
                'completed_at'  => date('Y-m-d H:i:s'),
            ], ['id' => $bookingId]);

            Database::execute(
                "UPDATE `booking_deliverables`
                 SET `status` = 'approved', `reviewed_at` = NOW()
                 WHERE `booking_id` = :id AND `status` = 'submitted'",
                ['id' => $bookingId]
            );

            // Release escrow to the creator.
            if ($payable > 0) {
                WalletService::creditEarning(
                    (int) $booking['creator_user_id'],
                    $payable,
                    $bookingId,
                    (string) $booking['code']
                );
            }

            Database::execute(
                'UPDATE `creators` SET `collabs_count` = `collabs_count` + 1 WHERE `id` = :id',
                ['id' => (int) $booking['creator_id']]
            );

            $reduced = !Money::equals($payable, Money::round((float) $booking['creator_earning']));

            // Say who actually did it. An admin force-completing from the panel
            // used to be filed as 'system' with the cron's own wording, so the
            // timeline both parties read claimed the review window had elapsed
            // when in fact a named operator had intervened.
            self::recordHistory(
                $bookingId,
                (string) $booking['status'],
                'completed',
                match ($actor) {
                    'system' => 'system',
                    'admin'  => 'admin',
                    default  => 'brand',
                },
                $brandUserId,
                match ($actor) {
                    'system' => 'Auto-completed after the review window elapsed.',
                    'admin'  => 'Completed by an administrator; escrow released.',
                    default  => 'Brand approved the deliverables.',
                }
                . ($reduced ? ' Earning reduced to ' . Money::format($payable) . ' after refunds.' : '')
            );

            if ($payable > 0) {
                NotificationService::send((int) $booking['creator_user_id'], 'wallet.earning', [
                    'amount'       => Money::groupIndian($payable, 0),
                    'currency'     => Money::symbol(),
                    'booking_code' => (string) $booking['code'],
                ], ['action_type' => 'wallet']);
            }

            NotificationService::send((int) $booking['brand_user_id'], 'booking.completed', [
                'creator_name' => self::displayName((int) $booking['creator_user_id']),
                'booking_code' => (string) $booking['code'],
            ], ['action_type' => 'review', 'action_value' => (string) $bookingId]);

            ReferralService::qualifyForUser((int) $booking['brand_user_id']);

            return self::find($bookingId);
        });
    }

    /**
     * Cancel a booking. Refund policy is applied automatically based on how
     * far the booking had progressed.
     */
    public static function cancel(int $bookingId, int $userId, string $reason, string $actorType = 'brand'): array
    {
        return Database::transaction(static function () use ($bookingId, $userId, $reason, $actorType): array {
            $booking = Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id FOR UPDATE', ['id' => $bookingId]);

            if ($booking === null) {
                throw HttpException::notFound('Booking not found.');
            }

            if ($actorType !== 'admin') {
                $side = self::sideOf($booking, $userId);
                if ($side !== $actorType) {
                    throw HttpException::forbidden('You are not a party to this booking.');
                }
            }

            self::assertTransition($booking, 'cancelled');

            $refundAmount = 0.0;

            if ($booking['payment_status'] === 'paid') {
                $refundAmount = self::refundableAmount($booking, $actorType);

                if ($refundAmount > 0) {
                    PaymentService::refundBooking(
                        $bookingId,
                        $refundAmount,
                        'booking_cancelled',
                        $actorType === 'admin' ? 'admin' : 'user',
                        $userId
                    );
                }

                // Anything retained goes to the creator as compensation.
                $retained = Money::subtract((float) $booking['total_amount'], $refundAmount);

                if ($retained > 0 && $actorType === 'brand') {
                    WalletService::credit((int) $booking['creator_user_id'], $retained, [
                        'category'        => 'booking_earning',
                        'description'     => 'Late cancellation compensation for #' . (string) $booking['code'],
                        'reference_type'  => 'booking',
                        'reference_id'    => $bookingId,
                        'idempotency_key' => 'cancel-compensation:' . $bookingId,
                    ]);
                }
            }

            Database::update('bookings', [
                'status'              => 'cancelled',
                'escrow_status'       => $booking['escrow_status'] === 'held' ? 'refunded' : $booking['escrow_status'],
                'cancelled_at'        => date('Y-m-d H:i:s'),
                'cancelled_by_type'   => $actorType,
                'cancelled_by_id'     => $userId,
                'cancellation_reason' => mb_substr($reason, 0, 500),
            ], ['id' => $bookingId]);

            self::recordHistory($bookingId, (string) $booking['status'], 'cancelled', $actorType, $userId, $reason);

            NotificationService::sendMany(
                [(int) $booking['brand_user_id'], (int) $booking['creator_user_id']],
                'booking.cancelled',
                ['booking_code' => (string) $booking['code'], 'reason' => $reason],
                ['action_type' => 'booking', 'action_value' => (string) $bookingId]
            );

            return self::find($bookingId);
        });
    }

    // -----------------------------------------------------------------
    // Scheduled maintenance (called by cron)
    // -----------------------------------------------------------------

    /** Expire requests nobody answered and acceptances nobody paid for. */
    public static function expireStale(): int
    {
        $stale = Database::select(
            "SELECT * FROM `bookings`
             WHERE `status` IN ('pending','countered','accepted','awaiting_payment')
               AND `expires_at` IS NOT NULL AND `expires_at` <= NOW()
               AND `deleted_at` IS NULL
             LIMIT 200"
        );

        $expired = 0;

        foreach ($stale as $booking) {
            try {
                Database::update('bookings', [
                    'status'       => 'expired',
                    'cancelled_at' => date('Y-m-d H:i:s'),
                ], ['id' => (int) $booking['id']]);

                self::recordHistory(
                    (int) $booking['id'],
                    (string) $booking['status'],
                    'expired',
                    'cron',
                    null,
                    'Expired automatically — no response within the allowed window.'
                );

                NotificationService::sendMany(
                    [(int) $booking['brand_user_id'], (int) $booking['creator_user_id']],
                    'booking.cancelled',
                    [
                        'booking_code' => (string) $booking['code'],
                        'reason'       => 'The request expired before it was actioned.',
                    ],
                    ['action_type' => 'booking', 'action_value' => (string) $booking['id']]
                );

                $expired++;
            } catch (\Throwable $e) {
                Logger::error('Failed to expire a booking', ['booking_id' => $booking['id'], 'error' => $e->getMessage()]);
            }
        }

        return $expired;
    }

    /** Auto-complete delivered work the brand never reviewed. */
    public static function autoCompleteDelivered(): int
    {
        $days = Config::settingInt('booking', 'auto_complete_days', 7);

        $candidates = Database::select(
            "SELECT `id` FROM `bookings`
             WHERE `status` = 'delivered'
               AND `delivered_at` <= DATE_SUB(NOW(), INTERVAL :days DAY)
               AND `deleted_at` IS NULL
             LIMIT 100",
            ['days' => max(1, $days)]
        );

        $completed = 0;

        foreach ($candidates as $candidate) {
            try {
                self::complete((int) $candidate['id'], null, 'system');
                $completed++;
            } catch (\Throwable $e) {
                Logger::error('Auto-completion failed', ['booking_id' => $candidate['id'], 'error' => $e->getMessage()]);
            }
        }

        return $completed;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public static function find(int $bookingId): array
    {
        $booking = Database::selectOne(
            'SELECT * FROM `bookings` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        return $booking;
    }

    /** Full booking payload for the detail screen. */
    public static function detail(int $bookingId, int $viewerId): array
    {
        $booking = Database::selectOne(
            'SELECT b.*,
                    bu.`name` AS `brand_name`, bu.`avatar_url` AS `brand_avatar`, bu.`company_name`,
                    bu.`has_blue_tick` AS `brand_verified`,
                    cu.`name` AS `creator_account_name`, cu.`avatar_url` AS `creator_avatar`,
                    c.`display_name` AS `creator_display_name`, c.`headline` AS `creator_headline`,
                    c.`handle` AS `creator_handle`, c.`rating_avg` AS `creator_rating`, c.`is_verified` AS `creator_verified`
             FROM `bookings` b
             INNER JOIN `users` bu ON bu.`id` = b.`brand_user_id`
             INNER JOIN `users` cu ON cu.`id` = b.`creator_user_id`
             INNER JOIN `creators` c ON c.`id` = b.`creator_id`
             WHERE b.`id` = :id AND b.`deleted_at` IS NULL',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        if ((int) $booking['brand_user_id'] !== $viewerId && (int) $booking['creator_user_id'] !== $viewerId) {
            throw HttpException::forbidden('You are not a party to this booking.');
        }

        $booking['timeline']     = Database::select(
            'SELECT * FROM `booking_status_history` WHERE `booking_id` = :id ORDER BY `id`',
            ['id' => $bookingId]
        );
        $booking['offers']       = Database::select(
            'SELECT * FROM `booking_offers` WHERE `booking_id` = :id ORDER BY `id`',
            ['id' => $bookingId]
        );
        $booking['deliverables'] = Database::select(
            'SELECT * FROM `booking_deliverables` WHERE `booking_id` = :id ORDER BY `id`',
            ['id' => $bookingId]
        );
        $booking['invoice']      = Database::selectOne(
            "SELECT * FROM `invoices` WHERE `booking_id` = :id AND `type` = 'brand_invoice' LIMIT 1",
            ['id' => $bookingId]
        );

        // The thread for this booking, if one has been started.
        //
        // `bookings` has no conversation_id column, so this was never supplied
        // and the app's `conversationId` was null on every booking. Both of its
        // Message buttons then fell back to `?? booking.id` and opened the chat
        // with a BOOKING id where a conversation id belongs — a 403 every time,
        // shown to the operator as "We hit a snag".
        //
        // Null here is a legitimate answer meaning "no thread yet"; the client
        // opens one through POST /conversations/start rather than guessing.
        $booking['conversation_id'] = Database::scalar(
            'SELECT `id` FROM `conversations` WHERE `booking_id` = :id ORDER BY `id` LIMIT 1',
            ['id' => $bookingId]
        );

        if ($booking['conversation_id'] !== null) {
            $booking['conversation_id'] = (int) $booking['conversation_id'];
        }

        return $booking;
    }

    /** @return array<string,mixed> */
    private static function lockAndAuthorise(int $bookingId, int $userId, string $requiredSide): array
    {
        $booking = Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id FOR UPDATE', ['id' => $bookingId]);

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        if (self::sideOf($booking, $userId) !== $requiredSide) {
            throw HttpException::forbidden('You are not authorised to perform this action on this booking.');
        }

        return $booking;
    }

    /** @param array<string,mixed> $booking */
    private static function sideOf(array $booking, int $userId): string
    {
        if ((int) $booking['brand_user_id'] === $userId) {
            return 'brand';
        }

        if ((int) $booking['creator_user_id'] === $userId) {
            return 'creator';
        }

        throw HttpException::forbidden('You are not a party to this booking.');
    }

    /**
     * The creator earning that is still backed by money the platform holds.
     *
     * `bookings.creator_earning` is fixed at pricing time; refunds are recorded
     * on `payments.amount_refunded`. When part of a booking has been refunded,
     * only the retained share of the earning can be released — pro-rated on the
     * same basis the refund was taken, so the creator, the brand and platform
     * revenue all absorb the refund in proportion.
     *
     * Returns the untouched earning when nothing has been refunded, so the
     * ordinary path is bit-for-bit what it always was.
     */
    private static function payableEarning(int $bookingId, float $creatorEarning): float
    {
        $earning = Money::round($creatorEarning);

        $row = Database::selectOne(
            "SELECT COALESCE(SUM(`amount`), 0) AS `paid`,
                    COALESCE(SUM(`amount_refunded`), 0) AS `refunded`
               FROM `payments`
              WHERE `booking_id` = :id
                AND `status` IN ('captured', 'partially_refunded', 'refunded')",
            ['id' => $bookingId]
        );

        $paid     = Money::round((float) ($row['paid'] ?? 0));
        $refunded = Money::round((float) ($row['refunded'] ?? 0));

        if ($paid <= 0 || $refunded <= 0) {
            return $earning;
        }

        $retained = Money::max(0.0, Money::subtract($paid, $refunded));

        return Money::clamp(Money::multiply($earning, $retained / $paid), 0.0, $earning);
    }

    /**
     * The platform's price bounds, applied to a negotiated amount.
     *
     * create() asserts both bounds inline with its own wording; this is the
     * same policy for the negotiation path, where only the floor was checked.
     */
    private static function assertAmountWithinPolicy(float $amount): void
    {
        if ($amount < Config::settingFloat('booking', 'min_booking_amount', 1000)) {
            throw HttpException::unprocessable('The offered amount is below the platform minimum.');
        }

        $maximum = Config::settingFloat('booking', 'max_booking_amount', 2000000);

        if ($amount > $maximum) {
            throw HttpException::unprocessable(
                'The offered amount is above the platform maximum of ' . Money::format($maximum) . '.'
            );
        }
    }

    /** @param array<string,mixed> $booking */
    private static function assertTransition(array $booking, string $target): void
    {
        $current = (string) $booking['status'];
        $allowed = self::ALLOWED_TRANSITIONS[$current] ?? [];

        if (!in_array($target, $allowed, true)) {
            throw HttpException::conflict(
                sprintf('A booking that is "%s" cannot become "%s".', str_replace('_', ' ', $current), str_replace('_', ' ', $target)),
                'invalid_status_transition'
            );
        }
    }

    /**
     * Is this move legal? For callers that need to decide before acting rather
     * than catch an exception — the admin dispute screen, for one.
     */
    public static function canTransition(string $from, string $to): bool
    {
        return $from === $to || in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    /**
     * The status a booking held immediately before it was disputed.
     *
     * Read from `booking_status_history` because `booking_disputes` does not
     * record it. Falls back to `completed` only when there is no history to
     * read, which means the dispute predates the history being written.
     */
    public static function statusBeforeDispute(int $bookingId): string
    {
        $row = Database::selectOne(
            "SELECT `from_status` FROM `booking_status_history`
              WHERE `booking_id` = :id AND `to_status` = 'disputed'
              ORDER BY `id` DESC LIMIT 1",
            ['id' => $bookingId]
        );

        return (string) ($row['from_status'] ?? 'completed');
    }

    /**
     * An administrative status change that is validated and recorded.
     *
     * Exists so admin screens stop writing `bookings.status` directly. Doing
     * that skipped the state machine and left no history row, which is how a
     * cancelled, never-paid booking could be turned into a completed
     * collaboration with released escrow and no trace of who did it.
     *
     * @param array<string,mixed> $extra additional columns to set alongside the status
     */
    public static function forceStatus(
        int $bookingId,
        string $target,
        string $actorType,
        ?int $actorId,
        string $note = '',
        array $extra = []
    ): void {
        $booking = Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id', ['id' => $bookingId]);

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        $current = (string) $booking['status'];

        if ($current === $target) {
            return;
        }

        self::assertTransition($booking, $target);

        // Nulls in $extra are deliberate clears, but a null for a NOT NULL
        // column would fail — so only non-null extras are written.
        $columns = ['status' => $target];

        foreach ($extra as $column => $value) {
            if ($value !== null) {
                $columns[$column] = $value;
            }
        }

        Database::update('bookings', $columns, ['id' => $bookingId]);

        self::recordHistory($bookingId, $current, $target, $actorType, $actorId, $note);
    }

    /**
     * Refund policy:
     *  - creator or admin cancels → full refund;
     *  - brand cancels outside the free window → full refund;
     *  - brand cancels inside the free window → retain the late fee.
     *
     * @param array<string,mixed> $booking
     */
    private static function refundableAmount(array $booking, string $actorType): float
    {
        $total = Money::round((float) $booking['total_amount']);

        if ($actorType !== 'brand') {
            return $total;
        }

        $freeDays = Config::settingInt('booking', 'free_cancellation_days', 7);
        $dueAt    = $booking['delivery_due_at'] !== null ? strtotime((string) $booking['delivery_due_at']) : false;

        // >= so a cancellation exactly on the boundary is still free. The
        // fee is for cancelling INSIDE the window, not on its edge.
        if ($dueAt === false || ($dueAt - time()) >= ($freeDays * 86400)) {
            return $total;
        }

        $feePercent = Config::settingFloat('booking', 'late_cancellation_fee_percent', 25);

        return Money::subtract($total, Money::percentOf($total, $feePercent));
    }

    private static function recordHistory(
        int $bookingId,
        ?string $from,
        string $to,
        string $actorType,
        ?int $actorId,
        string $note = ''
    ): void {
        Database::insert('booking_status_history', [
            'booking_id'      => $bookingId,
            'from_status'     => $from,
            'to_status'       => $to,
            'changed_by_type' => $actorType,
            'changed_by_id'   => $actorId,
            'note'            => mb_substr($note, 0, 500),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    private static function displayName(int $userId): string
    {
        $name = Database::scalar(
            'SELECT COALESCE(c.`display_name`, u.`name`)
             FROM `users` u LEFT JOIN `creators` c ON c.`user_id` = u.`id`
             WHERE u.`id` = :id',
            ['id' => $userId]
        );

        return (string) ($name ?? 'Someone');
    }
}

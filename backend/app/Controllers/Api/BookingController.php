<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Money;
use Zuste\Services\BookingService;
use Zuste\Services\CouponService;
use Zuste\Services\InvoiceService;

/**
 * Bookings and campaigns.
 *
 * Routes: /api/v1/bookings/*
 */
final class BookingController extends BaseApiController
{
    /** Statuses grouped into the tabs shown on My Bookings. */
    private const TAB_FILTERS = [
        'all'       => [],
        'pending'   => ['pending', 'countered', 'accepted', 'awaiting_payment'],
        'confirmed' => ['confirmed', 'in_progress', 'delivered'],
        'completed' => ['completed'],
        'cancelled' => ['cancelled', 'rejected', 'expired', 'refunded'],
        // The app's "Active" tab: pending ∪ confirmed. Without it the app had
        // to ask for `all` and narrow the first page itself, so an active
        // booking older than the twenty newest rows never appeared, and the
        // tab had no count of its own to badge.
        'active'    => ['pending', 'countered', 'accepted', 'awaiting_payment', 'confirmed', 'in_progress', 'delivered'],
    ];

    /** GET /bookings */
    public function index(Request $request): never
    {
        $user       = $this->user($request);
        $userId     = (int) $user['id'];
        $pagination = $this->pagination($request, 20, 50);

        $tab      = $request->string('tab', 'all');
        $statuses = self::TAB_FILTERS[$tab] ?? [];

        $clauses  = ['b.`deleted_at` IS NULL'];
        $bindings = ['user_id' => $userId];

        // Which side of the marketplace is asking?
        $role = $request->string('role', $user['account_type'] === 'creator' ? 'creator' : 'brand');

        $clauses[] = $role === 'creator'
            ? 'b.`creator_user_id` = :user_id'
            : 'b.`brand_user_id` = :user_id';

        if ($statuses !== []) {
            $placeholders = [];
            foreach ($statuses as $index => $status) {
                $placeholders[]              = ':status_' . $index;
                $bindings['status_' . $index] = $status;
            }
            $clauses[] = 'b.`status` IN (' . implode(', ', $placeholders) . ')';
        }

        if ($request->filled('search')) {
            $clauses[]          = "(b.`code` LIKE :search ESCAPE '\\\\' OR b.`title` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = \Zuste\Helpers\Str::likeTerm($request->string('search'));
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `bookings` b' . $where, $bindings);

        $rows = Database::select(
            'SELECT b.`id`, b.`code`, b.`title`, b.`campaign_type`, b.`platform`, b.`posts_count`,
                    b.`amount`, b.`total_amount`, b.`creator_earning`, b.`currency`,
                    b.`status`, b.`payment_status`, b.`escrow_status`,
                    b.`requested_at`, b.`delivery_due_at`, b.`completed_at`, b.`created_at`,
                    b.`brand_reviewed`, b.`creator_reviewed`,
                    bu.`name` AS `brand_name`, bu.`avatar_url` AS `brand_avatar`, bu.`company_name`,
                    bu.`has_blue_tick` AS `brand_verified`,
                    cu.`avatar_url` AS `creator_avatar`,
                    c.`display_name` AS `creator_name`, c.`handle` AS `creator_handle`, c.`is_verified` AS `creator_verified`
             FROM `bookings` b
             INNER JOIN `users` bu ON bu.`id` = b.`brand_user_id`
             INNER JOIN `users` cu ON cu.`id` = b.`creator_user_id`
             INNER JOIN `creators` c ON c.`id` = b.`creator_id`' . $where . '
             ORDER BY b.`id` DESC
             LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            $bindings
        );

        $items = array_map(fn (array $row): array => $this->presentListItem($row, $role), $rows);

        Response::paginated($items, $total, $pagination['page'], $pagination['per_page'], 'OK', [
            'tabs'  => $this->tabCounts($userId, $role),
            'role'  => $role,
        ]);
    }

    /** GET /bookings/{id} */
    public function show(Request $request): never
    {
        $booking = BookingService::detail($request->routeParamInt('id'), $this->userId($request));

        Response::success(['booking' => $this->presentDetail($booking, $this->userId($request))]);
    }

    /** POST /bookings — a brand sends a booking request. */
    public function store(Request $request): never
    {
        $data = $this->validate($request, [
            'creator_id'       => 'required|integer|exists:creators,id',
            'title'            => 'required|string|min:3|max:191',
            'description'      => 'nullable|string|max:5000',
            'campaign_type'    => 'nullable|string|max:80',
            'platform'         => 'required|in:instagram,youtube,tiktok,facebook,twitter,linkedin,multi',
            'deliverable_type' => 'nullable|in:post,reel,story,video,short,live,ugc,blog,review,bundle',
            'posts_count'      => 'nullable|integer|min_value:1|max_value:100',
            'amount'           => 'required|numeric|min_value:1',
            'package_id'       => 'nullable|integer',
            'delivery_due_at'  => 'nullable|datetime',
        ]);

        $booking = BookingService::create($this->userId($request), $data);

        Response::created(
            ['booking' => $this->presentDetail(BookingService::detail((int) $booking['id'], $this->userId($request)), $this->userId($request))],
            'Booking request sent. The creator will respond soon.'
        );
    }

    /** GET /bookings/quote — live price breakdown for the checkout screen. */
    public function quote(Request $request): never
    {
        $amount = $request->float('amount');

        if ($amount <= 0) {
            throw HttpException::unprocessable('Enter a budget to see the breakdown.');
        }

        $creatorId = $request->int('creator_id');
        $override  = null;

        if ($creatorId > 0) {
            $rate = Database::scalar('SELECT `commission_rate` FROM `creators` WHERE `id` = :id', ['id' => $creatorId]);
            $override = $rate === null ? null : (float) $rate;
        }

        $breakdown = Money::breakdown($amount, $override);

        Response::success([
            'currency'  => Money::defaultCurrency(),
            'symbol'    => Money::symbol(),
            'breakdown' => $breakdown,
            'formatted' => [
                'amount'       => Money::format($breakdown['amount']),
                'platform_fee' => Money::format($breakdown['platform_fee']),
                'tax'          => Money::format($breakdown['tax_amount']),
                'total'        => Money::format($breakdown['total_amount']),
                'you_receive'  => Money::format($breakdown['creator_earning']),
            ],
            'tax_label' => \Zuste\Core\Config::settingString('commission', 'tax_label', 'GST'),
        ]);
    }

    /** POST /bookings/{id}/accept */
    public function accept(Request $request): never
    {
        $booking = BookingService::accept($request->routeParamInt('id'), $this->userId($request));

        Response::success(['booking' => $booking], 'Booking accepted. The brand has been asked to complete payment.');
    }

    /** POST /bookings/{id}/reject */
    public function reject(Request $request): never
    {
        $data = $this->validate($request, ['reason' => 'nullable|string|max:500']);

        $booking = BookingService::reject(
            $request->routeParamInt('id'),
            $this->userId($request),
            (string) ($data['reason'] ?? '')
        );

        Response::success(['booking' => $booking], 'Booking declined.');
    }

    /** POST /bookings/{id}/counter-offer */
    public function counterOffer(Request $request): never
    {
        $data = $this->validate($request, [
            'amount'      => 'required|numeric|min_value:1',
            'posts_count' => 'nullable|integer|min_value:1|max_value:100',
            'message'     => 'nullable|string|max:1000',
        ]);

        $booking = BookingService::counterOffer(
            $request->routeParamInt('id'),
            $this->userId($request),
            (float) $data['amount'],
            (int) ($data['posts_count'] ?? 1),
            (string) ($data['message'] ?? '')
        );

        Response::success(['booking' => $booking], 'Counter offer sent.');
    }

    /** POST /bookings/{id}/accept-offer */
    public function acceptOffer(Request $request): never
    {
        $booking = BookingService::acceptOffer($request->routeParamInt('id'), $this->userId($request));

        Response::success(['booking' => $booking], 'Offer accepted.');
    }

    /**
     * POST /bookings/{id}/deliver
     *
     * Three shapes are accepted, all landing on the same rows:
     *   - `deliverables[]` — the full form, one object per item;
     *   - `content_url` (+ optional title/media_url/description) — one link;
     *   - `urls[]` + `note` — what the Flutter submission sheet posts.
     *
     * The last one used to fall through to "Add at least one deliverable link
     * or file", so submitting work from the app was impossible on every
     * booking regardless of its state — the request was refused before the
     * booking was even looked up.
     */
    public function deliver(Request $request): never
    {
        $deliverables = $request->array('deliverables');

        if ($deliverables === []) {
            // A single link, or the app's list of them — `content_url` first so
            // a client sending both keeps its primary link at the top.
            $links = array_merge(
                array_filter([$request->string('content_url')]),
                array_map('strval', $request->array('urls'))
            );

            $links = array_values(array_filter(
                array_map(static fn (string $url): string => mb_substr(trim($url), 0, 500), $links),
                static fn (string $url): bool => $url !== ''
            ));

            if ($links === []) {
                throw HttpException::unprocessable('Add at least one deliverable link or file.');
            }

            // `note` is what the app calls the description; `description` stays
            // supported for the clients that already send it.
            $note  = $request->string('description') ?: $request->string('note');
            $title = $request->string('title', 'Deliverable') ?: 'Deliverable';

            $deliverables = [];

            foreach ($links as $index => $url) {
                $deliverables[] = [
                    // Numbered only when there is more than one, so the single
                    // -link payload writes exactly the row it always did.
                    'title'       => count($links) > 1 ? $title . ' ' . ($index + 1) : $title,
                    'content_url' => $url,
                    'media_url'   => $index === 0 ? ($request->string('media_url') ?: null) : null,
                    'description' => $note !== '' ? mb_substr($note, 0, 1000) : null,
                ];
            }
        }

        $booking = BookingService::deliver(
            $request->routeParamInt('id'),
            $this->userId($request),
            array_slice($deliverables, 0, 20)
        );

        Response::success(['booking' => $booking], 'Deliverables submitted for review.');
    }

    /** POST /bookings/{id}/complete */
    public function complete(Request $request): never
    {
        $booking = BookingService::complete($request->routeParamInt('id'), $this->userId($request), 'brand');

        Response::success(['booking' => $booking], 'Booking completed and payment released to the creator.');
    }

    /** POST /bookings/{id}/cancel */
    public function cancel(Request $request): never
    {
        $data = $this->validate($request, ['reason' => 'required|string|min:3|max:500']);

        $user    = $this->user($request);
        $booking = BookingService::find($request->routeParamInt('id'));

        $side = (int) $booking['brand_user_id'] === (int) $user['id'] ? 'brand' : 'creator';

        $updated = BookingService::cancel(
            (int) $booking['id'],
            (int) $user['id'],
            (string) $data['reason'],
            $side
        );

        Response::success(['booking' => $updated], 'Booking cancelled.');
    }

    /** GET /bookings/{id}/coupons — public offers, priced against this booking. */
    public function coupons(Request $request): never
    {
        Response::success([
            'coupons' => CouponService::offersForBooking(
                $request->routeParamInt('id'),
                $this->userId($request)
            ),
        ]);
    }

    /**
     * POST /bookings/{id}/coupon — apply a discount code.
     *
     * Returns the whole repriced booking rather than just the discount, so the
     * checkout screen renders the new total from the server's own numbers
     * instead of doing the arithmetic itself and risking a mismatch with what
     * is actually charged.
     */
    public function applyCoupon(Request $request): never
    {
        $data = $this->validate($request, ['code' => 'required|string|max:32']);

        $userId = $this->userId($request);

        CouponService::applyToBooking($request->routeParamInt('id'), $userId, (string) $data['code']);

        $booking = BookingService::detail($request->routeParamInt('id'), $userId);

        Response::success(
            ['booking' => $this->presentDetail($booking, $userId)],
            sprintf('Coupon applied — you save %s.', Money::format(
                (float) $booking['discount_amount'],
                (string) $booking['currency']
            ))
        );
    }

    /** DELETE /bookings/{id}/coupon — drop the code and restore the full price. */
    public function removeCoupon(Request $request): never
    {
        $userId = $this->userId($request);

        CouponService::removeFromBooking($request->routeParamInt('id'), $userId);

        $booking = BookingService::detail($request->routeParamInt('id'), $userId);

        Response::success(
            ['booking' => $this->presentDetail($booking, $userId)],
            'Coupon removed.'
        );
    }

    /**
     * POST /bookings/{id}/request-revision — the brand sends work back.
     *
     * The middle option between approving and disputing. The app has offered
     * it on the deliverable-review screen since that screen was built and had
     * nowhere to post it.
     */
    public function requestRevision(Request $request): never
    {
        $data = $this->validate($request, ['note' => 'required|string|min:5|max:1000']);

        $booking = BookingService::requestRevision(
            $request->routeParamInt('id'),
            $this->userId($request),
            (string) $data['note']
        );

        Response::success(
            ['booking' => $booking],
            'Changes requested. The creator has been notified.'
        );
    }

    /** POST /bookings/{id}/dispute — escalate to the platform; escrow stays held. */
    public function dispute(Request $request): never
    {
        $data = $this->validate($request, [
            'reason' => 'required|in:not_delivered,poor_quality,late_delivery,payment_issue,scope_change,other',
            'detail' => 'nullable|string|max:2000',
            // The client uploads first and sends URLs, so nothing large passes
            // through this endpoint.
            'evidence_urls' => 'nullable|array',
        ]);

        $booking = BookingService::dispute(
            $request->routeParamInt('id'),
            $this->userId($request),
            (string) $data['reason'],
            (string) ($data['detail'] ?? ''),
            array_values(array_filter(
                array_map('strval', $request->array('evidence_urls')),
                static fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false
            ))
        );

        Response::created(
            ['booking' => $booking],
            'Dispute raised. Your payment stays in escrow until our team has reviewed it.'
        );
    }

    /** GET /bookings/{id}/cancellation-preview — what a cancellation would cost. */
    public function cancellationPreview(Request $request): never
    {
        $booking = BookingService::detail($request->routeParamInt('id'), $this->userId($request));

        $isBrand   = (int) $booking['brand_user_id'] === $this->userId($request);
        $total     = Money::round((float) $booking['total_amount']);
        $freeDays  = \Zuste\Core\Config::settingInt('booking', 'free_cancellation_days', 7);
        $feePercent = \Zuste\Core\Config::settingFloat('booking', 'late_cancellation_fee_percent', 25);

        $dueAt   = $booking['delivery_due_at'] !== null ? strtotime((string) $booking['delivery_due_at']) : false;
        $isLate  = $isBrand && $dueAt !== false && ($dueAt - time()) <= ($freeDays * 86400);
        $isPaid  = $booking['payment_status'] === 'paid';

        $fee    = ($isPaid && $isLate) ? Money::percentOf($total, $feePercent) : 0.0;
        $refund = $isPaid ? Money::subtract($total, $fee) : 0.0;

        Response::success([
            'is_paid'          => $isPaid,
            'total_paid'       => $total,
            'cancellation_fee' => $fee,
            'refund_amount'    => $refund,
            'is_late'          => $isLate,
            'free_window_days' => $freeDays,
            'formatted'        => [
                'total_paid'       => Money::format($total),
                'cancellation_fee' => Money::format($fee),
                'refund_amount'    => Money::format($refund),
            ],
            'message' => match (true) {
                !$isPaid => 'No payment has been made, so nothing will be charged.',
                $isLate  => sprintf(
                    'Cancelling now retains a %s%% late fee (%s). You will be refunded %s.',
                    rtrim(rtrim(number_format($feePercent, 2), '0'), '.'),
                    Money::format($fee),
                    Money::format($refund)
                ),
                default  => 'You will receive a full refund of ' . Money::format($refund) . '.',
            },
        ]);
    }

    /** GET /bookings/{id}/invoice */
    public function invoice(Request $request): never
    {
        $bookingId = $request->routeParamInt('id');
        $userId    = $this->userId($request);

        // Authorisation is enforced by detail().
        BookingService::detail($bookingId, $userId);

        $invoice = Database::selectOne(
            'SELECT * FROM `invoices` WHERE `booking_id` = :id AND `user_id` = :user_id ORDER BY `id` DESC LIMIT 1',
            ['id' => $bookingId, 'user_id' => $userId]
        );

        if ($invoice === null) {
            throw HttpException::notFound('No invoice has been issued for this booking yet.');
        }

        if ($request->string('format') === 'html') {
            Response::html(InvoiceService::renderHtml((int) $invoice['id'], $userId));
        }

        $invoice['line_items'] = json_decode((string) $invoice['line_items'], true) ?: [];

        Response::success(['invoice' => $invoice]);
    }

    // -----------------------------------------------------------------
    // Presenters
    // -----------------------------------------------------------------

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentListItem(array $row, string $role): array
    {
        $isCreatorView = $role === 'creator';

        return [
            'id'              => (int) $row['id'],
            'code'            => (string) $row['code'],
            'title'           => (string) $row['title'],
            'campaign_type'   => (string) $row['campaign_type'],
            'platform'        => (string) $row['platform'],
            'posts_count'     => (int) $row['posts_count'],
            'counterparty'    => [
                'name'        => $isCreatorView ? (string) ($row['company_name'] ?: $row['brand_name']) : (string) $row['creator_name'],
                'avatar_url'  => $isCreatorView ? $row['brand_avatar'] : $row['creator_avatar'],
                'handle'      => $isCreatorView ? null : (string) $row['creator_handle'],
                // A brand's tick is the admin-granted blue tick (users.has_blue_tick).
                'is_verified' => $isCreatorView ? (bool) ($row['brand_verified'] ?? false) : (bool) $row['creator_verified'],
            ],
            'amount'          => Money::round((float) ($isCreatorView ? $row['creator_earning'] : $row['total_amount'])),
            'amount_label'    => Money::formatShort((float) ($isCreatorView ? $row['creator_earning'] : $row['total_amount']), (string) $row['currency']),
            'currency'        => (string) $row['currency'],
            'status'          => (string) $row['status'],
            'status_label'    => $this->statusLabel((string) $row['status']),
            'status_tone'     => $this->statusTone((string) $row['status']),
            'payment_status'  => (string) $row['payment_status'],
            'requested_at'    => $row['requested_at'],
            'delivery_due_at' => $row['delivery_due_at'],
            'date_label'      => date('d M Y', strtotime((string) $row['created_at'])),
            'can_review'      => $row['status'] === 'completed'
                && (int) ($isCreatorView ? $row['creator_reviewed'] : $row['brand_reviewed']) === 0,
        ];
    }

    /**
     * @param  array<string,mixed> $booking
     * @return array<string,mixed>
     */
    private function presentDetail(array $booking, int $viewerId): array
    {
        $isBrand = (int) $booking['brand_user_id'] === $viewerId;

        $booking['viewer_role']  = $isBrand ? 'brand' : 'creator';

        // The other side of the booking, in the shape presentListItem() sends:
        // the app parses detail and list rows with the same model.
        $booking['counterparty'] = $isBrand
            ? [
                'name'        => (string) $booking['creator_display_name'],
                'avatar_url'  => $booking['creator_avatar'],
                'handle'      => (string) $booking['creator_handle'],
                'is_verified' => (bool) $booking['creator_verified'],
            ]
            : [
                'name'        => (string) ($booking['company_name'] ?: $booking['brand_name']),
                'avatar_url'  => $booking['brand_avatar'],
                'handle'      => null,
                'is_verified' => (bool) ($booking['brand_verified'] ?? false),
            ];

        $booking['status_label'] = $this->statusLabel((string) $booking['status']);
        $booking['status_tone']  = $this->statusTone((string) $booking['status']);

        $discount = (float) ($booking['discount_amount'] ?? 0);

        $booking['formatted'] = [
            'amount'       => Money::format((float) $booking['amount'], (string) $booking['currency']),
            'platform_fee' => Money::format((float) $booking['platform_fee'], (string) $booking['currency']),
            'tax'          => Money::format((float) $booking['tax_amount'], (string) $booking['currency']),
            // Signed, because it is rendered as a line in the order summary and
            // a bare amount next to "Coupon" reads as a charge, not a saving.
            'discount'     => '-' . Money::format($discount, (string) $booking['currency']),
            'total'        => Money::format((float) $booking['total_amount'], (string) $booking['currency']),
            'earning'      => Money::format((float) $booking['creator_earning'], (string) $booking['currency']),
            // What the creator gives up. `platform_fee` is the brand-side fee
            // and is usually zero, so the creator's breakdown read
            // "Platform commission − ₹0.00" above a payout that was clearly
            // smaller than the fee.
            'commission'   => Money::format((float) ($booking['commission_amount'] ?? 0), (string) $booking['currency']),
        ];

        // The code itself, so the app can show what was applied after a reload
        // rather than only within the session that applied it.
        $booking['coupon'] = $booking['coupon_id'] === null ? null : Database::selectOne(
            'SELECT `id`, `code`, `name` FROM `coupons` WHERE `id` = :id',
            ['id' => (int) $booking['coupon_id']]
        );

        // What the viewer is allowed to do right now — the app renders its
        // action bar straight from this, so the rules live in one place.
        $status = (string) $booking['status'];

        $booking['actions'] = [
            'can_accept'         => !$isBrand && in_array($status, ['pending', 'countered'], true),
            'can_reject'         => !$isBrand && in_array($status, ['pending', 'countered'], true),
            'can_counter'        => in_array($status, ['pending', 'countered'], true),
            'can_accept_offer'   => $status === 'countered',
            'can_pay'            => $isBrand && in_array($status, ['accepted', 'awaiting_payment'], true)
                                    && $booking['payment_status'] !== 'paid',
            'can_deliver'        => !$isBrand && in_array($status, ['confirmed', 'in_progress'], true),
            'can_complete'       => $isBrand && $status === 'delivered',
            'can_cancel'         => in_array($status, ['pending', 'countered', 'accepted', 'awaiting_payment', 'confirmed', 'in_progress'], true),
            'can_review'         => $status === 'completed'
                                    && (int) ($isBrand ? $booking['brand_reviewed'] : $booking['creator_reviewed']) === 0,
            'can_message'        => true,
            'can_view_invoice'   => $booking['invoice'] !== null,
        ];

        foreach ($booking['timeline'] as &$entry) {
            $entry['label'] = $this->statusLabel((string) $entry['to_status']);
        }
        unset($entry);

        foreach ($booking['deliverables'] as &$deliverable) {
            $decoded = json_decode((string) ($deliverable['metrics'] ?? '{}'), true);

            // (object) matters. `?: []` produced an empty PHP array, and
            // json_encode renders that as `[]` — a LIST where the contract
            // says object. A client that casts the field to a map then throws
            // on every booking whose deliverables have no metrics yet, which
            // is every freshly delivered booking. Casting to object makes the
            // empty case serialise as `{}` and the field's type stop changing
            // with its contents.
            $deliverable['metrics'] = is_array($decoded) && $decoded !== []
                ? $decoded
                : (object) [];
        }
        unset($deliverable);

        return $booking;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending'          => 'Pending',
            'countered'        => 'Counter offer',
            'accepted'         => 'Accepted',
            'rejected'         => 'Declined',
            'awaiting_payment' => 'Awaiting payment',
            'confirmed'        => 'Confirmed',
            'in_progress'      => 'In progress',
            'delivered'        => 'Delivered',
            'completed'        => 'Completed',
            'cancelled'        => 'Cancelled',
            'expired'          => 'Expired',
            'disputed'         => 'Disputed',
            'refunded'         => 'Refunded',
            default            => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /** Semantic tone the app maps onto the design system's status chips. */
    private function statusTone(string $status): string
    {
        return match ($status) {
            'pending', 'countered', 'awaiting_payment' => 'warning',
            'accepted', 'confirmed', 'in_progress', 'delivered' => 'info',
            'completed'                                 => 'success',
            'rejected', 'cancelled', 'expired', 'disputed', 'refunded' => 'error',
            default                                     => 'neutral',
        };
    }

    /** @return array<string,int> */
    private function tabCounts(int $userId, string $role): array
    {
        $column = $role === 'creator' ? 'creator_user_id' : 'brand_user_id';

        $rows = Database::select(
            'SELECT `status`, COUNT(*) AS `total` FROM `bookings`
             WHERE `' . $column . '` = :user_id AND `deleted_at` IS NULL
             GROUP BY `status`',
            ['user_id' => $userId]
        );

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[(string) $row['status']] = (int) $row['total'];
        }

        $counts = ['all' => array_sum($byStatus)];

        foreach (self::TAB_FILTERS as $tab => $statuses) {
            if ($tab === 'all') {
                continue;
            }

            $counts[$tab] = array_sum(array_map(
                static fn (string $status): int => $byStatus[$status] ?? 0,
                $statuses
            ));
        }

        return $counts;
    }
}

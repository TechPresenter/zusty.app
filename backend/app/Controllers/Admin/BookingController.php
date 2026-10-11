<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;
use Zuste\Services\ApprovalService;
use Zuste\Services\BookingService;
use Zuste\Services\CreatorService;
use Zuste\Services\DisputeService;
use Zuste\Services\ExportService;
use Zuste\Services\PaymentService;
use Zuste\Services\ReportService;

/**
 * Bookings, disputes, reviews and abuse reports.
 */
final class BookingController extends BaseAdminController
{
    private const SORTS = ['id', 'code', 'total_amount', 'status', 'created_at', 'completed_at'];

    /**
     * Backstop for exports, matching ResourceController. A genuinely huge
     * export belongs in a background job, not in a request that exhausts memory.
     */
    private const EXPORT_LIMIT = 10000;

    /** Export column => header, in the order they are written. */
    private const EXPORT_COLUMNS = [
        'code'            => 'Code',
        'title'           => 'Title',
        'brand_name'      => 'Brand',
        'creator_name'    => 'Creator',
        'platform'        => 'Platform',
        'amount'          => 'Amount',
        'platform_fee'    => 'Fee',
        'total_amount'    => 'Total',
        'creator_earning' => 'Creator earning',
        'status'          => 'Status',
        'payment_status'  => 'Payment',
        'created_at'      => 'Created',
    ];

    // =================================================================
    // Bookings
    // =================================================================

    /** GET /admin/bookings */
    public function index(Request $request): never
    {
        $this->authorize($request, 'bookings.view');

        $params   = $this->listParams($request, self::SORTS, 'id');
        $clauses  = ['b.`deleted_at` IS NULL'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = "(b.`code` LIKE :search ESCAPE '\\\\' OR b.`title` LIKE :search ESCAPE '\\\\'
                                    OR bu.`name` LIKE :search ESCAPE '\\\\' OR c.`display_name` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        foreach (['status' => 'b.`status`', 'payment_status' => 'b.`payment_status`', 'platform' => 'b.`platform`'] as $key => $column) {
            if ($request->filled($key)) {
                $clauses[]      = $column . ' = :' . $key;
                $bindings[$key] = $request->string($key);
            }
        }

        $this->applyDateRange($clauses, $bindings, 'b.`created_at`', $params['from'], $params['to']);

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $joins = ' INNER JOIN `users` bu ON bu.`id` = b.`brand_user_id`
                   INNER JOIN `creators` c ON c.`id` = b.`creator_id`';

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `bookings` b' . $joins . $where, $bindings);

        $select = 'SELECT b.`id`, b.`code`, b.`title`, b.`campaign_type`, b.`platform`, b.`posts_count`,
                    b.`amount`, b.`platform_fee`, b.`total_amount`, b.`creator_earning`, b.`currency`,
                    b.`status`, b.`payment_status`, b.`escrow_status`, b.`created_at`, b.`completed_at`,
                    COALESCE(bu.`company_name`, bu.`name`) AS `brand_name`, bu.`avatar_url` AS `brand_avatar`,
                    c.`display_name` AS `creator_name`, c.`handle` AS `creator_handle`,
                    cu.`avatar_url` AS `creator_avatar`,
                    (SELECT COUNT(*) FROM `booking_disputes` d WHERE d.`booking_id` = b.`id`
                       AND d.`status` IN ("open","under_review")) AS `open_disputes`
             FROM `bookings` b' . $joins . '
             INNER JOIN `users` cu ON cu.`id` = b.`creator_user_id`' . $where;

        $order = ' ORDER BY b.`' . $params['sort_by'] . '` ' . $params['sort_dir'];

        $rows = Database::select(
            $select . $order . ' LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $exportAs = strtolower($request->string('export'));

        if (in_array($exportAs, ['csv', 'xlsx', 'pdf'], true)) {
            $this->authorize($request, 'export.data');

            // Re-query WITHOUT the page window.
            //
            // `$rows` is one page — LIMIT per_page OFFSET offset — so exporting
            // it handed back the 25 rows on screen however many the filters
            // matched, with nothing in the file to say it was truncated. The
            // same $where and $bindings are reused, so the export is exactly
            // the filtered set the screen shows, just complete.
            $exportRows = Database::select(
                $select . $order . ' LIMIT ' . self::EXPORT_LIMIT,
                $bindings
            );

            $columns = array_keys(self::EXPORT_COLUMNS);
            $labels  = array_values(self::EXPORT_COLUMNS);

            // xlsx and pdf were rendered as buttons by the shared toolbar and
            // handled by nothing here, so both silently re-rendered the list
            // screen. They go through the same writers the resource screens use.
            if ($exportAs === 'csv') {
                $this->exportCsv('zuste-bookings', $labels, $exportRows, $columns);
            }

            $matrix = [];

            foreach ($exportRows as $row) {
                $line = [];

                foreach ($columns as $column) {
                    $value  = $row[$column] ?? '';
                    $line[] = is_scalar($value) || $value === null
                        ? (string) $value
                        : (string) json_encode($value);
                }

                $matrix[] = $line;
            }

            if ($exportAs === 'xlsx') {
                ExportService::xlsx('zuste-bookings', 'Bookings', $labels, $matrix);
            }

            ExportService::pdf(
                'Bookings',
                $labels,
                $matrix,
                'Exported ' . date('d M Y, g:i A') . ' — ' . count($matrix) . ' row(s)'
            );
        }

        $this->view($request, 'admin.bookings.index', [
            'pageTitle'    => 'Bookings',
            'pageSubtitle' => number_format($total) . ' bookings',
            'bookings'     => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
            'counts'       => $this->statusCounts(),
            // The same predicates the table is built from, so the summary cards
            // describe the list the operator is actually looking at.
            'totals'       => $this->periodTotals($clauses, $bindings, $joins),
        ]);
    }

    /** GET /admin/bookings/{id} */
    public function show(Request $request): never
    {
        $this->authorize($request, 'bookings.view');

        $bookingId = $request->routeParamInt('id');

        $booking = Database::selectOne(
            'SELECT b.*,
                    bu.`name` AS `brand_name`, bu.`email` AS `brand_email`, bu.`company_name`,
                    bu.`avatar_url` AS `brand_avatar`, bu.`phone` AS `brand_phone`,
                    cu.`name` AS `creator_account_name`, cu.`email` AS `creator_email`,
                    cu.`avatar_url` AS `creator_avatar`,
                    c.`display_name` AS `creator_name`, c.`handle` AS `creator_handle`,
                    c.`is_verified` AS `creator_verified`, c.`rating_avg` AS `creator_rating`
             FROM `bookings` b
             INNER JOIN `users` bu ON bu.`id` = b.`brand_user_id`
             INNER JOIN `users` cu ON cu.`id` = b.`creator_user_id`
             INNER JOIN `creators` c ON c.`id` = b.`creator_id`
             WHERE b.`id` = :id',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        $deliverables = Database::select(
            'SELECT * FROM `booking_deliverables` WHERE `booking_id` = :id ORDER BY `id`',
            ['id' => $bookingId]
        );

        foreach ($deliverables as &$deliverable) {
            $deliverable['metrics'] = json_decode((string) ($deliverable['metrics'] ?? '{}'), true) ?: [];
        }
        unset($deliverable);

        $this->view($request, 'admin.bookings.show', [
            'pageTitle'    => 'Booking #' . (string) $booking['code'],
            'pageSubtitle' => (string) $booking['title'],
            'booking'      => $booking,
            'timeline'     => Database::select(
                'SELECT * FROM `booking_status_history` WHERE `booking_id` = :id ORDER BY `id`',
                ['id' => $bookingId]
            ),
            'offers'       => Database::select(
                'SELECT * FROM `booking_offers` WHERE `booking_id` = :id ORDER BY `id`',
                ['id' => $bookingId]
            ),
            'deliverables' => $deliverables,
            'payments'     => Database::select(
                'SELECT * FROM `payments` WHERE `booking_id` = :id ORDER BY `id` DESC',
                ['id' => $bookingId]
            ),
            'refunds'      => Database::select(
                'SELECT * FROM `refunds` WHERE `booking_id` = :id ORDER BY `id` DESC',
                ['id' => $bookingId]
            ),
            'invoices'     => Database::select(
                'SELECT * FROM `invoices` WHERE `booking_id` = :id ORDER BY `id`',
                ['id' => $bookingId]
            ),
            'disputes'     => Database::select(
                'SELECT d.*, a.`name` AS `handler_name` FROM `booking_disputes` d
                 LEFT JOIN `admins` a ON a.`id` = d.`handled_by`
                 WHERE d.`booking_id` = :id ORDER BY d.`id` DESC',
                ['id' => $bookingId]
            ),
            'reviews'      => Database::select(
                'SELECT r.*, u.`name` AS `reviewer_name` FROM `reviews` r
                 INNER JOIN `users` u ON u.`id` = r.`reviewer_id`
                 WHERE r.`booking_id` = :id',
                ['id' => $bookingId]
            ),
            'messagesCount' => (int) Database::scalar(
                'SELECT COUNT(*) FROM `messages` m
                 INNER JOIN `conversations` cv ON cv.`id` = m.`conversation_id`
                 WHERE cv.`booking_id` = :id',
                ['id' => $bookingId]
            ),
        ]);
    }

    /** POST /admin/bookings/{id}/cancel */
    public function cancel(Request $request): never
    {
        $this->authorize($request, 'bookings.cancel');

        $data = $this->validate($request, ['reason' => 'required|string|min:5|max:500']);

        $bookingId = $request->routeParamInt('id');

        $booking = BookingService::cancel(
            $bookingId,
            $this->adminId($request),
            (string) $data['reason'],
            'admin'
        );

        $this->log($request, 'booking.cancelled', 'booking', $bookingId, 'Force-cancelled by admin.', ['reason' => $data['reason']]);

        $this->respond($request, 'Booking cancelled and any refund processed.', '/admin/bookings/' . $bookingId);
    }

    /** POST /admin/bookings/{id}/complete */
    public function complete(Request $request): never
    {
        $this->authorize($request, 'bookings.edit');

        $bookingId = $request->routeParamInt('id');

        // 'admin', not 'system': this is a named operator overriding the flow,
        // and the timeline the brand and the creator read must say so rather
        // than claim the auto-complete cron did it.
        BookingService::complete($bookingId, $this->adminId($request), 'admin');

        $this->log($request, 'booking.completed', 'booking', $bookingId, 'Completed manually by admin; payment released.');

        $this->respond($request, 'Booking completed and the creator has been paid.', '/admin/bookings/' . $bookingId);
    }

    /** POST /admin/bookings/{id}/refund */
    public function refund(Request $request): never
    {
        $this->authorize($request, 'payments.refund');

        $data = $this->validate($request, [
            'amount'      => 'required|numeric|min_value:1',
            'destination' => 'required|in:source,wallet',
            'reason'      => 'required|string|min:5|max:500',
        ]);

        $bookingId = $request->routeParamInt('id');
        $adminId   = $this->adminId($request);
        $reason    = (string) $data['reason'];

        $payment = Database::selectOne(
            "SELECT `id`, `reference`, `amount`, `amount_refunded` FROM `payments`
              WHERE `booking_id` = :id AND `status` IN ('captured', 'partially_refunded')
              ORDER BY `id` DESC LIMIT 1",
            ['id' => $bookingId]
        );

        if ($payment === null) {
            throw HttpException::notFound('No refundable payment found for this booking.');
        }

        // Segregation of duties, exactly as FinanceController::refundPayment
        // enforces it on the payments screen. The seeded "Large refund"
        // workflow was configurable in the panel and consulted by no code path
        // on this route, so any admin holding `payments.refund` could send back
        // any amount on their own from the booking screen.
        $parked = PaymentService::refundAwaitingApproval((int) $payment['id']);

        if ($parked !== null) {
            $approval = ApprovalService::existingFor('refund', (int) $parked['id']);
            $status   = $approval === null ? '' : (string) $approval['status'];

            if ($status === 'pending') {
                $this->respond(
                    $request,
                    'A refund of ' . Money::format((float) $parked['amount'])
                    . ' on this booking is already waiting for approval.',
                    '/admin/workflow/' . (int) $approval['id'],
                    [
                        'approval_id'      => (int) $approval['id'],
                        'refund_reference' => $parked['reference'],
                        'status'           => 'awaiting_approval',
                    ]
                );
            }

            if ($status === 'approved') {
                $settled = PaymentService::settleParkedRefund((int) $parked['id']);

                $this->log(
                    $request,
                    'booking.refunded',
                    'booking',
                    $bookingId,
                    'Refunded ' . Money::format((float) $settled['amount']),
                    [
                        'reference' => $settled['reference'],
                        'reason'    => 'Released after approval #' . (int) $approval['id'] . '.',
                    ]
                );

                $this->respond(
                    $request,
                    'Refund ' . (string) $settled['status'] . ' after approval.',
                    '/admin/bookings/' . $bookingId
                );
            }

            // Rejected, cancelled, or never raised: that refund will never be
            // paid, so close it out rather than leaving the payment blocked and
            // carry on with the fresh one being asked for here.
            PaymentService::abandonParkedRefund(
                (int) $parked['id'],
                'Approval ' . ($status === '' ? 'was never raised' : $status) . '.'
            );

            $this->log(
                $request,
                'payment.refund_abandoned',
                'booking',
                $bookingId,
                'Parked refund closed — approval ' . ($status === '' ? 'was never raised' : $status) . '.',
                ['reference' => $parked['reference']]
            );
        }

        // Test the threshold against the money that will actually move, not
        // whatever was typed into the box — PaymentService clamps it anyway.
        // Read after any dead parked refund was closed above, and net of
        // refunds still on their way back, so a refund Cashfree has accepted
        // but not yet confirmed cannot be paid a second time.
        $amount = min((float) $data['amount'], PaymentService::refundableBalance($payment));

        if ($amount <= 0) {
            $nothingLeft = 'There is nothing left to refund on this payment: the rest is already refunded or on its way back.';

            if ($request->expectsJson()) {
                throw HttpException::unprocessable($nothingLeft);
            }

            $this->back('error', $nothingLeft, '/admin/bookings/' . $bookingId);
        }

        if (ApprovalService::workflowFor('refund', $amount) !== null) {
            $refund = PaymentService::parkRefundForApproval(
                $bookingId,
                $amount,
                'other',
                'admin',
                $adminId,
                (string) $data['destination'],
                (int) $payment['id'],
                $reason
            );

            $approvalId = ApprovalService::open(
                'refund',
                (int) $refund['id'],
                (float) $refund['amount'],
                'Refund of ' . Money::format((float) $refund['amount']) . ' on booking '
                    . (string) Database::scalar('SELECT `code` FROM `bookings` WHERE `id` = :id', ['id' => $bookingId])
                    . ' awaiting approval.',
                $adminId
            );

            if ($approvalId !== null) {
                $this->log(
                    $request,
                    'booking.refund_approval_requested',
                    'booking',
                    $bookingId,
                    'Refund of ' . Money::format((float) $refund['amount']) . ' sent for approval.',
                    ['reference' => $refund['reference'], 'reason' => $reason, 'approval_id' => $approvalId]
                );

                $this->respond(
                    $request,
                    'This refund needs additional approval. It has been sent to the approvals queue.',
                    '/admin/workflow/' . $approvalId,
                    [
                        'approval_id'      => $approvalId,
                        'refund_reference' => $refund['reference'],
                        'status'           => 'awaiting_approval',
                    ]
                );
            }

            // The workflow was deactivated between the two calls, or has no
            // steps and could never complete. Neither is a reason to strand the
            // money, so settle the parked refund instead of leaving it pending.
            $settled = PaymentService::settleParkedRefund((int) $refund['id']);

            $this->log(
                $request,
                'booking.refunded',
                'booking',
                $bookingId,
                'Refunded ' . Money::format((float) $settled['amount']),
                ['reference' => $settled['reference'], 'reason' => $reason, 'destination' => $data['destination']]
            );

            $this->respond(
                $request,
                'Refund ' . (string) $settled['status'] . '.',
                '/admin/bookings/' . $bookingId
            );
        }

        $refund = PaymentService::refundBooking(
            $bookingId,
            $amount,
            'other',
            'admin',
            $adminId,
            (string) $data['destination'],
            (int) $payment['id']
        );

        $this->log(
            $request,
            'booking.refunded',
            'booking',
            $bookingId,
            'Refunded ' . Money::format((float) $refund['amount']),
            ['reference' => $refund['reference'], 'reason' => $reason, 'destination' => $data['destination']]
        );

        // Cashfree accepts a refund first and confirms it by webhook, so
        // `processing` is the normal answer for a refund to the original
        // method, not a rejection.
        $this->respond(
            $request,
            match ((string) $refund['status']) {
                'completed'  => 'Refund of ' . Money::format((float) $refund['amount']) . ' processed.',
                'processing' => 'Refund of ' . Money::format((float) $refund['amount'])
                    . ' sent to the original payment method. It completes when the gateway confirms it.',
                default      => 'Refund recorded but the gateway rejected it — check the payment log.',
            },
            '/admin/bookings/' . $bookingId
        );
    }

    // =================================================================
    // Disputes
    // =================================================================

    /** GET /admin/disputes */
    public function disputes(Request $request): never
    {
        $this->authorize($request, 'bookings.dispute');

        $params   = $this->listParams($request, ['id', 'created_at', 'status'], 'id');
        $clauses  = ['1 = 1'];
        $bindings = [];

        $status = $request->string('status', 'open');

        if ($status !== 'all') {
            $clauses[]          = 'd.`status` = :status';
            $bindings['status'] = $status;
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `booking_disputes` d INNER JOIN `bookings` b ON b.`id` = d.`booking_id`' . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT d.*, b.`code` AS `booking_code`, b.`title` AS `booking_title`,
                    b.`total_amount`, b.`currency`, b.`status` AS `booking_status`,
                    u.`name` AS `raised_by_name`, a.`name` AS `handler_name`
             FROM `booking_disputes` d
             INNER JOIN `bookings` b ON b.`id` = d.`booking_id`
             INNER JOIN `users` u ON u.`id` = d.`raised_by_id`
             LEFT JOIN `admins` a ON a.`id` = d.`handled_by`' . $where . '
             ORDER BY d.`' . $params['sort_by'] . '` ' . $params['sort_dir'] . '
             LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $this->view($request, 'admin.bookings.disputes', [
            'pageTitle'    => 'Disputes',
            'pageSubtitle' => number_format($total) . ' disputes',
            'disputes'     => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
        ]);
    }

    /** POST /admin/disputes/{id}/resolve */
    public function resolveDispute(Request $request): never
    {
        $this->authorize($request, 'bookings.dispute');

        $data = $this->validate($request, [
            'outcome'            => 'required|in:resolved_brand,resolved_creator,resolved_split,withdrawn',
            'refund_amount'      => 'nullable|numeric|min_value:0',
            'refund_destination' => 'nullable|in:source,wallet',
            'payout_amount'      => 'nullable|numeric|min_value:0',
            'note'               => 'required|string|min:10|max:2000',
        ]);

        $disputeId = $request->routeParamInt('id');

        $status = Database::scalar('SELECT `status` FROM `booking_disputes` WHERE `id` = :id', ['id' => $disputeId]);

        if ($status === null) {
            throw HttpException::notFound('Dispute not found.');
        }

        if (str_starts_with((string) $status, 'resolved') || $status === 'withdrawn') {
            $this->back('error', 'This dispute has already been resolved.');
        }

        // The brand's refund goes back to the original payment method unless
        // the admin picks the wallet. It used to be forced into the wallet,
        // which kept money the brand never chose to leave with us.
        //
        // DisputeService records the refund inside the resolution and sends
        // it only after that commits, re-checking the dispute and the booking
        // under their locks first. Where the booking lands (the previous
        // ternary force-completed every outcome, withdrawn included, and
        // wrote no history row) is decided there too.
        $resolved = DisputeService::resolve(
            $disputeId,
            (string) $data['outcome'],
            (float) ($data['refund_amount'] ?? 0),
            (string) ($data['refund_destination'] ?? '') === 'wallet' ? 'wallet' : 'source',
            (float) ($data['payout_amount'] ?? 0),
            (string) $data['note'],
            $this->adminId($request)
        );

        $refund    = $resolved['refund'];
        $bookingId = $resolved['booking_id'];

        $this->log(
            $request,
            'dispute.resolved',
            'booking',
            $bookingId,
            'Dispute resolved: ' . (string) $data['outcome'],
            [
                'refund'             => $resolved['refund_amount'],
                'payout'             => $resolved['payout_amount'],
                'refund_reference'   => $refund['reference'] ?? null,
                'refund_destination' => $refund['destination'] ?? null,
                'refund_status'      => $refund['status'] ?? null,
            ]
        );

        $payload = $refund === null ? [] : [
            'refund_reference'   => (string) $refund['reference'],
            'refund_destination' => (string) $refund['destination'],
            'refund_status'      => (string) $refund['status'],
        ];

        if ($refund === null) {
            $this->respond($request, 'Dispute resolved.', '/admin/disputes');
        }

        $amount = Money::format((float) $refund['amount'], (string) $refund['currency']);

        // A gateway refund used to be reported as "Dispute resolved." whatever
        // became of it, so a refused one left the brand unpaid with nobody
        // told. Each outcome is spelled out, and a failure is an error toast.
        if ((string) $refund['status'] === 'failed') {
            $message = 'Dispute resolved, but the refund of ' . $amount . ' (' . (string) $refund['reference'] . ') failed: '
                . trim((string) ($refund['failure_reason'] ?? 'the gateway refused it.'))
                . ' The payment is still refundable: issue the refund again from the booking (Admin actions → Issue refund)'
                . ' or Finance → Payments, to the Zusty wallet if the original method keeps refusing.';

            if ($request->expectsJson()) {
                Response::success($payload, $message);
            }

            $this->back('error', $message, '/admin/bookings/' . $bookingId);
        }

        $this->respond(
            $request,
            match (true) {
                (string) $refund['status'] === 'processing' => 'Dispute resolved. The refund of ' . $amount
                    . ' was sent to the original payment method; it completes when the gateway confirms it.',
                (string) $refund['destination'] === 'wallet' => 'Dispute resolved. ' . $amount . ' was refunded to the brand\'s Zusty wallet.',
                default => 'Dispute resolved. ' . $amount . ' was refunded to the original payment method.',
            },
            '/admin/disputes',
            $payload
        );
    }

    // =================================================================
    // Reviews
    // =================================================================

    /** GET /admin/reviews */
    public function reviews(Request $request): never
    {
        $this->authorize($request, 'reviews.view');

        $params   = $this->listParams($request, ['id', 'rating', 'created_at'], 'id');
        $clauses  = ['r.`deleted_at` IS NULL'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = "(r.`comment` LIKE :search ESCAPE '\\\\' OR r.`title` LIKE :search ESCAPE '\\\\'
                                    OR u.`name` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        if ($request->filled('rating')) {
            $clauses[]           = 'r.`rating` = :rating';
            $bindings['rating']  = $request->int('rating');
        }

        // One review, as linked from the abuse reports queue.
        if ($request->int('id') > 0) {
            $clauses[]      = 'r.`id` = :review_id';
            $bindings['review_id'] = $request->int('id');
        }

        if ($request->string('visibility') === 'hidden') {
            $clauses[] = 'r.`is_published` = 0';
        } elseif ($request->string('visibility') === 'flagged') {
            $clauses[] = 'r.`is_flagged` = 1';
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `reviews` r INNER JOIN `users` u ON u.`id` = r.`reviewer_id`' . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT r.*, u.`name` AS `reviewer_name`, u.`avatar_url` AS `reviewer_avatar`,
                    ru.`name` AS `reviewee_name`,
                    c.`display_name` AS `creator_name`, c.`handle` AS `creator_handle`,
                    b.`code` AS `booking_code`
             FROM `reviews` r
             INNER JOIN `users` u ON u.`id` = r.`reviewer_id`
             INNER JOIN `users` ru ON ru.`id` = r.`reviewee_id`
             LEFT JOIN `creators` c ON c.`id` = r.`creator_id`
             INNER JOIN `bookings` b ON b.`id` = r.`booking_id`' . $where . '
             ORDER BY r.`' . $params['sort_by'] . '` ' . $params['sort_dir'] . '
             LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $this->view($request, 'admin.reviews.index', [
            'pageTitle'    => 'Reviews',
            'pageSubtitle' => number_format($total) . ' reviews',
            'reviews'      => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
        ]);
    }

    /** POST /admin/reviews/{id}/moderate */
    public function moderateReview(Request $request): never
    {
        $this->authorize($request, 'reviews.moderate');

        $data = $this->validate($request, ['action' => 'required|in:publish,hide,delete,unflag']);

        $reviewId = $request->routeParamInt('id');

        $review = Database::selectOne('SELECT * FROM `reviews` WHERE `id` = :id', ['id' => $reviewId]);

        if ($review === null) {
            throw HttpException::notFound('Review not found.');
        }

        $updates = match ((string) $data['action']) {
            'publish' => ['is_published' => 1, 'is_flagged' => 0],
            'hide'    => ['is_published' => 0],
            'unflag'  => ['is_flagged' => 0],
            'delete'  => ['deleted_at' => date('Y-m-d H:i:s'), 'is_published' => 0],
        };

        $updates['moderated_by'] = $this->adminId($request);
        $updates['moderated_at'] = date('Y-m-d H:i:s');

        Database::update('reviews', $updates, ['id' => $reviewId]);

        // A hidden or deleted review must stop counting toward the rating.
        if ($review['creator_id'] !== null) {
            CreatorService::refreshStatistics((int) $review['creator_id']);
        }

        $this->log($request, 'review.' . (string) $data['action'], 'review', $reviewId, 'Review moderated.');

        $this->respond($request, 'Review ' . (string) $data['action'] . 'd.', '/admin/reviews');
    }

    // =================================================================
    // Reports
    // =================================================================

    /** GET /admin/reports */
    public function reports(Request $request): never
    {
        $this->authorize($request, 'reports.view');

        $params   = $this->listParams($request, ['id', 'created_at', 'status'], 'id');
        $clauses  = ['1 = 1'];
        $bindings = [];

        $status = $request->string('status', 'open');

        if ($status !== 'all') {
            $clauses[]          = 'rp.`status` = :status';
            $bindings['status'] = $status;
        }

        if ($request->filled('reportable_type')) {
            $clauses[]        = 'rp.`reportable_type` = :type';
            $bindings['type'] = $request->string('reportable_type');
        }

        if ($request->filled('reason')) {
            $clauses[]          = 'rp.`reason` = :reason';
            $bindings['reason'] = $request->string('reason');
        }

        // Every report about one account, as linked from its user page.
        if ($request->int('reported_user_id') > 0) {
            $clauses[]            = 'rp.`reported_user_id` = :reported';
            $bindings['reported'] = $request->int('reported_user_id');
        }

        if ($params['search'] !== '') {
            $clauses[]          = "(rp.`subject_excerpt` LIKE :search ESCAPE '\\\\' OR rp.`description` LIKE :search ESCAPE '\\\\'
                                    OR u.`name` LIKE :search ESCAPE '\\\\' OR ru.`name` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `reports` rp
             INNER JOIN `users` u ON u.`id` = rp.`reporter_id`
             LEFT JOIN `users` ru ON ru.`id` = rp.`reported_user_id`' . $where,
            $bindings
        );

        // The reported account, its creator profile (where a portfolio item
        // links to), how many people have blocked it and how many reports
        // about it are still open: what a moderator weighs first.
        $rows = Database::select(
            "SELECT rp.*, u.`name` AS `reporter_name`, u.`avatar_url` AS `reporter_avatar`,
                    a.`name` AS `handler_name`,
                    ru.`name` AS `reported_name`, ru.`status` AS `reported_status`,
                    rc.`id` AS `reported_creator_id`,
                    (SELECT COUNT(*) FROM `user_blocks` ub WHERE ub.`blocked_id` = rp.`reported_user_id`) AS `reported_blocked_by`,
                    (SELECT COUNT(*) FROM `reports` ro
                      WHERE ro.`reported_user_id` = rp.`reported_user_id` AND ro.`status` IN ('open','investigating')) AS `reported_open_reports`
             FROM `reports` rp
             INNER JOIN `users` u ON u.`id` = rp.`reporter_id`
             LEFT JOIN `users` ru ON ru.`id` = rp.`reported_user_id`
             LEFT JOIN `creators` rc ON rc.`user_id` = rp.`reported_user_id` AND rc.`deleted_at` IS NULL
             LEFT JOIN `admins` a ON a.`id` = rp.`handled_by`" . $where . '
             ORDER BY rp.`' . $params['sort_by'] . '` ' . $params['sort_dir'] . '
             LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $this->view($request, 'admin.reports.index', [
            'pageTitle'    => 'Abuse reports',
            'pageSubtitle' => number_format($total) . ' reports',
            'reports'      => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
        ]);
    }

    /** POST /admin/reports/{id}/handle */
    public function handleReport(Request $request): never
    {
        $this->authorize($request, 'reports.handle');

        $data = $this->validate($request, [
            'status' => 'required|in:investigating,resolved,dismissed',
            'note'   => 'nullable|string|max:1000',
        ]);

        $reportId = $request->routeParamInt('id');

        $updated = Database::update('reports', [
            'status'          => (string) $data['status'],
            'resolution_note' => $data['note'] ?? null,
            'handled_by'      => $this->adminId($request),
            'handled_at'      => date('Y-m-d H:i:s'),
        ], ['id' => $reportId]);

        if ($updated === 0) {
            throw HttpException::notFound('Report not found.');
        }

        $this->log($request, 'report.' . (string) $data['status'], 'report', $reportId, 'Report handled.', ['note' => $data['note'] ?? null]);

        $this->respond($request, 'Report marked as ' . (string) $data['status'] . '.', '/admin/reports');
    }

    /**
     * POST /admin/reports/{id}/take-down
     *
     * Removes the reported message, brief, review or portfolio item and
     * resolves every open report on it (ReportService::takeDown).
     */
    public function takeDownReport(Request $request): never
    {
        $this->authorize($request, 'reports.handle');

        $data = $this->validate($request, [
            'note' => 'nullable|string|max:900',
        ]);

        $reportId = $request->routeParamInt('id');
        $result   = ReportService::takeDown($reportId, $this->adminId($request), (string) ($data['note'] ?? ''));

        $this->log(
            $request,
            'report.take_down',
            'report',
            $reportId,
            $result['label'] . '.',
            ['subject_type' => $result['type'], 'resolved' => $result['resolved'], 'note' => $data['note'] ?? null]
        );

        $this->respond(
            $request,
            $result['label'] . '. ' . $result['resolved'] . ' report' . ($result['resolved'] === 1 ? '' : 's') . ' resolved.',
            '/admin/reports',
            $result
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @return array<string,int> */
    private function statusCounts(): array
    {
        $rows = Database::select(
            'SELECT `status`, COUNT(*) AS `total` FROM `bookings` WHERE `deleted_at` IS NULL GROUP BY `status`'
        );

        $counts = ['all' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
            $counts['all']                  += (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Totals for the rows the list is currently showing.
     *
     * Takes the caller's assembled predicates rather than rebuilding its own
     * from the date range: the cards used to ignore status, payment status,
     * platform and search entirely, so filtering to Cancelled still displayed
     * whole-platform revenue and read as ₹1.6 crore of cancelled business.
     *
     * @param  list<string>        $clauses  WHERE fragments, `b.`-qualified
     * @param  array<string,mixed> $bindings
     * @return array<string,float>
     */
    private function periodTotals(array $clauses, array $bindings, string $joins): array
    {
        $row = Database::selectOne(
            'SELECT COALESCE(SUM(b.`total_amount`), 0) AS `gross`,
                    COALESCE(SUM(b.`platform_fee`), 0) AS `fees`,
                    COALESCE(SUM(b.`commission_amount`), 0) AS `commission`,
                    COALESCE(SUM(b.`creator_earning`), 0) AS `payouts`
             FROM `bookings` b' . $joins . ' WHERE ' . implode(' AND ', $clauses),
            $bindings
        ) ?? [];

        return [
            'gross'      => Money::round((float) ($row['gross'] ?? 0)),
            'fees'       => Money::round((float) ($row['fees'] ?? 0)),
            'commission' => Money::round((float) ($row['commission'] ?? 0)),
            'payouts'    => Money::round((float) ($row['payouts'] ?? 0)),
        ];
    }
}

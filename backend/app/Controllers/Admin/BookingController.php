<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;
use Zuste\Services\ApprovalService;
use Zuste\Services\BookingService;
use Zuste\Services\CreatorService;
use Zuste\Services\ExportService;
use Zuste\Services\PaymentService;
use Zuste\Services\WalletService;

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

        $this->log($request, 'booking.completed', 'booking', $bookingId, 'Completed manually by admin; escrow released.');

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

        // Test the threshold against the money that will actually move, not
        // whatever was typed into the box — PaymentService clamps it anyway.
        $amount = min(
            (float) $data['amount'],
            Money::subtract((float) $payment['amount'], (float) $payment['amount_refunded'])
        );

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

        $this->respond(
            $request,
            $refund['status'] === 'completed'
                ? 'Refund of ' . Money::format((float) $refund['amount']) . ' processed.'
                : 'Refund recorded but the gateway rejected it — check the payment log.',
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
            'outcome'       => 'required|in:resolved_brand,resolved_creator,resolved_split,withdrawn',
            'refund_amount' => 'nullable|numeric|min_value:0',
            'payout_amount' => 'nullable|numeric|min_value:0',
            'note'          => 'required|string|min:10|max:2000',
        ]);

        $disputeId = $request->routeParamInt('id');

        $dispute = Database::selectOne(
            'SELECT d.*, b.`total_amount`, b.`creator_earning`, b.`code`, b.`creator_user_id`
             FROM `booking_disputes` d
             INNER JOIN `bookings` b ON b.`id` = d.`booking_id`
             WHERE d.`id` = :id',
            ['id' => $disputeId]
        );

        if ($dispute === null) {
            throw HttpException::notFound('Dispute not found.');
        }

        if (str_starts_with((string) $dispute['status'], 'resolved') || $dispute['status'] === 'withdrawn') {
            $this->back('error', 'This dispute has already been resolved.');
        }

        $refundAmount = Money::clamp((float) ($data['refund_amount'] ?? 0), 0.0, (float) $dispute['total_amount']);
        $payoutAmount = Money::clamp((float) ($data['payout_amount'] ?? 0), 0.0, (float) $dispute['creator_earning']);

        $adminId   = $this->adminId($request);
        $bookingId = (int) $dispute['booking_id'];

        Database::transaction(static function () use ($dispute, $disputeId, $bookingId, $data, $refundAmount, $payoutAmount, $adminId): void {
            if ($refundAmount > 0) {
                PaymentService::refundBooking($bookingId, $refundAmount, 'dispute', 'admin', $adminId, 'wallet');
            }

            if ($payoutAmount > 0) {
                WalletService::credit((int) $dispute['creator_user_id'], $payoutAmount, [
                    'category'          => 'booking_earning',
                    'description'       => 'Dispute settlement for #' . (string) $dispute['code'],
                    'reference_type'    => 'booking',
                    'reference_id'      => $bookingId,
                    'idempotency_key'   => 'dispute-payout:' . $disputeId,
                    'performed_by_type' => 'admin',
                    'performed_by_id'   => $adminId,
                ]);
            }

            Database::update('booking_disputes', [
                'status'          => (string) $data['outcome'],
                'refund_amount'   => $refundAmount,
                'payout_amount'   => $payoutAmount,
                'resolution_note' => (string) $data['note'],
                'handled_by'      => $adminId,
                'resolved_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $disputeId]);

            // Where the booking lands depends on the outcome. The previous
            // ternary had two identical branches, so every outcome — including
            // a withdrawn dispute — force-completed the booking. That revived
            // cancelled and unpaid bookings as "completed collaborations with
            // released escrow", and wrote no status-history row, so the ledger
            // and the timeline disagreed with the booking itself.
            $current = (string) Database::scalar(
                'SELECT `status` FROM `bookings` WHERE `id` = :id',
                ['id' => $bookingId]
            );

            $target = match ((string) $data['outcome']) {
                // Nothing was decided — put the booking back where it was
                // before the dispute, read from its own status history.
                'withdrawn' => BookingService::statusBeforeDispute($bookingId),
                // A full refund ends the booking as refunded, not completed.
                default     => $refundAmount > 0 && $payoutAmount <= 0 ? 'refunded' : 'completed',
            };

            // Only move if the state machine allows it; a booking that is not
            // in `disputed` must not be dragged into a terminal state by a
            // dispute record that no longer matches it.
            if ($current === $target) {
                return;
            }

            if (!BookingService::canTransition($current, $target)) {
                throw HttpException::conflict(
                    'This booking is ' . $current . ' and cannot be moved to ' . $target . '. '
                    . 'Resolve the booking itself first.'
                );
            }

            BookingService::forceStatus(
                $bookingId,
                $target,
                'admin',
                $adminId,
                'Dispute resolved: ' . (string) $data['outcome'],
                [
                    'escrow_status' => $refundAmount > 0 ? 'refunded' : 'released',
                    'completed_at'  => $target === 'completed' ? date('Y-m-d H:i:s') : null,
                ]
            );
        });

        $this->log(
            $request,
            'dispute.resolved',
            'booking',
            $bookingId,
            'Dispute resolved: ' . (string) $data['outcome'],
            ['refund' => $refundAmount, 'payout' => $payoutAmount]
        );

        $this->respond($request, 'Dispute resolved.', '/admin/disputes');
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

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `reports` rp INNER JOIN `users` u ON u.`id` = rp.`reporter_id`' . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT rp.*, u.`name` AS `reporter_name`, u.`avatar_url` AS `reporter_avatar`,
                    a.`name` AS `handler_name`
             FROM `reports` rp
             INNER JOIN `users` u ON u.`id` = rp.`reporter_id`
             LEFT JOIN `admins` a ON a.`id` = rp.`handled_by`' . $where . '
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

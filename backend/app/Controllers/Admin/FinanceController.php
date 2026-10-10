<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;
use Zuste\Services\ApprovalService;
use Zuste\Services\NotificationService;
use Zuste\Services\PaymentService;
use Zuste\Services\WalletService;

/**
 * Payments, refunds, wallets, payouts and settlements.
 *
 * Everything that moves money goes through WalletService or PaymentService —
 * this controller only decides *whether* it should move, and records why.
 */
final class FinanceController extends BaseAdminController
{
    /**
     * Ceiling on an export, so one click can never try to buffer a whole table.
     * Well above any realistic finance filter, and far above the 25-row page
     * these exports used to be limited to.
     */
    private const EXPORT_LIMIT = 50000;

    // =================================================================
    // Payments
    // =================================================================

    /** GET /admin/payments */
    public function payments(Request $request): never
    {
        $this->authorize($request, 'payments.view');

        $params   = $this->listParams($request, ['id', 'amount', 'status', 'created_at', 'paid_at'], 'id');
        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = "(p.`reference` LIKE :search ESCAPE '\\\\'
                                    OR p.`gateway_payment_id` LIKE :search ESCAPE '\\\\'
                                    OR p.`gateway_order_id` LIKE :search ESCAPE '\\\\'
                                    OR b.`code` LIKE :search ESCAPE '\\\\'
                                    OR u.`name` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        foreach (['status' => 'p.`status`', 'gateway' => 'p.`gateway`', 'purpose' => 'p.`purpose`'] as $key => $column) {
            if ($request->filled($key)) {
                $clauses[]      = $column . ' = :' . $key;
                $bindings[$key] = $request->string($key);
            }
        }

        $this->applyDateRange($clauses, $bindings, 'p.`created_at`', $params['from'], $params['to']);

        $where = ' WHERE ' . implode(' AND ', $clauses);
        $joins = ' INNER JOIN `users` u ON u.`id` = p.`user_id` LEFT JOIN `bookings` b ON b.`id` = p.`booking_id`';

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `payments` p' . $joins . $where, $bindings);

        // `refund_in_flight`: refunds recorded or accepted by the gateway but
        // not yet completed. They are not in `amount_refunded` yet, but they
        // are no longer refundable either (PaymentService::refundableBalance).
        $listSql = 'SELECT p.*, u.`name` AS `user_name`, u.`email` AS `user_email`,
                    b.`code` AS `booking_code`, b.`title` AS `booking_title`,
                    (SELECT COALESCE(SUM(r.`amount`), 0) FROM `refunds` r
                      WHERE r.`payment_id` = p.`id` AND r.`status` IN (\'pending\', \'processing\')) AS `refund_in_flight`
             FROM `payments` p' . $joins . $where . '
             ORDER BY p.`' . $params['sort_by'] . '` ' . $params['sort_dir'];

        $rows = Database::select(
            $listSql . ' LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $summary = Database::selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN p.`status` = 'captured' THEN p.`amount` ELSE 0 END), 0) AS `captured`,
                COALESCE(SUM(p.`amount_refunded`), 0) AS `refunded`,
                SUM(CASE WHEN p.`status` = 'failed' THEN 1 ELSE 0 END) AS `failed_count`,
                COUNT(*) AS `total_count`
             FROM `payments` p" . $joins . $where,
            $bindings
        ) ?? [];

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $this->exportCsv(
                'zuste-payments',
                ['Reference', 'User', 'Booking', 'Purpose', 'Gateway', 'Method', 'Amount', 'Refunded', 'Status', 'Paid at'],
                $this->exportRows($listSql, $bindings),
                ['reference', 'user_name', 'booking_code', 'purpose', 'gateway', 'method', 'amount', 'amount_refunded', 'status', 'paid_at']
            );
        }

        $this->view($request, 'admin.finance.payments', [
            'pageTitle'    => 'Payments',
            'pageSubtitle' => number_format($total) . ' transactions',
            'payments'     => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
            'summary'      => $summary,
        ]);
    }

    /** POST /admin/payments/{id}/refund */
    public function refundPayment(Request $request): never
    {
        $this->authorize($request, 'payments.refund');

        $data = $this->validate($request, [
            'amount'      => 'required|numeric|min_value:1',
            'destination' => 'required|in:source,wallet',
            'reason'      => 'required|string|min:5|max:500',
        ]);

        $paymentId = $request->routeParamInt('id');

        $payment = Database::selectOne('SELECT * FROM `payments` WHERE `id` = :id', ['id' => $paymentId]);

        if ($payment === null) {
            throw HttpException::notFound('Payment not found.');
        }

        if ($payment['status'] !== 'captured' && $payment['status'] !== 'partially_refunded') {
            $this->back('error', 'Only a captured payment can be refunded.');
        }

        if ($payment['booking_id'] === null) {
            // A wallet top-up refund debits the wallet and returns to source.
            $this->back('error', 'Refund wallet top-ups from the user\'s wallet screen instead.');
        }

        $bookingId = (int) $payment['booking_id'];
        $adminId   = $this->adminId($request);
        $reason    = (string) $data['reason'];

        // Segregation of duties — the same rule approveWithdrawal enforces on
        // payouts. The seeded "Large refund" workflow, its threshold and its
        // step were configurable in the admin panel and consulted by no code
        // path at all, so any admin holding `payments.refund` could move any
        // amount on their own.
        $parked = PaymentService::refundAwaitingApproval($paymentId);

        if ($parked !== null) {
            // Returns only when the parked refund is dead and a fresh one may
            // be raised; otherwise it responds and never comes back.
            $this->resumeParkedRefund($request, $paymentId, $parked);
        }

        // The amount that will actually move, so the approval threshold is
        // tested against the real figure rather than whatever was typed. Net
        // of refunds still on their way back (a Cashfree refund stays
        // `processing` for days), which can no longer be refunded again.
        $amount = min((float) $data['amount'], PaymentService::refundableBalance($payment));

        if ($amount <= 0) {
            $nothingLeft = 'There is nothing left to refund on this payment: the rest is already refunded or on its way back.';

            if ($request->expectsJson()) {
                throw HttpException::unprocessable($nothingLeft);
            }

            $this->back('error', $nothingLeft);
        }

        if (ApprovalService::workflowFor('refund', $amount) !== null) {
            $refund = PaymentService::parkRefundForApproval(
                $bookingId,
                $amount,
                'other',
                'admin',
                $adminId,
                (string) $data['destination'],
                $paymentId,
                $reason
            );

            $approvalId = ApprovalService::open(
                'refund',
                (int) $refund['id'],
                (float) $refund['amount'],
                'Refund of ' . Money::format((float) $refund['amount']) . ' on payment '
                    . (string) $payment['reference'] . ' awaiting approval.',
                $adminId
            );

            if ($approvalId !== null) {
                $this->log(
                    $request,
                    'payment.refund_approval_requested',
                    'payment',
                    $paymentId,
                    'Refund of ' . Money::format((float) $refund['amount']) . ' sent for approval.',
                    ['reference' => $refund['reference'], 'reason' => $reason, 'approval_id' => $approvalId]
                );

                $this->respond(
                    $request,
                    'This refund needs additional approval. It has been sent to the approvals queue.',
                    // The approval detail screen is /admin/workflow/{id}.
                    '/admin/workflow/' . $approvalId,
                    ['approval_id' => $approvalId, 'refund_reference' => $refund['reference'], 'status' => 'awaiting_approval']
                );
            }

            // No workflow after all — it was deactivated between the two calls,
            // or it has no steps and could never complete. Neither is a reason
            // to strand the money, so pay it out here instead of parking it.
            $this->finishRefund($request, $paymentId, PaymentService::settleParkedRefund((int) $refund['id']), $reason);
        }

        $refund = PaymentService::refundBooking(
            $bookingId,
            $amount,
            'other',
            'admin',
            $adminId,
            (string) $data['destination'],
            $paymentId
        );

        $this->finishRefund($request, $paymentId, $refund, $reason);
    }

    /**
     * Deal with a refund on this payment that is already parked for approval.
     *
     * Returns only when that refund is dead and the caller may raise a fresh
     * one; every other outcome responds and does not come back.
     *
     * @param array<string,mixed> $parked
     */
    private function resumeParkedRefund(Request $request, int $paymentId, array $parked): void
    {
        $approval = ApprovalService::existingFor('refund', (int) $parked['id']);
        $status   = $approval === null ? '' : (string) $approval['status'];

        if ($status === 'pending') {
            $this->respond(
                $request,
                'A refund of ' . Money::format((float) $parked['amount'])
                . ' on this payment is already waiting for approval.',
                '/admin/workflow/' . (int) $approval['id'],
                ['approval_id' => (int) $approval['id'], 'refund_reference' => $parked['reference'], 'status' => 'awaiting_approval']
            );
        }

        if ($status === 'approved') {
            $this->finishRefund(
                $request,
                $paymentId,
                PaymentService::settleParkedRefund((int) $parked['id']),
                'Released after approval #' . (int) $approval['id'] . '.'
            );
        }

        // Rejected, cancelled, or the approval was never raised: this refund
        // will never be paid, so close it out rather than leaving the payment
        // blocked, and let the caller raise a new one.
        PaymentService::abandonParkedRefund(
            (int) $parked['id'],
            'Approval ' . ($status === '' ? 'was never raised' : $status) . '.'
        );

        $this->log(
            $request,
            'payment.refund_abandoned',
            'payment',
            $paymentId,
            'Parked refund closed — approval ' . ($status === '' ? 'was never raised' : $status) . '.',
            ['reference' => $parked['reference']]
        );
    }

    /**
     * Record and announce a refund that has been through the money movement.
     *
     * @param array<string,mixed> $refund
     */
    private function finishRefund(Request $request, int $paymentId, array $refund, string $reason): never
    {
        $this->log(
            $request,
            'payment.refunded',
            'payment',
            $paymentId,
            'Refunded ' . Money::format((float) $refund['amount']),
            ['reference' => $refund['reference'], 'reason' => $reason]
        );

        // Deliberately no payload: this is the response shape the panel and any
        // existing caller already handle.
        $this->respond($request, 'Refund ' . (string) $refund['status'] . '.', '/admin/payments');
    }

    /** GET /admin/refunds */
    public function refunds(Request $request): never
    {
        $this->authorize($request, 'payments.view');

        $params  = $this->listParams($request, ['id', 'amount', 'created_at'], 'id');
        $clauses = ['1 = 1'];
        $bindings = [];

        if ($request->filled('status')) {
            $clauses[]          = 'r.`status` = :status';
            $bindings['status'] = $request->string('status');
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `refunds` r INNER JOIN `users` u ON u.`id` = r.`user_id`' . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT r.*, u.`name` AS `user_name`, b.`code` AS `booking_code`, p.`reference` AS `payment_reference`
             FROM `refunds` r
             INNER JOIN `users` u ON u.`id` = r.`user_id`
             INNER JOIN `payments` p ON p.`id` = r.`payment_id`
             LEFT JOIN `bookings` b ON b.`id` = r.`booking_id`' . $where . '
             ORDER BY r.`' . $params['sort_by'] . '` ' . $params['sort_dir'] . '
             LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $this->view($request, 'admin.finance.refunds', [
            'pageTitle'    => 'Refunds',
            'pageSubtitle' => number_format($total) . ' refunds',
            'refunds'      => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
        ]);
    }

    // =================================================================
    // Wallets
    // =================================================================

    /** GET /admin/wallets */
    public function wallets(Request $request): never
    {
        $this->authorize($request, 'wallet.view');

        $params   = $this->listParams($request, ['id', 'balance', 'locked_balance', 'last_activity_at'], 'balance');
        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = "(u.`name` LIKE :search ESCAPE '\\\\' OR u.`email` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        if ($request->bool('frozen_only')) {
            $clauses[] = 'w.`is_frozen` = 1';
        }

        if ($request->bool('with_balance')) {
            $clauses[] = '(w.`balance` > 0 OR w.`locked_balance` > 0)';
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `wallets` w INNER JOIN `users` u ON u.`id` = w.`user_id`' . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT w.*, u.`name` AS `user_name`, u.`email`, u.`account_type`, u.`avatar_url`, u.`kyc_status`
             FROM `wallets` w
             INNER JOIN `users` u ON u.`id` = w.`user_id`' . $where . '
             ORDER BY w.`' . $params['sort_by'] . '` ' . $params['sort_dir'] . '
             LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $totals = Database::selectOne(
            'SELECT COALESCE(SUM(w.`balance`), 0) AS `available`,
                    COALESCE(SUM(w.`locked_balance`), 0) AS `locked`,
                    COALESCE(SUM(w.`lifetime_credit`), 0) AS `lifetime_credit`,
                    COALESCE(SUM(w.`lifetime_debit`), 0) AS `lifetime_debit`
             FROM `wallets` w INNER JOIN `users` u ON u.`id` = w.`user_id`' . $where,
            $bindings
        ) ?? [];

        $this->view($request, 'admin.finance.wallets', [
            'pageTitle'    => 'Wallets',
            'pageSubtitle' => number_format($total) . ' wallets',
            'wallets'      => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
            'totals'       => $totals,
        ]);
    }

    /** POST /admin/wallets/{userId}/freeze */
    public function freezeWallet(Request $request): never
    {
        $this->authorize($request, 'wallet.adjust');

        $data = $this->validate($request, [
            'is_frozen' => 'required|boolean',
            'reason'    => 'nullable|string|max:255',
        ]);

        $userId = $request->routeParamInt('userId');
        $frozen = (bool) $data['is_frozen'];

        if ($frozen && empty($data['reason'])) {
            $this->back('error', 'Give a reason when freezing a wallet.');
        }

        // Same guard reconcileWallet applies. Without it a mistyped id updated
        // nothing, reported "Wallet frozen." and wrote an activity_logs entry
        // asserting a control was in place that was not — and activity_logs is
        // immutable, so the false entry could never be retracted.
        $this->requireUser($userId);

        if (!Database::exists('wallets', ['user_id' => $userId])) {
            $this->back('error', 'This account does not have a wallet yet, so there is nothing to change.');
        }

        Database::execute(
            'UPDATE `wallets` SET `is_frozen` = :frozen, `frozen_reason` = :reason WHERE `user_id` = :user_id',
            ['frozen' => $frozen ? 1 : 0, 'reason' => $frozen ? (string) $data['reason'] : null, 'user_id' => $userId]
        );

        $this->log(
            $request,
            $frozen ? 'wallet.frozen' : 'wallet.unfrozen',
            'user',
            $userId,
            $frozen ? 'Wallet frozen.' : 'Wallet unfrozen.',
            ['reason' => $data['reason'] ?? null]
        );

        $this->respond($request, $frozen ? 'Wallet frozen.' : 'Wallet unfrozen.');
    }

    /** GET /admin/wallets/{userId}/reconcile */
    public function reconcileWallet(Request $request): never
    {
        $this->authorize($request, 'wallet.view');

        $userId = $request->routeParamInt('userId');

        $this->requireUser($userId);

        $result = WalletService::reconcile($userId);

        $this->log(
            $request,
            'wallet.reconciled',
            'user',
            $userId,
            $result['matches'] ? 'Ledger matches the wallet balance.' : 'LEDGER DRIFT DETECTED.',
            $result
        );

        $this->respond(
            $request,
            $result['matches']
                ? 'Ledger reconciles: ' . Money::format($result['stored']) . '.'
                : 'Drift found — stored ' . Money::format($result['stored']) . ' vs ledger ' . Money::format($result['computed']) . '.',
            '/admin/users/' . $userId,
            $result
        );
    }

    // =================================================================
    // Withdrawals
    // =================================================================

    /** GET /admin/withdrawals */
    public function withdrawals(Request $request): never
    {
        $this->authorize($request, 'wallet.withdrawals');

        $params   = $this->listParams($request, ['id', 'amount', 'created_at', 'status'], 'id');
        $clauses  = ['1 = 1'];
        $bindings = [];

        $status = $request->string('status', 'pending');

        if ($status !== 'all') {
            $clauses[]          = 'w.`status` = :status';
            $bindings['status'] = $status;
        }

        if ($params['search'] !== '') {
            $clauses[]          = "(w.`reference` LIKE :search ESCAPE '\\\\' OR u.`name` LIKE :search ESCAPE '\\\\'
                                    OR u.`email` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `withdrawal_requests` w INNER JOIN `users` u ON u.`id` = w.`user_id`' . $where,
            $bindings
        );

        $listSql = 'SELECT w.*, u.`name` AS `user_name`, u.`email`, u.`avatar_url`, u.`kyc_status`,
                    a.`name` AS `processor_name`,
                    wl.`balance` AS `wallet_balance`, wl.`locked_balance`
             FROM `withdrawal_requests` w
             INNER JOIN `users` u ON u.`id` = w.`user_id`
             LEFT JOIN `admins` a ON a.`id` = w.`processed_by`
             LEFT JOIN `wallets` wl ON wl.`user_id` = w.`user_id`' . $where . '
             ORDER BY w.`' . $params['sort_by'] . '` ' . $params['sort_dir'];

        $rows = Database::select(
            $listSql . ' LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        foreach ($rows as &$row) {
            $row['snapshot'] = json_decode((string) ($row['snapshot'] ?? '{}'), true) ?: [];
        }
        unset($row);

        $pendingTotal = (float) Database::scalar(
            "SELECT COALESCE(SUM(`amount`), 0) FROM `withdrawal_requests` WHERE `status` = 'pending'"
        );

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $this->exportCsv(
                'zuste-withdrawals',
                ['Reference', 'User', 'Email', 'Amount', 'Fee', 'Net', 'Method', 'Status', 'Requested', 'Processed'],
                $this->exportRows($listSql, $bindings),
                ['reference', 'user_name', 'email', 'amount', 'fee_amount', 'net_amount', 'method', 'status', 'created_at', 'processed_at']
            );
        }

        $this->view($request, 'admin.finance.withdrawals', [
            'pageTitle'    => 'Withdrawals',
            'pageSubtitle' => number_format($total) . ' payout requests',
            'withdrawals'  => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
            'pendingTotal' => Money::round($pendingTotal),
            'counts'       => $this->withdrawalCounts(),
        ]);
    }

    /** POST /admin/withdrawals/{id}/approve */
    public function approveWithdrawal(Request $request): never
    {
        $this->authorize($request, 'wallet.withdrawals');

        $withdrawalId = $request->routeParamInt('id');

        $withdrawal = Database::selectOne(
            'SELECT `id`, `amount`, `status`, `user_id` FROM `withdrawal_requests` WHERE `id` = :id',
            ['id' => $withdrawalId]
        );

        if ($withdrawal === null || $withdrawal['status'] !== 'pending') {
            $this->back('error', 'Only a pending withdrawal can be approved.');
        }

        // Segregation of duties.
        //
        // If an approval workflow covers a payout of this size, one admin must
        // not be able to release it alone. Until this check existed the seeded
        // "High-value payout" workflow was configurable in the admin panel and
        // consulted nowhere — any admin holding `wallet.withdrawals` could
        // approve any amount.
        $approvalId = ApprovalService::open(
            'withdrawal',
            $withdrawalId,
            (float) $withdrawal['amount'],
            'Payout of ' . Money::format((float) $withdrawal['amount']) . ' awaiting approval.',
            $this->adminId($request)
        );

        if ($approvalId !== null) {
            $this->log(
                $request,
                'withdrawal.approval_requested',
                'withdrawal',
                $withdrawalId,
                'Payout sent for multi-step approval.'
            );

            $this->respond(
                $request,
                'This payout needs additional approval. It has been sent to the approvals queue.',
                // The approval detail screen is /admin/workflow/{id}; there is
                // no /admin/approvals route.
                '/admin/workflow/' . $approvalId
            );
        }

        $updated = Database::execute(
            "UPDATE `withdrawal_requests`
             SET `status` = 'approved', `approved_at` = NOW(), `processed_by` = :admin_id
             WHERE `id` = :id AND `status` = 'pending'",
            ['admin_id' => $this->adminId($request), 'id' => $withdrawalId]
        );

        if ($updated === 0) {
            $this->back('error', 'Only a pending withdrawal can be approved.');
        }

        $this->log($request, 'withdrawal.approved', 'withdrawal', $withdrawalId, 'Payout approved, awaiting settlement.');

        $this->respond($request, 'Withdrawal approved. Settle it once the bank transfer clears.', '/admin/withdrawals');
    }

    /** POST /admin/withdrawals/{id}/complete */
    public function completeWithdrawal(Request $request): never
    {
        $this->authorize($request, 'wallet.withdrawals');

        $data = $this->validate($request, [
            'payout_reference' => 'required|string|min:3|max:120',
        ], [
            'payout_reference.required' => 'Enter the bank UTR or gateway payout reference.',
        ]);

        $withdrawalId = $request->routeParamInt('id');

        WalletService::completeWithdrawal(
            $withdrawalId,
            (string) $data['payout_reference'],
            $this->adminId($request)
        );

        $withdrawal = Database::selectOne(
            'SELECT * FROM `withdrawal_requests` WHERE `id` = :id',
            ['id' => $withdrawalId]
        );

        if ($withdrawal !== null) {
            NotificationService::send((int) $withdrawal['user_id'], 'withdrawal.approved', [
                'amount'    => Money::groupIndian((float) $withdrawal['net_amount'], 0),
                'currency'  => Money::symbol(),
                'reference' => (string) $withdrawal['reference'],
            ], ['action_type' => 'wallet']);
        }

        $this->log(
            $request,
            'withdrawal.completed',
            'withdrawal',
            $withdrawalId,
            'Payout settled.',
            ['payout_reference' => $data['payout_reference']]
        );

        $this->respond($request, 'Withdrawal marked as settled.', '/admin/withdrawals');
    }

    /** POST /admin/withdrawals/{id}/reject */
    public function rejectWithdrawal(Request $request): never
    {
        $this->authorize($request, 'wallet.withdrawals');

        $data = $this->validate($request, ['reason' => 'required|string|min:5|max:500']);

        $withdrawalId = $request->routeParamInt('id');

        WalletService::rejectWithdrawal($withdrawalId, (string) $data['reason'], $this->adminId($request));

        $withdrawal = Database::selectOne(
            'SELECT * FROM `withdrawal_requests` WHERE `id` = :id',
            ['id' => $withdrawalId]
        );

        if ($withdrawal !== null) {
            NotificationService::send((int) $withdrawal['user_id'], 'withdrawal.rejected', [
                'amount'    => Money::groupIndian((float) $withdrawal['amount'], 0),
                'currency'  => Money::symbol(),
                'reason'    => (string) $data['reason'],
                'reference' => (string) $withdrawal['reference'],
            ], ['action_type' => 'wallet']);
        }

        $this->log($request, 'withdrawal.rejected', 'withdrawal', $withdrawalId, 'Payout rejected; funds returned.', ['reason' => $data['reason']]);

        $this->respond($request, 'Withdrawal rejected and the amount returned to the wallet.', '/admin/withdrawals');
    }

    // =================================================================
    // Settlements
    // =================================================================

    /** GET /admin/settlements */
    public function settlements(Request $request): never
    {
        $this->authorize($request, 'payments.view');

        $params   = $this->listParams($request, ['settlement_date', 'gross_volume', 'net_revenue'], 'settlement_date');
        $clauses  = ['1 = 1'];
        $bindings = [];

        $this->applyDateRange($clauses, $bindings, '`settlement_date`', $params['from'], $params['to']);

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `settlements`' . $where, $bindings);

        $listSql = 'SELECT * FROM `settlements`' . $where
            . ' ORDER BY `' . $params['sort_by'] . '` ' . $params['sort_dir'];

        $rows = Database::select(
            $listSql . ' LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $totals = Database::selectOne(
            'SELECT COALESCE(SUM(`gross_volume`), 0) AS `gross_volume`,
                    COALESCE(SUM(`platform_fees`), 0) AS `platform_fees`,
                    COALESCE(SUM(`commissions`), 0) AS `commissions`,
                    COALESCE(SUM(`taxes`), 0) AS `taxes`,
                    COALESCE(SUM(`refunds_total`), 0) AS `refunds_total`,
                    COALESCE(SUM(`payouts_total`), 0) AS `payouts_total`,
                    COALESCE(SUM(`net_revenue`), 0) AS `net_revenue`
             FROM `settlements`' . $where,
            $bindings
        ) ?? [];

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $this->exportCsv(
                'zuste-settlements',
                ['Date', 'Gross volume', 'Platform fees', 'Commissions', 'Taxes', 'Refunds', 'Payouts', 'Net revenue', 'Bookings', 'Payments'],
                $this->exportRows($listSql, $bindings),
                ['settlement_date', 'gross_volume', 'platform_fees', 'commissions', 'taxes', 'refunds_total', 'payouts_total', 'net_revenue', 'bookings_count', 'payments_count']
            );
        }

        $this->view($request, 'admin.finance.settlements', [
            'pageTitle'    => 'Settlements',
            'pageSubtitle' => 'Daily reconciliation',
            'settlements'  => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
            'totals'       => $totals,
            'series'       => array_map(
                static fn (array $row): array => [
                    'label' => date('d M', strtotime((string) $row['settlement_date'])),
                    'value' => (float) $row['net_revenue'],
                ],
                array_reverse($rows)
            ),
        ]);
    }

    /**
     * The rows an export should contain.
     *
     * Handing the paginated `$rows` to exportCsv produced a file holding one
     * page — 25 rows by default — of whatever the filter matched, with nothing
     * in the file or the headers to say the rest was missing. An export re-runs
     * the same filtered, sorted query without the page window, under an
     * explicit ceiling.
     *
     * @param  array<string,mixed>      $bindings
     * @return list<array<string,mixed>>
     */
    private function exportRows(string $listSql, array $bindings): array
    {
        return Database::select($listSql . ' LIMIT ' . self::EXPORT_LIMIT, $bindings);
    }

    /** Abort unless the account exists and has not been soft-deleted. */
    private function requireUser(int $userId): void
    {
        if ((int) Database::scalar(
            'SELECT COUNT(*) FROM `users` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $userId]
        ) === 0) {
            throw HttpException::notFound('User not found.');
        }
    }

    /** @return array<string,int> */
    private function withdrawalCounts(): array
    {
        $rows = Database::select('SELECT `status`, COUNT(*) AS `total` FROM `withdrawal_requests` GROUP BY `status`');

        $counts = ['all' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
            $counts['all']                  += (int) $row['total'];
        }

        return $counts;
    }
}

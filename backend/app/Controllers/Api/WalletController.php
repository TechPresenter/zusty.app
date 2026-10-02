<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Money;
use Zuste\Services\InvoiceService;
use Zuste\Services\PaymentService;
use Zuste\Services\WalletService;

/**
 * Wallet, payments, payouts and payout destinations.
 *
 * Routes: /api/v1/wallet/*, /api/v1/payments/*
 */
final class WalletController extends BaseApiController
{
    // -----------------------------------------------------------------
    // Wallet
    // -----------------------------------------------------------------

    /** GET /wallet */
    public function show(Request $request): never
    {
        $userId  = $this->userId($request);
        $balance = WalletService::balance($userId);

        $recent = WalletService::transactions($userId, ['per_page' => 8]);

        Response::success([
            'balance'   => $balance,
            'formatted' => [
                'balance' => Money::formatShort($balance['balance'], $balance['currency']),
                'locked'  => Money::formatShort($balance['locked'], $balance['currency']),
                'total'   => Money::formatShort($balance['total'], $balance['currency']),
            ],
            'limits' => [
                'min_topup'      => Config::settingFloat('wallet', 'min_topup', 500),
                'max_topup'      => Config::settingFloat('wallet', 'max_topup', 500000),
                'min_withdrawal' => Config::settingFloat('wallet', 'min_withdrawal', 1000),
                'max_withdrawal' => Config::settingFloat('wallet', 'max_withdrawal', 200000),
                'hold_days'      => Config::settingInt('wallet', 'withdrawal_hold_days', 3),
            ],
            'kyc_required_for_withdrawal' => Config::settingBool('wallet', 'require_kyc_for_withdrawal', true),
            'recent_transactions'         => array_map([$this, 'presentTransaction'], $recent['items']),
        ] + $this->walletTotals($userId));
    }

    /**
     * Lifetime figures for the wallet's summary tiles, in the flat keys the
     * app's Wallet model already reads (`lifetime_earnings`, `withdrawn`).
     *
     * Earned and spent come from bookings rather than the ledger: the ledger
     * also carries holds, releases and adjustments, so summing its credits
     * would count the same rupee more than once.
     *
     * @return array<string,float>
     */
    private function walletTotals(int $userId): array
    {
        $earned = (float) Database::scalar(
            "SELECT COALESCE(SUM(`creator_earning`), 0) FROM `bookings`
             WHERE `creator_user_id` = :user_id AND `status` = 'completed' AND `deleted_at` IS NULL",
            ['user_id' => $userId]
        );

        $spent = (float) Database::scalar(
            "SELECT COALESCE(SUM(`total_amount`), 0) FROM `bookings`
             WHERE `brand_user_id` = :user_id AND `payment_status` = 'paid' AND `deleted_at` IS NULL",
            ['user_id' => $userId]
        );

        $withdrawn = (float) Database::scalar(
            "SELECT COALESCE(SUM(`amount`), 0) FROM `withdrawal_requests`
             WHERE `user_id` = :user_id AND `status` = 'completed'",
            ['user_id' => $userId]
        );

        $inFlight = (float) Database::scalar(
            "SELECT COALESCE(SUM(`amount`), 0) FROM `withdrawal_requests`
             WHERE `user_id` = :user_id AND `status` IN ('pending', 'approved', 'processing')",
            ['user_id' => $userId]
        );

        return [
            'lifetime_earnings'   => Money::round($earned),
            'total_spent'         => Money::round($spent),
            'withdrawn'           => Money::round($withdrawn),
            'pending_withdrawals' => Money::round($inFlight),
        ];
    }

    /** GET /wallet/transactions */
    public function transactions(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);

        $result = WalletService::transactions($this->userId($request), [
            'category'  => $request->string('category') ?: null,
            'direction' => $request->string('direction') ?: null,
            'from'      => $request->string('from') ?: null,
            'to'        => $request->string('to') ?: null,
            'page'      => $pagination['page'],
            'per_page'  => $pagination['per_page'],
        ]);

        Response::paginated(
            array_map([$this, 'presentTransaction'], $result['items']),
            $result['total'],
            $result['page'],
            $result['per_page']
        );
    }

    /** POST /wallet/topup — start a wallet deposit. */
    public function topup(Request $request): never
    {
        $data = $this->validate($request, [
            'amount'  => 'required|numeric|min_value:1',
            'gateway' => 'required|in:razorpay,stripe,cashfree',
        ]);

        $order = PaymentService::createTopupOrder(
            $this->userId($request),
            (float) $data['amount'],
            (string) $data['gateway']
        );

        Response::success($order, 'Complete the payment to add money to your wallet.');
    }

    // -----------------------------------------------------------------
    // Payments
    // -----------------------------------------------------------------

    /** GET /payments/methods — what the checkout screen should offer. */
    public function methods(Request $request): never
    {
        $balance = WalletService::balance($this->userId($request));

        Response::success([
            'gateways'       => PaymentService::availableGateways(),
            'wallet_balance' => $balance['balance'],
            'wallet_label'   => Money::formatShort($balance['balance'], $balance['currency']),
            'currency'       => $balance['currency'],
            'symbol'         => Money::symbol($balance['currency']),
        ]);
    }

    /** POST /payments/booking/{id} — create a gateway order for a booking. */
    public function payBooking(Request $request): never
    {
        $data = $this->validate($request, [
            'gateway'     => 'required|in:razorpay,stripe,cashfree,wallet',
            'coupon_code' => 'nullable|string|max:32',
        ]);

        $order = PaymentService::createBookingOrder(
            $request->routeParamInt('id'),
            $this->userId($request),
            (string) $data['gateway'],
            isset($data['coupon_code']) ? (string) $data['coupon_code'] : null
        );

        Response::success(
            $order,
            $order['requires_client_action'] ? 'Complete the payment to confirm your booking.' : 'Payment successful.'
        );
    }

    /** POST /payments/verify — confirm a gateway checkout result. */
    public function verify(Request $request): never
    {
        $gateway = $request->string('gateway');
        $userId  = $this->userId($request);

        if ($gateway === 'razorpay') {
            $data = $this->validate($request, [
                'gateway'             => 'required|in:razorpay',
                'razorpay_order_id'   => 'required|string|max:120',
                'razorpay_payment_id' => 'required|string|max:120',
                'razorpay_signature'  => 'required|string|max:255',
            ]);

            $payment = PaymentService::verifyRazorpay(
                $userId,
                (string) $data['razorpay_order_id'],
                (string) $data['razorpay_payment_id'],
                (string) $data['razorpay_signature']
            );
        } elseif ($gateway === 'stripe') {
            $data = $this->validate($request, [
                'gateway'   => 'required|in:stripe',
                'intent_id' => 'required|string|max:120',
            ]);

            $payment = PaymentService::verifyStripe($userId, (string) $data['intent_id']);
        } elseif ($gateway === 'cashfree') {
            // Cashfree's SDK hands back no signature, so the client sends only
            // the order id and the outcome is read from Cashfree directly.
            $data = $this->validate($request, [
                'gateway'  => 'required|in:cashfree',
                'order_id' => 'required|string|max:120',
            ]);

            $payment = PaymentService::verifyCashfree($userId, (string) $data['order_id']);
        } else {
            throw HttpException::unprocessable('Unsupported payment gateway.');
        }

        Response::success(
            ['payment' => $this->presentPayment($payment)],
            'Payment confirmed.'
        );
    }

    /** GET /payments — payment history. */
    public function history(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);
        $userId     = $this->userId($request);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `payments` WHERE `user_id` = :id',
            ['id' => $userId]
        );

        $rows = Database::select(
            'SELECT p.*, b.`code` AS `booking_code`, b.`title` AS `booking_title`
             FROM `payments` p
             LEFT JOIN `bookings` b ON b.`id` = p.`booking_id`
             WHERE p.`user_id` = :id
             ORDER BY p.`id` DESC
             LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            ['id' => $userId]
        );

        Response::paginated(
            array_map([$this, 'presentPayment'], $rows),
            $total,
            $pagination['page'],
            $pagination['per_page']
        );
    }

    /**
     * GET /invoices — every invoice issued to the caller.
     *
     * Serves both sides: a brand sees `brand_invoice` rows, a creator sees
     * their `creator_payout` statements. `invoices.user_id` already records who
     * the document belongs to, so one query covers both without a role branch.
     *
     * The Flutter billing screen has called this path since it was written and
     * the route did not exist — the whole screen answered 404.
     */
    public function invoices(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);
        $userId     = $this->userId($request);

        $clauses  = ['i.`user_id` = :id'];
        $bindings = ['id' => $userId];

        if ($request->filled('type')) {
            $clauses[]        = 'i.`type` = :type';
            $bindings['type'] = $request->string('type');
        }

        if ($request->filled('status')) {
            $clauses[]          = 'i.`status` = :status';
            $bindings['status'] = $request->string('status');
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `invoices` i' . $where, $bindings);

        $rows = Database::select(
            'SELECT i.*, b.`code` AS `booking_code`, b.`title` AS `booking_title`
             FROM `invoices` i
             LEFT JOIN `bookings` b ON b.`id` = i.`booking_id`' . $where . '
             ORDER BY i.`id` DESC
             LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            $bindings
        );

        Response::paginated(
            array_map([$this, 'presentInvoice'], $rows),
            $total,
            $pagination['page'],
            $pagination['per_page']
        );
    }

    /**
     * GET /invoices/{id} — one invoice.
     *
     * `?format=html` returns the print-ready document, which is how the app
     * offers "download": there is no PDF library, so the page carries an
     * `@page` rule and prints to PDF from the browser.
     */
    public function invoice(Request $request): never
    {
        $userId    = $this->userId($request);
        $invoiceId = $request->routeParamInt('id');

        $invoice = Database::selectOne(
            'SELECT i.*, b.`code` AS `booking_code`, b.`title` AS `booking_title`
             FROM `invoices` i
             LEFT JOIN `bookings` b ON b.`id` = i.`booking_id`
             WHERE i.`id` = :id AND i.`user_id` = :user_id',
            ['id' => $invoiceId, 'user_id' => $userId]
        );

        // 404 rather than 403 for someone else's invoice: a 403 confirms the
        // id exists, which is enough to count how many invoices the platform
        // has issued.
        if ($invoice === null) {
            throw HttpException::notFound('Invoice not found.');
        }

        if ($request->string('format') === 'html') {
            Response::html(InvoiceService::renderHtml($invoiceId, $userId));
        }

        Response::success(['invoice' => $this->presentInvoice($invoice)]);
    }

    /**
     * @param  array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function presentInvoice(array $invoice): array
    {
        $invoice['line_items'] = json_decode((string) ($invoice['line_items'] ?? '[]'), true) ?: [];

        // The app renders "download" from this, so it is built here rather
        // than assembled client-side from an id.
        $invoice['url'] = '/api/v1/invoices/' . (int) $invoice['id'] . '?format=html';

        $invoice['formatted'] = [
            'subtotal' => Money::format((float) $invoice['subtotal'], (string) $invoice['currency']),
            'fee'      => Money::format((float) $invoice['fee_amount'], (string) $invoice['currency']),
            'tax'      => Money::format((float) $invoice['tax_amount'], (string) $invoice['currency']),
            'discount' => Money::format((float) ($invoice['discount_amount'] ?? 0), (string) $invoice['currency']),
            'total'    => Money::format((float) $invoice['total'], (string) $invoice['currency']),
        ];

        return $invoice;
    }

    // -----------------------------------------------------------------
    // Withdrawals
    // -----------------------------------------------------------------

    /** POST /wallet/withdraw */
    public function withdraw(Request $request): never
    {
        $data = $this->validate($request, [
            'amount'          => 'required|numeric|min_value:1',
            'bank_account_id' => 'required|integer',
        ]);

        $withdrawal = WalletService::requestWithdrawal(
            $this->userId($request),
            (float) $data['amount'],
            (int) $data['bank_account_id']
        );

        Response::created(
            ['withdrawal' => $this->presentWithdrawal($withdrawal)],
            'Withdrawal requested. Payouts are usually settled within 1–3 business days.'
        );
    }

    /** GET /wallet/withdrawals */
    public function withdrawals(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);
        $userId     = $this->userId($request);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `withdrawal_requests` WHERE `user_id` = :id',
            ['id' => $userId]
        );

        $rows = Database::select(
            'SELECT * FROM `withdrawal_requests` WHERE `user_id` = :id
             ORDER BY `id` DESC LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            ['id' => $userId]
        );

        Response::paginated(
            array_map([$this, 'presentWithdrawal'], $rows),
            $total,
            $pagination['page'],
            $pagination['per_page']
        );
    }

    /** POST /wallet/withdrawals/{id}/cancel */
    public function cancelWithdrawal(Request $request): never
    {
        $withdrawalId = $request->routeParamInt('id');
        $userId       = $this->userId($request);

        $withdrawal = Database::selectOne(
            'SELECT * FROM `withdrawal_requests` WHERE `id` = :id AND `user_id` = :user_id',
            ['id' => $withdrawalId, 'user_id' => $userId]
        );

        if ($withdrawal === null) {
            throw HttpException::notFound('Withdrawal request not found.');
        }

        if ($withdrawal['status'] !== 'pending') {
            throw HttpException::conflict('Only a pending withdrawal can be cancelled.');
        }

        Database::transaction(static function () use ($withdrawal, $withdrawalId, $userId): void {
            WalletService::release($userId, (float) $withdrawal['amount'], [
                'category'        => 'reversal',
                'description'     => 'Withdrawal ' . (string) $withdrawal['reference'] . ' cancelled',
                'reference_type'  => 'withdrawal',
                'reference_id'    => $withdrawalId,
                'idempotency_key' => 'withdrawal-cancel:' . $withdrawalId,
            ]);

            Database::update('withdrawal_requests', [
                'status'       => 'cancelled',
                'processed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $withdrawalId]);
        });

        Response::success(null, 'Withdrawal cancelled and the amount returned to your wallet.');
    }

    // -----------------------------------------------------------------
    // Payout destinations
    // -----------------------------------------------------------------

    /** GET /wallet/bank-accounts */
    public function bankAccounts(Request $request): never
    {
        $rows = Database::select(
            'SELECT `id`, `method`, `account_holder_name`, `account_last4`, `ifsc_code`,
                    `bank_name`, `branch_name`, `upi_id`, `is_primary`, `is_verified`, `created_at`
             FROM `bank_accounts`
             WHERE `user_id` = :id AND `deleted_at` IS NULL
             ORDER BY `is_primary` DESC, `id` DESC',
            ['id' => $this->userId($request)]
        );

        foreach ($rows as &$row) {
            $row['display'] = $row['method'] === 'upi'
                ? (string) $row['upi_id']
                : (string) $row['bank_name'] . ' •••• ' . (string) $row['account_last4'];
        }
        unset($row);

        Response::success(['bank_accounts' => $rows]);
    }

    /** POST /wallet/bank-accounts */
    public function addBankAccount(Request $request): never
    {
        $data = $this->validate($request, [
            'method'              => 'required|in:bank,upi',
            'account_holder_name' => 'nullable|string|max:150',
            'account_number'      => 'nullable|string|min:6|max:24',
            'ifsc_code'           => 'nullable|string|min:11|max:15',
            'bank_name'           => 'nullable|string|max:150',
            'upi_id'              => 'nullable|string|max:120',
            'is_primary'          => 'nullable|boolean',
        ]);

        $userId = $this->userId($request);
        $method = (string) $data['method'];

        if ($method === 'bank') {
            foreach (['account_holder_name', 'account_number', 'ifsc_code', 'bank_name'] as $field) {
                if (empty($data[$field])) {
                    throw HttpException::unprocessable('Bank transfers require the account holder, account number, IFSC and bank name.');
                }
            }

            $accountNumber = preg_replace('/\s/', '', (string) $data['account_number']) ?? '';

            if (preg_match('/^\d{6,24}$/', $accountNumber) !== 1) {
                throw HttpException::unprocessable('Enter a valid account number.');
            }

            if (preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper((string) $data['ifsc_code'])) !== 1) {
                throw HttpException::unprocessable('Enter a valid IFSC code, e.g. HDFC0001234.');
            }
        } else {
            if (empty($data['upi_id']) || preg_match('/^[\w.\-]{2,64}@[a-zA-Z]{2,32}$/', (string) $data['upi_id']) !== 1) {
                throw HttpException::unprocessable('Enter a valid UPI ID, e.g. name@bank.');
            }

            $accountNumber = '';
        }

        $accountId = Database::transaction(static function () use ($userId, $data, $method, $accountNumber): int {
            if (!empty($data['is_primary'])) {
                Database::execute(
                    'UPDATE `bank_accounts` SET `is_primary` = 0 WHERE `user_id` = :id',
                    ['id' => $userId]
                );
            }

            return Database::insert('bank_accounts', [
                'user_id'             => $userId,
                'method'              => $method,
                'account_holder_name' => $data['account_holder_name'] ?? null,
                // Encrypted at rest; only the last four digits are stored in clear.
                'account_number'      => $method === 'bank' ? Crypto::encrypt($accountNumber) : null,
                'account_last4'       => $method === 'bank' ? substr($accountNumber, -4) : null,
                'ifsc_code'           => isset($data['ifsc_code']) ? strtoupper((string) $data['ifsc_code']) : null,
                'bank_name'           => $data['bank_name'] ?? null,
                'upi_id'              => $data['upi_id'] ?? null,
                'is_primary'          => !empty($data['is_primary']) ? 1 : 0,
                'created_at'          => date('Y-m-d H:i:s'),
            ]);
        });

        Response::created(['id' => $accountId], 'Payout account added.');
    }

    /** DELETE /wallet/bank-accounts/{id} */
    public function deleteBankAccount(Request $request): never
    {
        $accountId = $request->routeParamInt('id');
        $userId    = $this->userId($request);

        $pending = Database::scalar(
            "SELECT 1 FROM `withdrawal_requests`
             WHERE `bank_account_id` = :id AND `status` IN ('pending','approved','processing') LIMIT 1",
            ['id' => $accountId]
        );

        if ($pending !== null) {
            throw HttpException::conflict('This account has a payout in progress and cannot be removed yet.');
        }

        $removed = Database::execute(
            'UPDATE `bank_accounts` SET `deleted_at` = NOW()
             WHERE `id` = :id AND `user_id` = :user_id AND `deleted_at` IS NULL',
            ['id' => $accountId, 'user_id' => $userId]
        );

        if ($removed === 0) {
            throw HttpException::notFound('Payout account not found.');
        }

        Response::noContent('Payout account removed.');
    }

    // -----------------------------------------------------------------
    // Presenters
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function presentTransaction(array $row): array
    {
        $direction = (string) $row['direction'];
        $amount    = Money::round((float) $row['amount']);

        return [
            'id'             => (int) $row['id'],
            'reference'      => (string) $row['reference'],
            'direction'      => $direction,
            'category'       => (string) $row['category'],
            'amount'         => $amount,
            'amount_label'   => Money::formatSigned($amount, $direction, (string) $row['currency']),
            'balance_after'  => Money::round((float) $row['balance_after']),
            'description'    => (string) $row['description'],
            'status'         => (string) $row['status'],
            'reference_type' => $row['reference_type'],
            'reference_id'   => $row['reference_id'] === null ? null : (int) $row['reference_id'],
            'created_at'     => $row['created_at'],
            'date_label'     => date('d M Y', strtotime((string) $row['created_at'])),
            'icon'           => $this->transactionIcon((string) $row['category'], $direction),
        ];
    }

    private function transactionIcon(string $category, string $direction): string
    {
        return match ($category) {
            'refund', 'reversal'                 => 'refresh',
            'deposit'                            => 'arrow_downward',
            'withdrawal'                         => 'account_balance',
            'booking_earning', 'escrow_release'  => 'payments',
            'referral', 'bonus'                  => 'card_giftcard',
            'commission', 'platform_fee', 'tax'  => 'percent',
            default                              => $direction === 'credit' ? 'arrow_downward' : 'arrow_upward',
        };
    }

    /** @param array<string,mixed> $row */
    private function presentPayment(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'reference'     => (string) $row['reference'],
            'booking_id'    => $row['booking_id'] === null ? null : (int) $row['booking_id'],
            'booking_code'  => $row['booking_code'] ?? null,
            'booking_title' => $row['booking_title'] ?? null,
            'purpose'       => (string) $row['purpose'],
            'gateway'       => (string) $row['gateway'],
            'method'        => $row['method'],
            'amount'        => Money::round((float) $row['amount']),
            'amount_label'  => Money::format((float) $row['amount'], (string) $row['currency']),
            'refunded'      => Money::round((float) $row['amount_refunded']),
            'currency'      => (string) $row['currency'],
            'status'        => (string) $row['status'],
            'card_last4'    => $row['card_last4'],
            'paid_at'       => $row['paid_at'],
            'created_at'    => $row['created_at'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function presentWithdrawal(array $row): array
    {
        $snapshot = json_decode((string) ($row['snapshot'] ?? '{}'), true) ?: [];

        return [
            'id'               => (int) $row['id'],
            'reference'        => (string) $row['reference'],
            'amount'           => Money::round((float) $row['amount']),
            'amount_label'     => Money::format((float) $row['amount'], (string) $row['currency']),
            'fee'              => Money::round((float) $row['fee_amount']),
            'net_amount'       => Money::round((float) $row['net_amount']),
            'net_label'        => Money::format((float) $row['net_amount'], (string) $row['currency']),
            'method'           => (string) $row['method'],
            'destination'      => ($snapshot['method'] ?? '') === 'upi'
                ? (string) ($snapshot['upi_id'] ?? '')
                : trim((string) ($snapshot['bank_name'] ?? '') . ' •••• ' . (string) ($snapshot['account_last4'] ?? '')),
            'status'           => (string) $row['status'],
            'payout_reference' => $row['payout_reference'],
            'rejection_reason' => $row['rejection_reason'],
            'created_at'       => $row['created_at'],
            'processed_at'     => $row['processed_at'],
            'can_cancel'       => $row['status'] === 'pending',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Helpers\Http;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * Payment orchestration across Razorpay, Stripe and the internal wallet.
 *
 * Security rules that are never relaxed:
 *  - a payment is only ever marked captured after the gateway signature is
 *    verified server-side; the client's word is never trusted;
 *  - the captured amount is compared against the booking total, so a tampered
 *    client cannot pay ₹1 for a ₹27,500 booking;
 *  - every webhook is persisted before it is processed, and replays are
 *    ignored by the unique (gateway, event_id) key.
 */
final class PaymentService
{
    /**
     * Booking statuses in which a paid booking's money is still in escrow.
     * Every other status a paid booking can reach is finished: completed,
     * cancelled or refunded.
     */
    private const ESCROW_HELD_STATUSES = ['confirmed', 'in_progress', 'delivered', 'disputed'];

    // -----------------------------------------------------------------
    // Order creation
    // -----------------------------------------------------------------

    /**
     * Create a gateway order for a booking.
     *
     * [$customerPhone] is the mobile number the payer typed at checkout. It
     * is only used for a Cashfree order on an account with no phone (every
     * email or Google sign-up), is never written to the account and is
     * never treated as verified — see cashfreeCustomer().
     *
     * @return array<string,mixed> Checkout payload for the Flutter client.
     */
    public static function createBookingOrder(
        int $bookingId,
        int $userId,
        string $gateway,
        ?string $couponCode = null,
        ?string $customerPhone = null
    ): array {
        // Applying the coupon before the booking is read means everything
        // below — the amount charged, the order created at the gateway, the
        // escrow hold — sees the discounted total with no further plumbing.
        if ($couponCode !== null && trim($couponCode) !== '') {
            CouponService::applyToBooking($bookingId, $userId, $couponCode);
        }

        $booking = Database::selectOne(
            'SELECT * FROM `bookings` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        if ((int) $booking['brand_user_id'] !== $userId) {
            throw HttpException::forbidden('Only the brand that created this booking can pay for it.');
        }

        if ($booking['payment_status'] === 'paid') {
            throw HttpException::conflict('This booking has already been paid for.');
        }

        if (!in_array($booking['status'], ['accepted', 'awaiting_payment'], true)) {
            throw HttpException::unprocessable('This booking is not ready for payment.');
        }

        // A coupon attached minutes ago can have expired, been switched off or
        // been claimed out since. Re-checking here is what stops the customer
        // being quietly charged the full price for a discount they accepted.
        $booking = CouponService::reverifyForCharge($booking);

        $amount = Money::round((float) $booking['total_amount']);

        return match ($gateway) {
            'wallet'   => self::payFromWallet($booking, $userId),
            'stripe'   => self::createStripeIntent($userId, $amount, 'booking', $bookingId, (string) $booking['code']),
            'razorpay' => self::createRazorpayOrder($userId, $amount, 'booking', $bookingId, (string) $booking['code']),
            'cashfree' => self::createCashfreeOrder($userId, $amount, 'booking', $bookingId, (string) $booking['code'], $customerPhone),
            default    => throw HttpException::unprocessable('Unsupported payment gateway.'),
        };
    }

    /**
     * Create a gateway order for a wallet top-up.
     *
     * Refused with 403 `topup_disabled` unless wallet.topup_enabled is on —
     * checked here as well as in the controller, so no other caller can
     * load a wallet while an admin has top-ups switched off.
     */
    public static function createTopupOrder(int $userId, float $amount, string $gateway): array
    {
        WalletService::assertTopupEnabled();

        $amount  = Money::round($amount);
        $minimum = Config::settingFloat('wallet', 'min_topup', 500);
        $maximum = Config::settingFloat('wallet', 'max_topup', 500000);

        if ($amount < $minimum) {
            throw HttpException::unprocessable('The minimum top-up is ' . Money::format($minimum) . '.');
        }

        if ($amount > $maximum) {
            throw HttpException::unprocessable('The maximum top-up is ' . Money::format($maximum) . '.');
        }

        return match ($gateway) {
            'stripe'   => self::createStripeIntent($userId, $amount, 'wallet_topup', null, 'TOPUP'),
            'razorpay' => self::createRazorpayOrder($userId, $amount, 'wallet_topup', null, 'TOPUP'),
            'cashfree' => self::createCashfreeOrder($userId, $amount, 'wallet_topup', null, 'TOPUP'),
            default    => throw HttpException::unprocessable('Unsupported payment gateway.'),
        };
    }

    /**
     * Settle a booking straight from wallet balance — no gateway involved.
     *
     * Only for money already in the wallet (a refund, say): the wallet cannot
     * be topped up to make up a shortfall, so a balance that does not cover
     * the whole total is refused up front with a pointer to the gateway. The
     * debit below re-checks under the wallet lock either way.
     */
    private static function payFromWallet(array $booking, int $userId): array
    {
        if (!Config::settingBool('payment', 'allow_wallet_payment', true)) {
            throw HttpException::forbidden('Wallet payments are disabled.');
        }

        $amount = Money::round((float) $booking['total_amount']);

        if (WalletService::balance($userId)['balance'] < $amount) {
            throw new HttpException(
                'Your wallet balance does not cover this booking. Pay with UPI, card or net banking instead.',
                422,
                'insufficient_balance'
            );
        }

        return Database::transaction(static function () use ($booking, $userId, $amount): array {
            WalletService::debit($userId, $amount, [
                'category'        => 'booking_payment',
                'description'     => 'Payment for booking #' . (string) $booking['code'],
                'reference_type'  => 'booking',
                'reference_id'    => (int) $booking['id'],
                'counterparty_id' => (int) $booking['creator_user_id'],
                'idempotency_key' => 'wallet-payment:booking:' . (int) $booking['id'],
            ]);

            $paymentId = self::recordPayment([
                'booking_id'      => (int) $booking['id'],
                'user_id'         => $userId,
                'purpose'         => 'booking',
                'gateway'         => 'wallet',
                'method'          => 'wallet',
                'amount'          => $amount,
                'status'          => 'captured',
                'paid_at'         => date('Y-m-d H:i:s'),
                'idempotency_key' => 'wallet-payment:booking:' . (int) $booking['id'],
            ]);

            BookingService::markPaid((int) $booking['id'], $paymentId);

            $payment = Database::selectOne('SELECT * FROM `payments` WHERE `id` = :id', ['id' => $paymentId]);

            return [
                'gateway'   => 'wallet',
                'status'    => 'captured',
                'payment'   => $payment,
                'requires_client_action' => false,
            ];
        });
    }

    // -----------------------------------------------------------------
    // Razorpay
    // -----------------------------------------------------------------

    private static function createRazorpayOrder(
        int $userId,
        float $amount,
        string $purpose,
        ?int $bookingId,
        string $reference
    ): array {
        [$keyId, $keySecret] = self::razorpayCredentials();

        $receipt = strtoupper(substr($purpose === 'booking' ? $reference : 'TOPUP' . $userId, 0, 38));

        $response = Http::post(
            (string) Config::get('payment.razorpay.api_base') . '/orders',
            [
                'amount'          => Money::toMinorUnits($amount),
                'currency'        => Money::defaultCurrency(),
                'receipt'         => $receipt,
                'payment_capture' => 1,
                'notes'           => [
                    'purpose'    => $purpose,
                    'booking_id' => (string) ($bookingId ?? ''),
                    'user_id'    => (string) $userId,
                ],
            ],
            ['Content-Type' => 'application/json'],
            25,
            [$keyId, $keySecret]
        );

        if (!Http::successful($response) || empty($response['json']['id'])) {
            Logger::error('Razorpay order creation failed', ['status' => $response['status']]);

            throw HttpException::server('Unable to start the payment. Please try again.');
        }

        $orderId = (string) $response['json']['id'];

        $paymentId = self::recordPayment([
            'booking_id'       => $bookingId,
            'user_id'          => $userId,
            'purpose'          => $purpose,
            'gateway'          => 'razorpay',
            'gateway_order_id' => $orderId,
            'amount'           => $amount,
            'status'           => 'created',
        ]);

        return [
            'gateway'                => 'razorpay',
            'payment_id'             => $paymentId,
            'order_id'               => $orderId,
            'key_id'                 => $keyId,
            'amount'                 => $amount,
            'amount_minor'           => Money::toMinorUnits($amount),
            'currency'               => Money::defaultCurrency(),
            'requires_client_action' => true,
        ];
    }

    /**
     * Verify the Razorpay checkout callback.
     *
     * The signature is HMAC-SHA256(order_id + "|" + payment_id, key_secret) —
     * this is what proves the client is not lying about the outcome.
     */
    public static function verifyRazorpay(int $userId, string $orderId, string $razorpayPaymentId, string $signature): array
    {
        [, $keySecret] = self::razorpayCredentials();

        if (!Crypto::verifySignature($orderId . '|' . $razorpayPaymentId, $signature, $keySecret)) {
            Logger::warning('Razorpay signature verification failed', ['order_id' => $orderId, 'user_id' => $userId]);

            throw HttpException::unprocessable('Payment verification failed.', 'signature_mismatch');
        }

        $payment = Database::selectOne(
            "SELECT * FROM `payments` WHERE `gateway` = 'razorpay' AND `gateway_order_id` = :order_id",
            ['order_id' => $orderId]
        );

        if ($payment === null) {
            throw HttpException::notFound('Payment record not found.');
        }

        if ((int) $payment['user_id'] !== $userId) {
            throw HttpException::forbidden('This payment belongs to another account.');
        }

        if ($payment['status'] === 'captured') {
            return $payment; // Idempotent.
        }

        // Confirm the amount with Razorpay rather than trusting the client.
        [$keyId, $secret] = self::razorpayCredentials();

        $lookup = Http::get(
            (string) Config::get('payment.razorpay.api_base') . '/payments/' . $razorpayPaymentId,
            [],
            20
        );

        // Basic auth is required; re-issue with credentials when the plain GET is refused.
        if (!Http::successful($lookup)) {
            $lookup = Http::request(
                'GET',
                (string) Config::get('payment.razorpay.api_base') . '/payments/' . $razorpayPaymentId,
                null,
                [],
                20,
                [$keyId, $secret]
            );
        }

        if (!Http::successful($lookup) || !is_array($lookup['json'])) {
            throw HttpException::server('Could not confirm the payment with the gateway.');
        }

        $gatewayAmount = Money::fromMinorUnits((int) ($lookup['json']['amount'] ?? 0));
        $gatewayStatus = (string) ($lookup['json']['status'] ?? '');

        if (!Money::equals($gatewayAmount, (float) $payment['amount'])) {
            Logger::critical('Razorpay amount mismatch', [
                'payment_id' => $payment['id'],
                'expected'   => $payment['amount'],
                'received'   => $gatewayAmount,
            ]);

            throw HttpException::unprocessable('The payment amount does not match the booking total.');
        }

        if (!in_array($gatewayStatus, ['captured', 'authorized'], true)) {
            throw HttpException::unprocessable('The payment has not been completed.');
        }

        return self::finalise(
            (int) $payment['id'],
            $razorpayPaymentId,
            $signature,
            (string) ($lookup['json']['method'] ?? ''),
            $lookup['json']
        );
    }

    // -----------------------------------------------------------------
    // Stripe
    // -----------------------------------------------------------------

    private static function createStripeIntent(
        int $userId,
        float $amount,
        string $purpose,
        ?int $bookingId,
        string $reference
    ): array {
        $secretKey = self::stripeSecret();

        $response = Http::post(
            (string) Config::get('payment.stripe.api_base') . '/payment_intents',
            [
                'amount'                      => Money::toMinorUnits($amount),
                'currency'                    => strtolower(Money::defaultCurrency()),
                'automatic_payment_methods'   => ['enabled' => 'true'],
                'metadata'                    => [
                    'purpose'    => $purpose,
                    'booking_id' => (string) ($bookingId ?? ''),
                    'user_id'    => (string) $userId,
                    'reference'  => $reference,
                ],
            ],
            [
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            25
        );

        if (!Http::successful($response) || empty($response['json']['id'])) {
            Logger::error('Stripe PaymentIntent creation failed', ['status' => $response['status']]);

            throw HttpException::server('Unable to start the payment. Please try again.');
        }

        $intentId = (string) $response['json']['id'];

        $paymentId = self::recordPayment([
            'booking_id'       => $bookingId,
            'user_id'          => $userId,
            'purpose'          => $purpose,
            'gateway'          => 'stripe',
            'gateway_order_id' => $intentId,
            'amount'           => $amount,
            'status'           => 'created',
        ]);

        return [
            'gateway'                => 'stripe',
            'payment_id'             => $paymentId,
            'client_secret'          => (string) ($response['json']['client_secret'] ?? ''),
            'publishable_key'        => self::stripeSetting('publishable_key'),
            'intent_id'              => $intentId,
            'amount'                 => $amount,
            'currency'               => Money::defaultCurrency(),
            'requires_client_action' => true,
        ];
    }

    /** Confirm a Stripe PaymentIntent by asking Stripe, never the client. */
    public static function verifyStripe(int $userId, string $intentId): array
    {
        $payment = Database::selectOne(
            "SELECT * FROM `payments` WHERE `gateway` = 'stripe' AND `gateway_order_id` = :intent_id",
            ['intent_id' => $intentId]
        );

        if ($payment === null) {
            throw HttpException::notFound('Payment record not found.');
        }

        if ((int) $payment['user_id'] !== $userId) {
            throw HttpException::forbidden('This payment belongs to another account.');
        }

        if ($payment['status'] === 'captured') {
            return $payment;
        }

        $response = Http::get(
            (string) Config::get('payment.stripe.api_base') . '/payment_intents/' . $intentId,
            ['Authorization' => 'Bearer ' . self::stripeSecret()],
            20
        );

        if (!Http::successful($response) || !is_array($response['json'])) {
            throw HttpException::server('Could not confirm the payment with the gateway.');
        }

        if (($response['json']['status'] ?? '') !== 'succeeded') {
            throw HttpException::unprocessable('The payment has not been completed.');
        }

        $gatewayAmount = Money::fromMinorUnits((int) ($response['json']['amount_received'] ?? 0));

        if (!Money::equals($gatewayAmount, (float) $payment['amount'])) {
            throw HttpException::unprocessable('The payment amount does not match the booking total.');
        }

        return self::finalise(
            (int) $payment['id'],
            (string) ($response['json']['latest_charge'] ?? $intentId),
            '',
            (string) ($response['json']['payment_method_types'][0] ?? 'card'),
            $response['json']
        );
    }

    // -----------------------------------------------------------------
    // Cashfree
    // -----------------------------------------------------------------

    /**
     * Create a Cashfree order and hand the client a `payment_session_id`.
     *
     * Unlike Razorpay, Cashfree wants *us* to supply the order id, so the
     * payment row is written first and its reference (`ZPAY00000123`) becomes
     * the order id. That gives a one-to-one mapping in both directions — a
     * webhook can find our row from `order_id` alone, with no lookup table.
     */
    private static function createCashfreeOrder(
        int $userId,
        float $amount,
        string $purpose,
        ?int $bookingId,
        string $reference,
        ?string $customerPhone = null
    ): array {
        [$appId, $secretKey] = self::cashfreeCredentials();

        $customer = self::cashfreeCustomer($userId, $customerPhone);

        $paymentId = self::recordPayment([
            'booking_id' => $bookingId,
            'user_id'    => $userId,
            'purpose'    => $purpose,
            'gateway'    => 'cashfree',
            'amount'     => $amount,
            'status'     => 'created',
        ]);

        // Matches what recordPayment stamped on the row.
        $orderId = Str::referenceCode('ZPAY', $paymentId, 8);

        $body = [
            'order_id'       => $orderId,
            // Cashfree takes major units (₹27500.00), not paise.
            'order_amount'   => Money::round($amount),
            'order_currency' => Money::defaultCurrency(),
            'order_note'     => $purpose === 'booking' ? 'Booking ' . $reference : 'Wallet top-up',
            'customer_details' => $customer,
            'order_meta'     => [
                'notify_url' => self::cashfreeNotifyUrl(),
            ],
            'order_tags'     => [
                'purpose'    => $purpose,
                'booking_id' => (string) ($bookingId ?? ''),
                'user_id'    => (string) $userId,
            ],
        ];

        $returnUrl = self::cashfreeSetting('return_url');

        if ($returnUrl !== '') {
            $body['order_meta']['return_url'] = $returnUrl;
        }

        $response = Http::post(
            self::cashfreeApiBase() . '/orders',
            $body,
            self::cashfreeHeaders($appId, $secretKey),
            25
        );

        if (!Http::successful($response) || empty($response['json']['payment_session_id'])) {
            Database::update('payments', [
                'status'         => 'failed',
                'failure_reason' => mb_substr(
                    (string) ($response['json']['message'] ?? 'Cashfree rejected the order.'),
                    0,
                    500
                ),
                'failed_at'      => date('Y-m-d H:i:s'),
            ], ['id' => $paymentId]);

            Logger::error('Cashfree order creation failed', [
                'status'     => $response['status'],
                'payment_id' => $paymentId,
                'code'       => (string) ($response['json']['code'] ?? ''),
            ]);

            throw HttpException::server('Unable to start the payment. Please try again.');
        }

        Database::update('payments', [
            'gateway_order_id'  => $orderId,
            'gateway_reference' => mb_substr((string) ($response['json']['cf_order_id'] ?? ''), 0, 120) ?: null,
        ], ['id' => $paymentId]);

        return [
            'gateway'                => 'cashfree',
            'payment_id'             => $paymentId,
            'order_id'               => $orderId,
            'payment_session_id'     => (string) $response['json']['payment_session_id'],
            // The hosted checkout URL is a gateway detail, so the backend
            // builds it: the client just opens whatever it is handed and does
            // not have to track Cashfree's sandbox/production hostnames.
            'checkout_url'           => self::cashfreeCheckoutUrl((string) $response['json']['payment_session_id']),
            'app_id'                 => $appId,
            'mode'                   => self::cashfreeMode(),
            'amount'                 => Money::round($amount),
            'currency'               => Money::defaultCurrency(),
            'requires_client_action' => true,
        ];
    }

    /**
     * Confirm a Cashfree checkout by asking Cashfree.
     *
     * Cashfree's SDK returns no signature to verify, so the order status is
     * fetched server-side and the paid amount is compared with our own row.
     * The client only ever supplies an order id — never an outcome.
     */
    public static function verifyCashfree(int $userId, string $orderId): array
    {
        $payment = Database::selectOne(
            "SELECT * FROM `payments` WHERE `gateway` = 'cashfree' AND `gateway_order_id` = :order_id",
            ['order_id' => $orderId]
        );

        if ($payment === null) {
            throw HttpException::notFound('Payment record not found.');
        }

        if ((int) $payment['user_id'] !== $userId) {
            throw HttpException::forbidden('This payment belongs to another account.');
        }

        if ($payment['status'] === 'captured') {
            return $payment; // Idempotent.
        }

        $order = self::cashfreeGet('/orders/' . rawurlencode($orderId));

        $orderStatus = strtoupper((string) ($order['order_status'] ?? ''));

        if ($orderStatus !== 'PAID') {
            throw HttpException::unprocessable(
                $orderStatus === 'ACTIVE'
                    ? 'The payment has not been completed yet.'
                    : 'The payment was not successful.'
            );
        }

        if (!Money::equals(Money::round((float) ($order['order_amount'] ?? 0)), (float) $payment['amount'])) {
            Logger::critical('Cashfree amount mismatch', [
                'payment_id' => $payment['id'],
                'expected'   => $payment['amount'],
                'received'   => $order['order_amount'] ?? null,
            ]);

            throw HttpException::unprocessable('The payment amount does not match the booking total.');
        }

        // The order says PAID; the payments collection carries the txn detail.
        $attempts = self::cashfreeGet('/orders/' . rawurlencode($orderId) . '/payments');
        $success  = self::firstSuccessfulCashfreePayment($attempts);

        return self::finalise(
            (int) $payment['id'],
            (string) ($success['cf_payment_id'] ?? $orderId),
            '',
            (string) ($success['payment_group'] ?? ''),
            $success !== [] ? $success : $order
        );
    }

    /**
     * Pick the captured attempt out of Cashfree's payment list.
     *
     * A payer may fail twice and succeed on the third try, so the list is
     * scanned rather than assuming index 0.
     *
     * @param  array<mixed> $attempts
     * @return array<string,mixed>
     */
    private static function firstSuccessfulCashfreePayment(array $attempts): array
    {
        foreach ($attempts as $attempt) {
            if (is_array($attempt) && strtoupper((string) ($attempt['payment_status'] ?? '')) === 'SUCCESS') {
                return $attempt;
            }
        }

        return [];
    }

    /** @return array<string,mixed> */
    private static function cashfreeGet(string $path): array
    {
        [$appId, $secretKey] = self::cashfreeCredentials();

        $response = Http::get(
            self::cashfreeApiBase() . $path,
            self::cashfreeHeaders($appId, $secretKey),
            20
        );

        if (!Http::successful($response) || !is_array($response['json'])) {
            Logger::error('Cashfree lookup failed', ['path' => $path, 'status' => $response['status']]);

            throw HttpException::server('Could not confirm the payment with the gateway.');
        }

        return $response['json'];
    }

    /**
     * Verify a Cashfree webhook.
     *
     * Cashfree signs base64(HMAC-SHA256(timestamp . rawBody, secret)) and
     * sends the timestamp in its own header, so both are needed — a signature
     * alone cannot be checked.
     */
    private static function verifyCashfreeWebhookSignature(string $rawBody, string $signature, string $timestamp): bool
    {
        $secret = self::cashfreeSetting('secret_key');

        if ($secret === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        // Reject anything older than five minutes, as for Stripe.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string,mixed> $payload
     */
    private static function processCashfreeEvent(string $eventType, array $payload): string
    {
        $data    = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $order   = is_array($data['order'] ?? null) ? $data['order'] : [];
        $entity  = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $orderId = (string) ($order['order_id'] ?? '');

        if ($orderId === '') {
            return 'Event carried no order id.';
        }

        $payment = Database::selectOne(
            "SELECT * FROM `payments` WHERE `gateway` = 'cashfree' AND `gateway_order_id` = :order_id",
            ['order_id' => $orderId]
        );

        if ($payment === null) {
            return 'No matching payment record.';
        }

        if ($eventType === 'PAYMENT_SUCCESS_WEBHOOK') {
            if ($payment['status'] === 'captured') {
                return 'Already captured.';
            }

            // The webhook is authenticated but still untrusted about money:
            // a mismatch means something is wrong, not that we should credit.
            $paid = Money::round((float) ($entity['payment_amount'] ?? $order['order_amount'] ?? 0));

            if (!Money::equals($paid, (float) $payment['amount'])) {
                Logger::critical('Cashfree webhook amount mismatch', [
                    'payment_id' => $payment['id'],
                    'expected'   => $payment['amount'],
                    'received'   => $paid,
                ]);

                throw new \RuntimeException('Webhook amount does not match the payment record.');
            }

            self::finalise(
                (int) $payment['id'],
                (string) ($entity['cf_payment_id'] ?? ''),
                '',
                (string) ($entity['payment_group'] ?? ''),
                $entity
            );

            return 'Payment captured.';
        }

        if (in_array($eventType, ['PAYMENT_FAILED_WEBHOOK', 'PAYMENT_USER_DROPPED_WEBHOOK'], true)) {
            if ($payment['status'] === 'captured') {
                return 'Ignored: the payment is already captured.';
            }

            Database::update('payments', [
                'status'         => $eventType === 'PAYMENT_USER_DROPPED_WEBHOOK' ? 'cancelled' : 'failed',
                'failure_code'   => mb_substr((string) ($entity['error_details']['error_code'] ?? ''), 0, 80) ?: null,
                'failure_reason' => mb_substr((string) ($entity['payment_message'] ?? ''), 0, 500) ?: null,
                'failed_at'      => date('Y-m-d H:i:s'),
            ], ['id' => (int) $payment['id']]);

            return 'Payment marked ' . ($eventType === 'PAYMENT_USER_DROPPED_WEBHOOK' ? 'cancelled' : 'failed') . '.';
        }

        if ($eventType === 'REFUND_STATUS_WEBHOOK') {
            $refund = is_array($data['refund'] ?? null) ? $data['refund'] : [];

            return self::syncCashfreeRefund($refund);
        }

        return 'Event acknowledged.';
    }

    /**
     * Reconcile a refund whose gateway processing finished asynchronously.
     *
     * `refundBooking` marks a Cashfree refund `processing`; Cashfree confirms
     * minutes to days later, and this is where that lands.
     *
     * @param array<string,mixed> $refund
     */
    private static function syncCashfreeRefund(array $refund): string
    {
        $reference = (string) ($refund['refund_id'] ?? '');

        if ($reference === '') {
            return 'Refund event carried no refund id.';
        }

        $row = Database::selectOne(
            'SELECT * FROM `refunds` WHERE `reference` = :reference',
            ['reference' => $reference]
        );

        if ($row === null) {
            return 'No matching refund record.';
        }

        $status = strtoupper((string) ($refund['refund_status'] ?? ''));

        if ($status === 'SUCCESS') {
            if ($row['status'] === 'completed') {
                return 'Refund already completed.';
            }

            self::completeRefund((int) $row['id']);

            return 'Refund completed.';
        }

        if ($status === 'FAILED' || $status === 'CANCELLED') {
            Database::update('refunds', [
                'status'         => 'failed',
                'failure_reason' => mb_substr((string) ($refund['status_description'] ?? 'Gateway reported failure.'), 0, 500),
            ], ['id' => (int) $row['id']]);

            return 'Refund marked failed.';
        }

        return 'Refund event acknowledged.';
    }

    // -----------------------------------------------------------------
    // Settlement
    // -----------------------------------------------------------------

    /**
     * Mark a payment captured and apply its effect (confirm the booking or
     * credit the wallet). Idempotent by design.
     *
     * @param array<string,mixed> $gatewayResponse
     */
    private static function finalise(
        int $paymentId,
        string $gatewayPaymentId,
        string $signature,
        string $method,
        array $gatewayResponse
    ): array {
        return Database::transaction(static function () use ($paymentId, $gatewayPaymentId, $signature, $method, $gatewayResponse): array {
            $payment = Database::selectOne('SELECT * FROM `payments` WHERE `id` = :id FOR UPDATE', ['id' => $paymentId]);

            if ($payment === null) {
                throw HttpException::notFound('Payment record not found.');
            }

            if ($payment['status'] === 'captured') {
                return $payment;
            }

            Database::update('payments', [
                'gateway_payment_id' => $gatewayPaymentId,
                'gateway_signature'  => $signature !== '' ? $signature : null,
                'method'             => $method !== '' ? mb_substr($method, 0, 40) : null,
                'status'             => 'captured',
                'paid_at'            => date('Y-m-d H:i:s'),
                'gateway_response'   => json_encode(self::scrubGatewayResponse($gatewayResponse), JSON_UNESCAPED_UNICODE),
            ], ['id' => $paymentId]);

            if ($payment['purpose'] === 'booking' && $payment['booking_id'] !== null) {
                BookingService::markPaid((int) $payment['booking_id'], $paymentId);

                NotificationService::send((int) $payment['user_id'], 'payment.success', [
                    'amount'       => Money::groupIndian((float) $payment['amount'], 0),
                    'currency'     => Money::symbol(),
                    'booking_code' => (string) (Database::scalar(
                        'SELECT `code` FROM `bookings` WHERE `id` = :id',
                        ['id' => (int) $payment['booking_id']]
                    ) ?? ''),
                ], ['action_type' => 'booking', 'action_value' => (string) $payment['booking_id']]);
            } else {
                // Payments, then wallets: the order every money path takes.
                // The erasure closes the wallet under its own lock, so this
                // waits for it and then sees the committed state.
                $wallet = Database::selectOne(
                    'SELECT * FROM `wallets` WHERE `user_id` = :id FOR UPDATE',
                    ['id' => (int) $payment['user_id']]
                );

                $accountGone = Database::scalar(
                    'SELECT 1 FROM `users` WHERE `id` = :id AND `deleted_at` IS NOT NULL',
                    ['id' => (int) $payment['user_id']]
                ) !== null;

                if (!empty($wallet['closed_at']) || $accountGone) {
                    // The money cannot go into a deleted account: it goes back
                    // where it came from. A gateway failure leaves a `failed`
                    // refund for finance to settle.
                    $refundId = self::openRefundRecord($payment, null, (float) $payment['amount'], 'other', 'system', null, 'source');
                    Database::update('refunds', ['notes' => 'Account deleted before this top-up was confirmed'], ['id' => $refundId]);
                    self::settleRefund($refundId);

                    Logger::warning('A wallet top-up was captured after its account was deleted; it is being refunded to its source', [
                        'payment_id' => $paymentId,
                        'refund_id'  => $refundId,
                    ]);
                } else {
                    WalletService::credit((int) $payment['user_id'], (float) $payment['amount'], [
                        'category'        => 'deposit',
                        'description'     => 'Money added to wallet',
                        'reference_type'  => 'payment',
                        'reference_id'    => $paymentId,
                        'idempotency_key' => 'topup:payment:' . $paymentId,
                    ]);

                    NotificationService::send((int) $payment['user_id'], 'wallet.credited', [
                        'amount'      => Money::groupIndian((float) $payment['amount'], 0),
                        'currency'    => Money::symbol(),
                        'description' => 'Money added to your wallet.',
                    ], ['action_type' => 'wallet']);
                }
            }

            $updated = Database::selectOne('SELECT * FROM `payments` WHERE `id` = :id', ['id' => $paymentId]);

            if ($updated === null) {
                throw HttpException::server('Failed to finalise the payment.');
            }

            return $updated;
        });
    }

    // -----------------------------------------------------------------
    // Refunds
    // -----------------------------------------------------------------

    /**
     * Refund a booking, back to the original method or to the wallet.
     *
     * The original method is the default and the wallet only an exception —
     * see {@see refundDestination()} for when it applies.
     *
     * `$paymentId` pins the refund to one payment; leave it null to take the
     * booking's most recent refundable one, which is what every existing
     * caller wants.
     *
     * The payment row is locked before the refundable balance is read, so two
     * refunds raised at once cannot both pass the check — see
     * {@see refundableBalance()} for what that balance leaves out.
     */
    public static function refundBooking(
        int $bookingId,
        float $amount,
        string $reason,
        string $initiatedByType = 'system',
        ?int $initiatedById = null,
        string $destination = 'source',
        ?int $paymentId = null
    ): array {
        return Database::transaction(static function () use ($bookingId, $amount, $reason, $initiatedByType, $initiatedById, $destination, $paymentId): array {
            [$payment, $amount, $destination, $note] = self::prepareRefund($bookingId, $paymentId, $amount, $destination);

            return self::settleRefund(
                self::openRefundRecord($payment, $bookingId, $amount, $reason, $initiatedByType, $initiatedById, $destination, $note)
            );
        });
    }

    /**
     * Record a refund without moving any money yet.
     *
     * An approval workflow needs a real subject to hang off: `approval_requests`
     * points at a refund id, and the queue cannot label, link to or review a
     * refund that does not exist. So a refund that needs sign-off is written
     * here in the `pending` state, and only {@see settleParkedRefund()} — run
     * once the approvers are done — actually pays it out.
     * {@see abandonParkedRefund()} closes it out when they refuse.
     *
     * The same split serves a caller that must not call a gateway inside its
     * own transaction (a dispute resolution, say): record the refund here as
     * part of that transaction, commit, then settle it. A parked refund
     * already counts against the payment's refundable balance.
     *
     * @return array<string,mixed> The `pending` refunds row.
     */
    public static function parkRefundForApproval(
        int $bookingId,
        float $amount,
        string $reason,
        string $initiatedByType = 'admin',
        ?int $initiatedById = null,
        string $destination = 'source',
        ?int $paymentId = null,
        string $notes = ''
    ): array {
        return Database::transaction(static function () use ($bookingId, $amount, $reason, $initiatedByType, $initiatedById, $destination, $paymentId, $notes): array {
            [$payment, $amount, $destination, $note] = self::prepareRefund($bookingId, $paymentId, $amount, $destination);

            $notes = trim($notes . ' ' . $note);

            $refundId = self::openRefundRecord(
                $payment,
                $bookingId,
                $amount,
                $reason,
                $initiatedByType,
                $initiatedById,
                $destination,
                $notes
            );

            $refund = Database::selectOne('SELECT * FROM `refunds` WHERE `id` = :id', ['id' => $refundId]);

            if ($refund === null) {
                throw HttpException::server('Failed to record the refund.');
            }

            return $refund;
        });
    }

    /**
     * Pay out a refund that was parked for approval.
     *
     * @return array<string,mixed> The updated refunds row.
     */
    public static function settleParkedRefund(int $refundId): array
    {
        return Database::transaction(static fn (): array => self::settleRefund($refundId));
    }

    /** Close out a parked refund that will never be paid. */
    public static function abandonParkedRefund(int $refundId, string $reason): void
    {
        Database::execute(
            "UPDATE `refunds`
                SET `status` = 'failed', `failure_reason` = :reason, `processed_at` = :processed_at
              WHERE `id` = :id AND `status` = 'pending'",
            [
                'reason'       => mb_substr($reason, 0, 500),
                'processed_at' => date('Y-m-d H:i:s'),
                'id'           => $refundId,
            ]
        );
    }

    /**
     * The refund on this payment that is parked awaiting approval, if any.
     *
     * @return array<string,mixed>|null
     */
    public static function refundAwaitingApproval(int $paymentId): ?array
    {
        return Database::selectOne(
            "SELECT * FROM `refunds`
              WHERE `payment_id` = :payment_id AND `status` = 'pending'
              ORDER BY `id` DESC LIMIT 1",
            ['payment_id' => $paymentId]
        );
    }

    /**
     * The payment a booking refund comes out of.
     *
     * `partially_refunded` belongs here exactly as much as `captured` does: the
     * unrefunded balance is still refundable and the admin panel offers it.
     * Narrowing this to `captured` made a *second* partial refund impossible —
     * the controller allowed it, this lookup could never find the row again,
     * and the remaining balance was unreachable from the panel.
     *
     * Locks the row (FOR UPDATE): the caller holds the transaction, and the
     * lock is what keeps a second refund from reading the balance before the
     * first one's row exists.
     *
     * @return array<string,mixed>
     */
    private static function refundablePayment(int $bookingId, ?int $paymentId = null): array
    {
        // A booking can carry more than one payment, so a caller that already
        // knows which one it means says so rather than trusting "the latest".
        $bindings = ['booking_id' => $bookingId];

        if ($paymentId !== null) {
            $bindings['payment_id'] = $paymentId;
        }

        $payment = Database::selectOne(
            "SELECT * FROM `payments`
             WHERE `booking_id` = :booking_id AND `status` IN ('captured', 'partially_refunded')"
            . ($paymentId === null ? '' : ' AND `id` = :payment_id')
            . ' ORDER BY `id` DESC LIMIT 1 FOR UPDATE',
            $bindings
        );

        if ($payment === null) {
            throw HttpException::notFound('No refundable payment found for this booking.');
        }

        return $payment;
    }

    /**
     * Everything a new refund needs before its row is written: the locked
     * payment, the amount clamped to what is left, and where it goes.
     * Assumes the caller holds the transaction.
     *
     * @return array{0:array<string,mixed>,1:float,2:string,3:string}
     */
    private static function prepareRefund(int $bookingId, ?int $paymentId, float $amount, string $destination): array
    {
        $payment = self::refundablePayment($bookingId, $paymentId);
        $amount  = self::refundableAmount($payment, $amount);

        [$destination, $note] = self::refundDestination($payment, $destination);

        return [$payment, $amount, $destination, $note];
    }

    /** @param array<string,mixed> $payment */
    private static function refundableAmount(array $payment, float $amount): float
    {
        $amount = Money::clamp($amount, 0.0, self::refundableBalance($payment));

        if ($amount <= 0) {
            throw HttpException::unprocessable('There is nothing left to refund on this payment.');
        }

        return $amount;
    }

    /**
     * What can still be refunded on a payment: its amount, less what has been
     * refunded, less what is already on its way back.
     *
     * `amount_refunded` only moves when a refund completes, and Cashfree
     * confirms a refund days after accepting it. Without the refunds still
     * `pending` (parked for approval, or recorded and not yet sent) or
     * `processing` (accepted by the gateway, not yet confirmed), the full
     * amount stayed refundable meanwhile — and a second refund to the wallet,
     * which is instant, paid the same money back twice once the first one
     * completed. The admin booking and payments screens read the same figure.
     *
     * @param array<string,mixed> $payment A payments row.
     */
    public static function refundableBalance(array $payment): float
    {
        $inFlight = (float) (Database::scalar(
            "SELECT COALESCE(SUM(`amount`), 0) FROM `refunds`
              WHERE `payment_id` = :id AND `status` IN ('pending', 'processing')",
            ['id' => (int) $payment['id']]
        ) ?? 0);

        return Money::max(
            0.0,
            Money::subtract(
                Money::subtract((float) $payment['amount'], (float) $payment['amount_refunded']),
                $inFlight
            )
        );
    }

    /**
     * Where a refund goes: back to the original payment method, unless an
     * admin chose the wallet or the original method cannot take it.
     *
     * Brands pay each booking at the gateway and cannot top the wallet up
     * (wallet.topup_enabled, migration 056), so a refund parked in the wallet
     * is money the brand never chose to leave with us. The wallet is only:
     *
     *  - the destination of a wallet payment, whose original method IS the
     *    wallet;
     *  - an admin's explicit choice (`wallet`);
     *  - the fallback when the money cannot go back automatically: a gateway
     *    this class has no refund call for, or a payment row without the id
     *    that call needs. A deleted account's closed wallet is never a
     *    fallback — that refund stays on the source and fails for finance.
     *
     * A gateway that refuses a refund, or whose credentials are missing, is
     * not a fallback either: the refund is recorded `failed` and finance
     * re-issues it, to the wallet if need be. Falling back on an error could
     * pay twice when the gateway took the refund but the reply was lost, and
     * would quietly hide a misconfigured gateway.
     *
     * @param  array<string,mixed> $payment
     * @param  string $requested `source` (the default) or `wallet`.
     * @return array{0:string,1:string} The destination, and a note for the
     *         refund row when it is not the one requested.
     */
    private static function refundDestination(array $payment, string $requested): array
    {
        if ($payment['gateway'] === 'wallet' || $requested === 'wallet') {
            return ['wallet', ''];
        }

        if (self::refundableAtSource($payment)) {
            return ['source', ''];
        }

        $wallet  = WalletService::existing((int) $payment['user_id']);
        $deleted = Database::scalar(
            'SELECT 1 FROM `users` WHERE `id` = :id AND `deleted_at` IS NOT NULL',
            ['id' => (int) $payment['user_id']]
        ) !== null;

        if ($deleted || !empty($wallet['closed_at'])) {
            return ['source', ''];
        }

        return [
            'wallet',
            'The original payment (' . (string) $payment['gateway'] . ') cannot be refunded automatically, so this refund went to the Zusty wallet.',
        ];
    }

    /**
     * Whether the gateway can take this payment back automatically: it is one
     * {@see refundAtGateway()} has a refund call for, and the row carries the
     * id that call needs. Credentials are deliberately not consulted — see
     * {@see refundDestination()}.
     *
     * @param array<string,mixed> $payment
     */
    private static function refundableAtSource(array $payment): bool
    {
        return match ((string) $payment['gateway']) {
            'razorpay', 'stripe' => (string) ($payment['gateway_payment_id'] ?? '') !== '',
            'cashfree'           => (string) ($payment['gateway_order_id'] ?? '') !== '',
            default              => false,
        };
    }

    /**
     * Write the refund row and give it its reference. No money moves here.
     * Assumes the caller holds the transaction.
     *
     * @param  array<string,mixed> $payment
     * @return int The new refund id.
     */
    private static function openRefundRecord(
        array $payment,
        ?int $bookingId,
        float $amount,
        string $reason,
        string $initiatedByType,
        ?int $initiatedById,
        string $destination,
        string $notes = ''
    ): int {
        $refundId = Database::insert('refunds', [
            'reference'         => 'PENDING',
            'payment_id'        => (int) $payment['id'],
            'booking_id'        => $bookingId,
            'user_id'           => (int) $payment['user_id'],
            'amount'            => $amount,
            'currency'          => (string) $payment['currency'],
            'destination'       => $destination,
            'reason'            => $reason,
            'notes'             => $notes === '' ? null : mb_substr($notes, 0, 1000),
            'status'            => 'pending',
            'initiated_by_type' => $initiatedByType,
            'initiated_by_id'   => $initiatedById,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        Database::update('refunds', ['reference' => Str::referenceCode('ZRF', $refundId, 8)], ['id' => $refundId]);

        return $refundId;
    }

    /**
     * Move the money for a refund that has already been recorded.
     * Assumes the caller holds the transaction.
     *
     * @return array<string,mixed> The updated refunds row.
     */
    private static function settleRefund(int $refundId): array
    {
        $refund = Database::selectOne('SELECT * FROM `refunds` WHERE `id` = :id FOR UPDATE', ['id' => $refundId]);

        if ($refund === null) {
            throw HttpException::notFound('Refund not found.');
        }

        // Settling twice would pay twice. A row that has left `pending` has
        // already been through here.
        if ((string) $refund['status'] !== 'pending') {
            return $refund;
        }

        $payment = Database::selectOne(
            'SELECT * FROM `payments` WHERE `id` = :id',
            ['id' => (int) $refund['payment_id']]
        );

        if ($payment === null) {
            throw HttpException::notFound('The payment behind this refund no longer exists.');
        }

        $amount      = (float) $refund['amount'];
        $reference   = (string) $refund['reference'];
        $destination = (string) $refund['destination'];
        $bookingId   = $refund['booking_id'] === null ? null : (int) $refund['booking_id'];

        $settled         = false;
        $accepted        = false;
        $gatewayRefundId = null;
        $failureReason   = null;

        if ($destination === 'wallet') {
            WalletService::credit((int) $payment['user_id'], $amount, [
                'category'        => 'refund',
                'description'     => 'Refund for booking #' . ($bookingId === null ? $reference : (string) (Database::scalar(
                    'SELECT `code` FROM `bookings` WHERE `id` = :id',
                    ['id' => $bookingId]
                ) ?? $bookingId)),
                'reference_type'  => 'refund',
                'reference_id'    => $refundId,
                'idempotency_key' => 'refund:' . $refundId,
            ]);

            $settled  = true;
            $accepted = true;
        } else {
            try {
                $result          = self::refundAtGateway($payment, $amount, $reference);
                $gatewayRefundId = $result['id'];
                $accepted        = true;
                $settled         = $result['settled'];
            } catch (\Throwable $e) {
                $failureReason = $e->getMessage();
                Logger::error('Gateway refund failed', ['refund_id' => $refundId, 'error' => $failureReason]);
            }
        }

        Database::update('refunds', [
            // A refund the gateway accepted but has not settled stays
            // `processing`; the REFUND_STATUS webhook closes it out.
            'status'            => match (true) {
                $settled  => 'completed',
                $accepted => 'processing',
                default   => 'failed',
            },
            'gateway_refund_id' => $gatewayRefundId,
            'failure_reason'    => $failureReason === null ? null : mb_substr($failureReason, 0, 500),
            'processed_at'      => $settled ? date('Y-m-d H:i:s') : null,
        ], ['id' => $refundId]);

        if ($settled) {
            self::applyRefundEffects($refundId);
        }

        $updated = Database::selectOne('SELECT * FROM `refunds` WHERE `id` = :id', ['id' => $refundId]);

        if ($updated === null) {
            throw HttpException::server('Failed to record the refund.');
        }

        return $updated;
    }

    /**
     * Mark an accepted refund settled and apply its effects.
     *
     * Split out of [refundBooking] because Cashfree confirms refunds
     * asynchronously — the webhook lands minutes or days later and needs the
     * same bookkeeping.
     */
    public static function completeRefund(int $refundId): void
    {
        Database::transaction(static function () use ($refundId): void {
            $refund = Database::selectOne(
                'SELECT * FROM `refunds` WHERE `id` = :id FOR UPDATE',
                ['id' => $refundId]
            );

            if ($refund === null || $refund['status'] === 'completed') {
                return; // Idempotent — a replayed webhook is a no-op.
            }

            // A refund still parked for approval was never sent to a gateway,
            // so no gateway can be reporting on it. An inbound event must not
            // release money the approvers have not signed off.
            if ($refund['status'] === 'pending') {
                Logger::warning('Ignored a completion event for an unapproved refund', [
                    'refund_id' => $refundId,
                ]);

                return;
            }

            Database::update('refunds', [
                'status'         => 'completed',
                'failure_reason' => null,
                'processed_at'   => date('Y-m-d H:i:s'),
            ], ['id' => $refundId]);

            self::applyRefundEffects($refundId);
        });
    }

    /**
     * Roll a completed refund up onto its payment and booking, and tell the
     * payer. Assumes the caller holds the transaction.
     */
    private static function applyRefundEffects(int $refundId): void
    {
        $refund = Database::selectOne('SELECT * FROM `refunds` WHERE `id` = :id', ['id' => $refundId]);

        if ($refund === null) {
            return;
        }

        $payment = Database::selectOne(
            'SELECT * FROM `payments` WHERE `id` = :id FOR UPDATE',
            ['id' => (int) $refund['payment_id']]
        );

        if ($payment === null) {
            return;
        }

        $amount        = (float) $refund['amount'];
        $totalRefunded = Money::add((float) $payment['amount_refunded'], $amount);
        $isFull        = Money::equals($totalRefunded, (float) $payment['amount']);

        Database::update('payments', [
            'amount_refunded' => $totalRefunded,
            'status'          => $isFull ? 'refunded' : 'partially_refunded',
        ], ['id' => (int) $payment['id']]);

        if ($refund['booking_id'] !== null) {
            // Retire the platform's accrual for the money that went back.
            // Without this the fee and commission on a refunded booking kept
            // counting as revenue for ever, on the dashboard and in every
            // finance report. Driven by the cumulative refunded share so a
            // second partial refund scales the original figures rather than
            // compounding against already-reduced ones.
            EarningsService::applyRefund(
                (int) $refund['booking_id'],
                (float) $payment['amount'] > 0.0 ? $totalRefunded / (float) $payment['amount'] : 1.0
            );

            $columns = ['payment_status' => $isFull ? 'refunded' : 'partially_refunded'];

            // Only a full refund empties the escrow. A partial one leaves the
            // rest of the money held, and marking that booking `refunded` told
            // every escrow-release and payout-maturation path that there was
            // nothing left to release.
            //
            // "Held" is only true while the booking is still running, though.
            // A gateway refund completes days after it was raised (Cashfree's
            // webhook), by which time a dispute resolution or a cancellation
            // has already closed the booking and settled its escrow. Writing
            // `held` onto that finished booking counted its money as escrow on
            // the dashboard and in the app, so a finished booking keeps the
            // escrow status it was closed with.
            $bookingStatus = (string) (Database::scalar(
                'SELECT `status` FROM `bookings` WHERE `id` = :id',
                ['id' => (int) $refund['booking_id']]
            ) ?? '');

            if ($isFull) {
                $columns['escrow_status'] = 'refunded';
            } elseif (in_array($bookingStatus, self::ESCROW_HELD_STATUSES, true)) {
                $columns['escrow_status'] = 'held';
            }

            Database::update('bookings', $columns, ['id' => (int) $refund['booking_id']]);
        }

        NotificationService::send((int) $payment['user_id'], 'payment.refunded', [
            'amount'      => Money::groupIndian($amount, 0),
            'currency'    => Money::symbol(),
            'destination' => $refund['destination'] === 'wallet' ? 'Zusty wallet' : 'original payment method',
        ], ['action_type' => 'wallet']);
    }

    /**
     * Ask the gateway for a refund.
     *
     * @param  array<string,mixed> $payment
     * @param  string $reference Our refund reference, reused as the gateway's
     *                           idempotency key where the API supports one.
     * @return array{id:?string,settled:bool} `settled` is false when the
     *         gateway accepted the request but will confirm it later.
     */
    private static function refundAtGateway(array $payment, float $amount, string $reference): array
    {
        if ($payment['gateway'] === 'razorpay') {
            [$keyId, $keySecret] = self::razorpayCredentials();

            $response = Http::post(
                (string) Config::get('payment.razorpay.api_base') . '/payments/' . (string) $payment['gateway_payment_id'] . '/refund',
                ['amount' => Money::toMinorUnits($amount), 'speed' => 'normal', 'receipt' => $reference],
                ['Content-Type' => 'application/json'],
                30,
                [$keyId, $keySecret]
            );

            if (!Http::successful($response) || empty($response['json']['id'])) {
                throw new \RuntimeException('Razorpay refused the refund.');
            }

            return ['id' => (string) $response['json']['id'], 'settled' => true];
        }

        if ($payment['gateway'] === 'stripe') {
            $response = Http::post(
                (string) Config::get('payment.stripe.api_base') . '/refunds',
                ['charge' => (string) $payment['gateway_payment_id'], 'amount' => Money::toMinorUnits($amount)],
                [
                    'Authorization' => 'Bearer ' . self::stripeSecret(),
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Idempotency-Key' => $reference,
                ],
                30
            );

            if (!Http::successful($response) || empty($response['json']['id'])) {
                throw new \RuntimeException('Stripe refused the refund.');
            }

            return ['id' => (string) $response['json']['id'], 'settled' => true];
        }

        if ($payment['gateway'] === 'cashfree') {
            [$appId, $secretKey] = self::cashfreeCredentials();

            $response = Http::post(
                self::cashfreeApiBase() . '/orders/' . rawurlencode((string) $payment['gateway_order_id']) . '/refunds',
                [
                    'refund_amount' => Money::round($amount),
                    'refund_id'     => $reference,
                    'refund_note'   => 'Booking refund',
                    'refund_speed'  => 'STANDARD',
                ],
                self::cashfreeHeaders($appId, $secretKey),
                30
            );

            if (!Http::successful($response) || empty($response['json']['cf_refund_id'])) {
                throw new \RuntimeException(
                    (string) ($response['json']['message'] ?? 'Cashfree refused the refund.')
                );
            }

            // Cashfree returns PENDING/ONHOLD first and confirms by webhook;
            // only SUCCESS here means the money has actually moved.
            $status = strtoupper((string) ($response['json']['refund_status'] ?? 'PENDING'));

            return [
                'id'      => (string) $response['json']['cf_refund_id'],
                'settled' => $status === 'SUCCESS',
            ];
        }

        throw new \RuntimeException('This gateway does not support automated refunds.');
    }

    // -----------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------

    /**
     * Persist and process a gateway webhook.
     *
     * @return array{handled:bool,message:string}
     */
    public static function handleWebhook(
        string $gateway,
        string $rawBody,
        string $signature,
        string $timestamp = ''
    ): array {
        $verified = match ($gateway) {
            'razorpay' => self::verifyRazorpayWebhookSignature($rawBody, $signature),
            'stripe'   => self::verifyStripeWebhookSignature($rawBody, $signature),
            'cashfree' => self::verifyCashfreeWebhookSignature($rawBody, $signature, $timestamp),
            default    => false,
        };

        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];

        $eventId   = self::webhookEventId($gateway, $payload);
        $eventType = (string) ($payload['event'] ?? ($payload['type'] ?? 'unknown'));

        // What is stored never carries the payer's email, phone or UPI ID.
        $stored = self::storableWebhookPayload($rawBody, $payload);

        // Reject BEFORE touching `payment_webhooks`.
        //
        // The de-dup key is (gateway, event_id). If an unverified event were
        // inserted first, an attacker could post a forgery carrying the event id
        // of a payment they were about to make; the forgery is refused, but its
        // row survives and the genuine webhook that follows collides with the
        // unique key and is dismissed as a duplicate — so a real payment would
        // never be processed. The rejection is logged with a random id so the
        // audit trail is kept without burning the real one.
        if (!$verified) {
            Database::insertIgnore('payment_webhooks', [
                'gateway'       => $gateway,
                'event_id'      => 'rejected:' . Crypto::uuid4(),
                'event_type'    => $eventType,
                'signature'     => mb_substr($signature, 0, 500),
                'payload'       => $stored,
                'is_verified'   => 0,
                'status'        => 'failed',
                'error_message' => 'Signature verification failed.',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            Logger::warning('Rejected an unverified payment webhook', [
                'gateway' => $gateway,
                'event'   => $eventType,
            ]);

            throw HttpException::unauthorized('Invalid webhook signature.', 'invalid_signature');
        }

        $webhookId = Database::insertIgnore('payment_webhooks', [
            'gateway'     => $gateway,
            'event_id'    => $eventId,
            'event_type'  => $eventType,
            'signature'   => mb_substr($signature, 0, 500),
            'payload'     => $stored,
            'is_verified' => 1,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        if ($webhookId === 0) {
            return ['handled' => true, 'message' => 'Duplicate event ignored.'];
        }

        try {
            $message = match ($gateway) {
                'razorpay' => self::processRazorpayEvent($eventType, $payload),
                'cashfree' => self::processCashfreeEvent($eventType, $payload),
                default    => self::processStripeEvent($eventType, $payload),
            };

            Database::update('payment_webhooks', [
                'status'       => 'processed',
                'processed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $webhookId]);

            return ['handled' => true, 'message' => $message];
        } catch (\Throwable $e) {
            Database::update('payment_webhooks', [
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ], ['id' => $webhookId]);

            Logger::error('Webhook processing failed', ['gateway' => $gateway, 'event' => $eventType, 'error' => $e->getMessage()]);

            return ['handled' => false, 'message' => 'Processing failed; the event has been stored for replay.'];
        }
    }

    /** @param array<string,mixed> $payload */
    private static function processRazorpayEvent(string $eventType, array $payload): string
    {
        $entity = $payload['payload']['payment']['entity'] ?? null;

        if (!is_array($entity)) {
            return 'No payment entity in the event.';
        }

        $orderId = (string) ($entity['order_id'] ?? '');

        if ($orderId === '') {
            return 'Event carried no order id.';
        }

        $payment = Database::selectOne(
            "SELECT * FROM `payments` WHERE `gateway` = 'razorpay' AND `gateway_order_id` = :order_id",
            ['order_id' => $orderId]
        );

        if ($payment === null) {
            return 'No matching payment record.';
        }

        if (in_array($eventType, ['payment.captured', 'order.paid'], true)) {
            if ($payment['status'] === 'captured') {
                return 'Already captured.';
            }

            self::finalise(
                (int) $payment['id'],
                (string) ($entity['id'] ?? ''),
                '',
                (string) ($entity['method'] ?? ''),
                $entity
            );

            return 'Payment captured.';
        }

        if ($eventType === 'payment.failed') {
            Database::update('payments', [
                'status'         => 'failed',
                'failure_code'   => mb_substr((string) ($entity['error_code'] ?? ''), 0, 80),
                'failure_reason' => mb_substr((string) ($entity['error_description'] ?? ''), 0, 500),
                'failed_at'      => date('Y-m-d H:i:s'),
            ], ['id' => (int) $payment['id']]);

            return 'Payment marked failed.';
        }

        return 'Event acknowledged.';
    }

    /** @param array<string,mixed> $payload */
    private static function processStripeEvent(string $eventType, array $payload): string
    {
        $object = $payload['data']['object'] ?? null;

        if (!is_array($object)) {
            return 'No object in the event.';
        }

        $intentId = (string) ($object['id'] ?? '');

        $payment = Database::selectOne(
            "SELECT * FROM `payments` WHERE `gateway` = 'stripe' AND `gateway_order_id` = :intent_id",
            ['intent_id' => $intentId]
        );

        if ($payment === null) {
            return 'No matching payment record.';
        }

        if ($eventType === 'payment_intent.succeeded') {
            if ($payment['status'] === 'captured') {
                return 'Already captured.';
            }

            self::finalise(
                (int) $payment['id'],
                (string) ($object['latest_charge'] ?? $intentId),
                '',
                (string) ($object['payment_method_types'][0] ?? 'card'),
                $object
            );

            return 'Payment captured.';
        }

        if ($eventType === 'payment_intent.payment_failed') {
            Database::update('payments', [
                'status'         => 'failed',
                'failure_reason' => mb_substr((string) ($object['last_payment_error']['message'] ?? ''), 0, 500),
                'failed_at'      => date('Y-m-d H:i:s'),
            ], ['id' => (int) $payment['id']]);

            return 'Payment marked failed.';
        }

        return 'Event acknowledged.';
    }

    /**
     * Razorpay signs the raw body with the webhook secret (HMAC-SHA256, hex).
     *
     * The empty-secret case is the whole reason this is not a one-liner:
     * `hash_hmac('sha256', $body, '')` is a perfectly computable value, so
     * comparing against it would authenticate ANY caller when the secret has
     * not been configured. Refuse instead.
     */
    private static function verifyRazorpayWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = self::razorpayWebhookSecret();

        if ($secret === '' || $signature === '') {
            Logger::warning('Razorpay webhook rejected: no webhook secret is configured');

            return false;
        }

        return Crypto::verifySignature($rawBody, $signature, $secret);
    }

    /** Stripe signs as `t=<ts>,v1=<hmac>` over "<ts>.<body>". */
    private static function verifyStripeWebhookSignature(string $rawBody, string $signatureHeader): bool
    {
        $secret = self::stripeSetting('webhook_secret');

        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $timestamp = '';
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === '' || $signatures === []) {
            return false;
        }

        // Reject events older than 5 minutes to blunt replay attacks.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A stable id for the event, so a redelivery is recognised as a duplicate
     * by the unique (gateway, event_id) key rather than processed twice.
     *
     * Only Stripe supplies a top-level event id; the others are derived from
     * the entity the event is about, which is stable across redeliveries.
     *
     * @param array<string,mixed> $payload
     */
    private static function webhookEventId(string $gateway, array $payload): string
    {
        if ($gateway === 'cashfree') {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $type = (string) ($payload['type'] ?? 'event');

            $handle = (string) (
                ($data['payment']['cf_payment_id'] ?? null)
                ?? ($data['refund']['cf_refund_id'] ?? null)
                ?? ($data['order']['order_id'] ?? '')
            );

            // A single order legitimately raises several event types, so the
            // type is part of the key — otherwise a refund event would be
            // swallowed as a duplicate of the payment event.
            return $handle !== '' ? $type . ':' . $handle : Crypto::uuid4();
        }

        return (string) (
            $payload['id']
            ?? ($payload['payload']['payment']['entity']['id'] ?? Crypto::uuid4())
        );
    }

    // -- Cashfree configuration ---------------------------------------

    /** @return array{0:string,1:string} */
    private static function cashfreeCredentials(): array
    {
        $appId  = self::cashfreeSetting('app_id');
        $secret = self::cashfreeSetting('secret_key');

        if ($appId === '' || $secret === '') {
            throw HttpException::server('Cashfree is not configured. Add the credentials in Admin → Settings → Payments.');
        }

        return [$appId, $secret];
    }

    /** @return array<string,string> */
    private static function cashfreeHeaders(string $appId, string $secretKey): array
    {
        return [
            'Content-Type'    => 'application/json',
            'x-api-version'   => (string) Config::get('payment.cashfree.api_version', '2023-08-01'),
            'x-client-id'     => $appId,
            'x-client-secret' => $secretKey,
        ];
    }

    /** `sandbox` unless an admin has explicitly switched to production. */
    private static function cashfreeMode(): string
    {
        $mode = strtolower(self::cashfreeSetting('mode'));

        return $mode === 'production' ? 'production' : 'sandbox';
    }

    private static function cashfreeApiBase(): string
    {
        $bases = Config::get('payment.cashfree.api_base', []);
        $mode  = self::cashfreeMode();

        return is_array($bases) && isset($bases[$mode])
            ? (string) $bases[$mode]
            : 'https://sandbox.cashfree.com/pg';
    }

    /**
     * Cashfree's hosted checkout for a session.
     *
     * Using the hosted page rather than a native SDK keeps checkout working
     * identically on Android, iOS and web, and keeps card data off our client
     * entirely.
     */
    private static function cashfreeCheckoutUrl(string $sessionId): string
    {
        $host = self::cashfreeMode() === 'production'
            ? 'https://payments.cashfree.com'
            : 'https://payments-test.cashfree.com';

        return $host . '/order/#' . $sessionId;
    }

    /** Where Cashfree posts its server-to-server events. */
    private static function cashfreeNotifyUrl(): string
    {
        return rtrim((string) Config::get('app.url', ''), '/') . '/api/v1/webhooks/cashfree';
    }

    private static function cashfreeSetting(string $key): string
    {
        $value = Config::settingString('payment', 'cashfree_' . $key);

        return $value !== '' ? $value : (string) Config::get('payment.cashfree.' . $key, '');
    }

    /**
     * Cashfree requires a phone number on every order, so this fails loudly
     * with something the user can act on rather than letting the gateway
     * return an opaque validation error at checkout.
     *
     * The account's own number wins. An account without one — every email
     * or Google sign-up, since sign-up is email-only and SMS is off, so a
     * number cannot be verified — may give one at checkout as
     * [$checkoutPhone]: an Indian mobile, used for this order only. It is
     * never written to the account and never counts as verified, so it
     * cannot be used to sign in or to claim someone else's number.
     *
     * @return array<string,string>
     */
    private static function cashfreeCustomer(int $userId, ?string $checkoutPhone = null): array
    {
        $user = Database::selectOne(
            'SELECT `uuid`, `name`, `email`, `phone`, `phone_country_code`
             FROM `users` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $userId]
        );

        if ($user === null) {
            throw HttpException::notFound('Account not found.');
        }

        $phone = preg_replace('/\D+/', '', (string) ($user['phone'] ?? '')) ?? '';

        if ($phone === '' && $checkoutPhone !== null && trim($checkoutPhone) !== '') {
            $phone = self::checkoutMobile($checkoutPhone);
        }

        if ($phone === '') {
            $message = 'Enter a mobile number for this payment. Our payment partner needs one on every order.';

            throw new HttpException($message, 422, 'phone_required', ['customer_phone' => [$message]]);
        }

        $customer = [
            'customer_id'    => (string) $user['uuid'],
            'customer_phone' => $phone,
            'customer_name'  => mb_substr((string) $user['name'], 0, 100),
        ];

        if (!empty($user['email'])) {
            $customer['customer_email'] = (string) $user['email'];
        }

        return $customer;
    }

    /**
     * A checkout mobile number as Cashfree takes it: ten digits, Indian
     * mobile range (6–9 first). "+91 98765 43210", "09876543210" and
     * "9876543210" all come out as 9876543210; anything else is a 422 on
     * `customer_phone`.
     */
    private static function checkoutMobile(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (preg_match('/^[6-9]\d{9}$/', $digits) !== 1) {
            $message = 'Enter a valid 10-digit Indian mobile number.';

            throw new HttpException($message, 422, 'phone_invalid', ['customer_phone' => [$message]]);
        }

        return $digits;
    }

    /** @param array<string,mixed> $data */
    private static function recordPayment(array $data): int
    {
        $paymentId = Database::insert('payments', array_merge([
            'reference'  => 'PENDING',
            'currency'   => Money::defaultCurrency(),
            'created_at' => date('Y-m-d H:i:s'),
        ], $data));

        Database::update('payments', ['reference' => Str::referenceCode('ZPAY', $paymentId, 8)], ['id' => $paymentId]);

        return $paymentId;
    }

    /**
     * Keys dropped from a gateway payload at any depth: raw card data and
     * gateway tokens, and the payer's contact details (email, phone, UPI ID,
     * name). Ids, statuses, amounts, the method and error codes stay.
     */
    private const GATEWAY_SCRUBBED_KEYS = [
        'card', 'payment_method_details', 'client_secret', 'source', 'charges',
        'email', 'contact', 'vpa', 'upi_id', 'receipt_email', 'billing_details', 'shipping',
        'customer_details', 'customer_email', 'customer_phone', 'customer_name',
    ];

    /**
     * Never persist raw card data, gateway tokens or the payer's contact
     * details in our own database. Recursive, so webhook envelopes are
     * covered too (payload.payment.entity, data.customer_details, ...).
     *
     * @param  array<mixed> $response
     * @return array<mixed>
     */
    public static function scrubGatewayResponse(array $response): array
    {
        foreach ($response as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::GATEWAY_SCRUBBED_KEYS, true)) {
                unset($response[$key]);

                continue;
            }

            if (is_array($value)) {
                $response[$key] = self::scrubGatewayResponse($value);
            }
        }

        return $response;
    }

    /**
     * The webhook body as it is kept in `payment_webhooks`: scrubbed and
     * re-encoded when it is JSON. Processing always uses the decoded,
     * unscrubbed payload.
     *
     * @param array<mixed> $payload
     */
    private static function storableWebhookPayload(string $rawBody, array $payload): string
    {
        // Not JSON (or empty): there is nothing to scrub.
        if ($payload === []) {
            return $rawBody;
        }

        $encoded = json_encode(self::scrubGatewayResponse($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '{}' : $encoded;
    }

    /** @return array{0:string,1:string} */
    private static function razorpayCredentials(): array
    {
        $keyId  = Config::settingString('payment', 'razorpay_key_id') ?: (string) Config::get('payment.razorpay.key_id', '');
        $secret = Config::settingString('payment', 'razorpay_key_secret') ?: (string) Config::get('payment.razorpay.key_secret', '');

        if ($keyId === '' || $secret === '') {
            throw HttpException::server('Razorpay is not configured. Add the credentials in Admin → Settings → Payments.');
        }

        return [$keyId, $secret];
    }

    private static function razorpayWebhookSecret(): string
    {
        return Config::settingString('payment', 'razorpay_webhook_secret')
            ?: (string) Config::get('payment.razorpay.webhook_secret', '');
    }

    private static function stripeSecret(): string
    {
        $secret = self::stripeSetting('secret_key');

        if ($secret === '') {
            throw HttpException::server('Stripe is not configured. Add the credentials in Admin → Settings → Payments.');
        }

        return $secret;
    }

    private static function stripeSetting(string $key): string
    {
        $value = Config::settingString('payment', 'stripe_' . $key);

        return $value !== '' ? $value : (string) Config::get('payment.stripe.' . $key, '');
    }

    /** Which gateways the client should offer at checkout. */
    public static function availableGateways(): array
    {
        $gateways = [];

        // `allowed_methods` has always listed `wallet` too. Under a card
        // gateway it read as "pay with your Zusty wallet here", which is not
        // something the gateway's page offers — the wallet is its own row.
        $instruments = static fn (array $default): array => array_values(array_diff(
            Config::settingArray('payment', 'allowed_methods', $default),
            ['wallet']
        ));

        if (Config::settingBool('payment', 'razorpay_enabled', false)) {
            $gateways[] = [
                'id'      => 'razorpay',
                'label'   => 'Card / UPI / Net Banking',
                'methods' => $instruments(['card', 'upi', 'netbanking']),
                'key_id'  => Config::settingString('payment', 'razorpay_key_id'),
            ];
        }

        if (Config::settingBool('payment', 'cashfree_enabled', false)) {
            $gateways[] = [
                'id'      => 'cashfree',
                'label'   => 'UPI / Card / Net Banking',
                'methods' => $instruments(['upi', 'card', 'netbanking']),
                'app_id'  => self::cashfreeSetting('app_id'),
                'mode'    => self::cashfreeMode(),
            ];
        }

        if (Config::settingBool('payment', 'stripe_enabled', false)) {
            $gateways[] = [
                'id'              => 'stripe',
                'label'           => 'Card',
                'methods'         => ['card'],
                'publishable_key' => self::stripeSetting('publishable_key'),
            ];
        }

        if (Config::settingBool('payment', 'allow_wallet_payment', true)) {
            $gateways[] = ['id' => 'wallet', 'label' => 'Zusty Wallet', 'methods' => ['wallet']];
        }

        return $gateways;
    }
}

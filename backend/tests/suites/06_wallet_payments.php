<?php

declare(strict_types=1);

/**
 * Wallet, coupons and the booking payment path.
 *
 * This file did not exist. The suite ran 1,357 checks across auth, discovery,
 * account, bookings, campaigns, messaging, notifications, CMS, KYC, security
 * and admin sync — and not one of them touched money. Every wallet read, every
 * top-up, every withdrawal and the entire booking-payment path were unproven.
 *
 * Two things are worth stating about what is checked here:
 *
 *  - the CHARGED amount is asserted, not the response's `success` flag. A
 *    coupon that reports "Applied" and then bills full price is the exact bug
 *    that made this coverage necessary, and only comparing the wallet debit
 *    against the quoted total catches it;
 *  - no live gateway is called. Card gateways need real credentials, so those
 *    paths are checked up to the point of the outbound call — validation,
 *    ownership, state — and the wallet gateway, which settles entirely
 *    server-side, carries the end-to-end assertions.
 */

use Zuste\Core\Database;
use Zuste\Core\RateLimiter;
use Zuste\Helpers\Money;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// -----------------------------------------------------------------
// Fixtures
//
// Paying needs a brand with approved KYC, an approved creator who will accept
// the booking, and a funded wallet. All of it is torn down at the end.
// -----------------------------------------------------------------

$wpBrand   = Actors::create('brand', 'brand');
$wpCreator = Actors::create('creator', 'creator');

$wpBrandId   = Actors::id('brand');
$wpCreatorId = Actors::id('creator');

if ($wpBrand === null || $wpCreator === null || $wpBrandId <= 0 || $wpCreatorId <= 0) {
    Suite::skipGroup('Wallet and payments', 'no sessions: ' . Actors::lastError());
    return;
}

/** Rows this suite created, removed in the teardown. */
$wpBookings    = [];
$wpCoupons     = [];
$wpKycInserted = [];

$wpApproveKyc = static function (int $userId, string $kind) use (&$wpKycInserted): void {
    $now      = date('Y-m-d H:i:s');
    $existing = Database::selectOne(
        'SELECT `id` FROM `kyc_profiles` WHERE `user_id` = :u AND `kind` = :k',
        ['u' => $userId, 'k' => $kind]
    );

    if ($existing === null) {
        Database::insert('kyc_profiles', [
            'user_id'              => $userId,
            'kind'                 => $kind,
            'status'               => 'approved',
            'reference'            => 'KYC-IT-W' . strtoupper($kind[0]) . '-' . $userId,
            'submitted_at'         => $now,
            'approved_at'          => $now,
            'submission_count'     => 1,
            'completeness'         => 100,
            'is_identity_verified' => 1,
            'created_at'           => $now,
        ]);

        $wpKycInserted[] = $userId;

        return;
    }

    Database::update(
        'kyc_profiles',
        ['status' => 'approved', 'approved_at' => $now, 'expires_on' => null],
        ['id' => (int) $existing['id']]
    );
};

$wpApproveKyc($wpBrandId, 'brand');
$wpApproveKyc($wpCreatorId, 'creator');

// Paying and withdrawing additionally need `verified` — a confirmed email or
// phone. Registration leaves both null and confirming one needs an OTP that
// only reaches a real inbox, so it is stamped directly for these throwaway
// accounts. Reverted in the teardown.
Database::execute(
    'UPDATE `users` SET `email_verified_at` = NOW() WHERE `id` IN (:brand, :creator)',
    ['brand' => $wpBrandId, 'creator' => $wpCreatorId]
);

$wpCreatorRow = Database::selectOne(
    'SELECT `id`, `status` FROM `creators` WHERE `user_id` = :u AND `deleted_at` IS NULL',
    ['u' => $wpCreatorId]
);

$wpOriginalCreatorStatus = $wpCreatorRow === null ? null : (string) $wpCreatorRow['status'];
$wpCreatorProfileId      = $wpCreatorRow === null ? 0 : (int) $wpCreatorRow['id'];

if ($wpCreatorRow !== null) {
    // The shared `it-creator` actor is used by other suites, and 02_discovery
    // asserts that its profile is `draft` and therefore invisible. Approving it
    // here is necessary — a draft creator cannot be booked — but leaving it
    // approved breaks that suite on the NEXT run, which is a confusing failure
    // a long way from its cause.
    //
    // The restore is registered as a shutdown function as well as running in
    // the teardown below, because the teardown does not run when a statement
    // between here and there dies: an earlier version of this file threw on a
    // bad column name and left the actor approved for every subsequent run.
    register_shutdown_function(static function () use ($wpCreatorProfileId, $wpOriginalCreatorStatus): void {
        try {
            Database::update('creators', ['status' => $wpOriginalCreatorStatus], ['id' => $wpCreatorProfileId]);
        } catch (\Throwable) {
            // Nothing useful to do during shutdown.
        }
    });

    Database::update(
        'creators',
        ['status' => 'approved', 'availability_status' => 'available'],
        ['id' => $wpCreatorProfileId]
    );
}

RateLimiter::clear('throttle:booking:user:' . $wpBrandId);
RateLimiter::clear('throttle:coupon:user:' . $wpBrandId);

foreach ([$wpBrandId, $wpCreatorId] as $wpUserId) {
    RateLimiter::clear('throttle:global:user:' . $wpUserId);
}

/**
 * Creates a booking and walks it to `accepted`, which is the only state from
 * which it can be paid.
 *
 * @return array{0:int,1:float} the booking id and its total
 */
$wpPayableBooking = static function (float $amount) use ($wpCreatorProfileId, &$wpBookings): array {
    $create = Http::post('/bookings', [
        'creator_id'       => $wpCreatorProfileId,
        'title'            => 'IT payment path',
        'description'      => 'Integration fixture for the wallet and coupon checks.',
        'platform'         => 'instagram',
        'deliverable_type' => 'reel',
        'posts_count'      => 1,
        'amount'           => $amount,
    ], 'brand');

    $id = (int) ($create->at('data.booking.id') ?? 0);

    if ($id <= 0) {
        return [0, 0.0];
    }

    $wpBookings[] = $id;

    Http::post("/bookings/{$id}/accept", [], 'creator');

    $total = (float) (Database::scalar(
        'SELECT `total_amount` FROM `bookings` WHERE `id` = :id',
        ['id' => $id]
    ) ?? 0);

    return [$id, $total];
};

/** Tops the brand's wallet up directly — the API route needs a live gateway. */
$wpFundWallet = static function (int $userId, float $amount): void {
    Database::execute(
        'INSERT INTO `wallets` (`user_id`, `balance`, `locked_balance`, `currency`, `created_at`)
         VALUES (:id, :bal, 0, "INR", NOW())
         ON DUPLICATE KEY UPDATE `balance` = `balance` + VALUES(`balance`)',
        ['id' => $userId, 'bal' => $amount]
    );
};

$wpBalance = static function (int $userId): float {
    return (float) (Database::scalar(
        'SELECT `balance` FROM `wallets` WHERE `user_id` = :id',
        ['id' => $userId]
    ) ?? 0);
};

// -----------------------------------------------------------------
Suite::group('Wallet — reading the balance');

$wallet = Http::get('/wallet', [], 'brand');
Suite::ok('GET /wallet returns the caller\'s wallet', $wallet);
Suite::check(
    'the wallet reports a spendable balance',
    is_numeric($wallet->at('data.balance.balance')),
    $wallet->describe()
);
Suite::check(
    'the wallet reports the escrow-locked balance separately',
    $wallet->at('data.balance.locked') !== null,
    'a single figure cannot tell a customer what they can actually spend'
);
Suite::check(
    'the wallet publishes its top-up and withdrawal limits',
    is_numeric($wallet->at('data.limits.min_topup'))
        && is_numeric($wallet->at('data.limits.max_withdrawal')),
    'the app cannot validate an amount client-side without these'
);

$anonWallet = Http::get('/wallet');
Suite::status('an unauthenticated caller cannot read a wallet', $anonWallet, 401);

$transactions = Http::get('/wallet/transactions', [], 'brand');
Suite::ok('GET /wallet/transactions lists the ledger', $transactions);
Suite::check(
    'the ledger is paginated',
    is_array($transactions->at('meta.pagination')),
    $transactions->describe()
);

$hugePage = Http::get('/wallet/transactions', ['page' => 999999999999], 'brand');
Suite::status('an absurd page number is clamped, not fatal', $hugePage, 200);

// -----------------------------------------------------------------
Suite::group('Wallet — payment methods');

$methods = Http::get('/payments/methods', [], 'brand');
Suite::ok('GET /payments/methods lists the gateways', $methods);

$gatewayIds = array_map(
    static fn (array $row): string => (string) ($row['id'] ?? ''),
    $methods->list('gateways')
);

Suite::check(
    'the wallet is offered as a payment method',
    in_array('wallet', $gatewayIds, true),
    'gateways were: ' . implode(', ', $gatewayIds)
);

Suite::check(
    'every gateway names itself for the UI',
    $gatewayIds !== [] && !array_filter(
        $methods->list('gateways'),
        static fn (array $row): bool => ($row['label'] ?? '') === ''
    ),
    'a gateway with no label renders as a blank row in the app'
);

Suite::check(
    'no gateway secret is exposed to the client',
    !preg_grep('/secret|key_secret|private/i', array_keys(
        array_merge(...array_map(
            static fn (array $r): array => $r,
            $methods->list('gateways') ?: [[]]
        ))
    )),
    'the methods endpoint is public to any signed-in user; it must carry publishable ids only'
);

// -----------------------------------------------------------------
Suite::group('Payments — the gateway contract');

[$guardBooking] = $wpPayableBooking(9000);

if ($guardBooking > 0) {
    $noGateway = Http::post("/payments/booking/{$guardBooking}", [], 'brand');
    Suite::status('paying without naming a gateway is refused', $noGateway, 422);

    $badGateway = Http::post("/payments/booking/{$guardBooking}", ['gateway' => 'paypal'], 'brand');
    Suite::status('an unsupported gateway is refused', $badGateway, 422);

    $asCreator = Http::post("/payments/booking/{$guardBooking}", ['gateway' => 'wallet'], 'creator');
    Suite::status('a creator cannot pay for a booking', $asCreator, [403, 404]);

    $anonPay = Http::post("/payments/booking/{$guardBooking}", ['gateway' => 'wallet']);
    Suite::status('an unauthenticated caller cannot pay', $anonPay, 401);

    $missing = Http::post('/payments/booking/99999999', ['gateway' => 'wallet'], 'brand');
    Suite::status('paying for a booking that does not exist is a 404', $missing, 404);
}

// A booking still pending acceptance is not payable.
$pending = Http::post('/bookings', [
    'creator_id'       => $wpCreatorProfileId,
    'title'            => 'IT unaccepted',
    'description'      => 'Never accepted, so it must not be payable.',
    'platform'         => 'instagram',
    'deliverable_type' => 'reel',
    'posts_count'      => 1,
    'amount'           => 7000,
], 'brand');

$pendingId = (int) ($pending->at('data.booking.id') ?? 0);

if ($pendingId > 0) {
    $wpBookings[] = $pendingId;

    $payPending = Http::post("/payments/booking/{$pendingId}", ['gateway' => 'wallet'], 'brand');
    Suite::status('a booking the creator has not accepted cannot be paid', $payPending, 422);
}

// -----------------------------------------------------------------
Suite::group('Payments — insufficient balance');

[$brokeBooking, $brokeTotal] = $wpPayableBooking(12000);

if ($brokeBooking > 0) {
    // Drain the wallet so the debit cannot succeed.
    Database::execute(
        'UPDATE `wallets` SET `balance` = 0 WHERE `user_id` = :id',
        ['id' => $wpBrandId]
    );

    $broke = Http::post("/payments/booking/{$brokeBooking}", ['gateway' => 'wallet'], 'brand');
    Suite::status('paying more than the wallet holds is refused', $broke, [402, 422]);

    Suite::equals(
        'the refused payment left the booking unpaid',
        (string) (Database::scalar(
            'SELECT `payment_status` FROM `bookings` WHERE `id` = :id',
            ['id' => $brokeBooking]
        ) ?? ''),
        'unpaid'
    );

    Suite::equals(
        'and it did not push the wallet negative',
        $wpBalance($wpBrandId),
        0.0
    );
}

// -----------------------------------------------------------------
Suite::group('Coupons — offers');

[$couponBooking, $couponTotal] = $wpPayableBooking(20000);

// A coupon this suite owns, so the checks do not depend on demo data.
$wpCouponCode = 'IT-WALLET-10';

Database::execute('DELETE FROM `coupons` WHERE `code` = :c', ['c' => $wpCouponCode]);

$wpCouponId = Database::insert('coupons', [
    'code'                 => $wpCouponCode,
    'name'                 => 'Integration: 10% capped',
    'discount_type'        => 'percentage',
    'discount_value'       => 10,
    'max_discount'         => 1500,
    'min_booking_amount'   => 1000,
    'applies_to'           => 'all',
    'usage_limit'          => 100,
    'usage_limit_per_user' => 1,
    'used_count'           => 0,
    'is_active'            => 1,
    'is_public'            => 1,
    'created_at'           => date('Y-m-d H:i:s'),
]);

$wpCoupons[] = $wpCouponId;

// A private code, to prove the offers list does not leak it.
Database::execute('DELETE FROM `coupons` WHERE `code` = :c', ['c' => 'IT-WALLET-SECRET']);

$wpSecretId = Database::insert('coupons', [
    'code'                 => 'IT-WALLET-SECRET',
    'name'                 => 'Integration: private',
    'discount_type'        => 'fixed',
    'discount_value'       => 750,
    'min_booking_amount'   => 0,
    'applies_to'           => 'all',
    'usage_limit_per_user' => 1,
    'used_count'           => 0,
    'is_active'            => 1,
    'is_public'            => 0,
    'created_at'           => date('Y-m-d H:i:s'),
]);

$wpCoupons[] = $wpSecretId;

if ($couponBooking > 0) {
    $offers = Http::get("/bookings/{$couponBooking}/coupons", [], 'brand');
    Suite::ok('GET /bookings/{id}/coupons lists offers', $offers);

    $offerRows  = $offers->list('coupons');
    $offerCodes = array_map(static fn (array $r): string => (string) ($r['code'] ?? ''), $offerRows);

    Suite::check(
        'the suite\'s public coupon is offered',
        in_array($wpCouponCode, $offerCodes, true),
        'offers were: ' . implode(', ', $offerCodes)
    );

    Suite::check(
        'a private code is never advertised',
        !in_array('IT-WALLET-SECRET', $offerCodes, true),
        'a code marked is_public = 0 appeared in the public offers list'
    );

    $mine = null;

    foreach ($offerRows as $row) {
        if ((string) ($row['code'] ?? '') === $wpCouponCode) {
            $mine = $row;
        }
    }

    if ($mine !== null) {
        Suite::equals(
            'the offer is priced against this booking, capped at max_discount',
            round((float) ($mine['discount'] ?? 0), 2),
            1500.00
        );
        Suite::check(
            'the offer says what it saves in words',
            is_string($mine['saving_label'] ?? null) && $mine['saving_label'] !== '',
            'saving_label was: ' . json_encode($mine['saving_label'] ?? null)
        );
    }

    Suite::check(
        'every ineligible offer explains why',
        !array_filter(
            $offerRows,
            static fn (array $r): bool => ($r['eligible'] ?? false) === false
                && (($r['reason'] ?? '') === '' || ($r['reason'] ?? null) === null)
        ),
        'an offer was marked ineligible with no reason the customer can act on'
    );

    $offersAsCreator = Http::get("/bookings/{$couponBooking}/coupons", [], 'creator');
    Suite::status('a creator cannot read a brand\'s offers', $offersAsCreator, [403, 404]);
}

// -----------------------------------------------------------------
Suite::group('Coupons — apply and remove');

if ($couponBooking > 0) {
    $blank = Http::post("/bookings/{$couponBooking}/coupon", ['code' => ''], 'brand');
    Suite::status('an empty code is refused', $blank, 422);

    $unknown = Http::post("/bookings/{$couponBooking}/coupon", ['code' => 'NOT-A-REAL-CODE'], 'brand');
    Suite::status('an unknown code is a 404', $unknown, 404);

    $apply = Http::post("/bookings/{$couponBooking}/coupon", ['code' => $wpCouponCode], 'brand');
    Suite::ok('a valid code applies', $apply);

    Suite::equals(
        'applying reduces total_amount by the discount',
        round((float) ($apply->at('data.booking.total_amount') ?? 0), 2),
        round($couponTotal - 1500, 2)
    );
    Suite::equals(
        'the discount is recorded on the booking',
        round((float) ($apply->at('data.booking.discount_amount') ?? 0), 2),
        1500.00
    );
    Suite::equals(
        'the creator\'s fee is not cut by the coupon',
        round((float) ($apply->at('data.booking.amount') ?? 0), 2),
        20000.00
    );
    Suite::check(
        'the applied code comes back on the booking, so a reload still shows it',
        (string) ($apply->at('data.booking.coupon.code') ?? '') === $wpCouponCode,
        $apply->describe()
    );
    Suite::check(
        'the discount is presented as a saving, not a charge',
        str_starts_with((string) ($apply->at('data.booking.formatted.discount') ?? ''), '-'),
        'formatted.discount was: ' . json_encode($apply->at('data.booking.formatted.discount'))
    );

    // Re-reading must show the same price: the discount is persisted, not
    // a property of the response that applied it.
    $reread = Http::get("/bookings/{$couponBooking}", [], 'brand');
    Suite::equals(
        'the discounted price survives a reload',
        round((float) ($reread->at('data.booking.total_amount') ?? 0), 2),
        round($couponTotal - 1500, 2)
    );

    // Applying a second time must replace, never stack.
    $again = Http::post("/bookings/{$couponBooking}/coupon", ['code' => $wpCouponCode], 'brand');
    Suite::ok('re-applying the same code is accepted', $again);
    Suite::equals(
        'and it replaces rather than stacking a second discount',
        round((float) ($again->at('data.booking.total_amount') ?? 0), 2),
        round($couponTotal - 1500, 2)
    );

    $strangerApply = Http::post("/bookings/{$couponBooking}/coupon", ['code' => $wpCouponCode], 'creator');
    Suite::status('a creator cannot reprice a brand\'s booking', $strangerApply, [403, 404]);

    $remove = Http::delete("/bookings/{$couponBooking}/coupon", null, 'brand');
    Suite::ok('the coupon can be removed', $remove);
    Suite::equals(
        'removing restores the full price exactly',
        round((float) ($remove->at('data.booking.total_amount') ?? 0), 2),
        round($couponTotal, 2)
    );
    Suite::check(
        'and clears the code from the booking',
        $remove->at('data.booking.coupon') === null,
        $remove->describe()
    );

    Suite::equals(
        'an unredeemed coupon\'s usage counter did not move',
        (int) (Database::scalar('SELECT `used_count` FROM `coupons` WHERE `id` = :id', ['id' => $wpCouponId]) ?? -1),
        0
    );
}

// -----------------------------------------------------------------
Suite::group('Payments — a discounted booking is charged the discounted amount');

[$payBooking, $payTotal] = $wpPayableBooking(20000);

if ($payBooking > 0) {
    $applied = Http::post("/bookings/{$payBooking}/coupon", ['code' => $wpCouponCode], 'brand');
    $due     = round((float) ($applied->at('data.booking.total_amount') ?? 0), 2);

    $wpFundWallet($wpBrandId, $payTotal + 5000);
    $before = $wpBalance($wpBrandId);

    $pay = Http::post("/payments/booking/{$payBooking}", ['gateway' => 'wallet'], 'brand');
    Suite::ok('the discounted booking is paid from the wallet', $pay);

    Suite::equals(
        'the payment record is for the discounted amount',
        round((float) ($pay->at('data.payment.amount') ?? 0), 2),
        $due
    );

    // The check that matters: what actually left the wallet.
    Suite::equals(
        'the wallet was debited the discounted amount, not the full price',
        round($before - $wpBalance($wpBrandId), 2),
        $due
    );

    Suite::check(
        'the debit is strictly less than the undiscounted total',
        $due < round($payTotal, 2),
        sprintf('charged %s against a full price of %s', Money::format($due), Money::format($payTotal))
    );

    Suite::equals(
        'the booking is marked paid',
        (string) (Database::scalar(
            'SELECT `payment_status` FROM `bookings` WHERE `id` = :id',
            ['id' => $payBooking]
        ) ?? ''),
        'paid'
    );

    Suite::equals(
        'and the money is held in escrow, not released to the creator',
        (string) (Database::scalar(
            'SELECT `escrow_status` FROM `bookings` WHERE `id` = :id',
            ['id' => $payBooking]
        ) ?? ''),
        'held'
    );

    Suite::equals(
        'the redemption is recorded exactly once',
        (int) (Database::scalar(
            'SELECT COUNT(*) FROM `coupon_redemptions` WHERE `booking_id` = :b',
            ['b' => $payBooking]
        ) ?? 0),
        1
    );

    Suite::equals(
        'the usage counter advanced only on capture',
        (int) (Database::scalar('SELECT `used_count` FROM `coupons` WHERE `id` = :id', ['id' => $wpCouponId]) ?? -1),
        1
    );

    $invoice = Database::selectOne(
        "SELECT * FROM `invoices` WHERE `booking_id` = :b AND `type` = 'brand_invoice'",
        ['b' => $payBooking]
    );

    Suite::check('an invoice is issued at capture', $invoice !== null, 'no invoice row');

    if ($invoice !== null) {
        Suite::equals(
            'the invoice records the discount, so it balances',
            round((float) $invoice['discount_amount'], 2),
            round($payTotal - $due, 2)
        );
        Suite::equals(
            'the invoice total is what was actually charged',
            round((float) $invoice['total'], 2),
            $due
        );

        // subtotal + fee + tax − discount must equal the total, or the
        // document does not add up.
        $reconstructed = round(
            (float) $invoice['subtotal'] + (float) $invoice['fee_amount']
            + (float) $invoice['tax_amount'] - (float) $invoice['discount_amount'],
            2
        );

        Suite::equals('the invoice lines reconcile to the total', $reconstructed, round((float) $invoice['total'], 2));
    }

    // Paying twice must not double-charge.
    $balanceBeforeReplay = $wpBalance($wpBrandId);
    $replay = Http::post("/payments/booking/{$payBooking}", ['gateway' => 'wallet'], 'brand');
    Suite::status('paying an already-paid booking is refused', $replay, 409);
    Suite::equals(
        'and the refused replay took nothing from the wallet',
        $wpBalance($wpBrandId),
        $balanceBeforeReplay
    );

    // The per-user cap is now spent.
    [$cappedBooking] = $wpPayableBooking(20000);

    if ($cappedBooking > 0) {
        $second = Http::post("/bookings/{$cappedBooking}/coupon", ['code' => $wpCouponCode], 'brand');
        Suite::status('a one-per-customer coupon cannot be used twice', $second, 422);
        Suite::check(
            'and the refusal says so in words the customer understands',
            str_contains(strtolower($second->message()), 'already used'),
            'message was: ' . $second->message()
        );
    }
}

// -----------------------------------------------------------------
Suite::group('Payments — an undiscounted booking still charges in full');

[$plainBooking, $plainTotal] = $wpPayableBooking(6000);

if ($plainBooking > 0) {
    $wpFundWallet($wpBrandId, $plainTotal + 1000);
    $before = $wpBalance($wpBrandId);

    $pay = Http::post("/payments/booking/{$plainBooking}", ['gateway' => 'wallet'], 'brand');
    Suite::ok('a booking with no coupon pays from the wallet', $pay);

    Suite::equals(
        'the full total is charged when no coupon is applied',
        round($before - $wpBalance($wpBrandId), 2),
        round($plainTotal, 2)
    );

    Suite::equals(
        'no redemption is recorded for an undiscounted booking',
        (int) (Database::scalar(
            'SELECT COUNT(*) FROM `coupon_redemptions` WHERE `booking_id` = :b',
            ['b' => $plainBooking]
        ) ?? -1),
        0
    );

    $couponAfterPaid = Http::post("/bookings/{$plainBooking}/coupon", ['code' => $wpCouponCode], 'brand');
    Suite::status('a coupon cannot be applied after payment', $couponAfterPaid, 409);
}

// -----------------------------------------------------------------
Suite::group('Payments — history');

$history = Http::get('/payments', [], 'brand');
Suite::ok('GET /payments lists the caller\'s payments', $history);
Suite::check(
    'the payment history is paginated',
    is_array($history->at('meta.pagination')),
    $history->describe()
);

$creatorHistory = Http::get('/payments', [], 'creator');
Suite::ok('a creator gets their own payment history', $creatorHistory);

$creatorPaymentIds = array_map(
    static fn (array $r): int => (int) ($r['id'] ?? 0),
    $creatorHistory->list('payments') ?: $creatorHistory->list()
);

$brandPaymentIds = array_map(
    static fn (array $r): int => (int) ($r['id'] ?? 0),
    $history->list('payments') ?: $history->list()
);

Suite::check(
    'one account cannot see another account\'s payments',
    array_intersect($brandPaymentIds, $creatorPaymentIds) === [],
    'payment ids leaked across accounts'
);

// -----------------------------------------------------------------
Suite::group('Invoices — the billing screen');

// The Flutter billing screen has called GET /invoices and /invoices/{id} since
// it was written; neither route existed, so the whole screen answered 404.

$invoiceList = Http::get('/invoices', [], 'brand');
Suite::ok('GET /invoices lists the caller\'s invoices', $invoiceList);
Suite::check(
    'the invoice list is paginated',
    is_array($invoiceList->at('meta.pagination')),
    $invoiceList->describe()
);

$invoiceRows = $invoiceList->list('invoices') ?: $invoiceList->list();

Suite::check(
    'each invoice carries the fields the app renders',
    $invoiceRows === [] || !array_filter(
        $invoiceRows,
        static fn (array $r): bool => ($r['invoice_number'] ?? '') === ''
            || !array_key_exists('line_items', $r)
            || !array_key_exists('url', $r)
    ),
    'an invoice arrived without a number, line items or a document URL'
);

Suite::check(
    'line items arrive decoded, not as a JSON string',
    $invoiceRows === [] || is_array($invoiceRows[0]['line_items'] ?? null),
    'line_items was: ' . gettype($invoiceRows[0]['line_items'] ?? null)
);

$anonInvoices = Http::get('/invoices');
Suite::status('an unauthenticated caller cannot list invoices', $anonInvoices, 401);

if ($invoiceRows !== []) {
    $invoiceId = (int) $invoiceRows[0]['id'];

    $one = Http::get("/invoices/{$invoiceId}", [], 'brand');
    Suite::ok('GET /invoices/{id} returns one invoice', $one);
    Suite::equals(
        'and it is the one that was asked for',
        (int) ($one->at('data.invoice.id') ?? 0),
        $invoiceId
    );

    // Suite::ok() reads the JSON envelope's `success` flag; this response is a
    // whole HTML document and has no envelope, so the status is checked directly.
    $html = Http::get("/invoices/{$invoiceId}", ['format' => 'html'], 'brand');
    Suite::status('?format=html returns the printable document', $html, 200);
    Suite::check(
        'which is HTML, not JSON',
        str_contains(strtolower((string) $html->header('content-type')), 'html'),
        'content-type was: ' . (string) $html->header('content-type')
    );

    // A 404 rather than a 403: a 403 would confirm the id exists, which is
    // enough to count how many invoices the platform has issued.
    $stranger = Http::get("/invoices/{$invoiceId}", [], 'creator');
    Suite::status('another account cannot read this invoice', $stranger, 404);
}

Suite::status('an invoice that does not exist is a 404', Http::get('/invoices/99999999', [], 'brand'), 404);

// -----------------------------------------------------------------
Suite::group('Bookings — revisions and disputes');

// Both endpoints were called by the deliverable-review screen and neither
// existed, so "Request changes" and "Raise a dispute" reported success and
// did nothing at all.

[$revisionBooking] = $wpPayableBooking(9000);

if ($revisionBooking > 0) {
    // Not yet delivered.
    $early = Http::post("/bookings/{$revisionBooking}/request-revision", ['note' => 'Please redo the intro.'], 'brand');
    Suite::status('changes cannot be requested before delivery', $early, 422);

    $shortNote = Http::post("/bookings/{$revisionBooking}/request-revision", ['note' => 'no'], 'brand');
    Suite::status('an empty or trivial note is refused', $shortNote, 422);

    $asCreator = Http::post("/bookings/{$revisionBooking}/request-revision", ['note' => 'Change this please.'], 'creator');
    Suite::status('a creator cannot request changes to their own work', $asCreator, [403, 404]);

    // Walk it to delivered: pay, then deliver.
    $wpFundWallet($wpBrandId, 20000);
    Http::post("/payments/booking/{$revisionBooking}", ['gateway' => 'wallet'], 'brand');
    Http::post("/bookings/{$revisionBooking}/deliver", [
        'deliverables' => [['title' => 'IT reel', 'content_url' => 'https://example.com/reel']],
        'note'         => 'Delivered for the integration suite.',
    ], 'creator');

    $delivered = (string) (Database::scalar(
        'SELECT `status` FROM `bookings` WHERE `id` = :id',
        ['id' => $revisionBooking]
    ) ?? '');

    if ($delivered === 'delivered') {
        $revise = Http::post("/bookings/{$revisionBooking}/request-revision", [
            'note' => 'The product label is out of frame for most of the reel — please reshoot that section.',
        ], 'brand');

        Suite::ok('delivered work can be sent back for changes', $revise);

        Suite::equals(
            'and the booking returns to in progress',
            (string) (Database::scalar('SELECT `status` FROM `bookings` WHERE `id` = :id', ['id' => $revisionBooking]) ?? ''),
            'in_progress'
        );

        Suite::equals(
            'the deliverable is marked as needing a revision',
            (string) (Database::scalar(
                "SELECT `status` FROM `booking_deliverables` WHERE `booking_id` = :id ORDER BY `id` DESC LIMIT 1",
                ['id' => $revisionBooking]
            ) ?? ''),
            'revision_requested'
        );

        Suite::check(
            'the revision counter advanced',
            (int) (Database::scalar(
                'SELECT COALESCE(MAX(`revision_count`), 0) FROM `booking_deliverables` WHERE `booking_id` = :id',
                ['id' => $revisionBooking]
            ) ?? 0) >= 1,
            'without this the revision cap can never be reached'
        );

        Suite::check(
            'the creator is told, rather than left waiting',
            (int) (Database::scalar(
                "SELECT COUNT(*) FROM `notifications`
                  WHERE `action_type` = 'booking' AND `action_value` = :id
                    AND `type` = 'booking.revision_requested'",
                ['id' => $revisionBooking]
            ) ?? 0) >= 1,
            'no notification was written — the creator would never learn their work came back'
        );

        Suite::equals(
            'escrow is untouched by a revision request',
            (string) (Database::scalar('SELECT `escrow_status` FROM `bookings` WHERE `id` = :id', ['id' => $revisionBooking]) ?? ''),
            'held'
        );

        // A deliverable with no metrics yet — which is every freshly delivered
        // one — must still serialise `metrics` as an OBJECT. It used to come
        // back as `[]` because `json_decode(...) ?: []` produces an empty PHP
        // array and json_encode renders that as a list. The Flutter model
        // casts the field to a map, so Booking Details threw a TypeError on
        // every delivered booking and showed "We hit a snag". The type of a
        // field must not change with its contents.
        $detail = Http::get("/bookings/{$revisionBooking}", [], 'brand');

        // Asserted against the RAW body on purpose. json_decode(..., true)
        // turns an empty JSON object into an empty PHP array, which re-encodes
        // as `[]` — so a decoded payload cannot tell `{}` from `[]` and the
        // check would pass whatever the server sent. The client sees the raw
        // bytes, so the test has to as well.
        Suite::check(
            'an empty deliverable metrics field is an object, not a list',
            !str_contains($detail->body, '"metrics":[]')
                && !str_contains($detail->body, '"metrics": []'),
            'the response carries "metrics":[] — a client casting that field to '
                . 'a map throws, which is what took out Booking Details'
        );

        Suite::check(
            'and the booking detail carries deliverables at all',
            $detail->at('data.booking.deliverables') !== [],
            'without any, the shape assertion above proves nothing'
        );
    }
}

// --- disputes --------------------------------------------------------------
[$disputeBooking] = $wpPayableBooking(9000);

if ($disputeBooking > 0) {
    $unpaid = Http::post("/bookings/{$disputeBooking}/dispute", ['reason' => 'not_delivered'], 'brand');
    Suite::status('an unpaid booking cannot be disputed', $unpaid, 422);

    $wpFundWallet($wpBrandId, 20000);
    Http::post("/payments/booking/{$disputeBooking}", ['gateway' => 'wallet'], 'brand');

    $badReason = Http::post("/bookings/{$disputeBooking}/dispute", ['reason' => 'because'], 'brand');
    Suite::status('an unrecognised reason is refused', $badReason, 422);

    $raise = Http::post("/bookings/{$disputeBooking}/dispute", [
        'reason' => 'poor_quality',
        'detail' => 'The delivered reel does not match the brief that was agreed.',
    ], 'brand');

    Suite::ok('a paid booking can be disputed', $raise, [200, 201]);

    Suite::equals(
        'the booking moves to disputed',
        (string) (Database::scalar('SELECT `status` FROM `bookings` WHERE `id` = :id', ['id' => $disputeBooking]) ?? ''),
        'disputed'
    );

    Suite::equals(
        'a dispute record is written for the admin queue',
        (int) (Database::scalar(
            "SELECT COUNT(*) FROM `booking_disputes` WHERE `booking_id` = :id AND `status` = 'open'",
            ['id' => $disputeBooking]
        ) ?? 0),
        1
    );

    // The point of a dispute is that neither side gets the money yet.
    Suite::equals(
        'escrow stays held — neither refunded nor released',
        (string) (Database::scalar('SELECT `escrow_status` FROM `bookings` WHERE `id` = :id', ['id' => $disputeBooking]) ?? ''),
        'held'
    );

    Suite::check(
        'both parties are notified',
        (int) (Database::scalar(
            "SELECT COUNT(*) FROM `notifications`
              WHERE `action_type` = 'booking' AND `action_value` = :id
                AND `type` = 'booking.disputed'",
            ['id' => $disputeBooking]
        ) ?? 0) >= 2,
        'the person who raised it needs the reference too; silence reads as the report being lost'
    );

    $again = Http::post("/bookings/{$disputeBooking}/dispute", ['reason' => 'other'], 'brand');
    Suite::status('a second dispute on the same booking is refused', $again, 409);

    $outsider = Http::post("/bookings/{$disputeBooking}/dispute", ['reason' => 'other'], 'creator');
    Suite::status('and a party who is not on the booking cannot raise one', $outsider, [403, 404, 409]);
}

// -----------------------------------------------------------------
Suite::group('Wallet — withdrawals and bank accounts');

$bankAccounts = Http::get('/wallet/bank-accounts', [], 'creator');
Suite::ok('GET /wallet/bank-accounts lists payout destinations', $bankAccounts);

$badBank = Http::post('/wallet/bank-accounts', [
    'account_holder' => 'IT Creator',
    'account_number' => '1',
    'ifsc'           => 'nope',
], 'creator');
Suite::status('a malformed bank account is refused', $badBank, 422);

$withdrawals = Http::get('/wallet/withdrawals', [], 'creator');
Suite::ok('GET /wallet/withdrawals lists payout requests', $withdrawals);

$overdraw = Http::post('/wallet/withdraw', ['amount' => 99999999], 'creator');
Suite::status('withdrawing more than the balance is refused', $overdraw, [402, 422]);

$negative = Http::post('/wallet/withdraw', ['amount' => -500], 'creator');
Suite::status('a negative withdrawal is refused', $negative, 422);

$foreignWithdrawal = (int) (Database::scalar(
    'SELECT `id` FROM `withdrawal_requests` WHERE `user_id` <> :me ORDER BY `id` DESC LIMIT 1',
    ['me' => $wpCreatorId]
) ?? 0);

if ($foreignWithdrawal > 0) {
    $cancelForeign = Http::post("/wallet/withdrawals/{$foreignWithdrawal}/cancel", [], 'creator');
    Suite::status('another user\'s payout cannot be cancelled', $cancelForeign, [403, 404]);
}

$topupNoAmount = Http::post('/wallet/topup', ['gateway' => 'wallet'], 'brand');
Suite::status('a top-up with no amount is refused', $topupNoAmount, 422);

$topupNegative = Http::post('/wallet/topup', ['amount' => -100, 'gateway' => 'razorpay'], 'brand');
Suite::status('a negative top-up is refused', $topupNegative, 422);

// -----------------------------------------------------------------
Suite::group('Wallet and payments — cleanup');

foreach ($wpBookings as $id) {
    Database::execute('DELETE FROM `booking_disputes` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `coupon_redemptions` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `platform_earnings` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `invoices` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute("DELETE FROM `wallet_transactions` WHERE `reference_type` = 'booking' AND `reference_id` = :id", ['id' => $id]);
    Database::execute('DELETE FROM `payments` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `booking_status_history` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `booking_offers` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `booking_deliverables` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `bookings` WHERE `id` = :id', ['id' => $id]);
}

foreach ($wpCoupons as $id) {
    Database::execute('DELETE FROM `coupon_redemptions` WHERE `coupon_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `coupons` WHERE `id` = :id', ['id' => $id]);
}

foreach ($wpKycInserted as $userId) {
    Database::execute('DELETE FROM `kyc_profiles` WHERE `user_id` = :id', ['id' => $userId]);
}

Database::execute(
    'UPDATE `users` SET `email_verified_at` = NULL WHERE `id` IN (:brand, :creator)',
    ['brand' => $wpBrandId, 'creator' => $wpCreatorId]
);

if ($wpCreatorProfileId > 0 && $wpOriginalCreatorStatus !== null) {
    Database::update('creators', ['status' => $wpOriginalCreatorStatus], ['id' => $wpCreatorProfileId]);
}

// The suite funded this wallet itself; leaving the money behind would skew
// every later balance assertion and the nightly reconcile report.
Database::execute('DELETE FROM `wallet_transactions` WHERE `user_id` = :id', ['id' => $wpBrandId]);
Database::execute('DELETE FROM `wallets` WHERE `user_id` = :id', ['id' => $wpBrandId]);

Suite::equals(
    'the suite left no bookings behind',
    (int) (Database::scalar(
        'SELECT COUNT(*) FROM `bookings` WHERE `brand_user_id` = :id',
        ['id' => $wpBrandId]
    ) ?? -1),
    0
);

Suite::equals(
    'the suite left no coupons behind',
    (int) (Database::scalar(
        "SELECT COUNT(*) FROM `coupons` WHERE `code` LIKE 'IT-WALLET-%'"
    ) ?? -1),
    0
);

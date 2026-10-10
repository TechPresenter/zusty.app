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
 *    server-side, carries the end-to-end assertions. Where the outbound call
 *    itself matters (a top-up order, a refund to the original method) the
 *    service is driven in-process against tests/fixtures/mock_cashfree.php,
 *    a stand-in for Cashfree on 127.0.0.1:8097.
 *
 * Wallet top-ups are off by default since migration 056 (brands pay each
 * booking at checkout). The suite sets wallet.topup_enabled explicitly for
 * each state it checks, and puts every setting it touched back at the end.
 */

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\RateLimiter;
use Zuste\Helpers\Money;
use Zuste\Services\BookingService;
use Zuste\Services\DisputeService;
use Zuste\Services\PaymentService;
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

/** Settings this suite changed: "group.key" => ['value' => original], or null when the row did not exist. */
$wpSettings = [];

$wpSet = static function (string $group, string $key, string $value) use (&$wpSettings): void {
    $id = $group . '.' . $key;

    if (!array_key_exists($id, $wpSettings)) {
        $row = Database::selectOne(
            'SELECT `value` FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
            ['g' => $group, 'k' => $key]
        );

        $wpSettings[$id] = $row === null ? null : ['value' => $row['value']];
    }

    Database::execute(
        'INSERT INTO `settings` (`group_key`, `setting_key`, `value`) VALUES (:g, :k, :v)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
        ['g' => $group, 'k' => $key, 'v' => $value]
    );

    Config::flushSettings();
};

$wpRestoreSettings = static function () use (&$wpSettings): void {
    foreach ($wpSettings as $id => $original) {
        [$group, $key] = explode('.', $id, 2);

        if ($original === null) {
            Database::execute(
                'DELETE FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
                ['g' => $group, 'k' => $key]
            );
        } else {
            Database::execute(
                'UPDATE `settings` SET `value` = :v WHERE `group_key` = :g AND `setting_key` = :k',
                ['v' => $original['value'], 'g' => $group, 'k' => $key]
            );
        }
    }

    $wpSettings = [];
    Config::flushSettings();
};

// As with the creator's status above: the switches go back even when a
// statement between here and the teardown dies.
register_shutdown_function(static function () use ($wpRestoreSettings): void {
    try {
        $wpRestoreSettings();
    } catch (\Throwable) {
        // Nothing useful to do during shutdown.
    }
});

// Checked explicitly rather than inherited from whatever the database holds.
$wpSet('payment', 'allow_wallet_payment', '1');
$wpSet('wallet', 'topup_enabled', '0');

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
Suite::check(
    'the wallet says whether money can be added to it',
    $wallet->at('data.topup_enabled') === false,
    'topup_enabled was: ' . json_encode($wallet->at('data.topup_enabled'))
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

$wpGatewayIds = static fn (\Zuste\Tests\Res $res): array => array_map(
    static fn (array $row): string => (string) ($row['id'] ?? ''),
    $res->list('gateways')
);

// The wallet cannot be topped up for a booking, so it is a way to pay only
// while it already holds money — an empty one could only fail at the debit.
Database::execute('UPDATE `wallets` SET `balance` = 0 WHERE `user_id` = :id', ['id' => $wpBrandId]);

$emptyMethods = Http::get('/payments/methods', [], 'brand');
Suite::ok('GET /payments/methods lists the gateways', $emptyMethods);

Suite::check(
    'an empty wallet is not offered as a way to pay',
    !in_array('wallet', $wpGatewayIds($emptyMethods), true),
    'gateways were: ' . implode(', ', $wpGatewayIds($emptyMethods))
);

$wpFundWallet($wpBrandId, 1500);

$methods    = Http::get('/payments/methods', [], 'brand');
$gatewayIds = $wpGatewayIds($methods);

Suite::check(
    'a wallet that holds money is offered as a payment method',
    in_array('wallet', $gatewayIds, true),
    'gateways were: ' . implode(', ', $gatewayIds)
);

Suite::equals(
    '…with the balance the checkout compares against the booking total',
    round((float) ($methods->at('data.wallet_balance') ?? -1), 2),
    1500.00
);

Suite::check(
    'no gateway lists the Zusty wallet among its own instruments',
    !array_filter(
        $methods->list('gateways'),
        static fn (array $row): bool => ($row['id'] ?? '') !== 'wallet'
            && in_array('wallet', (array) ($row['methods'] ?? []), true)
    ),
    'a card gateway offering "Wallet" reads as paying with the Zusty wallet on its page'
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

    // Money in the wallet (a refund, say) pays only when it covers the lot:
    // there is no top-up to make up the difference.
    $wpShortfall = round($brokeTotal / 2, 2);
    $wpFundWallet($wpBrandId, $wpShortfall);

    $short = Http::post("/payments/booking/{$brokeBooking}", ['gateway' => 'wallet'], 'brand');
    Suite::status('a balance short of the total is refused as well', $short, 422);
    Suite::equals('…as insufficient_balance', $short->code(), 'insufficient_balance');
    Suite::check(
        '…pointing the brand at paying by UPI, card or net banking',
        str_contains($short->message(), 'UPI'),
        'message was: ' . $short->message()
    );
    Suite::equals('…and the balance is untouched', round($wpBalance($wpBrandId), 2), $wpShortfall);
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

// -----------------------------------------------------------------
Suite::group('Wallet — top-up switched off (the default since 056)');

$wpTopupOrders = static fn (): int => (int) (Database::scalar(
    "SELECT COUNT(*) FROM `payments` WHERE `user_id` = :id AND `purpose` = 'wallet_topup'",
    ['id' => $wpBrandId]
) ?? 0);

$wpSet('wallet', 'topup_enabled', '0');
$wpOrdersBefore = $wpTopupOrders();

$topupOff = Http::post('/wallet/topup', ['amount' => 1000, 'gateway' => 'cashfree'], 'brand');
Suite::status('a top-up is refused while switched off', $topupOff, 403);
Suite::equals('…as topup_disabled', $topupOff->code(), 'topup_disabled');
Suite::check(
    '…telling the brand to pay at checkout instead',
    str_contains(strtolower($topupOff->message()), 'checkout'),
    'message was: ' . $topupOff->message()
);

$topupOffEmpty = Http::post('/wallet/topup', [], 'brand');
Suite::status('the switch is checked before the body, so an empty one is refused the same way', $topupOffEmpty, 403);

Suite::equals('no gateway order was created', $wpTopupOrders(), $wpOrdersBefore);

$configOff = Http::get('/app/config');
Suite::ok('GET /app/config answers', $configOff);
Suite::check(
    '/app/config tells the app to offer no top-up',
    $configOff->at('data.wallet.topup_enabled') === false,
    'wallet block was: ' . json_encode($configOff->at('data.wallet'))
);
Suite::check(
    '…while a balance already held may still pay for a booking',
    $configOff->at('data.wallet.wallet_payment') === true,
    'wallet block was: ' . json_encode($configOff->at('data.wallet'))
);

// No other caller can load a wallet behind the endpoint's back.
try {
    PaymentService::createTopupOrder($wpBrandId, 1000, 'cashfree');
    Suite::check('the service refuses a top-up as well', false, 'createTopupOrder() returned an order');
} catch (HttpException $e) {
    Suite::equals('the service refuses a top-up as well', [$e->status(), $e->errorCode()], [403, 'topup_disabled']);
}

// -----------------------------------------------------------------
Suite::group('Wallet — top-up switched back on by an admin');

$wpSet('wallet', 'enabled', '1');
$wpSet('wallet', 'topup_enabled', '1');

$configOn = Http::get('/app/config');
Suite::check(
    '/app/config tells the app it may offer a top-up',
    $configOn->at('data.wallet.topup_enabled') === true,
    'wallet block was: ' . json_encode($configOn->at('data.wallet'))
);
Suite::check(
    'GET /wallet agrees',
    Http::get('/wallet', [], 'brand')->at('data.topup_enabled') === true,
    'the wallet screen and the config must not disagree'
);

$topupNoAmount = Http::post('/wallet/topup', ['gateway' => 'wallet'], 'brand');
Suite::status('a top-up with no amount is refused', $topupNoAmount, 422);

$topupNegative = Http::post('/wallet/topup', ['amount' => -100, 'gateway' => 'razorpay'], 'brand');
Suite::status('a negative top-up is refused', $topupNegative, 422);

$topupSelf = Http::post('/wallet/topup', ['amount' => 1000, 'gateway' => 'wallet'], 'brand');
Suite::status('a wallet cannot fund itself', $topupSelf, 422);

// Past the switch and the validator, into the service's own limits: the
// furthest an HTTP check can go without a live gateway.
$topupTiny = Http::post('/wallet/topup', ['amount' => 1, 'gateway' => 'cashfree'], 'brand');
Suite::status('a top-up under the minimum is refused', $topupTiny, 422);
Suite::check(
    '…naming the minimum, which proves the switch let it through',
    str_contains(strtolower($topupTiny->message()), 'minimum'),
    'message was: ' . $topupTiny->message()
);

// -----------------------------------------------------------------
// The stand-in Cashfree, for the calls that have to leave the server.
// -----------------------------------------------------------------

const WP_CASHFREE_HOST = '127.0.0.1';
const WP_CASHFREE_PORT = 8097;

$wpCfLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zuste-mock-cashfree-' . getmypid() . '.log';
@unlink($wpCfLog);

$wpCfMock = proc_open(
    [PHP_BINARY, '-S', WP_CASHFREE_HOST . ':' . WP_CASHFREE_PORT, __DIR__ . '/../fixtures/mock_cashfree.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $wpCfLog . '.out', 'a'], 2 => ['file', $wpCfLog . '.out', 'a']],
    $wpCfPipes,
    dirname(__DIR__, 2),
    array_merge(getenv(), ['ZUSTE_MOCK_CASHFREE_LOG' => $wpCfLog])
);

$wpCfUp = false;

for ($i = 0; $i < 50 && is_resource($wpCfMock); $i++) {
    $socket = @fsockopen(WP_CASHFREE_HOST, WP_CASHFREE_PORT, $errno, $errstr, 0.2);

    if ($socket !== false) {
        fclose($socket);
        $wpCfUp = true;
        break;
    }

    usleep(100_000);
}

/** Everything the stand-in has received, oldest first. */
$wpCfSent = static function () use ($wpCfLog): array {
    $lines = is_file($wpCfLog) ? file($wpCfLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_values(array_filter(array_map(static fn (string $l): mixed => json_decode($l, true), $lines ?: []), 'is_array'));
};

// In-process only: the running API keeps talking to whatever Cashfree its
// own config names. Credentials already in the database win over these, and
// then travel no further than the stand-in on loopback.
$wpCfBase = 'http://' . WP_CASHFREE_HOST . ':' . WP_CASHFREE_PORT . '/pg';
Config::set('payment.cashfree.api_base', ['sandbox' => $wpCfBase, 'production' => $wpCfBase]);
Config::set('payment.cashfree.app_id', 'IT-CF-APP');
Config::set('payment.cashfree.secret_key', 'IT-CF-SECRET');

/** A captured gateway payment on [$bookingId], as if the brand had paid at checkout. */
$wpPlantPayment = static function (int $bookingId, float $amount, string $gateway, ?string $orderId) use ($wpBrandId): int {
    $id = Database::insert('payments', [
        'reference'          => 'PENDING',
        'booking_id'         => $bookingId,
        'user_id'            => $wpBrandId,
        'purpose'            => 'booking',
        'gateway'            => $gateway,
        'gateway_order_id'   => $orderId,
        'gateway_payment_id' => $orderId === null ? null : 'cfpay-' . $orderId,
        'amount'             => $amount,
        'currency'           => 'INR',
        'status'             => 'captured',
        'paid_at'            => date('Y-m-d H:i:s'),
        'created_at'         => date('Y-m-d H:i:s'),
    ]);

    Database::update('payments', ['reference' => 'ITWP' . str_pad((string) $id, 10, '0', STR_PAD_LEFT)], ['id' => $id]);

    return $id;
};

// -----------------------------------------------------------------
if (!$wpCfUp) {
    Suite::skipGroup('Wallet — a top-up order against the stand-in Cashfree', 'could not start php -S on ' . WP_CASHFREE_HOST . ':' . WP_CASHFREE_PORT);
} else {
    Suite::group('Wallet — a top-up order against the stand-in Cashfree');

    $wpBalanceBeforeTopup = round($wpBalance($wpBrandId), 2);

    try {
        $order = PaymentService::createTopupOrder($wpBrandId, 1000, 'cashfree');

        Suite::equals('switched on, a top-up creates a Cashfree order', $order['gateway'] ?? null, 'cashfree');
        Suite::check(
            '…with the hosted checkout to send the brand to',
            ($order['requires_client_action'] ?? false) === true
                && str_contains((string) ($order['checkout_url'] ?? ''), (string) ($order['payment_session_id'] ?? '-')),
            json_encode($order)
        );

        $orderRow = Database::selectOne('SELECT * FROM `payments` WHERE `id` = :id', ['id' => (int) ($order['payment_id'] ?? 0)]) ?? [];
        Suite::equals(
            '…recorded as a top-up waiting to be paid',
            [(string) ($orderRow['purpose'] ?? ''), (string) ($orderRow['status'] ?? ''), round((float) ($orderRow['amount'] ?? 0), 2)],
            ['wallet_topup', 'created', 1000.00]
        );

        $orderCall = array_values(array_filter($wpCfSent(), static fn (array $c): bool => str_ends_with((string) $c['path'], '/orders')));
        Suite::check(
            'Cashfree was asked for exactly that amount, tagged as a top-up',
            count($orderCall) === 1
                && (float) ($orderCall[0]['body']['order_amount'] ?? 0) === 1000.0
                && ($orderCall[0]['body']['order_tags']['purpose'] ?? '') === 'wallet_topup',
            json_encode($orderCall)
        );
    } catch (\Throwable $e) {
        Suite::check('switched on, a top-up creates a Cashfree order', false, get_class($e) . ': ' . $e->getMessage());
    }

    Suite::equals('nothing is credited until the payment is confirmed', round($wpBalance($wpBrandId), 2), $wpBalanceBeforeTopup);
}

// Off again for everything that follows.
$wpSet('wallet', 'topup_enabled', '0');

// -----------------------------------------------------------------
if (!$wpCfUp) {
    Suite::skipGroup('Refunds — back to the original payment method', 'could not start php -S on ' . WP_CASHFREE_HOST . ':' . WP_CASHFREE_PORT);
} else {
    Suite::group('Refunds — back to the original payment method');

    // --- the default: a gateway payment goes back to the gateway ---------
    [$srcBooking, $srcTotal] = $wpPayableBooking(8000);

    if ($srcBooking > 0) {
        $srcOrder   = 'ZPAY-IT-SRC-' . $srcBooking;
        $srcPayment = $wpPlantPayment($srcBooking, $srcTotal, 'cashfree', $srcOrder);
        $before     = round($wpBalance($wpBrandId), 2);
        $mark       = count($wpCfSent());

        // No destination named: what cancellations and the cron get.
        $refund = PaymentService::refundBooking($srcBooking, 500, 'other', 'admin');

        Suite::equals('a refund with no destination named goes back to the original method', $refund['destination'], 'source');
        Suite::equals('…accepted by Cashfree and waiting for its confirmation', $refund['status'], 'processing');

        $refundCalls = array_slice($wpCfSent(), $mark);
        Suite::check(
            '…which was asked to refund this order, for this amount, under our reference',
            count($refundCalls) === 1
                && $refundCalls[0]['path'] === '/pg/orders/' . rawurlencode($srcOrder) . '/refunds'
                && (float) ($refundCalls[0]['body']['refund_amount'] ?? 0) === 500.0
                && ($refundCalls[0]['body']['refund_id'] ?? '') === $refund['reference'],
            json_encode($refundCalls)
        );
        Suite::equals('…and nothing landed in the wallet', round($wpBalance($wpBrandId), 2), $before);

        // Cashfree's REFUND_STATUS webhook lands here.
        PaymentService::completeRefund((int) $refund['id']);

        Suite::equals(
            'once Cashfree confirms, the payment carries the refund',
            round((float) (Database::scalar('SELECT `amount_refunded` FROM `payments` WHERE `id` = :id', ['id' => $srcPayment]) ?? 0), 2),
            500.00
        );
        Suite::check(
            '…and the brand is told it went back to the original payment method',
            (int) (Database::scalar(
                "SELECT COUNT(*) FROM `notifications`
                  WHERE `user_id` = :u AND `type` = 'payment.refunded' AND `body` LIKE '%original payment method%'
                    AND `created_at` >= NOW() - INTERVAL 5 MINUTE",
                ['u' => $wpBrandId]
            ) ?? 0) >= 1,
            'no payment.refunded notification naming the original payment method'
        );
        Suite::equals('…still without touching the wallet', round($wpBalance($wpBrandId), 2), $before);

        // --- an admin may still choose the wallet ---------------------------
        $mark   = count($wpCfSent());
        $chosen = PaymentService::refundBooking($srcBooking, 300, 'other', 'admin', null, 'wallet');

        Suite::equals('an admin can still send a refund to the wallet', [$chosen['destination'], $chosen['status']], ['wallet', 'completed']);
        Suite::equals('…which is credited at once', round($wpBalance($wpBrandId) - $before, 2), 300.00);
        Suite::equals('…without asking Cashfree', count($wpCfSent()), $mark);
    }

    // --- a payment that cannot go back automatically -----------------------
    [$manualBooking, $manualTotal] = $wpPayableBooking(6000);

    if ($manualBooking > 0) {
        $wpPlantPayment($manualBooking, $manualTotal, 'manual', null);
        $before = round($wpBalance($wpBrandId), 2);

        $fallback = PaymentService::refundBooking($manualBooking, 400, 'other', 'admin');

        Suite::equals(
            'a payment no gateway can reverse is refunded to the wallet instead',
            [$fallback['destination'], $fallback['status']],
            ['wallet', 'completed']
        );
        Suite::check(
            '…and the refund says why, for finance',
            str_contains((string) ($fallback['notes'] ?? ''), 'cannot be refunded automatically'),
            'notes were: ' . json_encode($fallback['notes'] ?? null)
        );
        Suite::equals('…credited at once', round($wpBalance($wpBrandId) - $before, 2), 400.00);
    }

    // --- a gateway that refuses is not a reason to use the wallet ----------
    [$refusedBooking, $refusedTotal] = $wpPayableBooking(7000);

    if ($refusedBooking > 0) {
        $wpPlantPayment($refusedBooking, $refusedTotal, 'cashfree', 'ZPAY-IT-REFUSE-' . $refusedBooking);
        $before = round($wpBalance($wpBrandId), 2);

        $refused = PaymentService::refundBooking($refusedBooking, 250, 'other', 'admin');

        Suite::equals(
            'a refund the gateway refuses stays on the original method, failed',
            [$refused['destination'], $refused['status']],
            ['source', 'failed']
        );
        Suite::check(
            '…with the gateway\'s reason kept for finance',
            str_contains((string) ($refused['failure_reason'] ?? ''), 'refund_amount'),
            'failure_reason was: ' . json_encode($refused['failure_reason'] ?? null)
        );
        Suite::equals('…and it is not quietly moved into the wallet', round($wpBalance($wpBrandId), 2), $before);
    }

    // --- a wallet payment's original method is the wallet ------------------
    [$walletBooking, $walletTotal] = $wpPayableBooking(5000);
    $wpAdminId = (int) (Database::scalar('SELECT MIN(`id`) FROM `admins`') ?? 0);

    if ($walletBooking > 0 && $wpAdminId > 0) {
        $wpFundWallet($wpBrandId, $walletTotal);
        Http::post("/payments/booking/{$walletBooking}", ['gateway' => 'wallet'], 'brand');

        $before = round($wpBalance($wpBrandId), 2);

        BookingService::cancel($walletBooking, $wpAdminId, 'Integration: cancelling a booking paid from the wallet.', 'admin');

        $walletRefund = Database::selectOne(
            'SELECT * FROM `refunds` WHERE `booking_id` = :id ORDER BY `id` DESC LIMIT 1',
            ['id' => $walletBooking]
        ) ?? [];

        Suite::equals(
            'a booking paid from the wallet is refunded to the wallet, its original method',
            [(string) ($walletRefund['destination'] ?? ''), (string) ($walletRefund['status'] ?? '')],
            ['wallet', 'completed']
        );
        Suite::equals('…in full', round($wpBalance($wpBrandId) - $before, 2), round($walletTotal, 2));
    }

    // -----------------------------------------------------------------
    Suite::group('Refunds — money already on its way back is not refunded twice');

    RateLimiter::clear('throttle:booking:user:' . $wpBrandId);

    [$flightBooking, $flightTotal] = $wpPayableBooking(6000);

    if ($flightBooking > 0) {
        $flightPayment = $wpPlantPayment($flightBooking, $flightTotal, 'cashfree', 'ZPAY-IT-FLIGHT-' . $flightBooking);
        $flightRow     = static fn (): array => Database::selectOne('SELECT * FROM `payments` WHERE `id` = :id', ['id' => $flightPayment]) ?? [];

        $first = PaymentService::refundBooking($flightBooking, $flightTotal, 'other', 'admin');

        Suite::equals(
            'a full refund to Cashfree waits for its confirmation',
            [$first['destination'], $first['status']],
            ['source', 'processing']
        );
        Suite::equals('…and leaves nothing refundable while it does', PaymentService::refundableBalance($flightRow()), 0.0);

        $before = round($wpBalance($wpBrandId), 2);

        try {
            PaymentService::refundBooking($flightBooking, 100, 'other', 'admin', null, 'wallet');
            Suite::check('a wallet refund on top of it is refused', false, 'accepted: the brand is paid twice once Cashfree confirms');
        } catch (HttpException $e) {
            Suite::equals(
                'a wallet refund on top of it is refused',
                [$e->status(), $e->getMessage()],
                [422, 'There is nothing left to refund on this payment.']
            );
        }

        Suite::equals('…so the wallet is not credited', round($wpBalance($wpBrandId), 2), $before);

        try {
            PaymentService::parkRefundForApproval($flightBooking, 100, 'other', 'admin', null, 'source');
            Suite::check('nor can another be parked for approval', false, 'a refund was parked');
        } catch (HttpException $e) {
            Suite::equals('nor can another be parked for approval', $e->status(), 422);
        }

        // Cashfree reports it failed: the money never left, so it is
        // refundable again.
        Database::update('refunds', ['status' => 'failed', 'failure_reason' => 'IT: reported failed'], ['id' => (int) $first['id']]);

        Suite::equals(
            'a refund the gateway reports failed frees its amount again',
            PaymentService::refundableBalance($flightRow()),
            round($flightTotal, 2)
        );

        // A partial refund in flight reserves only its own amount.
        $partial = PaymentService::refundBooking($flightBooking, 1000, 'other', 'admin');
        $rest    = PaymentService::refundBooking($flightBooking, $flightTotal, 'other', 'admin', null, 'wallet');

        Suite::equals(
            'with ₹1,000 on its way back, a full-amount wallet refund is cut to the rest',
            [(string) $partial['status'], round((float) $rest['amount'], 2)],
            ['processing', round($flightTotal - 1000, 2)]
        );

        PaymentService::completeRefund((int) $partial['id']);

        $settled = $flightRow();
        Suite::equals(
            'once both complete, exactly the amount paid has been refunded',
            [round((float) ($settled['amount_refunded'] ?? 0), 2), (string) ($settled['status'] ?? '')],
            [round($flightTotal, 2), 'refunded']
        );
    }

    // -----------------------------------------------------------------
    Suite::group('Disputes — the refund is sent once the resolution is saved');

    /**
     * A booking paid at Cashfree, then disputed by the brand.
     *
     * @return array{0:int,1:float,2:int,3:int} booking, total, payment, dispute
     */
    $wpDisputed = static function (float $amount, string $tag) use ($wpPayableBooking, $wpPlantPayment, $wpBrandId): array {
        RateLimiter::clear('throttle:booking:user:' . $wpBrandId);

        [$bookingId, $total] = $wpPayableBooking($amount);

        if ($bookingId <= 0) {
            return [0, 0.0, 0, 0];
        }

        $paymentId = $wpPlantPayment($bookingId, $total, 'cashfree', 'ZPAY-IT-' . $tag . '-' . $bookingId);

        BookingService::markPaid($bookingId, $paymentId);
        BookingService::dispute($bookingId, $wpBrandId, 'poor_quality', 'Integration: the delivered work does not match the brief.');

        $disputeId = (int) (Database::scalar(
            "SELECT `id` FROM `booking_disputes` WHERE `booking_id` = :id AND `status` = 'open'",
            ['id' => $bookingId]
        ) ?? 0);

        return [$bookingId, $total, $paymentId, $disputeId];
    };

    $wpBookingRow = static fn (int $id): array => Database::selectOne(
        'SELECT `status`, `payment_status`, `escrow_status` FROM `bookings` WHERE `id` = :id',
        ['id' => $id]
    ) ?? [];

    // --- the default destination: back to Cashfree, after the commit ------
    $creatorWallet = Database::selectOne('SELECT `balance` FROM `wallets` WHERE `user_id` = :id', ['id' => $wpCreatorId]);

    [$splitBooking, $splitTotal, $splitPayment, $splitDispute] = $wpDisputed(9000, 'SPLIT');

    if ($splitDispute > 0 && $wpAdminId > 0) {
        $mark     = count($wpCfSent());
        $resolved = DisputeService::resolve($splitDispute, 'resolved_split', 2000, 'source', 1000, 'Integration: split the payment.', $wpAdminId);
        $refund   = $resolved['refund'] ?? [];

        Suite::equals(
            'a dispute refund goes back to the original method by default, accepted by Cashfree',
            [(string) ($refund['destination'] ?? ''), (string) ($refund['status'] ?? ''), round((float) ($refund['amount'] ?? 0), 2)],
            ['source', 'processing', 2000.00]
        );
        Suite::equals('…asked of Cashfree exactly once', count($wpCfSent()) - $mark, 1);
        Suite::equals(
            '…after the resolution was saved: the dispute is closed and the booking completed',
            [
                (string) (Database::scalar('SELECT `status` FROM `booking_disputes` WHERE `id` = :id', ['id' => $splitDispute]) ?? ''),
                (string) ($wpBookingRow($splitBooking)['status'] ?? ''),
            ],
            ['resolved_split', 'completed']
        );

        // Cashfree confirms days later. The booking is finished; its escrow
        // must not flip back to "held".
        $escrowBefore = (string) ($wpBookingRow($splitBooking)['escrow_status'] ?? '');
        PaymentService::completeRefund((int) ($refund['id'] ?? 0));
        $after = $wpBookingRow($splitBooking);

        Suite::equals(
            'a partial refund confirmed after the booking finished leaves its escrow as the resolution set it',
            [(string) ($after['escrow_status'] ?? ''), (string) ($after['payment_status'] ?? '')],
            [$escrowBefore === '' ? 'refunded' : $escrowBefore, 'partially_refunded']
        );
        Suite::check('…which is not "held"', ($after['escrow_status'] ?? '') !== 'held', json_encode($after));
    }

    // The creator's settlement was credited to the shared it-creator wallet;
    // put it back as it was.
    if ($creatorWallet === null) {
        Database::execute('DELETE FROM `wallet_transactions` WHERE `user_id` = :id', ['id' => $wpCreatorId]);
        Database::execute('DELETE FROM `wallets` WHERE `user_id` = :id', ['id' => $wpCreatorId]);
    } else {
        Database::update('wallets', ['balance' => $creatorWallet['balance']], ['user_id' => $wpCreatorId]);
    }

    // --- an admin's wallet choice is credited at once ---------------------
    [$walletDisputeBooking, $walletDisputeTotal, , $walletDispute] = $wpDisputed(5000, 'WDSP');

    if ($walletDispute > 0 && $wpAdminId > 0) {
        $mark     = count($wpCfSent());
        $before   = round($wpBalance($wpBrandId), 2);
        $resolved = DisputeService::resolve($walletDispute, 'resolved_brand', $walletDisputeTotal, 'wallet', 0, 'Integration: refund the brand in full.', $wpAdminId);
        $after    = $wpBookingRow($walletDisputeBooking);

        Suite::equals(
            'a dispute refund the admin sends to the wallet completes at once',
            [(string) ($resolved['refund']['destination'] ?? ''), (string) ($resolved['refund']['status'] ?? '')],
            ['wallet', 'completed']
        );
        Suite::equals('…crediting the whole booking', round($wpBalance($wpBrandId) - $before, 2), round($walletDisputeTotal, 2));
        Suite::equals('…without asking Cashfree', count($wpCfSent()), $mark);
        Suite::equals(
            '…and the booking ends refunded',
            [(string) ($after['status'] ?? ''), (string) ($after['payment_status'] ?? ''), (string) ($after['escrow_status'] ?? '')],
            ['refunded', 'refunded', 'refunded']
        );
    }

    // --- a refusal is reported, and the payment stays refundable ----------
    [$refuseBooking, $refuseTotal, $refusePayment, $refuseDispute] = $wpDisputed(7000, 'REFUSE');

    if ($refuseDispute > 0 && $wpAdminId > 0) {
        $resolved = DisputeService::resolve($refuseDispute, 'resolved_brand', 1500, 'source', 0, 'Integration: partial refund the gateway refuses.', $wpAdminId);

        Suite::equals(
            'a dispute refund the gateway refuses comes back failed on the original method',
            [(string) ($resolved['refund']['destination'] ?? ''), (string) ($resolved['refund']['status'] ?? '')],
            ['source', 'failed']
        );
        Suite::check(
            '…with the gateway\'s reason, for the admin\'s message',
            str_contains((string) ($resolved['refund']['failure_reason'] ?? ''), 'refund_amount'),
            json_encode($resolved['refund']['failure_reason'] ?? null)
        );
        Suite::equals(
            '…and the whole payment is still refundable, to re-issue',
            PaymentService::refundableBalance(Database::selectOne('SELECT * FROM `payments` WHERE `id` = :id', ['id' => $refusePayment]) ?? []),
            round($refuseTotal, 2)
        );
    }

    // --- a conflict refuses before any money is recorded or sent ----------
    [$staleBooking, , $stalePayment, $staleDispute] = $wpDisputed(6500, 'STALE');

    if ($staleDispute > 0 && $wpAdminId > 0) {
        // Cancelled from `disputed` while the dispute stayed open — its own
        // full refund goes to Cashfree as usual.
        BookingService::cancel($staleBooking, $wpAdminId, 'Integration: cancelled while the dispute is open.', 'admin');

        $refundsBefore = (int) (Database::scalar('SELECT COUNT(*) FROM `refunds` WHERE `booking_id` = :id', ['id' => $staleBooking]) ?? 0);
        $mark          = count($wpCfSent());

        try {
            DisputeService::resolve($staleDispute, 'resolved_brand', 500, 'source', 0, 'Integration: this booking is already cancelled.', $wpAdminId);
            Suite::check('resolving a dispute whose booking moved on is refused', false, 'it was resolved');
        } catch (HttpException $e) {
            Suite::equals('resolving a dispute whose booking moved on is refused', $e->status(), 409);
        }

        Suite::equals(
            '…before any refund was recorded',
            (int) (Database::scalar('SELECT COUNT(*) FROM `refunds` WHERE `booking_id` = :id', ['id' => $staleBooking]) ?? 0),
            $refundsBefore
        );
        Suite::equals('…or sent to Cashfree', count($wpCfSent()), $mark);
        Suite::equals(
            '…and the dispute is still open',
            (string) (Database::scalar('SELECT `status` FROM `booking_disputes` WHERE `id` = :id', ['id' => $staleDispute]) ?? ''),
            'open'
        );
    }

    // --- a dispute cannot be resolved twice --------------------------------
    if ($walletDispute > 0 && $wpAdminId > 0) {
        try {
            DisputeService::resolve($walletDispute, 'resolved_brand', 100, 'wallet', 0, 'Integration: a second resolution.', $wpAdminId);
            Suite::check('a resolved dispute cannot be resolved again', false, 'it was resolved twice');
        } catch (HttpException $e) {
            Suite::equals('a resolved dispute cannot be resolved again', $e->status(), 409);
        }
    }
}

if (is_resource($wpCfMock)) {
    proc_terminate($wpCfMock);
    proc_close($wpCfMock);
}

@unlink($wpCfLog);
@unlink($wpCfLog . '.out');

// -----------------------------------------------------------------
// The admin panel, over HTTP (session cookie + CSRF), for what only the
// controllers decide: how a dispute refund's outcome is reported, and what
// the booking screen offers while a refund is still on its way back.
//
// The running server talks to whatever Cashfree its own config names and,
// locally, holds no Cashfree credentials — so a refund to the original
// method fails there, which is exactly the outcome to report.
// -----------------------------------------------------------------
$wpAdminBase  = (string) preg_replace('#/api/v\d+/?$#', '', Http::baseUrl());
$wpAdminEmail = 'it-wallet-admin@zuste.test';
$wpAdminPass  = 'ItWallet!' . bin2hex(random_bytes(4));
$wpAdminJar   = (string) tempnam(sys_get_temp_dir(), 'it-wp-jar');

/** @return array{status:int,body:string,headers:array<string,string>} */
$wpPanel = static function (string $method, string $path, array $fields = [], bool $json = false) use ($wpAdminBase, $wpAdminJar): array {
    $handle  = curl_init($wpAdminBase . $path);
    $headers = [];

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $wpAdminJar,
        CURLOPT_COOKIEFILE     => $wpAdminJar,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'zusty-it-wallet-admin/1.0',
        CURLOPT_HTTPHEADER     => $json ? ['Accept: application/json'] : [],
        CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $body   = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $headers];
};

$wpCsrf = static function (string $html): string {
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    return preg_match('/name="csrf-token"\s+content="([^"]+)"/', $html, $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES)
        : '';
};

$wpDropAdmin = static function () use ($wpAdminEmail): void {
    foreach (Database::column('SELECT `id` FROM `admins` WHERE `email` = :e', ['e' => $wpAdminEmail]) as $id) {
        try {
            Database::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = :id', ['id' => (int) $id]);
            Database::execute('DELETE FROM `admin_permissions` WHERE `admin_id` = :id', ['id' => (int) $id]);
            Database::execute('DELETE FROM `admins` WHERE `id` = :id', ['id' => (int) $id]);
        } catch (\Throwable) {
            // Referenced from an audit row: retire it under another address.
            Database::update('admins', [
                'email'      => 'retired-' . (int) $id . '-' . bin2hex(random_bytes(3)) . '@zuste.test',
                'status'     => 'inactive',
                'deleted_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $id]);
        }
    }
};

$wpDropAdmin();

$wpPanelAdminId = (int) Database::insert('admins', [
    'name'                 => 'IT Wallet Admin',
    'email'                => $wpAdminEmail,
    'password_hash'        => Crypto::hashPassword($wpAdminPass),
    'role_id'              => (int) (Database::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1') ?? 0),
    'status'               => 'active',
    'must_change_password' => 0,
    'created_at'           => date('Y-m-d H:i:s'),
]);

$wpLoginPage = $wpPanel('GET', '/admin/login');
$wpLogin     = $wpPanel('POST', '/admin/login', [
    '_csrf'    => $wpCsrf($wpLoginPage['body']),
    'email'    => $wpAdminEmail,
    'password' => $wpAdminPass,
]);

$wpPanelCsrf = $wpCsrf($wpPanel('GET', '/admin/account/password')['body']) ?: $wpCsrf($wpPanel('GET', '/admin')['body']);
$wpSignedIn  = $wpLogin['status'] === 302
    && !str_contains((string) ($wpLogin['headers']['location'] ?? ''), '/admin/login')
    && $wpPanelCsrf !== '';

if (!$wpSignedIn) {
    Suite::skipGroup('Admin panel — dispute and booking refunds', 'the probe admin could not sign in: HTTP ' . $wpLogin['status']);
} else {
    Suite::group('Admin panel — dispute and booking refunds');

    RateLimiter::clear('throttle:booking:user:' . $wpBrandId);

    [$panelBooking, $panelTotal] = $wpPayableBooking(8000);

    if ($panelBooking > 0) {
        $panelPayment = $wpPlantPayment($panelBooking, $panelTotal, 'cashfree', 'ZPAY-IT-PANEL-' . $panelBooking);
        BookingService::markPaid($panelBooking, $panelPayment);
        BookingService::dispute($panelBooking, $wpBrandId, 'poor_quality', 'Integration: resolved from the admin panel.');

        $panelDispute = (int) (Database::scalar(
            "SELECT `id` FROM `booking_disputes` WHERE `booking_id` = :id AND `status` = 'open'",
            ['id' => $panelBooking]
        ) ?? 0);

        $resolve = $wpPanel('POST', '/admin/disputes/' . $panelDispute . '/resolve', [
            '_csrf'          => $wpPanelCsrf,
            'outcome'        => 'resolved_brand',
            'refund_amount'  => 1000,
            'payout_amount'  => 0,
            'note'           => 'Integration: a refund the gateway cannot take.',
            // No refund_destination: the original payment method by default.
        ], true);

        $resolveJson = json_decode($resolve['body'], true) ?: [];

        Suite::equals(
            'the panel resolves the dispute and returns the refund it sent to the original method',
            [$resolve['status'], $resolveJson['data']['refund_destination'] ?? null],
            [200, 'source']
        );
        Suite::equals('…which failed, with no Cashfree credentials on this server', $resolveJson['data']['refund_status'] ?? null, 'failed');
        Suite::check(
            '…and the admin is told so, and how to re-issue it — not just "Dispute resolved."',
            str_contains((string) ($resolveJson['message'] ?? ''), 'failed')
                && str_contains((string) ($resolveJson['message'] ?? ''), 'still refundable'),
            'message was: ' . (string) ($resolveJson['message'] ?? $resolve['body'])
        );

        $page = $wpPanel('GET', '/admin/bookings/' . $panelBooking);
        Suite::check(
            'the booking still offers the whole amount for a fresh refund',
            str_contains($page['body'], 'action="/admin/bookings/' . $panelBooking . '/refund"')
                && str_contains($page['body'], 'max="' . (string) round($panelTotal, 2) . '"'),
            'HTTP ' . $page['status']
        );

        // A Cashfree refund for the whole amount, accepted and not yet
        // confirmed: nothing is left to refund until it completes or fails.
        Database::insert('refunds', [
            'reference'         => 'ZRF-IT-' . $panelBooking,
            'payment_id'        => $panelPayment,
            'booking_id'        => $panelBooking,
            'user_id'           => $wpBrandId,
            'amount'            => $panelTotal,
            'currency'          => 'INR',
            'destination'       => 'source',
            'reason'            => 'other',
            'status'            => 'processing',
            'initiated_by_type' => 'admin',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        $page = $wpPanel('GET', '/admin/bookings/' . $panelBooking);
        Suite::check(
            'while it is processing, the booking screen offers no refund form',
            $page['status'] === 200 && !str_contains($page['body'], 'action="/admin/bookings/' . $panelBooking . '/refund"'),
            'HTTP ' . $page['status']
        );

        $before = round($wpBalance($wpBrandId), 2);
        $double = $wpPanel('POST', '/admin/bookings/' . $panelBooking . '/refund', [
            '_csrf'       => $wpPanelCsrf,
            'amount'      => 500,
            'destination' => 'wallet',
            'reason'      => 'Integration: a second refund on top of one in flight.',
        ], true);

        Suite::equals('…and a wallet refund on top of it is refused with 422', $double['status'], 422);
        Suite::check(
            '…saying nothing is left to refund',
            str_contains((string) (json_decode($double['body'], true)['message'] ?? ''), 'nothing left to refund'),
            $double['body']
        );
        Suite::equals('…so the wallet is not credited', round($wpBalance($wpBrandId), 2), $before);

        $financePage = $wpPanel('GET', '/admin/payments?q=' . rawurlencode('ITWP' . str_pad((string) $panelPayment, 10, '0', STR_PAD_LEFT)));
        Suite::check(
            'Finance → Payments shows the refund in progress and offers no refund',
            str_contains($financePage['body'], ' in progress</div>')
                && !str_contains($financePage['body'], 'openRefund(' . $panelPayment . ','),
            'HTTP ' . $financePage['status']
        );
    }
}

// -----------------------------------------------------------------
Suite::group('Wallet and payments — cleanup');

foreach ($wpBookings as $id) {
    Database::execute('DELETE FROM `booking_disputes` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `coupon_redemptions` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `platform_earnings` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `invoices` WHERE `booking_id` = :id', ['id' => $id]);
    Database::execute("DELETE FROM `wallet_transactions` WHERE `reference_type` = 'booking' AND `reference_id` = :id", ['id' => $id]);
    Database::execute('DELETE FROM `refunds` WHERE `booking_id` = :id', ['id' => $id]);
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

// The top-up orders it raised, which carry no booking.
Database::execute(
    "DELETE FROM `payments` WHERE `user_id` = :id AND `purpose` = 'wallet_topup'",
    ['id' => $wpBrandId]
);

$wpRestoreSettings();

// The probe admin, once the disputes it resolved are gone.
$wpDropAdmin();
@unlink($wpAdminJar);

Suite::equals(
    'the suite left no refunds behind',
    (int) (Database::scalar('SELECT COUNT(*) FROM `refunds` WHERE `user_id` = :id', ['id' => $wpBrandId]) ?? -1),
    0
);

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

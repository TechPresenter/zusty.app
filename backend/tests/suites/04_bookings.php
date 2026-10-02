<?php

declare(strict_types=1);

/**
 * Bookings — the marketplace transaction lifecycle.
 *
 * Covers the price quote, request creation, the BookingService state machine
 * (both a legal and an illegal transition at every interesting node), the
 * authorisation boundary between brand, creator and a third party, and the
 * listing/filter/pagination contract.
 *
 * Every booking this suite creates is deleted at the end, and the two fixture
 * mutations it makes (an approved KYC profile, and promoting the throwaway
 * creator's own draft profile so it is bookable) are reverted.
 */

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\RateLimiter;
use Zuste\Helpers\Money;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// A run makes hundreds of calls in seconds; without this the throttle
// fires mid-suite and the cascade of 429s hides real failures.
Suite::clearRateLimits();

// -----------------------------------------------------------------
// Fixtures
//
// POST /bookings is gated on `brand` + `kyc`, and POST accept on
// `creator` + `kyc`. KYC approval is an admin action with no public API, so
// the profiles are written directly — for the throwaway `it-` accounts only,
// and removed again at the end of the file.
// -----------------------------------------------------------------

$bkBrand    = Actors::create('brand', 'brand');
$bkCreator  = Actors::create('creator', 'creator');
// A second creator account: needed to prove that a creator cannot act on a
// booking addressed to somebody else. Costs one registration against the
// 5-per-15-minutes signup throttle on the first run only.
$bkOutsider = Actors::create('outsider', 'creator');

$bkBrandId    = Actors::id('brand');
$bkCreatorId  = Actors::id('creator');
$bkOutsiderId = Actors::id('outsider');

/** Booking ids created by this suite, deleted in the teardown. */
$bkIds = [];

/** User ids whose kyc_profiles row this suite inserted (so it can remove it). */
$bkKycInserted = [];

/** Original `creators.status` for the throwaway creator, restored in teardown. */
$bkCreatorRow            = null;
$bkOriginalCreatorStatus = null;

$bkApproveKyc = static function (int $userId, string $kind) use (&$bkKycInserted): void {
    if ($userId <= 0) {
        return;
    }

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
            'reference'            => 'KYC-IT-' . strtoupper($kind[0]) . '-' . $userId,
            'submitted_at'         => $now,
            'approved_at'          => $now,
            'submission_count'     => 1,
            'completeness'         => 100,
            'is_identity_verified' => 1,
            'created_at'           => $now,
        ]);

        $bkKycInserted[] = $userId;

        return;
    }

    Database::update(
        'kyc_profiles',
        ['status' => 'approved', 'approved_at' => $now, 'expires_on' => null],
        ['id' => (int) $existing['id']]
    );
};

$bkReady = $bkBrand !== null && $bkCreator !== null && $bkOutsider !== null
    && $bkBrandId > 0 && $bkCreatorId > 0 && $bkOutsiderId > 0;

if ($bkReady) {
    $bkApproveKyc($bkBrandId, 'brand');
    $bkApproveKyc($bkCreatorId, 'creator');
    $bkApproveKyc($bkOutsiderId, 'creator');

    $bkCreatorRow = Database::selectOne(
        'SELECT `id`, `status` FROM `creators` WHERE `user_id` = :u AND `deleted_at` IS NULL',
        ['u' => $bkCreatorId]
    );

    if ($bkCreatorRow !== null) {
        $bkOriginalCreatorStatus = (string) $bkCreatorRow['status'];

        Database::update(
            'creators',
            ['status' => 'approved', 'availability_status' => 'available'],
            ['id' => (int) $bkCreatorRow['id']]
        );
    }

    // The booking bucket is 20 per 600s per user; a third consecutive run of
    // this suite would otherwise be throttled out of its own fixtures. Only
    // this suite's throwaway actors are cleared.
    RateLimiter::clear('throttle:booking:user:' . $bkBrandId);

    foreach ([$bkBrandId, $bkCreatorId, $bkOutsiderId] as $bkUserId) {
        RateLimiter::clear('throttle:global:user:' . $bkUserId);
    }
}

$bkCreatorProfileId = $bkCreatorRow === null ? 0 : (int) $bkCreatorRow['id'];

// Pricing policy, read from the same settings the platform is configured with
// but recomputed independently of Money::breakdown so the arithmetic is a real
// assertion rather than a tautology.
$bkFeePercent        = Config::settingFloat('commission', 'platform_fee_percent', 10.0);
$bkCommissionPercent = Config::settingFloat('commission', 'creator_commission_percent', 10.0);
$bkTaxPercent        = Config::settingFloat('commission', 'tax_percent', 18.0);
$bkMinAmount         = Config::settingFloat('booking', 'min_booking_amount', 1000);

/** @return array{fee:float,tax:float,total:float,earning:float} */
$bkExpected = static function (float $amount) use ($bkFeePercent, $bkCommissionPercent, $bkTaxPercent): array {
    $fee        = round($amount * ($bkFeePercent / 100), 2);
    $commission = round($amount * ($bkCommissionPercent / 100), 2);
    $tax        = round($fee * ($bkTaxPercent / 100), 2);

    return [
        'fee'     => $fee,
        'tax'     => $tax,
        'total'   => round($amount + $fee + $tax, 2),
        'earning' => round($amount - $commission, 2),
    ];
};

$bkAmount   = 25000.0;
$bkPricing  = $bkExpected($bkAmount);

/** Creates a booking as the brand and remembers its id for teardown. */
$bkCreate = static function (array $overrides = []) use (&$bkIds, $bkCreatorProfileId, $bkAmount): Res {
    $res = Http::post('/bookings', array_merge([
        'creator_id'       => $bkCreatorProfileId,
        'title'            => 'IT Booking ' . bin2hex(random_bytes(4)),
        'description'      => 'Integration test booking — safe to delete.',
        'campaign_type'    => 'Instagram Post',
        'platform'         => 'instagram',
        'deliverable_type' => 'post',
        'posts_count'      => 1,
        'amount'           => $bkAmount,
    ], $overrides), 'brand');

    $id = (int) ($res->at('data.booking.id') ?? 0);

    if ($id > 0) {
        $bkIds[] = $id;
    }

    return $res;
};

/** @return list<int> ids in a listing response */
$bkListIds = static fn (Res $res): array => array_map(
    static fn (array $row): int => (int) ($row['id'] ?? 0),
    $res->list()
);

// =================================================================
Suite::group('Bookings — quote');

$quoteAnon = Http::get('/bookings/quote', ['amount' => 10000]);
Suite::status('GET /bookings/quote without a token is 401', $quoteAnon, 401);

if (!$bkReady) {
    Suite::skipGroup(
        'Bookings — quote',
        'could not obtain brand/creator/outsider sessions: ' . Actors::lastError()
    );
} else {
    $quoteZero = Http::get('/bookings/quote', ['amount' => 0], 'brand');
    Suite::status('a quote with no budget is rejected', $quoteZero, 422);

    $quote = Http::get('/bookings/quote', ['amount' => $bkAmount], 'brand');
    Suite::ok('GET /bookings/quote returns a breakdown', $quote, 200);

    Suite::equals(
        'the quoted amount is the amount that was asked for',
        (float) ($quote->at('data.breakdown.amount') ?? 0),
        $bkAmount
    );

    Suite::equals(
        'the platform commission is shown as a fee',
        (float) ($quote->at('data.breakdown.platform_fee') ?? -1),
        $bkPricing['fee']
    );

    Suite::equals(
        'the platform fee percentage is disclosed',
        (float) ($quote->at('data.breakdown.platform_fee_percent') ?? -1),
        $bkFeePercent
    );

    Suite::equals(
        'the creator commission percentage is disclosed',
        (float) ($quote->at('data.breakdown.commission_percent') ?? -1),
        $bkCommissionPercent
    );

    Suite::equals(
        'tax is charged on the platform fee, not on the whole budget',
        (float) ($quote->at('data.breakdown.tax_amount') ?? -1),
        $bkPricing['tax']
    );

    Suite::check(
        'the quoted total equals amount + platform fee + tax',
        static function () use ($quote): bool {
            $b = $quote->at('data.breakdown');

            if (!is_array($b)) {
                return false;
            }

            return Money::equals(
                (float) $b['total_amount'],
                (float) $b['amount'] + (float) $b['platform_fee'] + (float) $b['tax_amount']
            );
        },
        'breakdown did not add up: ' . $quote->describe()
    );

    Suite::equals(
        'the creator earning is the budget less the creator commission',
        (float) ($quote->at('data.breakdown.creator_earning') ?? -1),
        $bkPricing['earning']
    );

    Suite::check(
        'the quote carries currency, symbol, tax label and formatted strings',
        static function () use ($quote): bool {
            $data = $quote->data();

            return ($data['currency'] ?? '') !== ''
                && ($data['symbol'] ?? '') !== ''
                && ($data['tax_label'] ?? '') !== ''
                && is_array($data['formatted'] ?? null)
                && ($data['formatted']['total'] ?? '') !== '';
        },
        $quote->describe()
    );

    if ($bkCreatorProfileId > 0) {
        $quoteForCreator = Http::get(
            '/bookings/quote',
            ['amount' => $bkAmount, 'creator_id' => $bkCreatorProfileId],
            'brand'
        );
        Suite::ok('a quote can be scoped to one creator', $quoteForCreator, 200);
        Suite::equals(
            'a creator without a rate override quotes the platform default commission',
            (float) ($quoteForCreator->at('data.breakdown.commission_percent') ?? -1),
            $bkCommissionPercent
        );
    }
}

// =================================================================
Suite::group('Bookings — create');

if (!$bkReady || $bkCreatorProfileId === 0) {
    Suite::skipGroup(
        'Bookings — create',
        $bkReady
            ? 'the throwaway creator account has no creators row to book against'
            : 'no brand/creator sessions: ' . Actors::lastError()
    );
} else {
    $bkAnonCreate = Http::post('/bookings', ['creator_id' => $bkCreatorProfileId, 'amount' => $bkAmount]);
    Suite::status('POST /bookings without a token is 401', $bkAnonCreate, 401);

    $bkAsCreator = Http::post('/bookings', [
        'creator_id' => $bkCreatorProfileId,
        'title'      => 'IT should not exist',
        'platform'   => 'instagram',
        'amount'     => $bkAmount,
    ], 'creator');
    Suite::status('a creator account cannot open a booking request', $bkAsCreator, 403);

    $bkEmpty = Http::post('/bookings', [], 'brand');
    Suite::status('POST /bookings with no payload is rejected', $bkEmpty, 422);
    Suite::check(
        'the validation failure names the missing fields',
        static function () use ($bkEmpty): bool {
            $errors = $bkEmpty->at('data.errors') ?? $bkEmpty->at('errors');

            return is_array($errors)
                && array_key_exists('creator_id', $errors)
                && array_key_exists('amount', $errors);
        },
        $bkEmpty->describe()
    );

    $bkUnknownCreator = $bkCreate(['creator_id' => 999999999]);
    Suite::status('a booking for a creator that does not exist is refused', $bkUnknownCreator, [404, 422]);

    $bkTooCheap = $bkCreate(['amount' => max(1.0, $bkMinAmount - 1)]);
    Suite::status('a booking below the platform minimum is refused', $bkTooCheap, 422);
    Suite::check(
        'the refusal states the minimum',
        str_contains(strtolower($bkTooCheap->message()), 'minimum'),
        'message was: ' . $bkTooCheap->message()
    );

    // -- the real one -------------------------------------------------
    $bkA = $bkCreate(['title' => 'IT Lifecycle A ' . bin2hex(random_bytes(3))]);
    Suite::ok('POST /bookings opens a request', $bkA, 201);

    $bkAId = (int) ($bkA->at('data.booking.id') ?? 0);
    Suite::notEmpty('the created booking has an id', $bkAId);

    Suite::equals('a new booking starts as pending', (string) ($bkA->at('data.booking.status') ?? ''), 'pending');
    Suite::equals('a new booking is unpaid', (string) ($bkA->at('data.booking.payment_status') ?? ''), 'unpaid');
    Suite::equals('no escrow is held yet', (string) ($bkA->at('data.booking.escrow_status') ?? ''), 'none');
    Suite::equals(
        'the booking is addressed to the requested creator',
        (int) ($bkA->at('data.booking.creator_id') ?? 0),
        $bkCreatorProfileId
    );
    Suite::equals(
        'the booking is owned by the calling brand',
        (int) ($bkA->at('data.booking.brand_user_id') ?? 0),
        $bkBrandId
    );
    Suite::check(
        'the booking is issued a reference code',
        static function () use ($bkA): bool {
            $code = (string) ($bkA->at('data.booking.code') ?? '');

            return $code !== '' && $code !== 'PENDING' && str_starts_with($code, 'ZB');
        },
        'code was: ' . json_encode($bkA->at('data.booking.code'))
    );

    Suite::equals(
        'the stored total matches the quoted total for the same budget',
        (float) ($bkA->at('data.booking.total_amount') ?? -1),
        $bkPricing['total']
    );
    Suite::equals(
        'the stored creator earning matches the quote',
        (float) ($bkA->at('data.booking.creator_earning') ?? -1),
        $bkPricing['earning']
    );

    Suite::equals(
        'the brand sees itself as the brand side of the booking',
        (string) ($bkA->at('data.booking.viewer_role') ?? ''),
        'brand'
    );
    Suite::check(
        'a brand is not offered the accept action on its own request',
        ($bkA->at('data.booking.actions.can_accept') ?? true) === false,
        json_encode($bkA->at('data.booking.actions'))
    );
    Suite::check(
        'a pending request can still be cancelled',
        ($bkA->at('data.booking.actions.can_cancel') ?? false) === true,
        json_encode($bkA->at('data.booking.actions'))
    );
    Suite::check(
        'the timeline records the request',
        static function () use ($bkA): bool {
            $timeline = $bkA->at('data.booking.timeline');

            return is_array($timeline) && $timeline !== []
                && ($timeline[0]['to_status'] ?? '') === 'pending';
        },
        json_encode($bkA->at('data.booking.timeline'))
    );
}

// =================================================================
// Bookings against a seeded creator, as the app would do it. Guarded: an
// unseeded database has nothing to book.
Suite::group('Bookings — against a seeded creator');

$bkSeededCreator = $bkReady
    ? Database::selectOne(
        "SELECT c.`id` FROM `creators` c
          INNER JOIN `users` u ON u.`id` = c.`user_id`
          WHERE c.`status` = 'approved' AND c.`deleted_at` IS NULL AND u.`status` = 'active'
            AND c.`availability_status` <> 'unavailable'
            AND c.`user_id` NOT IN (:a, :b)
          ORDER BY c.`id` LIMIT 1",
        ['a' => $bkCreatorId, 'b' => $bkOutsiderId]
    )
    : null;

if ($bkSeededCreator === null) {
    Suite::skipGroup(
        'Bookings — against a seeded creator',
        $bkReady
            ? 'no seeded approved creators in this database — run scripts/install.php --seed-only'
            : 'no brand session'
    );
} else {
    $bkSeeded = $bkCreate([
        'creator_id' => (int) $bkSeededCreator['id'],
        'title'      => 'IT Seeded Creator ' . bin2hex(random_bytes(3)),
    ]);
    Suite::ok('a brand can book a seeded approved creator', $bkSeeded, 201);
    Suite::equals(
        'the seeded booking is addressed to that creator',
        (int) ($bkSeeded->at('data.booking.creator_id') ?? 0),
        (int) $bkSeededCreator['id']
    );
}

// =================================================================
// The state machine, as declared in BookingService::ALLOWED_TRANSITIONS:
//   pending          → accepted | rejected | countered | cancelled | expired
//   countered        → accepted | rejected | countered | cancelled | expired
//   accepted         → awaiting_payment | confirmed | cancelled | expired
//   confirmed        → in_progress | cancelled | disputed
//   in_progress      → delivered | cancelled | disputed
//   delivered        → completed | in_progress | disputed
//   rejected/cancelled/expired → (terminal)
// Completion additionally requires delivered|in_progress|disputed, and delivery
// requires confirmed|in_progress — both of which are only reachable through a
// captured payment (BookingService::markPaid), which no controller can trigger.
Suite::group('Bookings — state machine');

$bkAId = $bkAId ?? 0;

if (!$bkReady || $bkAId === 0) {
    Suite::skipGroup('Bookings — state machine', 'no booking was created to drive the machine with');
} else {
    // -- illegal from pending -----------------------------------------
    $illegalComplete = Http::post('/bookings/' . $bkAId . '/complete', [], 'brand');
    Suite::status('completing a booking that was never paid is refused', $illegalComplete, 422);
    Suite::check(
        'the refusal explains the status is wrong',
        str_contains(strtolower($illegalComplete->message()), 'cannot be completed'),
        'message was: ' . $illegalComplete->message()
    );

    $illegalDeliver = Http::post('/bookings/' . $bkAId . '/deliver', ['content_url' => 'https://example.test/it'], 'creator');
    Suite::status('delivering before the booking is confirmed is refused', $illegalDeliver, 422);
    Suite::check(
        'the refusal says only a confirmed booking can be delivered',
        str_contains(strtolower($illegalDeliver->message()), 'confirmed'),
        'message was: ' . $illegalDeliver->message()
    );

    // -- legal: pending → accepted ------------------------------------
    $accept = Http::post('/bookings/' . $bkAId . '/accept', [], 'creator');
    Suite::ok('the addressed creator can accept a pending request', $accept, 200);
    Suite::equals('accepting moves the booking to accepted', (string) ($accept->at('data.booking.status') ?? ''), 'accepted');
    Suite::notEmpty('the acceptance is timestamped', $accept->at('data.booking.accepted_at'));
    Suite::equals(
        'accepting does not mark the booking paid',
        (string) ($accept->at('data.booking.payment_status') ?? ''),
        'unpaid'
    );

    // -- illegal from accepted ----------------------------------------
    $acceptTwice = Http::post('/bookings/' . $bkAId . '/accept', [], 'creator');
    Suite::status('accepting an already-accepted booking is refused', $acceptTwice, 409);
    Suite::equals('the refusal is a status-transition conflict', $acceptTwice->code(), 'invalid_status_transition');

    $rejectAccepted = Http::post('/bookings/' . $bkAId . '/reject', ['reason' => 'IT changed my mind'], 'creator');
    Suite::status('rejecting a booking already accepted is refused', $rejectAccepted, 409);
    Suite::equals('rejecting an accepted booking is a transition conflict', $rejectAccepted->code(), 'invalid_status_transition');

    $completeUnpaid = Http::post('/bookings/' . $bkAId . '/complete', [], 'brand');
    Suite::status('an accepted-but-unpaid booking cannot be completed', $completeUnpaid, 422);

    $deliverUnpaid = Http::post('/bookings/' . $bkAId . '/deliver', ['content_url' => 'https://example.test/it'], 'creator');
    Suite::status('an accepted-but-unpaid booking cannot be delivered', $deliverUnpaid, 422);

    // -- legal: accepted → cancelled ----------------------------------
    $cancelNoReason = Http::post('/bookings/' . $bkAId . '/cancel', [], 'brand');
    Suite::status('cancelling without a reason is rejected', $cancelNoReason, 422);

    $cancel = Http::post('/bookings/' . $bkAId . '/cancel', ['reason' => 'IT teardown'], 'brand');
    Suite::ok('the brand can cancel a booking it has not paid for', $cancel, 200);
    Suite::equals('cancelling moves the booking to cancelled', (string) ($cancel->at('data.booking.status') ?? ''), 'cancelled');

    // -- illegal from cancelled (terminal) ----------------------------
    $cancelTwice = Http::post('/bookings/' . $bkAId . '/cancel', ['reason' => 'IT teardown again'], 'brand');
    Suite::status('a cancelled booking cannot be cancelled again', $cancelTwice, 409);
    Suite::equals('re-cancelling is a transition conflict', $cancelTwice->code(), 'invalid_status_transition');

    $acceptCancelled = Http::post('/bookings/' . $bkAId . '/accept', [], 'creator');
    Suite::status('a cancelled booking cannot be accepted', $acceptCancelled, 409);

    $counterCancelled = Http::post('/bookings/' . $bkAId . '/counter-offer', ['amount' => 20000], 'creator');
    Suite::status('a cancelled booking cannot be re-negotiated', $counterCancelled, 409);
}

// =================================================================
Suite::group('Bookings — reject path');

$bkB   = $bkReady && $bkCreatorProfileId > 0 ? $bkCreate(['title' => 'IT Reject B ' . bin2hex(random_bytes(3))]) : null;
$bkBId = $bkB === null ? 0 : (int) ($bkB->at('data.booking.id') ?? 0);

if ($bkBId === 0) {
    Suite::skipGroup('Bookings — reject path', 'could not create a booking to reject: ' . ($bkB?->describe() ?? 'no session'));
} else {
    $reject = Http::post('/bookings/' . $bkBId . '/reject', ['reason' => 'IT not a fit'], 'creator');
    Suite::ok('the addressed creator can decline a pending request', $reject, 200);
    Suite::equals('declining moves the booking to rejected', (string) ($reject->at('data.booking.status') ?? ''), 'rejected');
    Suite::equals(
        'the decline reason is stored',
        (string) ($reject->at('data.booking.rejection_reason') ?? ''),
        'IT not a fit'
    );

    $acceptRejected = Http::post('/bookings/' . $bkBId . '/accept', [], 'creator');
    Suite::status('a rejected booking cannot then be accepted', $acceptRejected, 409);
    Suite::equals('accepting a rejected booking is a transition conflict', $acceptRejected->code(), 'invalid_status_transition');

    $cancelRejected = Http::post('/bookings/' . $bkBId . '/cancel', ['reason' => 'IT after reject'], 'brand');
    Suite::status('a rejected booking cannot be cancelled — it is terminal', $cancelRejected, 409);
}

// =================================================================
Suite::group('Bookings — counter offer');

$bkC   = $bkReady && $bkCreatorProfileId > 0 ? $bkCreate(['title' => 'IT Counter C ' . bin2hex(random_bytes(3))]) : null;
$bkCId = $bkC === null ? 0 : (int) ($bkC->at('data.booking.id') ?? 0);

if ($bkCId === 0) {
    Suite::skipGroup('Bookings — counter offer', 'could not create a booking to negotiate on: ' . ($bkC?->describe() ?? 'no session'));
} elseif (!Config::settingBool('booking', 'allow_counter_offers', true)) {
    Suite::skipGroup('Bookings — counter offer', 'counter offers are disabled in settings');
} else {
    $counterAmount  = 18000.0;
    $counterPricing = $bkExpected($counterAmount);

    $lowball = Http::post('/bookings/' . $bkCId . '/counter-offer', ['amount' => max(1.0, $bkMinAmount - 1)], 'creator');
    Suite::status('a counter offer below the platform minimum is refused', $lowball, 422);

    $counter = Http::post('/bookings/' . $bkCId . '/counter-offer', [
        'amount'      => $counterAmount,
        'posts_count' => 2,
        'message'     => 'IT counter',
    ], 'creator');
    Suite::ok('the creator can counter a pending request', $counter, 200);
    Suite::equals('countering moves the booking to countered', (string) ($counter->at('data.booking.status') ?? ''), 'countered');

    $counterDetail = Http::get('/bookings/' . $bkCId, [], 'brand');
    Suite::ok('the brand can read the countered booking', $counterDetail, 200);
    Suite::check(
        'the counter offer is recorded and awaiting a response',
        static function () use ($counterDetail, $counterAmount): bool {
            $offers = $counterDetail->at('data.booking.offers');

            if (!is_array($offers) || $offers === []) {
                return false;
            }

            $latest = $offers[count($offers) - 1];

            return (string) ($latest['offered_by'] ?? '') === 'creator'
                && (string) ($latest['status'] ?? '') === 'pending'
                && abs((float) ($latest['amount'] ?? 0) - $counterAmount) < 0.005;
        },
        json_encode($counterDetail->at('data.booking.offers'))
    );
    Suite::check(
        'the brand is offered the accept-offer action',
        ($counterDetail->at('data.booking.actions.can_accept_offer') ?? false) === true,
        json_encode($counterDetail->at('data.booking.actions'))
    );

    $ownOffer = Http::post('/bookings/' . $bkCId . '/accept-offer', [], 'creator');
    Suite::status('the party that made an offer cannot accept it', $ownOffer, 403);
    Suite::check(
        'the refusal says so plainly',
        str_contains(strtolower($ownOffer->message()), 'your own offer'),
        'message was: ' . $ownOffer->message()
    );

    $acceptOffer = Http::post('/bookings/' . $bkCId . '/accept-offer', [], 'brand');
    Suite::ok('the other party can accept the counter offer', $acceptOffer, 200);
    Suite::equals('accepting an offer moves the booking to accepted', (string) ($acceptOffer->at('data.booking.status') ?? ''), 'accepted');
    Suite::equals(
        'accepting an offer re-prices the booking to the offered amount',
        (float) ($acceptOffer->at('data.booking.amount') ?? -1),
        $counterAmount
    );
    Suite::equals(
        'the total is recomputed from the offered amount',
        (float) ($acceptOffer->at('data.booking.total_amount') ?? -1),
        $counterPricing['total']
    );
    Suite::equals(
        'the deliverable count comes from the accepted offer',
        (int) ($acceptOffer->at('data.booking.posts_count') ?? 0),
        2
    );

    $acceptOfferAgain = Http::post('/bookings/' . $bkCId . '/accept-offer', [], 'brand');
    Suite::status('there is nothing left to accept once an offer is taken', $acceptOfferAgain, [404, 409]);
}

// =================================================================
// The offer chain is the one route into the booking that does NOT run through
// assertTransition(): BookingService::acceptOffer writes `status = accepted`
// unconditionally. Neither reject() nor cancel() supersedes an outstanding
// offer, so a terminal booking still has a `pending` row in `booking_offers`
// for accept-offer to act on.
Suite::group('Bookings — offer chain vs the state machine');

$bkD   = $bkReady && $bkCreatorProfileId > 0 ? $bkCreate(['title' => 'IT Offer D ' . bin2hex(random_bytes(3))]) : null;
$bkDId = $bkD === null ? 0 : (int) ($bkD->at('data.booking.id') ?? 0);

if ($bkDId === 0) {
    Suite::skipGroup('Bookings — offer chain vs the state machine', 'could not create a booking: ' . ($bkD?->describe() ?? 'no session'));
} elseif (!Config::settingBool('booking', 'allow_counter_offers', true)) {
    Suite::skipGroup('Bookings — offer chain vs the state machine', 'counter offers are disabled in settings');
} else {
    $brandCounter = Http::post('/bookings/' . $bkDId . '/counter-offer', ['amount' => 20000, 'posts_count' => 1], 'brand');
    Suite::ok('the brand can put an offer on the table', $brandCounter, 200);

    $declineCountered = Http::post('/bookings/' . $bkDId . '/reject', ['reason' => 'IT declined'], 'creator');
    Suite::ok('the creator can decline while an offer is outstanding', $declineCountered, 200);
    Suite::equals('the booking is now rejected', (string) ($declineCountered->at('data.booking.status') ?? ''), 'rejected');

    // `rejected` is terminal: ALLOWED_TRANSITIONS['rejected'] === [].
    $reviveByOffer = Http::post('/bookings/' . $bkDId . '/accept-offer', [], 'creator');
    Suite::status('accepting a stale offer cannot revive a rejected booking', $reviveByOffer, [404, 409, 422]);

    $afterRevive = Http::get('/bookings/' . $bkDId, [], 'brand');
    Suite::equals(
        'a rejected booking is still rejected after the offer chain is poked',
        (string) ($afterRevive->at('data.booking.status') ?? ''),
        'rejected'
    );
}

// =================================================================
Suite::group('Bookings — authorization');

$bkE   = $bkReady && $bkCreatorProfileId > 0 ? $bkCreate(['title' => 'IT Authz E ' . bin2hex(random_bytes(3))]) : null;
$bkEId = $bkE === null ? 0 : (int) ($bkE->at('data.booking.id') ?? 0);

if ($bkEId === 0) {
    Suite::skipGroup('Bookings — authorization', 'could not create a booking to probe: ' . ($bkE?->describe() ?? 'no session'));
} else {
    $showAnon = Http::get('/bookings/' . $bkEId);
    Suite::status('GET /bookings/{id} without a token is 401', $showAnon, 401);

    $showBrand = Http::get('/bookings/' . $bkEId, [], 'brand');
    Suite::ok('the brand that opened the booking can read it', $showBrand, 200);
    Suite::equals('and sees itself as the brand', (string) ($showBrand->at('data.booking.viewer_role') ?? ''), 'brand');

    $showCreator = Http::get('/bookings/' . $bkEId, [], 'creator');
    Suite::ok('the addressed creator can read the booking', $showCreator, 200);
    Suite::equals('and sees itself as the creator', (string) ($showCreator->at('data.booking.viewer_role') ?? ''), 'creator');

    $showOutsider = Http::get('/bookings/' . $bkEId, [], 'outsider');
    Suite::status('a third party is refused the booking detail', $showOutsider, [403, 404]);
    Suite::check(
        'the refusal does not leak the booking payload',
        !str_contains($showOutsider->body, '"total_amount"'),
        $showOutsider->describe()
    );

    $showMissing = Http::get('/bookings/999999999', [], 'brand');
    Suite::status('an unknown booking id is 404', $showMissing, 404);

    // A creator must not be able to accept work addressed to somebody else.
    // The outsider is a KYC-approved creator account, so the `creator` and
    // `kyc` gates both pass and the refusal has to come from the booking.
    $outsiderAccept = Http::post('/bookings/' . $bkEId . '/accept', [], 'outsider');
    Suite::status('a creator cannot accept a booking addressed to another creator', $outsiderAccept, 403);
    Suite::check(
        'and the refusal is an authorisation failure, not a KYC gate',
        $outsiderAccept->code() !== 'kyc_required',
        $outsiderAccept->describe()
    );

    $outsiderReject = Http::post('/bookings/' . $bkEId . '/reject', ['reason' => 'IT not mine'], 'outsider');
    Suite::status('a creator cannot decline somebody else\'s booking', $outsiderReject, 403);

    $outsiderCancel = Http::post('/bookings/' . $bkEId . '/cancel', ['reason' => 'IT not mine'], 'outsider');
    Suite::status('a third party cannot cancel a booking', $outsiderCancel, [403, 404]);

    $outsiderCounter = Http::post('/bookings/' . $bkEId . '/counter-offer', ['amount' => 20000], 'outsider');
    Suite::status('a third party cannot negotiate on a booking', $outsiderCounter, [403, 404]);

    // The brand must not be able to accept on the creator's behalf.
    $brandAccept = Http::post('/bookings/' . $bkEId . '/accept', [], 'brand');
    Suite::status('a brand cannot accept its own booking for the creator', $brandAccept, 403);

    $brandDeliver = Http::post('/bookings/' . $bkEId . '/deliver', ['content_url' => 'https://example.test/it'], 'brand');
    Suite::status('a brand cannot submit the creator\'s deliverables', $brandDeliver, 403);

    $creatorComplete = Http::post('/bookings/' . $bkEId . '/complete', [], 'creator');
    Suite::status('a creator cannot release its own escrow by completing', $creatorComplete, 403);

    $bkStillPending = Http::get('/bookings/' . $bkEId, [], 'brand');
    Suite::equals(
        'none of the refused calls changed the booking',
        (string) ($bkStillPending->at('data.booking.status') ?? ''),
        'pending'
    );
}

// =================================================================
Suite::group('Bookings — cancellation preview, invoice, review');

if ($bkEId === 0) {
    Suite::skipGroup('Bookings — cancellation preview, invoice, review', 'no booking available');
} else {
    $preview = Http::get('/bookings/' . $bkEId . '/cancellation-preview', [], 'brand');
    Suite::ok('the brand can preview what a cancellation would cost', $preview, 200);
    Suite::equals('an unpaid booking reports no payment', $preview->at('data.is_paid'), false);
    Suite::equals('and no cancellation fee', (float) ($preview->at('data.cancellation_fee') ?? -1), 0.0);
    Suite::equals('and nothing to refund', (float) ($preview->at('data.refund_amount') ?? -1), 0.0);
    Suite::equals(
        'the free-cancellation window matches the configured policy',
        (int) ($preview->at('data.free_window_days') ?? -1),
        Config::settingInt('booking', 'free_cancellation_days', 7)
    );
    Suite::check(
        'the message says nothing will be charged',
        str_contains(strtolower((string) $preview->at('data.message')), 'nothing will be charged'),
        (string) $preview->at('data.message')
    );

    // The free window is advertised to the client as `free_window_days`, and
    // the fee is charged when `delivery_due_at - now <= free_window_days`.
    // BookingService::create defaults `delivery_due_at` to
    // now + booking.default_delivery_days, which is also 7 — so a booking is
    // inside the late window the moment it is created and the free window can
    // never be used. Asserted here because it decides real money on a paid
    // booking.
    Suite::equals(
        'a booking created moments ago is not already inside the late-cancellation window',
        $preview->at('data.is_late'),
        false
    );

    $bkFarDue = $bkCreate([
        'title'           => 'IT Far Due F ' . bin2hex(random_bytes(3)),
        'delivery_due_at' => date('Y-m-d H:i:s', time() + (40 * 86400)),
    ]);
    $bkFarDueId = (int) ($bkFarDue->at('data.booking.id') ?? 0);

    if ($bkFarDueId === 0) {
        Suite::check('a booking with an explicit delivery date could be created', false, $bkFarDue->describe());
    } else {
        $farPreview = Http::get('/bookings/' . $bkFarDueId . '/cancellation-preview', [], 'brand');
        Suite::ok('a booking due well beyond the free window previews cleanly', $farPreview, 200);
        Suite::equals(
            'a delivery date far outside the free window is not late',
            $farPreview->at('data.is_late'),
            false
        );
    }

    $previewOutsider = Http::get('/bookings/' . $bkEId . '/cancellation-preview', [], 'outsider');
    Suite::status('a third party cannot preview a cancellation', $previewOutsider, [403, 404]);

    $invoice = Http::get('/bookings/' . $bkEId . '/invoice', [], 'brand');
    Suite::status('an unpaid booking has no invoice yet', $invoice, 404);

    $invoiceOutsider = Http::get('/bookings/' . $bkEId . '/invoice', [], 'outsider');
    Suite::status('a third party cannot fetch a booking invoice', $invoiceOutsider, [403, 404]);

    $reviewEarly = Http::post('/bookings/' . $bkEId . '/review', ['rating' => 5, 'comment' => 'IT'], 'brand');
    Suite::status('a booking cannot be reviewed before it completes', $reviewEarly, 422);
    Suite::check(
        'the refusal explains that reviews wait for completion',
        str_contains(strtolower($reviewEarly->message()), 'completed'),
        'message was: ' . $reviewEarly->message()
    );

    $reviewBadRating = Http::post('/bookings/' . $bkEId . '/review', ['rating' => 9], 'brand');
    Suite::status('an out-of-range rating is rejected', $reviewBadRating, 422);
    Suite::equals('and it is a validation failure', $reviewBadRating->code(), 'validation_failed');

    $reviewOutsider = Http::post('/bookings/' . $bkEId . '/review', ['rating' => 5], 'outsider');
    Suite::status('a third party cannot review a booking', $reviewOutsider, [403, 404]);
}

// =================================================================
Suite::group('Bookings — listing');

if (!$bkReady || $bkEId === 0) {
    Suite::skipGroup('Bookings — listing', 'no bookings were created to list');
} else {
    $bkMine = array_values(array_filter([$bkAId, $bkBId ?? 0, $bkCId ?? 0, $bkEId]));

    $listAnon = Http::get('/bookings');
    Suite::status('GET /bookings without a token is 401', $listAnon, 401);

    $list = Http::get('/bookings', ['per_page' => 50], 'brand');
    Suite::ok('GET /bookings lists the brand\'s bookings', $list, 200);

    Suite::check(
        'the response carries a pagination block',
        static function () use ($list): bool {
            $p = $list->at('meta.pagination');

            return is_array($p)
                && isset($p['total'], $p['per_page'], $p['current_page'], $p['last_page'], $p['has_more']);
        },
        $list->describe()
    );
    Suite::equals('the caller is listed on the brand side by default', (string) ($list->at('meta.role') ?? ''), 'brand');
    Suite::check(
        'the tab counters are returned for the filter chips',
        static function () use ($list): bool {
            $tabs = $list->at('meta.tabs');

            return is_array($tabs) && isset($tabs['all'], $tabs['pending'], $tabs['completed'], $tabs['cancelled']);
        },
        json_encode($list->at('meta.tabs'))
    );

    $listIds = $bkListIds($list);
    Suite::check(
        'every booking this suite opened is in the brand\'s list',
        array_diff($bkMine, $listIds) === [],
        'missing: ' . json_encode(array_values(array_diff($bkMine, $listIds)))
    );

    Suite::check(
        'a list row carries the counterparty, status and money labels',
        static function () use ($list): bool {
            foreach ($list->list() as $row) {
                if (!is_array($row)) {
                    return false;
                }

                if (!isset($row['code'], $row['status'], $row['status_label'], $row['status_tone'], $row['amount'], $row['currency'])
                    || !is_array($row['counterparty'] ?? null)) {
                    return false;
                }
            }

            return $list->list() !== [];
        },
        $list->describe()
    );

    Suite::check(
        'the list is ordered newest first',
        static function () use ($listIds): bool {
            $sorted = $listIds;
            rsort($sorted);

            return $sorted === $listIds;
        },
        json_encode($listIds)
    );

    // -- filtering -----------------------------------------------------
    $cancelledStatuses = ['cancelled', 'rejected', 'expired', 'refunded'];
    $pendingStatuses   = ['pending', 'countered', 'accepted', 'awaiting_payment'];

    $listCancelled = Http::get('/bookings', ['tab' => 'cancelled', 'per_page' => 50], 'brand');
    Suite::ok('the cancelled tab returns a list', $listCancelled, 200);
    Suite::check(
        'the cancelled tab contains only closed-out bookings',
        static function () use ($listCancelled, $cancelledStatuses): bool {
            foreach ($listCancelled->list() as $row) {
                if (!in_array((string) ($row['status'] ?? ''), $cancelledStatuses, true)) {
                    return false;
                }
            }

            return true;
        },
        json_encode(array_column($listCancelled->list(), 'status'))
    );

    $listPending = Http::get('/bookings', ['tab' => 'pending', 'per_page' => 50], 'brand');
    Suite::ok('the pending tab returns a list', $listPending, 200);
    Suite::check(
        'the pending tab contains only open bookings',
        static function () use ($listPending, $pendingStatuses): bool {
            foreach ($listPending->list() as $row) {
                if (!in_array((string) ($row['status'] ?? ''), $pendingStatuses, true)) {
                    return false;
                }
            }

            return true;
        },
        json_encode(array_column($listPending->list(), 'status'))
    );

    $cancelledIds = $bkListIds($listCancelled);
    $pendingIds   = $bkListIds($listPending);

    Suite::check(
        'the tabs actually narrow — the cancelled and pending sets are disjoint',
        array_intersect($cancelledIds, $pendingIds) === [],
        'overlap: ' . json_encode(array_values(array_intersect($cancelledIds, $pendingIds)))
    );

    Suite::check(
        'the cancelled booking is in the cancelled tab and not the pending one',
        in_array($bkAId, $cancelledIds, true) && !in_array($bkAId, $pendingIds, true),
        'cancelled=' . json_encode($cancelledIds) . ' pending=' . json_encode($pendingIds)
    );

    Suite::check(
        'the untouched request is in the pending tab and not the cancelled one',
        in_array($bkEId, $pendingIds, true) && !in_array($bkEId, $cancelledIds, true),
        'cancelled=' . json_encode($cancelledIds) . ' pending=' . json_encode($pendingIds)
    );

    Suite::check(
        'the tab counters agree with the filtered lists',
        (int) ($listPending->at('meta.tabs.pending') ?? -1) === (int) ($listPending->at('meta.pagination.total') ?? -2),
        'counter=' . json_encode($listPending->at('meta.tabs.pending'))
            . ' total=' . json_encode($listPending->at('meta.pagination.total'))
    );

    $listCompleted = Http::get('/bookings', ['tab' => 'completed'], 'brand');
    Suite::equals(
        'nothing this suite created is completed',
        (int) ($listCompleted->at('meta.pagination.total') ?? -1),
        0
    );

    // -- search --------------------------------------------------------
    $bkECode = (string) ($bkE->at('data.booking.code') ?? '');

    if ($bkECode !== '') {
        $search = Http::get('/bookings', ['search' => $bkECode], 'brand');
        Suite::ok('bookings can be searched by reference code', $search, 200);
        Suite::equals('searching a code matches exactly one booking', $bkListIds($search), [$bkEId]);
    } else {
        Suite::check('the booking carried a code to search by', false, 'no code on the created booking');
    }

    $searchMiss = Http::get('/bookings', ['search' => 'ZZ-no-such-booking-' . bin2hex(random_bytes(4))], 'brand');
    Suite::equals(
        'a search that matches nothing returns nothing',
        (int) ($searchMiss->at('meta.pagination.total') ?? -1),
        0
    );

    // -- pagination ----------------------------------------------------
    $page1 = Http::get('/bookings', ['per_page' => 2, 'page' => 1], 'brand');
    Suite::ok('the first page of a paged list loads', $page1, 200);
    Suite::equals('per_page is honoured', (int) ($page1->at('meta.pagination.per_page') ?? 0), 2);
    Suite::equals('the current page is reported', (int) ($page1->at('meta.pagination.current_page') ?? 0), 1);
    Suite::check(
        'a page never returns more rows than per_page',
        count($page1->list()) <= 2,
        'got ' . count($page1->list()) . ' rows'
    );

    $total1 = (int) ($page1->at('meta.pagination.total') ?? 0);

    Suite::equals(
        'last_page is derived from the total',
        (int) ($page1->at('meta.pagination.last_page') ?? 0),
        (int) max(1, (int) ceil($total1 / 2))
    );
    Suite::equals(
        'has_more is true while pages remain',
        $page1->at('meta.pagination.has_more'),
        $total1 > 2
    );

    $page2 = Http::get('/bookings', ['per_page' => 2, 'page' => 2], 'brand');
    Suite::ok('the second page loads', $page2, 200);
    Suite::check(
        'page 2 returns different rows from page 1',
        array_intersect($bkListIds($page1), $bkListIds($page2)) === [],
        'page1=' . json_encode($bkListIds($page1)) . ' page2=' . json_encode($bkListIds($page2))
    );
    Suite::equals(
        'the total is stable across pages',
        (int) ($page2->at('meta.pagination.total') ?? -1),
        $total1
    );

    // -- the other side of the marketplace ------------------------------
    $creatorList = Http::get('/bookings', ['per_page' => 50], 'creator');
    Suite::ok('the creator sees its own inbox', $creatorList, 200);
    Suite::equals('and is listed on the creator side by default', (string) ($creatorList->at('meta.role') ?? ''), 'creator');
    Suite::check(
        'every booking addressed to the creator appears in its inbox',
        array_diff($bkMine, $bkListIds($creatorList)) === [],
        'missing: ' . json_encode(array_values(array_diff($bkMine, $bkListIds($creatorList))))
    );
    Suite::check(
        'the creator view shows the creator earning, not the brand total',
        static function () use ($creatorList, $bkEId, $bkPricing): bool {
            foreach ($creatorList->list() as $row) {
                if ((int) ($row['id'] ?? 0) === $bkEId) {
                    return abs((float) ($row['amount'] ?? 0) - $bkPricing['earning']) < 0.005;
                }
            }

            return false;
        },
        'expected ' . $bkPricing['earning']
    );

    $brandAsCreator = Http::get('/bookings', ['role' => 'creator'], 'brand');
    Suite::equals(
        'a brand asking for the creator view sees nothing that is not its own',
        (int) ($brandAsCreator->at('meta.pagination.total') ?? -1),
        0
    );

    $outsiderList = Http::get('/bookings', ['per_page' => 50], 'outsider');
    Suite::ok('an unrelated account gets a list of its own', $outsiderList, 200);
    Suite::check(
        'and none of this suite\'s bookings leak into it',
        array_intersect($bkMine, $bkListIds($outsiderList)) === [],
        'leaked: ' . json_encode(array_values(array_intersect($bkMine, $bkListIds($outsiderList))))
    );
}

// =================================================================
Suite::group('Bookings — cleanup');

$bkIds = array_values(array_unique(array_filter($bkIds)));

if ($bkIds === []) {
    Suite::check('there were no bookings to clean up', true);
} else {
    $bkPlaceholders = [];
    $bkBindings     = [];

    foreach ($bkIds as $index => $id) {
        $bkPlaceholders[]            = ':id_' . $index;
        $bkBindings['id_' . $index]  = $id;
    }

    $bkIn = implode(', ', $bkPlaceholders);

    // `conversations.booking_id` is ON DELETE SET NULL, so the conversation
    // BookingService opens has to go first or it would be orphaned. Everything
    // else (offers, history, deliverables, invoices, reviews) cascades.
    foreach ([
        'DELETE FROM `conversations` WHERE `booking_id` IN (' . $bkIn . ')',
        'DELETE FROM `platform_earnings` WHERE `booking_id` IN (' . $bkIn . ')',
        'DELETE FROM `bookings` WHERE `id` IN (' . $bkIn . ')',
    ] as $bkStatement) {
        try {
            Database::execute($bkStatement, $bkBindings);
        } catch (\Throwable $e) {
            Suite::check('cleanup statement succeeded', false, $e->getMessage());
        }
    }

    $bkLeft = (int) (Database::scalar(
        'SELECT COUNT(*) FROM `bookings` WHERE `id` IN (' . $bkIn . ')',
        $bkBindings
    ) ?? 0);

    Suite::equals('every booking this suite created was removed', $bkLeft, 0);
}

// Put the fixture accounts back the way they were, so the creator this suite
// promoted does not show up as an approved creator in the discovery suites.
if ($bkCreatorRow !== null && $bkOriginalCreatorStatus !== null) {
    try {
        Database::update('creators', ['status' => $bkOriginalCreatorStatus], ['id' => (int) $bkCreatorRow['id']]);
    } catch (\Throwable) {
        // Non-fatal: the row belongs to a throwaway account.
    }
}

foreach ($bkKycInserted as $bkUserId) {
    try {
        Database::execute('DELETE FROM `kyc_profiles` WHERE `user_id` = :u', ['u' => $bkUserId]);
    } catch (\Throwable) {
        // Non-fatal: the row belongs to a throwaway account.
    }
}

Suite::check(
    'the fixture mutations were reverted',
    static function () use ($bkCreatorRow, $bkOriginalCreatorStatus): bool {
        if ($bkCreatorRow === null) {
            return true;
        }

        $status = Database::scalar('SELECT `status` FROM `creators` WHERE `id` = :id', ['id' => (int) $bkCreatorRow['id']]);

        return (string) $status === (string) $bkOriginalCreatorStatus;
    },
    'the throwaway creator profile was left approved'
);

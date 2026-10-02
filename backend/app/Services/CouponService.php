<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Helpers\Money;

/**
 * Coupon validation and redemption.
 *
 * The `coupons` table, its admin CRUD, the `coupon_redemptions` ledger and the
 * `coupons_at_checkout` feature flag all existed; nothing read any of them. The
 * Flutter checkout collected a code, posted it as `coupon_code`, and the API
 * dropped it — the customer saw "Applied" and paid full price.
 *
 * Two rules shape everything here:
 *
 *  - a coupon is validated against the booking it is being applied to, never in
 *    isolation, because eligibility depends on the amount, the category and
 *    whether this is the customer's first booking;
 *  - a redemption is recorded in the same transaction that charges the booking,
 *    so a coupon can never be spent twice by two concurrent checkouts.
 */
final class CouponService
{
    /**
     * Check a code against a specific booking.
     *
     * Throws with a message meant for the customer — "this code expired on
     * 3 March" is actionable, "invalid coupon" is not.
     *
     * @return array{coupon:array<string,mixed>,discount:float,total:float}
     */
    public static function validate(
        string $code,
        int $userId,
        float $amount,
        ?int $categoryId = null
    ): array {
        $code = strtoupper(trim($code));

        if ($code === '') {
            throw HttpException::unprocessable('Enter a coupon code.');
        }

        $coupon = Database::selectOne(
            'SELECT * FROM `coupons` WHERE UPPER(`code`) = :code AND `deleted_at` IS NULL',
            ['code' => $code]
        );

        if ($coupon === null) {
            throw HttpException::notFound('That coupon code was not recognised.');
        }

        if ((int) $coupon['is_active'] !== 1) {
            throw HttpException::unprocessable('That coupon is no longer active.');
        }

        $now = time();

        if ($coupon['starts_at'] !== null && strtotime((string) $coupon['starts_at']) > $now) {
            throw HttpException::unprocessable(
                'That coupon is not valid until ' . date('j F', strtotime((string) $coupon['starts_at'])) . '.'
            );
        }

        if ($coupon['expires_at'] !== null && strtotime((string) $coupon['expires_at']) < $now) {
            throw HttpException::unprocessable(
                'That coupon expired on ' . date('j F Y', strtotime((string) $coupon['expires_at'])) . '.'
            );
        }

        $minimum = (float) $coupon['min_booking_amount'];

        if ($minimum > 0 && $amount < $minimum) {
            throw HttpException::unprocessable(
                'This coupon needs a booking of at least ' . Money::format($minimum) . '.'
            );
        }

        // Global usage cap. `used_count` is maintained by redeem(), which
        // increments it inside the charging transaction.
        if ($coupon['usage_limit'] !== null
            && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            throw HttpException::unprocessable('This coupon has been fully claimed.');
        }

        // Per-customer cap, counted from the redemption ledger rather than a
        // denormalised column, so it cannot drift.
        $perUser = max(1, (int) $coupon['usage_limit_per_user']);

        $mine = (int) (Database::scalar(
            'SELECT COUNT(*) FROM `coupon_redemptions` WHERE `coupon_id` = :id AND `user_id` = :user',
            ['id' => (int) $coupon['id'], 'user' => $userId]
        ) ?? 0);

        if ($mine >= $perUser) {
            throw HttpException::unprocessable(
                $perUser === 1
                    ? 'You have already used this coupon.'
                    : 'You have used this coupon the maximum number of times.'
            );
        }

        self::assertEligible($coupon, $userId, $categoryId);

        $discount = self::discountFor($coupon, $amount);

        if ($discount <= 0) {
            throw HttpException::unprocessable('This coupon does not reduce the price of this booking.');
        }

        return [
            'coupon'   => $coupon,
            'discount' => $discount,
            'total'    => Money::subtract($amount, $discount),
        ];
    }

    /**
     * Attach a coupon to a booking.
     *
     * The discount is persisted onto the booking itself — `coupon_id`,
     * `discount_amount` and a reduced `total_amount` — rather than carried
     * through the payment call. Everything downstream (the gateway charge, the
     * escrow hold, the invoice, the refund) already reads `total_amount`, so
     * they all become discount-aware without a single change.
     *
     * The creator's fee is deliberately untouched: a marketing coupon is the
     * platform's cost, not a pay cut for the person doing the work.
     *
     * @return array<string,mixed> the updated booking row
     */
    public static function applyToBooking(int $bookingId, int $userId, string $code): array
    {
        return Database::transaction(static function () use ($bookingId, $userId, $code): array {
            $booking = self::lockPayableBooking($bookingId, $userId);

            // The base is the pre-discount total. Deriving it from the stored
            // columns rather than recomputing fees means replacing one coupon
            // with another cannot drift, even if the platform's fee settings
            // changed after the booking was made.
            $base = self::baseTotal($booking);

            $result = self::validate($code, $userId, $base, self::categoryOf($booking));

            Database::update('bookings', [
                'coupon_id'       => (int) $result['coupon']['id'],
                'discount_amount' => $result['discount'],
                'total_amount'    => $result['total'],
            ], ['id' => $bookingId]);

            return Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id', ['id' => $bookingId]) ?? $booking;
        });
    }

    /**
     * Detach a coupon and restore the full price.
     *
     * @return array<string,mixed> the updated booking row
     */
    public static function removeFromBooking(int $bookingId, int $userId): array
    {
        return Database::transaction(static function () use ($bookingId, $userId): array {
            $booking = self::lockPayableBooking($bookingId, $userId);

            if ($booking['coupon_id'] === null) {
                return $booking;
            }

            Database::update('bookings', [
                'coupon_id'       => null,
                'discount_amount' => 0,
                'total_amount'    => self::baseTotal($booking),
            ], ['id' => $bookingId]);

            return Database::selectOne('SELECT * FROM `bookings` WHERE `id` = :id', ['id' => $bookingId]) ?? $booking;
        });
    }

    /**
     * Re-check an attached coupon immediately before charging.
     *
     * A coupon can expire, be deactivated or be claimed out between the moment
     * it is applied and the moment the customer actually pays. Rather than
     * silently charging the full price — the customer agreed to the discounted
     * one — the coupon is dropped, the booking is repriced and the checkout is
     * stopped so the new total has to be accepted.
     *
     * @param  array<string,mixed> $booking
     * @return array<string,mixed> the booking, repriced if the coupon fell away
     */
    public static function reverifyForCharge(array $booking): array
    {
        if ($booking['coupon_id'] === null) {
            return $booking;
        }

        $coupon = Database::selectOne(
            'SELECT `code` FROM `coupons` WHERE `id` = :id',
            ['id' => (int) $booking['coupon_id']]
        );

        $userId = (int) $booking['brand_user_id'];

        try {
            // Validate against the pre-discount total, exactly as apply did.
            self::validate((string) ($coupon['code'] ?? ''), $userId, self::baseTotal($booking), self::categoryOf($booking));

            return $booking;
        } catch (HttpException $e) {
            self::removeFromBooking((int) $booking['id'], $userId);

            throw HttpException::conflict(
                $e->getMessage() . ' The price has been updated — please review it and pay again.',
                'coupon_no_longer_valid'
            );
        }
    }

    /**
     * Record the redemption of whatever coupon the booking carries.
     *
     * Called from BookingService::markPaid(), which is the single point every
     * gateway and the wallet funnel through, so a redemption is recorded once
     * per booking that is genuinely captured — never for an abandoned
     * checkout, and never twice for a duplicate webhook.
     *
     * @param array<string,mixed> $booking
     */
    public static function redeemForBooking(array $booking): void
    {
        if ($booking['coupon_id'] === null || (float) $booking['discount_amount'] <= 0) {
            return;
        }

        self::redeem(
            (int) $booking['coupon_id'],
            (int) $booking['brand_user_id'],
            (int) $booking['id'],
            (float) $booking['discount_amount']
        );
    }

    /**
     * Record a redemption and advance the usage counter.
     *
     * Call inside the transaction that charges the booking. The unique
     * (coupon, user, booking) key added in migration 026 makes a double submit
     * a no-op rather than a second discount.
     *
     * The INSERT is written out rather than going through Database::insertIgnore()
     * because that helper returns lastInsertId(), which MySQL leaves at its
     * previous value when IGNORE skips a row — indistinguishable from a real
     * insert. execute() returns the row count, which tells the truth, and the
     * usage counter must only advance when a row was actually written.
     */
    public static function redeem(int $couponId, int $userId, ?int $bookingId, float $discount): void
    {
        $inserted = Database::execute(
            'INSERT IGNORE INTO `coupon_redemptions`
                 (`coupon_id`, `user_id`, `booking_id`, `discount_amount`, `created_at`)
             VALUES (:coupon_id, :user_id, :booking_id, :discount_amount, :created_at)',
            [
                'coupon_id'       => $couponId,
                'user_id'         => $userId,
                'booking_id'      => $bookingId,
                'discount_amount' => Money::round($discount),
                'created_at'      => date('Y-m-d H:i:s'),
            ]
        );

        if ($inserted === 0) {
            return;
        }

        Database::execute(
            'UPDATE `coupons` SET `used_count` = `used_count` + 1 WHERE `id` = :id',
            ['id' => $couponId]
        );
    }

    /**
     * The public offers, priced against one specific booking.
     *
     * Each entry carries the actual saving and the resulting total, so the
     * checkout can show "SAVE500 — you save ₹500" instead of a list the
     * customer has to try one at a time. Codes that do not apply are still
     * returned, with the reason, because "needs a booking of at least ₹5,000"
     * tells the customer something useful; silently hiding it does not.
     *
     * @return list<array<string,mixed>>
     */
    public static function offersForBooking(int $bookingId, int $userId): array
    {
        $booking = Database::selectOne(
            'SELECT * FROM `bookings` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        if ((int) $booking['brand_user_id'] !== $userId) {
            throw HttpException::forbidden('You are not a party to this booking.');
        }

        $base       = self::baseTotal($booking);
        $categoryId = self::categoryOf($booking);
        $applied    = $booking['coupon_id'] === null ? 0 : (int) $booking['coupon_id'];
        $currency   = (string) $booking['currency'];

        $offers = [];

        foreach (self::available($userId) as $offer) {
            $offer['is_applied'] = $offer['id'] === $applied;

            try {
                $check = self::validate((string) $offer['code'], $userId, $base, $categoryId);

                $offer['eligible']         = true;
                $offer['reason']           = null;
                $offer['discount']         = $check['discount'];
                $offer['total']            = $check['total'];
                $offer['saving_label']     = 'You save ' . Money::format($check['discount'], $currency);
            } catch (HttpException $e) {
                $offer['eligible']     = false;
                $offer['reason']       = $e->getMessage();
                $offer['discount']     = 0.0;
                $offer['total']        = $base;
                $offer['saving_label'] = null;
            }

            $offers[] = $offer;
        }

        // Best saving first — an offers list is only useful if the best one is
        // at the top, and ineligible codes sink below every usable one.
        usort($offers, static fn (array $a, array $b): int => $b['discount'] <=> $a['discount']);

        return $offers;
    }

    /**
     * Public codes a customer could plausibly use, for a "available offers"
     * list. Private codes (`is_public = 0`) are deliberately excluded — they
     * are handed out directly.
     *
     * @return list<array<string,mixed>>
     */
    public static function available(int $userId): array
    {
        $rows = Database::select(
            'SELECT c.* FROM `coupons` c
              WHERE c.`is_active` = 1
                AND c.`is_public` = 1
                AND c.`deleted_at` IS NULL
                AND (c.`starts_at` IS NULL OR c.`starts_at` <= NOW())
                AND (c.`expires_at` IS NULL OR c.`expires_at` >= NOW())
                AND (c.`usage_limit` IS NULL OR c.`used_count` < c.`usage_limit`)
              ORDER BY c.`discount_value` DESC'
        );

        $available = [];

        foreach ($rows as $coupon) {
            $perUser = max(1, (int) $coupon['usage_limit_per_user']);

            $mine = (int) (Database::scalar(
                'SELECT COUNT(*) FROM `coupon_redemptions` WHERE `coupon_id` = :id AND `user_id` = :user',
                ['id' => (int) $coupon['id'], 'user' => $userId]
            ) ?? 0);

            if ($mine >= $perUser) {
                continue;
            }

            $available[] = self::present($coupon);
        }

        return $available;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Lock a booking the caller is allowed to reprice.
     *
     * FOR UPDATE because apply/remove both read the current total and write a
     * new one; without the lock two concurrent applies could each read the
     * same base and the second would overwrite the first's discount rather
     * than replace it.
     *
     * @return array<string,mixed>
     */
    private static function lockPayableBooking(int $bookingId, int $userId): array
    {
        $booking = Database::selectOne(
            'SELECT * FROM `bookings` WHERE `id` = :id AND `deleted_at` IS NULL FOR UPDATE',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        if ((int) $booking['brand_user_id'] !== $userId) {
            throw HttpException::forbidden('Only the brand that created this booking can change its price.');
        }

        if ($booking['payment_status'] === 'paid') {
            throw HttpException::conflict('This booking has already been paid for.');
        }

        if (!in_array($booking['status'], ['pending', 'countered', 'accepted', 'awaiting_payment'], true)) {
            throw HttpException::unprocessable('A coupon cannot be applied to this booking any more.');
        }

        return $booking;
    }

    /**
     * The price before any coupon.
     *
     * `total_amount` is stored net of the current discount, so adding the
     * discount back is the only way to recover the base without recomputing
     * fees against settings that may have moved since the booking was made.
     *
     * @param array<string,mixed> $booking
     */
    private static function baseTotal(array $booking): float
    {
        return Money::add(
            (float) $booking['total_amount'],
            (float) ($booking['discount_amount'] ?? 0)
        );
    }

    /**
     * The category a booking counts as, for `applies_to = category` coupons.
     *
     * Bookings carry no category of their own; the creator's primary category
     * is what a category-scoped campaign coupon is really targeting.
     *
     * @param array<string,mixed> $booking
     */
    private static function categoryOf(array $booking): ?int
    {
        $categoryId = Database::scalar(
            'SELECT `primary_category_id` FROM `creators` WHERE `id` = :id',
            ['id' => (int) $booking['creator_id']]
        );

        return $categoryId === null ? null : (int) $categoryId;
    }

    /** @param array<string,mixed> $coupon */
    private static function assertEligible(array $coupon, int $userId, ?int $categoryId): void
    {
        $appliesTo = (string) $coupon['applies_to'];

        if ($appliesTo === 'all') {
            return;
        }

        if ($appliesTo === 'category') {
            if ($categoryId === null || (int) $coupon['category_id'] !== $categoryId) {
                throw HttpException::unprocessable(
                    'This coupon only applies to bookings in a specific category.'
                );
            }

            return;
        }

        if ($appliesTo === 'first_booking') {
            $previous = (int) (Database::scalar(
                "SELECT COUNT(*) FROM `bookings`
                  WHERE `brand_user_id` = :user AND `payment_status` = 'paid' AND `deleted_at` IS NULL",
                ['user' => $userId]
            ) ?? 0);

            if ($previous > 0) {
                throw HttpException::unprocessable('This coupon is for your first booking only.');
            }

            return;
        }

        // brands / creators
        $accountType = (string) (Database::scalar(
            'SELECT `account_type` FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        ) ?? 'brand');

        $expected = $appliesTo === 'brands' ? 'brand' : 'creator';

        if ($accountType !== $expected) {
            throw HttpException::unprocessable('This coupon is not available on your account type.');
        }
    }

    /**
     * The discount, capped so it can never exceed the booking or the coupon's
     * own ceiling.
     *
     * @param array<string,mixed> $coupon
     */
    private static function discountFor(array $coupon, float $amount): float
    {
        $discount = (string) $coupon['discount_type'] === 'percentage'
            ? Money::percentOf($amount, (float) $coupon['discount_value'])
            : (float) $coupon['discount_value'];

        if ($coupon['max_discount'] !== null) {
            $discount = min($discount, (float) $coupon['max_discount']);
        }

        // A fixed-amount coupon larger than the booking must not produce a
        // negative total or a refund.
        return Money::round(min($discount, $amount));
    }

    /**
     * @param  array<string,mixed> $coupon
     * @return array<string,mixed>
     */
    public static function present(array $coupon): array
    {
        $isPercentage = (string) $coupon['discount_type'] === 'percentage';

        return [
            'id'            => (int) $coupon['id'],
            'code'          => (string) $coupon['code'],
            'name'          => (string) $coupon['name'],
            'description'   => $coupon['description'],
            'discount_type' => $coupon['discount_type'],
            'discount_value' => (float) $coupon['discount_value'],
            'discount_label' => $isPercentage
                ? rtrim(rtrim(number_format((float) $coupon['discount_value'], 2, '.', ''), '0'), '.') . '% off'
                : Money::format((float) $coupon['discount_value']) . ' off',
            'max_discount'  => $coupon['max_discount'] === null ? null : (float) $coupon['max_discount'],
            'min_booking_amount' => (float) $coupon['min_booking_amount'],
            'expires_at'    => $coupon['expires_at'],
        ];
    }
}

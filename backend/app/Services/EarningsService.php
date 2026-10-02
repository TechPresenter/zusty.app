<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\Logger;

/**
 * Platform revenue accrual.
 *
 * `platform_earnings` is written once per booking at payment capture
 * (BookingService) and read by every revenue widget, the daily analytics
 * rollup and the finance reports. Nothing reversed it when the money went
 * back, so a refunded booking kept earning the platform a fee it no longer
 * held, and every read site counted rows that had already been reversed.
 *
 * Both halves of that live here so the rule has one home: net() builds the
 * expression that decides what a row is currently worth, and applyRefund()
 * is the only thing that retires an accrual.
 *
 * The accrued figures are never rewritten. Finance needs to see what was
 * originally earned and what came back; `refunded_ratio` records the share
 * that was refunded, and revenue is read as the accrual net of it.
 */
final class EarningsService
{
    /**
     * The predicate every revenue query must carry.
     *
     * A reversed accrual is money the platform gave back. It stays on the
     * table as an audit record, which means any SUM that omits this filter
     * reports refunded money as income.
     *
     * Contains no user input and no placeholders — it is written for
     * interpolation into a WHERE clause.
     */
    public const LIVE = "`status` <> 'reversed'";

    /**
     * A SUM over one accrual column, net of anything refunded.
     *
     * A partially refunded booking still earns the platform its share of what
     * the customer kept, so the column is scaled rather than dropped. Full
     * refunds carry a ratio of 1 and contribute nothing.
     *
     * @param string $column One of the accrual money columns. Rejected unless
     *                       it is a known column, so this can never carry
     *                       caller-supplied SQL into a query.
     */
    public static function net(string $column): string
    {
        $allowed = ['total', 'platform_fee', 'commission', 'tax_collected'];

        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("Not an accrual column: {$column}");
        }

        return "COALESCE(SUM(`{$column}` * (1 - `refunded_ratio`)), 0)";
    }

    /**
     * Bring a booking's accrual back in line with what was actually kept.
     *
     * Takes the cumulative refunded share rather than an increment, so a
     * second partial refund lands on the original figures instead of
     * compounding against already-reduced ones, and calling this twice with
     * the same total is a no-op.
     *
     * @param int   $bookingId     The refunded booking.
     * @param float $refundedRatio Cumulative refunded share, 0.0-1.0.
     */
    public static function applyRefund(int $bookingId, float $refundedRatio): void
    {
        $earning = Database::selectOne(
            'SELECT * FROM `platform_earnings` WHERE `booking_id` = :id',
            ['id' => $bookingId]
        );

        // Nothing accrued — the booking was refunded before capture, or the
        // money never reached escrow. There is no revenue to retire.
        if ($earning === null) {
            return;
        }

        $ratio = max(0.0, min(1.0, $refundedRatio));
        $full  = $ratio >= 0.99999;

        $update = [
            'refunded_ratio' => $full ? 1.0 : round($ratio, 5),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        // A fully refunded accrual is retired outright, so it stops appearing
        // as live revenue anywhere that filters on status as well.
        if ($full) {
            $update['status'] = 'reversed';
        }

        Database::update('platform_earnings', $update, ['id' => (int) $earning['id']]);

        Logger::info(
            $full
                ? 'Platform earnings reversed on full refund'
                : 'Platform earnings pro-rated on partial refund',
            [
                'booking_id'     => $bookingId,
                'earning_id'     => (int) $earning['id'],
                'refunded_ratio' => $update['refunded_ratio'],
                'accrued_total'  => (float) $earning['total'],
                'live_total'     => round((float) $earning['total'] * (1 - (float) $update['refunded_ratio']), 2),
            ]
        );
    }
}

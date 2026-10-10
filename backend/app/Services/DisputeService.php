<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Helpers\Money;

/**
 * Settling a booking dispute: the brand's refund, the creator's payout and
 * where the booking ends up.
 *
 * The refund is recorded inside the resolution's transaction and only sent
 * once that transaction has committed. Since migration 056 a dispute refund
 * goes back to the original payment method by default, which is an HTTP
 * call to the gateway. Made inside the transaction, anything that rolled it
 * back afterwards — the booking no longer being in a state the outcome can
 * move it to, or a deadlock retry — erased the refund row while the gateway
 * kept the refund: its confirmation webhook then matched nothing, and doing
 * it again refunded twice. So every check that can refuse runs first, under
 * the booking's and the dispute's row locks, and the money moves last.
 */
final class DisputeService
{
    /** @var list<string> */
    public const OUTCOMES = ['resolved_brand', 'resolved_creator', 'resolved_split', 'withdrawn'];

    /**
     * Resolve a dispute.
     *
     * [$refundAmount] and [$payoutAmount] are clamped to the booking's total
     * and the creator's earning; the refund is further limited to what is
     * still refundable on the payment. [$refundDestination] is `source` (the
     * original payment method, the default) or `wallet`.
     *
     * @return array{
     *   booking_id:int, target:string, refund_amount:float, payout_amount:float,
     *   refund:array<string,mixed>|null
     * } `refund` is the refunds row after it was settled: `completed`
     *   (wallet, or a gateway that settles at once), `processing` (accepted by
     *   the gateway, confirmed later by its webhook) or `failed` (the gateway
     *   refused it; the payment stays refundable for finance to re-issue).
     */
    public static function resolve(
        int $disputeId,
        string $outcome,
        float $refundAmount,
        string $refundDestination,
        float $payoutAmount,
        string $note,
        int $adminId
    ): array {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw HttpException::unprocessable('Unknown dispute outcome.');
        }

        $refundDestination = $refundDestination === 'wallet' ? 'wallet' : 'source';

        $resolved = Database::transaction(static function () use ($disputeId, $outcome, $refundAmount, $refundDestination, $payoutAmount, $note, $adminId): array {
            // Dispute first, then booking — and both locked, so two admins
            // resolving the same dispute at once cannot both pay out.
            $dispute = Database::selectOne(
                'SELECT * FROM `booking_disputes` WHERE `id` = :id FOR UPDATE',
                ['id' => $disputeId]
            );

            if ($dispute === null) {
                throw HttpException::notFound('Dispute not found.');
            }

            if (str_starts_with((string) $dispute['status'], 'resolved') || $dispute['status'] === 'withdrawn') {
                throw HttpException::conflict('This dispute has already been resolved.');
            }

            $bookingId = (int) $dispute['booking_id'];

            $booking = Database::selectOne(
                'SELECT `id`, `code`, `status`, `total_amount`, `creator_earning`, `creator_user_id`
                   FROM `bookings` WHERE `id` = :id FOR UPDATE',
                ['id' => $bookingId]
            );

            if ($booking === null) {
                throw HttpException::notFound('Booking not found.');
            }

            $refund = Money::clamp($refundAmount, 0.0, (float) $booking['total_amount']);
            $payout = Money::clamp($payoutAmount, 0.0, (float) $booking['creator_earning']);

            // Where the booking lands depends on the outcome: a withdrawn
            // dispute goes back to where it was before, a full refund ends it
            // as refunded, anything else as completed.
            $current = (string) $booking['status'];
            $target  = match ($outcome) {
                'withdrawn' => BookingService::statusBeforeDispute($bookingId),
                default     => $refund > 0 && $payout <= 0 ? 'refunded' : 'completed',
            };

            // Only move if the state machine allows it; a booking that is not
            // in `disputed` must not be dragged into a terminal state by a
            // dispute record that no longer matches it. Checked before any
            // money is recorded, so refusing here leaves nothing behind.
            if ($current !== $target && !BookingService::canTransition($current, $target)) {
                throw HttpException::conflict(
                    'This booking is ' . $current . ' and cannot be moved to ' . $target . '. '
                    . 'Resolve the booking itself first.'
                );
            }

            // Recorded now, sent after the commit (below).
            $refundRow = null;

            if ($refund > 0) {
                $refundRow = PaymentService::parkRefundForApproval(
                    $bookingId,
                    $refund,
                    'dispute',
                    'admin',
                    $adminId,
                    $refundDestination
                );

                $refund = (float) $refundRow['amount'];
            }

            if ($payout > 0) {
                WalletService::credit((int) $booking['creator_user_id'], $payout, [
                    'category'          => 'booking_earning',
                    'description'       => 'Dispute settlement for #' . (string) $booking['code'],
                    'reference_type'    => 'booking',
                    'reference_id'      => $bookingId,
                    'idempotency_key'   => 'dispute-payout:' . $disputeId,
                    'performed_by_type' => 'admin',
                    'performed_by_id'   => $adminId,
                ]);
            }

            Database::update('booking_disputes', [
                'status'          => $outcome,
                'refund_amount'   => $refund,
                'payout_amount'   => $payout,
                'resolution_note' => $note,
                'handled_by'      => $adminId,
                'resolved_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $disputeId]);

            if ($current !== $target) {
                BookingService::forceStatus(
                    $bookingId,
                    $target,
                    'admin',
                    $adminId,
                    'Dispute resolved: ' . $outcome,
                    [
                        'escrow_status' => $refund > 0 ? 'refunded' : 'released',
                        'completed_at'  => $target === 'completed' ? date('Y-m-d H:i:s') : null,
                    ]
                );
            }

            return [
                'booking_id'    => $bookingId,
                'target'        => $target,
                'refund_amount' => $refund,
                'payout_amount' => $payout,
                'refund'        => $refundRow,
            ];
        });

        // The resolution is committed; only now does money leave. A wallet
        // refund completes here; a gateway refund is usually `processing`
        // until the gateway's webhook confirms it; a refused one is `failed`
        // and stays visible to finance with the gateway's reason.
        if ($resolved['refund'] !== null) {
            $resolved['refund'] = PaymentService::settleParkedRefund((int) $resolved['refund']['id']);
        }

        return $resolved;
    }
}

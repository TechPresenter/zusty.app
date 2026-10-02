<?php

declare(strict_types=1);

namespace Zuste\Middleware;

use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Services\KycService;

/**
 * Blocks money-moving and reputation-bearing actions until the account is
 * verified.
 *
 * Applied as the `kyc` route middleware. When KYC is switched off for that
 * side of the marketplace in Admin → Settings, this becomes a no-op, so the
 * requirement can be relaxed without touching route definitions.
 *
 * The refusal carries a machine-readable code and the current verification
 * status, so the client can route the user straight to the right screen rather
 * than showing a dead end.
 */
final class KycMiddleware
{
    public static function handle(Request $request): void
    {
        $user = $request->user();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $accountType = (string) ($user['account_type'] ?? 'brand');

        if (KycService::isVerified((int) $user['id'], $accountType)) {
            return;
        }

        $kind = $accountType === 'creator'
            ? KycService::KIND_CREATOR
            : KycService::KIND_BRAND;

        // An approval refused here only because its date has passed is
        // expired now — status, flags and any blue tick — instead of reading
        // "approved" (and showing a tick) until the nightly sweep. The user
        // then gets the "expired, re-submit" message below, not "get verified".
        KycService::expireIfLapsed((int) $user['id']);

        // Tell the client exactly where the profile currently sits, so it can
        // say "still under review" rather than "get verified" to someone who
        // has already submitted.
        $profile = KycService::profileFor((int) $user['id'], $kind);
        $status  = (string) $profile['status'];

        throw new HttpException(
            self::messageFor($status, $accountType),
            403,
            'kyc_required',
            [
                'kyc' => [
                    'status'       => $status,
                    'status_label' => KycService::statusLabel($status),
                    'kind'         => $kind,
                    'reference'    => $profile['reference'],
                    'completeness' => (int) $profile['completeness'],
                ],
            ]
        );
    }

    /**
     * A message that matches where the user actually is. Someone waiting on a
     * reviewer should not be told to go and submit documents.
     */
    private static function messageFor(string $status, string $accountType): string
    {
        return match ($status) {
            KycService::SUBMITTED, KycService::UNDER_REVIEW =>
                'Your verification is being reviewed. This usually takes 24-48 hours.',
            KycService::INFO_REQUIRED =>
                'We need a little more information before you can continue. Check your verification screen.',
            KycService::REJECTED =>
                'Your verification was not approved. Correct the details and resubmit to continue.',
            KycService::SUSPENDED =>
                'Your verification is suspended. Contact support to restore your account.',
            KycService::EXPIRED =>
                'Your verification has expired. Re-submit your documents to continue.',
            default => KycService::gateMessage($accountType),
        };
    }
}

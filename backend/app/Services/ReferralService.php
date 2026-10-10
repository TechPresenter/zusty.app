<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * Invite & Earn.
 *
 * A referral is recorded at signup but stays `pending` until the invited user
 * completes their first paid booking — that is what stops the programme from
 * paying out on throwaway accounts.
 */
final class ReferralService
{
    /** Generate a referral code that is unique across users. */
    public static function generateCode(): string
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = Str::referralCode(8);

            $exists = Database::scalar(
                'SELECT 1 FROM `users` WHERE `referral_code` = :code LIMIT 1',
                ['code' => $code]
            );

            if ($exists === null) {
                return $code;
            }
        }

        // Astronomically unlikely; fall back to a longer code.
        return Str::referralCode(12);
    }

    /** Resolve an inviter from a code entered at signup. */
    public static function resolveReferrer(string $code): ?array
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        return Database::selectOne(
            "SELECT `id`, `name`, `referral_code` FROM `users`
             WHERE `referral_code` = :code AND `status` = 'active' AND `deleted_at` IS NULL",
            ['code' => $code]
        );
    }

    /** Record the relationship at signup. Safe to call with a null referrer. */
    public static function record(int $referredUserId, ?int $referrerUserId, string $code): void
    {
        if ($referrerUserId === null || $referrerUserId === $referredUserId) {
            return;
        }

        if (!Config::settingBool('referral', 'enabled', true)) {
            return;
        }

        Database::insertIgnore('referrals', [
            'referrer_user_id' => $referrerUserId,
            'referred_user_id' => $referredUserId,
            'code'             => strtoupper($code),
            'referrer_reward'  => Config::settingFloat('referral', 'referrer_reward', 200),
            'referred_reward'  => Config::settingFloat('referral', 'referred_reward', 200),
            'status'           => 'pending',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Called after a booking completes. If the user was referred and has not
     * been rewarded yet, credit both sides.
     */
    public static function qualifyForUser(int $referredUserId): void
    {
        if (!Config::settingBool('referral', 'enabled', true)) {
            return;
        }

        $referral = Database::selectOne(
            "SELECT * FROM `referrals` WHERE `referred_user_id` = :id AND `status` = 'pending'",
            ['id' => $referredUserId]
        );

        if ($referral === null) {
            return;
        }

        $referrerReward = Money::round((float) $referral['referrer_reward']);
        $referredReward = Money::round((float) $referral['referred_reward']);

        // A referrer who deleted their account gets nothing (their wallet is
        // closed); the friend they invited is still rewarded.
        $referrerGone = false;

        try {
            Database::transaction(static function () use ($referral, $referrerReward, $referredReward, &$referrerGone): void {
                $referralId = (int) $referral['id'];

                $referrer = Database::selectOne(
                    'SELECT `deleted_at` FROM `users` WHERE `id` = :id',
                    ['id' => (int) $referral['referrer_user_id']]
                );

                $referrerGone = $referrer === null || $referrer['deleted_at'] !== null;

                if ($referrerReward > 0 && !$referrerGone) {
                    WalletService::credit((int) $referral['referrer_user_id'], $referrerReward, [
                        'category'        => 'referral',
                        'description'     => 'Referral reward',
                        'reference_type'  => 'referral',
                        'reference_id'    => $referralId,
                        'idempotency_key' => 'referral-referrer:' . $referralId,
                    ]);
                }

                if ($referredReward > 0) {
                    WalletService::credit((int) $referral['referred_user_id'], $referredReward, [
                        'category'        => 'referral',
                        'description'     => 'Welcome referral bonus',
                        'reference_type'  => 'referral',
                        'reference_id'    => $referralId,
                        'idempotency_key' => 'referral-referred:' . $referralId,
                    ]);
                }

                Database::update('referrals', [
                    'status'       => 'rewarded',
                    'qualified_at' => date('Y-m-d H:i:s'),
                    'rewarded_at'  => date('Y-m-d H:i:s'),
                ] + ($referrerGone ? ['referrer_reward' => 0] : []), ['id' => $referralId]);
            });

            if ($referrerGone) {
                return;
            }

            $friendName = (string) (Database::scalar(
                'SELECT `name` FROM `users` WHERE `id` = :id',
                ['id' => (int) $referral['referred_user_id']]
            ) ?? 'Your friend');

            NotificationService::send((int) $referral['referrer_user_id'], 'referral.rewarded', [
                'friend_name' => $friendName,
                'amount'      => Money::groupIndian($referrerReward, 0),
                'currency'    => Money::symbol(),
            ], ['action_type' => 'wallet']);
        } catch (\Throwable $e) {
            Logger::error('Failed to settle a referral reward', [
                'referral_id' => $referral['id'],
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Referral summary for the Invite & Earn screen.
     *
     * @return array<string,mixed>
     */
    public static function summary(int $userId): array
    {
        $user = Database::selectOne(
            'SELECT `referral_code` FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        );

        $stats = Database::selectOne(
            "SELECT
                COUNT(*) AS `total`,
                SUM(CASE WHEN `status` = 'pending'  THEN 1 ELSE 0 END) AS `pending`,
                SUM(CASE WHEN `status` = 'rewarded' THEN 1 ELSE 0 END) AS `rewarded`,
                COALESCE(SUM(CASE WHEN `status` = 'rewarded' THEN `referrer_reward` ELSE 0 END), 0) AS `earned`
             FROM `referrals` WHERE `referrer_user_id` = :id",
            ['id' => $userId]
        ) ?? [];

        $code    = (string) ($user['referral_code'] ?? '');
        // Lands on Web\PageController::invite. The fallback is the seeded value.
        $baseUrl = Config::settingString('referral', 'share_base_url', 'https://zusty.app/invite/');

        return [
            'code'             => $code,
            'share_url'        => rtrim($baseUrl, '/') . '/' . $code,
            'share_message'    => sprintf(
                'Join me on Zusty — book verified creators or get booked by brands. Use my code %s and we both earn %s. %s',
                $code,
                Money::formatShort(Config::settingFloat('referral', 'referred_reward', 200)),
                rtrim($baseUrl, '/') . '/' . $code
            ),
            'referrer_reward'  => Money::round(Config::settingFloat('referral', 'referrer_reward', 200)),
            'referred_reward'  => Money::round(Config::settingFloat('referral', 'referred_reward', 200)),
            'currency'         => Money::defaultCurrency(),
            'total_invites'    => (int) ($stats['total'] ?? 0),
            'pending_invites'  => (int) ($stats['pending'] ?? 0),
            'rewarded_invites' => (int) ($stats['rewarded'] ?? 0),
            'total_earned'     => Money::round((float) ($stats['earned'] ?? 0)),
            'enabled'          => Config::settingBool('referral', 'enabled', true),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function invitees(int $userId, int $limit = 50): array
    {
        return Database::select(
            'SELECT r.`status`, r.`referrer_reward`, r.`created_at`, r.`rewarded_at`,
                    u.`name`, u.`avatar_url`
             FROM `referrals` r
             INNER JOIN `users` u ON u.`id` = r.`referred_user_id`
             WHERE r.`referrer_user_id` = :id
             ORDER BY r.`id` DESC
             LIMIT ' . max(1, min(100, $limit)),
            ['id' => $userId]
        );
    }
}

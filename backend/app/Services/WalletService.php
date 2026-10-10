<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * The wallet ledger.
 *
 * Rules this class enforces, without exception:
 *
 *  1. Every balance change is written as an immutable `wallet_transactions`
 *     row in the same transaction that mutates `wallets`.
 *  2. The wallet row is locked with SELECT ... FOR UPDATE before it is read,
 *     so two concurrent debits can never both see the old balance.
 *  3. `idempotency_key` makes a retried call a no-op instead of a double post.
 *  4. Escrow money lives in `locked_balance`; it is not spendable and not
 *     withdrawable until it is explicitly released.
 *
 * Callers must never UPDATE `wallets` directly.
 */
final class WalletService
{
    // -----------------------------------------------------------------
    // Wallet lifecycle
    // -----------------------------------------------------------------

    /**
     * Fetch (or lazily create) a user's wallet.
     *
     * Write paths only — registration and `post()`. Read paths must call
     * `existing()` so that a GET never opens a wallet as a side effect.
     */
    public static function forUser(int $userId): array
    {
        $wallet = Database::selectOne('SELECT * FROM `wallets` WHERE `user_id` = :user_id', ['user_id' => $userId]);

        if ($wallet !== null) {
            return $wallet;
        }

        Database::insertIgnore('wallets', [
            'user_id'  => $userId,
            'currency' => Money::defaultCurrency(),
            'balance'  => 0.00,
        ]);

        $wallet = Database::selectOne('SELECT * FROM `wallets` WHERE `user_id` = :user_id', ['user_id' => $userId]);

        if ($wallet === null) {
            throw HttpException::server('Unable to open a wallet for this account.');
        }

        return $wallet;
    }

    /**
     * Read-only lookup. Never creates a row.
     *
     * Use this on every read path. `forUser()` is reserved for the two genuine
     * write paths (registration and `post()`), so that merely *viewing* an
     * account can never open a wallet for it.
     *
     * @return array<string,mixed>|null
     */
    public static function existing(int $userId): ?array
    {
        return Database::selectOne('SELECT * FROM `wallets` WHERE `user_id` = :user_id', ['user_id' => $userId]);
    }

    /** @return array{balance:float,locked:float,total:float,currency:string,is_frozen:bool} */
    public static function balance(int $userId): array
    {
        $wallet = self::existing($userId);

        if ($wallet === null) {
            return [
                'balance'   => 0.0,
                'locked'    => 0.0,
                'total'     => 0.0,
                'currency'  => Money::defaultCurrency(),
                'is_frozen' => false,
            ];
        }

        return [
            'balance'   => Money::round((float) $wallet['balance']),
            'locked'    => Money::round((float) $wallet['locked_balance']),
            'total'     => Money::add((float) $wallet['balance'], (float) $wallet['locked_balance']),
            'currency'  => (string) $wallet['currency'],
            'is_frozen' => (bool) $wallet['is_frozen'],
        ];
    }

    // -----------------------------------------------------------------
    // Core ledger operations
    // -----------------------------------------------------------------

    /**
     * Credit a wallet.
     *
     * @param array{
     *   category:string, description:string, reference_type?:string|null,
     *   reference_id?:int|null, counterparty_id?:int|null,
     *   idempotency_key?:string|null, metadata?:array<string,mixed>,
     *   performed_by_type?:string, performed_by_id?:int|null
     * } $options
     *
     * @return array<string,mixed> The created ledger row.
     */
    public static function credit(int $userId, float $amount, array $options): array
    {
        return self::post($userId, $amount, 'credit', $options);
    }

    /**
     * Debit a wallet. Throws 422 when the spendable balance is insufficient.
     *
     * @param array<string,mixed> $options See credit().
     */
    public static function debit(int $userId, float $amount, array $options): array
    {
        return self::post($userId, $amount, 'debit', $options);
    }

    /**
     * Move money from spendable to locked (escrow hold).
     * Used when a brand pays for a booking out of wallet balance.
     */
    public static function lock(int $userId, float $amount, array $options): array
    {
        $options['lock'] = true;

        return self::post($userId, $amount, 'debit', $options);
    }

    /**
     * Move money from locked back to spendable (escrow release / payout
     * maturation), optionally to a *different* user — that is exactly what
     * happens when a booking completes and the creator gets paid.
     */
    public static function release(int $userId, float $amount, array $options): array
    {
        $options['unlock'] = true;

        return self::post($userId, $amount, 'credit', $options);
    }

    /**
     * The single write path. Everything above funnels through here so the
     * locking and ledger rules exist in exactly one place.
     *
     * @param array<string,mixed> $options
     */
    private static function post(int $userId, float $amount, string $direction, array $options): array
    {
        $amount = Money::round($amount);

        if ($amount <= 0) {
            throw HttpException::unprocessable('Transaction amount must be greater than zero.');
        }

        $idempotencyKey = $options['idempotency_key'] ?? null;

        // Fast path: a retry of an already-completed request.
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = Database::selectOne(
                'SELECT * FROM `wallet_transactions` WHERE `idempotency_key` = :key',
                ['key' => $idempotencyKey]
            );

            if ($existing !== null) {
                return $existing;
            }
        }

        return Database::transaction(static function () use ($userId, $amount, $direction, $options, $idempotencyKey): array {
            // Ensure the wallet exists before we try to lock it.
            self::forUser($userId);

            $wallet = Database::selectOne(
                'SELECT * FROM `wallets` WHERE `user_id` = :user_id FOR UPDATE',
                ['user_id' => $userId]
            );

            if ($wallet === null) {
                throw HttpException::server('Wallet not found.');
            }

            // An erased account's wallet is closed (AccountDeletionService).
            // Read from the locked wallet row, never by locking `users` here:
            // the erasure locks users then wallets, so that would deadlock.
            // Admin postings still go through, so finance can settle it.
            if (!empty($wallet['closed_at']) && ($options['performed_by_type'] ?? 'system') !== 'admin') {
                throw new HttpException('This account has been deleted.', 409, 'wallet_closed');
            }

            if ((bool) $wallet['is_frozen'] && ($options['performed_by_type'] ?? 'system') !== 'admin') {
                throw new HttpException(
                    'This wallet is frozen. ' . (string) ($wallet['frozen_reason'] ?? 'Contact support.'),
                    403,
                    'wallet_frozen'
                );
            }

            $balance = Money::round((float) $wallet['balance']);
            $locked  = Money::round((float) $wallet['locked_balance']);

            $isLocking   = (bool) ($options['lock'] ?? false);
            $isUnlocking = (bool) ($options['unlock'] ?? false);

            if ($direction === 'debit') {
                if ($balance < $amount) {
                    throw new HttpException(
                        sprintf(
                            'Insufficient wallet balance. Available %s, required %s.',
                            Money::format($balance),
                            Money::format($amount)
                        ),
                        422,
                        'insufficient_balance'
                    );
                }

                $newBalance = Money::subtract($balance, $amount);
                $newLocked  = $isLocking ? Money::add($locked, $amount) : $locked;
            } else {
                if ($isUnlocking) {
                    if ($locked < $amount) {
                        throw new HttpException(
                            'Insufficient locked balance to release.',
                            422,
                            'insufficient_locked_balance'
                        );
                    }
                    $newLocked = Money::subtract($locked, $amount);
                } else {
                    $newLocked = $locked;
                }

                $newBalance = Money::add($balance, $amount);
            }

            Database::execute(
                'UPDATE `wallets`
                 SET `balance` = :balance,
                     `locked_balance` = :locked,
                     `lifetime_credit` = `lifetime_credit` + :credit,
                     `lifetime_debit`  = `lifetime_debit` + :debit,
                     `last_activity_at` = NOW()
                 WHERE `id` = :id',
                [
                    'balance' => $newBalance,
                    'locked'  => $newLocked,
                    'credit'  => $direction === 'credit' ? $amount : 0.00,
                    'debit'   => $direction === 'debit' ? $amount : 0.00,
                    'id'      => (int) $wallet['id'],
                ]
            );

            $transactionId = Database::insert('wallet_transactions', [
                'reference'         => 'PENDING',
                'wallet_id'         => (int) $wallet['id'],
                'user_id'           => $userId,
                'direction'         => $direction,
                'category'          => (string) $options['category'],
                'amount'            => $amount,
                'balance_before'    => $balance,
                'balance_after'     => $newBalance,
                'currency'          => (string) $wallet['currency'],
                'status'            => 'completed',
                'reference_type'    => $options['reference_type'] ?? null,
                'reference_id'      => isset($options['reference_id']) ? (int) $options['reference_id'] : null,
                'counterparty_id'   => isset($options['counterparty_id']) ? (int) $options['counterparty_id'] : null,
                'description'       => mb_substr((string) $options['description'], 0, 255),
                'idempotency_key'   => $idempotencyKey ?: null,
                'metadata'          => isset($options['metadata']) && $options['metadata'] !== []
                    ? json_encode($options['metadata'], JSON_UNESCAPED_UNICODE)
                    : null,
                'performed_by_type' => (string) ($options['performed_by_type'] ?? 'system'),
                'performed_by_id'   => isset($options['performed_by_id']) ? (int) $options['performed_by_id'] : null,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);

            // The public reference embeds the id, so it is unique by construction.
            $reference = Str::referenceCode('ZTXN', $transactionId, 10);
            Database::update('wallet_transactions', ['reference' => $reference], ['id' => $transactionId]);

            $row = Database::selectOne('SELECT * FROM `wallet_transactions` WHERE `id` = :id', ['id' => $transactionId]);

            if ($row === null) {
                throw HttpException::server('Failed to record the transaction.');
            }

            return $row;
        });
    }

    // -----------------------------------------------------------------
    // Higher-level operations
    // -----------------------------------------------------------------

    /**
     * Transfer between two users atomically — used when escrow is released
     * from the platform hold to a creator.
     */
    public static function transfer(int $fromUserId, int $toUserId, float $amount, array $options): array
    {
        return Database::transaction(static function () use ($fromUserId, $toUserId, $amount, $options): array {
            $debit = self::post($fromUserId, $amount, 'debit', array_merge($options, [
                'counterparty_id' => $toUserId,
                'unlock'          => false,
                'idempotency_key' => isset($options['idempotency_key']) ? $options['idempotency_key'] . ':out' : null,
            ]));

            $credit = self::post($toUserId, $amount, 'credit', array_merge($options, [
                'counterparty_id' => $fromUserId,
                'idempotency_key' => isset($options['idempotency_key']) ? $options['idempotency_key'] . ':in' : null,
            ]));

            return ['debit' => $debit, 'credit' => $credit];
        });
    }

    /**
     * Credit a creator's earnings for a completed booking.
     * The amount lands in `locked_balance` and matures after the configured
     * hold period, which is what the escrow:release cron job drains.
     */
    public static function creditEarning(int $creatorUserId, float $amount, int $bookingId, string $bookingCode): array
    {
        $holdDays = Config::settingInt('wallet', 'withdrawal_hold_days', 3);

        $transaction = self::credit($creatorUserId, $amount, [
            'category'        => 'booking_earning',
            'description'     => 'Earnings from booking #' . $bookingCode,
            'reference_type'  => 'booking',
            'reference_id'    => $bookingId,
            'idempotency_key' => 'earning:booking:' . $bookingId,
            'metadata'        => ['booking_code' => $bookingCode, 'hold_days' => $holdDays],
        ]);

        // Hold the freshly credited amount until it matures.
        if ($holdDays > 0) {
            Database::execute(
                'UPDATE `wallets`
                 SET `balance` = `balance` - :amount, `locked_balance` = `locked_balance` + :amount
                 WHERE `user_id` = :user_id AND `balance` >= :amount',
                ['amount' => Money::round($amount), 'user_id' => $creatorUserId]
            );

            Database::insertIgnore('wallet_transactions', [
                'reference'       => Str::referenceCode('ZHLD', $bookingId, 10),
                'wallet_id'       => (int) $transaction['wallet_id'],
                'user_id'         => $creatorUserId,
                'direction'       => 'debit',
                'category'        => 'escrow_hold',
                'amount'          => Money::round($amount),
                'balance_before'  => (float) $transaction['balance_after'],
                'balance_after'   => Money::subtract((float) $transaction['balance_after'], $amount),
                'currency'        => (string) $transaction['currency'],
                'status'          => 'completed',
                'reference_type'  => 'booking',
                'reference_id'    => $bookingId,
                'description'     => 'Held until ' . date('d M Y', strtotime('+' . $holdDays . ' days')),
                'idempotency_key' => 'hold:booking:' . $bookingId,
                'metadata'        => json_encode([
                    'matures_at' => date('Y-m-d H:i:s', strtotime('+' . $holdDays . ' days')),
                ], JSON_UNESCAPED_UNICODE),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        }

        return $transaction;
    }

    /**
     * Release matured earnings from locked to spendable.
     * Called hourly by the escrow:release job.
     *
     * @return int Number of holds released.
     */
    public static function releaseMaturedHolds(): int
    {
        $holdDays = Config::settingInt('wallet', 'withdrawal_hold_days', 3);

        $holds = Database::select(
            "SELECT * FROM `wallet_transactions`
             WHERE `category` = 'escrow_hold'
               AND `status` = 'completed'
               AND `created_at` <= DATE_SUB(NOW(), INTERVAL :days DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM `wallet_transactions` r
                   WHERE r.`category` = 'escrow_release'
                     AND r.`reference_type` = `wallet_transactions`.`reference_type`
                     AND r.`reference_id` = `wallet_transactions`.`reference_id`
               )
             LIMIT 200",
            ['days' => max(0, $holdDays)]
        );

        $released = 0;

        foreach ($holds as $hold) {
            try {
                self::release((int) $hold['user_id'], (float) $hold['amount'], [
                    'category'        => 'escrow_release',
                    'description'     => 'Earnings released and available to withdraw',
                    'reference_type'  => (string) $hold['reference_type'],
                    'reference_id'    => (int) $hold['reference_id'],
                    'idempotency_key' => 'release:' . (string) $hold['reference_type'] . ':' . (int) $hold['reference_id'],
                ]);

                $released++;
            } catch (\Throwable $e) {
                Logger::error('Failed to release an escrow hold', [
                    'transaction_id' => $hold['id'],
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        return $released;
    }

    // -----------------------------------------------------------------
    // Withdrawals
    // -----------------------------------------------------------------

    /**
     * Raise a payout request. The amount is debited immediately and moved to
     * locked, so a creator cannot spend money that is already on its way out.
     */
    public static function requestWithdrawal(int $userId, float $amount, int $bankAccountId): array
    {
        $amount = Money::round($amount);

        $minimum = Config::settingFloat('wallet', 'min_withdrawal', 1000);
        $maximum = Config::settingFloat('wallet', 'max_withdrawal', 200000);

        if ($amount < $minimum) {
            throw HttpException::unprocessable('The minimum withdrawal is ' . Money::format($minimum) . '.');
        }

        if ($amount > $maximum) {
            throw HttpException::unprocessable('The maximum withdrawal is ' . Money::format($maximum) . '.');
        }

        if (Config::settingBool('wallet', 'require_kyc_for_withdrawal', true)) {
            $kycStatus = Database::scalar('SELECT `kyc_status` FROM `users` WHERE `id` = :id', ['id' => $userId]);

            if ($kycStatus !== 'verified') {
                throw new HttpException(
                    'Complete your KYC verification before requesting a payout.',
                    403,
                    'kyc_required'
                );
            }
        }

        $bankAccount = Database::selectOne(
            'SELECT * FROM `bank_accounts` WHERE `id` = :id AND `user_id` = :user_id AND `deleted_at` IS NULL',
            ['id' => $bankAccountId, 'user_id' => $userId]
        );

        if ($bankAccount === null) {
            throw HttpException::notFound('The selected payout account was not found.');
        }

        ['fee' => $fee, 'net' => $net] = Money::withdrawalBreakdown($amount);

        return Database::transaction(static function () use ($userId, $amount, $fee, $net, $bankAccount): array {
            // Debit + lock in one movement: money leaves the spendable balance.
            self::lock($userId, $amount, [
                'category'       => 'withdrawal',
                'description'    => 'Withdrawal request',
                'reference_type' => 'withdrawal',
            ]);

            $withdrawalId = Database::insert('withdrawal_requests', [
                'reference'       => 'PENDING',
                'user_id'         => $userId,
                'bank_account_id' => (int) $bankAccount['id'],
                'amount'          => $amount,
                'fee_amount'      => $fee,
                'net_amount'      => $net,
                'currency'        => Money::defaultCurrency(),
                'method'          => (string) $bankAccount['method'],
                'status'          => 'pending',
                'snapshot'        => json_encode([
                    'method'         => $bankAccount['method'],
                    'holder'         => $bankAccount['account_holder_name'],
                    'account_last4'  => $bankAccount['account_last4'],
                    'ifsc'           => $bankAccount['ifsc_code'],
                    'bank_name'      => $bankAccount['bank_name'],
                    'upi_id'         => $bankAccount['upi_id'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            $reference = Str::referenceCode('ZWD', $withdrawalId, 8);
            Database::update('withdrawal_requests', ['reference' => $reference], ['id' => $withdrawalId]);

            $row = Database::selectOne('SELECT * FROM `withdrawal_requests` WHERE `id` = :id', ['id' => $withdrawalId]);

            if ($row === null) {
                throw HttpException::server('Failed to create the withdrawal request.');
            }

            return $row;
        });
    }

    /**
     * Whether the spendable balance could be paid out right now, and if not,
     * every reason why, in this order: below_minimum (wallet.min_withdrawal),
     * kyc_required (wallet.require_kyc_for_withdrawal with KYC not verified,
     * as requestWithdrawal() checks) and contact_unverified (neither email
     * nor phone verified, which the withdraw route requires).
     *
     * [$balance] lets a caller that already holds the wallet row pass it.
     *
     * @return array{possible:bool,reason:?string,reasons:list<string>}
     */
    public static function withdrawability(int $userId, ?float $balance = null): array
    {
        $balance = Money::round($balance ?? self::balance($userId)['balance']);
        $reasons = [];

        if ($balance < Money::round(Config::settingFloat('wallet', 'min_withdrawal', 1000))) {
            $reasons[] = 'below_minimum';
        }

        $user = Database::selectOne(
            'SELECT `kyc_status`, `email_verified_at`, `phone_verified_at` FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        ) ?? [];

        if (Config::settingBool('wallet', 'require_kyc_for_withdrawal', true) && ($user['kyc_status'] ?? null) !== 'verified') {
            $reasons[] = 'kyc_required';
        }

        if (empty($user['email_verified_at']) && empty($user['phone_verified_at'])) {
            $reasons[] = 'contact_unverified';
        }

        return ['possible' => $reasons === [], 'reason' => $reasons[0] ?? null, 'reasons' => $reasons];
    }

    /** Finance marks a payout as settled: the locked amount leaves the wallet. */
    public static function completeWithdrawal(int $withdrawalId, string $payoutReference, int $adminId): void
    {
        Database::transaction(static function () use ($withdrawalId, $payoutReference, $adminId): void {
            $withdrawal = Database::selectOne(
                'SELECT * FROM `withdrawal_requests` WHERE `id` = :id FOR UPDATE',
                ['id' => $withdrawalId]
            );

            if ($withdrawal === null) {
                throw HttpException::notFound('Withdrawal request not found.');
            }

            // An ALLOW list, not a block list.
            //
            // Blocking only the finalised states let a `pending` payout pass
            // straight through to settlement, so one admin holding
            // wallet.withdrawals could settle any payout of any size without
            // the approval step ever happening — defeating the separation of
            // duties that approveWithdrawal exists to enforce.
            //
            // Marking money as paid is the last step of a reviewed process, so
            // it names the states it accepts rather than guessing at the ones
            // it should refuse.
            if (!in_array($withdrawal['status'], ['approved', 'processing'], true)) {
                throw HttpException::conflict(
                    in_array($withdrawal['status'], ['completed', 'rejected', 'cancelled'], true)
                        ? 'This withdrawal has already been finalised.'
                        : 'This withdrawal has not been approved yet. Approve it before marking it paid.'
                );
            }

            $amount = Money::round((float) $withdrawal['amount']);

            // The money was already debited at request time; here we simply
            // drop it out of locked_balance because it has left the platform.
            Database::execute(
                'UPDATE `wallets`
                 SET `locked_balance` = GREATEST(0, `locked_balance` - :amount), `last_activity_at` = NOW()
                 WHERE `user_id` = :user_id',
                ['amount' => $amount, 'user_id' => (int) $withdrawal['user_id']]
            );

            Database::update('withdrawal_requests', [
                'status'           => 'completed',
                'payout_reference' => mb_substr($payoutReference, 0, 120),
                'processed_by'     => $adminId,
                'processed_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $withdrawalId]);
        });
    }

    /** Reject a payout: the locked amount returns to spendable balance. */
    public static function rejectWithdrawal(int $withdrawalId, string $reason, int $adminId): void
    {
        Database::transaction(static function () use ($withdrawalId, $reason, $adminId): void {
            $withdrawal = Database::selectOne(
                'SELECT * FROM `withdrawal_requests` WHERE `id` = :id FOR UPDATE',
                ['id' => $withdrawalId]
            );

            if ($withdrawal === null) {
                throw HttpException::notFound('Withdrawal request not found.');
            }

            if (in_array($withdrawal['status'], ['completed', 'rejected', 'cancelled'], true)) {
                throw HttpException::conflict('This withdrawal has already been finalised.');
            }

            self::release((int) $withdrawal['user_id'], (float) $withdrawal['amount'], [
                'category'          => 'reversal',
                'description'       => 'Withdrawal ' . (string) $withdrawal['reference'] . ' returned to wallet',
                'reference_type'    => 'withdrawal',
                'reference_id'      => $withdrawalId,
                'idempotency_key'   => 'withdrawal-reversal:' . $withdrawalId,
                'performed_by_type' => 'admin',
                'performed_by_id'   => $adminId,
            ]);

            Database::update('withdrawal_requests', [
                'status'           => 'rejected',
                'rejection_reason' => mb_substr($reason, 0, 500),
                'processed_by'     => $adminId,
                'processed_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $withdrawalId]);
        });
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    /**
     * @param  array{category?:string,direction?:string,from?:string,to?:string,page?:int,per_page?:int} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function transactions(int $userId, array $filters = []): array
    {
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $clauses  = ['`user_id` = :user_id'];
        $bindings = ['user_id' => $userId];

        if (!empty($filters['category'])) {
            $clauses[]            = '`category` = :category';
            $bindings['category'] = $filters['category'];
        }

        if (!empty($filters['direction'])) {
            $clauses[]             = '`direction` = :direction';
            $bindings['direction'] = $filters['direction'];
        }

        if (!empty($filters['from'])) {
            $clauses[]        = '`created_at` >= :from';
            $bindings['from'] = $filters['from'] . ' 00:00:00';
        }

        if (!empty($filters['to'])) {
            $clauses[]      = '`created_at` <= :to';
            $bindings['to'] = $filters['to'] . ' 23:59:59';
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `wallet_transactions`' . $where, $bindings);

        $items = Database::select(
            'SELECT * FROM `wallet_transactions`' . $where
            . ' ORDER BY `id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings
        );

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Recompute a wallet from its ledger and report any drift.
     * Run from the admin panel when a balance is disputed.
     *
     * The ledger tracks the *spendable* balance and nothing else: every row's
     * balance_before/balance_after is a `wallets`.`balance` reading, and both
     * ways of parking money — {@see lock()} for a payout and the escrow_hold
     * leg of {@see creditEarning()} — write a debit row for what is only a
     * balance → locked_balance move. Reconciling the ledger against
     * balance + locked_balance therefore double-counted every hold and
     * reported drift of exactly `locked_balance` on wallets that were in fact
     * perfectly consistent, while masking genuine gaps on wallets whose locked
     * balance happened to offset the difference.
     *
     * `locked_balance` is a separate invariant and is reported alongside, not
     * folded into, the ledger comparison.
     *
     * @return array{stored:float,computed:float,matches:bool,locked:float,total:float}
     */
    public static function reconcile(int $userId): array
    {
        $wallet = self::existing($userId);

        $computed = (float) Database::scalar(
            "SELECT COALESCE(SUM(CASE WHEN `direction` = 'credit' THEN `amount` ELSE -`amount` END), 0)
             FROM `wallet_transactions`
             WHERE `user_id` = :user_id AND `status` = 'completed'",
            ['user_id' => $userId]
        );

        $stored = $wallet === null ? 0.0 : Money::round((float) $wallet['balance']);
        $locked = $wallet === null ? 0.0 : Money::round((float) $wallet['locked_balance']);

        return [
            'stored'   => $stored,
            'computed' => Money::round($computed),
            'matches'  => Money::equals($stored, $computed),
            'locked'   => $locked,
            'total'    => Money::add($stored, $locked),
        ];
    }
}

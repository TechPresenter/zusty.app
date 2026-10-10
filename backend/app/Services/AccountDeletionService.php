<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Jwt;
use Zuste\Core\Logger;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * "Delete my account": the erasure of a brand or creator account.
 *
 * This is not a soft-delete that hides a row. Personal data is removed, or
 * anonymised where a record has to survive. The `users` row itself stays as
 * an anonymous tombstone ("Deleted user", no email, phone, password or
 * photo), because bookings, payments, invoices, refunds and the wallet
 * ledger must keep pointing at something: Indian tax and accounting law
 * requires those records to be retained, and 031 made their foreign keys
 * RESTRICT so nothing could cascade them away. Users are tombstoned, never
 * hard-deleted, and the deletion record itself is RESTRICT too (048).
 *
 * What goes and what stays is listed in {@see ERASED} and {@see retained()}.
 * The app and the public /account-deletion page show those lists, so the
 * screen and the behaviour cannot drift apart.
 *
 * Who can start it, recorded as `verified_via`:
 *   - the owner, after a password, an email or SMS code, or a fresh Google /
 *     Apple sign-in (password | email_code | sms_code | firebase);
 *   - support, on a request whose sender they confirmed (operator), from the
 *     admin panel or scripts/privacy_support_request.php;
 *   - the one-off clean-up of accounts closed before erasure existed
 *     (legacy, {@see eraseLegacyDeletion()}).
 *
 * Order of operations:
 *   1. Before anything, refuse while something is still in motion: open
 *      bookings, money in the wallet that has not been withdrawn or given
 *      up, a withdrawal, refund or top-up in flight ({@see assertDeletable()}).
 *   2. One transaction: lock the account row, then its wallet; check the
 *      blockers again on the locked state; record the deletion; give up the
 *      balance the user agreed to give up; close the wallet; erase and
 *      anonymise every row; tombstone the account; queue the goodbye.
 *   3. After it commits: revoke this access token, write the audit row and
 *      the erasure ledger, and delete files, data exports and KYC documents.
 *      Deleting the Firebase sign-in record runs once the response is on its
 *      way. Every step after the commit is recorded on the deletion row and
 *      retried by privacy:finish-deletions until it succeeds
 *      ({@see finishPending()}); none of them can undo the erasure.
 */
final class AccountDeletionService
{
    /** Booking statuses where work or money is still in motion. */
    private const OPEN_BOOKING_STATUSES = [
        'pending', 'countered', 'accepted', 'awaiting_payment', 'confirmed', 'in_progress', 'delivered', 'disputed',
    ];

    /**
     * Upload folders whose files belong to records that are kept: booking
     * deliverables and chat attachments are the other party's record too,
     * and KYC files are the KYC module's to retain or purge.
     */
    private const RETAINED_MEDIA_FOLDERS = ['deliverables', 'chat', 'kyc'];

    /** How the owner (or support) confirmed a deletion through {@see delete()}. */
    private const VERIFIED_VIA = ['password', 'email_code', 'sms_code', 'firebase', 'operator'];

    /** Every value account_deletion_requests.verified_via can hold. */
    private const RECORDED_VIA = ['password', 'email_code', 'sms_code', 'firebase', 'operator', 'legacy'];

    /** The clean-up steps that run after the erasure commits. */
    private const STEPS = ['files', 'exports', 'kyc', 'firebase'];

    /** Failed clean-up rounds after which a deletion needs a person to look at it. */
    private const ATTENTION_AFTER = 8;

    /** A top-up still being confirmed this recently blocks the deletion. */
    private const TOPUP_WINDOW_MINUTES = 30;

    /** @var list<array{key:string,title:string,detail:string}> */
    private const ERASED = [
        ['key' => 'profile',  'title' => 'Your profile',
         'detail' => 'Name, photo, bio, city, company details, and every email address and phone number on your account, except where listed under What we keep.'],
        ['key' => 'creator',  'title' => 'Your creator profile',
         'detail' => 'Public profile, portfolio, packages, social accounts, credentials, experience, FAQs, availability and its edit history. It leaves search straight away.'],
        ['key' => 'campaigns', 'title' => 'Your campaigns',
         'detail' => 'Open and draft campaigns are closed and removed. Creators who applied or were invited get a notification that the campaign has closed.'],
        ['key' => 'activity', 'title' => 'Saved items and activity',
         'detail' => 'Saved creators, follows, search history, the profiles you viewed, notifications, and our copies of the emails, texts and push messages sent to you.'],
        ['key' => 'signin',   'title' => 'Sign-in and devices',
         'detail' => 'Your password, linked Google or Apple sign-in, and every session and device. You are signed out everywhere.'],
        ['key' => 'payouts',  'title' => 'Payout details',
         'detail' => 'Saved bank accounts and UPI IDs. Past payouts keep only what is listed under What we keep.'],
        ['key' => 'files',    'title' => 'Files you uploaded',
         'detail' => 'Photos, videos and documents, except files attached to bookings or sent in chat.'],
        ['key' => 'kyc',      'title' => 'Identity documents',
         'detail' => 'KYC document images and numbers are deleted. A minimal record that a verification took place (its reference, outcome and dates) is kept.'],
        ['key' => 'exports',  'title' => 'Data exports',
         'detail' => 'Copies of your data waiting on Zusty\'s servers, and their download links. Files you already downloaded stay on your device.'],
    ];

    /**
     * Everything kept except the backups entry, whose retention period comes
     * from configuration ({@see retained()}).
     *
     * @var list<array{key:string,title:string,detail:string}>
     */
    private const RETAINED = [
        ['key' => 'financial', 'title' => 'Bookings, payments, invoices and payouts',
         'detail' => 'Kept as Indian tax and accounting law requires, generally for 8 years. Bookings and the wallet ledger show "Deleted user" in place of your account. Tax invoices and payout statements stay exactly as issued, with the name, address and GSTIN printed on them. Payout records keep the account holder\'s name, the bank and the last digits of the account number or UPI ID. Payment providers keep their own records of your payments.'],
        ['key' => 'messages',  'title' => 'Messages you sent',
         'detail' => 'They stay in the other person\'s conversation, shown as from "Deleted user". They are part of that person\'s record and may be needed to resolve a dispute. Notifications about you in their app lose your name; emails already delivered to them can\'t be recalled.'],
        ['key' => 'reviews',   'title' => 'Reviews you wrote',
         'detail' => 'They stay on the creator\'s or brand\'s page, shown as from "Deleted user" with no photo, so ratings stay honest.'],
        ['key' => 'safety',    'title' => 'Support tickets, contact-form messages and reports',
         'detail' => 'Your name and contact details are removed from them, but what you and our team wrote stays as written, for safety, fraud prevention and legal claims.'],
        ['key' => 'security',  'title' => 'Security logs',
         'detail' => 'Sign-in and account activity logs: the time, IP address and device of each sign-in, without your email address or phone number, kept for up to a year to detect fraud and abuse.'],
        ['key' => 'verification', 'title' => 'Identity verification record',
         'detail' => 'That your identity was checked, when, the outcome, and the name and IP address recorded at each step, kept for the legal period.'],
        ['key' => 'record',    'title' => 'A deletion record',
         'detail' => 'That an account was deleted, when, how it was confirmed, the reason you chose and anything you wrote with it, with no contact details.'],
    ];

    // -----------------------------------------------------------------
    // Before
    // -----------------------------------------------------------------

    /**
     * Everything deletion erases and keeps, for the public account-deletion
     * page (Web\PageController), which must say the same as the app.
     *
     * @return array{erased:list<array{key:string,title:string,detail:string}>,retained:list<array{key:string,title:string,detail:string}>}
     */
    public static function policy(): array
    {
        return ['erased' => self::ERASED, 'retained' => self::retained()];
    }

    /**
     * What is kept after a deletion, in the order the screens show it:
     * financial, messages, reviews, safety, security, verification, record,
     * backups.
     *
     * @return list<array{key:string,title:string,detail:string}>
     */
    public static function retained(): array
    {
        $days = max(1, (int) Config::get('backup.retention_days', 14));

        return array_merge(self::RETAINED, [[
            'key'    => 'backups',
            'title'  => 'Backups',
            'detail' => 'Copies of our database are kept for up to ' . $days . ' days to recover from failures, then deleted. '
                . 'If we ever restore one, we delete your account again straight away.',
        ]]);
    }

    /**
     * What the deletion screen shows before the user commits.
     *
     * `can_delete` is true when nothing hard blocks the deletion. A small
     * balance that cannot be paid out is not a blocker: it comes back as
     * `forfeit`, which the user can agree to give up.
     *
     * @param  array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function preflight(array $user): array
    {
        $userId      = (int) $user['id'];
        $accountType = (string) ($user['account_type'] ?? 'brand');
        $assessment  = self::assess($userId);

        $erased = array_values(array_filter(
            self::ERASED,
            static fn (array $item): bool => match ($item['key']) {
                'creator'   => $accountType === 'creator',
                'campaigns' => $accountType === 'brand',
                default     => true,
            }
        ));

        return [
            'can_delete'      => $assessment['blockers'] === [],
            'blockers'        => $assessment['blockers'],
            'forfeit'         => $assessment['forfeit'],
            'confirm_phrase'  => PrivacyService::CONFIRM_PHRASE,
            'reasons'         => self::reasons($accountType),
            'erased'          => $erased,
            'retained'        => self::retained(),
            'retained_counts' => self::retainedCounts($userId),
        ];
    }

    /**
     * Optional reasons for leaving. The code is stored, the label is shown.
     *
     * @return list<array{code:string,label:string}>
     */
    public static function reasons(string $accountType): array
    {
        $reasons = [
            'not_useful'    => 'I don\'t use Zusty any more',
            'opportunities' => $accountType === 'creator'
                ? 'Not enough bookings or opportunities'
                : 'I couldn\'t find the right creators',
            'fees'          => 'The fees are too high',
            'privacy'       => 'I\'m worried about my privacy',
            'notifications' => 'Too many emails or notifications',
            'experience'    => 'I had a bad experience',
            'other_account' => 'I have another account',
            'other'         => 'Something else',
        ];

        $list = [];

        foreach ($reasons as $code => $label) {
            $list[] = ['code' => $code, 'label' => $label];
        }

        return $list;
    }

    /**
     * Everything that must be settled first, each with what to do about it.
     *
     * @return list<array{code:string,title:string,message:string,action:string,count?:int,amount?:float}>
     */
    public static function blockers(int $userId): array
    {
        return self::assess($userId)['blockers'];
    }

    /**
     * Refuse, with every reason at once, while anything blocks deletion, and
     * insist on an explicit agreement before a balance is given up.
     *
     * The controller calls this before re-authenticating, so a one-time code
     * is not spent on a deletion that was always going to be refused. The
     * erasure checks again on the locked rows, in case something changed.
     *
     * @throws HttpException 409 deletion_blocked (errors keyed by blocker code),
     *                       409 forfeit_required (errors.wallet_balance) when a
     *                       balance would be given up and [$forfeitAck] is
     *                       missing or is not exactly that balance.
     */
    public static function assertDeletable(int $userId, ?float $forfeitAck = null): void
    {
        self::refuseUnlessDeletable(self::assess($userId), $forfeitAck);
    }

    /**
     * How many records of each kept kind this account has, so the screen can
     * say "3 bookings and 2 invoices will be kept" rather than a vague maybe.
     *
     * @return array<string,int>
     */
    public static function retainedCounts(int $userId): array
    {
        $p = ['id' => $userId];

        return [
            'bookings'        => (int) Database::scalar(
                'SELECT COUNT(*) FROM `bookings` WHERE (`brand_user_id` = :id OR `creator_user_id` = :id) AND `deleted_at` IS NULL',
                $p
            ),
            'payments'        => (int) Database::scalar('SELECT COUNT(*) FROM `payments` WHERE `user_id` = :id', $p),
            'invoices'        => (int) Database::scalar('SELECT COUNT(*) FROM `invoices` WHERE `user_id` = :id', $p),
            'wallet_entries'  => (int) Database::scalar('SELECT COUNT(*) FROM `wallet_transactions` WHERE `user_id` = :id', $p),
            'messages'        => (int) Database::scalar(
                "SELECT COUNT(*) FROM `messages` WHERE `sender_id` = :id AND `sender_type` = 'user' AND `deleted_at` IS NULL",
                $p
            ),
            'reviews_written' => (int) Database::scalar(
                'SELECT COUNT(*) FROM `reviews` WHERE `reviewer_id` = :id AND `deleted_at` IS NULL',
                $p
            ),
            'support_tickets' => (int) Database::scalar('SELECT COUNT(*) FROM `support_tickets` WHERE `user_id` = :id', $p),
        ];
    }

    /**
     * The blockers and the balance that may be given up, worked out once for
     * the screen, the controller's early refusal and the locked re-check.
     *
     * Withdrawals already on their way are counted first: their money sits in
     * locked_balance too, and calling it escrow told the user to withdraw it a
     * second time. Escrow is what is left of locked_balance after them.
     *
     * @param  array<string,mixed>|null $wallet      The locked wallet row (or null when there is none).
     * @param  bool                     $walletGiven Whether [$wallet] was read by the caller; otherwise it is read here.
     * @return array{blockers:list<array<string,mixed>>,forfeit:?array{amount:float,currency:string,label:string,reason:string,message:string}}
     */
    private static function assess(int $userId, ?array $wallet = null, bool $walletGiven = false): array
    {
        $blockers     = [];
        $placeholders = "'" . implode("','", self::OPEN_BOOKING_STATUSES) . "'";

        $openBookings = (int) Database::scalar(
            "SELECT COUNT(*) FROM `bookings`
              WHERE (`brand_user_id` = :id OR `creator_user_id` = :id)
                AND `status` IN ({$placeholders}) AND `deleted_at` IS NULL",
            ['id' => $userId]
        );

        if ($openBookings > 0) {
            $blockers[] = [
                'code'    => 'open_bookings',
                'title'   => 'Bookings in progress',
                'message' => $openBookings === 1
                    ? 'You have a booking in progress. Complete or cancel it first.'
                    : "You have {$openBookings} bookings in progress. Complete or cancel them first.",
                'action'  => 'bookings',
                'count'   => $openBookings,
            ];
        }

        $openDisputes = (int) Database::scalar(
            "SELECT COUNT(*) FROM `booking_disputes` d
               JOIN `bookings` b ON b.`id` = d.`booking_id`
              WHERE (b.`brand_user_id` = :id OR b.`creator_user_id` = :id)
                AND d.`status` IN ('open', 'under_review')
                AND b.`status` NOT IN ({$placeholders})",
            ['id' => $userId]
        );

        if ($openDisputes > 0) {
            $blockers[] = [
                'code'    => 'open_disputes',
                'title'   => 'A dispute is being reviewed',
                'message' => 'Our team is still reviewing a dispute on one of your bookings. You can delete your account once it is resolved.',
                'action'  => 'bookings',
                'count'   => $openDisputes,
            ];
        }

        $withdrawals = Database::selectOne(
            "SELECT COUNT(*) AS `n`, COALESCE(SUM(`amount`), 0) AS `total`, MAX(`currency`) AS `currency`
               FROM `withdrawal_requests`
              WHERE `user_id` = :id AND `status` IN ('pending', 'approved', 'processing')",
            ['id' => $userId]
        ) ?? [];

        $inFlight = Money::round((float) ($withdrawals['total'] ?? 0));

        if (!$walletGiven) {
            $wallet = WalletService::existing($userId);
        }

        $currency = (string) ($wallet['currency'] ?? Money::defaultCurrency());
        $balance  = Money::round((float) ($wallet['balance'] ?? 0));
        $escrow   = Money::max(0.0, Money::subtract((float) ($wallet['locked_balance'] ?? 0), $inFlight));
        $forfeit  = null;

        if ((bool) ($wallet['is_frozen'] ?? false)) {
            $blockers[] = [
                'code'    => 'wallet_on_hold',
                'title'   => 'Your wallet is on hold',
                'message' => 'Your wallet is on hold while our team reviews it. Contact support to close your account.',
                'action'  => 'support',
            ];
        } elseif ($escrow > 0.0) {
            $blockers[] = [
                'code'    => 'wallet_balance',
                'title'   => 'Money in your wallet',
                'message' => ($balance > 0.0
                        ? 'You have ' . Money::format($balance, $currency) . ' in your wallet and '
                          . Money::format($escrow, $currency) . ' still being released from escrow. Withdraw it all once it is released, '
                        : Money::format($escrow, $currency) . ' of your earnings is still being released from escrow. Withdraw it once it is released, ')
                    . 'then you can delete your account. If you need help, contact support.',
                'action'  => 'wallet',
                'amount'  => Money::add($balance, $escrow),
            ];
        } elseif ($balance > 0.0) {
            $minimum = Config::settingFloat('wallet', 'min_withdrawal', 1000);
            $limit   = max(0.0, Config::settingFloat('security', 'privacy_deletion_forfeit_limit', $minimum));
            $payout  = WalletService::withdrawability($userId, $balance);
            $label   = Money::format($balance, $currency);
            $floor   = Money::format($minimum, $currency);

            if ($payout['possible']) {
                $blockers[] = [
                    'code'    => 'wallet_balance',
                    'title'   => 'Money in your wallet',
                    'message' => 'You have ' . $label . ' in your wallet. Withdraw it before deleting your account so you don\'t lose it.',
                    'action'  => 'wallet',
                    'amount'  => $balance,
                ];
            } elseif ($limit > 0.0 && $balance <= Money::round($limit)) {
                $reason = (string) $payout['reason'];

                $forfeit = [
                    'amount'   => $balance,
                    'currency' => $currency,
                    'label'    => $label,
                    'reason'   => $reason,
                    'message'  => 'You have ' . $label . ' in your wallet. ' . match ($reason) {
                        'below_minimum' => 'That\'s below the ' . $floor . ' minimum withdrawal, so it can\'t be paid out.',
                        'kyc_required'  => 'Withdrawals need a completed identity verification (KYC), so it can\'t be paid out yet.',
                        default         => 'Withdrawals need a verified email address or mobile number, so it can\'t be paid out yet.',
                    } . ' You can give it up and delete your account, or contact support and we\'ll pay it out first.',
                ];
            } else {
                $reasons = $payout['reasons'];
                $onlyKyc = $reasons === ['kyc_required'];

                if ($onlyKyc) {
                    $message = 'You have ' . $label . ' in your wallet. Complete your identity verification (KYC) to withdraw it '
                        . 'before deleting your account, or contact support and we\'ll help you settle it.';
                } else {
                    $needs = [];

                    if (in_array('kyc_required', $reasons, true)) {
                        $needs[] = 'a completed identity verification (KYC)';
                    }

                    if (in_array('contact_unverified', $reasons, true)) {
                        $needs[] = 'a verified email address or mobile number';
                    }

                    $why = in_array('below_minimum', $reasons, true) || $needs === []
                        ? 'That\'s below the ' . $floor . ' minimum withdrawal, so it can\'t be paid out from the app.'
                        : 'Withdrawals need ' . implode(' and ', $needs) . '.';

                    $message = 'You have ' . $label . ' in your wallet. ' . $why
                        . ' Contact support and we\'ll help you settle it before you delete your account.';
                }

                $blockers[] = [
                    'code'    => 'wallet_balance',
                    'title'   => 'Money in your wallet',
                    'message' => $message,
                    'action'  => $onlyKyc ? 'kyc' : 'support',
                    'amount'  => $balance,
                ];
            }
        }

        if ((int) ($withdrawals['n'] ?? 0) > 0) {
            $blockers[] = [
                'code'    => 'pending_withdrawals',
                'title'   => 'A withdrawal is on its way',
                'message' => 'A withdrawal of ' . Money::format($inFlight, ($withdrawals['currency'] ?? null) ?: null)
                    . ' is still being processed. You can delete your account once it has been paid out.',
                'action'  => 'wallet',
                'count'   => (int) $withdrawals['n'],
                'amount'  => $inFlight,
            ];
        }

        $refunds = (int) Database::scalar(
            "SELECT COUNT(*) FROM `refunds` WHERE `user_id` = :id AND `status` IN ('pending', 'processing')",
            ['id' => $userId]
        );

        if ($refunds > 0) {
            $blockers[] = [
                'code'    => 'pending_refunds',
                'title'   => 'A refund is on its way',
                'message' => 'A refund to you is still being processed. You can delete your account once it arrives.',
                'action'  => 'wallet',
                'count'   => $refunds,
            ];
        }

        // An older abandoned order does not block: if it is ever captured,
        // PaymentService refunds it to its source because the wallet is closed.
        $topups = (int) Database::scalar(
            "SELECT COUNT(*) FROM `payments`
              WHERE `user_id` = :id AND `purpose` = 'wallet_topup' AND `status` = 'created'
                AND `created_at` > NOW() - INTERVAL " . self::TOPUP_WINDOW_MINUTES . ' MINUTE',
            ['id' => $userId]
        );

        if ($topups > 0) {
            $blockers[] = [
                'code'    => 'pending_topup',
                'title'   => 'A payment to your wallet is in progress',
                'message' => 'A payment into your wallet is still being confirmed. Wait a few minutes, then check again.',
                'action'  => 'wallet',
                'count'   => $topups,
            ];
        }

        return ['blockers' => $blockers, 'forfeit' => $forfeit];
    }

    /**
     * @param array{blockers:list<array<string,mixed>>,forfeit:?array<string,mixed>} $assessment
     */
    private static function refuseUnlessDeletable(array $assessment, ?float $forfeitAck): void
    {
        $blockers = $assessment['blockers'];

        if ($blockers !== []) {
            $errors = [];

            foreach ($blockers as $blocker) {
                $errors[(string) $blocker['code']] = [(string) $blocker['message']];
            }

            throw new HttpException(
                count($blockers) === 1
                    ? (string) $blockers[0]['message']
                    : 'A few things need to be settled before your account can be deleted.',
                409,
                'deletion_blocked',
                $errors
            );
        }

        $forfeit = $assessment['forfeit'];

        if ($forfeit === null || ($forfeitAck !== null && Money::equals($forfeitAck, (float) $forfeit['amount']))) {
            return;
        }

        $message = $forfeitAck === null
            ? (string) $forfeit['message']
            : 'Your wallet balance is now ' . $forfeit['label'] . ', not the amount you agreed to give up. ' . $forfeit['message'];

        throw new HttpException($message, 409, 'forfeit_required', ['wallet_balance' => [$message]]);
    }

    // -----------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------

    /**
     * Erase the account.
     *
     * The caller has already re-authenticated the user (or, for 'operator',
     * a member of staff confirmed who sent the request) and checked the typed
     * confirmation. An account that already has a deletion record comes back
     * as `already_deleted` with nothing done twice.
     *
     * @param  array<string,mixed> $user The account row (re-read and locked here).
     * @param  'password'|'email_code'|'sms_code'|'firebase'|'operator' $verifiedVia
     * @param  array<string,mixed> $context jti, token_exp, platform, forfeit_balance (?float);
     *                                      operator only: admin_id (?int), note (?string, up to 500), via ('panel'|'cli')
     * @return array{deletion_id:int,already_deleted:bool,erased:array<string,int>,retained:array<string,int>,
     *               notice:?array{channel:string,destination:string},forfeited:?array{amount:float,currency:string}}
     *
     * @throws HttpException 403 (not a brand or creator), 404 (no such account, or
     *                       self-service on an account staff soft-deleted), 409
     *                       deletion_blocked / forfeit_required
     */
    public static function delete(
        array $user,
        string $verifiedVia,
        ?string $reasonCode = null,
        ?string $reasonText = null,
        array $context = []
    ): array {
        $accountType = (string) ($user['account_type'] ?? '');

        if (!in_array($accountType, ['brand', 'creator'], true)) {
            throw HttpException::forbidden('This account cannot be deleted here.');
        }

        if (!in_array($verifiedVia, self::VERIFIED_VIA, true)) {
            throw new \InvalidArgumentException('Unknown way of confirming a deletion: ' . $verifiedVia);
        }

        $codes = array_column(self::reasons($accountType), 'code');

        if ($reasonCode !== null && $reasonCode !== '' && !in_array($reasonCode, $codes, true)) {
            $reasonCode = 'other';
        }

        return self::erase(
            $verifiedVia === 'operator' ? 'operator' : 'self',
            (int) $user['id'],
            $verifiedVia,
            $reasonCode === '' ? null : $reasonCode,
            $reasonText,
            $context
        );
    }

    /**
     * Erase an account that was closed through the pre-043 self-service path,
     * which only hid it. No blockers (any wallet balance stays on the closed
     * wallet for finance to settle), no goodbye and no token to revoke.
     *
     * @return array{deletion_id:int,already_deleted:bool,erased:array<string,int>,retained:array<string,int>,
     *               notice:?array{channel:string,destination:string},forfeited:?array{amount:float,currency:string}}
     */
    public static function eraseLegacyDeletion(int $userId): array
    {
        return self::erase('legacy', $userId, 'legacy', null, null, []);
    }

    /**
     * After a database restore, erase again every account the erasure ledger
     * says was deleted but the restored copy brought back. Never throws: a
     * restore must finish, and each failure is logged for a person to finish.
     *
     * @return int Accounts erased again.
     */
    public static function reapplyAfterRestore(): int
    {
        try {
            $entries = self::ledgerEntries();
        } catch (\Throwable $e) {
            Logger::critical('Erasures could not be re-applied after a restore: the ledger is unreadable.', ['error' => $e->getMessage()]);

            return 0;
        }

        $reapplied = 0;

        foreach ($entries as $userId => $entry) {
            try {
                $row = Database::selectOne(
                    'SELECT `id`, `status`, `name`, `email`, `account_type` FROM `users` WHERE `id` = :id',
                    ['id' => $userId]
                );

                if ($row === null || self::isErasedTombstone($row)) {
                    continue;
                }

                if (!in_array((string) $row['account_type'], ['brand', 'creator'], true)) {
                    Logger::error('A restored account in the erasure ledger is not a brand or creator; left for a person to check.', [
                        'user_id' => $userId,
                    ]);

                    continue;
                }

                $via = in_array((string) ($entry['verified_via'] ?? ''), self::RECORDED_VIA, true)
                    ? (string) $entry['verified_via']
                    : 'legacy';

                $reason = isset($entry['reason_code']) && is_string($entry['reason_code']) && $entry['reason_code'] !== ''
                    ? mb_substr($entry['reason_code'], 0, 40)
                    : null;

                self::erase('reapply', $userId, $via, $reason, null, []);
                $reapplied++;
            } catch (\Throwable $e) {
                Logger::critical('An erasure could not be re-applied after a restore.', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $reapplied;
    }

    /**
     * The erasure itself, shared by every way in.
     *
     * Modes: 'self' (the owner), 'operator' (support), 'legacy' (an account
     * closed before erasure existed) and 'reapply' (after a restore).
     *
     * @param  array<string,mixed> $context
     * @return array{deletion_id:int,already_deleted:bool,erased:array<string,int>,retained:array<string,int>,
     *               notice:?array{channel:string,destination:string},forfeited:?array{amount:float,currency:string}}
     */
    private static function erase(
        string $mode,
        int $userId,
        string $verifiedVia,
        ?string $reasonCode,
        ?string $reasonText,
        array $context
    ): array {
        $operator   = $mode === 'operator';
        $assessing  = $mode === 'self' || $operator;
        $adminId    = $operator && isset($context['admin_id']) && (int) $context['admin_id'] > 0 ? (int) $context['admin_id'] : null;
        $note       = $operator && isset($context['note']) && trim((string) $context['note']) !== ''
            ? mb_substr(trim((string) $context['note']), 0, 500)
            : null;
        $via        = $operator && in_array($context['via'] ?? null, ['panel', 'cli'], true) ? (string) $context['via'] : null;
        $forfeitAck = isset($context['forfeit_balance']) && is_numeric($context['forfeit_balance'])
            ? (float) $context['forfeit_balance']
            : null;
        $reasonText = $reasonText === null || trim($reasonText) === '' ? null : mb_substr(trim($reasonText), 0, 500);
        $platform   = match ($mode) {
            'operator' => 'admin',
            'self'     => in_array($context['platform'] ?? '', ['android', 'ios', 'web'], true) ? (string) $context['platform'] : 'unknown',
            default    => 'unknown',
        };

        $outcome = Database::transaction(static function () use (
            $mode,
            $userId,
            $verifiedVia,
            $reasonCode,
            $reasonText,
            $assessing,
            $operator,
            $adminId,
            $note,
            $via,
            $forfeitAck,
            $platform
        ): array {
            $now = date('Y-m-d H:i:s');
            $p   = ['id' => $userId];

            // The account row first, then its wallet: the lock order every
            // path that touches both uses, so two of them never deadlock.
            $locked = Database::selectOne('SELECT * FROM `users` WHERE `id` = :id FOR UPDATE', $p);

            if ($locked === null) {
                throw HttpException::notFound('This account no longer exists.');
            }

            if ($mode !== 'reapply') {
                $existing = Database::scalar(
                    'SELECT `id` FROM `account_deletion_requests` WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 1',
                    $p
                );

                if ($existing !== null) {
                    return ['already_deleted' => true, 'deletion_id' => (int) $existing];
                }

                // Hidden by staff but never erased: only support may finish it.
                if ($mode === 'self' && $locked['deleted_at'] !== null) {
                    throw HttpException::notFound('This account no longer exists.');
                }
            }

            $accountType = (string) $locked['account_type'];

            if (!in_array($accountType, ['brand', 'creator'], true)) {
                throw HttpException::forbidden('This account cannot be deleted here.');
            }

            $wallet  = Database::selectOne('SELECT * FROM `wallets` WHERE `user_id` = :id FOR UPDATE', $p);
            $forfeit = null;

            // The authoritative check: on the locked rows, inside the
            // transaction. A blocker here rolls everything back.
            if ($assessing) {
                $assessment = self::assess($userId, $wallet, true);
                self::refuseUnlessDeletable($assessment, $forfeitAck);
                $forfeit = $assessment['forfeit'];
            }

            // ---- Who this is, read from the locked row before anything changes ----

            $creator = Database::selectOne('SELECT `id`, `display_name` FROM `creators` WHERE `user_id` = :id', $p);
            $creatorId     = (int) ($creator['id'] ?? 0);
            $email         = trim((string) ($locked['email'] ?? ''));
            $firebaseUid   = trim((string) ($locked['firebase_uid'] ?? ''));
            $points        = self::contactPointsOf($userId, $locked);
            $names         = self::namesOf($locked, (string) ($creator['display_name'] ?? ''));
            $relations     = self::relationsOf($userId);
            $files         = self::personalFiles($userId, $creatorId, $locked);
            // Reads contact_change_requests, which is erased below.
            $previousEmail = PrivacyService::previousEmail($userId);
            $hasExports    = Database::scalar('SELECT 1 FROM `data_export_requests` WHERE `user_id` = :id LIMIT 1', $p) !== null
                || is_dir(rtrim((string) Config::get('storage.base_path'), '/\\') . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . $userId);

            $steps = [];

            if ($files['media'] !== [] || $files['paths'] !== []) {
                $steps[] = 'files';
            }

            if ($hasExports) {
                $steps[] = 'exports';
            }

            // Always: the KYC purge is an idempotent no-op for anyone without KYC.
            $steps[] = 'kyc';

            if ($firebaseUid !== '') {
                $steps[] = 'firebase';
            }

            // Inserted first, in this transaction: AuthMiddleware reads it to
            // tell a signed-out device the account was deleted (C9).
            $deletionId = Database::insert('account_deletion_requests', [
                'user_id'               => $userId,
                'account_type'          => $accountType,
                'reason_code'           => $reasonCode,
                'reason_text'           => $reasonText,
                'verified_via'          => $verifiedVia,
                'platform'              => $platform,
                'performed_by_admin_id' => $adminId,
                'pending_steps'         => json_encode($steps),
                'pending_files'         => in_array('files', $steps, true) ? json_encode($files, JSON_UNESCAPED_SLASHES) : null,
                'firebase_uid_enc'      => $firebaseUid !== '' ? Crypto::encrypt($firebaseUid) : null,
                'next_attempt_at'       => date('Y-m-d H:i:s', time() + 900),
                'created_at'            => $now,
            ]);

            $erased = [];

            // ---- Money: give up what was agreed, then close the wallet ----

            $forfeited = null;

            if ($forfeit !== null) {
                WalletService::debit($userId, (float) $forfeit['amount'], [
                    'category'          => 'adjustment',
                    'reference_type'    => 'manual',
                    'description'       => 'Wallet balance given up when the account was deleted',
                    'idempotency_key'   => 'deletion-forfeit:' . $deletionId,
                    'performed_by_type' => $operator ? 'admin' : 'user',
                    'performed_by_id'   => $operator ? $adminId : $userId,
                    'metadata'          => ['deletion_id' => $deletionId, 'reason' => (string) $forfeit['reason']],
                ]);

                $forfeited = ['amount' => (float) $forfeit['amount'], 'currency' => (string) $forfeit['currency']];
            }

            // From here only admin postings reach this wallet: a late top-up
            // is refunded to its source, a pending reward is refused.
            Database::execute(
                'INSERT INTO `wallets` (`user_id`, `currency`, `closed_at`) VALUES (:id, :currency, :now)
                 ON DUPLICATE KEY UPDATE `closed_at` = COALESCE(`closed_at`, VALUES(`closed_at`))',
                ['id' => $userId, 'currency' => (string) ($wallet['currency'] ?? Money::defaultCurrency()), 'now' => $now]
            );

            $erased['referrals_voided'] = Database::execute(
                "UPDATE `referrals` SET `status` = 'void' WHERE `referred_user_id` = :id AND `status` = 'pending'",
                $p
            );
            Database::execute(
                "UPDATE `referrals` SET `referrer_reward` = 0 WHERE `referrer_user_id` = :id AND `status` = 'pending'",
                $p
            );

            // ---- The creator profile, and the brand's campaigns ----

            if ($creatorId > 0) {
                $erased += self::eraseCreator($userId, $creatorId, $now);
            }

            // Their views and engagement on other creators stay counted, unlinked.
            $erased['profile_views_by_you'] = Database::execute(
                'UPDATE `creator_profile_views` SET `viewer_id` = NULL WHERE `viewer_id` = :id',
                $p
            );
            $erased['engagement_by_you'] = Database::execute(
                'UPDATE `creator_engagement_events` SET `actor_id` = NULL WHERE `actor_id` = :id',
                $p
            );

            $applicants = [];

            if ($accountType === 'brand') {
                $erased += self::closeCampaigns($userId, $now, $applicants);
            }

            // ---- Everything that exists only for this person's benefit ----

            $tables = [
                'user_devices'                => 'user_id',
                'refresh_tokens'              => 'user_id',
                'otp_verifications'           => 'user_id',
                'password_resets'             => 'user_id',
                'notifications'               => 'user_id',
                'search_histories'            => 'user_id',
                'favorites'                   => 'user_id',
                'follows'                     => 'follower_id',
                'contact_change_requests'     => 'user_id',
                'contact_verification_events' => 'user_id',
                'user_contact_points'         => 'user_id',
                'message_receipts'            => 'user_id',
                'bank_accounts'               => 'user_id',
            ];

            foreach ($tables as $table => $column) {
                $erased[$table] = Database::execute("DELETE FROM `{$table}` WHERE `{$column}` = :id", $p);
            }

            // ---- Our copies of what was sent to them, whatever its status ----

            $emails = $points['emails'];
            $phones = $points['phones'];

            $erased['push_queue'] = Database::execute(
                "DELETE FROM `push_queue` WHERE `target_type` = 'user' AND `target_value` = :id",
                ['id' => (string) $userId]
            );

            if ($emails !== []) {
                [$in, $bindings] = self::inList('em', $emails);
                $erased['email_queue'] = Database::execute("DELETE FROM `email_queue` WHERE `to_email` IN ({$in})", $bindings);
            }

            if ($phones !== []) {
                [$in, $bindings] = self::inList('ph', $phones);
                $erased['sms_queue'] = Database::execute("DELETE FROM `sms_queue` WHERE `to_phone` IN ({$in})", $bindings);
            }

            // Codes sent before the account existed carry no user id, only
            // the address they went to.
            if ($emails !== [] || $phones !== []) {
                [$in, $bindings] = self::inList('ds', array_merge($emails, $phones));
                $erased['otp_verifications'] += Database::execute(
                    "DELETE FROM `otp_verifications` WHERE `destination` IN ({$in})",
                    $bindings
                );
            }

            [$emailIn, $emailBindings] = self::inList('ns', $emails);
            $erased['newsletter_subscribers'] = Database::execute(
                'DELETE FROM `newsletter_subscribers` WHERE `user_id` = :id'
                    . ($emails === [] ? '' : " OR `email` IN ({$emailIn})"),
                $p + $emailBindings
            );

            $marketingCampaigns = self::eraseCampaignRecipients($userId, $emails, $phones, $erased);

            // ---- Kept records lose what identified the person ----

            // Testimonials made from their reviews are anonymised below; their
            // edit history would otherwise keep the name.
            $erased['revisions'] = ($erased['revisions'] ?? 0) + Database::execute(
                "DELETE FROM `revisions`
                  WHERE `entity_type` = 'testimonials'
                    AND `entity_id` IN (SELECT t.`id` FROM `testimonials` t
                                          JOIN `reviews` r ON r.`id` = t.`review_id`
                                         WHERE r.`reviewer_id` = :id)",
                $p
            );

            $identifiers = array_merge($emails, $phones);
            [$idIn, $idBindings] = self::inList('lh', $identifiers);
            $erased['sign_in_identifiers'] = Database::execute(
                "UPDATE `login_history` SET `identifier` = :anon
                  WHERE `actor_type` = 'user' AND (`actor_id` = :id" . ($identifiers === [] ? '' : " OR `identifier` IN ({$idIn})") . ')',
                ['anon' => 'deleted-user-' . $userId] + $p + $idBindings
            );
            $erased['api_log_bodies'] = Database::execute(
                'UPDATE `api_logs` SET `request_body` = NULL WHERE `user_id` = :id AND `request_body` IS NOT NULL',
                $p
            );

            $erased['notifications_renamed'] = self::renameInOthersNotifications($userId, $names, $relations);
            $erased['gateway_payloads']      = self::scrubGatewayPayloads($userId);
            $erased['contact_messages']      = self::scrubContactMessages($emails, $phones);
            $erased += self::minimiseFinancial($userId);

            Database::execute(
                "UPDATE `support_ticket_messages` SET `sender_name` = 'Deleted user'
                  WHERE `sender_type` = 'user' AND `sender_id` = :id",
                $p
            );
            Database::execute(
                "UPDATE `activity_logs` SET `actor_name` = 'Deleted user'
                  WHERE `actor_type` = 'user' AND `actor_id` = :id",
                $p
            );
            Database::execute(
                "UPDATE `testimonials` SET `author_name` = 'Deleted user', `author_role` = NULL, `company` = NULL, `avatar_url` = NULL
                  WHERE `review_id` IN (SELECT `id` FROM `reviews` WHERE `reviewer_id` = :id)",
                $p
            );
            Database::execute(
                'UPDATE `conversation_participants` SET `is_typing` = 0, `typing_until` = NULL WHERE `user_id` = :id',
                $p
            );

            // The tombstone. Every identifying column is cleared. The row
            // stays because retained financial records point at it, and
            // `deleted_at` frees the email and phone for a future sign-up
            // through the *_active unique keys.
            Database::update('users', [
                'name'                  => 'Deleted user',
                'email'                 => null,
                'phone'                 => null,
                'password_hash'         => null,
                'firebase_uid'          => null,
                'avatar_url'            => null,
                'bio'                   => null,
                'company_name'          => null,
                'website'               => null,
                'gst_number'            => null,
                'city'                  => null,
                'state'                 => null,
                'postal_code'           => null,
                'auth_providers'        => null,
                'notification_prefs'    => null,
                'last_login_ip'         => null,
                'email_changed_at'      => null,
                'phone_changed_at'      => null,
                'referral_code'         => 'DEL' . strtoupper(bin2hex(random_bytes(6))),
                'suspended_reason'      => null,
                'status'                => 'deleted',
                'deletion_requested_at' => $now,
                'deleted_at'            => $locked['deleted_at'] ?? $now,
            ], ['id' => $userId]);

            $retained = self::retainedCounts($userId);

            // Queued, not sent, and only now: the purge above has already run,
            // and a rollback takes the goodbye with it.
            $notice = $assessing
                ? self::queueGoodbye($locked, $previousEmail, $retained, $forfeited, $verifiedVia)
                : null;

            $summary = ['erased' => $erased, 'retained' => $retained];

            if ($forfeited !== null) {
                $summary['forfeited'] = $forfeited;
            }

            if ($notice !== null) {
                $summary['notice_channel'] = $notice['channel'];
            }

            if ($operator) {
                $summary['operator'] = ['admin_id' => $adminId, 'note' => $note, 'via' => $via];
            }

            if ($mode === 'reapply') {
                $summary['reapplied_after_restore'] = true;
            }

            Database::update('account_deletion_requests', [
                'summary'            => json_encode($summary, JSON_UNESCAPED_UNICODE),
                'forfeited_amount'   => $forfeited['amount'] ?? null,
                'forfeited_currency' => $forfeited['currency'] ?? null,
            ], ['id' => $deletionId]);

            return [
                'already_deleted'     => false,
                'deletion_id'         => $deletionId,
                'account_type'        => $accountType,
                'erased'              => $erased,
                'retained'            => $retained,
                'notice'              => $notice,
                'forfeited'           => $forfeited,
                'steps'               => $steps,
                'applicants'          => $applicants,
                'marketing_campaigns' => $marketingCampaigns,
                'deleted_at'          => $now,
            ];
        });

        if ($outcome['already_deleted']) {
            return [
                'deletion_id'     => (int) $outcome['deletion_id'],
                'already_deleted' => true,
                'erased'          => [],
                'retained'        => self::retainedCounts($userId),
                'notice'          => null,
                'forfeited'       => null,
            ];
        }

        // ---- After the commit: logged, retried, never rolled back ----

        $deletionId = (int) $outcome['deletion_id'];

        // Every refresh token is gone and the user row no longer loads, so no
        // access token works any more. The current one is still blacklisted
        // explicitly, so the guarantee does not rest on that lookup alone.
        if ($mode === 'self') {
            $jti = (string) ($context['jti'] ?? '');
            $exp = (int) ($context['token_exp'] ?? 0);

            try {
                if ($jti !== '' && $exp > time()) {
                    Jwt::revoke($jti, $userId, $exp);
                }
            } catch (\Throwable $e) {
                Logger::warning('Account deletion: the access token could not be blacklisted', [
                    'deletion_id' => $deletionId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        self::audit($mode, $userId, $deletionId, $verifiedVia, $reasonCode, (string) $outcome['account_type'], $adminId, $via);

        if ($mode !== 'reapply') {
            self::appendLedger([
                'user_id'      => $userId,
                'deletion_id'  => $deletionId,
                'account_type' => (string) $outcome['account_type'],
                'reason_code'  => $reasonCode,
                'verified_via' => $verifiedVia,
                'deleted_at'   => (string) $outcome['deleted_at'],
            ]);
        }

        // Files, exports and KYC are local and quick: done now.
        try {
            self::runPendingSteps($deletionId, ['files', 'exports', 'kyc']);
        } catch (\Throwable $e) {
            Logger::error('Account deletion: the clean-up after the erasure failed; privacy:finish-deletions will retry it', [
                'deletion_id' => $deletionId,
                'error'       => $e->getMessage(),
            ]);
        }

        // Firebase is a network call: it runs once the response is on its way
        // (PHP-FPM finishes the request first), and the job is the backstop.
        if (in_array('firebase', $outcome['steps'], true)) {
            register_shutdown_function(static function () use ($deletionId): void {
                try {
                    self::runPendingSteps($deletionId, ['firebase']);
                } catch (\Throwable $e) {
                    Logger::error('Account deletion: the Firebase clean-up failed; privacy:finish-deletions will retry it', [
                        'deletion_id' => $deletionId,
                        'error'       => $e->getMessage(),
                    ]);
                }
            });
        }

        foreach ($outcome['marketing_campaigns'] as $campaignId) {
            try {
                CampaignService::syncCounters((int) $campaignId);
            } catch (\Throwable $e) {
                Logger::warning('Account deletion: campaign counters could not be recomputed', [
                    'deletion_id' => $deletionId,
                    'campaign_id' => (int) $campaignId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        foreach ($outcome['applicants'] as $applicant) {
            try {
                // The campaign page is gone, so the notification links nowhere.
                NotificationService::send(
                    (int) $applicant['creator_user_id'],
                    'campaign.cancelled',
                    ['campaign' => (string) $applicant['title']],
                    ['action_type' => 'none']
                );
            } catch (\Throwable $e) {
                Logger::warning('Account deletion: an applicant could not be told the campaign closed', [
                    'deletion_id' => $deletionId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return [
            'deletion_id'     => $deletionId,
            'already_deleted' => false,
            'erased'          => $outcome['erased'],
            'retained'        => $outcome['retained'],
            'notice'          => $outcome['notice'],
            'forfeited'       => $outcome['forfeited'],
        ];
    }

    /** Write the activity-log row for an erasure. Never throws. */
    private static function audit(
        string $mode,
        int $userId,
        int $deletionId,
        string $verifiedVia,
        ?string $reasonCode,
        string $accountType,
        ?int $adminId,
        ?string $via
    ): void {
        $properties = [
            'deletion_id'  => $deletionId,
            'verified_via' => $verifiedVia,
            'reason_code'  => $reasonCode,
            'account_type' => $accountType,
        ];

        try {
            match ($mode) {
                'self' => ActivityLogger::log(
                    'account.deleted', 'user', $userId, 'Account deleted by its owner.', $properties, 'user', $userId, 'Deleted user'
                ),
                'operator' => ActivityLogger::log(
                    'account.deleted',
                    'user',
                    $userId,
                    'Account erased by support at the user\'s request.',
                    $properties + ['via' => $via],
                    'admin',
                    $adminId,
                    $adminId === null ? null : (string) (Database::scalar('SELECT `name` FROM `admins` WHERE `id` = :id', ['id' => $adminId]) ?? '')
                ),
                'legacy' => ActivityLogger::log(
                    'account.deleted', 'user', $userId, 'Account erased: it was closed before erasure existed.', $properties, 'system'
                ),
                default => ActivityLogger::log(
                    'account.erasure_reapplied', 'user', $userId, 'Erasure re-applied after a database restore.', $properties, 'system'
                ),
            };
        } catch (\Throwable $e) {
            Logger::error('Account deletion: the audit row could not be written', ['deletion_id' => $deletionId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The creator profile and everything hanging off it.
     *
     * The `creators` row is anonymised rather than deleted: bookings and
     * reviews reference it, and bookings.creator_id cascades, so a hard
     * delete would take retained financial records with it.
     *
     * @return array<string,int>
     */
    private static function eraseCreator(int $userId, int $creatorId, string $now): array
    {
        $c      = ['cid' => $creatorId];
        $erased = [];

        // Read before the testimonials go, so their edit history can follow.
        $testimonialIds = array_map('intval', Database::column(
            'SELECT `id` FROM `testimonials` WHERE `creator_id` = :cid',
            $c
        ));

        $tables = [
            'creator_portfolio', 'creator_packages', 'creator_social_accounts', 'creator_credentials',
            'creator_experience', 'creator_faqs', 'creator_working_hours', 'creator_availability',
            'creator_categories', 'creator_profile_terms', 'creator_handle_history', 'creator_profile_completion',
            'creator_profile_locks', 'creator_feature_overrides', 'creator_change_requests', 'creator_badges',
            'creator_badge_events', 'creator_profile_views', 'creator_engagement_events', 'testimonials',
            'favorites', 'follows',
        ];

        foreach ($tables as $table) {
            $erased[$table === 'favorites' || $table === 'follows' ? "saved_by_others_{$table}" : $table]
                = Database::execute("DELETE FROM `{$table}` WHERE `creator_id` = :cid", $c);
        }

        // The profile's edit history holds full snapshots (address, date of
        // birth). 'creator' is CreatorChangeService::REVISION_ENTITY.
        $erased['revisions'] = Database::execute(
            "DELETE FROM `revisions` WHERE `entity_type` = 'creator' AND `entity_id` = :cid",
            $c
        );

        if ($testimonialIds !== []) {
            [$in, $bindings] = self::inList('tm', $testimonialIds);
            $erased['revisions'] += Database::execute(
                "DELETE FROM `revisions` WHERE `entity_type` = 'testimonials' AND `entity_id` IN ({$in})",
                $bindings
            );
        }

        // Applications that became bookings stay as the link between the
        // campaign and the booking, without the pitch. The rest go, and the
        // campaigns' counters are recomputed so brands see true numbers.
        $campaignIds = array_map('intval', array_column(Database::select(
            'SELECT DISTINCT `campaign_id` FROM `campaign_applications` WHERE `creator_user_id` = :id',
            ['id' => $userId]
        ), 'campaign_id'));

        Database::execute(
            'UPDATE `campaign_applications` SET `pitch` = NULL, `portfolio_links` = NULL
              WHERE `creator_user_id` = :id AND `booking_id` IS NOT NULL',
            ['id' => $userId]
        );
        $erased['campaign_applications'] = Database::execute(
            'DELETE FROM `campaign_applications` WHERE `creator_user_id` = :id AND `booking_id` IS NULL',
            ['id' => $userId]
        );

        foreach ($campaignIds as $campaignId) {
            Database::execute(
                'UPDATE `campaigns` SET `applications_count` = (
                    SELECT COUNT(*) FROM `campaign_applications` a
                     WHERE a.`campaign_id` = :campaign AND a.`deleted_at` IS NULL
                 ) WHERE `id` = :campaign2',
                ['campaign' => $campaignId, 'campaign2' => $campaignId]
            );
        }

        Database::update('creators', [
            'handle'                    => 'deleted-' . $creatorId,
            'display_name'              => 'Deleted creator',
            'headline'                  => null,
            'about'                     => null,
            'cover_image_url'           => null,
            'intro_video_url'           => null,
            'intro_video_thumbnail_url' => null,
            'media_kit_url'             => null,
            'resume_url'                => null,
            'city'                      => null,
            'state'                     => null,
            'address_line1'             => null,
            'address_line2'             => null,
            'pin_code'                  => null,
            'address_is_public'         => 0,
            'gender'                    => null,
            'date_of_birth'             => null,
            'languages'                 => null,
            'is_public'                 => 0,
            'is_featured'               => 0,
            'featured_until'            => null,
            'availability_status'       => 'unavailable',
            'meta_title'                => null,
            'meta_description'          => null,
            'deleted_at'                => $now,
        ], ['id' => $creatorId]);

        $erased['creator_profile'] = 1;

        return $erased;
    }

    /**
     * A brand's campaigns: the ones still open or in draft are cancelled and
     * every campaign leaves public view. The rows stay, because bookings
     * reference the campaign they came from.
     *
     * The creators whose applications this closes are handed back through
     * [$applicants], so each is notified once the erasure has committed.
     *
     * @param  list<array{creator_user_id:int|string,title:string}> $applicants
     * @return array<string,int>
     */
    private static function closeCampaigns(int $userId, string $now, array &$applicants): array
    {
        $p = ['id' => $userId];

        $applicants = Database::select(
            "SELECT DISTINCT a.`creator_user_id`, c.`title`
               FROM `campaign_applications` a
               JOIN `campaigns` c ON c.`id` = a.`campaign_id`
              WHERE c.`brand_user_id` = :id
                AND a.`status` IN ('invited', 'applied', 'shortlisted')
                AND a.`deleted_at` IS NULL",
            $p
        );

        Database::execute(
            "UPDATE `campaign_applications` a
               JOIN `campaigns` c ON c.`id` = a.`campaign_id`
                SET a.`status` = 'declined', a.`decline_reason` = 'This campaign has closed.', a.`responded_at` = :now
              WHERE c.`brand_user_id` = :id AND a.`status` IN ('invited', 'applied', 'shortlisted')",
            ['id' => $userId, 'now' => $now]
        );

        $closed = Database::execute(
            "UPDATE `campaigns` SET `status` = 'cancelled' WHERE `brand_user_id` = :id AND `status` IN ('draft', 'open')",
            $p
        );

        Database::execute(
            'UPDATE `campaigns` SET `is_public` = 0, `deleted_at` = COALESCE(`deleted_at`, :now) WHERE `brand_user_id` = :id',
            ['id' => $userId, 'now' => $now]
        );

        return ['campaigns_closed' => $closed, 'campaign_applicants_notified' => count($applicants)];
    }

    /**
     * Marketing-campaign rows for this person: unsent ones go, so a sending,
     * paused or retried campaign cannot mail the address again; sent ones
     * stay for the campaign's totals with nothing that identifies anyone
     * (a NULL token turns open and click tracking into a no-op).
     *
     * @param  list<string>      $emails
     * @param  list<string>      $phones
     * @param  array<string,int> $erased
     * @return list<int> The campaigns whose counters need recomputing.
     */
    private static function eraseCampaignRecipients(int $userId, array $emails, array $phones, array &$erased): array
    {
        [$in, $bindings] = self::inList('cr', array_merge($emails, $phones, ['user:' . $userId]));
        $where           = "(`user_id` = :uid OR `destination` IN ({$in}))";
        $bindings       += ['uid' => $userId];

        $campaigns = array_map('intval', Database::column(
            "SELECT DISTINCT `campaign_id` FROM `campaign_recipients` WHERE {$where}",
            $bindings
        ));

        $erased['campaign_recipients_removed'] = Database::execute(
            "DELETE FROM `campaign_recipients` WHERE `status` IN ('pending', 'failed') AND {$where}",
            $bindings
        );

        $erased['campaign_recipients_anonymised'] = Database::execute(
            "UPDATE `campaign_recipients`
                SET `user_id` = NULL, `destination` = CONCAT('deleted:', `id`), `tracking_token` = NULL, `error_message` = NULL
              WHERE {$where}",
            $bindings
        );

        return $campaigns;
    }

    /**
     * The deleted person's name in other people's notifications about them:
     * a booking, a review, a conversation or a campaign they shared. Scoped
     * to those, so somebody else who happens to share the name is never
     * renamed.
     *
     * @param list<array{0:string,1:string}>                                   $names     [name, replacement], longest first
     * @param array{bookings:list<int>,conversations:list<int>,campaigns:list<int>} $relations
     */
    private static function renameInOthersNotifications(int $userId, array $names, array $relations): int
    {
        if ($names === []) {
            return 0;
        }

        $clauses  = [];
        $bindings = ['uid' => $userId];

        if ($relations['bookings'] !== []) {
            [$in, $b]  = self::inList('nb', array_map('strval', $relations['bookings']));
            $clauses[] = "(`action_type` IN ('booking', 'review') AND `action_value` IN ({$in}))";
            $bindings += $b;
        }

        if ($relations['conversations'] !== []) {
            [$in, $b]  = self::inList('nc', array_map('strval', $relations['conversations']));
            $clauses[] = "(`action_type` = 'chat' AND `action_value` IN ({$in}))";
            $bindings += $b;
        }

        if ($relations['campaigns'] !== []) {
            $links = [];

            foreach ($relations['campaigns'] as $campaignId) {
                $links[] = '/campaigns/' . $campaignId;
                $links[] = '/opportunities/' . $campaignId;
            }

            [$in, $b]  = self::inList('nu', $links);
            $clauses[] = "(`action_type` = 'url' AND `action_value` IN ({$in}))";
            $bindings += $b;
        }

        if ($clauses === []) {
            return 0;
        }

        $rows = Database::select(
            'SELECT `id`, `title`, `body` FROM `notifications`
              WHERE `user_id` <> :uid AND (' . implode(' OR ', $clauses) . ')',
            $bindings
        );

        $changed = 0;

        foreach ($rows as $row) {
            $title = self::withoutNames((string) $row['title'], $names);
            $body  = self::withoutNames((string) $row['body'], $names);

            if ($title === (string) $row['title'] && $body === (string) $row['body']) {
                continue;
            }

            Database::update('notifications', [
                'title' => mb_substr($title, 0, 191),
                'body'  => mb_substr($body, 0, 1000),
            ], ['id' => (int) $row['id']]);

            $changed++;
        }

        return $changed;
    }

    /**
     * Replace whole-word occurrences of each name in one pass, so a
     * replacement is never itself rewritten by a shorter name.
     *
     * @param list<array{0:string,1:string}> $names
     */
    private static function withoutNames(string $text, array $names): string
    {
        $map         = [];
        $alternation = [];

        foreach ($names as [$name, $replacement]) {
            $map[mb_strtolower($name)] = $replacement;
            $alternation[]             = preg_quote($name, '/');
        }

        $result = preg_replace_callback(
            '/(?<![\p{L}\p{N}_])(' . implode('|', $alternation) . ')(?![\p{L}\p{N}_])/iu',
            static fn (array $match): string => $map[mb_strtolower($match[1])] ?? 'Deleted user',
            $text
        );

        return $result ?? $text;
    }

    /**
     * Payment-provider payloads keep the payer's email, phone and UPI ID.
     * The ids, statuses, amounts and error codes stay.
     */
    private static function scrubGatewayPayloads(int $userId): int
    {
        $scrubbed = 0;

        foreach (['payments', 'refunds'] as $table) {
            foreach (Database::select(
                "SELECT `id`, `gateway_response` FROM `{$table}` WHERE `user_id` = :id AND `gateway_response` IS NOT NULL",
                ['id' => $userId]
            ) as $row) {
                $decoded = json_decode((string) $row['gateway_response'], true);

                if (!is_array($decoded)) {
                    continue;
                }

                $clean = PaymentService::scrubGatewayResponse($decoded);

                if ($clean === $decoded) {
                    continue;
                }

                Database::update($table, [
                    'gateway_response' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], ['id' => (int) $row['id']]);

                $scrubbed++;
            }
        }

        return $scrubbed;
    }

    /**
     * Contact-form messages they sent keep what was written, for the support
     * record, but lose the name, address, phone and IP. The email column is
     * NOT NULL, hence ''.
     *
     * @param list<string> $emails
     * @param list<string> $phones
     */
    private static function scrubContactMessages(array $emails, array $phones): int
    {
        $clauses  = [];
        $bindings = [];

        if ($emails !== []) {
            [$in, $b]  = self::inList('cme', $emails);
            $clauses[] = "`email` IN ({$in})";
            $bindings += $b;
        }

        $tails = [];

        foreach ($phones as $phone) {
            $digits = preg_replace('/\D/', '', $phone) ?? '';

            if (strlen($digits) >= 8) {
                $tails[substr($digits, -10)] = true;
            }
        }

        if ($tails !== []) {
            [$in, $b]  = self::inList('cmp', array_map('strval', array_keys($tails)));
            $clauses[] = "(`phone` IS NOT NULL AND RIGHT(REGEXP_REPLACE(`phone`, '[^0-9]', ''), 10) IN ({$in}))";
            $bindings += $b;
        }

        if ($clauses === []) {
            return 0;
        }

        return Database::execute(
            "UPDATE `contact_messages` SET `name` = 'Deleted user', `email` = '', `phone` = NULL, `ip_address` = NULL
              WHERE " . implode(' OR ', $clauses),
            $bindings
        );
    }

    /**
     * Kept financial records keep what the law needs, as issued: invoices
     * keep the billing name, address and GSTIN printed on them, payout
     * snapshots the holder, bank and last digits. The email copied onto an
     * invoice (never printed on it), the full UPI ID of a payout and the
     * UPI ID of a payment go.
     *
     * @return array<string,int>
     */
    private static function minimiseFinancial(int $userId): array
    {
        $p = ['id' => $userId];

        $invoices = Database::execute(
            'UPDATE `invoices` SET `bill_to_email` = NULL WHERE `user_id` = :id AND `bill_to_email` IS NOT NULL',
            $p
        );

        $snapshots = 0;

        // Blockers guarantee no payout is pending, approved or processing.
        foreach (Database::select(
            'SELECT `id`, `snapshot` FROM `withdrawal_requests` WHERE `user_id` = :id AND `snapshot` IS NOT NULL',
            $p
        ) as $row) {
            $snapshot = json_decode((string) $row['snapshot'], true);

            if (!is_array($snapshot)) {
                continue;
            }

            $upi = trim((string) ($snapshot['upi_id'] ?? ''));

            if ($upi === '' || str_contains($upi, '*')) {
                continue;
            }

            $snapshot['upi_id'] = Str::maskEmail($upi);

            Database::update('withdrawal_requests', [
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], ['id' => (int) $row['id']]);

            $snapshots++;
        }

        $payments = Database::execute(
            'UPDATE `payments` SET `upi_vpa` = NULL WHERE `user_id` = :id AND `upi_vpa` IS NOT NULL',
            $p
        );

        return ['invoice_emails' => $invoices, 'payout_upi_ids' => $snapshots, 'payment_upi_ids' => $payments];
    }

    // -----------------------------------------------------------------
    // Who the person is
    // -----------------------------------------------------------------

    /**
     * Every email address and phone number this account used: the current
     * ones, its other contact points, the old and new values of its contact
     * changes, where its verification codes went and what it signed in
     * with. Emails are lower-cased; phones are kept as stored and in E.164.
     *
     * An address another live account uses now, or that another person has
     * verified as theirs, is left out, so nobody else's mail is touched.
     *
     * @param  array<string,mixed> $user The locked users row.
     * @return array{emails:list<string>,phones:list<string>}
     */
    private static function contactPointsOf(int $userId, array $user): array
    {
        $emails      = [];
        $phones      = [];
        $countryCode = (string) ($user['phone_country_code'] ?? '');

        $add = static function (mixed $value, ?string $code = null) use (&$emails, &$phones): void {
            $value = trim((string) $value);

            // Masked values are not addresses.
            if ($value === '' || str_contains($value, '*')) {
                return;
            }

            if (str_contains($value, '@')) {
                $emails[mb_strtolower($value)] = true;

                return;
            }

            $digits = preg_replace('/\D/', '', $value) ?? '';

            if (strlen($digits) < 6) {
                return;
            }

            $phones[$value] = true;

            $normalised = Str::normalizePhone(
                (!str_starts_with($value, '+') && $code !== null ? $code : '') . $value
            );

            if ($normalised !== '') {
                $phones[$normalised] = true;
            }
        };

        $add($user['email'] ?? '');
        $add($user['phone'] ?? '', $countryCode);

        foreach (Database::select(
            'SELECT `channel`, `value`, `phone_country_code` FROM `user_contact_points` WHERE `user_id` = :id',
            ['id' => $userId]
        ) as $row) {
            $add($row['value'], $row['channel'] === 'phone' ? (string) ($row['phone_country_code'] ?? $countryCode) : null);
        }

        foreach (Database::select(
            'SELECT `channel`, `current_value`, `new_value`, `new_phone_country_code` FROM `contact_change_requests` WHERE `user_id` = :id',
            ['id' => $userId]
        ) as $row) {
            $isPhone = $row['channel'] === 'phone';
            $add($row['new_value'], $isPhone ? (string) ($row['new_phone_country_code'] ?? $countryCode) : null);
            $add($row['current_value'], $isPhone ? $countryCode : null);
        }

        foreach (Database::column(
            'SELECT DISTINCT `destination` FROM `contact_verification_events` WHERE `user_id` = :id AND `destination` IS NOT NULL',
            ['id' => $userId]
        ) as $destination) {
            $add($destination);
        }

        // An identifier an earlier erasure already anonymised ("deleted-user-N")
        // is no address, and its digits are no phone number.
        foreach (Database::column(
            "SELECT DISTINCT `identifier` FROM `login_history`
              WHERE `actor_type` = 'user' AND `actor_id` = :id AND `identifier` NOT LIKE 'deleted-user-%'",
            ['id' => $userId]
        ) as $identifier) {
            $add($identifier);
        }

        if ($emails !== []) {
            [$in, $b] = self::inList('xe', array_keys($emails));

            foreach (Database::column(
                "SELECT LOWER(`email_active`) FROM `users` WHERE `id` <> :uid AND `email_active` IN ({$in})
                 UNION
                 SELECT LOWER(`value`) FROM `user_contact_points`
                  WHERE `user_id` <> :uid AND `channel` = 'email' AND `verified_at` IS NOT NULL AND `deleted_at` IS NULL
                    AND `value` IN ({$in})",
                ['uid' => $userId] + $b
            ) as $taken) {
                unset($emails[(string) $taken]);
            }
        }

        if ($phones !== []) {
            [$in, $b] = self::inList('xp', array_map('strval', array_keys($phones)));

            foreach (Database::column(
                "SELECT `phone_active` FROM `users` WHERE `id` <> :uid AND `phone_active` IN ({$in})
                 UNION
                 SELECT `phone` FROM `users` WHERE `id` <> :uid AND `deleted_at` IS NULL AND `phone` IN ({$in})
                 UNION
                 SELECT CONCAT(COALESCE(`phone_country_code`, ''), `value`) FROM `user_contact_points`
                  WHERE `user_id` <> :uid AND `channel` = 'phone' AND `verified_at` IS NOT NULL AND `deleted_at` IS NULL
                    AND (`value` IN ({$in}) OR CONCAT(COALESCE(`phone_country_code`, ''), `value`) IN ({$in}))
                 UNION
                 SELECT `value` FROM `user_contact_points`
                  WHERE `user_id` <> :uid AND `channel` = 'phone' AND `verified_at` IS NOT NULL AND `deleted_at` IS NULL
                    AND (`value` IN ({$in}) OR CONCAT(COALESCE(`phone_country_code`, ''), `value`) IN ({$in}))",
                ['uid' => $userId] + $b
            ) as $taken) {
                unset($phones[(string) $taken]);
            }
        }

        return [
            'emails' => array_map('strval', array_keys($emails)),
            'phones' => array_map('strval', array_keys($phones)),
        ];
    }

    /**
     * The names other people's notifications may carry, each with what it
     * becomes: at least 3 characters, longest first. A creator's display
     * name wins when it is also their account name.
     *
     * @param  array<string,mixed> $user
     * @return list<array{0:string,1:string}>
     */
    private static function namesOf(array $user, string $displayName): array
    {
        $names = [];

        foreach ([
            [$displayName, 'Deleted creator'],
            [(string) ($user['name'] ?? ''), 'Deleted user'],
            [(string) ($user['company_name'] ?? ''), 'Deleted user'],
        ] as [$name, $replacement]) {
            $name = trim($name);
            $key  = mb_strtolower($name);

            if (mb_strlen($name) < 3 || isset($names[$key]) || in_array($key, ['deleted user', 'deleted creator'], true)) {
                continue;
            }

            $names[$key] = [$name, $replacement];
        }

        $names = array_values($names);
        usort($names, static fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return $names;
    }

    /**
     * The bookings, conversations and campaigns this person shared with
     * others, read before the erasure removes the applications.
     *
     * @return array{bookings:list<int>,conversations:list<int>,campaigns:list<int>}
     */
    private static function relationsOf(int $userId): array
    {
        $p = ['id' => $userId];

        return [
            'bookings'      => array_map('intval', Database::column(
                'SELECT `id` FROM `bookings` WHERE `brand_user_id` = :id OR `creator_user_id` = :id',
                $p
            )),
            'conversations' => array_map('intval', Database::column(
                'SELECT `conversation_id` FROM `conversation_participants` WHERE `user_id` = :id',
                $p
            )),
            'campaigns'     => array_values(array_unique(array_map('intval', array_merge(
                Database::column('SELECT `id` FROM `campaigns` WHERE `brand_user_id` = :id', $p),
                Database::column('SELECT DISTINCT `campaign_id` FROM `campaign_applications` WHERE `creator_user_id` = :id', $p)
            )))),
        ];
    }

    /**
     * The uploads that belong to this person alone: media rows they
     * uploaded outside the retained folders, plus profile files referenced
     * only by URL (older uploads predate the media table), as paths under
     * the uploads root.
     *
     * @param  array<string,mixed> $user The locked users row.
     * @return array{media:list<int>,paths:list<string>}
     */
    private static function personalFiles(int $userId, int $creatorId, array $user): array
    {
        $retained = "'" . implode("','", self::RETAINED_MEDIA_FOLDERS) . "'";

        $media = array_map('intval', array_column(Database::select(
            "SELECT `id` FROM `media`
              WHERE `uploader_type` = 'user' AND `uploader_id` = :id AND `folder` NOT IN ({$retained})",
            ['id' => $userId]
        ), 'id'));

        $urls = [(string) ($user['avatar_url'] ?? '')];

        if ($creatorId > 0) {
            $creator = Database::selectOne(
                'SELECT `cover_image_url`, `intro_video_url`, `intro_video_thumbnail_url`, `media_kit_url`, `resume_url`
                   FROM `creators` WHERE `id` = :cid',
                ['cid' => $creatorId]
            ) ?? [];

            $urls = array_merge($urls, array_map('strval', array_values($creator)));

            foreach (Database::select(
                'SELECT `media_url`, `thumbnail_url` FROM `creator_portfolio` WHERE `creator_id` = :cid',
                ['cid' => $creatorId]
            ) as $item) {
                $urls[] = (string) $item['media_url'];
                $urls[] = (string) $item['thumbnail_url'];
            }
        }

        $paths = [];

        foreach ($urls as $url) {
            $relative = self::uploadPathOf($url);

            if ($relative !== null) {
                $paths[$relative] = true;
            }
        }

        return ['media' => $media, 'paths' => array_map('strval', array_keys($paths))];
    }

    /**
     * The path under the uploads root of one of this server's own uploads,
     * or null. Seeded and third-party media (picsum, YouTube thumbnails) is
     * not ours to delete, and files in the retained folders are kept.
     */
    private static function uploadPathOf(string $url): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        $path   = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $prefix = rtrim((string) Config::get('storage.upload_url'), '/') . '/';

        if ($path === '' || !str_starts_with($path, $prefix)) {
            return null;
        }

        $relative = ltrim(substr($path, strlen($prefix)), '/');
        $folder   = strtok($relative, '/');

        if ($relative === '' || str_contains($relative, '..') || $folder === false
            || in_array($folder, self::RETAINED_MEDIA_FOLDERS, true)) {
            return null;
        }

        return $relative;
    }

    /** @param array<string,mixed> $row */
    private static function isErasedTombstone(array $row): bool
    {
        return (string) ($row['status'] ?? '') === 'deleted'
            && (string) ($row['name'] ?? '') === 'Deleted user'
            && ($row['email'] ?? null) === null;
    }

    // -----------------------------------------------------------------
    // The goodbye
    // -----------------------------------------------------------------

    /**
     * Queue the confirmation, inside the erasure, to the address the account
     * had and to the one it had before a recent change, or by text message
     * when it only has a phone number and SMS is on.
     *
     * @param  array<string,mixed>                     $user     The locked users row.
     * @param  array<string,int>                       $retained
     * @param  array{amount:float,currency:string}|null $forfeited
     * @return array{channel:string,destination:string}|null Where it went, masked.
     */
    private static function queueGoodbye(array $user, ?string $previousEmail, array $retained, ?array $forfeited, string $verifiedVia): ?array
    {
        $message = self::goodbyeMessage($retained, $forfeited['amount'] ?? null, $verifiedVia, $forfeited['currency'] ?? null);
        $email   = trim((string) ($user['email'] ?? ''));
        $notice  = null;

        if ($email !== ''
            && MailService::queue($email, '', $message['subject'], $message['html'], $message['text'], $message['reply_to']) > 0) {
            $notice = ['channel' => 'email', 'destination' => Str::maskEmail($email)];
        }

        if ($previousEmail !== null && $previousEmail !== '' && mb_strtolower($previousEmail) !== mb_strtolower($email)) {
            $queued = MailService::queue($previousEmail, '', $message['subject'], $message['html'], $message['text'], $message['reply_to']);

            if ($notice === null && $queued > 0) {
                $notice = ['channel' => 'email', 'destination' => Str::maskEmail($previousEmail)];
            }
        }

        $phone = trim((string) ($user['phone'] ?? ''));

        if ($notice === null && $email === '' && $phone !== '') {
            $support = trim(Config::settingString('general', 'support_email', ''));

            // SmsService::queue() returns 0 when SMS is switched off.
            $queued = SmsService::queue(
                (string) ($user['phone_country_code'] ?? '') . $phone,
                'Zusty: your account has been deleted and your personal data erased. If this wasn\'t you, contact '
                    . ($support !== '' ? $support : 'us through the Contact page on our website') . '.'
            );

            if ($queued > 0) {
                $notice = [
                    'channel'     => 'sms',
                    'destination' => trim((string) ($user['phone_country_code'] ?? '') . ' ' . Str::maskPhone($phone)),
                ];
            }
        }

        return $notice;
    }

    /**
     * The goodbye email. Pure, so it can be checked without a mail server.
     *
     * It goes from a no-reply address, so it never asks to be replied to:
     * the support address is in the text and in Reply-To.
     *
     * @param  array<string,int> $kept      retainedCounts(): bookings and invoices are named.
     * @param  string            $via       How the deletion was confirmed; 'operator' says support did it.
     * @return array{subject:string,html:string,text:string,reply_to:?string}
     */
    public static function goodbyeMessage(array $kept, ?float $forfeited, string $via, ?string $currency = null): array
    {
        $support = trim(Config::settingString('general', 'support_email', ''));

        $records  = [];
        $bookings = (int) ($kept['bookings'] ?? 0);
        $invoices = (int) ($kept['invoices'] ?? 0);

        if ($bookings > 0) {
            $records[] = $bookings . ' booking' . ($bookings === 1 ? '' : 's');
        }

        if ($invoices > 0) {
            $records[] = $invoices . ' invoice' . ($invoices === 1 ? '' : 's');
        }

        $body = 'Your Zusty account has been deleted, and your profile, contact details, saved items and sign-in '
            . 'methods have been erased. You have been signed out on every device. '
            . ($records !== []
                ? 'As the law requires, we keep the financial records of your ' . implode(' and ', $records)
                  . ', showing "Deleted user" in place of your account. '
                  . ($invoices > 0 ? 'Invoices stay exactly as they were issued. ' : '')
                : '')
            . ($forfeited !== null && $forfeited > 0
                ? 'You gave up ' . Money::format($forfeited, $currency) . ' that was in your wallet. '
                : '')
            . ($via === 'operator' ? 'Our support team deleted your account at your request. ' : '')
            . ($support !== ''
                ? 'If this wasn\'t you, contact us straight away at ' . $support . '.'
                : 'If this wasn\'t you, contact us straight away through the Contact page on our website.');

        return [
            'subject'  => 'Your Zusty account has been deleted',
            'html'     => MailService::renderDefault('Your account has been deleted', $body),
            'text'     => $body,
            'reply_to' => $support !== '' ? $support : null,
        ];
    }

    // -----------------------------------------------------------------
    // After the commit: clean-up that is retried until it succeeds
    // -----------------------------------------------------------------

    /**
     * Retry every deletion whose clean-up is due. Run by
     * privacy:finish-deletions. Each row is claimed with a conditional
     * update, so two runs never work on the same one; it never gives up,
     * and backs off to once a day.
     *
     * @return array{due:int,completed:int,pending:int,needs_attention:int}
     */
    public static function finishPending(int $limit = 25): array
    {
        $limit = max(1, min(500, $limit));

        $ids = array_map('intval', Database::column(
            "SELECT `id` FROM `account_deletion_requests`
              WHERE `completed_at` IS NULL AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= NOW())
              ORDER BY `next_attempt_at` ASC, `id` ASC
              LIMIT {$limit}"
        ));

        $result = ['due' => count($ids), 'completed' => 0, 'pending' => 0, 'needs_attention' => 0];

        foreach ($ids as $id) {
            $claimed = Database::execute(
                'UPDATE `account_deletion_requests` SET `next_attempt_at` = NOW() + INTERVAL 15 MINUTE
                  WHERE `id` = :id AND `completed_at` IS NULL AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= NOW())',
                ['id' => $id]
            );

            if ($claimed !== 1) {
                continue;
            }

            try {
                $state = self::runPendingSteps($id);
            } catch (\Throwable $e) {
                Logger::error('Account deletion: a clean-up retry failed', ['deletion_id' => $id, 'error' => $e->getMessage()]);
                $state = 'pending';
            }

            match ($state) {
                'completed'       => $result['completed']++,
                'needs_attention' => $result['needs_attention']++,
                default           => $result['pending']++,
            };
        }

        return $result;
    }

    /**
     * Run the clean-up steps still pending on one deletion: all of them, or
     * only those in [$only]. Every step is idempotent. Failures stay pending
     * with a back-off; the first one is recorded, without personal data.
     *
     * @param  list<string>|null $only
     * @return string 'completed' | 'pending' | 'needs_attention' | 'missing'
     */
    private static function runPendingSteps(int $deletionId, ?array $only = null): string
    {
        $row = Database::selectOne(
            'SELECT `id`, `user_id`, `pending_steps`, `pending_files`, `firebase_uid_enc`, `attempts`, `completed_at`
               FROM `account_deletion_requests` WHERE `id` = :id',
            ['id' => $deletionId]
        );

        if ($row === null) {
            return 'missing';
        }

        if ($row['completed_at'] !== null) {
            return 'completed';
        }

        $userId  = (int) $row['user_id'];
        $decoded = json_decode((string) ($row['pending_steps'] ?? ''), true);
        $steps   = array_values(array_intersect(self::STEPS, is_array($decoded) ? $decoded : []));
        $run     = $only === null ? $steps : array_values(array_intersect($steps, $only));

        $attempts = (int) $row['attempts'];

        if ($steps !== [] && $run === []) {
            return $attempts >= self::ATTENTION_AFTER ? 'needs_attention' : 'pending';
        }

        $files = json_decode((string) ($row['pending_files'] ?? ''), true);
        $files = [
            'media' => is_array($files['media'] ?? null) ? array_map('intval', $files['media']) : [],
            'paths' => is_array($files['paths'] ?? null) ? array_map('strval', $files['paths']) : [],
        ];

        $done     = [];
        $failures = [];

        foreach ($run as $step) {
            try {
                $error = match ($step) {
                    'files'    => self::purgeFiles($files),
                    'exports'  => self::purgeExports($userId),
                    'kyc'      => self::purgeKyc($userId),
                    'firebase' => self::deleteFirebaseUser((string) ($row['firebase_uid_enc'] ?? '')),
                };
            } catch (\Throwable $e) {
                $error = self::safeError($step, $e->getMessage());
            }

            if ($error === null) {
                $done[] = $step;
            } else {
                $failures[$step] = $error;
            }
        }

        $remaining = array_values(array_diff($steps, $done));

        if ($remaining === []) {
            Database::execute(
                'UPDATE `account_deletion_requests`
                    SET `completed_at` = NOW(), `pending_steps` = NULL, `pending_files` = NULL,
                        `firebase_uid_enc` = NULL, `last_error` = NULL, `next_attempt_at` = NULL
                  WHERE `id` = :id',
                ['id' => $deletionId]
            );

            return 'completed';
        }

        $update = [
            'pending_steps' => json_encode($remaining),
            'pending_files' => in_array('files', $remaining, true) ? json_encode($files, JSON_UNESCAPED_SLASHES) : null,
        ];

        if (in_array('firebase', $done, true)) {
            $update['firebase_uid_enc'] = null;
        }

        if ($failures !== []) {
            $attempts++;

            $update['attempts']        = min(65535, $attempts);
            $update['next_attempt_at'] = date('Y-m-d H:i:s', time() + 60 * ($attempts >= self::ATTENTION_AFTER ? 1440 : min(1440, 10 * (2 ** $attempts))));
            $update['last_error']      = mb_substr((string) reset($failures), 0, 500);

            foreach ($failures as $step => $error) {
                Logger::log(
                    $attempts >= self::ATTENTION_AFTER ? Logger::ERROR : Logger::WARNING,
                    'Account deletion: a clean-up step is still pending',
                    ['deletion_id' => $deletionId, 'step' => $step, 'attempts' => $attempts, 'error' => $error]
                );
            }
        }

        Database::update('account_deletion_requests', $update, ['id' => $deletionId]);

        return $attempts >= self::ATTENTION_AFTER ? 'needs_attention' : 'pending';
    }

    /**
     * Delete the person's files. A media row goes only once its file has;
     * a failure is written back to [$files] to try again.
     *
     * @param array{media:list<int>,paths:list<string>} $files
     */
    private static function purgeFiles(array &$files): ?string
    {
        $failedMedia = [];

        foreach ($files['media'] as $mediaId) {
            try {
                if (!UploadService::purgeFile($mediaId)) {
                    $failedMedia[] = $mediaId;
                }
            } catch (\Throwable $e) {
                $failedMedia[] = $mediaId;
                Logger::warning('Account deletion: a media file could not be removed', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
            }
        }

        $failedPaths = [];
        $root        = realpath(rtrim((string) Config::get('storage.upload_path'), '/\\'));

        // Without an uploads root there is nothing under it left to delete.
        if ($root !== false) {
            foreach ($files['paths'] as $relative) {
                $target = realpath($root . DIRECTORY_SEPARATOR . str_replace(['\\', '..'], ['/', ''], $relative));

                // Gone already, or not inside the uploads root: nothing of ours to delete.
                if ($target === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR) || !is_file($target)) {
                    continue;
                }

                @unlink($target);
                clearstatcache(true, $target);

                if (is_file($target)) {
                    $failedPaths[] = $relative;
                }
            }
        }

        $files = ['media' => $failedMedia, 'paths' => $failedPaths];
        $left  = count($failedMedia) + count($failedPaths);

        return $left === 0 ? null : 'files: ' . $left . ' file' . ($left === 1 ? '' : 's') . ' could not be deleted';
    }

    /** Data-export archives and their rows; done when no row is left. */
    private static function purgeExports(int $userId): ?string
    {
        DataExportService::purgeForUser($userId);

        $left = (int) Database::scalar('SELECT COUNT(*) FROM `data_export_requests` WHERE `user_id` = :id', ['id' => $userId]);

        return $left === 0 ? null : 'exports: ' . $left . ' archive' . ($left === 1 ? '' : 's') . ' could not be deleted';
    }

    /**
     * Identity documents are the KYC module's to purge under its retention
     * rules: it deletes document images and numbers and keeps the minimal
     * record the law requires. Done when nothing of the user's is unpurged.
     */
    private static function purgeKyc(int $userId): ?string
    {
        if (!method_exists(KycService::class, 'purgeForUser')) {
            return 'kyc: the identity-verification module cannot erase documents yet';
        }

        KycService::purgeForUser($userId, 'account_deleted');

        $left = (int) Database::scalar(
            'SELECT (SELECT COUNT(*) FROM `kyc_profiles` WHERE `user_id` = :id AND `purged_at` IS NULL)
                  + (SELECT COUNT(*) FROM `kyc_documents` WHERE `user_id` = :id AND `purged_at` IS NULL)',
            ['id' => $userId]
        );

        return $left === 0 ? null : 'kyc: ' . $left . ' identity record' . ($left === 1 ? '' : 's') . ' still hold data';
    }

    /**
     * Google, Apple and phone sign-ins also leave a copy of the email, name
     * and phone in Firebase Authentication. Deleting it there means a later
     * Google sign-in starts a genuinely new account. The uid is kept,
     * encrypted, only until Firebase confirms.
     */
    private static function deleteFirebaseUser(string $encryptedUid): ?string
    {
        if ($encryptedUid === '') {
            return null;
        }

        $uid = Crypto::decrypt($encryptedUid);

        if ($uid === null || trim($uid) === '') {
            return 'firebase: the stored sign-in id could not be decrypted (has the app key changed?)';
        }

        return FirebaseService::deleteAuthUser($uid) ? null : 'firebase: Firebase did not confirm the deletion';
    }

    /** A failure message with anything that looks like an address or number taken out. */
    private static function safeError(string $step, string $message): string
    {
        $message = (string) (preg_replace('/[^\s@]+@[^\s@]+/u', '[email]', $message) ?? '');
        $message = (string) (preg_replace('/\+?\d[\d\s().-]{6,}\d/', '[number]', $message) ?? '');

        return mb_substr($step . ': ' . trim($message), 0, 500);
    }

    // -----------------------------------------------------------------
    // The erasure ledger (outside the database, for restores)
    // -----------------------------------------------------------------

    /** Where the erasure ledger lives: one JSON line per erasure, no contact data. */
    public static function ledgerPath(): string
    {
        return rtrim((string) Config::get('storage.base_path'), '/\\') . DIRECTORY_SEPARATOR . 'privacy'
            . DIRECTORY_SEPARATOR . 'erasure-ledger.jsonl';
    }

    /**
     * Append one line to the ledger. A database restore cannot roll it back,
     * which is the point: {@see reapplyAfterRestore()} reads it. Only a
     * failure is logged.
     *
     * @param array<string,mixed> $entry
     */
    private static function appendLedger(array $entry): void
    {
        try {
            $path      = self::ledgerPath();
            $directory = dirname($path);

            if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new \RuntimeException('The ledger folder could not be created.');
            }

            if (!is_file($directory . DIRECTORY_SEPARATOR . '.htaccess')) {
                @file_put_contents($directory . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\nOptions -Indexes\n");
            }

            if (!is_file($directory . DIRECTORY_SEPARATOR . 'index.html')) {
                @file_put_contents($directory . DIRECTORY_SEPARATOR . 'index.html', '');
            }

            $handle = @fopen($path, 'ab');

            if ($handle === false) {
                throw new \RuntimeException('The ledger could not be opened for writing.');
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    throw new \RuntimeException('The ledger could not be locked.');
                }

                $written = fwrite($handle, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                fflush($handle);
                flock($handle, LOCK_UN);

                if ($written === false) {
                    throw new \RuntimeException('The ledger line could not be written.');
                }
            } finally {
                fclose($handle);
            }
        } catch (\Throwable $e) {
            Logger::error('Account deletion: the erasure ledger could not be written', [
                'deletion_id' => $entry['deletion_id'] ?? null,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * The newest ledger line per account.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function ledgerEntries(): array
    {
        $path = self::ledgerPath();

        if (!is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('The erasure ledger could not be opened.');
        }

        $entries = [];

        try {
            flock($handle, LOCK_SH);

            while (($line = fgets($handle)) !== false) {
                $entry = json_decode(trim($line), true);

                if (is_array($entry) && (int) ($entry['user_id'] ?? 0) > 0) {
                    $entries[(int) $entry['user_id']] = $entry;
                }
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $entries;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Named placeholders for an IN list, e.g. [':em0, :em1', ['em0' => …, 'em1' => …]].
     * An empty list gives NULL, which matches nothing.
     *
     * @param  list<int|string> $values
     * @return array{0:string,1:array<string,int|string>}
     */
    private static function inList(string $prefix, array $values): array
    {
        if ($values === []) {
            return ['NULL', []];
        }

        $names    = [];
        $bindings = [];

        foreach (array_values($values) as $i => $value) {
            $names[]                 = ':' . $prefix . $i;
            $bindings[$prefix . $i] = $value;
        }

        return [implode(', ', $names), $bindings];
    }
}

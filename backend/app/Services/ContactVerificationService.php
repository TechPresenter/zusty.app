<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\RateLimiter;
use Zuste\Helpers\Str;

/**
 * Contact points, and the proof that somebody actually holds them.
 *
 * Migration 036 built three tables and nothing read or wrote them, so the
 * platform still behaved the way that migration was written to stop:
 * AccountController::updateProfile() wrote a new email straight onto `users`
 * and blanked email_verified_at in the same UPDATE. A typo therefore cost the
 * account both its verified address and its route back — the code that would
 * fix it went to an address that did not exist.
 *
 * This service is the gate that was missing. Three rules hold everywhere in
 * it, and every other decision below follows from them:
 *
 *   1. A NEW VALUE IS A PROPOSAL, NOT A FACT. It lives in
 *      `contact_change_requests`.`new_value`. `users` and
 *      `user_contact_points` are not touched until the proposal is proved.
 *
 *   2. THE ROW NEVER CLAIMS MORE THAN HAPPENED. `verified_at` is set only
 *      when a one-time code issued for that exact request was redeemed.
 *      When an operator has switched OTP off entirely the change still
 *      applies — that is what switching the gate off means — but verified_at
 *      stays NULL and the identifier lands UNVERIFIED, because nothing proved
 *      it. An audit trail that lies is worse than no audit trail.
 *
 *   3. THE OTP ENGINE IS THE ONE IN `otp_verifications`. AuthService::sendOtp
 *      and ::verifyOtp already expire the previous live code, count wrong
 *      entries against max_attempts and stamp verified_at. Migration 036 added
 *      four members to that table's `purpose` ENUM for exactly this service.
 *      Nothing here re-implements any of it.
 *
 * What this service does NOT own: locking. `creator_profile_locks` already has
 * a 'contact' section and CreatorProfileLockService already enforces it, so
 * every path that can MOVE an identifier calls assertContactUnlocked() and
 * there is no second lock. That list is exhaustive and it matters which way
 * round it runs: an operator locks the section BECAUSE a suspicious change is
 * already in flight, so the lock has to bite on verify() and resend() — the
 * steps that finish such a change — and not only on the steps that start one.
 * cancel() is deliberately outside it: withdrawing a request is the one write
 * that can only ever reduce what a locked account is able to do.
 *
 * Locks are keyed to creators(id), so a brand user cannot be frozen that way —
 * migration 036 note 5 records that gap deliberately, and the chokepoint for a
 * brand is rejectChange(), which an admin can reach from the same screen.
 */
final class ContactVerificationService
{
    /** What an alternate contact is for. Operator-managed, not hardcoded. */
    public const TAXONOMY_LABEL = 'contact_label';

    /** Why somebody is changing an identifier. Also operator-managed. */
    public const TAXONOMY_REASON = 'contact_change_reason';

    /**
     * Which OTP purpose proves which slot.
     *
     * Four purposes rather than one shared 'change_contact', because
     * AuthService::verifyOtp() looks a code up by (destination, purpose) and
     * nothing else. If proving a new primary email and proving an alternate
     * email shared a purpose, a code issued for one could be redeemed against
     * the other for the same address. The purpose IS the scope of the code.
     *
     * @var array<string,array<string,string>>
     */
    private const PURPOSES = [
        'primary'   => ['email' => 'change_email',     'phone' => 'change_phone'],
        'alternate' => ['email' => 'verify_alt_email', 'phone' => 'verify_alt_phone'],
    ];

    /**
     * `contact_change_requests`, named column by column. NEVER `SELECT *`.
     *
     * `ip_address` is VARBINARY(16) and holds the PACKED inet_pton() form — for
     * 49.36.180.12 that is the four raw bytes 31 24 b4 0c, and 0xb4 is an
     * orphan UTF-8 continuation byte. Response::json() encodes with
     * JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES and no substitution flag,
     * so json_encode() returns FALSE on that byte and the client receives a
     * 200 with a zero-byte body. One `SELECT *` anywhere on this table
     * therefore blanks every endpoint in the module the moment a request is
     * recorded from a real client address rather than from 127.0.0.1, whose
     * packed form happens to be valid UTF-8.
     *
     * Converting at the source rather than in a mapper is deliberate: a mapper
     * is something a future reader can forget to call, whereas a row that never
     * carried the binary in the first place cannot leak it. INET6_NTOA reads
     * both families — it returns the dotted quad for the 4-byte rows and the
     * colon form for the 16-byte ones — so the audit value survives in a shape
     * a human and a JSON encoder can both read.
     */
    private const REQUEST_COLUMNS = '`id`, `user_id`, `channel`, `target`, `contact_point_id`,
               `current_value`, `new_value`, `new_phone_country_code`, `reason_code`, `reason_note`,
               `status`, `otp_id`, `attempt_count`, `expires_at`, `verified_at`, `applied_at`,
               `closed_at`, `actor_type`, `actor_id`, `actor_name`, `reviewed_by`, `review_note`,
               INET6_NTOA(`ip_address`) AS `ip_address`, `user_agent`,
               `created_at`, `updated_at`, `open_key`';

    /** `contact_verification_events`, for the same reason. See REQUEST_COLUMNS. */
    private const EVENT_COLUMNS = '`id`, `user_id`, `request_id`, `channel`, `target`, `destination`,
               `actor_type`, `actor_id`, `actor_name`, `action`, `from_status`, `to_status`,
               `note`, `metadata`, INET6_NTOA(`ip_address`) AS `ip_address`, `user_agent`, `created_at`';

    // =================================================================
    // Policy
    // =================================================================

    /**
     * Is proof of possession required at all?
     *
     * Seeded as creator.otp_verification_enabled. It sits in the `creator`
     * group because that is the settings screen an operator opens, but it
     * governs every account — a brand proves an email exactly as a creator
     * does. The default is TRUE on purpose: if the row is ever deleted the
     * platform must fall back to asking for proof, never to skipping it.
     */
    public static function otpEnabled(): bool
    {
        return Config::settingBool('creator', 'otp_verification_enabled', true);
    }

    /**
     * Can a new mobile number be proved right now?
     *
     * A number is proved with a code sent by text message. While SMS is off
     * (SmsService::enabled(), the standing decision) and proof is required,
     * no code can reach it, so a change or an additional number would be
     * opened and could never complete. policy() reports it, so the app hides
     * the buttons instead of offering a dead end, and the writes refuse it up
     * front with `sms_unavailable`. With proof switched off a change applies
     * at once and needs no text message.
     */
    public static function mobileCodesAvailable(): bool
    {
        return !self::otpEnabled() || SmsService::enabled();
    }

    /** Refuse a mobile-number write whose code could never be delivered. */
    private static function assertMobileProvable(string $channel): void
    {
        if ($channel === 'phone' && !self::mobileCodesAvailable()) {
            throw HttpException::unprocessable(
                'A new mobile number is confirmed by a text message, and text messages are '
                . 'switched off, so a number cannot be added or changed for now.',
                'sms_unavailable'
            );
        }
    }

    /**
     * Every switch and ceiling the apps and the admin screen need, resolved
     * once so the client is never left guessing why a button is disabled.
     *
     * @return array<string,mixed>
     */
    public static function policy(int $userId): array
    {
        $creatorId = self::creatorIdFor($userId);

        return [
            'otp_verification'           => self::otpEnabled(),
            'allow_email_change'         => Config::settingBool('creator', 'allow_email_change', true),
            'allow_mobile_change'        => Config::settingBool('creator', 'allow_mobile_change', true)
                && self::mobileCodesAvailable(),
            'allow_alternate_contacts'   => Config::settingBool('creator', 'allow_alternate_contacts', true),
            // False while a new number cannot be proved (SMS off, proof on):
            // no mobile change and no additional mobile number either.
            'mobile_codes_available'     => self::mobileCodesAvailable(),
            'max_alternates_per_channel' => max(0, Config::settingInt('creator', 'max_alternate_contacts_per_channel', 1)),
            'change_ttl_minutes'         => self::changeTtlMinutes(),
            'resend_seconds'             => max(0, Config::settingInt('auth', 'otp_resend_seconds', 45)),
            'max_resends'                => max(1, Config::settingInt('auth', 'otp_max_resends', 3)),
            // Reported rather than merely enforced, so the client can grey the
            // field out instead of letting somebody fill in a form the API is
            // then going to refuse.
            'contact_locked'             => $creatorId !== null
                && CreatorProfileLockService::isLocked($creatorId, 'contact'),
        ];
    }

    /**
     * How long a pending change stays open.
     *
     * security.contact_change_ttl_minutes, distinct from security.otp_ttl
     * which governs ONE code. A request has to outlive several codes, because
     * the first SMS not arriving is the ordinary case, not the exceptional one.
     */
    private static function changeTtlMinutes(): int
    {
        return max(1, Config::settingInt('security', 'contact_change_ttl_minutes', 30));
    }

    // =================================================================
    // Reading
    // =================================================================

    /**
     * The two identifiers that live on `users`, with their proof state.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function primary(int $userId): array
    {
        $user = Database::selectOne(
            'SELECT `id`, `name`, `email`, `email_verified_at`, `phone`, `phone_country_code`, `phone_verified_at`
               FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        );

        if ($user === null) {
            throw HttpException::notFound('Account not found.');
        }

        $pending = [];

        foreach (self::openRequests($userId) as $row) {
            if ($row['target'] === 'primary') {
                $pending[(string) $row['channel']] = $row;
            }
        }

        return [
            'email' => [
                'value'       => $user['email'],
                'verified'    => $user['email_verified_at'] !== null,
                'verified_at' => $user['email_verified_at'],
                'pending'     => $pending['email'] ?? null,
            ],
            'phone' => [
                'value'        => $user['phone'],
                'country_code' => $user['phone_country_code'],
                'display'      => $user['phone'] === null ? null : $user['phone_country_code'] . $user['phone'],
                'verified'     => $user['phone_verified_at'] !== null,
                'verified_at'  => $user['phone_verified_at'],
                'pending'      => $pending['phone'] ?? null,
            ],
        ];
    }

    /**
     * The alternates an account currently holds.
     *
     * Soft-deleted rows are excluded by default. They are not history — a
     * released row keeps its (user_id, channel, value) slot on
     * `uq_ucp_user_value`, which is what lets add() revive it instead of
     * failing on a unique key. The admin screen asks for them explicitly.
     *
     * @return list<array<string,mixed>>
     */
    public static function contactPoints(int $userId, bool $includeReleased = false): array
    {
        $labels = TaxonomyService::map(self::TAXONOMY_LABEL);

        // `*` is safe on THIS table and only on this table: `user_contact_points`
        // carries no binary column, and every row is rebuilt field by field
        // below before it leaves. The two tables that do hold a packed
        // ip_address are read through REQUEST_COLUMNS / EVENT_COLUMNS instead.
        $rows = Database::select(
            'SELECT * FROM `user_contact_points`
              WHERE `user_id` = :id' . ($includeReleased ? '' : ' AND `deleted_at` IS NULL') . '
              ORDER BY `channel`, `sort_order`, `id`',
            ['id' => $userId]
        );

        $pending = [];

        foreach (self::openRequests($userId) as $request) {
            if ($request['contact_point_id'] !== null) {
                $pending[(int) $request['contact_point_id']] = $request;
            }
        }

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'channel'      => $row['channel'],
                'label'        => $row['label'],
                'label_text'   => $row['label'] === null ? null : ($labels[(string) $row['label']] ?? (string) $row['label']),
                'value'        => $row['value'],
                'country_code' => $row['phone_country_code'],
                'display'      => $row['channel'] === 'phone'
                    ? ((string) $row['phone_country_code'] . (string) $row['value'])
                    : (string) $row['value'],
                'verified'     => $row['verified_at'] !== null,
                'verified_at'  => $row['verified_at'],
                'verified_via' => $row['verified_via'],
                'is_recovery'  => (bool) $row['is_recovery'],
                'released_at'  => $row['deleted_at'],
                'created_at'   => $row['created_at'],
                'pending'      => $pending[(int) $row['id']] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Requests still awaiting proof.
     *
     * @return list<array<string,mixed>>
     */
    public static function openRequests(int $userId): array
    {
        return Database::select(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM `contact_change_requests`
              WHERE `user_id` = :id AND `status` = "pending"
              ORDER BY `id` DESC',
            ['id' => $userId]
        );
    }

    /**
     * Every request, open or closed, for the admin screen.
     *
     * @return list<array<string,mixed>>
     */
    public static function requests(int $userId, int $limit = 50): array
    {
        return Database::select(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM `contact_change_requests`
              WHERE `user_id` = :id
              ORDER BY `id` DESC
              LIMIT ' . max(1, min(200, $limit)),
            ['id' => $userId]
        );
    }

    /**
     * The verification log.
     *
     * This is the only durable record of who changed an identifier and on
     * whose authority: CronRunner::purge() deletes otp_verifications rows a
     * week after they expire, so that table is a seven-day window by
     * construction and can never answer a question older than that.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $userId, int $limit = 100): array
    {
        return Database::select(
            'SELECT ' . self::EVENT_COLUMNS . ' FROM `contact_verification_events`
              WHERE `user_id` = :id
              ORDER BY `created_at` DESC, `id` DESC
              LIMIT ' . max(1, min(500, $limit)),
            ['id' => $userId]
        );
    }

    /**
     * Everything the app needs to draw its contact screen in one call.
     *
     * @return array<string,mixed>
     */
    public static function overview(int $userId): array
    {
        return [
            'primary'    => self::primary($userId),
            'alternates' => self::contactPoints($userId),
            'requests'   => self::openRequests($userId),
            'policy'     => self::policy($userId),
            'labels'     => TaxonomyService::terms(self::TAXONOMY_LABEL),
            'reasons'    => TaxonomyService::terms(self::TAXONOMY_REASON),
        ];
    }

    /**
     * The same picture plus the closed requests and the log, for an operator.
     *
     * @return array<string,mixed>
     */
    public static function adminOverview(int $userId, int $historyLimit = 100): array
    {
        return [
            'primary'    => self::primary($userId),
            'alternates' => self::contactPoints($userId, true),
            'requests'   => self::requests($userId),
            'history'    => self::history($userId, $historyLimit),
            'policy'     => self::policy($userId),
        ];
    }

    // =================================================================
    // Alternates
    // =================================================================

    /**
     * Claim an additional email address or mobile number.
     *
     * The row is inserted UNVERIFIED and immediately, before any code goes
     * out. Migration 036 suggested carrying a new alternate purely on the
     * request and inserting the row only once proved; the row goes in first
     * here for two reasons the comment-only version cannot cover:
     *
     *   * `label`, `is_recovery` and `sort_order` are facts about the contact
     *     point and `contact_change_requests` has nowhere to put them, so they
     *     would have to survive the round trip in some side channel.
     *   * `uq_ucp_user_value` reserves (user, channel, value) the moment the
     *     claim is made, so one account cannot stack three pending claims on
     *     the same address.
     *
     * An abandoned claim is harmless: `verified_key` is NULL while
     * verified_at is NULL, so an unproved row blocks nobody, anywhere.
     *
     * @param  array<string,mixed> $context label, is_recovery, reason_code,
     *                                      reason_note, actor_name, ip, user_agent
     * @return array<string,mixed>
     */
    public static function addAlternate(int $userId, string $channel, string $value, array $context = []): array
    {
        self::assertChannel($channel);
        self::assertContactUnlocked($userId);

        if (!Config::settingBool('creator', 'allow_alternate_contacts', true)) {
            throw HttpException::forbidden('Additional contact details are switched off.');
        }

        self::assertMobileProvable($channel);

        $normalised = self::normalise($channel, $value);

        $max = max(0, Config::settingInt('creator', 'max_alternate_contacts_per_channel', 1));

        if ($max === 0) {
            throw HttpException::forbidden('Additional contact details are switched off.');
        }

        self::assertNotOwnPrimary($userId, $channel, $normalised);
        self::assertAvailable($userId, $channel, $normalised);

        // A previously released row is revived rather than duplicated:
        // `uq_ucp_user_value` does not include deleted_at, so a soft-deleted
        // row still owns that slot and a plain INSERT would fail on it.
        $existing = Database::selectOne(
            'SELECT * FROM `user_contact_points`
              WHERE `user_id` = :u AND `channel` = :c AND `value` = :v',
            ['u' => $userId, 'c' => $channel, 'v' => $normalised['value']]
        );

        if ($existing !== null && $existing['deleted_at'] === null) {
            throw HttpException::conflict('You have already added that contact.', 'contact_exists');
        }

        $live = 0;

        foreach (self::contactPoints($userId) as $point) {
            if ($point['channel'] === $channel) {
                $live++;
            }
        }

        if ($live >= $max) {
            throw HttpException::unprocessable(
                $max === 1
                    ? 'You can hold one additional ' . ($channel === 'email' ? 'email address' : 'mobile number') . '. Remove it before adding another.'
                    : "You can hold at most {$max} additional contacts of this kind.",
                'alternate_limit_reached'
            );
        }

        // Refused here, before the row below is written: only one alternate per
        // channel can be awaiting proof at a time, and a claim that cannot be
        // proved must not be left behind counting against the ceiling.
        self::assertAlternateProofSlotFree($userId, $channel, $existing === null ? 0 : (int) $existing['id']);

        $label = self::resolveLabel($context['label'] ?? null);
        $now   = date('Y-m-d H:i:s');

        $payload = [
            'channel'            => $channel,
            'label'              => $label,
            'value'              => $normalised['value'],
            'phone_country_code' => $normalised['country_code'],
            // A revived row starts unproved again. The address may have moved
            // on since it was released, and carrying the old verified_at over
            // would re-assert a fact nobody has re-checked.
            'verified_at'        => null,
            'verified_via'       => null,
            'is_recovery'        => !empty($context['is_recovery']) ? 1 : 0,
            'sort_order'         => $live,
            'deleted_at'         => null,
            'updated_at'         => $now,
        ];

        if ($existing === null) {
            $pointId = Database::insert('user_contact_points', $payload + [
                'user_id'    => $userId,
                'created_at' => $now,
            ]);
        } else {
            $pointId = (int) $existing['id'];
            Database::update('user_contact_points', $payload, ['id' => $pointId]);
        }

        self::record($userId, 'alternate_added', [
            'channel'     => $channel,
            'target'      => 'alternate',
            'destination' => $normalised['destination'],
            'to_status'   => 'unverified',
            'note'        => $label === null ? null : 'Labelled: ' . $label,
        ] + $context);

        // Starting the proof is a separate step with its own failure mode, and
        // it is deliberately NOT wrapped in a transaction with the insert
        // above: if the code cannot be delivered the claim must still exist so
        // the holder can press resend, rather than vanishing with the error.
        $request = self::startProof($userId, $channel, 'alternate', $normalised, $pointId, $context);

        return [
            'contact_point' => self::contactPoint($userId, $pointId),
            'request'       => $request,
        ];
    }

    /**
     * Re-prove an alternate that is not currently verified.
     *
     * The path back after an administrator revokes a verification, and the
     * path forward for a claim whose first code never arrived.
     *
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function reverifyAlternate(int $userId, int $pointId, array $context = []): array
    {
        self::assertContactUnlocked($userId);

        $point = self::assertOwnedPoint($userId, $pointId);

        if ($point['verified_at'] !== null) {
            throw HttpException::conflict('That contact is already verified.', 'already_verified');
        }

        // Refused rather than quietly applied when the gate is off. Every
        // other flow has something to do without a code — a change still
        // changes the value, an add still records the claim — but this one
        // exists only to produce proof, and with proof switched off it would
        // open a request, apply it, and leave the contact exactly as
        // unverified as it started. An operator can force-verify instead.
        if (!self::otpEnabled()) {
            throw HttpException::unprocessable(
                'Verification codes are switched off. An administrator can verify this contact for you.',
                'otp_disabled'
            );
        }

        self::assertMobileProvable((string) $point['channel']);

        // Restarting THIS contact's own proof is fine and is the ordinary case;
        // stealing the open slot from a different contact is not. See
        // assertAlternateProofSlotFree().
        self::assertAlternateProofSlotFree($userId, (string) $point['channel'], $pointId);

        $normalised = [
            'value'        => (string) $point['value'],
            'country_code' => $point['phone_country_code'] === null ? null : (string) $point['phone_country_code'],
            'destination'  => (string) $point['phone_country_code'] . (string) $point['value'],
        ];

        return self::startProof($userId, (string) $point['channel'], 'alternate', $normalised, $pointId, $context);
    }

    /**
     * Release an alternate.
     *
     * SOFT delete — `deleted_at` is set and the row stays. Two things break if
     * this ever becomes a DELETE:
     *
     *   * `fk_ccr_point` is ON DELETE CASCADE, so deleting a contact point
     *     also deletes every `contact_change_requests` row that ever pointed
     *     at it. Each of those rows is the record of an identifier change,
     *     including applied ones. A tidy-up would silently destroy the audit.
     *   * `contact_verification_events`.`request_id` is ON DELETE SET NULL, so
     *     the events survive but lose the thread that made them legible.
     *
     * Setting deleted_at costs none of that and still releases the address:
     * `verified_key` is generated as NULL the moment deleted_at is non-NULL,
     * so `uq_ucp_verified` stops binding it and somebody else can prove it
     * from that second. It is the same mechanism migration 030 gave
     * `users`.`email` and `phone`, behaving the same way for the same reason.
     *
     * @param array<string,mixed> $context
     */
    public static function removeAlternate(int $userId, int $pointId, array $context = []): void
    {
        self::assertContactUnlocked($userId);

        $point = self::assertOwnedPoint($userId, $pointId);

        if ($point['deleted_at'] !== null) {
            return;
        }

        Database::transaction(static function () use ($userId, $pointId, $point, $context): void {
            Database::update('user_contact_points', [
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $pointId]);

            // A pending request aimed at this row would otherwise apply itself
            // to a released contact, quietly bringing it back.
            foreach (Database::select(
                'SELECT ' . self::REQUEST_COLUMNS . ' FROM `contact_change_requests`
                  WHERE `contact_point_id` = :p AND `status` = "pending"',
                ['p' => $pointId]
            ) as $request) {
                self::close($request, 'cancelled', 'The contact it was proving was removed.', $context);
            }

            self::record($userId, 'alternate_removed', [
                'channel'     => (string) $point['channel'],
                'target'      => 'alternate',
                'destination' => (string) $point['phone_country_code'] . (string) $point['value'],
                'from_status' => $point['verified_at'] !== null ? 'verified' : 'unverified',
                'to_status'   => 'released',
            ] + $context);
        });
    }

    // =================================================================
    // Changing a primary identifier
    // =================================================================

    /**
     * Propose a new primary email address or mobile number.
     *
     * Nothing on `users` moves here. The proposal sits in
     * `contact_change_requests` until a code sent to the NEW value is
     * redeemed, which is what makes "re-verification after a change" the
     * precondition of the change rather than a chore that follows it. A
     * completed change therefore leaves email_verified_at SET, not NULL.
     *
     * @param  array<string,mixed> $context reason_code, reason_note, actor_name, ip, user_agent
     * @return array<string,mixed>
     */
    public static function requestPrimaryChange(int $userId, string $channel, string $newValue, array $context = []): array
    {
        [$normalised, $current] = self::preflightPrimaryChange($userId, $channel, $newValue);

        return (array) self::startProof(
            $userId,
            $channel,
            'primary',
            $normalised,
            null,
            $context + ['current_value' => $current['value']]
        );
    }

    /**
     * Every check a primary change must pass before it is opened.
     *
     * Public so ContactController can run them BEFORE asking the owner to
     * prove it is them (PrivacyService::verify): a change that is going to be
     * refused must not spend the code they just typed. requestPrimaryChange()
     * runs them again, so nothing relies on the caller having done so.
     *
     * @param  string|null $reasonCode checked too when given, since an unknown one is refused later
     * @return array{0:array{value:string,country_code:?string,destination:string},1:array{value:?string,destination:string}}
     *         the normalised new value, and the current primary
     */
    public static function preflightPrimaryChange(int $userId, string $channel, string $newValue, ?string $reasonCode = null): array
    {
        self::assertChannel($channel);
        self::assertContactUnlocked($userId);
        self::resolveReason($reasonCode);

        $allowed = $channel === 'email'
            ? Config::settingBool('creator', 'allow_email_change', true)
            : Config::settingBool('creator', 'allow_mobile_change', true);

        if (!$allowed) {
            throw HttpException::forbidden(
                $channel === 'email'
                    ? 'Email changes are switched off. Contact support if you need yours changed.'
                    : 'Mobile number changes are switched off. Contact support if you need yours changed.'
            );
        }

        self::assertMobileProvable($channel);

        $normalised = self::normalise($channel, $newValue);
        $current    = self::currentPrimary($userId, $channel);

        if ($current['destination'] !== '' && $current['destination'] === $normalised['destination']) {
            throw HttpException::unprocessable(
                $channel === 'email'
                    ? 'That is already your email address.'
                    : 'That is already your mobile number.',
                'no_change'
            );
        }

        self::assertAvailable($userId, $channel, $normalised);

        return [$normalised, $current];
    }

    /**
     * Send another code for an open request.
     *
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function resend(int $userId, int $requestId, array $context = []): array
    {
        $request = self::assertOwnedRequest($userId, $requestId);

        // Checked here and not only where the request was opened. A frozen
        // section that still posts codes to the address a takeover proposed is
        // a lock in name only — see verify().
        self::assertContactUnlocked($userId);

        if (!self::otpEnabled()) {
            throw HttpException::unprocessable(
                'Verification codes are switched off, so there is nothing to resend.',
                'otp_disabled'
            );
        }

        self::assertLive($request);

        return self::issueCode($request, $context, true);
    }

    /**
     * Redeem the code and, if it is right, apply the change.
     *
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function verify(int $userId, int $requestId, string $code, array $context = []): array
    {
        $request = self::assertOwnedRequest($userId, $requestId);

        // THE DECISIVE WRITE, so the lock is enforced here above all. verify()
        // is what calls apply(), and apply() is what rewrites users.email /
        // users.phone and stamps *_verified_at. An operator freezes the section
        // precisely because a suspicious change is already pending; letting the
        // holder of the pending request finish it anyway would make the lock
        // decorative against the one scenario it exists for. Checking only at
        // requestPrimaryChange() guards the door after the intruder is inside.
        self::assertContactUnlocked($userId);

        if (!self::otpEnabled()) {
            // With the gate off, startProof() applies at once and never leaves
            // a request pending, so reaching here means the operator switched
            // OTP off mid-flight. Say so rather than failing on a missing code.
            throw HttpException::unprocessable(
                'Verification codes are switched off. This request no longer needs one.',
                'otp_disabled'
            );
        }

        self::assertLive($request);

        $destination = self::destinationFor($request);
        $purpose     = self::purposeFor($request);

        // A ceiling on WRONG GUESSES across the whole request, which
        // otp_verifications.attempts cannot provide on its own: that counter
        // resets with every fresh code, so five wrong guesses, resend, five
        // more is unlimited brute force through a counter that never trips.
        $bucket  = 'contact:otp:verify:' . $requestId;
        $perCode = (int) (Database::scalar(
            'SELECT `max_attempts` FROM `otp_verifications` WHERE `id` = :id',
            ['id' => $request['otp_id']]
        ) ?? 5);
        $ceiling = max(1, $perCode) * max(1, Config::settingInt('auth', 'otp_max_resends', 3));

        if (RateLimiter::tooManyAttempts($bucket, $ceiling)) {
            throw HttpException::tooManyRequests(
                'Too many incorrect codes. Start the change again.',
                RateLimiter::availableIn($bucket)
            );
        }

        try {
            // Bound to this account as well as to the request below, so a code
            // another account was sent for the same new address never matches.
            $record = AuthService::verifyOtp($destination, $code, $purpose, $userId);
        } catch (HttpException $e) {
            RateLimiter::hit($bucket, $ceiling, self::changeTtlMinutes() * 60);

            self::record($userId, 'otp_failed', [
                'request_id'  => (int) $request['id'],
                'channel'     => (string) $request['channel'],
                'target'      => (string) $request['target'],
                'destination' => $destination,
                'from_status' => 'pending',
                'to_status'   => 'pending',
                'note'        => $e->getMessage(),
                'metadata'    => ['error_code' => $e->errorCode()],
            ] + $context);

            throw $e;
        }

        // The code must be the one THIS request issued. Without the binding,
        // any live code matching (destination, purpose) would do — and two
        // accounts racing for the same new address both match.
        if ($request['otp_id'] !== null && (int) $record['id'] !== (int) $request['otp_id']) {
            throw HttpException::unprocessable(
                'That code belongs to a different request. Ask for a new one.',
                'otp_mismatch'
            );
        }

        $applied = Database::transaction(static function () use ($request, $context): array {
            $now = date('Y-m-d H:i:s');

            Database::update('contact_change_requests', [
                'status'      => 'verified',
                'verified_at' => $now,
                'updated_at'  => $now,
            ], ['id' => (int) $request['id']]);

            self::record((int) $request['user_id'], 'verified', [
                'request_id'  => (int) $request['id'],
                'channel'     => (string) $request['channel'],
                'target'      => (string) $request['target'],
                'destination' => self::destinationFor($request),
                'from_status' => 'pending',
                'to_status'   => 'verified',
                'note'        => 'The holder redeemed the code sent to this address.',
            ] + $context);

            return self::apply(['status' => 'verified'] + $request, true, $context);
        });

        RateLimiter::clear($bucket);
        RateLimiter::clear('contact:otp:cooldown:' . $requestId);
        RateLimiter::clear(self::sendBucket($userId, (string) $request['channel'], (string) $request['target']));

        return ['request' => $applied, 'overview' => self::overview($userId)];
    }

    /**
     * Withdraw a request the holder no longer wants.
     *
     * @param array<string,mixed> $context
     */
    public static function cancel(int $userId, int $requestId, array $context = []): void
    {
        $request = self::assertOwnedRequest($userId, $requestId);

        if ($request['status'] !== 'pending') {
            return;
        }

        self::close($request, 'cancelled', $context['note'] ?? null, $context);
    }

    /**
     * Close requests nobody finished. Called by the nightly job.
     *
     * Returns the count so the job log says what it did rather than only that
     * it ran.
     */
    public static function expireDue(): int
    {
        $due = Database::select(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM `contact_change_requests`
              WHERE `status` = "pending" AND `expires_at` <= NOW()'
        );

        foreach ($due as $request) {
            self::close($request, 'expired', 'The request was not proved before it lapsed.', ['actor_type' => 'system']);
        }

        return count($due);
    }

    // =================================================================
    // Administrative overrides
    // =================================================================

    /**
     * Mark a contact verified on an operator's authority.
     *
     * The escape hatch for the case the OTP engine cannot serve: somebody who
     * has proved an address to a support agent by other means, or whose
     * carrier will not deliver an SMS. `verified_via` records that it was an
     * operator and not a code, so nobody later mistakes it for proof of
     * possession.
     *
     * @param array<string,mixed> $context actor_id, actor_name, note, ip
     */
    public static function forceVerify(int $userId, string $channel, ?int $pointId, array $context = []): void
    {
        self::assertChannel($channel);

        $now = date('Y-m-d H:i:s');

        if ($pointId === null) {
            $current = self::currentPrimary($userId, $channel);

            if ($current['value'] === null || $current['value'] === '') {
                throw HttpException::unprocessable(
                    'This account has no ' . ($channel === 'email' ? 'email address' : 'mobile number') . ' to verify.'
                );
            }

            $column = $channel === 'email' ? 'email_verified_at' : 'phone_verified_at';

            // Read before the write, so the log records the state the operator
            // actually changed. A row that says unverified -> verified about an
            // address that was already verified is an audit entry asserting a
            // transition that never happened.
            $wasVerified = Database::scalar(
                'SELECT `' . $column . '` FROM `users` WHERE `id` = :id',
                ['id' => $userId]
            ) !== null;

            Database::update('users', [$column => $now], ['id' => $userId]);

            self::record($userId, 'force_verified', [
                'channel'     => $channel,
                'target'      => 'primary',
                'destination' => $current['destination'],
                'from_status' => $wasVerified ? 'verified' : 'unverified',
                'to_status'   => 'verified',
                'actor_type'  => 'admin',
            ] + $context);

            self::syncBadges($userId);

            return;
        }

        $point = self::assertOwnedPointOnChannel($userId, $pointId, $channel);

        if ($point['deleted_at'] !== null) {
            throw HttpException::unprocessable('That contact has been removed.');
        }

        self::assertAvailable($userId, (string) $point['channel'], [
            'value'        => (string) $point['value'],
            'country_code' => $point['phone_country_code'] === null ? null : (string) $point['phone_country_code'],
            'destination'  => (string) $point['phone_country_code'] . (string) $point['value'],
        ]);

        Database::update('user_contact_points', [
            'verified_at'  => $now,
            'verified_via' => 'admin_force',
            'updated_at'   => $now,
        ], ['id' => $pointId]);

        self::record($userId, 'force_verified', [
            'channel'     => (string) $point['channel'],
            'target'      => 'alternate',
            'destination' => (string) $point['phone_country_code'] . (string) $point['value'],
            'from_status' => $point['verified_at'] !== null ? 'verified' : 'unverified',
            'to_status'   => 'verified',
            'actor_type'  => 'admin',
        ] + $context);
    }

    /**
     * Withdraw a verification.
     *
     * There is deliberately no `revoked_at` column to set: two columns that
     * can disagree about whether something is verified is one column too many,
     * so verified_at simply returns to NULL and the event log carries the
     * story. On an alternate that also drops `verified_key` to NULL, which
     * releases the address for anybody else to prove.
     *
     * @param array<string,mixed> $context actor_id, actor_name, reason, ip
     */
    public static function revokeVerification(int $userId, string $channel, ?int $pointId, array $context = []): void
    {
        self::assertChannel($channel);

        $reason = isset($context['reason']) ? mb_substr((string) $context['reason'], 0, 1000) : null;

        if ($pointId === null) {
            $current = self::currentPrimary($userId, $channel);

            // The mirror of forceVerify()'s check. Without it an operator can
            // "revoke" a mobile number an account has never held, and the log
            // then carries a verification_revoked row with an empty destination
            // — a decision recorded about nothing, which is exactly the kind of
            // entry that makes a whole audit trail untrustworthy.
            if ($current['value'] === null || $current['value'] === '') {
                throw HttpException::unprocessable(
                    'This account has no ' . ($channel === 'email' ? 'email address' : 'mobile number') . ' to revoke.'
                );
            }

            $column = $channel === 'email' ? 'email_verified_at' : 'phone_verified_at';

            $wasVerified = Database::scalar(
                'SELECT `' . $column . '` FROM `users` WHERE `id` = :id',
                ['id' => $userId]
            ) !== null;

            Database::update('users', [$column => null], ['id' => $userId]);

            self::record($userId, 'verification_revoked', [
                'channel'     => $channel,
                'target'      => 'primary',
                'destination' => $current['destination'],
                'from_status' => $wasVerified ? 'verified' : 'unverified',
                'to_status'   => 'unverified',
                'actor_type'  => 'admin',
                'note'        => $reason,
            ] + $context);

            self::syncBadges($userId);

            return;
        }

        $point = self::assertOwnedPointOnChannel($userId, $pointId, $channel);

        // A released row keeps its verified_at as history — removeAlternate()
        // leaves it standing on purpose, and `verified_key` has already gone
        // NULL so the address is free regardless. Revoking one would destroy
        // that history to release something already released.
        if ($point['deleted_at'] !== null) {
            throw HttpException::unprocessable('That contact has been removed.');
        }

        Database::update('user_contact_points', [
            'verified_at'  => null,
            'verified_via' => null,
            'updated_at'   => date('Y-m-d H:i:s'),
        ], ['id' => $pointId]);

        self::record($userId, 'verification_revoked', [
            'channel'     => (string) $point['channel'],
            'target'      => 'alternate',
            'destination' => (string) $point['phone_country_code'] . (string) $point['value'],
            'from_status' => $point['verified_at'] !== null ? 'verified' : 'unverified',
            'to_status'   => 'unverified',
            'actor_type'  => 'admin',
            'note'        => $reason,
        ] + $context);
    }

    /**
     * Refuse a pending change.
     *
     * This is the chokepoint migration 036 note 5 points at for accounts that
     * `creator_profile_locks` cannot reach — a brand user has no creators row,
     * so there is no section to freeze, but the request itself can be stopped
     * and the operator's name and reason are recorded against it.
     *
     * $userId is required rather than inferred from the request row: the id
     * arrives from a URL segment, and a method that closes whichever request
     * that id names is one careless caller away from letting an operator close
     * a stranger's request from another account's screen. The ownership check
     * belongs with the write, not only in the controller in front of it.
     *
     * @param array<string,mixed> $context actor_id, actor_name, note, ip
     */
    public static function rejectChange(int $userId, int $requestId, array $context = []): void
    {
        $request = self::assertOwnedRequest($userId, $requestId);

        if ($request['status'] !== 'pending') {
            throw HttpException::conflict('That request is no longer open.', 'request_closed');
        }

        self::close(
            $request,
            'rejected',
            $context['note'] ?? null,
            ['actor_type' => 'admin'] + $context
        );
    }

    // =================================================================
    // The state machine
    // =================================================================

    /**
     * Open (or supersede) a request and start proving it.
     *
     * @param  array{value:string,country_code:?string,destination:string} $normalised
     * @param  array<string,mixed>                                        $context
     * @return array<string,mixed>
     */
    private static function startProof(
        int $userId,
        string $channel,
        string $target,
        array $normalised,
        ?int $pointId,
        array $context
    ): array {
        $now     = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + self::changeTtlMinutes() * 60);

        $payload = [
            'channel'                => $channel,
            'target'                 => $target,
            'contact_point_id'       => $pointId,
            'current_value'          => isset($context['current_value']) ? (string) $context['current_value'] : null,
            'new_value'              => $normalised['value'],
            'new_phone_country_code' => $normalised['country_code'],
            'reason_code'            => self::resolveReason($context['reason_code'] ?? null),
            'reason_note'            => isset($context['reason_note'])
                ? mb_substr((string) $context['reason_note'], 0, 500)
                : null,
            'status'                 => 'pending',
            'otp_id'                 => null,
            'attempt_count'          => 0,
            'expires_at'             => $expires,
            'verified_at'            => null,
            'applied_at'             => null,
            'closed_at'              => null,
            'actor_type'             => (string) ($context['actor_type'] ?? 'user'),
            'actor_id'               => $userId,
            'actor_name'             => isset($context['actor_name'])
                ? mb_substr((string) $context['actor_name'], 0, 150)
                : null,
            'ip_address'             => self::packIp($context['ip'] ?? null),
            'user_agent'             => isset($context['user_agent'])
                ? mb_substr((string) $context['user_agent'], 0, 255)
                : null,
            'updated_at'             => $now,
        ];

        // Everything above this line can still throw — resolveReason() and
        // resolveLabel() refuse an unknown term — and it all happens before the
        // open request below is touched. A malformed second attempt must not
        // cost the holder the request they already have live.
        //
        // `uq_ccr_open` permits exactly one pending request per account per
        // channel per slot, so starting a new change has to displace the open
        // one rather than collide with it. It is CLOSED and a fresh row opened,
        // never rewritten in place, and each of the three reasons is a bug the
        // in-place version actually had:
        //
        //   * The old row is what earlier events point at. Rewriting it left
        //     an `otp_sent` event recording a code to address A hanging off a
        //     row that now reads "change to B" — an audit trail that had been
        //     edited after the fact.
        //   * close() expires the code the dropped request issued. Keeping the
        //     row alive skipped that, so the superseded code stayed redeemable
        //     for its full TTL — the same hole close() exists to shut.
        //   * The wrong-guess ceiling is keyed 'contact:otp:verify:{id}'. Reuse
        //     the id and the message "Start the change again" hands the holder
        //     a brand-new request that is already out of attempts.
        $open = self::openRequestFor($userId, $channel, $target);

        $requestId = Database::transaction(
            static function () use ($open, $payload, $userId, $now, $context): int {
                // Closed first, inside the same transaction: `open_key` is
                // generated NULL for any non-pending row, so the unique slot is
                // only free for the INSERT once the UPDATE has landed.
                if ($open !== null) {
                    self::close(
                        $open,
                        'cancelled',
                        'Superseded by a newer request for the same slot.',
                        $context
                    );
                }

                return Database::insert('contact_change_requests', $payload + [
                    'user_id'    => $userId,
                    'created_at' => $now,
                ]);
            }
        );

        $request = self::loadRequest($requestId);

        self::record($userId, 'change_requested', [
            'request_id'  => $requestId,
            'channel'     => $channel,
            'target'      => $target,
            'destination' => $normalised['destination'],
            'from_status' => $open === null ? null : (string) $open['status'],
            'to_status'   => 'pending',
            'note'        => $open === null ? null : 'Superseded an earlier request for the same slot.',
            'metadata'    => [
                'current_value' => $payload['current_value'],
                'reason_code'   => $payload['reason_code'],
                // Named so the two halves of a supersede can be read back as
                // one story: this row says what replaced it, the closed row
                // says it was replaced.
                'superseded_request_id' => $open === null ? null : (int) $open['id'],
            ],
        ] + $context);

        // The operator has switched proof off. Apply now, and be honest about
        // it: the request never passes through 'verified', verified_at stays
        // NULL, and the identifier lands UNVERIFIED. See apply().
        if (!self::otpEnabled()) {
            return self::apply($request, false, $context);
        }

        self::issueCode($request, $context, false);

        return self::loadRequest($requestId);
    }

    /**
     * Send a one-time code for a request and bind it to that request.
     *
     * @param  array<string,mixed> $request
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function issueCode(array $request, array $context, bool $isResend): array
    {
        $userId      = (int) $request['user_id'];
        $requestId   = (int) $request['id'];
        $channel     = (string) $request['channel'];
        $target      = (string) $request['target'];
        $destination = self::destinationFor($request);
        $purpose     = self::purposeFor($request);

        $cooldown    = max(0, Config::settingInt('auth', 'otp_resend_seconds', 45));
        $maxResends  = max(1, Config::settingInt('auth', 'otp_max_resends', 3));
        $cooldownKey = 'contact:otp:cooldown:' . $requestId;
        $sendKey     = self::sendBucket($userId, $channel, $target);

        // Two different limits, both needed. The cooldown stops a client
        // hammering resend on a slow SMS; the ceiling stops an account being
        // used to pump codes at an address that is not theirs.
        if ($cooldown > 0) {
            $gate = RateLimiter::hit($cooldownKey, 1, $cooldown);

            if (!$gate['allowed']) {
                throw HttpException::tooManyRequests(
                    "Wait {$gate['retry_after']} seconds before asking for another code.",
                    $gate['retry_after']
                );
            }
        }

        $ceiling = RateLimiter::hit($sendKey, $maxResends, self::changeTtlMinutes() * 60);

        if (!$ceiling['allowed']) {
            throw HttpException::tooManyRequests(
                'Too many codes have been requested for this change. Try again later.',
                $ceiling['retry_after']
            );
        }

        // attempt_count counts CODES ISSUED against the request, which is not
        // otp_verifications.attempts — that one counts wrong entries against a
        // single code. Five wrong guesses and five fresh codes are different
        // kinds of suspicious and an operator needs to tell them apart.
        Database::execute(
            'UPDATE `contact_change_requests`
                SET `attempt_count` = LEAST(255, `attempt_count` + 1), `updated_at` = NOW()
              WHERE `id` = :id',
            ['id' => $requestId]
        );

        try {
            $result = AuthService::sendOtp(
                $destination,
                $channel === 'email' ? 'email' : 'sms',
                $purpose,
                $userId
            );
        } catch (HttpException $e) {
            // Nothing to bind. A failed send leaves no code behind: sendOtp()
            // refuses before writing one when a limit is hit, deletes the one
            // it could not deliver, and never queues a code to arrive later.
            // So the request keeps the code it already had, which still works
            // because nothing expired it. Record the failure and rethrow:
            // answering "code sent" when nothing left the building is the bug
            // AuthService already fixed once in its own layer.
            self::record($userId, 'otp_failed', [
                'request_id'  => $requestId,
                'channel'     => $channel,
                'target'      => $target,
                'destination' => $destination,
                'from_status' => 'pending',
                'to_status'   => 'pending',
                'note'        => $e->getMessage(),
                'metadata'    => ['error_code' => $e->errorCode(), 'resend' => $isResend],
            ] + $context);

            throw $e;
        }

        self::bindCode($requestId, (int) $result['otp_id']);

        self::record($userId, 'otp_sent', [
            'request_id'  => $requestId,
            'channel'     => $channel,
            'target'      => $target,
            'destination' => $destination,
            'from_status' => 'pending',
            'to_status'   => 'pending',
            'metadata'    => ['resend' => $isResend, 'purpose' => $purpose],
        ] + $context);

        return [
            'request'     => self::loadRequest($requestId),
            'expires_in'  => $result['expires_in'] ?? null,
            'length'      => $result['length'] ?? null,
            'channel'     => $result['channel'] ?? null,
            'resend_in'   => $cooldown,
            'resends_left' => $ceiling['remaining'],
        ];
    }

    /**
     * Attach the code AuthService just delivered to the request that asked
     * for it, by the id sendOtp() returned. Reading "the newest code for this
     * address" back instead could pick up a code issued to somebody else for
     * the same address in between.
     */
    private static function bindCode(int $requestId, int $otpId): void
    {
        if ($otpId <= 0) {
            return;
        }

        Database::update('contact_change_requests', [
            'otp_id'     => $otpId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $requestId]);
    }

    /**
     * Write the proposal onto the account.
     *
     * $proved says whether a code was actually redeemed. It drives the one
     * thing that must never be guessed: whether the identifier lands verified.
     * With OTP switched off the change still happens — that is what the
     * operator asked for — but email_verified_at is set to NULL rather than
     * NOW(), because no human proved anything and the column would otherwise
     * assert that they had.
     *
     * @param  array<string,mixed> $request
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function apply(array $request, bool $proved, array $context): array
    {
        $userId      = (int) $request['user_id'];
        $requestId   = (int) $request['id'];
        $channel     = (string) $request['channel'];
        $target      = (string) $request['target'];
        $value       = (string) $request['new_value'];
        $countryCode = $request['new_phone_country_code'] === null ? null : (string) $request['new_phone_country_code'];
        $now         = date('Y-m-d H:i:s');

        // Re-checked HERE and not only at request time. Minutes pass between
        // the two, and in a marketplace where two people can want the same
        // address the loser of that race must be told, not silently collided
        // with `uq_users_email`.
        self::assertAvailable($userId, $channel, [
            'value'        => $value,
            'country_code' => $countryCode,
            'destination'  => (string) $countryCode . $value,
        ]);

        // Read before the write: the notice below goes to the address being
        // replaced, which is the one the real owner still reads.
        $before = $target === 'primary'
            ? (Database::selectOne(
                'SELECT `name`, `email`, `phone`, `phone_country_code` FROM `users` WHERE `id` = :id',
                ['id' => $userId]
            ) ?? [])
            : [];

        if ($target === 'primary') {
            // *_changed_at starts the hold in PrivacyService: for a while the
            // new address cannot receive step-up codes or link a Google or
            // Apple sign-in. Stamped whether or not a code proved the change,
            // and for a first address too: what matters is that it is new.
            $updates = $channel === 'email'
                ? ['email' => $value, 'email_verified_at' => $proved ? $now : null, 'email_changed_at' => $now]
                : [
                    'phone'              => $value,
                    'phone_country_code' => $countryCode,
                    'phone_verified_at'  => $proved ? $now : null,
                    'phone_changed_at'   => $now,
                ];

            Database::update('users', $updates, ['id' => $userId]);

            // Promoting an address the holder already listed as an alternate
            // releases the alternate. Leaving it would give one account two
            // rows asserting the same address — the duplicate source of truth
            // this whole module exists to prevent — and would pin
            // `uq_ucp_verified` to a value that `users` now also owns.
            foreach (Database::select(
                'SELECT * FROM `user_contact_points`
                  WHERE `user_id` = :u AND `channel` = :c AND `value` = :v AND `deleted_at` IS NULL',
                ['u' => $userId, 'c' => $channel, 'v' => $value]
            ) as $promoted) {
                Database::update('user_contact_points', [
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ], ['id' => (int) $promoted['id']]);

                self::record($userId, 'alternate_removed', [
                    'channel'     => $channel,
                    'target'      => 'alternate',
                    'destination' => (string) $countryCode . $value,
                    'from_status' => $promoted['verified_at'] !== null ? 'verified' : 'unverified',
                    'to_status'   => 'released',
                    'note'        => 'Promoted to the primary contact.',
                ] + $context);
            }
        } else {
            $pointId = (int) $request['contact_point_id'];

            Database::update('user_contact_points', [
                'value'              => $value,
                'phone_country_code' => $countryCode,
                'verified_at'        => $proved ? $now : null,
                'verified_via'       => $proved ? 'otp' : null,
                // Applying to a released row brings it back deliberately: the
                // holder just proved it again.
                'deleted_at'         => null,
                'updated_at'         => $now,
            ], ['id' => $pointId]);
        }

        Database::update('contact_change_requests', [
            'status'     => 'applied',
            'applied_at' => $now,
            'updated_at' => $now,
        ], ['id' => $requestId]);

        self::record($userId, 'change_applied', [
            'request_id'  => $requestId,
            'channel'     => $channel,
            'target'      => $target,
            'destination' => (string) $countryCode . $value,
            'from_status' => $proved ? 'verified' : 'pending',
            'to_status'   => 'applied',
            'note'        => $proved
                ? null
                : 'Applied without proof: OTP verification is switched off, so the new value is recorded as unverified.',
            'metadata'    => [
                'proved'        => $proved,
                'current_value' => $request['current_value'],
            ],
        ] + $context);

        if ($target === 'primary') {
            self::syncBadges($userId);
            self::queueChangeNotice($before, $channel, $value);
        }

        return self::loadRequest($requestId);
    }

    /**
     * Tell the owner a primary identifier changed, at the address that was
     * just replaced, so somebody whose account was changed by someone else
     * finds out while the old address still reaches them.
     *
     * Queued, not sent: apply() runs inside the transaction that makes the
     * change, so the notice and the change commit or roll back together.
     *
     * @param array<string,mixed> $before the users row before the change
     */
    private static function queueChangeNotice(array $before, string $channel, string $newValue): void
    {
        $support = MailService::supportAddress();
        $name    = (string) ($before['name'] ?? '');
        $email   = strtolower(trim((string) ($before['email'] ?? '')));

        if ($channel === 'email') {
            if ($email === '' || $email === strtolower($newValue)) {
                return;
            }

            $subject = 'Your Zusty email address was changed';

            MailService::queue(
                $email,
                $name,
                $subject,
                MailService::renderDefault(
                    $subject,
                    'The email address on your Zusty account was changed to ' . Str::maskEmail($newValue)
                        . ". If this wasn't you, contact " . $support . ' straight away.',
                    $name
                ),
                null,
                $support !== '' ? $support : null
            );

            return;
        }

        $oldPhone = trim((string) ($before['phone'] ?? ''));

        // SmsService::queue() does nothing while SMS is switched off.
        if ($oldPhone !== '') {
            SmsService::queue(
                (string) ($before['phone_country_code'] ?? '') . $oldPhone,
                "Zusty: the mobile number on your account was changed. If this wasn't you, contact " . $support . '.'
            );
        }

        // The email did not change, so it reaches the owner too, whether or
        // not a text can be sent.
        if ($email !== '') {
            $ending  = substr((string) preg_replace('/\D/', '', $newValue), -4);
            $subject = $oldPhone !== ''
                ? 'The mobile number on your Zusty account was changed'
                : 'A mobile number was added to your Zusty account';

            MailService::queue(
                $email,
                $name,
                $subject,
                MailService::renderDefault(
                    $subject,
                    ($oldPhone !== ''
                        ? 'The mobile number on your Zusty account was changed to one ending in ' . $ending . '.'
                        : 'A mobile number ending in ' . $ending . ' was added to your Zusty account.')
                        . " If this wasn't you, contact " . $support . ' straight away.',
                    $name
                ),
                null,
                $support !== '' ? $support : null
            );
        }
    }

    /**
     * Move a request to one of its terminal states.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $context
     */
    private static function close(array $request, string $status, ?string $note, array $context): void
    {
        $now = date('Y-m-d H:i:s');

        $updates = [
            'status'     => $status,
            'closed_at'  => $now,
            'updated_at' => $now,
        ];

        if ($status === 'rejected') {
            $updates['reviewed_by']  = isset($context['actor_id']) ? (int) $context['actor_id'] : null;
            $updates['review_note']  = $note === null ? null : mb_substr($note, 0, 500);
        }

        Database::update('contact_change_requests', $updates, ['id' => (int) $request['id']]);

        // Any code still live for this request is worthless now, and leaving
        // it redeemable would let a cancelled change be completed after the
        // fact by whoever holds the SMS.
        if ($request['otp_id'] !== null) {
            Database::execute(
                'UPDATE `otp_verifications` SET `expires_at` = NOW()
                  WHERE `id` = :id AND `verified_at` IS NULL',
                ['id' => (int) $request['otp_id']]
            );
        }

        self::record((int) $request['user_id'], 'change_' . $status, [
            'request_id'  => (int) $request['id'],
            'channel'     => (string) $request['channel'],
            'target'      => (string) $request['target'],
            'destination' => self::destinationFor($request),
            'from_status' => (string) $request['status'],
            'to_status'   => $status,
            'note'        => $note,
        ] + $context);
    }

    // =================================================================
    // Guards
    // =================================================================

    private static function assertChannel(string $channel): void
    {
        if (!isset(self::PURPOSES['primary'][$channel])) {
            throw HttpException::unprocessable("Not a contact channel: {$channel}");
        }
    }

    /**
     * Refuse a write to a frozen contact section.
     *
     * Delegated rather than duplicated: CreatorProfileLockService already owns
     * every lock on this platform, and a second answer to "is this locked" is
     * how a lock silently stops being enforced. Accounts with no creator
     * profile are unlockable today — migration 036 note 5 records why that is
     * a note and not a second table.
     */
    private static function assertContactUnlocked(int $userId): void
    {
        $creatorId = self::creatorIdFor($userId);

        if ($creatorId !== null) {
            CreatorProfileLockService::assertUnlocked($creatorId, 'contact');
        }
    }

    private static function creatorIdFor(int $userId): ?int
    {
        $id = Database::scalar(
            'SELECT `id` FROM `creators` WHERE `user_id` = :u AND `deleted_at` IS NULL LIMIT 1',
            ['u' => $userId]
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Is this address free?
     *
     * Both halves matter. `users` holds the primary identifiers and
     * `user_contact_points` holds the proved alternates, and an address that
     * one account has PROVED as an alternate must not become another
     * account's primary — otherwise the platform has two accounts it can
     * legitimately reach at the same address, which is what account recovery
     * cannot survive.
     *
     * Only OTHER accounts are consulted. A collision inside one account is
     * already impossible — `uq_ucp_user_value` stops the same person listing
     * an address twice — and an address the holder already owns as an
     * alternate is one they are entitled to promote, which apply() handles by
     * releasing the alternate rather than by refusing the change.
     *
     * @param array{value:string,country_code:?string,destination:string} $normalised
     */
    private static function assertAvailable(int $userId, string $channel, array $normalised): void
    {
        if ($channel === 'email') {
            $taken = Database::scalar(
                'SELECT `id` FROM `users`
                  WHERE `email` = :v AND `deleted_at` IS NULL AND `id` <> :u LIMIT 1',
                ['v' => $normalised['value'], 'u' => $userId]
            );
        } else {
            $taken = Database::scalar(
                'SELECT `id` FROM `users`
                  WHERE `phone` = :v AND `phone_country_code` = :cc AND `deleted_at` IS NULL AND `id` <> :u LIMIT 1',
                ['v' => $normalised['value'], 'cc' => (string) $normalised['country_code'], 'u' => $userId]
            );
        }

        if ($taken !== null) {
            throw HttpException::conflict(
                $channel === 'email'
                    ? 'That email address is already in use.'
                    : 'That mobile number is already in use.',
                'contact_taken'
            );
        }

        // `<=>` rather than `=` because phone_country_code is NULL on every
        // email row, and `NULL = NULL` is NULL — the comparison would never
        // match and the check would pass for addresses that are taken.
        $claimed = Database::scalar(
            'SELECT `id` FROM `user_contact_points`
              WHERE `channel` = :c AND `value` = :v AND `phone_country_code` <=> :cc
                AND `verified_at` IS NOT NULL AND `deleted_at` IS NULL
                AND `user_id` <> :u LIMIT 1',
            [
                'c'  => $channel,
                'v'  => $normalised['value'],
                'cc' => $normalised['country_code'],
                'u'  => $userId,
            ]
        );

        if ($claimed !== null) {
            throw HttpException::conflict(
                'That contact has already been verified on another account.',
                'contact_taken'
            );
        }
    }

    /**
     * @param array{value:string,country_code:?string,destination:string} $normalised
     */
    private static function assertNotOwnPrimary(int $userId, string $channel, array $normalised): void
    {
        $current = self::currentPrimary($userId, $channel);

        if ($current['destination'] !== '' && $current['destination'] === $normalised['destination']) {
            throw HttpException::unprocessable(
                $channel === 'email'
                    ? 'That is already your primary email address.'
                    : 'That is already your primary mobile number.',
                'is_primary'
            );
        }
    }

    /**
     * The one open request for a slot, or nothing.
     *
     * @return array<string,mixed>|null
     */
    private static function openRequestFor(int $userId, string $channel, string $target): ?array
    {
        return Database::selectOne(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM `contact_change_requests`
              WHERE `user_id` = :u AND `channel` = :c AND `target` = :t AND `status` = "pending"',
            ['u' => $userId, 'c' => $channel, 't' => $target]
        );
    }

    /**
     * Only one alternate of a given channel may be awaiting proof at a time.
     *
     * `uq_ccr_open` is generated from (user_id, channel, target) and NOTHING
     * ELSE — contact_point_id is not in it, and the schema is fixed. So the
     * table can physically hold one pending alternate request per channel, no
     * matter what creator.max_alternate_contacts_per_channel is raised to.
     *
     * Left unguarded that limit expressed itself as silent damage: claiming a
     * second alternate displaced the first one's pending request, so the first
     * contact was left with no live proof at all and no way for its holder to
     * know. Refusing the second claim outright is the honest reading of a
     * schema that can only track one — the holder is told which proof is in
     * flight and can finish or cancel it, and nothing they already claimed is
     * quietly dropped. It also refuses BEFORE addAlternate() inserts anything,
     * so a refused claim leaves no row counting against the ceiling.
     *
     * $candidateId is the contact point the caller is about to prove, or 0 when
     * it does not exist yet — a request already aimed at that same point is not
     * a rival, it is this same proof being restarted.
     */
    private static function assertAlternateProofSlotFree(int $userId, string $channel, int $candidateId): void
    {
        $open = self::openRequestFor($userId, $channel, 'alternate');

        if ($open === null || (int) $open['contact_point_id'] === $candidateId) {
            return;
        }

        throw HttpException::conflict(
            'A verification is already in progress for '
                . (string) $open['new_phone_country_code'] . (string) $open['new_value']
                . '. Finish or cancel it before starting another.',
            'alternate_proof_in_progress'
        );
    }

    /**
     * The point, and proof that the caller named the channel it is actually on.
     *
     * The admin forms submit `channel` and `contact_point_id` side by side, and
     * without this the id decided everything while the channel decided only
     * what the activity log said. An operator marking an email address verified
     * could produce an admin-log line reading "Marked a phone contact verified"
     * against a contact_verification_events row that correctly says email — two
     * audit trails disagreeing about the same decision.
     *
     * @return array<string,mixed>
     */
    private static function assertOwnedPointOnChannel(int $userId, int $pointId, string $channel): array
    {
        $point = self::assertOwnedPoint($userId, $pointId);

        if ((string) $point['channel'] !== $channel) {
            throw HttpException::unprocessable(
                'That contact is ' . ((string) $point['channel'] === 'email' ? 'an email address' : 'a mobile number')
                    . ', not ' . ($channel === 'email' ? 'an email address' : 'a mobile number') . '.',
                'channel_mismatch'
            );
        }

        return $point;
    }

    /** @return array<string,mixed> */
    private static function assertOwnedPoint(int $userId, int $pointId): array
    {
        $point = Database::selectOne(
            'SELECT * FROM `user_contact_points` WHERE `id` = :id AND `user_id` = :u',
            ['id' => $pointId, 'u' => $userId]
        );

        if ($point === null) {
            throw HttpException::notFound('Contact not found.');
        }

        return $point;
    }

    /** @return array<string,mixed> */
    private static function assertOwnedRequest(int $userId, int $requestId): array
    {
        $request = Database::selectOne(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM `contact_change_requests`
              WHERE `id` = :id AND `user_id` = :u',
            ['id' => $requestId, 'u' => $userId]
        );

        if ($request === null) {
            throw HttpException::notFound('Contact change request not found.');
        }

        return $request;
    }

    /** @param array<string,mixed> $request */
    private static function assertLive(array $request): void
    {
        if ($request['status'] !== 'pending') {
            throw HttpException::conflict('That request is no longer open.', 'request_closed');
        }

        if (strtotime((string) $request['expires_at']) <= time()) {
            // Closed here rather than left for the nightly sweep, so the
            // caller's next read shows 'expired' instead of a request that
            // claims to be open and refuses every code.
            self::close($request, 'expired', 'The request lapsed before it was proved.', ['actor_type' => 'system']);

            throw HttpException::unprocessable('That request has expired. Start again.', 'request_expired');
        }
    }

    // =================================================================
    // Internals
    // =================================================================

    /**
     * Split and validate an address.
     *
     * A phone is stored as national number plus country code, exactly the way
     * `users` splits phone from phone_country_code, so the two can be compared
     * without parsing either of them.
     *
     * @return array{value:string,country_code:?string,destination:string}
     */
    private static function normalise(string $channel, string $value): array
    {
        $value = trim($value);

        if ($channel === 'email') {
            $value = mb_strtolower($value);

            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || mb_strlen($value) > 191) {
                throw HttpException::unprocessable('Enter a valid email address.', 'invalid_email');
            }

            return ['value' => $value, 'country_code' => null, 'destination' => $value];
        }

        [$code, $national] = Str::splitPhone(
            $value,
            Config::settingString('auth', 'otp_default_country_code', '+91')
        );

        if (preg_match('/^\d{6,15}$/', $national) !== 1) {
            throw HttpException::unprocessable('Enter a valid mobile number.', 'invalid_phone');
        }

        return ['value' => $national, 'country_code' => $code, 'destination' => $code . $national];
    }

    /** @return array{value:?string,destination:string} */
    private static function currentPrimary(int $userId, string $channel): array
    {
        $user = Database::selectOne(
            'SELECT `email`, `phone`, `phone_country_code` FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        );

        if ($user === null) {
            throw HttpException::notFound('Account not found.');
        }

        if ($channel === 'email') {
            return [
                'value'       => $user['email'] === null ? null : (string) $user['email'],
                'destination' => (string) $user['email'],
            ];
        }

        return [
            'value'       => $user['phone'] === null ? null : (string) $user['phone'],
            'destination' => $user['phone'] === null ? '' : (string) $user['phone_country_code'] . (string) $user['phone'],
        ];
    }

    /** @param array<string,mixed> $request */
    private static function destinationFor(array $request): string
    {
        return $request['channel'] === 'email'
            ? (string) $request['new_value']
            : (string) $request['new_phone_country_code'] . (string) $request['new_value'];
    }

    /** @param array<string,mixed> $request */
    private static function purposeFor(array $request): string
    {
        return self::PURPOSES[(string) $request['target']][(string) $request['channel']];
    }

    private static function sendBucket(int $userId, string $channel, string $target): string
    {
        return "contact:otp:send:{$userId}:{$channel}:{$target}";
    }

    /** @return array<string,mixed> */
    private static function loadRequest(int $requestId): array
    {
        $row = Database::selectOne(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM `contact_change_requests` WHERE `id` = :id',
            ['id' => $requestId]
        );

        if ($row === null) {
            throw HttpException::notFound('Contact change request not found.');
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private static function contactPoint(int $userId, int $pointId): ?array
    {
        foreach (self::contactPoints($userId, true) as $point) {
            if ($point['id'] === $pointId) {
                return $point;
            }
        }

        return null;
    }

    /**
     * A label must be a live `contact_label` term.
     *
     * NULL survives on purpose: the column is nullable precisely so that an
     * operator who empties the taxonomy does not thereby make it impossible to
     * add a contact at all.
     */
    private static function resolveLabel(mixed $label): ?string
    {
        $label = is_string($label) ? trim($label) : '';

        if ($label === '') {
            return null;
        }

        if (!TaxonomyService::isValid(self::TAXONOMY_LABEL, $label)) {
            throw HttpException::unprocessable("Not a contact label: {$label}");
        }

        return $label;
    }

    /** A reason must be a live `contact_change_reason` term, or nothing. */
    private static function resolveReason(mixed $reason): ?string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        if ($reason === '') {
            return null;
        }

        if (!TaxonomyService::isValid(self::TAXONOMY_REASON, $reason)) {
            throw HttpException::unprocessable("Not a contact change reason: {$reason}");
        }

        return $reason;
    }

    /**
     * Keep the verification badges in step with the identifier that just
     * moved. email_verified and mobile_verified are automatic badges read
     * straight off users.email_verified_at / phone_verified_at, so leaving
     * them alone would show a "Mobile verified" badge against a number the
     * account no longer holds.
     */
    private static function syncBadges(int $userId): void
    {
        $creatorId = self::creatorIdFor($userId);

        if ($creatorId !== null) {
            CreatorBadgeService::syncAutomatic($creatorId);
        }
    }

    /**
     * Append to the verification log.
     *
     * Same column vocabulary as kyc_events and creator_badge_events, so an
     * operator who has read one audit trail on this platform does not have to
     * learn a second to read this one.
     *
     * @param array<string,mixed> $context
     */
    private static function record(int $userId, string $action, array $context): void
    {
        $actorType = (string) ($context['actor_type'] ?? 'user');

        Database::insert('contact_verification_events', [
            'user_id'     => $userId,
            'request_id'  => isset($context['request_id']) ? (int) $context['request_id'] : null,
            'channel'     => $context['channel'] ?? null,
            'target'      => $context['target'] ?? null,
            // Stored in full. This is an audit record, and a masked audit
            // record answers no question worth asking — the admin screen can
            // mask on display, but the row has to be able to prove which
            // address was involved.
            'destination' => isset($context['destination']) ? mb_substr((string) $context['destination'], 0, 191) : null,
            'actor_type'  => in_array($actorType, ['user', 'admin', 'system'], true) ? $actorType : 'system',
            'actor_id'    => isset($context['actor_id']) ? (int) $context['actor_id'] : ($actorType === 'user' ? $userId : null),
            'actor_name'  => isset($context['actor_name']) ? mb_substr((string) $context['actor_name'], 0, 150) : null,
            'action'      => $action,
            'from_status' => $context['from_status'] ?? null,
            'to_status'   => $context['to_status'] ?? null,
            'note'        => isset($context['note']) && $context['note'] !== null
                ? mb_substr((string) $context['note'], 0, 1000)
                : null,
            'metadata'    => isset($context['metadata']) ? json_encode($context['metadata']) : null,
            'ip_address'  => self::packIp($context['ip'] ?? null),
            'user_agent'  => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 255) : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /** VARBINARY(16) holds both families; a malformed address stores NULL. */
    private static function packIp(mixed $ip): ?string
    {
        if (!is_string($ip) || $ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);

        return $packed === false ? null : $packed;
    }
}

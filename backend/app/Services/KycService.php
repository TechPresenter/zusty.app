<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Core\Request;
use Zuste\Core\ValidationException;
use Zuste\Helpers\KycIdentifiers;

/**
 * KYC and business verification.
 *
 * Owns the eight-state workflow, requirement completeness, the blue tick, the
 * retention rules and the audit trail. Every state change goes through
 * {@see transition()} so that no caller can move a profile into an illegal
 * state, and so that each change is logged with its actor, IP, and timestamp.
 *
 * State machine:
 *
 *   draft         -> submitted
 *   submitted     -> under_review | approved | rejected | info_required
 *   under_review  -> approved | rejected | info_required
 *   info_required -> submitted
 *   rejected      -> submitted
 *   approved      -> suspended | expired
 *   suspended     -> under_review (reinstate) | approved | rejected | info_required
 *   expired       -> submitted
 *
 * Rules every decision follows:
 *
 *   * A decision runs as ONE transaction holding a row lock on the profile
 *     (SELECT ... FOR UPDATE). The status is checked on the locked row, every
 *     column is written and the status changes inside it, so two reviewers
 *     acting at once are serialised: the second is refused on the status the
 *     first left, instead of half-applying over it.
 *   * A same-state decision is REFUSED (409 `kyc_already_in_state`): approving
 *     an approved profile, rejecting a rejected one, asking an info_required
 *     one for information again. None of them is a silent no-op that still
 *     rewrites columns, and none can change a profile without a kyc_events row.
 *   * There is no automatic approval. approve() needs a reviewer; the
 *     `kyc.auto_approve` setting is kept but permanently ignored.
 *   * Notifications go out after the decision commits and can never undo or
 *     fail it ({@see notify()}).
 *
 * KYC approval and the public blue tick are two separate decisions. Approval
 * says "these documents check out"; the tick says "an administrator chose to
 * vouch for this account in public". Only {@see grantBadge()} turns the tick
 * on, only for a currently approved, unexpired KYC (`kyc.badge_requires_kyc`
 * is kept but permanently ignored), and rejection, suspension, expiry and
 * purge all turn it off. The KYC-derived public badges ("KYC verified",
 * "Identity verified", "Business verified") are re-synced from the profile
 * after every decision ({@see syncKycBadges()}).
 */
final class KycService
{
    public const KIND_BRAND   = 'brand';
    public const KIND_CREATOR = 'creator';

    public const DRAFT         = 'draft';
    public const SUBMITTED     = 'submitted';
    public const UNDER_REVIEW  = 'under_review';
    public const INFO_REQUIRED = 'info_required';
    public const APPROVED      = 'approved';
    public const REJECTED      = 'rejected';
    public const SUSPENDED     = 'suspended';
    public const EXPIRED       = 'expired';

    public const BADGE_NONE    = 'none';
    public const BADGE_GRANTED = 'granted';
    public const BADGE_REVOKED = 'revoked';

    /** Default consent wording when the setting has not been seeded. */
    public const DEFAULT_CONSENT_TEXT =
        'I consent to Zusty processing my identity documents to verify my account, as described in the Privacy Policy.';

    /**
     * Legal transitions. Anything not listed here is rejected outright.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        self::DRAFT         => [self::SUBMITTED],
        self::SUBMITTED     => [self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::INFO_REQUIRED],
        self::UNDER_REVIEW  => [self::APPROVED, self::REJECTED, self::INFO_REQUIRED],
        self::INFO_REQUIRED => [self::SUBMITTED],
        self::REJECTED      => [self::SUBMITTED],
        self::APPROVED      => [self::SUSPENDED, self::EXPIRED],
        // Suspension is not a dead end: a reviewer can reinstate it for a
        // fresh review, ask for new documents, reject it (so the user can
        // correct and resubmit), or approve it outright.
        self::SUSPENDED     => [self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::INFO_REQUIRED],
        self::EXPIRED       => [self::SUBMITTED],
    ];

    /** How the review team is named to an applicant. Reviewers are never named. */
    public const TEAM_NAME = 'Zusty team';

    /**
     * Audit actions that are internal to the review team: kept in kyc_events
     * for the admin trail, never served on the applicant's own timeline.
     * Document views and downloads are access logs, not status history.
     */
    private const INTERNAL_ACTIONS = [
        'note_added', 'document_previewed', 'document_downloaded', 'notification_failed',
    ];

    /**
     * Admin-written notes the applicant is meant to read — the remarks and
     * reasons the panel labels "sent to the user". Every other admin event
     * (a blue-tick grant note, an identifier release reason, anything new)
     * reaches the applicant as a fixed line instead of the reviewer's words.
     */
    private const APPLICANT_FACING_ADMIN_NOTES = [
        'under_review', 'approved', 'rejected', 'info_required', 'resubmission_requested',
        'suspended', 'reinstated', 'document_approved', 'document_rejected', 'badge_revoked',
    ];

    /** The Aadhaar slots whose copy must be attested masked before approval. */
    private const AADHAAR_SLOTS = ['aadhaar_front', 'aadhaar_back'];

    /** Fields whose values are encrypted at rest. */
    private const ENCRYPTED_BRAND_FIELDS   = ['gst_number', 'pan_number', 'cin_number'];
    private const ENCRYPTED_CREATOR_FIELDS = [
        'bank_account_no', 'bank_ifsc', 'upi_id', 'pan_number', 'id_proof_number',
    ];

    /**
     * Government identifiers that are validated, encrypted AND blind-indexed,
     * keyed by kind then field: [blind-index domain, hash column, label].
     *
     * PAN shares one domain across both kinds on purpose: a proprietor's
     * business PAN is their personal PAN, and "already linked to another
     * account" has to see across the two tables to mean anything.
     */
    private const IDENTIFIERS = [
        self::KIND_CREATOR => [
            'pan_number' => ['pan', 'pan_hash', 'PAN'],
        ],
        self::KIND_BRAND => [
            'pan_number' => ['pan', 'pan_hash', 'PAN'],
            'gst_number' => ['gstin', 'gst_hash', 'GSTIN'],
            'cin_number' => ['cin', 'cin_hash', 'CIN'],
        ],
    ];

    /** Columns that hold blind indexes — never part of any API payload. */
    private const HASH_COLUMNS = ['aadhaar_hash', 'pan_hash', 'gst_hash', 'cin_hash'];

    /**
     * Statuses in which a verification CLAIMS its identifiers for uniqueness:
     * it was submitted and is with the review team, waiting on its owner,
     * approved, or suspended. Drafts, rejected, expired and purged profiles do
     * not ({@see identifierTaken()}). Public so the admin conflict list
     * (Admin\KycController::identifierConflicts) applies the same rule.
     */
    public const CLAIMING_STATUSES = [
        self::SUBMITTED, self::UNDER_REVIEW, self::INFO_REQUIRED, self::APPROVED, self::SUSPENDED,
    ];

    /** Where each blind-index domain is held: [table, hash column]. */
    private const IDENTIFIER_HOLDERS = [
        'aadhaar' => [['kyc_creator_details', 'aadhaar_hash']],
        'pan'     => [['kyc_creator_details', 'pan_hash'], ['kyc_brand_details', 'pan_hash']],
        'gstin'   => [['kyc_brand_details', 'gst_hash']],
        'cin'     => [['kyc_brand_details', 'cin_hash']],
    ];

    /** releaseIdentifier(): field => kind => detail columns cleared. */
    private const RELEASABLE = [
        'aadhaar' => [self::KIND_CREATOR => ['aadhaar_last4', 'aadhaar_hash']],
        'pan'     => [
            self::KIND_CREATOR => ['pan_number', 'pan_hash'],
            self::KIND_BRAND   => ['pan_number', 'pan_hash'],
        ],
        'gstin'   => [self::KIND_BRAND => ['gst_number', 'gst_hash']],
        'cin'     => [self::KIND_BRAND => ['cin_number', 'cin_hash']],
    ];

    private const RELEASABLE_LABELS = ['aadhaar' => 'Aadhaar', 'pan' => 'PAN', 'gstin' => 'GSTIN', 'cin' => 'CIN'];

    /** URL-bearing detail fields that must be http(s), plus image fields that may also be site-relative. */
    private const WEB_URL_FIELDS   = ['website', 'portfolio_url', 'map_place_url'];
    private const IMAGE_URL_FIELDS = ['logo_url', 'cover_url', 'photo_url'];

    // -----------------------------------------------------------------
    // Profiles
    // -----------------------------------------------------------------

    /**
     * Fetch the user's profile for [$kind], creating an empty draft on first
     * access so the client always has something to edit.
     *
     * @return array<string,mixed>
     */
    public static function profileFor(int $userId, string $kind): array
    {
        $kind = self::normaliseKind($kind);

        $profile = Database::selectOne(
            'SELECT * FROM `kyc_profiles`
              WHERE `user_id` = :user_id AND `kind` = :kind AND `deleted_at` IS NULL',
            ['user_id' => $userId, 'kind' => $kind]
        );

        if ($profile !== null) {
            return $profile;
        }

        $id = Database::insert('kyc_profiles', [
            'user_id'    => $userId,
            'kind'       => $kind,
            'status'     => self::DRAFT,
            'reference'  => self::nextReference($kind),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Database::update('users', ['kyc_profile_id' => $id], ['id' => $userId]);

        return Database::selectOne('SELECT * FROM `kyc_profiles` WHERE `id` = :id', ['id' => $id])
            ?? throw HttpException::server('Could not start a verification profile.');
    }

    /**
     * Everything the client needs to render the verification screen.
     *
     * [$apiBase] is prefixed to each document's `preview_url`. The controller
     * passes the absolute API root of the current request so the app can
     * fetch it as-is; without one the URL is relative to the API root, which
     * an HTTP client with a base URL resolves the same way.
     *
     * @return array<string,mixed>
     */
    public static function overview(int $userId, string $kind, string $apiBase = ''): array
    {
        // A lapsed approval is expired the moment its owner looks, not at the
        // next nightly sweep — the screen must not say "Approved" (or show a
        // tick) for a verification the KYC gate already treats as expired.
        self::expireIfLapsed($userId);

        $profile      = self::profileFor($userId, $kind);
        $profileId    = (int) $profile['id'];
        $requirements = self::effectiveRequirements($profile);
        $documents    = self::documents($profileId);

        $completeness = self::completeness($requirements, $documents);

        // Keep the stored figure honest without a write on every read.
        if ((int) $profile['completeness'] !== $completeness) {
            Database::update('kyc_profiles', ['completeness' => $completeness], ['id' => $profileId]);
            $profile['completeness'] = $completeness;
        }

        $details   = self::details($profileId, (string) $profile['kind']);
        $canSubmit = self::canSubmit($profile, $requirements, $documents);

        $presented               = self::presentProfile($profile);
        $presented['can_submit'] = $canSubmit;

        return [
            'profile'      => $presented,
            'details'      => $details,
            'requirements' => self::presentRequirements($requirements, $documents, self::resubmitSlots($profile)),
            'documents'    => array_map(
                static fn (array $row): array => self::presentUserDocument($row, $userId, $apiBase),
                $documents
            ),
            'completeness' => $completeness,
            'can_submit'   => $canSubmit,
            'timeline'     => self::timeline($profileId),
        ];
    }

    /**
     * Save the brand or creator detail block. Only supplied keys are touched,
     * so the client can autosave one section at a time.
     *
     * Government identifiers are validated here — the server is the authority,
     * the app only mirrors the rules — and every failure is collected and
     * returned field-keyed in one ValidationException, so a form can mark all
     * of its bad fields at once instead of one per round trip.
     *
     * [$ip] / [$agent] are the request's, for the audit event: this is the
     * event that records a PAN, Aadhaar, GSTIN or bank account changing, and
     * without a source an account takeover reads exactly like the owner.
     * (When a caller omits them, recordEvent() takes them from the request
     * being served.)
     *
     * @param array<string,mixed> $data
     *
     * @throws ValidationException
     */
    public static function saveDetails(int $userId, string $kind, array $data, ?string $ip = null, ?string $agent = null): void
    {
        $kind    = self::normaliseKind($kind);
        $profile = self::profileFor($userId, $kind);

        self::assertEditable($profile);

        $profileId = (int) $profile['id'];
        $table     = $kind === self::KIND_BRAND ? 'kyc_brand_details' : 'kyc_creator_details';
        $encrypted = $kind === self::KIND_BRAND
            ? self::ENCRYPTED_BRAND_FIELDS
            : self::ENCRYPTED_CREATOR_FIELDS;

        $current = Database::selectOne(
            'SELECT * FROM `' . $table . '` WHERE `profile_id` = :id',
            ['id' => $profileId]
        ) ?? [];

        $payload = [];
        $errors  = [];

        // Blind indexes are server-derived; a client can never write one.
        foreach (self::HASH_COLUMNS as $column) {
            unset($data[$column]);
        }
        unset($data['aadhaar_last4']);

        if ($kind === self::KIND_CREATOR) {
            self::takeAadhaar($data, $current, $userId, $payload, $errors);
        }

        // Identifiers with a format rule and a uniqueness rule.
        $validIncoming = [];

        foreach (self::IDENTIFIERS[$kind] as $field => [$domain, $hashColumn, $label]) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            unset($data[$field]);

            if ($value === null || trim((string) $value) === '') {
                $payload[$field]      = null;
                $payload[$hashColumn] = null;
                continue;
            }

            $currentPlain = Crypto::decryptNullable($current[$field] ?? null);

            // The masked rendering the app was shown, sent straight back: the
            // user did not touch this field, so leave the stored value alone.
            if (KycIdentifiers::isMasked(
                (string) $value,
                $currentPlain === null ? null : Crypto::mask($currentPlain, 4)
            )) {
                continue;
            }

            $normalized = KycIdentifiers::normalize((string) $value);
            $error      = self::identifierError($kind, $field, $normalized);

            if ($error === null) {
                $hash = Crypto::blindIndex($domain, $normalized);

                if (self::identifierTaken($domain, $hash, $userId)) {
                    $error = 'This ' . $label . ' is already linked to another account.';
                }
            }

            if ($error !== null) {
                $errors[$field] = [$error];
                continue;
            }

            $validIncoming[$field] = $normalized;
            $payload[$field]       = Crypto::encrypt($normalized);
            $payload[$hashColumn]  = Crypto::blindIndex($domain, $normalized);
        }

        // A GSTIN embeds its holder's PAN (characters 3-12). When both are
        // known, they must agree — checked whenever either was just sent.
        if ($kind === self::KIND_BRAND
            && !isset($errors['gst_number'])
            && !isset($errors['pan_number'])
            && (isset($validIncoming['gst_number']) || isset($validIncoming['pan_number']))) {
            $gst = $validIncoming['gst_number'] ?? Crypto::decryptNullable($current['gst_number'] ?? null);
            $pan = array_key_exists('pan_number', $payload)
                ? ($validIncoming['pan_number'] ?? null)
                : Crypto::decryptNullable($current['pan_number'] ?? null);

            if ($gst !== null && $pan !== null && $pan !== ''
                && KycIdentifiers::panInGstin($gst) !== KycIdentifiers::normalize($pan)) {
                $errors[isset($validIncoming['gst_number']) ? 'gst_number' : 'pan_number'] = [
                    'The GSTIN must contain your company PAN (characters 3-12).',
                ];
            }
        }

        // Web addresses: only http(s). A `javascript:` URL saved here would be
        // rendered as a link in the admin panel.
        foreach (self::WEB_URL_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== ''
                && !self::isWebUrl($data[$field])) {
                $errors[$field] = ['Enter a web address starting with http:// or https://.'];
            }
        }

        foreach (self::IMAGE_URL_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== ''
                && !self::isWebUrl($data[$field])
                && preg_match('#^/(?!/)#', $data[$field]) !== 1) {
                $errors[$field] = ['Enter an image address starting with http:// or https://.'];
            }
        }

        if (isset($data['social_links']) && is_array($data['social_links'])) {
            $clean = [];

            foreach ($data['social_links'] as $network => $url) {
                if ($url === null || trim((string) $url) === '') {
                    continue;
                }

                if (!is_string($url) || !self::isWebUrl($url)) {
                    $errors['social_links'] = ['Social links must be full web addresses starting with http:// or https://.'];
                    break;
                }

                $clean[mb_substr((string) $network, 0, 40)] = trim($url);
            }

            $data['social_links'] = $clean;
        }

        if ($errors !== []) {
            $first = reset($errors);

            throw new ValidationException($errors, (string) ($first[0] ?? 'The given data was invalid.'));
        }

        foreach ($data as $column => $value) {
            if ($value === null) {
                $payload[$column] = null;
                continue;
            }

            if (in_array($column, $encrypted, true)) {
                $plain = trim((string) $value);

                if ($plain === '') {
                    $payload[$column] = null;
                    continue;
                }

                $currentPlain = Crypto::decryptNullable($current[$column] ?? null);

                // Same masked round-trip guard as the identifiers above: a
                // bank account number shown as XXXXXX1234 must not come back
                // and replace the real one.
                if (KycIdentifiers::isMasked(
                    $plain,
                    $currentPlain === null ? null : Crypto::mask($currentPlain, 4)
                )) {
                    continue;
                }

                $payload[$column] = Crypto::encrypt(strtoupper($plain));
                continue;
            }

            // JSON columns arrive as arrays from the validator.
            $payload[$column] = is_array($value) ? json_encode($value) : $value;
        }

        if ($payload === []) {
            return;
        }

        // A profile whose data the retention sweep erased starts over here.
        self::reopenIfPurged($profile, $userId, $ip, $agent);

        if ($current !== []) {
            Database::update($table, $payload, ['profile_id' => $profileId]);
        } else {
            Database::insert($table, $payload + ['profile_id' => $profileId]);
        }

        self::recordEvent($profileId, 'details_updated', [
            'actor_type' => 'user',
            'actor_id'   => $userId,
            'note'       => 'Profile details updated.',
            // Field NAMES only; the hash columns are implementation detail.
            'metadata'   => ['fields' => array_values(array_diff(
                array_keys($payload),
                array_merge(self::HASH_COLUMNS, ['aadhaar_last4'])
            ))],
            'ip'         => $ip,
            'agent'      => $agent,
        ]);
    }

    /**
     * Pull the Aadhaar out of the payload and turn it into the only two
     * things that are ever stored: the last four digits and a keyed hash.
     *
     * Older app builds send it as `id_proof_number` with `id_proof_type =
     * aadhaar`, which used to encrypt and keep the whole number. That route is
     * folded in here, so no client can store a full Aadhaar any more.
     *
     * The "other ID" number is the other way a full Aadhaar used to get in:
     * with the type unset (or set to passport, licence, voter ID) the value
     * skipped this method, was encrypted whole and shown unmasked to
     * reviewers. A 12-digit Aadhaar-shaped value there is now refused,
     * whatever the type says — no passport, licence or voter ID number has
     * that shape.
     *
     * @param array<string,mixed>       $data    Consumed in place.
     * @param array<string,mixed>       $current The stored detail row.
     * @param array<string,mixed>       $payload Columns to write.
     * @param array<string,list<string>> $errors
     */
    private static function takeAadhaar(array &$data, array $current, int $userId, array &$payload, array &$errors): void
    {
        $field = 'aadhaar_number';

        if (array_key_exists('id_proof_number', $data)) {
            $type = $data['id_proof_type'] ?? $current['id_proof_type'] ?? null;

            if ($type === 'aadhaar') {
                if (!array_key_exists('aadhaar_number', $data)) {
                    $data['aadhaar_number'] = $data['id_proof_number'];
                    $field                  = 'id_proof_number';
                }

                unset($data['id_proof_number']);
            } elseif (is_scalar($data['id_proof_number'])
                && KycIdentifiers::looksLikeAadhaar((string) $data['id_proof_number'])) {
                // Any separator ('.', '/', '_', ...) is ignored for this check.
                unset($data['id_proof_number']);

                $errors['id_proof_number'] = [
                    'That looks like an Aadhaar number. Enter it in the Aadhaar field instead - only its last four digits are kept.',
                ];
            }
        }

        if (!array_key_exists('aadhaar_number', $data)) {
            return;
        }

        $value = $data['aadhaar_number'];
        unset($data['aadhaar_number']);

        if ($value === null || trim((string) $value) === '') {
            $payload['aadhaar_last4'] = null;
            $payload['aadhaar_hash']  = null;

            return;
        }

        if (KycIdentifiers::isMasked(
            (string) $value,
            KycIdentifiers::maskAadhaarLast4($current['aadhaar_last4'] ?? null)
        )) {
            return;
        }

        $digits = KycIdentifiers::normalize((string) $value);

        if (!KycIdentifiers::isAadhaarShaped($digits)) {
            $errors[$field] = ['Enter your 12-digit Aadhaar number.'];

            return;
        }

        if (!KycIdentifiers::isValidAadhaar($digits)) {
            $errors[$field] = ['That Aadhaar number is not valid. Check the digits and try again.'];

            return;
        }

        $hash = Crypto::blindIndex('aadhaar', $digits);

        if (self::identifierTaken('aadhaar', $hash, $userId)) {
            $errors[$field] = ['This Aadhaar number is already linked to another account.'];

            return;
        }

        $payload['aadhaar_last4'] = substr($digits, -4);
        $payload['aadhaar_hash']  = $hash;
    }

    /** The format error for one identifier, or null when it is well-formed. */
    private static function identifierError(string $kind, string $field, string $value): ?string
    {
        return match ($field) {
            'pan_number' => match (true) {
                !KycIdentifiers::isValidPan($value) => $kind === self::KIND_CREATOR
                    ? 'Enter a valid PAN, e.g. ABCPE1234F.'
                    : 'Enter a valid PAN, e.g. ABCDE1234F.',
                $kind === self::KIND_CREATOR && !KycIdentifiers::isPersonalPan($value) =>
                    'Enter your personal PAN (the 4th character is P).',
                default => null,
            },
            'gst_number' => match (true) {
                !KycIdentifiers::isGstinShaped($value)      => 'Enter a valid 15-character GSTIN, e.g. 27ABCDE1234F1Z5.',
                !KycIdentifiers::gstinStateValid($value)    => 'The first two digits of the GSTIN must be a valid state code.',
                !KycIdentifiers::gstinChecksumValid($value) => 'That GSTIN\'s last character does not match. Check for a typo.',
                default                                     => null,
            },
            'cin_number' => KycIdentifiers::isValidCin($value)
                ? null
                : 'Enter a valid 21-character CIN, e.g. U12345MH2020PTC123456.',
            default => null,
        };
    }

    /**
     * Is this identifier CLAIMED by another account's verification?
     *
     * Only a verification that really claims it counts: one that has been
     * submitted and is in review, waiting on its owner, approved or
     * suspended ({@see CLAIMING_STATUSES}), on a live account. A draft, a
     * rejected or expired profile, a purged one and a deleted account do not.
     * This used to count drafts too, so anybody could type a company's public
     * GSTIN (or someone's PAN) into a draft, never submit, and lock the real
     * holder out of verification for good. Two drafts may now hold the same
     * number; the conflict is settled where it matters — at submission and
     * at approval ({@see identifierCollisions()}), and a reviewer can release
     * a number from the account that should not have it
     * ({@see releaseIdentifier()}).
     *
     * The answer never says whose — only that it is taken — so the check
     * cannot be used to discover who holds a given PAN.
     */
    private static function identifierTaken(string $domain, string $hash, int $userId): bool
    {
        [$in, $statuses] = Database::inClause(self::CLAIMING_STATUSES);

        foreach (self::IDENTIFIER_HOLDERS[$domain] ?? [] as [$table, $column]) {
            $taken = (int) Database::scalar(
                'SELECT COUNT(*) FROM `' . $table . '` d
                   JOIN `kyc_profiles` p ON p.`id` = d.`profile_id`
                   JOIN `users` u ON u.`id` = p.`user_id`
                  WHERE d.`' . $column . '` = ?
                    AND p.`user_id` <> ?
                    AND p.`deleted_at` IS NULL
                    AND p.`purged_at` IS NULL
                    AND u.`deleted_at` IS NULL
                    AND p.`status` IN ' . $in,
                array_merge([$hash, $userId], $statuses)
            );

            if ($taken > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The identifiers on this profile that another account's verification
     * also claims — labels such as "PAN" or "GSTIN", empty when there are none.
     *
     * Re-checked from what is stored at submission and at approval, so two
     * accounts can never both be approved on one identity, whatever order
     * their drafts were saved in. A value saved before blind indexes existed
     * (hash column NULL) is hashed here from its decrypted value rather than
     * getting a pass.
     *
     * @return list<string>
     */
    public static function identifierCollisions(int $profileId): array
    {
        $profile = Database::selectOne('SELECT `id`, `user_id`, `kind` FROM `kyc_profiles` WHERE `id` = :id', ['id' => $profileId]);

        if ($profile === null) {
            return [];
        }

        $collisions = [];

        foreach (self::storedIdentifierHashes($profileId, (string) $profile['kind']) as $domain => [$hash, $label]) {
            if (self::identifierTaken($domain, $hash, (int) $profile['user_id'])) {
                $collisions[] = $label;
            }
        }

        return $collisions;
    }

    /**
     * The blind index of every identifier stored on a profile, by domain:
     * domain => [hash, label]. A missing hash is derived from the decrypted
     * value; an Aadhaar has only its hash (the number is never stored).
     *
     * @return array<string,array{0:string,1:string}>
     */
    private static function storedIdentifierHashes(int $profileId, string $kind): array
    {
        $kind  = self::normaliseKind($kind);
        $table = $kind === self::KIND_BRAND ? 'kyc_brand_details' : 'kyc_creator_details';
        $row   = Database::selectOne('SELECT * FROM `' . $table . '` WHERE `profile_id` = :id', ['id' => $profileId]);

        if ($row === null) {
            return [];
        }

        $hashes = [];

        foreach (self::IDENTIFIERS[$kind] as $field => [$domain, $hashColumn, $label]) {
            $hash = (string) ($row[$hashColumn] ?? '');

            if ($hash === '') {
                $plain = Crypto::decryptNullable($row[$field] ?? null);
                $hash  = $plain === null || trim($plain) === ''
                    ? ''
                    : Crypto::blindIndex($domain, KycIdentifiers::normalize($plain));
            }

            if ($hash !== '') {
                $hashes[$domain] = [$hash, $label];
            }
        }

        if ($kind === self::KIND_CREATOR && (string) ($row['aadhaar_hash'] ?? '') !== '') {
            $hashes['aadhaar'] = [(string) $row['aadhaar_hash'], 'Aadhaar'];
        }

        return $hashes;
    }

    /**
     * Release an Aadhaar, PAN, GSTIN or CIN from this verification — an
     * administrator's way out of "already linked to another account" when
     * the account holding the number should not have it.
     *
     * Clears the number (for an Aadhaar: its last four digits) and its blind
     * index on this profile, under the profile lock, and records an
     * `identifier_released` event with the admin as actor and the reason as
     * the note. The number itself is never written anywhere: not to the event,
     * not to a log. The applicant sees a fixed line on their trail
     * ({@see publicNote()}), never the reviewer's reason.
     *
     * [$field] is aadhaar | pan | gstin | cin.
     */
    public static function releaseIdentifier(int $profileId, string $field, int $adminId, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw HttpException::unprocessable('Say why the identifier is being released — it goes into the audit trail.');
        }

        if ($adminId <= 0) {
            throw HttpException::forbidden('Only a reviewer can release an identifier.');
        }

        $columns = self::RELEASABLE[$field] ?? null;

        if ($columns === null) {
            throw HttpException::unprocessable('Choose the Aadhaar, PAN, GSTIN or CIN to release.');
        }

        self::withLockedProfile($profileId, static function (array $profile) use ($profileId, $field, $columns, $adminId, $reason): void {
            $kind = (string) $profile['kind'];

            if (!isset($columns[$kind])) {
                throw HttpException::unprocessable(
                    'A ' . $kind . ' verification has no ' . self::RELEASABLE_LABELS[$field] . ' to release.'
                );
            }

            $table = $kind === self::KIND_BRAND ? 'kyc_brand_details' : 'kyc_creator_details';
            $row   = Database::selectOne('SELECT * FROM `' . $table . '` WHERE `profile_id` = :id', ['id' => $profileId]);

            $held = false;

            foreach ($columns[$kind] as $column) {
                if ($row !== null && ($row[$column] ?? null) !== null && $row[$column] !== '') {
                    $held = true;
                }
            }

            if (!$held) {
                throw HttpException::conflict(
                    'This verification holds no ' . self::RELEASABLE_LABELS[$field] . '.',
                    'identifier_not_held'
                );
            }

            Database::update($table, array_fill_keys($columns[$kind], null), ['profile_id' => $profileId]);

            self::recordEvent($profileId, 'identifier_released', [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => mb_substr($reason, 0, 1000),
                'metadata'   => ['field' => $field],
            ]);
        });
    }

    private static function isWebUrl(string $value): bool
    {
        $value = trim($value);

        return preg_match('#^https?://#i', $value) === 1
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Decrypted detail block, with sensitive values masked unless the caller is
     * a reviewer who has explicitly been granted full visibility.
     *
     * The `*_masked` keys are ALWAYS masked, whoever asks, and an Aadhaar is
     * only ever available masked — its full number is not stored at all.
     *
     * @return array<string,mixed>
     */
    public static function details(int $profileId, string $kind, bool $unmask = false): array
    {
        $kind  = self::normaliseKind($kind);
        $table = $kind === self::KIND_BRAND ? 'kyc_brand_details' : 'kyc_creator_details';

        $row = Database::selectOne(
            'SELECT * FROM `' . $table . '` WHERE `profile_id` = :id',
            ['id' => $profileId]
        );

        if ($row === null) {
            return [];
        }

        $encrypted = $kind === self::KIND_BRAND
            ? self::ENCRYPTED_BRAND_FIELDS
            : self::ENCRYPTED_CREATOR_FIELDS;

        $masked = [];

        foreach ($encrypted as $field) {
            $plain = Crypto::decryptNullable($row[$field] ?? null);

            $masked[$field] = $plain === null ? null : Crypto::mask($plain, 4);
            $row[$field]    = $plain === null
                ? null
                : ($unmask ? $plain : $masked[$field]);
        }

        if ($kind === self::KIND_BRAND) {
            $row['gst_masked']   = $masked['gst_number'];
            $row['cin_masked']   = $masked['cin_number'];
            $row['pan_masked']   = $masked['pan_number'];
            $row['cin_required'] = KycIdentifiers::companyTypeRequiresCin($row['company_type'] ?? null);
        } else {
            $row['aadhaar_masked'] = KycIdentifiers::maskAadhaarLast4($row['aadhaar_last4'] ?? null);
            $row['pan_masked']     = $masked['pan_number'];
        }

        foreach (['social_links', 'languages', 'categories', 'audience_stats'] as $jsonField) {
            if (array_key_exists($jsonField, $row)) {
                $row[$jsonField] = json_decode((string) ($row[$jsonField] ?? 'null'), true) ?: null;
            }
        }

        foreach (self::HASH_COLUMNS as $column) {
            unset($row[$column]);
        }

        unset($row['id'], $row['profile_id'], $row['aadhaar_last4']);

        return $row;
    }

    // -----------------------------------------------------------------
    // Requirements and documents
    // -----------------------------------------------------------------

    /**
     * The document rules for one side of the marketplace.
     *
     * Callers that ASK for documents (the app, completeness, submission) want
     * only the live rules, which stays the default. The settings screen passes
     * [$includeInactive] so a rule that was switched off can be switched back
     * on — without it, deactivating a requirement hides it from the only screen
     * that could ever re-enable it.
     *
     * @return list<array<string,mixed>>
     */
    public static function requirements(string $kind, bool $includeInactive = false): array
    {
        return Database::select(
            'SELECT * FROM `kyc_requirements`
              WHERE `kind` = :kind' . ($includeInactive ? '' : ' AND `is_active` = 1') . '
              ORDER BY `sort_order`, `id`',
            ['kind' => self::normaliseKind($kind)]
        );
    }

    /**
     * The rules as they apply to THIS profile.
     *
     * The Certificate of Incorporation is marked required in the table, but a
     * proprietorship, partnership or LLP has no CIN and so no such
     * certificate; demanding one would make those brands unverifiable. The
     * profile's company type relaxes it. An unchosen type keeps it required,
     * so the checklist never under-states what will be asked for.
     *
     * @param  array<string,mixed> $profile
     * @return list<array<string,mixed>>
     */
    public static function effectiveRequirements(array $profile): array
    {
        $kind         = self::normaliseKind((string) $profile['kind']);
        $requirements = self::requirements($kind);

        if ($kind !== self::KIND_BRAND) {
            return $requirements;
        }

        $companyType = Database::scalar(
            'SELECT `company_type` FROM `kyc_brand_details` WHERE `profile_id` = :id',
            ['id' => (int) $profile['id']]
        );

        $needsCin = KycIdentifiers::companyTypeRequiresCin($companyType === null ? null : (string) $companyType);

        foreach ($requirements as &$requirement) {
            if ($requirement['slot'] === 'incorporation_certificate' && !$needsCin) {
                $requirement['is_required'] = 0;
            }
        }
        unset($requirement);

        return $requirements;
    }

    public static function requirement(string $kind, string $slot): array
    {
        $row = Database::selectOne(
            'SELECT * FROM `kyc_requirements`
              WHERE `kind` = :kind AND `slot` = :slot AND `is_active` = 1',
            ['kind' => self::normaliseKind($kind), 'slot' => $slot]
        );

        return $row ?? throw HttpException::unprocessable('That document slot is not recognised.');
    }

    /**
     * The live documents: current version, not removed, blob not purged.
     *
     * @return list<array<string,mixed>>
     */
    public static function documents(int $profileId): array
    {
        return Database::select(
            'SELECT * FROM `kyc_documents`
              WHERE `profile_id` = :id AND `is_current` = 1 AND `deleted_at` IS NULL
                AND `purged_at` IS NULL
              ORDER BY `slot`, `id`',
            ['id' => $profileId]
        );
    }

    /**
     * Every version of every document, for the reviewer's history view.
     *
     * @return list<array<string,mixed>>
     */
    public static function documentHistory(int $profileId): array
    {
        return Database::select(
            'SELECT * FROM `kyc_documents`
              WHERE `profile_id` = :id
              ORDER BY `slot`, `version` DESC, `id` DESC',
            ['id' => $profileId]
        );
    }

    /**
     * Attach an uploaded file to a requirement slot.
     *
     * Uploading into a slot that already holds a single-file requirement
     * supersedes the previous version rather than deleting it, so reviewers can
     * still see what was originally submitted. [$replaceDocumentId] names the
     * exact document being replaced, which is how a file in a multi-file slot
     * is re-uploaded without stacking a duplicate next to it.
     *
     * A re-upload the reviewer asked for while the profile is still with them
     * (a document rejected mid-review — see reviewDocument()) is accepted into
     * that slot only, and answers the request: the slot leaves
     * `resubmit_slots`, so the reviewer sees a fresh pending copy.
     *
     * @param array<string,mixed> $file One de-normalised entry from $_FILES.
     */
    public static function attachDocument(
        int $userId,
        string $kind,
        string $slot,
        array $file,
        ?string $expiresOn = null,
        ?string $ip = null,
        ?int $replaceDocumentId = null,
        ?string $agent = null
    ): array {
        $kind        = self::normaliseKind($kind);
        $profile     = self::profileFor($userId, $kind);
        $profileId   = (int) $profile['id'];
        $requirement = self::requirement($kind, $slot);

        self::assertCanUpload($profile, $slot);

        $replacing = null;

        if ($replaceDocumentId !== null && $replaceDocumentId > 0) {
            $replacing = Database::selectOne(
                'SELECT * FROM `kyc_documents`
                  WHERE `id` = :id AND `profile_id` = :profile_id AND `user_id` = :user_id
                    AND `is_current` = 1 AND `deleted_at` IS NULL AND `purged_at` IS NULL',
                ['id' => $replaceDocumentId, 'profile_id' => $profileId, 'user_id' => $userId]
            );

            if ($replacing === null) {
                throw HttpException::notFound('The document you are replacing was not found.');
            }

            if ((string) $replacing['slot'] !== $slot) {
                throw HttpException::unprocessable('That document belongs to a different slot.');
            }
        }

        if ((int) $requirement['requires_expiry'] === 1 && ($expiresOn === null || $expiresOn === '')) {
            throw HttpException::unprocessable(
                (string) $requirement['label'] . ' needs an expiry date.'
            );
        }

        // Checked before a byte is written, and again under the lock below.
        self::assertWithinUploadCaps($profileId, $userId, $requirement, $replacing !== null, (int) ($file['size'] ?? 0));

        $stored = DocumentVault::store($file, $requirement, $userId);

        return Database::transaction(
            static function () use (
                $profileId, $userId, $requirement, $slot, $stored, $expiresOn, $ip, $agent, $replacing
            ): array {
                // Re-checked on the locked row: a reviewer's decision (or a
                // second upload answering the same request) may have landed
                // since the check above. The stored blob of a refused upload
                // is deleted here, not left for the retention sweep.
                $locked = Database::lockForUpdate('kyc_profiles', 'id', $profileId);

                try {
                    if ($locked !== null) {
                        self::assertCanUpload($locked, $slot);
                    }

                    // The lock serialises this account's uploads, so two
                    // parallel requests cannot both slip under a cap. No
                    // purging in here (see assertWithinUploadCaps()): the
                    // check before the transaction already did it.
                    self::assertWithinUploadCaps($profileId, $userId, $requirement, $replacing !== null, (int) $stored['size'], false);
                } catch (HttpException $e) {
                    // A refusal (not a deadlock, which is retried with the
                    // same blob): the stored copy goes at once.
                    DocumentVault::purge((string) $stored['path']);

                    throw $e;
                }

                if ($locked !== null) {
                    self::reopenIfPurged($locked, $userId, $ip, $agent);
                }

                $version  = 1;
                $previous = $replacing;

                // Single-file slots keep one live document; multi slots stack
                // unless a specific document is being replaced.
                if ($previous === null && (int) $requirement['allows_multiple'] === 0) {
                    $previous = Database::selectOne(
                        'SELECT `id`, `version` FROM `kyc_documents`
                          WHERE `profile_id` = :profile_id AND `slot` = :slot
                            AND `is_current` = 1 AND `deleted_at` IS NULL
                          ORDER BY `version` DESC LIMIT 1',
                        ['profile_id' => $profileId, 'slot' => $slot]
                    );
                }

                if ($previous !== null) {
                    $version = (int) $previous['version'] + 1;
                    Database::update(
                        'kyc_documents',
                        ['is_current' => 0],
                        ['id' => (int) $previous['id']]
                    );
                }

                $documentId = Database::insert('kyc_documents', [
                    'user_id'       => $userId,
                    'profile_id'    => $profileId,
                    'document_type' => $requirement['document_type'],
                    'slot'          => $slot,
                    'title'         => $requirement['label'],
                    'file_path'     => $stored['path'],
                    'original_name' => $stored['original_name'],
                    'mime_type'     => $stored['mime'],
                    'file_size'     => $stored['size'],
                    'checksum'      => $stored['checksum'],
                    'is_encrypted'  => 1,
                    'version'       => $version,
                    'is_current'    => 1,
                    'status'        => 'pending',
                    'expires_on'    => $expiresOn ?: null,
                    'uploaded_ip'   => $ip === null ? null : (@inet_pton($ip) ?: null),
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);

                if ($previous !== null) {
                    Database::update('kyc_documents', ['replaced_by' => $documentId], ['id' => (int) $previous['id']]);
                }

                self::recordEvent($profileId, $previous !== null ? 'document_replaced' : 'document_uploaded', [
                    'actor_type' => 'user',
                    'actor_id'   => $userId,
                    'note'       => (string) $requirement['label'] . ($previous !== null ? ' re-uploaded.' : ' uploaded.'),
                    'metadata'   => array_filter([
                        'slot'        => $slot,
                        'version'     => $version,
                        'document_id' => $documentId,
                        'replaces'    => $previous !== null ? (int) $previous['id'] : null,
                    ], static fn ($v): bool => $v !== null),
                    'ip'         => $ip,
                    'agent'      => $agent,
                ]);

                // A re-upload into a slot the reviewer reopened while the
                // profile is with them answers that request: the slot stops
                // being "awaiting re-upload", so the user cannot keep stacking
                // versions under the reviewer and the app stops flagging it.
                if ($locked !== null && !self::isEditable((string) $locked['status'])) {
                    $remaining = array_values(array_diff(self::resubmitSlots($locked), [$slot]));

                    if ($remaining !== self::resubmitSlots($locked)) {
                        Database::update('kyc_profiles', [
                            'resubmit_slots' => $remaining === [] ? null : json_encode($remaining),
                        ], ['id' => $profileId]);
                    }
                }

                $row = Database::selectOne(
                    'SELECT * FROM `kyc_documents` WHERE `id` = :id',
                    ['id' => $documentId]
                );

                return self::presentUserDocument($row ?? [], $userId, '');
            }
        );
    }

    /** Soft-delete a document and purge its blob. [$ip] / [$agent] go on the audit event. */
    public static function removeDocument(int $userId, int $documentId, ?string $ip = null, ?string $agent = null): void
    {
        $document = Database::selectOne(
            'SELECT * FROM `kyc_documents`
              WHERE `id` = :id AND `user_id` = :user_id AND `deleted_at` IS NULL',
            ['id' => $documentId, 'user_id' => $userId]
        );

        if ($document === null) {
            throw HttpException::notFound('That document was not found.');
        }

        $profile = Database::selectOne(
            'SELECT * FROM `kyc_profiles` WHERE `id` = :id',
            ['id' => (int) $document['profile_id']]
        );

        if ($profile !== null) {
            self::assertEditable($profile);
        }

        Database::update('kyc_documents', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_current' => 0,
        ], ['id' => $documentId]);

        // The bytes go, and with them the path, the stored file name, the
        // number and the upload IP — the row stays only as the record that a
        // document was there. (Removal used to set purged_at and keep all of
        // those, and the retention sweep only blanks rows not yet purged.)
        self::eraseDocument($document);

        if ($profile !== null) {
            self::recordEvent((int) $profile['id'], 'document_removed', [
                'actor_type' => 'user',
                'actor_id'   => $userId,
                'note'       => (string) ($document['title'] ?? 'Document') . ' removed.',
                'metadata'   => ['slot' => $document['slot']],
                'ip'         => $ip,
                'agent'      => $agent,
            ]);
        }
    }

    /**
     * Normalise a stored document status onto the three the product speaks:
     * pending | approved | rejected. Legacy rows carry `verified` and
     * `under_review`.
     */
    public static function documentStatus(?string $status): string
    {
        return match ($status) {
            'approved', 'verified' => 'approved',
            'rejected'             => 'rejected',
            default                => 'pending',
        };
    }

    /**
     * Group the live documents by slot, ignoring rejected ones.
     *
     * @param  list<array<string,mixed>> $documents
     * @return array<string,int> slot => number of live, non-rejected documents
     */
    private static function acceptableBySlot(array $documents): array
    {
        $bySlot = [];

        foreach ($documents as $document) {
            if (self::documentStatus($document['status'] ?? null) === 'rejected') {
                continue;
            }

            $slot          = (string) $document['slot'];
            $bySlot[$slot] = ($bySlot[$slot] ?? 0) + 1;
        }

        return $bySlot;
    }

    /**
     * Percentage of *required* slots that hold a live, non-rejected document.
     *
     * @param list<array<string,mixed>> $requirements
     * @param list<array<string,mixed>> $documents
     */
    public static function completeness(array $requirements, array $documents): int
    {
        $required = array_values(array_filter(
            $requirements,
            static fn (array $r): bool => (int) $r['is_required'] === 1
        ));

        if ($required === []) {
            return 100;
        }

        $filled = self::acceptableBySlot($documents);
        $have   = 0;

        foreach ($required as $requirement) {
            if (isset($filled[(string) $requirement['slot']])) {
                $have++;
            }
        }

        return (int) floor($have / count($required) * 100);
    }

    /**
     * Required slots with no live, non-rejected document yet.
     *
     * @param  list<array<string,mixed>> $requirements
     * @param  list<array<string,mixed>> $documents
     * @return list<string>
     */
    public static function missingSlots(array $requirements, array $documents): array
    {
        $filled  = self::acceptableBySlot($documents);
        $present = array_flip(array_map('strval', array_column($documents, 'slot')));
        $missing = [];

        foreach ($requirements as $requirement) {
            $slot = (string) $requirement['slot'];

            if ((int) $requirement['is_required'] === 1 && !isset($filled[$slot])) {
                $missing[] = (string) $requirement['label']
                    . (isset($present[$slot]) ? ' (rejected — upload a new one)' : '');
            }
        }

        return $missing;
    }

    // -----------------------------------------------------------------
    // Workflow
    // -----------------------------------------------------------------

    /**
     * Move a profile from draft/rejected/info_required/expired into the
     * review queue.
     *
     * [$consent] is the applicant's explicit agreement to their identity
     * documents being processed (DPDP Act 2023). It is recorded with the
     * version of the wording they saw, and a submission without it is refused.
     *
     * Runs under a lock on the profile row: two taps on "Submit" (or two
     * devices) used to both pass the status guard, both bump the counter and
     * both notify. The second now waits, re-reads the status the first left,
     * and is refused.
     *
     * There is no auto-approval: `kyc.auto_approve` is permanently inert, so
     * every submission waits for a human reviewer.
     */
    public static function submit(
        int $userId,
        string $kind,
        ?string $ip = null,
        ?string $agent = null,
        bool $consent = false
    ): array {
        $kind      = self::normaliseKind($kind);
        $profileId = (int) self::profileFor($userId, $kind)['id'];

        $reference = self::withLockedProfile($profileId, static function (array $profile) use (
            $userId, $kind, $profileId, $ip, $agent, $consent
        ): string {
            // A profile already with a reviewer must not be resubmitted: it
            // would bump submission_count, clear the reviewer's notes, and
            // reset the clock on work already in progress. The guard comes
            // before any write.
            if (!in_array((string) $profile['status'], [self::DRAFT, self::INFO_REQUIRED, self::REJECTED, self::EXPIRED], true)) {
                throw HttpException::conflict(
                    match ((string) $profile['status']) {
                        self::APPROVED  => 'Your verification is already approved.',
                        self::SUSPENDED => 'Your verification is suspended. Contact support to restore it.',
                        default         => 'This profile is already with our review team.',
                    }
                );
            }

            $requirements = self::effectiveRequirements($profile);
            $documents    = self::documents($profileId);
            $missing      = self::missingSlots($requirements, $documents);

            if ($missing !== []) {
                throw HttpException::unprocessable(
                    'Still needed: ' . implode(', ', $missing) . '.'
                );
            }

            $notReuploaded = self::slotsAwaitingReupload($profile, $documents);

            if ($notReuploaded !== []) {
                throw HttpException::unprocessable(
                    'Upload a new copy of: ' . implode(', ', $notReuploaded) . ' before resubmitting.'
                );
            }

            $issues = self::detailIssues($profileId, $kind);

            if ($issues !== []) {
                throw HttpException::unprocessable(
                    'Complete your profile details before submitting: ' . implode(', ', $issues) . '.'
                );
            }

            // A draft may hold a number another account also typed in; the
            // first to submit claims it. Never says whose account it is.
            $taken = self::identifierCollisions($profileId);

            if ($taken !== []) {
                throw new HttpException(
                    (count($taken) === 1 ? 'This ' . $taken[0] . ' is' : 'These numbers (' . implode(', ', $taken) . ') are')
                        . ' already linked to another account. Check it, or contact support if it is yours.',
                    422,
                    'kyc_identifier_taken'
                );
            }

            if (!$consent) {
                throw ValidationException::forField(
                    'consent',
                    'Confirm that you consent to your documents being processed for verification.'
                );
            }

            Database::update('kyc_profiles', [
                'completeness'     => self::completeness($requirements, $documents),
                'submission_count' => (int) $profile['submission_count'] + 1,
                'submitted_at'     => date('Y-m-d H:i:s'),
                'rejection_reason' => null,
                'info_requested'   => null,
                'resubmit_slots'   => null,
                'consent_at'       => date('Y-m-d H:i:s'),
                'consent_version'  => mb_substr(self::consentVersion(), 0, 20),
                'last_ip'          => $ip === null ? null : (@inet_pton($ip) ?: null),
                'last_user_agent'  => $agent === null ? null : mb_substr($agent, 0, 255),
            ], ['id' => $profileId]);

            Database::update('users', ['kyc_status' => 'submitted'], ['id' => $userId]);

            self::transition($profileId, self::SUBMITTED, [
                'actor_type' => 'user',
                'actor_id'   => $userId,
                'note'       => 'Submitted for review.',
                'metadata'   => ['consent_version' => self::consentVersion()],
                'ip'         => $ip,
                'agent'      => $agent,
            ]);

            return (string) $profile['reference'];
        });

        self::notify($userId, 'kyc.submitted', ['reference' => $reference], $profileId);

        return self::overview($userId, $kind);
    }

    /**
     * Reviewer opens a submission (submitted -> under_review).
     *
     * A no-op for any other status: opening a profile that is already under
     * review, or decided, must not move it. That is "nothing to do", not a
     * decision, so it is not refused.
     */
    public static function startReview(int $profileId, ?int $adminId, array $context = []): void
    {
        // Cheap unlocked look first: this runs on every detail-page view.
        if (self::find($profileId)['status'] !== self::SUBMITTED) {
            return;
        }

        $profile = self::withLockedProfile($profileId, static function (array $profile) use ($profileId, $adminId, $context): ?array {
            if ($profile['status'] !== self::SUBMITTED) {
                return null;
            }

            // Record who picked it up. Without this, `reviewed_by` keeps whatever a
            // previous decision left behind (or stays NULL), so both the queue's
            // "Reviewer" column and the ?reviewer= filter name the wrong admin.
            Database::update('kyc_profiles', [
                'reviewed_by' => $adminId,
                'reviewed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $profileId]);

            Database::update('users', ['kyc_status' => 'under_review'], ['id' => (int) $profile['user_id']]);

            self::transition($profileId, self::UNDER_REVIEW, [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => 'Review started.',
            ] + self::auditFields($context));

            return $profile;
        });

        if ($profile !== null) {
            self::notify((int) $profile['user_id'], 'kyc.under_review', [
                'reference' => $profile['reference'],
            ], $profileId);
        }
    }

    /**
     * What stands between this profile and an approval, in words a reviewer
     * can act on. Empty means it may be approved.
     *
     * Every required slot needs a live document that has not been rejected
     * (pending or approved), and the identity details must validate. Bulk
     * approval runs the same check, so a batch cannot approve what the detail
     * screen would refuse.
     *
     * @return list<string>
     */
    public static function approvalBlockers(int $profileId): array
    {
        $profile      = self::find($profileId);
        $requirements = self::effectiveRequirements($profile);
        $documents    = self::documents($profileId);
        $blockers     = [];

        $missing = self::missingSlots($requirements, $documents);

        if ($missing !== []) {
            $blockers[] = 'Documents still needed: ' . implode(', ', $missing) . '.';
        }

        $issues = self::detailIssues($profileId, (string) $profile['kind']);

        if ($issues !== []) {
            $blockers[] = 'Details missing or invalid: ' . implode(', ', $issues) . '.';
        }

        // Two accounts must never both be approved on one identity.
        $taken = self::identifierCollisions($profileId);

        if ($taken !== []) {
            $blockers[] = 'Also claimed by another account\'s verification: ' . implode(', ', $taken)
                . '. Release it from the account that should not hold it, or reject this one.';
        }

        if ($profile['purged_at'] !== null) {
            $blockers[] = 'This profile\'s documents were purged.';
        }

        return $blockers;
    }

    /**
     * Does approving this profile need the reviewer's "Aadhaar copy is
     * masked" attestation? True while a current Aadhaar front/back document is
     * on file. The admin approve form shows its checkbox on this.
     */
    public static function needsAadhaarMaskedAttestation(int $profileId): bool
    {
        [$in, $slots] = Database::inClause(self::AADHAAR_SLOTS);

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `kyc_documents`
              WHERE `profile_id` = ? AND `is_current` = 1 AND `deleted_at` IS NULL
                AND `purged_at` IS NULL AND `slot` IN ' . $in,
            array_merge([$profileId], $slots)
        ) > 0;
    }

    /**
     * Approve the KYC.
     *
     * Sets the verification flags (identity / business verified,
     * `users.kyc_status = verified`) and NOTHING public: the blue tick is a
     * separate decision made through {@see grantBadge()}.
     *
     * A reviewer is required: [$adminId] null is refused, there is no
     * automatic approval. While a current Aadhaar document is on file the
     * reviewer must attest the copy is masked (first 8 digits hidden) —
     * `$attestations['aadhaar_masked'] = true`, the approve form's checkbox —
     * and the attestation is recorded on the approval event.
     *
     * Approving an already approved profile is refused (it used to silently
     * push the expiry out by another validity period); so is every other
     * illegal move, before anything is written.
     *
     * @param array<string,mixed> $attestations aadhaar_masked (bool).
     * @param array<string,mixed> $context      ip, agent, actor_name of the deciding admin.
     */
    public static function approve(
        int $profileId,
        ?int $adminId,
        string $note = '',
        array $attestations = [],
        array $context = []
    ): void {
        if ($adminId === null || $adminId <= 0) {
            throw HttpException::conflict(
                'A verification can only be approved by a reviewer. Automatic approval is not available.',
                'kyc_reviewer_required'
            );
        }

        // Callers written before the attestation parameter passed the audit
        // context (ip, agent, actor_name) in this position. Accept that shape
        // so a reviewer's IP is never dropped from the trail.
        foreach (['ip', 'agent', 'actor_name'] as $key) {
            if (array_key_exists($key, $attestations)) {
                $context[$key] ??= $attestations[$key];
                unset($attestations[$key]);
            }
        }

        $aadhaarMasked = filter_var($attestations['aadhaar_masked'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $outcome = self::withLockedProfile($profileId, static function (array $profile) use (
            $profileId, $adminId, $note, $context, $aadhaarMasked
        ): array {
            self::assertTransitionAllowed((string) $profile['status'], self::APPROVED);

            $blockers = self::approvalBlockers($profileId);

            if ($blockers !== []) {
                throw new HttpException(
                    'This verification cannot be approved yet. ' . implode(' ', $blockers),
                    422,
                    'kyc_incomplete'
                );
            }

            $aadhaarOnFile = self::needsAadhaarMaskedAttestation($profileId);

            if ($aadhaarOnFile && !$aadhaarMasked) {
                throw new HttpException('Confirm the Aadhaar copy is masked before approving.', 422, 'kyc_incomplete');
            }

            $userId    = (int) $profile['user_id'];
            $months    = Config::settingInt('kyc', 'expiry_months', 24);
            $expiresOn = $months > 0
                ? date('Y-m-d', strtotime("+{$months} months"))
                : null;
            $isBrand   = $profile['kind'] === self::KIND_BRAND;

            Database::update('kyc_profiles', [
                'reviewed_by'          => $adminId,
                'reviewed_at'          => date('Y-m-d H:i:s'),
                'approved_at'          => date('Y-m-d H:i:s'),
                'expires_on'           => $expiresOn,
                'rejection_reason'     => null,
                'info_requested'       => null,
                'resubmit_slots'       => null,
                'is_identity_verified' => $isBrand ? 0 : 1,
                'is_business_verified' => $isBrand ? 1 : 0,
            ], ['id' => $profileId]);

            Database::update('users', [
                'kyc_status'           => 'verified',
                'is_identity_verified' => $isBrand ? 0 : 1,
                'is_business_verified' => $isBrand ? 1 : 0,
                'kyc_expires_on'       => $expiresOn,
            ], ['id' => $userId]);

            // The reviewer accepted the submission as a whole, so the
            // documents it rested on are accepted too — by that reviewer.
            Database::execute(
                "UPDATE `kyc_documents`
                    SET `status` = 'approved', `reviewed_by` = :admin, `reviewed_at` = NOW(),
                        `rejection_reason` = NULL
                  WHERE `profile_id` = :profile AND `is_current` = 1 AND `deleted_at` IS NULL
                    AND `purged_at` IS NULL AND `status` IN ('pending', 'under_review')",
                ['admin' => $adminId, 'profile' => $profileId]
            );

            self::transition($profileId, self::APPROVED, [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => $note !== '' ? $note : 'Verification approved.',
                'metadata'   => [
                    'expires_on'   => $expiresOn,
                    'attestations' => $aadhaarOnFile ? ['aadhaar_masked' => true] : [],
                ],
            ] + self::auditFields($context));

            return [
                'user_id'    => $userId,
                'reference'  => (string) $profile['reference'],
                'expires_on' => $expiresOn,
            ];
        });

        self::notify($outcome['user_id'], 'kyc.approved', [
            'reference'     => $outcome['reference'],
            'expires_on'    => $outcome['expires_on'],
            'expires_label' => $outcome['expires_on'] === null
                ? 'further notice'
                : date('j M Y', (int) strtotime((string) $outcome['expires_on'])),
        ], $profileId);

        self::syncKycBadges($outcome['user_id']);
    }

    /**
     * Reject the KYC with remarks the applicant sees; they can correct and
     * resubmit. Also allowed from suspended, so a suspension can end in a
     * resubmittable state.
     *
     * @param array<string,mixed> $context ip, agent, actor_name of the deciding admin.
     */
    public static function reject(int $profileId, ?int $adminId, string $reason, array $context = []): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw HttpException::unprocessable(
                'Give the user a reason so they can correct and resubmit.'
            );
        }

        $outcome = self::withLockedProfile($profileId, static function (array $profile) use (
            $profileId, $adminId, $reason, $context
        ): array {
            self::assertTransitionAllowed((string) $profile['status'], self::REJECTED);

            $userId = (int) $profile['user_id'];

            Database::update('kyc_profiles', [
                'reviewed_by'          => $adminId,
                'reviewed_at'          => date('Y-m-d H:i:s'),
                'rejection_reason'     => mb_substr($reason, 0, 1000),
                'is_identity_verified' => 0,
                'is_business_verified' => 0,
                'is_premium_verified'  => 0,
            ], ['id' => $profileId]);

            Database::update('users', [
                'kyc_status'           => 'rejected',
                'is_identity_verified' => 0,
                'is_business_verified' => 0,
                'is_premium_verified'  => 0,
            ], ['id' => $userId]);

            self::transition($profileId, self::REJECTED, [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => $reason,
            ] + self::auditFields($context));

            // A rejected account must not keep a tick from any earlier era.
            $tick = self::dropTick($profile, 'Verification rejected.', [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
            ] + $context);

            return ['user_id' => $userId, 'reference' => (string) $profile['reference'], 'tick' => $tick];
        });

        self::notify($outcome['user_id'], 'kyc.rejected', [
            'reference' => $outcome['reference'],
            'reason'    => $reason,
        ], $profileId);

        self::notifyTickDropped($outcome['user_id'], $profileId, $outcome['reference'], 'Verification rejected.', $outcome['tick']);
        self::syncKycBadges($outcome['user_id']);
    }

    /**
     * Ask the user for something specific without rejecting outright.
     *
     * Refused on a profile that is already waiting on the user (info_required):
     * it used to overwrite the open request and wipe the slots another
     * reviewer had just asked to be re-uploaded, with no event recorded. Slots
     * already sent back (a document rejected mid-review) stay requested.
     *
     * @param array<string,mixed> $context ip, agent, actor_name of the deciding admin.
     */
    public static function requestInformation(int $profileId, ?int $adminId, string $request, array $context = []): void
    {
        $request = trim($request);

        if ($request === '') {
            throw HttpException::unprocessable('Describe what the user needs to provide.');
        }

        $outcome = self::withLockedProfile($profileId, static function (array $profile) use (
            $profileId, $adminId, $request, $context
        ): array {
            self::assertTransitionAllowed((string) $profile['status'], self::INFO_REQUIRED);

            Database::update('kyc_profiles', [
                'reviewed_by'      => $adminId,
                'reviewed_at'      => date('Y-m-d H:i:s'),
                'info_requested'   => mb_substr($request, 0, 1000),
                // From suspended, this held the suspension reason.
                'rejection_reason' => null,
            ], ['id' => $profileId]);

            Database::update('users', ['kyc_status' => 'info_required'], ['id' => (int) $profile['user_id']]);

            self::transition($profileId, self::INFO_REQUIRED, [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => $request,
            ] + self::auditFields($context));

            return ['user_id' => (int) $profile['user_id'], 'reference' => (string) $profile['reference']];
        });

        self::notify($outcome['user_id'], 'kyc.info_required', [
            'reference' => $outcome['reference'],
            'request'   => $request,
        ], $profileId);

        self::syncKycBadges($outcome['user_id']);
    }

    /**
     * Send named slots back for re-upload.
     *
     * Moves the profile to info_required (so it is editable again), records
     * which slots the reviewer wants (`resubmit_slots` — the app highlights
     * them and submit() insists on a fresh upload for each), and tells the
     * user exactly which documents to replace.
     *
     * Also the way back from a suspension whose documents need replacing
     * (suspended -> info_required). Refused while the profile is already
     * info_required, like every same-state decision.
     *
     * @param list<string>        $slots
     * @param array<string,mixed> $context ip, agent, actor_name of the deciding admin.
     */
    public static function requestResubmission(
        int $profileId,
        ?int $adminId,
        array $slots,
        string $message = '',
        array $context = []
    ): void {
        $profile = self::find($profileId);

        // Before the slot checks, so a stale page gets the state error.
        self::assertTransitionAllowed((string) $profile['status'], self::INFO_REQUIRED);

        $labels = [];

        foreach (self::requirements((string) $profile['kind']) as $requirement) {
            $labels[(string) $requirement['slot']] = (string) $requirement['label'];
        }

        $slots = array_values(array_unique(array_filter(
            array_map('strval', $slots),
            static fn (string $slot): bool => $slot !== ''
        )));

        if ($slots === []) {
            throw HttpException::unprocessable('Choose at least one document to be re-uploaded.');
        }

        foreach ($slots as $slot) {
            if (!isset($labels[$slot])) {
                throw HttpException::unprocessable('Unknown document slot: ' . $slot . '.');
            }
        }

        $message = trim($message);

        $named = self::withLockedProfile($profileId, static function (array $locked) use (
            $profileId, $adminId, $slots, $labels, $message, $context
        ): array {
            self::assertTransitionAllowed((string) $locked['status'], self::INFO_REQUIRED);

            // Slots already waiting for a re-upload — documents rejected
            // mid-review, which reviewDocument() reopened — stay requested
            // whether or not the reviewer ticked them again: overwriting the
            // list used to drop that flag, and an optional slot could then be
            // resubmitted with the rejected copy still in place. Slots no
            // longer on the checklist cannot be uploaded into, so they go.
            $pending = array_values(array_filter(
                self::resubmitSlots($locked),
                static fn (string $slot): bool => isset($labels[$slot])
            ));
            $merged  = array_values(array_unique(array_merge($pending, $slots)));
            $named   = array_map(static fn (string $slot): string => $labels[$slot], $merged);
            $request = 'Please upload new copies of: ' . implode(', ', $named) . '.'
                . ($message !== '' ? ' ' . $message : '');

            Database::update('kyc_profiles', [
                'reviewed_by'      => $adminId,
                'reviewed_at'      => date('Y-m-d H:i:s'),
                'info_requested'   => mb_substr($request, 0, 1000),
                'resubmit_slots'   => json_encode($merged),
                // From suspended, this held the suspension reason.
                'rejection_reason' => null,
            ], ['id' => $profileId]);

            Database::update('users', ['kyc_status' => 'info_required'], ['id' => (int) $locked['user_id']]);

            self::transition($profileId, self::INFO_REQUIRED, [
                'action'     => 'resubmission_requested',
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => $request,
                'metadata'   => ['slots' => $merged, 'requested' => $slots],
            ] + self::auditFields($context));

            return $named;
        });

        self::notify((int) $profile['user_id'], 'kyc.resubmission_requested', [
            'reference' => $profile['reference'],
            'slots'     => implode(', ', $named),
            'message'   => $message,
        ], $profileId);

        self::syncKycBadges((int) $profile['user_id']);
    }

    /**
     * Suspend an approved verification. A reason is required — the user is
     * told it. The tick and every KYC-derived badge go at once.
     *
     * @param array<string,mixed> $context ip, agent, actor_name of the deciding admin.
     */
    public static function suspend(int $profileId, ?int $adminId, string $reason, array $context = []): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw HttpException::unprocessable('Give the user a reason for the suspension.');
        }

        $outcome = self::withLockedProfile($profileId, static function (array $profile) use (
            $profileId, $adminId, $reason, $context
        ): array {
            self::assertTransitionAllowed((string) $profile['status'], self::SUSPENDED);

            $userId = (int) $profile['user_id'];

            Database::update('kyc_profiles', [
                'rejection_reason'     => mb_substr($reason, 0, 1000),
                'is_identity_verified' => 0,
                'is_business_verified' => 0,
                'is_premium_verified'  => 0,
            ], ['id' => $profileId]);

            Database::update('users', [
                'kyc_status'           => 'suspended',
                'is_identity_verified' => 0,
                'is_business_verified' => 0,
                'is_premium_verified'  => 0,
            ], ['id' => $userId]);

            self::transition($profileId, self::SUSPENDED, [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => $reason,
            ] + self::auditFields($context));

            $tick = self::dropTick($profile, 'Verification suspended: ' . $reason, [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
            ] + $context);

            return ['user_id' => $userId, 'reference' => (string) $profile['reference'], 'tick' => $tick];
        });

        self::notify($outcome['user_id'], 'kyc.suspended', [
            'reference' => $outcome['reference'],
            'reason'    => $reason,
        ], $profileId);

        self::notifyTickDropped($outcome['user_id'], $profileId, $outcome['reference'], 'Verification suspended: ' . $reason, $outcome['tick']);
        self::syncKycBadges($outcome['user_id']);
    }

    /**
     * Lift a suspension and put the verification back in front of a reviewer
     * (suspended -> under_review).
     *
     * Suspension used to be a dead end: nothing moved a profile out of it, the
     * user could neither edit nor resubmit, and once the retention sweep had
     * deleted its documents it could never be approved again. Besides this, a
     * reviewer can now send a suspended profile back for new documents
     * ({@see requestResubmission()} / {@see requestInformation()}), reject it
     * so the user can correct and resubmit ({@see reject()}), or approve it.
     *
     * Reinstating does not restore the blue tick: that stays a separate grant.
     *
     * @param array<string,mixed> $context ip, agent, actor_name of the deciding admin.
     */
    public static function reinstate(int $profileId, int $adminId, string $note, array $context = []): void
    {
        $note = trim($note);

        if ($note === '') {
            throw HttpException::unprocessable('Say why the suspension is being lifted.');
        }

        $outcome = self::withLockedProfile($profileId, static function (array $profile) use (
            $profileId, $adminId, $note, $context
        ): array {
            if ((string) $profile['status'] !== self::SUSPENDED) {
                throw HttpException::conflict(
                    'Only a suspended verification can be reinstated (this one is '
                    . strtolower(self::statusLabel((string) $profile['status'])) . ').',
                    'invalid_kyc_transition'
                );
            }

            Database::update('kyc_profiles', [
                'reviewed_by'      => $adminId,
                'reviewed_at'      => date('Y-m-d H:i:s'),
                'rejection_reason' => null,
            ], ['id' => $profileId]);

            Database::update('users', ['kyc_status' => 'under_review'], ['id' => (int) $profile['user_id']]);

            self::transition($profileId, self::UNDER_REVIEW, [
                'action'     => 'reinstated',
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => $note,
            ] + self::auditFields($context));

            return ['user_id' => (int) $profile['user_id'], 'reference' => (string) $profile['reference']];
        });

        self::notify($outcome['user_id'], 'kyc.under_review', [
            'reference' => $outcome['reference'],
        ], $profileId);

        self::syncKycBadges($outcome['user_id']);
    }

    /**
     * A reviewer's verdict on one document.
     *
     * Only the live version of a document on a profile that is with the review
     * team can be judged. A rejection needs a reason, which the user sees
     * against that document in the app.
     *
     * The applicant is told about a rejected document and can act on it at
     * once: while the profile is with the reviewer (submitted / under review,
     * so not editable) the rejected slot is reopened for upload — added to
     * `resubmit_slots`, the same mechanism a re-upload request uses — and a
     * fresh upload into it lands as a new pending version for the reviewer.
     * Before this, the email said "see what to do next" while every upload
     * was refused with kyc_locked. Approving a document again closes its slot.
     *
     * @param array<string,mixed> $context ip, agent, actor_name of the deciding admin.
     */
    public static function reviewDocument(
        int $documentId,
        ?int $adminId,
        string $decision,
        ?string $reason = null,
        array $context = []
    ): void {
        $document = Database::selectOne(
            'SELECT * FROM `kyc_documents`
              WHERE `id` = :id AND `profile_id` IS NOT NULL AND `deleted_at` IS NULL',
            ['id' => $documentId]
        ) ?? throw HttpException::notFound('That document was not found.');

        $approve = $decision === 'approve' || $decision === 'approved';
        $reason  = trim((string) $reason);

        if (!$approve && $reason === '') {
            throw HttpException::unprocessable('Say why the document is rejected — the user sees this.');
        }

        $profileId = (int) $document['profile_id'];
        $label     = (string) ($document['title'] ?? 'Document');

        $profile = self::withLockedProfile($profileId, static function (array $profile) use (
            $documentId, $adminId, $approve, $reason, $label, $context
        ): array {
            if (!in_array((string) $profile['status'], [self::SUBMITTED, self::UNDER_REVIEW, self::INFO_REQUIRED], true)) {
                throw HttpException::conflict(
                    'Documents can only be reviewed while the verification is with the review team.',
                    'kyc_not_reviewable'
                );
            }

            // Re-read under the lock: an upload may have superseded it.
            $document = Database::selectOne(
                'SELECT * FROM `kyc_documents` WHERE `id` = :id AND `deleted_at` IS NULL',
                ['id' => $documentId]
            );

            if ($document === null || (int) $document['is_current'] !== 1 || $document['purged_at'] !== null) {
                throw HttpException::conflict('Only the current version of a document can be reviewed.');
            }

            Database::update('kyc_documents', [
                'status'           => $approve ? 'approved' : 'rejected',
                'reviewed_by'      => $adminId,
                'reviewed_at'      => date('Y-m-d H:i:s'),
                'rejection_reason' => $approve ? null : mb_substr($reason, 0, 500),
            ], ['id' => $documentId]);

            // Open (or close) the slot for upload while the profile itself is
            // locked to the user. An editable profile needs no help.
            if (!self::isEditable((string) $profile['status'])) {
                $slots   = self::resubmitSlots($profile);
                $slot    = (string) $document['slot'];
                $updated = $approve
                    ? array_values(array_diff($slots, [$slot]))
                    : array_values(array_unique(array_merge($slots, [$slot])));

                if ($updated !== $slots) {
                    Database::update('kyc_profiles', [
                        'resubmit_slots' => $updated === [] ? null : json_encode($updated),
                    ], ['id' => (int) $profile['id']]);
                }
            }

            self::recordEvent((int) $profile['id'], $approve ? 'document_approved' : 'document_rejected', [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => $label . ($approve ? ' approved.' : ' rejected: ' . $reason),
                'metadata'   => [
                    'document_id' => $documentId,
                    'slot'        => $document['slot'],
                    'version'     => (int) $document['version'],
                ],
            ] + self::auditFields($context));

            return $profile;
        });

        if (!$approve) {
            self::notify((int) $profile['user_id'], 'kyc.document_rejected', [
                'reference' => $profile['reference'],
                'document'  => $label,
                'reason'    => $reason,
            ], $profileId);
        }
    }

    // -----------------------------------------------------------------
    // The blue tick
    // -----------------------------------------------------------------

    /**
     * Grant the public blue tick.
     *
     * The ONLY way the tick turns on, and only after a successful manual KYC
     * approval that is still current: status approved, not purged, not past
     * its expiry date, on an active account, by an administrator. There is no
     * automatic path and no bypass — `kyc.badge_requires_kyc` is kept in the
     * settings table but permanently ignored.
     *
     * Granting a tick that is already showing is refused, like any same-state
     * decision. A partly applied tick (badge granted but a column missing) is
     * repaired instead.
     *
     * The optional [$note] is internal: it goes on the admin trail (event
     * metadata and the activity log) and never reaches the applicant.
     *
     * For creators the tick is the `creator_verified` badge, whose sync writes
     * `creators.is_verified` (what discovery and the app read); for every
     * account it is `users.has_blue_tick`.
     *
     * @param array<string,mixed> $context ip, agent, actor_name of the admin.
     */
    public static function grantBadge(int $profileId, int $adminId, ?string $note = null, array $context = []): void
    {
        $note = trim((string) $note);

        // An approval past its date is expired now (and anything it carried
        // dropped), then refused below like any other non-approved profile.
        self::expireIfLapsed((int) self::find($profileId)['user_id']);

        $profile = self::withLockedProfile($profileId, static function (array $profile) use (
            $profileId, $adminId, $note, $context
        ): array {
            if ($profile['purged_at'] !== null) {
                throw HttpException::conflict('This verification was purged and cannot carry a blue tick.', 'kyc_purged');
            }

            if ($profile['status'] !== self::APPROVED) {
                throw HttpException::conflict(
                    'The blue tick can only be granted once the KYC is approved (currently '
                    . strtolower(self::statusLabel((string) $profile['status'])) . ').',
                    'kyc_not_approved'
                );
            }

            if (self::hasLapsed($profile)) {
                throw HttpException::conflict(
                    'This verification has lapsed; it must be renewed before a blue tick can be granted.',
                    'kyc_expired'
                );
            }

            $accountStatus = Database::scalar(
                'SELECT `status` FROM `users` WHERE `id` = :id AND `deleted_at` IS NULL',
                ['id' => (int) $profile['user_id']]
            );

            if ($accountStatus !== 'active') {
                throw HttpException::conflict(
                    'The blue tick can only be granted to an active account (this one is '
                    . ($accountStatus === null ? 'deleted' : (string) $accountStatus) . ').',
                    'account_not_active'
                );
            }

            if (self::tickFullyShown($profile)) {
                throw HttpException::conflict('This account already has the blue tick.', 'kyc_badge_already_granted');
            }

            Database::update('kyc_profiles', [
                'badge_status'        => self::BADGE_GRANTED,
                'badge_granted_by'    => $adminId,
                'badge_granted_at'    => date('Y-m-d H:i:s'),
                'badge_revoked_at'    => null,
                'badge_revoke_reason' => null,
            ], ['id' => $profileId]);

            // The badge row (creator_badges.awarded_note) and its event trail
            // get fixed, applicant-safe text: the reviewer's own note is
            // internal and lives only in this decision's kyc_events metadata.
            self::setTickFlags((int) $profile['user_id'], (string) $profile['kind'], true, [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => 'Blue tick granted.',
            ] + $context);

            // The note field is fixed text; the reviewer's own words are kept
            // in metadata, which no applicant-facing read ever selects.
            self::recordEvent($profileId, 'badge_granted', [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
                'note'       => 'Blue tick granted.',
                'metadata'   => $note !== '' ? ['internal_note' => mb_substr($note, 0, 500)] : null,
            ] + self::auditFields($context));

            return $profile;
        });

        ActivityLogger::log(
            'kyc.badge_granted',
            'kyc_profile',
            $profileId,
            'Granted the blue tick on ' . $profile['reference'] . '.',
            ['note' => $note],
            'admin',
            $adminId,
            self::actorName('admin', $adminId)
        );

        self::notify((int) $profile['user_id'], 'kyc.badge_granted', ['reference' => $profile['reference']], $profileId);
        self::syncKycBadges((int) $profile['user_id']);
    }

    /**
     * Withdraw the blue tick. A reason is required; the user is told it.
     *
     * Refused when the account shows no tick at all (nothing to withdraw).
     * The KYC-derived badges are re-synced: they follow the KYC approval, not
     * the tick, so an account whose KYC stays approved keeps "KYC verified".
     *
     * @param array<string,mixed> $context ip, agent, actor_name of the admin.
     */
    public static function revokeBadge(int $profileId, int $adminId, string $reason, array $context = []): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw HttpException::unprocessable('Give a reason for removing the blue tick.');
        }

        $outcome = self::withLockedProfile($profileId, static function (array $profile) use (
            $adminId, $reason, $context
        ): array {
            $tick = self::dropTick($profile, $reason, [
                'actor_type' => 'admin',
                'actor_id'   => $adminId,
            ] + $context, true);

            if (!$tick['changed']) {
                throw HttpException::conflict('This account does not have the blue tick.', 'kyc_badge_not_granted');
            }

            return ['profile' => $profile, 'tick' => $tick];
        });

        $profile = $outcome['profile'];

        ActivityLogger::log(
            'kyc.badge_revoked',
            'kyc_profile',
            $profileId,
            'Revoked the blue tick on ' . $profile['reference'] . '.',
            ['reason' => $reason],
            'admin',
            $adminId,
            self::actorName('admin', $adminId)
        );

        self::notifyTickDropped((int) $profile['user_id'], $profileId, (string) $profile['reference'], $reason, $outcome['tick']);
        self::syncKycBadges((int) $profile['user_id']);
    }

    /**
     * Grant/revoke by the creator's id, for the creator screens that still
     * carry a "verified" switch. Both resolve to the creator's KYC profile so
     * there is one path to the tick.
     */
    public static function creatorProfileId(int $creatorId): ?int
    {
        $userId = Database::scalar(
            'SELECT `user_id` FROM `creators` WHERE `id` = :id',
            ['id' => $creatorId]
        );

        if ($userId === null) {
            return null;
        }

        $profileId = Database::scalar(
            'SELECT `id` FROM `kyc_profiles`
              WHERE `user_id` = :user_id AND `kind` = :kind AND `deleted_at` IS NULL',
            ['user_id' => (int) $userId, 'kind' => self::KIND_CREATOR]
        );

        return $profileId === null ? null : (int) $profileId;
    }

    /**
     * Turn the tick off, if anything shows it.
     *
     * Used by rejection, suspension, expiry and purge (automatic) and by
     * revokeBadge() (explicit). Besides a granted badge it also clears a tick
     * that predates the badge model — a seeded or legacy `creators.is_verified`
     * — because "the tick survived a rejection" is exactly the defect this
     * fixes.
     *
     * Runs inside the caller's locked decision and sends nothing itself: it
     * reports whether anything changed and whether the user should be told
     * (a granted tick went, or the removal was explicit), and the caller
     * notifies after commit through {@see notifyTickDropped()}.
     *
     * @param array<string,mixed> $profile The locked row.
     * @param array<string,mixed> $context
     * @return array{changed:bool,notify:bool}
     */
    private static function dropTick(
        array $profile,
        string $reason,
        array $context,
        bool $explicit = false
    ): array {
        $userId     = (int) $profile['user_id'];
        $wasGranted = ($profile['badge_status'] ?? self::BADGE_NONE) === self::BADGE_GRANTED;

        $visible = (int) Database::scalar('SELECT `has_blue_tick` FROM `users` WHERE `id` = :id', ['id' => $userId]) === 1;

        if (!$visible && $profile['kind'] === self::KIND_CREATOR) {
            $visible = (int) Database::scalar(
                'SELECT COUNT(*) FROM `creators` WHERE `user_id` = :id AND `is_verified` = 1',
                ['id' => $userId]
            ) > 0;
        }

        if (!$wasGranted && !$visible) {
            return ['changed' => false, 'notify' => false];
        }

        $profileId = (int) $profile['id'];

        Database::transaction(static function () use ($profile, $profileId, $userId, $wasGranted, $reason, $context): void {
            if ($wasGranted) {
                Database::update('kyc_profiles', [
                    'badge_status'        => self::BADGE_REVOKED,
                    'badge_revoked_at'    => date('Y-m-d H:i:s'),
                    'badge_revoke_reason' => mb_substr($reason, 0, 500),
                ], ['id' => $profileId]);
            }

            self::setTickFlags($userId, (string) $profile['kind'], false, $context + ['reason' => $reason]);

            self::recordEvent($profileId, 'badge_revoked', [
                'actor_type' => $context['actor_type'] ?? 'system',
                'actor_id'   => $context['actor_id'] ?? null,
                'note'       => $reason,
                'metadata'   => ['was_granted' => $wasGranted],
            ] + self::auditFields($context));
        });

        return ['changed' => true, 'notify' => $wasGranted || $explicit];
    }

    /**
     * Tell the user their tick went — after the decision that removed it has
     * committed, and only when {@see dropTick()} said they should be told.
     *
     * @param array{changed:bool,notify:bool} $tick
     */
    private static function notifyTickDropped(int $userId, int $profileId, string $reference, string $reason, array $tick): void
    {
        if (!($tick['notify'] ?? false)) {
            return;
        }

        self::notify($userId, 'kyc.badge_revoked', [
            'reference' => $reference,
            'reason'    => $reason,
        ], $profileId);
    }

    /**
     * Is the tick showing everywhere it should for this granted profile?
     * (Granted on the profile, on `users`, and — for a live creator — on
     * `creators`.) A partly applied grant reads false so it can be repaired.
     *
     * @param array<string,mixed> $profile
     */
    private static function tickFullyShown(array $profile): bool
    {
        if (($profile['badge_status'] ?? self::BADGE_NONE) !== self::BADGE_GRANTED) {
            return false;
        }

        $userId = (int) $profile['user_id'];

        if ((int) Database::scalar('SELECT `has_blue_tick` FROM `users` WHERE `id` = :id', ['id' => $userId]) !== 1) {
            return false;
        }

        if ($profile['kind'] !== self::KIND_CREATOR) {
            return true;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `creators` WHERE `user_id` = :id AND `deleted_at` IS NULL AND `is_verified` = 0',
            ['id' => $userId]
        ) === 0;
    }

    /**
     * Has this approved profile run past its expiry date?
     *
     * @param array<string,mixed> $profile
     */
    private static function hasLapsed(array $profile): bool
    {
        return ($profile['expires_on'] ?? null) !== null
            && strtotime((string) $profile['expires_on']) < strtotime('today');
    }

    /**
     * Write the tick onto every column that displays it.
     *
     * @param array<string,mixed> $context Passed through to the badge trail.
     */
    private static function setTickFlags(int $userId, string $kind, bool $on, array $context): void
    {
        Database::update('users', [
            'has_blue_tick' => $on ? 1 : 0,
            'blue_tick_at'  => $on ? date('Y-m-d H:i:s') : null,
        ], ['id' => $userId]);

        if ($kind !== self::KIND_CREATOR) {
            return;
        }

        $creatorId = Database::scalar(
            'SELECT `id` FROM `creators` WHERE `user_id` = :id AND `deleted_at` IS NULL',
            ['id' => $userId]
        );

        if ($creatorId !== null) {
            $badgeContext = [
                // The badge trail knows admin / creator / system actors.
                'actor_type' => ($context['actor_type'] ?? 'system') === 'admin' ? 'admin' : 'system',
                'actor_id'   => ($context['actor_type'] ?? 'system') === 'admin' ? ($context['actor_id'] ?? null) : null,
                'actor_name' => $context['actor_name'] ?? null,
                'ip'         => $context['ip'] ?? null,
            ];

            try {
                $on
                    ? CreatorBadgeService::award((int) $creatorId, 'creator_verified', $badgeContext + [
                        'note' => (string) ($context['note'] ?? 'Blue tick granted.'),
                    ])
                    : CreatorBadgeService::revoke((int) $creatorId, 'creator_verified', $badgeContext + [
                        'reason' => (string) ($context['reason'] ?? 'Blue tick removed.'),
                    ]);
            } catch (HttpException $e) {
                // The catalogue term may have been removed by an operator; the
                // column below is still what the app reads.
                Logger::warning('Could not sync the creator_verified badge', [
                    'creator_id' => (int) $creatorId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // Written directly as well: a legacy tick with no badge row is not
        // touched by CreatorBadgeService::revoke(), and must still go.
        Database::execute(
            $on
                ? 'UPDATE `creators` SET `is_verified` = 1, `verified_at` = COALESCE(`verified_at`, NOW()) WHERE `user_id` = :id'
                : 'UPDATE `creators` SET `is_verified` = 0, `verified_at` = NULL WHERE `user_id` = :id',
            ['id' => $userId]
        );
    }

    // -----------------------------------------------------------------
    // State machine
    // -----------------------------------------------------------------

    /**
     * Run one workflow decision as a unit, holding a row lock on the profile.
     *
     * Every decision used to check the status first, commit its column writes
     * in one transaction and change the status in another — with nothing held
     * in between. Two reviewers approving and rejecting the same profile could
     * then leave a `rejected` profile with no rejection reason and
     * `users.kyc_status = verified`, and a grant racing a suspension could put
     * a public tick on a suspended account.
     *
     * Now the row is locked (SELECT ... FOR UPDATE) before the status is read,
     * [$fn] re-checks it on that locked row and does every write — status
     * included — inside the same transaction. A concurrent decision waits, then
     * sees the status this one left and is refused. Notifications are the
     * caller's job, after this returns (after commit).
     *
     * @template T
     * @param  callable(array<string,mixed>):T $fn Receives the locked row.
     * @return T
     */
    private static function withLockedProfile(int $profileId, callable $fn): mixed
    {
        return Database::transaction(static function () use ($profileId, $fn): mixed {
            $profile = Database::selectOne(
                'SELECT * FROM `kyc_profiles` WHERE `id` = :id AND `deleted_at` IS NULL FOR UPDATE',
                ['id' => $profileId]
            ) ?? throw HttpException::notFound('Verification profile not found.');

            return $fn($profile);
        });
    }

    /**
     * Refuse an illegal or same-state move BEFORE anything is written.
     *
     * Same-state decisions are refused, never treated as no-ops: approving an
     * approved profile used to pass, push its expiry out by another validity
     * period, re-send "approved" and record nothing in kyc_events; rejecting a
     * rejected one silently replaced the reason the user had been given.
     */
    private static function assertTransitionAllowed(string $from, string $to): void
    {
        if ($from === $to) {
            throw HttpException::conflict(
                'This verification is already ' . strtolower(self::statusLabel($from)) . '.',
                'kyc_already_in_state'
            );
        }

        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw HttpException::conflict(
                "A verification cannot move from {$from} to {$to}.",
                'invalid_kyc_transition'
            );
        }
    }

    /**
     * Guarded state change. Refuses illegal and same-state moves and writes
     * the audit event.
     *
     * Locks the row itself (a savepoint when called inside a decision that
     * already holds the lock) and writes the new status only if the row still
     * holds the status it was checked against, so the event's from/to is
     * always what actually happened.
     *
     * @param array<string,mixed> $context action, actor_type, actor_id, actor_name,
     *                                     note, metadata, ip, agent.
     */
    public static function transition(int $profileId, string $to, array $context = []): void
    {
        self::withLockedProfile($profileId, static function (array $profile) use ($profileId, $to, $context): void {
            $from = (string) $profile['status'];

            self::assertTransitionAllowed($from, $to);

            $changed = Database::execute(
                'UPDATE `kyc_profiles` SET `status` = :to WHERE `id` = :id AND `status` = :from',
                ['to' => $to, 'id' => $profileId, 'from' => $from]
            );

            if ($changed !== 1) {
                throw HttpException::conflict(
                    'This verification changed while it was being decided. Reload it and try again.',
                    'kyc_conflict'
                );
            }

            self::recordEvent($profileId, (string) ($context['action'] ?? $to), [
                'actor_type' => $context['actor_type'] ?? 'system',
                'actor_id'   => $context['actor_id'] ?? null,
                'note'       => $context['note'] ?? null,
                'metadata'   => $context['metadata'] ?? null,
                'from'       => $from,
                'to'         => $to,
            ] + self::auditFields($context));
        });
    }

    /**
     * The request-origin fields of an audit context, in the shape recordEvent()
     * takes: ip, agent, and the actor's name when the caller resolved it.
     *
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function auditFields(array $context): array
    {
        $fields = [
            'ip'    => $context['ip'] ?? null,
            'agent' => $context['agent'] ?? null,
        ];

        if (isset($context['actor_name']) && trim((string) $context['actor_name']) !== '') {
            $fields['actor_name'] = (string) $context['actor_name'];
        }

        return $fields;
    }

    /**
     * Re-sync the account's KYC-derived public badges ("KYC verified",
     * "Identity verified", "Business verified") with its KYC profile, right
     * after a decision — not at the next nightly badge job.
     *
     * Runs after the decision has committed and never fails it: a badge
     * problem is logged, and CreatorBadgeService::forCreator() re-checks KYC
     * standing on every read anyway, so a stale row is never shown.
     */
    private static function syncKycBadges(int $userId): void
    {
        try {
            // Deleted creators included: their rows must lose the badges too.
            foreach (Database::column(
                'SELECT `id` FROM `creators` WHERE `user_id` = :u',
                ['u' => $userId]
            ) as $creatorId) {
                CreatorBadgeService::syncKycBadges((int) $creatorId);
            }
        } catch (\Throwable $e) {
            Logger::warning('Could not re-sync the KYC-derived creator badges', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Gating
    // -----------------------------------------------------------------

    /**
     * Whether [$userId] has cleared verification for the given account type.
     *
     * Returns true when KYC is not mandatory for that side of the marketplace,
     * so switching the requirement off in settings unblocks everyone at once.
     */
    public static function isVerified(int $userId, string $accountType): bool
    {
        $kind = $accountType === self::KIND_CREATOR ? self::KIND_CREATOR : self::KIND_BRAND;

        $mandatory = $kind === self::KIND_BRAND
            ? Config::settingBool('kyc', 'brand_mandatory', true)
            : Config::settingBool('kyc', 'creator_mandatory', true);

        if (!$mandatory) {
            return true;
        }

        $row = Database::selectOne(
            'SELECT `status`, `expires_on`, `purged_at` FROM `kyc_profiles`
              WHERE `user_id` = :user_id AND `kind` = :kind AND `deleted_at` IS NULL',
            ['user_id' => $userId, 'kind' => $kind]
        );

        if ($row === null || $row['status'] !== self::APPROVED || $row['purged_at'] !== null) {
            return false;
        }

        // An approved-but-lapsed profile does not count.
        return $row['expires_on'] === null
            || strtotime((string) $row['expires_on']) >= strtotime('today');
    }

    /** Human-readable reason a gated action was refused. */
    public static function gateMessage(string $accountType): string
    {
        return $accountType === self::KIND_CREATOR
            ? 'Complete your identity verification before accepting bookings or withdrawing earnings.'
            : 'Complete your business verification before creating campaigns or paying for bookings.';
    }

    // -----------------------------------------------------------------
    // Expiry (cron)
    // -----------------------------------------------------------------

    /**
     * Expire this account's approvals that have run past their date, now.
     *
     * The nightly sweep is no longer the only thing standing between a lapsed
     * approval and the public tick: the owner's own verification screen, the
     * KYC gate, a tick grant and the public creator profile all call this, so
     * the tick (and the KYC-derived badges) drop the first time anyone looks.
     * One indexed read when nothing has lapsed.
     *
     * @return int Profiles expired.
     */
    public static function expireIfLapsed(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $expired = 0;

        foreach (Database::column(
            "SELECT `id` FROM `kyc_profiles`
              WHERE `user_id` = :u AND `status` = 'approved'
                AND `expires_on` IS NOT NULL AND `expires_on` < CURDATE()
                AND `deleted_at` IS NULL",
            ['u' => $userId]
        ) as $profileId) {
            if (self::expireProfile((int) $profileId)) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * Move one lapsed approval to expired: flags off, tick off, event
     * recorded, user told, badges re-synced.
     *
     * Re-checked on the locked row, so the sweep and an on-read expiry racing
     * each other expire it once. False when there was nothing to do.
     */
    private static function expireProfile(int $profileId): bool
    {
        $outcome = self::withLockedProfile($profileId, static function (array $profile) use ($profileId): ?array {
            if ($profile['status'] !== self::APPROVED || !self::hasLapsed($profile)) {
                return null;
            }

            $userId = (int) $profile['user_id'];

            Database::update('kyc_profiles', [
                'is_identity_verified' => 0,
                'is_business_verified' => 0,
                'is_premium_verified'  => 0,
            ], ['id' => $profileId]);

            Database::update('users', [
                'kyc_status'           => 'expired',
                'is_identity_verified' => 0,
                'is_business_verified' => 0,
                'is_premium_verified'  => 0,
            ], ['id' => $userId]);

            self::transition($profileId, self::EXPIRED, [
                'actor_type' => 'system',
                'note'       => 'Verification validity lapsed.',
                'metadata'   => ['expires_on' => $profile['expires_on']],
            ]);

            // The kyc.expired message already says the tick went with it, so
            // no separate "blue tick removed" notification.
            self::dropTick($profile, 'Verification expired.', ['actor_type' => 'system']);

            return ['user_id' => $userId, 'reference' => (string) $profile['reference']];
        });

        if ($outcome === null) {
            return false;
        }

        self::notify($outcome['user_id'], 'kyc.expired', ['reference' => $outcome['reference']], $profileId);
        self::syncKycBadges($outcome['user_id']);

        return true;
    }

    /**
     * Expire lapsed verifications, warn the ones about to lapse, and flag
     * documents whose own expiry date has passed.
     *
     * [$userId] confines the sweep to one account (used by the test suite so
     * it never sends a reminder to, or expires, anybody else). It used to be
     * reused as the loop variable, which silently skipped the document step
     * on every night a profile lapsed; it is never reassigned now.
     *
     * One profile failing does not stop the rest of the run.
     *
     * @return array{expired:int,warned:int,documents_expired:int}
     */
    public static function runExpirySweep(?int $userId = null): array
    {
        $expired = 0;
        $warned  = 0;

        $scope         = $userId === null ? '' : ' AND `user_id` = :scope_user';
        $scopeBindings = $userId === null ? [] : ['scope_user' => $userId];

        $lapsed = Database::column(
            "SELECT `id` FROM `kyc_profiles`
              WHERE `status` = 'approved'
                AND `expires_on` IS NOT NULL
                AND `expires_on` < CURDATE()
                AND `deleted_at` IS NULL{$scope}",
            $scopeBindings
        );

        foreach ($lapsed as $lapsedId) {
            try {
                if (self::expireProfile((int) $lapsedId)) {
                    $expired++;
                }
            } catch (\Throwable $e) {
                Logger::error('KYC expiry failed for one profile; the sweep continues', [
                    'profile_id' => (int) $lapsedId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $days = max(1, Config::settingInt('kyc', 'expiry_reminder_days', 30));

        $expiring = Database::select(
            "SELECT `id`, `user_id`, `reference`, `expires_on` FROM `kyc_profiles`
              WHERE `status` = 'approved'
                AND `expires_on` IS NOT NULL
                AND `expires_on` BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
                AND `deleted_at` IS NULL{$scope}",
            ['days' => $days] + $scopeBindings
        );

        foreach ($expiring as $profile) {
            // The sweep runs daily and the window is 30 days wide, so without
            // this every user near expiry got the same warning thirty times.
            // Once a week is a reminder; once a day is spam.
            $recent = (int) Database::scalar(
                "SELECT COUNT(*) FROM `kyc_events`
                  WHERE `profile_id` = :id AND `action` = 'expiry_reminder'
                    AND `created_at` > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                ['id' => (int) $profile['id']]
            );

            if ($recent > 0) {
                continue;
            }

            try {
                self::recordEvent((int) $profile['id'], 'expiry_reminder', [
                    'actor_type' => 'system',
                    'note'       => 'Expiry reminder sent.',
                    'metadata'   => ['expires_on' => $profile['expires_on']],
                ]);
            } catch (\Throwable $e) {
                Logger::error('Could not record a KYC expiry reminder', [
                    'profile_id' => (int) $profile['id'],
                    'error'      => $e->getMessage(),
                ]);

                continue;
            }

            self::notify((int) $profile['user_id'], 'kyc.expiring', [
                'reference'  => $profile['reference'],
                'expires_on' => $profile['expires_on'],
            ], (int) $profile['id']);

            $warned++;
        }

        return [
            'expired'           => $expired,
            'warned'            => $warned,
            'documents_expired' => self::expireStaleDocuments($userId),
        ];
    }

    /**
     * Flag current documents whose own expiry date has passed.
     *
     * Replaces the bare UPDATE in DocumentVault::expireStaleDocuments(), which
     * turned a document "rejected — Document expired" with no audit event and
     * no word to its owner. Each document now gets a `document_expired` event
     * (system actor) and the owner is told. [$userId] scopes it to one account.
     *
     * @return int Documents expired.
     */
    public static function expireStaleDocuments(?int $userId = null): int
    {
        $documents = Database::select(
            "SELECT d.`id`, d.`profile_id`, d.`user_id`, d.`slot`, d.`title`, d.`version`, d.`expires_on`,
                    p.`reference`
               FROM `kyc_documents` d
               LEFT JOIN `kyc_profiles` p ON p.`id` = d.`profile_id`
              WHERE d.`is_current` = 1
                AND d.`deleted_at` IS NULL
                AND d.`purged_at` IS NULL
                AND d.`expires_on` IS NOT NULL
                AND d.`expires_on` < CURDATE()
                AND d.`status` <> 'rejected'" . ($userId === null ? '' : ' AND d.`user_id` = :scope_user'),
            $userId === null ? [] : ['scope_user' => $userId]
        );

        // A dedicated template if the operator has one, else the generic
        // "document not accepted" message, which carries the reason.
        $template = NotificationService::hasTemplate('kyc.document_expired')
            ? 'kyc.document_expired'
            : 'kyc.document_rejected';

        $count = 0;

        foreach ($documents as $document) {
            $documentId = (int) $document['id'];
            $profileId  = $document['profile_id'] === null ? null : (int) $document['profile_id'];
            $title      = (string) ($document['title'] ?? 'Document');
            $expiredOn  = date('j M Y', (int) strtotime((string) $document['expires_on']));
            $reason     = 'This document passed its expiry date (' . $expiredOn . '). Upload a current copy when you next update your verification.';

            try {
                $changed = Database::transaction(static function () use ($documentId, $profileId, $title, $expiredOn, $document): bool {
                    // Profile row first, the same lock order every decision
                    // takes, so this can never deadlock against a reviewer.
                    if ($profileId !== null) {
                        Database::lockForUpdate('kyc_profiles', 'id', $profileId);
                    }

                    $rows = Database::execute(
                        "UPDATE `kyc_documents`
                            SET `status` = 'rejected', `rejection_reason` = 'Document expired'
                          WHERE `id` = :id AND `status` <> 'rejected'",
                        ['id' => $documentId]
                    );

                    if ($rows === 0) {
                        return false;
                    }

                    // Legacy rows with no profile have no trail to write to.
                    if ($profileId !== null) {
                        self::recordEvent($profileId, 'document_expired', [
                            'actor_type' => 'system',
                            'note'       => $title . ' expired on ' . $expiredOn . '.',
                            'metadata'   => [
                                'document_id' => $documentId,
                                'slot'        => $document['slot'],
                                'version'     => (int) $document['version'],
                                'expires_on'  => $document['expires_on'],
                            ],
                        ]);
                    }

                    return true;
                });
            } catch (\Throwable $e) {
                Logger::error('Could not expire a KYC document', ['document_id' => $documentId, 'error' => $e->getMessage()]);

                continue;
            }

            if (!$changed) {
                continue;
            }

            $count++;

            if ($profileId !== null) {
                self::notify((int) $document['user_id'], $template, [
                    'reference'  => (string) ($document['reference'] ?? ''),
                    'document'   => $title,
                    'reason'     => $reason,
                    'expires_on' => $expiredOn,
                ], $profileId);
            }
        }

        return $count;
    }

    // -----------------------------------------------------------------
    // Retention & privacy
    // -----------------------------------------------------------------

    /**
     * Delete identity data that no longer has a reason to exist.
     *
     *   1. Drafts that were never submitted, with no activity for
     *      `retention_days_draft` days (default 30).
     *   2. Profiles rejected or left in action-needed with no activity for
     *      `retention_days_rejected` days.
     *   3. Profiles suspended or expired for `retention_days_closed` days (an
     *      owner re-uploading on an expired profile keeps it alive).
     *   4. Replaced versions older than `purge_superseded_after_days` (counted
     *      from when they were replaced), and removed documents.
     *   5. Documents from the pre-013 single-document flow (no profile) older
     *      than `retention_days_rejected`, of accounts that are not verified.
     *   6. Images the retired public uploader left in the `kyc` media folder,
     *      older than `retention_days_rejected`, that no live document uses
     *      (app users' uploads only — never the admin media library).
     *   7. Encrypted blobs under storage/kyc with no live row, older than a day
     *      (an upload in flight is never that old).
     *   8. Rows already marked purged that still carry a path, file name,
     *      number, holder or upload IP (`purged_rows_scrubbed`).
     *   9. Accounts deleted (users.deleted_at) whose KYC data was never erased
     *      — a failed or pre-dated erasure at deletion (`deleted_accounts`).
     *
     * For 1-3 it is the identity RECORD that goes, not only the files: every
     * file, the detail block (encrypted PAN / GSTIN / CIN, the Aadhaar's last
     * four and every blind index, bank account, IFSC, UPI, date of birth,
     * addresses, contacts), the identifying document columns (file name,
     * holder, number, upload IP) and the reviewers' working notes. The profile
     * row keeps its reference, status, dates and event trail as the minimal
     * record, with `purged_at` set. A profile holding details but no document
     * is swept too — that is also how a number squatted in a draft is freed.
     * An owner who comes back starts over with their next save or upload
     * ({@see reopenIfPurged()}).
     *
     * Approved, active profiles keep everything. Document rows are kept with
     * `purged_at` set — the record that a document existed and when it was
     * destroyed. One profile failing does not stop the run.
     *
     * [$dryRun] counts without deleting. [$userId] confines the sweep to one
     * account (an operator investigating one user, and the test suite, which
     * must never touch anyone else's documents).
     *
     * @return array<string,int|bool>
     */
    public static function runRetentionSweep(bool $dryRun = false, ?int $userId = null): array
    {
        $draftDays      = max(1, Config::settingInt('kyc', 'retention_days_draft', 30));
        $rejectedDays   = max(1, Config::settingInt('kyc', 'retention_days_rejected', 180));
        $closedDays     = max(1, Config::settingInt('kyc', 'retention_days_closed', 90));
        $supersededDays = max(1, Config::settingInt('kyc', 'purge_superseded_after_days', 30));

        $result = [
            'dry_run'              => $dryRun,
            'draft_profiles'       => 0,
            'rejected_profiles'    => 0,
            'closed_profiles'      => 0,
            'documents_purged'     => 0,
            'details_purged'       => 0,
            'superseded_purged'    => 0,
            'legacy_purged'        => 0,
            'legacy_media_purged'  => 0,
            'purged_rows_scrubbed' => 0,
            'deleted_accounts'     => 0,
            'orphan_files'         => 0,
        ];

        $scope         = $userId === null ? '' : ' AND p.`user_id` = :scope_user';
        $scopeBindings = $userId === null ? [] : ['scope_user' => $userId];

        // Last activity = newest event, falling back to the profile's own dates.
        $lastActivity = 'GREATEST(p.`created_at`,
                COALESCE(p.`reviewed_at`, p.`created_at`),
                COALESCE((SELECT MAX(e.`created_at`) FROM `kyc_events` e WHERE e.`profile_id` = p.`id`), p.`created_at`))';

        // When a closed profile entered its state — or its owner last acted on
        // it, whichever is later (an expired profile is editable).
        $closedSince = 'GREATEST(
                COALESCE((SELECT MAX(e.`created_at`) FROM `kyc_events` e
                           WHERE e.`profile_id` = p.`id` AND e.`to_status` = p.`status`), p.`updated_at`),
                COALESCE((SELECT MAX(e.`created_at`) FROM `kyc_events` e
                           WHERE e.`profile_id` = p.`id` AND e.`actor_type` = \'user\'), p.`created_at`))';

        // The day counts are integers from settings, inlined as literals.
        $branches = [
            'draft_profiles' => [
                "p.`status` = 'draft' AND {$lastActivity} < DATE_SUB(NOW(), INTERVAL {$draftDays} DAY)",
                static fn (array $p): string => 'Retention: draft never submitted, no activity for over ' . $draftDays . ' days.',
            ],
            'rejected_profiles' => [
                "p.`status` IN ('rejected', 'info_required') AND {$lastActivity} < DATE_SUB(NOW(), INTERVAL {$rejectedDays} DAY)",
                static fn (array $p): string => 'Retention: ' . self::statusLabel((string) $p['status']) . ' for over ' . $rejectedDays . ' days.',
            ],
            'closed_profiles' => [
                "p.`status` IN ('suspended', 'expired') AND {$closedSince} < DATE_SUB(NOW(), INTERVAL {$closedDays} DAY)",
                static fn (array $p): string => 'Retention: ' . self::statusLabel((string) $p['status']) . ' for over ' . $closedDays . ' days.',
            ],
        ];

        // Anything left to erase: a live document or a detail row.
        $holdsData = '(EXISTS (SELECT 1 FROM `kyc_documents` d WHERE d.`profile_id` = p.`id` AND d.`purged_at` IS NULL)
                    OR EXISTS (SELECT 1 FROM `kyc_creator_details` c WHERE c.`profile_id` = p.`id`)
                    OR EXISTS (SELECT 1 FROM `kyc_brand_details` b WHERE b.`profile_id` = p.`id`))';

        foreach ($branches as $counter => [$condition, $reason]) {
            $profiles = Database::select(
                "SELECT p.`id`, p.`status` FROM `kyc_profiles` p
                  WHERE p.`deleted_at` IS NULL AND {$condition} AND {$holdsData}{$scope}",
                $scopeBindings
            );

            foreach ($profiles as $profile) {
                try {
                    $purged = self::purgeProfileIdentity((int) $profile['id'], $condition, $reason($profile), $dryRun);
                } catch (\Throwable $e) {
                    Logger::error('KYC retention failed for one profile; the sweep continues', [
                        'profile_id' => (int) $profile['id'],
                        'error'      => $e->getMessage(),
                    ]);

                    continue;
                }

                if ($purged === null) {
                    continue;
                }

                $result[$counter]++;
                $result['documents_purged'] += $purged['documents'];
                $result['details_purged']   += $purged['details'];
            }
        }

        // Replaced versions, dated by the upload that replaced them; removed
        // documents (their blob is already gone) just get their marker. The
        // path, file name, number, holder and upload IP go with the bytes.
        $superseded = Database::select(
            'SELECT d.`id`, d.`profile_id`, d.`file_path`, d.`front_image_url`, d.`back_image_url`, d.`selfie_url`
               FROM `kyc_documents` d
               LEFT JOIN `kyc_documents` r ON r.`id` = d.`replaced_by`
              WHERE d.`purged_at` IS NULL
                AND (
                      (d.`is_current` = 0 AND d.`deleted_at` IS NULL
                        AND COALESCE(r.`created_at`, d.`updated_at`) < DATE_SUB(NOW(), INTERVAL :days DAY))
                   OR d.`deleted_at` IS NOT NULL
                )' . ($userId === null ? '' : ' AND d.`user_id` = :scope_user'),
            ['days' => $supersededDays] + $scopeBindings
        );

        foreach ($superseded as $document) {
            if (!$dryRun) {
                self::eraseDocument($document);
            }

            $result['superseded_purged']++;
        }

        // Rows already marked purged that still hold a path, the stored file
        // name, a number, a holder or an upload IP: documents removed by their
        // owner before removal blanked those, superseded copies purged before
        // the path was cleared, or a purge interrupted part-way.
        $leftovers = Database::select(
            'SELECT `id`, `file_path`, `front_image_url`, `back_image_url`, `selfie_url` FROM `kyc_documents`
              WHERE `purged_at` IS NOT NULL
                AND (`file_path` IS NOT NULL OR `original_name` IS NOT NULL OR `checksum` IS NOT NULL
                     OR `document_number` IS NOT NULL OR `holder_name` IS NOT NULL OR `uploaded_ip` IS NOT NULL
                     OR `front_image_url` IS NOT NULL OR `back_image_url` IS NOT NULL OR `selfie_url` IS NOT NULL)'
                . ($userId === null ? '' : ' AND `user_id` = :scope_user'),
            $scopeBindings
        );

        if (!$dryRun) {
            foreach ($leftovers as $document) {
                self::eraseDocument($document);
            }
        }

        $result['purged_rows_scrubbed'] = count($leftovers);
        $result['legacy_purged']        = self::purgeLegacyDocuments($rejectedDays, $dryRun, $userId);
        $result['legacy_media_purged']  = self::purgeLegacyMedia($rejectedDays, $dryRun, $userId);
        $result['deleted_accounts']     = self::purgeDeletedAccounts($dryRun, $userId);
        $result['orphan_files']         = DocumentVault::purgeOrphans($dryRun, 86400, $userId);

        return $result;
    }

    /**
     * Destroy one document's bytes and blank every column that identifies it:
     * the vault path, the stored file name, checksum, document number,
     * holder name, the retired flow's public image URLs and the upload IP.
     * The row itself stays, with `purged_at` set, as the record that the
     * document existed. Idempotent.
     *
     * @param array<string,mixed> $document `id`, `file_path` and, when known,
     *                                      the legacy image URL columns.
     */
    private static function eraseDocument(array $document): void
    {
        DocumentVault::purge((string) ($document['file_path'] ?? ''));

        foreach (['front_image_url', 'back_image_url', 'selfie_url'] as $column) {
            DocumentVault::purgeLegacyPublicFile((string) ($document[$column] ?? ''));
        }

        Database::execute(
            'UPDATE `kyc_documents`
                SET `purged_at` = COALESCE(`purged_at`, NOW()),
                    `file_path` = NULL, `original_name` = NULL, `checksum` = NULL,
                    `document_number` = NULL, `holder_name` = NULL,
                    `front_image_url` = NULL, `back_image_url` = NULL, `selfie_url` = NULL,
                    `uploaded_ip` = NULL
              WHERE `id` = :id',
            ['id' => (int) $document['id']]
        );
    }

    /**
     * Replaced copies of an account's documents that are past the
     * `purge_superseded_after_days` window, erased now rather than at the next
     * retention run — what the upload caps call before refusing an upload, so
     * an install where the nightly job has not run does not lock a user out
     * of a slot. [$profileId] / [$slot] narrow it to one document slot.
     *
     * @return int Copies erased.
     */
    private static function purgeAgedSuperseded(int $userId, ?int $profileId = null, ?string $slot = null): int
    {
        $days     = max(1, Config::settingInt('kyc', 'purge_superseded_after_days', 30));
        $where    = '';
        $bindings = ['days' => $days, 'u' => $userId];

        if ($profileId !== null) {
            $where          .= ' AND d.`profile_id` = :p';
            $bindings['p']   = $profileId;
        }

        if ($slot !== null) {
            $where          .= ' AND d.`slot` = :s';
            $bindings['s']   = $slot;
        }

        $documents = Database::select(
            'SELECT d.`id`, d.`file_path` FROM `kyc_documents` d
               LEFT JOIN `kyc_documents` r ON r.`id` = d.`replaced_by`
              WHERE d.`user_id` = :u AND d.`purged_at` IS NULL AND d.`deleted_at` IS NULL
                AND d.`is_current` = 0
                AND COALESCE(r.`created_at`, d.`updated_at`) < DATE_SUB(NOW(), INTERVAL :days DAY)' . $where,
            $bindings
        );

        foreach ($documents as $document) {
            self::eraseDocument($document);
        }

        if ($documents !== []) {
            Logger::info('KYC: replaced document copies past the retention window erased before an upload', [
                'user_id' => $userId,
                'slot'    => $slot,
                'copies'  => count($documents),
            ]);
        }

        return count($documents);
    }

    /**
     * Safety net for erasure on account deletion.
     *
     * Deleting an account (the user's own request, or an administrator)
     * erases its KYC data through {@see purgeForUser()}. When that step failed
     * — it is logged and the deletion goes ahead — or the account was deleted
     * before the step existed, its documents, numbers and legacy public
     * uploads stayed behind with nothing ever coming back for them. This pass
     * finds every account with `users.deleted_at` set (more than an hour ago,
     * so an erasure still in flight is not raced) that still holds KYC data,
     * and erases it the same way. One account failing does not stop the rest.
     *
     * @return int Accounts erased (or, on a dry run, that would be).
     */
    private static function purgeDeletedAccounts(bool $dryRun, ?int $userId): int
    {
        $accounts = array_map('intval', Database::column(
            "SELECT u.`id` FROM `users` u
              WHERE u.`deleted_at` IS NOT NULL
                AND u.`deleted_at` < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                AND (
                      EXISTS (SELECT 1 FROM `kyc_profiles` p WHERE p.`user_id` = u.`id` AND p.`purged_at` IS NULL)
                   OR EXISTS (SELECT 1 FROM `kyc_documents` d WHERE d.`user_id` = u.`id` AND d.`purged_at` IS NULL)
                   OR EXISTS (SELECT 1 FROM `kyc_profiles` p
                                JOIN `kyc_creator_details` c ON c.`profile_id` = p.`id` WHERE p.`user_id` = u.`id`)
                   OR EXISTS (SELECT 1 FROM `kyc_profiles` p
                                JOIN `kyc_brand_details` b ON b.`profile_id` = p.`id` WHERE p.`user_id` = u.`id`)
                   OR EXISTS (SELECT 1 FROM `media` m
                               WHERE m.`uploader_type` = 'user' AND m.`uploader_id` = u.`id`
                                 AND (m.`folder` = 'kyc' OR m.`path` LIKE 'kyc/%'))
                )" . ($userId === null ? '' : ' AND u.`id` = :scope_user'),
            $userId === null ? [] : ['scope_user' => $userId]
        ));

        if ($dryRun) {
            return count($accounts);
        }

        $erased = 0;

        foreach ($accounts as $accountId) {
            try {
                self::purgeForUser(
                    $accountId,
                    'Retention: the account was deleted but its identity data had not been erased.'
                );
                $erased++;
            } catch (\Throwable $e) {
                Logger::error('KYC erasure of a deleted account failed; the sweep continues', [
                    'user_id' => $accountId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $erased;
    }

    /**
     * Erase one profile's identity record for the retention sweep, under the
     * profile lock, re-checking on the locked row that it still qualifies
     * ([$condition] is the branch's own SQL, aliased `p`) — an owner who
     * resubmitted a moment ago keeps everything.
     *
     * Files first, then the rows (as in purgeForUser): a failure part-way
     * leaves rows that the next run finds and finishes.
     *
     * @return array{documents:int,details:int}|null Null when it no longer qualified.
     */
    private static function purgeProfileIdentity(int $profileId, string $condition, string $reason, bool $dryRun): ?array
    {
        $count = static function () use ($profileId): array {
            return [
                'documents' => (int) Database::scalar(
                    'SELECT COUNT(*) FROM `kyc_documents` WHERE `profile_id` = :id AND `purged_at` IS NULL',
                    ['id' => $profileId]
                ),
                'details' => (int) Database::scalar('SELECT COUNT(*) FROM `kyc_creator_details` WHERE `profile_id` = :id', ['id' => $profileId])
                    + (int) Database::scalar('SELECT COUNT(*) FROM `kyc_brand_details` WHERE `profile_id` = :id', ['id' => $profileId]),
            ];
        };

        if ($dryRun) {
            return $count();
        }

        return Database::transaction(static function () use ($profileId, $condition, $reason, $count): ?array {
            if (Database::lockForUpdate('kyc_profiles', 'id', $profileId) === null) {
                return null;
            }

            $qualifies = (int) Database::scalar(
                "SELECT COUNT(*) FROM `kyc_profiles` p WHERE p.`id` = :id AND p.`deleted_at` IS NULL AND {$condition}",
                ['id' => $profileId]
            ) === 1;

            if (!$qualifies) {
                return null;
            }

            $counts    = $count();
            $documents = Database::select(
                'SELECT `file_path`, `front_image_url`, `back_image_url`, `selfie_url` FROM `kyc_documents`
                  WHERE `profile_id` = :id AND (`purged_at` IS NULL OR `file_path` IS NOT NULL)',
                ['id' => $profileId]
            );

            foreach ($documents as $document) {
                DocumentVault::purge((string) ($document['file_path'] ?? ''));

                foreach (['front_image_url', 'back_image_url', 'selfie_url'] as $column) {
                    DocumentVault::purgeLegacyPublicFile((string) ($document[$column] ?? ''));
                }
            }

            Database::execute(
                'UPDATE `kyc_documents`
                    SET `purged_at` = COALESCE(`purged_at`, NOW()),
                        `file_path` = NULL, `original_name` = NULL, `checksum` = NULL,
                        `document_number` = NULL, `holder_name` = NULL,
                        `front_image_url` = NULL, `back_image_url` = NULL, `selfie_url` = NULL,
                        `uploaded_ip` = NULL
                  WHERE `profile_id` = :id',
                ['id' => $profileId]
            );

            Database::execute('DELETE FROM `kyc_creator_details` WHERE `profile_id` = :id', ['id' => $profileId]);
            Database::execute('DELETE FROM `kyc_brand_details` WHERE `profile_id` = :id', ['id' => $profileId]);
            $notes = Database::execute('DELETE FROM `kyc_notes` WHERE `profile_id` = :id', ['id' => $profileId]);

            Database::execute(
                'UPDATE `kyc_profiles`
                    SET `purged_at` = NOW(), `resubmit_slots` = NULL, `last_ip` = NULL, `last_user_agent` = NULL
                  WHERE `id` = :id',
                ['id' => $profileId]
            );

            self::recordEvent($profileId, 'documents_purged', [
                'actor_type' => 'system',
                'note'       => $reason,
                'metadata'   => [
                    'documents' => $counts['documents'],
                    'details'   => $counts['details'] > 0,
                    'notes'     => $notes,
                ],
            ]);

            return $counts;
        });
    }

    /**
     * Documents from the pre-013 single-document flow carry no profile, so no
     * profile branch ever reached them — and they are the rows that hold a
     * document number and public image URLs. Purged after the rejected
     * window, unless their account is verified.
     *
     * @return int Documents purged (or, on a dry run, that would be).
     */
    private static function purgeLegacyDocuments(int $days, bool $dryRun, ?int $userId): int
    {
        $documents = Database::select(
            "SELECT d.`id`, d.`file_path`, d.`front_image_url`, d.`back_image_url`, d.`selfie_url`
               FROM `kyc_documents` d
               LEFT JOIN `users` u ON u.`id` = d.`user_id`
              WHERE d.`profile_id` IS NULL
                AND d.`purged_at` IS NULL
                AND d.`created_at` < DATE_SUB(NOW(), INTERVAL {$days} DAY)
                AND (u.`id` IS NULL OR u.`kyc_status` IS NULL OR u.`kyc_status` <> 'verified')"
                . ($userId === null ? '' : ' AND d.`user_id` = :scope_user'),
            $userId === null ? [] : ['scope_user' => $userId]
        );

        if ($dryRun) {
            return count($documents);
        }

        foreach ($documents as $document) {
            DocumentVault::purge((string) ($document['file_path'] ?? ''));

            foreach (['front_image_url', 'back_image_url', 'selfie_url'] as $column) {
                DocumentVault::purgeLegacyPublicFile((string) ($document[$column] ?? ''));
            }

            Database::execute(
                'UPDATE `kyc_documents`
                    SET `purged_at` = NOW(), `file_path` = NULL, `original_name` = NULL, `checksum` = NULL,
                        `document_number` = NULL, `holder_name` = NULL,
                        `front_image_url` = NULL, `back_image_url` = NULL, `selfie_url` = NULL,
                        `uploaded_ip` = NULL
                  WHERE `id` = :id',
                ['id' => (int) $document['id']]
            );
        }

        return count($documents);
    }

    /**
     * ID images the retired `/me/upload folder=kyc` route wrote to the PUBLIC
     * uploads tree, with a `media` row each. scripts/kyc_migrate_legacy.php
     * moved the ones a document referenced into the vault and left the rest
     * where anyone with the URL can fetch them. Removed (file and row) after
     * the rejected window, unless a live document still points at one.
     *
     * Only what that route wrote: an app user's upload (`uploader_type =
     * user`) that is not filed in the admin media library (`folder_id` NULL —
     * the user uploader never sets it). The admin library stores its files
     * under the folder's slug, so a library folder named "kyc" has rows with
     * the same `folder` / `path`; those are admin content and are never
     * touched here.
     *
     * @return int Media rows purged (or, on a dry run, that would be).
     */
    private static function purgeLegacyMedia(int $days, bool $dryRun, ?int $userId): int
    {
        $media = Database::select(
            "SELECT m.`id` FROM `media` m
              WHERE (m.`folder` = 'kyc' OR m.`path` LIKE 'kyc/%')
                AND m.`uploader_type` = 'user'
                AND m.`folder_id` IS NULL
                AND m.`created_at` < DATE_SUB(NOW(), INTERVAL {$days} DAY)
                AND NOT EXISTS (
                      SELECT 1 FROM `kyc_documents` d
                       WHERE d.`purged_at` IS NULL
                         AND (d.`front_image_url` LIKE CONCAT('%', m.`path`)
                           OR d.`back_image_url`  LIKE CONCAT('%', m.`path`)
                           OR d.`selfie_url`      LIKE CONCAT('%', m.`path`)))"
                . ($userId === null ? '' : ' AND m.`uploader_id` = :scope_user'),
            $userId === null ? [] : ['scope_user' => $userId]
        );

        if (!$dryRun) {
            foreach ($media as $row) {
                UploadService::purge((int) $row['id']);
            }
        }

        return count($media);
    }

    /**
     * Erase a user's identity data — account deletion's KYC step.
     *
     * For every profile the user has: deletes every stored file (all
     * versions), deletes the detail block (encrypted numbers, hashes, names,
     * addresses), blanks the per-document identifying columns, deletes the
     * reviewers' working notes about the applicant (free text that may quote
     * their documents), removes the tick, and resets the verification flags
     * on `users` and `creators`.
     *
     * Also removes the ID images the retired `/me/upload folder=kyc` route
     * left in the PUBLIC uploads tree (`media` rows in folder `kyc` uploaded
     * by this user) — file and row. Account deletion leaves that folder to
     * this module, and nothing else ever deleted them.
     *
     * Keeps the profile row — reference, kind, final status, dates — and the
     * append-only event trail, as the minimal record that a verification
     * happened and who decided it. `purged_at` marks it.
     *
     * Idempotent, and a no-op for a user who never started KYC.
     *
     * @return array{profiles:int,documents:int,files:int,media:int}
     */
    public static function purgeForUser(int $userId, string $reason): array
    {
        $summary = ['profiles' => 0, 'documents' => 0, 'files' => 0, 'media' => 0];
        $reason  = trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : 'Identity data erased.';

        // Public legacy uploads first, one at a time: a file that cannot be
        // removed must not stop the rest of the erasure.
        foreach (Database::column(
            "SELECT `id` FROM `media`
              WHERE `uploader_type` = 'user' AND `uploader_id` = :id
                AND (`folder` = 'kyc' OR `path` LIKE 'kyc/%')",
            ['id' => $userId]
        ) as $mediaId) {
            try {
                UploadService::purge((int) $mediaId);
                $summary['media']++;
            } catch (\Throwable $e) {
                Logger::error('KYC erasure could not remove a legacy public upload', [
                    'media_id' => (int) $mediaId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $profiles = Database::select(
            'SELECT * FROM `kyc_profiles` WHERE `user_id` = :id',
            ['id' => $userId]
        );

        $documents = Database::select(
            'SELECT `id`, `profile_id`, `file_path`, `front_image_url`, `back_image_url`, `selfie_url`, `purged_at`
               FROM `kyc_documents` WHERE `user_id` = :id',
            ['id' => $userId]
        );

        // Files first: if the database work below fails, a retry finds the
        // rows unchanged and simply tries again; the reverse order could leave
        // rows claiming a purge whose files still exist.
        foreach ($documents as $document) {
            if (DocumentVault::purge((string) ($document['file_path'] ?? ''))) {
                $summary['files']++;
            }

            foreach (['front_image_url', 'back_image_url', 'selfie_url'] as $column) {
                if (DocumentVault::purgeLegacyPublicFile((string) ($document[$column] ?? ''))) {
                    $summary['files']++;
                }
            }

            if ($document['purged_at'] === null) {
                $summary['documents']++;
            }
        }

        Database::transaction(static function () use ($userId, $profiles, $reason, &$summary): void {
            Database::execute(
                'UPDATE `kyc_documents`
                    SET `purged_at` = COALESCE(`purged_at`, NOW()),
                        `file_path` = NULL, `original_name` = NULL, `checksum` = NULL,
                        `document_number` = NULL, `holder_name` = NULL,
                        `front_image_url` = NULL, `back_image_url` = NULL, `selfie_url` = NULL,
                        `uploaded_ip` = NULL
                  WHERE `user_id` = :id',
                ['id' => $userId]
            );

            foreach ($profiles as $profile) {
                $profileId     = (int) $profile['id'];
                $alreadyPurged = $profile['purged_at'] !== null;

                Database::execute('DELETE FROM `kyc_creator_details` WHERE `profile_id` = :id', ['id' => $profileId]);
                Database::execute('DELETE FROM `kyc_brand_details` WHERE `profile_id` = :id', ['id' => $profileId]);

                // Working notes, not the audit record (kyc_events stays).
                $notes = Database::execute('DELETE FROM `kyc_notes` WHERE `profile_id` = :id', ['id' => $profileId]);

                Database::update('kyc_profiles', [
                    'purged_at'            => $profile['purged_at'] ?? date('Y-m-d H:i:s'),
                    'badge_status'         => ($profile['badge_status'] ?? self::BADGE_NONE) === self::BADGE_GRANTED
                        ? self::BADGE_REVOKED
                        : ($profile['badge_status'] ?? self::BADGE_NONE),
                    'badge_revoked_at'     => ($profile['badge_status'] ?? '') === self::BADGE_GRANTED
                        ? date('Y-m-d H:i:s')
                        : $profile['badge_revoked_at'],
                    'is_identity_verified' => 0,
                    'is_business_verified' => 0,
                    'is_premium_verified'  => 0,
                    'resubmit_slots'       => null,
                    'last_ip'              => null,
                    'last_user_agent'      => null,
                ], ['id' => $profileId]);

                if (!$alreadyPurged) {
                    $summary['profiles']++;

                    self::recordEvent($profileId, 'purged', [
                        'actor_type' => 'system',
                        'note'       => $reason,
                        'metadata'   => ['files' => $summary['files'], 'media' => $summary['media'], 'notes' => $notes],
                    ]);
                }
            }

            Database::update('users', [
                'kyc_status'           => 'none',
                'is_identity_verified' => 0,
                'is_business_verified' => 0,
                'is_premium_verified'  => 0,
                'kyc_expires_on'       => null,
                'has_blue_tick'        => 0,
                'blue_tick_at'         => null,
            ], ['id' => $userId]);
        });

        // The tick on the creator side lives in the badge table too.
        if ((int) Database::scalar(
            'SELECT COUNT(*) FROM `creators` WHERE `user_id` = :id AND (`is_verified` = 1 OR `verified_at` IS NOT NULL)',
            ['id' => $userId]
        ) > 0) {
            self::setTickFlags($userId, self::KIND_CREATOR, false, [
                'actor_type' => 'system',
                'reason'     => $reason,
            ]);
        }

        // "KYC verified" / "Identity verified" go with the data behind them,
        // at once rather than at the next nightly badge job.
        self::syncKycBadges($userId);

        return $summary;
    }

    /**
     * The user's own KYC record for "Download my data".
     *
     * Status, dates, the tick and a list of what was uploaded — with every
     * identifier cut to its last four characters. No URLs, no tokens, no file
     * contents: the export must never be a second way to obtain the documents.
     *
     * @return list<array<string,mixed>>
     */
    public static function exportSummary(int $userId): array
    {
        $out = [];

        foreach (Database::select(
            'SELECT * FROM `kyc_profiles` WHERE `user_id` = :id ORDER BY `id`',
            ['id' => $userId]
        ) as $profile) {
            $profileId = (int) $profile['id'];
            $kind      = (string) $profile['kind'];
            $numbers   = [];

            if ($kind === self::KIND_CREATOR) {
                $row = Database::selectOne(
                    'SELECT `pan_number`, `aadhaar_last4` FROM `kyc_creator_details` WHERE `profile_id` = :id',
                    ['id' => $profileId]
                ) ?? [];

                $numbers = [
                    'pan'     => self::lastFour(Crypto::decryptNullable($row['pan_number'] ?? null)),
                    'aadhaar' => KycIdentifiers::maskAadhaarLast4($row['aadhaar_last4'] ?? null),
                ];
            } else {
                $row = Database::selectOne(
                    'SELECT `gst_number`, `cin_number`, `pan_number` FROM `kyc_brand_details` WHERE `profile_id` = :id',
                    ['id' => $profileId]
                ) ?? [];

                $numbers = [
                    'gstin' => self::lastFour(Crypto::decryptNullable($row['gst_number'] ?? null)),
                    'cin'   => self::lastFour(Crypto::decryptNullable($row['cin_number'] ?? null)),
                    'pan'   => self::lastFour(Crypto::decryptNullable($row['pan_number'] ?? null)),
                ];
            }

            $documents = array_map(
                static fn (array $row): array => [
                    'slot'        => $row['slot'],
                    'label'       => $row['title'],
                    'status'      => $row['purged_at'] !== null
                        ? 'deleted'
                        : self::documentStatus($row['status'] ?? null),
                    'uploaded_at' => $row['created_at'],
                ],
                Database::select(
                    'SELECT `slot`, `title`, `status`, `created_at`, `purged_at` FROM `kyc_documents`
                      WHERE `profile_id` = :id ORDER BY `id`',
                    ['id' => $profileId]
                )
            );

            $out[] = [
                'kind'             => $kind,
                'reference'        => $profile['reference'],
                'status'           => self::statusLabel((string) $profile['status']),
                'submitted_at'     => $profile['submitted_at'],
                'reviewed_at'      => $profile['reviewed_at'],
                'approved_at'      => $profile['approved_at'],
                'expires_on'       => $profile['expires_on'],
                'badge_status'     => $profile['badge_status'] ?? self::BADGE_NONE,
                'badge_granted_at' => $profile['badge_granted_at'] ?? null,
                'consent_at'       => $profile['consent_at'] ?? null,
                'consent_version'  => $profile['consent_version'] ?? null,
                'purged_at'        => $profile['purged_at'] ?? null,
                'identifiers'      => $numbers,
                'documents'        => $documents,
            ];
        }

        return $out;
    }

    private static function lastFour(?string $plain): ?string
    {
        return $plain === null || $plain === '' ? null : Crypto::mask($plain, 4);
    }

    // -----------------------------------------------------------------
    // Audit
    // -----------------------------------------------------------------

    /**
     * Append one row to the profile's audit trail.
     *
     * An event caused by a person (actor `user` or `admin`) always carries
     * where it came from: when the caller did not pass `ip` / `agent`, they are
     * taken from the HTTP request being served. Details edits, uploads and
     * document removals used to be recorded with neither, so an account
     * takeover changing a PAN read exactly like the owner doing it. System
     * events (cron, CLI) carry none.
     *
     * @param array<string,mixed> $context
     */
    public static function recordEvent(int $profileId, string $action, array $context = []): void
    {
        $ip        = $context['ip'] ?? null;
        $actorType = (string) ($context['actor_type'] ?? 'system');
        $actorId   = $context['actor_id'] ?? null;

        if (in_array($actorType, ['user', 'admin'], true)
            && (($ip === null || $ip === '') || !isset($context['agent']) || $context['agent'] === '')) {
            [$requestIp, $requestAgent] = self::requestOrigin();

            if ($ip === null || $ip === '') {
                $ip = $requestIp;
            }

            if (!isset($context['agent']) || $context['agent'] === '') {
                $context['agent'] = $requestAgent;
            }
        }

        Database::insert('kyc_events', [
            'profile_id'  => $profileId,
            'actor_type'  => in_array($actorType, ['user', 'admin', 'system'], true) ? $actorType : 'system',
            'actor_id'    => $actorId,
            'actor_name'  => $context['actor_name'] ?? self::actorName($actorType, $actorId),
            'action'      => $action,
            'from_status' => $context['from'] ?? null,
            'to_status'   => $context['to'] ?? null,
            'note'        => isset($context['note']) ? mb_substr((string) $context['note'], 0, 1000) : null,
            'metadata'    => isset($context['metadata']) ? json_encode($context['metadata']) : null,
            'ip_address'  => is_string($ip) && $ip !== '' ? (@inet_pton($ip) ?: null) : null,
            'user_agent'  => isset($context['agent']) ? mb_substr((string) $context['agent'], 0, 255) : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /** @var array{0:?string,1:?string}|null */
    private static ?array $requestOrigin = null;

    /**
     * IP and user agent of the HTTP request being served, or nulls on the
     * command line (cron, tests, scripts).
     *
     * @return array{0:?string,1:?string}
     */
    private static function requestOrigin(): array
    {
        if (PHP_SAPI === 'cli' || !isset($_SERVER['REMOTE_ADDR'])) {
            return [null, null];
        }

        if (self::$requestOrigin === null) {
            try {
                $request = Request::capture();
                $agent   = $request->userAgent();

                self::$requestOrigin = [$request->ip(), $agent === '' ? null : $agent];
            } catch (\Throwable) {
                self::$requestOrigin = [null, null];
            }
        }

        return self::$requestOrigin;
    }

    /**
     * Resolve the human name behind an actor, once, at write time.
     *
     * Stored rather than joined so the trail stays truthful even after the
     * admin is renamed or removed — a KYC audit record has to say who decided
     * it at the moment it was decided.
     */
    private static function actorName(string $actorType, mixed $actorId): ?string
    {
        $id = (int) ($actorId ?? 0);

        if ($id <= 0) {
            return null;
        }

        $table = match ($actorType) {
            'admin' => 'admins',
            'user'  => 'users',
            default => null,
        };

        if ($table === null) {
            return null;
        }

        $name = Database::scalar(
            'SELECT `name` FROM `' . $table . '` WHERE `id` = :id',
            ['id' => $id]
        );

        return $name === null || $name === '' ? null : mb_substr((string) $name, 0, 150);
    }

    /**
     * The audit trail, newest first.
     *
     * [$forAdmin] is the reviewer's view: every event, the recorded IP, the
     * deciding admin's name, and internal notes (a blue-tick grant note is
     * shown after its fixed text).
     *
     * The applicant's own view (GET /me/kyc, /me/kyc/timeline) is the status
     * history of THEIR verification, not the review team's working log:
     *
     *   * internal-only events are left out — internal notes, document views
     *     and downloads, failed-notification markers — filtered in SQL so the
     *     limit still returns a full page;
     *   * an admin actor is shown as "Zusty team", never a reviewer's name,
     *     email or IP (no IP is ever served on this view);
     *   * an admin note reaches the applicant only for decisions whose remarks
     *     are written for them (rejection reasons, information and re-upload
     *     requests, document verdicts, tick removal); any other admin event
     *     carries a fixed public line instead of the reviewer's words. Old rows
     *     that stored an internal note in `note` are covered the same way.
     *
     * @return list<array<string,mixed>>
     */
    public static function timeline(int $profileId, int $limit = 50, bool $forAdmin = false): array
    {
        $hidden = '';

        if (!$forAdmin) {
            // Constants, not input: safe to inline as literals.
            $hidden = ' AND `action` NOT IN (' . implode(', ', array_map(
                static fn (string $action): string => "'" . $action . "'",
                self::INTERNAL_ACTIONS
            )) . ')';
        }

        $rows = Database::select(
            'SELECT `action`, `from_status`, `to_status`, `note`, `actor_type`,
                    `actor_id`, `actor_name`, `ip_address`, `metadata`, `created_at`
               FROM `kyc_events`
              WHERE `profile_id` = :id' . $hidden . '
              ORDER BY `id` DESC
              LIMIT ' . max(1, min(200, $limit)),
            ['id' => $profileId]
        );

        // Rows written before actor_name was stored still carry an actor_id;
        // resolve those on read so the existing history is not blank either.
        $resolved = [];

        foreach ($rows as &$row) {
            $action        = (string) $row['action'];
            $row['label']  = self::eventLabel($action);
            $isAdminActor  = (string) $row['actor_type'] === 'admin';

            if (!$forAdmin && $isAdminActor) {
                // Reviewers are never named to the person they reviewed.
                $row['actor_name'] = self::TEAM_NAME;

                if (!in_array($action, self::APPLICANT_FACING_ADMIN_NOTES, true)) {
                    $row['note'] = self::publicNote($action);
                }
            } elseif (($row['actor_name'] ?? null) === null || $row['actor_name'] === '') {
                $cacheKey = $row['actor_type'] . ':' . (int) ($row['actor_id'] ?? 0);

                if (!array_key_exists($cacheKey, $resolved)) {
                    $resolved[$cacheKey] = self::actorName(
                        (string) $row['actor_type'],
                        $row['actor_id'] ?? null
                    );
                }

                $row['actor_name'] = $resolved[$cacheKey];
            }

            if ($forAdmin) {
                $packed    = $row['ip_address'] ?? null;
                $row['ip'] = is_string($packed) && $packed !== '' ? (@inet_ntop($packed) ?: null) : null;

                $metadata = json_decode((string) ($row['metadata'] ?? ''), true);

                if (is_array($metadata) && trim((string) ($metadata['internal_note'] ?? '')) !== '') {
                    $row['note'] = trim((string) $row['note'] . ' Internal note: ' . $metadata['internal_note']);
                }
            }

            // actor_id was selected only to resolve the name above; it is an
            // internal admin/user id and was never part of this payload.
            unset($row['actor_id'], $row['ip_address'], $row['metadata']);
        }
        unset($row);

        return $rows;
    }

    /** The line an applicant sees for an admin event whose note is internal. */
    private static function publicNote(string $action): string
    {
        return match ($action) {
            'badge_granted'       => 'Blue tick granted.',
            'identifier_released' => 'An identity number on this verification was cleared by the Zusty team.',
            default               => self::eventLabel($action) . '.',
        };
    }

    // -----------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------

    /**
     * @param  array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public static function presentProfile(array $profile): array
    {
        $status      = (string) $profile['status'];
        $badgeStatus = (string) ($profile['badge_status'] ?? self::BADGE_NONE);

        return [
            'id'                       => (int) $profile['id'],
            'reference'                => $profile['reference'],
            'kind'                     => $profile['kind'],
            'status'                   => $status,
            'status_label'             => self::statusLabel($status),
            'status_tone'              => self::statusTone($status),
            'completeness'             => (int) $profile['completeness'],
            'submission_count'         => (int) $profile['submission_count'],
            'submitted_at'             => $profile['submitted_at'],
            'reviewed_at'              => $profile['reviewed_at'],
            'approved_at'              => $profile['approved_at'],
            'expires_on'               => $profile['expires_on'],
            'rejection_reason'         => $profile['rejection_reason'],
            'info_requested'           => $profile['info_requested'],
            'resubmit_slots'           => self::resubmitSlots($profile),
            'is_editable'              => self::isEditable($status),
            'editable'                 => self::isEditable($status),
            'badge_status'             => $badgeStatus,
            'badge_granted_at'         => $badgeStatus === self::BADGE_GRANTED ? ($profile['badge_granted_at'] ?? null) : null,
            'has_blue_tick'            => $badgeStatus === self::BADGE_GRANTED,
            'consent_at'               => $profile['consent_at'] ?? null,
            'consent_version'          => $profile['consent_version'] ?? null,
            'consent_version_required' => self::consentVersion(),
            'consent_text'             => self::consentText(),
            // Processing consent (contract C3): asked before the first upload
            // or detail save, and again whenever the wording's version moves.
            'processing_consent_at'       => $profile['processing_consent_at'] ?? null,
            'processing_consent_version'  => $profile['processing_consent_version'] ?? null,
            'processing_consent_required' => !self::hasProcessingConsent($profile),
            'is_purged'                => ($profile['purged_at'] ?? null) !== null,
            'badges'                   => self::badgesFor($profile),
        ];
    }

    /**
     * A comma-separated format list as the vault will actually accept it:
     * trimmed, lower-cased, and limited to the formats it can check by content.
     *
     * @return list<string>
     */
    public static function formatList(string $raw): array
    {
        return array_values(array_intersect(
            array_values(array_unique(array_filter(array_map(
                static fn (string $item): string => strtolower(trim($item)),
                explode(',', $raw)
            )))),
            DocumentVault::supportedExtensions()
        ));
    }

    /**
     * A document as its owner sees it: the vault presentation plus the inline
     * preview URL for the app.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function presentUserDocument(array $row, int $userId, string $apiBase = ''): array
    {
        $presented = DocumentVault::present($row, 'user', $userId);

        $presented['preview_url'] = $presented['download_token'] === null || $presented['is_purged']
            ? null
            : rtrim($apiBase, '/') . '/me/kyc/documents/' . (int) $row['id']
                . '/preview?token=' . rawurlencode((string) $presented['download_token']);

        return $presented;
    }

    /**
     * @param  list<array<string,mixed>> $requirements
     * @param  list<array<string,mixed>> $documents
     * @param  list<string>              $resubmitSlots
     * @return list<array<string,mixed>>
     */
    private static function presentRequirements(array $requirements, array $documents, array $resubmitSlots = []): array
    {
        $bySlot = [];
        foreach ($documents as $document) {
            $bySlot[(string) $document['slot']][] = $document;
        }

        return array_map(
            static function (array $requirement) use ($bySlot, $resubmitSlots): array {
                $slot = (string) $requirement['slot'];
                $docs = $bySlot[$slot] ?? [];

                $statuses = array_map(
                    static fn (array $d): string => self::documentStatus($d['status'] ?? null),
                    $docs
                );

                $rejected = array_values(array_filter(
                    $docs,
                    static fn (array $d): bool => self::documentStatus($d['status'] ?? null) === 'rejected'
                ));

                $reviewStatus = match (true) {
                    $docs === []                                         => 'missing',
                    in_array('rejected', $statuses, true)                => 'rejected',
                    array_unique($statuses) === ['approved']             => 'approved',
                    default                                              => 'pending',
                };

                return [
                    'slot'               => $slot,
                    'label'              => $requirement['label'],
                    'description'        => $requirement['description'],
                    'document_type'      => $requirement['document_type'],
                    'is_required'        => (bool) $requirement['is_required'],
                    'allows_multiple'    => (bool) $requirement['allows_multiple'],
                    'requires_expiry'    => (bool) $requirement['requires_expiry'],
                    'allowed_formats'    => self::formatList((string) $requirement['allowed_formats']),
                    'max_size_mb'        => (int) $requirement['max_size_mb'],
                    'document_ids'       => array_map(static fn (array $d): int => (int) $d['id'], $docs),
                    'is_satisfied'       => count($docs) > count($rejected),
                    'review_status'      => $reviewStatus,
                    'rejection_reason'   => $rejected === [] ? null : ($rejected[0]['rejection_reason'] ?? null),
                    'resubmit_requested' => in_array($slot, $resubmitSlots, true),
                ];
            },
            $requirements
        );
    }

    /** @param array<string,mixed> $profile */
    public static function badgesFor(array $profile): array
    {
        if (!Config::settingBool('kyc', 'badges_enabled', true)) {
            return [];
        }

        $badges = [];

        if ((int) ($profile['is_identity_verified'] ?? 0) === 1) {
            $badges[] = ['code' => 'identity_verified', 'label' => 'Identity Verified', 'icon' => 'verified_user'];
        }

        if ((int) ($profile['is_business_verified'] ?? 0) === 1) {
            $badges[] = ['code' => 'business_verified', 'label' => 'Business Verified', 'icon' => 'domain_verification'];
        }

        // The "Verified Creator / Verified Brand" badge is the blue tick, so it
        // follows the tick — not the KYC approval it used to be derived from.
        if (($profile['badge_status'] ?? self::BADGE_NONE) === self::BADGE_GRANTED) {
            $badges[] = ($profile['kind'] ?? '') === self::KIND_BRAND
                ? ['code' => 'verified_brand', 'label' => 'Verified Brand', 'icon' => 'verified']
                : ['code' => 'verified_creator', 'label' => 'Verified Creator', 'icon' => 'verified'];
        }

        if ((int) ($profile['is_premium_verified'] ?? 0) === 1) {
            $badges[] = ['code' => 'premium_verified', 'label' => 'Premium Verified', 'icon' => 'workspace_premium'];
        }

        return $badges;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::DRAFT         => 'Draft',
            self::SUBMITTED     => 'Pending',
            self::UNDER_REVIEW  => 'Under review',
            self::INFO_REQUIRED => 'Action needed',
            self::APPROVED      => 'Approved',
            self::REJECTED      => 'Rejected',
            self::SUSPENDED     => 'Suspended',
            self::EXPIRED       => 'Expired',
            default             => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            self::APPROVED                            => 'success',
            self::SUBMITTED, self::UNDER_REVIEW       => 'info',
            self::INFO_REQUIRED, self::EXPIRED        => 'warning',
            self::REJECTED, self::SUSPENDED           => 'error',
            default                                   => 'neutral',
        };
    }

    private static function eventLabel(string $action): string
    {
        return match ($action) {
            'submitted'              => 'Submitted for review',
            'under_review'           => 'Review started',
            'approved'               => 'Approved',
            'rejected'               => 'Rejected',
            'info_required'          => 'More information requested',
            'resubmission_requested' => 'Re-upload requested',
            'suspended'              => 'Suspended',
            'expired'                => 'Expired',
            'document_uploaded'      => 'Document uploaded',
            'document_replaced'      => 'Document re-uploaded',
            'document_removed'       => 'Document removed',
            'document_approved'      => 'Document approved',
            'document_rejected'      => 'Document rejected',
            'document_downloaded'    => 'Document downloaded',
            'document_previewed'     => 'Document viewed',
            'documents_purged'       => 'Documents and details deleted (retention)',
            'processing_consent_given' => 'Consent to processing given',
            'reopened'               => 'Verification started again',
            'identifier_released'    => 'Identity number released',
            'details_updated'        => 'Details updated',
            'note_added'             => 'Internal note added',
            'badge_granted'          => 'Blue tick granted',
            'badge_revoked'          => 'Blue tick removed',
            'reinstated'             => 'Suspension lifted, back under review',
            'document_expired'       => 'Document expired',
            'notification_failed'    => 'Notification could not be sent',
            'expiry_reminder'        => 'Expiry reminder sent',
            'purged'                 => 'Identity data erased',
            default                  => ucfirst(str_replace('_', ' ', $action)),
        };
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    public static function find(int $profileId): array
    {
        return Database::selectOne(
            'SELECT * FROM `kyc_profiles` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $profileId]
        ) ?? throw HttpException::notFound('Verification profile not found.');
    }

    /**
     * The slots a reviewer asked to have re-uploaded.
     *
     * @param  array<string,mixed> $profile
     * @return list<string>
     */
    public static function resubmitSlots(array $profile): array
    {
        $raw = $profile['resubmit_slots'] ?? null;

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * Requested slots that have not had a fresh, non-rejected upload since the
     * request was made (the request stamps `reviewed_at`).
     *
     * @param  array<string,mixed>       $profile
     * @param  list<array<string,mixed>> $documents
     * @return list<string> Labels.
     */
    private static function slotsAwaitingReupload(array $profile, array $documents): array
    {
        $slots = self::resubmitSlots($profile);

        if ($slots === []) {
            return [];
        }

        $since   = strtotime((string) ($profile['reviewed_at'] ?? '')) ?: 0;
        $waiting = [];
        $labels  = [];

        foreach (self::requirements((string) $profile['kind']) as $requirement) {
            $labels[(string) $requirement['slot']] = (string) $requirement['label'];
        }

        foreach ($slots as $slot) {
            $fresh = false;

            foreach ($documents as $document) {
                if ((string) $document['slot'] === $slot
                    && self::documentStatus($document['status'] ?? null) !== 'rejected'
                    && (strtotime((string) $document['created_at']) ?: 0) >= $since) {
                    $fresh = true;
                    break;
                }
            }

            if (!$fresh) {
                $waiting[] = $labels[$slot] ?? $slot;
            }
        }

        return $waiting;
    }

    /**
     * The identity details a submission (and an approval) cannot go without,
     * re-validated from what is stored — a value saved before these rules
     * existed does not get a pass.
     *
     * @return list<string> Labels of what is missing or invalid.
     */
    public static function detailIssues(int $profileId, string $kind): array
    {
        $issues = [];

        if (self::normaliseKind($kind) === self::KIND_BRAND) {
            $row = Database::selectOne(
                'SELECT `company_name`, `company_type`, `gst_number`, `cin_number`, `pan_number`, `registered_address`
                   FROM `kyc_brand_details` WHERE `profile_id` = :id',
                ['id' => $profileId]
            ) ?? [];

            $gst = Crypto::decryptNullable($row['gst_number'] ?? null);
            $cin = Crypto::decryptNullable($row['cin_number'] ?? null);
            $pan = Crypto::decryptNullable($row['pan_number'] ?? null);

            if (trim((string) ($row['company_name'] ?? '')) === '') {
                $issues[] = 'Company name';
            }

            if (($row['company_type'] ?? null) === null) {
                $issues[] = 'Company type';
            }

            if ($gst === null || !KycIdentifiers::isValidGstin($gst)) {
                $issues[] = 'GSTIN';
            }

            // The company PAN is optional, but a stored one must be a real PAN
            // and the one the GSTIN embeds (characters 3-12) — saveDetails()
            // checks both on entry; a value saved before that rule is caught
            // here rather than waved through to approval.
            if ($pan !== null && trim($pan) !== '') {
                if (!KycIdentifiers::isValidPan($pan)) {
                    $issues[] = 'Company PAN';
                } elseif ($gst !== null && KycIdentifiers::isValidGstin($gst)
                    && KycIdentifiers::panInGstin($gst) !== KycIdentifiers::normalize($pan)) {
                    $issues[] = 'Company PAN (does not match the GSTIN)';
                }
            }

            if (($row['company_type'] ?? null) !== null
                && KycIdentifiers::companyTypeRequiresCin((string) $row['company_type'])
                && ($cin === null || !KycIdentifiers::isValidCin($cin))) {
                $issues[] = 'CIN';
            }

            if (trim((string) ($row['registered_address'] ?? '')) === '') {
                $issues[] = 'Registered address';
            }

            return $issues;
        }

        $row = Database::selectOne(
            'SELECT `full_name`, `date_of_birth`, `pan_number`, `aadhaar_last4`
               FROM `kyc_creator_details` WHERE `profile_id` = :id',
            ['id' => $profileId]
        ) ?? [];

        $pan = Crypto::decryptNullable($row['pan_number'] ?? null);

        if (trim((string) ($row['full_name'] ?? '')) === '') {
            $issues[] = 'Full name';
        }

        if (($row['date_of_birth'] ?? null) === null) {
            $issues[] = 'Date of birth';
        }

        if ($pan === null || !KycIdentifiers::isPersonalPan($pan)) {
            $issues[] = 'Personal PAN';
        }

        if (($row['aadhaar_last4'] ?? null) === null || $row['aadhaar_last4'] === '') {
            $issues[] = 'Aadhaar number';
        }

        return $issues;
    }

    private static function normaliseKind(string $kind): string
    {
        return $kind === self::KIND_CREATOR ? self::KIND_CREATOR : self::KIND_BRAND;
    }

    private static function isEditable(string $status): bool
    {
        return in_array(
            $status,
            [self::DRAFT, self::INFO_REQUIRED, self::REJECTED, self::EXPIRED],
            true
        );
    }

    /** @param array<string,mixed> $profile */
    private static function assertEditable(array $profile): void
    {
        if (self::isEditable((string) $profile['status'])) {
            return;
        }

        throw HttpException::conflict(
            'This verification is ' . strtolower(self::statusLabel((string) $profile['status']))
            . ' and cannot be edited right now.',
            'kyc_locked'
        );
    }

    /**
     * Uploads are allowed while the profile is editable, or into a slot the
     * reviewer explicitly asked to have re-uploaded.
     *
     * @param array<string,mixed> $profile
     */
    private static function assertCanUpload(array $profile, string $slot): void
    {
        if (!self::isEditable((string) $profile['status'])
            && in_array($slot, self::resubmitSlots($profile), true)) {
            return;
        }

        self::assertEditable($profile);
    }

    /**
     * Per-account upload caps, so one free account cannot fill the disk or
     * bury the review queue:
     *
     *   * files kept at once in a multi-file slot (`kyc.max_files_per_slot`,
     *     default 5) — a replacement swaps one, so it is not counted;
     *   * copies of one document held at once, current plus replaced ones not
     *     yet deleted by retention (`kyc.max_versions_per_slot`, default 10);
     *   * total bytes the account's verification documents take up
     *     (`kyc.max_profile_mb`, default 100 MB).
     *
     * Each refusal is a 422 (`kyc_upload_limit`) that says what to do.
     *
     * Replaced copies only leave the count when the retention job deletes
     * them, so before refusing on the copies or storage cap the copies already
     * past `purge_superseded_after_days` are erased on the spot
     * ({@see purgeAgedSuperseded()}) — a user is never locked out because the
     * nightly job has not run. [$mayPurge] is false for the re-check inside
     * the upload's transaction: a file deleted there could not be put back if
     * the transaction rolled back.
     *
     * @param array<string,mixed> $requirement
     */
    private static function assertWithinUploadCaps(
        int $profileId,
        int $userId,
        array $requirement,
        bool $replacing,
        int $incomingBytes,
        bool $mayPurge = true
    ): void {
        $slot  = (string) $requirement['slot'];
        $label = (string) $requirement['label'];

        if ((int) $requirement['allows_multiple'] === 1 && !$replacing) {
            $maxFiles = max(1, Config::settingInt('kyc', 'max_files_per_slot', 5));
            $files    = (int) Database::scalar(
                'SELECT COUNT(*) FROM `kyc_documents`
                  WHERE `profile_id` = :p AND `slot` = :s AND `is_current` = 1
                    AND `deleted_at` IS NULL AND `purged_at` IS NULL',
                ['p' => $profileId, 's' => $slot]
            );

            if ($files >= $maxFiles) {
                throw new HttpException(
                    'You can keep up to ' . $maxFiles . ' files in ' . $label . '. Remove one before adding another.',
                    422,
                    'kyc_upload_limit'
                );
            }
        }

        $maxVersions = max(1, Config::settingInt('kyc', 'max_versions_per_slot', 10));
        $countCopies = static fn (): int => (int) Database::scalar(
            'SELECT COUNT(*) FROM `kyc_documents`
              WHERE `profile_id` = :p AND `slot` = :s AND `deleted_at` IS NULL AND `purged_at` IS NULL',
            ['p' => $profileId, 's' => $slot]
        );
        $copies = $countCopies();

        if ($copies >= $maxVersions && $mayPurge && self::purgeAgedSuperseded($userId, $profileId, $slot) > 0) {
            $copies = $countCopies();
        }

        if ($copies >= $maxVersions) {
            $days = max(1, Config::settingInt('kyc', 'purge_superseded_after_days', 30));

            throw new HttpException(
                'You already have ' . $copies . ' copies of ' . $label . ' on file, the most we keep at once. '
                    . 'Replaced copies are deleted automatically ' . $days . ' days after they were replaced. '
                    . 'To upload it again before then, please contact support.',
                422,
                'kyc_upload_limit'
            );
        }

        $maxMb     = max(1, Config::settingInt('kyc', 'max_profile_mb', 100));
        $usedBytes = static fn (): int => (int) Database::scalar(
            'SELECT COALESCE(SUM(`file_size`), 0) FROM `kyc_documents`
              WHERE `user_id` = :u AND `deleted_at` IS NULL AND `purged_at` IS NULL',
            ['u' => $userId]
        );
        $used = $usedBytes();

        if ($used + max(0, $incomingBytes) > $maxMb * 1024 * 1024
            && $mayPurge && self::purgeAgedSuperseded($userId) > 0) {
            $used = $usedBytes();
        }

        if ($used + max(0, $incomingBytes) > $maxMb * 1024 * 1024) {
            throw new HttpException(
                'Your verification documents already use ' . DocumentVault::humanSize($used) . ' of the '
                    . $maxMb . ' MB allowed per account. Remove a document you no longer need before uploading more.',
                422,
                'kyc_upload_limit'
            );
        }
    }

    /**
     * A profile whose documents and details were erased by the retention
     * sweep (`purged_at` set) is started over by its owner's next save or
     * upload: the marker is cleared and a `reopened` event records when the
     * earlier data was erased. Without this, an applicant who came back after
     * the window could upload everything again and still never be approved
     * ("this profile's documents were purged").
     *
     * Only for a profile its owner may edit; never for an erased account.
     *
     * @param array<string,mixed> $profile
     */
    private static function reopenIfPurged(array $profile, int $userId, ?string $ip = null, ?string $agent = null): void
    {
        if (($profile['purged_at'] ?? null) === null || !self::isEditable((string) $profile['status'])) {
            return;
        }

        $changed = Database::execute(
            'UPDATE `kyc_profiles` SET `purged_at` = NULL WHERE `id` = :id AND `purged_at` IS NOT NULL',
            ['id' => (int) $profile['id']]
        );

        if ($changed === 1) {
            self::recordEvent((int) $profile['id'], 'reopened', [
                'actor_type' => 'user',
                'actor_id'   => $userId,
                'note'       => 'Verification started again after its earlier documents and details were deleted.',
                'metadata'   => ['purged_at' => $profile['purged_at']],
                'ip'         => $ip,
                'agent'      => $agent,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Processing consent
    // -----------------------------------------------------------------

    /**
     * Has this profile's owner agreed to the processing of their identity
     * data under the CURRENT consent wording?
     *
     * @param array<string,mixed> $profile
     */
    public static function hasProcessingConsent(array $profile): bool
    {
        return ($profile['processing_consent_at'] ?? null) !== null
            && (string) ($profile['processing_consent_version'] ?? '') === mb_substr(self::consentVersion(), 0, 20);
    }

    /**
     * The gate in front of every upload and detail save (contract C3).
     *
     * Identity documents and numbers are processed only after the applicant
     * has agreed to it — captured here, before the first upload or save, and
     * separately from the consent given at submission (DPDP Act 2023). When
     * no consent is on record for the current wording, [$consent] must be
     * true: it is then recorded on the profile (`processing_consent_at`,
     * `processing_consent_version`) with a `processing_consent_given` event.
     * Otherwise the request is refused with a 422 keyed `consent`. Once on
     * record, nothing more is asked until the wording's version changes.
     *
     * @throws ValidationException
     */
    public static function requireProcessingConsent(
        int $userId,
        string $kind,
        bool $consent,
        ?string $ip = null,
        ?string $agent = null
    ): void {
        $profile = self::profileFor($userId, $kind);

        if (self::hasProcessingConsent($profile)) {
            return;
        }

        if (!$consent) {
            throw ValidationException::forField('consent', 'Please agree to the processing of your documents first.');
        }

        $profileId = (int) $profile['id'];
        $version   = mb_substr(self::consentVersion(), 0, 20);

        Database::transaction(static function () use ($profileId, $userId, $version, $ip, $agent): void {
            $locked = Database::lockForUpdate('kyc_profiles', 'id', $profileId);

            // A parallel request may have recorded it a moment ago.
            if ($locked === null || self::hasProcessingConsent($locked)) {
                return;
            }

            Database::update('kyc_profiles', [
                'processing_consent_at'      => date('Y-m-d H:i:s'),
                'processing_consent_version' => $version,
            ], ['id' => $profileId]);

            self::recordEvent($profileId, 'processing_consent_given', [
                'actor_type' => 'user',
                'actor_id'   => $userId,
                'note'       => 'Agreed to the processing of identity documents for verification.',
                'metadata'   => ['consent_version' => $version],
                'ip'         => $ip,
                'agent'      => $agent,
            ]);
        });
    }

    /** @param list<array<string,mixed>> $requirements */
    private static function canSubmit(array $profile, array $requirements, array $documents): bool
    {
        return self::isEditable((string) $profile['status'])
            && self::missingSlots($requirements, $documents) === []
            && self::slotsAwaitingReupload($profile, $documents) === []
            && self::detailIssues((int) $profile['id'], (string) $profile['kind']) === []
            && self::identifierCollisions((int) $profile['id']) === [];
    }

    /** The consent wording version new submissions agree to. */
    public static function consentVersion(): string
    {
        $version = trim(Config::settingString('kyc', 'consent_version', '2026-10'));

        return $version !== '' ? $version : '2026-10';
    }

    public static function consentText(): string
    {
        $text = trim(Config::settingString('kyc', 'consent_text', self::DEFAULT_CONSENT_TEXT));

        return $text !== '' ? $text : self::DEFAULT_CONSENT_TEXT;
    }

    /**
     * Send a kyc.* notification with the recipient's name filled in.
     *
     * Every kyc.* email opens "Hi {{name}}", and nothing ever supplied it, so
     * each one went out as "Hi ,".
     *
     * Channels come from Settings → KYC → "Notification channels"
     * (`kyc.notify_channels`), with the in-app row always kept, so that
     * setting governs every KYC status message — including "under review",
     * whose template had no email channel. An unset or unusable setting falls
     * back to each template's own channels.
     *
     * Never throws. It runs after the decision has committed, and a failure
     * to notify (a locked queue table, a bad template) must not turn a
     * recorded decision into a 500, abort a bulk run or stop the expiry sweep
     * mid-loop. The failure is logged and, when [$profileId] is known, marked
     * on the admin trail as `notification_failed` (never shown to the user).
     *
     * @param array<string,mixed> $values
     */
    private static function notify(int $userId, string $template, array $values, ?int $profileId = null): void
    {
        try {
            if (!array_key_exists('name', $values)) {
                $name           = Database::scalar('SELECT `name` FROM `users` WHERE `id` = :id', ['id' => $userId]);
                $values['name'] = $name === null || trim((string) $name) === '' ? 'there' : (string) $name;
            }

            $channels = self::notificationChannels();

            NotificationService::send($userId, $template, $values, $channels === [] ? [] : ['channels' => $channels]);
        } catch (\Throwable $e) {
            try {
                Logger::error('KYC notification could not be sent', [
                    'template' => $template,
                    'user_id'  => $userId,
                    'error'    => $e->getMessage(),
                ]);

                if ($profileId !== null) {
                    self::recordEvent($profileId, 'notification_failed', [
                        'actor_type' => 'system',
                        'note'       => 'The "' . $template . '" notification could not be sent.',
                        'metadata'   => ['template' => $template, 'error' => mb_substr($e->getMessage(), 0, 300)],
                    ]);
                }
            } catch (\Throwable) {
                // Nothing more can be done; the decision itself stands.
            }
        }
    }

    /**
     * The channels KYC status messages go out on, from `kyc.notify_channels`.
     * Empty when the setting is missing or unusable (each template decides).
     *
     * @return list<string>
     */
    private static function notificationChannels(): array
    {
        $raw = Config::setting('kyc', 'notify_channels');

        $list = is_array($raw)
            ? $raw
            : (is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null);

        if (!is_array($list)) {
            return [];
        }

        $channels = array_values(array_intersect(
            ['in_app', 'email', 'push', 'sms'],
            array_map('strval', array_filter($list, 'is_scalar'))
        ));

        if ($channels === []) {
            return [];
        }

        // The in-app row is the user's own record of what happened to their
        // verification; it is not optional.
        return in_array('in_app', $channels, true) ? $channels : array_merge(['in_app'], $channels);
    }

    private static function nextReference(string $kind): string
    {
        $prefix = $kind === self::KIND_BRAND ? 'KYC-B-' : 'KYC-C-';

        $last = (int) Database::scalar(
            'SELECT COUNT(*) FROM `kyc_profiles` WHERE `kind` = :kind',
            ['kind' => $kind]
        );

        // Collisions are still possible under concurrency; the unique index is
        // the real guard, so retry with a random suffix if it ever fires.
        $candidate = $prefix . str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);

        if (Database::exists('kyc_profiles', ['reference' => $candidate])) {
            $candidate = $prefix . strtoupper(substr(Crypto::randomToken(4), 0, 6));
        }

        return $candidate;
    }
}

<?php

declare(strict_types=1);

/**
 * KYC and business verification.
 *
 * Covers the public requirement rulebook, the draft overview, partial detail
 * autosave, document upload validation, the submit gate, the audit timeline,
 * and — the point of this suite — the document vault's viewer binding: an
 * encrypted identity document must only ever reach the account it belongs to,
 * and only through a signed token minted for that exact viewer and that exact
 * document.
 *
 * Expectations updated for migration 044 (intentional product change): the
 * required sets are now GST certificate + Certificate of Incorporation for
 * brands and Aadhaar front/back + PAN for creators; identifiers are format-
 * and checksum-validated; submit needs `consent: true`; "submitted" is
 * labelled "Pending". The deeper coverage of those rules is in
 * 15_kyc_verification.php.
 *
 * Controllers read before writing:
 *   app/Controllers/Api/KycController.php
 *   app/Services/KycService.php
 *   app/Services/DocumentVault.php
 *   routes/api.php (lines 99, 129-138)
 */

use Zuste\Core\Database;
use Zuste\Services\DocumentVault;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// A run makes hundreds of calls in seconds; without this the throttle
// fires mid-suite and the cascade of 429s hides real failures.
Suite::clearRateLimits();

// -----------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------

$kycBrand   = Actors::create('brand', 'brand');
$kycCreator = Actors::create('creator', 'creator');

/** Resolve an actor's user id, falling back to the row when the auth payload omitted it. */
$kycUserId = static function (string $actor): int {
    $id = Actors::id($actor);

    if ($id > 0) {
        return $id;
    }

    return (int) (Database::scalar(
        'SELECT `id` FROM `users` WHERE `email` = :email',
        ['email' => Actors::email($actor)]
    ) ?? 0);
};

/**
 * Return a throwaway actor to "never started verification".
 *
 * The suite asserts what a *fresh* actor sees, and these accounts are reused
 * across runs, so the previous run's profile has to go first. Scoped strictly
 * to the `it-` throwaway user ids — no seeded row is touched. The same sweep
 * runs at the end so nothing is left behind.
 */
$kycReset = static function (int $userId): void {
    if ($userId <= 0) {
        return;
    }

    $documents = Database::select(
        'SELECT `file_path` FROM `kyc_documents` WHERE `user_id` = :user_id',
        ['user_id' => $userId]
    );

    foreach ($documents as $document) {
        DocumentVault::purge((string) $document['file_path']);
    }

    // kyc_events / kyc_*_details / kyc_documents all cascade from kyc_profiles.
    Database::execute('DELETE FROM `kyc_documents` WHERE `user_id` = :user_id', ['user_id' => $userId]);
    Database::execute('DELETE FROM `kyc_profiles` WHERE `user_id` = :user_id', ['user_id' => $userId]);
    Database::execute('UPDATE `users` SET `kyc_profile_id` = NULL WHERE `id` = :user_id', ['user_id' => $userId]);
};

$brandUserId   = $kycUserId('brand');
$creatorUserId = $kycUserId('creator');

// A 1x1 PNG — small, but a genuine PNG: the vault sniffs the content type and
// refuses anything whose bytes disagree with its extension.
$pngBytes = (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    true
);

$pngPath = sys_get_temp_dir() . '/it-kyc-proof.png';
$phpPath = sys_get_temp_dir() . '/it-kyc-payload.php';

file_put_contents($pngPath, $pngBytes);
file_put_contents($phpPath, "<?php echo 'this should never be stored'; ?>\n");

/** Tracks whether the kyc_upload bucket (40/hour) has been exhausted mid-run. */
$uploadThrottled = false;

$kycUpload = static function (
    string $slot,
    string $file,
    string $actor,
    array $extra = []
) use (&$uploadThrottled): \Zuste\Tests\Res {
    $res = Http::upload('/me/kyc/documents', ['file' => $file], ['slot' => $slot] + $extra, $actor);

    if ($res->status === 429) {
        $uploadThrottled = true;
    }

    return $res;
};

$kycBrandDocId  = 0;
$kycBrandToken  = '';
$kycGstDocId    = 0;
$kycSelfieDocId = 0;
$kycSelfieToken = '';

// -----------------------------------------------------------------
Suite::group('KYC — the public rulebook');

$brandRules = Http::get('/kyc/requirements');
Suite::ok('GET /kyc/requirements is reachable without a token', $brandRules);
Suite::equals('the rulebook defaults to the brand kind', $brandRules->at('data.kind'), 'brand');

$brandSlots = array_column($brandRules->at('data.requirements') ?? [], 'slot');
Suite::notEmpty('the brand rulebook lists requirements', $brandSlots);

Suite::check(
    'the brand rulebook requires the GST certificate and the Certificate of Incorporation',
    static function () use ($brandRules): bool {
        $required = array_column(
            array_values(array_filter(
                $brandRules->at('data.requirements') ?? [],
                static fn (array $r): bool => $r['is_required'] === true
            )),
            'slot'
        );

        sort($required);

        return $required === ['gst_certificate', 'incorporation_certificate'];
    },
    'required brand slots were: ' . json_encode(array_column($brandRules->at('data.requirements') ?? [], 'slot'))
);

Suite::check(
    'each requirement is fully described for the client',
    static function () use ($brandRules): bool {
        foreach ($brandRules->at('data.requirements') ?? [] as $requirement) {
            foreach (['slot', 'label', 'is_required', 'allows_multiple', 'requires_expiry', 'allowed_formats', 'max_size_mb'] as $key) {
                if (!array_key_exists($key, $requirement)) {
                    return false;
                }
            }

            if (!is_array($requirement['allowed_formats']) || $requirement['allowed_formats'] === []) {
                return false;
            }

            if (!is_bool($requirement['is_required']) || (int) $requirement['max_size_mb'] <= 0) {
                return false;
            }
        }

        return true;
    },
    $brandRules->describe(400)
);

Suite::check(
    'the policy block tells a prospective user the ground rules',
    static function () use ($brandRules): bool {
        $policy = $brandRules->at('data.policy');

        return is_array($policy)
            && is_bool($policy['mandatory'] ?? null)
            && (int) ($policy['max_upload_mb'] ?? 0) > 0
            && is_array($policy['allowed_formats'] ?? null)
            && (int) ($policy['expiry_months'] ?? -1) >= 0;
    },
    json_encode($brandRules->at('data.policy'))
);

$creatorRules = Http::get('/kyc/requirements', ['kind' => 'creator']);
Suite::ok('GET /kyc/requirements?kind=creator is reachable without a token', $creatorRules);
Suite::equals('the creator rulebook reports its own kind', $creatorRules->at('data.kind'), 'creator');

$creatorSlots = array_column($creatorRules->at('data.requirements') ?? [], 'slot');

Suite::check(
    'the creator rulebook requires Aadhaar (front and back) and PAN',
    static function () use ($creatorRules): bool {
        $required = array_column(
            array_values(array_filter(
                $creatorRules->at('data.requirements') ?? [],
                static fn (array $r): bool => $r['is_required'] === true
            )),
            'slot'
        );

        sort($required);

        return $required === ['aadhaar_back', 'aadhaar_front', 'pan_card'];
    },
    'required creator slots were: ' . json_encode($creatorSlots)
);

Suite::check(
    'the two rulebooks are genuinely different documents',
    $brandSlots !== $creatorSlots
        && in_array('gst_certificate', $brandSlots, true)
        && !in_array('gst_certificate', $creatorSlots, true)
        && in_array('selfie', $creatorSlots, true)
        && !in_array('selfie', $brandSlots, true),
    'brand=' . json_encode($brandSlots) . ' creator=' . json_encode($creatorSlots)
);

$oddKind = Http::get('/kyc/requirements', ['kind' => 'not-a-kind']);
Suite::equals('an unrecognised kind falls back to brand rather than erroring', $oddKind->at('data.kind'), 'brand');

Suite::check(
    'the public rulebook never carries anybody\'s documents',
    static function () use ($brandRules): bool {
        $data = $brandRules->data();

        return !array_key_exists('documents', $data)
            && !array_key_exists('profile', $data)
            && !str_contains($brandRules->body, 'download_token');
    },
    $brandRules->describe(300)
);

// -----------------------------------------------------------------
Suite::group('KYC — the authentication boundary');

foreach ([
    'GET /me/kyc'          => Http::get('/me/kyc'),
    'GET /me/kyc/timeline' => Http::get('/me/kyc/timeline'),
] as $label => $response) {
    Suite::status($label . ' without a token is 401', $response, 401);
}

Suite::status('PUT /me/kyc/details without a token is 401', Http::put('/me/kyc/details', ['industry' => 'Retail']), 401);
Suite::status('POST /me/kyc/submit without a token is 401', Http::post('/me/kyc/submit', []), 401);
Suite::status(
    'GET /me/kyc with a malformed token is 401',
    Http::get('/me/kyc', [], null, ['Authorization' => 'Bearer not.a.real.token']),
    401
);

// -----------------------------------------------------------------
Suite::group('KYC — a fresh profile');

if ($kycBrand === null || $kycCreator === null || $brandUserId <= 0 || $creatorUserId <= 0) {
    Suite::skipGroup(
        'KYC — a fresh profile',
        'could not obtain both a brand and a creator session: ' . Actors::lastError()
    );
} else {
    // Wipe the previous run's verification state so "fresh" means fresh.
    $kycReset($brandUserId);
    $kycReset($creatorUserId);

    $overview = Http::get('/me/kyc', [], 'brand');
    Suite::ok('GET /me/kyc opens a draft on first access', $overview);

    Suite::equals('a fresh profile is a draft', $overview->at('data.profile.status'), 'draft');
    Suite::equals('the draft is labelled for the client', $overview->at('data.profile.status_label'), 'Draft');
    Suite::equals('a draft carries a neutral tone', $overview->at('data.profile.status_tone'), 'neutral');
    Suite::equals('a fresh profile is 0% complete', $overview->at('data.completeness'), 0);
    Suite::equals('the stored completeness agrees with the computed one', $overview->at('data.profile.completeness'), 0);
    Suite::equals('a fresh profile cannot be submitted', $overview->at('data.can_submit'), false);
    Suite::equals('a draft is editable', $overview->at('data.profile.is_editable'), true);
    Suite::equals('a fresh profile holds no documents', $overview->at('data.documents'), []);
    Suite::equals('a fresh profile has earned no badges', $overview->at('data.profile.badges'), []);
    Suite::equals('a fresh profile has never been submitted', $overview->at('data.profile.submission_count'), 0);

    Suite::check(
        'a brand profile gets a brand reference',
        is_string($overview->at('data.profile.reference'))
            && str_starts_with((string) $overview->at('data.profile.reference'), 'KYC-B-'),
        'reference was: ' . json_encode($overview->at('data.profile.reference'))
    );

    Suite::equals('the profile is scoped to the brand kind', $overview->at('data.profile.kind'), 'brand');

    Suite::check(
        'no requirement is satisfied yet',
        static function () use ($overview): bool {
            $requirements = $overview->at('data.requirements') ?? [];

            if ($requirements === []) {
                return false;
            }

            foreach ($requirements as $requirement) {
                if ($requirement['is_satisfied'] !== false || $requirement['document_ids'] !== []) {
                    return false;
                }
            }

            return true;
        },
        $overview->describe(400)
    );

    Suite::check(
        'the overview serves the same rulebook the public endpoint advertises',
        array_column($overview->at('data.requirements') ?? [], 'slot') === $brandSlots,
        'overview=' . json_encode(array_column($overview->at('data.requirements') ?? [], 'slot'))
            . ' public=' . json_encode($brandSlots)
    );

    $creatorOverview = Http::get('/me/kyc', [], 'creator');
    Suite::ok('a creator gets an overview too', $creatorOverview);
    Suite::equals('a creator profile is scoped to the creator kind', $creatorOverview->at('data.profile.kind'), 'creator');
    Suite::equals('a fresh creator profile is 0% complete', $creatorOverview->at('data.completeness'), 0);

    Suite::check(
        'a creator profile gets a creator reference',
        str_starts_with((string) $creatorOverview->at('data.profile.reference'), 'KYC-C-'),
        'reference was: ' . json_encode($creatorOverview->at('data.profile.reference'))
    );

    Suite::check(
        'the account type picks the rulebook — a creator is never shown the brand form',
        array_column($creatorOverview->at('data.requirements') ?? [], 'slot') === $creatorSlots,
        'creator overview=' . json_encode(array_column($creatorOverview->at('data.requirements') ?? [], 'slot'))
    );

    Suite::check(
        'the two actors have separate profiles',
        (int) $overview->at('data.profile.id') !== (int) $creatorOverview->at('data.profile.id'),
        'both actors resolved to profile ' . json_encode($overview->at('data.profile.id'))
    );

    // Processing consent is given once, before the first save or upload
    // (contract C3). It writes no detail columns, so "fresh" stays fresh.
    Http::put('/me/kyc/details', ['consent' => true], 'brand');
    Http::put('/me/kyc/details', ['consent' => true], 'creator');
}

// -----------------------------------------------------------------
Suite::group('KYC — partial detail autosave');

if ($kycBrand === null || $brandUserId <= 0) {
    Suite::skipGroup('KYC — partial detail autosave', 'no brand session');
} else {
    // KycController::saveDetails narrows the validated payload to the keys the
    // client actually sent, so a one-section autosave must not blank the rest.
    $firstSave = Http::put('/me/kyc/details', [
        'company_name'       => 'IT Brand Pvt Ltd',
        'legal_name'         => 'IT Brand Private Limited',
        'registered_address' => '12 Integration Road, Bengaluru 560001',
        'pan_number'         => 'abcde1234f',
    ], 'brand');

    Suite::ok('PUT /me/kyc/details saves the brand block', $firstSave);
    Suite::equals('the saved company name is echoed back', $firstSave->at('data.details.company_name'), 'IT Brand Pvt Ltd');
    Suite::equals('the saved address is echoed back', $firstSave->at('data.details.registered_address'), '12 Integration Road, Bengaluru 560001');

    Suite::equals(
        'an encrypted identifier comes back masked, not in the clear',
        $firstSave->at('data.details.pan_number'),
        'XXXXXX234F'
    );

    Suite::check(
        'the PAN plaintext never appears anywhere in the response',
        !str_contains(strtoupper($firstSave->body), 'ABCDE1234F'),
        'the response body leaked the unmasked PAN'
    );

    // The proof: send ONE field, read everything back.
    $partialSave = Http::put('/me/kyc/details', ['industry' => 'Retail'], 'brand');
    Suite::ok('a one-field autosave is accepted', $partialSave);
    Suite::equals('the field that was sent is updated', $partialSave->at('data.details.industry'), 'Retail');

    $readBack = Http::get('/me/kyc', [], 'brand');
    Suite::ok('the overview reads the saved block back', $readBack);
    Suite::equals('company_name survived a partial save', $readBack->at('data.details.company_name'), 'IT Brand Pvt Ltd');
    Suite::equals('legal_name survived a partial save', $readBack->at('data.details.legal_name'), 'IT Brand Private Limited');
    Suite::equals('registered_address survived a partial save', $readBack->at('data.details.registered_address'), '12 Integration Road, Bengaluru 560001');
    Suite::equals('the encrypted PAN survived a partial save', $readBack->at('data.details.pan_number'), 'XXXXXX234F');
    Suite::equals('the field that was sent persisted', $readBack->at('data.details.industry'), 'Retail');

    // The other direction: an explicit null must still clear a column, or the
    // client could never undo a mistake.
    $clearOne = Http::put('/me/kyc/details', ['legal_name' => null], 'brand');
    Suite::ok('an explicit null is accepted', $clearOne);
    Suite::equals('an explicit null clears just that column', $clearOne->at('data.details.legal_name'), null);
    Suite::equals('clearing one column leaves the neighbours alone', $clearOne->at('data.details.company_name'), 'IT Brand Pvt Ltd');
    Suite::equals('clearing one column leaves the address alone', $clearOne->at('data.details.registered_address'), '12 Integration Road, Bengaluru 560001');

    // Keys outside the validated schema must never reach a column.
    $massAssign = Http::put('/me/kyc/details', [
        'industry'     => 'Retail',
        'status'       => 'approved',
        'completeness' => 100,
        'profile_id'   => 999999,
    ], 'brand');
    Suite::ok('unknown keys are ignored rather than rejected', $massAssign);
    Suite::equals('a client cannot approve itself through the detail block', $massAssign->at('data.profile.status'), 'draft');
    Suite::equals('a client cannot set its own completeness', $massAssign->at('data.completeness'), 0);

    $tooShort = Http::put('/me/kyc/details', ['company_name' => 'A'], 'brand');
    Suite::status('a company name below the minimum length is rejected', $tooShort, 422);
    Suite::check(
        'the validation failure names company_name',
        static function () use ($tooShort): bool {
            $errors = $tooShort->at('data.errors') ?? $tooShort->at('errors');

            return is_array($errors) && array_key_exists('company_name', $errors);
        },
        $tooShort->describe(300)
    );

    $badUrl = Http::put('/me/kyc/details', ['website' => 'definitely not a url'], 'brand');
    Suite::status('a malformed website is rejected', $badUrl, 422);

    $badEnum = Http::put('/me/kyc/details', ['company_type' => 'pirate_ship'], 'brand');
    Suite::status('a company type outside the enum is rejected', $badEnum, 422);

    Suite::check(
        'a rejected save leaves the stored block untouched',
        static function (): bool {
            $after = Http::get('/me/kyc', [], 'brand');

            return $after->at('data.details.company_name') === 'IT Brand Pvt Ltd';
        },
        'company_name changed after a 422'
    );

    // The creator block is a different table with different required columns —
    // exercise it separately rather than assuming the brand result carries over.
    if ($kycCreator !== null && $creatorUserId > 0) {
        // kyc_creator_details.full_name is NOT NULL with no default, so the very
        // first autosave on a profile that has no detail row yet must not be a
        // partial one. This is the behaviour under test.
        $creatorPartialFirst = Http::put('/me/kyc/details', ['bio' => 'Autosaved before anything else.'], 'creator');
        Suite::check(
            'the first autosave on an empty creator block is handled, not crashed',
            $creatorPartialFirst->status !== 500,
            'a partial first save returned ' . $creatorPartialFirst->describe(200)
        );

        $creatorSave = Http::put('/me/kyc/details', [
            'full_name'     => 'IT Creator',
            'date_of_birth' => '1994-04-04',
            'pan_number'    => 'zyxpv9876e',
            'id_proof_type' => 'aadhaar',
            'address'       => '9 Integration Lane, Pune 411001',
        ], 'creator');
        Suite::ok('PUT /me/kyc/details saves the creator block', $creatorSave);
        Suite::equals('the creator name is stored', $creatorSave->at('data.details.full_name'), 'IT Creator');
        Suite::equals('the creator PAN is masked on read', $creatorSave->at('data.details.pan_number'), 'XXXXXX876E');

        $creatorPartial = Http::put('/me/kyc/details', ['city' => 'Pune'], 'creator');
        Suite::ok('a one-field creator autosave is accepted', $creatorPartial);
        Suite::equals('the creator name survived a partial save', $creatorPartial->at('data.details.full_name'), 'IT Creator');
        Suite::equals('the creator address survived a partial save', $creatorPartial->at('data.details.address'), '9 Integration Lane, Pune 411001');
        Suite::equals('the creator ID proof type survived a partial save', $creatorPartial->at('data.details.id_proof_type'), 'aadhaar');

        $creatorEnum = Http::put('/me/kyc/details', ['id_proof_type' => 'library_card'], 'creator');
        Suite::status('an unsupported ID proof type is rejected', $creatorEnum, 422);

        // A creator must never be able to write into the brand block: the kind
        // follows the account type, so brand-only keys are simply not in the
        // creator schema and must be dropped.
        $wrongBlock = Http::put('/me/kyc/details', ['company_name' => 'IT Creator Ltd'], 'creator');
        Suite::check(
            'a creator cannot write brand fields through its own detail endpoint',
            $wrongBlock->status < 500
                && (int) (Database::scalar(
                    'SELECT COUNT(*) FROM `kyc_brand_details` b
                       JOIN `kyc_profiles` p ON p.`id` = b.`profile_id`
                      WHERE p.`user_id` = :user_id',
                    ['user_id' => $creatorUserId]
                ) ?? 0) === 0,
            'a brand detail row was created for the creator: ' . $wrongBlock->describe(200)
        );
    }
}

// -----------------------------------------------------------------
Suite::group('KYC — document upload validation');

if ($kycBrand === null || $brandUserId <= 0) {
    Suite::skipGroup('KYC — document upload validation', 'no brand session');
} else {
    // DocumentVault::BLOCKED_EXTENSIONS — this must be refused whatever the
    // requirement's allowed_formats says.
    $phpUpload = $kycUpload('pan_card', $phpPath, 'brand');
    Suite::status('a .php upload is refused', $phpUpload, 422);
    Suite::equals('the refusal is a validation error, not a crash', $phpUpload->code(), 'unprocessable');

    Suite::check(
        'the refused .php file never reached the vault',
        (int) (Database::scalar(
            "SELECT COUNT(*) FROM `kyc_documents`
              WHERE `user_id` = :user_id AND `original_name` LIKE '%.php'",
            ['user_id' => $brandUserId]
        ) ?? 0) === 0,
        'a .php document row exists for the brand actor'
    );

    // The content check fails closed: bytes the sniffer cannot identify are
    // refused, not waved through on their extension.
    $junkPath = sys_get_temp_dir() . '/it-kyc-junk.png';
    file_put_contents($junkPath, random_bytes(256));
    $junkUpload = $kycUpload('pan_card', $junkPath, 'brand');
    Suite::status('unidentifiable bytes named .png are refused', $junkUpload, 422);
    @unlink($junkPath);

    $unknownSlot = $kycUpload('definitely_not_a_slot', $pngPath, 'brand');
    Suite::status('an unrecognised slot is refused', $unknownSlot, 422);

    // `selfie` is a creator-only requirement; a brand must not be able to reach it.
    $wrongKindSlot = $kycUpload('selfie', $pngPath, 'brand');
    Suite::status('a brand cannot upload into a creator-only slot', $wrongKindSlot, 422);

    // `brochure` accepts PDF only.
    $wrongFormat = $kycUpload('brochure', $pngPath, 'brand');
    Suite::status('a PNG into a PDF-only slot is refused', $wrongFormat, 422);
    Suite::check(
        'the refusal tells the user which formats are accepted',
        str_contains(strtoupper($wrongFormat->message()), 'PDF'),
        'message was: ' . $wrongFormat->message()
    );

    // `trademark` has requires_expiry = 1.
    $noExpiry = $kycUpload('trademark', $pngPath, 'brand');
    Suite::status('a slot that requires an expiry date refuses an upload without one', $noExpiry, 422);
    Suite::check(
        'the refusal says an expiry date is what is missing',
        str_contains(strtolower($noExpiry->message()), 'expiry'),
        'message was: ' . $noExpiry->message()
    );

    $noFile = Http::upload('/me/kyc/documents', [], ['slot' => 'pan_card'], 'brand');
    Suite::status('an upload with no file attached is refused', $noFile, 422);

    $noSlot = Http::upload('/me/kyc/documents', ['file' => $pngPath], [], 'brand');
    Suite::status('an upload with no slot is refused', $noSlot, 422);
}

// -----------------------------------------------------------------
Suite::group('KYC — a real document raises completeness');

if ($kycBrand === null || $brandUserId <= 0) {
    Suite::skipGroup('KYC — a real document raises completeness', 'no brand session');
} elseif ($uploadThrottled) {
    Suite::skipGroup('KYC — a real document raises completeness', 'the kyc_upload throttle (40/hour) is exhausted');
} else {
    $panUpload = $kycUpload('gst_certificate', $pngPath, 'brand');
    Suite::ok('a genuine PNG is accepted into a requirement slot', $panUpload, 201);

    $kycBrandDocId = (int) ($panUpload->at('data.documents.0.id') ?? 0);
    $kycBrandToken = (string) ($panUpload->at('data.documents.0.download_token') ?? '');

    Suite::notEmpty('the stored document has an id', $kycBrandDocId);
    Suite::equals('the document is filed against the slot it was sent to', $panUpload->at('data.documents.0.slot'), 'gst_certificate');
    Suite::equals('the sniffed MIME type is recorded', $panUpload->at('data.documents.0.mime_type'), 'image/png');
    Suite::equals('an image is flagged as previewable', $panUpload->at('data.documents.0.is_image'), true);
    Suite::equals('the stored size matches the file', $panUpload->at('data.documents.0.file_size'), strlen($pngBytes));
    Suite::equals('a first upload is version 1', $panUpload->at('data.documents.0.version'), 1);
    Suite::equals('a new document starts as pending review', $panUpload->at('data.documents.0.status'), 'pending');
    Suite::notEmpty('a download token is minted for the owner', $kycBrandToken);

    Suite::check(
        'the on-disk path is never exposed to the client',
        !str_contains($panUpload->body, 'file_path')
            && !str_contains($panUpload->body, '.enc')
            && !str_contains($panUpload->body, 'storage/kyc'),
        'the response leaked the vault path: ' . $panUpload->describe(300)
    );

    Suite::equals(
        'completeness rises from 0% to 50% on the first of two required documents',
        $panUpload->at('data.overview.completeness'),
        50
    );

    Suite::equals(
        'one document out of two is still not submittable',
        $panUpload->at('data.overview.can_submit'),
        false
    );

    $afterUpload = Http::get('/me/kyc', [], 'brand');
    Suite::ok('the overview reflects the upload', $afterUpload);
    Suite::equals('the overview agrees on 50%', $afterUpload->at('data.completeness'), 50);

    Suite::check(
        'the document appears in the overview document list',
        in_array($kycBrandDocId, array_column($afterUpload->at('data.documents') ?? [], 'id'), true),
        'documents were: ' . json_encode(array_column($afterUpload->at('data.documents') ?? [], 'id'))
    );

    Suite::check(
        'the gst_certificate requirement is now satisfied and points at the document',
        static function () use ($afterUpload, $kycBrandDocId): bool {
            foreach ($afterUpload->at('data.requirements') ?? [] as $requirement) {
                if ($requirement['slot'] === 'gst_certificate') {
                    return $requirement['is_satisfied'] === true
                        && in_array($kycBrandDocId, $requirement['document_ids'], true);
                }
            }

            return false;
        },
        $afterUpload->describe(500)
    );

    Suite::check(
        'the other required slots are still unsatisfied',
        static function () use ($afterUpload): bool {
            $unsatisfied = 0;

            foreach ($afterUpload->at('data.requirements') ?? [] as $requirement) {
                if ($requirement['is_required'] === true && $requirement['is_satisfied'] === false) {
                    $unsatisfied++;
                }
            }

            return $unsatisfied === 1;
        },
        $afterUpload->describe(500)
    );

    // A second document, needed right now to prove a token is bound to one
    // specific document. The company PAN is optional since 044, so it leaves
    // completeness where it was.
    $gstUpload = $kycUpload('pan_card', $pngPath, 'brand');
    Suite::ok('an optional slot accepts a document', $gstUpload, 201);
    $kycGstDocId = (int) ($gstUpload->at('data.documents.0.id') ?? 0);
    Suite::equals('an optional document does not move completeness', $gstUpload->at('data.overview.completeness'), 50);
}

// -----------------------------------------------------------------
Suite::group('KYC — the document vault viewer binding');

if ($kycBrandDocId <= 0 || $kycBrandToken === '') {
    Suite::skipGroup(
        'KYC — the document vault viewer binding',
        'no brand document was stored, so there is nothing to guard'
    );
} else {
    // A second owner, so "another user's token" is a real token and not a forgery.
    $selfieUpload = $kycUpload('selfie', $pngPath, 'creator');
    $kycSelfieDocId = (int) ($selfieUpload->at('data.documents.0.id') ?? 0);
    $kycSelfieToken = (string) ($selfieUpload->at('data.documents.0.download_token') ?? '');

    Suite::ok('the creator stores a document of its own', $selfieUpload, 201);
    Suite::equals('an optional selfie does not count towards the creator\'s required documents', $selfieUpload->at('data.overview.completeness'), 0);

    $path = '/me/kyc/documents/' . $kycBrandDocId . '/download';

    $noToken = Http::get($path, [], 'brand');
    Suite::status('the owner cannot download without a token', $noToken, 403);
    Suite::check(
        'the refusal says a token is required',
        str_contains(strtolower($noToken->message()), 'token'),
        'message was: ' . $noToken->message()
    );

    $forged = Http::get($path, ['token' => 'aGVsbG8td29ybGQtbm90LWEtdG9rZW4'], 'brand');
    Suite::status('a forged token is refused', $forged, 403);

    $garbage = Http::get($path, ['token' => '!!!not base64 at all!!!'], 'brand');
    Suite::status('an unparseable token is refused', $garbage, 403);

    $tampered = Http::get($path, ['token' => substr($kycBrandToken, 0, -4) . 'zzzz'], 'brand');
    Suite::status('a token whose signature has been altered is refused', $tampered, 403);

    // Claims re-encoded by hand: change the viewer id and re-sign with a guessed
    // secret. Without the app key the signature cannot be reproduced.
    $handRolled = rtrim(strtr(base64_encode(
        $kycBrandDocId . '|user|' . $brandUserId . '|' . (time() + 600) . '|' . hash('sha256', 'guessed-secret')
    ), '+/', '-_'), '=');
    $selfSigned = Http::get($path, ['token' => $handRolled], 'brand');
    Suite::status('a self-signed token is refused', $selfSigned, 403);

    $anonymous = Http::get($path, ['token' => $kycBrandToken]);
    Suite::status('a valid token is worthless without a session', $anonymous, 401);

    $wrongBearer = Http::get($path, ['token' => $kycBrandToken], 'creator');
    Suite::status('another user cannot replay the owner\'s token', $wrongBearer, 403);
    Suite::check(
        'the refusal names the account mismatch',
        str_contains(strtolower($wrongBearer->message()), 'account'),
        'message was: ' . $wrongBearer->message()
    );

    if ($kycSelfieDocId > 0 && $kycSelfieToken !== '') {
        $crossOwner = Http::get(
            '/me/kyc/documents/' . $kycSelfieDocId . '/download',
            ['token' => $kycSelfieToken],
            'brand'
        );
        Suite::status('a brand cannot read a creator\'s document with the creator\'s own token', $crossOwner, 403);

        $crossOwnerNoToken = Http::get('/me/kyc/documents/' . $kycSelfieDocId . '/download', [], 'brand');
        Suite::status('a brand cannot reach a creator\'s document id at all', $crossOwnerNoToken, [403, 404]);
    } else {
        Suite::check('the creator document needed for the cross-owner check was stored', false, $selfieUpload->describe(300));
    }

    if ($kycGstDocId > 0) {
        $wrongDocument = Http::get(
            '/me/kyc/documents/' . $kycGstDocId . '/download',
            ['token' => $kycBrandToken],
            'brand'
        );
        Suite::status('a token minted for one document does not open another', $wrongDocument, 403);
    }

    $download = Http::get($path, ['token' => $kycBrandToken], 'brand');
    Suite::status('the owner downloads its own document with its own token', $download, 200);
    Suite::check(
        'the decrypted bytes are byte-for-byte the file that was uploaded',
        $download->body === $pngBytes,
        'expected ' . strlen($pngBytes) . ' bytes, got ' . strlen($download->body)
    );
    Suite::check(
        'the document is served as an attachment, not inline',
        str_contains((string) $download->header('content-disposition'), 'attachment'),
        'content-disposition was: ' . json_encode($download->header('content-disposition'))
    );
    Suite::check(
        'the stored MIME type is served back',
        str_contains((string) $download->header('content-type'), 'image/png'),
        'content-type was: ' . json_encode($download->header('content-type'))
    );

    Suite::check(
        'the download is recorded in the audit trail',
        static function () use ($kycBrandDocId): bool {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM `kyc_events` e
                   JOIN `kyc_documents` d ON d.`profile_id` = e.`profile_id`
                  WHERE d.`id` = :doc AND e.`action` = 'document_downloaded' AND e.`actor_type` = 'user'",
                ['doc' => $kycBrandDocId]
            ) > 0;
        },
        'no document_downloaded event was written'
    );

    // Views and downloads are access logs for the review team, not part of
    // the status history the applicant's own timeline shows (contract C5).
    Suite::check(
        'the access log stays off the applicant\'s own timeline',
        static function (): bool {
            $timeline = Http::get('/me/kyc/timeline', [], 'brand');

            return $timeline->status === 200
                && !in_array('document_downloaded', array_column($timeline->at('data.timeline') ?? [], 'action'), true);
        },
        'document_downloaded was served to the applicant'
    );
}

// -----------------------------------------------------------------
Suite::group('KYC — removing a document');

if ($kycBrandDocId <= 0) {
    Suite::skipGroup('KYC — removing a document', 'no brand document was stored');
} else {
    $crossDelete = Http::delete('/me/kyc/documents/' . $kycBrandDocId, null, 'creator');
    Suite::status('another user cannot delete the owner\'s document', $crossDelete, 404);

    Suite::check(
        'the refused cross-account delete left the document alive',
        (int) (Database::scalar(
            'SELECT COUNT(*) FROM `kyc_documents` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $kycBrandDocId]
        ) ?? 0) === 1,
        'the document was removed by a non-owner'
    );

    $delete = Http::delete('/me/kyc/documents/' . $kycBrandDocId, null, 'brand');
    Suite::ok('the owner removes its own document', $delete);
    Suite::equals('completeness falls back to 0% when the only filled required slot empties', $delete->at('data.completeness'), 0);

    Suite::check(
        'the document is gone from the overview',
        !in_array($kycBrandDocId, array_column($delete->at('data.documents') ?? [], 'id'), true),
        'documents were: ' . json_encode(array_column($delete->at('data.documents') ?? [], 'id'))
    );

    Suite::check(
        'the gst_certificate requirement is unsatisfied again',
        static function () use ($delete): bool {
            foreach ($delete->at('data.requirements') ?? [] as $requirement) {
                if ($requirement['slot'] === 'gst_certificate') {
                    return $requirement['is_satisfied'] === false && $requirement['document_ids'] === [];
                }
            }

            return false;
        },
        $delete->describe(500)
    );

    Suite::check(
        'the row is soft-deleted so the audit trail survives',
        (int) (Database::scalar(
            'SELECT COUNT(*) FROM `kyc_documents` WHERE `id` = :id AND `deleted_at` IS NOT NULL',
            ['id' => $kycBrandDocId]
        ) ?? 0) === 1,
        'the document row was hard-deleted or left live'
    );

    // The row is the record that a document existed — nothing more. Removal
    // used to mark it purged and keep the path, stored file name and upload
    // IP, which no later purge ever blanked.
    $removedRow = Database::selectOne(
        'SELECT `purged_at`, `file_path`, `original_name`, `checksum`, `uploaded_ip` FROM `kyc_documents` WHERE `id` = :id',
        ['id' => $kycBrandDocId]
    ) ?? [];
    Suite::check(
        'the removed row keeps no path, file name, checksum or upload IP',
        ($removedRow['purged_at'] ?? null) !== null
            && array_key_exists('file_path', $removedRow) && $removedRow['file_path'] === null
            && $removedRow['original_name'] === null && $removedRow['checksum'] === null && $removedRow['uploaded_ip'] === null,
        json_encode(array_map(static fn ($v) => $v === null ? null : 'set', $removedRow))
    );

    if ($kycBrandToken !== '') {
        $staleDownload = Http::get(
            '/me/kyc/documents/' . $kycBrandDocId . '/download',
            ['token' => $kycBrandToken],
            'brand'
        );
        Suite::status('a still-valid token cannot resurrect a deleted document', $staleDownload, 404);
    }

    $deleteAgain = Http::delete('/me/kyc/documents/' . $kycBrandDocId, null, 'brand');
    Suite::status('deleting an already-deleted document is a 404', $deleteAgain, 404);

    $deleteMissing = Http::delete('/me/kyc/documents/99999999', null, 'brand');
    Suite::status('deleting a document that never existed is a 404', $deleteMissing, 404);
}

// -----------------------------------------------------------------
Suite::group('KYC — the submit gate');

if ($kycBrand === null || $brandUserId <= 0) {
    Suite::skipGroup('KYC — the submit gate', 'no brand session');
} elseif ($uploadThrottled) {
    Suite::skipGroup('KYC — the submit gate', 'the kyc_upload throttle (40/hour) is exhausted');
} else {
    $earlySubmit = Http::post('/me/kyc/submit', [], 'brand');
    Suite::status('submitting with mandatory documents missing is refused', $earlySubmit, 422);

    Suite::check(
        'the refusal lists exactly what is still needed',
        static function () use ($earlySubmit): bool {
            $message = $earlySubmit->message();

            return str_contains($message, 'Still needed')
                && str_contains($message, 'GST certificate')
                // No company type yet, so the CIN certificate is still asked for.
                && str_contains($message, 'Certificate of Incorporation')
                // The company PAN is uploaded AND optional, so it must NOT be listed.
                && !str_contains($message, 'Company PAN card');
        },
        'message was: ' . $earlySubmit->message()
    );

    Suite::check(
        'a refused submit did not move the profile out of draft',
        static function (): bool {
            $after = Http::get('/me/kyc', [], 'brand');

            return $after->at('data.profile.status') === 'draft'
                && $after->at('data.profile.submission_count') === 0;
        },
        'the profile advanced despite a refused submit'
    );

    // A partnership has no CIN, so its certificate stops being required; the
    // GSTIN carries the PAN saved earlier (ABCDE1234F) and a valid checksum.
    $typed = Http::put('/me/kyc/details', [
        'company_type' => 'partnership',
        'gst_number'   => '27ABCDE1234F1Z0',
    ], 'brand');
    Suite::ok('the company type and GSTIN are saved', $typed);
    Suite::equals('a partnership is not asked for a CIN', $typed->at('data.details.cin_required'), false);

    // Fill the remaining required slot and submit for real.
    $filled = true;
    foreach (['gst_certificate'] as $slot) {
        $res = $kycUpload($slot, $pngPath, 'brand');
        if (!Suite::ok('the ' . $slot . ' slot accepts its document', $res, 201)) {
            $filled = false;
        }
    }

    if (!$filled) {
        Suite::skipGroup('KYC — the submit gate (complete run)', 'could not fill every required slot');
    } else {
        $ready = Http::get('/me/kyc', [], 'brand');
        Suite::equals('every required slot filled means 100% complete', $ready->at('data.completeness'), 100);
        Suite::equals('a complete draft can be submitted', $ready->at('data.can_submit'), true);

        $noConsent = Http::post('/me/kyc/submit', [], 'brand');
        Suite::status('submitting without consent is refused', $noConsent, 422);

        $submit = Http::post('/me/kyc/submit', ['consent' => true], 'brand');
        Suite::ok('POST /me/kyc/submit hands the profile to the review queue', $submit);
        Suite::equals('the profile moves to submitted', $submit->at('data.profile.status'), 'submitted');
        Suite::equals('the submitted state is labelled', $submit->at('data.profile.status_label'), 'Pending');
        Suite::equals('a submitted profile is no longer editable', $submit->at('data.profile.is_editable'), false);
        Suite::equals('a submitted profile cannot be submitted again', $submit->at('data.can_submit'), false);
        Suite::equals('the submission is counted', $submit->at('data.profile.submission_count'), 1);
        Suite::notEmpty('the submission is timestamped', $submit->at('data.profile.submitted_at'));

        Suite::check(
            'submitting is recorded as a state change in the audit trail',
            static function (): bool {
                $timeline = Http::get('/me/kyc/timeline', [], 'brand');

                foreach ($timeline->at('data.timeline') ?? [] as $event) {
                    if ($event['action'] === 'submitted'
                        && $event['from_status'] === 'draft'
                        && $event['to_status'] === 'submitted') {
                        return true;
                    }
                }

                return false;
            },
            'no draft -> submitted event was written'
        );

        // The lock: once it is with a reviewer, nothing about it may change.
        $lockedEdit = Http::put('/me/kyc/details', ['industry' => 'Media'], 'brand');
        Suite::status('a submitted profile refuses detail edits', $lockedEdit, 409);
        Suite::equals('the refusal is distinguishable from a permission error', $lockedEdit->code(), 'kyc_locked');

        $lockedUpload = $kycUpload('supporting', $pngPath, 'brand');
        Suite::status('a submitted profile refuses new documents', $lockedUpload, 409);
        Suite::equals('the upload refusal uses the same lock code', $lockedUpload->code(), 'kyc_locked');

        $liveDocId = (int) (Database::scalar(
            'SELECT `id` FROM `kyc_documents`
              WHERE `user_id` = :user_id AND `is_current` = 1 AND `deleted_at` IS NULL
              ORDER BY `id` DESC LIMIT 1',
            ['user_id' => $brandUserId]
        ) ?? 0);

        if ($liveDocId > 0) {
            $lockedDelete = Http::delete('/me/kyc/documents/' . $liveDocId, null, 'brand');
            Suite::status('a submitted profile refuses document removal', $lockedDelete, 409);
            Suite::equals('the delete refusal uses the same lock code', $lockedDelete->code(), 'kyc_locked');
        }

        // Every other mutation on a locked profile is refused; re-submitting
        // must be too, or a user can re-queue a profile that is already with a
        // reviewer and reset the reviewer's context.
        $resubmit = Http::post('/me/kyc/submit', ['consent' => true], 'brand');
        Suite::status('a submitted profile refuses a second submission', $resubmit, [409, 422]);

        Suite::check(
            'a refused second submission does not inflate the submission count',
            static function (): bool {
                $after = Http::get('/me/kyc', [], 'brand');

                return $after->at('data.profile.submission_count') === 1;
            },
            'submission_count moved past 1 without a state change'
        );
    }
}

// -----------------------------------------------------------------
Suite::group('KYC — the audit timeline');

if ($kycBrand === null || $brandUserId <= 0) {
    Suite::skipGroup('KYC — the audit timeline', 'no brand session');
} else {
    $timeline = Http::get('/me/kyc/timeline', [], 'brand');
    Suite::ok('GET /me/kyc/timeline returns the audit trail', $timeline);

    $events = $timeline->at('data.timeline') ?? [];
    Suite::notEmpty('the timeline is not empty after a run of activity', $events);

    $actions = array_column($events, 'action');

    foreach (['details_updated', 'document_uploaded', 'document_removed'] as $action) {
        Suite::check(
            'the timeline records ' . $action,
            in_array($action, $actions, true),
            'actions were: ' . json_encode($actions)
        );
    }

    Suite::check(
        'every event carries a human-readable label',
        static function () use ($events): bool {
            foreach ($events as $event) {
                if (!isset($event['label']) || trim((string) $event['label']) === '') {
                    return false;
                }
            }

            return $events !== [];
        },
        json_encode(array_slice($events, 0, 3))
    );

    Suite::check(
        'the timeline is newest-first',
        static function () use ($events): bool {
            $previous = null;

            foreach ($events as $event) {
                $at = strtotime((string) $event['created_at']);

                if ($previous !== null && $at > $previous) {
                    return false;
                }

                $previous = $at;
            }

            return true;
        },
        json_encode(array_column($events, 'created_at'))
    );

    Suite::check(
        'user-driven events are attributed to the user',
        static function () use ($events): bool {
            foreach ($events as $event) {
                if ($event['action'] === 'document_uploaded') {
                    return $event['actor_type'] === 'user';
                }
            }

            return false;
        },
        json_encode(array_slice($events, 0, 5))
    );

    if ($kycCreator !== null) {
        $creatorTimeline = Http::get('/me/kyc/timeline', [], 'creator');
        Suite::ok('a creator gets its own timeline', $creatorTimeline);

        Suite::check(
            'one actor\'s timeline never contains another actor\'s events',
            static function () use ($creatorTimeline): bool {
                foreach ($creatorTimeline->at('data.timeline') ?? [] as $event) {
                    $note = strtolower((string) ($event['note'] ?? ''));

                    // Brand-only document labels must never appear here.
                    if (str_contains($note, 'gst certificate')
                        || str_contains($note, 'company pan')
                        || str_contains($note, 'business registration')) {
                        return false;
                    }
                }

                return true;
            },
            json_encode($creatorTimeline->at('data.timeline'))
        );

        Suite::check(
            'the creator timeline records the creator\'s own upload',
            in_array(
                'document_uploaded',
                array_column($creatorTimeline->at('data.timeline') ?? [], 'action'),
                true
            ),
            json_encode($creatorTimeline->at('data.timeline'))
        );
    }
}

// -----------------------------------------------------------------
Suite::group('KYC — cross-account isolation');

if ($kycBrand === null || $kycCreator === null) {
    Suite::skipGroup('KYC — cross-account isolation', 'both actors are needed');
} else {
    $brandView   = Http::get('/me/kyc', [], 'brand');
    $creatorView = Http::get('/me/kyc', [], 'creator');

    Suite::check(
        'each actor sees only its own profile',
        (int) $brandView->at('data.profile.id') !== (int) $creatorView->at('data.profile.id')
            && $brandView->at('data.profile.reference') !== $creatorView->at('data.profile.reference'),
        'brand=' . json_encode($brandView->at('data.profile.reference'))
            . ' creator=' . json_encode($creatorView->at('data.profile.reference'))
    );

    Suite::check(
        'a creator never sees a brand\'s documents',
        static function () use ($creatorView, $brandUserId): bool {
            $brandDocIds = array_map('intval', Database::column(
                'SELECT `id` FROM `kyc_documents` WHERE `user_id` = :user_id',
                ['user_id' => $brandUserId]
            ));

            foreach ($creatorView->at('data.documents') ?? [] as $document) {
                if (in_array((int) $document['id'], $brandDocIds, true)) {
                    return false;
                }
            }

            return true;
        },
        json_encode($creatorView->at('data.documents'))
    );

    Suite::check(
        'a creator never sees a brand\'s detail block',
        !str_contains((string) json_encode($creatorView->at('data.details')), 'IT Brand Pvt Ltd'),
        json_encode($creatorView->at('data.details'))
    );
}

// -----------------------------------------------------------------
// Cleanup — remove every profile, detail row, event and encrypted blob this
// suite created. Scoped to the throwaway actors; no seeded row is touched.
// -----------------------------------------------------------------

if ($kycCreator !== null && $kycSelfieDocId > 0) {
    $creatorDelete = Http::delete('/me/kyc/documents/' . $kycSelfieDocId, null, 'creator');
    Suite::status('the creator can remove its own document', $creatorDelete, 200);
}

$kycReset($brandUserId);
$kycReset($creatorUserId);

@unlink($pngPath);
@unlink($phpPath);

Suite::check(
    'the suite left no KYC residue behind',
    (int) (Database::scalar(
        'SELECT COUNT(*) FROM `kyc_profiles` WHERE `user_id` IN (:brand, :creator)',
        ['brand' => $brandUserId, 'creator' => $creatorUserId]
    ) ?? 0) === 0,
    'kyc_profiles rows survived cleanup for the throwaway actors'
);

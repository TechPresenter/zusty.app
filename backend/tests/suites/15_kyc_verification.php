<?php

declare(strict_types=1);

/**
 * KYC & verification — identity rules, the blue tick, retention.
 *
 * 10_kyc covers the vault and the basic workflow. This suite covers what
 * migration 044 added:
 *
 *   * identifier validation — PAN (personal for creators), Aadhaar (Verhoeff,
 *     never stored in full), GSTIN (mod-36 checksum, state code, embedded
 *     PAN), CIN — with field-keyed errors;
 *   * the masked round-trip guard (a masked value sent back is ignored);
 *   * uniqueness across accounts, worded so it never says whose account;
 *   * consent on submit; the legacy endpoints retired;
 *   * the owner-only inline preview route and document replacement;
 *   * per-document review, re-upload requests, the approval completeness
 *     guard — driven at service level with admin id 1, the same calls the
 *     admin controllers make (the panel itself is session + CSRF protected);
 *   * the blue tick: approval no longer grants it; grant / revoke; reject and
 *     suspend take it away; auto-approval (approve() with no admin) cannot
 *     grant it;
 *   * purgeForUser() idempotency and exportSummary() holding no clear numbers;
 *   * the retention sweep and expiry reminders, scoped to the test user;
 *   * kyc_events being append-only, and log redaction of aadhaar / cin.
 *
 * Every row it writes belongs to `it-kycv-*` throwaway accounts and is removed
 * at the end. No seeded demo row is read for writing, let alone changed.
 */

use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Helpers\KycIdentifiers;
use Zuste\Services\ApiLogService;
use Zuste\Services\CreatorBadgeService;
use Zuste\Services\DocumentVault;
use Zuste\Services\KycService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// -----------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------

$kvCreator = Actors::create('kycv-creator', 'creator');
$kvTwin    = Actors::create('kycv-twin', 'creator');
$kvBrand   = Actors::create('kycv-brand', 'brand');
$kvBrand2  = Actors::create('kycv-brand2', 'brand');

$kvId = static function (string $actor): int {
    $id = Actors::id($actor);

    return $id > 0 ? $id : (int) (Database::scalar(
        'SELECT `id` FROM `users` WHERE `email` = :email',
        ['email' => Actors::email($actor)]
    ) ?? 0);
};

$kvCreatorId = $kvId('kycv-creator');
$kvTwinId    = $kvId('kycv-twin');
$kvBrandId   = $kvId('kycv-brand');
$kvBrand2Id  = $kvId('kycv-brand2');

/** Back to "never started verification" — strictly the it-kycv-* accounts. */
$kvReset = static function (int $userId): void {
    if ($userId <= 0) {
        return;
    }

    foreach (Database::select('SELECT `file_path` FROM `kyc_documents` WHERE `user_id` = :u', ['u' => $userId]) as $row) {
        DocumentVault::purge((string) ($row['file_path'] ?? ''));
    }

    // kyc_events / details / documents cascade from kyc_profiles (foreign-key
    // cascades do not fire the append-only triggers).
    Database::execute('DELETE FROM `kyc_documents` WHERE `user_id` = :u', ['u' => $userId]);
    Database::execute('DELETE FROM `kyc_profiles` WHERE `user_id` = :u', ['u' => $userId]);
    Database::execute(
        "UPDATE `users` SET `kyc_profile_id` = NULL, `kyc_status` = 'none', `has_blue_tick` = 0, `blue_tick_at` = NULL,
                `is_identity_verified` = 0, `is_business_verified` = 0, `kyc_expires_on` = NULL
          WHERE `id` = :u",
        ['u' => $userId]
    );

    $creatorId = Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :u', ['u' => $userId]);

    if ($creatorId !== null) {
        // The tick, and the KYC-derived badges an approval now awards at once.
        $badges = "('creator_verified', 'identity_verified', 'kyc_verified', 'business_verified')";
        Database::execute("DELETE FROM `creator_badges` WHERE `creator_id` = :c AND `badge` IN {$badges}", ['c' => (int) $creatorId]);
        Database::execute("DELETE FROM `creator_badge_events` WHERE `creator_id` = :c AND `badge` IN {$badges}", ['c' => (int) $creatorId]);
        Database::execute('UPDATE `creators` SET `is_verified` = 0, `verified_at` = NULL WHERE `id` = :c', ['c' => (int) $creatorId]);
    }
};

$kvErrors = static function (\Zuste\Tests\Res $res): array {
    $errors = $res->at('errors') ?? $res->at('data.errors');

    return is_array($errors) ? $errors : [];
};

$kvProfileId = static function (int $userId, string $kind): int {
    return (int) (Database::scalar(
        'SELECT `id` FROM `kyc_profiles` WHERE `user_id` = :u AND `kind` = :k',
        ['u' => $userId, 'k' => $kind]
    ) ?? 0);
};

$kvNotified = static function (int $userId, string $type): bool {
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :u AND `type` = :t AND `created_at` >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)',
        ['u' => $userId, 't' => $type]
    ) > 0;
};

$kvTick = static function (int $userId): array {
    return [
        'users'    => (int) Database::scalar('SELECT `has_blue_tick` FROM `users` WHERE `id` = :u', ['u' => $userId]),
        'creators' => (int) (Database::scalar('SELECT `is_verified` FROM `creators` WHERE `user_id` = :u', ['u' => $userId]) ?? 0),
    ];
};

// Identifiers. Every Aadhaar here carries a valid Verhoeff check digit and
// every GSTIN a valid mod-36 check character, computed rather than copied.
$aadhaarA    = '98765432101' . KycIdentifiers::verhoeffDigit('98765432101');
$aadhaarB    = '87654321098' . KycIdentifiers::verhoeffDigit('87654321098');
$aadhaarBad  = substr($aadhaarA, 0, 11) . (((int) substr($aadhaarA, -1) + 1) % 10);
$creatorPan  = 'KVCPA1234Q';           // 4th character P — an individual
$brandPan    = 'KVBCA5678R';           // 4th character C — a company
$brandGst    = '29' . $brandPan . '1Z';
$brandGst   .= KycIdentifiers::gstinCheckChar($brandGst);
$otherGst    = '27KVBCZ9999Z1Z';
$otherGst   .= KycIdentifiers::gstinCheckChar($otherGst);
$brandCin    = 'U74999KA2021PTC145678';

$pngBytes = (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    true
);
$pdfBytes = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n"
    . "trailer<</Root 1 0 R>>\n%%EOF\n";

$kvPng = sys_get_temp_dir() . '/it-kycv-proof.png';
$kvPdf = sys_get_temp_dir() . '/it-kycv-proof.pdf';
file_put_contents($kvPng, $pngBytes);
file_put_contents($kvPdf, $pdfBytes);

// Every detail save and upload carries `consent: true`: processing consent is
// captured before the first upload or save (contract C3), and this suite is
// about what happens after it. Ignored by a server that does not ask for it.
$kvUpload = static function (string $actor, string $slot, string $file, array $extra = []): \Zuste\Tests\Res {
    return Http::upload(
        '/me/kyc/documents',
        ['file' => $file],
        ($slot === '' ? [] : ['slot' => $slot]) + $extra + ['consent' => '1'],
        $actor
    );
};

$kvPut = static function (array $body, string $actor, array $headers = []): \Zuste\Tests\Res {
    return Http::put('/me/kyc/details', $body + ['consent' => true], $actor, $headers);
};

$kvEvents = static function (int $profileId, string $action): array {
    return Database::select(
        'SELECT * FROM `kyc_events` WHERE `profile_id` = :p AND `action` = :a ORDER BY `id`',
        ['p' => $profileId, 'a' => $action]
    );
};

$kvRefusal = static function (callable $call): ?HttpException {
    try {
        $call();
    } catch (HttpException $e) {
        return $e;
    }

    return null;
};

$kvDocFor = static function (\Zuste\Tests\Res $overview, string $slot): ?array {
    foreach ($overview->at('data.documents') ?? [] as $document) {
        if (($document['slot'] ?? null) === $slot) {
            return $document;
        }
    }

    return null;
};

$kvReqFor = static function (\Zuste\Tests\Res $overview, string $slot): ?array {
    foreach ($overview->at('data.requirements') ?? [] as $requirement) {
        if (($requirement['slot'] ?? null) === $slot) {
            return $requirement;
        }
    }

    return null;
};

$kvReady = $kvCreator !== null && $kvTwin !== null && $kvBrand !== null && $kvBrand2 !== null
    && $kvCreatorId > 0 && $kvTwinId > 0 && $kvBrandId > 0 && $kvBrand2Id > 0;

if ($kvReady) {
    foreach ([$kvCreatorId, $kvTwinId, $kvBrandId, $kvBrand2Id] as $uid) {
        $kvReset($uid);
    }
}

// -----------------------------------------------------------------
Suite::group('KYC verification — identifier rules (pure)');

Suite::check('a computed Aadhaar passes Verhoeff', KycIdentifiers::isValidAadhaar($aadhaarA), $aadhaarA);
Suite::check('one changed digit fails Verhoeff', !KycIdentifiers::isValidAadhaar($aadhaarBad), $aadhaarBad);
Suite::check('an Aadhaar may not start with 0 or 1', !KycIdentifiers::isAadhaarShaped('123456789012'));
Suite::check('Aadhaar input is normalised (spaces, hyphens)', KycIdentifiers::isValidAadhaar(
    substr($aadhaarA, 0, 4) . ' ' . substr($aadhaarA, 4, 4) . '-' . substr($aadhaarA, 8)
));
Suite::check('a real-world GSTIN validates', KycIdentifiers::isValidGstin('27AAPFU0939F1ZV'));
Suite::check('a GSTIN with a wrong check character fails', !KycIdentifiers::isValidGstin('27AAPFU0939F1ZW'));
Suite::check('a GSTIN with state code 99 fails', !KycIdentifiers::gstinStateValid('99AAPFU0939F1ZV'));
Suite::check('a CIN validates', KycIdentifiers::isValidCin($brandCin));
Suite::check('a malformed CIN fails', !KycIdentifiers::isValidCin('U74999KA2021XYZ145678'));
Suite::check('a personal PAN has P as its 4th character', KycIdentifiers::isPersonalPan($creatorPan) && !KycIdentifiers::isPersonalPan($brandPan));
Suite::check('masked renderings are recognised', KycIdentifiers::isMasked('XXXXXX234F')
    && KycIdentifiers::isMasked('XXXX XXXX 1234') && KycIdentifiers::isMasked('••••1234')
    && !KycIdentifiers::isMasked($creatorPan));
Suite::check(
    'company types map onto the CIN rule',
    KycIdentifiers::companyTypeRequiresCin('private_limited') && KycIdentifiers::companyTypeRequiresCin('opc')
        && !KycIdentifiers::companyTypeRequiresCin('partnership') && !KycIdentifiers::companyTypeRequiresCin('llp')
        && !KycIdentifiers::companyTypeRequiresCin('sole_proprietorship')
);

$redacted = json_decode(ApiLogService::redact((string) json_encode([
    'aadhaar_number' => $aadhaarA, 'cin_number' => $brandCin, 'cin' => $brandCin, 'pricing' => 'keep-me',
])), true);
Suite::check(
    'request logs redact aadhaar and cin but not look-alike keys',
    is_array($redacted)
        && $redacted['aadhaar_number'] === ApiLogService::REDACTED
        && $redacted['cin_number'] === ApiLogService::REDACTED
        && $redacted['cin'] === ApiLogService::REDACTED
        && $redacted['pricing'] === 'keep-me',
    json_encode($redacted)
);

if (!$kvReady) {
    Suite::skipGroup('KYC verification — API', 'could not obtain the four throwaway sessions: ' . Actors::lastError());
} else {
    // -----------------------------------------------------------------
    Suite::group('KYC verification — creator details');

    $first = Http::get('/me/kyc', [], 'kycv-creator');
    Suite::ok('GET /me/kyc opens a creator draft', $first);
    Suite::equals('consent wording version is advertised', $first->at('data.profile.consent_version_required'), KycService::consentVersion());
    Suite::notEmpty('consent text is advertised', $first->at('data.profile.consent_text'));
    Suite::equals('a fresh profile has no blue tick', $first->at('data.profile.badge_status'), 'none');
    Suite::equals('resubmit_slots is an empty list', $first->at('data.profile.resubmit_slots'), []);
    Suite::equals('editable mirrors is_editable', $first->at('data.profile.editable'), true);
    Suite::equals('can_submit is on the profile too', $first->at('data.profile.can_submit'), false);

    // Processing consent is given once, before the first save or upload
    // (contract C3); the rest of this suite is about what comes after it.
    foreach (['kycv-creator', 'kycv-twin', 'kycv-brand', 'kycv-brand2'] as $kvActor) {
        $kvPut([], $kvActor);
    }

    Suite::check(
        'the creator checklist requires Aadhaar front/back and PAN, each missing',
        static function () use ($first, $kvReqFor): bool {
            foreach (['aadhaar_front', 'aadhaar_back', 'pan_card'] as $slot) {
                $row = $kvReqFor($first, $slot);
                if ($row === null || $row['is_required'] !== true || $row['review_status'] !== 'missing'
                    || $row['resubmit_requested'] !== false || $row['requires_expiry'] !== false) {
                    return false;
                }
            }

            return true;
        },
        $first->describe(600)
    );

    $shape = Http::put('/me/kyc/details', ['aadhaar_number' => '12345'], 'kycv-creator');
    Suite::status('a short Aadhaar is refused', $shape, 422);
    Suite::check('the error is keyed aadhaar_number', isset($kvErrors($shape)['aadhaar_number']), $shape->describe(300));

    $checksum = Http::put('/me/kyc/details', ['aadhaar_number' => $aadhaarBad], 'kycv-creator');
    Suite::status('an Aadhaar failing Verhoeff is refused', $checksum, 422);
    Suite::check(
        'the Verhoeff refusal says the number is not valid',
        str_contains((string) ($kvErrors($checksum)['aadhaar_number'][0] ?? ''), 'not valid'),
        $checksum->describe(300)
    );

    $notPersonal = Http::put('/me/kyc/details', ['pan_number' => $brandPan], 'kycv-creator');
    Suite::status('a company PAN is refused for a creator', $notPersonal, 422);
    Suite::equals(
        'the refusal asks for a personal PAN',
        $kvErrors($notPersonal)['pan_number'][0] ?? null,
        'Enter your personal PAN (the 4th character is P).'
    );

    $both = Http::put('/me/kyc/details', ['pan_number' => 'ABC123', 'aadhaar_number' => '1111'], 'kycv-creator');
    Suite::check(
        'every bad field is reported at once',
        $both->status === 422 && isset($kvErrors($both)['pan_number'], $kvErrors($both)['aadhaar_number']),
        $both->describe(300)
    );

    $spaced = substr($aadhaarA, 0, 4) . ' ' . substr($aadhaarA, 4, 4) . ' ' . substr($aadhaarA, 8);
    $save = $kvPut([
        'full_name'      => 'IT KYCV Creator',
        'date_of_birth'  => '1995-05-05',
        'pan_number'     => strtolower($creatorPan),
        'aadhaar_number' => $spaced,
    ], 'kycv-creator', ['User-Agent' => 'it-kycv-app/1.0']);
    Suite::ok('valid creator details are saved', $save);

    // A PAN / Aadhaar change is the event an account takeover would leave;
    // it must say where it came from.
    $detailsEvent = Database::selectOne(
        "SELECT e.`ip_address`, e.`user_agent` FROM `kyc_events` e
           JOIN `kyc_profiles` p ON p.`id` = e.`profile_id`
          WHERE p.`user_id` = :u AND e.`action` = 'details_updated'
          ORDER BY e.`id` DESC LIMIT 1",
        ['u' => $kvCreatorId]
    ) ?? [];
    Suite::check('the details_updated event records the request IP', !empty($detailsEvent['ip_address']), json_encode(array_map('bin2hex', array_filter($detailsEvent, 'is_string'))));
    Suite::equals('and its user agent', $detailsEvent['user_agent'] ?? null, 'it-kycv-app/1.0');
    Suite::equals('the Aadhaar comes back masked to its last four', $save->at('data.details.aadhaar_masked'), 'XXXX XXXX ' . substr($aadhaarA, -4));
    Suite::equals('the PAN comes back masked', $save->at('data.details.pan_masked'), 'XXXXXX' . substr($creatorPan, -4));
    Suite::check(
        'neither full number appears anywhere in the response',
        !str_contains($save->body, $aadhaarA) && !str_contains(strtoupper($save->body), $creatorPan),
        'the response leaked an identifier'
    );
    Suite::check(
        'no hash column is exposed',
        !str_contains($save->body, 'aadhaar_hash') && !str_contains($save->body, 'pan_hash'),
        'a blind index leaked into the payload'
    );

    $row = Database::selectOne(
        'SELECT d.* FROM `kyc_creator_details` d JOIN `kyc_profiles` p ON p.`id` = d.`profile_id`
          WHERE p.`user_id` = :u',
        ['u' => $kvCreatorId]
    ) ?? [];
    Suite::equals('only the last four Aadhaar digits are stored', $row['aadhaar_last4'] ?? null, substr($aadhaarA, -4));
    Suite::check('the Aadhaar hash is a keyed 64-hex digest', preg_match('/^[0-9a-f]{64}$/', (string) ($row['aadhaar_hash'] ?? '')) === 1);
    Suite::check(
        'the full Aadhaar exists in no column, encrypted or not',
        static function () use ($row, $aadhaarA): bool {
            foreach ($row as $value) {
                $plain = is_string($value) ? (Crypto::decrypt($value) ?? $value) : (string) $value;

                if (str_contains((string) $value, $aadhaarA) || str_contains((string) $plain, $aadhaarA)) {
                    return false;
                }
            }

            return true;
        }
    );

    // --- the masked round trip ------------------------------------------
    $hashBefore = $row['aadhaar_hash'] ?? null;
    $roundTrip  = Http::put('/me/kyc/details', [
        'pan_number'     => 'XXXXXX' . substr($creatorPan, -4),
        'aadhaar_number' => 'XXXX XXXX ' . substr($aadhaarA, -4),
        'city'           => 'Bengaluru',
    ], 'kycv-creator');
    Suite::ok('sending the masked values back is accepted', $roundTrip);

    $after = Database::selectOne(
        'SELECT d.`pan_number`, d.`aadhaar_hash`, d.`city` FROM `kyc_creator_details` d
           JOIN `kyc_profiles` p ON p.`id` = d.`profile_id` WHERE p.`user_id` = :u',
        ['u' => $kvCreatorId]
    ) ?? [];
    Suite::equals('the masked PAN did not overwrite the real one', Crypto::decryptNullable($after['pan_number'] ?? null), $creatorPan);
    Suite::equals('the masked Aadhaar did not change the stored hash', $after['aadhaar_hash'] ?? null, $hashBefore);
    Suite::equals('the field that really changed was saved', $after['city'] ?? null, 'Bengaluru');

    $bullets = Http::put('/me/kyc/details', ['pan_number' => '••••••' . substr($creatorPan, -4)], 'kycv-creator');
    Suite::check(
        'a bullet-masked PAN is ignored too',
        $bullets->status === 200 && Crypto::decryptNullable((string) Database::scalar(
            'SELECT d.`pan_number` FROM `kyc_creator_details` d JOIN `kyc_profiles` p ON p.`id` = d.`profile_id` WHERE p.`user_id` = :u',
            ['u' => $kvCreatorId]
        )) === $creatorPan,
        $bullets->describe(200)
    );

    // --- uniqueness -----------------------------------------------------
    $dupeAadhaar = Http::put('/me/kyc/details', ['aadhaar_number' => $aadhaarA], 'kycv-twin');
    Suite::status('another account cannot reuse the Aadhaar', $dupeAadhaar, 422);
    Suite::equals(
        'the refusal never says whose account',
        $kvErrors($dupeAadhaar)['aadhaar_number'][0] ?? null,
        'This Aadhaar number is already linked to another account.'
    );
    Suite::check(
        'the refusal leaks no name or email',
        !str_contains($dupeAadhaar->body, 'KYCV Creator') && !str_contains($dupeAadhaar->body, Actors::email('kycv-creator')),
        $dupeAadhaar->describe(300)
    );

    $dupePan = Http::put('/me/kyc/details', ['pan_number' => $creatorPan], 'kycv-twin');
    Suite::equals(
        'another account cannot reuse the PAN',
        $kvErrors($dupePan)['pan_number'][0] ?? null,
        'This PAN is already linked to another account.'
    );

    $twinOwn = Http::put('/me/kyc/details', ['aadhaar_number' => $aadhaarB], 'kycv-twin');
    Suite::ok('a different Aadhaar is fine for the other account', $twinOwn);

    // -----------------------------------------------------------------
    Suite::group('KYC verification — brand details');

    $badGst = Http::put('/me/kyc/details', ['gst_number' => substr($brandGst, 0, 14) . ($brandGst[14] === 'A' ? 'B' : 'A')], 'kycv-brand');
    Suite::status('a GSTIN with a bad check character is refused', $badGst, 422);
    Suite::check('the error is keyed gst_number', isset($kvErrors($badGst)['gst_number']), $badGst->describe(300));

    $badState = Http::put('/me/kyc/details', ['gst_number' => '99' . substr($brandGst, 2)], 'kycv-brand');
    Suite::status('a GSTIN with an unknown state code is refused', $badState, 422);

    $badCin = Http::put('/me/kyc/details', ['cin_number' => 'U1234'], 'kycv-brand');
    Suite::status('a malformed CIN is refused', $badCin, 422);
    Suite::check('the error is keyed cin_number', isset($kvErrors($badCin)['cin_number']), $badCin->describe(300));

    $mismatch = Http::put('/me/kyc/details', ['pan_number' => $brandPan, 'gst_number' => $otherGst], 'kycv-brand');
    Suite::status('a GSTIN that does not contain the company PAN is refused', $mismatch, 422);
    Suite::equals(
        'the refusal explains the PAN match',
        $kvErrors($mismatch)['gst_number'][0] ?? null,
        'The GSTIN must contain your company PAN (characters 3-12).'
    );

    $badLink = Http::put('/me/kyc/details', ['social_links' => ['instagram' => 'javascript:alert(1)']], 'kycv-brand');
    Suite::status('a javascript: social link is refused', $badLink, 422);
    Suite::check('the error is keyed social_links', isset($kvErrors($badLink)['social_links']), $badLink->describe(300));

    $badSite = Http::put('/me/kyc/details', ['website' => 'javascript:alert(1)'], 'kycv-brand');
    Suite::status('a javascript: website is refused', $badSite, 422);

    $company = Http::put('/me/kyc/details', [
        'company_name'       => 'IT KYCV Brand Pvt Ltd',
        'company_type'       => 'private_limited',
        'pan_number'         => $brandPan,
        'gst_number'         => strtolower($brandGst),
        'cin_number'         => $brandCin,
        'registered_address' => '1 Test Street, Bengaluru 560001',
        'social_links'       => ['instagram' => 'https://instagram.com/itkycv'],
    ], 'kycv-brand');
    Suite::ok('valid brand details are saved', $company);
    Suite::equals('the GSTIN comes back masked', $company->at('data.details.gst_masked'), str_repeat('X', 11) . substr($brandGst, -4));
    Suite::equals('the CIN comes back masked', $company->at('data.details.cin_masked'), str_repeat('X', 17) . substr($brandCin, -4));
    Suite::equals('a private limited company needs a CIN', $company->at('data.details.cin_required'), true);
    Suite::equals(
        'and its incorporation certificate is required',
        $kvReqFor($company, 'incorporation_certificate')['is_required'] ?? null,
        true
    );
    Suite::check('no brand identifier leaks in clear', !str_contains(strtoupper($company->body), $brandGst)
        && !str_contains(strtoupper($company->body), $brandCin), 'a brand identifier leaked');

    $dupeGst = Http::put('/me/kyc/details', ['pan_number' => $brandPan, 'gst_number' => $brandGst], 'kycv-brand2');
    Suite::status('another brand cannot reuse the GSTIN', $dupeGst, 422);
    Suite::equals(
        'the GSTIN refusal never says whose account',
        $kvErrors($dupeGst)['gst_number'][0] ?? null,
        'This GSTIN is already linked to another account.'
    );

    $partnership = Http::put('/me/kyc/details', ['company_type' => 'partnership'], 'kycv-brand');
    Suite::equals('a partnership needs no CIN', $partnership->at('data.details.cin_required'), false);
    Suite::equals(
        'and no incorporation certificate',
        $kvReqFor($partnership, 'incorporation_certificate')['is_required'] ?? null,
        false
    );

    // -----------------------------------------------------------------
    Suite::group('KYC verification — documents, preview, replacement');

    $front = $kvUpload('kycv-creator', 'aadhaar_front', $kvPng);
    $back  = $kvUpload('kycv-creator', 'aadhaar_back', $kvPdf);
    $pan   = $kvUpload('kycv-creator', 'pan_card', $kvPng);
    Suite::ok('the Aadhaar front is accepted', $front, 201);
    Suite::ok('a PDF Aadhaar back is accepted', $back, 201);
    Suite::ok('the PAN card is accepted', $pan, 201);

    $frontDoc = $front->at('data.documents.0') ?? [];
    Suite::equals('a new document is pending', $frontDoc['status'] ?? null, 'pending');
    Suite::equals('and current', $frontDoc['is_current'] ?? null, true);
    Suite::equals('its sniffed MIME type is reported', $frontDoc['mime_type'] ?? null, 'image/png');
    Suite::check(
        'a preview URL points at the inline route on this API',
        str_starts_with((string) ($frontDoc['preview_url'] ?? ''), Http::baseUrl() . '/me/kyc/documents/' . (int) ($frontDoc['id'] ?? 0) . '/preview?token='),
        'preview_url was ' . json_encode($frontDoc['preview_url'] ?? null)
    );

    $overview = Http::get('/me/kyc', [], 'kycv-creator');
    Suite::equals('three required documents make the profile complete', $overview->at('data.completeness'), 100);
    Suite::equals('each required slot reads pending', $kvReqFor($overview, 'aadhaar_back')['review_status'] ?? null, 'pending');

    $previewPath = substr((string) ($frontDoc['preview_url'] ?? ''), strlen(Http::baseUrl()));
    $preview = Http::get($previewPath, [], 'kycv-creator');
    Suite::status('the owner can preview with the signed URL', $preview, 200);
    Suite::check('the preview is the uploaded bytes', $preview->body === $pngBytes, strlen($preview->body) . ' bytes');
    Suite::check('the preview is inline', str_starts_with((string) $preview->header('content-disposition'), 'inline'), (string) $preview->header('content-disposition'));
    Suite::check('the preview is never cached', str_contains((string) $preview->header('cache-control'), 'no-store'), (string) $preview->header('cache-control'));
    Suite::equals('the preview is never sniffed', $preview->header('x-content-type-options'), 'nosniff');

    Suite::status('another user cannot use the owner\'s preview URL', Http::get($previewPath, [], 'kycv-twin'), 403);
    Suite::status('the preview needs a session', Http::get($previewPath), 401);
    Suite::status(
        'the preview needs a token',
        Http::get('/me/kyc/documents/' . (int) ($frontDoc['id'] ?? 0) . '/preview', [], 'kycv-creator'),
        403
    );
    Suite::status(
        'a token for one document does not preview another',
        Http::get('/me/kyc/documents/' . (int) ($pan->at('data.documents.0.id') ?? 0) . '/preview', ['token' => $frontDoc['download_token'] ?? ''], 'kycv-creator'),
        403
    );

    $replace = $kvUpload('kycv-creator', '', $kvPng, ['replace_document_id' => (int) ($frontDoc['id'] ?? 0)]);
    Suite::ok('replace_document_id uploads a new version without naming the slot', $replace, 201);
    Suite::equals('the replacement is version 2', $replace->at('data.documents.0.version'), 2);
    Suite::equals('in the same slot', $replace->at('data.documents.0.slot'), 'aadhaar_front');

    $oldRow = Database::selectOne('SELECT `is_current`, `replaced_by` FROM `kyc_documents` WHERE `id` = :id', ['id' => (int) ($frontDoc['id'] ?? 0)]) ?? [];
    Suite::equals('the old version is no longer current', (int) ($oldRow['is_current'] ?? 1), 0);
    Suite::equals('and points at its replacement', (int) ($oldRow['replaced_by'] ?? 0), (int) ($replace->at('data.documents.0.id') ?? -1));

    Suite::status(
        'a replacement cannot target another user\'s document',
        $kvUpload('kycv-twin', 'aadhaar_front', $kvPng, ['replace_document_id' => (int) ($replace->at('data.documents.0.id') ?? 0)]),
        404
    );
    Suite::status(
        'a replacement must stay in its slot',
        $kvUpload('kycv-creator', 'pan_card', $kvPng, ['replace_document_id' => (int) ($replace->at('data.documents.0.id') ?? 0)]),
        422
    );

    // -----------------------------------------------------------------
    Suite::group('KYC verification — consent and submit');

    $noConsent = Http::post('/me/kyc/submit', [], 'kycv-creator');
    Suite::status('submit without consent is refused', $noConsent, 422);
    Suite::check('the error is keyed consent', isset($kvErrors($noConsent)['consent']), $noConsent->describe(300));

    $falseConsent = Http::post('/me/kyc/submit', ['consent' => false], 'kycv-creator');
    Suite::status('consent: false is refused too', $falseConsent, 422);

    $submitted = Http::post('/me/kyc/submit', ['consent' => true], 'kycv-creator');
    Suite::ok('submit with consent goes to the queue', $submitted);
    Suite::equals('status is submitted', $submitted->at('data.profile.status'), 'submitted');
    Suite::equals('labelled Pending', $submitted->at('data.profile.status_label'), 'Pending');
    Suite::notEmpty('consent is timestamped', $submitted->at('data.profile.consent_at'));
    Suite::equals('the consent version is recorded', $submitted->at('data.profile.consent_version'), KycService::consentVersion());

    $creatorProfileId = $kvProfileId($kvCreatorId, 'creator');

    // -----------------------------------------------------------------
    Suite::group('KYC verification — review (service level, admin #1)');

    KycService::startReview($creatorProfileId, 1, ['ip' => '198.51.100.7', 'agent' => 'it-kycv']);
    Suite::equals('starting the review moves it under review', KycService::find($creatorProfileId)['status'], 'under_review');

    // Settings → KYC → "Notification channels" governs every KYC status
    // message; "under review" used to go out in-app and push only.
    $kycChannels = \Zuste\Core\Config::setting('kyc', 'notify_channels', '[]');
    $kycChannels = is_array($kycChannels) ? $kycChannels : json_decode((string) $kycChannels, true);
    if (is_array($kycChannels) && in_array('email', $kycChannels, true)) {
        Suite::check(
            'the "under review" status change is emailed too',
            (int) Database::scalar(
                "SELECT COUNT(*) FROM `email_queue` WHERE `to_email` = :e AND `subject` LIKE '%being reviewed%'
                    AND `created_at` >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                ['e' => Actors::email('kycv-creator')]
            ) > 0
        );
    }

    $openAgain = KycService::find($creatorProfileId)['reviewed_at'];
    KycService::startReview($creatorProfileId, 2);
    Suite::equals('re-opening a profile under review changes nothing', KycService::find($creatorProfileId)['reviewed_at'], $openAgain);

    $backDocId = (int) ($back->at('data.documents.0.id') ?? 0);
    KycService::reviewDocument($backDocId, 1, 'reject', 'The scan is blurred.', ['ip' => '198.51.100.7', 'agent' => 'it-kycv']);

    $reviewed = Http::get('/me/kyc', [], 'kycv-creator');
    $backReq  = $kvReqFor($reviewed, 'aadhaar_back') ?? [];
    Suite::equals('the slot shows the rejection', $backReq['review_status'] ?? null, 'rejected');
    Suite::equals('with the reviewer\'s reason', $backReq['rejection_reason'] ?? null, 'The scan is blurred.');
    Suite::equals('and the document itself is rejected', ($kvDocFor($reviewed, 'aadhaar_back') ?? [])['status'] ?? null, 'rejected');
    Suite::check('the applicant was notified of the rejected document', $kvNotified($kvCreatorId, 'kyc.document_rejected'));

    Suite::check(
        'the admin decision is recorded with its IP and agent',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM `kyc_events` WHERE `profile_id` = :p AND `action` = 'document_rejected'
               AND `actor_type` = 'admin' AND `actor_id` = 1 AND `ip_address` = :ip AND `user_agent` = 'it-kycv'",
            ['p' => $creatorProfileId, 'ip' => inet_pton('198.51.100.7')]
        ) === 1
    );

    Suite::check(
        'approval is blocked while a required document is rejected',
        static function () use ($creatorProfileId): bool {
            try {
                KycService::approve($creatorProfileId, 1, 'should not pass', ['aadhaar_masked' => true]);

                return false;
            } catch (HttpException $e) {
                return $e->errorCode() === 'kyc_incomplete'
                    && KycService::find($creatorProfileId)['status'] === 'under_review';
            }
        }
    );

    // The applicant was just told the document was not accepted, so they must
    // be able to act on it even though the profile is still with the reviewer.
    Suite::equals('a document rejected mid-review is reopened for upload', $reviewed->at('data.profile.resubmit_slots'), ['aadhaar_back']);
    Suite::equals('and flagged on its requirement', $backReq['resubmit_requested'] ?? null, true);
    Suite::equals('while the profile stays under review', $reviewed->at('data.profile.status'), 'under_review');

    $otherLocked = $kvUpload('kycv-creator', 'pan_card', $kvPng);
    Suite::status('every other slot stays locked during the review', $otherLocked, 409);

    $midReview = $kvUpload('kycv-creator', 'aadhaar_back', $kvPng, ['replace_document_id' => $backDocId]);
    Suite::ok('the rejected document can be re-uploaded mid-review', $midReview, 201);

    $afterMid = Http::get('/me/kyc', [], 'kycv-creator');
    Suite::equals('the re-upload answers the request', $afterMid->at('data.profile.resubmit_slots'), []);
    Suite::equals('the fresh copy is pending for the reviewer', ($kvReqFor($afterMid, 'aadhaar_back') ?? [])['review_status'] ?? null, 'pending');
    Suite::equals('the profile is still under review', $afterMid->at('data.profile.status'), 'under_review');
    Suite::status(
        'the answered slot is closed again',
        $kvUpload('kycv-creator', 'aadhaar_back', $kvPng, ['replace_document_id' => (int) ($midReview->at('data.documents.0.id') ?? 0)]),
        409
    );

    // Still not good enough: rejected again, then sent back formally.
    $backDocId = (int) ($midReview->at('data.documents.0.id') ?? 0);
    KycService::reviewDocument($backDocId, 1, 'reject', 'Still blurred.', ['ip' => '198.51.100.7']);

    KycService::requestResubmission($creatorProfileId, 1, ['aadhaar_back'], 'Please rescan in good light.', ['ip' => '198.51.100.7']);

    $sameState = $kvRefusal(static fn () => KycService::requestInformation($creatorProfileId, 1, 'And your address proof.'));
    Suite::equals(
        'asking an info_required profile again is refused, not silently overwritten',
        $sameState?->errorCode(),
        'kyc_already_in_state'
    );
    Suite::equals(
        'and the open re-upload request survives it',
        KycService::resubmitSlots(KycService::find($creatorProfileId)),
        ['aadhaar_back']
    );

    $asked = Http::get('/me/kyc', [], 'kycv-creator');
    Suite::equals('a re-upload request makes it info_required', $asked->at('data.profile.status'), 'info_required');
    Suite::equals('labelled Action needed', $asked->at('data.profile.status_label'), 'Action needed');
    Suite::equals('the requested slots are listed', $asked->at('data.profile.resubmit_slots'), ['aadhaar_back']);
    Suite::equals('and flagged on the requirement', ($kvReqFor($asked, 'aadhaar_back') ?? [])['resubmit_requested'] ?? null, true);
    Suite::check('the applicant was notified of the request', $kvNotified($kvCreatorId, 'kyc.resubmission_requested'));

    $tooEarly = Http::post('/me/kyc/submit', ['consent' => true], 'kycv-creator');
    Suite::status('resubmitting before the re-upload is refused', $tooEarly, 422);

    $reupload = $kvUpload('kycv-creator', 'aadhaar_back', $kvPng, ['replace_document_id' => $backDocId]);
    Suite::ok('the rejected document can be replaced', $reupload, 201);

    $resubmitted = Http::post('/me/kyc/submit', ['consent' => true], 'kycv-creator');
    Suite::ok('then the profile can be resubmitted', $resubmitted);
    Suite::equals('which clears resubmit_slots', $resubmitted->at('data.profile.resubmit_slots'), []);

    // -----------------------------------------------------------------
    Suite::group('KYC verification — the blue tick');

    KycService::startReview($creatorProfileId, 1);

    $noReviewer = $kvRefusal(static fn () => KycService::approve($creatorProfileId, null, 'Auto-approved by policy.'));
    Suite::equals('an approval with no reviewer is refused', $noReviewer?->errorCode(), 'kyc_reviewer_required');

    Suite::equals('an Aadhaar on file asks for the masked-copy attestation', KycService::needsAadhaarMaskedAttestation($creatorProfileId), true);

    $unattested = $kvRefusal(static fn () => KycService::approve($creatorProfileId, 1, 'Documents verified.', [], ['ip' => '198.51.100.7']));
    Suite::equals('approving without "Aadhaar copy is masked" is refused', $unattested?->getMessage(), 'Confirm the Aadhaar copy is masked before approving.');
    Suite::equals('as an approval blocker', $unattested?->errorCode(), 'kyc_incomplete');
    Suite::equals('and nothing moved', KycService::find($creatorProfileId)['status'], 'under_review');

    // The attestation, with the audit context in the position older callers
    // used for it (ip / agent in the 4th argument) — both must land.
    KycService::approve($creatorProfileId, 1, 'Documents verified.', ['aadhaar_masked' => '1', 'ip' => '198.51.100.7', 'agent' => 'it-kycv']);

    Suite::equals('the KYC is approved', KycService::find($creatorProfileId)['status'], 'approved');

    $approvedEvent = $kvEvents($creatorProfileId, 'approved');
    $approvedMeta  = json_decode((string) (end($approvedEvent)['metadata'] ?? ''), true);
    Suite::equals('the attestation is recorded on the approval event', $approvedMeta['attestations']['aadhaar_masked'] ?? null, true);
    Suite::check(
        'with the reviewer\'s IP and agent',
        (end($approvedEvent)['ip_address'] ?? null) === inet_pton('198.51.100.7') && (end($approvedEvent)['user_agent'] ?? null) === 'it-kycv'
    );
    Suite::check(
        'the documents are approved by the reviewer, not by nobody',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM `kyc_documents` WHERE `profile_id` = :p AND `is_current` = 1 AND `deleted_at` IS NULL
                AND `purged_at` IS NULL AND (`status` <> 'approved' OR `reviewed_by` IS NULL OR `reviewed_by` <> 1)",
            ['p' => $creatorProfileId]
        ) === 0
    );

    // Re-approving used to push the expiry out by another validity period,
    // re-send "approved" and write nothing to the audit trail.
    $expiresBefore = KycService::find($creatorProfileId)['expires_on'];
    Database::execute('UPDATE `kyc_profiles` SET `expires_on` = DATE_ADD(CURDATE(), INTERVAL 100 DAY) WHERE `id` = :p', ['p' => $creatorProfileId]);
    $shiftedExpiry = KycService::find($creatorProfileId)['expires_on'];
    $reApprove     = $kvRefusal(static fn () => KycService::approve($creatorProfileId, 1, 'Again.', ['aadhaar_masked' => true]));
    Suite::equals('approving an approved profile is refused', $reApprove?->errorCode(), 'kyc_already_in_state');
    Suite::equals('and leaves its expiry alone', KycService::find($creatorProfileId)['expires_on'], $shiftedExpiry);
    Suite::equals('with no second approval event', count($kvEvents($creatorProfileId, 'approved')), count($approvedEvent));
    Database::execute('UPDATE `kyc_profiles` SET `expires_on` = :e WHERE `id` = :p', ['e' => $expiresBefore, 'p' => $creatorProfileId]);

    $reReject = $kvRefusal(static fn () => KycService::requestResubmission($creatorProfileId, 1, ['pan_card'], 'x'));
    Suite::equals('an approved profile cannot be sent back for re-upload', $reReject?->errorCode(), 'invalid_kyc_transition');

    // The KYC-derived public badges follow the approval, immediately.
    $creatorRowId = (int) (Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :u', ['u' => $kvCreatorId]) ?? 0);
    $catalogue    = CreatorBadgeService::catalogue();
    $kycBadges    = array_values(array_filter(['identity_verified', 'kyc_verified'], static fn (string $b): bool => isset($catalogue[$b])));
    $publicBadges = static fn (): array => array_column(CreatorBadgeService::forCreator($creatorRowId), 'value');

    if ($creatorRowId > 0 && $kycBadges !== []) {
        Suite::check(
            'approval puts the KYC badges on the public profile at once',
            array_diff($kycBadges, $publicBadges()) === [],
            json_encode($publicBadges())
        );
    }

    Suite::check(
        'a KYC badge cannot be awarded by hand',
        static function () use ($kvRefusal, $creatorRowId): bool {
            $e = $kvRefusal(static fn () => CreatorBadgeService::award($creatorRowId, 'kyc_verified', ['actor_type' => 'admin', 'actor_id' => 1]));

            return $e?->errorCode() === 'kyc_managed_badge';
        }
    );
    Suite::check(
        'nor removed by hand',
        static function () use ($kvRefusal, $creatorRowId): bool {
            $e = $kvRefusal(static fn () => CreatorBadgeService::revoke($creatorRowId, 'identity_verified', ['actor_type' => 'admin', 'actor_id' => 1, 'reason' => 'x']));

            return $e?->errorCode() === 'kyc_managed_badge';
        }
    );
    Suite::check(
        'the creator screen refuses KYC, tick and automatic badges alike',
        static function () use ($kvRefusal, $catalogue): bool {
            foreach (['identity_verified', 'kyc_verified', 'business_verified', 'creator_verified'] as $badge) {
                if (isset($catalogue[$badge])
                    && $kvRefusal(static fn () => CreatorBadgeService::assertManuallyManageable($badge))?->errorCode() !== 'kyc_managed_badge') {
                    return false;
                }
            }

            foreach ($catalogue as $badge => $definition) {
                if ($definition['automatic'] && !CreatorBadgeService::isKycBadge($badge) && $badge !== 'creator_verified'
                    && $kvRefusal(static fn () => CreatorBadgeService::assertManuallyManageable($badge))?->errorCode() !== 'automatic_badge') {
                    return false;
                }
            }

            return true;
        }
    );
    Suite::equals('users.kyc_status reads verified', Database::scalar('SELECT `kyc_status` FROM `users` WHERE `id` = :u', ['u' => $kvCreatorId]), 'verified');
    Suite::equals('approval does NOT set the tick', $kvTick($kvCreatorId), ['users' => 0, 'creators' => 0]);

    $approvedView = Http::get('/me/kyc', [], 'kycv-creator');
    Suite::equals('the app sees badge_status none after approval', $approvedView->at('data.profile.badge_status'), 'none');
    Suite::equals('and has_blue_tick false', $approvedView->at('data.profile.has_blue_tick'), false);
    Suite::check(
        'the "Verified Creator" badge follows the tick, not the approval',
        !in_array('verified_creator', array_column($approvedView->at('data.profile.badges') ?? [], 'code'), true),
        json_encode($approvedView->at('data.profile.badges'))
    );

    KycService::grantBadge($creatorProfileId, 1, 'IT grant: founder fast-tracked', ['ip' => '198.51.100.7']);
    Suite::equals('granting sets both tick columns', $kvTick($kvCreatorId), ['users' => 1, 'creators' => 1]);

    $twice = $kvRefusal(static fn () => KycService::grantBadge($creatorProfileId, 1));
    Suite::equals('granting a tick that is already showing is refused', $twice?->errorCode(), 'kyc_badge_already_granted');

    // Internal admin activity the applicant must never see in their trail.
    KycService::recordEvent($creatorProfileId, 'note_added', ['actor_type' => 'admin', 'actor_id' => 1, 'note' => 'Internal note added.']);
    KycService::recordEvent($creatorProfileId, 'document_previewed', ['actor_type' => 'admin', 'actor_id' => 1, 'note' => 'Reviewer viewed PAN card.']);

    $granted = Http::get('/me/kyc', [], 'kycv-creator');
    Suite::equals('the app sees the granted tick', $granted->at('data.profile.badge_status'), 'granted');
    Suite::notEmpty('with its date', $granted->at('data.profile.badge_granted_at'));
    Suite::check('the timeline records the grant', in_array('badge_granted', array_column($granted->at('data.timeline') ?? [], 'action'), true));
    Suite::check('the applicant was notified of the grant', $kvNotified($kvCreatorId, 'kyc.badge_granted'));

    $userTimeline = $granted->at('data.timeline') ?? [];
    $adminName    = (string) (Database::scalar('SELECT `name` FROM `admins` WHERE `id` = 1') ?? '');
    Suite::check(
        'the applicant never sees the internal grant note',
        !str_contains((string) json_encode($userTimeline), 'fast-tracked')
            && !str_contains(Http::get('/me/kyc/timeline', [], 'kycv-creator')->body, 'fast-tracked'),
        json_encode($userTimeline)
    );
    Suite::check(
        'admin decisions are shown as the Zusty team, never a reviewer\'s name',
        static function () use ($userTimeline, $adminName): bool {
            $adminRows = array_filter($userTimeline, static fn (array $e): bool => ($e['actor_type'] ?? '') === 'admin');

            foreach ($adminRows as $event) {
                if (($event['actor_name'] ?? null) !== KycService::TEAM_NAME) {
                    return false;
                }
            }

            return $adminRows !== []
                && ($adminName === '' || !str_contains((string) json_encode($userTimeline), $adminName));
        },
        json_encode(array_slice($userTimeline, 0, 4))
    );
    Suite::check(
        'internal notes and document views are not in the applicant\'s trail',
        array_intersect(['note_added', 'document_previewed', 'document_downloaded'], array_column($userTimeline, 'action')) === [],
        json_encode(array_column($userTimeline, 'action'))
    );
    Suite::check('no IP is served on the applicant\'s trail', !str_contains((string) json_encode($userTimeline), '198.51.100.7'));

    $adminTimeline = KycService::timeline($creatorProfileId, 100, true);
    Suite::check(
        'the reviewer\'s own view keeps all of it',
        in_array('note_added', array_column($adminTimeline, 'action'), true)
            && str_contains((string) json_encode($adminTimeline), 'fast-tracked'),
        json_encode(array_slice($adminTimeline, 0, 3))
    );

    Suite::check(
        'the creator_verified badge mirrors the tick',
        $creatorRowId > 0 && (string) Database::scalar(
            "SELECT `status` FROM `creator_badges` WHERE `creator_id` = :c AND `badge` = 'creator_verified'",
            ['c' => $creatorRowId]
        ) === 'active'
    );

    KycService::revokeBadge($creatorProfileId, 1, 'IT revoke check');
    Suite::equals('revoking clears both tick columns', $kvTick($kvCreatorId), ['users' => 0, 'creators' => 0]);
    Suite::equals('and marks the badge revoked', KycService::find($creatorProfileId)['badge_status'], 'revoked');
    Suite::check('the applicant was notified of the revocation', $kvNotified($kvCreatorId, 'kyc.badge_revoked'));

    $revokeAgain = $kvRefusal(static fn () => KycService::revokeBadge($creatorProfileId, 1, 'Again'));
    Suite::equals('revoking a tick that is not there is refused', $revokeAgain?->errorCode(), 'kyc_badge_not_granted');

    if ($creatorRowId > 0 && $kycBadges !== []) {
        Suite::check(
            'the KYC badges follow the approval, not the tick',
            array_diff($kycBadges, $publicBadges()) === [],
            json_encode($publicBadges())
        );
    }

    KycService::grantBadge($creatorProfileId, 1);
    KycService::suspend($creatorProfileId, 1, 'IT suspension');
    Suite::equals('suspension takes the tick away', $kvTick($kvCreatorId), ['users' => 0, 'creators' => 0]);
    Suite::equals('and revokes the badge', KycService::find($creatorProfileId)['badge_status'], 'revoked');

    if ($creatorRowId > 0 && $kycBadges !== []) {
        Suite::check(
            'and every KYC badge, at once — not at the nightly badge job',
            array_intersect($kycBadges, $publicBadges()) === []
                && (int) Database::scalar(
                    "SELECT COUNT(*) FROM `creator_badges` WHERE `creator_id` = :c AND `status` = 'active'
                        AND `badge` IN ('identity_verified', 'kyc_verified', 'business_verified')",
                    ['c' => $creatorRowId]
                ) === 0,
            json_encode($publicBadges())
        );
    }

    $reSuspend = $kvRefusal(static fn () => KycService::suspend($creatorProfileId, 1, 'Again'));
    Suite::equals('suspending a suspended profile is refused', $reSuspend?->errorCode(), 'kyc_already_in_state');
    Suite::equals('and keeps the reason the user was given', KycService::find($creatorProfileId)['rejection_reason'], 'IT suspension');

    Suite::check(
        'the tick cannot be granted on a suspended profile',
        static function () use ($creatorProfileId): bool {
            try {
                KycService::grantBadge($creatorProfileId, 1);

                return false;
            } catch (HttpException $e) {
                return $e->errorCode() === 'kyc_not_approved';
            }
        }
    );

    // Suspension is not a dead end: a reviewer can take it back into review.
    $noReason = $kvRefusal(static fn () => KycService::reinstate($creatorProfileId, 1, ' '));
    Suite::equals('lifting a suspension needs a reason', $noReason?->status(), 422);

    KycService::reinstate($creatorProfileId, 1, 'IT: investigation closed', ['ip' => '198.51.100.7']);
    Suite::equals('a suspended profile can be reinstated for review', KycService::find($creatorProfileId)['status'], 'under_review');
    Suite::equals('the reinstatement is on the trail', count($kvEvents($creatorProfileId, 'reinstated')), 1);
    Suite::equals('the suspension reason is cleared', KycService::find($creatorProfileId)['rejection_reason'], null);
    Suite::equals('reinstating does not bring the tick back', $kvTick($kvCreatorId), ['users' => 0, 'creators' => 0]);

    $notSuspended = $kvRefusal(static fn () => KycService::reinstate($creatorProfileId, 1, 'Again'));
    Suite::equals('only a suspended profile can be reinstated', $notSuspended?->errorCode(), 'invalid_kyc_transition');

    // A tick from before the badge model (set directly on the columns) must
    // not survive a rejection either — that was the defect.
    Database::execute('UPDATE `users` SET `has_blue_tick` = 1 WHERE `id` = :u', ['u' => $kvCreatorId]);
    Database::execute('UPDATE `creators` SET `is_verified` = 1 WHERE `user_id` = :u', ['u' => $kvCreatorId]);
    KycService::reject($creatorProfileId, 1, 'Name on PAN <RAHUL K> differs from profile & ID');
    Suite::equals('rejection takes any tick away', $kvTick($kvCreatorId), ['users' => 0, 'creators' => 0]);

    $reReject = $kvRefusal(static fn () => KycService::reject($creatorProfileId, 1, 'A different reason'));
    Suite::equals('rejecting a rejected profile is refused', $reReject?->errorCode(), 'kyc_already_in_state');
    Suite::equals(
        'so the reason the user was given is not silently replaced',
        KycService::find($creatorProfileId)['rejection_reason'],
        'Name on PAN <RAHUL K> differs from profile & ID'
    );

    // Values placed into an HTML email are escaped; plain-text channels are not.
    $rejectMail = (string) (Database::scalar(
        'SELECT `body_html` FROM `email_queue` WHERE `to_email` = :e AND `subject` LIKE :s ORDER BY `id` DESC LIMIT 1',
        ['e' => Actors::email('kycv-creator'), 's' => '%Action needed%']
    ) ?? '');
    Suite::check(
        'the rejection email escapes the reviewer\'s reason',
        str_contains($rejectMail, '&lt;RAHUL K&gt;') && !str_contains($rejectMail, '<RAHUL K>') && str_contains($rejectMail, '&amp; ID'),
        mb_substr($rejectMail, 0, 400)
    );
    Suite::check(
        'the in-app notification keeps the reason as plain text',
        str_contains((string) Database::scalar(
            "SELECT `body` FROM `notifications` WHERE `user_id` = :u AND `type` = 'kyc.rejected' ORDER BY `id` DESC LIMIT 1",
            ['u' => $kvCreatorId]
        ), '<RAHUL K>')
    );
    Suite::equals(
        'Str::interpolateHtml escapes every value',
        \Zuste\Helpers\Str::interpolateHtml('<p>Hi {{name}} {{x}}</p>', ['name' => '<a href="x">Z</a>', 'x' => 'O\'Neil & co']),
        '<p>Hi &lt;a href=&quot;x&quot;&gt;Z&lt;/a&gt; O&#039;Neil &amp; co</p>'
    );

    // A notification failure never fails (or rolls back) a decision.
    Suite::check(
        'a failing notification is logged on the admin trail, not thrown',
        static function () use ($creatorProfileId, $kvCreatorId): bool {
            $notify = new \ReflectionMethod(KycService::class, 'notify');

            try {
                // An object cannot be turned into text: NotificationService throws.
                $notify->invoke(null, $kvCreatorId, 'kyc.rejected', ['reference' => 'X', 'reason' => new \stdClass()], $creatorProfileId);
            } catch (\Throwable) {
                return false;
            }

            return (int) Database::scalar(
                "SELECT COUNT(*) FROM `kyc_events` WHERE `profile_id` = :p AND `action` = 'notification_failed'",
                ['p' => $creatorProfileId]
            ) === 1;
        }
    );
    Suite::check(
        'and that marker is not on the applicant\'s trail',
        !in_array('notification_failed', array_column(Http::get('/me/kyc/timeline', [], 'kycv-creator')->at('data.timeline') ?? [], 'action'), true)
    );

    // There is no automatic approval, and approval never grants the tick.
    $brandProfileId = $kvProfileId($kvBrandId, 'brand');
    $kvUpload('kycv-brand', 'gst_certificate', $kvPng);
    $brandSubmit = Http::post('/me/kyc/submit', ['consent' => true], 'kycv-brand');
    Suite::ok('a complete partnership brand can submit', $brandSubmit);
    Suite::equals('and waits for a human, whatever kyc.auto_approve says', KycService::find($brandProfileId)['status'], 'submitted');

    $systemApproval = $kvRefusal(static fn () => KycService::approve($brandProfileId, null, 'Auto-approved by policy.'));
    Suite::equals('a system approval is refused', $systemApproval?->errorCode(), 'kyc_reviewer_required');
    Suite::equals('and moves nothing', KycService::find($brandProfileId)['status'], 'submitted');

    Suite::equals('a brand with no Aadhaar needs no masked-copy attestation', KycService::needsAadhaarMaskedAttestation($brandProfileId), false);
    KycService::approve($brandProfileId, 1, 'Business documents verified.');
    Suite::equals('a reviewer approves the brand KYC', KycService::find($brandProfileId)['status'], 'approved');
    Suite::equals('but grants no tick', $kvTick($kvBrandId)['users'], 0);
    Suite::equals('and leaves the badge undecided', KycService::find($brandProfileId)['badge_status'], 'none');

    KycService::grantBadge($brandProfileId, 1);
    Suite::equals('a brand tick lives on users.has_blue_tick', $kvTick($kvBrandId)['users'], 1);

    $board = Http::get('/campaigns', [], 'kycv-creator');
    Suite::check(
        'campaign brand blocks carry is_verified',
        static function () use ($board): bool {
            $items = $board->list('items') ?: $board->list('campaigns') ?: ($board->at('data') ?? []);

            foreach ((array) $items as $item) {
                if (is_array($item) && isset($item['brand']) && !array_key_exists('is_verified', $item['brand'])) {
                    return false;
                }
            }

            return $board->status === 200;
        },
        $board->describe(300)
    );

    Suite::check(
        'the tick cannot be granted before approval',
        static function () use ($kvBrand2Id): bool {
            $profileId = (int) KycService::profileFor($kvBrand2Id, 'brand')['id'];

            try {
                KycService::grantBadge($profileId, 1);

                return false;
            } catch (HttpException $e) {
                return $e->errorCode() === 'kyc_not_approved';
            }
        }
    );

    // -----------------------------------------------------------------
    Suite::group('KYC verification — expiry reminders');

    Database::execute(
        "UPDATE `kyc_profiles` SET `status` = 'approved', `expires_on` = DATE_ADD(CURDATE(), INTERVAL 5 DAY) WHERE `id` = :p",
        ['p' => $brandProfileId]
    );

    $firstSweep  = KycService::runExpirySweep($kvBrandId);
    $secondSweep = KycService::runExpirySweep($kvBrandId);
    Suite::equals('the first sweep sends one reminder', $firstSweep['warned'], 1);
    Suite::equals('the next day\'s sweep sends none (once per 7 days)', $secondSweep['warned'], 0);

    // -----------------------------------------------------------------
    Suite::group('KYC verification — lapsed approvals and expired documents');

    // The brand is approved and ticked. Its validity runs out yesterday; the
    // tick must go the first time anybody looks, not at the nightly sweep.
    Suite::equals('the brand still carries its tick', $kvTick($kvBrandId)['users'], 1);
    Database::execute(
        'UPDATE `kyc_profiles` SET `expires_on` = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE `id` = :p',
        ['p' => $brandProfileId]
    );

    $lapsedView = Http::get('/me/kyc', [], 'kycv-brand');
    Suite::equals('a lapsed approval reads expired as soon as its owner looks', $lapsedView->at('data.profile.status'), 'expired');
    Suite::equals('the tick is gone with it', $kvTick($kvBrandId)['users'], 0);
    Suite::equals('the badge is revoked', KycService::find($brandProfileId)['badge_status'], 'revoked');
    Suite::equals('the expiry is on the trail as a system event', $kvEvents($brandProfileId, 'expired')[0]['actor_type'] ?? null, 'system');
    Suite::check('the owner is told', $kvNotified($kvBrandId, 'kyc.expired'));

    // A tick cannot be granted on an approval past its date, even before any
    // sweep has noticed (status still "approved" in the row).
    Database::execute(
        "UPDATE `kyc_profiles` SET `status` = 'approved', `badge_status` = 'none' WHERE `id` = :p",
        ['p' => $brandProfileId]
    );
    $lapsedGrant = $kvRefusal(static fn () => KycService::grantBadge($brandProfileId, 1));
    Suite::equals('granting on a lapsed approval is refused', $lapsedGrant?->errorCode(), 'kyc_not_approved');
    Suite::equals('which is expired on the spot', KycService::find($brandProfileId)['status'], 'expired');
    Suite::equals('and no tick was set', $kvTick($kvBrandId)['users'], 0);

    // A document whose own expiry date passes: event + notification, and the
    // sweep no longer skips this step (scoped or not).
    $tomorrow  = date('Y-m-d', strtotime('+1 day'));
    $trademark = $kvUpload('kycv-brand', 'trademark', $kvPng, ['expires_on' => $tomorrow]);
    Suite::ok('an expiring document is accepted on an expired (editable) profile', $trademark, 201);
    $trademarkId = (int) ($trademark->at('data.documents.0.id') ?? 0);
    Database::execute(
        'UPDATE `kyc_documents` SET `expires_on` = DATE_SUB(CURDATE(), INTERVAL 2 DAY) WHERE `id` = :id',
        ['id' => $trademarkId]
    );

    $docSweep = KycService::runExpirySweep($kvBrandId);
    Suite::equals('the scoped sweep expires the document', $docSweep['documents_expired'] ?? null, 1);
    $trademarkRow = Database::selectOne('SELECT `status`, `rejection_reason` FROM `kyc_documents` WHERE `id` = :id', ['id' => $trademarkId]) ?? [];
    Suite::equals('the document reads rejected — expired', [$trademarkRow['status'] ?? null, $trademarkRow['rejection_reason'] ?? null], ['rejected', 'Document expired']);
    Suite::equals('with a document_expired event', count($kvEvents($brandProfileId, 'document_expired')), 1);
    Suite::check(
        'and the owner is told',
        $kvNotified($kvBrandId, \Zuste\Services\NotificationService::hasTemplate('kyc.document_expired') ? 'kyc.document_expired' : 'kyc.document_rejected')
    );
    Suite::equals('a second sweep does nothing more', KycService::runExpirySweep($kvBrandId)['documents_expired'] ?? null, 0);

    // A suspension can end in a state the user can act on.
    Database::execute("UPDATE `kyc_profiles` SET `status` = 'suspended' WHERE `id` = :p", ['p' => $brandProfileId]);
    KycService::requestResubmission($brandProfileId, 1, ['gst_certificate'], 'Upload your current certificate.');
    Suite::equals('a suspended profile can be sent back for new documents', KycService::find($brandProfileId)['status'], 'info_required');
    Database::execute("UPDATE `kyc_profiles` SET `status` = 'suspended' WHERE `id` = :p", ['p' => $brandProfileId]);
    KycService::reject($brandProfileId, 1, 'Suspension reviewed; please correct and resubmit.');
    Suite::equals('or rejected so the user can correct and resubmit', KycService::find($brandProfileId)['status'], 'rejected');

    // -----------------------------------------------------------------
    Suite::group('KYC verification — export and purge');

    $export     = KycService::exportSummary($kvCreatorId);
    $exportJson = (string) json_encode($export);
    Suite::equals('the export has one creator profile', count($export), 1);
    Suite::equals('its identifiers are masked to the last four', $export[0]['identifiers'] ?? null, [
        'pan'     => 'XXXXXX' . substr($creatorPan, -4),
        'aadhaar' => 'XXXX XXXX ' . substr($aadhaarA, -4),
    ]);
    Suite::check(
        'the export holds no clear number, URL or token',
        !str_contains($exportJson, $creatorPan) && !str_contains($exportJson, $aadhaarA)
            && !str_contains($exportJson, 'http') && !str_contains($exportJson, 'token') && !str_contains($exportJson, '.enc'),
        $exportJson
    );
    Suite::check(
        'documents are listed as slot / label / status / uploaded_at only',
        static function () use ($export): bool {
            foreach ($export[0]['documents'] ?? [] as $document) {
                if (array_keys($document) !== ['slot', 'label', 'status', 'uploaded_at']) {
                    return false;
                }
            }

            return ($export[0]['documents'] ?? []) !== [];
        }
    );

    // Retention, scoped to this one user: a replaced version older than the
    // window, and an orphaned blob in the user's own vault directory.
    $replacedId = (int) ($frontDoc['id'] ?? 0);
    Database::execute(
        'UPDATE `kyc_documents` SET `created_at` = DATE_SUB(NOW(), INTERVAL 400 DAY) WHERE `id` = :id',
        ['id' => (int) ($replace->at('data.documents.0.id') ?? 0)]
    );
    $replacedPath = (string) Database::scalar('SELECT `file_path` FROM `kyc_documents` WHERE `id` = :id', ['id' => $replacedId]);
    $storageRoot  = rtrim((string) \Zuste\Core\Config::get('storage.base_path'), '/\\');
    $orphanPath   = $storageRoot . sprintf('/kyc/%02d/%d/it-orphan.png.enc', $kvCreatorId % 100, $kvCreatorId);
    @mkdir(dirname($orphanPath), 0770, true);
    file_put_contents($orphanPath, 'orphan');
    touch($orphanPath, time() - 3 * 86400);

    $dry = KycService::runRetentionSweep(true, $kvCreatorId);
    Suite::check('a dry run counts without deleting', $dry['orphan_files'] === 1 && is_file($orphanPath) && $dry['superseded_purged'] >= 1, json_encode($dry));

    $sweep = KycService::runRetentionSweep(false, $kvCreatorId);
    Suite::check('the sweep purges the old replaced version', $sweep['superseded_purged'] >= 1
        && Database::scalar('SELECT `purged_at` FROM `kyc_documents` WHERE `id` = :id', ['id' => $replacedId]) !== null
        && !is_file($storageRoot . '/' . $replacedPath), json_encode($sweep));
    Suite::check('and the orphaned blob', $sweep['orphan_files'] === 1 && !is_file($orphanPath), json_encode($sweep));
    Suite::check(
        'current documents of the profile are kept',
        (int) Database::scalar(
            'SELECT COUNT(*) FROM `kyc_documents` WHERE `profile_id` = :p AND `is_current` = 1 AND `purged_at` IS NULL',
            ['p' => $creatorProfileId]
        ) === 3
    );

    $paths = array_map('strval', Database::column(
        'SELECT `file_path` FROM `kyc_documents` WHERE `user_id` = :u AND `file_path` IS NOT NULL AND `purged_at` IS NULL',
        ['u' => $kvCreatorId]
    ));

    $purge = KycService::purgeForUser($kvCreatorId, 'IT account deletion');
    Suite::equals('purge reports the profile it erased', $purge['profiles'], 1);
    Suite::check(
        'every stored file is gone',
        static function () use ($paths, $storageRoot): bool {
            foreach ($paths as $path) {
                if (is_file($storageRoot . '/' . $path)) {
                    return false;
                }
            }

            return $paths !== [];
        }
    );
    Suite::equals(
        'the detail block (numbers, hashes, names) is deleted',
        (int) Database::scalar('SELECT COUNT(*) FROM `kyc_creator_details` WHERE `profile_id` = :p', ['p' => $creatorProfileId]),
        0
    );
    Suite::equals(
        'document rows keep no path, name or number',
        (int) Database::scalar(
            'SELECT COUNT(*) FROM `kyc_documents` WHERE `user_id` = :u
               AND (`file_path` IS NOT NULL OR `original_name` IS NOT NULL OR `purged_at` IS NULL)',
            ['u' => $kvCreatorId]
        ),
        0
    );
    Suite::check('the profile row survives as the record', KycService::find($creatorProfileId)['purged_at'] !== null);
    $flags = Database::selectOne(
        'SELECT `kyc_status`, `has_blue_tick`, `is_identity_verified` FROM `users` WHERE `id` = :u',
        ['u' => $kvCreatorId]
    ) ?? [];
    Suite::equals(
        'the user flags are reset',
        [(string) ($flags['kyc_status'] ?? ''), (int) ($flags['has_blue_tick'] ?? -1), (int) ($flags['is_identity_verified'] ?? -1)],
        ['none', 0, 0]
    );
    Suite::check('a purged profile does not pass the KYC gate', !KycService::isVerified($kvCreatorId, 'creator'));

    $again = KycService::purgeForUser($kvCreatorId, 'IT account deletion');
    Suite::equals('a second purge is a no-op', $again, ['profiles' => 0, 'documents' => 0, 'files' => 0]);
    Suite::equals(
        'and records no second purge event',
        (int) Database::scalar("SELECT COUNT(*) FROM `kyc_events` WHERE `profile_id` = :p AND `action` = 'purged'", ['p' => $creatorProfileId]),
        1
    );

    Suite::check(
        'purging a user with no KYC at all is safe',
        static function () use ($kvTwinId): bool {
            $summary = KycService::purgeForUser(999999999, 'nobody');

            return $summary === ['profiles' => 0, 'documents' => 0, 'files' => 0];
        }
    );

    $reuse = Http::put('/me/kyc/details', ['aadhaar_number' => $aadhaarA], 'kycv-twin');
    Suite::ok('a purged Aadhaar no longer blocks another account', $reuse);

    // -----------------------------------------------------------------
    Suite::group('KYC verification — audit integrity');

    Suite::check(
        'kyc_events refuses an UPDATE',
        static function () use ($creatorProfileId): bool {
            try {
                Database::execute("UPDATE `kyc_events` SET `note` = 'tampered' WHERE `profile_id` = :p", ['p' => $creatorProfileId]);

                return false;
            } catch (\Throwable $e) {
                return str_contains($e->getMessage(), 'append-only');
            }
        }
    );

    Suite::check(
        'kyc_events refuses a DELETE',
        static function () use ($creatorProfileId): bool {
            try {
                Database::execute('DELETE FROM `kyc_events` WHERE `profile_id` = :p', ['p' => $creatorProfileId]);

                return false;
            } catch (\Throwable $e) {
                return str_contains($e->getMessage(), 'append-only');
            }
        }
    );
}

// -----------------------------------------------------------------
Suite::group('KYC verification — legacy paths');

if ($kvCreator === null) {
    Suite::skipGroup('KYC verification — legacy paths', 'no creator session');
} else {
    $legacy = Http::post('/me/kyc/legacy', ['document_type' => 'aadhaar', 'document_number' => '1234', 'holder_name' => 'IT'], 'kycv-creator');
    Suite::status('POST /me/kyc/legacy is gone', $legacy, 410);
    Suite::equals('with code gone', $legacy->code(), 'gone');
    Suite::equals('pointing at the verification centre', $legacy->message(), 'Use the verification centre.');
    Suite::status('GET /me/kyc/legacy still reads', Http::get('/me/kyc/legacy', [], 'kycv-creator'), 200);

    $kycFolder = Http::upload('/me/upload', ['file' => $kvPng], ['folder' => 'kyc', 'kind' => 'image'], 'kycv-creator');
    Suite::status('the generic uploader refuses folder=kyc', $kycFolder, 422);
}

// -----------------------------------------------------------------
// Cleanup — only the it-kycv-* accounts.
// -----------------------------------------------------------------

foreach ([$kvCreatorId, $kvTwinId, $kvBrandId, $kvBrand2Id] as $uid) {
    $kvReset($uid);
}

@unlink($kvPng);
@unlink($kvPdf);

Suite::check(
    'the suite left no KYC rows for its accounts',
    (int) Database::scalar(
        'SELECT COUNT(*) FROM `kyc_profiles` WHERE `user_id` IN (:a, :b, :c, :d)',
        ['a' => $kvCreatorId, 'b' => $kvTwinId, 'c' => $kvBrandId, 'd' => $kvBrand2Id]
    ) === 0
);

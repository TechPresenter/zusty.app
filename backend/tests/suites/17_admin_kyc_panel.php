<?php

declare(strict_types=1);

/**
 * Admin KYC panel — the reviewer's side of verification, driven through the
 * real panel (session cookie + CSRF) rather than at service level.
 *
 * Covers what the panel itself is responsible for:
 *
 *   * the queue page's inline script parses (bulk actions were dead because
 *     of a raw newline in a string), bulk checkboxes only on submissions
 *     waiting for a decision, search and export keeping the tab and kind;
 *   * drafts (never submitted) show status only: no identity details, no
 *     file names, no document links — and a link minted by hand opens nothing;
 *   * a kyc.view-only reviewer never sees the applicant's file names or links;
 *   * the preview keeps the panel's CSP (frame-ancestors 'self' only) and is
 *     inline only for image/PDF types;
 *   * approving a profile with an Aadhaar copy needs the "copy is masked"
 *     confirmation; bulk approval never gives it, so it skips those profiles;
 *   * identifier conflicts are shown, and an identifier can be released
 *     (with a reason) from the profile that should not hold it;
 *   * requirement edits are audit-logged field by field;
 *   * the legacy queue lists only legacy documents, behind kyc.view;
 *   * the user screen's KYC card: kyc.view only, current colours, real link;
 *   * Settings → KYC: auto-approve and badge-requires-KYC are fixed by policy.
 *
 * Everything it writes belongs to `it-kycp-*` accounts and three probe admins,
 * and is removed at the end.
 */

use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Helpers\KycIdentifiers;
use Zuste\Services\DocumentVault;
use Zuste\Services\KycService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// -----------------------------------------------------------------
// Admin panel client (cookie jar per admin, CSRF from the page)
// -----------------------------------------------------------------

$akpBase = (string) preg_replace('#/api/v\d+/?$#', '', Http::baseUrl());

/** @return array{status:int,body:string,headers:array<string,string>} */
$akpRequest = static function (string $jar, string $method, string $path, array $fields = [], bool $json = false) use ($akpBase): array {
    $handle          = curl_init(str_starts_with($path, 'http') ? $path : $akpBase . $path);
    $responseHeaders = [];

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'zusty-it-admin-kyc/1.0',
        CURLOPT_HTTPHEADER     => $json ? ['Accept: application/json'] : [],
        CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $body   = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    unset($handle);

    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $responseHeaders];
};

$akpCsrf = static function (string $html): string {
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    return preg_match('/name="csrf-token"\s+content="([^"]+)"/', $html, $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES)
        : '';
};

/** @return array<string,mixed> */
$akpJson = static function (array $response): array {
    $decoded = json_decode($response['body'], true);

    return is_array($decoded) ? $decoded : [];
};

$akpDescribe = static fn (array $response): string => 'HTTP ' . $response['status'] . ' — '
    . mb_substr(trim(strip_tags($response['body'])), 0, 300);

/**
 * Inline <script> blocks that do not parse, as "n: message" lines. Null when
 * node is not installed (the check is then reported as skipped, not passed).
 *
 * @return list<string>|null
 */
$akpScriptErrors = static function (string $html): ?array {
    exec('node --version 2>&1', $version, $code);

    if ($code !== 0) {
        return null;
    }

    preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $blocks, PREG_SET_ORDER);

    $errors = [];

    foreach ($blocks as $i => [$_, $attributes, $source]) {
        if (stripos($attributes, 'src=') !== false || trim($source) === ''
            || (preg_match('/type\s*=\s*"([^"]+)"/i', $attributes, $type) === 1
                && !in_array(strtolower($type[1]), ['text/javascript', 'application/javascript'], true))) {
            continue;
        }

        $file = sys_get_temp_dir() . '/it-kycp-script-' . $i . '-' . bin2hex(random_bytes(3)) . '.js';
        file_put_contents($file, $source);
        $output = [];
        exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        @unlink($file);

        if ($status !== 0) {
            $errors[] = $i . ': ' . implode(' ', array_slice($output, 0, 4));
        }
    }

    return $errors;
};

// -----------------------------------------------------------------
// Fixtures: accounts, profiles, documents, identifiers
// -----------------------------------------------------------------

$akpCreator = Actors::create('kycp-creator', 'creator');
$akpDraft   = Actors::create('kycp-draft', 'creator');
$akpBrand   = Actors::create('kycp-brand', 'brand');
$akpRival   = Actors::create('kycp-rival', 'brand');

$akpUserId = static function (string $actor): int {
    $id = Actors::id($actor);

    return $id > 0 ? $id : (int) (Database::scalar(
        'SELECT `id` FROM `users` WHERE `email` = :email',
        ['email' => Actors::email($actor)]
    ) ?? 0);
};

$akpCreatorUser = $akpUserId('kycp-creator');
$akpDraftUser   = $akpUserId('kycp-draft');
$akpBrandUser   = $akpUserId('kycp-brand');
$akpRivalUser   = $akpUserId('kycp-rival');
$akpUsers       = array_values(array_filter([$akpCreatorUser, $akpDraftUser, $akpBrandUser, $akpRivalUser]));

/** Back to "never started verification" — strictly the it-kycp-* accounts. */
$akpReset = static function (int $userId): void {
    if ($userId <= 0) {
        return;
    }

    foreach (Database::select('SELECT `file_path` FROM `kyc_documents` WHERE `user_id` = :u', ['u' => $userId]) as $row) {
        DocumentVault::purge((string) ($row['file_path'] ?? ''));
    }

    // Details, documents and events cascade from kyc_profiles.
    Database::execute('DELETE FROM `kyc_documents` WHERE `user_id` = :u', ['u' => $userId]);
    Database::execute('DELETE FROM `kyc_profiles` WHERE `user_id` = :u', ['u' => $userId]);
    Database::execute(
        "UPDATE `users` SET `kyc_profile_id` = NULL, `kyc_status` = 'none', `has_blue_tick` = 0, `blue_tick_at` = NULL,
                `is_identity_verified` = 0, `is_business_verified` = 0, `kyc_expires_on` = NULL
          WHERE `id` = :u",
        ['u' => $userId]
    );
};

$akpDigits  = static fn (int $n): string => implode('', array_map(static fn (): string => (string) random_int(0, 9), range(1, $n)));
$akpLetters = static fn (int $n): string => implode('', array_map(static fn (): string => chr(random_int(65, 90)), range(1, $n)));

// Fresh, valid identifiers every run, so nothing collides with other suites.
$akpAadhaarBase = (string) random_int(2, 9) . $akpDigits(10);
$akpAadhaar     = $akpAadhaarBase . KycIdentifiers::verhoeffDigit($akpAadhaarBase);
$akpCreatorPan  = $akpLetters(3) . 'P' . $akpLetters(1) . $akpDigits(4) . $akpLetters(1);
$akpDraftPan    = $akpLetters(3) . 'P' . $akpLetters(1) . $akpDigits(4) . $akpLetters(1);
$akpBrandPan    = $akpLetters(3) . 'F' . $akpLetters(1) . $akpDigits(4) . $akpLetters(1);
$akpRivalPan    = $akpLetters(3) . 'F' . $akpLetters(1) . $akpDigits(4) . $akpLetters(1);
$akpGstBase     = '29' . $akpBrandPan . '1Z';
$akpGst         = $akpGstBase . KycIdentifiers::gstinCheckChar($akpGstBase);

$akpPng = sys_get_temp_dir() . '/it-kycp-proof.png';
$akpPdf = sys_get_temp_dir() . '/it-kycp-proof.pdf';
file_put_contents($akpPng, (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    true
));
file_put_contents(
    $akpPdf,
    "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
);

/** A profile in [$status], as the state machine would have left it. */
$akpProfile = static function (int $userId, string $kind, string $status): int {
    $profile = KycService::profileFor($userId, $kind);
    $fields  = ['status' => $status];

    if ($status !== KycService::DRAFT) {
        $fields += [
            'submitted_at'     => date('Y-m-d H:i:s'),
            'consent_at'       => date('Y-m-d H:i:s'),
            'consent_version'  => KycService::consentVersion(),
            'submission_count' => 1,
        ];
    }

    Database::update('kyc_profiles', $fields, ['id' => (int) $profile['id']]);

    return (int) $profile['id'];
};

/** One current, encrypted document in [$slot], under a revealing file name. */
$akpDocument = static function (int $profileId, int $userId, string $kind, string $slot, string $file, string $originalName): int {
    $requirement = KycService::requirement($kind, $slot);
    $stored      = DocumentVault::storeLocalFile($file, $userId);

    return (int) Database::insert('kyc_documents', [
        'user_id'       => $userId,
        'profile_id'    => $profileId,
        'document_type' => (string) $requirement['document_type'],
        'slot'          => $slot,
        'title'         => (string) $requirement['label'],
        'file_path'     => $stored['path'],
        'original_name' => $originalName,
        'mime_type'     => $stored['mime'],
        'file_size'     => $stored['size'],
        'checksum'      => $stored['checksum'],
        'is_encrypted'  => 1,
        'version'       => 1,
        'is_current'    => 1,
        'status'        => 'pending',
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
};

$akpReady = $akpCreator !== null && $akpDraft !== null && $akpBrand !== null && $akpRival !== null
    && count($akpUsers) === 4;

foreach ($akpUsers as $uid) {
    $akpReset($uid);
}

$akpCreatorProfile = $akpDraftProfile = $akpBrandProfile = $akpRivalProfile = 0;
$akpDocs           = [];

if ($akpReady) {
    // A complete creator submission with an Aadhaar copy.
    $akpCreatorProfile = $akpProfile($akpCreatorUser, 'creator', KycService::SUBMITTED);
    Database::insert('kyc_creator_details', [
        'profile_id'    => $akpCreatorProfile,
        'full_name'     => 'IT KYCP Creator',
        'date_of_birth' => '1994-04-04',
        'pan_number'    => Crypto::encrypt($akpCreatorPan),
        'pan_hash'      => Crypto::blindIndex('pan', $akpCreatorPan),
        'aadhaar_last4' => substr($akpAadhaar, -4),
        'aadhaar_hash'  => Crypto::blindIndex('aadhaar', $akpAadhaar),
    ]);
    $akpDocs['front'] = $akpDocument($akpCreatorProfile, $akpCreatorUser, 'creator', 'aadhaar_front', $akpPng, 'it-kycp aadhaar front ' . substr($akpAadhaar, 0, 4) . '.png');
    $akpDocs['back']  = $akpDocument($akpCreatorProfile, $akpCreatorUser, 'creator', 'aadhaar_back', $akpPng, 'it-kycp aadhaar back.png');
    $akpDocs['pan']   = $akpDocument($akpCreatorProfile, $akpCreatorUser, 'creator', 'pan_card', $akpPdf, 'it-kycp pan card.pdf');

    // A draft: uploaded and typed in, never submitted.
    $akpDraftProfile = $akpProfile($akpDraftUser, 'creator', KycService::DRAFT);
    Database::insert('kyc_creator_details', [
        'profile_id' => $akpDraftProfile,
        'full_name'  => 'IT KYCP Draft',
        'pan_number' => Crypto::encrypt($akpDraftPan),
        'pan_hash'   => Crypto::blindIndex('pan', $akpDraftPan),
    ]);
    $akpDocs['draft'] = $akpDocument($akpDraftProfile, $akpDraftUser, 'creator', 'aadhaar_front', $akpPng, 'it-kycp draft secret name.png');

    // Two brands claiming one GSTIN: the firm it belongs to (whose PAN it
    // embeds) and a rival that typed it in with a PAN of its own.
    foreach ([
        [$akpBrandUser, 'IT KYCP Brand LLP', 'brand', $akpBrandPan],
        [$akpRivalUser, 'IT KYCP Rival LLP', 'rival', $akpRivalPan],
    ] as [$uid, $company, $key, $pan]) {
        $pid = $akpProfile($uid, 'brand', KycService::SUBMITTED);
        Database::insert('kyc_brand_details', [
            'profile_id'         => $pid,
            'company_name'       => $company,
            'company_type'       => 'partnership',
            'pan_number'         => Crypto::encrypt($pan),
            'pan_hash'           => Crypto::blindIndex('pan', $pan),
            'gst_number'         => Crypto::encrypt($akpGst),
            'gst_hash'           => Crypto::blindIndex('gstin', $akpGst),
            'registered_address' => '1 Test Street, Bengaluru 560001',
        ]);
        $akpDocs[$key] = $akpDocument($pid, $uid, 'brand', 'gst_certificate', $akpPdf, 'it-kycp gst ' . $key . '.pdf');

        if ($key === 'brand') {
            $akpBrandProfile = $pid;
        } else {
            $akpRivalProfile = $pid;
        }
    }
}

// Probe admins: a full reviewer, a kyc.view-only reviewer, and a role with
// users.kyc + users.view but no KYC permission at all.
$akpPassword = 'ItKycPanel!' . bin2hex(random_bytes(4));
$akpAdmins   = [];

$akpPermissionId = static fn (string $slug): int => (int) (Database::scalar(
    'SELECT `id` FROM `permissions` WHERE `slug` = :s',
    ['s' => $slug]
) ?? 0);

$akpDropAdmin = static function (string $email): void {
    foreach (Database::column('SELECT `id` FROM `admins` WHERE `email` = :e', ['e' => $email]) as $id) {
        try {
            Database::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = :id', ['id' => (int) $id]);
            Database::execute('DELETE FROM `admin_permissions` WHERE `admin_id` = :id', ['id' => (int) $id]);
            Database::execute('DELETE FROM `admins` WHERE `id` = :id', ['id' => (int) $id]);
        } catch (\Throwable) {
            // Referenced from an audit row: retire it under another address.
            Database::update('admins', [
                'email'      => 'retired-' . (int) $id . '-' . bin2hex(random_bytes(3)) . '@zuste.test',
                'status'     => 'inactive',
                'deleted_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $id]);
        }
    }
};

$superRole   = (int) (Database::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1') ?? 0);
$supportRole = (int) (Database::scalar("SELECT `id` FROM `roles` WHERE `slug` = 'support'")
    ?? Database::scalar('SELECT `id` FROM `roles` ORDER BY `level` ASC LIMIT 1') ?? 0);

foreach ([
    'full'   => [$superRole, [], []],
    'viewer' => [$supportRole, ['kyc.view'], ['kyc.download', 'kyc.review', 'kyc.badge', 'kyc.settings', 'export.data']],
    'nokyc'  => [$supportRole, ['users.kyc', 'users.view'], ['kyc.view', 'kyc.download', 'kyc.review']],
] as $who => [$roleId, $allow, $deny]) {
    $email = 'it-kycp-' . $who . '@zuste.test';
    $akpDropAdmin($email);

    $adminId = (int) Database::insert('admins', [
        'name'                 => 'IT KYC ' . ucfirst($who),
        'email'                => $email,
        'password_hash'        => Crypto::hashPassword($akpPassword),
        'role_id'              => $roleId,
        'status'               => 'active',
        'must_change_password' => 0,
        'created_at'           => date('Y-m-d H:i:s'),
    ]);

    foreach ([['allow', $allow], ['deny', $deny]] as [$effect, $slugs]) {
        foreach ($slugs as $slug) {
            $permissionId = $akpPermissionId($slug);
            if ($permissionId > 0) {
                Database::insertIgnore('admin_permissions', ['admin_id' => $adminId, 'permission_id' => $permissionId, 'effect' => $effect]);
            }
        }
    }

    $jar = (string) tempnam(sys_get_temp_dir(), 'it-kycp-jar');
    $akpAdmins[$who] = ['id' => $adminId, 'email' => $email, 'jar' => $jar, 'csrf' => ''];
}

// By reference: each admin's CSRF token is only known after they sign in.
$akpGet = static function (string $who, string $path, bool $json = false) use (&$akpAdmins, $akpRequest): array {
    return $akpRequest($akpAdmins[$who]['jar'], 'GET', $path, [], $json);
};

$akpPost = static function (string $who, string $path, array $fields, bool $json = true) use (&$akpAdmins, $akpRequest): array {
    return $akpRequest($akpAdmins[$who]['jar'], 'POST', $path, $fields + ['_csrf' => $akpAdmins[$who]['csrf']], $json);
};

// -----------------------------------------------------------------
Suite::group('Admin KYC panel — sign-in');

$akpSignedIn = true;

foreach ($akpAdmins as $who => $admin) {
    $login = $akpRequest($admin['jar'], 'GET', '/admin/login');
    $post  = $akpRequest($admin['jar'], 'POST', '/admin/login', [
        '_csrf'    => $akpCsrf($login['body']),
        'email'    => $admin['email'],
        'password' => $akpPassword,
    ]);

    $ok = $post['status'] === 302 && !str_contains((string) ($post['headers']['location'] ?? ''), '/admin/login');
    Suite::check('the ' . $who . ' probe admin signs in', $ok, $akpDescribe($post) . ' → ' . ($post['headers']['location'] ?? ''));

    $landing = $akpRequest($admin['jar'], 'GET', '/admin/account/password');
    $akpAdmins[$who]['csrf'] = $akpCsrf($landing['body']);

    if ($akpAdmins[$who]['csrf'] === '') {
        $akpAdmins[$who]['csrf'] = $akpCsrf($akpRequest($admin['jar'], 'GET', '/admin')['body']);
    }

    $akpSignedIn = $akpSignedIn && $ok && $akpAdmins[$who]['csrf'] !== '';
}

if (!$akpReady || !$akpSignedIn) {
    Suite::skipGroup(
        'Admin KYC panel — panel checks',
        !$akpReady ? 'could not obtain the four throwaway accounts: ' . Actors::lastError() : 'a probe admin could not sign in'
    );
} else {
    $creatorRef = (string) Database::scalar('SELECT `reference` FROM `kyc_profiles` WHERE `id` = :id', ['id' => $akpCreatorProfile]);
    $brandRef   = (string) Database::scalar('SELECT `reference` FROM `kyc_profiles` WHERE `id` = :id', ['id' => $akpBrandProfile]);
    $rivalRef   = (string) Database::scalar('SELECT `reference` FROM `kyc_profiles` WHERE `id` = :id', ['id' => $akpRivalProfile]);
    $draftRef   = (string) Database::scalar('SELECT `reference` FROM `kyc_profiles` WHERE `id` = :id', ['id' => $akpDraftProfile]);

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — queue');

    $queue = $akpGet('full', '/admin/kyc?queue=all&per_page=200&q=it-kycp');
    Suite::check('the queue renders', $queue['status'] === 200, $akpDescribe($queue));

    $scriptErrors = $akpScriptErrors($queue['body']);
    if ($scriptErrors === null) {
        echo "  \033[33mnote\033[0m  node is not installed — inline script syntax not checked\n";
    } else {
        Suite::check('every inline script on the queue page parses (bulk actions work)', $scriptErrors === [], implode(' | ', $scriptErrors));
    }

    Suite::check(
        'the bulk confirmation has no raw line break inside a string',
        str_contains($queue['body'], "submission(s)?\\n'") && !str_contains($queue['body'], "submission(s)?\n"),
        'bulk script text changed'
    );

    Suite::check(
        'a submission waiting for a decision gets a bulk checkbox',
        str_contains($queue['body'], 'class="kyc-row" value="' . $akpCreatorProfile . '"')
            && str_contains($queue['body'], 'class="kyc-row" value="' . $akpBrandProfile . '"')
    );
    Suite::check(
        'a draft gets no bulk checkbox',
        str_contains($queue['body'], $draftRef) && !str_contains($queue['body'], 'class="kyc-row" value="' . $akpDraftProfile . '"')
    );

    $approvedTab = $akpGet('full', '/admin/kyc?queue=approved&tick=none');
    Suite::check(
        'the search box carries the current tab and filters',
        preg_match('#<form class="toolbar"[^>]*>(?:(?!</form>).)*name="queue" value="approved"(?:(?!</form>).)*name="tick" value="none"#s', $approvedTab['body']) === 1,
        'no hidden queue/tick input in the search form'
    );

    $brandsTab = $akpGet('full', '/admin/kyc/brands?queue=all');
    Suite::check(
        'Export from the Brands tab exports brands only',
        preg_match('#href="/admin/kyc/export\?[^"]*kind=brand[^"]*"#', $brandsTab['body']) === 1
            && preg_match('#href="/admin/kyc/export\?[^"]*queue=all#', $brandsTab['body']) === 1,
        'Export link does not carry kind/queue'
    );

    $export = $akpGet('full', '/admin/kyc/export?kind=brand&queue=all&q=it-kycp');
    Suite::check(
        'the brand export has the brand and not the creator',
        $export['status'] === 200 && str_contains($export['body'], Actors::email('kycp-brand'))
            && !str_contains($export['body'], Actors::email('kycp-creator')),
        $akpDescribe($export)
    );

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — drafts stay closed');

    $draftPage = $akpGet('full', '/admin/kyc/' . $akpDraftProfile);
    Suite::check('a draft opens', $draftPage['status'] === 200, $akpDescribe($draftPage));
    Suite::check('it says it has not been submitted', str_contains($draftPage['body'], 'Not submitted yet'));
    Suite::check('the applicant\'s file name is not shown', !str_contains($draftPage['body'], 'draft secret name'));
    Suite::check(
        'no identity number is shown, masked or not',
        !str_contains($draftPage['body'], $akpDraftPan) && !str_contains($draftPage['body'], 'XXXXXX' . substr($akpDraftPan, -4))
    );
    Suite::check('no document can be opened from it', !str_contains($draftPage['body'], 'KycDoc.preview('));
    Suite::equals(
        'opening a draft does not claim it for review',
        Database::scalar('SELECT `status` FROM `kyc_profiles` WHERE `id` = :id', ['id' => $akpDraftProfile]),
        'draft'
    );

    $draftLink = $akpGet('full', '/admin/kyc/documents/' . $akpDocs['draft'] . '/link', true);
    Suite::check('no link is minted for a draft\'s document', $draftLink['status'] === 403, $akpDescribe($draftLink));

    $handToken = DocumentVault::signToken($akpDocs['draft'], 'admin', $akpAdmins['full']['id']);
    $draftPreview = $akpGet('full', '/admin/kyc/documents/' . $akpDocs['draft'] . '/preview?token=' . rawurlencode($handToken));
    Suite::check('a hand-minted preview link opens nothing', $draftPreview['status'] === 403 && !str_contains($draftPreview['body'], 'PNG'), $akpDescribe($draftPreview));

    $draftDownload = $akpGet('full', '/admin/kyc/documents/' . $akpDocs['draft'] . '?token=' . rawurlencode($handToken));
    Suite::check('nor a hand-minted download link', $draftDownload['status'] === 403, $akpDescribe($draftDownload));

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — file names and links by permission');

    $viewerPage = $akpGet('viewer', '/admin/kyc/' . $akpCreatorProfile);
    Suite::check('a kyc.view-only reviewer can open a submission', $viewerPage['status'] === 200, $akpDescribe($viewerPage));
    Suite::check('they do not see the applicant\'s file names', !str_contains($viewerPage['body'], 'it-kycp aadhaar front'));
    $frontLabel = (string) KycService::requirement('creator', 'aadhaar_front')['label'];
    Suite::check('they see the document by its slot title', str_contains($viewerPage['body'], 'title="' . htmlspecialchars($frontLabel, ENT_QUOTES) . '"'), $frontLabel);
    Suite::check('they get no preview or download buttons', !str_contains($viewerPage['body'], 'KycDoc.preview('));
    Suite::check('they see the PAN masked', !str_contains($viewerPage['body'], $akpCreatorPan));
    Suite::equals(
        'a read-only look does not claim the submission',
        Database::scalar('SELECT `status` FROM `kyc_profiles` WHERE `id` = :id', ['id' => $akpCreatorProfile]),
        'submitted'
    );

    $viewerLink = $akpGet('viewer', '/admin/kyc/documents/' . $akpDocs['front'] . '/link', true);
    Suite::check('they cannot mint a document link', $viewerLink['status'] === 403, $akpDescribe($viewerLink));

    $fullPage = $akpGet('full', '/admin/kyc/' . $akpCreatorProfile);
    Suite::check('a reviewer with the download permission sees the file names', str_contains($fullPage['body'], 'it-kycp aadhaar front'));
    Suite::check('and the preview buttons', str_contains($fullPage['body'], 'KycDoc.preview(' . $akpDocs['front']));
    Suite::check('and is offered the Aadhaar masking confirmation', str_contains($fullPage['body'], 'name="aadhaar_masked"'));

    $showErrors = $akpScriptErrors($fullPage['body']);
    if ($showErrors !== null) {
        Suite::check('every inline script on the submission page parses', $showErrors === [], implode(' | ', $showErrors));
    }

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — document preview');

    $link = $akpJson($akpGet('full', '/admin/kyc/documents/' . $akpDocs['front'] . '/link', true));
    $previewUrl = (string) ($link['data']['preview_url'] ?? '');
    Suite::check('a fresh preview link is minted', str_starts_with($previewUrl, '/admin/kyc/documents/' . $akpDocs['front'] . '/preview?token='), json_encode($link));

    $image = $akpGet('full', $previewUrl);
    $csp   = (string) ($image['headers']['content-security-policy'] ?? '');
    Suite::check('the image preview is served inline', $image['status'] === 200
        && str_starts_with((string) ($image['headers']['content-disposition'] ?? ''), 'inline')
        && str_starts_with((string) ($image['headers']['content-type'] ?? ''), 'image/png'), $akpDescribe($image));
    Suite::check(
        'it keeps the panel\'s policy (default-src, script-src, form-action)',
        str_contains($csp, "default-src 'self'") && str_contains($csp, 'script-src') && str_contains($csp, "form-action 'self'"),
        $csp
    );
    Suite::check('only the panel may frame it', str_contains($csp, "frame-ancestors 'self'") && !str_contains($csp, "frame-ancestors 'none'"), $csp);
    Suite::check('an image preview is sandboxed', preg_match('/(^|;)\s*sandbox\s*(;|$)/', $csp) === 1, $csp);
    Suite::equals('X-Frame-Options allows the panel only', $image['headers']['x-frame-options'] ?? null, 'SAMEORIGIN');

    $pdfLink = $akpJson($akpGet('full', '/admin/kyc/documents/' . $akpDocs['pan'] . '/link', true));
    $pdf     = $akpGet('full', (string) ($pdfLink['data']['preview_url'] ?? ''));
    $pdfCsp  = (string) ($pdf['headers']['content-security-policy'] ?? '');
    Suite::check('a PDF preview is inline and not sandboxed (the PDF viewer refuses sandboxing)', $pdf['status'] === 200
        && str_starts_with((string) ($pdf['headers']['content-type'] ?? ''), 'application/pdf')
        && str_contains($pdfCsp, "frame-ancestors 'self'") && preg_match('/(^|;)\s*sandbox\s*(;|$)/', $pdfCsp) !== 1, $pdfCsp);

    // A stored type outside the allow-list is never rendered on the panel's origin.
    Database::update('kyc_documents', ['mime_type' => 'text/html'], ['id' => $akpDocs['back']]);
    try {
        $htmlLink = $akpJson($akpGet('full', '/admin/kyc/documents/' . $akpDocs['back'] . '/link', true));
        $html     = $akpGet('full', (string) ($htmlLink['data']['preview_url'] ?? ''));
        Suite::check(
            'any other stored type is sent as an opaque attachment',
            $html['status'] === 200
                && str_starts_with((string) ($html['headers']['content-disposition'] ?? ''), 'attachment')
                && str_starts_with((string) ($html['headers']['content-type'] ?? ''), 'application/octet-stream'),
            json_encode($html['headers'])
        );
    } finally {
        Database::update('kyc_documents', ['mime_type' => 'image/png'], ['id' => $akpDocs['back']]);
    }

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — identifier conflicts and release');

    $rivalPage = $akpGet('full', '/admin/kyc/' . $akpRivalProfile);
    Suite::check(
        'a number another account also claims is shown, with that verification',
        str_contains($rivalPage['body'], 'Also claimed by another account') && str_contains($rivalPage['body'], $brandRef),
        $akpDescribe($rivalPage)
    );
    Suite::check('the release form is offered', str_contains($rivalPage['body'], '/identifiers/release'));

    $releasePath = '/admin/kyc/' . $akpRivalProfile . '/identifiers/release';
    $gstHash     = static fn (): ?string => Database::scalar('SELECT `gst_hash` FROM `kyc_brand_details` WHERE `profile_id` = :p', ['p' => $akpRivalProfile]);

    $noReason = $akpPost('full', $releasePath, ['field' => 'gstin', 'reason' => '']);
    Suite::check('a release needs a reason', $noReason['status'] === 422 && $gstHash() !== null, $akpDescribe($noReason));

    $wrongKind = $akpPost('full', $releasePath, ['field' => 'aadhaar', 'reason' => 'IT wrong kind']);
    Suite::check('a brand has no Aadhaar to release', $wrongKind['status'] === 422, $akpDescribe($wrongKind));

    $viewerRelease = $akpPost('viewer', $releasePath, ['field' => 'gstin', 'reason' => 'IT viewer tries']);
    Suite::check('a kyc.view-only reviewer cannot release', $viewerRelease['status'] === 403 && $gstHash() !== null, $akpDescribe($viewerRelease));

    $release = $akpPost('full', $releasePath, ['field' => 'gstin', 'reason' => 'IT: GSTIN belongs to the other company']);
    Suite::check('the reviewer releases the GSTIN', $release['status'] === 200 && ($akpJson($release)['success'] ?? false) === true, $akpDescribe($release));

    $rivalRow = Database::selectOne('SELECT `gst_number`, `gst_hash` FROM `kyc_brand_details` WHERE `profile_id` = :p', ['p' => $akpRivalProfile]) ?? [];
    Suite::check('the number and its blind index are gone from that verification', ($rivalRow['gst_number'] ?? 'x') === null && ($rivalRow['gst_hash'] ?? 'x') === null, json_encode($rivalRow));
    Suite::check(
        'the release is in the verification\'s audit trail, with the reason',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM `kyc_events` WHERE `profile_id` = :p AND `action` = 'identifier_released'
               AND `actor_type` = 'admin' AND `actor_id` = :a AND `note` LIKE '%belongs to the other company%'",
            ['p' => $akpRivalProfile, 'a' => $akpAdmins['full']['id']]
        ) === 1
    );
    Suite::check(
        'and in the activity log, without the number',
        static function () use ($akpRivalProfile, $akpGst): bool {
            $row = Database::selectOne(
                "SELECT `properties`, `description` FROM `activity_logs`
                  WHERE `action` = 'kyc.identifier_released' AND `subject_id` = :p ORDER BY `id` DESC LIMIT 1",
                ['p' => $akpRivalProfile]
            );

            return $row !== null && str_contains((string) $row['properties'], 'gstin')
                && !str_contains((string) $row['properties'] . $row['description'], $akpGst);
        }
    );
    Suite::check(
        'the owner\'s verification no longer shows a GSTIN conflict',
        !str_contains($akpGet('full', '/admin/kyc/' . $akpBrandProfile)['body'], 'GSTIN:')
    );

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — bulk decisions');

    $bulk = $akpPost('full', '/admin/kyc/bulk', [
        'action' => 'approve',
        'ids'    => [$akpCreatorProfile, $akpBrandProfile, $akpRivalProfile, $akpDraftProfile],
    ]);
    $bulkMessage = (string) ($akpJson($bulk)['message'] ?? '');
    Suite::check('a bulk approval runs', $bulk['status'] === 200, $akpDescribe($bulk));

    $statusOf = static fn (int $id): string => (string) Database::scalar('SELECT `status` FROM `kyc_profiles` WHERE `id` = :id', ['id' => $id]);
    Suite::equals('a complete brand without an Aadhaar copy is approved', $statusOf($akpBrandProfile), 'approved');
    Suite::check(
        'a submission with an Aadhaar copy is skipped and named',
        in_array($statusOf($akpCreatorProfile), ['submitted', 'under_review'], true)
            && str_contains($bulkMessage, 'Aadhaar') && str_contains($bulkMessage, $creatorRef),
        $bulkMessage
    );
    Suite::check('an incomplete one is skipped as incomplete', $statusOf($akpRivalProfile) === 'submitted' && str_contains($bulkMessage, $rivalRef), $bulkMessage);
    Suite::equals('a draft is never decided in bulk', $statusOf($akpDraftProfile), 'draft');
    Suite::check(
        'the Aadhaar profiles are listed in the activity log',
        str_contains((string) Database::scalar(
            "SELECT `properties` FROM `activity_logs` WHERE `action` = 'kyc.bulk_approve' AND `actor_id` = :a ORDER BY `id` DESC LIMIT 1",
            ['a' => $akpAdmins['full']['id']]
        ), 'needs_aadhaar_check')
    );

    $again = $akpPost('full', '/admin/kyc/bulk', ['action' => 'reject', 'ids' => [$akpBrandProfile], 'reason' => 'IT should not apply']);
    Suite::check(
        'an approved profile cannot be re-decided in bulk',
        $again['status'] === 200 && $statusOf($akpBrandProfile) === 'approved',
        $akpDescribe($again)
    );

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — approval needs the Aadhaar confirmation');

    $reviewPath = '/admin/kyc/' . $akpCreatorProfile . '/review';

    $unconfirmed = $akpPost('full', $reviewPath, ['action' => 'approve', 'reason' => '']);
    Suite::check(
        'approving without confirming the copy is masked is refused',
        $unconfirmed['status'] === 422
            && str_contains((string) ($akpJson($unconfirmed)['message'] ?? ''), 'Confirm the Aadhaar copy is masked'),
        $akpDescribe($unconfirmed)
    );
    Suite::check('and the submission is still waiting', in_array($statusOf($akpCreatorProfile), ['submitted', 'under_review'], true));

    $viewerApprove = $akpPost('viewer', $reviewPath, ['action' => 'approve', 'aadhaar_masked' => '1']);
    Suite::check('a kyc.view-only reviewer cannot approve', $viewerApprove['status'] === 403, $akpDescribe($viewerApprove));

    $confirmed = $akpPost('full', $reviewPath, ['action' => 'approve', 'reason' => '', 'aadhaar_masked' => '1']);
    Suite::check('with the confirmation it is approved', $confirmed['status'] === 200 && $statusOf($akpCreatorProfile) === 'approved', $akpDescribe($confirmed));
    Suite::check(
        'the confirmation is recorded on the approval event',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM `kyc_events` WHERE `profile_id` = :p AND `action` IN ('approved', 'status_changed', 'approve')
               AND `to_status` = 'approved' AND `metadata` LIKE '%aadhaar_masked%'",
            ['p' => $akpCreatorProfile]
        ) >= 1
    );
    Suite::check(
        'and in the activity log',
        str_contains((string) Database::scalar(
            "SELECT `properties` FROM `activity_logs` WHERE `action` = 'kyc.approve' AND `subject_id` = :p ORDER BY `id` DESC LIMIT 1",
            ['p' => $akpCreatorProfile]
        ), '"aadhaar_masked":true')
    );

    $search = $akpGet('full', '/admin/kyc?queue=approved&q=' . rawurlencode($creatorRef));
    Suite::check('searching from the Approved tab searches approved verifications', str_contains($search['body'], 'href="/admin/kyc/' . $akpCreatorProfile . '"'));

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — user screen and legacy queue');

    $userPage = $akpGet('full', '/admin/users/' . $akpCreatorUser);
    Suite::check('the user screen links straight to the verification', str_contains($userPage['body'], 'href="/admin/kyc/' . $akpCreatorProfile . '"'), $akpDescribe($userPage));
    Suite::check('approved documents use the approved colour', preg_match('#chip chip--success">\s*Approved\s*<#', $userPage['body']) === 1);
    Suite::check('the stale ?status=all link is gone', !str_contains($userPage['body'], '/admin/kyc?status=all'));

    $noKycUser = $akpGet('nokyc', '/admin/users/' . $akpCreatorUser);
    Suite::check('a role without kyc.view does not get the KYC card', $noKycUser['status'] === 200 && !str_contains($noKycUser['body'], 'KYC submissions'), $akpDescribe($noKycUser));

    $legacy = $akpGet('full', '/admin/kyc-legacy?status=all&per_page=200');
    Suite::check('the legacy queue renders', $legacy['status'] === 200, $akpDescribe($legacy));
    Suite::check('it lists no document of the current system', !str_contains($legacy['body'], Actors::email('kycp-creator')) && !str_contains($legacy['body'], Actors::email('kycp-brand')));

    $legacyNoKyc = $akpGet('nokyc', '/admin/kyc-legacy?status=all');
    Suite::check('users.kyc alone no longer opens it', in_array($legacyNoKyc['status'], [302, 403], true) && !str_contains($legacyNoKyc['body'], 'Legacy KYC'), $akpDescribe($legacyNoKyc));

    $legacyViewer = $akpGet('viewer', '/admin/kyc-legacy?status=all');
    Suite::check('kyc.view opens it', $legacyViewer['status'] === 200, $akpDescribe($legacyViewer));

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — suspension is not a dead end');

    $suspend = $akpPost('full', $reviewPath, ['action' => 'suspend', 'reason' => 'IT: documents under investigation']);
    Suite::check('an approved verification can be suspended', $suspend['status'] === 200 && $statusOf($akpCreatorProfile) === 'suspended', $akpDescribe($suspend));

    $suspendedPage = $akpGet('full', '/admin/kyc/' . $akpCreatorProfile);
    Suite::check(
        'a suspended verification offers reinstate, request information and reject',
        str_contains($suspendedPage['body'], "KycDecide.go('reinstate')")
            && str_contains($suspendedPage['body'], "KycDecide.go('request_info')")
            && str_contains($suspendedPage['body'], "KycDecide.go('reject')")
    );

    $noNote = $akpPost('full', $reviewPath, ['action' => 'reinstate', 'reason' => '']);
    Suite::check('reinstating needs a reason', $statusOf($akpCreatorProfile) === 'suspended', $akpDescribe($noNote));

    $reinstate = $akpPost('full', $reviewPath, ['action' => 'reinstate', 'reason' => 'IT: investigation closed']);
    Suite::check('reinstating puts it back under review', $reinstate['status'] === 200 && $statusOf($akpCreatorProfile) === 'under_review', $akpDescribe($reinstate));

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — requirement changes are audited');

    $requirementRow = Database::selectOne("SELECT * FROM `kyc_requirements` WHERE `kind` = 'brand' AND `slot` = 'supporting'");

    if ($requirementRow === null) {
        Suite::skipGroup('Admin KYC panel — requirement changes are audited', 'no brand "supporting" requirement row');
    } else {
        $requirementId = (int) $requirementRow['id'];
        $oldSize       = (int) $requirementRow['max_size_mb'];
        $newSize       = $oldSize >= 100 ? $oldSize - 1 : $oldSize + 1;
        $form          = [
            'label'           => (string) $requirementRow['label'],
            'description'     => (string) ($requirementRow['description'] ?? ''),
            'allowed_formats' => (string) $requirementRow['allowed_formats'],
            'max_size_mb'     => $newSize,
            'sort_order'      => (int) $requirementRow['sort_order'],
        ];
        foreach (['is_required', 'allows_multiple', 'requires_expiry', 'is_active'] as $flag) {
            if ((int) $requirementRow[$flag] === 1) {
                $form[$flag] = '1';
            }
        }

        try {
            $save = $akpPost('full', '/admin/kyc/requirements/' . $requirementId, $form);
            Suite::check('a requirement edit saves', $save['status'] === 200, $akpDescribe($save));

            $log = Database::selectOne(
                "SELECT `properties`, `description` FROM `activity_logs`
                  WHERE `action` = 'kyc.requirement_updated' AND `subject_id` = :id ORDER BY `id` DESC LIMIT 1",
                ['id' => $requirementId]
            );
            $properties = json_decode((string) ($log['properties'] ?? ''), true);
            Suite::check(
                'the log records the old and the new value of what changed',
                is_array($properties)
                    && (string) ($properties['changes']['before']['max_size_mb'] ?? '') === (string) $oldSize
                    && (string) ($properties['changes']['after']['max_size_mb'] ?? '') === (string) $newSize
                    && ($properties['slot'] ?? null) === 'supporting'
                    && !isset($properties['changes']['after']['label']),
                (string) ($log['properties'] ?? 'no log row')
            );

            $logCount = static fn (): int => (int) Database::scalar(
                "SELECT COUNT(*) FROM `activity_logs` WHERE `action` = 'kyc.requirement_updated' AND `subject_id` = :id",
                ['id' => $requirementId]
            );
            $before    = $logCount();
            $unchanged = $akpPost('full', '/admin/kyc/requirements/' . $requirementId, $form);
            Suite::check('saving with nothing changed writes no audit entry', $unchanged['status'] === 200 && $logCount() === $before, $akpDescribe($unchanged));
        } finally {
            $restore = $requirementRow;
            unset($restore['id']);
            Database::update('kyc_requirements', $restore, ['id' => $requirementId]);
        }
    }

    // -----------------------------------------------------------------
    Suite::group('Admin KYC panel — settings fixed by policy');

    $kycSettings = Database::select("SELECT `setting_key`, `value`, `type`, `is_encrypted` FROM `settings` WHERE `group_key` = 'kyc'");
    $snapshot    = array_column($kycSettings, 'value', 'setting_key');

    $settingsPage = $akpGet('full', '/admin/settings?group=kyc');
    Suite::check(
        'auto-approve is shown switched off and disabled',
        preg_match('#<input type="checkbox" id="set_auto_approve"[^>]*\sdisabled#', $settingsPage['body']) === 1
            && preg_match('#<input type="checkbox" id="set_auto_approve"[^>]*\schecked#', $settingsPage['body']) !== 1,
        $akpDescribe($settingsPage)
    );
    Suite::check(
        'badge-requires-KYC is shown switched on and disabled',
        preg_match('#<input type="checkbox" id="set_badge_requires_kyc"[^>]*\schecked[^>]*\sdisabled#', $settingsPage['body']) === 1
    );
    Suite::check('the policy is explained', substr_count($settingsPage['body'], 'Fixed by policy') >= 2);

    // Post the group as it stands, except an attempt to switch auto-approve on
    // and badge-requires-KYC off.
    $payload = ['group' => 'kyc'];
    foreach ($kycSettings as $setting) {
        $key = (string) $setting['setting_key'];

        if ((int) $setting['is_encrypted'] === 1) {
            continue;
        }

        if ($setting['type'] === 'boolean') {
            if ((string) $setting['value'] === '1' && $key !== 'badge_requires_kyc') {
                $payload['settings[' . $key . ']'] = '1';
            }
            continue;
        }

        $payload['settings[' . $key . ']'] = (string) ($setting['value'] ?? '');
    }
    $payload['settings[auto_approve]'] = '1';

    try {
        $saved = $akpRequest($akpAdmins['full']['jar'], 'POST', '/admin/settings', $payload + ['_csrf' => $akpAdmins['full']['csrf']], true);
        Suite::check('the KYC settings save', $saved['status'] === 200, $akpDescribe($saved));

        $after = array_column(
            Database::select("SELECT `setting_key`, `value` FROM `settings` WHERE `group_key` = 'kyc'"),
            'value',
            'setting_key'
        );
        Suite::equals('auto-approve cannot be switched on', $after['auto_approve'] ?? null, '0');
        Suite::equals('badge-requires-KYC cannot be switched off', $after['badge_requires_kyc'] ?? null, '1');
        Suite::check(
            'every other KYC setting is left as it was',
            static function () use ($snapshot, $after): bool {
                foreach ($snapshot as $key => $value) {
                    if (in_array($key, ['auto_approve', 'badge_requires_kyc'], true)) {
                        continue;
                    }
                    if ((string) $value !== (string) ($after[$key] ?? '')) {
                        return false;
                    }
                }

                return true;
            }
        );
    } finally {
        foreach ($snapshot as $key => $value) {
            if (in_array($key, ['auto_approve', 'badge_requires_kyc'], true)) {
                continue;
            }
            Database::execute(
                "UPDATE `settings` SET `value` = :v WHERE `group_key` = 'kyc' AND `setting_key` = :k",
                ['v' => $value, 'k' => $key]
            );
        }
    }
}

// -----------------------------------------------------------------
// Cleanup — only the it-kycp-* accounts and the probe admins.
// -----------------------------------------------------------------

foreach ($akpUsers as $uid) {
    $akpReset($uid);
}

foreach ($akpAdmins as $admin) {
    $akpDropAdmin($admin['email']);
    @unlink($admin['jar']);
}

@unlink($akpPng);
@unlink($akpPdf);

Suite::check(
    'the suite left no KYC rows for its accounts',
    $akpUsers === [] || (int) Database::scalar(
        'SELECT COUNT(*) FROM `kyc_profiles` WHERE `user_id` IN (' . implode(',', array_map('intval', $akpUsers)) . ')'
    ) === 0
);

<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\ValidationException;
use Zuste\Services\DocumentVault;
use Zuste\Services\KycService;

/**
 * User-facing KYC and business verification.
 *
 * The `kind` of profile follows the account type: brands complete a business
 * profile, creators an identity profile. A user never chooses which one they
 * fill in, so there is no way to submit the wrong form.
 */
final class KycController extends BaseApiController
{
    /** GET /me/kyc — status, details, requirements, documents, timeline. */
    public function show(Request $request): never
    {
        $user = $this->user($request);

        Response::success(
            KycService::overview((int) $user['id'], $this->kindFor($user), $this->apiBase($request))
        );
    }

    /** PUT /me/kyc/details — save the brand or creator detail block. */
    public function saveDetails(Request $request): never
    {
        $user = $this->user($request);
        $kind = $this->kindFor($user);

        $data = $kind === KycService::KIND_BRAND
            ? $this->validateBrand($request)
            : $this->validateCreator($request);

        // Only persist keys the client actually sent, so a partial autosave
        // cannot blank out fields the user never touched.
        $submitted = array_intersect_key($data, $request->all());

        KycService::saveDetails((int) $user['id'], $kind, $submitted);

        Response::success(
            KycService::overview((int) $user['id'], $kind, $this->apiBase($request)),
            'Saved.'
        );
    }

    /**
     * POST /me/kyc/documents — upload one or more files into a requirement slot.
     *
     * Accepts `file` for a single upload or `files[]` for a drag-and-drop batch.
     *
     * `replace_document_id` uploads a new version of that exact document: the
     * old one is kept for the audit trail but stops being current. It is how a
     * rejected document is re-uploaded, and how a file in a multi-file slot is
     * replaced without stacking a duplicate next to it. `slot` may be omitted
     * then — it is taken from the document being replaced.
     */
    public function uploadDocument(Request $request): never
    {
        $user = $this->user($request);
        $kind = $this->kindFor($user);

        $data = $this->validate($request, [
            'slot'                => 'nullable|string|max:60',
            'expires_on'          => 'nullable|date',
            'replace_document_id' => 'nullable|integer|min_value:1',
        ]);

        $replaceId = isset($data['replace_document_id']) ? (int) $data['replace_document_id'] : null;
        $slot      = trim((string) ($data['slot'] ?? ''));

        if ($slot === '' && $replaceId !== null) {
            $slot = (string) (Database::scalar(
                'SELECT `slot` FROM `kyc_documents`
                  WHERE `id` = :id AND `user_id` = :user_id AND `deleted_at` IS NULL',
                ['id' => $replaceId, 'user_id' => (int) $user['id']]
            ) ?? '');

            if ($slot === '') {
                throw HttpException::notFound('The document you are replacing was not found.');
            }
        }

        if ($slot === '') {
            throw ValidationException::forField('slot', 'Choose which document this is.');
        }

        $files = $this->collectFiles($request);

        if ($files === []) {
            throw HttpException::unprocessable('Attach at least one file.');
        }

        $requirement = KycService::requirement($kind, $slot);

        if (((int) $requirement['allows_multiple'] === 0 || $replaceId !== null) && count($files) > 1) {
            throw HttpException::unprocessable(
                $replaceId !== null
                    ? 'A replacement is a single file.'
                    : (string) $requirement['label'] . ' accepts a single file.'
            );
        }

        $uploaded = [];
        $apiBase  = $this->apiBase($request);

        foreach ($files as $file) {
            $document = KycService::attachDocument(
                (int) $user['id'],
                $kind,
                $slot,
                $file,
                $data['expires_on'] ?? null,
                $request->ip(),
                $replaceId
            );

            $uploaded[] = KycService::presentUserDocument(
                Database::selectOne(
                    'SELECT * FROM `kyc_documents` WHERE `id` = :id',
                    ['id' => (int) $document['id']]
                ) ?? [],
                (int) $user['id'],
                $apiBase
            );
        }

        Response::success(
            [
                'documents' => $uploaded,
                'overview'  => KycService::overview((int) $user['id'], $kind, $apiBase),
            ],
            $replaceId !== null
                ? 'Document replaced.'
                : (count($uploaded) === 1 ? 'Document uploaded.' : count($uploaded) . ' documents uploaded.'),
            201
        );
    }

    /** DELETE /me/kyc/documents/{id} */
    public function deleteDocument(Request $request): never
    {
        $user = $this->user($request);

        KycService::removeDocument((int) $user['id'], $request->routeParamInt('id'));

        Response::success(
            KycService::overview((int) $user['id'], $this->kindFor($user), $this->apiBase($request)),
            'Document removed.'
        );
    }

    /**
     * GET /me/kyc/documents/{id}/download — stream a document back to its owner.
     *
     * Guarded by a signed, short-lived token so the URL cannot be shared.
     */
    public function downloadDocument(Request $request): never
    {
        [$user, $document] = $this->ownedDocumentFromToken($request);

        $file = DocumentVault::read($document);

        KycService::recordEvent((int) $document['profile_id'], 'document_downloaded', [
            'actor_type' => 'user',
            'actor_id'   => (int) $user['id'],
            'note'       => 'Owner downloaded ' . ($document['title'] ?? 'a document') . '.',
            'ip'         => $request->ip(),
            'agent'      => $request->userAgent(),
        ]);

        Response::download($file['contents'], $file['name'], $file['mime']);
    }

    /**
     * GET /me/kyc/documents/{id}/preview — the same document, for display.
     *
     * Same token rules as the download (minted for this document AND this
     * account, short-lived), but served `inline` so the app can show an image
     * or hand a PDF to a viewer, and never cached.
     */
    public function previewDocument(Request $request): never
    {
        [$user, $document] = $this->ownedDocumentFromToken($request);

        $file = DocumentVault::read($document);

        KycService::recordEvent((int) $document['profile_id'], 'document_previewed', [
            'actor_type' => 'user',
            'actor_id'   => (int) $user['id'],
            'note'       => 'Owner viewed ' . ($document['title'] ?? 'a document') . '.',
            'ip'         => $request->ip(),
            'agent'      => $request->userAgent(),
        ]);

        Response::inline($file['contents'], $file['name'], $file['mime']);
    }

    /**
     * Resolve `?token=` to a live document owned by the caller.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function ownedDocumentFromToken(Request $request): array
    {
        $user  = $this->user($request);
        $token = $request->string('token');

        if ($token === '') {
            throw HttpException::forbidden('A download token is required.');
        }

        $claims     = DocumentVault::verifyToken($token);
        $documentId = $request->routeParamInt('id');

        // The token must have been minted for this document *and* this viewer.
        if ($claims['document_id'] !== $documentId
            || $claims['viewer_type'] !== 'user'
            || $claims['viewer_id'] !== (int) $user['id']) {
            throw HttpException::forbidden('That download link is not valid for this account.');
        }

        $document = Database::selectOne(
            'SELECT * FROM `kyc_documents`
              WHERE `id` = :id AND `user_id` = :user_id AND `deleted_at` IS NULL AND `purged_at` IS NULL',
            ['id' => $documentId, 'user_id' => (int) $user['id']]
        );

        if ($document === null) {
            throw HttpException::notFound('That document was not found.');
        }

        return [$user, $document];
    }

    /**
     * POST /me/kyc/submit — hand the profile to the review queue.
     *
     * Body: `consent: true` — the applicant's agreement to their documents
     * being processed, recorded with the current consent wording's version.
     */
    public function submit(Request $request): never
    {
        $user = $this->user($request);
        $kind = $this->kindFor($user);

        KycService::submit(
            (int) $user['id'],
            $kind,
            $request->ip(),
            $request->userAgent(),
            $request->bool('consent')
        );

        Response::success(
            KycService::overview((int) $user['id'], $kind, $this->apiBase($request)),
            'Submitted for review.'
        );
    }

    /** GET /me/kyc/timeline */
    public function timeline(Request $request): never
    {
        $user    = $this->user($request);
        $profile = KycService::profileFor((int) $user['id'], $this->kindFor($user));

        Response::success([
            'timeline' => KycService::timeline((int) $profile['id']),
        ]);
    }

    /**
     * GET /kyc/requirements — the public rulebook.
     *
     * Unauthenticated so the marketing site and onboarding screens can tell a
     * prospective user what they will need before they sign up.
     */
    public function requirements(Request $request): never
    {
        $kind = $request->string('kind', KycService::KIND_BRAND);
        $kind = $kind === KycService::KIND_CREATOR
            ? KycService::KIND_CREATOR
            : KycService::KIND_BRAND;

        $rows = array_map(
            static fn (array $row): array => [
                'slot'            => $row['slot'],
                'label'           => $row['label'],
                'description'     => $row['description'],
                'is_required'     => (bool) $row['is_required'],
                'allows_multiple' => (bool) $row['allows_multiple'],
                'requires_expiry' => (bool) $row['requires_expiry'],
                'allowed_formats' => explode(',', (string) $row['allowed_formats']),
                'max_size_mb'     => (int) $row['max_size_mb'],
            ],
            KycService::requirements($kind)
        );

        Response::success([
            'kind'         => $kind,
            'requirements' => $rows,
            'policy'       => [
                'mandatory' => $kind === KycService::KIND_BRAND
                    ? Config::settingBool('kyc', 'brand_mandatory', true)
                    : Config::settingBool('kyc', 'creator_mandatory', true),
                'max_upload_mb'   => Config::settingInt('kyc', 'max_upload_mb', 10),
                'allowed_formats' => explode(
                    ',',
                    Config::settingString('kyc', 'allowed_formats', 'pdf,jpg,jpeg,png')
                ),
                'expiry_months'   => Config::settingInt('kyc', 'expiry_months', 24),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * The absolute API root of this request, e.g. `https://host/api/v1`, for
     * the `preview_url` the app fetches as-is.
     *
     * Derived from the request rather than configuration because the app
     * reaches the API under different hosts (an emulator talks to 10.0.2.2,
     * a device to the LAN address): the right root is the one it just used.
     */
    private function apiBase(Request $request): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        $pos  = strpos($path, '/me/kyc');

        $prefix = $pos === false ? '/api/v1' : substr($path, 0, $pos);
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($host === '' || preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
            return $prefix;
        }

        return ($request->isSecure() ? 'https' : 'http') . '://' . $host . $prefix;
    }

    /** @param array<string,mixed> $user */
    private function kindFor(array $user): string
    {
        return ($user['account_type'] ?? 'brand') === 'creator'
            ? KycService::KIND_CREATOR
            : KycService::KIND_BRAND;
    }

    /**
     * Normalise `file`, `files[]`, and `documents[]` into a flat list of
     * single-file arrays, so callers do not have to care which shape the
     * client used.
     *
     * @return list<array<string,mixed>>
     */
    private function collectFiles(Request $request): array
    {
        $collected = [];

        foreach (['file', 'files', 'documents'] as $key) {
            if (!isset($_FILES[$key])) {
                continue;
            }

            $entry = $_FILES[$key];

            // Single upload.
            if (!is_array($entry['name'])) {
                $collected[] = $entry;
                continue;
            }

            // Multi upload: PHP transposes the arrays, so flip them back.
            $count = count($entry['name']);
            for ($i = 0; $i < $count; $i++) {
                if ((int) $entry['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $collected[] = [
                    'name'     => $entry['name'][$i],
                    'type'     => $entry['type'][$i],
                    'tmp_name' => $entry['tmp_name'][$i],
                    'error'    => $entry['error'][$i],
                    'size'     => $entry['size'][$i],
                ];
            }
        }

        // A batch that would exceed any sane review workload is refused early.
        if (count($collected) > 20) {
            throw HttpException::unprocessable('Upload at most 20 files at a time.');
        }

        return $collected;
    }

    /** @return array<string,mixed> */
    private function validateBrand(Request $request): array
    {
        return $this->validate($request, [
            'company_name'        => 'nullable|string|min:2|max:191',
            'legal_name'          => 'nullable|string|max:191',
            'logo_url'            => 'nullable|string|max:500',
            'cover_url'           => 'nullable|string|max:500',
            'description'         => 'nullable|string|max:5000',
            'industry'            => 'nullable|string|max:120',
            'website'             => 'nullable|url|max:255',
            'business_email'      => 'nullable|email|max:191',
            'business_phone'      => 'nullable|string|max:32',
            // Format, checksum and uniqueness are checked in
            // KycService::saveDetails, where the errors stay field-keyed.
            'gst_number'          => 'nullable|string|max:25',
            'pan_number'          => 'nullable|string|max:20',
            'cin_number'          => 'nullable|string|max:30',
            'company_type'        => 'nullable|in:sole_proprietorship,partnership,llp,private_limited,public_limited,opc,trust,society,ngo,other',
            'year_established'    => 'nullable|integer|min_value:1800|max_value:2100',
            'company_size'        => 'nullable|in:1-10,11-50,51-200,201-500,500+',
            'registered_address'  => 'nullable|string|max:500',
            'office_address'      => 'nullable|string|max:500',
            'country'             => 'nullable|string|max:100',
            'state'               => 'nullable|string|max:100',
            'city'                => 'nullable|string|max:100',
            'pin_code'            => 'nullable|string|max:12',
            'map_latitude'        => 'nullable|numeric|min_value:-90|max_value:90',
            'map_longitude'       => 'nullable|numeric|min_value:-180|max_value:180',
            'map_place_url'       => 'nullable|string|max:500',
            'social_links'        => 'nullable|array',
            'contact_name'        => 'nullable|string|max:150',
            'contact_designation' => 'nullable|string|max:120',
            'contact_email'       => 'nullable|email|max:191',
            'contact_phone'       => 'nullable|string|max:32',
            'alt_contact_name'    => 'nullable|string|max:150',
            'alt_contact_email'   => 'nullable|email|max:191',
            'alt_contact_phone'   => 'nullable|string|max:32',
        ]);
    }

    /** @return array<string,mixed> */
    private function validateCreator(Request $request): array
    {
        return $this->validate($request, [
            'full_name'         => 'nullable|string|min:2|max:150',
            'display_name'      => 'nullable|string|max:120',
            'photo_url'         => 'nullable|string|max:500',
            'cover_url'         => 'nullable|string|max:500',
            'bio'               => 'nullable|string|max:2000',
            'gender'            => 'nullable|in:female,male,non_binary,prefer_not_to_say',
            'date_of_birth'     => 'nullable|date',
            'address'           => 'nullable|string|max:500',
            'country'           => 'nullable|string|max:100',
            'state'             => 'nullable|string|max:100',
            'city'              => 'nullable|string|max:100',
            'pin_code'          => 'nullable|string|max:12',
            'languages'         => 'nullable|array',
            'categories'        => 'nullable|array',
            'social_links'      => 'nullable|array',
            'portfolio_url'     => 'nullable|url|max:255',
            'audience_stats'    => 'nullable|array',
            'bank_account_name' => 'nullable|string|max:150',
            'bank_account_no'   => 'nullable|string|max:20',
            'bank_ifsc'         => 'nullable|string|max:15',
            'bank_name'         => 'nullable|string|max:150',
            'upi_id'            => 'nullable|string|max:100',
            'pan_number'        => 'nullable|string|max:20',
            // Validated, reduced to its last four digits and hashed by
            // KycService::saveDetails — the full number is never stored.
            'aadhaar_number'    => 'nullable|string|max:20',
            'id_proof_type'     => 'nullable|in:aadhaar,passport,driving_license,voter_id',
            'id_proof_number'   => 'nullable|string|max:20',
        ]);
    }
}

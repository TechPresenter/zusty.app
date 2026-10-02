<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\ValidationException;
use Zuste\Helpers\Str;
use Zuste\Services\ActivityLogger;
use Zuste\Services\DocumentVault;
use Zuste\Services\KycService;

/**
 * KYC management for the admin panel.
 *
 * Queues for brands and creators, a full profile view with every uploaded
 * document (and every earlier version of it), per-document and overall review
 * decisions, re-upload requests, the separate blue-tick decision, internal
 * notes, bulk actions, and export.
 *
 * Every read of a decrypted identity number and every document preview or
 * download is written to the activity log — reviewers handle the most
 * sensitive data on the platform, so who looked at what is itself auditable.
 * Every decision also lands in the profile's own append-only trail
 * (`kyc_events`) with the admin's name, IP address and user agent.
 */
final class KycController extends BaseAdminController
{
    /** Queues that map onto one or more profile statuses. */
    private const QUEUES = [
        'pending'  => [KycService::SUBMITTED, KycService::UNDER_REVIEW],
        'info'     => [KycService::INFO_REQUIRED],
        'approved' => [KycService::APPROVED],
        // Approved KYC whose blue tick has not been decided (or was withdrawn).
        'awaiting_tick' => [KycService::APPROVED],
        'rejected' => [KycService::REJECTED, KycService::SUSPENDED],
        'expired'  => [KycService::EXPIRED],
        // Listed so an operator can see a verification exists and how far it
        // got. A draft has never been submitted, so the applicant has not yet
        // asked anyone to look at it: its documents and identity numbers stay
        // closed (see show() and openableDocument()).
        'draft'    => [KycService::DRAFT],
    ];

    /** Statuses a bulk decision may act on: submissions waiting for one. */
    private const BULK_STATUSES = [KycService::SUBMITTED, KycService::UNDER_REVIEW];

    /**
     * The only types the in-page viewer shows, and so the only types the
     * preview route serves `inline`. Anything else goes out as an
     * `application/octet-stream` attachment and is never rendered by the
     * browser on the panel's origin.
     */
    private const INLINE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /**
     * Identifiers that are unique across accounts, by the name the release
     * action takes: the detail columns that hold each one, per kind, and every
     * table/hash column another account's claim on it can sit in.
     */
    private const IDENTIFIERS = [
        'aadhaar' => [
            'label'   => 'Aadhaar',
            'columns' => [KycService::KIND_CREATOR => ['aadhaar_last4', 'aadhaar_hash']],
            'holders' => [['kyc_creator_details', 'aadhaar_hash']],
        ],
        'pan' => [
            'label'   => 'PAN',
            'columns' => [
                KycService::KIND_CREATOR => ['pan_number', 'pan_hash'],
                KycService::KIND_BRAND   => ['pan_number', 'pan_hash'],
            ],
            'holders' => [['kyc_creator_details', 'pan_hash'], ['kyc_brand_details', 'pan_hash']],
        ],
        'gstin' => [
            'label'   => 'GSTIN',
            'columns' => [KycService::KIND_BRAND => ['gst_number', 'gst_hash']],
            'holders' => [['kyc_brand_details', 'gst_hash']],
        ],
        'cin' => [
            'label'   => 'CIN',
            'columns' => [KycService::KIND_BRAND => ['cin_number', 'cin_hash']],
            'holders' => [['kyc_brand_details', 'cin_hash']],
        ],
    ];

    // -----------------------------------------------------------------
    // Lists
    // -----------------------------------------------------------------

    /**
     * GET /admin/kyc — combined queue.
     *
     * [$forceKind] lets the dedicated brand and creator routes reuse this
     * without the caller being able to override it through the query string.
     */
    public function index(Request $request, ?string $forceKind = null): never
    {
        $this->authorize($request, 'kyc.view');

        $params = $this->listParams(
            $request,
            ['id', 'submitted_at', 'reviewed_at', 'completeness', 'status'],
            'submitted_at'
        );

        $kind  = $forceKind ?? $this->kindFilter($request);
        $queue = $this->queueFilter($request, 'pending');

        [$where, $bindings] = $this->buildFilters($request, $kind, $queue, $params['search']);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `kyc_profiles` p
             INNER JOIN `users` u ON u.`id` = p.`user_id`' . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT p.*, u.`name` AS `user_name`, u.`email`, u.`phone`, u.`avatar_url`,
                    u.`account_type`, u.`has_blue_tick`, a.`name` AS `reviewer_name`,
                    (SELECT COUNT(*) FROM `kyc_documents` d
                      WHERE d.`profile_id` = p.`id` AND d.`is_current` = 1 AND d.`deleted_at` IS NULL
                        AND d.`purged_at` IS NULL)
                      AS `document_count`
             FROM `kyc_profiles` p
             INNER JOIN `users` u ON u.`id` = p.`user_id`
             LEFT JOIN `admins` a ON a.`id` = p.`reviewed_by`' . $where . '
             ORDER BY p.`' . $params['sort_by'] . '` ' . $params['sort_dir'] . '
             LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        foreach ($rows as &$row) {
            $row['status_label'] = KycService::statusLabel((string) $row['status']);
            $row['status_tone']  = KycService::statusTone((string) $row['status']);
        }
        unset($row);

        $this->view($request, 'admin.kyc.index', [
            'pageTitle'    => 'KYC verification',
            'pageSubtitle' => number_format($total) . ' ' . ($kind ?? 'total') . ' submissions',
            'profiles'     => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
            'kind'         => $kind,
            'queue'        => $queue,
            'counts'       => $this->queueCounts($kind),
        ]);
    }

    /** GET /admin/kyc/brands */
    public function brands(Request $request): never
    {
        $this->index($request, KycService::KIND_BRAND);
    }

    /** GET /admin/kyc/creators */
    public function creators(Request $request): never
    {
        $this->index($request, KycService::KIND_CREATOR);
    }

    // -----------------------------------------------------------------
    // Detail
    // -----------------------------------------------------------------

    /** GET /admin/kyc/{id} — the full submission. */
    public function show(Request $request): never
    {
        $this->authorize($request, 'kyc.view');

        $profileId = $request->routeParamInt('id');
        $profile   = KycService::find($profileId);

        $user = Database::selectOne(
            'SELECT `id`, `name`, `email`, `phone`, `avatar_url`, `account_type`,
                    `status`, `kyc_status`, `has_blue_tick`, `blue_tick_at`, `created_at`, `deleted_at`
               FROM `users` WHERE `id` = :id',
            ['id' => (int) $profile['user_id']]
        );

        // A deleted account's verification is kept as a record and stays
        // reachable here for audit, but it is out of every queue and nothing
        // can be decided on it (see assertAccountLive()).
        $accountDeleted = $user === null || $user['deleted_at'] !== null;

        // A draft has never been submitted: the applicant is still filling it
        // in and has not asked anybody to review it (consent is given at
        // submission). There is nothing to decide, so a reviewer gets its
        // status and checklist only — no identity details, no file names and
        // no links to the documents.
        $draftLocked = self::isUnsubmittedDraft($profile);

        // Reviewers who may pull the underlying documents need the real numbers
        // to check them against those documents; everybody else sees the same
        // masked values the applicant's own app shows. kyc.view on its own is
        // the "look at the queue" permission, not a licence to read raw PAN,
        // GSTIN, CIN, bank account, IFSC and UPI numbers. An Aadhaar is masked
        // for everyone: only its last four digits exist.
        $canDownload = !$draftLocked && $this->can($request, 'kyc.download');
        $unmasked    = $canDownload;
        $details     = $draftLocked
            ? []
            : KycService::details($profileId, (string) $profile['kind'], $unmasked);

        $this->log(
            $request,
            'kyc.viewed',
            'kyc_profile',
            $profileId,
            'Opened verification ' . $profile['reference']
                . match (true) {
                    $draftLocked => ' (draft: status only).',
                    $unmasked    => ' (identity numbers shown in full).',
                    default      => ' (identity numbers masked).',
                },
            ['unmasked' => $unmasked, 'draft' => $draftLocked]
        );

        // Opening a submitted profile claims it for review — but only for an
        // admin who can actually decide it. A read-only auditor must be able to
        // look without moving the workflow or notifying the applicant, and a
        // deleted account's submission is never claimed (it would move the
        // record and send "under review" to an account that no longer exists).
        if ($this->can($request, 'kyc.review') && !$accountDeleted) {
            KycService::startReview($profileId, $this->adminId($request), $this->auditContext($request));
        }

        $profile      = KycService::find($profileId);
        $requirements = KycService::effectiveRequirements($profile);
        $adminId      = $this->adminId($request);

        // Every version of every document, presented once; links are only
        // minted for an admin who is allowed to follow them. The applicant's
        // own file name is shown only to the same admins: names of ID scans
        // routinely carry the holder's name or the ID number itself, which the
        // masking of the numbers above would otherwise be undone by.
        $history = array_map(
            fn (array $row): array => $this->presentForAdmin($row, $canDownload ? $adminId : null, $canDownload),
            KycService::documentHistory($profileId)
        );

        $slots    = [];
        $resubmit = KycService::resubmitSlots($profile);

        foreach ($requirements as $requirement) {
            $slots[(string) $requirement['slot']] = [
                'requirement'        => $requirement,
                'current'            => [],
                'versions'           => [],
                'resubmit_requested' => in_array((string) $requirement['slot'], $resubmit, true),
            ];
        }

        $otherDocuments = [];

        foreach ($history as $document) {
            $slot = (string) $document['slot'];

            if (!isset($slots[$slot])) {
                $otherDocuments[] = $document;
                continue;
            }

            $live = $document['is_current'] && !$document['is_purged'];

            $slots[$slot][$live ? 'current' : 'versions'][] = $document;
        }

        foreach ($slots as $slot => &$entry) {
            $statuses = array_column($entry['current'], 'status');

            $entry['review_status'] = match (true) {
                $entry['current'] === []                  => 'missing',
                in_array('rejected', $statuses, true)     => 'rejected',
                array_unique($statuses) === ['approved']  => 'approved',
                default                                   => 'pending',
            };
        }
        unset($entry);

        $badgeGrantedBy = $profile['badge_granted_by'] === null
            ? null
            : Database::scalar('SELECT `name` FROM `admins` WHERE `id` = :id', ['id' => (int) $profile['badge_granted_by']]);

        // The blue tick always needs a KYC that is approved and still in date
        // (kyc.badge_requires_kyc is fixed on by policy, whatever the row
        // says). The service refuses anything else; this only decides whether
        // the button is offered.
        $approvedAndCurrent = $profile['status'] === KycService::APPROVED
            && $profile['purged_at'] === null
            && ($profile['expires_on'] === null || (string) $profile['expires_on'] >= date('Y-m-d'));

        // Identifiers this verification holds, and any other live verification
        // that claims the same number — what a reviewer needs to settle a
        // "this GSTIN is already linked to another account" complaint.
        $canReview  = $this->can($request, 'kyc.review');
        $held       = $draftLocked ? [] : self::heldIdentifiers($profile);
        $releasable = [];

        foreach (array_keys($held) as $field) {
            $releasable[$field] = (string) self::IDENTIFIERS[$field]['label'];
        }

        $this->view($request, 'admin.kyc.show', [
            'pageTitle'      => 'Verification ' . $profile['reference'],
            'pageSubtitle'   => ($user['name'] ?? 'Unknown user') . ' · ' . ucfirst((string) $profile['kind']),
            'profile'        => $profile,
            'presented'      => KycService::presentProfile($profile),
            'user'           => $user ?? [],
            'details'        => $details,
            'unmasked'       => $unmasked,
            'draftLocked'    => $draftLocked,
            'accountDeleted' => $accountDeleted,
            'aadhaarOnFile'  => !$draftLocked && self::hasCurrentAadhaar($profileId),
            'releasable'     => $canReview ? $releasable : [],
            'conflicts'      => $canReview ? self::identifierConflicts($profile, $held) : [],
            'slots'          => $slots,
            'otherDocuments' => $otherDocuments,
            'documents'      => array_values(array_filter(
                $history,
                static fn (array $d): bool => $d['is_current'] && !$d['is_purged']
            )),
            'requirements'   => $requirements,
            'blockers'       => KycService::approvalBlockers($profileId),
            'timeline'       => KycService::timeline($profileId, 100, true),
            'notes'          => $this->notes($profileId),
            'badge'          => [
                'status'        => (string) ($profile['badge_status'] ?? KycService::BADGE_NONE),
                'granted_at'    => $profile['badge_granted_at'] ?? null,
                'granted_by'    => $badgeGrantedBy,
                'revoked_at'    => $profile['badge_revoked_at'] ?? null,
                'revoke_reason' => $profile['badge_revoke_reason'] ?? null,
                'visible'       => (int) ($user['has_blue_tick'] ?? 0) === 1,
                'can_grant'     => $approvedAndCurrent && !$accountDeleted,
            ],
            'linkTtl'        => max(30, Config::settingInt('kyc', 'download_link_ttl', 300)),
        ]);
    }

    // -----------------------------------------------------------------
    // Decisions
    // -----------------------------------------------------------------

    /** POST /admin/kyc/{id}/review */
    public function review(Request $request): never
    {
        $this->authorize($request, 'kyc.review');

        $data = $this->validate($request, [
            'action'         => 'required|in:approve,reject,request_info,suspend,reinstate',
            'reason'         => 'nullable|string|max:1000',
            'aadhaar_masked' => 'nullable|boolean',
        ]);

        $profileId = $request->routeParamInt('id');
        $profile   = KycService::find($profileId);
        $adminId   = $this->adminId($request);
        $reason    = trim((string) ($data['reason'] ?? ''));
        $context   = $this->auditContext($request);

        // Anything other than an approval owes the user an explanation.
        if ($data['action'] !== 'approve' && $reason === '') {
            $this->back('error', 'Give the user a reason so they can act on it.');
        }

        // What the reviewer confirmed by hand on the approve form. Only a
        // masked Aadhaar copy may be kept, and nothing can check that but the
        // person looking at the image, so the approval carries their word for
        // it: approve() refuses a profile holding an Aadhaar copy without it
        // ("Confirm the Aadhaar copy is masked before approving.") and records
        // it on the approval event.
        $attestations = ['aadhaar_masked' => $request->bool('aadhaar_masked')];

        try {
            self::assertAccountLive($profileId);

            match ($data['action']) {
                'approve'      => KycService::approve($profileId, $adminId, $reason, $attestations, $context),
                'reject'       => KycService::reject($profileId, $adminId, $reason, $context),
                'request_info' => KycService::requestInformation($profileId, $adminId, $reason, $context),
                'suspend'      => KycService::suspend($profileId, $adminId, $reason, $context),
                // Lifts a suspension back to "under review"; no tick returns.
                'reinstate'    => KycService::reinstate($profileId, $adminId, $reason, $context),
            };
        } catch (HttpException $e) {
            // An approval refused for missing documents (or an illegal move)
            // is a message for the reviewer, not an error page.
            $this->fail($request, $e);
        }

        $verb = match ($data['action']) {
            'approve'      => 'approved',
            'reject'       => 'rejected',
            'request_info' => 'returned for more information',
            'suspend'      => 'suspended',
            'reinstate'    => 'reinstated for review',
        };

        $this->log(
            $request,
            'kyc.' . $data['action'],
            'kyc_profile',
            $profileId,
            'Verification ' . $profile['reference'] . ' ' . $verb . '.',
            ['reason' => $reason]
                + ($data['action'] === 'approve' ? ['aadhaar_masked' => $attestations['aadhaar_masked']] : [])
        );

        $this->respond(
            $request,
            'Verification ' . $verb . '.'
                . ($data['action'] === 'approve' ? ' The blue tick is a separate decision below.' : '')
        );
    }

    /**
     * POST /admin/kyc/{id}/resubmission — send named documents back.
     *
     * Body: slots[] (requirement slots) and an optional message.
     */
    public function requestResubmission(Request $request): never
    {
        $this->authorize($request, 'kyc.review');

        $data = $this->validate($request, [
            'slots'   => 'required|array',
            'message' => 'nullable|string|max:800',
        ]);

        $profileId = $request->routeParamInt('id');
        $profile   = KycService::find($profileId);
        $slots     = array_values(array_map('strval', (array) $data['slots']));

        try {
            self::assertAccountLive($profileId);

            KycService::requestResubmission(
                $profileId,
                $this->adminId($request),
                $slots,
                (string) ($data['message'] ?? ''),
                $this->auditContext($request)
            );
        } catch (HttpException $e) {
            $this->fail($request, $e);
        }

        $this->log(
            $request,
            'kyc.resubmission_requested',
            'kyc_profile',
            $profileId,
            'Asked for re-upload on ' . $profile['reference'] . '.',
            ['slots' => $slots]
        );

        $this->respond($request, 'Re-upload requested. The applicant has been notified.');
    }

    /** POST /admin/kyc/documents/{id}/review — approve or reject one document. */
    public function reviewDocument(Request $request): never
    {
        $this->authorize($request, 'kyc.review');

        $data = $this->validate($request, [
            'decision' => 'required|in:approve,reject',
            'reason'   => 'nullable|string|max:500',
        ]);

        $documentId = $request->routeParamInt('id');

        $document = Database::selectOne(
            'SELECT `id`, `profile_id`, `title` FROM `kyc_documents` WHERE `id` = :id',
            ['id' => $documentId]
        ) ?? throw HttpException::notFound('That document was not found.');

        try {
            if ($document['profile_id'] !== null) {
                self::assertAccountLive((int) $document['profile_id']);
            }

            KycService::reviewDocument(
                $documentId,
                $this->adminId($request),
                (string) $data['decision'],
                $data['reason'] ?? null,
                $this->auditContext($request)
            );
        } catch (HttpException $e) {
            $this->fail($request, $e, '/admin/kyc/' . (int) $document['profile_id']);
        }

        $approved = $data['decision'] === 'approve';

        $this->log(
            $request,
            $approved ? 'kyc.document_approved' : 'kyc.document_rejected',
            'kyc_document',
            $documentId,
            ($document['title'] ?? 'Document') . ($approved ? ' approved.' : ' rejected.'),
            ['reason' => $data['reason'] ?? null]
        );

        $this->respond(
            $request,
            ($document['title'] ?? 'Document') . ($approved ? ' approved.' : ' rejected. The applicant has been told why.'),
            '/admin/kyc/' . (int) $document['profile_id']
        );
    }

    /** POST /admin/kyc/{id}/badge/grant — the blue tick, a separate decision. */
    public function grantBadge(Request $request): never
    {
        $this->authorize($request, 'kyc.badge');

        $data = $this->validate($request, [
            'note' => 'nullable|string|max:500',
        ]);

        $profileId = $request->routeParamInt('id');

        try {
            KycService::grantBadge(
                $profileId,
                $this->adminId($request),
                $data['note'] ?? null,
                $this->auditContext($request)
            );
        } catch (HttpException $e) {
            $this->fail($request, $e);
        }

        // KycService::grantBadge() writes the activity-log entry itself, so
        // every path to the tick (this screen, the creator screens) is logged
        // the same way.
        $this->respond($request, 'Blue tick granted.');
    }

    /** POST /admin/kyc/{id}/badge/revoke */
    public function revokeBadge(Request $request): never
    {
        $this->authorize($request, 'kyc.badge');

        $data = $this->validate($request, [
            'reason' => 'required|string|min:3|max:500',
        ]);

        try {
            KycService::revokeBadge(
                $request->routeParamInt('id'),
                $this->adminId($request),
                (string) $data['reason'],
                $this->auditContext($request)
            );
        } catch (HttpException $e) {
            $this->fail($request, $e);
        }

        $this->respond($request, 'Blue tick removed.');
    }

    /** POST /admin/kyc/bulk */
    public function bulk(Request $request): never
    {
        $this->authorize($request, 'kyc.review');

        $data = $this->validate($request, [
            'action' => 'required|in:approve,reject',
            'ids'    => 'required|array',
            'reason' => 'nullable|string|max:1000',
        ]);

        $ids = array_values(array_filter(array_map('intval', (array) $data['ids'])));

        if ($ids === []) {
            $this->back('error', 'Select at least one submission.');
        }

        // A blanket rejection without a reason would leave every one of those
        // users with no idea what to fix.
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($data['action'] === 'reject' && $reason === '') {
            $this->back('error', 'A bulk rejection still needs a reason.');
        }

        $ids = array_values(array_unique($ids));

        $adminId    = $this->adminId($request);
        $context    = $this->auditContext($request);
        $done       = 0;
        $skipped    = [];
        $incomplete = [];
        $aadhaar    = [];

        // Only submissions waiting for a decision can be decided in bulk. The
        // queue offers checkboxes on those rows alone; this is the same rule
        // for a crafted post, so a batch can never re-approve an approved
        // profile (silently extending its validity) or re-reject a rejected
        // one with a new reason.
        $statuses = [];
        $deleted  = [];

        // A deleted account's verification is a record, not a submission: it
        // is never decided (and its owner never notified) in bulk either.
        foreach (Database::select(
            'SELECT p.`id`, p.`status`, (u.`id` IS NULL OR u.`deleted_at` IS NOT NULL) AS `account_deleted`
               FROM `kyc_profiles` p
               LEFT JOIN `users` u ON u.`id` = p.`user_id`
              WHERE p.`deleted_at` IS NULL AND p.`id` IN (' . implode(',', array_map('intval', $ids)) . ')'
        ) as $row) {
            $statuses[(int) $row['id']] = (string) $row['status'];

            if ((int) $row['account_deleted'] === 1) {
                $deleted[(int) $row['id']] = true;
            }
        }

        foreach ($ids as $profileId) {
            if (isset($deleted[$profileId]) || !in_array($statuses[$profileId] ?? null, self::BULK_STATUSES, true)) {
                $skipped[] = $profileId;
                continue;
            }

            // A batch cannot confirm that each Aadhaar copy is masked — that
            // takes a reviewer looking at each image — so it never approves a
            // profile holding one. Those are listed for one-by-one review.
            if ($data['action'] === 'approve' && self::hasCurrentAadhaar($profileId)) {
                $aadhaar[] = $profileId;
                continue;
            }

            try {
                // approve() applies the same completeness guard as the detail
                // screen, so a batch cannot approve what one-by-one would not.
                // No attestation is ever given in bulk (see above).
                $data['action'] === 'approve'
                    ? KycService::approve($profileId, $adminId, 'Bulk approval.', [], $context)
                    : KycService::reject($profileId, $adminId, $reason, $context);
                $done++;
            } catch (HttpException $e) {
                // One bad row must not abort the rest of the batch.
                if ($e->errorCode() === 'kyc_incomplete') {
                    $incomplete[] = $profileId;
                } else {
                    $skipped[] = $profileId;
                }
            }
        }

        $this->log(
            $request,
            'kyc.bulk_' . $data['action'],
            'kyc_profile',
            null,
            "Bulk {$data['action']} on {$done} verifications.",
            [
                'ids'                 => $ids,
                'skipped'             => $skipped,
                'incomplete'          => $incomplete,
                'needs_aadhaar_check' => $aadhaar,
                'account_deleted'     => array_keys($deleted),
            ]
        );

        $message = $done . ' verification' . ($done === 1 ? '' : 's') . ' ' .
            ($data['action'] === 'approve' ? 'approved' : 'rejected') . '.';

        if ($incomplete !== []) {
            $message .= ' ' . count($incomplete) . ' skipped as incomplete (missing or rejected documents, or invalid details): '
                . self::references($incomplete) . '.';
        }

        if ($aadhaar !== []) {
            $message .= ' ' . count($aadhaar) . ' skipped because an Aadhaar copy has to be checked for masking on its own page: '
                . self::references($aadhaar) . '.';
        }

        if ($skipped !== []) {
            $message .= ' ' . count($skipped) . ' skipped (not waiting for a decision'
                . ($deleted !== [] ? ', or the account was deleted' : '') . ').';
        }

        $this->respond($request, $message);
    }

    /**
     * POST /admin/kyc/{id}/identifiers/release
     *
     * Take an Aadhaar, PAN, GSTIN or CIN off this verification so its rightful
     * holder can use it — the way out of "already linked to another account"
     * when the other account should not have it. Body: field, reason.
     */
    public function releaseIdentifier(Request $request): never
    {
        $this->authorize($request, 'kyc.review');

        $data = $this->validate($request, [
            'field'  => 'required|in:' . implode(',', array_keys(self::IDENTIFIERS)),
            'reason' => 'required|string|min:3|max:500',
        ], [
            'reason.required' => 'Say why the identifier is being released — it goes into the audit trail.',
        ]);

        $profileId = $request->routeParamInt('id');
        $profile   = KycService::find($profileId);
        $field     = (string) $data['field'];
        $label     = (string) self::IDENTIFIERS[$field]['label'];
        $reason    = trim((string) $data['reason']);

        if (mb_strlen($reason) < 3) {
            $this->fail($request, HttpException::unprocessable(
                'Say why the identifier is being released — it goes into the audit trail.'
            ));
        }

        if (!isset(self::IDENTIFIERS[$field]['columns'][(string) $profile['kind']])) {
            $this->fail($request, HttpException::unprocessable(
                'A ' . $profile['kind'] . ' verification has no ' . $label . ' to release.'
            ));
        }

        try {
            KycService::releaseIdentifier($profileId, $field, $this->adminId($request), $reason);
        } catch (HttpException $e) {
            $this->fail($request, $e);
        }

        // The number itself is never written to the log — only which one.
        $this->log(
            $request,
            'kyc.identifier_released',
            'kyc_profile',
            $profileId,
            $label . ' released from verification ' . $profile['reference'] . '.',
            ['field' => $field, 'reason' => $reason]
        );

        $this->respond(
            $request,
            $label . ' released from ' . $profile['reference'] . '. Its rightful holder can now use it.',
            '/admin/kyc/' . $profileId
        );
    }

    // -----------------------------------------------------------------
    // Notes
    // -----------------------------------------------------------------

    /** POST /admin/kyc/{id}/notes */
    public function addNote(Request $request): never
    {
        $this->authorize($request, 'kyc.review');

        $data = $this->validate($request, [
            'body'      => 'required|string|min:2|max:5000',
            'is_pinned' => 'nullable|boolean',
        ]);

        $profileId = $request->routeParamInt('id');
        KycService::find($profileId);

        Database::insert('kyc_notes', [
            'profile_id' => $profileId,
            'admin_id'   => $this->adminId($request),
            'body'       => $data['body'],
            'is_pinned'  => !empty($data['is_pinned']) ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        KycService::recordEvent($profileId, 'note_added', [
            'actor_type' => 'admin',
            'actor_id'   => $this->adminId($request),
            'note'       => 'Internal note added.',
            'ip'         => $request->ip(),
            'agent'      => $request->userAgent(),
        ]);

        $this->respond($request, 'Note added.');
    }

    // -----------------------------------------------------------------
    // Documents
    // -----------------------------------------------------------------

    /** GET /admin/kyc/documents/{id} — stream a document to a reviewer. */
    public function download(Request $request): never
    {
        // Opening a document is a KYC view as well as a download: both are held.
        $this->authorize($request, 'kyc.view');
        $this->authorize($request, 'kyc.download');

        $document = $this->documentFromToken($request);
        $file     = DocumentVault::read($document);

        // Reviewer access to identity documents is itself auditable.
        $this->log(
            $request,
            'kyc.document_downloaded',
            'kyc_document',
            (int) $document['id'],
            'Downloaded ' . ($document['title'] ?? 'a KYC document') . '.'
        );

        KycService::recordEvent((int) $document['profile_id'], 'document_downloaded', [
            'actor_type' => 'admin',
            'actor_id'   => $this->adminId($request),
            'note'       => 'Reviewer downloaded ' . ($document['title'] ?? 'a document') . '.',
            'ip'         => $request->ip(),
            'agent'      => $request->userAgent(),
        ]);

        Response::download($file['contents'], $file['name'], $file['mime']);
    }

    /**
     * GET /admin/kyc/documents/{id}/preview — the document, for the in-page
     * viewer.
     *
     * The panel's global headers forbid all framing (X-Frame-Options: DENY,
     * frame-ancestors 'none'), and the download route sends `attachment`, so
     * an <iframe> of a PDF either showed nothing or started a download — the
     * "broken preview". This response, and only this one, may be framed by
     * the panel's own pages, is `inline`, and is never cached.
     *
     * It keeps the panel's whole Content-Security-Policy and changes only who
     * may frame it. Only the image and PDF types the viewer shows are served
     * inline; any other stored type is sent as an opaque attachment, so a
     * file can never be rendered as a page on the panel's origin.
     */
    public function preview(Request $request): never
    {
        $this->authorize($request, 'kyc.view');
        $this->authorize($request, 'kyc.download');

        $document = $this->documentFromToken($request);
        $file     = DocumentVault::read($document);
        $mime     = strtolower(trim((string) $file['mime']));

        $this->log(
            $request,
            'kyc.document_previewed',
            'kyc_document',
            (int) $document['id'],
            'Viewed ' . ($document['title'] ?? 'a KYC document') . '.'
        );

        KycService::recordEvent((int) $document['profile_id'], 'document_previewed', [
            'actor_type' => 'admin',
            'actor_id'   => $this->adminId($request),
            'note'       => 'Reviewer viewed ' . ($document['title'] ?? 'a document') . '.',
            'metadata'   => ['document_id' => (int) $document['id'], 'version' => (int) $document['version']],
            'ip'         => $request->ip(),
            'agent'      => $request->userAgent(),
        ]);

        if (!in_array($mime, self::INLINE_MIME_TYPES, true)) {
            Response::download($file['contents'], $file['name'], 'application/octet-stream');
        }

        Response::header('X-Frame-Options', 'SAMEORIGIN');
        Response::header('Content-Security-Policy', self::previewPolicy($mime === 'application/pdf'));

        Response::inline($file['contents'], $file['name'], $mime);
    }

    /**
     * GET /admin/kyc/documents/{id}/link — fresh signed links.
     *
     * Tokens live for `download_link_ttl` seconds (300 by default), but a
     * reviewer can sit on the detail page far longer. The viewer asks for a
     * new pair each time it opens a document instead of reusing the ones baked
     * into the page.
     */
    public function documentLink(Request $request): never
    {
        $this->authorize($request, 'kyc.view');
        $this->authorize($request, 'kyc.download');

        $documentId = $request->routeParamInt('id');

        // Same checks as following the link, so a link is never minted for a
        // document that cannot be opened (deleted, purged, or still a draft's).
        $this->openableDocument($documentId);

        $token = DocumentVault::signToken($documentId, 'admin', $this->adminId($request));

        Response::success([
            'preview_url'  => '/admin/kyc/documents/' . $documentId . '/preview?token=' . rawurlencode($token),
            'download_url' => '/admin/kyc/documents/' . $documentId . '?token=' . rawurlencode($token),
            'expires_in'   => max(30, Config::settingInt('kyc', 'download_link_ttl', 300)),
        ]);
    }

    /**
     * Resolve `?token=` to a live document, checking the token was minted for
     * this document and this admin.
     *
     * @return array<string,mixed>
     */
    private function documentFromToken(Request $request): array
    {
        $documentId = $request->routeParamInt('id');
        $token      = $request->string('token');

        if ($token === '') {
            throw HttpException::forbidden('A download token is required.');
        }

        $claims  = DocumentVault::verifyToken($token);
        $adminId = $this->adminId($request);

        if ($claims['document_id'] !== $documentId
            || $claims['viewer_type'] !== 'admin'
            || $claims['viewer_id'] !== $adminId) {
            throw HttpException::forbidden('That download link is not valid for this account.');
        }

        return $this->openableDocument($documentId);
    }

    /**
     * A live document a reviewer may open, or an exception saying why not.
     *
     * A document belonging to a verification that was never submitted stays
     * closed: the applicant has not put it forward for review (that, and the
     * consent that goes with it, happens at submission), so a link minted
     * earlier, or by hand, opens nothing.
     *
     * @return array<string,mixed>
     */
    private function openableDocument(int $documentId): array
    {
        $document = Database::selectOne(
            'SELECT * FROM `kyc_documents` WHERE `id` = :id AND `deleted_at` IS NULL AND `purged_at` IS NULL',
            ['id' => $documentId]
        );

        if ($document === null) {
            throw HttpException::notFound('That document was not found, or has been deleted under the retention policy.');
        }

        // Documents from the retired single-document flow have no profile.
        if ($document['profile_id'] !== null) {
            $profile = Database::selectOne(
                'SELECT `status`, `deleted_at` FROM `kyc_profiles` WHERE `id` = :id',
                ['id' => (int) $document['profile_id']]
            );

            if ($profile === null || $profile['deleted_at'] !== null) {
                throw HttpException::notFound('That document was not found.');
            }

            if (self::isUnsubmittedDraft($profile)) {
                throw HttpException::forbidden(
                    'This verification has not been submitted yet. Its documents can be opened once the applicant submits it.'
                );
            }
        }

        return $document;
    }

    /**
     * A document for the reviewer's screen: the vault presentation plus the
     * admin preview / download URLs (only when [$adminId] may follow them).
     * The applicant's original file name is included only when [$showName].
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentForAdmin(array $row, ?int $adminId, bool $showName = false): array
    {
        $presented = DocumentVault::present($row, $adminId === null ? null : 'admin', $adminId);
        $token     = $presented['download_token'];
        $id        = (int) $row['id'];

        if (!$showName) {
            $presented['original_name'] = null;
        }

        $presented['preview_url']  = $token === null ? null : '/admin/kyc/documents/' . $id . '/preview?token=' . rawurlencode((string) $token);
        $presented['download_url'] = $token === null ? null : '/admin/kyc/documents/' . $id . '?token=' . rawurlencode((string) $token);
        $presented['deleted_at']   = $row['deleted_at'] ?? null;
        $presented['purged_at']    = $row['purged_at'] ?? null;

        return $presented;
    }

    // -----------------------------------------------------------------
    // Export
    // -----------------------------------------------------------------

    /** GET /admin/kyc/export */
    public function export(Request $request): never
    {
        $this->authorize($request, 'kyc.view');
        // The CSV carries names, email addresses and phone numbers, so it is
        // gated exactly like every other exporter in the panel.
        $this->authorize($request, 'export.data');

        $kind  = $this->kindFilter($request);
        // Same default as index() — an export launched from the screen must
        // carry the queue the operator was looking at, not silently widen to
        // every profile in every state. `?queue=all` still widens it on demand.
        $queue = $this->queueFilter($request, 'pending');

        // The list screen sends the search term as `q` (see listParams), and the
        // Export link forwards the list's own query string. `search` is still
        // honoured so older bookmarks keep working.
        $search = $request->string('q') ?: $request->string('search');

        [$where, $bindings] = $this->buildFilters($request, $kind, $queue, $search);

        $rows = Database::select(
            'SELECT p.`reference`, p.`kind`, p.`status`, p.`completeness`,
                    p.`submitted_at`, p.`reviewed_at`, p.`approved_at`, p.`expires_on`,
                    p.`submission_count`, p.`rejection_reason`, p.`badge_status`,
                    u.`name` AS `user_name`, u.`email`, u.`phone`,
                    a.`name` AS `reviewer_name`
             FROM `kyc_profiles` p
             INNER JOIN `users` u ON u.`id` = p.`user_id`
             LEFT JOIN `admins` a ON a.`id` = p.`reviewed_by`' . $where . '
             ORDER BY p.`id` DESC
             LIMIT 5000',
            $bindings
        );

        $this->log($request, 'kyc.exported', 'kyc_profile', null, count($rows) . ' rows exported.');

        $this->exportCsv(
            'kyc-' . ($kind ?? 'all') . '-' . date('Y-m-d') . '.csv',
            [
                'Reference', 'Type', 'Status', 'Completeness %', 'User', 'Email', 'Phone',
                'Submitted', 'Reviewed', 'Approved', 'Expires', 'Attempts', 'Reviewer', 'Reason', 'Blue tick',
            ],
            $rows,
            [
                'reference', 'kind', 'status', 'completeness', 'user_name', 'email', 'phone',
                'submitted_at', 'reviewed_at', 'approved_at', 'expires_on',
                'submission_count', 'reviewer_name', 'rejection_reason', 'badge_status',
            ]
        );
    }

    // -----------------------------------------------------------------
    // Requirements (settings)
    // -----------------------------------------------------------------

    /** GET /admin/kyc/requirements */
    public function requirements(Request $request): never
    {
        $this->authorize($request, 'kyc.settings');

        $this->view($request, 'admin.kyc.requirements', [
            'pageTitle'    => 'KYC requirements',
            'pageSubtitle' => 'Which documents each side of the marketplace must provide',
            // Inactive rules are listed here too — this is the only screen that
            // can switch one back on.
            'brand'        => KycService::requirements(KycService::KIND_BRAND, true),
            'creator'      => KycService::requirements(KycService::KIND_CREATOR, true),
        ]);
    }

    /** POST /admin/kyc/requirements/{id} */
    public function updateRequirement(Request $request): never
    {
        $this->authorize($request, 'kyc.settings');

        $data = $this->validate($request, [
            'label'           => 'required|string|max:150',
            'description'     => 'nullable|string|max:500',
            'is_required'     => 'nullable|boolean',
            'allows_multiple' => 'nullable|boolean',
            'requires_expiry' => 'nullable|boolean',
            'allowed_formats' => 'required|string|max:255',
            'max_size_mb'     => 'required|integer|min_value:1|max_value:100',
            'is_active'       => 'nullable|boolean',
            'sort_order'      => 'nullable|integer|min_value:0|max_value:9999',
        ]);

        $id = $request->routeParamInt('id');

        // Database::update() reports success on a zero-row match, so an unknown
        // id used to answer "Requirement saved." and write an activity-log entry
        // for a row that never existed. Confirm the row first — and keep it,
        // because the audit entry records what each field was before.
        $before = Database::selectOne('SELECT * FROM `kyc_requirements` WHERE `id` = :id', ['id' => $id]);

        if ($before === null) {
            throw HttpException::notFound('That requirement was not found.');
        }

        // Only formats the vault can verify by content. Anything else would be
        // silently ignored at upload (DocumentVault::allowedExtensions() fails
        // closed), so the screen would promise a format users can never send.
        $formats     = array_values(array_unique(array_filter(array_map(
            static fn (string $format): string => ltrim(strtolower(trim($format)), '.'),
            explode(',', (string) $data['allowed_formats'])
        ), static fn (string $format): bool => $format !== '')));
        $supported   = DocumentVault::supportedExtensions();
        $unsupported = array_values(array_diff($formats, $supported));

        if ($formats === [] || $unsupported !== []) {
            throw ValidationException::forField(
                'allowed_formats',
                ($unsupported !== []
                    ? 'These formats cannot be accepted: ' . implode(', ', $unsupported) . '. '
                    : 'List at least one format. ')
                . 'Choose from: ' . implode(', ', $supported) . '.'
            );
        }

        $after = [
            'label'           => (string) $data['label'],
            'description'     => $data['description'] ?? null,
            'is_required'     => !empty($data['is_required']) ? 1 : 0,
            'allows_multiple' => !empty($data['allows_multiple']) ? 1 : 0,
            'requires_expiry' => !empty($data['requires_expiry']) ? 1 : 0,
            'allowed_formats' => implode(',', $formats),
            'max_size_mb'     => (int) $data['max_size_mb'],
            'is_active'       => !empty($data['is_active']) ? 1 : 0,
            'sort_order'      => (int) ($data['sort_order'] ?? 0),
        ];

        // Field by field, old and new. Switching a slot off or making it
        // optional changes what every approval demands from that moment, so
        // "Requirement updated." on its own is not an audit record.
        $changes = ActivityLogger::diff($before, $after);

        if ($changes['after'] === []) {
            $this->respond($request, 'No changes to save.');
        }

        Database::update('kyc_requirements', $after, ['id' => $id]);

        $this->log(
            $request,
            'kyc.requirement_updated',
            'kyc_requirement',
            $id,
            'Requirement "' . $before['label'] . '" (' . $before['kind'] . ' · ' . $before['slot'] . ') updated: '
                . implode(', ', array_keys($changes['after'])) . '.',
            ['slot' => (string) $before['slot'], 'kind' => (string) $before['kind'], 'changes' => $changes]
        );

        $this->respond($request, 'Requirement saved.');
    }

    // -----------------------------------------------------------------
    // Legacy queue
    // -----------------------------------------------------------------

    /**
     * GET /admin/kyc-legacy — submissions from the retired single-document
     * flow, read-only.
     *
     * Only documents that never belonged to a verification profile: the
     * current system's documents are reviewed on /admin/kyc, behind the same
     * kyc.view permission as this list. (It used to be UserController::kyc(),
     * gated on users.kyc and with no profile filter, so roles without any KYC
     * permission could list every new-system submission here.)
     */
    public function legacy(Request $request): never
    {
        $this->authorize($request, 'kyc.view');

        $params   = $this->listParams($request, ['id', 'created_at', 'status'], 'id');
        // Deleted accounts are out of this queue as well (see buildFilters()).
        $clauses  = ['k.`profile_id` IS NULL', 'k.`deleted_at` IS NULL', 'u.`deleted_at` IS NULL'];
        $bindings = [];

        $status = $request->string('status', 'pending');

        if ($status !== 'all') {
            $clauses[]          = 'k.`status` = :status';
            $bindings['status'] = $status;
        }

        if ($params['search'] !== '') {
            $clauses[]          = "(u.`name` LIKE :search ESCAPE '\\\\' OR u.`email` LIKE :search ESCAPE '\\\\' OR k.`holder_name` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `kyc_documents` k INNER JOIN `users` u ON u.`id` = k.`user_id`' . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT k.*, u.`name` AS `user_name`, u.`email`, u.`avatar_url`, u.`account_type`
             FROM `kyc_documents` k
             INNER JOIN `users` u ON u.`id` = k.`user_id`' . $where . '
             ORDER BY k.`' . $params['sort_by'] . '` ' . $params['sort_dir'] . '
             LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        // Never the number itself: masked to its last four characters.
        foreach ($rows as &$row) {
            $plain                  = Crypto::decryptNullable($row['document_number'] ?? null);
            $row['document_number'] = $plain === null ? '—' : Crypto::mask($plain, 4);
        }
        unset($row);

        $this->view($request, 'admin.kyc.legacy', [
            'pageTitle'    => 'Legacy KYC submissions',
            'pageSubtitle' => number_format($total) . ' submissions from the retired single-document flow',
            'documents'    => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * A verification that has never been submitted. Draft is only ever the
     * first state (nothing moves back to it), so this is exactly "the
     * applicant has not put it forward for review yet".
     *
     * @param array<string,mixed> $profile
     */
    private static function isUnsubmittedDraft(array $profile): bool
    {
        return ($profile['status'] ?? null) === KycService::DRAFT;
    }

    /**
     * Refuse a decision on the verification of a deleted account.
     *
     * Its profile stays readable on the detail screen as the audit record,
     * but it is not a submission any more: approving, rejecting or asking for
     * documents would move the record and message an account that no longer
     * exists. Its identity data is erased by account deletion, or by the
     * retention sweep's safety net when that failed.
     *
     * @throws HttpException 409 account_deleted
     */
    private static function assertAccountLive(int $profileId): void
    {
        $deleted = (int) (Database::scalar(
            'SELECT COUNT(*) FROM `kyc_profiles` p
               LEFT JOIN `users` u ON u.`id` = p.`user_id`
              WHERE p.`id` = :id AND (u.`id` IS NULL OR u.`deleted_at` IS NOT NULL)',
            ['id' => $profileId]
        ) ?? 0) > 0;

        if ($deleted) {
            throw HttpException::conflict(
                'This account has been deleted. Its verification is kept as a record only and can no longer be decided.',
                'account_deleted'
            );
        }
    }

    /**
     * Does the verification hold a current Aadhaar card image — and so need
     * the reviewer's "copy is masked" confirmation to be approved?
     */
    private static function hasCurrentAadhaar(int $profileId): bool
    {
        return KycService::needsAadhaarMaskedAttestation($profileId);
    }

    /**
     * The unique identifiers this verification currently holds, by release
     * name, with the blind index each one is matched on (null for a row saved
     * before blind indexes existed).
     *
     * @param  array<string,mixed> $profile
     * @return array<string,?string>
     */
    private static function heldIdentifiers(array $profile): array
    {
        $kind  = (string) $profile['kind'];
        $table = $kind === KycService::KIND_BRAND ? 'kyc_brand_details' : 'kyc_creator_details';
        $row   = Database::selectOne(
            'SELECT * FROM `' . $table . '` WHERE `profile_id` = :id',
            ['id' => (int) $profile['id']]
        );

        if ($row === null) {
            return [];
        }

        $held = [];

        foreach (self::IDENTIFIERS as $field => $identifier) {
            if (!isset($identifier['columns'][$kind])) {
                continue;
            }

            [$valueColumn, $hashColumn] = $identifier['columns'][$kind];

            $value = $row[$valueColumn] ?? null;
            $hash  = $row[$hashColumn] ?? null;

            if (($value !== null && $value !== '') || ($hash !== null && $hash !== '')) {
                $held[$field] = $hash === null || $hash === '' ? null : (string) $hash;
            }
        }

        return $held;
    }

    /**
     * Other accounts' live verifications that hold one of the same numbers.
     *
     * Matched on the blind index, so no number is decrypted to compare.
     * Another account only — a proprietor's creator and brand verifications
     * legitimately share a PAN.
     *
     * Same rule as KycService::identifierCollisions() (policy P-unique): only
     * a verification that really claims the number counts — submitted, under
     * review, waiting on its owner, approved or suspended, on a live account.
     * A draft or a rejected profile holding the same number is not a conflict.
     *
     * @param  array<string,mixed>   $profile
     * @param  array<string,?string> $held    From heldIdentifiers().
     * @return list<array{field:string,label:string,id:int,reference:string,status_label:string,status_tone:string}>
     */
    private static function identifierConflicts(array $profile, array $held): array
    {
        $conflicts = [];
        [$in, $statuses] = Database::inClause(KycService::CLAIMING_STATUSES);

        foreach ($held as $field => $hash) {
            if ($hash === null) {
                continue;
            }

            foreach (self::IDENTIFIERS[$field]['holders'] as [$table, $column]) {
                $rows = Database::select(
                    'SELECT p.`id`, p.`reference`, p.`status`
                       FROM `' . $table . '` d
                       JOIN `kyc_profiles` p ON p.`id` = d.`profile_id`
                       JOIN `users` u ON u.`id` = p.`user_id`
                      WHERE d.`' . $column . '` = ?
                        AND p.`user_id` <> ?
                        AND p.`deleted_at` IS NULL
                        AND p.`purged_at` IS NULL
                        AND u.`deleted_at` IS NULL
                        AND p.`status` IN ' . $in . '
                      ORDER BY p.`id`
                      LIMIT 20',
                    array_merge([$hash, (int) $profile['user_id']], $statuses)
                );

                foreach ($rows as $row) {
                    $conflicts[] = [
                        'field'        => $field,
                        'label'        => (string) self::IDENTIFIERS[$field]['label'],
                        'id'           => (int) $row['id'],
                        'reference'    => (string) $row['reference'],
                        'status_label' => KycService::statusLabel((string) $row['status']),
                        'status_tone'  => KycService::statusTone((string) $row['status']),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /** @param list<int> $profileIds */
    private static function references(array $profileIds): string
    {
        if ($profileIds === []) {
            return '';
        }

        return implode(', ', array_map('strval', Database::column(
            'SELECT `reference` FROM `kyc_profiles` WHERE `id` IN ('
                . implode(',', array_map('intval', $profileIds)) . ') ORDER BY `id`'
        )));
    }

    /**
     * The Content-Security-Policy for an admin document preview: the panel's
     * own policy, unchanged except that the panel's pages (and only those)
     * may frame it. An image is also sandboxed; a PDF cannot be, because
     * Chrome's PDF viewer refuses to run in a sandboxed document.
     */
    private static function previewPolicy(bool $isPdf): string
    {
        $directives = [];

        foreach (explode(';', self::currentPolicy()) as $directive) {
            $directive = trim($directive);

            if ($directive === '' || str_starts_with(strtolower($directive), 'frame-ancestors')) {
                continue;
            }

            $directives[] = $directive;
        }

        $directives[] = "frame-ancestors 'self'";

        if (!$isPdf) {
            $directives[] = 'sandbox';
        }

        return implode('; ', $directives);
    }

    /**
     * The policy already queued for this response — the panel's, set by
     * Response::securityHeaders(true) before any admin action runs.
     *
     * Response keeps queued headers private and has no reader, so it is read
     * by reflection rather than copied here, where it would drift the first
     * time the panel's policy changes. Should that ever fail, a policy that
     * loads nothing but same-origin images stands in: stricter, never looser.
     */
    private static function currentPolicy(): string
    {
        $fallback = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'";

        try {
            $headers = (new \ReflectionProperty(Response::class, 'headers'))->getValue();
        } catch (\ReflectionException) {
            return $fallback;
        }

        $policy = is_array($headers) ? ($headers['Content-Security-Policy'] ?? null) : null;

        return is_string($policy) && trim($policy) !== '' ? $policy : $fallback;
    }

    /**
     * Who is deciding, from where — written into every kyc_events row a
     * decision produces. Admin decisions used to be recorded with a NULL IP.
     *
     * @return array<string,mixed>
     */
    private function auditContext(Request $request): array
    {
        return [
            'ip'         => $request->ip(),
            'agent'      => $request->userAgent(),
            'actor_name' => (string) ($this->admin($request)['name'] ?? ''),
        ];
    }

    /**
     * Report a refused decision back to the reviewer: a toast on the screen
     * they came from for a form post, the error payload for an AJAX call.
     */
    private function fail(Request $request, HttpException $e, ?string $redirect = null): never
    {
        if ($request->expectsJson()) {
            Response::error($e->getMessage(), $e->status(), $e->errorCode(), $e->errors());
        }

        $this->back('error', $e->getMessage(), $redirect);
    }

    /**
     * Resolve `?queue=` to a queue this controller actually knows.
     *
     * buildFilters() only adds a status predicate for a recognised key, so an
     * unknown value used to behave exactly like `all` — a stale bookmark or a
     * typo silently widened the queue to every status with no tab highlighted
     * and no hint that the filter had been dropped. Fall back to the default.
     */
    private function queueFilter(Request $request, string $default): string
    {
        $queue = $request->string('queue', $default);

        return $queue === 'all' || isset(self::QUEUES[$queue]) ? $queue : $default;
    }

    private function kindFilter(Request $request): ?string
    {
        $kind = $request->string('kind');

        return in_array($kind, [KycService::KIND_BRAND, KycService::KIND_CREATOR], true)
            ? $kind
            : null;
    }

    /**
     * Build the shared WHERE clause for the list and export.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildFilters(
        Request $request,
        ?string $kind,
        string $queue,
        string $search
    ): array {
        // Deleted (tombstoned or binned) accounts are in no queue and no
        // export: their verification is a record, opened directly from the
        // user or audit screens, never a submission to work through.
        $clauses  = ['p.`deleted_at` IS NULL', 'u.`deleted_at` IS NULL'];
        $bindings = [];

        if ($kind !== null) {
            $clauses[]        = 'p.`kind` = :kind';
            $bindings['kind'] = $kind;
        }

        if ($queue !== 'all' && isset(self::QUEUES[$queue])) {
            $statuses = self::QUEUES[$queue];
            $names    = [];

            foreach ($statuses as $i => $status) {
                $key            = 'st' . $i;
                $names[]        = ':' . $key;
                $bindings[$key] = $status;
            }

            $clauses[] = 'p.`status` IN (' . implode(', ', $names) . ')';

            if ($queue === 'awaiting_tick') {
                $clauses[] = "p.`badge_status` <> 'granted'";
            }
        }

        if ($search !== '') {
            $clauses[] = "(u.`name` LIKE :search ESCAPE '\\\\'"
                . " OR u.`email` LIKE :search ESCAPE '\\\\'"
                . " OR p.`reference` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($search);
        }

        // Advanced filters
        if ($request->filled('from')) {
            $clauses[]        = 'p.`submitted_at` >= :from';
            $bindings['from'] = $request->string('from') . ' 00:00:00';
        }

        if ($request->filled('to')) {
            $clauses[]      = 'p.`submitted_at` <= :to';
            $bindings['to'] = $request->string('to') . ' 23:59:59';
        }

        if ($request->filled('reviewer')) {
            $clauses[]            = 'p.`reviewed_by` = :reviewer';
            $bindings['reviewer'] = $request->int('reviewer');
        }

        if ($request->string('expiring') === '1') {
            $clauses[] = "p.`status` = 'approved' AND p.`expires_on` IS NOT NULL"
                . ' AND p.`expires_on` BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
        }

        if (in_array($request->string('tick'), ['granted', 'none', 'revoked'], true)) {
            $clauses[]        = 'p.`badge_status` = :tick';
            $bindings['tick'] = $request->string('tick');
        }

        return [' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /** @return array<string,int> */
    private function queueCounts(?string $kind): array
    {
        $counts   = [];
        $bindings = [];
        $kindSql  = '';

        if ($kind !== null) {
            $kindSql          = ' AND p.`kind` = :kind';
            $bindings['kind'] = $kind;
        }

        foreach (self::QUEUES as $queue => $statuses) {
            $quoted = implode(',', array_map(
                static fn (string $s): string => "'" . $s . "'",
                $statuses
            ));

            // Same rule as the lists (buildFilters): no deleted account.
            $counts[$queue] = (int) Database::scalar(
                'SELECT COUNT(*) FROM `kyc_profiles` p
                  INNER JOIN `users` u ON u.`id` = p.`user_id`
                  WHERE p.`deleted_at` IS NULL AND u.`deleted_at` IS NULL
                    AND p.`status` IN (' . $quoted . ')' . $kindSql
                    . ($queue === 'awaiting_tick' ? " AND p.`badge_status` <> 'granted'" : ''),
                $bindings
            );
        }

        return $counts;
    }

    /** @return list<array<string,mixed>> */
    private function notes(int $profileId): array
    {
        return Database::select(
            'SELECT n.*, a.`name` AS `admin_name`
               FROM `kyc_notes` n
               LEFT JOIN `admins` a ON a.`id` = n.`admin_id`
              WHERE n.`profile_id` = :id
              ORDER BY n.`is_pinned` DESC, n.`id` DESC
              LIMIT 100',
            ['id' => $profileId]
        );
    }
}

<?php

declare(strict_types=1);

/**
 * Renders the KYC admin templates with representative data.
 *
 * A syntax check only proves a template parses; this proves it actually runs —
 * that every variable the view reads is supplied, and that no helper call
 * blows up mid-render. It also asserts the few things on those screens that
 * matter for safety: the Aadhaar is only ever shown masked, a stored
 * `javascript:` link is never rendered as a link, the blue tick is its own
 * panel, and the legacy queue has no approve button. Run with:
 *
 *     php scripts/kyc_render_check.php
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\View;
use Zuste\Services\KycService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

(new Autoloader($basePath . '/storage/cache/classmap.php'))
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

App::boot($basePath);

// `csrf()` starts a session, which PHP refuses once output has been flushed.
// Buffering keeps headers_sent() false for the whole run.
ob_start();

// Normally set by App::runAdmin(); CLI has to point the renderer itself.
View::setPath($basePath . '/app/Views');

// The layout expects the shell context a controller would normally share.
View::share('admin', ['id' => 1, 'name' => 'Super Admin', 'email' => 'admin@zustee.local']);
View::share('permissions', ['kyc.view', 'kyc.review', 'kyc.download', 'kyc.settings', 'kyc.badge', 'users.kyc']);
View::share('badges', ['pending_kyc' => 2]);

$profileRow = [
    'id' => 1, 'user_id' => 1, 'kind' => 'brand', 'status' => 'under_review',
    'reference' => 'KYC-B-000001', 'submitted_at' => '2026-08-03 10:00:00',
    'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null,
    'info_requested' => null, 'approved_at' => null, 'expires_on' => '2028-08-03',
    'submission_count' => 1, 'completeness' => 50, 'resubmit_slots' => '["gst_certificate"]',
    'is_identity_verified' => 0, 'is_business_verified' => 0, 'is_premium_verified' => 0,
    'badge_status' => 'none', 'badge_granted_by' => null, 'badge_granted_at' => null,
    'badge_revoked_at' => null, 'badge_revoke_reason' => null,
    'consent_at' => '2026-08-03 10:00:00', 'consent_version' => '2026-10', 'purged_at' => null,
    'created_at' => '2026-08-01 09:00:00',
];

$pdf = [
    'id' => 11, 'slot' => 'gst_certificate', 'title' => 'GST certificate',
    'document_type' => 'gst_certificate', 'original_name' => 'gst.pdf',
    'mime_type' => 'application/pdf', 'file_size' => 240000,
    'size_label' => '234 KB', 'is_image' => false, 'is_pdf' => true,
    'version' => 2, 'status' => 'pending', 'rejection_reason' => null, 'reviewed_at' => null,
    'is_current' => true, 'is_purged' => false, 'expires_on' => null,
    'is_expired' => false, 'uploaded_at' => '2026-08-02 11:00:00',
    'download_token' => 'demo-token', 'preview_url' => '/admin/kyc/documents/11/preview?token=demo-token',
    'download_url' => '/admin/kyc/documents/11?token=demo-token', 'deleted_at' => null, 'purged_at' => null,
];

$older = ['id' => 10, 'version' => 1, 'status' => 'rejected', 'rejection_reason' => 'Blurred photo',
    'is_current' => false, 'mime_type' => 'image/png', 'is_pdf' => false, 'is_image' => true] + $pdf;

$brandRequirements = [
    ['slot' => 'gst_certificate', 'label' => 'GST certificate', 'is_required' => 1],
    ['slot' => 'incorporation_certificate', 'label' => 'Certificate of Incorporation', 'is_required' => 1],
    ['slot' => 'pan_card', 'label' => 'Company PAN card', 'is_required' => 0],
];

$brandShow = [
    'pageTitle'    => 'Verification KYC-B-000001',
    'pageSubtitle' => 'Bloom Skincare · Brand',
    'profile'      => $profileRow,
    'presented'    => KycService::presentProfile($profileRow),
    'user'         => [
        'id' => 1, 'name' => 'Bloom Skincare', 'email' => 'hello@bloom.test',
        'phone' => '9876543210', 'avatar_url' => null, 'account_type' => 'brand',
        'status' => 'active', 'kyc_status' => 'under_review', 'has_blue_tick' => 0,
        'blue_tick_at' => null, 'created_at' => '2026-02-14 10:00:00',
    ],
    'details' => [
        'company_name' => 'Bloom Skincare Pvt Ltd',
        'company_type' => 'private_limited', 'cin_required' => true,
        'industry' => 'Beauty & Skincare',
        'gst_number' => '27ABCDE1234F1Z0', 'gst_masked' => 'XXXXXXXXXXX1Z0',
        'pan_number' => 'ABCDE1234F', 'pan_masked' => 'XXXXXX234F',
        'cin_number' => 'U12345MH2020PTC123456', 'cin_masked' => 'XXXXXXXXXXXXXXXXX3456',
        'registered_address' => '4th Floor, BKC, Mumbai 400051',
        'city' => 'Mumbai', 'state' => 'Maharashtra', 'country' => 'India',
        'website' => 'https://bloom.test',
        'description' => 'D2C skincare brand.',
        'social_links' => ['instagram' => 'https://instagram.com/bloom', 'evil' => 'javascript:alert(1)'],
    ],
    'unmasked'     => true,
    'slots'        => [
        'gst_certificate' => [
            'requirement' => $brandRequirements[0], 'current' => [$pdf], 'versions' => [$older],
            'resubmit_requested' => true, 'review_status' => 'pending',
        ],
        'incorporation_certificate' => [
            'requirement' => $brandRequirements[1], 'current' => [], 'versions' => [],
            'resubmit_requested' => false, 'review_status' => 'missing',
        ],
        'pan_card' => [
            'requirement' => $brandRequirements[2], 'current' => [], 'versions' => [],
            'resubmit_requested' => false, 'review_status' => 'missing',
        ],
    ],
    'otherDocuments' => [],
    'documents'      => [$pdf],
    'requirements'   => $brandRequirements,
    'blockers'       => ['Documents still needed: Certificate of Incorporation.'],
    'timeline'       => [
        [
            'action' => 'submitted', 'from_status' => 'draft', 'to_status' => 'submitted',
            'note' => 'Submitted for review.', 'actor_type' => 'user',
            'actor_name' => 'Bloom Skincare', 'created_at' => '2026-08-03 10:00:00',
            'label' => 'Submitted for review', 'ip' => '203.0.113.9',
        ],
    ],
    'notes' => [
        [
            'id' => 1, 'admin_id' => 1, 'admin_name' => 'Super Admin',
            'body' => 'GST looks valid.', 'is_pinned' => 1,
            'created_at' => '2026-08-03 10:30:00',
        ],
    ],
    'badge' => [
        'status' => 'none', 'granted_at' => null, 'granted_by' => null, 'revoked_at' => null,
        'revoke_reason' => null, 'visible' => false, 'can_grant' => false,
    ],
    'linkTtl' => 300,
];

$creatorRow = ['id' => 2, 'kind' => 'creator', 'reference' => 'KYC-C-000002', 'status' => 'approved',
    'badge_status' => 'granted', 'badge_granted_by' => 1, 'badge_granted_at' => '2026-08-05 12:00:00',
    'resubmit_slots' => null, 'approved_at' => '2026-08-04 09:00:00'] + $profileRow;

$creatorShow = [
    'pageTitle'    => 'Verification KYC-C-000002',
    'profile'      => $creatorRow,
    'presented'    => KycService::presentProfile($creatorRow),
    'user'         => ['account_type' => 'creator', 'has_blue_tick' => 1] + $brandShow['user'],
    'details'      => [
        'full_name' => 'Aanya Kapoor', 'date_of_birth' => '1996-02-02',
        'aadhaar_masked' => 'XXXX XXXX 1234', 'pan_number' => 'XXXXXX234F', 'pan_masked' => 'XXXXXX234F',
    ],
    'unmasked'     => false,
    'blockers'     => [],
    'badge'        => [
        'status' => 'granted', 'granted_at' => '2026-08-05 12:00:00', 'granted_by' => 'Super Admin',
        'revoked_at' => null, 'revoke_reason' => null, 'visible' => true, 'can_grant' => true,
    ],
] + $brandShow;

$checks = [
    'admin.kyc.index' => [[
        'pageTitle'    => 'KYC verification',
        'pageSubtitle' => '2 submissions',
        'profiles'     => [
            $profileRow + [
                'user_name' => 'Bloom Skincare', 'email' => 'hello@bloom.test',
                'phone' => '9876543210', 'avatar_url' => null,
                'account_type' => 'brand', 'reviewer_name' => null, 'has_blue_tick' => 0,
                'document_count' => 4,
                'status_label' => 'Under review', 'status_tone' => 'info',
            ],
        ],
        'pagination' => ['total' => 1, 'page' => 1, 'per_page' => 20, 'last_page' => 1],
        'filters'    => ['search' => '', 'queue' => 'pending'],
        'kind'       => null,
        'queue'      => 'pending',
        'counts'     => ['pending' => 1, 'approved' => 0, 'rejected' => 0, 'awaiting_tick' => 3],
    ], ['Approved — awaiting blue tick', 'Blue tick'], []],

    'admin.kyc.show' => [$brandShow, [
        'Approval is blocked', 'Request re-upload', 'Blue tick', 'Available once the KYC is approved',
        'kyc-preview-watermark', 'Re-upload requested', 'earlier version', 'U12345MH2020PTC123456',
        '203.0.113.9', 'Blurred photo',
    ], ['href="javascript:', 'Grant blue tick']],

    'admin.kyc.show (creator, tick granted)' => [$creatorShow, [
        'XXXX XXXX 1234', 'only the last 4 digits are stored', 'Revoke blue tick', 'Granted',
        'Suspend verification',
    ], ['Grant blue tick', 'Approval is blocked']],

    'admin.kyc.requirements' => [[
        'pageTitle'    => 'KYC requirements',
        'pageSubtitle' => 'Document rules',
        'brand'        => KycService::requirements('brand'),
        'creator'      => KycService::requirements('creator'),
    ], ['Aadhaar card (front)', 'Certificate of Incorporation'], []],

    'admin.kyc.legacy' => [[
        'pageTitle'  => 'KYC verification',
        'documents'  => [[
            'id' => 5, 'user_id' => 1, 'user_name' => 'Old User', 'email' => 'old@x.test', 'avatar_url' => null,
            'document_type' => 'aadhaar', 'holder_name' => 'Old User', 'document_number' => 'XXXXXXXX1234',
            'created_at' => '2026-01-01 10:00:00', 'status' => 'pending', 'rejection_reason' => null,
            'front_image_url' => null, 'back_image_url' => null, 'selfie_url' => null,
        ]],
        'pagination' => ['total' => 1, 'page' => 1, 'per_page' => 20, 'last_page' => 1],
        'filters'    => ['status' => 'pending'],
    ], ['read-only', '/admin/kyc'], ['/review', 'Approve']],
];

echo "\nKYC template render check\n-------------------------\n";

$fail = 0;

foreach ($checks as $label => [$data, $mustContain, $mustNotContain]) {
    $template = explode(' ', $label)[0];

    try {
        $html = View::render($template, $data);
        $size = strlen($html);

        if ($size < 500) {
            echo "  FAIL  {$label} — rendered only {$size} bytes\n";
            $fail++;
            continue;
        }

        $problems = [];

        foreach ($mustContain as $needle) {
            if (!str_contains($html, $needle)) {
                $problems[] = 'missing "' . $needle . '"';
            }
        }

        foreach ($mustNotContain as $needle) {
            if (str_contains($html, $needle)) {
                $problems[] = 'unexpectedly contains "' . $needle . '"';
            }
        }

        if ($problems !== []) {
            echo "  FAIL  {$label} — " . implode('; ', $problems) . "\n";
            $fail++;
            continue;
        }

        echo "  PASS  {$label} (" . number_format($size) . " bytes)\n";
    } catch (\Throwable $e) {
        echo "  FAIL  {$label} — " . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        $fail++;
    }
}

echo "\n-------------------------\n";
echo $fail === 0 ? "  all templates rendered\n\n" : "  {$fail} template(s) failed\n\n";

// Discard any half-rendered markup a failing template left behind, then emit
// only the report.
$report = ob_get_clean();
fwrite(STDOUT, $report === false ? '' : $report);

exit($fail === 0 ? 0 : 1);

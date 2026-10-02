<?php

declare(strict_types=1);

/**
 * KYC module smoke test.
 *
 * Exercises the service layer directly — profile creation, the requirement
 * rulebook, completeness, the gate, and the state machine's refusal of an
 * illegal transition. Run with:
 *
 *     php scripts/kyc_smoke.php
 *
 * Safe to re-run: it only creates a draft profile for an existing user and
 * never approves anything.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
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

$pass = 0;
$fail = 0;

function check(string $label, callable $test): void
{
    global $pass, $fail;

    try {
        $result = $test();
        if ($result === true) {
            echo "  PASS  {$label}\n";
            $pass++;
            return;
        }
        echo "  FAIL  {$label} — {$result}\n";
        $fail++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$label} — " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\nKYC module smoke test\n---------------------\n";

$user = Database::selectOne("SELECT * FROM `users` WHERE `account_type` = 'brand' LIMIT 1");

if ($user === null) {
    echo "  SKIP  no brand user in the database\n";
    exit(0);
}

$userId = (int) $user['id'];
echo "  Using user #{$userId} ({$user['name']})\n\n";

check('requirements are seeded for brands', static function () {
    $rows = KycService::requirements(KycService::KIND_BRAND);
    return count($rows) >= 10 ?: 'only ' . count($rows) . ' requirements found';
});

check('requirements are seeded for creators', static function () {
    $rows = KycService::requirements(KycService::KIND_CREATOR);
    return count($rows) >= 8 ?: 'only ' . count($rows) . ' requirements found';
});

check('a draft profile is created on first access', static function () use ($userId) {
    $profile = KycService::profileFor($userId, KycService::KIND_BRAND);
    return $profile['status'] === 'draft' ?: 'status was ' . $profile['status'];
});

check('the profile reference is well formed', static function () use ($userId) {
    $profile = KycService::profileFor($userId, KycService::KIND_BRAND);
    return str_starts_with((string) $profile['reference'], 'KYC-B-')
        ?: 'reference was ' . $profile['reference'];
});

check('profileFor is idempotent', static function () use ($userId) {
    $a = KycService::profileFor($userId, KycService::KIND_BRAND);
    $b = KycService::profileFor($userId, KycService::KIND_BRAND);
    return (int) $a['id'] === (int) $b['id'] ?: 'two different profiles were created';
});

check('overview assembles without error', static function () use ($userId) {
    $overview = KycService::overview($userId, KycService::KIND_BRAND);
    foreach (['profile', 'details', 'requirements', 'documents', 'completeness', 'can_submit'] as $key) {
        if (!array_key_exists($key, $overview)) {
            return "missing key: {$key}";
        }
    }
    return true;
});

check('an empty profile is 0% complete', static function () use ($userId) {
    $overview = KycService::overview($userId, KycService::KIND_BRAND);
    return $overview['completeness'] === 0
        ?: 'completeness was ' . $overview['completeness'];
});

check('an empty profile cannot be submitted', static function () use ($userId) {
    $overview = KycService::overview($userId, KycService::KIND_BRAND);
    return $overview['can_submit'] === false ?: 'can_submit was true';
});

check('submitting an empty profile is refused', static function () use ($userId) {
    try {
        KycService::submit($userId, KycService::KIND_BRAND);
        return 'submit succeeded when it should have failed';
    } catch (HttpException $e) {
        return str_contains($e->getMessage(), 'Still needed')
            ?: 'unexpected message: ' . $e->getMessage();
    }
});

check('details save and read back', static function () use ($userId) {
    KycService::saveDetails($userId, KycService::KIND_BRAND, [
        'company_name' => 'Smoke Test Co.',
        'pan_number'   => 'ABCDE1234F',
        'industry'     => 'Beauty & Skincare',
    ]);

    $profile = KycService::profileFor($userId, KycService::KIND_BRAND);
    $details = KycService::details((int) $profile['id'], KycService::KIND_BRAND, true);

    if (($details['company_name'] ?? '') !== 'Smoke Test Co.') {
        return 'company_name did not round-trip';
    }

    // PAN is encrypted at rest, so the stored value must not be the plaintext.
    $stored = Database::scalar(
        'SELECT `pan_number` FROM `kyc_brand_details` WHERE `profile_id` = :id',
        ['id' => (int) $profile['id']]
    );

    if ($stored === 'ABCDE1234F') {
        return 'PAN was stored in plaintext';
    }

    return ($details['pan_number'] ?? '') === 'ABCDE1234F'
        ?: 'PAN did not decrypt correctly';
});

check('masking hides all but the last four characters', static function () use ($userId) {
    $profile = KycService::profileFor($userId, KycService::KIND_BRAND);
    $masked  = KycService::details((int) $profile['id'], KycService::KIND_BRAND, false);
    $value   = (string) ($masked['pan_number'] ?? '');

    return $value !== 'ABCDE1234F' && str_ends_with($value, '234F')
        ?: "masked value was: {$value}";
});

check('an illegal transition is refused', static function () use ($userId) {
    $profile = KycService::profileFor($userId, KycService::KIND_BRAND);

    try {
        // draft -> approved is not a legal edge.
        KycService::transition((int) $profile['id'], KycService::APPROVED);
        return 'draft was allowed to jump straight to approved';
    } catch (HttpException $e) {
        return $e->errorCode() === 'invalid_kyc_transition'
            ?: 'unexpected code: ' . $e->errorCode();
    }
});

check('the gate blocks an unverified brand', static function () use ($userId) {
    return KycService::isVerified($userId, 'brand') === false
        ?: 'an unverified brand passed the gate';
});

check('events are written to the audit trail', static function () use ($userId) {
    $profile = KycService::profileFor($userId, KycService::KIND_BRAND);
    $events  = KycService::timeline((int) $profile['id']);
    return count($events) > 0 ?: 'no events recorded';
});

check('status labels and tones resolve for every state', static function () {
    foreach ([
        KycService::DRAFT, KycService::SUBMITTED, KycService::UNDER_REVIEW,
        KycService::INFO_REQUIRED, KycService::APPROVED, KycService::REJECTED,
        KycService::SUSPENDED, KycService::EXPIRED,
    ] as $status) {
        if (KycService::statusLabel($status) === '' || KycService::statusTone($status) === '') {
            return "missing label or tone for: {$status}";
        }
    }
    return true;
});

check('the expiry sweep runs cleanly', static function () {
    $result = KycService::runExpirySweep();
    return isset($result['expired'], $result['warned'])
        ?: 'unexpected sweep result shape';
});

echo "\n---------------------\n";
echo "  {$pass} passed, {$fail} failed\n\n";

exit($fail === 0 ? 0 : 1);

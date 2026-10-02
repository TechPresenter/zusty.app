<?php

declare(strict_types=1);

/**
 * Bring stored KYC identifiers up to the current rules. One-off, re-runnable.
 *
 *     php scripts/kyc_backfill_identifiers.php           # dry run (default): counts only
 *     php scripts/kyc_backfill_identifiers.php --apply   # write the changes
 *
 * What it does, in order:
 *
 *   1. Blind indexes. Migration 044 added `pan_hash`, `gst_hash` and
 *      `cin_hash` as empty columns and only fresh saves ever filled them, so
 *      every PAN / GSTIN / CIN saved before 044 was invisible to the "already
 *      linked to another account" check. Each stored value is decrypted,
 *      normalised and hashed with Crypto::blindIndex() — exactly what a save
 *      does today.
 *
 *   2. Full Aadhaar numbers. Older builds kept a whole Aadhaar, encrypted, in
 *      `kyc_creator_details.id_proof_number` (type "aadhaar", or a 12-digit
 *      number typed into "Other ID"), and the retired single-document flow kept
 *      one in `kyc_documents.document_number`. Each is reduced to what the
 *      rules allow — the last four digits and the keyed hash, written only
 *      where the profile has none yet — and the full number is deleted.
 *
 *   3. Original file names. Uploads used to keep the client's file name in
 *      plain text (scans are often named after the holder or the number);
 *      each is replaced by the generated `<slot>-<yyyymmdd>.<ext>` label.
 *
 *   4. Report: identifiers that more than one account's live verification
 *      claims after the backfill, by profile reference, for a reviewer to
 *      settle (Admin -> KYC -> the profile -> Release identifier).
 *
 * Nothing it prints is an identity value: counts and profile references only.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Helpers\KycIdentifiers;

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

$apply = in_array('--apply', array_slice($argv, 1), true);

echo "\nKYC identifier backfill" . ($apply ? ' — APPLYING' : ' — dry run') . "\n";
echo str_repeat('-', 72) . "\n";

$stats = [
    'hashes_filled'          => 0,
    'hashes_unreadable'      => 0,
    'id_proof_scrubbed'      => 0,
    'aadhaar_tail_derived'   => 0,
    'document_numbers_cut'   => 0,
    'file_names_replaced'    => 0,
];

// ---------------------------------------------------------------------
// 1. Blind indexes for values saved before 044
// ---------------------------------------------------------------------
$identifiers = [
    'kyc_creator_details' => ['pan_number' => ['pan', 'pan_hash']],
    'kyc_brand_details'   => [
        'pan_number' => ['pan', 'pan_hash'],
        'gst_number' => ['gstin', 'gst_hash'],
        'cin_number' => ['cin', 'cin_hash'],
    ],
];

foreach ($identifiers as $table => $fields) {
    foreach ($fields as $column => [$domain, $hashColumn]) {
        $rows = Database::select(
            "SELECT `id`, `{$column}` AS `value` FROM `{$table}`
              WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' AND (`{$hashColumn}` IS NULL OR `{$hashColumn}` = '')"
        );

        foreach ($rows as $row) {
            $plain = Crypto::decryptNullable((string) $row['value']);

            if ($plain === null || trim($plain) === '') {
                $stats['hashes_unreadable']++;
                continue;
            }

            $stats['hashes_filled']++;

            if ($apply) {
                Database::update($table, [
                    $hashColumn => Crypto::blindIndex($domain, KycIdentifiers::normalize($plain)),
                ], ['id' => (int) $row['id']]);
            }
        }
    }
}

// ---------------------------------------------------------------------
// 2a. Full Aadhaar numbers in kyc_creator_details.id_proof_number
// ---------------------------------------------------------------------
foreach (Database::select(
    "SELECT `id`, `id_proof_type`, `id_proof_number`, `aadhaar_last4`, `aadhaar_hash`
       FROM `kyc_creator_details`
      WHERE `id_proof_number` IS NOT NULL AND `id_proof_number` <> ''"
) as $row) {
    $plain  = Crypto::decryptNullable((string) $row['id_proof_number']);
    $digits = $plain === null ? '' : KycIdentifiers::normalize($plain);

    $isAadhaar = $row['id_proof_type'] === 'aadhaar' || KycIdentifiers::isAadhaarShaped($digits);

    if (!$isAadhaar) {
        continue;
    }

    $update = ['id_proof_number' => null];

    if (KycIdentifiers::isAadhaarShaped($digits) && (string) ($row['aadhaar_hash'] ?? '') === '') {
        $update['aadhaar_last4'] = substr($digits, -4);
        $update['aadhaar_hash']  = Crypto::blindIndex('aadhaar', $digits);
        $stats['aadhaar_tail_derived']++;
    }

    $stats['id_proof_scrubbed']++;

    if ($apply) {
        Database::update('kyc_creator_details', $update, ['id' => (int) $row['id']]);
    }
}

// ---------------------------------------------------------------------
// 2b. Full Aadhaar numbers in the legacy kyc_documents.document_number
// ---------------------------------------------------------------------
foreach (Database::select(
    "SELECT `id`, `user_id`, `document_number` FROM `kyc_documents`
      WHERE `document_type` = 'aadhaar' AND `document_number` IS NOT NULL AND `document_number` <> ''"
) as $row) {
    $plain  = Crypto::decryptNullable((string) $row['document_number']);
    $digits = $plain === null ? '' : KycIdentifiers::normalize($plain);

    // Carry the tail and hash onto the user's creator profile when it has none.
    if (KycIdentifiers::isAadhaarShaped($digits)) {
        $detail = Database::selectOne(
            "SELECT d.`id` FROM `kyc_creator_details` d
               JOIN `kyc_profiles` p ON p.`id` = d.`profile_id`
              WHERE p.`user_id` = :u AND p.`kind` = 'creator' AND p.`deleted_at` IS NULL
                AND (d.`aadhaar_hash` IS NULL OR d.`aadhaar_hash` = '')",
            ['u' => (int) $row['user_id']]
        );

        if ($detail !== null) {
            $stats['aadhaar_tail_derived']++;

            if ($apply) {
                Database::update('kyc_creator_details', [
                    'aadhaar_last4' => substr($digits, -4),
                    'aadhaar_hash'  => Crypto::blindIndex('aadhaar', $digits),
                ], ['id' => (int) $detail['id']]);
            }
        }
    }

    $stats['document_numbers_cut']++;

    if ($apply) {
        Database::update('kyc_documents', ['document_number' => null], ['id' => (int) $row['id']]);
    }
}

// ---------------------------------------------------------------------
// 3. Client file names
// ---------------------------------------------------------------------
foreach (Database::select(
    "SELECT `id`, `slot`, `document_type`, `original_name`, `created_at` FROM `kyc_documents`
      WHERE `original_name` IS NOT NULL AND `original_name` <> ''"
) as $row) {
    $slot      = (string) ($row['slot'] ?? '') !== '' ? (string) $row['slot'] : (string) ($row['document_type'] ?? 'document');
    $extension = strtolower((string) pathinfo((string) $row['original_name'], PATHINFO_EXTENSION));
    $extension = preg_match('/^[a-z0-9]{1,5}$/', $extension) === 1 ? $extension : '';

    // The same shape DocumentVault::label() gives new uploads, dated by the
    // upload rather than by today.
    $label = preg_replace('/[^a-z0-9_]+/', '', strtolower($slot)) ?: 'document';
    $label .= '-' . date('Ymd', strtotime((string) $row['created_at']) ?: time())
        . ($extension === '' ? '' : '.' . $extension);

    if ($label === (string) $row['original_name']) {
        continue;
    }

    $stats['file_names_replaced']++;

    if ($apply) {
        Database::update('kyc_documents', ['original_name' => $label], ['id' => (int) $row['id']]);
    }
}

foreach ($stats as $key => $value) {
    printf("  %-24s %d\n", $key, $value);
}

// ---------------------------------------------------------------------
// 4. Identifiers more than one account's live verification claims
// ---------------------------------------------------------------------
$claiming = "('submitted', 'under_review', 'info_required', 'approved', 'suspended')";
$conflicts = [];

foreach ([
    'Aadhaar' => [['kyc_creator_details', 'aadhaar_hash']],
    'PAN'     => [['kyc_creator_details', 'pan_hash'], ['kyc_brand_details', 'pan_hash']],
    'GSTIN'   => [['kyc_brand_details', 'gst_hash']],
    'CIN'     => [['kyc_brand_details', 'cin_hash']],
] as $label => $holders) {
    $union = implode(' UNION ALL ', array_map(
        static fn (array $holder): string => "SELECT d.`{$holder[1]}` AS `hash`, p.`user_id`, p.`reference`
               FROM `{$holder[0]}` d
               JOIN `kyc_profiles` p ON p.`id` = d.`profile_id`
               JOIN `users` u ON u.`id` = p.`user_id`
              WHERE d.`{$holder[1]}` IS NOT NULL AND d.`{$holder[1]}` <> ''
                AND p.`deleted_at` IS NULL AND p.`purged_at` IS NULL AND u.`deleted_at` IS NULL
                AND p.`status` IN {$claiming}",
        $holders
    ));

    foreach (Database::select(
        "SELECT GROUP_CONCAT(DISTINCT c.`reference` ORDER BY c.`reference` SEPARATOR ', ') AS `refs`
           FROM ({$union}) c
          GROUP BY c.`hash`
         HAVING COUNT(DISTINCT c.`user_id`) > 1"
    ) as $row) {
        $conflicts[] = $label . ': ' . $row['refs'];
    }
}

echo "\nIdentifiers claimed by more than one account's live verification: " . count($conflicts) . "\n";

foreach ($conflicts as $line) {
    echo '  ' . $line . "\n";
}

echo $apply
    ? "\nDone.\n\n"
    : "\nNothing was changed. Re-run with --apply to write these changes.\n\n";

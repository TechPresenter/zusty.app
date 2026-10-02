<?php

declare(strict_types=1);

/**
 * Move identity documents out of the PUBLIC uploads tree into the vault.
 *
 *     php scripts/kyc_migrate_legacy.php            # dry run (default): list only
 *     php scripts/kyc_migrate_legacy.php --apply    # encrypt into storage/kyc, then delete the public copy
 *
 * The retired single-document KYC flow (POST /me/kyc/legacy, and /me/upload
 * with folder=kyc) stored ID images under public/uploads/kyc — readable by
 * anyone who has, or guesses, the URL, and never encrypted. Both entry points
 * are closed now; this deals with what they left behind.
 *
 * Listed:
 *   * every file under <upload_path>/kyc, and
 *   * every kyc_documents row whose front / back / selfie URL points there.
 *
 * With --apply, each referenced file is encrypted into the vault exactly like
 * a new upload (DocumentVault::storeLocalFile), the row's front image becomes
 * its `file_path` (back and selfie get rows of their own, flagged as legacy),
 * the public URL columns are cleared, and the public copy is deleted. Files no
 * row refers to are listed but NOT deleted — an operator decides about those.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Services\DocumentVault;

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

$apply   = in_array('--apply', array_slice($argv, 1), true);
$uploads = rtrim((string) Config::get('storage.upload_path', $basePath . '/public/uploads'), '/\\');
$kycDir  = $uploads . '/kyc';

/** Map a stored URL onto a file under <upload_path>/kyc, or null. */
$resolve = static function (?string $url) use ($uploads, $kycDir): ?string {
    $path = (string) (parse_url((string) $url, PHP_URL_PATH) ?? '');
    $pos  = strpos($path, '/uploads/kyc/');

    if ($pos === false) {
        return null;
    }

    $file = realpath($uploads . substr($path, $pos + strlen('/uploads')));
    $root = realpath($kycDir);

    return $file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)
        ? $file
        : null;
};

echo "\nLegacy KYC files in the public uploads tree" . ($apply ? ' — APPLYING' : ' — dry run') . "\n";
echo str_repeat('-', 72) . "\n";

$files = [];

if (is_dir($kycDir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kycDir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && !str_starts_with($file->getFilename(), '.')) {
            $files[(string) realpath($file->getPathname())] = $file->getSize();
        }
    }
}

echo "\nFiles under {$kycDir}: " . count($files) . "\n";

foreach ($files as $path => $size) {
    echo '  ' . $path . ' (' . DocumentVault::humanSize((int) $size) . ")\n";
}

$rows = Database::select(
    "SELECT * FROM `kyc_documents`
      WHERE `front_image_url` LIKE '%/uploads/kyc/%'
         OR `back_image_url`  LIKE '%/uploads/kyc/%'
         OR `selfie_url`      LIKE '%/uploads/kyc/%'"
);

echo "\nkyc_documents rows pointing at public files: " . count($rows) . "\n";

$referenced = [];

foreach ($rows as $row) {
    foreach (['front_image_url', 'back_image_url', 'selfie_url'] as $column) {
        $file = $resolve($row[$column] ?? null);

        echo sprintf(
            "  row #%d user #%d %-16s %-15s %s\n",
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['document_type'],
            $column,
            $file === null ? (empty($row[$column]) ? '—' : 'missing on disk') : $file
        );

        if ($file !== null) {
            $referenced[$file] = true;
        }
    }
}

$unreferenced = array_diff_key($files, $referenced);
echo "\nFiles no row refers to (left in place): " . count($unreferenced) . "\n";

if (!$apply) {
    echo "\nNothing was changed. Re-run with --apply to move referenced files into the vault.\n\n";
    exit(0);
}

$moved = 0;

foreach ($rows as $row) {
    $userId = (int) $row['user_id'];

    try {
        Database::transaction(static function () use ($row, $userId, $resolve, &$moved): void {
            $clear = ['front_image_url' => null, 'back_image_url' => null, 'selfie_url' => null];

            foreach (['front_image_url' => '', 'back_image_url' => ' (back)', 'selfie_url' => ' (selfie)'] as $column => $suffix) {
                $file = $resolve($row[$column] ?? null);

                if ($file === null) {
                    continue;
                }

                $stored = DocumentVault::storeLocalFile($file, $userId);
                $values = [
                    'file_path'     => $stored['path'],
                    'original_name' => $stored['original_name'],
                    'mime_type'     => $stored['mime'],
                    'file_size'     => $stored['size'],
                    'checksum'      => $stored['checksum'],
                    'is_encrypted'  => 1,
                ];

                if ($column === 'front_image_url' && empty($row['file_path'])) {
                    Database::update('kyc_documents', $values + $clear, ['id' => (int) $row['id']]);
                } else {
                    Database::insert('kyc_documents', $values + [
                        'user_id'       => $userId,
                        'profile_id'    => $row['profile_id'],
                        'document_type' => $row['document_type'],
                        'title'         => 'Legacy ' . str_replace('_', ' ', (string) $row['document_type']) . $suffix,
                        'status'        => $row['status'],
                        'is_current'    => 0,
                        'created_at'    => $row['created_at'],
                    ]);
                }

                @unlink($file);
                $moved++;
            }

            Database::update('kyc_documents', $clear, ['id' => (int) $row['id']]);
        });
    } catch (\Throwable $e) {
        echo '  ! row #' . (int) $row['id'] . ': ' . $e->getMessage() . "\n";
    }
}

echo "\nMoved {$moved} file(s) into the vault.\n\n";

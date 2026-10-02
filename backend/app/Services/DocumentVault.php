<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;

/**
 * Encrypted storage for KYC documents.
 *
 * Identity documents are the most sensitive data the platform holds, so they
 * are handled differently from ordinary media:
 *
 *   * files live under `storage/kyc/`, outside the web root — there is no URL
 *     that can serve them directly, even by accident;
 *   * every byte is encrypted at rest with AES-256-GCM before it touches disk;
 *   * retrieval goes through a short-lived signed token, so a leaked link
 *     stops working within minutes;
 *   * the plaintext SHA-256 is recorded, which detects both tampering and
 *     duplicate uploads;
 *   * uploads are validated by extension *and* by sniffed MIME type, because
 *     a filename proves nothing.
 *
 * Superseded files are retained (`is_current = 0`) rather than deleted, so an
 * audit can always reconstruct what a reviewer actually saw.
 */
final class DocumentVault
{
    /** Directory that holds encrypted document blobs. */
    private const STORAGE_DIR = 'kyc';

    /**
     * Extensions that may never be accepted regardless of settings — these
     * execute on a mis-configured server.
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
        'exe', 'sh', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'jsp', 'asp',
        'aspx', 'js', 'html', 'htm', 'svg', 'swf',
    ];

    /** Extension to acceptable sniffed MIME types. */
    private const MIME_MAP = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    ];

    // -----------------------------------------------------------------
    // Storing
    // -----------------------------------------------------------------

    /**
     * Validate and store one uploaded file against a requirement slot.
     *
     * @param  array<string,mixed> $file        One entry from $_FILES, already de-normalised.
     * @param  array<string,mixed> $requirement Row from `kyc_requirements`.
     * @return array{path:string,checksum:string,size:int,mime:string,extension:string,original_name:string}
     *
     * @throws HttpException when the file fails any validation gate.
     */
    public static function store(array $file, array $requirement, int $userId): array
    {
        self::assertUploadOk($file);

        $originalName = self::sanitiseName((string) ($file['name'] ?? 'document'));
        $extension    = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $tmpPath      = (string) ($file['tmp_name'] ?? '');
        $size         = (int) ($file['size'] ?? 0);

        self::assertExtensionAllowed($extension, $requirement);
        self::assertSizeAllowed($size, $requirement);

        $mime = self::sniffMime($tmpPath);
        self::assertMimeMatches($extension, $mime);

        $plaintext = @file_get_contents($tmpPath);
        if ($plaintext === false || $plaintext === '') {
            throw HttpException::unprocessable('That file could not be read. Please try again.');
        }

        $checksum = hash('sha256', $plaintext);

        // Encrypt before the bytes ever reach disk.
        $ciphertext = Crypto::encrypt($plaintext);

        $relativePath = self::buildPath($userId, $extension);
        $absolutePath = self::absolutePath($relativePath);

        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw HttpException::server('Could not prepare secure storage for that document.');
        }

        if (@file_put_contents($absolutePath, $ciphertext, LOCK_EX) === false) {
            throw HttpException::server('Could not store that document.');
        }

        @chmod($absolutePath, 0640);

        return [
            'path'          => $relativePath,
            'checksum'      => $checksum,
            'size'          => $size,
            'mime'          => $mime,
            'extension'     => $extension,
            'original_name' => $originalName,
        ];
    }

    /**
     * Encrypt a file that is already on this server into the vault.
     *
     * Only for moving legacy files out of the public uploads tree
     * (scripts/kyc_migrate_legacy.php) — they were accepted by the old flow,
     * so they are re-checked against the blocked extensions and sniffed MIME
     * map, but not against any requirement's size or format rules.
     *
     * @return array{path:string,checksum:string,size:int,mime:string,extension:string,original_name:string}
     */
    public static function storeLocalFile(string $absolutePath, int $userId): array
    {
        $originalName = self::sanitiseName(basename($absolutePath));
        $extension    = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === '' || in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw HttpException::unprocessable('That file type is not accepted.');
        }

        $mime = self::sniffMime($absolutePath);
        self::assertMimeMatches($extension, $mime);

        $plaintext = @file_get_contents($absolutePath);
        if ($plaintext === false || $plaintext === '') {
            throw HttpException::unprocessable('That file could not be read.');
        }

        $relativePath = self::buildPath($userId, $extension);
        $target       = self::absolutePath($relativePath);

        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0770, true) && !is_dir(dirname($target))) {
            throw HttpException::server('Could not prepare secure storage for that document.');
        }

        if (@file_put_contents($target, Crypto::encrypt($plaintext), LOCK_EX) === false) {
            throw HttpException::server('Could not store that document.');
        }

        @chmod($target, 0640);

        return [
            'path'          => $relativePath,
            'checksum'      => hash('sha256', $plaintext),
            'size'          => strlen($plaintext),
            'mime'          => $mime,
            'extension'     => $extension,
            'original_name' => $originalName,
        ];
    }

    // -----------------------------------------------------------------
    // Retrieving
    // -----------------------------------------------------------------

    /**
     * Decrypt a stored document.
     *
     * @return array{contents:string,mime:string,name:string}
     */
    public static function read(array $document): array
    {
        $relativePath = (string) ($document['file_path'] ?? '');
        if ($relativePath === '') {
            throw HttpException::notFound('That document is no longer available.');
        }

        $absolutePath = self::absolutePath($relativePath);
        if (!is_file($absolutePath)) {
            throw HttpException::notFound('That document is no longer available.');
        }

        $stored = @file_get_contents($absolutePath);
        if ($stored === false) {
            throw HttpException::server('That document could not be read.');
        }

        $contents = ((int) ($document['is_encrypted'] ?? 1)) === 1
            ? Crypto::decrypt($stored)
            : $stored;

        if ($contents === null) {
            throw HttpException::server('That document could not be decrypted.');
        }

        // A mismatch means the blob changed underneath us; refuse to serve it.
        $expected = (string) ($document['checksum'] ?? '');
        if ($expected !== '' && !hash_equals($expected, hash('sha256', $contents))) {
            throw HttpException::server('That document failed its integrity check.');
        }

        return [
            'contents' => $contents,
            'mime'     => (string) ($document['mime_type'] ?? 'application/octet-stream'),
            'name'     => (string) ($document['original_name'] ?? 'document'),
        ];
    }

    /**
     * Mint a short-lived signed token for one document.
     *
     * The token binds the document id to the viewer, so it cannot be replayed
     * by anyone else even inside its window.
     */
    public static function signToken(int $documentId, string $viewerType, int $viewerId): string
    {
        $ttl     = max(30, Config::settingInt('kyc', 'download_link_ttl', 300));
        $expires = time() + $ttl;

        $payload   = implode('|', [$documentId, $viewerType, $viewerId, $expires]);
        $signature = Crypto::sign($payload, self::signingSecret());

        return rtrim(strtr(base64_encode($payload . '|' . $signature), '+/', '-_'), '=');
    }

    /**
     * Validate a signed token and return its claims.
     *
     * @return array{document_id:int,viewer_type:string,viewer_id:int}
     */
    public static function verifyToken(string $token): array
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            throw HttpException::forbidden('That download link is not valid.');
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 5) {
            throw HttpException::forbidden('That download link is not valid.');
        }

        [$documentId, $viewerType, $viewerId, $expires, $signature] = $parts;

        $payload = implode('|', [$documentId, $viewerType, $viewerId, $expires]);
        if (!Crypto::verifySignature($payload, $signature, self::signingSecret())) {
            throw HttpException::forbidden('That download link is not valid.');
        }

        if ((int) $expires < time()) {
            throw HttpException::forbidden('That download link has expired. Please reopen the document.');
        }

        return [
            'document_id' => (int) $documentId,
            'viewer_type' => $viewerType,
            'viewer_id'   => (int) $viewerId,
        ];
    }

    // -----------------------------------------------------------------
    // Deleting
    // -----------------------------------------------------------------

    /**
     * Remove the blob from disk. The database row is soft-deleted separately so
     * the audit trail survives.
     */
    public static function purge(string $relativePath): bool
    {
        if ($relativePath === '') {
            return false;
        }

        $absolutePath = self::absolutePath($relativePath);

        return is_file($absolutePath) ? @unlink($absolutePath) : false;
    }

    /**
     * Delete encrypted blobs that no live row points at.
     *
     * A blob can outlive its row — a crash between the write and the INSERT,
     * a hard-deleted test profile, a row purged while its file was locked. An
     * identity document nobody can reach through the application is still an
     * identity document on disk, so the retention job sweeps them. Anything
     * younger than a day is left alone: that may be an upload in flight.
     *
     * [$userId] confines the sweep to that user's shard directory.
     *
     * @return int Files deleted (or, on a dry run, that would be).
     */
    public static function purgeOrphans(bool $dryRun = false, int $minAgeSeconds = 86400, ?int $userId = null): int
    {
        $root = self::absolutePath(
            $userId === null
                ? self::STORAGE_DIR
                : sprintf('%s/%s/%d', self::STORAGE_DIR, str_pad((string) ($userId % 100), 2, '0', STR_PAD_LEFT), $userId)
        );

        if (!is_dir($root)) {
            return 0;
        }

        $live = array_flip(array_map('strval', Database::column(
            "SELECT `file_path` FROM `kyc_documents`
              WHERE `file_path` IS NOT NULL AND `file_path` <> '' AND `purged_at` IS NULL"
        )));

        $base    = rtrim(str_replace('\\', '/', self::absolutePath('')), '/') . '/';
        $cutoff  = time() - max(0, $minAgeSeconds);
        $removed = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.enc')) {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : null;

            if ($relative === null || isset($live[$relative]) || $file->getMTime() > $cutoff) {
                continue;
            }

            if ($dryRun || @unlink($file->getPathname())) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Delete a file the legacy single-document KYC flow left in the public
     * uploads tree (`/uploads/kyc/...`), given the URL stored for it.
     *
     * Strictly confined to public/uploads/kyc: the URL comes from a database
     * row, and a crafted one must not be able to name any other file.
     */
    public static function purgeLegacyPublicFile(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $pos  = strpos($path, '/uploads/kyc/');

        if ($pos === false) {
            return false;
        }

        $uploads   = rtrim((string) Config::get('storage.upload_path', dirname(__DIR__, 2) . '/public/uploads'), '/\\');
        $publicKyc = realpath($uploads . '/kyc');

        if ($publicKyc === false) {
            return false;
        }

        // "/uploads/kyc/..." -> "<upload_path>/kyc/..."
        $candidate = realpath($uploads . substr($path, $pos + strlen('/uploads')));

        if ($candidate === false || !is_file($candidate)
            || !str_starts_with($candidate, $publicKyc . DIRECTORY_SEPARATOR)) {
            return false;
        }

        return @unlink($candidate);
    }

    // -----------------------------------------------------------------
    // Validation helpers
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $file */
    private static function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_OK) {
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw HttpException::unprocessable('That upload could not be verified.');
            }
            return;
        }

        throw HttpException::unprocessable(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE                        => 'No file was received.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not accept that file.',
            default                                   => 'That upload was rejected.',
        });
    }

    /** @param array<string,mixed> $requirement */
    private static function assertExtensionAllowed(string $extension, array $requirement): void
    {
        if ($extension === '' || in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw HttpException::unprocessable('That file type is not accepted.');
        }

        $allowed = self::allowedExtensions($requirement);

        if (!in_array($extension, $allowed, true)) {
            throw HttpException::unprocessable(
                'Accepted formats: ' . strtoupper(implode(', ', $allowed)) . '.'
            );
        }
    }

    /**
     * Per-requirement formats win, falling back to the global setting.
     *
     * @param  array<string,mixed> $requirement
     * @return list<string>
     */
    private static function allowedExtensions(array $requirement): array
    {
        $raw = trim((string) ($requirement['allowed_formats'] ?? ''));

        if ($raw === '') {
            $raw = Config::settingString('kyc', 'allowed_formats', 'pdf,jpg,jpeg,png');
        }

        $list = array_values(array_filter(array_map(
            static fn (string $item): string => strtolower(trim($item)),
            explode(',', $raw)
        )));

        // Never let configuration re-enable an executable extension.
        return array_values(array_diff($list, self::BLOCKED_EXTENSIONS));
    }

    /** @param array<string,mixed> $requirement */
    private static function assertSizeAllowed(int $size, array $requirement): void
    {
        if ($size <= 0) {
            throw HttpException::unprocessable('That file is empty.');
        }

        $limitMb = (int) ($requirement['max_size_mb'] ?? 0);
        if ($limitMb <= 0) {
            $limitMb = max(1, Config::settingInt('kyc', 'max_upload_mb', 10));
        }

        if ($size > $limitMb * 1024 * 1024) {
            throw HttpException::unprocessable("That file is larger than {$limitMb} MB.");
        }
    }

    private static function sniffMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return 'application/octet-stream';
    }

    /**
     * The declared extension must agree with the sniffed content type, which is
     * what stops a script being renamed to `.pdf`.
     */
    private static function assertMimeMatches(string $extension, string $mime): void
    {
        $expected = self::MIME_MAP[$extension] ?? null;

        // Unknown-but-allowed extensions fall back to the extension check only.
        if ($expected === null || $mime === 'application/octet-stream') {
            return;
        }

        if (!in_array($mime, $expected, true)) {
            throw HttpException::unprocessable(
                'That file does not look like a genuine ' . strtoupper($extension) . '.'
            );
        }
    }

    // -----------------------------------------------------------------
    // Paths
    // -----------------------------------------------------------------

    /**
     * Sharded by user so no directory grows unbounded, and randomised so a
     * filename can never be guessed.
     */
    private static function buildPath(int $userId, string $extension): string
    {
        $shard = str_pad((string) ($userId % 100), 2, '0', STR_PAD_LEFT);

        return sprintf(
            '%s/%s/%d/%s.%s.enc',
            self::STORAGE_DIR,
            $shard,
            $userId,
            Crypto::randomToken(16),
            $extension
        );
    }

    private static function absolutePath(string $relativePath): string
    {
        $root = rtrim(
            (string) Config::get('storage.base_path', dirname(__DIR__, 2) . '/storage'),
            '/\\'
        );

        // Defence in depth: a traversal sequence must never escape storage/.
        $clean = str_replace(['..', "\0"], '', $relativePath);

        return $root . '/' . ltrim($clean, '/\\');
    }

    private static function sanitiseName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w.\- ]+/u', '', $name) ?? 'document';

        return mb_substr(trim($name), 0, 180) ?: 'document';
    }

    /**
     * The key document links are signed with.
     *
     * There used to be a hard-coded last resort ('zuste-kyc-fallback'), which
     * meant a deployment missing both keys signed identity-document links with
     * a secret printed in the source — anyone who had read this file could mint
     * a valid link. With no key configured there is nothing safe to sign with,
     * so refuse instead.
     */
    private static function signingSecret(): string
    {
        $secret = (string) Config::get('app.key', '');

        if ($secret === '') {
            $secret = (string) Config::get('jwt.secret', '');
        }

        if ($secret === '') {
            throw new \RuntimeException(
                'No signing key is configured for KYC document links. Set APP_KEY or JWT_SECRET.'
            );
        }

        return $secret . '|kyc-document';
    }

    // -----------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------

    /**
     * Shape a document row for API and admin output.
     *
     * Never exposes `file_path` — the only route to the bytes is a signed
     * token minted for a specific viewer.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function present(array $row, ?string $viewerType = null, ?int $viewerId = null): array
    {
        $id = (int) $row['id'];

        // A purged or removed document has no bytes left; a token for it would
        // only ever produce a 404.
        $isPurged = ($row['purged_at'] ?? null) !== null
            || ($row['deleted_at'] ?? null) !== null
            || (string) ($row['file_path'] ?? '') === '';

        return [
            'id'            => $id,
            'slot'          => $row['slot'],
            'title'         => $row['title'],
            'document_type' => $row['document_type'],
            'original_name' => $row['original_name'],
            'mime_type'     => $row['mime_type'],
            'file_size'     => (int) $row['file_size'],
            'size_label'    => self::humanSize((int) $row['file_size']),
            'is_image'      => str_starts_with((string) $row['mime_type'], 'image/'),
            'is_pdf'        => $row['mime_type'] === 'application/pdf',
            'version'       => (int) $row['version'],
            // pending | approved | rejected — legacy `verified` / `under_review`
            // rows are mapped so a client only ever sees the three.
            'status'        => KycService::documentStatus($row['status'] ?? null),
            'rejection_reason' => $row['rejection_reason'] ?? null,
            'reviewed_at'   => $row['reviewed_at'] ?? null,
            'is_current'    => (int) ($row['is_current'] ?? 1) === 1,
            'is_purged'     => $isPurged,
            'expires_on'    => $row['expires_on'],
            'is_expired'    => $row['expires_on'] !== null
                && strtotime((string) $row['expires_on']) < strtotime('today'),
            'uploaded_at'   => $row['created_at'],
            'download_token' => $viewerType === null || $viewerId === null || $isPurged
                ? null
                : self::signToken($id, $viewerType, $viewerId),
        ];
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    /**
     * Flag documents whose expiry has passed. Called by the KYC cron job.
     *
     * @return int Number of documents marked expired.
     */
    public static function expireStaleDocuments(): int
    {
        return Database::execute(
            "UPDATE `kyc_documents`
                SET `status` = 'rejected',
                    `rejection_reason` = 'Document expired'
              WHERE `is_current` = 1
                AND `deleted_at` IS NULL
                AND `expires_on` IS NOT NULL
                AND `expires_on` < CURDATE()
                AND `status` <> 'rejected'"
        );
    }
}

<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;

/**
 * Secure file uploads.
 *
 * Defences applied to every upload, in order:
 *  1. PHP upload error checked, and `is_uploaded_file()` verified.
 *  2. Size checked against the per-type limit.
 *  3. Extension checked against an allow-list (never a deny-list).
 *  4. Real MIME type read from the file's content with finfo, then matched
 *     against the extension — a .php renamed to .jpg is rejected here.
 *  5. Images re-encoded through GD, which strips EXIF, embedded payloads and
 *     any polyglot content, and downscales oversized photos.
 *  6. A random filename is generated; the user's filename never reaches disk.
 *  7. The upload directory carries an .htaccess that disables PHP execution.
 */
final class UploadService
{
    /** extension => list of acceptable MIME types */
    private const MIME_MAP = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'gif'  => ['image/gif'],
        'mp4'  => ['video/mp4'],
        'mov'  => ['video/quicktime'],
        'webm' => ['video/webm'],
        'pdf'  => ['application/pdf'],
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Store an uploaded file and register it in the media library.
     *
     * @param array<string,mixed> $file    An entry from $_FILES.
     * @param string              $folder  Logical folder: avatars | portfolio | kyc | chat | banners | blogs.
     * @param string              $kind    image | video | document
     *
     * @return array{id:int,url:string,path:string,mime:string,size:int,width:int|null,height:int|null}
     */
    public static function store(
        array $file,
        string $folder = 'general',
        string $kind = 'image',
        string $uploaderType = 'user',
        ?int $uploaderId = null
    ): array {
        self::assertUploadOk($file);

        $tmpPath = (string) $file['tmp_name'];

        if (!is_uploaded_file($tmpPath)) {
            throw HttpException::unprocessable('Invalid upload.');
        }

        $originalName = (string) ($file['name'] ?? 'file');
        $extension    = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $sizeBytes    = (int) ($file['size'] ?? 0);

        [$allowedExtensions, $maxBytes] = self::limitsFor($kind);

        if (!in_array($extension, $allowedExtensions, true)) {
            throw HttpException::unprocessable(
                'Unsupported file type. Allowed: ' . implode(', ', $allowedExtensions) . '.'
            );
        }

        if ($sizeBytes <= 0 || $sizeBytes > $maxBytes) {
            throw HttpException::unprocessable(
                'The file is too large. Maximum size is ' . round($maxBytes / 1048576) . ' MB.'
            );
        }

        // Read the real type from the bytes, not from the client's claim.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw HttpException::server('Unable to inspect the uploaded file.');
        }

        $detectedMime = (string) finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        $acceptable = self::MIME_MAP[$extension] ?? [];

        if (!in_array($detectedMime, $acceptable, true)) {
            Logger::warning('Upload rejected: extension/MIME mismatch', [
                'extension' => $extension,
                'detected'  => $detectedMime,
                'uploader'  => $uploaderType . ':' . (string) $uploaderId,
            ]);

            throw HttpException::unprocessable('The file contents do not match its extension.');
        }

        $folder    = preg_replace('/[^a-z0-9_-]/', '', strtolower($folder)) ?: 'general';
        $directory = rtrim((string) Config::get('storage.upload_path'), '/\\') . '/' . $folder . '/' . date('Y/m');

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw HttpException::server('Unable to create the upload directory.');
        }

        self::hardenDirectory(rtrim((string) Config::get('storage.upload_path'), '/\\'));

        $fileName    = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $directory . '/' . $fileName;

        $width  = null;
        $height = null;

        if (in_array($extension, self::IMAGE_EXTENSIONS, true) && $extension !== 'gif') {
            [$width, $height] = self::processImage($tmpPath, $destination, $extension);
        } else {
            if (!move_uploaded_file($tmpPath, $destination)) {
                throw HttpException::server('Failed to store the uploaded file.');
            }

            if ($extension === 'gif') {
                $dimensions = @getimagesize($destination);
                $width      = $dimensions === false ? null : (int) $dimensions[0];
                $height     = $dimensions === false ? null : (int) $dimensions[1];
            }
        }

        @chmod($destination, 0644);

        $relativePath = $folder . '/' . date('Y/m') . '/' . $fileName;
        $url          = rtrim((string) Config::get('storage.upload_url'), '/') . '/' . $relativePath;
        $finalSize    = (int) (@filesize($destination) ?: $sizeBytes);

        $mediaId = Database::insert('media', [
            'disk'          => 'local',
            'folder'        => $folder,
            'file_name'     => $fileName,
            'original_name' => mb_substr($originalName, 0, 191),
            'path'          => $relativePath,
            'url'           => $url,
            'mime_type'     => $detectedMime,
            'extension'     => $extension,
            'size_bytes'    => $finalSize,
            'width'         => $width,
            'height'        => $height,
            'uploader_type' => $uploaderType,
            'uploader_id'   => $uploaderId,
            'checksum'      => (string) (@md5_file($destination) ?: ''),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return [
            'id'     => $mediaId,
            'url'    => $url,
            'path'   => $relativePath,
            'mime'   => $detectedMime,
            'size'   => $finalSize,
            'width'  => $width,
            'height' => $height,
        ];
    }

    /**
     * Re-encode an image through GD.
     *
     * This is the single most valuable step: decoding and re-encoding
     * discards EXIF, any appended PHP payload and any polyglot structure,
     * because only pixel data survives the round trip.
     *
     * @return array{0:int,1:int} Final width and height.
     */
    private static function processImage(string $source, string $destination, string $extension): array
    {
        if (!extension_loaded('gd')) {
            // Without GD we cannot sanitise; storing the raw bytes under a
            // random name in a no-exec directory is still safe.
            if (!move_uploaded_file($source, $destination)) {
                throw HttpException::server('Failed to store the uploaded image.');
            }

            $dimensions = @getimagesize($destination);

            return [$dimensions === false ? 0 : (int) $dimensions[0], $dimensions === false ? 0 : (int) $dimensions[1]];
        }

        $image = match ($extension) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($source),
            'png'         => @imagecreatefrompng($source),
            'webp'        => @imagecreatefromwebp($source),
            default       => false,
        };

        if ($image === false) {
            throw HttpException::unprocessable('The image could not be read. It may be corrupt.');
        }

        $originalWidth  = imagesx($image);
        $originalHeight = imagesy($image);
        $maxDimension   = Config::settingInt('upload', 'image_max_dimension', 2048);
        $quality        = Config::settingInt('upload', 'image_quality', 82);

        $width  = $originalWidth;
        $height = $originalHeight;

        if ($originalWidth > $maxDimension || $originalHeight > $maxDimension) {
            $scale  = min($maxDimension / $originalWidth, $maxDimension / $originalHeight);
            $width  = max(1, (int) round($originalWidth * $scale));
            $height = max(1, (int) round($originalHeight * $scale));
        }

        $canvas = imagecreatetruecolor($width, $height);

        if ($extension === 'png' || $extension === 'webp') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
        }

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, $originalWidth, $originalHeight);
        imagedestroy($image);

        $written = match ($extension) {
            'jpg', 'jpeg' => imagejpeg($canvas, $destination, $quality),
            'png'         => imagepng($canvas, $destination, 6),
            'webp'        => imagewebp($canvas, $destination, $quality),
            default       => false,
        };

        imagedestroy($canvas);

        if ($written === false) {
            throw HttpException::server('Failed to write the processed image.');
        }

        return [$width, $height];
    }

    /** Delete a media record and its file. */
    public static function delete(int $mediaId, string $requesterType = 'admin', ?int $requesterId = null): bool
    {
        $media = Database::selectOne('SELECT * FROM `media` WHERE `id` = :id', ['id' => $mediaId]);

        if ($media === null) {
            return false;
        }

        // A user may only remove their own uploads; admins may remove any.
        if ($requesterType === 'user' && (int) $media['uploader_id'] !== (int) $requesterId) {
            throw HttpException::forbidden('You can only delete your own uploads.');
        }

        $absolute = rtrim((string) Config::get('storage.upload_path'), '/\\') . '/' . (string) $media['path'];

        if (is_file($absolute)) {
            @unlink($absolute);
        }

        Database::update('media', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $mediaId]);

        return true;
    }

    /**
     * Permanently remove a media record and its file.
     *
     * delete() leaves a soft-deleted row behind, which the media library then
     * offers to restore — with its bytes already gone. Use this instead for a
     * file nothing refers to any more, such as a replaced profile photo.
     */
    public static function purge(int $mediaId): void
    {
        $path = Database::scalar('SELECT `path` FROM `media` WHERE `id` = :id', ['id' => $mediaId]);

        if ($path === null) {
            return;
        }

        // Refuse to touch anything outside the uploads root, even if a path
        // column were ever tampered with.
        $root   = realpath(rtrim((string) Config::get('storage.upload_path'), '/\\'));
        $target = $root === false
            ? false
            : realpath($root . DIRECTORY_SEPARATOR . str_replace(['\\', '..'], ['/', ''], (string) $path));

        if ($target !== false && str_starts_with($target, $root . DIRECTORY_SEPARATOR) && is_file($target)) {
            @unlink($target);
        }

        Database::execute('DELETE FROM `media` WHERE `id` = :id', ['id' => $mediaId]);
    }

    /**
     * Like purge(), but the row goes only once the file is gone, so a file
     * that cannot be deleted is never left behind with nothing pointing at
     * it. Account deletion uses it and retries what is left.
     *
     * Gone means unlinked now, already absent, or outside the uploads root
     * (never ours to touch).
     *
     * @return bool true when nothing is left (or the row did not exist);
     *              false, with the row kept, when the file could not be deleted.
     */
    public static function purgeFile(int $mediaId): bool
    {
        $path = Database::scalar('SELECT `path` FROM `media` WHERE `id` = :id', ['id' => $mediaId]);

        if ($path === null) {
            return true;
        }

        $root   = realpath(rtrim((string) Config::get('storage.upload_path'), '/\\'));
        $target = $root === false
            ? false
            : realpath($root . DIRECTORY_SEPARATOR . str_replace(['\\', '..'], ['/', ''], (string) $path));

        if ($target !== false && str_starts_with($target, $root . DIRECTORY_SEPARATOR) && is_file($target)) {
            @unlink($target);
            clearstatcache(true, $target);

            if (is_file($target)) {
                Logger::warning('An uploaded file could not be deleted; its media row is kept', ['media_id' => $mediaId]);

                return false;
            }
        }

        Database::execute('DELETE FROM `media` WHERE `id` = :id', ['id' => $mediaId]);

        return true;
    }

    /**
     * @param  array<string,mixed> $file
     */
    private static function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        throw HttpException::unprocessable(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the maximum upload size.',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE                        => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the file.',
            UPLOAD_ERR_EXTENSION                      => 'The upload was blocked by the server.',
            default                                   => 'The upload failed.',
        });
    }

    /**
     * Allowed extensions and the size ceiling in bytes for a kind of upload,
     * so a form can describe the same limits store() enforces.
     *
     * @return array{0:list<string>,1:int}
     */
    public static function limitsFor(string $kind): array
    {
        return match ($kind) {
            'video' => [
                array_map('strval', Config::settingArray('upload', 'allowed_video_types', ['mp4', 'mov', 'webm'])),
                Config::settingInt('upload', 'max_video_mb', 50) * 1048576,
            ],
            'document' => [
                array_map('strval', Config::settingArray('upload', 'allowed_document_types', ['pdf', 'jpg', 'jpeg', 'png'])),
                Config::settingInt('upload', 'max_document_mb', 10) * 1048576,
            ],
            default => [
                array_map('strval', Config::settingArray('upload', 'allowed_image_types', ['jpg', 'jpeg', 'png', 'webp'])),
                Config::settingInt('upload', 'max_image_mb', 5) * 1048576,
            ],
        };
    }

    /**
     * Drop an .htaccess into the uploads root that refuses to execute any
     * script, whatever ends up in there.
     */
    private static function hardenDirectory(string $root): void
    {
        $htaccess = $root . '/.htaccess';

        if (is_file($htaccess)) {
            return;
        }

        @file_put_contents($htaccess, <<<'CONF'
# Uploaded files are data, never code.
php_flag engine off
AddType text/plain .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .pl .py .jsp .asp .sh .cgi

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule \.(php[0-9]?|phtml|phar|pl|py|jsp|asp|sh|cgi)$ - [F,L,NC]
</IfModule>

<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set Content-Security-Policy "default-src 'none'; img-src 'self'; media-src 'self'"
</IfModule>

Options -Indexes -ExecCGI
CONF);
    }
}

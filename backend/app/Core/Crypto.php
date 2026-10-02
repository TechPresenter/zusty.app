<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Authenticated symmetric encryption for data at rest (KYC numbers, bank
 * account numbers, gateway secrets) plus small hashing/compare helpers.
 *
 * Format: base64( iv[12] || tag[16] || ciphertext )
 * Cipher: AES-256-GCM — confidentiality *and* integrity, so a tampered
 * ciphertext fails to decrypt rather than silently returning garbage.
 */
final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    private static ?string $key = null;

    /** Resolve the 32-byte binary key from APP_ENCRYPTION_KEY (64 hex chars). */
    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $raw = (string) Config::get('app.encryption_key', '');

        if ($raw === '') {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY is not set. Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        // Accept hex (preferred), base64, or a raw 32-byte string.
        if (strlen($raw) === 64 && ctype_xdigit($raw)) {
            $key = (string) hex2bin($raw);
        } elseif (strlen($raw) === 32) {
            $key = $raw;
        } else {
            $decoded = base64_decode($raw, true);
            $key     = ($decoded !== false && strlen($decoded) === 32) ? $decoded : hash('sha256', $raw, true);
        }

        return self::$key = $key;
    }

    /** Encrypt a plaintext string. Returns base64. */
    public static function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /** Decrypt a value produced by encrypt(). Returns null if invalid. */
    public static function decrypt(string $payload): ?string
    {
        $binary = base64_decode($payload, true);

        if ($binary === false || strlen($binary) <= self::IV_LEN + self::TAG_LEN) {
            return null;
        }

        $iv         = substr($binary, 0, self::IV_LEN);
        $tag        = substr($binary, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($binary, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? null : $plaintext;
    }

    /** Encrypt only when a value is present, so NULLs stay NULL. */
    public static function encryptNullable(?string $plaintext): ?string
    {
        return ($plaintext === null || $plaintext === '') ? null : self::encrypt($plaintext);
    }

    public static function decryptNullable(?string $payload): ?string
    {
        return ($payload === null || $payload === '') ? null : self::decrypt($payload);
    }

    // -----------------------------------------------------------------
    // Hashing / tokens
    // -----------------------------------------------------------------

    /** Hash a password with the strongest algorithm available. */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function passwordNeedsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Deterministic SHA-256 used for token lookups (never for passwords). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Cryptographically secure URL-safe random token. */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Zero-padded numeric OTP. */
    public static function randomOtp(int $length = 6): string
    {
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    public static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Constant-time comparison — always use this for secrets/signatures. */
    public static function secureEquals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    /** HMAC-SHA256 signature (webhook verification, signed URLs). */
    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verifySignature(string $payload, string $signature, string $secret): bool
    {
        return hash_equals(self::sign($payload, $secret), $signature);
    }

    /**
     * Keyed, deterministic hash of an identifier ("blind index").
     *
     * Lets the application ask "is this PAN / Aadhaar / GSTIN already on
     * another account?" with an indexed equality lookup, without decrypting
     * every row and without storing anything a leaked database could reverse:
     * a plain SHA-256 of a 12-digit Aadhaar is brute-forced in minutes, an
     * HMAC under a key the database never sees is not.
     *
     * The key is derived from APP_ENCRYPTION_KEY with HKDF and separated by
     * [$domain], so the same digits hashed as two different identifier types
     * never collide, and a hash column can never be used as an oracle for the
     * encryption key itself. key() throws when no key is configured, so this
     * fails closed rather than falling back to an unkeyed hash.
     */
    public static function blindIndex(string $domain, string $value): string
    {
        $subKey = hash_hkdf('sha256', self::key(), 32, 'zuste-blind-index|' . $domain);

        return hash_hmac('sha256', $value, $subKey);
    }

    /** Mask an identifier for display, e.g. "XXXXXX4567". */
    public static function mask(string $value, int $visible = 4, string $maskChar = 'X'): string
    {
        $length = strlen($value);
        if ($length <= $visible) {
            return str_repeat($maskChar, $length);
        }

        return str_repeat($maskChar, $length - $visible) . substr($value, -$visible);
    }
}

<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * RFC 6238 time-based one-time passwords, with no dependencies.
 *
 * Compatible with Google Authenticator, Authy, 1Password and any other
 * standard TOTP app: SHA-1, 6 digits, 30-second period.
 *
 * A ±1 window is accepted so a slightly skewed phone clock still works,
 * which is the standard trade-off; anything wider materially weakens the
 * second factor.
 */
final class Totp
{
    private const PERIOD    = 30;
    private const DIGITS    = 6;
    private const ALGORITHM = 'sha1';
    private const WINDOW    = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Generate a new base32 shared secret (160 bits). */
    public static function generateSecret(int $length = 32): string
    {
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    /** Current code for a secret — used only in tests and diagnostics. */
    public static function codeAt(string $secret, ?int $timestamp = null): string
    {
        $counter = (int) floor(($timestamp ?? time()) / self::PERIOD);

        return self::hotp($secret, $counter);
    }

    /**
     * Verify a submitted code against the accepted time window.
     *
     * Comparison is constant time so the check cannot be used as a timing
     * oracle to recover a valid code digit by digit.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = (int) floor(($timestamp ?? time()) / self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals(self::hotp($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * otpauth:// URI for the QR code.
     *
     * @param string $account Usually the admin's email address.
     * @param string $issuer  Shown as the account group in the authenticator.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            strtoupper(self::ALGORITHM),
            self::DIGITS,
            self::PERIOD
        );
    }

    /**
     * Render the provisioning URI as an inline SVG QR code.
     *
     * Generating the QR locally matters: sending the shared secret to a
     * third-party chart API would hand the second factor to that service.
     */
    public static function qrCodeSvg(string $text, int $moduleSize = 5): string
    {
        $matrix = QrCode::encode($text);
        $size   = count($matrix);
        $quiet  = 4;
        $total  = ($size + $quiet * 2) * $moduleSize;

        $rects = '';

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x] !== 1) {
                    continue;
                }

                $rects .= sprintf(
                    '<rect x="%d" y="%d" width="%d" height="%d"/>',
                    ($x + $quiet) * $moduleSize,
                    ($y + $quiet) * $moduleSize,
                    $moduleSize,
                    $moduleSize
                );
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" '
            . 'shape-rendering="crispEdges" role="img" aria-label="Two-factor QR code">'
            . '<rect width="%d" height="%d" fill="#ffffff"/><g fill="#000000">%s</g></svg>',
            $total, $total, $total, $total, $total, $total, $rects
        );
    }

    /**
     * Single-use recovery codes, returned in plain text once so they can be
     * shown to the operator. Only the hashes are ever stored.
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            // Grouped for readability: XXXX-XXXX
            $codes[] = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
        }

        return $codes;
    }

    /**
     * The canonical form of a recovery code, for hashing and comparison.
     *
     * Codes are *displayed* grouped (`XXXX-XXXX`) because that is far easier to
     * copy off a screen accurately, but the separator is presentation only. It
     * must be stripped on both sides of the comparison or the two never match —
     * which is exactly what happened: enrolment hashed the hyphenated string
     * while redemption hashed the stripped one, so no recovery code could ever
     * be redeemed. Normalising in one place is what stops that recurring.
     *
     * Also tolerates however the operator retypes it: lower case, spaced, or
     * with the hyphen omitted.
     */
    public static function normaliseRecoveryCode(string $code): string
    {
        return strtoupper(trim(str_replace([' ', '-', '_'], '', $code)));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private static function hotp(string $secret, int $counter): string
    {
        $key    = self::base32Decode($secret);
        $binary = pack('N*', 0) . pack('N*', $counter); // 64-bit big-endian counter
        $hash   = hash_hmac(self::ALGORITHM, $binary, $key, true);

        // Dynamic truncation, per RFC 4226 §5.4.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
               | ((ord($hash[$offset + 1]) & 0xFF) << 16)
               | ((ord($hash[$offset + 2]) & 0xFF) << 8)
               | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string
    {
        $secret = rtrim(strtoupper($secret), '=');

        if ($secret === '') {
            return '';
        }

        $buffer = '';

        foreach (str_split($secret) as $character) {
            $position = strpos(self::BASE32_ALPHABET, $character);

            if ($position === false) {
                continue; // Ignore separators a user may have pasted.
            }

            $buffer .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($buffer, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}

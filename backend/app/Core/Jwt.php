<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * HS256 JSON Web Tokens — encode, decode and verify with no dependencies.
 *
 * Hardening applied:
 *  - the `alg` header is pinned to HS256, so the "alg: none" and
 *    algorithm-confusion attacks are impossible;
 *  - signatures are compared in constant time;
 *  - `exp`, `nbf` and `iat` are validated with a small leeway;
 *  - `iss` and `aud` are validated against configuration;
 *  - every token carries a `jti` so it can be revoked via `token_blacklist`.
 */
final class Jwt
{
    private const ALGORITHM = 'HS256';
    private const LEEWAY    = 30; // seconds of clock skew tolerated

    /**
     * Issue an access token.
     *
     * @param array<string,mixed> $claims Additional public claims.
     */
    public static function issueAccessToken(int $userId, string $accountType, array $claims = []): string
    {
        $ttl = (int) Config::get('jwt.access_ttl', 3600);
        $now = time();

        return self::encode(array_merge($claims, [
            'sub'  => (string) $userId,
            'typ'  => 'access',
            'role' => $accountType,
            'jti'  => Crypto::uuid4(),
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + $ttl,
            'iss'  => (string) Config::get('jwt.issuer', ''),
            'aud'  => (string) Config::get('jwt.audience', ''),
        ]));
    }

    /**
     * Encode an arbitrary payload.
     *
     * @param array<string,mixed> $payload
     */
    public static function encode(array $payload, ?string $secret = null): string
    {
        $secret ??= self::secret();

        $header = self::base64UrlEncode(
            (string) json_encode(['typ' => 'JWT', 'alg' => self::ALGORITHM], JSON_UNESCAPED_SLASHES)
        );
        $body = self::base64UrlEncode(
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $signature = self::base64UrlEncode(
            hash_hmac('sha256', $header . '.' . $body, $secret, true)
        );

        return $header . '.' . $body . '.' . $signature;
    }

    /**
     * Verify and decode a token.
     *
     * @return array<string,mixed> The validated payload.
     * @throws TokenException on any structural, signature or claim failure.
     */
    public static function decode(string $token, ?string $secret = null): array
    {
        $secret ??= self::secret();

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new TokenException('Malformed token.');
        }

        [$header64, $payload64, $signature64] = $parts;

        $headerJson = self::base64UrlDecode($header64);
        $header     = json_decode($headerJson, true);

        if (!is_array($header)) {
            throw new TokenException('Malformed token header.');
        }

        // Pin the algorithm: never trust the header to choose it for us.
        if (($header['alg'] ?? '') !== self::ALGORITHM) {
            throw new TokenException('Unsupported token algorithm.');
        }

        $expected = self::base64UrlEncode(
            hash_hmac('sha256', $header64 . '.' . $payload64, $secret, true)
        );

        if (!hash_equals($expected, $signature64)) {
            throw new TokenException('Invalid token signature.');
        }

        $payload = json_decode(self::base64UrlDecode($payload64), true);
        if (!is_array($payload)) {
            throw new TokenException('Malformed token payload.');
        }

        $now = time();

        if (isset($payload['nbf']) && $now + self::LEEWAY < (int) $payload['nbf']) {
            throw new TokenException('Token is not yet valid.');
        }

        if (isset($payload['iat']) && $now + self::LEEWAY < (int) $payload['iat']) {
            throw new TokenException('Token issued in the future.');
        }

        if (!isset($payload['exp'])) {
            throw new TokenException('Token has no expiry.');
        }

        if ($now - self::LEEWAY >= (int) $payload['exp']) {
            throw new TokenException('Token has expired.', true);
        }

        $issuer = (string) Config::get('jwt.issuer', '');
        if ($issuer !== '' && isset($payload['iss']) && $payload['iss'] !== $issuer) {
            throw new TokenException('Token issuer mismatch.');
        }

        $audience = (string) Config::get('jwt.audience', '');
        if ($audience !== '' && isset($payload['aud']) && $payload['aud'] !== $audience) {
            throw new TokenException('Token audience mismatch.');
        }

        return $payload;
    }

    /** Read a payload without verifying — for logging/debugging only. */
    public static function peek(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($parts[1]), true);

        return is_array($payload) ? $payload : null;
    }

    /** Has this token id been explicitly revoked? */
    public static function isRevoked(string $jti): bool
    {
        if ($jti === '') {
            return false;
        }

        return Database::scalar(
            'SELECT 1 FROM `token_blacklist` WHERE `jti` = :jti AND `expires_at` > NOW() LIMIT 1',
            ['jti' => $jti]
        ) !== null;
    }

    /** Revoke a single access token by its jti. */
    public static function revoke(string $jti, int $userId, int $expiresAt): void
    {
        if ($jti === '') {
            return;
        }

        Database::execute(
            'INSERT INTO `token_blacklist` (`jti`, `user_id`, `expires_at`)
             VALUES (:jti, :user_id, :expires_at)
             ON DUPLICATE KEY UPDATE `expires_at` = VALUES(`expires_at`)',
            [
                'jti'        => $jti,
                'user_id'    => $userId,
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            ]
        );
    }

    /** Remove expired blacklist rows (called by the tokens:prune job). */
    public static function pruneBlacklist(): int
    {
        return Database::execute('DELETE FROM `token_blacklist` WHERE `expires_at` <= NOW()');
    }

    private static function secret(): string
    {
        $secret = (string) Config::get('jwt.secret', '');

        if (strlen($secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET must be at least 32 characters. Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        return $secret;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded  = str_pad(strtr($data, '-_', '+/'), (int) (ceil(strlen($data) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }
}

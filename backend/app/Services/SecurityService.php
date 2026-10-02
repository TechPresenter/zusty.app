<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Core\Request;
use Zuste\Core\Totp;

/**
 * Account-security operations: the login audit trail, two-factor enrolment
 * and the administrative IP allow list.
 *
 * These sit in one service because they share a single question — "should this
 * sign-in be trusted?" — and the answer needs all three signals together.
 */
final class SecurityService
{
    /** Outcomes accepted by `login_history`.`outcome`. */
    public const OUTCOME_SUCCESS     = 'success';
    public const OUTCOME_BAD_CREDS   = 'bad_credentials';
    public const OUTCOME_LOCKED      = 'locked';
    public const OUTCOME_SUSPENDED   = 'suspended';
    public const OUTCOME_THROTTLED   = 'throttled';
    public const OUTCOME_2FA_NEEDED  = '2fa_required';
    public const OUTCOME_2FA_FAILED  = '2fa_failed';
    /** The request was refused because an operator switched the method off. */
    public const OUTCOME_METHOD_DISABLED = 'method_disabled';
    /** The identity provider itself failed or rejected the exchange. */
    public const OUTCOME_PROVIDER_ERROR  = 'provider_error';

    /** The value `login_history`.`auth_method` uses for a second factor. */
    public const METHOD_2FA = '2fa';

    /**
     * Every value `login_history`.`auth_method` will accept.
     *
     * The column is an ENUM, so a value outside this set makes MySQL reject the
     * whole INSERT — and because recordLogin() swallows its own failures to
     * avoid blocking a sign-in, the audit row simply vanished. That is exactly
     * what happened to every two-factor sign-in: the caller passed
     * 'two_factor', nothing complained, and neither the successes nor the
     * brute-force attempts were ever recorded.
     */
    private const AUTH_METHODS = [
        'password', 'google', 'apple', 'phone_otp', 'refresh',
        self::METHOD_2FA, 'email_otp', 'facebook', 'guest', 'magic_link',
    ];

    // -----------------------------------------------------------------
    // Login audit trail
    // -----------------------------------------------------------------

    /**
     * Append a sign-in attempt to the audit trail.
     *
     * Never throws: a logging failure must not be able to block a legitimate
     * sign-in, nor to convert a failed one into a server error that leaks
     * whether the account exists.
     */
    public static function recordLogin(
        string $actorType,
        ?int $actorId,
        string $identifier,
        string $outcome,
        string $authMethod,
        ?Request $request = null,
        string $failureReason = '',
        // App-user sign-ins run inside AuthService, which never sees a Request —
        // its IP and user agent arrive in the device context the API controller
        // assembled. Optional and last, so all seven existing admin call sites
        // are unaffected.
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        try {
            $ip        = $ip        ?? $request?->ip()        ?? '';
            $userAgent = $userAgent ?? $request?->userAgent() ?? '';

            // Degrade instead of losing the row: an unrecognised method falls
            // back to the column's own default and the value that was asked for
            // is kept in the reason, so a typo costs a label rather than the
            // whole audit entry.
            if (!in_array($authMethod, self::AUTH_METHODS, true)) {
                Logger::warning('Unknown login auth_method; recording as "password"', ['auth_method' => $authMethod]);

                $failureReason = trim($failureReason . ' [auth_method: ' . $authMethod . ']');
                $authMethod    = 'password';
            }

            Database::insert('login_history', [
                'actor_type'     => $actorType === 'user' ? 'user' : 'admin',
                'actor_id'       => $actorId !== null && $actorId > 0 ? $actorId : null,
                // Truncate rather than reject: the column is an audit field, and
                // an over-long identifier is itself worth recording.
                'identifier'     => mb_substr($identifier, 0, 191),
                'outcome'        => $outcome,
                'auth_method'    => $authMethod,
                'ip_address'     => $ip !== '' ? mb_substr($ip, 0, 45) : null,
                'user_agent'     => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
                'device_type'    => self::deviceType($userAgent),
                'is_new_device'  => $outcome === self::OUTCOME_SUCCESS && $actorId !== null
                    ? (int) self::isNewDevice($actorType, $actorId, $userAgent)
                    : 0,
                'failure_reason' => $failureReason !== '' ? mb_substr($failureReason, 0, 191) : null,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Could not record login history', ['error' => $e->getMessage()]);
        }
    }

    /** Coarse device class derived from the user agent. */
    public static function deviceType(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'unknown';
        }

        $ua = strtolower($userAgent);

        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
            return 'mobile';
        }

        if (str_contains($ua, 'okhttp') || str_contains($ua, 'dart') || str_contains($ua, 'curl') || str_contains($ua, 'postman')) {
            return 'api';
        }

        if (str_contains($ua, 'mozilla') || str_contains($ua, 'chrome') || str_contains($ua, 'safari')) {
            return 'desktop';
        }

        return 'unknown';
    }

    /** True when this actor has never signed in successfully from this agent before. */
    private static function isNewDevice(string $actorType, int $actorId, string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        $seen = Database::scalar(
            "SELECT COUNT(*) FROM `login_history`
              WHERE `actor_type` = :type AND `actor_id` = :id
                AND `outcome` = 'success' AND `user_agent` = :ua",
            ['type' => $actorType, 'id' => $actorId, 'ua' => mb_substr($userAgent, 0, 255)]
        );

        return (int) $seen === 0;
    }

    // -----------------------------------------------------------------
    // IP allow list
    // -----------------------------------------------------------------

    /**
     * Is this address allowed to reach the given scope?
     *
     * An empty allow list permits everything. That is deliberate: locking an
     * administrator out of their own panel because they added no entries yet
     * would be a worse failure than the protection is worth. The list only
     * starts restricting once someone opts in by adding a first entry.
     */
    public static function ipAllowed(string $ip, string $scope = 'admin'): bool
    {
        if ($ip === '') {
            return true;
        }

        $rows = Database::select(
            "SELECT `id`, `ip_address` FROM `ip_whitelist`
              WHERE `is_enabled` = 1
                AND `scope` IN (:scope, 'both')
                AND (`expires_at` IS NULL OR `expires_at` > NOW())",
            ['scope' => $scope === 'api' ? 'api' : 'admin']
        );

        if ($rows === []) {
            return true;
        }

        foreach ($rows as $row) {
            if (self::ipMatches($ip, (string) $row['ip_address'])) {
                // Best-effort usage tracking; never block the request on it.
                try {
                    Database::execute(
                        'UPDATE `ip_whitelist` SET `hit_count` = `hit_count` + 1, `last_seen_at` = NOW() WHERE `id` = :id',
                        ['id' => (int) $row['id']]
                    );
                } catch (\Throwable) {
                    // Ignored on purpose.
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Match an address against an allow-list entry.
     *
     * Supports an exact address, CIDR notation (`203.0.113.0/24`) and a
     * trailing wildcard (`203.0.113.*`).
     */
    public static function ipMatches(string $ip, string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '' || $pattern === '*') {
            return true;
        }

        if (strcasecmp($ip, $pattern) === 0) {
            return true;
        }

        if (str_contains($pattern, '/')) {
            return self::cidrMatches($ip, $pattern);
        }

        if (str_ends_with($pattern, '*')) {
            return str_starts_with($ip, rtrim($pattern, '*'));
        }

        return false;
    }

    /** CIDR containment for both IPv4 and IPv6. */
    private static function cidrMatches(string $ip, string $cidr): bool
    {
        [$subnet, $bitsRaw] = array_pad(explode('/', $cidr, 2), 2, '');

        $packedIp     = @inet_pton($ip);
        $packedSubnet = @inet_pton($subnet);

        if ($packedIp === false || $packedSubnet === false || strlen($packedIp) !== strlen($packedSubnet)) {
            return false;
        }

        $bits = (int) $bitsRaw;
        $max  = strlen($packedIp) * 8;

        if ($bits < 0 || $bits > $max) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainder  = $bits % 8;

        if ($wholeBytes > 0 && strncmp($packedIp, $packedSubnet, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedSubnet[$wholeBytes]) & $mask);
    }

    // -----------------------------------------------------------------
    // Two-factor authentication
    // -----------------------------------------------------------------

    /**
     * Begin enrolment: generate a secret and stash it unconfirmed.
     *
     * The secret is stored encrypted and `two_factor_enabled` stays 0 until the
     * admin proves they can generate a code, so a half-finished enrolment can
     * never lock anyone out.
     *
     * @return array{secret:string,uri:string,svg:string}
     */
    public static function beginTwoFactorEnrolment(int $adminId, string $email): array
    {
        $secret = Totp::generateSecret();
        $issuer = Config::settingString('general', 'app_name', 'Zusty') . ' Admin';

        Database::update('admins', [
            'two_factor_secret'       => Crypto::encrypt($secret),
            'two_factor_enabled'      => 0,
            'two_factor_confirmed_at' => null,
        ], ['id' => $adminId]);

        $uri = Totp::provisioningUri($secret, $email, $issuer);

        return ['secret' => $secret, 'uri' => $uri, 'svg' => Totp::qrCodeSvg($uri)];
    }

    /**
     * Finish enrolment by verifying a code against the pending secret.
     *
     * @return list<string> freshly generated recovery codes (shown once)
     */
    public static function confirmTwoFactorEnrolment(int $adminId, string $code): array
    {
        $secret = self::secretFor($adminId);

        if ($secret === null || !Totp::verify($secret, $code)) {
            return [];
        }

        $codes  = Totp::generateRecoveryCodes();
        $now    = date('Y-m-d H:i:s');

        Database::transaction(static function () use ($adminId, $codes, $now): void {
            Database::update('admins', [
                'two_factor_enabled'      => 1,
                'two_factor_confirmed_at' => $now,
            ], ['id' => $adminId]);

            Database::execute('DELETE FROM `admin_recovery_codes` WHERE `admin_id` = :id', ['id' => $adminId]);

            foreach ($codes as $recovery) {
                Database::insert('admin_recovery_codes', [
                    'admin_id' => $adminId,
                    // Hash the canonical form — the hyphen in the displayed
                    // code is presentation, and hashing it here while stripping
                    // it at redemption is what made every recovery code
                    // permanently unusable.
                    'code_hash'  => Crypto::hashToken(Totp::normaliseRecoveryCode($recovery)),
                    'created_at' => $now,
                ]);
            }
        });

        return $codes;
    }

    /** Turn 2FA off and discard the secret and every recovery code. */
    public static function disableTwoFactor(int $adminId): void
    {
        Database::transaction(static function () use ($adminId): void {
            Database::update('admins', [
                'two_factor_secret'       => null,
                'two_factor_enabled'      => 0,
                'two_factor_confirmed_at' => null,
            ], ['id' => $adminId]);

            Database::execute('DELETE FROM `admin_recovery_codes` WHERE `admin_id` = :id', ['id' => $adminId]);
        });
    }

    /** Verify a six-digit code for an admin who has completed enrolment. */
    public static function verifyTwoFactorCode(int $adminId, string $code): bool
    {
        $secret = self::secretFor($adminId);

        return $secret !== null && Totp::verify($secret, $code);
    }

    /**
     * Consume a single-use recovery code.
     *
     * Each code is marked used inside the same statement that claims it, so two
     * concurrent attempts cannot both succeed with the same code.
     */
    public static function consumeRecoveryCode(int $adminId, string $code): bool
    {
        $normalised = Totp::normaliseRecoveryCode($code);

        if ($normalised === '') {
            return false;
        }

        $claim = static function (string $candidate) use ($adminId): int {
            return Database::execute(
                'UPDATE `admin_recovery_codes`
                    SET `used_at` = NOW()
                  WHERE `admin_id` = :id AND `code_hash` = :hash AND `used_at` IS NULL',
                ['id' => $adminId, 'hash' => Crypto::hashToken($candidate)]
            );
        };

        if ($claim($normalised) > 0) {
            return true;
        }

        // Codes enrolled before the normalisation fix were stored hashed WITH
        // the display hyphen. Re-hashing them is impossible — only the hash was
        // kept — so the grouped form is tried as a fallback. Without this, every
        // admin who enrolled earlier would be locked out of their own recovery
        // codes by a fix meant to repair them.
        if (strlen($normalised) === 8) {
            return $claim(substr($normalised, 0, 4) . '-' . substr($normalised, 4)) > 0;
        }

        return false;
    }

    /** How many unused recovery codes remain. */
    public static function recoveryCodesRemaining(int $adminId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `admin_recovery_codes` WHERE `admin_id` = :id AND `used_at` IS NULL',
            ['id' => $adminId]
        );
    }

    /** Decrypt the stored TOTP secret, or null when none is set. */
    private static function secretFor(int $adminId): ?string
    {
        $stored = Database::scalar(
            'SELECT `two_factor_secret` FROM `admins` WHERE `id` = :id',
            ['id' => $adminId]
        );

        if (!is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypto::decrypt($stored);
        } catch (\Throwable $e) {
            Logger::error('Could not decrypt a 2FA secret', ['admin_id' => $adminId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // -----------------------------------------------------------------
    // Sessions
    // -----------------------------------------------------------------

    /**
     * Active admin sessions, newest activity first.
     *
     * @return list<array<string,mixed>>
     */
    public static function activeSessions(int $idleMinutes = 120): array
    {
        return Database::select(
            'SELECT s.`id`, s.`admin_id`, s.`ip_address`, s.`user_agent`, s.`last_activity`, s.`created_at`,
                    a.`name` AS `admin_name`, a.`email` AS `admin_email`, r.`name` AS `role_name`
               FROM `admin_sessions` s
               INNER JOIN `admins` a ON a.`id` = s.`admin_id`
               INNER JOIN `roles`  r ON r.`id` = a.`role_id`
              WHERE s.`last_activity` >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
              ORDER BY s.`last_activity` DESC
              LIMIT 200',
            ['minutes' => $idleMinutes]
        );
    }

    /** Revoke one session by its hashed id. Returns true when a row was removed. */
    public static function revokeSession(string $sessionId): bool
    {
        return Database::execute('DELETE FROM `admin_sessions` WHERE `id` = :id', ['id' => $sessionId]) > 0;
    }

    /** Revoke every session for an admin except optionally the current one. */
    public static function revokeAllSessions(int $adminId, ?string $exceptSessionId = null): int
    {
        if ($exceptSessionId !== null) {
            return Database::execute(
                'DELETE FROM `admin_sessions` WHERE `admin_id` = :id AND `id` <> :keep',
                ['id' => $adminId, 'keep' => $exceptSessionId]
            );
        }

        return Database::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = :id', ['id' => $adminId]);
    }
}

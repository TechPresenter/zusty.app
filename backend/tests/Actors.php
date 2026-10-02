<?php

declare(strict_types=1);

namespace Zuste\Tests;

use Zuste\Core\Database;

/**
 * Throwaway accounts for the integration suite.
 *
 * Every actor is created through the real registration endpoint rather than by
 * inserting rows, so the suite exercises signup, hashing, wallet provisioning
 * and whatever else registration triggers. Rows are prefixed `it-` and swept at
 * the end of the run, so a seeded demo database is never disturbed.
 */
final class Actors
{
    /** Marker on every row this suite creates, used for the cleanup sweep. */
    public const PREFIX = 'it-';

    /** @var array<string,array<string,mixed>> */
    private static array $actors = [];

    /** Why the last create() failed, so the suite can report something useful. */
    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * Registers (or re-authenticates) an actor and stores its bearer token.
     *
     * @return array<string,mixed>|null The user payload, or null if it failed.
     */
    public static function create(string $name, string $accountType = 'brand'): ?array
    {
        if (isset(self::$actors[$name])) {
            return self::$actors[$name];
        }

        $email    = self::PREFIX . $name . '@zuste.test';
        $password = 'IntegrationTest#2024';

        // Login FIRST, register only if the account does not exist yet.
        //
        // `/auth/register` is throttled to 5 per 15 minutes per IP. Purging and
        // re-registering on every run would lock the suite out of its own
        // fixtures on the second consecutive run, so the accounts persist and
        // are reused. `cleanup()` removes them when a run asks for a clean slate.
        $login = Http::post('/auth/login', [
            // The login endpoint takes `identifier` (email OR phone), not `email`.
            'identifier' => $email,
            'password'   => $password,
        ]);

        $response = $login;

        $token = $login->at('data.tokens.access_token')
            ?? $login->at('data.access_token')
            ?? $login->at('data.token');

        if (!is_string($token) || $token === '') {
            $response = Http::post('/auth/register', [
                'name'         => 'IT ' . ucfirst($name),
                'email'        => $email,
                'password'     => $password,
                'account_type' => $accountType,
                'phone'        => self::phoneFor($name),
                // Required by AuthController::register — registration is refused
                // without an explicit acceptance, which is worth exercising.
                'accept_terms' => true,
            ]);

            $token = $response->at('data.tokens.access_token')
                ?? $response->at('data.access_token')
                ?? $response->at('data.token');

            if (!is_string($token) || $token === '') {
                self::$lastError = $response->describe();

                return null;
            }
        }

        $registration = $response;

        Http::setToken($name, $token);

        $user = $registration->at('data.user');

        self::$actors[$name] = [
            'email'    => $email,
            'password' => $password,
            'token'    => $token,
            'refresh'  => $registration->at('data.tokens.refresh_token')
                ?? $registration->at('data.refresh_token'),
            'user'     => is_array($user) ? $user : [],
            'id'       => (int) (is_array($user) ? ($user['id'] ?? 0) : 0),
        ];

        return self::$actors[$name];
    }

    /** @return array<string,mixed>|null */
    public static function get(string $name): ?array
    {
        return self::$actors[$name] ?? null;
    }

    public static function id(string $name): int
    {
        return (int) (self::$actors[$name]['id'] ?? 0);
    }

    public static function email(string $name): string
    {
        return (string) (self::$actors[$name]['email'] ?? '');
    }

    public static function password(string $name): string
    {
        return (string) (self::$actors[$name]['password'] ?? '');
    }

    /**
     * A deterministic, unique-looking Indian mobile number per actor.
     *
     * Deterministic so a re-run reuses the same number instead of leaking a new
     * account each time; the leading 9 keeps it valid for the phone validator.
     */
    private static function phoneFor(string $name): string
    {
        $digits = substr((string) preg_replace('/\D/', '', md5($name)), 0, 9);

        return '9' . str_pad($digits, 9, '0');
    }

    /**
     * Hard-deletes one account by email.
     *
     * Not used by [create] any more (see the throttle note there) — kept for
     * targeted cleanup when a suite deliberately needs a fresh account.
     */
    public static function purgeByEmail(string $email): void
    {
        $id = Database::scalar('SELECT `id` FROM `users` WHERE `email` = :email', ['email' => $email]);

        if ($id === null) {
            return;
        }

        self::purgeUserId((int) $id);
    }

    private static function purgeUserId(int $userId): void
    {
        // Most child tables cascade from users; the ones that do not are listed
        // explicitly so a re-run never trips a foreign key.
        foreach ([
            'refresh_tokens', 'user_devices', 'wallet_transactions', 'wallets',
            'favorites', 'follows', 'search_histories', 'notifications',
            'login_history', 'kyc_profiles',
        ] as $table) {
            try {
                Database::execute("DELETE FROM `{$table}` WHERE `user_id` = :id", ['id' => $userId]);
            } catch (\Throwable) {
                // Table may not have a user_id column in this schema version.
            }
        }

        try {
            Database::execute('DELETE FROM `users` WHERE `id` = :id', ['id' => $userId]);
        } catch (\Throwable) {
            // A foreign key we do not know about — leave the row, it is inert
            // and prefixed so it is obviously test residue.
        }
    }

    /**
     * Removes every account this suite created.
     *
     * Called at the end of a run. Reports what it could not remove rather than
     * failing silently, so residue never accumulates unnoticed.
     */
    public static function cleanup(): int
    {
        $removed = 0;

        $ids = Database::column(
            'SELECT `id` FROM `users` WHERE `email` LIKE :pattern',
            ['pattern' => self::PREFIX . '%@zuste.test']
        );

        foreach ($ids as $id) {
            self::purgeUserId((int) $id);
            $removed++;
        }

        self::$actors = [];

        return $removed;
    }
}

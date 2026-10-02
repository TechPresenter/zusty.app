<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Hardened session handling for the admin panel.
 *
 * The API is stateless (JWT) — sessions exist only for the Core PHP admin UI.
 * Cookies are HttpOnly + Secure + SameSite, the id is regenerated on login to
 * prevent fixation, and an idle timeout invalidates abandoned sessions.
 */
final class Session
{
    private const KEY_FLASH        = '__flash';
    private const KEY_FLASH_NEXT   = '__flash_next';
    private const KEY_LAST_ACTIVE  = '__last_active';
    private const KEY_FINGERPRINT  = '__fingerprint';
    private const KEY_PREVIOUS_URL = '__previous_url';

    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        $lifetime = (int) Config::get('session.lifetime', 7200);

        session_name((string) Config::get('session.name', 'zuste_admin_session'));

        session_set_cookie_params([
            'lifetime' => 0, // Session cookie: dies with the browser.
            'path'     => (string) Config::get('session.path', '/'),
            'domain'   => '',
            'secure'   => (bool) Config::get('session.secure', true),
            'httponly' => true,
            'samesite' => (string) Config::get('session.same_site', 'Lax'),
        ]);

        ini_set('session.use_strict_mode', '1');   // Reject client-supplied ids.
        ini_set('session.use_only_cookies', '1');  // No session id in the URL.
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');

        session_start();
        self::$started = true;

        self::rotateFlash();
        self::enforceIdleTimeout($lifetime);
        self::enforceFingerprint();
    }

    // -----------------------------------------------------------------
    // Data access
    // -----------------------------------------------------------------

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        self::start();

        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function all(): array
    {
        self::start();

        return $_SESSION;
    }

    // -----------------------------------------------------------------
    // Flash messages (survive exactly one redirect)
    // -----------------------------------------------------------------

    public static function flash(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[self::KEY_FLASH_NEXT][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[self::KEY_FLASH][$key] ?? $default;
    }

    public static function hasFlash(string $key): bool
    {
        self::start();

        return isset($_SESSION[self::KEY_FLASH][$key]);
    }

    /** Queue a toast for the next page render. */
    public static function toast(string $type, string $message): void
    {
        self::start();
        $_SESSION[self::KEY_FLASH_NEXT]['toasts'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function toasts(): array
    {
        self::start();

        /** @var list<array{type:string,message:string}> $toasts */
        $toasts = $_SESSION[self::KEY_FLASH]['toasts'] ?? [];

        return $toasts;
    }

    /** @return array<string,list<string>> */
    public static function errors(): array
    {
        /** @var array<string,list<string>> $errors */
        $errors = self::getFlash('errors', []);

        return is_array($errors) ? $errors : [];
    }

    public static function oldInput(string $key, mixed $default = ''): mixed
    {
        $old = self::getFlash('old', []);

        return is_array($old) ? ($old[$key] ?? $default) : $default;
    }

    private static function rotateFlash(): void
    {
        $_SESSION[self::KEY_FLASH]      = $_SESSION[self::KEY_FLASH_NEXT] ?? [];
        $_SESSION[self::KEY_FLASH_NEXT] = [];
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    /** Call immediately after a successful login. */
    public static function regenerate(bool $deleteOld = true): void
    {
        self::start();
        session_regenerate_id($deleteOld);
        $_SESSION[self::KEY_FINGERPRINT] = self::fingerprint();
        $_SESSION[self::KEY_LAST_ACTIVE] = time();
    }

    public static function destroy(): void
    {
        self::start();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name() ?: 'zuste_admin_session', '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        self::$started = false;
    }

    public static function id(): string
    {
        self::start();

        return session_id() ?: '';
    }

    public static function rememberUrl(string $url): void
    {
        self::set(self::KEY_PREVIOUS_URL, $url);
    }

    public static function previousUrl(): ?string
    {
        $url = self::get(self::KEY_PREVIOUS_URL);

        return is_string($url) ? $url : null;
    }

    private static function enforceIdleTimeout(int $lifetime): void
    {
        $lastActive = (int) ($_SESSION[self::KEY_LAST_ACTIVE] ?? 0);

        if ($lastActive > 0 && (time() - $lastActive) > $lifetime) {
            self::destroy();
            self::start();
            self::flash('session_expired', true);

            return;
        }

        $_SESSION[self::KEY_LAST_ACTIVE] = time();
    }

    /**
     * Bind the session to a coarse client fingerprint so a stolen cookie is
     * far less useful. Deliberately excludes the IP: mobile networks change
     * it constantly and it would log legitimate users out.
     */
    private static function enforceFingerprint(): void
    {
        $current = self::fingerprint();
        $stored  = $_SESSION[self::KEY_FINGERPRINT] ?? null;

        if ($stored === null) {
            $_SESSION[self::KEY_FINGERPRINT] = $current;

            return;
        }

        if (!hash_equals((string) $stored, $current)) {
            Logger::warning('Session fingerprint mismatch — session destroyed.');
            self::destroy();
            self::start();
        }
    }

    private static function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
            (string) Config::get('app.encryption_key', ''),
        ]));
    }
}

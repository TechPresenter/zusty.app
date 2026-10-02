<?php

declare(strict_types=1);

namespace Zuste\Middleware;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\Session;
use Zuste\Services\PermissionService;

/**
 * Session authentication and permission checks for the admin panel.
 */
final class AdminAuthMiddleware
{
    public const SESSION_KEY = 'admin_id';

    /** Require an authenticated, active admin. */
    public static function handle(Request $request): void
    {
        $adminId = (int) Session::get(self::SESSION_KEY, 0);

        if ($adminId <= 0) {
            self::rejectUnauthenticated($request);
        }

        $admin = self::loadAdmin($adminId);

        if ($admin === null || $admin['status'] !== 'active') {
            Session::destroy();
            self::rejectUnauthenticated($request, 'Your admin account is no longer active.');
        }

        // The `admin_sessions` row is the revocation record, so its absence has
        // to end the request. Until this check existed the row was pure
        // bookkeeping: "Other sessions have been signed out" after a password
        // change, "Session revoked" in the Security Centre and the deletion on
        // a password reset all removed rows that nothing ever read, so the
        // other browser — or a stolen cookie — stayed signed in indefinitely
        // and renewed its idle window on every request.
        if (!self::touchSession($adminId)) {
            Session::destroy();
            self::rejectUnauthenticated($request, 'This session was signed out. Please sign in again.');
        }

        $request->setAttribute('admin', $admin);
        $request->setAttribute('permissions', PermissionService::forAdmin($adminId));

        // Force a password change before anything else is reachable.
        if ((int) $admin['must_change_password'] === 1
            && !str_starts_with($request->path(), '/admin/account/password')
            && !str_starts_with($request->path(), '/admin/logout')) {
            Session::toast('warning', 'Please set a new password before continuing.');
            Response::redirect('/admin/account/password');
        }

        self::enforceTwoFactorPolicy($request, $admin);
    }

    /**
     * Honour settings.security.require_2fa_for_admins.
     *
     * The switch described itself as "Blocks the panel until every admin
     * enrols" and was read by no code at all: an admin with
     * `two_factor_enabled` = 0 signed in and browsed the whole panel with the
     * flag on, so operators had an assurance the software did not provide.
     *
     * Modelled on the must_change_password gate directly above, including its
     * allow list. `/admin/account/password` stays reachable so a forced
     * password change can still be completed — without that the two gates
     * would bounce a doubly-flagged admin between each other forever.
     *
     * @param array<string,mixed> $admin
     */
    private static function enforceTwoFactorPolicy(Request $request, array $admin): void
    {
        if ((int) ($admin['two_factor_enabled'] ?? 0) === 1) {
            return;
        }

        if (!Config::settingBool('security', 'require_2fa_for_admins', false)) {
            return;
        }

        $path = $request->path();

        foreach (['/admin/security/two-factor', '/admin/account/password', '/admin/logout'] as $allowed) {
            if (str_starts_with($path, $allowed)) {
                return;
            }
        }

        if ($request->expectsJson()) {
            Response::forbidden('Two-factor authentication is required before you can use the admin panel.');
        }

        Session::toast('warning', 'Two-factor authentication is required. Please set it up to continue.');
        Response::redirect('/admin/security/two-factor');
    }

    /** Bounce an already-signed-in admin away from the login page. */
    public static function guestOnly(Request $request): void
    {
        if ((int) Session::get(self::SESSION_KEY, 0) > 0) {
            Response::redirect('/admin');
        }
    }

    /** Require a specific permission slug, e.g. `bookings.edit`. */
    public static function requirePermission(Request $request, string $permission): void
    {
        if ($permission === '') {
            return;
        }

        $admin = $request->attribute('admin');

        if (!is_array($admin)) {
            self::rejectUnauthenticated($request);
        }

        if (PermissionService::adminCan((int) $admin['id'], $permission)) {
            return;
        }

        if ($request->expectsJson()) {
            Response::forbidden('You do not have the "' . $permission . '" permission.');
        }

        Session::toast('error', 'You do not have permission to access that area.');
        Response::redirect('/admin');
    }

    /** @return array<string,mixed>|null */
    private static function loadAdmin(int $adminId): ?array
    {
        return Database::selectOne(
            'SELECT a.*, r.`name` AS `role_name`, r.`slug` AS `role_slug`, r.`level` AS `role_level`
             FROM `admins` a
             INNER JOIN `roles` r ON r.`id` = a.`role_id`
             WHERE a.`id` = :id AND a.`deleted_at` IS NULL
             LIMIT 1',
            ['id' => $adminId]
        );
    }

    /**
     * Renew the session's activity stamp.
     *
     * Returns false when no row was renewed, which means the session has been
     * revoked — by a sign-out elsewhere, a password change, a password reset,
     * or an admin ending it from the Security Centre. The caller must treat
     * that as unauthenticated.
     *
     * MySQL reports 0 affected rows for an UPDATE that changes nothing, so the
     * row's existence is confirmed separately rather than inferred from the
     * count: two requests inside the same second would otherwise look like a
     * revoked session and sign the admin out at random.
     */
    private static function touchSession(int $adminId): bool
    {
        $sessionId = Session::id();

        if ($sessionId === '') {
            return false;
        }

        $id = Crypto::hashToken($sessionId);

        $exists = Database::scalar(
            'SELECT 1 FROM `admin_sessions` WHERE `id` = :id AND `admin_id` = :admin_id',
            ['id' => $id, 'admin_id' => $adminId]
        );

        if ($exists === null) {
            return false;
        }

        Database::execute(
            'UPDATE `admin_sessions` SET `last_activity` = NOW() WHERE `id` = :id AND `admin_id` = :admin_id',
            ['id' => $id, 'admin_id' => $adminId]
        );

        return true;
    }

    private static function rejectUnauthenticated(Request $request, string $message = 'Please sign in to continue.'): never
    {
        if ($request->expectsJson()) {
            Response::unauthorized($message);
        }

        Session::rememberUrl($request->path());
        Session::toast('info', $message);
        Response::redirect('/admin/login');
    }
}

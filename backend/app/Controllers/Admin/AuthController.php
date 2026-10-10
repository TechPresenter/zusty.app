<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Core\RateLimiter;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\Session;
use Zuste\Core\View;
use Zuste\Helpers\Csrf;
use Zuste\Services\ActivityLogger;
use Zuste\Services\MailService;
use Zuste\Services\PermissionService;
use Zuste\Services\SecurityService;
use Zuste\Services\UploadService;

/**
 * Admin authentication: sign in, sign out, password reset, own account.
 *
 * Brute-force defence is layered:
 *  - a per-IP+email rate-limit bucket,
 *  - a per-account failure counter that locks the account temporarily,
 *  - identical messaging for "unknown account" and "wrong password" so the
 *    form cannot be used to enumerate administrators.
 */
final class AuthController extends BaseAdminController
{
    /**
     * Where an admin waits between presenting a password and a second factor.
     *
     * Deliberately NOT AdminAuthMiddleware::SESSION_KEY — nothing that reads
     * that key may see a half-authenticated visitor, or the challenge would be
     * bypassable by simply navigating away from it.
     */
    private const PENDING_2FA_KEY = 'admin_pending_2fa';

    /** When the pending slot was created, for expiry. */
    private const PENDING_2FA_AT = 'admin_pending_2fa_at';

    /** A password should not buy an indefinite window to brute-force a code. */
    private const PENDING_2FA_TTL = 300;

    /**
     * GET /admin/login/two-factor — the challenge.
     */
    public function showTwoFactorChallenge(Request $request): never
    {
        $adminId = $this->pendingAdminId();

        if ($adminId === 0) {
            Session::flash('auth_error', 'Your sign-in attempt timed out. Please start again.');
            Response::redirect('/admin/login');
        }

        View::display('admin.auth.two-factor', [
            'error'     => Session::getFlash('auth_error'),
            'remaining' => SecurityService::recoveryCodesRemaining($adminId),
        ]);
    }

    /**
     * POST /admin/login/two-factor — verify the second factor.
     *
     * Accepts either a TOTP code or a single-use recovery code, so a lost
     * authenticator does not lock an admin out permanently.
     */
    public function verifyTwoFactorChallenge(Request $request): never
    {
        $adminId = $this->pendingAdminId();

        if ($adminId === 0) {
            Session::flash('auth_error', 'Your sign-in attempt timed out. Please start again.');
            Response::redirect('/admin/login');
        }

        $code = trim($request->string('code'));
        $ip   = $request->ip();

        // Rate limited independently of the password step: a valid password
        // must not hand out unlimited guesses at a six-digit code.
        $throttleKey = 'admin-2fa:' . $adminId . ':' . $ip;
        $maxAttempts = Config::settingInt('security', 'max_login_attempts', 5);

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $this->clearPending();

            SecurityService::recordLogin(
                'admin',
                $adminId,
                '',
                SecurityService::OUTCOME_THROTTLED,
                // '2fa' is the value `login_history`.`auth_method` accepts.
                // 'two_factor' is not in the ENUM, so every insert from this
                // challenge was rejected and a brute-force against the second
                // factor left no trace in the audit trail at all.
                SecurityService::METHOD_2FA,
                $request,
                'Too many incorrect two-factor codes.'
            );

            Session::flash('auth_error', 'Too many incorrect codes. Please sign in again.');
            Response::redirect('/admin/login');
        }

        RateLimiter::hit($throttleKey, $maxAttempts, 900);

        $usedRecoveryCode = false;
        $verified         = SecurityService::verifyTwoFactorCode($adminId, $code);

        if (!$verified) {
            // A recovery code is longer than a TOTP, so trying it second costs
            // nothing and keeps the common path fast.
            $verified = SecurityService::consumeRecoveryCode($adminId, $code);
            $usedRecoveryCode = $verified;
        }

        if (!$verified) {
            SecurityService::recordLogin(
                'admin',
                $adminId,
                '',
                SecurityService::OUTCOME_BAD_CREDS,
                SecurityService::METHOD_2FA,
                $request,
                'Incorrect two-factor code.'
            );

            Session::flash('auth_error', 'That code was not correct. Try again.');
            Response::redirect('/admin/login/two-factor');
        }

        $admin = Database::selectOne(
            'SELECT a.*, r.`name` AS `role_name`, r.`slug` AS `role_slug`, r.`level` AS `role_level`
             FROM `admins` a
             INNER JOIN `roles` r ON r.`id` = a.`role_id`
             WHERE a.`id` = :id AND a.`deleted_at` IS NULL',
            ['id' => $adminId]
        );

        // The account could have been suspended between the two factors.
        if ($admin === null || $admin['status'] !== 'active') {
            $this->clearPending();
            Session::flash('auth_error', 'This account is no longer active.');
            Response::redirect('/admin/login');
        }

        RateLimiter::clear($throttleKey);
        $this->clearPending();

        // Both branches audit as '2fa' — the only value the ENUM accepts for a
        // second factor. Which second factor was used is carried in the note,
        // because a redeemed recovery code is worth seeing in the trail.
        $this->grantSession(
            $request,
            $admin,
            $ip,
            SecurityService::METHOD_2FA,
            $usedRecoveryCode ? 'Signed in with a single-use recovery code.' : ''
        );
    }

    /**
     * The admin id waiting on a second factor, or 0 when there is none or the
     * window has lapsed.
     */
    private function pendingAdminId(): int
    {
        $adminId = (int) Session::get(self::PENDING_2FA_KEY, 0);
        $since   = (int) Session::get(self::PENDING_2FA_AT, 0);

        if ($adminId <= 0) {
            return 0;
        }

        if ($since <= 0 || (time() - $since) > self::PENDING_2FA_TTL) {
            $this->clearPending();

            return 0;
        }

        return $adminId;
    }

    private function clearPending(): void
    {
        Session::forget(self::PENDING_2FA_KEY);
        Session::forget(self::PENDING_2FA_AT);
    }

    /** GET /admin/login */
    public function showLogin(Request $request): never
    {
        View::display('admin.auth.login', ['error' => Session::getFlash('auth_error')]);
    }

    /** POST /admin/login */
    public function login(Request $request): never
    {
        $email    = strtolower(trim($request->string('email')));
        $password = $request->string('password');
        $ip       = $request->ip();

        $maxAttempts  = Config::settingInt('security', 'max_login_attempts', 5);
        $lockMinutes  = Config::settingInt('security', 'lockout_minutes', 15);
        $throttleKey  = 'admin-login:' . $email . ':' . $ip;

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($throttleKey) / 60));

            SecurityService::recordLogin(
                'admin',
                null,
                $email,
                SecurityService::OUTCOME_THROTTLED,
                'password',
                $request,
                'Rate limit reached; retry in ' . $minutes . ' minute(s).'
            );

            Session::flash('auth_error', 'Too many failed attempts. Try again in ' . $minutes . ' minute(s).');
            Response::redirect('/admin/login');
        }

        RateLimiter::hit($throttleKey, $maxAttempts, $lockMinutes * 60);

        if ($email === '' || $password === '') {
            Session::flash('auth_error', 'Enter your email address and password.');
            Session::flash('old', ['email' => $email]);
            Response::redirect('/admin/login');
        }

        $admin = Database::selectOne(
            'SELECT a.*, r.`name` AS `role_name`, r.`slug` AS `role_slug`, r.`level` AS `role_level`
             FROM `admins` a
             INNER JOIN `roles` r ON r.`id` = a.`role_id`
             WHERE a.`email` = :email AND a.`deleted_at` IS NULL',
            ['email' => $email]
        );

        // Constant-ish work whether or not the account exists.
        $hash    = (string) ($admin['password_hash'] ?? '$2y$12$usesomesillystringfaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $matches = Crypto::verifyPassword($password, $hash);

        if ($admin === null || !$matches) {
            if ($admin !== null) {
                $this->registerFailure((int) $admin['id'], $maxAttempts, $lockMinutes);
            }

            Logger::warning('Failed admin login', ['email' => $email, 'ip' => $ip]);

            SecurityService::recordLogin(
                'admin',
                $admin !== null ? (int) $admin['id'] : null,
                $email,
                SecurityService::OUTCOME_BAD_CREDS,
                'password',
                $request,
                $admin === null ? 'No admin account with that email.' : 'Password did not match.'
            );

            Session::flash('auth_error', 'Incorrect email address or password.');
            Session::flash('old', ['email' => $email]);
            Response::redirect('/admin/login');
        }

        if ($admin['locked_until'] !== null && strtotime((string) $admin['locked_until']) > time()) {
            $minutes = max(1, (int) ceil((strtotime((string) $admin['locked_until']) - time()) / 60));

            SecurityService::recordLogin(
                'admin',
                (int) $admin['id'],
                $email,
                SecurityService::OUTCOME_LOCKED,
                'password',
                $request,
                'Account locked for a further ' . $minutes . ' minute(s).'
            );

            Session::flash('auth_error', 'This account is temporarily locked. Try again in ' . $minutes . ' minute(s).');
            Response::redirect('/admin/login');
        }

        if ($admin['status'] !== 'active') {
            SecurityService::recordLogin(
                'admin',
                (int) $admin['id'],
                $email,
                SecurityService::OUTCOME_SUSPENDED,
                'password',
                $request,
                'Account status is ' . (string) $admin['status'] . '.'
            );

            Session::flash('auth_error', 'This account is not active. Contact a Super Admin.');
            Response::redirect('/admin/login');
        }

        // Correct password, active account — enforce the IP allow list before a
        // session exists, so a rejected address never gets an authenticated one.
        if (!SecurityService::ipAllowed($ip, 'admin')) {
            SecurityService::recordLogin(
                'admin',
                (int) $admin['id'],
                $email,
                SecurityService::OUTCOME_SUSPENDED,
                'password',
                $request,
                'Address ' . $ip . ' is not on the admin allow list.'
            );

            Logger::warning('Admin login blocked by IP allow list', ['email' => $email, 'ip' => $ip]);

            Session::flash('auth_error', 'This network is not permitted to sign in to the admin panel.');
            Response::redirect('/admin/login');
        }

        // Password accepted. If this account has 2FA, that is only the FIRST
        // factor — park the identity in a short-lived pending slot and send
        // the browser to the challenge. No admin session exists until the
        // second factor is presented.
        //
        // Before this existed, `two_factor_enabled` was set at enrolment, shown
        // as active in the security panel, and enforced nowhere: a password
        // alone granted a full session, so 2FA was decorative.
        if ((int) ($admin['two_factor_enabled'] ?? 0) === 1) {
            RateLimiter::clear($throttleKey);

            // Regenerate here too: the pending id is a credential of its own,
            // so it must not live in a session id the visitor arrived with.
            Session::regenerate();
            Session::set(self::PENDING_2FA_KEY, (int) $admin['id']);
            Session::set(self::PENDING_2FA_AT, time());
            Csrf::rotate();

            SecurityService::recordLogin(
                'admin',
                (int) $admin['id'],
                $email,
                SecurityService::OUTCOME_SUCCESS,
                'password',
                $request,
                'Password accepted; awaiting the second factor.'
            );

            Response::redirect('/admin/login/two-factor');
        }

        // Success — no second factor on this account.
        RateLimiter::clear($throttleKey);

        // Upgrade a stale hash on the way through, while the plaintext is
        // still in scope.
        if (Crypto::passwordNeedsRehash($hash)) {
            Database::update('admins', ['password_hash' => Crypto::hashPassword($password)], ['id' => (int) $admin['id']]);
        }

        $this->grantSession($request, $admin, $ip, 'password');
    }

    /**
     * Establishes the authenticated admin session.
     *
     * Shared by the password-only path and the two-factor path so the two
     * cannot drift — a session granted after a second factor must be identical
     * in every respect to one granted without.
     *
     * @param array<string,mixed> $admin
     * @param string              $note optional detail for the audit row, e.g.
     *                                  which second factor was accepted
     */
    private function grantSession(Request $request, array $admin, string $ip, string $method, string $note = ''): never
    {
        Session::regenerate();
        Session::set(\Zuste\Middleware\AdminAuthMiddleware::SESSION_KEY, (int) $admin['id']);
        Csrf::rotate();

        Database::update('admins', [
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => date('Y-m-d H:i:s'),
            'last_login_ip'   => $ip,
        ], ['id' => (int) $admin['id']]);

        Database::execute(
            'INSERT INTO `admin_sessions` (`id`, `admin_id`, `ip_address`, `user_agent`, `last_activity`, `created_at`)
             VALUES (:id, :admin_id, :ip, :ua, NOW(), NOW())
             ON DUPLICATE KEY UPDATE `last_activity` = NOW()',
            [
                'id'       => Crypto::hashToken(Session::id()),
                'admin_id' => (int) $admin['id'],
                'ip'       => $ip,
                'ua'       => $request->userAgent(),
            ]
        );

        PermissionService::flush((int) $admin['id']);

        ActivityLogger::admin($admin, 'login', 'admin', (int) $admin['id'], 'Signed in to the admin panel.');

        SecurityService::recordLogin(
            'admin',
            (int) $admin['id'],
            (string) $admin['email'],
            SecurityService::OUTCOME_SUCCESS,
            $method,
            $request,
            $note
        );

        Session::toast('success', 'Welcome back, ' . (string) $admin['name'] . '.');

        $intended = Session::previousUrl();
        Session::forget('__previous_url');

        Response::redirect($intended !== null && str_starts_with($intended, '/admin') && $intended !== '/admin/login'
            ? $intended
            : '/admin');
    }

    /** POST /admin/logout */
    public function logout(Request $request): never
    {
        $adminId = (int) Session::get(\Zuste\Middleware\AdminAuthMiddleware::SESSION_KEY, 0);

        if ($adminId > 0) {
            Database::execute(
                'DELETE FROM `admin_sessions` WHERE `id` = :id',
                ['id' => Crypto::hashToken(Session::id())]
            );

            ActivityLogger::log('logout', 'admin', $adminId, 'Signed out.', [], 'admin', $adminId);
        }

        Session::destroy();
        Session::start();
        Session::toast('info', 'You have been signed out.');

        Response::redirect('/admin/login');
    }

    /** GET /admin/forgot-password */
    public function showForgotPassword(Request $request): never
    {
        View::display('admin.auth.forgot-password', ['sent' => Session::hasFlash('reset_sent')]);
    }

    /** POST /admin/forgot-password */
    public function sendResetLink(Request $request): never
    {
        $email = strtolower(trim($request->string('email')));
        $key   = 'admin-reset:' . $request->ip();

        $result = RateLimiter::hit($key, 5, 900);

        if (!$result['allowed']) {
            Session::toast('error', 'Too many requests. Please wait a few minutes.');
            Response::redirect('/admin/forgot-password');
        }

        $admin = Database::selectOne(
            "SELECT * FROM `admins` WHERE `email` = :email AND `status` = 'active' AND `deleted_at` IS NULL",
            ['email' => $email]
        );

        if ($admin !== null) {
            $token = Crypto::randomToken(32);

            Database::insert('admin_password_resets', [
                'admin_id'   => (int) $admin['id'],
                'token_hash' => Crypto::hashToken($token),
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'ip_address' => $request->ip(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $resetUrl = rtrim((string) Config::get('app.url'), '/') . '/admin/reset-password?token=' . urlencode($token);

            $subject  = 'Reset your Zusty admin password';
            $bodyHtml = MailService::renderDefault(
                'Reset your admin password',
                'A password reset was requested for your Zusty admin account. This link expires in one hour. '
                . 'If this was not you, ignore this email and consider notifying a Super Admin.',
                (string) $admin['name'],
                'url',
                $resetUrl
            );

            // sendNow() reports failure by returning false, and on an SMTP
            // failure it has already put the message in the queue for
            // drainQueue() to retry with backoff. So this only records that it
            // went the slow way: queueing it again here sent the link twice.
            if (!MailService::sendNow((string) $admin['email'], (string) $admin['name'], $subject, $bodyHtml)) {
                Logger::warning('Admin password reset email could not be sent immediately; it waits in the mail queue.', [
                    'admin_id' => (int) $admin['id'],
                ]);
            }

            Logger::info('Admin password reset requested', ['admin_id' => $admin['id'], 'ip' => $request->ip()]);
        }

        // Identical response either way.
        Session::flash('reset_sent', true);
        Session::toast('success', 'If that address belongs to an admin account, a reset link is on its way.');
        Response::redirect('/admin/forgot-password');
    }

    /** GET /admin/reset-password */
    public function showResetPassword(Request $request): never
    {
        View::display('admin.auth.reset-password', ['token' => $request->string('token')]);
    }

    /** POST /admin/reset-password */
    public function resetPassword(Request $request): never
    {
        $data = $this->validate($request, [
            'token'    => 'required|string',
            'password' => 'required|string|password|confirmed|max:128',
        ]);

        $record = Database::selectOne(
            'SELECT * FROM `admin_password_resets`
             WHERE `token_hash` = :hash AND `used_at` IS NULL AND `expires_at` > NOW()',
            ['hash' => Crypto::hashToken((string) $data['token'])]
        );

        if ($record === null) {
            Session::toast('error', 'This reset link is invalid or has expired.');
            Response::redirect('/admin/forgot-password');
        }

        Database::transaction(static function () use ($record, $data): void {
            Database::update('admins', [
                'password_hash'        => Crypto::hashPassword((string) $data['password']),
                'password_changed_at'  => date('Y-m-d H:i:s'),
                'must_change_password' => 0,
                'failed_attempts'      => 0,
                'locked_until'         => null,
            ], ['id' => (int) $record['admin_id']]);

            Database::update('admin_password_resets', ['used_at' => date('Y-m-d H:i:s')], ['id' => (int) $record['id']]);

            // Invalidate every other session for this admin.
            Database::delete('admin_sessions', ['admin_id' => (int) $record['admin_id']]);
        });

        ActivityLogger::log('password.reset', 'admin', (int) $record['admin_id'], 'Password reset via email link.', [], 'admin', (int) $record['admin_id']);

        Session::toast('success', 'Your password has been reset. Please sign in.');
        Response::redirect('/admin/login');
    }

    // -----------------------------------------------------------------
    // Own account
    // -----------------------------------------------------------------

    /** GET /admin/account */
    public function account(Request $request): never
    {
        $admin = $this->admin($request);

        $sessions = Database::select(
            'SELECT `id`, `ip_address`, `user_agent`, `last_activity`, `created_at`
             FROM `admin_sessions` WHERE `admin_id` = :id ORDER BY `last_activity` DESC',
            ['id' => (int) $admin['id']]
        );

        $recentActivity = Database::select(
            "SELECT * FROM `activity_logs`
             WHERE `actor_type` = 'admin' AND `actor_id` = :id
             ORDER BY `id` DESC LIMIT 20",
            ['id' => (int) $admin['id']]
        );

        [$avatarTypes, $avatarMaxBytes] = UploadService::limitsFor('image');

        $this->view($request, 'admin.account.index', [
            'pageTitle'      => 'My account',
            'pageSubtitle'   => 'Profile, password and active sessions',
            'profile'        => $admin,
            'sessions'       => $sessions,
            'currentSession' => Crypto::hashToken(Session::id()),
            'recentActivity' => $recentActivity,
            'permissions'    => PermissionService::forAdmin((int) $admin['id']),
            'avatarTypes'    => $avatarTypes,
            'avatarMaxBytes' => $avatarMaxBytes,
        ]);
    }

    /** POST /admin/account */
    public function updateAccount(Request $request): never
    {
        $adminId = $this->adminId($request);

        $data = $this->validate($request, [
            'name'  => 'required|string|min:2|max:120',
            'email' => 'required|email|max:191|unique:admins,email,' . $adminId,
            'phone' => 'nullable|phone',
        ]);

        Database::update('admins', [
            'name'  => (string) $data['name'],
            'email' => strtolower((string) $data['email']),
            'phone' => $data['phone'] ?? null,
        ], ['id' => $adminId]);

        $this->log($request, 'account.updated', 'admin', $adminId, 'Updated own profile.');

        $this->respond($request, 'Profile updated.', '/admin/account');
    }

    /** POST /admin/account/avatar */
    public function updateAvatar(Request $request): never
    {
        $admin   = $this->admin($request);
        $adminId = (int) $admin['id'];
        $file    = $request->file('avatar');

        if ($file === null) {
            $this->back('error', 'Choose an image to upload.', '/admin/account');
        }

        // UploadService rejects a bad file with a 422, which on a plain form
        // post renders a full error page. Send the admin back to the form with
        // the reason instead.
        try {
            $upload = UploadService::store($file, 'avatars', 'image', 'admin', $adminId);
        } catch (HttpException $e) {
            if ($e->status() >= 500 || $request->expectsJson()) {
                throw $e;
            }

            $this->back('error', $e->getMessage(), '/admin/account');
        }

        Database::update('admins', ['avatar' => $upload['url']], ['id' => $adminId]);

        $this->discardAvatar($adminId, (string) ($admin['avatar'] ?? ''));

        $this->log($request, 'account.avatar_updated', 'admin', $adminId, 'Updated own profile photo.');

        $this->respond($request, 'Profile photo updated.', '/admin/account', ['avatar' => $upload['url']]);
    }

    /** POST /admin/account/avatar/delete */
    public function removeAvatar(Request $request): never
    {
        $admin   = $this->admin($request);
        $adminId = (int) $admin['id'];
        $current = (string) ($admin['avatar'] ?? '');

        if ($current === '') {
            $this->back('info', 'There is no profile photo to remove.', '/admin/account');
        }

        Database::update('admins', ['avatar' => null], ['id' => $adminId]);

        $this->discardAvatar($adminId, $current);

        $this->log($request, 'account.avatar_removed', 'admin', $adminId, 'Removed own profile photo.');

        $this->respond($request, 'Profile photo removed.', '/admin/account');
    }

    /** GET /admin/account/password */
    public function showChangePassword(Request $request): never
    {
        $this->view($request, 'admin.account.password', [
            'pageTitle'    => 'Change password',
            'pageSubtitle' => 'Choose a strong, unique password',
            'forced'       => (int) $this->admin($request)['must_change_password'] === 1,
        ]);
    }

    /** POST /admin/account/password */
    public function changePassword(Request $request): never
    {
        $data = $this->validate($request, [
            'current_password' => 'required|string|max:128',
            'password'         => 'required|string|password|confirmed|max:128',
        ]);

        $admin = $this->admin($request);

        if (!Crypto::verifyPassword((string) $data['current_password'], (string) $admin['password_hash'])) {
            $this->back('error', 'Your current password is incorrect.', '/admin/account/password');
        }

        if (Crypto::verifyPassword((string) $data['password'], (string) $admin['password_hash'])) {
            $this->back('error', 'Choose a password you have not used before.', '/admin/account/password');
        }

        $currentSession = Crypto::hashToken(Session::id());

        Database::transaction(static function () use ($admin, $data, $currentSession): void {
            Database::update('admins', [
                'password_hash'        => Crypto::hashPassword((string) $data['password']),
                'password_changed_at'  => date('Y-m-d H:i:s'),
                'must_change_password' => 0,
            ], ['id' => (int) $admin['id']]);

            // Kill every session except the one making the change.
            Database::execute(
                'DELETE FROM `admin_sessions` WHERE `admin_id` = :id AND `id` <> :current',
                ['id' => (int) $admin['id'], 'current' => $currentSession]
            );
        });

        $this->log($request, 'password.changed', 'admin', (int) $admin['id'], 'Changed own password.');

        $this->back('success', 'Password updated. Other sessions have been signed out.', '/admin/account');
    }

    /** POST /admin/account/sessions/{id}/revoke */
    public function revokeSession(Request $request): never
    {
        $sessionId = (string) $request->routeParam('id', '');

        Database::execute(
            'DELETE FROM `admin_sessions` WHERE `id` = :id AND `admin_id` = :admin_id',
            ['id' => $sessionId, 'admin_id' => $this->adminId($request)]
        );

        $this->back('success', 'Session revoked.', '/admin/account');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Delete a profile photo that has just been replaced or removed.
     *
     * Only a file this admin uploaded as their photo, and has not since filed
     * into a media library folder, is touched. Anything else stays put.
     */
    private function discardAvatar(int $adminId, string $url): void
    {
        if ($url === '') {
            return;
        }

        $mediaId = Database::scalar(
            "SELECT `id` FROM `media`
             WHERE `url` = :url AND `folder` = 'avatars' AND `folder_id` IS NULL
               AND `uploader_type` = 'admin' AND `uploader_id` = :admin AND `deleted_at` IS NULL
             LIMIT 1",
            ['url' => $url, 'admin' => $adminId]
        );

        if ($mediaId !== null) {
            UploadService::purge((int) $mediaId);
        }
    }

    /** Increment the failure counter and lock the account when it trips. */
    private function registerFailure(int $adminId, int $maxAttempts, int $lockMinutes): void
    {
        // MySQL/MariaDB evaluate SET assignments left to right, so the second
        // one already sees the incremented counter. The `+ 1` that used to be
        // in the comparison therefore read old + 2 and locked the account one
        // failure early — on the 4th attempt with max_login_attempts = 5.
        Database::execute(
            'UPDATE `admins`
             SET `failed_attempts` = `failed_attempts` + 1,
                 `locked_until` = IF(`failed_attempts` >= :max, DATE_ADD(NOW(), INTERVAL :minutes MINUTE), `locked_until`)
             WHERE `id` = :id',
            ['max' => $maxAttempts, 'minutes' => $lockMinutes, 'id' => $adminId]
        );
    }
}

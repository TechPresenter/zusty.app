<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Core\Session;
use Zuste\Services\SecurityService;

/**
 * Security centre: the sign-in audit trail, two-factor enrolment, the
 * administrative IP allow list and live session control.
 */
final class SecurityController extends BaseAdminController
{
    private const SORTABLE = ['created_at', 'outcome', 'identifier', 'ip_address'];

    // -----------------------------------------------------------------
    // Overview
    // -----------------------------------------------------------------

    /** GET /admin/security */
    public function index(Request $request): never
    {
        $this->authorize($request, 'security.logins');

        $adminId = $this->adminId($request);

        $summary = Database::selectOne(
            "SELECT
                COUNT(*)                                                                AS `attempts_24h`,
                SUM(`outcome` = 'success')                                              AS `success_24h`,
                SUM(`outcome` <> 'success')                                             AS `failed_24h`,
                COUNT(DISTINCT CASE WHEN `outcome` <> 'success' THEN `ip_address` END)  AS `failing_ips`,
                SUM(`is_new_device` = 1)                                                AS `new_devices`
               FROM `login_history`
              WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        ) ?? [];

        $recent = Database::select(
            'SELECT * FROM `login_history` ORDER BY `created_at` DESC LIMIT 10'
        );

        // Repeat offenders worth allow-listing away or blocking upstream.
        $topFailures = Database::select(
            "SELECT `ip_address`, COUNT(*) AS `attempts`, MAX(`created_at`) AS `last_seen`
               FROM `login_history`
              WHERE `outcome` <> 'success'
                AND `ip_address` IS NOT NULL
                AND `created_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              GROUP BY `ip_address`
              ORDER BY `attempts` DESC
              LIMIT 8"
        );

        $admin = $this->admin($request);

        $this->view($request, 'admin.security.index', [
            'pageTitle'      => 'Security',
            'pageSubtitle'   => 'Sign-in activity, two-factor and access control.',
            'summary'        => $summary,
            'recent'         => $recent,
            'topFailures'    => $topFailures,
            'twoFactorOn'    => (int) ($admin['two_factor_enabled'] ?? 0) === 1,
            'recoveryLeft'   => SecurityService::recoveryCodesRemaining($adminId),
            'whitelistCount' => (int) Database::scalar('SELECT COUNT(*) FROM `ip_whitelist` WHERE `is_enabled` = 1'),
            'sessionCount'   => (int) Database::scalar('SELECT COUNT(*) FROM `admin_sessions` WHERE `last_activity` >= DATE_SUB(NOW(), INTERVAL 120 MINUTE)'),
        ]);
    }

    // -----------------------------------------------------------------
    // Login history
    // -----------------------------------------------------------------

    /** GET /admin/security/login-history */
    public function loginHistory(Request $request): never
    {
        $this->authorize($request, 'security.logins');

        $params   = $this->listParams($request, self::SORTABLE, 'created_at');
        $outcome  = $request->string('outcome');
        $actor    = $request->string('actor_type');

        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[] = '(`identifier` LIKE :search OR `ip_address` LIKE :search)';
            $bindings['search'] = '%' . $params['search'] . '%';
        }

        if ($outcome !== '' && in_array($outcome, ['success', 'bad_credentials', 'locked', 'suspended', 'throttled', '2fa_required', '2fa_failed'], true)) {
            $clauses[] = '`outcome` = :outcome';
            $bindings['outcome'] = $outcome;
        }

        if (in_array($actor, ['admin', 'user'], true)) {
            $clauses[] = '`actor_type` = :actor';
            $bindings['actor'] = $actor;
        }

        $this->applyDateRange($clauses, $bindings, '`created_at`', $params['from'], $params['to']);

        $where = implode(' AND ', $clauses);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM `login_history` WHERE $where", $bindings);

        $rows = Database::select(
            "SELECT * FROM `login_history`
              WHERE $where
              ORDER BY `{$params['sort_by']}` {$params['sort_dir']}
              LIMIT {$params['per_page']} OFFSET {$params['offset']}",
            $bindings
        );

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $all = Database::select(
                "SELECT `created_at`, `actor_type`, `identifier`, `outcome`, `auth_method`,
                        `ip_address`, `device_type`, `is_new_device`, `failure_reason`
                   FROM `login_history` WHERE $where ORDER BY `created_at` DESC LIMIT 10000",
                $bindings
            );

            $this->exportCsv(
                'login-history',
                ['When', 'Actor', 'Identifier', 'Outcome', 'Method', 'IP address', 'Device', 'New device', 'Reason'],
                $all,
                ['created_at', 'actor_type', 'identifier', 'outcome', 'auth_method', 'ip_address', 'device_type', 'is_new_device', 'failure_reason']
            );
        }

        $this->view($request, 'admin.security.login-history', [
            'pageTitle'    => 'Login history',
            'pageSubtitle' => 'Every sign-in attempt across the admin panel and the apps.',
            'rows'         => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => [
                'q'          => $params['search'],
                'outcome'    => $outcome,
                'actor_type' => $actor,
                'from'       => $params['from'],
                'to'         => $params['to'],
                'sort_by'    => $params['sort_by'],
                'sort_dir'   => $params['sort_dir'],
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Two-factor authentication (self-service, always the current admin)
    // -----------------------------------------------------------------

    /** GET /admin/security/two-factor */
    public function twoFactor(Request $request): never
    {
        $admin = $this->admin($request);

        $this->view($request, 'admin.security.two-factor', [
            'pageTitle'    => 'Two-factor authentication',
            'pageSubtitle' => 'Protect your own account with a time-based code.',
            'enabled'      => (int) ($admin['two_factor_enabled'] ?? 0) === 1,
            'confirmedAt'  => $admin['two_factor_confirmed_at'] ?? null,
            'recoveryLeft' => SecurityService::recoveryCodesRemaining((int) $admin['id']),
            // Set once by begin(); read and cleared here so a secret is never
            // rendered twice or left sitting in the session.
            'enrolment'    => Session::getFlash('2fa_enrolment'),
            'recoveryCodes' => Session::getFlash('2fa_recovery_codes'),
        ]);
    }

    /** POST /admin/security/two-factor/begin */
    public function beginTwoFactor(Request $request): never
    {
        $admin = $this->admin($request);

        if ((int) ($admin['two_factor_enabled'] ?? 0) === 1) {
            $this->back('info', 'Two-factor is already switched on for your account.', '/admin/security/two-factor');
        }

        $enrolment = SecurityService::beginTwoFactorEnrolment((int) $admin['id'], (string) $admin['email']);

        Session::flash('2fa_enrolment', $enrolment);

        $this->back('info', 'Scan the QR code, then enter a code to finish.', '/admin/security/two-factor');
    }

    /** POST /admin/security/two-factor/confirm */
    public function confirmTwoFactor(Request $request): never
    {
        $admin = $this->admin($request);
        $code  = preg_replace('/\D/', '', $request->string('code')) ?? '';

        if (strlen($code) !== 6) {
            $this->back('error', 'Enter the six-digit code from your authenticator app.', '/admin/security/two-factor');
        }

        $codes = SecurityService::confirmTwoFactorEnrolment((int) $admin['id'], $code);

        if ($codes === []) {
            SecurityService::recordLogin(
                'admin',
                (int) $admin['id'],
                (string) $admin['email'],
                SecurityService::OUTCOME_2FA_FAILED,
                '2fa',
                $request,
                'Enrolment code did not match.'
            );

            $this->back('error', 'That code did not match. Check your device clock and try again.', '/admin/security/two-factor');
        }

        Session::flash('2fa_recovery_codes', $codes);

        $this->log($request, 'two_factor.enabled', 'admin', (int) $admin['id'], 'Enabled two-factor authentication.');

        $this->back('success', 'Two-factor is on. Save your recovery codes now.', '/admin/security/two-factor');
    }

    /** POST /admin/security/two-factor/disable */
    public function disableTwoFactor(Request $request): never
    {
        $admin = $this->admin($request);

        // Re-authenticate before removing a second factor.
        if (!Crypto::verifyPassword($request->string('password'), (string) $admin['password_hash'])) {
            $this->back('error', 'That password is not correct.', '/admin/security/two-factor');
        }

        SecurityService::disableTwoFactor((int) $admin['id']);

        $this->log($request, 'two_factor.disabled', 'admin', (int) $admin['id'], 'Disabled two-factor authentication.');

        $this->back('success', 'Two-factor has been switched off.', '/admin/security/two-factor');
    }

    // -----------------------------------------------------------------
    // IP allow list
    // -----------------------------------------------------------------

    /** GET /admin/security/ip-whitelist */
    public function ipWhitelist(Request $request): never
    {
        $this->authorize($request, 'security.ip');

        $rows = Database::select(
            'SELECT w.*, a.`name` AS `created_by_name`
               FROM `ip_whitelist` w
               LEFT JOIN `admins` a ON a.`id` = w.`created_by`
              ORDER BY w.`is_enabled` DESC, w.`created_at` DESC'
        );

        $this->view($request, 'admin.security.ip-whitelist', [
            'pageTitle'    => 'IP allow list',
            'pageSubtitle' => 'Restrict admin and API access to known addresses.',
            'rows'         => $rows,
            'currentIp'    => $request->ip(),
            // Surfaced in the view so nobody locks themselves out unknowingly.
            'wouldLockOut' => $rows !== [] && !SecurityService::ipAllowed($request->ip(), 'admin'),
        ]);
    }

    /** POST /admin/security/ip-whitelist */
    public function storeIp(Request $request): never
    {
        $this->authorize($request, 'security.ip');

        $data = $this->validate($request, [
            'label'      => 'required|string|min:2|max:120',
            'ip_address' => 'required|string|max:45',
            'scope'      => 'required|in:admin,api,both',
            'expires_at' => 'nullable|datetime',
        ]);

        $pattern = trim($data['ip_address']);

        if (!self::looksLikeIpPattern($pattern)) {
            $this->back('error', 'Enter an IP address, a CIDR range such as 203.0.113.0/24, or a prefix such as 203.0.113.*');
        }

        // Refuse an entry that would immediately lock the author out: if this is
        // the first admin-scoped rule, it has to cover the address they are on.
        $existing = (int) Database::scalar(
            "SELECT COUNT(*) FROM `ip_whitelist` WHERE `is_enabled` = 1 AND `scope` IN ('admin','both')"
        );

        if ($existing === 0
            && in_array($data['scope'], ['admin', 'both'], true)
            && !SecurityService::ipMatches($request->ip(), $pattern)) {
            $this->back(
                'error',
                'That would lock you out — the first admin rule must cover your current address (' . $request->ip() . ').'
            );
        }

        Database::insert('ip_whitelist', [
            'label'      => $data['label'],
            'ip_address' => $pattern,
            'scope'      => $data['scope'],
            'is_enabled' => 1,
            // The validator omits optional keys that were not submitted, so an
            // absent field and a blank one both have to mean "no expiry".
            'expires_at' => ($data['expires_at'] ?? '') !== '' ? $data['expires_at'] : null,
            'created_by' => $this->adminId($request),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log($request, 'ip_whitelist.created', 'ip_whitelist', null, 'Added ' . $pattern . ' to the allow list.');

        $this->respond($request, 'Address added to the allow list.', '/admin/security/ip-whitelist');
    }

    /** POST /admin/security/ip-whitelist/{id}/toggle */
    public function toggleIp(Request $request): never
    {
        $this->authorize($request, 'security.ip');

        $id  = $request->routeParamInt('id');
        $row = Database::selectOne('SELECT * FROM `ip_whitelist` WHERE `id` = :id', ['id' => $id]);

        if ($row === null) {
            $this->back('error', 'That allow-list entry no longer exists.', '/admin/security/ip-whitelist');
        }

        $enable = (int) $row['is_enabled'] === 0;

        // Disabling is always safe. Enabling is only safe if the result still
        // admits the current address.
        if ($enable && in_array($row['scope'], ['admin', 'both'], true)) {
            $others = (int) Database::scalar(
                "SELECT COUNT(*) FROM `ip_whitelist`
                  WHERE `is_enabled` = 1 AND `scope` IN ('admin','both') AND `id` <> :id",
                ['id' => $id]
            );

            if ($others === 0 && !SecurityService::ipMatches($request->ip(), (string) $row['ip_address'])) {
                $this->back('error', 'Enabling that as the only admin rule would lock you out.', '/admin/security/ip-whitelist');
            }
        }

        // Disabling the last rule that covers the current address would lock the
        // panel on the next sign-in, so refuse that too.
        if (!$enable && in_array($row['scope'], ['admin', 'both'], true)) {
            $remaining = Database::select(
                "SELECT `ip_address` FROM `ip_whitelist`
                  WHERE `is_enabled` = 1 AND `scope` IN ('admin','both') AND `id` <> :id
                    AND (`expires_at` IS NULL OR `expires_at` > NOW())",
                ['id' => $id]
            );

            $stillCovered = $remaining === [];

            foreach ($remaining as $other) {
                if (SecurityService::ipMatches($request->ip(), (string) $other['ip_address'])) {
                    $stillCovered = true;
                    break;
                }
            }

            if (!$stillCovered) {
                $this->back('error', 'Disabling that would leave no rule covering your address.', '/admin/security/ip-whitelist');
            }
        }

        Database::update('ip_whitelist', ['is_enabled' => $enable ? 1 : 0], ['id' => $id]);

        $this->log($request, 'ip_whitelist.toggled', 'ip_whitelist', $id, ($enable ? 'Enabled ' : 'Disabled ') . $row['ip_address']);

        $this->respond($request, $enable ? 'Entry enabled.' : 'Entry disabled.', '/admin/security/ip-whitelist');
    }

    /** POST /admin/security/ip-whitelist/{id}/delete */
    public function destroyIp(Request $request): never
    {
        $this->authorize($request, 'security.ip');

        $id  = $request->routeParamInt('id');
        $row = Database::selectOne('SELECT * FROM `ip_whitelist` WHERE `id` = :id', ['id' => $id]);

        if ($row === null) {
            $this->back('error', 'That entry no longer exists.', '/admin/security/ip-whitelist');
        }

        Database::execute('DELETE FROM `ip_whitelist` WHERE `id` = :id', ['id' => $id]);

        $this->log($request, 'ip_whitelist.deleted', 'ip_whitelist', $id, 'Removed ' . $row['ip_address'] . ' from the allow list.');

        $this->respond($request, 'Entry removed.', '/admin/security/ip-whitelist');
    }

    // -----------------------------------------------------------------
    // Sessions
    // -----------------------------------------------------------------

    /** GET /admin/security/sessions */
    public function sessions(Request $request): never
    {
        $this->authorize($request, 'security.sessions');

        $current = Crypto::hashToken(Session::id());

        $this->view($request, 'admin.security.sessions', [
            'pageTitle'    => 'Active sessions',
            'pageSubtitle' => 'Everyone currently signed in to the admin panel.',
            'rows'         => SecurityService::activeSessions(),
            'currentId'    => $current,
        ]);
    }

    /** POST /admin/security/sessions/{id}/revoke */
    public function revokeSession(Request $request): never
    {
        $this->authorize($request, 'security.sessions');

        $id = (string) $request->routeParam('id', '');

        if ($id === Crypto::hashToken(Session::id())) {
            $this->back('error', 'Use Sign out to end your own session.', '/admin/security/sessions');
        }

        $row = Database::selectOne(
            'SELECT s.`admin_id`, a.`name` FROM `admin_sessions` s
               INNER JOIN `admins` a ON a.`id` = s.`admin_id`
              WHERE s.`id` = :id',
            ['id' => $id]
        );

        if ($row === null || !SecurityService::revokeSession($id)) {
            $this->back('error', 'That session has already ended.', '/admin/security/sessions');
        }

        $this->log($request, 'session.revoked', 'admin', (int) $row['admin_id'], 'Revoked an admin session for ' . $row['name'] . '.');

        $this->respond($request, 'Session revoked.', '/admin/security/sessions');
    }

    /**
     * Accept an exact address, a CIDR range or a trailing-wildcard prefix.
     */
    private static function looksLikeIpPattern(string $pattern): bool
    {
        if (filter_var($pattern, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (str_contains($pattern, '/')) {
            [$subnet, $bits] = array_pad(explode('/', $pattern, 2), 2, '');

            return filter_var($subnet, FILTER_VALIDATE_IP) !== false
                && ctype_digit($bits)
                && (int) $bits >= 0
                && (int) $bits <= (str_contains($subnet, ':') ? 128 : 32);
        }

        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');

            return $prefix !== '' && preg_match('/^[0-9a-fA-F:.]+$/', $prefix) === 1;
        }

        return false;
    }
}

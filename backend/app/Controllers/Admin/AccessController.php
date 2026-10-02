<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\Session;
use Zuste\Helpers\Str;
use Zuste\Services\ActivityLogger;
use Zuste\Services\ExportService;
use Zuste\Services\MailService;
use Zuste\Services\PermissionService;

/**
 * Admin accounts, roles and the audit trail.
 *
 * Two invariants are enforced here and cannot be bypassed from the UI:
 *  1. An admin can never grant a role more powerful than their own.
 *  2. The last active Super Admin cannot be demoted, disabled or deleted —
 *     that would lock everyone out of the platform permanently.
 */
final class AccessController extends BaseAdminController
{
    /** Backstop for an export: past this a download should be a background job. */
    private const EXPORT_LIMIT = 10000;

    // =================================================================
    // Admin accounts
    // =================================================================

    /** GET /admin/admins */
    public function admins(Request $request): never
    {
        $this->authorize($request, 'admins.view');

        $params   = $this->listParams($request, ['id', 'name', 'email', 'created_at', 'last_login_at'], 'id');
        $clauses  = ['a.`deleted_at` IS NULL'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = "(a.`name` LIKE :search ESCAPE '\\\\' OR a.`email` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        if ($request->filled('role_id')) {
            $clauses[]           = 'a.`role_id` = :role_id';
            $bindings['role_id'] = $request->int('role_id');
        }

        if ($request->filled('status')) {
            $clauses[]          = 'a.`status` = :status';
            $bindings['status'] = $request->string('status');
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `admins` a INNER JOIN `roles` r ON r.`id` = a.`role_id`' . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT a.`id`, a.`name`, a.`email`, a.`phone`, a.`avatar`, a.`status`, a.`role_id`,
                    a.`last_login_at`, a.`last_login_ip`, a.`created_at`, a.`must_change_password`,
                    r.`name` AS `role_name`, r.`slug` AS `role_slug`, r.`level` AS `role_level`,
                    (SELECT COUNT(*) FROM `admin_sessions` s WHERE s.`admin_id` = a.`id`
                      AND s.`last_activity` > DATE_SUB(NOW(), INTERVAL 30 MINUTE)) AS `active_sessions`
             FROM `admins` a
             INNER JOIN `roles` r ON r.`id` = a.`role_id`' . $where . '
             ORDER BY a.`' . $params['sort_by'] . '` ' . $params['sort_dir'] . '
             LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $this->view($request, 'admin.access.admins', [
            'pageTitle'    => 'Admin users',
            'pageSubtitle' => number_format($total) . ' accounts',
            'admins'       => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
            'roles'        => $this->assignableRoles($request),
            'myLevel'      => (int) $this->admin($request)['role_level'],
        ]);
    }

    /** POST /admin/admins */
    public function storeAdmin(Request $request): never
    {
        $this->authorize($request, 'admins.manage');

        $data = $this->validate($request, [
            'name'    => 'required|string|min:2|max:120',
            'email'   => 'required|email|max:191|unique:admins,email',
            'phone'   => 'nullable|phone',
            'role_id' => 'required|integer|exists:roles,id',
        ]);

        $this->assertRoleAssignable($request, (int) $data['role_id']);

        // A temporary password is generated and emailed; the operator is then
        // forced to change it on first sign-in. We never ask one admin to
        // choose another admin's password.
        $temporaryPassword = $this->generateTemporaryPassword();

        $adminId = Database::insert('admins', [
            'role_id'              => (int) $data['role_id'],
            'name'                 => (string) $data['name'],
            'email'                => strtolower((string) $data['email']),
            'phone'                => $data['phone'] ?? null,
            'password_hash'        => Crypto::hashPassword($temporaryPassword),
            'status'               => 'active',
            'must_change_password' => 1,
            'created_by'           => $this->adminId($request),
            'created_at'           => date('Y-m-d H:i:s'),
        ]);

        $delivered = MailService::sendNow(
            strtolower((string) $data['email']),
            (string) $data['name'],
            'Your Zusty admin account',
            MailService::renderDefault(
                'Your admin account is ready',
                'An administrator account has been created for you. Sign in with this temporary password and '
                . 'you will be asked to choose a new one immediately: <strong>' . htmlspecialchars($temporaryPassword, ENT_QUOTES, 'UTF-8') . '</strong>',
                (string) $data['name'],
                'url',
                rtrim((string) \Zuste\Core\Config::get('app.url'), '/') . '/admin/login'
            )
        );

        $this->log(
            $request,
            'admin.created',
            'admin',
            $adminId,
            'Created admin ' . (string) $data['email'],
            ['invite_email_delivered' => $delivered]
        );

        // sendNow() swallows delivery failures and returns false. Reporting an
        // unconditional success stranded the new account: the password is only
        // ever emailed, is not stored anywhere in the clear, and the reset link
        // travels by the same (broken) channel. Hand the operator the one-time
        // credential instead so onboarding can still complete out of band.
        if (!$delivered) {
            Session::flash('admin_temp_password', [
                'name'     => (string) $data['name'],
                'email'    => strtolower((string) $data['email']),
                'password' => $temporaryPassword,
            ]);

            $warning = 'Admin created, but the invite email could not be delivered. '
                     . 'Copy the temporary password now — it is shown only once.';

            if ($request->expectsJson()) {
                Response::success(
                    [
                        'id'                 => $adminId,
                        'email_delivered'    => false,
                        'temporary_password' => $temporaryPassword,
                    ],
                    $warning
                );
            }

            $this->back('warning', $warning, '/admin/admins');
        }

        $this->respond($request, 'Admin created. A temporary password has been emailed to them.', '/admin/admins');
    }

    /** POST /admin/admins/{id} */
    public function updateAdmin(Request $request): never
    {
        $this->authorize($request, 'admins.manage');

        $targetId = $request->routeParamInt('id');
        $target   = $this->findAdmin($targetId);

        $data = $this->validate($request, [
            'name'    => 'required|string|min:2|max:120',
            'email'   => 'required|email|max:191|unique:admins,email,' . $targetId,
            'phone'   => 'nullable|phone',
            'role_id' => 'required|integer|exists:roles,id',
            'status'  => 'required|in:active,inactive,suspended',
        ]);

        $this->assertCanManage($request, $target);
        $this->assertRoleAssignable($request, (int) $data['role_id']);

        // Guard the last Super Admin.
        if ($this->isLastSuperAdmin($target)
            && ((int) $data['role_id'] !== (int) $target['role_id'] || $data['status'] !== 'active')) {
            $this->back('error', 'This is the last active Super Admin. Promote another one before changing this account.');
        }

        Database::update('admins', [
            'name'    => (string) $data['name'],
            'email'   => strtolower((string) $data['email']),
            'phone'   => $data['phone'] ?? null,
            'role_id' => (int) $data['role_id'],
            'status'  => (string) $data['status'],
        ], ['id' => $targetId]);

        // A suspended admin must lose their live sessions at once.
        if ($data['status'] !== 'active') {
            Database::delete('admin_sessions', ['admin_id' => $targetId]);
        }

        PermissionService::flush($targetId);

        $this->log(
            $request,
            'admin.updated',
            'admin',
            $targetId,
            'Updated admin ' . (string) $target['email'],
            ActivityLogger::diff($target, $data)
        );

        $this->respond($request, 'Admin updated.', '/admin/admins');
    }

    /** POST /admin/admins/{id}/permissions */
    public function updateAdminPermissions(Request $request): never
    {
        $this->authorize($request, 'roles.manage');

        $targetId = $request->routeParamInt('id');
        $target   = $this->findAdmin($targetId);

        $this->assertCanManage($request, $target);

        // Nobody grants themselves a permission.
        //
        // assertCanManage() returns early when the target IS the caller, on the
        // reasoning that self-editing belongs on the account screens — but this
        // endpoint writes the permission set, so that exemption turned
        // `roles.manage` into "grant yourself all 84 permissions", including
        // admins.manage, system.restore and users.impersonate.
        if ($targetId === $this->adminId($request)) {
            $this->back('error', 'You cannot change your own permissions. Ask another administrator.');
        }

        // And nobody grants a permission they do not hold. Otherwise a
        // delegated `roles.manage` becomes a route to every capability in the
        // system by way of a second account.
        $mine = PermissionService::forAdmin($this->adminId($request));

        /** @var array<int|string,mixed> $submitted */
        $submitted = $request->array('overrides');
        $overrides = [];
        $refused   = [];

        foreach ($submitted as $permissionId => $effect) {
            if (!in_array($effect, ['allow', 'deny'], true)) {
                continue;
            }

            $slug = PermissionService::slugFor((int) $permissionId);

            // Revoking is always allowed — you may take away what you cannot
            // give. Only granting is constrained.
            if ($effect === 'allow' && $slug !== null && !in_array($slug, $mine, true)) {
                $refused[] = $slug;
                continue;
            }

            $overrides[(int) $permissionId] = (string) $effect;
        }

        if ($refused !== []) {
            $this->back(
                'error',
                'You cannot grant permissions you do not hold yourself: ' . implode(', ', $refused) . '.'
            );
        }

        PermissionService::syncAdminOverrides($targetId, $overrides);

        $this->log($request, 'admin.permissions_updated', 'admin', $targetId, count($overrides) . ' override(s) set.');

        $this->respond($request, 'Permission overrides saved.', '/admin/admins');
    }

    /** POST /admin/admins/{id}/delete */
    public function deleteAdmin(Request $request): never
    {
        $this->authorize($request, 'admins.manage');

        $targetId = $request->routeParamInt('id');

        if ($targetId === $this->adminId($request)) {
            $this->back('error', 'You cannot delete your own account.');
        }

        $target = $this->findAdmin($targetId);

        $this->assertCanManage($request, $target);

        if ($this->isLastSuperAdmin($target)) {
            $this->back('error', 'This is the last active Super Admin and cannot be deleted.');
        }

        Database::transaction(static function () use ($targetId): void {
            Database::update('admins', [
                'status'     => 'inactive',
                'deleted_at' => date('Y-m-d H:i:s'),
            ], ['id' => $targetId]);

            Database::delete('admin_sessions', ['admin_id' => $targetId]);
        });

        PermissionService::flush($targetId);

        $this->log($request, 'admin.deleted', 'admin', $targetId, 'Deleted admin ' . (string) $target['email']);

        $this->respond($request, 'Admin account removed.', '/admin/admins');
    }

    // =================================================================
    // Roles
    // =================================================================

    /** GET /admin/roles */
    public function roles(Request $request): never
    {
        $this->authorize($request, 'roles.manage');

        $roles = Database::select(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM `admins` a WHERE a.`role_id` = r.`id` AND a.`deleted_at` IS NULL) AS `admin_count`,
                    (SELECT COUNT(*) FROM `role_permissions` rp WHERE rp.`role_id` = r.`id`) AS `permission_count`
             FROM `roles` r ORDER BY r.`level` DESC'
        );

        $assigned = [];

        foreach (Database::select('SELECT `role_id`, `permission_id` FROM `role_permissions`') as $row) {
            $assigned[(int) $row['role_id']][] = (int) $row['permission_id'];
        }

        $this->view($request, 'admin.access.roles', [
            'pageTitle'    => 'Roles & permissions',
            'pageSubtitle' => count($roles) . ' roles',
            'roles'        => $roles,
            'grouped'      => PermissionService::grouped(),
            'assigned'     => $assigned,
            'myLevel'      => (int) $this->admin($request)['role_level'],
        ]);
    }

    /** POST /admin/roles */
    public function storeRole(Request $request): never
    {
        $this->authorize($request, 'roles.manage');

        $data = $this->validate($request, [
            'name'        => 'required|string|min:2|max:64',
            'description' => 'nullable|string|max:255',
            'level'       => 'required|integer|min_value:1|max_value:99',
        ]);

        $myLevel = (int) $this->admin($request)['role_level'];

        if ((int) $data['level'] >= $myLevel) {
            $this->back('error', 'You cannot create a role at or above your own level.');
        }

        $roleId = Database::insert('roles', [
            'name'        => (string) $data['name'],
            'slug'        => Str::uniqueSlug((string) $data['name'], 'roles', 'slug'),
            'description' => $data['description'] ?? null,
            'level'       => (int) $data['level'],
            'is_system'   => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        PermissionService::syncRolePermissions(
            $roleId,
            $this->grantablePermissions($request, array_map('intval', $request->array('permissions')))
        );

        $this->log($request, 'role.created', 'role', $roleId, 'Created role ' . (string) $data['name']);

        $this->respond($request, 'Role created.', '/admin/roles');
    }

    /** POST /admin/roles/{id} */
    public function updateRole(Request $request): never
    {
        $this->authorize($request, 'roles.manage');

        $roleId = $request->routeParamInt('id');

        $role = Database::selectOne('SELECT * FROM `roles` WHERE `id` = :id', ['id' => $roleId]);

        if ($role === null) {
            throw HttpException::notFound('Role not found.');
        }

        $myLevel = (int) $this->admin($request)['role_level'];

        if ((int) $role['level'] >= $myLevel) {
            $this->back('error', 'You cannot modify a role at or above your own level.');
        }

        $data = $this->validate($request, [
            'name'        => 'required|string|min:2|max:64',
            'description' => 'nullable|string|max:255',
        ]);

        Database::update('roles', [
            'name'        => (string) $data['name'],
            'description' => $data['description'] ?? null,
        ], ['id' => $roleId]);

        PermissionService::syncRolePermissions(
            $roleId,
            $this->grantablePermissions($request, array_map('intval', $request->array('permissions')))
        );

        $this->log($request, 'role.updated', 'role', $roleId, 'Updated role ' . (string) $role['name']);

        $this->respond($request, 'Role updated.', '/admin/roles');
    }

    /** POST /admin/roles/{id}/delete */
    public function deleteRole(Request $request): never
    {
        $this->authorize($request, 'roles.manage');

        $roleId = $request->routeParamInt('id');

        $role = Database::selectOne('SELECT * FROM `roles` WHERE `id` = :id', ['id' => $roleId]);

        if ($role === null) {
            throw HttpException::notFound('Role not found.');
        }

        if ((int) $role['is_system'] === 1) {
            $this->back('error', 'System roles cannot be deleted.');
        }

        $inUse = (int) Database::scalar(
            'SELECT COUNT(*) FROM `admins` WHERE `role_id` = :id AND `deleted_at` IS NULL',
            ['id' => $roleId]
        );

        if ($inUse > 0) {
            $this->back('error', $inUse . ' admin(s) still use this role. Reassign them first.');
        }

        // fk_admins_role is ON DELETE RESTRICT and the database does not know
        // about `deleted_at`, so an archived admin holds the role just as firmly
        // as a live one. Counting only live admins let the guard wave the delete
        // through and the DELETE then blew up as an unhandled 1451 — a 500 with
        // the raw SQLSTATE on screen. Archived holders are reported separately
        // because the fix is different: they are in the recycle bin, not on the
        // admins list, so "reassign them first" was advice the operator could
        // not act on.
        $archived = (int) Database::scalar(
            'SELECT COUNT(*) FROM `admins` WHERE `role_id` = :id AND `deleted_at` IS NOT NULL',
            ['id' => $roleId]
        );

        if ($archived > 0) {
            $this->back(
                'error',
                $archived . ' deleted admin account(s) still reference this role. Restore them from the '
                . 'recycle bin and move them to another role, or purge them there, then delete the role.'
            );
        }

        // Belt and braces: approval_steps and dashboard_layouts null out and
        // role_permissions cascades, but a row can still be created between the
        // guard and the DELETE. Never let that reach the user as a 500.
        try {
            Database::delete('roles', ['id' => $roleId]);
        } catch (\PDOException) {
            $this->back('error', 'This role is still referenced elsewhere and cannot be deleted yet.');
        }

        PermissionService::flush();

        $this->log($request, 'role.deleted', 'role', $roleId, 'Deleted role ' . (string) $role['name']);

        $this->respond($request, 'Role deleted.', '/admin/roles');
    }

    // =================================================================
    // Activity log
    // =================================================================

    /** GET /admin/logs */
    public function activityLog(Request $request): never
    {
        $this->authorize($request, 'logs.activity');

        $filters = [
            'actor_type'   => $request->string('actor_type') ?: null,
            'actor_id'     => $request->int('actor_id') ?: null,
            'subject_type' => $request->string('subject_type') ?: null,
            'action'       => $request->string('action') ?: null,
            'from'         => $request->string('from') ?: null,
            'to'           => $request->string('to') ?: null,
        ];

        $exportAs = strtolower($request->string('export'));

        // The toolbar offers CSV, Excel and PDF. Only 'csv' was handled, so the
        // other two fell through to the HTML list: the operator clicked Excel
        // and the page silently reloaded.
        if (in_array($exportAs, ['csv', 'xlsx', 'pdf'], true)) {
            $this->authorize($request, 'export.data');

            // The export used to be handed the current page, so a filtered
            // 4,000-entry search downloaded as 50 rows with nothing to say so.
            $this->exportEntries(
                $exportAs,
                ['ID', 'Actor type', 'Actor', 'Action', 'Subject', 'Subject ID', 'Description', 'IP', 'When'],
                $this->allActivityEntries($filters),
                ['id', 'actor_type', 'actor_name', 'action', 'subject_type', 'subject_id', 'description', 'ip_address', 'created_at']
            );
        }

        $result = ActivityLogger::paginate($filters + [
            'page'     => $request->page(),
            'per_page' => $request->perPage(50, 200),
        ]);

        foreach ($result['items'] as &$entry) {
            $entry['properties'] = json_decode((string) ($entry['properties'] ?? 'null'), true);
        }
        unset($entry);

        $this->view($request, 'admin.access.logs', [
            'pageTitle'    => 'Activity log',
            'pageSubtitle' => number_format($result['total']) . ' entries',
            'entries'      => $result['items'],
            'pagination'   => $this->paginationMeta($result['total'], $result['page'], $result['per_page']),
            'filters'      => $request->all(),
            'actions'      => Database::column(
                'SELECT DISTINCT `action` FROM `activity_logs` ORDER BY `action` LIMIT 100'
            ),
        ]);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Write the activity log in the format the toolbar asked for.
     *
     * Flattened once so a value can never appear in one format and not another,
     * exactly as ResourceController and UserController do it.
     *
     * @param list<string>              $labels
     * @param list<array<string,mixed>> $rows
     * @param list<string>              $columns
     */
    private function exportEntries(string $format, array $labels, array $rows, array $columns): never
    {
        if ($format === 'csv') {
            $this->exportCsv('zuste-activity-log', $labels, $rows, $columns);
        }

        $matrix = [];

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $value  = $row[$column] ?? '';
                $line[] = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
            }

            $matrix[] = $line;
        }

        if ($format === 'xlsx') {
            ExportService::xlsx('zuste-activity-log', 'Activity log', $labels, $matrix);
        }

        // Delivered as a print-ready A4 page that fires the browser's print
        // dialog, exactly as ResourceController does it.
        ExportService::pdf(
            'Activity log',
            $labels,
            $matrix,
            'Exported ' . date('d M Y, g:i A') . ' — ' . count($matrix) . ' row(s)'
        );
    }

    /**
     * Every activity entry the filters match, for the CSV export.
     *
     * ActivityLogger::paginate() clamps per_page at 200, so a single call can
     * never return the whole result set — walk the pages instead. Capped so a
     * filterless export of a long-lived audit trail cannot exhaust memory; past
     * that a download should be a background job.
     *
     * @param  array<string,mixed>      $filters
     * @return list<array<string,mixed>>
     */
    private function allActivityEntries(array $filters): array
    {
        $rows = [];
        $page = 1;

        do {
            $chunk = ActivityLogger::paginate($filters + ['page' => $page, 'per_page' => 200]);

            foreach ($chunk['items'] as $item) {
                $rows[] = $item;
            }

            $page++;
        } while ($chunk['items'] !== [] && $page <= $chunk['last_page'] && count($rows) < self::EXPORT_LIMIT);

        return array_slice($rows, 0, self::EXPORT_LIMIT);
    }

    /** @return array<string,mixed> */
    private function findAdmin(int $adminId): array
    {
        $admin = Database::selectOne(
            'SELECT a.*, r.`level` AS `role_level`, r.`slug` AS `role_slug`
             FROM `admins` a INNER JOIN `roles` r ON r.`id` = a.`role_id`
             WHERE a.`id` = :id AND a.`deleted_at` IS NULL',
            ['id' => $adminId]
        );

        if ($admin === null) {
            throw HttpException::notFound('Admin not found.');
        }

        return $admin;
    }

    /** An admin may only manage accounts strictly below their own level. */
    /**
     * The permission ids the caller is allowed to hand out.
     *
     * Nobody may grant a permission they do not hold themselves. Without this,
     * `roles.manage` was effectively `*`: create a role one level below your
     * own, tick every box including `system.restore`, `wallet.adjust` and
     * `users.impersonate`, then assign that role to yourself — assertCanManage()
     * returns early when the target IS you, and assertRoleAssignable() only
     * compares levels. Two requests, and a level-70 admin holds all 84
     * permissions.
     *
     * Refuses rather than silently dropping the extras: an admin who ticked a
     * box needs to know it did not take effect.
     *
     * @param  list<int> $ids
     * @return list<int>
     */
    private function grantablePermissions(Request $request, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === [] || PermissionService::isSuperAdmin($this->adminId($request))) {
            return $ids;
        }

        $mine = PermissionService::forAdmin($this->adminId($request));

        $requested = Database::select(
            'SELECT `id`, `slug`, `name` FROM `permissions` WHERE `id` IN ('
            . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids
        );

        $refused = [];

        foreach ($requested as $permission) {
            if (!in_array((string) $permission['slug'], $mine, true)) {
                $refused[] = (string) $permission['name'];
            }
        }

        if ($refused !== []) {
            $this->back(
                'error',
                'You can only grant permissions you hold yourself. Refused: '
                . implode(', ', array_slice($refused, 0, 6))
                . (count($refused) > 6 ? ' and ' . (count($refused) - 6) . ' more' : '') . '.'
            );
        }

        return $ids;
    }

    private function assertCanManage(Request $request, array $target): void
    {
        $myLevel = (int) $this->admin($request)['role_level'];

        if ((int) $target['id'] === $this->adminId($request)) {
            return; // Editing yourself is handled by the account screens.
        }

        if ((int) $target['role_level'] >= $myLevel) {
            $this->back('error', 'You cannot manage an account at or above your own role level.');
        }
    }

    private function assertRoleAssignable(Request $request, int $roleId): void
    {
        $myLevel = (int) $this->admin($request)['role_level'];

        $level = (int) (Database::scalar('SELECT `level` FROM `roles` WHERE `id` = :id', ['id' => $roleId]) ?? 999);

        if ($level >= $myLevel) {
            $this->back('error', 'You cannot assign a role at or above your own level.');
        }
    }

    private function isLastSuperAdmin(array $admin): bool
    {
        if ((int) $admin['role_level'] < 100) {
            return false;
        }

        $others = (int) Database::scalar(
            "SELECT COUNT(*) FROM `admins` a
             INNER JOIN `roles` r ON r.`id` = a.`role_id`
             WHERE r.`level` >= 100 AND a.`status` = 'active' AND a.`deleted_at` IS NULL AND a.`id` <> :id",
            ['id' => (int) $admin['id']]
        );

        return $others === 0;
    }

    /** @return list<array<string,mixed>> */
    private function assignableRoles(Request $request): array
    {
        return Database::select(
            'SELECT `id`, `name`, `level` FROM `roles` WHERE `level` < :level ORDER BY `level` DESC',
            ['level' => (int) $this->admin($request)['role_level']]
        );
    }

    /** Readable but high-entropy temporary password. */
    private function generateTemporaryPassword(): string
    {
        $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower  = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $all    = $upper . $lower . $digits;

        // Guarantee the character classes the password policy requires.
        $password = $upper[random_int(0, strlen($upper) - 1)]
                  . $lower[random_int(0, strlen($lower) - 1)]
                  . $digits[random_int(0, strlen($digits) - 1)];

        for ($i = 0; $i < 9; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }
}

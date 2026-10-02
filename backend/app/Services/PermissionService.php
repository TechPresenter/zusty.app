<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;

/**
 * Role-based access control for the admin panel.
 *
 * Effective permissions = role grants
 *                       + per-admin `allow` overrides
 *                       - per-admin `deny` overrides
 *
 * Deny always wins, so revoking a single capability from one operator never
 * requires cloning a whole role. Super Admin (level 100) short-circuits to
 * "everything" so the platform can never be locked out of itself.
 */
final class PermissionService
{
    private const SUPER_ADMIN_LEVEL = 100;

    /** @var array<int,list<string>> */
    private static array $cache = [];

    /**
     * Every permission slug this admin effectively holds.
     *
     * @return list<string>
     */
    public static function forAdmin(int $adminId): array
    {
        if (isset(self::$cache[$adminId])) {
            return self::$cache[$adminId];
        }

        $admin = Database::selectOne(
            'SELECT a.`role_id`, r.`level`
             FROM `admins` a
             INNER JOIN `roles` r ON r.`id` = a.`role_id`
             WHERE a.`id` = :id AND a.`deleted_at` IS NULL',
            ['id' => $adminId]
        );

        if ($admin === null) {
            return self::$cache[$adminId] = [];
        }

        if ((int) $admin['level'] >= self::SUPER_ADMIN_LEVEL) {
            /** @var list<string> $all */
            $all = Database::column('SELECT `slug` FROM `permissions`');

            return self::$cache[$adminId] = $all;
        }

        /** @var list<string> $fromRole */
        $fromRole = Database::column(
            'SELECT p.`slug`
             FROM `role_permissions` rp
             INNER JOIN `permissions` p ON p.`id` = rp.`permission_id`
             WHERE rp.`role_id` = :role_id',
            ['role_id' => (int) $admin['role_id']]
        );

        $overrides = Database::select(
            'SELECT p.`slug`, ap.`effect`
             FROM `admin_permissions` ap
             INNER JOIN `permissions` p ON p.`id` = ap.`permission_id`
             WHERE ap.`admin_id` = :admin_id',
            ['admin_id' => $adminId]
        );

        $granted = array_flip($fromRole);

        foreach ($overrides as $override) {
            if ($override['effect'] === 'allow') {
                $granted[$override['slug']] = true;
            } else {
                unset($granted[$override['slug']]);
            }
        }

        return self::$cache[$adminId] = array_values(array_map('strval', array_keys($granted)));
    }

    public static function adminCan(int $adminId, string $permission): bool
    {
        if ($permission === '') {
            return true;
        }

        return in_array($permission, self::forAdmin($adminId), true);
    }

    /** True when the admin holds at least one of the listed permissions. */
    public static function adminCanAny(int $adminId, string ...$permissions): bool
    {
        $held = self::forAdmin($adminId);

        foreach ($permissions as $permission) {
            if (in_array($permission, $held, true)) {
                return true;
            }
        }

        return false;
    }

    public static function adminCanAll(int $adminId, string ...$permissions): bool
    {
        $held = self::forAdmin($adminId);

        foreach ($permissions as $permission) {
            if (!in_array($permission, $held, true)) {
                return false;
            }
        }

        return true;
    }

    public static function isSuperAdmin(int $adminId): bool
    {
        $level = Database::scalar(
            'SELECT r.`level` FROM `admins` a INNER JOIN `roles` r ON r.`id` = a.`role_id` WHERE a.`id` = :id',
            ['id' => $adminId]
        );

        return (int) ($level ?? 0) >= self::SUPER_ADMIN_LEVEL;
    }

    /**
     * All permissions grouped for the role editor UI.
     *
     * @return array<string,list<array{id:int,slug:string,name:string,description:string}>>
     */
    /**
     * The slug behind a permission id.
     *
     * Needed so a grant can be checked against what the granting admin
     * actually holds — the override form submits ids, the effective set is
     * slugs.
     */
    public static function slugFor(int $permissionId): ?string
    {
        $slug = Database::scalar(
            'SELECT `slug` FROM `permissions` WHERE `id` = :id',
            ['id' => $permissionId]
        );

        return is_string($slug) ? $slug : null;
    }

    public static function grouped(): array
    {
        // `is_available = 0` marks a permission that is defined and granted
        // but checked nowhere, so granting it does nothing. Hiding those here
        // keeps the role editor honest: every box an operator can tick has an
        // effect. See migration 025.
        $rows = Database::select(
            'SELECT `id`, `group_name`, `name`, `slug`, `description`
             FROM `permissions`
             WHERE `is_available` = 1
             ORDER BY `sort_order`, `id`'
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row['group_name']][] = [
                'id'          => (int) $row['id'],
                'slug'        => (string) $row['slug'],
                'name'        => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
            ];
        }

        return $grouped;
    }

    /**
     * Replace a role's permission set atomically.
     *
     * @param list<int> $permissionIds
     */
    public static function syncRolePermissions(int $roleId, array $permissionIds): void
    {
        Database::transaction(static function () use ($roleId, $permissionIds): void {
            Database::delete('role_permissions', ['role_id' => $roleId]);

            foreach (array_unique(array_map('intval', $permissionIds)) as $permissionId) {
                if ($permissionId <= 0) {
                    continue;
                }

                Database::insertIgnore('role_permissions', [
                    'role_id'       => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        });

        self::flush();
    }

    /**
     * Replace an individual admin's overrides.
     *
     * @param array<int,string> $overrides permission id => 'allow'|'deny'
     */
    public static function syncAdminOverrides(int $adminId, array $overrides): void
    {
        Database::transaction(static function () use ($adminId, $overrides): void {
            Database::delete('admin_permissions', ['admin_id' => $adminId]);

            foreach ($overrides as $permissionId => $effect) {
                if (!in_array($effect, ['allow', 'deny'], true)) {
                    continue;
                }

                Database::insertIgnore('admin_permissions', [
                    'admin_id'      => $adminId,
                    'permission_id' => (int) $permissionId,
                    'effect'        => $effect,
                ]);
            }
        });

        self::flush($adminId);
    }

    /** Drop the in-process cache after any permission change. */
    public static function flush(?int $adminId = null): void
    {
        if ($adminId === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$adminId]);
    }
}

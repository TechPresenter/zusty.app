<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\Logger;

/**
 * Audit trail.
 *
 * Every mutation that a human can be held accountable for is recorded here:
 * who did it, to what, and what changed. `diff()` stores only the fields that
 * actually moved, so an audit row stays readable and small.
 */
final class ActivityLogger
{
    /** Values that are never written to the audit trail. */
    private const SENSITIVE = [
        'password', 'password_hash', 'two_factor_secret', 'token_hash', 'code_hash',
        'account_number', 'document_number', 'key_secret', 'api_key', 'service_account_json',
    ];

    /**
     * @param array<string,mixed> $properties
     */
    public static function log(
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        string $description = '',
        array $properties = [],
        string $actorType = 'system',
        ?int $actorId = null,
        ?string $actorName = null
    ): void {
        try {
            Database::insert('activity_logs', [
                'actor_type'   => $actorType,
                'actor_id'     => $actorId,
                'actor_name'   => $actorName === null ? null : mb_substr($actorName, 0, 120),
                'action'       => mb_substr($action, 0, 64),
                'subject_type' => $subjectType === null ? null : mb_substr($subjectType, 0, 64),
                'subject_id'   => $subjectId === null ? null : (int) $subjectId,
                'description'  => mb_substr($description, 0, 255),
                'properties'   => $properties === [] ? null : json_encode(self::scrub($properties), JSON_UNESCAPED_UNICODE),
                'ip_address'   => self::ip(),
                'user_agent'   => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the operation it is auditing.
            Logger::error('Failed to write activity log', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** Record an action performed by a signed-in admin. */
    public static function admin(
        array $admin,
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        string $description = '',
        array $properties = []
    ): void {
        self::log(
            $action,
            $subjectType,
            $subjectId,
            $description,
            $properties,
            'admin',
            (int) ($admin['id'] ?? 0),
            (string) ($admin['name'] ?? 'Admin')
        );
    }

    /** Record an action performed by an app user. */
    public static function user(
        array $user,
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        string $description = '',
        array $properties = []
    ): void {
        self::log(
            $action,
            $subjectType,
            $subjectId,
            $description,
            $properties,
            'user',
            (int) ($user['id'] ?? 0),
            (string) ($user['name'] ?? 'User')
        );
    }

    /**
     * Compute a before/after diff of the fields that changed.
     *
     * @param  array<string,mixed> $before
     * @param  array<string,mixed> $after
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $changedBefore = [];
        $changedAfter  = [];

        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;

            // Loose comparison: MySQL hands back "1" where PHP supplied 1.
            if ((string) $oldValue === (string) $newValue) {
                continue;
            }

            $changedBefore[$key] = $oldValue;
            $changedAfter[$key]  = $newValue;
        }

        return [
            'before' => self::scrub($changedBefore),
            'after'  => self::scrub($changedAfter),
        ];
    }

    /**
     * Paginated audit trail for the admin panel.
     *
     * @param  array{actor_type?:string,actor_id?:int,subject_type?:string,subject_id?:int,action?:string,from?:string,to?:string,page?:int,per_page?:int} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function paginate(array $filters = []): array
    {
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $clauses  = ['1 = 1'];
        $bindings = [];

        if (!empty($filters['actor_type'])) {
            $clauses[]             = '`actor_type` = :actor_type';
            $bindings['actor_type'] = $filters['actor_type'];
        }

        if (!empty($filters['actor_id'])) {
            $clauses[]           = '`actor_id` = :actor_id';
            $bindings['actor_id'] = (int) $filters['actor_id'];
        }

        if (!empty($filters['subject_type'])) {
            $clauses[]               = '`subject_type` = :subject_type';
            $bindings['subject_type'] = $filters['subject_type'];
        }

        if (!empty($filters['subject_id'])) {
            $clauses[]             = '`subject_id` = :subject_id';
            $bindings['subject_id'] = (int) $filters['subject_id'];
        }

        if (!empty($filters['action'])) {
            $clauses[]          = '`action` LIKE :action';
            $bindings['action'] = '%' . $filters['action'] . '%';
        }

        if (!empty($filters['from'])) {
            $clauses[]        = '`created_at` >= :from';
            $bindings['from'] = $filters['from'] . ' 00:00:00';
        }

        if (!empty($filters['to'])) {
            $clauses[]      = '`created_at` <= :to';
            $bindings['to'] = $filters['to'] . ' 23:59:59';
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `activity_logs`' . $where, $bindings);

        $items = Database::select(
            'SELECT * FROM `activity_logs`' . $where . ' ORDER BY `id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings
        );

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function scrub(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE, true)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::scrub($value);
            } elseif (is_string($value) && strlen($value) > 1000) {
                $clean[$key] = substr($value, 0, 1000) . '…';
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private static function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
            $value = (string) ($_SERVER[$header] ?? '');
            if ($value === '') {
                continue;
            }

            $candidate = trim(explode(',', $value)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }
}

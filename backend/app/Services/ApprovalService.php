<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;

/**
 * Multi-level approvals.
 *
 * A workflow describes an ordered list of steps for one entity type. When
 * something needs sign-off, `open()` picks the workflow that applies and parks
 * a request on its first step. Each `act()` records one decision and moves the
 * request to the next step, or finishes it.
 *
 * The decision path is deliberately narrow: `act()` is the only way a request
 * changes state, it always runs inside a transaction with the request row
 * locked, and it re-checks the actor's authority against the *current* step
 * every time. Two approvers pressing Approve at the same moment therefore
 * cannot both advance the request — the second one blocks on the lock, then
 * sees the step it was shown is no longer current.
 *
 * One admin decides at most one step of a request. Steps are often satisfiable
 * by the same person (one names a role, the next a permission that role holds),
 * so without that rule a single admin could walk a two-approver workflow all
 * the way through on their own and the second signature would mean nothing.
 */
final class ApprovalService
{
    /** The `approval_workflows`.`entity_type` enum, with display labels. */
    public const ENTITY_TYPES = [
        'creator'        => 'Creator profile',
        'kyc'            => 'KYC document',
        'withdrawal'     => 'Withdrawal',
        'refund'         => 'Refund',
        'blog'           => 'Blog post',
        'page'           => 'Page',
        'booking_cancel' => 'Booking cancellation',
    ];

    /**
     * Where the subject of each entity type lives, so a request can never be
     * raised against something that does not exist.
     *
     * [table, label column, has a soft-delete column]
     *
     * @var array<string,array{0:string,1:string,2:bool}>
     */
    private const ENTITY_SOURCES = [
        'creator'        => ['creators', 'display_name', true],
        'kyc'            => ['kyc_documents', 'holder_name', false],
        'withdrawal'     => ['withdrawal_requests', 'reference', false],
        'refund'         => ['refunds', 'reference', false],
        'blog'           => ['blogs', 'title', true],
        'page'           => ['pages', 'title', false],
        'booking_cancel' => ['bookings', 'code', true],
    ];

    // -----------------------------------------------------------------
    // Raising a request
    // -----------------------------------------------------------------

    /**
     * Park an entity on the first step of whichever workflow covers it.
     *
     * Returns the request id, or null when no workflow applies — that is the
     * normal answer for a payout below the threshold, not a failure, and the
     * caller should simply carry on with the unapproved path.
     *
     * `approval_requests` carries a UNIQUE key on (entity_type, entity_id), so
     * a subject can only ever have one approval request. Calling this again
     * for the same subject therefore hands back the existing request whatever
     * state it is in; use {@see existingFor()} first when the caller needs to
     * tell "created" from "already there".
     */
    public static function open(
        string $entityType,
        int $entityId,
        ?float $amount,
        string $summary,
        int $requestedBy
    ): ?int {
        if (!isset(self::ENTITY_TYPES[$entityType]) || $entityId <= 0) {
            return null;
        }

        $existing = self::existingFor($entityType, $entityId);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $workflow = self::workflowFor($entityType, $amount);

        if ($workflow === null) {
            return null;
        }

        $firstStep = Database::scalar(
            'SELECT MIN(`step_order`) FROM `approval_steps` WHERE `workflow_id` = :workflow_id',
            ['workflow_id' => (int) $workflow['id']]
        );

        // A workflow with no steps can never complete, so it does not apply.
        if ($firstStep === null) {
            Logger::warning('Approval workflow has no steps', [
                'workflow_id' => (int) $workflow['id'],
                'entity_type' => $entityType,
            ]);

            return null;
        }

        try {
            return Database::insert('approval_requests', [
                'workflow_id'  => (int) $workflow['id'],
                'entity_type'  => $entityType,
                'entity_id'    => $entityId,
                'current_step' => (int) $firstStep,
                'status'       => 'pending',
                'amount'       => $amount,
                'summary'      => mb_substr($summary, 0, 255),
                'requested_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            // Two callers raced on the same subject. The unique key means the
            // other one won, so hand back the request it created.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }

            $raced = self::existingFor($entityType, $entityId);

            return $raced === null ? null : (int) $raced['id'];
        }
    }

    /**
     * The approval request already recorded against a subject, if any.
     *
     * @return array<string,mixed>|null
     */
    public static function existingFor(string $entityType, int $entityId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `approval_requests`
              WHERE `entity_type` = :entity_type AND `entity_id` = :entity_id
              LIMIT 1',
            ['entity_type' => $entityType, 'entity_id' => $entityId]
        );
    }

    /**
     * The active workflow that covers this entity type at this amount.
     *
     * A workflow with no threshold covers everything; one with a threshold
     * covers amounts at or above it. The tightest threshold wins, so a
     * ₹80,000 payout matches "above ₹50,000" rather than the catch-all.
     *
     * @return array<string,mixed>|null
     */
    public static function workflowFor(string $entityType, ?float $amount): ?array
    {
        if ($amount === null) {
            return Database::selectOne(
                'SELECT * FROM `approval_workflows`
                  WHERE `entity_type` = :entity_type AND `is_active` = 1 AND `threshold_amount` IS NULL
                  ORDER BY `id` ASC LIMIT 1',
                ['entity_type' => $entityType]
            );
        }

        // MySQL sorts NULL last under DESC, so the catch-all is the fallback.
        return Database::selectOne(
            'SELECT * FROM `approval_workflows`
              WHERE `entity_type` = :entity_type
                AND `is_active` = 1
                AND (`threshold_amount` IS NULL OR `threshold_amount` <= :amount)
              ORDER BY `threshold_amount` DESC, `id` ASC LIMIT 1',
            ['entity_type' => $entityType, 'amount' => $amount]
        );
    }

    // -----------------------------------------------------------------
    // Deciding
    // -----------------------------------------------------------------

    /**
     * Record one decision on the current step and move the request on.
     *
     * @param  string $action 'approved' or 'rejected'
     * @return array{ok:bool,message:string,status:string,completed:bool}
     */
    public static function act(int $requestId, int $adminId, string $action, string $note): array
    {
        if (!in_array($action, ['approved', 'rejected'], true)) {
            return self::result(false, 'That is not a decision this workflow understands.', '');
        }

        return Database::transaction(static function () use ($requestId, $adminId, $action, $note): array {
            // The lock is the whole point: everything below reads state that a
            // concurrent approver must not be able to change underneath us.
            $request = Database::selectOne(
                'SELECT * FROM `approval_requests` WHERE `id` = :id FOR UPDATE',
                ['id' => $requestId]
            );

            if ($request === null) {
                return self::result(false, 'That approval request no longer exists.', '');
            }

            $status = (string) $request['status'];

            if ($status !== 'pending') {
                return self::result(
                    false,
                    'Someone already ' . ($status === 'cancelled' ? 'cancelled' : $status) . ' this request.',
                    $status
                );
            }

            if ((int) ($request['requested_by'] ?? 0) === $adminId) {
                return self::result(false, 'You raised this request, so you cannot decide it.', 'pending');
            }

            // A multi-level workflow exists to put a second pair of eyes on
            // something. Several of the seeded steps are satisfiable by the
            // same admin (one by role, the next by permission), so without
            // this one person could walk a request through every step alone.
            if (self::hasDecided($requestId, $adminId)) {
                return self::result(
                    false,
                    'You already decided a step on this request. Another approver has to take the next one.',
                    'pending'
                );
            }

            $step = self::stepAtOrAfter((int) $request['workflow_id'], (int) $request['current_step']);

            if ($step === null) {
                return self::result(
                    false,
                    'This workflow has no step left to act on. Edit the workflow, then re-open the request.',
                    'pending'
                );
            }

            if (!self::adminMayActOn($step, $adminId)) {
                return self::result(
                    false,
                    'Step ' . (int) $step['step_order'] . ' (' . $step['name'] . ') is reserved for '
                    . self::stepAudience($step) . '.',
                    'pending'
                );
            }

            Database::insert('approval_actions', [
                'request_id' => $requestId,
                'step_order' => (int) $step['step_order'],
                'admin_id'   => $adminId,
                'action'     => $action,
                'note'       => $note === '' ? null : mb_substr($note, 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($action === 'rejected') {
                Database::update('approval_requests', [
                    'status'       => 'rejected',
                    'completed_at' => date('Y-m-d H:i:s'),
                ], ['id' => $requestId]);

                self::applyDecision(
                    (string) $request['entity_type'],
                    (int) $request['entity_id'],
                    false,
                    $adminId
                );

                return self::result(true, 'Request rejected.', 'rejected', true);
            }

            $nextStep = self::stepAfter((int) $request['workflow_id'], (int) $step['step_order']);

            if ($nextStep === null) {
                Database::update('approval_requests', [
                    'status'       => 'approved',
                    'completed_at' => date('Y-m-d H:i:s'),
                ], ['id' => $requestId]);

                // Recording the approval is not the same as acting on it. Until
                // this call existed, a fully approved request updated its own
                // row and left the subject exactly as it was — so the whole
                // engine was ceremony.
                self::applyDecision(
                    (string) $request['entity_type'],
                    (int) $request['entity_id'],
                    true,
                    $adminId
                );

                return self::result(true, 'Final approval recorded — the request is approved.', 'approved', true);
            }

            Database::update('approval_requests', [
                'current_step' => (int) $nextStep['step_order'],
            ], ['id' => $requestId]);

            return self::result(
                true,
                'Approved. It now sits with ' . $nextStep['name'] . '.',
                'pending'
            );
        });
    }

    /**
     * Withdraw a request that is still pending.
     *
     * @return array{ok:bool,message:string,status:string,completed:bool}
     */
    public static function cancel(int $requestId, int $adminId, string $reason): array
    {
        return Database::transaction(static function () use ($requestId, $adminId, $reason): array {
            $request = Database::selectOne(
                'SELECT * FROM `approval_requests` WHERE `id` = :id FOR UPDATE',
                ['id' => $requestId]
            );

            if ($request === null) {
                return self::result(false, 'That approval request no longer exists.', '');
            }

            if ((string) $request['status'] !== 'pending') {
                return self::result(false, 'Only a pending request can be cancelled.', (string) $request['status']);
            }

            Database::update('approval_requests', [
                'status'       => 'cancelled',
                'completed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $requestId]);

            // Kept on the trail so the timeline still explains what happened.
            Database::insert('approval_actions', [
                'request_id' => $requestId,
                'step_order' => (int) $request['current_step'],
                'admin_id'   => $adminId,
                'action'     => 'rejected',
                'note'       => mb_substr('Cancelled: ' . ($reason === '' ? 'no reason given' : $reason), 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return self::result(true, 'Request cancelled.', 'cancelled', true);
        });
    }

    // -----------------------------------------------------------------
    // Authority
    // -----------------------------------------------------------------

    /**
     * May this admin decide this step?
     *
     * A step names a role, a permission, or both. Holding either is enough.
     * A step that names neither is open to anyone with `workflow.act`.
     *
     * @param array<string,mixed> $step
     */
    public static function adminMayActOn(array $step, int $adminId): bool
    {
        // The module permission is the floor for every branch below.
        if (!PermissionService::adminCan($adminId, 'workflow.act')) {
            return false;
        }

        // A super admin is never locked out of the platform's own controls.
        if (self::isSuperAdmin($adminId)) {
            return true;
        }

        $roleId     = (int) ($step['role_id'] ?? 0);
        $permission = trim((string) ($step['permission'] ?? ''));

        if ($roleId === 0 && $permission === '') {
            return true;
        }

        if ($roleId > 0 && self::roleOf($adminId) === $roleId) {
            return true;
        }

        return $permission !== '' && PermissionService::adminCan($adminId, $permission);
    }

    /** Has this admin already recorded a decision on this request? */
    public static function hasDecided(int $requestId, int $adminId): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `approval_actions` WHERE `request_id` = :request_id AND `admin_id` = :admin_id',
            ['request_id' => $requestId, 'admin_id' => $adminId]
        ) > 0;
    }

    /**
     * Plain-English description of who a step is for, used in refusals.
     *
     * @param array<string,mixed> $step
     */
    public static function stepAudience(array $step): string
    {
        $parts  = [];
        $roleId = (int) ($step['role_id'] ?? 0);

        if ($roleId > 0) {
            $roleName = Database::scalar('SELECT `name` FROM `roles` WHERE `id` = :id', ['id' => $roleId]);
            $parts[]  = 'the ' . ($roleName === null ? 'role #' . $roleId : (string) $roleName) . ' role';
        }

        $permission = trim((string) ($step['permission'] ?? ''));

        if ($permission !== '') {
            $parts[] = 'holders of "' . $permission . '"';
        }

        return $parts === [] ? 'approvers' : implode(' or ', $parts);
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * The step a pending request is waiting on.
     *
     * Resolved as "the lowest step at or after `current_step`" so a workflow
     * whose steps were renumbered after a request was raised still lands on a
     * real step instead of stalling.
     *
     * @return array<string,mixed>|null
     */
    public static function stepAtOrAfter(int $workflowId, int $stepOrder): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `approval_steps`
              WHERE `workflow_id` = :workflow_id AND `step_order` >= :step_order
              ORDER BY `step_order` ASC LIMIT 1',
            ['workflow_id' => $workflowId, 'step_order' => $stepOrder]
        );
    }

    /** @return array<string,mixed>|null */
    public static function stepAfter(int $workflowId, int $stepOrder): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `approval_steps`
              WHERE `workflow_id` = :workflow_id AND `step_order` > :step_order
              ORDER BY `step_order` ASC LIMIT 1',
            ['workflow_id' => $workflowId, 'step_order' => $stepOrder]
        );
    }

    /**
     * Every step of a request's workflow with the decision taken on it.
     *
     * @return list<array{step:array<string,mixed>,action:array<string,mixed>|null,state:string}>
     */
    public static function timeline(int $requestId): array
    {
        $request = Database::selectOne(
            'SELECT * FROM `approval_requests` WHERE `id` = :id',
            ['id' => $requestId]
        );

        if ($request === null) {
            return [];
        }

        $steps = Database::select(
            'SELECT s.*, r.`name` AS `role_name`
               FROM `approval_steps` s
               LEFT JOIN `roles` r ON r.`id` = s.`role_id`
              WHERE s.`workflow_id` = :workflow_id
              ORDER BY s.`step_order` ASC',
            ['workflow_id' => (int) $request['workflow_id']]
        );

        $actions = Database::select(
            'SELECT a.*, ad.`name` AS `admin_name`, ad.`avatar` AS `admin_avatar`
               FROM `approval_actions` a
               LEFT JOIN `admins` ad ON ad.`id` = a.`admin_id`
              WHERE a.`request_id` = :request_id
              ORDER BY a.`id` ASC',
            ['request_id' => $requestId]
        );

        $byStep = [];
        foreach ($actions as $action) {
            $byStep[(int) $action['step_order']] = $action;
        }

        $pending    = (string) $request['status'] === 'pending';
        $activeStep = $pending
            ? self::stepAtOrAfter((int) $request['workflow_id'], (int) $request['current_step'])
            : null;
        $activeOrder = $activeStep === null ? -1 : (int) $activeStep['step_order'];

        $timeline = [];

        foreach ($steps as $step) {
            $order  = (int) $step['step_order'];
            $action = $byStep[$order] ?? null;

            $timeline[] = [
                'step'   => $step,
                'action' => $action,
                'state'  => match (true) {
                    $action !== null       => 'done',
                    $order === $activeOrder => 'current',
                    $pending                => 'upcoming',
                    default                 => 'skipped',
                },
            ];
        }

        return $timeline;
    }

    /**
     * How many pending requests this admin is personally able to decide.
     *
     * The role/permission test cannot be expressed in SQL against the override
     * table, so the candidate set is narrowed in the query and the authority
     * check runs in PHP over what is left.
     */
    public static function actionableCount(int $adminId): int
    {
        if (!PermissionService::adminCan($adminId, 'workflow.act')) {
            return 0;
        }

        $rows = Database::select(
            "SELECT r.`id`, s.`id` AS `step_id`, s.`role_id`, s.`permission`
               FROM `approval_requests` r
               LEFT JOIN `approval_steps` s ON s.`id` = (
                     SELECT s2.`id` FROM `approval_steps` s2
                      WHERE s2.`workflow_id` = r.`workflow_id` AND s2.`step_order` >= r.`current_step`
                      ORDER BY s2.`step_order` ASC LIMIT 1)
              WHERE r.`status` = 'pending'
                AND (r.`requested_by` IS NULL OR r.`requested_by` <> :admin_id)
                AND NOT EXISTS (
                      SELECT 1 FROM `approval_actions` d
                       WHERE d.`request_id` = r.`id` AND d.`admin_id` = :admin_id)
              LIMIT 500",
            ['admin_id' => $adminId]
        );

        $count = 0;

        foreach ($rows as $row) {
            // No step resolved means the workflow lost its steps: nobody can
            // act on it until it is repaired, so it is not this admin's queue.
            if ($row['step_id'] === null) {
                continue;
            }

            if (self::adminMayActOn($row, $adminId)) {
                $count++;
            }
        }

        return $count;
    }

    // -----------------------------------------------------------------
    // Subjects
    // -----------------------------------------------------------------

    /** Does the thing an approval is about actually exist? */
    public static function entityExists(string $entityType, int $entityId): bool
    {
        return self::entityLabel($entityType, $entityId) !== null;
    }

    /**
     * A human name for the subject of a request, or null when it is gone.
     *
     * The table and column come from a private map, never from the request, so
     * the identifiers below can be quoted rather than bound.
     */
    public static function entityLabel(string $entityType, int $entityId): ?string
    {
        if (!isset(self::ENTITY_SOURCES[$entityType]) || $entityId <= 0) {
            return null;
        }

        [$table, $labelColumn, $softDeletes] = self::ENTITY_SOURCES[$entityType];

        $sql = sprintf(
            'SELECT %s FROM %s WHERE `id` = :id%s LIMIT 1',
            Database::quoteIdentifier($labelColumn),
            Database::quoteIdentifier($table),
            $softDeletes ? ' AND `deleted_at` IS NULL' : ''
        );

        $value = Database::scalar($sql, ['id' => $entityId]);

        if ($value === null) {
            return null;
        }

        $label = trim((string) $value);

        return $label === '' ? '#' . $entityId : $label;
    }

    /**
     * Labels for a whole page of requests in one query per entity type.
     *
     * @param  list<array<string,mixed>> $requests rows carrying entity_type and entity_id
     * @return array<string,string>      "type:id" => label, absent when the subject is gone
     */
    public static function entityLabels(array $requests): array
    {
        $wanted = [];

        foreach ($requests as $request) {
            $type = (string) ($request['entity_type'] ?? '');
            $id   = (int) ($request['entity_id'] ?? 0);

            if ($id > 0 && isset(self::ENTITY_SOURCES[$type])) {
                $wanted[$type][$id] = true;
            }
        }

        $labels = [];

        foreach ($wanted as $type => $ids) {
            [$table, $labelColumn, $softDeletes] = self::ENTITY_SOURCES[$type];

            $ids         = array_keys($ids);
            $placeholders = [];
            $bindings     = [];

            foreach ($ids as $index => $id) {
                $placeholders[]        = ':id' . $index;
                $bindings['id' . $index] = $id;
            }

            // Identifiers come from the private map above, values are bound.
            $rows = Database::select(
                sprintf(
                    'SELECT `id`, %s AS `label` FROM %s WHERE `id` IN (%s)%s',
                    Database::quoteIdentifier($labelColumn),
                    Database::quoteIdentifier($table),
                    implode(', ', $placeholders),
                    $softDeletes ? ' AND `deleted_at` IS NULL' : ''
                ),
                $bindings
            );

            foreach ($rows as $row) {
                $label = trim((string) ($row['label'] ?? ''));
                $labels[$type . ':' . (int) $row['id']] = $label === '' ? '#' . (int) $row['id'] : $label;
            }
        }

        return $labels;
    }

    /** Where an approver should go to look at the subject. */
    public static function entityUrl(string $entityType, int $entityId): ?string
    {
        return match ($entityType) {
            'creator'        => '/admin/creators/' . $entityId,
            'kyc'            => '/admin/kyc',
            'withdrawal'     => '/admin/withdrawals',
            'refund'         => '/admin/refunds',
            'blog'           => '/admin/blogs/' . $entityId . '/edit',
            'page'           => '/admin/pages/' . $entityId . '/edit',
            'booking_cancel' => '/admin/bookings/' . $entityId,
            default          => null,
        };
    }

    public static function entityLabelFor(string $entityType): string
    {
        return self::ENTITY_TYPES[$entityType] ?? ucfirst(str_replace('_', ' ', $entityType));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * A queue page runs the authority test once per row, so the two facts it
     * needs about an admin are read once per request rather than per row.
     *
     * @var array<int,int>
     */
    private static array $roleCache = [];

    /** @var array<int,bool> */
    private static array $superCache = [];

    private static function roleOf(int $adminId): int
    {
        if (isset(self::$roleCache[$adminId])) {
            return self::$roleCache[$adminId];
        }

        $roleId = Database::scalar(
            'SELECT `role_id` FROM `admins` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $adminId]
        );

        return self::$roleCache[$adminId] = $roleId === null ? 0 : (int) $roleId;
    }

    private static function isSuperAdmin(int $adminId): bool
    {
        return self::$superCache[$adminId] ??= PermissionService::isSuperAdmin($adminId);
    }

    /** @return array{ok:bool,message:string,status:string,completed:bool} */
    /**
     * Carries a completed decision through to the subject.
     *
     * Runs inside the caller's transaction, so a failure here rolls the
     * approval back too — an approval recorded against an entity that was
     * never actually updated is worse than no approval at all.
     *
     * Entity types with no branch are deliberately no-ops: `blog`, `page` and
     * `kyc` are reviewed in their own modules, and routing them through here
     * as well would give two places that can change the same row.
     */
    private static function applyDecision(
        string $entityType,
        int $entityId,
        bool $approved,
        int $adminId
    ): void {
        $now = date('Y-m-d H:i:s');

        switch ($entityType) {
            case 'withdrawal':
                if ($approved) {
                    // Only a still-pending payout may move. If an admin settled
                    // it by another route while the request sat in the queue,
                    // the approval must not resurrect or re-approve it.
                    Database::execute(
                        "UPDATE `withdrawal_requests`
                            SET `status`       = 'approved',
                                `approved_at`  = :approved_at,
                                `processed_by` = :admin_id
                          WHERE `id` = :id AND `status` = 'pending'",
                        [
                            'approved_at' => $now,
                            'admin_id'    => $adminId > 0 ? $adminId : null,
                            'id'          => $entityId,
                        ]
                    );

                    break;
                }

                // A rejection goes through WalletService, NOT a status UPDATE.
                //
                // Requesting a payout moves the amount from spendable into
                // `locked_balance`. Only WalletService::rejectWithdrawal()
                // releases it again. Writing the status directly marked the
                // payout rejected while leaving the creator's money locked —
                // invisible to them, unspendable, and not withdrawable either,
                // because the funds are no longer attached to a live request.
                try {
                    WalletService::rejectWithdrawal(
                        $entityId,
                        'Rejected through the approvals queue.',
                        $adminId
                    );
                } catch (HttpException $e) {
                    // Already finalised by another route — the money is not
                    // stranded, so record it and let the approval stand.
                    Logger::warning('Approved rejection skipped an already-final withdrawal', [
                        'withdrawal_id' => $entityId,
                        'reason'        => $e->getMessage(),
                    ]);
                }
                break;

            case 'refund':
                // Without this branch an approved refund did nothing: the
                // refund row stayed parked in `pending`, the money never
                // moved, and the queue showed the request as decided. The
                // operator had to go back to the booking and submit the refund
                // form a second time for it to settle — so the approval step
                // recorded a decision it never carried out.
                //
                // PaymentService owns both halves of the parked-refund
                // lifecycle, so this only has to dispatch.
                try {
                    if ($approved) {
                        PaymentService::settleParkedRefund($entityId);
                    } else {
                        PaymentService::abandonParkedRefund(
                            $entityId,
                            'Rejected through the approvals queue.'
                        );
                    }
                } catch (HttpException $e) {
                    // Settled or abandoned by another route while the request
                    // sat in the queue. The money is not stranded either way,
                    // so record it and let the approval stand.
                    Logger::warning('Approved refund decision skipped an already-final refund', [
                        'refund_id' => $entityId,
                        'approved'  => $approved,
                        'reason'    => $e->getMessage(),
                    ]);
                }
                break;

            case 'creator':
                Database::execute(
                    "UPDATE `creators`
                        SET `status`      = :status,
                            `approved_by` = :admin_id,
                            `approved_at` = :approved_at
                      WHERE `id` = :id AND `status` IN ('pending_review', 'draft')",
                    [
                        'status'      => $approved ? 'approved' : 'rejected',
                        'admin_id'    => $approved && $adminId > 0 ? $adminId : null,
                        'approved_at' => $approved ? $now : null,
                        'id'          => $entityId,
                    ]
                );
                break;

            case 'booking_cancel':
                // A refused cancellation leaves the booking exactly as it was;
                // an approved one is executed by BookingService so the refund
                // and ledger entries are produced by the same code as any other
                // cancellation.
                if (!$approved) {
                    break;
                }

                try {
                    BookingService::cancel(
                        $entityId,
                        0,
                        'Cancellation approved by an administrator.',
                        'admin'
                    );
                } catch (\Throwable $e) {
                    Logger::error('Approved booking cancellation could not be applied', [
                        'booking_id' => $entityId,
                        'error'      => $e->getMessage(),
                    ]);

                    throw $e;
                }
                break;

            default:
                // `refund`, `kyc`, `blog`, `page` — reviewed in their own
                // modules today. Logged rather than silently ignored so a
                // workflow configured against them is visibly inert instead of
                // appearing to work.
                Logger::warning('Approval completed for an entity type with no apply step', [
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'approved'    => $approved,
                ]);
        }
    }

    private static function result(bool $ok, string $message, string $status, bool $completed = false): array
    {
        return ['ok' => $ok, 'message' => $message, 'status' => $status, 'completed' => $completed];
    }
}

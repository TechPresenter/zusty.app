<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Controllers\Admin\WorkflowController;
use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Helpers\Str;

/**
 * The automation runner.
 *
 * Rules written on Admin → Workflow → Automation are stored as a trigger, a
 * conditions block and a list of actions. This service is what turns that
 * configuration into behaviour: the `automation:run` scheduled job calls
 * {@see runDue()}, which polls each active rule's source table for rows that
 * appeared since the rule last looked, evaluates the conditions against a
 * payload projected from that row, and performs the actions.
 *
 * Polling rather than event emission is deliberate. Every trigger already
 * names a table and the column that carries its signal (see {@see EVENTS}),
 * so no domain service has to be modified to dispatch anything, and a rule
 * added today starts working without a deploy.
 *
 * Three properties the design has to hold:
 *
 *  - **Idempotency.** `automation_rule_runs` carries a UNIQUE key on
 *    (rule_id, entity_id) and the INSERT is the guard: a rule can fire at most
 *    once per subject, whatever happens to the watermark or the clock.
 *  - **A moving watermark.** Each rule keeps a keyset cursor
 *    (`watermark_at`, `watermark_id`) so a pass never rescans history. A rule
 *    that has never run arms its watermark at the current time and fires on
 *    nothing, so activating a rule cannot replay months of rows.
 *  - **Isolation.** One rule, and one action inside a rule, may fail without
 *    taking the pass down. Failures are written to `automation_rules.last_error`
 *    and shown on the rules screen.
 *
 * Known limitation, stated rather than hidden: the cursor is a timestamp, so a
 * row written with a *backdated* signal column after the watermark has passed
 * it is not picked up. Every column named below is set to the wall clock at the
 * moment the event happens, so this does not arise in practice.
 */
final class AutomationService
{
    /** Rows a rule examines per query. */
    private const BATCH = 200;

    /** Batches a single rule may drain in one pass, so one busy rule cannot starve the rest. */
    private const MAX_BATCHES = 5;

    /**
     * Rows are only considered once their second has fully elapsed.
     *
     * Without this a row committed a few milliseconds after the SELECT but
     * inside the same second would sit behind the advanced watermark forever.
     */
    private const COMMIT_LAG_SECONDS = 5;

    private const WEBHOOK_CONNECT_TIMEOUT = 5;
    private const WEBHOOK_TIMEOUT         = 10;
    private const WEBHOOK_MAX_BYTES       = 65536;

    /**
     * Every trigger event, and the machinery to poll it.
     *
     *  - `label`    what the operator sees.
     *  - `signal`   the human description shown on the rules screen.
     *  - `table`    the source table.
     *  - `key`      its primary key, used as the idempotency subject.
     *  - `at`       the column that carries the signal. NULL rows never match.
     *  - `filter`   an extra SQL predicate, no bindings, written here not by an operator.
     *  - `fields`   payload key => source column. This is the payload the
     *               conditions are evaluated against and the only thing an
     *               action can read.
     *  - `settable` the columns `set_field` may write on this entity. Anything
     *               absent is rejected at save time and again at run time —
     *               without this list the action would be an arbitrary UPDATE
     *               handed to anyone holding workflow.manage.
     *
     * @var array<string,array{label:string,signal:string,table:string,key:string,at:string,filter:string,fields:array<string,string>,settable:list<string>}>
     */
    public const EVENTS = [
        'user.registered' => [
            'label'    => 'User registered',
            'signal'   => 'users.created_at',
            'table'    => 'users',
            'key'      => 'id',
            'at'       => 'created_at',
            'filter'   => '`deleted_at` IS NULL',
            'fields'   => [
                'user_id'   => 'id',
                'name'      => 'name',
                'email'     => 'email',
                'phone'     => 'phone',
                'user_type' => 'account_type',
                'status'    => 'status',
            ],
            'settable' => [],
        ],
        'creator.submitted' => [
            'label'    => 'Creator profile submitted',
            'signal'   => "creators.status = 'pending_review'",
            'table'    => 'creators',
            'key'      => 'id',
            'at'       => 'updated_at',
            'filter'   => "`status` = 'pending_review' AND `deleted_at` IS NULL",
            'fields'   => [
                'creator_id'          => 'id',
                'user_id'             => 'user_id',
                'display_name'        => 'display_name',
                'primary_category_id' => 'primary_category_id',
                'city'                => 'city',
                'profile_score'       => 'profile_score',
            ],
            'settable' => ['search_boost'],
        ],
        'creator.approved' => [
            'label'    => 'Creator approved',
            'signal'   => 'creators.approved_at',
            'table'    => 'creators',
            'key'      => 'id',
            'at'       => 'approved_at',
            'filter'   => "`status` = 'approved' AND `deleted_at` IS NULL",
            'fields'   => [
                'creator_id'      => 'id',
                'user_id'         => 'user_id',
                'display_name'    => 'display_name',
                'is_verified'     => 'is_verified',
                'commission_rate' => 'commission_rate',
            ],
            'settable' => ['is_featured', 'search_boost'],
        ],
        'kyc.submitted' => [
            'label'    => 'KYC document submitted',
            'signal'   => 'kyc_documents.created_at',
            'table'    => 'kyc_documents',
            'key'      => 'id',
            'at'       => 'created_at',
            'filter'   => '`deleted_at` IS NULL',
            'fields'   => [
                'kyc_id'        => 'id',
                'user_id'       => 'user_id',
                'document_type' => 'document_type',
                'holder_name'   => 'holder_name',
                'status'        => 'status',
            ],
            'settable' => [],
        ],
        'booking.created' => [
            'label'    => 'Booking created',
            'signal'   => 'bookings.created_at',
            'table'    => 'bookings',
            'key'      => 'id',
            'at'       => 'created_at',
            'filter'   => '`deleted_at` IS NULL',
            'fields'   => [
                'booking_id'    => 'id',
                'code'          => 'code',
                'brand_user_id' => 'brand_user_id',
                'creator_id'    => 'creator_id',
                'total_amount'  => 'total_amount',
                'platform'      => 'platform',
                'campaign_type' => 'campaign_type',
            ],
            'settable' => [],
        ],
        'booking.completed' => [
            'label'    => 'Booking completed',
            'signal'   => 'bookings.completed_at',
            'table'    => 'bookings',
            'key'      => 'id',
            'at'       => 'completed_at',
            'filter'   => '`deleted_at` IS NULL',
            'fields'   => [
                'booking_id'      => 'id',
                'code'            => 'code',
                'creator_id'      => 'creator_id',
                'total_amount'    => 'total_amount',
                'creator_earning' => 'creator_earning',
            ],
            'settable' => [],
        ],
        'payment.captured' => [
            'label'    => 'Payment captured',
            'signal'   => "payments.status = 'captured'",
            'table'    => 'payments',
            'key'      => 'id',
            'at'       => 'paid_at',
            'filter'   => "`status` = 'captured'",
            'fields'   => [
                'payment_id' => 'id',
                'booking_id' => 'booking_id',
                'user_id'    => 'user_id',
                'amount'     => 'amount',
                'currency'   => 'currency',
            ],
            'settable' => [],
        ],
        'withdrawal.requested' => [
            'label'    => 'Withdrawal requested',
            'signal'   => 'withdrawal_requests.created_at',
            'table'    => 'withdrawal_requests',
            'key'      => 'id',
            'at'       => 'created_at',
            'filter'   => '',
            'fields'   => [
                'withdrawal_id' => 'id',
                'reference'     => 'reference',
                'user_id'       => 'user_id',
                'amount'        => 'amount',
                'net_amount'    => 'net_amount',
                'method'        => 'method',
            ],
            'settable' => [],
        ],
        'ticket.created' => [
            'label'    => 'Support ticket opened',
            'signal'   => 'support_tickets.created_at',
            'table'    => 'support_tickets',
            'key'      => 'id',
            'at'       => 'created_at',
            'filter'   => '',
            'fields'   => [
                'ticket_id' => 'id',
                'code'      => 'code',
                'user_id'   => 'user_id',
                'subject'   => 'subject',
                'category'  => 'category',
                'priority'  => 'priority',
            ],
            'settable' => ['priority', 'category'],
        ],
        'review.posted' => [
            'label'    => 'Review posted',
            'signal'   => 'reviews.created_at',
            'table'    => 'reviews',
            'key'      => 'id',
            'at'       => 'created_at',
            'filter'   => '`deleted_at` IS NULL',
            'fields'   => [
                'review_id'   => 'id',
                'booking_id'  => 'booking_id',
                'creator_id'  => 'creator_id',
                'reviewer_id' => 'reviewer_id',
                'rating'      => 'rating',
            ],
            'settable' => ['is_flagged'],
        ],
        'dispute.opened' => [
            'label'    => 'Booking dispute opened',
            'signal'   => 'booking_disputes.created_at',
            'table'    => 'booking_disputes',
            'key'      => 'id',
            'at'       => 'created_at',
            'filter'   => '',
            'fields'   => [
                'dispute_id'    => 'id',
                'booking_id'    => 'booking_id',
                'raised_by'     => 'raised_by',
                'reason'        => 'reason',
                'refund_amount' => 'refund_amount',
            ],
            'settable' => [],
        ],
    ];

    /**
     * Which payload key carries the subject id for each approval entity type.
     *
     * The types missing here (refund, blog, page) have no trigger that can
     * supply their id, so `open_approval` refuses them rather than raising an
     * approval against entity 0.
     *
     * @var array<string,string>
     */
    private const APPROVAL_KEYS = [
        'creator'        => 'creator_id',
        'kyc'            => 'kyc_id',
        'withdrawal'     => 'withdrawal_id',
        'booking_cancel' => 'booking_id',
    ];

    // =================================================================
    // The pass
    // =================================================================

    /**
     * Evaluate every active rule against whatever appeared since its last pass.
     *
     * @return array<string,mixed>
     */
    public static function runDue(): array
    {
        $rules = Database::select(
            'SELECT * FROM `automation_rules` WHERE `is_active` = 1 ORDER BY `id` ASC'
        );

        $summary = [
            'rules'          => count($rules),
            'examined'       => 0,
            'fired'          => 0,
            'actions_ok'     => 0,
            'actions_failed' => 0,
            'problems'       => [],
        ];

        foreach ($rules as $rule) {
            $ruleId = (int) $rule['id'];

            // One malformed rule must not abort the pass for every other rule.
            try {
                $result = self::runRule($rule);

                $summary['examined']       += $result['examined'];
                $summary['fired']          += $result['fired'];
                $summary['actions_ok']     += $result['actions_ok'];
                $summary['actions_failed'] += $result['actions_failed'];

                if ($result['error'] !== '') {
                    $summary['problems'][] = ['rule_id' => $ruleId, 'error' => $result['error']];
                }
            } catch (\Throwable $e) {
                $summary['problems'][] = ['rule_id' => $ruleId, 'error' => $e->getMessage()];

                self::recordError($ruleId, $e->getMessage());

                Logger::error('Automation rule threw', [
                    'rule_id' => $ruleId,
                    'error'   => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed> $rule
     * @return array{examined:int,fired:int,actions_ok:int,actions_failed:int,error:string}
     */
    private static function runRule(array $rule): array
    {
        $ruleId  = (int) $rule['id'];
        $event   = (string) $rule['trigger_event'];
        $outcome = ['examined' => 0, 'fired' => 0, 'actions_ok' => 0, 'actions_failed' => 0, 'error' => ''];

        $meta = self::EVENTS[$event] ?? null;

        if ($meta === null) {
            $outcome['error'] = 'Trigger event "' . $event . '" is not one the runner knows.';
            self::recordError($ruleId, $outcome['error']);

            return $outcome;
        }

        $actions = json_decode((string) $rule['actions'], true);

        // The same shape the editor enforces: a non-empty JSON list. A rule
        // stored any other way can never do anything, so it is reported rather
        // than fired against every row it matches.
        if (!is_array($actions) || $actions === [] || array_keys($actions) !== range(0, count($actions) - 1)) {
            $outcome['error'] = 'The actions column does not hold a usable list of actions.';
            self::recordError($ruleId, $outcome['error']);

            return $outcome;
        }

        $conditionsRaw = (string) ($rule['conditions'] ?? '');
        $conditions    = $conditionsRaw === '' ? [] : json_decode($conditionsRaw, true);

        if (!is_array($conditions)) {
            $outcome['error'] = 'The conditions column does not hold a usable conditions block.';
            self::recordError($ruleId, $outcome['error']);

            return $outcome;
        }

        $cutoff = date('Y-m-d H:i:s', time() - self::COMMIT_LAG_SECONDS);

        // A rule that has never run arms its cursor at "now" and fires on
        // nothing, so switching a rule on never replays the back catalogue.
        if ($rule['watermark_at'] === null) {
            Database::update(
                'automation_rules',
                ['watermark_at' => $cutoff, 'watermark_id' => 0, 'last_error' => null],
                ['id' => $ruleId]
            );

            return $outcome;
        }

        $watermarkAt = (string) $rule['watermark_at'];
        $watermarkId = (int) ($rule['watermark_id'] ?? 0);

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $rows = self::fetchCandidates($meta, $watermarkAt, $watermarkId, $cutoff);

            if ($rows === []) {
                // Nothing left in this window, so the cursor can jump to it.
                $watermarkAt = $cutoff;
                $watermarkId = 0;
                break;
            }

            foreach ($rows as $row) {
                $outcome['examined']++;

                $entityId = (int) $row['__entity_id'];
                $payload  = self::projectPayload($meta, $row);

                $verdict = WorkflowController::evaluateConditions($conditions, $payload);

                $watermarkAt = (string) $row['__signal_at'];
                $watermarkId = $entityId;

                if (!$verdict['passed']) {
                    continue;
                }

                // The INSERT is the dedup guard: if another pass (or another
                // runner process) already claimed this subject the unique key
                // rejects it and no action runs twice.
                $claimed = Database::execute(
                    'INSERT IGNORE INTO `automation_rule_runs` (`rule_id`, `entity_id`, `fired_at`)
                     VALUES (:rule_id, :entity_id, :fired_at)',
                    ['rule_id' => $ruleId, 'entity_id' => $entityId, 'fired_at' => date('Y-m-d H:i:s')]
                );

                if ($claimed !== 1) {
                    continue;
                }

                $results = self::performActions($rule, $meta, $actions, $payload);

                foreach ($results as $result) {
                    if ($result['ok']) {
                        $outcome['actions_ok']++;
                    } else {
                        $outcome['actions_failed']++;
                    }
                }

                $outcome['fired']++;

                ActivityLogger::log(
                    'automation_rule.fired',
                    'automation_rule',
                    $ruleId,
                    'Rule "' . (string) $rule['name'] . '" fired on ' . $event . ' #' . $entityId . '.',
                    ['entity_id' => $entityId, 'trigger_event' => $event, 'actions' => $results],
                    'cron'
                );
            }

            if (count($rows) < self::BATCH) {
                $watermarkAt = $cutoff;
                $watermarkId = 0;
                break;
            }
        }

        Database::update(
            'automation_rules',
            ['watermark_at' => $watermarkAt, 'watermark_id' => $watermarkId],
            ['id' => $ruleId]
        );

        if ($outcome['fired'] > 0) {
            Database::execute(
                'UPDATE `automation_rules`
                    SET `run_count` = `run_count` + :fired, `last_run_at` = :now
                  WHERE `id` = :id',
                ['fired' => $outcome['fired'], 'now' => date('Y-m-d H:i:s'), 'id' => $ruleId]
            );
        }

        if ($outcome['actions_failed'] === 0) {
            self::recordError($ruleId, null);
        } else {
            $outcome['error'] = $outcome['actions_failed'] . ' action(s) failed on the last pass.';
            self::recordError($ruleId, $outcome['error']);
        }

        return $outcome;
    }

    /**
     * Rows of the source table that appeared after the cursor.
     *
     * Every identifier interpolated here comes from {@see EVENTS}, never from
     * a request or a stored rule, and the cursor itself is bound.
     *
     * @param  array<string,mixed> $meta
     * @return list<array<string,mixed>>
     */
    private static function fetchCandidates(array $meta, string $watermarkAt, int $watermarkId, string $cutoff): array
    {
        $table  = '`' . $meta['table'] . '`';
        $key    = '`' . $meta['key'] . '`';
        $at     = '`' . $meta['at'] . '`';
        $filter = $meta['filter'] === '' ? '' : ' AND (' . $meta['filter'] . ')';

        $columns = [$key . ' AS `__entity_id`', $at . ' AS `__signal_at`'];

        foreach ($meta['fields'] as $payloadKey => $column) {
            $columns[] = '`' . $column . '` AS `' . $payloadKey . '`';
        }

        return Database::select(
            'SELECT ' . implode(', ', $columns) . '
               FROM ' . $table . '
              WHERE ' . $at . ' IS NOT NULL
                AND ' . $at . ' <= :cutoff
                AND (' . $at . ' > :watermark_at OR (' . $at . ' = :watermark_at AND ' . $key . ' > :watermark_id))'
                . $filter . '
              ORDER BY ' . $at . ' ASC, ' . $key . ' ASC
              LIMIT ' . self::BATCH,
            [
                'cutoff'       => $cutoff,
                'watermark_at' => $watermarkAt,
                'watermark_id' => $watermarkId,
            ]
        );
    }

    /**
     * @param  array<string,mixed> $meta
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function projectPayload(array $meta, array $row): array
    {
        $payload = [];

        foreach (array_keys($meta['fields']) as $payloadKey) {
            $payload[$payloadKey] = $row[$payloadKey] ?? null;
        }

        return $payload;
    }

    // =================================================================
    // Actions
    // =================================================================

    /**
     * @param  array<string,mixed>  $rule
     * @param  array<string,mixed>  $meta
     * @param  list<mixed>          $actions
     * @param  array<string,mixed>  $payload
     * @return list<array{type:string,ok:bool,detail:string}>
     */
    private static function performActions(array $rule, array $meta, array $actions, array $payload): array
    {
        $results = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                $results[] = ['type' => '?', 'ok' => false, 'detail' => 'Action entry is not an object.'];
                continue;
            }

            $type   = (string) ($action['type'] ?? '');
            $params = is_array($action['params'] ?? null) ? $action['params'] : [];

            // One failing action must not stop the ones after it.
            try {
                $results[] = ['type' => $type] + self::performAction($rule, $meta, $type, $params, $payload);
            } catch (\Throwable $e) {
                $results[] = ['type' => $type, 'ok' => false, 'detail' => $e->getMessage()];

                Logger::error('Automation action threw', [
                    'rule_id' => (int) $rule['id'],
                    'action'  => $type,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * @param  array<string,mixed> $rule
     * @param  array<string,mixed> $meta
     * @param  array<string,mixed> $params
     * @param  array<string,mixed> $payload
     * @return array{ok:bool,detail:string}
     */
    private static function performAction(array $rule, array $meta, string $type, array $params, array $payload): array
    {
        return match ($type) {
            'notify_admins' => self::actNotifyAdmins($rule, $params, $payload),
            'send_email'    => self::actSendEmail($rule, $params, $payload),
            'open_approval' => self::actOpenApproval($params, $payload),
            'assign_ticket' => self::actAssignTicket($params, $payload),
            'set_field'     => self::actSetField($meta, $params, $payload),
            'webhook'       => self::actWebhook($rule, $params, $payload),
            'log_only'      => self::actLogOnly($rule, $params, $payload),
            default         => ['ok' => false, 'detail' => 'Unknown action type.'],
        };
    }

    /**
     * Mail every active admin who holds the named permission.
     *
     * `notifications` is a user-facing table with a foreign key to `users`, so
     * an admin notice cannot be written there. Email is the channel the panel
     * actually has for admins, and it goes through the queue so a slow SMTP
     * host cannot stall the cron pass.
     *
     * @param  array<string,mixed> $rule
     * @param  array<string,mixed> $params
     * @param  array<string,mixed> $payload
     * @return array{ok:bool,detail:string}
     */
    private static function actNotifyAdmins(array $rule, array $params, array $payload): array
    {
        $permission = trim((string) ($params['permission'] ?? ''));
        $message    = trim((string) ($params['message'] ?? ''));

        if ($permission === '' || $message === '') {
            return ['ok' => false, 'detail' => 'notify_admins needs both a permission and a message.'];
        }

        $admins = Database::select(
            "SELECT `id`, `name`, `email` FROM `admins`
              WHERE `deleted_at` IS NULL AND `status` = 'active'"
        );

        $sent = 0;

        foreach ($admins as $admin) {
            if (!PermissionService::adminCan((int) $admin['id'], $permission)) {
                continue;
            }

            $body = '<p>' . htmlspecialchars(Str::interpolate($message, $payload), ENT_QUOTES, 'UTF-8') . '</p>'
                  . '<p style="color:#666;font-size:13px">Raised by the automation rule '
                  . '&ldquo;' . htmlspecialchars((string) $rule['name'], ENT_QUOTES, 'UTF-8') . '&rdquo; on '
                  . htmlspecialchars((string) $rule['trigger_event'], ENT_QUOTES, 'UTF-8') . '.</p>';

            if (MailService::queue(
                (string) $admin['email'],
                (string) $admin['name'],
                'Automation: ' . mb_substr((string) $rule['name'], 0, 120),
                $body
            ) > 0) {
                $sent++;
            }
        }

        return $sent === 0
            ? ['ok' => false, 'detail' => 'No active admin holds "' . $permission . '".']
            : ['ok' => true, 'detail' => 'Queued ' . $sent . ' admin notice(s).'];
    }

    /**
     * @param  array<string,mixed> $rule
     * @param  array<string,mixed> $params
     * @param  array<string,mixed> $payload
     * @return array{ok:bool,detail:string}
     */
    private static function actSendEmail(array $rule, array $params, array $payload): array
    {
        $to      = trim((string) ($params['to'] ?? ''));
        $subject = trim((string) ($params['subject'] ?? ''));
        $body    = (string) ($params['body'] ?? '');

        if ($to === '' || $subject === '' || trim($body) === '') {
            return ['ok' => false, 'detail' => 'send_email needs to, subject and body.'];
        }

        $name = '';

        if (strcasecmp($to, 'user') === 0) {
            $recipient = self::subjectUser($payload);

            if ($recipient === null) {
                return ['ok' => false, 'detail' => 'This event carries no user to email.'];
            }

            $to   = (string) $recipient['email'];
            $name = (string) $recipient['name'];
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'detail' => '"' . $to . '" is not an address.'];
        }

        $queued = MailService::queue(
            $to,
            $name === '' ? $to : $name,
            mb_substr(Str::interpolate($subject, $payload), 0, 255),
            Str::interpolate($body, $payload)
        );

        return $queued > 0
            ? ['ok' => true, 'detail' => 'Queued for ' . $to . '.']
            : ['ok' => false, 'detail' => 'The mail queue refused ' . $to . '.'];
    }

    /**
     * The user an event is about, if it names one.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private static function subjectUser(array $payload): ?array
    {
        foreach (['user_id', 'brand_user_id', 'reviewer_id'] as $key) {
            $userId = (int) ($payload[$key] ?? 0);

            if ($userId > 0) {
                $row = Database::selectOne(
                    'SELECT `name`, `email` FROM `users` WHERE `id` = :id AND `deleted_at` IS NULL',
                    ['id' => $userId]
                );

                if ($row !== null && (string) $row['email'] !== '') {
                    return $row;
                }
            }
        }

        if (isset($payload['email']) && filter_var((string) $payload['email'], FILTER_VALIDATE_EMAIL) !== false) {
            return ['name' => (string) ($payload['name'] ?? ''), 'email' => (string) $payload['email']];
        }

        return null;
    }

    /**
     * @param  array<string,mixed> $params
     * @param  array<string,mixed> $payload
     * @return array{ok:bool,detail:string}
     */
    private static function actOpenApproval(array $params, array $payload): array
    {
        $entityType = trim((string) ($params['entity_type'] ?? ''));
        $summary    = trim((string) ($params['summary'] ?? ''));

        if (!isset(self::APPROVAL_KEYS[$entityType])) {
            return ['ok' => false, 'detail' => '"' . $entityType . '" cannot be raised from automation.'];
        }

        $entityId = (int) ($payload[self::APPROVAL_KEYS[$entityType]] ?? 0);

        if ($entityId <= 0) {
            return ['ok' => false, 'detail' => 'This event carries no ' . $entityType . ' id.'];
        }

        if ($summary === '') {
            $summary = 'Raised automatically for ' . $entityType . ' #' . $entityId . '.';
        }

        $amount    = isset($payload['amount']) && is_numeric($payload['amount']) ? (float) $payload['amount'] : null;
        $requestId = ApprovalService::open($entityType, $entityId, $amount, Str::interpolate($summary, $payload), 0);

        return $requestId === null
            ? ['ok' => false, 'detail' => 'No active workflow covers that subject.']
            : ['ok' => true, 'detail' => 'Approval request #' . $requestId . '.'];
    }

    /**
     * @param  array<string,mixed> $params
     * @param  array<string,mixed> $payload
     * @return array{ok:bool,detail:string}
     */
    private static function actAssignTicket(array $params, array $payload): array
    {
        $ticketId = (int) ($payload['ticket_id'] ?? 0);
        $adminId  = (int) ($params['admin_id'] ?? 0);

        if ($ticketId <= 0) {
            return ['ok' => false, 'detail' => 'This event is not about a ticket.'];
        }

        if ($adminId <= 0 || !Database::exists('admins', ['id' => $adminId, 'status' => 'active'])) {
            return ['ok' => false, 'detail' => 'Admin #' . $adminId . ' is not an active admin.'];
        }

        Database::update('support_tickets', ['assigned_to' => $adminId], ['id' => $ticketId]);

        return ['ok' => true, 'detail' => 'Ticket #' . $ticketId . ' assigned to admin #' . $adminId . '.'];
    }

    /**
     * Write one whitelisted column on the subject.
     *
     * The column has to be in the trigger's `settable` list. Without that check
     * this action is an arbitrary UPDATE against the source table for anyone
     * who can save a rule, so the whitelist is the whole security of it.
     *
     * @param  array<string,mixed> $meta
     * @param  array<string,mixed> $params
     * @param  array<string,mixed> $payload
     * @return array{ok:bool,detail:string}
     */
    private static function actSetField(array $meta, array $params, array $payload): array
    {
        $field = trim((string) ($params['field'] ?? ''));

        if (!in_array($field, $meta['settable'], true)) {
            return [
                'ok'     => false,
                'detail' => '"' . $field . '" is not a field this trigger may set.',
            ];
        }

        $subjectKey = array_search($meta['key'], $meta['fields'], true);
        $entityId   = is_string($subjectKey) ? (int) ($payload[$subjectKey] ?? 0) : 0;

        if ($entityId <= 0) {
            return ['ok' => false, 'detail' => 'The event carries no subject id.'];
        }

        $value = $params['value'] ?? null;

        if (is_array($value) || is_object($value)) {
            return ['ok' => false, 'detail' => 'set_field takes a scalar value.'];
        }

        Database::update(
            $meta['table'],
            [$field => $value === null ? null : mb_substr((string) $value, 0, 255)],
            [$meta['key'] => $entityId]
        );

        return ['ok' => true, 'detail' => $meta['table'] . '.' . $field . ' set on #' . $entityId . '.'];
    }

    /**
     * @param  array<string,mixed> $rule
     * @param  array<string,mixed> $params
     * @param  array<string,mixed> $payload
     * @return array{ok:bool,detail:string}
     */
    private static function actLogOnly(array $rule, array $params, array $payload): array
    {
        $note = trim((string) ($params['note'] ?? ''));

        if ($note === '') {
            return ['ok' => false, 'detail' => 'log_only needs a note.'];
        }

        ActivityLogger::log(
            'automation_rule.note',
            'automation_rule',
            (int) $rule['id'],
            mb_substr(Str::interpolate($note, $payload), 0, 255),
            ['trigger_event' => (string) $rule['trigger_event'], 'payload' => $payload],
            'cron'
        );

        return ['ok' => true, 'detail' => 'Noted in the activity log.'];
    }

    // =================================================================
    // Webhook
    // =================================================================

    /**
     * @param  array<string,mixed> $rule
     * @param  array<string,mixed> $params
     * @param  array<string,mixed> $payload
     * @return array{ok:bool,detail:string}
     */
    private static function actWebhook(array $rule, array $params, array $payload): array
    {
        $url    = trim((string) ($params['url'] ?? ''));
        $method = strtoupper(trim((string) ($params['method'] ?? 'POST')));

        if ($method !== 'POST' && $method !== 'GET') {
            return ['ok' => false, 'detail' => 'A webhook may only be POST or GET.'];
        }

        $target = self::resolveWebhookTarget($url);

        if ($target === null) {
            return ['ok' => false, 'detail' => self::webhookProblem($url)];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'detail' => 'cURL is not available on this server.'];
        }

        $body = (string) json_encode([
            'rule'    => ['id' => (int) $rule['id'], 'name' => (string) $rule['name']],
            'event'   => (string) $rule['trigger_event'],
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $received = 0;
        $handle   = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => self::WEBHOOK_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::WEBHOOK_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // A redirect is the classic way back out of the address checks
            // below, so the runner never follows one.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'Zuste-Automation/1.0',
            // Pin the host to the address that was just vetted, so a second
            // DNS answer cannot swing the connection onto a private address.
            CURLOPT_RESOLVE        => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
            CURLOPT_WRITEFUNCTION  => static function ($_, string $chunk) use (&$received): int {
                $received += strlen($chunk);

                return $received > self::WEBHOOK_MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);

        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        curl_exec($handle);

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($handle);
        $error  = curl_error($handle);

        curl_close($handle);

        // Aborting an oversized body surfaces as a write error, which is the
        // cap doing its job rather than a failed call.
        if ($errno !== 0 && !($errno === CURLE_WRITE_ERROR && $status >= 200 && $status < 300)) {
            return ['ok' => false, 'detail' => 'Webhook failed: ' . $error];
        }

        return $status >= 200 && $status < 300
            ? ['ok' => true, 'detail' => 'Webhook returned ' . $status . '.']
            : ['ok' => false, 'detail' => 'Webhook returned ' . $status . '.'];
    }

    /**
     * Vet a webhook URL and hand back the address it is allowed to reach.
     *
     * https only, no credentials in the URL, and every address the host
     * resolves to has to be a public one — otherwise an operator with
     * workflow.manage could use the server to probe the private network it
     * sits in.
     *
     * @return array{host:string,port:int,ip:string}|null
     */
    public static function resolveWebhookTarget(string $url): ?array
    {
        $parts = parse_url($url);

        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? 443);

        if ($host === '' || $port !== 443) {
            return null;
        }

        $addresses = self::resolveHost($host);

        if ($addresses === []) {
            return null;
        }

        // Every answer has to pass, not just the one that will be used: a host
        // that resolves to both a public and a private address is a rebinding
        // attempt, not a legitimate endpoint.
        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                return null;
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /** Why a URL was refused, in words an operator can act on. */
    public static function webhookProblem(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || ($parts['host'] ?? '') === '') {
            return 'That is not a URL.';
        }

        if (($parts['scheme'] ?? '') !== 'https') {
            return 'A webhook has to be https.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Credentials cannot be put in the webhook URL.';
        }

        if ((int) ($parts['port'] ?? 443) !== 443) {
            return 'A webhook has to use port 443.';
        }

        return self::resolveHost((string) $parts['host']) === []
            ? 'That host does not resolve.'
            : 'That host resolves to a private or reserved address.';
    }

    /** @return list<string> */
    private static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        $v4        = gethostbynamel($host);

        if (is_array($v4)) {
            $addresses = $v4;
        }

        $v6 = @dns_get_record($host, DNS_AAAA);

        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Public routable address? Loopback, link-local, the cloud metadata range
     * and every RFC 1918 / unique-local block are all refused.
     */
    private static function isPublicAddress(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    // =================================================================
    // Shared with the rule editor
    // =================================================================

    /**
     * Columns `set_field` may write for a trigger.
     *
     * @return list<string>
     */
    public static function settableColumns(string $event): array
    {
        return self::EVENTS[$event]['settable'] ?? [];
    }

    /** Approval entity types an automation rule can raise from this trigger. */
    public static function approvalTypesFor(string $event): array
    {
        $fields = self::EVENTS[$event]['fields'] ?? [];
        $types  = [];

        foreach (self::APPROVAL_KEYS as $entityType => $payloadKey) {
            if (isset($fields[$payloadKey])) {
                $types[] = $entityType;
            }
        }

        return $types;
    }

    /** Does this trigger's payload carry a ticket? */
    public static function carriesTicket(string $event): bool
    {
        return isset(self::EVENTS[$event]['fields']['ticket_id']);
    }

    // =================================================================
    // Bookkeeping
    // =================================================================

    private static function recordError(int $ruleId, ?string $message): void
    {
        Database::update(
            'automation_rules',
            ['last_error' => $message === null ? null : mb_substr($message, 0, 500)],
            ['id' => $ruleId]
        );
    }
}

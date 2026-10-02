<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\Session;
use Zuste\Core\ValidationException;
use Zuste\Services\ApprovalService;
use Zuste\Services\AutomationService;
use Zuste\Services\PermissionService;

/**
 * Approvals and automation.
 *
 * Two related things live here: the queue of multi-level approval requests
 * (raised by {@see ApprovalService}) and the automation rule book that the
 * automation runner reads.
 *
 * The condition evaluator is public and static so the runner can use exactly
 * the same code that the "Test conditions" button in the admin panel uses —
 * a rule that passes in the tester must behave identically when it fires.
 */
final class WorkflowController extends BaseAdminController
{
    /**
     * Trigger events an automation rule may listen for, in the shape the
     * rules screen renders.
     *
     * The definitions themselves live on {@see AutomationService::EVENTS},
     * because that is what polls them: `source` is the table and column the
     * runner watches and `fields` are the payload keys it projects from that
     * row. Deriving the screen from the runner's own list means the editor can
     * never offer a trigger the runner does not implement.
     *
     * @return array<string,array{label:string,source:string,fields:list<string>}>
     */
    public static function triggerEvents(): array
    {
        $events = [];

        foreach (AutomationService::EVENTS as $key => $meta) {
            $events[$key] = [
                'label'  => $meta['label'],
                'source' => $meta['signal'],
                'fields' => array_keys($meta['fields']),
            ];
        }

        return $events;
    }

    /**
     * Condition operators, with the arity the value input needs.
     *
     * @var array<string,array{label:string,value:string}>
     */
    public const OPERATORS = [
        '='            => ['label' => 'is',                'value' => 'single'],
        '!='           => ['label' => 'is not',            'value' => 'single'],
        '>'            => ['label' => 'is greater than',   'value' => 'single'],
        '>='           => ['label' => 'is at least',       'value' => 'single'],
        '<'            => ['label' => 'is less than',      'value' => 'single'],
        '<='           => ['label' => 'is at most',        'value' => 'single'],
        'contains'     => ['label' => 'contains',          'value' => 'single'],
        'not_contains' => ['label' => 'does not contain',  'value' => 'single'],
        'starts_with'  => ['label' => 'starts with',       'value' => 'single'],
        'in'           => ['label' => 'is one of',         'value' => 'list'],
        'not_in'       => ['label' => 'is none of',        'value' => 'list'],
        'is_empty'     => ['label' => 'is empty',          'value' => 'none'],
        'is_not_empty' => ['label' => 'is not empty',      'value' => 'none'],
    ];

    /**
     * Action types a rule may perform, and the params each one expects.
     *
     * Text params accept `{{field}}` placeholders, filled from the event
     * payload when the rule fires.
     *
     * @var array<string,array{label:string,params:array<string,string>}>
     */
    public const ACTION_TYPES = [
        'notify_admins' => [
            'label'  => 'Notify admins',
            'params' => ['permission' => 'permission slug the notice goes to', 'message' => 'text of the notice'],
        ],
        'send_email' => [
            'label'  => 'Queue an email',
            'params' => ['to' => '"user" or an address', 'subject' => 'subject line', 'body' => 'HTML body'],
        ],
        'open_approval' => [
            'label'  => 'Raise an approval request',
            'params' => ['entity_type' => 'an entity this trigger carries an id for', 'summary' => 'one-line summary'],
        ],
        'assign_ticket' => [
            'label'  => 'Assign the ticket',
            'params' => ['admin_id' => 'numeric admin id'],
        ],
        'set_field' => [
            'label'  => 'Set a field on the subject',
            'params' => ['field' => 'one of the trigger\'s settable columns', 'value' => 'new value'],
        ],
        'webhook' => [
            'label'  => 'Call a webhook',
            'params' => ['url' => 'https://… on port 443, public host only', 'method' => 'POST or GET'],
        ],
        'log_only' => [
            'label'  => 'Record it only',
            'params' => ['note' => 'what to write to the activity log'],
        ],
    ];

    private const REQUEST_SORTABLE = ['created_at', 'amount', 'entity_type', 'status'];

    /** Whitelisted sort columns mapped to their qualified SQL expression. */
    private const REQUEST_SORT_SQL = [
        'created_at'  => 'r.`created_at`',
        'amount'      => 'r.`amount`',
        'entity_type' => 'r.`entity_type`',
        'status'      => 'r.`status`',
    ];

    private const RULE_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    // =================================================================
    // Approval queue
    // =================================================================

    /** GET /admin/workflow */
    public function index(Request $request): never
    {
        $this->authorize($request, 'workflow.view');

        $adminId = $this->adminId($request);
        $params  = $this->listParams($request, self::REQUEST_SORTABLE, 'created_at');

        $status = $request->string('status', 'pending');
        $entity = $request->string('entity_type');

        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($status !== '' && in_array($status, self::RULE_STATUSES, true)) {
            $clauses[] = 'r.`status` = :status';
            $bindings['status'] = $status;
        }

        if ($entity !== '' && isset(ApprovalService::ENTITY_TYPES[$entity])) {
            $clauses[] = 'r.`entity_type` = :entity_type';
            $bindings['entity_type'] = $entity;
        }

        if ($params['search'] !== '') {
            $clauses[] = '(r.`summary` LIKE :search OR w.`name` LIKE :search OR r.`entity_id` = :search_id)';
            $bindings['search']    = '%' . $params['search'] . '%';
            $bindings['search_id'] = ctype_digit($params['search']) ? (int) $params['search'] : 0;
        }

        $this->applyDateRange($clauses, $bindings, 'r.`created_at`', $params['from'], $params['to']);

        $where = implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            "SELECT COUNT(*)
               FROM `approval_requests` r
               INNER JOIN `approval_workflows` w ON w.`id` = r.`workflow_id`
              WHERE $where",
            $bindings
        );

        $orderBy = self::REQUEST_SORT_SQL[$params['sort_by']] ?? 'r.`created_at`';

        $rows = Database::select(
            "SELECT r.*,
                    w.`name`             AS `workflow_name`,
                    s.`id`               AS `step_id`,
                    s.`name`             AS `step_name`,
                    s.`step_order`       AS `step_order`,
                    s.`role_id`          AS `role_id`,
                    s.`permission`       AS `permission`,
                    s.`is_optional`      AS `step_optional`,
                    ro.`name`            AS `step_role_name`,
                    a.`name`             AS `requested_by_name`,
                    (SELECT COUNT(*) FROM `approval_steps` t WHERE t.`workflow_id` = r.`workflow_id`) AS `total_steps`,
                    (SELECT COUNT(*) FROM `approval_actions` c WHERE c.`request_id` = r.`id`)         AS `decisions`,
                    (SELECT COUNT(*) FROM `approval_actions` d
                      WHERE d.`request_id` = r.`id` AND d.`admin_id` = :viewer_id)                    AS `my_decisions`
               FROM `approval_requests` r
               INNER JOIN `approval_workflows` w ON w.`id` = r.`workflow_id`
               LEFT JOIN `approval_steps` s ON s.`id` = (
                     SELECT s2.`id` FROM `approval_steps` s2
                      WHERE s2.`workflow_id` = r.`workflow_id` AND s2.`step_order` >= r.`current_step`
                      ORDER BY s2.`step_order` ASC LIMIT 1)
               LEFT JOIN `roles`  ro ON ro.`id` = s.`role_id`
               LEFT JOIN `admins` a  ON a.`id`  = r.`requested_by`
              WHERE $where
              ORDER BY $orderBy {$params['sort_dir']}
              LIMIT {$params['per_page']} OFFSET {$params['offset']}",
            // Native prepares reject a bound name the statement does not use,
            // so the viewer only rides on the query that actually reads it.
            $bindings + ['viewer_id' => $adminId]
        );

        // Decorate with what this admin may actually do, and what the subject
        // is. Subject names are fetched one query per entity type, not one per
        // row, because a page can hold 200 of them.
        $labels = ApprovalService::entityLabels($rows);

        foreach ($rows as $index => $row) {
            $rows[$index]['can_act'] = (string) $row['status'] === 'pending'
                && $row['step_id'] !== null
                && (int) ($row['requested_by'] ?? 0) !== $adminId
                && (int) $row['my_decisions'] === 0
                && ApprovalService::adminMayActOn($row, $adminId);

            $rows[$index]['entity_label'] = $labels[$row['entity_type'] . ':' . (int) $row['entity_id']] ?? null;
        }

        $summary = Database::selectOne(
            "SELECT
                SUM(`status` = 'pending')   AS `pending`,
                SUM(`status` = 'approved')  AS `approved`,
                SUM(`status` = 'rejected')  AS `rejected`,
                SUM(`status` = 'cancelled') AS `cancelled`
               FROM `approval_requests`"
        ) ?? [];

        $oldest = Database::scalar(
            "SELECT MIN(`created_at`) FROM `approval_requests` WHERE `status` = 'pending'"
        );

        $workflows = Database::select(
            'SELECT w.*, (SELECT COUNT(*) FROM `approval_steps` s WHERE s.`workflow_id` = w.`id`) AS `step_count`
               FROM `approval_workflows` w
              ORDER BY w.`is_active` DESC, w.`entity_type` ASC, w.`threshold_amount` DESC'
        );

        $this->view($request, 'admin.workflow.index', [
            'pageTitle'    => 'Approvals',
            'pageSubtitle' => 'Everything waiting on a sign-off, and the workflows that route it.',
            'rows'         => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'summary'      => $summary,
            'oldestAt'     => $oldest === null ? null : (string) $oldest,
            'actionable'   => ApprovalService::actionableCount($adminId),
            'workflows'    => $workflows,
            'entityTypes'  => ApprovalService::ENTITY_TYPES,
            'currency'     => $this->currency(),
            'filters'      => [
                'q'           => $params['search'],
                'status'      => $status,
                'entity_type' => $entity,
                'from'        => $params['from'],
                'to'          => $params['to'],
                'sort_by'     => $params['sort_by'],
                'sort_dir'    => $params['sort_dir'],
            ],
        ]);
    }

    /** GET /admin/workflow/{id} */
    public function show(Request $request): never
    {
        $this->authorize($request, 'workflow.view');

        $id      = $request->routeParamInt('id');
        $adminId = $this->adminId($request);

        $row = Database::selectOne(
            'SELECT r.*, w.`name` AS `workflow_name`, w.`description` AS `workflow_description`,
                    w.`threshold_amount` AS `workflow_threshold`, w.`is_active` AS `workflow_active`,
                    a.`name` AS `requested_by_name`, a.`email` AS `requested_by_email`
               FROM `approval_requests` r
               INNER JOIN `approval_workflows` w ON w.`id` = r.`workflow_id`
               LEFT JOIN `admins` a ON a.`id` = r.`requested_by`
              WHERE r.`id` = :id',
            ['id' => $id]
        );

        if ($row === null) {
            $this->back('error', 'That approval request does not exist.', '/admin/workflow');
        }

        $timeline    = ApprovalService::timeline($id);
        $currentStep = (string) $row['status'] === 'pending'
            ? ApprovalService::stepAtOrAfter((int) $row['workflow_id'], (int) $row['current_step'])
            : null;

        $isOwn      = (int) ($row['requested_by'] ?? 0) === $adminId;
        $hasDecided = ApprovalService::hasDecided($id, $adminId);
        $canAct     = $currentStep !== null
            && !$isOwn
            && !$hasDecided
            && ApprovalService::adminMayActOn($currentStep, $adminId);

        $blockedReason = '';

        if ((string) $row['status'] !== 'pending') {
            $blockedReason = 'This request is closed.';
        } elseif ($currentStep === null) {
            $blockedReason = 'The workflow has no step at or after ' . (int) $row['current_step']
                . '. Add a step to the workflow before this can move.';
        } elseif ($isOwn) {
            $blockedReason = 'You raised this request, so you cannot decide it.';
        } elseif ($hasDecided) {
            $blockedReason = 'You already decided a step on this request. '
                . 'The next step has to be taken by someone else.';
        } elseif (!$canAct) {
            $blockedReason = 'Step ' . (int) $currentStep['step_order'] . ' is reserved for '
                . ApprovalService::stepAudience($currentStep) . '.';
        }

        $this->view($request, 'admin.workflow.show', [
            'pageTitle'     => 'Approval #' . $id,
            'pageSubtitle'  => (string) ($row['summary'] ?? ApprovalService::entityLabelFor((string) $row['entity_type'])),
            'request'       => $row,
            'timeline'      => $timeline,
            'currentStep'   => $currentStep,
            'canAct'        => $canAct,
            'blockedReason' => $blockedReason,
            'entityLabel'   => ApprovalService::entityLabel((string) $row['entity_type'], (int) $row['entity_id']),
            'entityUrl'     => ApprovalService::entityUrl((string) $row['entity_type'], (int) $row['entity_id']),
            'entityName'    => ApprovalService::entityLabelFor((string) $row['entity_type']),
            'canCancel'     => (string) $row['status'] === 'pending' && $this->can($request, 'workflow.manage'),
        ]);
    }

    /** POST /admin/workflow/{id}/act */
    public function act(Request $request): never
    {
        $this->authorize($request, 'workflow.act');

        $id   = $request->routeParamInt('id');
        $data = $this->validate($request, [
            'decision' => 'required|in:approved,rejected',
            'note'     => 'nullable|string|max:500',
        ]);

        $note   = trim((string) ($data['note'] ?? ''));
        $result = ApprovalService::act($id, $this->adminId($request), (string) $data['decision'], $note);

        if (!$result['ok']) {
            $this->back('error', $result['message'], '/admin/workflow/' . $id);
        }

        $this->log(
            $request,
            'approval.' . $data['decision'],
            'approval_request',
            $id,
            ucfirst((string) $data['decision']) . ' approval request #' . $id . '.',
            ['status' => $result['status'], 'note' => $note]
        );

        $this->respond($request, $result['message'], '/admin/workflow/' . $id);
    }

    /** POST /admin/workflow/{id}/cancel */
    public function cancelRequest(Request $request): never
    {
        $this->authorize($request, 'workflow.manage');

        $id     = $request->routeParamInt('id');
        $reason = trim($request->string('reason'));
        $result = ApprovalService::cancel($id, $this->adminId($request), $reason);

        if (!$result['ok']) {
            $this->back('error', $result['message'], '/admin/workflow/' . $id);
        }

        $this->log($request, 'approval.cancelled', 'approval_request', $id, 'Cancelled approval request #' . $id . '.', ['reason' => $reason]);

        $this->respond($request, $result['message'], '/admin/workflow/' . $id);
    }

    /** POST /admin/workflow/requests — raise an approval by hand. */
    public function raise(Request $request): never
    {
        $this->authorize($request, 'workflow.manage');

        $data = $this->validate($request, [
            'entity_type' => 'required|in:' . implode(',', array_keys(ApprovalService::ENTITY_TYPES)),
            'entity_id'   => 'required|integer|min_value:1',
            'amount'      => 'nullable|numeric|min_value:0',
            'summary'     => 'required|string|min:3|max:255',
        ]);

        $entityType = (string) $data['entity_type'];
        $entityId   = (int) $data['entity_id'];

        // The validator omits optional keys that were never submitted, so an
        // absent field and a blank one both have to mean "no amount".
        $amountRaw = $data['amount'] ?? null;
        $amount    = ($amountRaw === null || $amountRaw === '') ? null : (float) $amountRaw;

        if (!ApprovalService::entityExists($entityType, $entityId)) {
            $this->back(
                'error',
                'There is no ' . strtolower(ApprovalService::entityLabelFor($entityType)) . ' with id ' . $entityId . '.',
                '/admin/workflow'
            );
        }

        // One approval per subject is a database-level rule, so say so plainly
        // rather than letting the insert fail or silently returning the old id.
        $existing = ApprovalService::existingFor($entityType, $entityId);

        if ($existing !== null) {
            $this->back(
                'info',
                'That subject already has approval request #' . (int) $existing['id']
                . ' (' . $existing['status'] . '). A subject can only be approved once.',
                '/admin/workflow/' . (int) $existing['id']
            );
        }

        if (ApprovalService::workflowFor($entityType, $amount) === null) {
            $this->back(
                'error',
                'No active workflow covers a ' . strtolower(ApprovalService::entityLabelFor($entityType))
                . ($amount === null ? ' without an amount' : ' at that amount') . '.',
                '/admin/workflow'
            );
        }

        $requestId = ApprovalService::open(
            $entityType,
            $entityId,
            $amount,
            (string) $data['summary'],
            $this->adminId($request)
        );

        if ($requestId === null) {
            $this->back('error', 'That workflow could not accept the request. Check that it still has steps.', '/admin/workflow');
        }

        $this->log(
            $request,
            'approval.raised',
            'approval_request',
            $requestId,
            'Raised an approval for ' . $entityType . ' #' . $entityId . '.',
            ['amount' => $amount, 'summary' => $data['summary']]
        );

        $this->respond($request, 'Approval request #' . $requestId . ' raised.', '/admin/workflow/' . $requestId);
    }

    // =================================================================
    // Automation rules
    // =================================================================

    /** GET /admin/workflow/rules */
    public function rules(Request $request): never
    {
        $this->authorize($request, 'workflow.manage');

        $trigger = $request->string('trigger_event');
        $state   = $request->string('state');
        $search  = trim($request->string('q'));

        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($trigger !== '' && isset(AutomationService::EVENTS[$trigger])) {
            $clauses[] = 'r.`trigger_event` = :trigger_event';
            $bindings['trigger_event'] = $trigger;
        }

        if ($state === 'active' || $state === 'paused') {
            $clauses[] = 'r.`is_active` = :is_active';
            $bindings['is_active'] = $state === 'active' ? 1 : 0;
        }

        if ($search !== '') {
            $clauses[] = '(r.`name` LIKE :search OR r.`description` LIKE :search)';
            $bindings['search'] = '%' . $search . '%';
        }

        $where   = implode(' AND ', $clauses);
        $page    = $request->page();
        $perPage = $request->perPage(25, 200);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM `automation_rules` r WHERE $where",
            $bindings
        );

        // The heading counts have to come from the table, never from the page
        // of rows: a filtered or paginated array cannot say how many rules are
        // stored, and reporting it as "stored" is an affirmative untruth.
        $storedTotal = (int) Database::scalar('SELECT COUNT(*) FROM `automation_rules`');
        $activeTotal = (int) Database::scalar('SELECT COUNT(*) FROM `automation_rules` WHERE `is_active` = 1');

        $offset = ($page - 1) * $perPage;

        // Active first, then by name — the order the screen has always used.
        // The id is only a tiebreaker, so paging cannot repeat or drop a rule
        // when two share a name.
        $rows = Database::select(
            "SELECT r.*, a.`name` AS `created_by_name`
               FROM `automation_rules` r
               LEFT JOIN `admins` a ON a.`id` = r.`created_by`
              WHERE $where
              ORDER BY r.`is_active` DESC, r.`name` ASC, r.`id` ASC
              LIMIT $perPage OFFSET $offset",
            $bindings
        );

        $this->view($request, 'admin.workflow.rules', [
            'pageTitle'     => 'Automation rules',
            'pageSubtitle'  => 'Conditions and actions the automation runner evaluates.',
            'rows'          => $rows,
            'pagination'    => $this->paginationMeta($total, $page, $perPage),
            'storedTotal'   => $storedTotal,
            'activeTotal'   => $activeTotal,
            'shownTotal'    => $total,
            'editRow'       => $this->flashedEditTarget($rows),
            'triggerEvents' => self::triggerEvents(),
            'operators'     => self::OPERATORS,
            'actionTypes'   => self::ACTION_TYPES,
            'runnerJob'     => self::runnerRegistered(),
            'filters'       => [
                'q'             => $search,
                'trigger_event' => $trigger,
                'state'         => $state,
            ],
        ]);
    }

    /**
     * The rule a rejected edit was pointing at, when it is not on this page.
     *
     * The editor rebuilds itself from the rows it was given, so a rule that
     * a filter or the page boundary excluded would leave the form silently
     * pointed back at "create" and turn the operator's next Save into a
     * duplicate insert. Renaming or pausing a rule moves it in the ordering,
     * so this is reachable, not theoretical.
     *
     * @param  list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function flashedEditTarget(array $rows): ?array
    {
        $pending = trim((string) Session::oldInput('edit_id', ''));

        if ($pending === '' || !ctype_digit($pending)) {
            return null;
        }

        foreach ($rows as $row) {
            if ((int) $row['id'] === (int) $pending) {
                return null;
            }
        }

        return Database::selectOne(
            'SELECT r.*, a.`name` AS `created_by_name`
               FROM `automation_rules` r
               LEFT JOIN `admins` a ON a.`id` = r.`created_by`
              WHERE r.`id` = :id',
            ['id' => (int) $pending]
        );
    }

    /** POST /admin/workflow/rules */
    public function storeRule(Request $request): never
    {
        $this->authorize($request, 'workflow.manage');

        $payload = $this->ruleInput($request);

        $id = Database::insert('automation_rules', $payload + [
            'run_count'  => 0,
            'created_by' => $this->adminId($request),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log($request, 'automation_rule.created', 'automation_rule', $id, 'Created rule "' . $payload['name'] . '".', [
            'trigger_event' => $payload['trigger_event'],
        ]);

        $this->respond($request, 'Rule created.', '/admin/workflow/rules');
    }

    /** POST /admin/workflow/rules/{id} */
    public function updateRule(Request $request): never
    {
        $this->authorize($request, 'workflow.manage');

        $id       = $request->routeParamInt('id');
        $existing = Database::selectOne('SELECT * FROM `automation_rules` WHERE `id` = :id', ['id' => $id]);

        if ($existing === null) {
            $this->back('error', 'That rule no longer exists.', '/admin/workflow/rules');
        }

        $payload = $this->ruleInput($request);

        Database::update('automation_rules', $payload + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);

        $this->log($request, 'automation_rule.updated', 'automation_rule', $id, 'Updated rule "' . $payload['name'] . '".', [
            'trigger_event' => $payload['trigger_event'],
        ]);

        $this->respond($request, 'Rule saved.', '/admin/workflow/rules');
    }

    /** POST /admin/workflow/rules/{id}/toggle */
    public function toggleRule(Request $request): never
    {
        $this->authorize($request, 'workflow.manage');

        $id  = $request->routeParamInt('id');
        $row = Database::selectOne('SELECT * FROM `automation_rules` WHERE `id` = :id', ['id' => $id]);

        if ($row === null) {
            $this->back('error', 'That rule no longer exists.', '/admin/workflow/rules');
        }

        $enable = (int) $row['is_active'] === 0;

        // Activating re-arms the runner's cursor at the current time. Without
        // this, a rule paused for a month would fire on every event of that
        // month the moment it came back.
        Database::update('automation_rules', [
            'is_active'    => $enable ? 1 : 0,
            'watermark_at' => $enable ? null : ($row['watermark_at'] ?? null),
            'watermark_id' => $enable ? 0 : (int) ($row['watermark_id'] ?? 0),
            'last_error'   => $enable ? null : ($row['last_error'] ?? null),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        $this->log(
            $request,
            'automation_rule.toggled',
            'automation_rule',
            $id,
            ($enable ? 'Activated' : 'Paused') . ' rule "' . $row['name'] . '".'
        );

        $this->respond($request, $enable ? 'Rule is active.' : 'Rule paused.', '/admin/workflow/rules');
    }

    /** POST /admin/workflow/rules/{id}/duplicate */
    public function duplicateRule(Request $request): never
    {
        $this->authorize($request, 'workflow.manage');

        $id  = $request->routeParamInt('id');
        $row = Database::selectOne('SELECT * FROM `automation_rules` WHERE `id` = :id', ['id' => $id]);

        if ($row === null) {
            $this->back('error', 'That rule no longer exists.', '/admin/workflow/rules');
        }

        // The copy starts paused so a duplicate never doubles up a live rule.
        $newId = Database::insert('automation_rules', [
            'name'          => mb_substr($row['name'] . ' (copy)', 0, 150),
            'description'   => $row['description'],
            'trigger_event' => $row['trigger_event'],
            'conditions'    => $row['conditions'],
            'actions'       => $row['actions'],
            'is_active'     => 0,
            'run_count'     => 0,
            'last_run_at'   => null,
            'watermark_at'  => null,
            'watermark_id'  => 0,
            'last_error'    => null,
            'created_by'    => $this->adminId($request),
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->log($request, 'automation_rule.duplicated', 'automation_rule', $newId, 'Duplicated rule "' . $row['name'] . '".');

        $this->respond($request, 'Rule duplicated and left paused.', '/admin/workflow/rules');
    }

    /** POST /admin/workflow/rules/{id}/delete */
    public function deleteRule(Request $request): never
    {
        $this->authorize($request, 'workflow.manage');

        $id  = $request->routeParamInt('id');
        $row = Database::selectOne('SELECT * FROM `automation_rules` WHERE `id` = :id', ['id' => $id]);

        if ($row === null) {
            $this->back('error', 'That rule no longer exists.', '/admin/workflow/rules');
        }

        Database::execute('DELETE FROM `automation_rules` WHERE `id` = :id', ['id' => $id]);

        $this->log($request, 'automation_rule.deleted', 'automation_rule', $id, 'Deleted rule "' . $row['name'] . '".');

        $this->respond($request, 'Rule deleted.', '/admin/workflow/rules');
    }

    /**
     * POST /admin/workflow/rules/test
     *
     * Evaluate a conditions block against a sample payload and report each
     * comparison. This is the same evaluator the runner uses, so what passes
     * here is what will pass at run time.
     */
    public function testRule(Request $request): never
    {
        $this->authorize($request, 'workflow.manage');

        $conditionsRaw = trim($request->string('conditions'));
        $payloadRaw    = trim($request->string('payload'));

        $conditions = $conditionsRaw === ''
            ? ['match' => 'all', 'rules' => []]
            : self::decodeJson($conditionsRaw);

        if (!is_array($conditions)) {
            Response::json([
                'success' => false,
                'message' => 'Conditions: ' . self::describeJsonProblem($conditionsRaw),
            ], 422);
        }

        $payload = $payloadRaw === '' ? [] : self::decodeJson($payloadRaw);

        if (!is_array($payload)) {
            Response::json([
                'success' => false,
                'message' => 'Sample payload: ' . self::describeJsonProblem($payloadRaw),
            ], 422);
        }

        $outcome = self::evaluateConditions($conditions, $payload);

        Response::json([
            'success' => true,
            'message' => $outcome['passed'] ? 'The rule would fire.' : 'The rule would not fire.',
            'data'    => $outcome,
        ]);
    }

    // =================================================================
    // Condition evaluation — shared with the automation runner
    // =================================================================

    /**
     * Evaluate a conditions block against an event payload.
     *
     * @param  array<string,mixed> $conditions {match: "all"|"any", rules: [{field, operator, value}]}
     * @param  array<string,mixed> $payload
     * @return array{passed:bool,match:string,results:list<array<string,mixed>>}
     */
    public static function evaluateConditions(array $conditions, array $payload): array
    {
        $match = ($conditions['match'] ?? 'all') === 'any' ? 'any' : 'all';
        $rules = is_array($conditions['rules'] ?? null) ? $conditions['rules'] : [];

        $results = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $field    = (string) ($rule['field'] ?? '');
            $operator = (string) ($rule['operator'] ?? '=');
            $expected = $rule['value'] ?? null;
            $actual   = self::valueAtPath($payload, $field);

            $passed = self::compare($actual, $operator, $expected);

            $results[] = [
                'field'    => $field,
                'operator' => $operator,
                'expected' => $expected,
                'actual'   => $actual,
                'present'  => $actual !== null,
                'passed'   => $passed,
            ];
        }

        // No rules means "on every event of this type", which is a valid rule.
        if ($results === []) {
            return ['passed' => true, 'match' => $match, 'results' => []];
        }

        $passedCount = 0;

        foreach ($results as $result) {
            if ($result['passed']) {
                $passedCount++;
            }
        }

        return [
            'passed'  => $match === 'any' ? $passedCount > 0 : $passedCount === count($results),
            'match'   => $match,
            'results' => $results,
        ];
    }

    /** Read `a.b.c` out of a nested payload. */
    private static function valueAtPath(array $payload, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $cursor = $payload;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    private static function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        if ($operator === 'is_empty') {
            return $actual === null || $actual === '' || $actual === [];
        }

        if ($operator === 'is_not_empty') {
            return !($actual === null || $actual === '' || $actual === []);
        }

        if ($operator === 'in' || $operator === 'not_in') {
            $list = is_array($expected)
                ? $expected
                : array_map('trim', explode(',', (string) $expected));

            $found = false;

            foreach ($list as $candidate) {
                if (self::looseEquals($actual, $candidate)) {
                    $found = true;
                    break;
                }
            }

            return $operator === 'in' ? $found : !$found;
        }

        return match ($operator) {
            '='            => self::looseEquals($actual, $expected),
            '!='           => !self::looseEquals($actual, $expected),
            '>'            => self::numeric($actual) > self::numeric($expected),
            '>='           => self::numeric($actual) >= self::numeric($expected),
            '<'            => self::numeric($actual) < self::numeric($expected),
            '<='           => self::numeric($actual) <= self::numeric($expected),
            'contains'     => $actual !== null && str_contains(self::text($actual), self::text($expected)),
            'not_contains' => $actual === null || !str_contains(self::text($actual), self::text($expected)),
            'starts_with'  => $actual !== null && str_starts_with(self::text($actual), self::text($expected)),
            default        => false,
        };
    }

    /** Numbers compare as numbers, everything else as case-insensitive text. */
    private static function looseEquals(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return self::truthy($a) === self::truthy($b);
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }

        return self::text($a) === self::text($b);
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(self::text($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return mb_strtolower(trim((string) $value));
    }

    // =================================================================
    // Rule input
    // =================================================================

    /**
     * Validate a rule submission and return the exact column payload.
     *
     * @return array{name:string,description:?string,trigger_event:string,conditions:?string,actions:string,is_active:int}
     */
    private function ruleInput(Request $request): array
    {
        $data = $this->validate($request, [
            'name'          => 'required|string|min:2|max:150',
            'description'   => 'nullable|string|max:255',
            'trigger_event' => 'required|in:' . implode(',', array_keys(AutomationService::EVENTS)),
            'conditions'    => 'nullable|string|max:60000',
            'actions'       => 'required|string|max:60000',
        ]);

        $conditionsRaw = trim((string) ($data['conditions'] ?? ''));
        $actionsRaw    = trim((string) ($data['actions'] ?? ''));

        $conditions = null;

        if ($conditionsRaw !== '') {
            $decoded = self::decodeJson($conditionsRaw);

            if (!is_array($decoded)) {
                throw ValidationException::forField('conditions', 'Conditions: ' . self::describeJsonProblem($conditionsRaw));
            }

            $error = self::validateConditionsShape($decoded);

            if ($error !== '') {
                throw ValidationException::forField('conditions', $error);
            }

            $conditions = self::encodeJson($decoded);
        }

        $decodedActions = self::decodeJson($actionsRaw);

        if (!is_array($decodedActions)) {
            throw ValidationException::forField('actions', 'Actions: ' . self::describeJsonProblem($actionsRaw));
        }

        $error = self::validateActionsShape($decodedActions, (string) $data['trigger_event']);

        if ($error !== '') {
            throw ValidationException::forField('actions', $error);
        }

        return [
            'name'          => (string) $data['name'],
            'description'   => ($data['description'] ?? '') === '' ? null : (string) $data['description'],
            'trigger_event' => (string) $data['trigger_event'],
            'conditions'    => $conditions,
            'actions'       => self::encodeJson($decodedActions),
            'is_active'     => in_array($request->string('is_active'), ['1', 'on', 'true'], true) ? 1 : 0,
        ];
    }

    /** @param array<mixed> $conditions */
    private static function validateConditionsShape(array $conditions): string
    {
        $match = $conditions['match'] ?? 'all';

        if (!in_array($match, ['all', 'any'], true)) {
            return 'Conditions: "match" must be "all" or "any".';
        }

        if (!array_key_exists('rules', $conditions) || !is_array($conditions['rules'])) {
            return 'Conditions: "rules" must be a list, even if it is empty.';
        }

        foreach (array_values($conditions['rules']) as $index => $rule) {
            $position = 'Condition ' . ($index + 1) . ': ';

            if (!is_array($rule)) {
                return $position . 'each entry must be an object with field, operator and value.';
            }

            if (trim((string) ($rule['field'] ?? '')) === '') {
                return $position . '"field" is required.';
            }

            $operator = (string) ($rule['operator'] ?? '');

            if (!isset(self::OPERATORS[$operator])) {
                return $position . '"' . $operator . '" is not an operator. Use one of '
                    . implode(', ', array_keys(self::OPERATORS)) . '.';
            }

            if (self::OPERATORS[$operator]['value'] !== 'none'
                && (!array_key_exists('value', $rule) || $rule['value'] === null || $rule['value'] === '')) {
                return $position . 'the "' . self::OPERATORS[$operator]['label'] . '" operator needs a value.';
            }
        }

        return '';
    }

    /**
     * Validate the actions block against the trigger it belongs to.
     *
     * Shape alone is not enough. `set_field` and `webhook` are the two actions
     * that reach outside the rule book — one writes a column, the other opens
     * an outbound connection — so both are constrained here as well as at run
     * time, and the operator finds out at Save rather than from a silent
     * failure on the next cron pass.
     *
     * @param array<mixed> $actions
     */
    private static function validateActionsShape(array $actions, string $triggerEvent): string
    {
        if ($actions === []) {
            return 'Actions: a rule needs at least one action.';
        }

        if (array_keys($actions) !== range(0, count($actions) - 1)) {
            return 'Actions: this must be a JSON array of actions, for example [{"type":"log_only","params":{"note":"…"}}].';
        }

        foreach ($actions as $index => $action) {
            $position = 'Action ' . ((int) $index + 1) . ': ';

            if (!is_array($action)) {
                return $position . 'each entry must be an object with "type" and "params".';
            }

            $type = (string) ($action['type'] ?? '');

            if (!isset(self::ACTION_TYPES[$type])) {
                return $position . '"' . $type . '" is not an action type. Use one of '
                    . implode(', ', array_keys(self::ACTION_TYPES)) . '.';
            }

            if (array_key_exists('params', $action) && !is_array($action['params'])) {
                return $position . '"params" must be an object.';
            }

            $error = self::validateActionParams($type, is_array($action['params'] ?? null) ? $action['params'] : [], $triggerEvent);

            if ($error !== '') {
                return $position . $error;
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $params
     */
    private static function validateActionParams(string $type, array $params, string $triggerEvent): string
    {
        $text = static fn (string $key): string => is_scalar($params[$key] ?? null) ? trim((string) $params[$key]) : '';

        switch ($type) {
            case 'notify_admins':
                if ($text('permission') === '' || $text('message') === '') {
                    return 'a notice needs both "permission" and "message".';
                }

                break;

            case 'send_email':
                if ($text('to') === '' || $text('subject') === '' || $text('body') === '') {
                    return 'an email needs "to", "subject" and "body".';
                }

                if (strcasecmp($text('to'), 'user') !== 0
                    && filter_var($text('to'), FILTER_VALIDATE_EMAIL) === false) {
                    return '"to" must be the word "user" or an email address.';
                }

                break;

            case 'open_approval':
                $allowed = AutomationService::approvalTypesFor($triggerEvent);

                if ($allowed === []) {
                    return 'this trigger carries no subject an approval can be raised against.';
                }

                if (!in_array($text('entity_type'), $allowed, true)) {
                    return '"entity_type" must be one of ' . implode(', ', $allowed) . ' for this trigger.';
                }

                break;

            case 'assign_ticket':
                if (!AutomationService::carriesTicket($triggerEvent)) {
                    return 'only a ticket trigger can assign a ticket.';
                }

                if ((int) ($params['admin_id'] ?? 0) <= 0) {
                    return '"admin_id" must be a numeric admin id.';
                }

                break;

            case 'set_field':
                $settable = AutomationService::settableColumns($triggerEvent);

                if ($settable === []) {
                    return 'no field on this trigger\'s subject may be set from a rule.';
                }

                if (!in_array($text('field'), $settable, true)) {
                    return '"' . $text('field') . '" is not settable. This trigger allows '
                        . implode(', ', $settable) . '.';
                }

                if (array_key_exists('value', $params) && !is_scalar($params['value']) && $params['value'] !== null) {
                    return '"value" must be a single value, not an object or a list.';
                }

                break;

            case 'webhook':
                $method = strtoupper($text('method') === '' ? 'POST' : $text('method'));

                if ($method !== 'POST' && $method !== 'GET') {
                    return 'a webhook may only be POST or GET.';
                }

                if (AutomationService::resolveWebhookTarget($text('url')) === null) {
                    return AutomationService::webhookProblem($text('url'));
                }

                break;

            case 'log_only':
                if ($text('note') === '') {
                    return 'a log entry needs a "note".';
                }

                break;
        }

        return '';
    }

    // =================================================================
    // JSON helpers
    // =================================================================

    private static function decodeJson(string $raw): mixed
    {
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private static function encodeJson(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Turn a JSON failure into something an operator can act on.
     *
     * PHP only reports "Syntax error" with no position, so the string is
     * re-scanned here for the structural mistakes people actually make and
     * the line and column are reported.
     */
    public static function describeJsonProblem(string $raw): string
    {
        if (trim($raw) === '') {
            return 'nothing was submitted.';
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return is_array($decoded)
                ? 'this must be a JSON object or array.'
                : 'this must be a JSON object or array, not a bare value.';
        }

        $line     = 1;
        $column   = 0;
        $inString = false;
        $escaped  = false;
        $stringAt = [1, 0];
        $stack    = [];
        $length   = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($char === "\n") {
                $line++;
                $column = 0;
                continue;
            }

            $column++;

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $stringAt = [$line, $column];
                continue;
            }

            if ($char === "'") {
                return sprintf('line %d, column %d — single quotes are not JSON, use double quotes.', $line, $column);
            }

            if ($char === '{' || $char === '[') {
                $stack[] = [$char, $line, $column];
                continue;
            }

            if ($char === '}' || $char === ']') {
                $open = array_pop($stack);

                if ($open === null) {
                    return sprintf('line %d, column %d — "%s" closes something that was never opened.', $line, $column, $char);
                }

                $expected = $open[0] === '{' ? '}' : ']';

                if ($char !== $expected) {
                    return sprintf(
                        'line %d, column %d — "%s" does not close the "%s" opened on line %d, column %d.',
                        $line,
                        $column,
                        $char,
                        $open[0],
                        $open[1],
                        $open[2]
                    );
                }
            }
        }

        if ($inString) {
            return sprintf('line %d, column %d — this string is never closed.', $stringAt[0], $stringAt[1]);
        }

        if ($stack !== []) {
            $open = $stack[count($stack) - 1];

            return sprintf('line %d, column %d — "%s" is never closed.', $open[1], $open[2], $open[0]);
        }

        if (preg_match('/,\s*[}\]]/', $raw, $matches, PREG_OFFSET_CAPTURE) === 1) {
            [$line, $column] = self::lineAndColumn($raw, (int) $matches[0][1]);

            return sprintf('line %d, column %d — there is a comma before the closing bracket.', $line, $column);
        }

        return json_last_error_msg() . '.';
    }

    /** @return array{0:int,1:int} */
    private static function lineAndColumn(string $raw, int $offset): array
    {
        $before = substr($raw, 0, $offset);
        $line   = substr_count($before, "\n") + 1;
        $lastNl = strrpos($before, "\n");

        return [$line, $lastNl === false ? $offset + 1 : $offset - $lastNl];
    }

    // =================================================================
    // Misc
    // =================================================================

    /**
     * Is the automation runner scheduled and switched on?
     *
     * {@see AutomationService} is what evaluates the rule book, but only when
     * the `automation:run` job actually ticks. An operator can delete or
     * disable that job under System → Jobs, and the rules screen says so
     * rather than implying automation is live when nothing will call it.
     */
    private static function runnerRegistered(): bool
    {
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM `scheduled_jobs`
                  WHERE `job_key` = 'automation:run' AND `is_enabled` = 1"
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

}

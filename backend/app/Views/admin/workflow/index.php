<?php

declare(strict_types=1);

/**
 * Approval queue.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $summary
 * @var string|null $oldestAt
 * @var int $actionable
 * @var list<array<string,mixed>> $workflows
 * @var array<string,string> $entityTypes
 * @var string $currency
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (string $status): string => match ($status) {
    'approved'  => 'success',
    'rejected'  => 'error',
    'cancelled' => 'neutral',
    default     => 'warning',
};

$pending   = (int) ($summary['pending'] ?? 0);
$approved  = (int) ($summary['approved'] ?? 0);
$rejected  = (int) ($summary['rejected'] ?? 0);
$decided   = $approved + $rejected;
$approvalRate = $decided > 0 ? (int) round($approved / $decided * 100) : 0;

$entityOptions = ['' => 'Any subject'];
foreach ($entityTypes as $key => $label) {
    $entityOptions[$key] = $label;
}

$activeWorkflows = [];
foreach ($workflows as $workflow) {
    if ((int) $workflow['is_active'] === 1 && (int) $workflow['step_count'] > 0) {
        $activeWorkflows[] = $workflow;
    }
}

?>

<div class="wgrid mb-5">

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#B45309" href="/admin/workflow?status=pending">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('inbox') ?></div></div>
      <div class="kpi__value"><?= $this->number($pending) ?></div>
      <div class="kpi__label">Waiting for a decision</div>
      <div class="kpi__foot">
        <?= $oldestAt === null ? 'Nothing outstanding' : 'Oldest raised ' . $this->e($this->timeAgo($oldestAt)) ?>
      </div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#B80049" href="/admin/workflow?status=pending">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('user-check') ?></div></div>
      <div class="kpi__value"><?= $this->number($actionable) ?></div>
      <div class="kpi__label">You can decide</div>
      <div class="kpi__foot">Steps that match your role or permissions</div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#006A34" href="/admin/workflow?status=approved">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('check-circle') ?></div></div>
      <div class="kpi__value"><?= $decided === 0 ? '—' : $approvalRate . '%' ?></div>
      <div class="kpi__label">Approved of everything decided</div>
      <div class="kpi__foot"><?= $this->number($approved) ?> approved · <?= $this->number($rejected) ?> rejected</div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#632CE5" href="/admin/workflow/rules">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('refresh') ?></div></div>
      <div class="kpi__value"><?= $this->number(count($workflows)) ?></div>
      <div class="kpi__label">Workflows configured</div>
      <div class="kpi__foot"><?= $this->number(count($activeWorkflows)) ?> active and routable</div>
    </a>
  </div>
</div>

<div class="card mb-5">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search a summary, workflow or subject id…',
      'showDateRange'     => true,
      'selects'           => [
          ['name' => 'status', 'label' => 'Status', 'options' => [
              ''          => 'Any status',
              'pending'   => 'Pending',
              'approved'  => 'Approved',
              'rejected'  => 'Rejected',
              'cancelled' => 'Cancelled',
          ]],
          ['name' => 'entity_type', 'label' => 'Subject', 'options' => $entityOptions],
      ],
  ]); ?>

  <?php if ($rows === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
      <p class="empty__title">Nothing in this queue</p>
      <p class="empty__text">
        Requests appear here when a workflow covers something that needs sign-off.
        Clear the filters to see closed requests.
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Subject</th>
            <th>Workflow</th>
            <th class="text-right">
              <a href="<?= $this->queryUrl(['sort_by' => 'amount', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Amount</a>
            </th>
            <th>Waiting on</th>
            <th>Status</th>
            <th>Raised by</th>
            <th>
              <a href="<?= $this->queryUrl(['sort_by' => 'created_at', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Age</a>
            </th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
            $entityType  = (string) $row['entity_type'];
            $entityLabel = $row['entity_label'];
            $steps       = (int) $row['total_steps'];
            $decisions   = (int) $row['decisions'];
            ?>
            <tr>
              <td>
                <a href="/admin/workflow/<?= (int) $row['id'] ?>" class="cell-user__name">
                  <?= $this->e($row['summary'] ?? ('Request #' . (int) $row['id'])) ?>
                </a>
                <div class="tiny muted">
                  <?= $this->e(\Zuste\Services\ApprovalService::entityLabelFor($entityType)) ?>
                  ·
                  <?php if ($entityLabel === null): ?>
                    <span class="text-error">subject #<?= (int) $row['entity_id'] ?> is gone</span>
                  <?php else: ?>
                    <?= $this->e($entityLabel) ?>
                  <?php endif; ?>
                </div>
              </td>

              <td>
                <span class="small"><?= $this->e($row['workflow_name']) ?></span>
                <div class="tiny faint">
                  <?= $decisions ?> of <?= $steps ?> step<?= $steps === 1 ? '' : 's' ?> decided
                </div>
              </td>

              <td class="text-right mono small" style="white-space:nowrap">
                <?= $row['amount'] === null ? '—' : $this->e($this->money($row['amount'], $currency)) ?>
              </td>

              <td>
                <?php if ($row['step_id'] === null): ?>
                  <span class="chip chip--error">
                    <span class="chip__dot"></span> No step configured
                  </span>
                <?php else: ?>
                  <span class="small" style="font-weight:600">
                    <?= (int) $row['step_order'] ?>. <?= $this->e($row['step_name']) ?>
                  </span>
                  <div class="tiny muted">
                    <?php if (!empty($row['step_role_name'])): ?>
                      <?= $this->e($row['step_role_name']) ?>
                    <?php endif; ?>
                    <?php if (!empty($row['permission'])): ?>
                      <code class="mono"><?= $this->e($row['permission']) ?></code>
                    <?php endif; ?>
                    <?php if (empty($row['step_role_name']) && empty($row['permission'])): ?>
                      Any approver
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>

              <td>
                <span class="chip chip--<?= $statusTone((string) $row['status']) ?>">
                  <span class="chip__dot"></span>
                  <?= $this->e(ucfirst((string) $row['status'])) ?>
                </span>
              </td>

              <td class="small muted truncate" style="max-width:150px">
                <?= $this->e($row['requested_by_name'] ?? 'System') ?>
              </td>

              <td class="small muted" style="white-space:nowrap">
                <?= $this->timeAgo($row['created_at']) ?>
              </td>

              <td class="actions">
                <a href="/admin/workflow/<?= (int) $row['id'] ?>"
                   class="btn btn--<?= $row['can_act'] ? 'primary' : 'ghost' ?> btn--sm">
                  <?= $row['can_act'] ? 'Decide' : 'Open' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  <?php endif; ?>
</div>

<div class="grid grid--sidebar">

  <div class="card">
    <div class="card__head">
      <div>
        <h2 class="h3">Workflows</h2>
        <p class="muted small mt-1">Which requests route where, and at what amount.</p>
      </div>
      <a href="/admin/workflow/rules" class="btn btn--ghost btn--sm">
        Automation rules <?= $this->icon('chevron-right', 'ic ic--sm') ?>
      </a>
    </div>

    <?php if ($workflows === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('grid', 'ic ic--lg') ?></div>
        <p class="empty__title">No workflows</p>
        <p class="empty__text">Nothing will ever need approval until a workflow exists.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Workflow</th><th>Subject</th><th class="text-right">Applies from</th>
              <th class="text-right">Steps</th><th>State</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($workflows as $workflow): ?>
              <tr>
                <td>
                  <span class="small" style="font-weight:600"><?= $this->e($workflow['name']) ?></span>
                  <?php if (!empty($workflow['description'])): ?>
                    <div class="tiny muted"><?= $this->e($workflow['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="small muted">
                  <?= $this->e(\Zuste\Services\ApprovalService::entityLabelFor((string) $workflow['entity_type'])) ?>
                </td>
                <td class="text-right mono small" style="white-space:nowrap">
                  <?= $workflow['threshold_amount'] === null
                        ? 'Every one'
                        : $this->e($this->money($workflow['threshold_amount'], $currency)) ?>
                </td>
                <td class="text-right mono small"><?= (int) $workflow['step_count'] ?></td>
                <td>
                  <?php if ((int) $workflow['is_active'] !== 1): ?>
                    <span class="chip chip--neutral"><span class="chip__dot"></span>Off</span>
                  <?php elseif ((int) $workflow['step_count'] === 0): ?>
                    <span class="chip chip--error"><span class="chip__dot"></span>No steps</span>
                  <?php else: ?>
                    <span class="chip chip--success"><span class="chip__dot"></span>Active</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card__body">
        <p class="tiny faint">
          Workflows and their steps are seeded with the database. Editing them is a schema-level
          change, so it is not exposed here.
        </p>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($this->can('workflow.manage')): ?>
    <div class="card">
      <div class="card__head"><h2 class="h3">Raise a request</h2></div>

      <div class="card__body">
        <?php if ($activeWorkflows === []): ?>
          <div class="callout callout--warning">
            <?= $this->icon('alert', 'ic ic--sm') ?>
            <div>
              No active workflow has any steps, so a request raised now could never be decided.
            </div>
          </div>
        <?php else: ?>
          <form method="post" action="/admin/workflow/requests" class="grid">
            <?= $this->csrf() ?>

            <div class="field">
              <label class="field__label req" for="entity_type">Subject type</label>
              <select class="select" id="entity_type" name="entity_type" required>
                <?php foreach ($entityTypes as $key => $label): ?>
                  <option value="<?= $this->e($key) ?>" <?= $this->old('entity_type') === $key ? 'selected' : '' ?>>
                    <?= $this->e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if ($this->hasError('entity_type')): ?>
                <p class="field__error"><?= $this->e($this->error('entity_type')) ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="field__label req" for="entity_id">Subject id</label>
              <input class="input mono" id="entity_id" name="entity_id" type="number" min="1" required
                     value="<?= $this->old('entity_id') ?>">
              <p class="field__hint">The row id of the record. It is checked before the request is created.</p>
              <?php if ($this->hasError('entity_id')): ?>
                <p class="field__error"><?= $this->e($this->error('entity_id')) ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="field__label" for="amount">Amount (optional)</label>
              <input class="input mono" id="amount" name="amount" type="number" step="0.01" min="0"
                     value="<?= $this->e($this->old('amount')) ?>">
              <p class="field__hint">Decides which threshold applies. Leave blank for a workflow with no threshold.</p>
              <?php if ($this->hasError('amount')): ?>
                <p class="field__error"><?= $this->e($this->error('amount')) ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="field__label req" for="summary">Summary</label>
              <input class="input" id="summary" name="summary" required maxlength="255" minlength="3"
                     placeholder="Payout of ₹82,000 to Aarav Mehta"
                     value="<?= $this->e($this->old('summary')) ?>">
              <p class="field__hint">What the approver sees in the queue.</p>
              <?php if ($this->hasError('summary')): ?>
                <p class="field__error"><?= $this->e($this->error('summary')) ?></p>
              <?php endif; ?>
            </div>

            <div>
              <button type="submit" class="btn btn--primary">
                <?= $this->icon('plus', 'ic ic--sm') ?> Raise request
              </button>
            </div>

            <p class="tiny faint">
              You will not be able to decide this request yourself. A subject can only carry
              one approval request, so raising a second one for the same record opens the first.
            </p>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<link rel="stylesheet" href="/admin/assets/css/widgets.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<?php $this->stop(); ?>

<?php

declare(strict_types=1);

/**
 * One approval request: what it is about, every step, and the decision form.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $request
 * @var list<array{step:array<string,mixed>,action:array<string,mixed>|null,state:string}> $timeline
 * @var array<string,mixed>|null $currentStep
 * @var bool $canAct
 * @var string $blockedReason
 * @var string|null $entityLabel
 * @var string|null $entityUrl
 * @var string $entityName
 * @var bool $canCancel
 */

$this->extend('admin.layouts.app');
$this->start('content');

$status = (string) $request['status'];

$statusTone = match ($status) {
    'approved'  => 'success',
    'rejected'  => 'error',
    'cancelled' => 'neutral',
    default     => 'warning',
};

$stateTone = static fn (string $state, ?array $action): string => match (true) {
    $state === 'done' && ($action['action'] ?? '') === 'rejected' => 'error',
    $state === 'done'    => 'success',
    $state === 'current' => 'warning',
    $state === 'skipped' => 'neutral',
    default              => 'neutral',
};

?>

<div class="row row--between row--wrap gap-3 mb-5">
  <a href="/admin/workflow" class="btn btn--ghost btn--sm">
    <?= $this->icon('chevron-left', 'ic ic--sm') ?> Back to the queue
  </a>

  <div class="row gap-2">
    <span class="chip chip--<?= $statusTone ?><?= $status === 'pending' ? ' chip--pulse' : '' ?>">
      <span class="chip__dot"></span><?= $this->e(ucfirst($status)) ?>
    </span>
    <span class="chip chip--neutral"><?= $this->e($entityName) ?></span>
  </div>
</div>

<div class="grid grid--sidebar">

  <!-- Timeline -->
  <div class="card">
    <div class="card__head">
      <div>
        <h2 class="h3">Steps</h2>
        <p class="muted small mt-1">
          <?= $this->e($request['workflow_name']) ?><?php
          if (!empty($request['workflow_description'])): ?> — <?= $this->e($request['workflow_description']) ?><?php
          endif; ?>
        </p>
      </div>
    </div>

    <div class="card__body">
      <?php if ($timeline === []): ?>
        <div class="callout callout--error">
          <?= $this->icon('alert', 'ic ic--sm') ?>
          <div>
            <strong>This workflow has no steps.</strong>
            Nothing can be decided until at least one step exists on workflow
            #<?= (int) $request['workflow_id'] ?>.
          </div>
        </div>
      <?php else: ?>
        <ol class="tl">
          <?php foreach ($timeline as $entry): ?>
            <?php
            $step   = $entry['step'];
            $action = $entry['action'];
            $state  = $entry['state'];
            $tone   = $stateTone($state, $action);
            ?>
            <li class="tl__item tl__item--<?= $this->e($state) ?>">
              <span class="tl__marker" aria-hidden="true">
                <?php if ($state === 'done'): ?>
                  <?= $this->icon(($action['action'] ?? '') === 'rejected' ? 'x' : 'check', 'ic ic--sm') ?>
                <?php else: ?>
                  <?= (int) $step['step_order'] ?>
                <?php endif; ?>
              </span>

              <div class="tl__body">
                <div class="row row--between row--wrap gap-2">
                  <div>
                    <p class="tl__title"><?= $this->e($step['name']) ?></p>
                    <p class="tiny muted">
                      <?php if (!empty($step['role_name'])): ?>
                        <?= $this->e($step['role_name']) ?> role
                      <?php endif; ?>
                      <?php if (!empty($step['role_name']) && !empty($step['permission'])): ?> or <?php endif; ?>
                      <?php if (!empty($step['permission'])): ?>
                        <code class="mono"><?= $this->e($step['permission']) ?></code>
                      <?php endif; ?>
                      <?php if (empty($step['role_name']) && empty($step['permission'])): ?>
                        Any approver
                      <?php endif; ?>
                      <?php if ((int) $step['is_optional'] === 1): ?>
                        · marked optional
                      <?php endif; ?>
                    </p>
                  </div>

                  <span class="chip chip--<?= $tone ?>">
                    <?= $this->e(match ($state) {
                        'done'    => ucfirst((string) ($action['action'] ?? 'decided')),
                        'current' => 'Waiting',
                        'skipped' => 'Not reached',
                        default   => 'Upcoming',
                    }) ?>
                  </span>
                </div>

                <?php if ($action !== null): ?>
                  <div class="tl__note">
                    <div class="row gap-2">
                      <span class="avatar avatar--sm avatar--fallback">
                        <?= $this->e($this->initials((string) ($action['admin_name'] ?? 'Admin'))) ?>
                      </span>
                      <div class="flex-1">
                        <p class="small" style="font-weight:600">
                          <?= $this->e($action['admin_name'] ?? 'Removed admin') ?>
                        </p>
                        <p class="tiny muted">
                          <?= $this->dateTime($action['created_at']) ?> · <?= $this->timeAgo($action['created_at']) ?>
                        </p>
                      </div>
                    </div>

                    <?php if (!empty($action['note'])): ?>
                      <p class="small mt-2"><?= nl2br($this->e($action['note'])) ?></p>
                    <?php else: ?>
                      <p class="tiny faint mt-2">No note left.</p>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </div>
  </div>

  <!-- Side column -->
  <div class="grid">

    <div class="card">
      <div class="card__head"><h2 class="h3">Request</h2></div>

      <div class="card__body">
        <dl class="kv">
          <div class="kv__row">
            <dt class="label">Summary</dt>
            <dd><?= $this->e($request['summary'] ?? '—') ?></dd>
          </div>

          <div class="kv__row">
            <dt class="label">Subject</dt>
            <dd>
              <?php if ($entityLabel === null): ?>
                <span class="text-error">#<?= (int) $request['entity_id'] ?> no longer exists</span>
              <?php elseif ($entityUrl !== null): ?>
                <a href="<?= $this->e($entityUrl) ?>"><?= $this->e($entityLabel) ?></a>
                <span class="tiny faint">#<?= (int) $request['entity_id'] ?></span>
              <?php else: ?>
                <?= $this->e($entityLabel) ?>
              <?php endif; ?>
            </dd>
          </div>

          <div class="kv__row">
            <dt class="label">Amount</dt>
            <dd class="mono">
              <?= $request['amount'] === null ? 'Not applicable' : $this->e($this->money($request['amount'])) ?>
            </dd>
          </div>

          <div class="kv__row">
            <dt class="label">Threshold</dt>
            <dd class="mono small muted">
              <?= $request['workflow_threshold'] === null
                    ? 'Applies to every one'
                    : 'From ' . $this->e($this->money($request['workflow_threshold'])) ?>
            </dd>
          </div>

          <div class="kv__row">
            <dt class="label">Raised by</dt>
            <dd>
              <?= $this->e($request['requested_by_name'] ?? 'System') ?>
              <?php if (!empty($request['requested_by_email'])): ?>
                <div class="tiny faint mono"><?= $this->e(\Zuste\Helpers\Str::maskEmail((string) $request['requested_by_email'])) ?></div>
              <?php endif; ?>
            </dd>
          </div>

          <div class="kv__row">
            <dt class="label">Raised</dt>
            <dd class="small muted">
              <?= $this->dateTime($request['created_at']) ?> · <?= $this->timeAgo($request['created_at']) ?>
            </dd>
          </div>

          <?php if ($request['completed_at'] !== null): ?>
            <div class="kv__row">
              <dt class="label">Closed</dt>
              <dd class="small muted"><?= $this->dateTime($request['completed_at']) ?></dd>
            </div>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2 class="h3">Decision</h2></div>

      <div class="card__body">
        <?php if ($canAct && $currentStep !== null): ?>
          <p class="small muted mb-3">
            You are deciding step <?= (int) $currentStep['step_order'] ?>,
            <strong><?= $this->e($currentStep['name']) ?></strong>.
          </p>

          <form method="post" action="/admin/workflow/<?= (int) $request['id'] ?>/act" class="grid" id="decision-form">
            <?= $this->csrf() ?>

            <?php // The confirm dialog re-submits the form itself, which drops
                  // the pressed button's value, so the decision rides a hidden
                  // field that each button sets before the dialog opens. ?>
            <input type="hidden" name="decision" value="approved" id="decision-value">

            <div class="field">
              <label class="field__label" for="note">Note</label>
              <textarea class="textarea" id="note" name="note" rows="3" maxlength="500"
                        placeholder="Why you are approving or rejecting. Kept on the record."></textarea>
              <p class="field__hint">Up to 500 characters. Shown to everyone on the timeline.</p>
            </div>

            <div class="row gap-2">
              <button type="submit" class="btn btn--primary flex-1" data-decision="approved"
                      data-confirm="Approve step <?= (int) $currentStep['step_order'] ?> of this request?"
                      data-confirm-title="Approve?" data-confirm-label="Approve" data-confirm-tone="primary">
                <?= $this->icon('check', 'ic ic--sm') ?> Approve
              </button>

              <button type="submit" class="btn btn--danger flex-1" data-decision="rejected"
                      data-confirm="Rejecting ends this request immediately. Continue?"
                      data-confirm-title="Reject?" data-confirm-label="Reject" data-confirm-tone="danger">
                <?= $this->icon('x', 'ic ic--sm') ?> Reject
              </button>
            </div>
          </form>
        <?php else: ?>
          <div class="callout callout--<?= $status === 'pending' ? 'info' : 'success' ?>">
            <?= $this->icon($status === 'pending' ? 'info' : 'check-circle', 'ic ic--sm') ?>
            <div><?= $this->e($blockedReason !== '' ? $blockedReason : 'There is nothing left to decide.') ?></div>
          </div>
        <?php endif; ?>

        <?php if ($canCancel): ?>
          <form method="post" action="/admin/workflow/<?= (int) $request['id'] ?>/cancel" class="mt-4">
            <?= $this->csrf() ?>
            <div class="field">
              <label class="field__label" for="reason">Cancel instead</label>
              <input class="input" id="reason" name="reason" maxlength="400"
                     placeholder="Why this no longer needs a decision">
            </div>
            <button type="submit" class="btn btn--outline btn--sm mt-2"
                    data-confirm="Cancel this request? It cannot be reopened."
                    data-confirm-title="Cancel request?" data-confirm-label="Cancel it" data-confirm-tone="danger">
              <?= $this->icon('x', 'ic ic--sm') ?> Cancel request
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
/* Vertical step timeline — not part of admin.css, scoped to this screen. */
.tl { list-style: none; margin: 0; padding: 0; }
.tl__item { position: relative; padding: 0 0 24px 44px; }
.tl__item:last-child { padding-bottom: 0; }
.tl__item::before {
  content: ""; position: absolute; left: 13px; top: 30px; bottom: 2px;
  width: 2px; background: var(--outline-variant);
}
.tl__item:last-child::before { display: none; }
.tl__marker {
  position: absolute; left: 0; top: 0; width: 28px; height: 28px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--r-full); font-size: 12px; font-weight: 700;
  background: var(--surface-variant); color: var(--on-surface-variant);
  border: 2px solid var(--surface);
}
.tl__item--done .tl__marker    { background: var(--tertiary); color: #fff; }
.tl__item--current .tl__marker { background: var(--primary);  color: #fff; }
.tl__title { font-weight: 600; font-size: 14px; }
.tl__note {
  margin-top: 10px; padding: 12px;
  border: 1px solid var(--outline-variant); border-radius: var(--r);
  background: var(--surface-low, var(--surface-variant));
}
/* Definition list used for the request facts. */
.kv { margin: 0; }
.kv__row { display: grid; grid-template-columns: 110px 1fr; gap: 12px; padding: 8px 0; }
.kv__row + .kv__row { border-top: 1px solid var(--outline-variant); }
.kv__row dd { margin: 0; font-size: 14px; word-break: break-word; }
@media (max-width: 560px) { .kv__row { grid-template-columns: 1fr; gap: 2px; } }
</style>

<script>
(function () {
  // Runs on the button itself, so it fires before the panel's document-level
  // confirm handler and the hidden field is already correct when it submits.
  document.querySelectorAll('#decision-form [data-decision]').forEach(function (button) {
    button.addEventListener('click', function () {
      var field = document.getElementById('decision-value');
      if (field) { field.value = button.dataset.decision; }
    });
  });
})();
</script>
<?php $this->stop(); ?>

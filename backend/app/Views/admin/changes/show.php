<?php

declare(strict_types=1);

/**
 * One change request: old value beside new, per field, with a decision on each.
 *
 * This screen is the reason migration 038 exists. Before it, "approve this
 * change" meant re-approving a whole profile without ever being shown what the
 * change was, because the update had already destroyed the old value.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $request
 * @var array{from:array<string,mixed>|null,to:array<string,mixed>|null,changes:list<array<string,mixed>>} $comparison
 * @var list<array<string,mixed>> $versions
 * @var list<array<string,mixed>> $reasons
 * @var bool $enabled
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (string $s): string => match ($s) {
    'approved'                => 'success',
    'partial'                 => 'info',
    'rejected'                => 'error',
    'pending'                 => 'warning',
    default                   => 'neutral',
};

/** A long value is folded so twelve fields still fit on one screen. */
$preview = static function (?string $value, int $limit = 400): string {
    $value = (string) $value;

    return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) . '…' : $value;
};

$canDecide = $this->can('creators.approve');
$pending   = array_values(array_filter($request['fields'], static fn (array $f): bool => $f['status'] === 'pending'));

?>

<a href="/admin/creator-changes" class="btn btn--ghost btn--sm mb-4">
  <?= $this->icon('chevron-left', 'ic ic--sm') ?> Back to the queue
</a>

<div class="grid grid--sidebar">

  <div class="col gap-4">

    <!-- Header -->
    <div class="card">
      <div class="card__body">
        <div class="row gap-4 row--wrap">
          <?php if (!empty($request['avatar_url'])): ?>
            <img class="avatar avatar--lg" src="<?= $this->e($request['avatar_url']) ?>" alt="">
          <?php else: ?>
            <div class="avatar avatar--lg avatar--fallback" style="font-size:18px">
              <?= $this->e($this->initials((string) $request['display_name'])) ?>
            </div>
          <?php endif; ?>

          <div class="flex-1">
            <div class="row gap-2 row--wrap mb-1">
              <h2 class="h1"><?= $this->e($request['display_name']) ?></h2>
              <span class="chip chip--<?= $statusTone((string) $request['status']) ?>">
                <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $request['status']))) ?>
              </span>
              <?php if (!empty($request['overdue'])): ?>
                <span class="chip chip--error"><?= $this->icon('clock', 'ic ic--sm') ?> Overdue</span>
              <?php endif; ?>
            </div>
            <p class="small muted">
              @<?= $this->e($request['handle']) ?> ·
              <a href="/admin/creators/<?= (int) $request['creator_id'] ?>">Open the full profile</a>
            </p>
            <p class="small muted mt-1">
              Raised <?= $this->dateTime($request['created_at']) ?>
              (<?= (int) $request['age_hours'] ?>h ago)
              by <?= $this->e($request['requested_by_name'] ?: ucfirst((string) $request['requested_by_type'])) ?>
            </p>
          </div>
        </div>

        <?php if (in_array($request['status'], ['approved', 'partial'], true) && $request['applied_at'] === null): ?>
          <div class="row gap-2 mt-3 small text-error">
            <?= $this->icon('alert', 'ic ic--sm') ?>
            This request was approved but never written to the profile.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- The proposal, field by field -->
    <div class="card">
      <div class="card__head">
        <h2 class="h3">Proposed changes</h2>
        <p class="small muted">
          The left column is what the profile said when this was submitted; the right is
          what the creator is asking for.
        </p>
      </div>

      <?php if ($request['fields'] === []): ?>
        <div class="empty">
          <div class="empty__art"><?= $this->icon('file', 'ic ic--lg') ?></div>
          <p class="empty__title">Nothing proposed</p>
          <p class="empty__text">This request has no field rows.</p>
        </div>
      <?php else: ?>
        <div class="card__body col gap-4">
          <?php foreach ($request['fields'] as $field): ?>
            <div style="border:1px solid var(--outline-variant);border-radius:12px;overflow:hidden">

              <div class="row gap-2 row--wrap" style="padding:10px 14px;background:var(--surface-container)">
                <strong class="small"><?= $this->e($field['field_label']) ?></strong>
                <span class="chip chip--neutral chip--sm"><?= $this->e($field['section_label']) ?></span>
                <span class="tiny faint mono"><?= $this->e($field['target_table']) ?>.<?= $this->e($field['field'] ?? '—') ?></span>

                <span style="margin-left:auto">
                  <span class="chip chip--<?= $field['status'] === 'approved' ? 'success' : ($field['status'] === 'rejected' ? 'error' : ($field['status'] === 'pending' ? 'warning' : 'neutral')) ?> chip--sm">
                    <?= $this->e(ucfirst((string) $field['status'])) ?>
                  </span>
                </span>
              </div>

              <div class="grid" style="grid-template-columns:1fr 1fr;gap:0">
                <div style="padding:12px 14px;border-right:1px solid var(--outline-variant)">
                  <div class="tiny muted mb-1">Before</div>
                  <div class="small" style="white-space:pre-wrap;word-break:break-word">
                    <?= $field['old_value'] === null || $field['old_value'] === ''
                        ? '<span class="faint">empty</span>'
                        : $this->e($preview($field['old_value'])) ?>
                  </div>
                </div>
                <div style="padding:12px 14px;background:color-mix(in srgb, var(--primary) 5%, transparent)">
                  <div class="tiny muted mb-1">After</div>
                  <div class="small" style="white-space:pre-wrap;word-break:break-word">
                    <?= $field['new_value'] === null || $field['new_value'] === ''
                        ? '<span class="faint">empty</span>'
                        : $this->e($preview($field['new_value'])) ?>
                  </div>
                </div>
              </div>

              <?php if (!empty($field['drifted'])): ?>
                <!--
                  The live row no longer matches what was recorded as the old
                  value: somebody edited this field directly while the request
                  waited. Approving would overwrite that edit, so the reviewer
                  is shown it rather than finding out afterwards.
                -->
                <div class="row gap-2" style="padding:10px 14px;border-top:1px solid var(--outline-variant)">
                  <span class="text-warning"><?= $this->icon('alert', 'ic ic--sm') ?></span>
                  <p class="tiny flex-1">
                    The profile now says <strong><?= $this->e($preview((string) $field['live_value'], 120)) ?></strong> —
                    it was changed elsewhere after this was submitted. Approving replaces it.
                  </p>
                </div>
              <?php endif; ?>

              <?php if ($field['status'] !== 'pending'): ?>
                <div class="row gap-2 row--wrap" style="padding:10px 14px;border-top:1px solid var(--outline-variant)">
                  <span class="tiny muted">
                    <?= $this->e(ucfirst((string) $field['status'])) ?>
                    <?php if (!empty($field['reviewer_name'])): ?>
                      by <?= $this->e($field['reviewer_name']) ?>
                    <?php endif; ?>
                    <?php if (!empty($field['reviewed_at'])): ?>
                      on <?= $this->dateTime($field['reviewed_at']) ?>
                    <?php endif; ?>
                  </span>
                  <?php if (!empty($field['reason_label'])): ?>
                    <span class="chip chip--error chip--sm"><?= $this->e($field['reason_label']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($field['decision_note'])): ?>
                    <span class="tiny muted">“<?= $this->e($field['decision_note']) ?>”</span>
                  <?php endif; ?>
                </div>
              <?php elseif ($canDecide): ?>
                <div class="row gap-2" style="padding:10px 14px;border-top:1px solid var(--outline-variant)">
                  <form method="post" action="/admin/creator-changes/fields/<?= (int) $field['id'] ?>/approve">
                    <?= $this->csrf() ?>
                    <button type="submit" class="btn btn--sm btn--success">
                      <?= $this->icon('check', 'ic ic--sm') ?> Approve this field
                    </button>
                  </form>
                  <button type="button" class="btn btn--sm btn--ghost"
                          onclick="ChangeReview.reject('/admin/creator-changes/fields/<?= (int) $field['id'] ?>/reject')">
                    <?= $this->icon('x', 'ic ic--sm') ?> Reject this field
                  </button>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Version comparison, straight off `revisions` -->
    <div class="card">
      <div class="card__head">
        <h2 class="h3">Version comparison</h2>
        <p class="small muted">
          <?php if ($comparison['from'] === null): ?>
            No earlier version of this profile was stored.
          <?php else: ?>
            Version <?= (int) $comparison['from']['version'] ?>,
            saved <?= $this->dateTime($comparison['from']['created_at']) ?>,
            against <?= $comparison['to'] === null
                ? 'the profile as it stands now'
                : 'version ' . (int) $comparison['to']['version'] ?>.
          <?php endif; ?>
        </p>
      </div>

      <?php if ($comparison['changes'] === []): ?>
        <div class="empty">
          <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
          <p class="empty__title">Identical</p>
          <p class="empty__text">Nothing an operator edits differs between these two versions.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Field</th>
                <th>Section</th>
                <th>Before</th>
                <th>After</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($comparison['changes'] as $change): ?>
                <tr>
                  <td class="small">
                    <?= $this->e($change['label']) ?>
                    <?php if (!empty($change['reviewed'])): ?>
                      <span class="chip chip--info chip--sm">Reviewed</span>
                    <?php endif; ?>
                  </td>
                  <td class="small muted"><?= $this->e($change['section_label'] ?? '—') ?></td>
                  <td class="small" style="max-width:280px;word-break:break-word">
                    <?= $change['before'] === '' ? '<span class="faint">empty</span>' : $this->e($preview($change['before'], 160)) ?>
                  </td>
                  <td class="small" style="max-width:280px;word-break:break-word">
                    <?= $change['after'] === '' ? '<span class="faint">empty</span>' : $this->e($preview($change['after'], 160)) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if (count($versions) > 1): ?>
        <div class="card__body row gap-2 row--wrap" style="border-top:1px solid var(--outline-variant)">
          <span class="tiny muted">Compare against:</span>
          <?php foreach ($versions as $version): ?>
            <?php if ($comparison['from'] !== null && (int) $version['id'] === (int) $comparison['from']['id']) {
                continue;
            } ?>
            <a class="chip chip--neutral chip--sm"
               href="<?= $this->queryUrl(['against' => (int) $version['id']]) ?>">
              v<?= (int) $version['version'] ?> · <?= $this->date($version['created_at']) ?>
            </a>
          <?php endforeach; ?>
          <a class="chip chip--neutral chip--sm" href="<?= $this->queryUrl(['against' => null]) ?>">Live profile</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Decide the whole submission -->
  <div class="col gap-4">

    <div class="card">
      <div class="card__head">
        <h2 class="h3">Decision</h2>
      </div>
      <div class="card__body col gap-3">
        <?php if ($pending === []): ?>
          <p class="small muted">Every field on this request has been decided.</p>
        <?php elseif (!$canDecide): ?>
          <p class="small muted">You do not have the <span class="mono">creators.approve</span> permission.</p>
        <?php else: ?>
          <p class="small muted">
            <?= count($pending) ?> field(s) still waiting. A decision here settles all of them
            at once; the buttons beside each field settle one.
          </p>

          <form method="post" action="/admin/creator-changes/<?= (int) $request['id'] ?>/approve" class="col gap-2">
            <?= $this->csrf() ?>
            <label class="field">
              <span class="field__label">Note (optional)</span>
              <textarea class="textarea" name="note" rows="2" maxlength="1000"
                        placeholder="Recorded against the decision."></textarea>
            </label>
            <button type="submit" class="btn btn--primary btn--block">
              <?= $this->icon('check', 'ic ic--sm') ?> Approve and apply
            </button>
          </form>

          <div class="divider"></div>

          <form method="post" action="/admin/creator-changes/<?= (int) $request['id'] ?>/reject" class="col gap-2">
            <?= $this->csrf() ?>
            <label class="field">
              <span class="field__label">Reason</span>
              <select class="input" name="reason" required>
                <?php foreach ($reasons as $reason): ?>
                  <option value="<?= $this->e($reason['value']) ?>"><?= $this->e($reason['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="field__hint">Reported on, so pick the closest one rather than “Other”.</span>
            </label>
            <label class="field">
              <span class="field__label">Note</span>
              <textarea class="textarea" name="note" rows="2" maxlength="1000"
                        placeholder="Required when the reason is “Other”."></textarea>
            </label>
            <button type="submit" class="btn btn--danger btn--block">
              <?= $this->icon('x', 'ic ic--sm') ?> Reject
            </button>
          </form>

          <?php if ($this->can('creators.edit')): ?>
            <div class="divider"></div>
            <form method="post" action="/admin/creator-changes/<?= (int) $request['id'] ?>/withdraw">
              <?= $this->csrf() ?>
              <button type="submit" class="btn btn--ghost btn--block btn--sm"
                      onclick="return confirm('Take this request out of the queue without a verdict?')">
                Withdraw
              </button>
              <p class="field__hint mt-1">
                For a proposal that has stopped being a question. Records no verdict against the creator.
              </p>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <h2 class="h3">This submission</h2>
      </div>
      <div class="card__body col gap-2 small">
        <div class="row gap-2">
          <span class="muted flex-1">Summary</span>
          <span><?= $this->e($request['summary'] ?? '—') ?></span>
        </div>
        <div class="row gap-2">
          <span class="muted flex-1">Creator status</span>
          <span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $request['creator_status']))) ?></span>
        </div>
        <div class="row gap-2">
          <span class="muted flex-1">Fields</span>
          <span><?= count($request['fields']) ?></span>
        </div>
        <div class="row gap-2">
          <span class="muted flex-1">Applied</span>
          <span><?= $request['applied_at'] === null ? '—' : $this->dateTime($request['applied_at']) ?></span>
        </div>
        <?php if (!empty($request['reviewer_name'])): ?>
          <div class="row gap-2">
            <span class="muted flex-1">Reviewed by</span>
            <span><?= $this->e($request['reviewer_name']) ?></span>
          </div>
        <?php endif; ?>
        <?php if (!$enabled): ?>
          <div class="divider"></div>
          <p class="tiny muted">
            The hold-for-review switch is off, so new edits are going live immediately.
            Anything already in this queue still has to be decided.
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Per-field rejection, posted as a normal form so CSRF handling is unchanged. -->
<form id="field-reject-form" method="post" action="" style="display:none">
  <?= $this->csrf() ?>
  <input type="hidden" name="reason" id="field-reject-reason">
  <input type="hidden" name="note" id="field-reject-note">
</form>

<div id="field-reject-dialog" class="card" style="display:none;position:fixed;inset:auto 24px 24px auto;max-width:420px;z-index:60;box-shadow:var(--e3)">
  <div class="card__head"><h3 class="h3">Reject this field</h3></div>
  <div class="card__body col gap-3">
    <label class="field">
      <span class="field__label">Reason</span>
      <select class="input" id="field-reject-choice">
        <?php foreach ($reasons as $reason): ?>
          <option value="<?= $this->e($reason['value']) ?>" data-note="<?= $reason['requires_note'] ? '1' : '0' ?>">
            <?= $this->e($reason['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span class="field__label">Note</span>
      <textarea class="textarea" id="field-reject-text" rows="3"
                placeholder="Required when the reason is “Other”."></textarea>
    </label>
    <div class="row gap-2">
      <button type="button" class="btn btn--danger btn--sm" onclick="ChangeReview.confirm()">Reject field</button>
      <button type="button" class="btn btn--ghost btn--sm" onclick="ChangeReview.close()">Cancel</button>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
/** Per-field rejection: pick a reason, post the hidden form. */
const ChangeReview = {
  action: '',

  reject(action) {
    this.action = action;
    document.getElementById('field-reject-dialog').style.display = '';
  },

  close() {
    document.getElementById('field-reject-dialog').style.display = 'none';
  },

  confirm() {
    const choice = document.getElementById('field-reject-choice');
    const note = document.getElementById('field-reject-text').value.trim();

    // `other` carries requires_note in the taxonomy and the service refuses it
    // without one; checking here saves a round trip.
    const needsNote = choice.selectedOptions[0] && choice.selectedOptions[0].dataset.note === '1';

    if (needsNote && note === '') {
      window.alert('That reason needs a note explaining the decision.');
      return;
    }

    const form = document.getElementById('field-reject-form');
    form.action = this.action;
    document.getElementById('field-reject-reason').value = choice.value;
    document.getElementById('field-reject-note').value = note;
    form.submit();
  },
};
</script>
<?php $this->stop(); ?>

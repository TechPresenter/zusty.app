<?php

declare(strict_types=1);

/**
 * The profile change queue.
 *
 * Two tabs over one job: field changes a creator has proposed, and gallery or
 * portfolio items waiting for a verdict. Both answer "may this be published".
 *
 * @var \Zuste\Core\View $this
 * @var string $tab
 * @var string $status
 * @var list<array<string,mixed>> $requests
 * @var list<array<string,mixed>> $items
 * @var array<string,mixed> $pagination
 * @var array<string,int> $counts
 * @var array<string,int> $itemCounts
 * @var list<array<string,mixed>> $reasons
 * @var bool $enabled
 * @var int $slaHours
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (string $s): string => match ($s) {
    'approved'             => 'success',
    'partial'              => 'info',
    'rejected'             => 'error',
    'pending'              => 'warning',
    'withdrawn', 'superseded' => 'neutral',
    default                => 'neutral',
};

$changeQueues = [
    'pending'    => 'Waiting',
    'approved'   => 'Approved',
    'partial'    => 'Part approved',
    'rejected'   => 'Rejected',
    'withdrawn'  => 'Withdrawn',
    'superseded' => 'Superseded',
    'all'        => 'All',
];

$itemQueues = [
    'pending'  => 'Waiting',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];

?>

<!-- Which queue -->
<div class="chip-row mb-4">
  <a href="/admin/creator-changes"
     class="chip-filter<?= $tab === 'changes' ? ' chip-filter--active' : '' ?>">
    <?= $this->icon('edit', 'ic ic--sm') ?> Field changes
    <?php if (!empty($counts['pending'])): ?>
      <span class="badge badge--sm"><?= $this->number($counts['pending']) ?></span>
    <?php endif; ?>
  </a>
  <a href="/admin/creator-changes?tab=items"
     class="chip-filter<?= $tab === 'items' ? ' chip-filter--active' : '' ?>">
    <?= $this->icon('image', 'ic ic--sm') ?> Gallery &amp; portfolio
    <?php if (!empty($itemCounts['pending'])): ?>
      <span class="badge badge--sm"><?= $this->number($itemCounts['pending']) ?></span>
    <?php endif; ?>
  </a>
</div>

<?php if ($tab === 'changes' && !$enabled): ?>
  <!--
    The queue exists whether or not the switch is on: an administrator editing
    a profile on a creator's behalf can still raise a request, and anything
    already waiting still has to be decided. But if an operator is looking at an
    empty queue, the reason is almost always this setting, so say so here rather
    than let them conclude the feature is broken.
  -->
  <div class="card mb-5" style="border-color:var(--secondary);background:color-mix(in srgb, var(--secondary) 6%, transparent)">
    <div class="card__body row gap-3" style="padding:14px 18px">
      <span class="text-secondary"><?= $this->icon('info') ?></span>
      <p class="small flex-1">
        <strong>Edits are going live immediately.</strong>
        Creator changes are only held here while
        <span class="mono">creator.hold_changes_for_review</span> is on. With it off, a reviewed
        field is still flagged for review but the new value is already on the profile.
      </p>
      <?php if ($this->can('settings.manage')): ?>
        <a href="/admin/settings/creator" class="btn btn--sm btn--secondary">Settings</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'changes' && !empty($counts['unapplied'])): ?>
  <!--
    Approved but never written. The creator has been told their change was
    accepted, so this is the one state in the queue that is actively lying to
    somebody until it is cleared.
  -->
  <div class="card mb-5" style="border-color:var(--error)">
    <div class="card__body row gap-3" style="padding:14px 18px">
      <span class="text-error"><?= $this->icon('alert') ?></span>
      <p class="small flex-1">
        <strong><?= $this->number($counts['unapplied']) ?></strong> approved
        request(s) were never written to the profile. Open each one and approve the
        remaining fields again — the value it wanted may have been taken since.
      </p>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'changes'): ?>

  <!-- Status buckets -->
  <div class="chip-row mb-5">
    <?php foreach ($changeQueues as $value => $label): ?>
      <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1, 'tab' => 'changes'], '/admin/creator-changes') ?>"
         class="chip-filter<?= $status === $value ? ' chip-filter--active' : '' ?>">
        <?= $this->e($label) ?>
        <?php if (!empty($counts[$value])): ?>
          <span class="badge badge--sm"><?= $this->number($counts[$value]) ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <?php $this->partial('admin.partials.toolbar', [
        'action'            => '/admin/creator-changes',
        'filters'           => $filters,
        'searchPlaceholder' => 'Search by creator, handle or summary…',
    ]); ?>

    <?php if ($requests === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('inbox', 'ic ic--lg') ?></div>
        <p class="empty__title">Nothing waiting</p>
        <p class="empty__text">No profile changes match this filter.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Creator</th>
              <th>Proposed</th>
              <th>Fields</th>
              <th>Raised by</th>
              <th>Waiting</th>
              <th>Status</th>
              <th class="text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($requests as $row): ?>
              <tr>
                <td>
                  <div class="cell-user">
                    <?php if (!empty($row['avatar_url'])): ?>
                      <img class="avatar avatar--sm" src="<?= $this->e($row['avatar_url']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                      <div class="avatar avatar--sm avatar--fallback">
                        <?= $this->e($this->initials((string) $row['display_name'])) ?>
                      </div>
                    <?php endif; ?>
                    <div class="flex-1 truncate">
                      <div class="cell-user__name truncate">
                        <a href="/admin/creator-changes/<?= (int) $row['id'] ?>">
                          <?= $this->e($row['display_name']) ?>
                        </a>
                      </div>
                      <div class="cell-user__meta truncate">@<?= $this->e($row['handle']) ?></div>
                    </div>
                  </div>
                </td>

                <td class="small"><?= $this->e($row['summary'] ?? '—') ?></td>

                <td class="small">
                  <?= (int) $row['field_count'] ?>
                  <?php if ((int) $row['pending_count'] > 0 && (int) $row['pending_count'] < (int) $row['field_count']): ?>
                    <span class="tiny muted">(<?= (int) $row['pending_count'] ?> open)</span>
                  <?php endif; ?>
                </td>

                <td class="small">
                  <?= $this->e($row['requested_by_name'] ?: ucfirst((string) $row['requested_by_type'])) ?>
                  <div class="tiny muted"><?= $this->e(ucfirst((string) $row['requested_by_type'])) ?></div>
                </td>

                <td class="small muted">
                  <?= $this->timeAgo($row['created_at']) ?>
                  <?php if (!empty($row['overdue'])): ?>
                    <div class="tiny text-error">
                      Over the <?= (int) $slaHours ?>h target
                    </div>
                  <?php endif; ?>
                </td>

                <td>
                  <span class="chip chip--<?= $statusTone((string) $row['status']) ?>">
                    <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $row['status']))) ?>
                  </span>
                  <?php if (in_array($row['status'], ['approved', 'partial'], true) && $row['applied_at'] === null): ?>
                    <div class="tiny text-error mt-1">Not applied</div>
                  <?php endif; ?>
                </td>

                <td class="actions">
                  <a href="/admin/creator-changes/<?= (int) $row['id'] ?>" class="btn btn--sm btn--secondary">
                    <?= $row['status'] === 'pending' ? 'Review' : 'Open' ?>
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

<?php else: ?>

  <!-- Item moderation -->
  <div class="chip-row mb-5">
    <?php foreach ($itemQueues as $value => $label): ?>
      <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1, 'tab' => 'items'], '/admin/creator-changes') ?>"
         class="chip-filter<?= $status === $value ? ' chip-filter--active' : '' ?>">
        <?= $this->e($label) ?>
        <?php if (!empty($itemCounts[$value])): ?>
          <span class="badge badge--sm"><?= $this->number($itemCounts[$value]) ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card__head">
      <h2 class="h3">Gallery and portfolio items</h2>
      <p class="small muted">
        A verdict here is independent of the creator's own show/hide switch. An item
        is public only when the creator has it active <em>and</em> an operator has approved it.
      </p>
    </div>

    <?php if ($items === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('image', 'ic ic--lg') ?></div>
        <p class="empty__title">Nothing waiting</p>
        <p class="empty__text">No items match this filter.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Item</th>
              <th>Creator</th>
              <th>Collection</th>
              <th>Uploaded</th>
              <th>Verdict</th>
              <th class="text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td>
                  <div class="row gap-3">
                    <?php if (!empty($item['thumbnail_url']) || !empty($item['media_url'])): ?>
                      <img src="<?= $this->e($item['thumbnail_url'] ?: $item['media_url']) ?>" alt=""
                           loading="lazy"
                           style="width:56px;height:56px;object-fit:cover;border-radius:8px;background:var(--surface-container)">
                    <?php endif; ?>
                    <div class="flex-1 truncate">
                      <div class="truncate"><?= $this->e($item['title'] ?: 'Untitled') ?></div>
                      <div class="tiny muted truncate"><?= $this->e($item['caption'] ?? '') ?></div>
                    </div>
                  </div>
                </td>

                <td class="small">
                  <a href="/admin/creators/<?= (int) $item['creator_id'] ?>">
                    <?= $this->e($item['display_name']) ?>
                  </a>
                  <div class="tiny muted">@<?= $this->e($item['handle']) ?></div>
                </td>

                <td class="small">
                  <span class="chip chip--neutral chip--sm">
                    <?= $this->e(ucfirst(str_replace('_', ' ', (string) $item['collection']))) ?>
                  </span>
                </td>

                <td class="small muted"><?= $this->dateTime($item['created_at']) ?></td>

                <td>
                  <span class="chip chip--<?= $item['moderation_status'] === 'approved' ? 'success' : ($item['moderation_status'] === 'rejected' ? 'error' : 'warning') ?>">
                    <span class="chip__dot"></span><?= $this->e(ucfirst((string) $item['moderation_status'])) ?>
                  </span>
                  <?php if (!empty($item['reason_label'])): ?>
                    <div class="tiny text-error mt-1"><?= $this->e($item['reason_label']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($item['reviewer_name'])): ?>
                    <div class="tiny muted mt-1"><?= $this->e($item['reviewer_name']) ?></div>
                  <?php endif; ?>
                </td>

                <td class="actions">
                  <?php if ($this->can('creators.approve')): ?>
                    <div class="row gap-2">
                      <?php if ($item['moderation_status'] !== 'approved'): ?>
                        <form method="post" action="/admin/creator-changes/items/<?= (int) $item['id'] ?>/approve">
                          <?= $this->csrf() ?>
                          <button type="submit" class="btn btn--sm btn--success">
                            <?= $this->icon('check', 'ic ic--sm') ?> Approve
                          </button>
                        </form>
                      <?php endif; ?>
                      <?php if ($item['moderation_status'] !== 'rejected'): ?>
                        <button type="button" class="btn btn--sm btn--danger"
                                onclick="ChangeQueue.rejectItem(<?= (int) $item['id'] ?>)">
                          <?= $this->icon('x', 'ic ic--sm') ?> Reject
                        </button>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span class="tiny muted">View only</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
    <?php endif; ?>
  </div>

  <!--
    One rejection form, filled in and posted by the buttons above. A reason is
    required because the same list is what "why were items rejected this month"
    is reported from; a free-text note alone would answer that question with
    seventeen different sentences meaning the same thing.
  -->
  <form id="item-reject-form" method="post" action="" style="display:none">
    <?= $this->csrf() ?>
    <input type="hidden" name="reason" id="item-reject-reason">
    <input type="hidden" name="note" id="item-reject-note">
  </form>

  <div id="item-reject-dialog" class="card" style="display:none;position:fixed;inset:auto 24px 24px auto;max-width:420px;z-index:60;box-shadow:var(--e3)">
    <div class="card__head">
      <h3 class="h3">Reject this item</h3>
    </div>
    <div class="card__body col gap-3">
      <label class="field">
        <span class="field__label">Reason</span>
        <select class="input" id="item-reject-choice">
          <?php foreach ($reasons as $reason): ?>
            <option value="<?= $this->e($reason['value']) ?>"
                    data-note="<?= $reason['requires_note'] ? '1' : '0' ?>">
              <?= $this->e($reason['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label">Note to the creator</span>
        <textarea class="textarea" id="item-reject-text" rows="3"
                  placeholder="Optional, unless the reason is “Other”."></textarea>
      </label>
      <div class="row gap-2">
        <button type="button" class="btn btn--danger btn--sm" onclick="ChangeQueue.confirmItem()">Reject item</button>
        <button type="button" class="btn btn--ghost btn--sm" onclick="ChangeQueue.close()">Cancel</button>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
/**
 * Item rejection.
 *
 * Deliberately dependency-free and posted as a normal form, so CSRF, redirect
 * and toast handling stay identical to every other admin action.
 */
const ChangeQueue = {
  itemId: 0,

  rejectItem(id) {
    this.itemId = id;
    document.getElementById('item-reject-dialog').style.display = '';
  },

  close() {
    document.getElementById('item-reject-dialog').style.display = 'none';
  },

  confirmItem() {
    const choice = document.getElementById('item-reject-choice');
    const note = document.getElementById('item-reject-text').value.trim();

    // `other` carries requires_note in the taxonomy, and the service refuses it
    // without one. Catching it here saves the operator a round trip.
    const needsNote = choice.selectedOptions[0] && choice.selectedOptions[0].dataset.note === '1';

    if (needsNote && note === '') {
      window.alert('That reason needs a note explaining the decision.');
      return;
    }

    const form = document.getElementById('item-reject-form');
    form.action = '/admin/creator-changes/items/' + this.itemId + '/reject';
    document.getElementById('item-reject-reason').value = choice.value;
    document.getElementById('item-reject-note').value = note;
    form.submit();
  },
};
</script>
<?php $this->stop(); ?>

<?php

declare(strict_types=1);

/**
 * Audit trail.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $entries
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var list<string> $actions
 */

$this->extend('admin.layouts.app');
$this->start('content');

$actionOptions = ['' => 'Any action'];
foreach ($actions as $action) {
    $actionOptions[(string) $action] = (string) $action;
}

$actorTone = static fn (string $type): string => match ($type) {
    'admin'  => 'primary',
    'user'   => 'info',
    'cron'   => 'neutral',
    default  => 'neutral',
};

?>

<div class="card mb-5" style="border-color:var(--outline-variant)">
  <div class="card__body row gap-3" style="padding:14px 18px">
    <span class="faint"><?= $this->icon('shield') ?></span>
    <p class="small muted">
      Every state-changing action is recorded here with the actor, the subject and a before/after diff.
      Entries are immutable and retained for 12 months. Sensitive values (passwords, keys, account numbers)
      are redacted before they are written.
    </p>
  </div>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Filter by action…',
      'showDateRange'     => true,
      'showExport'        => true,
      'selects'           => [
          ['name' => 'actor_type', 'label' => 'Actor', 'options' => [
              '' => 'Any actor', 'admin' => 'Admin', 'user' => 'User', 'system' => 'System', 'cron' => 'Cron',
          ]],
          ['name' => 'subject_type', 'label' => 'Subject', 'options' => [
              '' => 'Any subject', 'user' => 'User', 'creator' => 'Creator', 'booking' => 'Booking',
              'payment' => 'Payment', 'withdrawal' => 'Withdrawal', 'settings' => 'Settings',
              'admin' => 'Admin', 'role' => 'Role',
          ]],
          ['name' => 'action', 'label' => 'Action', 'options' => $actionOptions],
      ],
  ]); ?>

  <?php if ($entries === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('shield', 'ic ic--lg') ?></div>
      <p class="empty__title">No matching entries</p>
      <p class="empty__text">Try widening the date range or clearing the filters.</p>
      <a href="/admin/logs" class="btn btn--outline"><?= $this->icon('refresh', 'ic ic--sm') ?> Reset filters</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th>Actor</th>
            <th>Action</th>
            <th>Subject</th>
            <th>Description</th>
            <th>Changes</th>
            <th>IP</th>
            <th>When</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $entry): ?>
            <tr>
              <td class="mono tiny faint"><?= (int) $entry['id'] ?></td>

              <td>
                <span class="chip chip--<?= $actorTone((string) $entry['actor_type']) ?>">
                  <?= $this->e(ucfirst((string) $entry['actor_type'])) ?>
                </span>
                <?php if (!empty($entry['actor_name'])): ?>
                  <div class="tiny muted truncate mt-1" style="max-width:130px"><?= $this->e($entry['actor_name']) ?></div>
                <?php endif; ?>
              </td>

              <td class="mono small" style="font-weight:600"><?= $this->e($entry['action']) ?></td>

              <td class="small muted">
                <?php if (!empty($entry['subject_type'])): ?>
                  <?= $this->e($entry['subject_type']) ?>
                  <?php if (!empty($entry['subject_id'])): ?>
                    <span class="mono tiny">#<?= (int) $entry['subject_id'] ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="faint">—</span>
                <?php endif; ?>
              </td>

              <td class="small truncate" style="max-width:260px"><?= $this->e($entry['description'] ?? '') ?></td>

              <td style="max-width:220px">
                <?php if (!empty($entry['properties'])): ?>
                  <details>
                    <summary class="tiny text-secondary" style="cursor:pointer;font-weight:600">View diff</summary>
                    <pre class="mono tiny" style="margin-top:6px;padding:8px;background:var(--surface-low);
                                border-radius:var(--r);overflow-x:auto;max-width:220px;white-space:pre-wrap"><?=
                      $this->e((string) json_encode($entry['properties'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                    ?></pre>
                  </details>
                <?php else: ?>
                  <span class="tiny faint">—</span>
                <?php endif; ?>
              </td>

              <td class="mono tiny faint"><?= $this->e($entry['ip_address'] ?? '—') ?></td>

              <td class="small muted" style="white-space:nowrap">
                <?= $this->dateTime($entry['created_at']) ?>
                <div class="tiny faint"><?= $this->timeAgo($entry['created_at']) ?></div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  <?php endif; ?>
</div>

<?php $this->stop(); ?>

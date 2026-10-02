<?php

declare(strict_types=1);

/**
 * Support ticket queue.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $tickets
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var array<string,int> $counts
 * @var list<array<string,mixed>> $agents
 */

$this->extend('admin.layouts.app');
$this->start('content');

$status = (string) ($filters['status'] ?? 'open');

$priorityTone = static fn (string $p): string => match ($p) {
    'urgent' => 'error', 'high' => 'warning', 'normal' => 'info', default => 'neutral',
};

$statusTone = static fn (string $s): string => match ($s) {
    'resolved', 'closed'          => 'success',
    'open', 'pending_agent'       => 'warning',
    'pending_user'                => 'info',
    default                       => 'neutral',
};

?>

<div class="chip-row mb-5">
  <?php foreach ([
      'open' => 'Needs a reply', 'pending_user' => 'Awaiting customer',
      'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All',
  ] as $value => $label): ?>
    <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= $status === $value ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?>
      <span class="chip-filter__count"><?= $this->number((int) ($counts[$value] ?? 0)) ?></span>
    </a>
  <?php endforeach; ?>

  <span style="width:1px;background:var(--outline-variant);margin:0 4px"></span>

  <a href="<?= $this->queryUrl(['mine' => empty($filters['mine']) ? '1' : '', 'page' => 1]) ?>"
     class="chip-filter<?= !empty($filters['mine']) ? ' chip-filter--active' : '' ?>">
    <?= $this->icon('user-check', 'ic ic--sm') ?> Assigned to me
  </a>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search by code, subject, name or email…',
      'selects'           => [
          ['name' => 'priority', 'label' => 'Priority', 'options' => [
              '' => 'Any priority', 'urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low',
          ]],
          ['name' => 'category', 'label' => 'Category', 'options' => [
              '' => 'Any category', 'account' => 'Account', 'booking' => 'Booking', 'payment' => 'Payment',
              'wallet' => 'Wallet', 'kyc' => 'KYC', 'technical' => 'Technical', 'abuse' => 'Abuse', 'other' => 'Other',
          ]],
      ],
  ]); ?>

  <?php if ($tickets === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('headset', 'ic ic--lg') ?></div>
      <p class="empty__title">Inbox zero</p>
      <p class="empty__text">No tickets match this filter. Nice work.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Ticket</th>
            <th>Customer</th>
            <th>Category</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Assignee</th>
            <th class="text-right">Replies</th>
            <th>Last activity</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tickets as $ticket): ?>
            <tr>
              <td>
                <a href="/admin/tickets/<?= (int) $ticket['id'] ?>" class="mono small text-primary" style="font-weight:700">
                  <?= $this->e($ticket['code']) ?>
                </a>
                <div class="tiny muted truncate" style="max-width:220px"><?= $this->e($ticket['subject']) ?></div>
              </td>

              <td>
                <div class="cell-user">
                  <?php if (!empty($ticket['avatar_url'])): ?>
                    <img class="avatar avatar--sm" src="<?= $this->e($ticket['avatar_url']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="avatar avatar--sm avatar--fallback"><?= $this->e($this->initials((string) $ticket['user_name'])) ?></div>
                  <?php endif; ?>
                  <div class="flex-1 truncate">
                    <a href="/admin/users/<?= (int) $ticket['user_id'] ?>" class="cell-user__name truncate">
                      <?= $this->e($ticket['user_name']) ?>
                    </a>
                    <div class="cell-user__meta truncate"><?= $this->e($ticket['email']) ?></div>
                  </div>
                </div>
              </td>

              <td class="small muted"><?= $this->e(ucfirst((string) $ticket['category'])) ?></td>

              <td>
                <span class="chip chip--<?= $priorityTone((string) $ticket['priority']) ?>">
                  <?= $this->e(ucfirst((string) $ticket['priority'])) ?>
                </span>
              </td>

              <td>
                <span class="chip chip--<?= $statusTone((string) $ticket['status']) ?><?= $ticket['last_reply_by'] === 'user' && in_array($ticket['status'], ['open', 'pending_agent'], true) ? ' chip--pulse' : '' ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $ticket['status']))) ?>
                </span>
              </td>

              <td class="small muted"><?= $this->e($ticket['assignee_name'] ?: 'Unassigned') ?></td>

              <td class="text-right mono"><?= (int) $ticket['message_count'] ?></td>

              <td class="small muted" style="white-space:nowrap">
                <?= $this->timeAgo($ticket['last_reply_at'] ?? $ticket['created_at']) ?>
                <?php if ($ticket['last_reply_by'] === 'user'): ?>
                  <div class="tiny text-warning" style="font-weight:600">Customer waiting</div>
                <?php endif; ?>
              </td>

              <td class="actions">
                <a href="/admin/tickets/<?= (int) $ticket['id'] ?>" class="btn btn--outline btn--sm">
                  <?= $this->icon('chat', 'ic ic--sm') ?> Open
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

<?php $this->stop(); ?>

<?php

declare(strict_types=1);

/**
 * Users list.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $users
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var array<string,int> $counts
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (string $status): string => match ($status) {
    'active'    => 'success',
    'pending'   => 'warning',
    'suspended' => 'warning',
    'banned'    => 'error',
    'deleted'   => 'neutral',
    default     => 'neutral',
};

$kycTone = static fn (string $status): string => match ($status) {
    'verified' => 'success',
    'pending'  => 'warning',
    'rejected' => 'error',
    default    => 'neutral',
};

$activeTab = (string) ($filters['account_type'] ?? '');

?>

<!-- Quick segment tabs -->
<div class="chip-row mb-5">
  <?php
  $tabs = [
      ''        => ['All accounts', (int) ($counts['all'] ?? 0)],
      'brand'   => ['Brands',       (int) ($counts['brand'] ?? 0)],
      'creator' => ['Creators',     (int) ($counts['creator'] ?? 0)],
  ];
  ?>
  <?php foreach ($tabs as $value => [$label, $count]): ?>
    <a href="<?= $this->queryUrl(['account_type' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= $activeTab === (string) $value ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?><span class="chip-filter__count"><?= $this->number($count) ?></span>
    </a>
  <?php endforeach; ?>

  <span style="width:1px;background:var(--outline-variant);margin:0 4px"></span>

  <?php foreach (['suspended' => 'Suspended', 'banned' => 'Banned'] as $value => $label): ?>
    <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= (string) ($filters['status'] ?? '') === $value ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?><span class="chip-filter__count"><?= $this->number((int) ($counts[$value] ?? 0)) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search by name, email, phone or company…',
      'showDateRange'     => true,
      'showExport'        => true,
      'selects'           => [
          [
              'name'    => 'status',
              'label'   => 'Status',
              'options' => ['' => 'Any status', 'active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended', 'banned' => 'Banned'],
          ],
          [
              'name'    => 'kyc_status',
              'label'   => 'KYC',
              'options' => ['' => 'Any KYC', 'none' => 'Not started', 'pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'],
          ],
      ],
  ]); ?>

  <?php if ($users === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('users', 'ic ic--lg') ?></div>
      <p class="empty__title">No users found</p>
      <p class="empty__text">
        Nothing matches these filters. Try widening the date range or clearing the search.
      </p>
      <a href="/admin/users" class="btn btn--outline"><?= $this->icon('refresh', 'ic ic--sm') ?> Reset filters</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>User</th>
            <th>Contact</th>
            <th>Type</th>
            <th>Status</th>
            <th>KYC</th>
            <th class="text-right">Bookings</th>
            <th class="text-right">Wallet</th>
            <th>
              <a href="<?= $this->queryUrl(['sort_by' => 'created_at', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'ASC' ? 'DESC' : 'ASC']) ?>"
                 style="display:inline-flex;align-items:center;gap:4px">
                Joined <?= $this->icon(($filters['sort_dir'] ?? 'DESC') === 'ASC' ? 'arrow-up' : 'arrow-down', 'ic ic--sm') ?>
              </a>
            </th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
            <tr>
              <td>
                <div class="cell-user">
                  <?php if (!empty($user['avatar_url'])): ?>
                    <img class="avatar" src="<?= $this->e($user['avatar_url']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="avatar avatar--fallback"><?= $this->e($this->initials((string) $user['name'])) ?></div>
                  <?php endif; ?>
                  <div class="flex-1 truncate">
                    <div class="cell-user__name truncate">
                      <a href="/admin/users/<?= (int) $user['id'] ?>"><?= $this->e($user['name']) ?></a>
                      <?php if (!empty($user['creator_verified'])): ?>
                        <span class="text-secondary" title="Verified creator"><?= $this->icon('check-circle', 'ic ic--sm') ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="cell-user__meta truncate">
                      <?= $this->e($user['company_name'] ?: ($user['handle'] ? '@' . $user['handle'] : '—')) ?>
                    </div>
                  </div>
                </div>
              </td>

              <td class="small">
                <div class="truncate" style="max-width:200px"><?= $this->e($user['email'] ?? '—') ?></div>
                <div class="tiny muted">
                  <?= $this->e($user['phone'] ? (string) $user['phone_country_code'] . ' ' . (string) $user['phone'] : '—') ?>
                </div>
              </td>

              <td>
                <span class="chip chip--<?= $user['account_type'] === 'creator' ? 'info' : 'primary' ?>">
                  <?= $this->e(ucfirst((string) $user['account_type'])) ?>
                </span>
              </td>

              <td>
                <span class="chip chip--<?= $statusTone((string) $user['status']) ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst((string) $user['status'])) ?>
                </span>
              </td>

              <td>
                <span class="chip chip--<?= $kycTone((string) $user['kyc_status']) ?>">
                  <?= $this->e($user['kyc_status'] === 'none' ? 'Not started' : ucfirst((string) $user['kyc_status'])) ?>
                </span>
              </td>

              <td class="text-right mono"><?= $this->number($user['bookings_count']) ?></td>
              <td class="text-right mono" style="font-weight:600"><?= $this->moneyShort($user['wallet_balance']) ?></td>

              <td class="small muted" style="white-space:nowrap">
                <?= $this->date($user['created_at']) ?>
                <div class="tiny faint">
                  <?= $user['last_login_at'] ? 'Seen ' . $this->timeAgo($user['last_login_at']) : 'Never signed in' ?>
                </div>
              </td>

              <td class="actions">
                <a href="/admin/users/<?= (int) $user['id'] ?>" class="btn btn--outline btn--sm">
                  <?= $this->icon('eye', 'ic ic--sm') ?> View
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

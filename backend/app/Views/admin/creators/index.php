<?php

declare(strict_types=1);

/**
 * Creators list.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $creators
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var list<array<string,mixed>> $categories
 * @var array<string,int> $counts
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (string $status): string => match ($status) {
    'approved'       => 'success',
    'pending_review' => 'warning',
    'draft'          => 'neutral',
    'rejected'       => 'error',
    'suspended'      => 'error',
    default          => 'neutral',
};

$categoryOptions = ['' => 'Any category'];
foreach ($categories as $category) {
    $categoryOptions[(string) $category['id']] = (string) $category['name'];
}

?>

<div class="chip-row mb-5">
  <?php
  $tabs = [
      ''               => ['All',            (int) ($counts['all'] ?? 0)],
      'pending_review' => ['Awaiting review', (int) ($counts['pending_review'] ?? 0)],
      'approved'       => ['Live',            (int) ($counts['approved'] ?? 0)],
      'draft'          => ['Draft',           (int) ($counts['draft'] ?? 0)],
      'rejected'       => ['Rejected',        (int) ($counts['rejected'] ?? 0)],
      'suspended'      => ['Suspended',       (int) ($counts['suspended'] ?? 0)],
  ];
  ?>
  <?php foreach ($tabs as $value => [$label, $count]): ?>
    <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= (string) ($filters['status'] ?? '') === (string) $value ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?><span class="chip-filter__count"><?= $this->number($count) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search by name, handle or email…',
      'showExport'        => true,
      'selects'           => [
          ['name' => 'category_id', 'label' => 'Category', 'options' => $categoryOptions],
          ['name' => 'sort_by', 'label' => 'Sort by', 'options' => [
              'id'              => 'Newest first',
              'rating_avg'      => 'Highest rated',
              'followers_total' => 'Most followers',
              'collabs_count'   => 'Most collaborations',
              'display_name'    => 'Name (A–Z)',
          ]],
      ],
  ]); ?>

  <?php if ($creators === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('star', 'ic ic--lg') ?></div>
      <p class="empty__title">No creators found</p>
      <p class="empty__text">Nothing matches these filters. Creators appear here once they submit a profile.</p>
      <a href="/admin/creators" class="btn btn--outline"><?= $this->icon('refresh', 'ic ic--sm') ?> Reset filters</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Creator</th>
            <th>Category</th>
            <th>Location</th>
            <th class="text-right">Rating</th>
            <th class="text-right">Followers</th>
            <th class="text-right">Collabs</th>
            <th>Price range</th>
            <th>Profile</th>
            <th>Status</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($creators as $creator): ?>
            <tr>
              <td>
                <div class="cell-user">
                  <?php if (!empty($creator['avatar_url'])): ?>
                    <img class="avatar" src="<?= $this->e($creator['avatar_url']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="avatar avatar--fallback"><?= $this->e($this->initials((string) $creator['display_name'])) ?></div>
                  <?php endif; ?>
                  <div class="flex-1 truncate">
                    <div class="cell-user__name truncate">
                      <a href="/admin/creators/<?= (int) $creator['id'] ?>"><?= $this->e($creator['display_name']) ?></a>
                      <?php if (!empty($creator['is_verified'])): ?>
                        <span class="text-secondary" title="Verified"><?= $this->icon('check-circle', 'ic ic--sm') ?></span>
                      <?php endif; ?>
                      <?php if (!empty($creator['is_featured'])): ?>
                        <span class="text-primary" title="Featured"><?= $this->icon('star', 'ic ic--sm') ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="cell-user__meta truncate">@<?= $this->e($creator['handle']) ?></div>
                  </div>
                </div>
              </td>

              <td class="small muted"><?= $this->e($creator['category_name'] ?? '—') ?></td>
              <td class="small muted"><?= $this->e($creator['city'] ?: '—') ?></td>

              <td class="text-right">
                <?php if ((int) $creator['rating_count'] > 0): ?>
                  <span style="font-weight:700">★ <?= number_format((float) $creator['rating_avg'], 1) ?></span>
                  <div class="tiny faint"><?= $this->number($creator['rating_count']) ?> reviews</div>
                <?php else: ?>
                  <span class="faint">—</span>
                <?php endif; ?>
              </td>

              <td class="text-right mono"><?= $this->compact($creator['followers_total']) ?></td>
              <td class="text-right mono"><?= $this->number($creator['collabs_count']) ?></td>

              <td class="small" style="white-space:nowrap">
                <?php if ((float) $creator['price_max'] > 0): ?>
                  <?= $this->e(\Zuste\Helpers\Money::formatRange((float) $creator['price_min'], (float) $creator['price_max'], (string) $creator['currency'])) ?>
                <?php else: ?>
                  <span class="faint">Not set</span>
                <?php endif; ?>
              </td>

              <td style="min-width:110px">
                <div class="progress" title="<?= (int) $creator['profile_score'] ?>% complete">
                  <div class="progress__bar" style="width:<?= max(2, (int) $creator['profile_score']) ?>%"></div>
                </div>
                <div class="tiny faint mt-1"><?= (int) $creator['profile_score'] ?>% complete</div>
              </td>

              <td>
                <span class="chip chip--<?= $statusTone((string) $creator['status']) ?><?= $creator['status'] === 'pending_review' ? ' chip--pulse' : '' ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $creator['status']))) ?>
                </span>
              </td>

              <td class="actions">
                <a href="/admin/creators/<?= (int) $creator['id'] ?>" class="btn btn--outline btn--sm">
                  <?= $this->icon('eye', 'ic ic--sm') ?> Review
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

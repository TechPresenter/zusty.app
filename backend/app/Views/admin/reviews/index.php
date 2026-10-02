<?php

declare(strict_types=1);

/**
 * Review moderation.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $reviews
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$canModerate = $this->can('reviews.moderate');

?>

<div class="chip-row mb-5">
  <?php foreach (['' => 'All', 'hidden' => 'Hidden', 'flagged' => 'Flagged'] as $value => $label): ?>
    <a href="<?= $this->queryUrl(['visibility' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= (string) ($filters['visibility'] ?? '') === (string) $value ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?>
    </a>
  <?php endforeach; ?>

  <span style="width:1px;background:var(--outline-variant);margin:0 4px"></span>

  <?php for ($star = 5; $star >= 1; $star--): ?>
    <a href="<?= $this->queryUrl(['rating' => $star, 'page' => 1]) ?>"
       class="chip-filter<?= (int) ($filters['rating'] ?? 0) === $star ? ' chip-filter--active' : '' ?>">
      ★ <?= $star ?>
    </a>
  <?php endfor; ?>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search review text or reviewer…',
  ]); ?>

  <?php if ($reviews === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('star', 'ic ic--lg') ?></div>
      <p class="empty__title">No reviews found</p>
      <p class="empty__text">Reviews appear once collaborations are completed.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Reviewer</th>
            <th>About</th>
            <th class="text-right">Rating</th>
            <th>Review</th>
            <th>Booking</th>
            <th>Visibility</th>
            <th>Posted</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reviews as $review): ?>
            <tr>
              <td>
                <div class="cell-user">
                  <?php if (!empty($review['reviewer_avatar'])): ?>
                    <img class="avatar avatar--sm" src="<?= $this->e($review['reviewer_avatar']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="avatar avatar--sm avatar--fallback"><?= $this->e($this->initials((string) $review['reviewer_name'])) ?></div>
                  <?php endif; ?>
                  <div class="flex-1 truncate">
                    <a href="/admin/users/<?= (int) $review['reviewer_id'] ?>" class="cell-user__name truncate">
                      <?= $this->e($review['reviewer_name']) ?>
                    </a>
                    <div class="cell-user__meta"><?= $this->e($review['direction'] === 'brand_to_creator' ? 'Brand' : 'Creator') ?></div>
                  </div>
                </div>
              </td>

              <td class="small truncate" style="max-width:150px">
                <?php if (!empty($review['creator_id'])): ?>
                  <a href="/admin/creators/<?= (int) $review['creator_id'] ?>"><?= $this->e($review['creator_name']) ?></a>
                <?php else: ?>
                  <?= $this->e($review['reviewee_name']) ?>
                <?php endif; ?>
              </td>

              <td class="text-right" style="font-weight:700;color:var(--primary);white-space:nowrap">
                ★ <?= (int) $review['rating'] ?>.0
              </td>

              <td style="max-width:280px">
                <?php if (!empty($review['title'])): ?>
                  <div class="small" style="font-weight:600"><?= $this->e($review['title']) ?></div>
                <?php endif; ?>
                <p class="tiny muted clamp-2"><?= $this->e($review['comment'] ?: '—') ?></p>
                <?php if (!empty($review['reply'])): ?>
                  <p class="tiny mt-1" style="padding:6px 8px;background:var(--surface-low);border-radius:var(--r)">
                    <strong>Reply:</strong> <?= $this->e(\Zuste\Helpers\Str::limit((string) $review['reply'], 90)) ?>
                  </p>
                <?php endif; ?>
              </td>

              <td class="small">
                <a href="/admin/bookings/<?= (int) $review['booking_id'] ?>" class="mono text-primary">
                  #<?= $this->e($review['booking_code']) ?>
                </a>
              </td>

              <td>
                <?php if ((int) $review['is_published'] === 1): ?>
                  <span class="chip chip--success"><span class="chip__dot"></span>Published</span>
                <?php else: ?>
                  <span class="chip chip--neutral">Hidden</span>
                <?php endif; ?>
                <?php if (!empty($review['is_flagged'])): ?>
                  <div class="mt-1"><span class="chip chip--error">Flagged</span></div>
                <?php endif; ?>
              </td>

              <td class="small muted" style="white-space:nowrap"><?= $this->date($review['created_at']) ?></td>

              <td class="actions">
                <?php if ($canModerate): ?>
                  <div class="row gap-2 row--end">
                    <?php if ((int) $review['is_published'] === 1): ?>
                      <?php $this->partial('admin.partials.moderate-button', [
                          'action' => 'hide', 'label' => 'Hide', 'reviewId' => (int) $review['id'],
                          'style' => 'ghost', 'confirm' => 'The review will no longer be visible and stops counting toward the rating.',
                      ]); ?>
                    <?php else: ?>
                      <?php $this->partial('admin.partials.moderate-button', [
                          'action' => 'publish', 'label' => 'Publish', 'reviewId' => (int) $review['id'],
                          'style' => 'outline', 'confirm' => 'The review becomes visible and counts toward the rating.',
                      ]); ?>
                    <?php endif; ?>

                    <?php $this->partial('admin.partials.moderate-button', [
                        'action' => 'delete', 'label' => '', 'reviewId' => (int) $review['id'],
                        'style' => 'ghost', 'icon' => 'trash', 'danger' => true,
                        'confirm' => 'Deleting a review cannot be undone.',
                    ]); ?>
                  </div>
                <?php else: ?>
                  <span class="tiny faint">Read only</span>
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

<?php $this->stop(); ?>

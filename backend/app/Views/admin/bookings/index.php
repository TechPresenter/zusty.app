<?php

declare(strict_types=1);

/**
 * Bookings list.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $bookings
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var array<string,int> $counts
 * @var array<string,float> $totals
 */

$this->extend('admin.layouts.app');
$this->start('content');

$tone = static fn (string $s): string => match ($s) {
    'completed'                                                    => 'success',
    'pending', 'countered', 'awaiting_payment', 'accepted'         => 'warning',
    'confirmed', 'in_progress', 'delivered'                        => 'info',
    'cancelled', 'rejected', 'expired', 'disputed', 'refunded'     => 'error',
    default                                                        => 'neutral',
};

$tabs = [
    ''            => 'All',
    'pending'     => 'Pending',
    'confirmed'   => 'Confirmed',
    'in_progress' => 'In progress',
    'delivered'   => 'Delivered',
    'completed'   => 'Completed',
    'disputed'    => 'Disputed',
    'cancelled'   => 'Cancelled',
];

?>

<!-- Period totals -->
<section class="grid grid--4 mb-6">
  <?php foreach ([
      ['Gross volume',    $this->moneyShort($totals['gross']),      'trending-up', 'var(--primary)'],
      ['Platform fees',   $this->moneyShort($totals['fees']),       'wallet',      'var(--secondary)'],
      ['Commission',      $this->moneyShort($totals['commission']), 'chart',       'var(--tertiary)'],
      ['Creator payouts', $this->moneyShort($totals['payouts']),    'card',        '#b45309'],
  ] as [$label, $value, $icon, $toneColor]): ?>
    <div class="stat" style="--tone:<?= $toneColor ?>">
      <div class="stat__icon"><?= $this->icon($icon) ?></div>
      <div class="stat__value"><?= $this->e($value) ?></div>
      <div class="stat__label"><?= $this->e($label) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<div class="chip-row mb-5">
  <?php foreach ($tabs as $value => $label): ?>
    <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= (string) ($filters['status'] ?? '') === (string) $value ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?>
      <span class="chip-filter__count"><?= $this->number((int) ($counts[$value ?: 'all'] ?? 0)) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search by code, campaign, brand or creator…',
      'showDateRange'     => true,
      'showExport'        => true,
      'selects'           => [
          ['name' => 'payment_status', 'label' => 'Payment', 'options' => [
              '' => 'Any payment', 'unpaid' => 'Unpaid', 'paid' => 'Paid',
              'refunded' => 'Refunded', 'partially_refunded' => 'Partially refunded', 'failed' => 'Failed',
          ]],
          ['name' => 'platform', 'label' => 'Platform', 'options' => [
              '' => 'Any platform', 'instagram' => 'Instagram', 'youtube' => 'YouTube',
              'tiktok' => 'TikTok', 'facebook' => 'Facebook', 'twitter' => 'X', 'linkedin' => 'LinkedIn',
          ]],
      ],
  ]); ?>

  <?php if ($bookings === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('calendar', 'ic ic--lg') ?></div>
      <p class="empty__title">No bookings found</p>
      <p class="empty__text">Nothing matches these filters. Try a wider date range.</p>
      <a href="/admin/bookings" class="btn btn--outline"><?= $this->icon('refresh', 'ic ic--sm') ?> Reset filters</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Booking</th>
            <th>Brand</th>
            <th>Creator</th>
            <th>Deliverable</th>
            <th class="text-right">Total</th>
            <th class="text-right">Creator gets</th>
            <th>Status</th>
            <th>Payment</th>
            <th>
              <a href="<?= $this->queryUrl(['sort_by' => 'created_at', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'ASC' ? 'DESC' : 'ASC']) ?>">
                Created
              </a>
            </th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bookings as $booking): ?>
            <tr>
              <td>
                <a href="/admin/bookings/<?= (int) $booking['id'] ?>" class="mono small text-primary" style="font-weight:700">
                  #<?= $this->e($booking['code']) ?>
                </a>
                <div class="tiny muted truncate" style="max-width:200px"><?= $this->e($booking['title']) ?></div>
                <?php if ((int) $booking['open_disputes'] > 0): ?>
                  <span class="chip chip--error chip--pulse mt-1"><span class="chip__dot"></span>Disputed</span>
                <?php endif; ?>
              </td>

              <td class="small truncate" style="max-width:150px"><?= $this->e($booking['brand_name']) ?></td>

              <td class="small truncate" style="max-width:150px">
                <?= $this->e($booking['creator_name']) ?>
                <div class="tiny faint">@<?= $this->e($booking['creator_handle']) ?></div>
              </td>

              <td class="small muted">
                <?= (int) $booking['posts_count'] ?> × <?= $this->e(ucfirst((string) $booking['platform'])) ?>
              </td>

              <td class="text-right" style="font-weight:700;white-space:nowrap">
                <?= $this->moneyShort($booking['total_amount'], (string) $booking['currency']) ?>
              </td>

              <td class="text-right mono small muted" style="white-space:nowrap">
                <?= $this->moneyShort($booking['creator_earning'], (string) $booking['currency']) ?>
              </td>

              <td>
                <span class="chip chip--<?= $tone((string) $booking['status']) ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $booking['status']))) ?>
                </span>
              </td>

              <td>
                <span class="chip chip--<?= $booking['payment_status'] === 'paid' ? 'success' : ($booking['payment_status'] === 'unpaid' ? 'neutral' : 'warning') ?>">
                  <?= $this->e(ucfirst(str_replace('_', ' ', (string) $booking['payment_status']))) ?>
                </span>
                <?php if ($booking['escrow_status'] === 'held'): ?>
                  <div class="tiny faint mt-1">In escrow</div>
                <?php endif; ?>
              </td>

              <td class="small muted" style="white-space:nowrap"><?= $this->date($booking['created_at']) ?></td>

              <td class="actions">
                <a href="/admin/bookings/<?= (int) $booking['id'] ?>" class="btn btn--outline btn--sm">
                  <?= $this->icon('eye', 'ic ic--sm') ?> Open
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

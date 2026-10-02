<?php

declare(strict_types=1);

/**
 * Refunds list.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $refunds
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

?>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search refunds…',
      'selects'           => [
          ['name' => 'status', 'label' => 'Status', 'options' => [
              '' => 'Any status', 'pending' => 'Pending', 'processing' => 'Processing',
              'completed' => 'Completed', 'failed' => 'Failed',
          ]],
      ],
  ]); ?>

  <?php if ($refunds === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('refresh', 'ic ic--lg') ?></div>
      <p class="empty__title">No refunds</p>
      <p class="empty__text">Refunds issued from bookings or payments appear here.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Reference</th>
            <th>User</th>
            <th>Booking</th>
            <th>Payment</th>
            <th class="text-right">Amount</th>
            <th>Destination</th>
            <th>Reason</th>
            <th>Status</th>
            <th>Processed</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($refunds as $refund): ?>
            <tr>
              <td class="mono small" style="font-weight:700"><?= $this->e($refund['reference']) ?></td>

              <td class="small truncate" style="max-width:160px">
                <a href="/admin/users/<?= (int) $refund['user_id'] ?>"><?= $this->e($refund['user_name']) ?></a>
              </td>

              <td class="small">
                <?php if (!empty($refund['booking_code'])): ?>
                  <a href="/admin/bookings/<?= (int) $refund['booking_id'] ?>" class="mono text-primary">#<?= $this->e($refund['booking_code']) ?></a>
                <?php else: ?>
                  <span class="faint">—</span>
                <?php endif; ?>
              </td>

              <td class="mono tiny faint"><?= $this->e($refund['payment_reference']) ?></td>

              <td class="text-right text-error" style="font-weight:700;white-space:nowrap">
                − <?= $this->money($refund['amount'], (string) $refund['currency']) ?>
              </td>

              <td>
                <span class="chip chip--<?= $refund['destination'] === 'wallet' ? 'info' : 'neutral' ?>">
                  <?= $this->e($refund['destination'] === 'wallet' ? 'Wallet' : 'Original method') ?>
                </span>
              </td>

              <td class="small muted"><?= $this->e(ucfirst(str_replace('_', ' ', (string) $refund['reason']))) ?></td>

              <td>
                <span class="chip chip--<?= $refund['status'] === 'completed' ? 'success' : ($refund['status'] === 'failed' ? 'error' : 'warning') ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst((string) $refund['status'])) ?>
                </span>
                <?php if (!empty($refund['failure_reason'])): ?>
                  <div class="tiny text-error mt-1 clamp-2" style="max-width:180px"><?= $this->e($refund['failure_reason']) ?></div>
                <?php endif; ?>
              </td>

              <td class="small muted" style="white-space:nowrap"><?= $this->dateTime($refund['processed_at'] ?? $refund['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  <?php endif; ?>
</div>

<?php $this->stop(); ?>

<?php

declare(strict_types=1);

/**
 * Payments list.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $payments
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var array<string,mixed> $summary
 */

$this->extend('admin.layouts.app');
$this->start('content');

$tone = static fn (string $s): string => match ($s) {
    'captured'                      => 'success',
    'created', 'pending', 'authorized' => 'warning',
    'failed', 'cancelled'           => 'error',
    'refunded', 'partially_refunded' => 'info',
    default                         => 'neutral',
};

?>

<section class="grid grid--4 mb-6">
  <?php foreach ([
      ['Captured',  $this->moneyShort($summary['captured'] ?? 0),        'check-circle', 'var(--tertiary)'],
      ['Refunded',  $this->moneyShort($summary['refunded'] ?? 0),        'refresh',      'var(--error)'],
      ['Failed',    $this->number((int) ($summary['failed_count'] ?? 0)), 'alert',        'var(--warning)'],
      ['Total',     $this->number((int) ($summary['total_count'] ?? 0)),  'card',         'var(--primary)'],
  ] as [$label, $value, $icon, $toneColor]): ?>
    <div class="stat" style="--tone:<?= $toneColor ?>">
      <div class="stat__icon"><?= $this->icon($icon) ?></div>
      <div class="stat__value"><?= $this->e($value) ?></div>
      <div class="stat__label"><?= $this->e($label) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search by reference, gateway id, booking or user…',
      'showDateRange'     => true,
      'showExport'        => true,
      'selects'           => [
          ['name' => 'status', 'label' => 'Status', 'options' => [
              '' => 'Any status', 'captured' => 'Captured', 'created' => 'Created',
              'failed' => 'Failed', 'refunded' => 'Refunded', 'partially_refunded' => 'Partially refunded',
          ]],
          ['name' => 'gateway', 'label' => 'Gateway', 'options' => [
              '' => 'Any gateway', 'razorpay' => 'Razorpay', 'cashfree' => 'Cashfree', 'stripe' => 'Stripe', 'wallet' => 'Wallet',
          ]],
          ['name' => 'purpose', 'label' => 'Purpose', 'options' => [
              '' => 'Any purpose', 'booking' => 'Booking', 'wallet_topup' => 'Wallet top-up',
          ]],
      ],
  ]); ?>

  <?php if ($payments === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('card', 'ic ic--lg') ?></div>
      <p class="empty__title">No payments found</p>
      <p class="empty__text">Nothing matches these filters.</p>
      <a href="/admin/payments" class="btn btn--outline"><?= $this->icon('refresh', 'ic ic--sm') ?> Reset filters</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Reference</th>
            <th>User</th>
            <th>Booking</th>
            <th>Gateway</th>
            <th class="text-right">Amount</th>
            <th class="text-right">Refunded</th>
            <th>Status</th>
            <th>Paid</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $payment): ?>
            <?php
              // Refunds recorded or accepted by the gateway but not completed
              // yet are not in amount_refunded, and can no longer be refunded.
              $inFlight   = (float) ($payment['refund_in_flight'] ?? 0);
              $refundable = round(max(0.0, (float) $payment['amount'] - (float) $payment['amount_refunded'] - $inFlight), 2);
            ?>
            <tr>
              <td>
                <span class="mono small" style="font-weight:700"><?= $this->e($payment['reference']) ?></span>
                <?php if (!empty($payment['gateway_payment_id'])): ?>
                  <div class="tiny faint mono truncate" style="max-width:150px"><?= $this->e($payment['gateway_payment_id']) ?></div>
                <?php endif; ?>
              </td>

              <td class="small truncate" style="max-width:170px">
                <a href="/admin/users/<?= (int) $payment['user_id'] ?>"><?= $this->e($payment['user_name']) ?></a>
                <div class="tiny faint truncate"><?= $this->e($payment['user_email']) ?></div>
              </td>

              <td class="small">
                <?php if (!empty($payment['booking_code'])): ?>
                  <a href="/admin/bookings/<?= (int) $payment['booking_id'] ?>" class="mono text-primary">#<?= $this->e($payment['booking_code']) ?></a>
                  <div class="tiny faint truncate" style="max-width:150px"><?= $this->e($payment['booking_title']) ?></div>
                <?php else: ?>
                  <span class="chip chip--neutral">Wallet top-up</span>
                <?php endif; ?>
              </td>

              <td class="small muted">
                <?= $this->e(ucfirst((string) $payment['gateway'])) ?>
                <?php if (!empty($payment['method'])): ?>
                  <div class="tiny faint"><?= $this->e($payment['method']) ?><?= $payment['card_last4'] ? ' ••••' . $this->e($payment['card_last4']) : '' ?></div>
                <?php endif; ?>
              </td>

              <td class="text-right" style="font-weight:700;white-space:nowrap">
                <?= $this->money($payment['amount'], (string) $payment['currency']) ?>
              </td>

              <td class="text-right mono small <?= (float) $payment['amount_refunded'] > 0 ? 'text-error' : 'faint' ?>">
                <?= (float) $payment['amount_refunded'] > 0 ? $this->money($payment['amount_refunded'], (string) $payment['currency']) : '—' ?>
                <?php if ($inFlight > 0): ?>
                  <div class="tiny muted" style="white-space:nowrap"><?= $this->money($inFlight, (string) $payment['currency']) ?> in progress</div>
                <?php endif; ?>
              </td>

              <td>
                <span class="chip chip--<?= $tone((string) $payment['status']) ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $payment['status']))) ?>
                </span>
                <?php if (!empty($payment['failure_reason'])): ?>
                  <div class="tiny text-error mt-1 clamp-2" style="max-width:170px"><?= $this->e($payment['failure_reason']) ?></div>
                <?php endif; ?>
              </td>

              <td class="small muted" style="white-space:nowrap"><?= $this->date($payment['paid_at'] ?? $payment['created_at']) ?></td>

              <td class="actions">
                <?php if ($this->can('payments.refund')
                          && in_array($payment['status'], ['captured', 'partially_refunded'], true)
                          && $refundable > 0
                          && $payment['booking_id'] !== null): ?>
                  <button type="button" class="btn btn--outline btn--sm"
                          onclick="openRefund(<?= (int) $payment['id'] ?>, '<?= $this->e($payment['reference']) ?>', <?= $refundable ?>)">
                    <?= $this->icon('refresh', 'ic ic--sm') ?> Refund
                  </button>
                <?php elseif (!empty($payment['booking_id'])): ?>
                  <a href="/admin/bookings/<?= (int) $payment['booking_id'] ?>" class="btn btn--ghost btn--sm">Open</a>
                <?php else: ?>
                  <span class="tiny faint">—</span>
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

<!-- Refund dialog -->
<div id="refund-dialog" class="dialog-backdrop hidden">
  <div class="dialog">
    <form method="post" id="refund-form">
      <?= $this->csrf() ?>
      <div class="dialog__head">
        <div class="dialog__icon" style="--tone:var(--warning)"><?= $this->icon('refresh', 'ic ic--lg') ?></div>
        <h2 class="h2">Issue a refund</h2>
        <p class="muted small mt-1">Payment <span class="mono" id="refund-reference"></span></p>
      </div>
      <div class="dialog__body">
        <div class="field">
          <label class="field__label" for="refund-amount">Amount</label>
          <input class="input" type="number" step="0.01" min="1" name="amount" id="refund-amount" required>
          <p class="field__hint">Maximum refundable: <span id="refund-max"></span></p>
        </div>
        <div class="field">
          <label class="field__label" for="refund-destination">Destination</label>
          <select class="select" name="destination" id="refund-destination">
            <option value="source">Original payment method (5–7 days)</option>
            <option value="wallet">Zusty wallet (instant)</option>
          </select>
        </div>
        <div class="field">
          <label class="field__label" for="refund-reason">Reason</label>
          <input class="input" name="reason" id="refund-reason" required minlength="5" maxlength="500"
                 placeholder="Recorded in the audit trail">
        </div>
      </div>
      <div class="dialog__foot">
        <button type="button" class="btn btn--outline" onclick="closeRefund()">Cancel</button>
        <button type="submit" class="btn btn--danger">Issue refund</button>
      </div>
    </form>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
function openRefund(id, reference, maxAmount) {
  const dialog = document.getElementById('refund-dialog');
  document.getElementById('refund-form').action = '/admin/payments/' + id + '/refund';
  document.getElementById('refund-reference').textContent = reference;
  document.getElementById('refund-amount').value = maxAmount.toFixed(2);
  document.getElementById('refund-amount').max = maxAmount;
  document.getElementById('refund-max').textContent = maxAmount.toFixed(2);
  dialog.classList.remove('hidden');
  document.getElementById('refund-reason').focus();
}

function closeRefund() {
  document.getElementById('refund-dialog').classList.add('hidden');
}

document.getElementById('refund-dialog').addEventListener('click', function (e) {
  if (e.target === this) closeRefund();
});

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeRefund();
});
</script>
<?php $this->stop(); ?>

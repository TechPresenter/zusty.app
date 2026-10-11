<?php

declare(strict_types=1);

/**
 * Booking detail with the full audit trail and admin interventions.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $booking
 * @var list<array<string,mixed>> $timeline
 * @var list<array<string,mixed>> $offers
 * @var list<array<string,mixed>> $deliverables
 * @var list<array<string,mixed>> $payments
 * @var list<array<string,mixed>> $refunds
 * @var list<array<string,mixed>> $invoices
 * @var list<array<string,mixed>> $disputes
 * @var list<array<string,mixed>> $reviews
 * @var int $messagesCount
 */

$this->extend('admin.layouts.app');
$this->start('content');

$currency = (string) $booking['currency'];

$tone = static fn (string $s): string => match ($s) {
    'completed'                                                => 'success',
    'pending', 'countered', 'awaiting_payment', 'accepted'     => 'warning',
    'confirmed', 'in_progress', 'delivered'                    => 'info',
    'cancelled', 'rejected', 'expired', 'disputed', 'refunded' => 'error',
    default                                                    => 'neutral',
};

// Statuses an admin can still act on.
//
// `disputed` belongs here: BookingService::ALLOWED_TRANSITIONS permits
// disputed -> completed | refunded | cancelled, but this list used to omit it,
// so the entire Admin actions panel was hidden for exactly the bookings that
// most need intervention — money sitting in escrow with no way to release or
// refund it from the panel.
$isLive = in_array(
    $booking['status'],
    ['pending', 'countered', 'accepted', 'awaiting_payment', 'confirmed', 'in_progress', 'delivered', 'disputed'],
    true
);

// What is genuinely still refundable, read from the payments themselves.
//
// The refund form used to be hidden behind $isLive AND payment_status ===
// 'paid'. Both are the wrong test: the server refunds a completed booking
// perfectly well (and an operator regularly needs to), and the first partial
// refund flips payment_status to 'partially_refunded', which hid the form while
// money was still refundable. The balance is the only thing that decides.
//
// Net of refunds still on their way back: amount_refunded only moves when a
// refund completes, and a Cashfree refund sits in `processing` for days. The
// full amount used to stay pre-filled meanwhile, and a second refund to the
// wallet (instant) paid the same money back twice. PaymentService refuses it
// either way (refundableBalance); this keeps the form from offering it.
$inFlight = [];

foreach ($refunds as $refund) {
    if (in_array((string) $refund['status'], ['pending', 'processing'], true)) {
        $inFlight[(int) $refund['payment_id']] = ($inFlight[(int) $refund['payment_id']] ?? 0.0) + (float) $refund['amount'];
    }
}

$refundable = 0.0;

foreach ($payments as $payment) {
    if (in_array((string) $payment['status'], ['captured', 'partially_refunded'], true)) {
        $refundable += max(
            0.0,
            (float) $payment['amount'] - (float) $payment['amount_refunded'] - ($inFlight[(int) $payment['id']] ?? 0.0)
        );
    }
}

$refundable     = round(max(0.0, $refundable), 2);
$refundInFlight = round(array_sum($inFlight), 2);
$canRefund      = $refundable > 0 && $this->can('payments.refund');

?>

<a href="/admin/bookings" class="btn btn--ghost btn--sm mb-4">
  <?= $this->icon('chevron-left', 'ic ic--sm') ?> Back to bookings
</a>

<div class="grid grid--sidebar">

  <div class="col gap-4">

    <!-- Header -->
    <div class="card">
      <div class="card__body">
        <div class="row row--between row--wrap gap-3 mb-4">
          <div>
            <div class="row gap-2 row--wrap mb-1">
              <h2 class="h1 mono">#<?= $this->e($booking['code']) ?></h2>
              <span class="chip chip--<?= $tone((string) $booking['status']) ?>">
                <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $booking['status']))) ?>
              </span>
              <span class="chip chip--<?= $booking['payment_status'] === 'paid' ? 'success' : 'neutral' ?>">
                <?= $this->e(ucfirst(str_replace('_', ' ', (string) $booking['payment_status']))) ?>
              </span>
              <?php if ($booking['escrow_status'] === 'held'): ?>
                <span class="chip chip--info"><?= $this->icon('shield', 'ic ic--sm') ?> Payment held</span>
              <?php endif; ?>
            </div>
            <p class="muted"><?= $this->e($booking['title']) ?></p>
          </div>

          <div class="text-right">
            <div class="label">Total</div>
            <div class="h1 text-primary"><?= $this->money($booking['total_amount'], $currency) ?></div>
          </div>
        </div>

        <hr class="divider">

        <div class="grid grid--2">
          <!-- Brand -->
          <div class="row gap-3">
            <?php if (!empty($booking['brand_avatar'])): ?>
              <img class="avatar" src="<?= $this->e($booking['brand_avatar']) ?>" alt="">
            <?php else: ?>
              <div class="avatar avatar--fallback"><?= $this->e($this->initials((string) $booking['brand_name'])) ?></div>
            <?php endif; ?>
            <div class="flex-1 truncate">
              <div class="label mb-1">Brand</div>
              <a href="/admin/users/<?= (int) $booking['brand_user_id'] ?>" style="font-weight:600">
                <?= $this->e($booking['company_name'] ?: $booking['brand_name']) ?>
              </a>
              <div class="tiny muted truncate"><?= $this->e($booking['brand_email']) ?></div>
            </div>
          </div>

          <!-- Creator -->
          <div class="row gap-3">
            <?php if (!empty($booking['creator_avatar'])): ?>
              <img class="avatar" src="<?= $this->e($booking['creator_avatar']) ?>" alt="">
            <?php else: ?>
              <div class="avatar avatar--fallback"><?= $this->e($this->initials((string) $booking['creator_name'])) ?></div>
            <?php endif; ?>
            <div class="flex-1 truncate">
              <div class="label mb-1">Creator</div>
              <a href="/admin/creators/<?= (int) $booking['creator_id'] ?>" style="font-weight:600">
                <?= $this->e($booking['creator_name']) ?>
                <?php if (!empty($booking['creator_verified'])): ?>
                  <span class="text-secondary"><?= $this->icon('check-circle', 'ic ic--sm') ?></span>
                <?php endif; ?>
              </a>
              <div class="tiny muted truncate">@<?= $this->e($booking['creator_handle']) ?> · ★ <?= number_format((float) $booking['creator_rating'], 1) ?></div>
            </div>
          </div>
        </div>

        <hr class="divider">

        <div class="form-grid">
          <?php foreach ([
              'Campaign type'   => (string) $booking['campaign_type'],
              'Platform'        => ucfirst((string) $booking['platform']),
              'Deliverable'     => (int) $booking['posts_count'] . ' × ' . ucfirst((string) $booking['deliverable_type']),
              'Requested'       => $this->dateTime($booking['requested_at']),
              'Delivery due'    => $this->dateTime($booking['delivery_due_at']),
              'Completed'       => $booking['completed_at'] ? $this->dateTime($booking['completed_at']) : '—',
          ] as $label => $value): ?>
            <div class="mb-4">
              <div class="label mb-1"><?= $this->e($label) ?></div>
              <div class="small"><?= $this->e($value) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($booking['description'])): ?>
          <div class="label mb-2 mt-2">Brief</div>
          <p class="small" style="line-height:1.65"><?= nl2br($this->e($booking['description'])) ?></p>
        <?php endif; ?>

        <?php if (!empty($booking['cancellation_reason'])): ?>
          <div class="card mt-4" style="border-color:var(--error);background:var(--error-container)">
            <div class="card__body" style="padding:12px 16px">
              <div class="label mb-1" style="color:var(--on-error-container)">Cancellation reason</div>
              <p class="small" style="color:var(--on-error-container)"><?= $this->e($booking['cancellation_reason']) ?></p>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Timeline -->
    <div class="card">
      <div class="card__head"><h3 class="h3">Status timeline</h3></div>
      <div class="card__body">
        <?php if ($timeline === []): ?>
          <p class="muted small">No transitions recorded.</p>
        <?php else: ?>
          <div style="position:relative;padding-left:26px">
            <div style="position:absolute;left:7px;top:6px;bottom:6px;width:2px;background:var(--outline-variant)"></div>
            <?php foreach ($timeline as $entry): ?>
              <div style="position:relative;padding-bottom:20px">
                <div style="position:absolute;left:-24px;top:3px;width:16px;height:16px;border-radius:50%;
                            background:var(--surface-lowest);border:3px solid var(--primary)"></div>
                <div class="row row--between gap-2">
                  <div>
                    <span class="small" style="font-weight:600">
                      <?= $this->e(ucfirst(str_replace('_', ' ', (string) $entry['to_status']))) ?>
                    </span>
                    <span class="tiny faint">
                      by <?= $this->e($entry['changed_by_type']) ?>
                    </span>
                  </div>
                  <span class="tiny muted" style="white-space:nowrap"><?= $this->dateTime($entry['created_at']) ?></span>
                </div>
                <?php if (!empty($entry['note'])): ?>
                  <p class="tiny muted mt-1"><?= $this->e($entry['note']) ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Offers -->
    <?php if ($offers !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Negotiation</h3></div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>From</th><th class="text-right">Amount</th><th class="text-right">Posts</th><th>Message</th><th>Status</th><th>When</th></tr></thead>
          <tbody>
            <?php foreach ($offers as $offer): ?>
              <tr>
                <td class="small" style="font-weight:600"><?= $this->e(ucfirst((string) $offer['offered_by'])) ?></td>
                <td class="text-right" style="font-weight:700"><?= $this->moneyShort($offer['amount'], $currency) ?></td>
                <td class="text-right mono"><?= (int) $offer['posts_count'] ?></td>
                <td class="small muted truncate" style="max-width:260px"><?= $this->e($offer['message'] ?: '—') ?></td>
                <td><span class="chip chip--<?= $offer['status'] === 'accepted' ? 'success' : ($offer['status'] === 'pending' ? 'warning' : 'neutral') ?>"><?= $this->e($offer['status']) ?></span></td>
                <td class="small muted" style="white-space:nowrap"><?= $this->date($offer['created_at'], 'd M, g:i A') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Deliverables -->
    <?php if ($deliverables !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Deliverables</h3></div>
      <div class="card__body col gap-4">
        <?php foreach ($deliverables as $deliverable): ?>
          <div class="row gap-3 row--wrap">
            <?php if (!empty($deliverable['thumbnail_url'])): ?>
              <img src="<?= $this->e($deliverable['thumbnail_url']) ?>" alt="" loading="lazy"
                   style="width:80px;height:80px;object-fit:cover;border-radius:var(--r-md)">
            <?php endif; ?>
            <div class="flex-1">
              <div class="row row--between gap-2 mb-1">
                <span class="small" style="font-weight:600"><?= $this->e($deliverable['title']) ?></span>
                <span class="chip chip--<?= $deliverable['status'] === 'approved' ? 'success' : ($deliverable['status'] === 'submitted' ? 'info' : 'neutral') ?>">
                  <?= $this->e(ucfirst(str_replace('_', ' ', (string) $deliverable['status']))) ?>
                </span>
              </div>
              <?php if (!empty($deliverable['content_url'])): ?>
                <a href="<?= $this->e($deliverable['content_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"
                   class="tiny text-secondary"><?= $this->icon('external', 'ic ic--sm') ?> View live post</a>
              <?php endif; ?>
              <?php if ($deliverable['metrics'] !== []): ?>
                <div class="row gap-4 mt-2">
                  <?php foreach ($deliverable['metrics'] as $metric => $value): ?>
                    <div>
                      <div class="tiny faint"><?= $this->e(ucfirst((string) $metric)) ?></div>
                      <div class="small mono" style="font-weight:600"><?= $this->compact((float) $value) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Payments & refunds -->
    <?php if ($payments !== [] || $refunds !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Money trail</h3></div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Type</th><th>Reference</th><th>Gateway</th><th class="text-right">Amount</th><th>Status</th><th>When</th></tr></thead>
          <tbody>
            <?php foreach ($payments as $payment): ?>
              <tr>
                <td><span class="chip chip--info">Payment</span></td>
                <td class="mono tiny"><?= $this->e($payment['reference']) ?></td>
                <td class="small muted"><?= $this->e(ucfirst((string) $payment['gateway'])) ?><?= $payment['method'] ? ' · ' . $this->e($payment['method']) : '' ?></td>
                <td class="text-right" style="font-weight:600"><?= $this->money($payment['amount'], (string) $payment['currency']) ?></td>
                <td><span class="chip chip--<?= $payment['status'] === 'captured' ? 'success' : ($payment['status'] === 'failed' ? 'error' : 'warning') ?>"><?= $this->e($payment['status']) ?></span></td>
                <td class="small muted" style="white-space:nowrap"><?= $this->dateTime($payment['paid_at'] ?? $payment['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php foreach ($refunds as $refund): ?>
              <tr>
                <td><span class="chip chip--warning">Refund</span></td>
                <td class="mono tiny"><?= $this->e($refund['reference']) ?></td>
                <td class="small muted">to <?= $this->e($refund['destination']) ?></td>
                <td class="text-right text-error" style="font-weight:600">− <?= $this->money($refund['amount'], (string) $refund['currency']) ?></td>
                <td><span class="chip chip--<?= match ((string) $refund['status']) { 'completed' => 'success', 'failed' => 'error', default => 'warning' } ?>"><?= $this->e($refund['status']) ?></span></td>
                <td class="small muted" style="white-space:nowrap"><?= $this->dateTime($refund['processed_at'] ?? $refund['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Disputes -->
    <?php if ($disputes !== []): ?>
    <div class="card" style="border-color:var(--error)">
      <div class="card__head"><h3 class="h3 text-error">Disputes</h3></div>
      <div class="card__body col gap-4">
        <?php foreach ($disputes as $dispute): ?>
          <div>
            <div class="row row--between gap-2 mb-2">
              <span class="small" style="font-weight:600">
                Raised by <?= $this->e($dispute['raised_by']) ?> ·
                <?= $this->e(ucfirst(str_replace('_', ' ', (string) $dispute['reason']))) ?>
              </span>
              <span class="chip chip--<?= str_starts_with((string) $dispute['status'], 'resolved') ? 'success' : 'error' ?>">
                <?= $this->e(ucfirst(str_replace('_', ' ', (string) $dispute['status']))) ?>
              </span>
            </div>
            <p class="small muted"><?= $this->e($dispute['description']) ?></p>
            <?php if (!empty($dispute['resolution_note'])): ?>
              <p class="tiny mt-2" style="padding:10px;background:var(--surface-low);border-radius:var(--r-md)">
                <strong>Resolution</strong> (<?= $this->e($dispute['handler_name'] ?? 'admin') ?>):
                <?= $this->e($dispute['resolution_note']) ?>
              </p>
            <?php endif; ?>
            <?php if (!str_starts_with((string) $dispute['status'], 'resolved') && $this->can('bookings.dispute')): ?>
              <a href="/admin/disputes?status=open" class="btn btn--danger btn--sm mt-3">Resolve this dispute</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ================= Sidebar ================= -->
  <div class="col gap-4">

    <!-- Money breakdown -->
    <div class="card">
      <div class="card__head"><h3 class="h3">Breakdown</h3></div>
      <div class="card__body col gap-3">
        <?php foreach ([
            ['Creator fee',   (float) $booking['amount'],            false],
            ['Platform fee',  (float) $booking['platform_fee'],      false],
            ['Tax on fee',    (float) $booking['tax_amount'],        false],
        ] as [$label, $value, $negative]): ?>
          <div class="row row--between">
            <span class="small muted"><?= $this->e($label) ?></span>
            <span class="small mono"><?= $this->money($value, $currency) ?></span>
          </div>
        <?php endforeach; ?>

        <hr class="divider" style="margin:6px 0">

        <div class="row row--between">
          <span class="small" style="font-weight:700">Brand pays</span>
          <span class="h3 text-primary"><?= $this->money($booking['total_amount'], $currency) ?></span>
        </div>

        <hr class="divider" style="margin:6px 0">

        <div class="row row--between">
          <span class="small muted">Commission</span>
          <span class="small mono text-error">− <?= $this->money($booking['commission_amount'], $currency) ?></span>
        </div>
        <div class="row row--between">
          <span class="small" style="font-weight:700">Creator receives</span>
          <span class="small mono" style="font-weight:700"><?= $this->money($booking['creator_earning'], $currency) ?></span>
        </div>
      </div>
    </div>

    <!-- Invoices -->
    <?php if ($invoices !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Invoices</h3></div>
      <div class="card__body col gap-3">
        <?php foreach ($invoices as $invoice): ?>
          <div class="row row--between gap-2">
            <div class="flex-1 truncate">
              <div class="small mono truncate"><?= $this->e($invoice['invoice_number']) ?></div>
              <div class="tiny muted"><?= $this->e($invoice['type'] === 'brand_invoice' ? 'Brand invoice' : 'Creator payout') ?></div>
            </div>
            <span class="small mono" style="font-weight:600"><?= $this->moneyShort($invoice['total'], (string) $invoice['currency']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Reviews -->
    <?php if ($reviews !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Reviews</h3></div>
      <div class="card__body col gap-3">
        <?php foreach ($reviews as $review): ?>
          <div>
            <div class="row row--between mb-1">
              <span class="small" style="font-weight:600"><?= $this->e($review['reviewer_name']) ?></span>
              <span class="small text-primary" style="font-weight:700">★ <?= (int) $review['rating'] ?></span>
            </div>
            <?php if (!empty($review['comment'])): ?>
              <p class="tiny muted"><?= $this->e($review['comment']) ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__body">
        <div class="row row--between">
          <span class="small muted">Messages exchanged</span>
          <span class="small mono" style="font-weight:600"><?= $this->number($messagesCount) ?></span>
        </div>
      </div>
    </div>

    <!-- Admin interventions -->
    <?php if (($isLive && ($this->can('bookings.cancel') || $this->can('bookings.edit'))) || $canRefund): ?>
    <div class="card" style="border-color:var(--warning)">
      <div class="card__head"><h3 class="h3" style="color:var(--warning)">Admin actions</h3></div>
      <div class="card__body col gap-3">
        <p class="tiny muted">
          These override the normal flow. Every action is recorded against your account.
        </p>

        <?php if ($this->can('bookings.edit') && in_array($booking['status'], ['delivered', 'in_progress', 'confirmed', 'disputed'], true)): ?>
          <form method="post" action="/admin/bookings/<?= (int) $booking['id'] ?>/complete">
            <?= $this->csrf() ?>
            <button type="submit" class="btn btn--success btn--block"
                    data-confirm="The held payment will be released to the creator immediately."
                    data-confirm-title="Force-complete this booking?" data-confirm-label="Complete" data-confirm-tone="info">
              <?= $this->icon('check', 'ic ic--sm') ?> Complete &amp; release payment
            </button>
          </form>
        <?php endif; ?>

        <?php if ($isLive && $this->can('bookings.cancel')): ?>
          <button type="button" class="btn btn--danger btn--block" onclick="cancelBooking()">
            <?= $this->icon('x', 'ic ic--sm') ?> Force-cancel booking
          </button>
        <?php endif; ?>

        <?php if ($canRefund): ?>
          <hr class="divider" style="margin:6px 0">
          <form method="post" action="/admin/bookings/<?= (int) $booking['id'] ?>/refund">
            <?= $this->csrf() ?>
            <div class="field">
              <label class="field__label" for="refund_amount">Refund amount</label>
              <input class="input" type="number" step="0.01" min="1" max="<?= $this->e((string) $refundable) ?>"
                     name="amount" id="refund_amount" value="<?= $this->e((string) $refundable) ?>" required>
              <p class="tiny muted"><?= $this->money($refundable, $currency) ?> of
                <?= $this->money($booking['total_amount'], $currency) ?> is still refundable.<?php if ($refundInFlight > 0): ?>
                <?= $this->money($refundInFlight, $currency) ?> more is already on its way back and waits for the gateway to confirm it.<?php endif; ?></p>
            </div>
            <div class="field">
              <label class="field__label" for="refund_destination">Destination</label>
              <select class="select" name="destination" id="refund_destination">
                <option value="source">Original payment method</option>
                <option value="wallet">Zusty wallet (instant)</option>
              </select>
              <p class="tiny muted">A brand can withdraw its wallet only from
                <?= $this->money(\Zuste\Core\Config::settingFloat('wallet', 'min_withdrawal', 1000), $currency) ?>; less than that can only pay for a booking it covers, or be paid out by support.</p>
            </div>
            <div class="field">
              <label class="field__label" for="refund_reason">Reason</label>
              <input class="input" name="reason" id="refund_reason" required minlength="5" placeholder="Why is this being refunded?">
            </div>
            <button type="submit" class="btn btn--outline btn--block"
                    data-confirm="This sends money back to the brand and cannot be undone."
                    data-confirm-title="Issue refund?" data-confirm-label="Refund">
              <?= $this->icon('refresh', 'ic ic--sm') ?> Issue refund
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<form id="cancel-form" method="post" action="/admin/bookings/<?= (int) $booking['id'] ?>/cancel" style="display:none">
  <?= $this->csrf() ?>
  <input type="hidden" name="reason" id="cancel-reason">
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
function cancelBooking() {
  const reason = window.prompt('Why is this booking being cancelled?\nBoth parties will see this message.');
  if (reason === null) return;

  if (reason.trim().length < 5) {
    Admin.toast('Give a clear reason — both parties will see it.', 'warning');
    return;
  }

  document.getElementById('cancel-reason').value = reason.trim();
  document.getElementById('cancel-form').submit();
}
</script>
<?php $this->stop(); ?>

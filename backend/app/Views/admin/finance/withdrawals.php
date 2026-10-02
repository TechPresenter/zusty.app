<?php

declare(strict_types=1);

/**
 * Payout queue.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $withdrawals
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var float $pendingTotal
 * @var array<string,int> $counts
 */

$this->extend('admin.layouts.app');
$this->start('content');

$tone = static fn (string $s): string => match ($s) {
    'completed'                => 'success',
    'pending', 'approved', 'processing' => 'warning',
    'rejected', 'failed', 'cancelled'   => 'error',
    default                    => 'neutral',
};

$canManage = $this->can('wallet.withdrawals');

?>

<section class="grid grid--3 mb-6">
  <div class="stat" style="--tone:var(--warning)">
    <div class="stat__icon"><?= $this->icon('wallet') ?></div>
    <div class="stat__value"><?= $this->moneyShort($pendingTotal) ?></div>
    <div class="stat__label">Awaiting approval</div>
  </div>
  <div class="stat" style="--tone:var(--secondary)">
    <div class="stat__icon"><?= $this->icon('inbox') ?></div>
    <div class="stat__value"><?= $this->number((int) ($counts['pending'] ?? 0)) ?></div>
    <div class="stat__label">Pending requests</div>
  </div>
  <div class="stat" style="--tone:var(--tertiary)">
    <div class="stat__icon"><?= $this->icon('check-circle') ?></div>
    <div class="stat__value"><?= $this->number((int) ($counts['completed'] ?? 0)) ?></div>
    <div class="stat__label">Settled all-time</div>
  </div>
</section>

<div class="chip-row mb-5">
  <?php foreach ([
      'pending' => 'Pending', 'approved' => 'Approved', 'processing' => 'Processing',
      'completed' => 'Completed', 'rejected' => 'Rejected', 'all' => 'All',
  ] as $value => $label): ?>
    <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= (string) ($filters['status'] ?? 'pending') === $value ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?>
      <span class="chip-filter__count"><?= $this->number((int) ($counts[$value] ?? 0)) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search by reference, name or email…',
      'showExport'        => true,
  ]); ?>

  <?php if ($withdrawals === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('wallet', 'ic ic--lg') ?></div>
      <p class="empty__title">Nothing to settle</p>
      <p class="empty__text">There are no payout requests matching this filter.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Reference</th>
            <th>Creator</th>
            <th>Destination</th>
            <th class="text-right">Amount</th>
            <th class="text-right">Net</th>
            <th>KYC</th>
            <th>Status</th>
            <th>Requested</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($withdrawals as $withdrawal): ?>
            <?php $snapshot = $withdrawal['snapshot']; ?>
            <tr>
              <td class="mono small" style="font-weight:700"><?= $this->e($withdrawal['reference']) ?></td>

              <td>
                <div class="cell-user">
                  <?php if (!empty($withdrawal['avatar_url'])): ?>
                    <img class="avatar avatar--sm" src="<?= $this->e($withdrawal['avatar_url']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="avatar avatar--sm avatar--fallback"><?= $this->e($this->initials((string) $withdrawal['user_name'])) ?></div>
                  <?php endif; ?>
                  <div class="flex-1 truncate">
                    <a href="/admin/users/<?= (int) $withdrawal['user_id'] ?>" class="cell-user__name truncate">
                      <?= $this->e($withdrawal['user_name']) ?>
                    </a>
                    <div class="cell-user__meta truncate"><?= $this->e($withdrawal['email']) ?></div>
                  </div>
                </div>
              </td>

              <td class="small">
                <?php if (($snapshot['method'] ?? '') === 'upi'): ?>
                  <span class="mono"><?= $this->e($snapshot['upi_id'] ?? '—') ?></span>
                  <div class="tiny faint">UPI</div>
                <?php else: ?>
                  <span><?= $this->e($snapshot['bank_name'] ?? '—') ?></span>
                  <div class="tiny faint mono">•••• <?= $this->e($snapshot['account_last4'] ?? '····') ?>
                    <?= !empty($snapshot['ifsc']) ? ' · ' . $this->e($snapshot['ifsc']) : '' ?></div>
                <?php endif; ?>
              </td>

              <td class="text-right" style="font-weight:700"><?= $this->money($withdrawal['amount'], (string) $withdrawal['currency']) ?></td>
              <td class="text-right mono small"><?= $this->money($withdrawal['net_amount'], (string) $withdrawal['currency']) ?></td>

              <td>
                <span class="chip chip--<?= $withdrawal['kyc_status'] === 'verified' ? 'success' : 'error' ?>">
                  <?= $this->e($withdrawal['kyc_status'] === 'verified' ? 'Verified' : ucfirst((string) $withdrawal['kyc_status'])) ?>
                </span>
              </td>

              <td>
                <span class="chip chip--<?= $tone((string) $withdrawal['status']) ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst((string) $withdrawal['status'])) ?>
                </span>
                <?php if (!empty($withdrawal['payout_reference'])): ?>
                  <div class="tiny faint mono mt-1"><?= $this->e($withdrawal['payout_reference']) ?></div>
                <?php endif; ?>
                <?php if (!empty($withdrawal['rejection_reason'])): ?>
                  <div class="tiny text-error mt-1 clamp-2" style="max-width:180px"><?= $this->e($withdrawal['rejection_reason']) ?></div>
                <?php endif; ?>
              </td>

              <td class="small muted" style="white-space:nowrap">
                <?= $this->date($withdrawal['created_at']) ?>
                <?php if (!empty($withdrawal['processor_name'])): ?>
                  <div class="tiny faint">by <?= $this->e($withdrawal['processor_name']) ?></div>
                <?php endif; ?>
              </td>

              <td class="actions">
                <?php if ($canManage && $withdrawal['status'] === 'pending'): ?>
                  <div class="row gap-2 row--end">
                    <form method="post" action="/admin/withdrawals/<?= (int) $withdrawal['id'] ?>/approve" style="display:inline">
                      <?= $this->csrf() ?>
                      <button type="submit" class="btn btn--outline btn--sm"
                              data-confirm="Approve this payout so it can be settled with the bank."
                              data-confirm-title="Approve payout?" data-confirm-label="Approve" data-confirm-tone="info">
                        Approve
                      </button>
                    </form>
                    <button type="button" class="btn btn--ghost btn--sm text-error"
                            onclick="rejectPayout(<?= (int) $withdrawal['id'] ?>)">Reject</button>
                  </div>
                <?php elseif ($canManage && in_array($withdrawal['status'], ['approved', 'processing'], true)): ?>
                  <button type="button" class="btn btn--primary btn--sm"
                          onclick="settlePayout(<?= (int) $withdrawal['id'] ?>)">Mark settled</button>
                <?php else: ?>
                  <span class="tiny faint"><?= $this->date($withdrawal['processed_at']) ?></span>
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

<form id="payout-form" method="post" style="display:none">
  <?= $this->csrf() ?>
  <input type="hidden" name="reason" id="payout-reason">
  <input type="hidden" name="payout_reference" id="payout-reference">
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
function rejectPayout(id) {
  const reason = window.prompt('Why is this payout being rejected?\nThe amount returns to the creator’s wallet and they will see this message.');
  if (reason === null) return;

  if (reason.trim().length < 5) {
    Admin.toast('Give a clear reason — the creator will see it.', 'warning');
    return;
  }

  const form = document.getElementById('payout-form');
  form.action = '/admin/withdrawals/' + id + '/reject';
  document.getElementById('payout-reason').value = reason.trim();
  form.submit();
}

function settlePayout(id) {
  const reference = window.prompt('Enter the bank UTR or gateway payout reference:');
  if (reference === null) return;

  if (reference.trim().length < 3) {
    Admin.toast('Enter the payout reference so the transfer is traceable.', 'warning');
    return;
  }

  const form = document.getElementById('payout-form');
  form.action = '/admin/withdrawals/' + id + '/complete';
  document.getElementById('payout-reference').value = reference.trim();
  form.submit();
}
</script>
<?php $this->stop(); ?>

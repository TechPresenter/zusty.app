<?php

declare(strict_types=1);

/**
 * Wallet overview.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $wallets
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var array<string,mixed> $totals
 */

$this->extend('admin.layouts.app');
$this->start('content');

?>

<section class="grid grid--4 mb-6">
  <?php foreach ([
      ['Available balance', $this->moneyShort($totals['available'] ?? 0),       'wallet',      'var(--tertiary)'],
      ['Locked (on hold)',  $this->moneyShort($totals['locked'] ?? 0),          'shield',      'var(--warning)'],
      ['Lifetime credited', $this->moneyShort($totals['lifetime_credit'] ?? 0), 'arrow-down',  'var(--secondary)'],
      ['Lifetime debited',  $this->moneyShort($totals['lifetime_debit'] ?? 0),  'arrow-up',    'var(--primary)'],
  ] as [$label, $value, $icon, $tone]): ?>
    <div class="stat" style="--tone:<?= $tone ?>">
      <div class="stat__icon"><?= $this->icon($icon) ?></div>
      <div class="stat__value"><?= $this->e($value) ?></div>
      <div class="stat__label"><?= $this->e($label) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<div class="card mb-5" style="border-color:var(--secondary);background:color-mix(in srgb, var(--secondary) 6%, transparent)">
  <div class="card__body row gap-3" style="padding:14px 18px">
    <span class="text-secondary"><?= $this->icon('info') ?></span>
    <p class="small">
      <strong>Available</strong> is spendable and withdrawable. <strong>Locked</strong> is a creator's earnings
      still in the hold period after a completed booking, or money reserved against a payout already in
      flight. A booking's own payment is held on the booking, not in a wallet. Every figure here is derived
      from the immutable ledger — use <em>Reconcile</em> on a user to verify it.
    </p>
  </div>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search by name or email…',
      'showExport'        => false,
      'selects'           => [
          ['name' => 'with_balance', 'label' => 'Balance', 'options' => ['' => 'All wallets', '1' => 'With a balance']],
          ['name' => 'frozen_only', 'label' => 'Frozen', 'options' => ['' => 'All', '1' => 'Frozen only']],
      ],
  ]); ?>

  <?php if ($wallets === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('wallet', 'ic ic--lg') ?></div>
      <p class="empty__title">No wallets found</p>
      <p class="empty__text">Nothing matches these filters.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>User</th>
            <th>Type</th>
            <th>KYC</th>
            <th class="text-right">Available</th>
            <th class="text-right">Locked</th>
            <th class="text-right">Total</th>
            <th>Last activity</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($wallets as $wallet): ?>
            <tr>
              <td>
                <div class="cell-user">
                  <?php if (!empty($wallet['avatar_url'])): ?>
                    <img class="avatar avatar--sm" src="<?= $this->e($wallet['avatar_url']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="avatar avatar--sm avatar--fallback"><?= $this->e($this->initials((string) $wallet['user_name'])) ?></div>
                  <?php endif; ?>
                  <div class="flex-1 truncate">
                    <a href="/admin/users/<?= (int) $wallet['user_id'] ?>" class="cell-user__name truncate">
                      <?= $this->e($wallet['user_name']) ?>
                    </a>
                    <div class="cell-user__meta truncate"><?= $this->e($wallet['email']) ?></div>
                  </div>
                  <?php if (!empty($wallet['is_frozen'])): ?>
                    <span class="chip chip--error" title="<?= $this->e($wallet['frozen_reason'] ?? '') ?>">Frozen</span>
                  <?php endif; ?>
                </div>
              </td>

              <td><span class="chip chip--<?= $wallet['account_type'] === 'creator' ? 'info' : 'primary' ?>"><?= $this->e(ucfirst((string) $wallet['account_type'])) ?></span></td>

              <td>
                <span class="chip chip--<?= $wallet['kyc_status'] === 'verified' ? 'success' : 'neutral' ?>">
                  <?= $this->e($wallet['kyc_status'] === 'none' ? 'None' : ucfirst((string) $wallet['kyc_status'])) ?>
                </span>
              </td>

              <td class="text-right" style="font-weight:700"><?= $this->moneyShort($wallet['balance'], (string) $wallet['currency']) ?></td>
              <td class="text-right mono small muted"><?= $this->moneyShort($wallet['locked_balance'], (string) $wallet['currency']) ?></td>
              <td class="text-right mono small">
                <?= $this->moneyShort((float) $wallet['balance'] + (float) $wallet['locked_balance'], (string) $wallet['currency']) ?>
              </td>

              <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($wallet['last_activity_at']) ?></td>

              <td class="actions">
                <div class="row gap-2 row--end">
                  <a href="/admin/wallets/<?= (int) $wallet['user_id'] ?>/reconcile" class="btn btn--ghost btn--sm"
                     title="Recompute the balance from the ledger">
                    <?= $this->icon('refresh', 'ic ic--sm') ?> Reconcile
                  </a>

                  <?php if ($this->can('wallet.adjust')): ?>
                    <?php if (empty($wallet['is_frozen'])): ?>
                      <button type="button" class="btn btn--ghost btn--sm text-error"
                              onclick="freezeWallet(<?= (int) $wallet['user_id'] ?>, true)">Freeze</button>
                    <?php else: ?>
                      <form method="post" action="/admin/wallets/<?= (int) $wallet['user_id'] ?>/freeze" style="display:inline">
                        <?= $this->csrf() ?>
                        <input type="hidden" name="is_frozen" value="0">
                        <button type="submit" class="btn btn--ghost btn--sm text-success">Unfreeze</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  <?php endif; ?>
</div>

<form id="freeze-form" method="post" style="display:none">
  <?= $this->csrf() ?>
  <input type="hidden" name="is_frozen" value="1">
  <input type="hidden" name="reason" id="freeze-reason">
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
function freezeWallet(userId) {
  const reason = window.prompt('Why is this wallet being frozen?\nThe user will be blocked from spending or withdrawing.');
  if (reason === null) return;

  if (reason.trim().length < 5) {
    Admin.toast('Give a clear reason — it is recorded in the audit trail.', 'warning');
    return;
  }

  const form = document.getElementById('freeze-form');
  form.action = '/admin/wallets/' + userId + '/freeze';
  document.getElementById('freeze-reason').value = reason.trim();
  form.submit();
}
</script>
<?php $this->stop(); ?>

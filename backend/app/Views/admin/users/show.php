<?php

declare(strict_types=1);

/**
 * User detail.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $user
 * @var array<string,mixed>|null $erasure  The deletion record, once the account has been erased
 * @var array<string,mixed> $wallet
 * @var list<array<string,mixed>> $bookings
 * @var list<array<string,mixed>> $transactions
 * @var list<array<string,mixed>> $kyc
 * @var list<array<string,mixed>> $devices
 * @var list<array<string,mixed>> $activity
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (string $s): string => match ($s) {
    'active' => 'success', 'pending', 'suspended' => 'warning', 'banned' => 'error', default => 'neutral',
};

$erasure  = $erasure ?? null;
$isErased = is_array($erasure);

?>

<a href="/admin/users" class="btn btn--ghost btn--sm mb-4">
  <?= $this->icon('chevron-left', 'ic ic--sm') ?> Back to users
</a>

<div class="grid grid--sidebar">

  <!-- ============ Left column ============ -->
  <div class="col gap-4">

    <!-- Identity -->
    <div class="card">
      <div class="card__body">
        <div class="row gap-4 row--wrap">
          <?php if (!empty($user['avatar_url'])): ?>
            <img class="avatar avatar--lg" src="<?= $this->e($user['avatar_url']) ?>" alt="">
          <?php else: ?>
            <div class="avatar avatar--lg avatar--fallback" style="font-size:18px">
              <?= $this->e($this->initials((string) $user['name'])) ?>
            </div>
          <?php endif; ?>

          <div class="flex-1">
            <div class="row gap-2 row--wrap mb-1">
              <h2 class="h1"><?= $this->e($user['name']) ?></h2>
              <span class="chip chip--<?= $statusTone((string) $user['status']) ?>">
                <span class="chip__dot"></span><?= $this->e(ucfirst((string) $user['status'])) ?>
              </span>
              <span class="chip chip--<?= $user['account_type'] === 'creator' ? 'info' : 'primary' ?>">
                <?= $this->e(ucfirst((string) $user['account_type'])) ?>
              </span>
            </div>
            <p class="muted small">
              <?= $this->e($user['company_name'] ?: '—') ?> ·
              Joined <?= $this->date($user['created_at']) ?> ·
              UUID <span class="mono tiny"><?= $this->e($user['uuid']) ?></span>
            </p>
          </div>

          <?php if (!empty($user['creator_id'])): ?>
            <a href="/admin/creators/<?= (int) $user['creator_id'] ?>" class="btn btn--outline btn--sm">
              <?= $this->icon('star', 'ic ic--sm') ?> Creator profile
            </a>
          <?php endif; ?>
        </div>

        <hr class="divider">

        <div class="form-grid">
          <?php foreach ([
              'Email'        => $user['email'] ?: '—',
              'Phone'        => $user['phone'] ? (string) $user['phone_country_code'] . ' ' . (string) $user['phone'] : '—',
              'City'         => $user['city'] ?: '—',
              'Country'      => $user['country'] ?: '—',
              'GST number'   => $user['gst_number'] ?: '—',
              'Website'      => $user['website'] ?: '—',
              'Referral code' => $user['referral_code'],
              'Referred by'  => $user['referrer_name'] ?: '—',
              'Last login'   => $user['last_login_at'] ? $this->dateTime($user['last_login_at']) : 'Never',
              'Last seen'    => $user['last_seen_at'] ? $this->timeAgo($user['last_seen_at']) : '—',
          ] as $label => $value): ?>
            <div class="mb-4">
              <div class="label mb-1"><?= $this->e($label) ?></div>
              <div class="small"><?= $this->e($value) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="row gap-2 row--wrap mt-2">
          <span class="chip chip--<?= $user['email_verified_at'] ? 'success' : 'neutral' ?>">
            <?= $this->icon($user['email_verified_at'] ? 'check' : 'x', 'ic ic--sm') ?> Email verified
          </span>
          <span class="chip chip--<?= $user['phone_verified_at'] ? 'success' : 'neutral' ?>">
            <?= $this->icon($user['phone_verified_at'] ? 'check' : 'x', 'ic ic--sm') ?> Phone verified
          </span>
          <span class="chip chip--<?= $user['kyc_status'] === 'verified' ? 'success' : ($user['kyc_status'] === 'pending' ? 'warning' : 'neutral') ?>">
            KYC: <?= $this->e($user['kyc_status'] === 'none' ? 'not started' : (string) $user['kyc_status']) ?>
          </span>
        </div>
      </div>
    </div>

    <!-- Bookings -->
    <div class="card">
      <div class="card__head"><h3 class="h3">Recent bookings</h3></div>
      <?php if ($bookings === []): ?>
        <div class="empty" style="padding:32px">
          <p class="empty__text mb-0">No bookings yet.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Code</th><th>Campaign</th><th>Amount</th><th>Status</th><th>Created</th></tr></thead>
            <tbody>
              <?php foreach ($bookings as $booking): ?>
                <tr>
                  <td class="mono small"><a class="text-primary" href="/admin/bookings/<?= (int) $booking['id'] ?>">#<?= $this->e($booking['code']) ?></a></td>
                  <td class="small truncate" style="max-width:220px"><?= $this->e($booking['title']) ?></td>
                  <td class="small" style="font-weight:600"><?= $this->moneyShort($booking['total_amount'], (string) $booking['currency']) ?></td>
                  <td><span class="chip chip--neutral"><?= $this->e(ucfirst(str_replace('_', ' ', (string) $booking['status']))) ?></span></td>
                  <td class="small muted"><?= $this->date($booking['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Wallet ledger -->
    <div class="card">
      <div class="card__head"><h3 class="h3">Wallet ledger</h3></div>
      <?php if ($transactions === []): ?>
        <div class="empty" style="padding:32px"><p class="empty__text mb-0">No transactions yet.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Reference</th><th>Description</th><th>Category</th><th class="text-right">Amount</th><th class="text-right">Balance</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach ($transactions as $transaction): ?>
                <tr>
                  <td class="mono tiny faint"><?= $this->e($transaction['reference']) ?></td>
                  <td class="small truncate" style="max-width:220px"><?= $this->e($transaction['description']) ?></td>
                  <td class="tiny muted"><?= $this->e(str_replace('_', ' ', (string) $transaction['category'])) ?></td>
                  <td class="text-right mono <?= $transaction['direction'] === 'credit' ? 'text-success' : '' ?>" style="font-weight:600">
                    <?= $this->e(\Zuste\Helpers\Money::formatSigned((float) $transaction['amount'], (string) $transaction['direction'], (string) $transaction['currency'])) ?>
                  </td>
                  <td class="text-right mono small muted"><?= $this->moneyShort($transaction['balance_after'], (string) $transaction['currency']) ?></td>
                  <td class="small muted" style="white-space:nowrap"><?= $this->date($transaction['created_at'], 'd M y') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ Right column ============ -->
  <div class="col gap-4">

    <!-- Wallet summary -->
    <div class="hero-card">
      <p style="font-size:12px;opacity:.85;font-weight:600;letter-spacing:.08em;text-transform:uppercase">Wallet balance</p>
      <p style="font-size:32px;font-weight:800;letter-spacing:-.02em;margin:6px 0 14px">
        <?= $this->moneyShort($wallet['balance'], (string) $wallet['currency']) ?>
      </p>
      <div class="row gap-6">
        <div>
          <div style="font-size:11px;opacity:.8">Locked</div>
          <div style="font-weight:700"><?= $this->moneyShort($wallet['locked'], (string) $wallet['currency']) ?></div>
        </div>
        <div>
          <div style="font-size:11px;opacity:.8">Total</div>
          <div style="font-weight:700"><?= $this->moneyShort($wallet['total'], (string) $wallet['currency']) ?></div>
        </div>
      </div>
    </div>

    <?php if ($isErased): ?>
    <div class="callout callout--info">
      <?= $this->icon('info', 'ic ic--sm') ?>
      <div class="small">
        <strong>This account was erased on <?= $this->date((string) $erasure['created_at']) ?>.</strong>
        Its personal data is gone and it cannot be restored. What is left are the records the law keeps.
        <a href="/admin/privacy-requests">Privacy requests</a> has the deletion record.
      </div>
    </div>
    <?php endif; ?>

    <!-- Status control (not for an erased account: there is nothing to reactivate) -->
    <?php if ($this->can('users.edit') && !$isErased): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Account status</h3></div>
      <div class="card__body">
        <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/status">
          <?= $this->csrf() ?>
          <div class="field">
            <label class="field__label" for="status">Status</label>
            <select class="select" name="status" id="status">
              <?php foreach (['active' => 'Active', 'suspended' => 'Suspended', 'banned' => 'Banned', 'pending' => 'Pending'] as $value => $label): ?>
                <option value="<?= $this->e($value) ?>" <?= $user['status'] === $value ? 'selected' : '' ?>><?= $this->e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="field__label" for="reason">Reason (shown to the user)</label>
            <textarea class="textarea" name="reason" id="reason" rows="3"
                      placeholder="Explain why, if suspending or banning…"><?= $this->e($user['suspended_reason'] ?? '') ?></textarea>
          </div>
          <button type="submit" class="btn btn--primary btn--block"
                  data-confirm="Suspending or banning immediately signs the user out of every device."
                  data-confirm-title="Update account status?" data-confirm-label="Update" data-confirm-tone="warning">
            Update status
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Manual wallet adjustment -->
    <?php if ($this->can('wallet.adjust') && ($user['deleted_at'] === null || $isErased)): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Wallet adjustment</h3></div>
      <div class="card__body">
        <?php if ($isErased): ?>
          <p class="small muted mb-4">
            This account was erased, so its wallet takes only postings made here. Use one to settle money
            still owed to or by it, for example a refund that came back after the erasure. Nobody is notified.
          </p>
        <?php endif; ?>
        <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/wallet-adjust">
          <?= $this->csrf() ?>
          <div class="field">
            <label class="field__label" for="direction">Direction</label>
            <select class="select" name="direction" id="direction">
              <option value="credit">Credit (add money)</option>
              <option value="debit">Debit (remove money)</option>
            </select>
          </div>
          <div class="field">
            <label class="field__label" for="amount">Amount</label>
            <input class="input" type="number" step="0.01" min="1" name="amount" id="amount" required placeholder="0.00">
          </div>
          <div class="field">
            <label class="field__label" for="description">Reason <span class="req">*</span></label>
            <input class="input" name="description" id="description" required minlength="3" maxlength="255"
                   placeholder="e.g. Goodwill credit for booking ZB000123">
          </div>
          <button type="submit" class="btn btn--secondary btn--block"
                  data-confirm="<?= $isErased ? 'This writes an immutable ledger entry.' : 'This writes an immutable ledger entry and notifies the user.' ?>"
                  data-confirm-title="Post wallet adjustment?" data-confirm-label="Post" data-confirm-tone="warning">
            Post adjustment
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- KYC -->
    <?php
      // Which identity documents someone holds, and their review state, is KYC
      // data: the card is drawn for kyc.view holders only. It lists the
      // documents in force — not replaced versions, nor ones removed or erased
      // under the retention policy — with the panel's own status colours, and
      // links to the verification itself.
      $kycCurrent   = [];
      $kycProfileId = null;

      if ($this->can('kyc.view')) {
          foreach ((array) ($kyc ?? []) as $document) {
              if ((int) ($document['is_current'] ?? 1) !== 1
                  || !empty($document['deleted_at'])
                  || !empty($document['purged_at'])) {
                  continue;
              }

              $kycCurrent[] = $document;
              $kycProfileId ??= empty($document['profile_id']) ? null : (int) $document['profile_id'];
          }
      }
    ?>
    <?php if ($kycCurrent !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">KYC submissions</h3></div>
      <div class="card__body col gap-3">
        <?php foreach ($kycCurrent as $document): ?>
          <?php
            // 'verified' (legacy) and 'approved' both read as approved.
            $kycStatus = \Zuste\Services\KycService::documentStatus((string) ($document['status'] ?? ''));
            [$kycTone, $kycLabel] = match ($kycStatus) {
                'approved' => ['success', 'Approved'],
                'rejected' => ['error', 'Rejected'],
                default    => ['info', 'Pending review'],
            };
            $kycTitle = trim((string) ($document['title'] ?? ''));
            if ($kycTitle === '') {
                $kycTitle = ucwords(str_replace('_', ' ', (string) $document['document_type']));
            }
          ?>
          <div class="row row--between gap-2">
            <div class="flex-1 truncate">
              <div class="small" style="font-weight:600"><?= $this->e($kycTitle) ?></div>
              <div class="tiny muted"><?= $this->date($document['created_at']) ?></div>
            </div>
            <span class="chip chip--<?= $kycTone ?>"><?= $this->e($kycLabel) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if ($kycProfileId !== null): ?>
          <a href="/admin/kyc/<?= (int) $kycProfileId ?>" class="btn btn--outline btn--sm btn--block mt-2">Open verification</a>
        <?php else: ?>
          <a href="/admin/kyc?<?= $this->e(http_build_query(['queue' => 'all', 'q' => (string) $user['email']])) ?>" class="btn btn--outline btn--sm btn--block mt-2">Open KYC queue</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Devices -->
    <?php if ($devices !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Devices</h3></div>
      <div class="card__body col gap-3">
        <?php foreach ($devices as $device): ?>
          <div class="row gap-3">
            <span class="faint"><?= $this->icon('card', 'ic ic--sm') ?></span>
            <div class="flex-1 truncate">
              <div class="small truncate"><?= $this->e($device['device_model'] ?: ucfirst((string) $device['platform'])) ?></div>
              <div class="tiny muted">
                <?= $this->e(ucfirst((string) $device['platform'])) ?>
                <?= $device['os_version'] ? ' ' . $this->e($device['os_version']) : '' ?>
                · v<?= $this->e($device['app_version'] ?: '—') ?>
              </div>
            </div>
            <span class="tiny faint"><?= $this->timeAgo($device['last_active_at']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Danger zone. A brand or creator that staff soft-deleted before Delete
         became an erasure still holds its personal data, so it is offered too. -->
    <?php $isGuest = (string) $user['account_type'] === 'guest'; ?>
    <?php if ($this->can('users.delete') && !$isErased && ($user['status'] !== 'deleted' || !$isGuest)): ?>
    <div class="card" style="border-color:var(--error)">
      <div class="card__head"><h3 class="h3 text-error">Danger zone</h3></div>
      <div class="card__body">
        <?php if ($isGuest): ?>
          <p class="small muted mb-4">
            Closes this guest account and signs out its device. A guest gave no name or contact details.
          </p>
          <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/delete">
            <?= $this->csrf() ?>
            <button type="submit" class="btn btn--danger btn--block"
                    data-confirm="The guest's device is signed out and the account closed."
                    data-confirm-title="Close this guest account?" data-confirm-label="Close account">
              <?= $this->icon('trash', 'ic ic--sm') ?> Close guest account
            </button>
          </form>
        <?php else: ?>
          <p class="small muted mb-4">
            Erases this account's personal data, exactly like the user deleting it in the app. It can't be
            undone or restored. To stop someone using Zusty without erasing their data, suspend or ban them instead.
          </p>
          <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/delete">
            <?= $this->csrf() ?>
            <div class="field">
              <label class="field__label" for="user-erase-note">How did you confirm the request comes from the owner? <span class="req">*</span></label>
              <textarea class="textarea" name="note" id="user-erase-note" rows="3" minlength="3" maxlength="500" required
                        placeholder="e.g. Ticket 1234: asked from the account email; confirmed by reply on 2 Oct"></textarea>
              <p class="field__hint">Stored with the deletion record. Write how the request was confirmed, not personal details.</p>
            </div>
            <div class="field">
              <label class="field__label" for="user-erase-confirmation">Type DELETE to confirm <span class="req">*</span></label>
              <input class="input" name="confirmation" id="user-erase-confirmation" required maxlength="20" autocomplete="off" placeholder="DELETE">
            </div>
            <button type="submit" class="btn btn--danger btn--block"
                    data-confirm="The profile, contact details, files and identity documents are erased for good. Bookings, payments and invoices are kept as the law requires. Bookings in progress, money in the wallet and withdrawals or refunds still being processed must be settled first."
                    data-confirm-title="Erase this account?" data-confirm-label="Erase account">
              <?= $this->icon('trash', 'ic ic--sm') ?> Erase account
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php $this->stop(); ?>

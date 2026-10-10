<?php

declare(strict_types=1);

/**
 * Privacy requests: data exports and account deletions, self-service and on
 * request, and the tab support uses to act on a request.
 *
 * @var \Zuste\Core\View $this
 * @var string $tab                          'deletions', 'exports' or 'act'
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed>|null $act        The act tab's context (null on the other tabs)
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $summary
 * @var array<string,string> $reasonLabels
 */

$this->extend('admin.layouts.app');
$this->start('content');

$verified = static fn (string $via): string => match ($via) {
    'password'   => 'Password',
    'email_code' => 'Email code',
    'sms_code'   => 'SMS code',
    'firebase'   => 'Google/Apple sign-in',
    'operator'   => 'Support request (staff)',
    'legacy'     => 'Closed before erasure existed',
    default      => ucfirst(str_replace('_', ' ', $via)),
};

$platform = static fn (string $value): string => $value === 'admin' ? 'Support' : ucfirst($value);

// The export's real state, from DataExportService::state(): a 'ready' row
// whose link expired or was used up is not ready any more.
$exportChip = static fn (string $state): array => match ($state) {
    'ready'      => ['Ready', 'success'],
    'used_up'    => ['Used up', 'neutral'],
    'expired'    => ['Expired', 'neutral'],
    'revoked'    => ['Link revoked', 'neutral'],
    'processing' => ['Preparing', 'info'],
    'failed'     => ['Failed', 'error'],
    default      => [ucfirst(str_replace('_', ' ', $state)), 'neutral'],
};

$bytes = static function (?int $size): string {
    if ($size === null) {
        return '—';
    }

    return $size >= 1048576 ? number_format($size / 1048576, 1) . ' MB' : number_format(max(1, (int) round($size / 1024))) . ' KB';
};

$reasonTotal = array_sum(array_map(static fn (array $r): int => (int) $r['n'], $summary['reasons']));
?>

<section class="grid grid--4 mb-5">
  <?php foreach ([
      ['Deletions, last 30 days', $this->number($summary['deletions_30d']), 'trash', 'var(--error)'],
      ['Exports, last 30 days', $this->number($summary['exports_30d']), 'download', 'var(--primary)'],
      ['Export links still live', $this->number($summary['live_exports']), 'lock', 'var(--tertiary)'],
      ['Clean-ups pending', $this->number($summary['cleanups_pending']), 'refresh', 'var(--warning, #B45309)'],
      ['Deletions, all time', $this->number($reasonTotal), 'users', 'var(--secondary)'],
  ] as [$label, $value, $icon, $toneVar]): ?>
    <div class="stat" style="--tone:<?= $toneVar ?>">
      <div class="stat__icon"><?= $this->icon($icon) ?></div>
      <div class="stat__value"><?= $this->e($value) ?></div>
      <div class="stat__label"><?= $this->e($label) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<?php if ($summary['reasons'] !== [] && $tab !== 'act'): ?>
  <div class="card mb-5">
    <div class="card__body">
      <h3 class="h3 mb-3">Why people leave</h3>
      <?php foreach ($summary['reasons'] as $reason): ?>
        <?php
          $code    = (string) ($reason['reason_code'] ?? '');
          $count   = (int) $reason['n'];
          $percent = $reasonTotal > 0 ? (int) round($count * 100 / $reasonTotal) : 0;
        ?>
        <div class="row gap-3 mb-2">
          <div class="flex-1 truncate"><?= $this->e($code === '' ? 'No reason given' : ($reasonLabels[$code] ?? $code)) ?></div>
          <div style="width:40%;max-width:320px">
            <div style="height:8px;border-radius:999px;background:var(--surface-container-high, #eee);overflow:hidden">
              <div style="height:100%;width:<?= $percent ?>%;background:var(--primary)"></div>
            </div>
          </div>
          <div class="small muted" style="width:72px;text-align:right"><?= $this->number($count) ?> · <?= $percent ?>%</div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="chip-row mb-4">
  <a href="/admin/privacy-requests" class="chip-filter<?= $tab === 'deletions' ? ' chip-filter--active' : '' ?>">Account deletions</a>
  <a href="/admin/privacy-requests?tab=exports" class="chip-filter<?= $tab === 'exports' ? ' chip-filter--active' : '' ?>">Data exports</a>
  <a href="/admin/privacy-requests?tab=act" class="chip-filter<?= $tab === 'act' ? ' chip-filter--active' : '' ?>">Act on a request</a>
</div>

<?php if ($tab === 'act' && is_array($act)): ?>
  <?php
    $actUser   = $act['user'];
    $preflight = $act['preflight'];
    $erasure   = $act['erasure'];
    $exportNow = $act['export'];
  ?>

  <div class="card mb-4">
    <div class="card__body">
      <h3 class="h3 mb-2">Find the account</h3>
      <p class="small muted mb-3">
        For someone who cannot use the app and has written in. First confirm the request comes from the
        account's owner, for example by replying to the account's own email address and getting an answer.
      </p>
      <form method="get" action="/admin/privacy-requests" class="row gap-2 row--wrap">
        <input type="hidden" name="tab" value="act">
        <input class="input flex-1" style="min-width:240px" name="user" value="<?= $this->e((string) $act['query']) ?>"
               placeholder="Account ID or email address" aria-label="Account ID or email address" required>
        <button type="submit" class="btn btn--primary"><?= $this->icon('search', 'ic ic--sm') ?> Find</button>
      </form>
    </div>
  </div>

  <?php if ($act['error'] !== null): ?>
    <div class="callout callout--warning mb-4">
      <?= $this->icon('alert-triangle', 'ic ic--sm') ?>
      <div><?= $this->e((string) $act['error']) ?></div>
    </div>
  <?php endif; ?>

  <?php if (is_array($actUser)): ?>
    <div class="card mb-4">
      <div class="card__body">
        <div class="row gap-2 row--wrap mb-2">
          <h3 class="h3"><?= $this->e($actUser['name']) ?></h3>
          <span class="chip chip--sm chip--<?= $actUser['account_type'] === 'creator' ? 'info' : 'neutral' ?>"><?= $this->e(ucfirst($actUser['account_type'])) ?></span>
          <span class="chip chip--sm chip--neutral"><?= $this->e(ucfirst($actUser['status'])) ?></span>
        </div>
        <p class="small muted mb-0">
          Account #<?= (int) $actUser['id'] ?>
          · <?= $this->e($actUser['email'] !== '' ? $actUser['email'] : 'no email address') ?>
          <?= $actUser['phone'] !== '' ? ' · ' . $this->e($actUser['phone']) : '' ?>
          · joined <?= $this->e($this->date($actUser['created_at'])) ?>
          · <a href="/admin/users/<?= (int) $actUser['id'] ?>">Open the user page</a>
        </p>
        <?php if ($actUser['deleted_at'] !== null && $erasure === null): ?>
          <p class="small mt-2 mb-0">
            Deleted by staff on <?= $this->e($this->date($actUser['deleted_at'])) ?>, but not erased: its personal data is still here.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <?php if (is_array($erasure)): ?>
      <div class="callout callout--success mb-4">
        <?= $this->icon('check-circle', 'ic ic--sm') ?>
        <div>
          <strong>This account was erased on <?= $this->e($this->date((string) $erasure['created_at'])) ?>.</strong>
          Deletion #<?= (int) $erasure['id'] ?>, confirmed with: <?= $this->e($verified((string) $erasure['verified_via'])) ?>.
          There is nothing left to export or erase.
          <?php if (empty($erasure['completed_at'])): ?>
            The clean-up after it is still running; see the Account deletions tab.
          <?php endif; ?>
        </div>
      </div>
    <?php elseif (is_array($preflight)): ?>
      <div class="grid grid--2 mb-4">
        <div class="card">
          <div class="card__head"><h3 class="h3">Erase the account</h3></div>
          <div class="card__body">
            <?php if (($preflight['blockers'] ?? []) !== []): ?>
              <div class="callout callout--warning mb-3">
                <?= $this->icon('alert-triangle', 'ic ic--sm') ?>
                <div>
                  <strong>Not yet: these must be settled first.</strong>
                  <ul class="small mt-1 mb-0">
                    <?php foreach ($preflight['blockers'] as $blocker): ?>
                      <li><strong><?= $this->e((string) $blocker['title']) ?>.</strong> <?= $this->e((string) $blocker['message']) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            <?php endif; ?>

            <?php $forfeit = is_array($preflight['forfeit'] ?? null) ? $preflight['forfeit'] : null; ?>
            <?php if ($forfeit !== null): ?>
              <div class="callout callout--info mb-3">
                <?= $this->icon('wallet', 'ic ic--sm') ?>
                <div class="small"><?= $this->e((string) $forfeit['message']) ?></div>
              </div>
            <?php endif; ?>

            <p class="small mb-1"><strong>What is erased</strong></p>
            <ul class="small muted mb-3">
              <?php foreach ((array) ($preflight['erased'] ?? []) as $item): ?>
                <li><?= $this->e((string) $item['title']) ?></li>
              <?php endforeach; ?>
            </ul>

            <?php
              $counts = (array) ($preflight['retained_counts'] ?? []);
              $kept   = [];

              foreach (['bookings' => 'booking', 'payments' => 'payment', 'invoices' => 'invoice', 'messages' => 'message', 'reviews_written' => 'review'] as $key => $noun) {
                  $n = (int) ($counts[$key] ?? 0);

                  if ($n > 0) {
                      $kept[] = $n . ' ' . $noun . ($n === 1 ? '' : 's');
                  }
              }
            ?>
            <p class="small mb-1"><strong>What is kept</strong><?= $kept !== [] ? ' (' . $this->e(implode(', ', $kept)) . ')' : '' ?></p>
            <ul class="small muted mb-4">
              <?php foreach ((array) ($preflight['retained'] ?? []) as $item): ?>
                <li><?= $this->e((string) $item['title']) ?></li>
              <?php endforeach; ?>
            </ul>

            <?php if ($this->can('users.delete')): ?>
              <?php $canDelete = !empty($preflight['can_delete']); ?>
              <form method="post" action="/admin/privacy-requests/erase">
                <?= $this->csrf() ?>
                <input type="hidden" name="user_id" value="<?= (int) $actUser['id'] ?>">
                <div class="field">
                  <label class="field__label" for="erase-note">How did you confirm the request comes from the owner? <span class="req">*</span></label>
                  <textarea class="textarea" name="note" id="erase-note" rows="3" maxlength="500" required
                            placeholder="e.g. Ticket 1234: asked from the account email; confirmed by reply on 2 Oct"><?= $this->old('note') ?></textarea>
                  <?php if ($this->hasError('note')): ?><p class="field__error"><?= $this->error('note') ?></p><?php endif; ?>
                  <p class="field__hint">Stored with the deletion record. Write how the request was confirmed, not personal details.</p>
                </div>
                <?php if ($forfeit !== null): ?>
                  <label class="row gap-2 small mb-3">
                    <input type="checkbox" name="forfeit" value="<?= $this->e(number_format((float) $forfeit['amount'], 2, '.', '')) ?>" required>
                    The owner gives up <?= $this->e((string) $forfeit['label']) ?> in their wallet
                  </label>
                <?php endif; ?>
                <div class="field">
                  <label class="field__label" for="erase-confirmation">Type DELETE to confirm <span class="req">*</span></label>
                  <input class="input" name="confirmation" id="erase-confirmation" required maxlength="20" autocomplete="off" placeholder="DELETE">
                  <?php if ($this->hasError('confirmation')): ?><p class="field__error"><?= $this->error('confirmation') ?></p><?php endif; ?>
                </div>
                <button type="submit" class="btn btn--danger btn--block"<?= $canDelete ? '' : ' disabled' ?>
                        data-confirm="This erases the account's personal data for good, exactly as if its owner deleted it in the app. It cannot be undone or restored."
                        data-confirm-title="Erase this account?" data-confirm-label="Erase account">
                  <?= $this->icon('trash', 'ic ic--sm') ?> Erase account
                </button>
                <?php if (!$canDelete): ?>
                  <p class="field__hint mt-2">Settle what is listed above first.</p>
                <?php endif; ?>
              </form>
            <?php else: ?>
              <p class="small muted mb-0">Erasing an account needs the users.delete permission.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card__head"><h3 class="h3">Send a copy of the data</h3></div>
          <div class="card__body">
            <?php if ($actUser['deleted_at'] !== null): ?>
              <p class="small muted mb-0">This account has been deleted, so there is no copy of its data to make.</p>
            <?php elseif ($actUser['email'] === ''): ?>
              <p class="small muted mb-0">This account has no email address, so a copy cannot be sent. The owner can download one in the app.</p>
            <?php else: ?>
              <p class="small muted mb-3">
                Prepares the same ZIP the app makes and emails its download link to the account's own address,
                <strong><?= $this->e($actUser['email_masked']) ?></strong>. The link is never shown here.
              </p>
              <?php $canRequest = !is_array($exportNow) || !empty($exportNow['can_request']); ?>
              <?php if (!$canRequest && !empty($exportNow['next_available_at'])): ?>
                <p class="small mb-3">A new copy can be made after <?= $this->e($this->dateTime((string) $exportNow['next_available_at'])) ?>.</p>
              <?php endif; ?>
              <?php if ($this->can('users.edit')): ?>
                <form method="post" action="/admin/privacy-requests/export">
                  <?= $this->csrf() ?>
                  <input type="hidden" name="user_id" value="<?= (int) $actUser['id'] ?>">
                  <button type="submit" class="btn btn--secondary btn--block"<?= $canRequest ? '' : ' disabled' ?>
                          data-confirm="A copy of everything this account holds is emailed to its own address."
                          data-confirm-title="Email a copy of the data?" data-confirm-label="Email the copy" data-confirm-tone="warning">
                    <?= $this->icon('download', 'ic ic--sm') ?> Email a copy of the data
                  </button>
                </form>
              <?php else: ?>
                <p class="small muted mb-0">Sending a copy needs the users.edit permission.</p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card__head"><h3 class="h3">Deleted by staff but not erased</h3></div>
    <div class="card__body">
      <p class="small muted mb-3">
        Accounts deleted from the user page before Delete became an erasure. They are hidden, but their
        name and contact details are still stored. Erase each one deliberately.
      </p>
      <?php if ($act['not_erased'] === []): ?>
        <p class="small mb-0">None.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Account</th><th>Type</th><th>Deleted</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($act['not_erased'] as $item): ?>
                <tr>
                  <td>
                    <div><?= $this->e((string) $item['name']) ?></div>
                    <div class="small muted">#<?= (int) $item['id'] ?> · <?= $this->e((string) $item['email']) ?></div>
                  </td>
                  <td><?= $this->e(ucfirst((string) $item['account_type'])) ?></td>
                  <td class="small"><?= $this->e($this->date((string) $item['deleted_at'])) ?></td>
                  <td class="text-right"><a class="btn btn--outline btn--sm" href="/admin/privacy-requests?tab=act&amp;user=<?= (int) $item['id'] ?>">Review</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <?php if ((int) $act['legacy'] > 0): ?>
        <p class="small muted mt-3 mb-0">
          Not listed: accounts their owners closed in the app before erasure existed
          (<?= $this->number((int) $act['legacy']) ?>). Erase those with
          <code class="mono">php scripts/privacy_erase_legacy_deletions.php</code>, run with <code class="mono">--dry-run</code> first.
        </p>
      <?php endif; ?>
    </div>
  </div>
<?php elseif ($rows === []): ?>
  <div class="empty">
    <div class="empty__art"><?= $this->icon($tab === 'deletions' ? 'trash' : 'download', 'ic ic--lg') ?></div>
    <p class="empty__title"><?= $tab === 'deletions' ? 'No accounts deleted yet' : 'No data exports yet' ?></p>
    <p class="empty__text">
      <?= $tab === 'deletions'
          ? 'When a brand or creator deletes their account in the app, or support erases one on request, it is recorded here.'
          : 'When a brand or creator downloads a copy of their data in the app, or support sends one on request, it is recorded here.' ?>
    </p>
  </div>
<?php elseif ($tab === 'deletions'): ?>
  <div class="card mb-4">
    <div class="card__body small muted">
      Personal data is erased when an account is deleted, so these rows carry no name or contact details.
      Bookings, payments and invoices are kept as tax and accounting law requires; invoices keep the billing details printed on them.
    </div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Deleted</th>
          <th>Account</th>
          <th style="min-width:240px">Reason</th>
          <th>Confirmed with</th>
          <th>From</th>
          <th>By</th>
          <th style="min-width:200px">Clean-up</th>
          <th>Records kept</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <?php
            $code = (string) ($row['reason_code'] ?? '');
            $kept = [];

            foreach (['bookings' => 'booking', 'invoices' => 'invoice', 'payments' => 'payment', 'messages' => 'message'] as $key => $noun) {
                $n = (int) ($row['retained'][$key] ?? 0);

                if ($n > 0) {
                    $kept[] = $n . ' ' . $noun . ($n === 1 ? '' : 's');
                }
            }

            $cleanup    = $row['cleanup'];
            $isOperator = (string) $row['verified_via'] === 'operator';
          ?>
          <tr>
            <td>
              <div><?= $this->e($this->date((string) $row['created_at'])) ?></div>
              <div class="small muted"><?= $this->e($this->timeAgo((string) $row['created_at'])) ?></div>
              <?php if (!empty($row['reapplied'])): ?>
                <div class="small muted">Re-applied after a restore</div>
              <?php endif; ?>
            </td>
            <td>
              <span class="chip chip--sm chip--<?= $row['account_type'] === 'creator' ? 'info' : 'neutral' ?>"><?= $this->e(ucfirst((string) $row['account_type'])) ?></span>
              <div class="small muted">User #<?= (int) $row['user_id'] ?></div>
            </td>
            <td>
              <div><?= $this->e($code === '' ? 'No reason given' : ($reasonLabels[$code] ?? $code)) ?></div>
              <?php if (!empty($row['reason_text'])): ?>
                <div class="small muted" style="white-space:normal">“<?= $this->e((string) $row['reason_text']) ?>”</div>
              <?php endif; ?>
            </td>
            <td><?= $this->e($verified((string) $row['verified_via'])) ?></td>
            <td><?= $this->e($platform((string) $row['platform'])) ?></td>
            <td class="small">
              <?php if ($isOperator): ?>
                <?= $this->e(!empty($row['performed_by_name']) ? (string) $row['performed_by_name'] : 'Staff (account since removed)') ?>
              <?php else: ?>
                Account owner
              <?php endif; ?>
            </td>
            <td>
              <?php if ($cleanup['state'] === 'completed'): ?>
                <span class="chip chip--sm chip--success"><span class="chip__dot"></span>Completed</span>
              <?php elseif ($cleanup['state'] === 'attention'): ?>
                <span class="chip chip--sm chip--error"><span class="chip__dot"></span>Needs attention</span>
                <?php if (!empty($row['last_error'])): ?>
                  <div class="small muted" style="white-space:normal"><?= $this->e((string) $row['last_error']) ?></div>
                <?php endif; ?>
                <div class="small muted" style="white-space:normal">
                  <?= (int) $row['attempts'] ?> attempts<?= $cleanup['steps'] !== [] ? ' · left: ' . $this->e(implode(', ', $cleanup['steps'])) : '' ?>
                </div>
              <?php else: ?>
                <span class="chip chip--sm chip--warning"><span class="chip__dot"></span>Clean-up pending</span>
                <div class="small muted" style="white-space:normal">
                  <?= $cleanup['steps'] !== [] ? 'Left: ' . $this->e(implode(', ', $cleanup['steps'])) . '. ' : '' ?>
                  <?= !empty($row['next_attempt_at']) ? 'Next try ' . $this->e($this->dateTime((string) $row['next_attempt_at'])) . '.' : '' ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="small">
              <?= $this->e($kept === [] ? 'None' : implode(', ', $kept)) ?>
              <?php if ($row['forfeited_amount'] !== null && (float) $row['forfeited_amount'] > 0): ?>
                <div class="muted">Gave up <?= $this->e($this->money((float) $row['forfeited_amount'], $row['forfeited_currency'] ? (string) $row['forfeited_currency'] : null)) ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Requested</th>
          <th>User</th>
          <th>Status</th>
          <th class="text-right">Size</th>
          <th class="text-right">Downloads</th>
          <th>Confirmed with</th>
          <th>Link expires</th>
          <th>IP address</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <?php
            $deleted          = $row['user_deleted_at'] !== null;
            [$chip, $tone]    = $exportChip((string) $row['state']);
          ?>
          <tr>
            <td>
              <div><?= $this->e($this->dateTime((string) $row['created_at'])) ?></div>
              <?php if (!empty($row['last_downloaded_at'])): ?>
                <div class="small muted">Last downloaded <?= $this->e($this->timeAgo((string) $row['last_downloaded_at'])) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($deleted): ?>
                <span class="muted">Deleted user</span>
              <?php else: ?>
                <a href="/admin/users/<?= (int) $row['user_id'] ?>"><?= $this->e((string) $row['name']) ?></a>
                <div class="small muted"><?= $this->e((string) ($row['email'] ?? '')) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="chip chip--sm chip--<?= $tone ?>">
                <span class="chip__dot"></span><?= $this->e($chip) ?>
              </span>
              <?php if ($row['state'] === 'failed'): ?>
                <div class="small muted" style="white-space:normal"><?= $this->e(!empty($row['error']) ? (string) $row['error'] : 'Stopped while preparing.') ?></div>
              <?php endif; ?>
            </td>
            <td class="text-right"><?= $this->e($bytes($row['file_size'] === null ? null : (int) $row['file_size'])) ?></td>
            <td class="text-right"><?= (int) $row['download_count'] ?> / <?= (int) $row['max_downloads'] ?></td>
            <td>
              <?= $this->e($verified((string) $row['verified_via'])) ?>
              <?php if ((string) $row['verified_via'] === 'operator' && !empty($row['requested_by_name'])): ?>
                <div class="small muted">by <?= $this->e((string) $row['requested_by_name']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= $this->e($row['expires_at'] ? $this->dateTime((string) $row['expires_at']) : '—') ?></td>
            <td class="small"><?= $this->e((string) ($row['ip_address'] ?? '—')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if ($tab !== 'act'): ?>
  <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
<?php endif; ?>

<?php $this->stop(); ?>

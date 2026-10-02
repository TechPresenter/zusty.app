<?php

declare(strict_types=1);

/**
 * Privacy requests: self-service data exports and account deletions.
 *
 * @var \Zuste\Core\View $this
 * @var string $tab                          'deletions' or 'exports'
 * @var list<array<string,mixed>> $rows
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
    default      => ucfirst($via),
};

$exportTone = static fn (string $status): string => match ($status) {
    'ready'      => 'success',
    'processing' => 'info',
    'failed'     => 'error',
    default      => 'neutral',
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
      ['Deletions, all time', $this->number($reasonTotal), 'users', 'var(--secondary)'],
  ] as [$label, $value, $icon, $toneVar]): ?>
    <div class="stat" style="--tone:<?= $toneVar ?>">
      <div class="stat__icon"><?= $this->icon($icon) ?></div>
      <div class="stat__value"><?= $this->e($value) ?></div>
      <div class="stat__label"><?= $this->e($label) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<?php if ($summary['reasons'] !== []): ?>
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
</div>

<?php if ($rows === []): ?>
  <div class="empty">
    <div class="empty__art"><?= $this->icon($tab === 'deletions' ? 'trash' : 'download', 'ic ic--lg') ?></div>
    <p class="empty__title"><?= $tab === 'deletions' ? 'No accounts deleted yet' : 'No data exports yet' ?></p>
    <p class="empty__text">
      <?= $tab === 'deletions'
          ? 'When a brand or creator deletes their account from Settings → Privacy &amp; data, it is recorded here.'
          : 'When a brand or creator downloads a copy of their data from Settings → Privacy &amp; data, it is recorded here.' ?>
    </p>
  </div>
<?php elseif ($tab === 'deletions'): ?>
  <div class="card mb-4">
    <div class="card__body small muted">
      Personal data is erased when an account is deleted, so these rows carry no name or contact details.
      Bookings, payments and invoices are kept, anonymised, as tax and accounting law requires.
    </div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Deleted</th>
          <th>Account</th>
          <th style="min-width:260px">Reason</th>
          <th>Confirmed with</th>
          <th>From</th>
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
          ?>
          <tr>
            <td>
              <div><?= $this->e($this->date((string) $row['created_at'])) ?></div>
              <div class="small muted"><?= $this->e($this->timeAgo((string) $row['created_at'])) ?></div>
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
            <td><?= $this->e(ucfirst((string) $row['platform'])) ?></td>
            <td class="small"><?= $this->e($kept === [] ? 'None' : implode(', ', $kept)) ?></td>
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
          <?php $deleted = $row['user_deleted_at'] !== null; ?>
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
              <span class="chip chip--sm chip--<?= $exportTone((string) $row['status']) ?>">
                <span class="chip__dot"></span><?= $this->e(ucfirst((string) $row['status'])) ?>
              </span>
              <?php if ($row['status'] === 'failed' && !empty($row['error'])): ?>
                <div class="small muted" style="white-space:normal"><?= $this->e((string) $row['error']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-right"><?= $this->e($bytes($row['file_size'] === null ? null : (int) $row['file_size'])) ?></td>
            <td class="text-right"><?= (int) $row['download_count'] ?> / <?= (int) $row['max_downloads'] ?></td>
            <td><?= $this->e($verified((string) $row['verified_via'])) ?></td>
            <td><?= $this->e($row['expires_at'] ? $this->dateTime((string) $row['expires_at']) : '—') ?></td>
            <td class="small"><?= $this->e((string) ($row['ip_address'] ?? '—')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>

<?php $this->stop(); ?>

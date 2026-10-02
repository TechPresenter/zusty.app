<?php

declare(strict_types=1);

/**
 * Security overview.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $summary
 * @var list<array<string,mixed>> $recent
 * @var list<array<string,mixed>> $topFailures
 * @var bool $twoFactorOn
 * @var int  $recoveryLeft
 * @var int  $whitelistCount
 * @var int  $sessionCount
 */

$this->extend('admin.layouts.app');
$this->start('content');

$outcomeTone = static fn (string $o): string => match ($o) {
    'success'                      => 'success',
    'bad_credentials', '2fa_failed' => 'error',
    'locked', 'suspended'          => 'warning',
    default                        => 'neutral',
};

$attempts = (int) ($summary['attempts_24h'] ?? 0);
$failed   = (int) ($summary['failed_24h'] ?? 0);
$failRate = $attempts > 0 ? round($failed / $attempts * 100) : 0;

?>

<div class="wgrid mb-5">

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#2563EB" href="/admin/security/login-history">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('shield') ?></div></div>
      <div class="kpi__value"><?= $this->number($attempts) ?></div>
      <div class="kpi__label">Sign-in attempts (24h)</div>
      <div class="kpi__foot"><?= $this->number((int) ($summary['success_24h'] ?? 0)) ?> succeeded</div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:<?= $failRate > 40 ? '#DC2626' : '#B45309' ?>"
       href="/admin/security/login-history?outcome=bad_credentials">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('alert') ?></div></div>
      <div class="kpi__value"><?= $this->number($failed) ?></div>
      <div class="kpi__label">Failed attempts (24h)</div>
      <div class="kpi__foot"><?= (int) $failRate ?>% of all attempts</div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#632CE5" href="/admin/security/ip-whitelist">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('key') ?></div></div>
      <div class="kpi__value"><?= $this->number($whitelistCount) ?></div>
      <div class="kpi__label">Allow-list rules</div>
      <div class="kpi__foot"><?= $whitelistCount === 0 ? 'All addresses permitted' : 'Access is restricted' ?></div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#006A34" href="/admin/security/sessions">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('users') ?></div></div>
      <div class="kpi__value"><?= $this->number($sessionCount) ?></div>
      <div class="kpi__label">Active sessions</div>
      <div class="kpi__foot">Seen in the last 2 hours</div>
    </a>
  </div>
</div>

<!-- Your own account -->
<div class="card mb-5">
  <div class="card__head">
    <div>
      <h2 class="h3">Your account</h2>
      <p class="muted small mt-1">Two-factor protects your own sign-in, not the whole panel.</p>
    </div>
    <a href="/admin/security/two-factor" class="btn btn--<?= $twoFactorOn ? 'outline' : 'primary' ?> btn--sm">
      <?= $this->icon('shield', 'ic ic--sm') ?>
      <?= $twoFactorOn ? 'Manage two-factor' : 'Turn on two-factor' ?>
    </a>
  </div>

  <div class="card__body">
    <?php if ($twoFactorOn): ?>
      <div class="row gap-3">
        <span class="chip chip--success"><span class="chip__dot"></span>Two-factor is on</span>
        <span class="small muted">
          <?= (int) $recoveryLeft ?> recovery code<?= $recoveryLeft === 1 ? '' : 's' ?> remaining
        </span>
      </div>
      <?php if ($recoveryLeft <= 2): ?>
        <p class="small text-warning mt-3">
          You are running low on recovery codes. Generate a fresh set from the two-factor page.
        </p>
      <?php endif; ?>
    <?php else: ?>
      <div class="row gap-3">
        <span class="chip chip--warning"><span class="chip__dot"></span>Two-factor is off</span>
        <span class="small muted">A stolen password is enough to sign in as you.</span>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid--2">

  <!-- Recent attempts -->
  <div class="card">
    <div class="card__head">
      <h2 class="h3">Recent sign-ins</h2>
      <a href="/admin/security/login-history" class="btn btn--ghost btn--sm">
        View all <?= $this->icon('chevron-right', 'ic ic--sm') ?>
      </a>
    </div>

    <?php if ($recent === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('shield', 'ic ic--lg') ?></div>
        <p class="empty__title">Nothing recorded yet</p>
        <p class="empty__text">Sign-in attempts appear here as they happen.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Identifier</th><th>Outcome</th><th>Address</th><th>When</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $row): ?>
              <tr>
                <td class="truncate" style="max-width:180px">
                  <span class="small" style="font-weight:600"><?= $this->e($row['identifier']) ?></span>
                  <div class="tiny muted"><?= $this->e(ucfirst((string) $row['actor_type'])) ?> · <?= $this->e((string) $row['device_type']) ?></div>
                </td>
                <td>
                  <span class="chip chip--<?= $outcomeTone((string) $row['outcome']) ?>">
                    <?= $this->e(ucfirst(str_replace('_', ' ', (string) $row['outcome']))) ?>
                  </span>
                </td>
                <td class="mono tiny"><?= $this->e($row['ip_address'] ?? '—') ?></td>
                <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($row['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Repeat offenders -->
  <div class="card">
    <div class="card__head">
      <h2 class="h3">Addresses failing most (7d)</h2>
    </div>

    <?php if ($topFailures === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
        <p class="empty__title">No repeated failures</p>
        <p class="empty__text">Nothing is hammering the sign-in form.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Address</th><th class="text-right">Failures</th><th>Last seen</th></tr>
          </thead>
          <tbody>
            <?php foreach ($topFailures as $row): ?>
              <tr>
                <td class="mono small"><?= $this->e($row['ip_address']) ?></td>
                <td class="text-right mono">
                  <span class="chip chip--<?= (int) $row['attempts'] >= 20 ? 'error' : 'warning' ?>">
                    <?= $this->number((int) $row['attempts']) ?>
                  </span>
                </td>
                <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($row['last_seen']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<link rel="stylesheet" href="/admin/assets/css/widgets.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<?php $this->stop(); ?>

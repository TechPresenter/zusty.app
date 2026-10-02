<?php

declare(strict_types=1);

/**
 * Administrative IP allow list.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var string $currentIp
 * @var bool $wouldLockOut
 */

$this->extend('admin.layouts.app');
$this->start('content');

$activeAdminRules = 0;
foreach ($rows as $row) {
    if ((int) $row['is_enabled'] === 1 && in_array($row['scope'], ['admin', 'both'], true)) {
        $activeAdminRules++;
    }
}

?>

<?php if ($wouldLockOut): ?>
  <div class="callout callout--error mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>Your current address is not covered.</strong>
      You are signed in already, but <code class="mono"><?= $this->e($currentIp) ?></code>
      would be refused on the next sign-in. Add a rule that covers it before signing out.
    </div>
  </div>
<?php endif; ?>

<div class="callout callout--<?= $activeAdminRules === 0 ? 'info' : 'warning' ?> mb-5">
  <?= $this->icon('info', 'ic ic--sm') ?>
  <div>
    <?php if ($activeAdminRules === 0): ?>
      <strong>The allow list is not enforcing anything yet.</strong>
      While it is empty, every address may reach the admin panel. Add the first rule to start restricting —
      it must cover your own address (<code class="mono"><?= $this->e($currentIp) ?></code>).
    <?php else: ?>
      <strong>Admin access is restricted to <?= (int) $activeAdminRules ?> rule<?= $activeAdminRules === 1 ? '' : 's' ?>.</strong>
      Addresses outside them are refused at sign-in, after the password check, and the attempt is recorded.
    <?php endif; ?>
  </div>
</div>

<div class="grid grid--sidebar">

  <div class="card">
    <div class="card__head">
      <h2 class="h3">Rules</h2>
      <span class="small muted">Your address: <code class="mono"><?= $this->e($currentIp) ?></code></span>
    </div>

    <?php if ($rows === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('key', 'ic ic--lg') ?></div>
        <p class="empty__title">No rules yet</p>
        <p class="empty__text">Add one to restrict admin access to known networks.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Label</th><th>Pattern</th><th>Scope</th><th>State</th>
              <th class="text-right">Hits</th><th>Last seen</th><th>Expires</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php $covers = \Zuste\Services\SecurityService::ipMatches($currentIp, (string) $row['ip_address']); ?>
              <tr>
                <td>
                  <span class="small" style="font-weight:600"><?= $this->e($row['label']) ?></span>
                  <?php if (!empty($row['created_by_name'])): ?>
                    <div class="tiny muted">Added by <?= $this->e($row['created_by_name']) ?></div>
                  <?php endif; ?>
                </td>

                <td>
                  <code class="mono small"><?= $this->e($row['ip_address']) ?></code>
                  <?php if ($covers): ?>
                    <div class="tiny text-success">Covers you</div>
                  <?php endif; ?>
                </td>

                <td><span class="chip chip--neutral"><?= $this->e(ucfirst((string) $row['scope'])) ?></span></td>

                <td>
                  <span class="chip chip--<?= (int) $row['is_enabled'] === 1 ? 'success' : 'neutral' ?>">
                    <span class="chip__dot"></span>
                    <?= (int) $row['is_enabled'] === 1 ? 'Enabled' : 'Disabled' ?>
                  </span>
                </td>

                <td class="text-right mono small"><?= $this->number((int) $row['hit_count']) ?></td>

                <td class="small muted" style="white-space:nowrap">
                  <?= $row['last_seen_at'] !== null ? $this->timeAgo($row['last_seen_at']) : '—' ?>
                </td>

                <td class="small muted" style="white-space:nowrap">
                  <?= $row['expires_at'] !== null ? $this->date($row['expires_at']) : 'Never' ?>
                </td>

                <td class="actions">
                  <form method="post" action="/admin/security/ip-whitelist/<?= (int) $row['id'] ?>/toggle" style="display:inline">
                    <?= $this->csrf() ?>
                    <button type="submit" class="btn btn--ghost btn--sm">
                      <?= (int) $row['is_enabled'] === 1 ? 'Disable' : 'Enable' ?>
                    </button>
                  </form>

                  <form method="post" action="/admin/security/ip-whitelist/<?= (int) $row['id'] ?>/delete" style="display:inline">
                    <?= $this->csrf() ?>
                    <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error"
                            aria-label="Delete"
                            data-confirm="Remove <?= $this->e($row['ip_address']) ?> from the allow list?"
                            data-confirm-title="Remove rule?" data-confirm-label="Remove" data-confirm-tone="danger">
                      <?= $this->icon('x', 'ic ic--sm') ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head"><h2 class="h3">Add a rule</h2></div>

    <div class="card__body">
      <form method="post" action="/admin/security/ip-whitelist" class="grid">
        <?= $this->csrf() ?>

        <div class="field">
          <label class="field__label" for="label">Label</label>
          <input class="input" id="label" name="label" required maxlength="120" placeholder="Head office">
          <p class="field__hint">What this address belongs to, so it stays meaningful later.</p>
        </div>

        <div class="field">
          <label class="field__label" for="ip_address">Address, range or prefix</label>
          <div class="row gap-2">
            <input class="input mono" id="ip_address" name="ip_address" required maxlength="45"
                   placeholder="203.0.113.24" value="">
            <button type="button" class="btn btn--outline btn--sm" data-use-my-ip>Use mine</button>
          </div>
          <p class="field__hint">
            Exact (<code>203.0.113.24</code>), CIDR (<code>203.0.113.0/24</code>)
            or prefix (<code>203.0.113.*</code>). IPv6 is supported.
          </p>
        </div>

        <div class="field">
          <label class="field__label" for="scope">Applies to</label>
          <select class="select" id="scope" name="scope">
            <option value="admin">Admin panel</option>
            <option value="api">REST API</option>
            <option value="both">Both</option>
          </select>
        </div>

        <div class="field">
          <label class="field__label" for="expires_at">Expires (optional)</label>
          <input class="input" id="expires_at" name="expires_at" type="datetime-local">
          <p class="field__hint">Useful for a contractor's address. Leave blank for permanent.</p>
        </div>

        <div>
          <button type="submit" class="btn btn--primary">
            <?= $this->icon('plus', 'ic ic--sm') ?> Add rule
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
(function () {
  const button = document.querySelector('[data-use-my-ip]');
  const input  = document.getElementById('ip_address');

  if (button && input) {
    button.addEventListener('click', function () {
      input.value = <?= $this->json($currentIp) ?>;
      input.focus();
    });
  }
})();
</script>
<?php $this->stop(); ?>

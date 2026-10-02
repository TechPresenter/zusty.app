<?php

declare(strict_types=1);

/**
 * Admin's own account: profile, sessions, permissions, recent activity.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $profile
 * @var list<array<string,mixed>> $sessions
 * @var string $currentSession
 * @var list<array<string,mixed>> $recentActivity
 * @var list<string> $permissions
 * @var list<string> $avatarTypes    Extensions UploadService accepts for an image.
 * @var int          $avatarMaxBytes
 */

$this->extend('admin.layouts.app');
$this->start('content');

?>

<div class="grid grid--sidebar">

  <div class="col gap-4">

    <!-- Profile -->
    <div class="card">
      <div class="card__head"><h3 class="h3">Profile</h3></div>
      <div class="card__body">
        <?php
          $hasAvatar  = !empty($profile['avatar']);
          $avatarSize = 'width:72px;height:72px;font-size:24px';
          $typeLabels = array_unique(array_map(
              static fn (string $type): string => strtoupper($type === 'jpeg' ? 'jpg' : $type),
              $avatarTypes
          ));
        ?>
        <div class="row gap-4 mb-4" style="align-items:center;flex-wrap:wrap">
          <?php if ($hasAvatar): ?>
            <img class="avatar avatar--lg" id="avatar-preview" style="<?= $avatarSize ?>"
                 src="<?= $this->e($profile['avatar']) ?>" alt="Your profile photo">
          <?php else: ?>
            <div class="avatar avatar--lg avatar--fallback" id="avatar-preview" style="<?= $avatarSize ?>">
              <?= $this->e($this->initials((string) $profile['name'])) ?>
            </div>
          <?php endif; ?>

          <div class="col gap-2">
            <div class="row gap-2" style="flex-wrap:wrap">
              <form method="post" action="/admin/account/avatar" enctype="multipart/form-data" id="avatar-form">
                <?= $this->csrf() ?>
                <input type="file" name="avatar" id="avatar-input" hidden
                       accept="<?= $this->e(implode(',', array_map(static fn (string $type): string => '.' . $type, $avatarTypes))) ?>">
                <button type="button" class="btn btn--outline btn--sm" id="avatar-pick">
                  <?= $this->icon('upload', 'ic ic--sm') ?> <?= $hasAvatar ? 'Change photo' : 'Upload photo' ?>
                </button>
              </form>

              <?php if ($hasAvatar): ?>
                <form method="post" action="/admin/account/avatar/delete">
                  <?= $this->csrf() ?>
                  <button type="submit" class="btn btn--ghost btn--sm text-error"
                          data-confirm="Your initials will show in its place."
                          data-confirm-title="Remove your profile photo?" data-confirm-label="Remove">
                    <?= $this->icon('trash', 'ic ic--sm') ?> Remove
                  </button>
                </form>
              <?php endif; ?>
            </div>
            <p class="field__hint mb-0">
              <?= $this->e(implode(', ', $typeLabels)) ?> · up to <?= (int) round($avatarMaxBytes / 1048576) ?> MB.
              A square photo looks best.
            </p>
          </div>
        </div>

        <form method="post" action="/admin/account">
          <?= $this->csrf() ?>

          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="field__label" for="name">Full name <span class="req">*</span></label>
              <input class="input" id="name" name="name" required maxlength="120"
                     value="<?= $this->e($profile['name']) ?>">
            </div>

            <div class="field">
              <label class="field__label" for="email">Email address <span class="req">*</span></label>
              <input class="input" id="email" name="email" type="email" required maxlength="191"
                     value="<?= $this->e($profile['email']) ?>">
            </div>

            <div class="field">
              <label class="field__label" for="phone">Phone</label>
              <input class="input" id="phone" name="phone" type="tel" maxlength="20"
                     value="<?= $this->e($profile['phone'] ?? '') ?>" placeholder="+91 90000 00000">
            </div>

            <div class="field">
              <label class="field__label">Role</label>
              <input class="input" value="<?= $this->e($profile['role_name']) ?>" disabled>
              <p class="field__hint">Only a Super Admin can change your role.</p>
            </div>
          </div>

          <button type="submit" class="btn btn--primary">Save profile</button>
        </form>
      </div>
    </div>

    <!-- Sessions -->
    <div class="card">
      <div class="card__head">
        <div>
          <h3 class="h3">Active sessions</h3>
          <p class="tiny muted">Sign out anywhere you do not recognise</p>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Device</th><th>IP address</th><th>Last active</th><th class="text-right">Action</th></tr></thead>
          <tbody>
            <?php foreach ($sessions as $session): ?>
              <?php $isCurrent = (string) $session['id'] === $currentSession; ?>
              <tr>
                <td class="small truncate" style="max-width:320px">
                  <?= $this->e($session['user_agent'] ?: 'Unknown device') ?>
                  <?php if ($isCurrent): ?>
                    <span class="chip chip--success" style="margin-left:6px">This device</span>
                  <?php endif; ?>
                </td>
                <td class="small mono muted"><?= $this->e($session['ip_address'] ?: '—') ?></td>
                <td class="small muted"><?= $this->timeAgo($session['last_activity']) ?></td>
                <td class="actions">
                  <?php if (!$isCurrent): ?>
                    <form method="post" action="/admin/account/sessions/<?= $this->e($session['id']) ?>/revoke" style="display:inline">
                      <?= $this->csrf() ?>
                      <button type="submit" class="btn btn--ghost btn--sm text-error"
                              data-confirm="That device will need to sign in again."
                              data-confirm-title="Revoke this session?" data-confirm-label="Revoke">
                        Revoke
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="tiny faint">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Activity -->
    <div class="card">
      <div class="card__head"><h3 class="h3">Your recent activity</h3></div>
      <?php if ($recentActivity === []): ?>
        <div class="empty" style="padding:32px"><p class="empty__text mb-0">Nothing recorded yet.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Action</th><th>Subject</th><th>Description</th><th>When</th></tr></thead>
            <tbody>
              <?php foreach ($recentActivity as $entry): ?>
                <tr>
                  <td class="small mono"><?= $this->e($entry['action']) ?></td>
                  <td class="small muted">
                    <?= $this->e($entry['subject_type'] ?? '—') ?>
                    <?= $entry['subject_id'] ? '#' . (int) $entry['subject_id'] : '' ?>
                  </td>
                  <td class="small truncate" style="max-width:280px"><?= $this->e($entry['description'] ?? '') ?></td>
                  <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($entry['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Sidebar -->
  <div class="col gap-4">

    <div class="card">
      <div class="card__head"><h3 class="h3">Security</h3></div>
      <div class="card__body col gap-3">
        <div>
          <div class="label mb-1">Password last changed</div>
          <div class="small"><?= $profile['password_changed_at'] ? $this->date($profile['password_changed_at']) : 'Never' ?></div>
        </div>
        <div>
          <div class="label mb-1">Last sign-in</div>
          <div class="small"><?= $this->dateTime($profile['last_login_at']) ?></div>
        </div>
        <a href="/admin/account/password" class="btn btn--primary btn--block mt-2">
          <?= $this->icon('key', 'ic ic--sm') ?> Change password
        </a>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <h3 class="h3">Your permissions</h3>
        <span class="chip chip--neutral"><?= count($permissions) ?></span>
      </div>
      <div class="card__body">
        <div class="chip-row">
          <?php foreach ($permissions as $permission): ?>
            <span class="chip chip--neutral" style="font-weight:500"><?= $this->e($permission) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
(() => {
  const form = document.getElementById('avatar-form');
  if (!form) return;

  const input    = document.getElementById('avatar-input');
  const pick     = document.getElementById('avatar-pick');
  const allowed  = <?= json_encode(array_values($avatarTypes), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const maxBytes = <?= (int) $avatarMaxBytes ?>;

  pick.addEventListener('click', () => input.click());

  input.addEventListener('change', () => {
    const file = input.files[0];
    if (!file) return;

    // The server checks all of this again. Catching it here saves a round
    // trip and gives a clear message instead of a rejected upload.
    const extension = (file.name.split('.').pop() || '').toLowerCase();

    if (!allowed.includes(extension)) {
      input.value = '';
      Admin.toast('Choose a ' + allowed.join(', ').toUpperCase() + ' image.', 'error');
      return;
    }

    if (file.size > maxBytes) {
      input.value = '';
      Admin.toast('That image is larger than ' + Math.round(maxBytes / 1048576) + ' MB.', 'error');
      return;
    }

    // Show the chosen photo straight away; the page reloads with the stored copy.
    const current = document.getElementById('avatar-preview');
    const preview = document.createElement('img');

    preview.id            = 'avatar-preview';
    preview.className     = 'avatar avatar--lg';
    preview.style.cssText = current.style.cssText;
    preview.alt           = '';
    preview.src           = URL.createObjectURL(file);
    current.replaceWith(preview);

    pick.classList.add('btn--loading');
    form.submit();
  });
})();
</script>
<?php $this->stop(); ?>

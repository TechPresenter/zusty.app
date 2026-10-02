<?php

declare(strict_types=1);

/**
 * Admin accounts.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $admins
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var list<array<string,mixed>> $roles
 * @var int $myLevel
 */

$this->extend('admin.layouts.app');
$this->start('content');

$canManage = $this->can('admins.manage');

$roleOptions = ['' => 'Any role'];
foreach ($roles as $role) {
    $roleOptions[(string) $role['id']] = (string) $role['name'];
}

// Set by AccessController::storeAdmin only when the invite email could not be
// delivered. Flash data survives exactly one redirect, so this is a genuine
// one-time hand-off rather than a credential parked in the session.
$tempCredential = \Zuste\Core\Session::getFlash('admin_temp_password');

?>

<?php if (is_array($tempCredential)): ?>
  <div class="callout callout--warning mb-3">
    <?= $this->icon('alert-triangle', 'ic ic--sm') ?>
    <div class="flex-1">
      <strong>The invite email could not be delivered.</strong>
      <div class="small mt-1">
        Give <?= $this->e((string) $tempCredential['name']) ?>
        (<?= $this->e((string) $tempCredential['email']) ?>) this temporary password over a channel you
        trust. They will be forced to change it at first sign-in. It is shown only once — reloading this
        page clears it, and you would then have to delete the account and create it again.
      </div>
      <div class="row gap-2 mt-2">
        <code class="mono" style="word-break:break-all;flex:1"><?= $this->e((string) $tempCredential['password']) ?></code>
        <button type="button" class="btn btn--ghost btn--icon btn--sm"
                data-copy="<?= $this->e((string) $tempCredential['password']) ?>" aria-label="Copy temporary password">
          <?= $this->icon('file', 'ic ic--sm') ?>
        </button>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--sidebar">

  <div class="card">
    <?php $this->partial('admin.partials.toolbar', [
        'filters'           => $filters,
        'searchPlaceholder' => 'Search by name or email…',
        'selects'           => [
            ['name' => 'role_id', 'label' => 'Role', 'options' => $roleOptions],
            ['name' => 'status', 'label' => 'Status', 'options' => [
                '' => 'Any status', 'active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended',
            ]],
        ],
    ]); ?>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Admin</th>
            <th>Role</th>
            <th>Status</th>
            <th>Last sign-in</th>
            <th class="text-right">Sessions</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($admins as $admin): ?>
            <?php $isAbove = (int) $admin['role_level'] >= $myLevel; ?>
            <tr>
              <td>
                <div class="cell-user">
                  <?php if (!empty($admin['avatar'])): ?>
                    <img class="avatar avatar--sm" src="<?= $this->e($admin['avatar']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="avatar avatar--sm avatar--fallback"><?= $this->e($this->initials((string) $admin['name'])) ?></div>
                  <?php endif; ?>
                  <div class="flex-1 truncate">
                    <div class="cell-user__name truncate"><?= $this->e($admin['name']) ?></div>
                    <div class="cell-user__meta truncate"><?= $this->e($admin['email']) ?></div>
                  </div>
                  <?php if ((int) $admin['must_change_password'] === 1): ?>
                    <span class="chip chip--warning" title="Must set a new password on next sign-in">New</span>
                  <?php endif; ?>
                </div>
              </td>

              <td>
                <span class="chip chip--<?= (int) $admin['role_level'] >= 100 ? 'primary' : 'neutral' ?>">
                  <?= $this->e($admin['role_name']) ?>
                </span>
                <div class="tiny faint mt-1">Level <?= (int) $admin['role_level'] ?></div>
              </td>

              <td>
                <span class="chip chip--<?= $admin['status'] === 'active' ? 'success' : ($admin['status'] === 'suspended' ? 'error' : 'neutral') ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst((string) $admin['status'])) ?>
                </span>
              </td>

              <td class="small muted" style="white-space:nowrap">
                <?= $admin['last_login_at'] ? $this->timeAgo($admin['last_login_at']) : 'Never' ?>
                <?php if (!empty($admin['last_login_ip'])): ?>
                  <div class="tiny faint mono"><?= $this->e($admin['last_login_ip']) ?></div>
                <?php endif; ?>
              </td>

              <td class="text-right">
                <?php if ((int) $admin['active_sessions'] > 0): ?>
                  <span class="chip chip--success"><span class="chip__dot"></span><?= (int) $admin['active_sessions'] ?> online</span>
                <?php else: ?>
                  <span class="tiny faint">—</span>
                <?php endif; ?>
              </td>

              <td class="actions">
                <?php if ($canManage && !$isAbove): ?>
                  <div class="row gap-2 row--end">
                    <button type="button" class="btn btn--outline btn--sm"
                            onclick="editAdmin(<?= $this->json([
                                'id'      => (int) $admin['id'],
                                'name'    => (string) $admin['name'],
                                'email'   => (string) $admin['email'],
                                'phone'   => (string) ($admin['phone'] ?? ''),
                                'role_id' => (int) $admin['role_id'],
                                'status'  => (string) $admin['status'],
                            ]) ?>)">
                      <?= $this->icon('edit', 'ic ic--sm') ?> Edit
                    </button>

                    <form method="post" action="/admin/admins/<?= (int) $admin['id'] ?>/delete" style="display:inline">
                      <?= $this->csrf() ?>
                      <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error" aria-label="Remove"
                              data-confirm="The account is disabled and every session is terminated."
                              data-confirm-title="Remove this admin?" data-confirm-label="Remove">
                        <?= $this->icon('trash', 'ic ic--sm') ?>
                      </button>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="tiny faint"><?= $isAbove ? 'Above your level' : 'Read only' ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  </div>

  <!-- Create / edit sidebar -->
  <div class="col gap-4">
    <?php if ($canManage): ?>
      <div class="card">
        <div class="card__head">
          <h3 class="h3" id="admin-form-title">Invite an admin</h3>
        </div>
        <div class="card__body">
          <form method="post" action="/admin/admins" id="admin-form">
            <?= $this->csrf() ?>

            <div class="field">
              <label class="field__label" for="admin_name">Full name <span class="req">*</span></label>
              <input class="input" name="name" id="admin_name" required maxlength="120">
            </div>

            <div class="field">
              <label class="field__label" for="admin_email">Email <span class="req">*</span></label>
              <input class="input" type="email" name="email" id="admin_email" required maxlength="191">
            </div>

            <div class="field">
              <label class="field__label" for="admin_phone">Phone</label>
              <input class="input" type="tel" name="phone" id="admin_phone" maxlength="20">
            </div>

            <div class="field">
              <label class="field__label" for="admin_role">Role <span class="req">*</span></label>
              <select class="select" name="role_id" id="admin_role" required>
                <?php foreach ($roles as $role): ?>
                  <option value="<?= (int) $role['id'] ?>"><?= $this->e($role['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="field__hint">You can only assign roles below your own level.</p>
            </div>

            <div class="field hidden" id="admin-status-field">
              <label class="field__label" for="admin_status">Status</label>
              <select class="select" name="status" id="admin_status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
              </select>
            </div>

            <button type="submit" class="btn btn--primary btn--block" id="admin-submit">
              <?= $this->icon('plus', 'ic ic--sm') ?> Send invitation
            </button>

            <button type="button" class="btn btn--ghost btn--block mt-2 hidden" id="admin-cancel" onclick="resetAdminForm()">
              Cancel
            </button>
          </form>

          <p class="tiny faint mt-4">
            A temporary password is generated and emailed. The new admin must change it before they can
            use the panel.
          </p>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><h3 class="h3">Role hierarchy</h3></div>
      <div class="card__body col gap-3">
        <?php foreach ($roles as $role): ?>
          <div class="row row--between">
            <span class="small"><?= $this->e($role['name']) ?></span>
            <span class="chip chip--neutral">Level <?= (int) $role['level'] ?></span>
          </div>
        <?php endforeach; ?>
        <p class="tiny faint mt-2">
          An admin can only manage accounts and assign roles strictly below their own level.
        </p>
      </div>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
function editAdmin(admin) {
  const form = document.getElementById('admin-form');
  form.action = '/admin/admins/' + admin.id;

  document.getElementById('admin-form-title').textContent = 'Edit ' + admin.name;
  document.getElementById('admin_name').value = admin.name;
  document.getElementById('admin_email').value = admin.email;
  document.getElementById('admin_phone').value = admin.phone;
  document.getElementById('admin_role').value = admin.role_id;
  document.getElementById('admin_status').value = admin.status;

  document.getElementById('admin-status-field').classList.remove('hidden');
  document.getElementById('admin-cancel').classList.remove('hidden');
  document.getElementById('admin-submit').innerHTML = 'Save changes';

  form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  document.getElementById('admin_name').focus();
}

function resetAdminForm() {
  const form = document.getElementById('admin-form');
  form.reset();
  form.action = '/admin/admins';

  document.getElementById('admin-form-title').textContent = 'Invite an admin';
  document.getElementById('admin-status-field').classList.add('hidden');
  document.getElementById('admin-cancel').classList.add('hidden');
  document.getElementById('admin-submit').innerHTML = 'Send invitation';
}
</script>
<?php $this->stop(); ?>

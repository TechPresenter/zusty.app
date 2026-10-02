<?php

declare(strict_types=1);

/**
 * Roles and the permission matrix.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $roles
 * @var array<string,list<array<string,mixed>>> $grouped
 * @var array<int,list<int>> $assigned
 * @var int $myLevel
 */

$this->extend('admin.layouts.app');
$this->start('content');

$totalPermissions = 0;
foreach ($grouped as $permissions) {
    $totalPermissions += count($permissions);
}

?>

<div class="card mb-5" style="border-color:var(--secondary);background:color-mix(in srgb, var(--secondary) 6%, transparent)">
  <div class="card__body row gap-3" style="padding:14px 18px">
    <span class="text-secondary"><?= $this->icon('key') ?></span>
    <p class="small">
      A role is a bundle of permissions. Super Admin (level 100) always holds every permission and cannot
      be edited. You can only view or change roles below your own level (<?= (int) $myLevel ?>).
      Individual overrides on a single admin take precedence, and a <em>deny</em> always wins.
    </p>
  </div>
</div>

<div class="col gap-4">
  <?php foreach ($roles as $role): ?>
    <?php
    $roleId     = (int) $role['id'];
    $level      = (int) $role['level'];
    $isEditable = $level < $myLevel && (int) $role['is_system'] !== 1 || ($level < $myLevel && $level < 100);
    $held       = $assigned[$roleId] ?? [];
    $isSuper    = $level >= 100;
    ?>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="row gap-2 row--wrap">
            <h3 class="h3"><?= $this->e($role['name']) ?></h3>
            <span class="chip chip--<?= $isSuper ? 'primary' : 'neutral' ?>">Level <?= $level ?></span>
            <?php if ((int) $role['is_system'] === 1): ?>
              <span class="chip chip--neutral">System</span>
            <?php endif; ?>
          </div>
          <p class="tiny muted mt-1"><?= $this->e($role['description'] ?? '') ?></p>
        </div>

        <div class="row gap-3">
          <div class="text-right">
            <div class="label">Admins</div>
            <div class="small mono" style="font-weight:700"><?= (int) $role['admin_count'] ?></div>
          </div>
          <div class="text-right">
            <div class="label">Permissions</div>
            <div class="small mono" style="font-weight:700">
              <?= $isSuper ? $totalPermissions : (int) $role['permission_count'] ?> / <?= $totalPermissions ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card__body">
        <?php if ($isSuper): ?>
          <p class="small muted">
            Super Admin holds every permission implicitly and is not editable — this guarantees the
            platform can never be locked out of itself.
          </p>
        <?php elseif ($level >= $myLevel): ?>
          <p class="small muted">This role is at or above your own level, so it is read-only for you.</p>
        <?php else: ?>
          <form method="post" action="/admin/roles/<?= $roleId ?>">
            <?= $this->csrf() ?>

            <div class="form-grid form-grid--2 mb-4">
              <div class="field">
                <label class="field__label" for="role_<?= $roleId ?>_name">Name</label>
                <input class="input" id="role_<?= $roleId ?>_name" name="name" required maxlength="64"
                       value="<?= $this->e($role['name']) ?>">
              </div>
              <div class="field">
                <label class="field__label" for="role_<?= $roleId ?>_description">Description</label>
                <input class="input" id="role_<?= $roleId ?>_description" name="description" maxlength="255"
                       value="<?= $this->e($role['description'] ?? '') ?>">
              </div>
            </div>

            <?php foreach ($grouped as $groupName => $permissions): ?>
              <div class="mb-4">
                <div class="row row--between mb-2">
                  <div class="label"><?= $this->e($groupName) ?></div>
                  <button type="button" class="tiny text-secondary" style="font-weight:600"
                          onclick="toggleGroup(this, <?= $roleId ?>, '<?= $this->e($groupName) ?>')">
                    Toggle all
                  </button>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px">
                  <?php foreach ($permissions as $permission): ?>
                    <label class="checkbox small" style="padding:7px 10px;border-radius:var(--r-md);background:var(--surface-low)"
                           title="<?= $this->e($permission['description']) ?>">
                      <input type="checkbox" name="permissions[]" value="<?= (int) $permission['id'] ?>"
                             data-role="<?= $roleId ?>" data-group="<?= $this->e($groupName) ?>"
                             <?= in_array((int) $permission['id'], $held, true) ? 'checked' : '' ?>>
                      <span class="truncate"><?= $this->e($permission['name']) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>

            <div class="row gap-2">
              <button type="submit" class="btn btn--primary">
                <?= $this->icon('check', 'ic ic--sm') ?> Save <?= $this->e($role['name']) ?>
              </button>

              <?php if ((int) $role['is_system'] !== 1 && (int) $role['admin_count'] === 0): ?>
                <button type="submit" class="btn btn--ghost text-error"
                        formaction="/admin/roles/<?= $roleId ?>/delete" formnovalidate
                        data-confirm="This role has no admins assigned and will be permanently removed."
                        data-confirm-title="Delete this role?" data-confirm-label="Delete">
                  <?= $this->icon('trash', 'ic ic--sm') ?> Delete role
                </button>
              <?php endif; ?>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <!-- Create a role -->
  <div class="card">
    <div class="card__head"><h3 class="h3">Create a role</h3></div>
    <div class="card__body">
      <form method="post" action="/admin/roles">
        <?= $this->csrf() ?>

        <div class="form-grid">
          <div class="field">
            <label class="field__label" for="new_role_name">Name <span class="req">*</span></label>
            <input class="input" name="name" id="new_role_name" required maxlength="64" placeholder="e.g. Regional Manager">
          </div>

          <div class="field">
            <label class="field__label" for="new_role_level">Level <span class="req">*</span></label>
            <input class="input" type="number" name="level" id="new_role_level" required
                   min="1" max="<?= max(1, $myLevel - 1) ?>" value="<?= max(1, min(50, $myLevel - 1)) ?>">
            <p class="field__hint">Must be below your own level (<?= (int) $myLevel ?>).</p>
          </div>

          <div class="field">
            <label class="field__label" for="new_role_description">Description</label>
            <input class="input" name="description" id="new_role_description" maxlength="255">
          </div>
        </div>

        <details class="mb-4">
          <summary class="small text-secondary" style="cursor:pointer;font-weight:600">Choose permissions</summary>

          <div class="mt-3">
            <?php foreach ($grouped as $groupName => $permissions): ?>
              <div class="mb-3">
                <div class="label mb-2"><?= $this->e($groupName) ?></div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px">
                  <?php foreach ($permissions as $permission): ?>
                    <label class="checkbox small" style="padding:7px 10px;border-radius:var(--r-md);background:var(--surface-low)">
                      <input type="checkbox" name="permissions[]" value="<?= (int) $permission['id'] ?>">
                      <span class="truncate"><?= $this->e($permission['name']) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </details>

        <button type="submit" class="btn btn--secondary">
          <?= $this->icon('plus', 'ic ic--sm') ?> Create role
        </button>
      </form>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
function toggleGroup(button, roleId, groupName) {
  const boxes = document.querySelectorAll(
    'input[data-role="' + roleId + '"][data-group="' + groupName + '"]'
  );
  const allChecked = Array.from(boxes).every(function (b) { return b.checked; });

  boxes.forEach(function (b) { b.checked = !allChecked; });
  button.textContent = allChecked ? 'Select all' : 'Clear all';
}
</script>
<?php $this->stop(); ?>

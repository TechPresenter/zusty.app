<?php

declare(strict_types=1);

/**
 * Change password.
 *
 * @var \Zuste\Core\View $this
 * @var bool $forced  True when the admin must change their password before continuing
 */

$this->extend('admin.layouts.app');
$this->start('content');

?>

<div style="max-width:520px">

  <?php if ($forced): ?>
    <div class="card mb-5" style="border-color:var(--warning);background:var(--warning-container)">
      <div class="card__body row gap-3" style="padding:14px 16px">
        <span style="color:var(--warning)"><?= $this->icon('alert') ?></span>
        <p class="small" style="color:var(--warning);font-weight:500">
          You must set a new password before you can use the admin panel.
        </p>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card__head">
      <div>
        <h3 class="h3">Change password</h3>
        <p class="tiny muted">All other sessions will be signed out</p>
      </div>
    </div>

    <div class="card__body">
      <form method="post" action="/admin/account/password">
        <?= $this->csrf() ?>

        <div class="field">
          <label class="field__label" for="current_password">Current password <span class="req">*</span></label>
          <div class="input-group">
            <span class="input-group__icon"><?= $this->icon('key', 'ic ic--sm') ?></span>
            <input class="input" id="current_password" name="current_password" type="password"
                   required autocomplete="current-password">
            <button type="button" class="btn btn--ghost btn--icon btn--sm input-group__action"
                    data-toggle-password="#current_password" aria-label="Show password">
              <?= $this->icon('eye', 'ic ic--sm') ?>
            </button>
          </div>
        </div>

        <div class="field">
          <label class="field__label" for="password">New password <span class="req">*</span></label>
          <div class="input-group">
            <span class="input-group__icon"><?= $this->icon('shield', 'ic ic--sm') ?></span>
            <input class="input" id="password" name="password" type="password" required
                   autocomplete="new-password" minlength="8">
            <button type="button" class="btn btn--ghost btn--icon btn--sm input-group__action"
                    data-toggle-password="#password" aria-label="Show password">
              <?= $this->icon('eye', 'ic ic--sm') ?>
            </button>
          </div>
          <p class="field__hint">
            At least 8 characters, including an uppercase letter, a lowercase letter and a number.
          </p>
        </div>

        <div class="field">
          <label class="field__label" for="password_confirmation">Confirm new password <span class="req">*</span></label>
          <div class="input-group">
            <span class="input-group__icon"><?= $this->icon('check', 'ic ic--sm') ?></span>
            <input class="input" id="password_confirmation" name="password_confirmation" type="password"
                   required autocomplete="new-password" minlength="8">
          </div>
        </div>

        <div class="row gap-3 mt-5">
          <button type="submit" class="btn btn--primary">Update password</button>
          <?php if (!$forced): ?>
            <a href="/admin/account" class="btn btn--ghost">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

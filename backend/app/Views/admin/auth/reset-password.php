<?php

declare(strict_types=1);

/**
 * Set a new admin password from an emailed reset link.
 *
 * @var \Zuste\Core\View $this
 * @var string $token
 */

$appName = \Zuste\Core\Config::settingString('general', 'app_name', 'Zusty');
$assetVersion = $this->e((string) \Zuste\Core\Config::get('app.version', '1.0.0'));

?><!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
<title>Set a new password · <?= $this->e($appName) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/css/admin.css?v=<?= $assetVersion ?>">
<link rel="icon" type="image/png" sizes="64x64" href="/admin/assets/img/favicon.png?v=<?= $assetVersion ?>">
<link rel="apple-touch-icon" href="/admin/assets/img/apple-touch-icon.png?v=<?= $assetVersion ?>">
</head>
<body>

<main style="min-height:100vh;display:grid;place-items:center;padding:24px">
  <div class="auth__card slide-up">

    <div class="text-center mb-6">
      <img src="/admin/assets/img/zusty-logo.png?v=<?= $assetVersion ?>" width="166" height="36" alt="<?= $this->e($appName) ?>" style="display:block;margin:0 auto">
    </div>

    <div class="card">
      <div class="card__body" style="padding:32px">

        <?php if (trim($token) === ''): ?>
          <div class="empty" style="padding:8px 0 0">
            <div class="empty__art" style="width:72px;height:72px"><?= $this->icon('alert', 'ic ic--lg') ?></div>
            <p class="empty__title">Link is incomplete</p>
            <p class="empty__text">
              This reset link is missing its token. Request a new one and use the most recent email.
            </p>
            <a href="/admin/forgot-password" class="btn btn--primary">Request a new link</a>
          </div>
        <?php else: ?>
          <h1 class="h1 mb-2">Set a new password</h1>
          <p class="muted small mb-6">Choose a strong password you have not used before.</p>

          <form method="post" action="/admin/reset-password">
            <?= $this->csrf() ?>
            <input type="hidden" name="token" value="<?= $this->e($token) ?>">

            <div class="field">
              <label class="field__label" for="password">New password <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group__icon"><?= $this->icon('shield', 'ic ic--sm') ?></span>
                <input class="input" id="password" name="password" type="password" required autofocus
                       minlength="8" autocomplete="new-password"
                       <?= $this->hasError('password') ? 'aria-invalid="true"' : '' ?>>
                <button type="button" class="btn btn--ghost btn--icon btn--sm input-group__action"
                        data-toggle-password="#password" aria-label="Show password">
                  <?= $this->icon('eye', 'ic ic--sm') ?>
                </button>
              </div>
              <?php if ($this->hasError('password')): ?>
                <p class="field__error"><?= $this->error('password') ?></p>
              <?php else: ?>
                <p class="field__hint">
                  At least 8 characters, including an uppercase letter, a lowercase letter and a number.
                </p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="field__label" for="password_confirmation">Confirm password <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group__icon"><?= $this->icon('check', 'ic ic--sm') ?></span>
                <input class="input" id="password_confirmation" name="password_confirmation" type="password"
                       required minlength="8" autocomplete="new-password">
              </div>
            </div>

            <button type="submit" class="btn btn--primary btn--lg btn--block mt-5">Update password</button>
          </form>

          <p class="text-center mt-5">
            <a href="/admin/login" class="small text-secondary" style="font-weight:600">Back to sign in</a>
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<div class="toasts" role="status" aria-live="polite"></div>

<?php foreach (\Zuste\Core\Session::toasts() as $toast): ?>
  <span data-flash="<?= $this->e($toast['message']) ?>" data-flash-type="<?= $this->e($toast['type']) ?>" hidden></span>
<?php endforeach; ?>

<script src="/admin/assets/js/admin.js"></script>
</body>
</html>

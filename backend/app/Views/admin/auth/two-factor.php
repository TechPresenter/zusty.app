<?php

declare(strict_types=1);

/**
 * The second factor.
 *
 * Reached only after a correct password on an account with 2FA enabled. The
 * visitor is NOT an authenticated admin at this point — the identity sits in a
 * short-lived pending slot, and no admin session exists until this form is
 * answered correctly.
 *
 * @var \Zuste\Core\View $this
 * @var string|null      $error
 * @var int              $remaining unused recovery codes
 */

$appName = \Zuste\Core\Config::settingString('general', 'app_name', 'Zusty');
$tagline = \Zuste\Core\Config::settingString('general', 'app_tagline', 'Shoot. Connect. Grow.');
$assetVersion = $this->e((string) \Zuste\Core\Config::get('app.version', '1.0.0'));

?><!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
<title>Two-factor authentication · <?= $this->e($appName) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/css/admin.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<link rel="icon" type="image/png" sizes="64x64" href="/admin/assets/img/favicon.png?v=<?= $assetVersion ?>">
<link rel="apple-touch-icon" href="/admin/assets/img/apple-touch-icon.png?v=<?= $assetVersion ?>">
</head>
<body>

<div class="auth">

  <aside class="auth__aside">
    <div>
      <div class="auth__logo"><img src="/admin/assets/img/zusty-logo.png?v=<?= $assetVersion ?>" width="166" height="36" alt="<?= $this->e($appName) ?>"></div>
      <div style="font-size:11px;letter-spacing:.2em;text-transform:uppercase;opacity:.8;font-weight:600;margin-top:14px">
        <?= $this->e($tagline) ?>
      </div>
    </div>

    <div style="max-width:420px">
      <h2 style="font-size:30px;font-weight:800;line-height:1.2;letter-spacing:-.02em;margin-bottom:14px">
        One more step.
      </h2>
      <p style="font-size:15px;line-height:1.6;opacity:.9">
        Your password was accepted. Enter the code from your authenticator app to
        finish signing in.
      </p>
    </div>

    <p style="font-size:12px;opacity:.75">
      &copy; <?= date('Y') ?> <?= $this->e(\Zuste\Core\Config::settingString('general', 'company_name', 'Zuste Technologies')) ?>
    </p>
  </aside>

  <main class="auth__panel">
    <div class="auth__card slide-up">

      <img class="auth__card-logo" src="/admin/assets/img/zusty-logo.png?v=<?= $assetVersion ?>" width="148" height="32" alt="<?= $this->e($appName) ?>">

      <div class="mb-6">
        <h1 class="display mb-2">Two-factor authentication</h1>
        <p class="muted">Enter the 6-digit code from your authenticator app.</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="card mb-5" style="border-color:var(--error);background:var(--error-container)">
          <div class="card__body row gap-3" style="padding:14px 16px">
            <span class="text-error"><?= $this->icon('alert') ?></span>
            <p class="small" style="color:var(--on-error-container);font-weight:500"><?= $this->e($error) ?></p>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" action="/admin/login/two-factor" novalidate>
        <?= $this->csrf() ?>

        <div class="field">
          <label class="field__label" for="code">Authentication code <span class="req">*</span></label>
          <div class="input-group">
            <span class="input-group__icon"><?= $this->icon('shield', 'ic ic--sm') ?></span>
            <input
              class="input"
              type="text"
              id="code"
              name="code"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="32"
              placeholder="123456"
              required
              autofocus
              style="letter-spacing:.35em;font-weight:600">
          </div>
          <p class="field__hint">
            You can also use one of your recovery codes.
            <?php if ($remaining > 0): ?>
              You have <?= (int) $remaining ?> left.
            <?php else: ?>
              <strong>You have none left</strong> — generate new ones after signing in.
            <?php endif; ?>
          </p>
        </div>

        <button class="btn btn--primary btn--block mt-4" type="submit">
          Verify and sign in
        </button>
      </form>

      <p class="small muted mt-5" style="text-align:center">
        Lost your device and your recovery codes? A Super Admin can disable
        two-factor authentication on your account.
      </p>

      <p class="small mt-3" style="text-align:center">
        <a href="/admin/login">Back to sign in</a>
      </p>

    </div>
  </main>

</div>

</body>
</html>

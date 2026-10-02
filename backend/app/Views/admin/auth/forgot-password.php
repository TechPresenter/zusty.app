<?php

declare(strict_types=1);

/**
 * Request an admin password reset link.
 *
 * @var \Zuste\Core\View $this
 * @var bool $sent
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
<title>Reset password · <?= $this->e($appName) ?> Admin</title>
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

        <?php if ($sent): ?>
          <div class="empty" style="padding:8px 0 0">
            <div class="empty__art" style="width:72px;height:72px"><?= $this->icon('inbox', 'ic ic--lg') ?></div>
            <p class="empty__title">Check your inbox</p>
            <p class="empty__text">
              If that address belongs to an admin account, a reset link is on its way.
              The link is valid for one hour.
            </p>
            <a href="/admin/login" class="btn btn--primary">Back to sign in</a>
          </div>
        <?php else: ?>
          <h1 class="h1 mb-2">Forgot your password?</h1>
          <p class="muted small mb-6">
            Enter the email address on your admin account and we will send you a reset link.
          </p>

          <form method="post" action="/admin/forgot-password">
            <?= $this->csrf() ?>

            <div class="field">
              <label class="field__label" for="email">Email address <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group__icon"><?= $this->icon('users', 'ic ic--sm') ?></span>
                <input class="input" id="email" name="email" type="email" required autofocus
                       autocomplete="username" placeholder="you@zustee.com">
              </div>
            </div>

            <button type="submit" class="btn btn--primary btn--lg btn--block mt-5">Send reset link</button>
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

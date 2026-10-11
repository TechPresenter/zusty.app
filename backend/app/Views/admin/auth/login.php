<?php

declare(strict_types=1);

/**
 * Admin sign-in.
 *
 * @var \Zuste\Core\View $this
 * @var string|null      $error
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
<title>Sign in · <?= $this->e($appName) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/css/admin.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<link rel="icon" type="image/png" sizes="64x64" href="/admin/assets/img/favicon.png?v=<?= $assetVersion ?>">
<link rel="apple-touch-icon" href="/admin/assets/img/apple-touch-icon.png?v=<?= $assetVersion ?>">
</head>
<body>

<div class="auth">

  <!-- Brand panel -->
  <aside class="auth__aside">
    <div>
      <div class="auth__logo"><img src="/admin/assets/img/zusty-logo.png?v=<?= $assetVersion ?>" width="166" height="36" alt="<?= $this->e($appName) ?>"></div>
      <div style="font-size:11px;letter-spacing:.2em;text-transform:uppercase;opacity:.8;font-weight:600;margin-top:14px">
        <?= $this->e($tagline) ?>
      </div>
    </div>

    <div style="max-width:420px">
      <h2 style="font-size:30px;font-weight:800;line-height:1.2;letter-spacing:-.02em;margin-bottom:14px">
        The control room for your creator marketplace.
      </h2>
      <p style="font-size:15px;line-height:1.6;opacity:.9">
        Approve creators, arbitrate bookings, release payouts and publish content — all from one place.
      </p>

      <div style="display:flex;gap:28px;margin-top:36px">
        <?php foreach ([
            ['Verified creators', 'shield'],
            ['Payment protection', 'wallet'],
            ['Full audit trail', 'file'],
        ] as [$label, $icon]): ?>
          <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-start">
            <div style="width:38px;height:38px;border-radius:12px;background:rgba(255,255,255,.16);display:grid;place-items:center">
              <?= $this->icon($icon) ?>
            </div>
            <span style="font-size:12px;font-weight:600;opacity:.92"><?= $this->e($label) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <p style="font-size:12px;opacity:.75">
      &copy; <?= date('Y') ?> <?= $this->e(trim(\Zuste\Core\Config::settingString('general', 'company_name', '')) ?: $appName) ?>
    </p>
  </aside>

  <!-- Form panel -->
  <main class="auth__panel">
    <div class="auth__card slide-up">

      <img class="auth__card-logo" src="/admin/assets/img/zusty-logo.png?v=<?= $assetVersion ?>" width="148" height="32" alt="<?= $this->e($appName) ?>">

      <div class="mb-6">
        <h1 class="display mb-2">Welcome back</h1>
        <p class="muted">Sign in to the <?= $this->e($appName) ?> admin panel.</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="card mb-5" style="border-color:var(--error);background:var(--error-container)">
          <div class="card__body row gap-3" style="padding:14px 16px">
            <span class="text-error"><?= $this->icon('alert') ?></span>
            <p class="small" style="color:var(--on-error-container);font-weight:500"><?= $this->e($error) ?></p>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" action="/admin/login" novalidate>
        <?= $this->csrf() ?>

        <div class="field">
          <label class="field__label" for="email">Email address <span class="req">*</span></label>
          <div class="input-group">
            <span class="input-group__icon"><?= $this->icon('users', 'ic ic--sm') ?></span>
            <input class="input" id="email" name="email" type="email" required autocomplete="username"
                   autofocus inputmode="email" placeholder="you@example.com"
                   value="<?= $this->old('email') ?>"
                   <?= $this->hasError('email') ? 'aria-invalid="true"' : '' ?>>
          </div>
          <?php if ($this->hasError('email')): ?>
            <p class="field__error"><?= $this->error('email') ?></p>
          <?php endif; ?>
        </div>

        <div class="field">
          <label class="field__label" for="password">Password <span class="req">*</span></label>
          <div class="input-group">
            <span class="input-group__icon"><?= $this->icon('key', 'ic ic--sm') ?></span>
            <input class="input" id="password" name="password" type="password" required
                   autocomplete="current-password" placeholder="••••••••"
                   <?= $this->hasError('password') ? 'aria-invalid="true"' : '' ?>>
            <button type="button" class="btn btn--ghost btn--icon btn--sm input-group__action"
                    data-toggle-password="#password" aria-label="Show password">
              <?= $this->icon('eye', 'ic ic--sm') ?>
            </button>
          </div>
          <?php if ($this->hasError('password')): ?>
            <p class="field__error"><?= $this->error('password') ?></p>
          <?php endif; ?>
        </div>

        <div class="row row--between mb-5">
          <label class="checkbox small">
            <input type="checkbox" name="remember" value="1">
            <span>Keep me signed in</span>
          </label>
          <a href="/admin/forgot-password" class="small text-secondary" style="font-weight:600">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn--primary btn--lg btn--block">
          Sign in
          <?= $this->icon('chevron-right', 'ic ic--sm') ?>
        </button>
      </form>

      <p class="tiny faint text-center mt-6">
        This area is monitored. All sign-in attempts are logged.
      </p>
    </div>
  </main>
</div>

<div class="toasts" role="status" aria-live="polite"></div>

<?php foreach (\Zuste\Core\Session::toasts() as $toast): ?>
  <span data-flash="<?= $this->e($toast['message']) ?>" data-flash-type="<?= $this->e($toast['type']) ?>" hidden></span>
<?php endforeach; ?>

<script src="/admin/assets/js/admin.js?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>"></script>
</body>
</html>

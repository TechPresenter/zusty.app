<?php

declare(strict_types=1);

/**
 * Two-factor enrolment for the signed-in admin.
 *
 * @var \Zuste\Core\View $this
 * @var bool $enabled
 * @var string|null $confirmedAt
 * @var int $recoveryLeft
 * @var array{secret:string,uri:string,svg:string}|null $enrolment
 * @var list<string>|null $recoveryCodes
 */

$this->extend('admin.layouts.app');
$this->start('content');

?>

<?php if (!empty($recoveryCodes)): ?>
  <!-- Shown exactly once, immediately after enrolment. -->
  <div class="card mb-5" style="border-color:var(--tertiary)">
    <div class="card__head">
      <div>
        <h2 class="h3">Save your recovery codes</h2>
        <p class="muted small mt-1">
          Each code works once. They are the only way back in if you lose your device —
          this is the only time they will be shown.
        </p>
      </div>
      <button class="btn btn--outline btn--sm" data-copy-codes>
        <?= $this->icon('file', 'ic ic--sm') ?> Copy all
      </button>
    </div>

    <div class="card__body">
      <div class="recovery-codes" id="recovery-codes">
        <?php foreach ($recoveryCodes as $code): ?>
          <code class="recovery-codes__item"><?= $this->e($code) ?></code>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--2">

  <div class="card">
    <div class="card__head">
      <div>
        <h2 class="h3">Two-factor authentication</h2>
        <p class="muted small mt-1">A six-digit code from your phone, on top of your password.</p>
      </div>
    </div>

    <div class="card__body">
      <?php if ($enabled): ?>

        <div class="row gap-3 mb-4">
          <span class="chip chip--success"><span class="chip__dot"></span>On</span>
          <span class="small muted">
            Confirmed <?= $confirmedAt !== null ? $this->dateTime($confirmedAt) : 'recently' ?>
            · <?= (int) $recoveryLeft ?> recovery code<?= $recoveryLeft === 1 ? '' : 's' ?> left
          </span>
        </div>

        <p class="small muted mb-4">
          You will be asked for a code the next time you sign in from a new session.
        </p>

        <form method="post" action="/admin/security/two-factor/disable" class="grid">
          <?= $this->csrf() ?>

          <div class="field">
            <label class="field__label" for="disable-password">Confirm your password to switch it off</label>
            <input class="input" id="disable-password" type="password" name="password"
                   autocomplete="current-password" required>
          </div>

          <div>
            <button type="submit" class="btn btn--danger btn--sm"
                    data-confirm="Your account will be protected by its password alone."
                    data-confirm-title="Turn off two-factor?"
                    data-confirm-label="Turn it off" data-confirm-tone="danger">
              <?= $this->icon('x', 'ic ic--sm') ?> Turn off two-factor
            </button>
          </div>
        </form>

      <?php elseif (!empty($enrolment)): ?>

        <p class="small muted mb-4">
          Scan this with Google Authenticator, 1Password, Authy or any TOTP app,
          then enter the code it shows.
        </p>

        <div class="qr-frame mb-4"><?= $enrolment['svg'] ?></div>

        <details class="mb-4">
          <summary class="small" style="cursor:pointer">Can't scan it?</summary>
          <p class="small muted mt-2">Enter this key manually:</p>
          <code class="mono" style="word-break:break-all;font-size:13px"><?= $this->e($enrolment['secret']) ?></code>
        </details>

        <form method="post" action="/admin/security/two-factor/confirm" class="grid">
          <?= $this->csrf() ?>

          <div class="field">
            <label class="field__label" for="code">Six-digit code</label>
            <input class="input mono" id="code" name="code" inputmode="numeric" pattern="[0-9]*"
                   maxlength="6" autocomplete="one-time-code" placeholder="000000" required
                   style="letter-spacing:.4em;font-size:20px;max-width:200px">
          </div>

          <div>
            <button type="submit" class="btn btn--primary">
              <?= $this->icon('check', 'ic ic--sm') ?> Confirm and turn on
            </button>
          </div>
        </form>

      <?php else: ?>

        <div class="row gap-3 mb-4">
          <span class="chip chip--warning"><span class="chip__dot"></span>Off</span>
        </div>

        <p class="small muted mb-4">
          With two-factor on, someone who steals your password still cannot sign in.
        </p>

        <form method="post" action="/admin/security/two-factor/begin">
          <?= $this->csrf() ?>
          <button type="submit" class="btn btn--primary">
            <?= $this->icon('shield', 'ic ic--sm') ?> Set up two-factor
          </button>
        </form>

      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2 class="h3">How it works</h2></div>
    <div class="card__body">
      <ol class="steps">
        <li><strong>Scan the code.</strong> Your app stores a shared secret and derives a new six-digit code every 30 seconds.</li>
        <li><strong>Confirm once.</strong> Entering a valid code proves the secret transferred correctly. Until you do, two-factor stays off — a half-finished setup can never lock you out.</li>
        <li><strong>Keep the recovery codes.</strong> Eight single-use codes, shown once. Store them somewhere other than the phone running the authenticator.</li>
      </ol>

      <p class="small muted mt-4">
        Codes are checked against the previous, current and next 30-second window,
        so a slightly wrong device clock still works.
      </p>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
  .qr-frame { display:inline-block; padding:16px; background:#fff; border-radius:var(--r-md); border:1px solid var(--outline-variant); }
  .qr-frame svg { display:block; width:200px; height:200px; }
  .recovery-codes { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; }
  .recovery-codes__item { font-family:var(--font-mono,ui-monospace,monospace); font-size:14px; letter-spacing:.08em;
    padding:10px 12px; background:var(--surface-variant); border-radius:var(--r); text-align:center; }
  .steps { margin:0; padding-left:20px; display:grid; gap:12px; font-size:14px; color:var(--on-surface-variant); }
  .steps strong { color:var(--on-surface); }
</style>
<script>
(function () {
  const button = document.querySelector('[data-copy-codes]');
  const box    = document.getElementById('recovery-codes');

  if (!button || !box) return;

  button.addEventListener('click', function () {
    const codes = Array.from(box.querySelectorAll('code')).map(c => c.textContent.trim()).join('\n');

    navigator.clipboard.writeText(codes).then(
      () => window.Admin && window.Admin.toast('Recovery codes copied.', 'success'),
      () => window.Admin && window.Admin.toast('Could not copy — select and copy them manually.', 'error')
    );
  });
})();
</script>
<?php $this->stop(); ?>

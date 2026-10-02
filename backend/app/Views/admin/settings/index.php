<?php

declare(strict_types=1);

/**
 * Platform settings.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,array{label:string,icon:string,permission:string}> $groups
 * @var string $activeGroup
 * @var list<array<string,mixed>> $settings
 */

$this->extend('admin.layouts.app');
$this->start('content');

$canManage = $this->can($groups[$activeGroup]['permission'] ?? 'settings.manage');

/** Render the right control for a setting's declared type. */
$control = function (array $setting) use ($canManage): string {
    $key      = (string) $setting['setting_key'];
    $name     = 'settings[' . $key . ']';
    $id       = 'set_' . $key;
    $value    = (string) ($setting['value'] ?? '');
    // A row fixed by policy (SettingsController::POLICY_LOCKED) is shown but
    // can never be changed here.
    $disabled = $canManage && empty($setting['locked']) ? '' : ' disabled';

    switch ((string) $setting['type']) {
        case 'boolean':
            return sprintf(
                '<label class="switch"><input type="checkbox" id="%s" name="%s" value="1"%s%s>'
                . '<span class="switch__track"></span><span class="small">%s</span></label>',
                $id, $this->e($name),
                in_array($value, ['1', 'true', 'yes'], true) ? ' checked' : '',
                $disabled,
                $this->e($setting['label'] ?? $key)
            );

        case 'secret':
            $hint = !empty($setting['has_value'])
                ? 'A value is set. Leave blank to keep it, or enter a new one to replace it.'
                : 'Not configured.';

            return sprintf(
                '<input class="input mono" type="password" id="%s" name="%s" value="" autocomplete="new-password" '
                . 'placeholder="%s"%s><p class="field__hint">%s</p>',
                $id, $this->e($name),
                !empty($setting['has_value']) ? '••••••••••••' : 'Enter a value',
                $disabled,
                $this->e($hint)
            );

        case 'text':
            return sprintf('<textarea class="textarea" id="%s" name="%s" rows="3"%s>%s</textarea>',
                $id, $this->e($name), $disabled, $this->e($value));

        case 'json':
            return sprintf('<textarea class="textarea mono" id="%s" name="%s" rows="4"%s>%s</textarea>',
                $id, $this->e($name), $disabled, $this->e($value));

        case 'integer':
            return sprintf('<input class="input" type="number" step="1" id="%s" name="%s" value="%s"%s>',
                $id, $this->e($name), $this->e($value), $disabled);

        case 'decimal':
            return sprintf('<input class="input" type="number" step="0.01" id="%s" name="%s" value="%s"%s>',
                $id, $this->e($name), $this->e($value), $disabled);

        case 'file':
            $preview = $value !== ''
                ? sprintf('<img src="%s" alt="" loading="lazy" style="width:76px;height:50px;object-fit:contain;'
                    . 'border-radius:var(--r-md);background:var(--surface-container);flex-shrink:0">', $this->e($value))
                : '<div style="width:76px;height:50px;border-radius:var(--r-md);background:var(--surface-container);flex-shrink:0"></div>';

            return sprintf('<div class="row gap-3">%s<input class="input" type="text" id="%s" name="%s" value="%s"%s></div>',
                $preview, $id, $this->e($name), $this->e($value), $disabled);

        default:
            // A colour setting gets a picker; everything else a text field.
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1) {
                return sprintf(
                    '<div class="row gap-3"><input type="color" value="%s" style="width:52px;height:42px;'
                    . 'border:1px solid var(--outline-variant);border-radius:var(--r-md);background:none;cursor:pointer;padding:3px"'
                    . ' oninput="this.nextElementSibling.value=this.value"%s>'
                    . '<input class="input mono" id="%s" name="%s" value="%s"%s></div>',
                    $this->e($value), $disabled, $id, $this->e($name), $this->e($value), $disabled
                );
            }

            return sprintf('<input class="input" type="text" id="%s" name="%s" value="%s"%s>',
                $id, $this->e($name), $this->e($value), $disabled);
    }
};

$testable = ['mail' => 'Send a test email', 'sms' => 'Send a test SMS', 'firebase' => 'Test push delivery'];

?>

<div class="grid" style="grid-template-columns:minmax(200px,240px) minmax(0,1fr);gap:24px">

  <!-- Group nav -->
  <nav class="card" style="align-self:start;position:sticky;top:calc(var(--topbar-h) + 24px)">
    <div class="card__body" style="padding:10px">
      <?php foreach ($groups as $key => $group): ?>
        <?php if (!$this->can($group['permission'])) { continue; } ?>
        <a href="/admin/settings?group=<?= $this->e($key) ?>"
           class="nav-item<?= $activeGroup === $key ? ' nav-item--active' : '' ?>">
          <?= $this->icon($group['icon']) ?>
          <span><?= $this->e($group['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>

  <!-- Group form -->
  <div class="col gap-4">

    <?php if ($activeGroup === 'security'): ?>
      <div class="card" style="border-color:var(--error);background:var(--error-container)">
        <div class="card__body row gap-3" style="padding:14px 18px">
          <span style="color:var(--on-error-container)"><?= $this->icon('alert') ?></span>
          <p class="small" style="color:var(--on-error-container)">
            These values govern authentication and abuse protection. Loosening them weakens every account
            on the platform — change them only with a clear reason.
          </p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($activeGroup === 'ai'): ?>
      <?php $this->partial('admin.settings.partials.ai-provider', [
          'aiProviders'  => $aiProviders,
          'aiCurrentId'  => $aiCurrentId,
          'aiModels'     => $aiModels,
          'aiActiveName' => $aiActiveName,
          'aiFeatures'   => $aiFeatures,
          'aiMonthSpend' => $aiMonthSpend,
          'aiCallsToday' => $aiCallsToday,
          'canManage'    => $canManage,
      ]); ?>
    <?php endif; ?>

    <div class="card">
      <div class="card__head">
        <div>
          <h3 class="h3"><?= $activeGroup === 'ai' ? 'App features &amp; limits' : $this->e($groups[$activeGroup]['label']) ?></h3>
          <p class="tiny muted"><?= count($settings) ?> settings</p>
        </div>

        <?php if (isset($testable[$activeGroup]) && $this->can('settings.manage')): ?>
          <button type="button" class="btn btn--outline btn--sm" onclick="testIntegration('<?= $this->e($activeGroup) ?>')">
            <?= $this->icon('refresh', 'ic ic--sm') ?> <?= $this->e($testable[$activeGroup]) ?>
          </button>
        <?php endif; ?>
      </div>

      <div class="card__body">
        <?php if ($settings === []): ?>
          <p class="muted small">No settings in this group.</p>
        <?php else: ?>
          <form method="post" action="/admin/settings">
            <?= $this->csrf() ?>
            <input type="hidden" name="group" value="<?= $this->e($activeGroup) ?>">

            <?php foreach ($settings as $setting): ?>
              <div class="field">
                <?php if ($setting['type'] !== 'boolean'): ?>
                  <label class="field__label" for="set_<?= $this->e($setting['setting_key']) ?>">
                    <?= $this->e($setting['label'] ?? $setting['setting_key']) ?>
                    <?php if ((int) $setting['is_encrypted'] === 1): ?>
                      <span class="chip chip--neutral" style="font-size:9px;margin-left:6px">Encrypted</span>
                    <?php endif; ?>
                    <?php if ((int) $setting['is_public'] === 1): ?>
                      <span class="chip chip--info" style="font-size:9px;margin-left:4px" title="Exposed via the public API">Public</span>
                    <?php endif; ?>
                  </label>
                <?php endif; ?>

                <?= $control($setting) ?>

                <?php if (!empty($setting['locked'])): ?>
                  <p class="field__hint">
                    <span class="chip chip--neutral" style="font-size:9px;margin-right:6px">Fixed by policy</span>
                    <?= $this->e((string) ($setting['locked_reason'] ?? 'This setting cannot be changed.')) ?>
                  </p>
                <?php elseif (!empty($setting['description']) && $setting['type'] !== 'secret'): ?>
                  <p class="field__hint"><?= $this->e($setting['description']) ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <?php if ($canManage): ?>
              <button type="submit" class="btn btn--primary">
                <?php $groupLabel = (string) $groups[$activeGroup]['label']; ?>
                <?= $this->icon('check', 'ic ic--sm') ?> Save <?= $this->e($groupLabel === strtoupper($groupLabel) ? $groupLabel : strtolower($groupLabel)) ?> settings
              </button>
            <?php else: ?>
              <p class="small muted">You have read-only access to this group.</p>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Integration test form -->
<form id="test-form" method="post" action="/admin/settings/test" style="display:none">
  <?= $this->csrf() ?>
  <input type="hidden" name="target" id="test-target">
  <input type="hidden" name="email" id="test-email">
  <input type="hidden" name="phone" id="test-phone">
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
function testIntegration(target) {
  document.getElementById('test-target').value = target;

  if (target === 'mail') {
    const email = window.prompt('Send the test email to:', '<?= $this->e($admin['email'] ?? '') ?>');
    if (email === null) return;
    document.getElementById('test-email').value = email.trim();
  }

  if (target === 'sms') {
    const phone = window.prompt('Send the test SMS to (with country code):', '+91');
    if (phone === null) return;
    if (phone.trim().length < 8) {
      Admin.toast('Enter a full phone number including the country code.', 'warning');
      return;
    }
    document.getElementById('test-phone').value = phone.trim();
  }

  Admin.toast('Running the test…', 'info', 2000);
  document.getElementById('test-form').submit();
}
</script>
<?php $this->stop(); ?>

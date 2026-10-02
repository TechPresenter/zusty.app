<?php

declare(strict_types=1);

/**
 * Authentication & Login.
 *
 * @var array<string,array<string,mixed>> $methods
 * @var array<string,array{success:int,failed:int,disabled:int}> $activity
 * @var list<array<string,mixed>> $recent
 * @var array<string,mixed> $policy
 * @var bool $canManage
 * @var bool $canTest
 */

$this->extend('admin.layouts.app');
$this->start('content');

/** How each method key is named in `login_history`.`auth_method`. */
$auditKey = [
    'email_password' => 'password',
    'mobile_otp'     => 'phone_otp',
    'google_login'   => 'google',
    'apple_login'    => 'apple',
    'facebook_login' => 'facebook',
    'guest_login'    => 'guest',
];

$tone = [
    'email_password' => '#B80049',
    'mobile_otp'     => '#0D9488',
    'google_login'   => '#4285F4',
    'apple_login'    => '#111111',
    'facebook_login' => '#1877F2',
    'guest_login'    => '#632CE5',
];

$statusChip = static function (array $method): array {
    if (!$method['enabled']) {
        return ['neutral', 'Off'];
    }

    return match ((string) $method['status']) {
        'connected'      => ['success', 'On'],
        'error'          => ['error', 'Failing'],
        'not_configured' => ['warning', 'On, not configured'],
        default          => ['info', ucfirst((string) $method['status'])],
    };
};
?>

<div class="chip-row mb-5" data-tabs>
  <button class="chip-filter chip-filter--active" data-tab="#panel-overview">Overview</button>
  <button class="chip-filter" data-tab="#panel-email">Email &amp; Password</button>
  <button class="chip-filter" data-tab="#panel-otp">Mobile OTP</button>
  <button class="chip-filter" data-tab="#panel-google">Google</button>
  <button class="chip-filter" data-tab="#panel-apple">Apple</button>
  <button class="chip-filter" data-tab="#panel-facebook">Facebook</button>
  <button class="chip-filter" data-tab="#panel-guest">Guest</button>
  <button class="chip-filter" data-tab="#panel-firebase">Firebase &amp; flow</button>
</div>

<!-- ===================== Overview ===================== -->
<div id="panel-overview">

  <div class="grid grid--3 mb-5">
    <?php foreach ($methods as $key => $method): ?>
      <?php [$chipTone, $chipLabel] = $statusChip($method); ?>
      <?php $counts = $activity[$auditKey[$key] ?? ''] ?? ['success' => 0, 'failed' => 0, 'disabled' => 0]; ?>
      <div class="card" style="--tone:<?= $this->e($tone[$key] ?? '#B80049') ?>">
        <div class="row row--between">
          <div>
            <h3 class="h3 mb-0"><?= $this->e((string) $method['definition']['name']) ?></h3>
            <p class="small muted mb-0"><?= $this->e((string) $method['definition']['vendor']) ?></p>
          </div>
          <span class="chip chip--<?= $this->e($chipTone) ?>"><?= $this->e($chipLabel) ?></span>
        </div>

        <div class="row gap-4 mt-4">
          <div>
            <div class="h2 mb-0 text-success"><?= $this->number($counts['success']) ?></div>
            <div class="tiny muted">signed in (24h)</div>
          </div>
          <div>
            <div class="h2 mb-0 <?= $counts['failed'] > 0 ? 'text-warning' : 'muted' ?>"><?= $this->number($counts['failed']) ?></div>
            <div class="tiny muted">failed</div>
          </div>
          <?php if ($counts['disabled'] > 0): ?>
            <div>
              <div class="h2 mb-0 text-error"><?= $this->number($counts['disabled']) ?></div>
              <div class="tiny muted">refused, off</div>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($method['row']['last_test_at'])): ?>
          <p class="tiny muted mt-4 mb-0">
            Last tested <?= $this->timeAgo((string) $method['row']['last_test_at']) ?> —
            <?= (int) ($method['row']['last_test_ok'] ?? 0) === 1
                ? '<span class="text-success">passed</span>'
                : '<span class="text-error">' . $this->e((string) ($method['row']['last_error'] ?? 'failed')) . '</span>' ?>
          </p>
        <?php endif; ?>

        <?php if ($canManage): ?>
          <form method="post" action="/admin/login-methods/<?= $this->e($key) ?>/toggle" class="mt-4">
            <?= $this->csrf() ?>
            <?php if ($method['enabled']): ?>
              <label class="switch mb-3">
                <input type="checkbox" name="revoke_sessions" value="1">
                <span class="switch__track"></span>
                <span class="small">Also sign out everyone using it</span>
              </label>
              <button type="submit" class="btn btn--outline btn--sm btn--block"
                      data-confirm="People signing in this way will be refused by the API, not just in the app."
                      data-confirm-title="Switch off <?= $this->e((string) $method['definition']['name']) ?>?"
                      data-confirm-label="Switch off" data-confirm-tone="danger">Switch off</button>
            <?php else: ?>
              <input type="hidden" name="enable" value="1">
              <button type="submit" class="btn btn--primary btn--sm btn--block">Switch on</button>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="callout callout--info mb-5">
    <strong>Switching a method off refuses it at the API, not just in the app.</strong>
    A request using a disabled method is answered <span class="mono">403 auth_method_disabled</span>
    and recorded below. Sessions already signed in keep working until their access token expires
    (currently <?= $this->number((int) round(\Zuste\Core\Config::settingInt('security', 'jwt_access_ttl', 3600) / 60)) ?>
    minutes) unless you tick "sign out everyone" when switching it off.
  </div>

  <div class="card">
    <div class="row row--between mb-4">
      <h2 class="h2 mb-0">Recent sign-ins</h2>
      <a class="btn btn--ghost btn--sm" href="/admin/security/login-history?actor_type=user">
        View all sign-in history <?= $this->icon('external', 'ic ic--sm') ?>
      </a>
    </div>

    <?php if ($recent === []): ?>
      <div class="empty">
        <p class="muted">No app sign-ins recorded yet.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead>
            <tr>
              <th>When</th><th>Identifier</th><th>Method</th>
              <th>Outcome</th><th>Device</th><th>IP</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $row): ?>
              <tr>
                <td class="tiny muted"><?= $this->timeAgo((string) $row['created_at']) ?></td>
                <td class="truncate"><?= $this->e((string) ($row['identifier'] ?: '—')) ?></td>
                <td><span class="chip chip--neutral tiny"><?= $this->e((string) $row['auth_method']) ?></span></td>
                <td>
                  <?php $outcome = (string) $row['outcome']; ?>
                  <span class="chip tiny chip--<?= $outcome === 'success' ? 'success' : ($outcome === 'method_disabled' ? 'error' : 'warning') ?>">
                    <?= $this->e(str_replace('_', ' ', $outcome)) ?>
                  </span>
                </td>
                <td class="tiny muted"><?= $this->e((string) ($row['device_type'] ?: '—')) ?></td>
                <td class="tiny mono muted"><?= $this->e((string) ($row['ip_address'] ?: '—')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
// ---------------------------------------------------------------------
// Per-provider credential panels.
// ---------------------------------------------------------------------
$panelId = [
    'email_password' => 'panel-email',
    'mobile_otp'     => 'panel-otp',
    'google_login'   => 'panel-google',
    'apple_login'    => 'panel-apple',
    'facebook_login' => 'panel-facebook',
    'guest_login'    => 'panel-guest',
];

foreach ($methods as $key => $method):
    $definition = $method['definition'];
    $buildTime  = $method['build_time_fields'];
    ?>
  <div id="<?= $this->e($panelId[$key]) ?>" class="hidden">

    <div class="card mb-5">
      <div class="row row--between mb-4">
        <div>
          <h2 class="h2 mb-0"><?= $this->e((string) $definition['name']) ?></h2>
          <p class="small muted mb-0"><?= $this->e((string) $definition['description']) ?></p>
        </div>
        <?php [$chipTone, $chipLabel] = $statusChip($method); ?>
        <span class="chip chip--<?= $this->e($chipTone) ?>"><?= $this->e($chipLabel) ?></span>
      </div>

      <?php if ((string) $definition['notes'] !== ''): ?>
        <div class="callout callout--info mb-4"><?= $this->e((string) $definition['notes']) ?></div>
      <?php endif; ?>

      <?php if ($definition['fields'] === []): ?>
        <p class="muted">
          This method stores no credentials of its own — its settings live in the groups
          that already own them, and are edited on the <strong>Firebase &amp; flow</strong> tab
          and under Settings.
        </p>
      <?php else: ?>
        <form method="post" action="/admin/login-methods/<?= $this->e($key) ?>">
          <?= $this->csrf() ?>

          <div class="form-grid form-grid--2">
            <?php foreach ($definition['fields'] as $field):
                $name    = (string) $field['name'];
                $isBuild = in_array($name, $buildTime, true);

                if ($isBuild) {
                    continue;   // rendered in the read-only card below
                }

                $inputId = 'f_' . $key . '_' . $name;
                $secret  = ($field['secret'] ?? false) === true;
                $stored  = $secret && !empty($method['has_secret'][$name]);
                $value   = $method['values'][$name] ?? '';
                ?>
              <div class="field">
                <label class="field__label" for="<?= $this->e($inputId) ?>">
                  <?= $this->e((string) $field['label']) ?>
                  <?php if ($stored): ?><span class="chip chip--success tiny">stored</span><?php endif; ?>
                </label>

                <?php if ((string) $field['type'] === 'textarea'): ?>
                  <textarea class="textarea mono" id="<?= $this->e($inputId) ?>" name="<?= $this->e($name) ?>" rows="5"
                            <?= $canManage ? '' : 'disabled' ?>
                            placeholder="<?= $stored ? 'Stored — leave blank to keep it' : '' ?>"><?= $secret ? '' : $this->e((string) $value) ?></textarea>
                <?php else: ?>
                  <input class="input <?= $secret ? 'mono' : '' ?>"
                         id="<?= $this->e($inputId) ?>" name="<?= $this->e($name) ?>"
                         type="<?= $secret ? 'password' : 'text' ?>"
                         <?= $secret ? 'autocomplete="new-password"' : '' ?>
                         <?= $canManage ? '' : 'disabled' ?>
                         value="<?= $secret ? '' : $this->e((string) $value) ?>"
                         placeholder="<?= $stored ? 'Stored — leave blank to keep it' : $this->e((string) $field['hint']) ?>">
                <?php endif; ?>

                <?php if ((string) $field['hint'] !== '' || $stored): ?>
                  <p class="field__hint">
                    <?= $this->e((string) $field['hint']) ?>
                    <?php if ($stored && $canManage): ?>
                      <button type="submit" class="btn btn--ghost btn--sm text-error"
                              formaction="/admin/login-methods/<?= $this->e($key) ?>/secret/<?= $this->e($name) ?>/clear"
                              data-confirm="The stored value is deleted and this sign-in method is switched off."
                              data-confirm-title="Remove this credential?" data-confirm-label="Remove"
                              data-confirm-tone="danger">Remove stored value</button>
                    <?php endif; ?>
                  </p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($canManage): ?>
            <div class="row gap-2 mt-4">
              <button type="submit" class="btn btn--primary"><?= $this->icon('check', 'ic ic--sm') ?> Save</button>
            </div>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($canTest): ?>
      <div class="card mb-5">
        <h3 class="h3">Connection test</h3>
        <p class="small muted">
          <?= $this->e(match ($key) {
              'google_login'   => 'Checks the client ID is well-formed, that Google is reachable from this server, and that a Firebase project is set. Google cannot confirm a client ID without a real sign-in.',
              'apple_login'    => 'Signs a real client-secret JWT with the stored key and presents it to Apple. Apple confirms whether the Service ID, Team ID, Key ID and .p8 belong together. No side effect.',
              'facebook_login' => 'Requests an app access token, which proves both the app ID and the secret.',
              'mobile_otp'     => 'Checks SMS delivery is switched on and a provider and key are configured.',
              'email_password' => 'Checks the password policy and that outgoing mail is configured, since recovery depends on it.',
              default          => 'Checks the method is ready to use.',
          }) ?>
        </p>

        <form method="post" action="/admin/login-methods/<?= $this->e($key) ?>/test" data-test-form>
          <?= $this->csrf() ?>
          <button type="submit" class="btn btn--tonal" data-test-button>
            <?= $this->icon('play', 'ic ic--sm') ?> Test connection
          </button>
        </form>

        <div class="mt-4" data-test-result></div>
      </div>
    <?php endif; ?>

    <?php if ($buildTime !== []): ?>
      <div class="card">
        <h3 class="h3">Platform registration <span class="chip chip--neutral tiny">Build-time</span></h3>

        <div class="callout callout--warning mb-4">
          <strong>These values are recorded here for reference only.</strong>
          They are compiled into the mobile app or registered in the provider's console.
          Changing them on this screen does <strong>not</strong> change the shipped app, does not
          change the provider's configuration, and will not take effect until a new build is
          released and the value is updated in the provider console.
        </div>

        <div class="form-grid form-grid--2">
          <?php foreach ($definition['fields'] as $field):
              $name = (string) $field['name'];

              if (!in_array($name, $buildTime, true)) {
                  continue;
              }
              ?>
            <div class="field">
              <label class="field__label"><?= $this->e((string) $field['label']) ?></label>
              <div class="input-group">
                <input class="input mono" value="<?= $this->e((string) ($method['values'][$name] ?? '')) ?>" disabled>
                <button type="button" class="btn btn--ghost btn--icon btn--sm"
                        data-copy="<?= $this->e((string) ($method['values'][$name] ?? '')) ?>"
                        aria-label="Copy"><?= $this->icon('file', 'ic ic--sm') ?></button>
              </div>
              <p class="field__hint"><?= $this->e((string) $field['hint']) ?></p>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($canManage): ?>
          <p class="small muted mt-4">
            Edit these on the provider's own form above — they are stored so the values can be
            compared against what the running app reports.
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<!-- ===================== Firebase & flow ===================== -->
<div id="panel-firebase" class="hidden">
  <form method="post" action="/admin/login-methods/policy">
    <?= $this->csrf() ?>

    <div class="card mb-5">
      <h2 class="h2">Firebase client options</h2>
      <p class="small muted">
        The values the mobile and web SDKs need to start Firebase. The server half —
        the service-account JSON used to verify tokens — stays under
        <a href="/admin/settings?group=firebase">Settings → Firebase</a> and is never sent to a client.
      </p>

      <div class="grid grid--3 mb-4">
        <div class="field">
          <label class="field__label">Project ID <span class="chip chip--neutral tiny">Settings → Firebase</span></label>
          <input class="input mono" value="<?= $this->e((string) $policy['firebase_project_id']) ?>" disabled>
        </div>
        <div class="field">
          <label class="field__label">Web API key <span class="chip chip--neutral tiny">Settings → Firebase</span></label>
          <input class="input mono" value="<?= $this->e((string) $policy['firebase_web_api_key']) ?>" disabled>
        </div>
        <div class="field">
          <label class="field__label">Storage bucket <span class="chip chip--neutral tiny">Settings → Firebase</span></label>
          <input class="input mono" value="<?= $this->e((string) $policy['firebase_storage_bucket']) ?>" disabled>
        </div>
      </div>

      <div class="form-grid form-grid--2">
        <?php foreach ([
            'firebase_app_id_android'       => 'App ID (Android)',
            'firebase_app_id_ios'           => 'App ID (iOS)',
            'firebase_app_id_web'           => 'App ID (Web)',
            'firebase_messaging_sender_id'  => 'Messaging sender ID',
            'firebase_auth_domain'          => 'Auth domain',
            'firebase_android_package_name' => 'Android package name',
            'firebase_ios_bundle_id'        => 'iOS bundle identifier',
        ] as $key => $label): ?>
          <div class="field">
            <label class="field__label" for="p_<?= $this->e($key) ?>"><?= $this->e($label) ?></label>
            <input class="input mono" id="p_<?= $this->e($key) ?>" name="<?= $this->e($key) ?>"
                   value="<?= $this->e((string) $policy[$key]) ?>" <?= $canManage ? '' : 'disabled' ?>>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card mb-5">
      <h2 class="h2">Mobile OTP</h2>
      <?php if (!$policy['sms_enabled']): ?>
        <div class="callout callout--warning mb-4">
          SMS delivery is switched off under <a href="/admin/settings?group=sms">Settings → SMS</a>,
          so codes are only written to the log. OTP sign-in will not work for real users.
        </div>
      <?php endif; ?>

      <div class="form-grid form-grid--2">
        <div class="field">
          <label class="switch">
            <input type="checkbox" name="otp_login_enabled" value="1"
                   <?= $policy['otp_login_enabled'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
            <span class="switch__track"></span>
            <span class="small">A code alone can sign someone in</span>
          </label>
          <p class="field__hint">Off means codes only verify a number the person already owns.</p>
        </div>
        <div class="field">
          <label class="field__label" for="p_otp_default_country_code">Default country code</label>
          <input class="input" id="p_otp_default_country_code" name="otp_default_country_code"
                 value="<?= $this->e((string) $policy['otp_default_country_code']) ?>" <?= $canManage ? '' : 'disabled' ?>>
        </div>
        <?php foreach ([
            'otp_phone_digits'   => ['Phone digits', 'Expected national number length.'],
            'otp_length'         => ['Code length', 'Shared with Settings → Security.'],
            'otp_ttl'            => ['Code lifetime (seconds)', 'Shared with Settings → Security.'],
            'otp_resend_seconds' => ['Resend cooldown (seconds)', 'How long before resend re-enables.'],
            'otp_max_resends'    => ['Maximum resends', 'Per verification attempt.'],
        ] as $key => [$label, $hint]): ?>
          <div class="field">
            <label class="field__label" for="p_<?= $this->e($key) ?>"><?= $this->e($label) ?></label>
            <input class="input" type="number" id="p_<?= $this->e($key) ?>" name="<?= $this->e($key) ?>"
                   value="<?= $this->e((string) $policy[$key]) ?>" <?= $canManage ? '' : 'disabled' ?>>
            <p class="field__hint"><?= $this->e($hint) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card mb-5">
      <h2 class="h2">Email &amp; password</h2>
      <?php if ((string) $policy['mail_host'] === ''): ?>
        <div class="callout callout--warning mb-4">
          No SMTP host is set under <a href="/admin/settings?group=mail">Settings → Email</a>,
          so password-reset messages cannot be delivered.
        </div>
      <?php endif; ?>

      <div class="form-grid form-grid--2">
        <div class="field">
          <label class="switch">
            <input type="checkbox" name="forgot_password_enabled" value="1"
                   <?= $policy['forgot_password_enabled'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
            <span class="switch__track"></span>
            <span class="small">Offer password recovery</span>
          </label>
          <p class="field__hint">
            The endpoint keeps answering 200 either way — a 403 would reveal which addresses exist.
          </p>
        </div>
        <div class="field">
          <label class="field__label" for="p_reset_token_ttl_minutes">Reset link lifetime (minutes)</label>
          <input class="input" type="number" id="p_reset_token_ttl_minutes" name="reset_token_ttl_minutes"
                 value="<?= $this->e((string) $policy['reset_token_ttl_minutes']) ?>" <?= $canManage ? '' : 'disabled' ?>>
          <p class="field__hint">Also what the reset email tells the recipient.</p>
        </div>
        <div class="field">
          <label class="field__label" for="p_identifier_mode">Sign-in identifier</label>
          <select class="select" id="p_identifier_mode" name="identifier_mode" <?= $canManage ? '' : 'disabled' ?>>
            <?php foreach (['email_or_phone' => 'Email or phone', 'email' => 'Email only', 'phone' => 'Phone only'] as $value => $label): ?>
              <option value="<?= $this->e($value) ?>" <?= $policy['identifier_mode'] === $value ? 'selected' : '' ?>><?= $this->e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field__label" for="p_password_min_length">Minimum password length</label>
          <input class="input" type="number" id="p_password_min_length" name="password_min_length"
                 value="<?= $this->e((string) $policy['password_min_length']) ?>" <?= $canManage ? '' : 'disabled' ?>>
          <p class="field__hint">Shared with Settings → Security.</p>
        </div>
      </div>
    </div>

    <div class="card mb-5">
      <h2 class="h2">Guest access</h2>
      <p class="small muted">
        A guest is a real account with no identity attached, so favourites and history survive
        when they sign up — upgrading changes the same row rather than creating a new one.
      </p>

      <div class="form-grid form-grid--2">
        <?php foreach ([
            'guest_session_days'       => ['Session length (days)', 'How long a guest session stays valid.'],
            'guest_purge_days'         => ['Purge idle guests after (days)', 'Unconverted, idle guests are soft-deleted past this.'],
            'guest_max_per_ip_per_day' => ['Guest sessions per IP per day', 'Blunts automated account farming.'],
        ] as $key => [$label, $hint]): ?>
          <div class="field">
            <label class="field__label" for="p_<?= $this->e($key) ?>"><?= $this->e($label) ?></label>
            <input class="input" type="number" id="p_<?= $this->e($key) ?>" name="<?= $this->e($key) ?>"
                   value="<?= $this->e((string) $policy[$key]) ?>" <?= $canManage ? '' : 'disabled' ?>>
            <p class="field__hint"><?= $this->e($hint) ?></p>
          </div>
        <?php endforeach; ?>
      </div>

      <p class="small muted mt-4 mb-0">
        Browsable without an account:
        <?php foreach ($policy['guest_browse_routes'] as $route): ?>
          <span class="chip chip--neutral tiny"><?= $this->e((string) $route) ?></span>
        <?php endforeach; ?>
      </p>
      <p class="small muted mb-0">
        Prompts to sign up on:
        <?php foreach ($policy['guest_prompt_on'] as $action): ?>
          <span class="chip chip--info tiny"><?= $this->e((string) $action) ?></span>
        <?php endforeach; ?>
      </p>
    </div>

    <div class="card mb-5">
      <h2 class="h2">Sign-up &amp; presentation</h2>

      <div class="form-grid form-grid--2">
        <div class="field">
          <label class="switch">
            <input type="checkbox" name="brand_signup_enabled" value="1"
                   <?= $policy['brand_signup_enabled'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
            <span class="switch__track"></span><span class="small">Brands may sign up</span>
          </label>
        </div>
        <div class="field">
          <label class="switch">
            <input type="checkbox" name="creator_signup_enabled" value="1"
                   <?= $policy['creator_signup_enabled'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
            <span class="switch__track"></span><span class="small">Creators may sign up</span>
          </label>
        </div>
        <div class="field">
          <label class="switch">
            <input type="checkbox" name="role_choice_visible" value="1"
                   <?= $policy['role_choice_visible'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
            <span class="switch__track"></span><span class="small">Show the brand/creator chooser</span>
          </label>
        </div>
        <div class="field">
          <label class="switch">
            <input type="checkbox" name="require_terms_accept" value="1"
                   <?= $policy['require_terms_accept'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
            <span class="switch__track"></span><span class="small">Require accepting the terms</span>
          </label>
          <p class="field__hint">Enforced server-side on registration, not only in the app.</p>
        </div>
        <div class="field">
          <label class="field__label" for="p_default_signup_role">Default sign-up role</label>
          <select class="select" id="p_default_signup_role" name="default_signup_role" <?= $canManage ? '' : 'disabled' ?>>
            <?php foreach (['brand' => 'Brand', 'creator' => 'Creator'] as $value => $label): ?>
              <option value="<?= $this->e($value) ?>" <?= $policy['default_signup_role'] === $value ? 'selected' : '' ?>><?= $this->e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field__label" for="p_method_order">Button order</label>
          <input class="input mono" id="p_method_order" name="method_order"
                 value="<?= $this->e((string) $policy['method_order']) ?>" <?= $canManage ? '' : 'disabled' ?>>
          <p class="field__hint">
            Comma-separated: email_password, mobile_otp, google, apple, facebook, guest.
            Anything you leave out is appended rather than hidden.
          </p>
        </div>
        <?php foreach ([
            'social_divider_label' => 'Sign-in divider label',
            'signup_divider_label' => 'Sign-up divider label',
            'terms_url'            => 'Terms URL',
            'privacy_url'          => 'Privacy URL',
        ] as $key => $label): ?>
          <div class="field">
            <label class="field__label" for="p_<?= $this->e($key) ?>"><?= $this->e($label) ?></label>
            <input class="input" id="p_<?= $this->e($key) ?>" name="<?= $this->e($key) ?>"
                   value="<?= $this->e((string) $policy[$key]) ?>" <?= $canManage ? '' : 'disabled' ?>>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($canManage): ?>
      <div class="row gap-2">
        <button type="submit" class="btn btn--primary"><?= $this->icon('check', 'ic ic--sm') ?> Save settings</button>
      </div>
    <?php endif; ?>
  </form>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
  // Connection tests run over AJAX so a slow provider does not blank the page.
  // Without JS the form posts normally and the controller redirects with a
  // toast, so the button works either way.
  document.querySelectorAll('[data-test-form]').forEach((form) => {
    const button = form.querySelector('[data-test-button]');
    const target = form.parentElement.querySelector('[data-test-result]');

    const render = (message, ok, ms) => {
      target.innerHTML = '';
      const box = document.createElement('div');
      box.className = 'callout callout--' + (ok ? 'success' : 'error');
      // textContent, never innerHTML: the message can quote a provider's reply.
      box.textContent = message + (ms ? ' (' + ms + 'ms)' : '');
      target.appendChild(box);
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      button.classList.add('btn--loading');
      button.disabled = true;
      target.innerHTML = '<div class="skeleton skeleton--text"></div>';

      try {
        const payload = await window.Admin.request(form.action, { method: 'POST', body: new FormData(form) });
        render(payload.message || 'Connection test passed.', true, payload.data && payload.data.duration_ms);
      } catch (error) {
        const data = (error.payload && error.payload.data) || {};
        render(error.payload?.message || error.message || 'The test could not be run.', false, data.duration_ms);
      } finally {
        button.classList.remove('btn--loading');
        button.disabled = false;
      }
    });
  });
</script>
<?php $this->stop(); ?>

<?php

declare(strict_types=1);

/**
 * One integration: credentials, webhook, sync schedule, activity and usage.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $state
 * @var array<string,array{label:string,icon:string,tone:string}> $categories
 * @var list<array<string,mixed>> $logs
 * @var list<array{date:string,calls:int,failures:int}> $usage
 */

$this->extend('admin.layouts.app');
$this->start('content');

$definition = $state['definition'];
$row        = $state['row'];
$key        = $state['key'];
$meta       = $categories[$definition['category']];
$canManage  = $this->can('integrations.manage');
$enabled    = $row !== null && (int) $row['is_enabled'] === 1;
$newSecret  = \Zuste\Core\Session::getFlash('webhook_secret');

$statusTone = match ($state['status']) {
    'connected' => 'success', 'error' => 'error',
    'delegated' => 'info', 'disabled' => 'neutral', default => 'warning',
};

$statusLabel = match ($state['status']) {
    'connected' => 'Connected', 'error' => 'Error',
    'delegated' => 'Managed in another module', 'disabled' => 'Configured but off',
    default => 'Not set up yet',
};

$peakCalls = 1;
foreach ($usage as $point) {
    $peakCalls = max($peakCalls, $point['calls']);
}

?>

<div class="row gap-2 mb-4">
  <a href="/admin/integrations" class="btn btn--ghost btn--sm">
    <?= $this->icon('chevron-left', 'ic ic--sm') ?> All integrations
  </a>
  <a href="<?= $this->queryUrl(['category' => $definition['category']], '/admin/integrations') ?>"
     class="chip chip--neutral"><?= $this->e($meta['label']) ?></a>
</div>

<div class="card mb-5">
  <div class="card__head">
    <div class="row gap-3">
      <span class="icard__icon" style="--tone:<?= $this->e($meta['tone']) ?>"><?= $this->icon($definition['icon']) ?></span>
      <div>
        <h2 class="h3"><?= $this->e($definition['name']) ?></h2>
        <p class="muted small mt-1">
          <?= $this->e($definition['vendor']) ?> ·
          <code class="mono"><?= $this->e($key) ?></code>
        </p>
      </div>
    </div>

    <div class="row gap-2">
      <span class="chip chip--<?= $statusTone ?>"><span class="chip__dot"></span><?= $this->e($statusLabel) ?></span>
      <a href="<?= $this->e($definition['doc']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn--ghost btn--sm">
        <?= $this->icon('external', 'ic ic--sm') ?> Documentation
      </a>
    </div>
  </div>

  <div class="card__body">
    <p class="small muted"><?= $this->e($definition['description']) ?></p>

    <?php if ($definition['notes'] !== ''): ?>
      <div class="callout callout--info mt-3">
        <?= $this->icon('info', 'ic ic--sm') ?>
        <div><?= $this->e($definition['notes']) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($row['last_error'])): ?>
      <div class="callout callout--error mt-3">
        <?= $this->icon('alert', 'ic ic--sm') ?>
        <div><strong>Last check failed.</strong> <?= $this->e($row['last_error']) ?></div>
      </div>
    <?php endif; ?>

    <div class="row gap-4 row--wrap mt-4">
      <div><div class="label">Last tested</div>
        <div class="small mono"><?= !empty($row['last_test_at']) ? $this->timeAgo($row['last_test_at']) : 'never' ?></div></div>
      <div><div class="label">Last sync</div>
        <div class="small mono"><?= !empty($row['last_sync_at']) ? $this->timeAgo($row['last_sync_at']) : 'never' ?></div></div>
      <div><div class="label">Auth</div>
        <div class="small mono"><?= $this->e(str_replace('_', ' ', $definition['auth'])) ?></div></div>
      <div><div class="label">Webhooks received</div>
        <div class="small mono"><?= $this->number((int) ($row['webhook_count'] ?? 0)) ?></div></div>
    </div>
  </div>
</div>

<?php if ($definition['delegate'] !== null): ?>
  <div class="card">
    <div class="card__body">
      <div class="empty">
        <div class="empty__art"><?= $this->icon('external', 'ic ic--lg') ?></div>
        <p class="empty__title">This one lives in its own module</p>
        <p class="empty__text">
          It is listed here so the hub is a complete picture of what the platform
          connects to, but everything about it is configured where it is owned.
        </p>
        <a href="<?= $this->e($definition['delegate']) ?>" class="btn btn--primary">
          Open <?= $this->e($definition['name']) ?> <?= $this->icon('chevron-right', 'ic ic--sm') ?>
        </a>
      </div>
    </div>
  </div>
<?php else: ?>

  <div class="grid grid--sidebar">
    <div>
      <!-- Credentials -->
      <div class="card mb-5">
        <div class="card__head">
          <h2 class="h3">Credentials & configuration</h2>
          <?php if ($canManage): ?>
            <form method="post" action="/admin/integrations/<?= $this->e($key) ?>/toggle" style="display:inline">
              <?= $this->csrf() ?>
              <button type="submit" class="btn btn--<?= $enabled ? 'outline' : 'primary' ?> btn--sm">
                <?= $enabled ? 'Disable' : 'Enable' ?>
              </button>
            </form>
          <?php endif; ?>
        </div>

        <div class="card__body">
          <?php if ($definition['fields'] === []): ?>
            <p class="small muted">This integration has nothing to configure.</p>
          <?php else: ?>
            <form method="post" action="/admin/integrations/<?= $this->e($key) ?>" class="grid">
              <?= $this->csrf() ?>

              <?php foreach ($definition['fields'] as $field): ?>
                <?php
                $name    = $field['name'];
                $stored  = $state['has_secret'][$name] ?? false;
                $value   = $state['values'][$name] ?? '';
                $inputId = $key . '-' . $name;

                // Which fields the status pill and the connection test both
                // treat as required — saying so on the form is what stops an
                // operator hunting for a value the integration does not need.
                $optional = ($field['optional'] ?? false) === true && $field['type'] !== 'switch'
                    ? ' <span class="chip chip--neutral tiny">optional</span>'
                    : '';
                ?>

                <div class="field">
                  <?php if ($field['type'] === 'switch'): ?>
                    <label class="row gap-2 small">
                      <input type="checkbox" name="<?= $this->e($name) ?>" value="1"
                             <?= (int) ($value ?: 0) === 1 ? 'checked' : '' ?>
                             <?= $canManage ? '' : 'disabled' ?>>
                      <?= $this->e($field['label']) ?>
                    </label>
                    <?php if ($field['hint'] !== ''): ?>
                      <p class="field__hint"><?= $this->e($field['hint']) ?></p>
                    <?php endif; ?>

                  <?php elseif ($field['type'] === 'select'): ?>
                    <label class="field__label" for="<?= $this->e($inputId) ?>">
                      <?= $this->e($field['label']) ?><?= $optional ?>
                    </label>
                    <select class="select" id="<?= $this->e($inputId) ?>" name="<?= $this->e($name) ?>" <?= $canManage ? '' : 'disabled' ?>>
                      <?php foreach ($field['options'] as $optValue => $optLabel): ?>
                        <option value="<?= $this->e($optValue) ?>" <?= (string) $value === (string) $optValue ? 'selected' : '' ?>>
                          <?= $this->e($optLabel) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>

                  <?php elseif ($field['type'] === 'textarea'): ?>
                    <label class="field__label" for="<?= $this->e($inputId) ?>">
                      <?= $this->e($field['label']) ?><?= $optional ?>
                      <?php if ($stored): ?><span class="chip chip--success tiny">stored</span><?php endif; ?>
                    </label>
                    <textarea class="textarea mono" id="<?= $this->e($inputId) ?>" name="<?= $this->e($name) ?>" rows="5"
                              <?= $canManage ? '' : 'disabled' ?>
                              placeholder="<?= $stored ? 'Stored — leave blank to keep it' : '' ?>"><?= ($field['secret'] ?? false) ? '' : $this->e((string) $value) ?></textarea>

                  <?php else: ?>
                    <label class="field__label" for="<?= $this->e($inputId) ?>">
                      <?= $this->e($field['label']) ?><?= $optional ?>
                      <?php if ($stored): ?><span class="chip chip--success tiny">stored</span><?php endif; ?>
                    </label>
                    <input class="input <?= ($field['secret'] ?? false) ? 'mono' : '' ?>"
                           id="<?= $this->e($inputId) ?>" name="<?= $this->e($name) ?>"
                           type="<?= ($field['secret'] ?? false) ? 'password' : 'text' ?>"
                           <?= ($field['secret'] ?? false) ? 'autocomplete="new-password"' : '' ?>
                           <?= $canManage ? '' : 'disabled' ?>
                           value="<?= ($field['secret'] ?? false) ? '' : $this->e((string) $value) ?>"
                           placeholder="<?= $stored ? 'Stored — leave blank to keep it' : $this->e($field['hint']) ?>">
                  <?php endif; ?>

                  <?php if ($field['hint'] !== '' && $field['type'] !== 'switch'): ?>
                    <p class="field__hint"><?= $this->e($field['hint']) ?></p>
                  <?php endif; ?>

                  <?php /* Its own block: nested inside the hint paragraph, this
                          button never rendered for the 20 secret fields that
                          declare no hint — including smtp.password and
                          cashfree.secret_key, the two an operator most needs to
                          be able to revoke. */ ?>
                  <?php if ($stored && $canManage && $field['type'] !== 'switch'): ?>
                    <p class="field__hint">
                      <button type="submit" class="btn btn--ghost btn--sm text-error"
                              formaction="/admin/integrations/<?= $this->e($key) ?>/secret/<?= $this->e($name) ?>/clear"
                              data-confirm="The stored value is deleted and this integration is switched off."
                              data-confirm-title="Remove this credential?" data-confirm-label="Remove"
                              data-confirm-tone="danger">Remove stored value</button>
                    </p>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>

              <?php if ($canManage): ?>
                <div class="row gap-2">
                  <button type="submit" class="btn btn--primary">
                    <?= $this->icon('check', 'ic ic--sm') ?> Save
                  </button>
                  <button type="submit" form="test-<?= $this->e($key) ?>" class="btn btn--outline">
                    <?= $this->icon('play', 'ic ic--sm') ?> Test connection
                  </button>
                  <?php if ($definition['sync']): ?>
                    <button type="submit" form="sync-<?= $this->e($key) ?>" class="btn btn--outline">
                      <?= $this->icon('refresh', 'ic ic--sm') ?> Sync now
                    </button>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </form>
          <?php endif; ?>

          <?php if ($definition['settings_map'] !== []): ?>
            <div class="callout callout--warning mt-4">
              <?= $this->icon('alert', 'ic ic--sm') ?>
              <div>
                <strong>These values are live.</strong>
                Saving here also writes the settings the running platform reads, so a change
                takes effect on the very next send. Fields mirrored:
                <?= $this->e(implode(', ', array_keys($definition['settings_map']))) ?>.
              </div>
            </div>
          <?php elseif ($state['reference_only']): ?>
            <?php /* The opposite case, and the one that used to be invisible:
                    a card that looks exactly like a live one but whose values
                    no code path reads. Saying so is the difference between an
                    operator believing the service is switched on and knowing
                    it is only recorded. */ ?>
            <div class="callout callout--info mt-4">
              <?= $this->icon('info', 'ic ic--sm') ?>
              <div>
                <strong>Stored for reference.</strong>
                Nothing on the platform reads these values yet — saving them records the
                credentials and lets the connection test check them, but no feature changes
                behaviour as a result. They become live when this service gains a consumer.
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Activity -->
      <div class="card">
        <div class="card__head">
          <h2 class="h3">Activity</h2>
          <span class="small muted"><?= $this->number(count($logs)) ?> most recent</span>
        </div>

        <?php if ($logs === []): ?>
          <div class="empty">
            <div class="empty__art"><?= $this->icon('file', 'ic ic--lg') ?></div>
            <p class="empty__title">Nothing recorded yet</p>
            <p class="empty__text">Tests, syncs, webhook deliveries and configuration changes appear here.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>When</th><th>Event</th><th>Result</th><th>Message</th><th>By</th></tr></thead>
              <tbody>
                <?php foreach ($logs as $log): ?>
                  <tr>
                    <td class="small muted" style="white-space:nowrap">
                      <?= $this->timeAgo($log['created_at']) ?>
                      <?php if ($log['duration_ms'] !== null): ?>
                        <div class="tiny faint"><?= $this->number((int) $log['duration_ms']) ?>ms</div>
                      <?php endif; ?>
                    </td>
                    <td><span class="chip chip--neutral"><?= $this->e(ucfirst((string) $log['event'])) ?></span></td>
                    <td>
                      <span class="chip chip--<?= match ($log['status']) {
                          'success' => 'success', 'failure' => 'error', 'warning' => 'warning', default => 'neutral' } ?>">
                        <?= $this->e(ucfirst((string) $log['status'])) ?>
                        <?php if (!empty($log['http_status'])): ?> · <?= (int) $log['http_status'] ?><?php endif; ?>
                      </span>
                    </td>
                    <td class="small truncate" style="max-width:340px" title="<?= $this->e($log['message']) ?>">
                      <?= $this->e($log['message']) ?>
                    </td>
                    <td class="tiny muted"><?= $this->e($log['admin_name'] ?? 'system') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Sidebar -->
    <div>
      <?php if ($definition['webhook']): ?>
        <div class="card mb-5">
          <div class="card__head"><h2 class="h3">Webhook</h2></div>
          <div class="card__body">
            <?php if ($newSecret !== null): ?>
              <div class="callout callout--success mb-3">
                <?= $this->icon('check-circle', 'ic ic--sm') ?>
                <div>
                  <strong>New signing secret — copy it now.</strong>
                  <code class="mono" style="word-break:break-all;display:block;margin-top:6px"><?= $this->e((string) $newSecret) ?></code>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($state['webhook_url'] !== null): ?>
              <div class="field">
                <label class="field__label">Callback URL</label>
                <div class="row gap-2">
                  <code class="mono small" style="word-break:break-all;flex:1"><?= $this->e($state['webhook_url']) ?></code>
                  <button type="button" class="btn btn--ghost btn--icon btn--sm"
                          data-copy="<?= $this->e($state['webhook_url']) ?>" aria-label="Copy URL">
                    <?= $this->icon('file', 'ic ic--sm') ?>
                  </button>
                </div>
                <p class="field__hint">Paste this into the provider's dashboard.</p>
              </div>
            <?php elseif (($definition['webhook_receiver'] ?? false) !== true): ?>
              <?php /* A URL was printed here for every provider that can send
                      callbacks, whether or not anything answered on it. Nine
                      of the ten 404'd, so a delivery pasted into the provider's
                      dashboard was dropped with nothing recorded. Better to
                      have no URL than a URL that silently discards. */ ?>
              <div class="callout callout--warning">
                <?= $this->icon('alert', 'ic ic--sm') ?>
                <div>
                  <strong>No callback endpoint yet.</strong>
                  <?= $this->e($definition['name']) ?> can send webhooks, but this platform does not
                  expose a URL that receives them — anything delivered would be answered with a 404
                  and lost. Nothing to paste into the provider's dashboard until a receiver is added.
                  The events and signing secret below are still stored, ready for that.
                </div>
              </div>
            <?php else: ?>
              <div class="callout callout--warning">
                <?= $this->icon('alert', 'ic ic--sm') ?>
                <div>Set <code>APP_URL</code> in the environment file before configuring webhooks.</div>
              </div>
            <?php endif; ?>

            <?php if ($canManage): ?>
              <form method="post" action="/admin/integrations/<?= $this->e($key) ?>/webhook" class="grid mt-3">
                <?= $this->csrf() ?>
                <div class="field">
                  <label class="field__label" for="events-<?= $this->e($key) ?>">Events</label>
                  <input class="input mono" id="events-<?= $this->e($key) ?>" name="webhook_events" maxlength="500"
                         value="<?= $this->e($row['webhook_events'] ?? '') ?>" placeholder="comma separated">
                </div>
                <div class="row gap-2">
                  <button type="submit" class="btn btn--outline btn--sm">Save</button>
                  <button type="submit" name="rotate" value="1" class="btn btn--ghost btn--sm"
                          data-confirm="The current secret stops working immediately. Update it at the provider straight after."
                          data-confirm-title="Rotate the signing secret?" data-confirm-label="Rotate"
                          data-confirm-tone="warning">Rotate secret</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($definition['sync']): ?>
        <div class="card mb-5">
          <div class="card__head"><h2 class="h3">Sync</h2></div>
          <div class="card__body">
            <div class="row gap-3 mb-3">
              <span class="chip chip--<?= match ($row['last_sync_status'] ?? 'never') {
                  'success' => 'success', 'failed' => 'error', 'running' => 'info', 'partial' => 'warning', default => 'neutral' } ?>">
                <?= $this->e(ucfirst((string) ($row['last_sync_status'] ?? 'never'))) ?>
              </span>
              <span class="small muted">
                <?= !empty($row['last_sync_at']) ? 'Last run ' . $this->timeAgo($row['last_sync_at']) : 'Never run' ?>
              </span>
            </div>

            <?php if (!empty($row['last_sync_summary'])): ?>
              <p class="small muted mb-3"><?= $this->e($row['last_sync_summary']) ?></p>
            <?php endif; ?>

            <?php if ($canManage): ?>
              <form method="post" action="/admin/integrations/<?= $this->e($key) ?>/schedule" class="grid">
                <?= $this->csrf() ?>
                <label class="row gap-2 small">
                  <input type="checkbox" name="auto_sync" value="1" <?= (int) ($row['auto_sync'] ?? 0) === 1 ? 'checked' : '' ?>>
                  Sync automatically
                </label>
                <div class="field">
                  <label class="field__label" for="int-<?= $this->e($key) ?>">Every (minutes)</label>
                  <input class="input" id="int-<?= $this->e($key) ?>" name="sync_interval_min" type="number"
                         min="5" max="10080" value="<?= (int) ($row['sync_interval_min'] ?? 60) ?>">
                  <p class="field__hint">
                    Runs on the <code>integrations:sync</code> cron job.
                    <?php if (!empty($row['next_sync_at'])): ?>
                      Next due <?= $this->timeAgo($row['next_sync_at']) ?>.
                    <?php endif; ?>
                  </p>
                </div>
                <div><button type="submit" class="btn btn--outline btn--sm">Save schedule</button></div>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__head"><h2 class="h3">Usage (30 days)</h2></div>
        <div class="card__body">
          <?php
          $totalCalls = array_sum(array_column($usage, 'calls'));
          $totalFails = array_sum(array_column($usage, 'failures'));
          ?>
          <div class="row gap-4 mb-3">
            <div><div class="label">Calls</div><div class="mono"><?= $this->number($totalCalls) ?></div></div>
            <div><div class="label">Failures</div><div class="mono"><?= $this->number($totalFails) ?></div></div>
            <div><div class="label">Success</div>
              <div class="mono"><?= $totalCalls > 0 ? (int) round(($totalCalls - $totalFails) / $totalCalls * 100) : 0 ?>%</div>
            </div>
          </div>

          <?php if ($totalCalls === 0): ?>
            <p class="small muted">No calls recorded yet.</p>
          <?php else: ?>
            <svg viewBox="0 0 300 60" preserveAspectRatio="none" style="width:100%;height:60px" role="img"
                 aria-label="Daily calls over the last 30 days">
              <?php foreach ($usage as $i => $point): ?>
                <?php
                $barWidth = 300 / max(1, count($usage));
                $height   = $point['calls'] > 0 ? max(2, (int) round($point['calls'] / $peakCalls * 54)) : 0;
                $x        = $i * $barWidth;
                ?>
                <?php if ($height > 0): ?>
                  <rect x="<?= round($x, 2) ?>" y="<?= 58 - $height ?>"
                        width="<?= round($barWidth - 1.5, 2) ?>" height="<?= $height ?>" rx="1"
                        fill="<?= $point['failures'] > 0 ? 'var(--error)' : $this->e($meta['tone']) ?>"
                        opacity="0.85"><title><?= $this->e($point['date']) ?>: <?= $point['calls'] ?> calls</title></rect>
                <?php endif; ?>
              <?php endforeach; ?>
            </svg>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($canManage): ?>
    <form id="test-<?= $this->e($key) ?>" method="post" action="/admin/integrations/<?= $this->e($key) ?>/test" hidden><?= $this->csrf() ?></form>
    <?php if ($definition['sync']): ?>
      <form id="sync-<?= $this->e($key) ?>" method="post" action="/admin/integrations/<?= $this->e($key) ?>/sync" hidden><?= $this->csrf() ?></form>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
  .icard__icon {
    display:inline-flex; align-items:center; justify-content:center;
    width:40px; height:40px; border-radius:var(--r);
    background:color-mix(in srgb, var(--tone) 12%, transparent); color:var(--tone);
  }
</style>
<?php $this->stop(); ?>

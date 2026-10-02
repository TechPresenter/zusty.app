<?php

declare(strict_types=1);

/**
 * Broadcasts, delivery queues and notification templates.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $broadcasts
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $queueStats
 * @var list<array<string,mixed>> $templates
 * @var array<string,int> $audienceSizes
 */

$this->extend('admin.layouts.app');
$this->start('content');

$canSend = $this->can('notifications.send');

?>

<div class="chip-row mb-5" data-tabs>
  <button class="chip-filter chip-filter--active" data-tab="#panel-compose">Compose</button>
  <button class="chip-filter" data-tab="#panel-history">History</button>
  <button class="chip-filter" data-tab="#panel-templates">Templates</button>
  <button class="chip-filter" data-tab="#panel-queues">Queues</button>
</div>

<!-- ===================== Compose ===================== -->
<div id="panel-compose">
  <?php if ($canSend): ?>
    <div class="grid grid--sidebar">
      <div class="card">
        <div class="card__head">
          <div>
            <h3 class="h3">New broadcast</h3>
            <p class="tiny muted">Sent immediately to every matching account</p>
          </div>
        </div>

        <div class="card__body">
          <form method="post" action="/admin/notifications/broadcast">
            <?= $this->csrf() ?>

            <div class="field">
              <label class="field__label" for="title">Title <span class="req">*</span></label>
              <input class="input" name="title" id="title" required maxlength="191"
                     placeholder="e.g. New creators just joined in Mumbai">
            </div>

            <div class="field">
              <label class="field__label" for="body">Message <span class="req">*</span></label>
              <textarea class="textarea" name="body" id="body" rows="4" required maxlength="1000"
                        placeholder="Keep it short — push notifications are truncated on most devices."></textarea>
              <p class="field__hint">Aim for under 120 characters so nothing is cut off.</p>
            </div>

            <div class="form-grid form-grid--2">
              <div class="field">
                <label class="field__label" for="action_type">Tap action</label>
                <select class="select" name="action_type" id="action_type">
                  <?php
                  // Labels for the `notifications`.`action_type` ENUM. Anything
                  // not in that column is not offered — 'screen' used to be, and
                  // every broadcast that chose it died on the first INSERT.
                  $actionLabels = [
                      'none'    => 'Do nothing',
                      'creator' => 'Open a creator',
                      'booking' => 'Open a booking',
                      'chat'    => 'Open a chat',
                      'wallet'  => 'Open the wallet',
                      'payment' => 'Open a payment',
                      'review'  => 'Open a review',
                      'ticket'  => 'Open a support ticket',
                      'kyc'     => 'Open KYC',
                      'url'     => 'Open a URL',
                  ];
                  ?>
                  <?php foreach (\Zuste\Services\NotificationService::actionTypes() as $actionType): ?>
                    <option value="<?= $this->e($actionType) ?>">
                      <?= $this->e($actionLabels[$actionType] ?? ucfirst($actionType)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label class="field__label" for="action_value">Action value</label>
                <input class="input" name="action_value" id="action_value" maxlength="255"
                       placeholder="e.g. /explore or a creator handle">
              </div>
            </div>

            <div class="field">
              <label class="field__label" for="image_url">Image URL</label>
              <input class="input" type="url" name="image_url" id="image_url" maxlength="500" placeholder="https://…">
            </div>

            <button type="submit" class="btn btn--primary"
                    data-confirm="This sends immediately to everyone in the selected audience and cannot be recalled."
                    data-confirm-title="Send this broadcast?" data-confirm-label="Send now" data-confirm-tone="warning">
              <?= $this->icon('megaphone', 'ic ic--sm') ?> Send broadcast
            </button>
          </form>
        </div>
      </div>

      <div class="col gap-4">
        <div class="card">
          <div class="card__head"><h3 class="h3">Audience</h3></div>
          <div class="card__body col gap-3">
            <?php foreach ([
                'all'               => 'Everyone',
                'brands'            => 'Brands only',
                'creators'          => 'Creators only',
                'verified_creators' => 'Verified creators',
            ] as $value => $label): ?>
              <label class="row gap-3" style="cursor:pointer">
                <input type="radio" name="audience" value="<?= $this->e($value) ?>"
                       form="" <?= $value === 'all' ? 'checked' : '' ?>
                       style="accent-color:var(--primary)"
                       onchange="document.querySelector('form[action=\'/admin/notifications/broadcast\'] input[name=audience]').value = this.value">
                <div class="flex-1">
                  <div class="small" style="font-weight:600"><?= $this->e($label) ?></div>
                  <div class="tiny muted"><?= $this->number((int) ($audienceSizes[$value] ?? 0)) ?> recipients</div>
                </div>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card">
          <div class="card__head"><h3 class="h3">Channels</h3></div>
          <div class="card__body col gap-3">
            <?php foreach ([
                'in_app' => ['In-app notification centre', true],
                'push'   => ['Push notification', true],
                'email'  => ['Email', false],
            ] as $value => [$label, $checked]): ?>
              <label class="switch">
                <input type="checkbox" name="channels[]" value="<?= $this->e($value) ?>"
                       form="" <?= $checked ? 'checked' : '' ?>
                       onchange="syncChannels()">
                <span class="switch__track"></span>
                <span class="small"><?= $this->e($label) ?></span>
              </label>
            <?php endforeach; ?>
            <p class="tiny faint mt-2">
              Email is queued and delivered by the <span class="mono">queue:dispatch</span> job.
            </p>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="empty">
        <div class="empty__art"><?= $this->icon('megaphone', 'ic ic--lg') ?></div>
        <p class="empty__title">No permission</p>
        <p class="empty__text">You need the <span class="mono">notifications.send</span> permission to broadcast.</p>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- ===================== History ===================== -->
<div id="panel-history" class="hidden">
  <div class="card">
    <?php if ($broadcasts === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('megaphone', 'ic ic--lg') ?></div>
        <p class="empty__title">No broadcasts yet</p>
        <p class="empty__text">Anything you send appears here with its delivery count.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Title</th><th>Audience</th><th>Channels</th><th class="text-right">Recipients</th>
                <th class="text-right">Delivered</th><th>Status</th><th>Sent by</th><th>When</th></tr>
          </thead>
          <tbody>
            <?php foreach ($broadcasts as $broadcast): ?>
              <?php $channels = json_decode((string) $broadcast['channels'], true) ?: []; ?>
              <tr>
                <td>
                  <div class="small" style="font-weight:600"><?= $this->e($broadcast['title']) ?></div>
                  <div class="tiny muted clamp-2" style="max-width:280px"><?= $this->e($broadcast['body']) ?></div>
                </td>
                <td><span class="chip chip--neutral"><?= $this->e(ucwords(str_replace('_', ' ', (string) $broadcast['audience']))) ?></span></td>
                <td class="tiny muted"><?= $this->e(implode(', ', array_map('strval', $channels))) ?></td>
                <td class="text-right mono"><?= $this->number($broadcast['recipients_count']) ?></td>
                <td class="text-right mono"><?= $this->number($broadcast['delivered_count']) ?></td>
                <td>
                  <span class="chip chip--<?= $broadcast['status'] === 'sent' ? 'success' : ($broadcast['status'] === 'failed' ? 'error' : 'warning') ?>">
                    <?= $this->e(ucfirst((string) $broadcast['status'])) ?>
                  </span>
                </td>
                <td class="small muted"><?= $this->e($broadcast['author_name'] ?? '—') ?></td>
                <td class="small muted" style="white-space:nowrap"><?= $this->dateTime($broadcast['sent_at'] ?? $broadcast['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
    <?php endif; ?>
  </div>
</div>

<!-- ===================== Templates ===================== -->
<div id="panel-templates" class="hidden">
  <div class="card mb-5" style="border-color:var(--secondary);background:color-mix(in srgb, var(--secondary) 6%, transparent)">
    <div class="card__body row gap-3" style="padding:14px 18px">
      <span class="text-secondary"><?= $this->icon('info') ?></span>
      <p class="small">
        Templates power every automatic notification. Use <span class="mono">{{placeholder}}</span> tokens —
        they are substituted at send time with values such as <span class="mono">{{creator_name}}</span>
        and <span class="mono">{{amount}}</span>.
      </p>
    </div>
  </div>

  <div class="col gap-4">
    <?php foreach ($templates as $template): ?>
      <?php $channels = json_decode((string) $template['channels'], true) ?: []; ?>
      <div class="card">
        <div class="card__head">
          <div>
            <h3 class="h3"><?= $this->e($template['name']) ?></h3>
            <p class="tiny muted mono"><?= $this->e($template['code']) ?> · <?= $this->e($template['category']) ?></p>
          </div>
          <span class="chip chip--<?= $template['is_active'] ? 'success' : 'neutral' ?>">
            <?= $template['is_active'] ? 'Active' : 'Disabled' ?>
          </span>
        </div>

        <div class="card__body">
          <?php if ($this->can('notifications.templates')): ?>
            <form method="post" action="/admin/notifications/templates/<?= (int) $template['id'] ?>">
              <?= $this->csrf() ?>

              <div class="form-grid form-grid--2">
                <div class="field">
                  <label class="field__label">Title</label>
                  <input class="input" name="title" required maxlength="191" value="<?= $this->e($template['title']) ?>">
                </div>
                <div class="field">
                  <label class="field__label">Email subject</label>
                  <input class="input" name="email_subject" maxlength="191" value="<?= $this->e($template['email_subject'] ?? '') ?>">
                </div>
              </div>

              <div class="field">
                <label class="field__label">Body</label>
                <textarea class="textarea" name="body" rows="2" required maxlength="1000"><?= $this->e($template['body']) ?></textarea>
              </div>

              <div class="field">
                <label class="field__label">SMS body</label>
                <input class="input" name="sms_body" maxlength="500" value="<?= $this->e($template['sms_body'] ?? '') ?>">
              </div>

              <div class="row gap-4 row--wrap mb-4">
                <?php foreach (['in_app' => 'In-app', 'push' => 'Push', 'email' => 'Email', 'sms' => 'SMS'] as $value => $label): ?>
                  <label class="checkbox small">
                    <input type="checkbox" name="channels[]" value="<?= $this->e($value) ?>"
                           <?= in_array($value, $channels, true) ? 'checked' : '' ?>>
                    <span><?= $this->e($label) ?></span>
                  </label>
                <?php endforeach; ?>

                <label class="switch">
                  <input type="checkbox" name="is_active" value="1" <?= $template['is_active'] ? 'checked' : '' ?>>
                  <span class="switch__track"></span>
                  <span class="small">Enabled</span>
                </label>
              </div>

              <button type="submit" class="btn btn--outline btn--sm">Save template</button>
            </form>
          <?php else: ?>
            <p class="small"><strong><?= $this->e($template['title']) ?></strong></p>
            <p class="small muted mt-1"><?= $this->e($template['body']) ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ===================== Queues ===================== -->
<div id="panel-queues" class="hidden">
  <div class="grid grid--3">
    <?php foreach ([
        ['Push queue', $queueStats, 'bell'],
    ] as [$label, $stats, $icon]): ?>
      <div class="card">
        <div class="card__head"><h3 class="h3"><?= $this->e($label) ?></h3></div>
        <div class="card__body col gap-3">
          <div class="row row--between">
            <span class="small muted">Pending</span>
            <span class="chip chip--warning"><?= $this->number((int) ($stats['pending'] ?? 0)) ?></span>
          </div>
          <div class="row row--between">
            <span class="small muted">Sent</span>
            <span class="chip chip--success"><?= $this->number((int) ($stats['sent'] ?? 0)) ?></span>
          </div>
          <div class="row row--between">
            <span class="small muted">Failed</span>
            <span class="chip chip--error"><?= $this->number((int) ($stats['failed'] ?? 0)) ?></span>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="card">
      <div class="card__head"><h3 class="h3">Delivery</h3></div>
      <div class="card__body">
        <p class="small muted mb-4">
          Queues are drained by the <span class="mono">queue:dispatch</span> job, which should run every minute.
        </p>
        <?php if ($this->can('system.jobs')): ?>
          <form method="post" action="/admin/system/jobs/queue:dispatch/run">
            <?= $this->csrf() ?>
            <button type="submit" class="btn btn--outline btn--block">
              <?= $this->icon('refresh', 'ic ic--sm') ?> Drain queues now
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
// The audience radios and channel switches live outside the form for layout
// reasons; mirror their values into hidden inputs on submit.
(function () {
  const form = document.querySelector('form[action="/admin/notifications/broadcast"]');
  if (!form) return;

  const audience = document.createElement('input');
  audience.type = 'hidden';
  audience.name = 'audience';
  audience.value = 'all';
  form.appendChild(audience);

  document.querySelectorAll('input[name="audience"][type="radio"]').forEach(function (radio) {
    radio.addEventListener('change', function () { audience.value = radio.value; });
  });

  window.syncChannels = function () {
    form.querySelectorAll('input[name="channels[]"][type="hidden"]').forEach(function (el) { el.remove(); });

    document.querySelectorAll('input[name="channels[]"][type="checkbox"]:checked').forEach(function (box) {
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'channels[]';
      hidden.value = box.value;
      form.appendChild(hidden);
    });
  };

  syncChannels();
})();
</script>
<?php $this->stop(); ?>

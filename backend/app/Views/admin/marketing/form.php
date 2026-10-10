<?php

declare(strict_types=1);

/**
 * Create or edit a marketing campaign.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $campaign
 * @var array<string,string> $filter
 * @var list<array<string,mixed>> $categories
 * @var bool $isNew
 * @var bool $trackingOn
 * @var bool $unsubscribeOn
 * @var bool $schedulerOn
 */

$this->extend('admin.layouts.app');
$this->start('content');

$campaignId = (int) ($campaign['id'] ?? 0);
$action     = $isNew ? '/admin/marketing' : '/admin/marketing/' . $campaignId;

$currentChannel  = (string) ($campaign['channel'] ?? 'email');
$currentAudience = (string) ($campaign['audience'] ?? 'all');

// The picker offers exactly what this build can deliver. A campaign saved on an
// earlier build keeps its own channel as one extra option, so opening and
// saving it can never rewrite the channel — and with it every destination —
// behind the operator's back.
$channelKeys = \Zuste\Services\CampaignService::CHANNELS;

if (in_array($currentChannel, \Zuste\Services\CampaignService::RETIRED_CHANNELS, true)) {
    $channelKeys[] = $currentChannel;
}

$channelLabels = [
    'email'    => 'Email',
    'sms'      => 'SMS',
    'push'     => 'Push notification',
    'whatsapp' => 'WhatsApp (retired, cannot be sent)',
];

$channels = [];
foreach ($channelKeys as $channelKey) {
    $support = \Zuste\Services\CampaignService::channelSupport($channelKey);

    $channels[$channelKey] = [
        'wired'   => $support['wired'],
        'ready'   => $support['ready'],
        'note'    => $support['note'],
        'blocker' => $support['blocker'],
    ];
}

// datetime-local wants "Y-m-dTH:i".
$scheduledValue = '';
if (!empty($campaign['scheduled_at'])) {
    $timestamp = strtotime((string) $campaign['scheduled_at']);
    $scheduledValue = $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
}

// Exactly the statuses the service will honour in a stored filter, so the form
// can always show back what was saved.
$statusOptions = [
    ''          => 'Any, except suspended',
    'pending'   => 'Pending only',
    'active'    => 'Active only',
    'suspended' => 'Suspended only',
];

?>

<form method="post" action="<?= $this->e($action) ?>" class="grid grid--sidebar" id="campaign-form">
  <?= $this->csrf() ?>

  <!-- ================= Message ================= -->
  <div class="grid">

    <div class="card">
      <div class="card__head"><h2 class="h3">Message</h2></div>

      <div class="card__body grid">

        <div class="field">
          <label class="field__label req" for="name">Internal name</label>
          <input class="input" id="name" name="name" required maxlength="150"
                 value="<?= $this->old('name', (string) ($campaign['name'] ?? '')) ?>"
                 placeholder="March creator newsletter"
                 <?= $this->hasError('name') ? 'aria-invalid="true"' : '' ?>>
          <p class="field__hint">Only staff see this. Use something you will recognise in six months.</p>
          <?php if ($this->hasError('name')): ?>
            <p class="field__error"><?= $this->error('name') ?></p>
          <?php endif; ?>
        </div>

        <div class="grid grid--2">
          <div class="field">
            <label class="field__label req" for="channel">Channel</label>
            <select class="select" id="channel" name="channel" required>
              <?php foreach ($channelKeys as $value): ?>
                <option value="<?= $this->e($value) ?>" <?= $currentChannel === $value ? 'selected' : '' ?>>
                  <?= $this->e($channelLabels[$value] ?? ucfirst($value)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if ($this->hasError('channel')): ?>
              <p class="field__error"><?= $this->error('channel') ?></p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label class="field__label" for="scheduled_at">Schedule (optional)</label>
            <input class="input" id="scheduled_at" name="scheduled_at" type="datetime-local"
                   value="<?= $this->e($scheduledValue) ?>">
            <p class="field__hint">
              <?php if ($schedulerOn): ?>
                Leave blank to keep it a draft. A scheduled campaign is picked up by the
                <code class="mono"><?= $this->e(\Zuste\Services\CampaignService::SCHEDULER_JOB) ?></code> job.
              <?php else: ?>
                Leave blank to keep it a draft. Nothing sends a schedule automatically in this build — no
                <code class="mono"><?= $this->e(\Zuste\Services\CampaignService::SCHEDULER_JOB) ?></code> job is
                registered — so a date here is a reminder, and you still press <em>Send now</em>.
              <?php endif; ?>
            </p>
            <?php if ($this->hasError('scheduled_at')): ?>
              <p class="field__error"><?= $this->error('scheduled_at') ?></p>
            <?php endif; ?>
          </div>
        </div>

        <div class="callout callout--info" id="channel-note">
          <?= $this->icon('info', 'ic ic--sm') ?>
          <div data-channel-note></div>
        </div>

        <div class="field">
          <label class="field__label" for="subject">Subject line</label>
          <input class="input" id="subject" name="subject" maxlength="191"
                 value="<?= $this->old('subject', (string) ($campaign['subject'] ?? '')) ?>"
                 placeholder="Your March collaborations are live">
          <p class="field__hint">
            Used as the email subject and the push title. Falls back to the internal name if left blank.
            SMS ignores it.
          </p>
          <?php if ($this->hasError('subject')): ?>
            <p class="field__error"><?= $this->error('subject') ?></p>
          <?php endif; ?>
        </div>

        <div class="field">
          <label class="field__label" for="preheader">Preheader</label>
          <input class="input" id="preheader" name="preheader" maxlength="191"
                 value="<?= $this->old('preheader', (string) ($campaign['preheader'] ?? '')) ?>"
                 placeholder="A one-line summary shown after the subject in most inboxes">
          <p class="field__hint">Stored with the campaign for your reference. Add it to the body yourself if you want it rendered.</p>
        </div>

        <div class="field">
          <label class="field__label req" for="content">Body</label>
          <?php /* The campaign body is HTML email — the placeholder below is
                   literally a <p> tag — so it gets the rich editor. Push
                   notification bodies deliberately do NOT: a push payload is
                   plain text and markup there is either ignored or shown raw. */ ?>
          <textarea class="textarea" id="content" name="content" rows="16" required
                    data-editor="rich"
                    placeholder="&lt;p&gt;Hi {{first_name}},&lt;/p&gt;"
                    <?= $this->hasError('content') ? 'aria-invalid="true"' : '' ?>><?= $this->old('content', (string) ($campaign['content'] ?? '')) ?></textarea>
          <p class="field__hint">
            HTML for email. For SMS and push the tags are stripped and the plain text is sent.
            Placeholders: <code class="mono">{{name}}</code>, <code class="mono">{{first_name}}</code>,
            <code class="mono">{{email}}</code>, <code class="mono">{{campaign_name}}</code>,
            <code class="mono">{{unsubscribe_url}}</code>.
            <?php if (!$unsubscribeOn): ?>
              No one-click unsubscribe endpoint is configured, so
              <code class="mono">{{unsubscribe_url}}</code> resolves to a mailto: address rather than a link that
              removes someone by itself.
            <?php endif; ?>
          </p>
          <?php if ($this->hasError('content')): ?>
            <p class="field__error"><?= $this->error('content') ?></p>
          <?php endif; ?>
        </div>

        <div class="row row--between">
          <button type="button" class="btn btn--outline btn--sm" data-toggle-preview aria-expanded="false">
            <?= $this->icon('eye', 'ic ic--sm') ?> <span data-preview-label>Preview the body</span>
          </button>
          <span class="tiny faint">Rendered in a sandboxed frame with scripts disabled.</span>
        </div>

        <iframe class="mkt-preview hidden" title="Message preview" sandbox referrerpolicy="no-referrer"></iframe>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <h2 class="h3">Audience</h2>
        <span class="small muted">Suppression rules always apply</span>
      </div>

      <div class="card__body grid">

        <div class="field">
          <label class="field__label req" for="audience">Who receives this</label>
          <select class="select" id="audience" name="audience" required>
            <?php foreach ([
                'all'               => 'Everyone with an active account',
                'brands'            => 'Brand accounts',
                'creators'          => 'Accounts with a creator profile',
                'verified_creators' => 'Verified creators only',
                'subscribers'       => 'Newsletter list (email only)',
                'custom'            => 'Custom filter',
            ] as $value => $label): ?>
              <option value="<?= $this->e($value) ?>" <?= $currentAudience === $value ? 'selected' : '' ?>>
                <?= $this->e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($this->hasError('audience')): ?>
            <p class="field__error"><?= $this->error('audience') ?></p>
          <?php endif; ?>
        </div>

        <div class="grid grid--3 hidden" data-custom-filters>
          <div class="field">
            <label class="field__label" for="filter_user_type">Account type</label>
            <select class="select" id="filter_user_type" name="filter_user_type">
              <option value="">Any</option>
              <option value="brand" <?= ($filter['user_type'] ?? '') === 'brand' ? 'selected' : '' ?>>Brand</option>
              <option value="creator" <?= ($filter['user_type'] ?? '') === 'creator' ? 'selected' : '' ?>>Creator</option>
            </select>
          </div>

          <div class="field">
            <label class="field__label" for="filter_status">Account status</label>
            <select class="select" id="filter_status" name="filter_status">
              <?php foreach ($statusOptions as $value => $label): ?>
                <option value="<?= $this->e($value) ?>" <?= ($filter['status'] ?? '') === (string) $value ? 'selected' : '' ?>>
                  <?= $this->e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="field__label" for="filter_is_verified">Creator verification</label>
            <select class="select" id="filter_is_verified" name="filter_is_verified">
              <option value="">Any</option>
              <option value="1" <?= ($filter['is_verified'] ?? '') === '1' ? 'selected' : '' ?>>Verified creators</option>
              <option value="0" <?= ($filter['is_verified'] ?? '') === '0' ? 'selected' : '' ?>>Unverified creators</option>
            </select>
            <p class="field__hint">Setting this limits the audience to creator profiles.</p>
          </div>

          <div class="field">
            <label class="field__label" for="filter_created_after">Joined on or after</label>
            <input class="input" id="filter_created_after" name="filter_created_after" type="date"
                   value="<?= $this->e($filter['created_after'] ?? '') ?>">
          </div>

          <div class="field">
            <label class="field__label" for="filter_created_before">Joined on or before</label>
            <input class="input" id="filter_created_before" name="filter_created_before" type="date"
                   value="<?= $this->e($filter['created_before'] ?? '') ?>">
          </div>

          <div class="field">
            <label class="field__label" for="filter_category_id">Creator category</label>
            <select class="select" id="filter_category_id" name="filter_category_id">
              <option value="">Any</option>
              <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>"
                  <?= ($filter['category_id'] ?? '') === (string) $category['id'] ? 'selected' : '' ?>>
                  <?= $this->e($category['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="field__hint">Matches the primary category or any secondary one.</p>
          </div>
        </div>

        <div class="callout callout--info">
          <?= $this->icon('shield', 'ic ic--sm') ?>
          <div>
            <strong>Always excluded, whatever you pick:</strong>
            banned and deleted accounts, suspended accounts unless you ask for them by status below,
            anyone who has not opted in to product updates and offers (marketing is off until
            the person ticks it at sign-up or turns it on in their notification settings),
            and — for email — any address on the newsletter list marked unsubscribed or bounced.
            Duplicate destinations are collapsed to one.
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ================= Side ================= -->
  <div class="grid">

    <div class="card">
      <div class="card__head"><h2 class="h3">Reach</h2></div>

      <div class="card__body grid">
        <p class="small muted" data-audience-description>Choose an audience, then check the real number.</p>

        <button type="button" class="btn btn--outline" data-preview-audience>
          <?= $this->icon('users', 'ic ic--sm') ?> Preview audience
        </button>

        <div data-audience-result></div>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2 class="h3">Save</h2></div>

      <div class="card__body grid">
        <?php if (!$trackingOn): ?>
          <p class="tiny muted">
            Open and click tracking is off, so those counters stay at zero. There is no public tracking
            endpoint in this build and no setting that turns one on — it is a developer change.
          </p>
        <?php endif; ?>

        <?php if (!$unsubscribeOn): ?>
          <p class="tiny muted">
            There is no one-click unsubscribe endpoint in this build, so the email footer links to the support
            address instead and someone has to action each request by hand. Plan for that before sending to a
            large list.
          </p>
        <?php endif; ?>

        <button type="submit" class="btn btn--primary">
          <?= $this->icon('check', 'ic ic--sm') ?>
          <?= $isNew ? 'Create campaign' : 'Save changes' ?>
        </button>

        <a href="<?= $isNew ? '/admin/marketing' : '/admin/marketing/' . $campaignId ?>" class="btn btn--ghost">Cancel</a>

        <p class="tiny faint">
          Saving never sends anything. Dispatch happens from the campaign page, and needs the
          send permission.
        </p>
      </div>
    </div>
  </div>
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
  .mkt-preview {
    width: 100%;
    min-height: 320px;
    border: 1px solid var(--outline-variant);
    border-radius: var(--r-md);
    background: #ffffff;
  }
  .mkt-sample {
    border: 1px solid var(--outline-variant);
    border-radius: var(--r);
    overflow: hidden;
  }
  .mkt-sample__row {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 8px 10px;
    border-bottom: 1px solid var(--outline-variant);
  }
  .mkt-sample__row:last-child { border-bottom: 0; }
  .mkt-count {
    font-size: 30px;
    font-weight: 800;
    line-height: 1.1;
    letter-spacing: -0.02em;
  }
</style>
<script>
(function () {
  'use strict';

  const form        = document.getElementById('campaign-form');
  const channel     = document.getElementById('channel');
  const audience    = document.getElementById('audience');
  const noteBox     = document.getElementById('channel-note');
  const noteText    = noteBox ? noteBox.querySelector('[data-channel-note]') : null;
  const customBlock = document.querySelector('[data-custom-filters]');
  const resultBox   = document.querySelector('[data-audience-result]');
  const description = document.querySelector('[data-audience-description]');
  const previewBtn  = document.querySelector('[data-preview-audience]');
  const bodyField   = document.getElementById('content');
  const frame       = document.querySelector('.mkt-preview');
  const toggleBtn   = document.querySelector('[data-toggle-preview]');
  const toggleLabel = toggleBtn ? toggleBtn.querySelector('[data-preview-label]') : null;

  const CHANNELS = <?= $this->json($channels) ?>;
  const csrf     = document.querySelector('meta[name="csrf-token"]');

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function paintChannelNote() {
    if (!noteBox || !noteText || !channel) return;

    const info = CHANNELS[channel.value] || CHANNELS.email;
    let tone = 'callout--info';
    let text = info.note;

    if (!info.wired) {
      tone = 'callout--error';
    } else if (!info.ready) {
      tone = 'callout--warning';
      text = info.note + ' ' + info.blocker;
    }

    noteBox.className = 'callout ' + tone;
    noteText.textContent = text;
  }

  function paintCustomBlock() {
    if (!customBlock || !audience) return;
    customBlock.classList.toggle('hidden', audience.value !== 'custom');
  }

  function collectFilters() {
    const body = new URLSearchParams();
    body.set('channel', channel ? channel.value : 'email');
    body.set('audience', audience ? audience.value : 'all');

    if (audience && audience.value === 'custom') {
      ['user_type', 'status', 'is_verified', 'created_after', 'created_before', 'category_id'].forEach(function (key) {
        const field = document.getElementById('filter_' + key);
        if (field && field.value !== '') body.set('filter_' + key, field.value);
      });
    }

    return body;
  }

  function renderPreview(payload) {
    if (!resultBox) return;

    if (payload.blocked) {
      resultBox.innerHTML = '<div class="callout callout--error">' + escapeHtml(payload.blocked) + '</div>';
      return;
    }

    let html = '<div class="mkt-count">' + escapeHtml(Number(payload.total).toLocaleString('en-IN')) + '</div>'
             + '<p class="small muted mb-3">' + (payload.total === 1 ? 'recipient matches right now' : 'recipients match right now') + '</p>';

    (payload.warnings || []).forEach(function (warning) {
      html += '<div class="callout callout--warning mb-3">' + escapeHtml(warning) + '</div>';
    });

    if (payload.sample && payload.sample.length) {
      html += '<p class="label mb-1">First ' + payload.sample.length + '</p><div class="mkt-sample">';
      payload.sample.forEach(function (row) {
        html += '<div class="mkt-sample__row">'
              + '<span class="small" style="font-weight:600">' + escapeHtml(row.name) + '</span>'
              + '<span class="tiny mono muted">' + escapeHtml(row.destination) + '</span>'
              + '</div>';
      });
      html += '</div>';
    }

    resultBox.innerHTML = html;

    if (description && payload.description) description.textContent = payload.description;
  }

  if (previewBtn) {
    previewBtn.addEventListener('click', async function () {
      previewBtn.classList.add('btn--loading');
      previewBtn.disabled = true;

      if (resultBox) resultBox.innerHTML = '<div class="skeleton" style="height:72px"></div>';

      try {
        const response = await fetch('/admin/marketing/preview-audience', {
          method: 'POST',
          headers: {
            'X-CSRF-Token': csrf ? csrf.content : '',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: collectFilters().toString()
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
          throw new Error(result.message || 'The audience could not be resolved.');
        }

        renderPreview(result.data || {});
      } catch (error) {
        if (resultBox) {
          resultBox.innerHTML = '<div class="callout callout--error">' + escapeHtml(error.message) + '</div>';
        }
        if (window.Admin) window.Admin.toast(error.message, 'error');
      } finally {
        previewBtn.classList.remove('btn--loading');
        previewBtn.disabled = false;
      }
    });
  }

  if (toggleBtn && toggleLabel && frame && bodyField) {
    toggleBtn.addEventListener('click', function () {
      const hidden = frame.classList.contains('hidden');

      if (hidden) {
        // srcdoc inside a sandboxed frame: no scripts, no same-origin access.
        frame.srcdoc = bodyField.value;
        frame.classList.remove('hidden');
        toggleLabel.textContent = 'Hide the preview';
      } else {
        frame.classList.add('hidden');
        frame.srcdoc = '';
        toggleLabel.textContent = 'Preview the body';
      }

      toggleBtn.setAttribute('aria-expanded', hidden ? 'true' : 'false');
    });
  }

  if (channel) channel.addEventListener('change', paintChannelNote);
  if (audience) audience.addEventListener('change', paintCustomBlock);

  paintChannelNote();
  paintCustomBlock();

  // A stray Enter inside a text input should not submit a long campaign form.
  if (form) {
    form.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && event.target.tagName === 'INPUT' && event.target.type !== 'submit') {
        event.preventDefault();
      }
    });
  }
})();
</script>
<?php $this->stop(); ?>

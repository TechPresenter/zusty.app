<?php

declare(strict_types=1);

/**
 * Campaign detail: live delivery statistics and the recipient list.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $campaign
 * @var array<string,mixed> $stats
 * @var array<string,int> $counts
 * @var list<array<string,mixed>> $recipients
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $support
 * @var string $audienceLabel
 * @var int $audienceToday
 * @var list<string> $allowed
 * @var bool $trackingOn
 * @var bool $schedulerOn
 * @var int $batchSize
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$id      = (int) $campaign['id'];
$status  = (string) $campaign['status'];
$channel = (string) $campaign['channel'];

$recipientTone = static fn (string $s): string => match ($s) {
    'sent'         => 'success',
    'opened'       => 'info',
    'clicked'      => 'info',
    'failed'       => 'error',
    'bounced'      => 'error',
    'unsubscribed' => 'warning',
    default        => 'neutral',
};

$canSend   = $this->can('marketing.send');
$canManage = $this->can('marketing.manage');

$progress = (float) $stats['progress'];

?>

<div class="row row--between row--wrap gap-3 mb-5">
  <div class="row gap-3 row--wrap">
    <span class="chip chip--<?= $this->e(\Zuste\Services\CampaignService::statusTone($status)) ?><?= $status === 'sending' ? ' chip--pulse' : '' ?>">
      <span class="chip__dot"></span><?= $this->e(ucfirst($status)) ?>
    </span>
    <span class="chip chip--neutral"><?= $this->e(strtoupper($channel)) ?></span>
    <?php if (!empty($campaign['scheduled_at'])): ?>
      <span class="chip chip--neutral">
        <?= $this->icon('calendar', 'ic ic--sm') ?> <?= $this->dateTime($campaign['scheduled_at']) ?>
      </span>
    <?php endif; ?>
  </div>

  <div class="row gap-2 row--wrap">
    <a href="/admin/marketing" class="btn btn--ghost btn--sm">
      <?= $this->icon('chevron-left', 'ic ic--sm') ?> All campaigns
    </a>

    <?php if ($canManage && !in_array($status, ['sending', 'sent'], true)): ?>
      <a href="/admin/marketing/<?= $id ?>/edit" class="btn btn--outline btn--sm">
        <?= $this->icon('edit', 'ic ic--sm') ?> Edit
      </a>
    <?php endif; ?>

    <?php if ($canSend && in_array($status, ['draft', 'scheduled', 'failed'], true) && $support['wired']): ?>
      <form method="post" action="/admin/marketing/<?= $id ?>/send" style="display:inline">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--primary btn--sm"
                data-confirm="This resolves the audience, writes the recipient list and hands the first <?= (int) $batchSize ?> messages to the <?= $this->e($channel) ?> queue. It cannot be undone for messages already queued."
                data-confirm-title="Send this campaign?" data-confirm-label="Send now" data-confirm-tone="danger">
          <?= $this->icon('megaphone', 'ic ic--sm') ?>
          <?= $status === 'failed' ? 'Retry sending' : 'Send now' ?>
        </button>
      </form>
    <?php endif; ?>

    <?php if ($canSend && $status === 'sending'): ?>
      <form method="post" action="/admin/marketing/<?= $id ?>/dispatch" style="display:inline">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--primary btn--sm">
          <?= $this->icon('chevron-right', 'ic ic--sm') ?> Send next <?= (int) $batchSize ?>
        </button>
      </form>

      <form method="post" action="/admin/marketing/<?= $id ?>/pause" style="display:inline">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--outline btn--sm"
                data-confirm="Nothing further will be queued until you resume. Messages already on the delivery queue still go out."
                data-confirm-title="Pause the campaign?" data-confirm-label="Pause" data-confirm-tone="warning">
          Pause
        </button>
      </form>
    <?php endif; ?>

    <?php if ($canSend && $status === 'paused'): ?>
      <form method="post" action="/admin/marketing/<?= $id ?>/resume" style="display:inline">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--primary btn--sm">
          <?= $this->icon('refresh', 'ic ic--sm') ?> Resume
        </button>
      </form>
    <?php endif; ?>

    <?php if ($canSend && in_array($status, ['draft', 'scheduled', 'sending', 'paused'], true)): ?>
      <form method="post" action="/admin/marketing/<?= $id ?>/cancel" style="display:inline">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--ghost btn--sm text-error"
                data-confirm="<?= in_array($status, ['draft', 'scheduled'], true)
                    ? 'The schedule and any pending recipients are cleared and the campaign goes back to draft.'
                    : 'Delivery stops for good. Every recipient still pending is marked failed with the reason recorded. Messages already on the delivery queue still go out.' ?>"
                data-confirm-title="Cancel the campaign?" data-confirm-label="Cancel campaign" data-confirm-tone="danger">
          <?= $this->icon('x', 'ic ic--sm') ?> Cancel
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$support['wired']): ?>
  <div class="callout callout--error mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div><strong>This channel cannot dispatch.</strong> <?= $this->e($support['note']) ?></div>
  </div>
<?php elseif (!$support['ready']): ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div><strong>Dispatch is blocked.</strong> <?= $this->e($support['blocker']) ?></div>
  </div>
<?php else: ?>
  <div class="callout callout--info mb-5">
    <?= $this->icon('info', 'ic ic--sm') ?>
    <div><?= $this->e($support['note']) ?></div>
  </div>
<?php endif; ?>

<?php if (!$trackingOn): ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>Open and click tracking is not recording.</strong>
      No tracking endpoint is configured, so no open pixel goes out with this campaign and no click is redirected
      through one. The <em>Opened</em> and <em>Clicked</em> figures below stay at whatever is already stored and will
      not move for this send. Everything else on this page — delivered, failed, bounced and unsubscribed — is written
      by the dispatcher and is accurate.
    </div>
  </div>
<?php endif; ?>

<?php if ($status === 'scheduled' && !$schedulerOn): ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>This schedule will not fire on its own.</strong>
      No <code class="mono"><?= $this->e(\Zuste\Services\CampaignService::SCHEDULER_JOB) ?></code> job is registered with
      the cron runner, so nothing is watching the clock. Use <em>Send now</em> when you want it to go out.
    </div>
  </div>
<?php endif; ?>

<?php if ($status === 'sending'): ?>
  <div class="card mb-5">
    <div class="card__body">
      <div class="row row--between mb-2">
        <span class="label">Delivery progress</span>
        <span class="small mono"><?= $this->e((string) $progress) ?>%</span>
      </div>
      <div class="mkt-bar mkt-bar--lg">
        <span style="width:<?= $this->e((string) max(0, min(100, $progress))) ?>%"></span>
      </div>
      <p class="tiny muted mt-2">
        <?= $this->number((int) $stats['sent']) ?> handed to the queue,
        <?= $this->number((int) $stats['failed']) ?> failed,
        <?= $this->number((int) $stats['pending']) ?> still pending.
        Each click of <em>Send next <?= (int) $batchSize ?></em> moves one batch<?= $schedulerOn
            ? ', and the ' . $this->e(\Zuste\Services\CampaignService::SCHEDULER_JOB) . ' job does the same unattended.'
            : '. No scheduled job is registered, so the remaining batches need a click each.' ?>
      </p>
    </div>
  </div>
<?php endif; ?>

<div class="wgrid mb-5">
  <?php
  $tiles = [
      ['Recipients', (int) $stats['total'], '#B80049', 'users', $audienceLabel],
      ['Delivered to queue', (int) $stats['sent'], '#006A34', 'check-circle', $stats['delivery_rate'] . '% of the list'],
      ['Failed', (int) $stats['failed'], '#BA1A1A', 'alert', $stats['bounced'] > 0 ? $this->number((int) $stats['bounced']) . ' bounced' : 'No bounces recorded'],
      ['Opened', (int) $stats['opened'], '#2563EB', 'eye', $trackingOn ? $stats['open_rate'] . '% open rate' : 'Tracking is off'],
      ['Clicked', (int) $stats['clicked'], '#632CE5', 'external', $trackingOn ? $stats['click_rate'] . '% click rate' : 'Tracking is off'],
      ['Unsubscribed', (int) $stats['unsubscribed'], '#B45309', 'x', 'Recorded against this campaign'],
  ];
  ?>
  <?php foreach ($tiles as [$label, $value, $tone, $icon, $foot]): ?>
    <div class="wgrid__item" style="--span:2">
      <div class="kpi" style="--tone:<?= $this->e($tone) ?>">
        <div class="kpi__head"><div class="kpi__icon"><?= $this->icon($icon) ?></div></div>
        <div class="kpi__value"><?= $this->number($value) ?></div>
        <div class="kpi__label"><?= $this->e($label) ?></div>
        <div class="kpi__foot truncate"><?= $this->e($foot) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid grid--sidebar">

  <!-- ================= Recipients ================= -->
  <div class="card">
    <div class="card__head">
      <h2 class="h3">Recipients</h2>
      <?php if ($counts !== []): ?>
        <div class="chip-row">
          <?php foreach ($counts as $countStatus => $count): ?>
            <a class="chip-filter<?= ($filters['rstatus'] ?? '') === $countStatus ? ' chip-filter--active' : '' ?>"
               href="<?= $this->queryUrl(['rstatus' => ($filters['rstatus'] ?? '') === $countStatus ? '' : $countStatus, 'page' => 1]) ?>">
              <?= $this->e(ucfirst($countStatus)) ?>
              <span class="chip-filter__count"><?= $this->number($count) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php $this->partial('admin.partials.toolbar', [
        'filters'           => $filters,
        'searchPlaceholder' => 'Search a destination…',
        'showExport'        => true,
        'selects'           => [
            ['name' => 'rstatus', 'label' => 'Recipient status', 'options' => [
                ''             => 'Any status',
                'pending'      => 'Pending',
                'sent'         => 'Sent',
                'opened'       => 'Opened',
                'clicked'      => 'Clicked',
                'failed'       => 'Failed',
                'bounced'      => 'Bounced',
                'unsubscribed' => 'Unsubscribed',
            ]],
        ],
    ]); ?>

    <?php if ($recipients === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('inbox', 'ic ic--lg') ?></div>
        <p class="empty__title">No recipients yet</p>
        <p class="empty__text">
          <?php if ((int) $stats['total'] === 0): ?>
            The list is built the moment the campaign is sent. Until then this stays empty —
            <?= $this->number($audienceToday) ?> account<?= $audienceToday === 1 ? '' : 's' ?> match the audience today.
          <?php else: ?>
            Nothing matches that filter.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Destination</th>
              <th>Status</th>
              <th>Handed to queue</th>
              <th<?= $trackingOn ? '' : ' title="Open tracking is not recording — this column cannot fill in."' ?>>
                Opened<?= $trackingOn ? '' : ' <span class="tiny faint">(not tracked)</span>' ?>
              </th>
              <th<?= $trackingOn ? '' : ' title="Click tracking is not recording — this column cannot fill in."' ?>>
                Clicked<?= $trackingOn ? '' : ' <span class="tiny faint">(not tracked)</span>' ?>
              </th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recipients as $recipient): ?>
              <tr>
                <td class="truncate" style="max-width:240px">
                  <span class="small mono"><?= $this->e($recipient['destination']) ?></span>
                  <?php if (!empty($recipient['user_name'])): ?>
                    <div class="tiny muted"><?= $this->e($recipient['user_name']) ?></div>
                  <?php endif; ?>
                </td>

                <td>
                  <span class="chip chip--<?= $this->e($recipientTone((string) $recipient['status'])) ?>">
                    <span class="chip__dot"></span><?= $this->e(ucfirst((string) $recipient['status'])) ?>
                  </span>
                </td>

                <td class="small muted" style="white-space:nowrap"><?= $this->dateTime($recipient['sent_at']) ?></td>
                <td class="small muted" style="white-space:nowrap"><?= $this->dateTime($recipient['opened_at']) ?></td>
                <td class="small muted" style="white-space:nowrap"><?= $this->dateTime($recipient['clicked_at']) ?></td>

                <td class="tiny muted truncate" style="max-width:240px" title="<?= $this->e($recipient['error_message'] ?? '') ?>">
                  <?= $this->e($recipient['error_message'] ?? '—') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
    <?php endif; ?>
  </div>

  <!-- ================= Details ================= -->
  <div class="grid">

    <div class="card">
      <div class="card__head"><h2 class="h3">Details</h2></div>
      <div class="card__body grid gap-3">

        <div>
          <p class="label">Audience</p>
          <p class="small"><?= $this->e($audienceLabel) ?></p>
          <p class="tiny muted"><?= $this->number($audienceToday) ?> account<?= $audienceToday === 1 ? '' : 's' ?> match today</p>
        </div>

        <?php if (!empty($campaign['subject'])): ?>
          <div>
            <p class="label">Subject</p>
            <p class="small"><?= $this->e($campaign['subject']) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($campaign['preheader'])): ?>
          <div>
            <p class="label">Preheader</p>
            <p class="small muted"><?= $this->e($campaign['preheader']) ?></p>
          </div>
        <?php endif; ?>

        <div>
          <p class="label">Created</p>
          <p class="small"><?= $this->dateTime($campaign['created_at']) ?></p>
          <p class="tiny muted">by <?= $this->e($campaign['created_by_name'] ?? 'System') ?></p>
        </div>

        <?php if (!empty($campaign['started_at'])): ?>
          <div>
            <p class="label">Started</p>
            <p class="small"><?= $this->dateTime($campaign['started_at']) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($campaign['completed_at'])): ?>
          <div>
            <p class="label">Finished</p>
            <p class="small"><?= $this->dateTime($campaign['completed_at']) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($campaign['audience_filter'])): ?>
          <div>
            <p class="label">Stored filter</p>
            <pre class="mono tiny mkt-json"><?= $this->e($campaign['audience_filter']) ?></pre>
          </div>
        <?php endif; ?>

        <div>
          <p class="label">Next possible states</p>
          <p class="small muted">
            <?= $allowed === [] ? 'None — this campaign is finished.' : $this->e(implode(', ', $allowed)) ?>
          </p>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <h2 class="h3">Message</h2>
        <button type="button" class="btn btn--ghost btn--sm" data-toggle-body>Show</button>
      </div>
      <div class="card__body">
        <iframe class="mkt-preview hidden" title="Message body" sandbox referrerpolicy="no-referrer"
                data-body="<?= $this->e($campaign['content']) ?>"></iframe>
        <p class="tiny faint">Rendered in a sandboxed frame with scripts disabled.</p>
      </div>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<link rel="stylesheet" href="/admin/assets/css/widgets.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<style>
  .mkt-bar {
    height: 6px;
    border-radius: var(--r-full);
    background: var(--surface-variant);
    overflow: hidden;
  }
  .mkt-bar--lg { height: 12px; }
  .mkt-bar > span {
    display: block;
    height: 100%;
    border-radius: var(--r-full);
    background: var(--primary);
    transition: width .3s ease;
  }
  .mkt-preview {
    width: 100%;
    min-height: 300px;
    border: 1px solid var(--outline-variant);
    border-radius: var(--r-md);
    background: #ffffff;
  }
  .mkt-json {
    margin: 0;
    padding: 8px 10px;
    background: var(--surface-variant);
    border-radius: var(--r);
    white-space: pre-wrap;
    word-break: break-all;
  }
</style>
<script>
(function () {
  'use strict';

  const button = document.querySelector('[data-toggle-body]');
  const frame  = document.querySelector('.mkt-preview');

  if (!button || !frame) return;

  button.addEventListener('click', function () {
    if (frame.classList.contains('hidden')) {
      frame.srcdoc = frame.dataset.body || '';
      frame.classList.remove('hidden');
      button.textContent = 'Hide';
    } else {
      frame.classList.add('hidden');
      frame.srcdoc = '';
      button.textContent = 'Show';
    }
  });
})();
</script>
<?php $this->stop(); ?>

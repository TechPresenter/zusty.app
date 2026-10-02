<?php

declare(strict_types=1);

/**
 * Marketing campaigns list.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed> $summary
 * @var int $subscribers
 * @var bool $schedulerOn
 * @var bool $trackingOn
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (string $s): string => \Zuste\Services\CampaignService::statusTone($s);

// `whatsapp` is retired rather than deleted: no new campaign can use it, but a
// row saved on an earlier build still has to read correctly here.
$channelLabel = static fn (string $c): string => match ($c) {
    'email'    => 'Email',
    'sms'      => 'SMS',
    'whatsapp' => 'WhatsApp',
    'push'     => 'Push',
    default    => ucfirst($c),
};

// The filter offers exactly the channels a campaign can be created on, plus the
// retired one currently being filtered on, if any — otherwise the dropdown would
// read "Any channel" while the list underneath it is filtered.
$channelOptions = ['' => 'Any channel'];
foreach (\Zuste\Services\CampaignService::CHANNELS as $channelKey) {
    $channelOptions[$channelKey] = $channelLabel($channelKey);
}

$activeChannel = (string) ($filters['channel'] ?? '');

if (!isset($channelOptions[$activeChannel])
    && in_array($activeChannel, \Zuste\Services\CampaignService::knownChannels(), true)) {
    $channelOptions[$activeChannel] = $channelLabel($activeChannel);
}

$audienceLabel = static fn (string $a): string => match ($a) {
    'all'               => 'Everyone active',
    'brands'            => 'Brands',
    'creators'          => 'Creators',
    'verified_creators' => 'Verified creators',
    'subscribers'       => 'Newsletter list',
    'custom'            => 'Custom filter',
    default             => ucfirst($a),
};

// The controller hands over an already normalised sort, so the header shows the
// real ordering even on a first load, where the URL carries no sort_by at all.
$sortBy  = (string) ($filters['sort_by'] ?? '');
$sortDir = (string) ($filters['sort_dir'] ?? 'DESC');

$sortHeader = function (string $key, string $label, array $attributes = []) use ($sortBy, $sortDir): void {
    $this->partial('admin.partials.sort-header', array_merge([
        'key'      => $key,
        'label'    => $label,
        'current'  => $sortBy,
        'dir'      => $sortDir,
        // Every column here has always opened newest or largest first.
        'firstDir' => 'DESC',
    ], $attributes));
};

$sending      = (int) ($summary['sending'] ?? 0);
$scheduled    = (int) ($summary['scheduled'] ?? 0);
$messagesSent = (int) ($summary['messages_sent'] ?? 0);
$opened       = (int) ($summary['messages_opened'] ?? 0);
$openRate     = $messagesSent > 0 ? round($opened / $messagesSent * 100, 1) : 0.0;

// Nothing in this build records an open or a click unless a tracking endpoint
// is configured, so the open rate is only quoted when one is. Otherwise the
// stored counters are whatever an earlier build or an import left behind, and
// presenting them as a live rate would be a lie.
$trackingOn = $trackingOn ?? false;

$openedHint = $trackingOn
    ? 'Opens recorded against this campaign.'
    : 'Open tracking is not recording. No tracking endpoint is configured, so these figures cannot change.';

$toolbar = [
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by campaign name or subject…',
    'showDateRange'     => true,
    'showExport'        => true,
    'selects'           => [
        ['name' => 'status', 'label' => 'Status', 'options' => [
            ''          => 'Any status',
            'draft'     => 'Draft',
            'scheduled' => 'Scheduled',
            'sending'   => 'Sending',
            'sent'      => 'Sent',
            'paused'    => 'Paused',
            'failed'    => 'Stopped or failed',
        ]],
        ['name' => 'channel', 'label' => 'Channel', 'options' => $channelOptions],
    ],
];

if ($this->can('marketing.manage')) {
    $toolbar['primaryAction'] = ['href' => '/admin/marketing/create', 'label' => 'New campaign', 'icon' => 'plus'];
}

?>

<div class="wgrid mb-5">

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#B80049">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('megaphone') ?></div></div>
      <div class="kpi__value"><?= $this->number((int) ($summary['total'] ?? 0)) ?></div>
      <div class="kpi__label">Campaigns</div>
      <div class="kpi__foot"><?= $this->number((int) ($summary['draft'] ?? 0)) ?> still in draft</div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:<?= $sending > 0 ? '#2563EB' : '#8F6F73' ?>" href="/admin/marketing?status=sending">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('refresh') ?></div></div>
      <div class="kpi__value"><?= $this->number($sending) ?></div>
      <div class="kpi__label">Sending now</div>
      <div class="kpi__foot"><?= $this->number($scheduled) ?> scheduled</div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#006A34">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('inbox') ?></div></div>
      <div class="kpi__value"><?= $this->compact($messagesSent) ?></div>
      <div class="kpi__label">Messages delivered to a queue</div>
      <div class="kpi__foot"><?= $trackingOn
          ? ($openRate > 0 ? $openRate . '% opened' : 'No opens recorded yet')
          : 'Open tracking is off' ?></div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#632CE5" href="/admin/marketing/subscribers">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('users') ?></div></div>
      <div class="kpi__value"><?= $this->number($subscribers) ?></div>
      <div class="kpi__label">Newsletter subscribers</div>
      <div class="kpi__foot">Open the list</div>
    </a>
  </div>
</div>

<?php if ($scheduled > 0 && $schedulerOn): ?>
  <div class="callout callout--info mb-5">
    <?= $this->icon('calendar', 'ic ic--sm') ?>
    <div>
      <strong><?= $this->number($scheduled) ?> campaign<?= $scheduled === 1 ? ' is' : 's are' ?> scheduled.</strong>
      Each one goes out when the <code class="mono"><?= $this->e(\Zuste\Services\CampaignService::SCHEDULER_JOB) ?></code>
      job next runs. Opening the campaign and using <em>Send now</em> does exactly the same thing immediately.
    </div>
  </div>
<?php elseif ($scheduled > 0): ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong><?= $this->number($scheduled) ?> campaign<?= $scheduled === 1 ? ' is' : 's are' ?> scheduled, but nothing will send <?= $scheduled === 1 ? 'it' : 'them' ?> automatically.</strong>
      No <code class="mono"><?= $this->e(\Zuste\Services\CampaignService::SCHEDULER_JOB) ?></code> job is registered with
      the cron runner, so a schedule is only a note to yourself. Open the campaign and use <em>Send now</em> when the
      time comes, or ask a developer to register the job.
    </div>
  </div>
<?php endif; ?>

<?php if (!$trackingOn && ($opened > 0 || (int) ($summary['messages_clicked'] ?? 0) > 0)): ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>Open and click tracking is not recording.</strong>
      No tracking endpoint is configured, so nothing increments these counters — the
      <?= $this->number($opened) ?> opens and <?= $this->number((int) ($summary['messages_clicked'] ?? 0)) ?> clicks
      below are historical figures that will not change. Delivery counts are unaffected: those are written by the
      dispatcher itself.
    </div>
  </div>
<?php endif; ?>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', $toolbar); ?>

  <?php if ($rows === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('megaphone', 'ic ic--lg') ?></div>
      <p class="empty__title">No campaigns match</p>
      <p class="empty__text">
        <?php if ($this->can('marketing.manage')): ?>
          Clear the filters, or start a new campaign.
        <?php else: ?>
          Clear the filters to see everything.
        <?php endif; ?>
      </p>
      <?php if ($this->can('marketing.manage')): ?>
        <a href="/admin/marketing/create" class="btn btn--primary btn--sm mt-3">
          <?= $this->icon('plus', 'ic ic--sm') ?> New campaign
        </a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <?php $sortHeader('name', 'Campaign'); ?>
            <?php $sortHeader('channel', 'Channel'); ?>
            <th>Audience</th>
            <?php $sortHeader('status', 'Status'); ?>
            <?php $sortHeader('sent_count', 'Delivered', ['style' => 'min-width:170px']); ?>
            <th class="text-right" title="<?= $this->e($openedHint) ?>">
              Opened<?= $trackingOn ? '' : ' <span class="tiny faint">(not tracked)</span>' ?>
            </th>
            <?php $sortHeader('created_at', 'Created'); ?>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
            $id         = (int) $row['id'];
            $recipients = (int) $row['recipients_count'];
            $sent       = (int) $row['sent_count'];
            $failed     = (int) $row['failed_count'];
            $percent    = $recipients > 0 ? min(100, (int) round($sent / $recipients * 100)) : 0;
            $rowOpened  = (int) $row['opened_count'];
            ?>
            <tr>
              <td>
                <a href="/admin/marketing/<?= $id ?>" class="small" style="font-weight:600">
                  <?= $this->e($row['name']) ?>
                </a>
                <?php if (!empty($row['subject'])): ?>
                  <div class="tiny muted truncate" style="max-width:280px"><?= $this->e($row['subject']) ?></div>
                <?php endif; ?>
              </td>

              <td>
                <span class="chip chip--<?= $row['channel'] === 'whatsapp' ? 'warning' : 'neutral' ?>">
                  <?= $this->e($channelLabel((string) $row['channel'])) ?>
                </span>
              </td>

              <td class="small muted"><?= $this->e($audienceLabel((string) $row['audience'])) ?></td>

              <td>
                <span class="chip chip--<?= $this->e($statusTone((string) $row['status'])) ?><?= $row['status'] === 'sending' ? ' chip--pulse' : '' ?>">
                  <span class="chip__dot"></span>
                  <?= $this->e(ucfirst((string) $row['status'])) ?>
                </span>
              </td>

              <td>
                <?php if ($recipients === 0): ?>
                  <span class="tiny faint">Not queued yet</span>
                <?php else: ?>
                  <div class="mkt-bar" role="img"
                       aria-label="<?= $this->e($sent . ' of ' . $recipients . ' delivered') ?>">
                    <span style="width:<?= $percent ?>%"></span>
                  </div>
                  <div class="tiny muted">
                    <?= $this->number($sent) ?> / <?= $this->number($recipients) ?>
                    <?php if ($failed > 0): ?>
                      · <span class="text-error"><?= $this->number($failed) ?> failed</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>

              <td class="text-right mono small<?= $trackingOn ? '' : ' muted' ?>" title="<?= $this->e($openedHint) ?>">
                <?= $rowOpened > 0 ? $this->number($rowOpened) : '—' ?>
              </td>

              <td class="small muted" style="white-space:nowrap">
                <?= $this->date($row['created_at']) ?>
                <div class="tiny faint"><?= $this->e($row['created_by_name'] ?? 'System') ?></div>
              </td>

              <td class="actions">
                <a href="/admin/marketing/<?= $id ?>" class="btn btn--ghost btn--icon btn--sm" aria-label="View" title="View">
                  <?= $this->icon('eye', 'ic ic--sm') ?>
                </a>

                <?php if ($this->can('marketing.manage') && !in_array($row['status'], ['sending', 'sent'], true)): ?>
                  <a href="/admin/marketing/<?= $id ?>/edit" class="btn btn--ghost btn--icon btn--sm" aria-label="Edit" title="Edit">
                    <?= $this->icon('edit', 'ic ic--sm') ?>
                  </a>
                <?php endif; ?>

                <?php if ($this->can('marketing.manage') && !in_array($row['status'], ['sending', 'paused'], true)): ?>
                  <form method="post" action="/admin/marketing/<?= $id ?>/delete" style="display:inline">
                    <?= $this->csrf() ?>
                    <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error"
                            aria-label="Delete" title="Delete"
                            data-confirm="&quot;<?= $this->e($row['name']) ?>&quot; will be moved to the recycle bin. Recipient records are kept."
                            data-confirm-title="Delete campaign?" data-confirm-label="Delete" data-confirm-tone="danger">
                      <?= $this->icon('trash', 'ic ic--sm') ?>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  <?php endif; ?>
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
    margin-bottom: 4px;
  }
  .mkt-bar > span {
    display: block;
    height: 100%;
    border-radius: var(--r-full);
    background: var(--primary);
    transition: width .3s ease;
  }
</style>
<?php $this->stop(); ?>

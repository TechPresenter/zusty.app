<?php

declare(strict_types=1);

/**
 * Newsletter subscribers: the list, manual additions and bulk import.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed> $summary
 * @var list<array{day:string,count:int,cumulative:int}> $growth
 * @var array<string,mixed> $pagination
 * @var array<string,mixed>|null $importResult
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$canManage = $this->can('marketing.manage');

$statusTone = static fn (string $s): string => match ($s) {
    'subscribed'   => 'success',
    'unsubscribed' => 'neutral',
    'bounced'      => 'error',
    default        => 'neutral',
};

/*
 * Sparkline, drawn in PHP. The series is the running total of subscribers on
 * each of the last thirty days, so a flat line means no growth rather than
 * no data.
 */
$sparkWidth  = 320;
$sparkHeight = 56;
$sparkPad    = 3;

$values = array_map(static fn (array $point): int => $point['cumulative'], $growth);
$points = '';
$area   = '';
$peak   = $values === [] ? 0 : max($values);
$floor  = $values === [] ? 0 : min($values);
$span   = max(1, $peak - $floor);
$stepX  = count($values) > 1 ? ($sparkWidth - ($sparkPad * 2)) / (count($values) - 1) : 0.0;

foreach ($values as $index => $value) {
    $x = $sparkPad + ($index * $stepX);
    $y = $sparkHeight - $sparkPad - (($value - $floor) / $span) * ($sparkHeight - ($sparkPad * 2));

    $points .= sprintf('%s%.2f,%.2f', $points === '' ? '' : ' ', $x, $y);
}

if ($points !== '' && count($values) > 1) {
    $area = sprintf(
        '%.2f,%.2f %s %.2f,%.2f',
        (float) $sparkPad,
        (float) $sparkHeight,
        $points,
        (float) ($sparkWidth - $sparkPad),
        (float) $sparkHeight
    );
}

$firstDay = $growth === [] ? '' : (string) $growth[0]['day'];
$lastDay  = $growth === [] ? '' : (string) $growth[count($growth) - 1]['day'];
$added30  = 0;
foreach ($growth as $point) {
    $added30 += (int) $point['count'];
}

// The series is gap-filled, so it is never empty. A flat line at zero means
// there is nothing to plot yet, which is what the empty state is for.
$hasPlot = $values !== [] && $peak > 0;

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

?>

<div class="grid grid--4 mb-5">
  <div class="card">
    <div class="card__body">
      <p class="label">Subscribed</p>
      <p class="h1 text-success"><?= $this->number((int) ($summary['subscribed'] ?? 0)) ?></p>
      <p class="tiny muted">Can receive a newsletter campaign</p>
    </div>
  </div>

  <div class="card">
    <div class="card__body">
      <p class="label">Unsubscribed</p>
      <p class="h1"><?= $this->number((int) ($summary['unsubscribed'] ?? 0)) ?></p>
      <p class="tiny muted">Suppressed on every email campaign</p>
    </div>
  </div>

  <div class="card">
    <div class="card__body">
      <p class="label">Bounced</p>
      <p class="h1 text-error"><?= $this->number((int) ($summary['bounced'] ?? 0)) ?></p>
      <p class="tiny muted">Also suppressed</p>
    </div>
  </div>

  <div class="card">
    <div class="card__body">
      <p class="label">Added in 30 days</p>
      <p class="h1 text-primary"><?= $this->number((int) ($summary['new_30d'] ?? 0)) ?></p>
      <p class="tiny muted">Across every source</p>
    </div>
  </div>
</div>

<div class="card mb-5">
  <div class="card__head">
    <h2 class="h3">Growth</h2>
    <span class="small muted">
      <?= $this->date($firstDay) ?> – <?= $this->date($lastDay) ?> ·
      <?= $this->number($added30) ?> added
    </span>
  </div>

  <div class="card__body">
    <?php if (!$hasPlot): ?>
      <p class="small muted">No subscribers yet, so there is nothing to plot.</p>
    <?php else: ?>
      <svg class="mkt-spark" viewBox="0 0 <?= (int) $sparkWidth ?> <?= (int) $sparkHeight ?>"
           preserveAspectRatio="none" role="img"
           aria-label="Total subscribers over the last <?= count($values) ?> days, from <?= (int) $floor ?> to <?= (int) $peak ?>">
        <?php if ($area !== ''): ?>
          <polygon points="<?= $this->e($area) ?>" fill="var(--primary)" opacity="0.12"></polygon>
        <?php endif; ?>
        <polyline points="<?= $this->e($points) ?>" fill="none" stroke="var(--primary)"
                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
      </svg>

      <div class="row row--between mt-2">
        <span class="tiny faint"><?= $this->number($floor) ?> on <?= $this->date($firstDay) ?></span>
        <span class="tiny faint"><?= $this->number($peak) ?> on <?= $this->date($lastDay) ?></span>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (is_array($importResult)): ?>
  <div class="callout callout--<?= (int) $importResult['rejected'] > 0 ? 'warning' : 'success' ?> mb-5">
    <?= $this->icon('upload', 'ic ic--sm') ?>
    <div>
      <strong>Import finished.</strong>
      <?= $this->number((int) $importResult['added']) ?> added,
      <?= $this->number((int) $importResult['resubscribed']) ?> re-subscribed,
      <?= $this->number((int) $importResult['skipped']) ?> skipped,
      <?= $this->number((int) $importResult['rejected']) ?> rejected,
      out of <?= $this->number((int) $importResult['total']) ?> lines.

      <?php if ((int) ($importResult['unprocessed'] ?? 0) > 0): ?>
        <div class="mt-2">
          <strong><?= $this->number((int) $importResult['unprocessed']) ?> lines were not read.</strong>
          One import handles <?= $this->number((int) ($importResult['limit'] ?? 0)) ?> lines so the request cannot
          time out halfway through. Paste the remainder and import again.
        </div>
      <?php endif; ?>

      <?php if (!empty($importResult['problems'])): ?>
        <div class="table-wrap mt-3">
          <table class="table">
            <thead><tr><th>Line</th><th>Value</th><th>Reason</th></tr></thead>
            <tbody>
              <?php foreach ($importResult['problems'] as $problem): ?>
                <tr>
                  <td class="mono tiny"><?= (int) $problem['line'] ?></td>
                  <td class="mono tiny truncate" style="max-width:260px"><?= $this->e($problem['value']) ?></td>
                  <td class="tiny muted"><?= $this->e($problem['reason']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="tiny faint mt-2">Only the first 50 problem lines are listed.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--sidebar">

  <div class="card">
    <?php $this->partial('admin.partials.toolbar', [
        'filters'           => $filters,
        'searchPlaceholder' => 'Search by email or name…',
        'showDateRange'     => true,
        'showExport'        => true,
        'selects'           => [
            ['name' => 'status', 'label' => 'Status', 'options' => [
                ''             => 'Any status',
                'subscribed'   => 'Subscribed',
                'unsubscribed' => 'Unsubscribed',
                'bounced'      => 'Bounced',
            ]],
            ['name' => 'source', 'label' => 'Source', 'options' => [
                ''       => 'Any source',
                'app'    => 'Mobile app',
                'web'    => 'Website',
                'import' => 'Imported',
                'admin'  => 'Added by an admin',
            ]],
        ],
    ]); ?>

    <?php if ($rows === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('inbox', 'ic ic--lg') ?></div>
        <p class="empty__title">No subscribers match</p>
        <p class="empty__text">Clear the filters, or add an address on the right.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <?php $sortHeader('email', 'Email'); ?>
              <th>Source</th>
              <?php $sortHeader('status', 'Status'); ?>
              <?php $sortHeader('subscribed_at', 'Subscribed'); ?>
              <?php if ($canManage): ?><th class="text-right">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php $subscriberId = (int) $row['id']; ?>
              <tr>
                <td class="truncate" style="max-width:260px">
                  <span class="small mono"><?= $this->e($row['email']) ?></span>
                  <?php if (!empty($row['name']) || !empty($row['account_name'])): ?>
                    <div class="tiny muted"><?= $this->e($row['name'] ?: $row['account_name']) ?></div>
                  <?php endif; ?>
                </td>

                <td><span class="chip chip--neutral"><?= $this->e(ucfirst((string) $row['source'])) ?></span></td>

                <td>
                  <span class="chip chip--<?= $this->e($statusTone((string) $row['status'])) ?>">
                    <span class="chip__dot"></span><?= $this->e(ucfirst((string) $row['status'])) ?>
                  </span>
                </td>

                <td class="small muted" style="white-space:nowrap">
                  <?= $this->date($row['subscribed_at']) ?>
                  <?php if (!empty($row['unsubscribed_at'])): ?>
                    <div class="tiny faint">Left <?= $this->date($row['unsubscribed_at']) ?></div>
                  <?php endif; ?>
                </td>

                <?php if ($canManage): ?>
                  <td class="actions">
                    <?php if ((string) $row['status'] === 'subscribed'): ?>
                      <form method="post" action="/admin/marketing/subscribers/<?= $subscriberId ?>/unsubscribe" style="display:inline">
                        <?= $this->csrf() ?>
                        <button type="submit" class="btn btn--ghost btn--sm">Unsubscribe</button>
                      </form>
                    <?php else: ?>
                      <form method="post" action="/admin/marketing/subscribers/<?= $subscriberId ?>/resubscribe" style="display:inline">
                        <?= $this->csrf() ?>
                        <button type="submit" class="btn btn--ghost btn--sm"
                                data-confirm="<?= $this->e($row['email']) ?> asked to stop receiving email. Only re-subscribe if they have asked to come back."
                                data-confirm-title="Re-subscribe?" data-confirm-label="Re-subscribe" data-confirm-tone="warning">
                          Re-subscribe
                        </button>
                      </form>
                    <?php endif; ?>

                    <form method="post" action="/admin/marketing/subscribers/<?= $subscriberId ?>/delete" style="display:inline">
                      <?= $this->csrf() ?>
                      <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error"
                              aria-label="Delete" title="Delete"
                              data-confirm="Deleting <?= $this->e($row['email']) ?> also deletes the record that they unsubscribed, so a future import could add them back."
                              data-confirm-title="Delete subscriber?" data-confirm-label="Delete" data-confirm-tone="danger">
                        <?= $this->icon('trash', 'ic ic--sm') ?>
                      </button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
    <?php endif; ?>
  </div>

  <div class="grid">
    <?php if ($canManage): ?>
      <div class="card">
        <div class="card__head"><h2 class="h3">Add one</h2></div>

        <div class="card__body">
          <form method="post" action="/admin/marketing/subscribers" class="grid">
            <?= $this->csrf() ?>

            <div class="field">
              <label class="field__label req" for="email">Email address</label>
              <input class="input" id="email" name="email" type="email" required maxlength="191"
                     placeholder="name@example.com">
              <?php if ($this->hasError('email')): ?>
                <p class="field__error"><?= $this->error('email') ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="field__label" for="name">Name</label>
              <input class="input" id="name" name="name" maxlength="120" placeholder="Optional">
            </div>

            <button type="submit" class="btn btn--primary">
              <?= $this->icon('plus', 'ic ic--sm') ?> Add subscriber
            </button>

            <p class="tiny faint">
              An address that previously unsubscribed is re-activated rather than duplicated.
            </p>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><h2 class="h3">Bulk import</h2></div>

        <div class="card__body">
          <form method="post" action="/admin/marketing/subscribers/import" class="grid">
            <?= $this->csrf() ?>

            <div class="field">
              <label class="field__label req" for="list">One address per line</label>
              <textarea class="textarea mono" id="list" name="list" rows="10" required
                        placeholder="alex@example.com,Alex Fernandes&#10;priya@example.com,Priya Rao&#10;sam@example.com"></textarea>
              <p class="field__hint">
                Format: <code class="mono">email,name</code>. The name is optional, and a comma,
                semicolon or tab all work as the separator. A leading
                <code class="mono">email,name</code> header row is ignored. Up to
                <?= $this->number(\Zuste\Services\CampaignService::MAX_IMPORT_LINES) ?> lines per import.
              </p>
            </div>

            <button type="submit" class="btn btn--outline"
                    data-confirm="Every valid address is added with the source set to Import. Duplicates are skipped and you will get an exact count."
                    data-confirm-title="Import subscribers?" data-confirm-label="Import" data-confirm-tone="warning">
              <?= $this->icon('upload', 'ic ic--sm') ?> Import list
            </button>

            <p class="tiny faint">
              Only import addresses that agreed to hear from you. Everything imported is suppressed
              automatically once it unsubscribes or bounces.
            </p>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card__body">
          <p class="small muted">
            You can read the list but not change it. Adding, importing and removing addresses needs the
            marketing management permission.
          </p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
  .mkt-spark {
    display: block;
    width: 100%;
    height: 72px;
    overflow: visible;
  }
</style>
<?php $this->stop(); ?>

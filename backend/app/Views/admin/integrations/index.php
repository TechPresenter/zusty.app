<?php

declare(strict_types=1);

/**
 * Integrations hub.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,array<string,array<string,mixed>>> $grouped
 * @var array<string,array{label:string,icon:string,tone:string}> $categories
 * @var array<string,int> $summary
 * @var array<string,string> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (string $s): string => match ($s) {
    'connected'      => 'success',
    'error'          => 'error',
    'disabled'       => 'neutral',
    'delegated'      => 'info',
    default          => 'warning',
};

$statusLabel = static fn (string $s): string => match ($s) {
    'connected'      => 'Connected',
    'error'          => 'Error',
    'disabled'       => 'Off',
    'delegated'      => 'Managed elsewhere',
    default          => 'Not set up',
};

$totalShown = 0;
foreach ($grouped as $set) {
    $totalShown += count($set);
}

?>

<div class="wgrid mb-5">
  <?php foreach ([
      ['Connected',       $summary['connected'],      '#006A34', 'check-circle', 'connected'],
      ['Needs setup',     $summary['not_configured'], '#B45309', 'alert',        'not_configured'],
      ['Reporting errors', $summary['error'],         '#DC2626', 'flag',         'error'],
      ['Managed elsewhere', $summary['delegated'],    '#2563EB', 'external',     'delegated'],
  ] as [$label, $value, $tone, $icon, $filter]): ?>
    <div class="wgrid__item" style="--span:3">
      <a class="kpi" style="--tone:<?= $tone ?>" href="<?= $this->queryUrl(['status' => $filter, 'category' => '']) ?>">
        <div class="kpi__head"><div class="kpi__icon"><?= $this->icon($icon) ?></div></div>
        <div class="kpi__value"><?= $this->number((int) $value) ?></div>
        <div class="kpi__label"><?= $this->e($label) ?></div>
        <div class="kpi__foot">of <?= $this->number($summary['total']) ?> integrations</div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mb-5">
  <form class="toolbar" method="get" action="/admin/integrations">
    <div class="toolbar__search">
      <div class="input-group">
        <span class="input-group__icon"><?= $this->icon('search', 'ic ic--sm') ?></span>
        <input class="input" type="search" name="q" value="<?= $this->e($filters['q']) ?>"
               placeholder="Search by name, vendor or capability…" data-auto-submit="400" aria-label="Search integrations">
      </div>
    </div>

    <select class="select" name="category" data-auto-submit aria-label="Category" style="max-width:220px">
      <option value="">All categories</option>
      <?php foreach ($categories as $slug => $meta): ?>
        <option value="<?= $this->e($slug) ?>" <?= $filters['category'] === $slug ? 'selected' : '' ?>>
          <?= $this->e($meta['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select class="select" name="status" data-auto-submit aria-label="Status" style="max-width:200px">
      <option value="">Any status</option>
      <?php foreach (['connected' => 'Connected', 'not_configured' => 'Not set up',
                      'error' => 'Error', 'disabled' => 'Off', 'delegated' => 'Managed elsewhere'] as $value => $label): ?>
        <option value="<?= $this->e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>>
          <?= $this->e($label) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div class="toolbar__spacer"></div>

    <?php if ($filters['q'] !== '' || $filters['category'] !== '' || $filters['status'] !== ''): ?>
      <a href="/admin/integrations" class="btn btn--ghost btn--sm">
        <?= $this->icon('x', 'ic ic--sm') ?> Clear
      </a>
    <?php endif; ?>

    <span class="small muted"><?= $this->number($totalShown) ?> shown</span>
  </form>
</div>

<?php if ($totalShown === 0): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__art"><?= $this->icon('grid', 'ic ic--lg') ?></div>
      <p class="empty__title">Nothing matches</p>
      <p class="empty__text">Try a different search or clear the filters.</p>
      <a href="/admin/integrations" class="btn btn--primary">Show everything</a>
    </div>
  </div>
<?php endif; ?>

<?php foreach ($grouped as $slug => $set): ?>
  <?php $meta = $categories[$slug]; ?>

  <div class="mb-5">
    <div class="row gap-2 mb-3">
      <span style="color:<?= $this->e($meta['tone']) ?>"><?= $this->icon($meta['icon']) ?></span>
      <h2 class="h3"><?= $this->e($meta['label']) ?></h2>
      <span class="chip chip--neutral"><?= count($set) ?></span>
    </div>

    <div class="igrid">
      <?php foreach ($set as $key => $entry): ?>
        <?php $definition = $entry['definition']; ?>

        <a class="icard" href="/admin/integrations/<?= $this->e($key) ?>"
           style="--tone:<?= $this->e($meta['tone']) ?>">
          <div class="icard__top">
            <span class="icard__icon"><?= $this->icon($definition['icon']) ?></span>
            <span class="chip chip--<?= $statusTone($entry['status']) ?>">
              <span class="chip__dot"></span><?= $this->e($statusLabel($entry['status'])) ?>
            </span>
          </div>

          <div class="icard__name"><?= $this->e($definition['name']) ?></div>
          <div class="icard__vendor"><?= $this->e($definition['vendor']) ?></div>
          <p class="icard__desc"><?= $this->e($definition['description']) ?></p>

          <div class="icard__foot">
            <?php /* Only where a callback endpoint really exists: the tag used
                     to appear on ten cards and only one of them could receive
                     anything. */ ?>
            <?php if ($definition['webhook'] && ($definition['webhook_receiver'] ?? false)): ?>
              <span class="itag">Webhook</span>
            <?php endif; ?>
            <?php if ($definition['sync']): ?><span class="itag">Sync</span><?php endif; ?>
            <?php if ($definition['settings_map'] !== []): ?>
              <span class="itag">Live</span>
            <?php elseif (\Zuste\Services\IntegrationService::isReferenceOnly($definition)): ?>
              <span class="itag itag--muted">Reference</span>
            <?php endif; ?>
            <?php if (($definition['test']['type'] ?? '') === 'http'): ?><span class="itag">Testable</span><?php endif; ?>
            <?php if (!empty($entry['row']['last_sync_at'])): ?>
              <span class="itag itag--muted">Synced <?= $this->timeAgo($entry['row']['last_sync_at']) ?></span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<link rel="stylesheet" href="/admin/assets/css/widgets.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<style>
  .igrid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:14px; }

  .icard {
    position:relative; display:flex; flex-direction:column; gap:4px;
    padding:16px; border-radius:var(--r-md);
    background:var(--surface); border:1px solid var(--outline-variant);
    box-shadow:0 4px 20px rgba(0,0,0,.04);
    text-decoration:none; color:inherit; overflow:hidden;
    transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease;
  }
  .icard::before {
    content:''; position:absolute; inset:0 0 auto 0; height:3px;
    background:linear-gradient(90deg, var(--tone), transparent 75%);
    opacity:.85;
  }
  .icard:hover { transform:translateY(-2px); box-shadow:0 10px 28px rgba(0,0,0,.09); border-color:var(--tone); }
  .icard:focus-visible { outline:2px solid var(--tone); outline-offset:2px; }

  .icard__top { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px; }
  .icard__icon {
    display:inline-flex; align-items:center; justify-content:center;
    width:34px; height:34px; border-radius:var(--r);
    background:color-mix(in srgb, var(--tone) 12%, transparent); color:var(--tone);
  }
  .icard__name   { font-weight:700; font-size:14.5px; line-height:1.3; }
  .icard__vendor { font-size:11.5px; color:var(--outline); text-transform:uppercase; letter-spacing:.06em; }
  .icard__desc   { font-size:12.5px; color:var(--on-surface-variant); line-height:1.5; margin:6px 0 0;
                   display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .icard__foot   { display:flex; flex-wrap:wrap; gap:5px; margin-top:10px; }

  .itag {
    font-size:10.5px; font-weight:600; letter-spacing:.04em;
    padding:2px 7px; border-radius:var(--r-full);
    background:color-mix(in srgb, var(--tone) 10%, transparent); color:var(--tone);
  }
  .itag--muted { background:var(--surface-variant); color:var(--outline); }

  @media (max-width: 600px) { .igrid { grid-template-columns:1fr; } }
</style>
<?php $this->stop(); ?>

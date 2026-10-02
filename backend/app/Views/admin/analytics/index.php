<?php

declare(strict_types=1);

/**
 * Analytics.
 *
 * @var \Zuste\Core\View $this
 * @var int $days
 * @var array<string,mixed> $totals
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $cities
 * @var list<array<string,mixed>> $platforms
 * @var list<array{label:string,value:float}> $gmvSeries
 * @var list<array{label:string,value:float}> $revenueSeries
 * @var list<array{label:string,value:float}> $bookingSeries
 */

$this->extend('admin.layouts.app');
$this->start('content');

$maxCategoryGmv = 1.0;
foreach ($categories as $category) {
    $maxCategoryGmv = max($maxCategoryGmv, (float) $category['gmv']);
}

?>

<!-- Range selector -->
<div class="row row--between row--wrap mb-5">
  <div class="chip-row">
    <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '12 months'] as $value => $label): ?>
      <a href="<?= $this->queryUrl(['days' => $value]) ?>"
         class="chip-filter<?= $days === $value ? ' chip-filter--active' : '' ?>"><?= $this->e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($this->can('export.data')): ?>
    <?php
      // All three formats export the selected day range, not just what is on
      // screen. PDF opens a print-ready page and fires the browser's print
      // dialog, so it needs a new tab rather than replacing this screen.
    ?>
    <div class="row gap-1">
      <a href="<?= $this->queryUrl(['export' => 'csv']) ?>" class="btn btn--outline btn--sm">
        <?= $this->icon('download', 'ic ic--sm') ?> CSV
      </a>
      <a href="<?= $this->queryUrl(['export' => 'xlsx']) ?>" class="btn btn--outline btn--sm">
        <?= $this->icon('file', 'ic ic--sm') ?> Excel
      </a>
      <a href="<?= $this->queryUrl(['export' => 'pdf']) ?>" class="btn btn--outline btn--sm"
         target="_blank" rel="noopener">
        <?= $this->icon('file', 'ic ic--sm') ?> PDF
      </a>
    </div>
  <?php endif; ?>
</div>

<!-- Totals -->
<section class="grid grid--4 mb-6">
  <?php foreach ([
      ['Gross volume',   $this->moneyShort($totals['gmv'] ?? 0),               'trending-up', 'var(--primary)'],
      ['Net revenue',    $this->moneyShort($totals['revenue'] ?? 0),           'wallet',      'var(--secondary)'],
      ['Refunds',        $this->moneyShort($totals['refunds'] ?? 0),           'refresh',     'var(--error)'],
      ['Creator payouts', $this->moneyShort($totals['payouts'] ?? 0),          'card',        'var(--tertiary)'],
      ['New users',      $this->number($totals['users_new'] ?? 0),             'users',       '#e2165f'],
      ['New creators',   $this->number($totals['creators_new'] ?? 0),          'star',        'var(--secondary)'],
      ['Bookings',       $this->number($totals['bookings_created'] ?? 0),      'calendar',    'var(--primary)'],
      ['Completed',      $this->number($totals['bookings_completed'] ?? 0),    'check-circle','var(--tertiary)'],
  ] as [$label, $value, $icon, $tone]): ?>
    <div class="stat" style="--tone:<?= $tone ?>">
      <div class="stat__icon"><?= $this->icon($icon) ?></div>
      <div class="stat__value"><?= $this->e($value) ?></div>
      <div class="stat__label"><?= $this->e($label) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<!-- Trend charts -->
<section class="grid grid--2 mb-6">
  <div class="card">
    <div class="card__head">
      <h2 class="h3">Gross merchandise value</h2>
      <span class="label"><?= $days ?> days</span>
    </div>
    <div class="card__body"><div data-chart="<?= $this->json($gmvSeries) ?>"></div></div>
  </div>

  <div class="card">
    <div class="card__head">
      <h2 class="h3">Platform revenue</h2>
      <span class="label"><?= $days ?> days</span>
    </div>
    <div class="card__body"><div data-chart="<?= $this->json($revenueSeries) ?>"></div></div>
  </div>
</section>

<section class="card mb-6">
  <div class="card__head">
    <h2 class="h3">Bookings created</h2>
    <span class="label"><?= $days ?> days</span>
  </div>
  <div class="card__body"><div data-chart="<?= $this->json($bookingSeries) ?>" data-chart-type="bar"></div></div>
</section>

<!-- Breakdowns -->
<section class="grid grid--2 mb-6">

  <div class="card">
    <div class="card__head"><h2 class="h3">Category performance</h2></div>
    <?php if ($categories === []): ?>
      <div class="empty" style="padding:32px"><p class="empty__text mb-0">No category data yet.</p></div>
    <?php else: ?>
      <div class="card__body col gap-4">
        <?php foreach ($categories as $category): ?>
          <div>
            <div class="row row--between mb-2">
              <span class="small" style="font-weight:600"><?= $this->e($category['name']) ?></span>
              <span class="small mono muted">
                <?= $this->moneyShort($category['gmv']) ?>
                · <?= (int) $category['creators'] ?> creators
              </span>
            </div>
            <div class="progress">
              <div class="progress__bar"
                   style="width:<?= max(2, (int) round(((float) $category['gmv'] / $maxCategoryGmv) * 100)) ?>%;
                          background:<?= $this->e($category['color_hex'] ?: '#B80049') ?>"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="col gap-4">
    <div class="card">
      <div class="card__head"><h2 class="h3">Bookings by platform</h2></div>
      <?php if ($platforms === []): ?>
        <div class="empty" style="padding:32px"><p class="empty__text mb-0">No bookings in this period.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Platform</th><th class="text-right">Bookings</th><th class="text-right">Volume</th></tr></thead>
            <tbody>
              <?php foreach ($platforms as $platform): ?>
                <tr>
                  <td class="small" style="font-weight:600"><?= $this->e(ucfirst((string) $platform['platform'])) ?></td>
                  <td class="text-right mono"><?= $this->number($platform['bookings']) ?></td>
                  <td class="text-right mono"><?= $this->moneyShort($platform['volume']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card__head"><h2 class="h3">Top creator cities</h2></div>
      <?php if ($cities === []): ?>
        <div class="empty" style="padding:32px"><p class="empty__text mb-0">No location data yet.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>City</th><th class="text-right">Creators</th><th class="text-right">Avg rating</th></tr></thead>
            <tbody>
              <?php foreach ($cities as $city): ?>
                <tr>
                  <td class="small"><?= $this->e($city['city']) ?></td>
                  <td class="text-right mono"><?= $this->number($city['creators']) ?></td>
                  <td class="text-right mono">★ <?= number_format((float) $city['avg_rating'], 1) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Engagement -->
<section class="grid grid--3">
  <?php foreach ([
      ['Messages sent',  $this->number($totals['messages_sent'] ?? 0),  'chat'],
      ['Searches',       $this->number($totals['searches'] ?? 0),       'search'],
      ['Profile views',  $this->number($totals['profile_views'] ?? 0),  'eye'],
  ] as [$label, $value, $icon]): ?>
    <div class="stat" style="--tone:var(--secondary)">
      <div class="stat__icon"><?= $this->icon($icon) ?></div>
      <div class="stat__value"><?= $this->e($value) ?></div>
      <div class="stat__label"><?= $this->e($label) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<?php $this->stop(); ?>

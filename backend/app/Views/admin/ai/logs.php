<?php

declare(strict_types=1);

/**
 * AI call log.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $summary
 * @var list<array<string,mixed>> $byModel
 * @var list<array{date:string,spend:float,calls:int}> $daily
 * @var int $windowDays
 * @var list<string> $providers
 * @var list<string> $promptCodes
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

// Aggregates round to two decimals; the per-call cost and the bar tooltips ask
// for the full six, which is the precision ai_logs.cost is stored at.
$cost      = static fn (mixed $value): string => \Zuste\Controllers\Admin\AiController::formatCost((float) $value);
$costExact = static fn (mixed $value): string => \Zuste\Controllers\Admin\AiController::formatCost((float) $value, true);

// Axis and peak labels need to stay short, so they trade the six-decimal
// precision of $costExact() for something readable. The exact figure is still
// on every bar's tooltip and in the table.
$tick = static function (float $value): string {
    if ($value <= 0.0) {
        return '0';
    }

    // USD, like every other AI cost figure: providers publish prices in
    // dollars and ai_logs.cost is stored unconverted.
    return \Zuste\Helpers\Money::symbol('USD')
        . \Zuste\Helpers\Money::groupIndian($value, $value < 1 ? 4 : 2);
};

$statusTone = static fn (string $status): string => match ($status) {
    'success'      => 'success',
    'failed'       => 'error',
    'rate_limited' => 'warning',
    'filtered'     => 'info',
    default        => 'neutral',
};

$calls     = (int) ($summary['calls'] ?? 0);
$successes = (int) ($summary['successes'] ?? 0);
$rate      = $calls > 0 ? (int) round($successes / $calls * 100) : 0;
$tokensIn  = (int) ($summary['tokens_in'] ?? 0);
$tokensOut = (int) ($summary['tokens_out'] ?? 0);

$providerOptions = ['' => 'Any provider'];
foreach ($providers as $provider) {
    $providerOptions[$provider] = ucfirst($provider);
}

$promptOptions = ['' => 'Any prompt'];
foreach ($promptCodes as $code) {
    $promptOptions[$code] = $code;
}

// ---------------------------------------------------------------------
// Daily-cost column chart, drawn here rather than by a library.
// Single series, so no legend: the heading names what is plotted.
// ---------------------------------------------------------------------
$chartWidth  = 720;
$chartHeight = 190;
$padLeft     = 62;
$padRight    = 12;
$padTop      = 14;
$padBottom   = 30;

$plotWidth  = $chartWidth - $padLeft - $padRight;
$plotHeight = $chartHeight - $padTop - $padBottom;
$baseline   = $padTop + $plotHeight;

$dayCount = max(1, count($daily));
$band     = $plotWidth / $dayCount;
$barWidth = min(24.0, max(3.0, $band - 2.0));   // 2px of surface between neighbours

$peak = 0.0;
$peakIndex = -1;
$totalSpend = 0.0;

foreach ($daily as $index => $point) {
    $totalSpend += $point['spend'];

    if ($point['spend'] > $peak) {
        $peak      = $point['spend'];
        $peakIndex = $index;
    }
}

$scaleMax = $peak > 0 ? $peak : 1.0;

/** Rounded-top column: square where it meets the baseline. */
$columnPath = static function (float $x, float $y, float $w, float $h): string {
    $r = min(4.0, $w / 2, $h);

    return sprintf(
        'M%.2f %.2fL%.2f %.2fA%.2f %.2f 0 0 1 %.2f %.2fL%.2f %.2fA%.2f %.2f 0 0 1 %.2f %.2fL%.2f %.2fZ',
        $x,
        $y + $h,
        $x,
        $y + $r,
        $r,
        $r,
        $x + $r,
        $y,
        $x + $w - $r,
        $y,
        $r,
        $r,
        $x + $w,
        $y + $r,
        $x + $w,
        $y + $h
    );
};

?>

<div class="wgrid mb-5">

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#632CE5">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('chat') ?></div></div>
      <div class="kpi__value"><?= $this->number($calls) ?></div>
      <div class="kpi__label">Calls in <?= (int) $windowDays ?> days</div>
      <div class="kpi__foot"><?= $this->number($successes) ?> succeeded</div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:<?= $calls > 0 && $rate < 90 ? '#B45309' : '#006A34' ?>">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('check-circle') ?></div></div>
      <div class="kpi__value"><?= $calls === 0 ? '—' : $rate . '%' ?></div>
      <div class="kpi__label">Success rate</div>
      <div class="kpi__foot"><?= $this->number($calls - $successes) ?> did not succeed</div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#B80049">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('wallet') ?></div></div>
      <div class="kpi__value"><?= $this->e($cost($summary['spend'] ?? 0)) ?></div>
      <div class="kpi__label">Spend in <?= (int) $windowDays ?> days</div>
      <div class="kpi__foot">Average <?= $this->number((int) round((float) ($summary['avg_duration'] ?? 0))) ?> ms per call</div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#2563EB">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('database') ?></div></div>
      <div class="kpi__value"><?= $this->compact($tokensIn + $tokensOut) ?></div>
      <div class="kpi__label">Tokens</div>
      <div class="kpi__foot"><?= $this->compact($tokensIn) ?> in · <?= $this->compact($tokensOut) ?> out</div>
    </div>
  </div>
</div>

<div class="grid grid--sidebar mb-5">

  <div class="card">
    <div class="card__head">
      <div>
        <h2 class="h3">Daily spend</h2>
        <p class="muted small mt-1">
          Cost per day over the last <?= (int) $windowDays ?> days · <?= $this->e($cost($totalSpend)) ?> in total
        </p>
      </div>
    </div>

    <div class="card__body">
      <?php if ($peak <= 0.0): ?>
        <div class="empty">
          <div class="empty__art"><?= $this->icon('chart', 'ic ic--lg') ?></div>
          <p class="empty__title">No spend recorded</p>
          <p class="empty__text">Bars appear here once model calls start being logged.</p>
        </div>
      <?php else: ?>
        <div class="chart-scroll">
          <svg class="chart" viewBox="0 0 <?= (int) $chartWidth ?> <?= (int) $chartHeight ?>"
               role="img" preserveAspectRatio="xMidYMid meet"
               aria-label="Daily AI spend for the last <?= (int) $windowDays ?> days, peaking at <?= $this->e($cost($peak)) ?>.">

            <?php // Gridlines and y ticks: hairline, one step off the surface. ?>
            <?php foreach ([0.0, 0.5, 1.0] as $fraction): ?>
              <?php $y = $baseline - ($plotHeight * $fraction); ?>
              <line class="chart__grid" x1="<?= (int) $padLeft ?>" y1="<?= sprintf('%.2f', $y) ?>"
                    x2="<?= (int) ($chartWidth - $padRight) ?>" y2="<?= sprintf('%.2f', $y) ?>"></line>
              <text class="chart__tick" x="<?= (int) ($padLeft - 8) ?>" y="<?= sprintf('%.2f', $y + 4) ?>"
                    text-anchor="end"><?= $this->e($tick($scaleMax * $fraction)) ?></text>
            <?php endforeach; ?>

            <?php foreach ($daily as $index => $point): ?>
              <?php
              $x      = $padLeft + ($band * $index) + (($band - $barWidth) / 2);
              $height = $point['spend'] > 0 ? max(2.0, $plotHeight * ($point['spend'] / $scaleMax)) : 0.0;
              $y      = $baseline - $height;
              $label  = date('j M', strtotime($point['date'])) . ' — '
                      . ($point['calls'] === 0
                          ? 'no calls'
                          : $costExact($point['spend']) . ' from ' . $point['calls']
                            . ' call' . ($point['calls'] === 1 ? '' : 's'));
              ?>
              <g class="chart__band">
                <title><?= $this->e($label) ?></title>

                <?php // Full-height hit area so the tooltip target is bigger than the bar. ?>
                <rect class="chart__hit" x="<?= sprintf('%.2f', $padLeft + ($band * $index)) ?>"
                      y="<?= (int) $padTop ?>" width="<?= sprintf('%.2f', $band) ?>"
                      height="<?= sprintf('%.2f', $plotHeight) ?>"></rect>

                <?php if ($height > 0.0): ?>
                  <path class="chart__bar" d="<?= $columnPath($x, $y, $barWidth, $height) ?>"></path>
                <?php else: ?>
                  <?php // A zero day still reads as a day, not a hole. ?>
                  <rect class="chart__zero" x="<?= sprintf('%.2f', $x) ?>" y="<?= sprintf('%.2f', $baseline - 2) ?>"
                        width="<?= sprintf('%.2f', $barWidth) ?>" height="2"></rect>
                <?php endif; ?>
              </g>
            <?php endforeach; ?>

            <?php // One direct label, on the peak, because that is the number worth reading. ?>
            <?php if ($peakIndex >= 0): ?>
              <?php
              $peakHeight = max(2.0, $plotHeight * ($peak / $scaleMax));
              $peakX      = $padLeft + ($band * $peakIndex) + ($band / 2);
              $peakY      = $baseline - $peakHeight - 6;
              $anchor     = $peakIndex < 3 ? 'start' : ($peakIndex > $dayCount - 4 ? 'end' : 'middle');
              ?>
              <text class="chart__value" x="<?= sprintf('%.2f', $peakX) ?>" y="<?= sprintf('%.2f', max($padTop + 9, $peakY)) ?>"
                    text-anchor="<?= $anchor ?>"><?= $this->e($tick($peak)) ?></text>
            <?php endif; ?>

            <?php // Three x labels: first, middle, last. ?>
            <?php foreach ([0, intdiv($dayCount - 1, 2), $dayCount - 1] as $slot): ?>
              <?php if (!isset($daily[$slot])) { continue; } ?>
              <text class="chart__tick" x="<?= sprintf('%.2f', $padLeft + ($band * $slot) + ($band / 2)) ?>"
                    y="<?= (int) ($baseline + 18) ?>"
                    text-anchor="<?= $slot === 0 ? 'start' : ($slot === $dayCount - 1 ? 'end' : 'middle') ?>">
                <?= $this->e(date('j M', strtotime($daily[$slot]['date']))) ?>
              </text>
            <?php endforeach; ?>
          </svg>
        </div>

        <p class="tiny faint mt-2">
          Hover a day for its exact cost. Every value is also in the table below and in the CSV export.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2 class="h3">Cost by model</h2></div>

    <?php if ($byModel === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('database', 'ic ic--lg') ?></div>
        <p class="empty__title">Nothing to break down</p>
        <p class="empty__text">No calls in the last <?= (int) $windowDays ?> days.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Model</th><th class="text-right">Calls</th>
              <th class="text-right">Tokens</th><th class="text-right">Cost</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($byModel as $model): ?>
              <tr>
                <td>
                  <span class="small mono"><?= $this->e($model['model']) ?></span>
                  <div class="tiny muted">
                    <?= $this->e(ucfirst((string) $model['provider'])) ?>
                    · <?= $this->number((int) round((float) $model['avg_duration'])) ?> ms average
                  </div>
                </td>
                <td class="text-right mono small"><?= $this->number((int) $model['calls']) ?></td>
                <td class="text-right mono small">
                  <?= $this->compact((int) $model['tokens_in'] + (int) $model['tokens_out']) ?>
                </td>
                <td class="text-right mono small"><?= $this->e($cost($model['spend'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search an error, model or prompt code…',
      'showDateRange'     => true,
      'showExport'        => true,
      'selects'           => [
          ['name' => 'status', 'label' => 'Status', 'options' => [
              ''             => 'Any status',
              'success'      => 'Success',
              'failed'       => 'Failed',
              'rate_limited' => 'Rate limited',
              'filtered'     => 'Filtered',
          ]],
          ['name' => 'provider', 'label' => 'Provider', 'options' => $providerOptions],
          ['name' => 'prompt_code', 'label' => 'Prompt', 'options' => $promptOptions],
      ],
  ]); ?>

  <?php if ($rows === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('star', 'ic ic--lg') ?></div>
      <p class="empty__title">No calls match</p>
      <p class="empty__text">
        Every model call is written here as it happens. Widen the date range or clear the filters.
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>
              <a href="<?= $this->queryUrl(['sort_by' => 'created_at', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">When</a>
            </th>
            <th>Prompt</th>
            <th>Model</th>
            <th>Status</th>
            <th class="text-right">
              <a href="<?= $this->queryUrl(['sort_by' => 'input_tokens', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Tokens</a>
            </th>
            <th class="text-right">
              <a href="<?= $this->queryUrl(['sort_by' => 'cost', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Cost</a>
            </th>
            <th class="text-right">
              <a href="<?= $this->queryUrl(['sort_by' => 'duration_ms', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Time</a>
            </th>
            <th>Requested by</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="small muted" style="white-space:nowrap">
                <?= $this->dateTime($row['created_at']) ?>
                <div class="tiny faint"><?= $this->timeAgo($row['created_at']) ?></div>
              </td>

              <td>
                <span class="small mono"><?= $this->e($row['prompt_code'] ?? '—') ?></span>
                <?php if (!empty($row['error_message'])): ?>
                  <div class="tiny text-error truncate" style="max-width:260px"
                       title="<?= $this->e($row['error_message']) ?>">
                    <?= $this->e($row['error_message']) ?>
                  </div>
                <?php endif; ?>
              </td>

              <td class="small muted">
                <?= $this->e($row['model'] ?? '—') ?>
                <div class="tiny faint"><?= $this->e(ucfirst((string) $row['provider'])) ?></div>
              </td>

              <td>
                <span class="chip chip--<?= $statusTone((string) $row['status']) ?>">
                  <span class="chip__dot"></span>
                  <?= $this->e(ucfirst(str_replace('_', ' ', (string) $row['status']))) ?>
                </span>
              </td>

              <td class="text-right mono small" style="white-space:nowrap">
                <?= $this->number((int) $row['input_tokens']) ?> / <?= $this->number((int) $row['output_tokens']) ?>
              </td>

              <td class="text-right mono small"><?= $this->e($costExact($row['cost'])) ?></td>

              <td class="text-right mono small"><?= $this->number((int) $row['duration_ms']) ?> ms</td>

              <td class="small muted">
                <?= $this->e(ucfirst((string) $row['requested_by_type'])) ?>
                <?php if ($row['requested_by_id'] !== null): ?>
                  <span class="tiny faint">#<?= (int) $row['requested_by_id'] ?></span>
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
/* Chart styling — the marks carry the colour, the text stays on ink tokens. */
.chart-scroll { overflow-x: auto; }
.chart { display: block; width: 100%; min-width: 480px; height: auto; }
.chart__grid { stroke: var(--outline-variant); stroke-width: 1; }
.chart__tick { fill: var(--on-surface-variant); font-size: 10px; }
.chart__value { fill: var(--on-surface); font-size: 11px; font-weight: 600; }
.chart__bar { fill: var(--secondary); }
.chart__zero { fill: var(--outline-variant); }
.chart__hit { fill: transparent; }
/* Opacity rather than a second hue, so the hover reads the same in both themes. */
.chart__band:hover .chart__bar  { fill-opacity: .72; }
.chart__band:hover .chart__zero { fill: var(--outline); }
.chart__band:hover .chart__hit  { fill: color-mix(in srgb, var(--secondary) 8%, transparent); }
</style>
<?php $this->stop(); ?>

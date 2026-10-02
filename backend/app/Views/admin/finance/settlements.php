<?php

declare(strict_types=1);

/**
 * Daily settlement reconciliation.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $settlements
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var array<string,mixed> $totals
 * @var list<array{label:string,value:float}> $series
 */

$this->extend('admin.layouts.app');
$this->start('content');

?>

<section class="grid grid--4 mb-6">
  <?php foreach ([
      ['Gross volume',  $this->moneyShort($totals['gross_volume'] ?? 0),  'trending-up', 'var(--primary)'],
      ['Platform fees', $this->moneyShort($totals['platform_fees'] ?? 0), 'wallet',      'var(--secondary)'],
      ['Commissions',   $this->moneyShort($totals['commissions'] ?? 0),   'percent',     'var(--tertiary)'],
      ['Net revenue',   $this->moneyShort($totals['net_revenue'] ?? 0),   'chart',       '#e2165f'],
  ] as [$label, $value, $icon, $tone]): ?>
    <div class="stat" style="--tone:<?= $tone ?>">
      <div class="stat__icon"><?= $this->icon($icon === 'percent' ? 'chart' : $icon) ?></div>
      <div class="stat__value"><?= $this->e($value) ?></div>
      <div class="stat__label"><?= $this->e($label) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<div class="card mb-6">
  <div class="card__head">
    <h2 class="h3">Net revenue trend</h2>
    <span class="label">Selected period</span>
  </div>
  <div class="card__body">
    <div data-chart="<?= $this->json($series) ?>"></div>
  </div>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'       => $filters,
      'showDateRange' => true,
      'showExport'    => true,
      'searchPlaceholder' => 'Filter by date range…',
  ]); ?>

  <?php if ($settlements === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('chart', 'ic ic--lg') ?></div>
      <p class="empty__title">No settlement rows yet</p>
      <p class="empty__text">
        Settlements are produced by the <code class="mono">analytics:rollup</code> job.
        Run it from System → Jobs to populate this table.
      </p>
      <?php if ($this->can('system.jobs')): ?>
        <a href="/admin/system?tab=jobs" class="btn btn--outline">Open scheduled jobs</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th class="text-right">Gross volume</th>
            <th class="text-right">Platform fees</th>
            <th class="text-right">Commissions</th>
            <th class="text-right">Tax</th>
            <th class="text-right">Refunds</th>
            <th class="text-right">Payouts</th>
            <th class="text-right">Net revenue</th>
            <th class="text-right">Bookings</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($settlements as $settlement): ?>
            <tr>
              <td class="small" style="font-weight:600;white-space:nowrap">
                <?= $this->date($settlement['settlement_date']) ?>
              </td>
              <td class="text-right mono"><?= $this->moneyShort($settlement['gross_volume'], (string) $settlement['currency']) ?></td>
              <td class="text-right mono"><?= $this->moneyShort($settlement['platform_fees'], (string) $settlement['currency']) ?></td>
              <td class="text-right mono"><?= $this->moneyShort($settlement['commissions'], (string) $settlement['currency']) ?></td>
              <td class="text-right mono small muted"><?= $this->moneyShort($settlement['taxes'], (string) $settlement['currency']) ?></td>
              <td class="text-right mono small <?= (float) $settlement['refunds_total'] > 0 ? 'text-error' : 'faint' ?>">
                <?= $this->moneyShort($settlement['refunds_total'], (string) $settlement['currency']) ?>
              </td>
              <td class="text-right mono small muted"><?= $this->moneyShort($settlement['payouts_total'], (string) $settlement['currency']) ?></td>
              <td class="text-right" style="font-weight:700;color:var(--primary)">
                <?= $this->moneyShort($settlement['net_revenue'], (string) $settlement['currency']) ?>
              </td>
              <td class="text-right mono small"><?= $this->number($settlement['bookings_count']) ?></td>
              <td>
                <span class="chip chip--<?= $settlement['status'] === 'reconciled' ? 'success' : ($settlement['status'] === 'closed' ? 'info' : 'neutral') ?>">
                  <?= $this->e(ucfirst((string) $settlement['status'])) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:var(--surface-low);font-weight:700">
            <td>Total</td>
            <td class="text-right mono"><?= $this->moneyShort($totals['gross_volume'] ?? 0) ?></td>
            <td class="text-right mono"><?= $this->moneyShort($totals['platform_fees'] ?? 0) ?></td>
            <td class="text-right mono"><?= $this->moneyShort($totals['commissions'] ?? 0) ?></td>
            <td class="text-right mono"><?= $this->moneyShort($totals['taxes'] ?? 0) ?></td>
            <td class="text-right mono text-error"><?= $this->moneyShort($totals['refunds_total'] ?? 0) ?></td>
            <td class="text-right mono"><?= $this->moneyShort($totals['payouts_total'] ?? 0) ?></td>
            <td class="text-right mono text-primary"><?= $this->moneyShort($totals['net_revenue'] ?? 0) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  <?php endif; ?>
</div>

<?php $this->stop(); ?>

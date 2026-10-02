<?php

declare(strict_types=1);

/**
 * Slow query monitor.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed> $summary
 * @var array<string,mixed>|null $slowest
 * @var array<string,mixed>|null $frequent
 * @var int $thresholdMs
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$durationTone = static fn (float $ms): string => match (true) {
    $ms >= 2000 => 'error',
    $ms >= 1000 => 'warning',
    default     => 'neutral',
};

$sortUrl = function (string $column) use ($filters): string {
    $descending = ($filters['sort_by'] ?? '') === $column && ($filters['sort_dir'] ?? 'DESC') === 'DESC';

    return $this->queryUrl(['sort_by' => $column, 'sort_dir' => $descending ? 'ASC' : 'DESC']);
};

// `rows_examined` is only filled in when the recorder can obtain it, so the
// column is shown only when there is something in it.
$showRowsExamined = false;
foreach ($rows as $candidate) {
    if ($candidate['rows_examined'] !== null) {
        $showRowsExamined = true;
        break;
    }
}

?>

<?php if ($thresholdMs > 0): ?>
  <div class="callout callout--info mb-5">
    <?= $this->icon('info', 'ic ic--sm') ?>
    <div>
      <strong>A statement is recorded once it takes longer than <?= $this->number($thresholdMs) ?> ms.</strong>
      Every query the application runs is timed in the database layer. Literals are stripped before hashing, so
      <code class="mono">WHERE id = 1</code> and <code class="mono">WHERE id = 2</code> aggregate into one row with an
      occurrence count, a running average and a high-water mark.
      The threshold is <code class="mono">security.slow_query_threshold_ms</code><?php if ($this->can('settings.view')): ?>,
      editable in <a href="/admin/settings?group=security">Settings → Security</a><?php endif; ?>. Setting it to 0 turns
      recording off.
    </div>
  </div>
<?php else: ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>Slow query recording is switched off.</strong>
      <code class="mono">security.slow_query_threshold_ms</code> is set to 0, so nothing new is being aggregated.
      Anything already recorded is still listed below.
    </div>
  </div>
<?php endif; ?>

<div class="wgrid mb-5">
  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#632CE5">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('database') ?></div></div>
      <div class="kpi__value"><?= $this->number((int) ($summary['shapes'] ?? 0)) ?></div>
      <div class="kpi__label">Distinct slow statements</div>
      <div class="kpi__foot"><span class="kpi__meta"><?= $this->number((int) ($summary['occurrences'] ?? 0)) ?> executions</span></div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#BA1A1A">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('alert') ?></div></div>
      <div class="kpi__value"><?= $this->number((int) round((float) ($summary['slowest'] ?? 0))) ?><span class="small muted"> ms</span></div>
      <div class="kpi__label">Worst single execution</div>
      <div class="kpi__foot">
        <span class="kpi__meta"><?= $slowest !== null ? $this->e((string) ($slowest['route'] ?? 'no route recorded')) : 'nothing recorded' ?></span>
      </div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#B45309">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('refresh') ?></div></div>
      <div class="kpi__value"><?= $frequent !== null ? $this->number((int) $frequent['occurrences']) : '0' ?></div>
      <div class="kpi__label">Most repeated statement</div>
      <div class="kpi__foot">
        <span class="kpi__meta"><?= $frequent !== null ? $this->number((int) round((float) $frequent['duration_ms'])) . ' ms average' : 'nothing recorded' ?></span>
      </div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#006A34">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('chart') ?></div></div>
      <div class="kpi__value"><?= $this->number((int) round((float) ($summary['avg_ms'] ?? 0))) ?><span class="small muted"> ms</span></div>
      <div class="kpi__label">Average across shapes</div>
      <div class="kpi__foot">
        <span class="kpi__meta">
          <?= ($summary['last_seen'] ?? null) !== null ? 'last seen ' . $this->timeAgo($summary['last_seen']) : 'no data yet' ?>
        </span>
      </div>
    </div>
  </div>
</div>

<?php if ($slowest !== null): ?>
  <div class="card mb-5">
    <div class="card__head">
      <h2 class="h3">Worst offender</h2>
      <span class="chip chip--error"><?= $this->number((int) round((float) $slowest['max_duration_ms'])) ?> ms peak</span>
    </div>
    <div class="card__body">
      <pre class="dev-sql"><?= $this->e($slowest['sql_text']) ?></pre>
      <p class="tiny muted mt-2">
        Seen <?= $this->number((int) $slowest['occurrences']) ?> time<?= (int) $slowest['occurrences'] === 1 ? '' : 's' ?>
        <?= !empty($slowest['route']) ? ' on ' . $this->e((string) $slowest['route']) : '' ?>.
      </p>
    </div>
  </div>
<?php endif; ?>

<div class="card">

  <div class="card__head">
    <div>
      <h2 class="h3">Aggregated statements</h2>
      <p class="muted small mt-1">
        Sorted by <strong><?= $this->e(str_replace('_', ' ', (string) ($filters['sort_by'] ?? 'max_duration_ms'))) ?></strong>,
        <?= ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'highest first' : 'lowest first' ?>.
      </p>
    </div>

    <form method="post" action="/admin/developer/queries/clear">
      <?= $this->csrf() ?>
      <button type="submit" class="btn btn--outline btn--sm text-error"
              data-confirm="Every aggregated statement, its occurrence count and its high-water mark will be deleted."
              data-confirm-title="Clear the query log?"
              data-confirm-label="Clear log" data-confirm-tone="danger">
        <?= $this->icon('trash', 'ic ic--sm') ?> Clear log
      </button>
    </form>
  </div>

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search statement text or route…',
      'showDateRange'     => true,
  ]); ?>

  <?php if ($rows === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
      <p class="empty__title">No slow queries recorded</p>
      <p class="empty__text">
        <?= $thresholdMs > 0
            ? 'Nothing has crossed ' . $this->number($thresholdMs) . ' ms since the log was last cleared.'
            : 'Recording is disabled, so nothing is being collected.' ?>
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Statement</th>
            <th>Route</th>
            <th class="text-right"><a href="<?= $sortUrl('occurrences') ?>">Runs</a></th>
            <th class="text-right"><a href="<?= $sortUrl('duration_ms') ?>">Average</a></th>
            <th class="text-right"><a href="<?= $sortUrl('max_duration_ms') ?>">Peak</a></th>
            <?php if ($showRowsExamined): ?><th class="text-right">Rows read</th><?php endif; ?>
            <th><a href="<?= $sortUrl('first_seen_at') ?>">First seen</a></th>
            <th><a href="<?= $sortUrl('last_seen_at') ?>">Last seen</a></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td style="max-width:420px">
                <pre class="dev-sql dev-sql--inline"><?= $this->e($row['sql_text']) ?></pre>
              </td>

              <td class="mono tiny truncate" style="max-width:150px"><?= $this->e($row['route'] ?? '—') ?></td>

              <td class="text-right mono small"><?= $this->number((int) $row['occurrences']) ?></td>

              <td class="text-right mono small"><?= $this->number((int) round((float) $row['duration_ms'])) ?> ms</td>

              <td class="text-right">
                <span class="chip chip--<?= $durationTone((float) $row['max_duration_ms']) ?>">
                  <?= $this->number((int) round((float) $row['max_duration_ms'])) ?> ms
                </span>
              </td>

              <?php if ($showRowsExamined): ?>
                <td class="text-right mono small">
                  <?= $row['rows_examined'] === null ? '—' : $this->number((int) $row['rows_examined']) ?>
                </td>
              <?php endif; ?>

              <td class="small muted" style="white-space:nowrap"><?= $this->date($row['first_seen_at']) ?></td>

              <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($row['last_seen_at']) ?></td>
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
  .dev-sql {
    margin: 0; padding: 12px; border-radius: var(--r);
    background: var(--surface-variant); border: 1px solid var(--outline-variant);
    font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
    font-size: 12px; line-height: 1.55; white-space: pre-wrap; word-break: break-word;
    max-height: 220px; overflow: auto;
  }
  .dev-sql--inline { max-height: 90px; font-size: 11.5px; padding: 8px 10px; }
</style>
<?php $this->stop(); ?>

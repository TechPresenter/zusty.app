<?php

declare(strict_types=1);

/**
 * Developer tools overview.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $api
 * @var array<string,mixed> $queries
 * @var array<string,mixed> $cache
 * @var array<string,mixed> $environment
 * @var array<string,bool>  $extensions
 * @var array<string,mixed> $settings
 */

$this->extend('admin.layouts.app');
$this->start('content');

$errorRate = (float) ($api['error_rate'] ?? 0);
$p95       = (int) ($api['p95_ms'] ?? 0);

$bytes = static function (int $value): string {
    if ($value < 1024) {
        return $value . ' B';
    }

    if ($value < 1048576) {
        return round($value / 1024, 1) . ' KB';
    }

    return round($value / 1048576, 2) . ' MB';
};

?>

<?php if (!(bool) $settings['api_logging']): ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>Request logging is switched off.</strong>
      No new rows are being written to <code class="mono">api_logs</code>. Existing rows are still searchable.
    </div>
  </div>
<?php endif; ?>

<div class="wgrid mb-5">

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#2563EB" href="/admin/developer/api-logs">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('chart') ?></div></div>
      <div class="kpi__value"><?= $this->number((int) $api['requests_24h']) ?></div>
      <div class="kpi__label">API requests (24h)</div>
      <div class="kpi__foot"><span class="kpi__meta"><?= $this->number((int) $api['total']) ?> stored in total</span></div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:<?= $errorRate > 5 ? '#BA1A1A' : '#B45309' ?>" href="/admin/developer/api-logs?status=5xx">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('alert') ?></div></div>
      <div class="kpi__value"><?= $this->e(number_format($errorRate, 1)) ?>%</div>
      <div class="kpi__label">Error rate (24h)</div>
      <div class="kpi__foot">
        <span class="kpi__meta"><?= $this->number((int) $api['server_errors_24h']) ?> server errors</span>
      </div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:<?= $p95 > 1000 ? '#BA1A1A' : '#632CE5' ?>"
       href="/admin/developer/api-logs?min_ms=1000&amp;sort_by=duration_ms&amp;sort_dir=DESC">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('trending-up') ?></div></div>
      <div class="kpi__value"><?= $this->number($p95) ?><span class="small muted"> ms</span></div>
      <div class="kpi__label">95th percentile (24h)</div>
      <div class="kpi__foot">
        <span class="kpi__meta">avg <?= $this->number((int) $api['avg_ms']) ?> ms · peak <?= $this->number((int) $api['slowest_ms']) ?> ms</span>
      </div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#006A34" href="/admin/developer/queries">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('database') ?></div></div>
      <div class="kpi__value"><?= $this->number((int) $queries['shapes']) ?></div>
      <div class="kpi__label">Slow query shapes</div>
      <div class="kpi__foot">
        <span class="kpi__meta">
          <?= $this->number((int) $queries['occurrences']) ?> executions
          <?= (int) $settings['slow_query_ms'] > 0 ? 'over ' . $this->number((int) $settings['slow_query_ms']) . ' ms' : '(recording is off)' ?>
        </span>
      </div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#0F766E" href="/admin/developer/cache">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('refresh') ?></div></div>
      <div class="kpi__value"><?= $this->number((int) ($cache['entries'] ?? 0)) ?></div>
      <div class="kpi__label">Cache entries</div>
      <div class="kpi__foot">
        <span class="kpi__meta"><?= $this->e($bytes((int) ($cache['bytes'] ?? 0))) ?> · <?= $this->number((int) ($cache['hits'] ?? 0)) ?> hits</span>
      </div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:<?= (int) ($cache['expired'] ?? 0) > 0 ? '#B45309' : '#64748B' ?>" href="/admin/developer/cache">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('trash') ?></div></div>
      <div class="kpi__value"><?= $this->number((int) ($cache['expired'] ?? 0)) ?></div>
      <div class="kpi__label">Expired entries</div>
      <div class="kpi__foot"><span class="kpi__meta">Driver: <?= $this->e((string) ($cache['driver'] ?? 'database')) ?></span></div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#B80049" href="/admin/developer/tester">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('external') ?></div></div>
      <div class="kpi__value" style="font-size:21px">API tester</div>
      <div class="kpi__label">Call an endpoint</div>
      <div class="kpi__foot"><span class="kpi__meta">Issued from the server</span></div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#475569" href="/admin/developer/api-logs?status=4xx">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('filter') ?></div></div>
      <div class="kpi__value"><?= $this->number(max(0, (int) $api['errors_24h'] - (int) $api['server_errors_24h'])) ?></div>
      <div class="kpi__label">4xx client errors (24h)</div>
      <div class="kpi__foot">
        <span class="kpi__meta"><?= $this->number((int) $api['errors_24h']) ?> failures in total</span>
      </div>
    </a>
  </div>
</div>

<div class="grid grid--2 mb-5">

  <div class="card">
    <div class="card__head">
      <h2 class="h3">Slowest paths (24h)</h2>
      <a href="/admin/developer/api-logs?sort_by=duration_ms&amp;sort_dir=DESC" class="btn btn--ghost btn--sm">
        Open log <?= $this->icon('chevron-right', 'ic ic--sm') ?>
      </a>
    </div>

    <?php if (($api['slowest_paths'] ?? []) === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('chart', 'ic ic--lg') ?></div>
        <p class="empty__title">No requests in the last 24 hours</p>
        <p class="empty__text">Paths appear here once the API starts serving traffic.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Path</th><th class="text-right">Calls</th><th class="text-right">Average</th><th class="text-right">Peak</th></tr>
          </thead>
          <tbody>
            <?php foreach ($api['slowest_paths'] as $row): ?>
              <tr>
                <td class="mono tiny truncate" style="max-width:280px">
                  <a href="/admin/developer/api-logs?q=<?= $this->e(rawurlencode((string) $row['path'])) ?>"><?= $this->e($row['path']) ?></a>
                </td>
                <td class="text-right mono small"><?= $this->number((int) $row['hits']) ?></td>
                <td class="text-right mono small"><?= $this->number((int) $row['avg_ms']) ?> ms</td>
                <td class="text-right mono small">
                  <span class="chip chip--<?= (int) $row['max_ms'] > 1000 ? 'error' : ((int) $row['max_ms'] > 500 ? 'warning' : 'neutral') ?>">
                    <?= $this->number((int) $row['max_ms']) ?> ms
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head"><h2 class="h3">Runtime</h2></div>

    <div class="table-wrap">
      <table class="table">
        <tbody>
          <tr><td class="muted small">PHP</td><td class="mono small"><?= $this->e($environment['php']) ?> <span class="muted">(<?= $this->e($environment['sapi']) ?>)</span></td></tr>
          <tr><td class="muted small">Database</td><td class="mono small"><?= $this->e($environment['database']) ?></td></tr>
          <tr><td class="muted small">Web server</td><td class="mono small truncate" style="max-width:280px"><?= $this->e($environment['server']) ?></td></tr>
          <tr><td class="muted small">Environment</td>
            <td>
              <span class="chip chip--<?= $environment['app_env'] === 'production' ? 'success' : 'info' ?>">
                <?= $this->e($environment['app_env']) ?>
              </span>
              <?php if ((bool) $environment['app_debug']): ?>
                <span class="chip chip--warning">debug on</span>
              <?php endif; ?>
            </td>
          </tr>
          <tr><td class="muted small">App version</td><td class="mono small"><?= $this->e($environment['app_version']) ?></td></tr>
          <tr><td class="muted small">Base URL</td><td class="mono small truncate" style="max-width:280px"><?= $this->e($environment['app_url']) ?></td></tr>
          <tr><td class="muted small">Timezone</td><td class="mono small"><?= $this->e($environment['timezone']) ?></td></tr>
          <tr><td class="muted small">Memory limit</td><td class="mono small"><?= $this->e($environment['memory_limit']) ?> · max exec <?= $this->e($environment['max_exec']) ?>s</td></tr>
          <?php if ($environment['curl_version'] !== ''): ?>
            <tr><td class="muted small">libcurl</td><td class="mono small"><?= $this->e($environment['curl_version']) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card__body">
      <p class="label mb-2">Extensions</p>
      <div class="chip-row">
        <?php foreach ($extensions as $name => $loaded): ?>
          <span class="chip chip--<?= $loaded ? 'success' : 'neutral' ?>">
            <span class="chip__dot"></span><?= $this->e($name) ?>
          </span>
        <?php endforeach; ?>
      </div>
      <p class="tiny muted mt-3">
        <?= $extensions['apcu'] ? 'APCu is present, so cache reads are served from local memory before the database.' : 'APCu is absent, so every cache read is a database lookup. That is expected on shared hosting.' ?>
      </p>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <div>
      <h2 class="h3">Developer settings</h2>
      <p class="muted small mt-1">Stored in the <code class="mono">settings</code> table under the <code class="mono">developer</code> group.</p>
    </div>
    <?php if (!(bool) $settings['stored']): ?>
      <span class="chip chip--info">Using defaults</span>
    <?php endif; ?>
  </div>

  <div class="card__body">
    <?php if ($this->can('settings.manage')): ?>
      <form method="post" action="/admin/developer/settings">
        <?= $this->csrf() ?>

        <div class="field">
          <span class="field__label">Request logging</span>
          <label class="switch">
            <input type="checkbox" name="api_logging" value="1" <?= (bool) $settings['api_logging'] ? 'checked' : '' ?>>
            <span class="switch__track"></span>
            <span class="small">Write one <code class="mono">api_logs</code> row per API request</span>
          </label>
          <p class="field__hint">Turn this off if the log is growing faster than you can prune it.</p>
        </div>

        <button type="submit" class="btn btn--primary">
          <?= $this->icon('check', 'ic ic--sm') ?> Save
        </button>
      </form>
    <?php else: ?>
      <p class="label">Request logging</p>
      <span class="chip chip--<?= (bool) $settings['api_logging'] ? 'success' : 'neutral' ?>">
        <span class="chip__dot"></span><?= (bool) $settings['api_logging'] ? 'On' : 'Off' ?>
      </span>
      <p class="tiny muted mt-3">Changing this needs the "settings.manage" permission.</p>
    <?php endif; ?>

    <div class="table-wrap mt-4">
      <table class="table">
        <tbody>
          <tr>
            <td class="muted small">Sample rate</td>
            <td class="mono small"><?= (int) $settings['sample_rate'] ?>%</td>
            <td class="tiny muted">
              <?= (int) $settings['sample_rate'] >= 100
                  ? 'Every request is recorded.'
                  : 'Successful requests are sampled; failures are always kept.' ?>
              <?php if ($this->can('settings.view')): ?>
                <a href="/admin/settings?group=security"><code class="mono">security.api_log_sample_rate</code></a>
              <?php else: ?>
                <code class="mono">security.api_log_sample_rate</code>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td class="muted small">Slow query threshold</td>
            <td class="mono small">
              <?= (int) $settings['slow_query_ms'] > 0 ? $this->number((int) $settings['slow_query_ms']) . ' ms' : 'disabled' ?>
            </td>
            <td class="tiny muted">
              Enforced by the database layer.
              <?php if ($this->can('settings.view')): ?>
                <a href="/admin/settings?group=security"><code class="mono">security.slow_query_threshold_ms</code></a>
              <?php else: ?>
                <code class="mono">security.slow_query_threshold_ms</code>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td class="muted small">Cache</td>
            <td class="mono small"><?= (bool) $settings['cache_enabled'] ? 'enabled' : 'disabled' ?></td>
            <td class="tiny muted">Default TTL <?= $this->number((int) $settings['cache_ttl']) ?>s</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="row row--wrap gap-4 mt-4">
      <?php if (($queries['last_seen'] ?? null) !== null): ?>
        <span class="small muted">Last slow query: <strong><?= $this->timeAgo($queries['last_seen']) ?></strong></span>
      <?php endif; ?>
      <?php if (($api['oldest'] ?? null) !== null): ?>
        <span class="small muted">Oldest log row: <strong><?= $this->date($api['oldest']) ?></strong></span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<link rel="stylesheet" href="/admin/assets/css/widgets.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<?php $this->stop(); ?>

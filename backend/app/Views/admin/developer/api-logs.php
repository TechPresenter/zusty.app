<?php

declare(strict_types=1);

/**
 * API request log.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed> $summary
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var bool $logging
 * @var int  $sampleRate
 * @var int  $retentionDays
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (int $code): string => match (true) {
    $code >= 500 => 'error',
    $code >= 400 => 'warning',
    $code >= 300 => 'info',
    $code >= 200 => 'success',
    default      => 'neutral',
};

$durationTone = static fn (int $ms): string => match (true) {
    $ms >= 1000 => 'error',
    $ms >= 500  => 'warning',
    default     => 'neutral',
};

$matched = (int) ($summary['matched'] ?? 0);

?>

<?php if (!$logging): ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>Request logging is off.</strong>
      Nothing new is being recorded. Turn it back on from
      <a href="/admin/developer">Developer tools</a>.
    </div>
  </div>
<?php elseif ($sampleRate < 100): ?>
  <div class="callout callout--info mb-5">
    <?= $this->icon('info', 'ic ic--sm') ?>
    <div>
      <strong>Only <?= (int) $sampleRate ?>% of successful requests are recorded.</strong>
      Anything that returned 4xx or 5xx is always kept, so counts below under-report traffic but not failures.
      The rate is <code class="mono">security.api_log_sample_rate</code><?php if ($this->can('settings.view')): ?>,
      in <a href="/admin/settings?group=security">Settings → Security</a><?php endif; ?>.
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--sidebar mb-5">
  <div class="card">
    <div class="card__head"><h2 class="h3">Matching requests</h2></div>
    <div class="card__body">
      <div class="row row--wrap gap-5">
        <div>
          <p class="label">Requests</p>
          <p class="h2 mono"><?= $this->number($matched) ?></p>
        </div>
        <div>
          <p class="label">Failures</p>
          <p class="h2 mono <?= (int) ($summary['errors'] ?? 0) > 0 ? 'text-error' : '' ?>">
            <?= $this->number((int) ($summary['errors'] ?? 0)) ?>
          </p>
        </div>
        <div>
          <p class="label">Average</p>
          <p class="h2 mono"><?= $this->number((int) ($summary['avg_ms'] ?? 0)) ?> <span class="small muted">ms</span></p>
        </div>
        <div>
          <p class="label">Slowest</p>
          <p class="h2 mono"><?= $this->number((int) ($summary['max_ms'] ?? 0)) ?> <span class="small muted">ms</span></p>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2 class="h3">Prune</h2></div>
    <div class="card__body">
      <form method="post" action="/admin/developer/api-logs/prune" class="grid">
        <?= $this->csrf() ?>

        <div class="field">
          <label class="field__label" for="days">Delete rows older than</label>
          <div class="input-group">
            <input class="input mono" id="days" name="days" type="number" min="1" max="3650"
                   step="1" required
                   <?= $this->hasError('days') ? 'aria-invalid="true"' : '' ?>
                   value="<?= $this->hasError('days') ? $this->old('days', (string) $retentionDays) : (int) $retentionDays ?>">
          </div>
          <?php if ($this->hasError('days')): ?>
            <?php // View::error() already escapes; wrapping it again would double-encode. ?>
            <p class="field__error"><?= $this->error('days') ?></p>
          <?php endif; ?>
          <p class="field__hint">Whole days, counted back from now. This cannot be undone.</p>
        </div>

        <div>
          <button type="submit" class="btn btn--outline btn--sm text-error"
                  data-confirm="Request log rows older than the number of days you entered will be deleted permanently."
                  data-confirm-title="Prune the request log?"
                  data-confirm-label="Prune" data-confirm-tone="danger">
            <?= $this->icon('trash', 'ic ic--sm') ?> Prune now
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search path, route name, IP or request id…',
      'showDateRange'     => true,
      'showExport'        => true,
      'selects'           => [
          ['name' => 'method', 'label' => 'Method', 'options' => [
              ''        => 'Any method',
              'GET'     => 'GET',
              'POST'    => 'POST',
              'PUT'     => 'PUT',
              'PATCH'   => 'PATCH',
              'DELETE'  => 'DELETE',
          ]],
          ['name' => 'status', 'label' => 'Status', 'options' => [
              ''    => 'Any status',
              '2xx' => '2xx success',
              '3xx' => '3xx redirect',
              '4xx' => '4xx client error',
              '5xx' => '5xx server error',
          ]],
          ['name' => 'min_ms', 'label' => 'Slower than', 'options' => [
              ''     => 'Any duration',
              '200'  => 'Over 200 ms',
              '500'  => 'Over 500 ms',
              '1000' => 'Over 1 s',
              '3000' => 'Over 3 s',
          ]],
      ],
  ]); ?>

  <?php if ($rows === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('inbox', 'ic ic--lg') ?></div>
      <p class="empty__title">No requests match</p>
      <p class="empty__text">Widen the date range, or clear the filters to see everything recorded.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>
              <a href="<?= $this->queryUrl(['sort_by' => 'created_at', 'sort_dir' => ($filters['sort_by'] ?? '') === 'created_at' && ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">When</a>
            </th>
            <th>Endpoint</th>
            <th>
              <a href="<?= $this->queryUrl(['sort_by' => 'status_code', 'sort_dir' => ($filters['sort_by'] ?? '') === 'status_code' && ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Status</a>
            </th>
            <th class="text-right">
              <a href="<?= $this->queryUrl(['sort_by' => 'duration_ms', 'sort_dir' => ($filters['sort_by'] ?? '') === 'duration_ms' && ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Duration</a>
            </th>
            <th class="text-right">
              <a href="<?= $this->queryUrl(['sort_by' => 'query_count', 'sort_dir' => ($filters['sort_by'] ?? '') === 'query_count' && ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Queries</a>
            </th>
            <th class="text-right">
              <a href="<?= $this->queryUrl(['sort_by' => 'memory_kb', 'sort_dir' => ($filters['sort_by'] ?? '') === 'memory_kb' && ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Memory</a>
            </th>
            <th>Caller</th>
            <th class="text-right">Detail</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
            $id       = (int) $row['id'];
            $status   = (int) $row['status_code'];
            $duration = (int) $row['duration_ms'];
            ?>
            <tr>
              <td class="small muted" style="white-space:nowrap">
                <?= $this->dateTime($row['created_at']) ?>
                <div class="tiny faint"><?= $this->timeAgo($row['created_at']) ?></div>
              </td>

              <td>
                <span class="dev-method dev-method--<?= $this->e(strtolower((string) $row['method'])) ?>"><?= $this->e($row['method']) ?></span>
                <span class="mono tiny"><?= $this->e($row['path']) ?></span>
                <?php if (!empty($row['route_name'])): ?>
                  <div class="tiny faint"><?= $this->e($row['route_name']) ?></div>
                <?php endif; ?>
              </td>

              <td>
                <span class="chip chip--<?= $statusTone($status) ?>">
                  <span class="chip__dot"></span><?= $status ?>
                </span>
              </td>

              <td class="text-right">
                <span class="chip chip--<?= $durationTone($duration) ?>"><?= $this->number($duration) ?> ms</span>
              </td>

              <td class="text-right mono small"><?= $this->number((int) $row['query_count']) ?></td>

              <td class="text-right mono small"><?= $this->number((int) $row['memory_kb']) ?> KB</td>

              <td>
                <?php if (!empty($row['user_name'])): ?>
                  <div class="cell-user">
                    <div>
                      <div class="cell-user__name"><?= $this->e($row['user_name']) ?></div>
                      <div class="cell-user__meta mono"><?= $this->e($row['ip_address'] ?? '—') ?></div>
                    </div>
                  </div>
                <?php else: ?>
                  <span class="small muted">Guest</span>
                  <div class="tiny faint mono"><?= $this->e($row['ip_address'] ?? '—') ?></div>
                <?php endif; ?>
              </td>

              <td class="actions">
                <button type="button" class="btn btn--ghost btn--icon btn--sm"
                        data-toggle-detail="log-detail-<?= $id ?>"
                        aria-expanded="false" aria-controls="log-detail-<?= $id ?>"
                        aria-label="Show request detail">
                  <?= $this->icon('eye', 'ic ic--sm') ?>
                </button>
              </td>
            </tr>

            <tr class="dev-detail" id="log-detail-<?= $id ?>" hidden>
              <td colspan="8">
                <div class="grid grid--2">
                  <div>
                    <p class="label mb-2">Request</p>
                    <dl class="dev-kv">
                      <dt>Request id</dt><dd class="mono tiny"><?= $this->e($row['request_id'] ?? '—') ?></dd>
                      <dt>Route</dt><dd class="mono tiny"><?= $this->e($row['route_name'] ?? '—') ?></dd>
                      <dt>Client</dt>
                      <dd class="tiny">
                        <?= $this->e($row['platform'] ?? 'unknown') ?>
                        <?= !empty($row['app_version']) ? ' · v' . $this->e($row['app_version']) : '' ?>
                      </dd>
                      <dt>User</dt>
                      <dd class="tiny">
                        <?= !empty($row['user_name']) ? $this->e($row['user_name']) . ' (#' . (int) $row['user_id'] . ')' : 'Not signed in' ?>
                      </dd>
                      <dt>Agent</dt><dd class="tiny muted"><?= $this->e($row['user_agent'] ?? '—') ?></dd>
                    </dl>
                  </div>

                  <div>
                    <p class="label mb-2">Body <span class="tiny faint">secrets removed before storage</span></p>
                    <?php if (!empty($row['request_body'])): ?>
                      <pre class="dev-code"><?= $this->e($row['request_body']) ?></pre>
                    <?php elseif ($status < 400): ?>
                      <p class="small muted">Bodies are kept only for requests that failed, so this one was not stored.</p>
                    <?php else: ?>
                      <p class="small muted">No body was sent with this request.</p>
                    <?php endif; ?>

                    <?php if (!empty($row['error_message'])): ?>
                      <p class="label mb-2 mt-3">Error</p>
                      <pre class="dev-code dev-code--error"><?= $this->e($row['error_message']) ?></pre>
                    <?php endif; ?>
                  </div>
                </div>
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
<style>
  .dev-method {
    display: inline-block; margin-right: 6px; padding: 1px 6px;
    border-radius: var(--r-sm); font-size: 10.5px; font-weight: 700;
    letter-spacing: .04em; background: var(--surface-variant); color: var(--on-surface-variant);
  }
  .dev-method--get    { background: color-mix(in srgb, var(--secondary) 14%, transparent); color: var(--secondary); }
  .dev-method--post   { background: color-mix(in srgb, var(--tertiary) 16%, transparent); color: var(--tertiary); }
  .dev-method--put,
  .dev-method--patch  { background: color-mix(in srgb, var(--warning) 16%, transparent); color: var(--warning); }
  .dev-method--delete { background: color-mix(in srgb, var(--error) 14%, transparent); color: var(--error); }

  .dev-detail > td { background: var(--surface-variant); padding: 18px 16px; }

  .dev-kv { display: grid; grid-template-columns: 90px 1fr; gap: 6px 12px; margin: 0; }
  .dev-kv dt { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--outline); font-weight: 700; }
  .dev-kv dd { margin: 0; word-break: break-word; }

  .dev-code {
    margin: 0; padding: 12px; border-radius: var(--r);
    background: var(--surface); border: 1px solid var(--outline-variant);
    font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
    font-size: 12px; line-height: 1.55; white-space: pre-wrap; word-break: break-word;
    max-height: 260px; overflow: auto;
  }
  .dev-code--error { border-color: var(--error); color: var(--error); }
</style>
<script>
(function () {
  document.querySelectorAll('[data-toggle-detail]').forEach(function (button) {
    button.addEventListener('click', function () {
      const row = document.getElementById(button.dataset.toggleDetail);
      if (!row) return;

      const open = row.hasAttribute('hidden');
      row.toggleAttribute('hidden', !open);
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
})();
</script>
<?php $this->stop(); ?>

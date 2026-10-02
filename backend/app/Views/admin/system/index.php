<?php

declare(strict_types=1);

/**
 * System health, scheduled jobs, backups and logs.
 *
 * @var \Zuste\Core\View $this
 * @var string $tab
 * @var array<string,mixed> $php
 * @var array<string,mixed> $database
 * @var array<string,mixed> $storage
 * @var list<array<string,mixed>> $jobs
 * @var list<array<string,mixed>> $backups
 * @var array<string,array<string,int>> $queues
 * @var list<array<string,mixed>> $logs
 * @var array<string,mixed> $logPagination
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$formatBytes = static function (float $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;

    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return round($bytes, 1) . ' ' . $units[$index];
};

$levelTone = static fn (string $level): string => match ($level) {
    'critical', 'alert', 'emergency', 'error' => 'error',
    'warning'                                 => 'warning',
    'notice', 'info'                          => 'info',
    default                                   => 'neutral',
};

?>

<div class="chip-row mb-5">
  <?php foreach ([
      'overview' => 'Overview', 'jobs' => 'Scheduled jobs', 'backups' => 'Backups',
      'database' => 'Database', 'logs' => 'Error log',
  ] as $value => $label): ?>
    <a href="/admin/system?tab=<?= $this->e($value) ?>"
       class="chip-filter<?= $tab === $value ? ' chip-filter--active' : '' ?>"><?= $this->e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'overview'): ?>

  <section class="grid grid--2 mb-6">
    <div class="card">
      <div class="card__head"><h3 class="h3">Runtime</h3></div>
      <div class="card__body col gap-3">
        <?php foreach ([
            'PHP version'        => $php['version'],
            'Memory limit'       => $php['memory_limit'],
            'Max execution time' => $php['max_execution_time'] . 's',
            'Upload max size'    => $php['upload_max_filesize'],
            'POST max size'      => $php['post_max_size'],
        ] as $label => $value): ?>
          <div class="row row--between">
            <span class="small muted"><?= $this->e($label) ?></span>
            <span class="small mono" style="font-weight:600"><?= $this->e($value) ?></span>
          </div>
        <?php endforeach; ?>

        <hr class="divider" style="margin:6px 0">

        <div>
          <div class="label mb-2">Extensions loaded</div>
          <div class="chip-row">
            <?php foreach ($php['extensions'] as $extension): ?>
              <span class="chip chip--success"><?= $this->e($extension) ?></span>
            <?php endforeach; ?>
            <?php foreach ($php['missing'] as $extension): ?>
              <span class="chip chip--error"><?= $this->e($extension) ?> missing</span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h3 class="h3">Storage</h3></div>
      <div class="card__body col gap-3">
        <div class="row row--between">
          <span class="small muted">Uploads</span>
          <span class="small mono" style="font-weight:600"><?= $formatBytes((float) $storage['upload_size']) ?></span>
        </div>
        <div class="row row--between">
          <span class="small muted">Logs</span>
          <span class="small mono" style="font-weight:600"><?= $formatBytes((float) $storage['log_size']) ?></span>
        </div>
        <div class="row row--between">
          <span class="small muted">Backups</span>
          <span class="small mono" style="font-weight:600"><?= $formatBytes((float) $storage['backup_size']) ?></span>
        </div>
        <div class="row row--between">
          <span class="small muted">Free disk space</span>
          <span class="small mono" style="font-weight:600"><?= $formatBytes((float) $storage['free_space']) ?></span>
        </div>

        <hr class="divider" style="margin:6px 0">

        <div class="row row--between">
          <span class="small muted">Uploads writable</span>
          <span class="chip chip--<?= $storage['upload_writable'] ? 'success' : 'error' ?>">
            <?= $storage['upload_writable'] ? 'Yes' : 'No' ?>
          </span>
        </div>
        <div class="row row--between">
          <span class="small muted">Logs writable</span>
          <span class="chip chip--<?= $storage['log_writable'] ? 'success' : 'error' ?>">
            <?= $storage['log_writable'] ? 'Yes' : 'No' ?>
          </span>
        </div>
      </div>
    </div>
  </section>

  <section class="grid grid--3">
    <?php foreach (['email' => 'Email queue', 'sms' => 'SMS queue', 'push' => 'Push queue'] as $key => $label): ?>
      <div class="card">
        <div class="card__head"><h3 class="h3"><?= $this->e($label) ?></h3></div>
        <div class="card__body col gap-3">
          <div class="row row--between">
            <span class="small muted">Pending</span>
            <span class="chip chip--<?= ($queues[$key]['pending'] ?? 0) > 50 ? 'warning' : 'neutral' ?>">
              <?= $this->number($queues[$key]['pending'] ?? 0) ?>
            </span>
          </div>
          <div class="row row--between">
            <span class="small muted">Sent</span>
            <span class="chip chip--success"><?= $this->number($queues[$key]['sent'] ?? 0) ?></span>
          </div>
          <div class="row row--between">
            <span class="small muted">Failed</span>
            <span class="chip chip--<?= ($queues[$key]['failed'] ?? 0) > 0 ? 'error' : 'neutral' ?>">
              <?= $this->number($queues[$key]['failed'] ?? 0) ?>
            </span>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

<?php elseif ($tab === 'jobs'): ?>

  <div class="card mb-5" style="border-color:var(--secondary);background:color-mix(in srgb, var(--secondary) 6%, transparent)">
    <div class="card__body row gap-3" style="padding:14px 18px">
      <span class="text-secondary"><?= $this->icon('info') ?></span>
      <p class="small">
        Jobs run from cron on the server. Register them once with the crontab lines in
        <span class="mono">docs/DEPLOYMENT.md</span>. Running one manually here is safe — each job is
        idempotent and file-locked against overlapping runs.
      </p>
    </div>
  </div>

  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Job</th><th>Schedule</th><th>Last run</th><th>Status</th><th class="text-right">Duration</th>
              <th class="text-right">Runs</th><th class="text-right">Failures</th><th class="text-right">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($jobs as $job): ?>
            <tr>
              <td>
                <div class="small mono" style="font-weight:700"><?= $this->e($job['job_key']) ?></div>
                <div class="tiny muted"><?= $this->e($job['description'] ?? '') ?></div>
              </td>
              <td class="mono tiny faint"><?= $this->e($job['schedule_hint']) ?></td>
              <td class="small muted" style="white-space:nowrap"><?= $job['last_run_at'] ? $this->timeAgo($job['last_run_at']) : 'Never' ?></td>
              <td>
                <span class="chip chip--<?= match ((string) $job['last_status']) {
                    'success' => 'success', 'failed' => 'error', 'running' => 'warning', default => 'neutral',
                } ?>"><?= $this->e(ucfirst((string) $job['last_status'])) ?></span>
                <?php if (!$job['is_enabled']): ?>
                  <div class="tiny faint mt-1">Disabled</div>
                <?php endif; ?>
              </td>
              <td class="text-right mono small"><?= $job['last_duration_ms'] !== null ? (int) $job['last_duration_ms'] . 'ms' : '—' ?></td>
              <td class="text-right mono small"><?= $this->number($job['run_count']) ?></td>
              <td class="text-right mono small <?= (int) $job['failure_count'] > 0 ? 'text-error' : 'faint' ?>">
                <?= $this->number($job['failure_count']) ?>
              </td>
              <td class="actions">
                <?php if ($this->can('system.jobs')): ?>
                  <div class="row gap-2 row--end">
                    <form method="post" action="/admin/system/jobs/<?= $this->e($job['job_key']) ?>/run" style="display:inline">
                      <?= $this->csrf() ?>
                      <button type="submit" class="btn btn--outline btn--sm">Run now</button>
                    </form>
                    <form method="post" action="/admin/system/jobs/<?= $this->e($job['job_key']) ?>/toggle" style="display:inline">
                      <?= $this->csrf() ?>
                      <button type="submit" class="btn btn--ghost btn--sm">
                        <?= $job['is_enabled'] ? 'Disable' : 'Enable' ?>
                      </button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($tab === 'backups'): ?>

  <div class="row row--between row--wrap gap-3 mb-5">
    <p class="small muted" style="max-width:560px">
      Backups are written to <span class="mono"><?= $this->e((string) \Zuste\Core\Config::get('storage.backup_path')) ?></span>,
      compressed, and pruned after the retention window. The directory is denied over HTTP.
    </p>

    <?php if ($this->can('system.backup')): ?>
      <form method="post" action="/admin/system/backup">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--primary">
          <?= $this->icon('database', 'ic ic--sm') ?> Create backup now
        </button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <?php if ($backups === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('database', 'ic ic--lg') ?></div>
        <p class="empty__title">No backups yet</p>
        <p class="empty__text">Create one now, or let the nightly <span class="mono">backup:database</span> job handle it.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>File</th><th>Type</th><th class="text-right">Size</th><th>Trigger</th>
                <th>Status</th><th>Created by</th><th>When</th><th class="text-right">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($backups as $backup): ?>
              <tr>
                <td class="mono small truncate" style="max-width:260px"><?= $this->e($backup['file_name']) ?></td>
                <td class="small muted"><?= $this->e(ucfirst((string) $backup['type'])) ?></td>
                <td class="text-right mono small"><?= $formatBytes((float) $backup['size_bytes']) ?></td>
                <td><span class="chip chip--neutral"><?= $this->e($backup['trigger']) ?></span></td>
                <td>
                  <span class="chip chip--<?= $backup['status'] === 'completed' ? 'success' : ($backup['status'] === 'failed' ? 'error' : 'warning') ?>">
                    <?= $this->e(ucfirst((string) $backup['status'])) ?>
                  </span>
                  <?php if (!empty($backup['error'])): ?>
                    <div class="tiny text-error mt-1 clamp-2" style="max-width:180px"><?= $this->e($backup['error']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="small muted"><?= $this->e($backup['author'] ?? 'Cron') ?></td>
                <td class="small muted" style="white-space:nowrap"><?= $this->dateTime($backup['created_at']) ?></td>
                <td class="actions">
                  <?php if ($backup['status'] === 'completed' && $this->can('system.backup')): ?>
                    <div class="row gap-2 row--end">
                      <a href="/admin/system/backup/<?= (int) $backup['id'] ?>/download" class="btn btn--ghost btn--sm">
                        <?= $this->icon('download', 'ic ic--sm') ?> Download
                      </a>

                      <?php if ($this->can('system.restore')): ?>
                        <button type="button" class="btn btn--ghost btn--sm text-error"
                                onclick="restoreBackup(<?= (int) $backup['id'] ?>, '<?= $this->e($backup['file_name']) ?>')">
                          Restore
                        </button>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'database'): ?>

  <div class="row row--between row--wrap gap-3 mb-5">
    <div>
      <p class="small muted">
        <span class="mono"><?= $this->e($database['name']) ?></span> ·
        <?= $this->e($database['version']) ?> ·
        <?= count($database['tables']) ?> tables
      </p>
    </div>

    <?php if ($this->can('system.optimize')): ?>
      <form method="post" action="/admin/system/optimize">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--outline"
                data-confirm="OPTIMIZE and ANALYZE run across every table. On a large database this can take a while and briefly lock tables."
                data-confirm-title="Optimise the database?" data-confirm-label="Optimise" data-confirm-tone="warning">
          <?= $this->icon('refresh', 'ic ic--sm') ?> Optimise tables
        </button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Table</th><th class="text-right">Rows</th><th class="text-right">Size</th>
              <th class="text-right">Reclaimable</th><th>Engine</th></tr>
        </thead>
        <tbody>
          <?php foreach ($database['tables'] as $table): ?>
            <tr>
              <td class="mono small"><?= $this->e($table['table_name']) ?></td>
              <td class="text-right mono"><?= $this->number((float) $table['row_count']) ?></td>
              <td class="text-right mono"><?= $this->e($table['size_mb']) ?> MB</td>
              <td class="text-right mono small <?= (float) $table['free_mb'] > 1 ? 'text-warning' : 'faint' ?>">
                <?= $this->e($table['free_mb']) ?> MB
              </td>
              <td class="small muted"><?= $this->e($table['engine']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($tab === 'logs'): ?>

  <div class="row row--between row--wrap gap-3 mb-5">
    <div class="chip-row">
      <?php foreach (['' => 'All', 'critical' => 'Critical', 'error' => 'Error', 'warning' => 'Warning', 'info' => 'Info'] as $value => $label): ?>
        <a href="/admin/system?tab=logs&level=<?= $this->e($value) ?>"
           class="chip-filter<?= (string) ($filters['level'] ?? '') === (string) $value ? ' chip-filter--active' : '' ?>">
          <?= $this->e($label) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="post" action="/admin/system/logs/clear">
      <?= $this->csrf() ?>
      <input type="hidden" name="level" value="<?= $this->e($filters['level'] ?? '') ?>">
      <button type="submit" class="btn btn--ghost btn--sm text-error"
              data-confirm="Cleared log entries cannot be recovered."
              data-confirm-title="Clear these logs?" data-confirm-label="Clear">
        <?= $this->icon('trash', 'ic ic--sm') ?> Clear
      </button>
    </form>
  </div>

  <div class="card">
    <?php if (($logs ?? []) === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
        <p class="empty__title">Nothing logged</p>
        <p class="empty__text">No entries at this level. That is a good sign.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:90px">Level</th><th>Channel</th><th>Message</th><th>Context</th><th>When</th></tr></thead>
          <tbody>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td><span class="chip chip--<?= $levelTone((string) $log['level']) ?>"><?= $this->e($log['level']) ?></span></td>
                <td class="small muted mono"><?= $this->e($log['channel']) ?></td>
                <td class="small" style="max-width:420px"><?= $this->e(\Zuste\Helpers\Str::limit((string) $log['message'], 200)) ?></td>
                <td style="max-width:200px">
                  <?php if (!empty($log['context'])): ?>
                    <details>
                      <summary class="tiny text-secondary" style="cursor:pointer;font-weight:600">Context</summary>
                      <pre class="mono tiny" style="margin-top:6px;padding:8px;background:var(--surface-low);
                                  border-radius:var(--r);overflow-x:auto;max-width:200px;white-space:pre-wrap"><?=
                        $this->e((string) json_encode(json_decode((string) $log['context'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                      ?></pre>
                    </details>
                  <?php else: ?>
                    <span class="tiny faint">—</span>
                  <?php endif; ?>
                </td>
                <td class="small muted" style="white-space:nowrap"><?= $this->dateTime($log['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php $this->partial('admin.partials.pagination', ['pagination' => $logPagination]); ?>
    <?php endif; ?>
  </div>

<?php endif; ?>

<form id="restore-form" method="post" style="display:none">
  <?= $this->csrf() ?>
  <input type="hidden" name="confirmation" id="restore-confirmation">
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
function restoreBackup(id, fileName) {
  const answer = window.prompt(
    'Restoring "' + fileName + '" REPLACES the entire current database.\n\n' +
    'A safety snapshot is taken first, but this is destructive.\n\n' +
    'Type RESTORE to confirm:'
  );
  if (answer === null) return;

  if (answer !== 'RESTORE') {
    Admin.toast('Restore cancelled — the confirmation did not match.', 'warning');
    return;
  }

  const form = document.getElementById('restore-form');
  form.action = '/admin/system/backup/' + id + '/restore';
  document.getElementById('restore-confirmation').value = answer;
  form.submit();
}
</script>
<?php $this->stop(); ?>

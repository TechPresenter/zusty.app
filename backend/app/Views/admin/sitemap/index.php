<?php

declare(strict_types=1);

/**
 * Sitemap generator.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,array<string,mixed>> $sources
 * @var array<string,mixed>|null $status
 * @var array<string,mixed>|null $lastRun
 * @var list<array<string,mixed>> $preview
 * @var string $robotsBody
 * @var list<string> $problems
 * @var list<string> $warnings
 * @var int $pendingTotal
 * @var list<string> $frequencies
 * @var string $staticPaths
 * @var string $robotsPaths
 * @var string $homePriority
 * @var string $homeFreq
 * @var int $maxPerFile
 */

$this->extend('admin.layouts.app');
$this->start('content');

$canGenerate = $this->can('cms.pages');

$bytes = static function (int $size): string {
    if ($size <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;
    $value = (float) $size;

    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return ($index === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$index];
};

$sourceTone = [
    'static'          => 'neutral',
    'pages'           => 'info',
    'blogs'           => 'success',
    'blog_categories' => 'neutral',
    'categories'      => 'warning',
    'creators'        => 'info',
];

// The delta between what is on disk right now — counted in the files themselves,
// not taken from the last-run snapshot — and what the database would produce.
$onDiskUrls = $status !== null && $status['exists'] ? (int) $status['url_count'] : null;
$drift      = $onDiskUrls === null ? 0 : $pendingTotal - $onDiskUrls;

$fileCount   = $status !== null ? count($status['files']) : 0;
$onDiskBytes = $status !== null ? (int) $status['total_bytes'] : 0;

?>

<?php foreach ($problems as $problem): ?>
  <div class="callout callout--error mb-4">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div><?= $this->e($problem) ?></div>
  </div>
<?php endforeach; ?>

<?php foreach ($warnings as $warning): ?>
  <div class="callout callout--warning mb-4">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div><?= $this->e($warning) ?></div>
  </div>
<?php endforeach; ?>

<?php if ($status !== null && !$status['exists']): ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('info', 'ic ic--sm') ?>
    <div>
      <strong>No sitemap has been published yet.</strong>
      <?= $this->e($this->number($pendingTotal)) ?> URL<?= $pendingTotal === 1 ? '' : 's' ?>
      are ready to write. Search engines will not see them until you generate.
    </div>
  </div>
<?php elseif ($status !== null && $drift !== 0): ?>
  <div class="callout callout--info mb-5">
    <?= $this->icon('refresh', 'ic ic--sm') ?>
    <div>
      <strong>The published sitemap is out of date.</strong>
      It holds <?= $this->number((int) $onDiskUrls) ?> URLs; the database now yields
      <?= $this->number($pendingTotal) ?>
      (<?= $drift > 0 ? '+' : '−' ?><?= $this->number(abs($drift)) ?>). Generate to publish the difference.
    </div>
  </div>
<?php endif; ?>

<!-- ================= Summary ================= -->
<div class="wgrid mb-5">

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:var(--primary)">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('external') ?></div></div>
      <div class="kpi__value"><?= $this->number($pendingTotal) ?></div>
      <div class="kpi__label">URLs ready to publish</div>
      <div class="kpi__foot">From <?= count(array_filter($sources, static fn (array $s): bool => $s['enabled'])) ?> of <?= count($sources) ?> sources</div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:var(--secondary)">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('file') ?></div></div>
      <div class="kpi__value"><?= $onDiskUrls === null ? '—' : $this->number($onDiskUrls) ?></div>
      <div class="kpi__label">URLs on disk</div>
      <div class="kpi__foot"><?= $fileCount === 0 ? 'No file written' : $fileCount . ' file' . ($fileCount === 1 ? '' : 's') . ', ' . $this->e($bytes($onDiskBytes)) ?></div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:var(--tertiary)">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('refresh') ?></div></div>
      <div class="kpi__value" style="font-size:22px">
        <?= $status !== null && $status['modified_at'] !== null ? $this->e($this->timeAgo((string) $status['modified_at'])) : 'Never' ?>
      </div>
      <div class="kpi__label">Last generated</div>
      <div class="kpi__foot">
        <?= $status !== null && $status['modified_at'] !== null ? $this->e($this->dateTime((string) $status['modified_at'])) : 'Nothing published yet' ?>
      </div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:<?= $status !== null && $status['robots']['has_sitemap_line'] ? 'var(--tertiary)' : 'var(--warning)' ?>">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('shield') ?></div></div>
      <div class="kpi__value" style="font-size:22px">
        <?= $status !== null && $status['robots']['exists'] ? 'Present' : 'Missing' ?>
      </div>
      <div class="kpi__label">robots.txt</div>
      <div class="kpi__foot">
        <?php if ($status !== null && $status['robots']['exists']): ?>
          <?= $status['robots']['has_sitemap_line'] ? 'Sitemap line present' : 'No Sitemap line' ?>
        <?php else: ?>
          Not written yet
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="grid grid--sidebar">

  <!-- ================= Sources & tuning ================= -->
  <div class="card">
    <div class="card__head">
      <div>
        <h2 class="h3">Sources</h2>
        <p class="muted small mt-1">Which URLs go into the sitemap, and how each type is weighted.</p>
      </div>
    </div>

    <form method="post" action="/admin/sitemap/settings">
      <?= $this->csrf() ?>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Source</th>
              <th>URL shape</th>
              <th class="text-right">URLs</th>
              <th>Include</th>
              <th>Change frequency</th>
              <th>Priority</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sources as $key => $source): ?>
              <tr>
                <td>
                  <span class="chip chip--<?= $this->e($sourceTone[$key] ?? 'neutral') ?>"><?= $this->e($source['label']) ?></span>
                </td>
                <td><code class="mono tiny"><?= $this->e($source['pattern']) ?></code></td>
                <td class="text-right mono small"><?= $this->number((int) $source['count']) ?></td>
                <td>
                  <label class="switch">
                    <input type="checkbox" name="include[<?= $this->e($key) ?>]" value="1"
                           <?= $source['enabled'] ? 'checked' : '' ?><?= $canGenerate ? '' : ' disabled' ?>
                           aria-label="Include <?= $this->e($source['label']) ?>">
                    <span class="switch__track"></span>
                  </label>
                </td>
                <td>
                  <select class="select" name="changefreq[<?= $this->e($key) ?>]" style="min-width:130px"
                          aria-label="Change frequency for <?= $this->e($source['label']) ?>"<?= $canGenerate ? '' : ' disabled' ?>>
                    <?php foreach ($frequencies as $frequency): ?>
                      <option value="<?= $this->e($frequency) ?>" <?= $source['changefreq'] === $frequency ? 'selected' : '' ?>>
                        <?= $this->e(ucfirst($frequency)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <select class="select" name="priority[<?= $this->e($key) ?>]" style="min-width:88px"
                          aria-label="Priority for <?= $this->e($source['label']) ?>"<?= $canGenerate ? '' : ' disabled' ?>>
                    <?php for ($step = 10; $step >= 0; $step--): ?>
                      <?php $weight = number_format($step / 10, 1, '.', ''); ?>
                      <option value="<?= $this->e($weight) ?>" <?= $source['priority'] === $weight ? 'selected' : '' ?>>
                        <?= $this->e($weight) ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>

            <tr>
              <td><span class="chip chip--success">Home page</span></td>
              <td><code class="mono tiny">/</code></td>
              <td class="text-right muted tiny">part of<br>static</td>
              <td class="muted tiny">Always,<br>if listed</td>
              <td>
                <select class="select" name="changefreq[home]" style="min-width:130px"
                        aria-label="Change frequency for the home page"<?= $canGenerate ? '' : ' disabled' ?>>
                  <?php foreach ($frequencies as $frequency): ?>
                    <option value="<?= $this->e($frequency) ?>" <?= $homeFreq === $frequency ? 'selected' : '' ?>>
                      <?= $this->e(ucfirst($frequency)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select class="select" name="priority[home]" style="min-width:88px"
                        aria-label="Priority for the home page"<?= $canGenerate ? '' : ' disabled' ?>>
                  <?php for ($step = 10; $step >= 0; $step--): ?>
                    <?php $weight = number_format($step / 10, 1, '.', ''); ?>
                    <option value="<?= $this->e($weight) ?>" <?= $homePriority === $weight ? 'selected' : '' ?>>
                      <?= $this->e($weight) ?>
                    </option>
                  <?php endfor; ?>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card__body">
        <div class="grid grid--2">
          <div class="field">
            <label class="field__label" for="static_paths">Static paths</label>
            <textarea class="textarea mono" id="static_paths" name="static_paths" rows="5"
                      placeholder="/&#10;/about&#10;/contact"<?= $canGenerate ? '' : ' disabled' ?>><?= $this->e($staticPaths) ?></textarea>
            <p class="field__hint">
              One site-relative path per line, for pages with no database row of their own.
              Absolute URLs are ignored so a foreign host can never reach the published file.
            </p>
          </div>

          <div class="field">
            <label class="field__label" for="robots_disallow">robots.txt disallow</label>
            <textarea class="textarea mono" id="robots_disallow" name="robots_disallow" rows="5"
                      placeholder="/admin&#10;/api"<?= $canGenerate ? '' : ' disabled' ?>><?= $this->e($robotsPaths) ?></textarea>
            <p class="field__hint">Paths crawlers are asked to skip. One per line.</p>
          </div>
        </div>

        <?php if ($canGenerate): ?>
          <div class="row row--end gap-2 mt-3">
            <button type="submit" class="btn btn--outline">
              <?= $this->icon('check', 'ic ic--sm') ?> Save settings
            </button>
          </div>
        <?php else: ?>
          <p class="tiny muted mt-3">You need the cms.pages permission to change these.</p>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- ================= Published files ================= -->
  <div class="card">
    <div class="card__head"><h2 class="h3">Published</h2></div>

    <div class="card__body">
      <?php if ($status !== null): ?>
        <div class="field">
          <span class="field__label">Public sitemap URL</span>
          <div class="row gap-2">
            <input class="input mono" value="<?= $this->e((string) $status['sitemap_url']) ?>" readonly
                   aria-label="Public sitemap URL" style="min-width:0">
            <button type="button" class="btn btn--outline btn--icon" aria-label="Copy sitemap URL" title="Copy"
                    data-copy="<?= $this->e((string) $status['sitemap_url']) ?>">
              <?= $this->icon('copy', 'ic ic--sm') ?>
            </button>
            <a class="btn btn--ghost btn--icon" href="<?= $this->e((string) $status['sitemap_url']) ?>"
               target="_blank" rel="noopener" aria-label="Open sitemap" title="Open">
              <?= $this->icon('external', 'ic ic--sm') ?>
            </a>
          </div>
          <p class="field__hint">
            Built from the canonical base URL in Settings → SEO, never from the request host.
          </p>
        </div>

        <?php if ($status['files'] === []): ?>
          <div class="empty">
            <div class="empty__art"><?= $this->icon('file', 'ic ic--lg') ?></div>
            <p class="empty__title">Nothing on disk</p>
            <p class="empty__text">Generate to write <?= $this->e((string) $status['directory']) ?>/sitemap.xml.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap mt-3">
            <table class="table" style="min-width:0">
              <thead>
                <tr><th>File</th><th class="text-right">URLs</th><th class="text-right">Size</th><th>Written</th></tr>
              </thead>
              <tbody>
                <?php foreach ($status['files'] as $file): ?>
                  <tr>
                    <td>
                      <a class="mono small" href="<?= $this->e((string) $file['url']) ?>" target="_blank" rel="noopener">
                        <?= $this->e((string) $file['name']) ?>
                      </a>
                    </td>
                    <td class="text-right mono small"><?= $this->number((int) $file['urls']) ?></td>
                    <td class="text-right mono small"><?= $this->e($bytes((int) $file['bytes'])) ?></td>
                    <td class="tiny muted" style="white-space:nowrap"><?= $this->e($this->timeAgo((string) $file['modified_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <p class="tiny muted mt-3">
          Written to <code class="mono"><?= $this->e((string) $status['directory']) ?></code>.
          Above <?= $this->number($maxPerFile) ?> URLs, sitemap.xml becomes an index and the URLs
          are split across sitemap-1.xml, sitemap-2.xml and so on.
        </p>
      <?php else: ?>
        <div class="empty">
          <div class="empty__art"><?= $this->icon('alert', 'ic ic--lg') ?></div>
          <p class="empty__title">Cannot reach the web root</p>
          <p class="empty__text">Fix the problem above, then generate.</p>
        </div>
      <?php endif; ?>

      <?php if ($canGenerate && $status !== null): ?>
        <form method="post" action="/admin/sitemap/generate" class="mt-4">
          <?= $this->csrf() ?>
          <button type="submit" class="btn btn--primary" style="width:100%"
                  <?= $pendingTotal === 0 ? 'disabled' : '' ?>>
            <?= $this->icon('refresh', 'ic ic--sm') ?> Generate now
          </button>
        </form>

        <?php if ($pendingTotal === 0): ?>
          <p class="tiny text-warning mt-2">
            Every source is switched off or empty. A sitemap with no URLs is not a valid document,
            so there is nothing to write.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if ($lastRun !== null): ?>
      <div class="card__head" style="border-top:1px solid var(--outline-variant)">
        <h3 class="h3">Last run</h3>
      </div>
      <div class="card__body">
        <p class="small muted mb-2">
          <?= $this->e($this->dateTime((string) ($lastRun['generated_at'] ?? ''))) ?> —
          <?= $this->number((int) ($lastRun['total'] ?? 0)) ?> URLs
          <?= !empty($lastRun['is_index']) ? 'in a sitemap index' : 'in one file' ?>.
        </p>
        <div class="chip-row">
          <?php foreach ($sources as $key => $source): ?>
            <?php
            $written = (int) ($lastRun['counts'][$key] ?? 0);
            $delta   = $source['enabled'] ? (int) $source['count'] - $written : -$written;
            ?>
            <span class="chip chip--<?= $delta === 0 ? 'neutral' : 'warning' ?>">
              <?= $this->e($source['label']) ?>: <?= $this->number($written) ?>
              <?php if ($delta !== 0): ?>
                (<?= $delta > 0 ? '+' : '−' ?><?= $this->number(abs($delta)) ?> pending)
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ================= robots.txt ================= -->
<div class="card mt-5">
  <div class="card__head">
    <div>
      <h2 class="h3">robots.txt</h2>
      <p class="muted small mt-1">Written separately, because it replaces anything already in the web root.</p>
    </div>
    <?php if ($canGenerate && $status !== null): ?>
      <form method="post" action="/admin/sitemap/robots">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--outline btn--sm"
                data-confirm="This replaces the current robots.txt in the web root."
                data-confirm-title="Write robots.txt?" data-confirm-label="Write" data-confirm-tone="warning">
          <?= $this->icon('upload', 'ic ic--sm') ?> Write robots.txt
        </button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card__body">
    <?php if ($robotsBody === ''): ?>
      <p class="small muted">The preview needs a valid base URL.</p>
    <?php else: ?>
      <pre class="mono tiny sitemap-pre"><?= $this->e($robotsBody) ?></pre>
    <?php endif; ?>

    <?php if ($status !== null && $status['robots']['exists'] && !$status['robots']['in_sync']): ?>
      <p class="tiny text-warning mt-2">
        The directives in the file on disk differ from this preview. Write it to bring them back in step.
      </p>
    <?php endif; ?>
  </div>
</div>

<!-- ================= URL preview ================= -->
<div class="card mt-5">
  <div class="card__head">
    <div>
      <h2 class="h3">URL preview</h2>
      <p class="muted small mt-1">
        The first <?= count($preview) ?> URL<?= count($preview) === 1 ? '' : 's' ?> the next run would write,
        in the order they appear in the file.
      </p>
    </div>
  </div>

  <?php if ($preview === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('search', 'ic ic--lg') ?></div>
      <p class="empty__title">No URLs</p>
      <p class="empty__text">Switch on a source, or add a static path, then reload.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:44px" class="text-right">#</th>
            <th>URL</th>
            <th>Source</th>
            <th>Last modified</th>
            <th>Change frequency</th>
            <th class="text-right">Priority</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview as $index => $entry): ?>
            <tr>
              <td class="text-right tiny faint mono"><?= $index + 1 ?></td>
              <td>
                <a class="mono small truncate" href="<?= $this->e((string) $entry['loc']) ?>" target="_blank" rel="noopener"
                   style="display:block;max-width:520px">
                  <?= $this->e((string) $entry['loc']) ?>
                </a>
              </td>
              <td>
                <span class="chip chip--<?= $this->e($sourceTone[$entry['source']] ?? 'neutral') ?>">
                  <?= $this->e($sources[$entry['source']]['label'] ?? (string) $entry['source']) ?>
                </span>
              </td>
              <td class="small muted" style="white-space:nowrap">
                <?= $entry['lastmod'] === null ? '—' : $this->e($this->dateTime((string) $entry['lastmod'])) ?>
              </td>
              <td class="small"><?= $this->e((string) $entry['changefreq']) ?></td>
              <td class="text-right mono small"><?= $this->e((string) $entry['priority']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<link rel="stylesheet" href="/admin/assets/css/widgets.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<style>
  /* Only class this screen adds: a scrollable, readable code block. */
  .sitemap-pre {
    margin: 0;
    padding: 14px 16px;
    background: var(--surface-variant);
    color: var(--on-surface);
    border: 1px solid var(--outline-variant);
    border-radius: var(--r);
    overflow-x: auto;
    line-height: 1.65;
    white-space: pre;
  }
</style>
<?php $this->stop(); ?>

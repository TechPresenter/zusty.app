<?php

declare(strict_types=1);

/**
 * Cache manager.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $stats
 * @var list<array<string,mixed>> $groups
 * @var list<array<string,mixed>> $largest
 * @var int  $defaultTtl
 * @var bool $cacheEnabled
 */

$this->extend('admin.layouts.app');
$this->start('content');

$bytes = static function (int $value): string {
    if ($value < 1024) {
        return $value . ' B';
    }

    if ($value < 1048576) {
        return round($value / 1024, 1) . ' KB';
    }

    return round($value / 1048576, 2) . ' MB';
};

$expired = (int) ($stats['expired'] ?? 0);
$entries = (int) ($stats['entries'] ?? 0);

?>

<?php if (!$cacheEnabled): ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>Caching is switched off in settings.</strong>
      Existing rows stay readable, but nothing new is being stored.
    </div>
  </div>
<?php endif; ?>

<div class="wgrid mb-5">
  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#0F766E">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('database') ?></div></div>
      <div class="kpi__value"><?= $this->number($entries) ?></div>
      <div class="kpi__label">Entries</div>
      <div class="kpi__foot"><span class="kpi__meta">Default TTL <?= $this->number($defaultTtl) ?>s</span></div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#2563EB">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('chart') ?></div></div>
      <div class="kpi__value"><?= $this->e($bytes((int) ($stats['bytes'] ?? 0))) ?></div>
      <div class="kpi__label">Stored payload</div>
      <div class="kpi__foot">
        <span class="kpi__meta">
          <?= $entries > 0 ? $this->e($bytes((int) round((int) ($stats['bytes'] ?? 0) / $entries))) . ' average' : 'nothing stored' ?>
        </span>
      </div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#006A34">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('trending-up') ?></div></div>
      <div class="kpi__value"><?= $this->number((int) ($stats['hits'] ?? 0)) ?></div>
      <div class="kpi__label">Reads served</div>
      <div class="kpi__foot"><span class="kpi__meta">Driver: <?= $this->e((string) ($stats['driver'] ?? 'database')) ?></span></div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:<?= $expired > 0 ? '#B45309' : '#64748B' ?>">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('trash') ?></div></div>
      <div class="kpi__value"><?= $this->number($expired) ?></div>
      <div class="kpi__label">Expired, not yet removed</div>
      <div class="kpi__foot"><span class="kpi__meta">Cleared by pruning</span></div>
    </div>
  </div>
</div>

<div class="card mb-5">
  <div class="card__head">
    <div>
      <h2 class="h3">Maintenance</h2>
      <p class="muted small mt-1">Expired entries are ignored on read, so pruning is housekeeping rather than correctness.</p>
    </div>
  </div>

  <div class="card__body">
    <div class="row row--wrap gap-3">
      <form method="post" action="/admin/developer/cache/prune">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--outline btn--sm"
                data-confirm="Entries whose lifetime has already passed will be deleted. Nothing still in use is affected."
                data-confirm-title="Prune expired entries?"
                data-confirm-label="Prune" data-confirm-tone="warning">
          <?= $this->icon('trash', 'ic ic--sm') ?> Prune expired (<?= $this->number($expired) ?>)
        </button>
      </form>

      <form method="post" action="/admin/developer/cache/flush">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--danger btn--sm"
                data-confirm="Every cached value will be discarded. The next request for each one recomputes it, which will be slower until the cache refills."
                data-confirm-title="Flush the entire cache?"
                data-confirm-label="Flush everything" data-confirm-tone="danger">
          <?= $this->icon('refresh', 'ic ic--sm') ?> Flush all (<?= $this->number($entries) ?>)
        </button>
      </form>
    </div>
  </div>
</div>

<div class="card mb-5">
  <div class="card__head"><h2 class="h3">Groups</h2></div>

  <?php if ($groups === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('database', 'ic ic--lg') ?></div>
      <p class="empty__title">The cache is empty</p>
      <p class="empty__text">Entries appear as pages and widgets are rendered.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Group</th>
            <th class="text-right">Entries</th>
            <th class="text-right">Size</th>
            <th class="text-right">Hits</th>
            <th class="text-right">Expired</th>
            <th>Oldest</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($groups as $group): ?>
            <?php $name = (string) $group['cache_group']; ?>
            <tr>
              <td><span class="chip chip--info"><?= $this->e($name) ?></span></td>
              <td class="text-right mono small"><?= $this->number((int) $group['entries']) ?></td>
              <td class="text-right mono small"><?= $this->e($bytes((int) $group['bytes'])) ?></td>
              <td class="text-right mono small"><?= $this->number((int) $group['hits']) ?></td>
              <td class="text-right mono small <?= (int) $group['expired'] > 0 ? 'text-warning' : '' ?>">
                <?= $this->number((int) $group['expired']) ?>
              </td>
              <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($group['oldest']) ?></td>
              <td class="actions">
                <form method="post" action="/admin/developer/cache/forget">
                  <?= $this->csrf() ?>
                  <input type="hidden" name="group" value="<?= $this->e($name) ?>">
                  <button type="submit" class="btn btn--ghost btn--sm text-error"
                          data-confirm="Every entry in the &quot;<?= $this->e($name) ?>&quot; group will be discarded."
                          data-confirm-title="Clear this group?"
                          data-confirm-label="Clear group" data-confirm-tone="danger">
                    Clear
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__head">
    <h2 class="h3">Largest entries</h2>
    <span class="small muted">Top 25 by stored size</span>
  </div>

  <?php if ($largest === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('database', 'ic ic--lg') ?></div>
      <p class="empty__title">Nothing cached</p>
      <p class="empty__text">There is nothing to rank yet.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Key</th>
            <th>Group</th>
            <th class="text-right">Size</th>
            <th class="text-right">Hits</th>
            <th>Stored</th>
            <th>Expires</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($largest as $entry): ?>
            <?php
            $expiresAt = $entry['expires_at'];
            $isExpired = $expiresAt !== null && strtotime((string) $expiresAt) !== false && strtotime((string) $expiresAt) <= time();
            ?>
            <tr>
              <td class="mono tiny truncate" style="max-width:340px" title="<?= $this->e($entry['cache_key']) ?>">
                <?= $this->e($entry['cache_key']) ?>
              </td>
              <td><span class="chip chip--neutral"><?= $this->e($entry['cache_group']) ?></span></td>
              <td class="text-right mono small"><?= $this->e($bytes((int) $entry['bytes'])) ?></td>
              <td class="text-right mono small"><?= $this->number((int) $entry['hits']) ?></td>
              <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($entry['created_at']) ?></td>
              <td class="small" style="white-space:nowrap">
                <?php if ($expiresAt === null): ?>
                  <span class="chip chip--neutral">Never</span>
                <?php elseif ($isExpired): ?>
                  <span class="chip chip--warning">Expired</span>
                <?php else: ?>
                  <span class="muted"><?= $this->dateTime($expiresAt) ?></span>
                <?php endif; ?>
              </td>
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
<?php $this->stop(); ?>

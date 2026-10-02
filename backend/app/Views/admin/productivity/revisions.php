<?php

declare(strict_types=1);

/**
 * Revisions — the list of stored versions, and the diff for one of them.
 *
 * One template serves both: `$revision` is null on the list screen and holds
 * the row being inspected on the detail screen.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed>|null $revision
 * @var list<array<string,mixed>> $rows
 * @var array<string,int> $counts
 * @var array<string,array{table:string,label:string,plural:string,title:string,permission:string,url:string,icon:string}> $entities
 * @var string $entityType
 * @var int $entityId
 */

$this->extend('admin.layouts.app');
$this->start('content');

$isDetail = $revision !== null;

/** Values longer than this get a "show all" toggle instead of filling the screen. */
$clampAt = 180;

?>

<?php if (!$isDetail): ?>

  <?php
  $typeOptions = ['' => 'Every entity type'];
  foreach ($entities as $key => $definition) {
      $typeOptions[$key] = $definition['plural'] . (isset($counts[$key]) ? ' (' . $counts[$key] . ')' : '');
  }
  ?>

  <?php if ($counts !== []): ?>
    <div class="card mb-5">
      <div class="card__head">
        <h2 class="h3">By entity</h2>
        <span class="small muted">A version is stored every time one of these is edited.</span>
      </div>
      <div class="card__body">
        <div class="chip-row">
          <?php foreach ($counts as $key => $count): ?>
            <a class="chip-filter<?= $entityType === (string) $key ? ' chip-filter--active' : '' ?>"
               href="<?= $this->queryUrl(['entity_type' => $key, 'entity_id' => null, 'page' => null]) ?>">
              <?= $this->icon($entities[$key]['icon'] ?? 'file', 'ic ic--sm') ?>
              <?= $this->e($entities[$key]['plural'] ?? $key) ?>
              <span class="chip-filter__count"><?= $this->number($count) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <?php $this->partial('admin.partials.toolbar', [
        'filters'           => $filters,
        'searchPlaceholder' => 'Search the change notes or the admin name…',
        'showDateRange'     => true,
        'showExport'        => true,
        'selects'           => [
            ['name' => 'entity_type', 'label' => 'Entity type', 'options' => $typeOptions],
        ],
    ]); ?>

    <?php if ($entityId > 0): ?>
      <div class="card__body" style="padding-top:0">
        <p class="small muted">
          Showing one record only —
          <strong><?= $this->e(\Zuste\Services\RevisionService::labelFor($entityType)) ?> #<?= (int) $entityId ?></strong>.
          <a href="<?= $this->queryUrl(['entity_id' => null]) ?>">Show every record</a>
        </p>
      </div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('database', 'ic ic--lg') ?></div>
        <p class="empty__title">No revisions stored</p>
        <p class="empty__text">
          A version appears here the moment someone edits a page, article, category,
          coupon or any of the other versioned records.
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
              <th>Record</th>
              <th class="text-right">
                <a href="<?= $this->queryUrl(['sort_by' => 'version', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Version</a>
              </th>
              <th>Changed by</th>
              <th>Note</th>
              <th class="text-right">Size</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
              $type       = (string) $row['entity_type'];
              $definition = $entities[$type] ?? null;
              ?>
              <tr>
                <td class="small muted" style="white-space:nowrap">
                  <?= $this->dateTime($row['created_at']) ?>
                  <div class="tiny faint"><?= $this->timeAgo($row['created_at']) ?></div>
                </td>

                <td>
                  <span class="small" style="font-weight:600">
                    <?= $this->e($definition['label'] ?? $type) ?> #<?= (int) $row['entity_id'] ?>
                  </span>
                  <div class="tiny muted">
                    <a href="<?= $this->queryUrl(['entity_type' => $type, 'entity_id' => (int) $row['entity_id'], 'page' => null]) ?>">
                      Only this record
                    </a>
                  </div>
                </td>

                <td class="text-right mono small">v<?= (int) $row['version'] ?></td>

                <td class="small muted"><?= $this->e($row['admin_name'] ?? 'System') ?></td>

                <td class="tiny muted truncate" style="max-width:280px" title="<?= $this->e($row['change_note'] ?? '') ?>">
                  <?= $this->e($row['change_note'] ?? '—') ?>
                </td>

                <td class="text-right tiny faint mono">
                  <?= $this->number((int) ($row['snapshot_bytes'] ?? 0)) ?> ch
                </td>

                <td class="actions">
                  <a href="/admin/revisions/<?= (int) $row['id'] ?>" class="btn btn--outline btn--sm">
                    <?= $this->icon('eye', 'ic ic--sm') ?> Diff
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
    <?php endif; ?>
  </div>

<?php else: ?>

  <?php
  $version      = (int) $revision['version'];
  $isLatest     = $version === (int) $latestVersion;
  $previousText = $previous === null
      ? 'This is the earliest stored version, so every field is listed as new.'
      : 'Compared with version ' . (int) $previous['version'] . ', saved ' . $this->timeAgo($previous['created_at']) . '.';
  ?>

  <div class="row row--between row--wrap gap-3 mb-4">
    <a href="/admin/revisions?entity_type=<?= $this->e($entityType) ?>&amp;entity_id=<?= (int) $entityId ?>" class="btn btn--ghost btn--sm">
      <?= $this->icon('chevron-left', 'ic ic--sm') ?> All versions of this record
    </a>

    <?php if ($canRestore && $sourceExists): ?>
      <form method="post" action="/admin/revisions/<?= (int) $revision['id'] ?>/restore">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn--primary btn--sm"
                data-confirm="Write version <?= $version ?> back over the live record. The version it replaces is stored first, so this is reversible."
                data-confirm-title="Restore this version?"
                data-confirm-label="Restore"
                data-confirm-tone="warning">
          <?= $this->icon('refresh', 'ic ic--sm') ?> Restore this version
        </button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$sourceExists): ?>
    <div class="callout callout--warning mb-4">
      <?= $this->icon('alert', 'ic ic--sm') ?>
      <div>
        <strong>The record this version belongs to is gone.</strong>
        It was deleted, or it is sitting in the recycle bin. Restore it there first and
        this version becomes restorable again.
      </div>
    </div>
  <?php elseif (!$canRestore): ?>
    <div class="callout callout--info mb-4">
      <?= $this->icon('info', 'ic ic--sm') ?>
      <div>
        You can read this history, but restoring needs the
        <code class="mono"><?= $this->e($definition['permission'] ?? '') ?></code> permission.
      </div>
    </div>
  <?php endif; ?>

  <div class="grid grid--sidebar">

    <div class="card">
      <div class="card__head">
        <h2 class="h3">What changed</h2>
        <span class="small muted"><?= count($changes) ?> field<?= count($changes) === 1 ? '' : 's' ?></span>
      </div>

      <?php if ($changes === []): ?>
        <div class="empty">
          <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
          <p class="empty__title">Nothing moved</p>
          <p class="empty__text">Every field in this version matches the one before it.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:190px">Field</th>
                <th>Before</th>
                <th>After</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($changes as $index => $change): ?>
                <tr>
                  <td>
                    <span class="small" style="font-weight:600"><?= $this->e($change['label']) ?></span>
                    <div class="tiny faint mono"><?= $this->e($change['field']) ?></div>
                    <?php if ($change['kind'] !== 'changed'): ?>
                      <span class="chip chip--<?= $change['kind'] === 'added' ? 'success' : 'error' ?> mt-1">
                        <?= $change['kind'] === 'added' ? 'New field' : 'Removed' ?>
                      </span>
                    <?php endif; ?>
                  </td>

                  <?php foreach (['before', 'after'] as $side): ?>
                    <?php
                    $value = (string) $change[$side];
                    $long  = mb_strlen($value) > $clampAt;
                    ?>
                    <td class="rev-cell rev-cell--<?= $side ?>">
                      <?php if ($value === ''): ?>
                        <span class="tiny faint">empty</span>
                      <?php else: ?>
                        <div class="rev-value">
                          <div class="rev-value__text<?= $long ? ' is-clamped' : '' ?>"
                               id="rev-<?= $side ?>-<?= (int) $index ?>"><?= $this->e($value) ?></div>
                          <?php if ($long): ?>
                            <button type="button" class="btn btn--ghost btn--sm"
                                    data-expand="rev-<?= $side ?>-<?= (int) $index ?>">Show all</button>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div>
      <div class="card mb-4">
        <div class="card__head"><h2 class="h3">This version</h2></div>
        <div class="card__body">
          <div class="grid gap-3">
            <div>
              <div class="label">Record</div>
              <div class="small">
                <?= $this->e($definition['label'] ?? $entityType) ?> #<?= (int) $entityId ?>
                <?php if ($entityUrl !== '' && $sourceExists): ?>
                  · <a href="<?= $this->e($entityUrl) ?>">Open it</a>
                <?php endif; ?>
              </div>
            </div>

            <div>
              <div class="label">Version</div>
              <div class="small">
                v<?= $version ?> of <?= (int) $latestVersion ?>
                <?php if ($isLatest): ?>
                  <span class="chip chip--info">Latest stored</span>
                <?php endif; ?>
              </div>
            </div>

            <div>
              <div class="label">Saved</div>
              <div class="small"><?= $this->dateTime($revision['created_at']) ?></div>
              <div class="tiny faint"><?= $this->timeAgo($revision['created_at']) ?></div>
            </div>

            <div>
              <div class="label">By</div>
              <div class="small"><?= $this->e($revision['admin_name'] ?? 'System') ?></div>
            </div>

            <?php if (!empty($revision['change_note'])): ?>
              <div>
                <div class="label">Note</div>
                <div class="small"><?= $this->e($revision['change_note']) ?></div>
              </div>
            <?php endif; ?>

            <div>
              <div class="label">Comparison</div>
              <div class="tiny muted"><?= $this->e($previousText) ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <h2 class="h3">Full snapshot</h2>
          <button type="button" class="btn btn--ghost btn--sm" data-toggle-snapshot>Show</button>
        </div>

        <div class="card__body hidden" data-snapshot>
          <?php if ($snapshot === []): ?>
            <p class="small muted">This snapshot is empty.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table class="table table--compact">
                <tbody>
                  <?php $slot = 0; ?>
                  <?php foreach ($snapshot as $field => $value): ?>
                    <?php
                    $rendered = \Zuste\Services\RevisionService::stringify($value);
                    $long     = mb_strlen($rendered) > $clampAt;
                    $slot++;
                    ?>
                    <tr>
                      <td style="width:170px">
                        <span class="tiny" style="font-weight:600">
                          <?= $this->e(\Zuste\Services\RevisionService::fieldLabel((string) $field)) ?>
                        </span>
                        <div class="tiny faint mono"><?= $this->e((string) $field) ?></div>
                      </td>
                      <td>
                        <?php if ($rendered === ''): ?>
                          <span class="tiny faint">empty</span>
                        <?php else: ?>
                          <div class="rev-value">
                            <div class="rev-value__text<?= $long ? ' is-clamped' : '' ?>"
                                 id="snap-<?= $slot ?>"><?= $this->e($rendered) ?></div>
                            <?php if ($long): ?>
                              <button type="button" class="btn btn--ghost btn--sm" data-expand="snap-<?= $slot ?>">Show all</button>
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
      </div>
    </div>
  </div>

<?php endif; ?>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
  .rev-value__text {
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 13px;
    line-height: 1.5;
  }
  .rev-value__text.is-clamped {
    max-height: 92px;
    overflow: hidden;
    -webkit-mask-image: linear-gradient(180deg, #000 60%, transparent 100%);
            mask-image: linear-gradient(180deg, #000 60%, transparent 100%);
  }
  .rev-cell--before .rev-value__text { color: var(--on-surface-variant); }
  .rev-cell--after  .rev-value__text { color: var(--on-surface); font-weight: 500; }
</style>
<script>
(function () {
  document.querySelectorAll('[data-expand]').forEach((button) => {
    button.addEventListener('click', () => {
      const target = document.getElementById(button.dataset.expand);
      if (!target) return;

      const clamped = target.classList.toggle('is-clamped');
      button.textContent = clamped ? 'Show all' : 'Show less';
    });
  });

  const snapshotToggle = document.querySelector('[data-toggle-snapshot]');
  const snapshotBody   = document.querySelector('[data-snapshot]');

  if (snapshotToggle && snapshotBody) {
    snapshotToggle.addEventListener('click', () => {
      const hidden = snapshotBody.classList.toggle('hidden');
      snapshotToggle.textContent = hidden ? 'Show' : 'Hide';
    });
  }
})();
</script>
<?php $this->stop(); ?>

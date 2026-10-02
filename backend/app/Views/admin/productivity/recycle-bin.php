<?php

declare(strict_types=1);

/**
 * Recycle bin: soft-deleted rows across every table that has a deleted_at column.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,array{label:string,singular:string,icon:string,count:int}> $types
 * @var string $type
 * @var array{table:string,label:string,singular:string,icon:string,title:string,sub:string}|null $definition
 * @var list<array<string,mixed>> $rows
 * @var array<int,array{total:int,tables:array<string,int>}> $impact
 * @var list<int> $kycHeld Binned accounts with a KYC history — never deleted for good.
 * @var array<string,mixed> $pagination
 * @var int $binTotal
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$typeOptions = ['' => 'Choose a type'];
foreach ($types as $key => $summary) {
    $typeOptions[$key] = $summary['label'] . ' (' . $summary['count'] . ')';
}

/** One readable sentence describing the collateral damage of a hard delete. */
$impactSentence = static function (array $entry): string {
    if (($entry['total'] ?? 0) === 0) {
        return '';
    }

    $tables = $entry['tables'];
    arsort($tables);

    $named = [];
    foreach (array_slice($tables, 0, 4, true) as $table => $count) {
        $named[] = $count . ' in ' . str_replace('_', ' ', (string) $table);
    }

    $extra = count($tables) - count($named);

    return implode(', ', $named) . ($extra > 0 ? ' and ' . $extra . ' more table' . ($extra === 1 ? '' : 's') : '');
};

?>

<div class="callout callout--<?= $binTotal === 0 ? 'success' : 'info' ?> mb-5">
  <?= $this->icon($binTotal === 0 ? 'check-circle' : 'info', 'ic ic--sm') ?>
  <div>
    <?php if ($binTotal === 0): ?>
      <strong>The recycle bin is empty.</strong>
      Deleting a record from a list screen moves it here first, so a mistake stays recoverable.
    <?php else: ?>
      <strong><?= $this->number($binTotal) ?> record<?= $binTotal === 1 ? '' : 's' ?> waiting.</strong>
      Restoring puts a record straight back into its module. Deleting it here is final —
      the database removes everything that depends on it at the same time.
    <?php endif; ?>
  </div>
</div>

<?php if ($types === []): ?>

  <div class="card">
    <div class="empty">
      <div class="empty__art"><?= $this->icon('trash', 'ic ic--lg') ?></div>
      <p class="empty__title">Nothing here supports soft deletes</p>
      <p class="empty__text">No table in this database keeps a deleted_at column, so nothing can be recovered.</p>
    </div>
  </div>

<?php else: ?>

  <div class="card mb-5">
    <div class="card__head">
      <h2 class="h3">By type</h2>
      <span class="small muted">Only tables with a deleted_at column appear.</span>
    </div>
    <div class="card__body">
      <div class="chip-row">
        <?php foreach ($types as $key => $summary): ?>
          <a class="chip-filter<?= $type === $key ? ' chip-filter--active' : '' ?>"
             href="<?= $this->queryUrl(['type' => $key, 'page' => null, 'q' => null]) ?>">
            <?= $this->icon($summary['icon'], 'ic ic--sm') ?>
            <?= $this->e($summary['label']) ?>
            <span class="chip-filter__count"><?= $this->number($summary['count']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card mb-5">
    <div class="card__head">
      <h2 class="h3"><?= $definition === null ? 'Deleted records' : $this->e($definition['label']) ?></h2>

      <?php if ($definition !== null && ($types[$type]['count'] ?? 0) > 0): ?>
        <form method="post" action="/admin/recycle-bin/empty" style="display:inline">
          <?= $this->csrf() ?>
          <input type="hidden" name="type" value="<?= $this->e($type) ?>">
          <button type="submit" class="btn btn--danger btn--sm"
                  data-confirm="Permanently delete all <?= (int) $types[$type]['count'] ?> <?= $this->e(strtolower($definition['label'])) ?> in the recycle bin, and every record that depends on them.<?= $type === 'users' ? ' Accounts with a KYC verification history are kept (their identity documents are erased).' : '' ?> This cannot be undone."
                  data-confirm-title="Empty this type?"
                  data-confirm-label="Delete them all"
                  data-confirm-tone="danger">
            <?= $this->icon('trash', 'ic ic--sm') ?> Empty this type
          </button>
        </form>
      <?php endif; ?>
    </div>

    <?php $this->partial('admin.partials.toolbar', [
        'filters'           => $filters,
        'searchPlaceholder' => 'Search the deleted records…',
        'showDateRange'     => true,
        'selects'           => [
            ['name' => 'type', 'label' => 'Type', 'options' => $typeOptions],
        ],
    ]); ?>

    <?php if ($rows === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('trash', 'ic ic--lg') ?></div>
        <p class="empty__title">Nothing to show</p>
        <p class="empty__text">
          <?= $definition === null
              ? 'Pick a type above to see what is recoverable.'
              : 'No deleted ' . $this->e(strtolower($definition['label'])) . ' match the current filters.' ?>
        </p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Record</th>
              <th>
                <a href="<?= $this->queryUrl(['sort_by' => 'deleted_at', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">Deleted</a>
              </th>
              <th>Also removed on a permanent delete</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
              $id       = (int) $row['id'];
              $title    = trim((string) ($row['bin_title'] ?? ''));
              $label    = $title === '' ? $definition['singular'] . ' #' . $id : \Zuste\Helpers\Str::limit($title, 70);
              $sub      = trim((string) ($row['bin_sub'] ?? ''));
              $entry    = $impact[$id] ?? ['total' => 0, 'tables' => []];
              $sentence = $impactSentence($entry);

              $confirm = 'Permanently delete ' . strtolower($definition['singular']) . ' "' . $label . '".'
                  . ($sentence !== '' ? ' This also removes ' . $this->number($entry['total']) . ' related record' . ($entry['total'] === 1 ? '' : 's') . ': ' . $sentence . '.' : '')
                  . ' It cannot be undone.';
              ?>
              <tr>
                <td>
                  <span class="small" style="font-weight:600"><?= $this->e($label) ?></span>
                  <div class="tiny muted truncate" style="max-width:340px">
                    <?= $sub === '' ? '#' . $id : $this->e(\Zuste\Helpers\Str::limit($sub, 90)) ?>
                  </div>
                </td>

                <td class="small muted" style="white-space:nowrap">
                  <?= $this->dateTime($row['deleted_at']) ?>
                  <div class="tiny faint"><?= $this->timeAgo($row['deleted_at']) ?></div>
                </td>

                <td class="tiny muted">
                  <?php if (in_array($id, $kycHeld ?? [], true)): ?>
                    <span class="chip chip--info">KYC history — kept</span>
                    <div class="mt-1">The verification record must outlive the account, so it cannot be deleted for good.</div>
                  <?php elseif ($entry['total'] === 0): ?>
                    <span class="chip chip--neutral">Nothing else</span>
                  <?php else: ?>
                    <span class="chip chip--warning"><?= $this->number($entry['total']) ?> record<?= $entry['total'] === 1 ? '' : 's' ?></span>
                    <div class="mt-1"><?= $this->e($sentence) ?></div>
                  <?php endif; ?>
                </td>

                <td class="actions">
                  <?php // No "view" link: a soft-deleted row is hidden from its own module until it is restored. ?>
                  <form method="post" action="/admin/recycle-bin/restore" style="display:inline">
                    <?= $this->csrf() ?>
                    <input type="hidden" name="type" value="<?= $this->e($type) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn btn--outline btn--sm">
                      <?= $this->icon('refresh', 'ic ic--sm') ?> Restore
                    </button>
                  </form>

                  <?php $kycKept = in_array($id, $kycHeld ?? [], true); ?>
                  <form method="post" action="/admin/recycle-bin/delete" style="display:inline">
                    <?= $this->csrf() ?>
                    <input type="hidden" name="type" value="<?= $this->e($type) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <?php if ($kycKept): ?>
                      <?php // The account stays (its KYC history must); the button only erases its identity documents. ?>
                      <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error"
                              aria-label="Erase identity documents" title="Erase identity documents"
                              data-confirm="<?= $this->e('"' . $label . '" has a KYC verification history, so the account stays in the recycle bin. Its identity documents (files, numbers and names) will be erased. This cannot be undone.') ?>"
                              data-confirm-title="Erase identity documents?"
                              data-confirm-label="Erase documents"
                              data-confirm-tone="danger">
                        <?= $this->icon('trash', 'ic ic--sm') ?>
                      </button>
                    <?php else: ?>
                      <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error"
                              aria-label="Delete permanently"
                              data-confirm="<?= $this->e($confirm) ?>"
                              data-confirm-title="Delete permanently?"
                              data-confirm-label="Delete for good"
                              data-confirm-tone="danger">
                        <?= $this->icon('trash', 'ic ic--sm') ?>
                      </button>
                    <?php endif; ?>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head"><h2 class="h3">Housekeeping</h2></div>
    <div class="card__body">
      <p class="small muted mb-3">
        Clear out anything that has been sitting in the bin long enough to be certain
        nobody wants it back. This runs across every type at once.
      </p>

      <form method="post" action="/admin/recycle-bin/purge" class="row gap-3 row--wrap">
        <?= $this->csrf() ?>

        <div class="field" style="max-width:220px;margin:0">
          <label class="field__label" for="purge_days">Delete records binned over</label>
          <select class="select" id="purge_days" name="days">
            <option value="30">30 days ago</option>
            <option value="60">60 days ago</option>
            <option value="90" selected>90 days ago</option>
            <option value="180">180 days ago</option>
            <option value="365">1 year ago</option>
          </select>
        </div>

        <div class="row" style="align-items:flex-end">
          <button type="submit" class="btn btn--danger"
                  data-confirm="Permanently delete every binned record older than the period you chose, across all types. This cannot be undone."
                  data-confirm-title="Purge old records?"
                  data-confirm-label="Purge"
                  data-confirm-tone="danger">
            <?= $this->icon('trash', 'ic ic--sm') ?> Purge
          </button>
        </div>
      </form>
    </div>
  </div>

<?php endif; ?>

<?php $this->stop(); ?>

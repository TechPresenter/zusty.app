<?php

declare(strict_types=1);

/**
 * Media file grid.
 *
 * Rendered both inside the library shell and on its own by
 * MediaController::grid(), which is what the folder tree, the filters and the
 * uploader swap in without reloading the page. It must therefore stay
 * self-contained: no layout, no shell state, everything it needs passed in.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $filters
 * @var list<array<string,mixed>> $files
 * @var array{total:int,page:int,per_page:int,last_page:int,from:int,to:int} $pagination
 * @var list<array{id:int,label:string,path:string,depth:int}> $folderOptions
 * @var array<string,mixed> $gridState
 */

use Zuste\Controllers\Admin\MediaController;

$canManage = $this->can('cms.media');
$trashed   = ($filters['state'] ?? 'active') === 'trashed';
$total     = (int) ($pagination['total'] ?? 0);
$page      = (int) ($pagination['page'] ?? 1);
$lastPage  = (int) ($pagination['last_page'] ?? 1);

/** Build a library URL that keeps every current filter. */
$link = static function (array $overrides) use ($filters): string {
    $query = [
        'folder'   => (string) ($filters['folder'] ?? ''),
        'sub'      => (string) ($filters['sub'] ?? ''),
        'state'    => (string) ($filters['state'] ?? 'active'),
        'q'        => (string) ($filters['q'] ?? ''),
        'type'     => (string) ($filters['type'] ?? ''),
        'from'     => (string) ($filters['from'] ?? ''),
        'to'       => (string) ($filters['to'] ?? ''),
        'sort_by'  => (string) ($filters['sort_by'] ?? 'created_at'),
        'sort_dir' => (string) ($filters['sort_dir'] ?? 'DESC'),
        'page'     => (string) ($filters['page'] ?? 1),
    ];

    $query = array_merge($query, array_map('strval', $overrides));
    $query = array_filter($query, static fn (string $value): bool => $value !== '');

    // Page 1 and the default sort are implied; keep the URL readable.
    if (($query['page'] ?? '1') === '1') {
        unset($query['page']);
    }

    return '/admin/media' . ($query === [] ? '' : '?' . http_build_query($query));
};

// Window of page numbers with ellipses, matching the shared pagination partial.
$pages  = [];
$window = 2;

for ($i = 1; $i <= $lastPage; $i++) {
    if ($i === 1 || $i === $lastPage || abs($i - $page) <= $window) {
        $pages[] = $i;
    } elseif (end($pages) !== '…') {
        $pages[] = '…';
    }
}

?>
<div class="card" data-mgrid-root>

  <div class="card__head row--wrap gap-2">
    <div class="row gap-3 flex-1">
      <?php if ($canManage && $files !== []): ?>
        <label class="checkbox tiny muted">
          <input type="checkbox" data-mselect-all aria-label="Select every file on this page">
          <span>Select all</span>
        </label>
      <?php endif; ?>

      <span class="small muted">
        <?php if ($total === 0): ?>
          No files
        <?php else: ?>
          Showing <strong><?= $this->number($pagination['from'] ?? 0) ?></strong>–<strong><?= $this->number($pagination['to'] ?? 0) ?></strong>
          of <strong><?= $this->number($total) ?></strong>
          <?= $trashed ? 'trashed' : '' ?> file<?= $total === 1 ? '' : 's' ?>
        <?php endif; ?>
      </span>
    </div>

    <button type="button" class="btn btn--ghost btn--sm" data-mrefresh>
      <?= $this->icon('refresh', 'ic ic--sm') ?> Refresh
    </button>
  </div>

  <?php if ($canManage): ?>
    <div class="mbulk hidden" data-mbulk>
      <span class="small"><strong data-mbulk-count>0</strong> selected</span>

      <div class="row row--wrap gap-2 flex-1 row--end">
        <?php if (!$trashed): ?>
          <select class="select mbulk__select" data-mbulk-folder aria-label="Move selection to folder">
            <option value="">Unfiled</option>
            <?php foreach ($folderOptions as $option): ?>
              <option value="<?= (int) $option['id'] ?>"><?= $this->e($option['label']) ?></option>
            <?php endforeach; ?>
          </select>

          <button type="button" class="btn btn--outline btn--sm" data-mbulk-action="move">
            <?= $this->icon('external', 'ic ic--sm') ?> Move
          </button>

          <button type="button" class="btn btn--outline btn--sm text-error" data-mbulk-action="trash">
            <?= $this->icon('trash', 'ic ic--sm') ?> Move to trash
          </button>
        <?php else: ?>
          <button type="button" class="btn btn--outline btn--sm" data-mbulk-action="restore">
            <?= $this->icon('refresh', 'ic ic--sm') ?> Restore
          </button>

          <button type="button" class="btn btn--danger btn--sm" data-mbulk-action="purge">
            <?= $this->icon('trash', 'ic ic--sm') ?> Delete permanently
          </button>
        <?php endif; ?>

        <button type="button" class="btn btn--ghost btn--sm" data-mbulk-clear>Clear</button>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($files === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon($trashed ? 'trash' : 'image', 'ic ic--lg') ?></div>

      <?php if ($trashed): ?>
        <p class="empty__title">The trash is empty</p>
        <p class="empty__text">Deleted files stay here until you remove them permanently.</p>
      <?php elseif (($filters['q'] ?? '') !== '' || ($filters['type'] ?? '') !== '' || ($filters['from'] ?? '') !== '' || ($filters['to'] ?? '') !== ''): ?>
        <p class="empty__title">Nothing matches those filters</p>
        <p class="empty__text">Clear the search or the type filter to see the whole folder.</p>
      <?php elseif (($filters['folder'] ?? '') === ''): ?>
        <p class="empty__title">No files yet</p>
        <p class="empty__text">Drop files anywhere on this panel, or use Upload files on the right.</p>
      <?php else: ?>
        <p class="empty__title">This folder is empty</p>
        <p class="empty__text">Drop files here to add them, or tick “Include sub-folders” to look deeper.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card__body">
      <div class="mgrid">
        <?php foreach ($files as $file): ?>
          <?php
          $id       = (int) $file['id'];
          $mime     = (string) $file['mime_type'];
          $group    = MediaController::group($mime);
          $isImage  = $group === 'image';
          $preview  = (string) ($file['thumbnail_url'] ?? '') !== ''
              ? (string) $file['thumbnail_url']
              : (string) $file['url'];
          $altText  = (string) ($file['alt_text'] ?? '');
          $isGone   = $file['deleted_at'] !== null;
          $meta     = [MediaController::bytes((int) $file['size_bytes'])];

          if ($file['width'] !== null && $file['height'] !== null) {
              $meta[] = (int) $file['width'] . '×' . (int) $file['height'];
          }

          if ($file['duration_secs'] !== null) {
              $meta[] = gmdate((int) $file['duration_secs'] >= 3600 ? 'H:i:s' : 'i:s', (int) $file['duration_secs']);
          }
          ?>
          <article class="mtile<?= $isGone ? ' mtile--gone' : '' ?>"
                   data-mfile="<?= $id ?>"
                   data-name="<?= $this->e($file['original_name']) ?>"
                   data-alt="<?= $this->e($altText) ?>"
                   data-folder="<?= $file['folder_id'] === null ? '' : (int) $file['folder_id'] ?>"
                   data-url="<?= $this->e($file['url']) ?>"
                   data-trashed="<?= $isGone ? '1' : '0' ?>">

            <?php if ($canManage): ?>
              <label class="mtile__check checkbox">
                <input type="checkbox" data-mselect="<?= $id ?>"
                       aria-label="Select <?= $this->e($file['original_name']) ?>">
              </label>
            <?php endif; ?>

            <div class="mtile__thumb">
              <?php if ($isImage): ?>
                <img src="<?= $this->e($preview) ?>" alt="<?= $this->e($altText) ?>" loading="lazy" decoding="async">
              <?php else: ?>
                <span class="mtile__glyph"><?= $this->icon('file', 'ic ic--lg') ?></span>
              <?php endif; ?>

              <span class="mtile__ext chip chip--neutral"><?= $this->e(strtoupper((string) $file['extension'])) ?></span>

              <?php if ((int) $file['is_optimized'] === 1): ?>
                <span class="mtile__badge chip chip--success" title="Re-encoded on upload">Optimised</span>
              <?php endif; ?>
            </div>

            <div class="mtile__body">
              <p class="tiny mtile__name" title="<?= $this->e($file['original_name']) ?>">
                <?= $this->e($file['original_name']) ?>
              </p>

              <p class="tiny faint mtile__meta"><?= $this->e(implode(' · ', $meta)) ?></p>

              <p class="tiny faint mtile__meta">
                <?= $this->e($file['folder_path'] !== null ? (string) $file['folder_path'] : 'Unfiled') ?>
                · <?= $this->e($this->date($file['created_at'])) ?>
              </p>

              <p class="tiny faint mtile__meta">
                <?php if (($file['uploader_name'] ?? null) !== null && (string) $file['uploader_name'] !== ''): ?>
                  <?= $this->e($file['uploader_name']) ?>
                <?php else: ?>
                  <?= $this->e(ucfirst((string) $file['uploader_type'])) ?><?= $file['uploader_id'] !== null ? ' #' . (int) $file['uploader_id'] : '' ?>
                <?php endif; ?>
                <?php if ($altText === '' && $isImage): ?>
                  · <span class="text-warning">no alt text</span>
                <?php endif; ?>
              </p>

              <div class="row gap-1 mt-2 row--wrap">
                <button type="button" class="btn btn--ghost btn--icon btn--sm"
                        data-mcopy="<?= $this->e($file['url']) ?>" title="Copy URL" aria-label="Copy URL">
                  <?= $this->icon('copy', 'ic ic--sm') ?>
                </button>

                <a href="<?= $this->e($file['url']) ?>" target="_blank" rel="noopener noreferrer"
                   class="btn btn--ghost btn--icon btn--sm" title="Open in a new tab" aria-label="Open">
                  <?= $this->icon('external', 'ic ic--sm') ?>
                </a>

                <?php if ($canManage): ?>
                  <?php if (!$isGone): ?>
                    <button type="button" class="btn btn--ghost btn--icon btn--sm"
                            data-medit="<?= $id ?>" title="Rename, alt text, folder" aria-label="Edit">
                      <?= $this->icon('edit', 'ic ic--sm') ?>
                    </button>

                    <button type="button" class="btn btn--ghost btn--icon btn--sm text-error"
                            style="margin-left:auto" data-mtrash="<?= $id ?>"
                            title="Move to trash" aria-label="Move to trash">
                      <?= $this->icon('trash', 'ic ic--sm') ?>
                    </button>
                  <?php else: ?>
                    <button type="button" class="btn btn--ghost btn--icon btn--sm"
                            data-mrestore="<?= $id ?>" title="Restore" aria-label="Restore">
                      <?= $this->icon('refresh', 'ic ic--sm') ?>
                    </button>

                    <button type="button" class="btn btn--ghost btn--icon btn--sm text-error"
                            style="margin-left:auto" data-mpurge="<?= $id ?>"
                            title="Delete permanently" aria-label="Delete permanently">
                      <?= $this->icon('x', 'ic ic--sm') ?>
                    </button>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($lastPage > 1): ?>
      <nav class="pagination" aria-label="Pagination">
        <span class="pagination__info">
          Showing <strong><?= $this->number($pagination['from'] ?? 0) ?></strong>–<strong><?= $this->number($pagination['to'] ?? 0) ?></strong>
          of <strong><?= $this->number($total) ?></strong>
        </span>

        <a class="pagination__item<?= $page <= 1 ? ' is-disabled' : '' ?>"
           href="<?= $this->e($link(['page' => max(1, $page - 1)])) ?>"
           data-mpage="<?= max(1, $page - 1) ?>" aria-label="Previous page"
           <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>
          <?= $this->icon('chevron-left', 'ic ic--sm') ?>
        </a>

        <?php foreach ($pages as $entry): ?>
          <?php if ($entry === '…'): ?>
            <span class="pagination__item is-disabled" aria-hidden="true">…</span>
          <?php else: ?>
            <a class="pagination__item<?= $entry === $page ? ' is-active' : '' ?>"
               href="<?= $this->e($link(['page' => (int) $entry])) ?>"
               data-mpage="<?= (int) $entry ?>"
               <?= $entry === $page ? 'aria-current="page"' : '' ?>><?= (int) $entry ?></a>
          <?php endif; ?>
        <?php endforeach; ?>

        <a class="pagination__item<?= $page >= $lastPage ? ' is-disabled' : '' ?>"
           href="<?= $this->e($link(['page' => min($lastPage, $page + 1)])) ?>"
           data-mpage="<?= min($lastPage, $page + 1) ?>" aria-label="Next page"
           <?= $page >= $lastPage ? 'aria-disabled="true"' : '' ?>>
          <?= $this->icon('chevron-right', 'ic ic--sm') ?>
        </a>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

  <?php // Fresh counters travel with every grid render so the tree and the
        // storage card stay accurate after an AJAX swap. ?>
  <script type="application/json" data-mstate><?= json_encode($gridState, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>

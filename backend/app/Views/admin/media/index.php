<?php

declare(strict_types=1);

/**
 * Media library: folder tree, file grid, uploader and storage summary.
 *
 * @var \Zuste\Core\View $this
 * @var array{rows:array<int,array<string,mixed>>,children:array<int,list<int>>,rollup:array<int,array{files:int,bytes:int}>,unfiled:array{files:int,bytes:int,held:int}} $tree
 * @var array<string,mixed> $filters
 * @var list<array<string,mixed>> $files
 * @var array<string,mixed> $pagination
 * @var list<array{id:int,label:string,path:string,depth:int}> $folderOptions
 * @var array<string,mixed> $gridState
 * @var array<string,int> $storage
 * @var list<array<string,mixed>> $largest
 * @var list<array{name:string,path:string,files:int,bytes:int}> $folderSizes
 * @var list<string> $accepted
 * @var int $maxUploadMb
 * @var int $maxDepth
 */

use Zuste\Controllers\Admin\MediaController;

$this->extend('admin.layouts.app');
$this->start('content');

$canManage    = $this->can('cms.media');
$activeFolder = (string) ($filters['folder'] ?? '');
$isTrash      = ($filters['state'] ?? 'active') === 'trashed';

// No folder glyph ships with the shared icon set, so it lives here.
$folderGlyph = '<svg class="ic ic--sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" '
             . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . '<path d="M3 7a2 2 0 0 1 2-2h4l2 2.5h8a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';

/** One tree level, then its children. */
$renderBranch = function (int $parentId) use (&$renderBranch, $tree, $activeFolder, $isTrash, $canManage, $folderGlyph): void {
    $childIds = $tree['children'][$parentId] ?? [];

    if ($childIds === []) {
        return;
    }

    // The top level sits flush; every nested level gets the indent guide.
    echo $parentId === 0 ? '<ul class="mtree__list mtree__list--root">' : '<ul class="mtree__list">';

    foreach ($childIds as $childId) {
        $childId = (int) $childId;
        $folder  = $tree['rows'][$childId] ?? null;

        if ($folder === null) {
            continue;
        }

        $rollup   = $tree['rollup'][$childId] ?? ['files' => 0, 'bytes' => 0];
        $hasKids  = ($tree['children'][$childId] ?? []) !== [];
        $isActive = !$isTrash && $activeFolder === (string) $childId;
        $isSystem = (int) $folder['is_system'] === 1;
        ?>
        <li class="mtree__node" data-mnode="<?= $childId ?>">
          <div class="mtree__row<?= $isActive ? ' mtree__row--active' : '' ?>">
            <?php if ($hasKids): ?>
              <button type="button" class="mtree__twist" data-mtwist aria-expanded="true"
                      aria-label="Collapse <?= $this->e($folder['name']) ?>">
                <?= $this->icon('chevron-down', 'ic ic--sm') ?>
              </button>
            <?php else: ?>
              <span class="mtree__twist mtree__twist--empty" aria-hidden="true"></span>
            <?php endif; ?>

            <a class="mtree__link" href="/admin/media?folder=<?= $childId ?>" data-mfolder="<?= $childId ?>"
               title="<?= $this->e($folder['path']) ?>">
              <?= $folderGlyph ?>
              <span class="mtree__name"><?= $this->e($folder['name']) ?></span>
            </a>

            <span class="mtree__count" data-mcount="<?= $childId ?>"><?= $this->number($rollup['files']) ?></span>

            <?php if ($canManage): ?>
              <button type="button" class="mtree__cog" title="Folder settings"
                      aria-label="Settings for <?= $this->e($folder['name']) ?>"
                      data-mfolder-edit="<?= $childId ?>"
                      data-fname="<?= $this->e($folder['name']) ?>"
                      data-fparent="<?= $folder['parent_id'] === null ? '' : (int) $folder['parent_id'] ?>"
                      data-fpath="<?= $this->e($folder['path']) ?>"
                      data-fsystem="<?= $isSystem ? '1' : '0' ?>"
                      data-ffiles="<?= (int) $folder['files'] ?>"
                      data-fheld="<?= (int) $folder['held'] ?>"
                      data-fkids="<?= count($tree['children'][$childId] ?? []) ?>">
                <?= $this->icon('settings', 'ic ic--sm') ?>
              </button>
            <?php endif; ?>
          </div>

          <?php $renderBranch($childId); ?>
        </li>
        <?php
    }

    echo '</ul>';
};

$totalLive = (int) ($storage['live_files'] ?? 0);

?>

<div class="media-shell" data-media
     data-filters="<?= $this->json([
         'folder'   => (string) ($filters['folder'] ?? ''),
         'sub'      => (string) ($filters['sub'] ?? ''),
         'state'    => (string) ($filters['state'] ?? 'active'),
         'q'        => (string) ($filters['q'] ?? ''),
         'type'     => (string) ($filters['type'] ?? ''),
         'from'     => (string) ($filters['from'] ?? ''),
         'to'       => (string) ($filters['to'] ?? ''),
         'sort_by'  => (string) ($filters['sort_by'] ?? 'created_at'),
         'sort_dir' => (string) ($filters['sort_dir'] ?? 'DESC'),
         'page'     => (int) ($filters['page'] ?? 1),
     ]) ?>">

  <!-- ================= Folders + storage ================= -->
  <aside class="col gap-4">

    <div class="card">
      <div class="card__head">
        <h2 class="h3">Folders</h2>
        <?php if ($canManage): ?>
          <button type="button" class="btn btn--ghost btn--sm" data-mfolder-new>
            <?= $this->icon('plus', 'ic ic--sm') ?> New
          </button>
        <?php endif; ?>
      </div>

      <div class="card__body card__body--flush">
        <nav class="mtree" aria-label="Media folders">
          <div class="mtree__row<?= (!$isTrash && $activeFolder === '') ? ' mtree__row--active' : '' ?>">
            <span class="mtree__twist mtree__twist--empty" aria-hidden="true"></span>
            <a class="mtree__link" href="/admin/media" data-mfolder="">
              <?= $this->icon('grid', 'ic ic--sm') ?>
              <span class="mtree__name">All files</span>
            </a>
            <span class="mtree__count" data-mcount="all"><?= $this->number($totalLive) ?></span>
          </div>

          <?php $renderBranch(0); ?>

          <div class="mtree__row<?= (!$isTrash && $activeFolder === 'unfiled') ? ' mtree__row--active' : '' ?>">
            <span class="mtree__twist mtree__twist--empty" aria-hidden="true"></span>
            <a class="mtree__link" href="/admin/media?folder=unfiled" data-mfolder="unfiled">
              <?= $this->icon('inbox', 'ic ic--sm') ?>
              <span class="mtree__name">Unfiled</span>
            </a>
            <span class="mtree__count" data-mcount="unfiled"><?= $this->number((int) $tree['unfiled']['files']) ?></span>
          </div>

          <div class="mtree__row<?= $isTrash ? ' mtree__row--active' : '' ?>">
            <span class="mtree__twist mtree__twist--empty" aria-hidden="true"></span>
            <a class="mtree__link" href="/admin/media?state=trashed" data-mtrash-view>
              <?= $this->icon('trash', 'ic ic--sm') ?>
              <span class="mtree__name">Trash</span>
            </a>
            <span class="mtree__count" data-mcount="trash"><?= $this->number((int) ($storage['trashed_files'] ?? 0)) ?></span>
          </div>
        </nav>
      </div>

      <?php if ($canManage): ?>
        <div class="card__body">
          <p class="tiny faint">
            System folders cannot be renamed, moved or deleted. A folder holding
            sub-folders must be emptied of them first; a folder holding files can
            only be deleted once you confirm its files go to the trash.
          </p>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card__head"><h2 class="h3">Storage</h2></div>

      <div class="card__body col gap-3">
        <div class="row row--between">
          <span class="small muted">Files</span>
          <span class="small mono" data-mstat="live_files"><?= $this->number($totalLive) ?></span>
        </div>
        <div class="row row--between">
          <span class="small muted">Total size</span>
          <span class="small mono" data-mstat="live_size"><?= $this->e(MediaController::bytes((int) ($storage['live_bytes'] ?? 0))) ?></span>
        </div>
        <div class="row row--between">
          <span class="small muted">In the trash</span>
          <span class="small mono">
            <span data-mstat="trashed_files"><?= $this->number((int) ($storage['trashed_files'] ?? 0)) ?></span>
            ·
            <span data-mstat="trashed_size"><?= $this->e(MediaController::bytes((int) ($storage['trashed_bytes'] ?? 0))) ?></span>
          </span>
        </div>
        <div class="row row--between">
          <span class="small muted">Images / video</span>
          <span class="small mono">
            <?= $this->number((int) ($storage['image_files'] ?? 0)) ?> · <?= $this->number((int) ($storage['video_files'] ?? 0)) ?>
          </span>
        </div>
      </div>

      <?php if ($folderSizes !== []): ?>
        <div class="card__body card__body--flush">
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th>Folder</th><th class="text-right">Files</th><th class="text-right">Size</th></tr></thead>
              <tbody>
                <?php foreach ($folderSizes as $row): ?>
                  <tr>
                    <td class="tiny truncate" title="<?= $this->e($row['path']) ?>"><?= $this->e($row['name']) ?></td>
                    <td class="tiny text-right mono"><?= $this->number($row['files']) ?></td>
                    <td class="tiny text-right mono"><?= $this->e(MediaController::bytes($row['bytes'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </aside>

  <!-- ================= Filters, uploader, grid ================= -->
  <div class="col gap-4">

    <div class="card">
      <form class="toolbar" id="media-filters" method="get" action="/admin/media">
        <input type="hidden" name="folder" value="<?= $this->e($activeFolder) ?>" data-mstate-field="folder">
        <input type="hidden" name="sub" value="<?= $this->e((string) ($filters['sub'] ?? '')) ?>" data-mstate-field="sub">
        <input type="hidden" name="state" value="<?= $this->e((string) ($filters['state'] ?? 'active')) ?>" data-mstate-field="state">

        <div class="toolbar__search">
          <div class="input-group">
            <span class="input-group__icon"><?= $this->icon('search', 'ic ic--sm') ?></span>
            <input class="input" type="search" name="q" value="<?= $this->e((string) ($filters['q'] ?? '')) ?>"
                   placeholder="Search file names and alt text…" aria-label="Search files">
          </div>
        </div>

        <select class="select" name="type" aria-label="File type" style="max-width:160px">
          <?php foreach ([
              ''         => 'All types',
              'image'    => 'Images',
              'video'    => 'Video',
              'audio'    => 'Audio',
              'document' => 'Documents',
          ] as $value => $label): ?>
            <option value="<?= $this->e($value) ?>" <?= (string) ($filters['type'] ?? '') === $value ? 'selected' : '' ?>>
              <?= $this->e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select class="select" name="sort_by" aria-label="Sort by" style="max-width:150px">
          <?php foreach ([
              'created_at'    => 'Date',
              'size_bytes'    => 'Size',
              'original_name' => 'Name',
          ] as $value => $label): ?>
            <option value="<?= $this->e($value) ?>" <?= (string) ($filters['sort_by'] ?? '') === $value ? 'selected' : '' ?>>
              <?= $this->e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select class="select" name="sort_dir" aria-label="Sort order" style="max-width:140px">
          <option value="DESC" <?= (string) ($filters['sort_dir'] ?? '') === 'DESC' ? 'selected' : '' ?>>Descending</option>
          <option value="ASC"  <?= (string) ($filters['sort_dir'] ?? '') === 'ASC' ? 'selected' : '' ?>>Ascending</option>
        </select>

        <input class="input" type="date" name="from" value="<?= $this->e((string) ($filters['from'] ?? '')) ?>"
               aria-label="Uploaded from" style="max-width:155px">
        <input class="input" type="date" name="to" value="<?= $this->e((string) ($filters['to'] ?? '')) ?>"
               aria-label="Uploaded to" style="max-width:155px">

        <div class="toolbar__spacer"></div>

        <button type="submit" class="btn btn--outline btn--sm" data-mfilter-apply>Apply</button>

        <?php if ($canManage): ?>
          <label class="btn btn--primary btn--sm" for="media-file-input">
            <?= $this->icon('upload', 'ic ic--sm') ?> Upload files
          </label>
          <input type="file" id="media-file-input" class="hidden" multiple
                 accept="<?= $this->e(implode(',', array_map(static fn (string $e): string => '.' . $e, $accepted))) ?>">
        <?php endif; ?>
      </form>

      <div class="card__body chip-row">
        <a class="chip-filter<?= !$isTrash ? ' chip-filter--active' : '' ?>" href="/admin/media" data-mstate-set="active">
          Library
        </a>
        <a class="chip-filter<?= $isTrash ? ' chip-filter--active' : '' ?>" href="/admin/media?state=trashed" data-mstate-set="trashed">
          Trash
          <span class="chip-filter__count" data-mcount="trash-chip"><?= $this->number((int) ($storage['trashed_files'] ?? 0)) ?></span>
        </a>

        <label class="checkbox tiny muted" style="margin-left:auto">
          <input type="checkbox" data-msub <?= (string) ($filters['sub'] ?? '') === '1' ? 'checked' : '' ?>>
          <span>Include sub-folders</span>
        </label>
      </div>
    </div>

    <?php if ($canManage): ?>
      <div class="card hidden" data-mqueue-card>
        <div class="card__head">
          <h2 class="h3">Uploading</h2>
          <span class="small muted"><span data-mqueue-done>0</span> of <span data-mqueue-total>0</span> finished</span>
        </div>
        <div class="card__body col gap-2" data-mqueue></div>
      </div>
    <?php endif; ?>

    <div class="mdrop" data-mdrop>
      <div class="mdrop__hint" aria-hidden="true">
        <?= $this->icon('upload', 'ic ic--lg') ?>
        <p class="h3 mt-2">Drop files to upload</p>
        <p class="small muted" data-mdrop-target></p>
      </div>

      <div id="media-grid">
        <?php $this->partial('admin.media.partials-grid', [
            'filters'       => $filters,
            'files'         => $files,
            'pagination'    => $pagination,
            'folderOptions' => $folderOptions,
            'gridState'     => $gridState,
        ]); ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <h2 class="h3">Largest files</h2>
        <span class="small muted">Top 10 by size</span>
      </div>

      <?php if ($largest === []): ?>
        <div class="empty">
          <div class="empty__art"><?= $this->icon('database', 'ic ic--lg') ?></div>
          <p class="empty__title">Nothing stored yet</p>
          <p class="empty__text">Once files are uploaded, the heaviest ten show here.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead>
              <tr><th>File</th><th>Folder</th><th>Type</th><th class="text-right">Size</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($largest as $row): ?>
                <tr>
                  <td class="small truncate" style="max-width:280px" title="<?= $this->e($row['original_name']) ?>">
                    <?= $this->e($row['original_name']) ?>
                  </td>
                  <td class="tiny muted"><?= $this->e($row['folder_path'] !== null ? (string) $row['folder_path'] : 'Unfiled') ?></td>
                  <td class="tiny muted"><?= $this->e(MediaController::group((string) $row['mime_type'])) ?></td>
                  <td class="small text-right mono"><?= $this->e(MediaController::bytes((int) $row['size_bytes'])) ?></td>
                  <td class="actions">
                    <a href="<?= $this->e($row['url']) ?>" target="_blank" rel="noopener noreferrer"
                       class="btn btn--ghost btn--icon btn--sm" aria-label="Open">
                      <?= $this->icon('external', 'ic ic--sm') ?>
                    </a>
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

<?php if ($canManage): ?>
  <!-- ================= Folder dialog ================= -->
  <div class="dialog-backdrop hidden" data-mdialog="folder" role="dialog" aria-modal="true" aria-labelledby="mfolder-title">
    <div class="dialog">
      <div class="dialog__head">
        <h2 class="h2" id="mfolder-title">New folder</h2>
      </div>

      <div class="dialog__body col gap-3">
        <div class="field">
          <label class="field__label" for="mfolder-name">Name <span class="req">*</span></label>
          <input class="input" id="mfolder-name" maxlength="100" autocomplete="off" placeholder="Campaign assets">
          <p class="field__hint">The URL-safe slug is derived from this name and must be unique inside its parent.</p>
        </div>

        <div class="field">
          <label class="field__label" for="mfolder-parent">Inside</label>
          <select class="select" id="mfolder-parent">
            <option value="" data-path="">Top level</option>
            <?php foreach ($folderOptions as $option): ?>
              <option value="<?= (int) $option['id'] ?>" data-path="<?= $this->e($option['path']) ?>">
                <?= $this->e($option['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="field__hint">Nesting is limited to <?= (int) $maxDepth ?> levels. A folder cannot be moved into itself or its own sub-folder.</p>
        </div>

        <p class="callout callout--warning hidden" data-mfolder-system>
          <?= $this->icon('shield', 'ic ic--sm') ?>
          <span>This is a system folder. It can hold new sub-folders and files, but it cannot be renamed, moved or deleted.</span>
        </p>

        <label class="checkbox small hidden" data-mfolder-purge-wrap>
          <input type="checkbox" data-mfolder-purge>
          <span data-mfolder-purge-label>Also move its files to the trash</span>
        </label>
      </div>

      <div class="dialog__foot">
        <button type="button" class="btn btn--ghost text-error hidden" data-mfolder-delete>
          <?= $this->icon('trash', 'ic ic--sm') ?> Delete folder
        </button>
        <button type="button" class="btn btn--outline" data-mdialog-close>Cancel</button>
        <button type="button" class="btn btn--primary" data-mfolder-save>Save</button>
      </div>
    </div>
  </div>

  <!-- ================= File dialog ================= -->
  <div class="dialog-backdrop hidden" data-mdialog="file" role="dialog" aria-modal="true" aria-labelledby="mfile-title">
    <div class="dialog">
      <div class="dialog__head">
        <h2 class="h2" id="mfile-title">File details</h2>
      </div>

      <div class="dialog__body col gap-3">
        <div class="field">
          <label class="field__label" for="mfile-name">File name <span class="req">*</span></label>
          <input class="input" id="mfile-name" maxlength="191" autocomplete="off">
          <p class="field__hint">This is the display name only. The stored file keeps its random name on disk.</p>
        </div>

        <div class="field">
          <label class="field__label" for="mfile-alt">Alt text</label>
          <input class="input" id="mfile-alt" maxlength="255" autocomplete="off"
                 placeholder="What the image shows, for screen readers">
        </div>

        <div class="field">
          <label class="field__label" for="mfile-folder">Folder</label>
          <select class="select" id="mfile-folder">
            <option value="">Unfiled</option>
            <?php foreach ($folderOptions as $option): ?>
              <option value="<?= (int) $option['id'] ?>"><?= $this->e($option['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="field__hint">Moving a file changes where it appears in the library. The bytes stay where they were written.</p>
        </div>
      </div>

      <div class="dialog__foot">
        <button type="button" class="btn btn--outline" data-mdialog-close>Cancel</button>
        <button type="button" class="btn btn--primary" data-mfile-save>Save</button>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
.media-shell { display: grid; grid-template-columns: minmax(240px, 300px) minmax(0, 1fr); gap: 18px; align-items: start; }
@media (max-width: 1000px) { .media-shell { grid-template-columns: 1fr; } }

/* ---- Folder tree ---- */
.mtree { padding: 8px; }
.mtree__list { list-style: none; margin: 0; padding-left: 14px; border-left: 1px solid var(--outline-variant); }
.mtree__list--root { padding-left: 0; border-left: 0; }
.mtree__node { margin: 0; }
.mtree__row { display: flex; align-items: center; gap: 4px; border-radius: var(--r-sm); padding: 2px 4px; }
.mtree__row:hover { background: var(--surface-variant); }
.mtree__row--active { background: var(--surface-container); }
.mtree__row--active .mtree__link { color: var(--primary); font-weight: 600; }
.mtree__link { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0;
  padding: 6px 4px; color: var(--on-surface); text-decoration: none; font-size: 13px; }
.mtree__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mtree__twist { width: 20px; height: 20px; flex-shrink: 0; display: grid; place-items: center;
  background: none; border: 0; padding: 0; cursor: pointer; color: var(--on-surface-variant); border-radius: var(--r-sm); }
.mtree__twist--empty { cursor: default; }
.mtree__twist[aria-expanded="false"] svg { transform: rotate(-90deg); }
.mtree__twist svg { transition: transform .15s ease; }
.mtree__count { font-size: 11px; font-variant-numeric: tabular-nums; color: var(--on-surface-variant); padding: 0 4px; }
.mtree__cog { width: 26px; height: 26px; flex-shrink: 0; display: grid; place-items: center; border: 0;
  background: none; cursor: pointer; color: var(--on-surface-variant); border-radius: var(--r-sm); opacity: 0; }
.mtree__row:hover .mtree__cog, .mtree__cog:focus-visible { opacity: 1; }
.mtree__cog:hover { background: var(--surface-variant); color: var(--primary); }
/* A tap produces no hover state, so on touch the cog — the only way into
   rename, move and delete — would never appear. Must stay after the base
   rule: a media query adds no specificity, so source order decides. */
@media (hover: none), (pointer: coarse) { .mtree__cog { opacity: 1; } }
.mtree__node[data-collapsed="true"] > .mtree__list { display: none; }

/* ---- Drop zone ---- */
.mdrop { position: relative; border-radius: var(--r-lg); }
.mdrop__hint { display: none; }
.mdrop--over .mdrop__hint { position: absolute; inset: 0; z-index: 5; display: grid; place-items: center;
  text-align: center; border-radius: var(--r-lg); border: 2px dashed var(--primary);
  background: var(--surface-high); pointer-events: none; }
@supports (background: color-mix(in srgb, red 50%, blue)) {
  .mdrop--over .mdrop__hint { background: color-mix(in srgb, var(--surface) 86%, var(--primary) 14%); }
}

/* ---- File grid ---- */
.mgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 14px; }
.mtile { position: relative; border: 1px solid var(--outline-variant); border-radius: var(--r-md);
  overflow: hidden; background: var(--surface); }
.mtile--gone { opacity: .68; }
.mtile--selected { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary) inset; }
.mtile__thumb { position: relative; aspect-ratio: 4 / 3; background: var(--surface-container);
  display: grid; place-items: center; overflow: hidden; }
.mtile__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.mtile__glyph { color: var(--outline); }
.mtile__ext { position: absolute; left: 6px; bottom: 6px; font-size: 9px; padding: 2px 7px; }
.mtile__badge { position: absolute; right: 6px; bottom: 6px; font-size: 9px; padding: 2px 7px; }
.mtile__check { position: absolute; top: 6px; left: 6px; z-index: 2; background: var(--surface);
  border-radius: var(--r-sm); padding: 3px; }
.mtile__body { padding: 10px; }
.mtile__name { font-weight: 600; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mtile__meta { margin: 2px 0 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ---- Bulk bar ---- */
.mbulk { display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  padding: 10px 18px; border-bottom: 1px solid var(--outline-variant); background: var(--surface-variant); }
.mbulk__select { max-width: 190px; }

/* ---- Upload queue ---- */
.mqrow { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 12px; align-items: center; }
.mqrow__name { font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mqrow__state { font-size: 11px; color: var(--on-surface-variant); white-space: nowrap; }
.mqrow__bar { grid-column: 1 / -1; height: 4px; border-radius: var(--r-full);
  background: var(--surface-container); overflow: hidden; }
.mqrow__fill { display: block; height: 100%; width: 100%; border-radius: var(--r-full); background: var(--outline-variant); }
.mqrow[data-state="uploading"] .mqrow__fill { width: 40%; background: var(--primary); animation: mq-slide 1.1s ease-in-out infinite; }
.mqrow[data-state="done"] .mqrow__fill { background: var(--tertiary); }
.mqrow[data-state="failed"] .mqrow__fill { background: var(--error); }
.mqrow[data-state="failed"] .mqrow__state { color: var(--error); }
@keyframes mq-slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(250%); } }
@media (prefers-reduced-motion: reduce) { .mqrow__fill { animation: none !important; } }
</style>

<script>
(function () {
  const root = document.querySelector('[data-media]');
  if (!root) return;

  const token    = document.querySelector('meta[name="csrf-token"]').content;
  const gridHost = document.getElementById('media-grid');
  const form     = document.getElementById('media-filters');
  const dropzone = document.querySelector('[data-mdrop]');
  const fileInput = document.getElementById('media-file-input');
  const canManage = <?= $canManage ? 'true' : 'false' ?>;

  const state = Object.assign({
    folder: '', sub: '', state: 'active', q: '', type: '',
    from: '', to: '', sort_by: 'created_at', sort_dir: 'DESC', page: 1
  }, JSON.parse(root.dataset.filters));

  /* ---------------------------------------------------------------
     admin.js delegates [data-confirm] on document, and the dialog that
     Admin.confirm() builds gives its own OK button that same attribute.
     Confirming anything here would therefore bubble up and open a second,
     empty dialog. Stop that click at <body>: the dialog's own handler has
     already run by then, and nothing else listens for it above.
     --------------------------------------------------------------- */
  document.body.addEventListener('click', (event) => {
    const button = event.target.closest('.dialog-backdrop [data-confirm]');
    if (button && button.dataset.confirm === '') event.stopPropagation();
  });

  /* ---------------------------------------------------------------
     URL + fetch plumbing
     --------------------------------------------------------------- */
  function queryString() {
    const params = new URLSearchParams();

    // Defaults are implied by the server, so leave them out of the URL.
    const defaults = { page: '1', state: 'active', sort_by: 'created_at', sort_dir: 'DESC' };

    Object.keys(state).forEach((key) => {
      const value = String(state[key] ?? '');
      if (value === '' || defaults[key] === value) return;
      params.set(key, value);
    });

    const query = params.toString();
    return query === '' ? '' : '?' + query;
  }

  async function post(url, fields) {
    const body = new FormData();

    Object.entries(fields || {}).forEach(([key, value]) => {
      if (Array.isArray(value)) value.forEach((item) => body.append(key + '[]', item));
      else if (value !== null && value !== undefined) body.append(key, value);
    });

    body.append('_csrf', token);

    const response = await fetch(url, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': token }
    });

    let payload = null;
    try { payload = await response.json(); } catch (e) { payload = null; }

    if (!response.ok || !payload || payload.success === false) {
      throw new Error((payload && payload.message) || 'The request failed (' + response.status + ').');
    }

    return payload;
  }

  let requestId = 0;

  async function refresh() {
    const mine = ++requestId;
    gridHost.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch('/admin/media/grid' + queryString(), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
      });

      if (!response.ok) {
        let message = 'Could not load the files (' + response.status + ').';
        try { const body = await response.json(); if (body && body.message) message = body.message; } catch (e) {}
        window.Admin.toast(message, 'error');
        return;
      }

      const html = await response.text();
      if (mine !== requestId) return; // A newer request already won.

      // Swapping the grid detaches whatever had the focus inside it — a tile
      // button, or the element a dialog just handed the focus back to — which
      // would drop the keyboard on <body>. Anything outside the grid keeps it.
      const focused = document.activeElement;

      gridHost.innerHTML = html;

      if (!focused || focused === document.body || !focused.isConnected) {
        gridHost.setAttribute('tabindex', '-1');
        gridHost.focus({ preventScroll: true });
      }

      applyCounts();
      history.replaceState(null, '', '/admin/media' + queryString());
      syncTree();
    } catch (e) {
      window.Admin.toast('Could not reach the server. Check your connection.', 'error');
    } finally {
      gridHost.removeAttribute('aria-busy');
    }
  }

  /* ---------------------------------------------------------------
     Keeping the tree and the storage card honest after a swap
     --------------------------------------------------------------- */
  function applyCounts() {
    const holder = gridHost.querySelector('[data-mstate]');
    if (!holder) return;

    let data;
    try { data = JSON.parse(holder.textContent); } catch (e) { return; }

    (data.folders || []).forEach((folder) => {
      const badge = document.querySelector('[data-mcount="' + folder.id + '"]');
      if (badge) { badge.textContent = String(folder.files); badge.title = folder.size; }
    });

    const unfiled = document.querySelector('[data-mcount="unfiled"]');
    if (unfiled && data.unfiled) { unfiled.textContent = String(data.unfiled.files); unfiled.title = data.unfiled.size; }

    if (!data.storage) return;

    const all = document.querySelector('[data-mcount="all"]');
    if (all) all.textContent = String(data.storage.live_files);

    ['trash', 'trash-chip'].forEach((key) => {
      const el = document.querySelector('[data-mcount="' + key + '"]');
      if (el) el.textContent = String(data.storage.trashed_files);
    });

    Object.entries(data.storage).forEach(([key, value]) => {
      const el = document.querySelector('[data-mstat="' + key + '"]');
      if (el) el.textContent = String(value);
    });
  }

  function syncTree() {
    document.querySelectorAll('.mtree__row').forEach((row) => row.classList.remove('mtree__row--active'));

    let link = null;
    if (state.state === 'trashed') link = document.querySelector('[data-mtrash-view]');
    else link = document.querySelector('[data-mfolder="' + state.folder + '"]');

    if (link) link.closest('.mtree__row').classList.add('mtree__row--active');

    document.querySelectorAll('[data-mstate-set]').forEach((chip) => {
      chip.classList.toggle('chip-filter--active', chip.dataset.mstateSet === state.state);
    });

    document.querySelectorAll('[data-mstate-field]').forEach((input) => {
      input.value = String(state[input.dataset.mstateField] ?? '');
    });
  }

  /* ---------------------------------------------------------------
     Folder tree interaction
     --------------------------------------------------------------- */
  document.querySelector('.mtree').addEventListener('click', (event) => {
    const twist = event.target.closest('[data-mtwist]');
    if (twist) {
      const node = twist.closest('[data-mnode]');
      const collapsed = node.dataset.collapsed === 'true';
      node.dataset.collapsed = collapsed ? 'false' : 'true';
      twist.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
      return;
    }

    const trashLink = event.target.closest('[data-mtrash-view]');
    if (trashLink) {
      event.preventDefault();
      state.state = 'trashed';
      state.page = 1;
      refresh();
      return;
    }

    const folderLink = event.target.closest('[data-mfolder]');
    if (folderLink) {
      event.preventDefault();
      state.folder = folderLink.dataset.mfolder;
      state.state = 'active';
      state.page = 1;
      refresh();
    }
  });

  document.querySelectorAll('[data-mstate-set]').forEach((chip) => {
    chip.addEventListener('click', (event) => {
      event.preventDefault();
      state.state = chip.dataset.mstateSet;
      state.page = 1;
      refresh();
    });
  });

  const subToggle = document.querySelector('[data-msub]');
  if (subToggle) {
    subToggle.addEventListener('change', () => {
      state.sub = subToggle.checked ? '1' : '';
      state.page = 1;
      refresh();
    });
  }

  /* ---------------------------------------------------------------
     Filters
     --------------------------------------------------------------- */
  function readForm() {
    state.q = form.elements.q.value.trim();
    state.type = form.elements.type.value;
    state.sort_by = form.elements.sort_by.value;
    state.sort_dir = form.elements.sort_dir.value;
    state.from = form.elements.from.value;
    state.to = form.elements.to.value;
    state.page = 1;
  }

  form.addEventListener('submit', (event) => { event.preventDefault(); readForm(); refresh(); });

  let searchTimer = null;
  form.elements.q.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { readForm(); refresh(); }, 450);
  });

  ['type', 'sort_by', 'sort_dir', 'from', 'to'].forEach((name) => {
    form.elements[name].addEventListener('change', () => { readForm(); refresh(); });
  });

  /* ---------------------------------------------------------------
     Grid: selection, paging, per-file actions
     --------------------------------------------------------------- */
  function selectedIds() {
    return Array.from(gridHost.querySelectorAll('[data-mselect]:checked')).map((box) => box.dataset.mselect);
  }

  function syncSelection() {
    const boxes = Array.from(gridHost.querySelectorAll('[data-mselect]'));
    const checked = boxes.filter((box) => box.checked);
    const bar = gridHost.querySelector('[data-mbulk]');
    const counter = gridHost.querySelector('[data-mbulk-count]');
    const all = gridHost.querySelector('[data-mselect-all]');

    boxes.forEach((box) => {
      const tile = box.closest('[data-mfile]');
      if (tile) tile.classList.toggle('mtile--selected', box.checked);
    });

    if (all) {
      all.checked = boxes.length > 0 && checked.length === boxes.length;
      all.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }

    if (bar) bar.classList.toggle('hidden', checked.length === 0);
    if (counter) counter.textContent = String(checked.length);
  }

  gridHost.addEventListener('change', (event) => {
    if (event.target.matches('[data-mselect-all]')) {
      const on = event.target.checked;
      gridHost.querySelectorAll('[data-mselect]').forEach((box) => { box.checked = on; });
    }

    if (event.target.matches('[data-mselect], [data-mselect-all]')) syncSelection();
  });

  gridHost.addEventListener('click', async (event) => {
    const pager = event.target.closest('[data-mpage]');
    if (pager) {
      event.preventDefault();
      const next = parseInt(pager.dataset.mpage, 10);
      if (!Number.isNaN(next) && next !== state.page) { state.page = next; refresh(); }
      return;
    }

    if (event.target.closest('[data-mrefresh]')) { refresh(); return; }

    const copy = event.target.closest('[data-mcopy]');
    if (copy) {
      try {
        await navigator.clipboard.writeText(copy.dataset.mcopy);
        window.Admin.toast('URL copied to the clipboard.', 'success', 2000);
      } catch (e) {
        window.Admin.toast('Could not copy — your browser blocked clipboard access.', 'error');
      }
      return;
    }

    if (!canManage) return;

    const edit = event.target.closest('[data-medit]');
    if (edit) { openFileDialog(edit.closest('[data-mfile]'), edit); return; }

    const trash = event.target.closest('[data-mtrash]');
    if (trash) {
      const ok = await window.Admin.confirm({
        title: 'Move to the trash?',
        message: 'The file stops appearing in the library but stays on disk, so you can restore it.',
        confirmLabel: 'Move to trash', tone: 'warning'
      });
      if (ok) run('/admin/media/' + trash.dataset.mtrash + '/delete');
      return;
    }

    const restore = event.target.closest('[data-mrestore]');
    if (restore) { run('/admin/media/' + restore.dataset.mrestore + '/restore'); return; }

    const purge = event.target.closest('[data-mpurge]');
    if (purge) {
      const ok = await window.Admin.confirm({
        title: 'Delete permanently?',
        message: 'The record and the stored file are both removed. Anything still linking to it will break.',
        confirmLabel: 'Delete for good', tone: 'danger'
      });
      if (ok) run('/admin/media/' + purge.dataset.mpurge + '/purge');
      return;
    }

    const bulk = event.target.closest('[data-mbulk-action]');
    if (bulk) { runBulk(bulk.dataset.mbulkAction); return; }

    if (event.target.closest('[data-mbulk-clear]')) {
      gridHost.querySelectorAll('[data-mselect]').forEach((box) => { box.checked = false; });
      syncSelection();
    }
  });

  async function run(url, fields) {
    try {
      const result = await post(url, fields);
      window.Admin.toast(result.message || 'Done.', 'success');
      refresh();
    } catch (error) {
      window.Admin.toast(error.message, 'error');
    }
  }

  async function runBulk(action) {
    const ids = selectedIds();
    if (ids.length === 0) { window.Admin.toast('Select at least one file first.', 'error'); return; }

    const fields = { action: action, ids: ids };

    if (action === 'move') {
      const select = gridHost.querySelector('[data-mbulk-folder]');
      fields.folder_id = select ? select.value : '';
    }

    if (action === 'trash' || action === 'purge') {
      const ok = await window.Admin.confirm({
        title: action === 'purge' ? 'Delete ' + ids.length + ' files permanently?' : 'Move ' + ids.length + ' files to the trash?',
        message: action === 'purge'
          ? 'Their records and stored files are both removed. This cannot be undone.'
          : 'They stop appearing in the library but stay on disk and can be restored.',
        confirmLabel: action === 'purge' ? 'Delete for good' : 'Move to trash',
        tone: action === 'purge' ? 'danger' : 'warning'
      });
      if (!ok) return;
    }

    run('/admin/media/bulk', fields);
  }

  /* ---------------------------------------------------------------
     Dialogs

     Both dialogs declare aria-modal="true", which tells assistive tech the
     page behind them is inert. That is only honest if Tab stays inside the
     dialog and the focus goes back to whatever opened it on close.
     --------------------------------------------------------------- */
  const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),'
    + 'select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

  let dialogReturn = null;

  function openBackdrop() {
    return document.querySelector('[data-mdialog]:not(.hidden)');
  }

  // Admin.confirm() builds its own backdrop on <body> and the folder dialog
  // stays open underneath it. While that one is up it owns the keyboard, so
  // this dialog stands down for both Escape and Tab — otherwise one Escape
  // would close the confirmation and the folder dialog behind it together.
  function overlayAbove() {
    return document.querySelector('.dialog-backdrop:not([data-mdialog])') !== null;
  }

  // Recomputed on every Tab, never cached: the folder dialog shows and hides
  // its delete button, purge checkbox and save button per folder, and
  // disables the name and parent fields for system folders.
  function dialogFocusables(dialog) {
    return Array.from(dialog.querySelectorAll(FOCUSABLE)).filter((el) => el.getClientRects().length > 0);
  }

  function openDialog(name, opener) {
    const dialog = document.querySelector('[data-mdialog="' + name + '"]');
    if (!dialog) return null;

    const from = opener || document.activeElement;
    dialogReturn = from instanceof HTMLElement && from !== document.body ? from : null;

    dialog.classList.remove('hidden');
    return dialog;
  }

  // Run once the caller has finished setting a dialog up, so that a dialog
  // with every field disabled still takes the focus rather than leaving it
  // on the page behind.
  function anchorFocus(dialog) {
    if (dialog.contains(document.activeElement)) return;

    const targets = dialogFocusables(dialog);
    if (targets.length > 0) { targets[0].focus(); return; }

    const panel = dialog.querySelector('.dialog');
    if (panel) { panel.setAttribute('tabindex', '-1'); panel.focus(); }
  }

  function closeDialogs() {
    const wasOpen = openBackdrop() !== null;
    document.querySelectorAll('[data-mdialog]').forEach((d) => d.classList.add('hidden'));

    if (!wasOpen) return;

    // Saving a file refreshes the grid, which replaces the tile the dialog
    // was opened from, so the recorded element may be detached by now.
    const back = dialogReturn && dialogReturn.isConnected ? dialogReturn : gridHost;
    dialogReturn = null;

    if (back === gridHost) {
      gridHost.setAttribute('tabindex', '-1');
      gridHost.focus({ preventScroll: true });
      return;
    }

    back.focus();
  }

  document.addEventListener('click', (event) => {
    if (event.target.closest('[data-mdialog-close]')) { closeDialogs(); return; }
    if (event.target.matches('[data-mdialog]')) closeDialogs();
  });

  document.addEventListener('keydown', (event) => {
    const dialog = openBackdrop();
    if (!dialog || overlayAbove()) return;

    if (event.key === 'Escape') { closeDialogs(); return; }
    if (event.key !== 'Tab') return;

    const targets = dialogFocusables(dialog);
    if (targets.length === 0) { event.preventDefault(); return; }

    const active = document.activeElement;
    const inside = dialog.contains(active);

    if (event.shiftKey && (!inside || active === targets[0])) {
      event.preventDefault();
      targets[targets.length - 1].focus();
    } else if (!event.shiftKey && (!inside || active === targets[targets.length - 1])) {
      event.preventDefault();
      targets[0].focus();
    }
  });

  /* ---- File dialog ---- */
  let editingFile = null;

  function openFileDialog(tile, opener) {
    if (!tile) return;

    const dialog = openDialog('file', opener);
    if (!dialog) return;

    editingFile = tile.dataset.mfile;

    document.getElementById('mfile-name').value = tile.dataset.name || '';
    document.getElementById('mfile-alt').value = tile.dataset.alt || '';
    document.getElementById('mfile-folder').value = tile.dataset.folder || '';

    document.getElementById('mfile-name').focus();
    anchorFocus(dialog);
  }

  const fileSave = document.querySelector('[data-mfile-save]');
  if (fileSave) {
    fileSave.addEventListener('click', async () => {
      const name = document.getElementById('mfile-name').value.trim();
      if (name === '') { window.Admin.toast('Give the file a name.', 'error'); return; }

      fileSave.classList.add('btn--loading');

      try {
        const result = await post('/admin/media/' + editingFile + '/update', {
          original_name: name,
          alt_text: document.getElementById('mfile-alt').value.trim(),
          folder_id: document.getElementById('mfile-folder').value
        });
        window.Admin.toast(result.message || 'File saved.', 'success');
        closeDialogs();
        refresh();
      } catch (error) {
        window.Admin.toast(error.message, 'error');
      } finally {
        fileSave.classList.remove('btn--loading');
      }
    });
  }

  /* ---- Folder dialog ---- */
  let editingFolder = null;

  function openFolderDialog(trigger, opener) {
    const dialog = openDialog('folder', opener || trigger);
    if (!dialog) return;

    const nameField   = document.getElementById('mfolder-name');
    const parentField = document.getElementById('mfolder-parent');
    const deleteBtn   = dialog.querySelector('[data-mfolder-delete]');
    const systemNote  = dialog.querySelector('[data-mfolder-system]');
    const purgeWrap   = dialog.querySelector('[data-mfolder-purge-wrap]');
    const purgeBox    = dialog.querySelector('[data-mfolder-purge]');
    const purgeLabel  = dialog.querySelector('[data-mfolder-purge-label]');

    purgeBox.checked = false;
    purgeWrap.classList.add('hidden');

    nameField.disabled = false;
    parentField.disabled = false;
    Array.from(parentField.options).forEach((option) => { option.disabled = false; });

    if (!trigger) {
      editingFolder = null;
      document.getElementById('mfolder-title').textContent = 'New folder';
      nameField.value = '';
      // Default the parent to whichever folder is open.
      parentField.value = /^\d+$/.test(state.folder) ? state.folder : '';
      deleteBtn.classList.add('hidden');
      systemNote.classList.add('hidden');
    } else {
      editingFolder = trigger.dataset.mfolderEdit;
      const isSystem = trigger.dataset.fsystem === '1';
      // Live files, and every file the folder still holds including trashed
      // ones — the server asks for confirmation on the second number, because
      // deleting the folder has to detach the trashed rows too.
      const files = parseInt(trigger.dataset.ffiles, 10) || 0;
      const held = parseInt(trigger.dataset.fheld, 10) || 0;
      const ownPath = trigger.dataset.fpath || '';

      document.getElementById('mfolder-title').textContent = ownPath;
      nameField.value = trigger.dataset.fname || '';
      parentField.value = trigger.dataset.fparent || '';

      // A folder can move nowhere inside its own subtree. The server enforces
      // this too; disabling the options just stops a pointless round trip.
      Array.from(parentField.options).forEach((option) => {
        const path = option.dataset.path || '';
        option.disabled = option.value === editingFolder
          || (ownPath !== '' && path === ownPath)
          || (ownPath !== '' && path.indexOf(ownPath + '/') === 0);
      });

      const kids = parseInt(trigger.dataset.fkids, 10) || 0;

      nameField.disabled = isSystem;
      parentField.disabled = isSystem;
      systemNote.classList.toggle('hidden', !isSystem);
      deleteBtn.classList.toggle('hidden', isSystem);

      // The server refuses this too; saying so up front is kinder.
      deleteBtn.disabled = kids > 0;
      deleteBtn.title = kids > 0
        ? 'Delete or move its ' + kids + ' sub-folder' + (kids === 1 ? '' : 's') + ' first.'
        : '';

      if (!isSystem && held > 0) {
        const trashed = held - files;
        purgeWrap.classList.remove('hidden');

        if (files > 0 && trashed > 0) {
          purgeLabel.textContent = 'Also move its ' + files + ' file' + (files === 1 ? '' : 's')
            + ' to the trash, and unfile the ' + trashed + ' already there';
        } else if (files > 0) {
          purgeLabel.textContent = 'Also move its ' + files + ' file' + (files === 1 ? '' : 's') + ' to the trash';
        } else {
          purgeLabel.textContent = 'Also unfile its ' + trashed + ' file'
            + (trashed === 1 ? '' : 's') + ', already in the trash';
        }
      }
    }

    dialog.querySelector('[data-mfolder-save]').classList.toggle('hidden', nameField.disabled);
    if (!nameField.disabled) nameField.focus();
    anchorFocus(dialog);
  }

  document.addEventListener('click', (event) => {
    const add = event.target.closest('[data-mfolder-new]');
    if (add) { openFolderDialog(null, add); return; }

    const cog = event.target.closest('[data-mfolder-edit]');
    if (cog) openFolderDialog(cog, cog);
  });

  const folderSave = document.querySelector('[data-mfolder-save]');
  if (folderSave) {
    folderSave.addEventListener('click', async () => {
      const name = document.getElementById('mfolder-name').value.trim();
      if (name === '') { window.Admin.toast('Give the folder a name.', 'error'); return; }

      const url = editingFolder ? '/admin/media/folders/' + editingFolder + '/update' : '/admin/media/folders';

      folderSave.classList.add('btn--loading');

      try {
        const result = await post(url, { name: name, parent_id: document.getElementById('mfolder-parent').value });
        window.Admin.toast(result.message || 'Folder saved.', 'success');
        // The tree structure changed, so the whole shell is rebuilt.
        window.setTimeout(() => window.location.assign('/admin/media' + queryString()), 500);
      } catch (error) {
        window.Admin.toast(error.message, 'error');
        folderSave.classList.remove('btn--loading');
      }
    });
  }

  const folderDelete = document.querySelector('[data-mfolder-delete]');
  if (folderDelete) {
    folderDelete.addEventListener('click', async () => {
      if (!editingFolder) return;

      const purgeBox = document.querySelector('[data-mfolder-purge]');
      const purgeWrap = document.querySelector('[data-mfolder-purge-wrap]');

      if (!purgeWrap.classList.contains('hidden') && !purgeBox.checked) {
        window.Admin.toast('Tick the confirmation to delete a folder that still holds files.', 'error');
        return;
      }

      const ok = await window.Admin.confirm({
        title: 'Delete this folder?',
        message: purgeBox.checked
          ? 'The folder goes and its files move to the trash, where they can still be restored.'
          : 'The folder is removed. It holds no files and no sub-folders.',
        confirmLabel: 'Delete folder', tone: 'danger'
      });

      if (!ok) return;

      try {
        const result = await post('/admin/media/folders/' + editingFolder + '/delete', {
          delete_files: purgeBox.checked ? '1' : '0'
        });
        window.Admin.toast(result.message || 'Folder deleted.', 'success');
        window.setTimeout(() => window.location.assign('/admin/media'), 500);
      } catch (error) {
        window.Admin.toast(error.message, 'error');
      }
    });
  }

  /* ---------------------------------------------------------------
     Uploading
     --------------------------------------------------------------- */
  if (canManage && fileInput && dropzone) {
    const queueCard = document.querySelector('[data-mqueue-card]');
    const queueList = document.querySelector('[data-mqueue]');
    const doneLabel = document.querySelector('[data-mqueue-done]');
    const totalLabel = document.querySelector('[data-mqueue-total]');
    const dropTarget = document.querySelector('[data-mdrop-target]');

    let queued = 0;
    let finished = 0;
    let busy = false;

    // Where the next upload will actually land. Files always go to the folder
    // selected in the tree, never to the trash, so this must be read from
    // state.folder rather than from whichever row is highlighted.
    function uploadFolder() {
      return /^\d+$/.test(state.folder) ? state.folder : '';
    }

    function folderLabel() {
      const id = uploadFolder();
      const link = id === ''
        ? null
        : document.querySelector('[data-mfolder="' + id + '"] .mtree__name');

      return link ? link.textContent.trim() : 'Unfiled';
    }

    fileInput.addEventListener('change', () => {
      enqueue(Array.from(fileInput.files));
      fileInput.value = '';
    });

    ['dragenter', 'dragover'].forEach((type) => {
      dropzone.addEventListener(type, (event) => {
        if (!event.dataTransfer || Array.from(event.dataTransfer.types || []).indexOf('Files') === -1) return;
        event.preventDefault();
        if (dropTarget) dropTarget.textContent = 'Into ' + folderLabel();
        dropzone.classList.add('mdrop--over');
      });
    });

    ['dragleave', 'dragend'].forEach((type) => {
      dropzone.addEventListener(type, (event) => {
        if (event.relatedTarget && dropzone.contains(event.relatedTarget)) return;
        dropzone.classList.remove('mdrop--over');
      });
    });

    dropzone.addEventListener('drop', (event) => {
      if (!event.dataTransfer) return;
      event.preventDefault();
      dropzone.classList.remove('mdrop--over');
      enqueue(Array.from(event.dataTransfer.files || []));
    });

    function addRow(file) {
      const row = document.createElement('div');
      row.className = 'mqrow';
      row.dataset.state = 'waiting';

      const name = document.createElement('span');
      name.className = 'mqrow__name';
      name.textContent = file.name;

      const status = document.createElement('span');
      status.className = 'mqrow__state';
      status.textContent = 'Waiting';

      const bar = document.createElement('span');
      bar.className = 'mqrow__bar';
      const fill = document.createElement('span');
      fill.className = 'mqrow__fill';
      bar.appendChild(fill);

      row.append(name, status, bar);
      queueList.appendChild(row);

      return { row: row, status: status };
    }

    async function enqueue(files) {
      if (files.length === 0 || busy) {
        if (busy) window.Admin.toast('Wait for the current upload to finish.', 'info');
        return;
      }

      busy = true;
      queued = files.length;
      finished = 0;
      queueList.innerHTML = '';
      queueCard.classList.remove('hidden');
      totalLabel.textContent = String(queued);
      doneLabel.textContent = '0';

      const targetFolder = uploadFolder();
      let failures = 0;

      for (const file of files) {
        const entry = addRow(file);
        entry.row.dataset.state = 'uploading';
        entry.status.textContent = 'Uploading';

        try {
          const body = new FormData();
          body.append('file', file);
          body.append('folder_id', targetFolder);
          body.append('_csrf', token);

          const response = await fetch('/admin/media/upload', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': token }
          });

          let payload = null;
          try { payload = await response.json(); } catch (e) { payload = null; }

          if (!response.ok || !payload || payload.success === false) {
            throw new Error((payload && payload.message) || 'Upload failed (' + response.status + ').');
          }

          entry.row.dataset.state = 'done';
          entry.status.textContent = 'Uploaded';
        } catch (error) {
          failures++;
          entry.row.dataset.state = 'failed';
          entry.status.textContent = error.message;
        }

        finished++;
        doneLabel.textContent = String(finished);
      }

      busy = false;

      const succeeded = queued - failures;
      if (succeeded > 0) {
        window.Admin.toast(succeeded + ' file' + (succeeded === 1 ? '' : 's') + ' uploaded.', 'success');
      }
      if (failures > 0) {
        window.Admin.toast(failures + ' file' + (failures === 1 ? '' : 's') + ' could not be uploaded.', 'error');
      }

      // Uploading from the trash view would otherwise hide everything that was
      // just added, because the new files are live and the trash is not.
      if (succeeded > 0 && state.state === 'trashed') {
        state.state = 'active';
        state.page = 1;
      }

      refresh();
    }
  }

  syncSelection();
})();
</script>
<?php $this->stop(); ?>

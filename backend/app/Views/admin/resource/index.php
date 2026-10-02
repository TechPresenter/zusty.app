<?php

declare(strict_types=1);

/**
 * Generic resource list.
 *
 * @var \Zuste\Core\View       $this
 * @var \Zuste\Admin\Resource  $resource
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed>    $pagination
 * @var array<string,mixed>    $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$canManage = $this->can($resource->managePermission);

/** Render one table cell according to its declared type. */
$cell = function (array $column, array $row) use ($resource): string {
    $key   = $column['key'];
    $value = $row[$key] ?? null;
    $type  = $column['type'] ?? 'text';

    switch ($type) {
        case 'title':
            $label = $this->e((string) $value);
            $href  = '/admin/' . $resource->key . '/' . (int) $row['id'] . '/edit';

            return '<a href="' . $href . '" style="font-weight:600">' . $label . '</a>';

        case 'mono':
            return '<span class="mono tiny faint">' . $this->e((string) $value) . '</span>';

        case 'number':
            return '<span class="mono">' . $this->number((float) $value) . '</span>';

        case 'boolean':
            return $value
                ? '<span class="chip chip--success"><span class="chip__dot"></span>Yes</span>'
                : '<span class="chip chip--neutral">No</span>';

        case 'chip':
            return $value === null || $value === ''
                ? '<span class="faint">—</span>'
                : '<span class="chip chip--neutral">' . $this->e(ucwords(str_replace('_', ' ', (string) $value))) . '</span>';

        case 'status':
            $tone = match ((string) $value) {
                'published' => 'success',
                'scheduled' => 'info',
                'draft'     => 'warning',
                'archived'  => 'neutral',
                default     => 'neutral',
            };

            return '<span class="chip chip--' . $tone . '"><span class="chip__dot"></span>'
                . $this->e(ucfirst((string) $value)) . '</span>';

        case 'rating':
            return '<span style="font-weight:700;color:var(--primary)">★ ' . (int) $value . '</span>';

        case 'date':
            return '<span class="small muted">' . $this->date($value === null ? null : (string) $value) . '</span>';

        case 'datetime':
            return '<span class="small muted">' . $this->dateTime($value === null ? null : (string) $value) . '</span>';

        case 'thumbnail':
            return $value
                ? '<img src="' . $this->e((string) $value) . '" alt="" loading="lazy" '
                    . 'style="width:68px;height:44px;object-fit:cover;border-radius:8px;background:var(--surface-container)">'
                : '<div style="width:68px;height:44px;border-radius:8px;background:var(--surface-container)"></div>';

        default:
            return $value === null || $value === ''
                ? '<span class="faint">—</span>'
                : '<span class="small">' . $this->e(\Zuste\Helpers\Str::limit((string) $value, 60)) . '</span>';
    }
};

$selects = [];
foreach ($resource->filters as $filter) {
    $selects[] = ['name' => $filter['name'], 'label' => $filter['label'], 'options' => $filter['options']];
}

// Drag-to-reorder.
//
// Six resources declare sortableRows and one of them tells the operator on
// screen to drag rows, but the table had no drag affordance at all — ordering
// could only be changed by opening each row and typing a number. The handle
// only appears while the list is actually ordered by sort_order: dragging a
// list sorted by title or rating would write positions that contradict what is
// on the screen.
$sortBy      = (string) ($filters['sort_by'] ?? $resource->defaultSort);
$sortDir     = strtoupper((string) ($filters['sort_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
$reorderable = $canManage && $resource->sortableRows && $sortBy === 'sort_order';

?>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'selects'           => $selects,
      'showExport'        => true,
      'searchPlaceholder' => 'Search ' . strtolower($resource->plural) . '…',
      'primaryAction'     => ($canManage && $resource->creatable)
          ? ['href' => '/admin/' . $resource->key . '/create', 'label' => 'New ' . strtolower($resource->singular), 'icon' => 'plus']
          : null,
  ]); ?>

  <?php if ($canManage): ?>
    <!-- Bulk action bar, revealed once rows are selected -->
    <form method="post" action="/admin/<?= $this->e($resource->key) ?>/bulk"
          class="toolbar hidden" data-bulk-bar style="background:var(--surface-low)">
      <?= $this->csrf() ?>
      <span class="small" style="font-weight:600">
        <span data-bulk-count>0</span> selected
      </span>

      <select class="select" name="bulk_action" required style="max-width:200px" aria-label="Bulk action">
        <option value="">Choose an action…</option>
        <?php if ($resource->activeColumn !== null): ?>
          <option value="activate">Activate</option>
          <option value="deactivate">Deactivate</option>
        <?php endif; ?>
        <?php if ($resource->featuredColumn !== null): ?>
          <option value="feature">Mark featured</option>
          <option value="unfeature">Remove featured</option>
        <?php endif; ?>
        <?php if ($resource->deletable): ?>
          <option value="delete">Delete</option>
        <?php endif; ?>
      </select>

      <button type="submit" class="btn btn--primary btn--sm"
              data-confirm="This applies to every selected row."
              data-confirm-title="Apply bulk action?" data-confirm-label="Apply" data-confirm-tone="warning">
        Apply
      </button>
    </form>
  <?php endif; ?>

  <?php if ($rows === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon($resource->icon, 'ic ic--lg') ?></div>
      <p class="empty__title">No <?= $this->e(strtolower($resource->plural)) ?> found</p>
      <p class="empty__text"><?= $this->e($resource->emptyMessage) ?></p>
      <?php if ($canManage && $resource->creatable): ?>
        <a href="/admin/<?= $this->e($resource->key) ?>/create" class="btn btn--primary">
          <?= $this->icon('plus', 'ic ic--sm') ?> New <?= $this->e(strtolower($resource->singular)) ?>
        </a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <?php if ($reorderable): ?>
              <th style="width:36px"><span class="reorder-th-label">Reorder</span></th>
            <?php endif; ?>

            <?php if ($canManage): ?>
              <th style="width:44px"><input type="checkbox" data-select-all aria-label="Select all rows"></th>
            <?php endif; ?>

            <?php foreach ($resource->columns as $column): ?>
              <th class="<?= ($column['align'] ?? '') === 'right' ? 'text-right' : '' ?><?= !empty($column['sortable']) ? ' is-sortable' : '' ?>"
                  <?= isset($column['width']) ? 'style="width:' . $this->e($column['width']) . '"' : '' ?>>
                <?php if (!empty($column['sortable'])): ?>
                  <?php
                  $isCurrent = ($filters['sort_by'] ?? $resource->defaultSort) === $column['key'];
                  $nextDir   = ($isCurrent && ($filters['sort_dir'] ?? '') === 'ASC') ? 'DESC' : 'ASC';
                  ?>
                  <a href="<?= $this->queryUrl(['sort_by' => $column['key'], 'sort_dir' => $nextDir]) ?>"
                     style="display:inline-flex;align-items:center;gap:4px">
                    <?= $this->e($column['label']) ?>
                    <?php if ($isCurrent): ?>
                      <?= $this->icon(($filters['sort_dir'] ?? 'ASC') === 'ASC' ? 'arrow-up' : 'arrow-down', 'ic ic--sm') ?>
                    <?php endif; ?>
                  </a>
                <?php else: ?>
                  <?= $this->e($column['label']) ?>
                <?php endif; ?>
              </th>
            <?php endforeach; ?>

            <th class="text-right">Actions</th>
          </tr>
        </thead>

        <tbody<?php if ($reorderable): ?> data-reorder
               data-reorder-url="/admin/<?= $this->e($resource->key) ?>/reorder"
               data-reorder-dir="<?= $this->e($sortDir) ?>"<?php endif; ?>>
          <?php foreach ($rows as $row): ?>
            <tr<?php if ($reorderable): ?> data-row-id="<?= (int) $row['id'] ?>"<?php endif; ?>>
              <?php if ($reorderable): ?>
                <td class="reorder-cell">
                  <span class="reorder-handle" draggable="true" role="button" tabindex="0"
                        title="Drag to reorder" aria-label="Drag to reorder this row">
                    <?= $this->icon('menu', 'ic ic--sm') ?>
                  </span>
                </td>
              <?php endif; ?>

              <?php if ($canManage): ?>
                <td>
                  <input type="checkbox" data-select-row name="ids[]" form="bulk-form-<?= (int) $row['id'] ?>"
                         value="<?= (int) $row['id'] ?>" aria-label="Select row">
                </td>
              <?php endif; ?>

              <?php foreach ($resource->columns as $column): ?>
                <td class="<?= ($column['align'] ?? '') === 'right' ? 'text-right' : '' ?>">
                  <?= $cell($column, $row) ?>
                </td>
              <?php endforeach; ?>

              <td class="actions">
                <div class="row gap-2 row--end">
                  <?php if ($canManage): ?>
                    <a href="/admin/<?= $this->e($resource->key) ?>/<?= (int) $row['id'] ?>/edit"
                       class="btn btn--outline btn--sm">
                      <?= $this->icon('edit', 'ic ic--sm') ?> Edit
                    </a>

                    <?php if ($resource->deletable && empty($row['is_system'])): ?>
                      <form method="post" action="/admin/<?= $this->e($resource->key) ?>/<?= (int) $row['id'] ?>/delete" style="display:inline">
                        <?= $this->csrf() ?>
                        <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error"
                                aria-label="Delete"
                                data-confirm="Deleting &ldquo;<?= $this->e($resource->labelFor($row)) ?>&rdquo; cannot be undone."
                                data-confirm-title="Delete this <?= $this->e(strtolower($resource->singular)) ?>?"
                                data-confirm-label="Delete">
                          <?= $this->icon('trash', 'ic ic--sm') ?>
                        </button>
                      </form>
                    <?php elseif (!empty($row['is_system'])): ?>
                      <span class="chip chip--neutral" title="System records cannot be deleted">System</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="tiny faint">Read only</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($reorderable): ?>
      <p class="tiny muted" style="padding:0 var(--sp-4) var(--sp-3)">
        Drag a row by its handle to change the order. Rows keep the positions this page already
        uses, so reordering here never disturbs the other pages.
      </p>
    <?php endif; ?>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  <?php endif; ?>
</div>

<?php if ($reorderable): ?>
<style>
.reorder-th-label { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
.reorder-cell { width:36px; }
.reorder-handle { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px;
  border-radius:var(--r-sm); color:var(--on-surface-variant); cursor:grab; }
.reorder-handle:hover, .reorder-handle:focus-visible { background:var(--surface-container); color:var(--on-surface); }
tr.is-dragging { opacity:.45; }
tr.is-drop-before td { box-shadow:inset 0 3px 0 0 var(--primary); }
tr.is-drop-after  td { box-shadow:inset 0 -3px 0 0 var(--primary); }
tbody[data-reorder].is-saving { opacity:.6; pointer-events:none; }
</style>
<?php endif; ?>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
// Route every row checkbox into the bulk form, so a selection made anywhere
// in the table submits with the bulk action.
document.addEventListener('DOMContentLoaded', function () {
  const bulkForm = document.querySelector('[data-bulk-bar]');
  if (!bulkForm) return;

  document.querySelectorAll('[data-select-row]').forEach(function (box) {
    box.removeAttribute('form');
    box.addEventListener('change', function () {
      const existing = bulkForm.querySelector('input[name="ids[]"][value="' + box.value + '"]');

      if (box.checked && !existing) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'ids[]';
        hidden.value = box.value;
        bulkForm.appendChild(hidden);
      } else if (!box.checked && existing) {
        existing.remove();
      }
    });
  });
});

// Drag-to-reorder for the resources that declare sortableRows.
//
// HTML5 drag events only — no library. The handle is the drag source so a
// click on a link or a checkbox inside the row still behaves normally. The
// request carries the ids in ASCENDING sort_order, whatever direction the
// table happens to be showing, which is the order /reorder expects.
document.addEventListener('DOMContentLoaded', function () {
  const body = document.querySelector('tbody[data-reorder]');
  if (!body) return;

  const descending = (body.dataset.reorderDir || 'DESC') === 'DESC';
  let dragged = null;
  let target  = null;

  function rowOf(node) {
    return node && node.closest ? node.closest('tr[data-row-id]') : null;
  }

  function clearMarkers() {
    body.querySelectorAll('.is-drop-before, .is-drop-after').forEach(function (tr) {
      tr.classList.remove('is-drop-before', 'is-drop-after');
    });
  }

  function snapshot() {
    return Array.prototype.map.call(body.querySelectorAll('tr[data-row-id]'), function (tr) {
      return parseInt(tr.dataset.rowId, 10);
    });
  }

  let original = snapshot();

  body.addEventListener('dragstart', function (e) {
    const handle = e.target.closest ? e.target.closest('.reorder-handle') : null;
    if (!handle) { e.preventDefault(); return; }

    dragged = rowOf(handle);
    if (!dragged) return;

    original = snapshot();
    dragged.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
    // Firefox refuses to start a drag without payload.
    e.dataTransfer.setData('text/plain', dragged.dataset.rowId);
  });

  body.addEventListener('dragover', function (e) {
    if (!dragged) return;

    const over = rowOf(e.target);
    if (!over || over === dragged) return;

    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    const box    = over.getBoundingClientRect();
    const before = (e.clientY - box.top) < (box.height / 2);

    if (target !== over) clearMarkers();
    target = over;
    over.classList.toggle('is-drop-before', before);
    over.classList.toggle('is-drop-after', !before);
  });

  body.addEventListener('drop', function (e) {
    if (!dragged || !target) return;
    e.preventDefault();

    const before = target.classList.contains('is-drop-before');
    target.parentNode.insertBefore(dragged, before ? target : target.nextSibling);
  });

  body.addEventListener('dragend', function () {
    if (dragged) dragged.classList.remove('is-dragging');
    clearMarkers();
    dragged = null;
    target  = null;
    save();
  });

  function save() {
    const now = snapshot();

    if (now.join(',') === original.join(',')) return;

    // The endpoint reads the ids as "ascending sort_order", so a table drawn
    // newest-first has to be turned back the right way up before sending.
    const payload = descending ? now.slice().reverse() : now.slice();
    const meta    = document.querySelector('meta[name="csrf-token"]');

    body.classList.add('is-saving');

    fetch(body.dataset.reorderUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': meta ? meta.content : ''
      },
      body: JSON.stringify({ order: payload })
    }).then(function (response) {
      return response.json().then(function (data) { return { ok: response.ok, data: data }; });
    }).then(function (result) {
      body.classList.remove('is-saving');

      if (!result.ok || !result.data.success) {
        window.location.reload();
        return;
      }

      original = now;
      if (window.Admin && window.Admin.toast) {
        window.Admin.toast(result.data.message || 'Order saved.', 'success');
      }
    }).catch(function () {
      body.classList.remove('is-saving');
      window.location.reload();
    });
  }
});
</script>
<?php $this->stop(); ?>

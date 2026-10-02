<?php

declare(strict_types=1);

/**
 * The dashboard.
 *
 * Widgets are rendered as empty, correctly-sized shells and then filled by
 * one batched AJAX call. That keeps time-to-first-paint independent of how
 * many aggregate queries the chosen widgets need, and gives every card a
 * real loading skeleton rather than a blank page.
 *
 * @var \Zuste\Core\View $this
 * @var list<array{key:string,size:int,collapsed:bool,widget:array<string,mixed>}> $layout
 * @var array<string,array<string,array<string,mixed>>> $catalogue
 * @var list<array<string,mixed>> $layouts
 */

$this->extend('admin.layouts.app');
$this->start('content');

// Icons used by client-rendered widgets are inlined once as templates, so
// widgets.js can clone them instead of shipping a second icon library.
$iconNames = ['users', 'star', 'calendar', 'wallet', 'card', 'chart', 'trending-up', 'check-circle',
              'alert', 'shield', 'headset', 'flag', 'inbox', 'megaphone', 'file', 'image', 'grid',
              'database', 'refresh', 'bell', 'chat', 'user-check', 'key', 'external', 'filter',
              'plus', 'x', 'check', 'chevron-right', 'settings', 'upload', 'eye', 'dashboard', 'edit'];

?>

<!-- Icon sprite for the client renderer -->
<div hidden>
  <?php foreach ($iconNames as $name): ?>
    <template id="icon-<?= $this->e($name) ?>"><?php
      // Strip the wrapper so only the path data is cloned.
      $svg = $this->icon($name);
      echo preg_replace('#^<svg[^>]*>|</svg>$#', '', $svg);
    ?></template>
  <?php endforeach; ?>
</div>

<!-- Shell cloned when a widget is added from the picker -->
<template id="widget-shell">
  <div class="wgrid__item" style="--span:3">
    <div class="widget is-loading" data-widget="">
      <div class="widget__head">
        <span class="widget__drag" data-edit-only><?= $this->icon('menu', 'ic ic--sm') ?></span>
        <span class="widget__title">Loading…</span>
        <span class="widget__tools">
          <button class="btn btn--ghost btn--icon btn--sm" data-widget-remove aria-label="Remove">
            <?= $this->icon('x', 'ic ic--sm') ?>
          </button>
        </span>
      </div>
      <div class="widget__body" data-widget-body>
        <div class="wskeleton">
          <div class="wskeleton__line" style="width:70%"></div>
          <div class="wskeleton__line" style="width:90%"></div>
          <div class="wskeleton__line" style="width:55%"></div>
        </div>
      </div>
    </div>
  </div>
</template>

<!-- ===================== Toolbar ===================== -->
<div class="wtoolbar">
  <button class="btn btn--outline btn--sm" data-toggle-edit>Customise</button>

  <button class="btn btn--outline btn--sm hidden" data-edit-only onclick="document.getElementById('widget-picker').classList.remove('hidden')">
    <?= $this->icon('plus', 'ic ic--sm') ?> Add widget
  </button>

  <button class="btn btn--outline btn--sm hidden" data-edit-only data-save-layout>
    <?= $this->icon('check', 'ic ic--sm') ?> Save layout
  </button>

  <button class="btn btn--ghost btn--sm hidden" data-edit-only data-reset-layout>
    <?= $this->icon('refresh', 'ic ic--sm') ?> Reset
  </button>

  <span class="wtoolbar__spacer"></span>

  <span class="tiny faint hidden" data-edit-only>
    Drag to reorder · click <?= $this->icon('grid', 'ic ic--sm') ?> to resize
  </span>
</div>

<!-- ===================== Widget grid ===================== -->
<div class="wgrid">
  <?php foreach ($layout as $entry): ?>
    <?php
    $key    = $entry['key'];
    $widget = $entry['widget'];
    $isStat = $widget['type'] === 'stat';
    $tag    = ($isStat && $widget['link'] !== '') ? 'a' : 'div';

    // Only offer Export where the widget actually has something to export.
    //
    // WidgetController::flattenForExport() understands columns/rows, series,
    // bars, segments, items, stages and a single KPI value. The three personal
    // widget types below return `actions`, `tasks` and `notes` instead, which
    // it cannot read, so the endpoint always bounced back with "This widget has
    // no tabular data to export." — a button that could never work.
    $canExport = !in_array($widget['type'], ['actions', 'todo', 'notes'], true);
    ?>
    <div class="wgrid__item" data-widget-key="<?= $this->e($key) ?>" style="--span:<?= (int) $entry['size'] ?>">

      <?php if ($isStat): ?>
        <!-- KPI card: the whole card is the click target -->
        <<?= $tag ?>
          class="kpi is-loading kpi--skeleton"
          data-widget="<?= $this->e($key) ?>"
          style="--tone:<?= $this->e($widget['tone']) ?>"
          <?= $widget['link'] !== '' ? 'href="' . $this->e($widget['link']) . '"' : '' ?>
          <?= $widget['link'] !== '' ? 'title="Open ' . $this->e($widget['title']) . '"' : '' ?>>

          <div class="kpi__head"><div class="kpi__icon"><?= $this->icon($widget['icon']) ?></div></div>
          <div class="kpi__value">—</div>
          <div class="kpi__label"><?= $this->e($widget['title']) ?></div>
          <div class="kpi__foot"></div>
        </<?= $tag ?>>

        <?php if (true): ?>
          <!-- Edit affordances sit outside the anchor so they stay clickable -->
          <div class="row gap-1 hidden" data-edit-only style="position:absolute;top:6px;right:6px;z-index:2">
            <button class="btn btn--ghost btn--icon btn--sm" data-widget-resize aria-label="Resize">
              <?= $this->icon('grid', 'ic ic--sm') ?>
            </button>
            <button class="btn btn--ghost btn--icon btn--sm text-error" data-widget-remove aria-label="Remove">
              <?= $this->icon('x', 'ic ic--sm') ?>
            </button>
          </div>
        <?php endif; ?>

      <?php else: ?>
        <!-- Panel widget -->
        <div class="widget is-loading<?= $entry['collapsed'] ? ' is-collapsed' : '' ?>"
             data-widget="<?= $this->e($key) ?>"
             style="--tone:<?= $this->e($widget['tone']) ?>">

          <div class="widget__head">
            <span class="widget__drag hidden" data-edit-only><?= $this->icon('menu', 'ic ic--sm') ?></span>
            <span class="widget__icon"><?= $this->icon($widget['icon']) ?></span>
            <span class="widget__title truncate"><?= $this->e($widget['title']) ?></span>

            <span class="widget__tools">
              <button class="btn btn--ghost btn--icon btn--sm" data-widget-refresh="<?= $this->e($key) ?>"
                      aria-label="Refresh" title="Refresh">
                <?= $this->icon('refresh', 'ic ic--sm') ?>
              </button>

              <?php if ($canExport && $this->can('export.data')): ?>
                <a class="btn btn--ghost btn--icon btn--sm" href="/admin/widgets/<?= $this->e($key) ?>/export?format=xlsx"
                   aria-label="Export" title="Export to Excel">
                  <?= $this->icon('download', 'ic ic--sm') ?>
                </a>
              <?php endif; ?>

              <button class="btn btn--ghost btn--icon btn--sm" data-widget-collapse
                      aria-label="Collapse" title="Collapse">
                <?= $this->icon('chevron-down', 'ic ic--sm') ?>
              </button>

              <button class="btn btn--ghost btn--icon btn--sm hidden" data-edit-only data-widget-resize
                      aria-label="Resize" title="Resize">
                <?= $this->icon('grid', 'ic ic--sm') ?>
              </button>

              <button class="btn btn--ghost btn--icon btn--sm text-error hidden" data-edit-only data-widget-remove
                      aria-label="Remove" title="Remove">
                <?= $this->icon('x', 'ic ic--sm') ?>
              </button>
            </span>
          </div>

          <div class="widget__body<?= in_array($widget['type'], ['table'], true) ? ' widget__body--flush' : '' ?>"
               data-widget-body>
            <div class="wskeleton">
              <div class="wskeleton__line" style="width:70%"></div>
              <div class="wskeleton__line" style="width:90%"></div>
              <div class="wskeleton__line" style="width:55%"></div>
              <div class="wskeleton__line" style="width:80%"></div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($layout === []): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__art"><?= $this->icon('dashboard', 'ic ic--lg') ?></div>
      <p class="empty__title">Your dashboard is empty</p>
      <p class="empty__text">Add the widgets that matter to your role.</p>
      <button class="btn btn--primary" onclick="document.getElementById('widget-picker').classList.remove('hidden')">
        <?= $this->icon('plus', 'ic ic--sm') ?> Add a widget
      </button>
    </div>
  </div>
<?php endif; ?>

<!-- ===================== Widget picker ===================== -->
<div id="widget-picker" class="dialog-backdrop hidden">
  <div class="dialog dialog--wide">
    <div class="dialog__head">
      <div class="row row--between">
        <div>
          <h2 class="h2">Add a widget</h2>
          <p class="muted small mt-1">Only widgets your role can see are listed.</p>
        </div>
        <button class="btn btn--ghost btn--icon" aria-label="Close"
                onclick="document.getElementById('widget-picker').classList.add('hidden')">
          <?= $this->icon('x') ?>
        </button>
      </div>
    </div>

    <div class="dialog__body">
      <div class="field">
        <input class="input" id="widget-search" type="search" placeholder="Search widgets…" aria-label="Search widgets">
      </div>

      <div class="wpicker">
        <?php foreach ($catalogue as $groupName => $widgets): ?>
          <div class="wpicker__group" data-picker-group>
            <div class="label mb-2"><?= $this->e($groupName) ?></div>
            <div class="wpicker__grid">
              <?php foreach ($widgets as $key => $widget): ?>
                <div class="wpicker__item" data-add-widget="<?= $this->e($key) ?>"
                     data-widget-size="<?= (int) $widget['size'] ?>"
                     data-search="<?= $this->e(strtolower((string) $widget['title'] . ' ' . $groupName)) ?>"
                     role="button" tabindex="0">
                  <span class="wpicker__dot" style="background:<?= $this->e($widget['tone']) ?>"></span>
                  <span class="truncate"><?= $this->e($widget['title']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="dialog__foot">
      <button class="btn btn--outline" onclick="document.getElementById('widget-picker').classList.add('hidden')">Done</button>
      <button class="btn btn--primary" data-save-layout
              onclick="document.getElementById('widget-picker').classList.add('hidden')">Save layout</button>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<link rel="stylesheet" href="/admin/assets/css/widgets.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<script src="/admin/assets/js/widgets.js?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>"></script>
<script>
// Widget picker: filter, keyboard support, backdrop dismiss.
(function () {
  const picker = document.getElementById('widget-picker');
  const search = document.getElementById('widget-search');

  if (search) {
    search.addEventListener('input', function () {
      const term = search.value.trim().toLowerCase();

      document.querySelectorAll('[data-search]').forEach(function (item) {
        item.classList.toggle('hidden', term !== '' && !item.dataset.search.includes(term));
      });

      // Hide a group heading once every widget inside it is filtered out.
      document.querySelectorAll('[data-picker-group]').forEach(function (group) {
        const visible = group.querySelectorAll('[data-search]:not(.hidden)').length;
        group.classList.toggle('hidden', visible === 0);
      });
    });
  }

  document.querySelectorAll('[data-add-widget]').forEach(function (item) {
    item.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); item.click(); }
    });
  });

  if (picker) {
    picker.addEventListener('click', function (e) { if (e.target === picker) picker.classList.add('hidden'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') picker.classList.add('hidden'); });
  }
})();
</script>
<?php $this->stop(); ?>

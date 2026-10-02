<?php

declare(strict_types=1);

/**
 * Menu builder: a two-level drag-and-drop tree, an item editor and a live
 * preview of how the menu will render.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $menu
 * @var list<array{row:array<string,mixed>,children:list<array<string,mixed>>}> $tree
 * @var list<array<string,mixed>> $items
 * @var int $repaired
 * @var array<int,int> $childCounts
 * @var array<string,mixed>|null $editItem
 * @var bool $editHasChildren
 * @var list<array<string,mixed>> $parentOptions
 * @var array<string,string> $locations
 * @var array<string,string> $linkTypes
 * @var array<string,string> $audiences
 * @var list<array<string,mixed>> $pages
 * @var list<array<string,mixed>> $categories
 * @var bool $canManage
 */

$this->extend('admin.layouts.app');
$this->start('content');

$menuId = (int) $menu['id'];

$pageTitles = [];
foreach ($pages as $page) {
    $pageTitles[(string) $page['slug']] = (string) $page['title'];
}

$categoryNames = [];
foreach ($categories as $category) {
    $categoryNames[(string) $category['id']] = (string) $category['name'];
}

/** One-line description of where an item points. */
$linkSummary = function (array $row) use ($linkTypes, $pageTitles, $categoryNames): string {
    $type  = (string) $row['link_type'];
    $value = (string) ($row['link_value'] ?? '');

    return match ($type) {
        'none'     => 'No link',
        'page'     => 'Page · ' . ($pageTitles[$value] ?? $value . ' (missing)'),
        'category' => 'Category · ' . ($categoryNames[$value] ?? $value . ' (missing)'),
        'url'      => 'Link · ' . $value,
        default    => ($linkTypes[$type] ?? $type) . ' · ' . $value,
    };
};

/** The data-* payload every tree node and the preview both read. */
$nodeData = function (array $row) use ($linkSummary): string {
    return implode(' ', [
        'data-id="' . (int) $row['id'] . '"',
        'data-label="' . $this->e($row['label']) . '"',
        'data-icon="' . $this->e((string) ($row['icon'] ?? '')) . '"',
        'data-badge="' . $this->e((string) ($row['badge_text'] ?? '')) . '"',
        'data-badge-color="' . $this->e((string) ($row['badge_color'] ?? '')) . '"',
        'data-audience="' . $this->e($row['audience']) . '"',
        'data-active="' . ((int) $row['is_active'] === 1 ? '1' : '0') . '"',
        'data-external="' . ((int) $row['open_external'] === 1 ? '1' : '0') . '"',
        'data-summary="' . $this->e($linkSummary($row)) . '"',
    ]);
};

$audienceLabel = function (string $key) use ($audiences): string {
    return $audiences[$key] ?? $key;
};

// Icon previews: the apps render Material Symbols, which the panel does not
// ship. Well-known names are mapped onto the admin icon set; anything else
// falls back to a lettered tile with the raw name in its tooltip.
$materialMap = [
    'home' => 'dashboard', 'dashboard' => 'dashboard', 'space_dashboard' => 'dashboard',
    'calendar_today' => 'calendar', 'calendar_month' => 'calendar', 'event' => 'calendar',
    'account_balance_wallet' => 'wallet', 'wallet' => 'wallet', 'savings' => 'wallet',
    'notifications' => 'bell', 'notifications_active' => 'bell',
    'bookmark' => 'star', 'favorite' => 'star', 'star' => 'star', 'grade' => 'star',
    'person_add' => 'user-check', 'how_to_reg' => 'user-check', 'verified' => 'check-circle',
    'group' => 'users', 'people' => 'users', 'groups' => 'users', 'person' => 'users',
    'settings' => 'settings', 'tune' => 'settings', 'manage_accounts' => 'settings',
    'logout' => 'logout', 'login' => 'logout',
    'search' => 'search', 'travel_explore' => 'search',
    'help_outline' => 'info', 'help' => 'info', 'info' => 'info', 'quiz' => 'info',
    'description' => 'file', 'article' => 'file', 'receipt_long' => 'file', 'gavel' => 'file',
    'image' => 'image', 'photo' => 'image', 'collections' => 'image',
    'credit_card' => 'card', 'payments' => 'card', 'account_balance' => 'card',
    'support_agent' => 'headset', 'headset_mic' => 'headset',
    'privacy_tip' => 'shield', 'shield' => 'shield', 'lock' => 'key', 'admin_panel_settings' => 'shield',
    'campaign' => 'megaphone', 'local_offer' => 'megaphone',
    'chat_bubble' => 'chat', 'chat' => 'chat', 'forum' => 'chat',
    'language' => 'external', 'open_in_new' => 'external', 'share' => 'external', 'public' => 'external',
    'storefront' => 'grid', 'category' => 'grid', 'grid_view' => 'grid', 'apps' => 'grid',
    'trending_up' => 'trending-up', 'insights' => 'chart', 'bar_chart' => 'chart', 'analytics' => 'chart',
    'inbox' => 'inbox', 'mail' => 'inbox', 'mark_email_unread' => 'inbox',
    'flag' => 'flag', 'report' => 'flag', 'key' => 'key', 'vpn_key' => 'key',
    'dark_mode' => 'moon', 'menu' => 'menu', 'upload' => 'upload', 'download' => 'download',
    'delete' => 'trash', 'edit' => 'edit', 'visibility' => 'eye', 'refresh' => 'refresh',
    'storage' => 'database', 'shopping_bag' => 'grid', 'work' => 'grid',
];

// Ship only the icons the map can actually reach, plus the two the preview
// draws itself.
$iconSvg = [];
foreach (array_unique(array_merge(array_values($materialMap), ['chevron-right', 'external'])) as $name) {
    $iconSvg[$name] = $this->icon($name, 'ic ic--sm');
}

// Cast every lookup to an object: a map keyed by category id would otherwise
// encode as a JSON array whenever the ids happen to start at zero.
$config = [
    'menuId'     => $menuId,
    'saveUrl'    => '/admin/menus/' . $menuId . '/tree',
    'canManage'  => $canManage,
    'icons'      => (object) $iconSvg,
    'material'   => (object) $materialMap,
    'linkTypes'  => (object) $linkTypes,
    'audiences'  => (object) $audiences,
    'pages'      => (object) $pageTitles,
    'categories' => (object) $categoryNames,
];

$editId       = $editItem !== null ? (int) $editItem['id'] : 0;
$formAction   = $editId > 0
    ? '/admin/menus/' . $menuId . '/items/' . $editId
    : '/admin/menus/' . $menuId . '/items';

$currentType  = (string) $this->old('link_type', $editItem['link_type'] ?? 'screen');
$currentValue = (string) ($editItem['link_value'] ?? '');
$oldValue     = \Zuste\Core\Session::oldInput('link_value', null);
if ($oldValue !== null) {
    $currentValue = (string) $oldValue;
}

?>

<div class="row row--between row--wrap gap-3 mb-4">
  <a href="/admin/menus" class="btn btn--ghost btn--sm">
    <?= $this->icon('chevron-left', 'ic ic--sm') ?> All menus
  </a>

  <div class="row gap-2 row--wrap">
    <span class="chip chip--<?= (int) $menu['is_active'] === 1 ? 'success' : 'neutral' ?>">
      <span class="chip__dot"></span><?= (int) $menu['is_active'] === 1 ? 'Active' : 'Switched off' ?>
    </span>
    <span class="chip chip--info"><?= $this->e($locations[$menu['location']] ?? $menu['location']) ?></span>
    <span class="chip chip--neutral mono"><?= $this->e($menu['slug']) ?></span>
  </div>
</div>

<?php if ($repaired > 0): ?>
  <div class="callout callout--warning mb-4">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong><?= (int) $repaired ?> item<?= $repaired === 1 ? '' : 's' ?> sat deeper than two levels.</strong>
      They are shown at the top level here. Saving the layout will store them that way.
    </div>
  </div>
<?php endif; ?>

<?php if (!$canManage): ?>
  <div class="callout callout--info mb-4">
    <?= $this->icon('info', 'ic ic--sm') ?>
    <div>You can read this menu but not change it. Editing needs the <code class="mono">cms.pages</code> permission.</div>
  </div>
<?php endif; ?>

<div class="grid grid--sidebar" data-menu-config="<?= $this->json($config) ?>">

  <!-- ================= Left column ================= -->
  <div class="col gap-4">

    <div class="card">
      <div class="card__head">
        <div>
          <h2 class="h3">Structure</h2>
          <p class="tiny muted">Drag a row to reorder it, or drop it onto another row to nest it.</p>
        </div>

        <?php if ($canManage): ?>
          <div class="row gap-2">
            <span class="chip chip--warning hidden" data-dirty-flag>
              <span class="chip__dot"></span>Unsaved layout
            </span>
            <button type="button" class="btn btn--ghost btn--sm hidden" data-revert>Discard</button>
            <button type="button" class="btn btn--primary btn--sm" data-save-tree disabled>
              <?= $this->icon('check', 'ic ic--sm') ?> Save layout
            </button>
          </div>
        <?php endif; ?>
      </div>

      <div class="card__body">
        <?php if ($tree === []): ?>
          <div class="empty">
            <div class="empty__art"><?= $this->icon('menu', 'ic ic--lg') ?></div>
            <p class="empty__title">This menu is empty</p>
            <p class="empty__text">Add the first item with the form below.</p>
          </div>
        <?php else: ?>
          <ul class="mnode-tree" data-tree>
            <?php foreach ($tree as $branch): ?>
              <?php $row = $branch['row']; ?>
              <li class="mnode mnode--root" <?= $nodeData($row) ?> <?= $canManage ? 'draggable="true"' : '' ?>>
                <div class="mnode__row">
                  <span class="mnode__grip" aria-hidden="true"><?= $this->icon('menu', 'ic ic--sm') ?></span>

                  <span class="mnode__body">
                    <span class="mnode__label"><?= $this->e($row['label']) ?></span>
                    <span class="mnode__meta"><?= $this->e($linkSummary($row)) ?></span>
                  </span>

                  <span class="mnode__chips">
                    <?php if ((int) $row['is_active'] !== 1): ?>
                      <span class="chip chip--neutral">Hidden</span>
                    <?php endif; ?>
                    <?php if ($row['audience'] !== 'all'): ?>
                      <span class="chip chip--info"><?= $this->e($audienceLabel((string) $row['audience'])) ?></span>
                    <?php endif; ?>
                    <?php if (($row['badge_text'] ?? '') !== ''): ?>
                      <span class="chip chip--warning"><?= $this->e($row['badge_text']) ?></span>
                    <?php endif; ?>
                  </span>

                  <span class="mnode__tools" data-no-drag>
                    <?php if ($canManage): ?>
                      <button type="button" class="btn btn--ghost btn--icon btn--sm" data-move="up" aria-label="Move up" title="Move up">
                        <?= $this->icon('arrow-up', 'ic ic--sm') ?>
                      </button>
                      <button type="button" class="btn btn--ghost btn--icon btn--sm" data-move="down" aria-label="Move down" title="Move down">
                        <?= $this->icon('arrow-down', 'ic ic--sm') ?>
                      </button>
                      <button type="button" class="btn btn--ghost btn--icon btn--sm" data-move="in" aria-label="Nest under the row above" title="Nest under the row above">
                        <?= $this->icon('chevron-right', 'ic ic--sm') ?>
                      </button>
                      <button type="button" class="btn btn--ghost btn--icon btn--sm" data-move="out" aria-label="Move to the top level" title="Move to the top level">
                        <?= $this->icon('chevron-left', 'ic ic--sm') ?>
                      </button>
                    <?php endif; ?>

                    <a href="/admin/menus/<?= $menuId ?>?item=<?= (int) $row['id'] ?>"
                       class="btn btn--ghost btn--icon btn--sm" draggable="false" aria-label="Edit item" title="Edit item">
                      <?= $this->icon('edit', 'ic ic--sm') ?>
                    </a>

                    <?php if ($canManage): ?>
                      <form method="post" action="/admin/menus/<?= $menuId ?>/items/<?= (int) $row['id'] ?>/delete" style="display:inline">
                        <?= $this->csrf() ?>
                        <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error" aria-label="Delete item"
                                title="Delete item"
                                data-confirm="&ldquo;<?= $this->e($row['label']) ?>&rdquo;<?= ($childCounts[(int) $row['id']] ?? 0) > 0 ? ' and its ' . (int) $childCounts[(int) $row['id']] . ' child item(s)' : '' ?> will be removed."
                                data-confirm-title="Delete this item?" data-confirm-label="Delete">
                          <?= $this->icon('trash', 'ic ic--sm') ?>
                        </button>
                      </form>
                    <?php endif; ?>
                  </span>
                </div>

                <ul class="mnode__kids" data-kids>
                  <?php foreach ($branch['children'] as $child): ?>
                    <li class="mnode mnode--child" <?= $nodeData($child) ?> <?= $canManage ? 'draggable="true"' : '' ?>>
                      <div class="mnode__row">
                        <span class="mnode__grip" aria-hidden="true"><?= $this->icon('menu', 'ic ic--sm') ?></span>

                        <span class="mnode__body">
                          <span class="mnode__label"><?= $this->e($child['label']) ?></span>
                          <span class="mnode__meta"><?= $this->e($linkSummary($child)) ?></span>
                        </span>

                        <span class="mnode__chips">
                          <?php if ((int) $child['is_active'] !== 1): ?>
                            <span class="chip chip--neutral">Hidden</span>
                          <?php endif; ?>
                          <?php if ($child['audience'] !== 'all'): ?>
                            <span class="chip chip--info"><?= $this->e($audienceLabel((string) $child['audience'])) ?></span>
                          <?php endif; ?>
                          <?php if (($child['badge_text'] ?? '') !== ''): ?>
                            <span class="chip chip--warning"><?= $this->e($child['badge_text']) ?></span>
                          <?php endif; ?>
                        </span>

                        <span class="mnode__tools" data-no-drag>
                          <?php if ($canManage): ?>
                            <button type="button" class="btn btn--ghost btn--icon btn--sm" data-move="up" aria-label="Move up" title="Move up">
                              <?= $this->icon('arrow-up', 'ic ic--sm') ?>
                            </button>
                            <button type="button" class="btn btn--ghost btn--icon btn--sm" data-move="down" aria-label="Move down" title="Move down">
                              <?= $this->icon('arrow-down', 'ic ic--sm') ?>
                            </button>
                            <button type="button" class="btn btn--ghost btn--icon btn--sm" data-move="in" aria-label="Nest under the row above" title="Nest under the row above">
                              <?= $this->icon('chevron-right', 'ic ic--sm') ?>
                            </button>
                            <button type="button" class="btn btn--ghost btn--icon btn--sm" data-move="out" aria-label="Move to the top level" title="Move to the top level">
                              <?= $this->icon('chevron-left', 'ic ic--sm') ?>
                            </button>
                          <?php endif; ?>

                          <a href="/admin/menus/<?= $menuId ?>?item=<?= (int) $child['id'] ?>"
                             class="btn btn--ghost btn--icon btn--sm" draggable="false" aria-label="Edit item" title="Edit item">
                            <?= $this->icon('edit', 'ic ic--sm') ?>
                          </a>

                          <?php if ($canManage): ?>
                            <form method="post" action="/admin/menus/<?= $menuId ?>/items/<?= (int) $child['id'] ?>/delete" style="display:inline">
                              <?= $this->csrf() ?>
                              <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error" aria-label="Delete item"
                                      title="Delete item"
                                      data-confirm="&ldquo;<?= $this->e($child['label']) ?>&rdquo; will be removed."
                                      data-confirm-title="Delete this item?" data-confirm-label="Delete">
                                <?= $this->icon('trash', 'ic ic--sm') ?>
                              </button>
                            </form>
                          <?php endif; ?>
                        </span>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($canManage): ?>
      <div class="card" id="item-editor">
        <div class="card__head">
          <h2 class="h3"><?= $editId > 0 ? 'Edit item' : 'Add an item' ?></h2>
          <?php if ($editId > 0): ?>
            <a href="/admin/menus/<?= $menuId ?>" class="btn btn--ghost btn--sm">
              <?= $this->icon('x', 'ic ic--sm') ?> Cancel
            </a>
          <?php endif; ?>
        </div>

        <div class="card__body">
          <form method="post" action="<?= $this->e($formAction) ?>" data-item-form
                <?= $editId > 0 ? 'data-item-id="' . $editId . '"' : '' ?>>
            <?= $this->csrf() ?>

            <div class="grid grid--2">
              <div class="field">
                <label class="field__label req" for="item_label">Label</label>
                <input class="input" id="item_label" name="label" required maxlength="100"
                       value="<?= $this->old('label', $editItem['label'] ?? '') ?>" placeholder="My Bookings"
                       <?= $this->hasError('label') ? 'aria-invalid="true"' : '' ?>>
                <?php if ($this->hasError('label')): ?>
                  <p class="field__error"><?= $this->error('label') ?></p>
                <?php endif; ?>
              </div>

              <div class="field">
                <label class="field__label" for="item_icon">Icon</label>
                <div class="row gap-2">
                  <span class="mprev__icon" data-icon-preview aria-hidden="true"></span>
                  <input class="input mono flex-1" id="item_icon" name="icon" maxlength="64" list="material-icons"
                         value="<?= $this->old('icon', $editItem['icon'] ?? '') ?>" placeholder="calendar_today"
                         <?= $this->hasError('icon') ? 'aria-invalid="true"' : '' ?>>
                </div>
                <datalist id="material-icons">
                  <?php foreach (array_keys($materialMap) as $name): ?>
                    <option value="<?= $this->e($name) ?>"></option>
                  <?php endforeach; ?>
                </datalist>
                <p class="field__hint">A Material Symbols name — that is what the apps draw.</p>
                <?php if ($this->hasError('icon')): ?>
                  <p class="field__error"><?= $this->error('icon') ?></p>
                <?php endif; ?>
              </div>

              <div class="field">
                <label class="field__label req" for="item_link_type">Link type</label>
                <select class="select" id="item_link_type" name="link_type" required>
                  <?php foreach ($linkTypes as $value => $label): ?>
                    <option value="<?= $this->e($value) ?>" <?= $currentType === $value ? 'selected' : '' ?>>
                      <?= $this->e($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label class="field__label" for="item_link_screen">Destination</label>

                <?php // Only the control for the chosen type is enabled, so exactly one
                      // link_value is ever submitted — the script re-points this as the
                      // type changes, and without scripting the markup is already right. ?>
                <input class="input mono<?= $currentType === 'screen' ? ' is-shown' : '' ?>"
                       id="item_link_screen" name="link_value" maxlength="255"
                       data-link-control="screen" placeholder="/bookings"
                       value="<?= $currentType === 'screen' ? $this->e($currentValue) : '' ?>"
                       <?= $currentType === 'screen' ? '' : 'disabled' ?>>

                <select class="select<?= $currentType === 'page' ? ' is-shown' : '' ?>"
                        id="item_link_page" name="link_value" data-link-control="page"
                        <?= $currentType === 'page' ? '' : 'disabled' ?>>
                  <option value="">Choose a page…</option>
                  <?php foreach ($pages as $page): ?>
                    <option value="<?= $this->e($page['slug']) ?>"
                      <?= $currentType === 'page' && $currentValue === (string) $page['slug'] ? 'selected' : '' ?>>
                      <?= $this->e($page['title']) ?><?= (int) $page['is_active'] === 1 ? '' : ' (inactive)' ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <select class="select<?= $currentType === 'category' ? ' is-shown' : '' ?>"
                        id="item_link_category" name="link_value" data-link-control="category"
                        <?= $currentType === 'category' ? '' : 'disabled' ?>>
                  <option value="">Choose a category…</option>
                  <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"
                      <?= $currentType === 'category' && $currentValue === (string) $category['id'] ? 'selected' : '' ?>>
                      <?= $this->e($category['name']) ?><?= (int) $category['is_active'] === 1 ? '' : ' (inactive)' ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <input class="input<?= $currentType === 'url' ? ' is-shown' : '' ?>"
                       id="item_link_url" name="link_value" type="url" maxlength="255"
                       data-link-control="url" placeholder="https://zustee.com/help"
                       value="<?= $currentType === 'url' ? $this->e($currentValue) : '' ?>"
                       <?= $currentType === 'url' ? '' : 'disabled' ?>>

                <p class="field__hint" data-link-hint></p>
                <?php if ($this->hasError('link_value')): ?>
                  <p class="field__error"><?= $this->error('link_value') ?></p>
                <?php endif; ?>
              </div>

              <div class="field">
                <label class="field__label" for="item_parent">Sits under</label>
                <select class="select" id="item_parent" name="parent_id" <?= $editHasChildren ? 'disabled' : '' ?>>
                  <option value="0">Top level</option>
                  <?php foreach ($parentOptions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"
                      <?= (int) ($editItem['parent_id'] ?? 0) === (int) $option['id'] ? 'selected' : '' ?>>
                      <?= $this->e($option['label']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="field__hint">
                  <?= $editHasChildren
                      ? 'This item has children of its own, so it stays at the top level.'
                      : 'Only top-level items can be parents.' ?>
                </p>
                <?php if ($this->hasError('parent_id')): ?>
                  <p class="field__error"><?= $this->error('parent_id') ?></p>
                <?php endif; ?>
              </div>

              <div class="field">
                <label class="field__label" for="item_audience">Audience</label>
                <select class="select" id="item_audience" name="audience" required>
                  <?php foreach ($audiences as $value => $label): ?>
                    <option value="<?= $this->e($value) ?>"
                      <?= (string) $this->old('audience', $editItem['audience'] ?? 'all') === $value ? 'selected' : '' ?>>
                      <?= $this->e($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label class="field__label" for="item_badge_text">Badge</label>
                <input class="input" id="item_badge_text" name="badge_text" maxlength="20"
                       value="<?= $this->old('badge_text', $editItem['badge_text'] ?? '') ?>" placeholder="New">
                <p class="field__hint">Up to 20 characters. Leave blank for no badge.</p>
              </div>

              <div class="field">
                <label class="field__label" for="item_badge_color">Badge colour</label>
                <div class="row gap-2">
                  <input type="color" data-color-picker value="<?= $this->e((string) ($editItem['badge_color'] ?? '#B80049')) ?>"
                         aria-label="Pick a badge colour"
                         style="width:52px;height:42px;border:1px solid var(--outline-variant);border-radius:var(--r-md);background:none;cursor:pointer;padding:3px">
                  <input class="input mono flex-1" id="item_badge_color" name="badge_color" maxlength="7"
                         value="<?= $this->old('badge_color', $editItem['badge_color'] ?? '') ?>" placeholder="#B80049">
                </div>
                <?php if ($this->hasError('badge_color')): ?>
                  <p class="field__error"><?= $this->error('badge_color') ?></p>
                <?php endif; ?>
              </div>
            </div>

            <div class="row gap-4 row--wrap mt-4">
              <label class="switch">
                <input type="checkbox" id="item_is_active" name="is_active" value="1"
                       <?= $editItem === null || (int) $editItem['is_active'] === 1 ? 'checked' : '' ?>>
                <span class="switch__track"></span>
                <span class="small">Visible</span>
              </label>

              <label class="switch">
                <input type="checkbox" id="item_open_external" name="open_external" value="1"
                       <?= $editItem !== null && (int) $editItem['open_external'] === 1 ? 'checked' : '' ?>>
                <span class="switch__track"></span>
                <span class="small">Open outside the app</span>
              </label>
            </div>

            <div class="row gap-2 mt-4">
              <button type="submit" class="btn btn--primary">
                <?= $this->icon($editId > 0 ? 'check' : 'plus', 'ic ic--sm') ?>
                <?= $editId > 0 ? 'Save item' : 'Add item' ?>
              </button>
              <?php if ($editId > 0): ?>
                <a href="/admin/menus/<?= $menuId ?>" class="btn btn--outline">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- ================= Right column ================= -->
  <div class="col gap-4">

    <div class="card">
      <div class="card__head">
        <h2 class="h3">Preview</h2>
        <span class="tiny muted">Live</span>
      </div>
      <div class="card__body">
        <div class="mprev" data-preview></div>
        <p class="tiny faint mt-3">
          Icons are Material Symbols names; the panel shows its own icon where the name is recognised
          and a lettered tile otherwise.
        </p>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2 class="h3">Menu settings</h2></div>
      <div class="card__body">
        <form method="post" action="/admin/menus/<?= $menuId ?>" class="grid">
          <?= $this->csrf() ?>

          <div class="field">
            <label class="field__label req" for="menu_name">Name</label>
            <input class="input" id="menu_name" name="name" required minlength="2" maxlength="100"
                   value="<?= $this->old('name', $menu['name']) ?>" <?= $canManage ? '' : 'disabled' ?>>
            <?php if ($this->hasError('name')): ?>
              <p class="field__error"><?= $this->error('name') ?></p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label class="field__label" for="menu_slug">Slug</label>
            <input class="input mono" id="menu_slug" name="slug" maxlength="100"
                   value="<?= $this->old('slug', $menu['slug']) ?>" <?= $canManage ? '' : 'disabled' ?>>
            <p class="field__hint">Blank rebuilds it from the name. Kept unique automatically.</p>
          </div>

          <div class="field">
            <label class="field__label req" for="menu_location">Location</label>
            <select class="select" id="menu_location" name="location" required <?= $canManage ? '' : 'disabled' ?>>
              <?php foreach ($locations as $value => $label): ?>
                <option value="<?= $this->e($value) ?>" <?= $menu['location'] === $value ? 'selected' : '' ?>>
                  <?= $this->e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="field__label" for="menu_description">Description</label>
            <textarea class="textarea" id="menu_description" name="description" rows="2" maxlength="255"
                      <?= $canManage ? '' : 'disabled' ?>><?= $this->old('description', $menu['description'] ?? '') ?></textarea>
          </div>

          <div class="field">
            <label class="switch">
              <input type="checkbox" name="is_active" value="1"
                     <?= (int) $menu['is_active'] === 1 ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
              <span class="switch__track"></span>
              <span class="small">Active</span>
            </label>
          </div>

          <?php if ($canManage): ?>
            <div>
              <button type="submit" class="btn btn--primary btn--sm">Save settings</button>
            </div>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <?php if ($canManage): ?>
      <div class="card">
        <div class="card__head"><h2 class="h3">Copy or remove</h2></div>
        <div class="card__body col gap-3">
          <form method="post" action="/admin/menus/<?= $menuId ?>/duplicate">
            <?= $this->csrf() ?>
            <button type="submit" class="btn btn--outline btn--sm"
                    data-confirm="This menu and its <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> will be copied into a new, switched-off menu."
                    data-confirm-title="Duplicate this menu?" data-confirm-label="Duplicate" data-confirm-tone="warning">
              <?= $this->icon('copy', 'ic ic--sm') ?> Duplicate as a draft
            </button>
            <p class="field__hint mt-2">The copy starts switched off so it cannot go live by accident.</p>
          </form>

          <form method="post" action="/admin/menus/<?= $menuId ?>/delete">
            <?= $this->csrf() ?>
            <button type="submit" class="btn btn--danger btn--sm"
                    data-confirm="Deleting &ldquo;<?= $this->e($menu['name']) ?>&rdquo; also removes its <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?>. This cannot be undone."
                    data-confirm-title="Delete this menu?" data-confirm-label="Delete">
              <?= $this->icon('trash', 'ic ic--sm') ?> Delete this menu
            </button>
            <p class="field__hint mt-2">
              <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> will be removed with it.
            </p>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
/* ---- Tree editor ------------------------------------------------- */
.mnode-tree, .mnode__kids { list-style: none; margin: 0; padding: 0; }
.mnode { position: relative; }
.mnode-tree > .mnode + .mnode { margin-top: 8px; }

.mnode__row {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px;
  border: 1px solid var(--outline-variant);
  border-radius: var(--r-md);
  background: var(--surface-lowest);
  transition: border-color .14s, box-shadow .14s, background .14s;
}
.mnode--root > .mnode__row { border-left: 3px solid var(--primary); }
.mnode--child > .mnode__row { border-left: 3px solid var(--secondary); background: var(--surface-low); }
.mnode__row:hover { border-color: var(--outline); }

.mnode__grip { display: grid; place-items: center; color: var(--outline); cursor: grab; flex-shrink: 0; }
.mnode__grip:active { cursor: grabbing; }

.mnode__body { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.mnode__label { font-weight: 600; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mnode__meta { font-size: 11.5px; color: var(--outline); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.mnode__chips { display: flex; gap: 6px; flex-wrap: wrap; flex-shrink: 0; }
.mnode__tools { display: flex; gap: 2px; align-items: center; flex-shrink: 0; }

.mnode__kids { margin: 8px 0 0 34px; display: flex; flex-direction: column; gap: 8px; min-height: 10px; }
.mnode-tree > .mnode > .mnode__kids:empty { min-height: 0; margin-top: 0; }
.mnode--child > .mnode__kids { display: none; }

.mnode.is-dragging > .mnode__row { opacity: .45; border-style: dashed; }
.mnode.is-drop-before > .mnode__row { box-shadow: 0 -3px 0 0 var(--primary); }
.mnode.is-drop-after  > .mnode__row { box-shadow: 0 3px 0 0 var(--primary); }
.mnode.is-drop-into   > .mnode__row { border-color: var(--secondary); background: color-mix(in srgb, var(--secondary) 10%, var(--surface)); }
.mnode-tree.is-drop-end { box-shadow: inset 0 -3px 0 0 var(--primary); }

/* ---- Live preview ------------------------------------------------ */
.mprev { display: flex; flex-direction: column; gap: 4px;
  padding: 12px; border-radius: var(--r-lg);
  border: 1px solid var(--outline-variant); background: var(--surface-low); }

.mprev__row {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border-radius: var(--r-md);
  background: var(--surface-lowest);
  margin-left: calc(var(--indent, 0) * 20px);
}
.mprev__row.is-off { opacity: .5; }
.mprev__row.is-new { outline: 1px dashed var(--secondary); outline-offset: 1px; }

.mprev__icon {
  width: 30px; height: 30px; flex-shrink: 0;
  display: grid; place-items: center;
  border-radius: var(--r-sm);
  background: var(--surface-container); color: var(--on-surface-variant);
  font-size: 11px; font-weight: 700; text-transform: uppercase;
}
.mprev__text { display: flex; flex-direction: column; gap: 1px; min-width: 0; flex: 1; }
.mprev__label { font-size: 13.5px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mprev__meta { font-size: 11px; color: var(--outline); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.mprev__badge {
  flex-shrink: 0; font-size: 10.5px; font-weight: 700; letter-spacing: .02em;
  padding: 3px 8px; border-radius: var(--r-full); color: var(--on-primary); background: var(--primary);
}
.mprev__tag {
  flex-shrink: 0; font-size: 10px; font-weight: 600;
  padding: 2px 7px; border-radius: var(--r-full);
  color: var(--on-surface-variant); border: 1px solid var(--outline-variant);
}
.mprev__chev { flex-shrink: 0; color: var(--outline); display: grid; place-items: center; }
.mprev__empty { padding: 18px 8px; text-align: center; font-size: 12.5px; color: var(--outline); }

/* Only the control matching the chosen link type is shown. */
[data-link-control] { display: none; }
[data-link-control].is-shown { display: block; }

/* ---- Narrow viewports -------------------------------------------- */
/* The six-button tool strip is 202px wide and cannot shrink; below this
   width it no longer fits beside the label and would be clipped by the
   card (which sets overflow:hidden), so it drops to its own line. */
@media (max-width: 760px) {
  .mnode__row { flex-wrap: wrap; row-gap: 8px; }
  .mnode__body { flex: 1 1 calc(100% - 40px); }
  .mnode__tools { margin-left: auto; flex-wrap: wrap; justify-content: flex-end; }
}
</style>

<script>
(function () {
  'use strict';

  var host = document.querySelector('[data-menu-config]');
  if (!host) return;

  var CFG = JSON.parse(host.dataset.menuConfig);

  /* ------------------------------------------------------------------
     Shared rendering helpers
     ------------------------------------------------------------------ */
  var HEX = /^#[0-9a-fA-F]{6}$/;

  /* Badge backgrounds come from a free-form admin colour picker, so the ink
     has to be chosen per colour — no theme token can express it. */
  var BADGE_INK_DARK = '#241619';

  function srgbChannel(value) {
    value /= 255;
    return value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
  }

  function relativeLuminance(hex) {
    return 0.2126 * srgbChannel(parseInt(hex.slice(1, 3), 16))
      + 0.7152 * srgbChannel(parseInt(hex.slice(3, 5), 16))
      + 0.0722 * srgbChannel(parseInt(hex.slice(5, 7), 16));
  }

  // 0.179 is where white and BADGE_INK_DARK give equal WCAG contrast.
  function badgeInk(hex) {
    return relativeLuminance(hex) > 0.179 ? BADGE_INK_DARK : '#ffffff';
  }

  function iconMarkup(name) {
    var mapped = CFG.material[name];
    return mapped && CFG.icons[mapped] ? CFG.icons[mapped] : '';
  }

  function iconTile(name) {
    var span = document.createElement('span');
    span.className = 'mprev__icon';

    if (!name) {
      span.textContent = '·';
      span.title = 'No icon';
      return span;
    }

    var svg = iconMarkup(name);

    if (svg) {
      // Only ever our own constant SVG strings reach innerHTML.
      span.innerHTML = svg;
    } else {
      span.textContent = name.replace(/[^a-zA-Z0-9]/g, '').slice(0, 2) || '·';
    }

    span.title = name;
    return span;
  }

  function summaryFor(type, value) {
    if (type === 'none') return 'No link';
    if (type === 'page') {
      return 'Page · ' + (CFG.pages[value] || (value ? value + ' (missing)' : 'not chosen'));
    }
    if (type === 'category') {
      return 'Category · ' + (CFG.categories[value] || (value ? value + ' (missing)' : 'not chosen'));
    }
    if (type === 'url') return 'Link · ' + (value || 'not set');
    return (CFG.linkTypes[type] || type) + ' · ' + (value || 'not set');
  }

  /* ------------------------------------------------------------------
     Tree state read straight from the DOM
     ------------------------------------------------------------------ */
  var tree = document.querySelector('[data-tree]');
  var preview = document.querySelector('[data-preview]');
  var saveBtn = document.querySelector('[data-save-tree]');
  var revertBtn = document.querySelector('[data-revert]');
  var dirtyFlag = document.querySelector('[data-dirty-flag]');
  var dirty = false;

  function nodeToData(li) {
    return {
      id: parseInt(li.dataset.id, 10),
      label: li.dataset.label || '',
      icon: li.dataset.icon || '',
      badge: li.dataset.badge || '',
      badgeColor: li.dataset.badgeColor || '',
      audience: li.dataset.audience || 'all',
      active: li.dataset.active === '1',
      external: li.dataset.external === '1',
      summary: li.dataset.summary || ''
    };
  }

  function collect() {
    if (!tree) return [];

    return Array.prototype.map.call(tree.querySelectorAll(':scope > .mnode'), function (root) {
      return {
        node: nodeToData(root),
        children: Array.prototype.map.call(
          root.querySelectorAll(':scope > [data-kids] > .mnode'),
          nodeToData
        )
      };
    });
  }

  /* ------------------------------------------------------------------
     Preview
     ------------------------------------------------------------------ */
  function previewRow(data, depth) {
    var row = document.createElement('div');
    row.className = 'mprev__row' + (data.active ? '' : ' is-off') + (data.isNew ? ' is-new' : '');
    row.style.setProperty('--indent', String(depth));

    row.appendChild(iconTile(data.icon));

    var text = document.createElement('span');
    text.className = 'mprev__text';

    var label = document.createElement('span');
    label.className = 'mprev__label';
    label.textContent = data.label || 'Untitled item';
    text.appendChild(label);

    var meta = document.createElement('span');
    meta.className = 'mprev__meta';
    meta.textContent = data.summary;
    text.appendChild(meta);

    row.appendChild(text);

    if (!data.active) {
      var hidden = document.createElement('span');
      hidden.className = 'mprev__tag';
      hidden.textContent = 'Hidden';
      row.appendChild(hidden);
    }

    if (data.audience && data.audience !== 'all') {
      var aud = document.createElement('span');
      aud.className = 'mprev__tag';
      aud.textContent = CFG.audiences[data.audience] || data.audience;
      row.appendChild(aud);
    }

    if (data.badge) {
      var badge = document.createElement('span');
      badge.className = 'mprev__badge';
      badge.textContent = data.badge;
      if (HEX.test(data.badgeColor)) {
        badge.style.background = data.badgeColor;
        badge.style.color = badgeInk(data.badgeColor);
      }
      row.appendChild(badge);
    }

    var chev = document.createElement('span');
    chev.className = 'mprev__chev';
    chev.innerHTML = data.external
      ? (CFG.icons['external'] || '')
      : (CFG.icons['chevron-right'] || '');
    row.appendChild(chev);

    return row;
  }

  function render() {
    if (!preview) return;

    var branches = collect();
    var draft = readDraft();

    if (draft) {
      branches = applyDraft(branches, draft);
    }

    preview.textContent = '';

    if (!branches.length) {
      var empty = document.createElement('p');
      empty.className = 'mprev__empty';
      empty.textContent = 'Nothing to show yet — add an item to see it here.';
      preview.appendChild(empty);
      return;
    }

    branches.forEach(function (branch) {
      preview.appendChild(previewRow(branch.node, 0));
      branch.children.forEach(function (child) {
        preview.appendChild(previewRow(child, 1));
      });
    });
  }

  /**
   * Overlay whatever is currently in the item form so a change is visible
   * before it is saved.
   */
  function applyDraft(branches, draft) {
    var out = [];
    var placed = false;

    branches.forEach(function (branch) {
      if (draft.id && branch.node.id === draft.id) {
        if (draft.parentId === 0) {
          out.push({ node: draftNode(draft), children: branch.children });
          placed = true;
        } else {
          // Moved under another item, so its own children rise to the top.
          branch.children.forEach(function (child) {
            out.push({ node: child, children: [] });
          });
        }
        return;
      }

      var kids = [];

      branch.children.forEach(function (child) {
        if (draft.id && child.id === draft.id) {
          if (draft.parentId === branch.node.id) {
            kids.push(draftNode(draft));
            placed = true;
          }
          return; // Moved elsewhere: dropped from this group.
        }

        kids.push(child);
      });

      out.push({ node: branch.node, children: kids });
    });

    if (!placed && draft.parentId > 0) {
      for (var i = 0; i < out.length; i++) {
        if (out[i].node.id === draft.parentId) {
          out[i].children.push(draftNode(draft));
          placed = true;
          break;
        }
      }
    }

    if (!placed) {
      out.push({ node: draftNode(draft), children: [] });
    }

    return out;
  }

  function draftNode(draft) {
    return {
      id: draft.id || 0,
      label: draft.label,
      icon: draft.icon,
      badge: draft.badge,
      badgeColor: draft.badgeColor,
      audience: draft.audience,
      active: draft.active,
      external: draft.external,
      summary: draft.summary,
      isNew: !draft.id
    };
  }

  /* ------------------------------------------------------------------
     Item form: link controls, colour swatch, live draft
     ------------------------------------------------------------------ */
  var form = document.querySelector('[data-item-form]');

  function activeLinkControl() {
    if (!form) return null;

    var type = form.querySelector('#item_link_type').value;
    return form.querySelector('[data-link-control="' + type + '"]');
  }

  var LINK_HINTS = {
    screen: 'An app route, for example /bookings or profile_settings.',
    page: 'Pick a published page. Its slug is stored.',
    category: 'Pick a category. Its id is stored.',
    url: 'A full http or https address.',
    none: 'A label with no destination — useful as a section heading.'
  };

  function syncLinkControls() {
    if (!form) return;

    var type = form.querySelector('#item_link_type').value;

    form.querySelectorAll('[data-link-control]').forEach(function (control) {
      var isActive = control.dataset.linkControl === type;
      control.classList.toggle('is-shown', isActive);
      // Disabled controls are not submitted, so only one link_value arrives.
      control.disabled = !isActive;
    });

    var hint = form.querySelector('[data-link-hint]');
    if (hint) hint.textContent = LINK_HINTS[type] || '';
  }

  function readDraft() {
    if (!form) return null;

    var type = form.querySelector('#item_link_type').value;
    var control = activeLinkControl();
    var value = control ? control.value.trim() : '';
    var parentSelect = form.querySelector('#item_parent');

    return {
      id: form.dataset.itemId ? parseInt(form.dataset.itemId, 10) : 0,
      // A disabled parent select means the item is pinned to the top level.
      parentId: parentSelect && !parentSelect.disabled ? (parseInt(parentSelect.value, 10) || 0) : 0,
      label: form.querySelector('#item_label').value.trim(),
      icon: form.querySelector('#item_icon').value.trim(),
      badge: form.querySelector('#item_badge_text').value.trim(),
      badgeColor: form.querySelector('#item_badge_color').value.trim(),
      audience: form.querySelector('#item_audience').value,
      active: form.querySelector('#item_is_active').checked,
      external: form.querySelector('#item_open_external').checked,
      summary: summaryFor(type, value)
    };
  }

  if (form) {
    syncLinkControls();

    form.addEventListener('input', function () { syncIconPreview(); render(); });
    form.addEventListener('change', function (e) {
      if (e.target.id === 'item_link_type') syncLinkControls();
      syncIconPreview();
      render();
    });

    var swatch = form.querySelector('[data-color-picker]');
    var colorInput = form.querySelector('#item_badge_color');

    if (swatch && colorInput) {
      swatch.addEventListener('input', function () {
        colorInput.value = swatch.value.toUpperCase();
        render();
      });

      colorInput.addEventListener('input', function () {
        if (HEX.test(colorInput.value.trim())) swatch.value = colorInput.value.trim();
      });
    }
  }

  function syncIconPreview() {
    if (!form) return;

    var holder = form.querySelector('[data-icon-preview]');
    if (!holder) return;

    var name = form.querySelector('#item_icon').value.trim();
    var svg = name ? iconMarkup(name) : '';

    if (svg) {
      holder.innerHTML = svg;
    } else {
      holder.textContent = name ? (name.replace(/[^a-zA-Z0-9]/g, '').slice(0, 2) || '·') : '·';
    }

    holder.title = name || 'No icon';
  }

  syncIconPreview();

  /* ------------------------------------------------------------------
     Drag and drop — HTML5 events only, at most two levels
     ------------------------------------------------------------------ */
  var dragged = null;

  function isChild(li) {
    return li.parentElement !== null && li.parentElement.hasAttribute('data-kids');
  }

  function hasChildren(li) {
    var kids = li.querySelector(':scope > [data-kids]');
    return !!kids && kids.querySelector(':scope > .mnode') !== null;
  }

  function clearMarkers() {
    if (!tree) return;
    tree.classList.remove('is-drop-end');
    tree.querySelectorAll('.is-drop-before, .is-drop-after, .is-drop-into').forEach(function (el) {
      el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-into');
    });
  }

  function dropTarget(e) {
    var node = e.target.closest ? e.target.closest('.mnode') : null;

    if (!node) return { node: null, mode: 'end' };

    var row = node.querySelector(':scope > .mnode__row');
    if (!row) return { node: null, mode: 'end' };

    var rect = row.getBoundingClientRect();
    var third = (rect.height || 1) / 3;
    var mode;

    if (e.clientY < rect.top + third) mode = 'before';
    else if (e.clientY > rect.bottom - third) mode = 'after';
    else mode = 'into';

    // Below the row of a root node means the children well underneath it.
    if (!isChild(node) && e.clientY > rect.bottom) mode = 'into';

    // A third level is not allowed, in either direction.
    if (mode === 'into' && (isChild(node) || (dragged && hasChildren(dragged)))) mode = 'after';

    return { node: node, mode: mode };
  }

  function targetIsLegal(target) {
    if (!dragged) return false;
    if (!target.node) return true;
    if (target.node === dragged || dragged.contains(target.node)) return false;

    // An item with children can only live at the top level.
    if (hasChildren(dragged) && target.mode !== 'into' && isChild(target.node)) return false;

    return true;
  }

  if (tree && CFG.canManage) {
    tree.addEventListener('dragstart', function (e) {
      var node = e.target.closest('.mnode');

      if (!node || (e.target.closest && e.target.closest('[data-no-drag]'))) {
        e.preventDefault();
        return;
      }

      dragged = node;
      node.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      // Firefox requires payload for a drag to start at all.
      e.dataTransfer.setData('text/plain', node.dataset.id);
    });

    tree.addEventListener('dragend', function () {
      if (dragged) dragged.classList.remove('is-dragging');
      dragged = null;
      clearMarkers();
    });

    tree.addEventListener('dragover', function (e) {
      if (!dragged) return;

      var target = dropTarget(e);
      clearMarkers();

      if (!targetIsLegal(target)) {
        e.dataTransfer.dropEffect = 'none';
        return;
      }

      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';

      if (!target.node) {
        tree.classList.add('is-drop-end');
        return;
      }

      target.node.classList.add('is-drop-' + target.mode);
    });

    tree.addEventListener('dragleave', function (e) {
      if (e.target === tree) clearMarkers();
    });

    tree.addEventListener('drop', function (e) {
      if (!dragged) return;

      e.preventDefault();

      var target = dropTarget(e);
      clearMarkers();

      if (!targetIsLegal(target)) return;

      if (!target.node) {
        tree.appendChild(dragged);
      } else if (target.mode === 'into') {
        var well = target.node.querySelector(':scope > [data-kids]');
        if (!well) return;
        well.appendChild(dragged);
      } else if (target.mode === 'before') {
        target.node.parentElement.insertBefore(dragged, target.node);
      } else {
        target.node.parentElement.insertBefore(dragged, target.node.nextSibling);
      }

      normalise();
      markDirty();
    });
  }

  /* ------------------------------------------------------------------
     Keyboard-friendly moves
     ------------------------------------------------------------------ */
  if (tree && CFG.canManage) {
    tree.addEventListener('click', function (e) {
      var button = e.target.closest('[data-move]');
      if (!button) return;

      var node = button.closest('.mnode');
      if (!node) return;

      var action = button.dataset.move;

      if (action === 'up') {
        var prev = node.previousElementSibling;
        if (prev) node.parentElement.insertBefore(node, prev);
      } else if (action === 'down') {
        var next = node.nextElementSibling;
        if (next) node.parentElement.insertBefore(next, node);
      } else if (action === 'in') {
        var above = node.previousElementSibling;
        if (!above || isChild(node) || hasChildren(node)) return;
        var well = above.querySelector(':scope > [data-kids]');
        if (!well) return;
        well.appendChild(node);
      } else if (action === 'out') {
        if (!isChild(node)) return;
        var parentNode = node.parentElement.closest('.mnode');
        if (!parentNode) return;
        parentNode.parentElement.insertBefore(node, parentNode.nextSibling);
      }

      normalise();
      markDirty();
    });
  }

  /* ------------------------------------------------------------------
     Structure bookkeeping
     ------------------------------------------------------------------ */
  function normalise() {
    if (!tree) return;

    tree.querySelectorAll(':scope > .mnode').forEach(function (root) {
      root.classList.add('mnode--root');
      root.classList.remove('mnode--child');

      if (!root.querySelector(':scope > [data-kids]')) {
        var well = document.createElement('ul');
        well.className = 'mnode__kids';
        well.setAttribute('data-kids', '');
        root.appendChild(well);
      }

      root.querySelectorAll(':scope > [data-kids] > .mnode').forEach(function (child) {
        child.classList.add('mnode--child');
        child.classList.remove('mnode--root');
      });
    });

    updateMoveButtons();
    render();
  }

  function updateMoveButtons() {
    if (!tree) return;

    tree.querySelectorAll('.mnode').forEach(function (node) {
      var child = isChild(node);
      var kids = hasChildren(node);

      var up = node.querySelector(':scope > .mnode__row [data-move="up"]');
      var down = node.querySelector(':scope > .mnode__row [data-move="down"]');
      var inBtn = node.querySelector(':scope > .mnode__row [data-move="in"]');
      var outBtn = node.querySelector(':scope > .mnode__row [data-move="out"]');

      if (up) up.disabled = node.previousElementSibling === null;
      if (down) down.disabled = node.nextElementSibling === null;
      if (inBtn) inBtn.disabled = child || kids || node.previousElementSibling === null;
      if (outBtn) outBtn.disabled = !child;
    });
  }

  function markDirty() {
    dirty = true;
    if (saveBtn) saveBtn.disabled = false;
    if (dirtyFlag) dirtyFlag.classList.remove('hidden');
    if (revertBtn) revertBtn.classList.remove('hidden');
  }

  /* ------------------------------------------------------------------
     Saving
     ------------------------------------------------------------------ */
  function serialise() {
    var payload = [];

    if (!tree) return payload;

    tree.querySelectorAll(':scope > .mnode').forEach(function (root, index) {
      var rootId = parseInt(root.dataset.id, 10);
      payload.push({ id: rootId, parent_id: null, sort_order: index + 1 });

      root.querySelectorAll(':scope > [data-kids] > .mnode').forEach(function (child, childIndex) {
        payload.push({ id: parseInt(child.dataset.id, 10), parent_id: rootId, sort_order: childIndex + 1 });
      });
    });

    return payload;
  }

  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      var token = document.querySelector('meta[name="csrf-token"]');

      saveBtn.disabled = true;
      saveBtn.classList.add('btn--loading');

      fetch(CFG.saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': token ? token.content : ''
        },
        body: JSON.stringify({ tree: serialise() })
      }).then(function (response) {
        return response.json().then(function (body) { return { ok: response.ok, body: body }; });
      }).then(function (result) {
        saveBtn.classList.remove('btn--loading');

        if (!result.ok || !result.body.success) {
          saveBtn.disabled = false;
          window.Admin.toast(result.body.message || 'The layout could not be saved.', 'error');
          return;
        }

        dirty = false;
        if (dirtyFlag) dirtyFlag.classList.add('hidden');
        if (revertBtn) revertBtn.classList.add('hidden');
        window.Admin.toast(result.body.message || 'Menu layout saved.', 'success');
      }).catch(function () {
        saveBtn.classList.remove('btn--loading');
        saveBtn.disabled = false;
        window.Admin.toast('The layout could not be saved. Check your connection and try again.', 'error');
      });
    });
  }

  if (revertBtn) {
    revertBtn.addEventListener('click', function () {
      window.location.reload();
    });
  }

  window.addEventListener('beforeunload', function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });

  /* ------------------------------------------------------------------
     First paint
     ------------------------------------------------------------------ */
  if (tree) {
    updateMoveButtons();
  }

  render();
})();
</script>
<?php $this->stop(); ?>

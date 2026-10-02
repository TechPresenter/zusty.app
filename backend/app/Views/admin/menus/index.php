<?php

declare(strict_types=1);

/**
 * Menus list: every navigation menu, with a create form beside it.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed> $pagination
 * @var array<string,string> $locations
 * @var array<string,mixed> $filters
 * @var bool $canManage
 */

$this->extend('admin.layouts.app');
$this->start('content');

$locationOptions = ['' => 'Every location'] + $locations;

?>

<div class="grid grid--sidebar">

  <div class="col gap-4">
    <div class="card">

      <?php $this->partial('admin.partials.toolbar', [
          'action'            => '/admin/menus',
          'filters'           => $filters,
          'searchPlaceholder' => 'Search menus by name or slug…',
          'showDateRange'     => true,
          'showExport'        => true,
          'selects'           => [
              ['name' => 'location', 'label' => 'Location', 'options' => $locationOptions],
              ['name' => 'state', 'label' => 'State', 'options' => [
                  ''         => 'Any state',
                  'active'   => 'Active',
                  'inactive' => 'Switched off',
              ]],
          ],
      ]); ?>

      <?php if ($rows === []): ?>
        <div class="empty">
          <div class="empty__art"><?= $this->icon('menu', 'ic ic--lg') ?></div>
          <p class="empty__title">No menus match</p>
          <p class="empty__text">
            A menu groups the links shown in one place — the app drawer, the website footer, and so on.
            <?= $canManage ? 'Create one on the right to get started.' : '' ?>
          </p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Menu</th>
                <th>Location</th>
                <th class="text-right">Items</th>
                <th>State</th>
                <th>Updated</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <?php
                $id       = (int) $row['id'];
                $items    = (int) $row['item_count'];
                $active   = (int) $row['active_count'];
                $isLive   = (int) $row['is_active'] === 1;
                ?>
                <tr>
                  <td>
                    <a href="/admin/menus/<?= $id ?>" style="font-weight:600"><?= $this->e($row['name']) ?></a>
                    <div class="tiny faint mono"><?= $this->e($row['slug']) ?></div>
                    <?php if (($row['description'] ?? '') !== ''): ?>
                      <div class="tiny muted truncate" style="max-width:340px"><?= $this->e($row['description']) ?></div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <span class="chip chip--info">
                      <?= $this->e($locations[$row['location']] ?? $row['location']) ?>
                    </span>
                  </td>

                  <td class="text-right">
                    <span class="mono"><?= $this->number($items) ?></span>
                    <?php if ($items > 0 && $active < $items): ?>
                      <div class="tiny muted"><?= $this->number($items - $active) ?> hidden</div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <span class="chip chip--<?= $isLive ? 'success' : 'neutral' ?>">
                      <span class="chip__dot"></span><?= $isLive ? 'Active' : 'Off' ?>
                    </span>
                  </td>

                  <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($row['updated_at']) ?></td>

                  <td class="actions">
                    <div class="row gap-2 row--end">
                      <a href="/admin/menus/<?= $id ?>" class="btn btn--outline btn--sm">
                        <?= $this->icon('grid', 'ic ic--sm') ?> Builder
                      </a>

                      <?php if ($canManage): ?>
                        <form method="post" action="/admin/menus/<?= $id ?>/duplicate" style="display:inline">
                          <?= $this->csrf() ?>
                          <button type="submit" class="btn btn--ghost btn--icon btn--sm" aria-label="Duplicate"
                                  title="Duplicate this menu"
                                  data-confirm="&ldquo;<?= $this->e($row['name']) ?>&rdquo; and its <?= $items ?> item<?= $items === 1 ? '' : 's' ?> will be copied into a new, switched-off menu."
                                  data-confirm-title="Duplicate this menu?"
                                  data-confirm-label="Duplicate" data-confirm-tone="warning">
                            <?= $this->icon('copy', 'ic ic--sm') ?>
                          </button>
                        </form>

                        <form method="post" action="/admin/menus/<?= $id ?>/delete" style="display:inline">
                          <?= $this->csrf() ?>
                          <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error" aria-label="Delete"
                                  title="Delete this menu"
                                  data-confirm="Deleting &ldquo;<?= $this->e($row['name']) ?>&rdquo; also removes its <?= $items ?> item<?= $items === 1 ? '' : 's' ?>. This cannot be undone."
                                  data-confirm-title="Delete this menu?"
                                  data-confirm-label="Delete">
                            <?= $this->icon('trash', 'ic ic--sm') ?>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="col gap-4">
    <?php if ($canManage): ?>
      <div class="card">
        <div class="card__head"><h2 class="h3">New menu</h2></div>

        <div class="card__body">
          <form method="post" action="/admin/menus" class="grid">
            <?= $this->csrf() ?>

            <div class="field">
              <label class="field__label req" for="menu_name">Name</label>
              <input class="input" id="menu_name" name="name" required minlength="2" maxlength="100"
                     value="<?= $this->old('name') ?>" placeholder="Website footer"
                     <?= $this->hasError('name') ? 'aria-invalid="true"' : '' ?>>
              <?php if ($this->hasError('name')): ?>
                <p class="field__error"><?= $this->error('name') ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="field__label" for="menu_slug">Slug</label>
              <input class="input mono" id="menu_slug" name="slug" maxlength="100"
                     value="<?= $this->old('slug') ?>" placeholder="website-footer" data-slug-target>
              <p class="field__hint">Left blank, it is built from the name. A number is added if it is already taken.</p>
            </div>

            <div class="field">
              <label class="field__label req" for="menu_location">Location</label>
              <select class="select" id="menu_location" name="location" required>
                <?php foreach ($locations as $value => $label): ?>
                  <option value="<?= $this->e($value) ?>"><?= $this->e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="field__hint">Where the apps or the website read this menu from.</p>
            </div>

            <div class="field">
              <label class="field__label" for="menu_description">Description</label>
              <textarea class="textarea" id="menu_description" name="description" rows="2" maxlength="255"
                        placeholder="Shown at the bottom of every web page."><?= $this->old('description') ?></textarea>
            </div>

            <div class="field">
              <label class="switch">
                <input type="checkbox" name="is_active" value="1" checked>
                <span class="switch__track"></span>
                <span class="small">Active</span>
              </label>
              <p class="field__hint">Switched off menus are kept but not served.</p>
            </div>

            <div>
              <button type="submit" class="btn btn--primary">
                <?= $this->icon('plus', 'ic ic--sm') ?> Create menu
              </button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="callout callout--info">
      <?= $this->icon('info', 'ic ic--sm') ?>
      <div>
        <strong>Two levels, no more.</strong>
        A menu item can hold children, but a child cannot. The builder blocks a third level while
        you drag, and the server refuses it as well.
      </div>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
(function () {
  'use strict';

  // Mirror the name into the slug box until someone edits the slug by hand.
  var name = document.getElementById('menu_name');
  var slug = document.getElementById('menu_slug');

  if (!name || !slug) return;

  var touched = slug.value.trim() !== '';

  slug.addEventListener('input', function () { touched = true; });

  name.addEventListener('input', function () {
    if (touched) return;

    slug.value = name.value
      .toLowerCase()
      .replace(/&/g, ' and ')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 100);
  });
})();
</script>
<?php $this->stop(); ?>

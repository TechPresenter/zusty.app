<?php

declare(strict_types=1);

/**
 * Manage one option list.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $taxonomy
 * @var list<array<string,mixed>> $terms
 */

$this->extend('admin.layouts.app');
$this->start('content');

$isSystem = (int) $taxonomy['is_system'] === 1;
$canEdit  = $this->can('taxonomies.manage');

?>

<div class="row gap-3 mb-4" style="align-items:center;flex-wrap:wrap">
  <a href="/admin/taxonomies" class="btn btn--sm btn--ghost">
    <?= $this->icon('arrow-left', 'ic ic--sm') ?> All lists
  </a>
  <span class="chip chip--neutral mono"><?= $this->e((string) $taxonomy['key']) ?></span>
  <?php if ($isSystem): ?>
    <span class="chip chip--warning">System list</span>
  <?php endif; ?>
</div>

<?php if ($isSystem): ?>
  <div class="card mb-4" style="border-color:var(--warning);background:color-mix(in srgb, var(--warning) 8%, transparent)">
    <div class="card__body row gap-3" style="padding:14px 18px">
      <span class="text-warning"><?= $this->icon('alert-triangle') ?></span>
      <p class="small">
        The server matches on these exact values, so options cannot be added, renamed or deleted.
        You can change the labels users see, reorder them, and hide ones you do not offer —
        hiding keeps existing records valid while removing the option from the apps.
      </p>
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--2" style="align-items:flex-start;gap:20px">

  <!-- Options -->
  <div class="card">
    <div class="card__head">
      <h3 class="card__title">Options</h3>
      <span class="tiny muted"><?= count($terms) ?> total</span>
    </div>
    <div class="card__body">
      <?php if ($terms === []): ?>
        <p class="small muted">No options yet.</p>
      <?php else: ?>
        <div class="stack gap-2">
          <?php foreach ($terms as $term): ?>
            <form method="post"
                  action="/admin/taxonomies/<?= (int) $taxonomy['id'] ?>/terms/<?= (int) $term['id'] ?>"
                  class="card" style="border:1px solid var(--border)">
              <?= $this->csrf() ?>
              <div class="card__body" style="padding:12px 14px">

                <div class="row gap-3" style="flex-wrap:wrap;align-items:flex-end">
                  <label class="field" style="flex:0 0 130px">
                    <span class="field__label tiny">Value (stored)</span>
                    <input type="text" class="input input--sm mono" value="<?= $this->e((string) $term['value']) ?>"
                           disabled title="The value the API stores. Fixed once created.">
                  </label>

                  <label class="field" style="flex:2 1 200px">
                    <span class="field__label tiny">Label (shown in the apps)</span>
                    <input type="text" name="label" class="input input--sm" required maxlength="150"
                           value="<?= $this->e((string) $term['label']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  </label>

                  <label class="field" style="flex:0 0 130px">
                    <span class="field__label tiny">Icon</span>
                    <input type="text" name="icon" class="input input--sm" maxlength="60"
                           value="<?= $this->e((string) ($term['icon'] ?? '')) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  </label>

                  <label class="field" style="flex:0 0 100px">
                    <span class="field__label tiny">Colour</span>
                    <input type="text" name="color_hex" class="input input--sm mono" maxlength="7"
                           placeholder="#B80049"
                           value="<?= $this->e((string) ($term['color_hex'] ?? '')) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  </label>

                  <label class="field" style="flex:0 0 80px">
                    <span class="field__label tiny">Order</span>
                    <input type="number" name="sort_order" class="input input--sm" min="0" max="9999"
                           value="<?= (int) $term['sort_order'] ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  </label>
                </div>

                <div class="row gap-3 mt-2" style="flex-wrap:wrap;align-items:center">
                  <label class="field flex-1" style="min-width:220px">
                    <span class="field__label tiny">Metadata (JSON)</span>
                    <input type="text" name="metadata" class="input input--sm mono"
                           placeholder='{"rank":1}'
                           value="<?= $this->e((string) ($term['metadata'] ?? '')) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  </label>

                  <label class="row gap-2 tiny">
                    <input type="checkbox" name="is_active" value="1"
                           <?= (int) $term['is_active'] === 1 ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                    Visible
                  </label>

                  <label class="row gap-2 tiny">
                    <input type="checkbox" name="is_default" value="1"
                           <?= (int) $term['is_default'] === 1 ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                    Default
                  </label>

                  <?php if ($canEdit): ?>
                    <div class="row gap-2" style="margin-left:auto">
                      <button type="submit" class="btn btn--sm btn--secondary">Save</button>
                      <?php if (!$isSystem): ?>
                        <button type="submit" class="btn btn--sm btn--ghost text-error"
                                formaction="/admin/taxonomies/<?= (int) $taxonomy['id'] ?>/terms/<?= (int) $term['id'] ?>/delete"
                                data-confirm="Removing an option hides it from every app immediately."
                                data-confirm-title="Delete this option?"
                                data-confirm-label="Delete" data-confirm-tone="danger">
                          Delete
                        </button>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="stack gap-4">

    <!-- List settings -->
    <div class="card">
      <div class="card__head"><h3 class="card__title">List settings</h3></div>
      <div class="card__body">
        <form method="post" action="/admin/taxonomies/<?= (int) $taxonomy['id'] ?>">
          <?= $this->csrf() ?>

          <label class="field">
            <span class="field__label">Name</span>
            <input type="text" name="name" class="input" required maxlength="120"
                   value="<?= $this->e((string) $taxonomy['name']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>

          <label class="field mt-3">
            <span class="field__label">Where it appears</span>
            <textarea name="description" rows="2" class="input" maxlength="500"
                      <?= $canEdit ? '' : 'disabled' ?>><?= $this->e((string) ($taxonomy['description'] ?? '')) ?></textarea>
          </label>

          <div class="row gap-3 mt-3" style="align-items:center">
            <label class="row gap-2 tiny">
              <input type="checkbox" name="is_active" value="1"
                     <?= (int) $taxonomy['is_active'] === 1 ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
              List is live
            </label>
            <label class="field" style="flex:0 0 90px">
              <span class="field__label tiny">Order</span>
              <input type="number" name="sort_order" class="input input--sm" min="0" max="9999"
                     value="<?= (int) $taxonomy['sort_order'] ?>" <?= $canEdit ? '' : 'disabled' ?>>
            </label>
          </div>

          <?php if ($canEdit): ?>
            <button type="submit" class="btn btn--secondary mt-3">Save list</button>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- Add option -->
    <?php if ($canEdit && !$isSystem): ?>
      <div class="card">
        <div class="card__head"><h3 class="card__title">Add an option</h3></div>
        <div class="card__body">
          <form method="post" action="/admin/taxonomies/<?= (int) $taxonomy['id'] ?>/terms">
            <?= $this->csrf() ?>

            <label class="field">
              <span class="field__label">Value</span>
              <input type="text" name="value" class="input mono" required maxlength="60"
                     placeholder="lowercase-with-dashes">
              <span class="tiny muted">Stored in the database. Cannot be changed later.</span>
            </label>

            <label class="field mt-3">
              <span class="field__label">Label</span>
              <input type="text" name="label" class="input" required maxlength="150"
                     placeholder="What users see">
            </label>

            <div class="row gap-2 mt-3">
              <label class="field flex-1">
                <span class="field__label tiny">Icon</span>
                <input type="text" name="icon" class="input input--sm" maxlength="60" placeholder="star">
              </label>
              <label class="field flex-1">
                <span class="field__label tiny">Colour</span>
                <input type="text" name="color_hex" class="input input--sm mono" maxlength="7" placeholder="#B80049">
              </label>
            </div>

            <label class="field mt-3">
              <span class="field__label tiny">Metadata (JSON, optional)</span>
              <input type="text" name="metadata" class="input input--sm mono" placeholder='{"rank":1}'>
            </label>

            <button type="submit" class="btn btn--primary mt-3">Add option</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__body">
        <p class="tiny muted">
          Saving any change flushes the cached bundle and moves its version stamp, so apps pick
          the new list up on their next launch rather than waiting for a cache to expire.
        </p>
      </div>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

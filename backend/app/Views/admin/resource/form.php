<?php

declare(strict_types=1);

/**
 * Generic resource create/edit form.
 *
 * @var \Zuste\Core\View      $this
 * @var \Zuste\Admin\Resource $resource
 * @var array<string,mixed>   $row
 * @var bool                  $isEdit
 */

$this->extend('admin.layouts.app');
$this->start('content');

$action = $isEdit
    ? '/admin/' . $resource->key . '/' . (int) $row['id']
    : '/admin/' . $resource->key;

/** Render a single field control. */
$control = function (array $field) use ($row): string {
    $name     = $field['name'];
    $type     = $field['type'];
    $id       = 'f_' . $name;
    $value    = $row[$name] ?? ($field['default'] ?? '');
    $invalid  = $this->hasError($name) ? ' aria-invalid="true"' : '';
    $required = str_contains($field['rules'] ?? '', 'required') ? ' required' : '';

    // A re-displayed form after a validation failure keeps what was typed.
    $old = \Zuste\Core\Session::oldInput($name, null);
    if ($old !== null) {
        $value = $old;
    }

    switch ($type) {
        case 'textarea':
        case 'html':
            // An `html` field gets the rich editor; a plain `textarea` does
            // not. That distinction already exists in the resource registry and
            // is exactly the right one — `content`, `answer` and `body` are
            // prose an operator formats, while `meta_description`, `excerpt`
            // and the AI prompt fields are plain strings where a toolbar would
            // be noise and stray markup would be a bug.
            //
            // editor.js enhances the textarea and leaves it in the form as the
            // value that submits, so a failed script costs nothing.
            return sprintf(
                '<textarea class="textarea%s" id="%s" name="%s" rows="%d"%s%s%s>%s</textarea>',
                $type === 'html' ? ' mono' : '',
                $id,
                $this->e($name),
                (int) ($field['rows'] ?? 4),
                $type === 'html' ? ' data-editor="rich"' : '',
                $required,
                $invalid,
                $this->e((string) $value)
            );

        case 'select':
            $options = '';
            foreach (($field['options'] ?? []) as $optionValue => $optionLabel) {
                $options .= sprintf(
                    '<option value="%s"%s>%s</option>',
                    $this->e((string) $optionValue),
                    (string) $value === (string) $optionValue ? ' selected' : '',
                    $this->e((string) $optionLabel)
                );
            }

            return sprintf('<select class="select" id="%s" name="%s"%s%s>%s</select>',
                $id, $this->e($name), $required, $invalid, $options);

        case 'switch':
            return sprintf(
                '<label class="switch"><input type="checkbox" id="%s" name="%s" value="1"%s>'
                . '<span class="switch__track"></span><span class="small">%s</span></label>',
                $id,
                $this->e($name),
                !empty($value) ? ' checked' : '',
                $this->e($field['label'])
            );

        case 'number':
            return sprintf('<input class="input" type="number" id="%s" name="%s" value="%s"%s%s>',
                $id, $this->e($name), $this->e((string) $value), $required, $invalid);

        case 'datetime':
            $formatted = ($value === '' || $value === null)
                ? ''
                : date('Y-m-d\TH:i', (int) strtotime((string) $value));

            return sprintf('<input class="input" type="datetime-local" id="%s" name="%s" value="%s"%s%s>',
                $id, $this->e($name), $this->e($formatted), $required, $invalid);

        case 'color':
            return sprintf(
                '<div class="row gap-3"><input type="color" id="%s" name="%s" value="%s" '
                . 'style="width:52px;height:42px;border:1px solid var(--outline-variant);border-radius:var(--r-md);'
                . 'background:none;cursor:pointer;padding:3px">'
                . '<input class="input mono" value="%s" readonly style="max-width:140px" aria-hidden="true"></div>',
                $id, $this->e($name), $this->e((string) ($value ?: '#B80049')), $this->e((string) ($value ?: '#B80049'))
            );

        case 'image':
            $preview = $value
                ? sprintf(
                    '<img src="%s" alt="" loading="lazy" style="width:84px;height:56px;object-fit:cover;'
                    . 'border-radius:var(--r-md);background:var(--surface-container);flex-shrink:0">',
                    $this->e((string) $value)
                )
                : '<div style="width:84px;height:56px;border-radius:var(--r-md);background:var(--surface-container);flex-shrink:0"></div>';

            // Deliberately type="text", not type="url".
            //
            // Every file the media library stores comes back as a root-relative
            // path (/uploads/…), which a type="url" control rejects as invalid.
            // That made any record already holding such a value — and every
            // banner, whose image_url is required — impossible to submit from a
            // browser at all. The pattern below accepts both an absolute
            // http(s) URL and a leading-slash path, so the control still
            // refuses free text while allowing what the library actually hands
            // out. data-image-input marks it for the live preview script.
            return sprintf(
                '<div class="row gap-3">%s<input class="input" type="text" inputmode="url" data-image-input '
                . 'id="%s" name="%s" value="%s" pattern="%s" '
                . 'title="Use a full https:// address or a path beginning with / (for example /uploads/banners/hero.jpg)." '
                . 'placeholder="https://… or /uploads/…"%s%s>'
                . '<a class="btn btn--ghost btn--sm" href="/admin/media" target="_blank" rel="noopener"'
                . ' title="Open the media library in a new tab to copy a file URL">Library</a></div>',
                $preview,
                $id,
                $this->e($name),
                $this->e((string) $value),
                $this->e('(https?://.+|/[^\s]*)'),
                $required,
                $invalid
            );

        default:
            return sprintf('<input class="input" type="text" id="%s" name="%s" value="%s"%s%s>',
                $id, $this->e($name), $this->e((string) $value), $required, $invalid);
    }
};

$groups = $resource->fieldGroups();

// The first group is the main column; the rest go in the sidebar.
$mainGroup     = array_key_first($groups);
$sidebarGroups = array_slice($groups, 1, null, true);

?>

<a href="/admin/<?= $this->e($resource->key) ?>" class="btn btn--ghost btn--sm mb-4">
  <?= $this->icon('chevron-left', 'ic ic--sm') ?> Back to <?= $this->e(strtolower($resource->plural)) ?>
</a>

<form method="post" action="<?= $this->e($action) ?>">
  <?= $this->csrf() ?>

  <div class="grid grid--sidebar">

    <!-- Main column -->
    <div class="col gap-4">
      <div class="card">
        <div class="card__head">
          <h3 class="h3"><?= $this->e($mainGroup ?? 'General') ?></h3>
        </div>
        <div class="card__body">
          <?php foreach ($groups[$mainGroup] ?? [] as $field): ?>
            <div class="field">
              <?php if ($field['type'] !== 'switch'): ?>
                <label class="field__label" for="f_<?= $this->e($field['name']) ?>">
                  <?= $this->e($field['label']) ?>
                  <?php if (str_contains($field['rules'] ?? '', 'required')): ?><span class="req">*</span><?php endif; ?>
                </label>
              <?php endif; ?>

              <?= $control($field) ?>

              <?php if ($this->hasError($field['name'])): ?>
                <p class="field__error"><?= $this->error($field['name']) ?></p>
              <?php elseif (!empty($field['hint'])): ?>
                <p class="field__hint"><?= $this->e($field['hint']) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Sidebar groups -->
    <div class="col gap-4">

      <div class="card">
        <div class="card__body">
          <button type="submit" class="btn btn--primary btn--block mb-3">
            <?= $this->icon('check', 'ic ic--sm') ?>
            <?= $isEdit ? 'Save changes' : 'Create ' . $this->e(strtolower($resource->singular)) ?>
          </button>

          <a href="/admin/<?= $this->e($resource->key) ?>" class="btn btn--ghost btn--block">Cancel</a>

          <?php if ($isEdit): ?>
            <hr class="divider">
            <div class="tiny faint">
              Record #<?= (int) $row['id'] ?>
              <?php if (!empty($row['created_at'])): ?>
                · created <?= $this->date($row['created_at']) ?>
              <?php endif; ?>
              <?php if (!empty($row['updated_at'])): ?>
                · updated <?= $this->timeAgo($row['updated_at']) ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php foreach ($sidebarGroups as $groupLabel => $fields): ?>
        <div class="card">
          <div class="card__head"><h3 class="h3"><?= $this->e($groupLabel) ?></h3></div>
          <div class="card__body">
            <?php foreach ($fields as $field): ?>
              <div class="field">
                <?php if ($field['type'] !== 'switch'): ?>
                  <label class="field__label" for="f_<?= $this->e($field['name']) ?>">
                    <?= $this->e($field['label']) ?>
                    <?php if (str_contains($field['rules'] ?? '', 'required')): ?><span class="req">*</span><?php endif; ?>
                  </label>
                <?php endif; ?>

                <?= $control($field) ?>

                <?php if ($this->hasError($field['name'])): ?>
                  <p class="field__error"><?= $this->error($field['name']) ?></p>
                <?php elseif (!empty($field['hint'])): ?>
                  <p class="field__hint"><?= $this->e($field['hint']) ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
// Mirror the colour picker value into its readonly hex field.
document.querySelectorAll('input[type="color"]').forEach(function (picker) {
  const mirror = picker.parentElement.querySelector('input[readonly]');
  if (mirror) picker.addEventListener('input', function () { mirror.value = picker.value; });
});

// Live image preview when a URL or an /uploads path is pasted.
document.querySelectorAll('[data-image-input], input[type="url"]').forEach(function (input) {
  const preview = input.parentElement.querySelector('img');
  if (!preview) return;

  input.addEventListener('change', function () {
    if (input.value) preview.src = input.value;
  });
});
</script>
<?php $this->stop(); ?>

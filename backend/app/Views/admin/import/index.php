<?php

declare(strict_types=1);

/**
 * CSV import, step 1: choose a resource and supply the data.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,\Zuste\Admin\Resource> $resources
 * @var \Zuste\Admin\Resource|null $selected
 * @var array<string,mixed>|null $summary
 * @var int $maxBytes
 * @var int $maxRows
 */

$this->extend('admin.layouts.app');
$this->start('content');

/** Describe what a declared field will accept, for the reference table. */
$accepts = static function (array $field): string {
    $rules = (string) ($field['rules'] ?? '');
    $type  = (string) $field['type'];

    if ($type === 'switch') {
        return '1 or 0 (yes / no / true / false also accepted)';
    }

    if (preg_match('/\bin:([^|]+)/', $rules, $matches) === 1) {
        return 'one of: ' . str_replace(',', ', ', $matches[1]);
    }

    if ($type === 'select' && !empty($field['options'])) {
        $keys = array_filter(array_keys($field['options']), static fn ($k): bool => (string) $k !== '');

        return 'one of: ' . implode(', ', array_map('strval', $keys));
    }

    if ($type === 'datetime') {
        return 'a date and time, e.g. 2026-08-01 09:30:00';
    }

    if (str_contains($rules, 'hex_color')) {
        return 'a hex colour, e.g. #B80049';
    }

    if (str_contains($rules, 'slug')) {
        return 'lowercase-words-with-hyphens';
    }

    if (str_contains($rules, 'integer')) {
        return 'a whole number';
    }

    if (str_contains($rules, 'numeric')) {
        return 'a number';
    }

    if (preg_match('/\bmax:(\d+)/', $rules, $matches) === 1) {
        return 'text, up to ' . $matches[1] . ' characters';
    }

    return 'text';
};

$isRequired = static function (array $field): bool {
    foreach (explode('|', (string) ($field['rules'] ?? '')) as $rule) {
        if ($rule === 'required') {
            return true;
        }
    }

    return false;
};

?>

<?php if ($summary !== null): ?>
  <div class="callout callout--success mb-5">
    <?= $this->icon('check-circle', 'ic ic--sm') ?>
    <div>
      <strong>Import finished.</strong>
      <?= (int) $summary['created'] ?> created,
      <?= (int) $summary['updated'] ?> updated,
      <?= (int) $summary['skipped'] ?> skipped,
      out of <?= (int) $summary['total'] ?> rows read into <?= $this->e($summary['resource']) ?>.
      <?php if ((int) ($summary['recycled'] ?? 0) > 0): ?>
        <?= (int) $summary['recycled'] ?> of the skipped
        row<?= (int) $summary['recycled'] === 1 ? '' : 's' ?> matched a record in the recycle bin —
        restore or permanently delete those, then import them again.
      <?php endif; ?>
      <a href="/admin/<?= $this->e($summary['key']) ?>">Open <?= $this->e(strtolower((string) $summary['resource'])) ?></a>
    </div>
  </div>
<?php endif; ?>

<?php if ($resources === [] || $selected === null): ?>

  <div class="card">
    <div class="empty">
      <div class="empty__art"><?= $this->icon('upload', 'ic ic--lg') ?></div>
      <p class="empty__title">Nothing here you can import into</p>
      <p class="empty__text">
        Importing writes records, so it needs the same permission as creating one by hand —
        for example Categories manage, or CMS pages. Ask an administrator to grant one.
      </p>
    </div>
  </div>

<?php else: ?>

  <div class="grid grid--sidebar">

    <div class="grid gap-4">

      <!-- Step 1: what to import ---------------------------------------->
      <div class="card">
        <div class="card__head">
          <h2 class="h3">1. What are you importing?</h2>
          <span class="tiny muted"><?= count($resources) ?> available to you</span>
        </div>

        <div class="card__body">
          <form method="get" action="/admin/import">
            <div class="field" style="margin-bottom:0">
              <label class="field__label" for="resource-picker">Destination</label>
              <select class="select" id="resource-picker" name="resource" data-auto-submit>
                <?php foreach ($resources as $key => $resource): ?>
                  <option value="<?= $this->e($key) ?>" <?= $key === $selected->key ? 'selected' : '' ?>>
                    <?= $this->e($resource->plural) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="field__hint"><?= $this->e($selected->description) ?></p>
            </div>
            <noscript>
              <button type="submit" class="btn btn--outline btn--sm mt-2">Switch</button>
            </noscript>
          </form>
        </div>
      </div>

      <!-- Step 2: the data ---------------------------------------------->
      <form method="post" action="/admin/import/preview" enctype="multipart/form-data" class="card">
        <?= $this->csrf() ?>
        <input type="hidden" name="resource" value="<?= $this->e($selected->key) ?>">

        <div class="card__head">
          <h2 class="h3">2. Add your rows</h2>
          <span class="tiny muted">Nothing is written until you approve the dry run</span>
        </div>

        <div class="card__body">

          <div class="field">
            <label class="field__label" id="csv_file-label" for="csv_file">Upload a CSV file</label>
            <?php // The input lives inside the tile so it keeps its place in the tab order:
                  // it is hidden visually, never with display:none, which would drop it from
                  // the tab order and the accessibility tree and leave the label — which is
                  // not focusable — as the only way in. ?>
            <label class="imp-drop" for="csv_file" id="imp-drop">
              <?= $this->icon('upload', 'ic ic--lg') ?>
              <span class="imp-drop__title" id="csv_file-choice" data-drop-label>Choose a .csv file</span>
              <span class="imp-drop__hint" id="csv_file-limits">
                Up to <?= $this->e(\Zuste\Services\ImportService::formatBytes($maxBytes)) ?>
                and <?= $this->number($maxRows) ?> rows
              </span>
              <input id="csv_file" name="csv_file" type="file"
                     accept=".csv,.tsv,.txt,text/csv,text/plain"
                     aria-labelledby="csv_file-label csv_file-choice"
                     aria-describedby="csv_file-limits">
            </label>
          </div>

          <div class="imp-or"><span>or paste the rows</span></div>

          <div class="field">
            <label class="field__label" for="csv_text">Paste CSV</label>
            <textarea class="textarea mono" id="csv_text" name="csv_text" rows="8" spellcheck="false"
                      placeholder="<?= $this->e(implode(',', array_slice(\Zuste\Services\ImportService::templateHeaders($selected), 0, 4))) ?>&#10;…"></textarea>
            <p class="field__hint">
              The first line must name the columns. Quoted values, embedded commas and line breaks are all handled.
            </p>
          </div>

          <div class="field">
            <label class="field__label" for="delimiter">Separator</label>
            <select class="select" id="delimiter" name="delimiter" style="max-width:260px">
              <option value="auto">Detect automatically</option>
              <option value="comma">Comma</option>
              <option value="semicolon">Semicolon</option>
              <option value="tab">Tab</option>
              <option value="pipe">Pipe</option>
            </select>
            <p class="field__hint">Detection reads the first rows and picks whichever splits them consistently.</p>
          </div>

          <div class="row row--end gap-2">
            <a href="/admin/import/<?= $this->e($selected->key) ?>/template" class="btn btn--outline">
              <?= $this->icon('download', 'ic ic--sm') ?> Template
            </a>
            <button type="submit" class="btn btn--primary">
              <?= $this->icon('eye', 'ic ic--sm') ?> Check the data
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- Reference ------------------------------------------------------->
    <div class="grid gap-4" style="align-content:start">

      <div class="card">
        <div class="card__head"><h2 class="h3">Template</h2></div>
        <div class="card__body">
          <p class="small muted mb-3">
            The template carries one header row and one example row, built from what
            <?= $this->e(strtolower($selected->plural)) ?> actually accept. Delete the example
            row, add yours, and upload it back.
          </p>
          <a href="/admin/import/<?= $this->e($selected->key) ?>/template" class="btn btn--outline btn--sm">
            <?= $this->icon('download', 'ic ic--sm') ?>
            zuste-import-template-<?= $this->e($selected->key) ?>.csv
          </a>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <h2 class="h3">Columns</h2>
          <span class="tiny muted"><?= count($selected->fields) ?> fields</span>
        </div>

        <div class="card__body card__body--flush">
          <div class="table-wrap">
            <table class="table table--compact" style="min-width:0">
              <thead>
                <tr><th>Column</th><th>Accepts</th></tr>
              </thead>
              <tbody>
                <?php foreach ($selected->fields as $field): ?>
                  <tr>
                    <td>
                      <code class="mono small"><?= $this->e($field['name']) ?></code>
                      <?php if ($isRequired($field)): ?>
                        <span class="chip chip--error" style="margin-left:6px">Required</span>
                      <?php endif; ?>
                      <div class="tiny muted"><?= $this->e($field['label']) ?></div>
                    </td>
                    <td class="tiny muted"><?= $this->e($accepts($field)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="callout callout--info">
        <?= $this->icon('info', 'ic ic--sm') ?>
        <div class="small">
          Columns you leave out keep their normal default.
          <?php if ($selected->slugSource !== null): ?>
            Leave <code>slug</code> out and it is generated from
            <code><?= $this->e($selected->slugSource) ?></code>.
          <?php endif; ?>
          Headers the panel does not recognise are ignored — you will see exactly which on the next screen.
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
  .imp-drop {
    position: relative;
    display: grid; place-items: center; gap: 6px; text-align: center;
    padding: 30px 20px; cursor: pointer;
    border: 1.5px dashed var(--outline-variant); border-radius: var(--r-lg);
    background: var(--surface-low); color: var(--on-surface-variant);
    transition: border-color .15s, background .15s, color .15s;
  }
  /* Hidden from sight, kept in the tab order and the accessibility tree —
     the same trade the panel's .switch input makes. */
  .imp-drop input[type="file"] {
    position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0;
    overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%);
    white-space: nowrap; border: 0;
  }
  .imp-drop:hover, .imp-drop.is-over { border-color: var(--primary); color: var(--primary); background: var(--surface); }
  .imp-drop:focus-within { border-color: var(--primary); outline: 2px solid var(--primary); outline-offset: 2px; }
  .imp-drop__title { font-size: 14px; font-weight: 600; color: var(--on-surface); }
  .imp-drop.is-set { border-style: solid; border-color: var(--tertiary); }
  .imp-drop__hint { font-size: 12px; color: var(--outline); }

  .imp-or { display: flex; align-items: center; gap: 12px; margin: 4px 0 18px; color: var(--outline); font-size: 12px; }
  .imp-or::before, .imp-or::after { content: ''; flex: 1; height: 1px; background: var(--outline-variant); }
</style>
<script>
(function () {
  const input = document.getElementById('csv_file');
  const drop  = document.getElementById('imp-drop');

  if (!input || !drop) return;

  const label = drop.querySelector('[data-drop-label]');

  function describe() {
    if (input.files && input.files.length > 0) {
      label.textContent = input.files[0].name;
      drop.classList.add('is-set');
    } else {
      label.textContent = 'Choose a .csv file';
      drop.classList.remove('is-set');
    }
  }

  input.addEventListener('change', describe);

  ['dragenter', 'dragover'].forEach(function (name) {
    drop.addEventListener(name, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
  });

  ['dragleave', 'drop'].forEach(function (name) {
    drop.addEventListener(name, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
  });

  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files.length > 0) {
      input.files = e.dataTransfer.files;
      describe();
    }
  });
})();
</script>
<?php $this->stop(); ?>

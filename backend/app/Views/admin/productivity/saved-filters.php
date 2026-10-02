<?php

declare(strict_types=1);

/**
 * Saved filters: a named query string per module.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,list<array<string,mixed>>> $filtersByModule keyed by module label
 * @var int $total
 * @var array<string,array{label:string,path:string,permission:string}> $modules
 * @var string $selectedModule
 */

$this->extend('admin.layouts.app');
$this->start('content');

// Longest paths first so /admin/security/login-history is matched before /admin.
$modulePaths = [];
foreach ($modules as $key => $definition) {
    $modulePaths[] = ['key' => $key, 'path' => $definition['path']];
}
usort($modulePaths, static fn (array $a, array $b): int => strlen($b['path']) <=> strlen($a['path']));

?>

<div class="grid grid--sidebar">

  <div>
    <?php if ($filtersByModule === []): ?>

      <div class="card">
        <div class="empty">
          <div class="empty__art"><?= $this->icon('filter', 'ic ic--lg') ?></div>
          <p class="empty__title">No saved filters yet</p>
          <p class="empty__text">
            Filter a list screen the way you like it, copy the address from your browser,
            and paste it into the form to keep it.
          </p>
        </div>
      </div>

    <?php else: ?>

      <?php foreach ($filtersByModule as $moduleLabel => $rows): ?>
        <div class="card mb-4">
          <div class="card__head">
            <h2 class="h3"><?= $this->e($moduleLabel) ?></h2>
            <span class="small muted"><?= count($rows) ?> filter<?= count($rows) === 1 ? '' : 's' ?></span>
          </div>

          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Conditions</th>
                  <th>Owner</th>
                  <th class="text-right">Uses</th>
                  <th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <?php $id = (int) $row['id']; ?>
                  <tr>
                    <td>
                      <span class="small" style="font-weight:600"><?= $this->e($row['name']) ?></span>
                      <div class="chip-row mt-1">
                        <?php if ((int) $row['is_default'] === 1): ?>
                          <span class="chip chip--info">Default</span>
                        <?php endif; ?>
                        <?php if ((int) $row['is_shared'] === 1): ?>
                          <span class="chip chip--success">Shared</span>
                        <?php endif; ?>
                        <?php if (!empty($row['is_orphaned'])): ?>
                          <span class="chip chip--error">Module removed</span>
                        <?php endif; ?>
                      </div>
                    </td>

                    <td>
                      <?php if ($row['parts'] === []): ?>
                        <span class="tiny muted">No conditions — the plain list</span>
                      <?php else: ?>
                        <div class="chip-row">
                          <?php foreach ($row['parts'] as $part): ?>
                            <span class="chip chip--neutral">
                              <?= $this->e($part['key']) ?>:
                              <strong><?= $this->e(mb_substr($part['value'], 0, 40)) ?></strong>
                            </span>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td class="small muted">
                      <?= empty($row['is_mine']) ? $this->e($row['owner_name'] ?? 'Another admin') : 'You' ?>
                      <div class="tiny faint"><?= $this->date($row['created_at']) ?></div>
                    </td>

                    <td class="text-right mono small"><?= $this->number((int) $row['use_count']) ?></td>

                    <td class="actions">
                      <?php if (empty($row['is_orphaned'])): ?>
                        <form method="post" action="/admin/saved-filters/<?= $id ?>/apply" style="display:inline">
                          <?= $this->csrf() ?>
                          <button type="submit" class="btn btn--primary btn--sm">Apply</button>
                        </form>
                      <?php endif; ?>

                      <?php if (!empty($row['is_mine'])): ?>
                        <form method="post" action="/admin/saved-filters/<?= $id ?>/default" style="display:inline">
                          <?= $this->csrf() ?>
                          <button type="submit" class="btn btn--ghost btn--sm">
                            <?= (int) $row['is_default'] === 1 ? 'Unset default' : 'Make default' ?>
                          </button>
                        </form>

                        <form method="post" action="/admin/saved-filters/<?= $id ?>/share" style="display:inline">
                          <?= $this->csrf() ?>
                          <button type="submit" class="btn btn--ghost btn--sm">
                            <?= (int) $row['is_shared'] === 1 ? 'Make private' : 'Share' ?>
                          </button>
                        </form>

                        <button type="button" class="btn btn--ghost btn--sm" data-rename-toggle="<?= $id ?>">
                          Rename
                        </button>

                        <form method="post" action="/admin/saved-filters/<?= $id ?>/delete" style="display:inline">
                          <?= $this->csrf() ?>
                          <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error"
                                  aria-label="Delete filter"
                                  data-confirm="Delete the &quot;<?= $this->e($row['name']) ?>&quot; filter? Anyone it was shared with loses it too."
                                  data-confirm-title="Delete filter?"
                                  data-confirm-label="Delete"
                                  data-confirm-tone="danger">
                            <?= $this->icon('trash', 'ic ic--sm') ?>
                          </button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>

                  <?php if (!empty($row['is_mine'])): ?>
                    <tr class="hidden" data-rename-row="<?= $id ?>">
                      <td colspan="5">
                        <form method="post" action="/admin/saved-filters/<?= $id ?>/rename" class="row gap-2 row--wrap">
                          <?= $this->csrf() ?>
                          <input class="input flex-1" name="name" required minlength="2" maxlength="120"
                                 value="<?= $this->e($row['name']) ?>" aria-label="New name">
                          <button type="submit" class="btn btn--primary btn--sm">Save name</button>
                          <button type="button" class="btn btn--ghost btn--sm" data-rename-toggle="<?= $id ?>">Cancel</button>
                        </form>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>

    <?php endif; ?>
  </div>

  <div>
    <div class="card mb-4">
      <div class="card__head"><h2 class="h3">Save a filter</h2></div>

      <div class="card__body">
        <?php if ($modules === []): ?>
          <p class="small muted">
            You do not have access to any module that supports saved filters yet.
          </p>
        <?php else: ?>
          <form method="post" action="/admin/saved-filters" class="grid">
            <?= $this->csrf() ?>

            <div class="field">
              <label class="field__label req" for="sf_query">Address or query string</label>
              <?php // The path map rides on a data-* attribute: $this->json() escapes for
                    // an attribute, and HTML entities are not decoded inside a <script>. ?>
              <input class="input mono" id="sf_query" name="query_string" maxlength="2000"
                     placeholder="/admin/users?status=active&amp;sort_by=created_at"
                     data-module-detect
                     data-module-paths="<?= $this->json($modulePaths) ?>">
              <p class="field__hint">
                Paste the whole address from the list screen. Only the part after the
                question mark is stored, and it is re-encoded before it is saved.
              </p>
            </div>

            <div class="field">
              <label class="field__label req" for="sf_module">Module</label>
              <select class="select" id="sf_module" name="module" required>
                <?php foreach ($modules as $key => $definition): ?>
                  <option value="<?= $this->e($key) ?>" <?= $selectedModule === $key ? 'selected' : '' ?>>
                    <?= $this->e($definition['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="field__hint" data-module-note>The screen the filter opens.</p>
            </div>

            <div class="field">
              <label class="field__label req" for="sf_name">Name</label>
              <input class="input" id="sf_name" name="name" required minlength="2" maxlength="120"
                     placeholder="Suspended brands this month">
              <p class="field__hint">Something you will recognise in three months.</p>
            </div>

            <div class="field">
              <label class="checkbox small">
                <input type="checkbox" name="is_shared" value="1">
                <span>Share with the whole team</span>
              </label>
              <p class="field__hint">Shared filters are read-only for everyone but you.</p>
            </div>

            <div class="field">
              <label class="checkbox small">
                <input type="checkbox" name="is_default" value="1">
                <span>Make this my default for the module</span>
              </label>
              <p class="field__hint">Replaces any default you already set there.</p>
            </div>

            <div>
              <button type="submit" class="btn btn--primary">
                <?= $this->icon('plus', 'ic ic--sm') ?> Save filter
              </button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="callout callout--info">
      <?= $this->icon('info', 'ic ic--sm') ?>
      <div>
        <strong><?= $this->number($total) ?> filter<?= $total === 1 ? '' : 's' ?> visible.</strong>
        You always see your own, plus anything a colleague chose to share. Only the
        owner can rename, share or delete one.
      </div>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
(function () {
  /* Inline rename rows. */
  document.querySelectorAll('[data-rename-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const row = document.querySelector('[data-rename-row="' + button.dataset.renameToggle + '"]');
      if (!row) return;

      row.classList.toggle('hidden');

      const input = row.querySelector('input[name="name"]');
      if (input && !row.classList.contains('hidden')) input.focus();
    });
  });

  /* Pasting a full admin address selects the matching module automatically. */
  const field  = document.querySelector('[data-module-detect]');
  const select = document.getElementById('sf_module');
  const note   = document.querySelector('[data-module-note]');

  if (!field || !select) return;

  let paths = [];
  try {
    paths = JSON.parse(field.dataset.modulePaths || '[]');
  } catch (err) {
    paths = [];
  }

  const detect = () => {
    let value = field.value.trim();
    if (!value) return;

    // Ignore anything before the path so a full https:// address works too.
    const pathStart = value.indexOf('/admin');
    if (pathStart === -1) return;

    const withoutOrigin = value.slice(pathStart);
    const pathOnly = withoutOrigin.split('?')[0].replace(/\/+$/, '');

    const match = paths.find((entry) => pathOnly === entry.path);
    if (!match) return;

    select.value = match.key;
    if (note) note.textContent = 'Detected from the address you pasted.';
  };

  field.addEventListener('change', detect);
  field.addEventListener('paste', () => setTimeout(detect, 0));
})();
</script>
<?php $this->stop(); ?>

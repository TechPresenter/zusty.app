<?php

declare(strict_types=1);

/**
 * CSV import, step 2: column mapping and the dry-run report.
 *
 * Nothing on this screen has been written. The parsed payload is carried in a
 * hidden field so the server keeps no copy between steps; both buttons post it
 * back, and the commit action re-parses and re-validates it from scratch.
 *
 * @var \Zuste\Core\View $this
 * @var \Zuste\Admin\Resource $resource
 * @var array<string,mixed> $analysis
 * @var list<string> $headers
 * @var list<list<string>> $samples
 * @var string $delimiter
 * @var string $delimiterToken
 * @var string $csv
 * @var string $mode
 * @var list<array<string,mixed>> $fields
 * @var list<string> $required
 */

$this->extend('admin.layouts.app');
$this->start('content');

$counts       = $analysis['counts'];
$blocking     = $analysis['blocking'];
$problems     = $analysis['problems'];
$mapping      = $analysis['mapping'];
$uniqueColumn = $analysis['unique_column'];

$mapped   = array_values(array_filter($mapping, static fn (string $f): bool => $f !== ''));
$ignored  = [];

foreach ($headers as $index => $header) {
    if (($mapping[$index] ?? '') === '') {
        $ignored[] = $header === '' ? 'column ' . ($index + 1) : $header;
    }
}

$delimiterName = [',' => 'comma', ';' => 'semicolon', "\t" => 'tab', '|' => 'pipe'][$delimiter] ?? 'comma';

// Duplicates whose match sits in the recycle bin can never be written, in any
// mode, so they never count towards what "Update existing" would update.
$recycled   = (int) ($counts['recycled'] ?? 0);
$updatable  = (int) $counts['duplicates'] - $recycled;

$willWrite = $counts['valid'] + ($mode === 'update' ? $updatable : 0);
$willSkip  = (int) $counts['invalid'] + ($mode === 'update' ? $recycled : (int) $counts['duplicates']);
$canWrite  = $blocking === [] && $willWrite > 0 && !($mode === 'abort' && $counts['duplicates'] > 0);

$tiles = [
    ['label' => 'Rows read',   'value' => $counts['total'],      'tone' => 'var(--on-surface)'],
    ['label' => 'Valid',       'value' => $counts['valid'],      'tone' => 'var(--tertiary)'],
    ['label' => 'With errors', 'value' => $counts['invalid'],    'tone' => 'var(--error)'],
    ['label' => 'Duplicates',  'value' => $counts['duplicates'], 'tone' => 'var(--warning)'],
];

?>

<div class="row row--between row--wrap gap-3 mb-4">
  <div class="row gap-2">
    <span class="chip chip--info"><span class="chip__dot"></span> Dry run — nothing written yet</span>
    <span class="small muted">
      Target: <strong><?= $this->e($resource->plural) ?></strong> ·
      <?= count($headers) ?> column<?= count($headers) === 1 ? '' : 's' ?> ·
      <?= $this->e($delimiterName) ?>-separated
    </span>
  </div>
  <a href="/admin/import?resource=<?= $this->e($resource->key) ?>" class="btn btn--ghost btn--sm">
    <?= $this->icon('x', 'ic ic--sm') ?> Start over
  </a>
</div>

<div class="imp-tiles mb-5">
  <?php foreach ($tiles as $tile): ?>
    <div class="imp-tile" style="--tone:<?= $this->e($tile['tone']) ?>">
      <div class="imp-tile__value"><?= $this->number((int) $tile['value']) ?></div>
      <div class="imp-tile__label"><?= $this->e($tile['label']) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php foreach ($blocking as $message): ?>
  <div class="callout callout--error mb-3">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div><?= $this->e($message) ?></div>
  </div>
<?php endforeach; ?>

<?php if ($blocking === [] && $counts['invalid'] > 0): ?>
  <div class="callout callout--warning mb-3">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong><?= (int) $counts['invalid'] ?> row<?= $counts['invalid'] === 1 ? '' : 's' ?> will be skipped.</strong>
      They failed the same checks the <?= $this->e(strtolower($resource->singular)) ?> form applies.
      Fix them in your spreadsheet and import again, or continue and bring in only the rows that pass.
    </div>
  </div>
<?php endif; ?>

<?php if ($blocking === [] && $recycled > 0): ?>
  <div class="callout callout--warning mb-3">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong><?= $this->number($recycled) ?>
        row<?= $recycled === 1 ? ' matches' : 's match' ?> a deleted record.</strong>
      A <?= $this->e(strtolower($resource->singular)) ?> in the recycle bin still holds that
      <code class="mono"><?= $this->e((string) $uniqueColumn) ?></code>, so nothing can be written under it —
      not even with <strong>Update existing</strong>. Restore or permanently delete those records first,
      then import again. Until then those rows are skipped.
      <?php if ($this->can('recyclebin.manage')): ?>
        <a href="/admin/recycle-bin">Open the recycle bin</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($mode === 'abort' && $counts['duplicates'] > 0): ?>
  <div class="callout callout--error mb-3">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>Blocked by duplicates.</strong>
      You chose to stop if any duplicate turns up, and <?= (int) $counts['duplicates'] ?> did.
      Pick a different policy below, or remove those rows from the file.
    </div>
  </div>
<?php endif; ?>

<form method="post" action="/admin/import/preview" id="imp-form">
  <?= $this->csrf() ?>
  <input type="hidden" name="resource" value="<?= $this->e($resource->key) ?>">
  <input type="hidden" name="delimiter" value="<?= $this->e($delimiterToken) ?>">
  <?php // The parsed payload travels with the form; the server stores no copy. ?>
  <textarea class="hidden" name="csv_text" aria-hidden="true" tabindex="-1">
<?= $this->e($csv) ?></textarea>

  <div class="grid grid--sidebar">

    <div class="grid gap-4">

      <!-- Mapping ------------------------------------------------------->
      <div class="card">
        <div class="card__head">
          <h2 class="h3">Column mapping</h2>
          <span class="tiny muted"><?= count($mapped) ?> of <?= count($headers) ?> mapped</span>
        </div>

        <div class="card__body card__body--flush">
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th style="width:26%">Column in your file</th>
                  <th style="width:34%">Sample values</th>
                  <th style="width:40%">Goes into</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($headers as $index => $header): ?>
                  <?php $target = $mapping[$index] ?? ''; ?>
                  <tr>
                    <td>
                      <code class="mono small"><?= $header === '' ? '(no name)' : $this->e($header) ?></code>
                      <div class="tiny muted">column <?= (int) $index + 1 ?></div>
                    </td>

                    <td class="tiny muted">
                      <?php
                      $seen = [];
                      foreach ($samples as $sample) {
                          $value = trim((string) ($sample[$index] ?? ''));
                          if ($value !== '' && !in_array($value, $seen, true)) {
                              $seen[] = $value;
                          }
                      }
                      ?>
                      <?php if ($seen === []): ?>
                        <span class="faint">empty</span>
                      <?php else: ?>
                        <?php foreach ($seen as $value): ?>
                          <div class="truncate" style="max-width:280px"><?= $this->e(mb_substr($value, 0, 90)) ?></div>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </td>

                    <td>
                      <select class="select" name="map[<?= (int) $index ?>]"
                              aria-label="Field for <?= $this->e($header) ?>">
                        <option value="">Ignore this column</option>
                        <?php foreach ($fields as $field): ?>
                          <option value="<?= $this->e($field['name']) ?>"
                            <?= $target === $field['name'] ? 'selected' : '' ?>>
                            <?= $this->e($field['label']) ?>
                            (<?= $this->e($field['name']) ?>)<?= in_array($field['name'], $required, true) ? ' — required' : '' ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php if ($ignored !== []): ?>
          <div class="card__body" style="border-top:1px solid var(--outline-variant)">
            <p class="tiny muted">
              Ignored: <?= $this->e(implode(', ', array_map('strval', $ignored))) ?>.
              Nothing from those columns is written.
            </p>
          </div>
        <?php endif; ?>
      </div>

      <!-- Problems ------------------------------------------------------>
      <div class="card">
        <div class="card__head">
          <h2 class="h3">Report</h2>
          <?php if ($problems !== []): ?>
            <span class="tiny muted">
              <?= count($problems) > 20 ? 'first 20 of ' . $this->number(count($problems)) : $this->number(count($problems)) ?>
              problem<?= count($problems) === 1 ? '' : 's' ?>
            </span>
          <?php endif; ?>
        </div>

        <?php if ($problems === []): ?>
          <div class="empty">
            <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
            <p class="empty__title">Every row checks out</p>
            <p class="empty__text">
              All <?= $this->number((int) $counts['total']) ?> rows passed validation and none of them
              repeat a record you already have.
            </p>
          </div>
        <?php else: ?>
          <div class="card__body card__body--flush">
            <div class="table-wrap">
              <table class="table table--compact">
                <thead>
                  <tr><th style="width:90px">CSV line</th><th style="width:26%">Column</th><th>What is wrong</th></tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($problems, 0, 20) as $problem): ?>
                    <tr>
                      <td class="mono small"><?= (int) $problem['line'] ?></td>
                      <td><code class="mono small"><?= $this->e($problem['column']) ?></code></td>
                      <td class="small"><?= $this->e($problem['message']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <?php if (count($problems) > 20): ?>
            <div class="card__body" style="border-top:1px solid var(--outline-variant)">
              <p class="tiny muted">
                <?= $this->number(count($problems) - 20) ?> more problem<?= count($problems) - 20 === 1 ? '' : 's' ?>
                not shown. Fixing these first usually clears most of the rest.
              </p>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Decisions ------------------------------------------------------->
    <div class="grid gap-4" style="align-content:start">

      <div class="card">
        <div class="card__head"><h2 class="h3">Duplicates</h2></div>

        <div class="card__body">
          <?php if ($uniqueColumn === null): ?>
            <p class="small muted">
              <?= $this->e($resource->plural) ?> do not declare a unique column, so there is no
              honest way to tell a repeat from a new record. Every valid row will be created.
            </p>
            <input type="hidden" name="mode" value="skip">
          <?php else: ?>
            <p class="small muted mb-3">
              Matched on <code class="mono"><?= $this->e($uniqueColumn) ?></code>,
              against existing records and against earlier rows in this file.
            </p>

            <?php
            $choices = [
                'skip'   => ['Skip them', 'Leave existing records untouched and create only what is new.'],
                'update' => ['Update existing', 'Overwrite the mapped columns on the record that already exists.'],
                'abort'  => ['Stop the import', 'Write nothing at all if any duplicate is found.'],
            ];
            ?>

            <?php foreach ($choices as $value => [$title, $note]): ?>
              <label class="imp-choice<?= $mode === $value ? ' is-active' : '' ?>">
                <input type="radio" name="mode" value="<?= $this->e($value) ?>"
                       <?= $mode === $value ? 'checked' : '' ?>>
                <span>
                  <span class="imp-choice__title"><?= $this->e($title) ?></span>
                  <span class="imp-choice__note"><?= $this->e($note) ?></span>
                </span>
              </label>
            <?php endforeach; ?>

            <p class="tiny muted mt-3">
              <?= (int) $counts['duplicates'] ?> row<?= $counts['duplicates'] === 1 ? '' : 's' ?>
              currently match.
              <?php if ($recycled > 0): ?>
                <?= $this->number($recycled) ?> of them match a record in the recycle bin and
                <?= $recycled === 1 ? 'is' : 'are' ?> skipped whichever policy you pick.
              <?php endif; ?>
            </p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><h2 class="h3">Ready?</h2></div>

        <div class="card__body">
          <p class="small muted mb-3">
            <?php if ($blocking !== []): ?>
              Fix the mapping above before importing.
            <?php elseif ($mode === 'abort' && $counts['duplicates'] > 0): ?>
              Your duplicate policy stops this import. Choose another one, or clean the file up.
            <?php elseif (!$canWrite && $recycled > 0 && $updatable === 0): ?>
              Every valid row matches a record in the recycle bin, so there is nothing that can be
              written. Restore or permanently delete those records first.
            <?php elseif (!$canWrite && $counts['duplicates'] > 0): ?>
              Every valid row already exists. Switch to <strong>Update existing</strong> to bring the
              changes across, or leave it — nothing needs importing.
            <?php elseif (!$canWrite): ?>
              No row passed validation, so there is nothing to write.
            <?php else: ?>
              <?= $this->number((int) $counts['valid']) ?>
              record<?= $counts['valid'] === 1 ? '' : 's' ?> will be created<?php
              if ($mode === 'update' && $updatable > 0) {
                  echo ' and ' . $this->number($updatable) . ' updated';
              }
              ?>.
              <?php if ($willSkip > 0): ?>
                <?= $this->number($willSkip) ?> will be skipped.
              <?php endif; ?>
              It runs as one transaction: if any row fails, none of them land.
            <?php endif; ?>
          </p>

          <div class="grid gap-2">
            <button type="submit" class="btn btn--outline" formaction="/admin/import/preview">
              <?= $this->icon('refresh', 'ic ic--sm') ?> Re-check with this mapping
            </button>

            <button type="submit" class="btn btn--primary" id="imp-commit"
                    formaction="/admin/import/commit"
                    data-rows="<?= (int) $willWrite ?>"
                    data-target="<?= $this->e(strtolower($resource->plural)) ?>"
                    <?= $canWrite ? '' : 'disabled' ?>>
              <?= $this->icon('upload', 'ic ic--sm') ?>
              Import <?= $this->number((int) $willWrite) ?> row<?= $willWrite === 1 ? '' : 's' ?>
            </button>
          </div>

          <p class="tiny muted mt-3">
            Changing the mapping or the duplicate policy needs a re-check first, so the
            numbers above always describe what will actually happen.
          </p>
        </div>
      </div>
    </div>
  </div>
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
  .imp-tiles { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
  .imp-tile {
    background: var(--surface); border: 1px solid var(--outline-variant);
    border-radius: var(--r-lg); padding: 16px 18px; border-left: 3px solid var(--tone);
  }
  .imp-tile__value { font-size: 26px; font-weight: 800; letter-spacing: -.02em; color: var(--tone); line-height: 1.1; }
  .imp-tile__label { font-size: 12px; color: var(--on-surface-variant); margin-top: 4px; }

  .imp-choice {
    display: flex; align-items: flex-start; gap: 10px; cursor: pointer;
    padding: 11px 13px; margin-bottom: 8px;
    border: 1px solid var(--outline-variant); border-radius: var(--r-md);
    transition: border-color .15s, background .15s;
  }
  .imp-choice:hover { border-color: var(--outline); }
  .imp-choice.is-active { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 6%, transparent); }
  .imp-choice input { margin-top: 3px; accent-color: var(--primary); flex-shrink: 0; }
  .imp-choice__title { display: block; font-size: 13.5px; font-weight: 600; color: var(--on-surface); }
  .imp-choice__note { display: block; font-size: 12px; color: var(--on-surface-variant); margin-top: 2px; }
</style>
<script>
(function () {
  // Keep the selected policy visibly selected without a round trip.
  document.querySelectorAll('.imp-choice input[type="radio"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      document.querySelectorAll('.imp-choice').forEach(function (choice) {
        choice.classList.toggle('is-active', choice.contains(radio) && radio.checked);
      });
    });
  });

  // Confirm before writing. requestSubmit keeps the button's formaction, which
  // a plain form.submit() would discard.
  const form   = document.getElementById('imp-form');
  const commit = document.getElementById('imp-commit');

  if (!form || !commit || !window.Admin || typeof window.Admin.confirm !== 'function') return;

  let approved = false;

  commit.addEventListener('click', async function (event) {
    if (approved) return;

    event.preventDefault();

    const ok = await window.Admin.confirm({
      title: 'Import now?',
      message: 'This writes ' + commit.dataset.rows + ' row(s) into ' + commit.dataset.target +
               '. It runs as one transaction and cannot be undone from here.',
      confirmLabel: 'Import',
      cancelLabel: 'Cancel',
      tone: 'warning'
    });

    if (!ok) return;

    approved = true;
    commit.classList.add('btn--loading');

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit(commit);
    } else {
      form.action = commit.getAttribute('formaction');
      form.submit();
    }
  });
})();
</script>
<?php $this->stop(); ?>

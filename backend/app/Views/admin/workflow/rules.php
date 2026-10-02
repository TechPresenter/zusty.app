<?php

declare(strict_types=1);

/**
 * Automation rules: the rule book the automation runner evaluates.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var array{total:int,page:int,per_page:int,last_page:int,from:int,to:int} $pagination
 * @var int $storedTotal
 * @var int $activeTotal
 * @var int $shownTotal
 * @var array<string,mixed>|null $editRow
 * @var array<string,array{label:string,source:string,fields:list<string>}> $triggerEvents
 * @var array<string,array{label:string,value:string}> $operators
 * @var array<string,array{label:string,params:array<string,string>}> $actionTypes
 * @var bool $runnerJob
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

// A compact record per rule for the editor, so clicking Edit fills the form
// with exactly what is stored rather than a re-rendered approximation.
//
// $editRow is the rule a rejected edit was pointing at when it is not on this
// page. It has to be here or the form would quietly fall back to "create" and
// the next Save would insert a duplicate.
$editable = [];
foreach (array_merge($rows, $editRow === null ? [] : [$editRow]) as $row) {
    $editable[(string) $row['id']] = [
        'id'            => (int) $row['id'],
        'name'          => (string) $row['name'],
        'description'   => (string) ($row['description'] ?? ''),
        'trigger_event' => (string) $row['trigger_event'],
        'conditions'    => (string) ($row['conditions'] ?? ''),
        'actions'       => (string) $row['actions'],
        'is_active'     => (int) $row['is_active'] === 1,
    ];
}

$operatorMeta = [];
foreach ($operators as $symbol => $meta) {
    $operatorMeta[$symbol] = ['label' => $meta['label'], 'value' => $meta['value']];
}

$actionMeta = [];
foreach ($actionTypes as $type => $meta) {
    $skeleton = [];
    foreach ($meta['params'] as $key => $hint) {
        $skeleton[$key] = '';
    }

    $actionMeta[$type] = [
        'label'    => $meta['label'],
        'hint'     => implode(', ', array_map(
            static fn (string $key, string $hint): string => $key . ' — ' . $hint,
            array_keys($meta['params']),
            array_values($meta['params'])
        )),
        'skeleton' => $skeleton,
    ];
}

$eventMeta = [];
foreach ($triggerEvents as $key => $meta) {
    $eventMeta[$key] = ['label' => $meta['label'], 'fields' => $meta['fields'], 'source' => $meta['source']];
}

?>

<?php if ($runnerJob): ?>
  <div class="callout callout--info mb-5">
    <?= $this->icon('info', 'ic ic--sm') ?>
    <div>
      <strong>The automation runner is scheduled.</strong>
      Active rules are evaluated on its next pass. Each rule below shows how many times it has run.
    </div>
  </div>
<?php else: ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>The automation runner is not scheduled.</strong>
      Rules are stored and validated here, but nothing will evaluate them until the
      <code class="mono">automation:run</code> job exists in the scheduler and is enabled.
      Turn it back on under <a href="/admin/system">System → Jobs</a>, or add the crontab
      entry for it — until then <em>run count</em> stays where it is.
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--sidebar">

  <!-- Rule list -->
  <div class="card">
    <div class="card__head">
      <div>
        <h2 class="h3">Rules</h2>
        <p class="muted small mt-1">
          <?php if ($shownTotal !== $storedTotal): ?>
            <?= $this->number($shownTotal) ?> shown of <?= $this->number($storedTotal) ?> stored
          <?php else: ?>
            <?= $this->number($storedTotal) ?> stored
          <?php endif; ?>
          · <?= $this->number($activeTotal) ?> active
        </p>
      </div>
    </div>

    <?php $this->partial('admin.partials.toolbar', [
        'filters'           => $filters,
        'searchPlaceholder' => 'Search rules by name or description…',
        'selects'           => [
            ['name' => 'trigger_event', 'label' => 'Trigger', 'options' => array_merge(
                ['' => 'Any trigger'],
                array_map(static fn (array $m): string => $m['label'], $triggerEvents)
            )],
            ['name' => 'state', 'label' => 'State', 'options' => [
                '' => 'Any state', 'active' => 'Active', 'paused' => 'Paused',
            ]],
        ],
    ]); ?>

    <?php if ($rows === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('refresh', 'ic ic--lg') ?></div>
        <?php // Three different emptinesses, and only one of them means there
              // are no rules. Saying "No rules yet" over a filter that simply
              // matched nothing is the screen telling the operator their work
              // has disappeared. ?>
        <?php if ($storedTotal === 0): ?>
          <p class="empty__title">No rules yet</p>
          <p class="empty__text">Build one on the right. Start paused, test the conditions, then activate it.</p>
        <?php elseif ($shownTotal === 0): ?>
          <p class="empty__title">No rule matches this filter</p>
          <p class="empty__text">
            <?= $this->number($storedTotal) ?> rule<?= $storedTotal === 1 ? ' is' : 's are' ?> stored.
            <a href="/admin/workflow/rules">Clear the filter</a> to see <?= $storedTotal === 1 ? 'it' : 'them' ?>.
          </p>
        <?php else: ?>
          <p class="empty__title">Nothing on this page</p>
          <p class="empty__text">
            <?= $this->number($shownTotal) ?> rule<?= $shownTotal === 1 ? '' : 's' ?> match, but none of them is here.
            <a href="<?= $this->queryUrl(['page' => 1]) ?>">Go to the first page</a>.
          </p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Rule</th><th>Trigger</th><th class="text-right">Runs</th>
              <th>Last run</th><th>State</th><th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
              $event      = (string) $row['trigger_event'];
              $eventLabel = $triggerEvents[$event]['label'] ?? $event;
              $conditions = (string) ($row['conditions'] ?? '');
              $ruleCount  = 0;

              if ($conditions !== '') {
                  $decoded   = json_decode($conditions, true);
                  $ruleCount = is_array($decoded) && is_array($decoded['rules'] ?? null)
                      ? count($decoded['rules'])
                      : 0;
              }

              $decodedActions = json_decode((string) $row['actions'], true);
              $actionCount    = is_array($decodedActions) ? count($decodedActions) : 0;
              ?>
              <tr>
                <td>
                  <span class="small" style="font-weight:600"><?= $this->e($row['name']) ?></span>
                  <?php if (!empty($row['description'])): ?>
                    <div class="tiny muted truncate" style="max-width:280px"><?= $this->e($row['description']) ?></div>
                  <?php endif; ?>
                  <div class="tiny faint">
                    <?= (int) $ruleCount ?> condition<?= $ruleCount === 1 ? '' : 's' ?>
                    · <?= (int) $actionCount ?> action<?= $actionCount === 1 ? '' : 's' ?>
                    <?php if (!empty($row['created_by_name'])): ?>
                      · by <?= $this->e($row['created_by_name']) ?>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($row['last_error'])): ?>
                    <div class="tiny text-error truncate" style="max-width:280px"
                         title="<?= $this->e($row['last_error']) ?>">
                      <?= $this->e($row['last_error']) ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td>
                  <code class="mono tiny"><?= $this->e($event) ?></code>
                  <div class="tiny muted"><?= $this->e($eventLabel) ?></div>
                </td>

                <td class="text-right mono small"><?= $this->number((int) $row['run_count']) ?></td>

                <td class="small muted" style="white-space:nowrap">
                  <?= $row['last_run_at'] === null ? 'Never' : $this->timeAgo($row['last_run_at']) ?>
                </td>

                <td>
                  <span class="chip chip--<?= (int) $row['is_active'] === 1 ? 'success' : 'neutral' ?>">
                    <span class="chip__dot"></span>
                    <?= (int) $row['is_active'] === 1 ? 'Active' : 'Paused' ?>
                  </span>
                </td>

                <td class="actions">
                  <button type="button" class="btn btn--ghost btn--sm" data-edit-rule="<?= (int) $row['id'] ?>">
                    Edit
                  </button>

                  <form method="post" action="/admin/workflow/rules/<?= (int) $row['id'] ?>/toggle" style="display:inline">
                    <?= $this->csrf() ?>
                    <button type="submit" class="btn btn--ghost btn--sm">
                      <?= (int) $row['is_active'] === 1 ? 'Pause' : 'Activate' ?>
                    </button>
                  </form>

                  <form method="post" action="/admin/workflow/rules/<?= (int) $row['id'] ?>/duplicate" style="display:inline">
                    <?= $this->csrf() ?>
                    <button type="submit" class="btn btn--ghost btn--icon btn--sm" aria-label="Duplicate" title="Duplicate">
                      <?= $this->icon('file', 'ic ic--sm') ?>
                    </button>
                  </form>

                  <form method="post" action="/admin/workflow/rules/<?= (int) $row['id'] ?>/delete" style="display:inline">
                    <?= $this->csrf() ?>
                    <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error" aria-label="Delete"
                            data-confirm="Delete the rule &quot;<?= $this->e($row['name']) ?>&quot;? This cannot be undone."
                            data-confirm-title="Delete rule?" data-confirm-label="Delete" data-confirm-tone="danger">
                      <?= $this->icon('trash', 'ic ic--sm') ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
    <?php endif; ?>

    <!-- Trigger reference -->
    <div class="card__head" style="border-top:1px solid var(--outline-variant)">
      <div>
        <h2 class="h3">Trigger events</h2>
        <p class="muted small mt-1">The signal the runner polls for each event, and the payload it builds from it.</p>
      </div>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Event</th><th>Signal</th><th>Payload fields</th><th>Polled</th></tr>
        </thead>
        <tbody>
          <?php foreach ($triggerEvents as $key => $meta): ?>
            <tr>
              <td>
                <code class="mono tiny"><?= $this->e($key) ?></code>
                <div class="tiny muted"><?= $this->e($meta['label']) ?></div>
              </td>
              <td class="mono tiny muted"><?= $this->e($meta['source']) ?></td>
              <td class="tiny faint truncate" style="max-width:280px">
                <?= $this->e(implode(', ', $meta['fields'])) ?>
              </td>
              <td>
                <span class="chip chip--<?= $runnerJob ? 'success' : 'neutral' ?>">
                  <?= $runnerJob ? 'Every pass' : 'Runner off' ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Editor -->
  <div class="card">
    <div class="card__head">
      <h2 class="h3" id="editor-title">New rule</h2>
      <button type="button" class="btn btn--ghost btn--sm hidden" id="editor-reset">Cancel edit</button>
    </div>

    <div class="card__body">
      <form method="post" action="/admin/workflow/rules" class="grid" id="rule-form">
        <?= $this->csrf() ?>

        <?php // Which rule is being edited. A rejected submission bounces back
              // here with the input flashed, and without this the form would
              // point at "create" again and the next Save would add a duplicate
              // instead of updating. The server routes by URL and ignores it. ?>
        <input type="hidden" name="edit_id" id="edit-id" value="<?= $this->old('edit_id') ?>">

        <div class="field">
          <label class="field__label req" for="name">Name</label>
          <input class="input" id="name" name="name" required minlength="2" maxlength="150"
                 value="<?= $this->old('name') ?>" placeholder="Flag high-value first bookings">
          <?php if ($this->hasError('name')): ?>
            <p class="field__error"><?= $this->e($this->error('name')) ?></p>
          <?php endif; ?>
        </div>

        <div class="field">
          <label class="field__label" for="description">Description</label>
          <input class="input" id="description" name="description" maxlength="255"
                 value="<?= $this->old('description') ?>"
                 placeholder="What this is for, in one line">
        </div>

        <div class="field">
          <label class="field__label req" for="trigger_event">Trigger event</label>
          <select class="select" id="trigger_event" name="trigger_event" required>
            <?php foreach ($triggerEvents as $key => $meta): ?>
              <option value="<?= $this->e($key) ?>" <?= $this->old('trigger_event') === $key ? 'selected' : '' ?>>
                <?= $this->e($meta['label']) ?> — <?= $this->e($key) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="field__hint" id="event-source"></p>
          <?php if ($this->hasError('trigger_event')): ?>
            <p class="field__error"><?= $this->e($this->error('trigger_event')) ?></p>
          <?php endif; ?>
        </div>

        <!-- Conditions -->
        <div class="field">
          <div class="row row--between gap-2">
            <?php // A caption for the whole group, not a control label: it has
                  // nothing to point `for` at, and a <label> that labels nothing
                  // is announced as an orphan. Same treatment as
                  // developer/index.php and sitemap/index.php. ?>
            <span class="field__label">Conditions</span>
            <div class="row gap-1">
              <button type="button" class="chip chip-filter chip-filter--active" data-mode="conditions" data-value="builder">Builder</button>
              <button type="button" class="chip chip-filter" data-mode="conditions" data-value="raw">Raw JSON</button>
            </div>
          </div>

          <div data-panel="conditions-builder">
            <div class="row gap-2 mb-2">
              <label class="small muted" for="conditions-match">Fire when</label>
              <select class="select" id="conditions-match" style="max-width:130px">
                <option value="all">all match</option>
                <option value="any">any match</option>
              </select>
            </div>

            <div id="condition-rows" class="grid gap-2"></div>

            <button type="button" class="btn btn--outline btn--sm mt-2" id="add-condition">
              <?= $this->icon('plus', 'ic ic--sm') ?> Add condition
            </button>

            <p class="field__hint">
              No conditions means the rule fires on every <span data-event-label></span> event.
            </p>
          </div>

          <div data-panel="conditions-raw" class="hidden">
            <textarea class="textarea mono" name="conditions" id="conditions" rows="8" spellcheck="false"
                      aria-label="Conditions JSON"
                      placeholder='{"match":"all","rules":[]}'><?= $this->old('conditions') ?></textarea>
            <p class="field__hint">
              An object with <code>match</code> (<code>all</code> or <code>any</code>) and a
              <code>rules</code> list of <code>{"field":…,"operator":…,"value":…}</code>.
            </p>
          </div>

          <?php if ($this->hasError('conditions')): ?>
            <p class="field__error"><?= $this->e($this->error('conditions')) ?></p>
          <?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="field">
          <div class="row row--between gap-2">
            <span class="field__label req">Actions</span>
            <div class="row gap-1">
              <button type="button" class="chip chip-filter chip-filter--active" data-mode="actions" data-value="builder">Builder</button>
              <button type="button" class="chip chip-filter" data-mode="actions" data-value="raw">Raw JSON</button>
            </div>
          </div>

          <div data-panel="actions-builder">
            <div id="action-rows" class="grid gap-2"></div>

            <button type="button" class="btn btn--outline btn--sm mt-2" id="add-action">
              <?= $this->icon('plus', 'ic ic--sm') ?> Add action
            </button>
          </div>

          <div data-panel="actions-raw" class="hidden">
            <textarea class="textarea mono" name="actions" id="actions" rows="8" spellcheck="false"
                      aria-label="Actions JSON"
                      placeholder='[{"type":"log_only","params":{"note":"…"}}]'><?= $this->old('actions') ?></textarea>
            <p class="field__hint">
              A list of <code>{"type":…,"params":{…}}</code>. Every action runs in order.
            </p>
          </div>

          <?php if ($this->hasError('actions')): ?>
            <p class="field__error"><?= $this->e($this->error('actions')) ?></p>
          <?php endif; ?>
        </div>

        <div class="field">
          <label class="switch">
            <input type="checkbox" name="is_active" value="1" id="is_active"
                   <?= $this->old('is_active') === '1' ? 'checked' : '' ?>>
            <span class="switch__track"></span>
            <span class="small">Active</span>
          </label>
          <p class="field__hint">Leave off while you are still testing the conditions.</p>
        </div>

        <div class="row gap-2">
          <button type="submit" class="btn btn--primary" id="rule-submit">
            <?= $this->icon('check', 'ic ic--sm') ?> Save rule
          </button>
        </div>
      </form>
    </div>

    <!-- Tester -->
    <div class="card__head" style="border-top:1px solid var(--outline-variant)">
      <div>
        <h2 class="h3">Test the conditions</h2>
        <p class="muted small mt-1">Runs the same evaluator the runner will use.</p>
      </div>
    </div>

    <div class="card__body">
      <div class="field">
        <label class="field__label" for="test-payload">Sample payload</label>
        <textarea class="textarea mono" id="test-payload" rows="6" spellcheck="false"></textarea>
        <p class="field__hint">Edit the values to match a real event, then test.</p>
      </div>

      <div class="row gap-2">
        <button type="button" class="btn btn--outline btn--sm" id="test-run">
          <?= $this->icon('refresh', 'ic ic--sm') ?> Test conditions
        </button>
        <button type="button" class="btn btn--ghost btn--sm" id="test-reset">Reset payload</button>
      </div>

      <?php // The result is swapped in wholesale on an explicit click and
            // cleared by Reset, so role="status" announces "Evaluating…" and
            // then the verdict exactly once per action. ?>
      <div id="test-output" class="mt-3" role="status" aria-live="polite"></div>
    </div>
  </div>
</div>

<datalist id="field-suggestions"></datalist>

<?php // The editor's reference data rides on data-* attributes rather than
      // being echoed into the <script> block. $this->json() HTML-escapes its
      // output, which is exactly right for an attribute (the parser decodes it
      // before JavaScript sees it) and wrong inside <script>, where the browser
      // never decodes entities and the whole block would fail to parse. ?>
<div id="rule-data" hidden
     data-operators="<?= $this->json($operatorMeta) ?>"
     data-actions="<?= $this->json($actionMeta) ?>"
     data-events="<?= $this->json($eventMeta) ?>"
     data-rules="<?= $this->json($editable) ?>"></div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
/* Builder rows — a grid that collapses on narrow screens. */
.rb-row {
  display: grid; grid-template-columns: 1fr 150px 1fr 36px; gap: 8px; align-items: start;
}
.rb-row--action { grid-template-columns: 190px 1fr 36px; }
@media (max-width: 720px) {
  .rb-row, .rb-row--action { grid-template-columns: 1fr; }
}
.rb-remove {
  width: 36px; height: 38px; display: inline-flex; align-items: center; justify-content: center;
  border: 1px solid var(--outline-variant); border-radius: var(--r); background: transparent;
  color: var(--error); cursor: pointer;
}
.rb-remove:hover { background: var(--surface-variant); }
.rb-hint { font-size: 11px; color: var(--on-surface-variant); margin-top: 4px; }
.rb-result { border: 1px solid var(--outline-variant); border-radius: var(--r); overflow: hidden; }
.rb-result__line {
  display: flex; gap: 8px; align-items: baseline; padding: 8px 12px; font-size: 12px;
}
.rb-result__line + .rb-result__line { border-top: 1px solid var(--outline-variant); }
</style>

<script>
(function () {
  'use strict';

  var dataHost = document.getElementById('rule-data');

  var OPERATORS = JSON.parse(dataHost.dataset.operators);
  var ACTIONS   = JSON.parse(dataHost.dataset.actions);
  var EVENTS    = JSON.parse(dataHost.dataset.events);
  var RULES     = JSON.parse(dataHost.dataset.rules);

  var form           = document.getElementById('rule-form');
  var conditionsBox  = document.getElementById('conditions');
  var actionsBox     = document.getElementById('actions');
  var conditionRows  = document.getElementById('condition-rows');
  var actionRows     = document.getElementById('action-rows');
  var matchSelect    = document.getElementById('conditions-match');
  var eventSelect    = document.getElementById('trigger_event');
  var suggestions    = document.getElementById('field-suggestions');
  var payloadBox     = document.getElementById('test-payload');
  var testOutput     = document.getElementById('test-output');
  var editorTitle    = document.getElementById('editor-title');
  var editorReset    = document.getElementById('editor-reset');
  var editIdField    = document.getElementById('edit-id');
  var csrf           = document.querySelector('meta[name=csrf-token]');

  var mode = { conditions: 'builder', actions: 'builder' };
  var payloadTouched = false;

  /* ---------------------------------------------------------------
     Condition rows
     --------------------------------------------------------------- */

  function conditionRow(rule) {
    rule = rule || { field: '', operator: '=', value: '' };

    var row = document.createElement('div');
    row.className = 'rb-row';

    // A placeholder is not an accessible name — it is gone the moment the
    // field has a value — so every control built here carries its own label.
    var field = document.createElement('input');
    field.className = 'input';
    field.placeholder = 'field';
    field.setAttribute('aria-label', 'Condition field');
    field.setAttribute('list', 'field-suggestions');
    field.value = rule.field || '';
    field.dataset.role = 'field';

    var operator = document.createElement('select');
    operator.className = 'select';
    operator.setAttribute('aria-label', 'Operator');
    operator.dataset.role = 'operator';

    Object.keys(OPERATORS).forEach(function (symbol) {
      var option = document.createElement('option');
      option.value = symbol;
      option.textContent = OPERATORS[symbol].label;
      operator.appendChild(option);
    });

    operator.value = OPERATORS[rule.operator] ? rule.operator : '=';

    var value = document.createElement('input');
    value.className = 'input';
    value.placeholder = 'value';
    value.setAttribute('aria-label', 'Condition value');
    value.dataset.role = 'value';
    value.value = Array.isArray(rule.value) ? rule.value.join(', ') : (rule.value === undefined || rule.value === null ? '' : String(rule.value));

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'rb-remove';
    remove.setAttribute('aria-label', 'Remove condition');
    remove.textContent = '×';

    remove.addEventListener('click', function () {
      row.remove();
      serialiseConditions();
    });

    function syncValueState() {
      var arity = OPERATORS[operator.value] ? OPERATORS[operator.value].value : 'single';
      value.disabled = arity === 'none';
      value.placeholder = arity === 'list' ? 'a, b, c' : (arity === 'none' ? 'not needed' : 'value');
      if (arity === 'none') { value.value = ''; }
    }

    [field, operator, value].forEach(function (input) {
      input.addEventListener('input', serialiseConditions);
      input.addEventListener('change', function () { syncValueState(); serialiseConditions(); });
    });

    row.appendChild(field);
    row.appendChild(operator);
    row.appendChild(value);
    row.appendChild(remove);
    syncValueState();

    return row;
  }

  function serialiseConditions() {
    if (mode.conditions !== 'builder') { return; }

    var rules = [];

    Array.prototype.forEach.call(conditionRows.children, function (row) {
      var field    = row.querySelector('[data-role="field"]').value.trim();
      var operator = row.querySelector('[data-role="operator"]').value;
      var raw      = row.querySelector('[data-role="value"]').value;

      if (field === '') { return; }

      var entry = { field: field, operator: operator };
      var arity = OPERATORS[operator] ? OPERATORS[operator].value : 'single';

      if (arity === 'list') {
        entry.value = raw.split(',').map(function (part) { return part.trim(); })
                         .filter(function (part) { return part !== ''; });
      } else if (arity !== 'none') {
        entry.value = raw;
      }

      rules.push(entry);
    });

    conditionsBox.value = rules.length === 0 && matchSelect.value === 'all'
      ? ''
      : JSON.stringify({ match: matchSelect.value, rules: rules }, null, 2);
  }

  function hydrateConditions() {
    var raw = conditionsBox.value.trim();
    conditionRows.innerHTML = '';

    if (raw === '') {
      matchSelect.value = 'all';
      return true;
    }

    var parsed;
    try { parsed = JSON.parse(raw); } catch (e) { return false; }

    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) { return false; }
    if (parsed.match !== 'all' && parsed.match !== 'any') { return false; }
    if (!Array.isArray(parsed.rules)) { return false; }

    var supported = parsed.rules.every(function (rule) {
      return rule && typeof rule === 'object' && !Array.isArray(rule)
          && typeof rule.field === 'string' && OPERATORS[rule.operator];
    });

    if (!supported) { return false; }

    matchSelect.value = parsed.match;
    parsed.rules.forEach(function (rule) { conditionRows.appendChild(conditionRow(rule)); });

    return true;
  }

  /* ---------------------------------------------------------------
     Action rows
     --------------------------------------------------------------- */

  function actionRow(action) {
    action = action || {};

    var row = document.createElement('div');
    row.className = 'rb-row rb-row--action';

    var typeWrap = document.createElement('div');
    var type = document.createElement('select');
    type.className = 'select';
    type.setAttribute('aria-label', 'Action type');
    type.dataset.role = 'type';

    Object.keys(ACTIONS).forEach(function (key) {
      var option = document.createElement('option');
      option.value = key;
      option.textContent = ACTIONS[key].label;
      type.appendChild(option);
    });

    type.value = ACTIONS[action.type] ? action.type : Object.keys(ACTIONS)[0];
    typeWrap.appendChild(type);

    var paramsWrap = document.createElement('div');
    var params = document.createElement('input');
    params.className = 'input mono';
    // The placeholder here is a JSON skeleton, so it would be a poor name even
    // if a placeholder counted as one.
    params.setAttribute('aria-label', 'Action parameters (JSON)');
    params.dataset.role = 'params';
    params.spellcheck = false;

    var hint = document.createElement('p');
    hint.className = 'rb-hint';

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'rb-remove';
    remove.setAttribute('aria-label', 'Remove action');
    remove.textContent = '×';

    remove.addEventListener('click', function () {
      row.remove();
      serialiseActions();
    });

    function applyHint() {
      hint.textContent = ACTIONS[type.value] ? ACTIONS[type.value].hint : '';
      params.placeholder = JSON.stringify(ACTIONS[type.value] ? ACTIONS[type.value].skeleton : {});
    }

    params.value = action.params && typeof action.params === 'object'
      ? JSON.stringify(action.params)
      : JSON.stringify(ACTIONS[type.value] ? ACTIONS[type.value].skeleton : {});

    type.addEventListener('change', function () {
      applyHint();
      params.value = JSON.stringify(ACTIONS[type.value].skeleton);
      serialiseActions();
    });

    params.addEventListener('input', serialiseActions);

    paramsWrap.appendChild(params);
    paramsWrap.appendChild(hint);
    applyHint();

    row.appendChild(typeWrap);
    row.appendChild(paramsWrap);
    row.appendChild(remove);

    return row;
  }

  function serialiseActions() {
    if (mode.actions !== 'builder') { return; }

    var list = [];
    var broken = false;

    Array.prototype.forEach.call(actionRows.children, function (row) {
      var type = row.querySelector('[data-role="type"]').value;
      var raw  = row.querySelector('[data-role="params"]').value.trim();
      var parsed = {};

      if (raw !== '') {
        try {
          parsed = JSON.parse(raw);
        } catch (e) {
          broken = true;
          return;
        }

        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
          broken = true;
          return;
        }
      }

      list.push({ type: type, params: parsed });
    });

    // A half-typed params object must not silently drop an action, so the
    // textarea keeps its previous value until the JSON parses again.
    if (broken) { return; }

    actionsBox.value = list.length === 0 ? '' : JSON.stringify(list, null, 2);
  }

  function hydrateActions() {
    var raw = actionsBox.value.trim();
    actionRows.innerHTML = '';

    if (raw === '') { return true; }

    var parsed;
    try { parsed = JSON.parse(raw); } catch (e) { return false; }

    if (!Array.isArray(parsed)) { return false; }

    var supported = parsed.every(function (action) {
      return action && typeof action === 'object' && !Array.isArray(action) && ACTIONS[action.type];
    });

    if (!supported) { return false; }

    parsed.forEach(function (action) { actionRows.appendChild(actionRow(action)); });

    return true;
  }

  /* ---------------------------------------------------------------
     Mode switching
     --------------------------------------------------------------- */

  function setMode(section, next) {
    if (next === 'builder') {
      var ok = section === 'conditions' ? hydrateConditions() : hydrateActions();

      if (!ok) {
        window.Admin.toast(
          'That JSON does not fit the builder, so it stays in raw mode. Clear it to use the builder.',
          'warning'
        );
        return;
      }
    }

    mode[section] = next;

    document.querySelectorAll('[data-mode="' + section + '"]').forEach(function (button) {
      button.classList.toggle('chip-filter--active', button.dataset.value === next);
    });

    document.querySelector('[data-panel="' + section + '-builder"]').classList.toggle('hidden', next !== 'builder');
    document.querySelector('[data-panel="' + section + '-raw"]').classList.toggle('hidden', next === 'builder');

    if (next === 'builder') {
      if (section === 'conditions') { serialiseConditions(); } else { serialiseActions(); }
    }
  }

  document.querySelectorAll('[data-mode]').forEach(function (button) {
    button.addEventListener('click', function () {
      setMode(button.dataset.mode, button.dataset.value);
    });
  });

  /* ---------------------------------------------------------------
     Event metadata: field suggestions and the sample payload
     --------------------------------------------------------------- */

  function samplePayload(eventKey) {
    var meta = EVENTS[eventKey];
    var sample = {};

    if (!meta) { return sample; }

    meta.fields.forEach(function (field) {
      sample[field] = /(_id|^id$|count|score|rate|amount|earning|rating|tokens)/.test(field) ? 0 : '';
    });

    return sample;
  }

  function applyEvent() {
    var key  = eventSelect.value;
    var meta = EVENTS[key];

    suggestions.innerHTML = '';

    if (meta) {
      meta.fields.forEach(function (field) {
        var option = document.createElement('option');
        option.value = field;
        suggestions.appendChild(option);
      });

      document.getElementById('event-source').textContent =
        'Built from ' + meta.source + '. Payload fields: ' + meta.fields.join(', ') + '.';

      document.querySelectorAll('[data-event-label]').forEach(function (node) {
        node.textContent = meta.label.toLowerCase();
      });
    }

    if (!payloadTouched) {
      payloadBox.value = JSON.stringify(samplePayload(key), null, 2);
    }
  }

  eventSelect.addEventListener('change', applyEvent);
  payloadBox.addEventListener('input', function () { payloadTouched = true; });

  document.getElementById('test-reset').addEventListener('click', function () {
    payloadTouched = false;
    applyEvent();
    testOutput.innerHTML = '';
  });

  /* ---------------------------------------------------------------
     Tester
     --------------------------------------------------------------- */

  document.getElementById('test-run').addEventListener('click', async function () {
    if (mode.conditions === 'builder') { serialiseConditions(); }

    testOutput.innerHTML = '<p class="small muted">Evaluating…</p>';

    var body = new URLSearchParams();
    body.set('conditions', conditionsBox.value);
    body.set('payload', payloadBox.value);

    try {
      var response = await fetch('/admin/workflow/rules/test', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': csrf ? csrf.content : ''
        },
        body: body.toString()
      });

      var payload = await response.json();

      if (!payload.success) {
        testOutput.innerHTML = '<div class="callout callout--error"><div>' + escapeHtml(payload.message) + '</div></div>';
        return;
      }

      renderResult(payload.data, payload.message);
    } catch (error) {
      testOutput.innerHTML = '<div class="callout callout--error"><div>Could not reach the server.</div></div>';
    }
  });

  function renderResult(data, message) {
    var html = '<div class="callout callout--' + (data.passed ? 'success' : 'warning') + ' mb-3"><div>'
             + escapeHtml(message) + ' Matching on <strong>' + escapeHtml(data.match) + '</strong>.</div></div>';

    if (!data.results.length) {
      html += '<p class="small muted">No conditions, so this rule fires on every event of this type.</p>';
      testOutput.innerHTML = html;
      return;
    }

    html += '<div class="rb-result">';

    data.results.forEach(function (result) {
      html += '<div class="rb-result__line">'
            + '<span class="chip chip--' + (result.passed ? 'success' : 'error') + '">'
            + (result.passed ? 'pass' : 'fail') + '</span>'
            + '<span class="mono">' + escapeHtml(result.field) + '</span>'
            + '<span class="muted">' + escapeHtml(labelFor(result.operator)) + '</span>'
            + '<span class="mono">' + escapeHtml(format(result.expected)) + '</span>'
            + '<span class="faint">actual ' + escapeHtml(result.present ? format(result.actual) : 'missing') + '</span>'
            + '</div>';
    });

    html += '</div>';
    testOutput.innerHTML = html;
  }

  function labelFor(operator) {
    return OPERATORS[operator] ? OPERATORS[operator].label : operator;
  }

  function format(value) {
    if (value === null || value === undefined) { return '—'; }
    if (typeof value === 'object') { return JSON.stringify(value); }
    return String(value);
  }

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* ---------------------------------------------------------------
     Editing an existing rule
     --------------------------------------------------------------- */

  function loadRule(id) {
    var rule = RULES[String(id)];
    if (!rule) { return; }

    form.action = '/admin/workflow/rules/' + rule.id;
    editorTitle.textContent = 'Edit: ' + rule.name;
    editorReset.classList.remove('hidden');
    editIdField.value = String(rule.id);

    document.getElementById('name').value = rule.name;
    document.getElementById('description').value = rule.description;
    eventSelect.value = rule.trigger_event;
    document.getElementById('is_active').checked = rule.is_active;

    conditionsBox.value = rule.conditions;
    actionsBox.value = rule.actions;

    setMode('conditions', hydrateConditions() ? 'builder' : 'raw');
    setMode('actions', hydrateActions() ? 'builder' : 'raw');

    payloadTouched = false;
    applyEvent();
    testOutput.innerHTML = '';

    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  document.querySelectorAll('[data-edit-rule]').forEach(function (button) {
    button.addEventListener('click', function () { loadRule(button.dataset.editRule); });
  });

  editorReset.addEventListener('click', function () {
    form.reset();
    form.action = '/admin/workflow/rules';
    editorTitle.textContent = 'New rule';
    editorReset.classList.add('hidden');
    // form.reset() restores the attribute value, which after a rejected edit
    // is the rule id, so this has to be cleared explicitly.
    editIdField.value = '';
    conditionsBox.value = '';
    actionsBox.value = '';
    conditionRows.innerHTML = '';
    actionRows.innerHTML = '';
    actionRows.appendChild(actionRow(null));
    setMode('conditions', 'builder');
    setMode('actions', 'builder');
    payloadTouched = false;
    applyEvent();
    testOutput.innerHTML = '';
  });

  /* ---------------------------------------------------------------
     Wiring
     --------------------------------------------------------------- */

  document.getElementById('add-condition').addEventListener('click', function () {
    conditionRows.appendChild(conditionRow(null));
    serialiseConditions();
  });

  document.getElementById('add-action').addEventListener('click', function () {
    actionRows.appendChild(actionRow(null));
    serialiseActions();
  });

  matchSelect.addEventListener('change', serialiseConditions);

  // The textareas are the submitted value in both modes, so the builder is
  // flushed into them one last time before the form leaves the page.
  form.addEventListener('submit', function () {
    serialiseConditions();
    serialiseActions();
  });

  // A rejected edit comes back with the input flashed. Point the form at the
  // same rule again so Save updates it rather than creating a second one. If
  // the rule went away in the meantime the form stays on "create".
  (function restoreEditTarget() {
    var pending = editIdField.value.trim();

    if (pending === '' || !RULES[pending]) {
      editIdField.value = '';

      return;
    }

    form.action = '/admin/workflow/rules/' + RULES[pending].id;
    editorTitle.textContent = 'Edit: ' + RULES[pending].name;
    editorReset.classList.remove('hidden');
  })();

  // Re-entering after a validation failure keeps whatever was typed: if the
  // old input still fits the builder it is shown there, otherwise raw wins.
  setMode('conditions', hydrateConditions() ? 'builder' : 'raw');

  if (actionsBox.value.trim() === '') {
    actionRows.appendChild(actionRow(null));
    setMode('actions', 'builder');
  } else {
    setMode('actions', hydrateActions() ? 'builder' : 'raw');
  }

  applyEvent();
})();
</script>
<?php $this->stop(); ?>

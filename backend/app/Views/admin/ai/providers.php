<?php

declare(strict_types=1);

/**
 * AI providers: credentials, models and pricing.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $providers
 * @var array<int,list<array<string,mixed>>> $models
 * @var array<string,array{label:string,auth:string,chat_path:string,doc:string}> $adapters
 * @var array{total:int,configured:int,enabled:int,spend:float,active:array<string,mixed>|null} $summary
 */

$this->extend('admin.layouts.app');
$this->start('content');

$adapterOptions = [];
foreach ($adapters as $key => $meta) {
    $adapterOptions[$key] = $meta['label'];
}

?>

<div class="wgrid mb-5">
  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#632CE5">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('star') ?></div></div>
      <div class="kpi__value"><?= $this->number($summary['enabled']) ?></div>
      <div class="kpi__label">Enabled providers</div>
      <div class="kpi__foot"><?= $this->number($summary['configured']) ?> have a key</div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#2563EB">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('grid') ?></div></div>
      <div class="kpi__value"><?= $this->number($summary['total']) ?></div>
      <div class="kpi__label">Providers configured</div>
      <div class="kpi__foot">Across all adapters</div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:#006A34">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('wallet') ?></div></div>
      <div class="kpi__value"><?= $this->money($summary['spend'], 'USD') ?></div>
      <div class="kpi__label">Spend this month</div>
      <div class="kpi__foot">Across every provider</div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:<?= $summary['active'] !== null ? '#006A34' : '#B45309' ?>">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('check-circle') ?></div></div>
      <div class="kpi__value" style="font-size:20px">
        <?= $summary['active'] !== null ? $this->e($summary['active']['name']) : 'None' ?>
      </div>
      <div class="kpi__label">Serving requests</div>
      <div class="kpi__foot">
        <?= $summary['active'] !== null
            ? $this->e((string) ($summary['active']['default_model'] ?? 'no default model'))
            : 'AI features are off' ?>
      </div>
    </div>
  </div>
</div>

<?php if ($summary['active'] === null): ?>
  <div class="callout callout--info mb-5">
    <?= $this->icon('info', 'ic ic--sm') ?>
    <div>
      <strong>No provider is serving AI requests.</strong>
      Every provider below ships switched off with no key. Paste a key, pick a default model,
      run <em>Test</em> to confirm the credentials work, then enable it.
    </div>
  </div>
<?php endif; ?>

<div class="callout callout--warning mb-5">
  <?= $this->icon('alert', 'ic ic--sm') ?>
  <div>
    <strong>Model prices are only seeded for Anthropic.</strong>
    Cost reporting multiplies token counts by the per-model rates below, so a model with no
    price contributes nothing to the spend figures. Add the current published rates for any
    other provider you enable — an out-of-date price silently produces wrong costs.
  </div>
</div>

<?php foreach ($providers as $provider): ?>
  <?php
  $pid          = (int) $provider['id'];
  $hasKey       = ($provider['api_key'] ?? null) !== null;
  $providerRows = $models[$pid] ?? [];
  $budget       = $provider['monthly_budget'] !== null ? (float) $provider['monthly_budget'] : null;
  $spent        = (float) $provider['spend_this_month'];
  $budgetPct    = ($budget !== null && $budget > 0) ? min(100, (int) round($spent / $budget * 100)) : null;
  ?>

  <div class="card mb-5">
    <div class="card__head">
      <div class="row gap-3">
        <div>
          <h2 class="h3">
            <?= $this->e($provider['name']) ?>
            <?php if ((int) $provider['is_default'] === 1): ?>
              <span class="chip chip--info">Default</span>
            <?php endif; ?>
          </h2>
          <p class="muted small mt-1">
            <code class="mono"><?= $this->e($provider['code']) ?></code>
            · <?= $this->e($adapters[$provider['adapter']]['label'] ?? $provider['adapter']) ?>
            <?php if (!empty($provider['description'])): ?>
              · <?= $this->e($provider['description']) ?>
            <?php endif; ?>
          </p>
        </div>
      </div>

      <div class="row gap-2">
        <span class="chip chip--<?= (int) $provider['is_enabled'] === 1 ? 'success' : 'neutral' ?>">
          <span class="chip__dot"></span><?= (int) $provider['is_enabled'] === 1 ? 'Enabled' : 'Off' ?>
        </span>
        <span class="chip chip--<?= $hasKey ? 'success' : 'warning' ?>">
          <?= $hasKey ? 'Key stored' : 'No key' ?>
        </span>
        <?php if (!empty($provider['verified_at'])): ?>
          <span class="chip chip--success" title="Last successful test">
            Verified <?= $this->timeAgo($provider['verified_at']) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>

    <div class="card__body">
      <?php if (!empty($provider['last_error'])): ?>
        <div class="callout callout--error mb-4">
          <?= $this->icon('alert', 'ic ic--sm') ?>
          <div><strong>Last test failed.</strong> <?= $this->e($provider['last_error']) ?></div>
        </div>
      <?php endif; ?>

      <div class="row gap-4 mb-4 row--wrap">
        <div>
          <div class="label">Calls (30d)</div>
          <div class="mono"><?= $this->number((int) $provider['calls_30d']) ?></div>
        </div>
        <div>
          <div class="label">Spend this month</div>
          <div class="mono"><?= $this->money($spent, 'USD') ?></div>
        </div>
        <div>
          <div class="label">Monthly budget</div>
          <div class="mono">
            <?= $budget !== null ? $this->money($budget, 'USD') : 'No cap' ?>
            <?php if ($budgetPct !== null): ?>
              <span class="chip chip--<?= $budgetPct >= 100 ? 'error' : ($budgetPct >= 80 ? 'warning' : 'neutral') ?>">
                <?= $budgetPct ?>%
              </span>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <div class="label">Models priced</div>
          <div class="mono"><?= $this->number(count($providerRows)) ?></div>
        </div>
      </div>

      <?php if ($this->can('ai.manage')): ?>
        <details class="mb-4">
          <summary class="small" style="cursor:pointer;font-weight:600">Credentials and settings</summary>

          <form method="post" action="/admin/ai/providers/<?= $pid ?>" class="grid mt-3">
            <?= $this->csrf() ?>

            <div class="grid grid--3">
              <div class="field">
                <label class="field__label" for="name-<?= $pid ?>">Display name</label>
                <input class="input" id="name-<?= $pid ?>" name="name" required maxlength="100"
                       value="<?= $this->e($provider['name']) ?>">
              </div>

              <div class="field">
                <label class="field__label" for="adapter-<?= $pid ?>">Adapter</label>
                <select class="select" id="adapter-<?= $pid ?>" name="adapter">
                  <?php foreach ($adapterOptions as $value => $label): ?>
                    <option value="<?= $this->e($value) ?>" <?= $provider['adapter'] === $value ? 'selected' : '' ?>>
                      <?= $this->e($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="field__hint"><?= $this->e($adapters[$provider['adapter']]['doc'] ?? '') ?></p>
              </div>

              <div class="field">
                <label class="field__label" for="model-<?= $pid ?>">Default model</label>
                <input class="input mono" id="model-<?= $pid ?>" name="default_model" maxlength="80"
                       value="<?= $this->e($provider['default_model'] ?? '') ?>"
                       list="models-<?= $pid ?>" placeholder="claude-opus-5">
                <datalist id="models-<?= $pid ?>">
                  <?php foreach ($providerRows as $model): ?>
                    <option value="<?= $this->e($model['model_code']) ?>"></option>
                  <?php endforeach; ?>
                </datalist>
              </div>
            </div>

            <div class="field">
              <label class="field__label" for="key-<?= $pid ?>">API key</label>
              <input class="input mono" id="key-<?= $pid ?>" name="api_key" type="password"
                     autocomplete="new-password" maxlength="400"
                     placeholder="<?= $hasKey ? 'Stored — leave blank to keep it' : 'Paste the key' ?>">
              <p class="field__hint">
                Encrypted before it is stored and never shown again.
                Leave blank to keep the current key.
                <?php if ($hasKey): ?>
                  <button type="submit" form="clear-key-<?= $pid ?>" class="btn btn--ghost btn--sm text-error"
                          data-confirm="The stored key will be deleted and this provider switched off."
                          data-confirm-title="Remove the key?" data-confirm-label="Remove"
                          data-confirm-tone="danger">Remove stored key</button>
                <?php endif; ?>
              </p>
            </div>

            <div class="grid grid--3">
              <div class="field">
                <label class="field__label" for="base-<?= $pid ?>">Base URL</label>
                <input class="input mono" id="base-<?= $pid ?>" name="api_base_url" maxlength="255"
                       value="<?= $this->e($provider['api_base_url'] ?? '') ?>" placeholder="https://…">
              </div>

              <div class="field">
                <label class="field__label" for="ver-<?= $pid ?>">API version</label>
                <input class="input mono" id="ver-<?= $pid ?>" name="api_version" maxlength="40"
                       value="<?= $this->e($provider['api_version'] ?? '') ?>">
                <p class="field__hint">Anthropic and Azure pin a dated version.</p>
              </div>

              <div class="field">
                <label class="field__label" for="org-<?= $pid ?>">Organisation / project id</label>
                <input class="input mono" id="org-<?= $pid ?>" name="organisation_id" maxlength="120"
                       value="<?= $this->e($provider['organisation_id'] ?? '') ?>">
              </div>
            </div>

            <div class="grid grid--4">
              <div class="field">
                <label class="field__label" for="budget-<?= $pid ?>">Monthly budget (USD)</label>
                <input class="input" id="budget-<?= $pid ?>" name="monthly_budget" type="number" step="0.01" min="0"
                       value="<?= $this->e($provider['monthly_budget'] ?? '') ?>" placeholder="No cap">
              </div>
              <div class="field">
                <label class="field__label" for="prio-<?= $pid ?>">Priority</label>
                <input class="input" id="prio-<?= $pid ?>" name="priority" type="number" min="0" max="9999"
                       value="<?= (int) $provider['priority'] ?>">
                <p class="field__hint">Lower runs first.</p>
              </div>
              <div class="field">
                <label class="field__label" for="timeout-<?= $pid ?>">Timeout (s)</label>
                <input class="input" id="timeout-<?= $pid ?>" name="timeout_seconds" type="number" min="5" max="600"
                       value="<?= (int) $provider['timeout_seconds'] ?>">
              </div>
              <div class="field">
                <label class="field__label" for="retries-<?= $pid ?>">Max retries</label>
                <input class="input" id="retries-<?= $pid ?>" name="max_retries" type="number" min="0" max="10"
                       value="<?= (int) $provider['max_retries'] ?>">
              </div>
            </div>

            <div class="row gap-4 row--wrap">
              <label class="row gap-2 small">
                <input type="checkbox" name="is_enabled" value="1" <?= (int) $provider['is_enabled'] === 1 ? 'checked' : '' ?>>
                Enabled
              </label>
              <label class="row gap-2 small">
                <input type="checkbox" name="is_default" value="1" <?= (int) $provider['is_default'] === 1 ? 'checked' : '' ?>>
                Use as the default provider
              </label>
            </div>

            <div class="row gap-2">
              <button type="submit" class="btn btn--primary btn--sm">
                <?= $this->icon('check', 'ic ic--sm') ?> Save
              </button>

              <button type="submit" form="test-<?= $pid ?>" class="btn btn--outline btn--sm" <?= $hasKey ? '' : 'disabled' ?>>
                <?= $this->icon('play', 'ic ic--sm') ?> Test connection
              </button>

              <?php if ((int) $provider['is_default'] !== 1 && $hasKey): ?>
                <button type="submit" form="default-<?= $pid ?>" class="btn btn--ghost btn--sm">Make default</button>
              <?php endif; ?>

              <span class="toolbar__spacer"></span>

              <button type="submit" form="delete-<?= $pid ?>" class="btn btn--ghost btn--sm text-error"
                      data-confirm="The provider is removed from the list. Its cost history is kept."
                      data-confirm-title="Remove this provider?" data-confirm-label="Remove"
                      data-confirm-tone="danger">Remove provider</button>
            </div>
          </form>
        </details>
      <?php endif; ?>

      <div class="row row--between mb-2">
        <h3 class="label">Models and pricing</h3>
        <span class="tiny faint">Costs are per million tokens</span>
      </div>

      <?php if ($providerRows === []): ?>
        <div class="callout callout--info">
          <?= $this->icon('info', 'ic ic--sm') ?>
          <div>
            No models listed. Calls to this provider will be logged with a cost of zero until you
            add each model and its current published rates.
          </div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Model</th><th>Label</th>
                <th class="text-right">Input / MTok</th><th class="text-right">Output / MTok</th>
                <th class="text-right">Context</th><th class="text-right">Max output</th>
                <th>State</th>
                <?php if ($this->can('ai.manage')): ?><th class="text-right">Actions</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($providerRows as $model): ?>
                <tr>
                  <td><code class="mono small"><?= $this->e($model['model_code']) ?></code></td>
                  <td class="small"><?= $this->e($model['label']) ?></td>
                  <td class="text-right mono small">
                    <?= $model['input_cost_per_mtok'] !== null
                        ? '$' . number_format((float) $model['input_cost_per_mtok'], 2)
                        : '<span class="text-warning">unpriced</span>' ?>
                  </td>
                  <td class="text-right mono small">
                    <?= $model['output_cost_per_mtok'] !== null
                        ? '$' . number_format((float) $model['output_cost_per_mtok'], 2)
                        : '<span class="text-warning">unpriced</span>' ?>
                  </td>
                  <td class="text-right mono tiny">
                    <?= $model['context_window'] !== null ? $this->compact((int) $model['context_window']) : '—' ?>
                  </td>
                  <td class="text-right mono tiny">
                    <?= $model['max_output_tokens'] !== null ? $this->compact((int) $model['max_output_tokens']) : '—' ?>
                  </td>
                  <td>
                    <span class="chip chip--<?= (int) $model['is_active'] === 1 ? 'success' : 'neutral' ?>">
                      <?= (int) $model['is_active'] === 1 ? 'Active' : 'Hidden' ?>
                    </span>
                  </td>
                  <?php if ($this->can('ai.manage')): ?>
                    <td class="actions">
                      <form method="post" action="/admin/ai/providers/<?= $pid ?>/models/<?= (int) $model['id'] ?>/delete" style="display:inline">
                        <?= $this->csrf() ?>
                        <button type="submit" class="btn btn--ghost btn--icon btn--sm text-error" aria-label="Remove model"
                                data-confirm="Remove <?= $this->e($model['model_code']) ?> from this provider?"
                                data-confirm-title="Remove model?" data-confirm-label="Remove" data-confirm-tone="danger">
                          <?= $this->icon('x', 'ic ic--sm') ?>
                        </button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($this->can('ai.manage')): ?>
        <details class="mt-3">
          <summary class="small" style="cursor:pointer;font-weight:600">Add a model</summary>

          <form method="post" action="/admin/ai/providers/<?= $pid ?>/models" class="grid mt-3">
            <?= $this->csrf() ?>

            <div class="grid grid--3">
              <div class="field">
                <label class="field__label" for="mcode-<?= $pid ?>">Model id</label>
                <input class="input mono" id="mcode-<?= $pid ?>" name="model_code" required maxlength="80"
                       placeholder="exact id sent on the wire">
              </div>
              <div class="field">
                <label class="field__label" for="mlabel-<?= $pid ?>">Label</label>
                <input class="input" id="mlabel-<?= $pid ?>" name="label" required maxlength="100">
              </div>
              <div class="field">
                <label class="field__label" for="msort-<?= $pid ?>">Sort order</label>
                <input class="input" id="msort-<?= $pid ?>" name="sort_order" type="number" min="0" value="100">
              </div>
            </div>

            <div class="grid grid--4">
              <div class="field">
                <label class="field__label" for="min-<?= $pid ?>">Input $ / MTok</label>
                <input class="input" id="min-<?= $pid ?>" name="input_cost_per_mtok" type="number" step="0.0001" min="0">
              </div>
              <div class="field">
                <label class="field__label" for="mout-<?= $pid ?>">Output $ / MTok</label>
                <input class="input" id="mout-<?= $pid ?>" name="output_cost_per_mtok" type="number" step="0.0001" min="0">
              </div>
              <div class="field">
                <label class="field__label" for="mctx-<?= $pid ?>">Context window</label>
                <input class="input" id="mctx-<?= $pid ?>" name="context_window" type="number" min="0">
              </div>
              <div class="field">
                <label class="field__label" for="mmax-<?= $pid ?>">Max output tokens</label>
                <input class="input" id="mmax-<?= $pid ?>" name="max_output_tokens" type="number" min="0">
              </div>
            </div>

            <div class="row gap-4 row--wrap">
              <label class="row gap-2 small"><input type="checkbox" name="supports_vision" value="1"> Vision</label>
              <label class="row gap-2 small"><input type="checkbox" name="supports_tools" value="1" checked> Tool use</label>
              <label class="row gap-2 small"><input type="checkbox" name="is_active" value="1" checked> Active</label>
            </div>

            <div>
              <button type="submit" class="btn btn--outline btn--sm">
                <?= $this->icon('plus', 'ic ic--sm') ?> Add model
              </button>
            </div>
          </form>
        </details>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($this->can('ai.manage')): ?>
    <?php // Standalone forms so the buttons above can live inside the main form. ?>
    <form id="test-<?= $pid ?>" method="post" action="/admin/ai/providers/<?= $pid ?>/test" hidden><?= $this->csrf() ?></form>
    <form id="default-<?= $pid ?>" method="post" action="/admin/ai/providers/<?= $pid ?>/default" hidden><?= $this->csrf() ?></form>
    <form id="clear-key-<?= $pid ?>" method="post" action="/admin/ai/providers/<?= $pid ?>/clear-key" hidden><?= $this->csrf() ?></form>
    <form id="delete-<?= $pid ?>" method="post" action="/admin/ai/providers/<?= $pid ?>/delete" hidden><?= $this->csrf() ?></form>
  <?php endif; ?>
<?php endforeach; ?>

<?php if ($this->can('ai.manage')): ?>
  <div class="card">
    <div class="card__head"><h2 class="h3">Add a provider</h2></div>

    <div class="card__body">
      <form method="post" action="/admin/ai/providers" class="grid">
        <?= $this->csrf() ?>

        <div class="grid grid--3">
          <div class="field">
            <label class="field__label" for="new-code">Code</label>
            <input class="input mono" id="new-code" name="code" required maxlength="40" placeholder="my-provider">
            <p class="field__hint">Lowercase, used in logs. Cannot be changed later.</p>
          </div>
          <div class="field">
            <label class="field__label" for="new-name">Display name</label>
            <input class="input" id="new-name" name="name" required maxlength="100">
          </div>
          <div class="field">
            <label class="field__label" for="new-adapter">Adapter</label>
            <select class="select" id="new-adapter" name="adapter">
              <?php foreach ($adapterOptions as $value => $label): ?>
                <option value="<?= $this->e($value) ?>"><?= $this->e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid grid--2">
          <div class="field">
            <label class="field__label" for="new-base">Base URL</label>
            <input class="input mono" id="new-base" name="api_base_url" maxlength="255" placeholder="https://…">
          </div>
          <div class="field">
            <label class="field__label" for="new-key">API key</label>
            <input class="input mono" id="new-key" name="api_key" type="password" autocomplete="new-password" maxlength="400">
          </div>
        </div>

        <div>
          <button type="submit" class="btn btn--primary">
            <?= $this->icon('plus', 'ic ic--sm') ?> Add provider
          </button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<link rel="stylesheet" href="/admin/assets/css/widgets.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<?php $this->stop(); ?>

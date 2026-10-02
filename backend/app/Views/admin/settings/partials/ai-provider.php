<?php

declare(strict_types=1);

/**
 * Settings -> AI: the provider, key and model behind the app's AI features.
 *
 * The key is write-only. The page learns whether one is stored and nothing
 * else; the app never sees it at all, because it calls /api/v1/ai/* and the
 * server calls the provider.
 *
 * @var \Zuste\Core\View $this
 * @var list<array{id:int,name:string,has_key:bool,default_model:string,is_enabled:bool,verified_at:?string,last_error:?string}> $aiProviders
 * @var int $aiCurrentId
 * @var array<int,list<array{code:string,label:string}>> $aiModels
 * @var string|null $aiActiveName
 * @var array<string,mixed> $aiFeatures
 * @var float $aiMonthSpend
 * @var int $aiCallsToday
 * @var bool $canManage
 */

$current = null;

foreach ($aiProviders as $provider) {
    if ($provider['id'] === $aiCurrentId) {
        $current = $provider;
    }
}

$current ??= $aiProviders[0] ?? null;

$featureLabels = [
    'bio_writer'     => 'Bio writer',
    'campaign_brief' => 'Brief writer',
    'creator_match'  => 'Creator matching',
];

$live = array_keys(array_filter(array_intersect_key($aiFeatures, $featureLabels)));

$why = match (true) {
    $live !== []                => null,
    $aiActiveName === null      => 'Add an API key below to start.',
    empty($aiFeatures['enabled']) => 'Turn on "AI features enabled" below.',
    default                     => 'Every feature switch below is off.',
};

?>

<div class="card">
  <div class="card__head">
    <div>
      <h3 class="h3">Provider &amp; API key</h3>
      <p class="tiny muted">The app never sees this key. It asks Zusty, and the server calls the provider.</p>
    </div>

    <?php if ($canManage && $aiActiveName !== null): ?>
      <button type="button" class="btn btn--outline btn--sm" onclick="testIntegration('ai')">
        <?= $this->icon('refresh', 'ic ic--sm') ?> Test connection
      </button>
    <?php endif; ?>
  </div>

  <div class="card__body col gap-4">

    <div class="row gap-2" style="flex-wrap:wrap;align-items:center">
      <span class="small" style="font-weight:600">Live in the app:</span>
      <?php foreach ($featureLabels as $key => $label): ?>
        <span class="chip chip--<?= in_array($key, $live, true) ? 'success' : 'neutral' ?>">
          <?php if (in_array($key, $live, true)): ?><span class="chip__dot"></span><?php endif; ?>
          <?= $this->e($label) ?>
        </span>
      <?php endforeach; ?>
      <?php if ($why !== null): ?>
        <span class="tiny muted"><?= $this->e($why) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($current === null): ?>
      <p class="small muted">No providers are configured. Add one under <a href="/admin/ai/providers">AI → Providers</a>.</p>
    <?php else: ?>
      <form method="post" action="/admin/settings/ai/provider" id="ai-provider-form"
            data-providers="<?= $this->json(array_column($aiProviders, null, 'id')) ?>"
            data-models="<?= $this->json($aiModels) ?>">
        <?= $this->csrf() ?>

        <div class="form-grid form-grid--2">
          <div class="field">
            <label class="field__label" for="ai_provider">Provider</label>
            <select class="select" id="ai_provider" name="provider_id"<?= $canManage ? '' : ' disabled' ?>>
              <?php foreach ($aiProviders as $provider): ?>
                <option value="<?= (int) $provider['id'] ?>"<?= $provider['id'] === $current['id'] ? ' selected' : '' ?>>
                  <?= $this->e($provider['name']) ?><?= $provider['has_key'] ? ' · key saved' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="field__label" for="ai_model">Model</label>
            <input class="input mono" id="ai_model" name="model" list="ai_model_options" maxlength="80" required
                   value="<?= $this->e($current['default_model']) ?>"<?= $canManage ? '' : ' disabled' ?>>
            <datalist id="ai_model_options">
              <?php foreach ($aiModels[$current['id']] ?? [] as $model): ?>
                <option value="<?= $this->e($model['code']) ?>"><?= $this->e($model['label']) ?></option>
              <?php endforeach; ?>
            </datalist>
            <p class="field__hint">Pick a suggestion or type any model name the provider offers.</p>
          </div>
        </div>

        <div class="field">
          <label class="field__label" for="ai_key">
            API key <span class="chip chip--neutral" style="font-size:9px;margin-left:6px">Encrypted</span>
          </label>
          <input class="input mono" id="ai_key" name="api_key" type="password" maxlength="500" autocomplete="new-password"
                 placeholder="<?= $current['has_key'] ? 'A key is saved. Leave blank to keep it.' : 'Paste the API key' ?>"<?= $canManage ? '' : ' disabled' ?>>
          <p class="field__hint" id="ai_key_status">
            <?php if (!$current['has_key']): ?>
              No key saved for <?= $this->e($current['name']) ?> yet.
            <?php elseif ($current['last_error']): ?>
              <span class="text-error">Last test failed: <?= $this->e($current['last_error']) ?></span>
            <?php elseif ($current['verified_at']): ?>
              Key verified <?= $this->timeAgo($current['verified_at']) ?>.
            <?php else: ?>
              Key saved but not tested yet.
            <?php endif; ?>
          </p>
        </div>

        <?php if ($canManage): ?>
          <div class="row gap-2" style="flex-wrap:wrap">
            <button type="submit" class="btn btn--primary">
              <?= $this->icon('check', 'ic ic--sm') ?> Save provider
            </button>
          </div>
        <?php endif; ?>
      </form>

      <?php if ($canManage && $current['has_key']): ?>
        <form method="post" action="/admin/settings/ai/clear-key">
          <?= $this->csrf() ?>
          <input type="hidden" name="provider_id" value="<?= (int) $current['id'] ?>">
          <button type="submit" class="btn btn--ghost btn--sm text-error"
                  data-confirm="AI features in the app stop working until a new key is saved."
                  data-confirm-title="Remove the <?= $this->e($current['name']) ?> key?" data-confirm-label="Remove key">
            <?= $this->icon('trash', 'ic ic--sm') ?> Remove key
          </button>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <div class="row gap-3 small muted" style="flex-wrap:wrap;border-top:1px solid var(--outline-variant);padding-top:12px">
      <span><?= $this->e(\Zuste\Controllers\Admin\AiController::formatCost($aiMonthSpend)) ?> spent this month</span>
      <span>·</span>
      <span><?= (int) $aiCallsToday ?> request<?= $aiCallsToday === 1 ? '' : 's' ?> today</span>
      <span>·</span>
      <a href="/admin/ai/logs">Usage logs</a>
      <a href="/admin/ai-prompts">Prompt templates</a>
      <a href="/admin/ai/providers">Advanced provider settings</a>
    </div>
  </div>
</div>

<script>
(() => {
  const form = document.getElementById('ai-provider-form');
  if (!form) return;

  const providers = JSON.parse(form.dataset.providers);
  const models    = JSON.parse(form.dataset.models);
  const select    = document.getElementById('ai_provider');
  const model     = document.getElementById('ai_model');
  const key       = document.getElementById('ai_key');
  const options   = document.getElementById('ai_model_options');
  const status    = document.getElementById('ai_key_status');

  // Switching provider swaps the model suggestions and says whether that
  // provider already has a key; nothing is saved until the form is submitted.
  select.addEventListener('change', () => {
    const provider = providers[select.value];
    const list     = models[select.value] || [];

    options.replaceChildren(...list.map((m) => {
      const option = document.createElement('option');
      option.value = m.code;
      option.textContent = m.label;
      return option;
    }));

    model.value = provider.default_model || (list[0] ? list[0].code : '');
    key.value = '';
    key.placeholder = provider.has_key ? 'A key is saved. Leave blank to keep it.' : 'Paste the API key';
    status.textContent = provider.has_key ? 'A key is saved for ' + provider.name + '.' : 'No key saved for ' + provider.name + ' yet.';
  });
})();
</script>

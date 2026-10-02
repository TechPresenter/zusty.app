<?php

declare(strict_types=1);

/**
 * AI overview: spend against budget, prompt usage, and a read-only summary of
 * the setup (which lives in Settings -> AI).
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $prompts
 * @var array{enabled:bool,provider:?string,model:string,verified:bool,features:array<string,mixed>,limit:int} $setup
 * @var bool $canManage
 * @var float $monthSpend
 * @var float $budget
 * @var array<string,mixed> $windowSummary
 * @var int $windowDays
 * @var list<array<string,mixed>> $recentFailures
 * @var int $promptCount
 */

$this->extend('admin.layouts.app');
$this->start('content');

$cost = static fn (mixed $value): string => \Zuste\Controllers\Admin\AiController::formatCost((float) $value);

$enabled    = (bool) $setup['enabled'];
$usedPct    = $budget > 0 ? min(100, (int) round($monthSpend / $budget * 100)) : 0;
$overBudget = $budget > 0 && $monthSpend >= $budget;

$calls     = (int) ($windowSummary['calls'] ?? 0);
$successes = (int) ($windowSummary['successes'] ?? 0);
$rate      = $calls > 0 ? (int) round($successes / $calls * 100) : 0;

$activePrompts = 0;
foreach ($prompts as $prompt) {
    if ((int) $prompt['is_active'] === 1) {
        $activePrompts++;
    }
}

$blockers = [];

if (!$enabled) {
    $blockers[] = 'the master switch is off';
}

if ($setup['provider'] === null) {
    $blockers[] = 'no provider has an API key';
}

if ($activePrompts === 0) {
    $blockers[] = 'no prompt is active';
}

if ($overBudget) {
    $blockers[] = 'this month is already at the budget';
}

?>

<?php if ($blockers === []): ?>
  <div class="callout callout--success mb-5">
    <?= $this->icon('check-circle', 'ic ic--sm') ?>
    <div>
      <strong>AI is configured and switched on.</strong>
      Calls go to <?= $this->e((string) $setup['provider']) ?> using
      <code class="mono"><?= $this->e($setup['model'] !== '' ? $setup['model'] : 'the model named on each prompt') ?></code>.
    </div>
  </div>
<?php else: ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>AI features will not run.</strong>
      <?= $this->e(ucfirst(implode(', ', array_slice($blockers, 0, -1)) . (count($blockers) > 1 ? ' and ' : '') . end($blockers))) ?>.
      <a href="/admin/settings?group=ai">Open Settings → AI</a>
    </div>
  </div>
<?php endif; ?>

<div class="wgrid mb-5">

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:<?= $enabled ? '#006A34' : '#79747E' ?>">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('star') ?></div></div>
      <div class="kpi__value"><?= $enabled ? 'On' : 'Off' ?></div>
      <div class="kpi__label">AI features</div>
      <div class="kpi__foot"><?= $setup['provider'] === null ? 'No provider set up' : $this->e((string) $setup['provider']) . ' · ' . ($setup['verified'] ? 'key verified' : 'key not tested') ?></div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <div class="kpi" style="--tone:<?= $overBudget ? '#BA1A1A' : '#B80049' ?>">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('wallet') ?></div></div>
      <div class="kpi__value"><?= $this->e($cost($monthSpend)) ?></div>
      <div class="kpi__label">Spent this month</div>
      <div class="kpi__foot">
        <?= $budget > 0
              ? $usedPct . '% of a ' . $this->e($this->money($budget, 'USD')) . ' budget'
              : 'No budget set' ?>
      </div>
    </div>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#632CE5" href="/admin/ai/logs">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('chat') ?></div></div>
      <div class="kpi__value"><?= $this->number($calls) ?></div>
      <div class="kpi__label">Calls in <?= (int) $windowDays ?> days</div>
      <div class="kpi__foot"><?= $calls === 0 ? 'Nothing logged yet' : $rate . '% succeeded' ?></div>
    </a>
  </div>

  <div class="wgrid__item" style="--span:3">
    <a class="kpi" style="--tone:#2563EB" href="/admin/ai-prompts">
      <div class="kpi__head"><div class="kpi__icon"><?= $this->icon('file') ?></div></div>
      <div class="kpi__value"><?= $this->number($promptCount) ?></div>
      <div class="kpi__label">Prompts</div>
      <div class="kpi__foot"><?= $this->number($activePrompts) ?> active</div>
    </a>
  </div>
</div>

<div class="grid grid--sidebar">

  <div class="grid">

    <!-- Prompt library -->
    <div class="card">
      <div class="card__head">
        <div>
          <h2 class="h3">Prompt library</h2>
          <p class="muted small mt-1">Usage over the last <?= (int) $windowDays ?> days. Editing lives in the prompt CRUD.</p>
        </div>
        <a href="/admin/ai-prompts" class="btn btn--outline btn--sm">
          Manage prompts <?= $this->icon('chevron-right', 'ic ic--sm') ?>
        </a>
      </div>

      <?php if ($prompts === []): ?>
        <div class="empty">
          <div class="empty__art"><?= $this->icon('file', 'ic ic--lg') ?></div>
          <p class="empty__title">No prompts</p>
          <p class="empty__text">Create one in the prompt library before any AI feature can run.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Prompt</th><th>Model</th><th class="text-right">Calls</th>
                <th class="text-right">Spend</th><th>Last used</th><th>State</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($prompts as $prompt): ?>
                <tr>
                  <td>
                    <a href="/admin/ai-prompts/<?= (int) $prompt['id'] ?>/edit" class="cell-user__name">
                      <?= $this->e($prompt['name']) ?>
                    </a>
                    <div class="tiny muted">
                      <code class="mono"><?= $this->e($prompt['code']) ?></code>
                      · v<?= (int) $prompt['version'] ?>
                      · <?= $this->e(str_replace('_', ' ', (string) $prompt['purpose'])) ?>
                    </div>
                  </td>

                  <td class="small muted">
                    <?= $this->e($prompt['model'] ?? '') !== ''
                          ? $this->e($prompt['model'])
                          : '<span class="faint">default</span>' ?>
                    <div class="tiny faint">
                      temp <?= $this->e((string) $prompt['temperature']) ?>
                      · max <?= $this->number((int) $prompt['max_tokens']) ?>
                    </div>
                  </td>

                  <td class="text-right mono small">
                    <?= $this->number((int) $prompt['calls']) ?>
                    <?php if ((int) $prompt['failures'] > 0): ?>
                      <div class="tiny text-error"><?= (int) $prompt['failures'] ?> failed</div>
                    <?php endif; ?>
                  </td>

                  <td class="text-right mono small"><?= $this->e($cost($prompt['spend'])) ?></td>

                  <td class="small muted" style="white-space:nowrap">
                    <?= $prompt['last_used_at'] === null ? 'Never' : $this->timeAgo($prompt['last_used_at']) ?>
                  </td>

                  <td>
                    <span class="chip chip--<?= (int) $prompt['is_active'] === 1 ? 'success' : 'neutral' ?>">
                      <span class="chip__dot"></span>
                      <?= (int) $prompt['is_active'] === 1 ? 'Active' : 'Off' ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Recent failures -->
    <div class="card">
      <div class="card__head">
        <h2 class="h3">Recent failures</h2>
        <a href="/admin/ai/logs?status=failed" class="btn btn--ghost btn--sm">
          All logs <?= $this->icon('chevron-right', 'ic ic--sm') ?>
        </a>
      </div>

      <?php if ($recentFailures === []): ?>
        <div class="empty">
          <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
          <p class="empty__title">No failed calls</p>
          <p class="empty__text">Errors, rate limits and filtered responses would appear here.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr><th>When</th><th>Prompt</th><th>Status</th><th>Error</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentFailures as $failure): ?>
                <tr>
                  <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($failure['created_at']) ?></td>
                  <td class="small mono"><?= $this->e($failure['prompt_code'] ?? '—') ?></td>
                  <td>
                    <span class="chip chip--<?= (string) $failure['status'] === 'failed' ? 'error' : 'warning' ?>">
                      <?= $this->e(ucfirst(str_replace('_', ' ', (string) $failure['status']))) ?>
                    </span>
                  </td>
                  <td class="tiny muted truncate" style="max-width:320px"
                      title="<?= $this->e($failure['error_message'] ?? '') ?>">
                    <?= $this->e($failure['error_message'] ?? '—') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Setup: read-only here, edited in Settings -> AI -->
  <div class="card">
    <div class="card__head">
      <div>
        <h2 class="h3">Setup</h2>
        <p class="muted small mt-1">Provider, key, model and the app&rsquo;s AI switches live in Settings.</p>
      </div>
    </div>

    <div class="card__body col gap-3">
      <div>
        <div class="label mb-1">Provider</div>
        <div class="small">
          <?php if ($setup['provider'] === null): ?>
            <span class="chip chip--warning"><span class="chip__dot"></span>None set up</span>
          <?php else: ?>
            <?= $this->e((string) $setup['provider']) ?>
            <?php if ($setup['model'] !== ''): ?>· <code class="mono"><?= $this->e($setup['model']) ?></code><?php endif; ?>
            <span class="chip chip--<?= $setup['verified'] ? 'success' : 'neutral' ?>" style="margin-left:4px">
              <?= $setup['verified'] ? 'Key verified' : 'Key not tested' ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <div class="label mb-1">Live in the app</div>
        <div class="chip-row">
          <?php foreach (['bio_writer' => 'Bio writer', 'campaign_brief' => 'Brief writer', 'creator_match' => 'Creator matching'] as $key => $label): ?>
            <span class="chip chip--<?= !empty($setup['features'][$key]) ? 'success' : 'neutral' ?>"><?= $this->e($label) ?></span>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <div class="label mb-1">Allowance</div>
        <div class="small"><?= (int) $setup['limit'] ?> requests per account per day</div>
      </div>

      <?php if ($canManage): ?>
        <a href="/admin/settings?group=ai" class="btn btn--primary btn--block mt-2">
          <?= $this->icon('settings', 'ic ic--sm') ?> Open Settings → AI
        </a>
      <?php endif; ?>
    </div>

    <div class="card__body" style="border-top:1px solid var(--outline-variant)">
      <p class="small muted mb-2" style="font-weight:600">Elsewhere</p>
      <div class="grid gap-2">
        <a href="/admin/ai-prompts" class="btn btn--ghost btn--sm" style="justify-content:flex-start">
          <?= $this->icon('file', 'ic ic--sm') ?> Prompt library
        </a>
        <a href="/admin/ai/logs" class="btn btn--ghost btn--sm" style="justify-content:flex-start">
          <?= $this->icon('chart', 'ic ic--sm') ?> Call log and spend
        </a>
      </div>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<link rel="stylesheet" href="/admin/assets/css/widgets.css?v=<?= $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')) ?>">
<?php $this->stop(); ?>

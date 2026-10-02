<?php

declare(strict_types=1);

/**
 * Profile completion — the list of creators by score, and one creator's
 * breakdown.
 *
 * ONE FILE, TWO SCREENS. Both render the same section bars, in the same
 * taxonomy order, with the same colours off the same term metadata. Split
 * across two templates that shared nothing but a convention, the two would
 * drift the first time a section was re-coloured. `$mode` decides which half
 * runs; everything above the split is shared by both.
 *
 * @var \Zuste\Core\View $this
 * @var string $mode                                   'list' or 'detail'
 * @var array<string,array<string,mixed>> $sectionList  list mode: the catalogue
 * @var list<array<string,mixed>> $creators             list mode
 * @var array<int,array<string,array<string,mixed>>> $breakdowns  list mode
 * @var array<string,mixed> $summary                    list mode
 * @var array<string,mixed> $pagination                 list mode
 * @var array<string,mixed> $filters                    list mode
 * @var array<string,mixed> $creator                    detail mode
 * @var array<string,mixed> $live                       detail mode
 * @var array<string,mixed> $stored                     detail mode
 * @var list<array<string,mixed>> $gates                detail mode
 * @var list<array<string,mixed>> $overrides            detail mode
 * @var array<string,mixed> $handle                     detail mode
 * @var list<array<string,mixed>> $handleLog            detail mode
 * @var bool $canEdit
 * @var bool $canFeature
 */

$this->extend('admin.layouts.app');
$this->start('content');

/**
 * The completion_section taxonomy names three icons View::icon() does not hold
 * — badge, tag and folder. An unknown name renders a meaningless plain circle
 * rather than failing, so the three are aliased onto icons that do exist and
 * carry the same meaning. The real fix is three paths in View.php, which this
 * module does not own; they are listed in the module's handover notes.
 */
$iconAliases = ['badge' => 'award', 'tag' => 'card', 'folder' => 'inbox'];
$sectionIcon = static fn (string $name): string => $iconAliases[$name] ?? $name;

/** Green once finished, amber while it is being worked on, red when untouched. */
$tone = static fn (int $percent): string => match (true) {
    $percent >= 100 => 'success',
    $percent >= 50  => 'warning',
    $percent > 0    => 'info',
    default         => 'error',
};

$statusTone = static fn (string $status): string => match ($status) {
    'approved'       => 'success',
    'pending_review' => 'warning',
    'draft'          => 'neutral',
    default          => 'error',
};

?>

<?php if ($mode === 'list'): ?>

  <?php // ============================================================ ?>
  <?php // The list                                                     ?>
  <?php // ============================================================ ?>

  <section class="grid grid--4 mb-5">
    <?php foreach ([
        ['Average completion', $summary['average'] . '%', 'chart', 'var(--primary)'],
        ['At ' . (int) $summary['required'] . '% or above', $this->number($summary['at_required']) . ' of ' . $this->number($summary['total']), 'check-circle', 'var(--tertiary)'],
        ['Live but under ' . (int) $summary['required'] . '%', $this->number($summary['approved_short']), 'alert-triangle', 'var(--error)'],
        ['Missing something mandatory', $this->number($summary['mandatory']), 'flag', 'var(--secondary)'],
    ] as [$label, $value, $icon, $toneVar]): ?>
      <div class="stat" style="--tone:<?= $toneVar ?>">
        <div class="stat__icon"><?= $this->icon($icon) ?></div>
        <div class="stat__value"><?= $this->e($value) ?></div>
        <div class="stat__label"><?= $this->e($label) ?></div>
      </div>
    <?php endforeach; ?>
  </section>

  <?php if ((int) $summary['never_scored'] > 0): ?>
    <div class="card mb-4">
      <div class="card__body row gap-3 row--wrap">
        <span class="text-secondary"><?= $this->icon('info') ?></span>
        <div class="flex-1">
          <div style="font-weight:600"><?= $this->number($summary['never_scored']) ?> profile(s) have never been scored</div>
          <p class="small muted">
            The nightly <code>creators:completion</code> job fills these in at 00:55. Run it now if you do not want to wait.
          </p>
        </div>
        <?php if ($canEdit): ?>
          <form method="post" action="/admin/creators/completion/recompute">
            <?= $this->csrf() ?>
            <button type="submit" class="btn btn--primary btn--sm">
              <?= $this->icon('refresh', 'ic ic--sm') ?> Rescore everyone
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="chip-row mb-5">
    <?php
    // A quick filter is lit only when the parameters it sets are EXACTLY the
    // ones currently in play. Comparing the whole set rather than each key is
    // what stops "All profiles" glowing alongside whichever narrower chip is
    // actually on.
    $filterKeys = ['status', 'below', 'section', 'attention'];
    $active     = [];

    foreach ($filterKeys as $key) {
        if (($filters[$key] ?? '') !== '') {
            $active[$key] = (string) $filters[$key];
        }
    }
    ?>

    <?php foreach ([
        [[], 'All profiles'],
        [['below' => (string) (int) $summary['required']], 'Below ' . (int) $summary['required'] . '%'],
        [['status' => 'approved', 'below' => (string) (int) $summary['required']], 'Live but short'],
        [['attention' => 'mandatory'], 'Mandatory field missing'],
        [['attention' => 'never_scored'], 'Never scored'],
    ] as [$params, $label]): ?>
      <?php
        // Blank every filter key first, then lay this chip's parameters over
        // the top. queryUrl() drops empty values, so a chip always REPLACES the
        // current filter set instead of stacking on it — clicking "Never
        // scored" while "Below 60%" is on must not silently AND the two.
        $isOn = $active == $params;
        $url  = array_merge(array_fill_keys($filterKeys, ''), $params, ['page' => 1]);
      ?>
      <a href="<?= $this->queryUrl($url) ?>"
         class="chip-filter<?= $isOn ? ' chip-filter--active' : '' ?>"><?= $this->e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="card">

    <?php
    $sectionOptions = ['' => 'Any section'];
    foreach ($sectionList as $key => $definition) {
        $sectionOptions[(string) $key] = $definition['label'] . ' — not finished';
    }
    ?>

    <?php $this->partial('admin.partials.toolbar', [
        'filters'           => $filters,
        'searchPlaceholder' => 'Search by name, handle or email…',
        'selects'           => [
            ['name' => 'status', 'label' => 'Status', 'options' => [
                ''               => 'Any status',
                'approved'       => 'Live',
                'pending_review' => 'Awaiting review',
                'draft'          => 'Draft',
                'rejected'       => 'Rejected',
                'suspended'      => 'Suspended',
            ]],
            ['name' => 'section', 'label' => 'Section', 'options' => $sectionOptions],
            ['name' => 'below', 'label' => 'Below', 'options' => [
                ''    => 'Any score',
                '25'  => 'Under 25%',
                '50'  => 'Under 50%',
                '60'  => 'Under 60%',
                '80'  => 'Under 80%',
                '100' => 'Under 100%',
            ]],
            // The two chips below the stats set this same parameter. It is a
            // SELECT rather than a hidden input because the toolbar owns the
            // GET form and re-emits only the fields it renders — as a hidden
            // filter it was dropped the moment anyone typed in the search box,
            // which lit a chip that was no longer doing anything.
            ['name' => 'attention', 'label' => 'Needs attention', 'options' => [
                ''             => 'Any profile',
                'mandatory'    => 'Mandatory field missing',
                'never_scored' => 'Never scored',
            ]],
        ],
    ]); ?>

    <?php if ($creators === []): ?>
      <div class="empty">
        <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
        <p class="empty__title">Nothing matches</p>
        <p class="empty__text">No creator fits these filters. Try widening the score or clearing the section.</p>
        <a href="/admin/creators/completion" class="btn btn--outline">
          <?= $this->icon('refresh', 'ic ic--sm') ?> Reset filters
        </a>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Creator</th>
              <th>Status</th>
              <th style="min-width:150px">Overall</th>
              <th style="min-width:220px">Sections</th>
              <th class="text-right">Mandatory</th>
              <th>Scored</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($creators as $row): ?>
              <?php
                $id        = (int) $row['id'];
                $score     = (int) $row['profile_score'];
                $breakdown = $breakdowns[$id] ?? [];
                $mandatory = 0;
                $scoredAt  = null;

                foreach ($breakdown as $stored) {
                    $mandatory += (int) $stored['mandatory_missing'];

                    if ($scoredAt === null || (string) $stored['computed_at'] < $scoredAt) {
                        $scoredAt = (string) $stored['computed_at'];
                    }
                }
              ?>
              <tr>
                <td>
                  <div class="cell-user">
                    <?php if (!empty($row['avatar_url'])): ?>
                      <img class="avatar" src="<?= $this->e($row['avatar_url']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                      <div class="avatar avatar--fallback"><?= $this->e($this->initials((string) $row['display_name'])) ?></div>
                    <?php endif; ?>
                    <div class="flex-1 truncate">
                      <div class="cell-user__name truncate">
                        <a href="/admin/creators/<?= $id ?>/completion"><?= $this->e($row['display_name']) ?></a>
                      </div>
                      <div class="cell-user__meta truncate">@<?= $this->e($row['handle']) ?></div>
                    </div>
                  </div>
                </td>

                <td>
                  <span class="chip chip--<?= $statusTone((string) $row['status']) ?> chip--sm">
                    <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $row['status']))) ?>
                  </span>
                </td>

                <td>
                  <div class="progress" title="<?= $score ?>% complete">
                    <div class="progress__bar" style="width:<?= max(2, $score) ?>%"></div>
                  </div>
                  <div class="tiny faint mt-1"><?= $score ?>%</div>
                </td>

                <td>
                  <?php if ($breakdown === []): ?>
                    <span class="tiny faint">Not scored yet</span>
                  <?php else: ?>
                    <div class="row gap-1 row--wrap">
                      <?php foreach ($sectionList as $key => $definition): ?>
                        <?php $percent = isset($breakdown[$key]) ? (int) $breakdown[$key]['percent'] : 0; ?>
                        <span class="chip chip--sm chip--<?= $tone($percent) ?>"
                              title="<?= $this->e($definition['label']) ?>: <?= $percent ?>%">
                          <?= $this->icon($sectionIcon((string) $definition['icon']), 'ic ic--sm') ?><?= $percent ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td class="text-right">
                  <?php if ($mandatory > 0): ?>
                    <span class="chip chip--error chip--sm"><?= $mandatory ?> missing</span>
                  <?php else: ?>
                    <span class="faint tiny">—</span>
                  <?php endif; ?>
                </td>

                <td class="small muted"><?= $scoredAt === null ? '<span class="faint">Never</span>' : $this->e($this->timeAgo($scoredAt)) ?></td>

                <td class="actions">
                  <a href="/admin/creators/<?= $id ?>/completion" class="btn btn--outline btn--sm">
                    <?= $this->icon('eye', 'ic ic--sm') ?> Breakdown
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
    <?php endif; ?>
  </div>

<?php else: ?>

  <?php // ============================================================ ?>
  <?php // One creator's breakdown                                      ?>
  <?php // ============================================================ ?>

  <?php
    $creatorId = (int) $creator['id'];
    $overall   = (int) $live['overall'];
    // The stored figure the rest of the panel reads. When it disagrees with the
    // live evaluation the cache is stale, and saying so is the whole reason
    // `computed_at` is on the row.
    $isStale   = $overall !== (int) $stored['overall'];
  ?>

  <a href="/admin/creators/completion" class="btn btn--ghost btn--sm mb-4">
    <?= $this->icon('chevron-left', 'ic ic--sm') ?> Back to completion
  </a>

  <div class="grid grid--sidebar">

    <div class="col gap-4">

      <!-- Headline -->
      <div class="card">
        <div class="card__body">
          <div class="row gap-4 row--wrap">
            <?php if (!empty($creator['avatar_url'])): ?>
              <img class="avatar avatar--lg" src="<?= $this->e($creator['avatar_url']) ?>" alt="">
            <?php else: ?>
              <div class="avatar avatar--lg avatar--fallback" style="font-size:18px">
                <?= $this->e($this->initials((string) $creator['display_name'])) ?>
              </div>
            <?php endif; ?>

            <div class="flex-1">
              <div class="row gap-2 row--wrap mb-1">
                <h2 class="h1"><?= $this->e($creator['display_name']) ?></h2>
                <span class="chip chip--<?= $statusTone((string) $creator['status']) ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $creator['status']))) ?>
                </span>
                <?php if ($live['ready']): ?>
                  <span class="chip chip--success"><?= $this->icon('check-circle', 'ic ic--sm') ?> Ready</span>
                <?php elseif ((int) $live['mandatory_missing'] > 0): ?>
                  <span class="chip chip--error">
                    <?= $this->icon('alert-triangle', 'ic ic--sm') ?>
                    <?= (int) $live['mandatory_missing'] ?> mandatory outstanding
                  </span>
                <?php endif; ?>
              </div>
              <p class="muted small">
                @<?= $this->e($creator['handle']) ?> ·
                <a href="/admin/creators/<?= $creatorId ?>" class="text-secondary">Full profile</a>
              </p>

              <div class="progress mt-3" title="<?= $overall ?>% complete">
                <div class="progress__bar" style="width:<?= max(2, $overall) ?>%"></div>
              </div>
              <p class="tiny faint mt-1">
                <?= $overall ?>% complete · <?= (int) $live['required_percent'] ?>% required
                <?php if ($stored['computed_at'] !== null): ?>
                  · stored figure computed <?= $this->e($this->timeAgo((string) $stored['computed_at'])) ?>
                <?php endif; ?>
              </p>
            </div>

            <?php if ($canEdit): ?>
              <form method="post" action="/admin/creators/<?= $creatorId ?>/completion/recompute">
                <?= $this->csrf() ?>
                <button type="submit" class="btn btn--<?= $isStale ? 'primary' : 'outline' ?> btn--sm">
                  <?= $this->icon('refresh', 'ic ic--sm') ?> Recompute
                </button>
              </form>
            <?php endif; ?>
          </div>

          <?php if ($isStale): ?>
            <hr class="divider">
            <p class="small text-error">
              <?= $this->icon('alert-triangle', 'ic ic--sm') ?>
              The stored breakdown says <?= (int) $stored['overall'] ?>% but the profile evaluates to <?= $overall ?>% right now.
              Everything else on the panel reads the stored figure until this is recomputed.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Sections -->
      <?php foreach ($live['sections'] as $key => $section): ?>
        <div class="card">
          <div class="card__head">
            <div class="row gap-2">
              <span style="color:<?= $this->e($section['color']) ?>">
                <?= $this->icon($sectionIcon((string) $section['icon'])) ?>
              </span>
              <div>
                <div class="card__title"><?= $this->e($section['label']) ?></div>
                <div class="tiny faint">
                  Worth <?= (int) $section['weight'] ?> of 100 ·
                  <?= (int) $section['rules_met'] ?> of <?= (int) $section['rules_total'] ?> checks met
                </div>
              </div>
            </div>
            <div class="card__actions">
              <span class="chip chip--<?= $tone((int) $section['percent']) ?>"><?= (int) $section['percent'] ?>%</span>
            </div>
          </div>

          <div class="card__body">
            <?php if ($section['rules'] === []): ?>
              <p class="small muted">No active checks. This section is excluded from the overall score.</p>
            <?php else: ?>
              <div class="progress mb-3">
                <div class="progress__bar" style="width:<?= max(2, (int) $section['percent']) ?>%"></div>
              </div>

              <div class="table-wrap">
                <table class="table table--compact">
                  <tbody>
                    <?php foreach ($section['rules'] as $rule): ?>
                      <tr>
                        <td style="width:32px">
                          <span class="<?= $rule['met'] ? 'text-success' : 'text-error' ?>">
                            <?= $this->icon($rule['met'] ? 'check-circle' : 'x', 'ic ic--sm') ?>
                          </span>
                        </td>
                        <td>
                          <div<?= $rule['met'] ? '' : ' style="font-weight:600"' ?>><?= $this->e($rule['label']) ?></div>
                          <?php if ($rule['description'] !== null): ?>
                            <div class="tiny faint"><?= $this->e($rule['description']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="tiny faint" style="white-space:nowrap">
                          <?= $this->e($rule['check_type']) ?>
                          <?php if ((int) $rule['threshold'] > 1): ?>
                            ≥ <?= (int) $rule['threshold'] ?>
                          <?php endif; ?>
                        </td>
                        <td class="text-right" style="white-space:nowrap">
                          <?php if ($rule['is_mandatory']): ?>
                            <span class="chip chip--<?= $rule['met'] ? 'neutral' : 'error' ?> chip--sm">Mandatory</span>
                          <?php else: ?>
                            <span class="tiny faint">weight <?= (int) $rule['weight'] ?></span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Sidebar -->
    <div class="col gap-4">

      <!-- Feature gates -->
      <div class="card">
        <div class="card__head">
          <div class="card__title">Feature gates</div>
        </div>
        <div class="card__body col gap-3">
          <?php if ($gates === []): ?>
            <p class="small muted">No gates are configured, so nothing is withheld.</p>
          <?php endif; ?>

          <?php foreach ($gates as $gate): ?>
            <div>
              <div class="row gap-2 row--wrap">
                <span class="chip chip--<?= $gate['allowed'] ? 'success' : 'error' ?> chip--sm">
                  <?= $this->icon($gate['allowed'] ? 'unlock' : 'lock', 'ic ic--sm') ?>
                  <?= $gate['allowed'] ? 'Open' : 'Locked' ?>
                </span>
                <div class="flex-1" style="font-weight:600"><?= $this->e($gate['name']) ?></div>
                <span class="tiny faint"><?= (int) $gate['required'] ?>%</span>
              </div>

              <?php if ($gate['override'] !== null): ?>
                <?php // Why this gate is open or shut. Removing it lives in the ?>
                <?php // overrides card below, which lists lapsed rows too.      ?>
                <div class="tiny mt-1 <?= (string) $gate['override']['mode'] === 'grant' ? 'text-success' : 'text-error' ?>">
                  Administrator override: <?= $this->e($gate['override']['mode']) ?>
                  <?php if (!empty($gate['override']['reason'])): ?>
                    — <?= $this->e($gate['override']['reason']) ?>
                  <?php endif; ?>
                  <?php if (!empty($gate['override']['expires_at'])): ?>
                    (until <?= $this->date((string) $gate['override']['expires_at']) ?>)
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <?php if ($gate['reasons'] !== []): ?>
                <ul class="tiny faint mt-1" style="padding-left:18px">
                  <?php foreach ($gate['reasons'] as $reason): ?>
                    <li><?= $this->e($reason) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if ($gate['enforcement'] === 'warn' && !$gate['meets']): ?>
                <div class="tiny faint mt-1">Enforcement is “warn”, so this stays usable while incomplete.</div>
              <?php endif; ?>
            </div>
            <hr class="divider">
          <?php endforeach; ?>

          <?php if ($canFeature && $gates !== []): ?>
            <form method="post" action="/admin/creators/<?= $creatorId ?>/features" class="col gap-2">
              <?= $this->csrf() ?>
              <div class="field">
                <label class="field__label" for="feature_key">Override a gate</label>
                <select class="input input--sm" name="feature_key" id="feature_key" required>
                  <?php foreach ($gates as $gate): ?>
                    <option value="<?= $this->e($gate['feature_key']) ?>"><?= $this->e($gate['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label class="field__label" for="mode">Decision</label>
                <select class="input input--sm" name="mode" id="mode">
                  <option value="grant">Grant regardless of score</option>
                  <option value="deny">Withhold regardless of score</option>
                </select>
              </div>
              <div class="field">
                <label class="field__label" for="reason">Reason</label>
                <input class="input input--sm" type="text" name="reason" id="reason"
                       maxlength="500" required placeholder="Why this creator, and on whose say-so">
              </div>
              <div class="field">
                <label class="field__label" for="expires_at">Expires (optional)</label>
<?php // Today is a legitimate choice: it now means "until tonight", not "already over". ?>
                <input class="input input--sm" type="date" name="expires_at" id="expires_at"
                       min="<?= date('Y-m-d') ?>">
                <p class="field__hint">
                  Runs to the END of the day chosen. Leave empty for an override that never lapses.
                </p>
              </div>
              <button type="submit" class="btn btn--primary btn--sm btn--block">Save override</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- Overrides, including the ones that have lapsed -->
      <?php
        // The gate list above can only show LIVE overrides, because a lapsed
        // one stops being an override at all — and yet its row is still on the
        // table, still on the unique key, and still the thing an operator has
        // to remove before they can grant the feature again on different terms.
        // Left unrendered it was invisible AND unremovable from this screen.
        $gateNames = [];
        foreach ($gates as $gateForName) {
            $gateNames[(string) $gateForName['feature_key']] = (string) $gateForName['name'];
        }
      ?>
      <?php if ($overrides !== []): ?>
        <div class="card">
          <div class="card__head">
            <div class="card__title">Overrides on record</div>
            <div class="card__actions">
              <span class="chip chip--sm chip--neutral"><?= count($overrides) ?></span>
            </div>
          </div>
          <div class="card__body col gap-3">
            <?php foreach ($overrides as $override): ?>
              <?php
                $expiresAt = $override['expires_at'] !== null ? (string) $override['expires_at'] : null;
                $lapsed    = $expiresAt !== null && strtotime($expiresAt) <= time();
                $isGrant   = (string) $override['mode'] === 'grant';
              ?>
              <div>
                <div class="row gap-2 row--wrap">
                  <span class="chip chip--sm chip--<?= $lapsed ? 'neutral' : ($isGrant ? 'success' : 'error') ?>">
                    <?= $this->icon($isGrant ? 'unlock' : 'lock', 'ic ic--sm') ?>
                    <?= $isGrant ? 'Grant' : 'Deny' ?>
                  </span>
                  <div class="flex-1 truncate" style="font-weight:600">
                    <?= $this->e($gateNames[(string) $override['feature_key']] ?? $override['feature_key']) ?>
                  </div>
                  <?php if ($lapsed): ?>
                    <span class="chip chip--warning chip--sm">Lapsed</span>
                  <?php endif; ?>
                </div>

                <div class="tiny faint mt-1">
                  <?php if ($expiresAt === null): ?>
                    Never lapses
                  <?php elseif ($lapsed): ?>
                    Lapsed <?= $this->e($this->timeAgo($expiresAt)) ?> — no longer applies, but the row remains
                  <?php else: ?>
                    Applies until <?= $this->e($this->dateTime($expiresAt)) ?>
                  <?php endif; ?>
                  · set <?= $this->e($this->timeAgo((string) $override['created_at'])) ?>
                </div>

                <?php if (!empty($override['reason'])): ?>
                  <div class="tiny mt-1"><?= $this->e($override['reason']) ?></div>
                <?php endif; ?>

                <?php if ($canFeature): ?>
                  <form method="post" action="/admin/creators/<?= $creatorId ?>/features" class="mt-1">
                    <?= $this->csrf() ?>
                    <input type="hidden" name="_method" value="DELETE">
                    <input type="hidden" name="feature_key" value="<?= $this->e($override['feature_key']) ?>">
                    <button type="submit" class="btn btn--ghost btn--sm">
                      <?= $this->icon('trash', 'ic ic--sm') ?>
                      <?= $lapsed ? 'Delete lapsed row' : 'Remove override' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
              <hr class="divider">
            <?php endforeach; ?>

            <p class="tiny faint">
              A lapsed row still holds its feature key on this creator. Delete it before granting the same
              feature again on different terms.
            </p>
          </div>
        </div>
      <?php endif; ?>

      <!-- Handle changes -->
      <div class="card">
        <div class="card__head">
          <div class="card__title">Handle changes</div>
          <div class="card__actions">
            <span class="chip chip--<?= $handle['allowed'] ? 'neutral' : 'warning' ?> chip--sm">
              <?= (int) $handle['used'] ?><?= $handle['limit'] > 0 ? ' of ' . (int) $handle['limit'] : '' ?> used
            </span>
          </div>
        </div>
        <div class="card__body">
          <p class="small muted">
            <?php if ($handle['limit'] > 0): ?>
              A creator may change their @handle <?= (int) $handle['limit'] ?> time(s),
            <?php else: ?>
              Handle changes are unlimited,
            <?php endif; ?>
            <?php if ((int) $handle['cooldown_days'] > 0): ?>
              once every <?= (int) $handle['cooldown_days'] ?> days.
            <?php else: ?>
              with no cooldown.
            <?php endif; ?>
          </p>

          <?php if ($handle['available_at'] !== null): ?>
            <p class="small text-error mt-2">
              In cooldown until <?= $this->dateTime((string) $handle['available_at']) ?>.
            </p>
          <?php endif; ?>

          <?php if ($handleLog === []): ?>
            <p class="tiny faint mt-2">This creator has never changed their handle.</p>
          <?php else: ?>
            <hr class="divider">
            <div class="col gap-2">
              <?php foreach ($handleLog as $entry): ?>
                <div class="small">
                  <span class="mono">@<?= $this->e($entry['old_handle']) ?></span>
                  <?= $this->icon('arrow-right', 'ic ic--sm') ?>
                  <span class="mono">@<?= $this->e($entry['new_handle']) ?></span>
                  <div class="tiny faint">
                    <?= $this->e($entry['actor_type']) ?>
                    <?= !empty($entry['actor_name']) ? '· ' . $this->e($entry['actor_name']) : '' ?>
                    · <?= $this->e($this->dateTime((string) $entry['created_at'])) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- What is outstanding -->
      <div class="card">
        <div class="card__head">
          <div class="card__title">Outstanding checks</div>
          <div class="card__actions">
            <span class="chip chip--sm chip--neutral"><?= count($live['missing']) ?></span>
          </div>
        </div>
        <div class="card__body">
          <?php if ($live['missing'] === []): ?>
            <p class="small text-success"><?= $this->icon('check-circle', 'ic ic--sm') ?> Every check passes.</p>
          <?php else: ?>
            <div class="col gap-2">
              <?php foreach ($live['missing'] as $rule): ?>
                <div class="row gap-2">
                  <span class="<?= $rule['is_mandatory'] ? 'text-error' : 'faint' ?>">
                    <?= $this->icon($rule['is_mandatory'] ? 'alert-triangle' : 'circle', 'ic ic--sm') ?>
                  </span>
                  <div class="flex-1">
                    <div class="small"><?= $this->e($rule['label']) ?></div>
                    <div class="tiny faint"><?= $this->e($live['sections'][$rule['section']]['label'] ?? $rule['section']) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php $this->stop(); ?>

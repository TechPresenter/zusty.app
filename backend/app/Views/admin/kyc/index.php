<?php

declare(strict_types=1);

/**
 * KYC verification queues.
 *
 * One screen serves brands, creators, and the combined view; `kind` narrows it
 * and `queue` picks the status bucket.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $profiles
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 * @var string|null $kind
 * @var string $queue
 * @var array<string,int> $counts
 */

$this->extend('admin.layouts.app');
$this->start('content');

$basePath = $kind === null ? '/admin/kyc' : '/admin/kyc/' . $kind . 's';

$queues = [
    'pending'  => 'Pending approval',
    'info'     => 'Action needed',
    'approved' => 'Approved',
    // The blue tick is a separate manual decision after KYC approval.
    'awaiting_tick' => 'Approved — awaiting blue tick',
    'rejected' => 'Rejected',
    'expired'  => 'Expired',
    'draft'    => 'Drafts (not submitted)',
    'all'      => 'All',
];

// Bulk decisions only ever act on submissions waiting for one (submitted or
// under review — the controller applies the same rule to whatever is posted).
// So the selection column exists only on the queues that can hold such rows:
// Pending, and All. Action needed (waiting on the applicant), Approved,
// Rejected, Expired and Drafts can never be approved or rejected in bulk, so
// they get no checkboxes at all; on All, only the waiting rows get one.
$bulkStatuses = ['submitted', 'under_review'];
$bulkQueues   = ['pending', 'all'];
$bulkEnabled  = $this->can('kyc.review') && in_array($queue, $bulkQueues, true);
$selectable   = $bulkEnabled ? count(array_filter(
    $profiles,
    static fn (array $p): bool => in_array((string) $p['status'], $bulkStatuses, true)
)) : 0;

// The search box and the advanced filters are separate forms; each carries
// the other's state, so searching never drops the tab or the filters.
$carried = [];
foreach (['from', 'to', 'tick', 'expiring', 'reviewer', 'sort_by', 'sort_dir', 'per_page'] as $key) {
    if (isset($filters[$key]) && is_scalar($filters[$key]) && (string) $filters[$key] !== '') {
        $carried[$key] = (string) $filters[$key];
    }
}

$searchTerm = (string) (is_scalar($filters['q'] ?? null) ? $filters['q'] : '');

?>

<!-- Which side of the marketplace -->
<div class="chip-row mb-4">
  <?php foreach ([
      '/admin/kyc'          => 'Everyone',
      '/admin/kyc/brands'   => 'Brands',
      '/admin/kyc/creators' => 'Creators',
  ] as $href => $label): ?>
    <a href="<?= $this->e($href) ?>"
       class="chip-filter<?= $basePath === $href ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<!-- Status buckets, with live counts -->
<div class="chip-row mb-5">
  <?php foreach ($queues as $value => $label): ?>
    <a href="<?= $this->queryUrl(['queue' => $value, 'page' => 1], $basePath) ?>"
       class="chip-filter<?= $queue === $value ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?>
      <?php if (!empty($counts[$value])): ?>
        <span class="badge badge--sm"><?= $this->number($counts[$value]) ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card mb-5" style="border-color:var(--secondary);background:color-mix(in srgb, var(--secondary) 6%, transparent)">
  <div class="card__body row gap-3" style="padding:14px 18px">
    <span class="text-secondary"><?= $this->icon('shield') ?></span>
    <p class="small">
      Identity numbers are encrypted at rest (an Aadhaar number is never stored beyond its last four
      digits, and only a masked Aadhaar copy may be approved) and documents are stored encrypted outside
      the web root. Opening a submission claims it for review, and every decision, note, document view
      and download is recorded against your account. Drafts have not been submitted, so only their
      status is shown. Approving KYC does not grant the blue tick — that is decided separately on each
      profile.
    </p>
  </div>
</div>

<div class="card">

  <?php // The shared list toolbar submits only `q`, which used to drop the tab
        // (searching from Approved searched Pending). This one carries the
        // queue and every advanced filter with it. ?>
  <form class="toolbar" method="get" action="<?= $this->e($basePath) ?>" id="kyc-search-form">
    <div class="toolbar__search">
      <div class="input-group">
        <span class="input-group__icon"><?= $this->icon('search', 'ic ic--sm') ?></span>
        <input class="input" type="search" name="q" value="<?= $this->e($searchTerm) ?>"
               placeholder="Search by name, email or reference…" data-auto-submit="500" aria-label="Search">
      </div>
    </div>

    <div class="toolbar__spacer"></div>

    <?php if ($searchTerm !== '' || !empty($filters['search'])): ?>
      <a href="<?= $this->queryUrl(['q' => null, 'search' => null, 'page' => null], $basePath) ?>" class="btn btn--ghost btn--sm">
        <?= $this->icon('x', 'ic ic--sm') ?> Clear search
      </a>
    <?php endif; ?>

    <input type="hidden" name="queue" value="<?= $this->e($queue) ?>">
    <?php foreach ($carried as $key => $value): ?>
      <input type="hidden" name="<?= $this->e($key) ?>" value="<?= $this->e($value) ?>">
    <?php endforeach; ?>
  </form>

  <!-- Advanced filters -->
  <form method="get" action="<?= $this->e($basePath) ?>"
        class="card__body row gap-3" style="flex-wrap:wrap;border-bottom:1px solid var(--outline-variant);padding:12px 18px">
    <input type="hidden" name="queue" value="<?= $this->e($queue) ?>">
    <?php // The toolbar submits the search term as `q`; carry that same key
          // through, or applying a date/expiry filter silently drops it. ?>
    <?php if (!empty($filters['q'])): ?>
      <input type="hidden" name="q" value="<?= $this->e($filters['q']) ?>">
    <?php endif; ?>
    <?php if (!empty($filters['search'])): ?>
      <input type="hidden" name="search" value="<?= $this->e($filters['search']) ?>">
    <?php endif; ?>

    <label class="row gap-2">
      <span class="tiny muted">From</span>
      <input type="date" name="from" value="<?= $this->e($filters['from'] ?? '') ?>" class="input input--sm">
    </label>

    <label class="row gap-2">
      <span class="tiny muted">To</span>
      <input type="date" name="to" value="<?= $this->e($filters['to'] ?? '') ?>" class="input input--sm">
    </label>

    <label class="row gap-2">
      <span class="tiny muted">Blue tick</span>
      <select name="tick" class="input input--sm">
        <option value="">Any</option>
        <?php foreach (['granted' => 'Granted', 'none' => 'Not granted', 'revoked' => 'Revoked'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= ($filters['tick'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="row gap-2">
      <span class="tiny muted">Expiry</span>
      <select name="expiring" class="input input--sm">
        <option value="">Any</option>
        <option value="1" <?= ($filters['expiring'] ?? '') === '1' ? 'selected' : '' ?>>Expiring in 30 days</option>
      </select>
    </label>

    <div class="row gap-2" style="margin-left:auto">
      <button type="submit" class="btn btn--sm btn--secondary">Apply</button>
      <a href="<?= $this->e($basePath . '?' . http_build_query(['queue' => $queue])) ?>" class="btn btn--sm btn--ghost">Clear</a>
      <?php if ($this->can('export.data')): ?>
        <?php // The Brands / Creators tabs fix the kind in the path, not the
              // query string, so it is passed explicitly: an export from the
              // Brands tab must not carry every creator's email and phone. ?>
        <a href="<?= $this->queryUrl(['kind' => $kind, 'queue' => $queue, 'page' => null], '/admin/kyc/export') ?>" class="btn btn--sm btn--ghost">
          <?= $this->icon('download', 'ic ic--sm') ?> Export
        </a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($profiles === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('user-check', 'ic ic--lg') ?></div>
      <p class="empty__title">Nothing here</p>
      <p class="empty__text">No verification submissions match this filter.</p>
    </div>
  <?php else: ?>

    <?php if ($bulkEnabled && $selectable > 0): ?>
      <!-- Revealed once rows are selected -->
      <div id="kyc-bulk-bar" class="card__body row gap-3"
           style="display:none;background:color-mix(in srgb, var(--primary) 8%, transparent);padding:12px 18px">
        <strong class="small"><span id="kyc-bulk-count">0</span> selected</strong>
        <span class="tiny muted">Submissions with an Aadhaar copy are approved one by one, after the masking check on their own page.</span>
        <div class="row gap-2" style="margin-left:auto">
          <button type="button" class="btn btn--sm btn--success" onclick="Kyc.bulk('approve')">Approve selected</button>
          <button type="button" class="btn btn--sm btn--danger" onclick="Kyc.bulk('reject')">Reject selected</button>
          <button type="button" class="btn btn--sm btn--ghost" onclick="Kyc.clearSelection()">Clear</button>
        </div>
      </div>
    <?php endif; ?>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <?php if ($bulkEnabled): ?>
              <th style="width:36px">
                <?php if ($selectable > 0): ?>
                  <input type="checkbox" id="kyc-select-all" onclick="Kyc.toggleAll(this)" aria-label="Select all waiting for a decision">
                <?php endif; ?>
              </th>
            <?php endif; ?>
            <th>Applicant</th>
            <th>Reference</th>
            <th>Type</th>
            <th>Docs</th>
            <th>Complete</th>
            <th>Submitted</th>
            <th>Status</th>
            <th>Blue tick</th>
            <th class="text-right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($profiles as $profile): ?>
            <?php
              $expiresSoon = $profile['status'] === 'approved'
                  && !empty($profile['expires_on'])
                  && strtotime((string) $profile['expires_on']) < strtotime('+30 days');
            ?>
            <tr>
              <?php if ($bulkEnabled): ?>
                <td>
                  <?php if (in_array((string) $profile['status'], $bulkStatuses, true)): ?>
                    <input type="checkbox" class="kyc-row" value="<?= (int) $profile['id'] ?>"
                           onclick="Kyc.refresh()" aria-label="Select submission <?= $this->e((string) $profile['reference']) ?>">
                  <?php endif; ?>
                </td>
              <?php endif; ?>

              <td>
                <div class="cell-user">
                  <?php if (!empty($profile['avatar_url'])): ?>
                    <img class="avatar avatar--sm" src="<?= $this->e($profile['avatar_url']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="avatar avatar--sm avatar--fallback"><?= $this->e($this->initials((string) $profile['user_name'])) ?></div>
                  <?php endif; ?>
                  <div class="flex-1 truncate">
                    <div class="cell-user__name truncate">
                      <a href="/admin/kyc/<?= (int) $profile['id'] ?>"><?= $this->e($profile['user_name']) ?></a>
                    </div>
                    <div class="cell-user__meta truncate"><?= $this->e($profile['email']) ?></div>
                  </div>
                </div>
              </td>

              <td class="small mono faint"><?= $this->e($profile['reference']) ?></td>

              <td class="small">
                <span class="chip chip--<?= $profile['kind'] === 'brand' ? 'info' : 'neutral' ?>">
                  <?= $this->e(ucfirst((string) $profile['kind'])) ?>
                </span>
              </td>

              <td class="small"><?= (int) $profile['document_count'] ?></td>

              <td style="min-width:110px">
                <div style="height:6px;border-radius:999px;background:var(--surface-container);overflow:hidden">
                  <div style="height:100%;width:<?= (int) $profile['completeness'] ?>%;background:var(--primary)"></div>
                </div>
                <span class="tiny muted"><?= (int) $profile['completeness'] ?>%</span>
              </td>

              <td class="small muted">
                <?= $profile['submitted_at'] ? $this->dateTime($profile['submitted_at']) : '—' ?>
              </td>

              <td>
                <span class="chip chip--<?= $this->e((string) $profile['status_tone']) ?>">
                  <span class="chip__dot"></span><?= $this->e((string) $profile['status_label']) ?>
                </span>
                <?php if ($expiresSoon): ?>
                  <div class="tiny text-warning mt-1">Expires <?= $this->date($profile['expires_on']) ?></div>
                <?php endif; ?>
                <?php if (!empty($profile['rejection_reason'])): ?>
                  <div class="tiny text-error mt-1 clamp-2" style="max-width:200px"><?= $this->e($profile['rejection_reason']) ?></div>
                <?php endif; ?>
              </td>

              <td class="small">
                <?php $tick = (string) ($profile['badge_status'] ?? 'none'); ?>
                <?php if ($tick === 'granted'): ?>
                  <span class="chip chip--sm chip--success"><?= $this->icon('verified', 'ic ic--sm') ?> Granted</span>
                <?php elseif ($tick === 'revoked'): ?>
                  <span class="chip chip--sm chip--error">Revoked</span>
                <?php elseif ($profile['status'] === 'approved'): ?>
                  <span class="chip chip--sm chip--warning">Awaiting</span>
                <?php elseif ((int) ($profile['has_blue_tick'] ?? 0) === 1): ?>
                  <span class="chip chip--sm chip--warning" title="A tick from before the badge model">Legacy tick</span>
                <?php else: ?>
                  <span class="faint">—</span>
                <?php endif; ?>
              </td>

              <td class="actions">
                <a href="/admin/kyc/<?= (int) $profile['id'] ?>" class="btn btn--sm btn--secondary">
                  <?= $profile['status'] === 'draft' ? 'Status' : 'Review' ?>
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

<!-- Bulk submission, driven from the action bar -->
<form id="kyc-bulk-form" method="post" action="/admin/kyc/bulk" style="display:none">
  <?= $this->csrf() ?>
  <input type="hidden" name="action" id="kyc-bulk-action">
  <input type="hidden" name="reason" id="kyc-bulk-reason">
  <div id="kyc-bulk-ids"></div>
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
/**
 * Bulk selection for the KYC queue.
 *
 * Deliberately dependency-free: it tracks checked rows, shows the action bar,
 * and posts a normal form so CSRF and redirect handling stay identical to
 * every other admin action.
 */
const Kyc = {
  rows() {
    return Array.from(document.querySelectorAll('.kyc-row'));
  },

  selected() {
    return this.rows().filter((row) => row.checked).map((row) => row.value);
  },

  toggleAll(master) {
    this.rows().forEach((row) => { row.checked = master.checked; });
    this.refresh();
  },

  clearSelection() {
    this.rows().forEach((row) => { row.checked = false; });
    const master = document.getElementById('kyc-select-all');
    if (master) { master.checked = false; }
    this.refresh();
  },

  refresh() {
    const bar = document.getElementById('kyc-bulk-bar');
    if (!bar) { return; }

    const count = this.selected().length;
    bar.style.display = count === 0 ? 'none' : '';
    document.getElementById('kyc-bulk-count').textContent = String(count);

    const master = document.getElementById('kyc-select-all');
    if (master) {
      master.indeterminate = count > 0 && count < this.rows().length;
    }
  },

  bulk(action) {
    const ids = this.selected();
    if (ids.length === 0) { return; }

    let reason = '';

    if (action === 'reject') {
      // A blanket rejection with no reason leaves every one of those users
      // with nothing to act on, so it is required here as well.
      const input = window.prompt(
        'Why are these ' + ids.length + ' submissions being rejected?\nEach user will see this message.'
      );
      if (input === null) { return; }
      reason = input.trim();
      if (reason.length < 5) {
        Admin.toast('Give a clear reason so users can resubmit correctly.', 'warning');
        return;
      }
    } else if (!window.confirm(
      'Approve ' + ids.length + ' submission(s)?\n'
      + 'Incomplete ones (missing or rejected documents, invalid details) are skipped and listed. '
      + 'So is any submission with an Aadhaar copy: its masking is confirmed on its own page. '
      + 'The blue tick is not granted by this.'
    )) {
      return;
    }

    document.getElementById('kyc-bulk-action').value = action;
    document.getElementById('kyc-bulk-reason').value = reason;

    const holder = document.getElementById('kyc-bulk-ids');
    holder.innerHTML = '';
    ids.forEach((id) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'ids[]';
      input.value = id;
      holder.appendChild(input);
    });

    document.getElementById('kyc-bulk-form').submit();
  },
};

document.addEventListener('DOMContentLoaded', () => Kyc.refresh());
</script>
<?php $this->stop(); ?>

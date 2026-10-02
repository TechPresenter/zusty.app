<?php

declare(strict_types=1);

/**
 * Creator detail and moderation.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $creator
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $socials
 * @var list<array<string,mixed>> $packages
 * @var list<array<string,mixed>> $portfolio
 * @var list<array<string,mixed>> $bookings
 * @var list<array<string,mixed>> $reviews
 * @var array<string,int> $portfolioCounts
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (string $s): string => match ($s) {
    'approved' => 'success', 'pending_review' => 'warning', 'rejected', 'suspended' => 'error', default => 'neutral',
};

?>

<a href="/admin/creators" class="btn btn--ghost btn--sm mb-4">
  <?= $this->icon('chevron-left', 'ic ic--sm') ?> Back to creators
</a>

<div class="grid grid--sidebar">

  <div class="col gap-4">

    <!-- Profile header -->
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
              <?php if (!empty($creator['is_verified'])): ?>
                <span class="chip chip--info"><?= $this->icon('check-circle', 'ic ic--sm') ?> Verified</span>
              <?php endif; ?>
              <?php if (!empty($creator['is_featured'])): ?>
                <span class="chip chip--primary"><?= $this->icon('star', 'ic ic--sm') ?> Featured</span>
              <?php endif; ?>
              <span class="chip chip--<?= $statusTone((string) $creator['status']) ?>">
                <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $creator['status']))) ?>
              </span>
            </div>
            <p class="muted small">
              @<?= $this->e($creator['handle']) ?>
              <?= $creator['headline'] ? ' · ' . $this->e($creator['headline']) : '' ?>
              <?= $creator['city'] ? ' · ' . $this->e($creator['city']) : '' ?>
            </p>
            <p class="tiny faint mt-1">
              <a href="/admin/users/<?= (int) $creator['user_id'] ?>" class="text-secondary"><?= $this->e($creator['email']) ?></a>
              · joined <?= $this->date($creator['joined_at']) ?>
            </p>
          </div>
        </div>

        <?php if (!empty($creator['about'])): ?>
          <hr class="divider">
          <div class="label mb-2">About</div>
          <p class="small" style="line-height:1.65"><?= nl2br($this->e($creator['about'])) ?></p>
        <?php endif; ?>

        <?php if ($categories !== []): ?>
          <div class="chip-row mt-4">
            <?php foreach ($categories as $category): ?>
              <span class="chip chip--neutral"><?= $this->e($category['name']) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid--4">
      <?php foreach ([
          ['Rating', $creator['rating_count'] > 0 ? '★ ' . number_format((float) $creator['rating_avg'], 1) : '—', 'star', 'var(--primary)'],
          ['Followers', $this->compact($creator['followers_total']), 'users', 'var(--secondary)'],
          ['Collaborations', $this->number($creator['collabs_count']), 'calendar', 'var(--tertiary)'],
          ['Completion', number_format((float) $creator['completion_rate'], 0) . '%', 'check-circle', '#b45309'],
      ] as [$label, $value, $icon, $tone]): ?>
        <div class="stat" style="--tone:<?= $tone ?>">
          <div class="stat__icon"><?= $this->icon($icon) ?></div>
          <div class="stat__value"><?= $this->e($value) ?></div>
          <div class="stat__label"><?= $this->e($label) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Social accounts -->
    <?php if ($socials !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Linked social accounts</h3></div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Platform</th><th>Handle</th><th class="text-right">Followers</th><th class="text-right">Engagement</th><th class="text-right">Link</th></tr></thead>
          <tbody>
            <?php foreach ($socials as $social): ?>
              <tr>
                <td class="small" style="font-weight:600"><?= $this->e(ucfirst((string) $social['platform'])) ?></td>
                <td class="small">@<?= $this->e($social['handle']) ?></td>
                <td class="text-right mono"><?= $this->compact($social['followers']) ?></td>
                <td class="text-right mono"><?= number_format((float) $social['engagement_rate'], 2) ?>%</td>
                <td class="actions">
                  <a href="<?= $this->e($social['profile_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"
                     class="btn btn--ghost btn--sm"><?= $this->icon('external', 'ic ic--sm') ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Packages -->
    <?php if ($packages !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Pricing packages</h3></div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Package</th><th>Platform</th><th>Deliverable</th><th class="text-right">Price</th><th class="text-right">Turnaround</th><th>Active</th></tr></thead>
          <tbody>
            <?php foreach ($packages as $package): ?>
              <tr>
                <td class="small" style="font-weight:600"><?= $this->e($package['title']) ?></td>
                <td class="small muted"><?= $this->e(ucfirst((string) $package['platform'])) ?></td>
                <td class="small muted"><?= (int) $package['quantity'] ?> × <?= $this->e($package['deliverable_type']) ?></td>
                <td class="text-right" style="font-weight:700"><?= $this->moneyShort($package['price'], (string) $package['currency']) ?></td>
                <td class="text-right small muted"><?= (int) $package['turnaround_days'] ?>d</td>
                <td><span class="chip chip--<?= $package['is_active'] ? 'success' : 'neutral' ?>"><?= $package['is_active'] ? 'Live' : 'Off' ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Portfolio -->
    <?php if ($portfolio !== []): ?>
    <div class="card">
      <div class="card__head">
        <h3 class="h3">Portfolio</h3>
        <span class="label">
          <?= (int) ($portfolioCounts['post'] ?? 0) ?> posts ·
          <?= (int) ($portfolioCounts['reel'] ?? 0) ?> reels ·
          <?= (int) ($portfolioCounts['photo'] ?? 0) ?> photos ·
          <?= (int) ($portfolioCounts['video'] ?? 0) ?> videos
        </span>
      </div>
      <div class="card__body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px">
          <?php foreach ($portfolio as $item): ?>
            <div style="position:relative;aspect-ratio:3/4;border-radius:var(--r-md);overflow:hidden;background:var(--surface-container)">
              <img src="<?= $this->e($item['thumbnail_url'] ?: $item['media_url']) ?>" alt=""
                   loading="lazy" style="width:100%;height:100%;object-fit:cover">
              <span class="chip chip--neutral" style="position:absolute;top:6px;left:6px;font-size:9px;padding:2px 7px">
                <?= $this->e($item['media_kind']) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Reviews -->
    <?php if ($reviews !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Recent reviews</h3></div>
      <div class="card__body col gap-4">
        <?php foreach ($reviews as $review): ?>
          <div>
            <div class="row row--between mb-1">
              <span class="small" style="font-weight:600"><?= $this->e($review['reviewer_name']) ?></span>
              <span class="small text-primary" style="font-weight:700">★ <?= (int) $review['rating'] ?>.0</span>
            </div>
            <?php if (!empty($review['comment'])): ?>
              <p class="small muted"><?= $this->e($review['comment']) ?></p>
            <?php endif; ?>
            <p class="tiny faint mt-1"><?= $this->date($review['created_at']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ============ Moderation sidebar ============ -->
  <div class="col gap-4">

    <?php if ($this->can('creators.approve')): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Moderation</h3></div>
      <div class="card__body col gap-3">

        <?php if (!empty($creator['rejection_reason'])): ?>
          <div class="card" style="border-color:var(--error);background:var(--error-container)">
            <div class="card__body" style="padding:12px 14px">
              <div class="label mb-1" style="color:var(--on-error-container)">Previous rejection</div>
              <p class="tiny" style="color:var(--on-error-container)"><?= $this->e($creator['rejection_reason']) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($creator['status'] !== 'approved'): ?>
          <form method="post" action="/admin/creators/<?= (int) $creator['id'] ?>/moderate">
            <?= $this->csrf() ?>
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn btn--success btn--block"
                    data-confirm="The profile goes live and becomes bookable immediately."
                    data-confirm-title="Approve this creator?" data-confirm-label="Approve" data-confirm-tone="info">
              <?= $this->icon('check', 'ic ic--sm') ?> Approve profile
            </button>
          </form>
        <?php endif; ?>

        <?php if (!in_array($creator['status'], ['rejected'], true)): ?>
          <button type="button" class="btn btn--outline btn--block" onclick="moderate('reject')">
            <?= $this->icon('x', 'ic ic--sm') ?> Reject with reason
          </button>
        <?php endif; ?>

        <?php if ($creator['status'] === 'approved'): ?>
          <button type="button" class="btn btn--danger btn--block" onclick="moderate('suspend')">
            <?= $this->icon('alert', 'ic ic--sm') ?> Suspend profile
          </button>
        <?php endif; ?>

        <?php if ($creator['status'] === 'suspended'): ?>
          <form method="post" action="/admin/creators/<?= (int) $creator['id'] ?>/moderate">
            <?= $this->csrf() ?>
            <input type="hidden" name="action" value="reinstate">
            <button type="submit" class="btn btn--secondary btn--block">
              <?= $this->icon('refresh', 'ic ic--sm') ?> Reinstate
            </button>
          </form>
        <?php endif; ?>

        <?php if (!empty($creator['approved_by_name'])): ?>
          <p class="tiny faint mt-2">
            Approved by <?= $this->e($creator['approved_by_name']) ?> on <?= $this->date($creator['approved_at']) ?>
          </p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($this->can('creators.view')): ?>
    <div class="card">
      <div class="card__head">
        <h3 class="h3">Verification badges</h3>
        <?php if ($this->can('creators.verify')): ?>
          <form method="post" action="/admin/creators/<?= (int) $creator['id'] ?>/badges/sync">
            <?= $this->csrf() ?>
            <button type="submit" class="btn btn--ghost btn--sm" title="Re-evaluate the badges the platform awards itself">
              <?= $this->icon('rotate-cw') ?> Re-check
            </button>
          </form>
        <?php endif; ?>
      </div>
      <div class="card__body col gap-3">
        <?php foreach ($badges as $badge): ?>
          <div class="row gap-3" style="align-items:flex-start;justify-content:space-between">
            <div class="row gap-2" style="align-items:flex-start;min-width:0">
              <span style="color:<?= $this->e($badge['color']) ?>;flex:0 0 auto"><?= $this->icon($badge['icon']) ?></span>
              <div style="min-width:0">
                <div class="row gap-2" style="align-items:center;flex-wrap:wrap">
                  <strong><?= $this->e($badge['label']) ?></strong>
                  <?php
                    $tone = match ($badge['status']) {
                        'active'  => 'success',
                        'revoked' => 'error',
                        'expired' => 'warning',
                        default   => 'neutral',
                    };
                  ?>
                  <span class="chip chip--<?= $tone ?> chip--sm"><?= $this->e(ucfirst($badge['status'])) ?></span>
                  <?php if ($badge['automatic']): ?>
                    <span class="chip chip--neutral chip--sm" title="Awarded by the platform from a rule, not by hand">Automatic</span>
                  <?php endif; ?>
                </div>
                <p class="small muted" style="margin:2px 0 0"><?= $this->e($badge['description']) ?></p>
                <?php if ($badge['status'] === 'active' && $badge['awarded_at'] !== null): ?>
                  <p class="small muted" style="margin:2px 0 0">
                    Awarded <?= $this->e(date('d M Y', strtotime((string) $badge['awarded_at']))) ?>
                    <?php if ($badge['expires_at'] !== null): ?>
                      · expires <?= $this->e(date('d M Y', strtotime((string) $badge['expires_at']))) ?>
                    <?php endif; ?>
                  </p>
                <?php elseif ($badge['status'] === 'revoked' && $badge['revoke_reason'] !== null): ?>
                  <p class="small muted" style="margin:2px 0 0">Revoked: <?= $this->e($badge['revoke_reason']) ?></p>
                <?php endif; ?>
              </div>
            </div>

            <?php
              // Featuring is a promotional decision with its own permission;
              // everything else is a verification one. KYC-derived badges,
              // automatic ones and the blue tick are never changed by hand
              // here (the server refuses them too): they follow the KYC review,
              // their rule, and the "Verified" switch respectively.
              $mayChange = ($badge['manageable'] ?? true) && ($badge['value'] === 'featured_creator'
                  ? $this->can('creators.feature')
                  : $this->can('creators.verify'));
            ?>
            <?php if (!empty($badge['kyc_derived'])): ?>
              <span class="small muted" style="flex:0 0 auto">Follows KYC review</span>
            <?php endif; ?>
            <?php if ($mayChange): ?>
              <?php if ($badge['held']): ?>
                <form method="post" action="/admin/creators/<?= (int) $creator['id'] ?>/badges/revoke"
                      onsubmit="return !!this.reason.value.trim()">
                  <?= $this->csrf() ?>
                  <input type="hidden" name="badge" value="<?= $this->e($badge['value']) ?>">
                  <input class="input input--sm" type="text" name="reason" maxlength="500" required
                         placeholder="Reason (required)" style="width:150px;margin-bottom:4px">
                  <button type="submit" class="btn btn--danger btn--sm btn--block">Revoke</button>
                </form>
              <?php else: ?>
                <form method="post" action="/admin/creators/<?= (int) $creator['id'] ?>/badges/award">
                  <?= $this->csrf() ?>
                  <input type="hidden" name="badge" value="<?= $this->e($badge['value']) ?>">
                  <input class="input input--sm" type="text" name="note" maxlength="500"
                         placeholder="Note (optional)" style="width:150px;margin-bottom:4px">
                  <button type="submit" class="btn btn--secondary btn--sm btn--block">Award</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="divider"></div>
        <?php endforeach; ?>

        <?php if ($badgeHistory !== []): ?>
          <details>
            <summary class="small muted" style="cursor:pointer">Verification history (<?= count($badgeHistory) ?>)</summary>
            <div class="col gap-2" style="margin-top:8px">
              <?php foreach ($badgeHistory as $event): ?>
                <p class="small muted" style="margin:0">
                  <strong><?= $this->e(date('d M Y H:i', strtotime((string) $event['created_at']))) ?></strong>
                  — <?= $this->e($event['badge']) ?> <?= $this->e($event['action']) ?>
                  by <?= $this->e($event['actor_name'] ?: $event['actor_type']) ?>
                  <?php if (!empty($event['note'])): ?>· <?= $this->e($event['note']) ?><?php endif; ?>
                </p>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($this->can('creators.edit')): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Profile section locks</h3></div>
      <div class="card__body col gap-2">
        <p class="small muted" style="margin:0 0 4px">
          A locked section stays visible but the creator cannot change it. Everything else on their profile stays theirs to edit.
        </p>
        <?php foreach ($locks as $lock): ?>
          <div class="row gap-2" style="align-items:center;justify-content:space-between">
            <div class="row gap-2" style="align-items:center;min-width:0">
              <span style="color:<?= $lock['locked'] ? 'var(--error)' : 'var(--text-muted)' ?>;flex:0 0 auto">
                <?= $this->icon($lock['locked'] ? 'lock' : 'unlock') ?>
              </span>
              <div style="min-width:0">
                <span class="small"><?= $this->e($lock['label']) ?></span>
                <?php if ($lock['locked'] && !empty($lock['reason'])): ?>
                  <p class="small muted" style="margin:0"><?= $this->e($lock['reason']) ?></p>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($lock['locked']): ?>
              <form method="post" action="/admin/creators/<?= (int) $creator['id'] ?>/locks">
                <?= $this->csrf() ?>
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="section" value="<?= $this->e($lock['section']) ?>">
                <button type="submit" class="btn btn--ghost btn--sm">Unlock</button>
              </form>
            <?php else: ?>
              <form method="post" action="/admin/creators/<?= (int) $creator['id'] ?>/locks" class="row gap-2">
                <?= $this->csrf() ?>
                <input type="hidden" name="section" value="<?= $this->e($lock['section']) ?>">
                <input class="input input--sm" type="text" name="reason" maxlength="500"
                       placeholder="Reason" style="width:130px">
                <button type="submit" class="btn btn--ghost btn--sm">Lock</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($this->can('creators.verify')): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Badges &amp; commission</h3></div>
      <div class="card__body">
        <form method="post" action="/admin/creators/<?= (int) $creator['id'] ?>/flags">
          <?= $this->csrf() ?>

          <?php
            // Each switch posts a hidden "0" first, so an unticked switch says
            // so (a bare checkbox posts nothing when unticked). The server only
            // acts on a switch whose state actually changed.
          ?>
          <?php if ($this->can('kyc.badge')): ?>
            <input type="hidden" name="is_verified" value="0">
            <label class="switch mb-4">
              <input type="checkbox" name="is_verified" value="1" <?= $creator['is_verified'] ? 'checked' : '' ?>>
              <span class="switch__track"></span>
              <span class="small">Verified badge (blue tick)</span>
            </label>
          <?php else: ?>
            <?php // Not posted at all: without kyc.badge the tick is shown, not changed. ?>
            <label class="switch mb-1">
              <input type="checkbox" disabled <?= $creator['is_verified'] ? 'checked' : '' ?>>
              <span class="switch__track"></span>
              <span class="small">Verified badge (blue tick)</span>
            </label>
            <p class="field__hint mb-4">Granted or removed by an administrator with the blue-tick permission, after KYC approval.</p>
          <?php endif; ?>

          <?php if ($this->can('creators.feature')): ?>
            <input type="hidden" name="is_featured" value="0">
            <label class="switch mb-4">
              <input type="checkbox" name="is_featured" value="1" <?= $creator['is_featured'] ? 'checked' : '' ?>>
              <span class="switch__track"></span>
              <span class="small">Featured placement</span>
            </label>
          <?php endif; ?>

          <div class="field">
            <label class="field__label" for="commission_rate">Commission override (%)</label>
            <input class="input" type="number" step="0.01" min="0" max="100" name="commission_rate" id="commission_rate"
                   value="<?= $creator['commission_rate'] !== null ? $this->e($creator['commission_rate']) : '' ?>"
                   placeholder="Leave blank to use the platform default">
            <p class="field__hint">Blank inherits the global rate from Settings → Commission.</p>
          </div>

          <button type="submit" class="btn btn--primary btn--block">Save changes</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($bookings !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Recent bookings</h3></div>
      <div class="card__body col gap-3">
        <?php foreach (array_slice($bookings, 0, 8) as $booking): ?>
          <div class="row row--between gap-2">
            <div class="flex-1 truncate">
              <a class="small text-primary mono" href="/admin/bookings/<?= (int) $booking['id'] ?>">#<?= $this->e($booking['code']) ?></a>
              <div class="tiny muted truncate"><?= $this->e($booking['title']) ?></div>
            </div>
            <span class="small mono" style="font-weight:600"><?= $this->moneyShort($booking['creator_earning'], (string) $booking['currency']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<form id="moderate-form" method="post" action="/admin/creators/<?= (int) $creator['id'] ?>/moderate" style="display:none">
  <?= $this->csrf() ?>
  <input type="hidden" name="action" id="moderate-action">
  <input type="hidden" name="reason" id="moderate-reason">
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
function moderate(action) {
  const verb = action === 'reject' ? 'rejecting' : 'suspending';
  const reason = window.prompt('Why are you ' + verb + ' this profile?\nThe creator will see this message.');
  if (reason === null) return;

  if (reason.trim().length < 5) {
    Admin.toast('Give a clear reason so the creator knows what to fix.', 'warning');
    return;
  }

  document.getElementById('moderate-action').value = action;
  document.getElementById('moderate-reason').value = reason.trim();
  document.getElementById('moderate-form').submit();
}
</script>
<?php $this->stop(); ?>

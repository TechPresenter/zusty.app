<?php

declare(strict_types=1);

/**
 * One account's contact details, their proof state, and the log behind them.
 *
 * The screen is built around one question an operator actually asks: "is this
 * address really theirs, and how do we know?" So every row shows the proof
 * route — a redeemed code, an operator's decision, or nothing at all — rather
 * than a bare verified tick that says who but never how.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $user
 * @var array<string,array<string,mixed>> $primary
 * @var list<array<string,mixed>> $alternates
 * @var list<array<string,mixed>> $requests
 * @var list<array<string,mixed>> $history
 * @var array<string,mixed> $policy
 * @var int|null $creatorId
 * @var string $lockLabel
 * @var array<string,string> $labels
 * @var array<string,string> $reasons
 */

$this->extend('admin.layouts.app');
$this->start('content');

/** Colour for a change-request state. */
$requestTone = static fn (string $s): string => match ($s) {
    'applied'                => 'success',
    'verified'               => 'info',
    'pending'                => 'warning',
    'rejected', 'expired'    => 'error',
    default                  => 'neutral',
};

/**
 * How an address was proved, in words. `verified_via` is an
 * application-owned vocabulary on a VARCHAR, so an unknown word is shown
 * verbatim rather than swallowed — a proof route nobody can read is worse
 * than an ugly one.
 */
$viaText = static function (?string $via): string {
    return match ($via) {
        'otp'         => 'one-time code',
        'admin_force' => 'operator decision',
        'firebase'    => 'Firebase sign-in',
        'import'      => 'imported record',
        null, ''      => 'unrecorded',
        default       => $via,
    };
};

$canVerify = $this->can('creators.verify');
$canEdit   = $this->can('users.edit');

?>

<a href="/admin/users/<?= (int) $user['id'] ?>" class="btn btn--ghost btn--sm mb-4">
  <?= $this->icon('chevron-left', 'ic ic--sm') ?> Back to <?= $this->e((string) $user['name']) ?>
</a>

<div class="grid grid--sidebar">

  <!-- ==================== Left column ==================== -->
  <div class="col gap-4">

    <!-- ---------- The two primary identifiers ---------- -->
    <div class="card">
      <div class="card__head">
        <h2 class="h2">Primary contact details</h2>
        <p class="tiny faint">The identifiers on the account itself. These are what sign-in and recovery use.</p>
      </div>
      <div class="card__body col gap-4">

        <?php foreach (['email' => 'Email address', 'phone' => 'Mobile number'] as $channel => $heading): ?>
          <?php
          $row      = $primary[$channel];
          $value    = $channel === 'email' ? ($row['value'] ?? null) : ($row['display'] ?? null);
          $verified = (bool) $row['verified'];
          ?>
          <div>
            <div class="row gap-2 row--wrap mb-1">
              <?= $this->icon($channel === 'email' ? 'mail' : 'smartphone', 'ic ic--sm') ?>
              <span class="label"><?= $this->e($heading) ?></span>
              <?php if ($verified): ?>
                <span class="chip chip--success chip--sm"><?= $this->icon('check', 'ic ic--sm') ?> Verified</span>
              <?php else: ?>
                <span class="chip chip--warning chip--sm">Unverified</span>
              <?php endif; ?>
            </div>

            <p class="small"><?= $value === null || $value === '' ? '<span class="muted">Not set</span>' : $this->e((string) $value) ?></p>

            <?php if ($verified): ?>
              <p class="tiny faint mt-1">Proved <?= $this->dateTime($row['verified_at']) ?></p>
            <?php endif; ?>

            <?php if ($row['pending'] !== null): ?>
              <p class="tiny mt-1">
                <span class="chip chip--warning chip--sm">Pending change</span>
                to <strong><?= $this->e(
                    $channel === 'email'
                        ? (string) $row['pending']['new_value']
                        : (string) $row['pending']['new_phone_country_code'] . (string) $row['pending']['new_value']
                ) ?></strong>
                &middot; expires <?= $this->dateTime($row['pending']['expires_at']) ?>
              </p>
            <?php endif; ?>

            <?php if ($value !== null && $value !== ''): ?>
              <div class="row gap-2 row--wrap mt-1">
                <?php if (!$verified && $canVerify): ?>
                  <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/contacts/force-verify">
                    <?= $this->csrf() ?>
                    <input type="hidden" name="channel" value="<?= $this->e($channel) ?>">
                    <input type="hidden" name="note" value="Verified by an administrator from the contact screen.">
                    <button type="submit" class="btn btn--sm btn--secondary">
                      <?= $this->icon('user-check', 'ic ic--sm') ?> Mark verified
                    </button>
                  </form>
                <?php endif; ?>

                <?php if ($verified && $canVerify): ?>
                  <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/contacts/revoke" class="row gap-2 row--wrap">
                    <?= $this->csrf() ?>
                    <input type="hidden" name="channel" value="<?= $this->e($channel) ?>">
                    <input class="input input--sm" type="text" name="reason" required minlength="3" maxlength="1000"
                           placeholder="Why is this being revoked?">
                    <button type="submit" class="btn btn--sm btn--danger">
                      <?= $this->icon('x', 'ic ic--sm') ?> Revoke
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

          <hr class="divider">
        <?php endforeach; ?>

        <p class="tiny faint">
          A verification is never removed silently: revoking one writes a row in the log below with your name
          and your reason on it.
        </p>
      </div>
    </div>

    <!-- ---------- Alternates ---------- -->
    <div class="card">
      <div class="card__head">
        <h2 class="h2">Additional contacts</h2>
        <p class="tiny faint">
          Extra addresses this account has claimed. A proved one is exclusive across the whole platform;
          an unproved one blocks nobody.
        </p>
      </div>
      <div class="card__body">
        <?php if ($alternates === []): ?>
          <div class="empty">
            <?= $this->icon('inbox', 'ic') ?>
            <p class="small muted">This account has not added any additional contacts.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Contact</th>
                  <th>Label</th>
                  <th>State</th>
                  <th>Proved by</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($alternates as $point): ?>
                  <tr>
                    <td>
                      <div class="row gap-2">
                        <?= $this->icon($point['channel'] === 'email' ? 'mail' : 'smartphone', 'ic ic--sm') ?>
                        <span class="small"><?= $this->e((string) $point['display']) ?></span>
                      </div>
                      <?php if ($point['is_recovery']): ?>
                        <span class="chip chip--info chip--sm">Recovery</span>
                      <?php endif; ?>
                    </td>
                    <td class="small"><?= $this->e($point['label_text'] ?? '—') ?></td>
                    <td>
                      <?php if ($point['released_at'] !== null): ?>
                        <span class="chip chip--neutral chip--sm">Released</span>
                      <?php elseif ($point['verified']): ?>
                        <span class="chip chip--success chip--sm">Verified</span>
                      <?php elseif ($point['pending'] !== null): ?>
                        <span class="chip chip--warning chip--sm">Awaiting code</span>
                      <?php else: ?>
                        <span class="chip chip--warning chip--sm">Unverified</span>
                      <?php endif; ?>
                    </td>
                    <td class="tiny faint">
                      <?php if ($point['verified']): ?>
                        <?= $this->e($viaText($point['verified_via'])) ?><br>
                        <?= $this->dateTime($point['verified_at']) ?>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($canVerify && $point['released_at'] === null): ?>
                        <?php if (!$point['verified']): ?>
                          <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/contacts/force-verify">
                            <?= $this->csrf() ?>
                            <input type="hidden" name="channel" value="<?= $this->e((string) $point['channel']) ?>">
                            <input type="hidden" name="contact_point_id" value="<?= (int) $point['id'] ?>">
                            <input type="hidden" name="note" value="Verified by an administrator from the contact screen.">
                            <button type="submit" class="btn btn--sm btn--secondary">Mark verified</button>
                          </form>
                        <?php else: ?>
                          <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/contacts/revoke" class="row gap-2 row--wrap">
                            <?= $this->csrf() ?>
                            <input type="hidden" name="channel" value="<?= $this->e((string) $point['channel']) ?>">
                            <input type="hidden" name="contact_point_id" value="<?= (int) $point['id'] ?>">
                            <input class="input input--sm" type="text" name="reason" required minlength="3"
                                   maxlength="1000" placeholder="Reason">
                            <button type="submit" class="btn btn--sm btn--danger">Revoke</button>
                          </form>
                        <?php endif; ?>
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

    <!-- ---------- Change requests ---------- -->
    <div class="card">
      <div class="card__head">
        <h2 class="h2">Change requests</h2>
        <p class="tiny faint">
          A proposal, not a fact. Nothing on the account moves until a code sent to the new address comes back,
          so a request sitting at "pending" has changed nothing.
        </p>
      </div>
      <div class="card__body">
        <?php if ($requests === []): ?>
          <div class="empty">
            <?= $this->icon('clock', 'ic') ?>
            <p class="small muted">No contact change has ever been requested on this account.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Requested</th>
                  <th>Change</th>
                  <th>Reason</th>
                  <th>Codes</th>
                  <th>State</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($requests as $req): ?>
                  <?php
                  $newValue = $req['channel'] === 'email'
                      ? (string) $req['new_value']
                      : (string) $req['new_phone_country_code'] . (string) $req['new_value'];
                  ?>
                  <tr>
                    <td class="tiny faint"><?= $this->dateTime($req['created_at']) ?></td>
                    <td class="small">
                      <span class="chip chip--neutral chip--sm"><?= $this->e(ucfirst((string) $req['target'])) ?></span>
                      <?php if (!empty($req['current_value'])): ?>
                        <span class="muted"><?= $this->e((string) $req['current_value']) ?></span> &rarr;
                      <?php endif; ?>
                      <strong><?= $this->e($newValue) ?></strong>
                    </td>
                    <td class="tiny">
                      <?= $this->e($reasons[(string) ($req['reason_code'] ?? '')] ?? '—') ?>
                      <?php if (!empty($req['reason_note'])): ?>
                        <br><span class="faint"><?= $this->e((string) $req['reason_note']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($req['review_note'])): ?>
                        <br><span class="faint">Operator: <?= $this->e((string) $req['review_note']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="tiny faint"><?= (int) $req['attempt_count'] ?> sent</td>
                    <td>
                      <span class="chip chip--<?= $this->e($requestTone((string) $req['status'])) ?> chip--sm">
                        <?= $this->e(ucfirst((string) $req['status'])) ?>
                      </span>
                      <?php if ($req['status'] === 'pending'): ?>
                        <br><span class="tiny faint">expires <?= $this->dateTime($req['expires_at']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($req['status'] === 'pending' && $canEdit): ?>
                        <form method="post"
                              action="/admin/users/<?= (int) $user['id'] ?>/contacts/requests/<?= (int) $req['id'] ?>/reject"
                              class="row gap-2 row--wrap">
                          <?= $this->csrf() ?>
                          <input class="input input--sm" type="text" name="note" required minlength="3" maxlength="500"
                                 placeholder="Why refuse this?">
                          <button type="submit" class="btn btn--sm btn--danger">Reject</button>
                        </form>
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

    <!-- ---------- The log ---------- -->
    <div class="card">
      <div class="card__head">
        <h2 class="h2">Verification log</h2>
        <p class="tiny faint">
          The durable record. One-time codes are purged after seven days, so this table is the only place a
          question older than a week can be answered.
        </p>
      </div>
      <div class="card__body">
        <?php if ($history === []): ?>
          <div class="empty">
            <?= $this->icon('file', 'ic') ?>
            <p class="small muted">Nothing recorded yet. The log starts from the day this account first touches a contact.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto">
            <table class="table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Action</th>
                  <th>Address</th>
                  <th>Change</th>
                  <th>By</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($history as $event): ?>
                  <tr>
                    <td class="tiny faint"><?= $this->dateTime($event['created_at']) ?></td>
                    <td class="small">
                      <?= $this->e(ucfirst(str_replace('_', ' ', (string) $event['action']))) ?>
                      <?php if (!empty($event['note'])): ?>
                        <br><span class="tiny faint"><?= $this->e((string) $event['note']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="tiny">
                      <?= $this->e((string) ($event['destination'] ?? '—')) ?>
                      <?php if (!empty($event['channel'])): ?>
                        <br><span class="faint"><?= $this->e((string) $event['channel']) ?>
                          / <?= $this->e((string) ($event['target'] ?? '')) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="tiny faint">
                      <?php if ($event['from_status'] !== null || $event['to_status'] !== null): ?>
                        <?= $this->e((string) ($event['from_status'] ?? '—')) ?>
                        &rarr; <?= $this->e((string) ($event['to_status'] ?? '—')) ?>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                    <td class="tiny">
                      <span class="chip chip--neutral chip--sm"><?= $this->e((string) $event['actor_type']) ?></span>
                      <?php if (!empty($event['actor_name'])): ?>
                        <br><?= $this->e((string) $event['actor_name']) ?>
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
  </div>

  <!-- ==================== Right column ==================== -->
  <div class="col gap-4">

    <div class="card">
      <div class="card__head"><h3 class="h3">Account</h3></div>
      <div class="card__body col gap-2">
        <div class="row gap-3">
          <?php if (!empty($user['avatar_url'])): ?>
            <img class="avatar" src="<?= $this->e((string) $user['avatar_url']) ?>" alt="">
          <?php else: ?>
            <div class="avatar avatar--fallback"><?= $this->e($this->initials((string) $user['name'])) ?></div>
          <?php endif; ?>
          <div class="flex-1">
            <p class="small"><strong><?= $this->e((string) $user['name']) ?></strong></p>
            <p class="tiny faint">
              <?= $this->e(ucfirst((string) $user['account_type'])) ?>
              &middot; joined <?= $this->date($user['created_at']) ?>
            </p>
          </div>
        </div>

        <?php if ($user['deleted_at'] !== null): ?>
          <p class="tiny">
            <span class="chip chip--error chip--sm">Deleted</span>
            Identifiers held by this account were released on <?= $this->date($user['deleted_at']) ?>.
          </p>
        <?php endif; ?>

        <a class="btn btn--sm btn--ghost btn--block" href="/admin/users/<?= (int) $user['id'] ?>">
          <?= $this->icon('eye', 'ic ic--sm') ?> Open the full user record
        </a>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h3 class="h3">Policy in force</h3></div>
      <div class="card__body col gap-2">
        <?php
        $switches = [
            'Proof of possession (OTP)' => $policy['otp_verification'],
            'Email changes allowed'     => $policy['allow_email_change'],
            'Mobile changes allowed'    => $policy['allow_mobile_change'],
            'Additional contacts'       => $policy['allow_alternate_contacts'],
        ];
        ?>
        <?php foreach ($switches as $label => $on): ?>
          <div class="row gap-2">
            <span class="chip chip--<?= $on ? 'success' : 'neutral' ?> chip--sm"><?= $on ? 'On' : 'Off' ?></span>
            <span class="small flex-1"><?= $this->e((string) $label) ?></span>
          </div>
        <?php endforeach; ?>

        <hr class="divider">

        <p class="tiny faint">
          Up to <strong><?= (int) $policy['max_alternates_per_channel'] ?></strong> additional contact(s) per channel.
          A request stays open for <strong><?= (int) $policy['change_ttl_minutes'] ?></strong> minutes and accepts
          <strong><?= (int) $policy['max_resends'] ?></strong> codes, at most one every
          <strong><?= (int) $policy['resend_seconds'] ?></strong> seconds.
        </p>

        <?php if (!$policy['otp_verification']): ?>
          <p class="tiny">
            <span class="chip chip--warning chip--sm"><?= $this->icon('alert-triangle', 'ic ic--sm') ?> No proof required</span>
            With codes switched off a change is applied immediately and the new value is recorded as
            <em>unverified</em>, because nobody proved it.
          </p>
        <?php endif; ?>

        <a class="btn btn--sm btn--ghost btn--block" href="/admin/settings/creator">
          <?= $this->icon('settings', 'ic ic--sm') ?> Change these settings
        </a>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h3 class="h3">Contact lock</h3></div>
      <div class="card__body col gap-2">
        <?php if ($creatorId === null): ?>
          <p class="tiny faint">
            Section locks are keyed to creator profiles and this account has none, so its contact details
            cannot be frozen. Reject the individual request instead — it is recorded with your name against it.
          </p>
        <?php else: ?>
          <div class="row gap-2">
            <span class="chip chip--<?= $policy['contact_locked'] ? 'error' : 'success' ?> chip--sm">
              <?= $this->icon($policy['contact_locked'] ? 'lock' : 'unlock', 'ic ic--sm') ?>
              <?= $policy['contact_locked'] ? 'Locked' : 'Open' ?>
            </span>
            <span class="small flex-1"><?= $this->e($lockLabel) ?></span>
          </div>
          <p class="tiny faint">
            Locking is handled on the creator profile so there is exactly one answer to "is this frozen".
          </p>
          <a class="btn btn--sm btn--ghost btn--block" href="/admin/creators/<?= (int) $creatorId ?>">
            <?= $this->icon('lock', 'ic ic--sm') ?> Open the creator profile
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php $this->stop(); ?>

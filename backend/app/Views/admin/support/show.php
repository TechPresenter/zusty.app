<?php

declare(strict_types=1);

/**
 * Support conversation.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $ticket
 * @var list<array<string,mixed>> $messages
 * @var list<array<string,mixed>> $agents
 * @var list<array<string,mixed>> $otherTickets
 */

$this->extend('admin.layouts.app');
$this->start('content');

$canReply = $this->can('support.reply');

?>

<a href="/admin/tickets" class="btn btn--ghost btn--sm mb-4">
  <?= $this->icon('chevron-left', 'ic ic--sm') ?> Back to tickets
</a>

<div class="grid grid--sidebar">

  <div class="col gap-4">

    <!-- Conversation -->
    <div class="card">
      <div class="card__head">
        <div>
          <div class="row gap-2 row--wrap">
            <span class="mono h3 text-primary"><?= $this->e($ticket['code']) ?></span>
            <span class="chip chip--<?= in_array($ticket['status'], ['resolved', 'closed'], true) ? 'success' : 'warning' ?>">
              <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $ticket['status']))) ?>
            </span>
            <span class="chip chip--<?= $ticket['priority'] === 'urgent' ? 'error' : 'neutral' ?>">
              <?= $this->e(ucfirst((string) $ticket['priority'])) ?>
            </span>
          </div>
          <p class="muted small mt-1"><?= $this->e($ticket['subject']) ?></p>
        </div>
      </div>

      <div class="card__body col gap-4" style="max-height:640px;overflow-y:auto">
        <?php foreach ($messages as $message): ?>
          <?php
          $isAgent    = $message['sender_type'] === 'agent';
          $isInternal = (int) $message['is_internal'] === 1;
          ?>
          <div style="display:flex;justify-content:<?= $isAgent ? 'flex-end' : 'flex-start' ?>">
            <div style="max-width:78%">
              <div class="row gap-2 mb-1" style="justify-content:<?= $isAgent ? 'flex-end' : 'flex-start' ?>">
                <span class="tiny" style="font-weight:600">
                  <?= $this->e($message['sender_name'] ?: ($isAgent ? 'Support' : (string) $ticket['user_name'])) ?>
                </span>
                <?php if ($isInternal): ?>
                  <span class="chip chip--warning" style="font-size:9px;padding:1px 6px">Internal note</span>
                <?php endif; ?>
                <span class="tiny faint"><?= $this->dateTime($message['created_at']) ?></span>
              </div>

              <div style="padding:12px 16px;border-radius:var(--r-lg);
                          <?= $isInternal
                              ? 'background:var(--warning-container);border:1px dashed var(--warning);'
                              : ($isAgent
                                  ? 'background:var(--secondary);color:#fff;border-bottom-right-radius:6px;'
                                  : 'background:var(--surface-container);border-bottom-left-radius:6px;') ?>">
                <p class="small" style="line-height:1.6;white-space:pre-wrap"><?= $this->e($message['message']) ?></p>

                <?php foreach ($message['attachments'] as $attachment): ?>
                  <a href="<?= $this->e($attachment['url'] ?? '#') ?>" target="_blank" rel="noopener noreferrer"
                     class="tiny mt-2" style="display:inline-flex;align-items:center;gap:5px;text-decoration:underline">
                    <?= $this->icon('file', 'ic ic--sm') ?> Attachment
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($canReply && $ticket['status'] !== 'closed'): ?>
        <div class="card__foot">
          <form method="post" action="/admin/tickets/<?= (int) $ticket['id'] ?>/reply" enctype="multipart/form-data">
            <?= $this->csrf() ?>

            <div class="field">
              <label class="field__label" for="message">Reply</label>
              <textarea class="textarea" name="message" id="message" rows="4" required maxlength="5000"
                        placeholder="Write your reply to the customer…"></textarea>
            </div>

            <div class="row gap-3 row--wrap">
              <label class="checkbox small">
                <input type="checkbox" name="is_internal" value="1">
                <span>Internal note (hidden from the customer)</span>
              </label>

              <select class="select" name="status" style="max-width:200px" aria-label="Set status">
                <option value="pending_user">Awaiting customer</option>
                <option value="pending_agent">Needs a reply</option>
                <option value="resolved">Mark resolved</option>
                <option value="closed">Close ticket</option>
              </select>

              <input class="input" type="file" name="attachment" style="max-width:220px"
                     accept=".pdf,.jpg,.jpeg,.png" aria-label="Attachment">

              <div class="toolbar__spacer"></div>

              <button type="submit" class="btn btn--primary">
                <?= $this->icon('chat', 'ic ic--sm') ?> Send reply
              </button>
            </div>
          </form>
        </div>
      <?php elseif ($ticket['status'] === 'closed'): ?>
        <div class="card__foot text-center">
          <p class="small muted">This ticket is closed. The customer must open a new one to continue.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Sidebar -->
  <div class="col gap-4">

    <div class="card">
      <div class="card__head"><h3 class="h3">Customer</h3></div>
      <div class="card__body">
        <div class="row gap-3 mb-4">
          <?php if (!empty($ticket['avatar_url'])): ?>
            <img class="avatar" src="<?= $this->e($ticket['avatar_url']) ?>" alt="">
          <?php else: ?>
            <div class="avatar avatar--fallback"><?= $this->e($this->initials((string) $ticket['user_name'])) ?></div>
          <?php endif; ?>
          <div class="flex-1 truncate">
            <a href="/admin/users/<?= (int) $ticket['user_id'] ?>" style="font-weight:600">
              <?= $this->e($ticket['user_name']) ?>
            </a>
            <div class="tiny muted truncate"><?= $this->e($ticket['email']) ?></div>
          </div>
        </div>

        <div class="col gap-2">
          <div class="row row--between">
            <span class="tiny faint">Account</span>
            <span class="tiny"><?= $this->e(ucfirst((string) $ticket['account_type'])) ?></span>
          </div>
          <div class="row row--between">
            <span class="tiny faint">Status</span>
            <span class="tiny"><?= $this->e(ucfirst((string) $ticket['user_status'])) ?></span>
          </div>
          <div class="row row--between">
            <span class="tiny faint">Member since</span>
            <span class="tiny"><?= $this->date($ticket['user_joined']) ?></span>
          </div>
          <?php if (!empty($ticket['booking_code'])): ?>
            <div class="row row--between">
              <span class="tiny faint">Booking</span>
              <a href="/admin/bookings/<?= (int) $ticket['booking_id'] ?>" class="tiny mono text-primary">
                #<?= $this->e($ticket['booking_code']) ?>
              </a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($this->can('support.assign')): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Routing</h3></div>
      <div class="card__body">
        <form method="post" action="/admin/tickets/<?= (int) $ticket['id'] ?>/assign">
          <?= $this->csrf() ?>

          <div class="field">
            <label class="field__label" for="assigned_to">Assignee</label>
            <select class="select" name="assigned_to" id="assigned_to">
              <option value="0">Unassigned</option>
              <?php foreach ($agents as $agent): ?>
                <option value="<?= (int) $agent['id'] ?>" <?= (int) ($ticket['assigned_to'] ?? 0) === (int) $agent['id'] ? 'selected' : '' ?>>
                  <?= $this->e($agent['name']) ?> — <?= $this->e($agent['role_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="field__label" for="priority">Priority</label>
            <select class="select" name="priority" id="priority">
              <?php foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $value => $label): ?>
                <option value="<?= $this->e($value) ?>" <?= $ticket['priority'] === $value ? 'selected' : '' ?>><?= $this->e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="btn btn--outline btn--block">Update routing</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($otherTickets !== []): ?>
    <div class="card">
      <div class="card__head"><h3 class="h3">Other tickets</h3></div>
      <div class="card__body col gap-3">
        <?php foreach ($otherTickets as $other): ?>
          <a href="/admin/tickets/<?= (int) $other['id'] ?>" class="row row--between gap-2">
            <div class="flex-1 truncate">
              <div class="small mono text-primary"><?= $this->e($other['code']) ?></div>
              <div class="tiny muted truncate"><?= $this->e($other['subject']) ?></div>
            </div>
            <span class="chip chip--neutral" style="font-size:10px"><?= $this->e($other['status']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php $this->stop(); ?>

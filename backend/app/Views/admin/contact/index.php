<?php

declare(strict_types=1);

/**
 * Contact form inbox.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $messages
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

?>

<div class="chip-row mb-5">
  <?php foreach ([
      '' => 'All', 'new' => 'New', 'read' => 'Read',
      'replied' => 'Replied', 'archived' => 'Archived', 'spam' => 'Spam',
  ] as $value => $label): ?>
    <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= (string) ($filters['status'] ?? '') === (string) $value ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search by name, email or subject…',
  ]); ?>

  <?php if ($messages === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('inbox', 'ic ic--lg') ?></div>
      <p class="empty__title">Inbox empty</p>
      <p class="empty__text">Enquiries from the contact form appear here.</p>
    </div>
  <?php else: ?>
    <div class="col gap-0">
      <?php foreach ($messages as $message): ?>
        <div style="padding:20px;border-bottom:1px solid var(--outline-variant)">
          <div class="row row--between row--wrap gap-3 mb-2">
            <div class="row gap-3">
              <div class="avatar avatar--fallback"><?= $this->e($this->initials((string) $message['name'])) ?></div>
              <div>
                <div class="small" style="font-weight:600"><?= $this->e($message['name']) ?></div>
                <div class="tiny muted">
                  <a href="mailto:<?= $this->e($message['email']) ?>" class="text-secondary"><?= $this->e($message['email']) ?></a>
                  <?php if (!empty($message['phone'])): ?> · <?= $this->e($message['phone']) ?><?php endif; ?>
                </div>
              </div>
            </div>

            <div class="row gap-2">
              <span class="chip chip--<?= match ((string) $message['status']) {
                  'new'      => 'warning',
                  'replied'  => 'success',
                  'spam'     => 'error',
                  default    => 'neutral',
              } ?>"><?= $this->e(ucfirst((string) $message['status'])) ?></span>
              <span class="tiny faint"><?= $this->timeAgo($message['created_at']) ?></span>
            </div>
          </div>

          <div class="small" style="font-weight:600;margin-bottom:4px"><?= $this->e($message['subject']) ?></div>
          <p class="small muted" style="line-height:1.6;white-space:pre-wrap"><?= $this->e($message['message']) ?></p>

          <?php if (!empty($message['reply_body'])): ?>
            <div style="margin-top:12px;padding:12px 14px;background:var(--surface-low);border-radius:var(--r-md)">
              <div class="label mb-1">Your reply · <?= $this->date($message['replied_at']) ?></div>
              <p class="tiny" style="white-space:pre-wrap"><?= $this->e($message['reply_body']) ?></p>
            </div>
          <?php endif; ?>

          <?php if ($message['status'] !== 'replied' && $message['status'] !== 'spam'): ?>
            <details style="margin-top:12px">
              <summary class="small text-secondary" style="cursor:pointer;font-weight:600">Reply</summary>

              <form method="post" action="/admin/contact/<?= (int) $message['id'] ?>/reply" class="mt-3">
                <?= $this->csrf() ?>
                <div class="field">
                  <textarea class="textarea" name="reply" rows="4" required minlength="10" maxlength="5000"
                            placeholder="Your reply is emailed to <?= $this->e($message['email']) ?>…"></textarea>
                </div>
                <div class="row gap-2">
                  <button type="submit" class="btn btn--primary btn--sm">
                    <?= $this->icon('inbox', 'ic ic--sm') ?> Send reply
                  </button>

                  <?php foreach (['archived' => 'Archive', 'spam' => 'Mark spam'] as $value => $label): ?>
                    <button type="submit" class="btn btn--ghost btn--sm"
                            formaction="/admin/contact/<?= (int) $message['id'] ?>/status"
                            formnovalidate
                            name="status" value="<?= $this->e($value) ?>"><?= $this->e($label) ?></button>
                  <?php endforeach; ?>
                </div>
              </form>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  <?php endif; ?>
</div>

<?php $this->stop(); ?>

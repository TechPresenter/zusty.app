<?php

declare(strict_types=1);

/**
 * Global search results, grouped by module.
 *
 * @var \Zuste\Core\View $this
 * @var string $term
 * @var list<array{key:string,label:string,icon:string,index:string,count:int,capped:bool,results:list<array<string,string>>}> $groups
 * @var int $total
 * @var list<array{key:string,label:string,icon:string,fields:string}> $sources
 * @var int $minLength
 * @var int $perSource
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$termLength = mb_strlen(trim($term));
$tooShort   = $termLength > 0 && $termLength < $minLength;

// Each source returns at most $perSource rows, so $total counts what is on the
// screen — never claim it is the number of records that matched.
$perSource = (int) ($perSource ?? 8);
$anyCapped = false;

foreach ($groups as $group) {
    if (!empty($group['capped'])) {
        $anyCapped = true;
        break;
    }
}

?>

<div class="card mb-5">
  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Name, email, phone, booking code, ticket code, slug…',
  ]); ?>

  <?php if ($term !== '' && !$tooShort): ?>
    <div class="card__body" style="padding-top:0">
      <p class="small muted">
        <?php if ($total === 0): ?>
          Nothing matched <strong><?= $this->e($term) ?></strong>.
        <?php else: ?>
          Showing <strong><?= $this->number($total) ?></strong> result<?= $total === 1 ? '' : 's' ?>
          for <strong><?= $this->e($term) ?></strong>
          across <?= count($groups) ?> module<?= count($groups) === 1 ? '' : 's' ?>.
          <?php if ($anyCapped): ?>
            Each module lists its first <?= $perSource ?>; open one to see every match.
          <?php endif; ?>
        <?php endif; ?>
      </p>
    </div>
  <?php endif; ?>
</div>

<?php if ($sources === []): ?>

  <div class="callout callout--warning">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>There is nothing you can search yet.</strong>
      Search covers users, creators, bookings, tickets and content, and each one
      needs its own permission. Ask an administrator to grant at least one.
    </div>
  </div>

<?php elseif ($term === ''): ?>

  <div class="card">
    <div class="empty">
      <div class="empty__art"><?= $this->icon('search', 'ic ic--lg') ?></div>
      <p class="empty__title">Search across the panel</p>
      <p class="empty__text">
        Type at least <?= (int) $minLength ?> characters. Results only ever include
        records you already have permission to open.
      </p>
    </div>
  </div>

<?php elseif ($tooShort): ?>

  <div class="card">
    <div class="empty">
      <div class="empty__art"><?= $this->icon('search', 'ic ic--lg') ?></div>
      <p class="empty__title">Keep typing</p>
      <p class="empty__text">A search needs at least <?= (int) $minLength ?> characters.</p>
    </div>
  </div>

<?php elseif ($groups === []): ?>

  <div class="card">
    <div class="empty">
      <div class="empty__art"><?= $this->icon('inbox', 'ic ic--lg') ?></div>
      <p class="empty__title">No matches</p>
      <p class="empty__text">
        Check the spelling, or try part of an email address, a booking code such as
        ZB100001, or a slug.
      </p>
    </div>
  </div>

<?php else: ?>

  <?php foreach ($groups as $group): ?>
    <div class="card mb-4">
      <div class="card__head">
        <h2 class="h3 row gap-2">
          <?= $this->icon($group['icon'], 'ic ic--sm') ?>
          <?= $this->e($group['label']) ?>
          <span class="chip chip--neutral"
                title="<?= empty($group['capped'])
                    ? 'Every match in this module'
                    : 'The first ' . $perSource . ' matches — open the module for the rest' ?>">
            <?= (int) $group['count'] ?><?= empty($group['capped']) ? '' : '+' ?>
          </span>
        </h2>
        <a href="<?= $this->e($group['index']) ?>" class="btn btn--ghost btn--sm">
          Open in <?= $this->e(strtolower($group['label'])) ?>
          <?= $this->icon('chevron-right', 'ic ic--sm') ?>
        </a>
      </div>

      <div class="table-wrap">
        <table class="table">
          <tbody>
            <?php foreach ($group['results'] as $result): ?>
              <tr>
                <td>
                  <div class="cell-user">
                    <div>
                      <div class="cell-user__name"><?= $this->e($result['label']) ?></div>
                      <div class="cell-user__meta truncate" style="max-width:520px">
                        <?= $this->e($result['sublabel']) ?>
                      </div>
                    </div>
                  </div>
                </td>

                <td style="width:140px">
                  <?php if (($result['chip'] ?? '') !== ''): ?>
                    <span class="chip chip--<?= $this->e($result['tone']) ?>">
                      <span class="chip__dot"></span><?= $this->e($result['chip']) ?>
                    </span>
                  <?php endif; ?>
                </td>

                <td class="actions" style="width:110px">
                  <a href="<?= $this->e($result['url']) ?>" class="btn btn--outline btn--sm">
                    Open <?= $this->icon('chevron-right', 'ic ic--sm') ?>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>

<?php endif; ?>

<?php if ($sources !== []): ?>
  <div class="card">
    <div class="card__head"><h2 class="h3">What this searches</h2></div>
    <div class="card__body">
      <p class="small muted mb-3">
        Only the sources you hold a permission for are queried. Everything else is
        skipped before the query runs, so a result you may not open never leaves the database.
      </p>
      <div class="chip-row">
        <?php foreach ($sources as $source): ?>
          <span class="chip chip--neutral" title="<?= $this->e($source['fields']) ?>">
            <?= $this->icon($source['icon'], 'ic ic--sm') ?>
            <?= $this->e($source['label']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
(function () {
  // Put the caret in the search box on load so the screen is usable immediately.
  const box = document.querySelector('.toolbar input[type="search"]');
  if (box) {
    const value = box.value;
    box.focus();
    box.setSelectionRange(value.length, value.length);
  }
})();
</script>
<?php $this->stop(); ?>

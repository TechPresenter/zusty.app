<?php

declare(strict_types=1);

/**
 * A single KYC submission: every field, every document with its version
 * history and per-document verdict, the full timeline, the review decisions,
 * the re-upload request, and — separately — the blue tick.
 *
 * @var \Zuste\Core\View $this
 * @var array<string,mixed> $profile
 * @var array<string,mixed> $presented
 * @var array<string,mixed> $user
 * @var array<string,mixed> $details
 * @var bool $unmasked
 * @var array<string,array<string,mixed>> $slots
 * @var list<array<string,mixed>> $otherDocuments
 * @var list<array<string,mixed>> $documents
 * @var list<array<string,mixed>> $requirements
 * @var list<string> $blockers
 * @var list<array<string,mixed>> $timeline
 * @var list<array<string,mixed>> $notes
 * @var array<string,mixed> $badge
 * @var int $linkTtl
 * @var bool $draftLocked   Never submitted: status and checklist only.
 * @var bool $aadhaarOnFile A current Aadhaar copy needs the masking check to approve.
 * @var array<string,string> $releasable  Identifiers this profile holds, field => label.
 * @var list<array<string,mixed>> $conflicts Other verifications holding the same numbers.
 * @var array<string,mixed> $admin  Shared by the shell; names the viewer in the watermark.
 */

$this->extend('admin.layouts.app');
$this->start('content');

$draftLocked   = (bool) ($draftLocked ?? false);
$aadhaarOnFile = (bool) ($aadhaarOnFile ?? false);
$releasable    = is_array($releasable ?? null) ? $releasable : [];
$conflicts     = is_array($conflicts ?? null) ? $conflicts : [];

$isBrand    = $profile['kind'] === 'brand';
$status     = (string) $profile['status'];
$reviewable = in_array($status, ['submitted', 'under_review', 'info_required'], true);
$isPurged   = !empty($profile['purged_at']);
$canApprove = in_array($status, ['submitted', 'under_review', 'suspended'], true);

/**
 * Render a label/value pair, skipping anything the user left blank.
 *
 * Declared as a closure inside the view so it inherits `$this` (the View) and
 * can use the same escaping helpers as the surrounding markup.
 */
$field = function (string $label, mixed $value, bool $mono = false, string $hint = ''): void {
    $text = is_array($value) ? implode(', ', $value) : (string) ($value ?? '');
    if (trim($text) === '') {
        return;
    }
    ?>
    <div class="kv">
      <dt class="tiny muted"><?= $this->e($label) ?></dt>
      <dd class="small<?= $mono ? ' mono' : '' ?>"><?= $this->e($text) ?><?php if ($hint !== ''): ?>
        <span class="tiny muted"> · <?= $this->e($hint) ?></span><?php endif; ?></dd>
    </div>
    <?php
};

/** A status chip for a document or slot verdict. */
$verdictChip = function (string $verdict): string {
    [$tone, $label] = match ($verdict) {
        'approved' => ['success', 'Approved'],
        'rejected' => ['error', 'Rejected'],
        'missing'  => ['neutral', 'Missing'],
        default    => ['info', 'Pending review'],
    };

    return '<span class="chip chip--sm chip--' . $tone . '">' . $this->e($label) . '</span>';
};

/** Only http(s) links are ever rendered as links — anything else is shown as text. */
$isWebUrl = static fn (string $url): bool => preg_match('#^https?://#i', trim($url)) === 1;

$badgeStatus = (string) ($badge['status'] ?? 'none');

?>

<div class="row gap-3 mb-4" style="align-items:flex-start;flex-wrap:wrap">
  <a href="/admin/kyc" class="btn btn--sm btn--ghost"><?= $this->icon('arrow-left', 'ic ic--sm') ?> Back to queue</a>

  <span class="chip chip--<?= $this->e((string) $presented['status_tone']) ?>">
    <span class="chip__dot"></span><?= $this->e((string) $presented['status_label']) ?>
  </span>

  <span class="chip chip--neutral mono"><?= $this->e((string) $profile['reference']) ?></span>

  <?php if ($badgeStatus === 'granted'): ?>
    <span class="chip chip--success"><?= $this->icon('verified', 'ic ic--sm') ?> Blue tick</span>
  <?php elseif ($status === 'approved'): ?>
    <span class="chip chip--warning">Approved — blue tick not granted</span>
  <?php endif; ?>

  <?php if (!empty($profile['expires_on'])): ?>
    <span class="chip chip--<?= strtotime((string) $profile['expires_on']) < time() ? 'error' : 'neutral' ?>">
      Expires <?= $this->date($profile['expires_on']) ?>
    </span>
  <?php endif; ?>

  <?php if (!empty($profile['consent_at'])): ?>
    <span class="chip chip--neutral" title="Consent wording version <?= $this->e((string) ($profile['consent_version'] ?? '')) ?>">
      Consent <?= $this->date($profile['consent_at']) ?> (v<?= $this->e((string) ($profile['consent_version'] ?? '?')) ?>)
    </span>
  <?php endif; ?>
</div>

<?php if ($isPurged): ?>
  <div class="alert alert--warning mb-4">
    <strong class="small">Identity data erased.</strong>
    <span class="small">Documents and identifiers for this profile were deleted on <?= $this->dateTime($profile['purged_at']) ?>.
      The reference, status and audit trail are kept as the record.</span>
  </div>
<?php endif; ?>

<?php if ($draftLocked): ?>
  <div class="alert alert--info mb-4">
    <strong class="small">Not submitted yet.</strong>
    <span class="small">The applicant is still preparing this verification and has not put it forward for review.
      Its identity details and documents stay closed until they submit it — only its status and checklist are shown here.</span>
  </div>
<?php endif; ?>

<div class="grid grid--2" style="align-items:flex-start;gap:20px">

  <!-- ============================ LEFT ============================ -->
  <div class="stack gap-4">

    <!-- Applicant -->
    <div class="card">
      <div class="card__head"><h3 class="card__title">Applicant</h3></div>
      <div class="card__body">
        <div class="cell-user mb-3">
          <?php if (!empty($user['avatar_url'])): ?>
            <img class="avatar" src="<?= $this->e($user['avatar_url']) ?>" alt="" loading="lazy">
          <?php else: ?>
            <div class="avatar avatar--fallback"><?= $this->e($this->initials((string) ($user['name'] ?? '?'))) ?></div>
          <?php endif; ?>
          <div class="flex-1 truncate">
            <div class="cell-user__name">
              <a href="/admin/users/<?= (int) ($user['id'] ?? 0) ?>"><?= $this->e($user['name'] ?? 'Unknown') ?></a>
            </div>
            <div class="cell-user__meta"><?= $this->e($user['email'] ?? '') ?></div>
          </div>
        </div>
        <dl class="kv-grid">
          <?php
            $field('Account type', ucfirst((string) ($user['account_type'] ?? '')));
            $field('Phone', $user['phone'] ?? '');
            $field('Member since', $this->date($user['created_at'] ?? null));
            $field('Attempts', (string) $profile['submission_count']);
            $field('Submitted', $profile['submitted_at'] ? $this->dateTime($profile['submitted_at']) : '');
            $field('Completeness', $profile['completeness'] . '%');
          ?>
        </dl>
      </div>
    </div>

    <!-- Details -->
    <div class="card">
      <div class="card__head">
        <h3 class="card__title"><?= $isBrand ? 'Business details' : 'Identity details' ?></h3>
        <span class="tiny muted">
          <?= match (true) {
                $draftLocked          => 'Closed until the applicant submits',
                (bool) ($unmasked ?? false) => 'PAN / GSTIN / CIN shown in full for review — this view is logged',
                default               => 'Identity numbers masked — needs the "Download KYC documents" permission',
              } ?>
        </span>
      </div>
      <div class="card__body">
        <?php if ($draftLocked): ?>
          <p class="small muted">Not shown: this verification has not been submitted for review yet.</p>
        <?php elseif ($details === []): ?>
          <p class="small muted">
            <?= $isPurged ? 'Erased with the rest of this profile\'s identity data.' : 'The applicant has not filled in this section yet.' ?>
          </p>
        <?php else: ?>
          <dl class="kv-grid">
            <?php if ($isBrand): ?>
              <?php
                $field('Company name', $details['company_name'] ?? '');
                $field('Legal name', $details['legal_name'] ?? '');
                $field('Industry', $details['industry'] ?? '');
                $field(
                    'Company type',
                    ucwords(str_replace('_', ' ', (string) ($details['company_type'] ?? ''))),
                    false,
                    !empty($details['cin_required']) ? 'CIN required' : 'no CIN required'
                );
                $field('Year established', $details['year_established'] ?? '');
                $field('Company size', $details['company_size'] ?? '');
                $field('GSTIN', $details['gst_number'] ?? '', true);
                $field('CIN', $details['cin_number'] ?? '', true);
                $field('Company PAN', $details['pan_number'] ?? '', true);
                $field('Business email', $details['business_email'] ?? '');
                $field('Business phone', $details['business_phone'] ?? '');
                $field('Registered address', $details['registered_address'] ?? '');
                $field('Office address', $details['office_address'] ?? '');
                $field('City', $details['city'] ?? '');
                $field('State', $details['state'] ?? '');
                $field('PIN code', $details['pin_code'] ?? '');
                $field('Country', $details['country'] ?? '');
                $field('Contact person', $details['contact_name'] ?? '');
                $field('Designation', $details['contact_designation'] ?? '');
                $field('Contact email', $details['contact_email'] ?? '');
                $field('Contact phone', $details['contact_phone'] ?? '');
                $field('Alternate contact', $details['alt_contact_name'] ?? '');
                $field('Alternate email', $details['alt_contact_email'] ?? '');
                $field('Alternate phone', $details['alt_contact_phone'] ?? '');
              ?>
            <?php else: ?>
              <?php
                $field('Full name', $details['full_name'] ?? '');
                $field('Display name', $details['display_name'] ?? '');
                $field('Gender', ucwords(str_replace('_', ' ', (string) ($details['gender'] ?? ''))));
                $field('Date of birth', $details['date_of_birth'] ? $this->date($details['date_of_birth']) : '');
                // Always masked: only the last four digits are stored at all.
                // The card image is another matter — it must be a masked copy,
                // which the approve form asks the reviewer to confirm.
                $field('Aadhaar', $details['aadhaar_masked'] ?? '', true, 'only the last 4 digits are stored; the card copy must be masked too');
                $field('PAN', $details['pan_number'] ?? '', true);
                $field('Other ID type', ucwords(str_replace('_', ' ', (string) ($details['id_proof_type'] ?? ''))));
                $field('Other ID number', $details['id_proof_number'] ?? '', true);
                $field('Address', $details['address'] ?? '');
                $field('City', $details['city'] ?? '');
                $field('State', $details['state'] ?? '');
                $field('Country', $details['country'] ?? '');
                $field('Languages', $details['languages'] ?? []);
                $field('Bank account name', $details['bank_account_name'] ?? '');
                $field('Bank', $details['bank_name'] ?? '');
                $field('Account number', $details['bank_account_no'] ?? '', true);
                $field('IFSC', $details['bank_ifsc'] ?? '', true);
                $field('UPI ID', $details['upi_id'] ?? '', true);
              ?>
            <?php endif; ?>
          </dl>

          <?php
            // Web addresses are links only when they are http(s); anything else
            // (a stored `javascript:` URL from before validation existed) is
            // shown as inert text.
            $links = [];
            foreach (['website' => 'Website', 'portfolio_url' => 'Portfolio'] as $key => $label) {
                if (!empty($details[$key])) {
                    $links[$label] = (string) $details[$key];
                }
            }
            if (!empty($details['social_links']) && is_array($details['social_links'])) {
                foreach ($details['social_links'] as $network => $url) {
                    if (is_string($url) && trim($url) !== '') {
                        $links[ucfirst((string) $network)] = $url;
                    }
                }
            }
          ?>

          <?php if ($links !== []): ?>
            <div class="mt-3">
              <div class="tiny muted mb-1">Web &amp; social</div>
              <div class="row gap-2" style="flex-wrap:wrap">
                <?php foreach ($links as $label => $url): ?>
                  <?php if ($isWebUrl($url)): ?>
                    <a class="chip chip--neutral" href="<?= $this->e($url) ?>"
                       target="_blank" rel="noopener noreferrer nofollow"><?= $this->e($label) ?></a>
                  <?php else: ?>
                    <span class="chip chip--warning" title="Not a web address — not linked">
                      <?= $this->e($label) ?>: <?= $this->e(mb_substr($url, 0, 60)) ?>
                    </span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($details['description'])): ?>
            <div class="mt-3">
              <div class="tiny muted mb-1">Description</div>
              <p class="small"><?= $this->e($details['description']) ?></p>
            </div>
          <?php endif; ?>

          <?php if (!empty($details['bio'])): ?>
            <div class="mt-3">
              <div class="tiny muted mb-1">Bio</div>
              <p class="small"><?= $this->e($details['bio']) ?></p>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($this->can('kyc.review') && !$draftLocked && !$isPurged && ($releasable !== [] || $conflicts !== [])): ?>
      <!-- Identifiers: uniqueness across accounts, and the release action -->
      <div class="card">
        <div class="card__head">
          <h3 class="card__title">Identifiers</h3>
          <span class="tiny muted">Each Aadhaar, PAN, GSTIN and CIN can back only one account</span>
        </div>
        <div class="card__body">
          <?php if ($conflicts !== []): ?>
            <div class="alert alert--warning mb-3">
              <strong class="small">Also claimed by another account</strong>
              <?php foreach ($conflicts as $conflict): ?>
                <p class="small">
                  <?= $this->e((string) $conflict['label']) ?>:
                  <a href="/admin/kyc/<?= (int) $conflict['id'] ?>" class="mono"><?= $this->e((string) $conflict['reference']) ?></a>
                  <span class="chip chip--sm chip--<?= $this->e((string) $conflict['status_tone']) ?>"><?= $this->e((string) $conflict['status_label']) ?></span>
                </p>
              <?php endforeach; ?>
              <p class="tiny muted mt-1">Check which account the documents support, then release the number from the one that should not hold it.</p>
            </div>
          <?php else: ?>
            <p class="small muted mb-3">No other account holds these numbers.</p>
          <?php endif; ?>

          <?php if ($releasable !== []): ?>
            <form method="post" action="/admin/kyc/<?= (int) $profile['id'] ?>/identifiers/release" id="kyc-release-form">
              <?= $this->csrf() ?>
              <div class="row gap-3" style="flex-wrap:wrap;align-items:flex-end">
                <label class="field" style="flex:0 0 140px">
                  <span class="field__label">Identifier</span>
                  <select name="field" class="input" required>
                    <?php foreach ($releasable as $value => $label): ?>
                      <option value="<?= $this->e((string) $value) ?>"><?= $this->e((string) $label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="field" style="flex:1 1 220px">
                  <span class="field__label">Reason (kept in the audit trail)</span>
                  <input type="text" name="reason" class="input" required minlength="3" maxlength="500"
                         placeholder="e.g. GSTIN belongs to another company — confirmed against its certificate">
                </label>
              </div>
              <button type="submit" class="btn btn--sm btn--danger mt-2"
                      data-confirm="The number is removed from this verification so its rightful holder can use it. If this verification still needs it, the applicant has to enter it again."
                      data-confirm-title="Release this identifier?" data-confirm-label="Release">
                Release identifier
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Documents, slot by slot -->
    <div class="card">
      <div class="card__head">
        <h3 class="card__title">Documents</h3>
        <span class="tiny muted"><?= count($documents) ?> current</span>
      </div>
      <div class="card__body stack gap-3">
        <?php if ($draftLocked): ?>
          <p class="tiny muted">The checklist shows what has been uploaded so far. Files open once the applicant submits this verification.</p>
        <?php elseif (!$this->can('kyc.download')): ?>
          <p class="tiny muted">You can see what was uploaded, but opening documents — and seeing the applicant's file names — needs the "Download KYC documents" permission.</p>
        <?php endif; ?>

        <?php $emptyOptional = []; ?>
        <?php foreach ($slots as $slotKey => $slot): ?>
          <?php
            $requirement = $slot['requirement'];
            $isRequired  = (int) $requirement['is_required'] === 1;

            if (!$isRequired && $slot['current'] === [] && $slot['versions'] === []) {
                $emptyOptional[] = (string) $requirement['label'];
                continue;
            }
          ?>
          <div style="border:1px solid var(--outline-variant);border-radius:12px;padding:12px">
            <div class="row gap-2 mb-2" style="flex-wrap:wrap;align-items:center">
              <strong class="small"><?= $this->e((string) $requirement['label']) ?></strong>
              <span class="chip chip--sm chip--<?= $isRequired ? 'primary' : 'neutral' ?>"><?= $isRequired ? 'Required' : 'Optional' ?></span>
              <?= $verdictChip((string) $slot['review_status']) ?>
              <?php if ($slot['resubmit_requested']): ?>
                <span class="chip chip--sm chip--warning">Re-upload requested</span>
              <?php endif; ?>
            </div>

            <?php if ($slot['current'] === []): ?>
              <p class="tiny muted">Nothing uploaded.</p>
            <?php endif; ?>

            <?php foreach ($slot['current'] as $document): ?>
              <div class="row gap-2" style="align-items:flex-start;flex-wrap:wrap">
                <span class="text-secondary"><?= $this->icon($document['is_pdf'] ? 'file' : 'image', 'ic ic--sm') ?></span>
                <div class="flex-1 truncate" style="min-width:160px">
                  <?php // The applicant's file name only reaches admins who may open the file. ?>
                  <?php $documentName = (string) ($document['original_name'] ?? $document['title'] ?? $requirement['label'] ?? 'Document'); ?>
                  <div class="small truncate" title="<?= $this->e($documentName) ?>">
                    <?= $this->e($documentName) ?>
                  </div>
                  <div class="tiny muted">
                    <?= $this->e((string) $document['size_label']) ?>
                    · v<?= (int) $document['version'] ?>
                    · <?= $this->dateTime($document['uploaded_at']) ?>
                    <?php if (!empty($document['expires_on'])): ?>
                      · expires <?= $this->date($document['expires_on']) ?>
                    <?php endif; ?>
                  </div>
                  <div class="row gap-2 mt-1" style="align-items:center">
                    <?= $verdictChip((string) $document['status']) ?>
                    <?php if (!empty($document['is_expired'])): ?>
                      <span class="chip chip--sm chip--error">Expired</span>
                    <?php endif; ?>
                  </div>
                  <?php if ($document['status'] === 'rejected' && !empty($document['rejection_reason'])): ?>
                    <div class="tiny text-error mt-1"><?= $this->e((string) $document['rejection_reason']) ?></div>
                  <?php endif; ?>
                </div>

                <div class="row gap-2" style="flex-wrap:wrap">
                  <?php if ($this->can('kyc.download') && !empty($document['preview_url'])): ?>
                    <button type="button" class="btn btn--sm btn--secondary"
                            onclick="KycDoc.preview(<?= (int) $document['id'] ?>, <?= $this->json($document['mime_type']) ?>, <?= $this->json($requirement['label'] . ' · v' . $document['version']) ?>)">
                      <?= $this->icon('eye', 'ic ic--sm') ?> Preview
                    </button>
                    <button type="button" class="btn btn--sm btn--ghost" onclick="KycDoc.download(<?= (int) $document['id'] ?>)">
                      <?= $this->icon('download', 'ic ic--sm') ?> Download
                    </button>
                  <?php endif; ?>

                  <?php if ($this->can('kyc.review') && $reviewable): ?>
                    <?php if ($document['status'] !== 'approved'): ?>
                      <form method="post" action="/admin/kyc/documents/<?= (int) $document['id'] ?>/review" style="display:inline">
                        <?= $this->csrf() ?>
                        <input type="hidden" name="decision" value="approve">
                        <button type="submit" class="btn btn--sm btn--success"><?= $this->icon('check', 'ic ic--sm') ?> Approve</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($document['status'] !== 'rejected'): ?>
                      <button type="button" class="btn btn--sm btn--danger"
                              onclick="KycDoc.reject(<?= (int) $document['id'] ?>, <?= $this->json($requirement['label']) ?>)">
                        <?= $this->icon('x', 'ic ic--sm') ?> Reject
                      </button>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>

            <?php if ($slot['versions'] !== []): ?>
              <details class="mt-2">
                <summary class="tiny muted" style="cursor:pointer">
                  <?= count($slot['versions']) ?> earlier version<?= count($slot['versions']) === 1 ? '' : 's' ?>
                </summary>
                <ul class="stack gap-1 mt-2" style="list-style:none;padding:0">
                  <?php foreach ($slot['versions'] as $version): ?>
                    <li class="row gap-2 tiny" style="align-items:center;flex-wrap:wrap">
                      <span class="mono">v<?= (int) $version['version'] ?></span>
                      <span class="muted"><?= $this->dateTime($version['uploaded_at']) ?></span>
                      <?= $verdictChip((string) $version['status']) ?>
                      <?php if (!empty($version['purged_at'])): ?>
                        <span class="chip chip--sm chip--neutral">File deleted <?= $this->date($version['purged_at']) ?></span>
                      <?php elseif (!empty($version['deleted_at'])): ?>
                        <span class="chip chip--sm chip--neutral">Removed by applicant</span>
                      <?php else: ?>
                        <span class="chip chip--sm chip--neutral">Replaced</span>
                      <?php endif; ?>
                      <?php if (!empty($version['rejection_reason'])): ?>
                        <span class="text-error"><?= $this->e((string) $version['rejection_reason']) ?></span>
                      <?php endif; ?>
                      <?php if ($this->can('kyc.download') && !empty($version['preview_url'])): ?>
                        <button type="button" class="btn btn--sm btn--ghost"
                                onclick="KycDoc.preview(<?= (int) $version['id'] ?>, <?= $this->json($version['mime_type']) ?>, <?= $this->json($requirement['label'] . ' · v' . $version['version']) ?>)">
                          Preview
                        </button>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if ($otherDocuments !== []): ?>
          <div style="border:1px dashed var(--outline-variant);border-radius:12px;padding:12px">
            <strong class="small">Other documents</strong>
            <p class="tiny muted mb-2">In slots that are no longer part of the checklist.</p>
            <?php foreach ($otherDocuments as $document): ?>
              <div class="row gap-2 tiny" style="align-items:center;flex-wrap:wrap">
                <span><?= $this->e((string) ($document['title'] ?? $document['slot'])) ?> · v<?= (int) $document['version'] ?></span>
                <?= $verdictChip((string) $document['status']) ?>
                <?php if ($this->can('kyc.download') && !empty($document['preview_url'])): ?>
                  <button type="button" class="btn btn--sm btn--ghost"
                          onclick="KycDoc.preview(<?= (int) $document['id'] ?>, <?= $this->json($document['mime_type']) ?>, <?= $this->json((string) ($document['title'] ?? 'Document')) ?>)">
                    Preview
                  </button>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($emptyOptional !== []): ?>
          <p class="tiny muted">Optional, not provided: <?= $this->e(implode(', ', $emptyOptional)) ?>.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ============================ RIGHT ============================ -->
  <div class="stack gap-4">

    <!-- Decision -->
    <?php if ($this->can('kyc.review') && !$isPurged): ?>
      <div class="card">
        <div class="card__head"><h3 class="card__title">KYC decision</h3></div>
        <div class="card__body">
          <?php if (!empty($profile['info_requested'])): ?>
            <div class="alert alert--info mb-3">
              <div class="tiny muted mb-1">Currently waiting on the applicant</div>
              <p class="small"><?= $this->e((string) $profile['info_requested']) ?></p>
            </div>
          <?php endif; ?>

          <?php if (!empty($profile['rejection_reason']) && in_array($status, ['rejected', 'suspended'], true)): ?>
            <div class="alert alert--error mb-3">
              <div class="tiny muted mb-1"><?= $status === 'suspended' ? 'Suspended' : 'Rejected' ?> because</div>
              <p class="small"><?= $this->e((string) $profile['rejection_reason']) ?></p>
            </div>
          <?php endif; ?>

          <?php if ($blockers !== [] && in_array($status, ['submitted', 'under_review', 'suspended'], true)): ?>
            <div class="alert alert--warning mb-3">
              <strong class="small">Approval is blocked</strong>
              <?php foreach ($blockers as $blocker): ?>
                <p class="small"><?= $this->e($blocker) ?></p>
              <?php endforeach; ?>
              <p class="tiny muted mt-1">Every required document needs a current upload that is not rejected, and the details must validate. Ask for a re-upload below, or reject with remarks.</p>
            </div>
          <?php endif; ?>

          <form method="post" action="/admin/kyc/<?= (int) $profile['id'] ?>/review" id="kyc-decision-form">
            <?= $this->csrf() ?>
            <input type="hidden" name="action" id="kyc-action">

            <label class="field">
              <span class="field__label">Remarks to the applicant</span>
              <textarea name="reason" id="kyc-reason" rows="4" class="input"
                        placeholder="Required for every decision except approval. This text is sent to the user."></textarea>
            </label>

            <?php if ($aadhaarOnFile && $canApprove): ?>
              <?php // Only a masked Aadhaar copy may be kept. Nothing but the
                    // reviewer's own eyes can check that, so approval needs
                    // their confirmation, which is recorded with the decision. ?>
              <label class="row gap-2 small mt-3" style="align-items:flex-start">
                <input type="checkbox" name="aadhaar_masked" value="1" id="kyc-aadhaar-masked" style="margin-top:3px">
                <span>I have opened the Aadhaar copy and it is masked: the first 8 digits are hidden and only the last 4 can be read.</span>
              </label>
              <p class="tiny muted mt-1">Required to approve. If the copy shows the full number, reject that document and ask for a masked Aadhaar.</p>
            <?php endif; ?>

            <div class="stack gap-2 mt-3">
              <?php if ($canApprove): ?>
                <button type="button" class="btn btn--success" onclick="KycDecide.go('approve')"
                  <?= $blockers !== [] ? 'disabled title="' . $this->e(implode(' ', $blockers)) . '"' : '' ?>>
                  <?= $this->icon('check', 'ic ic--sm') ?> Approve KYC
                </button>
              <?php endif; ?>
              <?php if ($status === 'suspended'): ?>
                <?php // A suspension is not a dead end: lift it for a fresh review. ?>
                <button type="button" class="btn btn--secondary" onclick="KycDecide.go('reinstate')">
                  <?= $this->icon('refresh', 'ic ic--sm') ?> Reinstate for review
                </button>
              <?php endif; ?>
              <?php if (in_array($status, ['submitted', 'under_review', 'suspended'], true)): ?>
                <button type="button" class="btn btn--secondary" onclick="KycDecide.go('request_info')">
                  <?= $this->icon('help-circle', 'ic ic--sm') ?> Request more information
                </button>
                <button type="button" class="btn btn--danger" onclick="KycDecide.go('reject')">
                  <?= $this->icon('x', 'ic ic--sm') ?> Reject
                </button>
              <?php endif; ?>
              <?php if ($status === 'approved'): ?>
                <button type="button" class="btn btn--ghost" onclick="KycDecide.go('suspend')">
                  Suspend verification
                </button>
              <?php endif; ?>
              <?php if (!in_array($status, ['submitted', 'under_review', 'suspended', 'approved'], true)): ?>
                <p class="tiny muted">Nothing to decide while the profile is <?= $this->e(strtolower((string) $presented['status_label'])) ?> — it is with the applicant.</p>
              <?php endif; ?>
            </div>
          </form>
          <p class="tiny muted mt-3">Approving the KYC does not give the blue tick — that is a separate decision below.</p>
        </div>
      </div>

      <?php if (in_array($status, ['submitted', 'under_review', 'suspended'], true)): ?>
        <!-- Request re-submission -->
        <div class="card">
          <div class="card__head"><h3 class="card__title">Request re-upload</h3></div>
          <div class="card__body">
            <form method="post" action="/admin/kyc/<?= (int) $profile['id'] ?>/resubmission" id="kyc-resubmit-form">
              <?= $this->csrf() ?>
              <p class="tiny muted mb-2">Choose the documents the applicant must upload again. The profile goes back to them as "Action needed".</p>
              <div class="stack gap-1 mb-3">
                <?php foreach ($slots as $slotKey => $slot): ?>
                  <label class="row gap-2 small">
                    <input type="checkbox" name="slots[]" value="<?= $this->e((string) $slotKey) ?>"
                      <?= $slot['review_status'] === 'rejected' ? 'checked' : '' ?>>
                    <?= $this->e((string) $slot['requirement']['label']) ?>
                    <?php if ($slot['review_status'] === 'rejected'): ?>
                      <span class="chip chip--sm chip--error">Rejected</span>
                    <?php endif; ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <label class="field">
                <span class="field__label">Message (optional)</span>
                <textarea name="message" rows="2" class="input" maxlength="800"
                          placeholder="e.g. The photo is blurred — upload a clear scan with all four corners visible."></textarea>
              </label>
              <button type="submit" class="btn btn--secondary mt-2"
                      data-confirm="The applicant will be asked to re-upload the selected documents."
                      data-confirm-title="Request re-upload?" data-confirm-label="Send request" data-confirm-tone="info">
                <?= $this->icon('upload', 'ic ic--sm') ?> Request re-upload
              </button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Blue tick: a separate, manual decision -->
    <div class="card">
      <div class="card__head">
        <h3 class="card__title">Blue tick</h3>
        <span class="tiny muted">Public verified badge — granted manually</span>
      </div>
      <div class="card__body">
        <?php if ($badgeStatus === 'granted'): ?>
          <p class="small"><?= $this->icon('verified', 'ic ic--sm') ?> Granted
            <?= !empty($badge['granted_at']) ? $this->dateTime($badge['granted_at']) : '' ?>
            <?= !empty($badge['granted_by']) ? ' by ' . $this->e((string) $badge['granted_by']) : '' ?>.</p>
        <?php elseif ($badgeStatus === 'revoked'): ?>
          <p class="small">Revoked <?= !empty($badge['revoked_at']) ? $this->dateTime($badge['revoked_at']) : '' ?>.</p>
          <?php if (!empty($badge['revoke_reason'])): ?>
            <p class="tiny muted"><?= $this->e((string) $badge['revoke_reason']) ?></p>
          <?php endif; ?>
        <?php else: ?>
          <p class="small">Not granted.</p>
        <?php endif; ?>

        <?php if ($badgeStatus !== 'granted' && !empty($badge['visible'])): ?>
          <p class="tiny text-warning mt-1">A tick from before this badge model is still showing on the account. Revoke it, or grant it properly once the KYC is approved.</p>
        <?php endif; ?>

        <?php if ($this->can('kyc.badge') && !$isPurged): ?>
          <?php if ($badgeStatus !== 'granted'): ?>
            <?php if (!empty($badge['can_grant'])): ?>
              <form method="post" action="/admin/kyc/<?= (int) $profile['id'] ?>/badge/grant" class="mt-3">
                <?= $this->csrf() ?>
                <label class="field">
                  <span class="field__label">Note (optional, internal)</span>
                  <input type="text" name="note" class="input" maxlength="500" placeholder="Why this account is being verified publicly">
                </label>
                <button type="submit" class="btn btn--primary mt-2"
                        data-confirm="The verified tick will show on this <?= $isBrand ? 'brand' : 'creator' ?> everywhere in the app."
                        data-confirm-title="Grant the blue tick?" data-confirm-label="Grant" data-confirm-tone="info">
                  <?= $this->icon('verified', 'ic ic--sm') ?> Grant blue tick
                </button>
              </form>
            <?php else: ?>
              <p class="tiny muted mt-2">Available once the KYC is approved, and only while that approval is in date.</p>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($badgeStatus === 'granted' || !empty($badge['visible'])): ?>
            <form method="post" action="/admin/kyc/<?= (int) $profile['id'] ?>/badge/revoke" class="mt-3" id="kyc-revoke-form">
              <?= $this->csrf() ?>
              <label class="field">
                <span class="field__label">Reason for removing it (sent to the user)</span>
                <input type="text" name="reason" class="input" required minlength="3" maxlength="500">
              </label>
              <button type="submit" class="btn btn--danger mt-2"
                      data-confirm="The verified tick will disappear from this account immediately."
                      data-confirm-title="Revoke the blue tick?" data-confirm-label="Revoke">
                Revoke blue tick
              </button>
            </form>
          <?php endif; ?>
        <?php elseif (!$this->can('kyc.badge')): ?>
          <p class="tiny muted mt-2">Granting or revoking needs the "Grant or revoke the blue tick" permission.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Internal notes -->
    <div class="card">
      <div class="card__head">
        <h3 class="card__title">Internal notes</h3>
        <span class="tiny muted">Never shown to the applicant</span>
      </div>
      <div class="card__body">
        <?php if ($this->can('kyc.review')): ?>
          <form method="post" action="/admin/kyc/<?= (int) $profile['id'] ?>/notes" class="mb-3">
            <?= $this->csrf() ?>
            <label class="field">
              <textarea name="body" rows="2" class="input" required minlength="2"
                        placeholder="Add a note for the next reviewer…"></textarea>
            </label>
            <div class="row gap-2 mt-2">
              <label class="row gap-2 tiny">
                <input type="checkbox" name="is_pinned" value="1"> Pin
              </label>
              <button type="submit" class="btn btn--sm btn--secondary" style="margin-left:auto">Add note</button>
            </div>
          </form>
        <?php endif; ?>

        <?php if ($notes === []): ?>
          <p class="small muted">No notes yet.</p>
        <?php else: ?>
          <div class="stack gap-2">
            <?php foreach ($notes as $note): ?>
              <div class="card" style="border:1px solid var(--outline-variant);background:var(--surface-container)">
                <div class="card__body" style="padding:10px 12px">
                  <div class="row gap-2 tiny muted mb-1">
                    <?php if ((int) $note['is_pinned'] === 1): ?>
                      <span class="chip chip--warning chip--sm">Pinned</span>
                    <?php endif; ?>
                    <span><?= $this->e($note['admin_name'] ?? 'Admin') ?></span>
                    <span style="margin-left:auto"><?= $this->timeAgo($note['created_at']) ?></span>
                  </div>
                  <p class="small"><?= nl2br($this->e((string) $note['body'])) ?></p>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Timeline -->
    <div class="card">
      <div class="card__head">
        <h3 class="card__title">Audit trail</h3>
        <span class="tiny muted">Append-only</span>
      </div>
      <div class="card__body">
        <?php if ($timeline === []): ?>
          <p class="small muted">No activity recorded.</p>
        <?php else: ?>
          <ol class="timeline">
            <?php foreach ($timeline as $event): ?>
              <li class="timeline__item">
                <div class="timeline__dot"></div>
                <div class="flex-1">
                  <div class="small"><strong><?= $this->e((string) $event['label']) ?></strong>
                    <?php if (!empty($event['from_status']) && !empty($event['to_status'])): ?>
                      <span class="tiny muted mono"><?= $this->e((string) $event['from_status']) ?> → <?= $this->e((string) $event['to_status']) ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($event['note'])): ?>
                    <div class="tiny muted clamp-2"><?= $this->e((string) $event['note']) ?></div>
                  <?php endif; ?>
                  <div class="tiny faint">
                    <?= $this->e(ucfirst((string) $event['actor_type'])) ?>
                    <?php if (!empty($event['actor_name'])): ?>
                      · <?= $this->e((string) $event['actor_name']) ?>
                    <?php endif; ?>
                    · <?= $this->dateTime($event['created_at']) ?>
                    <?php if (!empty($event['ip'])): ?>
                      · <span class="mono"><?= $this->e((string) $event['ip']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!--
  Document viewer.

  Uses the panel's .dialog-backdrop / .dialog component. The file is loaded
  from the admin preview route (inline, frameable by this origin only, never
  cached) with a link minted when the viewer opens — the ones in the page
  expire after <?= (int) $linkTtl ?> seconds. A watermark naming the viewing
  admin and the time sits over the document, so a screenshot carries its
  provenance.
-->
<div id="kyc-preview" class="dialog-backdrop" style="display:none" role="dialog" aria-modal="true"
     aria-labelledby="kyc-preview-title" onclick="KycDoc.backdrop(event)">
  <div class="dialog dialog--wide" style="width:min(960px,94vw)">
    <div class="dialog__head row gap-3" style="align-items:center">
      <h3 id="kyc-preview-title" class="card__title flex-1 truncate">Document</h3>
      <button type="button" class="btn btn--sm btn--ghost" id="kyc-preview-download">Download</button>
      <button type="button" class="btn btn--sm btn--ghost" onclick="KycDoc.close()">Close</button>
    </div>
    <div class="dialog__body" style="position:relative;min-height:60vh;overflow:hidden">
      <div id="kyc-preview-body"></div>
      <div id="kyc-preview-watermark" aria-hidden="true"
           style="position:absolute;inset:0;pointer-events:none;user-select:none;overflow:hidden;
                  display:flex;flex-wrap:wrap;align-content:space-around;justify-content:space-around;
                  transform:rotate(-24deg) scale(1.4);opacity:.16;font:600 15px/1.2 system-ui;color:#B80049"></div>
    </div>
  </div>
</div>

<form id="kyc-doc-reject-form" method="post" style="display:none">
  <?= $this->csrf() ?>
  <input type="hidden" name="decision" value="reject">
  <input type="hidden" name="reason" id="kyc-doc-reject-reason">
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
/**
 * In-page document viewer.
 *
 * Each open asks the server for a fresh signed link (GET .../link) rather than
 * reusing one baked into the page, so a reviewer who has had the page open for
 * an hour still gets a working preview. Nothing is cached in the DOM after
 * the viewer closes.
 */
const KycDoc = {
  // Raw JSON, not $this->json(): that one is HTML-escaped for attributes, and
  // in a script block its &quot; entities are a syntax error that took the
  // whole block — the viewer and every decision button — down with it. The
  // HEX flags keep a name containing a closing tag or quotes inert here.
  watermarkBase: <?= json_encode(
      'Confidential · ' . (string) ((is_array($admin ?? null) ? ($admin['name'] ?? null) : null) ?? 'Admin'),
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
  ) ?>,
  currentId: null,

  async link(id) {
    const payload = await Admin.request('/admin/kyc/documents/' + id + '/link');
    return payload.data;
  },

  stamp() {
    const now = new Date();
    const text = this.watermarkBase + ' · ' + now.toLocaleString();
    const layer = document.getElementById('kyc-preview-watermark');
    layer.innerHTML = '';
    for (let i = 0; i < 24; i++) {
      const span = document.createElement('span');
      span.textContent = text;
      span.style.cssText = 'padding:28px 36px;white-space:nowrap';
      layer.appendChild(span);
    }
  },

  async preview(id, mime, title) {
    const body = document.getElementById('kyc-preview-body');
    document.getElementById('kyc-preview-title').textContent = title || 'Document';
    body.innerHTML = '<p class="small muted" style="padding:24px">Loading…</p>';
    this.currentId = id;
    this.stamp();

    document.getElementById('kyc-preview').style.display = '';
    document.addEventListener('keydown', KycDoc._esc);

    let link;
    try {
      link = await this.link(id);
    } catch (error) {
      body.innerHTML = '';
      Admin.toast(error.message || 'Could not open that document.', 'error');
      this.close();
      return;
    }

    body.innerHTML = '';

    if (String(mime).startsWith('image/')) {
      const img = document.createElement('img');
      img.src = link.preview_url;
      img.alt = title || 'Document preview';
      img.draggable = false;
      img.style.cssText = 'max-width:100%;max-height:76vh;display:block;margin:0 auto';
      img.addEventListener('contextmenu', (e) => e.preventDefault());
      body.appendChild(img);
    } else if (mime === 'application/pdf') {
      const frame = document.createElement('iframe');
      frame.src = link.preview_url;
      frame.title = title || 'Document preview';
      frame.style.cssText = 'width:100%;height:76vh;border:0';
      body.appendChild(frame);
    } else {
      // Office formats have no reliable in-browser viewer; offer the download.
      const note = document.createElement('div');
      note.className = 'empty';
      note.innerHTML = '<p class="empty__title">No inline preview</p>' +
        '<p class="empty__text">This format cannot be previewed in the browser. Use Download to open it.</p>';
      body.appendChild(note);
    }
  },

  async download(id) {
    try {
      const link = await this.link(id || this.currentId);
      window.location.href = link.download_url;
    } catch (error) {
      Admin.toast(error.message || 'Could not download that document.', 'error');
    }
  },

  reject(id, label) {
    const reason = window.prompt('Why is the ' + label + ' being rejected?\nThe applicant sees this message.');
    if (reason === null) { return; }
    if (reason.trim().length < 5) {
      Admin.toast('Give a clear reason so the applicant knows what to fix.', 'warning');
      return;
    }
    const form = document.getElementById('kyc-doc-reject-form');
    form.action = '/admin/kyc/documents/' + id + '/review';
    document.getElementById('kyc-doc-reject-reason').value = reason.trim();
    form.submit();
  },

  /** Dismiss only when the click landed on the backdrop, not inside the panel. */
  backdrop(event) {
    if (event.target === event.currentTarget) { KycDoc.close(); }
  },

  close() {
    document.getElementById('kyc-preview').style.display = 'none';
    // Clearing the body stops a PDF or image continuing to load in background.
    document.getElementById('kyc-preview-body').innerHTML = '';
    document.removeEventListener('keydown', KycDoc._esc);
    this.currentId = null;
  },

  _esc(event) {
    if (event.key === 'Escape') { KycDoc.close(); }
  },
};

document.getElementById('kyc-preview-download').addEventListener('click', () => KycDoc.download());

/** Guards the decisions so none is submitted without what it needs. */
const KycDecide = {
  go(action) {
    const reason = document.getElementById('kyc-reason');
    const text = reason.value.trim();

    if (action !== 'approve' && text.length < 5) {
      Admin.toast('Explain the decision — the applicant will see this message.', 'warning');
      reason.focus();
      return;
    }

    // The server refuses an approval without the masking confirmation as
    // well; this only saves the reviewer a round trip.
    const masked = document.getElementById('kyc-aadhaar-masked');
    if (action === 'approve' && masked && !masked.checked) {
      Admin.toast('Confirm the Aadhaar copy is masked before approving.', 'warning');
      masked.focus();
      return;
    }

    const prompts = {
      approve: 'Approve this KYC? Verification-gated features unlock. The blue tick is NOT granted by this — decide it separately.',
      reject: 'Reject this verification? The applicant will be asked to fix it and resubmit, and any blue tick is removed.',
      request_info: 'Send this request back to the applicant?',
      suspend: 'Suspend this verification? Gated features and the blue tick are removed until it is restored.',
      reinstate: 'Lift the suspension and put this verification back under review? The blue tick is not restored by this.',
    };

    if (!window.confirm(prompts[action])) { return; }

    document.getElementById('kyc-action').value = action;
    document.getElementById('kyc-decision-form').submit();
  },
};
</script>
<?php $this->stop(); ?>

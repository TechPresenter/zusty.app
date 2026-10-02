import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/kyc.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_card.dart';
import 'kyc_status_style.dart';

/// One document slot: what it is, where it stands, what is on file, and what
/// the user can do about it — upload, preview, replace or remove.
///
/// A slot the reviewer turned down or asked to see again is outlined and
/// carries the reviewer's reason, so the user does not have to hunt through
/// the review trail to find out what was wrong.
class KycDocumentTile extends StatelessWidget {
  const KycDocumentTile({
    super.key,
    required this.requirement,
    required this.state,
    required this.documents,
    required this.onUpload,
    required this.onReplace,
    required this.onPreview,
    required this.onRemove,
    this.rejectionReason,
    this.requirementNote,
    this.isBusy = false,
    this.canUpload = false,
    this.canRemove = false,
    this.previewingId,
  });

  final KycRequirement requirement;
  final KycSlotState state;
  final List<KycDocument> documents;

  /// The reviewer's reason, shown while the slot needs attention.
  final String? rejectionReason;

  /// Why this slot is (or is not) needed, when that depends on something the
  /// user chose — the incorporation certificate and the company type.
  final String? requirementNote;

  /// An upload into this slot is in flight.
  final bool isBusy;
  final bool canUpload;
  final bool canRemove;

  /// The document whose preview is loading.
  final int? previewingId;

  final VoidCallback onUpload;
  final void Function(KycDocument document) onReplace;
  final void Function(KycDocument document) onPreview;
  final void Function(KycDocument document) onRemove;

  /// Rejected files that carry their own reason. Each reason is shown on its
  /// file's row, in full, so in a slot with several files the user can tell
  /// which file it is about.
  int get _rejectedWithReason => documents
      .where((doc) => doc.isRejected && doc.rejectionReason != null)
      .length;

  /// The slot-level reason, when it is not already on a file's row.
  String? get _slotReason {
    final reason = rejectionReason;
    if (reason == null) return null;

    final onRow = documents.any(
      (doc) => doc.isRejected && doc.rejectionReason == reason,
    );
    return onRow ? null : reason;
  }

  @override
  Widget build(BuildContext context) {
    final (tint, ink) = StatusChip.colorsFor(state.tone);
    final attention = state.needsAttention;
    final canAdd =
        canUpload && (documents.isEmpty || requirement.allowsMultiple);

    return ZCard(
      border: attention
          ? Border.all(
              color: state == KycSlotState.rejected
                  ? AppColors.error
                  : AppColors.warning,
              width: 1.4,
            )
          : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(color: tint, shape: BoxShape.circle),
                child: Icon(state.icon, size: 20, color: ink),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(requirement.label, style: AppTypography.titleSmall),
                    const SizedBox(height: 4),
                    StatusChip(
                      label: state.label,
                      tone: state.tone,
                      dense: true,
                    ),
                    if (requirement.description.isNotEmpty) ...[
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        requirement.description,
                        style: AppTypography.bodySmall,
                      ),
                    ],
                    if (requirementNote != null) ...[
                      const SizedBox(height: 4),
                      Text(
                        requirementNote!,
                        style: AppTypography.bodySmall.copyWith(
                          color: AppColors.onSurface,
                        ),
                      ),
                    ],
                    const SizedBox(height: 4),
                    Text(
                      requirement.requiresExpiry
                          ? '${requirement.constraintLabel} · expiry date needed'
                          : requirement.constraintLabel,
                      style: AppTypography.labelSmall.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          if (attention) ...[
            const SizedBox(height: AppSpacing.sm),
            _ReviewerNote(
              state: state,
              reason: _slotReason,
              rejectedFiles: _rejectedWithReason,
              canUpload: canUpload,
            ),
          ],
          // A requirement, not advice: only a masked copy is accepted.
          if (KycSlots.isAadhaar(requirement.slot) &&
              (documents.isEmpty || attention)) ...[
            const SizedBox(height: AppSpacing.sm),
            const _MaskedAadhaarRule(),
          ],
          for (final document in documents) ...[
            const SizedBox(height: AppSpacing.sm),
            _DocumentRow(
              document: document,
              isPreviewing: previewingId == document.id,
              isBusy: isBusy,
              onPreview: document.canPreview ? () => onPreview(document) : null,
              onReplace: canUpload ? () => onReplace(document) : null,
              onRemove: canRemove ? () => onRemove(document) : null,
            ),
          ],
          if (canAdd) ...[
            const SizedBox(height: AppSpacing.sm),
            Align(
              alignment: Alignment.centerRight,
              child: ZButton(
                label: documents.isEmpty ? 'Upload' : 'Add another',
                icon: Icons.file_upload_outlined,
                variant: documents.isEmpty
                    ? ZButtonVariant.tonal
                    : ZButtonVariant.outlined,
                size: ZButtonSize.medium,
                expand: false,
                isLoading: isBusy,
                onPressed: isBusy ? null : onUpload,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// What the reviewer said about this slot, and what the user can do now.
class _ReviewerNote extends StatelessWidget {
  const _ReviewerNote({
    required this.state,
    required this.rejectedFiles,
    required this.canUpload,
    this.reason,
  });

  final KycSlotState state;

  /// The slot-level reason, when no file row already shows it.
  final String? reason;

  /// How many rejected files show their own reason below.
  final int rejectedFiles;

  /// False while the profile is with the review team: the user cannot
  /// replace anything yet, so the note must not tell them to.
  final bool canUpload;

  String get _text {
    final rejected = state == KycSlotState.rejected;

    if (reason != null) {
      final next = canUpload
          ? ''
          : ' You can replace it when our team asks for a new copy.';
      return 'Reviewer: $reason$next';
    }

    if (!rejected) return 'Our reviewer asked for this to be uploaded again.';

    final what = rejectedFiles > 1
        ? 'Our reviewer could not accept $rejectedFiles files. The reason is '
              'shown under each one.'
        : rejectedFiles == 1
        ? 'Our reviewer could not accept this file. The reason is shown '
              'under it.'
        : 'Our reviewer could not accept this file.';

    return canUpload
        ? '$what Upload a clearer copy.'
        : '$what Our team will ask you for a new copy when the review '
              'finishes.';
  }

  @override
  Widget build(BuildContext context) {
    final rejected = state == KycSlotState.rejected;
    final (tint, ink) = StatusChip.colorsFor(
      rejected ? StatusTone.error : StatusTone.warning,
    );

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(color: tint, borderRadius: AppRadius.mdAll),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            rejected ? Icons.error_outline_rounded : Icons.restart_alt_rounded,
            size: 18,
            color: ink,
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              _text,
              style: AppTypography.bodySmall.copyWith(color: ink),
            ),
          ),
        ],
      ),
    );
  }
}

/// Only a masked Aadhaar is accepted — the copy UIDAI itself issues for
/// sharing, with the first eight digits hidden. A reviewer has to confirm the
/// copy is masked before approving, so an unmasked one is sent back.
class _MaskedAadhaarRule extends StatelessWidget {
  const _MaskedAadhaarRule();

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(Icons.privacy_tip_outlined, size: 16, color: AppColors.info),
        const SizedBox(width: AppSpacing.xs),
        Expanded(
          child: Text(
            'Masked Aadhaar required: the first 8 digits must be hidden. '
            'Download a masked Aadhaar from the UIDAI website. Copies showing '
            'the full number are rejected.',
            style: AppTypography.bodySmall,
          ),
        ),
      ],
    );
  }
}

/// A file on record: what it is, how it was judged, and its actions.
class _DocumentRow extends StatelessWidget {
  const _DocumentRow({
    required this.document,
    required this.isPreviewing,
    required this.isBusy,
    this.onPreview,
    this.onReplace,
    this.onRemove,
  });

  final KycDocument document;
  final bool isPreviewing;
  final bool isBusy;
  final VoidCallback? onPreview;
  final VoidCallback? onReplace;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) {
    final meta = [
      if (document.sizeLabel.isNotEmpty) document.sizeLabel,
      if (document.version > 1) 'Version ${document.version}',
      if (kycDate(document.uploadedAt) case final date?) 'Uploaded $date',
      if (document.isExpired)
        'Expired'
      else if (document.expiresOn != null)
        'Expires ${kycDate(document.expiresOn) ?? document.expiresOn}',
    ].join(' · ');

    final verdict = switch (document.status) {
      'approved' => ('Approved', AppColors.success),
      'rejected' => ('Rejected', AppColors.error),
      _ => null,
    };

    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: AppRadius.mdAll,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Icon(
                  document.isPdf
                      ? Icons.picture_as_pdf_rounded
                      : document.isImage
                      ? Icons.image_rounded
                      : Icons.insert_drive_file_rounded,
                  size: 20,
                  color: AppColors.primary,
                ),
              ),
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      document.displayName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelMedium,
                    ),
                    if (meta.isNotEmpty)
                      Text(
                        meta,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.labelSmall.copyWith(
                          color: document.isExpired
                              ? AppColors.error
                              : AppColors.onSurfaceVariant,
                        ),
                      ),
                    if (verdict != null)
                      Text(
                        verdict.$1,
                        style: AppTypography.labelSmall.copyWith(
                          color: verdict.$2,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    // In full: the reason is the whole point of the row,
                    // and a truncated one leaves the user guessing.
                    if (document.isRejected &&
                        document.rejectionReason != null) ...[
                      const SizedBox(height: 2),
                      Text(
                        'Reason: ${document.rejectionReason}',
                        style: AppTypography.bodySmall.copyWith(
                          color: AppColors.error,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          if (onPreview != null || onReplace != null || onRemove != null) ...[
            const SizedBox(height: AppSpacing.xs),
            Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                if (onPreview != null)
                  ZButton(
                    label: 'Preview',
                    icon: Icons.visibility_outlined,
                    variant: ZButtonVariant.tonal,
                    size: ZButtonSize.medium,
                    expand: false,
                    isLoading: isPreviewing,
                    onPressed: isPreviewing ? null : onPreview,
                  ),
                if (onReplace != null)
                  ZButton(
                    label: 'Replace',
                    icon: Icons.swap_horiz_rounded,
                    variant: ZButtonVariant.outlined,
                    size: ZButtonSize.medium,
                    expand: false,
                    isLoading: isBusy,
                    onPressed: isBusy ? null : onReplace,
                  ),
                if (onRemove != null)
                  Semantics(
                    button: true,
                    label: 'Remove ${document.displayName}',
                    excludeSemantics: true,
                    child: ZIconButton(
                      icon: Icons.delete_outline_rounded,
                      size: AppSpacing.minTouch,
                      tooltip: 'Remove',
                      background: AppColors.surfaceContainerLowest,
                      foreground: AppColors.error,
                      onPressed: isBusy ? null : onRemove,
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

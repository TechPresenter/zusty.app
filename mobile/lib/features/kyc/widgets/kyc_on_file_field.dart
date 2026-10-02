import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';

/// A sensitive number the server already holds, shown only as its masked
/// rendering — "Aadhaar on file: XXXX XXXX 1234 · Change".
///
/// It is deliberately NOT a text field. The form used to prefill the masked
/// value into an editable input and send it back on save, which overwrote the
/// real number with `XXXXXX234F`. Here the stored value is read-only, and
/// "Change" swaps in an empty input for a new number.
///
/// An optional number can also be removed outright ("Remove"), which the form
/// sends as an explicit null on the next save — the only way to take a wrong
/// or no-longer-needed identifier off file, since an empty input means "keep
/// the one on file".
class KycOnFileField extends StatelessWidget {
  const KycOnFileField({
    super.key,
    required this.label,
    required this.onFileLabel,
    required this.masked,
    this.onChange,
    this.onRemove,
    this.removing = false,
    this.onUndoRemove,
    this.errorText,
  });

  /// The field's own label, e.g. "Aadhaar number".
  final String label;

  /// "Aadhaar on file".
  final String onFileLabel;

  /// "XXXX XXXX 1234".
  final String masked;

  /// Null while the form is locked.
  final VoidCallback? onChange;

  /// Null when the number cannot be removed — it is needed to submit, or the
  /// form is locked.
  final VoidCallback? onRemove;

  /// The user chose to remove the number; it goes on the next save.
  final bool removing;
  final VoidCallback? onUndoRemove;

  final String? errorText;

  @override
  Widget build(BuildContext context) {
    if (removing) return _buildRemoving();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: AppTypography.labelLarge.copyWith(fontSize: 13)),
        const SizedBox(height: AppSpacing.xs),
        Container(
          constraints: const BoxConstraints(minHeight: 52),
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.sm,
            AppSpacing.xs,
            AppSpacing.base,
            AppSpacing.xs,
          ),
          decoration: BoxDecoration(
            color: AppColors.surfaceContainerLow,
            borderRadius: AppRadius.mdAll,
            border: Border.all(
              color: errorText != null
                  ? AppColors.error
                  : AppColors.outlineVariant,
            ),
          ),
          child: Row(
            children: [
              Icon(
                Icons.lock_outline_rounded,
                size: 18,
                color: AppColors.onSurfaceVariant,
              ),
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: Semantics(
                  label: '$onFileLabel: $masked',
                  excludeSemantics: true,
                  child: Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: '$onFileLabel: ',
                          style: AppTypography.bodySmall,
                        ),
                        TextSpan(
                          text: masked,
                          style: AppTypography.bodyMedium.copyWith(
                            fontWeight: FontWeight.w600,
                            letterSpacing: 0.6,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              if (onChange != null)
                TextButton(
                  onPressed: onChange,
                  style: TextButton.styleFrom(
                    minimumSize: const Size(AppSpacing.minTouch, 44),
                    foregroundColor: AppColors.primary,
                  ),
                  child: Text(
                    'Change',
                    semanticsLabel: 'Change $label',
                    style: AppTypography.labelLarge.copyWith(
                      color: AppColors.primary,
                    ),
                  ),
                ),
              if (onRemove != null)
                TextButton(
                  onPressed: onRemove,
                  style: TextButton.styleFrom(
                    minimumSize: const Size(AppSpacing.minTouch, 44),
                    foregroundColor: AppColors.error,
                  ),
                  child: Text(
                    'Remove',
                    semanticsLabel: 'Remove $label',
                    style: AppTypography.labelLarge.copyWith(
                      color: AppColors.error,
                    ),
                  ),
                ),
            ],
          ),
        ),
        if (errorText != null) ...[
          const SizedBox(height: 4),
          Text(
            errorText!,
            style: AppTypography.bodySmall.copyWith(color: AppColors.error),
          ),
        ],
      ],
    );
  }

  Widget _buildRemoving() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: AppTypography.labelLarge.copyWith(fontSize: 13)),
        const SizedBox(height: AppSpacing.xs),
        Container(
          constraints: const BoxConstraints(minHeight: 52),
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.sm,
            AppSpacing.xs,
            AppSpacing.base,
            AppSpacing.xs,
          ),
          decoration: BoxDecoration(
            color: AppColors.errorContainer,
            borderRadius: AppRadius.mdAll,
            border: Border.all(color: AppColors.error),
          ),
          child: Row(
            children: [
              Icon(
                Icons.delete_outline_rounded,
                size: 18,
                color: AppColors.error,
              ),
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: Text(
                  '$onFileLabel will be removed when you save.',
                  style: AppTypography.bodySmall.copyWith(
                    color: AppColors.onErrorContainer,
                  ),
                ),
              ),
              if (onUndoRemove != null)
                TextButton(
                  onPressed: onUndoRemove,
                  style: TextButton.styleFrom(
                    minimumSize: const Size(AppSpacing.minTouch, 44),
                  ),
                  child: Text(
                    'Undo',
                    semanticsLabel: 'Keep $label',
                    style: AppTypography.labelLarge.copyWith(
                      color: AppColors.primary,
                    ),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

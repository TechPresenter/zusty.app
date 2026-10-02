import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';
import 'z_button.dart';

/// Asks before an action that matters (cancel a booking, withdraw, log out).
///
/// Resolves true only on an explicit confirm; dismissing the dialog is "no".
/// [destructive] paints the confirm button red, for actions that lose
/// something.
Future<bool> showZConfirm(
  BuildContext context, {
  required String title,
  String? message,
  String confirmLabel = 'Confirm',
  String cancelLabel = 'Not now',
  bool destructive = false,
  IconData? icon,
}) async {
  final result = await showDialog<bool>(
    context: context,
    animationStyle: _dialogMotion(context),
    builder: (context) => Dialog(
      insetPadding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.lg,
          AppSpacing.lg,
          AppSpacing.lg,
          AppSpacing.md,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 56,
              height: 56,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: destructive
                    ? AppColors.errorContainer
                    : AppColors.primaryFixed,
              ),
              child: Icon(
                icon ??
                    (destructive
                        ? Icons.warning_amber_rounded
                        : Icons.help_outline_rounded),
                color: destructive ? AppColors.error : AppColors.primary,
                size: 28,
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            Text(
              title,
              textAlign: TextAlign.center,
              style: AppTypography.titleMedium,
            ),
            if (message != null) ...[
              const SizedBox(height: AppSpacing.xs),
              Text(
                message,
                textAlign: TextAlign.center,
                style: AppTypography.bodyMedium
                    .copyWith(color: AppColors.onSurfaceVariant),
              ),
            ],
            const SizedBox(height: AppSpacing.lg),
            ZButton(
              label: confirmLabel,
              variant:
                  destructive ? ZButtonVariant.danger : ZButtonVariant.primary,
              onPressed: () => Navigator.of(context).pop(true),
            ),
            const SizedBox(height: AppSpacing.xs),
            ZButton(
              label: cancelLabel,
              variant: ZButtonVariant.text,
              onPressed: () => Navigator.of(context).pop(false),
            ),
          ],
        ),
      ),
    ),
  );

  return result ?? false;
}

/// A modal sheet with a title row and a close button, sized to its content
/// and lifted above the keyboard.
Future<T?> showZSheet<T>(
  BuildContext context, {
  required String title,
  required WidgetBuilder builder,
  String? subtitle,
}) {
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    sheetAnimationStyle: zSheetMotion(context),
    builder: (context) => Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.marginMobile,
          0,
          AppSpacing.marginMobile,
          AppSpacing.lg,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: AppTypography.titleMedium),
                      if (subtitle != null) ...[
                        const SizedBox(height: 2),
                        Text(subtitle, style: AppTypography.bodySmall),
                      ],
                    ],
                  ),
                ),
                IconButton(
                  onPressed: () => Navigator.of(context).maybePop(),
                  icon: const Icon(Icons.close_rounded),
                  tooltip: 'Close',
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            builder(context),
          ],
        ),
      ),
    ),
  );
}

/// Slide-up timing for modal sheets: rises on the motion system's entrance
/// curve and drops away a little quicker. Pass it as `sheetAnimationStyle` to
/// any `showModalBottomSheet`; under reduced motion the sheet just appears.
AnimationStyle zSheetMotion(BuildContext context) => Motion.reduced(context)
    ? AnimationStyle.noAnimation
    : AnimationStyle(
        duration: Motion.slow,
        reverseDuration: Motion.medium,
        curve: Motion.emphasized,
        reverseCurve: Motion.exit,
      );

AnimationStyle _dialogMotion(BuildContext context) => Motion.reduced(context)
    ? AnimationStyle.noAnimation
    : AnimationStyle(
        duration: Motion.medium,
        reverseDuration: Motion.fast,
        curve: Motion.emphasized,
        reverseCurve: Motion.exit,
      );

import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';
import '../motion/reveal.dart';
import '../motion/success_check.dart';
import 'status_chip.dart';
import 'z_button.dart';

/// The confirmation after an important action: application sent, campaign
/// published, payment held in escrow. A large check, what happened, and what
/// to do next.
class SuccessView extends StatelessWidget {
  const SuccessView({
    super.key,
    required this.title,
    this.message,
    this.primaryLabel,
    this.onPrimary,
    this.secondaryLabel,
    this.onSecondary,
    this.details,
    this.icon = Icons.check_rounded,
  });

  final String title;
  final String? message;
  final String? primaryLabel;
  final VoidCallback? onPrimary;
  final String? secondaryLabel;
  final VoidCallback? onSecondary;

  /// Optional summary under the message (a receipt card, a reference code).
  final Widget? details;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        // The pale ring settles in and the check draws itself inside it; the
        // words and actions follow a beat later.
        _RingIn(
          child: Container(
            width: 112,
            height: 112,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: AppColors.successContainer,
            ),
            child: icon == Icons.check_rounded
                // Sized so its disc matches the 76px circle it replaces.
                ? SuccessCheck(
                    size: 76 / 0.82,
                    color: AppColors.success,
                    checkColor: AppColors.onTertiary,
                  )
                : Container(
                    width: 76,
                    height: 76,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: AppColors.success,
                    ),
                    child: Icon(icon, size: 40, color: AppColors.onTertiary),
                  ),
          ),
        ),
        const SizedBox(height: AppSpacing.lg),
        Reveal(
          index: 3,
          child: Text(
            title,
            textAlign: TextAlign.center,
            style: AppTypography.headlineMedium,
          ),
        ),
        if (message != null) ...[
          const SizedBox(height: AppSpacing.xs),
          Reveal(
            index: 4,
            child: Text(
              message!,
              textAlign: TextAlign.center,
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ),
        ],
        if (details != null) ...[
          const SizedBox(height: AppSpacing.lg),
          Reveal(index: 5, child: details!),
        ],
        if (primaryLabel != null && onPrimary != null) ...[
          const SizedBox(height: AppSpacing.xl),
          Reveal(
            index: 6,
            child: ZButton(label: primaryLabel!, onPressed: onPrimary),
          ),
        ],
        if (secondaryLabel != null && onSecondary != null) ...[
          const SizedBox(height: AppSpacing.xs),
          Reveal(
            index: 6,
            child: ZButton.outlined(
              label: secondaryLabel!,
              onPressed: onSecondary,
            ),
          ),
        ],
      ],
    );
  }
}

/// An inline notice: KYC pending, payment held, application shortlisted.
/// Tinted by tone, with an optional action on the right — or under the
/// message when the label would take more than a third of the width, as a
/// long label does at 360dp with large text.
class ZInfoBanner extends StatelessWidget {
  const ZInfoBanner({
    super.key,
    required this.message,
    this.title,
    this.tone = StatusTone.info,
    this.icon,
    this.actionLabel,
    this.onAction,
  });

  final String message;
  final String? title;
  final StatusTone tone;
  final IconData? icon;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final (background, foreground) = StatusChip.colorsFor(tone);

    final glyph =
        icon ??
        switch (tone) {
          StatusTone.success => Icons.check_circle_rounded,
          StatusTone.warning => Icons.schedule_rounded,
          StatusTone.error => Icons.error_outline_rounded,
          _ => Icons.info_outline_rounded,
        };

    final hasAction = actionLabel != null && onAction != null;
    final labelStyle = AppTypography.labelLarge.copyWith(color: foreground);

    final text = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (title != null) ...[
          Text(
            title!,
            style: AppTypography.titleSmall.copyWith(color: foreground),
          ),
          const SizedBox(height: 2),
        ],
        Text(
          message,
          style: AppTypography.bodySmall.copyWith(color: foreground),
        ),
      ],
    );

    Widget action({required bool below}) => TextButton(
      onPressed: onAction,
      style: TextButton.styleFrom(
        foregroundColor: foreground,
        minimumSize: const Size(AppSpacing.minTouch, 32),
        // Under the message the label lines up with the text above it.
        padding: below
            ? const EdgeInsets.symmetric(vertical: AppSpacing.xs)
            : null,
      ),
      child: Text(actionLabel!, style: labelStyle),
    );

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: background,
        borderRadius: AppRadius.lgAll,
      ),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final below =
              hasAction &&
              _labelWidth(context, actionLabel!, labelStyle) >
                  constraints.maxWidth / 3;

          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(glyph, size: 20, color: foreground),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: below
                    ? Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [text, action(below: true)],
                      )
                    : text,
              ),
              if (hasAction && !below) ...[
                const SizedBox(width: AppSpacing.xs),
                action(below: false),
              ],
            ],
          );
        },
      ),
    );
  }

  /// The label's laid-out width plus the button's own padding, at the
  /// user's text scale.
  static double _labelWidth(
    BuildContext context,
    String label,
    TextStyle style,
  ) {
    final painter = TextPainter(
      text: TextSpan(text: label, style: style),
      textDirection: Directionality.of(context),
      textScaler: MediaQuery.textScalerOf(context),
      maxLines: 1,
    )..layout();
    final width = painter.width + 2 * AppSpacing.sm;
    painter.dispose();
    return width;
  }
}

/// Scales the success ring up from 80% as it fades in.
class _RingIn extends StatelessWidget {
  const _RingIn({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    if (Motion.reduced(context)) return child;

    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: Motion.slow,
      curve: Motion.emphasized,
      child: child,
      builder: (context, t, child) => Opacity(
        opacity: t.clamp(0.0, 1.0),
        child: Transform.scale(scale: 0.8 + 0.2 * t, child: child),
      ),
    );
  }
}

import 'package:flutter/material.dart';

import '../../core/theme/app_theme.dart';
import '../motion/hover_lift.dart';
import '../motion/pressable.dart';

/// The app's primary layout vehicle (DESIGN.md § Cards): white surface, "2xl"
/// radius, 16px padding, and a soft ambient shadow rather than an elevation.
class ZCard extends StatelessWidget {
  const ZCard({
    super.key,
    required this.child,
    this.padding = AppSpacing.card,
    this.onTap,
    this.color,
    this.borderRadius = AppRadius.xlAll,
    this.border,
    this.shadow = true,
    this.margin,
    this.width,
    this.clip = false,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;

  /// Defaults to the lowest surface tone, resolved against the active theme.
  final Color? color;
  final BorderRadius borderRadius;
  final BoxBorder? border;
  final bool shadow;
  final EdgeInsetsGeometry? margin;
  final double? width;

  /// Clips the child to the card radius — needed when the card contains media
  /// that should bleed to its edges.
  final bool clip;

  @override
  Widget build(BuildContext context) {
    Widget content = Padding(padding: padding, child: child);

    if (onTap != null) {
      content = Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: borderRadius,
          splashColor: AppColors.primary.withValues(alpha: 0.05),
          highlightColor: AppColors.primary.withValues(alpha: 0.03),
          child: content,
        ),
      );
    }

    final card = Container(
      width: width,
      clipBehavior: clip ? Clip.antiAlias : Clip.none,
      decoration: BoxDecoration(
        color: color ?? AppColors.surfaceContainerLowest,
        borderRadius: borderRadius,
        // A white card gets a hairline blush border by default, so it still
        // reads as a card where the shadow is too faint to see.
        border:
            border ??
            (color == null
                ? Border.all(color: AppColors.outlineVariant)
                : null),
        boxShadow: shadow ? AppShadows.card : null,
      ),
      child: content,
    );

    // A tappable card sinks a touch under the finger and lifts under a mouse;
    // a static one stays still.
    final interactive = onTap == null
        ? card
        : Pressable(
            pressedScale: 0.985,
            child: HoverLift(borderRadius: borderRadius, child: card),
          );

    return margin == null
        ? interactive
        : Padding(padding: margin!, child: interactive);
  }
}

/// A section title with an optional trailing "View all" action — the repeating
/// header on Home, Explore, and the profile screens.
class SectionHeader extends StatelessWidget {
  const SectionHeader({
    super.key,
    required this.title,
    this.actionLabel,
    this.onAction,
    this.subtitle,
    this.padding = EdgeInsets.zero,
  });

  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;
  final String? subtitle;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    final hasAction = actionLabel != null && onAction != null;

    // The title and its action share one centred row, so "View all" sits on
    // the title's line however tall its touch target is; the subtitle runs
    // underneath at full width.
    return Padding(
      padding: padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  title,
                  style: AppTypography.titleMedium.copyWith(fontSize: 17),
                ),
              ),
              if (hasAction)
                TextButton(
                  onPressed: onAction,
                  style: TextButton.styleFrom(
                    minimumSize: const Size(
                      AppSpacing.minTouch,
                      AppSpacing.minTouch,
                    ),
                    padding: const EdgeInsets.symmetric(
                      horizontal: AppSpacing.xs,
                    ),
                  ),
                  child: Text(
                    actionLabel!,
                    style: AppTypography.labelLarge.copyWith(
                      color: AppColors.primary,
                    ),
                  ),
                ),
            ],
          ),
          if (subtitle != null)
            Padding(
              // Pulled up under the title when the row grew to fit the
              // action's touch target.
              padding: EdgeInsets.only(top: hasAction ? 0 : 2),
              child: Transform.translate(
                offset: Offset(0, hasAction ? -8 : 0),
                child: Text(subtitle!, style: AppTypography.bodySmall),
              ),
            ),
        ],
      ),
    );
  }
}

class ZDetailRow extends StatelessWidget {
  const ZDetailRow({
    super.key,
    required this.label,
    required this.value,
    this.emphasise = false,
    this.valueColor,
    this.icon,
  });

  final String label;
  final String value;

  /// Renders the row as a total: heavier weight on both sides.
  final bool emphasise;
  final Color? valueColor;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final maxValueWidth = constraints.maxWidth * 0.6;

          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (icon != null) ...[
                Icon(icon, size: 16, color: AppColors.onSurfaceVariant),
                const SizedBox(width: AppSpacing.xs),
              ],
              Expanded(
                child: Text(
                  label,
                  style: emphasise
                      ? AppTypography.bodyMedium.copyWith(
                          fontWeight: FontWeight.w700,
                        )
                      : AppTypography.bodyMedium.copyWith(
                          color: AppColors.onSurfaceVariant,
                        ),
                ),
              ),
              const SizedBox(width: AppSpacing.md),
              // Capped rather than Flexible: a Flexible beside the Expanded label
              // took half the row and left the value floating mid-card. The cap
              // keeps it hard right, wrapping only when it is genuinely long.
              ConstrainedBox(
                constraints: BoxConstraints(maxWidth: maxValueWidth),
                child: Text(
                  value,
                  textAlign: TextAlign.right,
                  style:
                      (emphasise
                              ? AppTypography.bodyLarge.copyWith(
                                  fontWeight: FontWeight.w700,
                                )
                              : AppTypography.bodyMedium.copyWith(
                                  fontWeight: FontWeight.w600,
                                ))
                          .copyWith(color: valueColor),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

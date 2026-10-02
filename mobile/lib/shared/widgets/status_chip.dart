import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';
import '../../models/booking.dart' show StatusTone;
import '../motion/pressable.dart';

// Re-exported so any screen that shows a chip gets the tone enum with it.
export '../../models/booking.dart' show StatusTone;

/// Pill-shaped status indicator (DESIGN.md § Status Chips): a low-opacity tint
/// of the semantic colour with high-contrast text of the same hue.
class StatusChip extends StatelessWidget {
  const StatusChip({
    super.key,
    required this.label,
    this.tone = StatusTone.neutral,
    this.icon,
    this.dense = false,
  });

  final String label;
  final StatusTone tone;
  final IconData? icon;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final (background, foreground) = _colorsFor(tone);

    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: dense ? AppSpacing.xs : AppSpacing.sm,
        vertical: dense ? 3 : 6,
      ),
      decoration: BoxDecoration(
        color: background,
        borderRadius: AppRadius.pill,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: dense ? 12 : 14, color: foreground),
            const SizedBox(width: 4),
          ],
          // Flexible so a long status still fits a narrow card instead of
          // overflowing the pill.
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.labelMedium.copyWith(
                color: foreground,
                fontSize: dense ? 10 : 12,
              ),
            ),
          ),
        ],
      ),
    );
  }

  static (Color, Color) _colorsFor(StatusTone tone) => switch (tone) {
    StatusTone.success => (
      AppColors.successContainer,
      AppColors.onSuccessContainer,
    ),
    StatusTone.warning => (
      AppColors.warningContainer,
      AppColors.onWarningContainer,
    ),
    StatusTone.info => (AppColors.infoContainer, AppColors.onInfoContainer),
    StatusTone.error => (AppColors.errorContainer, AppColors.onErrorContainer),
    StatusTone.neutral => (
      AppColors.surfaceContainerHigh,
      AppColors.onSurfaceVariant,
    ),
  };

  /// The tint and readable text colour for [tone], for surfaces that are not a
  /// chip (banners, timeline dots) but should match one.
  static (Color, Color) colorsFor(StatusTone tone) => _colorsFor(tone);
}

/// A star rating with its numeric value — the pattern repeated on every
/// creator card and review.
class RatingStars extends StatelessWidget {
  const RatingStars({
    super.key,
    required this.rating,
    this.count,
    this.size = 14,
    this.showValue = true,
    this.starCount = 1,
  });

  final double rating;

  /// Review count rendered as `(120 Reviews)` when supplied.
  final int? count;
  final double size;
  final bool showValue;

  /// 1 renders a single star plus the value; 5 renders the full row.
  final int starCount;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (starCount == 1)
          Icon(Icons.star_rounded, size: size + 2, color: AppColors.star)
        else
          ...List.generate(5, (i) {
            final filled = rating >= i + 1;
            final half = !filled && rating > i;
            return Icon(
              half
                  ? Icons.star_half_rounded
                  : (filled ? Icons.star_rounded : Icons.star_outline_rounded),
              size: size + 2,
              color: filled || half ? AppColors.star : AppColors.outlineVariant,
            );
          }),
        if (showValue) ...[
          const SizedBox(width: 4),
          Text(
            rating.toStringAsFixed(1),
            style: AppTypography.labelMedium.copyWith(fontSize: size - 1),
          ),
        ],
        if (count != null) ...[
          const SizedBox(width: 4),
          Text(
            '($count Reviews)',
            style: AppTypography.bodySmall.copyWith(fontSize: size - 1),
          ),
        ],
      ],
    );
  }
}

/// A pill-shaped selectable chip, used for categories, sort options, and tags.
class ZChip extends StatelessWidget {
  const ZChip({
    super.key,
    required this.label,
    this.selected = false,
    this.onTap,
    this.icon,
    this.color,
    this.maxWidth = 260,
  });

  final String label;
  final bool selected;
  final VoidCallback? onTap;
  final IconData? icon;

  /// Overrides the unselected foreground, e.g. for the niche tags on the
  /// creator profile.
  final Color? color;

  /// Ceiling before the label ellipsizes. Needed because a [Wrap] parent gives
  /// its children unbounded width.
  final double maxWidth;

  @override
  Widget build(BuildContext context) {
    final foreground = selected
        ? AppColors.onPrimary
        : (color ?? AppColors.onSurface);
    final background = selected
        ? AppColors.primary
        : (color != null
              ? color!.withValues(alpha: 0.10)
              : AppColors.surfaceContainerLow);

    // Selecting a chip eases its colours over rather than flipping them.
    final duration = Motion.of(context, Motion.fast);

    return Pressable(
      enabled: onTap != null,
      pressedScale: 0.95,
      child: AnimatedContainer(
        duration: duration,
        curve: Motion.standard,
        decoration: BoxDecoration(
          color: background,
          borderRadius: AppRadius.pill,
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            onTap: onTap,
            borderRadius: AppRadius.pill,
            child: Padding(
              padding: const EdgeInsets.symmetric(
                horizontal: AppSpacing.md,
                vertical: AppSpacing.xs + 2,
              ),
              // A Wrap hands its children unbounded width, so a long label
              // would overflow rather than ellipsize. Cap the chip and let the
              // text shrink inside that cap.
              child: ConstrainedBox(
                constraints: BoxConstraints(maxWidth: maxWidth),
                child: TweenAnimationBuilder<Color?>(
                  tween: ColorTween(end: foreground),
                  duration: duration,
                  curve: Motion.standard,
                  builder: (context, tint, _) => Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (icon != null) ...[
                        Icon(icon, size: 15, color: tint),
                        const SizedBox(width: 6),
                      ],
                      Flexible(
                        child: Text(
                          label,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.labelMedium.copyWith(
                            color: tint,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

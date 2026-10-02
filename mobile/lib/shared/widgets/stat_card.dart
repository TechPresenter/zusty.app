import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';
import '../motion/animated_counter.dart';
import 'z_card.dart';

/// One headline figure: an icon tile, the value, and what it measures.
/// Dashboards lay these out two-up.
class StatCard extends StatelessWidget {
  const StatCard({
    super.key,
    required this.label,
    required this.value,
    this.icon,
    this.caption,
    this.tint,
    this.onTap,
  });

  final String label;
  final String value;
  final IconData? icon;

  /// A secondary line under the value, e.g. "3 awaiting review".
  final String? caption;

  /// The icon tile's hue; magenta when omitted.
  final Color? tint;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final color = tint ?? AppColors.primary;

    return ZCard(
      onTap: onTap,
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.12),
                borderRadius: AppRadius.mdAll,
              ),
              child: Icon(icon, size: 20, color: color),
            ),
            const SizedBox(height: AppSpacing.sm),
          ],
          // Counts up to the figure the first time the card shows.
          AnimatedCounter.text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.headlineSmall.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.bodySmall,
          ),
          if (caption != null) ...[
            const SizedBox(height: AppSpacing.xs),
            Text(
              caption!,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.labelSmall.copyWith(color: color),
            ),
          ],
        ],
      ),
    );
  }
}

/// Figures side by side with hairline dividers between them: the
/// "1.2M Followers | 320 Following | 1.8K Posts" strip on a profile.
class StatStrip extends StatelessWidget {
  const StatStrip({super.key, required this.items, this.dense = false});

  /// Value / label pairs, in display order.
  final List<(String, String)> items;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    return IntrinsicHeight(
      child: Row(
        children: [
          for (var i = 0; i < items.length; i++) ...[
            if (i > 0)
              VerticalDivider(
                width: 1,
                thickness: 1,
                indent: 4,
                endIndent: 4,
                color: AppColors.outlineVariant,
              ),
            Expanded(
              child: Semantics(
                label: '${items[i].$1} ${items[i].$2}',
                excludeSemantics: true,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    AnimatedCounter.text(
                      items[i].$1,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: (dense
                              ? AppTypography.titleSmall
                              : AppTypography.titleMedium)
                          .copyWith(fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      items[i].$2,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodySmall,
                    ),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// A labelled horizontal bar, for audience splits and completion meters.
class ZProgressBar extends StatelessWidget {
  const ZProgressBar({
    super.key,
    required this.label,
    required this.fraction,
    this.valueLabel,
    this.color,
  });

  final String label;

  /// 0..1; values outside are clamped.
  final double fraction;

  /// Defaults to the rounded percentage.
  final String? valueLabel;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final f = fraction.clamp(0.0, 1.0);

    return Semantics(
      label: '$label ${valueLabel ?? '${(f * 100).round()}%'}',
      excludeSemantics: true,
      child: Row(
        children: [
          SizedBox(
            width: 72,
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.bodySmall.copyWith(color: AppColors.onSurface),
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: ClipRRect(
              borderRadius: AppRadius.pill,
              // The bar fills to its value when it first shows, and glides to
              // any new value after that.
              child: TweenAnimationBuilder<double>(
                tween: Tween(begin: Motion.reduced(context) ? f : 0, end: f),
                duration: Motion.of(context, Motion.max),
                curve: Motion.standard,
                builder: (context, value, _) => LinearProgressIndicator(
                  value: value,
                  minHeight: 8,
                  color: color ?? AppColors.primary,
                  backgroundColor: AppColors.surfaceContainerHigh,
                ),
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          SizedBox(
            width: 40,
            child: Text(
              valueLabel ?? '${(f * 100).round()}%',
              textAlign: TextAlign.right,
              style: AppTypography.labelMedium,
            ),
          ),
        ],
      ),
    );
  }
}

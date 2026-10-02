import 'package:flutter/material.dart';

import '../../../core/motion/motion.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/motion/pressable.dart';

/// One side of the marketplace on the "Choose your role" step: an
/// illustration tile, the role, what it gets you, and a radio-style mark.
///
/// Large on purpose. Picking the wrong side creates the wrong kind of account
/// (a brand profile cannot take bookings, a creator one cannot post a brief),
/// so this choice gets the full width and a clear selected state rather than a
/// small toggle.
class RoleChoiceCard extends StatelessWidget {
  const RoleChoiceCard({
    super.key,
    required this.title,
    required this.benefit,
    required this.icon,
    required this.accent,
    required this.selected,
    required this.onTap,
  });

  final String title;
  final String benefit;
  final IconData icon;

  /// The small glyph orbiting the illustration tile.
  final IconData accent;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final duration = Motion.of(context, Motion.fast);

    return Semantics(
      button: true,
      selected: selected,
      inMutuallyExclusiveGroup: true,
      label: '$title. $benefit',
      onTap: onTap,
      excludeSemantics: true,
      child: Pressable(
        pressedScale: 0.985,
        child: AnimatedContainer(
          duration: duration,
          curve: Motion.standard,
          decoration: BoxDecoration(
            color: selected
                ? AppColors.primaryFixed.withValues(alpha: 0.55)
                : AppColors.surfaceContainerLowest,
            borderRadius: AppRadius.xlAll,
            border: Border.all(
              color: selected ? AppColors.primary : AppColors.outlineVariant,
              width: selected ? 2 : 1,
            ),
            boxShadow: selected ? AppShadows.card : AppShadows.subtle,
          ),
          child: Material(
            type: MaterialType.transparency,
            child: InkWell(
              onTap: onTap,
              borderRadius: AppRadius.xlAll,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Row(
                  children: [
                    _Illustration(
                      icon: icon,
                      accent: accent,
                      selected: selected,
                      duration: duration,
                    ),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(title, style: AppTypography.titleMedium),
                          const SizedBox(height: 4),
                          Text(
                            benefit,
                            style: AppTypography.bodyMedium.copyWith(
                              color: AppColors.onSurfaceVariant,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    _RadioMark(selected: selected, duration: duration),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A gradient tile with the role's glyph and a small companion badge.
class _Illustration extends StatelessWidget {
  const _Illustration({
    required this.icon,
    required this.accent,
    required this.selected,
    required this.duration,
  });

  final IconData icon;
  final IconData accent;
  final bool selected;
  final Duration duration;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 68,
      height: 68,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          AnimatedContainer(
            duration: duration,
            width: 64,
            height: 64,
            decoration: BoxDecoration(
              borderRadius: AppRadius.lgAll,
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: selected
                    ? [AppColors.primary, AppColors.primaryContainer]
                    : [
                        AppColors.surfaceContainerHigh,
                        AppColors.surfaceContainer,
                      ],
              ),
            ),
            child: Icon(
              icon,
              size: 30,
              color: selected ? AppColors.onPrimary : AppColors.primary,
            ),
          ),
          Positioned(
            right: -2,
            bottom: -2,
            child: Container(
              width: 26,
              height: 26,
              decoration: BoxDecoration(
                color: AppColors.surfaceContainerLowest,
                shape: BoxShape.circle,
                boxShadow: AppShadows.subtle,
              ),
              child: Icon(accent, size: 15, color: AppColors.secondary),
            ),
          ),
        ],
      ),
    );
  }
}

class _RadioMark extends StatelessWidget {
  const _RadioMark({required this.selected, required this.duration});

  final bool selected;
  final Duration duration;

  @override
  Widget build(BuildContext context) {
    return AnimatedContainer(
      duration: duration,
      width: 24,
      height: 24,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: selected ? AppColors.primary : Colors.transparent,
        border: Border.all(
          color: selected ? AppColors.primary : AppColors.outline,
          width: 1.6,
        ),
      ),
      child: selected
          ? Icon(Icons.check_rounded, size: 16, color: AppColors.onPrimary)
          : null,
    );
  }
}

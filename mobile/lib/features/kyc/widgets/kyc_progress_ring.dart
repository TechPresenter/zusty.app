import 'package:flutter/material.dart';

import '../../../core/motion/motion.dart';
import '../../../core/theme/app_theme.dart';

/// The verification completeness as a ring with the figure in the middle —
/// or a check once the profile is approved.
///
/// Fills to its value when it first appears and glides to any new value after
/// an upload, so progress is something the user sees happen.
class KycProgressRing extends StatelessWidget {
  const KycProgressRing({
    super.key,
    required this.percent,
    this.complete = false,
    this.size = 72,
  });

  /// 0–100; clamped.
  final int percent;

  /// Draws the success state: a full green ring and a check.
  final bool complete;
  final double size;

  @override
  Widget build(BuildContext context) {
    final value = (percent.clamp(0, 100)) / 100;
    final color = complete ? AppColors.success : AppColors.primary;

    return Semantics(
      label: complete ? 'Verified' : 'Verification $percent percent complete',
      excludeSemantics: true,
      child: SizedBox.square(
        dimension: size,
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: Motion.reduced(context) ? value : 0, end: value),
          duration: Motion.of(context, Motion.max),
          curve: Motion.standard,
          builder: (context, animated, _) => Stack(
            alignment: Alignment.center,
            children: [
              SizedBox.square(
                dimension: size,
                child: CircularProgressIndicator(
                  value: animated,
                  strokeWidth: 7,
                  strokeCap: StrokeCap.round,
                  color: color,
                  backgroundColor: AppColors.surfaceContainerLowest,
                ),
              ),
              if (complete)
                Icon(Icons.verified_rounded, size: size * 0.42, color: color)
              else
                // Scales down inside the ring at large text sizes rather than
                // spilling over the stroke.
                Padding(
                  padding: EdgeInsets.all(size * 0.18),
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      '${(animated * 100).round()}%',
                      style: AppTypography.titleMedium.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

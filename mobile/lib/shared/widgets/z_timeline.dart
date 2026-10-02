import 'package:flutter/material.dart';

import '../../core/theme/app_theme.dart';

enum TimelineState { done, current, upcoming }

/// One step in a [ZTimeline].
class TimelineStep {
  const TimelineStep({
    required this.title,
    this.subtitle,
    this.time,
    this.state = TimelineState.done,
    this.icon,
  });

  final String title;
  final String? subtitle;

  /// Shown under the subtitle, e.g. "31 Jul 2026, 5:39 PM".
  final String? time;
  final TimelineState state;

  /// Replaces the default dot glyph.
  final IconData? icon;
}

/// A vertical status timeline: a dot per step joined by a line, filled for
/// what has happened, ringed for where things stand now, hollow for what is
/// still to come.
class ZTimeline extends StatelessWidget {
  const ZTimeline({super.key, required this.steps});

  final List<TimelineStep> steps;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (var i = 0; i < steps.length; i++)
          _StepRow(
            step: steps[i],
            isLast: i == steps.length - 1,
            nextDone: i + 1 < steps.length &&
                steps[i + 1].state != TimelineState.upcoming,
          ),
      ],
    );
  }
}

class _StepRow extends StatelessWidget {
  const _StepRow({
    required this.step,
    required this.isLast,
    required this.nextDone,
  });

  final TimelineStep step;
  final bool isLast;
  final bool nextDone;

  @override
  Widget build(BuildContext context) {
    final done = step.state == TimelineState.done;
    final current = step.state == TimelineState.current;

    final dotColor = done || current ? AppColors.primary : AppColors.outlineVariant;

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 28,
            child: Column(
              children: [
                Container(
                  width: 24,
                  height: 24,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: done ? AppColors.primary : AppColors.surfaceContainerLowest,
                    border: Border.all(color: dotColor, width: current ? 6 : 2),
                  ),
                  child: done
                      ? Icon(
                          step.icon ?? Icons.check_rounded,
                          size: 14,
                          color: AppColors.onPrimary,
                        )
                      : null,
                ),
                if (!isLast)
                  Expanded(
                    child: Container(
                      width: 2,
                      margin: const EdgeInsets.symmetric(vertical: 2),
                      decoration: BoxDecoration(
                        color: nextDone
                            ? AppColors.primary.withValues(alpha: 0.5)
                            : AppColors.outlineVariant,
                        borderRadius: AppRadius.pill,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(
                top: 2,
                bottom: isLast ? 0 : AppSpacing.md,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    step.title,
                    style: AppTypography.titleSmall.copyWith(
                      color: step.state == TimelineState.upcoming
                          ? AppColors.onSurfaceVariant
                          : AppColors.onSurface,
                    ),
                  ),
                  if (step.subtitle != null && step.subtitle!.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(step.subtitle!, style: AppTypography.bodySmall),
                  ],
                  if (step.time != null && step.time!.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      step.time!,
                      style: AppTypography.labelSmall
                          .copyWith(fontWeight: FontWeight.w500),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

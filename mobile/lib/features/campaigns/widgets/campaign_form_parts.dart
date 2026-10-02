import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/motion/motion.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_card.dart';

/// The pieces the campaign wizard is built from: the step header, titled
/// form cards, and the chip pickers its targeting fields use.

// -----------------------------------------------------------------------------
// Progress
// -----------------------------------------------------------------------------

/// "Step 2 of 4" over a segmented bar with each step's name beneath.
///
/// Steps already passed are tappable, so a brand on the preview can jump
/// straight back to fix one thing; steps ahead are not, because each one
/// validates before it lets you leave.
class WizardProgress extends StatelessWidget {
  const WizardProgress({
    super.key,
    required this.steps,
    required this.current,
    this.onStepTap,
  });

  final List<String> steps;
  final int current;
  final ValueChanged<int>? onStepTap;

  @override
  Widget build(BuildContext context) {
    final duration = Motion.of(context, Motion.medium);

    return Semantics(
      label: 'Step ${current + 1} of ${steps.length}: ${steps[current]}',
      container: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ExcludeSemantics(
            child: Text(
              'Step ${current + 1} of ${steps.length}',
              style: AppTypography.labelMedium
                  .copyWith(color: AppColors.primary),
            ),
          ),
          const SizedBox(height: AppSpacing.xs),
          Row(
            children: [
              for (var i = 0; i < steps.length; i++) ...[
                if (i > 0) const SizedBox(width: 6),
                Expanded(
                  child: AnimatedContainer(
                    duration: duration,
                    curve: Motion.standard,
                    height: 4,
                    decoration: BoxDecoration(
                      color: i <= current
                          ? AppColors.primary
                          : AppColors.surfaceContainerHigh,
                      borderRadius: AppRadius.pill,
                    ),
                  ),
                ),
              ],
            ],
          ),
          Row(
            children: [
              for (var i = 0; i < steps.length; i++) ...[
                if (i > 0) const SizedBox(width: 6),
                Expanded(
                  child: _StepLabel(
                    label: steps[i],
                    index: i,
                    state: i == current
                        ? _StepState.current
                        : (i < current ? _StepState.done : _StepState.ahead),
                    onTap: i < current && onStepTap != null
                        ? () => onStepTap!(i)
                        : null,
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

enum _StepState { done, current, ahead }

class _StepLabel extends StatelessWidget {
  const _StepLabel({
    required this.label,
    required this.index,
    required this.state,
    this.onTap,
  });

  final String label;
  final int index;
  final _StepState state;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final color = switch (state) {
      _StepState.current => AppColors.onSurface,
      _StepState.done => AppColors.primary,
      _StepState.ahead => AppColors.onSurfaceVariant,
    };

    final text = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (state == _StepState.done) ...[
          Icon(Icons.check_rounded, size: 13, color: color),
          const SizedBox(width: 2),
        ],
        Flexible(
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.labelSmall.copyWith(
              color: color,
              fontWeight: state == _StepState.current
                  ? FontWeight.w700
                  : FontWeight.w500,
            ),
          ),
        ),
      ],
    );

    // The label row is the tap target, padded out to a usable height.
    return Semantics(
      button: onTap != null,
      label: onTap != null ? 'Back to $label' : null,
      excludeSemantics: onTap != null,
      onTap: onTap,
      child: InkWell(
        onTap: onTap,
        borderRadius: AppRadius.smAll,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
          child: Align(alignment: Alignment.centerLeft, child: text),
        ),
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Layout
// -----------------------------------------------------------------------------

/// A step's own heading: what this step decides, in one line.
class StepHeading extends StatelessWidget {
  const StepHeading({super.key, required this.title, this.subtitle});

  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Semantics(
            header: true,
            child: Text(title, style: AppTypography.headlineSmall),
          ),
          if (subtitle != null) ...[
            const SizedBox(height: 4),
            Text(
              subtitle!,
              style: AppTypography.bodyMedium
                  .copyWith(color: AppColors.onSurfaceVariant),
            ),
          ],
        ],
      ),
    );
  }
}

/// A titled white card grouping a few related fields.
class FormSectionCard extends StatelessWidget {
  const FormSectionCard({
    super.key,
    required this.title,
    required this.children,
    this.subtitle,
    this.trailing,
  });

  final String title;
  final String? subtitle;
  final Widget? trailing;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: ZCard(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
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
                        Text(subtitle!, style: AppTypography.bodySmall),
                      ],
                    ],
                  ),
                ),
                if (trailing != null) ...[
                  const SizedBox(width: AppSpacing.xs),
                  trailing!,
                ],
              ],
            ),
            const SizedBox(height: AppSpacing.md),
            ...children,
          ],
        ),
      ),
    );
  }
}

/// A small caption above a chip group or picker.
class FieldLabel extends StatelessWidget {
  const FieldLabel(this.text, {super.key, this.hint});

  final String text;

  /// Shown after the label in the lighter style, e.g. "optional".
  final String? hint;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Text.rich(
        TextSpan(
          text: text,
          style: AppTypography.labelLarge.copyWith(color: AppColors.onSurface),
          children: [
            if (hint != null)
              TextSpan(
                text: '  $hint',
                style: AppTypography.labelSmall.copyWith(
                  color: AppColors.onSurfaceVariant,
                  fontWeight: FontWeight.w500,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Pickers
// -----------------------------------------------------------------------------

/// Single-select chips. With [allowClear], tapping the selected chip clears
/// it (reported as an empty string).
class ChoiceChips extends StatelessWidget {
  const ChoiceChips({
    super.key,
    required this.options,
    required this.value,
    required this.onChanged,
    this.allowClear = false,
  });

  /// Value → label, in display order.
  final Map<String, String> options;
  final String? value;
  final ValueChanged<String> onChanged;
  final bool allowClear;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: AppSpacing.xs,
      runSpacing: AppSpacing.xs,
      children: [
        for (final entry in options.entries)
          Semantics(
            selected: value == entry.key,
            child: ZChip(
              label: entry.value,
              selected: value == entry.key,
              onTap: () => onChanged(
                value == entry.key && allowClear ? '' : entry.key,
              ),
            ),
          ),
      ],
    );
  }
}

/// Multi-select chips over [options], keeping any selected value that is not
/// an option (a city typed by the admin, a language the taxonomy dropped) so
/// it stays visible and removable instead of silently riding along.
class MultiChips extends StatelessWidget {
  const MultiChips({
    super.key,
    required this.options,
    required this.selected,
    required this.onToggle,
    this.labels = const {},
  });

  /// Stored values, in display order.
  final List<String> options;
  final Set<String> selected;
  final ValueChanged<String> onToggle;

  /// Display label per value, where they differ.
  final Map<String, String> labels;

  @override
  Widget build(BuildContext context) {
    final all = [
      ...options,
      ...selected.where((value) => !options.contains(value)),
    ];

    return Wrap(
      spacing: AppSpacing.xs,
      runSpacing: AppSpacing.xs,
      children: [
        for (final value in all)
          Semantics(
            selected: selected.contains(value),
            child: ZChip(
              label: labels[value] ?? value,
              selected: selected.contains(value),
              icon: selected.contains(value) ? Icons.check_rounded : null,
              onTap: () => onToggle(value),
            ),
          ),
      ],
    );
  }
}

/// A − n + stepper for small counts.
class CountStepper extends StatelessWidget {
  const CountStepper({
    super.key,
    required this.value,
    required this.onChanged,
    this.min = 1,
    this.max = 20,
    this.unit = 'item',
  });

  final int value;
  final ValueChanged<int> onChanged;
  final int min;
  final int max;

  /// Spoken by screen readers: "2 reels".
  final String unit;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: '$value $unit${value == 1 ? '' : 's'} per creator',
      container: true,
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.surfaceContainerLow,
          borderRadius: AppRadius.pill,
          border: Border.all(color: AppColors.outlineVariant),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            IconButton(
              icon: const Icon(Icons.remove_rounded, size: 20),
              tooltip: 'Fewer',
              color: AppColors.primary,
              onPressed: value > min ? () => onChanged(value - 1) : null,
            ),
            SizedBox(
              width: 32,
              child: ExcludeSemantics(
                child: Text(
                  '$value',
                  textAlign: TextAlign.center,
                  style: AppTypography.titleMedium,
                ),
              ),
            ),
            IconButton(
              icon: const Icon(Icons.add_rounded, size: 20),
              tooltip: 'More',
              color: AppColors.primary,
              onPressed: value < max ? () => onChanged(value + 1) : null,
            ),
          ],
        ),
      ),
    );
  }
}

/// A tappable field-shaped row that opens a picker: the campaign window.
class PickerField extends StatelessWidget {
  const PickerField({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.value,
    this.placeholder = 'Select',
    this.onClear,
  });

  final IconData icon;
  final String label;
  final String? value;
  final String placeholder;
  final VoidCallback onTap;

  /// Shown as a clear button when a value is set.
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) {
    final hasValue = value != null && value!.isNotEmpty;

    return Semantics(
      button: true,
      label: '$label: ${hasValue ? value : placeholder}',
      excludeSemantics: true,
      onTap: onTap,
      child: Material(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.mdAll,
        child: InkWell(
          onTap: onTap,
          borderRadius: AppRadius.mdAll,
          child: Container(
            constraints: const BoxConstraints(minHeight: 56),
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.md,
              vertical: AppSpacing.xs,
            ),
            decoration: BoxDecoration(
              borderRadius: AppRadius.mdAll,
              border: Border.all(color: AppColors.outlineVariant),
            ),
            child: Row(
              children: [
                Icon(icon, size: 20, color: AppColors.primary),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(label, style: AppTypography.labelSmall),
                      const SizedBox(height: 2),
                      Text(
                        hasValue ? value! : placeholder,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodyMedium.copyWith(
                          color: hasValue
                              ? AppColors.onSurface
                              : AppColors.outline,
                          fontWeight: hasValue ? FontWeight.w600 : null,
                        ),
                      ),
                    ],
                  ),
                ),
                if (hasValue && onClear != null)
                  IconButton(
                    icon: const Icon(Icons.close_rounded, size: 18),
                    tooltip: 'Clear $label',
                    onPressed: onClear,
                  )
                else
                  Icon(
                    Icons.chevron_right_rounded,
                    size: 22,
                    color: AppColors.outline,
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A two-column summary row on the preview: label left, value right.
class SummaryRow extends StatelessWidget {
  const SummaryRow({super.key, required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 104,
            child: Text(
              label,
              style: AppTypography.bodySmall,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              value,
              style: AppTypography.bodyMedium.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

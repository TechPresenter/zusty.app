import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_calendar.dart';
import '../../../shared/widgets/z_dialogs.dart';

/// Picks a campaign's window on the app's own calendar, in a sheet, rather
/// than the full-screen Material range picker the form used to open.
///
/// The first tap sets the start, the second the end; tapping before the
/// start moves the start. A one-day campaign is a start with no second tap.
Future<DateTimeRange?> showCampaignDatesSheet(
  BuildContext context, {
  DateTimeRange? initial,
}) {
  return showZSheet<DateTimeRange>(
    context,
    title: 'Campaign window',
    subtitle: 'When creators should post. The last day is also the '
        'application deadline creators see.',
    builder: (_) => _DatesPicker(initial: initial),
  );
}

class _DatesPicker extends StatefulWidget {
  const _DatesPicker({this.initial});

  final DateTimeRange? initial;

  @override
  State<_DatesPicker> createState() => _DatesPickerState();
}

class _DatesPickerState extends State<_DatesPicker> {
  late DateTime? _start = _dateOnly(widget.initial?.start);
  late DateTime? _end = _dateOnly(widget.initial?.end);
  late DateTime _month = _start ?? _today;

  static DateTime get _today {
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  static DateTime? _dateOnly(DateTime? value) =>
      value == null ? null : DateTime(value.year, value.month, value.day);

  /// An existing brief can already have started; its window must still show
  /// and stay editable, so the floor is whichever is earlier.
  DateTime get _firstDate {
    final initial = _dateOnly(widget.initial?.start);
    return initial != null && initial.isBefore(_today) ? initial : _today;
  }

  void _onDay(DateTime day) {
    setState(() {
      if (_start == null || _end != null) {
        _start = day;
        _end = null;
      } else if (day.isBefore(_start!)) {
        _start = day;
      } else {
        _end = day;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            Expanded(
              child: _Endpoint(
                label: 'Starts',
                value: _start,
                active: _start == null || _end != null,
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
              child: Icon(
                Icons.arrow_forward_rounded,
                size: 18,
                color: AppColors.outline,
              ),
            ),
            Expanded(
              child: _Endpoint(
                label: 'Ends',
                value: _end ?? _start,
                active: _start != null && _end == null,
              ),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.md),
        ZCalendar(
          month: _month,
          onMonthChanged: (month) => setState(() => _month = month),
          selection: CalendarSelection.range,
          rangeStart: _start,
          rangeEnd: _end ?? _start,
          firstDate: _firstDate,
          lastDate: _today.add(const Duration(days: 365)),
          onDayTap: _onDay,
        ),
        const SizedBox(height: AppSpacing.lg),
        ZButton(
          label: _start == null ? 'Pick a start date' : 'Use these dates',
          onPressed: _start == null
              ? null
              : () => Navigator.of(context).pop(
                    DateTimeRange(start: _start!, end: _end ?? _start!),
                  ),
        ),
      ],
    );
  }
}

class _Endpoint extends StatelessWidget {
  const _Endpoint({
    required this.label,
    required this.value,
    required this.active,
  });

  final String label;
  final DateTime? value;

  /// The end the next tap will set.
  final bool active;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: '$label ${value == null ? 'not set' : Formatters.date(value)}',
      excludeSemantics: true,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.sm,
          vertical: AppSpacing.xs,
        ),
        decoration: BoxDecoration(
          color: active ? AppColors.primaryFixed : AppColors.surfaceContainerLow,
          borderRadius: AppRadius.mdAll,
          border: Border.all(
            color: active
                ? AppColors.primary.withValues(alpha: 0.4)
                : AppColors.outlineVariant,
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: AppTypography.labelSmall),
            const SizedBox(height: 2),
            Text(
              value == null ? 'Select' : Formatters.date(value),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.labelLarge.copyWith(
                color: value == null ? AppColors.outline : AppColors.onSurface,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

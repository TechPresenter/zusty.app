import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/theme/app_theme.dart';

/// How a single date should be painted.
enum DayState {
  /// Open for booking.
  available,

  /// Explicitly blocked by the creator.
  blocked,

  /// Open but already at its booking limit.
  booked,

  /// Before today, or outside the allowed range.
  disabled,

  /// No availability record — neutral.
  neutral,
}

/// A month grid styled from the design tokens.
///
/// Deliberately dependency-free: the layout, tone mapping, and selection model
/// all come from the Zusty design system rather than a third-party calendar, so
/// it matches the rest of the app exactly.
///
/// Supports three selection modes via [selection]:
///  * [CalendarSelection.single] — one date (booking start),
///  * [CalendarSelection.range] — a start/end pair (campaign window),
///  * [CalendarSelection.multi] — any set of dates (creator availability).
class ZCalendar extends StatelessWidget {
  const ZCalendar({
    super.key,
    required this.month,
    required this.onMonthChanged,
    this.selection = CalendarSelection.single,
    this.selected = const {},
    this.rangeStart,
    this.rangeEnd,
    this.onDayTap,
    this.stateFor,
    this.firstDate,
    this.lastDate,
    this.badgeFor,
    this.showHeader = true,
  });

  /// Any date inside the month being displayed.
  final DateTime month;
  final ValueChanged<DateTime> onMonthChanged;

  final CalendarSelection selection;

  /// Selected dates for [CalendarSelection.multi] and `single`.
  final Set<DateTime> selected;

  final DateTime? rangeStart;
  final DateTime? rangeEnd;

  final ValueChanged<DateTime>? onDayTap;

  /// Classifies a date so it can be tinted; defaults to [DayState.neutral].
  final DayState Function(DateTime date)? stateFor;

  final DateTime? firstDate;
  final DateTime? lastDate;

  /// Optional tiny caption under a day, e.g. a booking count.
  final String? Function(DateTime date)? badgeFor;

  final bool showHeader;

  static final _weekdayLabels = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

  DateTime get _firstOfMonth => DateTime(month.year, month.month);

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        if (showHeader) ...[
          _Header(
            month: _firstOfMonth,
            onPrevious: _canGoBack
                ? () => onMonthChanged(
                    DateTime(month.year, month.month - 1))
                : null,
            onNext: _canGoForward
                ? () => onMonthChanged(
                    DateTime(month.year, month.month + 1))
                : null,
          ),
          const SizedBox(height: AppSpacing.sm),
        ],
        Row(
          children: [
            for (final label in _weekdayLabels)
              Expanded(
                child: Center(
                  child: Text(
                    label,
                    style: AppTypography.labelSmall
                        .copyWith(color: AppColors.outline),
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: AppSpacing.xs),
        _Grid(
          month: _firstOfMonth,
          selection: selection,
          selected: selected,
          rangeStart: rangeStart,
          rangeEnd: rangeEnd,
          onDayTap: onDayTap,
          stateFor: stateFor,
          firstDate: firstDate,
          lastDate: lastDate,
          badgeFor: badgeFor,
        ),
      ],
    );
  }

  bool get _canGoBack {
    final limit = firstDate;
    if (limit == null) return true;
    final previous = DateTime(month.year, month.month - 1, 1);
    return !previous.isBefore(DateTime(limit.year, limit.month, 1));
  }

  bool get _canGoForward {
    final limit = lastDate;
    if (limit == null) return true;
    final next = DateTime(month.year, month.month + 1, 1);
    return !next.isAfter(DateTime(limit.year, limit.month, 1));
  }
}

enum CalendarSelection { single, range, multi }

class _Header extends StatelessWidget {
  const _Header({
    required this.month,
    required this.onPrevious,
    required this.onNext,
  });

  final DateTime month;
  final VoidCallback? onPrevious;
  final VoidCallback? onNext;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        _NavButton(
          icon: Icons.chevron_left_rounded,
          onTap: onPrevious,
          tooltip: 'Previous month',
        ),
        Expanded(
          child: Center(
            child: Text(
              DateFormat('MMMM yyyy').format(month),
              style: AppTypography.bodyLarge
                  .copyWith(fontWeight: FontWeight.w700),
            ),
          ),
        ),
        _NavButton(
          icon: Icons.chevron_right_rounded,
          onTap: onNext,
          tooltip: 'Next month',
        ),
      ],
    );
  }
}

class _NavButton extends StatelessWidget {
  const _NavButton({required this.icon, this.onTap, this.tooltip});

  final IconData icon;
  final VoidCallback? onTap;
  final String? tooltip;

  @override
  Widget build(BuildContext context) {
    final enabled = onTap != null;
    return Tooltip(
      message: tooltip ?? '',
      child: Material(
        color: enabled
            ? AppColors.surfaceContainerHigh
            : AppColors.surfaceContainerLow,
        shape: const CircleBorder(),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: SizedBox(
            width: 36,
            height: 36,
            child: Icon(
              icon,
              size: 20,
              color: enabled ? AppColors.onSurface : AppColors.outlineVariant,
            ),
          ),
        ),
      ),
    );
  }
}

class _Grid extends StatelessWidget {
  const _Grid({
    required this.month,
    required this.selection,
    required this.selected,
    required this.rangeStart,
    required this.rangeEnd,
    required this.onDayTap,
    required this.stateFor,
    required this.firstDate,
    required this.lastDate,
    required this.badgeFor,
  });

  final DateTime month;
  final CalendarSelection selection;
  final Set<DateTime> selected;
  final DateTime? rangeStart;
  final DateTime? rangeEnd;
  final ValueChanged<DateTime>? onDayTap;
  final DayState Function(DateTime date)? stateFor;
  final DateTime? firstDate;
  final DateTime? lastDate;
  final String? Function(DateTime date)? badgeFor;

  @override
  Widget build(BuildContext context) {
    final daysInMonth = DateUtils.getDaysInMonth(month.year, month.month);
    // DateTime.weekday is 1=Mon…7=Sun, and the grid starts on Monday.
    final leadingBlanks = DateTime(month.year, month.month, 1).weekday - 1;
    final cellCount = leadingBlanks + daysInMonth;
    final rows = (cellCount / 7).ceil();

    return Column(
      children: [
        for (var row = 0; row < rows; row++)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.base),
            child: Row(
              children: [
                for (var col = 0; col < 7; col++)
                  Expanded(
                    child: _buildCell(row * 7 + col - leadingBlanks),
                  ),
              ],
            ),
          ),
      ],
    );
  }

  Widget _buildCell(int dayIndex) {
    if (dayIndex < 0 || dayIndex >= DateUtils.getDaysInMonth(month.year, month.month)) {
      return const SizedBox(height: 44);
    }

    final date = DateTime(month.year, month.month, dayIndex + 1);
    final state = _resolveState(date);
    final isSelected = _isSelected(date);
    final inRange = _isInRange(date);
    final isToday = DateUtils.isSameDay(date, DateTime.now());
    final enabled = state != DayState.disabled && onDayTap != null;

    return _DayCell(
      date: date,
      state: state,
      isSelected: isSelected,
      isInRange: inRange,
      isToday: isToday,
      badge: badgeFor?.call(date),
      onTap: enabled ? () => onDayTap!(date) : null,
    );
  }

  DayState _resolveState(DateTime date) {
    final first = firstDate;
    final last = lastDate;
    if (first != null && date.isBefore(DateUtils.dateOnly(first))) {
      return DayState.disabled;
    }
    if (last != null && date.isAfter(DateUtils.dateOnly(last))) {
      return DayState.disabled;
    }
    return stateFor?.call(date) ?? DayState.neutral;
  }

  bool _isSelected(DateTime date) {
    if (selection == CalendarSelection.range) {
      return DateUtils.isSameDay(date, rangeStart) ||
          DateUtils.isSameDay(date, rangeEnd);
    }
    return selected.any((d) => DateUtils.isSameDay(d, date));
  }

  bool _isInRange(DateTime date) {
    if (selection != CalendarSelection.range) return false;
    final start = rangeStart;
    final end = rangeEnd;
    if (start == null || end == null) return false;
    return date.isAfter(DateUtils.dateOnly(start)) &&
        date.isBefore(DateUtils.dateOnly(end));
  }
}

class _DayCell extends StatelessWidget {
  const _DayCell({
    required this.date,
    required this.state,
    required this.isSelected,
    required this.isInRange,
    required this.isToday,
    required this.badge,
    required this.onTap,
  });

  final DateTime date;
  final DayState state;
  final bool isSelected;
  final bool isInRange;
  final bool isToday;
  final String? badge;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final (background, foreground) = _colors();

    return SizedBox(
      height: 44,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 2),
        child: Material(
          color: background,
          borderRadius: AppRadius.mdAll,
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            child: Container(
              decoration: BoxDecoration(
                borderRadius: AppRadius.mdAll,
                border: isToday && !isSelected
                    ? Border.all(color: AppColors.primary, width: 1.4)
                    : null,
              ),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    '${date.day}',
                    style: AppTypography.labelMedium.copyWith(
                      color: foreground,
                      fontWeight:
                          isSelected ? FontWeight.w700 : FontWeight.w600,
                    ),
                  ),
                  if (badge != null)
                    Text(
                      badge!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelSmall.copyWith(
                        fontSize: 8,
                        color: foreground.withValues(alpha: 0.8),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  (Color, Color) _colors() {
    if (isSelected) return (AppColors.primary, AppColors.onPrimary);
    if (isInRange) return (AppColors.primaryFixed, AppColors.onPrimaryFixed);

    return switch (state) {
      DayState.available => (
          AppColors.successContainer,
          AppColors.onTertiaryFixedVariant,
        ),
      DayState.blocked => (
          AppColors.surfaceContainerHighest,
          AppColors.outline,
        ),
      DayState.booked => (
          AppColors.warningContainer,
          AppColors.warning,
        ),
      DayState.disabled => (Colors.transparent, AppColors.outlineVariant),
      DayState.neutral => (Colors.transparent, AppColors.onSurface),
    };
  }
}

/// The colour key shown under an availability calendar.
class CalendarLegend extends StatelessWidget {
  const CalendarLegend({super.key, this.items = const []});

  final List<(String, Color)> items;

  /// A getter, not a constant, so the swatches follow the active theme.
  static List<(String, Color)> get availability => [
        ('Open', AppColors.successContainer),
        ('Booked', AppColors.warningContainer),
        ('Blocked', AppColors.surfaceContainerHighest),
      ];

  @override
  Widget build(BuildContext context) {
    final entries = items.isEmpty ? availability : items;

    return Wrap(
      spacing: AppSpacing.md,
      runSpacing: AppSpacing.xs,
      children: [
        for (final (label, color) in entries)
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 12,
                height: 12,
                decoration: BoxDecoration(
                  color: color,
                  borderRadius: AppRadius.smAll,
                ),
              ),
              const SizedBox(width: 6),
              Text(label, style: AppTypography.labelSmall),
            ],
          ),
      ],
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/theme/app_theme.dart';
import '../../models/availability.dart';
import '../../providers/calendar_provider.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_calendar.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_image.dart';

/// The campaign window a brand picked.
typedef CampaignWindow = ({DateTime start, DateTime end});

/// Booking Calendar — the brand-side date picker.
///
/// Shows the creator's real availability (from
/// `GET /creators/{id}/availability`) and only lets the brand choose a window
/// that starts on a bookable date, so a request cannot be sent for a day the
/// creator has blocked.
class BookingCalendarSheet extends ConsumerStatefulWidget {
  const BookingCalendarSheet({
    super.key,
    required this.creatorId,
    required this.creatorName,
    this.initialStart,
    this.initialEnd,
  });

  final int creatorId;
  final String creatorName;
  final DateTime? initialStart;
  final DateTime? initialEnd;

  /// Opens the sheet and resolves to the chosen window, or null if dismissed.
  static Future<CampaignWindow?> show(
    BuildContext context, {
    required int creatorId,
    required String creatorName,
    DateTime? initialStart,
    DateTime? initialEnd,
  }) {
    return showModalBottomSheet<CampaignWindow>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      sheetAnimationStyle: zSheetMotion(context),
      builder: (_) => BookingCalendarSheet(
        creatorId: creatorId,
        creatorName: creatorName,
        initialStart: initialStart,
        initialEnd: initialEnd,
      ),
    );
  }

  @override
  ConsumerState<BookingCalendarSheet> createState() =>
      _BookingCalendarSheetState();
}

class _BookingCalendarSheetState extends ConsumerState<BookingCalendarSheet> {
  late DateTime _month = DateTime(
    (widget.initialStart ?? DateTime.now()).year,
    (widget.initialStart ?? DateTime.now()).month,
  );

  DateTime? _start;
  DateTime? _end;

  @override
  void initState() {
    super.initState();
    _start = widget.initialStart;
    _end = widget.initialEnd;
  }

  /// Range selection: the first tap sets the start, the second sets the end.
  /// Tapping before the current start restarts the range.
  void _onDayTap(DateTime date, AvailabilityMonth data) {
    final day = data.dayFor(date);
    final start = _start;

    setState(() {
      if (start == null || _end != null || date.isBefore(start)) {
        // A window must begin on a date the creator is actually open for.
        if (day != null && !day.isBookable) return;
        _start = date;
        _end = null;
      } else {
        _end = date;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final availability = ref.watch(
      availabilityProvider((creatorId: widget.creatorId, month: _month)),
    );

    return SafeArea(
      top: false,
      // Scrolls when a small phone or a large text size leaves the month grid
      // taller than the space the sheet is given.
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.marginMobile,
          0,
          AppSpacing.marginMobile,
          AppSpacing.md,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Choose campaign dates', style: AppTypography.headlineSmall),
            const SizedBox(height: 2),
            Text(
              "Showing ${widget.creatorName}'s availability. Your window has "
              'to start on an open date.',
              style: AppTypography.bodySmall,
            ),
            const SizedBox(height: AppSpacing.md),
            AsyncView(
              value: availability,
              onRetry: () => ref.invalidate(
                availabilityProvider((
                  creatorId: widget.creatorId,
                  month: _month,
                )),
              ),
              loading: const ZShimmer(
                height: 320,
                borderRadius: AppRadius.lgAll,
              ),
              builder: (data) => Column(
                children: [
                  ZCalendar(
                    month: _month,
                    firstDate: DateTime.now(),
                    lastDate: DateTime.now().add(const Duration(days: 365)),
                    selection: CalendarSelection.range,
                    rangeStart: _start,
                    rangeEnd: _end,
                    onMonthChanged: (next) => setState(() => _month = next),
                    onDayTap: (date) => _onDayTap(date, data),
                    stateFor: (date) {
                      final day = data.dayFor(date);
                      if (day == null) return DayState.neutral;
                      if (!day.isAvailable) return DayState.blocked;
                      if (day.isFullyBooked) return DayState.booked;
                      return DayState.available;
                    },
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  const CalendarLegend(),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            _SelectionSummary(start: _start, end: _end),
            const SizedBox(height: AppSpacing.md),
            Row(
              children: [
                Expanded(
                  child: ZButton(
                    label: 'Clear',
                    variant: ZButtonVariant.text,
                    size: ZButtonSize.medium,
                    onPressed: _start == null
                        ? null
                        : () => setState(() {
                            _start = null;
                            _end = null;
                          }),
                  ),
                ),
                const SizedBox(width: AppSpacing.xs),
                Expanded(
                  flex: 2,
                  child: ZButton(
                    label: 'Confirm dates',
                    icon: Icons.check_rounded,
                    size: ZButtonSize.medium,
                    onPressed: _start == null
                        ? null
                        : () => Navigator.of(
                            context,
                          ).pop((start: _start!, end: _end ?? _start!)),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Echoes the picked window back with its duration.
class _SelectionSummary extends StatelessWidget {
  const _SelectionSummary({required this.start, required this.end});

  final DateTime? start;
  final DateTime? end;

  @override
  Widget build(BuildContext context) {
    final format = DateFormat('d MMM yyyy');

    final String label;
    if (start == null) {
      label = 'Tap an open date to set your start date';
    } else if (end == null) {
      label = 'Starts ${format.format(start!)} · tap again to set the end date';
    } else {
      final nights = end!.difference(start!).inDays + 1;
      label =
          '${format.format(start!)} → ${format.format(end!)} '
          '· $nights day${nights == 1 ? '' : 's'}';
    }

    return AnimatedContainer(
      duration: Motion.of(context, Motion.fast),
      curve: Motion.standard,
      width: double.infinity,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: start == null
            ? AppColors.surfaceContainerLow
            : AppColors.primaryFixed,
        borderRadius: AppRadius.lgAll,
        border: Border.all(
          color: start == null ? AppColors.outlineVariant : Colors.transparent,
        ),
      ),
      child: Row(
        children: [
          Icon(
            start == null
                ? Icons.touch_app_rounded
                : Icons.event_available_rounded,
            size: 17,
            color: start == null
                ? AppColors.onSurfaceVariant
                : AppColors.onPrimaryFixedVariant,
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              label,
              style: AppTypography.labelMedium.copyWith(
                color: start == null
                    ? AppColors.onSurfaceVariant
                    : AppColors.onPrimaryFixedVariant,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

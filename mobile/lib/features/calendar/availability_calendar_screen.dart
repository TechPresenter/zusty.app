import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../models/availability.dart';
import '../../providers/auth_provider.dart';
import '../../providers/calendar_provider.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_calendar.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../booking/widgets/booking_ui.dart';

/// Availability Calendar — the creator-side booking calendar.
///
/// Implements FR-028 / SRS-FR-… : a creator opens or blocks dates so brands can
/// only book them when they are actually free. Tap dates to build a selection,
/// then apply an action to the whole batch — the API takes up to 90 dates in
/// one `PUT /me/creator/availability` call.
class AvailabilityCalendarScreen extends ConsumerWidget {
  const AvailabilityCalendarScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final creatorId = user?.creatorId ?? user?.id ?? 0;
    final month = ref.watch(calendarMonthProvider);
    final selection = ref.watch(dateSelectionProvider);

    final availability = ref.watch(
      availabilityProvider((creatorId: creatorId, month: month)),
    );

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        title: 'Availability',
        actions: [
          if (selection.isNotEmpty)
            TextButton(
              onPressed: () => ref.read(dateSelectionProvider.notifier).clear(),
              style: TextButton.styleFrom(
                foregroundColor: AppColors.primary,
                minimumSize: const Size(
                  AppSpacing.minTouch,
                  AppSpacing.minTouch,
                ),
              ),
              child: Text(
                'Clear',
                style: AppTypography.labelLarge.copyWith(
                  color: AppColors.primary,
                ),
              ),
            ),
        ],
      ),
      bottomBar: selection.isEmpty
          ? null
          : _SelectionActions(creatorId: creatorId, month: month),
      body: AsyncView(
        value: availability,
        onRetry: () => ref.invalidate(
          availabilityProvider((creatorId: creatorId, month: month)),
        ),
        loading: ListView(
          physics: const NeverScrollableScrollPhysics(),
          padding: EdgeInsets.fromLTRB(
            AppBreakpoints.pageMargin(context),
            AppSpacing.xs,
            AppBreakpoints.pageMargin(context),
            AppSpacing.xl,
          ),
          children: const [
            ShimmerBlock(height: 112),
            SizedBox(height: AppSpacing.md),
            ShimmerBlock(height: 380),
          ],
        ),
        builder: (data) => ListView(
          padding: EdgeInsets.fromLTRB(
            AppBreakpoints.pageMargin(context),
            AppSpacing.xs,
            AppBreakpoints.pageMargin(context),
            AppSpacing.xl,
          ),
          children: [
            Text(
              'Open the dates you can take work, and block the rest. Brands '
              'can only request dates that are open.',
              style: AppTypography.bodySmall,
            ),
            const SizedBox(height: AppSpacing.sm),
            _SummaryRow(month: data),
            const SizedBox(height: AppSpacing.md),
            ZCard(
              child: Column(
                children: [
                  ZCalendar(
                    month: month,
                    firstDate: DateTime.now(),
                    lastDate: DateTime.now().add(const Duration(days: 365)),
                    selection: CalendarSelection.multi,
                    selected: selection,
                    onMonthChanged: (next) {
                      ref.read(calendarMonthProvider.notifier).state = next;
                      ref.read(dateSelectionProvider.notifier).clear();
                    },
                    onDayTap: (date) =>
                        ref.read(dateSelectionProvider.notifier).toggle(date),
                    stateFor: (date) => _stateFor(data, date),
                    badgeFor: (date) {
                      final day = data.dayFor(date);
                      if (day == null || day.bookedCount == 0) return null;
                      return '${day.bookedCount}/${day.maxBookings}';
                    },
                  ),
                  const SizedBox(height: AppSpacing.md),
                  const Divider(height: 1),
                  const SizedBox(height: AppSpacing.sm),
                  const CalendarLegend(),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            _QuickActions(month: month),
            const SizedBox(height: AppSpacing.md),
            _UpcomingList(data: data),
          ],
        ),
      ),
    );
  }

  static DayState _stateFor(AvailabilityMonth month, DateTime date) {
    final day = month.dayFor(date);
    if (day == null) return DayState.neutral;
    if (!day.isAvailable) return DayState.blocked;
    if (day.isFullyBooked) return DayState.booked;
    return DayState.available;
  }
}

/// Open / booked / blocked counts for the visible month.
class _SummaryRow extends StatelessWidget {
  const _SummaryRow({required this.month});

  final AvailabilityMonth month;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: _SummaryTile(
            label: 'Open',
            value: '${month.openCount}',
            color: AppColors.success,
            background: AppColors.successContainer,
            icon: Icons.event_available_rounded,
          ),
        ),
        const SizedBox(width: AppSpacing.xs),
        Expanded(
          child: _SummaryTile(
            label: 'Booked',
            value: '${month.bookedCount}',
            color: AppColors.warning,
            background: AppColors.warningContainer,
            icon: Icons.event_busy_rounded,
          ),
        ),
        const SizedBox(width: AppSpacing.xs),
        Expanded(
          child: _SummaryTile(
            label: 'Blocked',
            value: '${month.blockedCount}',
            color: AppColors.onSurfaceVariant,
            background: AppColors.surfaceContainerHighest,
            icon: Icons.block_rounded,
          ),
        ),
      ],
    );
  }
}

class _SummaryTile extends StatelessWidget {
  const _SummaryTile({
    required this.label,
    required this.value,
    required this.color,
    required this.background,
    required this.icon,
  });

  final String label;
  final String value;
  final Color color;
  final Color background;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: '$value $label',
      excludeSemantics: true,
      child: ZCard(
        padding: const EdgeInsets.all(AppSpacing.sm),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            TintedIconTile(
              icon: icon,
              color: color,
              background: background,
              size: 32,
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              value,
              style: AppTypography.headlineSmall.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

/// Bulk helpers that operate on the whole month rather than a manual selection.
class _QuickActions extends ConsumerWidget {
  const _QuickActions({required this.month});

  final DateTime month;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ZCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Quick actions',
            style: AppTypography.titleMedium.copyWith(fontSize: 17),
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'Apply a pattern to ${DateFormat('MMMM').format(month)}, then '
            'fine-tune individual dates on the calendar.',
            style: AppTypography.bodySmall,
          ),
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.xs,
            runSpacing: AppSpacing.xs,
            children: [
              ZChip(
                label: 'Open all weekdays',
                icon: Icons.event_available_rounded,
                onTap: () => _selectWeekdays(ref, weekend: false),
              ),
              ZChip(
                label: 'Block weekends',
                icon: Icons.weekend_rounded,
                onTap: () => _selectWeekdays(ref, weekend: true),
              ),
              ZChip(
                label: 'Select whole month',
                icon: Icons.calendar_month_rounded,
                onTap: () => _selectAll(ref),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Loads a pattern into the pending selection rather than saving directly, so
  /// the creator always sees exactly what will change before confirming.
  void _selectWeekdays(WidgetRef ref, {required bool weekend}) {
    final dates = _datesInMonth().where((date) {
      final isWeekend =
          date.weekday == DateTime.saturday || date.weekday == DateTime.sunday;
      return weekend ? isWeekend : !isWeekend;
    });
    ref.read(dateSelectionProvider.notifier).selectAll(dates);
  }

  void _selectAll(WidgetRef ref) =>
      ref.read(dateSelectionProvider.notifier).selectAll(_datesInMonth());

  List<DateTime> _datesInMonth() {
    final today = DateUtils.dateOnly(DateTime.now());
    final total = DateUtils.getDaysInMonth(month.year, month.month);

    return [
      for (var day = 1; day <= total; day++)
        DateTime(month.year, month.month, day),
    ].where((d) => !d.isBefore(today)).toList();
  }
}

/// The action bar shown once dates are selected.
class _SelectionActions extends ConsumerStatefulWidget {
  const _SelectionActions({required this.creatorId, required this.month});

  final int creatorId;
  final DateTime month;

  @override
  ConsumerState<_SelectionActions> createState() => _SelectionActionsState();
}

class _SelectionActionsState extends ConsumerState<_SelectionActions> {
  bool _saving = false;
  int _maxBookings = 2;

  Future<void> _apply({required bool isAvailable}) async {
    final dates = ref.read(dateSelectionProvider);
    if (dates.isEmpty) return;

    setState(() => _saving = true);

    try {
      await ref
          .read(availabilityActionsProvider)
          .apply(
            creatorId: widget.creatorId,
            month: widget.month,
            dates: dates,
            isAvailable: isAvailable,
            maxBookings: _maxBookings,
          );

      if (mounted) {
        showZSnack(
          context,
          isAvailable
              ? '${dates.length} date${dates.length == 1 ? '' : 's'} opened for booking.'
              : '${dates.length} date${dates.length == 1 ? '' : 's'} blocked.',
        );
      }
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final count = ref.watch(dateSelectionProvider).length;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                '$count date${count == 1 ? '' : 's'} selected',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.titleSmall,
              ),
            ),
            Text('Bookings / day', style: AppTypography.bodySmall),
            const SizedBox(width: AppSpacing.xs),
            _Stepper(
              value: _maxBookings,
              onChanged: (v) => setState(() => _maxBookings = v),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.xs),
        Row(
          children: [
            Expanded(
              child: ZButton.outlined(
                label: 'Block',
                icon: Icons.block_rounded,
                size: ZButtonSize.medium,
                isLoading: _saving,
                onPressed: () => _apply(isAvailable: false),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: ZButton(
                label: 'Open',
                icon: Icons.event_available_rounded,
                size: ZButtonSize.medium,
                isLoading: _saving,
                onPressed: () => _apply(isAvailable: true),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// A compact −/+ control for the per-day booking cap.
class _Stepper extends StatelessWidget {
  const _Stepper({required this.value, required this.onChanged});

  final int value;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerHigh,
        borderRadius: AppRadius.pill,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _StepButton(
            icon: Icons.remove_rounded,
            onTap: value > 1 ? () => onChanged(value - 1) : null,
          ),
          SizedBox(
            width: 24,
            child: Text(
              '$value',
              textAlign: TextAlign.center,
              style: AppTypography.labelLarge,
            ),
          ),
          _StepButton(
            icon: Icons.add_rounded,
            onTap: value < 10 ? () => onChanged(value + 1) : null,
          ),
        ],
      ),
    );
  }
}

class _StepButton extends StatelessWidget {
  const _StepButton({required this.icon, this.onTap});

  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final increase = icon == Icons.add_rounded;

    return Semantics(
      button: true,
      enabled: onTap != null,
      label: increase ? 'More bookings per day' : 'Fewer bookings per day',
      excludeSemantics: true,
      child: InkWell(
        onTap: onTap,
        customBorder: const CircleBorder(),
        child: SizedBox(
          width: 40,
          height: AppSpacing.minTouch,
          child: Icon(
            icon,
            size: 18,
            color: onTap == null ? AppColors.outline : AppColors.onSurface,
          ),
        ),
      ),
    );
  }
}

/// The next few open dates, so the creator can sanity-check at a glance.
class _UpcomingList extends StatelessWidget {
  const _UpcomingList({required this.data});

  final AvailabilityMonth data;

  @override
  Widget build(BuildContext context) {
    final upcoming = data.bookableDays
        .where((d) => !d.date.isBefore(DateUtils.dateOnly(DateTime.now())))
        .take(5)
        .toList();

    if (upcoming.isEmpty) {
      return ZCard(
        child: EmptyState(
          compact: true,
          icon: Icons.event_busy_rounded,
          title: 'No open dates',
          message:
              'Select dates above and tap Open to start taking bookings '
              'this month.',
        ),
      );
    }

    return ZCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Next open dates',
            style: AppTypography.titleMedium.copyWith(fontSize: 17),
          ),
          const SizedBox(height: AppSpacing.xs),
          for (final day in upcoming) ...[
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.base),
              child: Row(
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: AppColors.successContainer,
                      borderRadius: AppRadius.mdAll,
                    ),
                    alignment: Alignment.center,
                    // Scales down rather than overflowing the fixed tile at
                    // very large text sizes.
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            DateFormat('MMM').format(day.date).toUpperCase(),
                            style: AppTypography.labelSmall.copyWith(
                              fontSize: 9,
                              color: AppColors.onSuccessContainer,
                            ),
                          ),
                          Text(
                            '${day.date.day}',
                            style: AppTypography.labelLarge.copyWith(
                              color: AppColors.onSuccessContainer,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          DateFormat('EEEE').format(day.date),
                          style: AppTypography.bodyMedium.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        Text(
                          day.slots.isEmpty ? 'All day' : day.slots.join(' · '),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.labelSmall,
                        ),
                      ],
                    ),
                  ),
                  StatusChip(
                    label:
                        '${day.maxBookings - day.bookedCount} slot'
                        '${day.maxBookings - day.bookedCount == 1 ? '' : 's'}',
                    tone: StatusTone.success,
                    dense: true,
                  ),
                ],
              ),
            ),
            if (day != upcoming.last) const Divider(height: 1),
          ],
        ],
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/availability.dart';
import 'core_providers.dart';
import 'discovery_provider.dart';

/// The month a calendar is currently showing. Normalised to the first of the
/// month so the family key stays stable as the user pages back and forth.
final calendarMonthProvider =
    StateProvider.autoDispose<DateTime>((_) {
  final now = DateTime.now();
  return DateTime(now.year, now.month);
});

/// One creator's availability for one month.
typedef AvailabilityQuery = ({int creatorId, DateTime month});

final availabilityProvider = FutureProvider.autoDispose
    .family<AvailabilityMonth, AvailabilityQuery>((ref, query) {
  return ref
      .watch(creatorRepositoryProvider)
      .availability(query.creatorId, query.month);
});

/// Dates the creator has ticked in the availability editor but not yet saved.
class DateSelectionController extends AutoDisposeNotifier<Set<DateTime>> {
  @override
  Set<DateTime> build() => const {};

  bool contains(DateTime date) =>
      state.any((d) => DateUtils.isSameDay(d, date));

  void toggle(DateTime date) {
    final normalised = DateUtils.dateOnly(date);
    final next = {...state};

    final existing =
        next.where((d) => DateUtils.isSameDay(d, normalised)).toList();
    if (existing.isEmpty) {
      next.add(normalised);
    } else {
      next.removeAll(existing);
    }

    state = next;
  }

  void clear() => state = const {};

  /// Selects every remaining day in [month] that is not already blocked.
  void selectAll(Iterable<DateTime> dates) =>
      state = dates.map(DateUtils.dateOnly).toSet();
}

final dateSelectionProvider =
    AutoDisposeNotifierProvider<DateSelectionController, Set<DateTime>>(
        DateSelectionController.new);

/// Saves availability edits and refreshes the affected month.
class AvailabilityActions {
  AvailabilityActions(this._ref);

  final Ref _ref;

  Future<void> apply({
    required int creatorId,
    required DateTime month,
    required Set<DateTime> dates,
    required bool isAvailable,
    int maxBookings = 1,
  }) async {
    if (dates.isEmpty) return;

    await _ref.read(creatorRepositoryProvider).setAvailability(
          dates: dates.toList(),
          isAvailable: isAvailable,
          maxBookings: maxBookings,
        );

    _ref.read(dateSelectionProvider.notifier).clear();
    _ref.invalidate(availabilityProvider((creatorId: creatorId, month: month)));
    _ref.invalidate(creatorProfileProvider);
  }
}

final availabilityActionsProvider = Provider<AvailabilityActions>((ref) {
  return AvailabilityActions(ref);
});

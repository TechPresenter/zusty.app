import '../core/utils/formatters.dart';
import '../core/utils/json.dart';

/// One day in a creator's booking calendar.
///
/// Mirrors a `creator_availability` row as returned by
/// `GET /creators/{id}/availability`.
class AvailabilityDay {
  const AvailabilityDay({
    required this.date,
    this.isAvailable = true,
    this.maxBookings = 1,
    this.bookedCount = 0,
    this.slots = const [],
  });

  final DateTime date;

  /// The creator has opened this date for bookings.
  final bool isAvailable;

  /// How many campaigns the creator will take on this date.
  final int maxBookings;
  final int bookedCount;

  /// Optional time windows, e.g. `['09:00-12:00']`.
  final List<String> slots;

  factory AvailabilityDay.fromJson(Map<String, dynamic> json) {
    final raw = Json.strOrNull(json['available_on'] ?? json['date']);
    return AvailabilityDay(
      date: Formatters.parseDate(raw) ?? DateTime.now(),
      isAvailable: Json.boolean(json['is_available'], true),
      maxBookings: Json.integer(json['max_bookings'], 1),
      bookedCount: Json.integer(json['booked_count']),
      slots: Json.strings(json['slots']),
    );
  }

  /// The backend also sends `is_bookable`; recompute locally so the widget can
  /// stay correct after an optimistic edit.
  bool get isBookable => isAvailable && bookedCount < maxBookings;

  bool get isFullyBooked => isAvailable && bookedCount >= maxBookings;

  /// `2026-08-14` — the key the API expects in the `dates` array.
  String get key => _key(date);

  static String _key(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';

  static String keyFor(DateTime date) => _key(date);

  AvailabilityDay copyWith({bool? isAvailable, int? maxBookings}) =>
      AvailabilityDay(
        date: date,
        isAvailable: isAvailable ?? this.isAvailable,
        maxBookings: maxBookings ?? this.maxBookings,
        bookedCount: bookedCount,
        slots: slots,
      );
}

/// A month of availability, keyed by `yyyy-MM-dd` for O(1) day lookup.
class AvailabilityMonth {
  const AvailabilityMonth({required this.month, required this.days});

  /// First day of the month this covers.
  final DateTime month;

  final Map<String, AvailabilityDay> days;

  factory AvailabilityMonth.fromJson(Map<String, dynamic> json) {
    final label = Json.str(json['month']);
    final parsed = label.isEmpty ? null : DateTime.tryParse('$label-01');

    final days = <String, AvailabilityDay>{};
    for (final row in Json.list(json['days'], AvailabilityDay.fromJson)) {
      days[row.key] = row;
    }

    return AvailabilityMonth(
      month: parsed ?? DateTime(DateTime.now().year, DateTime.now().month),
      days: days,
    );
  }

  static AvailabilityMonth empty(DateTime month) =>
      AvailabilityMonth(month: DateTime(month.year, month.month), days: const {});

  AvailabilityDay? dayFor(DateTime date) => days[AvailabilityDay.keyFor(date)];

  /// `2026-08` — the `month` query parameter.
  String get key =>
      '${month.year.toString().padLeft(4, '0')}-${month.month.toString().padLeft(2, '0')}';

  /// Days the creator can actually take work on, ascending.
  List<AvailabilityDay> get bookableDays =>
      (days.values.where((d) => d.isBookable).toList())
        ..sort((a, b) => a.date.compareTo(b.date));

  int get openCount => days.values.where((d) => d.isBookable).length;
  int get blockedCount => days.values.where((d) => !d.isAvailable).length;
  int get bookedCount => days.values.where((d) => d.isFullyBooked).length;

  /// Applies a local edit so the grid updates before the server responds.
  AvailabilityMonth withDates(
    Iterable<DateTime> dates, {
    required bool isAvailable,
    int maxBookings = 1,
  }) {
    final updated = Map<String, AvailabilityDay>.from(days);

    for (final date in dates) {
      final key = AvailabilityDay.keyFor(date);
      final existing = updated[key];
      updated[key] = existing == null
          ? AvailabilityDay(
              date: DateTime(date.year, date.month, date.day),
              isAvailable: isAvailable,
              maxBookings: maxBookings,
            )
          : existing.copyWith(
              isAvailable: isAvailable,
              maxBookings: maxBookings,
            );
    }

    return AvailabilityMonth(month: month, days: updated);
  }
}

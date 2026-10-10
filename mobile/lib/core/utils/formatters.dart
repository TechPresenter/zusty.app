import 'package:intl/intl.dart';
import 'package:timeago/timeago.dart' as timeago;

import '../config/app_config.dart';

/// Presentation helpers. The backend already sends `*_label` strings for most
/// money and count fields; these cover locally-derived values and fixtures.
abstract final class Formatters {
  static final NumberFormat _currency = NumberFormat.currency(
    locale: 'en_IN',
    symbol: AppConfig.currencySymbol,
    decimalDigits: 0,
  );

  static final NumberFormat _decimal = NumberFormat.decimalPattern('en_IN');

  /// `₹15,000`
  static String currency(num? amount) =>
      amount == null ? '—' : _currency.format(amount);

  /// `₹15,000 – ₹50,000`
  static String currencyRange(num? min, num? max) {
    if (min == null && max == null) return '—';
    if (min == null) return currency(max);
    if (max == null || min == max) return currency(min);
    return '${currency(min)} – ${currency(max)}';
  }

  /// `1,20,540` (Indian digit grouping).
  static String number(num? value) =>
      value == null ? '0' : _decimal.format(value);

  /// `56K`, `1.2M` — matches `Str::compactNumber()` on the backend.
  static String compact(num? value) {
    final n = value ?? 0;
    if (n >= 10000000) return '${(n / 10000000).toStringAsFixed(1)}Cr';
    if (n >= 100000) return '${(n / 100000).toStringAsFixed(1)}L';
    if (n >= 1000) {
      final k = n / 1000;
      return '${k % 1 == 0 ? k.toStringAsFixed(0) : k.toStringAsFixed(1)}K';
    }
    return n.toStringAsFixed(0);
  }

  /// `₹1.3L`, `₹45K`: a price that fits a card.
  static String currencyCompact(num? amount) =>
      amount == null || amount <= 0 ? '—' : '₹${compact(amount)}';

  /// `3.4%`, or an em dash when there is no figure to show.
  static String percent(num? value) =>
      value == null || value <= 0 ? '—' : '${value.toStringAsFixed(1)}%';

  /// `01 Aug 2026`
  static String date(DateTime? value) =>
      value == null ? '—' : DateFormat('dd MMM yyyy').format(value);

  /// `01 Aug 2026, 4:30 PM`
  static String dateTime(DateTime? value) =>
      value == null ? '—' : DateFormat('dd MMM yyyy, h:mm a').format(value);

  /// `4:30 PM`
  static String time(DateTime? value) =>
      value == null ? '' : DateFormat('h:mm a').format(value);

  /// `Aug 1` — compact chat/list day separator.
  static String shortDate(DateTime? value) =>
      value == null ? '' : DateFormat('MMM d').format(value);

  /// `Jun 2018` — the granularity work history and education are read at,
  /// where the day of the month is noise.
  static String monthYear(DateTime? value) =>
      value == null ? '' : DateFormat('MMM yyyy').format(value);

  /// `2 hours ago`
  static String relative(DateTime? value) =>
      value == null ? '' : timeago.format(value, locale: 'en_short');

  /// `Today`, `Yesterday`, or the short date — used as a chat day divider.
  static String dayLabel(DateTime value) {
    final now = DateTime.now();
    final day = DateTime(value.year, value.month, value.day);
    final today = DateTime(now.year, now.month, now.day);
    final diff = today.difference(day).inDays;

    if (diff == 0) return 'Today';
    if (diff == 1) return 'Yesterday';
    if (diff < 7) return DateFormat('EEEE').format(value);
    return DateFormat('dd MMM yyyy').format(value);
  }

  /// Lenient ISO/MySQL datetime parsing — the API sends `Y-m-d H:i:s`.
  static DateTime? parseDate(dynamic value) {
    if (value == null) return null;
    if (value is DateTime) return value;
    final text = value.toString().trim();
    if (text.isEmpty) return null;
    return DateTime.tryParse(text.replaceFirst(' ', 'T'));
  }

  /// `AS` from "Ananya Sharma" — avatar fallback.
  static String initials(String? name) {
    final parts = (name ?? '')
        .trim()
        .split(RegExp(r'\s+'))
        .where((p) => p.isNotEmpty)
        .toList();
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts.first.substring(0, 1).toUpperCase();
    return (parts.first.substring(0, 1) + parts.last.substring(0, 1))
        .toUpperCase();
  }

  /// `4.8`
  static String rating(num? value) => (value ?? 0).toStringAsFixed(1);

  /// `2 days` / `1 day` / `Today`
  static String duration(int? days) {
    if (days == null) return '—';
    if (days <= 0) return 'Today';
    return days == 1 ? '1 day' : '$days days';
  }

  /// `0:05`, `1:00`, `13:00`: a wait in minutes and seconds, never negative.
  static String countdown(int seconds) {
    final s = seconds < 0 ? 0 : seconds;
    return '${s ~/ 60}:${(s % 60).toString().padLeft(2, '0')}';
  }
}

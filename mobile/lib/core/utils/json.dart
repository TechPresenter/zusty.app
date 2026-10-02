/// Lenient readers for API payloads.
///
/// The Core PHP backend emits numbers as JSON numbers in most places but as
/// strings in a few (MySQL `DECIMAL` columns come back as strings through PDO),
/// and booleans as `0`/`1`. These helpers absorb that so models never throw on
/// a type mismatch.
abstract final class Json {
  static String str(dynamic value, [String fallback = '']) {
    if (value == null) return fallback;
    final text = value.toString();
    return text == 'null' ? fallback : text;
  }

  static String? strOrNull(dynamic value) {
    if (value == null) return null;
    final text = value.toString().trim();
    return (text.isEmpty || text == 'null') ? null : text;
  }

  static int integer(dynamic value, [int fallback = 0]) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    if (value is bool) return value ? 1 : 0;
    return int.tryParse('$value') ?? fallback;
  }

  static int? intOrNull(dynamic value) {
    if (value == null) return null;
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse('$value');
  }

  static double decimal(dynamic value, [double fallback = 0]) {
    if (value is double) return value;
    if (value is num) return value.toDouble();
    return double.tryParse('$value') ?? fallback;
  }

  static double? doubleOrNull(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();
    return double.tryParse('$value');
  }

  /// Accepts `true`, `1`, `"1"`, `"true"`, `"yes"`.
  static bool boolean(dynamic value, [bool fallback = false]) {
    if (value is bool) return value;
    if (value is num) return value != 0;
    if (value is String) {
      final text = value.toLowerCase().trim();
      if (text == 'true' || text == '1' || text == 'yes') return true;
      if (text == 'false' || text == '0' || text == 'no' || text.isEmpty) {
        return false;
      }
    }
    return fallback;
  }

  /// A JSON object, or an empty map for anything that is not one.
  ///
  /// The cast is guarded rather than asserted because PHP's `json_encode`
  /// renders an empty associative array as `[]`, not `{}` — so an endpoint that
  /// returns a populated object most of the time returns a LIST the moment it
  /// is empty. `value as Map?` threw a TypeError on exactly that, taking down
  /// Booking Details and Deliverable Review for any booking with no metrics.
  static Map<String, dynamic> map(dynamic value) =>
      value is Map ? value.cast<String, dynamic>() : const {};

  /// As [map], but null when there is no object — an empty `[]` from the API
  /// reads as "no object", which is what it means.
  static Map<String, dynamic>? mapOrNull(dynamic value) =>
      value is Map ? value.cast<String, dynamic>() : null;

  /// Maps a JSON array of objects through [builder], skipping malformed rows.
  static List<T> list<T>(
    dynamic value,
    T Function(Map<String, dynamic>) builder,
  ) {
    if (value is! List) return const [];
    return value
        .whereType<Map>()
        .map((e) => builder(e.cast<String, dynamic>()))
        .toList();
  }

  static List<String> strings(dynamic value) {
    if (value is! List) return const [];
    return value.map((e) => e.toString()).where((e) => e.isNotEmpty).toList();
  }
}

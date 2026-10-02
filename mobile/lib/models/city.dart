import 'dart:math' as math;

import '../core/utils/json.dart';
import 'taxonomy.dart';

/// A serviceable city used by the header location selector and the Location
/// filter on search.
class City {
  const City({
    required this.name,
    required this.state,
    required this.latitude,
    required this.longitude,
    this.isMetro = false,
  });

  final String name;
  final String state;
  final double latitude;
  final double longitude;

  /// Tier-1 cities are surfaced first in the picker.
  final bool isMetro;

  /// Builds a city from a `city` taxonomy term, whose metadata carries the
  /// coordinates and metro flag.
  factory City.fromTerm(TaxonomyTerm term) => City(
        name: term.value,
        state: term.description ?? '',
        latitude: (term.metadata['lat'] as num?)?.toDouble() ?? 0,
        longitude: (term.metadata['lng'] as num?)?.toDouble() ?? 0,
        isMetro: term.metadata['metro'] == true,
      );

  factory City.fromJson(Map<String, dynamic> json) => City(
        name: Json.str(json['name']),
        state: Json.str(json['state']),
        latitude: Json.decimal(json['lat']),
        longitude: Json.decimal(json['lng']),
        isMetro: Json.boolean(json['metro']),
      );

  /// `Mumbai, Maharashtra`
  String get label => state.isEmpty ? name : '$name, $state';

  /// Great-circle distance in kilometres, used to snap a GPS fix to the nearest
  /// serviceable city without needing a reverse-geocoding service.
  double distanceTo(double lat, double lng) {
    const earthRadiusKm = 6371.0;

    double toRad(double deg) => deg * math.pi / 180.0;

    final dLat = toRad(lat - latitude);
    final dLng = toRad(lng - longitude);

    final a = math.sin(dLat / 2) * math.sin(dLat / 2) +
        math.cos(toRad(latitude)) *
            math.cos(toRad(lat)) *
            math.sin(dLng / 2) *
            math.sin(dLng / 2);

    return earthRadiusKm * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a));
  }

  @override
  bool operator ==(Object other) =>
      other is City && other.name == name && other.state == state;

  @override
  int get hashCode => Object.hash(name, state);
}

/// The serviceable-city list.
///
/// Sourced from the `city` taxonomy, so operations can open or close a city
/// from the admin panel without an app release. [hydrate] is called once when
/// the taxonomy bundle loads; until then the list is empty and the picker
/// shows its loading state rather than a stale hardcoded set.
abstract final class Cities {
  static List<City> _all = const [];

  /// Replaces the list from the admin-managed taxonomy.
  static void hydrate(List<City> cities) => _all = cities;

  static List<City> get all => _all;

  static List<City> get metros =>
      _all.where((c) => c.isMetro).toList(growable: false);

  /// Name/state substring search for the picker.
  static List<City> search(String query) {
    final q = query.trim().toLowerCase();
    if (q.isEmpty) return _all;
    return _all
        .where((c) =>
            c.name.toLowerCase().contains(q) ||
            c.state.toLowerCase().contains(q))
        .toList(growable: false);
  }

  static City? byName(String? name) {
    if (name == null || name.isEmpty) return null;
    final lower = name.toLowerCase();
    for (final city in _all) {
      if (city.name.toLowerCase() == lower) return city;
    }
    return null;
  }

  /// Snaps a GPS fix to the closest city in [all].
  ///
  /// Returns null when the fix is further than [maxKm] from anywhere we serve,
  /// so the UI can say "outside our coverage" instead of silently picking a
  /// city hundreds of kilometres away.
  static City? nearest(double lat, double lng, {double maxKm = 200}) {
    City? best;
    var bestDistance = double.infinity;

    for (final city in _all) {
      final distance = city.distanceTo(lat, lng);
      if (distance < bestDistance) {
        bestDistance = distance;
        best = city;
      }
    }

    return bestDistance <= maxKm ? best : null;
  }
}

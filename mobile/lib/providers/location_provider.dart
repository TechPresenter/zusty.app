import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../core/providers/core_providers.dart';
import '../models/city.dart';
import 'taxonomy_provider.dart';

/// How the current city was chosen.
enum LocationSource { none, detected, manual }

/// Outcome of a GPS attempt, so the UI can explain what went wrong.
enum DetectStatus { idle, detecting, success, denied, disabled, outOfRange, failed }

@immutable
class LocationState {
  const LocationState({
    this.city,
    this.source = LocationSource.none,
    this.status = DetectStatus.idle,
  });

  final City? city;
  final LocationSource source;
  final DetectStatus status;

  bool get isDetecting => status == DetectStatus.detecting;
  bool get hasCity => city != null;

  /// What the header chip shows.
  String get label => city?.name ?? 'Select city';

  String? get errorMessage => switch (status) {
        DetectStatus.denied =>
          'Location permission denied. Pick your city manually.',
        DetectStatus.disabled =>
          'Location services are off. Turn them on or pick manually.',
        DetectStatus.outOfRange =>
          'We are not in your area yet — pick the closest city.',
        DetectStatus.failed =>
          'Could not detect your location. Pick your city manually.',
        _ => null,
      };

  LocationState copyWith({
    City? city,
    LocationSource? source,
    DetectStatus? status,
  }) =>
      LocationState(
        city: city ?? this.city,
        source: source ?? this.source,
        status: status ?? this.status,
      );
}

/// The city used to bias discovery, shown in the header.
///
/// Auto-detection uses the device GPS and snaps the fix to the nearest
/// serviceable city in [Cities] — no reverse-geocoding service required, so it
/// works offline and adds no per-request cost.
class LocationController extends Notifier<LocationState> {
  @override
  LocationState build() {
    // `Cities` is filled from the taxonomy bundle, which is fetched after
    // start-up. Watching it rebuilds this once the list lands, so a city
    // saved last session is restored instead of looked up in an empty list.
    // Every change of city is written to the cache first, so a rebuild
    // restores the current choice rather than losing it.
    ref.watch(taxonomyBundleProvider);

    final stored = ref.read(keyValueCacheProvider).cityName;
    final city = Cities.byName(stored);

    return LocationState(
      city: city,
      source: city == null ? LocationSource.none : LocationSource.manual,
    );
  }

  /// Explicit pick from the selector sheet.
  Future<void> select(City city) async {
    state = LocationState(
      city: city,
      source: LocationSource.manual,
      status: DetectStatus.idle,
    );
    await ref.read(keyValueCacheProvider).setCityName(city.name);
  }

  Future<void> clear() async {
    state = const LocationState();
    await ref.read(keyValueCacheProvider).setCityName('');
  }

  /// Requests permission, takes a fix, and snaps it to the nearest city.
  Future<void> detect() async {
    state = state.copyWith(status: DetectStatus.detecting);

    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        state = state.copyWith(status: DetectStatus.disabled);
        return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        state = state.copyWith(status: DetectStatus.denied);
        return;
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.medium,
          timeLimit: Duration(seconds: 15),
        ),
      );

      final nearest = Cities.nearest(position.latitude, position.longitude);
      if (nearest == null) {
        state = state.copyWith(status: DetectStatus.outOfRange);
        return;
      }

      state = LocationState(
        city: nearest,
        source: LocationSource.detected,
        status: DetectStatus.success,
      );
      await ref.read(keyValueCacheProvider).setCityName(nearest.name);
    } catch (e) {
      debugPrint('[Zusty] location detect failed: $e');
      state = state.copyWith(status: DetectStatus.failed);
    }
  }
}

final locationProvider =
    NotifierProvider<LocationController, LocationState>(LocationController.new);

/// The selected city name, for wiring into search filters.
final selectedCityProvider = Provider<String?>((ref) {
  return ref.watch(locationProvider).city?.name;
});

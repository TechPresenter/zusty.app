import 'dart:convert';
import 'dart:math';

import 'package:shared_preferences/shared_preferences.dart';

import '../config/app_config.dart';

/// A JSON document plus the moment it was written.
class CachedDocument {
  const CachedDocument({required this.json, required this.savedAt});

  final Map<String, dynamic> json;
  final DateTime savedAt;

  Duration get age => DateTime.now().difference(savedAt);

  bool isFresh([Duration ttl = AppConfig.cacheTtl]) => age < ttl;
}

/// Durable key/value cache for offline support and app-shell preferences.
///
/// Nothing secret goes in here — tokens live in [SecureStore]. Everything is
/// stored as JSON so the read-through pattern below works for any endpoint.
///
/// ## The read-through pattern screen agents should reuse
///
/// ```dart
/// Future<Paginated<Creator>> savedCreators({bool refresh = false}) async {
///   const cacheKey = 'favorites:page:1';
///
///   // 1. Serve the cache first so the list paints instantly.
///   final cached = await _cache.readList(cacheKey);
///   if (cached != null && !refresh) {
///     unawaited(_refreshSavedCreators(cacheKey));      // fire and forget
///     return Paginated(items: cached.map(Creator.fromJson).toList(), ...);
///   }
///
///   // 2. Otherwise hit the network and write through.
///   try {
///     final response = await _client.get(ApiEndpoints.favorites);
///     await _cache.writeList(cacheKey, response.dataList);
///     return ...;
///   } on OfflineFailure {
///     // 3. Offline with nothing cached is a real failure — let it surface so
///     //    ZErrorState can offer Retry.
///     if (cached == null) rethrow;
///     return ...;                                       // stale but useful
///   }
/// }
/// ```
///
/// Rules the screens rely on:
///  * cache keys are namespaced `feature:variant`, e.g. `home:brand`,
///    `bookings:tab=pending:page=1`, `creator:aanya-sharma`;
///  * only GET responses are cached, never a mutation;
///  * every cached list is capped by the API page size, so nothing unbounded
///    is written;
///  * [clearNamespace] runs on sign-out and after a mutation that invalidates
///    a whole feature.
class KeyValueCache {
  KeyValueCache(this._prefs);

  /// Opens the shared instance. Call once during bootstrap and hand the result
  /// to [keyValueCacheProvider] via an override.
  static Future<KeyValueCache> open() async =>
      KeyValueCache(await SharedPreferences.getInstance());

  final SharedPreferences _prefs;

  static const _documentPrefix = 'cache.doc.';
  static const _stampPrefix = 'cache.at.';

  // -- Cached documents ----------------------------------------------------

  /// Reads a cached object. Returns null when absent or unreadable.
  CachedDocument? readDocument(String key) {
    final raw = _prefs.getString('$_documentPrefix$key');
    if (raw == null) return null;

    final savedAtMillis = _prefs.getInt('$_stampPrefix$key') ?? 0;

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) return null;

      return CachedDocument(
        json: Map<String, dynamic>.from(decoded),
        savedAt: DateTime.fromMillisecondsSinceEpoch(savedAtMillis),
      );
    } on FormatException {
      return null;
    }
  }

  /// Reads a cached list, e.g. a page of creators or bookings.
  List<Map<String, dynamic>>? readList(String key) {
    final document = readDocument(key);
    final items = document?.json['items'];
    if (items is! List) return null;

    return items
        .whereType<Map>()
        .map(Map<String, dynamic>.from)
        .toList(growable: false);
  }

  Future<void> writeDocument(String key, Map<String, dynamic> json) async {
    await _prefs.setString('$_documentPrefix$key', jsonEncode(json));
    await _prefs.setInt(
      '$_stampPrefix$key',
      DateTime.now().millisecondsSinceEpoch,
    );
  }

  Future<void> writeList(String key, List<Map<String, dynamic>> items) =>
      writeDocument(key, {'items': items});

  Future<void> remove(String key) async {
    await _prefs.remove('$_documentPrefix$key');
    await _prefs.remove('$_stampPrefix$key');
  }

  /// Drops every cached document whose key starts with [namespace], e.g.
  /// `clearNamespace('bookings')` after a booking is cancelled.
  Future<void> clearNamespace(String namespace) async {
    final prefix = '$_documentPrefix$namespace';
    final keys = _prefs.getKeys().where((key) => key.startsWith(prefix)).toList();

    for (final key in keys) {
      await _prefs.remove(key);
      await _prefs.remove(key.replaceFirst(_documentPrefix, _stampPrefix));
    }
  }

  /// Wipes every cached response. Runs on sign-out.
  Future<void> clearAll() async {
    final keys = _prefs
        .getKeys()
        .where((key) =>
            key.startsWith(_documentPrefix) || key.startsWith(_stampPrefix))
        .toList();

    for (final key in keys) {
      await _prefs.remove(key);
    }
  }

  // -- App-shell preferences ----------------------------------------------

  static const _onboardingKey = 'zuste.onboarding_complete';
  static const _themeModeKey = 'zuste.theme_mode';
  static const _localeKey = 'zuste.locale';
  static const _cityKey = 'zuste.city';
  static const _recentSearchKey = 'zuste.recent_searches';
  static const _deviceIdKey = 'zuste.device_id';

  /// A stable id for this install, used to key `user_devices` server-side.
  ///
  /// Random rather than derived from hardware: ANDROID_ID is no longer a device
  /// identifier and `identifierForVendor` resets on reinstall, so a value we own
  /// is both steadier and less personal. It deliberately survives [clearAll] —
  /// it identifies the device, not the account, and the server upsert is keyed
  /// on (user_id, device_id).
  String get deviceId => _prefs.getString(_deviceIdKey) ?? '';

  Future<String> ensureDeviceId() async {
    final existing = deviceId;
    if (existing.isNotEmpty) return existing;

    final random = Random.secure();
    final id = List.generate(16, (_) => random.nextInt(256))
        .map((byte) => byte.toRadixString(16).padLeft(2, '0'))
        .join();

    await _prefs.setString(_deviceIdKey, id);
    return id;
  }

  bool get hasCompletedOnboarding => _prefs.getBool(_onboardingKey) ?? false;

  Future<void> setOnboardingComplete() =>
      _prefs.setBool(_onboardingKey, true);

  /// One of `system`, `light`, `dark`.
  String get themeModeName => _prefs.getString(_themeModeKey) ?? 'system';

  Future<void> setThemeModeName(String name) =>
      _prefs.setString(_themeModeKey, name);

  String get localeCode => _prefs.getString(_localeKey) ?? 'en';

  Future<void> setLocaleCode(String code) => _prefs.setString(_localeKey, code);

  /// The city chosen in the header selector, biasing discovery. Empty until the
  /// user picks one or GPS detection succeeds.
  String get cityName => _prefs.getString(_cityKey) ?? '';

  Future<void> setCityName(String name) => name.isEmpty
      ? _prefs.remove(_cityKey)
      : _prefs.setString(_cityKey, name);

  /// Locally-kept search terms so the Search screen has something to show
  /// before `/search/suggestions` answers, and while offline.
  List<String> get recentSearches =>
      _prefs.getStringList(_recentSearchKey) ?? const [];

  Future<void> addRecentSearch(String term) async {
    final trimmed = term.trim();
    if (trimmed.length < 2) return;

    final next = <String>[
      trimmed,
      ...recentSearches.where(
        (existing) => existing.toLowerCase() != trimmed.toLowerCase(),
      ),
    ].take(10).toList();

    await _prefs.setStringList(_recentSearchKey, next);
  }

  Future<void> clearRecentSearches() => _prefs.remove(_recentSearchKey);
}

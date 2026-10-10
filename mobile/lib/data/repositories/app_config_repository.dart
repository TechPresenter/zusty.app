import 'package:flutter/foundation.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_endpoints.dart';
import '../../core/network/api_failure.dart';
import '../../core/storage/key_value_cache.dart';
import '../../core/utils/json.dart';
import '../../models/auth_config.dart';
import '../../models/menu.dart';

/// Reads the server-driven app configuration, of which the authentication
/// block is the part that decides what the login screen looks like.
///
/// Three layers, same shape as [TaxonomyRepository]:
///
///   1. **Network** — `/app/config`, sent with the version we hold as
///      `If-None-Match`. Unchanged config answers 304 with no body, so
///      re-checking on every resume costs almost nothing.
///   2. **Device cache** — the last config that arrived, so the login screen is
///      correct on a cold start with no connection.
///   3. **Compiled fallback** — email and password only. Deliberately minimal:
///      if the app has never reached the server it cannot know which providers
///      the operator has configured, and offering a Google button that has no
///      client ID would fail confusingly.
///
/// Deliberately NOT a [BaseRepository]: that class falls back to bundled
/// fixtures on transport failures, which for a config that gates authentication
/// would mean showing sign-in options the server may have switched off.
class AppConfigRepository {
  AppConfigRepository(this._client, this._cache);

  final ApiClient _client;
  final KeyValueCache _cache;

  /// Kept out of the namespace that `clearAll()` wipes on sign-out — otherwise
  /// the login screen the user lands on right after logging out would be the
  /// compiled fallback rather than the operator's real configuration.
  static const _cacheKey = 'app.auth_config';

  /// The whole `/app/config` document, cached separately from the auth block.
  ///
  /// Unlike the auth block this one IS wiped on sign-out, because the menus,
  /// feature flags and popups it holds are resolved for the account that
  /// fetched them — keeping them across a sign-out would show the next person
  /// the previous account's drawer.
  static const _documentKey = 'app.remote_config';

  /// The last config read this session, so widgets can rebuild without I/O.
  AuthConfig? _memo;

  /// The last full document read this session.
  Map<String, dynamic>? _document;

  AuthConfig get current => _memo ?? _readCache() ?? _fallback;

  /// The admin's navigation menus for this account, keyed by location.
  ///
  /// Empty until a config has been fetched, and empty for any location the
  /// admin has not built — the caller falls back to its own layout rather than
  /// rendering nothing.
  Map<String, List<MenuEntry>> get menus {
    final raw = (_document ?? _readDocument())?['menus'];
    if (raw is! Map) return const {};

    return raw.map(
      (location, items) => MapEntry(
        location as String,
        items is List
            ? items
                .whereType<Map>()
                .map((item) => MenuEntry.fromJson(item.cast<String, dynamic>()))
                .toList()
            : const <MenuEntry>[],
      ),
    );
  }

  Future<AuthConfig> load({bool forceRefresh = false}) async {
    final cached = _readCache();

    try {
      // Deliberately NOT skipAuth: the response is resolved by audience, so a
      // signed-in caller has to be recognised or they get the guest menu and
      // no creator- or brand-targeted flag ever reaches them. The endpoint is
      // `auth.optional`, so this is still safe before sign-in.
      final response = await _client.get(
        ApiEndpoints.appConfig,
        headers: forceRefresh || cached == null || cached.version.isEmpty
            ? null
            : {'If-None-Match': '"${cached.version}"'},
      );

      if (response.isNotModified && cached != null) {
        _memo = cached;
        return cached;
      }

      // Held whole, because `auth` is only one of several server-driven blocks
      // — the menus, feature flags and popups arrive in the same payload and
      // used to be dropped on the floor here.
      _document = response.dataMap;
      await _cache.writeDocument(_documentKey, response.dataMap);

      final auth = (response.dataMap['auth'] as Map?)?.cast<String, dynamic>();

      // A server that answered without an auth block is running an older build.
      // Keeping what we have beats blanking the login screen.
      if (auth == null || auth.isEmpty) {
        return _memo = cached ?? _fallback;
      }

      final config = AuthConfig.fromJson(auth);

      if (config.isEmpty) {
        return _memo = cached ?? _fallback;
      }

      await _cache.writeDocument(_cacheKey, config.toJson());
      return _memo = config;
    } on ApiFailure catch (e) {
      debugPrint('[Zusty] app config unavailable (${e.code}); using cache.');
      return _memo = cached ?? _fallback;
    } catch (e) {
      debugPrint('[Zusty] app config failed: $e');
      return _memo = cached ?? _fallback;
    }
  }

  AuthConfig? _readCache() {
    final document = _cache.readDocument(_cacheKey);
    if (document == null) return null;

    try {
      final config = AuthConfig.fromJson(document.json);
      return config.isEmpty ? null : config;
    } catch (_) {
      // A corrupt cache must never keep anyone from signing in.
      return null;
    }
  }

  Map<String, dynamic>? _readDocument() {
    final document = _cache.readDocument(_documentKey);
    if (document == null) return null;

    try {
      return _document = document.json;
    } catch (_) {
      return null;
    }
  }

  /// Email and password only — the one method that needs no provider
  /// configuration and therefore cannot be broken by a missing credential.
  static const _fallback = AuthConfig(
    methods: [
      AuthMethod(
        key: 'email_password',
        enabled: true,
        label: 'Email or phone',
        order: 10,
        raw: {
          'key': 'email_password',
          'enabled': true,
          'identifier_mode': 'email_or_phone',
          'password_min_length': 8,
          'forgot_password_enabled': true,
        },
      ),
    ],
  );

  /// Whether the Google Play listing is public: `GET /settings` →
  /// `settings.app.android_store_live` (Admin → Settings → App → "Play
  /// listing is live").
  ///
  /// The store URL is also the update link, so it is filled in long before
  /// the listing is public, and Google Play answers it with 404 until then.
  /// Anything short of the server saying "live" — the switch off, a backend
  /// without the setting, no connection — is therefore `false`: the website
  /// always works, a dead store link never helps anyone.
  Future<bool> isStoreListingLive() async {
    try {
      final response = await _client.get(ApiEndpoints.settings, skipAuth: true);
      final app = response.mapAt('settings')['app'];

      return app is Map && Json.boolean(app['android_store_live']);
    } catch (e) {
      debugPrint('[Zusty] public settings unavailable: $e');
      return false;
    }
  }

  Future<void> clear() => _cache.remove(_cacheKey);

  /// Drops the account-scoped part of the config, leaving the auth block so the
  /// login screen the user lands on is still the operator's real one.
  ///
  /// Called on sign-out: menus, flags and popups were resolved for the account
  /// that has just left, and the next person on this device must not inherit
  /// them.
  Future<void> clearAccountScoped() async {
    _document = null;
    await _cache.remove(_documentKey);
  }
}

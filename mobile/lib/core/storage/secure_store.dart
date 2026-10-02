import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// The JWT pair issued by `POST /auth/login`, `/auth/register`,
/// `/auth/firebase` and rotated by `POST /auth/refresh`.
class AuthTokens {
  const AuthTokens({
    required this.accessToken,
    required this.refreshToken,
    this.expiresAt,
  });

  /// Reads the `tokens` block the backend returns. Tolerates the block being
  /// inlined at the top level of `data`, which `/auth/refresh` does.
  static AuthTokens? tryParse(Map<String, dynamic>? json) {
    if (json == null) return null;

    final access = json['access_token']?.toString();
    final refresh = json['refresh_token']?.toString();
    if (access == null || access.isEmpty || refresh == null || refresh.isEmpty) {
      return null;
    }

    return AuthTokens(
      accessToken: access,
      refreshToken: refresh,
      expiresAt: DateTime.tryParse(json['expires_at']?.toString() ?? '')?.toLocal(),
    );
  }

  final String accessToken;
  final String refreshToken;
  final DateTime? expiresAt;
}

/// Secrets that must never touch SharedPreferences.
///
/// Backed by the Android keystore, the iOS keychain and, on Flutter Web, a
/// WebCrypto-wrapped localStorage entry — so the same API works everywhere.
///
/// The in-memory cache keeps the hot path (attaching a bearer token to every
/// request) off the platform channel.
class SecureStore {
  SecureStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              iOptions: IOSOptions(
                accessibility: KeychainAccessibility.first_unlock,
              ),
            );

  final FlutterSecureStorage _storage;

  static const _accessKey = 'zuste.access_token';
  static const _refreshKey = 'zuste.refresh_token';
  static const _expiryKey = 'zuste.access_expires_at';

  String? _cachedAccess;
  String? _cachedRefresh;
  bool _loaded = false;

  Future<void> _ensureLoaded() async {
    if (_loaded) return;

    try {
      _cachedAccess = await _storage.read(key: _accessKey);
      _cachedRefresh = await _storage.read(key: _refreshKey);
    } on Object catch (error) {
      // A keystore that is locked, corrupt, or unavailable means we have no
      // token — not that the request should fail. Letting this throw would
      // surface inside the Dio interceptor and be reported to the user as a
      // network error, which is both wrong and unactionable.
      debugPrint('[Zusty] Secure storage unavailable: $error');
      _cachedAccess = null;
      _cachedRefresh = null;
    }

    _loaded = true;
  }

  Future<String?> readAccessToken() async {
    await _ensureLoaded();
    return _cachedAccess;
  }

  Future<String?> readRefreshToken() async {
    await _ensureLoaded();
    return _cachedRefresh;
  }

  Future<bool> hasSession() async => (await readAccessToken()) != null;

  Future<void> save(AuthTokens tokens) async {
    _cachedAccess = tokens.accessToken;
    _cachedRefresh = tokens.refreshToken;
    _loaded = true;

    await Future.wait([
      _storage.write(key: _accessKey, value: tokens.accessToken),
      _storage.write(key: _refreshKey, value: tokens.refreshToken),
      _storage.write(
        key: _expiryKey,
        value: tokens.expiresAt?.toIso8601String() ?? '',
      ),
    ]);
  }

  Future<void> clear() async {
    _cachedAccess = null;
    _cachedRefresh = null;
    _loaded = true;

    await Future.wait([
      _storage.delete(key: _accessKey),
      _storage.delete(key: _refreshKey),
      _storage.delete(key: _expiryKey),
    ]);
  }
}

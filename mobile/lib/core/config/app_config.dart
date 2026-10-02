/// Build-time configuration.
///
/// Every value here comes from a compile-time environment so a single codebase
/// can target development, staging and production without a code change.
///
/// Point the app at a different backend like this:
/// ```
/// flutter run     --dart-define=ZUSTE_API_ORIGIN=https://api.zuste.com
/// flutter build apk --dart-define=ZUSTE_API_ORIGIN=https://api.zuste.com
/// flutter build web --dart-define=ZUSTE_API_ORIGIN=https://api.zuste.com
/// ```
/// The full base URL becomes `{ZUSTE_API_ORIGIN}/api/{ZUSTE_API_VERSION}`,
/// which resolves to `http://localhost:8000/api/v1` by default.
abstract final class AppConfig {
  static const String appName = 'Zusty';
  static const String tagline = 'Shoot. Connect. Grow.';

  /// Origin of the Core PHP REST API — scheme + host + optional port, no path.
  static const String apiOrigin = String.fromEnvironment(
    'ZUSTE_API_ORIGIN',
    defaultValue: 'http://localhost:8000',
  );

  static const String apiVersion = String.fromEnvironment(
    'ZUSTE_API_VERSION',
    defaultValue: 'v1',
  );

  /// The Dio base URL: `http://localhost:8000/api/v1` in development.
  static const String apiBaseUrl = '$apiOrigin/api/$apiVersion';

  /// A loadable URL for server media.
  ///
  /// Everything the backend stores comes back rooted at its origin
  /// (`/uploads/...`, from UPLOAD_URL); seeded and third-party media is
  /// already absolute. An image widget handed the rooted form has no host to
  /// fetch from, so every upload would render as a fallback.
  static String resolveMediaUrl(String url) =>
      url.startsWith('/') && !url.startsWith('//') ? '$apiOrigin$url' : url;

  /// Emits request/response logs. Defaults to on in debug builds only; the
  /// network layer additionally gates this behind [kDebugMode].
  static const bool verboseNetworkLogging = bool.fromEnvironment(
    'ZUSTE_VERBOSE_NETWORK',
    defaultValue: true,
  );

  static const Duration connectTimeout = Duration(seconds: 20);
  static const Duration receiveTimeout = Duration(seconds: 30);
  static const Duration sendTimeout = Duration(seconds: 30);

  /// AI writing and matching wait on a model, which can take far longer than
  /// an ordinary request; the server's own provider timeout is 60 seconds.
  static const Duration aiReceiveTimeout = Duration(seconds: 90);

  /// The backend exposes polling endpoints for chat rather than sockets.
  static const Duration chatPollInterval = Duration(seconds: 5);

  /// How long a typing indicator stays visible before it is assumed stale.
  static const Duration typingTimeout = Duration(seconds: 6);

  /// Default page size for infinite lists. The API caps most endpoints at 50.
  static const int defaultPageSize = 20;

  /// How long a cached list stays fresh before a read-through refetch.
  static const Duration cacheTtl = Duration(hours: 6);

  static const String currencyCode = 'INR';
  static const String currencySymbol = '₹';

  /// Deep-link host used by referral invites and push payloads.
  static const String deepLinkHost = String.fromEnvironment(
    'ZUSTE_DEEP_LINK_HOST',
    defaultValue: 'zuste.app',
  );
}

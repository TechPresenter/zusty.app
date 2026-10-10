import 'package:flutter/foundation.dart';

/// Build-time configuration.
///
/// Every value here comes from a compile-time environment so a single codebase
/// can target development, staging and production without a code change.
///
/// Point the app at a different backend like this:
/// ```
/// flutter run     --dart-define=ZUSTE_API_ORIGIN=http://localhost:8000
/// flutter build apk --dart-define=ZUSTE_API_ORIGIN=https://staging.zusty.app
/// flutter build web --dart-define=ZUSTE_API_ORIGIN=http://localhost:8000
/// ```
/// The full base URL becomes `{ZUSTE_API_ORIGIN}/api/{ZUSTE_API_VERSION}`.
/// With no define it resolves to `https://zusty.app/api/v1` in a release build
/// and `http://localhost:8000/api/v1` in a debug or profile build, so a store
/// build made without the flags still reaches production rather than a
/// cleartext localhost Android refuses to talk to.
abstract final class AppConfig {
  static const String appName = 'Zusty';
  static const String tagline = 'Shoot. Connect. Grow.';

  /// The production API, used by a release build unless told otherwise.
  static const String productionOrigin = 'https://zusty.app';

  /// Origin of the Core PHP REST API — scheme + host + optional port, no path.
  static const String apiOrigin = String.fromEnvironment(
    'ZUSTE_API_ORIGIN',
    defaultValue: kReleaseMode ? productionOrigin : 'http://localhost:8000',
  );

  /// Whether [apiOrigin] is safe to ship to a phone: TLS, which Android and
  /// iOS require of every host by default. A release build with any other
  /// origin cannot reach its API at all, so `main()` refuses to start it.
  static bool get usesSecureOrigin => apiOrigin.startsWith('https://');

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

  /// Emits request/response logs. Defaults to off in a release build; the
  /// network layer additionally gates this behind [kDebugMode].
  static const bool verboseNetworkLogging = bool.fromEnvironment(
    'ZUSTE_VERBOSE_NETWORK',
    defaultValue: !kReleaseMode,
  );

  static const Duration connectTimeout = Duration(seconds: 20);
  static const Duration receiveTimeout = Duration(seconds: 30);
  static const Duration sendTimeout = Duration(seconds: 30);

  /// How long a multipart upload may take to send its body.
  ///
  /// Dio's send timeout bounds the whole request body, and a portfolio video
  /// may be up to 50 MB (upload.max_video_mb). At a typical mobile uplink of
  /// 1–2 Mbit/s that is several minutes, which the ordinary 30 seconds would
  /// cut off every time.
  static const Duration uploadSendTimeout = Duration(minutes: 10);

  /// AI writing and matching wait on a model, which can take far longer than
  /// an ordinary request; the server's own provider timeout is 60 seconds.
  static const Duration aiReceiveTimeout = Duration(seconds: 90);

  /// Requests the server finishes while the user waits and that must not be
  /// abandoned half way: building a data export, erasing an account. At
  /// least the server's own time budget for them, so the app never reports a
  /// failure for work that is still running.
  static const Duration slowRequestTimeout = Duration(seconds: 120);

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
    defaultValue: 'zusty.app',
  );

  /// The public website, used when the app shares a link of its own (the
  /// store listing is preferred when the server has configured one).
  static const String websiteUrl = 'https://$deepLinkHost';
}

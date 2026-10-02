import 'package:device_info_plus/device_info_plus.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../storage/key_value_cache.dart';

/// Firebase Cloud Messaging, reduced to what this app needs: a token, and the
/// body `POST /me/devices` expects.
///
/// It deliberately never calls `Firebase.initializeApp` — that is
/// [SocialSignInService.ensureFirebase]'s single responsibility, and it is what
/// keeps the Firebase options server-driven. A second initialiser is how a
/// project silently ends up pinned to two different Firebase projects.
///
/// `dart:io` is intentionally not imported: it breaks the web build. Platform
/// questions are answered with [kIsWeb] and [defaultTargetPlatform].
class PushService {
  const PushService(this._cache);

  final KeyValueCache _cache;

  /// Permission plus a registration token, or null for every "this device
  /// cannot do push" case — permission refused, APNS not ready yet, or web
  /// without a VAPID key. None of those is an error worth showing anyone.
  Future<String?> token({String? vapidKey}) async {
    if (kIsWeb && (vapidKey == null || vapidKey.isEmpty)) {
      debugPrint('[Zusty] Web push needs a VAPID key; none is configured.');
      return null;
    }

    final messaging = FirebaseMessaging.instance;

    final settings = await messaging.requestPermission();
    if (settings.authorizationStatus == AuthorizationStatus.denied) {
      return null;
    }

    // iOS only issues an FCM token once APNS has answered; asking sooner
    // returns null rather than throwing.
    if (!kIsWeb && defaultTargetPlatform == TargetPlatform.iOS) {
      if (await messaging.getAPNSToken() == null) return null;
    }

    return messaging.getToken(vapidKey: kIsWeb ? vapidKey : null);
  }

  Stream<String> get onTokenRefresh => FirebaseMessaging.instance.onTokenRefresh;

  Future<void> deleteToken() => FirebaseMessaging.instance.deleteToken();

  /// The exact body `POST /me/devices` reads.
  ///
  /// The keys are the server's own — `BaseApiController::deviceContext` reads a
  /// fixed list and silently drops anything else, so a misspelled key here is a
  /// 200 that did nothing rather than a 422. In particular the token field is
  /// `fcm_token`, not `token`. `device_name` is read by the server but has no
  /// column, so it is left out.
  Future<Map<String, dynamic>> devicePayload(String fcmToken) async {
    final info = await PackageInfo.fromPlatform();
    final described = await _describe();

    return {
      'device_id': await _cache.ensureDeviceId(),
      'fcm_token': fcmToken,
      'platform': _platform,
      'device_model': described.model,
      'os_version': described.osVersion,
      'app_version': '${info.version}+${info.buildNumber}',
      'locale': _cache.localeCode,
    };
  }

  /// Only the three values the server stores a column for.
  static String get _platform {
    if (kIsWeb) return 'web';

    return switch (defaultTargetPlatform) {
      TargetPlatform.android => 'android',
      TargetPlatform.iOS => 'ios',
      _ => 'android',
    };
  }

  Future<_DeviceDescription> _describe() async {
    final plugin = DeviceInfoPlugin();

    try {
      if (kIsWeb) {
        final web = await plugin.webBrowserInfo;
        return _DeviceDescription(
          model: web.browserName.name,
          osVersion: web.platform ?? '',
        );
      }

      switch (defaultTargetPlatform) {
        case TargetPlatform.android:
          final android = await plugin.androidInfo;
          return _DeviceDescription(
            model: '${android.manufacturer} ${android.model}',
            osVersion: 'Android ${android.version.release}',
          );
        case TargetPlatform.iOS:
          final ios = await plugin.iosInfo;
          return _DeviceDescription(
            model: ios.utsname.machine,
            osVersion: '${ios.systemName} ${ios.systemVersion}',
          );
        default:
          return const _DeviceDescription(model: '', osVersion: '');
      }
    } catch (error) {
      // Describing the handset is a nicety; never let it cost a registration.
      debugPrint('[Zusty] Could not read device info: $error');
      return const _DeviceDescription(model: '', osVersion: '');
    }
  }
}

class _DeviceDescription {
  const _DeviceDescription({required this.model, required this.osVersion});

  final String model;
  final String osVersion;
}

import 'dart:ui';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_crashlytics/firebase_crashlytics.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

/// What happens to an error nothing else caught.
///
/// Without this, an uncaught async error vanished, a widget that threw while
/// building left a grey box in a release build, and nothing ever reached
/// Crashlytics even though the plugin ships in the app.
///
/// Crashlytics only starts once Firebase does, and Firebase is configured from
/// the server (`SocialSignInService.ensureFirebase`), so reporting is switched
/// on from there through [onFirebaseReady] — and only in a release build. The
/// manifest keeps native collection off until then, so a debug build never
/// sends a report. The privacy policy lists crash reports under technical
/// data; a build that stops reporting should drop that line with the plugin.
abstract final class CrashReporting {
  static bool _reporting = false;

  /// True once a release build has switched Crashlytics on.
  static bool get _canReport => _reporting && Firebase.apps.isNotEmpty;

  /// Installs the handlers. Call once, at the top of `main()`.
  static void install() {
    FlutterError.onError = (details) {
      FlutterError.presentError(details);
      if (_canReport) {
        FirebaseCrashlytics.instance.recordFlutterFatalError(details);
      }
    };

    PlatformDispatcher.instance.onError = (error, stack) {
      if (_canReport) {
        FirebaseCrashlytics.instance.recordError(error, stack, fatal: true);
      } else {
        debugPrint('[Zusty] Uncaught error: $error\n$stack');
      }
      // Handled: the error is reported, and the app keeps running.
      return true;
    };

    // A debug build keeps Flutter's red error screen, which says what broke.
    if (kReleaseMode) {
      ErrorWidget.builder = (_) => const ReleaseErrorWidget();
    }
  }

  /// Switches reporting on (release) or off (debug and profile) once Firebase
  /// has started. Safe to call more than once; never throws.
  static Future<void> onFirebaseReady() async {
    // Crashlytics has no web implementation.
    if (kIsWeb) return;

    try {
      await FirebaseCrashlytics.instance.setCrashlyticsCollectionEnabled(
        kReleaseMode,
      );
      _reporting = kReleaseMode;
    } catch (error) {
      debugPrint('[Zusty] Crash reporting unavailable: $error');
    }
  }
}

/// Stands in for a widget that threw while building, in a release build.
///
/// Deliberately plain: it may be drawn anywhere, even above the app's own
/// theme, so it depends on nothing but its own text style.
class ReleaseErrorWidget extends StatelessWidget {
  const ReleaseErrorWidget({super.key});

  @override
  Widget build(BuildContext context) {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(16),
        child: Text(
          'Something went wrong. Please go back and try again.',
          textAlign: TextAlign.center,
          textDirection: TextDirection.ltr,
          style: TextStyle(
            color: Color(0xFF6B6B76),
            fontSize: 14,
            decoration: TextDecoration.none,
          ),
        ),
      ),
    );
  }
}

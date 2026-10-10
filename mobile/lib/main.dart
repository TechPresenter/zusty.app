import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'core/auth/social_sign_in_service.dart';
import 'core/config/app_config.dart';
import 'core/diagnostics/crash_reporting.dart';
import 'core/network/device_headers.dart';
import 'core/storage/key_value_cache.dart';
import 'features/kyc/kyc_share_cache.dart';
// The feature-level barrel: re-exports core/providers and adds the repository
// providers, of which appConfigRepositoryProvider is one.
import 'providers/core_providers.dart';

Future<void> main() async {
  // A release build writes nothing to the device log. The messages are error
  // text for developers, and an error string can carry a server URL.
  if (kReleaseMode) debugPrint = (String? message, {int? wrapWidth}) {};

  // A phone cannot talk to a cleartext API (Android and iOS block it by
  // default), so a store build pointed anywhere but https would install and
  // then fail every request. Fail here instead, where a pre-launch test sees
  // it. The web build is exempt: the browser applies its own mixed-content
  // rules, and local QA serves a release web build over plain http.
  if (kReleaseMode && !kIsWeb && !AppConfig.usesSecureOrigin) {
    throw StateError(
      'Release build must use an https API origin '
      '(ZUSTE_API_ORIGIN=${AppConfig.apiOrigin}).',
    );
  }

  WidgetsFlutterBinding.ensureInitialized();

  // Uncaught errors are reported once Firebase is up, and a widget that fails
  // to build shows a short message in a release build instead of a grey box.
  CrashReporting.install();

  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.dark,
      statusBarBrightness: Brightness.light,
    ),
  );

  // A decrypted verification document handed to another app leaves a copy in
  // share_plus's cache folder that cannot be deleted while that app may still
  // be reading it. Whatever is left from last time goes now — in the
  // background, so it never holds up the first frame.
  unawaited(KycShareCache.purge());

  // SharedPreferences opens asynchronously, so the cache is created here and
  // injected — a second instance would split the stored cache.
  final cache = await KeyValueCache.open();

  // Start Firebase from the options the server is serving, before the first
  // frame, so a social sign-in button is usable the moment it is tapped.
  //
  // The cached config is used rather than a fresh fetch: this runs on the
  // critical path to first paint, and a slow network must not hold the splash
  // screen. A config that has changed is picked up by the refresh the app does
  // on start and on every resume; on a first launch, with nothing cached, push
  // registration and the sign-in buttons start Firebase themselves once the
  // config has arrived.
  //
  // This is only possible because the project ships no google-services.json or
  // GoogleService-Info.plist. Were either present, the native SDK would create
  // a default app from the bundled file before Dart ran and these options would
  // become an override rather than the source of truth.
  final container = ProviderContainer(
    overrides: [keyValueCacheProvider.overrideWithValue(cache)],
  );

  // Every request carries this install's id and version, which the server
  // uses for the minimum-version gate, version-targeted feature flags and
  // signing this device out. Before the first request, so /app/config is
  // already answered for the right version.
  try {
    await applyDeviceHeaders(container.read(apiClientProvider), cache);
  } catch (error) {
    // Never fatal: without the headers the server assumes the oldest version.
    debugPrint('[Zusty] Device headers skipped: $error');
  }

  try {
    final config = container.read(appConfigRepositoryProvider).current;

    if (config.firebase.isUsable) {
      await SocialSignInService.ensureFirebase(config.firebase);
    }
  } catch (error) {
    // Never fatal. Email/password and OTP need no Firebase at all, and an app
    // that will not boot is far worse than one missing a social button.
    debugPrint('[Zusty] Firebase bootstrap skipped: $error');
  }

  runApp(
    UncontrolledProviderScope(
      container: container,
      child: const ZusteApp(),
    ),
  );
}

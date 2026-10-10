import 'package:package_info_plus/package_info_plus.dart';

import '../storage/key_value_cache.dart';
import 'api_client.dart';

/// Puts this install's id and version on every request the [client] sends.
///
/// Run once from `main()`, before the first request. Without it the server
/// sees no `X-App-Version` at all and treats every install as 1.0.0: raising
/// the minimum version in the panel would then force-update every phone,
/// including ones already on the new release, and feature flags with a
/// minimum app version would never apply. Without `X-Device-Id`, a sign-out
/// cannot revoke the refresh token issued to this device.
///
/// The version is the store version alone ("1.0.1"), not "1.0.1+2": the
/// server compares it as a dotted version against the per-platform minimum.
/// [installedVersion] exists for tests; the app reads the real one.
Future<void> applyDeviceHeaders(
  ApiClient client,
  KeyValueCache cache, {
  Future<String> Function()? installedVersion,
}) async {
  final version = await (installedVersion ?? _installedVersion)();

  client.setDeviceHeaders(
    deviceId: await cache.ensureDeviceId(),
    appVersion: version,
  );
}

Future<String> _installedVersion() async =>
    (await PackageInfo.fromPlatform()).version;

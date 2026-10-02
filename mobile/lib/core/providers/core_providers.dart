import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../messaging/push_service.dart';
import '../network/api_client.dart';
import '../network/connectivity_service.dart';
import '../storage/key_value_cache.dart';
import '../storage/secure_store.dart';

/// The durable key/value cache.
///
/// [KeyValueCache.open] is async, so `main()` opens it and overrides this
/// provider. Reading it without that override throws on purpose — a silent
/// second SharedPreferences instance would split the cache.
final keyValueCacheProvider = Provider<KeyValueCache>(
  (ref) => throw StateError(
    'keyValueCacheProvider must be overridden in main() with the instance '
    'returned by KeyValueCache.open().',
  ),
);

/// Keystore/keychain-backed storage for the JWT pair.
final secureStoreProvider = Provider<SecureStore>((ref) => SecureStore());

final connectivityServiceProvider =
    Provider<ConnectivityService>((ref) => ConnectivityService());

/// Firebase Cloud Messaging. Constructing it touches no plugin — the FCM
/// plugin is only reached once `SocialSignInService.ensureFirebase` has
/// succeeded and somebody is signed in.
final pushServiceProvider = Provider<PushService>((ref) {
  return PushService(ref.watch(keyValueCacheProvider));
});

/// `true` while the device has a network route. Screens watch this to retry an
/// offline error state automatically when the connection returns.
final connectivityStatusProvider = StreamProvider<bool>((ref) {
  return ref.watch(connectivityServiceProvider).onStatusChange;
});

/// The shared, authenticated API client. Every repository depends on this one.
final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient(
    secureStore: ref.watch(secureStoreProvider),
    connectivity: ref.watch(connectivityServiceProvider),
    // Every successful GET is kept so the app can replay real data when the
    // backend is unreachable, instead of falling back to bundled fixtures.
    responseCache: ref.watch(keyValueCacheProvider),
  );
});

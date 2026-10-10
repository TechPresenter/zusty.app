import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/utils/share_links.dart';
import 'app_config_provider.dart';
import 'core_providers.dart';

/// Whether the Google Play listing is public (`app.android_store_live`, from
/// `GET /settings`). Fetched once per launch; `false` until the answer
/// arrives and whenever it cannot be had.
final storeListingLiveProvider = FutureProvider<bool>((ref) {
  return ref.watch(appConfigRepositoryProvider).isStoreListingLive();
});

/// What "Share Zusty" sends right now: the store listing once it is live,
/// the website until then — including while that answer is still on its way.
final shareAppTextProvider = Provider<String>((ref) {
  return shareAppText(
    ref.watch(updateGateProvider),
    storeLive: ref.watch(storeListingLiveProvider).valueOrNull ?? false,
  );
});

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/providers/core_providers.dart';
import '../core/storage/key_value_cache.dart';
import '../models/app_settings.dart';

/// The cache key `AppConfigRepository` writes the whole `/app/config` document
/// under, and which [remoteAppConfigProvider] reads back.
///
/// The repository already fetches, revalidates and stores the document on every
/// resume; nothing here makes a second request.
const String remoteConfigCacheKey = 'app.remote_config';

/// Everything `/app/config` delivers past the `auth` block.
///
/// Read from the cached document rather than the network: the config is already
/// refetched on resume and after sign-in, and a screen asking for a feature flag
/// must not cost a round trip. Invalidated by `AuthConfigController.refresh`,
/// which is what makes an admin change reach an installed app.
///
/// Falls back to [RemoteAppConfig.empty] before the first fetch, whose defaults
/// are deliberately "behave exactly as this build did before".
final remoteAppConfigProvider = Provider<RemoteAppConfig>((ref) {
  final document = ref.watch(keyValueCacheProvider).readDocument(
        remoteConfigCacheKey,
      );

  if (document == null) return RemoteAppConfig.empty;

  try {
    return RemoteAppConfig.fromJson(document.json);
  } catch (_) {
    // A corrupt cache must never take a screen down; the compiled defaults are
    // always a safe answer.
    return RemoteAppConfig.empty;
  }
});

/// Admin-managed app name, tagline, currency and support contacts.
final appIdentityProvider = Provider<AppIdentity>((ref) {
  return ref.watch(remoteAppConfigProvider).identity;
});

/// Admin-uploaded logos and accent colours.
final appBrandingProvider = Provider<AppBranding>((ref) {
  return ref.watch(remoteAppConfigProvider).branding;
});

/// Every feature switch the server resolved for this caller.
final featureFlagsProvider = Provider<FeatureFlags>((ref) {
  return ref.watch(remoteAppConfigProvider).features;
});

/// Which AI features to offer (Admin → Settings → AI).
final aiFeaturesProvider = Provider<AiFeatures>((ref) {
  return ref.watch(remoteAppConfigProvider).ai;
});

/// The minimum-version gate.
final updateGateProvider = Provider<UpdateGate>((ref) {
  return ref.watch(remoteAppConfigProvider).update;
});

/// Whether this build is below the operator's minimum and must be upgraded.
///
/// Deliberately a `bool` rather than the whole [UpdateGate]: the router watches
/// this, and rebuilding GoRouter on every config refresh would throw away the
/// navigation stack. A bool only notifies when the answer actually changes.
final mustUpdateProvider = Provider<bool>((ref) {
  return ref.watch(updateGateProvider).mustUpdate;
});

/// The maintenance notice, if the operator has switched one on.
final maintenanceNoticeProvider = Provider<MaintenanceNotice>((ref) {
  return ref.watch(remoteAppConfigProvider).maintenance;
});

/// Every popup the server judged eligible for this account.
final appPopupsProvider = Provider<List<AppPopup>>((ref) {
  return ref.watch(remoteAppConfigProvider).popups;
});

/// The popups that belong on one screen, highest priority first (the server
/// already ordered them).
final popupsForPlacementProvider =
    Provider.family<List<AppPopup>, String>((ref, placement) {
  return ref
      .watch(appPopupsProvider)
      .where((popup) => popup.showsOn(placement))
      .toList(growable: false);
});

/// The operator's social profiles.
final socialLinksProvider = Provider<SocialLinks>((ref) {
  return ref.watch(remoteAppConfigProvider).social;
});

/// The operator's terms and privacy URLs.
final legalLinksProvider = Provider<LegalLinks>((ref) {
  return ref.watch(remoteAppConfigProvider).legal;
});

/// Remembers which popups have already been shown on this device.
///
/// The admin picks the frequency per popup; honouring it is entirely a client
/// responsibility, so the record has to survive a restart for `once` and
/// `once_per_day` to mean anything. Kept in the same cache namespace the rest
/// of the account-scoped config uses, so signing out clears it with everything
/// else.
final popupSeenStoreProvider = Provider<PopupSeenStore>((ref) {
  return PopupSeenStore(ref.watch(keyValueCacheProvider));
});

/// Local record of popups already shown, keyed by popup id.
class PopupSeenStore {
  PopupSeenStore(this._cache);

  final KeyValueCache _cache;

  static const _seenKey = 'app.popups_seen';
  static const _sessionKey = 'app.session_count';

  /// Popups shown since this process started, for `once_per_session`.
  static final Set<int> _thisSession = <int>{};

  /// Which session this launch is, counted across installs.
  ///
  /// Resolved once per process so every popup in a session agrees on the
  /// number, which is what `session_count` triggers compare against.
  static int? _sessionNumber;

  int get sessionNumber {
    final resolved = _sessionNumber;
    if (resolved != null) return resolved;

    final stored = _cache.readDocument(_sessionKey)?.json['count'];
    final next = (stored is num ? stored.toInt() : 0) + 1;

    _sessionNumber = next;
    // Fire and forget: a lost write costs one session in the count, and
    // blocking a widget build on disk I/O for that would be worse.
    _cache.writeDocument(_sessionKey, {'count': next});

    return next;
  }

  /// True when [popup] has already been shown as often as its frequency allows.
  bool hasShown(AppPopup popup) {
    switch (popup.frequency) {
      case 'always':
        return false;
      case 'once_per_session':
        return _thisSession.contains(popup.id);
      case 'once_per_day':
        final last = _lastShown(popup.id);
        if (last == null) return false;
        final now = DateTime.now();
        return last.year == now.year &&
            last.month == now.month &&
            last.day == now.day;
      // 'once', and anything a newer panel invents — the conservative reading
      // of an unknown frequency is "do not show it twice".
      default:
        return _lastShown(popup.id) != null;
    }
  }

  Future<void> markShown(AppPopup popup) async {
    _thisSession.add(popup.id);

    final record = Map<String, dynamic>.from(
      _cache.readDocument(_seenKey)?.json ?? const <String, dynamic>{},
    );
    record['${popup.id}'] = DateTime.now().toIso8601String();

    await _cache.writeDocument(_seenKey, record);
  }

  DateTime? _lastShown(int id) {
    final raw = _cache.readDocument(_seenKey)?.json['$id'];
    return raw is String ? DateTime.tryParse(raw) : null;
  }

  /// Drops the in-memory session record. Exposed for tests, which run many
  /// popups through one process.
  static void resetSession() {
    _thisSession.clear();
    _sessionNumber = null;
  }
}

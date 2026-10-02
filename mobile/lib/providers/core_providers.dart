import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/providers/core_providers.dart';
import '../data/repositories/account_repository.dart';
import '../data/repositories/app_config_repository.dart';
import '../data/repositories/auth_repository.dart';
import '../data/repositories/booking_repository.dart';
import '../data/repositories/contact_repository.dart';
import '../data/repositories/creator_fields_repository.dart';
import '../data/repositories/creator_repository.dart';
import '../data/repositories/kyc_repository.dart';
import '../data/repositories/message_repository.dart';
import '../data/repositories/wallet_repository.dart';
import '../models/contact.dart';
import '../models/creator_fields.dart';
import '../models/auth_config.dart';
import '../models/menu.dart';
import 'app_config_provider.dart';

// The client, secure store, cache, and connectivity live in
// `core/providers/core_providers.dart`; re-exported here so feature code has a
// single import for everything it injects.
export '../core/providers/core_providers.dart';

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  return AuthRepository(
    ref.watch(apiClientProvider),
    ref.watch(secureStoreProvider),
    ref.watch(keyValueCacheProvider),
  );
});

final appConfigRepositoryProvider = Provider<AppConfigRepository>((ref) {
  return AppConfigRepository(
    ref.watch(apiClientProvider),
    ref.watch(keyValueCacheProvider),
  );
});

/// What the sign-in screens render from.
///
/// Seeded synchronously from the device cache so the first frame is already
/// correct, then refreshed from the server. A 304 makes the refresh nearly
/// free, which is what lets the app re-check on every resume and pick up an
/// admin's change without a restart.
final authConfigProvider =
    NotifierProvider<AuthConfigController, AuthConfig>(AuthConfigController.new);

class AuthConfigController extends Notifier<AuthConfig> {
  @override
  AuthConfig build() {
    Future.microtask(refresh);
    return ref.read(appConfigRepositoryProvider).current;
  }

  Future<void> refresh({bool force = false}) async {
    final config =
        await ref.read(appConfigRepositoryProvider).load(forceRefresh: force);

    // The menus arrive in the same payload, so anything watching them has to
    // be told even when the auth block is byte-identical — an admin reordering
    // the drawer does not change how anyone signs in.
    ref.invalidate(appMenusProvider);

    // Same for everything else in that payload: feature flags, popups, the
    // update gate, branding and the support contacts all ride on this one
    // fetch, and none of them move the auth version when an admin edits them.
    ref.invalidate(remoteAppConfigProvider);

    // Only replace when something actually changed, so a resume does not
    // rebuild every screen that watches this.
    if (config.version != state.version || state.isEmpty) {
      state = config;
    }
  }
}

/// The admin-built menus for the signed-in account, keyed by location.
///
/// Reads whatever the last `/app/config` fetch delivered rather than making a
/// call of its own — the config is already refetched on resume and after
/// sign-in, and the drawer should not cost a second round trip.
final appMenusProvider = Provider<Map<String, List<MenuEntry>>>((ref) {
  return ref.watch(appConfigRepositoryProvider).menus;
});

/// The app drawer, the only location the mobile client renders.
final appDrawerMenuProvider = Provider<List<MenuEntry>>((ref) {
  return ref.watch(appMenusProvider)['app_drawer'] ?? const [];
});

final creatorRepositoryProvider = Provider<CreatorRepository>((ref) {
  return CreatorRepository(ref.watch(apiClientProvider));
});

final bookingRepositoryProvider = Provider<BookingRepository>((ref) {
  return BookingRepository(ref.watch(apiClientProvider));
});

final contactRepositoryProvider = Provider<ContactRepository>((ref) {
  return ContactRepository(ref.watch(apiClientProvider));
});

final creatorFieldsRepositoryProvider =
    Provider<CreatorFieldsRepository>((ref) {
  return CreatorFieldsRepository(ref.watch(apiClientProvider));
});

/// The creator's own editable extended profile.
///
/// Separate from `creatorFieldsProvider`, which serves the PUBLIC view of any
/// creator: this one is always "me" and reflects edits immediately, while that
/// one is keyed by id and cached for browsing.
final myCreatorFieldsProvider =
    AsyncNotifierProvider.autoDispose<MyCreatorFieldsController, CreatorFields>(
  MyCreatorFieldsController.new,
);

class MyCreatorFieldsController
    extends AutoDisposeAsyncNotifier<CreatorFields> {
  @override
  Future<CreatorFields> build() =>
      ref.watch(creatorFieldsRepositoryProvider).load();

  /// Run a write, then reload.
  ///
  /// The write endpoints return the single row they touched rather than the
  /// whole profile, and a section's ordering and completeness depend on its
  /// siblings — so a reload is the honest way to reflect what actually
  /// changed. Errors propagate so the screen can show the server's message.
  Future<void> mutate(Future<void> Function() action) async {
    await action();

    state = await AsyncValue.guard(
      () => ref.read(creatorFieldsRepositoryProvider).load(),
    );
  }
}

/// The account's contact points, alternates, pending changes and the
/// operator's current policy.
///
/// Every mutation returns the refreshed book, so callers write the result back
/// with [ContactBookController.set] rather than refetching — a contact change
/// is a sequence of steps and an extra round trip between each one is visible.
final contactBookProvider =
    AsyncNotifierProvider.autoDispose<ContactBookController, ContactBook>(
  ContactBookController.new,
);

class ContactBookController extends AutoDisposeAsyncNotifier<ContactBook> {
  @override
  Future<ContactBook> build() =>
      ref.watch(contactRepositoryProvider).load();

  /// Adopt a book the server just returned from a mutation.
  void set(ContactBook book) => state = AsyncData(book);

  Future<void> refresh() async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(
      () => ref.read(contactRepositoryProvider).load(),
    );
  }
}

final walletRepositoryProvider = Provider<WalletRepository>((ref) {
  return WalletRepository(ref.watch(apiClientProvider));
});

final kycRepositoryProvider = Provider<KycRepository>((ref) {
  return KycRepository(ref.watch(apiClientProvider));
});

final messageRepositoryProvider = Provider<MessageRepository>((ref) {
  return MessageRepository(ref.watch(apiClientProvider));
});

final accountRepositoryProvider = Provider<AccountRepository>((ref) {
  return AccountRepository(ref.watch(apiClientProvider));
});

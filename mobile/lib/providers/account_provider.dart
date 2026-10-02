import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/network/api_response.dart';
import '../models/account_settings.dart';
import '../models/notification.dart';
import '../models/support.dart';
import '../models/user.dart';
import 'auth_provider.dart';
import 'core_providers.dart';

// -- Notifications --------------------------------------------------------

final notificationsProvider =
    FutureProvider.autoDispose<Paginated<AppNotification>>((ref) {
      return ref.watch(accountRepositoryProvider).notifications();
    });

/// The Notifications screen's filter pills, each a set of the server's
/// `category` values.
///
/// Payments takes in wallet events and Updates takes in promotions: to the
/// person reading, a refund and a top-up are both "money", and a platform
/// notice and an offer are both "from Zusty". Campaign events need no pill
/// of their own — the server files them under `booking`.
enum NotificationFilter {
  all('All', []),
  bookings('Bookings', ['booking']),
  payments('Payments', ['payment', 'wallet']),
  messages('Messages', ['message']),
  reviews('Reviews', ['review']),
  account('Account', ['account']),
  updates('Updates', ['system', 'promo']);

  const NotificationFilter(this.label, this.categories);

  final String label;
  final List<String> categories;
}

/// The pill selected on the Notifications screen.
final notificationFilterProvider =
    StateProvider.autoDispose<NotificationFilter>(
      (_) => NotificationFilter.all,
    );

/// Notifications for one filter, newest first.
///
/// "All" is the same list [notificationsProvider] loads, so the badge count and
/// the list can never disagree. A filter with several categories asks the
/// server for each and merges them; each request is filtered server-side, so
/// a quiet category is not starved by a busy one.
final filteredNotificationsProvider = FutureProvider.autoDispose
    .family<List<AppNotification>, NotificationFilter>((ref, filter) async {
      if (filter == NotificationFilter.all) {
        return (await ref.watch(notificationsProvider.future)).items;
      }

      final repository = ref.watch(accountRepositoryProvider);
      final pages = await Future.wait([
        for (final category in filter.categories)
          repository.notifications(category: category),
      ]);

      return [for (final page in pages) ...page.items]
        ..sort((a, b) => b.id.compareTo(a.id));
    });

/// The notification last opened from a list.
///
/// The details screen is reached by id, and the id may come from a filtered
/// list that "All" never loaded (a payment notice older than the newest
/// twenty). Remembering the row that was tapped lets the details screen show
/// it without an endpoint for a single notification, which the API does not
/// have.
final openedNotificationProvider = StateProvider<AppNotification?>((_) => null);

/// Unread badge count derived from the loaded list.
final unreadNotificationsProvider = Provider.autoDispose<int>((ref) {
  final async = ref.watch(notificationsProvider);
  return async.valueOrNull?.items.where((n) => !n.isRead).length ?? 0;
});

class NotificationActionsController {
  NotificationActionsController(this._ref);

  final Ref _ref;

  Future<void> markRead(int id) async {
    await _ref.read(accountRepositoryProvider).markNotificationRead(id);
    _refresh();
  }

  Future<void> markAllRead() async {
    await _ref.read(accountRepositoryProvider).markAllNotificationsRead();
    _refresh();
  }

  Future<void> delete(int id) async {
    await _ref.read(accountRepositoryProvider).deleteNotification(id);
    _refresh();
  }

  /// Every list that could be showing the changed row.
  void _refresh() {
    _ref.invalidate(notificationsProvider);
    _ref.invalidate(filteredNotificationsProvider);
  }
}

final notificationActionsProvider = Provider<NotificationActionsController>((
  ref,
) {
  return NotificationActionsController(ref);
});

// -- Support --------------------------------------------------------------

final ticketsProvider = FutureProvider.autoDispose<List<SupportTicket>>((ref) {
  return ref.watch(accountRepositoryProvider).tickets();
});

final ticketProvider = FutureProvider.autoDispose.family<SupportTicket, int>((
  ref,
  id,
) {
  return ref.watch(accountRepositoryProvider).ticket(id);
});

/// FAQ category filter on the Help Centre.
final faqCategoryProvider = StateProvider.autoDispose<String?>((_) => null);

final faqsProvider = FutureProvider.autoDispose<List<Faq>>((ref) {
  return ref
      .watch(accountRepositoryProvider)
      .faqs(category: ref.watch(faqCategoryProvider));
});

final cmsPageProvider = FutureProvider.autoDispose.family<CmsPage, String>((
  ref,
  slug,
) {
  return ref.watch(accountRepositoryProvider).page(slug);
});

final referralsProvider = FutureProvider.autoDispose<ReferralSummary>((ref) {
  return ref.watch(accountRepositoryProvider).referrals();
});

// -- Profile --------------------------------------------------------------

/// Saves profile edits and pushes the fresh user into [authProvider] so every
/// screen that shows a name or avatar updates at once.
class ProfileController {
  ProfileController(this._ref);

  final Ref _ref;

  Future<void> save(Map<String, dynamic> fields) async {
    final user = await _ref
        .read(accountRepositoryProvider)
        .updateProfile(fields);
    _ref.read(authProvider.notifier).setUser(user);
  }

  Future<void> updateNotificationPrefs(NotificationPrefs prefs) async {
    await _ref
        .read(accountRepositoryProvider)
        .updateNotificationPreferences(prefs);

    final user = _ref.read(currentUserProvider);
    if (user != null) {
      _ref
          .read(authProvider.notifier)
          .setUser(user.copyWith(notificationPrefs: prefs));
    }
  }

  Future<String?> uploadAvatar(String filePath) {
    return _ref.read(accountRepositoryProvider).uploadAvatar(filePath);
  }
}

final profileControllerProvider = Provider<ProfileController>((ref) {
  return ProfileController(ref);
});

// -- Security & privacy ---------------------------------------------------

/// Devices signed in to this account, for Settings → Active sessions.
final accountSessionsProvider =
    FutureProvider.autoDispose<List<AccountSession>>((ref) {
      return ref.watch(accountRepositoryProvider).sessions();
    });

/// A creator's public-directory switch, for Settings → Privacy.
final creatorVisibilityProvider = FutureProvider.autoDispose<CreatorVisibility>(
  (ref) {
    return ref.watch(accountRepositoryProvider).creatorVisibility();
  },
);

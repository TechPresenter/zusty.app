import '../../core/network/api_endpoints.dart';
import '../../core/network/api_failure.dart';
import '../../core/network/api_response.dart';
import '../../models/account_settings.dart';
import '../../models/notification.dart';
import '../../models/support.dart';
import '../../models/user.dart';
import 'base_repository.dart';

/// Profile, notifications, support, referrals, and CMS content.
class AccountRepository extends BaseRepository {
  AccountRepository(super.client);

  // -- Profile -------------------------------------------------------------

  Future<User> profile() => readThrough(
    () async {
      final response = await client.get(ApiEndpoints.profile);
      return User.fromJson(response.maybeMapAt('user') ?? response.dataMap);
    },
    'profile',
    (json) => User.fromJson(asMap(json)),
  );

  /// `GET /me` straight from the server, never from a fallback.
  ///
  /// [profile] answers from the response cache (or a fixture) when the host
  /// cannot be reached, which is right for showing a screen but wrong for
  /// replacing the signed-in account: an old copy would quietly undo a newer
  /// save. A replayed body throws [OfflineFailure] here instead.
  Future<User> liveProfile() async {
    final response = await client.get(ApiEndpoints.profile);
    if (response.fromCache) throw const OfflineFailure();
    return User.fromJson(response.maybeMapAt('user') ?? response.dataMap);
  }

  Future<User> updateProfile(Map<String, dynamic> fields) => writeThrough(
    () async {
      final response = await client.put(ApiEndpoints.profile, body: fields);
      return User.fromJson(response.maybeMapAt('user') ?? response.dataMap);
    },
    'profile',
    (json) => User.fromJson({...asMap(json), ...fields}),
  );

  Future<String?> uploadAvatar(String filePath) => writeThrough(
    () async {
      final response = await client.upload(
        ApiEndpoints.upload,
        field: 'file',
        filePath: filePath,
        fields: {'purpose': 'avatar'},
      );
      return response.dataMap['url']?.toString();
    },
    'noop',
    (_) => filePath,
  );

  Future<void> updateNotificationPreferences(NotificationPrefs prefs) =>
      writeThrough(
        () async {
          await client.put(
            ApiEndpoints.notificationPreferences,
            body: prefs.toJson(),
          );
        },
        'noop',
        (_) {},
      );

  // -- Devices -------------------------------------------------------------

  /// Registers or refreshes this device's FCM token.
  ///
  /// [payload] comes from `PushService.devicePayload`, whose keys are the
  /// server's own. The endpoint does not validate unknown fields — it reads a
  /// fixed list and drops the rest — so a wrong key is a 200 that registered
  /// nothing rather than an error.
  Future<void> registerDevice(Map<String, dynamic> payload) => writeThrough(
    () async {
      await client.post(ApiEndpoints.devices, body: payload);
    },
    'noop',
    (_) {},
  );

  /// Stops the server targeting this device.
  ///
  /// There is no DELETE route. Re-registering without an `fcm_token` stores an
  /// empty token, and `FirebaseService::sendToUser` filters those out, so a
  /// token-less registration is the unregister.
  Future<void> unregisterDevice(String deviceId) => writeThrough(
    () async {
      await client.post(ApiEndpoints.devices, body: {'device_id': deviceId});
    },
    'noop',
    (_) {},
  );

  // -- Notifications -------------------------------------------------------

  /// One page of notifications, optionally narrowed to one of the server's
  /// categories (`booking`, `payment`, `message`, `review`, `system`,
  /// `promo`, `account`, `wallet`). Filtering happens server-side, so a
  /// category's page is a full page of that category rather than whatever of
  /// it happened to be in the newest twenty.
  Future<Paginated<AppNotification>> notifications({
    int page = 1,
    String? category,
  }) => readThrough(
    () async {
      final response = await client.get(
        ApiEndpoints.notifications,
        query: {'page': page, 'per_page': 20, 'category': ?category},
      );
      final rows = response.dataList.isNotEmpty
          ? response.dataList
          : response.listAt('notifications');
      return Paginated(
        items: rows.map(AppNotification.fromJson).toList(),
        pagination: response.pagination,
      );
    },
    'notifications',
    (json) {
      var items = asRows(json).map(AppNotification.fromJson).toList();
      if (category != null) {
        final kind = NotificationKind.resolve(category: category);
        items = items
            .where(
              (n) =>
                  n.category == category ||
                  (n.category == null && n.kind == kind),
            )
            .toList();
      }
      return Paginated(
        items: items,
        pagination: Pagination.singlePage(items.length),
      );
    },
  );

  Future<void> markNotificationRead(int id) => writeThrough(
    () async {
      await client.post(ApiEndpoints.readNotification(id));
    },
    'noop',
    (_) {},
  );

  Future<void> markAllNotificationsRead() => writeThrough(
    () async {
      await client.post(ApiEndpoints.readAllNotifications);
    },
    'noop',
    (_) {},
  );

  Future<void> deleteNotification(int id) => writeThrough(
    () async {
      await client.delete(ApiEndpoints.notification(id));
    },
    'noop',
    (_) {},
  );

  // -- Security & privacy --------------------------------------------------
  //
  // None of these fall back to a fixture. A list of signed-in devices or a
  // visibility switch that only pretended to load — or a sign-out that only
  // pretended to happen — is worse than an honest error.

  /// Devices currently signed in to this account (`GET /auth/sessions`).
  Future<List<AccountSession>> sessions() async {
    final response = await client.get(ApiEndpoints.sessions);
    final rows = response.listAt('sessions').isNotEmpty
        ? response.listAt('sessions')
        : response.dataList;
    return rows.map(AccountSession.fromJson).toList();
  }

  /// Signs one device out (`DELETE /auth/sessions/{id}`).
  Future<void> revokeSession(int id) async {
    await client.delete(ApiEndpoints.session(id));
  }

  /// Signs every device out, this one included (`POST /auth/logout-all`).
  /// The caller still clears the local session afterwards.
  Future<void> logoutEverywhere() async {
    await client.post(ApiEndpoints.logoutAll);
  }

  /// The creator's public-directory switch (`GET /me/creator/visibility`).
  Future<CreatorVisibility> creatorVisibility() async {
    final response = await client.get(_creatorVisibility);
    return CreatorVisibility.fromJson(response.dataMap);
  }

  /// Turns the creator's public profile on or off. Returns the new state and
  /// the server's own sentence about it ("Your profile is live.").
  Future<({CreatorVisibility visibility, String message})> setCreatorVisibility(
    bool isPublic,
  ) async {
    final response = await client.put(
      _creatorVisibility,
      body: {'is_public': isPublic},
    );
    return (
      visibility: CreatorVisibility.fromJson(response.dataMap),
      message: response.message,
    );
  }

  /// Not in ApiEndpoints yet; kept here so this file stays the only one the
  /// settings work touches.
  static const _creatorVisibility = '/me/creator/visibility';

  // -- Support -------------------------------------------------------------

  Future<List<SupportTicket>> tickets() => readThrough(
    () async {
      final response = await client.get(ApiEndpoints.tickets);
      final rows = response.dataList.isNotEmpty
          ? response.dataList
          : response.listAt('tickets');
      return rows.map(SupportTicket.fromJson).toList();
    },
    'tickets',
    (json) => asRows(json).map(SupportTicket.fromJson).toList(),
  );

  Future<SupportTicket> ticket(int id) => readThrough(
    () async {
      final response = await client.get(ApiEndpoints.ticket(id));
      return SupportTicket.fromJson(
        response.maybeMapAt('ticket') ?? response.dataMap,
      );
    },
    'ticket.detail:$id',
    (json) => SupportTicket.fromJson(asMap(json)),
  );

  Future<SupportTicket> createTicket({
    required String subject,
    required String category,
    required String message,
    int? bookingId,
  }) => writeThrough(
    () async {
      final response = await client.post(
        ApiEndpoints.tickets,
        body: {
          'subject': subject,
          'category': category,
          'message': message,
          'booking_id': ?bookingId,
        },
      );
      return SupportTicket.fromJson(
        response.maybeMapAt('ticket') ?? response.dataMap,
      );
    },
    'ticket.detail:1',
    (json) => SupportTicket.fromJson({
      ...asMap(json),
      'subject': subject,
      'category': category,
      'status': 'open',
    }),
  );

  Future<void> replyToTicket(int id, String message) => writeThrough(
    () async {
      await client.post(
        ApiEndpoints.replyToTicket(id),
        body: {'message': message},
      );
    },
    'noop',
    (_) {},
  );

  // -- CMS -----------------------------------------------------------------

  Future<List<Faq>> faqs({String? category}) => readThrough(
    () async {
      final response = await client.get(
        ApiEndpoints.faqs,
        query: {'category': category},
      );
      return response.listAt('faqs').map(Faq.fromJson).toList();
    },
    'faqs',
    (json) {
      final rows = asRows(json).map(Faq.fromJson).toList();
      return category == null
          ? rows
          : rows.where((f) => f.category == category).toList();
    },
  );

  Future<void> rateFaq(int id, {required bool helpful}) => writeThrough(
    () async {
      await client.post(ApiEndpoints.rateFaq(id), body: {'helpful': helpful});
    },
    'noop',
    (_) {},
  );

  Future<CmsPage> page(String slug) => readThrough(
    () async {
      final response = await client.get(ApiEndpoints.page(slug));
      return CmsPage.fromJson(response.maybeMapAt('page') ?? response.dataMap);
    },
    'page:$slug',
    (json) => CmsPage.fromJson(asMap(json)),
  );

  Future<ReferralSummary> referrals() => readThrough(
    () async {
      final response = await client.get(ApiEndpoints.referrals);
      return ReferralSummary.fromJson(response.dataMap);
    },
    'referrals',
    (json) => ReferralSummary.fromJson(asMap(json)),
  );
}

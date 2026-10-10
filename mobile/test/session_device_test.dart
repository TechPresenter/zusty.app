import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/auth/social_sign_in_service.dart';
import 'package:zuste/core/messaging/push_service.dart';
import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/data/repositories/auth_repository.dart';
import 'package:zuste/data/repositories/creator_repository.dart';
import 'package:zuste/models/creator.dart';
import 'package:zuste/models/notification.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/account_provider.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/discovery_provider.dart';
import 'package:zuste/providers/privacy_provider.dart';
import 'package:zuste/providers/session_end.dart';

import 'support/mock_data.dart';

/// What a device keeps of an account once its session is over.
///
/// These run the REAL clearing code (AuthController, AuthRepository,
/// KeyValueCache, SecureStore, ApiClient) over mocked SharedPreferences and
/// secure storage, so removing a clearing step turns them red. Only the
/// controller's build(), the push plugin, the Firebase/Google sign-out and
/// the server are stand-ins.

// ---------------------------------------------------------------------------
// Stand-ins
// ---------------------------------------------------------------------------

/// Plays the server: records each request and answers with [answer].
class _Server implements HttpClientAdapter {
  _Server(this.answer);

  final ResponseBody Function(RequestOptions options) answer;
  final requests = <String>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add('${options.method} ${options.path}');
    return answer(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(int status, Map<String, dynamic> body) => ResponseBody.fromString(
      jsonEncode(body),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

ResponseBody _ok([Map<String, dynamic> data = const {}]) =>
    _json(200, {'success': true, 'message': 'OK', 'data': data});

ResponseBody _unauthorised(String code, [String message = 'Unauthenticated.']) =>
    _json(401, {'success': false, 'message': message, 'code': code});

/// Counts token deletions instead of reaching Firebase Messaging.
class _FakePush extends PushService {
  _FakePush(super.cache, {this.fails = false});

  final bool fails;
  int deleted = 0;

  @override
  Future<void> deleteToken() async {
    deleted++;
    if (fails) throw StateError('Firebase is not available');
  }
}

/// The real controller, signed in, without the start-up restore race.
class _SignedIn extends AuthController {
  @override
  AuthState build() => AuthState(
        stage: AuthStage.authenticated,
        user: User.fromJson(MockData.currentUser),
      );
}

/// A controller whose account can be switched by the test.
class _Switchable extends AuthController {
  @override
  AuthState build() => AuthState(
        stage: AuthStage.authenticated,
        user: User.fromJson(MockData.currentUser),
      );

  void signOutLocally() => state = const AuthState(stage: AuthStage.unauthenticated);

  void switchTo(int id) => state = AuthState(
        stage: AuthStage.authenticated,
        user: User.fromJson({...MockData.currentUser, 'id': id}),
      );
}

/// Confirms every favourite and follow tap.
class _FakeCreators extends CreatorRepository {
  _FakeCreators(super.client);

  @override
  Future<bool> toggleFavorite(int creatorId, {required bool isFavorite}) async => !isFavorite;

  @override
  Future<bool> toggleFollow(int creatorId, {required bool isFollowing}) async => !isFollowing;
}

/// Records whether the old session's documents were still cached when the
/// new tokens were saved.
class _RecordingStore extends SecureStore {
  _RecordingStore(this.cache);

  final KeyValueCache cache;
  bool? documentsLeftAtSave;

  @override
  Future<void> save(AuthTokens tokens) async {
    documentsLeftAtSave = cache.readDocument('http./me/bookings') != null;
    await super.save(tokens);
  }
}

/// A keystore that cannot be cleared, as when Android has lost its key.
class _StuckStore extends SecureStore {
  @override
  Future<void> clear() async => throw StateError('keystore unavailable');
}

// ---------------------------------------------------------------------------
// A device that has been used by a signed-in account
// ---------------------------------------------------------------------------

const _usedDevice = <String, Object>{
  'cache.doc.http./auth/me': '{"success":true,"data":{"user":{"id":1}}}',
  'cache.at.http./auth/me': 1,
  'cache.doc.http./me/bookings': '{"success":true,"data":{"items":[{"id":9}]}}',
  'cache.at.http./me/bookings': 1,
  'cache.doc.profile': '{"id":1,"name":"Priya Sharma"}',
  'cache.at.profile': 1,
  'cache.doc.app.remote_config': '{"menus":{"app_drawer":[]}}',
  'cache.at.app.remote_config': 1,
  'zuste.recent_searches': <String>['lipstick'],
  'zuste.city': 'Pune',
  'zuste.theme_mode': 'dark',
  'zuste.locale': 'hi',
  'zuste.device_id': 'device-1',
  'zuste.onboarding_complete': true,
};

const _signedInTokens = <String, String>{
  'zuste.access_token': 'access-old',
  'zuste.refresh_token': 'refresh-old',
  'zuste.access_expires_at': '',
};

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;
  late SecureStore store;
  late _FakePush push;
  late List<bool> socialSignOuts;

  setUp(() async {
    SharedPreferences.setMockInitialValues(Map.of(_usedDevice));
    FlutterSecureStorage.setMockInitialValues(Map.of(_signedInTokens));
    cache = await KeyValueCache.open();
    store = SecureStore();
    push = _FakePush(cache);
    socialSignOuts = [];
  });

  ApiClient clientFor(_Server server, {SecureStore? secureStore}) => ApiClient(
        secureStore: secureStore ?? store,
        connectivity: ConnectivityService(),
        responseCache: cache,
        dio: Dio()..httpClientAdapter = server,
      );

  ProviderContainer signedIn(
    _Server server, {
    PushService? pushService,
    Future<void> Function({bool revoke})? socialSignOut,
    SecureStore? secureStore,
  }) {
    final container = ProviderContainer(
      overrides: [
        keyValueCacheProvider.overrideWithValue(cache),
        secureStoreProvider.overrideWithValue(secureStore ?? store),
        pushServiceProvider.overrideWithValue(pushService ?? push),
        socialSignOutProvider.overrideWithValue(
          socialSignOut ?? ({bool revoke = false}) async => socialSignOuts.add(revoke),
        ),
        apiClientProvider.overrideWithValue(clientFor(server, secureStore: secureStore)),
        authProvider.overrideWith(_SignedIn.new),
      ],
    );
    addTearDown(container.dispose);
    return container;
  }

  /// What must survive any sign-out: it belongs to the device.
  void expectDeviceSettingsKept() {
    expect(cache.themeModeName, 'dark');
    expect(cache.localeCode, 'hi');
    expect(cache.deviceId, 'device-1');
    expect(cache.hasCompletedOnboarding, isTrue);
  }

  /// What must be gone once the account has left the device.
  Future<void> expectAccountGone() async {
    expect(cache.readDocument('http./auth/me'), isNull);
    expect(cache.readDocument('http./me/bookings'), isNull);
    expect(cache.readDocument('profile'), isNull);
    expect(cache.readDocument('app.remote_config'), isNull);
    expect(cache.recentSearches, isEmpty);
    expect(cache.cityName, '');
    expect(await store.hasSession(), isFalse);
    expect(await store.readRefreshToken(), isNull);
  }

  // -------------------------------------------------------------------------
  // P31: a session the server ended.
  // -------------------------------------------------------------------------

  group('when the server ends the session', () {
    test('the device is cleared and lands signed out, without calling the server', () async {
      final server = _Server((_) => _ok());
      final container = signedIn(server);
      expect(container.read(authProvider).isAuthenticated, isTrue);

      await container.read(sessionEndProvider)();

      await expectAccountGone();
      expectDeviceSettingsKept();
      expect(push.deleted, 1);
      expect(socialSignOuts, [false]);
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
      expect(server.requests, isEmpty);
    });

    test('concurrent session ends share one run', () async {
      final container = signedIn(_Server((_) => _ok()));
      final end = container.read(sessionEndProvider);

      await Future.wait([end(), end(), end()]);

      expect(push.deleted, 1);
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
    });

    test('a failing step does not stop the rest', () async {
      final container = signedIn(
        _Server((_) => _ok()),
        pushService: _FakePush(cache, fails: true),
        socialSignOut: ({bool revoke = false}) async => throw StateError('no Google'),
      );

      await container.read(sessionEndProvider)();

      await expectAccountGone();
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
    });

    test("a 401 the refresh cannot fix runs the same clearing, with the server's code", () async {
      final server = _Server(
        (options) => options.path.endsWith('/auth/refresh')
            ? _unauthorised('invalid_refresh_token')
            : _unauthorised('token_revoked', 'Your session was ended.'),
      );
      final container = signedIn(server);
      final client = container.read(apiClientProvider);
      final codes = <String>[];
      final ended = Completer<void>();
      // Wired as app.dart wires it for every code but account_deleted.
      client.onSessionExpired = (code) {
        codes.add(code);
        container.read(sessionEndProvider)().then(ended.complete);
      };

      await expectLater(
        client.get('/me/bookings'),
        throwsA(isA<UnauthorisedFailure>()
            .having((f) => f.code, 'code', 'token_revoked')
            .having((f) => f.message, 'message', 'Your session was ended.')),
      );
      await ended.future;

      expect(codes, ['token_revoked']);
      expect(server.requests, ['GET /me/bookings', 'POST /auth/refresh']);
      await expectAccountGone();
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
    });

    test('a 401 account_deleted hands the device over to the goodbye screen', () async {
      final server = _Server(
        (options) => options.path.endsWith('/auth/refresh')
            ? _unauthorised('invalid_refresh_token')
            : _unauthorised('account_deleted', 'This account has been deleted.'),
      );
      final container = signedIn(server);
      final client = container.read(apiClientProvider);
      final handedOver = Completer<void>();
      // Wired as app.dart wires it.
      client.onSessionExpired = (code) {
        if (code == 'account_deleted') {
          finishAccountDeletion(
            container.read(accountDeletedProvider.notifier),
            container.read(authProvider.notifier),
          ).then(handedOver.complete);
        } else {
          container.read(sessionEndProvider)().then(handedOver.complete);
        }
      };

      await expectLater(
        client.get('/me/bookings'),
        throwsA(isA<UnauthorisedFailure>().having((f) => f.code, 'code', 'account_deleted')),
      );
      await handedOver.future;

      expect(container.read(accountDeletedProvider), isTrue);
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
      expect(push.deleted, 1);
      expect(socialSignOuts, contains(true));
      await expectAccountGone();
      expectDeviceSettingsKept();
    });

    test("a new sign-in never inherits the last session's cached documents", () async {
      final recording = _RecordingStore(cache);
      final server = _Server((_) => _ok({
            'user': {'id': 2, 'name': 'Rohan', 'account_type': 'creator'},
            'tokens': {'access_token': 'access-new', 'refresh_token': 'refresh-new'},
          }));

      await AuthRepository(clientFor(server, secureStore: recording), recording, cache)
          .login(identifier: 'rohan@example.com', password: 'secret-123');

      expect(recording.documentsLeftAtSave, isFalse);
      expect(cache.readDocument('http./auth/me'), isNull);
      expect(cache.readDocument('profile'), isNull);
      expect(await recording.readAccessToken(), 'access-new');
      expectDeviceSettingsKept();
    });
  });

  // -------------------------------------------------------------------------
  // P29: the API client passes the server's code on, and ends a session only
  // when the request carried one.
  // -------------------------------------------------------------------------

  group('the API client', () {
    test('reports account_deleted, through a failed refresh too', () async {
      final server = _Server(
        (options) => options.path.endsWith('/auth/refresh')
            ? _unauthorised('invalid_refresh_token')
            : _unauthorised('account_deleted', 'This account has been deleted.'),
      );
      final client = clientFor(server);
      final codes = <String>[];
      client.onSessionExpired = codes.add;

      await expectLater(
        client.get('/me/privacy'),
        throwsA(isA<UnauthorisedFailure>()
            .having((f) => f.code, 'code', 'account_deleted')
            .having((f) => f.message, 'message', 'This account has been deleted.')),
      );

      expect(codes, ['account_deleted']);
      expect(server.requests, ['GET /me/privacy', 'POST /auth/refresh']);
      expect(await store.hasSession(), isFalse);
    });

    test('a 401 after a successful refresh still ends the session with its code', () async {
      var refreshed = false;
      final server = _Server((options) {
        if (options.path.endsWith('/auth/refresh')) {
          refreshed = true;
          return _ok({
            'tokens': {'access_token': 'access-2', 'refresh_token': 'refresh-2'},
          });
        }
        return _unauthorised(refreshed ? 'account_deleted' : 'token_expired');
      });
      final client = clientFor(server);
      final codes = <String>[];
      client.onSessionExpired = codes.add;

      await expectLater(
        client.get('/bookings'),
        throwsA(isA<UnauthorisedFailure>().having((f) => f.code, 'code', 'account_deleted')),
      );
      expect(codes, ['account_deleted']);
      expect(server.requests, ['GET /bookings', 'POST /auth/refresh', 'GET /bookings']);
    });

    test('a wrong password at sign-in is an answer, not a lost session', () async {
      final server = _Server(
        (_) => _unauthorised('invalid_credentials', 'These credentials do not match our records.'),
      );
      final client = clientFor(server);
      final codes = <String>[];
      client.onSessionExpired = codes.add;

      await expectLater(
        AuthRepository(client, store, cache).login(identifier: 'priya@example.com', password: 'wrong'),
        throwsA(isA<UnauthorisedFailure>()
            .having((f) => f.code, 'code', 'invalid_credentials')
            .having((f) => f.message, 'message', 'These credentials do not match our records.')),
      );

      expect(codes, isEmpty);
      // No refresh was attempted, and nothing on the device was touched.
      expect(server.requests, ['POST /auth/login']);
      expect(await store.readAccessToken(), 'access-old');
      expect(cache.readDocument('http./me/bookings'), isNotNull);
    });

    test('a write that times out does not make it treat the host as down', () async {
      final server = _Server((options) {
        if (options.method == 'POST') {
          throw DioException(requestOptions: options, type: DioExceptionType.receiveTimeout);
        }
        return _ok({'exists': true});
      });
      final client = clientFor(server);

      await expectLater(
        client.post('/me/privacy/delete-account', body: {'confirmation': 'DELETE'}),
        throwsA(isA<TimeoutFailure>()),
      );
      // The follow-up check reaches the server instead of being refused.
      final check = await client.get('/me/privacy');

      expect(check.success, isTrue);
      expect(server.requests, ['POST /me/privacy/delete-account', 'GET /me/privacy']);
    });

    test('a read that times out still backs off', () async {
      final server = _Server(
        (options) => throw DioException(requestOptions: options, type: DioExceptionType.receiveTimeout),
      );
      final client = clientFor(server);

      await expectLater(client.get('/explore'), throwsA(isA<TimeoutFailure>()));
      await expectLater(client.get('/explore'), throwsA(isA<ServerFailure>()));
      expect(server.requests, ['GET /explore']);
    });
  });

  // -------------------------------------------------------------------------
  // P30, P32 and P57: sign-out and deletion run the real controller.
  // -------------------------------------------------------------------------

  group('the real AuthController', () {
    test('signOutAfterDeletion really clears the device', () async {
      final server = _Server((_) => _ok());
      final container = signedIn(server);

      await container.read(authProvider.notifier).signOutAfterDeletion();

      expect(push.deleted, 1);
      expect(await store.hasSession(), isFalse);
      expect(await store.readRefreshToken(), isNull);
      expect(cache.readDocument('profile'), isNull);
      expect(cache.recentSearches, isEmpty);
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
      // The Google grant is withdrawn too, and nothing is sent to a server
      // that no longer knows the account.
      expect(socialSignOuts, [true]);
      expect(server.requests, isEmpty);
      await expectAccountGone();
      expectDeviceSettingsKept();
    });

    test('signOutAfterDeletion runs once for concurrent calls', () async {
      final container = signedIn(_Server((_) => _ok()));
      final auth = container.read(authProvider.notifier);

      await Future.wait([auth.signOutAfterDeletion(), auth.signOutAfterDeletion()]);

      expect(push.deleted, 1);
      expect(socialSignOuts, [true]);
    });

    test('signOutAfterDeletion never throws and always ends signed out', () async {
      final container = signedIn(
        _Server((_) => _ok()),
        pushService: _FakePush(cache, fails: true),
        socialSignOut: ({bool revoke = false}) async => throw StateError('no Google'),
      );

      await container.read(authProvider.notifier).signOutAfterDeletion();

      await expectAccountGone();
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
    });

    test('logout tells the server, then clears the device and the Google session', () async {
      final server = _Server((_) => _ok());
      final container = signedIn(server);

      await container.read(authProvider.notifier).logout();

      expect(server.requests, ['POST /me/devices', 'POST /auth/logout']);
      expect(socialSignOuts, [false]);
      await expectAccountGone();
      expectDeviceSettingsKept();
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
    });

    test('logout still clears the cache and ends signed out when the keystore fails', () async {
      final container = signedIn(_Server((_) => _ok()), secureStore: _StuckStore());

      await container.read(authProvider.notifier).logout();

      expect(cache.readDocument('profile'), isNull);
      expect(cache.readDocument('http./me/bookings'), isNull);
      expect(cache.recentSearches, isEmpty);
      expect(socialSignOuts, [false]);
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
      expectDeviceSettingsKept();
    });
  });

  group("one account's in-memory state", () {
    const creator = Creator(id: 42, userId: 420, handle: 'aanya', displayName: 'Aanya');

    ProviderContainer withTaps() {
      final container = ProviderContainer(
        overrides: [
          authProvider.overrideWith(_Switchable.new),
          creatorRepositoryProvider.overrideWithValue(
            _FakeCreators(ApiClient(secureStore: SecureStore(), connectivity: ConnectivityService())),
          ),
        ],
      );
      addTearDown(container.dispose);
      return container;
    }

    Future<void> tap(ProviderContainer container) async {
      container.read(recentSearchesProvider.notifier).add('lipstick');
      await container.read(favoriteToggleProvider.notifier).toggle(creator);
      await container.read(followToggleProvider.notifier).toggle(creator);
      container.read(openedNotificationProvider.notifier).state =
          AppNotification.fromJson({'id': 5, 'title': 'Payment received'});

      expect(container.read(recentSearchesProvider), ['lipstick']);
      expect(container.read(isFavoriteProvider(creator)), isTrue);
      expect(container.read(isFollowingProvider(creator)), isTrue);
      expect(container.read(openedNotificationProvider), isNotNull);
    }

    void expectReset(ProviderContainer container) {
      expect(container.read(recentSearchesProvider), isEmpty);
      // Back to the server's own flags.
      expect(container.read(isFavoriteProvider(creator)), isFalse);
      expect(container.read(isFollowingProvider(creator)), isFalse);
      expect(container.read(openedNotificationProvider), isNull);
    }

    test('is gone after a sign-out', () async {
      final container = withTaps();
      await tap(container);

      (container.read(authProvider.notifier) as _Switchable).signOutLocally();

      expectReset(container);
    });

    test('does not carry over to another account', () async {
      final container = withTaps();
      await tap(container);

      (container.read(authProvider.notifier) as _Switchable).switchTo(2);

      expectReset(container);
    });

    test('survives a profile update on the same account', () async {
      final container = withTaps();
      await tap(container);

      final auth = container.read(authProvider.notifier);
      auth.setUser(container.read(authProvider).user!.copyWith(name: 'Priya S.'));

      expect(container.read(recentSearchesProvider), ['lipstick']);
      expect(container.read(isFavoriteProvider(creator)), isTrue);
    });
  });

  group('the device cache', () {
    test('clearAccountData forgets the account but keeps the device settings', () async {
      await cache.clearAccountData();

      expect(cache.readDocument('profile'), isNull);
      expect(cache.readDocument('http./me/bookings'), isNull);
      expect(cache.recentSearches, isEmpty);
      expect(cache.cityName, '');
      expectDeviceSettingsKept();
    });

    test('clearAll still clears only the cached responses', () async {
      await cache.clearAll();

      expect(cache.readDocument('profile'), isNull);
      expect(cache.recentSearches, ['lipstick']);
      expect(cache.cityName, 'Pune');
    });
  });

  test('signing out of Firebase and Google is safe when neither ever started', () async {
    await SocialSignInService.signOut();
    await SocialSignInService.signOut(revoke: true);
  });

  // -------------------------------------------------------------------------
  // NEW-mobile-deletion-1: Android backups leave the cache out.
  // -------------------------------------------------------------------------

  group('Android backups', () {
    String read(String path) => File(path).readAsStringSync();

    test('the manifest points Auto Backup at the rules', () {
      final manifest = read('android/app/src/main/AndroidManifest.xml');
      final application = RegExp(r'<application\b[^>]*>').firstMatch(manifest)!.group(0)!;

      expect(application, contains('android:dataExtractionRules="@xml/data_extraction_rules"'));
      expect(application, contains('android:fullBackupContent="@xml/backup_rules"'));
    });

    test('both rule files leave out the shared preferences and the token store', () {
      final extraction = read('android/app/src/main/res/xml/data_extraction_rules.xml');
      for (final section in ['cloud-backup', 'device-transfer']) {
        final body = RegExp('<$section>(.*?)</$section>', dotAll: true).firstMatch(extraction)?.group(1);
        expect(body, isNotNull, reason: section);
        expect(body, contains('<exclude domain="sharedpref" path="FlutterSharedPreferences.xml"/>'), reason: section);
        expect(body, contains('<exclude domain="sharedpref" path="FlutterSecureStorage.xml"/>'), reason: section);
      }

      final legacy = read('android/app/src/main/res/xml/backup_rules.xml');
      expect(legacy, contains('<full-backup-content>'));
      expect(legacy, contains('<exclude domain="sharedpref" path="FlutterSharedPreferences.xml"/>'));
      expect(legacy, contains('<exclude domain="sharedpref" path="FlutterSecureStorage.xml"/>'));
    });
  });
}

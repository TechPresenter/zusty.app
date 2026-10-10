import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/app.dart';
import 'package:zuste/core/messaging/push_service.dart';
import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/data/repositories/account_repository.dart';
import 'package:zuste/models/auth_config.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/push_provider.dart';
import 'package:zuste/router/app_router.dart';

import 'support/mock_data.dart';

/// Push registration (M1).
///
/// The controller used to be built exactly once, by a `ref.read` in the app
/// root while the stored session was still being restored. Riverpod 2 only
/// rebuilds a provider that something listens to, so it never saw the
/// sign-in: no FCM token reached `POST /me/devices` and no tap or foreground
/// handler was ever attached. These run the REAL [PushController] over a
/// fake FCM plugin and a recording server — and, in the last group, the real
/// `ZusteApp` over a recording repository — so going back to a one-off read
/// turns them red.

// ---------------------------------------------------------------------------
// Stand-ins
// ---------------------------------------------------------------------------

/// Plays the server: records every request and its body, answers 200.
class _Server implements HttpClientAdapter {
  final requests = <String>[];
  final bodies = <Map<String, dynamic>>[];

  /// The bodies sent to `POST /me/devices`, in order.
  List<Map<String, dynamic>> get registrations => [
        for (var i = 0; i < requests.length; i++)
          if (requests[i] == 'POST /me/devices') bodies[i],
      ];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add('${options.method} ${options.path}');
    final data = options.data;
    bodies.add(data is Map ? Map<String, dynamic>.from(data) : const {});

    return ResponseBody.fromString(
      jsonEncode({'success': true, 'message': 'OK', 'data': const {}}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

/// Firebase Messaging, reduced to what the controller touches.
class _FakeMessaging extends PushService {
  _FakeMessaging(super.cache);

  final refreshes = StreamController<String>.broadcast();
  final opened = StreamController<PushMessage>.broadcast();
  final foreground = StreamController<PushMessage>.broadcast();

  int tokenRequests = 0;

  /// What [token] answers. Replace with a pending future to hold a
  /// registration mid-flight.
  Future<String?> Function() nextToken = () async => 'fcm-1';

  bool get handlersAttached => opened.hasListener && foreground.hasListener;

  @override
  Future<String?> token({String? vapidKey}) {
    tokenRequests++;
    return nextToken();
  }

  @override
  Stream<String> get onTokenRefresh => refreshes.stream;

  @override
  Stream<PushMessage> get onMessageOpened => opened.stream;

  @override
  Stream<PushMessage> get onForegroundMessage => foreground.stream;

  @override
  Future<PushMessage?> initialMessage() async => null;

  @override
  Future<void> deleteToken() async {}

  /// The real payload reads the package and device-info plugins, which a
  /// test does not have. The keys are the server's own.
  @override
  Future<Map<String, dynamic>> devicePayload(String fcmToken) async => {
        'device_id': 'device-1',
        'fcm_token': fcmToken,
        'platform': 'android',
      };

  Future<void> close() async {
    await refreshes.close();
    await opened.close();
    await foreground.close();
  }
}

/// Records device registrations without HTTP, for widget tests: the API
/// client's platform-channel and network work does not run on the test's
/// fake clock. The controller tests above use the real repository.
class _RecordingAccounts extends AccountRepository {
  _RecordingAccounts()
      : super(ApiClient(secureStore: SecureStore(), connectivity: ConnectivityService()));

  final registered = <Map<String, dynamic>>[];

  @override
  Future<void> registerDevice(Map<String, dynamic> payload) async => registered.add(payload);
}

/// The real sign-in states, switched by the test instead of by a server.
class _Auth extends AuthController {
  _Auth(this._initial);

  final AuthState _initial;

  @override
  AuthState build() => _initial;

  void signIn(int id) => state = AuthState(
        stage: AuthStage.authenticated,
        user: User.fromJson({...MockData.currentUser, 'id': id}),
      );

  void becomeGuest() => state = AuthState(
        stage: AuthStage.guest,
        user: User.fromJson({...MockData.currentUser, 'id': 99}),
      );

  void signOutLocally() => state = const AuthState(stage: AuthStage.unauthenticated);
}

/// The server config, with no network behind it.
class _FixedAuthConfig extends AuthConfigController {
  _FixedAuthConfig({this.firebase = true});

  final bool firebase;

  static AuthConfig config({required bool firebase}) => AuthConfig.fromJson({
        'methods': [
          {'key': 'email_password', 'enabled': true},
        ],
        'firebase': firebase
            ? {'enabled': true, 'project_id': 'p', 'api_key': 'k', 'app_id': 'a'}
            : {'enabled': false, 'project_id': '', 'api_key': '', 'app_id': ''},
      });

  @override
  AuthConfig build() => config(firebase: firebase);

  @override
  Future<void> refresh({bool force = false}) async {}

  /// What a refresh does once the server's answer has arrived.
  void arrive(AuthConfig next) => state = next;
}

const _signedOut = AuthState(stage: AuthStage.unauthenticated);

/// Lets every pending event, timer and microtask run.
Future<void> _settle() async {
  for (var i = 0; i < 20; i++) {
    await Future<void>.delayed(Duration.zero);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;
  late _FakeMessaging messaging;
  late _Server server;
  late int firebaseStarts;

  setUp(() async {
    SharedPreferences.setMockInitialValues({'zuste.device_id': 'device-1'});
    FlutterSecureStorage.setMockInitialValues({
      'zuste.access_token': 'access-1',
      'zuste.refresh_token': 'refresh-1',
      'zuste.access_expires_at': '',
    });
    cache = await KeyValueCache.open();
    messaging = _FakeMessaging(cache);
    server = _Server();
    firebaseStarts = 0;
    addTearDown(messaging.close);
  });

  List<Override> overrides(AuthState initial, {bool firebaseConfigured = true}) => [
        keyValueCacheProvider.overrideWithValue(cache),
        apiClientProvider.overrideWithValue(ApiClient(
          secureStore: SecureStore(),
          connectivity: ConnectivityService(),
          dio: Dio()..httpClientAdapter = server,
        )),
        authProvider.overrideWith(() => _Auth(initial)),
        authConfigProvider.overrideWith(
          () => _FixedAuthConfig(firebase: firebaseConfigured),
        ),
        pushServiceProvider.overrideWithValue(messaging),
        // Starts only with usable options, as `ensureFirebase` does.
        pushFirebaseStarterProvider.overrideWithValue((config) async {
          firebaseStarts++;
          return config.isUsable;
        }),
      ];

  /// A container holding the same live subscription the app root holds.
  ProviderContainer listening(AuthState initial, {bool firebaseConfigured = true}) {
    final container = ProviderContainer(
      overrides: overrides(initial, firebaseConfigured: firebaseConfigured),
    );
    addTearDown(container.dispose);
    container.listen<PushState>(pushProvider, (_, _) {});
    return container;
  }

  _Auth authOf(ProviderContainer container) =>
      container.read(authProvider.notifier) as _Auth;

  group('the push controller', () {
    test('registers nothing while signed out, or as a guest', () async {
      final container = listening(_signedOut);
      await _settle();

      expect(container.read(pushProvider).status, PushStatus.idle);

      // `/me/*` carries `no-guest`: a guest is not registered either.
      authOf(container).becomeGuest();
      await _settle();

      expect(container.read(pushProvider).status, PushStatus.idle);
      expect(messaging.tokenRequests, 0);
      expect(firebaseStarts, 0);
      expect(messaging.handlersAttached, isFalse);
      expect(server.requests, isEmpty);
    });

    test('registers the device the moment somebody signs in', () async {
      // As at launch: the stored session is still being restored.
      final container = listening(const AuthState());
      await _settle();
      expect(server.requests, isEmpty);

      authOf(container).signIn(1);
      await _settle();

      expect(server.requests, ['POST /me/devices']);
      expect(server.registrations.single, containsPair('fcm_token', 'fcm-1'));
      expect(server.registrations.single, containsPair('device_id', 'device-1'));
      expect(messaging.tokenRequests, 1);
      expect(container.read(pushProvider).status, PushStatus.registered);
      expect(container.read(pushProvider).token, 'fcm-1');
      // Taps and foreground pushes are handled from now on.
      expect(messaging.handlersAttached, isTrue);
      expect(messaging.refreshes.hasListener, isTrue);
    });

    test('a profile edit does not register again; a rotated token does', () async {
      final container = listening(_signedOut);
      authOf(container).signIn(1);
      await _settle();
      expect(server.registrations, hasLength(1));

      final auth = authOf(container);
      auth.setUser(container.read(authProvider).user!.copyWith(name: 'Priya S.'));
      await _settle();

      expect(server.registrations, hasLength(1));
      expect(messaging.tokenRequests, 1);

      messaging.refreshes.add('fcm-2');
      await _settle();

      expect(server.registrations, hasLength(2));
      expect(server.registrations.last, containsPair('fcm_token', 'fcm-2'));
      expect(container.read(pushProvider).token, 'fcm-2');
    });

    test('signing out drops every handler and registers nothing more', () async {
      final container = listening(_signedOut);
      authOf(container).signIn(1);
      await _settle();
      expect(messaging.handlersAttached, isTrue);

      authOf(container).signOutLocally();
      await _settle();

      expect(container.read(pushProvider).status, PushStatus.idle);
      expect(messaging.handlersAttached, isFalse);
      expect(messaging.refreshes.hasListener, isFalse);

      messaging.refreshes.add('fcm-2');
      await _settle();
      expect(server.registrations, hasLength(1));
    });

    test('a sign-out during registration registers nothing', () async {
      final pending = Completer<String?>();
      messaging.nextToken = () => pending.future;

      final container = listening(_signedOut);
      authOf(container).signIn(1);
      await _settle();
      expect(messaging.tokenRequests, 1);

      authOf(container).signOutLocally();
      await _settle();
      pending.complete('fcm-late');
      await _settle();

      expect(server.requests, isEmpty);
      expect(container.read(pushProvider).status, PushStatus.idle);
      expect(messaging.handlersAttached, isFalse);
    });

    test('the next account to sign in is registered too', () async {
      final container = listening(_signedOut);

      authOf(container).signIn(1);
      await _settle();
      authOf(container).signOutLocally();
      await _settle();
      authOf(container).signIn(2);
      await _settle();

      expect(server.registrations, hasLength(2));
      expect(container.read(pushProvider).status, PushStatus.registered);
      expect(messaging.handlersAttached, isTrue);
    });

    test('a first launch with no Firebase options registers once they arrive', () async {
      final container = listening(_signedOut, firebaseConfigured: false);

      authOf(container).signIn(1);
      await _settle();
      expect(container.read(pushProvider).status, PushStatus.failed);
      expect(server.requests, isEmpty);

      (container.read(authConfigProvider.notifier) as _FixedAuthConfig)
          .arrive(_FixedAuthConfig.config(firebase: true));
      await _settle();

      expect(server.registrations, hasLength(1));
      expect(container.read(pushProvider).status, PushStatus.registered);
    });
  });

  // -------------------------------------------------------------------------
  // The app root is what keeps the controller alive.
  // -------------------------------------------------------------------------

  group('ZusteApp', () {
    Future<void> pumpFrames(WidgetTester tester) async {
      for (var i = 0; i < 5; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }
    }

    testWidgets('registers the device once the restored session is signed in',
        (tester) async {
      final router = GoRouter(routes: [
        GoRoute(path: '/', builder: (_, _) => const Scaffold(body: Text('Home'))),
      ]);
      addTearDown(router.dispose);
      final accounts = _RecordingAccounts();

      // The session starts unknown, exactly as at launch.
      await tester.pumpWidget(ProviderScope(
        overrides: [
          ...overrides(const AuthState()),
          accountRepositoryProvider.overrideWithValue(accounts),
          routerProvider.overrideWithValue(router),
        ],
        child: const ZusteApp(),
      ));
      await tester.pump(const Duration(milliseconds: 300));

      // Splash: the session is still being restored, nothing is registered.
      expect(accounts.registered, isEmpty);
      expect(messaging.tokenRequests, 0);

      // The restore finds a signed-in session.
      final container = ProviderScope.containerOf(tester.element(find.byType(ZusteApp)));
      (container.read(authProvider.notifier) as _Auth).signIn(1);
      await pumpFrames(tester);

      expect(find.text('Home'), findsOneWidget);
      expect(accounts.registered, hasLength(1));
      expect(accounts.registered.single, containsPair('fcm_token', 'fcm-1'));
      expect(container.read(pushProvider).status, PushStatus.registered);
      expect(messaging.handlersAttached, isTrue);

      // Signing out drops the handlers again.
      (container.read(authProvider.notifier) as _Auth).signOutLocally();
      await pumpFrames(tester);

      expect(container.read(pushProvider).status, PushStatus.idle);
      expect(messaging.handlersAttached, isFalse);
      expect(accounts.registered, hasLength(1));
    });
  });
}

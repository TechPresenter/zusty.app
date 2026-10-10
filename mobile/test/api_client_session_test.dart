// "Delete my account" end to end through the REAL ApiClient and
// PrivacyRepository, against a stub server: what happens when the server
// erased the account but the device never got the answer, or when the
// account was already gone.
//
// privacy_flow_test.dart pins the screen with a faked repository, and
// session_device_test.dart pins the client's own 401 rules (the code passed
// on through a failed refresh, a sign-in 401 that never ends a session, a
// slow write that does not start the back-off). These join the two: the
// client's session-expiry handler, wired as app.dart wires it, and the
// delete screen both reach the one hand-over, and nothing is sent twice.
import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/features/privacy/delete_account_screen.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/privacy_provider.dart';
import 'package:zuste/router/routes.dart';
import 'package:zuste/shared/widgets/z_button.dart';
import 'package:zuste/shared/widgets/z_text_field.dart';

import 'support/mock_data.dart';

/// `GET /me/privacy` for an account that can delete itself with a password.
const _overview = {
  'verification': {
    'password': true,
    'has_password': true,
    'channels': [
      {'channel': 'email', 'destination': 'it****@zuste.test'},
    ],
    'unavailable': <Map<String, dynamic>>[],
    'providers': <String>[],
  },
  'export': {
    'latest': null,
    'can_request': true,
    'next_available_at': null,
    'cooldown_hours': 24,
    'link_hours': 72,
    'max_downloads': 5,
    'format': 'ZIP archive of JSON files',
    'includes': <Map<String, dynamic>>[],
  },
  'deletion': {
    'can_delete': true,
    'blockers': <Map<String, dynamic>>[],
    'forfeit': null,
    'confirm_phrase': 'DELETE',
    'reasons': [
      {'code': 'other', 'label': 'Something else'},
    ],
    'erased': [
      {'key': 'profile', 'title': 'Your profile', 'detail': 'Name, photo, bio and contact details.'},
    ],
    'retained': [
      {'key': 'financial', 'title': 'Bookings, payments and invoices', 'detail': 'Kept for up to 8 years.'},
    ],
    'retained_counts': {'bookings': 0, 'invoices': 0, 'payments': 0, 'messages': 0},
  },
};

ResponseBody _json(int status, Map<String, dynamic> body) => ResponseBody.fromString(
      jsonEncode(body),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

ResponseBody _accountDeleted() => _json(401, {
      'success': false,
      'message': 'This account has been deleted.',
      'code': 'account_deleted',
    });

/// Plays the server. The privacy overview answers until the deletion has
/// been sent; from then on every signed-in request is refused as the server
/// refuses an erased account, and the refresh token is gone.
class _Server implements HttpClientAdapter {
  _Server({required this.loseTheAnswer});

  /// True: the deletion commits but its answer never arrives (a timeout).
  /// False: the account was already erased, so the deletion itself is
  /// refused with 401 account_deleted.
  final bool loseTheAnswer;

  final requests = <String>[];
  bool _erased = false;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add('${options.method} ${options.path}');
    final path = options.path;

    if (path.endsWith('/auth/refresh')) {
      return _json(401, {
        'success': false,
        'message': 'Your session has expired. Please sign in again.',
        'code': 'invalid_refresh_token',
      });
    }

    if (path.endsWith('/me/privacy/delete-account')) {
      final alreadyErased = _erased || !loseTheAnswer;
      _erased = true;

      if (alreadyErased) return _accountDeleted();
      throw DioException(requestOptions: options, type: DioExceptionType.receiveTimeout);
    }

    if (path.endsWith('/me/privacy')) {
      return _erased ? _accountDeleted() : _json(200, {'success': true, 'message': 'OK', 'data': _overview});
    }

    return _json(404, {'success': false, 'message': 'Not found.', 'code': 'not_found'});
  }

  @override
  void close({bool force = false}) {}

  int count(String request) => requests.where((r) => r == request).length;
}

/// Always online. The real plugin's platform channel never answers under a
/// widget test's fake clock, which would hold every request back.
class _Online implements Connectivity {
  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => [ConnectivityResult.wifi];

  @override
  Stream<List<ConnectivityResult>> get onConnectivityChanged => const Stream.empty();
}

/// Signed in. The sign-out after a deletion waits for [gate], so a test can
/// see every caller arrive before the first one finishes.
class _Auth extends AuthController {
  _Auth(this.gate);

  final Completer<void> gate;
  int signOutCalls = 0;
  bool signedOut = false;

  @override
  AuthState build() => AuthState(
        stage: AuthStage.authenticated,
        user: User.fromJson(MockData.currentUser),
      );

  @override
  Future<void> signOutAfterDeletion() async {
    signOutCalls++;
    await gate.future;
    signedOut = true;
    state = const AuthState(stage: AuthStage.unauthenticated);
  }

  @override
  Future<void> restore() async {
    state = const AuthState(stage: AuthStage.unauthenticated);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;
  late SecureStore store;
  late _Server server;
  late _Auth auth;
  late ProviderContainer container;
  late List<String> otherSessionEnds;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({
      'zuste.access_token': 'access-old',
      'zuste.refresh_token': 'refresh-old',
      'zuste.access_expires_at': '',
    });
    cache = await KeyValueCache.open();
    store = SecureStore();
  });

  Future<void> settle(WidgetTester tester) async {
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
  }

  Future<void> pumpDeleteScreen(WidgetTester tester, {required bool loseTheAnswer}) async {
    tester.view.physicalSize = const Size(1170, 6000);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    server = _Server(loseTheAnswer: loseTheAnswer);
    auth = _Auth(Completer<void>());
    otherSessionEnds = [];

    final client = ApiClient(
      secureStore: store,
      connectivity: ConnectivityService(connectivity: _Online()),
      responseCache: cache,
      dio: Dio()..httpClientAdapter = server,
    );

    container = ProviderContainer(overrides: [
      keyValueCacheProvider.overrideWithValue(cache),
      secureStoreProvider.overrideWithValue(store),
      apiClientProvider.overrideWithValue(client),
      authProvider.overrideWith(() => auth),
    ]);
    addTearDown(container.dispose);

    // Wired exactly as app.dart wires it.
    client.onSessionExpired = (code) {
      if (code == 'account_deleted') {
        finishAccountDeletion(
          container.read(accountDeletedProvider.notifier),
          container.read(authProvider.notifier),
        );
      } else {
        otherSessionEnds.add(code);
      }
    };

    final router = GoRouter(
      initialLocation: '/screen',
      routes: [
        GoRoute(path: '/screen', builder: (_, _) => const DeleteAccountScreen()),
        for (final path in [Routes.privacy, Routes.login])
          GoRoute(path: path, builder: (_, _) => Scaffold(body: Text('route:$path'))),
      ],
    );

    await tester.pumpWidget(UncontrolledProviderScope(
      container: container,
      child: MaterialApp.router(theme: AppTheme.light, routerConfig: router),
    ));
    await settle(tester);
  }

  Finder field(String label) => find.descendant(
        of: find.ancestor(of: find.text(label), matching: find.byType(ZTextField)),
        matching: find.byType(TextFormField),
      );

  Future<void> type(WidgetTester tester, String label, String text) async {
    await tester.ensureVisible(field(label));
    await tester.enterText(field(label), text);
    await tester.pump();
  }

  Future<void> tapText(WidgetTester tester, String text) async {
    await tester.ensureVisible(find.text(text));
    await tester.tap(find.text(text));
    await settle(tester);
  }

  /// Walks the three steps with a password and sends the deletion.
  Future<void> deleteWithPassword(WidgetTester tester) async {
    await tapText(tester, 'Continue');
    await tapText(tester, 'Skip');
    await type(tester, 'Your password', 'pw');
    await type(tester, 'Type DELETE', 'DELETE');
    await tester.ensureVisible(find.byType(Checkbox));
    await tester.tap(find.byType(Checkbox));
    await tester.pump();

    await tester.tap(find.widgetWithText(ZButton, 'Delete my account'));
    await settle(tester);
    await tester.tap(find.text('Delete permanently'));
    await tester.pump();
  }

  testWidgets('a deletion whose answer was lost is found through the real client, and the device handed over once',
      (tester) async {
    await pumpDeleteScreen(tester, loseTheAnswer: true);
    expect(server.requests, ['GET /me/privacy']);

    await deleteWithPassword(tester);
    await tester.pump(const Duration(seconds: 1));
    expect(find.textContaining('Checking whether your account was deleted'), findsOneWidget);

    // The first check, about 3 seconds on, meets the erased account: the
    // client tries its refresh, ends the session with the server's code,
    // and both the app-wide handler and the screen hand the device over.
    for (var i = 0; i < 4; i++) {
      await tester.pump(const Duration(seconds: 1));
    }

    expect(server.count('POST /me/privacy/delete-account'), 1, reason: 'the deletion is never sent twice');
    expect(server.count('POST /auth/refresh'), 1);
    expect(otherSessionEnds, isEmpty, reason: 'account_deleted is not an ordinary session end');
    expect(await store.hasSession(), isFalse, reason: 'the client forgot the dead tokens');
    expect(container.read(accountDeletedProvider), isTrue);
    expect(auth.signOutCalls, 1, reason: 'the handler and the screen share one hand-over');

    auth.gate.complete();
    await settle(tester);

    expect(auth.signedOut, isTrue);
    expect(container.read(deletionInFlightProvider), isFalse);
    expect(find.textContaining('took too long'), findsNothing);
    expect(find.textContaining('was not deleted'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('an account already erased elsewhere is handed over through the real client, with no error',
      (tester) async {
    await pumpDeleteScreen(tester, loseTheAnswer: false);

    await deleteWithPassword(tester);
    await settle(tester);

    expect(server.requests, [
      'GET /me/privacy',
      'POST /me/privacy/delete-account',
      'POST /auth/refresh',
    ]);
    expect(otherSessionEnds, isEmpty);
    expect(container.read(accountDeletedProvider), isTrue);
    expect(auth.signOutCalls, 1);

    auth.gate.complete();
    await settle(tester);

    expect(auth.signedOut, isTrue);
    expect(find.text('This account has been deleted.'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}

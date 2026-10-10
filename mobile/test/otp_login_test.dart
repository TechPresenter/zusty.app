import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/auth_repository.dart';
import 'package:zuste/features/auth/login_screen.dart';
import 'package:zuste/models/auth_config.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';

/// OTP sign-in is offered only when a code can actually sign someone in.
///
/// With mobile OTP on but OTP sign-in off, the server only confirms a code
/// (`{verified: true}`) and returns no session. The app used to offer the
/// button anyway and treat that answer as a sign-in, leaving it "signed in"
/// with no tokens.

/// Holds a fixed sign-in config instead of fetching one.
class _FixedAuthConfig extends AuthConfigController {
  _FixedAuthConfig(this.config);

  final AuthConfig config;

  @override
  AuthConfig build() => config;
}

/// Answers every request with [body] and records the paths it was asked for.
class _StubServer implements HttpClientAdapter {
  _StubServer(this.body);

  final Map<String, dynamic> body;
  final paths = <String>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    paths.add(options.path);
    return ResponseBody.fromString(
      jsonEncode(body),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

AuthConfig _config({required bool otpEnabled, required bool loginEnabled}) =>
    AuthConfig.fromJson({
      'version': 'test',
      'methods': [
        {
          'key': 'email_password',
          'enabled': true,
          'label': 'Email or phone',
          'forgot_password_enabled': true,
        },
        {
          'key': 'mobile_otp',
          'enabled': otpEnabled,
          'label': 'Continue with OTP',
          'login_enabled': loginEnabled,
        },
      ],
    });

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    cache = await KeyValueCache.open();
  });

  Future<void> pumpLogin(WidgetTester tester, AuthConfig config) async {
    tester.view.physicalSize = const Size(1170, 4800);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(ProviderScope(
      overrides: [
        keyValueCacheProvider.overrideWithValue(cache),
        authConfigProvider.overrideWith(() => _FixedAuthConfig(config)),
      ],
      child: MaterialApp(theme: AppTheme.light, home: const LoginScreen()),
    ));
    await tester.pump();
  }

  group('the login screen', () {
    testWidgets('hides OTP sign-in when a code cannot sign anyone in', (tester) async {
      await pumpLogin(tester, _config(otpEnabled: true, loginEnabled: false));

      expect(find.text('Welcome Back!'), findsOneWidget);
      expect(find.text('Continue with OTP'), findsNothing);
    });

    testWidgets('offers OTP sign-in when the operator allows it', (tester) async {
      await pumpLogin(tester, _config(otpEnabled: true, loginEnabled: true));

      expect(find.text('Continue with OTP'), findsOneWidget);
    });

    testWidgets('hides it while mobile OTP is switched off', (tester) async {
      await pumpLogin(tester, _config(otpEnabled: false, loginEnabled: true));

      expect(find.text('Continue with OTP'), findsNothing);
    });
  });

  group('a sign-in that returns no session', () {
    late SecureStore store;
    late _StubServer server;
    late ApiClient client;

    setUp(() {
      store = SecureStore();
      server = _StubServer({
        'success': true,
        'message': 'Verification successful.',
        'data': {'verified': true},
      });
      client = ApiClient(
        secureStore: store,
        connectivity: ConnectivityService(),
        responseCache: cache,
        dio: Dio()..httpClientAdapter = server,
      );
    });

    test('is a failure, and nothing is saved', () async {
      final repository = AuthRepository(client, store, cache);

      await expectLater(
        repository.verifyOtp(destination: '+919876543210', code: '123456'),
        throwsA(
          isA<UnknownFailure>()
              .having((f) => f.code, 'code', 'no_session')
              .having((f) => f.message, 'message', 'Sign-in did not return a session. Please try again.'),
        ),
      );

      expect(server.paths.single, endsWith('/auth/otp/verify'));
      expect(await store.hasSession(), isFalse);
      expect(cache.hasCompletedOnboarding, isFalse);
    });

    test('leaves the app signed out', () async {
      final container = ProviderContainer(
        overrides: [
          keyValueCacheProvider.overrideWithValue(cache),
          secureStoreProvider.overrideWithValue(store),
          apiClientProvider.overrideWithValue(client),
        ],
      );
      addTearDown(container.dispose);

      // Let the controller's own start-up restore settle first.
      container.read(authProvider);
      await Future<void>.delayed(Duration.zero);

      await expectLater(
        container.read(authProvider.notifier).verifyOtp(phone: '+919876543210', code: '123456'),
        throwsA(isA<UnknownFailure>()),
      );
      expect(container.read(authProvider).isAuthenticated, isFalse);
      expect(container.read(authProvider).user, isNull);
    });

    test('a sign-in with tokens still works', () async {
      server = _StubServer({
        'success': true,
        'message': 'Signed in successfully.',
        'data': {
          'user': {'id': 7, 'name': 'Asha', 'email': 'asha@example.com', 'account_type': 'brand'},
          'tokens': {'access_token': 'access-1', 'refresh_token': 'refresh-1'},
        },
      });
      client = ApiClient(
        secureStore: store,
        connectivity: ConnectivityService(),
        responseCache: cache,
        dio: Dio()..httpClientAdapter = server,
      );

      final result = await AuthRepository(client, store, cache)
          .login(identifier: 'asha@example.com', password: 'secret-123');

      expect(result.user.id, 7);
      expect(await store.readAccessToken(), 'access-1');
      expect(cache.hasCompletedOnboarding, isTrue);
    });
  });
}

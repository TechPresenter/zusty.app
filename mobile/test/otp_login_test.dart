import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/auth_repository.dart';
import 'package:zuste/features/auth/login_screen.dart';
import 'package:zuste/features/auth/otp_screen.dart';
import 'package:zuste/features/auth/widgets/otp_code_field.dart';
import 'package:zuste/models/auth_config.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/router/routes.dart';
import 'package:zuste/shared/widgets/z_text_field.dart';

/// OTP sign-in is offered only when a code can actually sign someone in.
///
/// With mobile OTP on but OTP sign-in off, the server only confirms a code
/// (`{verified: true}`) and returns no session. The app used to offer the
/// button anyway and treat that answer as a sign-in, leaving it "signed in"
/// with no tokens.
///
/// Codes go to the account's email address (the server's `channels` is
/// `["email"]`); the phone step appears only when the server offers SMS
/// alone, and a code never creates an account.

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

AuthConfig _config({
  required bool otpEnabled,
  required bool loginEnabled,
  List<String>? channels,
  String label = 'Continue with OTP',
}) =>
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
          'label': label,
          'login_enabled': loginEnabled,
          'channels': ?channels,
          'otp_length': 6,
          'resend_after_seconds': 45,
        },
      ],
    });

/// Always online, without asking the platform: a widget test's fake clock
/// never sees a platform channel answer, so the real check would hang.
class _Online extends ConnectivityService {
  @override
  Future<bool> isOnline() async => true;
}

/// What the server answers today: codes by email only.
AuthConfig _emailConfig() => _config(
      otpEnabled: true,
      loginEnabled: true,
      channels: const ['email'],
      label: 'Log in with email code',
    );

/// Answers by path, like the API, and records every request.
class _OtpServer implements HttpClientAdapter {
  final requests = <RequestOptions>[];

  /// What POST /auth/otp/verify answers with: a session, or a failure.
  Map<String, dynamic> verifyBody = {
    'success': true,
    'message': 'Signed in successfully.',
    'data': {
      'user': {'id': 7, 'name': 'Asha', 'email': 'asha@example.com', 'account_type': 'brand'},
      'tokens': {'access_token': 'access-1', 'refresh_token': 'refresh-1'},
    },
  };
  int verifyStatus = 200;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);

    final (status, body) = switch (options.path) {
      final p when p.endsWith('/auth/otp/send') => (
          200,
          {
            'success': true,
            'message': 'Verification code sent.',
            'data': {'expires_in': 300, 'length': 6, 'channel': 'email', 'resend_in': 45},
          },
        ),
      final p when p.endsWith('/auth/otp/verify') => (verifyStatus, verifyBody),
      _ => (200, {'success': true, 'message': 'OK', 'data': <String, dynamic>{}}),
    };

    return ResponseBody.fromString(
      jsonEncode(body),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}

  List<RequestOptions> to(String path) =>
      requests.where((r) => r.path.endsWith(path)).toList();
}

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

    testWidgets('names the email code with the label the server sends', (tester) async {
      await pumpLogin(tester, _emailConfig());

      expect(find.text('Log in with email code'), findsOneWidget);
      expect(find.byIcon(Icons.mail_outline_rounded), findsOneWidget);
      expect(find.byIcon(Icons.sms_outlined), findsNothing);
    });

    testWidgets('falls back to an email label when the server sends none', (tester) async {
      await pumpLogin(
        tester,
        _config(otpEnabled: true, loginEnabled: true, channels: const ['email'], label: ''),
      );

      expect(find.text('Log in with email code'), findsOneWidget);
    });
  });

  group('which channel a code goes on', () {
    test('email when the server lists email', () {
      expect(_emailConfig().otpChannel, 'email');
      expect(
        _config(otpEnabled: true, loginEnabled: true, channels: const ['email', 'sms']).otpChannel,
        'email',
      );
    });

    test('SMS only when the server offers SMS alone', () {
      expect(
        _config(otpEnabled: true, loginEnabled: true, channels: const ['sms']).otpChannel,
        'sms',
      );
    });

    test('email when no config has been read yet', () {
      expect(const AuthConfig().otpChannel, 'email');
    });
  });

  group('the OTP screen, by email', () {
    late SecureStore store;
    late _OtpServer server;
    late ApiClient client;

    setUp(() {
      store = SecureStore();
      server = _OtpServer();
      client = ApiClient(
        secureStore: store,
        connectivity: _Online(),
        responseCache: cache,
        dio: Dio()..httpClientAdapter = server,
      );
    });

    Future<void> pumpOtp(WidgetTester tester, AuthConfig config) async {
      tester.view.physicalSize = const Size(1170, 4800);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      final router = GoRouter(
        initialLocation: Routes.otp,
        routes: [
          GoRoute(path: Routes.otp, builder: (_, _) => const OtpScreen()),
          GoRoute(path: Routes.home, builder: (_, _) => const Scaffold(body: Text('HOME'))),
          GoRoute(path: Routes.login, builder: (_, _) => const Scaffold(body: Text('LOGIN'))),
          GoRoute(path: Routes.signUp, builder: (_, _) => const Scaffold(body: Text('SIGN UP'))),
        ],
      );
      addTearDown(router.dispose);

      await tester.pumpWidget(ProviderScope(
        overrides: [
          keyValueCacheProvider.overrideWithValue(cache),
          secureStoreProvider.overrideWithValue(store),
          apiClientProvider.overrideWithValue(client),
          authConfigProvider.overrideWith(() => _FixedAuthConfig(config)),
        ],
        child: MaterialApp.router(theme: AppTheme.light, routerConfig: router),
      ));
      await tester.pump();
    }

    /// A second of frames. Not pumpAndSettle: the resend countdown ticks every
    /// second for as long as the code step is on screen, so it never settles.
    Future<void> settle(WidgetTester tester) async {
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
    }

    Finder codeInput() => find.descendant(
          of: find.byType(OtpCodeField),
          matching: find.byType(TextField),
        );

    testWidgets('asks for an email address, never a mobile number', (tester) async {
      await pumpOtp(tester, _emailConfig());

      expect(find.text('Log in with a code'), findsOneWidget);
      expect(find.textContaining("We'll email you a 6-digit code"), findsOneWidget);
      expect(find.text('Email address'), findsOneWidget);
      expect(find.text('Mobile number'), findsNothing);
      expect(find.textContaining('SMS'), findsNothing);

      final field = tester.widget<TextField>(
        find.descendant(of: find.byType(ZTextField), matching: find.byType(TextField)),
      );
      expect(field.keyboardType, TextInputType.emailAddress);
    });

    testWidgets('refuses something that is not an email address, and sends nothing', (tester) async {
      await pumpOtp(tester, _emailConfig());

      await tester.enterText(find.byType(ZTextField), '9876543210');
      await tester.tap(find.text('Send code'));
      await tester.pump();

      expect(find.text('Enter a valid email address.'), findsOneWidget);
      expect(server.to('/auth/otp/send'), isEmpty);
    });

    testWidgets('emails the code, then signs in with it', (tester) async {
      await pumpOtp(tester, _emailConfig());

      await tester.enterText(find.byType(ZTextField), '  asha@example.com ');
      await tester.tap(find.text('Send code'));
      await settle(tester);

      final send = server.to('/auth/otp/send');
      expect(send, hasLength(1));
      expect(send.single.data, {
        'destination': 'asha@example.com',
        'channel': 'email',
        'purpose': 'login',
      });

      expect(find.text('Enter the code'), findsOneWidget);
      expect(find.text('Sent to asha@example.com'), findsOneWidget);
      expect(find.textContaining('+91'), findsNothing);
      // The server's resend_in (45 s), not a compiled 30 seconds.
      expect(find.textContaining('Resend in 0:4'), findsOneWidget);

      await tester.enterText(codeInput(), '123456');
      await settle(tester);

      final verify = server.to('/auth/otp/verify');
      expect(verify, hasLength(1));
      expect(verify.single.data, {
        'destination': 'asha@example.com',
        'code': '123456',
        'purpose': 'login',
      });
      expect(find.text('HOME'), findsOneWidget);
      expect(await store.readAccessToken(), 'access-1');
    });

    testWidgets('an address with no account is told to sign up, not signed up', (tester) async {
      server
        ..verifyStatus = 404
        ..verifyBody = {
          'success': false,
          'message': 'No Zusty account uses this email address. Create an account first.',
          'code': 'account_not_found',
        };
      await pumpOtp(tester, _emailConfig());

      await tester.enterText(find.byType(ZTextField), 'nobody@example.com');
      await tester.tap(find.text('Send code'));
      await settle(tester);
      await tester.enterText(codeInput(), '123456');
      await settle(tester);

      expect(find.text('No Zusty account uses this email address. Create an account first.'), findsOneWidget);
      expect(find.text('Create an account'), findsOneWidget);
      expect(find.text('HOME'), findsNothing);
      expect(await store.hasSession(), isFalse);
    });

    testWidgets('shows the phone step only when the server offers SMS alone', (tester) async {
      await pumpOtp(
        tester,
        _config(otpEnabled: true, loginEnabled: true, channels: const ['sms']),
      );

      expect(find.text('Sign in with OTP'), findsOneWidget);
      expect(find.text('Mobile number'), findsOneWidget);
      expect(find.text('Email address'), findsNothing);
    });
  });

  group('sending a code', () {
    test('goes by email unless told otherwise, and reports resend_in', () async {
      final server = _OtpServer();
      final store = SecureStore();
      final client = ApiClient(
        secureStore: store,
        connectivity: ConnectivityService(),
        responseCache: cache,
        dio: Dio()..httpClientAdapter = server,
      );

      final resendIn = await AuthRepository(client, store, cache).sendOtp('asha@example.com');

      expect(resendIn, 45);
      expect(server.requests.single.data, {
        'destination': 'asha@example.com',
        'channel': 'email',
        'purpose': 'login',
      });
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
        container.read(authProvider.notifier).verifyOtp(destination: 'asha@example.com', code: '123456'),
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

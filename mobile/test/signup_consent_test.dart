import 'dart:convert';

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
import 'package:zuste/data/repositories/app_config_repository.dart';
import 'package:zuste/data/repositories/auth_repository.dart';
import 'package:zuste/features/auth/login_screen.dart';
import 'package:zuste/features/auth/signup_screen.dart';
import 'package:zuste/features/auth/widgets/legal_links.dart';
import 'package:zuste/features/settings/settings_screen.dart';
import 'package:zuste/features/settings/widgets/account_sheets.dart';
import 'package:zuste/models/app_settings.dart';
import 'package:zuste/models/auth_config.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/app_config_provider.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/router/routes.dart';
import 'package:zuste/shared/widgets/z_text_field.dart';

/// Consent at sign-up, for Google Play review and the DPDP Act.
///
///  * Google sign-in, on the login and the sign-up screen, comes with "By
///    continuing, you agree to the Terms and Privacy Policy" directly above
///    its button, both documents linked, and tells the server so
///    (`accept_terms`), because a first sign-in creates the account.
///  * The email sign-up asks for an email address only, and its "product
///    updates and offers" box starts unticked; marketing is on only when it
///    is ticked.
///  * No SMS notification switch while SMS is off, and marketing reads as
///    off unless the server says the account turned it on.
///  * Every legal link is a 48 dp touch target (Play's accessibility check).
///  * Before any config has been read, the login field asks for an email.

/// Holds a fixed sign-in config instead of fetching one.
class _FixedAuthConfig extends AuthConfigController {
  _FixedAuthConfig(this.config);

  final AuthConfig config;

  @override
  AuthConfig build() => config;
}

/// Always online, without asking the platform.
class _Online extends ConnectivityService {
  @override
  Future<bool> isOnline() async => true;
}

/// Answers every request with a fresh session and records what was sent.
class _AuthServer implements HttpClientAdapter {
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);

    return ResponseBody.fromString(
      jsonEncode({
        'success': true,
        'message': 'Welcome to Zusty!',
        'data': {
          'user': {'id': 7, 'name': 'Asha', 'email': 'asha@example.com', 'account_type': 'brand'},
          'tokens': {'access_token': 'access-1', 'refresh_token': 'refresh-1'},
          'is_new_account': true,
        },
      }),
      200,
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

/// What the server sends today: email and password, Google ready on this
/// platform (Firebase options and the web client ID present), and the
/// operator's legal pages.
AuthConfig _config({
  bool google = true,
  String identifierMode = 'email',
  bool creatorSignup = false,
}) =>
    AuthConfig.fromJson({
      'version': 'test',
      'methods': [
        {
          'key': 'email_password',
          'enabled': true,
          'label': 'Email',
          'identifier_mode': identifierMode,
          'forgot_password_enabled': true,
        },
        {
          'key': 'google',
          'enabled': google,
          'label': 'Google',
          'exchange': 'firebase',
          'client_id': 'android-client.apps.googleusercontent.com',
          'server_client_id': 'web-client.apps.googleusercontent.com',
        },
        {'key': 'apple', 'enabled': false, 'label': 'Apple', 'platforms': ['ios', 'web']},
      ],
      'firebase': {
        'enabled': true,
        'project_id': 'zusty-test',
        'api_key': 'test-key',
        'app_id': '1:123:android:abc',
      },
      'signup': {
        'brand_enabled': true,
        'creator_enabled': creatorSignup,
        'terms_url': 'https://zusty.app/terms',
        'privacy_url': 'https://zusty.app/privacy',
      },
      'ui': {
        'divider_label': 'OR CONTINUE WITH',
        'signup_divider_label': 'OR SIGN UP WITH',
        'show_social_divider': google,
      },
    });

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;
  late SecureStore store;
  late _AuthServer server;
  late ApiClient client;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    cache = await KeyValueCache.open();
    store = SecureStore();
    server = _AuthServer();
    client = ApiClient(
      secureStore: store,
      connectivity: _Online(),
      responseCache: cache,
      dio: Dio()..httpClientAdapter = server,
    );
  });

  Future<void> pump(WidgetTester tester, String initial, AuthConfig config) async {
    tester.view.physicalSize = const Size(1170, 4800);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    final router = GoRouter(
      initialLocation: initial,
      routes: [
        GoRoute(path: Routes.login, builder: (_, _) => const LoginScreen()),
        GoRoute(path: Routes.signUp, builder: (_, _) => const SignUpScreen()),
        GoRoute(path: Routes.home, builder: (_, _) => const Scaffold(body: Text('HOME'))),
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

  /// A few frames, enough for a request to go out and its answer to land.
  Future<void> settle(WidgetTester tester) async {
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
  }

  /// The notice sits directly above the Google button: above it, and close.
  void expectNoticeAboveGoogle(WidgetTester tester) {
    final notice = tester.getRect(find.byType(LegalConsentNotice));
    final google = tester.getRect(find.text('Google'));

    expect(notice.bottom, lessThanOrEqualTo(google.top));
    expect(google.top - notice.bottom, lessThan(48));
  }

  /// The links in the notice, by label, with where each one goes.
  Map<String, String> noticeLinks(WidgetTester tester) => {
        for (final link in tester.widgetList<LegalLink>(
          find.descendant(of: find.byType(LegalConsentNotice), matching: find.byType(LegalLink)),
        ))
          link.label: link.url,
      };

  Finder fieldLabelled(String label) => find.descendant(
        of: find.widgetWithText(ZTextField, label),
        matching: find.byType(TextField),
      );

  group('the terms notice above Google sign-in', () {
    testWidgets('is on the login screen, with both documents linked', (tester) async {
      await pump(tester, Routes.login, _config());

      expect(find.textContaining('By continuing, you agree to the'), findsOneWidget);
      expect(noticeLinks(tester), {
        'Terms': 'https://zusty.app/terms',
        'Privacy Policy': 'https://zusty.app/privacy',
      });
      expectNoticeAboveGoogle(tester);
    });

    testWidgets('is on the sign-up screen too', (tester) async {
      await pump(tester, Routes.signUp, _config());

      expect(find.byType(LegalConsentNotice), findsOneWidget);
      expect(noticeLinks(tester).keys, ['Terms', 'Privacy Policy']);
      expectNoticeAboveGoogle(tester);
    });

    testWidgets('gives every legal link a full 48 dp touch target', (tester) async {
      await pump(tester, Routes.signUp, _config());

      // The notice above Google, and the terms box of the email sign-up.
      final links = find.descendant(
        of: find.byType(LegalLink),
        matching: find.byType(InkWell),
      );
      expect(links, findsNWidgets(4));

      for (final element in links.evaluate()) {
        final size = tester.getSize(find.byWidget(element.widget));
        expect(size.height, greaterThanOrEqualTo(48));
        expect(size.width, greaterThanOrEqualTo(48));
      }
    });

    testWidgets('is not shown when there is no social sign-in', (tester) async {
      await pump(tester, Routes.login, _config(google: false));

      expect(find.text('Google'), findsNothing);
      expect(find.byType(LegalConsentNotice), findsNothing);
    });

    test('a first Google sign-in tells the server the terms were agreed to', () async {
      final container = ProviderContainer(
        overrides: [
          keyValueCacheProvider.overrideWithValue(cache),
          secureStoreProvider.overrideWithValue(store),
          apiClientProvider.overrideWithValue(client),
        ],
      );
      addTearDown(container.dispose);

      container.read(authProvider);
      await Future<void>.delayed(Duration.zero);

      await container
          .read(authProvider.notifier)
          .signInWithProvider('firebase-id-token', role: UserRole.creator, acceptTerms: true);

      expect(server.to('/auth/firebase').single.data, {
        'id_token': 'firebase-id-token',
        'account_type': 'creator',
        'accept_terms': true,
      });
      expect(container.read(authProvider).isAuthenticated, isTrue);
    });

    test('without the notice, nothing is claimed', () async {
      await AuthRepository(client, store, cache).signInWithFirebase('firebase-id-token');

      expect(server.to('/auth/firebase').single.data, {'id_token': 'firebase-id-token'});
    });
  });

  group('the email sign-up', () {
    // The terms box comes first, the marketing box under it.
    Finder termsBox() => find.byType(Checkbox).first;
    Finder marketingBox() => find.byType(Checkbox).last;

    Future<void> fillIn(WidgetTester tester, {String email = 'asha@example.com'}) async {
      await tester.enterText(fieldLabelled('Your full name'), 'Asha Rao');
      await tester.enterText(fieldLabelled('Email address'), email);
      await tester.enterText(fieldLabelled('Password'), 'Zusty#2026');
      await tester.tap(termsBox());
      await tester.pump();
    }

    testWidgets('asks for an email address, never a phone number', (tester) async {
      await pump(tester, Routes.signUp, _config());

      expect(find.text('Email address'), findsOneWidget);
      expect(find.text('Email or phone'), findsNothing);
      expect(find.textContaining('98765'), findsNothing);
    });

    testWidgets('refuses a phone number, and sends nothing', (tester) async {
      await pump(tester, Routes.signUp, _config());

      await fillIn(tester, email: '9876543210');
      await tester.tap(find.text('Create account'));
      await tester.pump();

      expect(find.text('Enter a valid email address.'), findsOneWidget);
      expect(server.to('/auth/register'), isEmpty);
    });

    testWidgets('offers product updates and offers in an unticked box', (tester) async {
      await pump(tester, Routes.signUp, _config());

      expect(find.byType(Checkbox), findsNWidgets(2));
      expect(find.text('Send me product updates and offers'), findsOneWidget);
      expect(tester.widget<Checkbox>(marketingBox()).value, isFalse);
      expect(tester.widget<Checkbox>(termsBox()).value, isFalse);
    });

    testWidgets('left unticked, the account starts with marketing off', (tester) async {
      await pump(tester, Routes.signUp, _config());

      await fillIn(tester);
      await tester.tap(find.text('Create account'));
      await settle(tester);

      final body = server.to('/auth/register').single.data as Map;
      expect(body['email'], 'asha@example.com');
      expect(body['accept_terms'], isTrue);
      expect(body['marketing_opt_in'], isFalse);
      expect(body.containsKey('phone'), isFalse);
      expect(find.text('HOME'), findsOneWidget);
    });

    testWidgets('ticked, it asks for marketing on', (tester) async {
      await pump(tester, Routes.signUp, _config());

      await fillIn(tester);
      await tester.tap(find.text('Send me product updates and offers'));
      await tester.pump();
      await tester.tap(find.text('Create account'));
      await settle(tester);

      expect((server.to('/auth/register').single.data as Map)['marketing_opt_in'], isTrue);
    });

    testWidgets('the terms still have to be agreed to', (tester) async {
      await pump(tester, Routes.signUp, _config());

      await tester.enterText(fieldLabelled('Your full name'), 'Asha Rao');
      await tester.enterText(fieldLabelled('Email address'), 'asha@example.com');
      await tester.enterText(fieldLabelled('Password'), 'Zusty#2026');
      await tester.tap(find.text('Send me product updates and offers'));
      await tester.tap(find.text('Create account'));
      await tester.pump();

      expect(find.text('Please accept the Terms & Conditions to continue.'), findsOneWidget);
      expect(server.to('/auth/register'), isEmpty);
    });
  });

  group('the login field', () {
    testWidgets('asks for an email address', (tester) async {
      await pump(tester, Routes.login, _config());

      expect(find.text('Email'), findsOneWidget);
      expect(find.text('Email or phone'), findsNothing);

      await tester.enterText(fieldLabelled('Email'), 'not an address');
      await tester.tap(find.text('Log in'));
      await tester.pump();

      expect(find.text('Enter a valid email address.'), findsOneWidget);
      expect(server.to('/auth/login'), isEmpty);
    });

    testWidgets('still lets an older account sign in with its number', (tester) async {
      await pump(tester, Routes.login, _config());

      await tester.enterText(fieldLabelled('Email'), '9876543210');
      await tester.enterText(fieldLabelled('Password'), 'Zusty#2026');
      await tester.tap(find.text('Log in'));
      await settle(tester);

      expect((server.to('/auth/login').single.data as Map)['identifier'], '9876543210');
    });

    test('asks for an email before any config has been read', () {
      // Fresh install, never reached the server: the compiled fallback.
      final fallback = AppConfigRepository(client, cache).current.method('email_password');

      expect(fallback?.label, 'Email');
      expect(fallback?.string('identifier_mode'), 'email');
    });

    testWidgets('invites a number only when the operator says so', (tester) async {
      await pump(tester, Routes.login, _config(identifierMode: 'email_or_phone'));

      expect(find.text('Email or phone'), findsOneWidget);
    });
  });

  group('notification settings', () {
    test('marketing reads as off unless the server says it is on', () {
      expect(const NotificationPrefs().marketing, isFalse);
      expect(NotificationPrefs.fromJson(const {'push': true, 'email': true}).marketing, isFalse);
      expect(NotificationPrefs.fromJson(const {'marketing': true}).marketing, isTrue);
    });

    test('SMS counts as off until the server says otherwise', () {
      expect(FeatureFlags.empty.smsEnabled, isFalse);
      expect(FeatureFlags.fromJson(const {'sms_enabled': true}).smsEnabled, isTrue);
    });

    Future<void> pumpSettings(WidgetTester tester, {required bool sms}) async {
      tester.view.physicalSize = const Size(1170, 7200);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(ProviderScope(
        overrides: [
          keyValueCacheProvider.overrideWithValue(cache),
          currentUserProvider.overrideWithValue(User.fromJson(const {
            'id': 7,
            'name': 'Asha',
            'email': 'asha@example.com',
            'account_type': 'brand',
            'notification_prefs': {'push': true, 'email': true, 'sms': false},
          })),
          remoteAppConfigProvider.overrideWithValue(
            RemoteAppConfig(features: FeatureFlags({'sms_enabled': sms})),
          ),
          appVersionProvider.overrideWith((ref) async => '1.0.0'),
        ],
        child: MaterialApp(theme: AppTheme.light, home: const SettingsScreen()),
      ));
      await tester.pump();
    }

    testWidgets('offer no SMS switch while SMS is off', (tester) async {
      await pumpSettings(tester, sms: false);

      expect(find.text('Push notifications'), findsOneWidget);
      expect(find.text('Product updates & offers'), findsOneWidget);
      expect(find.text('SMS'), findsNothing);
    });

    testWidgets('offer it once SMS is on', (tester) async {
      await pumpSettings(tester, sms: true);

      expect(find.text('SMS'), findsOneWidget);
    });
  });
}

import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/config/app_config.dart';
import 'package:zuste/core/diagnostics/crash_reporting.dart';
import 'package:zuste/core/messaging/push_service.dart';
import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/network/device_headers.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/core/utils/share_links.dart';
import 'package:zuste/data/repositories/app_config_repository.dart';
import 'package:zuste/data/repositories/taxonomy_defaults.dart';
import 'package:zuste/data/repositories/wallet_repository.dart';
import 'package:zuste/features/booking/widgets/gateway_checkout.dart';
import 'package:zuste/features/wallet/widgets/wallet_sheets.dart';
import 'package:zuste/features/onboarding/widgets/onboarding_collage.dart';
import 'package:zuste/features/support/support_screen.dart';
import 'package:zuste/models/app_settings.dart';
import 'package:zuste/models/auth_config.dart';
import 'package:zuste/models/onboarding_slide.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/app_config_provider.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/push_provider.dart';
import 'package:zuste/providers/share_provider.dart';
import 'package:zuste/providers/taxonomy_provider.dart';
import 'package:zuste/router/routes.dart';
import 'package:zuste/shared/widgets/z_button.dart';
import 'package:zuste/shared/widgets/z_image.dart';
import 'package:zuste/shared/widgets/zuste_logo.dart';

import 'support/mock_data.dart';

/// The production-readiness fixes for the Play release: what a store build
/// talks to, what it shows a brand-new user, how it takes money, and what it
/// does with pushes and uploads.

/// Plays the server: records each request and answers with [answer].
class _Server implements HttpClientAdapter {
  _Server(this.answer);

  final ResponseBody Function(RequestOptions options) answer;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return answer(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _ok([Object? data = const {}]) => ResponseBody.fromString(
      jsonEncode({'success': true, 'message': 'OK', 'data': data}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

/// Records confirmations instead of asking a gateway.
class _RecordingWallet extends WalletRepository {
  _RecordingWallet()
      : super(ApiClient(secureStore: SecureStore(), connectivity: ConnectivityService()));

  final verified = <Map<String, dynamic>>[];

  @override
  Future<void> verifyPayment(Map<String, dynamic> payload) async => verified.add(payload);
}

/// Records top-ups instead of creating gateway orders; each one comes back
/// already settled, so the sheet finishes without a hosted page.
class _TopUpWallet extends WalletRepository {
  _TopUpWallet()
      : super(ApiClient(secureStore: SecureStore(), connectivity: ConnectivityService()));

  final topups = <(double, String?)>[];

  @override
  Future<Map<String, dynamic>> topup({required double amount, String? method}) async {
    topups.add((amount, method));
    return const {'gateway': 'cashfree', 'requires_client_action': false};
  }
}

AuthConfig _authConfig({
  bool firebase = true,
  String clientId = 'android-client',
  String serverClientId = 'web-client',
  String serviceId = '',
  String redirectUri = '',
}) =>
    AuthConfig.fromJson({
      'methods': [
        {'key': 'email_password', 'enabled': true},
        {
          'key': 'google',
          'enabled': true,
          'client_id': clientId,
          'server_client_id': serverClientId,
        },
        {
          'key': 'apple',
          'enabled': true,
          'platforms': ['ios', 'web'],
          'service_id': serviceId,
          'redirect_uri': redirectUri,
        },
        {'key': 'facebook', 'enabled': true},
      ],
      'firebase': firebase
          ? {'enabled': true, 'project_id': 'p', 'api_key': 'k', 'app_id': 'a'}
          : {'enabled': false, 'project_id': '', 'api_key': '', 'app_id': ''},
    });

List<String> _keys(List<AuthMethod> methods) => methods.map((m) => m.key).toList();

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    cache = await KeyValueCache.open();
  });

  ApiClient clientFor(_Server server) => ApiClient(
        secureStore: SecureStore(),
        connectivity: ConnectivityService(),
        responseCache: cache,
        dio: Dio()..httpClientAdapter = server,
      );

  // -------------------------------------------------------------------------
  // B1: what a build talks to.
  // -------------------------------------------------------------------------

  group('build configuration', () {
    test('debug builds default to the local API; release builds to production', () {
      // Tests run in debug mode, so this is the development default. The
      // release default is the same const expression's other branch.
      expect(AppConfig.apiOrigin, 'http://localhost:8000');
      expect(AppConfig.productionOrigin, 'https://zusty.app');
      expect(AppConfig.verboseNetworkLogging, isTrue);
    });

    test('links use the Zusty domain, not the old brand', () {
      expect(AppConfig.deepLinkHost, 'zusty.app');
      expect(AppConfig.websiteUrl, 'https://zusty.app');
    });
  });

  // -------------------------------------------------------------------------
  // B2 / N5: a social button only when the sign-in can complete.
  // -------------------------------------------------------------------------

  group('social sign-in buttons', () {
    test('none while Firebase is not configured, as on production today', () {
      final config = _authConfig(firebase: false, clientId: '', serverClientId: '');

      expect(config.socialFor('android'), isEmpty);
      expect(config.socialFor('ios'), isEmpty);
      expect(config.socialFor('web'), isEmpty);
    });

    test('Google on Android needs the Web client ID as its server client ID', () {
      expect(_keys(_authConfig(serverClientId: '').socialFor('android')), isEmpty);
      expect(_keys(_authConfig().socialFor('android')), ['google']);
    });

    test('Apple only where it can run, and Facebook never', () {
      expect(_keys(_authConfig().socialFor('ios')), ['google', 'apple']);
      expect(_keys(_authConfig().socialFor('web')), ['google']);
      expect(
        _keys(_authConfig(serviceId: 'svc', redirectUri: 'https://zusty.app/cb').socialFor('web')),
        ['google', 'apple'],
      );
    });
  });

  // -------------------------------------------------------------------------
  // I3: the server can tell which version is asking.
  // -------------------------------------------------------------------------

  group('device headers', () {
    test('/app/config is asked for with this install id and store version', () async {
      final server = _Server((_) => _ok());
      final client = clientFor(server);

      await applyDeviceHeaders(client, cache, installedVersion: () async => '1.0.1');
      await client.get('/app/config', skipAuth: true);

      final headers = server.requests.single.headers;
      expect(headers['X-App-Version'], '1.0.1');
      expect(cache.deviceId, isNotEmpty);
      expect(headers['X-Device-Id'], cache.deviceId);
    });

    test('the installed version is sent without its build number', () async {
      PackageInfo.setMockInitialValues(
        appName: 'Zusty',
        packageName: 'com.zuste.zuste',
        version: '1.0.1',
        buildNumber: '2',
        buildSignature: '',
      );
      final server = _Server((_) => _ok());
      final client = clientFor(server);

      await applyDeviceHeaders(client, cache);
      await client.get('/app/config', skipAuth: true);

      expect(server.requests.single.headers['X-App-Version'], '1.0.1');
    });
  });

  // -------------------------------------------------------------------------
  // I5: uploads.
  // -------------------------------------------------------------------------

  group('uploads', () {
    test('get minutes to send, and a slow uplink does not take the app offline', () async {
      final server = _Server((options) {
        if (options.method == 'POST') {
          throw DioException(requestOptions: options, type: DioExceptionType.sendTimeout);
        }
        return _ok({'items': []});
      });
      final client = clientFor(server);

      await expectLater(
        client.uploadBytes(
          '/me/portfolio',
          field: 'media',
          bytes: Uint8List(16),
          fileName: 'reel.mp4',
        ),
        throwsA(isA<TimeoutFailure>()),
      );

      final upload = server.requests.single;
      expect(upload.sendTimeout, AppConfig.uploadSendTimeout);
      expect(upload.receiveTimeout, AppConfig.slowRequestTimeout);

      // The next request reaches the server instead of being refused for 30s.
      final next = await client.get('/me/portfolio');
      expect(next.success, isTrue);
      expect(server.requests.map((r) => r.method), ['POST', 'GET']);
    });
  });

  // -------------------------------------------------------------------------
  // B3 / B4: money is only reported once it has moved.
  // -------------------------------------------------------------------------

  group('gateway checkout', () {
    Future<bool?> run(
      WidgetTester tester,
      Map<String, dynamic> order,
      _RecordingWallet wallet,
    ) async {
      bool? result;

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            keyValueCacheProvider.overrideWithValue(cache),
            walletRepositoryProvider.overrideWithValue(wallet),
          ],
          child: MaterialApp(
            theme: AppTheme.light,
            home: Scaffold(
              body: Consumer(
                builder: (context, ref, _) => TextButton(
                  onPressed: () async =>
                      result = await completeGatewayPayment(context, ref, order),
                  child: const Text('Pay'),
                ),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('Pay'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      return result;
    }

    testWidgets('an order the wallet settled needs nothing more', (tester) async {
      final wallet = _RecordingWallet();

      final paid = await run(tester, {'gateway': 'wallet', 'requires_client_action': false}, wallet);

      expect(paid, isTrue);
      expect(wallet.verified, isEmpty);
    });

    testWidgets('a gateway order with no hosted page is refused, never reported paid',
        (tester) async {
      final wallet = _RecordingWallet();

      final paid = await run(
        tester,
        {'gateway': 'razorpay', 'order_id': 'order_1', 'requires_client_action': true},
        wallet,
      );

      expect(paid, isFalse);
      expect(find.text('This payment method is not available right now.'), findsOneWidget);
      expect(wallet.verified, isEmpty);
    });
  });

  // -------------------------------------------------------------------------
  // M3: the top-up sheet promises no choice it cannot honour.
  // -------------------------------------------------------------------------

  group('wallet top-up', () {
    testWidgets('has no payment-method chips, and says where the method is chosen',
        (tester) async {
      tester.view.physicalSize = const Size(1170, 4800);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      final wallet = _TopUpWallet();

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            keyValueCacheProvider.overrideWithValue(cache),
            walletRepositoryProvider.overrideWithValue(wallet),
            taxonomiesProvider.overrideWithValue(TaxonomyDefaults.bundle),
          ],
          child: MaterialApp(
            theme: AppTheme.light,
            home: Scaffold(
              body: Builder(
                builder: (context) => TextButton(
                  onPressed: () => TopUpSheet.show(context),
                  child: const Text('Open'),
                ),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('Open'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      expect(tester.takeException(), isNull);
      expect(find.text(topUpPaymentNote), findsOneWidget);
      expect(topUpPaymentNote, contains('UPI, card or net banking'));
      expect(topUpPaymentNote, contains('Cashfree'));
      expect(find.text('Pay using'), findsNothing);
      for (final chip in ['UPI', 'Card', 'Net banking']) {
        expect(find.text(chip), findsNothing, reason: chip);
      }

      // Paying still works, and asks for no method.
      await tester.enterText(find.byType(TextField), '500');
      await tester.tap(find.widgetWithText(ZButton, 'Add money'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      expect(wallet.topups, [(500.0, null)]);
      expect(find.text('Payment confirmed. The money is in your wallet.'), findsOneWidget);
    });

    test('a top-up with no method goes to the enabled gateway this build can complete',
        () async {
      final server = _Server((options) => switch (options.path) {
            '/payments/methods' => _ok({
                'gateways': [
                  {'id': 'razorpay', 'label': 'Card', 'methods': ['card']},
                  {'id': 'cashfree', 'label': 'UPI / Card / Net Banking', 'methods': ['upi', 'card', 'netbanking']},
                ],
              }),
            _ => _ok({
                'gateway': 'cashfree',
                'order_id': 'ZT-TOPUP-1',
                'checkout_url': 'https://payments.cashfree.com/order/#session',
                'requires_client_action': true,
              }),
          });

      final order = await WalletRepository(clientFor(server)).topup(amount: 1000);

      final post = server.requests.last;
      expect(post.path, '/wallet/topup');
      expect((post.data as Map)['gateway'], 'cashfree');
      expect((post.data as Map).containsKey('method'), isFalse);
      expect(order['requires_client_action'], isTrue);
    });
  });

  // -------------------------------------------------------------------------
  // B5: the first screen.
  // -------------------------------------------------------------------------

  testWidgets('onboarding without admin artwork shows bundled brand art, fetching nothing',
      (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        home: const Scaffold(
          body: SizedBox(
            width: 360,
            height: 400,
            child: OnboardingCollage(
              slide: OnboardingSlide(id: 1, title: 'Find creators', icon: 'search'),
            ),
          ),
        ),
      ),
    );

    expect(tester.takeException(), isNull);
    expect(find.byType(ZImage), findsNothing);

    final images = tester.widgetList<Image>(find.byType(Image));
    expect(images, isNotEmpty);
    for (final image in images) {
      expect(image.image, isA<AssetImage>());
      expect((image.image as AssetImage).assetName, BrandAssets.mark);
    }
  });

  // -------------------------------------------------------------------------
  // B6 / I8: what the app tells people.
  // -------------------------------------------------------------------------

  group('shared and shown contacts', () {
    const listing = 'https://play.google.com/store/apps/details?id=com.zuste.zuste';

    test('"Share Zusty" shares the store listing only once it is live, else the website', () {
      const gate = UpdateGate(storeUrl: listing);

      expect(shareAppText(gate, storeLive: true), contains(listing));

      // The store URL is set long before the listing is public, and Google
      // Play answers it with 404 until then.
      expect(shareAppText(gate, storeLive: false), contains('https://zusty.app'));
      expect(shareAppText(gate, storeLive: false), isNot(contains('play.google.com')));

      // Live, but no usable listing configured: still the website.
      expect(shareAppText(UpdateGate.empty, storeLive: true), contains('https://zusty.app'));
      expect(
        shareAppText(const UpdateGate(storeUrl: 'market://details?id=x'), storeLive: true),
        contains('https://zusty.app'),
      );

      for (final live in [true, false]) {
        expect(shareAppText(UpdateGate.empty, storeLive: live), isNot(contains('zuste.com')));
      }
    });

    group('whether the Play listing is live', () {
      Future<bool> liveWhen(_Server server) =>
          AppConfigRepository(clientFor(server), cache).isStoreListingLive();

      ResponseBody settings(Map<String, dynamic> app) => _ok({
            'settings': {'app': app},
          });

      test('is read from GET /settings → app.android_store_live', () async {
        final server = _Server(
          (_) => settings({'android_store_url': listing, 'android_store_live': true}),
        );

        expect(await liveWhen(server), isTrue);
        expect(server.requests.single.method, 'GET');
        expect(server.requests.single.path, '/settings');
      });

      test('is false while the switch is off', () async {
        expect(
          await liveWhen(_Server(
            (_) => settings({'android_store_url': listing, 'android_store_live': false}),
          )),
          isFalse,
        );
      });

      test('is false on a backend without the setting', () async {
        expect(await liveWhen(_Server((_) => settings({'android_store_url': listing}))), isFalse);
        // PHP renders an empty settings object as [].
        expect(await liveWhen(_Server((_) => _ok({'settings': <Object>[]}))), isFalse);
      });

      test('is false when the server cannot be reached', () async {
        expect(
          await liveWhen(_Server(
            (options) => throw DioException(
              requestOptions: options,
              type: DioExceptionType.connectionError,
            ),
          )),
          isFalse,
        );
      });
    });

    test('the share text follows the server switch', () async {
      Future<String> shareTextWhen(bool live) async {
        final container = ProviderContainer(overrides: [
          keyValueCacheProvider.overrideWithValue(cache),
          apiClientProvider.overrideWithValue(clientFor(_Server(
            (_) => _ok({
              'settings': {
                'app': {'android_store_url': listing, 'android_store_live': live},
              },
            }),
          ))),
          updateGateProvider.overrideWithValue(const UpdateGate(storeUrl: listing)),
        ]);
        addTearDown(container.dispose);

        // Before the answer arrives, the website — never a listing that may
        // not exist yet.
        expect(container.read(shareAppTextProvider), contains('https://zusty.app'));
        expect(container.read(shareAppTextProvider), isNot(contains(listing)));

        await container.read(storeListingLiveProvider.future);
        return container.read(shareAppTextProvider);
      }

      expect(await shareTextWhen(true), contains(listing));

      final notLive = await shareTextWhen(false);
      expect(notLive, contains('https://zusty.app'));
      expect(notLive, isNot(contains('play.google.com')));
    });

    testWidgets('support shows the zusty.app address and no invented phone number',
        (tester) async {
      tester.view.physicalSize = const Size(1170, 7200);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            keyValueCacheProvider.overrideWithValue(cache),
            currentUserProvider.overrideWithValue(User.fromJson(MockData.currentUser)),
          ],
          child: MaterialApp(theme: AppTheme.light, home: const SupportScreen()),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(tester.takeException(), isNull);
      expect(find.text('support@zusty.app'), findsOneWidget);
      expect(find.text('Call us'), findsNothing);
      expect(find.textContaining('1800'), findsNothing);
    });
  });

  // -------------------------------------------------------------------------
  // I4: pushes.
  // -------------------------------------------------------------------------

  group('push', () {
    test('a tapped push opens what it is about, or the inbox', () {
      expect(
        pushTarget(const PushMessage(data: {'action_type': 'booking', 'action_value': '42'})),
        Routes.booking(42),
      );
      expect(pushTarget(const PushMessage(data: {'action_type': 'none'})), Routes.notifications);
      expect(pushTarget(const PushMessage()), Routes.notifications);
      expect(
        pushTarget(const PushMessage(
          data: {'action_type': 'url', 'action_value': 'https://zusty.app/about'},
        )),
        'https://zusty.app/about',
      );
    });

    test('a push reads as the inbox row it mirrors', () {
      final row = const PushMessage(
        title: 'Booking accepted',
        body: 'Aanya accepted your booking.',
        data: {'notification_id': '91', 'type': 'booking.accepted', 'action_type': 'booking', 'action_value': '7'},
      ).notification;

      expect(row.id, 91);
      expect(row.title, 'Booking accepted');
      expect(row.actionUrl, Routes.booking(7));
    });

    group('the Android build', () {
      String read(String path) => File(path).readAsStringSync();

      test('opens the app when a push with the server\'s click action is tapped', () {
        final manifest = read('android/app/src/main/AndroidManifest.xml');

        expect(manifest, contains('<action android:name="FLUTTER_NOTIFICATION_CLICK"/>'));
        expect(manifest, contains('com.google.firebase.messaging.default_notification_icon'));
        expect(manifest, contains('@drawable/ic_stat_notification'));
        expect(File('android/app/src/main/res/drawable/ic_stat_notification.xml').existsSync(), isTrue);
        expect(read('android/app/src/main/kotlin/com/zuste/zuste/MainActivity.kt'), contains('"zuste_default"'));
      });

      test('declares no advertising ID or biometric permission', () {
        final manifest = read('android/app/src/main/AndroidManifest.xml');

        for (final permission in [
          'com.google.android.gms.permission.AD_ID',
          'android.permission.USE_BIOMETRIC',
          'android.permission.USE_FINGERPRINT',
        ]) {
          expect(
            manifest,
            contains('<uses-permission android:name="$permission" tools:node="remove"/>'),
            reason: permission,
          );
        }
        expect(read('pubspec.yaml'), isNot(contains('firebase_analytics')));
      });
    });
  });

  // -------------------------------------------------------------------------
  // I1: a widget that fails in a release build.
  // -------------------------------------------------------------------------

  testWidgets('the release error widget says something went wrong', (tester) async {
    await tester.pumpWidget(const ReleaseErrorWidget());

    expect(find.textContaining('Something went wrong'), findsOneWidget);
  });
}

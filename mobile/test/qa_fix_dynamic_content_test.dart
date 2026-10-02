// TEMPORARY verification harness for the dynamic-content fixes.
//
// Every payload it feeds the app is a real response captured from the running
// backend (see the `qa-fix-` json files in the scratchpad), so a pass here means
// the app renders what the admin panel actually serves. Delete once the fixes
// are signed off.
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/base_repository.dart';
import 'package:zuste/models/app_settings.dart';
import 'package:zuste/models/category.dart';
import 'package:zuste/models/home_feed.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/app_config_provider.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/discovery_provider.dart';
import 'package:zuste/router/app_router.dart';
import 'package:zuste/router/routes.dart';

import 'package:zuste/features/home/home_screen.dart';
import 'package:zuste/features/support/support_screen.dart';

import 'support/fixtures.dart';
import 'support/mock_data.dart';

/// Responses captured from the live API (`GET /app/config` as build 1.0.0 and
/// as 0.9.0, and `GET /home` signed out), kept with the tests so they run
/// anywhere. They used to be read from a session's temp folder, which broke
/// the suite as soon as that folder was cleaned up. Re-capture them with curl
/// when the payloads change.
const _fixtures = 'test/fixtures';

Map<String, dynamic> _liveData(String file) {
  final raw = File('$_fixtures/$file').readAsStringSync();
  // The capture files start with a UTF-8 BOM from PowerShell's Out-File.
  final json = jsonDecode(raw.replaceFirst('\uFEFF', '')) as Map;
  return (json['data'] as Map).cast<String, dynamic>();
}

/// Holds whatever stage the test asks for, so the router's own gate is the only
/// thing under test.
class _StubAuth extends AuthController {
  _StubAuth(this._state);

  final AuthState _state;

  @override
  AuthState build() => _state;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    cache = await KeyValueCache.open();
    BaseRepository.demoMode = true;
    registerTestFixtures();
    PopupSeenStore.resetSession();
  });

  Future<void> seedConfig(Map<String, dynamic> document) =>
      cache.writeDocument(remoteConfigCacheKey, document);

  Future<void> pumpScreen(
    WidgetTester tester,
    Widget screen, {
    List<Override> overrides = const [],
  }) async {
    tester.view.physicalSize = const Size(1170, 7200);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          keyValueCacheProvider.overrideWithValue(cache),
          currentUserProvider.overrideWithValue(
            User.fromJson(MockData.currentUser),
          ),
          ...overrides,
        ],
        child: MaterialApp(theme: AppTheme.light, home: screen),
      ),
    );

    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
  }

  group('C49 — every /app/config block reaches a model', () {
    test('the live payload parses into every block, not just auth', () {
      final config = RemoteAppConfig.fromJson(_liveData('appconfig.json'));

      // App identity — the values an operator edits under Settings → General.
      expect(config.identity.name, 'Zusty');
      expect(config.identity.supportEmail, 'support@zustee.com');
      expect(config.identity.supportPhone, '+91 90000 00000');

      // Branding.
      expect(config.branding.logoUrl, '/uploads/branding/zuste-logo.png');
      expect(config.branding.primary, const Color(0xFF00B815));

      // Feature flags: the settings-backed switches and the feature_flags rows
      // arrive merged, and both have to survive the parse.
      expect(config.features.walletEnabled, isTrue);
      expect(config.features.isOn('ai_creator_match'), isFalse);
      expect(config.features.values.length, greaterThan(6));

      // Popups, update gate, social and legal.
      expect(config.popups, isNotEmpty);
      expect(config.popups.first.id, 1517);
      expect(config.update.minVersion, '1.0.0');
      expect(config.social.configured, isNotEmpty);
      expect(config.legal.termsUrl, isNotEmpty);
    });

    test('an unfetched config leaves every switch as this build shipped', () {
      const flags = FeatureFlags.empty;

      expect(flags.walletEnabled, isTrue);
      expect(flags.referralEnabled, isTrue);
      expect(flags.realtimeChatEnabled, isTrue);

      // Nothing compiled to render these, so they stay off until asked for.
      expect(flags.couponsAtCheckout, isFalse);
    });
  });

  group('C50 — force-update gate', () {
    testWidgets('an outdated build is held on the update screen',
        (tester) async {
      // Captured from the live server with X-App-Version: 0.9.0, which is below
      // the configured android_min_version.
      await seedConfig(_liveData('appconfig_old.json'));

      tester.view.physicalSize = const Size(1170, 2400);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      final container = ProviderContainer(
        overrides: [keyValueCacheProvider.overrideWithValue(cache)],
      );
      addTearDown(container.dispose);

      expect(container.read(mustUpdateProvider), isTrue);

      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: ProviderContainer(
            overrides: [
              keyValueCacheProvider.overrideWithValue(cache),
              authProvider.overrideWith(
                () => _StubAuth(const AuthState(stage: AuthStage.onboarding)),
              ),
            ],
          ),
          child: Consumer(
            builder: (_, ref, _) => MaterialApp.router(
              theme: AppTheme.light,
              routerConfig: ref.watch(routerProvider),
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(tester.takeException(), isNull);
      expect(find.text('Update Zusty to continue'), findsOneWidget);
      expect(find.text('Update now'), findsOneWidget);

      // The admin's message, not a compiled one.
      expect(
        find.textContaining('A newer version of Zusty is available'),
        findsOneWidget,
      );
    });

    testWidgets('a current build is never gated', (tester) async {
      await seedConfig(_liveData('appconfig.json'));

      final container = ProviderContainer(
        overrides: [keyValueCacheProvider.overrideWithValue(cache)],
      );
      addTearDown(container.dispose);

      expect(container.read(mustUpdateProvider), isFalse);
    });
  });

  group('C51 — popups', () {
    test('the live popup carries its trigger, frequency and placement', () {
      final config = RemoteAppConfig.fromJson(_liveData('appconfig.json'));
      final popup = config.popups.first;

      // Popup 1517, the brand first-booking offer live in the seed data. (The
      // fixture used to be the referral slide-in, which expired on 18 Sep.)
      expect(popup.title, '10% off your first booking');
      expect(popup.placement, 'home');
      expect(popup.showsOn('home'), isTrue);
      expect(popup.trigger, 'delay');
      expect(popup.delay, const Duration(seconds: 5));
      expect(popup.frequency, 'once');
      expect(popup.isDismissible, isTrue);
      expect(popup.isSlideIn, isFalse);
      expect(Routes.resolveMenuTarget(popup.ctaValue), Routes.explore);
    });

    test('frequency is honoured across a restart', () async {
      final store = PopupSeenStore(cache);
      const once = AppPopup(id: 4242, frequency: 'once');

      expect(store.hasShown(once), isFalse);
      await store.markShown(once);
      expect(store.hasShown(once), isTrue);

      // A fresh store over the same device cache is what a relaunch looks like.
      PopupSeenStore.resetSession();
      expect(PopupSeenStore(cache).hasShown(once), isTrue);

      // once_per_session forgets when the process does.
      const perSession = AppPopup(id: 4243, frequency: 'once_per_session');
      await store.markShown(perSession);
      expect(store.hasShown(perSession), isTrue);
      PopupSeenStore.resetSession();
      expect(PopupSeenStore(cache).hasShown(perSession), isFalse);
    });

    testWidgets('an immediate popup is rendered on the screen it targets',
        (tester) async {
      await seedConfig({
        'popups': [
          {
            'id': 9001,
            'type': 'announcement_bar',
            'title': 'Finish verification to unlock payouts',
            'content': 'Payouts stay locked until your KYC is approved.',
            'cta_label': 'Verify now',
            'cta_action': 'screen',
            'cta_value': 'kyc',
            'placement': 'home',
            'trigger': 'immediate',
            'trigger_value': 0,
            'frequency': 'always',
            'is_dismissible': true,
          },
        ],
      });

      await pumpScreen(tester, const HomeScreen());

      expect(tester.takeException(), isNull);
      expect(
        find.text('Finish verification to unlock payouts'),
        findsOneWidget,
      );
      expect(find.text('Verify now'), findsOneWidget);
    });

    testWidgets('a popup already shown today does not come back',
        (tester) async {
      const popup = AppPopup(id: 9002, frequency: 'once_per_day');
      await PopupSeenStore(cache).markShown(popup);
      PopupSeenStore.resetSession();

      await seedConfig({
        'popups': [
          {
            'id': 9002,
            'type': 'announcement_bar',
            'title': 'Already seen today',
            'placement': 'home',
            'trigger': 'immediate',
            'frequency': 'once_per_day',
          },
        ],
      });

      await pumpScreen(tester, const HomeScreen());

      expect(tester.takeException(), isNull);
      expect(find.text('Already seen today'), findsNothing);
    });
  });

  group('C52 — home sections come from the admin panel', () {
    test('the live feed parses its section list', () {
      final feed = HomeFeed.fromJson(_liveData('home.json'));

      expect(feed.sections, isNotEmpty);
      expect(
        feed.sections.map((s) => s.title),
        containsAll(<String>['Top Categories', 'Top 10+ new creators']),
      );
    });

    testWidgets('the screen renders the admin titles, not the compiled ones',
        (tester) async {
      final feed = HomeFeed.fromJson(_liveData('home.json'));

      await pumpScreen(
        tester,
        const HomeScreen(),
        overrides: [
          homeFeedProvider.overrideWith((ref) async => feed),
        ],
      );

      expect(tester.takeException(), isNull);

      // 'Top 10+ new creators' is the row in homepage_sections. The '+' has to
      // survive: the old compiled title had drifted from the admin's and
      // rendered without it.
      expect(find.text('Top 10+ new creators'), findsOneWidget);
      expect(find.text('Top 10 new creators'), findsNothing);

      // Server-set subtitles and CTA labels render too.
      expect(find.text('Handpicked for your brand'), findsOneWidget);
      expect(find.text('Most booked this week'), findsOneWidget);
    });

    testWidgets('a feed with no sections still renders the old layout',
        (tester) async {
      // The demo fixtures predate `sections`, which is exactly the shape an
      // older backend returns.
      await pumpScreen(tester, const HomeScreen());

      expect(tester.takeException(), isNull);
      expect(find.text('Recommended Creators'), findsOneWidget);
    });
  });

  group('C72 — category icons', () {
    test('every seeded icon name resolves to a real glyph', () {
      // The names in the `categories` table right now.
      const seeded = [
        'auto_awesome',
        'checkroom',
        'flight_takeoff',
        'restaurant',
        'fitness_center',
        'devices',
        'face_retouching_natural',
        'sports_esports',
        'spa',
        'music_note',
        'school',
        'savings',
        'child_care',
        'directions_car',
        'theater_comedy',
        'palette',
      ];

      for (final name in seeded) {
        final category = Category(id: 1, name: name, slug: name, icon: name);
        expect(
          category.iconData,
          isNot(Icons.category_rounded),
          reason: '$name still falls back to the generic glyph',
        );
      }
    });
  });

  group('C75 — support contacts', () {
    testWidgets('the screen shows the configured address, not a compiled one',
        (tester) async {
      await seedConfig(_liveData('appconfig.json'));

      await pumpScreen(tester, const SupportScreen());

      expect(tester.takeException(), isNull);
      expect(find.text('support@zustee.com'), findsOneWidget);
      expect(find.text('+91 90000 00000'), findsOneWidget);
      expect(find.text('support@zuste.com'), findsNothing);
    });

    testWidgets('with no config fetched it keeps its own fallback',
        (tester) async {
      await pumpScreen(tester, const SupportScreen());

      expect(tester.takeException(), isNull);
      expect(find.text('Email support'), findsOneWidget);
    });
  });
}

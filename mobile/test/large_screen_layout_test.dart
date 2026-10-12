import 'dart:math' as math;

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/app.dart';
import 'package:zuste/core/layout/orientation_policy.dart';
import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/base_repository.dart';
import 'package:zuste/data/repositories/creator_tools_repository.dart';
import 'package:zuste/models/app_settings.dart';
import 'package:zuste/models/creator.dart';
import 'package:zuste/models/creator_tools.dart';
import 'package:zuste/models/onboarding_slide.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/account_provider.dart';
import 'package:zuste/providers/app_config_provider.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/creator_tools_provider.dart';
import 'package:zuste/providers/onboarding_provider.dart';
import 'package:zuste/providers/opportunity_provider.dart';
import 'package:zuste/shared/motion/motion.dart';
import 'package:zuste/shared/widgets/campaign_card.dart';
import 'package:zuste/shared/widgets/creator_card.dart';
import 'package:zuste/shared/widgets/creator_grid.dart';
import 'package:zuste/shared/widgets/z_button.dart';
import 'package:zuste/shared/widgets/z_card.dart';
import 'package:zuste/shared/widgets/z_dialogs.dart';
import 'package:zuste/shared/widgets/z_scaffold.dart';
import 'package:zuste/shared/widgets/z_tabs.dart';
import 'package:zuste/shared/widgets/z_text_field.dart';

import 'package:zuste/features/auth/login_screen.dart';
import 'package:zuste/features/auth/otp_screen.dart';
import 'package:zuste/features/auth/signup_screen.dart';
import 'package:zuste/features/booking/booking_details_screen.dart';
import 'package:zuste/features/booking/my_bookings_screen.dart';
import 'package:zuste/features/booking/new_booking_screen.dart';
import 'package:zuste/features/chat/chat_screen.dart';
import 'package:zuste/features/chat/messages_screen.dart';
import 'package:zuste/features/creator/creator_profile_screen.dart';
import 'package:zuste/features/creator/manage/my_packages_screen.dart';
import 'package:zuste/features/creator/widgets/profile_layout.dart';
import 'package:zuste/features/dashboard/brand_dashboard_screen.dart';
import 'package:zuste/features/discovery/explore_screen.dart';
import 'package:zuste/features/discovery/saved_creators_screen.dart';
import 'package:zuste/features/home/home_screen.dart';
import 'package:zuste/features/onboarding/onboarding_screen.dart';
import 'package:zuste/features/opportunities/opportunities_screen.dart';
import 'package:zuste/features/profile/menu_screen.dart';
import 'package:zuste/features/search/search_results_screen.dart';
import 'package:zuste/features/search/search_screen.dart';
import 'package:zuste/features/settings/settings_screen.dart';
import 'package:zuste/features/settings/widgets/account_sheets.dart';
import 'package:zuste/features/shell/app_shell.dart';
import 'package:zuste/features/wallet/wallet_screen.dart';

import 'support/fixtures.dart';
import 'support/mock_data.dart';

/// Tablets: the phone layouts centred in a capped column on a medium or
/// expanded window, navigation on a rail in landscape, grids and card lists
/// re-flowed — and phones (under 600dp) exactly as they were.
///
/// Every main screen is rendered at a phone size and at the sizes Google Play
/// shows tablet screenshots at: a 7-inch (600x960) and a 10-inch (800x1280)
/// tablet in portrait, and a 10-inch in landscape (1280x800), plus a 7-inch in
/// landscape (960x600), the shortest window the app has to fit. A RenderFlex
/// overflow is reported as a FlutterError, which `takeException` surfaces.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    cache = await KeyValueCache.open();
    BaseRepository.demoMode = true;
    // The shared fixtures have no entry for the public brief board; the
    // brand campaign rows have the same shape.
    BaseRepository.fixtures = (key) =>
        key == 'campaigns.open' ? MockData.campaigns : resolveTestFixture(key);
  });

  tearDown(clearTestFixtures);

  const phone = Size(390, 844);
  const sizes = <String, Size>{
    'phone 390x844': phone,
    // The widest phone: one dp short of the first tablet breakpoint.
    'phone 599x900': Size(599, 900),
    '7-inch 600x960': Size(600, 960),
    // A 1080x1920 7-inch tablet at 288dpi: sw600dp to Android, but a float
    // density leaves Flutter a hair under 600dp. Still a tablet.
    '7-inch float 599.99998x960': Size(_floatSw600, 960),
    '10-inch 800x1280': Size(800, 1280),
    'landscape 1280x800': Size(1280, 800),
    '7-inch landscape 960x600': Size(960, 600),
  };

  final brand = User.fromJson(MockData.currentUser);
  final creator = User.fromJson({
    ...MockData.currentUser,
    'role': 'creator',
    'is_creator': true,
  });

  /// A signed-in session and the providers the screens need offline.
  List<Override> session(User signedIn) => [
    keyValueCacheProvider.overrideWithValue(cache),
    authProvider.overrideWith(() => _SignedIn(signedIn)),
    currentUserProvider.overrideWithValue(signedIn),
    isCreatorSessionProvider.overrideWithValue(
      signedIn.role.isCreator || signedIn.isCreator,
    ),
    onboardingSlidesProvider.overrideWith((ref) async => _slides),
    remoteAppConfigProvider.overrideWithValue(const RemoteAppConfig()),
    appVersionProvider.overrideWith((ref) async => '1.0.0'),
  ];

  /// A window of [size] dp, with an on-screen keyboard [keyboard] dp tall and,
  /// when [statusBar] is given, a status bar that many dp tall.
  void setWindow(
    WidgetTester tester,
    Size size, {
    double keyboard = 0,
    double statusBar = 0,
  }) {
    tester.view.physicalSize = size * 2;
    tester.view.devicePixelRatio = 2;
    tester.view.viewInsets = FakeViewPadding(bottom: keyboard * 2);
    if (statusBar > 0) {
      tester.view.padding = FakeViewPadding(top: statusBar * 2);
    }
    addTearDown(tester.view.reset);
  }

  Future<void> pumpAt(
    WidgetTester tester,
    Size size,
    Widget home, {
    User? user,
    List<Override> overrides = const [],
    double keyboard = 0,
    double statusBar = 0,
  }) async {
    setWindow(tester, size, keyboard: keyboard, statusBar: statusBar);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [...session(user ?? brand), ...overrides],
        child: MaterialApp(theme: AppTheme.light, home: home),
      ),
    );

    // Settle the FutureProviders and entrance animations without waiting on
    // the chat poll or the hero slider's autoplay.
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
  }

  /// The page's main scroll area: of the scrollables not inside another one
  /// scrolling the same way (a shrink-wrapped grid in a list does not
  /// count), the one that takes up the most of the screen, preferring one
  /// that scrolls down the page over a pager. Its width is the width the
  /// page's content was laid out at.
  Rect mainColumn(WidgetTester tester) {
    final screen = Offset.zero & tester.view.physicalSize / 2;

    Axis? axisOf(Widget widget) =>
        widget is Scrollable ? axisDirectionToAxis(widget.axisDirection) : null;

    Rect? best;
    var bestScore = -1.0;
    for (final element in find.byType(Scrollable).evaluate()) {
      final axis = axisOf(element.widget);
      var nested = false;
      element.visitAncestorElements((ancestor) {
        nested = axisOf(ancestor.widget) == axis;
        return !nested;
      });
      if (nested) continue;

      final box = element.renderObject;
      if (box is! RenderBox || !box.hasSize) continue;
      final rect = box.localToGlobal(Offset.zero) & box.size;
      final onScreen = rect.intersect(screen);
      var score = onScreen.isEmpty ? 0.0 : onScreen.width * onScreen.height;
      // Any vertical scroll area outranks any pager.
      if (axis == Axis.vertical) score += screen.width * screen.height;
      if (score > bestScore) {
        best = rect;
        bestScore = score;
      }
    }
    expect(best, isNotNull, reason: 'no scrollable on screen');
    return best!;
  }

  double widest(Finder finder) {
    var width = 0.0;
    for (final element in finder.evaluate()) {
      final box = element.renderObject;
      if (box is RenderBox && box.hasSize && box.size.width > width) {
        width = box.size.width;
      }
    }
    return width;
  }

  // ---------------------------------------------------------------------------
  group('Every main screen fits every window', () {
    final cases = <_ScreenCase>[
      _ScreenCase(
        'Home (brand)',
        () => const HomeScreen(),
        ContentWidth.wide,
        searchSpansMediumColumn: true,
      ),
      _ScreenCase(
        'Home (creator)',
        () => const HomeScreen(),
        ContentWidth.wide,
        asCreator: true,
        searchSpansMediumColumn: true,
      ),
      _ScreenCase('Search', () => const SearchScreen(), ContentWidth.readable),
      _ScreenCase(
        'Search results grid',
        () => const SearchResultsScreen(),
        ContentWidth.wide,
      ),
      _ScreenCase('Explore', () => const ExploreScreen(), ContentWidth.wide),
      _ScreenCase(
        'Saved creators list',
        () => const SavedCreatorsScreen(),
        ContentWidth.wide,
      ),
      _ScreenCase(
        'Creator profile',
        () => const CreatorProfileScreen(handle: 'ananyasharma'),
        ContentWidth.readable,
      ),
      _ScreenCase(
        'Booking request',
        () => const NewBookingScreen(handle: 'ananyasharma'),
        ContentWidth.readable,
      ),
      _ScreenCase(
        'Booking details',
        () => const BookingDetailsScreen(bookingId: 1),
        ContentWidth.readable,
      ),
      _ScreenCase(
        'My bookings',
        () => const MyBookingsScreen(),
        ContentWidth.readable,
      ),
      _ScreenCase(
        'Messages',
        () => const MessagesScreen(),
        ContentWidth.readable,
      ),
      _ScreenCase(
        'Chat',
        () => const ChatScreen(conversationId: 1),
        ContentWidth.readable,
      ),
      _ScreenCase(
        'Opportunities',
        () => const OpportunitiesScreen(),
        ContentWidth.wide,
        asCreator: true,
      ),
      _ScreenCase(
        'My packages',
        () => const MyPackagesScreen(),
        ContentWidth.readable,
        asCreator: true,
        packages: true,
      ),
      _ScreenCase('Wallet', () => const WalletScreen(), ContentWidth.readable),
      _ScreenCase(
        'Settings',
        () => const SettingsScreen(),
        ContentWidth.readable,
      ),
      _ScreenCase(
        'Profile menu',
        () => const MenuScreen(),
        ContentWidth.readable,
      ),
      _ScreenCase(
        'Brand dashboard',
        () => const BrandDashboardScreen(),
        ContentWidth.readable,
      ),
      // The sign-in screens and onboarding keep their narrow 560 column.
      _ScreenCase(
        'Log in',
        () => const LoginScreen(),
        AppBreakpoints.maxContentWidth,
      ),
      _ScreenCase(
        'Sign up',
        () => const SignUpScreen(),
        AppBreakpoints.maxContentWidth,
      ),
      _ScreenCase(
        'Email code',
        () => const OtpScreen(),
        AppBreakpoints.maxContentWidth,
      ),
      _ScreenCase(
        'Onboarding',
        () => const OnboardingScreen(),
        AppBreakpoints.maxContentWidth,
      ),
    ];

    for (final screen in cases) {
      for (final size in sizes.entries) {
        testWidgets('${screen.name} at ${size.key}', (tester) async {
          await pumpAt(
            tester,
            size.value,
            screen.build(),
            user: screen.asCreator ? creator : brand,
            overrides: [
              if (screen.packages)
                creatorToolsRepositoryProvider.overrideWithValue(_Packages()),
            ],
          );

          // No overflow, no layout assertion, nothing thrown while building.
          expect(tester.takeException(), isNull);

          final column = mainColumn(tester);
          final screenWidth = size.value.width;

          if (_isPhoneWidth(screenWidth)) {
            if (screenWidth <= AppBreakpoints.maxContentWidth) {
              // A phone: the page is laid out across the whole screen.
              expect(column.width, screenWidth);
              expect(column.left, 0);
            } else {
              // A wide phone keeps the phone rules: the page spans the
              // screen, or a constrained page sits in the phone's own
              // centred 560 column. Never a tablet's 720 or 1040 column.
              expect(
                column.width == screenWidth ||
                    column.width == AppBreakpoints.maxContentWidth,
                isTrue,
                reason: 'phone column ${column.width} at $screenWidth',
              );
              expect((column.center.dx - screenWidth / 2).abs(), lessThan(1));
            }
            return;
          }

          // A tablet: the page is a column no wider than its kind allows,
          // centred on the screen...
          expect(column.width, lessThanOrEqualTo(screen.maxWidth + 0.01));
          expect(
            (column.center.dx - screenWidth / 2).abs(),
            lessThan(1),
            reason: 'the content column is centred',
          );
          // ...and as wide as that allows: the page is laid out at the
          // column's width (less, at most, its 24dp margins), not shrunk
          // to its content.
          expect(
            column.width,
            greaterThanOrEqualTo(
              math.min(screen.maxWidth, screenWidth) - 2 * 24 - 0.01,
            ),
            reason: 'the content column fills its cap',
          );

          // Nothing inside it stretches past it: no edge-to-edge cards,
          // buttons or text fields.
          for (final type in [ZCard, ZButton, TextField]) {
            expect(
              widest(find.byType(type)),
              lessThanOrEqualTo(screen.maxWidth),
              reason: '$type wider than the ${screen.maxWidth} column',
            );
          }
          // A text field keeps a readable width even on a grid page.
          expect(
            widest(find.byType(TextField)),
            lessThanOrEqualTo(ContentWidth.readable),
            reason: 'a text field wider than a readable column',
          );

          // So does a whole search bar (filter button included), except
          // Home's on a medium window, which spans the column like the
          // sections under it.
          final searchBars = find.byType(ZSearchField);
          if (screen.searchSpansMediumColumn) {
            expect(searchBars, findsOneWidget);
          }
          for (final element in searchBars.evaluate()) {
            final width = (element.renderObject! as RenderBox).size.width;
            if (screen.searchSpansMediumColumn && _isMediumWidth(screenWidth)) {
              expect(width, closeTo(column.width - 2 * 24, 0.01));
            } else {
              expect(
                width,
                lessThanOrEqualTo(ContentWidth.readable),
                reason: 'a search bar wider than a readable column',
              );
            }
          }
        });
      }
    }
  });

  // ---------------------------------------------------------------------------
  group('App bars and bottom bars line up with the column', () {
    Widget page() => ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Details'),
      bottomBar: const ZButton(label: 'Continue'),
      body: ListView(children: const [Text('Body')]),
    );

    testWidgets('on a phone they span the screen as before', (tester) async {
      await pumpAt(tester, phone, page());

      final bar = tester.getRect(find.byType(AppBar));
      expect(bar.left, 0);
      expect(bar.width, phone.width);
      expect(tester.getRect(find.byType(ListView)).width, phone.width);
      // The bottom bar's button keeps the phone's 16dp margins.
      expect(tester.getRect(find.byType(ZButton)).width, phone.width - 32);
    });

    testWidgets('on a tablet the bar sits over the content column', (
      tester,
    ) async {
      await pumpAt(tester, const Size(1280, 800), page());

      final bar = tester.getRect(find.byType(AppBar));
      final body = tester.getRect(find.byType(ListView));
      expect(bar.width, ContentWidth.readable);
      expect(bar.left, body.left);
      expect(body.width, ContentWidth.readable);

      // The button lines up with the content's 24dp margins.
      final button = tester.getRect(find.byType(ZButton));
      expect(button.width, ContentWidth.readable - 48);
      expect(button.left, body.left + 24);
    });
  });

  // ---------------------------------------------------------------------------
  group('Navigation', () {
    Future<void> pumpShell(WidgetTester tester, Size size) async {
      tester.view.physicalSize = size * 2;
      tester.view.devicePixelRatio = 2;
      addTearDown(tester.view.reset);

      GoRoute tab(String path) => GoRoute(
        path: path,
        builder: (_, _) =>
            Scaffold(body: ListView(children: [Text('TAB $path')])),
      );

      final router = GoRouter(
        initialLocation: '/home',
        routes: [
          StatefulShellRoute.indexedStack(
            builder: (_, _, shell) => AppShell(navigationShell: shell),
            branches: [
              StatefulShellBranch(routes: [tab('/home')]),
              StatefulShellBranch(routes: [tab('/search')]),
              StatefulShellBranch(routes: [tab('/bookings')]),
              StatefulShellBranch(routes: [tab('/menu')]),
            ],
          ),
        ],
      );
      addTearDown(router.dispose);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            keyValueCacheProvider.overrideWithValue(cache),
            authProvider.overrideWith(() => _SignedIn(brand)),
            currentUserProvider.overrideWithValue(brand),
            unreadNotificationsProvider.overrideWithValue(3),
          ],
          child: MaterialApp.router(
            theme: AppTheme.light,
            routerConfig: router,
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
    }

    /// The bottom bar's row of five buttons.
    Finder navRow() =>
        find.ancestor(of: find.text('Bookings'), matching: find.byType(Row));

    for (final size in const [phone, Size(599, 900), Size(599.4, 900)]) {
      testWidgets('a ${size.width}dp phone keeps the full-width '
          'bottom bar', (tester) async {
        await pumpShell(tester, size);

        expect(tester.takeException(), isNull);
        expect(find.byType(NavigationRail), findsNothing);
        expect(tester.getRect(navRow().first).width, closeTo(size.width, 1e-6));
      });
    }

    for (final width in const [600.0, _floatSw600]) {
      testWidgets('a ${width}dp tablet centres the bottom bar', (tester) async {
        await pumpShell(tester, Size(width, 960));

        expect(find.byType(NavigationRail), findsNothing);
        expect(
          tester.getRect(navRow().first).width,
          ContentWidth.navigationBar,
        );
      });
    }

    // The second line, 840dp, allows for the same float error.
    for (final (width, rail) in const [
      (839.4, false),
      (839.99998, true),
      (840.0, true),
    ]) {
      testWidgets(
        'a ${width}dp window ${rail ? 'navigates from the rail' : 'keeps the bottom bar'}',
        (tester) async {
          await pumpShell(tester, Size(width, 1200));

          expect(tester.takeException(), isNull);
          expect(
            find.byType(NavigationRail),
            rail ? findsOneWidget : findsNothing,
          );
        },
      );
    }

    testWidgets('a portrait tablet centres the bottom bar', (tester) async {
      await pumpShell(tester, const Size(800, 1280));

      expect(tester.takeException(), isNull);
      expect(find.byType(NavigationRail), findsNothing);

      final row = tester.getRect(navRow().first);
      expect(row.width, ContentWidth.navigationBar);
      expect(row.center.dx, 400);
      // The page itself still spans the screen above it.
      expect(tester.getRect(find.byType(ListView)).width, 800);
    });

    testWidgets('a landscape tablet navigates from a rail', (tester) async {
      await pumpShell(tester, const Size(1280, 800));

      expect(tester.takeException(), isNull);
      expect(find.byType(NavigationRail), findsOneWidget);
      expect(find.text('TAB /home'), findsOneWidget);

      final rail = tester.getRect(find.byType(NavigationRail));
      expect(rail.left, 0);
      // The page takes the rest of the width.
      expect(
        tester.getRect(find.text('TAB /home')).left,
        greaterThanOrEqualTo(rail.right),
      );

      // The unread count rides on Profile, as on the bar.
      expect(find.text('3'), findsOneWidget);

      await tester.tap(find.text('Search'));
      await tester.pumpAndSettle();
      expect(find.text('TAB /search'), findsOneWidget);
      expect(
        tester
            .widget<NavigationRail>(find.byType(NavigationRail))
            .selectedIndex,
        1,
      );

      // Create opens the same sheet as the bar's centre button.
      await tester.tap(find.byIcon(Icons.add_rounded));
      await tester.pumpAndSettle();
      expect(find.text('Start a collaboration with creators.'), findsOneWidget);
    });

    testWidgets('a 7-inch tablet in landscape fits the rail', (tester) async {
      await pumpShell(tester, const Size(960, 600));

      expect(tester.takeException(), isNull);
      expect(find.byType(NavigationRail), findsOneWidget);
    });

    testWidgets('turning a tablet keeps the tab and what is on it', (
      tester,
    ) async {
      await pumpShell(tester, const Size(800, 1280));

      await tester.tap(find.text('Search'));
      await tester.pumpAndSettle();
      expect(find.text('TAB /search'), findsOneWidget);

      // The tab page's own state object, which holds its scroll position.
      final scrollable = find.descendant(
        of: find.ancestor(
          of: find.text('TAB /search'),
          matching: find.byType(ListView),
        ),
        matching: find.byType(Scrollable),
      );
      final before = tester.state<ScrollableState>(scrollable);

      // Bottom bar to rail: the shell rebuilds around the same navigators.
      tester.view.physicalSize = const Size(1280, 800) * 2;
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.byType(NavigationRail), findsOneWidget);
      expect(find.text('TAB /search'), findsOneWidget);
      expect(
        tester.state<ScrollableState>(scrollable),
        same(before),
        reason: 'the tab was moved, not rebuilt',
      );
    });
  });

  // ---------------------------------------------------------------------------
  group('The shell around the real tab pages', () {
    Future<void> pumpRealShell(
      WidgetTester tester,
      Size size, {
      double keyboard = 0,
      String initial = '/home',
    }) async {
      setWindow(tester, size, keyboard: keyboard);

      GoRoute tab(String path, Widget page) =>
          GoRoute(path: path, builder: (_, _) => page);

      final router = GoRouter(
        initialLocation: initial,
        routes: [
          StatefulShellRoute.indexedStack(
            builder: (_, _, shell) => AppShell(navigationShell: shell),
            branches: [
              StatefulShellBranch(routes: [tab('/home', const HomeScreen())]),
              StatefulShellBranch(
                routes: [tab('/search', const SearchScreen())],
              ),
              StatefulShellBranch(
                routes: [tab('/bookings', const MyBookingsScreen())],
              ),
              StatefulShellBranch(routes: [tab('/menu', const MenuScreen())]),
            ],
          ),
        ],
      );
      addTearDown(router.dispose);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            ...session(brand),
            unreadNotificationsProvider.overrideWithValue(3),
          ],
          child: MaterialApp.router(
            theme: AppTheme.light,
            routerConfig: router,
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
    }

    testWidgets('Home beside the rail on a landscape tablet', (tester) async {
      await pumpRealShell(tester, const Size(1280, 800));

      expect(tester.takeException(), isNull);
      final rail = tester.getRect(find.byType(NavigationRail));
      final column = mainColumn(tester);

      // The feed's column sits in the space right of the rail, centred there
      // and as wide as the grid cap allows.
      expect(column.left, greaterThanOrEqualTo(rail.right));
      expect(column.width, ContentWidth.wide);
      expect(
        (column.center.dx - (rail.right + (1280 - rail.right) / 2)).abs(),
        lessThan(1),
      );
    });

    for (final (size, keyboard) in const [
      (Size(960, 600), 260.0),
      (Size(1280, 800), 400.0),
    ]) {
      testWidgets('the keyboard on Search at ${size.width.round()}x'
          '${size.height.round()} leaves the rail whole', (tester) async {
        await pumpRealShell(
          tester,
          size,
          keyboard: keyboard,
          initial: '/search',
        );

        expect(tester.takeException(), isNull);
        // The rail keeps the full height; the page makes room for the
        // keyboard itself.
        expect(tester.getRect(find.byType(NavigationRail)).height, size.height);
        expect(
          mainColumn(tester).bottom,
          lessThanOrEqualTo(size.height - keyboard + 0.01),
        );
        expect(find.text('Profile'), findsOneWidget);
      });
    }

    testWidgets('a very short window scrolls the rail', (tester) async {
      // A landscape tablet in split screen, at its shortest.
      const height = 320.0;
      await pumpRealShell(tester, const Size(1280, height));

      expect(tester.takeException(), isNull);
      expect(find.byType(NavigationRail), findsOneWidget);

      // Profile starts below the fold, and is reachable.
      final profile = find.text('Profile');
      expect(tester.getRect(profile).bottom, greaterThan(height));
      // (No pumpAndSettle: the Home banners turn on their own.)
      await tester.ensureVisible(profile);
      await tester.pump();
      expect(tester.getRect(profile).bottom, lessThanOrEqualTo(height));
      await tester.tap(profile);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));
      expect(
        tester
            .widget<NavigationRail>(find.byType(NavigationRail))
            .selectedIndex,
        3,
      );
      expect(tester.takeException(), isNull);
    });
  });

  // ---------------------------------------------------------------------------
  group('With the keyboard up', () {
    // What is left of the window once the on-screen keyboard is open.
    const windows = <String, (Size, double)>{
      'phone 390x844, 300dp keyboard': (phone, 300),
      '7-inch landscape, 260dp keyboard': (Size(960, 600), 260),
      '10-inch landscape, 400dp keyboard': (Size(1280, 800), 400),
      '10-inch portrait, 360dp keyboard': (Size(800, 1280), 360),
    };

    final screens = <String, (Widget Function(), bool)>{
      'Chat': (() => const ChatScreen(conversationId: 1), false),
      'Booking request': (
        () => const NewBookingScreen(handle: 'ananyasharma'),
        false,
      ),
      'Log in': (() => const LoginScreen(), false),
      'Sign up': (() => const SignUpScreen(), false),
      'Email code': (() => const OtpScreen(), false),
      'Search': (() => const SearchScreen(), false),
      'Search results': (() => const SearchResultsScreen(), false),
      'Opportunities': (() => const OpportunitiesScreen(), true),
    };

    for (final screen in screens.entries) {
      for (final window in windows.entries) {
        testWidgets('${screen.key} at ${window.key}', (tester) async {
          final (size, keyboard) = window.value;
          final (build, asCreator) = screen.value;

          // Open from the first frame, so the loading state is covered too.
          await pumpAt(
            tester,
            size,
            build(),
            user: asCreator ? creator : brand,
            keyboard: keyboard,
          );

          expect(tester.takeException(), isNull);
        });
      }
    }
  });

  // ---------------------------------------------------------------------------
  group('The phone/tablet line', () {
    Widget page() => ZScaffold(
      appBar: const ZAppBar(title: 'Details'),
      bottomBar: const ZButton(label: 'Continue'),
      body: ListView(children: const [Text('Body')]),
    );

    testWidgets('599dp keeps every phone rule', (tester) async {
      await pumpAt(tester, const Size(599, 900), page());

      // The phone's own centred 560 column with its 16dp margins.
      final body = tester.getRect(find.byType(ListView));
      expect(body.width, AppBreakpoints.maxContentWidth - 32);
      expect(body.center.dx, closeTo(599 / 2, 0.5));
      // The app bar spans the screen; the bottom button sits in the phone's
      // 560 column.
      expect(tester.getRect(find.byType(AppBar)).width, 599);
      expect(
        tester.getRect(find.byType(ZButton)).width,
        AppBreakpoints.maxContentWidth - 32,
      );
    });

    testWidgets('600dp is a tablet', (tester) async {
      await pumpAt(tester, const Size(600, 960), page());

      // The tablet column (the whole 600, under its 720 cap) with 24dp
      // margins.
      expect(tester.getRect(find.byType(ListView)).width, 600 - 48);
      expect(tester.getRect(find.byType(ZButton)).width, 600 - 48);
    });

    testWidgets('599.99998dp, an sw600dp tablet after float error, is a '
        'tablet', (tester) async {
      await pumpAt(tester, const Size(_floatSw600, 960), page());

      // Exactly the 600dp tablet's layout, not the phone's 560 column.
      expect(
        tester.getRect(find.byType(ListView)).width,
        closeTo(_floatSw600 - 48, 1e-6),
      );
      expect(
        tester.getRect(find.byType(ZButton)).width,
        closeTo(_floatSw600 - 48, 1e-6),
      );
      expect(tester.getRect(find.byType(AppBar)).left, 0);
    });

    testWidgets('599.4dp is still a phone', (tester) async {
      await pumpAt(tester, const Size(599.4, 900), page());

      // The phone's centred 560 column with its 16dp margins.
      expect(
        tester.getRect(find.byType(ListView)).width,
        AppBreakpoints.maxContentWidth - 32,
      );
      expect(tester.getRect(find.byType(AppBar)).width, closeTo(599.4, 1e-6));
    });

    test('the size classes allow for float error at both lines', () {
      expect(WindowSize.forWidth(390), WindowSize.compact);
      expect(WindowSize.forWidth(599), WindowSize.compact);
      expect(WindowSize.forWidth(599.4), WindowSize.compact);
      expect(WindowSize.forWidth(_floatSw600), WindowSize.medium);
      expect(WindowSize.forWidth(600), WindowSize.medium);
      expect(WindowSize.forWidth(800), WindowSize.medium);
      expect(WindowSize.forWidth(839.4), WindowSize.medium);
      expect(WindowSize.forWidth(839.99998), WindowSize.expanded);
      expect(WindowSize.forWidth(840), WindowSize.expanded);
      expect(WindowSize.forWidth(1280), WindowSize.expanded);

      expect(Breakpoints.reaches(_floatSw600, Breakpoints.medium), isTrue);
      expect(Breakpoints.reaches(599.4, Breakpoints.medium), isFalse);
      expect(Breakpoints.reaches(839.99998, Breakpoints.expanded), isTrue);
    });

    for (final (width, tablet) in const [
      (390.0, false),
      (599.4, false),
      (_floatSw600, true),
      (600.0, true),
      (839.99998, true),
      (840.0, true),
    ]) {
      testWidgets('every width check agrees at ${width}dp', (tester) async {
        late BuildContext context;
        await pumpAt(
          tester,
          Size(width, 960),
          Builder(
            builder: (c) {
              context = c;
              return const SizedBox.expand();
            },
          ),
        );

        expect(WindowSize.of(context).isCompact, !tablet);
        expect(AppBreakpoints.isMobile(context), !tablet);
        expect(AppBreakpoints.isTablet(context), tablet);
        expect(AppBreakpoints.isDesktop(context), isFalse);
        expect(
          AppBreakpoints.pageMargin(context),
          tablet ? AppSpacing.marginTablet : AppSpacing.marginMobile,
        );
        // The orientation policy judges the shorter side by the same line,
        // portrait or landscape.
        expect(OrientationPolicy.allowsLandscape(Size(width, 960)), tablet);
        expect(OrientationPolicy.allowsLandscape(Size(1280, width)), tablet);
      });
    }

    test('the card lists split in two at the same allowance', () {
      // AdaptiveCardList goes two-up at 700dp of list: a hair short of it
      // after float error counts.
      expect(
        Breakpoints.reaches(699.99998, ContentWidth.twoColumnList),
        isTrue,
      );
      expect(Breakpoints.reaches(699.4, ContentWidth.twoColumnList), isFalse);
    });

    test('the creator grid keeps phone columns up to 599dp', () {
      // The grid's own width on a 599dp phone, less its 16dp margins.
      expect(_gridColumns(599 - 32), 2);
      expect(_gridColumns(599 - 32, maxColumns: 3), 3);
      // A 600dp tablet's grid, less 24dp margins, is the same 552dp as a
      // large phone's and keeps two columns; 800dp adds a third.
      expect(_gridColumns(600 - 48), 2);
      expect(_gridColumns(800 - 48), 3);
    });
  });

  // ---------------------------------------------------------------------------
  group('Home banners', () {
    Rect firstSlide(WidgetTester tester) => tester.getRect(
      find
          .descendant(
            of: find.byType(ZHeroSlider),
            matching: find.byType(InkWell),
          )
          .first,
    );

    testWidgets('a phone keeps one banner at a time', (tester) async {
      await pumpAt(tester, phone, const HomeScreen());

      final slider = tester.widget<ZHeroSlider>(find.byType(ZHeroSlider));
      expect(slider.viewportFraction, 0.92);
      expect(slider.height, 184);
    });

    for (final size in const [Size(800, 1280), Size(1280, 800)]) {
      testWidgets('a ${size.width.round()}dp tablet shows two, each close to '
          'a phone banner\'s shape', (tester) async {
        await pumpAt(tester, size, const HomeScreen());

        expect(tester.takeException(), isNull);
        final slider = tester.widget<ZHeroSlider>(find.byType(ZHeroSlider));
        expect(slider.viewportFraction, 0.5);

        final slide = firstSlide(tester);
        // A phone banner is about 1.9:1; a tablet's stays under 2.5:1
        // rather than becoming a 4:1 strip.
        expect(slide.width / slide.height, lessThan(2.5));
        expect(slide.width, lessThanOrEqualTo(ContentWidth.wide / 2));
      });
    }
  });

  // ---------------------------------------------------------------------------
  group('Home shares one left edge', () {
    /// The left edge every section starts at: the first section title's.
    double sectionEdge(WidgetTester tester) =>
        tester.getRect(find.text('Top Categories')).left;

    /// The first banner on screen.
    Rect firstSlide(WidgetTester tester) => tester.getRect(
      find
          .descendant(
            of: find.byType(ZHeroSlider),
            matching: find.byType(InkWell),
          )
          .first,
    );

    // The window, the left edge of the column the feed is laid out in, and
    // the page margin inside it.
    for (final (size, columnLeft, margin) in const [
      (phone, 0.0, 16.0),
      (Size(_floatSw600, 960), 0.0, 24.0),
      (Size(600, 960), 0.0, 24.0),
      (Size(800, 1280), 0.0, 24.0),
      (Size(1280, 800), (1280 - ContentWidth.wide) / 2, 24.0),
    ]) {
      testWidgets('search, banners and sections at ${size.width}dp', (
        tester,
      ) async {
        await pumpAt(tester, size, const HomeScreen());
        expect(tester.takeException(), isNull);

        final edge = columnLeft + margin;
        final search = tester.getRect(find.byType(ZSearchField));
        expect(sectionEdge(tester), closeTo(edge, 0.01));
        expect(search.left, closeTo(edge, 0.01));
        expect(firstSlide(tester).left, closeTo(edge, 0.01));
        // The second section, below the banners, too.
        expect(
          tester.getRect(find.text('Active Campaigns')).left,
          closeTo(edge, 0.01),
        );

        if (size.width >= Breakpoints.expanded) {
          // Landscape: a readable-width bar from that edge, not one
          // across the whole grid, and not centred away from the edge.
          expect(
            search.width,
            closeTo(ContentWidth.readable - 2 * margin, 0.01),
          );
        } else {
          // A phone or a portrait tablet: the bar spans the column like the
          // sections, so the right edges agree as well.
          expect(search.width, closeTo(size.width - 2 * margin, 0.01));
        }
      });
    }

    // A lone banner follows the same edge (and width) as the search bar.
    for (final size in const [phone, Size(800, 1280), Size(1280, 800)]) {
      testWidgets('a lone banner lines up with the search bar at '
          '${size.width}dp', (tester) async {
        BaseRepository.fixtures = (key) => key == 'home'
            ? {
                ...MockData.home,
                'banners': [(MockData.home['banners'] as List).first],
              }
            : resolveTestFixture(key);

        await pumpAt(tester, size, const HomeScreen());
        expect(tester.takeException(), isNull);

        final search = tester.getRect(find.byType(ZSearchField));
        final slide = firstSlide(tester);
        expect(slide.left, closeTo(search.left, 0.01));
        // The slider keeps its 8dp gap after a slide, as it does on a phone.
        expect(slide.right, closeTo(search.right - AppSpacing.xs, 0.01));
        expect(sectionEdge(tester), closeTo(search.left, 0.01));
      });
    }
  });

  // ---------------------------------------------------------------------------
  group('The status bar over the creator profile', () {
    const statusBar = 24.0;

    /// The cover photo (or its gradient stand-in), below the avatar's
    /// overhang.
    Rect cover(WidgetTester tester) => tester.getRect(
      find
          .descendant(
            of: find.byType(FlexibleSpaceBar),
            matching: find.byWidgetPredicate(
              (w) =>
                  w is Positioned && w.bottom == ProfileLayout.avatarOverhang,
            ),
          )
          .first,
    );

    Brightness? statusIcons() =>
        SystemChrome.latestStyle?.statusBarIconBrightness;

    // Where the cover spans the screen, its photo runs under the status bar
    // and the icons are light. Where it is capped to the column, the page
    // background shows either side under the clock and battery: the cover
    // starts below the status bar and the icons are dark.
    for (final (size, spans) in const [
      (phone, true),
      (Size(_floatSw600, 960), true),
      (Size(600, 960), true),
      (Size(800, 1280), false),
      (Size(1280, 800), false),
    ]) {
      testWidgets('at ${size.width}x${size.height}, scrolled to the top', (
        tester,
      ) async {
        await pumpAt(
          tester,
          size,
          const CreatorProfileScreen(handle: 'ananyasharma'),
          statusBar: statusBar,
        );
        expect(tester.takeException(), isNull);
        expect(find.byType(SliverAppBar), findsOneWidget);

        final photo = cover(tester);
        if (spans) {
          expect(photo.left, closeTo(0, 0.01));
          expect(photo.width, closeTo(size.width, 0.01));
          expect(photo.top, 0, reason: 'under the status bar, as on a phone');
          expect(statusIcons(), Brightness.light);
        } else {
          expect(photo.left, greaterThan(0), reason: 'gutters either side');
          expect(photo.width, ContentWidth.readable);
          expect(photo.top, statusBar, reason: 'below the status bar');
          expect(statusIcons(), Brightness.dark);
          // The photo keeps its phone height below the status bar.
          expect(photo.height, ProfileLayout.coverHeight);
        }
      });
    }

    for (final size in const [phone, Size(1280, 800)]) {
      testWidgets('at ${size.width}dp, once the cover has scrolled away', (
        tester,
      ) async {
        await pumpAt(
          tester,
          size,
          const CreatorProfileScreen(handle: 'ananyasharma'),
          statusBar: statusBar,
        );
        await tester.drag(
          find.byType(CustomScrollView).first,
          const Offset(0, -400),
        );
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 400));

        expect(tester.takeException(), isNull);
        // Dark on the plain toolbar, on a phone as before and on a tablet.
        expect(statusIcons(), Brightness.dark);
      });
    }
  });

  // ---------------------------------------------------------------------------
  group('Filter pills and tabs line up with the content', () {
    /// The first pill under the search bar.
    Rect firstPill(WidgetTester tester) => tester.getRect(
      find
          .descendant(
            of: find.byType(ZFilterPills),
            matching: find.byType(AnimatedContainer),
          )
          .first,
    );

    for (final (size, margin) in const [
      (phone, 16.0),
      (Size(_floatSw600, 960), 24.0),
      (Size(600, 960), 24.0),
      (Size(800, 1280), 24.0),
      (Size(1280, 800), 24.0),
    ]) {
      testWidgets('Browse all creators at ${size.width}dp', (tester) async {
        await pumpAt(tester, size, const SearchResultsScreen());
        expect(tester.takeException(), isNull);
        expect(find.byType(ZFilterPills), findsOneWidget);

        // The content's edge: the grid's first card.
        final card = tester.getRect(find.byType(CreatorGridCard).first);
        final column = mainColumn(tester);
        expect(card.left, closeTo(column.left + margin, 0.01));

        expect(firstPill(tester).left, closeTo(card.left, 0.01));
        expect(
          tester.getRect(find.textContaining('found')).left,
          closeTo(card.left, 0.01),
        );
      });
    }

    for (final (size, margin) in const [
      (phone, 16.0),
      (Size(599.4, 900), 16.0),
      (Size(_floatSw600, 960), 24.0),
      (Size(600, 960), 24.0),
      (Size(800, 1280), 24.0),
      (Size(1280, 800), 24.0),
    ]) {
      testWidgets('segmented tabs at ${size.width}dp', (tester) async {
        await pumpAt(
          tester,
          size,
          const Scaffold(
            body: DefaultTabController(
              length: 2,
              child: Column(
                children: [
                  ZSegmentedTabs(labels: ['Active', 'Done']),
                ],
              ),
            ),
          ),
        );

        final track = tester.getRect(
          find
              .descendant(
                of: find.byType(ZSegmentedTabs),
                matching: find.byType(Container),
              )
              .first,
        );
        expect(track.left, margin);
        expect(track.right, closeTo(size.width - margin, 1e-6));
      });
    }
  });

  // ---------------------------------------------------------------------------
  group('Two-up card rows are even', () {
    /// A card that is [height] tall unless its row asks for more.
    Widget probe(int i, double height) => Container(
      key: ValueKey('card $i'),
      constraints: BoxConstraints(minHeight: height),
      color: const Color(0xFFEEEEEE),
    );

    /// A card whose content is [height] tall and can change after the first
    /// frame, rebuilding only itself, not the list around it: a data refresh
    /// of one item, or a chip appearing.
    Widget growingProbe(int i, ValueNotifier<double> height) => ColoredBox(
      key: ValueKey('card $i'),
      color: const Color(0xFFEEEEEE),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          ValueListenableBuilder<double>(
            valueListenable: height,
            builder: (_, value, _) => SizedBox(height: value),
          ),
        ],
      ),
    );

    const heights = [100.0, 160.0, 120.0];
    Rect card(WidgetTester tester, int i) =>
        tester.getRect(find.byKey(ValueKey('card $i')));

    Widget boxList({int? count, IndexedWidgetBuilder? item}) => Scaffold(
      body: AdaptiveCardList(
        padding: const EdgeInsets.all(24),
        itemCount: count ?? heights.length,
        itemBuilder: item ?? (_, i) => probe(i, heights[i]),
      ),
    );

    Widget sliverList({int? count, IndexedWidgetBuilder? item}) => Scaffold(
      body: CustomScrollView(
        slivers: [
          SliverPadding(
            padding: const EdgeInsets.all(24),
            sliver: SliverAdaptiveCardList(
              itemCount: count ?? heights.length,
              itemBuilder: item ?? (_, i) => probe(i, heights[i]),
            ),
          ),
        ],
      ),
    );

    // Each list with its default gap between cards.
    for (final (name, list, spacing) in [
      ('AdaptiveCardList', boxList, AppSpacing.sm),
      ('SliverAdaptiveCardList', sliverList, AppSpacing.md),
    ]) {
      // A phone, and a 7-inch tablet whose 552dp list is too narrow for two
      // columns (including one measured a hair under 600dp): the plain list.
      for (final size in const [
        phone,
        Size(_floatSw600, 960),
        Size(600, 960),
      ]) {
        testWidgets('$name: at ${size.width}dp one column, each card its own '
            'height', (tester) async {
          await pumpAt(tester, size, list());
          expect(tester.takeException(), isNull);

          for (var i = 0; i < heights.length; i++) {
            expect(card(tester, i).height, heights[i]);
            expect(card(tester, i).left, 24);
            expect(card(tester, i).width, closeTo(size.width - 48, 1e-6));
          }
          // The phone's gap, and nothing else, between one card and the next.
          for (var i = 1; i < heights.length; i++) {
            expect(
              card(tester, i).top - card(tester, i - 1).bottom,
              closeTo(spacing, 1e-6),
            );
          }
        });
      }

      for (final size in const [Size(800, 1280), Size(1280, 800)]) {
        testWidgets('$name: at ${size.width}dp a row is as tall as its '
            'tallest card', (tester) async {
          await pumpAt(tester, size, list());
          expect(tester.takeException(), isNull);

          final a = card(tester, 0);
          final b = card(tester, 1);
          expect(b.top, a.top, reason: 'side by side');
          expect(a.height, 160);
          expect(b.height, 160);
          expect(a.width, b.width);
          expect(b.left, greaterThan(a.right));

          // The odd card out keeps its own height and its column's width.
          final c = card(tester, 2);
          expect(c.height, 120);
          expect(c.width, a.width);
          expect(c.left, a.left);
        });
      }

      testWidgets('$name: right to left', (tester) async {
        setWindow(tester, const Size(1280, 800));
        await tester.pumpWidget(
          MaterialApp(
            theme: AppTheme.light,
            home: Directionality(
              textDirection: TextDirection.rtl,
              child: list(),
            ),
          ),
        );

        // The first card is on the right.
        expect(card(tester, 0).left, greaterThan(card(tester, 1).right));
        expect(card(tester, 0).height, card(tester, 1).height);
      });

      for (final size in const [Size(800, 1280), Size(1280, 800)]) {
        testWidgets('$name: at ${size.width}dp a card that changes after the '
            'first frame evens its row again', (tester) async {
          final first = ValueNotifier(160.0);
          final second = ValueNotifier(100.0);
          addTearDown(first.dispose);
          addTearDown(second.dispose);

          await pumpAt(
            tester,
            size,
            list(
              count: 2,
              item: (_, i) => growingProbe(i, i == 0 ? first : second),
            ),
          );

          Future<void> expectRow(double height) async {
            await tester.pump();
            expect(tester.takeException(), isNull, reason: 'no overflow');
            expect(card(tester, 0).height, height);
            expect(card(tester, 1).height, height);
            expect(card(tester, 1).top, card(tester, 0).top);
          }

          await expectRow(160);

          // The stretched card outgrows its neighbour: the row grows with
          // it rather than the card overflowing at the old height.
          second.value = 220;
          await expectRow(220);

          // It shrinks back below its neighbour: the row follows.
          second.value = 130;
          await expectRow(160);

          // The taller card shrinks: now the other one sets the height.
          first.value = 100;
          await expectRow(130);

          // And the newly stretched card grows again.
          first.value = 200;
          await expectRow(200);
        });
      }
    }

    // Find campaigns: a brief whose title wraps beside one whose does not.
    final briefs = [
      {
        ...MockData.campaigns[0],
        'id': 1,
        'title':
            'A long brief title that wraps onto a second line on every card',
      },
      {...MockData.campaigns[0], 'id': 2, 'title': 'Short brief'},
    ];

    Finder inCard(int i, Finder finder) =>
        find.descendant(of: find.byType(CampaignCard).at(i), matching: finder);

    // A phone, and a 7-inch tablet whose list is one column wide.
    for (final size in const [phone, Size(_floatSw600, 960), Size(600, 960)]) {
      testWidgets('Find campaigns at ${size.width}dp: one column, cards as on '
          'a phone', (tester) async {
        BaseRepository.fixtures = (key) =>
            key == 'campaigns.open' ? briefs : resolveTestFixture(key);
        await pumpAt(tester, size, const OpportunitiesScreen(), user: creator);
        expect(tester.takeException(), isNull);

        final first = tester.getRect(find.byType(CampaignCard).at(0));
        final second = tester.getRect(find.byType(CampaignCard).at(1));
        // One above the other, each its own height: the wrapped title makes
        // the first taller.
        expect(second.top, greaterThan(first.bottom));
        expect(second.left, first.left);
        expect(first.height, greaterThan(second.height));

        // No gap opens anywhere: the footer follows the facts at the phone's
        // spacing, and the card ends its padding below the footer.
        for (var i = 0; i < 2; i++) {
          final facts = tester.getRect(inCard(i, find.byType(Wrap)));
          final divider = tester.getRect(inCard(i, find.byType(Divider)));
          final cardRect = tester.getRect(find.byType(CampaignCard).at(i));
          final footer = tester.getRect(
            find
                .ancestor(
                  of: inCard(i, find.byType(ZButton)),
                  matching: find.byType(Row),
                )
                .first,
          );
          expect(divider.top - facts.bottom, AppSpacing.sm);
          // The footer's 16dp padding and the card's 1dp border.
          expect(cardRect.bottom - footer.bottom, AppSpacing.md + 1);
        }
      });
    }

    for (final size in const [Size(800, 1280), Size(1280, 800)]) {
      testWidgets('Find campaigns at ${size.width}dp: even cards, footers '
          'level', (tester) async {
        BaseRepository.fixtures = (key) =>
            key == 'campaigns.open' ? briefs : resolveTestFixture(key);
        await pumpAt(tester, size, const OpportunitiesScreen(), user: creator);
        expect(tester.takeException(), isNull);

        final first = tester.getRect(find.byType(CampaignCard).at(0));
        final second = tester.getRect(find.byType(CampaignCard).at(1));
        expect(second.top, first.top);
        expect(second.height, first.height);

        // The shorter card's budget row and Apply button sit on its bottom,
        // level with its neighbour's.
        expect(
          tester.getRect(inCard(1, find.byType(Divider))).top,
          tester.getRect(inCard(0, find.byType(Divider))).top,
        );
        expect(
          tester.getRect(inCard(1, find.byType(ZButton))).bottom,
          tester.getRect(inCard(0, find.byType(ZButton))).bottom,
        );
      });
    }

    for (final size in const [Size(800, 1280), Size(1280, 800)]) {
      testWidgets('saved creators at ${size.width}dp: even cards, actions '
          'level', (tester) async {
        await pumpAt(tester, size, const SavedCreatorsScreen());
        expect(tester.takeException(), isNull);

        final cards = find.byType(CreatorListCard);
        expect(cards, findsAtLeastNWidgets(2));
        final a = tester.getRect(cards.at(0));
        final b = tester.getRect(cards.at(1));
        expect(b.top, a.top);
        expect(b.height, a.height);

        // The footers (price, Follow and View) sit level.
        Rect view(int i) => tester.getRect(
          find
              .descendant(of: cards.at(i), matching: find.text('View'))
              .first,
        );
        expect(view(1).bottom, view(0).bottom);
      });
    }

    for (final (size, margin) in const [
      (phone, 16.0),
      (Size(_floatSw600, 960), 24.0),
      (Size(600, 960), 24.0),
    ]) {
      testWidgets('saved creators at ${size.width}dp: one column, cards as '
          'on a phone', (tester) async {
        await pumpAt(tester, size, const SavedCreatorsScreen());
        expect(tester.takeException(), isNull);

        final cards = find.byType(CreatorListCard);
        expect(cards, findsAtLeastNWidgets(2));
        final a = tester.getRect(cards.at(0));
        final b = tester.getRect(cards.at(1));
        expect(b.top, greaterThan(a.bottom));
        expect(b.left, a.left);
        expect(a.width, closeTo(size.width - 2 * margin, 1e-6));

        // No gap between body and footer: the footer group starts where
        // the body ends, and the card is exactly the two plus its padding.
        for (var i = 0; i < 2; i++) {
          final content = tester.renderObject<RenderFlex>(
            find.descendant(
              of: cards.at(i),
              matching: find.byType(CardBodyAndFooter),
            ),
          );
          final body = content.firstChild!;
          final footer = content.lastChild!;
          final footerTop = (footer.parentData! as FlexParentData).offset.dy;
          expect(footerTop, body.size.height);
          expect(content.size.height, body.size.height + footer.size.height);
          // The card's 8dp padding and 1dp border, top and bottom.
          expect(
            tester.getRect(cards.at(i)).height,
            content.size.height + 2 * (AppSpacing.sm + 1),
          );
        }
      });
    }

    testWidgets('CardBodyAndFooter: a plain column until given a minimum '
        'height, which opens above the footer', (tester) async {
      Widget item(double? minHeight) {
        final content = CardBodyAndFooter(
          body: const [SizedBox(key: ValueKey('body'), width: 120, height: 40)],
          footer: const [
            SizedBox(key: ValueKey('footer'), width: 60, height: 20),
          ],
        );
        return Align(
          alignment: Alignment.topLeft,
          child: SizedBox(
            key: const ValueKey('card'),
            width: 200,
            child: minHeight == null
                ? content
                : ConstrainedBox(
                    constraints: BoxConstraints(minHeight: minHeight),
                    child: content,
                  ),
          ),
        );
      }

      Rect rect(String key) => tester.getRect(find.byKey(ValueKey(key)));

      // Unbounded height, as in a list: one after the other, no gap.
      await pumpAt(tester, phone, ListView(children: [item(null)]));
      expect(rect('card').height, 60);
      expect(rect('body').top, rect('card').top);
      expect(rect('footer').top, rect('body').bottom);
      expect(rect('footer').left, rect('card').left);

      // A minimum from a two-up row: the extra goes between the groups.
      await pumpAt(tester, phone, ListView(children: [item(100)]));
      expect(rect('card').height, 100);
      expect(rect('body').top, rect('card').top);
      expect(rect('footer').bottom, rect('card').bottom);
    });
  });

  // ---------------------------------------------------------------------------
  group('Launch splash', () {
    // The artwork (946x1663) and where its loading ring sits in it.
    const art = Size(946, 1663);

    Future<Rect> loaderAt(WidgetTester tester, Size size) async {
      await pumpAt(tester, size, const LaunchSplash());
      // Past the loader's entrance.
      await tester.pump(const Duration(seconds: 1));
      return tester.getRect(find.byType(BrandLoader));
    }

    testWidgets('a phone: the artwork covers the screen, as before', (
      tester,
    ) async {
      final ring = await loaderAt(tester, phone);

      // Cover: scaled to the height, centred across.
      final scale = math.max(
        phone.width / art.width,
        phone.height / art.height,
      );
      final shownW = art.width * scale;
      expect(ring.width, closeTo(0.097 * shownW, 0.5));
      expect(
        ring.center.dx,
        closeTo((phone.width - shownW) / 2 + 0.499 * shownW, 0.5),
      );
      expect(ring.center.dy, closeTo(0.778 * art.height * scale, 0.5));
      expect(find.byType(ShaderMask), findsNothing);
    });

    for (final size in const [Size(1280, 800), Size(960, 600)]) {
      testWidgets(
        'a ${size.width.round()}x${size.height.round()} landscape tablet: '
        'the whole artwork, and the loader on screen',
        (tester) async {
          final ring = await loaderAt(tester, size);

          expect(tester.takeException(), isNull);
          // Contain: scaled to the height, so the ring is where the artwork
          // draws it and well inside the screen.
          expect(ring.bottom, lessThan(size.height));
          expect(ring.center.dy, closeTo(0.778 * size.height, 0.5));
          expect(ring.center.dx, closeTo(size.width / 2, 2));
          // The portrait artwork's sides fade into the white.
          expect(find.byType(ShaderMask), findsOneWidget);
        },
      );
    }
  });

  // ---------------------------------------------------------------------------
  group('Lists and grids re-flow on a tablet', () {
    testWidgets('briefs sit two to a row on a landscape tablet', (
      tester,
    ) async {
      await pumpAt(
        tester,
        const Size(1280, 800),
        const OpportunitiesScreen(),
        user: creator,
      );

      final cards = find.byType(CampaignCard);
      expect(cards, findsAtLeastNWidgets(2));
      final first = tester.getRect(cards.at(0));
      final second = tester.getRect(cards.at(1));
      expect(second.top, first.top, reason: 'side by side');
      expect(second.left, greaterThan(first.right));
      expect(first.width, lessThan(ContentWidth.wide / 2));
    });

    testWidgets('briefs stay one to a row on a phone', (tester) async {
      await pumpAt(tester, phone, const OpportunitiesScreen(), user: creator);

      final cards = find.byType(CampaignCard);
      expect(cards, findsAtLeastNWidgets(2));
      expect(
        tester.getRect(cards.at(1)).top,
        greaterThan(tester.getRect(cards.at(0)).bottom),
      );
      expect(tester.getRect(cards.at(0)).width, phone.width - 32);
    });

    testWidgets('saved creators sit two to a row on a portrait tablet', (
      tester,
    ) async {
      await pumpAt(tester, const Size(800, 1280), const SavedCreatorsScreen());

      final cards = find.byType(CreatorListCard);
      expect(cards, findsAtLeastNWidgets(2));
      expect(tester.getRect(cards.at(1)).top, tester.getRect(cards.at(0)).top);
    });

    testWidgets('the creator grid adds columns instead of stretching cards', (
      tester,
    ) async {
      await pumpAt(tester, const Size(1280, 800), const ExploreScreen());

      final cards = find.byType(CreatorGridCard);
      expect(cards, findsAtLeastNWidgets(4));
      final top = tester.getRect(cards.at(0)).top;
      for (var i = 1; i < 4; i++) {
        expect(tester.getRect(cards.at(i)).top, top, reason: 'card $i');
      }
      expect(tester.getRect(cards.at(0)).width, lessThan(260));
    });

    test('the grid delegate keeps phone columns and adds tablet ones', () {
      const columns = _gridColumns;

      // Phones, unchanged: two columns, or the section's own up to 4.
      expect(columns(358), 2);
      expect(columns(567), 2);
      expect(columns(567, maxColumns: 3), 3);
      // Tablets: as many as fit at 200dp or more, never fewer than asked.
      expect(columns(752), 3);
      expect(columns(992), 4);
      expect(columns(992, maxColumns: 3), 4);
    });
  });

  // ---------------------------------------------------------------------------
  group('Sheets and dialogs', () {
    Future<BuildContext> host(WidgetTester tester, Size size) async {
      await pumpAt(tester, size, const Scaffold(body: SizedBox.expand()));
      return tester.element(find.byType(Scaffold));
    }

    for (final size in [phone, const Size(1280, 800)]) {
      testWidgets('a confirm dialog at ${size.width.round()}dp', (
        tester,
      ) async {
        final context = await host(tester, size);
        showZConfirm(context, title: 'Cancel this booking?', message: 'Sure?');
        await tester.pumpAndSettle();

        final dialog = tester.getRect(
          find
              .descendant(
                of: find.byType(Dialog),
                matching: find.byType(Material),
              )
              .first,
        );
        if (size == phone) {
          // As before: the screen less the dialog's 24dp insets.
          expect(dialog.width, phone.width - 48);
        } else {
          expect(dialog.width, ContentWidth.dialog);
          expect(dialog.center.dx, size.width / 2);
        }
      });

      testWidgets('a sheet at ${size.width.round()}dp', (tester) async {
        final context = await host(tester, size);
        showZSheet<void>(
          context,
          title: 'Sort by',
          builder: (_) => const Text('Newest'),
        );
        await tester.pumpAndSettle();

        // The sheet's surface; the BottomSheet itself spans the screen to
        // centre it.
        final sheet = tester.getRect(
          find
              .descendant(
                of: find.byType(BottomSheet),
                matching: find.byType(Material),
              )
              .first,
        );
        expect(sheet.width, size == phone ? phone.width : ContentWidth.sheet);
        expect(sheet.center.dx, size.width / 2);
        expect(tester.takeException(), isNull);
      });
    }
  });

  // ---------------------------------------------------------------------------
  group('Orientation', () {
    test('phones stay portrait; tablets may turn', () {
      expect(OrientationPolicy.allowsLandscape(const Size(390, 844)), isFalse);
      // A phone held sideways is still a phone.
      expect(OrientationPolicy.allowsLandscape(const Size(844, 390)), isFalse);
      expect(OrientationPolicy.allowsLandscape(const Size(599, 900)), isFalse);
      expect(
        OrientationPolicy.allowsLandscape(const Size(599.4, 900)),
        isFalse,
      );
      expect(OrientationPolicy.allowsLandscape(const Size(600, 960)), isTrue);
      // An sw600dp tablet that Flutter measures a hair short of 600dp.
      expect(
        OrientationPolicy.allowsLandscape(const Size(_floatSw600, 959.99997)),
        isTrue,
      );
      expect(
        OrientationPolicy.allowsLandscape(const Size(959.99997, _floatSw600)),
        isTrue,
      );
      expect(OrientationPolicy.allowsLandscape(const Size(1280, 800)), isTrue);
      // A window not measured yet is treated as a phone.
      expect(OrientationPolicy.allowsLandscape(Size.zero), isFalse);
    });

    /// Records what the app asks the platform for, from a fresh policy.
    List<Object?> recordOrientations(WidgetTester tester) {
      final calls = <Object?>[];
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        (call) async {
          if (call.method == 'SystemChrome.setPreferredOrientations') {
            calls.add(call.arguments);
          }
          return null;
        },
      );
      addTearDown(
        () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
          SystemChannels.platform,
          null,
        ),
      );
      addTearDown(tester.view.reset);
      OrientationPolicy.reset();
      addTearDown(OrientationPolicy.reset);
      return calls;
    }

    const portrait = [
      'DeviceOrientation.portraitUp',
      'DeviceOrientation.portraitDown',
    ];

    testWidgets('a tablet measured only after main() ran is unlocked once '
        'the app is up', (tester) async {
      final calls = recordOrientations(tester);

      // main(): Android has not measured the view yet, so portrait is
      // locked for now.
      tester.view.physicalSize = Size.zero;
      await OrientationPolicy.update();
      expect(calls, [portrait]);

      // The real size arrives while main() is still starting up, before
      // anything listens for it...
      tester.view.devicePixelRatio = 2;
      tester.view.physicalSize = const Size(800, 1280) * 2;
      await tester.idle();
      expect(calls, hasLength(1));

      // ...and the app root applies the policy as it mounts.
      await tester.pumpWidget(const OrientationPolicyHost(child: SizedBox()));
      await tester.pump();
      expect(calls, hasLength(2));
      expect(calls.last, isEmpty, reason: 'every orientation allowed');
    });

    testWidgets('the app root follows a foldable opened and folded', (
      tester,
    ) async {
      final calls = recordOrientations(tester);
      tester.view.devicePixelRatio = 2;
      tester.view.physicalSize = phone * 2;

      await tester.pumpWidget(const OrientationPolicyHost(child: SizedBox()));
      await tester.pump();
      expect(calls, [portrait]);

      // Opened out: a 700x840 tablet that may turn.
      tester.view.physicalSize = const Size(700, 840) * 2;
      await tester.pump();
      expect(calls, hasLength(2));
      expect(calls.last, isEmpty);

      // Folded again.
      tester.view.physicalSize = phone * 2;
      await tester.pump();
      expect(calls, hasLength(3));
      expect(calls.last, portrait);
    });

    testWidgets('a 1080x1920 tablet whose float density leaves it a hair '
        'under 600dp may turn', (tester) async {
      final calls = recordOrientations(tester);

      // 288dpi: Android's density of 1.8, as a float.
      tester.view.devicePixelRatio = 1080 / _floatSw600;
      tester.view.physicalSize = const Size(1080, 1920);
      expect(
        tester.view.physicalSize.width / tester.view.devicePixelRatio,
        lessThan(600),
      );
      await OrientationPolicy.update();

      expect(calls, hasLength(1));
      expect(calls.single, isEmpty, reason: 'every orientation allowed');
    });

    testWidgets('the platform is told once per change', (tester) async {
      final calls = recordOrientations(tester);

      tester.view.devicePixelRatio = 2;
      tester.view.physicalSize = phone * 2;
      await OrientationPolicy.update();
      await OrientationPolicy.update();
      expect(calls, [
        ['DeviceOrientation.portraitUp', 'DeviceOrientation.portraitDown'],
      ]);

      tester.view.physicalSize = const Size(800, 1280) * 2;
      await OrientationPolicy.update();
      // Turning the tablet changes nothing: its shorter side is the same.
      tester.view.physicalSize = const Size(1280, 800) * 2;
      await OrientationPolicy.update();
      expect(calls, hasLength(2));
      expect(calls.last, isEmpty, reason: 'every orientation allowed');
    });
  });
}

/// What a 600dp-wide (`sw600dp`) 7-inch tablet measures in Flutter: Android
/// works the size out with a float density, so it can arrive a hair short.
const _floatSw600 = 599.99998;

/// Whether a window [width] dp wide is a phone: under 600dp by at least the
/// half-dp the breakpoints allow for float error. Spelled out here rather
/// than asked of `WindowSize`, so the tests do not grade the code with
/// itself.
bool _isPhoneWidth(double width) => width < 599.5;

/// Whether a window [width] dp wide is a medium (portrait tablet) window,
/// with the same half-dp allowance at 840.
bool _isMediumWidth(double width) => !_isPhoneWidth(width) && width < 839.5;

/// How many columns [CreatorGridDelegate] lays out across a grid [width] dp
/// wide.
int _gridColumns(double width, {int maxColumns = 2}) {
  final layout =
      CreatorGridDelegate(maxColumns: maxColumns).getLayout(
            SliverConstraints(
              axisDirection: AxisDirection.down,
              growthDirection: GrowthDirection.forward,
              userScrollDirection: ScrollDirection.idle,
              scrollOffset: 0,
              precedingScrollExtent: 0,
              overlap: 0,
              remainingPaintExtent: 1000,
              crossAxisExtent: width,
              crossAxisDirection: AxisDirection.right,
              viewportMainAxisExtent: 1000,
              remainingCacheExtent: 1000,
              cacheOrigin: 0,
            ),
          )
          as SliverGridRegularTileLayout;
  return layout.crossAxisCount;
}

class _ScreenCase {
  _ScreenCase(
    this.name,
    this.build,
    this.maxWidth, {
    this.asCreator = false,
    this.packages = false,
    this.searchSpansMediumColumn = false,
  });

  final String name;
  final Widget Function() build;

  /// The widest the page's column may be on a tablet.
  final double maxWidth;
  final bool asCreator;

  /// Whether the screen needs the creator's packages.
  final bool packages;

  /// Whether the screen's one search bar spans its column (less the page
  /// margins) on a medium window instead of keeping a readable width, as
  /// Home's does to share the sections' edges.
  final bool searchSpansMediumColumn;
}

/// Signed in from the start, without the session restore the real controller
/// kicks off.
class _SignedIn extends AuthController {
  _SignedIn(this.user);

  final User user;

  @override
  AuthState build() => AuthState(stage: AuthStage.authenticated, user: user);
}

class _Online extends ConnectivityService {
  @override
  Future<bool> isOnline() async => true;
}

/// Three packages for My packages, without the network.
class _Packages extends CreatorToolsRepository {
  _Packages()
    : super(
        ApiClient(
          secureStore: SecureStore(),
          connectivity: _Online(),
          dio: Dio(),
        ),
      );

  @override
  Future<OwnPackages> packages({bool fresh = false}) async => OwnPackages(
    packages: [
      for (final (id, title, price) in [
        (1, 'One reel', 15000.0),
        (2, 'Story set', 8000.0),
        (3, 'Two reels and a story', 25000.0),
      ])
        OwnPackage(
          isActive: true,
          package: CreatorPackage(
            id: id,
            title: title,
            platform: 'instagram',
            deliverableType: 'reel',
            price: price,
            priceFormatted: '₹${price.round()}',
            turnaroundDays: 5,
            revisions: 1,
          ),
        ),
    ],
  );
}

const _slides = [
  OnboardingSlide(
    id: 1,
    title: 'Find the perfect Creator for your brand.',
    body: 'Browse verified creators by city, category, and budget.',
  ),
  OnboardingSlide(
    id: 2,
    title: 'Book with confidence.',
    body: 'Payments are held by Zusty until you approve the deliverables.',
  ),
];

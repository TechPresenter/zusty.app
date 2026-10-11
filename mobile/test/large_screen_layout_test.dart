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

  /// A window of [size] dp, with an on-screen keyboard [keyboard] dp tall.
  void setWindow(WidgetTester tester, Size size, {double keyboard = 0}) {
    tester.view.physicalSize = size * 2;
    tester.view.devicePixelRatio = 2;
    tester.view.viewInsets = FakeViewPadding(bottom: keyboard * 2);
    addTearDown(tester.view.reset);
  }

  Future<void> pumpAt(
    WidgetTester tester,
    Size size,
    Widget home, {
    User? user,
    List<Override> overrides = const [],
    double keyboard = 0,
  }) async {
    setWindow(tester, size, keyboard: keyboard);

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
      _ScreenCase('Home (brand)', () => const HomeScreen(), ContentWidth.wide),
      _ScreenCase(
        'Home (creator)',
        () => const HomeScreen(),
        ContentWidth.wide,
        asCreator: true,
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

          if (screenWidth < Breakpoints.medium) {
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

    for (final size in const [phone, Size(599, 900)]) {
      testWidgets('a ${size.width.round()}dp phone keeps the full-width '
          'bottom bar', (tester) async {
        await pumpShell(tester, size);

        expect(tester.takeException(), isNull);
        expect(find.byType(NavigationRail), findsNothing);
        expect(tester.getRect(navRow().first).width, size.width);
      });
    }

    testWidgets('a 600dp tablet centres the bottom bar', (tester) async {
      await pumpShell(tester, const Size(600, 960));

      expect(find.byType(NavigationRail), findsNothing);
      expect(tester.getRect(navRow().first).width, ContentWidth.navigationBar);
    });

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

    testWidgets('the home search bar keeps a readable width, centred', (
      tester,
    ) async {
      await pumpAt(tester, const Size(1280, 800), const HomeScreen());

      final field = tester.getRect(find.byType(ZSearchField));
      expect(field.width, ContentWidth.readable - 48);
      expect(field.center.dx, closeTo(640, 0.5));
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
      expect(OrientationPolicy.allowsLandscape(const Size(600, 960)), isTrue);
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
  });

  final String name;
  final Widget Function() build;

  /// The widest the page's column may be on a tablet.
  final double maxWidth;
  final bool asCreator;

  /// Whether the screen needs the creator's packages.
  final bool packages;
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

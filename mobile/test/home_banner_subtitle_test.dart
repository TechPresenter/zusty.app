// The Home hero banner shows its subtitle on up to two lines.
//
// The creator banner's subtitle ("Set your packages, get booked, and get paid
// when the work is approved.") was cut after "and" on a phone when the
// subtitle had one line. The headline keeps its two lines; at a larger text
// size the subtitle gives way, and nothing overflows. Migration 060 shortened
// the subtitle to fit two lines on a 360 dp phone too.
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/base_repository.dart';
import 'package:zuste/features/home/home_screen.dart';
import 'package:zuste/models/home_feed.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/app_config_provider.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/discovery_provider.dart';

import 'support/fixtures.dart';
import 'support/mock_data.dart';

const _title = 'Turn Your Audience Into Income';
const _subtitle = 'Set your packages, get booked, get paid on approval.';

const _feed = HomeFeed(
  sections: [
    HomeSection(
      id: 1,
      type: 'banner_carousel',
      title: 'Featured',
      layout: 'full',
      banners: [
        HomeBanner(
          id: 2,
          title: _title,
          subtitle: _subtitle,
          ctaLabel: 'Get Started',
        ),
      ],
    ),
  ],
);

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

  /// A 1080x2400 phone at 420 dpi (411 x 914 logical), like the Play captures.
  Future<void> pumpHome(WidgetTester tester, {double textScale = 1}) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.625;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          keyValueCacheProvider.overrideWithValue(cache),
          currentUserProvider.overrideWithValue(
            User.fromJson(MockData.currentUser),
          ),
          homeFeedProvider.overrideWith((ref) async => _feed),
        ],
        child: MaterialApp(
          theme: AppTheme.light,
          builder: (context, child) => MediaQuery(
            data: MediaQuery.of(context)
                .copyWith(textScaler: TextScaler.linear(textScale)),
            child: child!,
          ),
          home: const HomeScreen(),
        ),
      ),
    );

    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
  }

  testWidgets('the subtitle may take two lines under a two-line headline',
      (tester) async {
    await pumpHome(tester);

    expect(tester.takeException(), isNull);

    final subtitle = tester.widget<Text>(find.text(_subtitle));
    expect(subtitle.maxLines, 2);

    // Two lines of bodySmall (16 px each), and the headline keeps both of
    // its lines (25 px each).
    expect(tester.getSize(find.text(_subtitle)).height, closeTo(32, 0.5));
    expect(tester.getSize(find.text(_title)).height, closeTo(50, 0.5));
  });

  testWidgets('at the largest text size the headline keeps its two lines',
      (tester) async {
    // The app caps text scaling at 1.25 (app.dart).
    await pumpHome(tester, textScale: 1.25);

    expect(tester.takeException(), isNull, reason: 'no RenderFlex overflow');
    expect(tester.getSize(find.text(_title)).height, closeTo(62.5, 0.5));
    expect(find.text(_subtitle), findsOneWidget);
  });
}

// Counts read aloud or shown with their noun agree with the number.
//
// A creator with one review was announced to screen readers as "Rated 4.0
// from 1 reviews", and the same pattern printed "1 days usage rights",
// "1 creators", "1 applicants" and "1 stars: 1 reviews", and RatingStars
// would have printed "(1 Reviews)". Formatters.count is the one place for the
// plural now.
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/core/utils/formatters.dart';
import 'package:zuste/data/repositories/base_repository.dart';
import 'package:zuste/features/creator/creator_profile_screen.dart';
import 'package:zuste/models/creator.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/discovery_provider.dart';
import 'package:zuste/shared/widgets/status_chip.dart';

import 'support/fixtures.dart';
import 'support/mock_data.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('Formatters.count', () {
    test('one is singular, everything else plural', () {
      expect(Formatters.count(1, 'review'), '1 review');
      expect(Formatters.count(0, 'review'), '0 reviews');
      expect(Formatters.count(2, 'day'), '2 days');
      expect(Formatters.count(null, 'creator'), '0 creators');
    });

    test('groups large numbers and takes an irregular plural', () {
      expect(Formatters.count(124000, 'creator'), '1,24,000 creators');
      expect(Formatters.count(3, 'person', 'people'), '3 people');
      expect(Formatters.count(1, 'person', 'people'), '1 person');
    });
  });

  group('RatingStars', () {
    Future<void> pumpStars(WidgetTester tester, int count) => tester.pumpWidget(
          MaterialApp(
            theme: AppTheme.light,
            home: Scaffold(body: RatingStars(rating: 4.5, count: count)),
          ),
        );

    testWidgets('shows "(1 review)" for one review', (tester) async {
      await pumpStars(tester, 1);

      expect(find.text('(1 review)'), findsOneWidget);
      expect(find.textContaining('Reviews'), findsNothing);
    });

    testWidgets('shows "(12 reviews)" for more than one', (tester) async {
      await pumpStars(tester, 12);

      expect(find.text('(12 reviews)'), findsOneWidget);
    });
  });

  group('the creator profile rating label', () {
    late KeyValueCache cache;

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      cache = await KeyValueCache.open();
      BaseRepository.demoMode = true;
      registerTestFixtures();
    });

    Future<void> pumpProfile(WidgetTester tester, int reviews) async {
      tester.view.physicalSize = const Size(1170, 7200);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      final profile = CreatorProfile.fromJson({
        ...MockData.creatorProfile('ananyasharma'),
        'rating': 4.0,
        'rating_count': reviews,
      });

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            keyValueCacheProvider.overrideWithValue(cache),
            currentUserProvider.overrideWithValue(
              User.fromJson(MockData.currentUser),
            ),
            creatorProfileProvider.overrideWith((ref, handle) async => profile),
          ],
          child: MaterialApp(
            theme: AppTheme.light,
            home: const CreatorProfileScreen(handle: 'ananyasharma'),
          ),
        ),
      );

      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(tester.takeException(), isNull);
    }

    testWidgets('says "1 review" for one review', (tester) async {
      final semantics = tester.ensureSemantics();
      await pumpProfile(tester, 1);

      expect(find.bySemanticsLabel('Rated 4.0 from 1 review'), findsOneWidget);
      expect(find.bySemanticsLabel('Rated 4.0 from 1 reviews'), findsNothing);
      semantics.dispose();
    });

    testWidgets('says "reviews" for more than one', (tester) async {
      final semantics = tester.ensureSemantics();
      await pumpProfile(tester, 12);

      expect(find.bySemanticsLabel('Rated 4.0 from 12 reviews'), findsOneWidget);
      semantics.dispose();
    });
  });
}

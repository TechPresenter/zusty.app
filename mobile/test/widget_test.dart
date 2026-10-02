import 'dart:ui' show SemanticsAction;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/shared/widgets/status_chip.dart';
import 'package:zuste/shared/widgets/z_button.dart';
import 'package:zuste/shared/widgets/menu_tile.dart';
import 'package:zuste/shared/widgets/z_feedback.dart';

/// Wraps a widget in the app theme so styling resolves the same way it does in
/// the running app.
Widget _host(Widget child) => MaterialApp(
  theme: AppTheme.light,
  home: Scaffold(body: Center(child: child)),
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('ZButton', () {
    testWidgets('renders its label and fires onPressed', (tester) async {
      var taps = 0;

      await tester.pumpWidget(
        _host(ZButton(label: 'Book Now', onPressed: () => taps++)),
      );

      expect(find.text('Book Now'), findsOneWidget);

      await tester.tap(find.text('Book Now'));
      expect(taps, 1);
    });

    testWidgets('swaps the label for a spinner while loading', (tester) async {
      await tester.pumpWidget(
        _host(const ZButton(label: 'Book Now', isLoading: true)),
      );

      expect(find.text('Book Now'), findsNothing);
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('ignores taps when disabled', (tester) async {
      var taps = 0;

      await tester.pumpWidget(_host(const ZButton(label: 'Pay')));

      await tester.tap(find.text('Pay'));
      expect(taps, 0);
    });
  });

  group('StatusChip', () {
    testWidgets('renders the status label', (tester) async {
      await tester.pumpWidget(
        _host(const StatusChip(label: 'Confirmed', tone: StatusTone.success)),
      );

      expect(find.text('Confirmed'), findsOneWidget);
    });
  });

  group('KeyValueCache', () {
    setUp(() => SharedPreferences.setMockInitialValues({}));

    test('records that onboarding is complete', () async {
      final cache = await KeyValueCache.open();

      expect(cache.hasCompletedOnboarding, isFalse);
      await cache.setOnboardingComplete();
      expect(cache.hasCompletedOnboarding, isTrue);
    });

    test('keeps recent searches unique and newest-first', () async {
      final cache = await KeyValueCache.open();

      await cache.addRecentSearch('lifestyle');
      await cache.addRecentSearch('fashion');
      await cache.addRecentSearch('lifestyle');

      expect(cache.recentSearches, ['lifestyle', 'fashion']);
    });

    test('round-trips a cached list', () async {
      final cache = await KeyValueCache.open();

      await cache.writeList('creators:page=1', [
        {'id': 1, 'display_name': 'Ananya Sharma'},
      ]);

      final cached = cache.readList('creators:page=1');
      expect(cached, isNotNull);
      expect(cached!.single['display_name'], 'Ananya Sharma');
    });
  });

  group('ZMenuTile', () {
    // A section's only row used to merge into the section title, so screen
    // readers read "Your account, Delete my account" as plain text.
    testWidgets('a lone row in a section is its own button', (tester) async {
      final handle = tester.ensureSemantics();
      var taps = 0;

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(
            body: ListView(
              children: [
                ZMenuSection(
                  title: 'Your account',
                  children: [
                    ZMenuTile(
                      icon: Icons.delete_outline_rounded,
                      title: 'Delete my account',
                      subtitle: 'Erase your profile and data',
                      destructive: true,
                      onTap: () => taps++,
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      );

      final data = tester
          .getSemantics(find.byType(ZMenuTile))
          .getSemanticsData();
      expect(data.flagsCollection.isButton, isTrue);
      expect(data.hasAction(SemanticsAction.tap), isTrue);
      expect(data.label, contains('Delete my account'));
      expect(data.label, contains('Erase your profile and data'));
      expect(data.label.toLowerCase(), isNot(contains('your account')));

      await tester.tap(find.text('Delete my account'));
      expect(taps, 1);
      handle.dispose();
    });
  });

  group('ZInfoBanner', () {
    // A 360dp phone at 1.3x text, the narrowest the app is laid out for.
    Future<void> pumpNarrow(WidgetTester tester, Widget banner) async {
      tester.view.physicalSize = const Size(360, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: MediaQuery(
            data: const MediaQueryData(
              size: Size(360, 800),
              textScaler: TextScaler.linear(1.3),
            ),
            child: Scaffold(
              body: Padding(padding: const EdgeInsets.all(16), child: banner),
            ),
          ),
        ),
      );
    }

    testWidgets('keeps a short action beside the message', (tester) async {
      await pumpNarrow(
        tester,
        ZInfoBanner(
          message: 'Your payout is on hold.',
          actionLabel: 'View',
          onAction: () {},
        ),
      );

      final message = tester.getRect(find.text('Your payout is on hold.'));
      final action = tester.getRect(find.text('View'));
      expect(action.left, greaterThan(message.right));
      expect(tester.takeException(), isNull);
    });

    testWidgets('moves a long action under the message', (tester) async {
      var taps = 0;
      await pumpNarrow(
        tester,
        ZInfoBanner(
          message: 'One of your documents was not accepted.',
          actionLabel: 'Re-upload documents',
          onAction: () => taps++,
        ),
      );

      final message = tester.getRect(
        find.text('One of your documents was not accepted.'),
      );
      final action = tester.getRect(find.text('Re-upload documents'));
      expect(action.top, greaterThanOrEqualTo(message.bottom));
      expect(tester.takeException(), isNull);

      await tester.tap(find.text('Re-upload documents'));
      expect(taps, 1);
    });
  });
}

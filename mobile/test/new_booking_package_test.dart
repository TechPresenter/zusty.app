// The booking form, opened with a package already picked.
//
// The profile's "Choose a package" sheet opens the form with that package
// selected, and the form says a package "Fills in the title and budget". It
// did not: initState only remembered the package id, so the brand saw the
// package ticked above an empty title and budget. Picking a different
// package also kept the first package's title, because the title was only
// filled in while it was empty.
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/base_repository.dart';
import 'package:zuste/features/booking/new_booking_screen.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/discovery_provider.dart';
import 'package:zuste/shared/widgets/z_text_field.dart';

import 'support/fixtures.dart';
import 'support/mock_data.dart';

const _handle = 'ananyasharma';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    cache = await KeyValueCache.open();
    BaseRepository.demoMode = true;
    registerTestFixtures();
  });

  // The creator's packages as the demo profile serves them: ids 1, 2 and 3.
  final packages = (MockData.creatorProfile(_handle)['packages'] as List)
      .cast<Map<String, dynamic>>();
  String titleOf(int id) =>
      packages.firstWhere((p) => p['id'] == id)['title'] as String;
  String priceOf(int id) => (packages.firstWhere((p) => p['id'] == id)['price']
          as num)
      .toStringAsFixed(0);

  Future<void> pumpApp(WidgetTester tester, Widget home) async {
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
        ],
        child: MaterialApp(theme: AppTheme.light, home: home),
      ),
    );

    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(tester.takeException(), isNull);
  }

  Future<void> pumpForm(WidgetTester tester, {int? packageId}) =>
      pumpApp(tester, NewBookingScreen(handle: _handle, packageId: packageId));

  // The form's fields, top to bottom: campaign title, brief, budget.
  Finder input(int index) => find.descendant(
        of: find.byType(ZTextField).at(index),
        matching: find.byType(EditableText),
      );
  String textOf(WidgetTester tester, int index) =>
      tester.widget<EditableText>(input(index)).controller.text;
  String title(WidgetTester tester) => textOf(tester, 0);
  String budget(WidgetTester tester) => textOf(tester, 2);

  /// The package card's own title (a Text, not the title field's text).
  Future<void> tapPackage(WidgetTester tester, int id) async {
    final card = find
        .byWidgetPredicate((w) => w is Text && w.data == titleOf(id))
        .first;
    await tester.ensureVisible(card);
    await tester.tap(card);
    await tester.pump();
  }

  int selectedPackages() =>
      find.byIcon(Icons.radio_button_checked_rounded).evaluate().length;

  testWidgets('a package picked before the form opens fills in its title and budget',
      (tester) async {
    await pumpForm(tester, packageId: 2);

    expect(find.byType(ZTextField), findsNWidgets(3));
    expect(selectedPackages(), 1);
    expect(title(tester), titleOf(2));
    expect(budget(tester), priceOf(2));
  });

  testWidgets('…also when the profile is already loaded, as it is from the profile screen',
      (tester) async {
    // The profile screen keeps the creator's profile loaded while the sheet
    // pushes the form, so the form finds it before its first frame.
    await pumpApp(
      tester,
      Consumer(
        builder: (context, ref, _) {
          ref.watch(creatorProfileProvider(_handle));
          return Scaffold(
            body: TextButton(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) =>
                      const NewBookingScreen(handle: _handle, packageId: 2),
                ),
              ),
              child: const Text('Book'),
            ),
          );
        },
      ),
    );

    await tester.tap(find.text('Book'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));

    expect(tester.takeException(), isNull);
    expect(title(tester), titleOf(2));
    expect(budget(tester), priceOf(2));
  });

  testWidgets('switching package replaces the title the form filled in',
      (tester) async {
    await pumpForm(tester, packageId: 2);
    await tapPackage(tester, 3);

    expect(selectedPackages(), 1);
    expect(title(tester), titleOf(3));
    expect(budget(tester), priceOf(3));
  });

  testWidgets('switching package keeps a title the brand typed',
      (tester) async {
    await pumpForm(tester, packageId: 2);

    await tester.enterText(input(0), 'Diwali launch reel');
    await tester.pump();
    await tapPackage(tester, 3);

    expect(title(tester), 'Diwali launch reel');
    expect(budget(tester), priceOf(3));
  });

  testWidgets('without a package, picking one fills in both', (tester) async {
    await pumpForm(tester);

    expect(selectedPackages(), 0);
    expect(title(tester), isEmpty);
    expect(budget(tester), isEmpty);

    await tapPackage(tester, 2);

    expect(title(tester), titleOf(2));
    expect(budget(tester), priceOf(2));
  });

  testWidgets('a package the creator no longer offers opens the form without one',
      (tester) async {
    await pumpForm(tester, packageId: 99);

    expect(selectedPackages(), 0);
    expect(title(tester), isEmpty);
    expect(budget(tester), isEmpty);
  });
}

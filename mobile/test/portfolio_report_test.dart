// Reporting a portfolio item from the full-screen viewer.
//
// The server has always accepted `portfolio` reports and the app's model had
// the target, but no screen offered it: Google Play's user-generated content
// policy asks for a way to report every piece of user content. The viewer's
// ⋮ menu now reports the item on screen through the shared report sheet, for
// everyone but the creator. The repository is faked at the edge, so this pins
// what would be sent.
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/base_repository.dart';
import 'package:zuste/data/repositories/safety_repository.dart';
import 'package:zuste/features/creator/widgets/media_viewer.dart';
import 'package:zuste/models/creator.dart';
import 'package:zuste/models/safety.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/safety_provider.dart';

/// Records the reports the viewer would file.
class _FakeSafety extends SafetyRepository {
  _FakeSafety(super.client);

  final reports = <Map<String, Object?>>[];

  @override
  Future<String> report({
    required ReportTarget target,
    required int id,
    required String reason,
    String? note,
  }) async {
    reports.add({'target': target, 'id': id, 'reason': reason});
    return 'Thank you. Our moderation team will review this report.';
  }
}

const _items = [
  PortfolioItem(id: 41, mediaKind: 'photo', title: 'Monsoon lookbook'),
  PortfolioItem(id: 42, mediaKind: 'photo', title: 'Street food series'),
];

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late _FakeSafety fake;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    BaseRepository.demoMode = false;
  });

  Future<void> settle(WidgetTester tester) async {
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
  }

  Future<void> tapText(WidgetTester tester, String text) async {
    await tester.ensureVisible(find.text(text).last);
    await tester.tap(find.text(text).last);
    await settle(tester);
  }

  /// A screen with one button that opens the viewer on [initialIndex].
  Future<void> pumpViewer(
    WidgetTester tester, {
    required bool canReport,
    int initialIndex = 0,
  }) async {
    tester.view.physicalSize = const Size(1170, 2532);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    final cache = await KeyValueCache.open();
    fake = _FakeSafety(
      ApiClient(
        secureStore: SecureStore(),
        connectivity: ConnectivityService(),
        responseCache: cache,
        dio: Dio(),
      ),
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          safetyRepositoryProvider.overrideWithValue(fake),
          currentUserProvider.overrideWithValue(null),
        ],
        child: MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(
            body: Builder(
              builder: (context) => Center(
                child: TextButton(
                  onPressed: () => MediaViewer.open(
                    context,
                    items: _items,
                    initialIndex: initialIndex,
                    canReport: canReport,
                    creatorName: 'Aanya Rao',
                    creatorUserId: 7,
                  ),
                  child: const Text('open'),
                ),
              ),
            ),
          ),
        ),
      ),
    );
    await settle(tester);
    await tapText(tester, 'open');
  }

  testWidgets('someone else\'s post is reported from the viewer\'s menu', (tester) async {
    await pumpViewer(tester, canReport: true);

    expect(find.text('Monsoon lookbook'), findsOneWidget);

    await tester.tap(find.byTooltip('More options'));
    await settle(tester);
    await tapText(tester, 'Report post');

    expect(find.text('Report Aanya Rao\'s post'), findsOneWidget);
    expect(find.text('Why are you reporting this post?'), findsOneWidget);

    await tapText(tester, 'Uses someone\'s work without permission');
    await tapText(tester, 'Submit report');

    expect(fake.reports.single, {
      'target': ReportTarget.portfolio,
      'id': 41,
      'reason': 'copyright',
    });
    expect(find.text('Report sent'), findsOneWidget);
    expect(find.text('Block Aanya Rao'), findsOneWidget, reason: 'the usual next step is offered');
  });

  testWidgets('it reports the item on screen, not the first one', (tester) async {
    await pumpViewer(tester, canReport: true, initialIndex: 1);

    expect(find.text('Street food series'), findsOneWidget);

    await tester.tap(find.byTooltip('More options'));
    await settle(tester);
    await tapText(tester, 'Report post');
    await tapText(tester, 'Spam or misleading');
    await tapText(tester, 'Submit report');

    expect(fake.reports.single['target'], ReportTarget.portfolio);
    expect(fake.reports.single['id'], 42);
  });

  testWidgets('the creator\'s own work has no report menu', (tester) async {
    await pumpViewer(tester, canReport: false);

    expect(find.text('Monsoon lookbook'), findsOneWidget);
    expect(find.byTooltip('More options'), findsNothing);
    expect(find.text('Report post'), findsNothing);
  });
}

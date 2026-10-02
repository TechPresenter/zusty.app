// AI writing and matching in the app.
//
// The config block decides what is drawn, the repository decides what is sent,
// and the screens decide where a draft lands. Each is checked here: the
// repository against a scripted Dio adapter (exact request bodies, timeouts,
// error codes), the screens in fixture mode with the AI switches overridden.
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/config/app_config.dart';
import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/ai_repository.dart';
import 'package:zuste/data/repositories/base_repository.dart';
import 'package:zuste/features/ai/ai_match_screen.dart';
import 'package:zuste/features/campaigns/campaign_form_screen.dart';
import 'package:zuste/features/profile/edit_profile_screen.dart';
import 'package:zuste/models/app_settings.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/app_config_provider.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';

import 'support/fixtures.dart';
import 'support/mock_data.dart';

/// Answers every request with one scripted response and keeps the last one.
class _ScriptedAdapter implements HttpClientAdapter {
  _ScriptedAdapter(this.status, this.body);

  final int status;
  final Map<String, dynamic> body;
  RequestOptions? last;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    last = options;

    return ResponseBody.fromString(
      jsonEncode(body),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Map<String, dynamic> _ok(Object? data) =>
    {'success': true, 'message': 'OK', 'data': data};

const _allOn = AiFeatures(
  enabled: true,
  bioWriter: true,
  campaignBrief: true,
  creatorMatch: true,
  dailyLimit: 20,
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    cache = await KeyValueCache.open();
  });

  // ---------------------------------------------------------------------------
  group('config', () {
    test('every AI feature is off until the server says otherwise', () {
      expect(RemoteAppConfig.empty.ai.enabled, isFalse);

      final ai = RemoteAppConfig.fromJson(const {}).ai;
      expect([ai.bioWriter, ai.campaignBrief, ai.creatorMatch], everyElement(isFalse));
    });

    test('reads the ai block of /app/config', () {
      final ai = RemoteAppConfig.fromJson({
        'ai': {
          'enabled': true,
          'bio_writer': true,
          'campaign_brief': false,
          'creator_match': true,
          'daily_limit': 20,
        },
      }).ai;

      expect(ai.bioWriter, isTrue);
      expect(ai.campaignBrief, isFalse);
      expect(ai.creatorMatch, isTrue);
      expect(ai.dailyLimit, 20);
    });

    test('the master switch wins over a stale feature switch', () {
      final ai = AiFeatures.fromJson({'enabled': false, 'bio_writer': true});
      expect(ai.bioWriter, isFalse);
    });
  });

  // ---------------------------------------------------------------------------
  group('repository', () {
    setUp(() => BaseRepository.demoMode = false);

    AiRepository scripted(_ScriptedAdapter adapter) => AiRepository(
          ApiClient(
            secureStore: SecureStore(),
            connectivity: ConnectivityService(),
            responseCache: cache,
            dio: Dio()..httpClientAdapter = adapter,
          ),
        );

    test('the bio writer sends the field length and parses the draft', () async {
      final adapter = _ScriptedAdapter(200, _ok(MockData.aiBio));
      final draft = await scripted(adapter).writeBio(maxChars: 300);

      expect(adapter.last!.method, 'POST');
      expect(adapter.last!.path, '/ai/bio');
      expect(adapter.last!.data, {'max_chars': 300}, reason: 'empty notes are not sent');
      expect(adapter.last!.receiveTimeout, AppConfig.aiReceiveTimeout);
      expect(draft.text, MockData.aiBioText);
      expect(draft.remainingToday, 19);
    });

    test('the brief writer sends the form and leaves unset budgets out', () async {
      final adapter = _ScriptedAdapter(200, _ok(MockData.aiBrief));

      await scripted(adapter).writeCampaignBrief(
        idea: 'Honest reviews of our new serum',
        title: 'Glow launch',
        platform: 'instagram',
        deliverableType: 'reel',
        postsCount: 2,
        budgetMin: 0,
        budgetMax: 15000,
      );

      expect(adapter.last!.path, '/ai/campaign-brief');
      expect(adapter.last!.data, {
        'idea': 'Honest reviews of our new serum',
        'title': 'Glow launch',
        'platform': 'instagram',
        'deliverable_type': 'reel',
        'posts_count': 2,
        'budget_max': 15000.0,
      });
    });

    test('matches come back as creator cards with a score and a reason', () async {
      final adapter = _ScriptedAdapter(200, _ok(MockData.aiMatches));
      final result = await scripted(adapter).matchCreators(brief: 'A skincare launch for Diwali');

      expect(adapter.last!.path, '/ai/creator-match');
      expect(result.matches, hasLength(3));
      expect(result.matches.first.creator.displayName, 'Ananya Sharma');
      expect(result.matches.first.score, 92);
      expect(result.matches.first.reason, 'Strong Lifestyle audience.');
      expect(result.remainingToday, 17);
    });

    test('a switched-off feature surfaces its code', () async {
      final adapter = _ScriptedAdapter(403, {
        'success': false,
        'message': 'This AI feature is switched off.',
        'code': 'ai_disabled',
      });

      await expectLater(
        scripted(adapter).writeBio(),
        throwsA(isA<ApiFailure>()
            .having((f) => f.statusCode, 'statusCode', 403)
            .having((f) => f.code, 'code', 'ai_disabled')),
      );
    });
  });

  // ---------------------------------------------------------------------------
  group('screens', () {
    setUp(() {
      BaseRepository.demoMode = true;
      registerTestFixtures();
    });

    tearDown(clearTestFixtures);

    final creatorUser = {...MockData.currentUser, 'role': 'creator'};

    Future<void> pump(
      WidgetTester tester,
      Widget screen, {
      AiFeatures ai = _allOn,
      Map<String, dynamic>? user,
    }) async {
      tester.view.physicalSize = const Size(1170, 7200);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            keyValueCacheProvider.overrideWithValue(cache),
            currentUserProvider.overrideWithValue(
              User.fromJson(user ?? MockData.currentUser),
            ),
            aiFeaturesProvider.overrideWithValue(ai),
          ],
          child: MaterialApp(theme: AppTheme.light, home: screen),
        ),
      );

      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
    }

    Future<void> settle(WidgetTester tester) async {
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));
    }

    Finder fieldContaining(String text) => find.byWidgetPredicate(
          (widget) => widget is EditableText && widget.controller.text == text,
        );

    testWidgets('a creator drafts their bio with AI', (tester) async {
      await pump(tester, const EditProfileScreen(), user: creatorUser);

      expect(find.text('Write with AI'), findsOneWidget);

      await tester.tap(find.text('Write with AI'));
      await settle(tester);
      expect(find.text('Write your bio with AI'), findsOneWidget);

      // The sheet's own button sits above the screen's.
      await tester.tap(find.text('Write with AI').last);
      await settle(tester);
      expect(
        find.byWidgetPredicate((w) => w is SelectableText && w.data == MockData.aiBioText),
        findsOneWidget,
      );
      expect(find.textContaining('19 AI requests left today'), findsOneWidget);

      // The mock account already has a bio, so accepting says what it does.
      await tester.tap(find.text('Replace my bio'));
      await settle(tester);
      expect(fieldContaining(MockData.aiBioText), findsOneWidget);
    });

    testWidgets('an empty bio is filled rather than replaced', (tester) async {
      await pump(tester, const EditProfileScreen(), user: {...creatorUser, 'bio': ''});

      await tester.tap(find.text('Write with AI'));
      await settle(tester);
      await tester.tap(find.text('Write with AI').last);
      await settle(tester);

      await tester.tap(find.text('Use this'));
      await settle(tester);
      expect(fieldContaining(MockData.aiBioText), findsOneWidget);
    });

    testWidgets('brands, and apps with the feature off, see no bio writer', (tester) async {
      await pump(tester, const EditProfileScreen());
      expect(find.text('Write with AI'), findsNothing, reason: 'brand account');

      await pump(tester, const EditProfileScreen(), user: creatorUser, ai: AiFeatures.off);
      expect(find.text('Write with AI'), findsNothing, reason: 'switched off');
    });

    testWidgets('a brand drafts the campaign brief with AI', (tester) async {
      await pump(tester, const CampaignFormScreen());

      await tester.tap(find.text('Write with AI'));
      await settle(tester);
      expect(find.text('Write the brief with AI'), findsOneWidget);

      await tester.enterText(find.byType(TextField).last, 'Honest reviews of our new serum');
      await tester.tap(find.text('Write with AI').last);
      await settle(tester);

      await tester.tap(find.text('Use this'));
      await settle(tester);
      expect(fieldContaining(MockData.aiBrief['text'] as String), findsOneWidget);
    });

    testWidgets('the brief writer is hidden when switched off', (tester) async {
      await pump(tester, const CampaignFormScreen(), ai: AiFeatures.off);
      expect(find.text('Write with AI'), findsNothing);
    });

    testWidgets('AI match ranks creators for a brief', (tester) async {
      await pump(tester, const AiMatchScreen());

      await tester.enterText(find.byType(TextField), 'Honest skincare reviews for our Diwali launch');
      await tester.tap(find.text('Find matches'));
      await settle(tester);

      expect(find.text('Top matches'), findsOneWidget);
      expect(find.text('92% match'), findsOneWidget);
      expect(find.text('Strong Lifestyle audience.'), findsOneWidget);
      expect(find.text('Ananya Sharma'), findsOneWidget);
      expect(find.text('Invite'), findsNothing, reason: 'only offered inside a campaign');
    });

    testWidgets('AI match asks for a real brief first', (tester) async {
      await pump(tester, const AiMatchScreen());

      await tester.enterText(find.byType(TextField), 'Too short');
      await tester.tap(find.text('Find matches'));
      await settle(tester);

      expect(find.textContaining('at least 20 characters'), findsOneWidget);
      expect(find.text('Top matches'), findsNothing);
    });

    testWidgets('AI match says so when it is switched off', (tester) async {
      await pump(tester, const AiMatchScreen(), ai: AiFeatures.off);
      expect(find.text('AI matching is switched off'), findsOneWidget);
    });
  });
}

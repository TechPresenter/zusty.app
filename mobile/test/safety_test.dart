// Reporting and blocking in the app: the report sheet every content menu
// opens, the block confirmation, the ⋮ menu that offers both, a chat that a
// block has closed, and Settings → Privacy & data → Blocked accounts.
//
// The repository is faked at the edge (it records what would be sent and can
// be told to fail), so these pin what the screens promise: a reason before
// anything is sent, what the server says afterwards, an explanation before a
// block, and a list that follows the server as people are unblocked. One
// group drives the real repository against a scripted adapter to pin the
// requests themselves.
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/base_repository.dart';
import 'package:zuste/data/repositories/message_repository.dart';
import 'package:zuste/data/repositories/safety_repository.dart';
import 'package:zuste/features/chat/chat_screen.dart';
import 'package:zuste/features/safety/block_dialogs.dart';
import 'package:zuste/features/safety/blocked_users_screen.dart';
import 'package:zuste/features/safety/report_sheet.dart';
import 'package:zuste/features/safety/safety_menu.dart';
import 'package:zuste/models/message.dart';
import 'package:zuste/models/safety.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/safety_provider.dart';
import 'package:zuste/shared/widgets/z_button.dart';

/// One thread with Aanya (user 7): her message, and whether the server lets
/// the user write back. With [inInbox] false the inbox leaves the thread out,
/// as the server does once the user has blocked her, and only the thread
/// itself says who it is with.
class _FakeMessages extends MessageRepository {
  _FakeMessages(
    super.client, {
    this.canMessage = true,
    this.inInbox = true,
    this.blockedByMe = false,
  });

  bool canMessage;
  bool inInbox;
  bool blockedByMe;

  Conversation get _row => Conversation(
    id: 1,
    otherName: 'Aanya Rao',
    otherUserId: 7,
    canMessage: canMessage,
    blockedByMe: blockedByMe,
  );

  @override
  Future<List<Conversation>> conversations({String? query}) async =>
      inInbox ? [_row] : const [];

  @override
  Future<ThreadSnapshot> thread(int conversationId, {int? viewerId}) async => (
    messages: [
      Message(
        id: 55,
        type: MessageType.text,
        body: 'Send me your number',
        senderId: 7,
        createdAt: DateTime(2026, 10, 9, 18, 30),
      ),
    ],
    othersTyping: false,
    canMessage: canMessage,
    conversation: _row,
  );

  @override
  Future<void> markRead(int conversationId) async {}
}

/// Records what the screens would send, and fails on request.
class _FakeSafety extends SafetyRepository {
  _FakeSafety(super.client);

  final reports = <Map<String, Object?>>[];
  final blocked = <int>[];
  final unblocked = <int>[];

  List<BlockedUser> list = const [];
  ApiFailure? failNextReport;
  ApiFailure? failBlocks;
  int listCalls = 0;

  @override
  Future<String> report({
    required ReportTarget target,
    required int id,
    required String reason,
    String? note,
  }) async {
    final failure = failNextReport;
    if (failure != null) {
      failNextReport = null;
      throw failure;
    }
    reports.add({'target': target, 'id': id, 'reason': reason, 'note': note});
    return 'Thank you. Our moderation team will review this report.';
  }

  @override
  Future<List<BlockedUser>> blocks() async {
    listCalls++;
    final failure = failBlocks;
    if (failure != null) throw failure;
    return list;
  }

  @override
  Future<String> block(int userId) async {
    blocked.add(userId);
    // Like the server: the next read of the list includes the new block.
    list = [
      BlockedUser(userId: userId, name: 'Account $userId'),
      ...list.where((user) => user.userId != userId),
    ];
    return 'Blocked. They can no longer message you or send you requests.';
  }

  @override
  Future<String> unblock(int userId) async {
    unblocked.add(userId);
    list = list.where((user) => user.userId != userId).toList();
    return 'Unblocked.';
  }
}

/// Answers every request with one status and body, and keeps the last one.
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

/// `GET /me/blocks` over [pages], answering each `page` query with its page
/// and the pagination block the server sends, and recording what was asked.
class _PagedBlocksAdapter implements HttpClientAdapter {
  _PagedBlocksAdapter(this.pages);

  final List<List<Map<String, dynamic>>> pages;
  final requests = <Map<String, dynamic>>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(Map<String, dynamic>.from(options.queryParameters));
    final page = int.parse('${options.queryParameters['page'] ?? 1}');
    final rows = page <= pages.length ? pages[page - 1] : const [];
    return ResponseBody.fromString(
      jsonEncode({
        'success': true,
        'message': 'OK',
        'data': rows,
        'meta': {
          'pagination': {
            'total': pages.fold<int>(0, (sum, p) => sum + p.length),
            'current_page': page,
            'last_page': pages.length,
            'has_more': page < pages.length,
          },
        },
      }),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

final _aanya = BlockedUser.fromJson(const {
  'user_id': 7,
  'name': 'Aanya Rao',
  'account_type': 'creator',
  'handle': 'aanya',
  'blocked_at': '2026-10-03 10:00:00',
});

final _glowCo = BlockedUser.fromJson(const {
  'user_id': 9,
  'name': 'GlowCo',
  'account_type': 'brand',
  'blocked_at': '2026-10-05 18:30:00',
});

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;
  late _FakeSafety fake;
  late ProviderContainer container;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    cache = await KeyValueCache.open();
    BaseRepository.demoMode = false;
  });

  ApiClient client({HttpClientAdapter? adapter}) => ApiClient(
    secureStore: SecureStore(),
    connectivity: ConnectivityService(),
    responseCache: cache,
    dio: adapter == null ? Dio() : (Dio()..httpClientAdapter = adapter),
  );

  Future<void> settle(WidgetTester tester) async {
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
  }

  Future<void> pump(WidgetTester tester, Widget child) async {
    tester.view.physicalSize = const Size(1170, 6000);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    fake = _FakeSafety(client());
    container = ProviderContainer(
      overrides: [
        safetyRepositoryProvider.overrideWithValue(fake),
        currentUserProvider.overrideWithValue(null),
      ],
    );
    addTearDown(container.dispose);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(body: child),
        ),
      ),
    );
    await settle(tester);
  }

  Future<void> tapText(WidgetTester tester, String text) async {
    await tester.ensureVisible(find.text(text).last);
    await tester.tap(find.text(text).last);
    await settle(tester);
  }

  // ---------------------------------------------------------------------------
  group('models', () {
    test('a blocked account reads the list the server sends', () {
      expect(_aanya.userId, 7);
      expect(_aanya.isCreator, isTrue);
      expect(_aanya.blockedLabel, 'Blocked on 03 Oct 2026');
      expect(_glowCo.handle, isNull);
      expect(BlockedUser.fromJson(const {'user_id': 3}).name, 'Zusty user');
    });

    test('every reason the sheet offers is one the server accepts', () {
      const server = {
        'spam', 'fraud', 'payment_issue', 'abuse', 'hate_speech',
        'sexual_content', 'violence', 'self_harm', 'child_safety',
        'impersonation', 'inappropriate', 'copyright', 'other',
      };
      expect(ReportReason.all.map((r) => r.code).toSet().difference(server), isEmpty);
      expect(ReportReason.all.last.code, 'other');
    });

    test('the sheet names what is being reported', () {
      expect(reportTitle(ReportTarget.message, 'Aanya'), 'Report Aanya\'s message');
      expect(reportTitle(ReportTarget.user, 'Aanya'), 'Report Aanya');
      expect(reportTitle(ReportTarget.booking, null), 'Report this booking');
      expect(reportTitle(ReportTarget.campaign, ' '), 'Report this brief');
    });
  });

  // ---------------------------------------------------------------------------
  group('repository', () {
    test('a report sends the target, the reason and a trimmed note', () async {
      final adapter = _ScriptedAdapter(201, {
        'success': true,
        'message': 'Thank you. Our moderation team will review this report.',
        'data': {'report': {'id': 12}},
      });

      final message = await SafetyRepository(client(adapter: adapter)).report(
        target: ReportTarget.campaign,
        id: 44,
        reason: 'fraud',
        note: '  asks for a deposit  ',
      );

      expect(adapter.last!.method, 'POST');
      expect(adapter.last!.path, '/reports');
      expect(adapter.last!.data, {
        'reportable_type': 'campaign',
        'reportable_id': 44,
        'reason': 'fraud',
        'note': 'asks for a deposit',
      });
      expect(message, 'Thank you. Our moderation team will review this report.');
    });

    test('an empty note is not sent', () async {
      final adapter = _ScriptedAdapter(201, {'success': true, 'message': '', 'data': null});

      await SafetyRepository(client(adapter: adapter))
          .report(target: ReportTarget.user, id: 3, reason: 'spam', note: '   ');

      expect(adapter.last!.data, {'reportable_type': 'user', 'reportable_id': 3, 'reason': 'spam'});
    });

    test('a duplicate report surfaces as the server\'s conflict', () async {
      final adapter = _ScriptedAdapter(409, {
        'success': false,
        'message': 'You have already reported this. Our team is reviewing it.',
        'code': 'already_reported',
      });

      expect(
        () => SafetyRepository(client(adapter: adapter))
            .report(target: ReportTarget.review, id: 5, reason: 'abuse'),
        throwsA(isA<ConflictFailure>().having((f) => f.code, 'code', 'already_reported')),
      );
    });

    test('blocking posts the account, unblocking deletes it', () async {
      final adapter = _ScriptedAdapter(201, {'success': true, 'message': 'Blocked.', 'data': {}});
      final repository = SafetyRepository(client(adapter: adapter));

      await repository.block(7);
      expect(adapter.last!.method, 'POST');
      expect(adapter.last!.path, '/me/blocks');
      expect(adapter.last!.data, {'user_id': 7});

      await repository.unblock(7);
      expect(adapter.last!.method, 'DELETE');
      expect(adapter.last!.path, '/me/blocks/7');
    });

    test('the list is read from the paginated endpoint', () async {
      final adapter = _ScriptedAdapter(200, {
        'success': true,
        'message': 'OK',
        'data': [
          {'user_id': 7, 'name': 'Aanya Rao', 'account_type': 'creator', 'handle': 'aanya'},
        ],
        'meta': {'pagination': {'total': 1}},
      });

      final users = await SafetyRepository(client(adapter: adapter)).blocks();

      expect(adapter.last!.path, '/me/blocks');
      expect(adapter.last!.queryParameters['per_page'], SafetyRepository.blocksPageSize);
      expect(adapter.last!.queryParameters['page'], 1);
      expect(users.single.name, 'Aanya Rao');
    });

    test('a list longer than a page is read to the end, so old blocks can be lifted', () async {
      // The server allows 1000 blocks; one page holds 200.
      final adapter = _PagedBlocksAdapter([
        [
          for (var id = 1000; id < 1000 + SafetyRepository.blocksPageSize; id++)
            {'user_id': id, 'name': 'Account $id'},
        ],
        [
          // A block lifted between the two reads shifts this page by one:
          // the last row of page one comes round again.
          {'user_id': 1000 + SafetyRepository.blocksPageSize - 1, 'name': 'Shifted'},
          {'user_id': 7, 'name': 'Aanya Rao', 'blocked_at': '2025-01-02 09:00:00'},
        ],
      ]);

      final users = await SafetyRepository(client(adapter: adapter)).blocks();

      expect(adapter.requests.map((q) => q['page']), [1, 2]);
      expect(users, hasLength(SafetyRepository.blocksPageSize + 1));
      expect(users.last.name, 'Aanya Rao', reason: 'the oldest block is listed too');
      expect(users.map((u) => u.userId).toSet(), hasLength(users.length), reason: 'nobody twice');
    });
  });

  // ---------------------------------------------------------------------------
  group('report sheet', () {
    late Future<bool> result;

    Widget opener({int? blockUserId = 7}) => Builder(
      builder: (context) => Center(
        child: TextButton(
          onPressed: () => result = showReportSheet(
            context,
            target: ReportTarget.message,
            id: 42,
            name: 'Aanya',
            blockUserId: blockUserId,
          ),
          child: const Text('open'),
        ),
      ),
    );

    testWidgets('asks for a reason before anything is sent', (tester) async {
      await pump(tester, opener());
      await tapText(tester, 'open');

      expect(find.text('Report Aanya\'s message'), findsOneWidget);
      expect(find.textContaining('Aanya is not told who made them'), findsOneWidget);
      for (final reason in ReportReason.all) {
        expect(find.text(reason.label), findsOneWidget);
      }

      await tapText(tester, 'Submit report');

      expect(find.text('Choose a reason to continue.'), findsOneWidget);
      expect(fake.reports, isEmpty);
    });

    testWidgets('files the reason and the note, then confirms', (tester) async {
      await pump(tester, opener());
      await tapText(tester, 'open');

      await tapText(tester, 'Harassment or bullying');
      await tester.enterText(find.byType(EditableText).first, 'Keeps messaging after I said no');
      await tester.pump();
      await tapText(tester, 'Submit report');

      expect(fake.reports.single, {
        'target': ReportTarget.message,
        'id': 42,
        'reason': 'abuse',
        'note': 'Keeps messaging after I said no',
      });
      expect(find.text('Report sent'), findsOneWidget);
      expect(find.text('Thank you. Our moderation team will review this report.'), findsOneWidget);
      expect(find.text('Block Aanya'), findsOneWidget, reason: 'the usual next step is offered');

      await tapText(tester, 'Done');

      expect(find.text('Report sent'), findsNothing);
      expect(await result, isTrue);
    });

    testWidgets('a report already under review reads as a confirmation', (tester) async {
      await pump(tester, opener(blockUserId: null));
      fake.failNextReport = const ConflictFailure(
        message: 'You have already reported this. Our team is reviewing it.',
        code: 'already_reported',
      );

      await tapText(tester, 'open');
      await tapText(tester, 'Spam or misleading');
      await tapText(tester, 'Submit report');

      expect(find.text('Report sent'), findsOneWidget);
      expect(find.text('You have already reported this. Our team is reviewing it.'), findsOneWidget);
      expect(find.textContaining('Block'), findsNothing, reason: 'nobody to block was given');
    });

    testWidgets('a refusal keeps the form and says why', (tester) async {
      await pump(tester, opener());
      fake.failNextReport = const ValidationFailure(message: 'Choose a reason for the report.');

      await tapText(tester, 'open');
      await tapText(tester, 'Scam or fraud');
      await tapText(tester, 'Submit report');

      expect(find.text('Choose a reason for the report.'), findsOneWidget);
      expect(find.text('Report sent'), findsNothing);
      expect(find.text('Submit report'), findsOneWidget);
    });

    testWidgets('closing the sheet files nothing', (tester) async {
      await pump(tester, opener());
      await tapText(tester, 'open');
      await tester.tap(find.byTooltip('Close'));
      await settle(tester);

      expect(fake.reports, isEmpty);
      expect(await result, isFalse);
    });
  });

  // ---------------------------------------------------------------------------
  group('block flow', () {
    testWidgets('the confirmation explains the effect before anything happens', (tester) async {
      late Future<bool> outcome;

      await pump(
        tester,
        Consumer(
          builder: (context, ref, _) => TextButton(
            onPressed: () => outcome = confirmBlock(context, ref, userId: 7, name: 'Aanya'),
            child: const Text('block'),
          ),
        ),
      );

      await tapText(tester, 'block');

      expect(find.text('Block Aanya?'), findsOneWidget);
      expect(find.text(blockExplanation('Aanya')), findsOneWidget);
      expect(blockExplanation('Aanya'), contains('won\'t be able to message you'));
      expect(blockExplanation('Aanya'), contains('already paid for can still be completed or disputed'));

      await tapText(tester, 'Cancel');

      expect(fake.blocked, isEmpty, reason: 'changing your mind blocks nobody');
      expect(await outcome, isFalse);

      await tapText(tester, 'block');
      await tapText(tester, 'Block');

      expect(fake.blocked, [7]);
      expect(await outcome, isTrue);
      expect(container.read(blockedIdsProvider), {7});
      expect(
        find.text('Blocked. They can no longer message you or send you requests.'),
        findsOneWidget,
      );
    });

    testWidgets('the menu reports and blocks, then offers to unblock', (tester) async {
      await pump(
        tester,
        const Align(
          alignment: Alignment.topRight,
          child: SafetyMenuButton(
            target: ReportTarget.creator,
            targetId: 31,
            name: 'Aanya',
            userId: 7,
          ),
        ),
      );

      await tester.tap(find.byTooltip('More options'));
      await settle(tester);

      expect(find.text('Report profile'), findsOneWidget);
      expect(find.text('Block Aanya'), findsOneWidget);

      await tapText(tester, 'Report profile');
      expect(find.text('Report Aanya\'s profile'), findsOneWidget);

      await tapText(tester, 'Pretending to be someone else');
      await tapText(tester, 'Submit report');
      expect(fake.reports.single['target'], ReportTarget.creator);
      expect(fake.reports.single['id'], 31);

      // The confirmation's own way to block, straight after the report.
      await tapText(tester, 'Block Aanya');
      await tapText(tester, 'Block');

      expect(fake.blocked, [7]);
      expect(find.text('Report sent'), findsNothing, reason: 'blocking closes the sheet');

      await tester.tap(find.byTooltip('More options'));
      await settle(tester);

      expect(find.text('Unblock Aanya'), findsOneWidget);
      expect(find.text('Block Aanya'), findsNothing);

      await tapText(tester, 'Unblock Aanya');
      expect(find.text('Unblock Aanya?'), findsOneWidget);
      await tapText(tester, 'Unblock');

      expect(fake.unblocked, [7]);
      expect(container.read(blockedIdsProvider), isEmpty);
    });

    testWidgets('a menu without an account only reports', (tester) async {
      await pump(
        tester,
        const Align(
          alignment: Alignment.topRight,
          child: SafetyMenuButton(
            target: ReportTarget.booking,
            targetId: 5,
            name: 'GlowCo',
          ),
        ),
      );

      await tester.tap(find.byTooltip('More options'));
      await settle(tester);

      expect(find.text('Report booking'), findsOneWidget);
      expect(find.textContaining('Block'), findsNothing);
    });
  });

  // ---------------------------------------------------------------------------
  group('blocked accounts screen', () {
    testWidgets('lists the blocked accounts and unblocks one at a time', (tester) async {
      await pump(tester, const SizedBox.shrink());
      fake.list = [_glowCo, _aanya];

      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: MaterialApp(theme: AppTheme.light, home: const BlockedUsersScreen()),
        ),
      );
      await settle(tester);

      expect(find.text('Blocked accounts'), findsOneWidget);
      expect(find.text('GlowCo'), findsOneWidget);
      expect(find.text('Aanya Rao'), findsOneWidget);
      expect(find.text('@aanya  ·  Blocked on 03 Oct 2026'), findsOneWidget);
      expect(container.read(blockedIdsProvider), {9, 7}, reason: 'the list tells the rest of the app');

      await tester.tap(find.widgetWithText(ZButton, 'Unblock').first);
      await settle(tester);
      expect(find.text('Unblock GlowCo?'), findsOneWidget);

      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(fake.unblocked, isEmpty);

      await tester.tap(find.widgetWithText(ZButton, 'Unblock').first);
      await settle(tester);
      await tapText(tester, 'Unblock');

      expect(fake.unblocked, [9]);
      expect(find.text('GlowCo'), findsNothing, reason: 'the list reloads from the server');
      expect(find.text('Aanya Rao'), findsOneWidget);
      expect(container.read(blockedIdsProvider), {7});

      await tester.tap(find.widgetWithText(ZButton, 'Unblock'));
      await settle(tester);
      await tapText(tester, 'Unblock');

      expect(fake.unblocked, [9, 7]);
      expect(find.text('You haven\'t blocked anyone'), findsOneWidget);
    });

    testWidgets('a list that cannot load says so and can be retried', (tester) async {
      await pump(tester, const SizedBox.shrink());
      fake.failBlocks = const ServerFailure();

      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: MaterialApp(theme: AppTheme.light, home: const BlockedUsersScreen()),
        ),
      );
      await settle(tester);

      expect(find.text('We hit a snag'), findsOneWidget);
      final callsBefore = fake.listCalls;

      fake.failBlocks = null;
      fake.list = [_aanya];
      await tapText(tester, 'Try again');

      expect(fake.listCalls, greaterThan(callsBefore));
      expect(find.text('Aanya Rao'), findsOneWidget);
    });
  });

  // ---------------------------------------------------------------------------
  group('chat', () {
    /// The thread opened from the inbox, so closing it has somewhere to go.
    /// A plain ProviderScope: taking the tree down at the end disposes the
    /// thread's poll timer with it.
    Future<void> pumpChat(
      WidgetTester tester, {
      required bool canMessage,
      bool inInbox = true,
      bool blockedByMe = false,
    }) async {
      tester.view.physicalSize = const Size(1170, 2532);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      fake = _FakeSafety(client());

      final router = GoRouter(
        initialLocation: '/messages/1',
        routes: [
          GoRoute(
            path: '/messages',
            builder: (_, _) => const Scaffold(body: Text('inbox')),
            routes: [
              GoRoute(
                path: ':id',
                builder: (_, state) => ChatScreen(
                  conversationId: int.parse(state.pathParameters['id']!),
                ),
              ),
            ],
          ),
        ],
      );
      addTearDown(router.dispose);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            safetyRepositoryProvider.overrideWithValue(fake),
            messageRepositoryProvider.overrideWithValue(
              _FakeMessages(
                client(),
                canMessage: canMessage,
                inInbox: inInbox,
                blockedByMe: blockedByMe,
              ),
            ),
            currentUserProvider.overrideWithValue(null),
          ],
          child: MaterialApp.router(theme: AppTheme.light, routerConfig: router),
        ),
      );
      await settle(tester);
    }

    testWidgets('a thread a block has closed says so instead of the composer', (tester) async {
      await pumpChat(tester, canMessage: false);

      expect(find.text('Send me your number'), findsOneWidget, reason: 'the history stays readable');
      expect(find.textContaining('You can\'t send messages in this conversation'), findsOneWidget);
      expect(find.text('Write a message…'), findsNothing);
    });

    testWidgets('Block sits next to Report, and blocking closes the chat', (tester) async {
      await pumpChat(tester, canMessage: true);

      expect(find.text('Write a message…'), findsOneWidget);

      await tester.tap(find.byTooltip('More options'));
      await settle(tester);

      expect(find.text('Report'), findsOneWidget);
      expect(find.text('Block'), findsOneWidget);

      await tapText(tester, 'Block');
      expect(find.text('Block Aanya Rao?'), findsOneWidget);
      expect(find.text(blockExplanation('Aanya Rao')), findsOneWidget);

      await tapText(tester, 'Block');

      expect(fake.blocked, [7]);
      expect(find.text('inbox'), findsOneWidget, reason: 'the thread leaves the inbox, so the chat closes');
    });

    testWidgets('one message is reported from its long-press', (tester) async {
      await pumpChat(tester, canMessage: true);

      await tester.longPress(find.text('Send me your number'));
      await settle(tester);

      expect(find.text('Copy text'), findsOneWidget);
      await tapText(tester, 'Report message');

      expect(find.text('Report Aanya Rao\'s message'), findsOneWidget);
      await tapText(tester, 'Scam or fraud');
      await tapText(tester, 'Submit report');

      expect(fake.reports.single['target'], ReportTarget.message);
      expect(fake.reports.single['id'], 55);
      expect(find.text('Block Aanya Rao'), findsOneWidget, reason: 'blocking the sender is offered next');
    });

    testWidgets('blocking from the report confirmation closes the chat too', (tester) async {
      await pumpChat(tester, canMessage: true);

      await tester.longPress(find.text('Send me your number'));
      await settle(tester);
      await tapText(tester, 'Report message');
      await tapText(tester, 'Harassment or bullying');
      await tapText(tester, 'Submit report');

      await tapText(tester, 'Block Aanya Rao');
      expect(find.text('Block Aanya Rao?'), findsOneWidget);
      await tapText(tester, 'Block');

      expect(fake.blocked, [7]);
      expect(
        find.text('Write a message…'),
        findsNothing,
        reason: 'the composer never comes back for someone just blocked',
      );
      expect(find.text('inbox'), findsOneWidget, reason: 'the chat closes, as Block from the menu does');
    });

    testWidgets('a thread the inbox leaves out keeps its header and offers Unblock', (tester) async {
      // Blocked in an earlier session and opened from its booking: the inbox
      // no longer lists it, and nothing in this session knew of the block.
      await pumpChat(tester, canMessage: false, inInbox: false, blockedByMe: true);

      expect(find.text('Aanya Rao'), findsWidgets, reason: 'the header names who it is with');
      expect(find.text('Write a message…'), findsNothing);
      expect(find.text('You blocked Aanya Rao. Unblock them to send messages.'), findsOneWidget);

      await tester.tap(find.byTooltip('More options'));
      await settle(tester);

      Finder menuItem(String label) => find.widgetWithText(PopupMenuItem<String>, label);
      expect(menuItem('Unblock'), findsOneWidget);
      expect(menuItem('Block'), findsNothing);
      expect(menuItem('Report'), findsOneWidget);
    });
  });
}

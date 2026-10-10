// Temporary verification harness for the API-contract fixes.
//
// Group 1 drives the real repositories through a scripted Dio adapter and
// asserts the exact request bodies they now emit and the models they build
// from the real server payloads.
//
// Group 2 runs the read paths against the live backend on localhost:8000.
import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/models/booking.dart';
import 'package:zuste/models/home_feed.dart';
import 'package:zuste/data/repositories/booking_repository.dart';
import 'package:zuste/data/repositories/campaign_repository.dart';
import 'package:zuste/data/repositories/creator_repository.dart';
import 'package:zuste/data/repositories/message_repository.dart';
import 'package:zuste/data/repositories/wallet_repository.dart';

class _Call {
  _Call(this.method, this.path, this.body, this.query);
  final String method;
  final String path;
  final Map<String, dynamic> body;
  final Map<String, dynamic> query;
  @override
  String toString() => '$method $path ?$query ${jsonEncode(body)}';
}

/// Answers each path from [routes] and records every request.
class _RecordingAdapter implements HttpClientAdapter {
  _RecordingAdapter(this.routes, {this.html = const {}});

  final Map<String, Map<String, dynamic>> routes;

  /// Paths answered with an HTML document rather than a JSON envelope.
  final Map<String, String> html;
  final calls = <_Call>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final raw = options.data;
    calls.add(
      _Call(
        options.method,
        options.path,
        raw is Map ? Map<String, dynamic>.from(raw) : const {},
        Map<String, dynamic>.from(options.queryParameters),
      ),
    );

    final document = html[options.path];
    if (document != null) {
      return ResponseBody.fromString(
        document,
        200,
        headers: {
          Headers.contentTypeHeader: ['text/html; charset=utf-8'],
        },
      );
    }

    final body = routes[options.path] ??
        <String, dynamic>{'success': true, 'message': 'OK', 'data': {}};

    return ResponseBody.fromString(
      jsonEncode(body),
      200,
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

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    cache = await KeyValueCache.open();
  });

  ApiClient scripted(_RecordingAdapter adapter) => ApiClient(
        secureStore: SecureStore(),
        connectivity: ConnectivityService(),
        responseCache: cache,
        dio: Dio()..httpClientAdapter = adapter,
      );

  group('contract', () {
    test('C34 topup sends gateway, resolved from the enabled gateways',
        () async {
      final adapter = _RecordingAdapter({
        '/payments/methods': _ok({
          'gateways': [
            {
              'id': 'razorpay',
              'label': 'Card / UPI / Net Banking',
              'methods': ['card', 'upi', 'netbanking'],
            },
            {
              'id': 'cashfree',
              'label': 'UPI / Card / Net Banking',
              'methods': ['upi', 'card', 'netbanking'],
            },
            {
              'id': 'wallet',
              'label': 'Zusty Wallet',
              'methods': ['wallet'],
            },
          ],
        }),
        '/wallet/topup': _ok({
          'gateway': 'cashfree',
          'order_id': 'ZT-TOPUP-1',
          'checkout_url': 'https://payments.cashfree.com/order/#session',
          'requires_client_action': true,
        }),
      });

      final order = await WalletRepository(scripted(adapter))
          .topup(amount: 1000, method: 'upi');

      final post = adapter.calls.last;
      expect(post.path, '/wallet/topup');
      expect(post.body.containsKey('method'), isFalse);
      // Razorpay needs a native SDK this build does not ship, so the gateway
      // whose hosted checkout the app can open is chosen.
      expect(post.body['gateway'], 'cashfree');
      expect(post.body['amount'], 1000);
      // The order comes back for the caller to complete, rather than being
      // thrown away and reported as money added.
      expect(order['checkout_url'], 'https://payments.cashfree.com/order/#session');
      expect(order['requires_client_action'], isTrue);
    });

    test('topup with no gateway this build can complete says so, sending nothing',
        () async {
      final adapter = _RecordingAdapter({
        '/payments/methods': _ok({
          'gateways': [
            {'id': 'razorpay', 'label': 'Card', 'methods': ['card']},
            {'id': 'stripe', 'label': 'Card', 'methods': ['card']},
            {'id': 'wallet', 'label': 'Zusty Wallet', 'methods': ['wallet']},
          ],
        }),
      });

      await expectLater(
        WalletRepository(scripted(adapter)).topup(amount: 1000, method: 'upi'),
        throwsA(isA<ConflictFailure>()
            .having((f) => f.code, 'code', 'topup_unavailable')),
      );
      expect(adapter.calls.map((c) => c.path), isNot(contains('/wallet/topup')));
    });

    test('checkout offers only the gateways this build can complete', () async {
      final adapter = _RecordingAdapter({
        '/payments/methods': _ok({
          'gateways': [
            {'id': 'razorpay', 'label': 'Card / UPI', 'methods': ['card', 'upi']},
            {'id': 'cashfree', 'label': 'UPI / Card', 'methods': ['upi', 'card']},
            {'id': 'stripe', 'label': 'Card', 'methods': ['card']},
            {'id': 'wallet', 'label': 'Zusty Wallet', 'methods': ['wallet']},
          ],
        }),
      });

      final methods = await WalletRepository(scripted(adapter)).paymentMethods();

      expect(methods.map((m) => m.gateway), ['cashfree', 'wallet']);
    });

    test('C35 addBankAccount sends account_holder_name and ifsc_code',
        () async {
      final adapter = _RecordingAdapter({});

      await WalletRepository(scripted(adapter)).addBankAccount(
        method: 'bank',
        holderName: 'Aanya Kapoor',
        accountNumber: '000111222333',
        ifsc: 'HDFC0001234',
        bankName: 'HDFC Bank',
      );

      final post = adapter.calls.single;
      expect(post.path, '/wallet/bank-accounts');
      expect(post.body['account_holder_name'], 'Aanya Kapoor');
      expect(post.body['ifsc_code'], 'HDFC0001234');
      expect(post.body.containsKey('account_holder'), isFalse);
      expect(post.body.containsKey('ifsc'), isFalse);
    });

    test('C36 bankAccounts reads data.bank_accounts and its column names',
        () async {
      // Verbatim from GET /wallet/bank-accounts on the running backend.
      final adapter = _RecordingAdapter({
        '/wallet/bank-accounts': _ok({
          'bank_accounts': [
            {
              'id': 1971,
              'method': 'bank',
              'account_holder_name': 'Aanya Kapoor',
              'account_last4': '4017',
              'ifsc_code': 'ICIC0002001',
              'bank_name': 'ICICI Bank',
              'upi_id': null,
              'is_primary': 1,
              'is_verified': 1,
            },
          ],
        }),
      });

      final accounts = await WalletRepository(scripted(adapter)).bankAccounts();

      expect(accounts, hasLength(1));
      expect(accounts.single.id, 1971);
      expect(accounts.single.accountHolder, 'Aanya Kapoor');
      expect(accounts.single.ifsc, 'ICIC0002001');
      expect(accounts.single.displayLabel, 'ICICI Bank •••• 4017');
    });

    test('C37 wallet reads the nested balance and limits blocks', () async {
      // Verbatim from GET /wallet on the running backend.
      final adapter = _RecordingAdapter({
        '/wallet': _ok({
          'balance': {
            'balance': 503400,
            'locked': 325497.5,
            'total': 828897.5,
            'currency': 'INR',
            'is_frozen': false,
          },
          'limits': {'min_withdrawal': 1000, 'max_withdrawal': 200000},
          'kyc_required_for_withdrawal': true,
          'recent_transactions': <dynamic>[],
        }),
      });

      final wallet = await WalletRepository(scripted(adapter)).wallet();

      expect(wallet.balance, 503400);
      expect(wallet.escrow, 325497.5);
      expect(wallet.currency, 'INR');
      expect(wallet.minWithdrawal, 1000);
      expect(wallet.canWithdraw, isTrue);
    });

    test('C37 a frozen wallet cannot withdraw', () async {
      final adapter = _RecordingAdapter({
        '/wallet': _ok({
          'balance': {'balance': 10, 'locked': 0, 'currency': 'INR', 'is_frozen': true},
          'limits': {'min_withdrawal': 1000},
        }),
      });

      final wallet = await WalletRepository(scripted(adapter)).wallet();
      expect(wallet.canWithdraw, isFalse);
    });

    test('C38 saving with publish:true publishes the campaign', () async {
      final adapter = _RecordingAdapter({
        '/campaigns': _ok({
          'campaign': {'id': 4242, 'title': 'Spring drop', 'status': 'draft'},
        }),
        '/campaigns/4242': _ok({
          'campaign': {'id': 4242, 'title': 'Spring drop', 'status': 'open'},
        }),
        '/campaigns/4242/publish': _ok({'campaign': {'id': 4242, 'status': 'open'}}),
      });

      final campaign = await CampaignRepository(scripted(adapter)).save(
        title: 'Spring drop',
        description: 'A brief long enough to pass validation.',
        platform: 'instagram',
        deliverableType: 'reel',
        postsCount: 2,
        budgetMin: 10000,
        budgetMax: 20000,
        publish: true,
      );

      expect(
        adapter.calls.map((c) => '${c.method} ${c.path}'),
        containsAllInOrder([
          'POST /campaigns',
          'POST /campaigns/4242/publish',
          'GET /campaigns/4242',
        ]),
      );
      expect(campaign.status.value, 'open');
    });

    test('C38 saving as a draft does not publish', () async {
      final adapter = _RecordingAdapter({
        '/campaigns': _ok({
          'campaign': {'id': 4243, 'title': 'Draft', 'status': 'draft'},
        }),
      });

      await CampaignRepository(scripted(adapter)).save(
        title: 'Draft',
        description: 'A brief long enough to pass validation.',
        platform: 'instagram',
        deliverableType: 'reel',
        postsCount: 1,
        budgetMin: 1000,
        budgetMax: 2000,
      );

      expect(adapter.calls.map((c) => c.path), ['/campaigns']);
    });

    test('C39 invite issues one creator_id per creator', () async {
      final adapter = _RecordingAdapter({});

      await CampaignRepository(scripted(adapter)).invite(77, [152, 153]);

      expect(adapter.calls, hasLength(2));
      expect(adapter.calls.every((c) => c.path == '/campaigns/77/invite'), isTrue);
      expect(adapter.calls.map((c) => c.body['creator_id']), [152, 153]);
      expect(adapter.calls.any((c) => c.body.containsKey('creator_ids')), isFalse);
    });

    test('C40 start reads data.conversation_id', () async {
      final adapter = _RecordingAdapter({
        '/conversations/start': _ok({'conversation_id': 1069}),
      });

      final id = await MessageRepository(scripted(adapter))
          .start(creatorId: 152);

      expect(id, 1069);
    });

    test('C41 mute sends is_muted, both ways', () async {
      final adapter = _RecordingAdapter({});
      final repo = MessageRepository(scripted(adapter));

      await repo.mute(817, muted: true);
      await repo.mute(817, muted: false);

      expect(adapter.calls.map((c) => c.body['is_muted']), [true, false]);
      expect(adapter.calls.any((c) => c.body.containsKey('muted')), isFalse);
    });

    test('M56 counter offer sends the note as message', () async {
      final adapter = _RecordingAdapter({});

      await BookingRepository(scripted(adapter))
          .counterOffer(81, amount: 6000, note: 'Can do it for this.');

      expect(adapter.calls.single.body['message'], 'Can do it for this.');
      expect(adapter.calls.single.body.containsKey('note'), isFalse);
    });

    test('M50 the booking list asks for tab, not status', () async {
      final adapter = _RecordingAdapter({});

      await BookingRepository(scripted(adapter)).list(status: 'completed');

      final get = adapter.calls.single;
      expect(get.path, '/bookings');
      expect(get.query['tab'], 'completed');
      expect(get.query.containsKey('status'), isFalse);
    });

    test('C43 deliver posts the note and every link', () async {
      final adapter = _RecordingAdapter({});

      await BookingRepository(scripted(adapter)).deliver(
        1037,
        note: 'Delivered the reel and the story frames',
        urls: const [
          'https://instagram.com/p/one',
          'https://instagram.com/p/two',
        ],
      );

      final post = adapter.calls.single;
      expect(post.path, '/bookings/1037/deliver');
      expect(post.body['note'], 'Delivered the reel and the story frames');
      expect(post.body['urls'], hasLength(2));
    });

    test('M57 invoiceHtml fetches the rendering through the API client',
        () async {
      // Opening the endpoint as a link handed a browser a URL it could not
      // authenticate against, so the document is fetched with the token.
      final adapter = _RecordingAdapter(
        {},
        html: {'/bookings/432/invoice': '<html>ZINV-2026-0621</html>'},
      );

      final html = await BookingRepository(scripted(adapter)).invoiceHtml(432);

      expect(html, '<html>ZINV-2026-0621</html>');
      final call = adapter.calls.single;
      expect(call.method, 'GET');
      expect(call.query['format'], 'html');
    });

    test('M57 wallet invoiceHtml fetches the invoice rendering', () async {
      final adapter = _RecordingAdapter(
        {},
        html: {'/invoices/621': '<html>ZINV-2026-0621</html>'},
      );

      final html = await WalletRepository(scripted(adapter)).invoiceHtml(621);

      expect(html, '<html>ZINV-2026-0621</html>');
      expect(adapter.calls.single.query['format'], 'html');
    });

    test('M49 suggestions fall back to the landing blocks', () async {
      final adapter = _RecordingAdapter({
        '/search/suggestions': _ok({
          'recent_searches': ['beauty reel creators'],
          'popular_searches': ['Beauty Creator', 'Fashion Influencer'],
          'suggested_creators': [
            {'display_name': 'Aanya Kapoor'},
          ],
        }),
      });

      final terms = await CreatorRepository(scripted(adapter))
          .searchSuggestions('beauty');

      expect(terms, ['beauty reel creators', 'Beauty Creator']);
    });
  });

  group('live backend', () {
    // Signed in once for the whole group: /auth/login is throttled per IP, and
    // a login per test tripped it with a 429 that looked like a failure.
    late final String token;
    late ApiClient client;

    setUpAll(() async {
      HttpOverrides.global = null;

      final dio = Dio(BaseOptions(baseUrl: 'http://localhost:8000/api/v1'));
      final login = await dio.post<Map<String, dynamic>>(
        '/auth/login',
        data: {
          'identifier': 'aanya.kapoor@gmail.com',
          'password': 'Password123!',
        },
      );
      token = login.data!['data']['tokens']['access_token'] as String;
    });

    setUp(() async {
      // flutter_test installs an HttpOverrides that 400s every request.
      HttpOverrides.global = null;

      final authed = Dio()
        ..interceptors.add(
          InterceptorsWrapper(
            onRequest: (options, handler) {
              options.headers['Authorization'] = 'Bearer $token';
              handler.next(options);
            },
          ),
        );

      client = ApiClient(
        secureStore: SecureStore(),
        connectivity: ConnectivityService(),
        responseCache: cache,
        dio: authed,
      );
    });

    test('C37 the wallet screen gets a real balance from the live API',
        () async {
      final wallet = await WalletRepository(client).wallet();

      expect(wallet.balance, greaterThan(0));
      expect(wallet.currency, 'INR');
      expect(wallet.minWithdrawal, greaterThan(0));
    });

    test('C36 the payout picker gets the live bank accounts', () async {
      final accounts = await WalletRepository(client).bankAccounts();

      expect(accounts, isNotEmpty);
      expect(accounts.first.id, greaterThan(0));
      expect(
        accounts.where((a) => !a.isUpi).every((a) => a.accountHolder.isNotEmpty),
        isTrue,
      );
      expect(
        accounts.where((a) => !a.isUpi).every((a) => a.ifsc.isNotEmpty),
        isTrue,
      );
    });

    test('M49 the type-ahead gets live suggestions, narrowed by the term',
        () async {
      final repo = CreatorRepository(client);

      // A creator matches on handle and headline too ("raisingriaa" matches
      // "aa"), so the assertion is that the term narrows the list rather than
      // that every label contains it literally.
      final typed = await repo.searchSuggestions('aa');
      final landing = await repo.searchSuggestions('');

      expect(typed, isNotEmpty);
      expect(landing, isNotEmpty);
      expect(typed.length, lessThan(landing.length));
    });

    test('M51 the response-time facet narrows the live results', () async {
      final repo = CreatorRepository(client);

      final all = await repo.search(const CreatorFilters(), perPage: 1);
      final within6h = await repo.search(
        const CreatorFilters(responseTime: 'within_6h'),
        perPage: 1,
      );
      final within1h = await repo.search(
        const CreatorFilters(responseTime: 'within_1h'),
        perPage: 1,
      );

      // The term is resolved to hours server-side; it used to be read as an
      // int, become 0, and drop the constraint entirely.
      expect(within6h.pagination.total, lessThan(all.pagination.total));
      expect(within1h.pagination.total, lessThanOrEqualTo(within6h.pagination.total));
    });

    test('M50 the booking tabs narrow the live list', () async {
      final repo = BookingRepository(client);

      final all = await repo.list();
      final completed = await repo.list(status: 'completed');

      // `status` was ignored by the endpoint, so every tab showed every row.
      expect(completed.pagination.total, lessThan(all.pagination.total));
      expect(
        completed.items.every((b) => b.status == BookingStatus.completed),
        isTrue,
      );
    });
  });
}

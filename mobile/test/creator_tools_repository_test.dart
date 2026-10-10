import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/data/repositories/creator_tools_repository.dart';
import 'package:zuste/features/creator/manage/creator_tools_rules.dart';
import 'package:zuste/models/creator.dart';
import 'package:zuste/models/creator_tools.dart';

/// The creator editors' data layer: what each call sends, and how each
/// answer is read.
///
/// Answers are the shapes `CreatorController` produces (see
/// `backend/tests/suites/25_creator_tools.php`, which pins the server side).

/// Always online, without asking the platform.
class _Online extends ConnectivityService {
  @override
  Future<bool> isOnline() async => true;
}

/// Answers by method and path, and records every request.
class _Server implements HttpClientAdapter {
  final requests = <RequestOptions>[];

  /// `METHOD path-suffix` → (status, envelope).
  final routes = <String, (int, Map<String, dynamic>)>{};

  void on(String method, String path, Object? data, {int status = 200, Map<String, dynamic>? meta}) {
    routes['$method $path'] = (
      status,
      {
        'success': status < 400,
        'message': status < 400 ? 'OK' : 'Refused.',
        'data': data,
        'meta': ?meta,
      },
    );
  }

  void fail(String method, String path, int status, String message, {Map<String, dynamic>? errors, String code = 'error'}) {
    routes['$method $path'] = (
      status,
      {'success': false, 'message': message, 'code': code, 'errors': ?errors},
    );
  }

  RequestOptions last(String method, String path) =>
      requests.lastWhere((r) => r.method == method && r.path.endsWith(path));

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);

    final key = routes.keys.firstWhere(
      (k) {
        final space = k.indexOf(' ');
        return k.substring(0, space) == options.method &&
            options.path.endsWith(k.substring(space + 1));
      },
      orElse: () => '',
    );
    final (status, body) = routes[key] ??
        (404, {'success': false, 'message': 'Not found', 'code': 'not_found'});

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

Map<String, dynamic> _packageJson({
  int id = 7,
  bool active = true,
  int revisions = 1,
  List<String> includes = const ['Story reshare'],
}) => {
  'id': id,
  'title': 'Reel + story',
  'description': 'One reel.',
  'platform': 'instagram',
  'deliverable_type': 'reel',
  'quantity': 1,
  'price': 15000,
  'currency': 'INR',
  'price_formatted': '₹15,000',
  'turnaround_days': 5,
  'revisions': revisions,
  'includes': includes,
  'is_active': active,
  'sort_order': 0,
};

void main() {
  late _Server server;
  late CreatorToolsRepository repo;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    server = _Server();
    repo = CreatorToolsRepository(
      ApiClient(
        secureStore: SecureStore(),
        connectivity: _Online(),
        dio: Dio()..httpClientAdapter = server,
      ),
    );
  });

  group('packages', () {
    test('the list reads hidden packages, the lock and the limits', () async {
      server.on('GET', '/me/creator/packages', {
        'packages': [_packageJson(), _packageJson(id: 8, active: false)],
        'locked': true,
        'limits': {
          'min_price': 1000,
          'max_price': 2000000,
          'max_includes': 10,
          'include_max_length': 120,
        },
      });

      final book = await repo.packages();

      expect(book.packages.map((p) => p.id), [7, 8]);
      expect(book.packages.map((p) => p.isActive), [true, false]);
      expect(book.packages.first.package.includes, ['Story reshare']);
      expect(book.locked, isTrue);
      expect(book.limits.minPrice, 1000);
      expect(book.limits.maxPrice, 2000000);
      expect(book.byId(8)?.isActive, isFalse);
      // A hidden package is never the "from" price.
      expect(book.startingPackage?.id, 7);
    });

    test('limits fall back to the server defaults when absent', () {
      final book = OwnPackages.fromJson(const {'packages': []});

      expect(book.limits.minPrice, 1000);
      expect(book.limits.maxIncludes, 10);
      expect(book.limits.includeMaxLength, 120);
    });

    test('create posts every field the API accepts, and no is_active', () async {
      server.on('POST', '/me/creator/packages', {'package': _packageJson()}, status: 201);

      final created = await repo.createPackage(
        const PackageDraft(
          title: '  Reel + story ',
          description: '',
          platform: 'instagram',
          deliverableType: 'reel',
          price: 15000,
          turnaroundDays: 5,
          revisions: 2,
          quantity: 3,
          includes: [' Story reshare ', '', 'Usage rights'],
        ),
      );

      final body = server.last('POST', '/me/creator/packages').data as Map;
      expect(body['title'], 'Reel + story');
      expect(body['platform'], 'instagram');
      expect(body['deliverable_type'], 'reel');
      expect(body['quantity'], 3);
      // Whole rupees go up as an integer.
      expect(body['price'], 15000);
      expect(body['price'], isA<int>());
      expect(body['turnaround_days'], 5);
      expect(body['revisions'], 2);
      expect(body['includes'], ['Story reshare', 'Usage rights']);
      // An empty description is not sent on create (the client prunes it)…
      expect(body.containsKey('description'), isFalse);
      expect(body.containsKey('is_active'), isFalse);
      expect(created.id, 7);
    });

    test('update sends the whole form unpruned, so emptied fields clear', () async {
      server.on('PUT', '/me/creator/packages/7', {'package': _packageJson(includes: const [])});

      await repo.updatePackage(
        7,
        const PackageDraft(
          title: 'Reel + story',
          description: '   ',
          platform: 'instagram',
          deliverableType: 'reel',
          price: 1499.5,
          isActive: false,
        ),
      );

      final body = server.last('PUT', '/me/creator/packages/7').data as Map;
      // …but on update an emptied description is an explicit null, and an
      // emptied list an explicit [] — the API clears exactly what it is sent.
      expect(body.containsKey('description'), isTrue);
      expect(body['description'], isNull);
      expect(body['includes'], isEmpty);
      expect(body['price'], 1499.5);
      expect(body['is_active'], false);
    });

    test('hide and delete hit the package by id', () async {
      server
        ..on('PUT', '/me/creator/packages/7', {'package': _packageJson(active: false)})
        ..on('DELETE', '/me/creator/packages/7', null);

      final hidden = await repo.setPackageActive(7, active: false);
      expect(hidden.isActive, isFalse);
      expect(server.last('PUT', '/me/creator/packages/7').data, {'is_active': false});

      await repo.deletePackage(7);
      expect(server.requests.last.method, 'DELETE');
      expect(server.requests.last.path, endsWith('/me/creator/packages/7'));
    });

    test('a field-keyed refusal reaches the caller intact', () async {
      server.fail(
        'POST',
        '/me/creator/packages',
        422,
        'The lowest price a package can have is ₹1,000.',
        code: 'validation_failed',
        errors: {
          'price': ['The lowest price a package can have is ₹1,000.'],
        },
      );

      final call = repo.createPackage(
        const PackageDraft(title: 'Tiny', platform: 'instagram', deliverableType: 'reel', price: 10),
      );

      await expectLater(
        call,
        throwsA(
          isA<ValidationFailure>().having(
            (f) => f.errorFor('price'),
            'price error',
            'The lowest price a package can have is ₹1,000.',
          ),
        ),
      );
    });

    test('a section lock is a ForbiddenFailure with the reason', () async {
      server.fail('DELETE', '/me/creator/packages/7', 403, 'Pricing is locked by an administrator: review.');

      await expectLater(
        repo.deletePackage(7),
        throwsA(isA<ForbiddenFailure>().having((f) => f.message, 'message', contains('locked'))),
      );
    });
  });

  group('portfolio', () {
    test('the list reads every review state, the lock and the cursor', () async {
      server.on(
        'GET',
        '/me/creator/portfolio',
        [
          {
            'id': 3,
            'media_kind': 'reel',
            'media_url': 'https://cdn.example/a.jpg',
            'aspect_ratio': '4:5',
            'moderation_status': 'pending',
            'is_active': true,
          },
          {
            'id': 2,
            'media_kind': 'post',
            'media_url': 'https://cdn.example/b.jpg',
            'moderation_status': 'rejected',
            'reason_label': 'Copyright or licensing',
            'is_active': true,
          },
        ],
        meta: {
          'pagination': {'total': 31, 'per_page': 30, 'current_page': 1, 'last_page': 2, 'has_more': true},
          'locked': true,
          'max_items': 100,
        },
      );

      final result = await repo.portfolio();
      final query = server.last('GET', '/me/creator/portfolio').queryParameters;

      expect(query['page'], 1);
      expect(query['per_page'], 30);
      expect(result.locked, isTrue);
      // creator.max_portfolio_items (migration 057).
      expect(result.maxItems, 100);
      expect(result.page.hasMore, isTrue);
      expect(result.page.total, 31);
      expect(result.page.items.map((i) => i.review), [PortfolioReview.pending, PortfolioReview.rejected]);
      expect(result.page.items.last.reasonLabel, 'Copyright or licensing');
      expect(result.page.items.first.item.aspectRatio, closeTo(0.8, 0.001));
      expect(result.page.items.first.isPublic, isFalse);
    });

    test('add uploads the picture as `media`, with the details as fields', () async {
      server.on(
        'POST',
        '/me/creator/portfolio',
        {
          'item': {'id': 9, 'media_kind': 'reel', 'moderation_status': 'pending', 'is_active': true},
        },
        status: 201,
      );

      final added = await repo.addPortfolioItem(
        const PortfolioDraft(
          mediaKind: 'reel',
          title: 'Launch reel',
          caption: '',
          externalUrl: 'https://www.instagram.com/reel/abc/',
        ),
        bytes: Uint8List.fromList(const [1, 2, 3]),
        fileName: 'cover.jpg',
      );

      final form = server.last('POST', '/me/creator/portfolio').data as FormData;
      final fields = Map.fromEntries(form.fields);

      expect(form.files.single.key, 'media');
      expect(form.files.single.value.filename, 'cover.jpg');
      expect(fields['media_kind'], 'reel');
      expect(fields['title'], 'Launch reel');
      expect(fields['external_url'], 'https://www.instagram.com/reel/abc/');
      // A blank caption is left out rather than sent empty.
      expect(fields.containsKey('caption'), isFalse);
      expect(added.review, PortfolioReview.pending);
    });

    test('delete hits the item by id', () async {
      server.on('DELETE', '/me/creator/portfolio/9', null);

      await repo.deletePortfolioItem(9);

      expect(server.requests.last.path, endsWith('/me/creator/portfolio/9'));
    });

    test('a live read after a write, with no ceiling set, reads 0', () async {
      server.on('GET', '/me/creator/portfolio', const [], meta: {
        'pagination': {'total': 0, 'per_page': 30, 'current_page': 1, 'last_page': 1, 'has_more': false},
      });

      final result = await repo.portfolio(fresh: true);

      expect(result.maxItems, 0);
      expect(result.page.isEmpty, isTrue);
    });
  });

  group('social accounts', () {
    final accounts = [
      {
        'id': 1,
        'platform': 'instagram',
        'platform_label': 'Instagram',
        'handle': 'asha.makes',
        'profile_url': 'https://instagram.com/asha.makes',
        'followers': 12000,
        'engagement_rate': 3.4,
        'has_metrics': true,
        'icon': 'instagram',
      },
      {
        'id': 2,
        'platform': 'website',
        'platform_label': 'Website',
        'handle': 'asha.example.com',
        'profile_url': 'https://asha.example.com',
        'has_metrics': false,
      },
    ];

    test('the list reads accounts in order, with the ceiling', () async {
      server.on('GET', '/me/creator/social', {
        'social_accounts': accounts,
        'locked': false,
        'max_accounts': 2,
      });

      final book = await repo.socialAccounts();

      expect(book.accounts.map((a) => a.id), [1, 2]);
      expect(book.accounts.first.platformLabel, 'Instagram');
      expect(book.accounts.first.displayHandle, '@asha.makes');
      // A website is shown by its address, never with an @.
      expect(book.accounts.last.displayHandle, 'asha.example.com');
      expect(book.isFull, isTrue);
    });

    test('save sends the draft, and the answer replaces the list', () async {
      server.on('PUT', '/me/creator/social', {'social_accounts': accounts});

      final list = await repo.saveSocialAccount(
        const SocialAccountDraft(
          platform: 'instagram',
          handle: ' asha.makes ',
          profileUrl: 'https://instagram.com/asha.makes',
          followers: 12000,
          engagementRate: 3.4,
        ),
      );

      final body = server.last('PUT', '/me/creator/social').data as Map;
      expect(body, {
        'platform': 'instagram',
        'handle': 'asha.makes',
        'profile_url': 'https://instagram.com/asha.makes',
        'followers': 12000,
        'engagement_rate': 3.4,
      });
      expect(list, hasLength(2));
    });

    test('an edit names the row; a custom link carries its name', () {
      const draft = SocialAccountDraft(
        id: 4,
        platform: 'other',
        handle: 'asha.substack.com',
        profileUrl: 'https://asha.substack.com',
        label: ' My newsletter ',
      );

      expect(draft.toJson(), {
        'id': 4,
        'platform': 'other',
        'handle': 'asha.substack.com',
        'profile_url': 'https://asha.substack.com',
        'label': 'My newsletter',
      });
    });

    test('the editor list says which rows are custom links', () {
      final custom = SocialAccount.fromJson(const {
        'id': 5,
        'platform': 'other',
        'platform_label': 'My newsletter',
        'handle': 'asha.substack.com',
        'has_metrics': false,
        'is_custom': true,
      });
      final network = SocialAccount.fromJson(accounts.first);

      expect(custom.isCustom, isTrue);
      expect(custom.platformLabel, 'My newsletter');
      expect(network.isCustom, isFalse);
    });

    test('unlink and reorder answer with the fresh list', () async {
      server
        ..on('DELETE', '/me/creator/social/2', {'social_accounts': [accounts.first]})
        ..on('PUT', '/me/creator/social/order', {'social_accounts': accounts.reversed.toList()});

      expect(await repo.deleteSocialAccount(2), hasLength(1));

      final reordered = await repo.reorderSocialAccounts([2, 1]);
      expect(server.last('PUT', '/me/creator/social/order').data, {'ids': [2, 1]});
      expect(reordered.map((a) => a.id), [2, 1]);
    });
  });

  group('the plural', () {
    test('revisions read as English', () {
      CreatorPackage withRevisions(int n) =>
          CreatorPackage(id: 1, title: 'x', revisions: n);

      expect(withRevisions(0).revisionsLabel, 'No revisions');
      expect(withRevisions(1).revisionsLabel, '1 revision');
      expect(withRevisions(3).revisionsLabel, '3 revisions');
    });
  });

  group('rules mirror the API', () {
    const limits = PackageLimits(minPrice: 1000, maxPrice: 2000000);

    test('package title: required, 3 to 150', () {
      expect(CreatorToolsRules.packageTitle(''), isNotNull);
      expect(CreatorToolsRules.packageTitle('ab'), isNotNull);
      expect(CreatorToolsRules.packageTitle('abc'), isNull);
      expect(CreatorToolsRules.packageTitle('a' * 151), isNotNull);
    });

    test('price: inside the booking bounds', () {
      expect(CreatorToolsRules.price('', limits), 'Enter the price.');
      expect(CreatorToolsRules.price('abc', limits), isNotNull);
      expect(CreatorToolsRules.price('999', limits), contains('₹1,000'));
      expect(CreatorToolsRules.price('1,000', limits), isNull);
      expect(CreatorToolsRules.price('2000001', limits), contains('₹20,00,000'));
    });

    test('quantity 1–100, delivery 1–180, revisions 0–10', () {
      expect(CreatorToolsRules.quantity('0'), isNotNull);
      expect(CreatorToolsRules.quantity('100'), isNull);
      expect(CreatorToolsRules.turnaroundDays('181'), isNotNull);
      expect(CreatorToolsRules.turnaroundDays('1'), isNull);
      expect(CreatorToolsRules.revisions('0'), isNull);
      expect(CreatorToolsRules.revisions('11'), isNotNull);
    });

    test('included lines are short', () {
      expect(CreatorToolsRules.includeLine('', limits), isNotNull);
      expect(CreatorToolsRules.includeLine('a' * 120, limits), isNull);
      expect(CreatorToolsRules.includeLine('a' * 121, limits), isNotNull);
    });

    test('links are http(s), and handles lose their @', () {
      expect(CreatorToolsRules.link('javascript:alert(1)'), isNotNull);
      expect(CreatorToolsRules.link('instagram.com/x'), isNotNull);
      expect(CreatorToolsRules.link('https://instagram.com/x'), isNull);
      expect(CreatorToolsRules.optionalLink(''), isNull);
      expect(CreatorToolsRules.handle('@'), isNotNull);
      expect(CreatorToolsRules.handle('@asha'), isNull);
    });

    test('figures: whole followers, engagement 0–100', () {
      expect(CreatorToolsRules.followers(''), isNull);
      expect(CreatorToolsRules.followers('12.5'), isNotNull);
      expect(CreatorToolsRules.engagementRate('101'), isNotNull);
      expect(CreatorToolsRules.engagementRate('3.4'), isNull);
    });
  });
}

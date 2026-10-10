import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/api_response.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/creator_tools_repository.dart';
import 'package:zuste/features/campaigns/widgets/campaign_form_parts.dart';
import 'package:zuste/features/creator/manage/my_packages_screen.dart';
import 'package:zuste/features/creator/manage/my_portfolio_screen.dart';
import 'package:zuste/features/creator/manage/package_form_screen.dart';
import 'package:zuste/features/creator/manage/portfolio_form_screen.dart';
import 'package:zuste/features/creator/manage/social_accounts_screen.dart';
import 'package:zuste/features/creator/widgets/profile_insights.dart';
import 'package:zuste/features/creator/widgets/profile_media.dart';
import 'package:zuste/features/creator/widgets/profile_packages.dart';
import 'package:zuste/models/creator.dart';
import 'package:zuste/models/creator_tools.dart';
import 'package:zuste/models/taxonomy.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/creator_tools_provider.dart';
import 'package:zuste/providers/opportunity_provider.dart';
import 'package:zuste/providers/taxonomy_provider.dart';
import 'package:zuste/router/routes.dart';
import 'package:zuste/shared/widgets/z_button.dart';
import 'package:zuste/shared/widgets/z_text_field.dart';

/// The creator editors — packages, portfolio, social accounts — driven
/// through their real screens and router paths against an in-memory
/// repository.
///
/// The server side of every rule here is pinned by
/// `backend/tests/suites/25_creator_tools.php`; these prove the screens send
/// what the API expects, refuse what it would refuse, and put its refusals
/// where the creator can read them.

class _Online extends ConnectivityService {
  @override
  Future<bool> isOnline() async => true;
}

ApiClient _unusedClient() => ApiClient(
  secureStore: SecureStore(),
  connectivity: _Online(),
  dio: Dio(),
);

/// The API, in memory: what the screens read and every write they make.
class _FakeTools extends CreatorToolsRepository {
  _FakeTools() : super(_unusedClient());

  OwnPackages packageBook = const OwnPackages();
  List<OwnPortfolioItem> portfolioItems = [];
  bool portfolioLocked = false;
  int portfolioMax = 0;
  SocialAccountsBook social = const SocialAccountsBook();

  /// Reads after a write ask for a live answer (`fresh`); counted so a test
  /// can see the editor reloaded.
  int freshReads = 0;

  /// The reload after a write fails, as when the connection drops.
  bool failFreshReads = false;

  void _freshRead(bool fresh) {
    if (!fresh) return;
    freshReads++;
    if (failFreshReads) throw const OfflineFailure();
  }

  final created = <PackageDraft>[];
  final updated = <(int, PackageDraft)>[];
  final toggled = <(int, bool)>[];
  final deletedPackages = <int>[];
  final added = <(PortfolioDraft, String)>[];
  final deletedItems = <int>[];
  final savedAccounts = <SocialAccountDraft>[];
  final unlinked = <int>[];

  /// Thrown by the next write, once.
  ApiFailure? failNext;

  int _nextId = 100;

  void _maybeFail() {
    final failure = failNext;
    if (failure != null) {
      failNext = null;
      throw failure;
    }
  }

  OwnPackage _fromDraft(int id, PackageDraft draft, {bool active = true}) => OwnPackage(
    isActive: active,
    package: CreatorPackage(
      id: id,
      title: draft.title.trim(),
      description: draft.description.trim(),
      platform: draft.platform,
      deliverableType: draft.deliverableType,
      quantity: draft.quantity,
      price: draft.price,
      priceFormatted: '₹${draft.price.round()}',
      turnaroundDays: draft.turnaroundDays,
      revisions: draft.revisions,
      includes: draft.includes,
    ),
  );

  void _setPackages(List<OwnPackage> next) => packageBook = OwnPackages(
    packages: next,
    locked: packageBook.locked,
    limits: packageBook.limits,
  );

  @override
  Future<OwnPackages> packages({bool fresh = false}) async {
    _freshRead(fresh);
    return packageBook;
  }

  @override
  Future<OwnPackage> createPackage(PackageDraft draft) async {
    _maybeFail();
    created.add(draft);
    final package = _fromDraft(_nextId++, draft);
    _setPackages([...packageBook.packages, package]);
    return package;
  }

  @override
  Future<OwnPackage> updatePackage(int id, PackageDraft draft) async {
    _maybeFail();
    updated.add((id, draft));
    final package = _fromDraft(id, draft, active: draft.isActive ?? true);
    _setPackages([for (final p in packageBook.packages) p.id == id ? package : p]);
    return package;
  }

  @override
  Future<OwnPackage> setPackageActive(int id, {required bool active}) async {
    _maybeFail();
    toggled.add((id, active));
    final current = packageBook.byId(id)!;
    final package = OwnPackage(package: current.package, isActive: active);
    _setPackages([for (final p in packageBook.packages) p.id == id ? package : p]);
    return package;
  }

  @override
  Future<void> deletePackage(int id) async {
    _maybeFail();
    deletedPackages.add(id);
    _setPackages(packageBook.packages.where((p) => p.id != id).toList());
  }

  @override
  Future<OwnPortfolioPage> portfolio({
    int page = 1,
    int perPage = 30,
    bool fresh = false,
  }) async {
    _freshRead(fresh);
    return (
      page: Paginated(
        items: portfolioItems,
        pagination: Pagination.singlePage(portfolioItems.length),
      ),
      locked: portfolioLocked,
      maxItems: portfolioMax,
    );
  }

  @override
  Future<OwnPortfolioItem> addPortfolioItem(
    PortfolioDraft draft, {
    required Uint8List bytes,
    required String fileName,
  }) async {
    _maybeFail();
    added.add((draft, fileName));
    final item = OwnPortfolioItem(
      review: PortfolioReview.pending,
      item: PortfolioItem(
        id: _nextId++,
        mediaKind: draft.mediaKind,
        title: draft.title,
        externalUrl: draft.externalUrl.isEmpty ? null : draft.externalUrl,
      ),
    );
    portfolioItems = [item, ...portfolioItems];
    return item;
  }

  @override
  Future<void> deletePortfolioItem(int id) async {
    _maybeFail();
    deletedItems.add(id);
    portfolioItems = portfolioItems.where((i) => i.id != id).toList();
  }

  @override
  Future<SocialAccountsBook> socialAccounts() async => social;

  @override
  Future<List<SocialAccount>> saveSocialAccount(SocialAccountDraft draft) async {
    _maybeFail();
    savedAccounts.add(draft);
    final account = SocialAccount(
      id: draft.id ?? _nextId++,
      platform: draft.platform,
      handle: draft.handle,
      profileUrl: draft.profileUrl,
      label: draft.label,
      followers: draft.followers ?? 0,
      engagementRate: draft.engagementRate ?? 0,
      hasMetrics: draft.platform != 'website' && draft.platform != 'other',
    );
    final next = draft.id == null
        ? [...social.accounts, account]
        : [for (final a in social.accounts) a.id == draft.id ? account : a];
    social = social.withAccounts(next);
    return next;
  }

  @override
  Future<List<SocialAccount>> deleteSocialAccount(int id) async {
    _maybeFail();
    unlinked.add(id);
    social = social.withAccounts(social.accounts.where((a) => a.id != id).toList());
    return social.accounts;
  }

  final reorders = <List<int>>[];

  @override
  Future<List<SocialAccount>> reorderSocialAccounts(List<int> ids) async {
    _maybeFail();
    reorders.add(ids);
    final byId = {for (final a in social.accounts) a.id: a};
    social = social.withAccounts([for (final id in ids) byId[id]!]);
    return social.accounts;
  }
}

const _taxonomies = TaxonomyBundle(
  version: 'test',
  taxonomies: {
    Tax.platform: [
      TaxonomyTerm(value: 'instagram', label: 'Instagram', isDefault: true),
      TaxonomyTerm(value: 'youtube', label: 'YouTube'),
    ],
    Tax.deliverableType: [
      TaxonomyTerm(value: 'post', label: 'Feed post', isDefault: true),
      TaxonomyTerm(value: 'reel', label: 'Reel'),
    ],
    Tax.portfolioKind: [
      TaxonomyTerm(value: 'post', label: 'Posts', isDefault: true),
      TaxonomyTerm(value: 'reel', label: 'Reels'),
    ],
    Tax.socialPlatform: [
      TaxonomyTerm(
        value: 'instagram',
        label: 'Instagram',
        icon: 'instagram',
        metadata: {'url': 'https://instagram.com/{handle}', 'metrics': true},
      ),
      TaxonomyTerm(
        value: 'website',
        label: 'Website',
        icon: 'external',
        metadata: {'url': '{handle}', 'metrics': false},
      ),
      TaxonomyTerm(
        value: 'other',
        label: 'Other link',
        icon: 'link',
        metadata: {'url': '{handle}', 'metrics': false, 'custom_label': true},
      ),
      TaxonomyTerm(
        value: 'upload',
        label: 'Uploaded directly',
        icon: 'upload',
        metadata: {'url': '{handle}', 'metrics': false},
      ),
    ],
  },
);

/// A 1×1 PNG, so the picked-picture preview decodes.
final _png = Uint8List.fromList(const [
  0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, 0x00, 0x00, 0x00, 0x0D,
  0x49, 0x48, 0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01,
  0x08, 0x06, 0x00, 0x00, 0x00, 0x1F, 0x15, 0xC4, 0x89, 0x00, 0x00, 0x00,
  0x0D, 0x49, 0x44, 0x41, 0x54, 0x78, 0xDA, 0x63, 0x64, 0xF8, 0xCF, 0x50,
  0x0F, 0x00, 0x03, 0x86, 0x01, 0x80, 0x5A, 0x34, 0x7D, 0x6B, 0x00, 0x00,
  0x00, 0x00, 0x49, 0x45, 0x4E, 0x44, 0xAE, 0x42, 0x60, 0x82,
]);

OwnPackage _package(int id, String title, {bool active = true, int revisions = 1, double price = 15000}) =>
    OwnPackage(
      isActive: active,
      package: CreatorPackage(
        id: id,
        title: title,
        platform: 'instagram',
        deliverableType: 'reel',
        price: price,
        priceFormatted: '₹${price.round()}',
        turnaroundDays: 5,
        revisions: revisions,
      ),
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;
  late _FakeTools fake;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    cache = await KeyValueCache.open();
    fake = _FakeTools();
  });

  Future<void> settle(WidgetTester tester) async {
    for (var i = 0; i < 6; i++) {
      await tester.pump(const Duration(milliseconds: 120));
    }
  }

  Future<void> pumpAt(
    WidgetTester tester,
    String location, {
    bool creator = true,
    Future<PickedImage?> Function()? picker,
    TaxonomyBundle taxonomies = _taxonomies,
    Widget? host,
  }) async {
    tester.view.physicalSize = const Size(1170, 9000);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    Widget page(String text) => Scaffold(body: Center(child: Text(text)));

    final router = GoRouter(
      initialLocation: location,
      routes: [
        GoRoute(
          path: Routes.myPackages,
          builder: (_, _) => const MyPackagesScreen(),
          routes: [
            GoRoute(path: 'new', builder: (_, _) => const PackageFormScreen()),
            GoRoute(
              path: ':id',
              builder: (_, state) => PackageFormScreen(
                packageId: int.tryParse(state.pathParameters['id'] ?? '') ?? -1,
              ),
            ),
          ],
        ),
        GoRoute(
          path: Routes.myPortfolio,
          builder: (_, _) => const MyPortfolioScreen(),
          routes: [
            GoRoute(path: 'new', builder: (_, _) => const PortfolioFormScreen()),
          ],
        ),
        GoRoute(
          path: Routes.socialAccounts,
          builder: (_, _) => const SocialAccountsScreen(),
        ),
        GoRoute(path: Routes.search, builder: (_, _) => page('SEARCH')),
        GoRoute(path: Routes.support, builder: (_, _) => page('SUPPORT')),
        // Where a test puts a widget of its own, e.g. a profile tab.
        GoRoute(
          path: '/host',
          builder: (_, _) => Scaffold(
            body: SingleChildScrollView(child: host ?? const SizedBox()),
          ),
        ),
      ],
    );
    addTearDown(router.dispose);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          keyValueCacheProvider.overrideWithValue(cache),
          creatorToolsRepositoryProvider.overrideWithValue(fake),
          isCreatorSessionProvider.overrideWithValue(creator),
          taxonomiesProvider.overrideWithValue(taxonomies),
          if (picker != null) portfolioImagePickerProvider.overrideWithValue(picker),
        ],
        child: MaterialApp.router(theme: AppTheme.light, routerConfig: router),
      ),
    );
    await settle(tester);
  }

  /// The text input under a [ZTextField] labelled [label].
  Finder field(String label) => find.descendant(
    of: find.widgetWithText(ZTextField, label),
    matching: find.byType(TextField),
  );

  Future<void> tapText(WidgetTester tester, String text) async {
    await tester.ensureVisible(find.text(text).last);
    await tester.tap(find.text(text).last);
    await settle(tester);
  }

  // ===================================================================
  group('My packages', () {
    testWidgets('an empty list says what a package is and offers one', (tester) async {
      await pumpAt(tester, Routes.myPackages);

      expect(tester.takeException(), isNull);
      expect(find.text('No packages yet'), findsOneWidget);

      await tapText(tester, 'Add a package');
      expect(find.text('New package'), findsOneWidget);
    });

    testWidgets('lists hidden packages beside live ones, in plain English', (tester) async {
      fake.packageBook = OwnPackages(
        packages: [
          _package(1, 'One reel'),
          _package(2, 'Story set', active: false, revisions: 0),
          _package(3, 'Two reels', revisions: 3, price: 25000),
        ],
      );

      await pumpAt(tester, Routes.myPackages);

      expect(find.text('One reel'), findsOneWidget);
      expect(find.text('Story set'), findsOneWidget);
      expect(find.text('Live'), findsNWidgets(2));
      expect(find.text('Hidden'), findsOneWidget);
      expect(find.text('1 revision'), findsOneWidget);
      expect(find.text('No revisions'), findsOneWidget);
      expect(find.text('3 revisions'), findsOneWidget);
      expect(find.text('1 revisions'), findsNothing);
      // The cheapest LIVE package is the profile's starting price.
      expect(find.text('Starting price'), findsOneWidget);
    });

    testWidgets('a brand is told the screen is for creators', (tester) async {
      await pumpAt(tester, Routes.myPackages, creator: false);

      expect(find.text('This is for creator accounts'), findsOneWidget);
      expect(find.text('No packages yet'), findsNothing);
    });

    testWidgets('delete asks first, and keeps the package on "Keep it"', (tester) async {
      fake.packageBook = OwnPackages(packages: [_package(1, 'One reel')]);
      await pumpAt(tester, Routes.myPackages);

      await tester.tap(find.byTooltip('Options for One reel'));
      await settle(tester);
      await tapText(tester, 'Delete');

      expect(find.text('Delete "One reel"?'), findsOneWidget);
      await tapText(tester, 'Keep it');
      expect(fake.deletedPackages, isEmpty);
      expect(find.text('One reel'), findsOneWidget);

      await tester.tap(find.byTooltip('Options for One reel'));
      await settle(tester);
      await tapText(tester, 'Delete');
      await tapText(tester, 'Delete');

      expect(fake.deletedPackages, [1]);
      expect(find.text('One reel'), findsNothing);
      expect(find.text('Package deleted.'), findsOneWidget);
    });

    testWidgets('hiding a package flips it in place', (tester) async {
      fake.packageBook = OwnPackages(packages: [_package(1, 'One reel')]);
      await pumpAt(tester, Routes.myPackages);

      await tester.tap(find.byTooltip('Options for One reel'));
      await settle(tester);
      await tapText(tester, 'Hide from profile');

      expect(fake.toggled, [(1, false)]);
      expect(find.text('Hidden'), findsOneWidget);
    });

    testWidgets('a refused delete is reported, and the package stays', (tester) async {
      fake.packageBook = OwnPackages(packages: [_package(1, 'One reel')]);
      fake.failNext = const ForbiddenFailure(
        message: 'Pricing is locked by an administrator: under review.',
      );
      await pumpAt(tester, Routes.myPackages);

      await tester.tap(find.byTooltip('Options for One reel'));
      await settle(tester);
      await tapText(tester, 'Delete');
      await tapText(tester, 'Delete');

      expect(find.text('Pricing is locked by an administrator: under review.'), findsOneWidget);
      expect(find.text('One reel'), findsOneWidget);
    });
  });

  // ===================================================================
  group('Package form', () {
    testWidgets('refuses what the API would, before sending anything', (tester) async {
      await pumpAt(tester, Routes.newPackage);

      await tapText(tester, 'Add package');

      expect(find.text('Give the package a title.'), findsOneWidget);
      expect(find.text('Enter the price.'), findsOneWidget);
      expect(find.text('Choose a platform.'), findsOneWidget);
      expect(find.text('Choose the type of content.'), findsOneWidget);

      await tester.enterText(field('Title'), 'ab');
      await tester.enterText(field('Price (₹)'), '500');
      await settle(tester);

      expect(find.text('Use at least 3 characters.'), findsOneWidget);
      expect(find.text('The lowest price a package can have is ₹1,000.'), findsOneWidget);
      expect(fake.created, isEmpty);
    });

    testWidgets('adds a package and returns to the list', (tester) async {
      await pumpAt(tester, Routes.myPackages);
      await tapText(tester, 'Add a package');

      await tester.enterText(field('Title'), 'One reel with a story');
      await tapText(tester, 'Instagram');
      await tapText(tester, 'Reel');
      await tester.enterText(field('Price (₹)'), '15000');
      await tester.enterText(field('Revisions'), '2');
      await tester.enterText(field('Add an item'), 'Story reshare');
      await tester.tap(find.byTooltip('Add to the list'));
      await settle(tester);

      await tapText(tester, 'Add package');

      expect(fake.created, hasLength(1));
      final draft = fake.created.single;
      expect(draft.title, 'One reel with a story');
      expect(draft.platform, 'instagram');
      expect(draft.deliverableType, 'reel');
      expect(draft.price, 15000);
      expect(draft.revisions, 2);
      expect(draft.quantity, 1);
      expect(draft.turnaroundDays, 7);
      expect(draft.includes, ['Story reshare']);
      expect(draft.isActive, isNull);

      // Back on the list, with the new package in it.
      expect(find.text('My packages'), findsOneWidget);
      expect(find.text('One reel with a story'), findsOneWidget);
      expect(find.text('Package added.'), findsOneWidget);
    });

    testWidgets("puts the server's field message beside the field", (tester) async {
      fake.failNext = const ValidationFailure(
        message: 'The lowest price a package can have is ₹2,000.',
        errors: {
          'price': ['The lowest price a package can have is ₹2,000.'],
        },
      );
      await pumpAt(tester, Routes.newPackage);

      await tester.enterText(field('Title'), 'One reel');
      await tapText(tester, 'Instagram');
      await tapText(tester, 'Reel');
      await tester.enterText(field('Price (₹)'), '1500');
      await tapText(tester, 'Add package');

      // Still on the form, the message under the price.
      expect(find.text('New package'), findsOneWidget);
      final price = tester.widget<ZTextField>(find.widgetWithText(ZTextField, 'Price (₹)'));
      expect(price.errorText, 'The lowest price a package can have is ₹2,000.');
    });

    testWidgets('a refusal with no field goes above the form', (tester) async {
      fake.failNext = const ForbiddenFailure(
        message: 'Pricing is locked by an administrator: under review.',
      );
      await pumpAt(tester, Routes.newPackage);

      await tester.enterText(field('Title'), 'One reel');
      await tapText(tester, 'Instagram');
      await tapText(tester, 'Reel');
      await tester.enterText(field('Price (₹)'), '1500');
      await tapText(tester, 'Add package');

      expect(
        find.text('Pricing is locked by an administrator: under review.'),
        findsWidgets,
      );
      expect(find.text('New package'), findsOneWidget);
    });

    testWidgets('edits a package from what is stored, including hiding it', (tester) async {
      fake.packageBook = OwnPackages(packages: [_package(4, 'One reel')]);
      await pumpAt(tester, Routes.myPackages);

      await tapText(tester, 'One reel');
      expect(find.text('Edit package'), findsOneWidget);
      expect(find.text('One reel'), findsOneWidget);

      await tester.enterText(field('Title'), 'One reel, edited');
      await tester.tap(find.byType(Switch));
      await settle(tester);
      await tapText(tester, 'Save changes');

      expect(fake.updated, hasLength(1));
      final (id, draft) = fake.updated.single;
      expect(id, 4);
      expect(draft.title, 'One reel, edited');
      expect(draft.price, 15000);
      expect(draft.isActive, isFalse);
      expect(find.text('Hidden'), findsOneWidget);
    });

    testWidgets('a package that no longer exists says so', (tester) async {
      await pumpAt(tester, Routes.editPackage(99));

      expect(find.text('This package is no longer here'), findsOneWidget);
    });

    testWidgets("a typed \"what's included\" line is saved, not dropped", (tester) async {
      await pumpAt(tester, Routes.newPackage);

      await tester.enterText(field('Title'), 'One reel');
      await tapText(tester, 'Instagram');
      await tapText(tester, 'Reel');
      await tester.enterText(field('Price (₹)'), '15000');
      await tester.enterText(field('Add an item'), 'Story reshare');
      await tester.tap(find.byTooltip('Add to the list'));
      await settle(tester);
      // Typed, but neither + nor Enter pressed.
      await tester.enterText(field('Add an item'), '  Usage rights for 30 days ');
      await tapText(tester, 'Add package');

      expect(fake.created.single.includes, ['Story reshare', 'Usage rights for 30 days']);
    });

    testWidgets('after a save the list is read again, live', (tester) async {
      fake.packageBook = OwnPackages(packages: [_package(1, 'One reel')]);
      await pumpAt(tester, Routes.myPackages);

      await tester.tap(find.byTooltip('Options for One reel'));
      await settle(tester);
      await tapText(tester, 'Hide from profile');

      expect(fake.freshReads, 1);
    });
  });

  // ===================================================================
  group('Portfolio', () {
    testWidgets('an empty portfolio explains what to add', (tester) async {
      await pumpAt(tester, Routes.myPortfolio);

      expect(find.text('Your portfolio is empty'), findsOneWidget);
    });

    testWidgets('shows where each item stands with the team', (tester) async {
      fake.portfolioItems = [
        const OwnPortfolioItem(
          review: PortfolioReview.pending,
          item: PortfolioItem(id: 1, mediaKind: 'reel', title: 'Launch'),
        ),
        const OwnPortfolioItem(
          review: PortfolioReview.rejected,
          reasonLabel: 'Copyright or licensing',
          item: PortfolioItem(id: 2, mediaKind: 'post', title: 'Old shoot'),
        ),
        const OwnPortfolioItem(item: PortfolioItem(id: 3, mediaKind: 'post', title: 'Live one')),
      ];

      await pumpAt(tester, Routes.myPortfolio);

      expect(find.text('In review'), findsOneWidget);
      expect(find.text('Not approved'), findsOneWidget);
      expect(find.text('3 items'), findsOneWidget);
      expect(find.textContaining('1 item is waiting for our team'), findsOneWidget);

      // The reason travels with a turned-down item.
      await tester.tap(find.bySemanticsLabel(RegExp('Old shoot')));
      await settle(tester);
      expect(find.textContaining('Copyright or licensing'), findsOneWidget);
    });

    testWidgets('the form wants a picture and a real link', (tester) async {
      await pumpAt(tester, Routes.newPortfolioItem, picker: () async => null);

      await tester.enterText(field('Link to the original (optional)'), 'instagram.com/p/1');
      await tapText(tester, 'Add to portfolio');

      expect(find.text('Choose a picture.'), findsOneWidget);
      expect(find.text('Enter a full link, starting with https://'), findsOneWidget);
      expect(fake.added, isEmpty);
    });

    testWidgets('adds a reel with its cover and says it is in review', (tester) async {
      await pumpAt(
        tester,
        Routes.myPortfolio,
        picker: () async => (bytes: _png, name: 'cover.png'),
      );
      await tapText(tester, 'Add to portfolio');

      await tapText(tester, 'Reels');
      expect(find.text('Cover picture'), findsOneWidget);

      await tester.tap(find.bySemanticsLabel('Choose a picture'));
      await settle(tester);
      expect(find.text('Choose another'), findsOneWidget);

      await tester.enterText(
        field('Link to the original (recommended)'),
        'https://www.instagram.com/reel/abc/',
      );
      await tester.enterText(field('Title (optional)'), 'Launch reel');
      await tapText(tester, 'Add to portfolio');

      expect(fake.added, hasLength(1));
      final (draft, fileName) = fake.added.single;
      expect(draft.mediaKind, 'reel');
      expect(draft.externalUrl, 'https://www.instagram.com/reel/abc/');
      expect(draft.title, 'Launch reel');
      expect(fileName, 'cover.png');

      expect(find.text('Portfolio'), findsOneWidget);
      expect(find.text('In review'), findsOneWidget);
      expect(
        find.text('Added. It appears on your profile once our team has reviewed it.'),
        findsOneWidget,
      );
    });

    testWidgets("shows the server's refusal on the form", (tester) async {
      fake.failNext = const ValidationFailure(
        message: 'Unsupported file type. Allowed: jpg, jpeg, png, webp.',
      );
      await pumpAt(
        tester,
        Routes.newPortfolioItem,
        picker: () async => (bytes: _png, name: 'cover.png'),
      );

      await tester.tap(find.bySemanticsLabel('Choose a picture'));
      await settle(tester);
      await tapText(tester, 'Add to portfolio');

      expect(find.text('Unsupported file type. Allowed: jpg, jpeg, png, webp.'), findsWidgets);
      expect(find.text('Add to portfolio'), findsWidgets);
    });

    testWidgets('remove goes through the details and a confirmation', (tester) async {
      fake.portfolioItems = [
        const OwnPortfolioItem(item: PortfolioItem(id: 5, mediaKind: 'post', title: 'Shoot')),
      ];
      await pumpAt(tester, Routes.myPortfolio);

      await tester.tap(find.bySemanticsLabel(RegExp('Shoot')));
      await settle(tester);
      expect(find.text('Live on your profile.'), findsOneWidget);

      await tapText(tester, 'Remove from portfolio');
      expect(find.text('Remove this from your portfolio?'), findsOneWidget);
      await tapText(tester, 'Remove');

      expect(fake.deletedItems, [5]);
      expect(find.text('Removed from your portfolio.'), findsOneWidget);
      expect(find.text('Your portfolio is empty'), findsOneWidget);
    });

    testWidgets('the count follows the first add', (tester) async {
      await pumpAt(
        tester,
        Routes.myPortfolio,
        picker: () async => (bytes: _png, name: 'first.png'),
      );
      // The empty state's button.
      await tapText(tester, 'Add to portfolio');
      await tester.tap(find.bySemanticsLabel('Choose a picture'));
      await settle(tester);
      await tapText(tester, 'Add to portfolio');

      expect(fake.added, hasLength(1));
      // It read "0 items" until the next pull-to-refresh.
      expect(find.text('1 item'), findsOneWidget);
      // And page 1 was read again, so "Show more" pages from the right place.
      expect(fake.freshReads, 1);
    });

    testWidgets('the count follows a remove, even when the reload fails', (tester) async {
      fake.portfolioItems = [
        for (var id = 1; id <= 3; id++)
          OwnPortfolioItem(item: PortfolioItem(id: id, mediaKind: 'post', title: 'Shoot $id')),
      ];
      await pumpAt(tester, Routes.myPortfolio);
      expect(find.text('3 items'), findsOneWidget);

      fake.failFreshReads = true;
      await tester.tap(find.bySemanticsLabel(RegExp('Shoot 2')));
      await settle(tester);
      await tapText(tester, 'Remove from portfolio');
      await tapText(tester, 'Remove');

      expect(fake.deletedItems, [2]);
      expect(fake.freshReads, 1);
      // The removal is on the server; a failed reload does not undo it.
      expect(find.text('2 items'), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp('Shoot 2')), findsNothing);
    });

    testWidgets('at the ceiling, adding is switched off and says why', (tester) async {
      fake.portfolioMax = 2;
      fake.portfolioItems = [
        const OwnPortfolioItem(item: PortfolioItem(id: 1, mediaKind: 'post', title: 'One')),
        const OwnPortfolioItem(item: PortfolioItem(id: 2, mediaKind: 'post', title: 'Two')),
      ];
      await pumpAt(tester, Routes.myPortfolio);

      expect(find.textContaining('holds up to 2 items'), findsOneWidget);
      final add = tester.widget<ZButton>(find.widgetWithText(ZButton, 'Add to portfolio'));
      expect(add.onPressed, isNull);
    });
  });

  // ===================================================================
  group('Social accounts', () {
    const insta = SocialAccount(
      id: 1,
      platform: 'instagram',
      handle: 'asha.makes',
      label: 'Instagram',
      profileUrl: 'https://instagram.com/asha.makes',
      followers: 12000,
      engagementRate: 3.4,
    );
    const site = SocialAccount(
      id: 2,
      platform: 'website',
      handle: 'asha.example.com',
      label: 'Website',
      profileUrl: 'https://asha.example.com',
      hasMetrics: false,
    );

    testWidgets('an empty list offers to link one', (tester) async {
      await pumpAt(tester, Routes.socialAccounts);

      expect(find.text('No accounts linked'), findsOneWidget);
      expect(find.textContaining('does not fetch them'), findsOneWidget);
    });

    testWidgets('figures are labelled as the creator\'s own', (tester) async {
      fake.social = const SocialAccountsBook(accounts: [insta, site]);
      await pumpAt(tester, Routes.socialAccounts);

      expect(find.text('Instagram'), findsOneWidget);
      expect(find.text('@asha.makes'), findsOneWidget);
      expect(find.text('12K followers · 3.4% engagement'), findsOneWidget);
      // One network with figures, one link without.
      expect(find.text('Self-reported'), findsOneWidget);
      expect(find.text('asha.example.com'), findsOneWidget);
    });

    testWidgets('links Instagram, filling the link from the handle', (tester) async {
      await pumpAt(tester, Routes.socialAccounts);
      await tapText(tester, 'Link an account');

      // A direct upload is not a network anyone links.
      expect(find.text('Uploaded directly'), findsNothing);

      await tapText(tester, 'Instagram');
      await tester.enterText(field('Handle'), '@asha.makes');
      await settle(tester);

      final link = tester.widget<TextField>(field('Profile link'));
      expect(link.controller!.text, 'https://instagram.com/asha.makes');

      await tester.enterText(field('Followers'), '12000');
      await tapText(tester, 'Link account');

      expect(fake.savedAccounts, hasLength(1));
      final draft = fake.savedAccounts.single;
      expect(draft.platform, 'instagram');
      expect(draft.handle, 'asha.makes');
      expect(draft.profileUrl, 'https://instagram.com/asha.makes');
      expect(draft.followers, 12000);
      expect(draft.engagementRate, isNull);

      expect(find.text('Account linked.'), findsOneWidget);
      expect(find.text('@asha.makes'), findsOneWidget);
    });

    testWidgets('a custom link needs a name and a real address', (tester) async {
      await pumpAt(tester, Routes.socialAccounts);
      await tapText(tester, 'Link an account');
      await tapText(tester, 'Other link');

      await tester.enterText(field('Link'), 'not a link');
      await tapText(tester, 'Link account');

      expect(find.text('Name this link, e.g. "My newsletter".'), findsOneWidget);
      expect(find.text('Enter a full link, starting with https://'), findsOneWidget);
      expect(fake.savedAccounts, isEmpty);

      await tester.enterText(field('Name'), 'My newsletter');
      await tester.enterText(field('Link'), 'https://www.asha.substack.com/');
      await tapText(tester, 'Link account');

      final draft = fake.savedAccounts.single;
      expect(draft.label, 'My newsletter');
      // A link-only entry is stored by its address.
      expect(draft.handle, 'asha.substack.com');
      expect(draft.followers, isNull);
    });

    testWidgets("keeps the sheet open with the server's message", (tester) async {
      fake.failNext = const ValidationFailure(
        message: 'That account is already linked.',
        errors: {
          'handle': ['That account is already linked.'],
        },
      );
      await pumpAt(tester, Routes.socialAccounts);
      await tapText(tester, 'Link an account');
      await tapText(tester, 'Instagram');
      await tester.enterText(field('Handle'), 'asha.makes');
      await tapText(tester, 'Link account');

      final handle = tester.widget<ZTextField>(find.widgetWithText(ZTextField, 'Handle'));
      expect(handle.errorText, 'That account is already linked.');
      expect(find.text('Link account'), findsOneWidget);
    });

    testWidgets('editing keeps the network and sends the row id', (tester) async {
      fake.social = const SocialAccountsBook(accounts: [insta]);
      await pumpAt(tester, Routes.socialAccounts);

      await tapText(tester, '@asha.makes');
      expect(find.text('Edit Instagram'), findsOneWidget);
      expect(find.textContaining('To move this to another network'), findsOneWidget);

      await tester.enterText(field('Handle'), 'asha.creates');
      await tester.enterText(field('Profile link'), 'https://instagram.com/asha.creates');
      await tapText(tester, 'Save changes');

      final draft = fake.savedAccounts.single;
      expect(draft.id, 1);
      expect(draft.platform, 'instagram');
      expect(draft.handle, 'asha.creates');
      expect(find.text('Account updated.'), findsOneWidget);
    });

    testWidgets('dragging a row sends the new order', (tester) async {
      fake.social = const SocialAccountsBook(accounts: [insta, site]);
      await pumpAt(tester, Routes.socialAccounts);

      final handle = find.bySemanticsLabel('Drag to reorder Instagram');
      expect(handle, findsOneWidget);

      // The handle starts a drag at once; no long press is needed.
      final gesture = await tester.startGesture(tester.getCenter(handle));
      await tester.pump(const Duration(milliseconds: 50));
      for (var i = 0; i < 10; i++) {
        await gesture.moveBy(const Offset(0, 20));
        await tester.pump(const Duration(milliseconds: 16));
      }
      await gesture.up();
      await settle(tester);

      expect(fake.reorders, [
        [2, 1],
      ]);
      // The website now leads.
      expect(
        tester.getTopLeft(find.text('asha.example.com')).dy,
        lessThan(tester.getTopLeft(find.text('@asha.makes')).dy),
      );
    });

    testWidgets('unlink asks first', (tester) async {
      fake.social = const SocialAccountsBook(accounts: [insta, site]);
      await pumpAt(tester, Routes.socialAccounts);

      await tester.tap(find.byTooltip('Unlink Website'));
      await settle(tester);
      expect(find.text('Unlink Website?'), findsOneWidget);
      await tapText(tester, 'Keep it');
      expect(fake.unlinked, isEmpty);

      await tester.tap(find.byTooltip('Unlink Website'));
      await settle(tester);
      await tapText(tester, 'Unlink');

      expect(fake.unlinked, [2]);
      expect(find.text('asha.example.com'), findsNothing);
      expect(find.text('Account unlinked.'), findsOneWidget);
    });

    testWidgets('a network already linked is not offered again', (tester) async {
      fake.social = const SocialAccountsBook(accounts: [insta, site]);
      await pumpAt(tester, Routes.socialAccounts);
      await tapText(tester, 'Link an account');

      Finder chip(String label) => find.descendant(
        of: find.byType(ChoiceChips),
        matching: find.text(label),
      );

      // Linking it again would replace the linked one, figures and all.
      expect(chip('Instagram'), findsNothing);
      expect(find.textContaining('Already linked: Instagram'), findsOneWidget);
      // A link-only entry may repeat.
      expect(chip('Website'), findsOneWidget);
      expect(chip('Other link'), findsOneWidget);
    });

    testWidgets('editing before the networks have loaded keeps the handle and figures', (tester) async {
      fake.social = const SocialAccountsBook(accounts: [insta]);
      await pumpAt(
        tester,
        Routes.socialAccounts,
        taxonomies: const TaxonomyBundle(version: 'empty', taxonomies: {}),
      );

      await tapText(tester, '@asha.makes');
      // Read as link-only, the handle field vanished and saving wiped the
      // follower figures.
      expect(field('Handle'), findsOneWidget);

      await tester.enterText(field('Followers'), '13000');
      await tapText(tester, 'Save changes');

      final draft = fake.savedAccounts.single;
      expect(draft.id, 1);
      expect(draft.handle, 'asha.makes');
      expect(draft.profileUrl, 'https://instagram.com/asha.makes');
      expect(draft.followers, 13000);
      expect(draft.engagementRate, 3.4);
    });

    testWidgets('a custom link keeps its name field when the networks have not loaded', (tester) async {
      fake.social = const SocialAccountsBook(
        accounts: [
          SocialAccount(
            id: 3,
            platform: 'other',
            handle: 'asha.substack.com',
            label: 'My newsletter',
            profileUrl: 'https://asha.substack.com',
            hasMetrics: false,
            isCustom: true,
          ),
        ],
      );
      await pumpAt(
        tester,
        Routes.socialAccounts,
        taxonomies: const TaxonomyBundle(version: 'empty', taxonomies: {}),
      );

      await tapText(tester, 'asha.substack.com');
      await tapText(tester, 'Save changes');

      expect(fake.savedAccounts.single.label, 'My newsletter');
    });
  });

  // ===================================================================
  group("The creator's own profile", () {
    const me = Creator(
      id: 7,
      userId: 70,
      handle: 'asha',
      displayName: 'Asha Rao',
    );

    testWidgets('an empty Packages tab leads to My packages', (tester) async {
      await pumpAt(
        tester,
        '/host',
        host: const ProfilePackagesTab(
          profile: CreatorProfile(creator: me, isOwner: true),
          onBook: null,
        ),
      );

      // Booking is a request the creator accepts, then a checkout.
      expect(find.textContaining('one tap'), findsNothing);
      expect(find.textContaining('booking request'), findsOneWidget);
      // With no live package the server's price is 0, and no rate is quoted.
      expect(find.text('Typical rate'), findsNothing);

      await tapText(tester, 'Manage packages');
      expect(find.text('My packages'), findsOneWidget);
    });

    testWidgets('an empty Media tab leads to the portfolio editor', (tester) async {
      await pumpAt(
        tester,
        '/host',
        host: const ProfileMediaTab(
          profile: CreatorProfile(creator: me, isOwner: true),
        ),
      );

      await tapText(tester, 'Manage portfolio');
      expect(find.text('Your portfolio is empty'), findsOneWidget);
    });

    testWidgets('an empty Insights tab leads to Social accounts', (tester) async {
      await pumpAt(
        tester,
        '/host',
        host: ProfileInsightsTab(
          profile: const CreatorProfile(creator: me, isOwner: true),
          onOpenAccount: (_) {},
        ),
      );

      await tapText(tester, 'Link an account');
      expect(find.text('No accounts linked'), findsOneWidget);
    });

    testWidgets('a brand reads the figures as self-reported, never synced', (tester) async {
      await pumpAt(
        tester,
        '/host',
        host: ProfileInsightsTab(
          profile: CreatorProfile(
            creator: const Creator(
              id: 7,
              userId: 70,
              handle: 'asha',
              displayName: 'Asha Rao',
              followers: 12000,
              followersLabel: '12K',
            ),
            socialAccounts: [
              SocialAccount(
                id: 1,
                platform: 'instagram',
                handle: 'asha.makes',
                label: 'Instagram',
                followers: 12000,
                engagementRate: 3.4,
                lastSyncedAt: DateTime(2026, 10, 10, 9, 30),
              ),
            ],
          ),
          onOpenAccount: (_) {},
        ),
      );

      expect(find.text('Self-reported, on Instagram'), findsOneWidget);
      expect(find.text('Self-reported by the creator · entered 10 Oct 2026'), findsOneWidget);
      expect(find.textContaining('does not fetch these figures'), findsOneWidget);
      expect(find.textContaining('Updated'), findsNothing);
      expect(find.textContaining('sync'), findsNothing);
    });
  });
}

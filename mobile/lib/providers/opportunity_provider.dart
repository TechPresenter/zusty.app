import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/network/api_response.dart';
import '../models/campaign.dart';
import 'auth_provider.dart';
import 'campaign_provider.dart';

// The creator's side of brand campaigns: the public brief board
// ("opportunities"), applying, and tracking applications.
//
// Kept apart from campaign_provider.dart, which is the brand's side. Both read
// through the one CampaignRepository, so there is a single place that knows
// the endpoints — but the state, tabs and invalidation rules here are the
// creator's own, and a brand action never needs to know about them.

/// Whether the signed-in account is a creator, for the creator-only screens.
///
/// Reads the payload's own creator markers (`is_creator`, `creator_id`) as
/// well as `role`. The API names the side of the marketplace `account_type`,
/// which `User.fromJson` does not read, so `role` — and therefore
/// `AuthState.isCreator` — currently reports every account as a brand. The
/// creator markers are what the server actually sends, and a brand never has
/// them.
final isCreatorSessionProvider = Provider<bool>((ref) {
  final auth = ref.watch(authProvider);
  final user = auth.user;
  if (!auth.isAuthenticated || user == null) return false;
  return user.role.isCreator || user.isCreator || user.creatorId != null;
});

/// How the board is ordered. The [query] values are the server's fixed sort
/// keys; anything else falls back to newest server-side.
enum OpportunitySort {
  newest,
  endingSoon,
  budgetHigh,
  popular;

  String get label => switch (this) {
        OpportunitySort.newest => 'Newest',
        OpportunitySort.endingSoon => 'Ending soon',
        OpportunitySort.budgetHigh => 'Highest budget',
        OpportunitySort.popular => 'Most applied',
      };

  String get query => switch (this) {
        OpportunitySort.newest => 'newest',
        OpportunitySort.endingSoon => 'ending_soon',
        OpportunitySort.budgetHigh => 'budget_high',
        OpportunitySort.popular => 'popular',
      };
}

/// Everything that selects a page of the board. A value type, so it can key a
/// provider family: the same search twice reuses the same feed.
class OpportunityQuery {
  const OpportunityQuery({
    this.q = '',
    this.categoryId,
    this.sort = OpportunitySort.newest,
  });

  final String q;
  final int? categoryId;
  final OpportunitySort sort;

  bool get isFiltered => q.trim().isNotEmpty || categoryId != null;

  OpportunityQuery copyWith({
    String? q,
    Object? categoryId = _keep,
    OpportunitySort? sort,
  }) =>
      OpportunityQuery(
        q: q ?? this.q,
        categoryId:
            identical(categoryId, _keep) ? this.categoryId : categoryId as int?,
        sort: sort ?? this.sort,
      );

  @override
  bool operator ==(Object other) =>
      other is OpportunityQuery &&
      other.q.trim() == q.trim() &&
      other.categoryId == categoryId &&
      other.sort == sort;

  @override
  int get hashCode => Object.hash(q.trim(), categoryId, sort);
}

const Object _keep = Object();

/// One query's worth of the board, plus the state of the page being appended.
class OpportunityFeed {
  const OpportunityFeed({
    required this.page,
    this.loadingMore = false,
    this.loadMoreError,
  });

  final Paginated<Campaign> page;
  final bool loadingMore;

  /// Set when fetching the NEXT page failed. The loaded pages stay on screen;
  /// only the footer offers a retry.
  final Object? loadMoreError;

  List<Campaign> get items => page.items;
  bool get hasMore => page.hasMore;
  int get total => page.total;

  OpportunityFeed copyWith({
    Paginated<Campaign>? page,
    bool? loadingMore,
    Object? loadMoreError = _keep,
  }) =>
      OpportunityFeed(
        page: page ?? this.page,
        loadingMore: loadingMore ?? this.loadingMore,
        loadMoreError: identical(loadMoreError, _keep)
            ? this.loadMoreError
            : loadMoreError,
      );
}

/// The paginated board for one [OpportunityQuery].
///
/// An AsyncNotifier rather than a FutureProvider because the list grows: the
/// first page is the async state, and [loadMore] appends without flipping the
/// whole screen back to a skeleton.
class OpportunityFeedController
    extends AutoDisposeFamilyAsyncNotifier<OpportunityFeed, OpportunityQuery> {
  @override
  Future<OpportunityFeed> build(OpportunityQuery query) async {
    return OpportunityFeed(page: await _fetch(1));
  }

  Future<Paginated<Campaign>> _fetch(int page) =>
      ref.read(campaignRepositoryProvider).openCampaigns(
            q: arg.q.trim().isEmpty ? null : arg.q.trim(),
            categoryId: arg.categoryId,
            sort: arg.sort.query,
            page: page,
          );

  /// Appends the next page. Safe to call on every scroll tick: it does nothing
  /// while a page is in flight, after the last page, or before the first.
  Future<void> loadMore() async {
    final current = state.valueOrNull;
    if (current == null || current.loadingMore || !current.hasMore) return;

    state = AsyncData(current.copyWith(loadingMore: true, loadMoreError: null));

    try {
      final next = await _fetch(current.page.nextPage);
      // De-duplicated by id: a brief published between two page reads shifts
      // every later row down by one, which would otherwise repeat a card.
      final seen = current.items.map((c) => c.id).toSet();
      final fresh = next.items.where((c) => !seen.contains(c.id)).toList();
      state = AsyncData(
        current.copyWith(
          page: Paginated(
            items: [...current.items, ...fresh],
            pagination: next.pagination,
          ),
          loadingMore: false,
        ),
      );
    } catch (error) {
      state = AsyncData(
        current.copyWith(loadingMore: false, loadMoreError: error),
      );
    }
  }
}

final opportunityFeedProvider = AsyncNotifierProvider.autoDispose
    .family<OpportunityFeedController, OpportunityFeed, OpportunityQuery>(
  OpportunityFeedController.new,
);

/// One brief as a creator sees it, including `my_application`.
final opportunityProvider =
    FutureProvider.autoDispose.family<Campaign, int>((ref, id) {
  return ref.watch(campaignRepositoryProvider).detail(id);
});

/// The tabs on My applications. Each maps to the statuses it shows, so the
/// screen fetches one tab in one request.
enum ApplicationTab {
  active,
  hired,
  declined;

  String get label => switch (this) {
        ApplicationTab.active => 'Active',
        ApplicationTab.hired => 'Hired',
        ApplicationTab.declined => 'Declined',
      };

  List<RecipientStatus> get statuses => switch (this) {
        ApplicationTab.active => const [
            RecipientStatus.invited,
            RecipientStatus.applied,
            RecipientStatus.shortlisted,
          ],
        ApplicationTab.hired => const [RecipientStatus.hired],
        ApplicationTab.declined => const [RecipientStatus.declined],
      };
}

final myApplicationsProvider = FutureProvider.autoDispose
    .family<ApplicationList, ApplicationTab>((ref, tab) {
  return ref
      .watch(campaignRepositoryProvider)
      .myApplications(statuses: tab.statuses);
});

/// Creator-side campaign mutations, refreshing every view they change.
class OpportunityActions {
  OpportunityActions(this._ref);

  final Ref _ref;

  Future<MyApplication> apply(
    int campaignId, {
    required double quotedAmount,
    required String pitch,
    List<String> portfolioLinks = const [],
  }) async {
    final application = await _ref.read(campaignRepositoryProvider).apply(
          campaignId,
          quotedAmount: quotedAmount,
          pitch: pitch,
          portfolioLinks: portfolioLinks,
        );

    // The brief now shows "Applied", its applicant count moved, and the
    // application belongs on the Active tab. Feeds are autoDispose, so only
    // the ones still on screen actually refetch.
    _ref.invalidate(opportunityProvider(campaignId));
    _ref.invalidate(opportunityFeedProvider);
    for (final tab in ApplicationTab.values) {
      _ref.invalidate(myApplicationsProvider(tab));
    }

    return application;
  }
}

final opportunityActionsProvider = Provider<OpportunityActions>((ref) {
  return OpportunityActions(ref);
});

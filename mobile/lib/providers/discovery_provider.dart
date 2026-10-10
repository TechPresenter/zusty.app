import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/network/api_response.dart';
import '../models/category.dart';
import '../models/creator.dart';
import '../models/creator_completion.dart';
import '../models/creator_fields.dart';
import '../models/home_feed.dart';
import '../models/review.dart';
import 'auth_provider.dart';
import 'core_providers.dart';
import 'location_provider.dart';
import 'safety_provider.dart';

/// The home feed (`GET /home`).
final homeFeedProvider = FutureProvider.autoDispose<HomeFeed>((ref) {
  return ref.watch(creatorRepositoryProvider).home();
});

/// The explore feed (`GET /explore`).
final exploreFeedProvider = FutureProvider.autoDispose<HomeFeed>((ref) {
  return ref.watch(creatorRepositoryProvider).explore();
});

/// All categories — cached for the lifetime of the app session.
final categoriesProvider = FutureProvider<List<Category>>((ref) {
  return ref.watch(creatorRepositoryProvider).categories();
});

/// A creator's public profile, keyed by handle or numeric id.
final creatorProfileProvider =
    FutureProvider.autoDispose.family<CreatorProfile, String>((ref, handle) {
  return ref.watch(creatorRepositoryProvider).profile(handle);
});

/// The extended profile — skills, education, experience, awards, work history,
/// working hours and FAQs — keyed by creator id.
///
/// Its own provider rather than part of [creatorProfileProvider] so the header,
/// price and booking button paint as soon as the profile lands; this block
/// renders below the fold and can arrive a moment later.
final creatorFieldsProvider =
    FutureProvider.autoDispose.family<CreatorFields, int>((ref, creatorId) {
  return ref.watch(creatorRepositoryProvider).fields(creatorId);
});

/// The share sheet payload: URL, pre-composed text, QR and the channels the
/// operator has enabled.
final creatorShareProvider =
    FutureProvider.autoDispose.family<CreatorShare, int>((ref, creatorId) {
  return ref.watch(creatorRepositoryProvider).share(creatorId);
});

/// The signed-in creator's profile completion and feature gates.
///
/// Not `.family` — it is always "me". Kept auto-disposing so it refetches when
/// the creator returns to the menu after editing something, which is exactly
/// when the number will have moved.
final creatorCompletionProvider =
    FutureProvider.autoDispose<CreatorCompletion>((ref) {
  return ref.watch(creatorRepositoryProvider).completion();
});

/// Portfolio media for the gallery / reels / videos tabs.
typedef PortfolioQuery = ({int creatorId, String kind});

final portfolioProvider = FutureProvider.autoDispose
    .family<Paginated<PortfolioItem>, PortfolioQuery>((ref, query) {
  return ref
      .watch(creatorRepositoryProvider)
      .portfolio(query.creatorId, kind: query.kind);
});

final creatorReviewsProvider =
    FutureProvider.autoDispose.family<Paginated<Review>, int>((ref, creatorId) {
  return ref.watch(creatorRepositoryProvider).reviews(creatorId);
});

/// Saved / bookmarked creators (`GET /me/favorites`). Blocking someone
/// removes them from the list on the server; the filter covers a cached copy.
final favoritesProvider =
    FutureProvider.autoDispose<List<Creator>>((ref) async {
  final blocked = ref.watch(blockedIdsProvider);
  final creators = await ref.watch(creatorRepositoryProvider).favorites();
  return creators.where((creator) => !blocked.contains(creator.userId)).toList();
});

/// The active filter set shared by Search, Search Results, and Explore.
///
/// Passing `null` to any setter clears that facet.
class FiltersController extends Notifier<CreatorFilters> {
  @override
  CreatorFilters build() => const CreatorFilters();

  void setQuery(String? query) => state = state.copyWith(query: query ?? '');

  void setCategory(String? slug) => state = state.copyWith(categorySlug: slug);

  void setSort(String sort) => state = state.copyWith(sort: sort);

  void setPriceRange(double? min, double? max) =>
      state = state.copyWith(minPrice: min, maxPrice: max);

  void setFollowerRange(int? min, int? max) =>
      state = state.copyWith(minFollowers: min, maxFollowers: max);

  void setMinRating(double? rating) => state = state.copyWith(minRating: rating);

  void setMinEngagement(double? rate) =>
      state = state.copyWith(minEngagement: rate);

  void setCity(String? city) => state = state.copyWith(city: city);

  void setPlatform(String? platform) =>
      state = state.copyWith(platform: platform);

  void setLanguage(String? language) =>
      state = state.copyWith(language: language);

  void setGender(String? gender) => state = state.copyWith(gender: gender);

  void setAgeGroup(String? group) => state = state.copyWith(ageGroup: group);

  void setExperience(String? level) =>
      state = state.copyWith(experience: level);

  void setResponseTime(String? window) =>
      state = state.copyWith(responseTime: window);

  void setVerifiedOnly(bool value) =>
      state = state.copyWith(verifiedOnly: value);

  void setAvailableOnly(bool value) =>
      state = state.copyWith(availableOnly: value);

  /// Clears every facet but keeps the current search term.
  void reset() => state = state.cleared();

  void replace(CreatorFilters filters) => state = filters;
}

final filtersProvider =
    NotifierProvider<FiltersController, CreatorFilters>(FiltersController.new);

/// The filters actually sent to the API.
///
/// The city chosen in the header acts as a *default* location: it applies when
/// the user has not picked one explicitly in the filter sheet, and an explicit
/// choice always wins.
final effectiveFiltersProvider = Provider.autoDispose<CreatorFilters>((ref) {
  final filters = ref.watch(filtersProvider);
  if (filters.city != null) return filters;

  final headerCity = ref.watch(selectedCityProvider);
  return headerCity == null ? filters : filters.copyWith(city: headerCity);
});

/// Paginated search results for the current filter set.
///
/// The server leaves out anyone with a block between them and the viewer;
/// the filter here also covers a page replayed from the offline cache.
final searchResultsProvider =
    FutureProvider.autoDispose<Paginated<Creator>>((ref) async {
  final filters = ref.watch(effectiveFiltersProvider);
  final blocked = ref.watch(blockedIdsProvider);
  final page = await ref.watch(creatorRepositoryProvider).search(filters);
  return page.removeWhere((creator) => blocked.contains(creator.userId));
});

/// A one-off search that ignores the shared filter state — used by the
/// category drill-down and "view all" rails.
final creatorSearchProvider = FutureProvider.autoDispose
    .family<Paginated<Creator>, CreatorFilters>((ref, filters) async {
  final blocked = ref.watch(blockedIdsProvider);
  final page = await ref.watch(creatorRepositoryProvider).search(filters);
  return page.removeWhere((creator) => blocked.contains(creator.userId));
});

/// Type-ahead suggestions for the search field.
final searchSuggestionsProvider =
    FutureProvider.autoDispose.family<List<String>, String>((ref, query) {
  return ref.watch(creatorRepositoryProvider).searchSuggestions(query);
});

/// Locally-kept recent searches, so the Search screen has history without a
/// dedicated backend read.
///
/// Belongs to the signed-in account: a sign-out or a switch to another
/// account starts it empty again.
class RecentSearchesController extends Notifier<List<String>> {
  @override
  List<String> build() {
    ref.watch(currentUserProvider.select((user) => user?.id));
    return const [];
  }

  void add(String term) {
    final trimmed = term.trim();
    if (trimmed.isEmpty) return;
    state = [trimmed, ...state.where((e) => e != trimmed)].take(8).toList();
  }

  void remove(String term) =>
      state = state.where((e) => e != term).toList(growable: false);

  void clear() => state = const [];
}

final recentSearchesProvider =
    NotifierProvider<RecentSearchesController, List<String>>(
        RecentSearchesController.new);

/// Optimistic favourite toggling shared by every creator card in the app.
///
/// State is a sparse map of *overrides*: a creator id only appears once the
/// user has tapped its heart in this session. Cards fall back to the server's
/// `is_favorite` flag for anything not in the map, so a tap updates instantly
/// everywhere that creator appears without re-fetching the lists.
///
/// The overrides are one account's taps, so a sign-out or a switch to another
/// account drops them and the cards go back to the server's flags.
class FavoriteToggleController extends Notifier<Map<int, bool>> {
  @override
  Map<int, bool> build() {
    ref.watch(currentUserProvider.select((user) => user?.id));
    return const {};
  }

  /// The current favourite state for [creator], honouring any local override.
  bool isFavorite(Creator creator) => state[creator.id] ?? creator.isFavorite;

  Future<void> toggle(Creator creator) async {
    final wasFavorite = isFavorite(creator);
    state = {...state, creator.id: !wasFavorite};

    try {
      final confirmed = await ref
          .read(creatorRepositoryProvider)
          .toggleFavorite(creator.id, isFavorite: wasFavorite);
      state = {...state, creator.id: confirmed};
      ref.invalidate(favoritesProvider);
    } catch (_) {
      // Roll back so the heart keeps matching the server.
      state = {...state, creator.id: wasFavorite};
      rethrow;
    }
  }
}

final favoriteToggleProvider =
    NotifierProvider<FavoriteToggleController, Map<int, bool>>(
        FavoriteToggleController.new);

/// Watches the favourite state of a single creator, so only the card that
/// changed rebuilds.
final isFavoriteProvider = Provider.family<bool, Creator>((ref, creator) {
  return ref.watch(favoriteToggleProvider)[creator.id] ?? creator.isFavorite;
});

/// Follow state, with the same sparse-override model as favourites: a tap
/// flips every card showing that creator at once, and is rolled back if the
/// server refuses. Reset on a sign-out or an account switch, like favourites.
class FollowToggleController extends Notifier<Map<int, bool>> {
  @override
  Map<int, bool> build() {
    ref.watch(currentUserProvider.select((user) => user?.id));
    return const {};
  }

  bool isFollowing(Creator creator) => state[creator.id] ?? creator.isFollowing;

  Future<void> toggle(Creator creator) async {
    final wasFollowing = isFollowing(creator);
    state = {...state, creator.id: !wasFollowing};

    try {
      final confirmed = await ref
          .read(creatorRepositoryProvider)
          .toggleFollow(creator.id, isFollowing: wasFollowing);
      state = {...state, creator.id: confirmed};
    } catch (_) {
      state = {...state, creator.id: wasFollowing};
      rethrow;
    }
  }
}

final followToggleProvider =
    NotifierProvider<FollowToggleController, Map<int, bool>>(
        FollowToggleController.new);

final isFollowingProvider = Provider.family<bool, Creator>((ref, creator) {
  return ref.watch(followToggleProvider)[creator.id] ?? creator.isFollowing;
});

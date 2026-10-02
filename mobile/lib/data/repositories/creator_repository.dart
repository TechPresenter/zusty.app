import '../../core/network/api_endpoints.dart';
import '../../core/network/api_response.dart';
import '../../models/availability.dart';
import '../../models/category.dart';
import '../../models/creator.dart';
import '../../models/creator_completion.dart';
import '../../models/creator_fields.dart';
import '../../models/home_feed.dart';
import '../../models/review.dart';
import 'base_repository.dart';

/// Discovery, creator profiles, and portfolio media.
class CreatorRepository extends BaseRepository {
  CreatorRepository(super.client);

  Future<HomeFeed> home() => readThrough(
        () async => HomeFeed.fromJson((await client.get(ApiEndpoints.home)).dataMap),
        'home',
        (json) => HomeFeed.fromJson(asMap(json)),
      );

  Future<HomeFeed> explore() => readThrough(
        () async =>
            HomeFeed.fromJson((await client.get(ApiEndpoints.explore)).dataMap),
        'explore',
        (json) => HomeFeed.fromJson(asMap(json)),
      );

  Future<List<Category>> categories() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.categories);
          return response
              .listAt('categories')
              .map(Category.fromJson)
              .toList();
        },
        'categories',
        (json) => asRows(json).map(Category.fromJson).toList(),
      );

  Future<Paginated<Creator>> search(
    CreatorFilters filters, {
    int page = 1,
    int perPage = 20,
  }) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.creators,
            query: filters.toQuery(page: page, perPage: perPage),
          );
          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('creators');
          return Paginated(
            items: rows.map(Creator.fromJson).toList(),
            pagination: response.pagination,
          );
        },
        'creators',
        (json) => _filterFixtures(
          asRows(json),
          filters,
          page: page,
          perPage: perPage,
        ),
      );

  Future<CreatorProfile> profile(String handleOrId) => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.creator(handleOrId));
          final data = response.maybeMapAt('creator') ?? response.dataMap;
          return CreatorProfile.fromJson(data);
        },
        'creator.detail:$handleOrId',
        (json) => CreatorProfile.fromJson(asMap(json)),
      );

  /// The extended profile: skills, interests, languages, education,
  /// certifications, awards, work history, brand collaborations, working hours
  /// and FAQs.
  ///
  /// Fetched separately from [profile] because it renders below the fold — the
  /// header, price and booking button should not wait on it. Returns an empty
  /// [CreatorFields] rather than throwing when the creator has filled none of
  /// it in, which is the common case for a new profile.
  Future<CreatorFields> fields(int creatorId) => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.creatorFields(creatorId));
          return CreatorFields.fromJson(
            response.maybeMapAt('fields') ?? response.dataMap,
          );
        },
        'creator.fields:$creatorId',
        (json) => CreatorFields.fromJson(asMap(json)),
      );

  /// The signed-in creator's completion breakdown and feature gates.
  ///
  /// The admin panel has had this since the engine shipped; this is the same
  /// data for the person who can actually act on it.
  Future<CreatorCompletion> completion() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.creatorCompletion);
          return CreatorCompletion.fromJson(response.dataMap);
        },
        'creator.completion',
        (json) => CreatorCompletion.fromJson(asMap(json)),
      );

  /// The share URL and QR payload for a profile.
  ///
  /// [qr] names the representations wanted — the server's default is the light
  /// one, and asking for all three returns roughly 52 KB.
  Future<CreatorShare> share(int creatorId, {String? qr}) => readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.creatorShare(creatorId),
            query: {'qr': ?qr},
          );
          return CreatorShare.fromJson(
            response.maybeMapAt('share') ?? response.dataMap,
          );
        },
        'creator.share:$creatorId',
        (json) => CreatorShare.fromJson(asMap(json)),
      );

  /// Count a share, a QR download, a portfolio download or a social click.
  ///
  /// Deliberately fire-and-forget: this is a counter, and a failed count must
  /// never break the action the user actually took. The server ignores a
  /// creator's engagement with their own profile, so the caller does not have
  /// to check who is looking.
  Future<void> recordEngagement(
    int creatorId, {
    required String eventType,
    String? channel,
    int? targetId,
  }) async {
    try {
      await client.post(
        ApiEndpoints.creatorEngagement(creatorId),
        body: {
          'event_type': eventType,
          'channel': ?channel,
          'target_id': ?targetId,
        },
      );
    } catch (_) {
      // Swallowed on purpose. See the doc comment above.
    }
  }

  Future<Paginated<PortfolioItem>> portfolio(
    int creatorId, {
    String kind = 'post',
    int page = 1,
  }) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.creatorPortfolio(creatorId),
            query: {'kind': kind, 'page': page, 'per_page': 24},
          );
          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('items');
          return Paginated(
            items: rows.map(PortfolioItem.fromJson).toList(),
            pagination: response.pagination,
          );
        },
        'creator.portfolio:$kind',
        (json) {
          final items = asRows(json).map(PortfolioItem.fromJson).toList();
          return Paginated(
            items: items,
            pagination: Pagination.singlePage(items.length),
          );
        },
      );

  Future<Paginated<Review>> reviews(int creatorId, {int page = 1}) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.creatorReviews(creatorId),
            query: {'page': page},
          );
          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('reviews');
          return Paginated(
            items: rows.map(Review.fromJson).toList(),
            pagination: response.pagination,
          );
        },
        'creator.reviews',
        (json) {
          final items = asRows(json).map(Review.fromJson).toList();
          return Paginated(
            items: items,
            pagination: Pagination.singlePage(items.length),
          );
        },
      );

  /// A creator's booking calendar for one month (`yyyy-MM`).
  Future<AvailabilityMonth> availability(int creatorId, DateTime month) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.creatorAvailability(creatorId),
            query: {'month': _monthKey(month)},
          );
          return AvailabilityMonth.fromJson(response.dataMap);
        },
        'creator.availability:${_monthKey(month)}',
        (json) => AvailabilityMonth.fromJson(asMap(json)),
      );

  /// Opens or blocks a set of dates on the signed-in creator's calendar.
  Future<void> setAvailability({
    required List<DateTime> dates,
    required bool isAvailable,
    int maxBookings = 1,
  }) =>
      writeThrough(
        () async {
          await client.put(
            ApiEndpoints.creatorSetAvailability,
            body: {
              'dates': dates.map(AvailabilityDay.keyFor).toList(),
              'is_available': isAvailable,
              'max_bookings': maxBookings,
            },
          );
        },
        'noop',
        (_) {},
      );

  /// Orders the response-time buckets so a stricter filter also admits faster
  /// creators. Unknown values sort last.
  static int _responseRank(String? value) => switch (value) {
        'within_1h' => 0,
        'within_6h' => 1,
        'within_24h' => 2,
        'within_48h' => 3,
        _ => 99,
      };

  static String _monthKey(DateTime month) =>
      '${month.year.toString().padLeft(4, '0')}-'
      '${month.month.toString().padLeft(2, '0')}';

  /// Returns the new favourite state.
  Future<bool> toggleFavorite(int creatorId, {required bool isFavorite}) =>
      writeThrough(
        () async {
          final response =
              await client.post(ApiEndpoints.toggleFavorite(creatorId));
          final value = response.dataMap['is_favorite'];
          return value is bool ? value : !isFavorite;
        },
        'noop',
        (_) => !isFavorite,
      );

  Future<bool> toggleFollow(int creatorId, {required bool isFollowing}) =>
      writeThrough(
        () async {
          final response =
              await client.post(ApiEndpoints.toggleFollow(creatorId));
          final value = response.dataMap['is_following'];
          return value is bool ? value : !isFollowing;
        },
        'noop',
        (_) => !isFollowing,
      );

  Future<List<Creator>> favorites() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.favorites);
          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('creators');
          return rows.map(Creator.fromJson).toList();
        },
        'creators',
        (json) => asRows(json)
            .where((c) => c['is_favorite'] == true)
            .map(Creator.fromJson)
            .toList(),
      );

  Future<List<String>> searchSuggestions(String query) => readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.searchSuggestions,
            query: {'q': query},
          );
          final raw = response.dataMap['suggestions'];
          if (raw is List && raw.isNotEmpty) {
            return raw
                .map((e) => e is Map ? '${e['label'] ?? e['term']}' : '$e')
                .toList();
          }

          // Older builds of the endpoint send only the landing blocks. Merging
          // them here keeps the type-ahead populated instead of blank.
          return _mergeSuggestions([
            ...response.dataMap['recent_searches'] is List
                ? (response.dataMap['recent_searches'] as List).map((e) => '$e')
                : const <String>[],
            ...response
                .listAt('suggested_creators')
                .map((c) => '${c['display_name'] ?? c['handle'] ?? ''}'),
            ...response.dataMap['popular_searches'] is List
                ? (response.dataMap['popular_searches'] as List).map((e) => '$e')
                : const <String>[],
          ], query);
        },
        // The endpoint narrows by query server-side, so the fixture is asked
        // for the same thing rather than being filtered here.
        'search.suggestions:$query',
        (json) => (json as List).map((e) => '$e').toList(),
      );

  /// De-duplicates the landing blocks and keeps what matches what was typed.
  static List<String> _mergeSuggestions(Iterable<String> terms, String query) {
    final needle = query.trim().toLowerCase();
    final seen = <String, String>{};

    for (final term in terms) {
      final value = term.trim();
      if (value.isEmpty || seen.length >= 12) continue;
      if (needle.isNotEmpty && !value.toLowerCase().contains(needle)) continue;
      seen.putIfAbsent(value.toLowerCase(), () => value);
    }

    return seen.values.toList(growable: false);
  }

  /// Client-side filtering over the supplied fixture rows, so the Search screen
  /// behaves realistically when the live endpoint is out of reach.
  Paginated<Creator> _filterFixtures(
    List<Map<String, dynamic>> fixtureRows,
    CreatorFilters filters, {
    required int page,
    required int perPage,
  }) {
    var rows = fixtureRows.map(Creator.fromJson).toList();

    final query = filters.query?.trim().toLowerCase();
    if (query != null && query.isNotEmpty) {
      rows = rows
          .where((c) =>
              c.displayName.toLowerCase().contains(query) ||
              c.headline.toLowerCase().contains(query) ||
              (c.category ?? '').toLowerCase().contains(query) ||
              c.handle.toLowerCase().contains(query))
          .toList();
    }
    if (filters.categorySlug != null) {
      rows = rows.where((c) => c.categorySlug == filters.categorySlug).toList();
    }
    if (filters.city != null) {
      rows = rows.where((c) => c.city == filters.city).toList();
    }
    if (filters.minPrice != null) {
      rows = rows.where((c) => c.priceMax >= filters.minPrice!).toList();
    }
    if (filters.maxPrice != null) {
      rows = rows.where((c) => c.priceMin <= filters.maxPrice!).toList();
    }
    if (filters.minFollowers != null) {
      rows = rows.where((c) => c.followers >= filters.minFollowers!).toList();
    }
    if (filters.maxFollowers != null) {
      rows = rows.where((c) => c.followers <= filters.maxFollowers!).toList();
    }
    if (filters.minRating != null) {
      rows = rows.where((c) => c.rating >= filters.minRating!).toList();
    }
    if (filters.minEngagement != null) {
      rows = rows
          .where((c) => c.engagementRate >= filters.minEngagement!)
          .toList();
    }
    if (filters.language != null) {
      rows = rows
          .where((c) => c.languages.contains(filters.language))
          .toList();
    }
    if (filters.gender != null) {
      rows = rows.where((c) => c.gender == filters.gender).toList();
    }
    if (filters.ageGroup != null) {
      rows = rows.where((c) => c.ageGroup == filters.ageGroup).toList();
    }
    if (filters.experience != null) {
      rows = rows.where((c) => c.experience == filters.experience).toList();
    }
    if (filters.responseTime != null) {
      // "Within 6 hours" should also match creators who reply within 1 hour,
      // so compare rank rather than equality.
      final ceiling = _responseRank(filters.responseTime);
      rows = rows
          .where((c) => _responseRank(c.responseTime) <= ceiling)
          .toList();
    }
    if (filters.verifiedOnly) {
      rows = rows.where((c) => c.isVerified).toList();
    }
    if (filters.availableOnly) {
      rows = rows
          .where((c) => c.availability == Availability.available)
          .toList();
    }

    switch (filters.sort) {
      case 'rating':
        rows.sort((a, b) => b.rating.compareTo(a.rating));
      case 'popular':
        rows.sort((a, b) => b.followers.compareTo(a.followers));
      case 'price_low':
        rows.sort((a, b) => a.priceMin.compareTo(b.priceMin));
      case 'price_high':
        rows.sort((a, b) => b.priceMax.compareTo(a.priceMax));
      case 'newest':
        rows.sort((a, b) => b.id.compareTo(a.id));
      default:
        rows.sort((a, b) {
          if (a.isFeatured != b.isFeatured) return a.isFeatured ? -1 : 1;
          return b.rating.compareTo(a.rating);
        });
    }

    final start = (page - 1) * perPage;
    final slice =
        rows.skip(start).take(perPage).toList(growable: false);

    return Paginated(
      items: slice,
      pagination: Pagination(
        total: rows.length,
        perPage: perPage,
        currentPage: page,
        lastPage: (rows.length / perPage).ceil().clamp(1, 999),
        hasMore: start + slice.length < rows.length,
      ),
    );
  }
}

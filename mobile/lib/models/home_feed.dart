import '../core/utils/action_link.dart';
import '../core/utils/json.dart';
import 'booking.dart';
import 'category.dart';
import 'creator.dart';

/// A promotional banner in the home hero carousel.
class HomeBanner {
  const HomeBanner({
    required this.id,
    required this.title,
    this.subtitle = '',
    this.imageUrl,
    this.ctaLabel = 'Explore Now',
    this.actionUrl,
  });

  final int id;
  final String title;
  final String subtitle;
  final String? imageUrl;
  final String ctaLabel;
  final String? actionUrl;

  factory HomeBanner.fromJson(Map<String, dynamic> json) => HomeBanner(
        id: Json.integer(json['id']),
        title: Json.str(json['title'], 'Find the Right Creator'),
        subtitle: Json.str(json['subtitle']),
        imageUrl: Json.strOrNull(json['image_url'] ?? json['image']),
        ctaLabel: Json.str(json['cta_label'] ?? json['cta'], 'Explore Now'),
        actionUrl: Json.strOrNull(json['action_url'] ?? json['link']) ??
            actionLinkFor(json['action_type'], json['action_value']),
      );
}

/// One rail of the home screen, as configured in `homepage_sections`.
///
/// The admin panel has always let an operator reorder, retitle and retire these
/// rows, and the app used to paint a fixed Dart layout with its own copy of the
/// titles — which is how a panel title with a "+" in it came to read without
/// the "+" in the app. Everything a rail needs to draw itself is here,
/// so an operator adding a rail does not need a release.
class HomeSection {
  const HomeSection({
    required this.id,
    required this.type,
    required this.title,
    this.subtitle,
    this.layout = 'carousel',
    this.ctaLabel,
    this.ctaAction,
    this.categories = const [],
    this.creators = const [],
    this.banners = const [],
  });

  final int id;

  /// `banner_carousel`, `category_strip`, `creator_grid`, `creator_carousel`,
  /// and the web-only types this build has no widget for.
  final String type;
  final String title;
  final String? subtitle;

  /// `grid_2`, `grid_3`, `carousel`, `list` or `full`.
  final String layout;
  final String? ctaLabel;

  /// The in-app path the section header's action opens, e.g. `/explore`.
  final String? ctaAction;

  // The server resolves `items` per type, so exactly one of these is populated.
  final List<Category> categories;
  final List<Creator> creators;
  final List<HomeBanner> banners;

  bool get isEmpty =>
      categories.isEmpty && creators.isEmpty && banners.isEmpty;

  factory HomeSection.fromJson(Map<String, dynamic> json) {
    final type = Json.str(json['type'], 'creator_carousel');
    final items = json['items'];

    return HomeSection(
      id: Json.integer(json['id']),
      type: type,
      title: Json.str(json['title']),
      subtitle: Json.strOrNull(json['subtitle']),
      layout: Json.str(json['layout'], 'carousel'),
      ctaLabel: Json.strOrNull(json['cta_label']),
      ctaAction: Json.strOrNull(json['cta_action']),
      categories:
          type == 'category_strip' ? Json.list(items, Category.fromJson) : const [],
      banners:
          type == 'banner_carousel' ? Json.list(items, HomeBanner.fromJson) : const [],
      creators: type == 'creator_grid' || type == 'creator_carousel'
          ? Json.list(items, Creator.fromJson)
          : const [],
    );
  }
}

/// Everything the home screen renders, from `GET /home`.
class HomeFeed {
  const HomeFeed({
    this.banners = const [],
    this.categories = const [],
    this.topCreators = const [],
    this.trending = const [],
    this.newCreators = const [],
    this.activeBookings = const [],
    this.sections = const [],
    this.unreadNotifications = 0,
    this.unreadMessages = 0,
  });

  final List<HomeBanner> banners;
  final List<Category> categories;
  final List<Creator> topCreators;
  final List<Creator> trending;
  final List<Creator> newCreators;
  final List<Booking> activeBookings;

  /// The admin-configured layout. Empty against an older backend, in which case
  /// the screen falls back to the flat keys above.
  final List<HomeSection> sections;

  final int unreadNotifications;
  final int unreadMessages;

  factory HomeFeed.fromJson(Map<String, dynamic> json) {
    final badges = Json.map(json['badges']);
    return HomeFeed(
      banners: Json.list(json['banners'], HomeBanner.fromJson),
      categories: Json.list(json['categories'], Category.fromJson),
      topCreators: Json.list(json['top_creators'], Creator.fromJson),
      trending: Json.list(json['trending'], Creator.fromJson),
      newCreators: Json.list(json['new_creators'], Creator.fromJson),
      activeBookings: Json.list(json['active_bookings'], Booking.fromJson),
      sections: Json.list(json['sections'], HomeSection.fromJson),
      unreadNotifications: Json.integer(badges['unread_notifications']),
      unreadMessages: Json.integer(badges['unread_messages']),
    );
  }
}

/// Sentinel so `copyWith` can tell "leave unchanged" from "clear this".
const Object _keep = Object();

/// The filter set applied on Search, Search Results, and Explore.
///
/// Serialises straight into the query string `GET /creators` expects. Facets
/// the backend does not yet index are still sent — unknown params are ignored
/// server-side — and are additionally applied client-side against the fixtures
/// so the UI behaves correctly in demo mode.
class CreatorFilters {
  const CreatorFilters({
    this.query,
    this.categorySlug,
    this.city,
    this.platform,
    this.minPrice,
    this.maxPrice,
    this.minFollowers,
    this.maxFollowers,
    this.minRating,
    this.minEngagement,
    this.language,
    this.gender,
    this.ageGroup,
    this.experience,
    this.responseTime,
    this.sort = 'relevance',
    this.verifiedOnly = false,
    this.availableOnly = false,
  });

  final String? query;
  final String? categorySlug;

  /// City name, e.g. `Mumbai`.
  final String? city;
  final String? platform;
  final double? minPrice;
  final double? maxPrice;
  final int? minFollowers;
  final int? maxFollowers;
  final double? minRating;

  /// Minimum engagement rate as a percentage, e.g. `3.0`.
  final double? minEngagement;

  final String? language;

  /// `female`, `male`, `non_binary`.
  final String? gender;

  /// One of [ageGroupOptions].
  final String? ageGroup;

  /// One of [experienceOptions].
  final String? experience;

  /// One of [responseTimeOptions] — the maximum acceptable reply window.
  final String? responseTime;

  /// One of [sortOptions].
  final String sort;
  final bool verifiedOnly;
  final bool availableOnly;

  Map<String, dynamic> toQuery({int page = 1, int perPage = 20}) => {
        'page': page,
        'per_page': perPage,
        if (query != null && query!.isNotEmpty) 'q': query,
        if (categorySlug != null) 'category_slug': categorySlug,
        if (city != null) 'city': city,
        if (platform != null) 'platform': platform,
        if (minPrice != null) 'min_price': minPrice,
        if (maxPrice != null) 'max_price': maxPrice,
        if (minFollowers != null) 'min_followers': minFollowers,
        if (maxFollowers != null) 'max_followers': maxFollowers,
        if (minRating != null) 'min_rating': minRating,
        if (minEngagement != null) 'min_engagement': minEngagement,
        if (language != null) 'language': language,
        if (gender != null) 'gender': gender,
        if (ageGroup != null) 'age_group': ageGroup,
        if (experience != null) 'experience': experience,
        if (responseTime != null) 'response_time': responseTime,
        if (verifiedOnly) 'verified': 1,
        if (availableOnly) 'available': 1,
        'sort': sort,
      };

  /// Every active facet beyond the free-text query — drives the badge on the
  /// filter button and the "clear all" affordance.
  int get activeCount => [
        categorySlug,
        city,
        platform,
        minPrice,
        maxPrice,
        minFollowers,
        maxFollowers,
        minRating,
        minEngagement,
        language,
        gender,
        ageGroup,
        experience,
        responseTime,
        verifiedOnly ? true : null,
        availableOnly ? true : null,
        sort == 'relevance' ? null : sort,
      ].where((e) => e != null).length;

  bool get isEmpty => activeCount == 0 && (query == null || query!.isEmpty);

  /// Pass `null` explicitly to clear a facet; omit it to leave it unchanged.
  CreatorFilters copyWith({
    Object? query = _keep,
    Object? categorySlug = _keep,
    Object? city = _keep,
    Object? platform = _keep,
    Object? minPrice = _keep,
    Object? maxPrice = _keep,
    Object? minFollowers = _keep,
    Object? maxFollowers = _keep,
    Object? minRating = _keep,
    Object? minEngagement = _keep,
    Object? language = _keep,
    Object? gender = _keep,
    Object? ageGroup = _keep,
    Object? experience = _keep,
    Object? responseTime = _keep,
    String? sort,
    bool? verifiedOnly,
    bool? availableOnly,
  }) =>
      CreatorFilters(
        query: _resolve(query, this.query),
        categorySlug: _resolve(categorySlug, this.categorySlug),
        city: _resolve(city, this.city),
        platform: _resolve(platform, this.platform),
        minPrice: _resolve(minPrice, this.minPrice),
        maxPrice: _resolve(maxPrice, this.maxPrice),
        minFollowers: _resolve(minFollowers, this.minFollowers),
        maxFollowers: _resolve(maxFollowers, this.maxFollowers),
        minRating: _resolve(minRating, this.minRating),
        minEngagement: _resolve(minEngagement, this.minEngagement),
        language: _resolve(language, this.language),
        gender: _resolve(gender, this.gender),
        ageGroup: _resolve(ageGroup, this.ageGroup),
        experience: _resolve(experience, this.experience),
        responseTime: _resolve(responseTime, this.responseTime),
        sort: sort ?? this.sort,
        verifiedOnly: verifiedOnly ?? this.verifiedOnly,
        availableOnly: availableOnly ?? this.availableOnly,
      );

  static T? _resolve<T>(Object? incoming, T? current) =>
      identical(incoming, _keep) ? current : incoming as T?;

  /// Drops every facet, keeping the search term.
  CreatorFilters cleared() => CreatorFilters(query: query);

  // Option lists live in the `taxonomies` tables and reach the app via
  // `/app/taxonomies`. See `TaxonomyBundle` and `Tax`.

  /// Maximum acceptable reply window.
  /// Upper bound on the budget slider, in rupees.
  static const maxBudget = 200000.0;

  /// Upper bound on the follower slider.
  static const maxFollowerCount = 1000000;
}

import '../core/utils/formatters.dart';
import '../core/utils/json.dart';
import 'category.dart';
import 'review.dart';

/// Whether a creator is currently taking bookings.
enum Availability {
  available,
  busy,
  unavailable;

  static Availability fromString(String? value) =>
      switch (value?.toLowerCase()) {
        'busy' || 'limited' => Availability.busy,
        'unavailable' || 'paused' || 'away' => Availability.unavailable,
        _ => Availability.available,
      };

  String get label => switch (this) {
    Availability.available => 'Available',
    Availability.busy => 'Busy',
    Availability.unavailable => 'Unavailable',
  };
}

/// A creator as rendered in search results and grids.
///
/// Mirrors `CreatorService::presentCard()`.
class Creator {
  const Creator({
    required this.id,
    required this.userId,
    required this.handle,
    required this.displayName,
    this.headline = '',
    this.avatarUrl,
    this.city,
    this.location = '',
    this.category,
    this.categorySlug,
    this.rating = 0,
    this.ratingCount = 0,
    this.followers = 0,
    this.followersLabel = '0',
    this.postsCount = 0,
    this.collabsCount = 0,
    this.priceMin = 0,
    this.priceMax = 0,
    this.priceLabel = '',
    this.currency = 'INR',
    this.isVerified = false,
    this.isFeatured = false,
    this.availability = Availability.available,
    this.isFavorite = false,
    this.isFollowing = false,
    this.engagementRate = 0,
    this.platforms = const [],
    this.languages = const [],
    this.gender,
    this.ageGroup,
    this.experience,
    this.responseTime,
  });

  final int id;
  final int userId;
  final String handle;
  final String displayName;
  final String headline;
  final String? avatarUrl;
  final String? city;
  final String location;
  final String? category;
  final String? categorySlug;
  final double rating;
  final int ratingCount;
  final int followers;

  /// Server-compacted count, e.g. `56K`.
  final String followersLabel;
  final int postsCount;
  final int collabsCount;
  final double priceMin;
  final double priceMax;

  /// Server-formatted range, e.g. `₹15,000 – ₹50,000`.
  final String priceLabel;
  final String currency;
  final bool isVerified;
  final bool isFeatured;
  final Availability availability;
  final bool isFavorite;
  final bool isFollowing;

  /// Average engagement across linked social accounts, as a percentage.
  final double engagementRate;

  /// `social_platform` values the creator is on, their own order first.
  final List<String> platforms;

  /// Languages the creator produces content in.
  final List<String> languages;

  /// `female`, `male`, `non_binary`.
  final String? gender;

  /// A term value from the `age_group` taxonomy.
  final String? ageGroup;

  /// A term value from the `experience_level` taxonomy.
  final String? experience;

  /// A term value from the `response_time` taxonomy.
  final String? responseTime;

  factory Creator.fromJson(Map<String, dynamic> json) {
    final followers = Json.integer(
      json['followers'] ?? json['followers_total'],
    );

    return Creator(
      id: Json.integer(json['id']),
      userId: Json.integer(json['user_id']),
      handle: Json.str(json['handle']),
      displayName: Json.str(json['display_name'], 'Creator'),
      headline: Json.str(json['headline']),
      avatarUrl: Json.strOrNull(json['avatar_url']),
      city: Json.strOrNull(json['city']),
      location: Json.str(json['location'], _locationFrom(json)),
      category: Json.strOrNull(json['category'] ?? json['category_name']),
      categorySlug: Json.strOrNull(json['category_slug']),
      rating: Json.decimal(json['rating'] ?? json['rating_avg']),
      ratingCount: Json.integer(json['rating_count']),
      followers: followers,
      // The fallback compacts the count actually parsed. It used to read
      // `followers` alone, and the profile payload (a raw `creators` row)
      // only has `followers_total` — so every profile header said "0".
      followersLabel: Json.str(
        json['followers_label'],
        Formatters.compact(followers),
      ),
      postsCount: Json.integer(json['posts_count']),
      collabsCount: Json.integer(json['collabs_count']),
      priceMin: Json.decimal(json['price_min']),
      priceMax: Json.decimal(json['price_max']),
      priceLabel: Json.str(
        json['price_label'] ?? json['price_formatted'],
        Formatters.currencyRange(
          Json.decimal(json['price_min']),
          Json.decimal(json['price_max']),
        ),
      ),
      currency: Json.str(json['currency'], 'INR'),
      isVerified: Json.boolean(json['is_verified']),
      isFeatured: Json.boolean(json['is_featured']),
      // `availability` on a search card, `availability_status` on the raw
      // profile row. Reading only the first made every profile "Available",
      // including creators who had paused bookings.
      availability: Availability.fromString(
        Json.strOrNull(json['availability'] ?? json['availability_status']),
      ),
      isFavorite: Json.boolean(json['is_favorite']),
      isFollowing: Json.boolean(json['is_following']),
      engagementRate: Json.decimal(json['engagement_rate']),
      platforms: Json.strings(json['platforms']),
      languages: Json.strings(json['languages']),
      gender: Json.strOrNull(json['gender']),
      ageGroup: Json.strOrNull(json['age_group']),
      experience: Json.strOrNull(
        json['experience'] ?? json['experience_level'],
      ),
      responseTime: Json.strOrNull(json['response_time']),
    );
  }

  /// "Mumbai, Maharashtra" from the raw columns, for a payload that has no
  /// composed `location` (the profile row, and responses cached before the
  /// server started sending one).
  static String _locationFrom(Map<String, dynamic> json) {
    final city = Json.strOrNull(json['city']);
    final region =
        Json.strOrNull(json['state']) ?? Json.strOrNull(json['country']);

    return [?city, ?region].join(', ');
  }

  String get atHandle => handle.startsWith('@') ? handle : '@$handle';

  String get initials => Formatters.initials(displayName);

  Creator copyWith({bool? isFavorite, bool? isFollowing}) => Creator(
    id: id,
    userId: userId,
    handle: handle,
    displayName: displayName,
    headline: headline,
    avatarUrl: avatarUrl,
    city: city,
    location: location,
    category: category,
    categorySlug: categorySlug,
    rating: rating,
    ratingCount: ratingCount,
    followers: followers,
    followersLabel: followersLabel,
    postsCount: postsCount,
    collabsCount: collabsCount,
    priceMin: priceMin,
    priceMax: priceMax,
    priceLabel: priceLabel,
    currency: currency,
    isVerified: isVerified,
    isFeatured: isFeatured,
    availability: availability,
    isFavorite: isFavorite ?? this.isFavorite,
    isFollowing: isFollowing ?? this.isFollowing,
    engagementRate: engagementRate,
    platforms: platforms,
    languages: languages,
    gender: gender,
    ageGroup: ageGroup,
    experience: experience,
    responseTime: responseTime,
  );
}

/// The full public profile behind `GET /creators/{handle}`.
class CreatorProfile {
  const CreatorProfile({
    required this.creator,
    this.bio = '',
    this.coverUrl,
    this.languages = const [],
    this.categories = const [],
    this.socialAccounts = const [],
    this.packages = const [],
    this.recentPortfolio = const [],
    this.reviews = const [],
    this.badges = const [],
    this.lockedSections = const [],
    this.ratingBreakdown = const {},
    this.portfolioCounts = const {},
    this.isOnline = false,
    this.isOwner = false,
    this.responseTime,
    this.joinedAt,
    this.responseTimeHours,
    this.completionRate,
    this.platformFollowers = 0,
    this.introVideoUrl,
    this.introVideoThumbnailUrl,
    this.mediaKitUrl,
    this.audience,
    this.ratingAspects,
  });

  final Creator creator;

  /// The creator's own description. The server column is `about`; `bio` is
  /// what older payloads (and the test fixtures) call it.
  final String bio;

  /// Header photo — `cover_image_url` on the server.
  final String? coverUrl;
  final List<String> languages;
  final List<Category> categories;
  final List<SocialAccount> socialAccounts;
  final List<CreatorPackage> packages;
  final List<PortfolioItem> recentPortfolio;
  final List<Review> reviews;

  /// Verification badges this creator holds. On the creator's OWN profile the
  /// server sends the whole catalogue so the app can also show what is not yet
  /// earned; on a public profile only the held badges arrive.
  final List<VerificationBadge> badges;

  /// Profile sections an administrator has frozen. The app renders these
  /// read-only rather than letting the creator fill in a form the API will
  /// refuse with a 403.
  final List<String> lockedSections;

  /// Star value (1–5) to count, for the rating histogram.
  final Map<int, int> ratingBreakdown;

  /// Media kind (`post`, `reel`, `photo`, `video`) to item count.
  final Map<String, int> portfolioCounts;

  final bool isOnline;
  final bool isOwner;

  /// A ready-made reply-time phrase, when the payload carries one. The live
  /// server sends [responseTimeHours] instead; read [responseTimeLabel].
  final String? responseTime;
  final DateTime? joinedAt;

  /// Typical hours to first reply (`response_time_hours`).
  final int? responseTimeHours;

  /// Share of accepted bookings delivered, 0–100. Null when not sent.
  final double? completionRate;

  /// People following this creator on Zusty itself — the counter the Follow
  /// button moves, as opposed to [Creator.followers] (social reach).
  final int platformFollowers;

  final String? introVideoUrl;
  final String? introVideoThumbnailUrl;
  final String? mediaKitUrl;

  /// Who the creator reaches, aggregated across linked accounts. Null when no
  /// account has audience figures — never filled in by the app.
  final AudienceSummary? audience;

  /// Average aspect scores from published brand reviews.
  final RatingAspects? ratingAspects;

  factory CreatorProfile.fromJson(Map<String, dynamic> json) {
    final breakdown = <int, int>{};
    Json.map(json['rating_breakdown']).forEach((key, value) {
      final star = int.tryParse(key.replaceAll(RegExp(r'\D'), ''));
      if (star != null) breakdown[star] = Json.integer(value);
    });

    final counts = <String, int>{};
    Json.map(json['portfolio_counts']).forEach((key, value) {
      counts[key] = Json.integer(value);
    });

    return CreatorProfile(
      creator: Creator.fromJson(json),
      // The server's names are `about`, `cover_image_url` and
      // `response_time_hours`. This read only `bio`, `cover_url`/`cover_image`
      // and `response_time`, none of which the live API sends — so About and
      // the cover photo never rendered for any real creator. Both spellings
      // are accepted so cached responses and fixtures still parse.
      bio: Json.str(json['bio'] ?? json['about']),
      coverUrl: Json.strOrNull(
        json['cover_url'] ?? json['cover_image'] ?? json['cover_image_url'],
      ),
      languages: Json.strings(json['languages']),
      categories: Json.list(json['categories'], Category.fromJson),
      socialAccounts: Json.list(
        json['social_accounts'],
        SocialAccount.fromJson,
      ),
      packages: Json.list(json['packages'], CreatorPackage.fromJson),
      recentPortfolio: Json.list(
        json['recent_portfolio'],
        PortfolioItem.fromJson,
      ),
      reviews: Json.list(json['reviews'], Review.fromJson),
      badges: Json.list(json['badges'], VerificationBadge.fromJson),
      lockedSections: Json.strings(json['locked_sections']),
      ratingBreakdown: breakdown,
      portfolioCounts: counts,
      isOnline: Json.boolean(json['is_online']),
      isOwner: Json.boolean(json['is_owner']),
      responseTime: Json.strOrNull(json['response_time']),
      joinedAt: Formatters.parseDate(json['joined_at']),
      responseTimeHours: Json.intOrNull(json['response_time_hours']),
      completionRate: Json.doubleOrNull(json['completion_rate']),
      platformFollowers: Json.integer(json['platform_followers']),
      introVideoUrl: Json.strOrNull(json['intro_video_url']),
      introVideoThumbnailUrl: Json.strOrNull(json['intro_video_thumbnail_url']),
      mediaKitUrl: Json.strOrNull(json['media_kit_url']),
      audience: AudienceSummary.maybeFromJson(json['audience']),
      ratingAspects: RatingAspects.maybeFromJson(json['rating_aspects']),
    );
  }

  int get totalReviews =>
      ratingBreakdown.values.fold(0, (sum, count) => sum + count);

  /// "Replies within 12 hours", from whichever the payload carried.
  String? get responseTimeLabel {
    final label = responseTime;
    if (label != null && label.contains(' ')) return label;

    // Past here `label` is absent or a bare taxonomy slug (`within_6h`),
    // which is not something to show a person.
    final hours = responseTimeHours;
    if (hours == null || hours <= 0) return null;
    if (hours <= 1) return 'Replies within an hour';
    if (hours < 24) return 'Replies within $hours hours';

    final days = (hours / 24).ceil();
    return days == 1 ? 'Replies within a day' : 'Replies within $days days';
  }

  /// "~12h" — the same figure, short enough for a stat tile.
  String? get responseTimeShort {
    final hours = responseTimeHours;
    if (hours == null || hours <= 0) return null;
    if (hours < 24) return '~${hours}h';

    final days = (hours / 24).ceil();
    return '~${days}d';
  }

  /// Accounts with real audience figures — not website or portfolio links.
  List<SocialAccount> get metricAccounts =>
      socialAccounts.where((account) => account.hasMetrics).toList();

  /// Follower-weighted engagement across [metricAccounts], or the server's
  /// own figure when the accounts carry none.
  double get averageEngagement {
    var reach = 0;
    var weighted = 0.0;

    for (final account in metricAccounts) {
      if (account.followers <= 0) continue;
      reach += account.followers;
      weighted += account.engagementRate * account.followers;
    }

    return reach > 0 ? weighted / reach : creator.engagementRate;
  }

  /// The account the creator nominated as primary, else their largest.
  SocialAccount? get primaryAccount {
    final accounts = metricAccounts;
    if (accounts.isEmpty) return null;

    for (final account in accounts) {
      if (account.isPrimary) return account;
    }

    return accounts.reduce((a, b) => b.followers > a.followers ? b : a);
  }

  /// The cheapest active package — the honest "from" price.
  CreatorPackage? get startingPackage => packages.isEmpty
      ? null
      : packages.reduce((a, b) => b.price < a.price ? b : a);

  /// Every approved portfolio item, across all kinds.
  int get portfolioTotal =>
      portfolioCounts.values.fold(0, (sum, count) => sum + count);
}

/// Who a creator's audience is, aggregated server-side across their linked
/// accounts and weighted by followers (`audience` on the profile).
///
/// Every value is a percentage of the audience as reported by the platform
/// sync. The buckets are whatever the sync wrote — this declares none.
class AudienceSummary {
  const AudienceSummary({
    this.accounts = 0,
    this.gender = const {},
    this.age = const {},
    this.locations = const {},
  });

  /// How many linked accounts the split was drawn from.
  final int accounts;
  final Map<String, double> gender;
  final Map<String, double> age;
  final Map<String, double> locations;

  static AudienceSummary? maybeFromJson(dynamic value) {
    final json = Json.mapOrNull(value);
    if (json == null) return null;

    Map<String, double> shares(dynamic raw) {
      final out = <String, double>{};
      Json.map(raw).forEach((bucket, share) {
        final parsed = Json.doubleOrNull(share);
        if (parsed != null) out[bucket] = parsed;
      });
      return out;
    }

    final summary = AudienceSummary(
      accounts: Json.integer(json['accounts']),
      gender: shares(json['gender']),
      age: shares(json['age']),
      locations: shares(json['geo']),
    );

    return summary.isEmpty ? null : summary;
  }

  bool get isEmpty => gender.isEmpty && age.isEmpty && locations.isEmpty;
}

/// Average aspect scores (1–5) from published brand reviews. Each is null when
/// no review answered that question.
class RatingAspects {
  const RatingAspects({
    this.count = 0,
    this.quality,
    this.communication,
    this.timeliness,
  });

  final int count;
  final double? quality;
  final double? communication;
  final double? timeliness;

  static RatingAspects? maybeFromJson(dynamic value) {
    final json = Json.mapOrNull(value);
    if (json == null) return null;

    final aspects = RatingAspects(
      count: Json.integer(json['count']),
      quality: Json.doubleOrNull(json['quality']),
      communication: Json.doubleOrNull(json['communication']),
      timeliness: Json.doubleOrNull(json['timeliness']),
    );

    return aspects.hasAny ? aspects : null;
  }

  bool get hasAny =>
      quality != null || communication != null || timeliness != null;
}

/// A verification badge a creator holds (`creator_badges`).
///
/// The catalogue is an admin-managed taxonomy on the server, so the label,
/// icon and colour all arrive with the badge. Nothing about a badge is
/// declared here — an operator adding a twelfth badge needs no app release.
class VerificationBadge {
  const VerificationBadge({
    required this.value,
    required this.label,
    this.description = '',
    this.icon = 'award',
    this.color,
    this.automatic = false,
    this.awardedAt,
    this.expiresAt,
  });

  final String value;
  final String label;
  final String description;

  /// Icon *name* from the server's catalogue, mapped to a glyph by the widget
  /// that draws it.
  final String icon;

  /// Hex string as sent (`#059669`), or null when the server had none.
  final String? color;

  /// Awarded by a platform rule rather than by an administrator.
  final bool automatic;

  final DateTime? awardedAt;
  final DateTime? expiresAt;

  factory VerificationBadge.fromJson(Map<String, dynamic> json) =>
      VerificationBadge(
        value: Json.str(json['value']),
        label: Json.str(json['label']),
        description: Json.str(json['description']),
        icon: Json.str(json['icon'], 'award'),
        color: Json.strOrNull(json['color']),
        automatic: Json.boolean(json['automatic']),
        awardedAt: Formatters.parseDate(json['awarded_at']),
        expiresAt: Formatters.parseDate(json['expires_at']),
      );

  /// Whether the server also told us this badge is currently held. Present on
  /// the creator's own profile, where the full catalogue is sent so the app can
  /// show what is not yet earned.
  bool get lapsed => expiresAt != null && expiresAt!.isBefore(DateTime.now());
}

/// A linked social account shown on the profile (`creator_social_accounts`).
class SocialAccount {
  const SocialAccount({
    required this.platform,
    required this.handle,
    this.id = 0,
    this.label,
    this.profileUrl,
    this.followers = 0,
    this.following = 0,
    this.posts = 0,
    this.engagementRate = 0,
    this.avgViews = 0,
    this.avgLikes = 0,
    this.avgComments = 0,
    this.isVerified = false,
    this.isPrimary = false,
    this.hasMetrics = true,
    this.icon = 'link',
    this.color,
    this.lastSyncedAt,
  });

  final String platform;
  final String handle;

  /// The `creator_social_accounts` row id — what a `social_click` engagement
  /// event names as its target. 0 on payloads that predate it.
  final int id;

  /// The name the creator gave a custom link ("My Substack"). Null for a real
  /// network, whose name comes from the platform taxonomy.
  final String? label;

  final String? profileUrl;
  final int followers;
  final int following;
  final int posts;
  final double engagementRate;
  final int avgViews;
  final int avgLikes;
  final int avgComments;
  final bool isVerified;
  final bool isPrimary;

  /// False for a website, blog or portfolio link, where follower and
  /// engagement figures do not exist and must not be rendered as zeroes.
  final bool hasMetrics;

  final String icon;
  final String? color;
  final DateTime? lastSyncedAt;

  factory SocialAccount.fromJson(Map<String, dynamic> json) => SocialAccount(
    platform: Json.str(json['platform']),
    handle: Json.str(json['handle']),
    id: Json.integer(json['id']),
    label: Json.strOrNull(json['platform_label'] ?? json['label']),
    profileUrl: Json.strOrNull(json['profile_url']),
    followers: Json.integer(json['followers']),
    following: Json.integer(json['following']),
    posts: Json.integer(json['posts']),
    engagementRate: Json.decimal(json['engagement_rate']),
    avgViews: Json.integer(json['avg_views']),
    avgLikes: Json.integer(json['avg_likes']),
    avgComments: Json.integer(json['avg_comments']),
    isVerified: Json.boolean(json['is_verified']),
    isPrimary: Json.boolean(json['is_primary']),
    // Absent on older payloads, where every account was a real network.
    hasMetrics: Json.boolean(json['has_metrics'], true),
    icon: Json.str(json['icon'], 'link'),
    color: Json.strOrNull(json['color']),
    lastSyncedAt: Formatters.parseDate(json['last_synced_at']),
  );

  String get followersLabel => Formatters.compact(followers);
  String get followingLabel => Formatters.compact(following);
  String get postsLabel => Formatters.compact(posts);

  /// `@aanya.kapoor` — a custom link's "handle" is often a URL or a site
  /// name, which reads wrong with an @ in front, so only bare names get one.
  String get displayHandle {
    final value = handle.trim();
    if (value.isEmpty || value.startsWith('@') || !hasMetrics) return value;
    if (value.contains(' ') || value.contains('/')) return value;
    return '@$value';
  }

  /// The network's display name.
  ///
  /// This used to be a hardcoded switch over eight platforms. The server now
  /// supports sixteen through an admin-managed taxonomy and sends the label
  /// with the account, so Threads, TikTok, Moj, Josh, ShareChat and the
  /// link-only entries no longer fall through to a raw slug. The switch is
  /// kept only as a fallback for a payload that predates `platform_label`.
  String get platformLabel {
    final supplied = label;
    if (supplied != null && supplied.isNotEmpty) return supplied;

    return switch (platform.toLowerCase()) {
      'instagram' => 'Instagram',
      'youtube' => 'YouTube',
      'facebook' => 'Facebook',
      'twitter' || 'x' => 'X',
      'linkedin' => 'LinkedIn',
      'snapchat' => 'Snapchat',
      'pinterest' => 'Pinterest',
      'tiktok' => 'TikTok',
      _ => platform,
    };
  }
}

/// A bookable deliverable bundle (`creator_packages`).
class CreatorPackage {
  const CreatorPackage({
    required this.id,
    required this.title,
    this.description = '',
    this.platform = '',
    this.deliverableType = '',
    this.quantity = 1,
    this.price = 0,
    this.priceFormatted = '',
    this.currency = 'INR',
    this.turnaroundDays = 0,
    this.revisions = 0,
    this.includes = const [],
  });

  final int id;
  final String title;
  final String description;
  final String platform;
  final String deliverableType;
  final int quantity;
  final double price;
  final String priceFormatted;
  final String currency;
  final int turnaroundDays;
  final int revisions;
  final List<String> includes;

  factory CreatorPackage.fromJson(Map<String, dynamic> json) => CreatorPackage(
    id: Json.integer(json['id']),
    title: Json.str(json['title'], 'Package'),
    description: Json.str(json['description']),
    platform: Json.str(json['platform']),
    deliverableType: Json.str(json['deliverable_type']),
    quantity: Json.integer(json['quantity'], 1),
    price: Json.decimal(json['price']),
    priceFormatted: Json.str(
      json['price_formatted'],
      Formatters.currency(Json.decimal(json['price'])),
    ),
    currency: Json.str(json['currency'], 'INR'),
    turnaroundDays: Json.integer(json['turnaround_days']),
    revisions: Json.integer(json['revisions']),
    includes: Json.strings(json['includes']),
  );

  String get turnaroundLabel => Formatters.duration(turnaroundDays);
}

/// One piece of portfolio media (`creator_portfolio`).
class PortfolioItem {
  const PortfolioItem({
    required this.id,
    required this.mediaKind,
    this.title = '',
    this.caption = '',
    this.mediaUrl,
    this.thumbnailUrl,
    this.externalUrl,
    this.sourcePlatform,
    this.aspectRatio = 1,
    this.durationSecs = 0,
    this.viewsCount = 0,
    this.likesCount = 0,
    this.commentsCount = 0,
    this.publishedAt,
  });

  final int id;

  /// `post`, `reel`, `photo`, or `video`.
  final String mediaKind;
  final String title;
  final String caption;
  final String? mediaUrl;
  final String? thumbnailUrl;
  final String? externalUrl;
  final String? sourcePlatform;
  final double aspectRatio;
  final int durationSecs;
  final int viewsCount;
  final int likesCount;
  final int commentsCount;
  final DateTime? publishedAt;

  factory PortfolioItem.fromJson(Map<String, dynamic> json) => PortfolioItem(
    id: Json.integer(json['id']),
    mediaKind: Json.str(json['media_kind'], 'post'),
    title: Json.str(json['title']),
    caption: Json.str(json['caption']),
    mediaUrl: Json.strOrNull(json['media_url']),
    thumbnailUrl: Json.strOrNull(json['thumbnail_url']),
    externalUrl: Json.strOrNull(json['external_url']),
    sourcePlatform: Json.strOrNull(json['source_platform']),
    aspectRatio: _ratio(json['aspect_ratio']),
    durationSecs: Json.integer(json['duration_secs']),
    viewsCount: Json.integer(json['views_count']),
    likesCount: Json.integer(json['likes_count']),
    commentsCount: Json.integer(json['comments_count']),
    publishedAt: Formatters.parseDate(json['published_at']),
  );

  /// Width over height. The server stores the column as `"9:16"`, which a
  /// plain number parse turned into the 1.0 fallback for every item; a bare
  /// number is still accepted.
  static double _ratio(dynamic value) {
    final text = Json.str(value).trim();
    final parts = text.split(':');

    if (parts.length == 2) {
      final w = double.tryParse(parts[0].trim());
      final h = double.tryParse(parts[1].trim());
      if (w != null && h != null && w > 0 && h > 0) return w / h;
    }

    final number = double.tryParse(text);
    return number != null && number > 0 ? number : 1;
  }

  /// Prefer the thumbnail; fall back to the full asset.
  String? get displayUrl => thumbnailUrl ?? mediaUrl;

  bool get isVideo => mediaKind == 'reel' || mediaKind == 'video';

  String get viewsLabel => Formatters.compact(viewsCount);
  String get likesLabel => Formatters.compact(likesCount);

  /// `1:23`
  String get durationLabel {
    if (durationSecs <= 0) return '';
    final minutes = durationSecs ~/ 60;
    final seconds = durationSecs % 60;
    return '$minutes:${seconds.toString().padLeft(2, '0')}';
  }
}

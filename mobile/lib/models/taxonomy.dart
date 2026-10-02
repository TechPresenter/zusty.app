import '../core/utils/json.dart';

/// One option in an admin-managed list.
///
/// Every dropdown, chip row and filter in the app is built from these. The
/// `value` is what the API stores; the `label` is what the user reads, and an
/// admin can change it without a release.
class TaxonomyTerm {
  const TaxonomyTerm({
    required this.value,
    required this.label,
    this.description,
    this.icon,
    this.color,
    this.imageUrl,
    this.metadata = const {},
    this.isDefault = false,
  });

  final String value;
  final String label;
  final String? description;

  /// Material Symbols name chosen in the admin panel.
  final String? icon;

  /// `#RRGGBB`, when the admin set one.
  final String? color;
  final String? imageUrl;

  /// Free-form extras — `rank` for ordered comparisons, `amount` for a
  /// top-up preset, `tone` for a status chip, `min`/`max` for a band.
  final Map<String, dynamic> metadata;

  final bool isDefault;

  factory TaxonomyTerm.fromJson(Map<String, dynamic> json) => TaxonomyTerm(
        value: Json.str(json['value']),
        label: Json.str(json['label']),
        description: Json.strOrNull(json['description']),
        icon: Json.strOrNull(json['icon']),
        color: Json.strOrNull(json['color']),
        imageUrl: Json.strOrNull(json['image_url']),
        metadata: Json.map(json['metadata']),
        isDefault: Json.boolean(json['is_default']),
      );

  Map<String, dynamic> toJson() => {
        'value': value,
        'label': label,
        if (description != null) 'description': description,
        if (icon != null) 'icon': icon,
        if (color != null) 'color': color,
        if (imageUrl != null) 'image_url': imageUrl,
        if (metadata.isNotEmpty) 'metadata': metadata,
        'is_default': isDefault,
      };

  /// Numeric ordering hint, used where a comparison must survive an admin
  /// reordering the list — "responds within 6h" must still match a creator who
  /// responds within 1h regardless of display order.
  int rank({int fallback = 99}) {
    final value = metadata['rank'];
    if (value is num) return value.toInt();
    return int.tryParse('$value') ?? fallback;
  }

  /// Numeric payload, e.g. a wallet top-up preset amount.
  double? get amount {
    final value = metadata['amount'];
    if (value is num) return value.toDouble();
    return double.tryParse('$value');
  }

  /// Status-chip tone supplied by the admin, e.g. `success`.
  String? get tone => Json.strOrNull(metadata['tone']);
}

/// The bundled option lists, fetched once and cached on device.
///
/// Fetching everything in one call keeps launch to a single request and makes
/// the whole set consistent — a screen can never render half-old options.
class TaxonomyBundle {
  const TaxonomyBundle({
    required this.version,
    this.taxonomies = const {},
    this.maxBudget = 200000,
    this.maxFollowers = 1000000,
    this.defaultPageSize = 20,
    this.trendingSearches = const [],
  });

  /// Stamp that changes whenever any term is added, edited or removed. Sent
  /// back as an `If-None-Match` header so an unchanged bundle costs a 304.
  final String version;

  final Map<String, List<TaxonomyTerm>> taxonomies;

  // Numeric bounds that used to be compile-time constants.
  final double maxBudget;
  final int maxFollowers;
  final int defaultPageSize;
  final List<String> trendingSearches;

  factory TaxonomyBundle.fromJson(Map<String, dynamic> json) {
    final lists = <String, List<TaxonomyTerm>>{};

    Json.map(json['taxonomies']).forEach((key, value) {
      lists[key] = Json.list(value, TaxonomyTerm.fromJson);
    });

    final discovery = Json.map(json['discovery']);

    return TaxonomyBundle(
      version: Json.str(json['version']),
      taxonomies: lists,
      maxBudget: Json.decimal(discovery['max_budget'], 200000),
      maxFollowers: Json.integer(discovery['max_followers'], 1000000),
      defaultPageSize: Json.integer(discovery['default_page_size'], 20),
      trendingSearches: Json.strings(discovery['trending_searches']),
    );
  }

  Map<String, dynamic> toJson() => {
        'version': version,
        'taxonomies': taxonomies.map(
          (key, terms) => MapEntry(key, terms.map((t) => t.toJson()).toList()),
        ),
        'discovery': {
          'max_budget': maxBudget,
          'max_followers': maxFollowers,
          'default_page_size': defaultPageSize,
          'trending_searches': trendingSearches,
        },
      };

  bool get isEmpty => taxonomies.isEmpty;

  /// Terms for [key], or an empty list when the admin has not defined it.
  List<TaxonomyTerm> terms(String key) => taxonomies[key] ?? const [];

  /// `value → label`, the shape most chip rows want.
  Map<String, String> map(String key) => {
        for (final term in terms(key)) term.value: term.label,
      };

  /// The label for one value, falling back to a humanised version of the
  /// value so an unknown option never renders as a raw enum string.
  String label(String key, String? value) {
    if (value == null || value.isEmpty) return '';

    for (final term in terms(key)) {
      if (term.value == value) return term.label;
    }

    return value
        .split('_')
        .map((word) => word.isEmpty ? word : word[0].toUpperCase() + word.substring(1))
        .join(' ');
  }

  TaxonomyTerm? term(String key, String? value) {
    if (value == null) return null;
    for (final t in terms(key)) {
      if (t.value == value) return t;
    }
    return null;
  }

  /// The term the admin flagged as pre-selected.
  String? defaultValue(String key) {
    for (final term in terms(key)) {
      if (term.isDefault) return term.value;
    }
    return terms(key).isEmpty ? null : terms(key).first.value;
  }

  /// Ordering rank for a value, from its term metadata.
  int rank(String key, String? value, {int fallback = 99}) =>
      term(key, value)?.rank(fallback: fallback) ?? fallback;
}

/// The taxonomy keys the app reads.
///
/// Constants rather than bare strings so a typo is a compile error and every
/// usage is findable.
abstract final class Tax {
  static const creatorSort = 'creator_sort';
  static const platform = 'platform';
  static const socialPlatform = 'social_platform';
  static const language = 'language';
  static const gender = 'gender';
  static const ageGroup = 'age_group';
  static const experience = 'experience_level';
  static const responseTime = 'response_time';
  static const ratingFilter = 'rating_filter';
  static const engagementFilter = 'engagement_filter';
  static const campaignType = 'campaign_type';
  static const deliverableType = 'deliverable_type';
  static const portfolioKind = 'portfolio_kind';
  static const industry = 'industry';
  static const companyType = 'company_type';
  static const companySize = 'company_size';
  static const supportTopic = 'support_topic';
  static const faqCategory = 'faq_category';
  static const ticketCategory = 'ticket_category';
  static const idProofType = 'id_proof_type';
  static const city = 'city';
  static const topupPreset = 'topup_preset';
  static const bookingStatus = 'booking_status';
}

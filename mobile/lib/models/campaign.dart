import '../core/utils/formatters.dart';
import '../core/utils/json.dart';
import 'booking.dart' show StatusTone;
import 'creator.dart';

/// Lifecycle of a brand campaign, mirroring the `campaigns.status` enum.
enum CampaignStatus {
  draft,
  open,
  inProgress,
  completed,
  cancelled,
  archived;

  static CampaignStatus fromString(String? value) =>
      switch (value?.toLowerCase()) {
        'open' => CampaignStatus.open,
        'in_progress' => CampaignStatus.inProgress,
        'completed' => CampaignStatus.completed,
        'cancelled' => CampaignStatus.cancelled,
        'archived' => CampaignStatus.archived,
        _ => CampaignStatus.draft,
      };

  String get value => switch (this) {
        CampaignStatus.inProgress => 'in_progress',
        _ => name,
      };

  String get label => switch (this) {
        CampaignStatus.draft => 'Draft',
        CampaignStatus.open => 'Open',
        CampaignStatus.inProgress => 'In progress',
        CampaignStatus.completed => 'Completed',
        CampaignStatus.cancelled => 'Cancelled',
        CampaignStatus.archived => 'Archived',
      };

  StatusTone get tone => switch (this) {
        CampaignStatus.draft => StatusTone.neutral,
        CampaignStatus.open => StatusTone.warning,
        CampaignStatus.inProgress => StatusTone.info,
        CampaignStatus.completed => StatusTone.success,
        CampaignStatus.cancelled || CampaignStatus.archived => StatusTone.error,
      };

  /// Still accepting applications or running.
  bool get isLive =>
      this == CampaignStatus.open || this == CampaignStatus.inProgress;

  bool get isEditable =>
      this == CampaignStatus.draft || this == CampaignStatus.open;
}

/// Targeting rules stored in `campaigns.requirements` as JSON.
class CampaignRequirements {
  const CampaignRequirements({
    this.minFollowers,
    this.cities = const [],
    this.gender,
    this.minRating,
    this.languages = const [],
    this.maxFollowers,
    this.minEngagementRate,
    this.ageRange,
    this.deliverables = const [],
    this.usageRightsDays,
    this.exclusivityDays,
    this.barterOnly = false,
  });

  final int? minFollowers;
  final List<String> cities;

  /// `any`, `female`, `male`, `non_binary`.
  final String? gender;
  final double? minRating;
  final List<String> languages;

  // -- Read-only brief terms -----------------------------------------------
  //
  // `requirements` is free JSON, and briefs created outside the app's form
  // (the admin panel, imports, the seeded catalogue) carry more than the five
  // targeting fields above: the shot list, usage rights, exclusivity. A
  // creator deciding whether to apply needs exactly those terms, so they are
  // read here for display.
  //
  // They are deliberately left out of [toJson], [isEmpty] and [summary]: the
  // brand form writes `requirements` back whole, and those three define what
  // that form owns. Adding them there would change what a brand's save sends.

  /// Upper follower bound, when the brand wants mid-tier rather than mega.
  final int? maxFollowers;

  /// Minimum engagement rate, as a percentage (`3.8` is 3.8%).
  final double? minEngagementRate;

  /// Free text such as `21-38`.
  final String? ageRange;

  /// The shot list, one line per deliverable.
  final List<String> deliverables;

  /// How long the brand may reuse the content, in days.
  final int? usageRightsDays;

  /// How long the creator must not promote a competitor, in days.
  final int? exclusivityDays;

  /// Paid in product rather than money.
  final bool barterOnly;

  factory CampaignRequirements.fromJson(Map<String, dynamic> json) =>
      CampaignRequirements(
        minFollowers: Json.intOrNull(json['min_followers']),
        cities: Json.strings(json['cities']),
        gender: Json.strOrNull(json['gender']),
        minRating: Json.doubleOrNull(json['min_rating']),
        languages: Json.strings(json['languages']),
        maxFollowers: Json.intOrNull(json['max_followers']),
        minEngagementRate: Json.doubleOrNull(json['min_engagement_rate']),
        ageRange: Json.strOrNull(json['age_range']),
        deliverables: Json.strings(json['deliverables']),
        usageRightsDays: Json.intOrNull(json['usage_rights_days']),
        exclusivityDays: Json.intOrNull(json['exclusivity_days']),
        barterOnly: Json.boolean(json['barter_only']),
      );

  /// True when the brief names who it wants in any way a creator can check
  /// themselves against — the targeting fields plus the read-only ones.
  bool get hasAudienceRules =>
      !isEmpty ||
      maxFollowers != null ||
      minEngagementRate != null ||
      (ageRange != null && ageRange!.isNotEmpty);

  /// Usage rights, exclusivity and barter — the commercial small print.
  bool get hasTerms =>
      usageRightsDays != null || exclusivityDays != null || barterOnly;

  Map<String, dynamic> toJson() => {
        if (minFollowers != null) 'min_followers': minFollowers,
        if (cities.isNotEmpty) 'cities': cities,
        if (gender != null && gender != 'any') 'gender': gender,
        if (minRating != null) 'min_rating': minRating,
        if (languages.isNotEmpty) 'languages': languages,
      };

  bool get isEmpty =>
      minFollowers == null &&
      cities.isEmpty &&
      (gender == null || gender == 'any') &&
      minRating == null &&
      languages.isEmpty;

  /// Human summary for the campaign card, e.g.
  /// `50K+ followers · Mumbai, Pune · 4.5★+`.
  List<String> get summary => [
        if (minFollowers != null)
          '${Formatters.compact(minFollowers)}+ followers',
        if (cities.isNotEmpty) cities.join(', '),
        if (minRating != null) '${minRating!.toStringAsFixed(1)}★+',
        if (gender != null && gender != 'any') _genderLabel,
        if (languages.isNotEmpty) languages.join(', '),
      ];

  String get _genderLabel => switch (gender) {
        'female' => 'Female creators',
        'male' => 'Male creators',
        'non_binary' => 'Non-binary creators',
        _ => '',
      };

  CampaignRequirements copyWith({
    Object? minFollowers = _keep,
    List<String>? cities,
    Object? gender = _keep,
    Object? minRating = _keep,
    List<String>? languages,
  }) =>
      CampaignRequirements(
        minFollowers: identical(minFollowers, _keep)
            ? this.minFollowers
            : minFollowers as int?,
        cities: cities ?? this.cities,
        gender: identical(gender, _keep) ? this.gender : gender as String?,
        minRating:
            identical(minRating, _keep) ? this.minRating : minRating as double?,
        languages: languages ?? this.languages,
        // Carried through untouched so an in-memory copy never silently loses
        // them; [toJson] still decides what is sent.
        maxFollowers: maxFollowers,
        minEngagementRate: minEngagementRate,
        ageRange: ageRange,
        deliverables: deliverables,
        usageRightsDays: usageRightsDays,
        exclusivityDays: exclusivityDays,
        barterOnly: barterOnly,
      );
}

/// The brand behind a campaign, as the API embeds it (`brand{id,name,
/// avatar_url,is_verified}`). `name` is the company name when the brand set
/// one.
class CampaignBrand {
  const CampaignBrand({
    required this.id,
    required this.name,
    this.avatarUrl,
    this.isVerified = false,
  });

  final int id;
  final String name;
  final String? avatarUrl;

  /// The blue tick — granted by the Zusty team after KYC approval, never
  /// inferred. Absent from older payloads, which read as not verified.
  final bool isVerified;

  factory CampaignBrand.fromJson(Map<String, dynamic> json) => CampaignBrand(
        id: Json.integer(json['id']),
        name: Json.str(json['name']),
        avatarUrl: Json.strOrNull(json['avatar_url']),
        isVerified: Json.boolean(json['is_verified']),
      );
}

const Object _keep = Object();

/// A brand campaign — an open brief creators can be invited to or apply for.
///
/// Distinct from a [Booking]: a campaign is one-to-many (many creators against
/// one brief), while a booking is the individual contract with one creator.
class Campaign {
  const Campaign({
    required this.id,
    required this.code,
    required this.title,
    required this.description,
    required this.status,
    this.categoryId,
    this.categoryName,
    this.platform = 'instagram',
    this.deliverableType = 'post',
    this.postsCount = 1,
    this.budgetMin = 0,
    this.budgetMax = 0,
    this.currency = 'INR',
    this.briefUrl,
    this.referenceUrls = const [],
    this.requirements = const CampaignRequirements(),
    this.startsOn,
    this.endsOn,
    this.applicationsCount = 0,
    this.hiredCount = 0,
    this.isPublic = false,
    this.recipients = const [],
    this.createdAt,
    this.brand,
    this.myApplication,
    this.serverBudgetLabel,
    this.statusLabel,
  });

  final int id;

  /// Human-readable reference, e.g. `ZC100045`.
  final String code;
  final String title;
  final String description;
  final CampaignStatus status;
  final int? categoryId;
  final String? categoryName;
  final String platform;
  final String deliverableType;
  final int postsCount;
  final double budgetMin;
  final double budgetMax;
  final String currency;
  final String? briefUrl;
  final List<String> referenceUrls;
  final CampaignRequirements requirements;
  final DateTime? startsOn;
  final DateTime? endsOn;
  final int applicationsCount;
  final int hiredCount;

  /// Listed in the creator-facing opportunities feed.
  final bool isPublic;

  /// Populated on the detail endpoint only.
  final List<CampaignRecipient> recipients;
  final DateTime? createdAt;

  /// Who posted the brief. Null only on payloads that predate the field.
  final CampaignBrand? brand;

  /// The viewing creator's own application, or null when they have none.
  ///
  /// Full on `GET /campaigns/{id}`; on the `scope=open` list it is a slice
  /// (status, quote, booking) — enough to label a card, without the pitch.
  final MyApplication? myApplication;

  /// The server's own `budget_label`, formatted in the campaign's currency.
  /// [budgetLabel] is the locally formatted fallback the brand screens use.
  final String? serverBudgetLabel;

  /// The server's `status_label`.
  final String? statusLabel;

  factory Campaign.fromJson(Map<String, dynamic> json) => Campaign(
        id: Json.integer(json['id']),
        code: Json.str(json['code']),
        title: Json.str(json['title'], 'Untitled campaign'),
        description: Json.str(json['description']),
        status: CampaignStatus.fromString(Json.strOrNull(json['status'])),
        categoryId: Json.intOrNull(json['category_id']),
        categoryName: Json.strOrNull(json['category_name']),
        platform: Json.str(json['platform'], 'instagram'),
        deliverableType: Json.str(json['deliverable_type'], 'post'),
        postsCount: Json.integer(json['posts_count'], 1),
        budgetMin: Json.decimal(json['budget_min']),
        budgetMax: Json.decimal(json['budget_max']),
        currency: Json.str(json['currency'], 'INR'),
        briefUrl: Json.strOrNull(json['brief_url']),
        referenceUrls: Json.strings(json['reference_urls']),
        requirements:
            CampaignRequirements.fromJson(Json.map(json['requirements'])),
        startsOn: Formatters.parseDate(json['starts_on']),
        endsOn: Formatters.parseDate(json['ends_on']),
        applicationsCount: Json.integer(json['applications_count']),
        hiredCount: Json.integer(json['hired_count']),
        isPublic: Json.boolean(json['is_public']),
        recipients:
            Json.list(json['recipients'], CampaignRecipient.fromJson),
        createdAt: Formatters.parseDate(json['created_at']),
        brand: switch (Json.mapOrNull(json['brand'])) {
          final map? when map.isNotEmpty => CampaignBrand.fromJson(map),
          _ => null,
        },
        myApplication: switch (Json.mapOrNull(json['my_application'])) {
          final map? when map.isNotEmpty => MyApplication.fromJson(map),
          _ => null,
        },
        serverBudgetLabel: Json.strOrNull(json['budget_label']),
        statusLabel: Json.strOrNull(json['status_label']),
      );

  /// `₹15,000 – ₹50,000`
  String get budgetLabel =>
      Formatters.currencyRange(budgetMin, budgetMax);

  /// The budget as the creator should read it: the server's label when sent
  /// (it knows the currency), otherwise the local format.
  String get displayBudget {
    final server = serverBudgetLabel?.trim();
    return server == null || server.isEmpty ? budgetLabel : server;
  }

  /// Accepting applications right now. `in_progress` briefs stay on the board
  /// so creators can see them, but the API refuses new applications to them.
  bool get isAcceptingApplications => status == CampaignStatus.open;

  /// `2 Reels`, `1 Post` — the deliverable count without the platform, for
  /// places that show the platform separately.
  String get deliverableShortLabel {
    final type = deliverableType.isEmpty ? 'post' : deliverableType;
    final plural = postsCount == 1 ? type : '${type}s';
    final word = switch (type) {
      'ugc' => postsCount == 1 ? 'UGC video' : 'UGC videos',
      _ => '${plural[0].toUpperCase()}${plural.substring(1)}',
    };
    return '$postsCount $word';
  }

  /// Whole days from today until [endsOn]; negative once it has passed, null
  /// when the brief has no end date.
  int? get daysLeft {
    final end = endsOn;
    if (end == null) return null;
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    return DateTime(end.year, end.month, end.day).difference(today).inDays;
  }

  /// `3 Reels on Instagram`
  String get deliverableLabel {
    final type = deliverableType.isEmpty ? 'post' : deliverableType;
    final plural = postsCount == 1 ? type : '${type}s';
    return '$postsCount ${plural[0].toUpperCase()}${plural.substring(1)} '
        'on $platformLabel';
  }

  String get platformLabel => switch (platform) {
        'instagram' => 'Instagram',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'facebook' => 'Facebook',
        'twitter' => 'X',
        'linkedin' => 'LinkedIn',
        'multi' => 'Multiple platforms',
        _ => platform,
      };

  /// `1 Aug – 14 Aug 2026`
  String get timelineLabel {
    if (startsOn == null && endsOn == null) return 'Flexible timeline';
    if (endsOn == null) return 'From ${Formatters.date(startsOn)}';
    if (startsOn == null) return 'By ${Formatters.date(endsOn)}';
    return '${Formatters.date(startsOn)} → ${Formatters.date(endsOn)}';
  }

  int get shortlistedCount =>
      recipients.where((r) => r.status == RecipientStatus.shortlisted).length;

  Campaign copyWith({CampaignStatus? status, bool? isPublic}) => Campaign(
        id: id,
        code: code,
        title: title,
        description: description,
        status: status ?? this.status,
        categoryId: categoryId,
        categoryName: categoryName,
        platform: platform,
        deliverableType: deliverableType,
        postsCount: postsCount,
        budgetMin: budgetMin,
        budgetMax: budgetMax,
        currency: currency,
        briefUrl: briefUrl,
        referenceUrls: referenceUrls,
        requirements: requirements,
        startsOn: startsOn,
        endsOn: endsOn,
        applicationsCount: applicationsCount,
        hiredCount: hiredCount,
        isPublic: isPublic ?? this.isPublic,
        recipients: recipients,
        createdAt: createdAt,
        brand: brand,
        myApplication: myApplication,
        serverBudgetLabel: serverBudgetLabel,
        statusLabel: statusLabel,
      );

}

/// Where one creator sits in a campaign's pipeline
/// (`campaign_recipients.status`).
enum RecipientStatus {
  invited,
  applied,
  shortlisted,
  hired,
  declined;

  static RecipientStatus fromString(String? value) =>
      switch (value?.toLowerCase()) {
        'applied' => RecipientStatus.applied,
        'shortlisted' => RecipientStatus.shortlisted,
        'hired' => RecipientStatus.hired,
        'declined' || 'rejected' => RecipientStatus.declined,
        _ => RecipientStatus.invited,
      };

  String get label => switch (this) {
        RecipientStatus.invited => 'Invited',
        RecipientStatus.applied => 'Applied',
        RecipientStatus.shortlisted => 'Shortlisted',
        RecipientStatus.hired => 'Hired',
        RecipientStatus.declined => 'Declined',
      };

  StatusTone get tone => switch (this) {
        RecipientStatus.invited => StatusTone.neutral,
        RecipientStatus.applied => StatusTone.warning,
        RecipientStatus.shortlisted => StatusTone.info,
        RecipientStatus.hired => StatusTone.success,
        RecipientStatus.declined => StatusTone.error,
      };
}

/// A creator in a campaign's pipeline.
class CampaignRecipient {
  const CampaignRecipient({
    required this.id,
    required this.creator,
    required this.status,
    this.quotedAmount,
    this.pitch,
    this.bookingId,
    this.respondedAt,
    this.appliedAt,
    this.portfolioLinks = const [],
  });

  final int id;
  final Creator creator;
  final RecipientStatus status;

  /// What the creator asked for when applying.
  final double? quotedAmount;
  final String? pitch;

  /// Set once the brand hires them and a booking is created.
  final int? bookingId;
  final DateTime? respondedAt;
  final DateTime? appliedAt;

  /// Work the creator linked when applying, newest first as they entered it.
  final List<String> portfolioLinks;

  factory CampaignRecipient.fromJson(Map<String, dynamic> json) =>
      CampaignRecipient(
        id: Json.integer(json['id']),
        creator: Creator.fromJson(Json.map(json['creator']).isEmpty
            ? json
            : Json.map(json['creator'])),
        status: RecipientStatus.fromString(Json.strOrNull(json['status'])),
        quotedAmount: Json.doubleOrNull(json['quoted_amount']),
        pitch: Json.strOrNull(json['pitch']),
        bookingId: Json.intOrNull(json['booking_id']),
        respondedAt: Formatters.parseDate(json['responded_at']),
        appliedAt: Formatters.parseDate(json['applied_at']),
        portfolioLinks: Json.strings(json['portfolio_links']),
      );

  String get quoteLabel =>
      quotedAmount == null ? '—' : Formatters.currency(quotedAmount);

  CampaignRecipient copyWith({RecipientStatus? status}) => CampaignRecipient(
        id: id,
        creator: creator,
        status: status ?? this.status,
        quotedAmount: quotedAmount,
        pitch: pitch,
        bookingId: bookingId,
        respondedAt: respondedAt,
      );
}

/// The signed-in creator's own application to one campaign — `my_application`
/// on the campaign payload, and the application half of each `/me/applications`
/// row.
///
/// Distinct from [CampaignRecipient], which is the BRAND's view of the same
/// row and carries the applicant's full creator card. A creator never needs
/// their own card back, but does need what the brand decided and why.
class MyApplication {
  const MyApplication({
    required this.id,
    required this.status,
    this.statusLabel,
    this.quotedAmount,
    this.quotedAmountLabel,
    this.currency = 'INR',
    this.pitch,
    this.portfolioLinks = const [],
    this.bookingId,
    this.declineReason,
    this.invitedAt,
    this.appliedAt,
    this.respondedAt,
    this.createdAt,
  });

  final int id;
  final RecipientStatus status;

  /// The server's label, preferred over [RecipientStatus.label] when present.
  final String? statusLabel;

  /// The rate the creator quoted. Null on an invitation not yet answered.
  final double? quotedAmount;

  /// `₹30,000`, formatted server-side in [currency].
  final String? quotedAmountLabel;
  final String currency;
  final String? pitch;
  final List<String> portfolioLinks;

  /// Set once the brand hires and the hire becomes a booking.
  final int? bookingId;

  /// Why the brand passed. Only ever set when [status] is declined, and only
  /// when the brand chose to say.
  final String? declineReason;
  final DateTime? invitedAt;
  final DateTime? appliedAt;

  /// When the brand last changed the status.
  final DateTime? respondedAt;
  final DateTime? createdAt;

  factory MyApplication.fromJson(Map<String, dynamic> json) => MyApplication(
        id: Json.integer(json['id']),
        status: RecipientStatus.fromString(Json.strOrNull(json['status'])),
        statusLabel: Json.strOrNull(json['status_label']),
        quotedAmount: Json.doubleOrNull(json['quoted_amount']),
        quotedAmountLabel: Json.strOrNull(json['quoted_amount_label']),
        currency: Json.str(json['currency'], 'INR'),
        pitch: Json.strOrNull(json['pitch']),
        portfolioLinks: Json.strings(json['portfolio_links']),
        bookingId: Json.intOrNull(json['booking_id']),
        declineReason: Json.strOrNull(json['decline_reason']),
        invitedAt: Formatters.parseDate(json['invited_at']),
        appliedAt: Formatters.parseDate(json['applied_at']),
        respondedAt: Formatters.parseDate(json['responded_at']),
        createdAt: Formatters.parseDate(json['created_at']),
      );

  String get label => statusLabel ?? status.label;

  /// `₹30,000`, or null when nothing has been quoted yet.
  String? get quoteLabel {
    final server = quotedAmountLabel?.trim();
    if (server != null && server.isNotEmpty) return server;
    return quotedAmount == null ? null : Formatters.currency(quotedAmount);
  }

  /// Still waiting on somebody: the creator (invited) or the brand.
  bool get isActive =>
      status == RecipientStatus.invited ||
      status == RecipientStatus.applied ||
      status == RecipientStatus.shortlisted;

  /// An invitation the creator can still turn into an application. Once they
  /// have applied, re-submitting would reset a shortlisted application back
  /// to "applied" server-side, so the app only offers it for invitations.
  bool get canApply => status == RecipientStatus.invited;

  /// When the creator last acted: applied, or failing that, was invited.
  DateTime? get lastActivityAt => appliedAt ?? invitedAt ?? createdAt;
}

/// One row of `GET /me/applications`: the creator's application plus the
/// slice of its campaign a list card needs.
///
/// The campaign is parsed with [Campaign.fromJson], so every getter the card
/// uses (budget, deliverables, deadline) behaves exactly as it does on the
/// opportunities board. Fields the list leaves out — the description, the
/// requirements — come back empty rather than missing.
class CampaignApplication {
  const CampaignApplication({required this.application, required this.campaign});

  final MyApplication application;
  final Campaign campaign;

  factory CampaignApplication.fromJson(Map<String, dynamic> json) =>
      CampaignApplication(
        application: MyApplication.fromJson(json),
        campaign: Campaign.fromJson(Json.map(json['campaign'])),
      );

  int get id => application.id;
  RecipientStatus get status => application.status;
}

/// A page of the creator's applications plus the per-status totals the
/// server sends in `meta.counts`, so tab badges need no extra request.
class ApplicationList {
  const ApplicationList({
    required this.items,
    this.counts = const {},
    this.hasMore = false,
    this.total = 0,
  });

  final List<CampaignApplication> items;

  /// Totals across ALL of the creator's applications, not just this page.
  final Map<RecipientStatus, int> counts;
  final bool hasMore;
  final int total;

  bool get isEmpty => items.isEmpty;

  /// The sum of [counts] for [statuses].
  int countOf(Iterable<RecipientStatus> statuses) =>
      statuses.fold(0, (sum, status) => sum + (counts[status] ?? 0));
}

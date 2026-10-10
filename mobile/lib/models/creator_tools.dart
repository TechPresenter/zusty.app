import '../core/network/api_response.dart';
import '../core/utils/json.dart';
import 'creator.dart';

/// The creator's own editors — packages, portfolio and linked accounts.
///
/// The public profile carries the same rows, but only the ones a brand may
/// see: active packages, approved portfolio items. These wrap the shared
/// models with what only the owner needs — whether a package is hidden,
/// whether an item is still in review — and the limits the API enforces, so
/// a form can refuse what the server would before the creator presses Save.

// =====================================================================
// Packages
// =====================================================================

/// One of the creator's packages, hidden ones included.
class OwnPackage {
  const OwnPackage({required this.package, this.isActive = true});

  final CreatorPackage package;

  /// False when the creator has hidden it from their profile. A hidden
  /// package keeps its bookings; it just cannot be picked for a new one.
  final bool isActive;

  int get id => package.id;

  factory OwnPackage.fromJson(Map<String, dynamic> json) => OwnPackage(
    package: CreatorPackage.fromJson(json),
    isActive: Json.boolean(json['is_active'], true),
  );
}

/// What `/me/creator/packages` validates against.
///
/// The price bounds are the booking minimum and maximum: once a brand picks a
/// package its price IS the booking amount, so a package outside them could
/// never be booked. Defaults match the server's own defaults, for the moment
/// before the list has loaded.
class PackageLimits {
  const PackageLimits({
    this.minPrice = 1000,
    this.maxPrice = 2000000,
    this.maxIncludes = 10,
    this.includeMaxLength = 120,
  });

  final double minPrice;
  final double maxPrice;
  final int maxIncludes;
  final int includeMaxLength;

  factory PackageLimits.fromJson(Map<String, dynamic> json) {
    const fallback = PackageLimits();

    return PackageLimits(
      minPrice: Json.decimal(json['min_price'], fallback.minPrice),
      maxPrice: Json.decimal(json['max_price'], fallback.maxPrice),
      maxIncludes: Json.integer(json['max_includes'], fallback.maxIncludes),
      includeMaxLength: Json.integer(
        json['include_max_length'],
        fallback.includeMaxLength,
      ),
    );
  }
}

/// The packages editor's whole state, as `GET /me/creator/packages` sends it.
class OwnPackages {
  const OwnPackages({
    this.packages = const [],
    this.locked = false,
    this.limits = const PackageLimits(),
  });

  final List<OwnPackage> packages;

  /// An administrator has frozen pricing. Writes are refused with their
  /// reason; the screen says so up front instead of letting a form fail.
  final bool locked;
  final PackageLimits limits;

  factory OwnPackages.fromJson(Map<String, dynamic> json) => OwnPackages(
    packages: Json.list(json['packages'], OwnPackage.fromJson),
    locked: Json.boolean(json['locked']),
    limits: PackageLimits.fromJson(Json.map(json['limits'])),
  );

  OwnPackage? byId(int id) {
    for (final package in packages) {
      if (package.id == id) return package;
    }
    return null;
  }

  /// The cheapest live package — what the profile will call "Starting price".
  OwnPackage? get startingPackage {
    OwnPackage? cheapest;
    for (final package in packages.where((p) => p.isActive)) {
      if (cheapest == null || package.package.price < cheapest.package.price) {
        cheapest = package;
      }
    }
    return cheapest;
  }
}

/// A package as the form submits it.
///
/// Every field the API accepts, and nothing else: `offering_type`,
/// `pricing_model` and `price_max` exist on the table but no endpoint writes
/// them, so the form does not offer them.
class PackageDraft {
  const PackageDraft({
    required this.title,
    required this.platform,
    required this.deliverableType,
    required this.price,
    this.description = '',
    this.quantity = 1,
    this.turnaroundDays = 7,
    this.revisions = 1,
    this.includes = const [],
    this.isActive,
  });

  final String title;
  final String description;
  final String platform;
  final String deliverableType;
  final int quantity;
  final double price;
  final int turnaroundDays;
  final int revisions;
  final List<String> includes;

  /// Only on an update: a new package is always created live, and the API's
  /// create rules do not read it.
  final bool? isActive;

  /// The body for both create and update.
  ///
  /// Sent whole on an update, with an emptied description as null and an
  /// emptied list as `[]`, because the server clears exactly what it is sent
  /// and keeps whatever it is not.
  Map<String, dynamic> toJson() => {
    'title': title.trim(),
    'description': description.trim().isEmpty ? null : description.trim(),
    'platform': platform,
    'deliverable_type': deliverableType,
    'quantity': quantity,
    // Whole rupees go up as an integer, so the API stores what was typed
    // rather than a float's nearest neighbour.
    'price': price == price.roundToDouble() ? price.round() : price,
    'turnaround_days': turnaroundDays,
    'revisions': revisions,
    'includes': [
      for (final line in includes)
        if (line.trim().isNotEmpty) line.trim(),
    ],
    'is_active': ?isActive,
  };
}

// =====================================================================
// Portfolio
// =====================================================================

/// Where one portfolio item stands with the moderation team.
enum PortfolioReview {
  approved,
  pending,
  rejected;

  static PortfolioReview from(String? value) => switch (value) {
    'pending' => PortfolioReview.pending,
    'rejected' => PortfolioReview.rejected,
    _ => PortfolioReview.approved,
  };
}

/// One of the creator's own portfolio items, whatever its review state.
class OwnPortfolioItem {
  const OwnPortfolioItem({
    required this.item,
    this.review = PortfolioReview.approved,
    this.reasonLabel,
    this.isActive = true,
  });

  final PortfolioItem item;
  final PortfolioReview review;

  /// The moderation reason's label when the team turned it down. Never the
  /// moderator's own note, which the API does not send.
  final String? reasonLabel;
  final bool isActive;

  int get id => item.id;

  /// Brands can see it: approved and not hidden.
  bool get isPublic => review == PortfolioReview.approved && isActive;

  factory OwnPortfolioItem.fromJson(Map<String, dynamic> json) =>
      OwnPortfolioItem(
        item: PortfolioItem.fromJson(json),
        review: PortfolioReview.from(Json.strOrNull(json['moderation_status'])),
        reasonLabel: Json.strOrNull(json['reason_label']),
        isActive: Json.boolean(json['is_active'], true),
      );
}

/// One page of `GET /me/creator/portfolio`: the rows, whether an
/// administrator has locked the section, and the item ceiling
/// (`creator.max_portfolio_items`; 0 means none).
typedef OwnPortfolioPage = ({
  Paginated<OwnPortfolioItem> page,
  bool locked,
  int maxItems,
});

/// What one "add to portfolio" sends besides the image itself.
class PortfolioDraft {
  const PortfolioDraft({
    required this.mediaKind,
    this.title = '',
    this.caption = '',
    this.externalUrl = '',
  });

  /// A `portfolio_kind` term — which tab of the profile it appears under.
  final String mediaKind;
  final String title;
  final String caption;

  /// Where the original lives. For a reel or a video this is what plays:
  /// the upload is its cover.
  final String externalUrl;

  /// Multipart fields. Blank ones are left out rather than sent empty.
  Map<String, dynamic> toFields() => {
    'media_kind': mediaKind,
    if (title.trim().isNotEmpty) 'title': title.trim(),
    if (caption.trim().isNotEmpty) 'caption': caption.trim(),
    if (externalUrl.trim().isNotEmpty) 'external_url': externalUrl.trim(),
  };
}

// =====================================================================
// Social accounts
// =====================================================================

/// The linked-accounts editor's whole state, as `GET /me/creator/social`
/// sends it — and every write answers with the same list.
class SocialAccountsBook {
  const SocialAccountsBook({
    this.accounts = const [],
    this.locked = false,
    this.maxAccounts = 20,
  });

  /// In the creator's own order.
  final List<SocialAccount> accounts;
  final bool locked;

  /// 0 means no ceiling.
  final int maxAccounts;

  bool get isFull => maxAccounts > 0 && accounts.length >= maxAccounts;

  factory SocialAccountsBook.fromJson(Map<String, dynamic> json) =>
      SocialAccountsBook(
        accounts: Json.list(json['social_accounts'], SocialAccount.fromJson),
        locked: Json.boolean(json['locked']),
        maxAccounts: Json.integer(json['max_accounts'], 20),
      );

  /// After a write: the server's fresh list, with the rest kept.
  SocialAccountsBook withAccounts(List<SocialAccount> next) =>
      SocialAccountsBook(
        accounts: next,
        locked: locked,
        maxAccounts: maxAccounts,
      );
}

/// A linked account as the form submits it.
///
/// [followers] and [engagementRate] are the creator's own figures. Nothing
/// fetches them from the network, and the editor says so.
class SocialAccountDraft {
  const SocialAccountDraft({
    required this.platform,
    required this.handle,
    required this.profileUrl,
    this.id,
    this.label,
    this.followers,
    this.engagementRate,
  });

  /// Set when editing an existing link; the network cannot change.
  final int? id;
  final String platform;
  final String handle;
  final String profileUrl;

  /// The name of a custom link ("My Substack"); null for a real network.
  final String? label;
  final int? followers;
  final double? engagementRate;

  Map<String, dynamic> toJson() => {
    'id': ?id,
    'platform': platform,
    'handle': handle.trim(),
    'profile_url': profileUrl.trim(),
    if (label != null && label!.trim().isNotEmpty) 'label': label!.trim(),
    'followers': ?followers,
    'engagement_rate': ?engagementRate,
  };
}

import '../core/utils/json.dart';

/// One intro slide, managed in the admin panel.
///
/// The three onboarding screens used to be a `const` list in the Flutter
/// source, so changing a headline meant a store release. They now come from
/// `GET /app/onboarding`.
class OnboardingSlide {
  const OnboardingSlide({
    required this.id,
    required this.title,
    this.body = '',
    this.imageUrl,
    this.images = const [],
    this.icon,
    this.ctaLabel,
  });

  final int id;
  final String title;
  final String body;

  /// Artwork uploaded in the admin panel. When absent the screen falls back to
  /// the seeded placeholder bubbles.
  final String? imageUrl;

  /// Every collage photo the admin uploaded, hero first. Use [artwork], which
  /// also covers servers that only send [imageUrl].
  final List<String> images;

  /// Material symbol name, used when no image is set.
  final String? icon;

  /// Overrides the default Next / Get started button label.
  final String? ctaLabel;

  factory OnboardingSlide.fromJson(Map<String, dynamic> json) =>
      OnboardingSlide(
        id: Json.integer(json['id']),
        title: Json.str(json['title']),
        body: Json.str(json['subtitle']),
        imageUrl: Json.strOrNull(json['image_url']),
        images: Json.strings(
          json['images'],
        ).where((url) => url.trim().isNotEmpty).toList(growable: false),
        icon: Json.strOrNull(json['icon']),
        ctaLabel: Json.strOrNull(json['cta_label']),
      );

  /// Shown only when the API is unreachable on a first launch and the admin
  /// has configured nothing — better than a blank screen.
  static const fallback = OnboardingSlide(
    id: 0,
    title: 'Find the perfect Creator for your brand.',
    body: 'Connect with verified influencers and grow your brand effortlessly.',
  );

  /// Deterministic placeholder seeds, used only when the admin has uploaded no
  /// artwork. [imageUrl] is deliberately NOT folded in here — it is a real URL
  /// and must be rendered as-is, not turned into a placeholder seed.
  String get heroSeed => 'onboard-hero-$id';

  List<String> get satelliteSeeds => [
    'onboard-sat-${id}a',
    'onboard-sat-${id}b',
  ];

  /// True when the admin has supplied real artwork for this slide.
  bool get hasArtwork => artwork.isNotEmpty;

  /// The admin's collage photos in display order: the `images` list when the
  /// server sends one, otherwise the single [imageUrl]. Empty means "use the
  /// placeholder composition".
  List<String> get artwork {
    if (images.isNotEmpty) return images;
    final single = imageUrl;
    return single == null || single.isEmpty ? const [] : [single];
  }
}

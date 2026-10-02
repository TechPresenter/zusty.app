import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/placeholder_image.dart';
import '../../../models/onboarding_slide.dart';
import '../../../shared/widgets/z_image.dart';

/// The photo collage at the top of an onboarding slide.
///
/// Built from whatever the admin uploaded for the slide:
///
/// * three or more photos (or none, which falls back to seeded placeholder
///   portraits) — an editorial collage: a tall hero with two smaller
///   portraits overlapping it;
/// * two photos — the hero with one portrait;
/// * one photo — a single large rounded hero sitting on soft brand shapes, so
///   one strong image is shown big rather than padded out with stock.
///
/// The composition is laid out in fractions of a fixed-aspect box, so it keeps
/// its proportions from a 360dp phone to a tablet and never overflows when the
/// copy below it needs more room at a large text scale.
class OnboardingCollage extends StatelessWidget {
  const OnboardingCollage({super.key, required this.slide});

  final OnboardingSlide slide;

  /// Height over width of the composition box.
  static const _aspect = 1.08;

  @override
  Widget build(BuildContext context) {
    final artwork = slide.artwork;

    // No admin artwork yet: the same deterministic placeholders the slide has
    // always used, so the screen is never an empty frame.
    final photos = artwork.isEmpty
        ? [
            placeholderPhoto(slide.heroSeed, 600, 760),
            placeholderPhoto(slide.satelliteSeeds.first, 400, 440),
            placeholderPhoto(slide.satelliteSeeds.last, 400, 440),
          ]
        : artwork;

    return LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth;
        final height = constraints.maxHeight;

        // The largest box of the target aspect that fits.
        var boxWidth = width;
        var boxHeight = width * _aspect;
        if (boxHeight > height) {
          boxHeight = height;
          boxWidth = height / _aspect;
        }

        return Center(
          child: SizedBox(
            width: boxWidth,
            height: boxHeight,
            child: photos.length == 1
                ? _SingleHero(url: photos.first, icon: _iconFor(slide.icon))
                : _Collage(photos: photos, icon: _iconFor(slide.icon)),
          ),
        );
      },
    );
  }

  /// The admin types a Material symbol name for each slide. Unknown names get
  /// a neutral sparkle rather than an empty badge.
  static IconData _iconFor(String? name) => switch (name) {
    'search' => Icons.search_rounded,
    'verified' => Icons.verified_rounded,
    'verified_user' => Icons.verified_user_rounded,
    'lock' => Icons.lock_rounded,
    'shield' => Icons.shield_rounded,
    'payments' => Icons.payments_rounded,
    'account_balance_wallet' => Icons.account_balance_wallet_rounded,
    'campaign' => Icons.campaign_rounded,
    'chat' => Icons.chat_bubble_rounded,
    'handshake' => Icons.handshake_rounded,
    'star' => Icons.star_rounded,
    'favorite' => Icons.favorite_rounded,
    'groups' => Icons.groups_rounded,
    'trending_up' => Icons.trending_up_rounded,
    'photo_camera' || 'camera' => Icons.photo_camera_rounded,
    'videocam' => Icons.videocam_rounded,
    'event' || 'calendar_today' => Icons.event_rounded,
    _ => Icons.auto_awesome_rounded,
  };
}

/// Hero plus one or two overlapping portraits.
class _Collage extends StatelessWidget {
  const _Collage({required this.photos, required this.icon});

  final List<String> photos;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final w = box.maxWidth;
        final h = box.maxHeight;
        final hasThird = photos.length > 2;

        return Stack(
          clipBehavior: Clip.none,
          children: [
            // Soft brand shapes behind the photos — colour, not decoration
            // that competes with the faces.
            Positioned(
              left: -w * 0.04,
              top: h * 0.02,
              child: _Blob(size: w * 0.62, color: AppColors.primaryFixed),
            ),
            Positioned(
              right: -w * 0.02,
              bottom: h * 0.04,
              child: _Blob(size: w * 0.46, color: AppColors.secondaryFixed),
            ),
            Positioned(
              right: w * 0.06,
              top: h * 0.02,
              child: _Ring(size: w * 0.16),
            ),

            // Hero: tall portrait, left of centre.
            Positioned(
              left: w * 0.04,
              top: h * 0.08,
              width: w * 0.58,
              height: h * 0.80,
              child: _Photo(url: photos[0], radius: AppRadius.xxl),
            ),

            // First portrait, upper right, overlapping the hero's edge.
            Positioned(
              right: w * 0.02,
              top: hasThird ? h * 0.12 : h * 0.24,
              width: w * 0.42,
              height: h * 0.40,
              child: _Photo(url: photos[1], radius: AppRadius.xl, framed: true),
            ),

            // Second portrait, lower right.
            if (hasThird)
              Positioned(
                right: w * 0.10,
                bottom: 0,
                width: w * 0.38,
                height: h * 0.36,
                child: _Photo(
                  url: photos[2],
                  radius: AppRadius.xl,
                  framed: true,
                ),
              ),

            Positioned(
              left: 0,
              bottom: h * 0.06,
              child: _IconBadge(icon: icon, size: (w * 0.17).clamp(44.0, 64.0)),
            ),
          ],
        );
      },
    );
  }
}

/// One admin photo, shown large on brand shapes.
class _SingleHero extends StatelessWidget {
  const _SingleHero({required this.url, required this.icon});

  final String url;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final w = box.maxWidth;
        final h = box.maxHeight;

        return Stack(
          clipBehavior: Clip.none,
          children: [
            // A tilted blush tile peeking out behind the photo.
            Positioned(
              left: w * 0.06,
              top: h * 0.04,
              width: w * 0.82,
              height: h * 0.86,
              child: Transform.rotate(
                angle: -0.07,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    color: AppColors.primaryFixed,
                    borderRadius: AppRadius.xxlAll,
                  ),
                ),
              ),
            ),
            Positioned(
              right: -w * 0.02,
              bottom: h * 0.02,
              child: _Blob(size: w * 0.40, color: AppColors.secondaryFixed),
            ),
            Positioned(
              right: w * 0.02,
              top: 0,
              child: _Ring(size: w * 0.18),
            ),
            Positioned(
              left: w * 0.10,
              top: h * 0.06,
              width: w * 0.80,
              height: h * 0.86,
              child: _Photo(url: url, radius: AppRadius.xxl),
            ),
            Positioned(
              left: 0,
              bottom: h * 0.10,
              child: _IconBadge(icon: icon, size: (w * 0.17).clamp(44.0, 64.0)),
            ),
          ],
        );
      },
    );
  }
}

class _Photo extends StatelessWidget {
  const _Photo({required this.url, required this.radius, this.framed = false});

  final String url;
  final double radius;

  /// A white frame separates an overlapping portrait from the hero beneath.
  final bool framed;

  @override
  Widget build(BuildContext context) {
    final shape = BorderRadius.circular(radius);

    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: shape,
        color: AppColors.surfaceContainerLowest,
        boxShadow: framed ? AppShadows.raised : AppShadows.card,
      ),
      child: Padding(
        padding: EdgeInsets.all(framed ? 4 : 0),
        child: LayoutBuilder(
          builder: (context, box) => ZImage(
            url: url,
            width: box.maxWidth,
            height: box.maxHeight,
            borderRadius: BorderRadius.circular(framed ? radius - 4 : radius),
            fallbackIcon: Icons.person_rounded,
          ),
        ),
      ),
    );
  }
}

/// The slide's admin-chosen symbol, floating over the collage's corner.
class _IconBadge extends StatelessWidget {
  const _IconBadge({required this.icon, required this.size});

  final IconData icon;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      padding: EdgeInsets.all(size * 0.14),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        shape: BoxShape.circle,
        boxShadow: AppShadows.raised,
      ),
      child: DecoratedBox(
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [AppColors.primary, AppColors.primaryContainer],
          ),
        ),
        child: Icon(icon, size: size * 0.38, color: AppColors.onPrimary),
      ),
    );
  }
}

class _Blob extends StatelessWidget {
  const _Blob({required this.size, required this.color});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: color, shape: BoxShape.circle),
    );
  }
}

/// A hairline brand ring — the lightest of the background shapes.
class _Ring extends StatelessWidget {
  const _Ring({required this.size});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(
          color: AppColors.primary.withValues(alpha: 0.28),
          width: 1.5,
        ),
      ),
    );
  }
}

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';
import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../motion/pulse_dot.dart';

/// Circular avatar with an initials fallback, an optional verified tick, and an
/// optional online dot — the combination used on profiles, chat, and bookings.
class ZAvatar extends StatelessWidget {
  const ZAvatar({
    super.key,
    this.imageUrl,
    this.name,
    this.size = 44,
    this.isVerified = false,
    this.isOnline = false,
    this.ringColor,
    this.ringWidth = 0,
    this.onTap,
  });

  final String? imageUrl;
  final String? name;
  final double size;
  final bool isVerified;
  final bool isOnline;

  /// Draws a gradient-style ring around the avatar (creator profile header).
  final Color? ringColor;
  final double ringWidth;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final indicatorSize = (size * 0.28).clamp(10.0, 22.0);
    final dpr = MediaQuery.maybeDevicePixelRatioOf(context) ?? 1.0;

    final initials = Center(
      child: Text(
        Formatters.initials(name),
        style: AppTypography.labelMedium.copyWith(
          fontSize: size * 0.34,
          color: AppColors.onPrimaryFixed,
        ),
      ),
    );

    Widget avatar = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: AppColors.primaryFixed,
        border: ringWidth > 0
            ? Border.all(
                color: ringColor ?? AppColors.primary,
                width: ringWidth,
              )
            : Border.all(color: AppColors.outlineVariant, width: 0.5),
      ),
      clipBehavior: Clip.antiAlias,
      child: (imageUrl == null || imageUrl!.isEmpty)
          ? initials
          // Initials while loading and when the photo cannot be fetched or
          // decoded: a person's monogram, never a broken-image glyph.
          : CachedNetworkImage(
              imageUrl: AppConfig.resolveMediaUrl(imageUrl!),
              width: size,
              height: size,
              fit: BoxFit.cover,
              memCacheWidth: (size * dpr).round(),
              memCacheHeight: (size * dpr).round(),
              fadeInDuration: Motion.of(context, Motion.medium),
              fadeInCurve: Motion.standard,
              placeholder: (_, _) => initials,
              errorWidget: (_, _, _) => initials,
            ),
    );

    if (isVerified || isOnline) {
      avatar = Stack(
        clipBehavior: Clip.none,
        children: [
          avatar,
          if (isOnline)
            Positioned(
              right: 0,
              bottom: 0,
              // Breathes gently so "online" reads as live; holds still under
              // reduced motion.
              child: PulseDot(
                size: indicatorSize,
                borderColor: AppColors.surface,
                borderWidth: 2,
              ),
            ),
          if (isVerified && !isOnline)
            Positioned(
              right: -2,
              bottom: -2,
              child: Container(
                padding: const EdgeInsets.all(1.5),
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerLowest,
                  shape: BoxShape.circle,
                ),
                child: VerifiedBadge(size: indicatorSize),
              ),
            ),
        ],
      );
    }

    if (onTap == null) return avatar;

    return InkWell(
      onTap: onTap,
      customBorder: const CircleBorder(),
      child: avatar,
    );
  }
}

/// The small blue tick shown next to a verified creator's name.
class VerifiedBadge extends StatelessWidget {
  const VerifiedBadge({super.key, this.size = 16});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: 'Verified',
      child: Icon(
        Icons.verified_rounded,
        size: size,
        color: AppColors.verified,
      ),
    );
  }
}

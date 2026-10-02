import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';

import '../../core/config/app_config.dart';
import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';

/// Network image with a shimmer placeholder and a branded fallback.
///
/// Every remote image in the app goes through this so a slow or missing asset
/// degrades into a tonal surface rather than a broken-image glyph.
class ZImage extends StatelessWidget {
  const ZImage({
    super.key,
    required this.url,
    this.width,
    this.height,
    this.fit = BoxFit.cover,
    this.borderRadius,
    this.fallbackIcon = Icons.image_rounded,
  });

  final String? url;
  final double? width;
  final double? height;
  final BoxFit fit;
  final BorderRadius? borderRadius;
  final IconData fallbackIcon;

  @override
  Widget build(BuildContext context) {
    final radius = borderRadius ?? BorderRadius.zero;
    final raw = url;

    if (raw == null || raw.isEmpty) {
      return ClipRRect(
        borderRadius: radius,
        child: _Fallback(
          width: width,
          height: height,
          icon: fallbackIcon,
        ),
      );
    }

    // Decode at display size, not source size.
    //
    // Without this a 1200px photo rendered into a 40px avatar is decoded and
    // held in memory at full resolution — roughly 900x the bytes it needs. In
    // a scrolling list of creator cards that is the difference between a few
    // megabytes and a few hundred. The multiplier is the device pixel ratio so
    // the image is still sharp on a 3x screen.
    final ratio = MediaQuery.maybeDevicePixelRatioOf(context) ?? 1.0;

    final source = AppConfig.resolveMediaUrl(raw);

    int? decodeAt(double? logical) =>
        logical == null || !logical.isFinite ? null : (logical * ratio).round();

    return ClipRRect(
      borderRadius: radius,
      child: CachedNetworkImage(
        imageUrl: source,
        width: width,
        height: height,
        fit: fit,
        memCacheWidth: decodeAt(width),
        memCacheHeight: decodeAt(height),
        // A loaded image eases in over its placeholder; under reduced motion
        // it simply appears.
        fadeInDuration: Motion.of(context, Motion.medium),
        fadeInCurve: Motion.standard,
        fadeOutDuration: Motion.of(context, Motion.fast),
        placeholder: (_, _) => ZShimmer(width: width, height: height),
        errorWidget: (_, _, _) => _Fallback(
          width: width,
          height: height,
          icon: fallbackIcon,
        ),
      ),
    );
  }
}

class _Fallback extends StatelessWidget {
  const _Fallback({this.width, this.height, required this.icon});

  final double? width;
  final double? height;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      color: AppColors.surfaceContainerHighest,
      alignment: Alignment.center,
      child: Icon(icon, color: AppColors.outline, size: 28),
    );
  }
}

/// A tonal shimmer block used for loading placeholders.
class ZShimmer extends StatelessWidget {
  const ZShimmer({
    super.key,
    this.width,
    this.height,
    this.borderRadius,
  });

  final double? width;
  final double? height;
  final BorderRadius? borderRadius;

  @override
  Widget build(BuildContext context) {
    final block = Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerHigh,
        borderRadius: borderRadius ?? BorderRadius.zero,
      ),
    );

    // The sweep is decoration: under reduced motion the placeholder holds
    // still and still reads as "loading".
    if (Motion.reduced(context)) return block;

    return Shimmer.fromColors(
      baseColor: AppColors.surfaceContainerHigh,
      highlightColor: AppColors.surfaceContainerLow,
      period: const Duration(milliseconds: 1400),
      child: block,
    );
  }
}

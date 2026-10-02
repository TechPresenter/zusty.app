import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';

/// The bundled brand artwork (declared in pubspec.yaml).
///
/// Every image is transparent, with a twin for dark surfaces whose navy
/// ".app" and tagline are lifted to white. The aspect ratios let a logo be
/// laid out at its final size before the image decodes, so nothing around it
/// jumps when it arrives.
abstract final class BrandAssets {
  /// Mark, "Zusty.app" and the "INFLUENCER MARKETPLACE" tagline.
  static const logo = 'assets/branding/zusty_logo.png';
  static const logoDark = 'assets/branding/zusty_logo_dark.png';

  /// Mark and "Zusty.app", without the tagline: for app bars and headers.
  static const wordmark = 'assets/branding/zusty_wordmark.png';
  static const wordmarkDark = 'assets/branding/zusty_wordmark_dark.png';

  /// The ribbon mark on its own, for compact places.
  static const mark = 'assets/branding/zusty_mark.png';

  /// The full-screen launch artwork (the static loading ring painted out, so
  /// a live loader can turn in its place).
  static const splash = 'assets/branding/splash.jpg';

  static const logoAspect = 1400 / 366;
  static const wordmarkAspect = 1200 / 261;
}

/// Whether the logo sits on a dark surface. An explicit light [color] (the
/// old text logo's ink) means the caller is on a dark background.
bool _onDark(BuildContext context, Color? color) =>
    color != null ? color.computeLuminance() > 0.5 : Theme.of(context).brightness == Brightness.dark;

/// The Zusty logo, centred: the full lockup with its tagline, or the
/// tagline-free wordmark.
///
/// [fontSize] keeps the old text logo's call sites meaningful: the artwork
/// is laid out at roughly the visual size that wordmark had at that size.
class ZusteLogo extends StatelessWidget {
  const ZusteLogo({
    super.key,
    this.fontSize = 40,
    this.showTagline = false,
    this.color,
  });

  final double fontSize;

  /// The artwork carries its own "INFLUENCER MARKETPLACE" tagline.
  final bool showTagline;

  /// Only its lightness is used now: light ink picks the dark-surface logo.
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final dark = _onDark(context, color);

    return showTagline
        ? _BrandImage(
            asset: dark ? BrandAssets.logoDark : BrandAssets.logo,
            height: fontSize * 1.9,
            aspect: BrandAssets.logoAspect,
          )
        : _BrandImage(
            asset: dark ? BrandAssets.wordmarkDark : BrandAssets.wordmark,
            height: fontSize * 1.25,
            aspect: BrandAssets.wordmarkAspect,
          );
  }
}

/// The horizontal logo for app bars and screen headers: the Zusty mark
/// beside "Zusty.app".
class ZusteLogoLockup extends StatelessWidget {
  const ZusteLogoLockup({
    super.key,
    this.height = 30,
    this.showTagline = false,
    this.onTap,
  });

  /// The old lockup's mark height. The artwork is scaled so its letters keep
  /// about the weight the text wordmark had beside a mark of this size.
  final double height;

  /// Uses the lockup with its tagline — only legible at larger sizes.
  final bool showTagline;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final dark = _onDark(context, null);

    final lockup = showTagline
        ? _BrandImage(
            asset: dark ? BrandAssets.logoDark : BrandAssets.logo,
            height: height * 1.45,
            aspect: BrandAssets.logoAspect,
          )
        : _BrandImage(
            asset: dark ? BrandAssets.wordmarkDark : BrandAssets.wordmark,
            height: height * 1.1,
            aspect: BrandAssets.wordmarkAspect,
          );

    if (onTap == null) return lockup;

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: lockup,
      ),
    );
  }
}

/// The Zusty ribbon mark alone, square.
class ZusteMark extends StatelessWidget {
  const ZusteMark({super.key, this.size = 32});

  final double size;

  @override
  Widget build(BuildContext context) =>
      _BrandImage(asset: BrandAssets.mark, height: size, aspect: 1);
}

/// One piece of brand artwork at a fixed height, decoded at the size it is
/// drawn rather than at its full source resolution.
class _BrandImage extends StatelessWidget {
  const _BrandImage({
    required this.asset,
    required this.height,
    required this.aspect,
  });

  final String asset;
  final double height;
  final double aspect;

  @override
  Widget build(BuildContext context) {
    final dpr = MediaQuery.maybeDevicePixelRatioOf(context) ?? 2;

    return Semantics(
      label: AppConfig.appName,
      image: true,
      child: ExcludeSemantics(
        child: SizedBox(
          height: height,
          width: height * aspect,
          child: Image.asset(
            asset,
            fit: BoxFit.contain,
            cacheHeight: (height * dpr).ceil(),
            filterQuality: FilterQuality.medium,
            gaplessPlayback: true,
          ),
        ),
      ),
    );
  }
}

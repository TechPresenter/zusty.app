import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/creator.dart';
import '../../../shared/motion/motion.dart';
import '../../../shared/widgets/z_image.dart';

/// One portfolio thumbnail, shared by the profile's Media tab and the gallery.
///
/// Fills whatever cell its grid gives it — it never sizes itself — so a grid
/// at any width or text scale lays out without overflow. Video kinds get a
/// bottom scrim carrying views and duration and a small play glyph; stills
/// stay clean, because a grid of photos reads as a portfolio and a grid of
/// badges does not.
class PortfolioTile extends StatelessWidget {
  const PortfolioTile({
    super.key,
    required this.item,
    required this.onTap,
    this.borderRadius = AppRadius.mdAll,
    this.showMetrics = true,
  });

  final PortfolioItem item;
  final VoidCallback onTap;
  final BorderRadius borderRadius;

  /// Views and duration on video tiles. Off for very small tiles.
  final bool showMetrics;

  @override
  Widget build(BuildContext context) {
    final label = [
      item.isVideo ? 'Video' : 'Photo',
      if (item.title.isNotEmpty) item.title,
      if (item.isVideo && item.viewsCount > 0) '${item.viewsLabel} views',
    ].join(', ');

    return Semantics(
      button: true,
      label: label,
      excludeSemantics: true,
      child: Pressable(
        pressedScale: 0.97,
        child: GestureDetector(
          onTap: onTap,
          child: ClipRRect(
            borderRadius: borderRadius,
            child: Stack(
              fit: StackFit.expand,
              children: [
                ColoredBox(color: AppColors.surfaceContainerHigh),
                ZImage(
                  url: item.displayUrl,
                  fallbackIcon: item.isVideo
                      ? Icons.movie_creation_outlined
                      : Icons.image_outlined,
                ),
                if (item.isVideo) ...[
                  DecoratedBox(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.bottomCenter,
                        end: Alignment.center,
                        colors: [
                          Colors.black.withValues(alpha: 0.62),
                          Colors.transparent,
                        ],
                      ),
                    ),
                  ),
                  const Positioned(
                    top: 6,
                    right: 6,
                    child: Icon(
                      Icons.play_circle_fill_rounded,
                      size: 22,
                      color: Colors.white,
                      shadows: [Shadow(blurRadius: 6, color: Colors.black38)],
                    ),
                  ),
                  if (showMetrics)
                    Positioned(
                      left: 6,
                      right: 6,
                      bottom: 6,
                      child: Row(
                        children: [
                          if (item.viewsCount > 0) ...[
                            const Icon(
                              Icons.play_arrow_rounded,
                              size: 14,
                              color: Colors.white,
                            ),
                            const SizedBox(width: 2),
                            Flexible(
                              child: Text(
                                item.viewsLabel,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: AppTypography.labelSmall.copyWith(
                                  color: Colors.white,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ],
                          const Spacer(),
                          if (item.durationLabel.isNotEmpty)
                            Text(
                              item.durationLabel,
                              maxLines: 1,
                              style: AppTypography.labelSmall.copyWith(
                                color: Colors.white,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                        ],
                      ),
                    ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The glyph for a portfolio kind, for tabs and shortcuts. Kinds come from
/// the admin-managed `portfolio_kind` taxonomy, so anything unknown gets a
/// neutral media icon rather than nothing.
IconData portfolioKindIcon(String kind) => switch (kind) {
  'reel' || 'short' => Icons.movie_creation_outlined,
  'video' || 'live' => Icons.smart_display_outlined,
  'photo' => Icons.photo_outlined,
  'carousel' => Icons.view_carousel_outlined,
  'audio' => Icons.graphic_eq_rounded,
  'document' || 'case_study' => Icons.article_outlined,
  _ => Icons.grid_view_rounded,
};

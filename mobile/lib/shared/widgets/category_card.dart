import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../models/category.dart';
import '../../router/routes.dart';
import '../motion/pressable.dart';
import 'z_image.dart';

/// The circular category button in the Home "Top Categories" rail.
class CategoryCircle extends StatelessWidget {
  const CategoryCircle({super.key, required this.category, this.onTap});

  final Category category;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) =>
      Pressable(pressedScale: 0.94, child: _tile(context));

  Widget _tile(BuildContext context) {
    return SizedBox(
      width: 72,
      child: InkWell(
        onTap: onTap ??
            () => context.push('${Routes.searchResults}?category=${category.slug}'),
        borderRadius: AppRadius.lgAll,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.base),
          child: Column(
            children: [
              Container(
                width: 60,
                height: 60,
                decoration: BoxDecoration(
                  color: category.color.withValues(alpha: 0.12),
                  shape: BoxShape.circle,
                ),
                clipBehavior: Clip.antiAlias,
                child: category.coverImage != null
                    ? ZImage(url: category.coverImage, width: 60, height: 60)
                    : Icon(category.iconData, color: category.color, size: 26),
              ),
              const SizedBox(height: AppSpacing.xs),
              Text(
                category.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
                style: AppTypography.labelMedium.copyWith(
                  fontWeight: FontWeight.w500,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The larger media card on the Categories screen — cover image, name, and
/// creator count.
class CategoryTile extends StatelessWidget {
  const CategoryTile({super.key, required this.category, this.onTap});

  final Category category;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) =>
      Pressable(pressedScale: 0.98, child: _tile(context));

  Widget _tile(BuildContext context) {
    return Material(
      color: AppColors.surfaceContainerLowest,
      borderRadius: AppRadius.xlAll,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap ??
            () => context
                .push('${Routes.searchResults}?category=${category.slug}'),
        child: Stack(
          fit: StackFit.expand,
          children: [
            ZImage(url: category.coverImage, fallbackIcon: Icons.category_rounded),
            // Scrim so the label stays legible over any photograph.
            DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.bottomCenter,
                  end: Alignment.topCenter,
                  colors: [
                    AppColors.onSurface.withValues(alpha: 0.82),
                    AppColors.onSurface.withValues(alpha: 0.15),
                    Colors.transparent,
                  ],
                  stops: const [0, 0.55, 1],
                ),
              ),
            ),
            Positioned(
              left: AppSpacing.sm,
              right: AppSpacing.sm,
              bottom: AppSpacing.sm,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(5),
                        decoration: BoxDecoration(
                          color: category.color,
                          borderRadius: AppRadius.smAll,
                        ),
                        child: Icon(
                          category.iconData,
                          size: 13,
                          color: AppColors.onPrimary,
                        ),
                      ),
                      const SizedBox(width: AppSpacing.xs),
                      Expanded(
                        child: Text(
                          category.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.labelMedium
                              .copyWith(color: AppColors.onPrimary, fontSize: 14),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 2),
                  Text(
                    '${category.creatorCount} creators',
                    style: AppTypography.labelSmall.copyWith(
                      color: AppColors.onPrimary.withValues(alpha: 0.85),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A compact list row for the alphabetical category list.
/// The tile on the Categories grid: a tinted icon square on a white card, with
/// the name and creator count beneath.
class CategoryIconTile extends StatelessWidget {
  const CategoryIconTile({super.key, required this.category, this.onTap});

  final Category category;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) =>
      Pressable(pressedScale: 0.95, child: _tile(context));

  Widget _tile(BuildContext context) {
    return Material(
      color: AppColors.surfaceContainerLowest,
      shape: RoundedRectangleBorder(
        borderRadius: AppRadius.xlAll,
        side: BorderSide(color: AppColors.outlineVariant),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap ??
            () => context
                .push('${Routes.searchResults}?category=${category.slug}'),
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.xs,
            vertical: AppSpacing.md,
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: category.color.withValues(alpha: 0.12),
                  borderRadius: AppRadius.lgAll,
                ),
                child: Icon(category.iconData, color: category.color, size: 26),
              ),
              const SizedBox(height: AppSpacing.sm),
              Text(
                category.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
                style: AppTypography.titleSmall.copyWith(fontSize: 13),
              ),
              if (category.creatorCount > 0) ...[
                const SizedBox(height: 2),
                Text(
                  '${category.creatorCount} creators',
                  maxLines: 1,
                  style: AppTypography.labelSmall,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class CategoryListRow extends StatelessWidget {
  const CategoryListRow({super.key, required this.category, this.onTap});

  final Category category;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      onTap: onTap ??
          () => context
              .push('${Routes.searchResults}?category=${category.slug}'),
      leading: Container(
        width: 44,
        height: 44,
        decoration: BoxDecoration(
          color: category.color.withValues(alpha: 0.12),
          borderRadius: AppRadius.mdAll,
        ),
        child: Icon(category.iconData, color: category.color, size: 22),
      ),
      minTileHeight: 56,
      title: Text(category.name, style: AppTypography.titleSmall),
      subtitle: Text(
        '${category.creatorCount} creators',
        style: AppTypography.labelSmall,
      ),
      trailing: const Icon(Icons.chevron_right_rounded, size: 20),
    );
  }
}

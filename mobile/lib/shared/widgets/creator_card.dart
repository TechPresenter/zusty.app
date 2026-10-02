import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/motion/motion.dart';
import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/creator.dart';
import '../../providers/discovery_provider.dart';
import '../../router/routes.dart';
import '../motion/pop_toggle_icon.dart';
import '../motion/pressable.dart';
import 'platform_icon.dart';
import 'states.dart';
import 'status_chip.dart';
import 'z_avatar.dart';
import 'z_card.dart';
import 'z_image.dart';

/// The 2-up creator card on Home, Explore, Saved and Search results.
///
/// Portrait photo with the favourite heart, then who they are (name, handle,
/// city, niche), what they reach (followers, engagement, networks) and what
/// they cost. The whole card opens the profile.
///
/// Grids hosting it should size rows with [CreatorGridCard.extentFor] rather
/// than an aspect ratio, so the text block never overflows on a narrow phone.
class CreatorGridCard extends ConsumerWidget {
  const CreatorGridCard({
    super.key,
    required this.creator,
    this.showFavorite = true,
    this.onTap,
  });

  final Creator creator;
  final bool showFavorite;
  final VoidCallback? onTap;

  /// Row height for a grid cell [width] wide: a 4:5 photo plus the text
  /// block beneath it. The card itself flexes the photo, so this sets the
  /// proportions rather than a size the content must squeeze into.
  static double extentFor(double width, {double textScale = 1}) =>
      (width - AppSpacing.xs * 2) * 5 / 4 + 124 * textScale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.xs),
      onTap: onTap ?? () => context.push(Routes.creator(creator.handle)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // The photo takes whatever the cell leaves after the text block, so
          // the card fills a fixed-height grid cell exactly at any font size
          // instead of overflowing it. At the default size that is 4:5.
          Expanded(
            child: Stack(
              children: [
                Positioned.fill(
                  child: ZImage(
                    url: creator.avatarUrl,
                    borderRadius: AppRadius.lgAll,
                    fallbackIcon: Icons.person_rounded,
                  ),
                ),
                if (creator.isFeatured)
                  const Positioned(
                    left: AppSpacing.xs,
                    top: AppSpacing.xs,
                    child: _FeaturedTag(),
                  ),
                if (showFavorite)
                  Positioned(
                    right: AppSpacing.xs,
                    top: AppSpacing.xs,
                    child: CreatorFavoriteButton(creator: creator),
                  ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.base,
              AppSpacing.xs + 2,
              AppSpacing.base,
              AppSpacing.base,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _NameRow(creator: creator, style: AppTypography.titleSmall),
                const SizedBox(height: 2),
                Text(
                  _subtitle(creator),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
                const SizedBox(height: AppSpacing.xs),
                _ReachRow(creator: creator, dense: true),
                const SizedBox(height: AppSpacing.xs),
                Row(
                  children: [
                    Expanded(child: _StartingPrice(creator: creator)),
                    const SizedBox(width: AppSpacing.base),
                    PlatformIconRow(
                      platforms: creator.platforms,
                      max: 2,
                      size: 20,
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The full-width creator row on Search results and Saved creators: every fact
/// a brand compares on, plus Follow and View profile.
class CreatorListCard extends ConsumerWidget {
  const CreatorListCard({
    super.key,
    required this.creator,
    this.showFavorite = true,
    this.trailing,
    this.onTap,
  });

  final Creator creator;
  final bool showFavorite;
  final Widget? trailing;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    void open() => context.push(Routes.creator(creator.handle));

    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.sm),
      onTap: onTap ?? open,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ZImage(
                url: creator.avatarUrl,
                width: 76,
                height: 88,
                borderRadius: AppRadius.lgAll,
                fallbackIcon: Icons.person_rounded,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: _NameRow(
                            creator: creator,
                            style: AppTypography.titleSmall.copyWith(
                              fontSize: 15,
                            ),
                          ),
                        ),
                        if (showFavorite)
                          CreatorFavoriteButton(
                            creator: creator,
                            onImage: false,
                          ),
                      ],
                    ),
                    Text(
                      creator.atHandle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodySmall,
                    ),
                    const SizedBox(height: AppSpacing.base),
                    Text(
                      [
                        if ((creator.category ?? '').isNotEmpty)
                          creator.category!,
                        if ((creator.city ?? '').isNotEmpty) creator.city!,
                      ].join('  ·  '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodySmall.copyWith(
                        color: AppColors.onSurface,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.xs),
                    _ReachRow(creator: creator),
                    if (creator.platforms.isNotEmpty) ...[
                      const SizedBox(height: AppSpacing.xs),
                      PlatformIconRow(
                        platforms: creator.platforms,
                        max: 4,
                        size: 20,
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          // Price takes what is left after the two actions, so a long figure
          // ellipsizes instead of pushing the buttons off a narrow phone.
          Row(
            children: [
              Expanded(child: _StartingPrice(creator: creator)),
              const SizedBox(width: AppSpacing.xs),
              CreatorFollowButton(creator: creator),
              const SizedBox(width: AppSpacing.xs),
              _PillAction(label: 'View', onTap: onTap ?? open),
            ],
          ),
          if (trailing != null) ...[
            const SizedBox(height: AppSpacing.xs),
            trailing!,
          ],
        ],
      ),
    );
  }
}

/// A compact tile for narrow horizontal rails ("Trending", "New on Zusty").
class CreatorRailCard extends StatelessWidget {
  const CreatorRailCard({super.key, required this.creator, this.width = 152});

  final Creator creator;
  final double width;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: width,
      child: ZCard(
        padding: const EdgeInsets.all(AppSpacing.xs),
        onTap: () => context.push(Routes.creator(creator.handle)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            AspectRatio(
              aspectRatio: 1,
              child: Stack(
                children: [
                  Positioned.fill(
                    child: ZImage(
                      url: creator.avatarUrl,
                      borderRadius: AppRadius.lgAll,
                      fallbackIcon: Icons.person_rounded,
                    ),
                  ),
                  Positioned(
                    right: AppSpacing.base + 2,
                    top: AppSpacing.base + 2,
                    child: CreatorFavoriteButton(creator: creator, size: 30),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.base,
                AppSpacing.xs,
                AppSpacing.base,
                AppSpacing.base,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _NameRow(creator: creator, style: AppTypography.titleSmall),
                  const SizedBox(height: 2),
                  Text(
                    _subtitle(creator),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.bodySmall,
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  _StartingPrice(creator: creator),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The wide card for a "Featured creators" rail: a large photo with the
/// creator's name over it, their reach underneath, and Follow / View profile.
class CreatorFeatureCard extends StatelessWidget {
  const CreatorFeatureCard({
    super.key,
    required this.creator,
    this.width = 272,
  });

  final Creator creator;
  final double width;

  @override
  Widget build(BuildContext context) {
    void open() => context.push(Routes.creator(creator.handle));

    return SizedBox(
      width: width,
      child: ZCard(
        padding: EdgeInsets.zero,
        clip: true,
        onTap: open,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Flexes to what the rail leaves after the facts below, so the
            // card never overflows its fixed-height row at larger text sizes.
            Expanded(
              child: Stack(
                fit: StackFit.expand,
                children: [
                  ZImage(
                    url: creator.avatarUrl,
                    fallbackIcon: Icons.person_rounded,
                  ),
                  // A short scrim so the name stays legible on any photo.
                  const DecoratedBox(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                        stops: [0.45, 1],
                        colors: [Color(0x00000000), Color(0xB3140A0F)],
                      ),
                    ),
                  ),
                  if (creator.isFeatured)
                    const Positioned(
                      left: AppSpacing.sm,
                      top: AppSpacing.sm,
                      child: _FeaturedTag(),
                    ),
                  Positioned(
                    right: AppSpacing.sm,
                    top: AppSpacing.sm,
                    child: CreatorFavoriteButton(creator: creator),
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
                            Flexible(
                              child: Text(
                                creator.displayName,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: AppTypography.titleMedium.copyWith(
                                  color: Colors.white,
                                ),
                              ),
                            ),
                            if (creator.isVerified) ...[
                              const SizedBox(width: 4),
                              const VerifiedBadge(size: 16),
                            ],
                          ],
                        ),
                        Text(
                          _subtitle(creator),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.bodySmall.copyWith(
                            color: Colors.white.withValues(alpha: 0.85),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(AppSpacing.sm),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(child: _ReachRow(creator: creator)),
                      PlatformIconRow(
                        platforms: creator.platforms,
                        max: 3,
                        size: 20,
                      ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Row(
                    children: [
                      Expanded(child: _StartingPrice(creator: creator)),
                      CreatorFollowButton(creator: creator),
                      const SizedBox(width: AppSpacing.xs),
                      _PillAction(label: 'View', onTap: open),
                    ],
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

// -- Shared pieces ------------------------------------------------------------

/// The favourite heart, on a frosted disc when it sits over a photo.
class CreatorFavoriteButton extends ConsumerWidget {
  const CreatorFavoriteButton({
    super.key,
    required this.creator,
    this.onImage = true,
    this.size = 34,
  });

  final Creator creator;

  /// Over a photo the heart gets a disc behind it; inline it stands alone.
  final bool onImage;
  final double size;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isFavorite = ref.watch(isFavoriteProvider(creator));

    Future<void> toggle() async {
      // A light tick as the heart fills; nothing when it empties.
      if (!isFavorite) HapticFeedback.lightImpact();

      try {
        await ref.read(favoriteToggleProvider.notifier).toggle(creator);
      } on ApiFailure catch (e) {
        if (context.mounted) showZSnack(context, e.message, isError: true);
      }
    }

    // Pops and fills when saved, empties quietly when removed.
    final icon = AnimatedFavoriteIcon(
      isFavorite: isFavorite,
      size: size * 0.55,
      activeColor: AppColors.primary,
      inactiveColor: onImage ? AppColors.onSurface : AppColors.outline,
    );

    return Semantics(
      button: true,
      selected: isFavorite,
      label: isFavorite ? 'Remove from saved' : 'Save creator',
      child: Pressable(
        pressedScale: 0.88,
        child: Material(
          color: onImage
              ? AppColors.surfaceContainerLowest.withValues(alpha: 0.9)
              : Colors.transparent,
          shape: const CircleBorder(),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: toggle,
            child: SizedBox(
              width: onImage ? size : AppSpacing.minTouch,
              height: onImage ? size : AppSpacing.minTouch,
              child: Center(child: icon),
            ),
          ),
        ),
      ),
    );
  }
}

/// Follow / Following, optimistic and rolled back if the server refuses.
class CreatorFollowButton extends ConsumerWidget {
  const CreatorFollowButton({
    super.key,
    required this.creator,
    this.large = false,
  });

  final Creator creator;

  /// The profile header uses a full-height button; cards use the compact pill.
  final bool large;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final following = ref.watch(isFollowingProvider(creator));

    Future<void> toggle() async {
      try {
        await ref.read(followToggleProvider.notifier).toggle(creator);
      } on ApiFailure catch (e) {
        if (context.mounted) showZSnack(context, e.message, isError: true);
      }
    }

    // Following eases the pill from filled to outlined while the label
    // cross-fades and the width settles, and the tick pops in.
    final duration = Motion.of(context, Motion.medium);
    final foreground = following ? AppColors.onSurface : AppColors.onPrimary;

    return Semantics(
      button: true,
      toggled: following,
      child: Pressable(
        pressedScale: 0.95,
        child: AnimatedContainer(
          duration: duration,
          curve: Motion.standard,
          decoration: ShapeDecoration(
            color: following
                ? AppColors.surfaceContainerLowest
                : AppColors.primary,
            shape: StadiumBorder(
              side: BorderSide(
                color: following
                    ? AppColors.outlineVariant
                    : Colors.transparent,
              ),
            ),
          ),
          child: Material(
            type: MaterialType.transparency,
            child: InkWell(
              onTap: toggle,
              customBorder: const StadiumBorder(),
              child: Container(
                height: large ? 44 : 34,
                padding: EdgeInsets.symmetric(
                  horizontal: large ? AppSpacing.lg : AppSpacing.md - 2,
                ),
                alignment: Alignment.center,
                child: AnimatedSize(
                  duration: duration,
                  curve: Motion.standard,
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      PopToggleIcon(
                        active: following,
                        activeIcon: Icons.check_rounded,
                        inactiveIcon: Icons.add_rounded,
                        size: large ? 18 : 15,
                        activeColor: foreground,
                        inactiveColor: foreground,
                      ),
                      const SizedBox(width: 4),
                      // Flexible so a large text scale ellipsizes the label
                      // instead of overflowing a narrow card's action row.
                      Flexible(
                        child: AnimatedSwitcher(
                          duration: duration,
                          switchInCurve: Motion.standard,
                          switchOutCurve: Motion.exit,
                          child: Text(
                            following ? 'Following' : 'Follow',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            key: ValueKey(following),
                            style:
                                (large
                                        ? AppTypography.labelLarge
                                        : AppTypography.labelMedium)
                                    .copyWith(color: foreground),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _PillAction extends StatelessWidget {
  const _PillAction({required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.primaryFixed,
      shape: const StadiumBorder(),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Container(
          height: 34,
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md - 2),
          alignment: Alignment.center,
          child: Text(
            label,
            style: AppTypography.labelMedium.copyWith(
              color: AppColors.primaryDark,
            ),
          ),
        ),
      ),
    );
  }
}

class _NameRow extends StatelessWidget {
  const _NameRow({required this.creator, required this.style});

  final Creator creator;
  final TextStyle style;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Flexible(
          child: Text(
            creator.displayName,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: style,
          ),
        ),
        if (creator.isVerified) ...[
          const SizedBox(width: 4),
          VerifiedBadge(size: (style.fontSize ?? 14) + 1),
        ],
      ],
    );
  }
}

/// Followers, engagement and rating on one line, each only when there is a
/// figure to show: a "0.0" rating reads as a bad review, not as "new".
class _ReachRow extends StatelessWidget {
  const _ReachRow({required this.creator, this.dense = false});

  final Creator creator;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final style = AppTypography.labelMedium.copyWith(
      fontSize: dense ? 11.5 : 12,
    );

    Widget metric(IconData icon, String value, String semantics) => Semantics(
      label: semantics,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: dense ? 13 : 14, color: AppColors.onSurfaceVariant),
          const SizedBox(width: 3),
          Text(value, style: style),
        ],
      ),
    );

    final items = <Widget>[
      metric(
        Icons.people_alt_rounded,
        creator.followersLabel,
        '${creator.followersLabel} followers',
      ),
      if (creator.engagementRate > 0)
        metric(
          Icons.insights_rounded,
          Formatters.percent(creator.engagementRate),
          '${Formatters.percent(creator.engagementRate)} engagement',
        ),
      if (creator.ratingCount > 0)
        RatingStars(rating: creator.rating, size: dense ? 11 : 12)
      else if (!dense)
        Text('New', style: style.copyWith(color: AppColors.primary)),
    ];

    return Wrap(
      spacing: dense ? AppSpacing.xs : AppSpacing.sm,
      runSpacing: 2,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: items,
    );
  }
}

class _StartingPrice extends StatelessWidget {
  const _StartingPrice({required this.creator});

  final Creator creator;

  @override
  Widget build(BuildContext context) {
    if (creator.priceMin <= 0) {
      return Text('Price on request', style: AppTypography.bodySmall);
    }

    return Text.rich(
      TextSpan(
        children: [
          TextSpan(text: 'From ', style: AppTypography.bodySmall),
          TextSpan(
            text: Formatters.currencyCompact(creator.priceMin),
            style: AppTypography.titleSmall.copyWith(color: AppColors.primary),
          ),
        ],
      ),
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
    );
  }
}

class _FeaturedTag extends StatelessWidget {
  const _FeaturedTag();

  @override
  Widget build(BuildContext context) {
    return const StatusChip(
      label: 'Featured',
      tone: StatusTone.warning,
      dense: true,
      icon: Icons.bolt_rounded,
    );
  }
}

/// "Fashion · Delhi", falling back to the headline, then "Creator".
String _subtitle(Creator creator) {
  final parts = [
    if ((creator.category ?? '').isNotEmpty) creator.category!,
    if ((creator.city ?? '').isNotEmpty) creator.city!,
  ];

  if (parts.isNotEmpty) return parts.join(' · ');
  return creator.headline.isEmpty ? 'Creator' : creator.headline;
}

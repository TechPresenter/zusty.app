import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/foundation.dart' show ValueListenable;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config/app_config.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/action_link.dart';
import '../../core/utils/formatters.dart';
import '../../models/booking.dart';
import '../../models/category.dart';
import '../../models/creator.dart';
import '../../models/home_feed.dart';
import '../../providers/auth_provider.dart';
import '../../providers/discovery_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/adaptive_layout.dart';
import '../../shared/widgets/app_popup_host.dart';
import '../../shared/widgets/category_card.dart';
import '../../shared/widgets/creator_card.dart';
import '../../shared/widgets/creator_grid.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_text_field.dart';
import '../shell/app_shell.dart';

/// Screen 06 — Home.
///
/// The header (logo, city, notifications, avatar) and search are fixed; every
/// rail beneath them is the admin panel's `homepage_sections`, in its order and
/// with its titles. A backend that does not send sections gets the original
/// fixed layout from the flat feed keys.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final feed = ref.watch(homeFeedProvider);
    final user = ref.watch(currentUserProvider);
    final unread = user?.unreadNotifications ?? 0;

    // On a tablet the feed is a centred grid page, header included.
    return Scaffold(
      backgroundColor: AppColors.surface,
      appBar: ContentWidthAppBar(
        maxWidth: ContentWidth.wide,
        child: ShellHeader(
          title: 'Home',
          actions: [
            IconButton(
              icon: Badge(
                isLabelVisible: unread > 0,
                label: Text(unread > 99 ? '99+' : '$unread'),
                backgroundColor: AppColors.primary,
                child: const Icon(Icons.notifications_none_rounded),
              ),
              onPressed: () => context.push(Routes.notifications),
              tooltip: unread > 0 ? 'Notifications, $unread unread' : 'Notifications',
            ),
            Padding(
              padding: const EdgeInsets.only(left: 4),
              child: Semantics(
                button: true,
                label: 'Your profile',
                child: ZAvatar(
                  imageUrl: user?.avatarUrl,
                  name: user?.displayName,
                  size: 36,
                  ringWidth: 2,
                  ringColor: AppColors.primaryFixed,
                  onTap: () => context.go(Routes.menu),
                ),
              ),
            ),
          ],
        ),
      ),
      // Popups for the `home` placement, plus every popup the admin targeted at
      // `all`, are rendered here; the host reads them from the config the app
      // already fetches and honours the trigger and frequency set in the panel.
      body: AppPopupHost(
        placement: 'home',
        child: ContentWidthBox(
          maxWidth: ContentWidth.wide,
          child: ZRefresh(
            onRefresh: () async => ref.refresh(homeFeedProvider.future),
            child: AsyncView(
              value: feed,
              onRetry: () => ref.invalidate(homeFeedProvider),
              loading: const _HomeSkeleton(),
              builder: (data) => _HomeBody(feed: data),
            ),
          ),
        ),
      ),
    );
  }
}

class _HomeBody extends StatelessWidget {
  const _HomeBody({required this.feed});

  final HomeFeed feed;

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);

    return ListView(
      padding: const EdgeInsets.only(top: AppSpacing.xs, bottom: AppSpacing.xxl),
      children: [
        // -- Search -------------------------------------------------------
        // From the sections' left edge on a tablet; at a readable width in
        // landscape rather than a strip across the whole grid.
        _LeadBlock(
          child: Padding(
            padding: EdgeInsets.symmetric(horizontal: margin),
            child: ZSearchField(
              readOnly: true,
              hint: 'Search creators, categories…',
              voiceInput: false,
              onTap: () => context.go(Routes.search),
              onFilterTap: () => context.go(Routes.search),
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.md),

        // The layout, the order and every title below come from
        // `homepage_sections` in the admin panel. The fixed Dart layout is kept
        // only as the fallback for a backend that does not send `sections`.
        ...(feed.sections.isEmpty
            ? _fallbackLayout(context, margin)
            : _managedLayout(context, margin)),
      ],
    );
  }

  List<Widget> _managedLayout(BuildContext context, double margin) {
    final widgets = <Widget>[];

    // "Active Campaigns" is the signed-in user's own in-flight work, not an
    // admin-managed rail — it has no row in `homepage_sections`. It keeps the
    // slot it has always had: directly above the first creator rail.
    var bookingsPlaced = feed.activeBookings.isEmpty;
    var carousels = 0;

    for (final section in feed.sections) {
      if (section.isEmpty) continue;

      if (!bookingsPlaced && section.creators.isNotEmpty) {
        widgets.addAll(_activeBookings(context, margin));
        bookingsPlaced = true;
      }

      widgets.addAll(_section(context, section, margin, carousels));
      if (section.type == 'creator_carousel') carousels++;
    }

    if (!bookingsPlaced) widgets.addAll(_activeBookings(context, margin));

    return widgets;
  }

  List<Widget> _section(
    BuildContext context,
    HomeSection section,
    double margin,
    int carouselIndex,
  ) {
    final target = Routes.resolveMenuTarget(section.ctaAction);

    final header = SectionHeader(
      title: section.title,
      subtitle: section.subtitle,
      actionLabel: target == null ? null : (section.ctaLabel ?? 'View all'),
      onAction: target == null ? null : () => context.push(target),
      padding: EdgeInsets.only(left: margin, right: margin - AppSpacing.xs),
    );

    switch (section.type) {
      // Full-bleed hero: the banners carry their own admin-set headlines, so
      // the section title stays out of the layout as it always has.
      case 'banner_carousel':
        return [
          _BannerCarousel(banners: section.banners, margin: margin),
        ];

      case 'category_strip':
        return [
          const SizedBox(height: AppSpacing.lg),
          header,
          _CategoryStrip(categories: section.categories, margin: margin),
        ];

      case 'creator_grid':
        return [
          const SizedBox(height: AppSpacing.lg),
          header,
          const SizedBox(height: AppSpacing.xs),
          _CreatorGrid(
            creators: section.creators,
            margin: margin,
            columns: section.layout == 'grid_3' ? 3 : 2,
          ),
        ];

      // The first carousel gets the large featured cards; later ones the
      // compact tile, so the page does not become a wall of big photos.
      case 'creator_carousel':
        return [
          const SizedBox(height: AppSpacing.lg),
          header,
          const SizedBox(height: AppSpacing.xs),
          carouselIndex == 0
              ? _FeaturedRail(creators: section.creators, margin: margin)
              : _CreatorRail(creators: section.creators, margin: margin),
        ];

      default:
        return const [];
    }
  }

  List<Widget> _activeBookings(BuildContext context, double margin) => [
        const SizedBox(height: AppSpacing.lg),
        SectionHeader(
          title: 'Active Campaigns',
          subtitle: 'Collaborations in progress',
          actionLabel: 'View all',
          onAction: () => context.go(Routes.bookings),
          padding: EdgeInsets.only(left: margin, right: margin - AppSpacing.xs),
        ),
        const SizedBox(height: AppSpacing.xs),
        SizedBox(
          height: _ActiveBookingCard.height(context),
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: EdgeInsets.symmetric(horizontal: margin),
            itemCount: feed.activeBookings.length,
            separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.sm),
            itemBuilder: (_, i) =>
                _ActiveBookingCard(booking: feed.activeBookings[i]),
          ),
        ),
      ];

  List<Widget> _fallbackLayout(BuildContext context, double margin) {
    SectionHeader header(String title, {String? subtitle, String? route}) =>
        SectionHeader(
          title: title,
          subtitle: subtitle,
          actionLabel: route == null ? null : 'View all',
          onAction: route == null ? null : () => context.push(route),
          padding: EdgeInsets.only(left: margin, right: margin - AppSpacing.xs),
        );

    return [
      if (feed.banners.isNotEmpty)
        _BannerCarousel(banners: feed.banners, margin: margin),

      if (feed.categories.isNotEmpty) ...[
        const SizedBox(height: AppSpacing.lg),
        header('Top Categories', route: Routes.categories),
        _CategoryStrip(categories: feed.categories, margin: margin),
      ],

      if (feed.activeBookings.isNotEmpty) ..._activeBookings(context, margin),

      if (feed.topCreators.isNotEmpty) ...[
        const SizedBox(height: AppSpacing.lg),
        // `top_creators` is sorted by rating and `trending` by the follower
        // totals creators report (GET /home), the same for every viewer, so
        // the headings say that rather than "handpicked" or "most booked".
        header(
          'Top-rated creators',
          subtitle: 'Highest rated first',
          route: Routes.explore,
        ),
        const SizedBox(height: AppSpacing.xs),
        _CreatorGrid(creators: feed.topCreators, margin: margin),
      ],

      if (feed.trending.isNotEmpty) ...[
        const SizedBox(height: AppSpacing.lg),
        header(
          'Largest audiences',
          subtitle: 'Ranked by self-reported followers',
          route: Routes.explore,
        ),
        const SizedBox(height: AppSpacing.xs),
        _FeaturedRail(creators: feed.trending, margin: margin),
      ],

      if (feed.newCreators.isNotEmpty) ...[
        const SizedBox(height: AppSpacing.lg),
        header('New on Zusty', route: Routes.explore),
        const SizedBox(height: AppSpacing.xs),
        _CreatorRail(creators: feed.newCreators, margin: margin),
      ],
    ];
  }
}

class _CategoryStrip extends StatelessWidget {
  const _CategoryStrip({required this.categories, required this.margin});

  final List<Category> categories;
  final double margin;

  @override
  Widget build(BuildContext context) {
    final scale = MediaQuery.textScalerOf(context).scale(12) / 12;

    return SizedBox(
      height: 92 + 16 * scale,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsets.symmetric(horizontal: margin - AppSpacing.xs),
        itemCount: categories.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.base),
        itemBuilder: (_, i) => Reveal(
          index: i,
          child: CategoryCircle(category: categories[i]),
        ),
      ),
    );
  }
}

class _CreatorGrid extends StatelessWidget {
  const _CreatorGrid({
    required this.creators,
    required this.margin,
    this.columns = 2,
  });

  final List<Creator> creators;
  final double margin;
  final int columns;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.symmetric(horizontal: margin),
      child: GridView.builder(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        padding: EdgeInsets.zero,
        gridDelegate: CreatorGridDelegate(
          maxColumns: columns,
          textScale: MediaQuery.textScalerOf(context).scale(14) / 14,
        ),
        itemCount: creators.length,
        itemBuilder: (_, i) => CreatorGridCard(creator: creators[i]),
      ),
    );
  }
}

/// The large featured cards, for the first creator carousel.
class _FeaturedRail extends StatelessWidget {
  const _FeaturedRail({required this.creators, required this.margin});

  final List<Creator> creators;
  final double margin;

  static const _width = 272.0;

  @override
  Widget build(BuildContext context) {
    final scale = MediaQuery.textScalerOf(context).scale(14) / 14;

    return SizedBox(
      // Photo at 16:11, then the reach row, price and actions beneath it.
      height: _width * 11 / 16 + 108 * scale,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsets.symmetric(horizontal: margin),
        itemCount: creators.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.sm),
        itemBuilder: (_, i) =>
            CreatorFeatureCard(creator: creators[i], width: _width),
      ),
    );
  }
}

class _CreatorRail extends StatelessWidget {
  const _CreatorRail({required this.creators, required this.margin});

  final List<Creator> creators;
  final double margin;

  static const _width = 152.0;

  @override
  Widget build(BuildContext context) {
    final scale = MediaQuery.textScalerOf(context).scale(14) / 14;

    return SizedBox(
      height: _width - AppSpacing.xs * 2 + 96 * scale,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsets.symmetric(horizontal: margin),
        itemCount: creators.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.sm),
        itemBuilder: (_, i) =>
            CreatorRailCard(creator: creators[i], width: _width),
      ),
    );
  }
}

/// The blocks at the top of the feed that are not rails or grids: the search
/// bar and a lone banner. On a tablet they start at the same left edge as
/// every section below them.
///
/// On a medium window (a tablet in portrait) they span the column as the
/// sections do, so the page is the phone's layout with the same left and
/// right edges throughout. On an expanded one (landscape), whose column is up
/// to 1040dp, they keep a readable width ([maxWidth]) from that left edge
/// instead of becoming a 1000dp search bar or a 4:1 banner strip. On a phone
/// [child] is returned as is.
class _LeadBlock extends StatelessWidget {
  const _LeadBlock({
    required this.child,
    this.maxWidth = ContentWidth.readable,
  });

  final Widget child;

  /// The widest the block grows on an expanded window, margins included
  /// when [child] carries them.
  final double maxWidth;

  @override
  Widget build(BuildContext context) {
    if (!WindowSize.of(context).isExpanded) return child;

    return ContentWidthBox(
      maxWidth: maxWidth,
      alignment: AlignmentDirectional.topStart,
      child: child,
    );
  }
}

class _BannerCarousel extends StatelessWidget {
  const _BannerCarousel({required this.banners, required this.margin});

  final List<HomeBanner> banners;
  final double margin;

  /// From this column width a tablet shows two banners side by side, each
  /// close to a phone banner's shape, rather than one long strip whose photo
  /// `BoxFit.cover` would crop to a sliver.
  static const _twoUpWidth = 760.0;

  ZHeroSlider _slider({
    Key? key,
    required double height,
    required double viewportFraction,
  }) {
    return ZHeroSlider(
      key: key,
      itemCount: banners.length,
      height: height,
      leadingInset: margin,
      viewportFraction: viewportFraction,
      autoPlay: banners.length > 1,
      itemBuilder: (context, i, offset) => Padding(
        padding: EdgeInsets.only(right: banners.length > 1 ? 0 : margin),
        child: _BannerCard(banner: banners[i], offset: offset),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (WindowSize.of(context).isCompact) {
      return _slider(
        height: 184,
        viewportFraction: banners.length > 1 ? 0.92 : 1,
      );
    }

    // A tablet: a little taller, so the headline is not letterboxed. A lone
    // banner lines up with the search bar above it and the sections below.
    if (banners.length == 1) {
      return _LeadBlock(child: _slider(height: 240, viewportFraction: 1));
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        final fraction = Breakpoints.reaches(constraints.maxWidth, _twoUpWidth)
            ? 0.5
            : 0.92;
        return _slider(
          // The page controller keeps the fraction it was made with, so a
          // tablet turned past the two-up width starts a fresh carousel.
          key: ValueKey(fraction),
          height: 220,
          viewportFraction: fraction,
        );
      },
    );
  }
}

class _BannerCard extends StatelessWidget {
  const _BannerCard({required this.banner, required this.offset});

  final HomeBanner banner;
  final ValueListenable<double> offset;

  Future<void> _open(BuildContext context) async {
    final link = banner.actionUrl;
    if (link == null) return;

    if (isExternalLink(link)) {
      await launchUrl(Uri.parse(link), mode: LaunchMode.externalApplication);
      return;
    }

    final route = Routes.resolveMenuTarget(link);
    if (route != null && context.mounted) context.push(route);
  }

  @override
  Widget build(BuildContext context) {
    final image = banner.imageUrl;

    return Material(
      borderRadius: AppRadius.xlAll,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: banner.actionUrl == null ? null : () => _open(context),
        child: Stack(
          fit: StackFit.expand,
          children: [
            // The brand gradient is the base layer, so a banner whose image
            // is missing or still loading reads as designed, not as broken.
            DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [AppColors.primaryDark, AppColors.primary],
                ),
              ),
            ),
            if (image != null && image.isNotEmpty)
              HeroParallax(
                offset: offset,
                child: CachedNetworkImage(
                  imageUrl: AppConfig.resolveMediaUrl(image),
                  fit: BoxFit.cover,
                  fadeInDuration: const Duration(milliseconds: 250),
                  errorWidget: (_, _, _) => const SizedBox.shrink(),
                ),
              ),
            // Left-to-right plum scrim so the headline stays readable on any
            // photograph.
            DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.centerLeft,
                  end: Alignment.centerRight,
                  colors: [
                    AppColors.primaryDark.withValues(alpha: 0.92),
                    AppColors.primaryDark.withValues(alpha: 0.45),
                    Colors.transparent,
                  ],
                  stops: const [0, 0.55, 1],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: FractionallySizedBox(
                widthFactor: 0.72,
                alignment: Alignment.centerLeft,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      banner.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.headlineSmall.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                        height: 1.25,
                      ),
                    ),
                    // Two lines fit under a two-line headline at the default
                    // text size, so a one-sentence subtitle (up to about 65
                    // characters on a 360 dp phone) is not cut mid-sentence.
                    // Flexible, so at a larger text size it gives way rather
                    // than the headline.
                    if (banner.subtitle.isNotEmpty) ...[
                      const SizedBox(height: AppSpacing.base),
                      Flexible(
                        child: Text(
                          banner.subtitle,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.bodySmall.copyWith(
                            color: Colors.white.withValues(alpha: 0.85),
                          ),
                        ),
                      ),
                    ],
                    const SizedBox(height: AppSpacing.sm),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: AppSpacing.md,
                        vertical: AppSpacing.xs,
                      ),
                      decoration: const BoxDecoration(
                        color: Colors.white,
                        borderRadius: AppRadius.pill,
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Flexible(
                            child: Text(
                              banner.ctaLabel,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: AppTypography.labelLarge
                                  .copyWith(color: AppColors.primary),
                            ),
                          ),
                          const SizedBox(width: 4),
                          Icon(
                            Icons.arrow_forward_rounded,
                            size: 16,
                            color: AppColors.primary,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A booking in flight: who with, where it stands, what it is worth and when
/// it is due.
class _ActiveBookingCard extends StatelessWidget {
  const _ActiveBookingCard({required this.booking});

  final Booking booking;

  static double height(BuildContext context) =>
      84 + 34 * (MediaQuery.textScalerOf(context).scale(14) / 14);

  @override
  Widget build(BuildContext context) {
    final due = booking.deliveryDueAt;

    return SizedBox(
      width: 292,
      child: ZCard(
        padding: const EdgeInsets.all(AppSpacing.md),
        onTap: () => context.push(Routes.booking(booking.id)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                ZAvatar(
                  imageUrl: booking.counterparty.avatarUrl,
                  name: booking.counterparty.name,
                  size: 44,
                  isVerified: booking.counterparty.isVerified,
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        booking.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.titleSmall,
                      ),
                      const SizedBox(height: 2),
                      Text(
                        booking.counterparty.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySmall,
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                Flexible(
                  child: StatusChip(
                    label: booking.statusLabel,
                    tone: booking.tone,
                    dense: true,
                  ),
                ),
                if (due != null) ...[
                  const SizedBox(width: AppSpacing.xs),
                  Icon(
                    Icons.event_rounded,
                    size: 14,
                    color: AppColors.onSurfaceVariant,
                  ),
                  const SizedBox(width: 2),
                  Text(
                    Formatters.shortDate(due),
                    style: AppTypography.labelSmall,
                  ),
                ],
                const Spacer(),
                Text(
                  booking.amountLabel,
                  style: AppTypography.titleSmall
                      .copyWith(color: AppColors.primary),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _HomeSkeleton extends StatelessWidget {
  const _HomeSkeleton();

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);

    return ListView(
      padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, margin, AppSpacing.xl),
      children: [
        // Where the search bar will be (the list's padding already holds
        // the margins).
        _LeadBlock(
          maxWidth: ContentWidth.readable - 2 * margin,
          child: const ZShimmer(height: 52, borderRadius: AppRadius.pill),
        ),
        const SizedBox(height: AppSpacing.md),
        const ZShimmer(height: 176, borderRadius: AppRadius.xlAll),
        const SizedBox(height: AppSpacing.lg),
        const ZShimmer(width: 160, height: 18, borderRadius: AppRadius.smAll),
        const SizedBox(height: AppSpacing.sm),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: List.generate(
            5,
            (i) => const ZShimmer(
              width: 52,
              height: 52,
              borderRadius: AppRadius.pill,
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.lg),
        const GridSkeleton(),
      ],
    );
  }
}

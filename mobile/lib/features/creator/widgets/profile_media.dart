import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/creator.dart';
import '../../../models/taxonomy.dart';
import '../../../providers/taxonomy_provider.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import 'media_viewer.dart';
import 'portfolio_tile.dart';
import 'profile_layout.dart';

/// The Media tab: shortcuts into each portfolio kind with its count, the
/// creator's featured work as a grid, and the way into the full gallery.
///
/// The grid is the server's `recent_portfolio` (featured first, then the
/// creator's own order), so it opens in the viewer in place; the kind
/// shortcuts lead to the dedicated gallery, reels and videos screens, which
/// page through everything.
class ProfileMediaTab extends ConsumerWidget {
  const ProfileMediaTab({super.key, required this.profile});

  final CreatorProfile profile;

  /// Reels and shorts open the reels screen, videos and lives the videos
  /// screen; every other kind opens the gallery, which tabs them all.
  static String routeFor(String kind, String handle) => switch (kind) {
    'reel' || 'short' => Routes.creatorReels(handle),
    'video' || 'live' => Routes.creatorVideos(handle),
    _ => Routes.creatorGallery(handle),
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final creator = profile.creator;
    final items = profile.recentPortfolio;
    final taxonomy = ref.watch(taxonomiesProvider);

    // Only kinds that actually hold something, largest first: a row of
    // "Audio 0 · Document 0" shortcuts is noise.
    final kinds =
        profile.portfolioCounts.entries
            .where((entry) => entry.value > 0)
            .toList()
          ..sort((a, b) => b.value.compareTo(a.value));

    if (items.isEmpty && kinds.isEmpty) {
      return ProfileTabBody(
        children: [
          EmptyState(
            title: 'No portfolio yet',
            message: profile.isOwner
                ? 'Add your best work so brands can see what you make. Our '
                      'team may review new items before they show here. You '
                      'can also manage your portfolio from the Profile tab, '
                      'under For creators.'
                : '${firstNameOf(creator.displayName)} has not published '
                      'any work here yet.',
            icon: Icons.photo_library_outlined,
            actionLabel: profile.isOwner ? 'Manage portfolio' : null,
            onAction: profile.isOwner
                ? () => context.push(Routes.myPortfolio)
                : null,
            compact: true,
          ),
        ],
      );
    }

    return ProfileTabBody(
      children: [
        if (kinds.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.md),
            child: Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              children: [
                for (final entry in kinds)
                  ZChip(
                    label:
                        '${taxonomy.label(Tax.portfolioKind, entry.key)}'
                        '  ${entry.value}',
                    icon: portfolioKindIcon(entry.key),
                    onTap: () =>
                        context.push(routeFor(entry.key, creator.handle)),
                  ),
              ],
            ),
          ),
        if (items.isNotEmpty)
          // At most a dozen featured items, so a non-lazy grid costs nothing
          // and keeps the tab a plain column inside the page's scroll view.
          GridView.builder(
            shrinkWrap: true,
            padding: EdgeInsets.zero,
            physics: const NeverScrollableScrollPhysics(),
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 3,
              crossAxisSpacing: AppSpacing.xs,
              mainAxisSpacing: AppSpacing.xs,
              // Portrait cells: most creator work is shot vertically, and a
              // 4:5 crop shows a reel's subject where a square cuts it off.
              childAspectRatio: 4 / 5,
            ),
            itemCount: items.length,
            itemBuilder: (context, i) => PortfolioTile(
              item: items[i],
              onTap: () =>
                  MediaViewer.open(context, items: items, initialIndex: i),
            ),
          ),
        Padding(
          padding: const EdgeInsets.only(top: AppSpacing.lg),
          child: ZButton.outlined(
            label: profile.portfolioTotal > items.length
                ? 'View all ${profile.portfolioTotal} in gallery'
                : 'Open gallery',
            icon: Icons.photo_library_outlined,
            size: ZButtonSize.medium,
            onPressed: () =>
                context.push(Routes.creatorGallery(creator.handle)),
          ),
        ),
      ],
    );
  }
}

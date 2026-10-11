import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_response.dart';
import '../../core/theme/app_theme.dart';
import '../../models/creator.dart';
import '../../models/taxonomy.dart';
import '../../providers/core_providers.dart';
import '../../providers/discovery_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import 'widgets/media_viewer.dart';
import 'widgets/portfolio_tile.dart';
import 'widgets/profile_layout.dart';

/// Screen 10 — Creator Gallery (also 21 Reels and 22 Videos).
///
/// One tab per portfolio kind over the same endpoint, which takes a `kind`
/// parameter. Tabs come from the admin-managed `portfolio_kind` taxonomy and
/// carry the creator's count for each; kinds the creator has nothing in are
/// left out, so a creator with reels and photos sees two tabs rather than ten
/// with eight empty.
class CreatorGalleryScreen extends ConsumerWidget {
  const CreatorGalleryScreen({
    super.key,
    required this.handle,
    this.initialKind = 'post',
    this.title = 'Gallery',
  });

  final String handle;
  final String initialKind;
  final String title;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(creatorProfileProvider(handle));
    final data = profile.valueOrNull;

    if (data == null) {
      return ZScaffold(
        backgroundColor: AppColors.surface,
        padded: false,
        constrain: false,
        appBar: ZAppBar(title: title),
        body: profile.hasError
            ? ErrorState(
                error: profile.error!,
                onRetry: () => ref.invalidate(creatorProfileProvider(handle)),
              )
            : const _GridSkeleton(video: false),
      );
    }

    final taxonomy = ref.watch(taxonomiesProvider);
    final all = taxonomy.terms(Tax.portfolioKind);

    // The kinds worth a tab: only those with items. When the kind this screen
    // was opened for is empty it opens on the first kind that has something
    // (the plain gallery route always asks for "post", which many creators
    // have none of). With nothing at all, every kind shows its empty state.
    var kinds = all
        .where((term) => (data.portfolioCounts[term.value] ?? 0) > 0)
        .toList();
    if (kinds.isEmpty) kinds = all;

    return _GalleryTabs(
      // A new tab set needs a new TabController; keying on it rebuilds the
      // state instead of crashing on a length mismatch.
      key: ValueKey(kinds.map((k) => k.value).join(',')),
      profile: data,
      kinds: kinds,
      initialKind: initialKind,
    );
  }
}

class _GalleryTabs extends StatefulWidget {
  const _GalleryTabs({
    super.key,
    required this.profile,
    required this.kinds,
    required this.initialKind,
  });

  final CreatorProfile profile;
  final List<TaxonomyTerm> kinds;
  final String initialKind;

  @override
  State<_GalleryTabs> createState() => _GalleryTabsState();
}

class _GalleryTabsState extends State<_GalleryTabs>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(
    length: widget.kinds.length,
    vsync: this,
    initialIndex: widget.kinds
        .indexWhere((term) => term.value == widget.initialKind)
        .clamp(0, widget.kinds.isEmpty ? 0 : widget.kinds.length - 1),
  );

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final profile = widget.profile;
    final creator = profile.creator;

    return ZScaffold(
      backgroundColor: AppColors.surface,
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        titleWidget: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              creator.displayName,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.titleMedium,
            ),
            Text(
              profile.portfolioTotal == 1
                  ? '1 item in portfolio'
                  : '${profile.portfolioTotal} items in portfolio',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.bodySmall,
            ),
          ],
        ),
        bottom: TabBar(
          controller: _tabs,
          isScrollable: true,
          tabAlignment: TabAlignment.start,
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
          tabs: [
            for (final term in widget.kinds)
              Tab(
                height: 46,
                child: _TabLabel(
                  label: term.label,
                  icon: portfolioKindIcon(term.value),
                  count: profile.portfolioCounts[term.value] ?? 0,
                ),
              ),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabs,
        children: [
          for (final term in widget.kinds)
            PortfolioGrid(
              creatorId: creator.id,
              kind: term.value,
              kindLabel: term.label,
              creatorName: creator.displayName,
              creatorUserId: creator.userId,
              canReport: !profile.isOwner,
            ),
        ],
      ),
    );
  }
}

class _TabLabel extends StatelessWidget {
  const _TabLabel({
    required this.label,
    required this.icon,
    required this.count,
  });

  final String label;
  final IconData icon;
  final int count;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 18),
        const SizedBox(width: 6),
        Text(label),
        if (count > 0) ...[
          const SizedBox(width: 6),
          Text(
            '$count',
            style: AppTypography.labelSmall.copyWith(
              color: AppColors.onSurfaceVariant,
            ),
          ),
        ],
      ],
    );
  }
}

/// A responsive media grid for one portfolio kind, paging as it scrolls.
///
/// Photos and posts get a tighter grid of 4:5 tiles; reels and videos a wider
/// one at 9:16 with views and duration. The column count follows the width,
/// so a small phone, a large phone and a tablet each get tiles of a sensible
/// size rather than a fixed three columns stretched or squeezed.
///
/// The first page comes from [portfolioProvider], so it is shared and cached;
/// later pages are fetched here as the user nears the end.
class PortfolioGrid extends ConsumerStatefulWidget {
  const PortfolioGrid({
    super.key,
    required this.creatorId,
    required this.kind,
    this.kindLabel,
    this.creatorName,
    this.creatorUserId,
    this.canReport = false,
  });

  final int creatorId;
  final String kind;
  final String? kindLabel;
  final String? creatorName;

  /// The account behind the work, offered for blocking after a report.
  final int? creatorUserId;

  /// Whether the viewer offers "Report post": not on the creator's own work.
  final bool canReport;

  @override
  ConsumerState<PortfolioGrid> createState() => _PortfolioGridState();
}

class _PortfolioGridState extends ConsumerState<PortfolioGrid>
    with AutomaticKeepAliveClientMixin {
  /// Pages after the first, appended as they arrive.
  Paginated<PortfolioItem>? _more;
  bool _loadingMore = false;
  bool _moreFailed = false;

  PortfolioQuery get _query => (creatorId: widget.creatorId, kind: widget.kind);

  bool get _isVideoKind =>
      const {'reel', 'video', 'short', 'live'}.contains(widget.kind);

  // Keeps each tab's pages and scroll position when swiping between tabs.
  @override
  bool get wantKeepAlive => true;

  Future<void> _refresh() async {
    setState(() {
      _more = null;
      _moreFailed = false;
    });

    try {
      ref.invalidate(portfolioProvider(_query));
      await ref.read(portfolioProvider(_query).future);
    } catch (_) {
      // The grid shows the error state itself.
    }
  }

  Future<void> _loadMore(Paginated<PortfolioItem> current) async {
    if (_loadingMore || !current.hasMore) return;

    setState(() {
      _loadingMore = true;
      _moreFailed = false;
    });

    try {
      final next = await ref
          .read(creatorRepositoryProvider)
          .portfolio(
            widget.creatorId,
            kind: widget.kind,
            page: current.nextPage,
          );

      if (!mounted) return;
      setState(() => _more = _more == null ? next : _more!.merge(next));
    } catch (_) {
      if (mounted) setState(() => _moreFailed = true);
    } finally {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);

    final media = ref.watch(portfolioProvider(_query));
    final margin = AppBreakpoints.pageMargin(context);

    final first = media.valueOrNull;

    if (first == null) {
      return ZRefresh(
        onRefresh: _refresh,
        child: media.hasError
            ? _ScrollableFill(
                child: ErrorState(
                  error: media.error!,
                  onRetry: () => ref.invalidate(portfolioProvider(_query)),
                ),
              )
            : _GridSkeleton(video: _isVideoKind),
      );
    }

    final page = _more == null ? first : first.merge(_more!);
    final items = page.items;

    if (items.isEmpty) {
      final label = (widget.kindLabel ?? widget.kind).toLowerCase();
      final name = widget.creatorName == null
          ? 'This creator'
          : firstNameOf(widget.creatorName!);

      return ZRefresh(
        onRefresh: _refresh,
        child: _ScrollableFill(
          child: EmptyState(
            title: 'Nothing here yet',
            message: '$name has not published any $label yet.',
            icon: portfolioKindIcon(widget.kind),
            compact: true,
          ),
        ),
      );
    }

    return ZRefresh(
      onRefresh: _refresh,
      child: NotificationListener<ScrollNotification>(
        onNotification: (notification) {
          // Deferred: a scroll notification can arrive mid-layout (a content
          // resize settling the position), where setState is not allowed.
          if (notification is ScrollUpdateNotification &&
              notification.metrics.extentAfter < 600 &&
              page.hasMore &&
              !_loadingMore &&
              !_moreFailed) {
            Future.microtask(() {
              if (mounted) _loadMore(page);
            });
          }
          return false;
        },
        child: CustomScrollView(
          key: PageStorageKey<String>('gallery-${widget.kind}'),
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: [
            SliverPadding(
              padding: EdgeInsets.fromLTRB(
                margin,
                AppSpacing.md,
                margin,
                AppSpacing.md,
              ),
              sliver: SliverGrid(
                gridDelegate: _delegate(_isVideoKind),
                delegate: SliverChildBuilderDelegate(
                  (context, i) => PortfolioTile(
                    item: items[i],
                    onTap: () => MediaViewer.open(
                      context,
                      items: items,
                      initialIndex: i,
                      canReport: widget.canReport,
                      creatorName: widget.creatorName,
                      creatorUserId: widget.creatorUserId,
                    ),
                  ),
                  childCount: items.length,
                ),
              ),
            ),
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xl),
                child: Center(
                  child: _loadingMore
                      ? const BrandLoader(size: 28)
                      : _moreFailed
                      ? TextButton(
                          onPressed: () => _loadMore(page),
                          child: const Text('Could not load more. Retry'),
                        )
                      : page.hasMore
                      ? const SizedBox(height: 28)
                      : Text(
                          items.length == 1
                              ? '1 item'
                              : '${items.length} items',
                          style: AppTypography.bodySmall,
                        ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Column count from the available width: about three stills or two videos
/// across a phone, more on a tablet.
SliverGridDelegate _delegate(bool video) =>
    SliverGridDelegateWithMaxCrossAxisExtent(
      maxCrossAxisExtent: video ? 200 : 140,
      crossAxisSpacing: AppSpacing.xs,
      mainAxisSpacing: AppSpacing.xs,
      childAspectRatio: video ? 9 / 16 : 4 / 5,
    );

/// Lets an empty or error state sit centred while still being pullable to
/// refresh — a bare Center has nothing to overscroll.
class _ScrollableFill extends StatelessWidget {
  const _ScrollableFill({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return CustomScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      slivers: [SliverFillRemaining(hasScrollBody: false, child: child)],
    );
  }
}

/// Tile-shaped shimmer in the same grid the content will use, so nothing
/// jumps when it lands.
class _GridSkeleton extends StatelessWidget {
  const _GridSkeleton({required this.video});

  final bool video;

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);

    return CustomScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      slivers: [
        SliverPadding(
          padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, 0),
          sliver: SliverGrid(
            gridDelegate: _delegate(video),
            delegate: SliverChildBuilderDelegate(
              (_, _) => const ZShimmer(borderRadius: AppRadius.mdAll),
              childCount: 9,
            ),
          ),
        ),
      ],
    );
  }
}

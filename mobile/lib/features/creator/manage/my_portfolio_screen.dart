import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/creator_tools.dart';
import '../../../models/taxonomy.dart';
import '../../../providers/creator_tools_provider.dart';
import '../../../providers/taxonomy_provider.dart';
import '../../../router/routes.dart';
import '../../../shared/motion/motion.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_dialogs.dart';
import '../../../shared/widgets/z_feedback.dart';
import '../../../shared/widgets/z_image.dart';
import '../../../shared/widgets/z_scaffold.dart';
import '../widgets/portfolio_tile.dart';
import 'widgets/creator_tools_common.dart';

/// The creator's own portfolio, every item in it — including the ones the
/// team has not reviewed yet and the ones it turned down, which the public
/// gallery never shows.
class MyPortfolioScreen extends ConsumerWidget {
  const MyPortfolioScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final portfolio = ref.watch(myPortfolioProvider);
    final hasAny = portfolio.valueOrNull?.items.isNotEmpty ?? false;
    final isFull = portfolio.valueOrNull?.isFull ?? false;

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Portfolio'),
      bottomBar: hasAny
          ? ZButton(
              label: 'Add to portfolio',
              icon: Icons.add_photo_alternate_outlined,
              onPressed: isFull ? null : () => context.push(Routes.newPortfolioItem),
            )
          : null,
      body: CreatorToolsGate(
        child: portfolio.when(
          skipLoadingOnRefresh: true,
          loading: () => Padding(
            padding: EdgeInsets.symmetric(
              horizontal: AppBreakpoints.pageMargin(context),
            ),
            child: const GridSkeleton(count: 6),
          ),
          error: (error, _) => CreatorToolsErrorView(
            error: error,
            onRetry: () => ref.invalidate(myPortfolioProvider),
          ),
          data: (data) => _PortfolioGrid(state: data),
        ),
      ),
    );
  }
}

class _PortfolioGrid extends ConsumerWidget {
  const _PortfolioGrid({required this.state});

  final MyPortfolioState state;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final margin = AppBreakpoints.pageMargin(context);
    final items = state.items;
    final inReview = items.where((i) => i.review == PortfolioReview.pending).length;
    final columns = WindowSize.of(context).isCompact ? 2 : 3;

    return ZRefresh(
      onRefresh: () => ref.read(myPortfolioProvider.notifier).refresh(),
      child: CustomScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        slivers: [
          SliverPadding(
            padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, 0),
            sliver: SliverList.list(
              children: [
                if (state.locked) const LockedSectionBanner(what: 'Your portfolio'),
                if (items.isEmpty)
                  EmptyState(
                    icon: Icons.photo_library_outlined,
                    title: 'Your portfolio is empty',
                    message:
                        'Add your best work so brands can see what you make: a '
                        'photo, or a cover picture with a link to the reel or '
                        'video.',
                    actionLabel: 'Add to portfolio',
                    onAction: () => context.push(Routes.newPortfolioItem),
                  )
                else ...[
                  if (inReview > 0) ...[
                    ZInfoBanner(
                      icon: Icons.hourglass_top_rounded,
                      message: inReview == 1
                          ? '1 item is waiting for our team to review it. It '
                                'appears on your profile once approved.'
                          : '$inReview items are waiting for our team to '
                                'review them. They appear on your profile once '
                                'approved.',
                    ),
                    const SizedBox(height: AppSpacing.md),
                  ],
                  Text(
                    state.total == 1 ? '1 item' : '${state.total} items',
                    style: AppTypography.bodySmall,
                  ),
                  if (state.isFull) ...[
                    const SizedBox(height: 4),
                    Text(
                      'Your portfolio holds up to ${state.maxItems} items. '
                      'Remove one to add another.',
                      style: AppTypography.bodySmall,
                    ),
                  ],
                  const SizedBox(height: AppSpacing.xs),
                ],
              ],
            ),
          ),
          if (items.isNotEmpty)
            SliverPadding(
              padding: EdgeInsets.symmetric(horizontal: margin),
              sliver: SliverGrid.builder(
                itemCount: items.length,
                gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: columns,
                  mainAxisSpacing: AppSpacing.sm,
                  crossAxisSpacing: AppSpacing.sm,
                  childAspectRatio: 4 / 5,
                ),
                itemBuilder: (_, i) => _ManageTile(own: items[i]),
              ),
            ),
          SliverPadding(
            padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, AppSpacing.xxl),
            sliver: SliverToBoxAdapter(
              child: state.page.hasMore
                  ? Center(
                      child: ZButton.outlined(
                        label: 'Show more',
                        size: ZButtonSize.medium,
                        expand: false,
                        isLoading: state.loadingMore,
                        onPressed: () => _loadMore(context, ref),
                      ),
                    )
                  : const SizedBox.shrink(),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _loadMore(BuildContext context, WidgetRef ref) async {
    try {
      await ref.read(myPortfolioProvider.notifier).loadMore();
    } catch (error) {
      if (context.mounted) {
        showZSnack(context, failureMessage(error), isError: true);
      }
    }
  }
}

/// One item in the editor's grid: the picture, where it stands, and a way
/// to remove it.
class _ManageTile extends ConsumerWidget {
  const _ManageTile({required this.own});

  final OwnPortfolioItem own;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final item = own.item;
    final kind = ref.watch(taxonomiesProvider).label(Tax.portfolioKind, item.mediaKind);
    final status = _status(own);

    void open() => _showDetails(context, ref, own, kind);

    return Semantics(
      button: true,
      label: [
        kind,
        if (item.title.isNotEmpty) item.title,
        if (status != null) status.$1,
      ].join(', '),
      hint: 'Opens the details, with the option to remove it',
      onTap: open,
      excludeSemantics: true,
      child: Pressable(
        pressedScale: 0.97,
        child: GestureDetector(
          onTap: open,
          child: ClipRRect(
            borderRadius: AppRadius.mdAll,
            child: Stack(
              fit: StackFit.expand,
              children: [
                ColoredBox(color: AppColors.surfaceContainerHigh),
                ZImage(
                  url: item.displayUrl,
                  fallbackIcon: portfolioKindIcon(item.mediaKind),
                ),
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
                Positioned(
                  left: AppSpacing.xs,
                  right: AppSpacing.xs,
                  bottom: AppSpacing.xs,
                  child: Row(
                    children: [
                      Icon(
                        portfolioKindIcon(item.mediaKind),
                        size: 14,
                        color: Colors.white,
                      ),
                      const SizedBox(width: 4),
                      Expanded(
                        child: Text(
                          item.title.isNotEmpty ? item.title : kind,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.labelSmall.copyWith(
                            color: Colors.white,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                if (status != null)
                  Positioned(
                    top: AppSpacing.xs,
                    left: AppSpacing.xs,
                    right: AppSpacing.xs,
                    child: Align(
                      alignment: Alignment.topLeft,
                      child: StatusChip(
                        label: status.$1,
                        tone: status.$2,
                        icon: status.$3,
                        dense: true,
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The label, tone and glyph for an item brands cannot see — or null when
/// they can, so a live grid stays clean.
(String, StatusTone, IconData)? _status(OwnPortfolioItem own) =>
    switch (own.review) {
      PortfolioReview.pending => (
        'In review',
        StatusTone.warning,
        Icons.hourglass_top_rounded,
      ),
      PortfolioReview.rejected => (
        'Not approved',
        StatusTone.error,
        Icons.block_rounded,
      ),
      PortfolioReview.approved when !own.isActive => (
        'Hidden',
        StatusTone.neutral,
        Icons.visibility_off_outlined,
      ),
      _ => null,
    };

/// One item, larger, with what the team said about it and the way to
/// remove it.
Future<void> _showDetails(
  BuildContext context,
  WidgetRef ref,
  OwnPortfolioItem own,
  String kind,
) async {
  final item = own.item;
  final status = _status(own);

  final remove = await showZSheet<bool>(
    context,
    title: item.title.isNotEmpty ? item.title : kind,
    subtitle: kind,
    builder: (sheetContext) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        ClipRRect(
          borderRadius: AppRadius.lgAll,
          child: AspectRatio(
            aspectRatio: item.aspectRatio.clamp(0.5, 2.0).toDouble(),
            child: ZImage(
              url: item.mediaUrl ?? item.thumbnailUrl,
              fit: BoxFit.cover,
              fallbackIcon: portfolioKindIcon(item.mediaKind),
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        ZInfoBanner(
          tone: status?.$2 ?? StatusTone.success,
          icon: status?.$3 ?? Icons.visibility_outlined,
          message: switch (own.review) {
            PortfolioReview.pending =>
              'Our team is reviewing this. It appears on your profile once '
                  'approved.',
            PortfolioReview.rejected =>
              own.reasonLabel == null
                  ? 'Our team did not approve this, so brands cannot see it.'
                  : 'Our team did not approve this: ${own.reasonLabel}. '
                        'Brands cannot see it.',
            PortfolioReview.approved =>
              own.isActive
                  ? 'Live on your profile.'
                  : 'Hidden from your profile.',
          },
        ),
        if (item.caption.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.md),
          Text(item.caption, style: AppTypography.bodyMedium),
        ],
        if (item.externalUrl != null) ...[
          const SizedBox(height: AppSpacing.sm),
          Row(
            children: [
              Icon(Icons.link_rounded, size: 18, color: AppColors.onSurfaceVariant),
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: SelectableText(
                  item.externalUrl!,
                  style: AppTypography.bodySmall,
                ),
              ),
            ],
          ),
        ],
        const SizedBox(height: AppSpacing.lg),
        ZButton(
          label: 'Remove from portfolio',
          variant: ZButtonVariant.danger,
          icon: Icons.delete_outline_rounded,
          onPressed: () => Navigator.of(sheetContext).pop(true),
        ),
      ],
    ),
  );

  if (remove != true || !context.mounted) return;

  final confirmed = await confirmRemoval(
    context,
    title: 'Remove this from your portfolio?',
    message: 'It will no longer appear on your profile. This cannot be undone.',
  );
  if (!confirmed || !context.mounted) return;

  try {
    await ref.read(myPortfolioProvider.notifier).remove(own.id);
    if (context.mounted) showZSnack(context, 'Removed from your portfolio.');
  } catch (error) {
    if (context.mounted) {
      showZSnack(context, failureMessage(error), isError: true);
    }
  }
}

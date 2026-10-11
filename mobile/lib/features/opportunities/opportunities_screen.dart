import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/category.dart';
import '../../providers/discovery_provider.dart';
import '../../providers/opportunity_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/campaign_card.dart';
import '../../shared/widgets/menu_tile.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_tabs.dart';
import '../../shared/widgets/z_text_field.dart';
import 'widgets/opportunity_states.dart';

/// Opportunities — the public brief board a creator browses for paid work.
///
/// One scroll: a short headline, search, category pills, the sort, and the
/// cards. Filters live at the top of the same scroll rather than pinned, so
/// on a small phone the cards get the whole screen once the creator starts
/// reading. Pages load as the end of the list comes into view.
class OpportunitiesScreen extends StatelessWidget {
  const OpportunitiesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return ZScaffold(
      padded: false,
      maxContentWidth: ContentWidth.wide,
      appBar: ZAppBar(
        title: 'Opportunities',
        actions: [
          IconButton(
            tooltip: 'My applications',
            icon: const Icon(Icons.assignment_outlined),
            onPressed: () => context.push(Routes.applications),
          ),
        ],
      ),
      body: const CreatorOnlyGate(child: _Board()),
    );
  }
}

class _Board extends ConsumerStatefulWidget {
  const _Board();

  @override
  ConsumerState<_Board> createState() => _BoardState();
}

class _BoardState extends ConsumerState<_Board> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  Timer? _debounce;

  OpportunityQuery _query = const OpportunityQuery();

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_maybeLoadMore);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  /// Fetches the next page once the reader is within a couple of cards of the
  /// end. The controller ignores repeat calls while a page is in flight.
  void _maybeLoadMore() {
    if (!_scroll.hasClients) return;
    if (_scroll.position.extentAfter < 600) {
      ref.read(opportunityFeedProvider(_query).notifier).loadMore();
    }
  }

  /// Typing settles for a moment before it becomes a request, so "skincare"
  /// is one search rather than eight.
  void _onSearchChanged(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      if (mounted) setState(() => _query = _query.copyWith(q: value));
    });
    // Rebuild now only for the clear button's visibility.
    setState(() {});
  }

  void _submitSearch(String value) {
    _debounce?.cancel();
    setState(() => _query = _query.copyWith(q: value));
  }

  void _clearFilters() {
    _debounce?.cancel();
    _search.clear();
    setState(() => _query = OpportunityQuery(sort: _query.sort));
  }

  Future<void> _pickSort() async {
    final picked = await showZSheet<OpportunitySort>(
      context,
      title: 'Sort briefs',
      builder: (context) => Column(
        children: [
          for (final sort in OpportunitySort.values)
            ZMenuTile(
              icon: switch (sort) {
                OpportunitySort.newest => Icons.fiber_new_outlined,
                OpportunitySort.endingSoon => Icons.timer_outlined,
                OpportunitySort.budgetHigh => Icons.payments_outlined,
                OpportunitySort.popular => Icons.local_fire_department_outlined,
              },
              title: sort.label,
              showChevron: false,
              trailing: sort == _query.sort
                  ? Icon(Icons.check_rounded, color: AppColors.primary)
                  : null,
              onTap: () => Navigator.of(context).pop(sort),
            ),
        ],
      ),
    );

    if (picked != null && picked != _query.sort && mounted) {
      setState(() => _query = _query.copyWith(sort: picked));
    }
  }

  @override
  Widget build(BuildContext context) {
    final feed = ref.watch(opportunityFeedProvider(_query));
    final categories = ref.watch(categoriesProvider).valueOrNull ?? const [];
    final margin = AppBreakpoints.pageMargin(context);

    // The board can only filter by categories that exist; "All" is index 0.
    final categoryIndex = _query.categoryId == null
        ? 0
        : categories.indexWhere((c) => c.id == _query.categoryId) + 1;

    return ZRefresh(
      onRefresh: () => ref.refresh(opportunityFeedProvider(_query).future),
      child: RevealScope(
        child: CustomScrollView(
          controller: _scroll,
          // Pull-to-refresh must work even when the list is short or empty.
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: [
            SliverToBoxAdapter(
              child: Padding(
                padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, margin, 0),
                child: const _Header(),
              ),
            ),
            SliverToBoxAdapter(
              // On a tablet the field keeps a readable width, in line with
              // the headline above it, rather than spanning the card grid.
              child: ContentWidthBox(
                alignment: AlignmentDirectional.topStart,
                child: Padding(
                  padding: EdgeInsets.fromLTRB(
                    margin,
                    AppSpacing.md,
                    margin,
                    AppSpacing.sm,
                  ),
                  child: ZSearchField(
                    controller: _search,
                    hint: 'Search briefs by keyword',
                    onChanged: _onSearchChanged,
                    onSubmitted: _submitSearch,
                    showClear: _search.text.isNotEmpty,
                    onClear: () {
                      _search.clear();
                      _submitSearch('');
                    },
                  ),
                ),
              ),
            ),
            if (categories.isNotEmpty)
              SliverToBoxAdapter(
                child: ZFilterPills(
                  padding: EdgeInsets.symmetric(horizontal: margin),
                  labels: ['All', for (final Category c in categories) c.name],
                  selected: categoryIndex < 0 ? 0 : categoryIndex,
                  onSelected: (i) => setState(
                    () => _query = _query.copyWith(
                      categoryId: i == 0 ? null : categories[i - 1].id,
                    ),
                  ),
                ),
              ),
            SliverToBoxAdapter(
              child: Padding(
                padding: EdgeInsets.fromLTRB(
                  margin,
                  AppSpacing.xs,
                  margin - AppSpacing.xs,
                  AppSpacing.xs,
                ),
                child: _SortRow(
                  total: feed.valueOrNull?.total,
                  sort: _query.sort,
                  onTap: _pickSort,
                ),
              ),
            ),
            ...feed.when(
              loading: () => [
                SliverPadding(
                  padding: EdgeInsets.symmetric(horizontal: margin),
                  sliver: SliverList.separated(
                    itemCount: 3,
                    separatorBuilder: (_, _) =>
                        const SizedBox(height: AppSpacing.md),
                    itemBuilder: (_, _) => const CampaignCardSkeleton(),
                  ),
                ),
              ],
              error: (error, _) => [
                SliverFillRemaining(
                  hasScrollBody: false,
                  child: OpportunityErrorView(
                    error: error,
                    onRetry: () =>
                        ref.invalidate(opportunityFeedProvider(_query)),
                  ),
                ),
              ],
              data: (data) {
                if (data.items.isEmpty) {
                  return [
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: _query.isFiltered
                          ? EmptyState(
                              icon: Icons.manage_search_rounded,
                              title: 'No briefs match',
                              message: 'Try another keyword or category. New '
                                  'briefs are published every week.',
                              actionLabel: 'Clear filters',
                              onAction: _clearFilters,
                            )
                          : EmptyState(
                              icon: Icons.campaign_outlined,
                              title: 'No open briefs right now',
                              message: 'Brands publish new campaigns all the '
                                  'time. Pull down to refresh, or check on '
                                  'the applications you have already sent.',
                              actionLabel: 'My applications',
                              onAction: () =>
                                  context.push(Routes.applications),
                            ),
                    ),
                  ];
                }

                return [
                  SliverPadding(
                    padding: EdgeInsets.symmetric(horizontal: margin),
                    // Two columns of briefs on a tablet.
                    sliver: SliverAdaptiveCardList(
                      itemCount: data.items.length,
                      itemBuilder: (_, i) {
                        final campaign = data.items[i];
                        return Reveal(
                          revealKey: campaign.id,
                          index: i,
                          child: CampaignCard(campaign: campaign),
                        );
                      },
                    ),
                  ),
                  SliverToBoxAdapter(
                    child: _Footer(
                      feed: data,
                      onRetry: () => ref
                          .read(opportunityFeedProvider(_query).notifier)
                          .loadMore(),
                    ),
                  ),
                ];
              },
            ),
          ],
        ),
      ),
    );
  }
}

/// The board's headline: what this screen is for, in one line, and what an
/// application involves — so applying never feels like a commitment to a form
/// of unknown length.
class _Header extends StatelessWidget {
  const _Header();

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Find your next brand deal',
          style: AppTypography.headlineSmall,
        ),
        const SizedBox(height: 4),
        Text(
          'Paid briefs from brands looking for creators like you. Apply with '
          'your rate and a short pitch.',
          style: AppTypography.bodyMedium.copyWith(
            color: AppColors.onSurfaceVariant,
          ),
        ),
      ],
    );
  }
}

class _SortRow extends StatelessWidget {
  const _SortRow({required this.total, required this.sort, required this.onTap});

  final int? total;
  final OpportunitySort sort;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final count = total;

    return Row(
      children: [
        Expanded(
          child: Text(
            count == null
                ? 'Loading briefs…'
                : count == 1
                    ? '1 brief'
                    : '${Formatters.number(count)} briefs',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.labelLarge.copyWith(
              color: AppColors.onSurfaceVariant,
            ),
          ),
        ),
        TextButton.icon(
          onPressed: onTap,
          style: TextButton.styleFrom(
            foregroundColor: AppColors.onSurface,
            minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
          ),
          icon: const Icon(Icons.swap_vert_rounded, size: 18),
          label: Text(
            sort.label,
            style: AppTypography.labelLarge,
          ),
        ),
      ],
    );
  }
}

/// Under the last card: the next page loading, a retry if it failed, or a
/// quiet end-of-board marker.
class _Footer extends StatelessWidget {
  const _Footer({required this.feed, required this.onRetry});

  final OpportunityFeed feed;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final Widget child;

    if (feed.loadingMore) {
      child = const BrandLoader(size: 26);
    } else if (feed.loadMoreError != null) {
      child = TextButton.icon(
        onPressed: onRetry,
        style: TextButton.styleFrom(
          minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
        ),
        icon: const Icon(Icons.refresh_rounded, size: 18),
        label: const Text('Couldn\'t load more. Try again'),
      );
    } else if (!feed.hasMore) {
      child = Text(
        'You\'re all caught up',
        style: AppTypography.labelMedium.copyWith(
          color: AppColors.onSurfaceVariant,
        ),
      );
    } else {
      child = const SizedBox(height: 26);
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(0, AppSpacing.lg, 0, AppSpacing.xl),
      child: Center(child: child),
    );
  }
}

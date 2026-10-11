import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../models/home_feed.dart';
import '../../models/taxonomy.dart';
import '../../providers/discovery_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/creator_card.dart';
import '../../shared/widgets/creator_grid.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../search/filter_sheet.dart';

/// Screen 23 — Explore Creators.
///
/// Category chips across the top, then a masonry-style grid of every creator,
/// ordered by the tab the user picks.
class ExploreScreen extends ConsumerStatefulWidget {
  const ExploreScreen({super.key});

  @override
  ConsumerState<ExploreScreen> createState() => _ExploreScreenState();
}

class _ExploreScreenState extends ConsumerState<ExploreScreen>
    with SingleTickerProviderStateMixin {
  /// Explore tabs are the first three admin-defined sort orders, so renaming
  /// "Trending" in the panel renames the tab. Read once — a TabController's
  /// length is fixed at construction.
  late final List<TaxonomyTerm> _sorts = ref
      .read(taxonomiesProvider)
      .terms(Tax.creatorSort)
      .where((t) => t.value != 'relevance')
      .take(3)
      .toList();

  late final TabController _tabs =
      TabController(length: _sorts.length, vsync: this);


  String? _category;

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final categories = ref.watch(categoriesProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      backgroundColor: AppColors.surface,
      padded: false,
      constrain: false,
      maxContentWidth: ContentWidth.wide,
      appBar: ZAppBar(
        title: 'Explore Creators',
        actions: [
          IconButton(
            icon: const Icon(Icons.tune_rounded, size: 22),
            tooltip: 'Filters',
            onPressed: () => FilterSheet.show(context),
          ),
        ],
        bottom: TabBar(
          controller: _tabs,
          onTap: (_) => setState(() {}),
          tabs: [for (final term in _sorts) Tab(text: term.label)],
        ),
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          categories.maybeWhen(
            data: (items) => SizedBox(
              height: 56,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: EdgeInsets.symmetric(horizontal: margin),
                itemCount: items.length + 1,
                separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.xs),
                itemBuilder: (_, i) => Center(
                  child: i == 0
                      ? ZChip(
                          label: 'All',
                          selected: _category == null,
                          onTap: () => setState(() => _category = null),
                        )
                      : ZChip(
                          label: items[i - 1].name,
                          selected: _category == items[i - 1].slug,
                          onTap: () =>
                              setState(() => _category = items[i - 1].slug),
                        ),
                ),
              ),
            ),
            orElse: () => const SizedBox(height: 56),
          ),
          Expanded(
            child: TabBarView(
              controller: _tabs,
              children: [
                for (final term in _sorts)
                  _ExploreGrid(
                    filters: CreatorFilters(
                      sort: term.value,
                      categorySlug: _category,
                    ),
                    margin: margin,
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ExploreGrid extends ConsumerWidget {
  const _ExploreGrid({required this.filters, required this.margin});

  final CreatorFilters filters;
  final double margin;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final results = ref.watch(creatorSearchProvider(filters));

    return ZRefresh(
      onRefresh: () async =>
          ref.refresh(creatorSearchProvider(filters).future),
      child: AsyncView(
        value: results,
        onRetry: () => ref.invalidate(creatorSearchProvider(filters)),
        loading: Padding(
          padding: EdgeInsets.symmetric(horizontal: margin),
          child: const GridSkeleton(count: 6),
        ),
        builder: (page) {
          if (page.isEmpty) {
            return EmptyState(
              title: 'Nothing here yet',
              message: 'No creators match this category right now.',
              icon: Icons.explore_outlined,
              actionLabel: 'Browse all categories',
              onAction: () => context.push(Routes.categories),
            );
          }

          return GridView.builder(
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.md,
              margin,
              AppSpacing.xl,
            ),
            gridDelegate: CreatorGridDelegate(
              textScale: MediaQuery.textScalerOf(context).scale(14) / 14,
            ),
            itemCount: page.items.length,
            itemBuilder: (_, i) => CreatorGridCard(creator: page.items[i]),
          );
        },
      ),
    );
  }
}

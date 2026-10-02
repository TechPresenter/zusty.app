import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme/app_theme.dart';
import '../../models/home_feed.dart';
import '../../models/taxonomy.dart';
import '../../providers/discovery_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../shared/widgets/creator_card.dart';
import '../../shared/widgets/creator_grid.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_tabs.dart';
import '../../shared/widgets/z_text_field.dart';
import 'filter_sheet.dart';

/// Screen 31 — Search Results.
///
/// Honours the shared [filtersProvider] and offers a grid/list toggle. A
/// `category` query parameter pre-selects that filter, which is how the Home
/// and Categories rails drill in.
class SearchResultsScreen extends ConsumerStatefulWidget {
  const SearchResultsScreen({super.key, this.categorySlug});

  final String? categorySlug;

  @override
  ConsumerState<SearchResultsScreen> createState() =>
      _SearchResultsScreenState();
}

class _SearchResultsScreenState extends ConsumerState<SearchResultsScreen> {
  late final TextEditingController _controller;
  bool _gridView = true;

  @override
  void initState() {
    super.initState();
    _controller =
        TextEditingController(text: ref.read(filtersProvider).query ?? '');

    if (widget.categorySlug != null) {
      // Applied after the first frame so the notifier is not written to during
      // widget construction.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        ref.read(filtersProvider.notifier).setCategory(widget.categorySlug);
      });
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final filters = ref.watch(filtersProvider);
    final results = ref.watch(searchResultsProvider);

    return ZScaffold(
      backgroundColor: AppColors.surface,
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        titleWidget: ZSearchField(
          controller: _controller,
          hint: 'Search creators...',
          onSubmitted: (value) =>
              ref.read(filtersProvider.notifier).setQuery(value),
          onFilterTap: () => FilterSheet.show(context),
          filterCount: filters.activeCount,
        ),
        actions: [
          IconButton(
            icon: Icon(
              _gridView ? Icons.view_list_rounded : Icons.grid_view_rounded,
              size: 22,
            ),
            tooltip: _gridView ? 'List view' : 'Grid view',
            onPressed: () => setState(() => _gridView = !_gridView),
          ),
        ],
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SizedBox(height: AppSpacing.xs),
          _CategoryPills(selected: filters.categorySlug),
          if (filters.activeCount > 0)
            _ActiveFilterRow(filters: filters),
          Expanded(
            child: AsyncView(
              value: results,
              onRetry: () => ref.invalidate(searchResultsProvider),
              loading: Padding(
                padding: EdgeInsets.symmetric(
                  horizontal: AppBreakpoints.pageMargin(context),
                ),
                child: const GridSkeleton(count: 6),
              ),
              builder: (page) {
                if (page.isEmpty) {
                  return EmptyState(
                    title: 'No creators match',
                    message:
                        'Try widening your budget or clearing a filter or two.',
                    icon: Icons.person_search_rounded,
                    actionLabel: 'Reset filters',
                    onAction: () =>
                        ref.read(filtersProvider.notifier).reset(),
                  );
                }

                final margin = AppBreakpoints.pageMargin(context);

                return Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: EdgeInsets.fromLTRB(
                        margin,
                        AppSpacing.xs,
                        margin,
                        AppSpacing.xs,
                      ),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${page.total} creator${page.total == 1 ? '' : 's'} found',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: AppTypography.titleSmall,
                            ),
                          ),
                          _SortButton(current: filters.sort),
                        ],
                      ),
                    ),
                    Expanded(
                      child: _gridView
                          ? GridView.builder(
                              padding: EdgeInsets.fromLTRB(
                                margin,
                                AppSpacing.xs,
                                margin,
                                AppSpacing.xl,
                              ),
                              gridDelegate: CreatorGridDelegate(
                                textScale: MediaQuery.textScalerOf(context)
                                        .scale(14) /
                                    14,
                              ),
                              itemCount: page.items.length,
                              itemBuilder: (_, i) =>
                                  CreatorGridCard(creator: page.items[i]),
                            )
                          : ListView.separated(
                              padding: EdgeInsets.fromLTRB(
                                margin,
                                AppSpacing.xs,
                                margin,
                                AppSpacing.xl,
                              ),
                              itemCount: page.items.length,
                              separatorBuilder: (_, _) =>
                                  const SizedBox(height: AppSpacing.sm),
                              itemBuilder: (_, i) =>
                                  CreatorListCard(creator: page.items[i]),
                            ),
                    ),
                  ],
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

/// Horizontally-scrolling summary of what is currently filtered, each chip
/// removable with a tap.
class _ActiveFilterRow extends ConsumerWidget {
  const _ActiveFilterRow({required this.filters});

  final CreatorFilters filters;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifier = ref.read(filtersProvider.notifier);
    final margin = AppBreakpoints.pageMargin(context);

    final chips = <Widget>[
      if (filters.categorySlug != null)
        _RemovableChip(
          label: filters.categorySlug!,
          onRemove: () => notifier.setCategory(null),
        ),
      if (filters.city != null)
        _RemovableChip(
          label: filters.city!,
          onRemove: () => notifier.setCity(null),
        ),
      if (filters.platform != null)
        _RemovableChip(
          label: filters.platform!,
          onRemove: () => notifier.setPlatform(null),
        ),
      if (filters.minPrice != null || filters.maxPrice != null)
        _RemovableChip(
          label: 'Budget',
          onRemove: () => notifier.setPriceRange(null, null),
        ),
      if (filters.minFollowers != null || filters.maxFollowers != null)
        _RemovableChip(
          label: 'Followers',
          onRemove: () => notifier.setFollowerRange(null, null),
        ),
      if (filters.minRating != null)
        _RemovableChip(
          label: '${filters.minRating!.toStringAsFixed(1)}+ rating',
          onRemove: () => notifier.setMinRating(null),
        ),
      if (filters.verifiedOnly)
        _RemovableChip(
          label: 'Verified',
          onRemove: () => notifier.setVerifiedOnly(false),
        ),
      if (filters.availableOnly)
        _RemovableChip(
          label: 'Available',
          onRemove: () => notifier.setAvailableOnly(false),
        ),
    ];

    if (chips.isEmpty) return const SizedBox.shrink();

    return SizedBox(
      height: 46,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsets.symmetric(horizontal: margin),
        itemCount: chips.length + 1,
        separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.xs),
        itemBuilder: (_, i) => Center(
          child: i == chips.length
              ? ZChip(
                  label: 'Clear all',
                  icon: Icons.refresh_rounded,
                  onTap: notifier.reset,
                )
              : chips[i],
        ),
      ),
    );
  }
}

class _RemovableChip extends StatelessWidget {
  const _RemovableChip({required this.label, required this.onRemove});

  final String label;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.only(left: AppSpacing.sm, right: 6),
      height: 32,
      decoration: BoxDecoration(
        color: AppColors.primaryFixed,
        borderRadius: AppRadius.pill,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label[0].toUpperCase() + label.substring(1),
            style: AppTypography.labelMedium
                .copyWith(color: AppColors.onPrimaryFixedVariant),
          ),
          const SizedBox(width: 2),
          InkWell(
            onTap: onRemove,
            customBorder: const CircleBorder(),
            child: Padding(
              padding: EdgeInsets.all(3),
              child: Icon(
                Icons.close_rounded,
                size: 14,
                color: AppColors.onPrimaryFixedVariant,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Category pills under the search bar: "All" plus every category, the
/// selected one filled. One tap re-scopes the results.
class _CategoryPills extends ConsumerWidget {
  const _CategoryPills({required this.selected});

  final String? selected;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final categories = ref.watch(categoriesProvider).valueOrNull ?? const [];
    if (categories.isEmpty) return const SizedBox.shrink();

    final labels = ['All', ...categories.map((c) => c.name)];
    final index = selected == null
        ? 0
        : categories.indexWhere((c) => c.slug == selected) + 1;

    return ZFilterPills(
      labels: labels,
      selected: index < 0 ? 0 : index,
      onSelected: (i) => ref
          .read(filtersProvider.notifier)
          .setCategory(i == 0 ? null : categories[i - 1].slug),
    );
  }
}

/// The current sort, as a button that opens the options. The options are the
/// admin-managed `creator_sort` taxonomy.
class _SortButton extends ConsumerWidget {
  const _SortButton({required this.current});

  final String current;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final options = ref.watch(taxonomiesProvider).map(Tax.creatorSort);
    final label = options[current] ?? 'Relevance';

    return TextButton.icon(
      onPressed: () => showZSheet<void>(
        context,
        title: 'Sort by',
        builder: (sheetContext) => Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final entry in options.entries)
              ListTile(
                contentPadding: EdgeInsets.zero,
                minTileHeight: AppSpacing.minTouch,
                title: Text(entry.value, style: AppTypography.bodyLarge),
                trailing: entry.key == current
                    ? Icon(Icons.check_circle_rounded, color: AppColors.primary)
                    : null,
                onTap: () {
                  ref.read(filtersProvider.notifier).setSort(entry.key);
                  Navigator.of(sheetContext).pop();
                },
              ),
          ],
        ),
      ),
      style: TextButton.styleFrom(
        minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
      ),
      icon: Icon(Icons.swap_vert_rounded, size: 18, color: AppColors.primary),
      label: Text(
        label,
        style: AppTypography.labelLarge.copyWith(color: AppColors.primary),
      ),
    );
  }
}

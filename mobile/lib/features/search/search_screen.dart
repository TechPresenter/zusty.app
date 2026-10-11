import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../models/home_feed.dart';
import '../../providers/auth_provider.dart';
import '../../providers/discovery_provider.dart';
import '../../providers/location_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../router/routes.dart';
import '../../shared/widgets/adaptive_layout.dart';
import '../../shared/widgets/category_card.dart';
import '../../shared/widgets/creator_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_text_field.dart';
import 'filter_sheet.dart';

/// Screen 07 — Search.
///
/// Live suggestions while typing; recent searches, trending terms, and a
/// category grid when the field is empty.
class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key});

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  final _controller = TextEditingController();
  String _query = '';

  @override
  void initState() {
    super.initState();
    final existing = ref.read(filtersProvider).query;
    if (existing != null && existing.isNotEmpty) {
      _controller.text = existing;
      _query = existing;
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit(String term) {
    final trimmed = term.trim();
    if (trimmed.isEmpty) return;

    ref.read(recentSearchesProvider.notifier).add(trimmed);
    ref.read(filtersProvider.notifier).setQuery(trimmed);
    context.push(Routes.searchResults);
  }

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);
    final filters = ref.watch(filtersProvider);

    // On a tablet the page is a centred readable column, header included.
    return Scaffold(
      backgroundColor: AppColors.surface,
      appBar: ContentWidthAppBar(
        child: AppBar(
          backgroundColor: AppColors.surface,
          automaticallyImplyLeading: false,
          titleSpacing: margin,
          toolbarHeight: 128,
          title: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Search', style: AppTypography.headlineMedium),
              Text(
                'Creators by name, niche, city or platform',
                style: AppTypography.bodySmall,
              ),
              const SizedBox(height: AppSpacing.sm),
              ZSearchField(
                hint: 'Search creators or niches',
                controller: _controller,
                autofocus: false,
                showClear: _query.isNotEmpty,
                onClear: () {
                  _controller.clear();
                  setState(() => _query = '');
                },
                onChanged: (value) => setState(() => _query = value),
                onSubmitted: _submit,
                onFilterTap: () => FilterSheet.show(context),
                filterCount: filters.activeCount,
              ),
            ],
          ),
        ),
      ),
      body: ContentWidthBox(
        child: _query.isEmpty
            ? _SearchLanding(onTermTap: _submit, margin: margin)
            : _Suggestions(query: _query, onTermTap: _submit, margin: margin),
      ),
    );
  }
}

/// The empty-field state: recent searches, trending terms, top categories.
class _SearchLanding extends ConsumerWidget {
  const _SearchLanding({required this.onTermTap, required this.margin});

  final ValueChanged<String> onTermTap;
  final double margin;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final recents = ref.watch(recentSearchesProvider);
    final categories = ref.watch(categoriesProvider);
    final filters = ref.watch(filtersProvider);
    final notifier = ref.read(filtersProvider.notifier);
    final city = ref.watch(selectedCityProvider);
    final isCreator = ref.watch(authProvider).isCreator;
    final trending = ref.watch(taxonomiesProvider).trendingSearches;

    return ListView(
      padding: EdgeInsets.fromLTRB(0, AppSpacing.xs, 0, AppSpacing.xxl),
      children: [
        // -- Quick filters --------------------------------------------------
        // Each pill is a real facet of the same filters the sheet edits, so
        // "Verified" here and the toggle in the sheet are one setting.
        SizedBox(
          height: AppSpacing.minTouch,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: EdgeInsets.symmetric(horizontal: margin),
            children: [
              _QuickFilter(
                icon: Icons.tune_rounded,
                label: filters.activeCount > 0
                    ? 'Filters · ${filters.activeCount}'
                    : 'All filters',
                selected: filters.activeCount > 0,
                onTap: () => FilterSheet.show(context),
              ),
              if (city != null)
                _QuickFilter(
                  icon: Icons.location_on_rounded,
                  label: 'In $city',
                  selected: filters.city == city,
                  onTap: () =>
                      notifier.setCity(filters.city == city ? null : city),
                ),
              _QuickFilter(
                icon: Icons.verified_rounded,
                label: 'Verified',
                selected: filters.verifiedOnly,
                onTap: () => notifier.setVerifiedOnly(!filters.verifiedOnly),
              ),
              _QuickFilter(
                icon: Icons.event_available_rounded,
                label: 'Available now',
                selected: filters.availableOnly,
                onTap: () => notifier.setAvailableOnly(!filters.availableOnly),
              ),
              _QuickFilter(
                icon: Icons.insights_rounded,
                label: 'High engagement',
                selected: (filters.minEngagement ?? 0) >= 3,
                onTap: () => notifier.setMinEngagement(
                  (filters.minEngagement ?? 0) >= 3 ? null : 3,
                ),
              ),
            ],
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(margin, AppSpacing.sm, margin, 0),
          child: ZButton.tonal(
            label: filters.activeCount > 0
                ? 'Show matching creators'
                : 'Browse all creators',
            icon: Icons.grid_view_rounded,
            size: ZButtonSize.medium,
            onPressed: () => context.push(Routes.searchResults),
          ),
        ),

        // -- Creators looking for work ---------------------------------------
        if (isCreator) ...[
          const SizedBox(height: AppSpacing.md),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: margin),
            child: _CampaignsPromo(
              onTap: () => context.push(Routes.creatorCampaigns),
            ),
          ),
        ],

        // -- Recent ------------------------------------------------------------
        if (recents.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.lg),
          SectionHeader(
            title: 'Recent searches',
            actionLabel: 'Clear',
            onAction: () => ref.read(recentSearchesProvider.notifier).clear(),
            padding: EdgeInsets.only(
              left: margin,
              right: margin - AppSpacing.xs,
            ),
          ),
          const SizedBox(height: AppSpacing.xs),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: margin),
            child: Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              children: [
                for (final term in recents)
                  InputChip(
                    avatar: Icon(
                      Icons.history_rounded,
                      size: 16,
                      color: AppColors.onSurfaceVariant,
                    ),
                    label: Text(term),
                    labelStyle: AppTypography.labelLarge.copyWith(
                      fontWeight: FontWeight.w500,
                      fontSize: 13,
                    ),
                    onPressed: () => onTermTap(term),
                    onDeleted: () =>
                        ref.read(recentSearchesProvider.notifier).remove(term),
                    deleteIcon: const Icon(Icons.close_rounded, size: 16),
                    deleteButtonTooltipMessage: 'Remove $term',
                    backgroundColor: AppColors.surfaceContainerLowest,
                    side: BorderSide(color: AppColors.outlineVariant),
                    shape: const StadiumBorder(),
                  ),
              ],
            ),
          ),
        ],

        // -- Popular -----------------------------------------------------------
        if (trending.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.lg),
          SectionHeader(
            title: 'Popular searches',
            padding: EdgeInsets.symmetric(horizontal: margin),
          ),
          const SizedBox(height: AppSpacing.xs),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: margin),
            child: Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              children: [
                for (final term in trending)
                  ZChip(
                    label: term,
                    icon: Icons.trending_up_rounded,
                    onTap: () => onTermTap(term),
                  ),
              ],
            ),
          ),
        ],

        // -- Categories --------------------------------------------------------
        const SizedBox(height: AppSpacing.lg),
        SectionHeader(
          title: 'Browse categories',
          actionLabel: 'View all',
          onAction: () => context.push(Routes.categories),
          padding: EdgeInsets.only(left: margin, right: margin - AppSpacing.xs),
        ),
        const SizedBox(height: AppSpacing.xs),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: margin),
          child: categories.when(
            data: (items) => GridView.builder(
              shrinkWrap: true,
              padding: EdgeInsets.zero,
              physics: const NeverScrollableScrollPhysics(),
              gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 3,
                crossAxisSpacing: AppSpacing.sm,
                mainAxisSpacing: AppSpacing.sm,
                mainAxisExtent:
                    120 +
                    18 * (MediaQuery.textScalerOf(context).scale(14) / 14),
              ),
              itemCount: items.length > 9 ? 9 : items.length,
              itemBuilder: (_, i) => CategoryIconTile(category: items[i]),
            ),
            loading: () => const GridSkeleton(),
            error: (error, _) => ErrorState(
              error: error,
              onRetry: () => ref.invalidate(categoriesProvider),
            ),
          ),
        ),
      ],
    );
  }
}

class _QuickFilter extends StatelessWidget {
  const _QuickFilter({
    required this.icon,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final foreground = selected ? AppColors.onPrimary : AppColors.onSurface;

    return Padding(
      padding: const EdgeInsets.only(right: AppSpacing.xs),
      child: Semantics(
        button: true,
        selected: selected,
        child: Material(
          color: selected
              ? AppColors.primary
              : AppColors.surfaceContainerLowest,
          shape: StadiumBorder(
            side: selected
                ? BorderSide.none
                : BorderSide(color: AppColors.outlineVariant),
          ),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    icon,
                    size: 16,
                    color: selected ? AppColors.onPrimary : AppColors.primary,
                  ),
                  const SizedBox(width: 6),
                  Text(
                    label,
                    style: AppTypography.labelLarge.copyWith(
                      fontSize: 13,
                      color: foreground,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// For creators: their own search is for campaigns, which live on the
/// Opportunities board.
class _CampaignsPromo extends StatelessWidget {
  const _CampaignsPromo({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      borderRadius: AppRadius.xlAll,
      clipBehavior: Clip.antiAlias,
      child: Ink(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [AppColors.primaryDark, AppColors.primary],
          ),
        ),
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.16),
                    borderRadius: AppRadius.mdAll,
                  ),
                  child: const Icon(
                    Icons.campaign_rounded,
                    color: Colors.white,
                  ),
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Find brand campaigns',
                        style: AppTypography.titleSmall.copyWith(
                          color: Colors.white,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'Open briefs you can apply to today',
                        style: AppTypography.bodySmall.copyWith(
                          color: Colors.white.withValues(alpha: 0.85),
                        ),
                      ),
                    ],
                  ),
                ),
                const Icon(Icons.arrow_forward_rounded, color: Colors.white),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Suggestions extends ConsumerWidget {
  const _Suggestions({
    required this.query,
    required this.onTermTap,
    required this.margin,
  });

  final String query;
  final ValueChanged<String> onTermTap;
  final double margin;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final suggestions = ref.watch(searchSuggestionsProvider(query));
    final matches = ref.watch(
      creatorSearchProvider(CreatorFilters(query: query)),
    );

    return ListView(
      padding: EdgeInsets.fromLTRB(
        margin,
        AppSpacing.md,
        margin,
        AppSpacing.xl,
      ),
      children: [
        suggestions.when(
          data: (terms) => Column(
            children: terms
                .map(
                  (term) => ListTile(
                    contentPadding: EdgeInsets.zero,
                    minTileHeight: AppSpacing.minTouch,
                    leading: Icon(
                      Icons.search_rounded,
                      size: 20,
                      color: AppColors.onSurfaceVariant,
                    ),
                    title: Text(term, style: AppTypography.bodyLarge),
                    trailing: Icon(
                      Icons.north_west_rounded,
                      size: 16,
                      color: AppColors.outline,
                    ),
                    onTap: () => onTermTap(term),
                  ),
                )
                .toList(),
          ),
          loading: () => const Padding(
            padding: EdgeInsets.symmetric(vertical: AppSpacing.md),
            child: LinearProgressIndicator(minHeight: 2),
          ),
          error: (_, _) => const SizedBox.shrink(),
        ),
        const SizedBox(height: AppSpacing.md),
        matches.when(
          data: (page) {
            if (page.isEmpty) {
              return EmptyState(
                title: 'No creators found',
                message: 'Try a different keyword or adjust your filters.',
                icon: Icons.person_search_rounded,
                compact: true,
              );
            }
            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SectionHeader(
                  title: 'Creators',
                  actionLabel: 'See all ${page.total}',
                  onAction: () => onTermTap(query),
                ),
                const SizedBox(height: AppSpacing.xs),
                ...page.items
                    .take(5)
                    .map(
                      (creator) => Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: CreatorListCard(creator: creator),
                      ),
                    ),
              ],
            );
          },
          loading: () => const ListSkeleton(count: 3, height: 112),
          error: (error, _) => ErrorState(error: error),
        ),
      ],
    );
  }
}

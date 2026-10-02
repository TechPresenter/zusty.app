import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../models/category.dart';
import '../../providers/discovery_provider.dart';
import '../../router/routes.dart';
import '../../shared/widgets/category_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';

/// Screen 08 — Categories.
///
/// A searchable grid of every creator category, with a compact list fallback
/// so long catalogues stay scannable.
class CategoriesScreen extends ConsumerStatefulWidget {
  const CategoriesScreen({super.key});

  @override
  ConsumerState<CategoriesScreen> createState() => _CategoriesScreenState();
}

class _CategoriesScreenState extends ConsumerState<CategoriesScreen> {
  final _controller = TextEditingController();
  String _query = '';
  bool _gridView = true;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  List<Category> _filter(List<Category> items) {
    if (_query.isEmpty) return items;
    final lower = _query.toLowerCase();
    return items
        .where((c) => c.name.toLowerCase().contains(lower))
        .toList(growable: false);
  }

  void _open(Category category) {
    ref.read(filtersProvider.notifier)
      ..setQuery('')
      ..setCategory(category.slug);
    context.push('${Routes.searchResults}?category=${category.slug}');
  }

  @override
  Widget build(BuildContext context) {
    final categories = ref.watch(categoriesProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      backgroundColor: AppColors.surface,
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        title: 'Categories',
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
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.xs,
              margin,
              AppSpacing.md,
            ),
            child: ZSearchField(
              controller: _controller,
              hint: 'Search categories...',
              showClear: _query.isNotEmpty,
              onClear: () {
                _controller.clear();
                setState(() => _query = '');
              },
              onChanged: (value) => setState(() => _query = value),
            ),
          ),
          Expanded(
            child: AsyncView(
              value: categories,
              onRetry: () => ref.invalidate(categoriesProvider),
              loading: Padding(
                padding: EdgeInsets.symmetric(horizontal: margin),
                child: const GridSkeleton(count: 6),
              ),
              builder: (items) {
                final filtered = _filter(items);

                if (filtered.isEmpty) {
                  return EmptyState(
                    title: 'No categories found',
                    message: 'Try a different search term.',
                    icon: Icons.category_outlined,
                  );
                }

                if (!_gridView) {
                  return ListView.separated(
                    padding: EdgeInsets.fromLTRB(
                      margin - 16,
                      0,
                      margin - 16,
                      AppSpacing.xl,
                    ),
                    itemCount: filtered.length,
                    separatorBuilder: (_, _) => const Divider(
                      height: 1,
                      indent: AppSpacing.md,
                      endIndent: AppSpacing.md,
                    ),
                    itemBuilder: (_, i) => CategoryListRow(
                      category: filtered[i],
                      onTap: () => _open(filtered[i]),
                    ),
                  );
                }

                return GridView.builder(
                  padding: EdgeInsets.fromLTRB(
                    margin,
                    0,
                    margin,
                    AppSpacing.xl,
                  ),
                  // Three tinted icon tiles across on a phone, more where
                  // there is room; rows sized for the name and count.
                  gridDelegate: SliverGridDelegateWithMaxCrossAxisExtent(
                    maxCrossAxisExtent: 140,
                    crossAxisSpacing: AppSpacing.sm,
                    mainAxisSpacing: AppSpacing.sm,
                    mainAxisExtent: 120 +
                        18 * (MediaQuery.textScalerOf(context).scale(14) / 14),
                  ),
                  itemCount: filtered.length,
                  itemBuilder: (_, i) => CategoryIconTile(
                    category: filtered[i],
                    onTap: () => _open(filtered[i]),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

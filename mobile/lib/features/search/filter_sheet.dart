import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/city.dart';
import '../../models/home_feed.dart';
import '../../models/taxonomy.dart';
import '../../providers/discovery_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_dialogs.dart';

/// The advanced filter sheet behind the tune button on Search and Explore.
///
/// Fifteen facets grouped into scannable sections (SRS-FR-11 and beyond),
/// edited against a local draft so nothing applies until "Show results" is
/// tapped — the user can explore combinations without the list thrashing
/// underneath them. A live count in the footer previews how many creators the
/// current draft matches, so a dead end is obvious before committing to it.
class FilterSheet extends ConsumerStatefulWidget {
  const FilterSheet({super.key});

  static Future<void> show(BuildContext context) => showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        useSafeArea: true,
        backgroundColor: AppColors.surfaceContainerLowest,
        sheetAnimationStyle: zSheetMotion(context),
        builder: (_) => const FilterSheet(),
      );

  @override
  ConsumerState<FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends ConsumerState<FilterSheet> {
  late CreatorFilters _draft = ref.read(filtersProvider);

  void _update(CreatorFilters next) => setState(() => _draft = next);

  void _apply() {
    ref.read(filtersProvider.notifier).replace(_draft);
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final categories = ref.watch(categoriesProvider);
    // Every option list below is managed in the admin panel.
    final tax = ref.watch(taxonomiesProvider);
    // Live match count for the draft, so the CTA can say what it will show.
    final preview = ref.watch(creatorSearchProvider(_draft));

    return DraggableScrollableSheet(
      initialChildSize: 0.92,
      minChildSize: 0.5,
      maxChildSize: 0.96,
      expand: false,
      builder: (context, scrollController) => Column(
        children: [
          _Header(
            activeCount: _draft.activeCount,
            onReset: () => _update(_draft.cleared()),
          ),
          const Divider(height: 1),
          Expanded(
            child: ListView(
              controller: scrollController,
              padding: EdgeInsets.fromLTRB(
                AppBreakpoints.pageMargin(context),
                AppSpacing.md,
                AppBreakpoints.pageMargin(context),
                AppSpacing.lg,
              ),
              children: [
                _Section(
                  title: 'Sort by',
                  icon: Icons.swap_vert_rounded,
                  child: _ChoiceChips(
                    options: tax.map(Tax.creatorSort),
                    value: _draft.sort,
                    allowClear: false,
                    onChanged: (value) =>
                        _update(_draft.copyWith(sort: value ?? 'relevance')),
                  ),
                ),
                _Section(
                  title: 'Location',
                  icon: Icons.location_on_outlined,
                  valueLabel: _draft.city,
                  child: _ChoiceChips(
                    options: {
                      for (final city in Cities.all) city.name: city.name,
                    },
                    value: _draft.city,
                    scrollable: true,
                    onChanged: (value) => _update(_draft.copyWith(city: value)),
                  ),
                ),
                _Section(
                  title: 'Category',
                  icon: Icons.category_outlined,
                  child: categories.maybeWhen(
                    data: (list) => _ChoiceChips(
                      options: {
                        for (final category in list)
                          category.slug: category.name,
                      },
                      value: _draft.categorySlug,
                      onChanged: (value) =>
                          _update(_draft.copyWith(categorySlug: value)),
                    ),
                    orElse: () => const SizedBox(
                      height: 38,
                      child: Center(
                        child: SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      ),
                    ),
                  ),
                ),
                _Section(
                  title: 'Price range',
                  icon: Icons.payments_outlined,
                  valueLabel: _budgetLabel(),
                  child: _RangeSliderField(
                    min: 0,
                    max: tax.maxBudget,
                    divisions: 20,
                    start: _draft.minPrice ?? 0,
                    end: _draft.maxPrice ?? tax.maxBudget,
                    labelBuilder: Formatters.currency,
                    onChanged: (start, end) => _update(
                      _draft.copyWith(
                        minPrice: start <= 0 ? null : start,
                        maxPrice: end >= tax.maxBudget ? null : end,
                      ),
                    ),
                  ),
                ),
                _Section(
                  title: 'Platform',
                  icon: Icons.share_outlined,
                  child: _ChoiceChips(
                    options: tax.map(Tax.platform),
                    value: _draft.platform,
                    onChanged: (value) =>
                        _update(_draft.copyWith(platform: value)),
                  ),
                ),
                _Section(
                  title: 'Followers',
                  icon: Icons.group_outlined,
                  valueLabel: _followerLabel(),
                  child: _RangeSliderField(
                    min: 0,
                    max: tax.maxFollowers.toDouble(),
                    divisions: 20,
                    start: (_draft.minFollowers ?? 0).toDouble(),
                    end: (_draft.maxFollowers ?? tax.maxFollowers)
                        .toDouble(),
                    labelBuilder: Formatters.compact,
                    onChanged: (start, end) => _update(
                      _draft.copyWith(
                        minFollowers: start <= 0 ? null : start.round(),
                        maxFollowers: end >= tax.maxFollowers
                            ? null
                            : end.round(),
                      ),
                    ),
                  ),
                ),
                _Section(
                  title: 'Engagement rate',
                  icon: Icons.trending_up_rounded,
                  valueLabel: _draft.minEngagement == null
                      ? null
                      : '${_draft.minEngagement!.toStringAsFixed(0)}%+',
                  child: _ChoiceChips(
                    options: tax.map(Tax.engagementFilter),
                    value: _draft.minEngagement?.toStringAsFixed(0),
                    onChanged: (value) => _update(
                      _draft.copyWith(
                        minEngagement:
                            value == null ? null : double.parse(value),
                      ),
                    ),
                  ),
                ),
                _Section(
                  title: 'Rating',
                  icon: Icons.star_outline_rounded,
                  child: _RatingChips(
                    terms: tax.terms(Tax.ratingFilter),
                    value: _draft.minRating,
                    onChanged: (value) =>
                        _update(_draft.copyWith(minRating: value)),
                  ),
                ),
                _Section(
                  title: 'Language',
                  icon: Icons.translate_rounded,
                  child: _ChoiceChips(
                    options: tax.map(Tax.language),
                    value: _draft.language,
                    onChanged: (value) =>
                        _update(_draft.copyWith(language: value)),
                  ),
                ),
                _Section(
                  title: 'Gender',
                  icon: Icons.person_outline_rounded,
                  child: _ChoiceChips(
                    options: tax.map(Tax.gender),
                    value: _draft.gender,
                    onChanged: (value) =>
                        _update(_draft.copyWith(gender: value)),
                  ),
                ),
                _Section(
                  title: 'Age group',
                  icon: Icons.cake_outlined,
                  child: _ChoiceChips(
                    options: tax.map(Tax.ageGroup),
                    value: _draft.ageGroup,
                    onChanged: (value) =>
                        _update(_draft.copyWith(ageGroup: value)),
                  ),
                ),
                _Section(
                  title: 'Experience',
                  icon: Icons.workspace_premium_outlined,
                  child: _ChoiceChips(
                    options: tax.map(Tax.experience),
                    value: _draft.experience,
                    onChanged: (value) =>
                        _update(_draft.copyWith(experience: value)),
                  ),
                ),
                _Section(
                  title: 'Response time',
                  icon: Icons.schedule_rounded,
                  child: _ChoiceChips(
                    options: tax.map(Tax.responseTime),
                    value: _draft.responseTime,
                    onChanged: (value) =>
                        _update(_draft.copyWith(responseTime: value)),
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),
                _ToggleRow(
                  icon: Icons.verified_rounded,
                  title: 'Verified creators only',
                  subtitle: 'KYC-checked, with confirmed social accounts',
                  value: _draft.verifiedOnly,
                  onChanged: (value) =>
                      _update(_draft.copyWith(verifiedOnly: value)),
                ),
                _ToggleRow(
                  icon: Icons.event_available_rounded,
                  title: 'Available now',
                  subtitle: 'Open to new bookings this month',
                  value: _draft.availableOnly,
                  onChanged: (value) =>
                      _update(_draft.copyWith(availableOnly: value)),
                ),
              ],
            ),
          ),
          _Footer(
            count: preview.valueOrNull?.pagination.total,
            isLoading: preview.isLoading,
            onApply: _apply,
            onReset: _draft.activeCount == 0
                ? null
                : () => _update(_draft.cleared()),
          ),
        ],
      ),
    );
  }

  String? _budgetLabel() {
    if (_draft.minPrice == null && _draft.maxPrice == null) return null;
    return Formatters.currencyRange(_draft.minPrice, _draft.maxPrice);
  }

  String? _followerLabel() {
    if (_draft.minFollowers == null && _draft.maxFollowers == null) return null;
    final min = Formatters.compact(_draft.minFollowers ?? 0);
    final max = _draft.maxFollowers == null
        ? 'any'
        : Formatters.compact(_draft.maxFollowers);
    return '$min – $max';
  }
}

/// Sheet title with the active-facet count and a reset action.
class _Header extends StatelessWidget {
  const _Header({required this.activeCount, required this.onReset});

  final int activeCount;
  final VoidCallback onReset;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
        AppBreakpoints.pageMargin(context),
        AppSpacing.xs,
        AppSpacing.xs,
        AppSpacing.sm,
      ),
      child: Row(
        children: [
          Text('Filters', style: AppTypography.titleMedium),
          if (activeCount > 0) ...[
            const SizedBox(width: AppSpacing.xs),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(
                color: AppColors.primary,
                borderRadius: AppRadius.pill,
              ),
              child: Text(
                '$activeCount',
                style: AppTypography.labelSmall
                    .copyWith(color: AppColors.onPrimary),
              ),
            ),
          ],
          const Spacer(),
          IconButton(
            icon: const Icon(Icons.close_rounded, size: 20),
            onPressed: () => Navigator.of(context).pop(),
            tooltip: 'Close',
          ),
        ],
      ),
    );
  }
}

/// Pinned CTA showing how many creators the draft currently matches.
class _Footer extends StatelessWidget {
  const _Footer({
    required this.count,
    required this.isLoading,
    required this.onApply,
    this.onReset,
  });

  final int? count;
  final bool isLoading;
  final VoidCallback onApply;

  /// Null when nothing is set, which disables the button.
  final VoidCallback? onReset;

  @override
  Widget build(BuildContext context) {
    final label = isLoading || count == null
        ? 'Show results'
        : count == 0
            ? 'No matches'
            : 'Show $count creator${count == 1 ? '' : 's'}';

    return Container(
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        boxShadow: AppShadows.bottomBar,
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Row(
            children: [
              ZButton.outlined(
                label: 'Reset',
                expand: false,
                onPressed: onReset,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: ZButton(
                  label: label,
                  onPressed: count == 0 ? null : onApply,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A titled filter group with an optional summary of its current value.
class _Section extends StatelessWidget {
  const _Section({
    required this.title,
    required this.icon,
    required this.child,
    this.valueLabel,
  });

  final String title;
  final IconData icon;
  final Widget child;

  /// Rendered on the right of the header, e.g. `₹5,000 – ₹40,000`.
  final String? valueLabel;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.md),
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: AppColors.outlineVariant)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 28,
                height: 28,
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerLow,
                  borderRadius: AppRadius.smAll,
                ),
                child: Icon(icon, size: 16, color: AppColors.primary),
              ),
              const SizedBox(width: AppSpacing.xs),
              Text(title, style: AppTypography.titleSmall),
              if (valueLabel != null) ...[
                const SizedBox(width: AppSpacing.xs),
                Expanded(
                  child: Text(
                    valueLabel!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.right,
                    style: AppTypography.labelMedium
                        .copyWith(color: AppColors.primary),
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: AppSpacing.xs + 2),
          child,
        ],
      ),
    );
  }
}

/// A wrap (or single-line scroller) of mutually-exclusive chips.
///
/// Tapping the selected chip clears the facet unless [allowClear] is false.
class _ChoiceChips extends StatelessWidget {
  const _ChoiceChips({
    required this.options,
    required this.value,
    required this.onChanged,
    this.allowClear = true,
    this.scrollable = false,
  });

  final Map<String, String> options;
  final String? value;
  final ValueChanged<String?> onChanged;
  final bool allowClear;

  /// Long lists (cities) scroll horizontally instead of wrapping to many rows.
  final bool scrollable;

  @override
  Widget build(BuildContext context) {
    final chips = [
      for (final entry in options.entries)
        ZChip(
          label: entry.value,
          selected: value == entry.key,
          onTap: () => onChanged(
            value == entry.key && allowClear ? null : entry.key,
          ),
        ),
    ];

    if (!scrollable) {
      return Wrap(
        spacing: AppSpacing.xs,
        runSpacing: AppSpacing.xs,
        children: chips,
      );
    }

    return SizedBox(
      height: 38,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: chips.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.xs),
        itemBuilder: (_, i) => chips[i],
      ),
    );
  }
}

/// Star-rating floors, rendered with the star glyph rather than bare numbers.
class _RatingChips extends StatelessWidget {
  const _RatingChips({
    required this.terms,
    required this.value,
    required this.onChanged,
  });

  /// Thresholds defined in the admin panel, e.g. 4.5+, 4.0+.
  final List<TaxonomyTerm> terms;
  final double? value;
  final ValueChanged<double?> onChanged;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: AppSpacing.xs,
      runSpacing: AppSpacing.xs,
      children: [
        for (final term in terms)
          Builder(
            builder: (_) {
              final level = double.tryParse(term.value);
              if (level == null) return const SizedBox.shrink();
              return ZChip(
                label: term.label,
                icon: Icons.star_rounded,
                selected: value == level,
                onTap: () => onChanged(value == level ? null : level),
              );
            },
          ),
      ],
    );
  }
}

/// A themed two-thumb range slider with a live readout beneath it.
class _RangeSliderField extends StatelessWidget {
  const _RangeSliderField({
    required this.min,
    required this.max,
    required this.start,
    required this.end,
    required this.onChanged,
    required this.labelBuilder,
    this.divisions,
  });

  final double min;
  final double max;
  final double start;
  final double end;
  final void Function(double start, double end) onChanged;
  final String Function(num value) labelBuilder;
  final int? divisions;

  @override
  Widget build(BuildContext context) {
    // Guard against a stored value drifting outside the slider's bounds.
    final safeStart = start.clamp(min, max);
    final safeEnd = end.clamp(safeStart, max);

    return Column(
      children: [
        RangeSlider(
          min: min,
          max: max,
          divisions: divisions,
          values: RangeValues(safeStart, safeEnd),
          labels: RangeLabels(labelBuilder(safeStart), labelBuilder(safeEnd)),
          onChanged: (values) => onChanged(values.start, values.end),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(labelBuilder(safeStart), style: AppTypography.labelSmall),
              Text(
                safeEnd >= max ? 'Any' : labelBuilder(safeEnd),
                style: AppTypography.labelSmall,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// A switch row for the boolean facets.
class _ToggleRow extends StatelessWidget {
  const _ToggleRow({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.value,
    required this.onChanged,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return SwitchListTile.adaptive(
      value: value,
      onChanged: onChanged,
      contentPadding: EdgeInsets.zero,
      secondary: Container(
        width: 38,
        height: 38,
        decoration: BoxDecoration(
          color: value ? AppColors.primaryFixed : AppColors.surfaceContainerHigh,
          borderRadius: AppRadius.mdAll,
        ),
        child: Icon(
          icon,
          size: 18,
          color: value ? AppColors.primary : AppColors.onSurfaceVariant,
        ),
      ),
      title: Text(
        title,
        style: AppTypography.bodyMedium.copyWith(fontWeight: FontWeight.w600),
      ),
      subtitle: Text(subtitle, style: AppTypography.labelSmall),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/creator.dart';
import '../../providers/discovery_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';

/// Compare Creators — side-by-side evaluation of shortlisted creators.
///
/// Picking between similar creators is the decision a brand actually agonises
/// over, and it is impossible from a scrolling list. This lays the deciding
/// attributes out in one grid and highlights the best value in each row, so the
/// trade-offs are visible rather than remembered.
class CompareCreatorsScreen extends ConsumerStatefulWidget {
  const CompareCreatorsScreen({super.key});

  /// The most creators that stay legible side by side on a phone.
  static const maxSelection = 4;

  @override
  ConsumerState<CompareCreatorsScreen> createState() =>
      _CompareCreatorsScreenState();
}

class _CompareCreatorsScreenState
    extends ConsumerState<CompareCreatorsScreen> {
  final Set<int> _selected = {};

  @override
  Widget build(BuildContext context) {
    final saved = ref.watch(favoritesProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      appBar: ZAppBar(
        title: 'Compare creators',
        actions: [
          if (_selected.isNotEmpty)
            TextButton(
              onPressed: () => setState(_selected.clear),
              style: TextButton.styleFrom(
                minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
              ),
              child: const Text('Clear'),
            ),
        ],
      ),
      body: AsyncView<List<Creator>>(
        value: saved,
        onRetry: () => ref.invalidate(favoritesProvider),
        loading: ListView(
          physics: const NeverScrollableScrollPhysics(),
          padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, 0),
          children: const [
            ZShimmer(width: 160, height: 22, borderRadius: AppRadius.smAll),
            SizedBox(height: AppSpacing.md),
            ZShimmer(height: 132, borderRadius: AppRadius.xlAll),
            SizedBox(height: AppSpacing.lg),
            ZShimmer(height: 360, borderRadius: AppRadius.xlAll),
          ],
        ),
        builder: (creators) {
          if (creators.isEmpty) {
            return EmptyState(
              icon: Icons.compare_arrows_rounded,
              title: 'Save creators to compare them',
              message:
                  'Tap the heart on any creator, then come back here to line '
                  'them up side by side.',
              actionLabel: 'Browse creators',
              onAction: () => context.push(Routes.explore),
            );
          }

          final chosen =
              creators.where((c) => _selected.contains(c.id)).toList();

          return ZRefresh(
            onRefresh: () async {
              ref.invalidate(favoritesProvider);
              try {
                await ref.read(favoritesProvider.future);
              } catch (_) {
                // The screen shows the error itself.
              }
            },
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: EdgeInsets.fromLTRB(
                margin,
                AppSpacing.md,
                margin,
                AppSpacing.xl,
              ),
              children: [
                Text(
                  'Pick up to ${CompareCreatorsScreen.maxSelection}',
                  style: AppTypography.headlineSmall,
                ),
                const SizedBox(height: 4),
                Text(
                  '${_selected.length} of ${creators.length} saved creators '
                  'selected',
                  style: AppTypography.bodyMedium
                      .copyWith(color: AppColors.onSurfaceVariant),
                ),
                const SizedBox(height: AppSpacing.md),
                ZCard(
                  padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
                  child: _Picker(
                    creators: creators,
                    selected: _selected,
                    onToggle: _toggle,
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                if (chosen.length < 2)
                  ZCard(
                    child: EmptyState(
                      compact: true,
                      icon: Icons.tune_rounded,
                      title: 'Choose at least two',
                      message:
                          'Select two or more saved creators above to see them '
                          'compared.',
                    ),
                  )
                else ...[
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          'Side by side',
                          style: AppTypography.titleMedium.copyWith(fontSize: 17),
                        ),
                      ),
                      const _BestLegend(),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  Reveal(child: _ComparisonTable(creators: chosen)),
                  if (chosen.length > 2) ...[
                    const SizedBox(height: AppSpacing.xs),
                    Text(
                      'Swipe the table sideways to see everyone.',
                      textAlign: TextAlign.center,
                      style: AppTypography.labelSmall,
                    ),
                  ],
                ],
              ],
            ),
          );
        },
      ),
    );
  }

  void _toggle(Creator creator) {
    setState(() {
      if (_selected.contains(creator.id)) {
        _selected.remove(creator.id);
      } else if (_selected.length < CompareCreatorsScreen.maxSelection) {
        _selected.add(creator.id);
      } else {
        showZSnack(
          context,
          'You can compare up to ${CompareCreatorsScreen.maxSelection} '
          'creators at once.',
          isError: true,
        );
      }
    });
  }
}

/// "Best" swatch explaining the green highlight.
class _BestLegend extends StatelessWidget {
  const _BestLegend();

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 12,
          height: 12,
          decoration: BoxDecoration(
            color: AppColors.successContainer,
            borderRadius: AppRadius.smAll,
            border: Border.all(color: AppColors.success.withValues(alpha: 0.4)),
          ),
        ),
        const SizedBox(width: 6),
        Text('Best in row', style: AppTypography.labelSmall),
      ],
    );
  }
}

/// Horizontal strip of saved creators to toggle into the comparison.
class _Picker extends StatelessWidget {
  const _Picker({
    required this.creators,
    required this.selected,
    required this.onToggle,
  });

  final List<Creator> creators;
  final Set<int> selected;
  final ValueChanged<Creator> onToggle;

  @override
  Widget build(BuildContext context) {
    // Avatar and padding are fixed; the two text lines grow with the
    // reader's text size, so the strip does too.
    final textScaler = MediaQuery.textScalerOf(context);

    return SizedBox(
      height: 90 + textScaler.scale(34),
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
        itemCount: creators.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.base),
        itemBuilder: (_, i) {
          final creator = creators[i];
          final isSelected = selected.contains(creator.id);

          return Semantics(
            button: true,
            selected: isSelected,
            label: '${creator.displayName}, ${creator.followersLabel} followers',
            excludeSemantics: true,
            onTap: () => onToggle(creator),
            child: InkWell(
              onTap: () => onToggle(creator),
              borderRadius: AppRadius.lgAll,
              child: SizedBox(
                width: 80,
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
                  child: Column(
                    children: [
                      Stack(
                        clipBehavior: Clip.none,
                        children: [
                          ZAvatar(
                            imageUrl: creator.avatarUrl,
                            name: creator.displayName,
                            size: 60,
                            ringWidth: isSelected ? 2.5 : 0,
                            ringColor: AppColors.primary,
                          ),
                          Positioned(
                            right: -2,
                            bottom: -2,
                            child: AnimatedScale(
                              scale: isSelected ? 1 : 0,
                              duration: Motion.of(context, Motion.fast),
                              curve: Motion.standard,
                              child: Container(
                                width: 22,
                                height: 22,
                                decoration: BoxDecoration(
                                  color: AppColors.primary,
                                  shape: BoxShape.circle,
                                  border: Border.all(
                                    color: AppColors.surfaceContainerLowest,
                                    width: 2,
                                  ),
                                ),
                                child: Icon(
                                  Icons.check_rounded,
                                  size: 13,
                                  color: AppColors.onPrimary,
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(
                        creator.displayName.split(' ').first,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.labelMedium.copyWith(
                          color: isSelected
                              ? AppColors.primary
                              : AppColors.onSurface,
                          fontWeight:
                              isSelected ? FontWeight.w700 : FontWeight.w600,
                        ),
                      ),
                      Text(
                        creator.followersLabel,
                        maxLines: 1,
                        style: AppTypography.labelSmall,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

/// The comparison grid: one column per creator, one row per attribute.
class _ComparisonTable extends StatelessWidget {
  const _ComparisonTable({required this.creators});

  final List<Creator> creators;

  /// Column width that keeps 2–4 creators readable without squashing.
  static const _columnWidth = 132.0;

  @override
  Widget build(BuildContext context) {
    final rows = <_Row>[
      _Row(
        label: 'Starting price',
        icon: Icons.payments_outlined,
        values: creators.map((c) => Formatters.currency(c.priceMin)).toList(),
        // Cheapest wins.
        bestIndex: _indexOfMin(creators.map((c) => c.priceMin).toList()),
      ),
      _Row(
        label: 'Followers',
        icon: Icons.group_outlined,
        values: creators.map((c) => c.followersLabel).toList(),
        bestIndex: _indexOfMax(
          creators.map((c) => c.followers.toDouble()).toList(),
        ),
      ),
      _Row(
        label: 'Engagement',
        icon: Icons.trending_up_rounded,
        values: creators
            .map((c) => '${c.engagementRate.toStringAsFixed(1)}%')
            .toList(),
        bestIndex:
            _indexOfMax(creators.map((c) => c.engagementRate).toList()),
      ),
      _Row(
        label: 'Rating',
        icon: Icons.star_outline_rounded,
        values: creators
            .map((c) => '${Formatters.rating(c.rating)} (${c.ratingCount})')
            .toList(),
        bestIndex: _indexOfMax(creators.map((c) => c.rating).toList()),
      ),
      _Row(
        label: 'Campaigns done',
        icon: Icons.handshake_outlined,
        values: creators.map((c) => '${c.collabsCount}').toList(),
        bestIndex: _indexOfMax(
          creators.map((c) => c.collabsCount.toDouble()).toList(),
        ),
      ),
      _Row(
        label: 'Responds',
        icon: Icons.schedule_rounded,
        values: creators.map((c) => _responseLabel(c.responseTime)).toList(),
      ),
      _Row(
        label: 'Availability',
        icon: Icons.event_available_outlined,
        values: creators.map((c) => c.availability.label).toList(),
      ),
      _Row(
        label: 'Location',
        icon: Icons.location_on_outlined,
        values: creators
            .map((c) => c.city ?? c.location.split(',').first)
            .toList(),
      ),
      _Row(
        label: 'Languages',
        icon: Icons.translate_rounded,
        values: creators
            .map((c) => c.languages.isEmpty ? '—' : c.languages.join(', '))
            .toList(),
      ),
      _Row(
        label: 'Verified',
        icon: Icons.verified_outlined,
        values: creators.map((c) => c.isVerified ? 'Yes' : 'No').toList(),
      ),
    ];

    return ZCard(
      padding: EdgeInsets.zero,
      clip: true,
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _HeaderRow(creators: creators, columnWidth: _columnWidth),
            for (var i = 0; i < rows.length; i++)
              _AttributeRow(
                row: rows[i],
                columnWidth: _columnWidth,
                shaded: i.isOdd,
              ),
            _BookRow(creators: creators, columnWidth: _columnWidth),
          ],
        ),
      ),
    );
  }

  static String _responseLabel(String? value) => switch (value) {
        'within_1h' => '< 1 hour',
        'within_6h' => '< 6 hours',
        'within_24h' => '< 24 hours',
        'within_48h' => '< 2 days',
        _ => '—',
      };

  static int? _indexOfMax(List<double> values) {
    if (values.isEmpty) return null;
    var best = 0;
    for (var i = 1; i < values.length; i++) {
      if (values[i] > values[best]) best = i;
    }
    // A tie has no winner worth highlighting.
    return values.where((v) => v == values[best]).length > 1 ? null : best;
  }

  static int? _indexOfMin(List<double> values) {
    if (values.isEmpty) return null;
    var best = 0;
    for (var i = 1; i < values.length; i++) {
      if (values[i] < values[best]) best = i;
    }
    return values.where((v) => v == values[best]).length > 1 ? null : best;
  }
}

class _Row {
  const _Row({
    required this.label,
    required this.icon,
    required this.values,
    this.bestIndex,
  });

  final String label;
  final IconData icon;
  final List<String> values;

  /// Column to highlight as the strongest on this attribute, if any.
  final int? bestIndex;
}

const double _labelWidth = 116;

class _HeaderRow extends StatelessWidget {
  const _HeaderRow({required this.creators, required this.columnWidth});

  final List<Creator> creators;
  final double columnWidth;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.surfaceContainerLow,
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.md),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: _labelWidth,
            child: Padding(
              padding: const EdgeInsets.only(left: AppSpacing.md, top: 4),
              child: Text(
                '${creators.length} creators',
                style: AppTypography.labelMedium
                    .copyWith(color: AppColors.onSurfaceVariant),
              ),
            ),
          ),
          for (final creator in creators)
            SizedBox(
              width: columnWidth,
              child: Column(
                children: [
                  ZAvatar(
                    imageUrl: creator.avatarUrl,
                    name: creator.displayName,
                    size: 48,
                    isVerified: creator.isVerified,
                    onTap: () => context.push(Routes.creator(creator.handle)),
                  ),
                  const SizedBox(height: 6),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 6),
                    child: Text(
                      creator.displayName,
                      maxLines: 2,
                      textAlign: TextAlign.center,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelLarge,
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 6),
                    child: Text(
                      creator.atHandle,
                      maxLines: 1,
                      textAlign: TextAlign.center,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelSmall,
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _AttributeRow extends StatelessWidget {
  const _AttributeRow({
    required this.row,
    required this.columnWidth,
    required this.shaded,
  });

  final _Row row;
  final double columnWidth;

  /// Alternate rows take a faint tint so the eye can follow one across.
  final bool shaded;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: shaded
            ? AppColors.surfaceContainerLow.withValues(alpha: 0.5)
            : null,
        border: Border(
          top: BorderSide(color: AppColors.outlineVariant),
        ),
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 48),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              SizedBox(
                width: _labelWidth,
                child: Padding(
                  padding: const EdgeInsets.only(left: AppSpacing.md),
                  child: Row(
                    children: [
                      Icon(
                        row.icon,
                        size: 16,
                        color: AppColors.primary,
                      ),
                      const SizedBox(width: 6),
                      Flexible(
                        child: Text(
                          row.label,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.labelMedium.copyWith(
                            color: AppColors.onSurfaceVariant,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              for (var i = 0; i < row.values.length; i++)
                SizedBox(
                  width: columnWidth,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 6),
                    child: row.bestIndex == i
                        ? _BestValue(value: row.values[i])
                        : Text(
                            row.values[i],
                            textAlign: TextAlign.center,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.bodySmall.copyWith(
                              color: AppColors.onSurface,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Highlights the strongest value in a row.
class _BestValue extends StatelessWidget {
  const _BestValue({required this.value});

  final String value;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: '$value, best',
      excludeSemantics: true,
      child: Center(
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
          decoration: BoxDecoration(
            color: AppColors.successContainer,
            borderRadius: AppRadius.pill,
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.emoji_events_rounded,
                size: 12,
                color: AppColors.onSuccessContainer,
              ),
              const SizedBox(width: 4),
              Flexible(
                child: Text(
                  value,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.labelMedium.copyWith(
                    color: AppColors.onSuccessContainer,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _BookRow extends StatelessWidget {
  const _BookRow({required this.creators, required this.columnWidth});

  final List<Creator> creators;
  final double columnWidth;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: AppColors.outlineVariant)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(
          children: [
            const SizedBox(width: _labelWidth),
            for (final creator in creators)
              SizedBox(
                width: columnWidth,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 6),
                  child: Column(
                    children: [
                      ZButton(
                        label: 'Book',
                        size: ZButtonSize.small,
                        onPressed: () =>
                            context.push(Routes.newBooking(creator.handle)),
                      ),
                      const SizedBox(height: 4),
                      TextButton(
                        onPressed: () =>
                            context.push(Routes.creator(creator.handle)),
                        style: TextButton.styleFrom(
                          minimumSize: const Size(
                            AppSpacing.minTouch,
                            AppSpacing.minTouch,
                          ),
                        ),
                        child: const Text('Profile'),
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

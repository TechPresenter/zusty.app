import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme/app_theme.dart';
import '../../models/city.dart';
import '../../providers/location_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_text_field.dart';

/// The city picker behind the header chip.
///
/// Offers GPS auto-detect at the top, then metro shortcuts, then the full
/// searchable list — the fastest path first, with a manual fallback that always
/// works even when permission is refused.
class CitySelectorSheet extends ConsumerStatefulWidget {
  const CitySelectorSheet({super.key});

  static Future<void> show(BuildContext context) => showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        sheetAnimationStyle: zSheetMotion(context),
        builder: (_) => const CitySelectorSheet(),
      );

  @override
  ConsumerState<CitySelectorSheet> createState() => _CitySelectorSheetState();
}

class _CitySelectorSheetState extends ConsumerState<CitySelectorSheet> {
  final _search = TextEditingController();
  String _query = '';

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _detect() async {
    await ref.read(locationProvider.notifier).detect();
    if (!mounted) return;

    final state = ref.read(locationProvider);

    if (state.status == DetectStatus.success) {
      _close();
      showZSnack(context, 'Location set to ${state.city!.name}.');
    } else if (state.errorMessage != null) {
      showZSnack(context, state.errorMessage!, isError: true);
    }
  }

  Future<void> _select(City city) async {
    await ref.read(locationProvider.notifier).select(city);
    if (mounted) _close();
  }

  /// Pops this sheet, and only this sheet.
  ///
  /// Both callers pop after an await. If the sheet was dismissed in the
  /// meantime (a barrier tap, the back gesture, a second tap on a row) it is
  /// still mounted while it animates out, and a bare pop() would take the page
  /// underneath instead, emptying the router's stack.
  void _close() {
    if (ModalRoute.of(context)?.isCurrent ?? false) {
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final location = ref.watch(locationProvider);

    // The list comes from the taxonomy bundle. Watching it starts the fetch if
    // nothing has yet, and rebuilds the sheet when it lands.
    final cities = ref.watch(taxonomyBundleProvider);
    final results = Cities.search(_query);
    final showMetros = _query.trim().isEmpty;

    return SafeArea(
      top: false,
      child: Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: SizedBox(
          height: MediaQuery.sizeOf(context).height * 0.78,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.marginMobile,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Choose your city',
                        style: AppTypography.headlineSmall),
                    const SizedBox(height: 2),
                    Text(
                      'We use this to surface creators near you.',
                      style: AppTypography.bodySmall,
                    ),
                    const SizedBox(height: AppSpacing.md),
                    _DetectButton(
                      isDetecting: location.isDetecting,
                      onTap: _detect,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    ZSearchField(
                      controller: _search,
                      hint: 'Search for a city or state…',
                      showClear: _query.isNotEmpty,
                      onClear: () {
                        _search.clear();
                        setState(() => _query = '');
                      },
                      onChanged: (value) => setState(() => _query = value),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.sm),
              Expanded(
                child: Cities.all.isEmpty && cities.isLoading
                    ? const ZLoader()
                    : Cities.all.isEmpty && cities.hasError
                    ? ErrorState(
                        error: cities.error!,
                        onRetry: () => ref.invalidate(taxonomyBundleProvider),
                      )
                    : results.isEmpty
                    ? const EmptyState(
                        compact: true,
                        icon: Icons.location_off_rounded,
                        title: 'No matching city',
                        message: 'Try a different name, or pick a nearby metro.',
                      )
                    : ListView(
                        padding: EdgeInsets.fromLTRB(
                          AppSpacing.marginMobile,
                          0,
                          AppSpacing.marginMobile,
                          AppSpacing.lg,
                        ),
                        children: [
                          if (showMetros) ...[
                            Text('Popular cities',
                                style: AppTypography.labelMedium.copyWith(
                                  color: AppColors.onSurfaceVariant,
                                )),
                            const SizedBox(height: AppSpacing.xs),
                            Wrap(
                              spacing: AppSpacing.xs,
                              runSpacing: AppSpacing.xs,
                              children: [
                                for (final city in Cities.metros)
                                  ZChip(
                                    label: city.name,
                                    icon: Icons.location_city_rounded,
                                    selected: location.city == city,
                                    onTap: () => _select(city),
                                  ),
                              ],
                            ),
                            const SizedBox(height: AppSpacing.md),
                            Text('All cities',
                                style: AppTypography.labelMedium.copyWith(
                                  color: AppColors.onSurfaceVariant,
                                )),
                            const SizedBox(height: AppSpacing.base),
                          ],
                          for (final city in results)
                            _CityRow(
                              city: city,
                              selected: location.city == city,
                              onTap: () => _select(city),
                            ),
                        ],
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The GPS call-to-action at the top of the sheet.
class _DetectButton extends StatelessWidget {
  const _DetectButton({required this.isDetecting, required this.onTap});

  final bool isDetecting;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.primaryFixed,
      borderRadius: AppRadius.mdAll,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: isDetecting ? null : onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md,
            vertical: AppSpacing.sm,
          ),
          child: Row(
            children: [
              SizedBox(
                width: 20,
                height: 20,
                child: isDetecting
                    ? CircularProgressIndicator(
                        strokeWidth: 2,
                        valueColor: AlwaysStoppedAnimation(
                          AppColors.onPrimaryFixedVariant,
                        ),
                      )
                    : Icon(
                        Icons.my_location_rounded,
                        size: 20,
                        color: AppColors.onPrimaryFixedVariant,
                      ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      isDetecting
                          ? 'Detecting your location…'
                          : 'Use my current location',
                      style: AppTypography.bodyMedium.copyWith(
                        fontWeight: FontWeight.w600,
                        color: AppColors.onPrimaryFixedVariant,
                      ),
                    ),
                    Text(
                      'Fastest way to see creators near you',
                      style: AppTypography.labelSmall.copyWith(
                        color: AppColors.onPrimaryFixedVariant
                            .withValues(alpha: 0.8),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CityRow extends StatelessWidget {
  const _CityRow({
    required this.city,
    required this.selected,
    required this.onTap,
  });

  final City city;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      onTap: onTap,
      contentPadding: EdgeInsets.zero,
      dense: true,
      leading: Container(
        width: 38,
        height: 38,
        decoration: BoxDecoration(
          color: selected
              ? AppColors.primary
              : AppColors.surfaceContainerHigh,
          borderRadius: AppRadius.mdAll,
        ),
        child: Icon(
          Icons.location_on_rounded,
          size: 18,
          color: selected ? AppColors.onPrimary : AppColors.onSurfaceVariant,
        ),
      ),
      title: Text(
        city.name,
        style: AppTypography.bodyMedium.copyWith(
          fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
        ),
      ),
      subtitle: Text(city.state, style: AppTypography.labelSmall),
      trailing: selected
          ? Icon(Icons.check_circle_rounded,
              size: 20, color: AppColors.primary)
          : null,
    );
  }
}

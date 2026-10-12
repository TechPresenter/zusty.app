import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';
import '../motion/pressable.dart';

/// Pill-segmented tabs ("Active | Completed | Cancelled"): the selected
/// segment fills magenta, the rest sit on a blush track.
///
/// Drives a [TabController] like a [TabBar] does, so it drops into an existing
/// `DefaultTabController` / `TabBarView` pair unchanged. Counts, when given,
/// render as a small badge after each label.
class ZSegmentedTabs extends StatelessWidget implements PreferredSizeWidget {
  const ZSegmentedTabs({
    super.key,
    required this.labels,
    this.controller,
    this.counts,
    this.padding,
  });

  final List<String> labels;
  final TabController? controller;

  /// Optional badge per tab; null or zero hides it.
  final List<int?>? counts;

  /// Defaults to the page margin across (16dp on a phone, 24 on a tablet, in
  /// line with the page's content) and 8dp above and below.
  final EdgeInsetsGeometry? padding;

  @override
  Size get preferredSize => const Size.fromHeight(44 + AppSpacing.xs * 2);

  @override
  Widget build(BuildContext context) {
    final tabs = controller ?? DefaultTabController.of(context);

    return Padding(
      padding:
          padding ??
          EdgeInsets.symmetric(
            horizontal: AppBreakpoints.pageMargin(context),
            vertical: AppSpacing.xs,
          ),
      child: Container(
        height: 44,
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(
          color: AppColors.surfaceContainerLow,
          borderRadius: AppRadius.pill,
          border: Border.all(color: AppColors.outlineVariant),
        ),
        child: TabBar(
          controller: tabs,
          dividerColor: Colors.transparent,
          indicatorSize: TabBarIndicatorSize.tab,
          indicator: BoxDecoration(
            color: AppColors.primary,
            borderRadius: AppRadius.pill,
            boxShadow: AppShadows.subtle,
          ),
          labelColor: AppColors.onPrimary,
          unselectedLabelColor: AppColors.onSurfaceVariant,
          labelStyle: AppTypography.labelLarge,
          unselectedLabelStyle: AppTypography.labelLarge
              .copyWith(fontWeight: FontWeight.w500),
          labelPadding: EdgeInsets.zero,
          splashBorderRadius: AppRadius.pill,
          overlayColor: const WidgetStatePropertyAll(Colors.transparent),
          tabs: [
            for (var i = 0; i < labels.length; i++)
              Tab(
                height: 36,
                child: _Label(
                  label: labels[i],
                  count: counts == null || i >= counts!.length ? null : counts![i],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _Label extends StatelessWidget {
  const _Label({required this.label, this.count});

  final String label;
  final int? count;

  @override
  Widget build(BuildContext context) {
    final color = DefaultTextStyle.of(context).style.color;

    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      mainAxisSize: MainAxisSize.min,
      children: [
        Flexible(
          child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis),
        ),
        if (count != null && count! > 0) ...[
          const SizedBox(width: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
            decoration: BoxDecoration(
              color: (color ?? AppColors.onSurface).withValues(alpha: 0.16),
              borderRadius: AppRadius.pill,
            ),
            child: Text(
              '$count',
              style: AppTypography.labelSmall.copyWith(color: color),
            ),
          ),
        ],
      ],
    );
  }
}

/// A horizontal row of filter pills for a list ("All, Bookings, Payments…"),
/// scrolling when it does not fit. Selection is the caller's state.
class ZFilterPills extends StatelessWidget {
  const ZFilterPills({
    super.key,
    required this.labels,
    required this.selected,
    required this.onSelected,
    this.padding,
  });

  final List<String> labels;
  final int selected;
  final ValueChanged<int> onSelected;

  /// Defaults to the page margin either side: 16dp on a phone, 24 on a
  /// tablet, so the first pill lines up with the content below it.
  final EdgeInsetsGeometry? padding;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: AppSpacing.minTouch,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding:
            padding ??
            EdgeInsets.symmetric(
              horizontal: AppBreakpoints.pageMargin(context),
            ),
        itemCount: labels.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.xs),
        itemBuilder: (context, i) {
          final isSelected = i == selected;
          final duration = Motion.of(context, Motion.fast);

          // The selection moves by easing colours, not by flipping them.
          return Pressable(
            pressedScale: 0.95,
            child: AnimatedContainer(
              duration: duration,
              curve: Motion.standard,
              decoration: ShapeDecoration(
                color: isSelected
                    ? AppColors.primary
                    : AppColors.surfaceContainerLowest,
                shape: StadiumBorder(
                  side: BorderSide(
                    color: isSelected
                        ? Colors.transparent
                        : AppColors.outlineVariant,
                  ),
                ),
              ),
              child: Material(
                type: MaterialType.transparency,
                child: InkWell(
                  onTap: () => onSelected(i),
                  customBorder: const StadiumBorder(),
                  child: Padding(
                    padding:
                        const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                    child: Center(
                      child: AnimatedDefaultTextStyle(
                        duration: duration,
                        curve: Motion.standard,
                        style: AppTypography.labelLarge.copyWith(
                          fontSize: 13,
                          color: isSelected
                              ? AppColors.onPrimary
                              : AppColors.onSurface,
                        ),
                        child: Text(labels[i]),
                      ),
                    ),
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

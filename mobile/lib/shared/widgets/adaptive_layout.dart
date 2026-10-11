import 'package:flutter/material.dart';

import '../../core/theme/app_theme.dart';

// Large-screen layout primitives. Each one hands its child through untouched
// on a compact (phone) window, so phone layouts are exactly what they were;
// only on a medium or expanded window do they centre, cap or re-flow.

/// Centres [child] in a column at most [maxWidth] wide on a tablet, with the
/// page background showing either side. On a phone it returns [child] as is.
///
/// The child is given the column's full width (as a phone screen gives a page
/// its full width), so a page laid out for 390dp lays out for 720dp the same
/// way rather than shrink-wrapping.
class ContentWidthBox extends StatelessWidget {
  const ContentWidthBox({
    super.key,
    required this.child,
    this.maxWidth = ContentWidth.readable,
    this.alignment = Alignment.topCenter,
  });

  final Widget child;

  /// The widest the column grows. `double.infinity` leaves the child full
  /// width.
  final double maxWidth;

  /// Where the column sits when the child does not fill the height.
  final AlignmentGeometry alignment;

  @override
  Widget build(BuildContext context) {
    if (WindowSize.of(context).isCompact || maxWidth == double.infinity) {
      return child;
    }

    return Align(
      alignment: alignment,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: maxWidth),
        child: SizedBox(width: double.infinity, child: child),
      ),
    );
  }
}

/// An app bar whose contents line up with a [ContentWidthBox] body: the back
/// button, title and actions sit over the content column rather than in the
/// far corners of a tablet. [backgroundColor] fills the sides, and should be
/// the bar's own colour. On a phone it returns [child] as is.
class ContentWidthAppBar extends StatelessWidget
    implements PreferredSizeWidget {
  const ContentWidthAppBar({
    super.key,
    required this.child,
    this.maxWidth = ContentWidth.readable,
    this.backgroundColor,
  });

  final PreferredSizeWidget child;
  final double maxWidth;
  final Color? backgroundColor;

  // The very object the bar reports, so Scaffold's toolbar-height lookup
  // (`AppBar.preferredHeightFor`) treats the wrapper exactly like the bar.
  @override
  Size get preferredSize => child.preferredSize;

  @override
  Widget build(BuildContext context) {
    if (WindowSize.of(context).isCompact || maxWidth == double.infinity) {
      return child;
    }

    return ColoredBox(
      color: backgroundColor ?? AppColors.surface,
      child: Align(
        alignment: Alignment.topCenter,
        heightFactor: 1,
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: maxWidth),
          child: SizedBox(width: double.infinity, child: child),
        ),
      ),
    );
  }
}

/// A scrolling list of cards: one column on a phone, two side by side once
/// the list is at least [ContentWidth.twoColumnList] wide (a tablet), so the
/// cards keep roughly their phone width instead of stretching.
///
/// On a phone this is exactly `ListView.separated` with a [spacing]-tall gap.
/// The cards must lay out at any width (they are given half the row each);
/// rows are top-aligned, so neighbours of different heights do not stretch.
class AdaptiveCardList extends StatelessWidget {
  const AdaptiveCardList({
    super.key,
    required this.itemCount,
    required this.itemBuilder,
    this.padding,
    this.spacing = AppSpacing.sm,
    this.controller,
    this.physics,
  });

  final int itemCount;
  final IndexedWidgetBuilder itemBuilder;
  final EdgeInsetsGeometry? padding;

  /// The gap between cards, down and across.
  final double spacing;
  final ScrollController? controller;
  final ScrollPhysics? physics;

  Widget _list({required bool twoColumns}) => ListView.separated(
    controller: controller,
    physics: physics,
    padding: padding,
    itemCount: twoColumns ? (itemCount + 1) ~/ 2 : itemCount,
    separatorBuilder: (_, _) => SizedBox(height: spacing),
    itemBuilder: twoColumns
        ? (context, row) => _CardRow(
            row: row,
            itemCount: itemCount,
            spacing: spacing,
            itemBuilder: itemBuilder,
          )
        : itemBuilder,
  );

  @override
  Widget build(BuildContext context) {
    if (WindowSize.of(context).isCompact) return _list(twoColumns: false);

    return LayoutBuilder(
      builder: (context, constraints) {
        final insets =
            padding?.resolve(Directionality.of(context)).horizontal ?? 0;
        return _list(
          twoColumns:
              constraints.maxWidth - insets >= ContentWidth.twoColumnList,
        );
      },
    );
  }
}

/// The sliver form of [AdaptiveCardList], for a `CustomScrollView`. On a
/// phone it is exactly `SliverList.separated` with a [spacing]-tall gap.
class SliverAdaptiveCardList extends StatelessWidget {
  const SliverAdaptiveCardList({
    super.key,
    required this.itemCount,
    required this.itemBuilder,
    this.spacing = AppSpacing.md,
  });

  final int itemCount;
  final IndexedWidgetBuilder itemBuilder;
  final double spacing;

  Widget _list({required bool twoColumns}) => SliverList.separated(
    itemCount: twoColumns ? (itemCount + 1) ~/ 2 : itemCount,
    separatorBuilder: (_, _) => SizedBox(height: spacing),
    itemBuilder: twoColumns
        ? (context, row) => _CardRow(
            row: row,
            itemCount: itemCount,
            spacing: spacing,
            itemBuilder: itemBuilder,
          )
        : itemBuilder,
  );

  @override
  Widget build(BuildContext context) {
    if (WindowSize.of(context).isCompact) return _list(twoColumns: false);

    return SliverLayoutBuilder(
      builder: (context, constraints) => _list(
        twoColumns: constraints.crossAxisExtent >= ContentWidth.twoColumnList,
      ),
    );
  }
}

/// Cards `2 * row` and `2 * row + 1` side by side; the second slot is left
/// empty under an odd count so the last card keeps its column width.
class _CardRow extends StatelessWidget {
  const _CardRow({
    required this.row,
    required this.itemCount,
    required this.spacing,
    required this.itemBuilder,
  });

  final int row;
  final int itemCount;
  final double spacing;
  final IndexedWidgetBuilder itemBuilder;

  @override
  Widget build(BuildContext context) {
    final first = row * 2;
    final second = first + 1;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: itemBuilder(context, first)),
        SizedBox(width: spacing),
        Expanded(
          child: second < itemCount
              ? itemBuilder(context, second)
              : const SizedBox.shrink(),
        ),
      ],
    );
  }
}

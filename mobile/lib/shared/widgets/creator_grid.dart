import 'package:flutter/rendering.dart';

import '../../core/theme/app_theme.dart';
import 'creator_card.dart';

/// Lays out [CreatorGridCard]s: two columns on a phone, up to [maxColumns]
/// where there is room, each row as tall as the card needs at the current
/// width and text size.
///
/// A grid wider than any phone's (a tablet) adds columns instead, so cards
/// stay close to their phone size rather than becoming 500dp posters: as many
/// as fit at [_tabletMinCell] each, and never fewer than [maxColumns].
///
/// A fixed `childAspectRatio` cannot do this: the card's text block is a fixed
/// height while its photo scales with the width, so any single ratio is too
/// short on one phone and too tall on the next. This delegate reads the real
/// cross-axis width at layout time and sizes rows from it.
class CreatorGridDelegate extends SliverGridDelegate {
  const CreatorGridDelegate({
    this.maxColumns = 2,
    this.spacing = AppSpacing.sm,
    this.textScale = 1,
  });

  final int maxColumns;
  final double spacing;

  /// `MediaQuery.textScalerOf(context).scale(14) / 14`, so larger text gets
  /// taller rows rather than a squeezed photo.
  final double textScale;

  /// A grid at least this wide is on a tablet: a phone's screen, margins
  /// and all, is narrower.
  static const double _tabletWidth = Breakpoints.medium;

  /// The narrowest a card gets on a tablet; a phone's are 170 to 280.
  static const double _tabletMinCell = 200;

  int _columnsFor(double width) {
    final phoneColumns = width < 520 ? 2 : maxColumns.clamp(2, 4);
    if (width < _tabletWidth) return phoneColumns;

    final fit = ((width + spacing) / (_tabletMinCell + spacing)).floor();
    return fit.clamp(phoneColumns, 6);
  }

  @override
  SliverGridLayout getLayout(SliverConstraints constraints) {
    final width = constraints.crossAxisExtent;
    final columns = _columnsFor(width);
    final cell = (width - spacing * (columns - 1)) / columns;
    final extent = CreatorGridCard.extentFor(cell, textScale: textScale);

    return SliverGridRegularTileLayout(
      crossAxisCount: columns,
      mainAxisStride: extent + spacing,
      crossAxisStride: cell + spacing,
      childMainAxisExtent: extent,
      childCrossAxisExtent: cell,
      reverseCrossAxis: axisDirectionIsReversed(constraints.crossAxisDirection),
    );
  }

  @override
  bool shouldRelayout(CreatorGridDelegate oldDelegate) =>
      oldDelegate.maxColumns != maxColumns ||
      oldDelegate.spacing != spacing ||
      oldDelegate.textScale != textScale;
}

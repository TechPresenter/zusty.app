import 'package:flutter/rendering.dart';

import '../../core/theme/app_theme.dart';
import 'creator_card.dart';

/// Lays out [CreatorGridCard]s: two columns on a phone, up to [maxColumns]
/// where there is room, each row as tall as the card needs at the current
/// width and text size.
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

  @override
  SliverGridLayout getLayout(SliverConstraints constraints) {
    final width = constraints.crossAxisExtent;
    final columns = width < 520 ? 2 : maxColumns.clamp(2, 4);
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

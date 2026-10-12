import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';

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
/// The cards must lay out at any width (they are given half the row each).
/// The two cards in a row are as tall as each other, so a title that wraps
/// in one does not leave its neighbour short: the shorter card is given a
/// minimum height, which a [CardBodyAndFooter] in it places above its footer.
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
          twoColumns: Breakpoints.reaches(
            constraints.maxWidth - insets,
            ContentWidth.twoColumnList,
          ),
        );
      },
    );
  }
}

/// The sliver form of [AdaptiveCardList], for a `CustomScrollView`. On a
/// phone it is exactly `SliverList.separated` with a [spacing]-tall gap; on a
/// tablet the two cards in a row are as tall as each other.
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
        twoColumns: Breakpoints.reaches(
          constraints.crossAxisExtent,
          ContentWidth.twoColumnList,
        ),
      ),
    );
  }
}

/// Cards `2 * row` and `2 * row + 1` side by side and as tall as each other;
/// the second slot is left empty under an odd count so the last card keeps
/// its column width (and its own height).
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

    return _EqualHeightRow(
      columns: 2,
      spacing: spacing,
      children: [
        itemBuilder(context, first),
        if (second < itemCount) itemBuilder(context, second),
      ],
    );
  }
}

/// Lays [children] out side by side in [columns] equal columns [spacing]
/// apart, every child as tall as the tallest. Children past [columns] are
/// not allowed; fewer leave the trailing columns empty.
///
/// Each child is laid out once at its column's width to find its own height,
/// then a shorter one again with the tallest height as its minimum. Unlike
/// `IntrinsicHeight` around a stretched `Row`, this never asks a card for its
/// intrinsic size, which a card holding a `LayoutBuilder` cannot answer, and
/// costs one extra layout of the shorter card rather than a speculative pass
/// over both.
///
/// No child is ever given a tight height, so none becomes its own relayout
/// boundary: when a card's content changes by itself later (a chip appearing,
/// a title growing), the change reaches this row, which measures both cards
/// again, rather than the card laying itself out alone at a stale height.
class _EqualHeightRow extends MultiChildRenderObjectWidget {
  const _EqualHeightRow({
    required this.columns,
    required this.spacing,
    required super.children,
  }) : assert(columns > 0),
       assert(children.length <= columns);

  final int columns;
  final double spacing;

  @override
  RenderObject createRenderObject(BuildContext context) =>
      _RenderEqualHeightRow(
        columns: columns,
        spacing: spacing,
        textDirection: Directionality.of(context),
      );

  @override
  void updateRenderObject(
    BuildContext context,
    _RenderEqualHeightRow renderObject,
  ) {
    renderObject
      ..columns = columns
      ..spacing = spacing
      ..textDirection = Directionality.of(context);
  }
}

class _EqualHeightRowParentData extends ContainerBoxParentData<RenderBox> {}

class _RenderEqualHeightRow extends RenderBox
    with
        ContainerRenderObjectMixin<RenderBox, _EqualHeightRowParentData>,
        RenderBoxContainerDefaultsMixin<RenderBox, _EqualHeightRowParentData> {
  _RenderEqualHeightRow({
    required int columns,
    required double spacing,
    required TextDirection textDirection,
  }) : _columns = columns,
       _spacing = spacing,
       _textDirection = textDirection;

  int get columns => _columns;
  int _columns;
  set columns(int value) {
    if (value == _columns) return;
    _columns = value;
    markNeedsLayout();
  }

  double get spacing => _spacing;
  double _spacing;
  set spacing(double value) {
    if (value == _spacing) return;
    _spacing = value;
    markNeedsLayout();
  }

  TextDirection get textDirection => _textDirection;
  TextDirection _textDirection;
  set textDirection(TextDirection value) {
    if (value == _textDirection) return;
    _textDirection = value;
    markNeedsLayout();
  }

  @override
  void setupParentData(RenderBox child) {
    if (child.parentData is! _EqualHeightRowParentData) {
      child.parentData = _EqualHeightRowParentData();
    }
  }

  double _cellWidth(double width) =>
      math.max(0, (width - spacing * (columns - 1)) / columns);

  /// A child's constraints: its column's width, and any height up to the
  /// row's own limit.
  BoxConstraints _cellConstraints(BoxConstraints constraints) {
    final cell = _cellWidth(constraints.maxWidth);
    return BoxConstraints(
      minWidth: cell,
      maxWidth: cell,
      maxHeight: constraints.maxHeight,
    );
  }

  @override
  void performLayout() {
    final constraints = this.constraints;
    assert(constraints.hasBoundedWidth, 'An equal-height row needs a width.');
    final cell = _cellConstraints(constraints);

    // Each child's own height first...
    var height = 0.0;
    for (var child = firstChild; child != null; child = childAfter(child)) {
      child.layout(cell, parentUsesSize: true);
      height = math.max(height, child.size.height);
    }

    // ...then the shorter ones again, at least as tall as the tallest. A
    // minimum rather than an exact height: Flutter makes a child laid out
    // under tight constraints its own relayout boundary, so a stretched card
    // whose content later grew would relayout alone at the old height and
    // overflow instead of telling this row. (The minimum and maximum meet
    // only when the row is already as tall as its parent allows, and then
    // it could not grow anyway.)
    var column = 0;
    for (var child = firstChild; child != null; child = childAfter(child)) {
      if (child.size.height < height) {
        child.layout(cell.copyWith(minHeight: height), parentUsesSize: true);
      }
      final start = column * (cell.maxWidth + spacing);
      (child.parentData! as _EqualHeightRowParentData).offset = Offset(
        switch (textDirection) {
          TextDirection.ltr => start,
          TextDirection.rtl => constraints.maxWidth - start - cell.maxWidth,
        },
        0,
      );
      column++;
    }

    size = constraints.constrain(Size(constraints.maxWidth, height));
  }

  @override
  Size computeDryLayout(covariant BoxConstraints constraints) {
    final cell = _cellConstraints(constraints);
    var height = 0.0;
    for (var child = firstChild; child != null; child = childAfter(child)) {
      height = math.max(height, child.getDryLayout(cell).height);
    }
    return constraints.constrain(Size(constraints.maxWidth, height));
  }

  double _tallest(double width, double Function(RenderBox, double) measure) {
    final cell = _cellWidth(width);
    var height = 0.0;
    for (var child = firstChild; child != null; child = childAfter(child)) {
      height = math.max(height, measure(child, cell));
    }
    return height;
  }

  double _widest(double height, double Function(RenderBox, double) measure) {
    var width = 0.0;
    for (var child = firstChild; child != null; child = childAfter(child)) {
      width = math.max(width, measure(child, height));
    }
    return width * columns + spacing * (columns - 1);
  }

  @override
  double computeMinIntrinsicHeight(double width) =>
      _tallest(width, (child, w) => child.getMinIntrinsicHeight(w));

  @override
  double computeMaxIntrinsicHeight(double width) =>
      _tallest(width, (child, w) => child.getMaxIntrinsicHeight(w));

  @override
  double computeMinIntrinsicWidth(double height) =>
      _widest(height, (child, h) => child.getMinIntrinsicWidth(h));

  @override
  double computeMaxIntrinsicWidth(double height) =>
      _widest(height, (child, h) => child.getMaxIntrinsicWidth(h));

  @override
  bool hitTestChildren(BoxHitTestResult result, {required Offset position}) =>
      defaultHitTestChildren(result, position: position);

  @override
  void paint(PaintingContext context, Offset offset) =>
      defaultPaint(context, offset);
}

/// A card's content as a [body] over a [footer], for a card that may sit in
/// an [AdaptiveCardList].
///
/// In a phone's list, where a card is as tall as its content, this lays out
/// exactly as one start-aligned `Column` of [body] then [footer]. When a
/// tablet's list gives the card a minimum height to match a taller
/// neighbour, the extra opens between the two groups, so the footers of the
/// two cards in a row sit level along the bottom.
///
/// The card's own wrappers must hand the minimum down (padding, decoration,
/// ink and transforms do; an `Align` or a loose `Stack` would not).
class CardBodyAndFooter extends StatelessWidget {
  const CardBodyAndFooter({
    super.key,
    required this.body,
    required this.footer,
  });

  /// Everything above the footer, top to bottom.
  final List<Widget> body;

  /// The bottom block (a price, an action row), top to bottom.
  final List<Widget> footer;

  @override
  Widget build(BuildContext context) => Column(
    // `min`: a list's unbounded height gives the card exactly its content's
    // height, so a minimum from the row is the only thing that adds space,
    // and the Column hands that space out between its two groups.
    mainAxisSize: MainAxisSize.min,
    mainAxisAlignment: MainAxisAlignment.spaceBetween,
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: body,
      ),
      Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: footer,
      ),
    ],
  );
}

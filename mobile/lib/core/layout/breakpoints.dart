import 'package:flutter/widgets.dart';

/// Window size classes, after Material 3's: a phone in portrait is compact;
/// a small tablet, a foldable opened up, or a large tablet in portrait is
/// medium; a tablet in landscape is expanded.
///
/// Every large-screen adjustment in the app is keyed on this, and every one is
/// a no-op on [WindowSize.compact], so phone layouts are exactly what they were
/// before tablets were considered.
enum WindowSize {
  /// Under 600dp wide: every phone in portrait. "600dp" allows for the float
  /// error in a measured width (see [Breakpoints.reaches]), so a tablet
  /// Android calls 600dp wide is never compact.
  compact,

  /// 600 to 839dp wide: a 7-inch tablet, or a 10-inch tablet in portrait.
  medium,

  /// 840dp and wider: tablets in landscape.
  expanded;

  /// The class of the window [context] is laid out in.
  static WindowSize of(BuildContext context) =>
      forWidth(MediaQuery.sizeOf(context).width);

  static WindowSize forWidth(double width) {
    if (!Breakpoints.reaches(width, Breakpoints.medium)) return compact;
    if (!Breakpoints.reaches(width, Breakpoints.expanded)) return medium;
    return expanded;
  }

  bool get isCompact => this == compact;
  bool get isExpanded => this == expanded;
}

/// Width thresholds between the [WindowSize] classes, in logical pixels.
///
/// Compare a width against any of these, or against any other layout
/// threshold a tablet path uses, with [reaches] rather than `<` or `>=`.
abstract final class Breakpoints {
  static const double medium = 600;
  static const double expanded = 840;

  /// How far short of a threshold a width may fall and still count as
  /// reaching it.
  ///
  /// Android hands Flutter a window's size in physical pixels and a float
  /// density, so a size that is a whole number of dp to Android itself need
  /// not be one in Flutter: a 7-inch tablet at 1080x1920 and 288dpi is
  /// `sw600dp` to Android but 599.99998dp wide here, and a plain
  /// `width < 600` would give it the phone layout. Half a dp absorbs that
  /// error (and any rounding) while a phone a whole dp narrower, 599dp,
  /// stays a phone.
  static const double tolerance = 0.5;

  /// Whether [width] reaches [threshold]: `width >= threshold`, allowing for
  /// the float error in a measured window size (see [tolerance]).
  static bool reaches(double width, double threshold) =>
      width >= threshold - tolerance;
}

/// The widest each kind of content is allowed to grow on a large screen.
///
/// The app's layouts are designed for a phone. On a tablet they are centred
/// in a column no wider than these, and the page background shows either
/// side, rather than stretching cards, buttons and lines of text edge to edge.
abstract final class ContentWidth {
  /// Forms, detail pages, chat, settings and single-column lists: wide enough
  /// to use a tablet, narrow enough to keep lines of text readable.
  static const double readable = 720;

  /// Grids and multi-column lists of creators and briefs.
  static const double wide = 1040;

  /// The bottom navigation bar's buttons on a medium window, so five items
  /// do not spread across a whole tablet.
  static const double navigationBar = 560;

  /// Modal bottom sheets (Material 3's own default, stated explicitly).
  static const double sheet = 640;

  /// Dialogs. Wide enough for the date and time pickers in landscape.
  static const double dialog = 640;

  /// A list of cards turns into two columns once it has this much room, which
  /// only happens on a medium or expanded window.
  static const double twoColumnList = 700;
}

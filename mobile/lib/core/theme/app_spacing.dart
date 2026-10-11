import 'package:flutter/widgets.dart';

import '../layout/breakpoints.dart';

/// Spacing scale on an 8px grid, with 4px reserved for hairline nudges
/// (icon-to-label gaps, badge offsets).
abstract final class AppSpacing {
  static const double base = 4;
  static const double xs = 8;
  static const double sm = 12;
  static const double md = 16;
  static const double lg = 24;
  static const double xl = 32;
  static const double xxl = 48;

  /// Standard gutter between grid items.
  static const double gutter = 16;

  /// Outer page margin on phones.
  static const double marginMobile = 16;

  /// Outer page margin on tablets. Pages there are already centred in a
  /// capped column (see `ContentWidth`), so the margin only has to keep
  /// content off the column's edge, as on a phone.
  static const double marginTablet = 24;

  /// Outer page margin on desktop breakpoints. No longer used by
  /// [AppBreakpoints.pageMargin]: with the content column capped, 64 on each
  /// side left a 7-inch tablet less room than a large phone.
  static const double marginDesktop = 64;

  /// Horizontal page padding — the default for every screen body.
  static const EdgeInsets pageHorizontal =
      EdgeInsets.symmetric(horizontal: marginMobile);

  /// Padding inside a standard card.
  static const EdgeInsets card = EdgeInsets.all(md);

  /// Minimum touch target edge, per platform accessibility guidance.
  static const double minTouch = 44;
}

/// Corner radii. Generous by design: 12 for inputs and small tiles, 16 for
/// inner cards, 20 for cards, 28 for sheets, and pills for primary actions.
abstract final class AppRadius {
  static const double sm = 6;
  static const double base = 10;
  static const double md = 12; // inputs, icon tiles
  static const double lg = 16; // inner cards, media
  static const double xl = 20; // cards
  static const double xxl = 28; // sheets, hero panels
  static const double full = 9999;

  static const BorderRadius smAll = BorderRadius.all(Radius.circular(sm));
  static const BorderRadius baseAll = BorderRadius.all(Radius.circular(base));
  static const BorderRadius mdAll = BorderRadius.all(Radius.circular(md));
  static const BorderRadius lgAll = BorderRadius.all(Radius.circular(lg));
  static const BorderRadius xlAll = BorderRadius.all(Radius.circular(xl));
  static const BorderRadius xxlAll = BorderRadius.all(Radius.circular(xxl));
  static const BorderRadius pill = BorderRadius.all(Radius.circular(full));
}

/// Responsive breakpoints for the Flutter Web / tablet layouts.
///
/// The window size classes and content widths the large-screen layouts use
/// live in `core/layout/breakpoints.dart`; [mobile] is the same 600dp line.
abstract final class AppBreakpoints {
  static const double mobile = Breakpoints.medium;
  static const double tablet = 900;
  static const double desktop = 1200;

  /// The narrow column the sign-in screens, onboarding and the update gate
  /// keep at every size: short forms read better narrow. Also the legacy cap
  /// a `ZScaffold(constrain: true)` applies on a phone wider than 560dp.
  /// Other pages use `ContentWidth` on tablets.
  static const double maxContentWidth = 560;

  static bool isMobile(BuildContext context) =>
      MediaQuery.sizeOf(context).width < mobile;

  static bool isTablet(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    return width >= mobile && width < desktop;
  }

  static bool isDesktop(BuildContext context) =>
      MediaQuery.sizeOf(context).width >= desktop;

  /// Page margin appropriate to the current width.
  static double pageMargin(BuildContext context) =>
      isMobile(context) ? AppSpacing.marginMobile : AppSpacing.marginTablet;
}

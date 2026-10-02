import 'package:flutter/widgets.dart';

/// Motion tokens: every duration and curve the app animates with, in one
/// place, so the whole app moves the same way.
///
/// The budget is 150–400 ms on decelerating curves — quick enough never to
/// stand between a tap and its result, slow enough to read as motion rather
/// than a flicker. Nothing overshoots: the press spring is critically damped
/// and no curve here passes its end value.
///
/// Ambient loops (an online pulse, a typing indicator, a skeleton shimmer) are
/// the one exception to the budget: they are slow on purpose, so they read as
/// "alive" rather than urgent, and they stop when motion is reduced.
abstract final class Motion {
  // -- Durations --------------------------------------------------------------

  /// Press feedback, chip selection, icon swaps.
  static const Duration fast = Duration(milliseconds: 150);

  /// Fades, toasts, list reveals, tab changes.
  static const Duration medium = Duration(milliseconds: 250);

  /// Page transitions, sheets, counters.
  static const Duration slow = Duration(milliseconds: 320);

  /// The ceiling: success marks and count-ups.
  static const Duration max = Duration(milliseconds: 400);

  /// How far apart staggered siblings start, and how many get a stagger at all
  /// — the rest appear with the last of them, so a long list never makes
  /// anyone wait.
  static const Duration stagger = Duration(milliseconds: 40);
  static const int staggerLimit = 6;

  // -- Curves -----------------------------------------------------------------

  /// The default: a soft ease-out.
  static const Curve standard = Curves.easeOutCubic;

  /// Entrances: a fast start that settles gently (Material's emphasized
  /// decelerate).
  static const Curve emphasized = Cubic(0.05, 0.7, 0.1, 1.0);

  /// Exits: leaves without lingering.
  static const Curve exit = Curves.easeInCubic;

  /// The press spring — critically damped, so it settles without a bounce.
  static final SpringDescription spring = SpringDescription.withDampingRatio(
    mass: 1,
    stiffness: 520,
  );

  // -- Accessibility ----------------------------------------------------------

  /// True when the user asked the system to reduce motion (iOS Reduce Motion,
  /// Android "Remove animations"). Entrances are skipped, loops stop and
  /// changes happen at once.
  static bool reduced(BuildContext context) =>
      MediaQuery.maybeDisableAnimationsOf(context) ?? false;

  /// [duration], or zero when motion is reduced.
  static Duration of(BuildContext context, Duration duration) =>
      reduced(context) ? Duration.zero : duration;

  /// The start delay for the [index]th of a run of staggered siblings.
  static Duration staggerFor(int index) =>
      stagger * (index.clamp(0, staggerLimit));
}

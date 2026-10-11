import 'dart:async';

import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';

import 'breakpoints.dart';

/// Which way up the app may be held.
///
/// Phones stay locked to portrait, as they always have been. A tablet (or a
/// foldable opened out) may also turn to landscape, where the layouts centre
/// themselves and navigation moves to a side rail. The device is judged by
/// its shorter side, so turning a tablet never flips the decision; folding
/// or unfolding a foldable does, and [update] follows it.
abstract final class OrientationPolicy {
  static const _portrait = [
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ];

  /// What was last applied: null before the first call.
  static bool? _landscapeAllowed;

  /// Whether a window of [size] (logical pixels) is on a tablet-sized
  /// screen. An empty size — a view that has not been measured yet — counts
  /// as a phone, which is the safe answer.
  static bool allowsLandscape(Size size) =>
      !size.isEmpty && size.shortestSide >= Breakpoints.medium;

  /// Applies the policy for the app's view as it is now. Cheap to call on
  /// every metrics change: the platform is only told when the answer changes.
  /// Needs the binding (`WidgetsFlutterBinding.ensureInitialized()`).
  static Future<void> update() async {
    final views = WidgetsBinding.instance.platformDispatcher.views;
    final size = views.isEmpty
        ? Size.zero
        : views.first.physicalSize / views.first.devicePixelRatio;
    final landscape = allowsLandscape(size);

    if (landscape == _landscapeAllowed) return;
    _landscapeAllowed = landscape;

    // An empty list hands the choice back to the platform: every
    // orientation the app's manifest and Info.plist allow.
    await SystemChrome.setPreferredOrientations(
      landscape ? const <DeviceOrientation>[] : _portrait,
    );
  }

  /// Forgets what was last applied, so the next [update] always tells the
  /// platform.
  @visibleForTesting
  static void reset() => _landscapeAllowed = null;
}

/// Keeps [OrientationPolicy] in step with the window, for as long as the app
/// runs. Put it at the root, around the app.
///
/// `main` applies the policy before `runApp`, when Android and iOS have often
/// not measured the view yet: an empty window counts as a phone, so a tablet
/// starts locked to portrait. The real size can arrive before anything is
/// listening for metrics changes, so this re-applies the policy as soon as it
/// is mounted and again after the first frame (by when the view has a size),
/// then on every metrics change: a foldable opened out becomes a tablet that
/// may turn, and folded back a phone that may not.
class OrientationPolicyHost extends StatefulWidget {
  const OrientationPolicyHost({super.key, required this.child});

  final Widget child;

  @override
  State<OrientationPolicyHost> createState() => _OrientationPolicyHostState();
}

class _OrientationPolicyHostState extends State<OrientationPolicyHost>
    with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(OrientationPolicy.update());
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) unawaited(OrientationPolicy.update());
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeMetrics() => unawaited(OrientationPolicy.update());

  @override
  Widget build(BuildContext context) => widget.child;
}

import 'package:flutter/material.dart';

import 'motion.dart';

/// Page enter and exit for every route in the app.
///
/// iOS and macOS keep the native slide, edge swipe-back included — that is
/// what people there expect. Everywhere else the new page fades in while
/// sliding a short way from the right, and the page beneath drifts a little to
/// the left: a light parallax that reads as "one level deeper" without a
/// full-width shove. Popping plays it in reverse, a touch quicker.
///
/// Under reduced motion only a quick fade remains (the framework also shortens
/// route animations to a fraction of their length).
abstract final class ZPageTransitions {
  static const PageTransitionsTheme theme = PageTransitionsTheme(
    builders: {
      TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
      TargetPlatform.macOS: CupertinoPageTransitionsBuilder(),
      TargetPlatform.android: ZFadeSlidePageTransitionsBuilder(),
      TargetPlatform.fuchsia: ZFadeSlidePageTransitionsBuilder(),
      TargetPlatform.linux: ZFadeSlidePageTransitionsBuilder(),
      TargetPlatform.windows: ZFadeSlidePageTransitionsBuilder(),
    },
  );
}

/// The fade, slide and parallax transition described on [ZPageTransitions].
class ZFadeSlidePageTransitionsBuilder extends PageTransitionsBuilder {
  const ZFadeSlidePageTransitionsBuilder();

  @override
  Duration get transitionDuration => Motion.slow;

  @override
  Duration get reverseTransitionDuration => const Duration(milliseconds: 260);

  @override
  Widget buildTransitions<T>(
    PageRoute<T> route,
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) {
    if (Motion.reduced(context)) {
      return FadeTransition(opacity: animation, child: child);
    }

    return _FadeSlideParallax(
      animation: animation,
      secondaryAnimation: secondaryAnimation,
      // A full-screen dialog rises instead of arriving from the side.
      vertical: route.fullscreenDialog,
      child: child,
    );
  }
}

class _FadeSlideParallax extends StatefulWidget {
  const _FadeSlideParallax({
    required this.animation,
    required this.secondaryAnimation,
    required this.vertical,
    required this.child,
  });

  final Animation<double> animation;
  final Animation<double> secondaryAnimation;
  final bool vertical;
  final Widget child;

  @override
  State<_FadeSlideParallax> createState() => _FadeSlideParallaxState();
}

class _FadeSlideParallaxState extends State<_FadeSlideParallax> {
  late CurvedAnimation _enter;
  late CurvedAnimation _fade;
  late CurvedAnimation _under;

  @override
  void initState() {
    super.initState();
    _curve();
  }

  @override
  void didUpdateWidget(_FadeSlideParallax oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.animation != widget.animation ||
        oldWidget.secondaryAnimation != widget.secondaryAnimation) {
      _dispose();
      _curve();
    }
  }

  // Each direction decelerates in time: arriving settles gently, leaving
  // starts briskly and eases out.
  void _curve() {
    _enter = CurvedAnimation(
      parent: widget.animation,
      curve: Motion.emphasized,
      reverseCurve: Motion.exit,
    );
    _fade = CurvedAnimation(
      parent: widget.animation,
      curve: const Interval(0.0, 0.65, curve: Curves.easeOut),
      reverseCurve: const Interval(0.3, 1.0, curve: Curves.easeIn),
    );
    _under = CurvedAnimation(
      parent: widget.secondaryAnimation,
      curve: Motion.standard,
      reverseCurve: Motion.exit,
    );
  }

  void _dispose() {
    _enter.dispose();
    _fade.dispose();
    _under.dispose();
  }

  @override
  void dispose() {
    _dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final enterFrom =
        widget.vertical ? const Offset(0, 0.06) : const Offset(0.08, 0);

    return SlideTransition(
      // The page beneath drifts left while another arrives on top of it.
      position: _under.drive(
        Tween<Offset>(begin: Offset.zero, end: const Offset(-0.05, 0)),
      ),
      child: FadeTransition(
        opacity: _fade,
        child: SlideTransition(
          position: _enter.drive(
            Tween<Offset>(begin: enterFrom, end: Offset.zero),
          ),
          child: widget.child,
        ),
      ),
    );
  }
}

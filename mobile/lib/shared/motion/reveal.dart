import 'package:flutter/widgets.dart';

import '../../core/motion/motion.dart';

/// Fades and slides its child into place, once, when it first appears.
///
/// In a lazily built list this is a scroll reveal: items arrive as they scroll
/// into view. Give siblings an [index] and they stagger, capped at
/// [Motion.staggerLimit] so a long list never makes anyone wait. Inside a
/// [RevealScope], a [revealKey] makes an item animate only the first time —
/// scroll back up and it is simply there.
///
/// A single controller covers both the delay and the motion: no timers, and
/// nothing outlives the widget. Under reduced motion the child is shown at
/// rest immediately.
class Reveal extends StatefulWidget {
  const Reveal({
    super.key,
    required this.child,
    this.index = 0,
    this.delay,
    this.offset = const Offset(0, 14),
    this.duration = Motion.medium,
    this.revealKey,
  });

  final Widget child;

  /// Position among staggered siblings; ignored when [delay] is given.
  final int index;
  final Duration? delay;

  /// Where the child starts, relative to where it ends up.
  final Offset offset;
  final Duration duration;

  /// Identifies the item inside a [RevealScope] so it reveals only once.
  final Object? revealKey;

  @override
  State<Reveal> createState() => _RevealState();
}

class _RevealState extends State<Reveal> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _progress;
  bool _started = false;

  @override
  void initState() {
    super.initState();

    final delay = widget.delay ?? Motion.staggerFor(widget.index);
    final total = delay + widget.duration;

    _controller = AnimationController(vsync: this, duration: total);
    _progress = CurvedAnimation(
      parent: _controller,
      curve: Interval(
        total == Duration.zero ? 0 : delay.inMicroseconds / total.inMicroseconds,
        1,
        curve: Motion.emphasized,
      ),
    );
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;

    final key = widget.revealKey;
    final alreadyShown = key != null && !(RevealScope._of(context)?.claim(key) ?? true);

    if (alreadyShown || Motion.reduced(context)) {
      _controller.value = 1;
    } else {
      _controller.forward();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _progress,
      child: AnimatedBuilder(
        animation: _progress,
        builder: (context, child) => Transform.translate(
          offset: widget.offset * (1 - _progress.value),
          child: child,
        ),
        child: widget.child,
      ),
    );
  }
}

/// Remembers which [Reveal]s in one list have already played.
///
/// Put it around a list whose items carry a `revealKey`; it survives rebuilds
/// of the screen, so a refresh or a filter change does not replay every
/// entrance.
class RevealScope extends StatefulWidget {
  const RevealScope({super.key, required this.child});

  final Widget child;

  static _RevealScopeState? _of(BuildContext context) =>
      context.getInheritedWidgetOfExactType<_RevealRegistry>()?.state;

  @override
  State<RevealScope> createState() => _RevealScopeState();
}

class _RevealScopeState extends State<RevealScope> {
  final Set<Object> _shown = {};

  /// True the first time [key] is claimed.
  bool claim(Object key) => _shown.add(key);

  @override
  Widget build(BuildContext context) =>
      _RevealRegistry(state: this, child: widget.child);
}

class _RevealRegistry extends InheritedWidget {
  const _RevealRegistry({required this.state, required super.child});

  final _RevealScopeState state;

  @override
  bool updateShouldNotify(_RevealRegistry oldWidget) => false;
}

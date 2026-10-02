import 'package:flutter/gestures.dart';
import 'package:flutter/physics.dart';
import 'package:flutter/widgets.dart';

import '../../core/motion/motion.dart';

/// Shrinks its child slightly while a finger is on it, then springs back.
///
/// Purely visual. It reads raw pointer events and never joins the gesture
/// arena, so taps, long-presses and scrolls behave exactly as they would
/// without it, and nothing ever waits on the animation. A drag past the touch
/// slop — the start of a scroll — releases it at once, so a list never shows a
/// card stuck mid-press.
class Pressable extends StatefulWidget {
  const Pressable({
    super.key,
    required this.child,
    this.enabled = true,
    this.pressedScale = 0.97,
  });

  final Widget child;

  /// Off for disabled controls: they should not look pressable.
  final bool enabled;

  /// How small the child gets at the bottom of the press. 0.97 suits buttons
  /// and cards; a small icon can go further.
  final double pressedScale;

  @override
  State<Pressable> createState() => _PressableState();
}

class _PressableState extends State<Pressable>
    with SingleTickerProviderStateMixin {
  // 0 is at rest, 1 is fully pressed.
  late final AnimationController _press = AnimationController(
    vsync: this,
    duration: Motion.fast,
  );

  int? _pointer;
  Offset? _downAt;

  @override
  void didUpdateWidget(Pressable oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!widget.enabled && _pointer != null) _release();
  }

  @override
  void dispose() {
    _press.dispose();
    super.dispose();
  }

  void _down(PointerDownEvent event) {
    if (!widget.enabled || _pointer != null || Motion.reduced(context)) return;

    _pointer = event.pointer;
    _downAt = event.position;
    _press.animateTo(1, duration: Motion.fast, curve: Motion.standard);
  }

  void _move(PointerMoveEvent event) {
    final downAt = _downAt;
    if (event.pointer != _pointer || downAt == null) return;

    if ((event.position - downAt).distance > kTouchSlop) _release();
  }

  void _end(PointerEvent event) {
    if (event.pointer == _pointer) _release();
  }

  void _release() {
    _pointer = null;
    _downAt = null;

    // Back from wherever the press got to, on a spring that does not bounce.
    // A spring stops within a tolerance of its target, so it is snapped to
    // exactly rest at the end: an un-pressed control then carries no
    // transform at all, rather than a near-identity one with its own layer.
    _press
        .animateWith(SpringSimulation(Motion.spring, _press.value, 0, 0))
        .whenCompleteOrCancel(() {
      if (mounted && _pointer == null && !_press.isAnimating) _press.value = 0;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Listener(
      onPointerDown: _down,
      onPointerMove: _move,
      onPointerUp: _end,
      onPointerCancel: _end,
      child: ScaleTransition(
        scale: _press.drive(Tween(begin: 1.0, end: widget.pressedScale)),
        child: widget.child,
      ),
    );
  }
}

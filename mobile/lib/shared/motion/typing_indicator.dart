import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';

/// Three dots that rise and brighten in turn — "the other person is typing".
///
/// One controller drives all three, repainting only this widget. Under reduced
/// motion the dots sit still, which still reads as "typing".
class TypingIndicator extends StatefulWidget {
  const TypingIndicator({super.key, this.color, this.dotSize = 7});

  /// Defaults to [AppColors.onSurfaceVariant].
  final Color? color;
  final double dotSize;

  @override
  State<TypingIndicator> createState() => _TypingIndicatorState();
}

class _TypingIndicatorState extends State<TypingIndicator>
    with SingleTickerProviderStateMixin {
  late final AnimationController _loop = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  );

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    if (Motion.reduced(context)) {
      _loop.stop();
    } else if (!_loop.isAnimating) {
      _loop.repeat();
    }
  }

  @override
  void dispose() {
    _loop.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final color = widget.color ?? AppColors.onSurfaceVariant;
    final still = Motion.reduced(context);

    return Semantics(
      label: 'Typing',
      child: RepaintBoundary(
        child: AnimatedBuilder(
          animation: _loop,
          builder: (context, _) => Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              for (var i = 0; i < 3; i++) ...[
                if (i > 0) SizedBox(width: widget.dotSize * 0.55),
                _dot(color, still ? 0.5 : _wave(i)),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// 0 → 1 → 0 over the first 60% of this dot's turn, then rest. Each dot
  /// starts a fifth of a cycle after the one before it.
  double _wave(int index) {
    final t = (_loop.value - index * 0.2) % 1.0;
    final phase = (t / 0.6).clamp(0.0, 1.0);
    return math.sin(math.pi * phase);
  }

  Widget _dot(Color color, double wave) => Transform.translate(
        offset: Offset(0, -widget.dotSize * 0.45 * wave),
        child: Container(
          width: widget.dotSize,
          height: widget.dotSize,
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.35 + 0.65 * wave),
            shape: BoxShape.circle,
          ),
        ),
      );
}

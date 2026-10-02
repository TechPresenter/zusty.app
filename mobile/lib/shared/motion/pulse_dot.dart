import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';

/// The "online" dot, with a soft ring that breathes outward from it.
///
/// The ring is painted in its own layer, so the loop repaints a few pixels and
/// nothing around it. It is ticker-driven, so it pauses by itself whenever the
/// screen is covered, and it is not drawn at all under reduced motion — the
/// dot alone still says "online".
class PulseDot extends StatefulWidget {
  const PulseDot({
    super.key,
    this.size = 10,
    this.color,
    this.borderColor,
    this.borderWidth = 0,
  });

  final double size;

  /// Defaults to [AppColors.online].
  final Color? color;

  /// A ring in the surface colour separates the dot from an avatar beneath.
  final Color? borderColor;
  final double borderWidth;

  @override
  State<PulseDot> createState() => _PulseDotState();
}

class _PulseDotState extends State<PulseDot>
    with SingleTickerProviderStateMixin {
  late final AnimationController _loop = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1800),
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
    final color = widget.color ?? AppColors.online;

    final dot = Container(
      width: widget.size,
      height: widget.size,
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        border: widget.borderWidth > 0
            ? Border.all(
                color: widget.borderColor ?? AppColors.surface,
                width: widget.borderWidth,
              )
            : null,
      ),
    );

    if (Motion.reduced(context)) return dot;

    return RepaintBoundary(
      child: CustomPaint(
        painter: _RingPainter(_loop, color),
        child: dot,
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  _RingPainter(this.progress, this.color) : super(repaint: progress);

  final Animation<double> progress;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    // The ring grows over the first two thirds of the cycle, then rests, so
    // the pulse reads as a calm heartbeat rather than a constant ripple.
    final t = (progress.value / 0.66).clamp(0.0, 1.0);
    if (t >= 1) return;

    final eased = Curves.easeOut.transform(t);
    final radius = size.shortestSide / 2;

    canvas.drawCircle(
      size.center(Offset.zero),
      radius * (1 + 0.9 * eased),
      Paint()..color = color.withValues(alpha: 0.38 * (1 - eased)),
    );
  }

  @override
  bool shouldRepaint(_RingPainter oldDelegate) => oldDelegate.color != color;
}

import 'dart:ui' show PathMetric;

import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';

/// A success mark: the circle settles into place, a soft halo spreads from
/// it, and the check draws itself — about 400 ms in all.
///
/// It plays once when first built, after [delay]. Under reduced motion it
/// appears finished.
class SuccessCheck extends StatefulWidget {
  const SuccessCheck({
    super.key,
    this.size = 88,
    this.color,
    this.checkColor,
    this.delay = Duration.zero,
  });

  final double size;

  /// Defaults to [AppColors.success].
  final Color? color;

  /// Defaults to [AppColors.onPrimary].
  final Color? checkColor;
  final Duration delay;

  @override
  State<SuccessCheck> createState() => _SuccessCheckState();
}

class _SuccessCheckState extends State<SuccessCheck>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: widget.delay + Motion.max,
  );

  bool _started = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;

    if (Motion.reduced(context)) {
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
    final total = _controller.duration!.inMicroseconds;
    final start = total == 0 ? 0.0 : widget.delay.inMicroseconds / total;

    double phase(double from, double to) {
      final a = start + (1 - start) * from;
      final b = start + (1 - start) * to;
      final t = ((_controller.value - a) / (b - a)).clamp(0.0, 1.0);
      return Motion.standard.transform(t);
    }

    return Semantics(
      label: 'Done',
      child: SizedBox.square(
        dimension: widget.size,
        child: RepaintBoundary(
          child: AnimatedBuilder(
            animation: _controller,
            builder: (context, _) => CustomPaint(
              painter: _CheckPainter(
                circle: phase(0, 0.55),
                halo: phase(0.2, 1),
                check: phase(0.38, 1),
                color: widget.color ?? AppColors.success,
                checkColor: widget.checkColor ?? AppColors.onPrimary,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _CheckPainter extends CustomPainter {
  _CheckPainter({
    required this.circle,
    required this.halo,
    required this.check,
    required this.color,
    required this.checkColor,
  });

  final double circle;
  final double halo;
  final double check;
  final Color color;
  final Color checkColor;

  @override
  void paint(Canvas canvas, Size size) {
    final center = size.center(Offset.zero);
    final radius = size.shortestSide / 2 * 0.82;

    if (halo > 0 && halo < 1) {
      canvas.drawCircle(
        center,
        radius * (1 + 0.22 * halo),
        Paint()..color = color.withValues(alpha: 0.22 * (1 - halo)),
      );
    }

    if (circle > 0) {
      canvas.drawCircle(
        center,
        radius * (0.6 + 0.4 * circle),
        Paint()..color = color.withValues(alpha: circle),
      );
    }

    if (check <= 0) return;

    final path = Path()
      ..moveTo(center.dx - radius * 0.42, center.dy + radius * 0.02)
      ..lineTo(center.dx - radius * 0.12, center.dy + radius * 0.32)
      ..lineTo(center.dx + radius * 0.46, center.dy - radius * 0.3);

    final drawn = Path();
    for (final PathMetric metric in path.computeMetrics()) {
      drawn.addPath(metric.extractPath(0, metric.length * check), Offset.zero);
    }

    canvas.drawPath(
      drawn,
      Paint()
        ..color = checkColor
        ..style = PaintingStyle.stroke
        ..strokeWidth = radius * 0.16
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round,
    );
  }

  @override
  bool shouldRepaint(_CheckPainter old) =>
      old.circle != circle || old.halo != halo || old.check != check ||
      old.color != color || old.checkColor != checkColor;
}

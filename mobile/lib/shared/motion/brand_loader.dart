import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';

/// The Zusty loader: an arc in the brand gradient sweeping round a faint
/// track, its length breathing as it turns.
///
/// A loader is feedback, not decoration, so it keeps turning under reduced
/// motion — just without the breathing.
class BrandLoader extends StatefulWidget {
  const BrandLoader({super.key, this.size = 36, this.strokeWidth = 3});

  final double size;
  final double strokeWidth;

  @override
  State<BrandLoader> createState() => _BrandLoaderState();
}

class _BrandLoaderState extends State<BrandLoader>
    with SingleTickerProviderStateMixin {
  late final AnimationController _spin = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1300),
  )..repeat();

  @override
  void dispose() {
    _spin.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final still = Motion.reduced(context);

    return Semantics(
      label: 'Loading',
      child: SizedBox.square(
        dimension: widget.size,
        child: RepaintBoundary(
          child: CustomPaint(
            painter: _ArcPainter(
              progress: _spin,
              breathe: !still,
              strokeWidth: widget.strokeWidth,
              start: AppColors.primary,
              end: AppColors.secondary,
              track: AppColors.primary.withValues(alpha: 0.12),
            ),
          ),
        ),
      ),
    );
  }
}

class _ArcPainter extends CustomPainter {
  _ArcPainter({
    required this.progress,
    required this.breathe,
    required this.strokeWidth,
    required this.start,
    required this.end,
    required this.track,
  }) : super(repaint: progress);

  final Animation<double> progress;
  final bool breathe;
  final double strokeWidth;
  final Color start;
  final Color end;
  final Color track;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = (Offset.zero & size).deflate(strokeWidth / 2);
    final t = progress.value;

    canvas.drawArc(
      rect,
      0,
      math.pi * 2,
      false,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = strokeWidth
        ..color = track,
    );

    // The arc grows from a quarter to three quarters of a turn and back.
    final sweep = breathe
        ? math.pi * 2 * (0.25 + 0.5 * (0.5 - 0.5 * math.cos(t * math.pi * 2)))
        : math.pi * 1.2;
    final rotation = t * math.pi * 2;

    canvas.save();
    canvas.translate(size.width / 2, size.height / 2);
    canvas.rotate(rotation - math.pi / 2);
    canvas.translate(-size.width / 2, -size.height / 2);

    canvas.drawArc(
      rect,
      0,
      sweep,
      false,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = strokeWidth
        ..strokeCap = StrokeCap.round
        ..shader = SweepGradient(
          colors: [start, end],
          stops: const [0.0, 1.0],
          endAngle: sweep,
        ).createShader(rect),
    );

    canvas.restore();
  }

  @override
  bool shouldRepaint(_ArcPainter old) =>
      old.breathe != breathe || old.strokeWidth != strokeWidth ||
      old.start != start || old.end != end || old.track != track;
}

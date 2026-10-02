import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';

/// An icon that switches between two states with a little ceremony when it
/// turns on: a single gentle pop (no wobble) and a faint ring that spreads and
/// fades. Turning off is a quiet cross-fade.
///
/// For favourite hearts, bookmarks and follow ticks. It only draws — the tap
/// and the state stay with the caller, so wrapping an existing control
/// changes nothing about what it does.
class PopToggleIcon extends StatefulWidget {
  const PopToggleIcon({
    super.key,
    required this.active,
    required this.activeIcon,
    required this.inactiveIcon,
    this.size = 20,
    this.activeColor,
    this.inactiveColor,
  });

  final bool active;
  final IconData activeIcon;
  final IconData inactiveIcon;
  final double size;

  /// Defaults to [AppColors.primary].
  final Color? activeColor;

  /// Defaults to [AppColors.onSurfaceVariant].
  final Color? inactiveColor;

  @override
  State<PopToggleIcon> createState() => _PopToggleIconState();
}

class _PopToggleIconState extends State<PopToggleIcon>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pop = AnimationController(
    vsync: this,
    duration: Motion.slow,
  );

  // Up to 1.18 and back, each half on an ease-out: one pop, no oscillation.
  static final Animatable<double> _scale = TweenSequence<double>([
    TweenSequenceItem(
      tween: Tween(begin: 1.0, end: 1.18).chain(CurveTween(curve: Curves.easeOut)),
      weight: 40,
    ),
    TweenSequenceItem(
      tween: Tween(begin: 1.18, end: 1.0).chain(CurveTween(curve: Motion.standard)),
      weight: 60,
    ),
  ]);

  @override
  void didUpdateWidget(PopToggleIcon oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (!oldWidget.active && widget.active && !Motion.reduced(context)) {
      _pop.forward(from: 0);
    }
  }

  @override
  void dispose() {
    _pop.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final activeColor = widget.activeColor ?? AppColors.primary;
    final inactiveColor = widget.inactiveColor ?? AppColors.onSurfaceVariant;

    final icon = AnimatedSwitcher(
      duration: Motion.of(context, Motion.fast),
      switchInCurve: Motion.standard,
      switchOutCurve: Motion.exit,
      transitionBuilder: (child, animation) => FadeTransition(
        opacity: animation,
        child: ScaleTransition(
          scale: Tween(begin: 0.8, end: 1.0).animate(animation),
          child: child,
        ),
      ),
      child: Icon(
        widget.active ? widget.activeIcon : widget.inactiveIcon,
        key: ValueKey(widget.active),
        size: widget.size,
        color: widget.active ? activeColor : inactiveColor,
      ),
    );

    return SizedBox.square(
      dimension: widget.size,
      child: CustomPaint(
        painter: _BurstPainter(_pop, activeColor),
        child: ScaleTransition(scale: _pop.drive(_scale), child: icon),
      ),
    );
  }
}

/// The favourite heart, preset.
class AnimatedFavoriteIcon extends StatelessWidget {
  const AnimatedFavoriteIcon({
    super.key,
    required this.isFavorite,
    this.size = 20,
    this.activeColor,
    this.inactiveColor,
  });

  final bool isFavorite;
  final double size;
  final Color? activeColor;
  final Color? inactiveColor;

  @override
  Widget build(BuildContext context) => PopToggleIcon(
        active: isFavorite,
        activeIcon: Icons.favorite_rounded,
        inactiveIcon: Icons.favorite_border_rounded,
        size: size,
        activeColor: activeColor,
        inactiveColor: inactiveColor,
      );
}

class _BurstPainter extends CustomPainter {
  _BurstPainter(this.progress, this.color) : super(repaint: progress);

  final Animation<double> progress;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final t = progress.value;
    if (t <= 0 || t >= 1) return;

    final eased = Motion.standard.transform(t);
    final radius = size.shortestSide * (0.35 + 0.45 * eased);

    canvas.drawCircle(
      size.center(Offset.zero),
      radius,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2 * (1 - eased) + 0.5
        ..color = color.withValues(alpha: 0.45 * (1 - eased)),
    );
  }

  @override
  bool shouldRepaint(_BurstPainter oldDelegate) => oldDelegate.color != color;
}

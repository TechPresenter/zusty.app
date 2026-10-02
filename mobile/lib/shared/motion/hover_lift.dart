import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';

/// Raises its child a couple of pixels, with a deeper shadow, while a mouse
/// or trackpad pointer rests on it — the hover half of a card's elevation
/// (the press half is [Pressable]).
///
/// Touch screens never hover, so on a phone this does nothing and costs
/// nothing.
class HoverLift extends StatefulWidget {
  const HoverLift({
    super.key,
    required this.child,
    required this.borderRadius,
    this.enabled = true,
  });

  final Widget child;

  /// The child's shape, so the lifted shadow follows it.
  final BorderRadius borderRadius;
  final bool enabled;

  @override
  State<HoverLift> createState() => _HoverLiftState();
}

class _HoverLiftState extends State<HoverLift> {
  bool _hovered = false;

  void _hover(bool value) {
    if (_hovered != value) setState(() => _hovered = value);
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.enabled) return widget.child;

    final lifted = _hovered && !Motion.reduced(context);

    return MouseRegion(
      onEnter: (_) => _hover(true),
      onExit: (_) => _hover(false),
      child: AnimatedContainer(
        duration: Motion.medium,
        curve: Motion.standard,
        transform: Matrix4.translationValues(0, lifted ? -2 : 0, 0),
        decoration: BoxDecoration(
          borderRadius: widget.borderRadius,
          boxShadow: lifted ? AppShadows.raised : const [],
        ),
        child: widget.child,
      ),
    );
  }
}

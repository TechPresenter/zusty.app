import 'package:flutter/widgets.dart';

import '../../core/motion/motion.dart';

/// Holds the bottom-navigation tabs and fades between them.
///
/// Every tab stays mounted, exactly as in an `IndexedStack`, so each keeps
/// its scroll position, its stack of pages and its state. Switching tabs is
/// a quick fade-through: the old tab fades out and the new one fades in while
/// settling from 98% scale. Hidden tabs are offstage with their tickers off,
/// so they cost nothing while you are elsewhere.
///
/// Every child keeps the same wrapper widgets at all times — only their
/// settings change — so a tab is never rebuilt or reparented by a switch.
class BranchFadeContainer extends StatefulWidget {
  const BranchFadeContainer({
    super.key,
    required this.currentIndex,
    required this.children,
  });

  final int currentIndex;
  final List<Widget> children;

  @override
  State<BranchFadeContainer> createState() => _BranchFadeContainerState();
}

class _BranchFadeContainerState extends State<BranchFadeContainer>
    with SingleTickerProviderStateMixin {
  late final AnimationController _switch = AnimationController(
    vsync: this,
    duration: Motion.medium,
    value: 1,
  );

  int? _previous;
  int _generation = 0;

  // Fade-through: out over the first 40%, in over the rest.
  late final Animation<double> _fadeOut = CurvedAnimation(
    parent: _switch,
    curve: const Interval(0.0, 0.4, curve: Curves.easeIn),
  ).drive(Tween(begin: 1.0, end: 0.0));

  late final Animation<double> _fadeIn = CurvedAnimation(
    parent: _switch,
    curve: const Interval(0.3, 1.0, curve: Curves.easeOut),
  );

  late final Animation<double> _settle = CurvedAnimation(
    parent: _switch,
    curve: const Interval(0.3, 1.0, curve: Motion.emphasized),
  ).drive(Tween(begin: 0.98, end: 1.0));

  @override
  void didUpdateWidget(BranchFadeContainer oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.currentIndex == widget.currentIndex) return;

    if (Motion.reduced(context)) {
      _previous = null;
      _switch.value = 1;
      return;
    }

    _previous = oldWidget.currentIndex;

    // A switch made mid-fade cancels the one before; only the latest may
    // clear the leaving tab.
    final generation = ++_generation;
    _switch.forward(from: 0).whenCompleteOrCancel(() {
      if (mounted && generation == _generation) setState(() => _previous = null);
    });
  }

  @override
  void dispose() {
    _switch.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        for (var i = 0; i < widget.children.length; i++) _branch(i),
      ],
    );
  }

  Widget _branch(int index) {
    final current = index == widget.currentIndex;
    final leaving = index == _previous;
    final visible = current || leaving;

    final Animation<double> opacity = current
        ? _fadeIn
        : leaving
            ? _fadeOut
            : const AlwaysStoppedAnimation(1);
    final Animation<double> scale =
        current ? _settle : const AlwaysStoppedAnimation(1);

    return Offstage(
      offstage: !visible,
      child: TickerMode(
        enabled: visible,
        child: IgnorePointer(
          ignoring: !current,
          child: FadeTransition(
            opacity: opacity,
            child: ScaleTransition(scale: scale, child: widget.children[index]),
          ),
        ),
      ),
    );
  }
}

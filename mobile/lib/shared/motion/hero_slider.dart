import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';

/// Builds one slide. [offset] reports how far the slide sits from the centre
/// of the viewport, in pages — 0 for the current slide, ±1 for its neighbours.
/// Listen to it from an inner image (see [HeroParallax]) to give the slide
/// depth; the slide itself is built once, not on every frame of a swipe.
typedef HeroSlideBuilder = Widget Function(
  BuildContext context,
  int index,
  ValueListenable<double> offset,
);

/// An auto-playing hero carousel.
///
/// Swipe between slides, or let it advance on its own every [interval]. The
/// slides beside the current one sit slightly faded and scaled down and settle
/// as they arrive, and the dots stretch to mark the active slide. It loops:
/// after the last slide comes the first, moving forward, never rewinding
/// across the whole set.
///
/// Auto-play waits while a finger is down and restarts after a swipe. It runs
/// on a ticker rather than a timer, so it pauses by itself whenever the screen
/// is covered, and it is off entirely under reduced motion.
class ZHeroSlider extends StatefulWidget {
  const ZHeroSlider({
    super.key,
    required this.itemCount,
    required this.itemBuilder,
    this.height = 172,
    this.viewportFraction = 0.96,
    this.leadingInset = 0,
    this.gap = AppSpacing.xs,
    this.interval = const Duration(seconds: 5),
    this.autoPlay = true,
    this.onPageChanged,
  });

  final int itemCount;
  final HeroSlideBuilder itemBuilder;
  final double height;
  final double viewportFraction;

  /// Space before the first slide, to line it up with the page margin.
  final double leadingInset;

  /// Space between slides.
  final double gap;
  final Duration interval;
  final bool autoPlay;
  final ValueChanged<int>? onPageChanged;

  @override
  State<ZHeroSlider> createState() => _ZHeroSliderState();
}

class _ZHeroSliderState extends State<ZHeroSlider>
    with SingleTickerProviderStateMixin {
  /// Pages before the start, so the loop can run either way without an end.
  static const _loopSpan = 10000;

  late PageController _pages;
  late final AnimationController _clock = AnimationController(
    vsync: this,
    duration: widget.interval,
  )..addStatusListener(_onTick);

  late int _origin = _originFor(widget.itemCount);
  int _active = 0;
  bool _held = false;

  bool get _loops => widget.itemCount > 1;

  static int _originFor(int count) =>
      count > 1 ? count * (_loopSpan ~/ count) : 0;

  @override
  void initState() {
    super.initState();
    _pages = PageController(
      viewportFraction: widget.viewportFraction,
      initialPage: _origin,
    );
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _restartClock();
  }

  @override
  void didUpdateWidget(ZHeroSlider oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.interval != widget.interval) _clock.duration = widget.interval;

    // A refreshed feed with a different number of slides starts over.
    if (oldWidget.itemCount != widget.itemCount) {
      _origin = _originFor(widget.itemCount);
      _active = 0;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && _pages.hasClients) _pages.jumpToPage(_origin);
      });
    }

    _restartClock();
  }

  @override
  void dispose() {
    _clock.dispose();
    _pages.dispose();
    super.dispose();
  }

  void _restartClock() {
    final canPlay =
        widget.autoPlay && _loops && !_held && !Motion.reduced(context);

    if (canPlay) {
      _clock.forward(from: 0);
    } else {
      _clock.stop();
    }
  }

  void _onTick(AnimationStatus status) {
    if (status == AnimationStatus.completed && mounted && _pages.hasClients) {
      _pages.nextPage(duration: Motion.max, curve: Motion.emphasized);
    }
  }

  void _onPageChanged(int page) {
    final index = _indexOf(page);
    setState(() => _active = index);
    widget.onPageChanged?.call(index);
    _restartClock();
  }

  int _indexOf(int page) => _loops ? (page - _origin) % widget.itemCount : page;

  bool _onScroll(ScrollNotification notification) {
    if (notification is ScrollStartNotification &&
        notification.dragDetails != null) {
      _held = true;
      _clock.stop();
    } else if (notification is ScrollEndNotification && _held) {
      _held = false;
      _restartClock();
    }
    return false;
  }

  @override
  Widget build(BuildContext context) {
    final reduced = Motion.reduced(context);

    return Column(
      children: [
        SizedBox(
          height: widget.height,
          child: NotificationListener<ScrollNotification>(
            onNotification: _onScroll,
            child: PageView.builder(
              controller: _pages,
              padEnds: false,
              itemCount: _loops ? null : widget.itemCount,
              onPageChanged: _onPageChanged,
              itemBuilder: (context, page) => Padding(
                padding: EdgeInsets.only(
                  left: page == _origin ? widget.leadingInset : widget.gap,
                  right: widget.gap,
                ),
                child: _Slide(
                  pages: _pages,
                  page: page,
                  restingPage: _origin + _active,
                  still: reduced,
                  builder: (context, offset) =>
                      widget.itemBuilder(context, _indexOf(page), offset),
                ),
              ),
            ),
          ),
        ),
        if (_loops) ...[
          const SizedBox(height: AppSpacing.xs),
          _Dots(count: widget.itemCount, active: _active),
        ],
      ],
    );
  }
}

/// One slide: built once, then faded and scaled per frame by how far it sits
/// from the centre.
class _Slide extends StatefulWidget {
  const _Slide({
    required this.pages,
    required this.page,
    required this.restingPage,
    required this.still,
    required this.builder,
  });

  final PageController pages;
  final int page;

  /// Where the carousel rests before the controller has a position.
  final int restingPage;
  final bool still;
  final Widget Function(BuildContext context, ValueListenable<double> offset)
      builder;

  @override
  State<_Slide> createState() => _SlideState();
}

class _SlideState extends State<_Slide> {
  late _SlideOffset _offset = _SlideOffset(widget.pages, widget.page, widget.restingPage);

  @override
  void didUpdateWidget(_Slide oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.pages != widget.pages || oldWidget.page != widget.page) {
      _offset.dispose();
      _offset = _SlideOffset(widget.pages, widget.page, widget.restingPage);
    } else {
      _offset.restingPage = widget.restingPage;
    }
  }

  @override
  void dispose() {
    _offset.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final slide = widget.builder(context, _offset);
    if (widget.still) return slide;

    return AnimatedBuilder(
      animation: _offset,
      child: slide,
      builder: (context, child) {
        final distance = _offset.value.abs().clamp(0.0, 1.0);
        return Opacity(
          opacity: 1 - 0.3 * distance,
          child: Transform.scale(scale: 1 - 0.04 * distance, child: child),
        );
      },
    );
  }
}

/// A slide's distance from the viewport centre, in pages, kept in step with
/// the page controller.
class _SlideOffset extends ChangeNotifier implements ValueListenable<double> {
  _SlideOffset(this._pages, this._page, this.restingPage) {
    _pages.addListener(notifyListeners);
  }

  final PageController _pages;
  final int _page;
  int restingPage;

  @override
  double get value {
    if (!_pages.hasClients || !_pages.position.haveDimensions) {
      return (_page - restingPage).toDouble();
    }
    return _page - (_pages.page ?? restingPage.toDouble());
  }

  @override
  void dispose() {
    _pages.removeListener(notifyListeners);
    super.dispose();
  }
}

/// Moves its child against a slide's [offset] for a gentle parallax — put it
/// around the image inside a hero slide.
class HeroParallax extends StatelessWidget {
  const HeroParallax({
    super.key,
    required this.offset,
    required this.child,
    this.depth = 28,
  });

  final ValueListenable<double> offset;
  final Widget child;

  /// How far, in logical pixels, the child shifts when its slide is one page
  /// away from the centre.
  final double depth;

  @override
  Widget build(BuildContext context) {
    if (Motion.reduced(context)) return child;

    return ValueListenableBuilder<double>(
      valueListenable: offset,
      child: child,
      builder: (context, value, child) => Transform.translate(
        offset: Offset(-value.clamp(-1.0, 1.0) * depth, 0),
        // Slightly enlarged so the shifted image never shows an edge.
        child: Transform.scale(scale: 1 + depth / 300, child: child),
      ),
    );
  }
}

class _Dots extends StatelessWidget {
  const _Dots({required this.count, required this.active});

  final int count;
  final int active;

  @override
  Widget build(BuildContext context) {
    final duration = Motion.of(context, Motion.medium);

    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: List.generate(count, (i) {
        final selected = i == active;
        return AnimatedContainer(
          duration: duration,
          curve: Motion.standard,
          margin: const EdgeInsets.symmetric(horizontal: 3),
          width: selected ? 18 : 6,
          height: 6,
          decoration: BoxDecoration(
            color: selected ? AppColors.primary : AppColors.primaryFixedDim,
            borderRadius: AppRadius.pill,
          ),
        );
      }),
    );
  }
}

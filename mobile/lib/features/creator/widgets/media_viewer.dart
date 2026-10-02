import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../models/creator.dart';
import '../../../shared/motion/motion.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_image.dart';
import 'profile_layout.dart';

/// Full-screen, swipeable viewer for portfolio media.
///
/// Edge to edge on black: pinch to zoom, swipe sideways between items, drag
/// down (or tap close) to dismiss, tap once to hide the chrome and look at the
/// picture alone. The caption panel carries the title, caption and the
/// item's real numbers.
///
/// There is no video player in the app, so a reel or video shows its poster
/// frame with a "Watch on …" action that opens the original post — the honest
/// way to play it, rather than a play button that does nothing.
class MediaViewer extends StatefulWidget {
  const MediaViewer({super.key, required this.items, this.initialIndex = 0});

  final List<PortfolioItem> items;
  final int initialIndex;

  static Future<void> open(
    BuildContext context, {
    required List<PortfolioItem> items,
    int initialIndex = 0,
  }) {
    if (items.isEmpty) return Future.value();

    return Navigator.of(context, rootNavigator: true).push(
      PageRouteBuilder(
        // Not opaque, and no barrier: the black is the viewer's own
        // background, which thins as the user drags down to dismiss so the
        // screen they came from shows through.
        opaque: false,
        transitionDuration: Motion.of(context, Motion.medium),
        reverseTransitionDuration: Motion.of(context, Motion.fast),
        pageBuilder: (_, _, _) => MediaViewer(
          items: items,
          initialIndex: initialIndex.clamp(0, items.length - 1),
        ),
        transitionsBuilder: (_, animation, _, child) => FadeTransition(
          opacity: CurvedAnimation(parent: animation, curve: Motion.standard),
          child: child,
        ),
      ),
    );
  }

  @override
  State<MediaViewer> createState() => _MediaViewerState();
}

class _MediaViewerState extends State<MediaViewer> {
  late final PageController _controller = PageController(
    initialPage: widget.initialIndex,
  );
  late int _index = widget.initialIndex;

  /// Hidden by a single tap so the picture can be seen without the chrome.
  bool _chrome = true;

  /// Vertical drag-to-dismiss, only while the current page is not zoomed.
  double _drag = 0;
  bool _zoomed = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _close() => Navigator.of(context).maybePop();

  @override
  Widget build(BuildContext context) {
    final item = widget.items[_index];
    final dismissProgress = (_drag.abs() / 300).clamp(0.0, 1.0);

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        backgroundColor: Colors.black.withValues(
          alpha: 1 - dismissProgress * 0.7,
        ),
        body: Stack(
          fit: StackFit.expand,
          children: [
            GestureDetector(
              onTap: () => setState(() => _chrome = !_chrome),
              onVerticalDragUpdate: _zoomed
                  ? null
                  : (details) => setState(() => _drag += details.delta.dy),
              onVerticalDragEnd: _zoomed
                  ? null
                  : (details) {
                      final fling = (details.primaryVelocity ?? 0).abs() > 700;
                      if (_drag.abs() > 120 || fling) {
                        _close();
                      } else {
                        setState(() => _drag = 0);
                      }
                    },
              child: Transform.translate(
                offset: Offset(0, _drag),
                child: PageView.builder(
                  controller: _controller,
                  itemCount: widget.items.length,
                  physics: _zoomed
                      ? const NeverScrollableScrollPhysics()
                      : const PageScrollPhysics(),
                  onPageChanged: (i) => setState(() {
                    _index = i;
                    _zoomed = false;
                  }),
                  itemBuilder: (_, i) => _ZoomablePage(
                    item: widget.items[i],
                    onZoomChanged: (zoomed) {
                      if (zoomed != _zoomed) setState(() => _zoomed = zoomed);
                    },
                  ),
                ),
              ),
            ),
            if (item.isVideo)
              IgnorePointer(
                child: Center(
                  child: Container(
                    width: 72,
                    height: 72,
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: 0.35),
                      shape: BoxShape.circle,
                      border: Border.all(color: Colors.white54, width: 1.5),
                    ),
                    child: const Icon(
                      Icons.play_arrow_rounded,
                      size: 44,
                      color: Colors.white,
                    ),
                  ),
                ),
              ),
            AnimatedOpacity(
              opacity: _chrome && _drag == 0 ? 1 : 0,
              duration: Motion.of(context, Motion.fast),
              child: IgnorePointer(
                ignoring: !_chrome,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    _TopBar(
                      index: _index,
                      count: widget.items.length,
                      onClose: _close,
                    ),
                    Align(
                      alignment: Alignment.bottomCenter,
                      child: _CaptionPanel(
                        item: item,
                        index: _index,
                        count: widget.items.length,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// One page: the full asset, pinch-zoomable, reporting whether it is zoomed
/// so the pager and drag-to-dismiss stand aside while the user pans.
class _ZoomablePage extends StatefulWidget {
  const _ZoomablePage({required this.item, required this.onZoomChanged});

  final PortfolioItem item;
  final ValueChanged<bool> onZoomChanged;

  @override
  State<_ZoomablePage> createState() => _ZoomablePageState();
}

class _ZoomablePageState extends State<_ZoomablePage> {
  final TransformationController _transform = TransformationController();

  @override
  void initState() {
    super.initState();
    _transform.addListener(_report);
  }

  @override
  void dispose() {
    _transform
      ..removeListener(_report)
      ..dispose();
    super.dispose();
  }

  void _report() =>
      widget.onZoomChanged(_transform.value.getMaxScaleOnAxis() > 1.01);

  @override
  Widget build(BuildContext context) {
    final item = widget.item;

    return InteractiveViewer(
      transformationController: _transform,
      maxScale: 4,
      child: Center(
        child: AspectRatio(
          aspectRatio: item.aspectRatio,
          child: ZImage(
            url: item.mediaUrl ?? item.thumbnailUrl,
            fit: BoxFit.contain,
            fallbackIcon: item.isVideo
                ? Icons.movie_creation_outlined
                : Icons.image_outlined,
          ),
        ),
      ),
    );
  }
}

class _TopBar extends StatelessWidget {
  const _TopBar({
    required this.index,
    required this.count,
    required this.onClose,
  });

  final int index;
  final int count;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.topCenter,
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Colors.black.withValues(alpha: 0.55), Colors.transparent],
          ),
        ),
        child: SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.xs,
              AppSpacing.xs,
              AppSpacing.md,
              AppSpacing.lg,
            ),
            child: Row(
              children: [
                IconButton(
                  onPressed: onClose,
                  tooltip: 'Close',
                  iconSize: 26,
                  style: IconButton.styleFrom(
                    minimumSize: const Size(
                      AppSpacing.minTouch,
                      AppSpacing.minTouch,
                    ),
                  ),
                  icon: const Icon(Icons.close_rounded, color: Colors.white),
                ),
                const Spacer(),
                if (count > 1)
                  Semantics(
                    label: 'Item ${index + 1} of $count',
                    excludeSemantics: true,
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: AppSpacing.sm,
                        vertical: AppSpacing.base,
                      ),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.16),
                        borderRadius: AppRadius.pill,
                      ),
                      child: Text(
                        '${index + 1} / $count',
                        style: AppTypography.labelMedium.copyWith(
                          color: Colors.white,
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _CaptionPanel extends StatelessWidget {
  const _CaptionPanel({
    required this.item,
    required this.index,
    required this.count,
  });

  final PortfolioItem item;
  final int index;
  final int count;

  String get _watchLabel {
    final source = item.sourcePlatform;
    if (source == null || source.isEmpty) {
      return item.isVideo ? 'Watch original' : 'Open original';
    }

    final name = titleise(source);
    return item.isVideo ? 'Watch on $name' : 'View on $name';
  }

  @override
  Widget build(BuildContext context) {
    final metrics = <(IconData, String)>[
      if (item.viewsCount > 0) (Icons.visibility_rounded, item.viewsLabel),
      if (item.likesCount > 0) (Icons.favorite_rounded, item.likesLabel),
      if (item.commentsCount > 0)
        (Icons.mode_comment_rounded, Formatters.compact(item.commentsCount)),
      if (item.durationLabel.isNotEmpty)
        (Icons.schedule_rounded, item.durationLabel),
    ];

    final hasText = item.title.isNotEmpty || item.caption.isNotEmpty;
    final external = item.externalUrl;

    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.bottomCenter,
          end: Alignment.topCenter,
          colors: [
            Colors.black.withValues(alpha: 0.82),
            Colors.black.withValues(alpha: 0.45),
            Colors.transparent,
          ],
          stops: const [0, 0.65, 1],
        ),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.md,
            AppSpacing.xxl,
            AppSpacing.md,
            AppSpacing.md,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (item.title.isNotEmpty)
                Text(
                  item.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleSmall.copyWith(
                    color: Colors.white,
                    fontSize: 16,
                  ),
                ),
              if (item.caption.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.base),
                // Scrolls inside a cap rather than covering the picture.
                ConstrainedBox(
                  constraints: const BoxConstraints(maxHeight: 96),
                  child: SingleChildScrollView(
                    child: Text(
                      item.caption,
                      style: AppTypography.bodyMedium.copyWith(
                        color: Colors.white70,
                        height: 1.45,
                      ),
                    ),
                  ),
                ),
              ],
              if (metrics.isNotEmpty || item.publishedAt != null) ...[
                SizedBox(height: hasText ? AppSpacing.sm : 0),
                Wrap(
                  spacing: AppSpacing.md,
                  runSpacing: AppSpacing.xs,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    for (final (icon, label) in metrics)
                      _Metric(icon: icon, label: label),
                    if (item.publishedAt != null)
                      Text(
                        Formatters.date(item.publishedAt),
                        style: AppTypography.labelSmall.copyWith(
                          color: Colors.white60,
                        ),
                      ),
                  ],
                ),
              ],
              if (external != null && external.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.md),
                ZButton(
                  label: _watchLabel,
                  icon: item.isVideo
                      ? Icons.play_circle_outline_rounded
                      : Icons.open_in_new_rounded,
                  variant: ZButtonVariant.secondary,
                  size: ZButtonSize.medium,
                  expand: false,
                  onPressed: () => openExternalLink(context, external),
                ),
              ],
              if (count > 1 && count <= 12) ...[
                const SizedBox(height: AppSpacing.md),
                Center(
                  child: _Dots(index: index, count: count),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 15, color: Colors.white70),
        const SizedBox(width: 4),
        Text(
          label,
          style: AppTypography.labelMedium.copyWith(color: Colors.white),
        ),
      ],
    );
  }
}

/// Where the reader is in a short set; hidden for long ones, where the
/// counter in the top bar says it better.
class _Dots extends StatelessWidget {
  const _Dots({required this.index, required this.count});

  final int index;
  final int count;

  @override
  Widget build(BuildContext context) {
    return ExcludeSemantics(
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var i = 0; i < count; i++)
            AnimatedContainer(
              duration: Motion.of(context, Motion.fast),
              curve: Motion.standard,
              margin: const EdgeInsets.symmetric(horizontal: 3),
              width: i == index ? 18 : 6,
              height: 6,
              decoration: BoxDecoration(
                color: i == index ? Colors.white : Colors.white38,
                borderRadius: AppRadius.pill,
              ),
            ),
        ],
      ),
    );
  }
}

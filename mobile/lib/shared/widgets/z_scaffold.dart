import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import 'adaptive_layout.dart';

export 'adaptive_layout.dart';

/// The app bar used on every non-shell screen: a back chevron, a title, and
/// optional actions, on the warm surface with no elevation.
class ZAppBar extends StatelessWidget implements PreferredSizeWidget {
  const ZAppBar({
    super.key,
    this.title,
    this.actions,
    this.showBack = true,
    this.onBack,
    this.centerTitle = false,
    this.bottom,
    this.backgroundColor,
    this.titleWidget,
  });

  final String? title;
  final List<Widget>? actions;
  final bool showBack;
  final VoidCallback? onBack;
  final bool centerTitle;
  final PreferredSizeWidget? bottom;
  final Color? backgroundColor;
  final Widget? titleWidget;

  @override
  Size get preferredSize =>
      Size.fromHeight(56 + (bottom?.preferredSize.height ?? 0));

  @override
  Widget build(BuildContext context) {
    return AppBar(
      backgroundColor: backgroundColor ?? AppColors.surface,
      centerTitle: centerTitle,
      // Without a back button the title starts at the page margin, in line
      // with the content: 16dp on a phone, 24 on a tablet.
      titleSpacing: showBack ? 0 : AppBreakpoints.pageMargin(context),
      leading: showBack
          ? IconButton(
              icon: const Icon(Icons.arrow_back_ios_new_rounded, size: 20),
              onPressed: onBack ??
                  () => context.canPop()
                      ? context.pop()
                      : context.go('/home'),
              tooltip: 'Back',
            )
          : null,
      title: titleWidget ??
          (title == null
              ? null
              : Text(
                  title!,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleMedium,
                )),
      actions: [
        ...?actions,
        const SizedBox(width: AppSpacing.xs),
      ],
      bottom: bottom,
    );
  }
}

/// Standard page scaffold: warm surface, safe-area aware, and centred in a
/// capped column on a tablet so the mobile-first layouts do not stretch.
///
/// On a medium or expanded window the app bar, the body and the bottom bar
/// all line up in one column [maxContentWidth] wide, with the page background
/// either side (see `ContentWidthBox`). On a phone nothing about the layout
/// changes.
class ZScaffold extends StatelessWidget {
  const ZScaffold({
    super.key,
    required this.body,
    this.appBar,
    this.bottomBar,
    this.floatingActionButton,
    this.backgroundColor,
    this.padded = true,
    this.constrain = true,
    this.resizeToAvoidBottomInset = true,
    this.maxContentWidth = ContentWidth.readable,
  });

  final Widget body;
  final PreferredSizeWidget? appBar;

  /// A pinned action bar at the bottom of the screen (booking actions, "Book
  /// Now", the chat composer).
  final Widget? bottomBar;
  final Widget? floatingActionButton;
  final Color? backgroundColor;

  /// Applies the standard page margin.
  final bool padded;

  /// On a phone wider than 560dp, caps the content at 560 and centres it.
  /// On a tablet it centres a body that does not fill the height, as it
  /// always has; the width there is [maxContentWidth] either way.
  final bool constrain;
  final bool resizeToAvoidBottomInset;

  /// How wide the page's column grows on a tablet: [ContentWidth.readable]
  /// for forms, details and single-column lists, [ContentWidth.wide] for
  /// grids and two-column lists. Ignored on a phone.
  final double maxContentWidth;

  @override
  Widget build(BuildContext context) {
    final large = !WindowSize.of(context).isCompact;
    final bar = appBar;
    Widget content = body;

    if (padded) {
      content = Padding(
        padding: EdgeInsets.symmetric(
          horizontal: AppBreakpoints.pageMargin(context),
        ),
        child: content,
      );
    }

    if (large) {
      content = ContentWidthBox(
        maxWidth: maxContentWidth,
        alignment: constrain ? Alignment.center : Alignment.topCenter,
        child: content,
      );
    } else if (constrain) {
      content = Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(
            maxWidth: AppBreakpoints.maxContentWidth,
          ),
          child: content,
        ),
      );
    }

    return Scaffold(
      backgroundColor: backgroundColor ?? AppColors.surface,
      appBar: bar == null || !large
          ? bar
          : ContentWidthAppBar(
              maxWidth: maxContentWidth,
              // The sides take the bar's colour, so the bar reads as one
              // strip across the screen rather than a block in the middle.
              backgroundColor: bar is ZAppBar
                  ? (bar.backgroundColor ?? AppColors.surface)
                  : (backgroundColor ?? AppColors.surface),
              child: bar,
            ),
      resizeToAvoidBottomInset: resizeToAvoidBottomInset,
      floatingActionButton: floatingActionButton,
      body: SafeArea(top: appBar == null, bottom: false, child: content),
      bottomNavigationBar: bottomBar == null
          ? null
          : large
              ? _BottomActionBar(
                  // In line with the content column, but never wider than a
                  // readable one: a single button across a grid page would
                  // be a 1000dp pill.
                  maxWidth: maxContentWidth < ContentWidth.readable
                      ? maxContentWidth
                      : ContentWidth.readable,
                  margin: AppBreakpoints.pageMargin(context),
                  child: bottomBar!,
                )
              : _BottomActionBar(child: bottomBar!),
    );
  }
}

/// Wraps a pinned bottom bar with the surface treatment and safe-area padding
/// the designs use.
class _BottomActionBar extends StatelessWidget {
  const _BottomActionBar({
    required this.child,
    this.maxWidth = AppBreakpoints.maxContentWidth,
    this.margin = AppSpacing.marginMobile,
  });

  final Widget child;

  /// The widest the bar's content grows; the surface spans the screen.
  final double maxWidth;

  /// Space either side of the content, inside [maxWidth].
  final double margin;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        boxShadow: AppShadows.bottomBar,
      ),
      child: SafeArea(
        top: false,
        // `heightFactor: 1` is load-bearing: Scaffold lays the bottom bar out
        // against the full screen height, so a plain Center would expand to
        // fill it and squeeze the body to nothing. Sizing to the child keeps
        // the bar exactly as tall as its content.
        child: Align(
          alignment: Alignment.center,
          heightFactor: 1,
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: maxWidth),
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                margin,
                AppSpacing.sm,
                margin,
                AppSpacing.sm,
              ),
              child: child,
            ),
          ),
        ),
      ),
    );
  }
}

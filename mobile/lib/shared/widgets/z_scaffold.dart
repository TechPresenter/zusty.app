import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';

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
      titleSpacing: showBack ? 0 : AppSpacing.marginMobile,
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

/// Standard page scaffold: warm surface, safe-area aware, and a content column
/// capped at a readable width on tablet/desktop so the mobile-first layouts do
/// not stretch.
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
  });

  final Widget body;
  final PreferredSizeWidget? appBar;

  /// A pinned action bar at the bottom of the screen (booking actions, "Book
  /// Now", the chat composer).
  final Widget? bottomBar;
  final Widget? floatingActionButton;
  final Color? backgroundColor;

  /// Applies the standard 20px page margin.
  final bool padded;

  /// Caps content width on wide screens.
  final bool constrain;
  final bool resizeToAvoidBottomInset;

  @override
  Widget build(BuildContext context) {
    Widget content = body;

    if (padded) {
      content = Padding(
        padding: EdgeInsets.symmetric(
          horizontal: AppBreakpoints.pageMargin(context),
        ),
        child: content,
      );
    }

    if (constrain) {
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
      appBar: appBar,
      resizeToAvoidBottomInset: resizeToAvoidBottomInset,
      floatingActionButton: floatingActionButton,
      body: SafeArea(top: appBar == null, bottom: false, child: content),
      bottomNavigationBar: bottomBar == null
          ? null
          : _BottomActionBar(child: bottomBar!),
    );
  }
}

/// Wraps a pinned bottom bar with the surface treatment and safe-area padding
/// the designs use.
class _BottomActionBar extends StatelessWidget {
  const _BottomActionBar({required this.child});

  final Widget child;

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
            constraints: const BoxConstraints(
              maxWidth: AppBreakpoints.maxContentWidth,
            ),
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                AppSpacing.marginMobile,
                AppSpacing.sm,
                AppSpacing.marginMobile,
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

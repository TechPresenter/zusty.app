import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/zuste_logo.dart';

/// The frame every sign-in screen shares: warm surface, two soft brand shapes
/// bleeding off the top corner, an optional back chevron, and one scrolling
/// column capped at a readable width.
///
/// The body scrolls as a whole and the scaffold resizes for the keyboard, so
/// whichever field has focus is scrolled clear of it — [ZTextField] already
/// asks for that room through its `scrollPadding`. Dragging the content
/// dismisses the keyboard, the usual way out on a form this short.
class AuthScaffold extends StatelessWidget {
  const AuthScaffold({
    super.key,
    required this.child,
    this.onBack,
    this.bottom,
  });

  final Widget child;

  /// Shows a back chevron when set.
  final VoidCallback? onBack;

  /// Pinned under the scroll view — a sticky primary action.
  final Widget? bottom;

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: Stack(
        children: [
          const Positioned.fill(child: _Backdrop()),
          SafeArea(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(
                  maxWidth: AppBreakpoints.maxContentWidth,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (onBack != null)
                      Padding(
                        padding: EdgeInsets.only(
                          left: margin - AppSpacing.xs,
                          top: AppSpacing.xs,
                        ),
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: IconButton(
                            onPressed: onBack,
                            tooltip: 'Back',
                            icon: const Icon(
                              Icons.arrow_back_ios_new_rounded,
                              size: 20,
                            ),
                          ),
                        ),
                      ),
                    Expanded(
                      child: SingleChildScrollView(
                        keyboardDismissBehavior:
                            ScrollViewKeyboardDismissBehavior.onDrag,
                        padding: EdgeInsets.fromLTRB(
                          margin,
                          onBack == null ? AppSpacing.xl : AppSpacing.xs,
                          margin,
                          AppSpacing.xl,
                        ),
                        child: child,
                      ),
                    ),
                    if (bottom != null)
                      Padding(
                        padding: EdgeInsets.fromLTRB(
                          margin,
                          AppSpacing.xs,
                          margin,
                          AppSpacing.md,
                        ),
                        child: bottom,
                      ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Wordmark, headline and one supporting line — the top of every auth screen.
///
/// [badge] swaps the wordmark for an icon tile on the secondary screens (OTP,
/// reset password), where the brand has already been seen and the icon says
/// more about what this step is.
class AuthHeader extends StatelessWidget {
  const AuthHeader({
    super.key,
    required this.title,
    this.subtitle,
    this.badge,
    this.eyebrow,
  });

  final String title;
  final String? subtitle;
  final IconData? badge;

  /// A small line above the title, e.g. "Step 2 of 2".
  final String? eyebrow;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (badge != null)
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              color: AppColors.primaryFixed,
              borderRadius: AppRadius.lgAll,
            ),
            child: Icon(badge, size: 28, color: AppColors.primary),
          )
        else
          const ZusteLogoLockup(height: 34),
        const SizedBox(height: AppSpacing.lg),
        if (eyebrow != null) ...[
          Text(
            eyebrow!.toUpperCase(),
            style: AppTypography.labelMedium.copyWith(
              color: AppColors.primary,
              letterSpacing: 1.2,
            ),
          ),
          const SizedBox(height: AppSpacing.xs),
        ],
        Text(
          title,
          style: AppTypography.displayLarge.copyWith(fontSize: 28, height: 1.2),
        ),
        if (subtitle != null) ...[
          const SizedBox(height: AppSpacing.xs),
          Text(
            subtitle!,
            style: AppTypography.bodyLarge.copyWith(
              color: AppColors.onSurfaceVariant,
              height: 1.45,
            ),
          ),
        ],
      ],
    );
  }
}

/// "New to Zusty? Create an account" — a prompt and a link that reflow onto
/// two lines at large text sizes instead of overflowing.
class AuthFooterLink extends StatelessWidget {
  const AuthFooterLink({
    super.key,
    required this.prompt,
    required this.action,
    required this.onTap,
  });

  final String prompt;
  final String action;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      alignment: WrapAlignment.center,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text(
          prompt,
          style: AppTypography.bodyMedium.copyWith(
            color: AppColors.onSurfaceVariant,
          ),
        ),
        TextButton(
          onPressed: onTap,
          style: TextButton.styleFrom(
            minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
          ),
          child: Text(
            action,
            style: AppTypography.labelLarge.copyWith(color: AppColors.primary),
          ),
        ),
      ],
    );
  }
}

/// Inline error panel for a whole-form failure ("Incorrect password").
/// Field-specific errors go on the field itself.
class AuthErrorBanner extends StatelessWidget {
  const AuthErrorBanner({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      liveRegion: true,
      child: Container(
        padding: const EdgeInsets.all(AppSpacing.sm),
        decoration: BoxDecoration(
          color: AppColors.errorContainer,
          borderRadius: AppRadius.mdAll,
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              Icons.error_outline_rounded,
              size: 20,
              color: AppColors.onErrorContainer,
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: Text(
                message,
                style: AppTypography.bodyMedium.copyWith(
                  color: AppColors.onErrorContainer,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Two soft brand shapes off the top-right corner: enough colour that the
/// screen is unmistakably Zusty, little enough that it never sits behind a
/// field.
class _Backdrop extends StatelessWidget {
  const _Backdrop();

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;

    return IgnorePointer(
      child: Stack(
        children: [
          Positioned(
            top: -width * 0.32,
            right: -width * 0.28,
            child: Container(
              width: width * 0.78,
              height: width * 0.78,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.primaryFixed.withValues(alpha: 0.7),
              ),
            ),
          ),
          Positioned(
            top: width * 0.18,
            right: -width * 0.12,
            child: Container(
              width: width * 0.30,
              height: width * 0.30,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.secondaryFixed.withValues(alpha: 0.8),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

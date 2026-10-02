import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/motion/motion.dart';
import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../motion/brand_loader.dart';
import '../motion/reveal.dart';
import 'z_button.dart';
import 'z_image.dart';

/// Illustrated empty state — no results, no bookings, no messages.
class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.title,
    this.message,
    this.icon = Icons.inbox_rounded,
    this.actionLabel,
    this.onAction,
    this.compact = false,
  });

  final String title;
  final String? message;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: compact ? AppSpacing.lg : AppSpacing.xl,
        ),
        // Settles into place rather than popping in when a list turns out empty.
        child: Reveal(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Two soft rings around the glyph: an illustration's weight
              // without shipping one per empty state.
              Container(
                width: compact ? 88 : 120,
                height: compact ? 88 : 120,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerLow,
                  shape: BoxShape.circle,
                ),
                child: Container(
                  width: compact ? 60 : 80,
                  height: compact ? 60 : 80,
                  decoration: BoxDecoration(
                    color: AppColors.primaryFixed,
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    icon,
                    size: compact ? 28 : 36,
                    color: AppColors.primary,
                  ),
                ),
              ),
              SizedBox(height: compact ? AppSpacing.md : AppSpacing.lg),
              Text(
                title,
                textAlign: TextAlign.center,
                style: compact
                    ? AppTypography.titleMedium.copyWith(fontSize: 16)
                    : AppTypography.titleMedium,
              ),
              if (message != null) ...[
                const SizedBox(height: AppSpacing.xs),
                Text(
                  message!,
                  textAlign: TextAlign.center,
                  style: AppTypography.bodyMedium.copyWith(
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
              ],
              if (actionLabel != null && onAction != null) ...[
                const SizedBox(height: AppSpacing.lg),
                ZButton(
                  label: actionLabel!,
                  onPressed: onAction,
                  expand: false,
                  size: ZButtonSize.medium,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Failure state with a retry affordance. Shows the backend's own message when
/// the error came from the API.
class ErrorState extends StatelessWidget {
  const ErrorState({super.key, required this.error, this.onRetry});

  final Object error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final message = error is ApiFailure
        ? (error as ApiFailure).message
        : 'Something went wrong. Please try again.';

    final offline = error is OfflineFailure;

    return EmptyState(
      title: offline ? 'You are offline' : 'We hit a snag',
      message: offline
          ? 'Check your connection, then try again. Anything you have opened '
                'before is still here.'
          : message,
      icon: offline ? Icons.wifi_off_rounded : Icons.cloud_off_rounded,
      actionLabel: onRetry == null ? null : 'Try again',
      onAction: onRetry,
    );
  }
}

/// Renders an [AsyncValue] with the app's loading, error, and data states —
/// used by nearly every screen so the three branches stay consistent.
class AsyncView<T> extends StatelessWidget {
  const AsyncView({
    super.key,
    required this.value,
    required this.builder,
    this.loading,
    this.onRetry,
    this.skeletonCount = 4,
  });

  final AsyncValue<T> value;
  final Widget Function(T data) builder;

  /// Custom skeleton; defaults to a stack of shimmer cards.
  final Widget? loading;
  final VoidCallback? onRetry;
  final int skeletonCount;

  @override
  Widget build(BuildContext context) {
    return value.when(
      data: builder,
      loading: () => loading ?? ListSkeleton(count: skeletonCount),
      error: (error, _) => ErrorState(error: error, onRetry: onRetry),
    );
  }
}

/// A vertical stack of shimmer rows — the default list loading state.
class ListSkeleton extends StatelessWidget {
  const ListSkeleton({
    super.key,
    this.count = 4,
    this.height = 88,
    this.padding = const EdgeInsets.symmetric(vertical: AppSpacing.md),
  });

  final int count;
  final double height;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    return ListView.separated(
      padding: padding,
      physics: const NeverScrollableScrollPhysics(),
      shrinkWrap: true,
      itemCount: count,
      separatorBuilder: (_, _) => const SizedBox(height: AppSpacing.sm),
      itemBuilder: (_, _) => _RowSkeleton(height: height),
    );
  }
}

/// A two-column shimmer grid matching the creator card aspect ratio.
class GridSkeleton extends StatelessWidget {
  const GridSkeleton({super.key, this.count = 4});

  final int count;

  @override
  Widget build(BuildContext context) {
    return GridView.builder(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.md),
      physics: const NeverScrollableScrollPhysics(),
      shrinkWrap: true,
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: AppSpacing.sm,
        mainAxisSpacing: AppSpacing.sm,
        childAspectRatio: 0.58,
      ),
      itemCount: count,
      itemBuilder: (_, _) => const _CardSkeleton(),
    );
  }
}

/// A list row's silhouette: thumbnail, a title line and a shorter one.
class _RowSkeleton extends StatelessWidget {
  const _RowSkeleton({required this.height});

  final double height;

  @override
  Widget build(BuildContext context) {
    // Padding shrinks with the row, so a compact skeleton (a chat thread asks
    // for 56) still fits its two text lines.
    final pad = ((height - 30) / 2).clamp(4.0, AppSpacing.md);
    final thumb = (height - pad * 2 - 2).clamp(24.0, 72.0);

    return Container(
      height: height,
      padding: EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: pad),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.xlAll,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: Row(
        children: [
          ZShimmer(width: thumb, height: thumb, borderRadius: AppRadius.lgAll),
          const SizedBox(width: AppSpacing.md),
          const Expanded(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                FractionallySizedBox(
                  widthFactor: 0.7,
                  child: ZShimmer(height: 12, borderRadius: AppRadius.smAll),
                ),
                SizedBox(height: 6),
                FractionallySizedBox(
                  widthFactor: 0.45,
                  child: ZShimmer(height: 10, borderRadius: AppRadius.smAll),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// A grid card's silhouette: a photo and two caption lines.
class _CardSkeleton extends StatelessWidget {
  const _CardSkeleton();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.xs),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.xlAll,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: const Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: ZShimmer(borderRadius: AppRadius.lgAll)),
          SizedBox(height: AppSpacing.sm),
          FractionallySizedBox(
            widthFactor: 0.75,
            child: ZShimmer(height: 14, borderRadius: AppRadius.smAll),
          ),
          SizedBox(height: AppSpacing.xs),
          FractionallySizedBox(
            widthFactor: 0.5,
            child: ZShimmer(height: 12, borderRadius: AppRadius.smAll),
          ),
          SizedBox(height: AppSpacing.xs),
        ],
      ),
    );
  }
}

/// Full-screen centred spinner, for blocking transitions.
class ZLoader extends StatelessWidget {
  const ZLoader({super.key, this.message});

  final String? message;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const BrandLoader(size: 34),
          if (message != null) ...[
            const SizedBox(height: AppSpacing.md),
            Text(
              message!,
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Shows a themed snackbar. Centralised so tone and shape stay consistent.
void showZSnack(
  BuildContext context,
  String message, {
  bool isError = false,
  String? actionLabel,
  VoidCallback? onAction,
}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(
      // A toast slides and fades up on the motion system's timing, and leaves
      // a little quicker than it came; under reduced motion it just appears.
      snackBarAnimationStyle: Motion.reduced(context)
          ? AnimationStyle.noAnimation
          : AnimationStyle(
              duration: Motion.slow,
              reverseDuration: Motion.medium,
              curve: Motion.emphasized,
              reverseCurve: Motion.exit,
            ),
      SnackBar(
        content: Row(
          children: [
            Icon(
              isError
                  ? Icons.error_outline_rounded
                  : Icons.check_circle_rounded,
              size: 20,
              color: isError
                  ? AppColors.errorContainer
                  : AppColors.inversePrimary,
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(child: Text(message)),
          ],
        ),
        backgroundColor: isError
            ? AppColors.onErrorContainer
            : AppColors.inverseSurface,
        action: (actionLabel != null && onAction != null)
            ? SnackBarAction(
                label: actionLabel,
                textColor: AppColors.inversePrimary,
                onPressed: onAction,
              )
            : null,
      ),
    );
}

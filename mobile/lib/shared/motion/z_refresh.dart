import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/theme/app_theme.dart';

/// Pull-to-refresh in the app's colours, with a light haptic tick as the
/// refresh starts — the moment the pull "catches".
///
/// A drop-in for [RefreshIndicator]: same contract, so swapping it in changes
/// how the pull looks and feels and nothing about what it loads. On iOS it
/// shows the native activity spinner.
class ZRefresh extends StatelessWidget {
  const ZRefresh({
    super.key,
    required this.onRefresh,
    required this.child,
    this.edgeOffset = 0,
    this.notificationPredicate = defaultScrollNotificationPredicate,
  });

  final RefreshCallback onRefresh;
  final Widget child;
  final double edgeOffset;
  final ScrollNotificationPredicate notificationPredicate;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator.adaptive(
      onRefresh: () {
        HapticFeedback.lightImpact();
        return onRefresh();
      },
      color: AppColors.primary,
      backgroundColor: AppColors.surfaceContainerLowest,
      strokeWidth: 2.6,
      displacement: 44,
      edgeOffset: edgeOffset,
      notificationPredicate: notificationPredicate,
      child: child,
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme/app_theme.dart';
import '../../models/safety.dart';
import '../../providers/safety_provider.dart';
import 'block_dialogs.dart';
import 'report_sheet.dart';

enum _SafetyAction { report, block, unblock }

/// The ⋮ menu that reports a piece of content and blocks — or unblocks —
/// the account behind it. Put in an app bar's actions, or over a cover photo
/// with [onCover].
///
/// [userId] is the account behind the content; without it the menu only
/// reports. [blocked] is what the screen's own payload says (a profile's
/// `is_blocked`); a block or unblock made this session is picked up as well.
class SafetyMenuButton extends ConsumerWidget {
  const SafetyMenuButton({
    super.key,
    required this.target,
    required this.targetId,
    required this.name,
    this.userId,
    this.blocked = false,
    this.onBlocked,
    this.onUnblocked,
    this.onCover = false,
  });

  final ReportTarget target;
  final int targetId;

  /// Whose content this is, as the menu and the dialogs name them.
  final String name;
  final int? userId;
  final bool blocked;

  /// Runs after a block succeeds: a chat closes itself, a profile reloads.
  final VoidCallback? onBlocked;
  final VoidCallback? onUnblocked;

  /// Draws the near-white disc used over a cover photo instead of a plain
  /// icon.
  final bool onCover;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final userId = this.userId;
    final isBlocked =
        userId != null && (blocked || ref.watch(isBlockedProvider(userId)));

    Future<void> onSelected(_SafetyAction action) async {
      switch (action) {
        case _SafetyAction.report:
          await showReportSheet(
            context,
            target: target,
            id: targetId,
            name: name,
            blockUserId: isBlocked ? null : userId,
          );
        case _SafetyAction.block:
          if (userId == null) return;
          if (await confirmBlock(context, ref, userId: userId, name: name)) {
            onBlocked?.call();
          }
        case _SafetyAction.unblock:
          if (userId == null) return;
          if (await confirmUnblock(context, ref, userId: userId, name: name)) {
            onUnblocked?.call();
          }
      }
    }

    return PopupMenuButton<_SafetyAction>(
      tooltip: 'More options',
      position: PopupMenuPosition.under,
      shape: const RoundedRectangleBorder(borderRadius: AppRadius.lgAll),
      color: AppColors.surfaceContainerLowest,
      onSelected: onSelected,
      icon: onCover ? null : const Icon(Icons.more_vert_rounded, size: 22),
      itemBuilder: (_) => [
        safetyMenuItem(
          _SafetyAction.report,
          Icons.flag_outlined,
          'Report ${target.noun}',
          destructive: true,
        ),
        if (userId != null)
          isBlocked
              ? safetyMenuItem(
                  _SafetyAction.unblock,
                  Icons.lock_open_rounded,
                  'Unblock $name',
                )
              : safetyMenuItem(
                  _SafetyAction.block,
                  Icons.block_rounded,
                  'Block $name',
                  destructive: true,
                ),
      ],
      child: onCover ? const _CoverDisc() : null,
    );
  }
}

/// A row in a report-and-block menu: the glyph, then the label, red for the
/// actions that act against someone.
PopupMenuItem<T> safetyMenuItem<T>(
  T value,
  IconData icon,
  String label, {
  bool destructive = false,
}) {
  final color = destructive ? AppColors.error : AppColors.onSurface;

  return PopupMenuItem<T>(
    value: value,
    height: 48,
    child: Row(
      children: [
        Icon(icon, size: 20, color: color),
        const SizedBox(width: AppSpacing.sm),
        Flexible(
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.bodyMedium.copyWith(color: color),
          ),
        ),
      ],
    ),
  );
}

/// The ⋮ glyph on the same 44dp near-white disc as the cover's other
/// buttons. The menu button around it handles the tap.
class _CoverDisc extends StatelessWidget {
  const _CoverDisc();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: AppSpacing.minTouch,
      height: AppSpacing.minTouch,
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest.withValues(alpha: 0.92),
        shape: BoxShape.circle,
      ),
      child: Icon(Icons.more_vert_rounded, size: 20, color: AppColors.onSurface),
    );
  }
}

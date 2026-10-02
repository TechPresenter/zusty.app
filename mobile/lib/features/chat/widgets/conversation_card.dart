import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/booking.dart';
import '../../../models/message.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_avatar.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_image.dart';

/// One thread on the Messages list.
///
/// Reads top to bottom the way people scan an inbox: who, when, what they
/// said, and — for a booking thread — which booking and where it stands, so a
/// brand juggling several campaigns can tell two threads with the same
/// creator apart without opening either.
class ConversationCard extends StatelessWidget {
  const ConversationCard({super.key, required this.conversation});

  final Conversation conversation;

  @override
  Widget build(BuildContext context) {
    final c = conversation;
    final unread = c.hasUnread;
    final preview = c.lastMessage.isEmpty
        ? (c.isBookingThread
              ? 'Booking chat opened. Say hello.'
              : 'No messages yet')
        : c.lastMessage;

    return ZCard(
      onTap: () => context.push(Routes.chat(c.id)),
      padding: const EdgeInsets.all(AppSpacing.sm),
      // An unread thread carries a faint magenta edge and the card shadow;
      // a read one sits flat, so the eye lands on what needs a reply.
      border: Border.all(
        color: unread
            ? AppColors.primary.withValues(alpha: 0.28)
            : AppColors.outlineVariant,
      ),
      shadow: unread,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ZAvatar(
            imageUrl: c.otherAvatar,
            name: c.otherName,
            size: 52,
            isOnline: c.isOnline,
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // -- Name, verified, muted, time -------------------------
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        c.otherName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.titleSmall.copyWith(
                          fontWeight: unread
                              ? FontWeight.w700
                              : FontWeight.w600,
                        ),
                      ),
                    ),
                    if (c.isVerified) ...[
                      const SizedBox(width: 4),
                      const VerifiedBadge(size: 14),
                    ],
                    if (c.isMuted) ...[
                      const SizedBox(width: 6),
                      Tooltip(
                        message: 'Muted',
                        child: Icon(
                          Icons.notifications_off_outlined,
                          size: 14,
                          color: AppColors.onSurfaceVariant,
                        ),
                      ),
                    ],
                    const SizedBox(width: AppSpacing.xs),
                    const Spacer(),
                    Text(
                      c.timeLabel,
                      style: AppTypography.labelSmall.copyWith(
                        color: unread
                            ? AppColors.primary
                            : AppColors.onSurfaceVariant,
                        fontWeight: unread ? FontWeight.w700 : FontWeight.w500,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),

                // -- Preview and unread count ------------------------------
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text(
                        preview,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodyMedium.copyWith(
                          height: 1.35,
                          color: unread
                              ? AppColors.onSurface
                              : AppColors.onSurfaceVariant,
                          fontWeight: unread
                              ? FontWeight.w600
                              : FontWeight.w400,
                          fontStyle: c.lastMessage.isEmpty
                              ? FontStyle.italic
                              : FontStyle.normal,
                        ),
                      ),
                    ),
                    if (unread) ...[
                      const SizedBox(width: AppSpacing.xs),
                      _UnreadBadge(count: c.unreadCount),
                    ],
                  ],
                ),

                // -- Booking context ---------------------------------------
                if (c.isBookingThread &&
                    (c.bookingLabel != null || c.bookingStatus != null)) ...[
                  const SizedBox(height: AppSpacing.xs),
                  _BookingContext(conversation: c),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _UnreadBadge extends StatelessWidget {
  const _UnreadBadge({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: const BoxConstraints(minWidth: 22, minHeight: 22),
      padding: const EdgeInsets.symmetric(horizontal: 6),
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: AppColors.primary,
        borderRadius: AppRadius.pill,
      ),
      child: Text(
        count > 99 ? '99+' : '$count',
        style: AppTypography.labelSmall.copyWith(
          color: AppColors.onPrimary,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

/// `ZB2400472 · Counter offer` — which booking a thread belongs to, and
/// where that booking stands right now.
class _BookingContext extends StatelessWidget {
  const _BookingContext({required this.conversation});

  final Conversation conversation;

  @override
  Widget build(BuildContext context) {
    final label = conversation.bookingLabel;
    final status = conversation.bookingStatus == null
        ? null
        : BookingStatus.fromString(conversation.bookingStatus);

    return Wrap(
      spacing: 6,
      runSpacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (label != null)
          Container(
            constraints: const BoxConstraints(maxWidth: 200),
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(
              color: AppColors.surfaceContainerLow,
              borderRadius: AppRadius.pill,
              border: Border.all(color: AppColors.outlineVariant),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  Icons.work_outline_rounded,
                  size: 12,
                  color: AppColors.onSurfaceVariant,
                ),
                const SizedBox(width: 4),
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.labelSmall.copyWith(
                      color: AppColors.onSurface,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
            ),
          ),
        if (status != null)
          StatusChip(label: status.label, tone: status.tone, dense: true),
      ],
    );
  }
}

/// Placeholder rows shaped like [ConversationCard], so the list settles into
/// place rather than swapping a grey slab for content.
class ConversationSkeleton extends StatelessWidget {
  const ConversationSkeleton({super.key, this.count = 6});

  final int count;

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);

    return ListView.separated(
      physics: const NeverScrollableScrollPhysics(),
      padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, margin, 0),
      itemCount: count,
      separatorBuilder: (_, _) => const SizedBox(height: AppSpacing.xs),
      itemBuilder: (_, _) => Container(
        padding: const EdgeInsets.all(AppSpacing.sm),
        decoration: BoxDecoration(
          color: AppColors.surfaceContainerLowest,
          borderRadius: AppRadius.xlAll,
          border: Border.all(color: AppColors.outlineVariant),
        ),
        child: const Row(
          children: [
            ZShimmer(
              width: 52,
              height: 52,
              borderRadius: BorderRadius.all(Radius.circular(26)),
            ),
            SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  ZShimmer(
                    width: 140,
                    height: 14,
                    borderRadius: AppRadius.smAll,
                  ),
                  SizedBox(height: AppSpacing.xs),
                  ZShimmer(height: 12, borderRadius: AppRadius.smAll),
                  SizedBox(height: 6),
                  ZShimmer(
                    width: 180,
                    height: 12,
                    borderRadius: AppRadius.smAll,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

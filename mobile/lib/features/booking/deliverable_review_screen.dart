import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/booking.dart';
import '../../providers/booking_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import 'widgets/booking_dialogs.dart';
import 'widgets/booking_ui.dart';

/// Deliverable Review — the brand's approval gate.
///
/// Implements the three branches of `Zuste_Workflow.md` § 5:
///   * **Approve** → releases the held payment, commission is deducted,
///     booking completes
///   * **Request changes** → back to the creator, the payment stays held
///   * **Dispute** → our team reviews it and rules on the held payment
///
/// The copy calls it payment protection, never "escrow" (decision
/// P-ESCROW); `escrow_status` stays the API's field name.
///
/// Approving moves real money, so it is confirmed explicitly and the amount
/// being released is restated at the point of decision.
class DeliverableReviewScreen extends ConsumerWidget {
  const DeliverableReviewScreen({super.key, required this.bookingId});

  final int bookingId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(bookingDetailProvider(bookingId));
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'Review deliverables'),
      bottomBar: detail.maybeWhen(
        data: (data) => _ActionBar(detail: data),
        orElse: () => null,
      ),
      body: ZRefresh(
        onRefresh: () async =>
            ref.refresh(bookingDetailProvider(bookingId).future),
        child: AsyncView<BookingDetail>(
          value: detail,
          onRetry: () => ref.invalidate(bookingDetailProvider(bookingId)),
          loading: ListView(
            physics: const NeverScrollableScrollPhysics(),
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.xs,
              margin,
              AppSpacing.xl,
            ),
            children: const [
              ShimmerBlock(height: 92),
              SizedBox(height: AppSpacing.sm),
              ShimmerBlock(height: 76, borderRadius: AppRadius.lgAll),
              SizedBox(height: AppSpacing.lg),
              ShimmerBlock(height: 280),
            ],
          ),
          builder: (data) {
            final submitted = data.deliverables
                .where((d) => d.status == 'submitted' || d.status == 'approved')
                .length;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: EdgeInsets.fromLTRB(
                margin,
                AppSpacing.xs,
                margin,
                AppSpacing.xl,
              ),
              children: [
                _CreatorHeader(detail: data),
                const SizedBox(height: AppSpacing.sm),
                _EscrowNotice(detail: data),
                PanelHeading(
                  'Submitted work',
                  subtitle: data.deliverables.isEmpty
                      ? null
                      : 'Check each item against the brief before you decide.',
                  trailing: data.deliverables.isEmpty
                      ? null
                      : StatusChip(
                          label: '$submitted of ${data.deliverables.length} in',
                          tone: submitted == data.deliverables.length
                              ? StatusTone.success
                              : StatusTone.neutral,
                          dense: true,
                        ),
                ),
                if (data.deliverables.isEmpty)
                  ZCard(
                    child: const EmptyState(
                      compact: true,
                      icon: Icons.inbox_rounded,
                      title: 'Nothing submitted yet',
                      message:
                          'You will be notified the moment the creator uploads '
                          'their deliverables.',
                    ),
                  )
                else
                  for (var i = 0; i < data.deliverables.length; i++)
                    Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: Reveal(
                        index: i,
                        child: _DeliverableCard(
                          deliverable: data.deliverables[i],
                        ),
                      ),
                    ),
                const SizedBox(height: AppSpacing.xs),
                _BriefReminder(brief: data.brief),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _CreatorHeader extends StatelessWidget {
  const _CreatorHeader({required this.detail});

  final BookingDetail detail;

  @override
  Widget build(BuildContext context) {
    final booking = detail.booking;

    return ZCard(
      child: Row(
        children: [
          BookingAvatar(
            counterparty: booking.counterparty,
            platform: booking.platform,
            size: 48,
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  booking.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodyMedium.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  '${booking.counterparty.name} · ${booking.code}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 110),
            child: StatusChip(
              label: booking.statusLabel,
              tone: booking.tone,
              dense: true,
            ),
          ),
        ],
      ),
    );
  }
}

/// Restates exactly what approving will release, and to whom.
class _EscrowNotice extends StatelessWidget {
  const _EscrowNotice({required this.detail});

  final BookingDetail detail;

  @override
  Widget build(BuildContext context) {
    return ZInfoBanner(
      tone: StatusTone.warning,
      icon: Icons.lock_rounded,
      title: '${detail.amounts.total} held by Zusty',
      message:
          'Approving releases ${detail.amounts.earning} to '
          '${detail.booking.counterparty.name} and closes this booking. '
          'This cannot be undone.',
    );
  }
}

class _DeliverableCard extends StatelessWidget {
  const _DeliverableCard({required this.deliverable});

  final Deliverable deliverable;

  @override
  Widget build(BuildContext context) {
    final (statusLabel, tone) = switch (deliverable.status) {
      'approved' => ('Approved', StatusTone.success),
      'submitted' => ('Submitted', StatusTone.success),
      'revision_requested' => ('Changes asked', StatusTone.warning),
      'rejected' => ('Rejected', StatusTone.error),
      _ => ('Pending', StatusTone.neutral),
    };
    final submitted =
        deliverable.status == 'submitted' || deliverable.status == 'approved';
    final preview = deliverable.mediaUrl ?? deliverable.thumbnailUrl;
    final live = deliverable.liveUrl;

    return ZCard(
      padding: EdgeInsets.zero,
      clip: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (preview != null)
            AspectRatio(
              aspectRatio: 16 / 9,
              child: ZImage(
                url: preview,
                width: double.infinity,
                fallbackIcon: _iconFor(deliverable.type),
              ),
            ),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    TintedIconTile(
                      icon: _iconFor(deliverable.type),
                      color: submitted
                          ? AppColors.success
                          : AppColors.onSurfaceVariant,
                      background: submitted
                          ? AppColors.successContainer
                          : AppColors.surfaceContainerHigh,
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            deliverable.quantity > 1
                                ? '${deliverable.quantity}× ${deliverable.displayName}'
                                : deliverable.displayName,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.bodyMedium.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          if (deliverable.description.isNotEmpty) ...[
                            const SizedBox(height: 2),
                            Text(
                              deliverable.description,
                              maxLines: 3,
                              overflow: TextOverflow.ellipsis,
                              style: AppTypography.bodySmall,
                            ),
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    StatusChip(label: statusLabel, tone: tone, dense: true),
                  ],
                ),
                if (deliverable.metrics.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.sm),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: AppSpacing.sm,
                      vertical: AppSpacing.xs + 2,
                    ),
                    decoration: BoxDecoration(
                      color: AppColors.surfaceContainerLow,
                      borderRadius: AppRadius.mdAll,
                    ),
                    child: Row(
                      children: [
                        for (final entry in deliverable.metrics.entries)
                          Expanded(
                            child: LabeledValue(
                              label: entry.key.isEmpty
                                  ? ''
                                  : entry.key[0].toUpperCase() +
                                        entry.key.substring(1),
                              value: Formatters.compact(
                                entry.value is num
                                    ? entry.value as num
                                    : num.tryParse('${entry.value}') ?? 0,
                              ),
                              emphasise: true,
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
                if (live != null || deliverable.submittedAt != null) ...[
                  const SizedBox(height: AppSpacing.xs),
                  Row(
                    children: [
                      if (deliverable.submittedAt != null)
                        Expanded(
                          child: Text(
                            'Submitted ${Formatters.relative(deliverable.submittedAt)}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.bodySmall,
                          ),
                        )
                      else
                        const Spacer(),
                      if (live != null)
                        LinkButton(
                          label: 'Open post',
                          icon: Icons.open_in_new_rounded,
                          url: live,
                        ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  static IconData _iconFor(String type) => switch (type) {
    'reel' => Icons.movie_creation_outlined,
    'post' => Icons.grid_on_rounded,
    'story' => Icons.amp_stories_rounded,
    'video' => Icons.play_circle_outline_rounded,
    _ => Icons.attachment_rounded,
  };
}

/// The original brief, so the reviewer can check the work against it without
/// leaving the screen.
class _BriefReminder extends StatelessWidget {
  const _BriefReminder({required this.brief});

  final String brief;

  @override
  Widget build(BuildContext context) {
    if (brief.isEmpty) return const SizedBox.shrink();

    return ZCard(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
      color: AppColors.surfaceContainerLow,
      shadow: false,
      border: Border.all(color: AppColors.outlineVariant),
      child: Theme(
        data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
        child: ExpansionTile(
          tilePadding: EdgeInsets.zero,
          childrenPadding: const EdgeInsets.only(bottom: AppSpacing.md),
          shape: const Border(),
          collapsedShape: const Border(),
          iconColor: AppColors.primary,
          collapsedIconColor: AppColors.onSurfaceVariant,
          leading: TintedIconTile(
            icon: Icons.description_outlined,
            size: 36,
            color: AppColors.secondary,
          ),
          title: Text(
            'What you asked for',
            style: AppTypography.bodyMedium.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
          subtitle: Text('The original brief', style: AppTypography.bodySmall),
          children: [
            Align(
              alignment: Alignment.centerLeft,
              child: Text(
                brief,
                style: AppTypography.bodyMedium.copyWith(
                  color: AppColors.onSurfaceVariant,
                  height: 1.5,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The three approval branches.
class _ActionBar extends ConsumerStatefulWidget {
  const _ActionBar({required this.detail});

  final BookingDetail detail;

  @override
  ConsumerState<_ActionBar> createState() => _ActionBarState();
}

class _ActionBarState extends ConsumerState<_ActionBar> {
  bool _busy = false;

  BookingDetail get _detail => widget.detail;

  Future<void> _approve() async {
    final confirmed = await showZConfirm(
      context,
      title: 'Release payment?',
      message:
          '${_detail.amounts.earning} will be released to '
          '${_detail.booking.counterparty.name} and the booking will be '
          'marked complete. This cannot be undone.',
      confirmLabel: 'Approve & release',
      cancelLabel: 'Keep reviewing',
      icon: Icons.lock_open_rounded,
    );

    if (!confirmed) return;
    await _run(
      () => ref.read(bookingActionsProvider).complete(_detail.booking.id),
      'Payment released. Leave a review to close the loop.',
    );
  }

  Future<void> _requestChanges() async {
    final note = await reasonDialog(
      context,
      title: 'Request changes',
      message:
          'The work goes back to the creator. Zusty keeps holding your '
          'payment until you approve.',
      fieldLabel: 'What needs to change?',
      hint: 'Be specific so the creator can fix it in one pass.',
      confirmLabel: 'Send request',
      cancelLabel: 'Cancel',
      destructive: false,
      minLength: 10,
      icon: Icons.edit_note_rounded,
    );
    if (note == null) return;

    await _run(
      () => ref
          .read(bookingActionsProvider)
          .requestRevision(_detail.booking.id, note: note),
      'Sent back to the creator. Your payment stays held.',
    );
  }

  Future<void> _dispute() async {
    final detail = await reasonDialog(
      context,
      title: 'Raise a dispute',
      message:
          'Our team reviews the brief, chat and deliverables before '
          'deciding. Zusty keeps holding the payment until then.',
      fieldLabel: 'What went wrong?',
      hint: 'Tell us what happened, with as much detail as you can.',
      confirmLabel: 'Open dispute',
      cancelLabel: 'Cancel',
      minLength: 10,
      icon: Icons.gavel_rounded,
    );
    if (detail == null) return;

    await _run(
      () => ref
          .read(bookingActionsProvider)
          .dispute(_detail.booking.id, reason: 'deliverables', detail: detail),
      'Dispute opened. Support will be in touch within 24 hours.',
      thenGoToSupport: true,
    );
  }

  Future<void> _run(
    Future<void> Function() action,
    String message, {
    bool thenGoToSupport = false,
  }) async {
    setState(() => _busy = true);
    try {
      await action();
      if (!mounted) return;
      showZSnack(context, message);
      thenGoToSupport ? context.push(Routes.support) : context.pop();
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final actions = _detail.actions;

    // Nothing to decide until the creator has actually delivered.
    if (!actions.canComplete) {
      return ZButton.outlined(
        label: 'Message creator',
        icon: Icons.chat_bubble_outline_rounded,
        onPressed: () => context.push(Routes.messages),
      );
    }

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            Expanded(
              child: ZButton.outlined(
                label: 'Request changes',
                size: ZButtonSize.medium,
                isLoading: _busy,
                onPressed: _requestChanges,
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              flex: 2,
              child: ZButton(
                label: 'Approve & release',
                icon: Icons.check_circle_outline_rounded,
                size: ZButtonSize.medium,
                isLoading: _busy,
                onPressed: _approve,
              ),
            ),
          ],
        ),
        TextButton.icon(
          onPressed: _busy ? null : _dispute,
          style: TextButton.styleFrom(
            minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
          ),
          icon: Icon(Icons.gavel_rounded, size: 16, color: AppColors.error),
          label: Text(
            'Something is wrong — raise a dispute',
            style: AppTypography.labelMedium.copyWith(color: AppColors.error),
          ),
        ),
      ],
    );
  }
}

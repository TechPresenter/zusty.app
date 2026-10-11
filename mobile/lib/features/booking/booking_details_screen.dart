import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/booking.dart';
import '../../models/safety.dart';
import '../../providers/app_config_provider.dart';
import '../../providers/booking_provider.dart';
import '../../providers/core_providers.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/platform_icon.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_timeline.dart';
import '../billing/invoice_share.dart';
import '../safety/report_sheet.dart';
import 'widgets/booking_dialogs.dart';
import 'widgets/booking_ui.dart';

/// Screen 12 — Booking Details.
///
/// Reads top-down in the order a person needs it: where the booking stands
/// and with whom, what to do next, the work, the dates, the brief, the money,
/// and the history. The sticky action bar is driven entirely by the
/// `actions` flags the backend computes, so the rules live in one place.
class BookingDetailsScreen extends ConsumerWidget {
  const BookingDetailsScreen({super.key, required this.bookingId});

  final int bookingId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(bookingDetailProvider(bookingId));

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        title: 'Booking details',
        actions: [
          ...detail.maybeWhen(
            data: (data) => [
              IconButton(
                icon: const Icon(Icons.chat_bubble_outline_rounded, size: 21),
                tooltip: 'Message ${data.booking.counterparty.name}',
                onPressed: () => _openChat(context, ref, data),
              ),
              _MoreMenu(detail: data),
            ],
            orElse: () => const <Widget>[],
          ),
        ],
      ),
      bottomBar: detail.maybeWhen(
        data: (data) => _ActionBar(detail: data),
        orElse: () => null,
      ),
      body: ZRefresh(
        onRefresh: () async =>
            ref.refresh(bookingDetailProvider(bookingId).future),
        child: AsyncView(
          value: detail,
          onRetry: () => ref.invalidate(bookingDetailProvider(bookingId)),
          loading: const _DetailSkeleton(),
          builder: (data) => _Body(detail: data),
        ),
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.detail});

  final BookingDetail detail;

  @override
  Widget build(BuildContext context) {
    final booking = detail.booking;
    final margin = AppBreakpoints.pageMargin(context);
    final banner = _NextStepBanner.forDetail(context, detail);

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: EdgeInsets.fromLTRB(
        margin,
        AppSpacing.xs,
        margin,
        AppSpacing.xl,
      ),
      children: [
        Reveal(child: _SummaryCard(detail: detail)),

        if (banner != null) ...[
          const SizedBox(height: AppSpacing.sm),
          Reveal(index: 1, child: banner),
        ],

        // -- Deliverables -------------------------------------------------
        PanelHeading(
          'Deliverables',
          trailing: detail.deliverables.isEmpty
              ? null
              : _SubmittedCount(deliverables: detail.deliverables),
        ),
        _DeliverablesCard(detail: detail),

        // -- Details and milestones ---------------------------------------
        const PanelHeading('Details & dates'),
        _ScheduleCard(detail: detail),

        // -- Brief ----------------------------------------------------------
        if (detail.brief.isNotEmpty) ...[
          const PanelHeading('Campaign brief'),
          ZCard(child: _ExpandableText(text: detail.brief)),
        ],

        // -- Negotiation ----------------------------------------------------
        if (detail.offers.isNotEmpty) ...[
          PanelHeading(
            'Negotiation',
            subtitle:
                '${detail.offers.length} '
                'offer${detail.offers.length == 1 ? '' : 's'} so far',
          ),
          for (final offer in detail.offers)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: _OfferCard(offer: offer, detail: detail),
            ),
        ],

        // -- Payment summary ------------------------------------------------
        const PanelHeading('Payment summary'),
        _PaymentCard(detail: detail),

        // -- Timeline -------------------------------------------------------
        if (detail.timeline.isNotEmpty) ...[
          const PanelHeading('Status timeline'),
          ZCard(child: ZTimeline(steps: _timelineSteps(detail))),
        ],

        // The booking reference, quietly, for anyone talking to support.
        const SizedBox(height: AppSpacing.lg),
        Center(
          child: Text(
            'Booking ${booking.code} · created ${booking.dateLabel}',
            textAlign: TextAlign.center,
            style: AppTypography.labelSmall.copyWith(
              color: AppColors.onSurfaceVariant,
            ),
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

/// Status, title, and the other party, with the three figures people look
/// for first: the value, the deadline, and when it was requested.
class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.detail});

  final BookingDetail detail;

  @override
  Widget build(BuildContext context) {
    final booking = detail.booking;
    final counterparty = booking.counterparty;
    final handle = counterparty.handle;

    final format = [
      if (booking.campaignType.isNotEmpty) booking.campaignType,
      '${booking.postsCount} post${booking.postsCount == 1 ? '' : 's'}',
    ].join(' · ');

    final role = detail.isBrandView ? 'Creator' : 'Brand';
    final hasProfile = handle != null && handle.isNotEmpty;
    final headline = detail.isBrandView ? detail.counterpartyHeadline : null;

    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.md + 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // spaceBetween, not a Spacer between two Flexibles: those split
          // the row into equal thirds and truncated both the status and the
          // code even when there was room for both.
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Flexible(
                child: StatusChip(
                  label: booking.statusLabel,
                  tone: booking.tone,
                  icon: _statusIcon(booking.status),
                ),
              ),
              const SizedBox(width: AppSpacing.xs),
              Flexible(child: _CopyableCode(code: booking.code)),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            booking.title,
            style: AppTypography.headlineSmall.copyWith(
              fontWeight: FontWeight.w700,
              height: 1.25,
            ),
          ),
          const SizedBox(height: AppSpacing.xs),
          Row(
            children: [
              if (booking.platform.isNotEmpty) ...[
                PlatformIcon(platform: booking.platform, size: 22),
                const SizedBox(width: AppSpacing.xs),
              ],
              Expanded(
                child: Text(
                  format,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          Divider(height: 1, color: AppColors.outlineVariant),
          const SizedBox(height: AppSpacing.md),
          // The whole row opens the creator's profile — a far bigger target
          // than a button squeezed in beside the name.
          Semantics(
            button: hasProfile,
            label: hasProfile ? 'View ${counterparty.name}\'s profile' : null,
            child: InkWell(
              onTap: hasProfile
                  ? () => context.push(Routes.creator(handle))
                  : null,
              borderRadius: AppRadius.mdAll,
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  children: [
                    ZAvatar(
                      imageUrl: counterparty.avatarUrl,
                      name: counterparty.name,
                      size: 48,
                      isVerified: counterparty.isVerified,
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Flexible(
                                child: Text(
                                  counterparty.name,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: AppTypography.titleSmall.copyWith(
                                    fontSize: 15,
                                  ),
                                ),
                              ),
                              if (counterparty.isVerified) ...[
                                const SizedBox(width: 4),
                                const VerifiedBadge(size: 15),
                              ],
                            ],
                          ),
                          const SizedBox(height: 2),
                          Text(
                            headline == null || headline.isEmpty
                                ? role
                                : '$role · $headline',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    if (hasProfile) ...[
                      const SizedBox(width: AppSpacing.xs),
                      ExcludeSemantics(
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(
                              'Profile',
                              style: AppTypography.labelLarge.copyWith(
                                color: AppColors.primary,
                              ),
                            ),
                            Icon(
                              Icons.chevron_right_rounded,
                              size: 20,
                              color: AppColors.primary,
                            ),
                          ],
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(height: AppSpacing.md),
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
                Expanded(
                  child: LabeledValue(
                    label: detail.isBrandView ? 'Total' : 'You earn',
                    value: detail.isBrandView
                        ? _orDash(detail.amounts.total)
                        : _orDash(detail.amounts.earning),
                    emphasise: true,
                    valueColor: AppColors.primary,
                  ),
                ),
                const SizedBox(width: AppSpacing.xs),
                Expanded(
                  child: LabeledValue(
                    label: 'Delivery due',
                    value: booking.deliveryDueAt == null
                        ? 'Not set'
                        : Formatters.date(booking.deliveryDueAt),
                  ),
                ),
                const SizedBox(width: AppSpacing.xs),
                Expanded(
                  child: LabeledValue(
                    label: 'Requested',
                    value: booking.requestedAt == null
                        ? _orDash(booking.dateLabel)
                        : Formatters.date(booking.requestedAt),
                    align: CrossAxisAlignment.end,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The booking reference, tappable to copy — the number support will ask for.
class _CopyableCode extends StatelessWidget {
  const _CopyableCode({required this.code});

  final String code;

  @override
  Widget build(BuildContext context) {
    if (code.isEmpty) return const SizedBox.shrink();

    return Semantics(
      button: true,
      label: 'Booking ID $code. Copy',
      excludeSemantics: true,
      child: InkWell(
        onTap: () => _copyCode(context, code),
        borderRadius: AppRadius.pill,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: AppSpacing.minTouch),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Flexible(
                  child: Text(
                    '#$code',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.labelMedium.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
                  ),
                ),
                const SizedBox(width: 4),
                Icon(
                  Icons.copy_rounded,
                  size: 14,
                  color: AppColors.onSurfaceVariant,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

Future<void> _copyCode(BuildContext context, String code) async {
  await Clipboard.setData(ClipboardData(text: code));
  if (context.mounted) showZSnack(context, 'Booking ID copied.');
}

/// What the booking is waiting on, and who has to act — the line that turns
/// a status label into a next step.
class _NextStepBanner {
  static Widget? forDetail(BuildContext context, BookingDetail detail) {
    final booking = detail.booking;
    final actions = detail.actions;
    final name = booking.counterparty.name;
    final status = booking.status;

    if (status == BookingStatus.disputed) {
      return const ZInfoBanner(
        tone: StatusTone.error,
        icon: Icons.gavel_rounded,
        title: 'Under review by Zusty',
        message:
            'Our team is looking at the brief, chat and deliverables. '
            'Zusty keeps holding the payment until they decide.',
      );
    }

    if (status == BookingStatus.cancelled ||
        status == BookingStatus.rejected ||
        status == BookingStatus.expired) {
      final reason = status == BookingStatus.rejected
          ? detail.rejectionReason
          : detail.cancellationReason;

      return ZInfoBanner(
        tone: StatusTone.neutral,
        icon: Icons.event_busy_rounded,
        title: switch (status) {
          BookingStatus.rejected => 'Request declined',
          BookingStatus.expired => 'Request expired',
          _ => 'Booking cancelled',
        },
        message: reason == null || reason.isEmpty
            ? (status == BookingStatus.expired
                  ? 'The request was not answered in time.'
                  : 'No reason was given.')
            : '“$reason”',
      );
    }

    if (actions.canComplete) {
      return ZInfoBanner(
        tone: StatusTone.warning,
        icon: Icons.rate_review_outlined,
        title: 'Ready for your review',
        message:
            '$name has delivered. Check the work, then approve to '
            'release payment — or ask for changes.',
        actionLabel: 'Review',
        onAction: () => context.push(Routes.reviewDeliverables(booking.id)),
      );
    }

    if (actions.canPay) {
      return ZInfoBanner(
        tone: StatusTone.warning,
        icon: Icons.lock_clock_rounded,
        title: 'Payment needed to confirm',
        message:
            '$name accepted. Once you pay, Zusty holds your payment until '
            'you approve the work.',
      );
    }

    if (actions.canAcceptOffer) {
      final latest = detail.offers.isEmpty ? null : detail.offers.last;
      return ZInfoBanner(
        tone: StatusTone.info,
        icon: Icons.swap_horiz_rounded,
        title: 'New offer on the table',
        message: latest == null
            ? 'Accept the latest offer, or send a counter of your own.'
            : '${latest.amountLabel} is on the table. Accept it, or send a '
                  'counter of your own.',
      );
    }

    if (actions.canAccept) {
      return const ZInfoBanner(
        tone: StatusTone.info,
        icon: Icons.mark_email_unread_outlined,
        title: 'New booking request',
        message: 'Accept to lock it in, or decline or propose a different fee.',
      );
    }

    if (actions.canDeliver) {
      return const ZInfoBanner(
        tone: StatusTone.info,
        icon: Icons.movie_creation_outlined,
        title: 'In production',
        message:
            'Submit links to the published work once it is live. The '
            'brand reviews it, then payment is released.',
      );
    }

    if (status == BookingStatus.delivered && !detail.isBrandView) {
      return const ZInfoBanner(
        tone: StatusTone.info,
        icon: Icons.hourglass_top_rounded,
        title: 'With the brand for review',
        message: 'Payment is released to you as soon as they approve.',
      );
    }

    if ((status == BookingStatus.pending ||
            status == BookingStatus.countered) &&
        detail.isBrandView) {
      final expires = detail.expiresAt;
      return ZInfoBanner(
        tone: StatusTone.info,
        icon: Icons.schedule_rounded,
        title: 'Waiting on $name',
        message: expires == null
            ? 'You will be notified as soon as they respond.'
            : 'You will be notified when they respond. The request expires '
                  'on ${Formatters.date(expires)}.',
      );
    }

    if (actions.canReview) {
      return const ZInfoBanner(
        tone: StatusTone.success,
        icon: Icons.celebration_outlined,
        title: 'Campaign complete',
        message: 'A short review helps others decide who to work with.',
      );
    }

    return null;
  }
}

// ---------------------------------------------------------------------------
// Deliverables
// ---------------------------------------------------------------------------

class _SubmittedCount extends StatelessWidget {
  const _SubmittedCount({required this.deliverables});

  final List<Deliverable> deliverables;

  @override
  Widget build(BuildContext context) {
    final done = deliverables.where((d) => _isSubmitted(d.status)).length;

    return StatusChip(
      label: '$done of ${deliverables.length} in',
      tone: done == deliverables.length
          ? StatusTone.success
          : StatusTone.neutral,
      dense: true,
    );
  }
}

bool _isSubmitted(String status) =>
    status == 'submitted' || status == 'approved';

class _DeliverablesCard extends StatelessWidget {
  const _DeliverablesCard({required this.detail});

  final BookingDetail detail;

  @override
  Widget build(BuildContext context) {
    final booking = detail.booking;

    if (detail.deliverables.isEmpty) {
      return ZCard(
        child: Row(
          children: [
            TintedIconTile(icon: _iconForType(booking.campaignType)),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${booking.postsCount}× '
                    '${booking.campaignType.isEmpty ? 'Post' : booking.campaignType}',
                    style: AppTypography.bodyMedium.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'Links to the finished work appear here once the creator '
                    'submits them.',
                    style: AppTypography.bodySmall,
                  ),
                ],
              ),
            ),
          ],
        ),
      );
    }

    return ZCard(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.xs,
      ),
      child: Column(
        children: [
          for (var i = 0; i < detail.deliverables.length; i++) ...[
            if (i > 0) Divider(height: 1, color: AppColors.outlineVariant),
            _DeliverableRow(deliverable: detail.deliverables[i]),
          ],
        ],
      ),
    );
  }
}

class _DeliverableRow extends StatelessWidget {
  const _DeliverableRow({required this.deliverable});

  final Deliverable deliverable;

  @override
  Widget build(BuildContext context) {
    final (statusLabel, tone) = _deliverableStatus(deliverable.status);
    final submitted = _isSubmitted(deliverable.status);
    final live = deliverable.liveUrl;
    final media = deliverable.mediaUrl;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              TintedIconTile(
                icon: _iconForType(
                  deliverable.type.isEmpty
                      ? deliverable.title
                      : deliverable.type,
                ),
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
          if (deliverable.remarks != null &&
              deliverable.remarks!.isNotEmpty &&
              deliverable.status == 'revision_requested') ...[
            const SizedBox(height: AppSpacing.xs),
            Padding(
              padding: const EdgeInsets.only(left: 52),
              child: Text(
                'Changes asked: ${deliverable.remarks}',
                style: AppTypography.bodySmall.copyWith(
                  color: AppColors.onWarningContainer,
                ),
              ),
            ),
          ],
          if (deliverable.metrics.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.xs),
            Padding(
              padding: const EdgeInsets.only(left: 52),
              child: Wrap(
                spacing: AppSpacing.md,
                runSpacing: 4,
                children: [
                  for (final entry in deliverable.metrics.entries)
                    Text.rich(
                      TextSpan(
                        children: [
                          TextSpan(
                            text: Formatters.compact(
                              entry.value is num
                                  ? entry.value as num
                                  : num.tryParse('${entry.value}') ?? 0,
                            ),
                            style: AppTypography.labelLarge.copyWith(
                              color: AppColors.onSurface,
                            ),
                          ),
                          TextSpan(
                            text: ' ${entry.key}',
                            style: AppTypography.bodySmall,
                          ),
                        ],
                      ),
                    ),
                ],
              ),
            ),
          ],
          if (live != null || media != null) ...[
            const SizedBox(height: 4),
            Padding(
              padding: const EdgeInsets.only(left: 44),
              child: Wrap(
                children: [
                  if (live != null)
                    LinkButton(
                      label: 'View live post',
                      icon: Icons.open_in_new_rounded,
                      url: live,
                    ),
                  if (media != null && media != live)
                    LinkButton(
                      label: 'Open file',
                      icon: Icons.attach_file_rounded,
                      url: media,
                    ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

(String, StatusTone) _deliverableStatus(String status) => switch (status) {
  'approved' => ('Approved', StatusTone.success),
  'submitted' => ('Submitted', StatusTone.success),
  'revision_requested' => ('Changes asked', StatusTone.warning),
  'rejected' => ('Rejected', StatusTone.error),
  _ => ('Pending', StatusTone.neutral),
};

IconData _iconForType(String type) {
  final value = type.toLowerCase();
  if (value.contains('reel') || value.contains('short')) {
    return Icons.movie_creation_outlined;
  }
  if (value.contains('story')) return Icons.amp_stories_outlined;
  if (value.contains('video') || value.contains('youtube')) {
    return Icons.play_circle_outline_rounded;
  }
  if (value.contains('live')) return Icons.sensors_rounded;
  if (value.contains('blog') || value.contains('review')) {
    return Icons.article_outlined;
  }
  if (value.contains('post') || value.contains('carousel')) {
    return Icons.grid_on_rounded;
  }
  return Icons.inventory_2_outlined;
}

// ---------------------------------------------------------------------------
// Details and dates
// ---------------------------------------------------------------------------

class _ScheduleCard extends StatelessWidget {
  const _ScheduleCard({required this.detail});

  final BookingDetail detail;

  @override
  Widget build(BuildContext context) {
    final booking = detail.booking;
    final awaitingAnswer =
        booking.status == BookingStatus.pending ||
        booking.status == BookingStatus.countered;

    final dates = <(String, DateTime?, IconData)>[
      ('Requested', booking.requestedAt, Icons.send_rounded),
      if (awaitingAnswer)
        ('Respond by', detail.expiresAt, Icons.hourglass_bottom_rounded),
      ('Accepted', detail.acceptedAt, Icons.handshake_outlined),
      ('Paid', detail.paidAt, Icons.lock_outline_rounded),
      ('Delivered', detail.deliveredAt, Icons.upload_file_rounded),
      ('Completed', detail.completedAt, Icons.task_alt_rounded),
      ('Cancelled', detail.cancelledAt, Icons.event_busy_rounded),
    ].where((row) => row.$2 != null).toList();

    return ZCard(
      child: Column(
        children: [
          ZDetailRow(
            label: 'Campaign type',
            value: _orDash(booking.campaignType),
            icon: Icons.campaign_outlined,
          ),
          ZDetailRow(
            label: 'Platform',
            value: booking.platform.isEmpty
                ? '—'
                : booking.platform[0].toUpperCase() +
                      booking.platform.substring(1),
            icon: Icons.public_rounded,
          ),
          ZDetailRow(
            label: 'Posts',
            value: '${booking.postsCount}',
            icon: Icons.grid_view_rounded,
          ),
          if (detail.startDate != null && detail.endDate != null)
            ZDetailRow(
              label: 'Campaign window',
              value:
                  '${Formatters.shortDate(detail.startDate)} – ${Formatters.date(detail.endDate)}',
              icon: Icons.date_range_rounded,
            ),
          if (booking.deliveryDueAt != null)
            ZDetailRow(
              label: 'Delivery due',
              value: Formatters.date(booking.deliveryDueAt),
              icon: Icons.event_available_rounded,
            ),
          if (dates.isNotEmpty) ...[
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
              child: Divider(height: 1, color: AppColors.outlineVariant),
            ),
            for (final row in dates)
              ZDetailRow(
                label: row.$1,
                value: Formatters.date(row.$2),
                icon: row.$3,
              ),
          ],
        ],
      ),
    );
  }
}

/// A long brief folds to a few lines with a "Read more", so the money and the
/// actions below it are not pushed off the screen.
class _ExpandableText extends StatefulWidget {
  const _ExpandableText({required this.text});

  final String text;

  @override
  State<_ExpandableText> createState() => _ExpandableTextState();
}

class _ExpandableTextState extends State<_ExpandableText> {
  static const _collapsedLines = 6;
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    final style = AppTypography.bodyMedium.copyWith(
      color: AppColors.onSurfaceVariant,
      height: 1.55,
    );

    return LayoutBuilder(
      builder: (context, constraints) {
        final painter = TextPainter(
          text: TextSpan(text: widget.text, style: style),
          maxLines: _collapsedLines,
          textDirection: Directionality.of(context),
          textScaler: MediaQuery.textScalerOf(context),
        )..layout(maxWidth: constraints.maxWidth);
        final overflows = painter.didExceedMaxLines;
        painter.dispose();

        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            AnimatedSize(
              duration: Motion.of(context, Motion.medium),
              curve: Motion.standard,
              alignment: Alignment.topCenter,
              child: Text(
                widget.text,
                style: style,
                maxLines: _expanded ? null : _collapsedLines,
                overflow: _expanded ? null : TextOverflow.fade,
              ),
            ),
            if (overflows || _expanded)
              TextButton(
                onPressed: () => setState(() => _expanded = !_expanded),
                style: TextButton.styleFrom(
                  padding: EdgeInsets.zero,
                  minimumSize: const Size(
                    AppSpacing.minTouch,
                    AppSpacing.minTouch,
                  ),
                  foregroundColor: AppColors.primary,
                ),
                child: Text(
                  _expanded ? 'Show less' : 'Read more',
                  style: AppTypography.labelLarge.copyWith(
                    color: AppColors.primary,
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Negotiation
// ---------------------------------------------------------------------------

/// One round of the negotiation, told from the viewer's side: "You offered"
/// or "Ananya countered". The open offer is outlined so it stands out from
/// the rounds it replaced.
class _OfferCard extends StatelessWidget {
  const _OfferCard({required this.offer, required this.detail});

  final CounterOffer offer;
  final BookingDetail detail;

  @override
  Widget build(BuildContext context) {
    final mine = offer.byRole == detail.viewerRole;
    final byBrand = offer.byRole == 'brand';
    final superseded = offer.status == 'superseded';
    final open = offer.status == 'pending';
    final firstName = detail.booking.counterparty.name.split(' ').first;

    final who = mine
        ? 'You offered'
        : (byBrand ? '$firstName offered' : '$firstName countered');

    final (statusLabel, tone) = switch (offer.status) {
      'accepted' => ('Accepted', StatusTone.success),
      'rejected' || 'declined' => ('Declined', StatusTone.error),
      'superseded' => ('Replaced', StatusTone.neutral),
      'expired' => ('Expired', StatusTone.neutral),
      _ => ('Open', StatusTone.warning),
    };

    final meta = [
      if (offer.deliveryDays != null && offer.deliveryDays! > 0)
        '${offer.deliveryDays}-day turnaround',
      Formatters.relative(offer.createdAt),
    ].where((part) => part.isNotEmpty).join(' · ');

    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.md),
      shadow: !superseded,
      color: superseded ? AppColors.surfaceContainerLow : null,
      border: open
          ? Border.all(color: AppColors.primary.withValues(alpha: 0.45))
          : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              TintedIconTile(
                icon: byBrand
                    ? Icons.business_center_rounded
                    : Icons.auto_awesome_rounded,
                color: byBrand ? AppColors.primary : AppColors.secondary,
                size: 36,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      who,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelLarge,
                    ),
                    if (meta.isNotEmpty)
                      Text(
                        meta,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySmall,
                      ),
                  ],
                ),
              ),
              const SizedBox(width: AppSpacing.xs),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    offer.amountLabel,
                    style: AppTypography.titleMedium.copyWith(
                      fontSize: 16,
                      color: superseded
                          ? AppColors.onSurfaceVariant
                          : AppColors.onSurface,
                      decoration: superseded
                          ? TextDecoration.lineThrough
                          : null,
                    ),
                  ),
                  const SizedBox(height: 2),
                  StatusChip(label: statusLabel, tone: tone, dense: true),
                ],
              ),
            ],
          ),
          if (offer.note != null && offer.note!.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(AppSpacing.sm),
              decoration: BoxDecoration(
                color: superseded
                    ? AppColors.surfaceContainerLowest
                    : AppColors.surfaceContainerLow,
                borderRadius: AppRadius.mdAll,
              ),
              child: Text(
                offer.note!,
                style: AppTypography.bodyMedium.copyWith(
                  color: AppColors.onSurfaceVariant,
                  height: 1.45,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Payment
// ---------------------------------------------------------------------------

class _PaymentCard extends ConsumerWidget {
  const _PaymentCard({required this.detail});

  final BookingDetail detail;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final booking = detail.booking;
    final amounts = detail.amounts;

    // Brand totals are "paid" once money has moved — whichever field the
    // server recorded it in — and "payable" until then.
    final totalLabel = !detail.isBrandView
        ? 'You receive'
        : detail.isPaid
        ? 'Total paid'
        : 'Total payable';

    final commission = amounts.commission.isNotEmpty
        ? amounts.commission
        : amounts.platformFee;

    return ZCard(
      child: Column(
        children: [
          ZDetailRow(label: 'Creator fee', value: _orDash(amounts.amount)),
          if (detail.isBrandView) ...[
            ZDetailRow(label: 'GST', value: _orDash(amounts.tax)),
            if (detail.discountAmount > 0)
              ZDetailRow(
                label: detail.couponCode == null
                    ? 'Discount'
                    : 'Coupon (${detail.couponCode})',
                value: amounts.discount,
                valueColor: AppColors.success,
              ),
          ] else
            ZDetailRow(label: 'Platform commission', value: '- $commission'),
          const SizedBox(height: AppSpacing.xs),
          TotalPanel(
            label: totalLabel,
            value: detail.isBrandView
                ? _orDash(amounts.total)
                : _orDash(amounts.earning),
          ),
          const SizedBox(height: AppSpacing.sm),
          _EscrowStatus(detail: detail),
          if (detail.actions.canViewInvoice) ...[
            const SizedBox(height: AppSpacing.sm),
            ZButton.outlined(
              label: 'Download GST invoice',
              icon: Icons.download_rounded,
              size: ZButtonSize.medium,
              onPressed: () => shareInvoice(
                context,
                number: booking.code,
                load: () =>
                    ref.read(bookingRepositoryProvider).invoiceHtml(booking.id),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Where the money currently sits, in plain language, from the viewer's side.
/// Zusty holds a paid booking's money ("payment protection", never
/// "escrow"): released on approval, or automatically after the review window.
class _EscrowStatus extends ConsumerWidget {
  const _EscrowStatus({required this.detail});

  final BookingDetail detail;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final brand = detail.isBrandView;
    final payment = detail.booking.paymentStatus;
    final escrow = detail.escrowStatus;
    final window = ref.watch(reviewWindowProvider);

    // `escrow_status` is the precise field; `payment_status` values are kept
    // for rows written before it existed.
    final state = switch ((escrow, payment)) {
      ('released', _) || (_, 'released') => 'released',
      ('refunded', _) || (_, 'refunded') => 'refunded',
      ('held', _) || (_, 'escrow') || (_, 'paid') => 'held',
      (_, 'processing') => 'processing',
      _ => 'unpaid',
    };

    final (icon, message, tone) = switch (state) {
      'held' => (
        Icons.lock_rounded,
        brand
            ? 'Held by Zusty until you approve the work, or until $window '
                  'after delivery if you don\'t respond.'
            : 'Paid and held by Zusty. Released to you when the brand '
                  'approves the work, or automatically $window after delivery.',
        StatusTone.info,
      ),
      'released' => (
        Icons.check_circle_rounded,
        brand ? 'Released to the creator.' : 'Released to your wallet.',
        StatusTone.success,
      ),
      'refunded' => (
        Icons.replay_rounded,
        // Usually the original method; a wallet payment, or one that cannot
        // be reversed automatically, is refunded to the wallet instead.
        brand
            ? 'Refunded to your original payment method or Zusty wallet.'
            : 'Refunded to the brand.',
        StatusTone.warning,
      ),
      'processing' => (
        Icons.sync_rounded,
        'Payment is being confirmed with the bank.',
        StatusTone.warning,
      ),
      _ => (
        Icons.schedule_rounded,
        brand
            ? 'Payment has not been made yet.'
            : 'Not paid yet. Work starts once the brand pays.',
        StatusTone.warning,
      ),
    };

    final (background, foreground) = StatusChip.colorsFor(tone);

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: AppSpacing.xs + 2,
      ),
      decoration: BoxDecoration(
        color: background,
        borderRadius: AppRadius.mdAll,
      ),
      child: Row(
        children: [
          Icon(icon, size: 16, color: foreground),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              message,
              style: AppTypography.labelMedium.copyWith(color: foreground),
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Timeline
// ---------------------------------------------------------------------------

/// The recorded history, then — while the booking is still live — the steps
/// still to come, so the timeline also answers "what happens next".
List<TimelineStep> _timelineSteps(BookingDetail detail) {
  final entries = detail.timeline;
  final live = detail.booking.status.isActive;

  final steps = <TimelineStep>[
    for (var i = 0; i < entries.length; i++)
      TimelineStep(
        title: entries[i].label,
        subtitle: entries[i].note,
        time: [
          _actorLabel(entries[i], detail),
          if (entries[i].createdAt != null)
            Formatters.dateTime(entries[i].createdAt),
        ].whereType<String>().where((part) => part.isNotEmpty).join(' · '),
        state: i == entries.length - 1 && live
            ? TimelineState.current
            : TimelineState.done,
        icon: _isTerminalFailure(entries[i].toStatus)
            ? Icons.close_rounded
            : null,
      ),
  ];

  if (!live) return steps;

  final reached = _stage(detail.booking.status);

  const upcoming = <(int, String, String)>[
    (1, 'Accepted', 'The creator agrees to the brief and fee.'),
    (2, 'Paid', 'Zusty holds the payment until the work is approved.'),
    (3, 'Delivered', 'The creator submits the finished work.'),
    (4, 'Completed', 'Approved, and payment released to the creator.'),
  ];

  for (final step in upcoming) {
    if (step.$1 > reached) {
      steps.add(
        TimelineStep(
          title: step.$2,
          subtitle: step.$3,
          state: TimelineState.upcoming,
        ),
      );
    }
  }

  return steps;
}

int _stage(BookingStatus status) => switch (status) {
  BookingStatus.pending || BookingStatus.countered => 0,
  BookingStatus.accepted || BookingStatus.awaitingPayment => 1,
  BookingStatus.confirmed || BookingStatus.inProgress => 2,
  BookingStatus.delivered => 3,
  BookingStatus.completed => 4,
  _ => 99,
};

bool _isTerminalFailure(String status) => const {
  'rejected',
  'cancelled',
  'expired',
  'disputed',
  'refunded',
}.contains(status);

String? _actorLabel(BookingTimelineEntry entry, BookingDetail detail) {
  if (entry.actorName != null && entry.actorName!.isNotEmpty) {
    return entry.actorName;
  }

  final type = entry.actorType;
  if (type == null || type.isEmpty) return null;
  if (type == detail.viewerRole) return 'You';

  return switch (type) {
    'brand' || 'creator' => detail.booking.counterparty.name,
    'admin' || 'system' => 'Zusty',
    _ => null,
  };
}

IconData _statusIcon(BookingStatus status) => switch (status) {
  BookingStatus.pending => Icons.schedule_rounded,
  BookingStatus.countered => Icons.swap_horiz_rounded,
  BookingStatus.accepted ||
  BookingStatus.awaitingPayment => Icons.payments_outlined,
  BookingStatus.confirmed ||
  BookingStatus.inProgress => Icons.autorenew_rounded,
  BookingStatus.delivered => Icons.inventory_2_outlined,
  BookingStatus.completed => Icons.check_circle_rounded,
  BookingStatus.disputed => Icons.gavel_rounded,
  _ => Icons.block_rounded,
};

String _orDash(String value) => value.trim().isEmpty ? '—' : value;

// ---------------------------------------------------------------------------
// Chat, overflow menu, cancel
// ---------------------------------------------------------------------------

/// Opens the thread for a booking, starting one if it does not exist yet.
///
/// Both Message affordances used to do `Routes.chat(conversationId ?? bookingId)`.
/// `conversationId` was ALWAYS null — `bookings` has no conversation_id column
/// and nothing supplied one — so the fallback fired every single time and the
/// chat screen was handed a BOOKING id where a conversation id belongs. That is
/// a different thread or none at all, so the server answered 403 and the user
/// saw "We hit a snag". Tapping "Try again" re-sent the same wrong id.
///
/// An id is never invented here. Either the server has told us the thread, or
/// we ask it to open one.
Future<void> _openChat(
  BuildContext context,
  WidgetRef ref,
  BookingDetail detail,
) async {
  final existing = detail.conversationId;

  if (existing != null && existing > 0) {
    context.push(Routes.chat(existing));
    return;
  }

  final creatorId = detail.creatorId;

  if (creatorId == null || creatorId <= 0) {
    showZSnack(context, 'This booking has no one to message.', isError: true);
    return;
  }

  try {
    final id = await ref
        .read(messageRepositoryProvider)
        .start(creatorId: creatorId, bookingId: detail.booking.id);

    if (!context.mounted) return;

    if (id <= 0) {
      showZSnack(
        context,
        'Could not open the chat. Please try again.',
        isError: true,
      );
      return;
    }

    // The booking now has a thread, so a reopened screen links straight to it.
    ref.invalidate(bookingDetailProvider(detail.booking.id));

    context.push(Routes.chat(id));
  } on ApiFailure catch (e) {
    if (context.mounted) showZSnack(context, e.message, isError: true);
  } catch (_) {
    if (context.mounted) {
      showZSnack(
        context,
        'Could not open the chat. Please try again.',
        isError: true,
      );
    }
  }
}

/// Asks for a reason and cancels, telling the person up front what the
/// cancellation will cost — the server's own refund preview — rather than
/// after the fact.
///
/// The server requires a reason of at least three characters, so the dialog
/// asks for one instead of letting an empty submission fail.
Future<void> _cancelBooking(
  BuildContext context,
  WidgetRef ref,
  BookingDetail detail,
  Future<void> Function(Future<void> Function() action, String message) run,
) async {
  final id = detail.booking.id;

  String? notice;
  try {
    notice = await ref
        .read(bookingRepositoryProvider)
        .cancellationNotice(id)
        .timeout(const Duration(seconds: 8));
  } catch (_) {
    // No preview is not a reason to stop someone cancelling.
  }

  if (!context.mounted) return;

  final reason = await reasonDialog(
    context,
    title: 'Cancel this booking?',
    message:
        notice ??
        (detail.isPaid
            ? 'Any refund follows the cancellation policy and goes back the '
                  'way you paid.'
            : 'Nothing has been paid yet, so nothing will be charged.'),
    hint: 'Let ${detail.booking.counterparty.name.split(' ').first} know why',
    confirmLabel: 'Cancel booking',
    cancelLabel: 'Keep booking',
    minLength: 3,
    icon: Icons.event_busy_rounded,
  );

  if (reason == null) return;

  await run(
    () => ref.read(bookingActionsProvider).cancel(id, reason: reason),
    'Booking cancelled.',
  );
}

/// Secondary actions that do not earn a place in the bar: copy the reference,
/// fetch the invoice, and cancel — the last always reachable while the server
/// allows it, even when the bar is busy with a more important action.
class _MoreMenu extends ConsumerWidget {
  const _MoreMenu({required this.detail});

  final BookingDetail detail;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final actions = detail.actions;
    final booking = detail.booking;

    Future<void> run(Future<void> Function() action, String message) async {
      try {
        await action();
        if (context.mounted) showZSnack(context, message);
      } on ApiFailure catch (e) {
        if (context.mounted) showZSnack(context, e.message, isError: true);
      }
    }

    return PopupMenuButton<String>(
      tooltip: 'More options',
      icon: const Icon(Icons.more_vert_rounded, size: 22),
      shape: const RoundedRectangleBorder(borderRadius: AppRadius.lgAll),
      color: AppColors.surfaceContainerLowest,
      onSelected: (value) {
        switch (value) {
          case 'copy':
            _copyCode(context, booking.code);
          case 'invoice':
            shareInvoice(
              context,
              number: booking.code,
              load: () =>
                  ref.read(bookingRepositoryProvider).invoiceHtml(booking.id),
            );
          case 'cancel':
            _cancelBooking(context, ref, detail, run);
          case 'report':
            showReportSheet(
              context,
              target: ReportTarget.booking,
              id: booking.id,
            );
        }
      },
      itemBuilder: (_) => [
        _menuItem('copy', Icons.copy_rounded, 'Copy booking ID'),
        if (actions.canViewInvoice)
          _menuItem('invoice', Icons.download_rounded, 'Download invoice'),
        if (actions.canCancel)
          _menuItem(
            'cancel',
            Icons.event_busy_rounded,
            'Cancel booking',
            color: AppColors.error,
          ),
        // Conduct, for the moderators. Contesting the work or the money is a
        // dispute, which has its own place on this screen.
        _menuItem(
          'report',
          Icons.flag_outlined,
          'Report booking',
          color: AppColors.error,
        ),
      ],
    );
  }

  static PopupMenuItem<String> _menuItem(
    String value,
    IconData icon,
    String label, {
    Color? color,
  }) => PopupMenuItem<String>(
    value: value,
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 19, color: color ?? AppColors.onSurfaceVariant),
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

// ---------------------------------------------------------------------------
// Action bar
// ---------------------------------------------------------------------------

/// Renders the action bar straight from the backend's permission flags.
class _ActionBar extends ConsumerStatefulWidget {
  const _ActionBar({required this.detail});

  final BookingDetail detail;

  @override
  ConsumerState<_ActionBar> createState() => _ActionBarState();
}

class _ActionBarState extends ConsumerState<_ActionBar> {
  bool _busy = false;

  Future<void> _run(
    Future<void> Function() action,
    String successMessage,
  ) async {
    setState(() => _busy = true);
    try {
      await action();
      if (mounted) showZSnack(context, successMessage);
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = widget.detail;
    final actions = detail.actions;
    final id = detail.booking.id;
    final controller = ref.read(bookingActionsProvider);

    final buttons = <Widget>[];

    if (actions.canPay) {
      buttons.add(
        ZButton(
          label: 'Pay ${detail.amounts.total}',
          icon: Icons.lock_rounded,
          isLoading: _busy,
          onPressed: () => context.push(Routes.payment(id)),
        ),
      );
    }

    if (actions.canAccept) {
      buttons.add(
        ZButton(
          label: 'Accept booking',
          icon: Icons.check_rounded,
          isLoading: _busy,
          onPressed: () =>
              _run(() => controller.accept(id), 'Booking accepted.'),
        ),
      );
    }

    if (actions.canAcceptOffer) {
      final latest = detail.offers.isEmpty ? null : detail.offers.last;
      buttons.add(
        ZButton(
          label: latest == null
              ? 'Accept counter-offer'
              : 'Accept ${latest.amountLabel}',
          icon: Icons.handshake_outlined,
          isLoading: _busy,
          onPressed: () =>
              _run(() => controller.acceptOffer(id), 'Offer accepted.'),
        ),
      );
    }

    if (actions.canDeliver) {
      buttons.add(
        ZButton(
          label: 'Submit deliverables',
          icon: Icons.upload_rounded,
          isLoading: _busy,
          onPressed: () async {
            final result = await DeliverSheet.show(
              context,
              reviewWindow: ref.read(reviewWindowProvider),
            );
            if (result == null) return;
            await _run(
              () =>
                  controller.deliver(id, note: result.note, urls: result.urls),
              'Deliverables submitted for review.',
            );
          },
        ),
      );
    }

    if (actions.canComplete) {
      buttons.add(
        ZButton(
          label: 'Approve and release payment',
          icon: Icons.check_circle_outline_rounded,
          isLoading: _busy,
          onPressed: () async {
            final confirmed = await showZConfirm(
              context,
              title: 'Release payment?',
              message:
                  'This approves the delivered work and releases the held '
                  'payment to the creator, minus the platform commission. '
                  'This cannot be undone.',
              confirmLabel: 'Approve and release',
              icon: Icons.lock_open_rounded,
            );
            if (!confirmed) return;
            await _run(
              () => controller.complete(id),
              'Payment released to the creator.',
            );
          },
        ),
      );
    }

    if (actions.canReview) {
      buttons.add(
        ZButton(
          label: 'Leave a review',
          icon: Icons.star_outline_rounded,
          isLoading: _busy,
          onPressed: () async {
            final result = await ReviewSheet.show(context);
            if (result == null) return;
            await _run(
              () => controller.review(
                id,
                rating: result.rating,
                comment: result.comment,
              ),
              'Thanks for the review.',
            );
          },
        ),
      );
    }

    // Secondary row.
    final secondary = <Widget>[];

    if (actions.canComplete) {
      // The full review screen carries the other two branches — request
      // changes and dispute — which the one-tap approval above does not.
      secondary.add(
        Expanded(
          child: ZButton.outlined(
            label: 'Review work',
            icon: Icons.rate_review_outlined,
            size: ZButtonSize.medium,
            onPressed: _busy
                ? null
                : () => context.push(Routes.reviewDeliverables(id)),
          ),
        ),
      );
    }

    if (actions.canCounter) {
      secondary.add(
        Expanded(
          child: ZButton.outlined(
            label: 'Counter-offer',
            icon: Icons.swap_horiz_rounded,
            size: ZButtonSize.medium,
            onPressed: _busy
                ? null
                : () async {
                    final result = await CounterOfferSheet.show(
                      context,
                      currentAmount: detail.booking.amount,
                    );
                    if (result == null) return;
                    await _run(
                      () => controller.counterOffer(
                        id,
                        amount: result.amount,
                        note: result.note,
                      ),
                      'Counter-offer sent.',
                    );
                  },
          ),
        ),
      );
    }

    if (actions.canReject) {
      secondary.add(
        Expanded(
          child: ZButton(
            label: 'Decline',
            variant: ZButtonVariant.danger,
            size: ZButtonSize.medium,
            onPressed: _busy
                ? null
                : () async {
                    final reason = await reasonDialog(
                      context,
                      title: 'Decline this request?',
                      message:
                          'The brand is told you declined. This cannot '
                          'be undone.',
                      hint: 'Let the brand know why',
                      confirmLabel: 'Decline request',
                      cancelLabel: 'Keep request',
                    );
                    if (reason == null) return;
                    await _run(
                      () => controller.reject(id, reason: reason),
                      'Request declined.',
                    );
                  },
          ),
        ),
      );
    }

    if (actions.canCancel && buttons.isEmpty && secondary.isEmpty) {
      secondary.add(
        Expanded(
          child: ZButton.outlined(
            label: 'Cancel booking',
            icon: Icons.event_busy_rounded,
            size: ZButtonSize.medium,
            isLoading: _busy,
            onPressed: _busy
                ? null
                : () => _cancelBooking(context, ref, detail, _run),
          ),
        ),
      );
    }

    if (buttons.isEmpty && secondary.isEmpty) {
      return ZButton.outlined(
        label: 'Message ${detail.booking.counterparty.name.split(' ').first}',
        icon: Icons.chat_bubble_outline_rounded,
        onPressed: () => _openChat(context, ref, detail),
      );
    }

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (actions.canPay)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  Icons.verified_user_rounded,
                  size: 14,
                  color: AppColors.success,
                ),
                const SizedBox(width: 4),
                Flexible(
                  child: Text(
                    'Held by Zusty until you approve the work',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.labelSmall.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
                  ),
                ),
              ],
            ),
          ),
        for (var i = 0; i < buttons.length; i++) ...[
          if (i > 0) const SizedBox(height: AppSpacing.xs),
          buttons[i],
        ],
        if (secondary.isNotEmpty) ...[
          if (buttons.isNotEmpty) const SizedBox(height: AppSpacing.xs),
          Row(
            children: [
              for (var i = 0; i < secondary.length; i++) ...[
                if (i > 0) const SizedBox(width: AppSpacing.xs),
                secondary[i],
              ],
            ],
          ),
        ],
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Loading
// ---------------------------------------------------------------------------

/// The page's silhouette while the booking loads.
class _DetailSkeleton extends StatelessWidget {
  const _DetailSkeleton();

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);

    return ListView(
      physics: const NeverScrollableScrollPhysics(),
      padding: EdgeInsets.fromLTRB(
        margin,
        AppSpacing.xs,
        margin,
        AppSpacing.xl,
      ),
      children: const [
        ZShimmer(height: 232, borderRadius: AppRadius.xlAll),
        SizedBox(height: AppSpacing.sm),
        ZShimmer(height: 72, borderRadius: AppRadius.lgAll),
        SizedBox(height: AppSpacing.lg),
        FractionallySizedBox(
          alignment: Alignment.centerLeft,
          widthFactor: 0.4,
          child: ZShimmer(height: 16, borderRadius: AppRadius.smAll),
        ),
        SizedBox(height: AppSpacing.sm),
        ZShimmer(height: 150, borderRadius: AppRadius.xlAll),
        SizedBox(height: AppSpacing.lg),
        FractionallySizedBox(
          alignment: Alignment.centerLeft,
          widthFactor: 0.35,
          child: ZShimmer(height: 16, borderRadius: AppRadius.smAll),
        ),
        SizedBox(height: AppSpacing.sm),
        ZShimmer(height: 180, borderRadius: AppRadius.xlAll),
      ],
    );
  }
}

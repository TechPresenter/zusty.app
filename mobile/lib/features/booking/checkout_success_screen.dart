import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../models/booking.dart';
import '../../providers/booking_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_timeline.dart';
import 'widgets/booking_ui.dart';

/// Checkout Success — the confirmation moment after escrow is funded.
///
/// A full screen rather than a dialog: this is where the brand learns what
/// happens next, and where the escrow guarantee from `Zuste_Workflow.md` § 6 is
/// spelled out. Payment is held, not released — that distinction is the whole
/// trust proposition, so it gets the headline.
class CheckoutSuccessScreen extends ConsumerWidget {
  const CheckoutSuccessScreen({super.key, required this.bookingId});

  final int bookingId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(bookingDetailProvider(bookingId));

    return ZScaffold(
      padded: false,
      constrain: false,
      // No back button: payment is done, so the only ways on are forward.
      appBar: const ZAppBar(showBack: false, title: 'Payment complete'),
      bottomBar: Row(
        children: [
          Expanded(
            child: ZButton.outlined(
              label: 'Message',
              icon: Icons.chat_bubble_outline_rounded,
              size: ZButtonSize.medium,
              onPressed: () => context.go(Routes.messages),
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            flex: 2,
            child: ZButton(
              label: 'View booking',
              size: ZButtonSize.medium,
              onPressed: () => context.go(Routes.booking(bookingId)),
            ),
          ),
        ],
      ),
      body: AsyncView(
        value: detail,
        onRetry: () => ref.invalidate(bookingDetailProvider(bookingId)),
        builder: (data) => ListView(
          padding: EdgeInsets.fromLTRB(
            AppBreakpoints.pageMargin(context),
            AppSpacing.lg,
            AppBreakpoints.pageMargin(context),
            AppSpacing.xl,
          ),
          // The badge plays first; the words and cards follow it in.
          children: [
            const _SuccessBadge(),
            const SizedBox(height: AppSpacing.lg),
            Reveal(
              index: 4,
              child: Text(
                'Payment secured in escrow',
                textAlign: TextAlign.center,
                style: AppTypography.headlineMedium,
              ),
            ),
            const SizedBox(height: AppSpacing.xs),
            Reveal(
              index: 5,
              child: Text(
                '${data.amounts.total} is held safely by Zusty. It is released '
                'to ${data.booking.counterparty.name} only after you approve '
                'the delivered work.',
                textAlign: TextAlign.center,
                style: AppTypography.bodyMedium.copyWith(
                  color: AppColors.onSurfaceVariant,
                ),
              ),
            ),
            const SizedBox(height: AppSpacing.lg),
            Reveal(index: 6, child: _ReceiptCard(detail: data)),
            const SizedBox(height: AppSpacing.md),
            const Reveal(index: 6, child: _NextSteps()),
          ],
        ),
      ),
    );
  }
}

/// The success mark, ringed to echo the escrow "held" metaphor: the ring
/// settles in, the check draws itself, then a small lock pops onto the ring —
/// done, and held safely.
class _SuccessBadge extends StatelessWidget {
  const _SuccessBadge();

  @override
  Widget build(BuildContext context) {
    final reduced = Motion.reduced(context);

    final ring = Container(
      width: 104,
      height: 104,
      decoration: BoxDecoration(
        color: AppColors.successContainer.withValues(alpha: 0.5),
        shape: BoxShape.circle,
      ),
    );

    return Center(
      child: SizedBox.square(
        dimension: 104,
        child: Stack(
          alignment: Alignment.center,
          clipBehavior: Clip.none,
          children: [
            if (reduced)
              ring
            else
              TweenAnimationBuilder<double>(
                tween: Tween(begin: 0, end: 1),
                duration: Motion.slow,
                curve: Motion.emphasized,
                child: ring,
                builder: (context, t, child) => Opacity(
                  opacity: t.clamp(0.0, 1.0),
                  child: Transform.scale(scale: 0.8 + 0.2 * t, child: child),
                ),
              ),
            // Its disc matches the 76px circle the badge has always had.
            SuccessCheck(
              size: 76 / 0.82,
              color: AppColors.tertiary,
              checkColor: AppColors.onTertiary,
              delay: const Duration(milliseconds: 80),
            ),
            Positioned(
              right: 4,
              bottom: 4,
              child: Reveal(
                delay: const Duration(milliseconds: 380),
                offset: const Offset(0, 6),
                child: Container(
                  width: 30,
                  height: 30,
                  decoration: BoxDecoration(
                    color: AppColors.surfaceContainerLowest,
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: AppColors.successContainer,
                      width: 2,
                    ),
                  ),
                  child: Icon(
                    Icons.lock_rounded,
                    size: 15,
                    color: AppColors.tertiary,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ReceiptCard extends StatelessWidget {
  const _ReceiptCard({required this.detail});

  final BookingDetail detail;

  @override
  Widget build(BuildContext context) {
    final booking = detail.booking;

    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.md + 4),
      child: Column(
        children: [
          Row(
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
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            booking.counterparty.name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.bodySmall,
                          ),
                        ),
                        if (booking.counterparty.isVerified) ...[
                          const SizedBox(width: 4),
                          const VerifiedBadge(size: 13),
                        ],
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
          Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
            child: Divider(height: 1, color: AppColors.outlineVariant),
          ),
          ZDetailRow(label: 'Booking ID', value: booking.code),
          ZDetailRow(label: 'Campaign value', value: detail.amounts.amount),
          ZDetailRow(label: 'Taxes', value: detail.amounts.tax),
          if (detail.discountAmount > 0)
            ZDetailRow(
              label: detail.couponCode == null
                  ? 'Discount'
                  : 'Coupon (${detail.couponCode})',
              value: detail.amounts.discount,
              valueColor: AppColors.success,
            ),
          const SizedBox(height: AppSpacing.xs),
          TotalPanel(
            label: 'Paid into escrow',
            value: detail.amounts.total,
            color: AppColors.success,
          ),
        ],
      ),
    );
  }
}

/// What happens next, so the brand is not left guessing after paying — the
/// payment step already ticked, the creator's step underway.
class _NextSteps extends StatelessWidget {
  const _NextSteps();

  @override
  Widget build(BuildContext context) {
    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.md + 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'What happens next',
            style: AppTypography.titleMedium.copyWith(fontSize: 17),
          ),
          const SizedBox(height: AppSpacing.md),
          const ZTimeline(
            steps: [
              TimelineStep(
                title: 'Payment secured',
                subtitle: 'Held by Zusty — not released to anyone yet.',
                icon: Icons.lock_rounded,
              ),
              TimelineStep(
                title: 'Creator starts production',
                subtitle:
                    'They will share drafts with you in chat as the '
                    'work progresses.',
                state: TimelineState.current,
              ),
              TimelineStep(
                title: 'Deliverables submitted',
                subtitle:
                    'You will be notified the moment content is ready '
                    'for review.',
                state: TimelineState.upcoming,
              ),
              TimelineStep(
                title: 'You approve, we release',
                subtitle:
                    'Escrow pays out to the creator, minus the platform '
                    'commission.',
                state: TimelineState.upcoming,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

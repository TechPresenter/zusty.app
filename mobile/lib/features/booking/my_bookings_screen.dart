import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_response.dart';
import '../../core/theme/app_theme.dart';
import '../../models/booking.dart';
import '../../providers/auth_provider.dart';
import '../../providers/booking_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_tabs.dart';
import '../shell/app_shell.dart';
import 'widgets/booking_ui.dart';

/// Screen 14 — My Bookings.
///
/// Three segments over the booking state machine — Active, Completed,
/// Cancelled — each badged with the total the list endpoint reports, and each
/// list a stack of cards that answer "who, what, how much, by when" before
/// the booking is even opened.
class MyBookingsScreen extends ConsumerStatefulWidget {
  const MyBookingsScreen({super.key});

  @override
  ConsumerState<MyBookingsScreen> createState() => _MyBookingsScreenState();
}

class _MyBookingsScreenState extends ConsumerState<MyBookingsScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(
    length: BookingTab.values.length,
    vsync: this,
  );

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final counts = ref.watch(bookingTabCountsProvider);
    final isCreator = ref.watch(currentUserProvider)?.role.isCreator ?? false;
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      // A city means nothing on a list of one's own bookings.
      appBar: const ShellHeader(title: 'Bookings', showLocation: false),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, margin, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'My bookings',
                  style: AppTypography.headlineMedium.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  isCreator
                      ? 'Requests, work in progress and payouts in one place.'
                      : 'Requests, payments and deliverables in one place.',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.xs),
          ZSegmentedTabs(
            controller: _tabs,
            padding: EdgeInsets.symmetric(
              horizontal: margin,
              vertical: AppSpacing.xs,
            ),
            labels: [for (final tab in BookingTab.values) tab.label],
            counts: counts == null
                ? null
                : [counts.active, counts.completed, counts.cancelled],
          ),
          Expanded(
            child: TabBarView(
              controller: _tabs,
              children: [
                for (final tab in BookingTab.values)
                  _BookingList(tab: tab, isCreator: isCreator),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _BookingList extends ConsumerWidget {
  const _BookingList({required this.tab, required this.isCreator});

  final BookingTab tab;
  final bool isCreator;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bookings = ref.watch(bookingsProvider(tab));
    final margin = AppBreakpoints.pageMargin(context);

    Future<void> refresh() => ref.refresh(bookingsProvider(tab).future);

    return ZRefresh(
      onRefresh: refresh,
      child: bookings.when(
        loading: () => ListView(
          physics: const NeverScrollableScrollPhysics(),
          padding: EdgeInsets.fromLTRB(
            margin,
            AppSpacing.xs,
            margin,
            AppSpacing.xl,
          ),
          children: const [
            _BookingCardSkeleton(),
            SizedBox(height: AppSpacing.sm),
            _BookingCardSkeleton(),
            SizedBox(height: AppSpacing.sm),
            _BookingCardSkeleton(),
          ],
        ),
        error: (error, _) => _Scrollable(
          child: ErrorState(
            error: error,
            onRetry: () => ref.invalidate(bookingsProvider(tab)),
          ),
        ),
        data: (page) => page.isEmpty
            ? _Scrollable(
                child: _EmptyTab(tab: tab, isCreator: isCreator),
              )
            : _List(page: page, tab: tab),
      ),
    );
  }
}

class _List extends StatelessWidget {
  const _List({required this.page, required this.tab});

  final Paginated<Booking> page;
  final BookingTab tab;

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);

    // The scope remembers which cards have already played their entrance, so
    // a pull-to-refresh does not replay the whole stagger.
    return RevealScope(
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: EdgeInsets.fromLTRB(
          margin,
          AppSpacing.xs,
          margin,
          AppSpacing.xl,
        ),
        itemCount: page.items.length,
        separatorBuilder: (_, _) => const SizedBox(height: AppSpacing.sm),
        itemBuilder: (_, i) => Reveal(
          index: i,
          revealKey: '${tab.name}-${page.items[i].id}',
          child: BookingCard(booking: page.items[i]),
        ),
      ),
    );
  }
}

/// Keeps a non-list state pullable, so an empty or failed tab can still be
/// refreshed with the same gesture as a full one.
class _Scrollable extends StatelessWidget {
  const _Scrollable({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) => SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: constraints.maxHeight),
          child: Center(child: child),
        ),
      ),
    );
  }
}

/// An empty tab says what would be here and offers the next useful step —
/// for a brand that is finding a creator, for a creator finding a campaign.
class _EmptyTab extends StatelessWidget {
  const _EmptyTab({required this.tab, required this.isCreator});

  final BookingTab tab;
  final bool isCreator;

  @override
  Widget build(BuildContext context) {
    final (title, message, icon) = switch (tab) {
      BookingTab.active => (
        'No active bookings',
        isCreator
            ? 'Apply to open campaigns, or keep your profile fresh so brands '
                  'can book you directly.'
            : 'Find a creator you love and send your first campaign request.',
        Icons.event_note_rounded,
      ),
      BookingTab.completed => (
        'Nothing completed yet',
        isCreator
            ? 'Finished campaigns and their payouts will appear here.'
            : 'Completed campaigns and their invoices will appear here.',
        Icons.task_alt_rounded,
      ),
      BookingTab.cancelled => (
        'No cancelled bookings',
        'Cancelled, declined and expired bookings show up here — '
            'hopefully this stays empty.',
        Icons.event_busy_rounded,
      ),
    };

    return EmptyState(
      title: title,
      message: message,
      icon: icon,
      actionLabel: isCreator ? 'Find campaigns' : 'Find creators',
      onAction: () =>
          context.push(isCreator ? Routes.creatorCampaigns : Routes.explore),
    );
  }
}

/// A booking at a glance: who it is with, what it is, where it stands, its
/// reference, the money, and the deadline.
class BookingCard extends StatelessWidget {
  const BookingCard({super.key, required this.booking});

  final Booking booking;

  @override
  Widget build(BuildContext context) {
    final counterparty = booking.counterparty;
    final (deadlineLabel, deadlineValue, deadlineColor) = _deadline(booking);

    final eyebrow = [
      counterparty.name,
      if (booking.campaignType.isNotEmpty) booking.campaignType,
    ].join(' · ');

    return Semantics(
      button: true,
      label:
          '${booking.title}, with ${counterparty.name}, '
          '${booking.statusLabel}, ${booking.amountLabel}',
      child: ZCard(
        onTap: () => context.push(Routes.booking(booking.id)),
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                BookingAvatar(
                  counterparty: counterparty,
                  platform: booking.platform,
                  size: 48,
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
                              eyebrow,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: AppTypography.labelMedium.copyWith(
                                color: AppColors.onSurfaceVariant,
                              ),
                            ),
                          ),
                          if (counterparty.isVerified) ...[
                            const SizedBox(width: 4),
                            const VerifiedBadge(size: 14),
                          ],
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        booking.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.titleSmall.copyWith(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          height: 1.3,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: AppSpacing.xs),
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 112),
                  child: StatusChip(
                    label: booking.statusLabel,
                    tone: booking.tone,
                    dense: true,
                  ),
                ),
              ],
            ),
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
                  Expanded(
                    child: LabeledValue(
                      label: 'Booking ID',
                      value: booking.code.isEmpty ? '—' : booking.code,
                    ),
                  ),
                  const SizedBox(width: AppSpacing.xs),
                  Expanded(
                    child: LabeledValue(
                      label: deadlineLabel,
                      value: deadlineValue,
                      valueColor: deadlineColor,
                    ),
                  ),
                  const SizedBox(width: AppSpacing.xs),
                  Expanded(
                    child: LabeledValue(
                      label: 'Amount',
                      value: booking.amountLabel,
                      emphasise: true,
                      align: CrossAxisAlignment.end,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                Expanded(
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: booking.canReview
                        ? ZButton.tonal(
                            label: 'Leave a review',
                            icon: Icons.star_outline_rounded,
                            size: ZButtonSize.medium,
                            expand: false,
                            onPressed: () =>
                                context.push(Routes.booking(booking.id)),
                          )
                        : _NextStepHint(booking: booking),
                  ),
                ),
                const SizedBox(width: AppSpacing.xs),
                ExcludeSemantics(
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        'View details',
                        style: AppTypography.labelLarge.copyWith(
                          color: AppColors.primary,
                        ),
                      ),
                      const SizedBox(width: 2),
                      Icon(
                        Icons.chevron_right_rounded,
                        size: 20,
                        color: AppColors.primary,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  /// The deadline cell: a countdown while the booking is live, otherwise the
  /// date it was booked on.
  static (String, String, Color?) _deadline(Booking booking) {
    final due = booking.deliveryDueAt;

    if (due == null || !booking.status.isActive) {
      return (
        'Booked on',
        booking.dateLabel.isEmpty ? '—' : booking.dateLabel,
        null,
      );
    }

    final today = DateUtils.dateOnly(DateTime.now());
    final days = DateUtils.dateOnly(due).difference(today).inDays;

    if (days < 0) return ('Deadline', 'Overdue', AppColors.error);
    if (days == 0) return ('Deadline', 'Due today', AppColors.warning);
    if (days <= 2) return ('Deadline', 'Due in ${days}d', AppColors.warning);
    return ('Deadline', 'Due in ${days}d', null);
  }
}

/// One quiet line saying what the booking is waiting on, where the status
/// alone does not say it.
class _NextStepHint extends StatelessWidget {
  const _NextStepHint({required this.booking});

  final Booking booking;

  @override
  Widget build(BuildContext context) {
    final hint = switch (booking.status) {
      BookingStatus.pending => 'Awaiting a response',
      BookingStatus.countered => 'New offer on the table',
      BookingStatus.accepted ||
      BookingStatus.awaitingPayment => 'Awaiting payment',
      BookingStatus.confirmed || BookingStatus.inProgress => 'In production',
      BookingStatus.delivered => 'Work ready for review',
      BookingStatus.disputed => 'Under review by Zusty',
      _ => null,
    };

    if (hint == null) return const SizedBox.shrink();

    return Text(
      hint,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: AppTypography.bodySmall,
    );
  }
}

/// The card's silhouette while a tab loads: avatar, two lines, the fact strip
/// and the footer, so nothing jumps when the real cards arrive.
class _BookingCardSkeleton extends StatelessWidget {
  const _BookingCardSkeleton();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.xlAll,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const ZShimmer(
                width: 48,
                height: 48,
                borderRadius: AppRadius.pill,
              ),
              const SizedBox(width: AppSpacing.sm),
              const Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    FractionallySizedBox(
                      widthFactor: 0.45,
                      child: ZShimmer(
                        height: 10,
                        borderRadius: AppRadius.smAll,
                      ),
                    ),
                    SizedBox(height: AppSpacing.xs),
                    FractionallySizedBox(
                      widthFactor: 0.8,
                      child: ZShimmer(
                        height: 14,
                        borderRadius: AppRadius.smAll,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              ZShimmer(width: 64, height: 20, borderRadius: AppRadius.pill),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          const ZShimmer(height: 48, borderRadius: AppRadius.mdAll),
          const SizedBox(height: AppSpacing.sm),
          const Align(
            alignment: Alignment.centerRight,
            child: ZShimmer(
              width: 96,
              height: 14,
              borderRadius: AppRadius.smAll,
            ),
          ),
        ],
      ),
    );
  }
}

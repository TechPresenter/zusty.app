import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_response.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/booking.dart';
import '../../models/campaign.dart';
import '../../models/wallet.dart';
import '../../providers/app_config_provider.dart';
import '../../providers/auth_provider.dart';
import '../../providers/booking_provider.dart';
import '../../providers/campaign_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/campaign_card.dart';
import '../../shared/widgets/stat_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_image.dart';
import '../campaigns/widgets/brand_campaign_view.dart';
import '../shell/app_shell.dart';

/// Brand Dashboard — the command centre for a brand account.
///
/// Home is for discovery; this is for running what you have already started.
/// The ordering is deliberate: the money and the headline counts first, then
/// the shortcuts, then anything *waiting on the brand* — an unapproved
/// deliverable is money sitting frozen in escrow, and a counter-offer left
/// unanswered is a creator walking away — then the work in flight.
///
/// Every figure comes from an endpoint the app already calls (wallet, active
/// bookings, the brand's campaigns); nothing here is estimated.
class BrandDashboardScreen extends ConsumerWidget {
  const BrandDashboardScreen({super.key});

  Future<void> _refresh(WidgetRef ref) async {
    ref
      ..invalidate(walletProvider)
      ..invalidate(bookingsProvider(BookingTab.active))
      ..invalidate(campaignsProvider(CampaignTab.all));
    try {
      await Future.wait([
        ref.read(walletProvider.future),
        ref.read(bookingsProvider(BookingTab.active).future),
        ref.read(campaignsProvider(CampaignTab.all).future),
      ]);
    } catch (_) {
      // Each section shows its own error; the pull just needs to finish.
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final wallet = ref.watch(walletProvider);
    final active = ref.watch(bookingsProvider(BookingTab.active));
    final campaigns = ref.watch(campaignsProvider(CampaignTab.all));
    final margin = AppBreakpoints.pageMargin(context);

    final attention = active.valueOrNull == null
        ? const <_Attention>[]
        : _Attention.from(active.valueOrNull!.items);

    var section = 0;
    Widget reveal(Widget child) => Reveal(index: section++, child: child);

    return Scaffold(
      backgroundColor: AppColors.surface,
      appBar: const ShellHeader(title: 'Dashboard'),
      body: ZRefresh(
        onRefresh: () => _refresh(ref),
        child: RevealScope(
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.md,
              margin,
              AppSpacing.xxl,
            ),
            children: [
              _Greeting(
                name: user?.displayName ?? 'there',
                attentionCount: active.hasValue ? attention.length : null,
              ),
              const SizedBox(height: AppSpacing.lg),

              // -- Spend -------------------------------------------------
              reveal(
                AsyncView<Wallet>(
                  value: wallet,
                  loading: const ZShimmer(
                    height: 168,
                    borderRadius: AppRadius.xxlAll,
                  ),
                  onRetry: () => ref.invalidate(walletProvider),
                  builder: (data) => _SpendCard(wallet: data),
                ),
              ),
              const SizedBox(height: AppSpacing.md),

              // -- Headline counts ---------------------------------------
              reveal(
                _StatGrid(
                  campaigns: campaigns,
                  active: active,
                  attention: attention,
                ),
              ),
              const SizedBox(height: AppSpacing.lg),

              // -- Quick actions -----------------------------------------
              reveal(const _QuickActions()),

              // -- Waiting on the brand ----------------------------------
              if (attention.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.lg),
                reveal(_NeedsAttention(items: attention)),
              ],
              const SizedBox(height: AppSpacing.lg),

              // -- Campaigns ---------------------------------------------
              reveal(_ActiveCampaigns(campaigns: campaigns)),
              const SizedBox(height: AppSpacing.lg),

              // -- Bookings ----------------------------------------------
              reveal(
                _RecentBookings(
                  active: active,
                  // Already listed above with their action; not twice.
                  exclude: {for (final item in attention) item.booking.id},
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Greeting
// -----------------------------------------------------------------------------

class _Greeting extends StatelessWidget {
  const _Greeting({required this.name, required this.attentionCount});

  final String name;

  /// Null while bookings load, so the line does not claim "all caught up"
  /// before it knows.
  final int? attentionCount;

  static String _salutation(DateTime now) => switch (now.hour) {
        < 12 => 'Good morning',
        < 17 => 'Good afternoon',
        _ => 'Good evening',
      };

  @override
  Widget build(BuildContext context) {
    final count = attentionCount;
    final summary = switch (count) {
      null => 'Here is how your campaigns are doing.',
      0 => 'You are all caught up. Here is how your campaigns are doing.',
      1 => '1 thing needs you today.',
      _ => '$count things need you today.',
    };

    return Semantics(
      header: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _salutation(DateTime.now()),
            style: AppTypography.bodyMedium
                .copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: 2),
          Text(
            name,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.headlineMedium,
          ),
          const SizedBox(height: AppSpacing.base),
          Text(
            summary,
            style: AppTypography.bodyMedium.copyWith(
              color: count != null && count > 0
                  ? AppColors.primaryDark
                  : AppColors.onSurfaceVariant,
              fontWeight:
                  count != null && count > 0 ? FontWeight.w600 : null,
            ),
          ),
        ],
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Spend
// -----------------------------------------------------------------------------

/// The one gradient on the screen: money held for creators, with the wallet
/// and lifetime spend beneath it. Opens the wallet.
class _SpendCard extends StatelessWidget {
  const _SpendCard({required this.wallet});

  final Wallet wallet;

  @override
  Widget build(BuildContext context) {
    // Container tones rather than primary/secondary: they keep white text
    // readable in both themes, where the dark primary turns pale.
    final foreground = AppColors.onPrimaryContainer;

    return Semantics(
      button: true,
      label: 'Committed to campaigns ${Formatters.currency(wallet.escrow)}. '
          'Open wallet',
      excludeSemantics: true,
      onTap: () => context.push(Routes.wallet),
      child: Material(
        color: Colors.transparent,
        borderRadius: AppRadius.xxlAll,
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: AppRadius.xxlAll,
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                AppColors.secondaryContainer,
                AppColors.primaryContainer,
              ],
            ),
            boxShadow: AppShadows.raised,
          ),
          child: InkWell(
            borderRadius: AppRadius.xxlAll,
            onTap: () => context.push(Routes.wallet),
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          'Committed to campaigns',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.labelLarge.copyWith(
                            color: foreground.withValues(alpha: 0.88),
                          ),
                        ),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: AppSpacing.xs,
                          vertical: 4,
                        ),
                        decoration: BoxDecoration(
                          color: foreground.withValues(alpha: 0.16),
                          borderRadius: AppRadius.pill,
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(Icons.lock_rounded, size: 13, color: foreground),
                            const SizedBox(width: 4),
                            Text(
                              'In escrow',
                              style: AppTypography.labelSmall
                                  .copyWith(color: foreground),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  AnimatedCounter.text(
                    Formatters.currency(wallet.escrow),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.displayLarge
                        .copyWith(color: foreground, fontSize: 34),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'Released to creators as you approve their work.',
                    style: AppTypography.bodySmall.copyWith(
                      color: foreground.withValues(alpha: 0.8),
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      vertical: AppSpacing.sm,
                    ),
                    decoration: BoxDecoration(
                      color: foreground.withValues(alpha: 0.12),
                      borderRadius: AppRadius.lgAll,
                    ),
                    child: IntrinsicHeight(
                      child: Row(
                        children: [
                          Expanded(
                            child: _HeroStat(
                              label: 'Wallet balance',
                              value: Formatters.currency(wallet.balance),
                              color: foreground,
                            ),
                          ),
                          VerticalDivider(
                            width: 1,
                            thickness: 1,
                            color: foreground.withValues(alpha: 0.25),
                          ),
                          Expanded(
                            child: _HeroStat(
                              label: 'Lifetime spend',
                              value: Formatters.currency(wallet.totalSpent),
                              color: foreground,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _HeroStat extends StatelessWidget {
  const _HeroStat({
    required this.label,
    required this.value,
    required this.color,
  });

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.labelSmall
                .copyWith(color: color.withValues(alpha: 0.8)),
          ),
          const SizedBox(height: 2),
          AnimatedCounter.text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.titleSmall
                .copyWith(color: color, fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Headline counts
// -----------------------------------------------------------------------------

/// Four counts, two-up. Each opens the list it summarises.
class _StatGrid extends ConsumerWidget {
  const _StatGrid({
    required this.campaigns,
    required this.active,
    required this.attention,
  });

  final AsyncValue<Paginated<Campaign>> campaigns;
  final AsyncValue<Paginated<Booking>> active;
  final List<_Attention> attention;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final page = campaigns.valueOrNull;
    final live = page?.items.where((c) => c.status.isLive).toList();

    // The "All" page holds 20. Past that, the live count comes from the two
    // status filters' totals instead of a partial page.
    int? liveCount = live?.length;
    if (page != null && page.hasMore) {
      final open = ref.watch(campaignsProvider(CampaignTab.open)).valueOrNull;
      final running =
          ref.watch(campaignsProvider(CampaignTab.inProgress)).valueOrNull;
      liveCount = open == null || running == null
          ? null
          : open.total + running.total;
    }

    final applicants =
        live?.fold<int>(0, (sum, c) => sum + c.applicationsCount);
    final drafts =
        page?.items.where((c) => c.status == CampaignStatus.draft).length;

    final bookings = active.valueOrNull?.items;
    final toReview = attention
        .where((a) => a.kind == _AttentionKind.review)
        .toList();

    Widget tile(Widget? card) => Expanded(
          child: card ??
              const ZShimmer(height: 136, borderRadius: AppRadius.xlAll),
        );

    return Column(
      children: [
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              tile(
                liveCount == null
                    ? null
                    : StatCard(
                        label: 'Active campaigns',
                        value: '$liveCount',
                        icon: Icons.campaign_rounded,
                        tint: AppColors.secondary,
                        caption: drafts != null && drafts > 0
                            ? '$drafts draft${drafts == 1 ? '' : 's'} to finish'
                            : null,
                        onTap: () => context.push(Routes.campaigns),
                      ),
              ),
              const SizedBox(width: AppSpacing.sm),
              tile(
                bookings == null
                    ? null
                    : StatCard(
                        label: 'Active bookings',
                        value: '${bookings.length}',
                        icon: Icons.handshake_rounded,
                        onTap: () => context.go(Routes.bookings),
                      ),
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              tile(
                bookings == null
                    ? null
                    : StatCard(
                        label: 'Pending approvals',
                        value: '${toReview.length}',
                        icon: Icons.fact_check_rounded,
                        tint: toReview.isEmpty
                            ? AppColors.onSurfaceVariant
                            : AppColors.warning,
                        caption: toReview.isEmpty
                            ? 'Nothing to review'
                            : 'Deliveries to review',
                        onTap: () => toReview.length == 1
                            ? context.push(
                                Routes.reviewDeliverables(
                                  toReview.first.booking.id,
                                ),
                              )
                            : context.go(Routes.bookings),
                      ),
              ),
              const SizedBox(width: AppSpacing.sm),
              tile(
                applicants == null
                    ? null
                    : StatCard(
                        label: 'Applicants',
                        value: '$applicants',
                        icon: Icons.people_alt_rounded,
                        tint: AppColors.info,
                        caption: 'On live campaigns',
                        onTap: () => context.push(Routes.campaigns),
                      ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

// -----------------------------------------------------------------------------
// Quick actions
// -----------------------------------------------------------------------------

class _QuickActions extends ConsumerWidget {
  const _QuickActions();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final shortcuts = <(IconData, String, String)>[
      (Icons.search_rounded, 'Find creators', Routes.explore),
      if (ref.watch(aiFeaturesProvider).creatorMatch)
        (Icons.auto_awesome_rounded, 'AI match', Routes.aiMatch),
      (Icons.compare_arrows_rounded, 'Compare', Routes.compare),
      (Icons.receipt_long_rounded, 'Invoices', Routes.invoices),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SectionHeader(title: 'Quick actions'),
        const SizedBox(height: AppSpacing.xs),

        // The one action that starts new work gets the full width.
        ZCard(
          color: AppColors.primaryFixed,
          border: Border.all(color: AppColors.primary.withValues(alpha: 0.12)),
          shadow: false,
          onTap: () => context.push(Routes.newCampaign),
          child: Semantics(
            button: true,
            label: 'Create a campaign',
            excludeSemantics: true,
            child: Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(
                    color: AppColors.primary,
                    borderRadius: AppRadius.mdAll,
                  ),
                  child: Icon(
                    Icons.add_rounded,
                    color: AppColors.onPrimary,
                    size: 24,
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Create a campaign',
                        style: AppTypography.titleSmall.copyWith(
                          color: AppColors.onPrimaryFixed,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'Post a brief and let matching creators apply.',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySmall.copyWith(
                          color: AppColors.onPrimaryFixedVariant,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: AppSpacing.xs),
                Icon(
                  Icons.arrow_forward_rounded,
                  size: 20,
                  color: AppColors.onPrimaryFixedVariant,
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (var i = 0; i < shortcuts.length; i++) ...[
              if (i > 0) const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: _ShortcutTile(
                  icon: shortcuts[i].$1,
                  label: shortcuts[i].$2,
                  onTap: () => context.push(shortcuts[i].$3),
                ),
              ),
            ],
          ],
        ),
      ],
    );
  }
}

class _ShortcutTile extends StatelessWidget {
  const _ShortcutTile({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ZCard(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.base,
        vertical: AppSpacing.sm,
      ),
      onTap: onTap,
      child: Semantics(
        button: true,
        label: label,
        excludeSemantics: true,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: AppColors.surfaceContainerLow,
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 20, color: AppColors.primary),
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              label,
              maxLines: 2,
              textAlign: TextAlign.center,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.labelMedium,
            ),
          ],
        ),
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Needs attention
// -----------------------------------------------------------------------------

enum _AttentionKind { review, respond, pay }

/// A booking that cannot move until the brand acts on it.
class _Attention {
  const _Attention(this.kind, this.booking);

  final _AttentionKind kind;
  final Booking booking;

  /// Deliveries first: they hold escrow and the creator's payout.
  static List<_Attention> from(List<Booking> bookings) {
    _AttentionKind? kindOf(Booking b) => switch (b.status) {
          BookingStatus.delivered => _AttentionKind.review,
          BookingStatus.countered => _AttentionKind.respond,
          BookingStatus.accepted ||
          BookingStatus.awaitingPayment =>
            _AttentionKind.pay,
          _ => null,
        };

    final items = [
      for (final booking in bookings)
        if (kindOf(booking) case final kind?) _Attention(kind, booking),
    ]..sort((a, b) => a.kind.index.compareTo(b.kind.index));
    return items;
  }

  String get title => switch (kind) {
        _AttentionKind.review => 'Review delivery',
        _AttentionKind.respond => 'Answer counter-offer',
        _AttentionKind.pay => 'Pay to confirm',
      };

  String get actionLabel => switch (kind) {
        _AttentionKind.review => 'Review',
        _AttentionKind.respond => 'Respond',
        _AttentionKind.pay => 'Pay',
      };

  IconData get icon => switch (kind) {
        _AttentionKind.review => Icons.fact_check_rounded,
        _AttentionKind.respond => Icons.swap_horiz_rounded,
        _AttentionKind.pay => Icons.account_balance_wallet_rounded,
      };

  StatusTone get tone => switch (kind) {
        _AttentionKind.review => StatusTone.warning,
        _AttentionKind.respond => StatusTone.info,
        _AttentionKind.pay => StatusTone.success,
      };

  String get route => switch (kind) {
        _AttentionKind.review => Routes.reviewDeliverables(booking.id),
        // The booking screen carries the accept / counter / pay actions.
        _ => Routes.booking(booking.id),
      };
}

class _NeedsAttention extends StatelessWidget {
  const _NeedsAttention({required this.items});

  final List<_Attention> items;

  @override
  Widget build(BuildContext context) {
    final shown = items.take(4).toList();
    final hasReview = items.any((i) => i.kind == _AttentionKind.review);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          title: 'Needs your attention',
          subtitle: hasReview
              ? 'Escrow stays frozen until you approve, so creators are waiting.'
              : 'Creators are waiting on your answer.',
          actionLabel: items.length > shown.length ? 'View all' : null,
          onAction: items.length > shown.length
              ? () => context.go(Routes.bookings)
              : null,
        ),
        const SizedBox(height: AppSpacing.xs),
        _RowGroup(
          children: [
            for (final item in shown)
              _AttentionRow(item: item),
          ],
        ),
      ],
    );
  }
}

class _AttentionRow extends StatelessWidget {
  const _AttentionRow({required this.item});

  final _Attention item;

  @override
  Widget build(BuildContext context) {
    final booking = item.booking;
    final (tint, ink) = StatusChip.colorsFor(item.tone);

    return _TappableRow(
      onTap: () => context.push(item.route),
      semanticsLabel: '${item.title}: ${booking.title}, '
          '${booking.counterparty.name}, ${booking.amountLabel}',
      child: Row(
        children: [
          Stack(
            clipBehavior: Clip.none,
            children: [
              ZAvatar(
                imageUrl: booking.counterparty.avatarUrl,
                name: booking.counterparty.name,
                size: 44,
              ),
              Positioned(
                right: -4,
                bottom: -4,
                child: Container(
                  width: 22,
                  height: 22,
                  decoration: BoxDecoration(
                    color: tint,
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: AppColors.surfaceContainerLowest,
                      width: 2,
                    ),
                  ),
                  child: Icon(item.icon, size: 12, color: ink),
                ),
              ),
            ],
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleSmall,
                ),
                const SizedBox(height: 2),
                Text(
                  '${booking.counterparty.name} · ${booking.title}',
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
                booking.amountLabel,
                style: AppTypography.labelLarge,
              ),
              const SizedBox(height: 4),
              StatusChip(label: item.actionLabel, tone: item.tone, dense: true),
            ],
          ),
        ],
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Campaigns
// -----------------------------------------------------------------------------

class _ActiveCampaigns extends StatelessWidget {
  const _ActiveCampaigns({required this.campaigns});

  final AsyncValue<Paginated<Campaign>> campaigns;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          title: 'Active campaigns',
          actionLabel: 'View all',
          onAction: () => context.push(Routes.campaigns),
        ),
        const SizedBox(height: AppSpacing.xs),
        campaigns.when(
          loading: () => const ListSkeleton(
            count: 2,
            height: 72,
            padding: EdgeInsets.zero,
          ),
          error: (error, _) => ZCard(
            child: ErrorState(error: error),
          ),
          data: (page) {
            // Live briefs first, then drafts waiting to go out; closed ones
            // live on the Campaigns screen.
            final live = page.items.where((c) => c.status.isLive);
            final drafts =
                page.items.where((c) => c.status == CampaignStatus.draft);
            final shown = [...live, ...drafts].take(3).toList();

            if (shown.isEmpty) {
              return ZCard(
                child: EmptyState(
                  compact: true,
                  icon: Icons.campaign_outlined,
                  title: page.isEmpty
                      ? 'No campaigns yet'
                      : 'No campaigns running',
                  message: 'Post a brief and let creators come to you.',
                  actionLabel: 'Create campaign',
                  onAction: () => context.push(Routes.newCampaign),
                ),
              );
            }

            return _RowGroup(
              children: [
                for (final campaign in shown) _CampaignRow(campaign: campaign),
              ],
            );
          },
        ),
      ],
    );
  }
}

class _CampaignRow extends ConsumerWidget {
  const _CampaignRow({required this.campaign});

  final Campaign campaign;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final style = campaignCategoryStyle(ref, campaign);
    final status = campaign.brandStatus;
    final schedule = campaign.scheduleLabel;

    final facts = campaign.status == CampaignStatus.draft
        ? 'Draft · ${campaign.deliverableShortLabel}'
        : '${campaign.applicationsCount} applicants · '
            '${campaign.hiredCount} hired · ${schedule.label}';

    return _TappableRow(
      onTap: () => context.push(Routes.campaign(campaign.id)),
      semanticsLabel: '${campaign.title}, ${status.label}, $facts',
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: style.tint.withValues(alpha: 0.12),
              borderRadius: AppRadius.mdAll,
            ),
            child: Icon(style.icon, size: 22, color: style.tint),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  campaign.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleSmall,
                ),
                const SizedBox(height: 2),
                Text(
                  facts,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          StatusChip(label: status.label, tone: status.tone, dense: true),
        ],
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Bookings
// -----------------------------------------------------------------------------

class _RecentBookings extends StatelessWidget {
  const _RecentBookings({required this.active, required this.exclude});

  final AsyncValue<Paginated<Booking>> active;
  final Set<int> exclude;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          title: 'Recent bookings',
          actionLabel: 'View all',
          onAction: () => context.go(Routes.bookings),
        ),
        const SizedBox(height: AppSpacing.xs),
        active.when(
          loading: () => const ListSkeleton(
            count: 2,
            height: 72,
            padding: EdgeInsets.zero,
          ),
          error: (error, _) => ZCard(child: ErrorState(error: error)),
          data: (page) {
            final shown = page.items
                .where((b) => !exclude.contains(b.id))
                .take(3)
                .toList();

            if (shown.isEmpty) {
              return ZCard(
                child: EmptyState(
                  compact: true,
                  icon: Icons.handshake_outlined,
                  title: page.isEmpty
                      ? 'No active bookings'
                      : 'Everything else is above',
                  message: page.isEmpty
                      ? 'Book a creator directly, or hire from a campaign.'
                      : 'Your other active bookings are waiting on you above.',
                  actionLabel: page.isEmpty ? 'Find creators' : null,
                  onAction: page.isEmpty
                      ? () => context.push(Routes.explore)
                      : null,
                ),
              );
            }

            return _RowGroup(
              children: [
                for (final booking in shown) _BookingRow(booking: booking),
              ],
            );
          },
        ),
      ],
    );
  }
}

class _BookingRow extends StatelessWidget {
  const _BookingRow({required this.booking});

  final Booking booking;

  @override
  Widget build(BuildContext context) {
    final when = booking.dateLabel.isNotEmpty
        ? booking.dateLabel
        : booking.deliveryDueAt != null
            ? 'Due ${Formatters.shortDate(booking.deliveryDueAt)}'
            : booking.code;

    return _TappableRow(
      onTap: () => context.push(Routes.booking(booking.id)),
      semanticsLabel: '${booking.title} with ${booking.counterparty.name}, '
          '${booking.statusLabel}, ${booking.amountLabel}',
      child: Row(
        children: [
          ZAvatar(
            imageUrl: booking.counterparty.avatarUrl,
            name: booking.counterparty.name,
            size: 44,
            isVerified: booking.counterparty.isVerified,
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  booking.counterparty.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleSmall,
                ),
                const SizedBox(height: 2),
                Text(
                  '${booking.title} · $when',
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
              Text(booking.amountLabel, style: AppTypography.labelLarge),
              const SizedBox(height: 4),
              StatusChip(
                label: booking.statusLabel,
                tone: booking.tone,
                dense: true,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Shared row chrome
// -----------------------------------------------------------------------------

/// Rows on one white card with hairlines between them — a short list reads
/// as one group instead of a stack of separate cards.
class _RowGroup extends StatelessWidget {
  const _RowGroup({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return ZCard(
      padding: EdgeInsets.zero,
      clip: true,
      child: Column(
        children: [
          for (var i = 0; i < children.length; i++) ...[
            if (i > 0)
              Divider(
                height: 1,
                indent: AppSpacing.md + 44 + AppSpacing.sm,
                color: AppColors.outlineVariant,
              ),
            children[i],
          ],
        ],
      ),
    );
  }
}

class _TappableRow extends StatelessWidget {
  const _TappableRow({
    required this.child,
    required this.onTap,
    required this.semanticsLabel,
  });

  final Widget child;
  final VoidCallback onTap;
  final String semanticsLabel;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: semanticsLabel,
      excludeSemantics: true,
      onTap: onTap,
      child: InkWell(
        onTap: onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 68),
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.md,
              vertical: AppSpacing.sm,
            ),
            child: child,
          ),
        ),
      ),
    );
  }
}

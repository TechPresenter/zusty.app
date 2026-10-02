import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/theme/app_theme.dart';
import '../../models/campaign.dart';
import '../../providers/opportunity_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/campaign_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_tabs.dart';
import 'widgets/opportunity_states.dart';

/// My applications — every brief the creator was invited to or applied for,
/// split by where it stands: still in play, won, or passed on.
///
/// Each tab is one request (the server takes several statuses at once), and
/// every response carries the totals for all statuses, so the tab badges are
/// right without fetching the other tabs.
class MyApplicationsScreen extends StatelessWidget {
  const MyApplicationsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return ZScaffold(
      padded: false,
      appBar: ZAppBar(
        title: 'My applications',
        actions: [
          IconButton(
            tooltip: 'Browse opportunities',
            icon: const Icon(Icons.travel_explore_rounded),
            onPressed: () => context.push(Routes.creatorCampaigns),
          ),
        ],
      ),
      body: const CreatorOnlyGate(
        child: DefaultTabController(
          length: 3,
          child: _Tabs(),
        ),
      ),
    );
  }
}

class _Tabs extends ConsumerWidget {
  const _Tabs();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Any tab's response carries every status's total; the Active tab is the
    // one always loaded first.
    final counts = ref
        .watch(myApplicationsProvider(ApplicationTab.active))
        .valueOrNull;

    return Column(
      children: [
        ZSegmentedTabs(
          labels: [for (final tab in ApplicationTab.values) tab.label],
          counts: counts == null
              ? null
              : [
                  for (final tab in ApplicationTab.values)
                    counts.countOf(tab.statuses),
                ],
        ),
        Expanded(
          child: TabBarView(
            children: [
              for (final tab in ApplicationTab.values) _ApplicationList(tab: tab),
            ],
          ),
        ),
      ],
    );
  }
}

class _ApplicationList extends ConsumerWidget {
  const _ApplicationList({required this.tab});

  final ApplicationTab tab;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final applications = ref.watch(myApplicationsProvider(tab));
    final margin = AppBreakpoints.pageMargin(context);

    return ZRefresh(
      onRefresh: () => ref.refresh(myApplicationsProvider(tab).future),
      child: applications.when(
        loading: () => ListSkeleton(
          count: 3,
          height: 150,
          padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, 0),
        ),
        error: (error, _) => _Scrollable(
          child: OpportunityErrorView(
            error: error,
            onRetry: () => ref.invalidate(myApplicationsProvider(tab)),
          ),
        ),
        data: (list) {
          if (list.isEmpty) return _Scrollable(child: _empty(context));

          return RevealScope(
            child: ListView.separated(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: EdgeInsets.fromLTRB(
                margin,
                AppSpacing.md,
                margin,
                AppSpacing.xl,
              ),
              itemCount: list.items.length + (list.hasMore ? 1 : 0),
              separatorBuilder: (_, _) => const SizedBox(height: AppSpacing.sm),
              itemBuilder: (_, i) {
                if (i == list.items.length) {
                  return Padding(
                    padding: const EdgeInsets.only(top: AppSpacing.sm),
                    child: Text(
                      'Showing your ${list.items.length} most recent',
                      textAlign: TextAlign.center,
                      style: AppTypography.labelMedium.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                  );
                }
                final item = list.items[i];
                return Reveal(
                  revealKey: item.id,
                  index: i,
                  child: _ApplicationCard(item: item),
                );
              },
            ),
          );
        },
      ),
    );
  }

  Widget _empty(BuildContext context) => switch (tab) {
        ApplicationTab.active => EmptyState(
            icon: Icons.send_outlined,
            title: 'No active applications',
            message: 'Find a brief that fits your audience and send your '
                'first pitch. Invitations from brands show up here too.',
            actionLabel: 'Browse opportunities',
            onAction: () => context.push(Routes.creatorCampaigns),
          ),
        ApplicationTab.hired => const EmptyState(
            icon: Icons.workspace_premium_outlined,
            title: 'No hires yet',
            message: 'When a brand hires you, the campaign and its booking '
                'appear here.',
          ),
        ApplicationTab.declined => const EmptyState(
            icon: Icons.inbox_outlined,
            title: 'Nothing declined',
            message: 'Briefs a brand passed on are kept here, with their note '
                'when they leave one.',
          ),
      };
}

/// Lets an empty or error state still be pulled to refresh.
class _Scrollable extends StatelessWidget {
  const _Scrollable({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return CustomScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      slivers: [
        SliverFillRemaining(hasScrollBody: false, child: child),
      ],
    );
  }
}

/// One application: the brief, what the creator asked for, where it stands,
/// and the one next step when there is one.
class _ApplicationCard extends ConsumerWidget {
  const _ApplicationCard({required this.item});

  final CampaignApplication item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final campaign = item.campaign;
    final application = item.application;
    final style = campaignCategoryStyle(ref, campaign);
    final brand = campaign.brand;
    final reason = application.declineReason?.trim();
    final activity = application.lastActivityAt;
    final hiredWithBooking = application.status == RecipientStatus.hired &&
        application.bookingId != null;
    final campaignClosed = !campaign.status.isLive;

    return ZCard(
      onTap: () => _open(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: style.tint.withValues(alpha: 0.14),
                  borderRadius: AppRadius.mdAll,
                ),
                child: Icon(style.icon, color: style.tint, size: 22),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      campaign.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.titleSmall.copyWith(height: 1.3),
                    ),
                    if (brand != null) ...[
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          ZAvatar(
                            imageUrl: brand.avatarUrl,
                            name: brand.name,
                            size: 18,
                          ),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(
                              brand.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: AppTypography.bodySmall,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: AppSpacing.xs),
              StatusChip(
                label: application.label,
                tone: application.tone,
                icon: application.icon,
                dense: true,
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Divider(height: 1, color: AppColors.outlineVariant),
          const SizedBox(height: AppSpacing.sm),
          Row(
            children: [
              Expanded(
                child: _Figure(
                  label: 'Your rate',
                  value: application.quoteLabel ?? 'Not quoted',
                  emphasise: application.quoteLabel != null,
                ),
              ),
              Expanded(
                child: _Figure(
                  label: application.status == RecipientStatus.invited
                      ? 'Invited'
                      : 'Applied',
                  value: activity == null
                      ? '—'
                      : DateFormat('d MMM yyyy').format(activity),
                ),
              ),
              Expanded(
                child: _Figure(label: 'Budget', value: campaign.displayBudget),
              ),
            ],
          ),
          if (application.status == RecipientStatus.declined &&
              reason != null &&
              reason.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            ZInfoBanner(
              tone: StatusTone.error,
              icon: Icons.format_quote_rounded,
              title: 'Note from the brand',
              message: reason,
            ),
          ],
          if (campaignClosed && !hiredWithBooking) ...[
            const SizedBox(height: AppSpacing.sm),
            CampaignMeta(
              icon: Icons.lock_clock_outlined,
              label: 'Campaign ${(campaign.statusLabel ?? campaign.status.label).toLowerCase()}',
            ),
          ],
          if (hiredWithBooking) ...[
            const SizedBox(height: AppSpacing.sm),
            ZButton.tonal(
              label: 'Open booking',
              icon: Icons.work_outline_rounded,
              size: ZButtonSize.medium,
              onPressed: () =>
                  context.push(Routes.booking(application.bookingId!)),
            ),
          ] else if (application.canApply && campaign.isAcceptingApplications) ...[
            const SizedBox(height: AppSpacing.sm),
            ZButton(
              label: 'Respond to invite',
              trailingIcon: Icons.arrow_forward_rounded,
              size: ZButtonSize.medium,
              onPressed: () =>
                  context.push(Routes.applyToOpportunity(campaign.id)),
            ),
          ],
        ],
      ),
    );
  }

  /// Opens the brief — unless it has closed, in which case the public brief
  /// is gone (the API answers 404 for a closed campaign), so the card says so
  /// instead of opening onto "not found". A hire still has its booking.
  void _open(BuildContext context) {
    final campaign = item.campaign;
    final application = item.application;

    if (campaign.status.isLive) {
      context.push(Routes.opportunity(campaign.id));
    } else if (application.bookingId != null) {
      context.push(Routes.booking(application.bookingId!));
    } else {
      showZSnack(
        context,
        'This campaign has closed, so its brief is no longer public.',
      );
    }
  }
}

class _Figure extends StatelessWidget {
  const _Figure({
    required this.label,
    required this.value,
    this.emphasise = false,
  });

  final String label;
  final String value;
  final bool emphasise;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: AppTypography.labelSmall.copyWith(
            color: AppColors.onSurfaceVariant,
            fontWeight: FontWeight.w500,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: AppTypography.labelLarge.copyWith(
            fontSize: 13,
            color: emphasise ? AppColors.primaryDark : AppColors.onSurface,
          ),
        ),
      ],
    );
  }
}

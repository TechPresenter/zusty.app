import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_failure.dart';
import '../../../core/theme/app_theme.dart';
import '../../../models/campaign.dart';
import '../../../providers/app_config_provider.dart';
import '../../../providers/campaign_provider.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/campaign_card.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_dialogs.dart';
import 'brand_campaign_view.dart';

/// One of the brand's own briefs in the Campaigns list.
///
/// Built on the same cover panel creators see on the board, so a brand
/// recognises its brief in both places, but read from the owner's side: the
/// figures that matter are budget, applicants and hires, and the action row
/// offers the next thing to do for the brief's stage — finish and publish a
/// draft, or work the applicants of a live one.
class BrandCampaignCard extends ConsumerWidget {
  const BrandCampaignCard({super.key, required this.campaign});

  final Campaign campaign;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = campaign.brandStatus;
    final schedule = campaign.scheduleLabel;

    return ZCard(
      padding: EdgeInsets.zero,
      clip: true,
      onTap: () => context.push(Routes.campaign(campaign.id)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CampaignCoverPanel(
            campaign: campaign,
            height: 84,
            showStatus: false,
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.md,
              AppSpacing.sm,
              AppSpacing.md,
              AppSpacing.md,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text(
                        campaign.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.titleMedium.copyWith(
                          fontSize: 17,
                          height: 1.3,
                        ),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    StatusChip(
                      label: status.label,
                      tone: status.tone,
                      icon: status.icon,
                      dense: true,
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.xs),
                Wrap(
                  spacing: AppSpacing.sm,
                  runSpacing: 6,
                  children: [
                    CampaignMeta(
                      icon: Icons.event_outlined,
                      label: schedule.label,
                      tone: schedule.tone,
                    ),
                    CampaignMeta(
                      icon: campaign.isPublic
                          ? Icons.public_rounded
                          : Icons.lock_outline_rounded,
                      label: campaign.isPublic ? 'Public' : 'Invite-only',
                    ),
                    if (campaign.code.isNotEmpty)
                      CampaignMeta(icon: Icons.tag_rounded, label: campaign.code),
                  ],
                ),
                const SizedBox(height: AppSpacing.sm),
                CampaignMetricStrip(
                  items: [
                    (campaign.compactBudget, 'Budget / creator'),
                    ('${campaign.applicationsCount}', 'Applicants'),
                    ('${campaign.hiredCount}', 'Hired'),
                  ],
                ),
                _CardActions(campaign: campaign),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Three figures on a blush tray, divided by hairlines. The first cell is
/// wider because it usually holds a range.
class CampaignMetricStrip extends StatelessWidget {
  const CampaignMetricStrip({super.key, required this.items});

  /// Value / label pairs.
  final List<(String, String)> items;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: AppSpacing.xs + 2,
      ),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: AppRadius.lgAll,
      ),
      child: IntrinsicHeight(
        child: Row(
          children: [
            for (var i = 0; i < items.length; i++) ...[
              if (i > 0)
                VerticalDivider(
                  width: AppSpacing.md,
                  thickness: 1,
                  indent: 2,
                  endIndent: 2,
                  color: AppColors.outlineVariant,
                ),
              Expanded(
                flex: i == 0 ? 4 : 3,
                child: Semantics(
                  label: '${items[i].$2}: ${items[i].$1}',
                  excludeSemantics: true,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        items[i].$1,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.labelLarge.copyWith(
                          color: i == 0
                              ? AppColors.primaryDark
                              : AppColors.onSurface,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        items[i].$2,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.labelSmall.copyWith(
                          color: AppColors.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// The next step for the brief's stage. Closed briefs get none — the card
/// itself opens the record.
class _CardActions extends ConsumerWidget {
  const _CardActions({required this.campaign});

  final Campaign campaign;

  Future<void> _publish(BuildContext context, WidgetRef ref) async {
    final confirmed = await showZConfirm(
      context,
      title: 'Publish this campaign?',
      message: campaign.isPublic
          ? 'It goes live on the creator board straight away, and matching '
              'creators can start applying.'
          : 'It is invite-only, so only creators you invite will see it.',
      confirmLabel: 'Publish',
      icon: Icons.campaign_rounded,
    );
    if (!confirmed || !context.mounted) return;

    try {
      await ref.read(campaignActionsProvider).publish(campaign.id);
      if (context.mounted) {
        showZSnack(context, '"${campaign.title}" is live.');
      }
    } on ApiFailure catch (e) {
      if (!context.mounted) return;
      // The server refuses a brief with no budget or description; the fix is
      // one tap away.
      showZSnack(
        context,
        e.message,
        isError: true,
        actionLabel: 'Edit',
        onAction: () => context.push(Routes.editCampaign(campaign.id)),
      );
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ai = ref.watch(aiFeaturesProvider);

    final Widget? row = switch (campaign.status) {
      CampaignStatus.draft => Row(
          children: [
            Expanded(
              child: ZButton.outlined(
                label: 'Edit',
                icon: Icons.edit_outlined,
                size: ZButtonSize.small,
                onPressed: () =>
                    context.push(Routes.editCampaign(campaign.id)),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: ZButton(
                label: 'Publish',
                icon: Icons.campaign_rounded,
                size: ZButtonSize.small,
                onPressed: () => _publish(context, ref),
              ),
            ),
          ],
        ),
      CampaignStatus.open || CampaignStatus.inProgress => Row(
          children: [
            Expanded(
              child: ZButton.outlined(
                label: 'Applicants',
                icon: Icons.people_alt_outlined,
                size: ZButtonSize.small,
                onPressed: () => context.push(Routes.campaign(campaign.id)),
              ),
            ),
            if (ai.creatorMatch) ...[
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: ZButton.tonal(
                  label: 'AI matches',
                  icon: Icons.auto_awesome_rounded,
                  size: ZButtonSize.small,
                  onPressed: () =>
                      context.push(Routes.campaignMatches(campaign.id)),
                ),
              ),
            ],
          ],
        ),
      _ => null,
    };

    if (row == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.sm),
      child: row,
    );
  }
}

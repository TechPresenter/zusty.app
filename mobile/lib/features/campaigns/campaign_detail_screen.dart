import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/campaign.dart';
import '../../providers/app_config_provider.dart';
import '../../providers/campaign_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/campaign_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_tabs.dart';
import 'widgets/applicant_card.dart';
import 'widgets/brand_campaign_card.dart';
import 'widgets/brand_campaign_view.dart';
import 'widgets/invite_creators_sheet.dart';

/// Campaign detail — the brief plus its applicant pipeline.
///
/// The pipeline is the working surface here: a brand's job on this screen is
/// to move creators from Applied → Shortlisted → Hired, so the applicants come
/// straight after the summary, grouped by stage with the ones waiting on a
/// decision first, and each card carries its own actions. The brief itself
/// is reference material and sits below.
class CampaignDetailScreen extends ConsumerStatefulWidget {
  const CampaignDetailScreen({super.key, required this.campaignId});

  final int campaignId;

  @override
  ConsumerState<CampaignDetailScreen> createState() =>
      _CampaignDetailScreenState();
}

class _CampaignDetailScreenState extends ConsumerState<CampaignDetailScreen> {
  /// Null shows every stage, grouped.
  RecipientStatus? _filter;

  /// Stages in the order a brand works through them: decisions first.
  static const _stageOrder = [
    RecipientStatus.applied,
    RecipientStatus.shortlisted,
    RecipientStatus.hired,
    RecipientStatus.invited,
    RecipientStatus.declined,
  ];

  Future<void> _refresh() async {
    ref.invalidate(campaignDetailProvider(widget.campaignId));
    try {
      await ref.read(campaignDetailProvider(widget.campaignId).future);
    } catch (_) {
      // The screen renders the error itself.
    }
  }

  Future<void> _publish(Campaign campaign) async {
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
    if (!confirmed || !mounted) return;

    try {
      await ref.read(campaignActionsProvider).publish(campaign.id);
      if (mounted) {
        showZSnack(context, 'Campaign is live — creators can now apply.');
      }
    } on ApiFailure catch (e) {
      if (!mounted) return;
      showZSnack(
        context,
        e.message,
        isError: true,
        actionLabel: 'Edit',
        onAction: () => context.push(Routes.editCampaign(campaign.id)),
      );
    }
  }

  void _invite(Campaign campaign) =>
      showInviteCreatorsSheet(context, campaign: campaign);

  @override
  Widget build(BuildContext context) {
    final campaign = ref.watch(campaignDetailProvider(widget.campaignId));

    return ZScaffold(
      padded: false,
      appBar: ZAppBar(
        title: 'Campaign',
        actions: [
          campaign.maybeWhen(
            data: (data) => _OverflowMenu(
              campaign: data,
              onPublish: () => _publish(data),
            ),
            orElse: () => const SizedBox.shrink(),
          ),
        ],
      ),
      bottomBar: campaign.maybeWhen(
        data: _bottomBar,
        orElse: () => null,
      ),
      body: campaign.when(
        loading: () => const _DetailSkeleton(),
        error: (error, _) => ErrorState(
          error: error,
          onRetry: () =>
              ref.invalidate(campaignDetailProvider(widget.campaignId)),
        ),
        data: (data) => ZRefresh(
          onRefresh: _refresh,
          child: RevealScope(child: _body(data)),
        ),
      ),
    );
  }

  Widget? _bottomBar(Campaign campaign) {
    final ai = ref.watch(aiFeaturesProvider);

    if (campaign.status == CampaignStatus.draft) {
      return Row(
        children: [
          Expanded(
            child: ZButton.outlined(
              label: 'Edit',
              icon: Icons.edit_outlined,
              size: ZButtonSize.medium,
              onPressed: () => context.push(Routes.editCampaign(campaign.id)),
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            flex: 2,
            child: ZButton(
              label: 'Publish campaign',
              icon: Icons.campaign_rounded,
              size: ZButtonSize.medium,
              onPressed: () => _publish(campaign),
            ),
          ),
        ],
      );
    }

    // A closed brief takes no more invitations; the server refuses them.
    if (!campaign.status.isLive) return null;

    if (!ai.creatorMatch) {
      return ZButton(
        label: 'Invite creators',
        icon: Icons.person_add_alt_rounded,
        onPressed: () => _invite(campaign),
      );
    }

    return Row(
      children: [
        Expanded(
          child: ZButton.outlined(
            label: 'Invite',
            icon: Icons.person_add_alt_rounded,
            size: ZButtonSize.medium,
            onPressed: () => _invite(campaign),
          ),
        ),
        const SizedBox(width: AppSpacing.xs),
        Expanded(
          flex: 2,
          child: ZButton(
            label: 'Find matches with AI',
            icon: Icons.auto_awesome_rounded,
            size: ZButtonSize.medium,
            onPressed: () => context.push(Routes.campaignMatches(campaign.id)),
          ),
        ),
      ],
    );
  }

  Widget _body(Campaign campaign) {
    final margin = AppBreakpoints.pageMargin(context);
    final recipients = campaign.recipients;
    final canInvite = campaign.status.isLive;
    final awaiting = campaign.awaitingReview;

    var index = 0;
    Widget reveal(Widget child) => Reveal(index: index++, child: child);

    final visible = _filter == null
        ? recipients
        : recipients.where((r) => r.status == _filter).toList();

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, margin, AppSpacing.xl),
      children: [
        reveal(_Header(campaign: campaign)),

        if (campaign.status == CampaignStatus.draft) ...[
          const SizedBox(height: AppSpacing.md),
          reveal(
            ZInfoBanner(
              tone: StatusTone.warning,
              icon: Icons.visibility_off_outlined,
              title: 'Draft — creators cannot see it yet',
              message: 'Publish when the brief is ready. Until then only '
                  'you can see it.',
              actionLabel: 'Publish',
              onAction: () => _publish(campaign),
            ),
          ),
        ] else if (!campaign.status.isLive) ...[
          const SizedBox(height: AppSpacing.md),
          reveal(
            const ZInfoBanner(
              tone: StatusTone.neutral,
              icon: Icons.lock_clock_outlined,
              message: 'This campaign is closed and no longer takes '
                  'applications. Hired creators keep their bookings.',
            ),
          ),
        ],

        // -- Pipeline ----------------------------------------------------
        const SizedBox(height: AppSpacing.lg),
        SectionHeader(
          title: 'Applicants',
          subtitle: recipients.isEmpty
              ? 'Nobody in the pipeline yet'
              : awaiting > 0
                  ? '$awaiting waiting for your decision'
                  : '${recipients.length} in your pipeline',
          actionLabel: canInvite ? 'Invite' : null,
          onAction: canInvite ? () => _invite(campaign) : null,
        ),
        if (recipients.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.xs),
          ZFilterPills(
            padding: EdgeInsets.zero,
            labels: [
              'All · ${recipients.length}',
              for (final stage in _stageOrder)
                '${stage.label} · ${campaign.countIn(stage)}',
            ],
            selected: _filter == null ? 0 : _stageOrder.indexOf(_filter!) + 1,
            onSelected: (i) => setState(
              () => _filter = i == 0 ? null : _stageOrder[i - 1],
            ),
          ),
        ],
        const SizedBox(height: AppSpacing.xs),
        if (recipients.isEmpty)
          ZCard(
            child: EmptyState(
              compact: true,
              icon: Icons.people_outline_rounded,
              title: 'No applicants yet',
              message: switch (campaign.status) {
                CampaignStatus.draft =>
                  'Publish the brief and applications will arrive here.',
                _ when !canInvite =>
                  'This campaign closed without applications.',
                _ when campaign.isPublic =>
                  'Creators matching your brief will show up here. Invite '
                      'a few to get things moving.',
                _ => 'This brief is invite-only. Invite creators to start '
                    'receiving applications.',
              },
              actionLabel: canInvite ? 'Invite creators' : null,
              onAction: canInvite ? () => _invite(campaign) : null,
            ),
          )
        else if (visible.isEmpty)
          ZCard(
            child: EmptyState(
              compact: true,
              icon: Icons.filter_alt_off_outlined,
              title: 'None ${_filter!.label.toLowerCase()}',
              message: 'Nobody is at this stage right now.',
              actionLabel: 'Show everyone',
              onAction: () => setState(() => _filter = null),
            ),
          )
        else if (_filter == null) ...[
          // Grouped by stage, each under its own heading.
          for (final stage in _stageOrder)
            if (campaign.countIn(stage) > 0) ...[
              PipelineGroupHeader(
                status: stage,
                count: campaign.countIn(stage),
              ),
              for (final recipient
                  in recipients.where((r) => r.status == stage))
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: ApplicantCard(
                    key: ValueKey('applicant-${recipient.id}'),
                    campaign: campaign,
                    recipient: recipient,
                  ),
                ),
            ],
        ] else ...[
          const SizedBox(height: AppSpacing.xs),
          for (final recipient in visible)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: ApplicantCard(
                key: ValueKey('applicant-${recipient.id}'),
                campaign: campaign,
                recipient: recipient,
              ),
            ),
        ],

        // -- The brief ---------------------------------------------------
        const SizedBox(height: AppSpacing.lg),
        const SectionHeader(title: 'The brief'),
        const SizedBox(height: AppSpacing.xs),
        _BriefCard(campaign: campaign),
      ],
    );
  }
}

// -----------------------------------------------------------------------------
// Header
// -----------------------------------------------------------------------------

/// Status, title and the headline numbers: budget, applicants, hires, and
/// the window.
class _Header extends StatelessWidget {
  const _Header({required this.campaign});

  final Campaign campaign;

  @override
  Widget build(BuildContext context) {
    final status = campaign.brandStatus;
    final schedule = campaign.scheduleLabel;

    return ZCard(
      padding: EdgeInsets.zero,
      clip: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          CampaignCoverPanel(
            campaign: campaign,
            height: 112,
            showStatus: false,
          ),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: AppSpacing.xs,
                  runSpacing: AppSpacing.xs,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    StatusChip(
                      label: status.label,
                      tone: status.tone,
                      icon: status.icon,
                    ),
                    StatusChip(
                      label: campaign.isPublic ? 'Public' : 'Invite-only',
                      icon: campaign.isPublic
                          ? Icons.public_rounded
                          : Icons.lock_outline_rounded,
                    ),
                    if (campaign.code.isNotEmpty)
                      Text(campaign.code, style: AppTypography.labelSmall),
                  ],
                ),
                const SizedBox(height: AppSpacing.sm),
                Semantics(
                  header: true,
                  child: Text(
                    campaign.title,
                    style: AppTypography.headlineSmall,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  [
                    campaign.deliverableLabel,
                    if (campaign.categoryName != null) campaign.categoryName!,
                  ].join(' · '),
                  style: AppTypography.bodySmall,
                ),
                const SizedBox(height: AppSpacing.md),
                CampaignMetricStrip(
                  items: [
                    (campaign.compactBudget, 'Budget / creator'),
                    ('${campaign.applicationsCount}', 'Applicants'),
                    ('${campaign.hiredCount}', 'Hired'),
                  ],
                ),
                const SizedBox(height: AppSpacing.sm),
                Row(
                  children: [
                    Icon(
                      Icons.event_outlined,
                      size: 16,
                      color: AppColors.onSurfaceVariant,
                    ),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        campaign.timelineLabel,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySmall
                            .copyWith(color: AppColors.onSurface),
                      ),
                    ),
                    if (schedule.tone != StatusTone.neutral) ...[
                      const SizedBox(width: AppSpacing.xs),
                      StatusChip(
                        label: schedule.label,
                        tone: schedule.tone,
                        dense: true,
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// The brief
// -----------------------------------------------------------------------------

class _BriefCard extends StatefulWidget {
  const _BriefCard({required this.campaign});

  final Campaign campaign;

  @override
  State<_BriefCard> createState() => _BriefCardState();
}

class _BriefCardState extends State<_BriefCard> {
  bool _expanded = false;

  Future<void> _open(String url) async {
    final uri = Uri.tryParse(url);
    final opened = uri != null &&
        await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!opened && mounted) {
      showZSnack(context, 'Could not open that link.', isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final campaign = widget.campaign;
    final requirements = campaign.requirements;
    final description = campaign.description.trim();
    final isLong = description.length > 280;

    Widget label(String text) => Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.xs),
          child: Text(
            text,
            style: AppTypography.labelLarge.copyWith(color: AppColors.onSurface),
          ),
        );

    return ZCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (description.isNotEmpty) ...[
            Text(
              description,
              maxLines: _expanded || !isLong ? null : 6,
              overflow: _expanded || !isLong ? null : TextOverflow.ellipsis,
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
                height: 1.55,
              ),
            ),
            if (isLong)
              TextButton(
                onPressed: () => setState(() => _expanded = !_expanded),
                style: TextButton.styleFrom(
                  padding: EdgeInsets.zero,
                  minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
                ),
                child: Text(_expanded ? 'Show less' : 'Read the full brief'),
              ),
          ] else
            Text(
              'No description yet.',
              style: AppTypography.bodyMedium
                  .copyWith(color: AppColors.onSurfaceVariant),
            ),
          const SizedBox(height: AppSpacing.md),
          Divider(height: 1, color: AppColors.outlineVariant),
          const SizedBox(height: AppSpacing.md),

          label('Who can apply'),
          if (requirements.summary.isEmpty)
            Text('Open to every creator', style: AppTypography.bodySmall)
          else
            Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              children: [
                for (final rule in requirements.summary)
                  ZChip(label: rule, color: AppColors.secondary),
              ],
            ),

          // Terms set outside the app's form (seeded or admin-made briefs):
          // read-only here, but the brand should see what creators see.
          if (requirements.deliverables.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.md),
            label('Shot list'),
            for (final item in requirements.deliverables)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Icon(
                        Icons.check_circle_outline_rounded,
                        size: 16,
                        color: AppColors.primary,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    Expanded(
                      child: Text(item, style: AppTypography.bodySmall
                          .copyWith(color: AppColors.onSurface)),
                    ),
                  ],
                ),
              ),
          ],
          if (requirements.hasTerms) ...[
            const SizedBox(height: AppSpacing.md),
            label('Terms'),
            Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              children: [
                if (requirements.usageRightsDays != null)
                  StatusChip(
                    label:
                        '${Formatters.count(requirements.usageRightsDays, 'day')} usage rights',
                    icon: Icons.copyright_rounded,
                  ),
                if (requirements.exclusivityDays != null)
                  StatusChip(
                    label:
                        '${Formatters.count(requirements.exclusivityDays, 'day')} exclusivity',
                    icon: Icons.block_rounded,
                  ),
                if (requirements.barterOnly)
                  const StatusChip(
                    label: 'Paid in product',
                    icon: Icons.card_giftcard_rounded,
                  ),
              ],
            ),
          ],

          if (campaign.referenceUrls.isNotEmpty || campaign.briefUrl != null) ...[
            const SizedBox(height: AppSpacing.md),
            label('References'),
            if (campaign.briefUrl != null)
              _LinkRow(
                icon: Icons.description_outlined,
                label: 'Brief document',
                url: campaign.briefUrl!,
                onTap: () => _open(campaign.briefUrl!),
              ),
            for (final url in campaign.referenceUrls)
              _LinkRow(
                icon: Icons.link_rounded,
                label: Uri.tryParse(url)?.host ?? url,
                url: url,
                onTap: () => _open(url),
              ),
          ],
        ],
      ),
    );
  }
}

class _LinkRow extends StatelessWidget {
  const _LinkRow({
    required this.icon,
    required this.label,
    required this.url,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final String url;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      link: true,
      label: '$label, $url',
      excludeSemantics: true,
      onTap: onTap,
      child: InkWell(
        onTap: onTap,
        borderRadius: AppRadius.mdAll,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: AppSpacing.minTouch),
          child: Row(
            children: [
              Container(
                width: 32,
                height: 32,
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerLow,
                  borderRadius: AppRadius.mdAll,
                ),
                child: Icon(icon, size: 16, color: AppColors.secondary),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelLarge,
                    ),
                    Text(
                      url,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelSmall.copyWith(
                        color: AppColors.secondary,
                      ),
                    ),
                  ],
                ),
              ),
              Icon(
                Icons.open_in_new_rounded,
                size: 16,
                color: AppColors.outline,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// App bar menu
// -----------------------------------------------------------------------------

class _OverflowMenu extends ConsumerWidget {
  const _OverflowMenu({required this.campaign, required this.onPublish});

  final Campaign campaign;
  final VoidCallback onPublish;

  Future<void> _close(BuildContext context, WidgetRef ref) async {
    final confirmed = await showZConfirm(
      context,
      title: 'Close this campaign?',
      message: 'It stops taking applications, and creators still waiting '
          'are told it has closed. Hired creators keep their bookings.',
      confirmLabel: 'Close campaign',
      destructive: true,
      icon: Icons.lock_clock_outlined,
    );
    if (!confirmed || !context.mounted) return;

    try {
      await ref.read(campaignActionsProvider).close(campaign.id);
      if (context.mounted) showZSnack(context, 'Campaign closed.');
    } on ApiFailure catch (e) {
      if (context.mounted) showZSnack(context, e.message, isError: true);
    }
  }

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    final confirmed = await showZConfirm(
      context,
      title: 'Delete this campaign?',
      message: 'The brief and its applicant list are removed for good. A '
          'campaign with hired creators cannot be deleted — close it instead.',
      confirmLabel: 'Delete campaign',
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );
    if (!confirmed || !context.mounted) return;

    try {
      await ref.read(campaignActionsProvider).delete(campaign.id);
      if (!context.mounted) return;
      showZSnack(context, 'Campaign deleted.');
      context.canPop() ? context.pop() : context.go(Routes.campaigns);
    } on ApiFailure catch (e) {
      if (context.mounted) showZSnack(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return PopupMenuButton<String>(
      tooltip: 'Campaign actions',
      icon: const Icon(Icons.more_vert_rounded),
      shape: RoundedRectangleBorder(borderRadius: AppRadius.lgAll),
      onSelected: (value) {
        switch (value) {
          case 'edit':
            context.push(Routes.editCampaign(campaign.id));
          case 'publish':
            onPublish();
          case 'close':
            _close(context, ref);
          case 'delete':
            _delete(context, ref);
        }
      },
      itemBuilder: (_) => [
        if (campaign.status.isEditable)
          _item('edit', Icons.edit_outlined, 'Edit campaign'),
        if (campaign.status == CampaignStatus.draft)
          _item('publish', Icons.campaign_outlined, 'Publish'),
        if (campaign.status.isLive)
          _item('close', Icons.lock_clock_outlined, 'Close campaign'),
        _item('delete', Icons.delete_outline_rounded, 'Delete', destructive: true),
      ],
    );
  }

  static PopupMenuItem<String> _item(
    String value,
    IconData icon,
    String label, {
    bool destructive = false,
  }) {
    final color = destructive ? AppColors.error : AppColors.onSurface;
    return PopupMenuItem(
      value: value,
      child: Row(
        children: [
          Icon(icon, size: 20, color: destructive ? color : AppColors.onSurfaceVariant),
          const SizedBox(width: AppSpacing.sm),
          Text(label, style: AppTypography.bodyMedium.copyWith(color: color)),
        ],
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Loading
// -----------------------------------------------------------------------------

class _DetailSkeleton extends StatelessWidget {
  const _DetailSkeleton();

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);

    return ListView(
      physics: const NeverScrollableScrollPhysics(),
      padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, margin, AppSpacing.xl),
      children: const [
        ZShimmer(height: 300, borderRadius: AppRadius.xlAll),
        SizedBox(height: AppSpacing.lg),
        ZShimmer(width: 140, height: 18, borderRadius: AppRadius.smAll),
        ListSkeleton(count: 2, height: 180),
      ],
    );
  }
}

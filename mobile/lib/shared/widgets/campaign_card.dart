import 'package:collection/collection.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/campaign.dart';
import '../../models/category.dart';
import '../../providers/discovery_provider.dart';
import '../../router/routes.dart';
import 'platform_icon.dart';
import 'status_chip.dart';
import 'z_avatar.dart';
import 'z_button.dart';
import 'z_card.dart';
import 'z_image.dart';

// -----------------------------------------------------------------------------
// Creator-side presentation of a campaign
//
// Shared by the card and the opportunity screens so a deadline, a status or an
// application outcome reads the same everywhere a creator meets it.
// -----------------------------------------------------------------------------

/// A label plus the tone to draw it in.
typedef ToneLabel = ({String label, StatusTone tone});

extension CampaignCreatorView on Campaign {
  /// Where the brief stands for a creator browsing: open to applications,
  /// running (visible, but no longer taking applicants), or closed.
  ///
  /// A brief still marked open after its end date is not called "Open" — a
  /// green "Open" beside "Ended 13 Sep" contradicts itself. It reads "Past
  /// end date" instead: the brand has not closed it, so applying stays
  /// possible, but the creator sees that the date has gone.
  ToneLabel get boardStatus => switch (status) {
        CampaignStatus.open when (daysLeft ?? 0) < 0 =>
          (label: 'Past end date', tone: StatusTone.warning),
        CampaignStatus.open => (label: 'Open', tone: StatusTone.success),
        CampaignStatus.inProgress => (label: 'In progress', tone: StatusTone.info),
        CampaignStatus.draft => (label: 'Draft', tone: StatusTone.neutral),
        _ => (label: statusLabel ?? status.label, tone: StatusTone.neutral),
      };

  /// "Apply by 12 Oct", "Ends in 3 days", "Ends today".
  ///
  /// Driven by `ends_on`, the only deadline the API enforces against. A date
  /// that has passed on a brief still marked open is shown as it is — "Ended
  /// 13 Sep" — rather than hidden: the brand has not closed it and the server
  /// still accepts applications, so the creator should see both facts
  /// ([boardStatus] then reads "Past end date", not "Open").
  ToneLabel get deadline {
    final days = daysLeft;
    if (days == null) return (label: 'Flexible timeline', tone: StatusTone.neutral);
    final date = DateFormat('d MMM').format(endsOn!);
    if (days < 0) return (label: 'Ended $date', tone: StatusTone.neutral);
    if (days == 0) return (label: 'Ends today', tone: StatusTone.warning);
    if (days == 1) return (label: 'Ends tomorrow', tone: StatusTone.warning);
    if (days <= 7) return (label: 'Ends in $days days', tone: StatusTone.warning);
    return (label: 'Apply by $date', tone: StatusTone.neutral);
  }

  /// "Mumbai, Delhi +4" — the brief's target cities, or null when it names
  /// none (which means the brand did not restrict location, not "remote").
  String? get locationLabel {
    final cities = requirements.cities;
    if (cities.isEmpty) return null;
    if (cities.length <= 2) return cities.join(', ');
    return '${cities.take(2).join(', ')} +${cities.length - 2}';
  }

  /// "23 applied", or an invitation to be first.
  String get applicantsLabel => switch (applicationsCount) {
        0 => 'Be the first to apply',
        1 => '1 applied',
        final n => '${Formatters.compact(n)} applied',
      };

  /// Published in the last three days.
  bool get isNew {
    final created = createdAt;
    return created != null &&
        DateTime.now().difference(created) < const Duration(days: 3);
  }

  /// The creator may submit an application right now: the brief is open and
  /// they either have nothing on it yet or hold an unanswered invitation.
  bool get canApply =>
      isAcceptingApplications &&
      (myApplication == null || myApplication!.canApply);
}

extension MyApplicationView on MyApplication {
  IconData get icon => switch (status) {
        RecipientStatus.invited => Icons.mark_email_unread_rounded,
        RecipientStatus.applied => Icons.hourglass_top_rounded,
        RecipientStatus.shortlisted => Icons.star_rounded,
        RecipientStatus.hired => Icons.verified_rounded,
        RecipientStatus.declined => Icons.do_not_disturb_on_rounded,
      };

  StatusTone get tone => status.tone;

  /// One plain sentence on what the status means and what happens next.
  String get explanation => switch (status) {
        RecipientStatus.invited =>
          'The brand invited you to this brief. Send your rate and pitch to apply.',
        RecipientStatus.applied =>
          'Your application is with the brand. You will be notified when they respond.',
        RecipientStatus.shortlisted =>
          'You made the shortlist. The brand is choosing who to hire.',
        RecipientStatus.hired => bookingId == null
            ? 'You are hired. The brand is setting up the booking.'
            : 'You are hired. The work is tracked in your booking.',
        RecipientStatus.declined =>
          'The brand went with other creators this time.',
      };
}

/// The resolved category for a campaign — its admin-set colour and icon, or
/// the brand defaults while categories load or when the brief has none.
({Color tint, IconData icon}) campaignCategoryStyle(WidgetRef ref, Campaign campaign) {
  final categories = ref.watch(categoriesProvider).valueOrNull;
  final Category? category =
      categories?.firstWhereOrNull((c) => c.id == campaign.categoryId);

  return (
    tint: category?.color ?? AppColors.primary,
    icon: category?.iconData ?? Icons.campaign_rounded,
  );
}

// -----------------------------------------------------------------------------
// Cover panel
// -----------------------------------------------------------------------------

/// The campaign's "image": a panel tinted with the category colour, its icon
/// drawn large and faint as a watermark, and the platform and deliverables
/// pinned to the bottom.
///
/// The API has no cover image for a brief, and a stock photo would claim a
/// look the brand never chose — so the panel is honest decoration built from
/// what the brief does say: what kind of work it is, and where it runs.
class CampaignCoverPanel extends ConsumerWidget {
  const CampaignCoverPanel({
    super.key,
    required this.campaign,
    this.height = 112,
    this.borderRadius = BorderRadius.zero,
    this.showStatus = true,
  });

  final Campaign campaign;
  final double height;
  final BorderRadius borderRadius;

  /// Shows the board status chip top-right. The detail hero turns it off
  /// because it shows the status beside the title instead.
  final bool showStatus;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final style = campaignCategoryStyle(ref, campaign);
    final base = AppColors.surfaceContainerLowest;
    final status = campaign.boardStatus;
    final large = height >= 160;

    return Semantics(
      container: true,
      child: ClipRRect(
        borderRadius: borderRadius,
        child: Container(
          height: height,
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                Color.alphaBlend(style.tint.withValues(alpha: 0.24), base),
                Color.alphaBlend(style.tint.withValues(alpha: 0.08), base),
              ],
            ),
          ),
          child: Stack(
            children: [
              // Two soft discs give the flat tint some depth.
              Positioned(
                right: -height * 0.35,
                top: -height * 0.55,
                child: _Disc(size: height * 1.3, color: style.tint, alpha: 0.10),
              ),
              Positioned(
                left: -height * 0.3,
                bottom: -height * 0.7,
                child: _Disc(size: height * 1.1, color: style.tint, alpha: 0.06),
              ),
              Positioned(
                right: AppSpacing.md,
                bottom: -height * 0.12,
                child: Icon(
                  style.icon,
                  size: height * 0.85,
                  color: style.tint.withValues(alpha: 0.16),
                ),
              ),
              Padding(
                padding: EdgeInsets.all(large ? AppSpacing.md : AppSpacing.sm),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: _GlassPill(
                            icon: style.icon,
                            iconColor: style.tint,
                            label: campaign.categoryName ?? 'Campaign',
                          ),
                        ),
                        if (campaign.isNew) ...[
                          const SizedBox(width: AppSpacing.xs),
                          const StatusChip(
                            label: 'New',
                            tone: StatusTone.warning,
                            dense: true,
                          ),
                        ],
                        if (showStatus) ...[
                          const Spacer(),
                          const SizedBox(width: AppSpacing.xs),
                          StatusChip(
                            label: status.label,
                            tone: status.tone,
                            dense: true,
                          ),
                        ],
                      ],
                    ),
                    const Spacer(),
                    _GlassPill(
                      leading: PlatformIcon(
                        platform: campaign.platform,
                        // `multi` is not a social network, so it has no
                        // taxonomy term; a globe reads as "several".
                        iconName:
                            campaign.platform == 'multi' ? 'external' : null,
                        size: 20,
                      ),
                      label: campaign.platform == 'multi'
                          ? '${campaign.deliverableShortLabel} · Multi-platform'
                          : '${campaign.deliverableShortLabel} · ${campaign.platformLabel}',
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Disc extends StatelessWidget {
  const _Disc({required this.size, required this.color, required this.alpha});

  final double size;
  final Color color;
  final double alpha;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: color.withValues(alpha: alpha),
        ),
      );
}

/// A small frosted pill sitting on the cover panel.
class _GlassPill extends StatelessWidget {
  const _GlassPill({
    required this.label,
    this.icon,
    this.iconColor,
    this.leading,
  });

  final String label;
  final IconData? icon;
  final Color? iconColor;
  final Widget? leading;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.fromLTRB(
        leading != null ? 4 : AppSpacing.xs,
        4,
        AppSpacing.sm,
        4,
      ),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest.withValues(alpha: 0.88),
        borderRadius: AppRadius.pill,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (leading != null) ...[leading!, const SizedBox(width: 6)],
          if (icon != null) ...[
            Icon(icon, size: 14, color: iconColor),
            const SizedBox(width: 4),
          ],
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.labelMedium.copyWith(
                color: AppColors.onSurface,
                fontSize: 12,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// The card
// -----------------------------------------------------------------------------

/// A brief on the creator's opportunities board.
///
/// Reads top to bottom the way a creator decides: what kind of work and where
/// (the cover), who it is for (brand), what it is (title), whether they
/// qualify (location, follower floor), when (deadline), and what it pays —
/// with the one action that matters beside the money.
///
/// Built to hold at 360dp: every text run is single- or two-line with
/// ellipsis, and the bottom row gives the CTA its natural width and the
/// budget whatever is left.
class CampaignCard extends StatelessWidget {
  const CampaignCard({
    super.key,
    required this.campaign,
    this.onTap,
    this.onApply,
  });

  final Campaign campaign;

  /// Defaults to opening the opportunity.
  final VoidCallback? onTap;

  /// Defaults to opening the apply form.
  final VoidCallback? onApply;

  @override
  Widget build(BuildContext context) {
    final brand = campaign.brand;
    final application = campaign.myApplication;
    final deadline = campaign.deadline;
    final minFollowers = campaign.requirements.minFollowers;

    return ZCard(
      padding: EdgeInsets.zero,
      clip: true,
      onTap: onTap ?? () => context.push(Routes.opportunity(campaign.id)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CampaignCoverPanel(campaign: campaign),
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
                if (brand != null)
                  Row(
                    children: [
                      ZAvatar(
                        imageUrl: brand.avatarUrl,
                        name: brand.name,
                        size: 24,
                      ),
                      const SizedBox(width: AppSpacing.xs),
                      Expanded(
                        child: Row(
                          children: [
                            Flexible(
                              child: Text(
                                brand.name,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: AppTypography.labelLarge.copyWith(
                                  fontSize: 13,
                                  color: AppColors.onSurfaceVariant,
                                ),
                              ),
                            ),
                            // The brand's blue tick, granted by the Zusty
                            // team after KYC — only when the API says so.
                            if (brand.isVerified) ...[
                              const SizedBox(width: 4),
                              const VerifiedBadge(size: 14),
                            ],
                          ],
                        ),
                      ),
                      if (application?.status == RecipientStatus.invited) ...[
                        const SizedBox(width: AppSpacing.xs),
                        const StatusChip(
                          label: 'Invited',
                          tone: StatusTone.info,
                          icon: Icons.mark_email_unread_rounded,
                          dense: true,
                        ),
                      ],
                    ],
                  ),
                const SizedBox(height: AppSpacing.xs),
                Text(
                  campaign.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleMedium.copyWith(
                    fontSize: 17,
                    height: 1.3,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),
                Wrap(
                  spacing: AppSpacing.sm,
                  runSpacing: 6,
                  children: [
                    if (campaign.locationLabel != null)
                      CampaignMeta(
                        icon: Icons.place_outlined,
                        label: campaign.locationLabel!,
                      ),
                    if (minFollowers != null)
                      CampaignMeta(
                        icon: Icons.groups_outlined,
                        label: '${Formatters.compact(minFollowers)}+ followers',
                      ),
                    CampaignMeta(
                      icon: Icons.event_outlined,
                      label: deadline.label,
                      tone: deadline.tone,
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.sm),
                Divider(height: 1, color: AppColors.outlineVariant),
                const SizedBox(height: AppSpacing.sm),
                Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            campaign.displayBudget,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.titleMedium.copyWith(
                              fontSize: 16,
                              color: AppColors.primaryDark,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            'Budget · ${campaign.applicantsLabel}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.labelSmall.copyWith(
                              color: AppColors.onSurfaceVariant,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    _CardAction(campaign: campaign, onApply: onApply),
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

/// The card's right-hand action: Apply, the application's status, or why
/// there is nothing to do.
class _CardAction extends StatelessWidget {
  const _CardAction({required this.campaign, this.onApply});

  final Campaign campaign;
  final VoidCallback? onApply;

  @override
  Widget build(BuildContext context) {
    final application = campaign.myApplication;

    if (campaign.canApply) {
      return ZButton(
        label: application == null ? 'Apply' : 'Respond',
        size: ZButtonSize.medium,
        expand: false,
        trailingIcon: Icons.arrow_forward_rounded,
        onPressed: onApply ??
            () => context.push(Routes.applyToOpportunity(campaign.id)),
      );
    }

    if (application != null) {
      return StatusChip(
        label: application.label,
        tone: application.tone,
        icon: application.icon,
      );
    }

    return const StatusChip(
      label: 'Not accepting',
      tone: StatusTone.neutral,
      icon: Icons.lock_clock_outlined,
    );
  }
}

/// An icon-and-label fact under a campaign title ("Mumbai, Pune",
/// "Ends in 3 days"), optionally tinted by tone.
class CampaignMeta extends StatelessWidget {
  const CampaignMeta({
    super.key,
    required this.icon,
    required this.label,
    this.tone = StatusTone.neutral,
  });

  final IconData icon;
  final String label;
  final StatusTone tone;

  @override
  Widget build(BuildContext context) {
    final color = tone == StatusTone.neutral
        ? AppColors.onSurfaceVariant
        : StatusChip.colorsFor(tone).$2;

    return ConstrainedBox(
      // A Wrap hands its children unbounded width; cap each fact so a very
      // long city list ellipsizes instead of overflowing the card.
      constraints: const BoxConstraints(maxWidth: 240),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: color),
          const SizedBox(width: 4),
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.labelMedium.copyWith(
                color: color,
                fontWeight: tone == StatusTone.neutral
                    ? FontWeight.w500
                    : FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A shimmer silhouette matching [CampaignCard], for the board's first load.
class CampaignCardSkeleton extends StatelessWidget {
  const CampaignCardSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.xlAll,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: const Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ZShimmer(height: 112),
          Padding(
            padding: EdgeInsets.all(AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _Bar(width: 0.35, height: 12),
                SizedBox(height: AppSpacing.sm),
                _Bar(width: 0.9, height: 16),
                SizedBox(height: 6),
                _Bar(width: 0.6, height: 16),
                SizedBox(height: AppSpacing.sm),
                _Bar(width: 0.5, height: 12),
                SizedBox(height: AppSpacing.md),
                Row(
                  children: [
                    Expanded(child: _Bar(width: 0.6, height: 18)),
                    SizedBox(width: AppSpacing.md),
                    ZShimmer(width: 96, height: 46, borderRadius: AppRadius.pill),
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

class _Bar extends StatelessWidget {
  const _Bar({required this.width, required this.height});

  final double width;
  final double height;

  @override
  Widget build(BuildContext context) => FractionallySizedBox(
        alignment: AlignmentDirectional.centerStart,
        widthFactor: width,
        child: ZShimmer(height: height, borderRadius: AppRadius.smAll),
      );
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/campaign.dart';
import '../../providers/opportunity_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/campaign_card.dart';
import '../../shared/widgets/platform_icon.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_timeline.dart';
import '../kyc/widgets/kyc_gate.dart';
import 'widgets/opportunity_states.dart';

/// One brief, as a creator weighs it.
///
/// The order follows the decision: what it is and who it is from, the numbers
/// (pay, dates, platform, volume, competition), the brief in the brand's own
/// words, whether the creator fits, the small print, and when things happen.
/// The action — apply, or where an application stands — is pinned to the
/// bottom so it is never more than a thumb away.
class OpportunityDetailScreen extends ConsumerWidget {
  const OpportunityDetailScreen({super.key, required this.campaignId});

  final int campaignId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final campaign = ref.watch(opportunityProvider(campaignId));
    final isCreator = ref.watch(isCreatorSessionProvider);

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Opportunity'),
      bottomBar: isCreator
          ? campaign.maybeWhen(
              data: (data) => _ActionBar(campaign: data),
              orElse: () => null,
            )
          : null,
      body: CreatorOnlyGate(
        child: campaign.when(
          loading: () => const _DetailSkeleton(),
          // A verification gate gets its own way out, worded for where the
          // creator's verification stands; anything else, the shared states.
          error: (error, _) => isKycGate(error)
              ? KycGateView(failure: error as ApiFailure)
              : OpportunityErrorView(
                  error: error,
                  onRetry: () =>
                      ref.invalidate(opportunityProvider(campaignId)),
                ),
          data: (data) => ZRefresh(
            onRefresh: () => ref.refresh(opportunityProvider(campaignId).future),
            child: _Body(campaign: data),
          ),
        ),
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.campaign});

  final Campaign campaign;

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);
    final requirements = campaign.requirements;
    final application = campaign.myApplication;
    final links = _referenceLinks(campaign);

    // Each section staggers in once; under reduced motion they are simply
    // there.
    var index = 0;
    Widget section(Widget child) => Padding(
          padding: const EdgeInsets.only(top: AppSpacing.lg),
          child: Reveal(index: index++, child: child),
        );

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, margin, AppSpacing.xl),
      children: [
        CampaignCoverPanel(
          campaign: campaign,
          height: 168,
          borderRadius: AppRadius.xxlAll,
          showStatus: false,
        ),
        const SizedBox(height: AppSpacing.md),
        _TitleBlock(campaign: campaign),
        if (campaign.brand != null) ...[
          const SizedBox(height: AppSpacing.md),
          _BrandRow(campaign: campaign),
        ],
        section(_FactsGrid(campaign: campaign)),
        if (application != null) section(_YourApplication(application: application)),
        if (campaign.description.trim().isNotEmpty)
          section(
            _Section(
              title: 'About the brief',
              child: _ExpandableText(text: campaign.description.trim()),
            ),
          ),
        if (requirements.deliverables.isNotEmpty)
          section(
            _Section(
              title: 'What you\'ll create',
              child: Column(
                children: [
                  for (final item in requirements.deliverables)
                    _Bullet(text: item),
                ],
              ),
            ),
          ),
        if (requirements.hasAudienceRules)
          section(
            _Section(
              title: 'Who they\'re looking for',
              child: _Requirements(requirements: requirements),
            ),
          ),
        if (requirements.hasTerms)
          section(
            _Section(
              title: 'Terms',
              child: _Terms(requirements: requirements),
            ),
          ),
        if (links.isNotEmpty)
          section(
            _Section(
              title: 'Brief & references',
              child: Column(
                children: [for (final link in links) _LinkRow(link: link)],
              ),
            ),
          ),
        section(
          _Section(
            title: 'Important dates',
            child: ZTimeline(steps: _timeline(campaign)),
          ),
        ),
      ],
    );
  }

  /// The brief document first, then each reference once. Seeded and
  /// imported briefs often list the brief PDF among the references too.
  static List<_Link> _referenceLinks(Campaign campaign) {
    final seen = <String>{};
    final links = <_Link>[];

    final brief = campaign.briefUrl?.trim();
    if (brief != null && brief.isNotEmpty && seen.add(brief)) {
      links.add(_Link(url: brief, isBrief: true));
    }
    for (final url in campaign.referenceUrls) {
      final trimmed = url.trim();
      if (trimmed.isNotEmpty && seen.add(trimmed)) {
        links.add(_Link(url: trimmed));
      }
    }
    return links;
  }

  /// Published, the creator's own application, start and end — in date
  /// order, with today's position marked.
  static List<TimelineStep> _timeline(Campaign campaign) {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final application = campaign.myApplication;

    final events = <(DateTime, String, String?)>[
      if (campaign.createdAt != null)
        (campaign.createdAt!, 'Brief published', null),
      if (application?.invitedAt != null)
        (application!.invitedAt!, 'You were invited', null),
      if (application?.appliedAt != null)
        (
          application!.appliedAt!,
          'You applied',
          application.quoteLabel == null
              ? null
              : 'Quoted ${application.quoteLabel}',
        ),
      if (application?.respondedAt != null)
        if (_responseTitle(application!.status) case final title?)
          (application.respondedAt!, title, null),
      if (campaign.startsOn != null)
        (campaign.startsOn!, 'Campaign starts', 'Content work begins'),
      if (campaign.endsOn != null)
        (campaign.endsOn!, 'Campaign ends', 'Last day for deliverables'),
    ]..sort((a, b) => a.$1.compareTo(b.$1));

    if (events.isEmpty) {
      return const [
        TimelineStep(
          title: 'Flexible timeline',
          subtitle: 'The brand has not set dates for this brief.',
          state: TimelineState.current,
        ),
      ];
    }

    // Everything up to today is done; the first date still ahead is "now"
    // (the ringed dot), and the rest are still to come.
    final steps = <TimelineStep>[];
    var currentMarked = false;
    for (final (date, title, subtitle) in events) {
      final day = DateTime(date.year, date.month, date.day);
      final TimelineState state;
      if (!day.isAfter(today)) {
        state = TimelineState.done;
      } else if (!currentMarked) {
        currentMarked = true;
        state = TimelineState.current;
      } else {
        state = TimelineState.upcoming;
      }
      steps.add(
        TimelineStep(
          title: title,
          subtitle: subtitle,
          time: DateFormat('EEE, d MMM yyyy').format(date),
          state: state,
        ),
      );
    }
    return steps;
  }

  /// The timeline entry for the brand's latest decision, or null for the
  /// statuses that are not a decision.
  static String? _responseTitle(RecipientStatus status) => switch (status) {
        RecipientStatus.shortlisted => 'You were shortlisted',
        RecipientStatus.hired => 'You were hired',
        RecipientStatus.declined => 'The brand passed this time',
        RecipientStatus.invited || RecipientStatus.applied => null,
      };
}

// -----------------------------------------------------------------------------
// Header
// -----------------------------------------------------------------------------

class _TitleBlock extends StatelessWidget {
  const _TitleBlock({required this.campaign});

  final Campaign campaign;

  @override
  Widget build(BuildContext context) {
    final status = campaign.boardStatus;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: AppSpacing.xs,
          runSpacing: AppSpacing.xs,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            StatusChip(label: status.label, tone: status.tone),
            if (campaign.code.isNotEmpty)
              Text(
                campaign.code,
                style: AppTypography.labelSmall.copyWith(
                  color: AppColors.onSurfaceVariant,
                  letterSpacing: 0.4,
                ),
              ),
          ],
        ),
        const SizedBox(height: AppSpacing.xs),
        Semantics(
          header: true,
          child: Text(campaign.title, style: AppTypography.headlineMedium),
        ),
      ],
    );
  }
}

class _BrandRow extends StatelessWidget {
  const _BrandRow({required this.campaign});

  final Campaign campaign;

  @override
  Widget build(BuildContext context) {
    final brand = campaign.brand!;
    final posted = campaign.createdAt;

    return Row(
      children: [
        ZAvatar(imageUrl: brand.avatarUrl, name: brand.name, size: 44),
        const SizedBox(width: AppSpacing.sm),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Flexible(
                    child: Text(
                      brand.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.titleSmall,
                    ),
                  ),
                  // Blue tick: granted by the Zusty team after KYC.
                  if (brand.isVerified) ...[
                    const SizedBox(width: 4),
                    const VerifiedBadge(size: 16),
                  ],
                ],
              ),
              const SizedBox(height: 2),
              Text(
                posted == null
                    ? 'Brand'
                    : 'Posted ${DateFormat('d MMM yyyy').format(posted)}',
                style: AppTypography.bodySmall.copyWith(
                  color: AppColors.onSurfaceVariant,
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
// Key facts
// -----------------------------------------------------------------------------

/// The numbers a creator scans first, two to a row.
class _FactsGrid extends StatelessWidget {
  const _FactsGrid({required this.campaign});

  final Campaign campaign;

  @override
  Widget build(BuildContext context) {
    final deadline = campaign.deadline;
    final timeline = _timelineLabel(campaign);

    final facts = <Widget>[
      _Fact(
        icon: Icons.payments_outlined,
        label: 'Budget',
        value: campaign.displayBudget,
        emphasise: true,
      ),
      _Fact(
        icon: Icons.date_range_outlined,
        label: 'Timeline',
        value: timeline,
      ),
      _Fact(
        leading: PlatformIcon(
          platform: campaign.platform,
          iconName: campaign.platform == 'multi' ? 'external' : null,
          size: 32,
        ),
        label: 'Platform',
        value: campaign.platformLabel,
      ),
      _Fact(
        icon: Icons.movie_creation_outlined,
        label: 'Deliverables',
        value: campaign.deliverableShortLabel,
      ),
      _Fact(
        icon: Icons.groups_2_outlined,
        label: 'Applicants',
        value: campaign.applicationsCount == 0
            ? 'None yet'
            : '${Formatters.number(campaign.applicationsCount)} applied',
      ),
      _Fact(
        icon: Icons.hourglass_bottom_rounded,
        label: 'Deadline',
        value: deadline.label,
        tone: deadline.tone,
      ),
    ];

    return LayoutBuilder(
      builder: (context, constraints) {
        final width = (constraints.maxWidth - AppSpacing.sm) / 2;
        return Wrap(
          spacing: AppSpacing.sm,
          runSpacing: AppSpacing.sm,
          children: [
            for (final fact in facts) SizedBox(width: width, child: fact),
          ],
        );
      },
    );
  }

  static String _timelineLabel(Campaign campaign) {
    final start = campaign.startsOn;
    final end = campaign.endsOn;
    final short = DateFormat('d MMM');
    if (start == null && end == null) return 'Flexible';
    if (start == null) return 'Until ${short.format(end!)}';
    if (end == null) return 'From ${short.format(start)}';
    return '${short.format(start)} – ${short.format(end)}';
  }
}

class _Fact extends StatelessWidget {
  const _Fact({
    required this.label,
    required this.value,
    this.icon,
    this.leading,
    this.emphasise = false,
    this.tone = StatusTone.neutral,
  });

  final String label;
  final String value;
  final IconData? icon;
  final Widget? leading;
  final bool emphasise;
  final StatusTone tone;

  @override
  Widget build(BuildContext context) {
    final valueColor = emphasise
        ? AppColors.primaryDark
        : tone == StatusTone.neutral
            ? AppColors.onSurface
            : StatusChip.colorsFor(tone).$2;

    return Semantics(
      label: '$label: $value',
      excludeSemantics: true,
      child: Container(
        padding: const EdgeInsets.all(AppSpacing.sm),
        decoration: BoxDecoration(
          color: AppColors.surfaceContainerLowest,
          borderRadius: AppRadius.lgAll,
          border: Border.all(color: AppColors.outlineVariant),
        ),
        child: Row(
          children: [
            leading ??
                Container(
                  width: 32,
                  height: 32,
                  decoration: BoxDecoration(
                    color: AppColors.primaryFixed,
                    shape: BoxShape.circle,
                  ),
                  child: Icon(icon, size: 17, color: AppColors.primary),
                ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: Column(
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
                  const SizedBox(height: 1),
                  Text(
                    value,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.titleSmall.copyWith(
                      color: valueColor,
                      fontSize: 13,
                      height: 1.25,
                    ),
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

// -----------------------------------------------------------------------------
// Sections
// -----------------------------------------------------------------------------

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(
          header: true,
          child: Text(title, style: AppTypography.titleMedium),
        ),
        const SizedBox(height: AppSpacing.sm),
        child,
      ],
    );
  }
}

/// A long brief folds to its first lines with a "Read more", so the facts
/// and requirements below are not pushed off the screen by a wall of text.
class _ExpandableText extends StatefulWidget {
  const _ExpandableText({required this.text});

  final String text;

  @override
  State<_ExpandableText> createState() => _ExpandableTextState();
}

class _ExpandableTextState extends State<_ExpandableText> {
  static const _foldAt = 360;
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    final long = widget.text.length > _foldAt;
    final style = AppTypography.bodyLarge.copyWith(
      color: AppColors.onSurface,
      height: 1.55,
    );

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
            maxLines: long && !_expanded ? 6 : null,
            overflow: long && !_expanded ? TextOverflow.ellipsis : null,
          ),
        ),
        if (long)
          TextButton(
            onPressed: () => setState(() => _expanded = !_expanded),
            style: TextButton.styleFrom(
              padding: EdgeInsets.zero,
              minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
              alignment: Alignment.centerLeft,
            ),
            child: Text(
              _expanded ? 'Show less' : 'Read more',
              style: AppTypography.labelLarge.copyWith(color: AppColors.primary),
            ),
          ),
      ],
    );
  }
}

class _Bullet extends StatelessWidget {
  const _Bullet({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Icon(
              Icons.check_circle_rounded,
              size: 18,
              color: AppColors.success,
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(child: Text(text, style: AppTypography.bodyMedium)),
        ],
      ),
    );
  }
}

/// The brief's targeting, as rows a creator can check themselves against.
class _Requirements extends StatelessWidget {
  const _Requirements({required this.requirements});

  final CampaignRequirements requirements;

  @override
  Widget build(BuildContext context) {
    final r = requirements;
    final followers = switch ((r.minFollowers, r.maxFollowers)) {
      (final int min?, final int max?) =>
        '${Formatters.compact(min)} – ${Formatters.compact(max)} followers',
      (final int min?, null) => '${Formatters.compact(min)}+ followers',
      (null, final int max?) => 'Up to ${Formatters.compact(max)} followers',
      _ => null,
    };
    final gender = switch (r.gender) {
      'female' => 'Female creators',
      'male' => 'Male creators',
      'non_binary' => 'Non-binary creators',
      _ => null,
    };

    return ZCard(
      shadow: false,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.xs,
      ),
      child: Column(
        children: [
          if (followers != null)
            _RequirementRow(
              icon: Icons.groups_outlined,
              label: 'Audience size',
              value: followers,
            ),
          if (r.minEngagementRate != null)
            _RequirementRow(
              icon: Icons.trending_up_rounded,
              label: 'Engagement',
              value: '${r.minEngagementRate!.toStringAsFixed(1)}%+ engagement rate',
            ),
          if (r.minRating != null)
            _RequirementRow(
              icon: Icons.star_outline_rounded,
              label: 'Rating',
              value: '${r.minRating!.toStringAsFixed(1)}★ or higher',
            ),
          if (gender != null)
            _RequirementRow(
              icon: Icons.person_outline_rounded,
              label: 'Creator',
              value: gender,
            ),
          if (r.ageRange != null && r.ageRange!.isNotEmpty)
            _RequirementRow(
              icon: Icons.cake_outlined,
              label: 'Age',
              value: r.ageRange!.replaceAll('-', '–'),
            ),
          if (r.cities.isNotEmpty)
            _RequirementRow(
              icon: Icons.place_outlined,
              label: 'Cities',
              chips: r.cities,
            ),
          if (r.languages.isNotEmpty)
            _RequirementRow(
              icon: Icons.translate_rounded,
              label: 'Languages',
              chips: r.languages,
            ),
        ],
      ),
    );
  }
}

class _RequirementRow extends StatelessWidget {
  const _RequirementRow({
    required this.icon,
    required this.label,
    this.value,
    this.chips = const [],
  });

  final IconData icon;
  final String label;
  final String? value;
  final List<String> chips;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Icon(icon, size: 18, color: AppColors.primary),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: AppTypography.labelSmall.copyWith(
                    color: AppColors.onSurfaceVariant,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 2),
                if (value != null)
                  Text(value!, style: AppTypography.bodyMedium),
                if (chips.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: [
                        for (final chip in chips)
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: AppSpacing.sm,
                              vertical: 5,
                            ),
                            decoration: BoxDecoration(
                              color: AppColors.surfaceContainerLow,
                              borderRadius: AppRadius.pill,
                            ),
                            child: Text(
                              chip,
                              style: AppTypography.labelMedium,
                            ),
                          ),
                      ],
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

class _Terms extends StatelessWidget {
  const _Terms({required this.requirements});

  final CampaignRequirements requirements;

  @override
  Widget build(BuildContext context) {
    final r = requirements;
    return ZCard(
      shadow: false,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.xs,
      ),
      child: Column(
        children: [
          if (r.usageRightsDays != null)
            ZDetailRow(
              icon: Icons.copyright_outlined,
              label: 'Usage rights',
              value: Formatters.duration(r.usageRightsDays),
            ),
          if (r.exclusivityDays != null)
            ZDetailRow(
              icon: Icons.block_outlined,
              label: 'Exclusivity',
              value: Formatters.duration(r.exclusivityDays),
            ),
          if (r.barterOnly)
            const ZDetailRow(
              icon: Icons.card_giftcard_outlined,
              label: 'Payment',
              value: 'Product only (barter)',
            ),
        ],
      ),
    );
  }
}

class _Link {
  const _Link({required this.url, this.isBrief = false});

  final String url;
  final bool isBrief;

  Uri? get uri => Uri.tryParse(url);

  String get host => (uri?.host ?? '').replaceFirst('www.', '');

  bool get isImage =>
      RegExp(r'\.(png|jpe?g|webp|gif)(\?|$)', caseSensitive: false)
          .hasMatch(url) ||
      host.contains('picsum.photos');

  bool get isPdf => RegExp(r'\.pdf(\?|$)', caseSensitive: false).hasMatch(url);
}

/// A reference the brand attached. Opens outside the app: these are the
/// brand's files and pages, not screens of ours.
class _LinkRow extends StatelessWidget {
  const _LinkRow({required this.link});

  final _Link link;

  @override
  Widget build(BuildContext context) {
    final title = link.isBrief
        ? 'Campaign brief'
        : link.isPdf
            ? 'Reference document'
            : link.isImage
                ? 'Mood board image'
                : 'Reference link';

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: ZCard(
        shadow: false,
        padding: const EdgeInsets.all(AppSpacing.sm),
        onTap: () => _open(context, link.url),
        child: Semantics(
          link: true,
          label: '$title, ${link.host}. Opens outside the app',
          excludeSemantics: true,
          child: Row(
            children: [
              ClipRRect(
                borderRadius: AppRadius.mdAll,
                child: SizedBox(
                  width: 44,
                  height: 44,
                  child: link.isImage
                      ? ZImage(url: link.url, width: 44, height: 44)
                      : ColoredBox(
                          color: AppColors.primaryFixed,
                          child: Icon(
                            link.isBrief || link.isPdf
                                ? Icons.description_outlined
                                : Icons.link_rounded,
                            color: AppColors.primary,
                            size: 22,
                          ),
                        ),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: AppTypography.titleSmall),
                    const SizedBox(height: 2),
                    Text(
                      link.host.isEmpty ? link.url : link.host,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodySmall,
                    ),
                  ],
                ),
              ),
              Icon(
                Icons.open_in_new_rounded,
                size: 18,
                color: AppColors.onSurfaceVariant,
              ),
            ],
          ),
        ),
      ),
    );
  }

  static Future<void> _open(BuildContext context, String url) async {
    final uri = Uri.tryParse(url);
    final opened = uri != null &&
        await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!opened && context.mounted) {
      showZSnack(context, 'Could not open that link.', isError: true);
    }
  }
}

/// What the creator sent, and — once the brand has answered — what came of
/// it, including the reason when a brand declined and gave one.
class _YourApplication extends StatelessWidget {
  const _YourApplication({required this.application});

  final MyApplication application;

  @override
  Widget build(BuildContext context) {
    final a = application;
    final pitch = a.pitch?.trim();

    return _Section(
      title: 'Your application',
      child: ZCard(
        shadow: false,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                StatusChip(label: a.label, tone: a.tone, icon: a.icon),
                const Spacer(),
                if (a.quoteLabel != null)
                  Text(
                    a.quoteLabel!,
                    style: AppTypography.titleMedium.copyWith(fontSize: 16),
                  ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(
              a.explanation,
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
            if (a.status == RecipientStatus.declined &&
                (a.declineReason?.trim().isNotEmpty ?? false)) ...[
              const SizedBox(height: AppSpacing.sm),
              ZInfoBanner(
                tone: StatusTone.error,
                icon: Icons.format_quote_rounded,
                title: 'Note from the brand',
                message: a.declineReason!.trim(),
              ),
            ],
            if (pitch != null && pitch.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.md),
              Text(
                'Your pitch',
                style: AppTypography.labelSmall.copyWith(
                  color: AppColors.onSurfaceVariant,
                  fontWeight: FontWeight.w500,
                ),
              ),
              const SizedBox(height: 4),
              Text(pitch, style: AppTypography.bodyMedium),
            ],
            if (a.portfolioLinks.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.md),
              Text(
                'Portfolio links',
                style: AppTypography.labelSmall.copyWith(
                  color: AppColors.onSurfaceVariant,
                  fontWeight: FontWeight.w500,
                ),
              ),
              const SizedBox(height: 4),
              for (final url in a.portfolioLinks)
                InkWell(
                  onTap: () => _LinkRow._open(context, url),
                  borderRadius: AppRadius.smAll,
                  child: ConstrainedBox(
                    constraints:
                        const BoxConstraints(minHeight: AppSpacing.minTouch),
                    child: Row(
                      children: [
                        Icon(Icons.link_rounded,
                            size: 16, color: AppColors.primary),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            url,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.bodyMedium.copyWith(
                              color: AppColors.primary,
                            ),
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

// -----------------------------------------------------------------------------
// Sticky action bar
// -----------------------------------------------------------------------------

/// Apply, or where the creator's application stands.
class _ActionBar extends StatelessWidget {
  const _ActionBar({required this.campaign});

  final Campaign campaign;

  @override
  Widget build(BuildContext context) {
    final application = campaign.myApplication;

    if (campaign.canApply) {
      return Row(
        children: [
          Expanded(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  application == null ? 'Budget' : 'You\'re invited',
                  style: AppTypography.labelSmall.copyWith(
                    color: AppColors.onSurfaceVariant,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                Text(
                  campaign.displayBudget,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleMedium.copyWith(
                    fontSize: 16,
                    color: AppColors.primaryDark,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          ZButton(
            label: 'Apply now',
            expand: false,
            trailingIcon: Icons.arrow_forward_rounded,
            onPressed: () =>
                context.push(Routes.applyToOpportunity(campaign.id)),
          ),
        ],
      );
    }

    if (application == null) {
      return Row(
        children: [
          Icon(Icons.lock_clock_outlined, color: AppColors.onSurfaceVariant),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              'This brief is no longer taking applications.',
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ),
        ],
      );
    }

    final hiredWithBooking = application.status == RecipientStatus.hired &&
        application.bookingId != null;

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            StatusChip(
              label: application.label,
              tone: application.tone,
              icon: application.icon,
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Text(
                application.explanation,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.bodySmall.copyWith(
                  color: AppColors.onSurfaceVariant,
                ),
              ),
            ),
          ],
        ),
        if (hiredWithBooking) ...[
          const SizedBox(height: AppSpacing.sm),
          ZButton(
            label: 'Open booking',
            icon: Icons.work_outline_rounded,
            onPressed: () => context.push(Routes.booking(application.bookingId!)),
          ),
        ] else ...[
          const SizedBox(height: AppSpacing.xs),
          ZButton(
            label: 'Track my applications',
            variant: ZButtonVariant.outlined,
            size: ZButtonSize.medium,
            onPressed: () => context.push(Routes.applications),
          ),
        ],
      ],
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
      padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, margin, 0),
      children: [
        const ZShimmer(height: 168, borderRadius: AppRadius.xxlAll),
        const SizedBox(height: AppSpacing.md),
        const ZShimmer(width: 80, height: 22, borderRadius: AppRadius.pill),
        const SizedBox(height: AppSpacing.sm),
        const ZShimmer(height: 26, borderRadius: AppRadius.smAll),
        const SizedBox(height: 6),
        const FractionallySizedBox(
          alignment: AlignmentDirectional.centerStart,
          widthFactor: 0.6,
          child: ZShimmer(height: 26, borderRadius: AppRadius.smAll),
        ),
        const SizedBox(height: AppSpacing.lg),
        LayoutBuilder(
          builder: (context, constraints) {
            final width = (constraints.maxWidth - AppSpacing.sm) / 2;
            return Wrap(
              spacing: AppSpacing.sm,
              runSpacing: AppSpacing.sm,
              children: [
                for (var i = 0; i < 6; i++)
                  ZShimmer(
                    width: width,
                    height: 58,
                    borderRadius: AppRadius.lgAll,
                  ),
              ],
            );
          },
        ),
      ],
    );
  }
}

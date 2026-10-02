import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../models/ai.dart';
import '../../models/campaign.dart';
import '../../providers/ai_provider.dart';
import '../../providers/app_config_provider.dart';
import '../../providers/campaign_provider.dart';
import '../../providers/core_providers.dart';
import '../../shared/widgets/creator_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';

/// AI creator matching for brands.
///
/// Opened from a campaign, it starts from that campaign's brief, narrows the
/// search with the campaign's category, platform, city and budget, and lets the
/// brand invite each match. Opened on its own, the brand types a brief.
class AiMatchScreen extends ConsumerStatefulWidget {
  const AiMatchScreen({super.key, this.campaignId});

  final int? campaignId;

  @override
  ConsumerState<AiMatchScreen> createState() => _AiMatchScreenState();
}

class _AiMatchScreenState extends ConsumerState<AiMatchScreen> {
  final _brief = TextEditingController();
  final Set<int> _invited = {};

  CreatorMatches? _result;
  String? _error;
  bool _busy = false;
  bool _seeded = false;

  static const _minBriefLength = 20;

  @override
  void dispose() {
    _brief.dispose();
    super.dispose();
  }

  Campaign? get _campaign {
    final id = widget.campaignId;
    return id == null ? null : ref.read(campaignDetailProvider(id)).valueOrNull;
  }

  Future<void> _find() async {
    final brief = _brief.text.trim();

    if (brief.length < _minBriefLength) {
      setState(() => _error =
          'Describe the campaign in at least $_minBriefLength characters.');
      return;
    }

    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });

    final campaign = _campaign;

    try {
      final result = await ref.read(aiRepositoryProvider).matchCreators(
            brief: brief,
            categoryId: campaign?.categoryId,
            platform: campaign?.platform,
            city: campaign == null || campaign.requirements.cities.isEmpty
                ? null
                : campaign.requirements.cities.first,
            budgetMax: campaign?.budgetMax,
          );
      if (mounted) setState(() => _result = result);
    } on ApiFailure catch (failure) {
      if (!mounted) return;
      setState(() => _error = failure.message);

      if (failure.statusCode == 403 && failure.code.startsWith('ai_')) {
        ref.read(authConfigProvider.notifier).refresh(force: true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _invite(CreatorMatch match) async {
    final campaignId = widget.campaignId;
    if (campaignId == null) return;

    try {
      await ref
          .read(campaignActionsProvider)
          .invite(campaignId, [match.creator.id]);
      if (!mounted) return;
      setState(() => _invited.add(match.creator.id));
      showZSnack(context, 'Invited ${match.creator.displayName}.');
    } on ApiFailure catch (failure) {
      if (mounted) showZSnack(context, failure.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ai = ref.watch(aiFeaturesProvider);
    final margin = AppBreakpoints.pageMargin(context);
    final campaignId = widget.campaignId;

    // Start from the campaign's own brief, once it has loaded.
    if (campaignId != null && !_seeded) {
      final campaign = ref.watch(campaignDetailProvider(campaignId)).valueOrNull;
      if (campaign != null) {
        _seeded = true;
        _brief.text = campaign.description;
      }
    }

    return ZScaffold(
      backgroundColor: AppColors.surfaceContainerLow,
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'AI creator match'),
      body: !ai.creatorMatch
          ? const EmptyState(
              icon: Icons.auto_awesome_rounded,
              title: 'AI matching is switched off',
              message: 'Browse creators from Explore in the meantime.',
            )
          : ListView(
              padding: EdgeInsets.fromLTRB(
                margin,
                AppSpacing.md,
                margin,
                AppSpacing.xl,
              ),
              children: [
                Text(
                  campaignId == null
                      ? 'Describe your campaign and we will rank the creators '
                          'who fit it best, with a reason for each.'
                      : 'We start from this campaign\'s brief and filters. '
                          'Edit the brief to steer the results.',
                  style: AppTypography.bodyMedium
                      .copyWith(color: AppColors.onSurfaceVariant),
                ),
                const SizedBox(height: AppSpacing.md),
                ZTextField(
                  label: 'Your brief',
                  hint: 'What are you promoting, to whom, and what should '
                      'creators make?',
                  controller: _brief,
                  maxLines: 6,
                  minLines: 3,
                  maxLength: 5000,
                  enabled: !_busy,
                  textCapitalization: TextCapitalization.sentences,
                ),
                if (_error != null) ...[
                  const SizedBox(height: AppSpacing.xs),
                  Text(
                    _error!,
                    style: AppTypography.bodyMedium
                        .copyWith(color: AppColors.error),
                  ),
                ],
                const SizedBox(height: AppSpacing.sm),
                ZButton(
                  label: _busy
                      ? 'Finding matches…'
                      : (_result == null ? 'Find matches' : 'Find again'),
                  icon: Icons.auto_awesome_rounded,
                  isLoading: _busy,
                  onPressed: _busy ? null : _find,
                ),
                if (_result != null) ..._results(_result!),
              ],
            ),
    );
  }

  List<Widget> _results(CreatorMatches result) {
    final remaining = result.remainingToday;

    return [
      const SizedBox(height: AppSpacing.lg),
      Text('Top matches', style: AppTypography.headlineSmall),
      if (remaining != null)
        Text(
          '$remaining AI request${remaining == 1 ? '' : 's'} left today',
          style: AppTypography.labelSmall
              .copyWith(color: AppColors.onSurfaceVariant),
        ),
      const SizedBox(height: AppSpacing.sm),
      if (result.matches.isEmpty)
        const EmptyState(
          compact: true,
          icon: Icons.person_search_rounded,
          title: 'No creators fit yet',
          message: 'Try a broader brief, or widen the campaign\'s filters.',
        )
      else
        for (final match in result.matches) ...[
          CreatorListCard(
            creator: match.creator,
            trailing: _MatchDetails(
              match: match,
              invited: _invited.contains(match.creator.id),
              onInvite: widget.campaignId == null ? null : () => _invite(match),
            ),
          ),
          const SizedBox(height: AppSpacing.xs),
        ],
    ];
  }
}

/// Score, the AI's reason and, inside a campaign, an invite button.
class _MatchDetails extends StatelessWidget {
  const _MatchDetails({
    required this.match,
    required this.invited,
    this.onInvite,
  });

  final CreatorMatch match;
  final bool invited;
  final VoidCallback? onInvite;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.xs),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              StatusChip(
                label: '${match.score}% match',
                tone: match.score >= 75 ? StatusTone.success : StatusTone.info,
                icon: Icons.auto_awesome_rounded,
                dense: true,
              ),
              const Spacer(),
              if (onInvite != null)
                ZButton.tonal(
                  label: invited ? 'Invited' : 'Invite',
                  icon: invited
                      ? Icons.check_rounded
                      : Icons.person_add_alt_rounded,
                  size: ZButtonSize.small,
                  expand: false,
                  onPressed: invited ? null : onInvite,
                ),
            ],
          ),
          if (match.reason.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(match.reason, style: AppTypography.bodyMedium),
          ],
        ],
      ),
    );
  }
}

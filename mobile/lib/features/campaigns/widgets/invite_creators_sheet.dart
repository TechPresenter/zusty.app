import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_failure.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../models/campaign.dart';
import '../../../models/creator.dart';
import '../../../providers/app_config_provider.dart';
import '../../../providers/campaign_provider.dart';
import '../../../providers/discovery_provider.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/menu_tile.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/z_avatar.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_dialogs.dart';
import '../../../shared/widgets/z_image.dart';

/// Where a brand invites creators onto a brief.
///
/// The invite endpoint was only reachable from the AI match screen, so a
/// brand without AI matching enabled — or one who already knew who they
/// wanted — had no way to invite anybody. This puts the creators they have
/// already saved one tap away, next to the two ways of finding new ones.
Future<void> showInviteCreatorsSheet(
  BuildContext context, {
  required Campaign campaign,
}) {
  return showZSheet<void>(
    context,
    title: 'Invite creators',
    subtitle: 'Invited creators are notified and can apply with their rate.',
    builder: (_) => _InviteSheet(campaign: campaign),
  );
}

class _InviteSheet extends ConsumerStatefulWidget {
  const _InviteSheet({required this.campaign});

  final Campaign campaign;

  @override
  ConsumerState<_InviteSheet> createState() => _InviteSheetState();
}

class _InviteSheetState extends ConsumerState<_InviteSheet> {
  final Set<int> _selected = {};
  bool _sending = false;

  /// Creators already in this campaign's pipeline, in any stage.
  late final Set<int> _inPipeline = {
    for (final recipient in widget.campaign.recipients) recipient.creator.id,
  };

  Future<void> _send() async {
    setState(() => _sending = true);
    final count = _selected.length;

    try {
      await ref
          .read(campaignActionsProvider)
          .invite(widget.campaign.id, _selected.toList());
      if (!mounted) return;
      // The snack lives on the app's messenger, so it outlasts the sheet.
      showZSnack(
        context,
        count == 1 ? 'Invitation sent.' : 'Invitations sent to $count creators.',
      );
      Navigator.of(context).pop();
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  void _openRoute(String route) {
    final router = GoRouter.of(context);
    Navigator.of(context).pop();
    router.push(route);
  }

  @override
  Widget build(BuildContext context) {
    final ai = ref.watch(aiFeaturesProvider);
    final saved = ref.watch(favoritesProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        ZMenuSection(
          children: [
            if (ai.creatorMatch)
              ZMenuTile(
                icon: Icons.auto_awesome_rounded,
                title: 'Find matches with AI',
                subtitle: 'Creators ranked against this brief',
                onTap: () =>
                    _openRoute(Routes.campaignMatches(widget.campaign.id)),
              ),
            ZMenuTile(
              icon: Icons.search_rounded,
              title: 'Browse creators',
              subtitle: 'Search the marketplace, then save the ones you like',
              onTap: () => _openRoute(Routes.explore),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.lg),
        Text(
          'From your saved creators',
          style: AppTypography.titleSmall,
        ),
        const SizedBox(height: AppSpacing.xs),
        saved.when(
          loading: () => const Column(
            children: [
              ZShimmer(height: 64, borderRadius: AppRadius.lgAll),
              SizedBox(height: AppSpacing.xs),
              ZShimmer(height: 64, borderRadius: AppRadius.lgAll),
            ],
          ),
          error: (error, _) => ErrorState(
            error: error,
            onRetry: () => ref.invalidate(favoritesProvider),
          ),
          data: (creators) {
            if (creators.isEmpty) {
              return const EmptyState(
                compact: true,
                icon: Icons.favorite_border_rounded,
                title: 'No saved creators yet',
                message: 'Tap the heart on a creator while browsing and they '
                    'will be here to invite.',
              );
            }

            return Column(
              children: [
                for (final creator in creators)
                  _InviteRow(
                    creator: creator,
                    inPipeline: _inPipeline.contains(creator.id),
                    selected: _selected.contains(creator.id),
                    onChanged: _sending
                        ? null
                        : (value) => setState(
                              () => value
                                  ? _selected.add(creator.id)
                                  : _selected.remove(creator.id),
                            ),
                  ),
              ],
            );
          },
        ),
        const SizedBox(height: AppSpacing.md),
        ZButton(
          label: _selected.isEmpty
              ? 'Select creators to invite'
              : 'Send ${_selected.length} '
                  'invitation${_selected.length == 1 ? '' : 's'}',
          icon: Icons.send_rounded,
          isLoading: _sending,
          onPressed: _selected.isEmpty ? null : _send,
        ),
      ],
    );
  }
}

class _InviteRow extends StatelessWidget {
  const _InviteRow({
    required this.creator,
    required this.inPipeline,
    required this.selected,
    required this.onChanged,
  });

  final Creator creator;

  /// Already invited or applied: inviting again would do nothing.
  final bool inPipeline;
  final bool selected;
  final ValueChanged<bool>? onChanged;

  @override
  Widget build(BuildContext context) {
    final facts = [
      '${creator.followersLabel} followers',
      if (creator.rating > 0) '${Formatters.rating(creator.rating)}★',
      if (creator.city != null && creator.city!.isNotEmpty) creator.city!,
    ].join(' · ');

    return Opacity(
      opacity: inPipeline ? 0.55 : 1,
      child: CheckboxListTile(
        value: inPipeline || selected,
        onChanged: inPipeline || onChanged == null
            ? null
            : (value) => onChanged!(value ?? false),
        contentPadding: EdgeInsets.zero,
        controlAffinity: ListTileControlAffinity.trailing,
        activeColor: AppColors.primary,
        secondary: ZAvatar(
          imageUrl: creator.avatarUrl,
          name: creator.displayName,
          size: 44,
          isVerified: creator.isVerified,
        ),
        title: Text(
          creator.displayName,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: AppTypography.titleSmall,
        ),
        subtitle: Text(
          inPipeline ? 'Already on this campaign' : facts,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: AppTypography.bodySmall,
        ),
      ),
    );
  }
}

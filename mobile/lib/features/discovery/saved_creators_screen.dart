import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../models/creator.dart';
import '../../providers/core_providers.dart';
import '../../providers/discovery_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/creator_card.dart';
import '../../shared/widgets/creator_grid.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_scaffold.dart';

/// Screen 25 — Saved Creators.
///
/// The brand's shortlist (SRS-FR-12), with a compare-style grid/list toggle so
/// several creators can be weighed side by side.
class SavedCreatorsScreen extends ConsumerStatefulWidget {
  const SavedCreatorsScreen({super.key});

  @override
  ConsumerState<SavedCreatorsScreen> createState() =>
      _SavedCreatorsScreenState();
}

class _SavedCreatorsScreenState extends ConsumerState<SavedCreatorsScreen> {
  bool _gridView = false;

  @override
  Widget build(BuildContext context) {
    final saved = ref.watch(favoritesProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      backgroundColor: AppColors.surface,
      padded: false,
      constrain: false,
      maxContentWidth: ContentWidth.wide,
      appBar: ZAppBar(
        title: 'Saved Creators',
        actions: [
          IconButton(
            icon: Icon(
              _gridView ? Icons.view_list_rounded : Icons.grid_view_rounded,
              size: 22,
            ),
            tooltip: _gridView ? 'List view' : 'Grid view',
            onPressed: () => setState(() => _gridView = !_gridView),
          ),
        ],
      ),
      body: ZRefresh(
        onRefresh: () async => ref.refresh(favoritesProvider.future),
        child: AsyncView(
          value: saved,
          onRetry: () => ref.invalidate(favoritesProvider),
          loading: Padding(
            padding: EdgeInsets.symmetric(horizontal: margin),
            child: const ListSkeleton(count: 4, height: 112),
          ),
          builder: (creators) {
            if (creators.isEmpty) {
              return EmptyState(
                title: 'No saved creators yet',
                message:
                    'Tap the heart on any creator to shortlist them for your '
                    'next campaign.',
                icon: Icons.favorite_border_rounded,
                actionLabel: 'Explore creators',
                onAction: () => context.push(Routes.explore),
              );
            }

            if (_gridView) {
              return GridView.builder(
                padding: EdgeInsets.fromLTRB(
                  margin,
                  AppSpacing.md,
                  margin,
                  AppSpacing.xl,
                ),
                gridDelegate: CreatorGridDelegate(
                  textScale: MediaQuery.textScalerOf(context).scale(14) / 14,
                ),
                itemCount: creators.length,
                itemBuilder: (_, i) => CreatorGridCard(creator: creators[i]),
              );
            }

            // Two columns of cards on a tablet.
            return AdaptiveCardList(
              padding: EdgeInsets.fromLTRB(
                margin,
                AppSpacing.md,
                margin,
                AppSpacing.xl,
              ),
              itemCount: creators.length,
              itemBuilder: (_, i) => CreatorListCard(
                creator: creators[i],
                trailing: _QuickActions(creator: creators[i]),
              ),
            );
          },
        ),
      ),
    );
  }
}

/// Book / message shortcuts on each shortlisted creator.
class _QuickActions extends ConsumerStatefulWidget {
  const _QuickActions({required this.creator});

  final Creator creator;

  @override
  ConsumerState<_QuickActions> createState() => _QuickActionsState();
}

class _QuickActionsState extends ConsumerState<_QuickActions> {
  bool _opening = false;

  /// Opens (or creates) the conversation with this creator.
  ///
  /// A creator's id is not a conversation id: pushing `Routes.chat(creator.id)`
  /// opened whichever thread happened to share that number, or none. The
  /// server resolves the real thread.
  Future<void> _message() async {
    setState(() => _opening = true);

    try {
      final conversationId = await ref
          .read(messageRepositoryProvider)
          .start(creatorId: widget.creator.id);
      if (mounted) context.push(Routes.chat(conversationId));
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _opening = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: ZButton(
            label: 'Book',
            size: ZButtonSize.small,
            onPressed: () =>
                context.push(Routes.newBooking(widget.creator.handle)),
          ),
        ),
        const SizedBox(width: AppSpacing.xs),
        Expanded(
          child: ZButton.outlined(
            label: 'Message',
            size: ZButtonSize.small,
            isLoading: _opening,
            onPressed: _message,
          ),
        ),
      ],
    );
  }
}

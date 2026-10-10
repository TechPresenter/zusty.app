import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme/app_theme.dart';
import '../../models/safety.dart';
import '../../providers/safety_provider.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_scaffold.dart';
import 'block_dialogs.dart';

/// Settings → Privacy & data → Blocked accounts: everyone the user blocked,
/// newest first, each with an Unblock button.
class BlockedUsersScreen extends ConsumerWidget {
  const BlockedUsersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final blocked = ref.watch(blockedUsersProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Blocked accounts'),
      body: ZRefresh(
        onRefresh: () => ref.refresh(blockedUsersProvider.future),
        child: AsyncView(
          value: blocked,
          onRetry: () => ref.invalidate(blockedUsersProvider),
          skeletonCount: 3,
          builder: (users) => ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.md,
              margin,
              AppSpacing.xxl,
            ),
            children: [
              Text(
                'People you block can\'t message you or send you booking '
                'requests, offers, invitations or applications, and you '
                'don\'t see each other in search. Bookings already paid for '
                'can still be completed or disputed.',
                style: AppTypography.bodySmall,
              ),
              const SizedBox(height: AppSpacing.md),
              if (users.isEmpty)
                const EmptyState(
                  title: 'You haven\'t blocked anyone',
                  message:
                      'To block someone, open their profile or your chat '
                      'with them and choose Block from the ⋮ menu.',
                  icon: Icons.block_rounded,
                  compact: true,
                )
              else
                for (var i = 0; i < users.length; i++)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Reveal(
                      index: i,
                      child: _BlockedUserCard(user: users[i]),
                    ),
                  ),
            ],
          ),
        ),
      ),
    );
  }
}

class _BlockedUserCard extends ConsumerStatefulWidget {
  const _BlockedUserCard({required this.user});

  final BlockedUser user;

  @override
  ConsumerState<_BlockedUserCard> createState() => _BlockedUserCardState();
}

class _BlockedUserCardState extends ConsumerState<_BlockedUserCard> {
  bool _busy = false;

  Future<void> _unblock() async {
    setState(() => _busy = true);
    try {
      // The list reloads itself once the unblock lands.
      await confirmUnblock(
        context,
        ref,
        userId: widget.user.userId,
        name: widget.user.name,
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = widget.user;
    final handle = user.handle;

    return ZCard(
      child: Row(
        children: [
          ZAvatar(imageUrl: user.avatarUrl, name: user.name, size: 44),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  user.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleSmall,
                ),
                const SizedBox(height: 2),
                Text(
                  [
                    if (handle != null && handle.isNotEmpty) '@$handle',
                    user.blockedLabel,
                  ].join('  ·  '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          ZButton.outlined(
            label: 'Unblock',
            size: ZButtonSize.small,
            expand: false,
            isLoading: _busy,
            onPressed: _busy ? null : _unblock,
          ),
        ],
      ),
    );
  }
}
